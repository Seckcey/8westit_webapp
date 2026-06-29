-- 8 West IT RMM — migration: agent deployment console (sites, tags, key options)
-- Run ONCE on the live database via phpMyAdmin (SQL tab: paste; or Import: upload this file).
-- Plain ALTERs for MySQL compatibility (HostGator runs MySQL, which does NOT support
-- "ADD COLUMN IF NOT EXISTS"). If a column already exists you'll get "Duplicate column
-- name" — remove that line and re-run.

ALTER TABLE enrollment_keys
  ADD COLUMN site            VARCHAR(128) NOT NULL DEFAULT '' AFTER label,
  ADD COLUMN name_prefix     VARCHAR(32)  NOT NULL DEFAULT '' AFTER site,
  ADD COLUMN tags            VARCHAR(255) NOT NULL DEFAULT '' AFTER name_prefix,
  ADD COLUMN bundle_rustdesk TINYINT(1)   NOT NULL DEFAULT 1  AFTER tags,
  ADD COLUMN expires_at      DATETIME     NULL                AFTER bundle_rustdesk,
  ADD COLUMN use_count       INT UNSIGNED NOT NULL DEFAULT 0  AFTER expires_at;

ALTER TABLE agents
  ADD COLUMN site VARCHAR(128) NOT NULL DEFAULT '' AFTER client_id,
  ADD COLUMN tags VARCHAR(255) NOT NULL DEFAULT '' AFTER site;
