<?php
/** JSON: live presence + metrics for a single agent (session-authed, polled by app.js).
 *
 * Three-way source resolution, mirroring the agent.php Live card:
 *   realtime  — agent is connected to the RT backend right now (cpu/mem/disk/uptime from presence)
 *   snapshot  — fall back to the durable agent_metrics_latest row (last reported sample)
 *   none      — no snapshot yet; online via the last_seen heuristic, metrics null
 *
 * Degrades cleanly: with rt disabled and an empty agent_metrics_latest this still returns
 * ok:true with null metrics and makes ZERO backend calls (rt_enabled() gates every rt_* call).
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/realtime.php';
enforce_https();
if (!current_user()) json_err('Unauthorized', 401);

$id = (int)($_GET['id'] ?? 0);

// Agent row (only non-archived; 404 otherwise — don't leak existence of archived/missing).
$st = db()->prepare('SELECT id, last_seen_at, heartbeat_secs, last_user FROM agents WHERE id=? AND is_archived=0');
$st->execute([$id]);
$a = $st->fetch();
if (!$a) json_err('Not found', 404);

// Durable snapshot (DECIMAL/BIGINT come back as STRINGS — cast below).
$ms = db()->prepare('SELECT cpu, mem, disk_c, uptime_secs, logged_user, sampled_at FROM agent_metrics_latest WHERE agent_id=?');
$ms->execute([$id]);
$snap = $ms->fetch();

// Live presence (self-guards; returns null when rt disabled / backend down / timeout).
$live = rt_enabled() ? rt_presence_one($id) : null;

if ($live && !empty($live['online'])) {
    $source     = 'realtime';
    $online     = true;
    $cpu        = isset($live['cpu'])    ? (float)$live['cpu']    : null;
    $mem        = isset($live['mem'])    ? (float)$live['mem']    : null;
    $diskC      = isset($live['disk_c']) ? (float)$live['disk_c'] : null;
    $uptime     = isset($live['uptime_secs']) ? (int)$live['uptime_secs'] : null;
    $loggedUser = ($a['last_user'] !== '') ? $a['last_user'] : null;
} elseif ($snap) {
    $source     = 'snapshot';
    $online     = agent_is_online($a);
    $cpu        = $snap['cpu']    !== null ? (float)$snap['cpu']    : null;
    $mem        = $snap['mem']    !== null ? (float)$snap['mem']    : null;
    $diskC      = $snap['disk_c'] !== null ? (float)$snap['disk_c'] : null;
    $uptime     = $snap['uptime_secs'] !== null ? (int)$snap['uptime_secs'] : null;
    $loggedUser = ($snap['logged_user'] !== '') ? $snap['logged_user'] : null;
} else {
    $source     = 'none';
    $online     = agent_is_online($a);
    $cpu        = null;
    $mem        = null;
    $diskC      = null;
    $uptime     = null;
    $loggedUser = ($a['last_user'] !== '') ? $a['last_user'] : null;
}

$lastSeen = time_ago($a['last_seen_at']);

// Freshness clock is always derived from the snapshot's sampled_at (DATETIME, treated as UTC),
// never from the realtime payload's last_metrics_ts (epoch) — a single, well-defined age source.
$age = ($snap && !empty($snap['sampled_at']))
    ? max(0, time() - strtotime($snap['sampled_at'] . ' UTC'))
    : null;

json_out([
    'ok'              => true,
    'id'              => $id,
    'online'          => $online,
    'source'          => $source,
    'cpu'             => $cpu,
    'mem'             => $mem,
    'disk_c'          => $diskC,
    'uptime_secs'     => $uptime,
    'logged_user'     => $loggedUser,
    'last_seen'       => $lastSeen,
    'sampled_age_secs'=> $age,
]);
