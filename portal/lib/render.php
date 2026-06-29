<?php
/** Shared HTML layout + small view helpers for the dashboard. */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/** Online if seen within 2.5x its heartbeat interval. */
function agent_is_online(array $a): bool
{
    if (empty($a['last_seen_at'])) return false;
    $hb = max(30, (int)($a['heartbeat_secs'] ?: 60));
    return (time() - strtotime($a['last_seen_at'] . ' UTC')) <= $hb * 2.5;
}

function time_ago(?string $utc): string
{
    if (!$utc) return 'never';
    $diff = time() - strtotime($utc . ' UTC');
    if ($diff < 0) $diff = 0;
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

function layout_header(string $title, ?array $user): void
{
    $u = $user;
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Milepost</title>
<link rel="icon" href="assets/img/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<script>(function(){try{var t=localStorage.getItem('mp-theme')||'system';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','system');}})();</script>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php">
    <img src="assets/img/logo-horizontal.png" alt="Milepost" class="brand-logo"
         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
    <span class="brand-fallback">
      <span class="badge">8</span>
      <span class="brand-name">Milepost <small>by 8 West IT</small></span>
    </span>
  </a>
  <nav class="topnav">
    <a href="index.php">Dashboard</a>
    <a href="clients.php">Clients</a>
    <a href="deploy.php">Deploy&nbsp;Agent</a>
    <?php if ($u && ($u['role'] ?? '') === 'admin'): ?>
      <a href="users.php">Users</a>
    <?php endif; ?>
    <div class="theme-toggle" role="group" aria-label="Appearance">
      <button type="button" data-theme-set="light" title="Light" aria-label="Light mode">&#9728;</button>
      <button type="button" data-theme-set="dark" title="Dark" aria-label="Dark mode">&#9789;</button>
      <button type="button" data-theme-set="system" title="System" aria-label="Match system">&#9680;</button>
    </div>
    <?php if ($u): ?>
      <a class="who" href="account.php" title="My account"><?= e($u['full_name'] ?: $u['username']) ?></a>
      <a class="btn-ghost" href="logout.php">Sign out</a>
    <?php endif; ?>
  </nav>
</header>
<main class="container">
<?php
}

function layout_footer(): void
{
    ?>
</main>
<footer class="foot">Milepost · an 8 West IT, LLC product · <?= date('Y') ?></footer>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php
}
