<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/realtime.php';
require_once __DIR__ . '/../lib/update.php';
require_once __DIR__ . '/../lib/patch.php';
require_once __DIR__ . '/../lib/winget.php';
enforce_https();
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT a.*, c.name AS client_name FROM agents a
       LEFT JOIN clients c ON c.id = a.client_id WHERE a.id = ? AND a.is_archived = 0'
);
$stmt->execute([$id]);
$agent = $stmt->fetch();
if (!$agent) { http_response_code(404); exit('Agent not found.'); }

$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'queue_job') {
        $type = in_array($_POST['job_type'] ?? '', ['powershell','cmd','restart','message'], true)
            ? $_POST['job_type'] : 'powershell';
        $payload = (string)($_POST['payload'] ?? '');
        if ($type === 'restart') $payload = '';
        if ($type !== 'restart' && trim($payload) === '') {
            $flash = 'Nothing to run — command was empty.';
        } else {
            // Try real-time first: if the agent is connected to the RT backend, mint the job
            // already-claimed (status=running) and push it over WS so it runs in seconds.
            // On any failure (RT disabled, backend down, agent not connected) fall back to the
            // existing polling queue exactly as before — the safety floor never goes away.
            $delivered = false;
            if (rt_enabled() && rt_agent_online($id)) {
                // Mint the row already claimed for RT so the 60s poller (status=queued) can't grab it.
                $ins = db()->prepare(
                    'INSERT INTO jobs (agent_id, created_by, job_type, payload, status, queued_at,
                        picked_at, delivered_via)
                     VALUES (?,?,?,?,\'running\',NOW(),NOW(),\'realtime\')'
                );
                $ins->execute([$id, $user['id'], $type, $payload]);
                $jobId = (int) db()->lastInsertId();

                $res = rt_dispatch_command($id, $jobId, $type, $payload);
                if (!empty($res['delivered'])) {
                    $delivered = true;
                    audit((int)$user['id'], $id, 'queue_job', "type=$type via=realtime job=$jobId");
                    $flash = 'Command sent in real time — running now on the agent.';
                } else {
                    // Backend accepted us but couldn't deliver (agent dropped): drop back to polling.
                    db()->prepare(
                        'UPDATE jobs SET status=\'queued\', picked_at=NULL, delivered_via=\'poll\'
                          WHERE id=? AND status=\'running\''
                    )->execute([$jobId]);
                    audit((int)$user['id'], $id, 'queue_job', "type=$type via=poll(rt_fallback) job=$jobId");
                    $flash = 'Command queued. It will run on the next agent check-in.';
                    $delivered = true; // job already created; skip the plain insert below
                }
            }

            if (!$delivered) {
                $ins = db()->prepare(
                    'INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,?,?,?)'
                );
                $ins->execute([$id, $user['id'], $type, $payload]);
                audit((int)$user['id'], $id, 'queue_job', "type=$type via=poll");
                $flash = 'Command queued. It will run on the next agent check-in.';
            }
        }
    } elseif ($action === 'update_agent') {
        $name = mb_substr(trim((string)($_POST['display_name'] ?? '')), 0, 128);
        $cid  = ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null;
        db()->prepare('UPDATE agents SET display_name=?, client_id=? WHERE id=?')
            ->execute([$name, $cid, $id]);
        $flash = 'Saved.';
    } elseif ($action === 'update_agent_version') {
        // Targeted/canary push: set THIS agent's target_version to the global config target.
        // High blast radius, so admin-only (other actions on this page are login-only). The
        // heartbeat directive + resolver do the actual rollout; no job_type / no ENUM change.
        if (($user['role'] ?? '') !== 'admin') {
            $flash = 'Only admins can push agent updates.';
        } else {
            $au = cfg('agent_update', []);
            $gt = trim((string)($au['target_version'] ?? ''));
            if (empty($au['enabled']) || $gt === '') {
                $flash = 'Auto-update is disabled or no global target is set.';
            } else {
                db()->prepare('UPDATE agents SET target_version=? WHERE id=?')->execute([$gt, $id]);
                audit((int)$user['id'], $id, 'update_agent_version', 'target=' . $gt);
                $flash = 'Update to ' . $gt . ' queued for this endpoint.';
            }
        }
    } elseif ($action === 'patch_scan') {
        // On-demand Windows Update scan (read-only; login-only). Queued job the agent runs at check-in.
        db()->prepare('INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,?,\'patch_scan\',\'\')')
            ->execute([$id, $user['id']]);
        audit((int)$user['id'], $id, 'patch_scan', '');
        $flash = 'Windows Update scan queued — results appear within a minute.';
    } elseif ($action === 'patch_install') {
        // Install selected updates (DESTRUCTIVE → admin-only; the human click is the approval).
        if (($user['role'] ?? '') !== 'admin') {
            $flash = 'Only admins can install updates.';
        } else {
            $kbs = [];
            foreach ((array)($_POST['kb'] ?? []) as $k) {
                $k = strtoupper(trim((string)$k));
                if (preg_match('/^KB[0-9]{4,10}$/', $k) && !in_array($k, $kbs, true)) $kbs[] = $k;
                if (count($kbs) >= 100) break;
            }
            if (!$kbs) {
                $flash = 'No updates selected.';
            } else {
                db()->prepare('INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,?,\'patch_install\',?)')
                    ->execute([$id, $user['id'], implode(',', $kbs)]);
                audit((int)$user['id'], $id, 'patch_install', count($kbs) . ' KBs: ' . implode(',', $kbs));
                $flash = count($kbs) . ' update(s) queued to install — this can take several minutes. Use "Scan now" afterward to refresh.';
            }
        }
    } elseif ($action === 'patch_rollback') {
        // Best-effort uninstall of selected KBs (DESTRUCTIVE → admin-only). Honest: some updates
        // (servicing-stack / feature / many cumulative) can't be removed — the job output says so per-KB.
        if (($user['role'] ?? '') !== 'admin') {
            $flash = 'Only admins can roll back updates.';
        } else {
            $kbs = patch_kb_sanitize($_POST['kb'] ?? []);
            if (!$kbs) {
                $flash = 'No updates selected.';
            } else {
                db()->prepare('INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,?,\'patch_rollback\',?)')
                    ->execute([$id, $user['id'], implode(',', $kbs)]);
                audit((int)$user['id'], $id, 'patch_rollback', count($kbs) . ' KBs: ' . implode(',', $kbs));
                $flash = count($kbs) . ' update(s) queued to roll back — best-effort (some may not be reversible). Use "Scan now" afterward to refresh.';
            }
        }
    } elseif ($action === 'winget_scan') {
        // On-demand winget upgrade scan (read-only; login-only). Queued job the agent runs at check-in.
        db()->prepare('INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,?,\'winget_scan\',\'\')')
            ->execute([$id, $user['id']]);
        audit((int)$user['id'], $id, 'winget_scan', '');
        $flash = 'winget scan queued — results appear within a minute.';
    } elseif ($action === 'winget_install') {
        // Upgrade selected third-party apps (DESTRUCTIVE → admin-only; the human click is the approval).
        if (($user['role'] ?? '') !== 'admin') {
            $flash = 'Only admins can upgrade apps.';
        } else {
            $ids = winget_id_sanitize($_POST['appid'] ?? []);
            if (!$ids) {
                $flash = 'No apps selected.';
            } else {
                db()->prepare('INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,?,\'winget_install\',?)')
                    ->execute([$id, $user['id'], implode(',', $ids)]);
                audit((int)$user['id'], $id, 'winget_install', count($ids) . ' apps: ' . implode(',', $ids));
                $flash = count($ids) . ' app upgrade(s) queued — this runs in the background. Use "Scan now" afterward to refresh.';
            }
        }
    } elseif ($action === 'archive') {
        db()->prepare('UPDATE agents SET is_archived=1 WHERE id=?')->execute([$id]);
        audit((int)$user['id'], $id, 'archive', '');
        header('Location: index.php'); exit;
    }
    // reload
    $stmt->execute([$id]); $agent = $stmt->fetch();
}

$clients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();

$inv = db()->prepare('SELECT data_json, updated_at FROM inventory WHERE agent_id=?');
$inv->execute([$id]);
$invRow = $inv->fetch();
$inventory = $invRow ? json_decode($invRow['data_json'], true) : null;

$patch = patch_status($id);   // Phase 3: latest Windows Update scan (null if none / feature off)

$jobs = db()->prepare('SELECT * FROM jobs WHERE agent_id=? ORDER BY id DESC LIMIT 20');
$jobs->execute([$id]);
$jobRows = $jobs->fetchAll();

// ── Live metrics card (server-rendered initial values so it works with JS off and
//    never flashes empty). Same three-way source resolution as agent_live.php.
//    realtime.php is already required at the top of this file.
$ms = db()->prepare('SELECT cpu, mem, disk_c, uptime_secs, logged_user, sampled_at FROM agent_metrics_latest WHERE agent_id=?');
$ms->execute([$id]);
$snap = $ms->fetch();
$live = rt_enabled() ? rt_presence_one($id) : null;

if ($live && !empty($live['online'])) {
    $liveSrc    = 'realtime';
    $liveOnline = true;
    $liveCpu    = isset($live['cpu'])    ? (float)$live['cpu']    : null;
    $liveMem    = isset($live['mem'])    ? (float)$live['mem']    : null;
    $liveDisk   = isset($live['disk_c']) ? (float)$live['disk_c'] : null;
    $liveUptime = isset($live['uptime_secs']) ? (int)$live['uptime_secs'] : null;
    $liveUser   = ($agent['last_user'] !== '') ? $agent['last_user'] : null;
} elseif ($snap) {
    $liveSrc    = 'snapshot';
    $liveOnline = agent_is_online($agent);
    $liveCpu    = $snap['cpu']    !== null ? (float)$snap['cpu']    : null;
    $liveMem    = $snap['mem']    !== null ? (float)$snap['mem']    : null;
    $liveDisk   = $snap['disk_c'] !== null ? (float)$snap['disk_c'] : null;
    $liveUptime = $snap['uptime_secs'] !== null ? (int)$snap['uptime_secs'] : null;
    $liveUser   = ($snap['logged_user'] !== '') ? $snap['logged_user'] : null;
} else {
    $liveSrc    = 'none';
    $liveOnline = agent_is_online($agent);
    $liveCpu    = null;
    $liveMem    = null;
    $liveDisk   = null;
    $liveUptime = null;
    $liveUser   = ($agent['last_user'] !== '') ? $agent['last_user'] : null;
}
$liveAge = ($snap && !empty($snap['sampled_at']))
    ? max(0, time() - strtotime($snap['sampled_at'] . ' UTC'))
    : null;

// Humanize uptime for the server-rendered tile (mirrors humanUptime() in app.js).
$fmtUp = static function (?int $s): string {
    if ($s === null) return '—';
    $s = max(0, $s);
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    if ($d > 0) return $d . 'd ' . $h . 'h';
    if ($h > 0) return $h . 'h ' . $m . 'm';
    if ($m > 0) return $m . 'm';
    return '<1m';
};
// Trim trailing zero from a percentage (42.0 -> "42", 42.5 -> "42.5").
$fmtPct = static function (?float $v): string {
    if ($v === null) return '—';
    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
};
// Humanize a "seconds ago" age (mirrors humanAge() in app.js).
$fmtAge = static function (?int $s): string {
    if ($s === null) return '';
    $s = max(0, $s);
    if ($s < 60)    return $s . 's ago';
    if ($s < 3600)  return intdiv($s, 60) . 'm ago';
    if ($s < 86400) return intdiv($s, 3600) . 'h ago';
    return intdiv($s, 86400) . 'd ago';
};
// A snapshot older than ~2.5 heartbeats is "stale" — flagged visually, not shown as "live".
$staleAfter = max(60, (int)$agent['heartbeat_secs']) * 2.5;
$liveStale  = ($liveSrc === 'snapshot' && $liveAge !== null && $liveAge > $staleAfter);

$online = agent_is_online($agent);
$csrf = csrf_token();

// ── Auto-update / targeted-push state for the System card.
$au           = cfg('agent_update', []);
$auEnabled    = !empty($au['enabled']);
$globalTarget = trim((string)($au['target_version'] ?? ''));
$curVer       = (string)($agent['agent_version'] ?? '');
$devTarget    = trim((string)($agent['target_version'] ?? ''));
$isAdmin      = (($user['role'] ?? '') === 'admin');
// "update pending" = this device has a per-device target ahead of its current version.
$updatePending = ($devTarget !== '' && version_compare($devTarget, $curVer === '' ? '0' : $curVer, '>'));
// The button is shown only when an admin can actually act: feature on, a global target set,
// and that global target is strictly newer than what this agent is currently running.
$canPushUpdate = $isAdmin && $auEnabled && $globalTarget !== ''
    && version_compare($globalTarget, $curVer === '' ? '0' : $curVer, '>');

layout_header($agent['display_name'] ?: $agent['hostname'], $user);
?>
<div class="page-head">
  <h2>
    <span class="dot <?= $online ? 'dot-on':'dot-off' ?>"></span>
    <?= e($agent['display_name'] ?: $agent['hostname']) ?>
  </h2>
  <div>
    <?php if ($online && $agent['rustdesk_id']): ?>
      <a class="btn-primary" href="remote.php?id=<?= $id ?>">Remote In</a>
    <?php endif; ?>
    <a class="btn-ghost" href="index.php">&larr; Back</a>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>

<section class="card mp-live" data-agent-live="<?= $id ?>" data-heartbeat="<?= (int)$agent['heartbeat_secs'] ?>">
  <h3>Live
    <span class="mp-fresh<?= $liveStale ? ' mp-stale' : '' ?>" data-live-fresh><?php
      if ($liveSrc === 'realtime') { echo 'live'; }
      elseif ($liveSrc === 'snapshot') { echo 'as of ' . e($fmtAge($liveAge)); }
      else { echo '—'; }
    ?></span>
    <span class="dot <?= $liveOnline ? 'dot-on' : 'dot-off' ?>" data-live-dot></span>
  </h3>
  <?php
    // Always render the tile grid (with em-dash placeholders when a value is null) so the
    // 7s poller can fill it in place — including the brand-new agent / fresh-DB case, where
    // the page starts in 'none' and becomes live with no reload. The hint below is just
    // toggled. Each metric tile carries a (possibly hidden) unit span the poller reconciles.
    $tiles = [['cpu', 'CPU', $liveCpu], ['mem', 'Memory', $liveMem], ['disk_c', 'Disk C:', $liveDisk]];
  ?>
  <div class="mp-live-grid">
    <?php foreach ($tiles as [$k, $lbl, $val]): ?>
      <div class="mp-tile">
        <div class="mp-tile-label"><?= $lbl ?></div>
        <div class="mp-tile-val"><span data-metric="<?= $k ?>"><?= e($fmtPct($val)) ?></span><span class="mp-unit" data-unit="<?= $k ?>"<?= $val === null ? ' hidden' : '' ?>>%</span></div>
        <div class="mp-bar"><span class="mp-bar-fill" data-bar="<?= $k ?>" style="width:<?= $val !== null ? max(0, min(100, $val)) : 0 ?>%"></span></div>
      </div>
    <?php endforeach; ?>
    <div class="mp-tile mp-tile-nobar">
      <div class="mp-tile-label">Uptime</div>
      <div class="mp-tile-val"><span data-metric="uptime"><?= e($fmtUp($liveUptime)) ?></span></div>
    </div>
    <div class="mp-tile mp-tile-nobar">
      <div class="mp-tile-label">Logged-in user</div>
      <div class="mp-tile-val mp-user"><span data-metric="user"><?= $liveUser !== null ? e($liveUser) : '—' ?></span></div>
    </div>
  </div>
  <p class="muted small mp-live-hint" data-live-empty<?= $liveSrc === 'none' ? '' : ' hidden' ?>>No live metrics yet — enable the real-time backend (DEPLOYMENT.md Part&nbsp;6) or wait for the next check-in.</p>
</section>

<section class="card mp-chart-card" data-metric-history="<?= $id ?>">
  <h3>Performance
    <span class="mp-range" role="group" aria-label="Time range">
      <button type="button" data-range="6h" aria-pressed="false">6h</button>
      <button type="button" data-range="24h" class="active" aria-pressed="true">24h</button>
      <button type="button" data-range="7d" aria-pressed="false">7d</button>
    </span>
  </h3>
  <div class="mp-legend" data-chart-legend></div>
  <div class="mp-chart" data-chart-canvas></div>
  <div class="mp-extras" data-chart-extras hidden></div>
  <p class="muted small mp-chart-empty" data-chart-empty hidden>No performance history yet — samples accrue about once a minute while the agent is online.</p>
</section>

<?php
$pPending = $patch ? (int)$patch['pending_count']  : 0;
$pCrit    = $patch ? (int)$patch['critical_count'] : 0;
$pReboot  = $patch && (int)$patch['reboot_pending'] === 1;
$pComp    = ($patch && $patch['compliance_pct'] !== null) ? (float)$patch['compliance_pct'] : null;
$pList    = $patch ? (json_decode((string)$patch['pending_json'], true) ?: []) : [];
?>
<section class="card">
  <div class="card-head">
    <h3>Patch status<?php if ($patch): ?> <small class="muted">scanned <?= e(time_ago($patch['last_scan_at'])) ?></small><?php endif; ?></h3>
    <?php if (patch_enabled()): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="patch_scan">
        <button class="btn-sm btn-ghost">Scan now</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!patch_enabled()): ?>
    <p class="muted">Patch management is off. Enable it in <code>config.php</code> (<code>patch.enabled</code>) to collect Windows Update status.</p>
  <?php elseif (!$patch): ?>
    <p class="muted">No scan reported yet — a patch-capable agent (v1.2.0+) reports within a minute of check-in.</p>
  <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
      <span class="pill"><b><?= $pPending === 0 ? 'Up to date' : $pPending . ' pending' ?></b></span>
      <?php if ($pCrit > 0): ?><span class="tag tag-sev-critical"><?= $pCrit ?> critical/security</span><?php endif; ?>
      <?php if ($pReboot): ?><span class="tag tag-sev-warning">reboot pending</span><?php endif; ?>
      <?php if ($pComp !== null): ?><span class="pill">compliance <b><?= e(rtrim(rtrim(number_format($pComp, 1), '0'), '.')) ?>%</b></span><?php endif; ?>
    </div>
    <?php if ($pList): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="patch_install">
        <table class="grid mini" style="margin-top:14px">
          <thead><tr><th style="width:34px"></th><th>Update</th><th>KB</th><th>Classification</th><th>Severity</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($pList, 0, 100) as $u): $ukb = strtoupper(trim((string)($u['kb'] ?? ''))); ?>
            <tr>
              <td><?php if (preg_match('/^KB[0-9]{4,10}$/', $ukb)): ?><input type="checkbox" name="kb[]" value="<?= e($ukb) ?>"><?php endif; ?></td>
              <td><?= e((string)($u['title'] ?? '')) ?></td>
              <td class="muted"><?= e((string)($u['kb'] ?? '')) ?></td>
              <td class="muted small"><?= e((string)($u['classification'] ?? '')) ?></td>
              <td class="muted small"><?= e((string)($u['severity'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
          <div class="rule-editor-actions">
            <button class="btn-sm btn-primary" onclick="return confirm('Install the selected updates on this device now? It runs in the background and may require a reboot afterward.');">Install selected</button>
            <span class="muted small">Installs run in the background (can take minutes). Reboot is manual — use Run a command → restart.</span>
          </div>
        <?php else: ?>
          <p class="muted small" style="margin-top:10px">Only admins can install updates.</p>
        <?php endif; ?>
      </form>
    <?php endif; ?>
    <?php $pInstalled = patch_installed_kbs_for_agent($id); if ($pInstalled && ($user['role'] ?? '') === 'admin'): ?>
      <details style="margin-top:16px">
        <summary class="muted small" style="cursor:pointer">Roll back — updates Milepost installed here (<?= count($pInstalled) ?>)</summary>
        <p class="muted small" style="margin:8px 0 4px">Best-effort uninstall (DISM, then wusa). Servicing-stack, feature, and many cumulative updates
          <b>cannot be removed</b> — the result reports that per-KB (fix-forward is the primary path). If this device is in a
          running rollout, pause it first so the update isn't re-installed.</p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="patch_rollback">
          <table class="grid mini">
            <thead><tr><th style="width:34px"></th><th>KB</th><th>Installed</th></tr></thead>
            <tbody>
            <?php foreach ($pInstalled as $u): ?>
              <tr>
                <td><input type="checkbox" name="kb[]" value="<?= e($u['kb']) ?>"></td>
                <td class="muted"><?= e($u['kb']) ?></td>
                <td class="muted small"><?= e(time_ago($u['at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <div class="rule-editor-actions">
            <button class="btn-sm btn-ghost" onclick="return confirm('Roll back the selected updates on this device? Best-effort — some updates cannot be uninstalled — and it may require a reboot afterward.');">Roll back selected</button>
          </div>
        </form>
      </details>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php
$wg     = winget_status($id);   // Phase 3: latest winget upgrade scan (null if none / feature off)
$wgList = $wg ? (json_decode((string)$wg['apps_json'], true) ?: []) : [];
?>
<section class="card">
  <div class="card-head">
    <h3>Third-party apps<?php if ($wg): ?> <small class="muted">scanned <?= e(time_ago($wg['last_scan_at'])) ?></small><?php endif; ?></h3>
    <?php if (winget_enabled()): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="winget_scan">
        <button class="btn-sm btn-ghost">Scan now</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!winget_enabled()): ?>
    <p class="muted">Third-party app patching (winget) is off. Enable it in <code>config.php</code> (<code>winget.enabled</code>) to collect app-upgrade status.</p>
  <?php elseif (!$wg): ?>
    <p class="muted">No winget scan reported yet — a winget-capable agent (v1.5.0+) reports within a minute of check-in.</p>
  <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
      <span class="pill"><b><?= count($wgList) === 0 ? 'All apps up to date' : count($wgList) . ' upgrade' . (count($wgList) === 1 ? '' : 's') . ' available' ?></b></span>
    </div>
    <?php if ($wgList): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="winget_install">
        <table class="grid mini" style="margin-top:14px">
          <thead><tr><th style="width:34px"></th><th>App</th><th>Id</th><th>Installed</th><th>Available</th><th>Source</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($wgList, 0, 200) as $a): $aid = (string)($a['id'] ?? ''); ?>
            <tr>
              <td><?php if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-+_]{0,127}$/', $aid)): ?><input type="checkbox" name="appid[]" value="<?= e($aid) ?>"><?php endif; ?></td>
              <td><?= e((string)($a['name'] ?? '')) ?></td>
              <td class="muted small"><?= e($aid) ?></td>
              <td class="muted small"><?= e((string)($a['version'] ?? '')) ?></td>
              <td class="muted small"><?= e((string)($a['available'] ?? '')) ?></td>
              <td class="muted small"><?= e((string)($a['source'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
          <div class="rule-editor-actions">
            <button class="btn-sm btn-primary" onclick="return confirm('Upgrade the selected apps on this device now? Runs in the background via winget.');">Upgrade selected</button>
            <span class="muted small">winget runs in machine context (LocalSystem); user-scoped installs may not appear.</span>
          </div>
        <?php else: ?>
          <p class="muted small" style="margin-top:10px">Only admins can upgrade apps.</p>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</section>

<div class="cols">
  <section class="card">
    <h3>System</h3>
    <dl class="kv">
      <dt>Status</dt><dd><?= $online ? 'Online' : 'Offline' ?> · <?= e(time_ago($agent['last_seen_at'])) ?></dd>
      <dt>Hostname</dt><dd><?= e($agent['hostname']) ?></dd>
      <dt>Client</dt><dd><?= e($agent['client_name'] ?? '—') ?></dd>
      <dt>Logged-in user</dt><dd><?= e($agent['last_user'] ?: '—') ?></dd>
      <dt>OS</dt><dd><?= e($agent['os_name']) ?> <?= e($agent['os_version']) ?></dd>
      <dt>Public IP</dt><dd><?= e($agent['public_ip'] ?: '—') ?></dd>
      <dt>Local IP</dt><dd><?= e($agent['local_ip'] ?: '—') ?></dd>
      <dt>Agent ver.</dt><dd>
        <?= e($curVer ?: '—') ?>
        <?php if ($auEnabled && $globalTarget !== ''): ?>
          <?php if ($updatePending): ?>
            <span class="tag tag-queued">update pending → <?= e($devTarget) ?></span>
          <?php elseif (version_compare($globalTarget, $curVer === '' ? '0' : $curVer, '>')): ?>
            <span class="muted small">target <?= e($globalTarget) ?></span>
          <?php else: ?>
            <span class="muted small">up to date</span>
          <?php endif; ?>
        <?php endif; ?>
      </dd>
      <dt>RustDesk ID</dt><dd><?= e($agent['rustdesk_id'] ?: 'not reported') ?></dd>
    </dl>
    <?php if ($canPushUpdate): ?>
    <form method="post" class="inline-form" onsubmit="return confirm('Push agent update to <?= e($globalTarget) ?> on this endpoint?');">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="update_agent_version">
      <button class="btn-sm btn-primary">Update to latest (<?= e($globalTarget) ?>)</button>
      <p class="muted small">Sets this endpoint's target version. It self-updates on its next check-in.</p>
    </form>
    <?php endif; ?>
    <form method="post" class="inline-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="update_agent">
      <label>Display name<input name="display_name" value="<?= e($agent['display_name']) ?>"></label>
      <label>Client
        <select name="client_id">
          <option value="">— Unassigned —</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $c['id']==$agent['client_id']?'selected':'' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn-sm btn-primary">Save</button>
    </form>
  </section>

  <section class="card">
    <h3>Run a command</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="queue_job">
      <label>Type
        <select name="job_type" id="job_type">
          <option value="powershell">PowerShell</option>
          <option value="cmd">CMD</option>
          <option value="restart">Restart computer</option>
        </select>
      </label>
      <label id="payload_wrap">Command
        <textarea name="payload" rows="4" placeholder="e.g. Get-Service | Where-Object Status -eq 'Stopped'"></textarea>
      </label>
      <button class="btn-primary">Queue command</button>
      <p class="muted small">Runs as SYSTEM on the agent's next check-in (≤ <?= (int)$agent['heartbeat_secs'] ?>s).</p>
    </form>
  </section>
</div>

<section class="card">
  <h3>Recent commands</h3>
  <table class="grid jobs-table" data-agent="<?= $id ?>">
    <thead><tr><th>#</th><th>Type</th><th>Status</th><th>Exit</th><th>When</th><th>Output</th></tr></thead>
    <tbody>
    <?php foreach ($jobRows as $j): ?>
      <tr data-job="<?= (int)$j['id'] ?>">
        <td><?= (int)$j['id'] ?></td>
        <td><?= e($j['job_type']) ?></td>
        <td><span class="tag tag-<?= e($j['status']) ?>"><?= e($j['status']) ?></span></td>
        <td><?= $j['exit_code'] === null ? '—' : (int)$j['exit_code'] ?></td>
        <td class="muted"><?= e(time_ago($j['created_at'])) ?></td>
        <td><?php if ($j['output'] !== null && $j['output'] !== ''): ?>
          <details><summary>view</summary><pre class="out"><?= e($j['output']) ?></pre></details>
        <?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h3>Inventory <?php if ($invRow): ?><small class="muted">updated <?= e(time_ago($invRow['updated_at'])) ?></small><?php endif; ?></h3>
  <?php if (!$inventory): ?>
    <p class="muted">No inventory reported yet.</p>
  <?php else: ?>
    <?php require __DIR__ . '/../lib/inventory_view.php'; render_inventory($inventory); ?>
  <?php endif; ?>
</section>

<section class="card danger">
  <form method="post" onsubmit="return confirm('Archive this agent? It will disappear from the dashboard.');">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="archive">
    <button class="btn-sm btn-danger">Archive agent</button>
  </form>
</section>
<?php layout_footer();
