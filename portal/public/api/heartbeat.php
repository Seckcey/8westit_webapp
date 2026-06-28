<?php
/**
 * POST /api/heartbeat.php   (Authorization: Bearer <token>)
 * Agent reports it's alive plus volatile state. Returns whether jobs are waiting.
 *
 * Body: { last_user, public_ip, local_ip, agent_version, rustdesk_id, rustdesk_pass }
 * Returns: { ok, pending_jobs }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);

$agent = require_agent();
$in = read_json_body();

$lastUser = mb_substr(trim((string)($in['last_user'] ?? '')), 0, 128);
$localIp  = mb_substr(trim((string)($in['local_ip'] ?? '')), 0, 45);
$ver      = mb_substr(trim((string)($in['agent_version'] ?? '')), 0, 32);
$rdId     = mb_substr(trim((string)($in['rustdesk_id'] ?? '')), 0, 32);
$rdPass   = mb_substr(trim((string)($in['rustdesk_pass'] ?? '')), 0, 128);
$publicIp = client_ip(); // trust the connection's source IP, not the body

$stmt = db()->prepare(
    'UPDATE agents SET last_seen_at = NOW(), last_user = ?, public_ip = ?, local_ip = ?,
        agent_version = ?,
        rustdesk_id   = CASE WHEN ? <> \'\' THEN ? ELSE rustdesk_id   END,
        rustdesk_pass = CASE WHEN ? <> \'\' THEN ? ELSE rustdesk_pass END
     WHERE id = ?'
);
$stmt->execute([$lastUser, $publicIp, $localIp, $ver, $rdId, $rdId, $rdPass, $rdPass, $agent['id']]);

$stmt = db()->prepare('SELECT COUNT(*) FROM jobs WHERE agent_id = ? AND status = "queued"');
$stmt->execute([$agent['id']]);
$pending = (int)$stmt->fetchColumn();

json_out(['ok' => true, 'pending_jobs' => $pending]);
