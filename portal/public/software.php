<?php
/** Software inventory (fleet aggregate) + license tracking. Session-authed; license CRUD admin-only. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/software.php';
enforce_https();
$user    = require_login();
$csrf    = csrf_token();
$isAdmin = ($user['role'] ?? '') === 'admin';
$flash   = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if (!$isAdmin) {
        $flash = 'Only admins can manage licenses.';
    } elseif ($action === 'license_save') {
        $product = mb_substr(trim((string)($_POST['product'] ?? '')), 0, 160);
        if ($product === '') {
            $flash = 'Product name is required.';
        } else {
            $lid    = (int)($_POST['id'] ?? 0);
            $vendor = mb_substr(trim((string)($_POST['vendor'] ?? '')), 0, 120);
            $match  = mb_substr(trim((string)($_POST['match_name'] ?? '')), 0, 160);
            $seats  = max(0, (int)($_POST['seats'] ?? 0));
            $key    = mb_substr(trim((string)($_POST['license_key'] ?? '')), 0, 255);
            $notes  = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500);
            $exp    = trim((string)($_POST['expires_at'] ?? ''));
            $exp    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : null;
            if ($lid > 0) {
                db()->prepare('UPDATE software_licenses SET product=?,vendor=?,match_name=?,seats=?,license_key=?,expires_at=?,notes=? WHERE id=?')
                    ->execute([$product, $vendor, $match, $seats, $key, $exp, $notes, $lid]);
            } else {
                db()->prepare('INSERT INTO software_licenses (product,vendor,match_name,seats,license_key,expires_at,notes,created_by) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$product, $vendor, $match, $seats, $key, $exp, $notes, (int)$user['id']]);
                $lid = (int)db()->lastInsertId();
            }
            audit((int)$user['id'], null, 'license_save', "license#$lid $product");
            $flash = 'License saved.';
        }
    } elseif ($action === 'license_delete') {
        $lid = (int)($_POST['id'] ?? 0);
        if ($lid > 0) {
            db()->prepare('DELETE FROM software_licenses WHERE id=?')->execute([$lid]);
            audit((int)$user['id'], null, 'license_delete', "license#$lid");
            $flash = 'License deleted.';
        }
    }
}

$q        = trim((string)($_GET['q'] ?? ''));
$software = software_fleet_inventory($q !== '' ? $q : null, 500);
$licenses = licenses_list();

layout_header('Software', $user);
?>
<div class="page-head"><h2>Software</h2></div>
<?php if ($flash): ?><div class="alert"><?= e($flash) ?></div><?php endif; ?>

<section class="card">
  <div class="card-head"><h3>Licenses</h3></div>
  <p class="muted small">Track paid licenses. "Seats used" is auto-counted from installed-software inventory via the match text (a case-insensitive substring of the app name).</p>
  <?php if (!$licenses): ?>
    <p class="muted small">No licenses tracked yet.</p>
  <?php else: ?>
    <table class="grid mini">
      <thead><tr><th>Product</th><th>Vendor</th><th>Match</th><th>Seats</th><th>Expires</th><th>Notes</th><?php if ($isAdmin): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($licenses as $l):
        $seats = (int)$l['seats']; $used = (int)$l['seats_used'];
        $over  = $seats > 0 && $used > $seats;
        $exp   = $l['expires_at'];
        $expd  = $exp && strtotime($exp . ' 23:59:59 UTC') < time();
        $soon  = $exp && !$expd && strtotime($exp . ' 23:59:59 UTC') < time() + 30 * 86400;
        $licJson = ['id'=>(int)$l['id'],'product'=>$l['product'],'vendor'=>$l['vendor'],'match_name'=>$l['match_name'],'seats'=>$seats,'expires_at'=>$exp ?: '','license_key'=>$l['license_key'],'notes'=>$l['notes']];
      ?>
        <tr>
          <td><b><?= e($l['product']) ?></b></td>
          <td class="muted small"><?= e($l['vendor']) ?></td>
          <td class="muted small"><?= e($l['match_name']) ?></td>
          <td><?php if ($over): ?><span class="tag tag-sev-critical"><?= $used ?> / <?= $seats ?></span><?php else: ?><?= $used ?><?= $seats > 0 ? ' / ' . $seats : '' ?><?php endif; ?></td>
          <td><?php if ($exp): ?><span class="<?= $expd ? 'tag tag-sev-critical' : ($soon ? 'tag tag-sev-warning' : 'muted small') ?>"><?= e($exp) ?></span><?php else: ?><span class="muted small">—</span><?php endif; ?></td>
          <td class="muted small"><?= e($l['notes']) ?></td>
          <?php if ($isAdmin): ?>
          <td style="white-space:nowrap">
            <button type="button" class="btn-sm btn-ghost" onclick="editLic(<?= e(json_encode($licJson, JSON_UNESCAPED_SLASHES)) ?>)">Edit</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this license?')">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="license_delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <button class="btn-sm btn-danger">Delete</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php if ($isAdmin): ?>
  <form method="post" class="rule-editor" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="license_save">
    <input type="hidden" name="id" id="lic-id" value="">
    <div class="rule-editor-row">
      <label>Product<input name="product" id="lic-product" placeholder="Adobe Acrobat Pro" required></label>
      <label>Vendor<input name="vendor" id="lic-vendor" placeholder="Adobe"></label>
      <label>Match text<input name="match_name" id="lic-match" placeholder="Acrobat"></label>
      <label>Seats<input type="number" name="seats" id="lic-seats" value="0" min="0" step="1" style="width:80px"></label>
    </div>
    <div class="rule-editor-row">
      <label>Expires<input type="date" name="expires_at" id="lic-exp"></label>
      <label>License key<input name="license_key" id="lic-key"></label>
      <label>Notes<input name="notes" id="lic-notes"></label>
    </div>
    <div class="rule-editor-actions">
      <button class="btn-sm btn-primary">Save license</button>
      <button type="button" class="btn-sm btn-ghost" onclick="resetLic()">Clear</button>
      <span class="muted small">"Match text" auto-counts seats from installed software (leave blank to track manually).</span>
    </div>
  </form>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head"><h3>Software inventory</h3></div>
  <p class="muted small">Installed applications across the fleet (from device inventory), most-installed first.</p>
  <form method="get" style="margin-bottom:10px">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filter by app name…" style="max-width:280px">
    <button class="btn-sm btn-ghost">Search</button>
  </form>
  <?php if (!$software): ?>
    <p class="muted small"><?= $q !== '' ? 'No apps match “' . e($q) . '”.' : 'No software inventory yet.' ?></p>
  <?php else: ?>
    <table class="grid mini">
      <thead><tr><th>Application</th><th>Publisher</th><th>Devices</th><th>Versions</th></tr></thead>
      <tbody>
      <?php foreach ($software as $s): ?>
        <tr>
          <td><?= e($s['name']) ?></td>
          <td class="muted small"><?= e($s['publisher']) ?></td>
          <td><?= (int)$s['devices'] ?></td>
          <td class="muted small"><?= e(implode(', ', array_slice($s['versions'], 0, 6))) . (count($s['versions']) > 6 ? ' …' : '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<script>
function editLic(l){var g=function(i){return document.getElementById(i);};g('lic-id').value=l.id;g('lic-product').value=l.product||'';g('lic-vendor').value=l.vendor||'';g('lic-match').value=l.match_name||'';g('lic-seats').value=l.seats||0;g('lic-exp').value=l.expires_at||'';g('lic-key').value=l.license_key||'';g('lic-notes').value=l.notes||'';window.scrollTo(0,document.body.scrollHeight);}
function resetLic(){['lic-id','lic-product','lic-vendor','lic-match','lic-key','lic-notes'].forEach(function(i){document.getElementById(i).value='';});document.getElementById('lic-seats').value=0;document.getElementById('lic-exp').value='';}
</script>
<?php layout_footer();
