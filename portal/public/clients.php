<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'add') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 128);
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($name !== '') {
            try {
                db()->prepare('INSERT INTO clients (name, notes) VALUES (?,?)')->execute([$name, $notes]);
                $flash = 'Client added.';
            } catch (PDOException $e) { $flash = 'A client with that name already exists.'; }
        }
    } elseif (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM clients WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        $flash = 'Client removed (its agents are now unassigned).';
    }
}

$clients = db()->query(
    'SELECT c.*, (SELECT COUNT(*) FROM agents a WHERE a.client_id=c.id AND a.is_archived=0) AS agent_count
       FROM clients c ORDER BY c.name'
)->fetchAll();
$csrf = csrf_token();
layout_header('Clients', $user);
?>
<div class="page-head"><h2>Clients</h2></div>
<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>

<section class="card">
  <h3>Add a client</h3>
  <form method="post" class="inline-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="add">
    <label>Name<input name="name" required placeholder="Acme Dental"></label>
    <label>Notes<input name="notes" placeholder="optional"></label>
    <button class="btn-primary">Add</button>
  </form>
</section>

<section class="card">
  <table class="grid">
    <thead><tr><th>Client</th><th>Agents</th><th>Notes</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($clients as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td><?= (int)$c['agent_count'] ?></td>
        <td class="muted"><?= e($c['notes'] ?? '') ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete this client?');">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="btn-sm btn-danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$clients): ?><tr><td colspan="4" class="muted">No clients yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php layout_footer();
