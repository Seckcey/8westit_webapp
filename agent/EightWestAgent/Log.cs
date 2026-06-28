using System;
using System.IO;

namespace EightWest.Agent
{
    /// <summary>Lightweight rolling file logger at C:\ProgramData\8WestIT\Agent\agent.log.</summary>
    public static class Log
    {
        private static readonly object Gate = new object();

        private static string Dir =>
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                         "8WestIT", "Agent");

        private static string FilePath => Path.Combine(Dir, "agent.log");

        public static void Info(string msg)  => Write("INFO", msg);
        public static void Warn(string msg)  => Write("WARN", msg);
        public static void Error(string msg) => Write("ERROR", msg);

        private static void Write(string level, string msg)
        {
            try
            {
                lock (Gate)
                {
                    Directory.CreateDirectory(Dir);
                    // Roll at ~1 MB.
                    if (File.Exists(FilePath) && new FileInfo(FilePath).Length > 1_000_000)
                        File.Copy(FilePath, FilePath + ".1", true);
                    File.AppendAllText(FilePath,
                        $"{DateTime.UtcNow:yyyy-MM-dd HH:mm:ss}Z [{level}] {msg}{Environment.NewLine}");
                }
                if (Environment.UserInteractive) Console.WriteLine($"[{level}] {msg}");
            }
            catch { /* logging must never throw */ }
        }
    }
}
