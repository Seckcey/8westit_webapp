<?php
/**
 * Portal → Milepost RT backend client (spec §3.7).
 *
 * The portal calls the Node backend's /internal/* routes to (a) push an instant command to a
 * connected agent and (b) read live presence/metrics for the dashboard. Same shared-secret +
 * HMAC scheme the backend uses to call us (spec §2.1), with the portal as the caller.
 *
 * EVERYTHING here fails gracefully: if real-time is disabled or the backend is unreachable,
 * functions return a benign result (rt_dispatch_command → ['ok'=>false,'delivered'=>false],
 * rt_presence → []) so the caller can fall back to the existing jobs queue / MySQL snapshot.
 * Nothing in the portal ever hard-depends on the backend being up.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Is the real-time integration switched on in config? */
function rt_enabled(): bool
{
    $rt = cfg('realtime', []);
    return is_array($rt) && !empty($rt['enabled']);
}

/** Base URL of the backend (no trailing slash), or '' if not configured. */
function rt_backend_base(): string
{
    $rt = cfg('realtime', []);
    return rtrim((string)($rt['backend_url'] ?? ''), '/');
}

/** WS URL advertised to agents via enroll/heartbeat, or '' when disabled. */
function rt_agent_ws_url(): string
{
    if (!rt_enabled()) return '';
    $rt = cfg('realtime', []);
    return (string)($rt['agent_ws_url'] ?? '');
}

/**
 * Make a signed POST/GET to the backend's /internal/* path.
 * Returns the decoded JSON array, or null on ANY transport/parse error (caller falls back).
 *
 * @param string $method 'POST' | 'GET'
 * @param string $path   path beginning with '/internal/...' (no host)
 * @param array  $body   JSON body for POST (ignored for GET)
 * @param string $query  raw query string for GET (without leading '?'), e.g. 'agent_ids=1,2'
 */
function rt_call(string $method, string $path, array $body = [], string $query = ''): ?array
{
    if (!rt_enabled()) return null;
    $base = rt_backend_base();
    $secret = (string) cfg('service_secret', '');
    if ($base === '' || $secret === '' || $secret === 'CHANGE_ME_64_HEX') return null;

    $method = strtoupper($method) === 'GET' ? 'GET' : 'POST';
    $payload = $method === 'POST' ? json_encode($body, JSON_UNESCAPED_SLASHES) : '';
    if ($payload === false) $payload = '';

    // The HMAC is computed over the PATH only (no query string, no host) — matching the
    // backend's verifier, which mirrors require_service() in svc_auth.php.
    $ts    = (string) time();
    $nonce = rt_uuidv4();
    $bodyHash = hash('sha256', $payload);
    $signBase = $method . "\n" . $path . "\n" . $ts . "\n" . $nonce . "\n" . $bodyHash;
    $sign  = hash_hmac('sha256', $signBase, $secret);

    $url = $base . $path;
    if ($method === 'GET' && $query !== '') $url .= '?' . $query;

    $rt = cfg('realtime', []);
    $timeoutMs = (int)($rt['dispatch_timeout_ms'] ?? 4000);

    $headers = [
        'Content-Type: application/json',
        'X-Milepost-Service: '   . $secret,
        'X-Milepost-Timestamp: ' . $ts,
        'X-Milepost-Nonce: '     . $nonce,
        'X-Milepost-Sign: '      . $sign,
    ];

    // Prefer cURL; fall back to a stream context if cURL is unavailable on the host.
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) return null;
        $data = json_decode((string)$resp, true);
        return is_array($data) ? $data : null;
    }

    // Stream fallback.
    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $headers),
        'content'       => $payload,
        'timeout'       => max(1, (int)ceil($timeoutMs / 1000)),
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return null;
    $data = json_decode((string)$resp, true);
    return is_array($data) ? $data : null;
}

/**
 * Push an already-minted job to a connected agent over WS (spec §3.7 /internal/dispatch).
 * The portal must have created the job via job_create with dispatch='realtime' (status=running)
 * BEFORE calling this. Returns ['ok'=>bool,'delivered'=>bool].
 *
 * delivered=false (agent offline, or backend down) means the caller should leave/return the
 * job to the polling path (status='queued') so the 60s poller picks it up.
 */
function rt_dispatch_command(int $agentId, int $jobId, string $jobType, string $payload,
                             int $timeoutSecs = 300, ?string $toolActionId = null): array
{
    $notAfter = time() + max(1, $timeoutSecs);
    $res = rt_call('POST', '/internal/dispatch', [
        'agent_id'       => $agentId,
        'job_id'         => $jobId,
        'job_type'       => $jobType,
        'payload'        => $payload,
        'timeout_secs'   => $timeoutSecs,
        'not_after'      => $notAfter,
        'tool_action_id' => $toolActionId,
    ]);
    if ($res === null) return ['ok' => false, 'delivered' => false];
    return [
        'ok'        => !empty($res['ok']),
        'delivered' => !empty($res['delivered']),
    ];
}

/**
 * Fetch live presence/metrics for a set of agents (spec §3.7 /internal/presence).
 * Returns a map agentId(int) => info array, or [] on any failure (caller falls back to
 * agent_metrics_latest in MySQL and shows "live metrics unavailable").
 */
function rt_presence(array $agentIds): array
{
    $ids = array_values(array_filter(array_map('intval', $agentIds), static fn($i) => $i > 0));
    if (!$ids) return [];
    $res = rt_call('GET', '/internal/presence', [], 'agent_ids=' . implode(',', $ids));
    if ($res === null || empty($res['ok']) || !isset($res['agents']) || !is_array($res['agents'])) {
        return [];
    }
    $out = [];
    foreach ($res['agents'] as $k => $v) {
        if (is_array($v)) $out[(int)$k] = $v;
    }
    return $out;
}

/** Convenience: live presence for a single agent, or null when unavailable. */
function rt_presence_one(int $agentId): ?array
{
    $map = rt_presence([$agentId]);
    return $map[$agentId] ?? null;
}

/** Is the agent currently connected to the backend? Conservative: false when unknown. */
function rt_agent_online(int $agentId): bool
{
    $p = rt_presence_one($agentId);
    return $p !== null && !empty($p['online']);
}

/** Backend health probe (spec §3.7 /healthz). Returns the decoded body or null. */
function rt_healthz(): ?array
{
    return rt_call('GET', '/healthz');
}

/** RFC-4122 v4 UUID (no extension dependency). */
function rt_uuidv4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
