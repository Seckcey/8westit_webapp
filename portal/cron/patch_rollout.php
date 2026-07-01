<?php
/**
 * Milepost — Phase 3 increment 2b: patch ring-rollout driver (CLI-only, every 1 min).
 *
 * Drives each `running` patch_rollouts row through its ring order. Per current-ring device:
 *   pending -> (in window + online + has auto-approve KBs) create patch_install job -> installing
 *   installing -> (job done) installed | (job error) failed
 *   installed -> (auto-approve updates gone) [reboot if required + policy allows] -> verified
 *                | (still pending) failed
 * When the ring is complete + baked, advance to the next ring; if a ring's failure rate hits
 * max_failure_pct, HALT. Reuses the 2a patch_install job, maintenance windows, and the patch policy.
 * PORTAL-ONLY — no agent change. Gated by config.php patch.enabled.
 *
 * cPanel cron (every minute):
 *   * * * * * /usr/local/bin/php /home/<acct>/public_html/8westit/cron/patch_rollout.php >/dev/null 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: this maintenance script is CLI-only.\n");
}

require_once __DIR__ . '/../lib/patch.php';
require_once __DIR__ . '/../lib/alerts.php';   // maintenance_active_for_agent() + mw_is_active_now()

$line = static function (string $s): void { fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "Z] $s\n"); };
if (!patch_enabled()) { $line('patch disabled — nothing to do.'); exit(0); }

$pdo   = db();
$now   = gmdate('Y-m-d H:i:s');
$nowTs = strtotime($now . ' UTC');

$rollouts = $pdo->query("SELECT * FROM patch_rollouts WHERE status='running'")->fetchAll();
$line('running rollouts: ' . count($rollouts));
foreach ($rollouts as $ro) {
    try { patch_rollout_tick($pdo, $ro, $now, $nowTs, $line); }
    catch (Throwable $e) { $line("rollout {$ro['id']} error: " . $e->getMessage()); }
}
$line('done.');

/** Advance one rollout by one tick. */
function patch_rollout_tick(PDO $pdo, array $ro, string $now, int $nowTs, callable $line): void
{
    $roId  = (int)$ro['id'];
    $rings = json_decode((string)$ro['ring_order'], true);
    if (!is_array($rings) || !$rings) { $line("rollout $roId: no ring_order"); return; }

    // Initialize the current ring on first run.
    $ring = (string)$ro['current_ring'];
    if ($ring === '' || !in_array($ring, $rings, true)) {
        $ring = (string)$rings[0];
        $pdo->prepare('UPDATE patch_rollouts SET current_ring=?, ring_started_at=? WHERE id=?')->execute([$ring, $now, $roId]);
        $ro['ring_started_at'] = $now;
    }

    // 1) Enroll this ring's devices as targets (once).
    $existing = [];
    $st = $pdo->prepare('SELECT agent_id FROM patch_rollout_targets WHERE rollout_id=?');
    $st->execute([$roId]);
    foreach ($st as $r) $existing[(int)$r['agent_id']] = true;
    foreach (patch_ring_agents($ro, $ring) as $aid) {
        if (!isset($existing[$aid])) {
            $pdo->prepare("INSERT INTO patch_rollout_targets (rollout_id, agent_id, ring, state) VALUES (?,?,?,'pending')")
                ->execute([$roId, $aid, $ring]);
        }
    }

    // 2) Step every target in the current ring.
    $ts = $pdo->prepare('SELECT * FROM patch_rollout_targets WHERE rollout_id=? AND ring=?');
    $ts->execute([$roId, $ring]);
    foreach ($ts->fetchAll() as $t) {
        try { patch_target_step($pdo, $ro, $t, $nowTs); }
        catch (Throwable $e) { $line("rollout $roId target {$t['agent_id']} error: " . $e->getMessage()); }
    }

    // 3) Ring completion → auto-halt on failures, else advance after bake.
    $by = ['pending' => 0, 'installing' => 0, 'installed' => 0, 'verified' => 0, 'failed' => 0];
    $cs = $pdo->prepare('SELECT state, COUNT(*) c FROM patch_rollout_targets WHERE rollout_id=? AND ring=? GROUP BY state');
    $cs->execute([$roId, $ring]);
    foreach ($cs as $r) $by[$r['state']] = (int)$r['c'];
    $total   = array_sum($by);
    $done    = $by['verified'] + $by['failed'];
    $failPct = $total > 0 ? ($by['failed'] / $total * 100) : 0.0;

    if ($total > 0 && $failPct >= (float)$ro['max_failure_pct']) {
        $pdo->prepare("UPDATE patch_rollouts SET status='halted' WHERE id=?")->execute([$roId]);
        $line("rollout $roId ring '$ring' HALTED: failures {$failPct}% >= {$ro['max_failure_pct']}%");
        return;
    }
    $baked    = !empty($ro['ring_started_at']) && ($nowTs - strtotime($ro['ring_started_at'] . ' UTC')) >= ((int)$ro['advance_after_min'] * 60);
    $complete = ($total === 0) || ($done === $total);
    if ($complete && ($total === 0 || $baked)) {
        $idx = array_search($ring, $rings, true);
        if ($idx !== false && $idx + 1 < count($rings)) {
            $next = (string)$rings[$idx + 1];
            $pdo->prepare('UPDATE patch_rollouts SET current_ring=?, ring_started_at=? WHERE id=?')->execute([$next, $now, $roId]);
            $line("rollout $roId: ring '$ring' done ({$by['verified']} ok / {$by['failed']} failed) → advance to '$next'");
        } else {
            $pdo->prepare("UPDATE patch_rollouts SET status='completed' WHERE id=?")->execute([$roId]);
            $line("rollout $roId COMPLETED (last ring '$ring')");
        }
    }
}

function patch_target_step(PDO $pdo, array $ro, array $t, int $nowTs): void
{
    $aid   = (int)$t['agent_id'];
    $tid   = (int)$t['id'];
    $state = (string)$t['state'];

    if ($state === 'pending') {
        // Only kick off when the device is ONLINE and inside the install window, so the queued job
        // runs (near-)immediately within the window rather than whenever the agent next polls.
        if (!rollout_online($pdo, $aid, $nowTs) || !rollout_in_window($pdo, $ro, $aid, $nowTs)) return;
        $kbs  = array_slice(patch_auto_approve_kbs($aid), 0, 100);   // WU track
        $apps = array_slice(winget_auto_upgrade_ids($aid), 0, 100);  // winget track (opt-in per policy)
        if (!$kbs && !$apps) {   // nothing auto-approvable pending → this device is already compliant
            $pdo->prepare("UPDATE patch_rollout_targets SET state='verified' WHERE id=?")->execute([$tid]);
            return;
        }
        $kbPayload  = implode(',', $kbs);
        $appPayload = implode(',', $apps);
        $installJob = null; $wingetJob = null;
        if ($kbs) {
            $pdo->prepare("INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,NULL,'patch_install',?)")->execute([$aid, $kbPayload]);
            $installJob = (int)$pdo->lastInsertId();
        }
        if ($apps) {
            $pdo->prepare("INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,NULL,'winget_install',?)")->execute([$aid, $appPayload]);
            $wingetJob = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE patch_rollout_targets SET state='installing', install_job_id=?, winget_job_id=?, kb_list=?, app_list=?, attempts=attempts+1 WHERE id=?")
            ->execute([$installJob, $wingetJob, $kbPayload, $appPayload, $tid]);
        return;
    }

    if ($state === 'installing') {
        // Wait for BOTH tracks (whichever exist). Any error => failed; all present jobs done => installed.
        $err = null; $allDone = true;
        foreach (['install_job_id' => 'WU install', 'winget_job_id' => 'winget upgrade'] as $col => $label) {
            $jid = (int)$t[$col];
            if ($jid === 0) continue;
            $js = $pdo->prepare('SELECT status, output FROM jobs WHERE id=?'); $js->execute([$jid]);
            $job = $js->fetch();
            if (!$job) { $err = "$label job missing"; break; }
            if ($job['status'] === 'error') { $err = mb_substr($label . ': ' . (string)$job['output'], 0, 255); break; }
            if ($job['status'] !== 'done') $allDone = false;
        }
        if ($err !== null) { $pdo->prepare("UPDATE patch_rollout_targets SET state='failed', last_error=? WHERE id=?")->execute([$err, $tid]); return; }
        if ($allDone) $pdo->prepare("UPDATE patch_rollout_targets SET state='installed' WHERE id=?")->execute([$tid]);
        return;   // queued/running → wait
    }

    if ($state === 'installed') {
        // The agent self-rescans after installing. If the SPECIFIC things we pushed are still pending
        // (WU KBs or winget apps), the install did not take → fail. Otherwise reboot (WU, per policy) or verify.
        $kbStill  = array_intersect(patch_kb_sanitize((string)$t['kb_list']), patch_auto_approve_kbs($aid));
        $appStill = array_intersect(winget_id_sanitize((string)$t['app_list']), winget_auto_upgrade_ids($aid));
        if ($kbStill || $appStill) {
            $pdo->prepare("UPDATE patch_rollout_targets SET state='failed', last_error='updates still pending after install' WHERE id=?")->execute([$tid]);
            return;
        }
        $ps       = patch_settings_for_agent($aid);
        $policy   = (string)($ps['reboot']['policy'] ?? 'if_required');
        $status   = patch_status($aid);
        $needBoot = $status && (int)$status['reboot_pending'] === 1 && in_array($policy, ['if_required', 'force'], true);
        if ($needBoot && (int)$t['reboot_job_id'] === 0) {
            $canBoot = ($policy === 'force') || rollout_in_window($pdo, $ro, $aid, $nowTs);
            if ($canBoot && rollout_online($pdo, $aid, $nowTs)) {
                $pdo->prepare("INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,NULL,'restart','')")->execute([$aid]);
                $pdo->prepare('UPDATE patch_rollout_targets SET reboot_job_id=? WHERE id=?')->execute([(int)$pdo->lastInsertId(), $tid]);
            }
            return;   // wait for the reboot to clear reboot_pending
        }
        if ($needBoot && (int)$t['reboot_job_id'] !== 0) return;   // reboot issued; waiting for the box to come back
        $pdo->prepare("UPDATE patch_rollout_targets SET state='verified' WHERE id=?")->execute([$tid]);
    }
}

/** Online if seen within 2.5× its heartbeat interval (mirrors render.php agent_is_online). */
function rollout_online(PDO $pdo, int $agentId, int $nowTs): bool
{
    $st = $pdo->prepare('SELECT last_seen_at, heartbeat_secs FROM agents WHERE id=? AND is_archived=0');
    $st->execute([$agentId]);
    $a = $st->fetch();
    if (!$a || empty($a['last_seen_at'])) return false;
    $hb = max(30, (int)($a['heartbeat_secs'] ?: 60));
    return ($nowTs - strtotime($a['last_seen_at'] . ' UTC')) <= $hb * 2.5;
}

/** Install/reboot only inside the rollout's maintenance window (or any window if none is set). */
function rollout_in_window(PDO $pdo, array $ro, int $agentId, int $nowTs): bool
{
    $wid = $ro['window_id'] !== null ? (int)$ro['window_id'] : 0;
    if ($wid > 0) {
        $w = $pdo->prepare('SELECT starts_at, ends_at, recurrence, is_enabled FROM maintenance_windows WHERE id=?');
        $w->execute([$wid]);
        $win = $w->fetch();
        return $win && (int)$win['is_enabled'] === 1 && mw_is_active_now($win, $nowTs);
    }
    return maintenance_active_for_agent($agentId, $nowTs);
}
