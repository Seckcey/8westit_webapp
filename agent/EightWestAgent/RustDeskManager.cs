using System;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net;
using System.Text;

namespace EightWest.Agent
{
    /// <summary>
    /// Owns the RustDesk client used for remote-support sessions:
    ///  - silently installs RustDesk if it isn't present (downloaded on first run)
    ///  - points it at your self-hosted relay (hbbs/hbbr on your VPS)
    ///  - sets a permanent unattended password
    ///  - reads the device ID to report back to the portal
    ///
    /// Targets RustDesk 1.4.x installed as a service. The installer URL can be
    /// overridden per-machine via HKLM\SOFTWARE\8WestIT\Agent\RustDeskUrl.
    /// </summary>
    public class RustDeskManager
    {
        // Pinned, verified Windows x64 build. Override via config to bump versions.
        public const string DefaultInstallerUrl =
            "https://github.com/rustdesk/rustdesk/releases/download/1.4.8/rustdesk-1.4.8-x86_64.exe";

        private readonly string _relayHost;
        private readonly string _relayKey;
        private readonly string _installerUrl;

        public RustDeskManager(string relayHost, string relayKey, string installerUrl = null)
        {
            _relayHost = relayHost ?? "";
            _relayKey = relayKey ?? "";
            _installerUrl = string.IsNullOrEmpty(installerUrl) ? DefaultInstallerUrl : installerUrl;
        }

        public static string ExePath
        {
            get
            {
                var pf = Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles);
                var candidate = Path.Combine(pf, "RustDesk", "rustdesk.exe");
                if (File.Exists(candidate)) return candidate;
                var pf86 = Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86);
                candidate = Path.Combine(pf86, "RustDesk", "rustdesk.exe");
                return File.Exists(candidate) ? candidate : null;
            }
        }

        public bool IsInstalled => ExePath != null;

        /// <summary>
        /// Make sure RustDesk is installed. If missing, download the installer and run it
        /// silently (which also registers RustDesk's own service for unattended access).
        /// Returns true once rustdesk.exe is present.
        /// </summary>
        public bool EnsureInstalled()
        {
            if (IsInstalled) return true;

            // 1) Prefer the installer bundled in the MSI (sits next to the agent exe).
            //    Self-contained — works even on networks that block GitHub.
            var bundled = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "rustdesk-setup.exe");
            if (File.Exists(bundled) && RunInstaller(bundled, deleteAfter: false))
                return true;

            // 2) Fall back to downloading the pinned version.
            if (string.IsNullOrEmpty(_installerUrl)) return false;
            var tmp = Path.Combine(Path.GetTempPath(), "rustdesk-setup.exe");
            try
            {
                ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12 | SecurityProtocolType.Tls11;
                Log.Info("Downloading RustDesk installer: " + _installerUrl);
                using (var wc = new WebClient())
                    wc.DownloadFile(_installerUrl, tmp);
                return RunInstaller(tmp, deleteAfter: true);
            }
            catch (Exception ex)
            {
                Log.Warn("RustDesk download/install failed: " + ex.Message);
                return false;
            }
        }

        private bool RunInstaller(string installerPath, bool deleteAfter)
        {
            try
            {
                Log.Info("Installing RustDesk (" + Path.GetFileName(installerPath) + ")...");
                RunNoCapture(installerPath, "--silent-install", 180000);
                System.Threading.Thread.Sleep(5000); // give the installer time to register the service
                if (IsInstalled) { Log.Info("RustDesk installed."); return true; }
                Log.Warn("RustDesk installer ran but rustdesk.exe was not found yet.");
                return false;
            }
            catch (Exception ex)
            {
                Log.Warn("RustDesk install failed: " + ex.Message);
                return false;
            }
            finally
            {
                if (deleteAfter) { try { if (File.Exists(installerPath)) File.Delete(installerPath); } catch { } }
            }
        }

        /// <summary>Write relay settings into every account profile RustDesk's service might use.</summary>
        public void ApplyRelayConfig()
        {
            if (string.IsNullOrEmpty(_relayHost)) return;
            try
            {
                // The RustDesk service typically runs as LocalSystem; cover the common profiles.
                var paths = new[]
                {
                    @"C:\Windows\System32\config\systemprofile\AppData\Roaming\RustDesk\config",
                    @"C:\Windows\ServiceProfiles\LocalService\AppData\Roaming\RustDesk\config",
                    Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                                 "RustDesk", "config"),
                };
                foreach (var p in paths) WriteToml(p);
                Log.Info("RustDesk relay config applied (" + _relayHost + ").");
            }
            catch (Exception ex) { Log.Warn("ApplyRelayConfig failed: " + ex.Message); }
        }

        private void WriteToml(string configDir)
        {
            Directory.CreateDirectory(configDir);
            var path = Path.Combine(configDir, "RustDesk2.toml");
            var sb = new StringBuilder();
            sb.AppendLine("rendezvous_server = '" + _relayHost + ":21116'");
            sb.AppendLine("nat_type = 1");
            sb.AppendLine("[options]");
            sb.AppendLine("custom-rendezvous-server = '" + _relayHost + "'");
            sb.AppendLine("relay-server = '" + _relayHost + "'");
            sb.AppendLine("api-server = ''");
            if (!string.IsNullOrEmpty(_relayKey))
                sb.AppendLine("key = '" + _relayKey + "'");
            File.WriteAllText(path, sb.ToString());
        }

        /// <summary>Ensure a permanent password is set; returns it (generating + applying if needed).</summary>
        public string EnsurePassword(string existing)
        {
            var exe = ExePath;
            if (exe == null) return existing ?? "";
            var pw = string.IsNullOrEmpty(existing) ? GeneratePassword() : existing;
            try { RunNoCapture(exe, "--password " + pw, 8000); }
            catch (Exception ex) { Log.Warn("Set RustDesk password failed: " + ex.Message); }
            return pw;
        }

        /// <summary>Read the RustDesk device ID (digits only).</summary>
        public string GetId()
        {
            var exe = ExePath;
            if (exe == null) return "";
            try
            {
                var output = Run(exe, "--get-id", 8000);
                return new string(output.Trim().Where(char.IsDigit).ToArray());
            }
            catch (Exception ex) { Log.Warn("GetId failed: " + ex.Message); return ""; }
        }

        /// <summary>Run and capture stdout. Use only for short commands that exit cleanly (e.g. --get-id).</summary>
        private static string Run(string exe, string args, int timeoutMs)
        {
            var psi = new ProcessStartInfo(exe, args)
            {
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true,
            };
            using (var p = Process.Start(psi))
            {
                var sb = new StringBuilder();
                p.OutputDataReceived += (s, e) => { if (e.Data != null) sb.AppendLine(e.Data); };
                p.BeginOutputReadLine();
                p.WaitForExit(timeoutMs);
                try { p.CancelOutputRead(); } catch { }
                return sb.ToString();
            }
        }

        /// <summary>
        /// Run without redirecting stdout/stderr. REQUIRED for the RustDesk installer:
        /// --silent-install spawns the RustDesk service which inherits the parent's stdout
        /// handle, so a captured ReadToEnd() would block until the service exits (i.e. forever).
        /// </summary>
        private static void RunNoCapture(string exe, string args, int timeoutMs)
        {
            var psi = new ProcessStartInfo(exe, args)
            {
                UseShellExecute = false,
                CreateNoWindow = true,
            };
            using (var p = Process.Start(psi))
            {
                p.WaitForExit(timeoutMs);
            }
        }

        private static string GeneratePassword()
        {
            const string chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789";
            var bytes = new byte[12];
            using (var rng = new System.Security.Cryptography.RNGCryptoServiceProvider())
                rng.GetBytes(bytes);
            var sb = new StringBuilder();
            foreach (var b in bytes) sb.Append(chars[b % chars.Length]);
            return sb.ToString();
        }
    }
}
