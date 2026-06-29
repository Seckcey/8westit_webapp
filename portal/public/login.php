<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
enforce_https();

if (current_user()) { header('Location: index.php'); exit; }

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $u = login_attempt(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''));
    if ($u) { header('Location: index.php'); exit; }
    $error = 'Invalid username or password.';
}
$csrf = csrf_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Milepost</title>
<link rel="icon" href="assets/img/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-body">
<div class="login-card">
  <div class="login-brand">
    <img src="assets/img/logo-stacked.png" alt="Milepost" class="login-logo"
         onerror="this.style.display='none';this.nextElementSibling.style.display='inline-grid';">
    <span class="badge login-fallback-badge">8</span>
    <h1>Milepost</h1><p>by 8 West IT · Remote Management</p>
  </div>
  <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <label>Username<input name="username" required autofocus></label>
    <label>Password<input name="password" type="password" required></label>
    <button class="btn-primary" type="submit">Sign in</button>
  </form>
</div>
</body>
</html>
