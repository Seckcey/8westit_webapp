'use strict';

/**
 * In-memory presence + latest-metrics store, with an OPTIONAL SQLite cache
 * (spec §2.4, §6). The store is ephemeral and rebuildable: if the backend is
 * wiped, polling still works and the portal stays the source of truth.
 *
 * SQLite is only loaded when SQLITE_PATH is set AND `better-sqlite3` is
 * installed; otherwise the store is pure in-memory and the optional dependency
 * is never required (keeps `npm ci --omit=dev` minimal).
 */

class LiveStore {
  /**
   * @param {object} cfg
   * @param {object} logger
   * @param {object} [deps] { now } ms clock for tests
   */
  constructor(cfg, logger, deps = {}) {
    this.cfg = cfg;
    this.log = logger;
    this.now = deps.now || (() => Date.now());

    // agentId -> { online, last_metrics_ts, cpu, mem, disk_c, uptime_secs,
    //              logged_user, local_ip, last_user, last_snapshot_at }
    this.presence = new Map();

    this.db = null;
    this._initSqlite();
  }

  _initSqlite() {
    if (!this.cfg.SQLITE_PATH) return;
    let Database;
    try {
      // Optional dependency; absence is fine (pure in-memory).
      Database = require('better-sqlite3');
    } catch (e) {
      this.log.warn('SQLITE_PATH set but better-sqlite3 not installed; using in-memory only');
      return;
    }
    try {
      const fs = require('fs');
      const path = require('path');
      const dir = path.dirname(this.cfg.SQLITE_PATH);
      if (dir && dir !== '.') fs.mkdirSync(dir, { recursive: true });
      this.db = new Database(this.cfg.SQLITE_PATH);
      this.db.pragma('journal_mode = WAL');
      this.db.exec(`
        CREATE TABLE IF NOT EXISTS metrics_cache (
          agent_id     INTEGER PRIMARY KEY,
          cpu          REAL,
          mem          REAL,
          disk_c       REAL,
          uptime_secs  INTEGER,
          logged_user  TEXT,
          local_ip     TEXT,
          last_user    TEXT,
          online       INTEGER,
          sampled_at   INTEGER
        );
        CREATE TABLE IF NOT EXISTS result_outbox (
          job_id     INTEGER PRIMARY KEY,
          agent_id   INTEGER NOT NULL,
          status     TEXT NOT NULL,
          exit_code  INTEGER,
          output     TEXT,
          created_at INTEGER NOT NULL
        );
      `);
      this._loadFromSqlite();
      this.log.info('SQLite cache enabled', { path: this.cfg.SQLITE_PATH });
    } catch (e) {
      this.log.warn('failed to open SQLite cache; using in-memory only', { err: e.message });
      this.db = null;
    }
  }

  _loadFromSqlite() {
    if (!this.db) return;
    const rows = this.db.prepare('SELECT * FROM metrics_cache').all();
    for (const r of rows) {
      this.presence.set(r.agent_id, {
        online: false, // never trust persisted "online" across a restart
        last_metrics_ts: r.sampled_at,
        cpu: r.cpu,
        mem: r.mem,
        disk_c: r.disk_c,
        uptime_secs: r.uptime_secs,
        logged_user: r.logged_user || '',
        local_ip: r.local_ip || '',
        last_user: r.last_user || '',
        last_snapshot_at: 0,
      });
    }
  }

  /** Mark an agent online (on welcome). */
  setOnline(agentId) {
    const ent = this.presence.get(agentId) || {};
    ent.online = true;
    ent.last_snapshot_at = ent.last_snapshot_at || 0;
    this.presence.set(agentId, ent);
  }

  /** Mark an agent offline (on disconnect / bye). */
  setOffline(agentId) {
    const ent = this.presence.get(agentId);
    if (ent) {
      ent.online = false;
      this.presence.set(agentId, ent);
    } else {
      this.presence.set(agentId, { online: false });
    }
  }

  /**
   * Record the latest metrics push (in-memory + SQLite cache). Lossy by design;
   * this NEVER writes to the portal — only the throttled snapshot does.
   * @param {number} agentId
   * @param {object} m metrics payload from a `metrics` frame
   */
  recordMetrics(agentId, m) {
    const ent = this.presence.get(agentId) || { online: true };
    ent.online = true;
    ent.last_metrics_ts = Math.floor(this.now() / 1000);
    if (m.cpu !== undefined) ent.cpu = m.cpu;
    if (m.mem !== undefined) ent.mem = m.mem;
    if (m.disk_c !== undefined) ent.disk_c = m.disk_c;
    if (m.uptime_secs !== undefined) ent.uptime_secs = m.uptime_secs;
    if (m.logged_user !== undefined) {
      ent.logged_user = m.logged_user;
      ent.last_user = m.logged_user;
    }
    if (m.local_ip !== undefined) ent.local_ip = m.local_ip;
    this.presence.set(agentId, ent);

    if (this.db) {
      try {
        this.db
          .prepare(
            `INSERT INTO metrics_cache
               (agent_id,cpu,mem,disk_c,uptime_secs,logged_user,local_ip,last_user,online,sampled_at)
             VALUES (@agent_id,@cpu,@mem,@disk_c,@uptime_secs,@logged_user,@local_ip,@last_user,1,@sampled_at)
             ON CONFLICT(agent_id) DO UPDATE SET
               cpu=excluded.cpu, mem=excluded.mem, disk_c=excluded.disk_c,
               uptime_secs=excluded.uptime_secs, logged_user=excluded.logged_user,
               local_ip=excluded.local_ip, last_user=excluded.last_user,
               online=1, sampled_at=excluded.sampled_at`
          )
          .run({
            agent_id: agentId,
            cpu: ent.cpu ?? null,
            mem: ent.mem ?? null,
            disk_c: ent.disk_c ?? null,
            uptime_secs: ent.uptime_secs ?? null,
            logged_user: ent.logged_user ?? '',
            local_ip: ent.local_ip ?? '',
            last_user: ent.last_user ?? '',
            sampled_at: ent.last_metrics_ts,
          });
      } catch (e) {
        this.log.debug('sqlite metrics cache write failed', { err: e.message });
      }
    }
  }

  /** Read presence for a set of agent ids (for /internal/presence). */
  getPresence(agentIds) {
    const out = {};
    for (const id of agentIds) {
      const ent = this.presence.get(id);
      if (!ent || !ent.online) {
        out[id] = { online: false };
      } else {
        out[id] = {
          online: true,
          last_metrics_ts: ent.last_metrics_ts || null,
          cpu: ent.cpu ?? null,
          mem: ent.mem ?? null,
          disk_c: ent.disk_c ?? null,
          uptime_secs: ent.uptime_secs ?? null,
        };
      }
    }
    return out;
  }

  /**
   * Collect agents whose snapshot is due (older than throttle window) and the
   * batched payload for metrics_snapshot.php. Marks them snapshotted at `now`.
   * @returns {Array<object>} items array for the snapshot request
   */
  collectDueSnapshots() {
    const nowSec = Math.floor(this.now() / 1000);
    const throttle = this.cfg.METRICS_SNAPSHOT_THROTTLE_SECS;
    const items = [];
    for (const [agentId, ent] of this.presence) {
      const due = nowSec - (ent.last_snapshot_at || 0) >= throttle;
      if (!due) continue;
      // Only snapshot agents that are online OR transitioning to offline.
      if (ent.online) {
        items.push({
          agent_id: agentId,
          online: true,
          local_ip: ent.local_ip || '',
          last_user: ent.last_user || '',
          cpu: ent.cpu ?? null,
          mem: ent.mem ?? null,
          disk_c: ent.disk_c ?? null,
          uptime_secs: ent.uptime_secs ?? null,
        });
        ent.last_snapshot_at = nowSec;
      } else if (ent.pendingOffline) {
        items.push({ agent_id: agentId, online: false });
        ent.last_snapshot_at = nowSec;
        ent.pendingOffline = false;
      }
    }
    return items;
  }

  /** Flag an agent as needing an offline snapshot on next flush. */
  flagOffline(agentId) {
    const ent = this.presence.get(agentId) || {};
    ent.online = false;
    ent.pendingOffline = true;
    this.presence.set(agentId, ent);
  }

  // ── Result outbox (SQLite ring; survives backend restart) ───────────────
  persistResult(rec) {
    if (!this.db) return;
    try {
      this.db
        .prepare(
          `INSERT OR REPLACE INTO result_outbox
             (job_id,agent_id,status,exit_code,output,created_at)
           VALUES (?,?,?,?,?,?)`
        )
        .run(rec.job_id, rec.agent_id, rec.status, rec.exit_code ?? null, rec.output ?? '', Math.floor(this.now() / 1000));
    } catch (e) {
      this.log.debug('sqlite result outbox write failed', { err: e.message });
    }
  }

  clearResult(jobId) {
    if (!this.db) return;
    try {
      this.db.prepare('DELETE FROM result_outbox WHERE job_id=?').run(jobId);
    } catch (e) {
      this.log.debug('sqlite result outbox delete failed', { err: e.message });
    }
  }

  pendingResults() {
    if (!this.db) return [];
    try {
      return this.db.prepare('SELECT * FROM result_outbox ORDER BY created_at ASC').all();
    } catch (e) {
      return [];
    }
  }

  close() {
    if (this.db) {
      try {
        this.db.close();
      } catch (e) {
        /* ignore */
      }
      this.db = null;
    }
  }
}

module.exports = { LiveStore };
