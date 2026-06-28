<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_admin();

$flash = ''; $err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 64);
        $fullname = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 128);
        $role     = ($_POST['role'] ?? 'tech') === 'admin' ? 'admin' : 'tech';
        $pass     = (string)($_POST['password'] ?? '');
        if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            $err = 'Username must be 3–64 chars (letters, numbers, . _ -).';
        } elseif (strlen($pass) < 10) {
            $err = 'Password must be at least 10 characters.';
        } else {
            try {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                db()->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?,?,?,?)')
                    ->execute([$username, $hash, $fullname, $role]);
                audit((int)$user['id'], null, 'user_add', "username=$username role=$role");
                $flash = "User '$username' created.";
            } catch (PDOException $e) {
                $err = 'That username already exists.';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$user['id']) { $err = "You can't deactivate your own account."; }
        else {
            db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            $flash = 'Updated.';
        }
    } elseif ($action === 'reset') {
        $id = (int)($_POST['id'] ?? 0);
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 10) { $err = 'New password must be at least 10 characters.'; }
        else {
            set_password($id, $pass);
            audit((int)$user['id'], null, 'password_reset', "user_id=$id");
            $flash = 'Password reset.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$user['id']) { $err = "You can't delete your own account."; }
        else {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            audit((int)$user['id'], null, 'user_delete', "user_id=$id");
            $flash = 'User deleted.';
        }
    }
}

$users = db()->query('SELECT * FROM users ORDER BY role, username')->fetchAll();
$csrf = csrf_token();
layout_header('Users', $user);
?>
<div class="page-head"><h2>Users &amp; Techs</h2></div>
<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>

<section class="card">
  <h3>Add a user</h3>
  <form method="post" class="inline-form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="add">
    <label>Username<input name="username" required placeholder="jsmith"></label>
    <label>Full name<input name="full_name" placeholder="Jane Smith"></label>
    <label>Role
      <select name="role">
        <option value="tech">Tech</option>
        <option value="admin">Admin</option>
      </select>
    </label>
    <label>Password <small>(10+)</small><input type="password" name="password" required></label>
    <button class="btn-primary">Create</button>
  </form>
  <p class="muted small">Admins can manage users and keys. Techs can use the dashboard, run commands, and remote in.</p>
</section>

<section class="card">
  <table class="grid">
    <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th>Last login</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$user['id']; ?>
      <tr>
        <td><?= e($u['username']) ?><?= $self ? ' <span class="tag">you</span>' : '' ?></td>
        <td><?= e($u['full_name'] ?: '—') ?></td>
        <td><?= e($u['role']) ?></td>
        <td><?= $u['is_active'] ? '<span class="tag tag-done">active</span>' : '<span class="tag">disabled</span>' ?></td>
        <td class="muted"><?= e($u['last_login_at'] ? time_ago($u['last_login_at']) : 'never') ?></td>
        <td>
          <div class="row-actions">
            <?php if (!$self): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn-sm btn-ghost"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
              </form>
            <?php endif; ?>
            <form method="post" onsubmit="return setReset(this);">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="reset">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="password" value="">
              <button class="btn-sm btn-ghost">Reset password</button>
            </form>
            <?php if (!$self): ?>
              <form method="post" onsubmit="return confirm('Delete this user?');">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn-sm btn-danger">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<script>
function setReset(form){
  var pw = prompt('Enter a new password for this user (10+ characters):');
  if (pw === null) return false;
  if (pw.length < 10) { alert('Password must be at least 10 characters.'); return false; }
  form.password.value = pw;
  return true;
}
</script>
<?php layout_footer();
