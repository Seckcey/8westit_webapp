<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/realtime.php';
enforce_https();
$user = require_login();

$rows = db()->query(
    'SELECT a.*, c.name AS client_name
       FROM agents a LEFT JOIN clients c ON c.id = a.client_id
      WHERE a.is_archived = 0
      ORDER BY c.name IS NULL, c.name, a.site, a.hostname'
)->fetchAll();

// One batched presence read so the initial paint reflects real-time online status
// (self-guards; [] when rt disabled / backend down — then we fall back to the heuristic).
$allIds = array_map(static fn($r) => (int)$r['id'], $rows);
$live = rt_enabled() ? rt_presence($allIds) : [];

/** Online if the agent holds a live real-time connection OR was seen recently (polling).
 *  Real-time presence only ADDS online-ness — it must never demote a polling-online agent:
 *  the backend returns {online:false} for every agent that isn't currently RT-connected, so a
 *  plain "prefer RT" would wrongly gray out healthy poll-only / older agents. */
function row_online(array $r, array $live): bool {
    $p = $live[(int)$r['id']] ?? null;
    return ($p !== null && !empty($p['online'])) || agent_is_online($r);
}

$total = count($rows); $online = 0;
foreach ($rows as $r) if (row_online($r, $live)) $online++;

// Group: client -> site -> [agents]. Precompute _online onto each row so agent_row()
// can read it without changing its signature.
$groups = [];
foreach ($rows as $r) {
    $r['_online'] = row_online($r, $live);
    $cn = $r['client_name'] ?? 'Unassigned';
    $site = (string)($r['site'] ?? '');
    if (!isset($groups[$cn])) $groups[$cn] = ['online' => 0, 'total' => 0, 'sites' => []];
    $groups[$cn]['sites'][$site][] = $r;
    $groups[$cn]['total']++;
    if ($r['_online']) $groups[$cn]['online']++;
}

/** Render one agent row (shared markup so live-refresh JS can target it). */
function agent_row(array $r): void {
    $on = $r['_online'] ?? agent_is_online($r); ?>
    <tr>
      <td><span class="dot <?= $on ? 'dot-on' : 'dot-off' ?>"></span></td>
      <td><a href="agent.php?id=<?= (int)$r['id'] ?>"><?= e($r['display_name'] ?: $r['hostname']) ?></a></td>
      <td><?= e($r['last_user'] ?: '—') ?></td>
      <td><?= e($r['os_name']) ?></td>
      <td><?php foreach (array_filter(array_map('trim', explode(',', (string)($r['tags'] ?? '')))) as $t): ?><span class="tag"><?= e($t) ?></span> <?php endforeach; ?></td>
      <td><?= e($r['public_ip'] ?: '—') ?></td>
      <td class="muted cell-lastseen"><?= e(time_ago($r['last_seen_at'])) ?></td>
      <td><?php if ($on && $r['rustdesk_id']): ?>
            <a class="btn-sm btn-primary" href="remote.php?id=<?= (int)$r['id'] ?>">Remote&nbsp;In</a>
          <?php else: ?>
            <a class="btn-sm btn-ghost" href="agent.php?id=<?= (int)$r['id'] ?>">Open</a>
          <?php endif; ?></td>
    </tr>
<?php }

layout_header('Dashboard', $user);
?>
<div class="page-head">
  <h2>Dashboard</h2>
  <div class="stat-pills">
    <span class="pill pill-on"><b><?= $online ?></b> online</span>
    <span class="pill pill-off"><b><?= $total - $online ?></b> offline</span>
    <span class="pill"><b><?= $total ?></b> total</span>
  </div>
</div>

<?php if ($total === 0): ?>
  <div class="empty">
    <p>No computers enrolled yet.</p>
    <p>Go to <a href="deploy.php">Deploy Agent</a>, create a deployment for a client, and send or scan
       the download link on the computer you're setting up.</p>
  </div>
<?php else:
  $colspan = 8;
  foreach ($groups as $clientName => $g): ?>
  <section class="card folder" data-poll="1">
    <details open>
      <summary>
        <span class="folder-ico">📁</span>
        <span class="folder-name"><?= e($clientName) ?></span>
        <span class="folder-stats">
          <span class="pill pill-on"><b><?= $g['online'] ?></b> online</span>
          <span class="pill"><b><?= $g['total'] ?></b> total</span>
        </span>
      </summary>
      <?php foreach ($g['sites'] as $site => $agents): ?>
        <?php if ($site !== ''): ?><h4 class="site-head">📍 <?= e($site) ?></h4><?php endif; ?>
        <table class="grid agents-grid">
          <thead><tr><th></th><th>Computer</th><th>User</th><th>OS</th><th>Tags</th><th>Public IP</th><th>Last seen</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($agents as $r) agent_row($r); ?>
          </tbody>
        </table>
      <?php endforeach; ?>
    </details>
  </section>
<?php endforeach; ?>
<p class="muted small">Status refreshes automatically every 20 seconds.</p>
<?php endif; ?>
<?php layout_footer();
