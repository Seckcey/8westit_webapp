<?php
/**
 * Milepost telemetry maintenance — roll raw samples up into hourly + daily aggregates and
 * prune each tier to its retention window. Keeps agent_metric_samples bounded so the time-series
 * store stays small on shared hosting, while preserving long-range trend data in the rollups.
 *
 * CLI-ONLY (refuses any web request) — it takes no auth and must never be reachable over HTTP.
 *
 * cPanel cron (every 15 minutes is plenty):
 *    /usr/local/bin/php /home/<acct>/public_html/8westit/cron/metrics_rollup.php >/dev/null 2>&1
 *
 * Idempotent: re-running (or a late run after an outage) re-aggregates the recent window via
 * INSERT ... ON DUPLICATE KEY UPDATE, so buckets self-correct. Retention is tunable via the
 * optional 'telemetry' config block; the defaults below apply when it is absent.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: this maintenance script is CLI-only.\n");
}

require_once __DIR__ . '/../lib/bootstrap.php';

$t        = cfg('telemetry', []);
$rawDays  = max(2,  (int)($t['raw_days']  ?? 14));   // raw 1/min samples
$hourDays = max(7,  (int)($t['hour_days'] ?? 90));   // hourly aggregates
$dayDays  = max(30, (int)($t['day_days']  ?? 730));  // daily aggregates (~2y)

// Retention tiers MUST widen (raw < hour < day): pruning deletes each tier independently, so an
// inverted config would delete an aggregate before the tier that feeds it has been built —
// silent, permanent loss of long-range trend data. Refuse to run rather than corrupt the store.
if ($rawDays >= $hourDays || $hourDays >= $dayDays) {
    fwrite(STDERR, "metrics_rollup: invalid retention — need raw_days < hour_days < day_days "
        . "(got raw=$rawDays hour=$hourDays day=$dayDays)\n");
    exit(1);
}

$pdo = db();
$now = time();
$line = static function (string $s): void { fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "Z] $s\n"); };

/* ── 1) RAW -> HOURLY. Re-aggregate the recent window (covers the just-closed hour and the
      in-progress one; the latter finalizes on the next run). The 8-hour window tolerates cron
      delays/outages up to ~2 hours before a closed hour could be missed; ON DUPLICATE KEY makes
      a late re-run self-correct. FROM_UNIXTIME under the +00:00 session pin yields a UTC bucket
      start, matching how rows are stored/read elsewhere. */
$hourFrom = gmdate('Y-m-d H:i:s', $now - 8 * 3600);
$stmt = $pdo->prepare(
    'INSERT INTO agent_metric_rollup
        (agent_id, metric_key, instance, bucket, bucket_at, min_v, avg_v, max_v, samples)
     SELECT agent_id, metric_key, instance, \'hour\',
            FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(sampled_at)/3600)*3600) AS bucket_at,
            MIN(value), AVG(value), MAX(value), COUNT(value)
       FROM agent_metric_samples
      WHERE sampled_at >= ? AND value IS NOT NULL
      GROUP BY agent_id, metric_key, instance, bucket_at
     ON DUPLICATE KEY UPDATE
        min_v=VALUES(min_v), avg_v=VALUES(avg_v), max_v=VALUES(max_v), samples=VALUES(samples)'
);
$stmt->execute([$hourFrom]);
$line('raw->hour: ' . $stmt->rowCount() . ' bucket writes (window since ' . $hourFrom . 'Z)');

/* ── 2) HOURLY -> DAILY. Sample-weighted daily average = SUM(avg_v*samples)/SUM(samples).
      The SELECT is wrapped in a derived table so MySQL materializes it before the upsert
      (insert + read of the same table otherwise risks error 1093). */
$dayFrom = gmdate('Y-m-d H:i:s', $now - 2 * 86400);
$stmt = $pdo->prepare(
    'INSERT INTO agent_metric_rollup
        (agent_id, metric_key, instance, bucket, bucket_at, min_v, avg_v, max_v, samples)
     SELECT agent_id, metric_key, instance, \'day\', day_at, mn, av, mx, sm FROM (
        SELECT agent_id, metric_key, instance,
               DATE(bucket_at)                          AS day_at,
               MIN(min_v)                               AS mn,
               SUM(avg_v*samples)/NULLIF(SUM(samples),0) AS av,
               MAX(max_v)                               AS mx,
               SUM(samples)                             AS sm
          FROM agent_metric_rollup
         WHERE bucket=\'hour\' AND bucket_at >= ?
         GROUP BY agent_id, metric_key, instance, day_at
     ) t
     ON DUPLICATE KEY UPDATE
        min_v=VALUES(min_v), avg_v=VALUES(avg_v), max_v=VALUES(max_v), samples=VALUES(samples)'
);
$stmt->execute([$dayFrom]);
$line('hour->day: ' . $stmt->rowCount() . ' bucket writes (window since ' . $dayFrom . 'Z)');

/* ── 3) PRUNE each tier to its retention window. Raw cutoff (>=2 days) is far newer than the
      6h rollup window, so nothing is pruned before it has been aggregated. */
$rawCut  = gmdate('Y-m-d H:i:s', $now - $rawDays  * 86400);
$hourCut = gmdate('Y-m-d H:i:s', $now - $hourDays * 86400);
$dayCut  = gmdate('Y-m-d H:i:s', $now - $dayDays  * 86400);

$d1 = $pdo->prepare('DELETE FROM agent_metric_samples WHERE sampled_at < ?');
$d1->execute([$rawCut]);
$d2 = $pdo->prepare('DELETE FROM agent_metric_rollup WHERE bucket=\'hour\' AND bucket_at < ?');
$d2->execute([$hourCut]);
$d3 = $pdo->prepare('DELETE FROM agent_metric_rollup WHERE bucket=\'day\' AND bucket_at < ?');
$d3->execute([$dayCut]);
$line("prune: raw={$d1->rowCount()} (<{$rawCut}Z), hour={$d2->rowCount()} (<{$hourCut}Z), day={$d3->rowCount()} (<{$dayCut}Z)");

$line('done.');
