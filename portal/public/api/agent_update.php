<?php
/**
 * GET /api/agent_update.php?variant=lite|full   (Authorization: Bearer <token>)
 *
 * The agent self-update pull: streams the upgrade MSI to an already-enrolled agent. No
 * enrollment key is needed — the agent authenticates with its existing bearer token (the same
 * token heartbeat.php uses), and its state.json identity/token survives the MSI MajorUpgrade.
 *
 * CRITICAL: this streams the template VERBATIM (readfile). Unlike download.php it does NOT
 * byte-patch the enrollment key in — any mutation would change the bytes and break the agent's
 * SHA-256 check against the hash resolve_agent_update() computed over this exact file.
 *
 * KNOWN CAVEAT (fix-forward — see config.sample.php 'agent_update' for the full note):
 * because the template is unpatched, the MajorUpgrade rewrites HKLM\...\Agent\EnrollKey to the
 * build PLACEHOLDER. The agent keeps working (identity+token live in state.json), but if the
 * token is ever revoked/reset the agent's Enroll() retry would POST the placeholder key, which
 * enroll.php rejects — so a token reset AFTER an auto-update requires a manual reinstall via
 * download.php (which re-patches the real key). Do not silently rely on 401-recovery post-update.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
enforce_https();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_err('GET required', 405);

$agent = require_agent();

// Variant: from ?variant=, validated against the known templates; default from config.
$au = cfg('agent_update', []);
$default = in_array(($au['variant'] ?? 'lite'), ['lite', 'full'], true) ? $au['variant'] : 'lite';
$variant = (string)($_GET['variant'] ?? $default);
if (!in_array($variant, ['lite', 'full'], true)) json_err('Invalid variant');

$tpl = APP_ROOT . '/installers/agent-template-' . $variant . '.msi';
if (!is_file($tpl)) {
    http_response_code(404);
    exit('Update template not available.');
}

audit(null, (int)$agent['id'], 'agent_update', 'variant=' . $variant);

// Stream the exact bytes the resolver hashed. readfile() avoids loading the (up to 24 MB) MSI
// into memory.
header('Content-Type: application/x-msi');
header('Content-Length: ' . filesize($tpl));
header('X-Content-Type-Options: nosniff');
readfile($tpl);
