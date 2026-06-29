<?php
/**
 * Agent auto-update resolver (spec: targeted/canary self-update).
 *
 * resolve_agent_update() is the SINGLE source of truth that both enroll.php and
 * heartbeat.php call. It returns the "update" directive (a small assoc array) to embed
 * in the API response, or null when no update should be advertised.
 *
 * It is OFF BY DEFAULT and fails safe at every step:
 *   - returns null unless cfg('agent_update')['enabled'] is truthy AND a non-empty global
 *     target_version is set (the hard kill-switch);
 *   - advertises only when the effective target is STRICTLY GREATER than the agent's current
 *     version (version_compare) — version-increase-only, never equal, never downgrade;
 *   - returns null if the variant's template MSI is missing on disk (never advertise an
 *     update we cannot serve);
 *   - sha256 is hash_file() over the EXACT bytes agent_update.php streams verbatim, so the
 *     agent's SHA-256 check is authoritative.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/**
 * Resolve the auto-update directive for one agent row.
 *
 * @param array $agentRow Needs at least 'agent_version' and 'target_version' (the per-device
 *                        override). On heartbeat the caller passes the freshly-reported version
 *                        as 'agent_version' (NOT the possibly-stale DB value).
 * @return array|null ['target_version','url','sha256','variant'] or null to advertise nothing.
 */
function resolve_agent_update(array $agentRow): ?array
{
    $au = cfg('agent_update', []);
    if (!is_array($au) || empty($au['enabled'])) return null;   // master kill-switch

    // The version of the currently-uploaded template (the latest available). Required: it is
    // what we advertise, and it matches the served MSI + its sha256.
    $target = trim((string)($au['target_version'] ?? ''));
    if ($target === '') return null;

    // Rollout scope. DEFAULT (fleet_wide=false) = CANARY: only endpoints an admin explicitly
    // opted in (agents.target_version set, via the agent page's "Update to latest" button)
    // receive the directive — so you can update CERTAIN endpoints without touching the fleet.
    // Set fleet_wide=true to roll out to EVERY eligible endpoint.
    $fleetWide = !empty($au['fleet_wide']);
    $optedIn   = trim((string)($agentRow['target_version'] ?? '')) !== '';
    if (!$fleetWide && !$optedIn) return null;

    // Version-INCREASE-ONLY: advertise only when the target is newer than the agent's current.
    $current = (string)($agentRow['agent_version'] ?? '');
    if (!version_compare($target, $current === '' ? '0' : $current, '>')) return null;

    // Variant from config, validated against the known templates.
    $variant = (string)($au['variant'] ?? 'lite');
    if (!in_array($variant, ['lite', 'full'], true)) $variant = 'lite';

    // Never advertise an update we cannot serve.
    $tpl = APP_ROOT . '/installers/agent-template-' . $variant . '.msi';
    if (!is_file($tpl)) return null;

    // SHA-256 of the exact bytes agent_update.php streams. Cache per request per variant so a
    // hot heartbeat path does not re-hash a 24 MB file on every call.
    static $cache = [];
    if (!isset($cache[$variant])) {
        $h = hash_file('sha256', $tpl);
        if ($h === false) return null;
        $cache[$variant] = $h;
    }

    return [
        'target_version' => $target,
        'url'            => rtrim((string)cfg('base_url', ''), '/') . '/api/agent_update.php',
        'sha256'         => $cache[$variant],
        'variant'        => $variant,
    ];
}
