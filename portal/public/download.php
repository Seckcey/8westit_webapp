<?php
/**
 * Public agent download endpoint.
 *   GET /download.php?k=<enrollment-key>
 *
 * Serves a single, self-contained MSI with this client's enrollment key patched in.
 * No build server needed: a generic template MSI (built by agent/build-templates.ps1
 * and uploaded to ../installers/) carries a fixed-length placeholder key, which we
 * replace in-place with the real key — same length, so the MSI stays valid.
 *
 * No login required: the key in the link authorizes the download (it's meant to be
 * shared with / scanned on the client PC).
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
enforce_https();

/** 64-char placeholder — MUST match $placeholder in agent/build-templates.ps1. */
function enroll_placeholder(): string
{
    $base = '8WESTIT-ENROLLKEY-PLACEHOLDER-';
    return $base . str_repeat('0', 64 - strlen($base));
}

function slugify(string $s): string
{
    $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'Client';
}

$key = strtolower(trim((string)($_GET['k'] ?? $_GET['key'] ?? '')));
if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
    http_response_code(400);
    exit('Invalid or missing download key.');
}

$stmt = db()->prepare(
    'SELECT k.*, c.name AS client_name FROM enrollment_keys k
       LEFT JOIN clients c ON c.id = k.client_id
      WHERE k.key_value = ? AND k.is_active = 1'
);
$stmt->execute([$key]);
$dep = $stmt->fetch();

if (!$dep) { http_response_code(404); exit('This download link is not valid or has been disabled.'); }
if (!empty($dep['expires_at']) && strtotime($dep['expires_at'] . ' UTC') < time()) {
    http_response_code(410);
    exit('This download link has expired. Ask your IT provider for a new one.');
}

$variant  = ((int)($dep['bundle_rustdesk'] ?? 1) === 1) ? 'full' : 'lite';
$template  = APP_ROOT . '/installers/agent-template-' . $variant . '.msi';
if (!is_file($template)) {
    http_response_code(503);
    exit("Installer template is not available yet. (Admin: upload agent-template-$variant.msi to /installers.)");
}

$bytes = file_get_contents($template);
$ph = enroll_placeholder();
$pos = strpos($bytes, $ph);
if ($pos === false) {
    http_response_code(500);
    exit('Installer template is missing its key placeholder. Rebuild and re-upload the template.');
}
$bytes = substr_replace($bytes, $key, $pos, strlen($ph));

audit(null, null, 'agent_download', 'client=' . ($dep['client_name'] ?? '') . ' variant=' . $variant);

$fname = '8West-' . slugify((string)($dep['client_name'] ?? 'Client')) . '-Agent.msi';
header('Content-Type: application/x-msi');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($bytes));
header('X-Content-Type-Options: nosniff');
echo $bytes;
