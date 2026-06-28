using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Text;
using System.Web.Script.Serialization;

namespace EightWest.Agent
{
    /// <summary>HTTP client for the 8 West portal API. JSON over HTTPS with a Bearer token.</summary>
    public class ApiClient
    {
        private readonly string _baseUrl;
        private string _token;
        private readonly JavaScriptSerializer _json = new JavaScriptSerializer { MaxJsonLength = 16_000_000 };

        public ApiClient(string baseUrl, string token = null)
        {
            _baseUrl = baseUrl.TrimEnd('/');
            _token = token;
            ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12 | SecurityProtocolType.Tls11;
        }

        public void SetToken(string token) => _token = token;

        public Dictionary<string, object> Post(string path, object body, bool auth = true)
            => Send("POST", path, body, auth);

        public Dictionary<string, object> Get(string path, bool auth = true)
            => Send("GET", path, null, auth);

        private Dictionary<string, object> Send(string method, string path, object body, bool auth)
        {
            var url = _baseUrl + path;
            var req = (HttpWebRequest)WebRequest.Create(url);
            req.Method = method;
            req.Timeout = 30000;
            req.UserAgent = "EightWestAgent/" + Worker.Version;
            req.Accept = "application/json";
            if (auth && !string.IsNullOrEmpty(_token))
                req.Headers["Authorization"] = "Bearer " + _token;

            if (body != null)
            {
                req.ContentType = "application/json";
                var data = Encoding.UTF8.GetBytes(_json.Serialize(body));
                req.ContentLength = data.Length;
                using (var s = req.GetRequestStream()) s.Write(data, 0, data.Length);
            }

            try
            {
                using (var resp = (HttpWebResponse)req.GetResponse())
                using (var sr = new StreamReader(resp.GetResponseStream()))
                    return Parse(sr.ReadToEnd());
            }
            catch (WebException ex)
            {
                var resp = ex.Response as HttpWebResponse;
                if (resp != null)
                {
                    using (var sr = new StreamReader(resp.GetResponseStream()))
                    {
                        var msg = sr.ReadToEnd();
                        throw new ApiException((int)resp.StatusCode, msg);
                    }
                }
                throw new ApiException(0, ex.Message);
            }
        }

        private Dictionary<string, object> Parse(string s)
        {
            if (string.IsNullOrWhiteSpace(s)) return new Dictionary<string, object>();
            return _json.Deserialize<Dictionary<string, object>>(s) ?? new Dictionary<string, object>();
        }
    }

    public class ApiException : Exception
    {
        public int StatusCode { get; }
        public ApiException(int code, string message) : base($"HTTP {code}: {message}") { StatusCode = code; }
    }
}
