<?php
/**
 * Milepost — Phase 4 (Automation), increment 2: scheduled-script dispatcher (CLI-only, every 1 min).
 *
 * Fires each enabled, DUE script_schedule → enqueues one job per in-scope agent (via
 * script_run_on_agent, created_by NULL = system), then stamps last_run_at. Reuses the increment-1
 * script library + the existing agent job pipeline — no agent change.
 *
 * cPanel cron (every minute):
 *   * * * * * /usr/local/bin/php /home/<acct>/public_html/8westit/cron/script_dispatch.php >/dev/null 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: this maintenance script is CLI-only.\n");
}

require_once __DIR__ . '/../lib/scripts.php';

$line  = static function (string $s): void { fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "Z] $s\n"); };
$pdo   = db();
$now   = gmdate('Y-m-d H:i:s');
$nowTs = strtotime($now . ' UTC');

$scheds = $pdo->query('SELECT * FROM script_schedules WHERE is_enabled=1')->fetchAll();
$line('enabled schedules: ' . count($scheds));

foreach ($scheds as $s) {
    try {
        if (!schedule_is_due($s, $nowTs)) continue;
        $agents = schedule_scope_agents((string)$s['scope_type'], $s['scope_id'] !== null ? (int)$s['scope_id'] : null);
        $n = 0;
        foreach ($agents as $aid) {
            if (script_run_on_agent((int)$s['script_id'], $aid, null) > 0) $n++;
        }
        $pdo->prepare('UPDATE script_schedules SET last_run_at=? WHERE id=?')->execute([$now, (int)$s['id']]);
        $line("schedule {$s['id']} ({$s['recurrence']}) fired on $n agent(s)");
    } catch (Throwable $e) {
        $line("schedule {$s['id']} error: " . $e->getMessage());
    }
}
$line('done.');
