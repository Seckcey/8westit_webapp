/*
  Milepost — Phase 3 increment 3: patch ROLLBACK job type (best-effort per-KB uninstall).
  (Follows 2026-07-02_phase3_patch_install.sql. Additive; rollback is human-gated + admin-only, and
   is best-effort — servicing-stack / feature / many cumulative updates cannot be uninstalled and are
   reported honestly per-KB. Inert until a 1.4.0+ agent that handles patch_rollback jobs.)

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.

  MySQL 8: a single MODIFY that APPENDS 'patch_rollback' to the END of jobs.job_type — appending an
  ENUM value at the end is an instant, in-place metadata change and leaves existing rows untouched.
  Keep the existing values in their current order and add the new one last.
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE jobs
  MODIFY job_type ENUM('powershell','cmd','restart','message','rustdesk_refresh','patch_scan','patch_install','patch_rollback')
    NOT NULL DEFAULT 'powershell';
