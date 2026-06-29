<?php
/**
 * POST /api/svc/job_result.php   (service-signed; spec §3.2)
 *
 * Durable persistence of a command result that flowed back over WS. Idempotent by job_id;
 * trusts agent_id from the verified backend. Mirrors the existing /api/jobs.php POST rules
 * (status normalized to done|error, output capped at 1,000,000 bytes).
 *
 * Request:  { agent_id, job_id, status, exit_code, output }
 * Response: { ok:true, already:false }   // already:true when the row was already terminal
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../lib/svc_auth.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);
require_service();

$in      = read_json_body();
$agentId = (int)($in['agent_id'] ?? 0);
$jobId   = (int)($in['job_id'] ?? 0);
$status  = ($in['status'] ?? '') === 'error' ? 'error' : 'done';
$exit    = isset($in['exit_code']) ? (int)$in['exit_code'] : null;
$output  = (string)($in['output'] ?? '');
if (strlen($output) > 1_000_000) $output = substr($output, 0, 1_000_000) . "\n...[truncated]";

if ($agentId <= 0 || $jobId <= 0) json_err('agent_id and job_id required', 400);

$stmt = db()->prepare(
    'UPDATE jobs
        SET status=?, exit_code=?, output=?, finished_at=NOW(), delivered_via=\'realtime\'
      WHERE id=? AND agent_id=? AND status IN (\'queued\',\'running\')'
);
$stmt->execute([$status, $exit, $output, $jobId, $agentId]);
$already = $stmt->rowCount() === 0; // 0 rows => already terminal (idempotent no-op)

if (!$already) {
    audit(null, $agentId, 'job_result_rt', "job=$jobId status=$status exit=$exit");

    // Advance any tool invocation linked to this job (governed-tool provenance, spec §4.3).
    try {
        $inv = db()->prepare(
            'UPDATE tool_invocations SET status=?, decided_at=COALESCE(decided_at, NOW())
              WHERE job_id=? AND status IN (\'dispatched\',\'approved\')'
        );
        $inv->execute([$status === 'error' ? 'error' : 'done', $jobId]);
    } catch (Throwable $e) { /* tool tables optional / not migrated */ }
}

json_out(['ok' => true, 'already' => $already]);
