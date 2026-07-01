using System;
using System.Diagnostics;
using System.IO;
using System.Reflection;

namespace EightWest.Agent
{
    /// <summary>
    /// Recovers from a "stale running image" — the update landed on disk (the MSI upgraded
    /// EightWestAgent.exe) but the live service process was never cycled, so it keeps running
    /// (and reporting) the OLD binary while the portal re-advertises the same update forever.
    /// (Observed live on the 1.1.8 rollout: on-disk = 1.1.8, running service still = 1.1.7,
    /// looping in its 6 h update backoff until a human restarted the service.)
    ///
    /// <see cref="CheckStaleBinary"/> compares the RUNNING assembly version (<see cref="Worker.Version"/>,
    /// derived from the loaded assembly) against the ProductVersion of the on-disk service exe
    /// (this process's ImagePath). When on-disk is strictly newer, it requests a one-shot SYSTEM
    /// service restart (net stop/start via a Scheduled Task, decoupled from this process) so SCM
    /// relaunches the new binary. A restart preserves identity/token (state.json) and the
    /// EnrollKey (registry) — nothing about those is touched.
    ///
    /// SAFETY: idempotent + loop-safe — restarts AT MOST ONCE per detected on-disk version
    /// (persisted marker in state.json), holds off while an update was just launched (lets the
    /// MSI + its own restart settle), and NEVER throws (best-effort; the main loop must keep
    /// running regardless).
    /// </summary>
    internal static class SelfHeal
    {
        // One-shot restart task name (the task's own cmd deletes it after net start). Distinct
        // from the Updater's "EightWestAgentUpdate" task so the two never collide.
        private const string TaskName = "EightWestAgentSelfHeal";

        // Hold off self-heal while a self-update was launched this recently: the update path's
        // own MSI + service restart may still be settling. Only after this passes with the
        // mismatch still present do we conclude the restart genuinely missed and step in.
        private static readonly TimeSpan UpdateSettleGrace = TimeSpan.FromMinutes(10);

        /// <summary>
        /// Detect + recover a stale running image. Best-effort; swallows all exceptions. Safe
        /// to call at startup and periodically from the main loop.
        /// </summary>
        public static void CheckStaleBinary(AgentState state)
        {
            try
            {
                var running = ParseVersionPrefix(Worker.Version);
                var exePath = CurrentExePath();
                if (running == null || string.IsNullOrEmpty(exePath)) return;

                var onDisk = OnDiskVersion(exePath);
                if (onDisk == null) return;

                // Healthy: the running image has caught up to (or is newer than) the on-disk
                // binary. Clear any stale marker so a FUTURE update gets a fresh single retry.
                if (Compare(onDisk, running) <= 0)
                {
                    if (!string.IsNullOrEmpty(state.SelfHealRestartVersion))
                    {
                        Log.Info($"SelfHeal: running {Canon(running)} >= on-disk {Canon(onDisk)}; clearing self-heal marker.");
                        state.ClearSelfHealMarker();
                    }
                    return;
                }

                // Mismatch: the on-disk binary is NEWER than this process — an update landed
                // but the service was not cycled.
                var onDiskStr = Canon(onDisk);
                Log.Warn($"SelfHeal: on-disk binary {onDiskStr} is NEWER than the running process {Canon(running)} " +
                         $"— an update landed but the service was not cycled. exe={exePath}");

                // Let the update path's own MSI/restart settle before we intervene, so we never
                // race a legitimate in-flight upgrade.
                if (state.RecentUpdateAttempt(UpdateSettleGrace))
                {
                    Log.Info("SelfHeal: an update was launched recently; deferring self-restart to let the installer settle.");
                    return;
                }

                // Loop-safety: restart AT MOST ONCE per detected on-disk version.
                if (state.AlreadySelfHealedFor(onDiskStr))
                {
                    Log.Warn($"SelfHeal: already requested a self-restart for {onDiskStr} at {state.SelfHealRestartAtUtc} " +
                             "but the process is STILL the old image — not restarting again (manual attention needed).");
                    return;
                }

                RequestServiceRestart(state, onDiskStr);
            }
            catch (Exception ex)
            {
                // NEVER throw — self-heal is best-effort; the main loop must keep running.
                Log.Warn("SelfHeal: " + ex.Message);
            }
        }

        /// <summary>
        /// Full path of the on-disk service exe this process launched from (= the service
        /// ImagePath). Reading version info from it reflects the CURRENT file on disk, even
        /// after an MSI swapped it underneath the still-running process.
        /// </summary>
        private static string CurrentExePath()
        {
            try
            {
                using (var p = Process.GetCurrentProcess())
                {
                    var path = p.MainModule?.FileName;
                    if (!string.IsNullOrEmpty(path) && File.Exists(path)) return path;
                }
            }
            catch { /* fall through */ }
            try
            {
                var loc = Assembly.GetExecutingAssembly().Location;
                if (!string.IsNullOrEmpty(loc) && File.Exists(loc)) return loc;
            }
            catch { }
            return null;
        }

        /// <summary>
        /// The on-disk exe's version, from its ProductVersion (falling back to FileVersion),
        /// numeric prefix only so a "1.1.9+&lt;gitsha&gt;" informational-version suffix compares
        /// cleanly against the numeric running version.
        /// </summary>
        private static Version OnDiskVersion(string exePath)
        {
            try
            {
                var fvi = FileVersionInfo.GetVersionInfo(exePath);
                return ParseVersionPrefix(fvi.ProductVersion) ?? ParseVersionPrefix(fvi.FileVersion);
            }
            catch { return null; }
        }

        /// <summary>
        /// Parse the leading numeric "a.b[.c[.d]]" of a version string, ignoring any
        /// InformationalVersion suffix (e.g. "1.1.9+abc123" or "1.1.9-rc1"). Null when there is
        /// no leading numeric component.
        /// </summary>
        internal static Version ParseVersionPrefix(string s)
        {
            if (string.IsNullOrWhiteSpace(s)) return null;
            s = s.Trim();
            int i = 0;
            while (i < s.Length && (char.IsDigit(s[i]) || s[i] == '.')) i++;
            var prefix = s.Substring(0, i).Trim('.');
            return Version.TryParse(prefix, out var v) ? v : null;
        }

        /// <summary>
        /// Compare two versions on Major.Minor.Build only (our 3-part semantic scheme), so a
        /// differing part count / phantom revision never registers as a spurious diff (e.g.
        /// "1.1.8" vs "1.1.8.0").
        /// </summary>
        private static int Compare(Version a, Version b)
        {
            int c;
            if ((c = a.Major.CompareTo(b.Major)) != 0) return c;
            if ((c = a.Minor.CompareTo(b.Minor)) != 0) return c;
            int ab = a.Build < 0 ? 0 : a.Build, bb = b.Build < 0 ? 0 : b.Build;
            return ab.CompareTo(bb);
        }

        /// <summary>Canonical "M.m.b" string for logs and the loop-safety marker.</summary>
        private static string Canon(Version v) =>
            v.Major + "." + v.Minor + "." + (v.Build < 0 ? 0 : v.Build);

        /// <summary>
        /// Launch a one-shot SYSTEM Scheduled Task that cycles the service (net stop/start),
        /// decoupled from this process so it survives our own OnStop. The loop-safety marker is
        /// recorded only AFTER a successful launch, so a transient schtasks failure retries next
        /// cycle instead of being permanently suppressed.
        /// </summary>
        private static void RequestServiceRestart(AgentState state, string onDiskStr)
        {
            try
            {
                var dir = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                    "8WestIT", "Agent", "update");
                Directory.CreateDirectory(dir);

                var cmdPath = Path.Combine(dir, "self-heal-restart.cmd");
                File.WriteAllText(cmdPath,
                    "@echo off\r\n" +
                    "net stop " + Program.ServiceName + "\r\n" +
                    "net start " + Program.ServiceName + "\r\n" +
                    // Best-effort cleanup of the one-shot task, then the script deletes itself.
                    "schtasks /delete /tn \"" + TaskName + "\" /f >nul 2>&1\r\n" +
                    "del \"%~f0\"\r\n");

                if (TryLaunchViaScheduledTask(cmdPath))
                {
                    // Mark AFTER a successful launch: one restart per on-disk version.
                    state.RecordSelfHealRestart(onDiskStr);
                    Log.Warn($"SelfHeal: one-shot SYSTEM restart scheduled to load {onDiskStr} (once). " +
                             "state.json + EnrollKey are preserved across the restart.");
                }
                else
                {
                    Log.Warn("SelfHeal: could not schedule the restart task; will retry next cycle.");
                }
            }
            catch (Exception ex)
            {
                Log.Warn("SelfHeal: restart request failed: " + ex.Message);
            }
        }

        /// <summary>
        /// Register a one-shot SYSTEM task (replacing any stale one) and run it now. The task
        /// host is svchost — NOT a child of this service — so it survives our own net stop.
        /// BCL-only (schtasks.exe), no new NuGet.
        /// </summary>
        private static bool TryLaunchViaScheduledTask(string cmdPath)
        {
            var createArgs =
                "/create /tn \"" + TaskName + "\" /tr \"cmd.exe /c \\\"" + cmdPath + "\\\"\" " +
                "/sc ONCE /st 00:00 /ru SYSTEM /rl HIGHEST /f";
            if (!RunSchtasks(createArgs)) { Log.Warn("SelfHeal: schtasks /create failed."); return false; }

            if (!RunSchtasks("/run /tn \"" + TaskName + "\""))
            {
                Log.Warn("SelfHeal: schtasks /run failed; cleaning up.");
                try { RunSchtasks("/delete /tn \"" + TaskName + "\" /f"); } catch { }
                return false;
            }
            return true;
        }

        /// <summary>Run schtasks.exe with the given args; true on exit code 0 within the timeout.</summary>
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
                    if (!p.WaitForExit(30000)) { try { p.Kill(); } catch { } return false; }
                    return p.ExitCode == 0;
                }
            }
            catch { return false; }
        }
    }
}
