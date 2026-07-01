<?php
/**
 * Milepost — minimal, dependency-free SMTP mailer.
 *
 * The portal has no composer/build step, so this is a small hand-rolled SMTP client (AUTH LOGIN,
 * STARTTLS or implicit SSL) rather than PHPMailer. Config comes from config.php `alerts`:
 *   from, from_name, smtp{host,port,secure('tls'|'ssl'|''),user,pass}.
 * When smtp.host is empty it falls back to PHP mail() (works, but deliverability is poorer).
 *
 * Body is sent as UTF-8 text/plain, base64-encoded, so unicode in alert messages (°C, ≥) survives.
 * Returns true on success; on failure returns false and sets $err.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function mailer_send(array $to, string $subject, string $body, ?string &$err = null): bool
{
    $to = array_values(array_filter(array_map('trim', $to), 'strlen'));
    if (!$to) { $err = 'no recipients'; return false; }

    $a    = cfg('alerts', []);
    $from = (string)($a['from'] ?? 'milepost-alerts@localhost');
    $name = (string)($a['from_name'] ?? 'Milepost Alerts');
    $smtp = (isset($a['smtp']) && is_array($a['smtp'])) ? $a['smtp'] : [];
    $host = trim((string)($smtp['host'] ?? ''));

    $subjEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fromHdr = '=?UTF-8?B?' . base64_encode($name) . '?= <' . $from . '>';

    if ($host === '') {
        // Fallback: PHP mail().
        $headers = "From: {$fromHdr}\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: base64\r\n";
        $ok = @mail(implode(', ', $to), $subjEnc, chunk_split(base64_encode($body)), $headers);
        if (!$ok) { $err = 'php mail() returned false'; return false; }
        return true;
    }

    $port   = (int)($smtp['port'] ?? 587);
    $secure = (string)($smtp['secure'] ?? 'tls');
    $user   = (string)($smtp['user'] ?? '');
    $pass   = (string)($smtp['pass'] ?? '');
    $timeout = 15;

    $transport = ($secure === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp  = @stream_socket_client($transport, $eno, $estr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $err = "connect failed: {$estr} ({$eno})"; return false; }
    stream_set_timeout($fp, $timeout);

    // Local closures over $fp for the request/response dance.
    $read = static function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // Multiline replies keep "code-" until the final "code " line.
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $code = (int)substr($data, 0, 3);
        return [$code, rtrim($data)];
    };
    $write = static function (string $cmd) use ($fp): void { fwrite($fp, $cmd . "\r\n"); };

    $fail = static function (string $why) use ($fp, &$err): bool {
        $err = $why;
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return false;
    };

    $host_ehlo = (string)($_SERVER['SERVER_NAME'] ?? 'milepost.local');

    [$code] = $read();
    if ($code !== 220) return $fail("bad greeting: {$code}");

    $write("EHLO {$host_ehlo}");
    [$code] = $read();
    if ($code !== 250) return $fail("EHLO rejected: {$code}");

    if ($secure === 'tls') {
        $write('STARTTLS');
        [$code] = $read();
        if ($code !== 220) return $fail("STARTTLS rejected: {$code}");
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
            return $fail('TLS negotiation failed');
        }
        $write("EHLO {$host_ehlo}");
        [$code] = $read();
        if ($code !== 250) return $fail("EHLO after STARTTLS rejected: {$code}");
    }

    if ($user !== '') {
        $write('AUTH LOGIN');
        [$code] = $read();
        if ($code !== 334) return $fail("AUTH LOGIN rejected: {$code}");
        $write(base64_encode($user));
        [$code] = $read();
        if ($code !== 334) return $fail("AUTH user rejected: {$code}");
        $write(base64_encode($pass));
        [$code] = $read();
        if ($code !== 235) return $fail("AUTH failed: {$code}");
    }

    $write('MAIL FROM:<' . $from . '>');
    [$code] = $read();
    if ($code !== 250) return $fail("MAIL FROM rejected: {$code}");

    foreach ($to as $rcpt) {
        $write('RCPT TO:<' . $rcpt . '>');
        [$code] = $read();
        if ($code !== 250 && $code !== 251) return $fail("RCPT TO <{$rcpt}> rejected: {$code}");
    }

    $write('DATA');
    [$code] = $read();
    if ($code !== 354) return $fail("DATA rejected: {$code}");

    $headers = 'Date: ' . gmdate('r') . "\r\n"
             . 'From: ' . $fromHdr . "\r\n"
             . 'To: ' . implode(', ', $to) . "\r\n"
             . 'Subject: ' . $subjEnc . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n";
    // base64 output contains no leading dots, but dot-stuff defensively per RFC 5321 §4.5.2.
    $payload = $headers . "\r\n" . chunk_split(base64_encode($body));
    $payload = preg_replace('/^\./m', '..', $payload);
    fwrite($fp, $payload . "\r\n.\r\n");
    [$code] = $read();
    if ($code !== 250) return $fail("message rejected: {$code}");

    $write('QUIT');
    @fclose($fp);
    return true;
}
