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
                }
            }
            catch { /* ignore */ }

            cfg.PortalUrl = (cfg.PortalUrl ?? "").TrimEnd('/');
            return cfg;
        }
    }
}
