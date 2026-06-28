using System;
using System.IO;
using System.Web.Script.Serialization;

namespace EightWest.Agent
{
    /// <summary>
    /// Persistent per-machine identity, stored in
    /// C:\ProgramData\8WestIT\Agent\state.json (writable by LocalSystem).
    /// </summary>
    public class AgentState
    {
        public string AgentUid { get; set; } = "";
        public string AuthToken { get; set; } = "";
        public string RustDeskPassword { get; set; } = "";

        private static string Dir =>
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                         "8WestIT", "Agent");

        private static string FilePath => Path.Combine(Dir, "state.json");

        public static AgentState Load()
        {
            try
            {
                if (File.Exists(FilePath))
                    return new JavaScriptSerializer().Deserialize<AgentState>(File.ReadAllText(FilePath))
                           ?? new AgentState();
            }
            catch { /* fall through to new */ }
            return new AgentState();
        }

        public void Save()
        {
            Directory.CreateDirectory(Dir);
            File.WriteAllText(FilePath, new JavaScriptSerializer().Serialize(this));
        }

        public bool IsEnrolled =>
            !string.IsNullOrEmpty(AgentUid) && !string.IsNullOrEmpty(AuthToken);
    }
}
