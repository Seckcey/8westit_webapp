<?php
/**
 * Agent job queue endpoint   (Authorization: Bearer <token>)
 *
 *   GET  /api/jobs.php           -> claim the next queued job (marks it running)
 *                                   returns { ok, job: {id, job_type, payload} } or { ok, job:null }
 *   POST /api/jobs.php           -> report a result for a job
 *                                   body: { job_id, status:"done"|"error", exit_code, output }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
enforce_https();

$agent  = require_agent();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo    = db();

if ($method === 'GET') {
    // Expire jobs that have been running too long without a result (15 min).
    $pdo->prepare(
        'UPDATE jobs SET status="expired", finished_at=NOW()
         WHERE agent_id=? AND status="running" AND picked_at < (NOW() - INTERVAL 15 MINUTE)'
    )->execute([$agent['id']]);

    $stmt = $pdo->prepare(
        'SELECT id, job_type, payload FROM jobs
         WHERE agent_id=? AND status="queued" ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([$agent['id']]);
    $job = $stmt->fetch();

    if (!$job) json_out(['ok' => true, 'job' => null]);

    $upd = $pdo->prepare('UPDATE jobs SET status="running", picked_at=NOW() WHERE id=? AND status="queued"');
    $upd->execute([$job['id']]);
    if ($upd->rowCount() === 0) json_out(['ok' => true, 'job' => null]); // raced; try next poll

    json_out(['ok' => true, 'job' => [
        'id'       => (int)$job['id'],
        'job_type' => $job['job_type'],
        'payload'  => $job['payload'],
    ]]);
}

if ($method === 'POST') {
    $in = read_json_body();
    $jobId  = (int)($in['job_id'] ?? 0);
    $status = ($in['status'] ?? '') === 'error' ? 'error' : 'done';
    $exit   = isset($in['exit_code']) ? (int)$in['exit_code'] : null;
    $output = (string)($in['output'] ?? '');
    if (strlen($output) > 1_000_000) $output = substr($output, 0, 1_000_000) . "\n...[truncated]";

    $stmt = $pdo->prepare(
        'UPDATE jobs SET status=?, exit_code=?, output=?, finished_at=NOW()
         WHERE id=? AND agent_id=? AND status="running"'
    );
    $stmt->execute([$status, $exit, $output, $jobId, $agent['id']]);
    if ($stmt->rowCount() === 0) json_err('Job not found or not running', 404);

    audit(null, (int)$agent['id'], 'job_result', "job=$jobId status=$status exit=$exit");
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);
