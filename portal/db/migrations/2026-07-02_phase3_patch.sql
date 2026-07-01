/*
  Milepost — Phase 3 (patch management) MVP: patch VISIBILITY / compliance reporting.
  Scan-and-report only (no installs yet). Additive + backward compatible; inert until config.php
  `patch.enabled` is flipped on and an agent that supports scanning reports in.

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.
  Block comments throughout, so a phpMyAdmin SQL-tab paste is also safe.

  MySQL 8 notes: `CREATE TABLE IF NOT EXISTS` only (safe to re-run). FK name fk_aps_agent is unique
  per database. The MVP agent scans on startup + on a timer and reports, so on-demand "Scan now"
  (a 'patch_scan' job type) is deferred to the install increment.
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

/* Latest patch/compliance snapshot per agent (one row per agent, like agent_metrics_latest).
      Written by /api/patch_report.php when an agent reports a Windows Update scan; read by the
      device Patch panel + the fleet compliance column. `pending_json` holds the list of pending
      updates ([{kb,title,classification,severity,reboot_required}, ...]) for the detail view. */
CREATE TABLE IF NOT EXISTS agent_patch_status (
  agent_id       INT UNSIGNED NOT NULL,
  last_scan_at   DATETIME     NOT NULL,
  compliance_pct DECIMAL(5,2) NULL,
  pending_count  INT UNSIGNED NOT NULL DEFAULT 0,
  critical_count INT UNSIGNED NOT NULL DEFAULT 0,
  reboot_pending TINYINT(1)   NOT NULL DEFAULT 0,
  pending_json   JSON         NULL,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (agent_id),
  CONSTRAINT fk_aps_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
