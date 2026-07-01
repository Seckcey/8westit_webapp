/*
  Milepost — Phase 2 (Step 3): smart alerting — threshold engine + alert lifecycle.
  (Roadmap Phase 2 "smart alerting": policy-based thresholds inherited Client->Site->Group->Device
   on the existing policies/policy_assignments engine, an alert lifecycle open->ack->resolve for
   MTTA/MTTR, and multi-channel delivery. This migration adds only the lifecycle/state/outbox
   tables; the THRESHOLDS THEMSELVES live in policy doc_json under a `thresholds` key and are
   resolved by portal/lib/policy.php — so no new "rules" table and no migration for new metrics.)

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import tab >
  upload this file > Go.  Block comments are used throughout, so this ALSO runs correctly pasted
  into the SQL tab (lost line breaks cannot swallow a following statement).

  MySQL 8 has NO "ALTER TABLE ... ADD COLUMN IF NOT EXISTS" (MariaDB-only). This file is pure
  "CREATE TABLE IF NOT EXISTS" (safe to re-run) with NO ALTER of existing tables. FK constraint
  names are globally unique per database (fk_alerts_*, fk_astate_*, fk_adeliv_*) — MySQL 8
  enforces per-DB uniqueness even though MariaDB does not (the Phase-1 fk_inv_agent lesson).

  Everything here is ADDITIVE and backward compatible: three brand-new tables, existing rows and
  the existing real-time/polling code paths are untouched. Alerting stays dark until config.php
  `alerts.enabled` is true, so importing this migration alone changes nothing user-visible.

  THRESHOLD DOC SHAPE (lives in a policy's doc_json, merged by lib/policy.php; device wins):
    "thresholds": {
      "cpu":              { "op":"gt", "warning":90, "critical":97, "for_min":10 },
      "mem":              { "op":"gt", "warning":90, "critical":97, "for_min":10 },
      "disk_pct:C:":      { "op":"gt", "warning":85, "critical":95, "for_min":0  },
      "disk_free_gb:C:":  { "op":"lt", "warning":20, "critical":5,  "for_min":0  },
      "disk_health":      { "op":"lt", "critical":1,  "for_min":0 },
      "offline":          { "for_min":10, "severity":"critical" }
    }
  Keys are "<metric_key>" or "<metric_key>:<instance>" (matching agent_metric_samples), plus the
  synthetic "offline" rule (evaluated from agents.last_seen_at by the dispatch cron, not a metric).
  Any key absent from the doc falls back to the built-in floor in lib/alerts.php, so alerting works
  out-of-the-box with zero seeding; a global policy can override per key.
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

/* 1) ALERTS — the open->ack->resolve lifecycle row (one row per breach episode).
      Anti-fatigue: at most ONE active (status IN ('open','acked')) row per (agent_id, rule_key)
      is created by lib/alerts.php; a warning that worsens to critical UPDATES the same row
      (severity bump) rather than opening a second alert. Resolved rows are retained for MTTA/MTTR
      history. `rule_key` is the metric identity ("cpu", "disk_pct:C:") or the synthetic "offline". */
CREATE TABLE IF NOT EXISTS alerts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id      INT UNSIGNED    NOT NULL,
  rule_key      VARCHAR(80)     NOT NULL,
  metric_key    VARCHAR(40)     NOT NULL DEFAULT '',
  instance      VARCHAR(64)     NOT NULL DEFAULT '',
  severity      ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  status        ENUM('open','acked','resolved')   NOT NULL DEFAULT 'open',
  compare_op    ENUM('gt','lt','gte','lte','eq')  NOT NULL DEFAULT 'gt',
  threshold     DOUBLE          NULL,
  trigger_value DOUBLE          NULL,           /* value that first opened the alert */
  last_val      DOUBLE          NULL,           /* most recent evaluated value (last_value is a MySQL-8 reserved word) */
  message       VARCHAR(255)    NOT NULL DEFAULT '',
  opened_at     DATETIME        NOT NULL,
  acked_at      DATETIME        NULL,
  acked_by      INT UNSIGNED    NULL,
  resolved_at   DATETIME        NULL,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  /* the active-alert lookup: WHERE agent_id=? AND rule_key=? AND status IN('open','acked') */
  KEY idx_alerts_active (agent_id, rule_key, status),
  KEY idx_alerts_status_opened (status, opened_at),
  KEY idx_alerts_agent (agent_id),
  CONSTRAINT fk_alerts_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_alerts_user  FOREIGN KEY (acked_by) REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* 2) ALERT_STATE — per (agent, rule) breach/clear tracking for sustained-duration thresholds
      ("> X for N minutes") and hysteresis, so a value flapping across the line does NOT spam
      open/resolve. One row per (agent_id, rule_key); rewritten in place each evaluation. */
CREATE TABLE IF NOT EXISTS alert_state (
  agent_id     INT UNSIGNED NOT NULL,
  rule_key     VARCHAR(80)  NOT NULL,
  breach_level ENUM('none','warning','critical') NOT NULL DEFAULT 'none',
  breach_since DATETIME     NULL,   /* first sample of the current consecutive breach; NULL when clear */
  clear_since  DATETIME     NULL,   /* first clear sample while an alert is still open (auto-resolve timer) */
  last_val     DOUBLE       NULL,
  last_eval_at DATETIME     NULL,
  PRIMARY KEY (agent_id, rule_key),
  CONSTRAINT fk_astate_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* 3) ALERT_DELIVERIES — the notification outbox. Detection (real-time, in metrics_snapshot.php)
      only ENQUEUES a row here; the every-1-minute cron/alerts_dispatch.php sends it, so a slow or
      failing mail server never touches the metrics hot path. Retries with backoff via next_try_at;
      `channel` is email-only in this step (webhook is the documented fast-follow). */
CREATE TABLE IF NOT EXISTS alert_deliveries (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  alert_id    BIGINT UNSIGNED NOT NULL,
  event       ENUM('open','ack','resolve','escalate') NOT NULL DEFAULT 'open',
  channel     ENUM('email')  NOT NULL DEFAULT 'email',
  target      VARCHAR(255)   NOT NULL DEFAULT '',
  status      ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts    INT UNSIGNED   NOT NULL DEFAULT 0,
  last_error  VARCHAR(255)   NOT NULL DEFAULT '',
  created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  next_try_at DATETIME       NULL,
  sent_at     DATETIME       NULL,
  PRIMARY KEY (id),
  KEY idx_adeliv_pending (status, next_try_at),
  KEY idx_adeliv_alert (alert_id),
  CONSTRAINT fk_adeliv_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
