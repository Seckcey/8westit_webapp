using System;
using System.Collections.Generic;
using System.Linq;
using System.Management;
using System.Net;
using System.Net.Sockets;
using System.Threading;

namespace EightWest.Agent
{
    /// <summary>
    /// The agent's main loop: enroll once, then heartbeat / run jobs / report inventory
    /// until the service stops.
    /// </summary>
    public class Worker
    {
        public const string Version = "1.0.3";

        private readonly ManualResetEvent _stop = new ManualResetEvent(false);
        private Thread _thread;

        private Config _cfg;
        private AgentState _state;
        private ApiClient _api;
        private RustDeskManager _rust;

        private int _heartbeatSecs = 60;
        private DateTime _lastInventory = DateTime.MinValue;
        private bool _rustConfigured = false;
        private DateTime _lastRustAttempt = DateTime.MinValue;

        public void Start()
        {
            _thread = new Thread(Run) { IsBackground = true, Name = "EightWestAgentWorker" };
            _thread.Start();
        }

        public void Stop()
        {
            _stop.Set();
            _thread?.Join(TimeSpan.FromSeconds(10));
        }

        private void Run()
        {
            try
            {
                _cfg = Config.Load();
                _state = AgentState.Load();
                Log.Info($"Agent {Version} starting. Portal={_cfg.PortalUrl}");

                if (string.IsNullOrEmpty(_cfg.PortalUrl))
                {
                    Log.Error("No portal URL configured. Set HKLM\\SOFTWARE\\8WestIT\\Agent\\PortalUrl.");
                    return;
                }

                _api = new ApiClient(_cfg.PortalUrl, _state.IsEnrolled ? _state.AuthToken : null);

                if (!_state.IsEnrolled)
                {
                    if (!Enroll()) { ScheduleRetry(); return; }
                }

                MainLoop();
            }
            catch (Exception ex)
            {
                Log.Error("Fatal worker error: " + ex);
            }
        }

        /// <summary>If enrollment fails (e.g. portal down at boot), keep retrying every 2 min.</summary>
        private void ScheduleRetry()
        {
            while (!_stop.WaitOne(TimeSpan.FromMinutes(2)))
            {
                if (Enroll()) { MainLoop(); return; }
            }
        }

        private bool Enroll()
        {
            try
            {
                if (string.IsNullOrEmpty(_state.AgentUid))
                    _state.AgentUid = Guid.NewGuid().ToString();

                var body = new Dictionary<string, object>
                {
                    ["enrollment_key"] = _cfg.EnrollKey,
                    ["agent_uid"] = _state.AgentUid,
                    ["hostname"] = Environment.MachineName,
                    ["os_name"] = OsName(),
                    ["os_version"] = OsVersion(),
                    ["agent_version"] = Version,
                };
                var resp = _api.Post("/api/enroll.php", body, auth: false);
                if (!IsOk(resp)) { Log.Error("Enroll rejected: " + Str(resp, "error")); return false; }

                _state.AuthToken = Str(resp, "token");
                _state.Save();
                _api.SetToken(_state.AuthToken);
                _heartbeatSecs = (int)Num(resp, "heartbeat_secs", 60);

                // Store the relay settings the portal handed us. The actual RustDesk
                // download/install/config happens in the main loop so enrollment stays fast.
                var rd = resp.ContainsKey("rustdesk") ? resp["rustdesk"] as Dictionary<string, object> : null;
                var relayHost = rd != null ? Convert.ToString(rd.GetValueOrDefaultSafe("relay_host")) : "";
                var relayKey = rd != null ? Convert.ToString(rd.GetValueOrDefaultSafe("relay_key")) : "";
                _rust = new RustDeskManager(relayHost, relayKey, _cfg.RustDeskUrl);
                _rustConfigured = false;
                _lastRustAttempt = DateTime.MinValue;

                Log.Info("Enrolled successfully. uid=" + _state.AgentUid);
                return true;
            }
            catch (Exception ex)
            {
                Log.Error("Enroll error: " + ex.Message);
                return false;
            }
        }

        private void MainLoop()
        {
            // Run the first cycle immediately, then every heartbeat interval.
            // The first iteration is inside the same try/catch so a stale token
            // (e.g. server-side reset) triggers re-enrollment instead of a crash.
            bool first = true;
            while (first || !_stop.WaitOne(TimeSpan.FromSeconds(Math.Max(30, _heartbeatSecs))))
            {
                first = false;
                try
                {
                    var pending = Heartbeat();
                    if (pending > 0) DrainJobs();

                    if ((DateTime.UtcNow - _lastInventory).TotalHours >= 6) SendInventory();

                    // Install + configure RustDesk in the background, retrying every 10 min
                    // until it's ready (first run downloads + silently installs it).
                    if (!_rustConfigured && (DateTime.UtcNow - _lastRustAttempt).TotalMinutes >= 10)
                    {
                        _lastRustAttempt = DateTime.UtcNow;
                        TrySetupRustDesk();
                    }
                }
                catch (ApiException aex) when (aex.StatusCode == 401)
                {
                    Log.Warn("Token rejected (401). Re-enrolling.");
                    _state.AuthToken = "";
                    if (!Enroll()) ScheduleRetry();
                }
                catch (Exception ex)
                {
                    Log.Warn("Loop iteration error: " + ex.Message);
                }
            }
            Log.Info("Worker stopped.");
        }

        private int Heartbeat()
        {
            if (_rust == null) _rust = new RustDeskManager("", "");
            var body = new Dictionary<string, object>
            {
                ["last_user"] = LoggedInUser(),
                ["local_ip"] = LocalIp(),
                ["agent_version"] = Version,
                ["rustdesk_id"] = _rust.IsInstalled ? _rust.GetId() : "",
                ["rustdesk_pass"] = _state.RustDeskPassword ?? "",
            };
            var resp = _api.Post("/api/heartbeat.php", body);
            return (int)Num(resp, "pending_jobs", 0);
        }

        private void DrainJobs()
        {
            for (int i = 0; i < 25; i++) // safety cap per cycle
            {
                if (_stop.WaitOne(0)) return;
                var resp = _api.Get("/api/jobs.php");
                var job = resp.ContainsKey("job") ? resp["job"] as Dictionary<string, object> : null;
                if (job == null) return;

                var id = (int)Num(job, "id", 0);
                var type = Str(job, "job_type");
                var payload = Str(job, "payload");
                Log.Info($"Running job {id} ({type})");

                var result = JobRunner.Execute(type, payload);
                _api.Post("/api/jobs.php", new Dictionary<string, object>
                {
                    ["job_id"] = id,
                    ["status"] = result.Success ? "done" : "error",
                    ["exit_code"] = result.ExitCode,
                    ["output"] = result.Output,
                });
            }
        }

        private void SendInventory()
        {
            try
            {
                _api.Post("/api/inventory.php", Inventory.Collect());
                _lastInventory = DateTime.UtcNow;
                Log.Info("Inventory uploaded.");
            }
            catch (Exception ex) { Log.Warn("Inventory upload failed: " + ex.Message); }
        }

        /// <summary>
        /// Ensure RustDesk is installed, pointed at our relay, and has an unattended
        /// password. Runs on a background loop until it succeeds, then stops retrying.
        /// </summary>
        private void TrySetupRustDesk()
        {
            if (_rust == null) return;
            try
            {
                if (!_rust.IsInstalled && !_rust.EnsureInstalled())
                {
                    Log.Info("RustDesk not ready yet — will retry in 10 minutes.");
                    return;
                }
                _rust.ApplyRelayConfig();
                _state.RustDeskPassword = _rust.EnsurePassword(_state.RustDeskPassword);
                _state.Save();
                _rustConfigured = true;
                Log.Info("RustDesk is ready for remote support (id will report on the next check-in).");
            }
            catch (Exception ex)
            {
                Log.Warn("RustDesk setup error (will retry): " + ex.Message);
            }
        }

        /* ---------- system helpers ---------- */

        private static string OsName()
        {
            try
            {
                foreach (ManagementObject os in new ManagementObjectSearcher(
                             "SELECT Caption FROM Win32_OperatingSystem").Get())
                    return os["Caption"]?.ToString()?.Trim() ?? "Windows";
            }
            catch { }
            return "Windows";
        }

        private static string OsVersion()
        {
            try
            {
                foreach (ManagementObject os in new ManagementObjectSearcher(
                             "SELECT Version FROM Win32_OperatingSystem").Get())
                {
                    var v = os["Version"]?.ToString()?.Trim();
                    if (!string.IsNullOrEmpty(v)) return v;
                }
            }
            catch { }
            return Environment.OSVersion.Version.ToString();
        }

        private static string LoggedInUser()
        {
            try
            {
                foreach (ManagementObject cs in new ManagementObjectSearcher(
                             "SELECT UserName FROM Win32_ComputerSystem").Get())
                {
                    var u = cs["UserName"]?.ToString();
                    if (!string.IsNullOrEmpty(u)) return u;
                }
            }
            catch { }
            return "";
        }

        private static string LocalIp()
        {
            try
            {
                using (var s = new Socket(AddressFamily.InterNetwork, SocketType.Dgram, 0))
                {
                    s.Connect("8.8.8.8", 65530);
                    return ((IPEndPoint)s.LocalEndPoint).Address.ToString();
                }
            }
            catch
            {
                try { return Dns.GetHostAddresses(Dns.GetHostName())
                        .FirstOrDefault(a => a.AddressFamily == AddressFamily.InterNetwork)?.ToString() ?? ""; }
                catch { return ""; }
            }
        }

        /* ---------- response parsing helpers ---------- */

        private static bool IsOk(Dictionary<string, object> r) =>
            r != null && r.ContainsKey("ok") && Convert.ToBoolean(r["ok"]);

        private static string Str(Dictionary<string, object> r, string k) =>
            r != null && r.ContainsKey(k) && r[k] != null ? r[k].ToString() : "";

        private static double Num(Dictionary<string, object> r, string k, double dflt) =>
            r != null && r.ContainsKey(k) && r[k] != null && double.TryParse(r[k].ToString(), out var v) ? v : dflt;
    }

    internal static class DictExtensions
    {
        public static object GetValueOrDefaultSafe(this Dictionary<string, object> d, string key)
            => d != null && d.ContainsKey(key) ? d[key] : null;
    }
}
