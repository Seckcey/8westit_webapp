<?php
/** JSON: live status for the dashboard grid (session-authed, polled by app.js). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
if (!current_user()) json_err('Unauthorized', 401);

$rows = db()->query('SELECT id, last_seen_at, heartbeat_secs, last_user, public_ip, rustdesk_id FROM agents WHERE is_archived=0')->fetchAll();
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'        => (int)$r['id'],
        'online'    => agent_is_online($r),
        'last_seen' => time_ago($r['last_seen_at']),
        'user'      => $r['last_user'] ?: '—',
        'public_ip' => $r['public_ip'] ?: '—',
        'can_remote'=> (bool)$r['rustdesk_id'],
    ];
}
json_out(['ok' => true, 'agents' => $out]);
