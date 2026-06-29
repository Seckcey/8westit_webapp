<?php
/**
 * Service auth for backend→portal calls (Milepost real-time backend on the VPS).
 *
 * Every backend→portal request carries (spec §2.1):
 *   X-Milepost-Service:   <service_secret>            static shared secret (constant-time compared)
 *   X-Milepost-Timestamp: <unix seconds>             replay window ±service_replay_window
 *   X-Milepost-Nonce:     <uuidv4>                    single-use within the window
 *   X-Milepost-Sign:      <hex HMAC-SHA256(secret, METHOD\nPATH\nTS\nNONCE\nsha256(body))>
 *
 * Both the static header AND the HMAC+timestamp+nonce must validate (defense in depth:
 * HostGator sometimes strips Authorization-style headers, and a stolen static header alone
 * is not enough). On ANY failure this 401s and exits.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Verify a backend-to-portal service request. 401s on any failure. */
function require_service(): void
{
    $secret = (string) cfg('service_secret', '');
    if ($secret === '' || $secret === 'CHANGE_ME_64_HEX') {
        json_err('Service auth not configured', 401);
    }

    $sent  = $_SERVER['HTTP_X_MILEPOST_SERVICE']   ?? '';
    $ts    = $_SERVER['HTTP_X_MILEPOST_TIMESTAMP'] ?? '';
    $nonce = $_SERVER['HTTP_X_MILEPOST_NONCE']     ?? '';
    $sign  = $_SERVER['HTTP_X_MILEPOST_SIGN']      ?? '';

    // 1) Static shared secret (constant-time).
    if (!hash_equals($secret, (string)$sent)) json_err('Unauthorized', 401);

    // 2) Timestamp freshness (±skew).
    $skew = (int) cfg('service_replay_window', 300);
    if (!ctype_digit((string)$ts) || abs(time() - (int)$ts) > $skew) json_err('Stale request', 401);

    // 3) Nonce shape (uuidv4-ish: 36 chars hex + dashes).
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', (string)$nonce)) json_err('Bad nonce', 401);

    // 4) HMAC over METHOD\nPATH\nTS\nNONCE\nsha256(body).
    //    `body` is the raw request bytes; file_get_contents('php://input') is replayable
    //    within the same request, so read_json_body() (which also reads it) still works.
    $body   = file_get_contents('php://input') ?: '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $base   = $method . "\n" . $path . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);
    $want   = hash_hmac('sha256', $base, $secret);
    if (!hash_equals($want, (string)$sign)) json_err('Bad signature', 401);

    // 5) Replay protection: the nonce must be unused within the window.
    try {
        db()->prepare('INSERT INTO service_nonces (nonce) VALUES (?)')->execute([$nonce]);
    } catch (Throwable $e) {
        // Duplicate key (PK on nonce) => already seen => replay.
        json_err('Replay detected', 401);
    }

    // 6) Opportunistic prune (cheap, runs ~1% of requests).
    if (random_int(1, 100) === 1) {
        try {
            db()->prepare('DELETE FROM service_nonces WHERE seen_at < (NOW() - INTERVAL 1 HOUR)')->execute();
        } catch (Throwable $e) { /* best-effort */ }
    }
}
