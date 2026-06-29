<?php
/**
 * POST /api/svc/verify_agent.php   (service-signed; spec §3.1)
 *
 * The RT backend cannot verify an agent bearer token itself (no DB), so it asks the portal.
 * We mirror authenticate_agent() exactly: sha256(token) → agents WHERE auth_token_hash AND
 * is_archived=0. HTTP stays 200 even for an unknown token; the `ok` flag carries the result.
 *
 * Request:  { "token": "<raw agent bearer token>" }
 * Response: { ok:true, agent_id, client_id, site_id, site, hostname, display_name,
 *             is_archived:false, policy_etag }
 *           { ok:false, error:"unknown token" }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../lib/svc_auth.php';
require_once __DIR__ . '/../../../lib/policy.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);
require_service();

$in    = read_json_body();
$token = (string)($in['token'] ?? '');
if ($token === '') json_out(['ok' => false, 'error' => 'missing token']);

$hash = hash('sha256', $token);
$stmt = db()->prepare(
    'SELECT id, client_id, site_id, site, hostname, display_name, policy_etag
       FROM agents WHERE auth_token_hash = ? AND is_archived = 0'
);
$stmt->execute([$hash]);
$a = $stmt->fetch();

if (!$a) json_out(['ok' => false, 'error' => 'unknown token']);

// Make sure the etag is populated (lazily compute+persist if the migration backfill missed it).
$etag = $a['policy_etag'];
if ($etag === null || $etag === '') {
    $etag = refresh_agent_policy_etag((int)$a['id']);
}

json_out([
    'ok'           => true,
    'agent_id'     => (int)$a['id'],
    'client_id'    => $a['client_id'] !== null ? (int)$a['client_id'] : null,
    'site_id'      => $a['site_id'] !== null ? (int)$a['site_id'] : null,
    'site'         => (string)($a['site'] ?? ''),
    'hostname'     => (string)($a['hostname'] ?? ''),
    'display_name' => (string)($a['display_name'] ?? ''),
    'is_archived'  => false,
    'policy_etag'  => (string)$etag,
]);
