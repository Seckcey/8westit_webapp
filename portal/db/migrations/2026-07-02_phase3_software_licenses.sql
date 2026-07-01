/*
  Milepost — Phase 3: software license tracking. A small manual registry of the paid software licenses
  8 West manages; the portal auto-counts "seats used" by matching each license's `match_name` against
  the installed-software inventory the agent already collects (no agent change).

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.

  FK name fk_swlic_user (prefixed + unique per DB, per the MySQL-8 FK-name rule).
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS software_licenses (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product     VARCHAR(160) NOT NULL,
  vendor      VARCHAR(120) NOT NULL DEFAULT '',
  match_name  VARCHAR(160) NOT NULL DEFAULT '',   -- substring auto-matched against installed software
  seats       INT          NOT NULL DEFAULT 0,    -- 0 = untracked / unlimited
  license_key VARCHAR(255) NOT NULL DEFAULT '',
  expires_at  DATE         NULL,
  notes       VARCHAR(500) NOT NULL DEFAULT '',
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_swlic_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
