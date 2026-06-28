using System;
using System.ServiceProcess;

namespace EightWest.Agent
{
    /// <summary>
    /// Entry point. Runs as a Windows Service normally, or as a console app
    /// for debugging when started with the "console" argument.
    /// </summary>
    internal static class Program
    {
        internal const string ServiceName = "EightWestAgent";

        private static void Main(string[] args)
        {
            if (args.Length > 0 && args[0].Equals("console", StringComparison.OrdinalIgnoreCase))
            {
                Console.WriteLine("8 West IT RMM Agent — console mode. Ctrl+C to quit.");
                var worker = new Worker();
                worker.Start();
                Console.CancelKeyPress += (s, e) => { worker.Stop(); };
                System.Threading.Thread.Sleep(System.Threading.Timeout.Infinite);
                return;
            }

            ServiceBase.Run(new AgentService());
        }
    }
}
