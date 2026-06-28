<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

$flash = ''; $err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $cur  = (string)($_POST['current'] ?? '');
    $new  = (string)($_POST['new'] ?? '');
    $conf = (string)($_POST['confirm'] ?? '');

    if (!password_verify($cur, $user['password_hash'])) {
        $err = 'Your current password is incorrect.';
    } elseif (strlen($new) < 10) {
        $err = 'New password must be at least 10 characters.';
    } elseif ($new !== $conf) {
        $err = 'New password and confirmation do not match.';
    } elseif ($new === $cur) {
        $err = 'New password must be different from the current one.';
    } else {
        set_password((int)$user['id'], $new);
        audit((int)$user['id'], null, 'password_change', 'self');
        $flash = 'Password updated.';
    }
}
$csrf = csrf_token();
layout_header('My Account', $user);
?>
<div class="page-head"><h2>My Account</h2></div>
<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>

<section class="card" style="max-width:520px">
  <h3>Change password</h3>
  <dl class="kv">
    <dt>Signed in as</dt><dd><?= e($user['username']) ?> (<?= e($user['role']) ?>)</dd>
  </dl>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <label>Current password<input type="password" name="current" required></label>
    <label>New password <small>(10+ characters)</small><input type="password" name="new" required></label>
    <label>Confirm new password<input type="password" name="confirm" required></label>
    <button class="btn-primary">Update password</button>
  </form>
</section>
<?php layout_footer();
