<?php
/** Automation — Phase 4. Increment 1: the Script library (admin-managed reusable scripts). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/scripts.php';
enforce_https();
$user    = require_login();
$csrf    = csrf_token();
$isAdmin = ($user['role'] ?? '') === 'admin';
$flash   = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if (!$isAdmin) {
        $flash = 'Only admins can manage scripts.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'script_save') {
            $r = script_save((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), (string)($_POST['description'] ?? ''),
                             (string)($_POST['language'] ?? 'powershell'), (string)($_POST['body'] ?? ''), (int)$user['id']);
            if ($r['ok']) { audit((int)$user['id'], null, 'script_save', 'script#' . $r['id']); $flash = 'Script saved.'; }
            else { $flash = $r['error']; }
        } elseif ($action === 'script_delete') {
            $sid = (int)($_POST['id'] ?? 0);
            if ($sid > 0) { script_delete($sid); audit((int)$user['id'], null, 'script_delete', "script#$sid"); $flash = 'Script deleted.'; }
        }
    }
}

$scripts = scripts_list();
$editing = (($eid = (int)($_GET['edit'] ?? 0)) > 0 && $isAdmin) ? script_get($eid) : null;

layout_header('Automation', $user);
?>
<div class="page-head"><h2>Automation</h2></div>
<?php if ($flash): ?><div class="alert"><?= e($flash) ?></div><?php endif; ?>

<section class="card">
  <div class="card-head"><h3>Script library</h3></div>
  <p class="muted small">Reusable PowerShell / cmd scripts. Run a saved script on a device from its device page ("Run saved script"). Admin-managed.</p>
  <?php if (!$scripts): ?>
    <p class="muted small">No scripts yet.</p>
  <?php else: ?>
    <table class="grid mini">
      <thead><tr><th>Name</th><th>Lang</th><th>Description</th><th>Ver</th><th>Runs</th><th>Updated</th><?php if ($isAdmin): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($scripts as $s): ?>
        <tr>
          <td><b><?= e($s['name']) ?></b></td>
          <td class="muted small"><?= e($s['language']) ?></td>
          <td class="muted small"><?= e($s['description']) ?></td>
          <td class="muted small">v<?= (int)$s['version'] ?></td>
          <td class="muted small"><?= (int)$s['run_count'] ?></td>
          <td class="muted small"><?= e(time_ago($s['updated_at'])) ?></td>
          <?php if ($isAdmin): ?>
          <td style="white-space:nowrap">
            <a class="btn-sm btn-ghost" href="automation.php?edit=<?= (int)$s['id'] ?>">Edit</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this script?')">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="script_delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
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
    <input type="hidden" name="action" value="script_save">
    <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">
    <div class="rule-editor-row">
      <label>Name<input name="name" value="<?= $editing ? e($editing['name']) : '' ?>" placeholder="Clear temp files" required></label>
      <label>Language<select name="language">
        <option value="powershell"<?= $editing && $editing['language'] === 'powershell' ? ' selected' : '' ?>>PowerShell</option>
        <option value="cmd"<?= $editing && $editing['language'] === 'cmd' ? ' selected' : '' ?>>cmd</option>
      </select></label>
      <label style="flex:1">Description<input name="description" value="<?= $editing ? e($editing['description']) : '' ?>" placeholder="optional"></label>
    </div>
    <div class="rule-editor-row">
      <label style="flex:1;display:block">Script<textarea name="body" rows="10" style="width:100%;font-family:ui-monospace,Consolas,monospace" placeholder="Write-Output 'hello'"><?= $editing ? e($editing['body']) : '' ?></textarea></label>
    </div>
    <div class="rule-editor-actions">
      <button class="btn-sm btn-primary"><?= $editing ? 'Save changes' : 'Add script' ?></button>
      <?php if ($editing): ?><a class="btn-sm btn-ghost" href="automation.php">Cancel</a><?php endif; ?>
      <span class="muted small">Runs on the device as the agent account (LocalSystem). Test on a canary before fleet use.</span>
    </div>
  </form>
  <?php endif; ?>
</section>
<?php layout_footer();
