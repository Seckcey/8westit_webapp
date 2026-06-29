'use strict';

/**
 * Agent WS authentication (spec §2.3).
 *
 * The backend cannot verify the bearer token itself (no DB), so on `hello` it
 * asks the portal via POST /api/svc/verify_agent.php. Results are cached IN
 * MEMORY for VERIFY_CACHE_TTL_SECS, keyed on sha256(token) — never the raw
 * token. The raw token is used transiently for the portal call and never
 * logged or persisted.
 */

const crypto = require('crypto');

function sha256Hex(s) {
  return crypto.createHash('sha256').update(s, 'utf8').digest('hex');
}

class AgentAuthenticator {
  /**
   * @param {object} portalClient  PortalClient instance
   * @param {object} cfg
   * @param {object} logger
   * @param {object} [deps] { now } injectable clock for tests (ms)
   */
  constructor(portalClient, cfg, logger, deps = {}) {
    this.portal = portalClient;
    this.cfg = cfg;
    this.log = logger;
    this.ttlMs = cfg.VERIFY_CACHE_TTL_SECS * 1000;
    this.now = deps.now || (() => Date.now());
    this.cache = new Map(); // tokenHash -> { result, expiresAt }
  }

  _cacheGet(hash) {
    const ent = this.cache.get(hash);
    if (!ent) return undefined;
    if (ent.expiresAt <= this.now()) {
      this.cache.delete(hash);
      return undefined;
    }
    return ent.result;
  }

  _cacheSet(hash, result) {
    this.cache.set(hash, { result, expiresAt: this.now() + this.ttlMs });
  }

  /**
   * Verify a raw bearer token via the portal (cached).
   *
   * @param {string} rawToken
   * @returns {Promise<{ok:true, identity:object}|{ok:false, reason:string}>}
   *   identity = { agent_id, client_id, site_id, site, hostname, display_name,
   *                policy_etag }
   */
  async verify(rawToken) {
    if (typeof rawToken !== 'string' || rawToken.length === 0) {
      return { ok: false, reason: 'missing token' };
    }
    const hash = sha256Hex(rawToken);

    const cached = this._cacheGet(hash);
    if (cached !== undefined) {
      return cached;
    }

    let resp;
    try {
      // verify_agent is a read; prioritize so reconnect storms aren't dropped
      // by the write rate bucket, but it is still a portal round-trip.
      resp = await this.portal.post('verify_agent', { token: rawToken }, { priority: true });
    } catch (e) {
      // Portal unreachable: DO NOT cache; surface as a transient failure so the
      // backend can close 1011 (agent keeps polling and retries WS later).
      this.log.warn('verify_agent portal call failed', { code: e.code, status: e.status });
      return { ok: false, reason: 'portal_unreachable', transient: true };
    }

    if (resp && resp.ok === true) {
      const identity = {
        agent_id: resp.agent_id,
        client_id: resp.client_id,
        site_id: resp.site_id,
        site: resp.site,
        hostname: resp.hostname,
        display_name: resp.display_name,
        is_archived: resp.is_archived === true,
        policy_etag: resp.policy_etag || null,
      };
      const result = { ok: true, identity };
      this._cacheSet(hash, result);
      return result;
    }

    // Known bad token: cache the negative result so a flood of the same revoked
    // token doesn't hammer the portal. (Cleared naturally on TTL expiry.)
    const result = { ok: false, reason: 'unknown_token' };
    this._cacheSet(hash, result);
    return result;
  }

  /** Invalidate a cached entry (e.g. after a 4401 observed elsewhere). */
  invalidate(rawToken) {
    this.cache.delete(sha256Hex(rawToken));
  }

  /** Expose hashing so callers never key on raw tokens. */
  static hash(rawToken) {
    return sha256Hex(rawToken);
  }
}

module.exports = { AgentAuthenticator, sha256Hex };
