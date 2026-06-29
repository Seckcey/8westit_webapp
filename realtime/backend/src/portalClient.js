'use strict';

/**
 * Signed service client: backend -> portal HTTPS calls under /api/svc/*.
 *
 * Every request carries (spec §2.1):
 *   X-Milepost-Service:   <secret>                  static shared secret
 *   X-Milepost-Timestamp: <unix seconds>
 *   X-Milepost-Nonce:     <uuidv4>
 *   X-Milepost-Sign:      hex HMAC-SHA256(secret,
 *                           METHOD + "\n" + PATH + "\n" + TS + "\n" + NONCE + "\n" + sha256(body))
 *
 * PATH is the request path beginning with /api/svc/... (no query, no host).
 * body is the raw request bytes (empty string for GET/none).
 *
 * A token bucket caps total write rate (PORTAL_MAX_WRITE_RPS). Command results
 * are prioritized over presence/metrics by the caller passing `priority:true`,
 * which bypasses the bucket (durable data is never dropped/delayed for rate).
 */

const crypto = require('crypto');
const http = require('http');
const https = require('https');
const { URL } = require('url');

/** Simple token-bucket rate limiter. */
class TokenBucket {
  constructor(ratePerSec, burst) {
    this.rate = Math.max(0.001, ratePerSec);
    this.capacity = burst || Math.max(1, ratePerSec);
    this.tokens = this.capacity;
    this.last = Date.now();
  }

  _refill() {
    const now = Date.now();
    const elapsed = (now - this.last) / 1000;
    if (elapsed > 0) {
      this.tokens = Math.min(this.capacity, this.tokens + elapsed * this.rate);
      this.last = now;
    }
  }

  /** Try to take one token; returns true if granted. */
  tryTake() {
    this._refill();
    if (this.tokens >= 1) {
      this.tokens -= 1;
      return true;
    }
    return false;
  }
}

class PortalClient {
  /**
   * @param {object} cfg     config object (config.js)
   * @param {object} logger
   * @param {object} [deps]  { httpModule } injectable for tests
   */
  constructor(cfg, logger, deps = {}) {
    this.cfg = cfg;
    this.log = logger;
    this.secret = cfg.MILEPOST_SERVICE_SECRET;
    this.base = new URL(cfg.PORTAL_BASE_URL);
    this.transport = this.base.protocol === 'http:' ? http : https;
    if (deps.httpModule) this.transport = deps.httpModule;
    this.bucket = new TokenBucket(cfg.PORTAL_MAX_WRITE_RPS, cfg.PORTAL_MAX_WRITE_RPS);
    this.timeoutMs = deps.timeoutMs || 10000;
  }

  /**
   * Compute the request signature.
   * @param {string} method
   * @param {string} pathOnly  begins with /api/svc/...
   * @param {string} ts        unix seconds (string)
   * @param {string} nonce     uuidv4
   * @param {string} body      raw body bytes ('' for none)
   * @returns {string} hex HMAC
   */
  sign(method, pathOnly, ts, nonce, body) {
    const bodyHash = crypto.createHash('sha256').update(body, 'utf8').digest('hex');
    const base = `${method}\n${pathOnly}\n${ts}\n${nonce}\n${bodyHash}`;
    return crypto.createHmac('sha256', this.secret).update(base, 'utf8').digest('hex');
  }

  /**
   * Perform a signed POST to /api/svc/<endpoint>.php.
   * @param {string} endpoint  e.g. 'verify_agent' (no path, no extension)
   * @param {object} payload   JSON body
   * @param {object} [opts]    { priority }
   * @returns {Promise<object>} parsed JSON response
   */
  async post(endpoint, payload, opts = {}) {
    const pathOnly = `/api/svc/${endpoint}.php`;
    const body = JSON.stringify(payload || {});
    return this._send('POST', pathOnly, body, opts);
  }

  async _send(method, pathOnly, body, opts) {
    if (!opts.priority) {
      if (!this.bucket.tryTake()) {
        const err = new Error('portal write rate limited');
        err.code = 'RATE_LIMITED';
        throw err;
      }
    }

    const ts = String(Math.floor(Date.now() / 1000));
    const nonce = crypto.randomUUID();
    const sign = this.sign(method, pathOnly, ts, nonce, body);

    const url = new URL(pathOnly, this.base);
    const headers = {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(body),
      'X-Milepost-Service': this.secret,
      'X-Milepost-Timestamp': ts,
      'X-Milepost-Nonce': nonce,
      'X-Milepost-Sign': sign,
    };

    const reqOpts = {
      method,
      hostname: url.hostname,
      port: url.port || (url.protocol === 'https:' ? 443 : 80),
      path: url.pathname + url.search,
      headers,
      timeout: this.timeoutMs,
    };

    return new Promise((resolve, reject) => {
      const req = this.transport.request(reqOpts, (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const text = Buffer.concat(chunks).toString('utf8');
          let json;
          try {
            json = text ? JSON.parse(text) : {};
          } catch (e) {
            const err = new Error(`portal returned non-JSON (status ${res.statusCode})`);
            err.code = 'BAD_RESPONSE';
            err.status = res.statusCode;
            return reject(err);
          }
          if (res.statusCode < 200 || res.statusCode >= 300) {
            const err = new Error(
              `portal ${pathOnly} HTTP ${res.statusCode}: ${json.error || 'error'}`
            );
            err.code = 'HTTP_ERROR';
            err.status = res.statusCode;
            err.body = json;
            return reject(err);
          }
          resolve(json);
        });
      });
      req.on('error', (e) => {
        e.code = e.code || 'NETWORK_ERROR';
        reject(e);
      });
      req.on('timeout', () => {
        req.destroy(new Error('portal request timed out'));
      });
      req.write(body);
      req.end();
    });
  }
}

module.exports = { PortalClient, TokenBucket };
