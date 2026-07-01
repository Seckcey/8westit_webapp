<?php
/**
 * Milepost — Phase 3: third-party application patching via winget (Windows Package Manager).
 *
 * A 1.5.0+ agent runs `winget upgrade`, POSTs the available upgrades to /api/winget_report.php, and
 * the latest snapshot per agent lives in `agent_app_updates`, surfaced on the device page. Admins can
 * queue winget_install jobs (upgrade selected package Ids). Gated by config.php `winget.enabled`.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function winget_enabled(): bool
{
    $w = cfg('winget', []);
    return is_array($w) && !empty($w['enabled']);
}

function winget_scan_interval_hours(): int
{
    $w = cfg('winget', []);
    return max(1, (int)($w['scan_interval_hours'] ?? 12));
}

/** Sanitize a winget package-Id list (array or CSV) to unique valid Ids (Google.Chrome, …). Caps at $max. */
function winget_id_sanitize($list, int $max = 100): array
{
    if (is_string($list)) $list = explode(',', $list);
    $out = [];
    foreach ((array)$list as $x) {
        $x = trim((string)$x);
        if ($x !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-+_]{0,127}$/', $x) && !in_array($x, $out, true)) $out[] = $x;
        if (count($out) >= $max) break;
    }
    return $out;
}

/** Validate + upsert one agent's winget upgrade list. $data = { apps:[{id,name,version,available,source}] }. */
function winget_upsert_status(int $agentId, array $data): void
{
    $apps  = (isset($data['apps']) && is_array($data['apps'])) ? $data['apps'] : [];
    $clean = []; $n = 0;
    foreach ($apps as $a) {
        if ($n >= 300) break;
        if (!is_array($a)) continue;
        $id = mb_substr((string)($a['id'] ?? ''), 0, 128);
        if ($id === '') continue;
        $clean[] = [
            'id'        => $id,
            'name'      => mb_substr((string)($a['name'] ?? ''), 0, 200),
            'version'   => mb_substr((string)($a['version'] ?? ''), 0, 64),
            'available' => mb_substr((string)($a['available'] ?? ''), 0, 64),
            'source'    => mb_substr((string)($a['source'] ?? ''), 0, 40),
        ];
        $n++;
    }
    db()->prepare(
        'INSERT INTO agent_app_updates (agent_id, last_scan_at, update_count, apps_json)
         VALUES (?, NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE last_scan_at=NOW(), update_count=VALUES(update_count), apps_json=VALUES(apps_json)'
    )->execute([$agentId, count($clean), json_encode($clean, JSON_UNESCAPED_SLASHES)]);
}

/** Latest winget status for one agent, or null. Tolerant of a pre-migration DB. */
function winget_status(int $agentId): ?array
{
    try {
        $st = db()->prepare('SELECT agent_id, last_scan_at, update_count, apps_json FROM agent_app_updates WHERE agent_id=?');
        $st->execute([$agentId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}
