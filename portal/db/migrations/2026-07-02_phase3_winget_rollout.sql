/*
  Milepost — Phase 3: fold winget (third-party app) upgrades into the ring rollout engine.
  Additive. A rollout can now, per target, ALSO auto-upgrade winget apps (gated identically to WU:
  ring-by-ring, inside the maintenance window, online, auto-halt/auto-advance). Opt-in per patch policy
  (patch_settings.winget.auto_upgrade — server-only, default OFF). Inert until a rollout runs with a
  policy that enables it + a 1.5.0+ agent (which already handles winget_install jobs).

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.

  Adds a parallel winget track to patch_rollout_targets (the WU track's kb_list/install_job_id stay):
    app_list       — the winget package Ids this target was told to upgrade
    winget_job_id  — the winget_install job created for it
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE patch_rollout_targets
  ADD COLUMN app_list      VARCHAR(1000) NOT NULL DEFAULT '' AFTER kb_list,
  ADD COLUMN winget_job_id INT UNSIGNED  NULL             AFTER install_job_id;
