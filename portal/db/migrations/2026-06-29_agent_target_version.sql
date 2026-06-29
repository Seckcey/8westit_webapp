-- 8 West IT RMM — migration: agent auto-update target version (per-device canary push)
--
-- HOW TO RUN: cPanel > phpMyAdmin > Import (upload this file) — or paste into the SQL tab.
-- Run ONCE on the live database BEFORE deploying the PHP that references target_version
-- (require_agent()'s SELECT * and resolve_agent_update() both read this column).
--
-- MySQL 8 (HostGator) has NO "ADD COLUMN IF NOT EXISTS", so this is a plain ALTER.
-- If re-run it reports "Duplicate column name 'target_version'" — that means the migration
-- already applied: remove the ADD COLUMN line and re-run, or simply skip it.
--
-- Everything here is ADDITIVE and backward-compatible: the column is nullable, existing rows
-- are untouched (NULL = no per-device target), and old code reads none of it. No FK is added,
-- so there is no constraint-name-uniqueness concern. MySQL-8 + MariaDB compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE agents
  ADD COLUMN target_version VARCHAR(32) NULL AFTER agent_version;
