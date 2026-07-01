using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Text;
using System.Web.Script.Serialization;

namespace EightWest.Agent
{
    /// <summary>
    /// Windows Update manager (Phase 3: scan / install / rollback). Shells short PowerShell scripts
    /// against the Windows Update Agent COM API (Microsoft.Update.Session) and DISM. Scan queries
    /// applicable, not-yet-installed updates and returns a report body matching /api/patch_report.php
    /// ({ pending:[{kb,title,classification,severity,reboot_required}], reboot_pending }); Install
    /// downloads+installs selected KBs; Rollback best-effort uninstalls them (honest per-KB
    /// reversibility). Never throws — returns an empty report / error summary on any failure.
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

        // Set once by Worker (after enroll) so the scan/install JOBS can self-report to the portal.
        private static string _portalUrl;
        private static Func<string> _token;
        public static void Init(string portalUrl, Func<string> tokenProvider)
        {
            _portalUrl = portalUrl;
            _token = tokenProvider;
        }

        /// <summary>Scan Windows Update and POST the result to /api/patch_report.php; returns a summary.</summary>
        public static string ScanAndReport()
        {
            var report = Scan();
            Report(report);
            var pending = report.ContainsKey("pending") ? report["pending"] as System.Collections.ICollection : null;
            return "Scan complete: " + (pending?.Count ?? 0) + " update(s) pending.";
        }

        private static void Report(Dictionary<string, object> report)
        {
            if (string.IsNullOrEmpty(_portalUrl) || _token == null) return;
            try { new ApiClient(_portalUrl, _token()).Post("/api/patch_report.php", report); }
            catch (Exception ex) { Log.Warn("Patch report failed: " + ex.Message); }
        }

        /// <summary>
        /// Install the given KBs ("KB5049999,KB5000002") via WUA (download + install), then re-scan +
        /// report so the portal refreshes. Long-running; the caller runs this OFF the heartbeat loop.
        /// Never reboots — the summary reports whether a reboot is required. Never throws.
        /// </summary>
        public static string Install(string kbCsv)
        {
            var kbs = SanitizeKbs(kbCsv);
            if (kbs.Count == 0) return "No valid KBs requested.";
            try
            {
                var json = RunPowerShell(InstallScript(string.Join(",", kbs)), 60 * 60 * 1000);   // up to 60 min
                var res = new JavaScriptSerializer { MaxJsonLength = 16_000_000 }
                    .Deserialize<Dictionary<string, object>>(json ?? "");
                string summary;
                if (res == null) summary = "Install finished (no result returned).";
                else
                {
                    int code = res.ContainsKey("result_code") ? Convert.ToInt32(res["result_code"]) : -1;
                    bool reboot = res.ContainsKey("reboot_required") && Convert.ToBoolean(res["reboot_required"]);
                    // WUA OperationResultCode: 0=none matched, 2=Succeeded, 3=SucceededWithErrors, 4=Failed, 5=Aborted.
                    string status = code == 2 ? "succeeded"
                                  : code == 3 ? "succeeded with errors"
                                  : code == 0 ? "no matching updates found"
                                  : "failed (code " + code + ")";
                    summary = "Install " + status + " for " + kbs.Count + " update(s)." + (reboot ? " REBOOT REQUIRED." : "");
                }
                ScanAndReport();   // refresh the portal's patch status after the install
                return summary;
            }
            catch (Exception ex)
            {
                Log.Warn("Patch install failed: " + ex.Message);
                return "Install failed: " + ex.Message;
            }
        }

        // Accept ONLY KB<digits> tokens — this list is injected into the install PowerShell.
        private static List<string> SanitizeKbs(string csv)
        {
            var list = new List<string>();
            foreach (var raw in (csv ?? "").Split(','))
            {
                var t = raw.Trim().ToUpperInvariant();
                if (System.Text.RegularExpressions.Regex.IsMatch(t, "^KB[0-9]{4,10}$") && !list.Contains(t))
                    list.Add(t);
            }
            return list;
        }

        private static string InstallScript(string kbList) => @"
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$want='" + kbList + @"'.Split(',') | ForEach-Object { $_.Trim().ToUpper() } | Where-Object { $_ }
$session=New-Object -ComObject Microsoft.Update.Session
$searcher=$session.CreateUpdateSearcher()
$r=$searcher.Search('IsInstalled=0 and IsHidden=0')
$coll=New-Object -ComObject Microsoft.Update.UpdateColl
foreach($u in $r.Updates){
  $kbs=@($u.KBArticleIDs | ForEach-Object {'KB'+$_.ToUpper()})
  if(@($kbs | Where-Object { $want -contains $_ }).Count -gt 0){
    if(-not $u.EulaAccepted){ try { $u.AcceptEula() } catch {} }
    [void]$coll.Add($u)
  }
}
if($coll.Count -eq 0){ [PSCustomObject]@{ result_code=0; reboot_required=$false } | ConvertTo-Json -Compress; exit 0 }
$dl=$session.CreateUpdateDownloader(); $dl.Updates=$coll; [void]$dl.Download()
$inst=$session.CreateUpdateInstaller(); $inst.Updates=$coll; $res=$inst.Install()
[PSCustomObject]@{ result_code=[int]$res.ResultCode; reboot_required=[bool]$res.RebootRequired } | ConvertTo-Json -Compress
";

        /// <summary>
        /// Best-effort uninstall of the given KBs, then re-scan + report so the portal refreshes.
        /// DISM (Remove-WindowsPackage) is tried first, wusa second. HONEST reversibility: servicing-stack,
        /// feature, and many cumulative updates cannot be uninstalled — those come back ok=false,
        /// reversible=false with a plain message (fix-forward is the primary path). Long-running; the
        /// caller runs this OFF the heartbeat loop. Never reboots. Never throws.
        /// </summary>
        public static string Rollback(string kbCsv)
        {
            var kbs = SanitizeKbs(kbCsv);
            if (kbs.Count == 0) return "No valid KBs requested.";
            try
            {
                var json = RunPowerShell(RollbackScript(string.Join(",", kbs)), 60 * 60 * 1000);   // up to 60 min
                var res = new JavaScriptSerializer { MaxJsonLength = 16_000_000 }
                    .Deserialize<Dictionary<string, object>>(json ?? "");
                string summary;
                if (res == null || !res.ContainsKey("results")) summary = "Rollback finished (no result returned).";
                else
                {
                    int ok = 0, irreversible = 0, failed = 0, total = 0;
                    bool reboot = res.ContainsKey("reboot_required") && Convert.ToBoolean(res["reboot_required"]);
                    if (res["results"] is System.Collections.IEnumerable list)
                        foreach (var row in list)
                        {
                            total++;
                            if (!(row is Dictionary<string, object> d)) continue;
                            bool rok = d.ContainsKey("ok") && Convert.ToBoolean(d["ok"]);
                            bool rev = !d.ContainsKey("reversible") || Convert.ToBoolean(d["reversible"]);
                            if (rok) ok++; else if (!rev) irreversible++; else failed++;
                        }
                    summary = "Rollback: " + ok + " removed, " + irreversible + " not reversible, "
                            + failed + " failed (of " + total + ")." + (reboot ? " REBOOT REQUIRED." : "");
                }
                ScanAndReport();   // refresh the portal's patch status after the rollback
                return summary;
            }
            catch (Exception ex)
            {
                Log.Warn("Patch rollback failed: " + ex.Message);
                return "Rollback failed: " + ex.Message;
            }
        }

        // Per KB: find the matching DISM package and Remove-WindowsPackage; if there's no DISM package,
        // fall back to wusa /uninstall. Anything DISM/wusa refuses is reported reversible=false (honest).
        private static string RollbackScript(string kbList) => @"
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$want='" + kbList + @"'.Split(',') | ForEach-Object { $_.Trim().ToUpper() } | Where-Object { $_ }
$results=@()
$rebootAny=$false
try { $pkgs=@(Get-WindowsPackage -Online) } catch { $pkgs=@() }
foreach($kb in $want){
  $num=$kb -replace '^KB',''
  $ok=$false; $rev=$true; $method=''; $msg=''; $rb=$false
  $match=@($pkgs | Where-Object { $_.PackageName -match ('KB'+$num) })
  if($match.Count -gt 0){
    $method='dism'
    foreach($m in $match){
      try {
        $r=Remove-WindowsPackage -Online -PackageName $m.PackageName -NoRestart -ErrorAction Stop
        $ok=$true; $msg='removed via DISM'
        if(([string]$r.RestartNeeded) -notin @('No','','0')){ $rb=$true }
      } catch {
        $ok=$false; $rev=$false; $msg='DISM cannot remove (likely permanent/SSU/feature): '+$_.Exception.Message
      }
    }
  } else {
    $method='wusa'
    try {
      $p=Start-Process -FilePath 'wusa.exe' -ArgumentList ('/uninstall /kb:'+$num+' /quiet /norestart') -Wait -PassThru
      switch([int]$p.ExitCode){
        0 { $ok=$true; $msg='removed via wusa' }
        3010 { $ok=$true; $rb=$true; $msg='removed via wusa (reboot required)' }
        2149842967 { $ok=$false; $rev=$false; $msg='not uninstallable (0x80240017)' }
        1605 { $ok=$false; $rev=$false; $msg='not installed (1605)' }
        default { $ok=$false; $rev=$false; $msg='wusa exit '+$p.ExitCode }
      }
    } catch { $ok=$false; $rev=$false; $msg='wusa failed: '+$_.Exception.Message }
  }
  if($rb){ $rebootAny=$true }
  $results += [PSCustomObject]@{ kb=$kb; ok=$ok; reversible=$rev; method=$method; message=$msg; reboot_required=$rb }
}
[PSCustomObject]@{ results=@($results); reboot_required=$rebootAny } | ConvertTo-Json -Depth 4 -Compress
";

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
