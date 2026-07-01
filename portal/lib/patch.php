<?php
/**
 * Milepost — Phase 3 patch management (MVP: scan-and-report visibility).
 *
 * Agents run a Windows Update scan and POST the result to /api/patch_report.php; the latest snapshot
 * per agent lives in `agent_patch_status` and is surfaced on the device page + a fleet column.
 * Gated by config.php `patch.enabled` (default off). No installs in this MVP.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function patch_enabled(): bool
{
    $p = cfg('patch', []);
    return is_array($p) && !empty($p['enabled']);
}

function patch_scan_interval_hours(): int
{
    $p = cfg('patch', []);
    return max(1, (int)($p['scan_interval_hours'] ?? 8));
}

/**
 * Validate + upsert one agent's Windows Update scan report.
 * $scan (decoded agent JSON): { pending:[{kb,title,classification,severity,reboot_required}],
 *                               reboot_pending:bool, compliance_pct?:number }.
 */
function patch_upsert_status(int $agentId, array $scan): void
{
    $pending = (isset($scan['pending']) && is_array($scan['pending'])) ? $scan['pending'] : [];
    $clean = []; $critical = 0; $n = 0;
    foreach ($pending as $u) {
        if ($n >= 500) break;                     // cap the stored list
        if (!is_array($u)) continue;
        $item = [
            'kb'             => mb_substr((string)($u['kb'] ?? ''), 0, 40),
            'title'          => mb_substr((string)($u['title'] ?? ''), 0, 300),
            'classification' => mb_substr((string)($u['classification'] ?? ''), 0, 64),
            'severity'       => mb_substr((string)($u['severity'] ?? ''), 0, 24),
            'reboot'         => !empty($u['reboot_required']),
        ];
        if (strtolower($item['severity']) === 'critical' || strpos(strtolower($item['classification']), 'security') !== false) {
            $critical++;
        }
        $clean[] = $item; $n++;
    }
    $count  = count($clean);
    $reboot = !empty($scan['reboot_pending']) ? 1 : 0;

    // compliance: honor an agent-supplied value; otherwise 100% only when nothing is pending,
    // else leave NULL (we don't know the installed total without a second, slower scan).
    $compliance = null;
    if (isset($scan['compliance_pct']) && is_numeric($scan['compliance_pct'])) {
        $compliance = max(0.0, min(100.0, (float)$scan['compliance_pct']));
    } elseif ($count === 0) {
        $compliance = 100.0;
    }

    db()->prepare(
        'INSERT INTO agent_patch_status
            (agent_id, last_scan_at, compliance_pct, pending_count, critical_count, reboot_pending, pending_json)
         VALUES (?, NOW(), ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE last_scan_at=NOW(), compliance_pct=VALUES(compliance_pct),
           pending_count=VALUES(pending_count), critical_count=VALUES(critical_count),
           reboot_pending=VALUES(reboot_pending), pending_json=VALUES(pending_json)'
    )->execute([$agentId, $compliance, $count, $critical, $reboot, json_encode($clean, JSON_UNESCAPED_SLASHES)]);
}

/** Latest patch status for one agent, or null. Tolerant of a pre-migration DB. */
function patch_status(int $agentId): ?array
{
    try {
        $st = db()->prepare(
            'SELECT agent_id, last_scan_at, compliance_pct, pending_count, critical_count, reboot_pending, pending_json
               FROM agent_patch_status WHERE agent_id=?'
        );
        $st->execute([$agentId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/** Fleet map agent_id => status row (no pending_json). Tolerant of a pre-migration DB. */
function patch_fleet(): array
{
    try {
        $map = [];
        foreach (db()->query(
            'SELECT agent_id, last_scan_at, compliance_pct, pending_count, critical_count, reboot_pending
               FROM agent_patch_status'
        ) as $r) {
            $map[(int)$r['agent_id']] = $r;
        }
        return $map;
    } catch (Throwable $e) { return []; }
}
