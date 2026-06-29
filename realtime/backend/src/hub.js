'use strict';

/**
 * ConnectionHub — the live registry of authenticated agent connections.
 * One connection per agent_id; a new connection for an already-connected agent
 * closes the OLDER socket with 4409 (spec §1.5, "duplicate connection").
 */

const { CLOSE } = require('./connection');

class ConnectionHub {
  constructor(logger) {
    this.log = logger;
    this.byAgent = new Map(); // agentId -> AgentConnection
  }

  /** Register a freshly-welcomed connection; evict any older one (4409). */
  register(conn) {
    const existing = this.byAgent.get(conn.agentId);
    if (existing && existing !== conn) {
      this.log.info('duplicate connection; closing older socket', { agent_id: conn.agentId });
      existing.close(CLOSE.DUPLICATE, 'duplicate connection');
    }
    this.byAgent.set(conn.agentId, conn);
  }

  unregister(agentId) {
    this.byAgent.delete(agentId);
  }

  /** The current registered connection for an agent (or undefined). */
  current(agentId) {
    return this.byAgent.get(agentId);
  }

  isOnline(agentId) {
    const c = this.byAgent.get(agentId);
    return !!c && c.state === 'online';
  }

  count() {
    return this.byAgent.size;
  }

  all() {
    return [...this.byAgent.values()];
  }
}

module.exports = { ConnectionHub };
