<?php
/**
 * GET /api/svc/agent_policy.php?agent_id=42   (service-signed; spec §3.6)
 *
 * Returns the resolved effective policy + tool grants for an agent. The backend caches this
 * and refreshes on policy_etag change. Resolution is computed in PHP (policy.php) by merging
 * policy_assignments/policies in order global → client → site → group → device.
 *
 * Response: { ok:true, policy_etag, effective:{ metrics_interval_s, allow_remote,
 *             allowed_tools, deny, auto_approve_tiers, max_blast_radius } }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../lib/svc_auth.php';
require_once __DIR__ . '/../../../lib/policy.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_err('GET required', 405);
require_service();

$agentId = (int)($_GET['agent_id'] ?? 0);
if ($agentId <= 0) json_err('agent_id required', 400);

$chk = db()->prepare('SELECT id FROM agents WHERE id=? AND is_archived=0');
$chk->execute([$agentId]);
if (!$chk->fetch()) json_err('Unknown agent', 404);

$res = effective_policy_for_agent($agentId);

// Keep the stored etag in sync so the backend's change-detection is accurate.
try {
    db()->prepare('UPDATE agents SET policy_etag=? WHERE id=?')->execute([$res['etag'], $agentId]);
} catch (Throwable $e) { /* column not present yet */ }

// `thresholds` is a server-side alerting concern — the agent evaluates nothing from it, so keep it
// out of the agent-facing payload (the etag already excludes it, so this never causes a refetch).
$effective = $res['effective'];
unset($effective['thresholds']);

json_out([
    'ok'          => true,
    'policy_etag' => $res['etag'],
    'effective'   => $effective,
]);
