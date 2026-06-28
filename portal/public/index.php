<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$rows = db()->query(
    'SELECT a.*, c.name AS client_name
       FROM agents a LEFT JOIN clients c ON c.id = a.client_id
      WHERE a.is_archived = 0
      ORDER BY c.name IS NULL, c.name, a.hostname'
)->fetchAll();

$total = count($rows);
$online = 0;
foreach ($rows as $r) if (agent_is_online($r)) $online++;

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
    <p>No agents enrolled yet.</p>
    <p>Go to <a href="enroll-keys.php">Agents &amp; Keys</a> to create an enrollment key, then build and
       install the agent MSI on a client computer.</p>
  </div>
<?php else: ?>
<table class="grid" id="agents-grid" data-poll="1">
  <thead><tr>
    <th></th><th>Computer</th><th>Client</th><th>User</th>
    <th>OS</th><th>Public IP</th><th>Last seen</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
        $on = agent_is_online($r); ?>
    <tr>
      <td><span class="dot <?= $on ? 'dot-on' : 'dot-off' ?>" title="<?= $on ? 'Online' : 'Offline' ?>"></span></td>
      <td><a href="agent.php?id=<?= (int)$r['id'] ?>"><?= e($r['display_name'] ?: $r['hostname']) ?></a></td>
      <td><?= e($r['client_name'] ?? '—') ?></td>
      <td><?= e($r['last_user'] ?: '—') ?></td>
      <td><?= e($r['os_name']) ?></td>
      <td><?= e($r['public_ip'] ?: '—') ?></td>
      <td class="muted"><?= e(time_ago($r['last_seen_at'])) ?></td>
      <td>
        <?php if ($on && $r['rustdesk_id']): ?>
          <a class="btn-sm btn-primary" href="remote.php?id=<?= (int)$r['id'] ?>">Remote&nbsp;In</a>
        <?php else: ?>
          <a class="btn-sm btn-ghost" href="agent.php?id=<?= (int)$r['id'] ?>">Open</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="muted small">Status refreshes automatically every 20 seconds.</p>
<?php endif; ?>
<?php layout_footer();
