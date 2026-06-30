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
        public const string Version = "1.1.7";

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

        // --- Milepost real-time (Phase 1) ---
        private RealtimeClient _rt;
        // The wss URL the portal advertised (enroll/heartbeat "realtime_url"); a
        // RealtimeUrl registry/json override takes precedence over this.
        private string _advertisedRtUrl = "";

        // --- Agent auto-update ---
        // The "update" directive the portal advertised this cycle (enroll/heartbeat),
        // or null when absent. Consumed once per MainLoop iteration by Updater.MaybeUpdate
        // and then cleared, so a no-update heartbeat clears any prior directive.
        private Dictionary<string, object> _pendingUpdate;

        public void Start()
        {
            _thread = new Thread(Run) { IsBackground = true, Name = "EightWestAgentWorker" };
            _thread.Start();
        }

        public void Stop()
        {
            _stop.Set();
            try { _rt?.Stop(); } catch { }
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

                StartRealtime();
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
                if (Enroll()) { StartRealtime(); MainLoop(); return; }
            }
        }

        /// <summary>
        /// Start the real-time client once, after enrollment. It runs on its own
        /// background thread alongside MainLoop (which keeps polling as the always-on
        /// fallback, constraint 3). A RealtimeUrl override or the portal-advertised
        /// URL is required; with neither, the agent stays pure-polling.
        /// </summary>
        private void StartRealtime()
        {
            if (_rt != null) return;                       // start once
            if (!_cfg.RealtimeEnabledFlag)
            {
                Log.Info("Realtime disabled by config (RealtimeEnabled=0); polling only.");
                return;
            }
            var url = ResolveRtUrl();
            if (string.IsNullOrEmpty(url))
            {
                Log.Info("Realtime URL not configured/advertised yet; polling only.");
                return;
            }
            try
            {
                _rt = new RealtimeClient(
                    url,
                    () => _state.AuthToken,
                    JobRunner.Execute,
                    new MetricsCollector(),
                    _state,
                    _api,
                    () => _rust != null && _rust.IsInstalled ? _rust.GetId() : "");
                _rt.Start();
            }
            catch (Exception ex)
            {
                Log.Warn("Realtime start failed (polling continues): " + ex.Message);
                _rt = null;
            }
        }

        /// <summary>RealtimeUrl override (registry/json) wins; else the portal-advertised URL.</summary>
        private string ResolveRtUrl()
        {
            // A locally-configured override is trusted, but must still be wss:// so the
            // bearer token never traverses plaintext.
            if (!string.IsNullOrEmpty(_cfg.RealtimeUrl))
                return ValidateRtUrl(_cfg.RealtimeUrl, requireSameDomain: false);
            // The portal-advertised URL arrives over the wire, so it is less trusted:
            // require wss:// AND that its host shares the portal's registrable domain, so a
            // tampered/compromised response can't redirect the agent + token to an
            // attacker-controlled endpoint.
            return ValidateRtUrl(_advertisedRtUrl ?? "", requireSameDomain: true);
        }

        /// <summary>
        /// Returns the URL only if it is wss:// (and, when required, on the same
        /// registrable domain as the portal); otherwise "" so RT stays off and polling
        /// carries the load.
        /// </summary>
        private string ValidateRtUrl(string url, bool requireSameDomain)
        {
            if (string.IsNullOrEmpty(url)) return "";
            Uri u;
            try { u = new Uri(url); }
            catch { Log.Warn("Realtime: ignoring malformed URL: " + url); return ""; }

            if (!string.Equals(u.Scheme, "wss", StringComparison.OrdinalIgnoreCase))
            {
                Log.Warn("Realtime: ignoring non-wss URL (token must stay encrypted): " + url);
                return "";
            }
            if (requireSameDomain)
            {
                var portalHost = TryHost(_cfg.PortalUrl);
                if (portalHost.Length == 0 || !SameRegistrableDomain(u.Host, portalHost))
                {
                    Log.Warn($"Realtime: ignoring advertised host '{u.Host}' (not on portal domain '{portalHost}').");
                    return "";
                }
            }
            return url;
        }

        private static string TryHost(string url)
        {
            try { return new Uri(url).Host; } catch { return ""; }
        }

        // Registrable-domain heuristic (no public-suffix list): compare the last two DNS
        // labels, so support.8westit.com and rt.8westit.com both reduce to 8westit.com.
        private static bool SameRegistrableDomain(string a, string b)
        {
            var ra = LastTwoLabels(a);
            var rb = LastTwoLabels(b);
            return ra.Length > 0 && ra == rb;
        }

        private static string LastTwoLabels(string host)
        {
            var parts = (host ?? "").ToLowerInvariant().TrimEnd('.').Split('.');
            if (parts.Length <= 2) return string.Join(".", parts);
            return parts[parts.Length - 2] + "." + parts[parts.Length - 1];
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

                // Phase 1: the portal may advertise the real-time WS endpoint here
                // (old agents simply ignore the key). A registry/json RealtimeUrl
                // override, if set, wins over this in ResolveRtUrl().
                var advertised = Str(resp, "realtime_url");
                if (!string.IsNullOrEmpty(advertised)) _advertisedRtUrl = advertised;

                // The portal may advertise an "update" directive here too (old agents
                // ignore the key). Stash it; MainLoop hands it to Updater once per cycle.
                _pendingUpdate = resp.ContainsKey("update") ? resp["update"] as Dictionary<string, object> : null;

                // A fresh token was just minted — let any halted RT client reconnect.
                _rt?.NotifyTokenRotated();

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

                    // Real-time fallback maintenance: flush any results the WS path
                    // couldn't durably deliver, via the existing REST endpoint
                    // (idempotent by job_id). Safe whether WS is up or down.
                    _rt?.DrainResultOutboxViaRest();

                    // The portal may begin advertising the WS URL after enrollment
                    // (migration step 4). If so, start RT now without re-enrolling.
                    if (_rt == null) StartRealtime();

                    // Guarded self-update: if the portal advertised an "update" directive
                    // (enroll or this heartbeat), hand it to the Updater once. It enforces
                    // every guard and never throws. Consume-once: clear after handoff so a
                    // no-update heartbeat clears any prior directive.
                    if (_pendingUpdate != null)
                    {
                        Updater.MaybeUpdate(_pendingUpdate, _cfg, _state);
                        _pendingUpdate = null;
                    }

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

            // Phase 1: the portal advertises the WS endpoint on heartbeat too, so an
            // already-running agent can pick it up after a portal config flip without
            // re-enrolling (old agents ignore the key).
            var advertised = Str(resp, "realtime_url");
            if (!string.IsNullOrEmpty(advertised)) _advertisedRtUrl = advertised;

            // The portal advertises the "update" directive on heartbeat too. Overwrite each
            // cycle (null when absent), so a no-update heartbeat clears any prior directive.
            _pendingUpdate = resp.ContainsKey("update") ? resp["update"] as Dictionary<string, object> : null;

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

                // Idempotency guard: if the real-time path already claimed this job_id,
                // never run it again here. This is what prevents the double-execution a
                // mid-run WS disconnect + requeue would otherwise cause.
                if (id != 0 && _state.HasSeenJob(id))
                {
                    var prior = _state.FindResult(id);
                    if (prior != null)
                    {
                        // RT finished but its result hasn't been durably persisted yet —
                        // re-report the real cached result (never a fabricated one).
                        Log.Info($"Job {id} already ran via realtime; re-reporting cached result.");
                        _api.Post("/api/jobs.php", new Dictionary<string, object>
                        {
                            ["job_id"] = id,
                            ["status"] = prior.Status,
                            ["exit_code"] = prior.ExitCode,
                            ["output"] = prior.Output,
                        });
                        _state.RemoveResult(id);
                    }
                    else
                    {
                        // Claimed by RT and still in flight (or already delivered + acked):
                        // the RT path owns delivery. Do NOT execute or fabricate a result.
                        Log.Info($"Job {id} is owned by the realtime path; skipping poll execution.");
                    }
                    continue;
                }

                Log.Info($"Running job {id} ({type})");

                // Single-flight across BOTH paths (shared with RealtimeClient): a poll job
                // and an RT-pushed job can never run concurrently on this machine.
                JobResult result;
                JobRunner.ExecGate.Wait();
                try { result = JobRunner.Execute(type, payload); }
                finally { JobRunner.ExecGate.Release(); }

                if (id != 0) _state.MarkJobSeen(id);
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
