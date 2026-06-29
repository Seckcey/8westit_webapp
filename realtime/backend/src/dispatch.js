'use strict';

/**
 * Command dispatch + result handling (spec §1.6, §2.4, §3).
 *
 * Two-phase ownership for correctness:
 *  - The backend keeps each `command` in a per-agent outbox until `cmd_result`
 *    arrives (so an undeliverable/lost command can be requeued to polling).
 *  - On `cmd_result` the backend persists to the portal via job_result.php;
 *    on success it sends `cmd_result_ack` so the agent may drop its copy.
 *
 * Idempotency is by `job_id` end to end. The portal's job_result.php is
 * idempotent (0 rows affected -> already terminal), so retries/double-writes
 * are safe.
 */

const { makeEnvelope, encode } = require('./protocol');

class Dispatcher {
  /**
   * @param {object} portalClient
   * @param {object} liveStore
   * @param {object} logger
   */
  constructor(portalClient, liveStore, logger) {
    this.portal = portalClient;
    this.store = liveStore;
    this.log = logger;
    // agentId -> Map(job_id -> { command, sentAt })
    this.outbox = new Map();
  }

  _agentOutbox(agentId) {
    let m = this.outbox.get(agentId);
    if (!m) {
      m = new Map();
      this.outbox.set(agentId, m);
    }
    return m;
  }

  /**
   * Deliver a command to a connected agent's socket. Records it in the outbox.
   *
   * @param {object} conn  the AgentConnection (has .agentId and .send())
   * @param {object} job   { job_id, job_type, payload, timeout_secs, not_after,
   *                         tool_action_id }
   * @returns {boolean} true if the frame was queued to the socket
   */
  deliver(conn, job) {
    const env = makeEnvelope('command', {
      job_id: job.job_id,
      job_type: job.job_type,
      payload: job.payload,
      timeout_secs: job.timeout_secs ?? 300,
      not_after: job.not_after ?? null,
      tool_action_id: job.tool_action_id ?? null,
    }, { id: require('./protocol').uuid() });

    const ok = conn.send(env);
    if (ok) {
      this._agentOutbox(conn.agentId).set(job.job_id, {
        command: job,
        commandId: env.id,
        sentAt: Date.now(),
      });
      this.log.info('command delivered', {
        agent_id: conn.agentId,
        job_id: job.job_id,
        job_type: job.job_type,
      });
    }
    return ok;
  }

  /** An agent acked a command. accepted:false means it won't run -> requeue. */
  async onCmdAck(agentId, d) {
    const jobId = d.job_id;
    if (d.accepted === false) {
      this.log.info('command refused by agent', { agent_id: agentId, job_id: jobId, reason: d.reason });
      this._agentOutbox(agentId).delete(jobId);
      await this._requeue(agentId, jobId);
    }
  }

  /**
   * An agent reported a terminal result. Persist to portal, then ack on success.
   *
   * @param {object} conn
   * @param {object} env   the full cmd_result envelope (for ref correlation)
   * @returns {Promise<void>}
   */
  async onCmdResult(conn, env) {
    const d = env.d || {};
    const agentId = conn.agentId;
    const jobId = d.job_id;
    const status = d.status === 'error' ? 'error' : 'done';

    // Buffer in the SQLite ring so a restart before ack doesn't lose it.
    this.store.persistResult({
      job_id: jobId,
      agent_id: agentId,
      status,
      exit_code: d.exit_code ?? null,
      output: d.output ?? '',
    });

    let resp;
    try {
      resp = await this.portal.post(
        'job_result',
        {
          agent_id: agentId,
          job_id: jobId,
          status,
          exit_code: d.exit_code ?? null,
          output: d.output ?? '',
        },
        { priority: true } // durable data: never dropped by the rate bucket
      );
    } catch (e) {
      // Persistence failed: do NOT ack. The agent's REST fallback (30s) is the
      // backstop; we keep the buffered copy and retry on the next result/flush.
      this.log.warn('job_result persist failed; not acking', {
        agent_id: agentId,
        job_id: jobId,
        code: e.code,
      });
      return;
    }

    if (resp && resp.ok === true) {
      // Drop from both outboxes; tell the agent it is durably persisted.
      this._agentOutbox(agentId).delete(jobId);
      this.store.clearResult(jobId);
      const ack = makeEnvelope('cmd_result_ack', { job_id: jobId, persisted: true }, { ref: env.id });
      conn.send(ack);
      this.log.info('cmd_result persisted + acked', {
        agent_id: agentId,
        job_id: jobId,
        status,
        already: resp.already === true,
      });
    } else {
      this.log.warn('portal job_result returned ok:false', { agent_id: agentId, job_id: jobId });
    }
  }

  /** Requeue an undeliverable/refused RT job back to the polling path. */
  async _requeue(agentId, jobId) {
    try {
      await this.portal.post('job_requeue', { agent_id: agentId, job_id: jobId }, { priority: true });
      this.log.info('job requeued to poll', { agent_id: agentId, job_id: jobId });
    } catch (e) {
      this.log.warn('job_requeue failed', { agent_id: agentId, job_id: jobId, code: e.code });
    }
  }

  /**
   * Agent disconnected: any commands still in the outbox were never confirmed
   * complete, so requeue them to polling (the poller reclaims them).
   * @param {number} agentId
   */
  async onDisconnect(agentId) {
    const m = this.outbox.get(agentId);
    if (!m || m.size === 0) return;
    const jobs = [...m.keys()];
    this.outbox.delete(agentId);
    for (const jobId of jobs) {
      await this._requeue(agentId, jobId);
    }
  }

  /** Outstanding (unresolved) command count for an agent — for tests/metrics. */
  pendingCount(agentId) {
    const m = this.outbox.get(agentId);
    return m ? m.size : 0;
  }

  /**
   * Requeue commands that were delivered but never resolved — no cmd_ack(false)
   * and no cmd_result — within a generous grace. This covers the case where the
   * agent silently drops/ignores the frame (or its handler dies) while the socket
   * stays open and keeps answering pings: without this sweep that job would sit
   * 'running' in MySQL forever and never fall back to polling, since the portal's
   * expired sweep only fires on a poll GET that never happens for a running RT job.
   *
   * Grace is derived per-command from its timeout_secs (2x + 60s slack), so it is
   * always safely longer than the agent's own execution cap. Called from the ping
   * timer (server.js).
   *
   * @param {number} now Date.now()
   */
  async sweepStale(now) {
    for (const [agentId, m] of this.outbox) {
      for (const [jobId, entry] of [...m]) {
        const timeoutSecs = (entry.command && entry.command.timeout_secs) || 300;
        const graceMs = (timeoutSecs * 2 + 60) * 1000;
        if (now - entry.sentAt > graceMs) {
          m.delete(jobId);
          this.log.warn('command unresolved past grace; requeuing to poll', {
            agent_id: agentId,
            job_id: jobId,
            age_ms: now - entry.sentAt,
          });
          await this._requeue(agentId, jobId);
        }
      }
      if (m.size === 0) this.outbox.delete(agentId);
    }
  }
}

module.exports = { Dispatcher };
