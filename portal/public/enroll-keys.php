<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 128);
        $cid   = ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null;
        $key   = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO enrollment_keys (client_id, label, key_value) VALUES (?,?,?)')
            ->execute([$cid, $label, $key]);
        $flash = 'Key created.';
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE enrollment_keys SET is_active = 1 - is_active WHERE id=?')
            ->execute([(int)($_POST['id'] ?? 0)]);
    }
}

$keys = db()->query(
    'SELECT k.*, c.name AS client_name FROM enrollment_keys k
       LEFT JOIN clients c ON c.id=k.client_id ORDER BY k.created_at DESC'
)->fetchAll();
$clients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();
$csrf = csrf_token();
$baseUrl = cfg('base_url', '');
layout_header('Agents & Keys', $user);
?>
<div class="page-head"><h2>Agents &amp; Enrollment Keys</h2></div>
<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>

<section class="card">
  <h3>How enrollment works</h3>
  <ol class="steps">
    <li>Create an enrollment key (optionally tied to a client).</li>
    <li>Build the agent MSI with that key baked in (see <code>agent/build-agent.ps1</code>),
        or pass it at install time:
        <code>msiexec /i EightWestAgent.msi ENROLLKEY=&lt;key&gt; PORTAL=<?= e($baseUrl) ?></code></li>
    <li>Install on the client PC. It appears on the Dashboard within ~1 minute.</li>
  </ol>
</section>

<section class="card">
  <h3>Create a key</h3>
  <form method="post" class="inline-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="create">
    <label>Label<input name="label" placeholder="Acme front desk"></label>
    <label>Client
      <select name="client_id">
        <option value="">— Any / unassigned —</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn-primary">Generate</button>
  </form>
</section>

<section class="card">
  <table class="grid">
    <thead><tr><th>Label</th><th>Client</th><th>Key</th><th>Active</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($keys as $k): ?>
      <tr>
        <td><?= e($k['label'] ?: '—') ?></td>
        <td><?= e($k['client_name'] ?? 'Any') ?></td>
        <td><code class="key" id="k<?= (int)$k['id'] ?>"><?= e($k['key_value']) ?></code>
            <button class="btn-sm btn-ghost" data-copy="k<?= (int)$k['id'] ?>">Copy</button></td>
        <td><?= $k['is_active'] ? '<span class="tag tag-done">active</span>' : '<span class="tag">disabled</span>' ?></td>
        <td class="muted"><?= e(time_ago($k['created_at'])) ?></td>
        <td>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
            <button class="btn-sm btn-ghost"><?= $k['is_active'] ? 'Disable' : 'Enable' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$keys): ?><tr><td colspan="6" class="muted">No keys yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php layout_footer();
