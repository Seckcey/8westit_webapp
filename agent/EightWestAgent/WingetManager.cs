using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Text.RegularExpressions;

namespace EightWest.Agent
{
    /// <summary>
    /// Third-party application patching via winget (Windows Package Manager) — Phase 3.
    /// Scan() lists available app upgrades; Install() upgrades selected package Ids. winget ships with
    /// App Installer on Win10 1809+/11. The agent runs as LocalSystem, so winget.exe is resolved from
    /// the per-machine WindowsApps DesktopAppInstaller folder (it is usually NOT on SYSTEM's PATH), and
    /// runs in MACHINE context — user-scoped installs may not appear (honest limitation, surfaced in UI).
    /// English-locale table headers are assumed. Never throws — empty report / error summary on failure.
    /// </summary>
    public static class WingetManager
    {
        private static string _portalUrl;
        private static Func<string> _token;
        public static void Init(string portalUrl, Func<string> tokenProvider) { _portalUrl = portalUrl; _token = tokenProvider; }

        // Prefer the real per-machine winget.exe (WindowsApps); the PATH entry under a user is a 0-byte
        // App Execution Alias that does not work under SYSTEM. Fall back to PATH, then a bare name.
        private static string ResolveWinget()
        {
            try
            {
                var root = Path.Combine(Environment.GetEnvironmentVariable("ProgramFiles") ?? @"C:\Program Files", "WindowsApps");
                if (Directory.Exists(root))
                {
                    var dirs = Directory.GetDirectories(root, "Microsoft.DesktopAppInstaller_*_x64__8wekyb3d8bbwe");
                    Array.Sort(dirs, StringComparer.OrdinalIgnoreCase); Array.Reverse(dirs);   // newest version first
                    foreach (var d in dirs)
                    {
                        var exe = Path.Combine(d, "winget.exe");
                        if (File.Exists(exe)) return exe;
                    }
                }
            }
            catch { }
            try
            {
                var w = RunRaw("where.exe", "winget.exe", 15000, out _);
                foreach (var line in (w ?? "").Replace("\r", "").Split('\n'))
                {
                    var t = line.Trim();
                    if (t.EndsWith("winget.exe", StringComparison.OrdinalIgnoreCase) && File.Exists(t)) return t;
                }
            }
            catch { }
            return "winget.exe";
        }

        public static Dictionary<string, object> Scan()
        {
            try
            {
                var winget = ResolveWinget();
                var outp = RunRaw(winget,
                    "upgrade --include-unknown --accept-source-agreements --disable-interactivity",
                    3 * 60 * 1000, out _);
                return new Dictionary<string, object> { ["apps"] = ParseUpgradeTable(outp) };
            }
            catch (Exception ex)
            {
                Log.Warn("winget scan failed: " + ex.Message);
                return new Dictionary<string, object> { ["apps"] = new List<object>() };
            }
        }

        public static string ScanAndReport()
        {
            var report = Scan();
            Report(report);
            var apps = report.ContainsKey("apps") ? report["apps"] as System.Collections.ICollection : null;
            return "winget scan complete: " + (apps?.Count ?? 0) + " app upgrade(s) available.";
        }

        private static void Report(Dictionary<string, object> report)
        {
            if (string.IsNullOrEmpty(_portalUrl) || _token == null) return;
            try { new ApiClient(_portalUrl, _token()).Post("/api/winget_report.php", report); }
            catch (Exception ex) { Log.Warn("winget report failed: " + ex.Message); }
        }

        /// <summary>
        /// Upgrade the given winget package Ids ("Google.Chrome,Mozilla.Firefox"), then re-scan + report.
        /// Long-running; the caller runs this OFF the heartbeat loop. Never throws.
        /// </summary>
        public static string Install(string idCsv)
        {
            var ids = SanitizeIds(idCsv);
            if (ids.Count == 0) return "No valid package Ids requested.";
            var winget = ResolveWinget();
            int ok = 0, fail = 0;
            var msgs = new List<string>();
            foreach (var id in ids)
            {
                try
                {
                    RunRaw(winget,
                        "upgrade --id " + id + " --exact --silent --accept-source-agreements --accept-package-agreements --disable-interactivity",
                        30 * 60 * 1000, out int code);
                    // winget: 0 = success; -1978335189 (0x8A15002B) = no applicable upgrade.
                    if (code == 0) { ok++; msgs.Add(id + ": upgraded"); }
                    else { fail++; msgs.Add(id + ": exit " + code); }
                }
                catch (Exception ex) { fail++; msgs.Add(id + ": " + ex.Message); }
            }
            ScanAndReport();   // refresh the portal's app-update list after upgrading
            return "winget upgrade: " + ok + " ok, " + fail + " failed (of " + ids.Count + "). " + string.Join("; ", msgs);
        }

        // winget package Ids: letters/digits then letters/digits/'.'/'-'/'+'/'_' (Google.Chrome, Microsoft.PowerShell).
        private static readonly Regex IdRe = new Regex(@"^[A-Za-z0-9][A-Za-z0-9.\-+_]{0,127}$", RegexOptions.Compiled);

        private static List<string> SanitizeIds(string csv)
        {
            var list = new List<string>();
            foreach (var raw in (csv ?? "").Split(','))
            {
                var t = raw.Trim();
                if (IdRe.IsMatch(t) && !list.Contains(t)) list.Add(t);
            }
            return list;
        }

        // Parse the fixed-width `winget upgrade` table using the header's column start positions.
        private static List<object> ParseUpgradeTable(string text)
        {
            var apps = new List<object>();
            if (string.IsNullOrWhiteSpace(text)) return apps;
            var lines = text.Replace("\r", "").Split('\n');
            int hi = -1;
            for (int i = 0; i < lines.Length; i++)
            {
                var l = lines[i];
                if (l.StartsWith("Name") && l.Contains("Id") && l.Contains("Version") && l.Contains("Available")) { hi = i; break; }
            }
            if (hi < 0) return apps;
            var h = lines[hi];
            int cId = h.IndexOf("Id", StringComparison.Ordinal);
            int cVer = h.IndexOf("Version", StringComparison.Ordinal);
            int cAvail = h.IndexOf("Available", StringComparison.Ordinal);
            int cSrc = h.IndexOf("Source", StringComparison.Ordinal);
            if (cId < 0 || cVer < 0 || cAvail < 0) return apps;
            for (int i = hi + 2; i < lines.Length; i++)   // +2 skips the dashed separator row
            {
                var l = lines[i];
                if (l.Length < cAvail) continue;
                if (l.TrimStart().StartsWith("-")) continue;
                if (Regex.IsMatch(l.Trim(), @"^\d+\s+upgrade", RegexOptions.IgnoreCase)) break;   // trailing summary
                string Slice(int a, int b)
                {
                    if (a >= l.Length) return "";
                    int end = (b < 0 || b > l.Length) ? l.Length : b;
                    return end > a ? l.Substring(a, end - a).Trim() : "";
                }
                var id = Slice(cId, cVer);
                if (id.Length == 0 || !IdRe.IsMatch(id)) continue;
                var avail = Slice(cAvail, cSrc);
                if (avail.Length == 0) continue;
                apps.Add(new Dictionary<string, object>
                {
                    ["id"]        = id,
                    ["name"]      = Slice(0, cId),
                    ["version"]   = Slice(cVer, cAvail),
                    ["available"] = avail,
                    ["source"]    = cSrc >= 0 ? Slice(cSrc, -1) : "",
                });
                if (apps.Count >= 300) break;
            }
            return apps;
        }

        // Run a process, capture stdout + exit code (async reads = no deadlock, hard timeout).
        private static string RunRaw(string exe, string args, int timeoutMs, out int exitCode)
        {
            var psi = new ProcessStartInfo(exe, args)
            {
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true,
            };
            var outSb = new StringBuilder();
            var errSb = new StringBuilder();
            using (var p = new Process { StartInfo = psi })
            {
                p.OutputDataReceived += (s, e) => { if (e.Data != null) outSb.AppendLine(e.Data); };
                p.ErrorDataReceived += (s, e) => { if (e.Data != null) errSb.AppendLine(e.Data); };
                p.Start();
                p.BeginOutputReadLine();
                p.BeginErrorReadLine();
                if (!p.WaitForExit(timeoutMs)) { try { p.Kill(); } catch { } throw new Exception("winget timed out"); }
                p.WaitForExit();
                exitCode = p.ExitCode;
                return outSb.ToString();
            }
        }
    }
}
