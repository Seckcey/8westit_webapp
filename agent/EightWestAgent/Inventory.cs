using System;
using System.Collections.Generic;
using System.Linq;
using System.Management;
using Microsoft.Win32;

namespace EightWest.Agent
{
    /// <summary>Collects hardware/software inventory via WMI + registry.</summary>
    public static class Inventory
    {
        public static Dictionary<string, object> Collect()
        {
            var inv = new Dictionary<string, object>
            {
                ["system"]   = System(),
                ["cpu"]      = Cpu(),
                ["disks"]    = Disks(),
                ["network"]  = Network(),
                ["software"] = Software(),
            };
            return inv;
        }

        private static IEnumerable<ManagementObject> Query(string wql)
        {
            try { return new ManagementObjectSearcher(wql).Get().Cast<ManagementObject>().ToList(); }
            catch (Exception ex) { Log.Warn("WMI query failed: " + wql + " — " + ex.Message); return Enumerable.Empty<ManagementObject>(); }
        }

        private static string S(ManagementObject o, string p)
        {
            try { return o[p]?.ToString()?.Trim() ?? ""; } catch { return ""; }
        }

        private static Dictionary<string, object> System()
        {
            var d = new Dictionary<string, object>();
            foreach (var cs in Query("SELECT * FROM Win32_ComputerSystem"))
            {
                d["manufacturer"] = S(cs, "Manufacturer");
                d["model"] = S(cs, "Model");
                ulong ram = 0; ulong.TryParse(S(cs, "TotalPhysicalMemory"), out ram);
                d["ram_gb"] = Math.Round(ram / 1024d / 1024 / 1024, 1);
            }
            foreach (var os in Query("SELECT * FROM Win32_OperatingSystem"))
            {
                d["os"] = S(os, "Caption");
                d["os_build"] = S(os, "BuildNumber");
                try
                {
                    var boot = ManagementDateTimeConverter.ToDateTime(S(os, "LastBootUpTime"));
                    d["uptime"] = FormatSpan(DateTime.Now - boot);
                }
                catch { }
            }
            foreach (var bios in Query("SELECT SerialNumber FROM Win32_BIOS"))
                d["serial"] = S(bios, "SerialNumber");
            return d;
        }

        private static Dictionary<string, object> Cpu()
        {
            var d = new Dictionary<string, object>();
            foreach (var c in Query("SELECT Name, NumberOfCores FROM Win32_Processor"))
            {
                d["name"] = S(c, "Name");
                d["cores"] = S(c, "NumberOfCores");
                break;
            }
            return d;
        }

        private static List<object> Disks()
        {
            var list = new List<object>();
            foreach (var ld in Query("SELECT DeviceID, Size, FreeSpace FROM Win32_LogicalDisk WHERE DriveType=3"))
            {
                double size = 0, free = 0;
                double.TryParse(S(ld, "Size"), out size);
                double.TryParse(S(ld, "FreeSpace"), out free);
                list.Add(new Dictionary<string, object>
                {
                    ["drive"] = S(ld, "DeviceID"),
                    ["size_gb"] = Math.Round(size / 1e9, 1),
                    ["free_gb"] = Math.Round(free / 1e9, 1),
                });
            }
            return list;
        }

        private static List<object> Network()
        {
            var list = new List<object>();
            foreach (var n in Query("SELECT * FROM Win32_NetworkAdapterConfiguration WHERE IPEnabled=True"))
            {
                string ipv4 = "";
                try
                {
                    var ips = n["IPAddress"] as string[];
                    if (ips != null) ipv4 = ips.FirstOrDefault(x => !x.Contains(":")) ?? "";
                }
                catch { }
                list.Add(new Dictionary<string, object>
                {
                    ["name"] = S(n, "Description"),
                    ["ipv4"] = ipv4,
                    ["mac"] = S(n, "MACAddress"),
                });
            }
            return list;
        }

        /// <summary>Installed apps from the uninstall registry keys (both 64- and 32-bit).</summary>
        private static List<object> Software()
        {
            var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            var list = new List<object>();
            var roots = new[]
            {
                (RegistryHive.LocalMachine, RegistryView.Registry64),
                (RegistryHive.LocalMachine, RegistryView.Registry32),
            };
            foreach (var (hive, view) in roots)
            {
                try
                {
                    using (var baseKey = RegistryKey.OpenBaseKey(hive, view))
                    using (var uninstall = baseKey.OpenSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"))
                    {
                        if (uninstall == null) continue;
                        foreach (var sub in uninstall.GetSubKeyNames())
                        {
                            using (var k = uninstall.OpenSubKey(sub))
                            {
                                var name = k?.GetValue("DisplayName") as string;
                                if (string.IsNullOrWhiteSpace(name) || !seen.Add(name)) continue;
                                if ((k.GetValue("SystemComponent") as int?) == 1) continue;
                                list.Add(new Dictionary<string, object>
                                {
                                    ["name"] = name,
                                    ["version"] = (k.GetValue("DisplayVersion") as string) ?? "",
                                    ["publisher"] = (k.GetValue("Publisher") as string) ?? "",
                                });
                            }
                        }
                    }
                }
                catch (Exception ex) { Log.Warn("Software scan failed: " + ex.Message); }
            }
            return list.OrderBy(x => ((Dictionary<string, object>)x)["name"]).ToList();
        }

        private static string FormatSpan(TimeSpan t) =>
            $"{(int)t.TotalDays}d {t.Hours}h {t.Minutes}m";
    }
}
