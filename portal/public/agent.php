<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/realtime.php';
enforce_https();
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT a.*, c.name AS client_name FROM agents a
       LEFT JOIN clients c ON c.id = a.client_id WHERE a.id = ?'
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

$jobs = db()->prepare('SELECT * FROM jobs WHERE agent_id=? ORDER BY id DESC LIMIT 20');
$jobs->execute([$id]);
$jobRows = $jobs->fetchAll();

$online = agent_is_online($agent);
$csrf = csrf_token();
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
      <dt>Agent ver.</dt><dd><?= e($agent['agent_version'] ?: '—') ?></dd>
      <dt>RustDesk ID</dt><dd><?= e($agent['rustdesk_id'] ?: 'not reported') ?></dd>
    </dl>
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
