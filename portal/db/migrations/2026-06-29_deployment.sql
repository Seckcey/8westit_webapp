-- 8 West IT RMM — migration: agent deployment console (sites, tags, key options)
-- Run once on the live database via phpMyAdmin (Import or SQL tab).
-- MariaDB-safe: uses ADD COLUMN IF NOT EXISTS so it's safe to re-run.

ALTER TABLE enrollment_keys
  ADD COLUMN IF NOT EXISTS site            VARCHAR(128) NOT NULL DEFAULT '' AFTER label,
  ADD COLUMN IF NOT EXISTS name_prefix     VARCHAR(32)  NOT NULL DEFAULT '' AFTER site,
  ADD COLUMN IF NOT EXISTS tags            VARCHAR(255) NOT NULL DEFAULT '' AFTER name_prefix,
  ADD COLUMN IF NOT EXISTS bundle_rustdesk TINYINT(1)   NOT NULL DEFAULT 1  AFTER tags,
  ADD COLUMN IF NOT EXISTS expires_at      DATETIME     NULL                AFTER bundle_rustdesk,
  ADD COLUMN IF NOT EXISTS use_count       INT UNSIGNED NOT NULL DEFAULT 0  AFTER expires_at;

ALTER TABLE agents
  ADD COLUMN IF NOT EXISTS site VARCHAR(128) NOT NULL DEFAULT '' AFTER client_id,
  ADD COLUMN IF NOT EXISTS tags VARCHAR(255) NOT NULL DEFAULT '' AFTER site;
