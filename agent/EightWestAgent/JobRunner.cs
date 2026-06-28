using System;
using System.Diagnostics;
using System.Text;

namespace EightWest.Agent
{
    public class JobResult
    {
        public bool Success;
        public int ExitCode;
        public string Output = "";
    }

    /// <summary>Executes a queued job on the local machine (runs as the service account = SYSTEM).</summary>
    public static class JobRunner
    {
        public static JobResult Execute(string type, string payload)
        {
            switch ((type ?? "").ToLowerInvariant())
            {
                case "powershell": return RunShell("powershell.exe",
                    "-NoProfile -NonInteractive -ExecutionPolicy Bypass -Command -", payload);
                case "cmd":        return RunShell("cmd.exe", "/Q /C \"" + EscapeForCmd(payload) + "\"", null);
                case "restart":    return Restart();
                case "message":    return ShowMessage(payload);
                default:           return new JobResult { Success = false, ExitCode = -1, Output = "Unknown job type: " + type };
            }
        }

        private static JobResult RunShell(string exe, string args, string stdin)
        {
            var psi = new ProcessStartInfo(exe, args)
            {
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                RedirectStandardInput = stdin != null,
                CreateNoWindow = true,
            };
            var sb = new StringBuilder();
            try
            {
                using (var p = new Process { StartInfo = psi })
                {
                    p.OutputDataReceived += (s, e) => { if (e.Data != null) sb.AppendLine(e.Data); };
                    p.ErrorDataReceived  += (s, e) => { if (e.Data != null) sb.AppendLine(e.Data); };
                    p.Start();
                    p.BeginOutputReadLine();
                    p.BeginErrorReadLine();
                    if (stdin != null)
                    {
                        p.StandardInput.Write(payloadOrEmpty(stdin));
                        p.StandardInput.Close();
                    }
                    if (!p.WaitForExit(5 * 60 * 1000)) // 5-minute cap
                    {
                        try { p.Kill(); } catch { }
                        return new JobResult { Success = false, ExitCode = -2,
                            Output = sb + "\n[timed out after 5 minutes]" };
                    }
                    p.WaitForExit(); // flush async buffers
                    return new JobResult { Success = p.ExitCode == 0, ExitCode = p.ExitCode, Output = sb.ToString() };
                }
            }
            catch (Exception ex)
            {
                return new JobResult { Success = false, ExitCode = -3, Output = sb + "\n" + ex.Message };
            }
        }

        private static string payloadOrEmpty(string s) => s ?? "";

        private static JobResult Restart()
        {
            try
            {
                Process.Start(new ProcessStartInfo("shutdown.exe", "/r /t 30 /c \"8 West IT scheduled restart\"")
                { UseShellExecute = false, CreateNoWindow = true });
                return new JobResult { Success = true, ExitCode = 0, Output = "Restart scheduled in 30 seconds." };
            }
            catch (Exception ex)
            {
                return new JobResult { Success = false, ExitCode = -1, Output = ex.Message };
            }
        }

        private static JobResult ShowMessage(string msg)
        {
            // msg.exe shows a message box to the active console user.
            try
            {
                Process.Start(new ProcessStartInfo("msg.exe", "* " + EscapeForCmd(msg))
                { UseShellExecute = false, CreateNoWindow = true });
                return new JobResult { Success = true, ExitCode = 0, Output = "Message sent." };
            }
            catch (Exception ex)
            {
                return new JobResult { Success = false, ExitCode = -1, Output = ex.Message };
            }
        }

        private static string EscapeForCmd(string s) => (s ?? "").Replace("\"", "\\\"");
    }
}
