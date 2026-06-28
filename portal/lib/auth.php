<?php
/**
 * Portal session auth (for the human-facing dashboard) and
 * agent token auth (for the machine-facing API).
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = cfg('force_https') ? true : false;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('EIGHTWEST_RMM');
    session_start();
}

function current_user(): ?array
{
    session_start_secure();
    if (empty($_SESSION['uid'])) return null;
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$_SESSION['uid']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

/** Redirect to login if not authenticated. Returns the user row otherwise. */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

/** Require an authenticated admin; 403 for techs. */
function require_admin(): array
{
    $u = require_login();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('This page is for administrators only.');
    }
    return $u;
}

/** Hash and store a new password for a user. */
function set_password(int $userId, string $plain): void
{
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
}

function login_attempt(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        session_start_secure();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
        audit((int)$u['id'], null, 'login', 'success');
        return $u;
    }
    audit(null, null, 'login', 'failed user=' . mb_substr($username, 0, 64));
    return null;
}

function logout(): void
{
    session_start_secure();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** CSRF token for forms. */
function csrf_token(): string
{
    session_start_secure();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    session_start_secure();
    $sent = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(403);
        exit('Invalid CSRF token. Go back and try again.');
    }
}

/* ------------------------------------------------------------------ */
/* Agent (machine) auth                                                */
/* ------------------------------------------------------------------ */

/** Pull the Bearer token from the Authorization header. */
function bearer_token(): ?string
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) return $m[1];
    return null;
}

/** Authenticate an agent by its bearer token. Returns the agent row or null. */
function authenticate_agent(): ?array
{
    $token = bearer_token();
    if (!$token) return null;
    $hash = hash('sha256', $token);
    $stmt = db()->prepare('SELECT * FROM agents WHERE auth_token_hash = ? AND is_archived = 0');
    $stmt->execute([$hash]);
    return $stmt->fetch() ?: null;
}

function require_agent(): array
{
    $a = authenticate_agent();
    if (!$a) json_err('Unauthorized', 401);
    return $a;
}
