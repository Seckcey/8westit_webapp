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
require_once __DIR__ . '/policy.php';   // effective_policy_for_agent() for patch_settings (2b)
require_once __DIR__ . '/winget.php';   // winget_status() for the rollout's winget track (one-way dep)

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

/**
 * Patch-compliance rollup grouped by client → site, from agent_patch_status (WU) + agent_app_updates
 * (winget). One aggregate query grouped by (client, site), folded into client rows with nested sites.
 * Read-only; tolerant of a pre-migration DB. A device is "compliant" when it has reported and has 0
 * pending WU updates.
 */
function patch_compliance_report(): array
{
    try {
        $rows = db()->query(
            "SELECT a.client_id,
                    COALESCE(c.name, '(unassigned)') AS client_name,
                    CASE WHEN a.site = '' THEN '(no site)' ELSE a.site END AS site,
                    COUNT(*)                                         AS devices,
                    SUM(aps.agent_id IS NOT NULL)                    AS reporting,
                    AVG(aps.compliance_pct)                          AS avg_compliance,
                    SUM(COALESCE(aps.pending_count, 0))              AS pending,
                    SUM(COALESCE(aps.critical_count, 0))             AS critical,
                    SUM(COALESCE(aps.reboot_pending, 0))             AS reboots,
                    SUM(COALESCE(aau.update_count, 0))               AS app_updates,
                    SUM(CASE WHEN aps.agent_id IS NOT NULL AND COALESCE(aps.pending_count, 0) = 0 THEN 1 ELSE 0 END) AS compliant
               FROM agents a
               LEFT JOIN clients c            ON c.id = a.client_id
               LEFT JOIN agent_patch_status aps ON aps.agent_id = a.id
               LEFT JOIN agent_app_updates aau  ON aau.agent_id = a.id
              WHERE a.is_archived = 0
              GROUP BY a.client_id, client_name, site
              ORDER BY client_name, site"
        )->fetchAll();
    } catch (Throwable $e) { return []; }

    $clients = [];
    foreach ($rows as $r) {
        $cid = (int)$r['client_id'];
        if (!isset($clients[$cid])) {
            $clients[$cid] = ['client_id' => $cid, 'name' => (string)$r['client_name'],
                              'devices' => 0, 'reporting' => 0, 'pending' => 0, 'critical' => 0,
                              'reboots' => 0, 'app_updates' => 0, 'compliant' => 0, '_wsum' => 0.0, '_wn' => 0, 'sites' => []];
        }
        $c =& $clients[$cid];
        foreach (['devices','reporting','pending','critical','reboots','app_updates','compliant'] as $k) $c[$k] += (int)$r[$k];
        if ($r['avg_compliance'] !== null) { $c['_wsum'] += (float)$r['avg_compliance'] * (int)$r['reporting']; $c['_wn'] += (int)$r['reporting']; }
        $c['sites'][] = [
            'site' => (string)$r['site'], 'devices' => (int)$r['devices'], 'reporting' => (int)$r['reporting'],
            'avg_compliance' => $r['avg_compliance'] !== null ? round((float)$r['avg_compliance'], 1) : null,
            'pending' => (int)$r['pending'], 'critical' => (int)$r['critical'], 'reboots' => (int)$r['reboots'],
            'app_updates' => (int)$r['app_updates'], 'compliant' => (int)$r['compliant'],
        ];
        unset($c);
    }
    foreach ($clients as &$c) {
        $c['avg_compliance'] = $c['_wn'] > 0 ? round($c['_wsum'] / $c['_wn'], 1) : null;
        unset($c['_wsum'], $c['_wn']);
    }
    return array_values($clients);
}

/* ── Patch policy (patch_settings) — Phase 3 increment 2b ───────────────────────────────────────
   Rides the policy engine under a `patch_settings` doc key (inherited Client→Site→Group→Device,
   like `thresholds`). SERVER-ONLY: agent_policy.php strips it and policy.php excludes it from the
   policy etag, so patch-policy edits never churn agent policy refetch. */

/** Built-in floor — applied where a policy doc omits a key, so rings work with zero configuration. */
function patch_default_settings(): array
{
    return [
        'ring'         => 'broad',                                 // canary|early|broad|exclude
        'auto_approve' => ['SecurityUpdates', 'CriticalUpdates'],  // WU classifications that auto-install
        'reboot'       => ['policy' => 'if_required', 'grace_min' => 60, 'prompt_user' => true],
        // Third-party app (winget) auto-upgrade in a rollout — opt-in, default OFF. include: 'all' or
        // a list of package Ids; exclude: package Ids to never auto-upgrade.
        'winget'       => ['auto_upgrade' => false, 'include' => 'all', 'exclude' => []],
    ];
}

/** Effective patch_settings for one agent (inherited policy over the floor). Per-request cached. */
function patch_settings_for_agent(int $agentId): array
{
    static $cache = [];
    if (array_key_exists($agentId, $cache)) return $cache[$agentId];
    $s = patch_default_settings();
    try {
        $eff = effective_policy_for_agent($agentId)['effective'];
        $ps  = (isset($eff['patch_settings']) && is_array($eff['patch_settings'])) ? $eff['patch_settings'] : [];
        if (isset($ps['ring']) && is_string($ps['ring']))            $s['ring']         = $ps['ring'];
        if (isset($ps['auto_approve']) && is_array($ps['auto_approve'])) $s['auto_approve'] = array_values($ps['auto_approve']);
        if (isset($ps['reboot']) && is_array($ps['reboot']))         $s['reboot']       = array_replace($s['reboot'], $ps['reboot']);
        if (isset($ps['winget']) && is_array($ps['winget']))         $s['winget']       = array_replace($s['winget'], $ps['winget']);
    } catch (Throwable $e) { /* pre-migration / no policy tables */ }
    return $cache[$agentId] = $s;
}

/**
 * KBs from an agent's latest scan whose classification/severity matches its auto_approve list.
 * The rollout cron uses this to decide what to auto-install (Security/Critical by default).
 */
function patch_auto_approve_kbs(int $agentId): array
{
    $st = patch_status($agentId);
    if (!$st) return [];
    $pending = json_decode((string)($st['pending_json'] ?? '[]'), true);
    if (!is_array($pending)) return [];
    // normalize the configured classifications for space-insensitive substring matching
    $classes = [];
    foreach (patch_settings_for_agent($agentId)['auto_approve'] as $c) {
        $c = strtolower(str_replace(' ', '', (string)$c));
        if ($c !== '') $classes[] = $c;
    }
    $kbs = [];
    foreach ($pending as $u) {
        if (!is_array($u)) continue;
        $kb = strtoupper(trim((string)($u['kb'] ?? '')));
        if (!preg_match('/^KB[0-9]{4,10}$/', $kb)) continue;
        $cls = strtolower(str_replace(' ', '', (string)($u['classification'] ?? '')));
        $match = (strtolower((string)($u['severity'] ?? '')) === 'critical');
        foreach ($classes as $c) { if (strpos($cls, $c) !== false) { $match = true; break; } }
        if ($match && !in_array($kb, $kbs, true)) $kbs[] = $kb;
    }
    return $kbs;
}

/**
 * winget package Ids from an agent's latest scan that a rollout should auto-upgrade, per its patch
 * policy (patch_settings.winget). Returns [] when auto_upgrade is off. include='all' upgrades every
 * scanned app except `exclude`; a list restricts to those Ids (minus `exclude`).
 */
function winget_auto_upgrade_ids(int $agentId): array
{
    $w = patch_settings_for_agent($agentId)['winget'] ?? [];
    if (empty($w['auto_upgrade'])) return [];
    $st = winget_status($agentId);
    if (!$st) return [];
    $apps = json_decode((string)($st['apps_json'] ?? '[]'), true);
    if (!is_array($apps)) return [];
    $include = $w['include'] ?? 'all';
    $exclude = is_array($w['exclude'] ?? null) ? array_map('strval', $w['exclude']) : [];
    $ids = [];
    foreach ($apps as $a) {
        if (!is_array($a)) continue;
        $id = trim((string)($a['id'] ?? ''));
        if ($id === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-+_]{0,127}$/', $id)) continue;
        if (in_array($id, $exclude, true)) continue;
        if (is_array($include) && !in_array($id, $include, true)) continue;   // list restricts; 'all' does not
        if (!in_array($id, $ids, true)) $ids[] = $id;
    }
    return $ids;
}

/* ── Rollout reads (used by the UI + cron) ─────────────────────────────────────────────────────── */

function patch_rollouts_list(): array
{
    try { return db()->query('SELECT * FROM patch_rollouts ORDER BY created_at DESC')->fetchAll(); }
    catch (Throwable $e) { return []; }
}

function patch_rollout_get(int $id): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM patch_rollouts WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/** Non-archived agent ids in a rollout's scope whose effective patch_settings.ring == $ring. */
function patch_ring_agents(array $rollout, string $ring): array
{
    $type = (string)$rollout['scope_type'];
    $sid  = $rollout['scope_id'] !== null ? (int)$rollout['scope_id'] : null;
    if ($type === 'global') {
        $rows = db()->query('SELECT id FROM agents WHERE is_archived=0')->fetchAll();
    } else {
        $col = ['client' => 'client_id', 'site' => 'site_id', 'group' => 'group_id', 'device' => 'id'][$type] ?? null;
        if ($col === null || $sid === null) return [];
        $st = db()->prepare("SELECT id FROM agents WHERE is_archived=0 AND $col = ?");
        $st->execute([$sid]);
        $rows = $st->fetchAll();
    }
    $out = [];
    foreach ($rows as $r) {
        $aid = (int)$r['id'];
        if (patch_settings_for_agent($aid)['ring'] === $ring) $out[] = $aid;
    }
    return $out;
}

/* ── Rollback (Phase 3 increment 3) ──────────────────────────────────────────────────────────────
   Best-effort per-KB uninstall (DISM Remove-WindowsPackage, wusa fallback) run by a 1.4.0+ agent.
   Fix-forward is the primary path; rollback is a human-gated, admin-only recourse. Servicing-stack /
   feature / many cumulative updates cannot be uninstalled — the agent reports that honestly per-KB. */

/** Sanitize a KB list (array or CSV) to unique uppercased KB tokens. Caps at $max. */
function patch_kb_sanitize($list, int $max = 100): array
{
    if (is_string($list)) $list = explode(',', $list);
    $out = [];
    foreach ((array)$list as $k) {
        $k = strtoupper(trim((string)$k));
        if (preg_match('/^KB[0-9]{4,10}$/', $k) && !in_array($k, $out, true)) $out[] = $k;
        if (count($out) >= $max) break;
    }
    return $out;
}

/**
 * KBs Milepost has installed on this device (manual installs + rollout auto-installs both create
 * 'patch_install' jobs), de-duped most-recent-first — the set an admin can choose to roll back.
 * Returns [['kb'=>'KB…','at'=>DATETIME], …]. Tolerant of a pre-migration DB.
 */
function patch_installed_kbs_for_agent(int $agentId, int $limit = 50): array
{
    try {
        $st = db()->prepare(
            "SELECT payload, created_at FROM jobs
              WHERE agent_id=? AND job_type='patch_install' AND status='done'
              ORDER BY id DESC LIMIT 200"
        );
        $st->execute([$agentId]);
        $seen = []; $out = [];
        foreach ($st as $r) {
            foreach (patch_kb_sanitize((string)$r['payload']) as $kb) {
                if (isset($seen[$kb])) continue;
                $seen[$kb] = true;
                $out[] = ['kb' => $kb, 'at' => $r['created_at']];
                if (count($out) >= $limit) return $out;
            }
        }
        return $out;
    } catch (Throwable $e) { return []; }
}
