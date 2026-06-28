<?php
/**
 * POST /api/inventory.php   (Authorization: Bearer <token>)
 * Agent uploads a full hardware/software inventory snapshot as JSON.
 * Stored verbatim (one current row per agent); rendered on the detail page.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);

$agent = require_agent();
$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 1_500_000) json_err('Payload too large', 413);

$data = json_decode($raw, true);
if (!is_array($data)) json_err('Invalid JSON');

// Re-encode to normalize / strip anything weird before storing.
$clean = json_encode($data, JSON_UNESCAPED_SLASHES);

$stmt = db()->prepare(
    'INSERT INTO inventory (agent_id, data_json, updated_at) VALUES (?,?,NOW())
     ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = NOW()'
);
$stmt->execute([$agent['id'], $clean]);

json_out(['ok' => true]);
