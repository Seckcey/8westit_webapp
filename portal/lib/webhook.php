<?php
/**
 * Milepost — outbound webhook delivery for alerts (Slack / Discord / Telegram / generic JSON).
 *
 * Targets are the compact "type|…" strings produced by webhook_target_string() (lib/alerts.php)
 * and stored in alert_deliveries.target; cron/alerts_dispatch.php calls webhook_send() for each.
 * URLs must be https. Returns true on a 2xx response; on failure returns false and sets $err.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function webhook_send(string $target, string $subject, string $body, ?string &$err = null): bool
{
    $parts = explode('|', $target);
    $type  = strtolower($parts[0] ?? '');
    $text  = $subject . "\n" . $body;

    switch ($type) {
        case 'slack':
            return webhook_curl((string)($parts[1] ?? ''),
                json_encode(['text' => $text], JSON_UNESCAPED_SLASHES), 'application/json', $err);
        case 'discord':
            // Discord hard-caps message content at 2000 chars.
            return webhook_curl((string)($parts[1] ?? ''),
                json_encode(['content' => mb_substr($text, 0, 1900)], JSON_UNESCAPED_SLASHES), 'application/json', $err);
        case 'telegram':
            $token = (string)($parts[1] ?? ''); $chat = (string)($parts[2] ?? '');
            if ($token === '' || $chat === '') { $err = 'telegram target incomplete'; return false; }
            return webhook_curl('https://api.telegram.org/bot' . $token . '/sendMessage',
                http_build_query(['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true']),
                'application/x-www-form-urlencoded', $err);
        case 'generic':
        default:
            return webhook_curl((string)($parts[1] ?? ''),
                json_encode(['subject' => $subject, 'body' => $body, 'text' => $text], JSON_UNESCAPED_SLASHES),
                'application/json', $err);
    }
}

function webhook_curl(string $url, string $body, string $contentType, ?string &$err): bool
{
    if ($url === '' || !preg_match('#^https://#i', $url)) { $err = 'invalid webhook url'; return false; }
    if (!function_exists('curl_init'))                    { $err = 'cURL not available';  return false; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: ' . $contentType],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { $err = 'curl: ' . curl_error($ch); curl_close($ch); return false; }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) return true;
    $err = 'http ' . $code . ': ' . mb_substr((string)$resp, 0, 180);
    return false;
}
