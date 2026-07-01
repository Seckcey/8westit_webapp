<?php
/**
 * POST /api/patch_report.php   (Authorization: Bearer <token>)
 * Agent uploads a Windows Update scan result (pending updates + reboot-pending). Stored as one
 * current row per agent in agent_patch_status. Mirrors api/inventory.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';     // require_agent() lives here
require_once __DIR__ . '/../../lib/patch.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);

$agent = require_agent();

// When patch management is off, accept the request but ignore it (so an agent that scans anyway
// doesn't error-loop); the agent is told via the heartbeat whether to scan at all.
if (!patch_enabled()) json_out(['ok' => true, 'disabled' => true]);

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 2_000_000) json_err('Payload too large', 413);
$data = json_decode($raw, true);
if (!is_array($data)) json_err('Invalid JSON');

try {
    patch_upsert_status((int)$agent['id'], $data);
} catch (Throwable $e) {
    error_log('patch_report: ' . $e->getMessage());
    json_err('Store failed', 500);
}

json_out(['ok' => true]);
