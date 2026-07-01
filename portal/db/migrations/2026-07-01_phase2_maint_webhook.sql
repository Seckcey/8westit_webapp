/*
  Milepost — Phase 2 (Step 3 fast-follow): maintenance windows + webhook delivery channel.
  (Follows 2026-07-01_phase2_alerting.sql. Additive + backward compatible.)

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.
  Block comments throughout, so a phpMyAdmin SQL-tab paste is also safe.

  MySQL 8 notes: pure CREATE TABLE IF NOT EXISTS + a single MODIFY that only APPENDS an ENUM value
  ('webhook') to the END of alert_deliveries.channel — an instant, in-place metadata change in 8.0
  that leaves existing 'email' rows untouched. FK name fk_mw_user is unique per database.
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

/* 1) MAINTENANCE WINDOWS — suppress alerting for matching devices during planned work.
      Scope is inherited the same way as thresholds (global -> client -> site -> group -> device):
      a device is "in maintenance" if ANY enabled window whose scope matches its chain is active now.
      Times are UTC. recurrence: 'none' = one-off [starts_at, ends_at]; 'daily'/'weekly' repeat the
      same UTC time-of-day (and weekday, for weekly), for the window's duration, on/after starts_at. */
CREATE TABLE IF NOT EXISTS maintenance_windows (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(128) NOT NULL DEFAULT '',
  scope_type  ENUM('global','client','site','group','device') NOT NULL DEFAULT 'global',
  scope_id    INT UNSIGNED NULL,
  starts_at   DATETIME     NOT NULL,
  ends_at     DATETIME     NOT NULL,
  recurrence  ENUM('none','daily','weekly') NOT NULL DEFAULT 'none',
  is_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mw_scope (scope_type, scope_id),
  KEY idx_mw_active (is_enabled, starts_at, ends_at),
  CONSTRAINT fk_mw_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* 2) WEBHOOK delivery channel. Append 'webhook' to the alert_deliveries.channel ENUM (kept 'email'
      as the default). Adding a value at the END is an INSTANT operation in MySQL 8.0 and does not
      rewrite the table or alter existing rows. */
ALTER TABLE alert_deliveries
  MODIFY channel ENUM('email','webhook') NOT NULL DEFAULT 'email';
