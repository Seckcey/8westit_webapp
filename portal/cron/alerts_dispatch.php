<?php
/**
 * Milepost — alert delivery + offline sweep (Phase 2 Step 3).
 *
 * Two jobs, run every minute so MTTA stays low:
 *   1) Offline sweep — open an 'offline' alert for any non-archived agent unseen for >= the
 *      configured window, and resolve it when the agent checks back in. (Threshold breaches are
 *      detected in real time inside metrics_snapshot.php; "offline" has no sample, so it is swept
 *      here from agents.last_seen_at.)
 *   2) Drain the alert_deliveries outbox — send pending email notifications via lib/mailer.php,
 *      with exponential backoff and a per-delivery attempt cap. Detection only ENQUEUES; this is
 *      the only place mail is sent, so a slow mail server never touches the metrics hot path.
 *
 * CLI-ONLY (refuses any web request) — it takes no auth and must never be reachable over HTTP.
 * Gated by config.php `alerts.enabled`: a no-op while alerting is off.
 *
 * cPanel cron (every minute):
 *    * * * * * /usr/local/bin/php /home/<acct>/public_html/8westit/cron/alerts_dispatch.php >/dev/null 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: this maintenance script is CLI-only.\n");
}

require_once __DIR__ . '/../lib/alerts.php';
require_once __DIR__ . '/../lib/mailer.php';

$line = static function (string $s): void { fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "Z] $s\n"); };

if (!alerts_enabled()) { $line('alerts disabled — nothing to do.'); exit(0); }

$pdo = db();
$now = gmdate('Y-m-d H:i:s');

/* ── 1) Offline sweep. ───────────────────────────────────────────────────────────────────────── */
try {
    $sw = alerts_sweep_offline($pdo, $now);
    $line("offline sweep: opened={$sw['opened']} resolved={$sw['resolved']}");
} catch (Throwable $e) {
    $line('offline sweep FAILED: ' . $e->getMessage());
}

/* ── 2) Drain the email outbox. ──────────────────────────────────────────────────────────────── */
$a       = cfg('alerts', []);
$maxTry  = max(1, (int)($a['max_attempts'] ?? 5));
$base    = rtrim((string)cfg('base_url', ''), '/');

$sel = $pdo->prepare(
    "SELECT d.id, d.alert_id, d.event, d.target, d.attempts,
            a.agent_id, a.severity, a.message, a.opened_at, a.resolved_at,
            ag.hostname, ag.display_name
       FROM alert_deliveries d
       JOIN alerts a  ON a.id  = d.alert_id
       JOIN agents ag ON ag.id = a.agent_id
      WHERE d.channel='email' AND d.status='pending'
        AND (d.next_try_at IS NULL OR d.next_try_at <= ?)
      ORDER BY d.id
      LIMIT 100"
);
$sel->execute([$now]);
$rows = $sel->fetchAll();

$sent = 0; $failed = 0; $retry = 0;
$markSent   = $pdo->prepare("UPDATE alert_deliveries SET status='sent', sent_at=?, attempts=attempts+1, last_error='' WHERE id=?");
$markFailed = $pdo->prepare("UPDATE alert_deliveries SET status='failed', attempts=attempts+1, last_error=? WHERE id=?");
$markRetry  = $pdo->prepare("UPDATE alert_deliveries SET attempts=attempts+1, last_error=?, next_try_at=? WHERE id=?");

foreach ($rows as $r) {
    $to = array_values(array_filter(array_map('trim', explode(',', (string)$r['target'])), 'strlen'));
    if (!$to) {                        // recipients were cleared after enqueue — nothing to send
        $pdo->prepare("UPDATE alert_deliveries SET status='skipped', last_error='no recipients' WHERE id=?")
            ->execute([(int)$r['id']]);
        continue;
    }
    [$subject, $body] = alert_email_render($r, (string)$r['event'], $base);
    $err = null;
    try {
        $ok = mailer_send($to, $subject, $body, $err);
    } catch (Throwable $e) {
        $ok = false; $err = $e->getMessage();
    }

    if ($ok) {
        $markSent->execute([gmdate('Y-m-d H:i:s'), (int)$r['id']]);
        $sent++;
    } else {
        $attempts = (int)$r['attempts'] + 1;
        if ($attempts >= $maxTry) {
            $markFailed->execute([mb_substr((string)$err, 0, 255), (int)$r['id']]);
            $failed++;
        } else {
            $delay = min(1800, 60 * (2 ** min($attempts, 5)));   // 2,4,8,16,32,60→cap 30m
            $next  = gmdate('Y-m-d H:i:s', time() + $delay);
            $markRetry->execute([mb_substr((string)$err, 0, 255), $next, (int)$r['id']]);
            $retry++;
        }
    }
}
$line("email outbox: sent={$sent} retry={$retry} failed={$failed} (scanned=" . count($rows) . ')');
$line('done.');

/** Build [subject, body] for one delivery row (alert joined to agent). */
function alert_email_render(array $r, string $event, string $base): array
{
    $device = ($r['display_name'] !== '' ? $r['display_name'] : ($r['hostname'] !== '' ? $r['hostname'] : ('agent#' . $r['agent_id'])));
    $sev    = strtoupper((string)$r['severity']);
    $tag    = ['open' => $sev, 'escalate' => 'ESCALATED', 'resolve' => 'RESOLVED', 'ack' => 'ACK'][$event] ?? $sev;
    $verb   = ['open' => 'opened', 'escalate' => 'escalated to CRITICAL', 'resolve' => 'resolved', 'ack' => 'acknowledged'][$event] ?? $event;

    $subject = "[Milepost {$tag}] {$device} — " . (string)$r['message'];

    $lines = [];
    $lines[] = "A Milepost alert was {$verb}.";
    $lines[] = '';
    $lines[] = 'Device:    ' . $device . ($r['hostname'] !== '' ? " ({$r['hostname']})" : '');
    $lines[] = 'Severity:  ' . $sev;
    $lines[] = 'Condition: ' . (string)$r['message'];
    $lines[] = 'Opened:    ' . (string)$r['opened_at'] . ' UTC';
    if ($event === 'resolve' && !empty($r['resolved_at'])) {
        $lines[] = 'Resolved:  ' . (string)$r['resolved_at'] . ' UTC';
    }
    if ($base !== '') {
        $lines[] = '';
        $lines[] = 'Open in Milepost: ' . $base . '/alerts.php';
    }
    $lines[] = '';
    $lines[] = '— Milepost, an 8 West IT product';
    return [$subject, implode("\n", $lines)];
}
