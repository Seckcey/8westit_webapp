/*
  Milepost — Phase 4 (Automation & Self-Healing), increment 1: the Script Library.
  A reusable, versioned store of scripts an admin can run on a device (via the existing jobs framework:
  a saved script becomes a powershell/cmd job). Foundation for scheduled + event-driven automation later.

  HOW TO RUN: cPanel > phpMyAdmin > select the DB (qygiabte_8westit_webapp) > Import > this file > Go.

  FK name fk_scripts_user (prefixed + unique per DB, per the MySQL-8 FK-name rule).
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS scripts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(160) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  language    ENUM('powershell','cmd') NOT NULL DEFAULT 'powershell',
  body        MEDIUMTEXT   NOT NULL,
  version     INT UNSIGNED NOT NULL DEFAULT 1,     -- bumps on each save (simple change counter)
  run_count   INT UNSIGNED NOT NULL DEFAULT 0,     -- how many times it has been dispatched
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_scripts_name (name),
  CONSTRAINT fk_scripts_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
