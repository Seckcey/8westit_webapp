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

        /// <summary>
        /// Auto-update backoff bookkeeping (bounds crash-loops). The target version of
        /// the most recent self-update attempt and when it was launched (ISO-8601 "o"
        /// round-trip string, NOT a DateTime, to avoid JavaScriptSerializer's /Date()/
        /// format). The same target is never retried within the backoff window.
        /// </summary>
        public string LastUpdateAttemptVersion { get; set; } = "";
        public string LastUpdateAttemptAtUtc { get; set; } = "";

        /// <summary>
        /// Download/verification-failure backoff (distinct from a launched-attempt backoff).
        /// A SHA-256 mismatch or truncated download does NOT mean the target build is bad —
        /// it can be a transient bad byte, an intercepting proxy/AV, or a corrupt template.
        /// We back off the SAME target on a SHORT escalating window (see ShouldSkipUpdateForVerifyBackoff)
        /// keyed on consecutive failures, so a persistent mismatch can never re-download the
        /// full MSI every heartbeat, while a one-off bad byte still retries quickly.
        /// </summary>
        public string LastVerifyFailVersion { get; set; } = "";
        public string LastVerifyFailAtUtc { get; set; } = "";
        public int VerifyFailCount { get; set; } = 0;

        /// <summary>
        /// Self-heal (stale-binary) marker. When an update lands on disk but the live service
        /// process is never cycled (the SCM restart missed), the running image is OLDER than
        /// the on-disk exe. <see cref="SelfHeal"/> restarts the service to load the new binary —
        /// but AT MOST ONCE per detected on-disk version, guarded by this marker, so a restart
        /// that fails to fix the mismatch can never become a restart loop. Cleared once the
        /// running version has caught up to the on-disk version again.
        /// </summary>
        public string SelfHealRestartVersion { get; set; } = "";
        public string SelfHealRestartAtUtc { get; set; } = "";

        /// <summary>job_ids already executed (idempotency LRU). Oldest first.</summary>
        public List<int> SeenJobIds { get; set; } = new List<int>();

        /// <summary>Results produced but not yet confirmed persisted.</summary>
        public List<PendingResult> ResultOutbox { get; set; } = new List<PendingResult>();

        private const int SeenJobIdCap = 256;

        // Guards the auto-update bookkeeping fields. A private field, so the
        // JavaScriptSerializer skips it (never leaks into state.json; no [ScriptIgnore]).
        private readonly object _updateLock = new object();

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
                    // Self-heal marker strings are read via .Length; guard against an explicit
                    // null in a hand-edited/older state.json.
                    if (s.SelfHealRestartVersion == null) s.SelfHealRestartVersion = "";
                    if (s.SelfHealRestartAtUtc == null) s.SelfHealRestartAtUtc = "";
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

        /* ---------- auto-update backoff (thread-safe) ---------- */

        /// <summary>
        /// Record a self-update attempt for <paramref name="version"/> at UtcNow, then
        /// persist. Called BEFORE launching msiexec so a crash-loop is bounded: the same
        /// target is suppressed by <see cref="ShouldSkipUpdateForBackoff"/> within the window.
        /// </summary>
        public void RecordUpdateAttempt(string version)
        {
            lock (_updateLock)
            {
                LastUpdateAttemptVersion = version ?? "";
                LastUpdateAttemptAtUtc = DateTime.UtcNow.ToString("o");
            }
            SafeSave();
        }

        /// <summary>
        /// True when the SAME target version was last attempted within
        /// <paramref name="window"/> — used to skip retrying a failing update too soon.
        /// A different target, an unparseable timestamp, or an expired window → false.
        /// </summary>
        public bool ShouldSkipUpdateForBackoff(string target, TimeSpan window)
        {
            lock (_updateLock)
            {
                if (!string.Equals(LastUpdateAttemptVersion, target, StringComparison.OrdinalIgnoreCase))
                    return false;
                if (!DateTime.TryParse(LastUpdateAttemptAtUtc, null,
                        System.Globalization.DateTimeStyles.RoundtripKind, out var t))
                    return false;
                return (DateTime.UtcNow - t) < window;
            }
        }

        /// <summary>
        /// Record a download/verification FAILURE for <paramref name="version"/> (SHA-256
        /// mismatch or truncated download). Increments the consecutive-failure counter when
        /// the target is unchanged, else resets it to 1. Persists. This is what bounds the
        /// "re-download every 60s forever" loop on a persistent mismatch.
        /// </summary>
        public void RecordVerifyFailure(string version)
        {
            lock (_updateLock)
            {
                if (string.Equals(LastVerifyFailVersion, version, StringComparison.OrdinalIgnoreCase))
                    VerifyFailCount = VerifyFailCount < int.MaxValue ? VerifyFailCount + 1 : VerifyFailCount;
                else
                    VerifyFailCount = 1;
                LastVerifyFailVersion = version ?? "";
                LastVerifyFailAtUtc = DateTime.UtcNow.ToString("o");
            }
            SafeSave();
        }

        /// <summary>
        /// Reset the verification-failure backoff (e.g. once a download verifies cleanly), so a
        /// later transient failure starts a fresh quick-retry cycle.
        /// </summary>
        public void ResetVerifyFailure()
        {
            lock (_updateLock)
            {
                if (VerifyFailCount == 0 && LastVerifyFailVersion.Length == 0) return;
                LastVerifyFailVersion = "";
                LastVerifyFailAtUtc = "";
                VerifyFailCount = 0;
            }
            SafeSave();
        }

        /// <summary>
        /// True when the SAME target recently FAILED verification and we are still inside its
        /// escalating backoff window. The window grows with the consecutive-failure count
        /// (capped) so a one-off bad byte retries within ~<paramref name="baseWindow"/> while a
        /// persistent mismatch quickly backs off to <paramref name="maxWindow"/> — never a
        /// per-heartbeat re-download. A different target → false (fresh target gets a free try).
        /// </summary>
        public bool ShouldSkipUpdateForVerifyBackoff(string target, TimeSpan baseWindow, TimeSpan maxWindow)
        {
            lock (_updateLock)
            {
                if (VerifyFailCount <= 0) return false;
                if (!string.Equals(LastVerifyFailVersion, target, StringComparison.OrdinalIgnoreCase))
                    return false;
                if (!DateTime.TryParse(LastVerifyFailAtUtc, null,
                        System.Globalization.DateTimeStyles.RoundtripKind, out var t))
                    return false;

                // Escalate: window = baseWindow * 2^(failures-1), capped at maxWindow.
                double mult = Math.Pow(2, Math.Min(VerifyFailCount - 1, 10));
                var ticks = (long)Math.Min(baseWindow.Ticks * mult, (double)maxWindow.Ticks);
                var window = TimeSpan.FromTicks(ticks);
                return (DateTime.UtcNow - t) < window;
            }
        }

        /* ---------- self-heal (stale-binary restart) bookkeeping (thread-safe) ---------- */

        /// <summary>
        /// True when a self-update was LAUNCHED within <paramref name="grace"/>. Used to hold
        /// off self-heal so the update path's own MSI + service restart can settle before we
        /// conclude the process is genuinely stuck (avoids racing an in-flight upgrade).
        /// </summary>
        public bool RecentUpdateAttempt(TimeSpan grace)
        {
            lock (_updateLock)
            {
                if (!DateTime.TryParse(LastUpdateAttemptAtUtc, null,
                        System.Globalization.DateTimeStyles.RoundtripKind, out var t))
                    return false;
                return (DateTime.UtcNow - t) < grace;
            }
        }

        /// <summary>
        /// Record that a one-shot self-heal restart was requested for <paramref name="onDiskVersion"/>,
        /// so we never request it again for that same on-disk version (loop-safe). Persists.
        /// </summary>
        public void RecordSelfHealRestart(string onDiskVersion)
        {
            lock (_updateLock)
            {
                SelfHealRestartVersion = onDiskVersion ?? "";
                SelfHealRestartAtUtc = DateTime.UtcNow.ToString("o");
            }
            SafeSave();
        }

        /// <summary>
        /// True when a self-heal restart was already requested for this exact on-disk version,
        /// so the caller must not request another (bounds a restart loop).
        /// </summary>
        public bool AlreadySelfHealedFor(string onDiskVersion)
        {
            lock (_updateLock)
                return SelfHealRestartVersion.Length > 0 &&
                       string.Equals(SelfHealRestartVersion, onDiskVersion, StringComparison.OrdinalIgnoreCase);
        }

        /// <summary>
        /// Clear the self-heal marker (the running version has caught up to the on-disk binary),
        /// so a FUTURE update that lands the same way gets a fresh single self-restart. Persists
        /// only when something actually changed.
        /// </summary>
        public void ClearSelfHealMarker()
        {
            lock (_updateLock)
            {
                if (SelfHealRestartVersion.Length == 0 && SelfHealRestartAtUtc.Length == 0) return;
                SelfHealRestartVersion = "";
                SelfHealRestartAtUtc = "";
            }
            SafeSave();
        }

        private void SafeSave()
        {
            try { Save(); } catch { /* persistence is best-effort; never crash the loop */ }
        }
    }
}
