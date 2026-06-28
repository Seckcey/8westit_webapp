<?php
/**
 * POST /api/enroll.php
 * Agent presents a pre-shared enrollment key and its self-generated GUID.
 * We create (or re-attach) the agent row and issue a unique bearer token.
 *
 * Body: { "enrollment_key": "...", "agent_uid": "GUID", "hostname": "...",
 *         "os_name": "...", "os_version": "...", "agent_version": "..." }
 * Returns: { ok, token, heartbeat_secs, rustdesk: { relay_host, relay_key } }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);

$in = read_json_body();
$key      = trim((string)($in['enrollment_key'] ?? ''));
$uid      = trim((string)($in['agent_uid'] ?? ''));
$hostname = mb_substr(trim((string)($in['hostname'] ?? '')), 0, 128);
$osName   = mb_substr(trim((string)($in['os_name'] ?? '')), 0, 128);
$osVer    = mb_substr(trim((string)($in['os_version'] ?? '')), 0, 64);
$ver      = mb_substr(trim((string)($in['agent_version'] ?? '')), 0, 32);

if ($key === '' || !preg_match('/^[a-f0-9]{64}$/i', $key)) json_err('Invalid enrollment key');
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $uid)) json_err('Invalid agent_uid');

// Validate the enrollment key.
$stmt = db()->prepare('SELECT * FROM enrollment_keys WHERE key_value = ? AND is_active = 1');
$stmt->execute([strtolower($key)]);
$ek = $stmt->fetch();
if (!$ek) {
    audit(null, null, 'enroll', 'rejected bad key host=' . $hostname);
    json_err('Enrollment key not recognized', 403);
}

// Issue a fresh token (returned once, stored only as a hash).
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$pdo = db();

// Re-enroll if this GUID already exists, else create.
$stmt = $pdo->prepare('SELECT id FROM agents WHERE agent_uid = ?');
$stmt->execute([$uid]);
$existing = $stmt->fetch();

if ($existing) {
    $stmt = $pdo->prepare(
        'UPDATE agents SET auth_token_hash=?, hostname=?, os_name=?, os_version=?,
            agent_version=?, client_id=COALESCE(client_id, ?), is_archived=0
         WHERE id=?'
    );
    $stmt->execute([$tokenHash, $hostname, $osName, $osVer, $ver, $ek['client_id'], $existing['id']]);
    $agentId = (int)$existing['id'];
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO agents (client_id, agent_uid, auth_token_hash, hostname, display_name,
            os_name, os_version, agent_version, last_seen_at)
         VALUES (?,?,?,?,?,?,?,?,NOW())'
    );
    $stmt->execute([$ek['client_id'], $uid, $tokenHash, $hostname, $hostname, $osName, $osVer, $ver]);
    $agentId = (int)$pdo->lastInsertId();
}

audit(null, $agentId, 'enroll', 'host=' . $hostname);

$rd = cfg('rustdesk', []);
json_out([
    'ok' => true,
    'token' => $token,
    'heartbeat_secs' => 60,
    'rustdesk' => [
        'relay_host' => $rd['relay_host'] ?? '',
        'relay_key'  => $rd['relay_key'] ?? '',
    ],
]);
