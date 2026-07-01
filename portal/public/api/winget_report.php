<?php
/**
 * POST /api/winget_report.php   (Authorization: Bearer <token>)
 * Agent uploads a winget upgrade scan (available third-party app upgrades). Stored as one current row
 * per agent in agent_app_updates. Mirrors api/patch_report.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';     // require_agent() lives here
require_once __DIR__ . '/../../lib/winget.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);

$agent = require_agent();

// When the feature is off, accept but ignore (so an agent that scans anyway doesn't error-loop);
// the agent is told via the heartbeat whether to scan at all.
if (!winget_enabled()) json_out(['ok' => true, 'disabled' => true]);

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 2_000_000) json_err('Payload too large', 413);
$data = json_decode($raw, true);
if (!is_array($data)) json_err('Invalid JSON');

try {
    winget_upsert_status((int)$agent['id'], $data);
} catch (Throwable $e) {
    error_log('winget_report: ' . $e->getMessage());
    json_err('Store failed', 500);
}

json_out(['ok' => true]);
