<?php
/** JSON: time-series metric history for one agent (session-authed; drawn by app.js as a graph).
 *
 * Returns two things:
 *   pct[]    — the 0-100% family for the line chart: cpu, mem, and EVERY disk volume (disk_pct,
 *              one entry per instance). Each carries downsampled points so the payload stays small.
 *   latest[] — the most recent value of the non-percentage gauges (network throughput, SMART disk
 *              health, temperature) for a compact readout. These don't share the % axis, so they
 *              are surfaced as current values rather than charted (richer views come with the NOC
 *              dashboard step).
 *
 * Raw samples are downsampled on the fly into a range-appropriate number of buckets, so the chart
 * is readable and the payload bounded regardless of sample density. Every supported range fits
 * inside the raw retention window (see cron/metrics_rollup.php); longer ranges will read the
 * rollup tables in a later step.
 *
 * Single-tenant portal: any logged-in user may view any non-archived agent (same model as
 * agent.php / agent_live.php). 404 (not 403) for missing/archived ids — don't leak existence.
 *
 * GET  metrics_history.php?id=<agent>&range=<6h|24h|7d>
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
if (!current_user()) json_err('Unauthorized', 401);

$id = (int)($_GET['id'] ?? 0);

$st = db()->prepare('SELECT id FROM agents WHERE id=? AND is_archived=0');
$st->execute([$id]);
if (!$st->fetch()) json_err('Not found', 404);

// Whitelisted ranges -> [window seconds, bucket seconds] (~72-96 points each).
$ranges = [
    '6h'  => [6 * 3600,   300],
    '24h' => [24 * 3600,  900],
    '7d'  => [7 * 86400,  7200],
];
$range = (string)($_GET['range'] ?? '24h');
if (!isset($ranges[$range])) $range = '24h';
[$windowSecs, $bucketSecs] = $ranges[$range];

$fromTs  = time() - $windowSecs;
$fromUtc = gmdate('Y-m-d H:i:s', $fromTs);

// ── Percentage family for the chart: cpu, mem, and all disk volumes. Bucketed avg per
//    (metric_key, instance). FLOOR(UNIX_TIMESTAMP/b)*b is epoch math (timezone-agnostic).
$pctStmt = db()->prepare(
    'SELECT metric_key, instance,
            FLOOR(UNIX_TIMESTAMP(sampled_at)/?)*? AS bucket_ts,
            ROUND(AVG(value),1)                  AS v
       FROM agent_metric_samples
      WHERE agent_id = ? AND sampled_at >= ? AND value IS NOT NULL
        AND metric_key IN (?,?,?)
   GROUP BY metric_key, instance, bucket_ts
   ORDER BY metric_key, instance, bucket_ts'
);
$pctStmt->execute([$bucketSecs, $bucketSecs, $id, $fromUtc, 'cpu', 'mem', 'disk_pct']);

$grouped = []; // "key|instance" => ['key','instance','points'=>[]]
foreach ($pctStmt->fetchAll() as $row) {
    $key  = $row['metric_key'];
    $inst = (string)$row['instance'];
    $gk   = $key . '|' . $inst;
    if (!isset($grouped[$gk])) $grouped[$gk] = ['key' => $key, 'instance' => $inst, 'points' => []];
    $grouped[$gk]['points'][] = [(int)$row['bucket_ts'], (float)$row['v']];
}

// Stable, sensible order: CPU, Memory, then disks by drive letter.
$order = ['cpu' => 0, 'mem' => 1, 'disk_pct' => 2];
uasort($grouped, static function ($a, $b) use ($order) {
    $ra = $order[$a['key']] ?? 9;
    $rb = $order[$b['key']] ?? 9;
    if ($ra !== $rb) return $ra <=> $rb;
    return strcmp($a['instance'], $b['instance']);
});

$labels = ['cpu' => 'CPU', 'mem' => 'Memory'];
$pct = [];
foreach ($grouped as $g) {
    $label = $g['key'] === 'disk_pct'
        ? 'Disk ' . ($g['instance'] !== '' ? $g['instance'] : '?')
        : ($labels[$g['key']] ?? $g['key']);
    $pct[] = ['key' => $g['key'], 'instance' => $g['instance'], 'label' => $label, 'points' => $g['points']];
}

// ── Latest non-% gauges (network / SMART health / temperature): newest value per (key,instance)
//    within the range. Join the per-group MAX(sampled_at) back to the row (PK guarantees one row).
$latStmt = db()->prepare(
    'SELECT s.metric_key, s.instance, s.value, UNIX_TIMESTAMP(s.sampled_at) AS at
       FROM agent_metric_samples s
       JOIN (SELECT metric_key, instance, MAX(sampled_at) AS ms
               FROM agent_metric_samples
              WHERE agent_id = ? AND sampled_at >= ? AND metric_key IN (?,?,?,?,?)
           GROUP BY metric_key, instance) m
         ON m.metric_key = s.metric_key AND m.instance = s.instance AND m.ms = s.sampled_at
      WHERE s.agent_id = ?
   ORDER BY s.metric_key, s.instance'
);
$latStmt->execute([$id, $fromUtc, 'net_up_kbps', 'net_down_kbps', 'disk_health', 'temp_c', 'disk_free_gb', $id]);

$latest = [];
foreach ($latStmt->fetchAll() as $row) {
    $latest[] = [
        'key'      => $row['metric_key'],
        'instance' => (string)$row['instance'],
        'value'    => (float)$row['value'],
        'at'       => (int)$row['at'],
    ];
}

json_out([
    'ok'          => true,
    'id'          => $id,
    'range'       => $range,
    'bucket_secs' => $bucketSecs,
    'from'        => $fromTs,
    'pct'         => $pct,
    'latest'      => $latest,
]);
