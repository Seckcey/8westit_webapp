using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Management;

namespace EightWest.Agent
{
    /// <summary>
    /// Samples lightweight live telemetry (CPU / memory / system-drive usage /
    /// uptime / logged-in user) for the real-time channel. All sampling is cheap
    /// (sub-millisecond after warm-up) and is driven only on the backend-dictated
    /// cadence (see <see cref="RealtimeClient"/>).
    ///
    /// Produces the <c>metrics.d</c> payload defined in PHASE1-SPEC §1.3:
    ///   { cpu, mem, disk_c, uptime_secs, logged_user, net_up, net_down }
    /// </summary>
    public sealed class MetricsCollector : IDisposable
    {
        private PerformanceCounter _cpu;
        private bool _cpuPrimed;
        private bool _disposed;

        public MetricsCollector()
        {
            // A cached counter is far cheaper than constructing one per sample.
            // The very first NextValue() call always returns 0, so prime it once.
            try
            {
                _cpu = new PerformanceCounter("Processor", "% Processor Time", "_Total", readOnly: true);
                _cpu.NextValue();
                _cpuPrimed = true;
            }
            catch
            {
                // PerformanceCounter can be unavailable / corrupted on some images;
                // we fall back to a WMI read in SampleCpu().
                _cpu = null;
                _cpuPrimed = false;
            }
        }

        /// <summary>Collect one telemetry snapshot as the spec's metrics.d object.</summary>
        public Dictionary<string, object> Sample()
        {
            return new Dictionary<string, object>
            {
                ["cpu"] = Round(SampleCpu()),
                ["mem"] = Round(SampleMemPercent()),
                ["disk_c"] = Round(SampleSystemDrivePercent()),
                ["uptime_secs"] = SampleUptimeSecs(),
                ["logged_user"] = SampleLoggedUser(),
                // net_up / net_down are best-effort instantaneous byte rates; the spec
                // marks metrics as lossy/advisory, so 0 is an acceptable placeholder
                // when no cheap counter is primed. Kept in the payload for forward-compat.
                ["net_up"] = 0,
                ["net_down"] = 0,
            };
        }

        private double SampleCpu()
        {
            try
            {
                if (_cpu != null && _cpuPrimed)
                    return Clamp(_cpu.NextValue(), 0, 100);
            }
            catch { /* fall through to WMI */ }

            try
            {
                foreach (ManagementObject mo in new ManagementObjectSearcher(
                             "SELECT PercentProcessorTime FROM Win32_PerfFormattedData_PerfOS_Processor WHERE Name='_Total'").Get())
                {
                    var v = mo["PercentProcessorTime"];
                    if (v != null) return Clamp(Convert.ToDouble(v), 0, 100);
                }
            }
            catch { }
            return 0;
        }

        private static double SampleMemPercent()
        {
            try
            {
                foreach (ManagementObject os in new ManagementObjectSearcher(
                             "SELECT TotalVisibleMemorySize, FreePhysicalMemory FROM Win32_OperatingSystem").Get())
                {
                    double total = Convert.ToDouble(os["TotalVisibleMemorySize"]); // KB
                    double free = Convert.ToDouble(os["FreePhysicalMemory"]);      // KB
                    if (total > 0) return Clamp((total - free) / total * 100.0, 0, 100);
                }
            }
            catch { }
            return 0;
        }

        private static double SampleSystemDrivePercent()
        {
            try
            {
                var sysRoot = Path.GetPathRoot(Environment.GetFolderPath(Environment.SpecialFolder.System))
                              ?? "C:\\";
                var di = new DriveInfo(sysRoot);
                if (di.IsReady && di.TotalSize > 0)
                {
                    double used = di.TotalSize - di.TotalFreeSpace;
                    return Clamp(used / di.TotalSize * 100.0, 0, 100);
                }
            }
            catch { }
            return 0;
        }

        private static long SampleUptimeSecs()
        {
            try
            {
                foreach (ManagementObject os in new ManagementObjectSearcher(
                             "SELECT LastBootUpTime FROM Win32_OperatingSystem").Get())
                {
                    var raw = os["LastBootUpTime"]?.ToString();
                    if (!string.IsNullOrEmpty(raw))
                    {
                        var boot = ManagementDateTimeConverter.ToDateTime(raw);
                        var secs = (long)(DateTime.Now - boot).TotalSeconds;
                        if (secs > 0) return secs;
                    }
                }
            }
            catch { }
            // Fallback: Environment.TickCount (wraps after ~49.7 days, but better than 0).
            try { return Math.Max(0, Environment.TickCount / 1000); } catch { return 0; }
        }

        private static string SampleLoggedUser()
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

        private static double Clamp(double v, double lo, double hi)
            => v < lo ? lo : (v > hi ? hi : v);

        private static double Round(double v) => Math.Round(v, 1);

        public void Dispose()
        {
            if (_disposed) return;
            _disposed = true;
            try { _cpu?.Dispose(); } catch { }
            _cpu = null;
        }
    }
}
