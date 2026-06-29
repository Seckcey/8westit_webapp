using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Security.Cryptography;
using System.Threading;

namespace EightWest.Agent
{
    /// <summary>
    /// Guarded self-update. The portal advertises an optional "update" directive on the
    /// enroll/heartbeat responses; <see cref="MaybeUpdate"/> validates it against a strict
    /// set of guards and, only if ALL pass, downloads the verified MSI and launches a
    /// MajorUpgrade detached from the service so it survives the service stop.
    ///
    /// SAFETY (anti-brick): off-by-default at the portal; version-INCREASE-ONLY (strictly
    /// greater System.Version); host-pinned https only; SHA-256 verified before install;
    /// never mid-job (try-acquire <see cref="JobRunner.ExecGate"/>); bounded retries via the
    /// persisted LastUpdateAttempt backoff. Recovery from a bad version is FIX-FORWARD
    /// (ship a newer good build) — MSI MajorUpgrade blocks downgrades; there is no rollback.
    ///
    /// MaybeUpdate is best-effort and NEVER throws: polling/RT must keep working regardless.
    /// </summary>
    internal static class Updater
    {
        // Don't retry the SAME target version within this window after a LAUNCHED attempt
        // (bounds crash-loops where the new build fails to come up).
        private static readonly TimeSpan BackoffWindow = TimeSpan.FromHours(6);

        // Separate, SHORTER escalating backoff for DOWNLOAD/VERIFY failures (SHA mismatch,
        // truncated download). Starts ~5 min and doubles per consecutive failure up to 6 h, so
        // a transient bad byte retries fast while a persistent mismatch can never re-download the
        // full MSI every heartbeat (finding: unbounded re-download loop on persistent mismatch).
        private static readonly TimeSpan VerifyBackoffBase = TimeSpan.FromMinutes(5);
        private static readonly TimeSpan VerifyBackoffMax = TimeSpan.FromHours(6);

        /// <summary>
        /// Apply the portal's update directive if every guard passes. Best-effort; swallows
        /// all exceptions. Call once per heartbeat cycle from Worker.MainLoop.
        /// </summary>
        public static void MaybeUpdate(Dictionary<string, object> directive, Config cfg, AgentState state)
        {
            try
            {
                // (a) Local kill-switch.
                if (cfg == null || !cfg.AutoUpdateEnabledFlag)
                {
                    Log.Info("Updater: auto-update disabled locally; skipping.");
                    return;
                }

                // (b) Directive present and complete.
                if (directive == null) return;
                var target = Str(directive, "target_version");
                var url = Str(directive, "url");
                var sha256 = Str(directive, "sha256");
                if (string.IsNullOrEmpty(target) || string.IsNullOrEmpty(url) || string.IsNullOrEmpty(sha256))
                {
                    Log.Info("Updater: directive missing target/url/sha256; skipping.");
                    return;
                }

                // (c) Version-INCREASE-ONLY: target must parse and be strictly greater.
                if (!Version.TryParse(target, out var t) || !Version.TryParse(Worker.Version, out var c))
                {
                    Log.Info($"Updater: unparseable version (target='{target}', current='{Worker.Version}'); skipping.");
                    return;
                }
                if (t <= c)
                {
                    Log.Info($"Updater: target {target} is not greater than current {Worker.Version}; skipping.");
                    return;
                }

                // (d) Backoff: don't retry the same failing target within the window.
                if (state.ShouldSkipUpdateForBackoff(target, BackoffWindow))
                {
                    Log.Info($"Updater: target {target} attempted recently; in backoff window, skipping.");
                    return;
                }

                // (d2) Verify backoff: a persistent SHA-256 mismatch / truncated download must
                // not re-download the full MSI every heartbeat. Honor the escalating verify
                // backoff for the same target before we hit the network at all.
                if (state.ShouldSkipUpdateForVerifyBackoff(target, VerifyBackoffBase, VerifyBackoffMax))
                {
                    Log.Info($"Updater: target {target} recently failed verification; in verify-backoff window, skipping.");
                    return;
                }

                // (e) URL guard: https only AND host pinned to the configured portal host.
                Uri u;
                try { u = new Uri(url); }
                catch { Log.Warn("Updater: malformed update url; skipping: " + url); return; }
                if (!string.Equals(u.Scheme, "https", StringComparison.OrdinalIgnoreCase))
                {
                    Log.Warn("Updater: rejecting non-https update url: " + url);
                    return;
                }
                string portalHost;
                try { portalHost = new Uri(cfg.PortalUrl).Host; }
                catch { Log.Warn("Updater: portal url unparseable; cannot pin host; skipping."); return; }
                if (!string.Equals(u.Host, portalHost, StringComparison.OrdinalIgnoreCase))
                {
                    Log.Warn($"Updater: rejecting non-pinned host '{u.Host}' (portal host is '{portalHost}').");
                    return;
                }

                // (f) Never mid-job: non-blocking try-acquire of the shared exec gate. If a
                // poll/RT job holds it, skip this cycle — never block a long job.
                if (!JobRunner.ExecGate.Wait(0))
                {
                    Log.Info("Updater: a job is running; deferring update to a later cycle.");
                    return;
                }
                try
                {
                    DownloadVerifyAndLaunch(target, url, sha256, state);
                }
                finally
                {
                    // Release before/after the detached launch: the MSI stops the service
                    // anyway, so we must not hold the gate across it.
                    JobRunner.ExecGate.Release();
                }
            }
            catch (Exception ex)
            {
                // NEVER throw out of MaybeUpdate — best-effort; polling/RT must keep working.
                Log.Warn("Updater: " + ex.Message);
            }
        }

        private static void DownloadVerifyAndLaunch(string target, string url, string expectedSha, AgentState state)
        {
            ServicePointManager.SecurityProtocol |= SecurityProtocolType.Tls12;

            var dir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "8WestIT", "Agent", "update");
            Directory.CreateDirectory(dir);
            var msi = Path.Combine(dir, "agent-" + target + ".msi");

            // (1) Download. Use HttpWebRequest directly (ApiClient is JSON-only; HttpClient
            // is not referenced and the csproj is out of edit-scope). Send the bearer token.
            //
            // SECURITY (finding: token leak across cross-host redirect): the https/host pin is
            // checked by MaybeUpdate against the ORIGINAL url only. On .NET Framework 4.8,
            // HttpWebRequest does not reliably strip the Authorization header across a cross-host
            // 3xx, so an open redirect / MITM / portal compromise could 302 this authenticated
            // request to an attacker host and leak the long-lived agent bearer token. The portal
            // serves the MSI directly, so NO redirect is legitimate: disable auto-redirect and
            // treat any 3xx as a hard error.
            Log.Info($"Updater: downloading update {target} from {url}");
            var req = (HttpWebRequest)WebRequest.Create(url);
            req.Method = "GET";
            req.Timeout = 120000;
            req.ReadWriteTimeout = 120000;
            req.UserAgent = "EightWestAgent/" + Worker.Version;
            req.AllowAutoRedirect = false; // never follow redirects on an authenticated download
            req.Headers["Authorization"] = "Bearer " + state.AuthToken;

            long contentLength;
            try
            {
                using (var resp = (HttpWebResponse)req.GetResponse())
                {
                    // Reject any redirect outright — the token must not travel to another host.
                    int code = (int)resp.StatusCode;
                    if (code >= 300 && code < 400)
                    {
                        Log.Warn($"Updater: refusing redirect (HTTP {code}) on authenticated download to {resp.Headers["Location"]}; aborting.");
                        return;
                    }
                    contentLength = resp.ContentLength; // -1 when the server omits it
                    using (var rs = resp.GetResponseStream())
                    using (var fs = new FileStream(msi, FileMode.Create, FileAccess.Write, FileShare.None))
                    {
                        if (rs == null) { Log.Warn("Updater: empty response stream; aborting."); return; }
                        rs.CopyTo(fs);
                    }
                }
            }
            catch (WebException wex)
            {
                // A 3xx is surfaced as a WebException only if a protocol error were raised; with
                // AllowAutoRedirect=false the 3xx comes back as a normal response (handled above),
                // so this catch is for real transport/HTTP errors. Treat as a transient failure
                // (no verify-fail escalation — nothing was downloaded to verify).
                Log.Warn("Updater: download failed: " + wex.Message);
                try { if (File.Exists(msi)) File.Delete(msi); } catch { }
                return;
            }

            // (1b) Truncation sanity check: if the server declared a length, the file on disk
            // must match it. A short read (dropped connection, intercepting proxy) is detected
            // here BEFORE hashing and counted as a verification failure so it backs off.
            long onDisk;
            try { onDisk = new FileInfo(msi).Length; } catch { onDisk = -1; }
            if (contentLength > 0 && onDisk != contentLength)
            {
                Log.Warn($"Updater: truncated download for {target} (declared {contentLength} bytes, got {onDisk}); deleting and backing off.");
                try { File.Delete(msi); } catch { }
                state.RecordVerifyFailure(target); // bound the retry loop (escalating verify backoff)
                return;
            }

            // (2) Verify SHA-256 over the exact bytes the portal hashed (streamed verbatim).
            string actual;
            using (var fs = File.OpenRead(msi))
            using (var sha = SHA256.Create())
                actual = BitConverter.ToString(sha.ComputeHash(fs)).Replace("-", "").ToLowerInvariant();

            if (!string.Equals(actual, (expectedSha ?? "").Trim(), StringComparison.OrdinalIgnoreCase))
            {
                // FINDING FIX: do NOT silently retry every cycle. Record an escalating verify
                // failure so the SAME target is suppressed for a growing window (≥5 min, up to
                // 6 h). A transient bad byte still retries soon; a persistent mismatch (bad
                // template, AV/proxy rewrite) cannot re-download the 24 MB MSI every heartbeat
                // nor flood the portal audit_log.
                Log.Warn($"Updater: SHA-256 mismatch for {target} (expected {expectedSha}, got {actual}); deleting and backing off.");
                try { File.Delete(msi); } catch { }
                state.RecordVerifyFailure(target);
                return;
            }
            // Clean verify: clear any prior verify-failure backoff for a fresh future cycle.
            state.ResetVerifyFailure();
            Log.Info($"Updater: download verified (sha256 {actual}).");

            // (3) Record the attempt BEFORE launching, so a crash-loop is bounded.
            state.RecordUpdateAttempt(target);

            // (4) Decoupled launch via a one-shot Scheduled Task running as SYSTEM.
            //
            // ANTI-BRICK (finding fix): the MSI MajorUpgrade STOPS the LocalSystem EightWestAgent
            // service mid-install. If we launched msiexec from the service's own process tree
            // (e.g. "cmd /c start /b ..."), the service stop could tear down that tree and kill
            // the msiexec CLIENT driving the transaction — leaving the binary half-swapped and
            // the service not reinstalled (a remote brick). A Scheduled Task is hosted by the
            // task engine (svchost), NOT a child of the agent service, so it survives the service
            // stop cleanly. The task runs the upgrade via a small .cmd, then deletes itself.
            var cmdPath = Path.Combine(dir, "run-update.cmd");
            File.WriteAllText(cmdPath,
                "@echo off\r\n" +
                "msiexec /i \"" + msi + "\" /qn /norestart\r\n" +
                // Best-effort cleanup of the one-shot task (ignore failure).
                "schtasks /delete /tn \"" + TaskName + "\" /f >nul 2>&1\r\n" +
                "del \"%~f0\"\r\n");

            if (TryLaunchViaScheduledTask(cmdPath, target))
            {
                Log.Info("Updater: upgrade scheduled (one-shot SYSTEM task); the service will be restarted by the installer.");
                return;
            }

            // Fallback only if schtasks is unavailable/blocked: spawn the cmd broken-away from the
            // service's job object and detached from its console, so it is the most-decoupled
            // child we can create without P/Invoke. Less robust than the task, but better than a
            // child tied to the service lifetime.
            Log.Warn("Updater: scheduled-task launch failed; falling back to detached process launch.");
            try
            {
                Process.Start(new ProcessStartInfo("cmd.exe", "/c \"" + cmdPath + "\"")
                {
                    UseShellExecute = true,      // route through the shell (own process group)
                    CreateNoWindow = true,
                    WindowStyle = ProcessWindowStyle.Hidden,
                });
                Log.Info("Updater: upgrade launched (fallback); the service will be restarted by the installer.");
            }
            catch (Exception ex)
            {
                Log.Warn("Updater: fallback launch failed: " + ex.Message);
            }
        }

        // One-shot upgrade task name (deleted by the task's own cmd after msiexec returns).
        private const string TaskName = "EightWestAgentUpdate";

        /// <summary>
        /// Register and immediately run a one-shot Scheduled Task (SYSTEM, highest privileges)
        /// that drives the MSI upgrade decoupled from the agent service process tree. Returns
        /// true if the task was created and started. BCL-only (schtasks.exe), no new NuGet.
        /// </summary>
        private static bool TryLaunchViaScheduledTask(string cmdPath, string target)
        {
            try
            {
                // /create — replace any stale task (/f), run as SYSTEM with highest privileges,
                // ONCE, schedule defensively in the past so it is runnable; we then /run it now.
                var createArgs =
                    "/create /tn \"" + TaskName + "\" /tr \"cmd.exe /c \\\"" + cmdPath + "\\\"\" " +
                    "/sc ONCE /st 00:00 /ru SYSTEM /rl HIGHEST /f";
                if (!RunSchtasks(createArgs))
                {
                    Log.Warn("Updater: schtasks /create failed for upgrade task.");
                    return false;
                }

                // Kick it off now (the task host is svchost, not the agent service).
                if (!RunSchtasks("/run /tn \"" + TaskName + "\""))
                {
                    Log.Warn("Updater: schtasks /run failed for upgrade task; cleaning up.");
                    try { RunSchtasks("/delete /tn \"" + TaskName + "\" /f"); } catch { }
                    return false;
                }

                Log.Info($"Updater: one-shot upgrade task '{TaskName}' created and started for {target}.");
                return true;
            }
            catch (Exception ex)
            {
                Log.Warn("Updater: scheduled-task launch error: " + ex.Message);
                return false;
            }
        }

        /// <summary>Run schtasks.exe with the given args; return true on exit code 0 (within timeout).</summary>
        private static bool RunSchtasks(string args)
        {
            try
            {
                using (var p = new Process())
                {
                    p.StartInfo = new ProcessStartInfo("schtasks.exe", args)
                    {
                        UseShellExecute = false,
                        CreateNoWindow = true,
                        RedirectStandardOutput = true,
                        RedirectStandardError = true,
                    };
                    if (!p.Start()) return false;
                    p.StandardOutput.ReadToEnd();
                    p.StandardError.ReadToEnd();
                    if (!p.WaitForExit(30000))
                    {
                        try { p.Kill(); } catch { }
                        return false;
                    }
                    return p.ExitCode == 0;
                }
            }
            catch { return false; }
        }

        private static string Str(Dictionary<string, object> r, string k) =>
            r != null && r.ContainsKey(k) && r[k] != null ? r[k].ToString() : "";
    }
}
