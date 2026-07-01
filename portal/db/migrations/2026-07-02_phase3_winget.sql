/*
  Milepost — Phase 3: third-party application patching via winget (scan/report + on-demand install).
  Additive. Inert until config.php winget.enabled + a 1.5.0+ agent that scans/handles winget jobs.

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.

  Two changes:
    1) agent_app_updates — one current row per agent: winget's list of available app upgrades.
       FK name fk_aau_agent (prefixed + unique per DB, per the MySQL-8 FK-name rule).
    2) jobs.job_type — APPEND 'winget_scan','winget_install' at the END (instant metadata change;
       keep the existing values in order).
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS agent_app_updates (
  agent_id     INT UNSIGNED NOT NULL,
  last_scan_at DATETIME     NULL,
  update_count INT UNSIGNED NOT NULL DEFAULT 0,
  apps_json    MEDIUMTEXT   NULL,
  PRIMARY KEY (agent_id),
  CONSTRAINT fk_aau_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE jobs
  MODIFY job_type ENUM('powershell','cmd','restart','message','rustdesk_refresh','patch_scan','patch_install','patch_rollback','winget_scan','winget_install')
    NOT NULL DEFAULT 'powershell';
