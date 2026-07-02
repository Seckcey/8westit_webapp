/*
  Milepost — Phase 4 (Automation), increment 2: scheduled scripts.
  Run a saved script on a schedule (interval / daily / weekly) against a scope (global/client/site/
  group/device). A 1-min CLI cron (cron/script_dispatch.php) fires due schedules → enqueues one job per
  in-scope agent (reuses increment 1's script_run_on_agent + the agent job pipeline; no agent change).

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.
  Then add a cPanel cron: * * * * * /usr/local/bin/php /home/qygiabte/public_html/8westit/cron/script_dispatch.php

  FK names fk_sched_script / fk_sched_user (prefixed + unique per DB, per the MySQL-8 FK-name rule).
  Times are UTC (at_time), matching every other DATETIME in this app.
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS script_schedules (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  script_id    INT UNSIGNED NOT NULL,
  name         VARCHAR(160) NOT NULL DEFAULT '',
  scope_type   ENUM('global','client','site','group','device') NOT NULL DEFAULT 'global',
  scope_id     INT UNSIGNED NULL,
  recurrence   ENUM('interval','daily','weekly') NOT NULL DEFAULT 'daily',
  at_time      TIME         NULL,        -- daily/weekly run time (UTC)
  dow          TINYINT      NULL,        -- weekly: 0=Sun … 6=Sat (matches PHP gmdate('w'))
  interval_min INT UNSIGNED NULL,        -- interval: run every N minutes
  is_enabled   TINYINT(1)   NOT NULL DEFAULT 1,
  last_run_at  DATETIME     NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sched_enabled (is_enabled),
  CONSTRAINT fk_sched_script FOREIGN KEY (script_id) REFERENCES scripts(id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_user   FOREIGN KEY (created_by) REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
