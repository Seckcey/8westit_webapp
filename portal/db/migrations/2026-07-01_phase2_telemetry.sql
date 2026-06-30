/*
  Milepost — Phase 2 (Step 1): telemetry backbone — time-series metric history + rollups.
  (Roadmap Phase 2: "performance graphs from the telemetry store"; the foundation every
   later Phase 2 capability — thresholds, baselines, anomaly detection — reads from.)

  HOW TO RUN: cPanel > phpMyAdmin > select your DB (qygiabte_8westit_webapp) > Import tab >
  upload this file > Go.  Block comments are used throughout, so this also runs correctly if you
  paste it into the SQL tab.

  Everything here is ADDITIVE: two brand-new tables, no ALTER of existing tables, and the
  existing 60s/real-time code reads NONE of them. "CREATE TABLE IF NOT EXISTS" makes the file
  safe to re-run. FK constraint names are unique per database (fk_ams_*, fk_amr_*) — MySQL 8
  enforces this even though MariaDB does not (the Phase 1 fk_inv_agent collision lesson).

  DESIGN — a TALL (key/instance/value) time-series, not a wide column-per-metric table, so new
  metrics (per-volume disk, SMART, network, services, temperatures, custom) become new
  metric_key / instance values with NO future migration. Step 1 writes three keys:
    cpu      / ''     percent
    mem      / ''     percent
    disk_pct / 'C:'   percent (instance carries the volume; more volumes are additive)
*/

SET NAMES utf8mb4;
SET time_zone = '+00:00';

/* 1) RAW SAMPLES — one row per (agent, metric, instance, sampled minute).
      Written best-effort by /api/svc/metrics_snapshot.php alongside agent_metrics_latest,
      at the same backend-throttled <=1/agent/60s cadence. Pruned to a short window by the
      rollup cron (portal/cron/metrics_rollup.php); long-range history lives in the rollup table.
      The PRIMARY KEY doubles as the series-read index: its leftmost prefix
      (agent_id, metric_key, instance, sampled_at) is exactly the chart query. */
CREATE TABLE IF NOT EXISTS agent_metric_samples (
  agent_id    INT UNSIGNED NOT NULL,
  metric_key  VARCHAR(40)  NOT NULL,
  instance    VARCHAR(64)  NOT NULL DEFAULT '',
  value       DOUBLE       NULL,
  sampled_at  DATETIME     NOT NULL,
  PRIMARY KEY (agent_id, metric_key, instance, sampled_at),
  KEY idx_ams_prune (sampled_at),
  CONSTRAINT fk_ams_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* 2) ROLLUPS — downsampled hourly + daily aggregates for long-range graphs and baselines.
      Filled idempotently by the cron (INSERT ... ON DUPLICATE KEY UPDATE), so a re-run or a
      late run self-corrects. avg_v at the day bucket is sample-weighted (see the cron). */
CREATE TABLE IF NOT EXISTS agent_metric_rollup (
  agent_id    INT UNSIGNED NOT NULL,
  metric_key  VARCHAR(40)  NOT NULL,
  instance    VARCHAR(64)  NOT NULL DEFAULT '',
  bucket      ENUM('hour','day') NOT NULL,
  bucket_at   DATETIME     NOT NULL,
  min_v       DOUBLE       NULL,
  avg_v       DOUBLE       NULL,
  max_v       DOUBLE       NULL,
  samples     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (agent_id, metric_key, instance, bucket, bucket_at),
  KEY idx_amr_prune (bucket, bucket_at),
  CONSTRAINT fk_amr_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
