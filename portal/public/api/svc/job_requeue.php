<?php
/**
 * POST /api/svc/job_requeue.php   (service-signed; spec §3.4)
 *
 * Drops an RT-claimed job back to the polling path when WS delivery failed (agent offline),
 * so the next 60s poll claims it. Only affects rows still status='running'.
 *
 * Request:  { agent_id, job_id }
 * Response: { ok:true }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../lib/svc_auth.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);
require_service();

$in      = read_json_body();
$agentId = (int)($in['agent_id'] ?? 0);
$jobId   = (int)($in['job_id'] ?? 0);
if ($agentId <= 0 || $jobId <= 0) json_err('agent_id and job_id required', 400);

$stmt = db()->prepare(
    'UPDATE jobs SET status=\'queued\', picked_at=NULL, delivered_via=\'poll\'
      WHERE id=? AND agent_id=? AND status=\'running\''
);
$stmt->execute([$jobId, $agentId]);

if ($stmt->rowCount() > 0) {
    audit(null, $agentId, 'job_requeue', "job=$jobId");
}

json_out(['ok' => true]);
