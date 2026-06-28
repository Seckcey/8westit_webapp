<?php
/** JSON: status + output for the most recent jobs of an agent (for live console updates). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
if (!current_user()) json_err('Unauthorized', 401);

$agentId = (int)($_GET['agent'] ?? 0);
$stmt = db()->prepare('SELECT id, status, exit_code, output FROM jobs WHERE agent_id=? ORDER BY id DESC LIMIT 20');
$stmt->execute([$agentId]);
$jobs = [];
foreach ($stmt->fetchAll() as $j) {
    $jobs[] = [
        'id'        => (int)$j['id'],
        'status'    => $j['status'],
        'exit_code' => $j['exit_code'] === null ? null : (int)$j['exit_code'],
        'output'    => (string)($j['output'] ?? ''),
    ];
}
json_out(['ok' => true, 'jobs' => $jobs]);
