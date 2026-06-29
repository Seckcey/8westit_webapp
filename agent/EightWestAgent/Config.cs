using System;
using System.IO;
using Microsoft.Win32;
using System.Web.Script.Serialization;

namespace EightWest.Agent
{
    /// <summary>
    /// Install-time settings: the portal URL and the enrollment key.
    /// The MSI writes these to HKLM\SOFTWARE\8WestIT\Agent. As a fallback we also
    /// read a config.json placed next to the executable (useful for console testing).
    /// </summary>
    public class Config
    {
        public string PortalUrl { get; set; } = "";
        public string EnrollKey { get; set; } = "";
        // Optional override for the RustDesk installer URL (blank = built-in pinned version).
        public string RustDeskUrl { get; set; } = "";

        // --- Milepost real-time (Phase 1, PHASE1-SPEC §7.3) ---
        // Optional override for the wss:// URL the portal advertises (enroll/heartbeat
        // return "realtime_url"). Blank = use whatever the portal returns.
        public string RealtimeUrl { get; set; } = "";
        // "0" forces pure polling (kill switch). Default on. Stored as string to match
        // the registry/json string convention; parsed via RealtimeEnabledFlag.
        public string RealtimeEnabled { get; set; } = "1";

        /// <summary>True unless RealtimeEnabled is explicitly "0"/"false"/"no"/"off".</summary>
        public bool RealtimeEnabledFlag
        {
            get
            {
                var v = (RealtimeEnabled ?? "1").Trim().ToLowerInvariant();
                return v != "0" && v != "false" && v != "no" && v != "off";
            }
        }

        private const string RegPath = @"SOFTWARE\8WestIT\Agent";

        public static Config Load()
        {
            var cfg = new Config();

            // 1) Registry (written by the MSI), 64-bit view.
            try
            {
                using (var baseKey = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64))
                using (var key = baseKey.OpenSubKey(RegPath))
                {
                    if (key != null)
                    {
                        cfg.PortalUrl = (key.GetValue("PortalUrl") as string) ?? cfg.PortalUrl;
                        cfg.EnrollKey = (key.GetValue("EnrollKey") as string) ?? cfg.EnrollKey;
                        cfg.RustDeskUrl = (key.GetValue("RustDeskUrl") as string) ?? cfg.RustDeskUrl;
                        cfg.RealtimeUrl = (key.GetValue("RealtimeUrl") as string) ?? cfg.RealtimeUrl;
                        // Only override the default if the value is actually present.
                        var re = key.GetValue("RealtimeEnabled") as string;
                        if (!string.IsNullOrEmpty(re)) cfg.RealtimeEnabled = re;
                    }
                }
            }
            catch { /* ignore */ }

            // 2) config.json next to the exe (overrides only empty values).
            try
            {
                var path = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "config.json");
                if (File.Exists(path))
                {
                    var json = File.ReadAllText(path);
                    var fromFile = new JavaScriptSerializer().Deserialize<Config>(json);
                    if (string.IsNullOrEmpty(cfg.PortalUrl)) cfg.PortalUrl = fromFile.PortalUrl ?? "";
                    if (string.IsNullOrEmpty(cfg.EnrollKey)) cfg.EnrollKey = fromFile.EnrollKey ?? "";
                    if (string.IsNullOrEmpty(cfg.RustDeskUrl)) cfg.RustDeskUrl = fromFile.RustDeskUrl ?? "";
                    if (string.IsNullOrEmpty(cfg.RealtimeUrl)) cfg.RealtimeUrl = fromFile.RealtimeUrl ?? "";
                    // RealtimeEnabled: only take the file value when the registry didn't supply one.
                    if (cfg.RealtimeEnabled == "1" && !string.IsNullOrEmpty(fromFile.RealtimeEnabled))
                        cfg.RealtimeEnabled = fromFile.RealtimeEnabled;
                }
            }
            catch { /* ignore */ }

            cfg.PortalUrl = (cfg.PortalUrl ?? "").TrimEnd('/');
            cfg.RealtimeUrl = (cfg.RealtimeUrl ?? "").Trim();
            return cfg;
        }
    }
}
