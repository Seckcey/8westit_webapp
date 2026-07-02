<?php
/**
 * Milepost — Phase 4 (Automation), increment 3: event-driven automation engine (CLI-only, every 1 min).
 *
 * Evaluates OPEN alerts against enabled automations; a match fires the automation's saved script on the
 * alerting device (reuses the script library + agent job pipeline). Guardrails: fire once per
 * (automation, alert) [uq_autorun], a per-agent cooldown, and a per-automation daily cap. Gated by
 * config.php automation.enabled — self-healing runs scripts, so it stays OFF until an admin enables it.
 *
 * cPanel cron (every minute):
 *   * * * * * /usr/local/bin/php /home/<acct>/public_html/8westit/cron/automation_run.php >/dev/null 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: this maintenance script is CLI-only.\n");
}

require_once __DIR__ . '/../lib/automation.php';

$line = static function (string $s): void { fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "Z] $s\n"); };
if (!automation_enabled()) { $line('automation disabled — nothing to do.'); exit(0); }

$pdo   = db();
$now   = gmdate('Y-m-d H:i:s');
$nowTs = strtotime($now . ' UTC');

$autos = $pdo->query('SELECT * FROM automations WHERE is_enabled=1')->fetchAll();
if (!$autos) { $line('no enabled automations.'); exit(0); }

// Open alerts from the last 24h (uq_autorun prevents re-fire; the window just bounds work).
$as = $pdo->prepare("SELECT id, agent_id, rule_key, severity FROM alerts WHERE status='open' AND opened_at >= DATE_SUB(?, INTERVAL 1 DAY)");
$as->execute([$now]);
$alerts = $as->fetchAll();
$line('enabled automations: ' . count($autos) . ', open alerts: ' . count($alerts));

foreach ($autos as $a) {
    $aid = (int)$a['id'];
    $cap = (int)$a['max_per_day'];
    $today = 0;
    if ($cap > 0) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM automation_runs WHERE automation_id=? AND created_at >= ?');
        $c->execute([$aid, gmdate('Y-m-d 00:00:00', $nowTs)]);
        $today = (int)$c->fetchColumn();
    }
    foreach ($alerts as $al) {
        if ($cap > 0 && $today >= $cap) break;
        if (!automation_matches($a, $al)) continue;

        $ex = $pdo->prepare('SELECT 1 FROM automation_runs WHERE automation_id=? AND alert_id=?');
        $ex->execute([$aid, (int)$al['id']]);
        if ($ex->fetchColumn()) continue;                      // already fired for this alert

        if ((int)$a['cooldown_min'] > 0) {
            $lr = $pdo->prepare('SELECT MAX(created_at) FROM automation_runs WHERE automation_id=? AND agent_id=?');
            $lr->execute([$aid, (int)$al['agent_id']]);
            $last = $lr->fetchColumn();
            if ($last && ($nowTs - strtotime($last . ' UTC')) < (int)$a['cooldown_min'] * 60) continue;
        }

        $jobId = script_run_on_agent((int)$a['script_id'], (int)$al['agent_id'], null);
        try {
            $pdo->prepare('INSERT INTO automation_runs (automation_id, alert_id, agent_id, job_id) VALUES (?,?,?,?)')
                ->execute([$aid, (int)$al['id'], (int)$al['agent_id'], $jobId ?: null]);
            $today++;
            $line("automation $aid fired on agent {$al['agent_id']} for alert {$al['id']} ({$al['rule_key']}) -> job $jobId");
        } catch (Throwable $e) { /* uq_autorun race — another tick fired it */ }
    }
}
$line('done.');
