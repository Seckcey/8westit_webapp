'use strict';

/**
 * Milepost real-time backend — entry point.
 *
 * Responsibilities:
 *  - WebSocket server (ws) on ws://BIND:PORT WS_PATH (TLS terminates at Caddy).
 *  - Agent auth on `hello` via the portal (wsAuth), short verify cache.
 *  - Presence/connection registry (hub), latest-metrics store (liveStore).
 *  - Command push + result handling (dispatch).
 *  - Portal-facing REST: /internal/dispatch, /internal/presence, /healthz —
 *    guarded by the shared service secret (serviceSign).
 *  - Ping/pong keepalive + dead-connection cleanup.
 *  - Throttled, batched metrics snapshot flush to the portal.
 *  - Graceful shutdown.
 *
 * Config is strictly via .env (config.js).
 */

const http = require('http');
const { WebSocketServer } = require('ws');

const { loadDotEnv, buildConfig, validateConfig } = require('./config');
const { createLogger } = require('./logger');
const { PortalClient } = require('./portalClient');
const { AgentAuthenticator } = require('./wsAuth');
const { LiveStore } = require('./liveStore');
const { Dispatcher } = require('./dispatch');
const { ConnectionHub } = require('./hub');
const { AgentConnection } = require('./connection');
const serviceSign = require('./serviceSign');

/**
 * Construct the backend (without starting listeners). Exported for tests.
 * @param {object} [overrides] { cfg, portalClient } for injection in tests
 * @returns {object} app with { cfg, log, start, stop, server, wss, ... }
 */
function createServer(overrides = {}) {
  const cfg = overrides.cfg || buildConfig();
  const log = overrides.logger || createLogger(cfg.LOG_LEVEL);

  const portal = overrides.portalClient || new PortalClient(cfg, log);
  const auth = overrides.auth || new AgentAuthenticator(portal, cfg, log);
  const store = overrides.store || new LiveStore(cfg, log);
  const dispatch = overrides.dispatch || new Dispatcher(portal, store, log);
  const hub = new ConnectionHub(log);
  const nonceStore = new serviceSign.NonceStore(cfg.SERVICE_REPLAY_WINDOW_SECS);

  const startedAt = Date.now();
  let portalReachable = true;

  const connCtx = { cfg, log, auth, dispatch, store, hub };

  // ── HTTP server: hosts both the WS upgrade and the /internal + /healthz API.
  const server = http.createServer((req, res) => handleHttp(req, res));

  const wss = new WebSocketServer({
    noServer: true,
    maxPayload: cfg.MAX_WS_PAYLOAD_BYTES,
    handleProtocols: (protocols) => {
      // Pin the subprotocol; reject if the client didn't request milepost.v1.
      const want = require('./protocol').SUBPROTOCOL;
      return protocols.has(want) ? want : false;
    },
  });

  server.on('upgrade', (req, socket, head) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname !== cfg.WS_PATH) {
      socket.write('HTTP/1.1 404 Not Found\r\n\r\n');
      socket.destroy();
      return;
    }
    wss.handleUpgrade(req, socket, head, (ws) => {
      if (ws.protocol !== require('./protocol').SUBPROTOCOL) {
        // Subprotocol mismatch -> protocol violation.
        try {
          ws.close(1002, 'subprotocol');
        } catch (e) {
          /* ignore */
        }
        return;
      }
      const conn = new AgentConnection(ws, connCtx);
      conn.start();
    });
  });

  // ── HTTP request router (service-secret guarded except /healthz) ──────────
  function handleHttp(req, res) {
    const url = new URL(req.url, 'http://localhost');
    const pathOnly = url.pathname;

    if (pathOnly === '/healthz' && req.method === 'GET') {
      return sendJson(res, 200, {
        ok: true,
        connections: hub.count(),
        portal_reachable: portalReachable,
        uptime_s: Math.floor((Date.now() - startedAt) / 1000),
      });
    }

    if (pathOnly.startsWith('/internal/')) {
      return readBody(req, (body) => {
        const check = serviceSign.verify({
          secret: cfg.MILEPOST_SERVICE_SECRET,
          method: req.method,
          path: pathOnly,
          body,
          headers: req.headers,
          windowSecs: cfg.SERVICE_REPLAY_WINDOW_SECS,
          nonceStore,
        });
        if (!check.ok) {
          log.warn('service auth rejected', { path: pathOnly, code: check.code });
          return sendJson(res, 401, { ok: false, error: check.msg });
        }
        return routeInternal(req, res, pathOnly, body);
      });
    }

    return sendJson(res, 404, { ok: false, error: 'not found' });
  }

  function routeInternal(req, res, pathOnly, body) {
    if (pathOnly === '/internal/dispatch' && req.method === 'POST') {
      let job;
      try {
        job = JSON.parse(body || '{}');
      } catch (e) {
        return sendJson(res, 400, { ok: false, error: 'bad json' });
      }
      // Authorization boundary: the agent executes whatever we push as SYSTEM, so
      // validate the request shape here even though /internal is service-secret
      // guarded. job_type is constrained to the known enum (the agent enforces the
      // same allow-list as defense in depth), and ids must be positive integers.
      const ALLOWED_JOB_TYPES = new Set(['powershell', 'cmd', 'restart', 'message']);
      if (!Number.isInteger(job.agent_id) || job.agent_id <= 0 ||
          !Number.isInteger(job.job_id) || job.job_id <= 0) {
        return sendJson(res, 400, { ok: false, error: 'bad agent_id/job_id' });
      }
      if (!ALLOWED_JOB_TYPES.has(String(job.job_type))) {
        log.warn('dispatch refused: unsupported job_type', {
          agent_id: job.agent_id, job_id: job.job_id, job_type: job.job_type,
        });
        return sendJson(res, 400, { ok: false, error: 'unsupported job_type' });
      }
      const conn = hub.current(job.agent_id);
      if (!conn || conn.state !== 'online') {
        // Agent not connected -> portal leaves the job queued for polling.
        return sendJson(res, 200, { ok: true, delivered: false });
      }
      if (conn.isSaturated()) {
        log.warn('dispatch refused: backpressure', { agent_id: job.agent_id });
        return sendJson(res, 200, { ok: true, delivered: false });
      }
      const ok = dispatch.deliver(conn, {
        job_id: job.job_id,
        job_type: job.job_type,
        payload: job.payload,
        timeout_secs: job.timeout_secs,
        not_after: job.not_after,
        tool_action_id: job.tool_action_id,
      });
      return sendJson(res, 200, { ok: true, delivered: ok });
    }

    if (pathOnly === '/internal/presence' && req.method === 'GET') {
      const url = new URL(req.url, 'http://localhost');
      const idsParam = url.searchParams.get('agent_ids') || '';
      const ids = idsParam
        .split(',')
        .map((s) => parseInt(s.trim(), 10))
        .filter((n) => Number.isFinite(n));
      const presence = store.getPresence(ids);
      // Re-key as strings to match the spec's JSON ("42": {...}).
      const agents = {};
      for (const [k, v] of Object.entries(presence)) agents[String(k)] = v;
      return sendJson(res, 200, { ok: true, agents });
    }

    return sendJson(res, 404, { ok: false, error: 'unknown internal route' });
  }

  // ── Background timers ────────────────────────────────────────────────────
  let pingTimer = null;
  let snapshotTimer = null;

  function startTimers() {
    pingTimer = setInterval(() => {
      const now = Date.now();
      for (const conn of hub.all()) {
        try {
          conn.tick(now);
        } catch (e) {
          log.debug('tick error', { agent_id: conn.agentId, err: e.message });
        }
      }
      // Requeue any delivered-but-unresolved commands so a silently-dropped frame
      // can't leave a job stuck 'running' with no polling fallback.
      dispatch.sweepStale(now).catch((e) => log.debug('sweep error', { err: e.message }));
    }, cfg.PING_INTERVAL_SECS * 1000);
    if (pingTimer.unref) pingTimer.unref();

    // Flush throttled, batched presence/metrics snapshots to the portal.
    snapshotTimer = setInterval(() => {
      flushSnapshots().catch((e) => log.debug('snapshot flush error', { err: e.message }));
    }, Math.max(5, Math.floor(cfg.METRICS_SNAPSHOT_THROTTLE_SECS / 4)) * 1000);
    if (snapshotTimer.unref) snapshotTimer.unref();
  }

  async function flushSnapshots() {
    const items = store.collectDueSnapshots();
    if (items.length === 0) return;
    try {
      await portal.post('metrics_snapshot', { ts: Math.floor(Date.now() / 1000), items });
      portalReachable = true;
    } catch (e) {
      if (e.code === 'RATE_LIMITED') return; // try again next cycle
      portalReachable = false;
      log.warn('metrics_snapshot flush failed', { code: e.code, count: items.length });
    }
  }

  function start() {
    return new Promise((resolve) => {
      server.listen(cfg.PORT, cfg.BIND, () => {
        startTimers();
        log.info('milepost-rt listening', {
          bind: cfg.BIND,
          port: cfg.PORT,
          ws_path: cfg.WS_PATH,
          portal: cfg.PORTAL_BASE_URL,
          sqlite: cfg.SQLITE_PATH || '(in-memory)',
        });
        resolve();
      });
    });
  }

  function stop() {
    return new Promise((resolve) => {
      log.info('shutting down');
      if (pingTimer) clearInterval(pingTimer);
      if (snapshotTimer) clearInterval(snapshotTimer);
      // Tell agents we're going away (1001), they reconnect with backoff.
      for (const conn of hub.all()) {
        conn.close(1001, 'server shutdown');
      }
      wss.close(() => {
        server.close(() => {
          try {
            store.close();
          } catch (e) {
            /* ignore */
          }
          resolve();
        });
      });
      // Hard cap so shutdown never hangs.
      setTimeout(() => resolve(), 5000).unref?.();
    });
  }

  return { cfg, log, server, wss, hub, store, dispatch, auth, portal, start, stop, address: () => server.address() };
}

// ── HTTP helpers ────────────────────────────────────────────────────────────
function sendJson(res, code, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
}

function readBody(req, cb) {
  const chunks = [];
  let size = 0;
  req.on('data', (c) => {
    size += c.length;
    if (size > 2 * 1024 * 1024) {
      req.destroy();
      return;
    }
    chunks.push(c);
  });
  req.on('end', () => cb(Buffer.concat(chunks).toString('utf8')));
  req.on('error', () => cb(''));
}

// ── Bootstrap when run directly ──────────────────────────────────────────────
if (require.main === module) {
  loadDotEnv();
  const cfg = buildConfig();
  try {
    validateConfig(cfg);
  } catch (e) {
    process.stderr.write(e.message + '\n');
    process.exit(1);
  }
  const app = createServer({ cfg });
  app.start();

  let shuttingDown = false;
  const shutdown = async (sig) => {
    if (shuttingDown) return;
    shuttingDown = true;
    app.log.info('signal received', { sig });
    await app.stop();
    process.exit(0);
  };
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('uncaughtException', (e) => {
    app.log.error('uncaughtException', { err: e.message, stack: e.stack });
  });
  process.on('unhandledRejection', (e) => {
    app.log.error('unhandledRejection', { err: e && e.message });
  });
}

module.exports = { createServer };
