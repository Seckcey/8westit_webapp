<?php
/**
 * Milepost — Phase 2 (Step 3): smart alerting engine.
 *
 * Threshold evaluation + alert lifecycle (open -> ack -> resolve) with anti-fatigue guards:
 *   - sustained-duration thresholds ("> X for N minutes") so a momentary spike never alerts;
 *   - hysteresis on clear (auto-resolve only after the value has been good for the clear window)
 *     so a value flapping across the line does not spam open/resolve;
 *   - one active alert per (agent, rule); a warning that worsens escalates the SAME alert to
 *     critical rather than opening a second one.
 *
 * Thresholds are resolved from the device's EFFECTIVE policy (lib/policy.php, inherited
 * Client->Site->Group->Device) under the `thresholds` key, falling back to a built-in floor here
 * so alerting works with zero configuration. Detection runs inline in metrics_snapshot.php (the
 * only real-time path to MySQL) but only ENQUEUES notifications into alert_deliveries; the
 * every-minute cron/alerts_dispatch.php actually sends them, so a slow mail server never touches
 * the metrics hot path.
 *
 * ALL of this is gated by config.php `alerts.enabled`; when false the engine is a no-op.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/policy.php';

/** Master gate — mirrors realtime.enabled / agent_update.enabled. */
function alerts_enabled(): bool
{
    $a = cfg('alerts', []);
    return is_array($a) && !empty($a['enabled']);
}

/**
 * Built-in threshold floor, keyed by metric_key (no instance). Used when a policy doc omits a key,
 * so a fresh install alerts sensibly out-of-the-box. A policy can override per key or per
 * "key:instance". Every field is optional in a policy doc; missing fields fall back to these.
 */
function alert_default_thresholds(): array
{
    return [
        'cpu'          => ['op' => 'gt', 'warning' => 90, 'critical' => 97, 'for_min' => 10],
        'mem'          => ['op' => 'gt', 'warning' => 90, 'critical' => 97, 'for_min' => 10],
        'disk_pct'     => ['op' => 'gt', 'warning' => 85, 'critical' => 95, 'for_min' => 0],
        'disk_free_gb' => ['op' => 'lt', 'warning' => 20, 'critical' => 5,  'for_min' => 0],
        'disk_health'  => ['op' => 'lt', 'critical' => 1, 'for_min' => 0],   // WMI predict: 0 = failing
        // 'offline' is not a sampled metric — evaluated by alerts_sweep_offline() from last_seen_at.
        'offline'      => ['for_min' => 10, 'severity' => 'critical'],
    ];
}

/** Effective `thresholds` doc for one agent (merged policy; empty array if none). Per-request cached. */
function alert_thresholds_for_agent(int $agentId): array
{
    static $cache = [];
    if (array_key_exists($agentId, $cache)) return $cache[$agentId];
    $t = [];
    try {
        $eff = effective_policy_for_agent($agentId)['effective'];
        if (isset($eff['thresholds']) && is_array($eff['thresholds'])) $t = $eff['thresholds'];
    } catch (Throwable $e) { $t = []; }
    return $cache[$agentId] = $t;
}

/**
 * Resolve the rule for a (metric_key, instance): exact "key:instance" wins, then the bare "key"
 * from the policy doc, then the built-in floor. Returns a normalized rule or null when there is
 * nothing to evaluate (rule disabled, no warning/critical set, or the synthetic offline key).
 */
function alert_lookup_rule(array $thresholds, string $mk, string $inst): ?array
{
    if ($mk === 'offline') return null;                 // handled by the offline sweep, not here
    $exact = $inst !== '' ? ($mk . ':' . $inst) : $mk;
    $raw = null;
    if (isset($thresholds[$exact]) && is_array($thresholds[$exact]))      $raw = $thresholds[$exact];
    elseif (isset($thresholds[$mk]) && is_array($thresholds[$mk]))        $raw = $thresholds[$mk];
    else { $def = alert_default_thresholds(); if (isset($def[$mk])) $raw = $def[$mk]; }
    if ($raw === null) return null;
    if (array_key_exists('enabled', $raw) && !$raw['enabled']) return null;

    $warning  = (array_key_exists('warning', $raw)  && $raw['warning']  !== null) ? (float)$raw['warning']  : null;
    $critical = (array_key_exists('critical', $raw) && $raw['critical'] !== null) ? (float)$raw['critical'] : null;
    if ($warning === null && $critical === null) return null;
    $op = in_array(($raw['op'] ?? 'gt'), ['gt', 'lt', 'gte', 'lte', 'eq'], true) ? $raw['op'] : 'gt';
    $forMin   = max(0, (int)($raw['for_min'] ?? 0));
    $clearMin = array_key_exists('clear_min', $raw) ? max(0, (int)$raw['clear_min']) : $forMin;
    return ['op' => $op, 'warning' => $warning, 'critical' => $critical,
            'for_min' => $forMin, 'clear_min' => $clearMin];
}

function alert_compare(float $v, float $thr, string $op): bool
{
    switch ($op) {
        case 'lt':  return $v <  $thr;
        case 'lte': return $v <= $thr;
        case 'gte': return $v >= $thr;
        case 'eq':  return $v == $thr;
        case 'gt':
        default:    return $v >  $thr;
    }
}

/** Breach level for a value: ['critical'|'warning'|'none', crossed_threshold|null]. Critical wins. */
function alert_level(float $v, array $rule): array
{
    if ($rule['critical'] !== null && alert_compare($v, (float)$rule['critical'], $rule['op']))
        return ['critical', (float)$rule['critical']];
    if ($rule['warning'] !== null && alert_compare($v, (float)$rule['warning'], $rule['op']))
        return ['warning', (float)$rule['warning']];
    return ['none', null];
}

/**
 * Evaluate all arrived samples for one agent and drive the state machine.
 * $samples: list of [metric_key, instance, value]. $now: UTC 'Y-m-d H:i:s'. Never throws to caller
 * intent — callers still wrap in try/catch because this is best-effort off the metrics hot path.
 */
function alerts_evaluate(PDO $pdo, int $agentId, array $samples, string $now): void
{
    if (!$samples) return;
    $thresholds = alert_thresholds_for_agent($agentId);

    // Preload this agent's state + active alerts so the loop is index lookups, not per-sample queries.
    $stStmt = $pdo->prepare('SELECT rule_key, breach_level, breach_since, clear_since FROM alert_state WHERE agent_id=?');
    $stStmt->execute([$agentId]);
    $state = [];
    foreach ($stStmt as $r) $state[$r['rule_key']] = $r;

    $alStmt = $pdo->prepare("SELECT id, rule_key, severity, status FROM alerts WHERE agent_id=? AND status IN('open','acked')");
    $alStmt->execute([$agentId]);
    $active = [];
    foreach ($alStmt as $r) $active[$r['rule_key']] = $r;

    // Collapse duplicate samples for the same (metric_key, instance): a stock agent reports
    // disk_pct:C: via BOTH the flat disk_c field AND the generic series channel in one snapshot,
    // which — since the preloaded $active map is not refreshed between samples — would otherwise
    // open TWO alerts for one rule in a single pass (only one of which ever auto-resolves). Last
    // value wins; one evaluation per rule_key keeps the one-active-alert-per-(agent,rule) invariant.
    $byRule = [];
    foreach ($samples as $s) {
        if (!is_array($s) || count($s) < 3) continue;
        $mk = (string)$s[0]; $inst = (string)$s[1];
        if (!is_numeric($s[2])) continue;
        $rule = alert_lookup_rule($thresholds, $mk, $inst);
        if ($rule === null) continue;
        $ruleKey = $inst !== '' ? ($mk . ':' . $inst) : $mk;
        $byRule[$ruleKey] = [$mk, $inst, (float)$s[2], $rule];
    }
    foreach ($byRule as $ruleKey => [$mk, $inst, $val, $rule]) {
        alerts_apply($pdo, $agentId, $ruleKey, $mk, $inst, $val, $rule, $now,
                     $state[$ruleKey] ?? null, $active[$ruleKey] ?? null);
    }
}

/** One rule's state transition for one fresh value. Writes alert_state + opens/escalates/resolves. */
function alerts_apply(PDO $pdo, int $agentId, string $ruleKey, string $mk, string $inst,
                      float $val, array $rule, string $now, ?array $state, ?array $active): void
{
    [$level, $thr] = alert_level($val, $rule);
    $forSec   = $rule['for_min']   * 60;
    $clearSec = $rule['clear_min'] * 60;
    $nowTs    = strtotime($now . ' UTC');

    if ($level !== 'none') {
        // Breaching: start (or continue) the breach timer.
        $breachSince = ($state && $state['breach_level'] !== 'none' && !empty($state['breach_since']))
            ? (string)$state['breach_since'] : $now;
        $sustained = ($nowTs - strtotime($breachSince . ' UTC')) >= $forSec;
        alert_state_upsert($pdo, $agentId, $ruleKey, $level, $breachSince, null, $val, $now);
        if (!$sustained) return;

        if (!$active) {
            $msg = alert_message($mk, $inst, $val, $thr, $rule['op']);
            $id  = alert_insert($pdo, $agentId, $ruleKey, $mk, $inst, $level, $rule['op'], $thr, $val, $msg, $now);
            alert_enqueue($pdo, $id, 'open');
        } elseif ($level === 'critical' && $active['severity'] !== 'critical') {
            $msg = alert_message($mk, $inst, $val, $thr, $rule['op']);
            $pdo->prepare('UPDATE alerts SET severity=?, threshold=?, last_val=?, message=? WHERE id=?')
                ->execute(['critical', $thr, $val, $msg, (int)$active['id']]);
            alert_enqueue($pdo, (int)$active['id'], 'escalate');
        } else {
            $pdo->prepare('UPDATE alerts SET last_val=? WHERE id=?')->execute([$val, (int)$active['id']]);
        }
    } else {
        // Cleared: auto-resolve only after the value has been good for the clear window (hysteresis).
        if ($active) {
            $clearSince = ($state && !empty($state['clear_since'])) ? (string)$state['clear_since'] : $now;
            $clearedFor = ($nowTs - strtotime($clearSince . ' UTC')) >= $clearSec;
            alert_state_upsert($pdo, $agentId, $ruleKey, 'none', null, $clearedFor ? null : $clearSince, $val, $now);
            if ($clearedFor) {
                alert_resolve($pdo, (int)$active['id'], $val, $now);
                alert_enqueue($pdo, (int)$active['id'], 'resolve');
            } else {
                $pdo->prepare('UPDATE alerts SET last_val=? WHERE id=?')->execute([$val, (int)$active['id']]);
            }
        } else {
            alert_state_upsert($pdo, $agentId, $ruleKey, 'none', null, null, $val, $now);
        }
    }
}

function alert_state_upsert(PDO $pdo, int $agentId, string $ruleKey, string $level,
                            ?string $breachSince, ?string $clearSince, ?float $val, string $now): void
{
    $pdo->prepare(
        'INSERT INTO alert_state (agent_id, rule_key, breach_level, breach_since, clear_since, last_val, last_eval_at)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE breach_level=VALUES(breach_level), breach_since=VALUES(breach_since),
           clear_since=VALUES(clear_since), last_val=VALUES(last_val), last_eval_at=VALUES(last_eval_at)'
    )->execute([$agentId, $ruleKey, $level, $breachSince, $clearSince, $val, $now]);
}

function alert_insert(PDO $pdo, int $agentId, string $ruleKey, string $mk, string $inst, string $sev,
                      string $op, ?float $thr, ?float $val, string $msg, string $now): int
{
    $pdo->prepare(
        "INSERT INTO alerts (agent_id, rule_key, metric_key, instance, severity, status, compare_op,
                             threshold, trigger_value, last_val, message, opened_at)
         VALUES (?,?,?,?,?,'open',?,?,?,?,?,?)"
    )->execute([$agentId, $ruleKey, $mk, $inst, $sev, $op, $thr, $val, $val, mb_substr($msg, 0, 255), $now]);
    return (int)$pdo->lastInsertId();
}

function alert_resolve(PDO $pdo, int $alertId, ?float $val, string $now): void
{
    $pdo->prepare("UPDATE alerts SET status='resolved', last_val=?, resolved_at=? WHERE id=? AND status<>'resolved'")
        ->execute([$val, $now, $alertId]);
}

/**
 * Enqueue an email delivery for an alert event. Recipients are snapshotted from config at enqueue
 * time. No recipients configured => in-app only (nothing queued, alert still shows in the portal).
 */
function alert_enqueue(PDO $pdo, int $alertId, string $event): void
{
    $a  = cfg('alerts', []);
    $to = (isset($a['email_to']) && is_array($a['email_to']))
        ? array_values(array_filter(array_map('trim', $a['email_to']), 'strlen')) : [];
    if (!$to) return;
    $target = mb_substr(implode(',', $to), 0, 255);
    $pdo->prepare(
        "INSERT INTO alert_deliveries (alert_id, event, channel, target, status, next_try_at)
         VALUES (?,?,'email',?,'pending',?)"
    )->execute([$alertId, $event, $target, gmdate('Y-m-d H:i:s')]);
}

/* ── Human-readable labels ─────────────────────────────────────────────────────────────────── */

function alert_metric_label(string $mk, string $inst): string
{
    $base = [
        'cpu' => 'CPU', 'mem' => 'Memory', 'disk_pct' => 'Disk', 'disk_free_gb' => 'Disk free',
        'disk_health' => 'Disk health', 'net_up_kbps' => 'Upload', 'net_down_kbps' => 'Download',
        'temp_c' => 'Temperature', 'offline' => 'Offline',
    ][$mk] ?? $mk;
    return $inst !== '' ? ($base . ' ' . $inst) : $base;
}

function alert_metric_unit(string $mk): string
{
    return [
        'cpu' => '%', 'mem' => '%', 'disk_pct' => '%', 'disk_free_gb' => ' GB',
        'net_up_kbps' => ' kbps', 'net_down_kbps' => ' kbps', 'temp_c' => '°C',
    ][$mk] ?? '';
}

function alert_fmt(float $v): string
{
    $s = number_format($v, 1, '.', '');
    return (strpos($s, '.') !== false) ? rtrim(rtrim($s, '0'), '.') : $s;
}

function alert_message(string $mk, string $inst, float $val, ?float $thr, string $op): string
{
    $unit = alert_metric_unit($mk);
    $line = alert_metric_label($mk, $inst) . ' ' . alert_fmt($val) . $unit;
    if ($thr !== null) {
        $sym = in_array($op, ['lt', 'lte'], true) ? '≤' : '≥';
        $line .= ' (' . $sym . ' ' . alert_fmt($thr) . $unit . ')';
    }
    return $line;
}

/* ── Offline sweep (called by the dispatch cron; not a sampled metric) ──────────────────────── */

/**
 * Open an 'offline' alert for any non-archived agent unseen for >= offline_after_min, and resolve
 * it once the agent checks back in. Returns ['opened'=>n,'resolved'=>n].
 */
function alerts_sweep_offline(PDO $pdo, string $now): array
{
    $nowTs  = strtotime($now . ' UTC');
    $opened = 0; $resolved = 0;

    $activeByAgent = [];
    foreach ($pdo->query("SELECT agent_id, id FROM alerts WHERE rule_key='offline' AND status IN('open','acked')") as $r) {
        $activeByAgent[(int)$r['agent_id']] = (int)$r['id'];
    }
    $rows = $pdo->query('SELECT id, hostname, display_name, last_seen_at FROM agents WHERE is_archived=0')->fetchAll();
    foreach ($rows as $r) {
        $aid      = (int)$r['id'];
        $orule    = alert_offline_rule($aid);   // per-agent: policy > config > built-in default
        $offMin   = $orule['for_min'];
        $lastSeen = !empty($r['last_seen_at']) ? strtotime($r['last_seen_at'] . ' UTC') : null;
        $isOffline = ($lastSeen === null) || (($nowTs - $lastSeen) >= $offMin * 60);
        $active    = $activeByAgent[$aid] ?? null;
        if ($isOffline && !$active) {
            $mins = $lastSeen ? (int)floor(($nowTs - $lastSeen) / 60) : 0;
            $msg  = $lastSeen ? "Offline — last seen {$mins}m ago" : 'Offline — never checked in';
            $id   = alert_insert($pdo, $aid, 'offline', 'offline', '', $orule['severity'], 'gt', (float)$offMin, (float)$mins, $msg, $now);
            alert_enqueue($pdo, $id, 'open');
            $opened++;
        } elseif (!$isOffline && $active) {
            alert_resolve($pdo, $active, null, $now);
            alert_enqueue($pdo, $active, 'resolve');
            $resolved++;
        }
    }
    return ['opened' => $opened, 'resolved' => $resolved];
}

/**
 * Resolve the effective offline rule for an agent: built-in default, overridden by the config
 * `offline_after_min`, overridden by an `offline` key in the agent's inherited policy thresholds.
 * Returns ['for_min'=>int>=1, 'severity'=>'info'|'warning'|'critical'].
 */
function alert_offline_rule(int $agentId): array
{
    $def    = alert_default_thresholds()['offline'] ?? [];
    $forMin = max(1, (int)($def['for_min'] ?? 10));
    $sev    = (string)($def['severity'] ?? 'critical');

    $cfgMin = (int)(cfg('alerts', [])['offline_after_min'] ?? 0);
    if ($cfgMin > 0) $forMin = $cfgMin;

    $t = alert_thresholds_for_agent($agentId);
    if (isset($t['offline']) && is_array($t['offline'])) {
        if (isset($t['offline']['for_min']))  $forMin = max(1, (int)$t['offline']['for_min']);
        if (isset($t['offline']['severity']) && in_array($t['offline']['severity'], ['info', 'warning', 'critical'], true)) {
            $sev = (string)$t['offline']['severity'];
        }
    }
    return ['for_min' => max(1, $forMin), 'severity' => $sev];
}

/* ── Read + action helpers (Alerts UI) ──────────────────────────────────────────────────────── */

/** Count of currently-open (un-acked) alerts, for the nav badge. Tolerant of a pre-migration DB. */
function alerts_open_count(): int
{
    try { return (int)db()->query("SELECT COUNT(*) FROM alerts WHERE status='open'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** List alerts for the UI, joined to agent names. $status: 'active' (open+acked) | 'resolved' | 'all'. */
function alerts_list(string $status = 'active', int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $where = "a.status IN('open','acked')";
    if ($status === 'resolved') $where = "a.status='resolved'";
    elseif ($status === 'all')  $where = '1=1';
    $sql = "SELECT a.id, a.agent_id, a.rule_key, a.metric_key, a.instance, a.severity, a.status,
                   a.threshold, a.trigger_value, a.last_val, a.message,
                   a.opened_at, a.acked_at, a.resolved_at,
                   ag.hostname, ag.display_name, u.username AS acked_by_name
              FROM alerts a
              JOIN agents ag ON ag.id = a.agent_id
              LEFT JOIN users u ON u.id = a.acked_by
             WHERE $where
             ORDER BY (a.status='open') DESC,
                      FIELD(a.severity,'critical','warning','info'),
                      a.opened_at DESC
             LIMIT $limit";
    return db()->query($sql)->fetchAll();
}

/** Ack an open alert (records who + when for MTTA). Returns true if it transitioned. */
function alert_ack(int $alertId, int $userId): bool
{
    $st = db()->prepare("UPDATE alerts SET status='acked', acked_at=?, acked_by=? WHERE id=? AND status='open'");
    $st->execute([gmdate('Y-m-d H:i:s'), $userId, $alertId]);
    return $st->rowCount() > 0;
}

/**
 * Manually resolve an active alert and reset its state so a still-breaching condition starts a
 * fresh for_min timer (a new episode) rather than immediately re-opening.
 */
function alert_manual_resolve(int $alertId): bool
{
    $pdo = db();
    $st  = $pdo->prepare("UPDATE alerts SET status='resolved', resolved_at=? WHERE id=? AND status IN('open','acked')");
    $st->execute([gmdate('Y-m-d H:i:s'), $alertId]);
    if ($st->rowCount() === 0) return false;
    $pdo->prepare(
        "UPDATE alert_state s
            JOIN alerts a ON a.agent_id = s.agent_id AND a.rule_key = s.rule_key
             SET s.breach_level='none', s.breach_since=NULL, s.clear_since=NULL
           WHERE a.id = ?"
    )->execute([$alertId]);
    return true;
}
