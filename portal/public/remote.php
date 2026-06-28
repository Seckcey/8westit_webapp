<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM agents WHERE id = ?');
$stmt->execute([$id]);
$agent = $stmt->fetch();
if (!$agent) { http_response_code(404); exit('Agent not found.'); }

if (!$agent['rustdesk_id']) {
    layout_header('Remote', $user);
    echo '<div class="alert">This agent has not reported a RustDesk ID yet. '
       . 'Wait for the next check-in or queue a <code>rustdesk_refresh</code> job.</div>';
    echo '<p><a class="btn-ghost" href="agent.php?id=' . $id . '">&larr; Back</a></p>';
    layout_footer();
    exit;
}

audit((int)$user['id'], $id, 'remote_open', 'rd=' . $agent['rustdesk_id']);
$rdUri = 'rustdesk://' . rawurlencode($agent['rustdesk_id']);
layout_header('Remote · ' . ($agent['display_name'] ?: $agent['hostname']), $user);
?>
<div class="page-head">
  <h2>Remote In — <?= e($agent['display_name'] ?: $agent['hostname']) ?></h2>
  <a class="btn-ghost" href="agent.php?id=<?= $id ?>">&larr; Back</a>
</div>

<section class="card remote-card">
  <p>Connect using the RustDesk client (configured for your 8 West relay). Click <b>Launch</b>,
     or open RustDesk and enter the ID below.</p>

  <div class="creds">
    <div class="cred">
      <label>RustDesk ID</label>
      <div class="copy-row"><code id="rd-id"><?= e($agent['rustdesk_id']) ?></code>
        <button class="btn-sm btn-ghost" data-copy="rd-id">Copy</button></div>
    </div>
    <div class="cred">
      <label>Password (unattended)</label>
      <div class="copy-row"><code id="rd-pass"><?= e($agent['rustdesk_pass'] ?: '— set on agent —') ?></code>
        <button class="btn-sm btn-ghost" data-copy="rd-pass">Copy</button></div>
    </div>
  </div>

  <p style="margin-top:18px">
    <a class="btn-primary btn-lg" href="<?= e($rdUri) ?>">Launch RustDesk</a>
  </p>
  <p class="muted small">
    The “Launch” link opens your installed RustDesk client. If nothing happens, open RustDesk
    manually and paste the ID. Don’t have it? Download from
    <a href="https://rustdesk.com/" target="_blank" rel="noopener">rustdesk.com</a> and point it at your relay.
  </p>
</section>
<?php layout_footer();
