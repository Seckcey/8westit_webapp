<?php
/**
 * POST /api/svc/job_create.php   (service-signed; spec §3.3)
 *
 * Mints a jobs row. When dispatch='realtime' it returns the row already claimed (status=running,
 * picked_at=NOW(), delivered_via=realtime) so the polling GET (which only claims status=queued)
 * cannot also grab it. When dispatch='poll' it inserts status=queued / delivered_via=poll.
 *
 * Called by the portal UI ("Run now") or by the backend when it needs the canonical job_id
 * before pushing a `command`. created_by_user is recorded in audit_log, not on the jobs row.
 *
 * Request:  { agent_id, job_type, payload, created_by_user?, tool_action_id?,
 *             timeout_secs?, dispatch:"realtime"|"poll" }
 * Response: { ok:true, job_id, status }   // status "running" (realtime) | "queued" (poll)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../lib/svc_auth.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);
require_service();

$in       = read_json_body();
$agentId  = (int)($in['agent_id'] ?? 0);
$jobType  = (string)($in['job_type'] ?? 'powershell');
$payload  = (string)($in['payload'] ?? '');
$createdBy = isset($in['created_by_user']) && $in['created_by_user'] !== null
    ? (int)$in['created_by_user'] : null;
$toolAction = isset($in['tool_action_id']) && $in['tool_action_id'] !== null
    ? (string)$in['tool_action_id'] : null;
$dispatch = ($in['dispatch'] ?? 'poll') === 'realtime' ? 'realtime' : 'poll';

if ($agentId <= 0) json_err('agent_id required', 400);
if (!in_array($jobType, ['powershell', 'cmd', 'restart', 'message'], true)) {
    json_err('Unsupported job_type', 400);
}
if ($jobType === 'restart') $payload = '';

// Validate the agent exists and is active (the backend trusts us as source of truth).
$chk = db()->prepare('SELECT id FROM agents WHERE id=? AND is_archived=0');
$chk->execute([$agentId]);
if (!$chk->fetch()) json_err('Unknown agent', 404);

if ($dispatch === 'realtime') {
    $status = 'running';
    $stmt = db()->prepare(
        'INSERT INTO jobs (agent_id, created_by, job_type, payload, status, queued_at,
            picked_at, delivered_via, tool_action_id)
         VALUES (?,?,?,?,\'running\',NOW(),NOW(),\'realtime\',?)'
    );
    $stmt->execute([$agentId, $createdBy, $jobType, $payload, $toolAction]);
} else {
    $status = 'queued';
    $stmt = db()->prepare(
        'INSERT INTO jobs (agent_id, created_by, job_type, payload, status, queued_at,
            delivered_via, tool_action_id)
         VALUES (?,?,?,?,\'queued\',NOW(),\'poll\',?)'
    );
    $stmt->execute([$agentId, $createdBy, $jobType, $payload, $toolAction]);
}

$jobId = (int) db()->lastInsertId();
audit($createdBy, $agentId, 'job_create', "job=$jobId type=$jobType dispatch=$dispatch"
    . ($toolAction !== null ? " tool=$toolAction" : ''));

json_out(['ok' => true, 'job_id' => $jobId, 'status' => $status]);
