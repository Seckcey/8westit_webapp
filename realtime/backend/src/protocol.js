'use strict';

/**
 * Milepost real-time wire protocol (subprotocol `milepost.v1`).
 *
 * Envelope (every message, both directions):
 *   { "t": "<type>", "id": "<uuid-or-null>", "ts": <unix-seconds>, "ref": "<id-or-null>", "d": {} }
 *
 *   t   - message type (string, required)
 *   id  - uuidv4 message id; present when a reply/ack is expected, null for fire-and-forget
 *   ts  - Unix seconds, sender clock. ADVISORY ONLY; never used for authorization.
 *   ref - the `id` this message responds to; null/omitted otherwise
 *   d   - type-specific payload object (may be {})
 *
 * Forward-compat rules (spec §1.1):
 *   - Frames are UTF-8 text, exactly one JSON object per frame.
 *   - Unknown `t` values and unknown fields MUST be ignored.
 */

const SUBPROTOCOL = 'milepost.v1';

// Agent -> Backend message types.
const AGENT_MSG = new Set([
  'hello',
  'metrics',
  'cmd_ack',
  'cmd_result',
  'event',
  'pong',
  'bye',
]);

// Backend -> Agent message types.
const SERVER_MSG = new Set([
  'welcome',
  'command',
  'set_metrics_interval',
  'ping',
  'cmd_result_ack',
  'error',
]);

// Defined `event` kinds (spec §1.3). Unknown kinds are ignored, not rejected.
const EVENT_KINDS = new Set([
  'rustdesk_ready',
  'user_login',
  'user_logout',
  'reboot_pending',
]);

// Error codes (spec §1.4).
const ERROR_CODES = new Set([
  'bad_message',
  'rate_limit',
  'backpressure',
  'not_authenticated',
]);

/**
 * Generate a uuidv4. Uses node:crypto (built in), no external dependency.
 * @returns {string}
 */
function uuid() {
  // crypto.randomUUID is available on Node 14.17+/16+; spec targets node:20.
  return require('crypto').randomUUID();
}

/** Current Unix time in seconds. */
function nowSec() {
  return Math.floor(Date.now() / 1000);
}

/**
 * Build a protocol envelope.
 * @param {string} t       message type
 * @param {object} [d]     payload object
 * @param {object} [opts]  { id, ref, ts }
 * @returns {object} envelope
 */
function makeEnvelope(t, d = {}, opts = {}) {
  const env = {
    t,
    id: opts.id !== undefined ? opts.id : null,
    ts: opts.ts !== undefined ? opts.ts : nowSec(),
    ref: opts.ref !== undefined ? opts.ref : null,
    d: d == null ? {} : d,
  };
  return env;
}

/**
 * Serialize an envelope to a single UTF-8 JSON text frame.
 * @param {object} env
 * @returns {string}
 */
function encode(env) {
  return JSON.stringify(env);
}

/**
 * Parse one inbound text frame into a validated envelope.
 *
 * Returns { ok:true, env } on success or { ok:false, code, msg } on a
 * structural problem (caller decides whether to send `error` / close).
 *
 * Note: unknown `t` is NOT an error here — it parses fine; the dispatcher
 * is responsible for ignoring unknown types (forward-compat). This function
 * only rejects frames that are not a single JSON object with a string `t`.
 *
 * @param {string|Buffer} raw
 * @returns {{ok:true, env:object}|{ok:false, code:string, msg:string}}
 */
function decode(raw) {
  let text;
  if (typeof raw === 'string') {
    text = raw;
  } else if (Buffer.isBuffer(raw)) {
    text = raw.toString('utf8');
  } else {
    return { ok: false, code: 'bad_message', msg: 'non-text frame' };
  }

  let parsed;
  try {
    parsed = JSON.parse(text);
  } catch (e) {
    return { ok: false, code: 'bad_message', msg: 'invalid JSON' };
  }

  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return { ok: false, code: 'bad_message', msg: 'envelope must be an object' };
  }
  if (typeof parsed.t !== 'string' || parsed.t.length === 0) {
    return { ok: false, code: 'bad_message', msg: 'missing type' };
  }

  // Normalize optional fields without dropping unknown ones (forward-compat:
  // unknown fields are ignored by consumers but harmless if retained).
  const env = {
    t: parsed.t,
    id: typeof parsed.id === 'string' ? parsed.id : null,
    ts: typeof parsed.ts === 'number' ? parsed.ts : nowSec(),
    ref: typeof parsed.ref === 'string' ? parsed.ref : null,
    d: parsed.d != null && typeof parsed.d === 'object' && !Array.isArray(parsed.d)
      ? parsed.d
      : {},
  };
  return { ok: true, env };
}

/** True if `t` is a recognized agent->backend type. */
function isAgentType(t) {
  return AGENT_MSG.has(t);
}

/** True if `t` is a recognized backend->agent type. */
function isServerType(t) {
  return SERVER_MSG.has(t);
}

module.exports = {
  SUBPROTOCOL,
  AGENT_MSG,
  SERVER_MSG,
  EVENT_KINDS,
  ERROR_CODES,
  uuid,
  nowSec,
  makeEnvelope,
  encode,
  decode,
  isAgentType,
  isServerType,
};
