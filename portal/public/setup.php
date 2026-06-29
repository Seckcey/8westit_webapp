<?php
/**
 * First-run setup: creates the initial admin account.
 * Self-disables once any user exists. Safe to leave in place, but you may delete it.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
enforce_https();

$userCount = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($userCount > 0) {
    http_response_code(403);
    exit('Setup is already complete. Delete setup.php if you like.');
}

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $fullname = trim((string)($_POST['full_name'] ?? ''));
    $pass     = (string)($_POST['password'] ?? '');
    if ($username === '' || strlen($pass) < 10) {
        $msg = 'Username required and password must be at least 10 characters.';
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        db()->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?,?,?,"admin")')
            ->execute([$username, $hash, $fullname]);
        header('Location: login.php'); exit;
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup · Milepost</title>
<link rel="icon" href="assets/img/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/app.css"></head>
<body class="login-body">
<div class="login-card">
  <div class="login-brand">
    <img src="assets/img/logo-stacked.png" alt="Milepost" class="login-logo"
         onerror="this.style.display='none';this.nextElementSibling.style.display='inline-grid';">
    <span class="badge login-fallback-badge">8</span>
    <h1>Milepost</h1><p>First-run setup — create your admin account</p>
  </div>
  <?php if ($msg): ?><div class="alert"><?= e($msg) ?></div><?php endif; ?>
  <form method="post">
    <label>Full name<input name="full_name" placeholder="Frank Gonzalez"></label>
    <label>Username<input name="username" required autofocus></label>
    <label>Password <small>(10+ chars)</small><input type="password" name="password" required></label>
    <button class="btn-primary">Create account</button>
  </form>
</div></body></html>
