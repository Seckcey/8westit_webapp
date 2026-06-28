<?php
/**
 * Shared bootstrap: loads config, opens DB, defines helpers.
 * Every entry point (web page or API) includes this first.
 */
declare(strict_types=1);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));

$configPath = APP_ROOT . '/config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Configuration missing. Copy config/config.sample.php to config/config.php.');
}
$CONFIG = require $configPath;

/** Open a shared PDO connection. */
function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $d = $CONFIG['db'];
        $port = !empty($d['port']) ? ";port={$d['port']}" : '';
        $dsn = "mysql:host={$d['host']}{$port};dbname={$d['name']};charset={$d['charset']}";
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Pin the session to UTC so NOW()/CURRENT_TIMESTAMP match PHP's UTC clock,
        // regardless of the hosting server's local time zone (HostGator varies).
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function cfg(string $key, $default = null)
{
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

/** Force HTTPS if configured. */
function enforce_https(): void
{
    if (!cfg('force_https')) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$https) {
        $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . $url, true, 301);
        exit;
    }
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function audit(?int $userId, ?int $agentId, string $action, string $detail = ''): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_log (user_id, agent_id, action, detail, ip) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([$userId, $agentId, $action, mb_substr($detail, 0, 512), client_ip()]);
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Send a JSON response and stop. */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $msg, int $code = 400): void
{
    json_out(['ok' => false, 'error' => $msg], $code);
}

/** Read and decode a JSON request body. */
function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
