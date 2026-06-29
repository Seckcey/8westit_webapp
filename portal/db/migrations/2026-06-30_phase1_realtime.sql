/*
  Milepost — Phase 1: real-time foundation + Client->Site->Group->Device policy + governed tools.
  (Spec: realtime/PHASE1-SPEC.md section 4. Portal-side migration deliverable.)

  HOW TO RUN: cPanel > phpMyAdmin > select your DB (qygiabte_8westit_webapp) > Import tab >
  upload this file > Go.  Block comments are used throughout, so this also runs correctly if you
  paste it into the SQL tab: line breaks are not required for the comments to terminate.

  MySQL 8 has NO "ALTER TABLE ... ADD COLUMN IF NOT EXISTS". These are plain ALTER/CREATE.
  "CREATE TABLE IF NOT EXISTS" is fine. If a re-run reports "Duplicate column name", the ALTER
  already applied on a previous run — delete that ALTER block and re-run the rest.

  Everything here is ADDITIVE and backward compatible: all new columns are nullable/defaulted,
  existing rows are untouched, and the existing 60s polling code reads NONE of the new columns.
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

/* 1) HIERARCHY: Sites + Groups. `site` was free-text on agents/enrollment_keys; we promote it
      to a first-class row while keeping the existing text column for backward compatibility. */
CREATE TABLE IF NOT EXISTS sites (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id  INT UNSIGNED NOT NULL,
  name       VARCHAR(128) NOT NULL,
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
  site_id    INT UNSIGNED NULL,
  parent_id  INT UNSIGNED NULL,
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

/* 2) Hook agents into the hierarchy + real-time/presence state (additive, nullable). */
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

/* 3) POLICIES + ASSIGNMENTS (generic key/value docs, inheritance by scope).
      Inheritance: global -> client -> site -> group -> device (device wins).
      Resolution is computed in PHP (portal/lib/policy.php), never recursive SQL. */
CREATE TABLE IF NOT EXISTS policies (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(128) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  doc_json    JSON NOT NULL,
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
  scope_id    INT UNSIGNED NULL,
  priority    INT NOT NULL DEFAULT 100,
  is_enabled  TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assign (policy_id, scope_type, scope_id),
  KEY idx_assign_scope (scope_type, scope_id),
  CONSTRAINT fk_assign_policy FOREIGN KEY (policy_id) REFERENCES policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* 4) GOVERNED TOOLS (groundwork for AI/automation with approval). */
CREATE TABLE IF NOT EXISTS tool_actions (
  id                VARCHAR(64)  NOT NULL,
  display_name      VARCHAR(128) NOT NULL,
  job_type          ENUM('powershell','cmd','restart','message') NOT NULL DEFAULT 'powershell',
  payload_tmpl      MEDIUMTEXT   NOT NULL,
  params_schema     JSON         NOT NULL,
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
  requested_by  VARCHAR(64)  NOT NULL DEFAULT '',
  status        ENUM('pending','approved','denied','dispatched','done','error','expired')
                NOT NULL DEFAULT 'pending',
  approved_by   INT UNSIGNED NULL,
  job_id        INT UNSIGNED NULL,
  reason        VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at    DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_inv_agent (agent_id),
  KEY idx_inv_status (status),
  /* FK names are prefixed fk_toolinv_* (NOT fk_inv_*): the base schema's `inventory`
     table already owns `fk_inv_agent`, and MySQL 8 requires FK constraint names to be
     unique per database (MariaDB does not enforce this, so local tests miss it). */
  CONSTRAINT fk_toolinv_action FOREIGN KEY (action_id)   REFERENCES tool_actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_toolinv_agent  FOREIGN KEY (agent_id)    REFERENCES agents(id)       ON DELETE CASCADE,
  CONSTRAINT fk_toolinv_user   FOREIGN KEY (approved_by) REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_toolinv_job    FOREIGN KEY (job_id)      REFERENCES jobs(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* 5) JOBS: record delivery path, queue time, and the optional tool link (additive).
      The live `jobs` table ALREADY has KEY idx_jobs_agent_status from the base schema,
      so this migration does NOT re-add it (that would error). */
ALTER TABLE jobs
  ADD COLUMN queued_at      DATETIME NULL AFTER status,
  ADD COLUMN delivered_via  ENUM('poll','realtime') NOT NULL DEFAULT 'poll' AFTER queued_at,
  ADD COLUMN tool_action_id VARCHAR(64) NULL AFTER delivered_via;

/* 6) LATEST METRICS (one row per agent; durable snapshot for normal page loads). */
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

/* 7) SERVICE NONCES (replay protection for backend->portal calls). */
CREATE TABLE IF NOT EXISTS service_nonces (
  nonce    VARCHAR(36) NOT NULL,
  seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (nonce),
  KEY idx_sn_seen (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* 8) ONE-TIME BACKFILL (safe to run after the DDL above).
      Create a site row per (client_id, site) pair seen on existing agents, then link. */
INSERT IGNORE INTO sites (client_id, name)
  SELECT DISTINCT client_id, site FROM agents WHERE site <> '' AND client_id IS NOT NULL;

UPDATE agents a JOIN sites s ON s.client_id = a.client_id AND s.name = a.site
  SET a.site_id = s.id WHERE a.site_id IS NULL AND a.site <> '';
