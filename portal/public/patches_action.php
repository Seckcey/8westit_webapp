<?php
/** JSON: patch rollout + patch-policy mutations (session-authed, CSRF, ADMIN-ONLY). POST only. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/patch.php';
enforce_https();
$user = current_user();
if (!$user) json_err('Unauthorized', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);
csrf_check();
// Patch automation installs updates + reboots endpoints fleet-wide — admin-only.
if (($user['role'] ?? '') !== 'admin') json_err('Only admins can manage patch rollouts.', 403);

$uid    = (int)$user['id'];
$action = (string)($_POST['action'] ?? '');
$pdo    = db();

$validateScope = static function (string $type, ?int $id) use ($pdo): void {
    if ($type === 'global') return;
    $tbl = ['client' => 'clients', 'site' => 'sites', 'group' => 'device_groups', 'device' => 'agents'][$type] ?? null;
    if ($tbl === null || $id === null || $id <= 0) json_err('scope target required', 400);
    $chk = $pdo->prepare("SELECT id FROM $tbl WHERE id=?");
    $chk->execute([$id]);
    if (!$chk->fetch()) json_err('scope target not found', 404);
};

if ($action === 'rollout_create') {
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 128); if ($name === '') $name = 'Rollout';
    $scopeType = (string)($_POST['scope_type'] ?? 'global');
    if (!in_array($scopeType, ['global', 'client', 'site', 'group', 'device'], true)) json_err('Bad scope', 400);
    $scopeId = $scopeType === 'global' ? null : (int)($_POST['scope_id'] ?? 0);
    $validateScope($scopeType, $scopeId);
    $windowId = ($_POST['window_id'] ?? '') !== '' ? (int)$_POST['window_id'] : null;
    if ($windowId !== null) {
        $chk = $pdo->prepare('SELECT id FROM maintenance_windows WHERE id=?'); $chk->execute([$windowId]);
        if (!$chk->fetch()) $windowId = null;
    }
    $known = ['canary', 'early', 'broad'];
    $rings = [];
    foreach (explode(',', (string)($_POST['ring_order'] ?? '')) as $r) {
        $r = strtolower(trim($r));
        if (in_array($r, $known, true) && !in_array($r, $rings, true)) $rings[] = $r;
    }
    if (!$rings) $rings = ['canary', 'broad'];
    $advance = max(0, (int)($_POST['advance_after_min'] ?? 1440));
    $maxfail = max(0.0, min(100.0, (float)($_POST['max_failure_pct'] ?? 20)));
    $pdo->prepare(
        "INSERT INTO patch_rollouts (name,scope_type,scope_id,window_id,ring_order,current_ring,status,advance_after_min,max_failure_pct,created_by)
         VALUES (?,?,?,?,?,'','draft',?,?,?)"
    )->execute([$name, $scopeType, $scopeId, $windowId, json_encode($rings), $advance, $maxfail, $uid]);
    $rid = (int)$pdo->lastInsertId();
    audit($uid, null, 'patch_rollout_create', "rollout#$rid $scopeType" . ($scopeId ? ":$scopeId" : ''));
    json_out(['ok' => true, 'id' => $rid]);
}

if ($action === 'rollout_status') {
    $rid = (int)($_POST['id'] ?? 0);
    $to  = (string)($_POST['to'] ?? '');
    if ($rid <= 0) json_err('id required', 400);
    if (!in_array($to, ['running', 'paused', 'halted'], true)) json_err('bad status', 400);
    $pdo->prepare('UPDATE patch_rollouts SET status=? WHERE id=?')->execute([$to, $rid]);
    audit($uid, null, 'patch_rollout_status', "rollout#$rid -> $to");
    json_out(['ok' => true]);
}

if ($action === 'rollout_delete') {
    $rid = (int)($_POST['id'] ?? 0);
    if ($rid <= 0) json_err('id required', 400);
    $pdo->prepare('DELETE FROM patch_rollouts WHERE id=?')->execute([$rid]);   // cascades targets
    audit($uid, null, 'patch_rollout_delete', "rollout#$rid");
    json_out(['ok' => true]);
}

if ($action === 'rollout_rollback') {
    // Best-effort roll back the KBs Milepost installed for this rollout's current ring. Fix-forward is
    // primary; some updates can't be uninstalled (a 1.4.0+ agent reports that per-KB). This is the manual
    // recourse after an auto-halt — queue one patch_rollback job per target that actually installed.
    $rid = (int)($_POST['id'] ?? 0);
    if ($rid <= 0) json_err('id required', 400);
    $ro = patch_rollout_get($rid);
    if (!$ro) json_err('Not found', 404);
    $ring = (string)($ro['current_ring'] ?? '');
    if ($ring !== '') {
        $st = $pdo->prepare("SELECT agent_id, kb_list FROM patch_rollout_targets WHERE rollout_id=? AND ring=? AND kb_list IS NOT NULL AND kb_list<>''");
        $st->execute([$rid, $ring]);
    } else {
        $st = $pdo->prepare("SELECT agent_id, kb_list FROM patch_rollout_targets WHERE rollout_id=? AND kb_list IS NOT NULL AND kb_list<>''");
        $st->execute([$rid]);
    }
    $n = 0;
    foreach ($st->fetchAll() as $t) {
        $kbs = patch_kb_sanitize((string)$t['kb_list']);
        if (!$kbs) continue;
        $pdo->prepare("INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,?,'patch_rollback',?)")
            ->execute([(int)$t['agent_id'], $uid, implode(',', $kbs)]);
        $n++;
    }
    audit($uid, null, 'patch_rollout_rollback', "rollout#$rid ring=$ring targets=$n");
    json_out(['ok' => true, 'targets' => $n]);
}

if ($action === 'prule_save') {
    $scopeType = (string)($_POST['scope_type'] ?? 'global');
    if (!in_array($scopeType, ['global', 'client', 'site', 'group', 'device'], true)) json_err('Bad scope', 400);
    $scopeId = $scopeType === 'global' ? null : (int)($_POST['scope_id'] ?? 0);
    $validateScope($scopeType, $scopeId);

    $ring = in_array(($_POST['ring'] ?? 'broad'), ['canary', 'early', 'broad', 'exclude'], true) ? (string)$_POST['ring'] : 'broad';
    $auto = [];
    $validClasses = ['SecurityUpdates', 'CriticalUpdates', 'UpdateRollups', 'Updates', 'Drivers', 'FeaturePacks'];
    foreach ((array)($_POST['auto_approve'] ?? []) as $a) {
        $a = (string)$a;
        if (in_array($a, $validClasses, true) && !in_array($a, $auto, true)) $auto[] = $a;
    }
    $rpolicy = in_array(($_POST['reboot_policy'] ?? 'if_required'), ['if_required', 'never', 'force'], true) ? (string)$_POST['reboot_policy'] : 'if_required';
    $ps = [
        'ring'         => $ring,
        'auto_approve' => $auto,
        'reboot'       => ['policy' => $rpolicy, 'grace_min' => max(0, (int)($_POST['grace_min'] ?? 60)), 'prompt_user' => !empty($_POST['prompt_user'])],
    ];
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 128);
    if ($name === '') $name = 'Patch policy — ' . ($scopeType === 'global' ? 'Global' : ucfirst($scopeType) . ' #' . $scopeId);
    $isEnabled = array_key_exists('is_enabled', $_POST) ? (int)!!$_POST['is_enabled'] : 1;

    $policyId = (int)($_POST['policy_id'] ?? 0);
    if ($policyId > 0) {
        $cur = $pdo->prepare('SELECT doc_json FROM policies WHERE id=?'); $cur->execute([$policyId]);
        $c   = $cur->fetchColumn();
        $doc = ($c !== false) ? json_decode((string)$c, true) : [];
        if (!is_array($doc)) $doc = [];
        $doc['patch_settings'] = $ps;
        $pdo->prepare('UPDATE policies SET name=?, doc_json=? WHERE id=?')->execute([$name, json_encode($doc, JSON_UNESCAPED_SLASHES), $policyId]);
        $pdo->prepare('UPDATE policy_assignments SET scope_type=?, scope_id=?, is_enabled=? WHERE policy_id=?')->execute([$scopeType, $scopeId, $isEnabled, $policyId]);
    } else {
        $pdo->prepare('INSERT INTO policies (name, description, doc_json, created_by) VALUES (?,?,?,?)')
            ->execute([$name, 'Patch settings', json_encode(['patch_settings' => $ps], JSON_UNESCAPED_SLASHES), $uid]);
        $policyId = (int)$pdo->lastInsertId();
        try {
            $pdo->prepare('INSERT INTO policy_assignments (policy_id, scope_type, scope_id, priority, is_enabled) VALUES (?,?,?,100,?)')
                ->execute([$policyId, $scopeType, $scopeId, $isEnabled]);
        } catch (PDOException $e) { /* uq_assign collision */ }
    }
    audit($uid, null, 'patch_rule_save', "policy#$policyId $scopeType" . ($scopeId ? ":$scopeId" : ''));
    json_out(['ok' => true, 'policy_id' => $policyId]);
}

if ($action === 'prule_delete') {
    $pid = (int)($_POST['policy_id'] ?? 0);
    if ($pid <= 0) json_err('policy_id required', 400);
    $sel = $pdo->prepare('SELECT doc_json FROM policies WHERE id=?'); $sel->execute([$pid]);
    $cur = $sel->fetchColumn();
    if ($cur === false) json_err('Not found', 404);
    $doc = json_decode((string)$cur, true);
    if (!is_array($doc) || !isset($doc['patch_settings'])) json_err('Not a patch policy', 400);
    unset($doc['patch_settings']);
    if ($doc === []) $pdo->prepare('DELETE FROM policies WHERE id=?')->execute([$pid]);
    else $pdo->prepare('UPDATE policies SET doc_json=? WHERE id=?')->execute([json_encode($doc, JSON_UNESCAPED_SLASHES), $pid]);
    audit($uid, null, 'patch_rule_delete', "policy#$pid");
    json_out(['ok' => true]);
}

json_err('Unknown action', 400);
