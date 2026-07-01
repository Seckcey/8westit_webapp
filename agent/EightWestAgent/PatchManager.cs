using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Text;
using System.Web.Script.Serialization;

namespace EightWest.Agent
{
    /// <summary>
    /// Windows Update scanner (Phase 3 MVP: scan-and-report only — no installs). Shells a short
    /// PowerShell script that queries the Windows Update Agent COM API (Microsoft.Update.Session)
    /// for applicable, not-yet-installed updates, and returns a report body that matches
    /// /api/patch_report.php ({ pending:[{kb,title,classification,severity,reboot_required}],
    /// reboot_pending }). Never throws — returns an empty report on any failure.
    /// </summary>
    public static class PatchManager
    {
        // Uses the same WUA COM query proven against the live agent (IsInstalled=0). Emits compact
        // JSON on stdout. reboot_pending reflects a machine already waiting on a reboot to finish a
        // prior install (the standard CBS / WindowsUpdate reboot-required registry markers).
        private const string ScanScript = @"
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$session=New-Object -ComObject Microsoft.Update.Session
$searcher=$session.CreateUpdateSearcher()
$r=$searcher.Search('IsInstalled=0 and IsHidden=0')
$pending=@()
foreach($u in $r.Updates){
  $kb=@($u.KBArticleIDs | ForEach-Object {'KB' + $_}) -join ','
  $cats=@($u.Categories | ForEach-Object { $_.Name }) -join ','
  $reboot = ($u.InstallationBehavior -ne $null) -and ($u.InstallationBehavior.RebootBehavior -ne 0)
  $pending += [PSCustomObject]@{ kb=$kb; title=[string]$u.Title; classification=$cats; severity=[string]$u.MsrcSeverity; reboot_required=[bool]$reboot }
}
$rp=$false
foreach($p in @('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending','HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired')){ if(Test-Path $p){$rp=$true} }
[PSCustomObject]@{ pending=@($pending); reboot_pending=$rp } | ConvertTo-Json -Depth 4 -Compress
";

        public static Dictionary<string, object> Scan()
        {
            try
            {
                var json = RunPowerShell(ScanScript, 3 * 60 * 1000);
                if (string.IsNullOrWhiteSpace(json)) return Empty();
                var parsed = new JavaScriptSerializer { MaxJsonLength = 16_000_000 }
                    .Deserialize<Dictionary<string, object>>(json);
                return parsed ?? Empty();
            }
            catch (Exception ex)
            {
                Log.Warn("Patch scan failed: " + ex.Message);
                return Empty();
            }
        }

        private static Dictionary<string, object> Empty() =>
            new Dictionary<string, object> { ["pending"] = new List<object>(), ["reboot_pending"] = false };

        // Mirrors JobRunner.RunShell: async stdout/stderr reads (no deadlock) + a hard timeout.
        // -EncodedCommand (UTF-16LE base64) sidesteps all quoting/escaping of the multi-line script.
        private static string RunPowerShell(string script, int timeoutMs)
        {
            var encoded = Convert.ToBase64String(Encoding.Unicode.GetBytes(script));
            var psi = new ProcessStartInfo("powershell.exe",
                "-NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand " + encoded)
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
                p.ErrorDataReceived  += (s, e) => { if (e.Data != null) errSb.AppendLine(e.Data); };
                p.Start();
                p.BeginOutputReadLine();
                p.BeginErrorReadLine();
                if (!p.WaitForExit(timeoutMs))
                {
                    try { p.Kill(); } catch { }
                    throw new Exception("WUA scan timed out");
                }
                p.WaitForExit(); // flush async buffers
                var outStr = outSb.ToString().Trim();
                if (p.ExitCode != 0 && outStr.Length == 0)
                    throw new Exception("powershell exit " + p.ExitCode + ": " + errSb.ToString().Trim());
                return outStr;
            }
        }
    }
}
