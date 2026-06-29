-- Milepost — Phase 1: real-time foundation + Client→Site→Group→Device policy + governed tools.
-- (Spec: realtime/PHASE1-SPEC.md §4. This is the portal-side migration deliverable.)
--
-- Run ONCE on the live database via phpMyAdmin (SQL tab: paste; or Import: upload this file).
-- MySQL 8 does NOT support "ALTER TABLE ... ADD COLUMN IF NOT EXISTS" — these are PLAIN
-- ALTER/CREATE statements. "CREATE TABLE IF NOT EXISTS" is fine. If a column already exists
-- you'll get "Duplicate column name" — delete that line and re-run.
--
-- Everything here is ADDITIVE and backward compatible: all new columns are nullable/defaulted,
-- existing rows are untouched, and the existing 60s polling code reads NONE of the new columns.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ───────────────────────────────────────────────────────────────────────────
-- 1) HIERARCHY: Sites + Groups.
--    `site` was free-text on agents/enrollment_keys; we promote it to a first-class
--    row while keeping the existing text column for backward compatibility.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sites (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id  INT UNSIGNED NOT NULL,
  name       VARCHAR(128) NOT NULL,            -- mirrors the existing agents.site string
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sites_client_name (client_id, name),
  KEY idx_sites_client (client_id),
  CONSTRAINT fk_sites_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS device_groups (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id  INT UNSIGNED NOT NULL,
  site_id    INT UNSIGNED NULL,                -- NULL = client-wide group
  parent_id  INT UNSIGNED NULL,                -- nestable groups
  name       VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_group_client (client_id),
  KEY idx_group_site (site_id),
  KEY idx_group_parent (parent_id),
  CONSTRAINT fk_group_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_group_site FOREIGN KEY (site_id)
    REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────────
-- 2) Hook agents into the hierarchy + real-time/presence state (additive, nullable).
-- ───────────────────────────────────────────────────────────────────────────
ALTER TABLE agents
  ADD COLUMN site_id            INT UNSIGNED NULL AFTER client_id,
  ADD COLUMN group_id           INT UNSIGNED NULL AFTER site_id,
  ADD COLUMN presence           ENUM('online','stale','offline') NOT NULL DEFAULT 'offline' AFTER last_seen_at,
  ADD COLUMN rt_supported       TINYINT(1) NOT NULL DEFAULT 0 AFTER agent_version,
  ADD COLUMN rt_last_connect_at DATETIME NULL AFTER rt_supported,
  ADD COLUMN policy_etag        CHAR(12) NULL AFTER tags;

ALTER TABLE agents
  ADD KEY idx_agents_site (site_id),
  ADD KEY idx_agents_group (group_id),
  ADD KEY idx_agents_presence (presence),
  ADD CONSTRAINT fk_agents_site  FOREIGN KEY (site_id)  REFERENCES sites(id)         ON DELETE SET NULL,
  ADD CONSTRAINT fk_agents_group FOREIGN KEY (group_id) REFERENCES device_groups(id) ON DELETE SET NULL;

-- ───────────────────────────────────────────────────────────────────────────
-- 3) POLICIES + ASSIGNMENTS (generic key/value docs, inheritance by scope).
--    Inheritance: global → client → site → group → device (device wins).
--    Resolution is computed in PHP (portal/lib/policy.php), never recursive SQL.
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS policies (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(128) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  doc_json    JSON NOT NULL,                   -- the settings blob this policy carries
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_policy_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS policy_assignments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  policy_id   INT UNSIGNED NOT NULL,
  scope_type  ENUM('global','client','site','group','device') NOT NULL,
  scope_id    INT UNSIGNED NULL,               -- NULL only when scope_type='global'
  priority    INT NOT NULL DEFAULT 100,        -- tie-break within a scope level (higher wins)
  is_enabled  TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assign (policy_id, scope_type, scope_id),
  KEY idx_assign_scope (scope_type, scope_id),
  CONSTRAINT fk_assign_policy FOREIGN KEY (policy_id) REFERENCES policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────────
-- 4) GOVERNED TOOLS (groundwork for AI/automation with approval).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tool_actions (
  id                VARCHAR(64)  NOT NULL,      -- slug, e.g. 'restart-print-spooler'
  display_name      VARCHAR(128) NOT NULL,
  job_type          ENUM('powershell','cmd','restart','message') NOT NULL DEFAULT 'powershell',
  payload_tmpl      MEDIUMTEXT   NOT NULL,      -- template; {{params}} substituted at invoke
  params_schema     JSON         NOT NULL,      -- JSON Schema for allowed params
  tier              ENUM('read','standard','elevated','destructive') NOT NULL DEFAULT 'standard',
  max_blast_radius  INT          NOT NULL DEFAULT 1,
  requires_approval TINYINT(1)   NOT NULL DEFAULT 1,
  is_enabled        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tool_invocations (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  action_id     VARCHAR(64)  NOT NULL,
  agent_id      INT UNSIGNED NOT NULL,
  params_json   JSON         NOT NULL,
  tier          ENUM('read','standard','elevated','destructive') NOT NULL DEFAULT 'standard',
  blast_radius  INT          NOT NULL DEFAULT 1,
  requested_by  VARCHAR(64)  NOT NULL DEFAULT '',  -- 'user:3' | 'ai:assistant' | 'system'
  status        ENUM('pending','approved','denied','dispatched','done','error','expired')
                NOT NULL DEFAULT 'pending',
  approved_by   INT UNSIGNED NULL,
  job_id        INT UNSIGNED NULL,              -- the jobs row created on approval
  reason        VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at    DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_inv_agent (agent_id),
  KEY idx_inv_status (status),
  CONSTRAINT fk_inv_action FOREIGN KEY (action_id)   REFERENCES tool_actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_agent  FOREIGN KEY (agent_id)    REFERENCES agents(id)       ON DELETE CASCADE,
  CONSTRAINT fk_inv_user   FOREIGN KEY (approved_by) REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_inv_job    FOREIGN KEY (job_id)      REFERENCES jobs(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────────
-- 5) JOBS: record delivery path, queue time, and the optional tool link (additive).
--    Existing columns (agent_id, created_by, job_type, payload, status, exit_code,
--    output, created_at, picked_at, finished_at) are unchanged.
--
--    NOTE: the live `jobs` table ALREADY has KEY idx_jobs_agent_status (agent_id, status)
--    from the base schema, so this migration does NOT re-add it (that would error).
-- ───────────────────────────────────────────────────────────────────────────
ALTER TABLE jobs
  ADD COLUMN queued_at      DATETIME NULL AFTER status,
  ADD COLUMN delivered_via  ENUM('poll','realtime') NOT NULL DEFAULT 'poll' AFTER queued_at,
  ADD COLUMN tool_action_id VARCHAR(64) NULL AFTER delivered_via;

-- ───────────────────────────────────────────────────────────────────────────
-- 6) LATEST METRICS (one row per agent; durable snapshot for normal page loads).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS agent_metrics_latest (
  agent_id     INT UNSIGNED NOT NULL,
  cpu          DECIMAL(5,2) NULL,
  mem          DECIMAL(5,2) NULL,
  disk_c       DECIMAL(5,2) NULL,
  uptime_secs  BIGINT UNSIGNED NULL,
  logged_user  VARCHAR(128) NOT NULL DEFAULT '',
  sampled_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (agent_id),
  CONSTRAINT fk_metrics_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────────
-- 7) SERVICE NONCES (replay protection for backend→portal calls).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS service_nonces (
  nonce    VARCHAR(36) NOT NULL,
  seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (nonce),
  KEY idx_sn_seen (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────────
-- 8) ONE-TIME BACKFILL (optional; safe to run after the DDL above).
--    Create a site row per (client_id, site) pair seen on existing agents, then link.
-- ───────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO sites (client_id, name)
  SELECT DISTINCT client_id, site FROM agents WHERE site <> '' AND client_id IS NOT NULL;

UPDATE agents a JOIN sites s ON s.client_id = a.client_id AND s.name = a.site
  SET a.site_id = s.id WHERE a.site_id IS NULL AND a.site <> '';
