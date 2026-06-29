using System.ComponentModel;
using System.Configuration.Install;
using System.ServiceProcess;

namespace EightWest.Agent
{
    /// <summary>
    /// Lets the service be installed via InstallUtil as a fallback to the MSI.
    /// The MSI (WiX ServiceInstall) is the primary path; this exists for manual installs/debugging:
    ///   InstallUtil.exe EightWestAgent.exe
    /// </summary>
    [RunInstaller(true)]
    public class AgentProjectInstaller : Installer
    {
        public AgentProjectInstaller()
        {
            var process = new ServiceProcessInstaller { Account = ServiceAccount.LocalSystem };
            var service = new ServiceInstaller
            {
                ServiceName = Program.ServiceName,
                DisplayName = "Milepost Agent",
                Description = "Milepost RMM agent by 8 West IT — reports status and enables remote support.",
                StartType = ServiceStartMode.Automatic,
            };
            Installers.Add(process);
            Installers.Add(service);
        }
    }
}
