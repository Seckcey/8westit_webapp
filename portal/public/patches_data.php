<?php
/** JSON: patch rollouts (+ progress) + patch policy rules + scopes/windows (session-authed). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/patch.php';
enforce_https();
if (!current_user()) json_err('Unauthorized', 401);

$pdo = db();
$all = static function (string $sql) use ($pdo): array {
    try { return $pdo->query($sql)->fetchAll(); } catch (Throwable $e) { return []; }
};
$clients = $all('SELECT id, name FROM clients ORDER BY name');
$sites   = $all('SELECT id, name, client_id FROM sites ORDER BY name');
$groups  = $all('SELECT id, name, client_id, site_id FROM device_groups ORDER BY name');
$agents  = $all("SELECT id, COALESCE(NULLIF(display_name,''), NULLIF(hostname,''), CONCAT('agent#',id)) AS name
                   FROM agents WHERE is_archived=0 ORDER BY name");
$windows = $all('SELECT id, name FROM maintenance_windows ORDER BY name');

$cmap = []; foreach ($clients as $x) $cmap[(int)$x['id']] = $x['name'];
$smap = []; foreach ($sites   as $x) $smap[(int)$x['id']] = $x['name'];
$gmap = []; foreach ($groups  as $x) $gmap[(int)$x['id']] = $x['name'];
$amap = []; foreach ($agents  as $x) $amap[(int)$x['id']] = $x['name'];
$wmap = []; foreach ($windows as $x) $wmap[(int)$x['id']] = $x['name'];
$label = static function (string $t, ?int $id) use ($cmap, $smap, $gmap, $amap): string {
    if ($t === 'global') return 'Global';
    $m = ['client' => $cmap, 'site' => $smap, 'group' => $gmap, 'device' => $amap][$t] ?? [];
    return ucfirst($t) . ': ' . ($m[$id] ?? "#$id");
};

// Rollouts with per-state progress.
$rollouts = [];
foreach (patch_rollouts_list() as $ro) {
    $roId = (int)$ro['id'];
    $by = ['pending' => 0, 'installing' => 0, 'installed' => 0, 'verified' => 0, 'failed' => 0];
    try {
        $st = $pdo->prepare('SELECT state, COUNT(*) c FROM patch_rollout_targets WHERE rollout_id=? GROUP BY state');
        $st->execute([$roId]);
        foreach ($st as $r) $by[$r['state']] = (int)$r['c'];
    } catch (Throwable $e) { /* pre-migration */ }
    $rollouts[] = [
        'id'                => $roId,
        'name'              => (string)$ro['name'],
        'scope_label'       => $label((string)$ro['scope_type'], $ro['scope_id'] !== null ? (int)$ro['scope_id'] : null),
        'current_ring'      => (string)$ro['current_ring'],
        'status'            => (string)$ro['status'],
        'ring_order'        => json_decode((string)$ro['ring_order'], true) ?: [],
        'window'            => $ro['window_id'] !== null ? ($wmap[(int)$ro['window_id']] ?? ('#' . $ro['window_id'])) : null,
        'advance_after_min' => (int)$ro['advance_after_min'],
        'max_failure_pct'   => (float)$ro['max_failure_pct'],
        'progress'          => $by,
        'total'             => array_sum($by),
    ];
}

// Patch policy rules (policies whose doc carries a patch_settings key).
$rules = [];
try {
    foreach ($pdo->query(
        "SELECT p.id AS pid, p.name, p.doc_json, pa.scope_type, pa.scope_id, pa.is_enabled
           FROM policies p JOIN policy_assignments pa ON pa.policy_id = p.id
          ORDER BY FIELD(pa.scope_type,'global','client','site','group','device'), p.name"
    ) as $p) {
        $doc = json_decode((string)$p['doc_json'], true);
        if (!is_array($doc) || !isset($doc['patch_settings']) || !is_array($doc['patch_settings'])) continue;
        $sid = $p['scope_id'] !== null ? (int)$p['scope_id'] : null;
        $rules[] = [
            'policy_id'      => (int)$p['pid'],
            'name'           => (string)$p['name'],
            'scope_type'     => (string)$p['scope_type'],
            'scope_id'       => $sid,
            'scope_label'    => $label((string)$p['scope_type'], $sid),
            'is_enabled'     => (int)$p['is_enabled'],
            'patch_settings' => $doc['patch_settings'],
        ];
    }
} catch (Throwable $e) { $rules = []; }

json_out([
    'ok'       => true,
    'enabled'  => patch_enabled(),
    'rollouts' => $rollouts,
    'rules'    => $rules,
    'defaults' => patch_default_settings(),
    'scopes'   => ['clients' => $clients, 'sites' => $sites, 'groups' => $groups, 'agents' => $agents],
    'windows'  => $windows,
]);
