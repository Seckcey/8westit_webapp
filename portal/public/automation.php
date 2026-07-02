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
        } elseif ($action === 'schedule_save') {
            $r = schedule_save($_POST, (int)$user['id']);
            if ($r['ok']) { audit((int)$user['id'], null, 'schedule_save', 'schedule#' . $r['id']); $flash = 'Schedule saved.'; }
            else { $flash = $r['error']; }
        } elseif ($action === 'schedule_delete') {
            $sid = (int)($_POST['id'] ?? 0);
            if ($sid > 0) { schedule_delete($sid); audit((int)$user['id'], null, 'schedule_delete', "schedule#$sid"); $flash = 'Schedule deleted.'; }
        } elseif ($action === 'schedule_toggle') {
            $sid = (int)($_POST['id'] ?? 0);
            if ($sid > 0) { schedule_set_enabled($sid, !empty($_POST['on'])); $flash = 'Schedule updated.'; }
        }
    }
}

$scripts   = scripts_list();
$schedules = schedules_list();
$editing   = (($eid = (int)($_GET['edit'] ?? 0)) > 0 && $isAdmin) ? script_get($eid) : null;
$scopes    = [
    'clients' => db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll(),
    'sites'   => db()->query('SELECT id, name FROM sites ORDER BY name')->fetchAll(),
    'groups'  => db()->query('SELECT id, name FROM device_groups ORDER BY name')->fetchAll(),
    'agents'  => db()->query("SELECT id, COALESCE(NULLIF(display_name,''), hostname) AS name FROM agents WHERE is_archived=0 ORDER BY name")->fetchAll(),
];
$DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
// scope_type => [id => name] for resolving a schedule's scope label
$scopeName = [];
foreach (['client' => 'clients', 'site' => 'sites', 'group' => 'groups', 'device' => 'agents'] as $t => $k) {
    foreach ($scopes[$k] as $row) $scopeName[$t][(int)$row['id']] = (string)$row['name'];
}
$fmtScope = static function (array $s) use ($scopeName): string {
    if ($s['scope_type'] === 'global') return 'All devices';
    $nm = $scopeName[$s['scope_type']][(int)$s['scope_id']] ?? ('#' . (int)$s['scope_id']);
    return ucfirst((string)$s['scope_type']) . ': ' . $nm;
};
$fmtCadence = static function (array $s) use ($DOW): string {
    if ($s['recurrence'] === 'interval') return 'every ' . (int)$s['interval_min'] . ' min';
    $t = substr((string)($s['at_time'] ?? '00:00:00'), 0, 5);
    return $s['recurrence'] === 'weekly' ? ('weekly ' . ($DOW[(int)$s['dow']] ?? '?') . ' ' . $t . ' UTC') : ('daily ' . $t . ' UTC');
};

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

<section class="card">
  <div class="card-head"><h3>Schedules</h3></div>
  <p class="muted small">Run a saved script on a schedule against a scope. Times are UTC. Fired by the <code>script_dispatch.php</code> cron (every minute).</p>
  <?php if (!$schedules): ?>
    <p class="muted small">No schedules yet.</p>
  <?php else: ?>
    <table class="grid mini">
      <thead><tr><th>Name</th><th>Script</th><th>Scope</th><th>Cadence</th><th>Last run</th><th>On</th><?php if ($isAdmin): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($schedules as $sc): ?>
        <tr>
          <td><b><?= e($sc['name'] !== '' ? $sc['name'] : ('#' . (int)$sc['id'])) ?></b></td>
          <td class="muted small"><?= e($sc['script_name']) ?></td>
          <td class="muted small"><?= e($fmtScope($sc)) ?></td>
          <td class="muted small"><?= e($fmtCadence($sc)) ?></td>
          <td class="muted small"><?= $sc['last_run_at'] ? e(time_ago($sc['last_run_at'])) : 'never' ?></td>
          <td>
            <?php if ($isAdmin): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="schedule_toggle"><input type="hidden" name="id" value="<?= (int)$sc['id'] ?>"><input type="hidden" name="on" value="<?= $sc['is_enabled'] ? '0' : '1' ?>">
              <button class="btn-sm btn-ghost"><?= $sc['is_enabled'] ? 'on' : 'off' ?></button>
            </form>
            <?php else: ?><?= $sc['is_enabled'] ? 'on' : 'off' ?><?php endif; ?>
          </td>
          <?php if ($isAdmin): ?>
          <td style="white-space:nowrap">
            <button type="button" class="btn-sm btn-ghost" onclick="editSched(<?= e(json_encode(['id'=>(int)$sc['id'],'script_id'=>(int)$sc['script_id'],'name'=>$sc['name'],'scope_type'=>$sc['scope_type'],'scope_id'=>$sc['scope_id']!==null?(int)$sc['scope_id']:'','recurrence'=>$sc['recurrence'],'at_time'=>substr((string)($sc['at_time']??''),0,5),'dow'=>$sc['dow']!==null?(int)$sc['dow']:0,'interval_min'=>$sc['interval_min']!==null?(int)$sc['interval_min']:60,'is_enabled'=>(int)$sc['is_enabled']], JSON_UNESCAPED_SLASHES)) ?>)">Edit</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this schedule?')">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="schedule_delete"><input type="hidden" name="id" value="<?= (int)$sc['id'] ?>">
              <button class="btn-sm btn-danger">Delete</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($isAdmin && $scripts): ?>
  <form method="post" class="rule-editor" style="margin-top:14px" id="sched-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="schedule_save">
    <input type="hidden" name="id" id="sc-id" value="">
    <div class="rule-editor-row">
      <label>Script<select name="script_id" id="sc-script">
        <?php foreach ($scripts as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
      </select></label>
      <label>Name<input name="name" id="sc-name" placeholder="Nightly cleanup"></label>
      <label>Scope<select name="scope_type" id="sc-scope-type" onchange="scFillTarget()">
        <option value="global">Global</option><option value="client">Client</option><option value="site">Site</option><option value="group">Group</option><option value="device">Device</option>
      </select></label>
      <label id="sc-target-wrap" hidden>Target<select name="scope_id" id="sc-scope-id"></select></label>
    </div>
    <div class="rule-editor-row">
      <label>Recurrence<select name="recurrence" id="sc-rec" onchange="scToggle()">
        <option value="daily">Daily</option><option value="weekly">Weekly</option><option value="interval">Interval</option>
      </select></label>
      <label id="sc-time-wrap">At (UTC)<input type="time" name="at_time" id="sc-at" value="02:00"></label>
      <label id="sc-dow-wrap" hidden>Day<select name="dow" id="sc-dow">
        <?php foreach ($DOW as $i => $d): ?><option value="<?= $i ?>"><?= $d ?></option><?php endforeach; ?>
      </select></label>
      <label id="sc-int-wrap" hidden>Every (min)<input type="number" name="interval_min" id="sc-int" value="60" min="1" style="width:90px"></label>
      <input type="hidden" name="is_enabled" value="0">
      <label class="chk"><input type="checkbox" name="is_enabled" id="sc-enabled" value="1" checked> Enabled</label>
    </div>
    <div class="rule-editor-actions">
      <button class="btn-sm btn-primary">Save schedule</button>
      <button type="button" class="btn-sm btn-ghost" onclick="scReset()">Clear</button>
    </div>
  </form>
  <?php elseif ($isAdmin): ?>
    <p class="muted small">Add a script above first, then you can schedule it.</p>
  <?php endif; ?>
</section>

<script>
var SCOPES = <?= json_encode($scopes, JSON_UNESCAPED_SLASHES) ?>;
function scOpts(t){return {client:SCOPES.clients,site:SCOPES.sites,group:SCOPES.groups,device:SCOPES.agents}[t]||[];}
function scFillTarget(sel){var t=document.getElementById('sc-scope-type').value,w=document.getElementById('sc-target-wrap'),s=document.getElementById('sc-scope-id');if(t==='global'){w.hidden=true;return;}w.hidden=false;s.innerHTML='';scOpts(t).forEach(function(o){var op=document.createElement('option');op.value=o.id;op.textContent=o.name;s.appendChild(op);});if(sel)s.value=sel;}
function scToggle(){var r=document.getElementById('sc-rec').value;document.getElementById('sc-time-wrap').hidden=(r==='interval');document.getElementById('sc-dow-wrap').hidden=(r!=='weekly');document.getElementById('sc-int-wrap').hidden=(r!=='interval');}
function editSched(s){document.getElementById('sc-id').value=s.id;document.getElementById('sc-script').value=s.script_id;document.getElementById('sc-name').value=s.name||'';document.getElementById('sc-scope-type').value=s.scope_type;scFillTarget(s.scope_id||'');document.getElementById('sc-rec').value=s.recurrence;document.getElementById('sc-at').value=s.at_time||'02:00';document.getElementById('sc-dow').value=s.dow||0;document.getElementById('sc-int').value=s.interval_min||60;document.getElementById('sc-enabled').checked=!!s.is_enabled;scToggle();document.getElementById('sched-form').scrollIntoView({behavior:'smooth',block:'nearest'});}
function scReset(){document.getElementById('sc-id').value='';document.getElementById('sc-name').value='';document.getElementById('sc-scope-type').value='global';scFillTarget();document.getElementById('sc-rec').value='daily';document.getElementById('sc-at').value='02:00';document.getElementById('sc-enabled').checked=true;scToggle();}
scToggle();
</script>
<?php layout_footer();
