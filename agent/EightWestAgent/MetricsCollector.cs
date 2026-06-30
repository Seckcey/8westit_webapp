using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Management;
using System.Net.NetworkInformation;
using System.Text;

namespace EightWest.Agent
{
    /// <summary>
    /// Samples live telemetry for the real-time channel. Two parts:
    ///
    ///   1. The flat "core" gauges (cpu / mem / system-drive % / uptime / logged-user / net rates)
    ///      that the live dashboard tile and agent_metrics_latest consume — unchanged wire shape,
    ///      so older portals keep working (PHASE1-SPEC §1.3).
    ///
    ///   2. A generic <c>series</c> array (Phase 2) carrying the WIDER metric set as
    ///      { "k":&lt;metric_key&gt;, "i":&lt;instance&gt;, "v":&lt;number&gt; } tuples: per-volume disk
    ///      usage + free space, network throughput, SMART disk health, and temperatures. The RT
    ///      backend forwards this opaquely and the portal appends each tuple to the tall time-series
    ///      store (agent_metric_samples) — so STORING a new metric needs only an entry here (no DB
    ///      schema and no RT-backend change). Surfacing it in the dashboard additionally needs it
    ///      added to metrics_history.php (read) and app.js (render).
    ///
    /// Core gauges are sub-millisecond. Per-volume disk + network are cheap and sampled every frame;
    /// SMART + temperature use WMI (root\WMI) which can be slow/absent, so they are best-effort and
    /// refreshed at most once every <see cref="HealthIntervalSecs"/> (cached between).
    /// </summary>
    public sealed class MetricsCollector : IDisposable
    {
        private const double HealthIntervalSecs = 300; // SMART/temperature refresh cadence

        private PerformanceCounter _cpu;
        private bool _cpuPrimed;
        private bool _disposed;

        // Network rate state (delta between samples).
        private DateTime _lastNetAt = DateTime.MinValue;
        private long _lastBytesSent = -1;
        private long _lastBytesRecv = -1;
        private double _netUpBps;    // bytes/sec (flat core field, back-compat)
        private double _netDownBps;

        // SMART/temperature cache (refreshed on HealthIntervalSecs).
        private DateTime _lastHealthAt = DateTime.MinValue;
        private List<Dictionary<string, object>> _healthCache = new List<Dictionary<string, object>>();

        public MetricsCollector()
        {
            try
            {
                _cpu = new PerformanceCounter("Processor", "% Processor Time", "_Total", readOnly: true);
                _cpu.NextValue();
                _cpuPrimed = true;
            }
            catch
            {
                _cpu = null;
                _cpuPrimed = false;
            }
        }

        /// <summary>Collect one telemetry snapshot (core fields + the Phase 2 series array).</summary>
        public Dictionary<string, object> Sample()
        {
            // Network first so the flat net_up/net_down fields below reflect this sample.
            var series = new List<Dictionary<string, object>>();
            SampleNetwork(series);
            AddDiskSeries(series);
            AddHealthSeries(series);

            return new Dictionary<string, object>
            {
                ["cpu"] = Round(SampleCpu()),
                ["mem"] = Round(SampleMemPercent()),
                ["disk_c"] = Round(SampleSystemDrivePercent()),
                ["uptime_secs"] = SampleUptimeSecs(),
                ["logged_user"] = SampleLoggedUser(),
                // Flat byte-rate fields kept for back-compat; the charted throughput lives in `series`
                // as net_up_kbps / net_down_kbps (kilobits/sec).
                ["net_up"] = (long)_netUpBps,
                ["net_down"] = (long)_netDownBps,
                ["series"] = series,
            };
        }

        // ── one series tuple: {k,i,v}. Skips non-finite values. ──────────────────────────────
        private static void Add(List<Dictionary<string, object>> s, string key, string instance, double value)
        {
            if (double.IsNaN(value) || double.IsInfinity(value)) return;
            s.Add(new Dictionary<string, object>
            {
                ["k"] = key,
                ["i"] = instance ?? "",
                ["v"] = Math.Round(value, 2),
            });
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

        // Per-volume disk: usage % + free GB for every fixed, ready drive (instance = "C:", "D:", …).
        private static void AddDiskSeries(List<Dictionary<string, object>> series)
        {
            try
            {
                foreach (var di in DriveInfo.GetDrives())
                {
                    try
                    {
                        if (di.DriveType != DriveType.Fixed || !di.IsReady || di.TotalSize <= 0) continue;
                        var letter = (di.Name ?? "").TrimEnd('\\'); // "C:\" -> "C:"
                        if (letter.Length == 0) continue;
                        double usedPct = (di.TotalSize - di.TotalFreeSpace) / (double)di.TotalSize * 100.0;
                        double freeGb = di.TotalFreeSpace / 1073741824.0;
                        Add(series, "disk_pct", letter, Clamp(usedPct, 0, 100));
                        Add(series, "disk_free_gb", letter, freeGb);
                    }
                    catch { /* skip this volume */ }
                }
            }
            catch { }
        }

        // Network throughput across all up, non-loopback/tunnel interfaces. Rates are derived from
        // the byte-counter delta since the previous sample; the first sample establishes a baseline.
        private void SampleNetwork(List<Dictionary<string, object>> series)
        {
            long sent = 0, recv = 0;
            bool any = false;
            // Windows commonly surfaces the SAME physical link through several NetworkInterface
            // objects (Wi-Fi Direct / virtual adapters) that all mirror identical byte counters —
            // naively summing every "Up" interface multiplies real throughput several-fold. Dedupe
            // by the (sent,received) counter signature so a mirrored link is counted once; the many
            // idle virtual NICs collapse to a single 0/0 entry and contribute nothing.
            var seen = new HashSet<string>();
            try
            {
                foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
                {
                    try
                    {
                        if (ni.OperationalStatus != OperationalStatus.Up) continue;
                        if (ni.NetworkInterfaceType == NetworkInterfaceType.Loopback ||
                            ni.NetworkInterfaceType == NetworkInterfaceType.Tunnel) continue;
                        var s = ni.GetIPv4Statistics();
                        if (!seen.Add(s.BytesSent + "|" + s.BytesReceived)) continue; // mirrored/duplicate adapter
                        sent += s.BytesSent;
                        recv += s.BytesReceived;
                        any = true;
                    }
                    catch { /* skip this NIC */ }
                }
            }
            catch { any = false; }

            var now = DateTime.UtcNow;
            if (any && _lastBytesSent >= 0 && _lastNetAt != DateTime.MinValue)
            {
                double elapsed = (now - _lastNetAt).TotalSeconds;
                if (elapsed > 0)
                {
                    double upBps = (sent - _lastBytesSent) / elapsed;
                    double downBps = (recv - _lastBytesRecv) / elapsed;
                    if (upBps < 0) upBps = 0;     // counter reset/rollover guard
                    if (downBps < 0) downBps = 0;
                    _netUpBps = upBps;
                    _netDownBps = downBps;
                    // kilobits/sec for the chart (bytes/sec * 8 / 1000).
                    Add(series, "net_up_kbps", "", upBps * 8.0 / 1000.0);
                    Add(series, "net_down_kbps", "", downBps * 8.0 / 1000.0);
                }
            }
            if (any)
            {
                _lastBytesSent = sent;
                _lastBytesRecv = recv;
                _lastNetAt = now;
            }
        }

        // SMART disk health (1 = healthy, 0 = failure predicted) + temperatures (°C). WMI root\WMI is
        // slow and frequently unavailable (VMs, servers without thermal sensors), so this is throttled
        // and best-effort: the cached result is reused between refreshes and may legitimately be empty.
        private void AddHealthSeries(List<Dictionary<string, object>> series)
        {
            var now = DateTime.UtcNow;
            if (_lastHealthAt == DateTime.MinValue || (now - _lastHealthAt).TotalSeconds >= HealthIntervalSecs)
            {
                var fresh = new List<Dictionary<string, object>>();

                // SMART failure prediction per physical disk.
                try
                {
                    foreach (ManagementObject mo in new ManagementObjectSearcher(
                                 @"root\WMI", "SELECT InstanceName, PredictFailure FROM MSStorageDriver_FailurePredictStatus").Get())
                    {
                        try
                        {
                            bool predictFail = Convert.ToBoolean(mo["PredictFailure"]);
                            var inst = Sanitize((mo["InstanceName"] as string) ?? "disk");
                            Add(fresh, "disk_health", inst, predictFail ? 0.0 : 1.0);
                        }
                        catch { }
                    }
                }
                catch { /* SMART namespace unavailable */ }

                // Thermal-zone temperatures (tenths of Kelvin → °C).
                try
                {
                    int zone = 0;
                    foreach (ManagementObject mo in new ManagementObjectSearcher(
                                 @"root\WMI", "SELECT CurrentTemperature FROM MSAcpi_ThermalZoneTemperature").Get())
                    {
                        try
                        {
                            double tenthsK = Convert.ToDouble(mo["CurrentTemperature"]);
                            double c = tenthsK / 10.0 - 273.15;
                            if (c > -50 && c < 150) Add(fresh, "temp_c", zone.ToString(), c);
                        }
                        catch { }
                        zone++;
                    }
                }
                catch { /* no thermal sensors exposed */ }

                _healthCache = fresh;
                _lastHealthAt = now;
            }

            // Replay the cache into this frame's series (instances/keys already validated above).
            foreach (var d in _healthCache) series.Add(d);
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

        // Keep instance strings to a safe charset/length so the portal stores them verbatim and the
        // dashboard can render them without escaping surprises (defense in depth; the portal also
        // validates on ingest).
        private static string Sanitize(string s)
        {
            if (string.IsNullOrEmpty(s)) return "";
            var sb = new StringBuilder(Math.Min(s.Length, 64));
            foreach (var ch in s)
            {
                if (sb.Length >= 64) break;
                // Map backslash (common in WMI instance names like "SCSI\Disk&Ven_...") to '_' so the
                // value matches the portal's accepted charset [A-Za-z0-9 :._-] EXACTLY. If we emitted a
                // backslash the portal would silently strip it, losing instance identity.
                if (ch == '\\') sb.Append('_');
                else if (char.IsLetterOrDigit(ch) || ch == ' ' || ch == ':' || ch == '.' || ch == '_' || ch == '-')
                    sb.Append(ch);
            }
            return sb.ToString().Trim();
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
