using System;
using System.Collections.Generic;
using System.Net.WebSockets;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using System.Web.Script.Serialization;

namespace EightWest.Agent
{
    /// <summary>
    /// Milepost Phase 1 real-time client (PHASE1-SPEC §1 / §5).
    ///
    /// Owns one persistent <see cref="ClientWebSocket"/> to the VPS backend, authenticated
    /// with the agent's EXISTING bearer token (no re-enrollment, constraint 4). It:
    ///   - sends <c>hello</c> and awaits <c>welcome</c> (10 s deadline);
    ///   - replies to backend <c>ping</c> with <c>pong</c>;
    ///   - streams <c>metrics</c> on the backend-dictated cadence (coalesced, lossy);
    ///   - receives <c>command</c>, dedupes by job_id, runs it via the EXISTING
    ///     <see cref="JobRunner"/>, and reports <c>cmd_result</c>;
    ///   - holds each result in an on-disk outbox until <c>cmd_result_ack</c> OR the
    ///     REST fallback (POST /api/jobs.php) writes it after a grace period.
    ///
    /// Everything degrades to the existing HTTP polling loop: this class NEVER stops
    /// polling and NEVER executes a job that the poller could also claim twice
    /// (idempotency by job_id end to end). Uses only the BCL — no new NuGet.
    ///
    /// Threading model: a single owner thread runs the connect/receive loop. A serializing
    /// <see cref="SemaphoreSlim"/> guards all socket sends (ClientWebSocket permits one
    /// in-flight send + one receive). Command execution runs on the thread-pool with a
    /// concurrency gate of 1 (SYSTEM shells must not overlap).
    /// </summary>
    public sealed class RealtimeClient
    {
        // --- internal tunables (constants by design; not registry keys, PHASE1-SPEC §7.3) ---
        private const string SubProtocol = "milepost.v1";
        private const int HelloTimeoutSecs = 10;
        private const int SilenceDeadSecs = 45;          // no frame for this long => dead socket
        private const int BackoffBaseSecs = 5;
        private const int BackoffCapSecs = 60;
        private const int BackoffWideCapSecs = 5 * 60;   // widen after many failures
        private const int BackoffWidenAfter = 10;
        private const int ConnSurvivedResetSecs = 60;    // reset attempt counter after this
        private const int RtResultFallbackSecs = 30;     // ack grace before REST fallback
        private const int MaxFrameBytes = 256 * 1024;    // 256 KB cap (matches backend maxPayload)
        private const int WireOutputCap = 256 * 1024;    // on-wire output cap per cmd_result

        private readonly string _url;
        private readonly Func<string> _tokenProvider;
        private readonly Func<string, string, JobResult> _executor;
        private readonly MetricsCollector _metrics;
        private readonly AgentState _state;
        private readonly Func<string> _rustDeskIdProvider;
        private readonly ApiClient _restFallback;        // existing POST /api/jobs.php path

        private readonly JavaScriptSerializer _json = new JavaScriptSerializer { MaxJsonLength = 16_000_000 };
        private readonly Random _rng = new Random();

        private Thread _thread;
        private CancellationTokenSource _cts;
        private volatile bool _started;

        // Set true when the backend closes 4401 (token bad). Cleared by NotifyTokenRotated().
        private volatile bool _tokenRejected;

        // Per-connection state (reset on each connect).
        private ClientWebSocket _ws;
        private SemaphoreSlim _sendGate;
        private volatile int _metricsIntervalSecs = 60;
        private long _lastInboundTicks;                  // Environment.TickCount of last frame
        private long _lastMetricsTicks;
        private int _welcomed;                           // 0/1 via Interlocked

        public RealtimeClient(
            string url,
            Func<string> tokenProvider,
            Func<string, string, JobResult> executor,
            MetricsCollector metrics,
            AgentState state,
            ApiClient restFallback,
            Func<string> rustDeskIdProvider = null)
        {
            _url = (url ?? "").Trim();
            _tokenProvider = tokenProvider ?? throw new ArgumentNullException(nameof(tokenProvider));
            _executor = executor ?? throw new ArgumentNullException(nameof(executor));
            _metrics = metrics ?? throw new ArgumentNullException(nameof(metrics));
            _state = state ?? throw new ArgumentNullException(nameof(state));
            _restFallback = restFallback;
            _rustDeskIdProvider = rustDeskIdProvider;
        }

        /// <summary>True while the socket is open AND past the welcome handshake.</summary>
        public bool IsConnected =>
            _ws != null && _ws.State == WebSocketState.Open && Volatile.Read(ref _welcomed) == 1;

        public void Start()
        {
            if (_started) return;
            _started = true;
            _cts = new CancellationTokenSource();
            _thread = new Thread(() => RunLoop(_cts.Token))
            {
                IsBackground = true,
                Name = "MilepostRealtime"
            };
            _thread.Start();
            Log.Info("Realtime client started. url=" + _url);
        }

        public void Stop()
        {
            if (!_started) return;
            _started = false;
            try { _cts?.Cancel(); } catch { }
            // Best-effort graceful bye + close.
            try
            {
                var ws = _ws;
                if (ws != null && ws.State == WebSocketState.Open)
                {
                    TrySendAsync(BuildEnvelope("bye", null, null,
                        new Dictionary<string, object> { ["reason"] = "service_stop" }),
                        CancellationToken.None).Wait(2000);
                    ws.CloseAsync(WebSocketCloseStatus.NormalClosure, "bye", CancellationToken.None).Wait(2000);
                }
            }
            catch { }
            try { _thread?.Join(TimeSpan.FromSeconds(5)); } catch { }
            Log.Info("Realtime client stopped.");
        }

        /// <summary>
        /// Called by Worker after the existing 401 handler re-enrolls and rotates the
        /// token. Clears the 4401 "token bad" latch so the loop reconnects with the
        /// fresh token (PHASE1-SPEC §5.3).
        /// </summary>
        public void NotifyTokenRotated()
        {
            if (_tokenRejected)
            {
                _tokenRejected = false;
                Log.Info("Realtime: token rotated, will reconnect.");
            }
        }

        // ────────────────────────────────────────────────────────────────────
        //  Connect / reconnect loop with full-jitter exponential backoff.
        // ────────────────────────────────────────────────────────────────────
        private void RunLoop(CancellationToken ct)
        {
            int attempt = 0;
            while (!ct.IsCancellationRequested)
            {
                if (_tokenRejected)
                {
                    // Token is bad; do not hammer the backend. Poll carries the load.
                    // Wait for NotifyTokenRotated() (or cancellation).
                    SleepCancelable(TimeSpan.FromSeconds(10), ct);
                    continue;
                }

                var connectedAt = DateTime.UtcNow;
                bool connectedOk = false;
                try
                {
                    connectedOk = ConnectAndServe(ct).GetAwaiter().GetResult();
                }
                catch (OperationCanceledException) { break; }
                catch (Exception ex)
                {
                    Log.Warn("Realtime connection error: " + ex.Message);
                }

                if (ct.IsCancellationRequested) break;
                if (_tokenRejected) continue; // skip backoff sleep; the latch handler waits

                // Reset attempt counter if the connection survived long enough.
                if (connectedOk && (DateTime.UtcNow - connectedAt).TotalSeconds >= ConnSurvivedResetSecs)
                    attempt = 0;
                else
                    attempt++;

                var delay = ComputeBackoff(attempt);
                Log.Info($"Realtime: reconnecting in {delay.TotalSeconds:0}s (attempt {attempt}).");
                SleepCancelable(delay, ct);
            }
        }

        private TimeSpan ComputeBackoff(int attempt)
        {
            int cap = attempt >= BackoffWidenAfter ? BackoffWideCapSecs : BackoffCapSecs;
            // base * 2^attempt, clamped to cap; full jitter rand(0, that).
            double exp = BackoffBaseSecs * Math.Pow(2, Math.Min(attempt, 16));
            double ceiling = Math.Min(cap, exp);
            double secs;
            lock (_rng) { secs = _rng.NextDouble() * ceiling; }
            return TimeSpan.FromSeconds(Math.Max(1, secs));
        }

        private void SleepCancelable(TimeSpan d, CancellationToken ct)
        {
            try { ct.WaitHandle.WaitOne(d); } catch { }
        }

        // ────────────────────────────────────────────────────────────────────
        //  One connection: connect, hello/welcome, then serve until death.
        //  Returns true if the welcome handshake completed (used for backoff reset).
        // ────────────────────────────────────────────────────────────────────
        private async Task<bool> ConnectAndServe(CancellationToken outerCt)
        {
            var token = _tokenProvider();
            if (string.IsNullOrEmpty(token))
            {
                Log.Warn("Realtime: no auth token yet; deferring.");
                return false;
            }

            using (var connCts = CancellationTokenSource.CreateLinkedTokenSource(outerCt))
            {
                _ws = new ClientWebSocket();
                _sendGate = new SemaphoreSlim(1, 1);
                Volatile.Write(ref _welcomed, 0);
                _metricsIntervalSecs = 60;

                // Hard safety net: the bearer token rides in the hello frame, so refuse to
                // connect over anything but wss://. ResolveRtUrl() already validates scheme +
                // host before we get here; this guards against any future call path.
                if (!IsSecureWsUrl(_url))
                {
                    Log.Error("Realtime: refusing non-wss URL (token must never cross plaintext): " + _url);
                    _tokenRejected = true; // park until reconfigured; polling carries the load
                    return false;
                }

                try
                {
                    _ws.Options.AddSubProtocol(SubProtocol);
                    _ws.Options.KeepAliveInterval = TimeSpan.Zero; // app-level ping instead
                    await _ws.ConnectAsync(new Uri(_url), connCts.Token).ConfigureAwait(false);

                    if (!string.Equals(_ws.SubProtocol, SubProtocol, StringComparison.Ordinal))
                    {
                        Log.Warn("Realtime: server did not negotiate milepost.v1; closing.");
                        await SafeClose(WebSocketCloseStatus.ProtocolError, "subprotocol").ConfigureAwait(false);
                        return false;
                    }

                    MarkInbound();
                    await SendHello(token, connCts.Token).ConfigureAwait(false);

                    bool welcomed = await AwaitWelcome(connCts.Token).ConfigureAwait(false);
                    if (!welcomed)
                    {
                        if (_tokenRejected) return false; // 4401 handled inside
                        Log.Warn("Realtime: no welcome within timeout.");
                        await SafeClose(WebSocketCloseStatus.NormalClosure, "no welcome").ConfigureAwait(false);
                        return false;
                    }

                    Log.Info("Realtime: connected and welcomed.");

                    // On (re)connect, immediately try to flush any pending results.
                    await FlushOutboxViaWs(connCts.Token).ConfigureAwait(false);

                    await ServeLoop(connCts.Token).ConfigureAwait(false);
                    return true;
                }
                catch (OperationCanceledException) { throw; }
                catch (WebSocketException wex)
                {
                    Log.Warn("Realtime websocket: " + wex.Message);
                    return Volatile.Read(ref _welcomed) == 1;
                }
                finally
                {
                    try { _ws?.Dispose(); } catch { }
                    _ws = null;
                    _sendGate?.Dispose();
                    _sendGate = null;
                }
            }
        }

        // Receive and "cadence" (metrics + silence watchdog + outbox sweep) run as two
        // INDEPENDENT loops. CRITICAL: never cancel the WebSocket ReceiveAsync to wake up for
        // cadence — cancelling a ClientWebSocket receive ABORTS the socket (it transitions to
        // Aborted). The old single-loop design bounded each receive with a 5 s CancelAfter,
        // which aborted the connection ~5 s after every connect (close code 1006) and produced
        // an endless connect/abort/reconnect loop. So the receive now blocks on the connection
        // token only, and a separate ~1 s tick drives all sending.
        private async Task ServeLoop(CancellationToken ct)
        {
            using (var loopCts = CancellationTokenSource.CreateLinkedTokenSource(ct))
            {
                var receive = ReceiveLoop(loopCts.Token);
                var cadence = CadenceLoop(loopCts.Token);
                await Task.WhenAny(receive, cadence).ConfigureAwait(false);
                loopCts.Cancel(); // whichever ended first, stop the other
                try { await Task.WhenAll(receive, cadence).ConfigureAwait(false); }
                catch { /* cancellation / already-handled close errors */ }
            }
        }

        // Blocks on ReceiveAsync (NO abort-on-timeout) until a frame arrives, the server closes,
        // or the connection is cancelled; dispatches each frame.
        private async Task ReceiveLoop(CancellationToken ct)
        {
            var buf = new byte[16 * 1024];
            var msg = new System.IO.MemoryStream();

            while (!ct.IsCancellationRequested && _ws.State == WebSocketState.Open)
            {
                WebSocketReceiveResult res;
                msg.SetLength(0);
                try
                {
                    do
                    {
                        res = await _ws.ReceiveAsync(new ArraySegment<byte>(buf), ct).ConfigureAwait(false);
                        if (res.MessageType == WebSocketMessageType.Close)
                        {
                            Log.Info($"Realtime: server close {(int?)res.CloseStatus} {res.CloseStatusDescription}");
                            await HandleServerClose(res).ConfigureAwait(false);
                            return;
                        }
                        if (res.MessageType == WebSocketMessageType.Binary)
                        {
                            await SafeClose((WebSocketCloseStatus)1003, "binary").ConfigureAwait(false);
                            return;
                        }
                        if (msg.Length + res.Count > MaxFrameBytes)
                        {
                            await SafeClose((WebSocketCloseStatus)1009, "too large").ConfigureAwait(false);
                            return;
                        }
                        msg.Write(buf, 0, res.Count);
                    }
                    while (!res.EndOfMessage);
                }
                catch (OperationCanceledException) { return; }
                catch (WebSocketException wex)
                {
                    Log.Warn("Realtime receive error: " + wex.Message);
                    return;
                }

                MarkInbound();
                var text = Encoding.UTF8.GetString(msg.GetBuffer(), 0, (int)msg.Length);
                await HandleFrame(text, ct).ConfigureAwait(false);
            }
        }

        // Metrics cadence, 45 s silence watchdog, and REST-fallback sweep on a ~1 s tick —
        // entirely separate from the receive, so it never touches the socket's read.
        private async Task CadenceLoop(CancellationToken ct)
        {
            while (!ct.IsCancellationRequested && _ws.State == WebSocketState.Open)
            {
                // Watchdog: 45 s of silence => dead socket.
                if (TickElapsedSecs(_lastInboundTicks) >= SilenceDeadSecs)
                {
                    Log.Warn("Realtime: 45s silence, treating socket as dead.");
                    await SafeClose(WebSocketCloseStatus.NormalClosure, "silence").ConfigureAwait(false);
                    return;
                }

                // Metrics cadence (coalesced: we only ever send the newest sample).
                if (TickElapsedSecs(_lastMetricsTicks) >= _metricsIntervalSecs)
                {
                    _lastMetricsTicks = Environment.TickCount;
                    await SendMetrics(ct).ConfigureAwait(false);
                }

                // REST fallback sweep for results that have waited past the grace.
                SweepOutboxFallback();

                try { await Task.Delay(1000, ct).ConfigureAwait(false); }
                catch (OperationCanceledException) { return; }
            }
        }

        // ────────────────────────────────────────────────────────────────────
        //  Inbound frame dispatch.
        // ────────────────────────────────────────────────────────────────────
        private async Task HandleFrame(string text, CancellationToken ct)
        {
            Dictionary<string, object> env;
            try { env = _json.Deserialize<Dictionary<string, object>>(text); }
            catch { return; } // malformed JSON: ignore (forward-compat)
            if (env == null) return;

            var t = Str(env, "t");
            var id = StrOrNull(env, "id");
            var d = Dict(env, "d");

            switch (t)
            {
                case "ping":
                    await TrySendAsync(BuildEnvelope("pong", null, id, new Dictionary<string, object>()), ct)
                        .ConfigureAwait(false);
                    break;

                case "command":
                    await OnCommand(id, d, ct).ConfigureAwait(false);
                    break;

                case "set_metrics_interval":
                    var s = (int)Num(d, "seconds", _metricsIntervalSecs);
                    if (s >= 5 && s <= 3600) _metricsIntervalSecs = s;
                    break;

                case "cmd_result_ack":
                    OnResultAck(d);
                    break;

                case "error":
                    Log.Warn($"Realtime: backend error code={Str(d, "code")} msg={Str(d, "msg")}");
                    break;

                // welcome is consumed by AwaitWelcome; any other / unknown type: ignore.
                default:
                    break;
            }
        }

        private void OnResultAck(Dictionary<string, object> d)
        {
            if (!ToBool(d, "persisted")) return;
            int jobId = (int)Num(d, "job_id", 0);
            if (jobId == 0) return;
            _state.RemoveResult(jobId);
            Log.Info($"Realtime: result for job {jobId} acked & persisted.");
        }

        // Execute a pushed command exactly once, then report and outbox the result.
        private async Task OnCommand(string commandId, Dictionary<string, object> d, CancellationToken ct)
        {
            int jobId = (int)Num(d, "job_id", 0);
            string jobType = Str(d, "job_type");
            string payload = Str(d, "payload");
            long notAfter = (long)Num(d, "not_after", 0);

            if (jobId == 0)
            {
                await SendCmdAck(commandId, jobId, false, "missing job_id", ct).ConfigureAwait(false);
                return;
            }

            // Defense in depth: only ever execute the known job types as SYSTEM, even if
            // a pushed frame carries something else (the backend enforces the same set).
            if (!IsAllowedJobType(jobType))
            {
                await SendCmdAck(commandId, jobId, false, "unsupported job_type", ct).ConfigureAwait(false);
                return;
            }

            // Refuse if past not_after — leave the job queued for the poller.
            if (notAfter > 0 && NowUnix() > notAfter)
            {
                await SendCmdAck(commandId, jobId, false, "expired", ct).ConfigureAwait(false);
                return;
            }

            // Idempotency: if we already ran this job_id, re-send the result (cheap)
            // rather than re-executing (PHASE1-SPEC §1.6, §5.2).
            if (_state.HasSeenJob(jobId))
            {
                await SendCmdAck(commandId, jobId, true, "already_ran", ct).ConfigureAwait(false);
                var prior = _state.FindResult(jobId);
                if (prior != null) await SendCmdResult(prior, ct).ConfigureAwait(false);
                return;
            }

            await SendCmdAck(commandId, jobId, true, "", ct).ConfigureAwait(false);

            // Claim the job BEFORE executing. If the socket dies mid-run, the backend
            // requeues this job to 'queued'; marking it seen now makes the polling path
            // (Worker.DrainJobs) recognize we own it and NOT run it a second time. The
            // poller only re-reports once a concrete result lands in the outbox below;
            // while the result is still pending it simply yields to us.
            _state.MarkJobSeen(jobId);

            // Run on the thread-pool with single-flight gating so we keep serving the socket.
            var pending = await Task.Run(() => RunJobGated(jobId, jobType, payload)).ConfigureAwait(false);

            // Persist the result to disk BEFORE we try to send, so a crash can't lose it.
            _state.EnqueueResult(pending);

            await SendCmdResult(pending, ct).ConfigureAwait(false);
        }

        private PendingResult RunJobGated(int jobId, string jobType, string payload)
        {
            // Shared single-flight gate (JobRunner.ExecGate): never overlap a poll-path job.
            JobRunner.ExecGate.Wait();
            try
            {
                Log.Info($"Realtime: running job {jobId} ({jobType}).");
                JobResult r;
                try { r = _executor(jobType, payload); }
                catch (Exception ex)
                {
                    r = new JobResult { Success = false, ExitCode = -1, Output = "Agent exception: " + ex.Message };
                }

                var output = r.Output ?? "";
                bool truncated = false;
                if (Encoding.UTF8.GetByteCount(output) > WireOutputCap)
                {
                    output = TruncateUtf8(output, WireOutputCap);
                    truncated = true;
                }

                return new PendingResult
                {
                    JobId = jobId,
                    Status = r.Success ? "done" : "error",
                    ExitCode = r.ExitCode,
                    Output = output,
                    Truncated = truncated,
                    ProducedTs = NowUnix(),
                };
            }
            finally { JobRunner.ExecGate.Release(); }
        }

        // ────────────────────────────────────────────────────────────────────
        //  Outbound message builders.
        // ────────────────────────────────────────────────────────────────────
        private Task SendHello(string token, CancellationToken ct)
        {
            var d = new Dictionary<string, object>
            {
                ["agent_uid"] = _state.AgentUid ?? "",
                ["token"] = token,
                ["agent_version"] = Worker.Version,
                ["caps"] = new[] { "metrics", "exec" },
                ["boot_ts"] = BootUnix(),
                ["rustdesk_id"] = _rustDeskIdProvider != null ? (_rustDeskIdProvider() ?? "") : "",
            };
            return TrySendAsync(BuildEnvelope("hello", NewId(), null, d), ct);
        }

        private async Task<bool> AwaitWelcome(CancellationToken ct)
        {
            var deadline = DateTime.UtcNow.AddSeconds(HelloTimeoutSecs);
            var buf = new byte[16 * 1024];
            var msg = new System.IO.MemoryStream();

            while (DateTime.UtcNow < deadline && !ct.IsCancellationRequested && _ws.State == WebSocketState.Open)
            {
                using (var recvCts = CancellationTokenSource.CreateLinkedTokenSource(ct))
                {
                    var remaining = deadline - DateTime.UtcNow;
                    if (remaining <= TimeSpan.Zero) break;
                    recvCts.CancelAfter(remaining);
                    msg.SetLength(0);
                    WebSocketReceiveResult res;
                    try
                    {
                        do
                        {
                            res = await _ws.ReceiveAsync(new ArraySegment<byte>(buf), recvCts.Token).ConfigureAwait(false);
                            if (res.MessageType == WebSocketMessageType.Close)
                            {
                                await HandleServerClose(res).ConfigureAwait(false);
                                return false;
                            }
                            msg.Write(buf, 0, res.Count);
                        }
                        while (!res.EndOfMessage);
                    }
                    catch (OperationCanceledException) when (!ct.IsCancellationRequested)
                    {
                        return false; // welcome timeout
                    }
                    catch (WebSocketException) { return false; }
                }

                MarkInbound();
                var text = Encoding.UTF8.GetString(msg.GetBuffer(), 0, (int)msg.Length);
                Dictionary<string, object> env;
                try { env = _json.Deserialize<Dictionary<string, object>>(text); }
                catch { continue; }
                if (env == null) continue;

                if (Str(env, "t") == "welcome")
                {
                    var d = Dict(env, "d");
                    var mi = (int)Num(d, "metrics_interval", 60);
                    if (mi >= 5 && mi <= 3600) _metricsIntervalSecs = mi;
                    _lastMetricsTicks = Environment.TickCount - (_metricsIntervalSecs * 1000); // send soon
                    Volatile.Write(ref _welcomed, 1);
                    return true;
                }
                // Any other frame before welcome: ignore and keep waiting.
            }
            return false;
        }

        private Task SendCmdAck(string commandId, int jobId, bool accepted, string reason, CancellationToken ct)
        {
            var d = new Dictionary<string, object>
            {
                ["job_id"] = jobId,
                ["accepted"] = accepted,
                ["reason"] = reason ?? "",
            };
            return TrySendAsync(BuildEnvelope("cmd_ack", NewId(), commandId, d), ct);
        }

        private Task SendCmdResult(PendingResult r, CancellationToken ct)
        {
            var d = new Dictionary<string, object>
            {
                ["job_id"] = r.JobId,
                ["status"] = r.Status,
                ["exit_code"] = r.ExitCode,
                ["output"] = r.Output ?? "",
                ["truncated"] = r.Truncated,
            };
            return TrySendAsync(BuildEnvelope("cmd_result", NewId(), null, d), ct);
        }

        private async Task SendMetrics(CancellationToken ct)
        {
            if (Volatile.Read(ref _welcomed) != 1) return;
            Dictionary<string, object> d;
            try { d = _metrics.Sample(); }
            catch { return; }
            // metrics are fire-and-forget (id:null), lossy by design.
            await TrySendAsync(BuildEnvelope("metrics", null, null, d), ct).ConfigureAwait(false);
        }

        // ────────────────────────────────────────────────────────────────────
        //  Outbox: WS flush on connect, ack-gated; REST fallback after grace.
        // ────────────────────────────────────────────────────────────────────
        private async Task FlushOutboxViaWs(CancellationToken ct)
        {
            foreach (var r in _state.SnapshotOutbox())
            {
                if (ct.IsCancellationRequested) return;
                await SendCmdResult(r, ct).ConfigureAwait(false);
            }
        }

        // Write any result older than the grace via the existing REST path. Idempotent
        // by job_id: the second write hits a non-'running' row and no-ops on the portal.
        private void SweepOutboxFallback()
        {
            if (_restFallback == null) return;
            foreach (var r in _state.SnapshotOutbox())
            {
                if (NowUnix() - r.ProducedTs < RtResultFallbackSecs) continue;
                if (TryRestWriteResult(r))
                {
                    _state.RemoveResult(r.JobId);
                    Log.Info($"Realtime: result for job {r.JobId} delivered via REST fallback.");
                }
            }
        }

        private bool TryRestWriteResult(PendingResult r)
        {
            try
            {
                _restFallback.Post("/api/jobs.php", new Dictionary<string, object>
                {
                    ["job_id"] = r.JobId,
                    ["status"] = r.Status,
                    ["exit_code"] = r.ExitCode,
                    ["output"] = r.Output ?? "",
                });
                return true;
            }
            catch (ApiException aex)
            {
                // 401 will be handled by the polling loop (re-enroll); a terminal 4xx
                // (job already done) is effectively success for our purposes.
                if (aex.StatusCode >= 400 && aex.StatusCode < 500 && aex.StatusCode != 401)
                    return true;
                Log.Warn($"Realtime: REST fallback for job {r.JobId} failed: {aex.Message}");
                return false;
            }
            catch (Exception ex)
            {
                Log.Warn($"Realtime: REST fallback for job {r.JobId} error: {ex.Message}");
                return false;
            }
        }

        /// <summary>
        /// Public entry point so Worker can drain the outbox via REST while WS is down
        /// (called each polling cycle). Safe to call from the polling thread.
        /// </summary>
        public void DrainResultOutboxViaRest()
        {
            if (_restFallback == null) return;
            foreach (var r in _state.SnapshotOutbox())
            {
                // While disconnected, don't wait for the grace — push it now.
                bool overdue = NowUnix() - r.ProducedTs >= RtResultFallbackSecs;
                if (!overdue && IsConnected) continue;
                if (TryRestWriteResult(r))
                {
                    _state.RemoveResult(r.JobId);
                    Log.Info($"Realtime: outbox job {r.JobId} flushed via REST (WS down).");
                }
            }
        }

        // ────────────────────────────────────────────────────────────────────
        //  Socket send / close helpers.
        // ────────────────────────────────────────────────────────────────────
        private async Task TrySendAsync(string text, CancellationToken ct)
        {
            var ws = _ws;
            var gate = _sendGate;
            if (ws == null || gate == null || ws.State != WebSocketState.Open) return;

            var bytes = Encoding.UTF8.GetBytes(text);
            if (bytes.Length > MaxFrameBytes)
            {
                Log.Warn("Realtime: outbound frame exceeds cap; dropping.");
                return;
            }

            bool taken = false;
            try
            {
                await gate.WaitAsync(ct).ConfigureAwait(false);
                taken = true;
                await ws.SendAsync(new ArraySegment<byte>(bytes), WebSocketMessageType.Text, true, ct)
                        .ConfigureAwait(false);
            }
            catch (OperationCanceledException) { }
            catch (ObjectDisposedException) { }
            catch (WebSocketException wex) { Log.Warn("Realtime send failed: " + wex.Message); }
            finally { if (taken) try { gate.Release(); } catch { } }
        }

        private async Task HandleServerClose(WebSocketReceiveResult res)
        {
            int code = res.CloseStatus.HasValue ? (int)res.CloseStatus.Value : 1000;
            if (code == 4401)
            {
                // Auth failed / token revoked / archived: stop WS for this token.
                _tokenRejected = true;
                Log.Warn("Realtime: auth rejected (4401). Halting WS until token rotates; polling continues.");
            }
            await SafeClose(WebSocketCloseStatus.NormalClosure, "ack close").ConfigureAwait(false);
        }

        private async Task SafeClose(WebSocketCloseStatus status, string desc)
        {
            var ws = _ws;
            if (ws == null) return;
            try
            {
                if (ws.State == WebSocketState.Open || ws.State == WebSocketState.CloseReceived)
                {
                    using (var cts = new CancellationTokenSource(TimeSpan.FromSeconds(3)))
                        await ws.CloseOutputAsync(status, desc, cts.Token).ConfigureAwait(false);
                }
            }
            catch { /* best effort */ }
        }

        // ────────────────────────────────────────────────────────────────────
        //  Envelope + parsing helpers.
        // ────────────────────────────────────────────────────────────────────
        private string BuildEnvelope(string t, string id, string @ref, Dictionary<string, object> d)
        {
            var env = new Dictionary<string, object>
            {
                ["t"] = t,
                ["id"] = (object)id, // may be null (serialized as null)
                ["ts"] = NowUnix(),
                ["ref"] = (object)@ref,
                ["d"] = d ?? new Dictionary<string, object>(),
            };
            return _json.Serialize(env);
        }

        private static string NewId() => Guid.NewGuid().ToString();

        private static bool IsSecureWsUrl(string url)
        {
            if (string.IsNullOrEmpty(url)) return false;
            try { return string.Equals(new Uri(url).Scheme, "wss", StringComparison.OrdinalIgnoreCase); }
            catch { return false; }
        }

        private static bool IsAllowedJobType(string t)
        {
            switch ((t ?? "").ToLowerInvariant())
            {
                case "powershell":
                case "cmd":
                case "restart":
                case "message":
                    return true;
                default:
                    return false;
            }
        }

        private void MarkInbound() => _lastInboundTicks = Environment.TickCount;

        private static long TickElapsedSecs(long sinceTick)
        {
            long ms = unchecked(Environment.TickCount - sinceTick);
            if (ms < 0) ms = 0; // TickCount wrapped; treat as fresh
            return ms / 1000;
        }

        private static long NowUnix() => DateTimeOffset.UtcNow.ToUnixTimeSeconds();

        private static long BootUnix()
        {
            try { return NowUnix() - Math.Max(0, Environment.TickCount / 1000); }
            catch { return NowUnix(); }
        }

        private static string TruncateUtf8(string s, int maxBytes)
        {
            if (string.IsNullOrEmpty(s)) return s;
            var bytes = Encoding.UTF8.GetBytes(s);
            if (bytes.Length <= maxBytes) return s;
            // Trim to a valid UTF-8 boundary.
            int len = maxBytes;
            while (len > 0 && (bytes[len] & 0xC0) == 0x80) len--; // back off continuation bytes
            return Encoding.UTF8.GetString(bytes, 0, len);
        }

        private static string Str(Dictionary<string, object> r, string k) =>
            r != null && r.ContainsKey(k) && r[k] != null ? r[k].ToString() : "";

        private static string StrOrNull(Dictionary<string, object> r, string k) =>
            r != null && r.ContainsKey(k) && r[k] != null ? r[k].ToString() : null;

        private static double Num(Dictionary<string, object> r, string k, double dflt) =>
            r != null && r.ContainsKey(k) && r[k] != null && double.TryParse(r[k].ToString(), out var v) ? v : dflt;

        private static bool ToBool(Dictionary<string, object> r, string k)
        {
            if (r == null || !r.ContainsKey(k) || r[k] == null) return false;
            try { return Convert.ToBoolean(r[k]); } catch { return string.Equals(r[k].ToString(), "true", StringComparison.OrdinalIgnoreCase); }
        }

        private static Dictionary<string, object> Dict(Dictionary<string, object> r, string k) =>
            r != null && r.ContainsKey(k) ? r[k] as Dictionary<string, object> : null;
    }
}
