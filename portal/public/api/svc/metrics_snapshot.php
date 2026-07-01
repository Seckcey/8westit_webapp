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
require_once __DIR__ . '/../../../lib/alerts.php';
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
// Time-series rows accumulated during the loop (NO DB I/O here) and written best-effort AFTER
// the presence/latest commit, so a history failure can never roll back the critical path.
$histRows  = [];
$sampledAt = gmdate('Y-m-d H:i:s'); // one UTC stamp for the whole batch (db() pins +00:00)
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

            // Queue the core numeric samples for the time-series store (back-compat: works for
            // pre-1.1.8 agents that only send these flat fields).
            if ($cpu   !== null) $histRows[] = [$aid, 'cpu',      '',   $cpu];
            if ($mem   !== null) $histRows[] = [$aid, 'mem',      '',   $mem];
            if ($diskC !== null) $histRows[] = [$aid, 'disk_pct', 'C:', $diskC];

            // Phase 2 wider metric set: the generic `series` channel ([{k,i,v},...]) carries
            // per-volume disk, network, SMART, temperature, etc. Validate every tuple — the keys
            // and instances become metric_key/instance in the store and (escaped) labels in the UI,
            // so enforce a strict charset, finite numeric values, and a per-agent cap.
            if (isset($it['series']) && is_array($it['series'])) {
                $n = 0;
                foreach ($it['series'] as $sp) {
                    if ($n >= 200) break;                       // cap rows per agent per snapshot
                    if (!is_array($sp)) continue;
                    $k = (string)($sp['k'] ?? '');
                    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k)) continue;
                    if (!isset($sp['v']) || !is_numeric($sp['v'])) continue;
                    $v = (float)$sp['v'];
                    if (!is_finite($v)) continue;
                    $inst = preg_replace('/[^A-Za-z0-9 :._\-]/', '', (string)($sp['i'] ?? ''));
                    $inst = mb_substr($inst, 0, 64);
                    $histRows[] = [$aid, $k, $inst, $v];
                    $n++;
                }
            }
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

// ── Time-series history (best-effort, post-commit). A failure here is swallowed: presence and
//    agent_metrics_latest are already durable, and Step 1's chart is a non-critical consumer.
//    INSERT IGNORE absorbs the rare PK collision (same agent/key/instance within one second).
$historied = 0;
if ($histRows) {
    try {
        foreach (array_chunk($histRows, 400) as $chunk) {
            $ph  = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
            $ins = $pdo->prepare(
                'INSERT IGNORE INTO agent_metric_samples (agent_id, metric_key, instance, value, sampled_at)
                 VALUES ' . $ph
            );
            $args = [];
            foreach ($chunk as $r) { $args[] = $r[0]; $args[] = $r[1]; $args[] = $r[2]; $args[] = $r[3]; $args[] = $sampledAt; }
            $ins->execute($args);
            $historied += count($chunk);
        }
    } catch (Throwable $e) {
        // Best-effort only — never fail the snapshot over history. Log so silent telemetry-write
        // loss is visible to ops (the presence/agent_metrics_latest critical path already committed).
        error_log('metrics_snapshot: history insert failed after commit: ' . $e->getMessage());
        $historied = 0;
    }
}

// ── Smart alerting (Phase 2 Step 3): evaluate the just-arrived samples against each device's
//    effective thresholds. Best-effort and fully decoupled — it runs AFTER the metrics commit and
//    history write, only opens/resolves alert rows + enqueues notifications (the every-minute cron
//    sends them), and a failure here never affects presence, metrics, or this response.
if (alerts_enabled() && $histRows) {
    try {
        $byAgent = [];
        foreach ($histRows as $r) { $byAgent[$r[0]][] = [$r[1], $r[2], $r[3]]; }
        foreach ($byAgent as $aid => $vals) {
            alerts_evaluate($pdo, (int)$aid, $vals, $sampledAt);
        }
    } catch (Throwable $e) {
        error_log('metrics_snapshot: alert evaluation failed after commit: ' . $e->getMessage());
    }
}

json_out(['ok' => true, 'updated' => $updated, 'skipped' => $skipped, 'history' => $historied]);
