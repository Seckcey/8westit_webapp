<?php
/** JSON: live status for the dashboard grid (session-authed, polled by app.js). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/realtime.php';
enforce_https();
if (!current_user()) json_err('Unauthorized', 401);

$rows = db()->query('SELECT id, last_seen_at, heartbeat_secs, last_user, public_ip, rustdesk_id FROM agents WHERE is_archived=0')->fetchAll();

// ONE batched presence call so the dashboard's online dots reflect the real-time connection
// state, not just the last_seen heuristic. Self-guards: rt_presence() returns [] when the
// real-time backend is disabled / unreachable / times out, and we fall back to agent_is_online.
// (Per-machine cpu/mem live on the agent detail page; the list intentionally stays lean.)
$allIds = array_map(static fn($r) => (int)$r['id'], $rows);
$live = rt_enabled() ? rt_presence($allIds) : [];

$out = [];
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $p  = $live[$id] ?? null;
    $isOnline = ($p !== null) ? !empty($p['online']) : agent_is_online($r);
    $out[] = [
        'id'        => $id,
        'online'    => $isOnline,
        'last_seen' => time_ago($r['last_seen_at']),
        'user'      => $r['last_user'] ?: '—',
        'public_ip' => $r['public_ip'] ?: '—',
        'can_remote'=> (bool)$r['rustdesk_id'],
    ];
}
json_out(['ok' => true, 'agents' => $out]);
