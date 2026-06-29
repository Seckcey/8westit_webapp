'use strict';

/**
 * Shared-secret service signature helpers (spec §2.1), used in BOTH directions:
 *  - portalClient signs backend -> portal calls.
 *  - server.js verifies portal -> backend calls on /internal/* using verify().
 *
 * Signature base string:
 *   METHOD + "\n" + PATH + "\n" + TS + "\n" + NONCE + "\n" + sha256(body)
 */

const crypto = require('crypto');

function bodyHash(body) {
  return crypto.createHash('sha256').update(body || '', 'utf8').digest('hex');
}

/**
 * @param {string} secret
 * @param {string} method
 * @param {string} pathOnly  request path (no query)
 * @param {string} ts        unix seconds (string)
 * @param {string} nonce
 * @param {string} body      raw body bytes
 * @returns {string} hex HMAC-SHA256
 */
function computeSign(secret, method, pathOnly, ts, nonce, body) {
  const base = `${method}\n${pathOnly}\n${ts}\n${nonce}\n${bodyHash(body)}`;
  return crypto.createHmac('sha256', secret).update(base, 'utf8').digest('hex');
}

/** Constant-time string compare (length-safe). */
function safeEqual(a, b) {
  const ba = Buffer.from(String(a), 'utf8');
  const bb = Buffer.from(String(b), 'utf8');
  if (ba.length !== bb.length) return false;
  return crypto.timingSafeEqual(ba, bb);
}

/**
 * Verify an inbound service request (portal -> backend on /internal/*).
 *
 * Validates: static secret header (constant-time), timestamp within window,
 * nonce shape + single-use, and the HMAC signature. Tracks used nonces in the
 * supplied `nonceStore` (a Set-like with .has/.add and self-pruning) to reject
 * replays within the window.
 *
 * @param {object} args
 * @param {string} args.secret
 * @param {string} args.method
 * @param {string} args.path       request path (no query)
 * @param {string} args.body       raw request body
 * @param {object} args.headers    lower-cased header map (Node http gives these)
 * @param {number} args.windowSecs replay window
 * @param {object} args.nonceStore { has(nonce), add(nonce, ts) }
 * @returns {{ok:true}|{ok:false, code:string, msg:string}}
 */
function verify(args) {
  const { secret, method, path, body, headers, windowSecs, nonceStore } = args;
  if (!secret) return { ok: false, code: 'not_configured', msg: 'service secret not set' };

  const sent = headers['x-milepost-service'] || '';
  const ts = headers['x-milepost-timestamp'] || '';
  const nonce = headers['x-milepost-nonce'] || '';
  const sign = headers['x-milepost-sign'] || '';

  if (!safeEqual(secret, sent)) return { ok: false, code: 'unauthorized', msg: 'bad secret' };

  if (!/^\d+$/.test(String(ts))) return { ok: false, code: 'stale', msg: 'bad timestamp' };
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - parseInt(ts, 10)) > windowSecs) {
    return { ok: false, code: 'stale', msg: 'timestamp outside window' };
  }
  if (!/^[0-9a-fA-F-]{36}$/.test(String(nonce))) {
    return { ok: false, code: 'bad_nonce', msg: 'bad nonce' };
  }

  const want = computeSign(secret, method, path, String(ts), String(nonce), body);
  if (!safeEqual(want, sign)) return { ok: false, code: 'bad_signature', msg: 'bad signature' };

  if (nonceStore.has(nonce)) return { ok: false, code: 'replay', msg: 'nonce reused' };
  nonceStore.add(nonce, now);

  return { ok: true };
}

/** A self-pruning nonce store (window-bounded), used by server.js. */
class NonceStore {
  constructor(windowSecs) {
    this.windowSecs = windowSecs;
    this.map = new Map(); // nonce -> seenAtSec
  }
  has(nonce) {
    return this.map.has(nonce);
  }
  add(nonce, ts) {
    this.map.set(nonce, ts);
    // Opportunistic prune.
    if (this.map.size > 2048) this.prune();
  }
  prune() {
    const cutoff = Math.floor(Date.now() / 1000) - this.windowSecs;
    for (const [n, t] of this.map) {
      if (t < cutoff) this.map.delete(n);
    }
  }
}

module.exports = { computeSign, bodyHash, safeEqual, verify, NonceStore };
