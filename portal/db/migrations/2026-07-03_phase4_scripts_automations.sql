/*
  Milepost — Phase 4 (Automation), increment 3: event-driven automations + self-healing.
  An automation = trigger (a matching OPEN alert: rule substring + severity + agent scope) -> action
  (run a saved script on the alerting device), with guardrails (per-agent cooldown + per-automation
  daily cap). A 1-min CLI cron (cron/automation_run.php) evaluates open alerts against automations.
  Master kill-switch config.php automation.enabled (default off) — self-healing runs scripts, so it
  stays OFF until an admin turns it on.

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.
  Then add a cPanel cron: * * * * * /usr/local/bin/php /home/qygiabte/public_html/8westit/cron/automation_run.php

  NAMED `scripts_automations` so it sorts AFTER `_scripts.sql` (the FK parent) — filename sort order
  must respect FK dependencies (a lesson from the schedules migration). FK names fk_autom_* / fk_autorun_*.
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS automations (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(160) NOT NULL DEFAULT '',
  match_rule     VARCHAR(80)  NOT NULL DEFAULT '',   -- substring on alert.rule_key ('' = any)
  match_severity ENUM('any','warning','critical') NOT NULL DEFAULT 'any',
  scope_type     ENUM('global','client','site','group','device') NOT NULL DEFAULT 'global',
  scope_id       INT UNSIGNED NULL,
  script_id      INT UNSIGNED NOT NULL,              -- action: run this script on the alerting device
  cooldown_min   INT UNSIGNED NOT NULL DEFAULT 60,   -- per-agent: don't re-run within N min
  max_per_day    INT UNSIGNED NOT NULL DEFAULT 10,   -- per-automation daily cap (0 = unlimited)
  is_enabled     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_autom_script FOREIGN KEY (script_id)  REFERENCES scripts(id) ON DELETE CASCADE,
  CONSTRAINT fk_autom_user   FOREIGN KEY (created_by)  REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS automation_runs (
  id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  automation_id INT UNSIGNED    NOT NULL,
  alert_id      BIGINT UNSIGNED NOT NULL,            -- alerts.id is BIGINT
  agent_id      INT UNSIGNED    NOT NULL,
  job_id        INT UNSIGNED    NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_autorun (automation_id, alert_id),   -- fire at most once per (automation, alert)
  KEY idx_autorun_cooldown (automation_id, agent_id, created_at),
  CONSTRAINT fk_autorun_autom FOREIGN KEY (automation_id) REFERENCES automations(id) ON DELETE CASCADE,
  CONSTRAINT fk_autorun_agent FOREIGN KEY (agent_id)      REFERENCES agents(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
