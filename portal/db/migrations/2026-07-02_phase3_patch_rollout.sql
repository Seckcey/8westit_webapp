/*
  Milepost — Phase 3 increment 2b: automated ring rollout (orchestration tables).
  (Follows 2026-07-02_phase3_patch_install.sql. PORTAL-ONLY — no agent change: the rollout cron
   drives the already-shipped 2a patch_install job + maintenance-window gate + patch policy.)

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.

  MySQL 8: CREATE TABLE IF NOT EXISTS only (safe to re-run). FK names are globally unique
  (fk_prollout_*, fk_prt_*). Everything is inert until config.php patch.enabled is on and a rollout
  is created + started in the Patch UI. Ring membership + auto-approve + reboot policy live in the
  policy engine under a `patch_settings` doc key (like `thresholds`); no column for them here.
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

/* 1) ROLLOUTS — one orchestration row per patch campaign. Order of rings is JSON (e.g.
      ["canary","early","broad"]); the cron installs the current ring's matching devices, bakes for
      advance_after_min, then advances if the failure rate is under max_failure_pct, else halts. */
CREATE TABLE IF NOT EXISTS patch_rollouts (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(128) NOT NULL DEFAULT '',
  scope_type        ENUM('global','client','site','group','device') NOT NULL DEFAULT 'global',
  scope_id          INT UNSIGNED NULL,
  window_id         INT UNSIGNED NULL,                 /* install only inside this maintenance window */
  ring_order        JSON         NOT NULL,             /* ordered ring names, e.g. ["canary","broad"] */
  current_ring      VARCHAR(16)  NOT NULL DEFAULT '',
  status            ENUM('draft','running','paused','halted','completed') NOT NULL DEFAULT 'draft',
  advance_after_min INT UNSIGNED NOT NULL DEFAULT 1440,  /* bake time per ring before auto-advance (24h) */
  max_failure_pct   DECIMAL(5,2) NOT NULL DEFAULT 20.00, /* auto-halt if a ring's failure rate >= this */
  ring_started_at   DATETIME     NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_prollout_status (status),
  CONSTRAINT fk_prollout_window FOREIGN KEY (window_id) REFERENCES maintenance_windows(id) ON DELETE SET NULL,
  CONSTRAINT fk_prollout_user   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* 2) ROLLOUT TARGETS — per (rollout, agent) state as the cron drives each device through the ring:
      pending -> installing -> installed -> verified | failed. Links the install/reboot jobs for
      forensics. UNIQUE(rollout_id,agent_id) so a device appears once per rollout. */
CREATE TABLE IF NOT EXISTS patch_rollout_targets (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rollout_id     INT UNSIGNED NOT NULL,
  agent_id       INT UNSIGNED NOT NULL,
  ring           VARCHAR(16)  NOT NULL DEFAULT '',
  state          ENUM('pending','installing','installed','verified','failed') NOT NULL DEFAULT 'pending',
  kb_list        VARCHAR(1000) NOT NULL DEFAULT '',
  install_job_id INT UNSIGNED NULL,
  reboot_job_id  INT UNSIGNED NULL,
  attempts       INT UNSIGNED NOT NULL DEFAULT 0,
  last_error     VARCHAR(255) NOT NULL DEFAULT '',
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prt (rollout_id, agent_id),
  KEY idx_prt_state (rollout_id, state),
  CONSTRAINT fk_prt_rollout FOREIGN KEY (rollout_id) REFERENCES patch_rollouts(id) ON DELETE CASCADE,
  CONSTRAINT fk_prt_agent   FOREIGN KEY (agent_id)   REFERENCES agents(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
