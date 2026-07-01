/*
  Milepost — Phase 3 increment 2a: on-demand patch scan + human-approved install job types.
  (Follows 2026-07-02_phase3_patch.sql. Additive; installs are still human-gated per device — no
   ring automation yet. Inert until config.php patch.enabled + a 1.3.0+ agent that handles them.)

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.

  MySQL 8: a single MODIFY that APPENDS two values to the END of jobs.job_type — appending ENUM
  values at the end is an instant, in-place metadata change and leaves existing rows untouched.
  Keep the existing values in their current order and add the new ones last.
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE jobs
  MODIFY job_type ENUM('powershell','cmd','restart','message','rustdesk_refresh','patch_scan','patch_install')
    NOT NULL DEFAULT 'powershell';
