<?php
/**
 * POST /api/svc/metrics_snapshot.php   (service-signed; spec §3.5)
 *
 * The ONLY path live presence/metrics reach MySQL. Batched across agents and throttled by the
 * backend to at most once/agent/60s, so WS-connected agents write LESS than 60s polling.
 *
 * Per online item: bump agents.last_seen_at + presence='online', upsert agent_metrics_latest.
 * Per offline item: set presence='offline' WITHOUT bumping last_seen_at.
 *
 * Request:  { ts, items:[ { agent_id, online, local_ip?, last_user?, cpu?, mem?, disk_c?,
 *             uptime_secs? }, ... ] }
 * Response: { ok:true, updated:<count> }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../lib/svc_auth.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);
require_service();

$in    = read_json_body();
$items = isset($in['items']) && is_array($in['items']) ? $in['items'] : [];

$pdo = db();
$updOnline  = $pdo->prepare(
    'UPDATE agents SET last_seen_at=NOW(), local_ip=?, last_user=?, presence=\'online\' WHERE id=?'
);
$updOffline = $pdo->prepare('UPDATE agents SET presence=\'offline\' WHERE id=?');
$upsert = $pdo->prepare(
    'INSERT INTO agent_metrics_latest (agent_id,cpu,mem,disk_c,uptime_secs,logged_user,sampled_at)
     VALUES (?,?,?,?,?,?,NOW())
     ON DUPLICATE KEY UPDATE cpu=VALUES(cpu),mem=VALUES(mem),disk_c=VALUES(disk_c),
       uptime_secs=VALUES(uptime_secs),logged_user=VALUES(logged_user),sampled_at=NOW()'
);

$updated = 0;
$skipped = 0;
$pdo->beginTransaction();
foreach ($items as $it) {
    if (!is_array($it)) continue;
    $aid = (int)($it['agent_id'] ?? 0);
    if ($aid <= 0) continue;
    $online = !empty($it['online']);

    // Per-item guard: a single stale/deleted agent_id (FK violation on the metrics
    // upsert) must NOT roll back the whole batch and 500 — it would discard every
    // other agent's presence/metrics. InnoDB rolls back only the failing statement,
    // so we skip just this item and keep going.
    try {
        if ($online) {
            $localIp  = mb_substr((string)($it['local_ip'] ?? ''), 0, 45);
            $lastUser = mb_substr((string)($it['last_user'] ?? ''), 0, 128);
            $updOnline->execute([$localIp, $lastUser, $aid]);

            $cpu    = isset($it['cpu'])    ? (float)$it['cpu']    : null;
            $mem    = isset($it['mem'])    ? (float)$it['mem']    : null;
            $diskC  = isset($it['disk_c']) ? (float)$it['disk_c'] : null;
            $uptime = isset($it['uptime_secs']) ? (int)$it['uptime_secs'] : null;
            $logged = mb_substr((string)($it['logged_user'] ?? ($it['last_user'] ?? '')), 0, 128);
            $upsert->execute([$aid, $cpu, $mem, $diskC, $uptime, $logged]);
        } else {
            $updOffline->execute([$aid]);
        }
        $updated++;
    } catch (Throwable $e) {
        $skipped++;
    }
}
try {
    $pdo->commit();
} catch (Throwable $e) {
    // Only a catastrophic failure (lost connection, deadlock) reaches here.
    try { $pdo->rollBack(); } catch (Throwable $e2) { /* nothing to roll back */ }
    json_err('Snapshot failed', 500);
}

json_out(['ok' => true, 'updated' => $updated, 'skipped' => $skipped]);
