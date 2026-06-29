using System;
using System.Collections.Generic;
using System.IO;
using System.Web.Script.Serialization;

namespace EightWest.Agent
{
    /// <summary>
    /// A command result the agent must still deliver. Held in the on-disk outbox
    /// until the backend confirms durable persistence (<c>cmd_result_ack</c>) or the
    /// REST fallback writes it via the existing POST /api/jobs.php path
    /// (PHASE1-SPEC §5.4). Idempotent by <see cref="JobId"/> end to end.
    /// </summary>
    public class PendingResult
    {
        public int JobId { get; set; }
        public string Status { get; set; } = "done";   // "done" | "error"
        public int ExitCode { get; set; }
        public string Output { get; set; } = "";
        public bool Truncated { get; set; }
        // Unix seconds when the result was produced; drives the REST fallback grace.
        public long ProducedTs { get; set; }
    }

    /// <summary>
    /// Persistent per-machine identity, stored in
    /// C:\ProgramData\8WestIT\Agent\state.json (writable by LocalSystem).
    ///
    /// Phase 1 adds two real-time concerns (PHASE1-SPEC §5):
    ///   - <see cref="SeenJobIds"/>: a small LRU of job_ids run in the last ~10 min,
    ///     the end-to-end idempotency guard against double execution (WS + poll).
    ///   - <see cref="ResultOutbox"/>: results awaiting ack / REST fallback.
    /// Both are persisted so a service restart never loses or re-runs a job.
    /// </summary>
    public class AgentState
    {
        public string AgentUid { get; set; } = "";
        public string AuthToken { get; set; } = "";
        public string RustDeskPassword { get; set; } = "";

        /// <summary>job_ids already executed (idempotency LRU). Oldest first.</summary>
        public List<int> SeenJobIds { get; set; } = new List<int>();

        /// <summary>Results produced but not yet confirmed persisted.</summary>
        public List<PendingResult> ResultOutbox { get; set; } = new List<PendingResult>();

        private const int SeenJobIdCap = 256;

        private static string Dir =>
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                         "8WestIT", "Agent");

        private static string FilePath => Path.Combine(Dir, "state.json");

        public static AgentState Load()
        {
            try
            {
                if (File.Exists(FilePath))
                {
                    var s = new JavaScriptSerializer().Deserialize<AgentState>(File.ReadAllText(FilePath))
                            ?? new AgentState();
                    if (s.SeenJobIds == null) s.SeenJobIds = new List<int>();
                    if (s.ResultOutbox == null) s.ResultOutbox = new List<PendingResult>();
                    return s;
                }
            }
            catch { /* fall through to new */ }
            return new AgentState();
        }

        public void Save()
        {
            Directory.CreateDirectory(Dir);
            File.WriteAllText(FilePath, new JavaScriptSerializer().Serialize(this));
        }

        public bool IsEnrolled =>
            !string.IsNullOrEmpty(AgentUid) && !string.IsNullOrEmpty(AuthToken);

        /* ---------- idempotency LRU (thread-safe; callers may run on the WS thread) ---------- */

        public bool HasSeenJob(int jobId)
        {
            lock (SeenJobIds) { return SeenJobIds.Contains(jobId); }
        }

        /// <summary>Record a job_id as executed; trims the LRU to its cap. Persists.</summary>
        public void MarkJobSeen(int jobId)
        {
            lock (SeenJobIds)
            {
                SeenJobIds.Remove(jobId);          // move-to-end semantics
                SeenJobIds.Add(jobId);
                while (SeenJobIds.Count > SeenJobIdCap) SeenJobIds.RemoveAt(0);
            }
            SafeSave();
        }

        /* ---------- result outbox (thread-safe) ---------- */

        public void EnqueueResult(PendingResult r)
        {
            lock (ResultOutbox)
            {
                // Replace any existing entry for the same job_id (idempotent by job_id).
                ResultOutbox.RemoveAll(x => x.JobId == r.JobId);
                ResultOutbox.Add(r);
            }
            SafeSave();
        }

        public PendingResult FindResult(int jobId)
        {
            lock (ResultOutbox) { return ResultOutbox.Find(x => x.JobId == jobId); }
        }

        public List<PendingResult> SnapshotOutbox()
        {
            lock (ResultOutbox) { return new List<PendingResult>(ResultOutbox); }
        }

        public void RemoveResult(int jobId)
        {
            lock (ResultOutbox)
            {
                if (ResultOutbox.RemoveAll(x => x.JobId == jobId) == 0) return;
            }
            SafeSave();
        }

        private void SafeSave()
        {
            try { Save(); } catch { /* persistence is best-effort; never crash the loop */ }
        }
    }
}
