<?php
/**
 * JSON: alerts list + threshold rules + scope options (session-authed, polled by alerts.php).
 * Mirrors the agent_live.php convention: enforce_https, 401 if not logged in, json_out.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/alerts.php';
enforce_https();
if (!current_user()) json_err('Unauthorized', 401);

$status = (string)($_GET['status'] ?? 'active');
if (!in_array($status, ['active', 'resolved', 'all'], true)) $status = 'active';

// ── Alerts ───────────────────────────────────────────────────────────────────────────────────
$now    = time();
$alerts = [];
foreach (alerts_list($status, 300) as $r) {
    $device = $r['display_name'] !== '' ? $r['display_name']
            : ($r['hostname'] !== '' ? $r['hostname'] : ('agent#' . $r['agent_id']));
    $opened = !empty($r['opened_at']) ? strtotime($r['opened_at'] . ' UTC') : null;
    $alerts[] = [
        'id'          => (int)$r['id'],
        'agent_id'    => (int)$r['agent_id'],
        'device'      => $device,
        'hostname'    => (string)$r['hostname'],
        'rule_key'    => (string)$r['rule_key'],
        'metric_key'  => (string)$r['metric_key'],
        'instance'    => (string)$r['instance'],
        'severity'    => (string)$r['severity'],
        'status'      => (string)$r['status'],
        'threshold'   => $r['threshold']  !== null ? (float)$r['threshold']  : null,
        'last_val'  => $r['last_val'] !== null ? (float)$r['last_val'] : null,
        'message'     => (string)$r['message'],
        'opened_at'   => (string)$r['opened_at'],
        'age_secs'    => $opened !== null ? max(0, $now - $opened) : null,
        'acked_at'    => $r['acked_at'],
        'acked_by'    => $r['acked_by_name'],
        'resolved_at' => $r['resolved_at'],
    ];
}

// ── Scope options (single-tenant, small lists) ─────────────────────────────────────────────────
$loadAll = static function (string $sql): array {
    try { return db()->query($sql)->fetchAll(); } catch (Throwable $e) { return []; }
};
$clients = $loadAll('SELECT id, name FROM clients ORDER BY name');
$sites   = $loadAll('SELECT id, name, client_id FROM sites ORDER BY name');
$groups  = $loadAll('SELECT id, name, client_id, site_id FROM device_groups ORDER BY name');
$agents  = $loadAll("SELECT id, COALESCE(NULLIF(display_name,''), NULLIF(hostname,''), CONCAT('agent#',id)) AS name
                       FROM agents WHERE is_archived=0 ORDER BY name");

$cmap = []; foreach ($clients as $c) $cmap[(int)$c['id']] = $c['name'];
$smap = []; foreach ($sites   as $s) $smap[(int)$s['id']] = $s['name'];
$gmap = []; foreach ($groups  as $g) $gmap[(int)$g['id']] = $g['name'];
$amap = []; foreach ($agents  as $a) $amap[(int)$a['id']] = $a['name'];

// ── Threshold rules (policies whose doc carries a `thresholds` key) ─────────────────────────────
$rules = [];
try {
    $prows = db()->query(
        "SELECT p.id AS policy_id, p.name, p.doc_json,
                pa.id AS assignment_id, pa.scope_type, pa.scope_id, pa.is_enabled, pa.priority
           FROM policies p JOIN policy_assignments pa ON pa.policy_id = p.id
          ORDER BY FIELD(pa.scope_type,'global','client','site','group','device'), p.name"
    )->fetchAll();
    foreach ($prows as $p) {
        $doc = json_decode((string)$p['doc_json'], true);
        if (!is_array($doc) || !isset($doc['thresholds']) || !is_array($doc['thresholds'])) continue;
        $st  = (string)$p['scope_type'];
        $sid = $p['scope_id'] !== null ? (int)$p['scope_id'] : null;
        $target = $st === 'client' ? ($cmap[$sid] ?? "#$sid")
                : ($st === 'site'  ? ($smap[$sid] ?? "#$sid")
                : ($st === 'group' ? ($gmap[$sid] ?? "#$sid")
                : ($st === 'device'? ($amap[$sid] ?? "#$sid") : null)));
        $label = $st === 'global' ? 'Global' : (ucfirst($st) . ': ' . $target);
        $rules[] = [
            'policy_id'     => (int)$p['policy_id'],
            'assignment_id' => (int)$p['assignment_id'],
            'name'          => (string)$p['name'],
            'scope_type'    => $st,
            'scope_id'      => $sid,
            'scope_label'   => $label,
            'is_enabled'    => (int)$p['is_enabled'],
            'thresholds'    => $doc['thresholds'],
        ];
    }
} catch (Throwable $e) { $rules = []; }

// ── Metric catalog + built-in defaults (for the rule editor) ───────────────────────────────────
$def     = alert_default_thresholds();
$metrics = [];
foreach (['cpu', 'mem', 'disk_pct', 'disk_free_gb', 'disk_health', 'net_up_kbps', 'net_down_kbps', 'temp_c'] as $mk) {
    $metrics[] = [
        'key'     => $mk,
        'label'   => alert_metric_label($mk, ''),
        'unit'    => trim(alert_metric_unit($mk)),
        'default' => $def[$mk] ?? new stdClass(),
    ];
}

json_out([
    'ok'         => true,
    'status'     => $status,
    'open_count' => alerts_open_count(),
    'alerts'     => $alerts,
    'rules'      => $rules,
    'scopes'     => ['clients' => $clients, 'sites' => $sites, 'groups' => $groups, 'agents' => $agents],
    'metrics'    => $metrics,
]);
