'use strict';

/**
 * AgentConnection — wraps one WebSocket, owns its lifecycle state machine
 * (spec §1.2, §1.6): hello deadline, ping/pong liveness, send serialization,
 * backpressure awareness, and per-agent metrics cadence.
 */

const {
  decode,
  makeEnvelope,
  encode,
  uuid,
  nowSec,
} = require('./protocol');

const STATE = {
  CONNECTING: 'connecting', // socket open, awaiting hello
  ONLINE: 'online', // welcomed
  CLOSING: 'closing',
  CLOSED: 'closed',
};

// Close codes (spec §1.5).
const CLOSE = {
  NORMAL: 1000,
  PROTOCOL: 1002,
  UNSUPPORTED_DATA: 1003,
  TOO_LARGE: 1009,
  INTERNAL: 1011,
  AUTH_FAILED: 4401,
  NO_HELLO: 4408,
  DUPLICATE: 4409,
};

class AgentConnection {
  /**
   * @param {object} ws       a ws.WebSocket
   * @param {object} ctx      { cfg, log, auth, dispatch, store, hub }
   */
  constructor(ws, ctx) {
    this.ws = ws;
    this.cfg = ctx.cfg;
    this.log = ctx.log;
    this.auth = ctx.auth;
    this.dispatch = ctx.dispatch;
    this.store = ctx.store;
    this.hub = ctx.hub;

    this.state = STATE.CONNECTING;
    this.agentId = null;
    this.clientId = null;
    this.sessionId = null;
    this.agentUid = null;
    this.metricsInterval = this.cfg.DEFAULT_METRICS_INTERVAL_SECS;

    this.lastRecvAt = Date.now();
    this.missedPongs = 0;
    this.lastPingId = null;

    this._helloTimer = null;
    this._wired = false;
  }

  /** Begin: wire socket events + start the hello deadline. */
  start() {
    if (this._wired) return;
    this._wired = true;

    this.ws.on('message', (data, isBinary) => this._onMessage(data, isBinary));
    this.ws.on('close', (code, reason) => this._onClose(code, reason));
    this.ws.on('error', (err) => this._onError(err));

    this._helloTimer = setTimeout(() => {
      if (this.state === STATE.CONNECTING) {
        this.log.warn('hello deadline missed', { remote: this._remote() });
        this.close(CLOSE.NO_HELLO, 'no hello');
      }
    }, this.cfg.HELLO_TIMEOUT_SECS * 1000);
  }

  _remote() {
    try {
      return this.ws._socket && this.ws._socket.remoteAddress;
    } catch (e) {
      return undefined;
    }
  }

  /** Serialized send of an envelope. Returns false if not writable. */
  send(env) {
    if (this.ws.readyState !== 1 /* OPEN */) return false;
    try {
      this.ws.send(encode(env));
      return true;
    } catch (e) {
      this.log.debug('send failed', { agent_id: this.agentId, err: e.message });
      return false;
    }
  }

  sendError(code, msg, ref) {
    this.send(makeEnvelope('error', { code, msg }, { ref: ref || null }));
  }

  async _onMessage(data, isBinary) {
    this.lastRecvAt = Date.now();

    if (isBinary) {
      this.sendError('bad_message', 'binary frames not supported');
      return this.close(CLOSE.UNSUPPORTED_DATA, 'binary');
    }

    const res = decode(data);
    if (!res.ok) {
      this.sendError(res.code, res.msg);
      // Malformed framing is a protocol violation; close.
      return this.close(CLOSE.PROTOCOL, res.msg);
    }
    const env = res.env;

    // Before welcome, only `hello` is accepted (spec §1.2).
    if (this.state === STATE.CONNECTING && env.t !== 'hello') {
      this.sendError('not_authenticated', 'hello required first', env.id);
      return; // ignore; the hello timer will close if hello never comes
    }

    try {
      switch (env.t) {
        case 'hello':
          await this._onHello(env);
          break;
        case 'metrics':
          this._onMetrics(env);
          break;
        case 'cmd_ack':
          await this.dispatch.onCmdAck(this.agentId, env.d || {});
          break;
        case 'cmd_result':
          await this.dispatch.onCmdResult(this, env);
          break;
        case 'event':
          this._onEvent(env);
          break;
        case 'pong':
          this._onPong(env);
          break;
        case 'bye':
          this.log.info('agent bye', { agent_id: this.agentId, reason: (env.d || {}).reason });
          return this.close(CLOSE.NORMAL, 'bye');
        default:
          // Unknown type: ignore (forward-compat, spec §1.1).
          this.log.debug('ignoring unknown message type', { t: env.t });
      }
    } catch (e) {
      this.log.error('message handler error', { agent_id: this.agentId, t: env.t, err: e.message });
      // Non-fatal: surface as protocol error, keep the socket if possible.
      this.sendError('bad_message', 'handler error', env.id);
    }
  }

  async _onHello(env) {
    if (this.state !== STATE.CONNECTING) {
      // Duplicate hello; ignore.
      return;
    }
    const d = env.d || {};
    const rawToken = d.token;

    const verified = await this.auth.verify(rawToken);
    if (!verified.ok) {
      this.sendError('not_authenticated', 'auth failed', env.id);
      if (verified.transient) {
        // Portal unreachable — close 1011 so the agent backs off and keeps polling.
        return this.close(CLOSE.INTERNAL, 'portal unreachable');
      }
      return this.close(CLOSE.AUTH_FAILED, 'auth failed');
    }

    const id = verified.identity;
    this.agentId = id.agent_id;
    this.clientId = id.client_id;
    this.agentUid = d.agent_uid || null;
    this.sessionId = 's-' + uuid();
    this.state = STATE.ONLINE;
    clearTimeout(this._helloTimer);
    this._helloTimer = null;

    // Register with the hub; an older socket for the same agent is closed 4409.
    this.hub.register(this);
    this.store.setOnline(this.agentId);

    this.send(
      makeEnvelope(
        'welcome',
        {
          agent_id: this.agentId,
          session_id: this.sessionId,
          metrics_interval: this.metricsInterval,
          ping_interval: this.cfg.PING_INTERVAL_SECS,
          server_time: nowSec(),
          features: ['exec', 'metrics'],
        },
        { ref: env.id, id: uuid() }
      )
    );

    this.log.info('agent online', {
      agent_id: this.agentId,
      client_id: this.clientId,
      session_id: this.sessionId,
      agent_version: d.agent_version,
    });
  }

  _onMetrics(env) {
    if (this.state !== STATE.ONLINE) return;
    this.store.recordMetrics(this.agentId, env.d || {});
  }

  _onEvent(env) {
    const d = env.d || {};
    this.log.info('agent event', { agent_id: this.agentId, kind: d.kind });
    // rustdesk_ready / user_login etc. update presence-adjacent state if needed.
    if (d.kind === 'user_login' || d.kind === 'user_logout') {
      // logged-user info typically arrives via metrics; nothing durable here.
    }
  }

  _onPong(env) {
    this.missedPongs = 0;
    this.lastPingId = null;
  }

  /** Called by the hub's ping timer. Returns false if the socket looks dead. */
  tick(nowMs) {
    if (this.state !== STATE.ONLINE) return true;

    // Dead-socket detection: 45s of total silence -> close + let agent reconnect.
    if (nowMs - this.lastRecvAt > 45000) {
      this.log.warn('no traffic for 45s; closing', { agent_id: this.agentId });
      this.close(CLOSE.INTERNAL, 'idle timeout');
      return false;
    }

    // If a previous ping went unanswered, count it before sending the next.
    if (this.lastPingId) {
      this.missedPongs += 1;
      if (this.missedPongs >= 2) {
        this.log.warn('2 missed pongs; closing', { agent_id: this.agentId });
        this.close(CLOSE.INTERNAL, 'missed pongs');
        return false;
      }
    }

    const ping = makeEnvelope('ping', {}, { id: uuid() });
    this.lastPingId = ping.id;
    this.send(ping);
    return true;
  }

  /** Set per-agent metrics cadence (e.g. when a tech is watching). */
  setMetricsInterval(seconds) {
    this.metricsInterval = seconds;
    this.send(makeEnvelope('set_metrics_interval', { seconds }));
  }

  /** Backpressure check (spec §1.6). */
  isSaturated() {
    return this.ws.bufferedAmount > this.cfg.WS_BUFFER_HIGH_WATER;
  }

  close(code, reason) {
    if (this.state === STATE.CLOSED || this.state === STATE.CLOSING) return;
    this.state = STATE.CLOSING;
    if (this._helloTimer) {
      clearTimeout(this._helloTimer);
      this._helloTimer = null;
    }
    try {
      this.ws.close(code, (reason || '').slice(0, 120));
    } catch (e) {
      try {
        this.ws.terminate();
      } catch (e2) {
        /* ignore */
      }
    }
  }

  _onClose(code, reason) {
    const wasOnline = this.state === STATE.ONLINE;
    this.state = STATE.CLOSED;
    if (this._helloTimer) {
      clearTimeout(this._helloTimer);
      this._helloTimer = null;
    }
    if (this.agentId != null) {
      // Only act if we are still the registered connection (avoid a stale dup
      // 4409 close from clobbering the newer socket's presence).
      if (this.hub.current(this.agentId) === this) {
        this.hub.unregister(this.agentId);
        this.store.flagOffline(this.agentId);
        // Requeue any in-flight commands to polling.
        this.dispatch.onDisconnect(this.agentId).catch((e) =>
          this.log.warn('onDisconnect requeue failed', { agent_id: this.agentId, err: e.message })
        );
      }
      if (wasOnline) {
        this.log.info('agent offline', { agent_id: this.agentId, code });
      }
    }
  }

  _onError(err) {
    this.log.debug('ws error', { agent_id: this.agentId, err: err.message });
  }
}

module.exports = { AgentConnection, STATE, CLOSE };
