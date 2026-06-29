'use strict';

/**
 * Shared test helpers: a mock portal (records calls, scriptable responses),
 * a way to boot the real backend against it on an ephemeral port, a mock WS
 * agent client, and a signed /internal HTTP caller.
 */

const http = require('http');
const crypto = require('crypto');
const { WebSocket } = require('ws');

const { createServer } = require('../src/server');
const { PortalClient } = require('../src/portalClient');
const { createLogger } = require('../src/logger');
const serviceSign = require('../src/serviceSign');
const protocol = require('../src/protocol');

const TEST_SECRET = 'a'.repeat(64); // 64 hex

/** Build a config object suitable for tests (fast timers, in-memory store). */
function testConfig(overrides = {}) {
  return Object.assign(
    {
      PORT: 0, // ephemeral
      BIND: '127.0.0.1',
      WS_PATH: '/agent',
      PORTAL_BASE_URL: 'http://127.0.0.1:1', // unused; PortalClient is mocked
      MILEPOST_SERVICE_SECRET: TEST_SECRET,
      SERVICE_REPLAY_WINDOW_SECS: 300,
      PING_INTERVAL_SECS: 1,
      HELLO_TIMEOUT_SECS: 1,
      DEFAULT_METRICS_INTERVAL_SECS: 60,
      WATCHING_METRICS_INTERVAL_SECS: 15,
      VERIFY_CACHE_TTL_SECS: 300,
      METRICS_SNAPSHOT_THROTTLE_SECS: 60,
      PORTAL_MAX_WRITE_RPS: 100,
      WS_BUFFER_HIGH_WATER: 1048576,
      MAX_WS_PAYLOAD_BYTES: 262144,
      RESULT_FALLBACK_GRACE_SECS: 30,
      SQLITE_PATH: '',
      LOG_LEVEL: 'error', // keep test output quiet
    },
    overrides
  );
}

/**
 * A mock PortalClient drop-in. Same `.post(endpoint, payload, opts)` surface.
 * `handlers` maps endpoint -> (payload) => responseObject (or throws).
 */
class MockPortal {
  constructor(handlers = {}) {
    this.handlers = handlers;
    this.calls = []; // { endpoint, payload, opts }
  }
  async post(endpoint, payload, opts = {}) {
    this.calls.push({ endpoint, payload, opts });
    const h = this.handlers[endpoint];
    if (!h) {
      const e = new Error('no mock handler for ' + endpoint);
      e.code = 'NETWORK_ERROR';
      throw e;
    }
    return h(payload, opts);
  }
  callsTo(endpoint) {
    return this.calls.filter((c) => c.endpoint === endpoint);
  }
}

/** Boot the real backend with a mock portal on an ephemeral port. */
async function bootBackend({ cfg = {}, portalHandlers = {} } = {}) {
  const config = testConfig(cfg);
  const portal = new MockPortal(portalHandlers);
  const app = createServer({
    cfg: config,
    portalClient: portal,
    logger: createLogger(config.LOG_LEVEL),
  });
  await app.start();
  const addr = app.address();
  const base = `127.0.0.1:${addr.port}`;
  return {
    app,
    portal,
    port: addr.port,
    wsUrl: `ws://${base}${config.WS_PATH}`,
    httpBase: `http://${base}`,
    async close() {
      await app.stop();
    },
  };
}

/**
 * A mock WS agent client. Connects with the milepost.v1 subprotocol, lets the
 * test send envelopes and await specific inbound types.
 */
class MockAgent {
  constructor(wsUrl) {
    this.ws = new WebSocket(wsUrl, [protocol.SUBPROTOCOL]);
    this.inbox = [];
    this._waiters = [];
    this.closed = null;
    this.ws.on('message', (data) => {
      const res = protocol.decode(data);
      if (res.ok) {
        this.inbox.push(res.env);
        this._drain();
      }
    });
    this.ws.on('close', (code, reason) => {
      this.closed = { code, reason: reason && reason.toString() };
      for (const w of this._waiters) w.reject(new Error('socket closed ' + code));
      this._waiters = [];
    });
  }

  open() {
    return new Promise((resolve, reject) => {
      this.ws.once('open', resolve);
      this.ws.once('error', reject);
      this.ws.once('close', (c) => reject(new Error('closed before open: ' + c)));
    });
  }

  send(t, d = {}, opts = {}) {
    const env = protocol.makeEnvelope(t, d, Object.assign({ id: protocol.uuid() }, opts));
    this.ws.send(protocol.encode(env));
    return env;
  }

  sendRaw(text) {
    this.ws.send(text);
  }

  _drain() {
    for (let i = this._waiters.length - 1; i >= 0; i--) {
      const w = this._waiters[i];
      const idx = this.inbox.findIndex(w.match);
      if (idx !== -1) {
        const env = this.inbox.splice(idx, 1)[0];
        this._waiters.splice(i, 1);
        w.resolve(env);
      }
    }
  }

  /** Wait for the next inbound envelope of type `t` (consumes it). */
  waitFor(t, timeoutMs = 2000) {
    return new Promise((resolve, reject) => {
      const match = (env) => env.t === t;
      const idx = this.inbox.findIndex(match);
      if (idx !== -1) return resolve(this.inbox.splice(idx, 1)[0]);
      const timer = setTimeout(() => {
        this._waiters = this._waiters.filter((x) => x !== waiter);
        reject(new Error('timeout waiting for ' + t));
      }, timeoutMs);
      const waiter = {
        match,
        resolve: (v) => {
          clearTimeout(timer);
          resolve(v);
        },
        reject: (e) => {
          clearTimeout(timer);
          reject(e);
        },
      };
      this._waiters.push(waiter);
    });
  }

  /** Wait for the socket to close, resolving with { code, reason }. */
  waitClose(timeoutMs = 2000) {
    return new Promise((resolve, reject) => {
      if (this.closed) return resolve(this.closed);
      const timer = setTimeout(() => reject(new Error('timeout waiting for close')), timeoutMs);
      this.ws.once('close', (code, reason) => {
        clearTimeout(timer);
        resolve({ code, reason: reason && reason.toString() });
      });
    });
  }

  close() {
    try {
      this.ws.close();
    } catch (e) {
      /* ignore */
    }
  }
}

/** Make a signed /internal request (mimics the portal calling the backend). */
function signedRequest(httpBase, method, pathOnly, bodyObj, secret = TEST_SECRET) {
  const body = bodyObj === undefined || bodyObj === null ? '' : JSON.stringify(bodyObj);
  const ts = String(Math.floor(Date.now() / 1000));
  const nonce = crypto.randomUUID();
  const url = new URL(pathOnly, httpBase);
  // PATH in the signature base is the pathname only (no query string), matching
  // serviceSign.verify() on the server, which uses url.pathname.
  const sign = serviceSign.computeSign(secret, method, url.pathname, ts, nonce, body);
  const opts = {
    method,
    hostname: url.hostname,
    port: url.port,
    path: url.pathname + url.search,
    headers: {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(body),
      'X-Milepost-Service': secret,
      'X-Milepost-Timestamp': ts,
      'X-Milepost-Nonce': nonce,
      'X-Milepost-Sign': sign,
    },
  };
  return rawHttp(opts, body);
}

function plainGet(httpBase, pathOnly) {
  const url = new URL(pathOnly, httpBase);
  return rawHttp(
    { method: 'GET', hostname: url.hostname, port: url.port, path: url.pathname + url.search, headers: {} },
    ''
  );
}

function rawHttp(opts, body) {
  return new Promise((resolve, reject) => {
    const req = http.request(opts, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        const text = Buffer.concat(chunks).toString('utf8');
        let json = null;
        try {
          json = text ? JSON.parse(text) : null;
        } catch (e) {
          /* leave null */
        }
        resolve({ status: res.statusCode, json, text });
      });
    });
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

module.exports = {
  TEST_SECRET,
  testConfig,
  MockPortal,
  bootBackend,
  MockAgent,
  signedRequest,
  plainGet,
  PortalClient,
  serviceSign,
  protocol,
};
