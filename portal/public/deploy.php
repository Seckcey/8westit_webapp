<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$baseUrl = rtrim((string)cfg('base_url', ''), '/');
$flash = ''; $err = ''; $newKey = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        // Resolve client (existing or new).
        $clientId = ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null;
        $newClient = trim((string)($_POST['new_client'] ?? ''));
        if ($newClient !== '') {
            try {
                db()->prepare('INSERT INTO clients (name) VALUES (?)')->execute([$newClient]);
                $clientId = (int)db()->lastInsertId();
            } catch (PDOException $e) {
                // Name exists — reuse it.
                $s = db()->prepare('SELECT id FROM clients WHERE name = ?');
                $s->execute([$newClient]);
                $clientId = (int)$s->fetchColumn();
            }
        }
        if (!$clientId) {
            $err = 'Pick an existing client or enter a new client name.';
        } else {
            $site   = mb_substr(trim((string)($_POST['site'] ?? '')), 0, 128);
            $prefix = mb_substr(trim((string)($_POST['name_prefix'] ?? '')), 0, 32);
            $tags   = mb_substr(trim((string)($_POST['tags'] ?? '')), 0, 255);
            $bundle = isset($_POST['bundle']) ? 1 : 0;
            $expDays = (int)($_POST['expires_days'] ?? 0);
            $expires = $expDays > 0 ? gmdate('Y-m-d H:i:s', time() + $expDays * 86400) : null;
            $label  = $site !== '' ? $site : 'Deployment';
            $key    = bin2hex(random_bytes(32));

            db()->prepare(
                'INSERT INTO enrollment_keys
                   (client_id, label, site, name_prefix, tags, bundle_rustdesk, expires_at, key_value)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$clientId, $label, $site, $prefix, $tags, $bundle, $expires, $key]);
            audit((int)$user['id'], null, 'deploy_create', "client=$clientId site=$site bundle=$bundle");
            $newKey = $key;
            $flash = 'Deployment created. Share the link or QR below.';
        }
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE enrollment_keys SET is_active = 1 - is_active WHERE id = ?')
            ->execute([(int)($_POST['id'] ?? 0)]);
        $flash = 'Updated.';
    }
}

$clients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();
$keys = db()->query(
    'SELECT k.*, c.name AS client_name FROM enrollment_keys k
       LEFT JOIN clients c ON c.id = k.client_id
      ORDER BY k.is_active DESC, k.created_at DESC'
)->fetchAll();
$csrf = csrf_token();
layout_header('Agent Deployment', $user);
?>
<div class="page-head"><h2>Agent Deployment</h2></div>
<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>

<?php if ($newKey): $url = $baseUrl . '/download.php?k=' . $newKey; ?>
<section class="card deploy-ready">
  <h3>✅ Deployment ready</h3>
  <p class="muted">Send this link to the client, or scan the QR on the computer you're setting up.
     Download &amp; run the single <code>.msi</code> — it installs and auto-enrolls under the client. No extra steps.</p>
  <div class="deploy-share">
    <div class="qr" id="qr-new"></div>
    <div class="deploy-link">
      <label>Download link</label>
      <div class="copy-row"><code id="dl-url"><?= e($url) ?></code>
        <button class="btn-sm btn-ghost" data-copy="dl-url">Copy</button></div>
      <p><a class="btn-primary" href="<?= e($url) ?>">Download the installer</a></p>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="card">
  <h3>New deployment</h3>
  <form method="post" class="deploy-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="create">
    <div class="form-grid">
      <label>Client
        <select name="client_id" id="client_id">
          <option value="">— choose existing —</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>…or new client
        <input name="new_client" placeholder="Tanner Engineering">
      </label>
      <label>Site / location <span class="opt">(optional)</span>
        <input name="site" placeholder="Main Office">
      </label>
      <label>Computer name prefix <span class="opt">(optional)</span>
        <input name="name_prefix" placeholder="TANNER-">
      </label>
      <label>Tags <span class="opt">(optional, comma-separated)</span>
        <input name="tags" placeholder="workstation, reception">
      </label>
      <label>Deployment link expires
        <select name="expires_days">
          <option value="0">Never</option>
          <option value="7">In 7 days</option>
          <option value="30">In 30 days</option>
          <option value="90">In 90 days</option>
        </select>
      </label>
    </div>
    <label class="check"><input type="checkbox" name="bundle" checked>
      Include remote-support client in the installer
      <span class="opt">(~24&nbsp;MB, works on offline/locked-down networks; uncheck for a ~1&nbsp;MB installer that fetches it on first run)</span>
    </label>
    <button class="btn-primary">Generate deployment</button>
  </form>
</section>

<section class="card">
  <h3>Deployments</h3>
  <table class="grid">
    <thead><tr><th>Client</th><th>Site</th><th>Options</th><th>Installs</th><th>Link</th><th></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($keys as $k):
        $url = $baseUrl . '/download.php?k=' . $k['key_value'];
        $expd = !empty($k['expires_at']);
        $expired = $expd && strtotime($k['expires_at'] . ' UTC') < time(); ?>
      <tr class="<?= $k['is_active'] ? '' : 'row-off' ?>">
        <td><?= e($k['client_name'] ?? '—') ?></td>
        <td><?= e($k['site'] ?: '—') ?></td>
        <td class="small muted">
          <?= ((int)($k['bundle_rustdesk'] ?? 1) === 1) ? 'Full (RustDesk)' : 'Lite' ?>
          <?php if (!empty($k['name_prefix'])): ?> · prefix <code><?= e($k['name_prefix']) ?></code><?php endif; ?>
          <?php if ($expd): ?> · <?= $expired ? '<span class="tag tag-error">expired</span>' : 'expires ' . e(date('M j', strtotime($k['expires_at'].' UTC'))) ?><?php endif; ?>
          <?php if (!$k['is_active']): ?> · <span class="tag">disabled</span><?php endif; ?>
        </td>
        <td><?= (int)($k['use_count'] ?? 0) ?></td>
        <td><code class="key" id="u<?= (int)$k['id'] ?>"><?= e($url) ?></code>
            <button class="btn-sm btn-ghost" data-copy="u<?= (int)$k['id'] ?>">Copy</button></td>
        <td><button class="btn-sm btn-ghost qr-btn" data-url="<?= e($url) ?>">QR</button></td>
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
    <?php if (!$keys): ?><tr><td colspan="7" class="muted">No deployments yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<!-- QR modal -->
<div id="qr-modal" class="qr-modal" hidden>
  <div class="qr-modal-box">
    <div id="qr-modal-canvas"></div>
    <p class="muted small">Scan on the computer you're setting up.</p>
    <button class="btn-sm btn-ghost" id="qr-close">Close</button>
  </div>
</div>

<script src="assets/js/qrcode.min.js"></script>
<script>
(function(){
  // Inline QR for a freshly-created deployment.
  var qn = document.getElementById('qr-new');
  if (qn && window.QRCode) new QRCode(qn, { text: <?= json_encode($newKey ? ($baseUrl.'/download.php?k='.$newKey) : '') ?>, width:180, height:180 });

  // QR modal for any row.
  var modal = document.getElementById('qr-modal');
  var canvas = document.getElementById('qr-modal-canvas');
  var current = null;
  document.addEventListener('click', function(ev){
    var b = ev.target.closest('.qr-btn');
    if (b) {
      canvas.innerHTML = '';
      if (current) current = null;
      new QRCode(canvas, { text: b.getAttribute('data-url'), width:200, height:200 });
      modal.hidden = false;
    }
    if (ev.target.id === 'qr-close' || ev.target === modal) modal.hidden = true;
  });
})();
</script>
<?php layout_footer();
