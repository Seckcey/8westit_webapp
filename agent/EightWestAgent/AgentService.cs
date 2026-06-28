using System.ServiceProcess;

namespace EightWest.Agent
{
    /// <summary>Thin ServiceBase wrapper around <see cref="Worker"/>.</summary>
    public class AgentService : ServiceBase
    {
        private readonly Worker _worker = new Worker();

        public AgentService()
        {
            ServiceName = Program.ServiceName;
            CanStop = true;
            CanShutdown = true;
            AutoLog = true;
        }

        protected override void OnStart(string[] args) => _worker.Start();
        protected override void OnStop() => _worker.Stop();
        protected override void OnShutdown() => _worker.Stop();
    }
}
