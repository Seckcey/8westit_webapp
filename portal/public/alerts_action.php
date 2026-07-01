<?php
/**
 * JSON: alert + rule mutations (session-authed, CSRF-checked). POST only.
 *   action=ack|resolve            id=<alert_id>
 *   action=rule_save              policy_id?, name?, scope_type, scope_id?, is_enabled?, thresholds=<json>
 *   action=rule_delete            policy_id=<policy_id>
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';    // current_user()/csrf_check() live here (alerts.php alone does NOT pull them in)
require_once __DIR__ . '/../lib/alerts.php';
enforce_https();
$user = current_user();
if (!$user) json_err('Unauthorized', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('POST required', 405);
csrf_check();

$uid    = (int)$user['id'];
$action = (string)($_POST['action'] ?? '');

if ($action === 'ack') {
    $id = (int)($_POST['id'] ?? 0);
    $ok = alert_ack($id, $uid);
    if ($ok) audit($uid, null, 'alert_ack', 'alert#' . $id);
    json_out(['ok' => $ok]);
}

if ($action === 'resolve') {
    $id = (int)($_POST['id'] ?? 0);
    $ok = alert_manual_resolve($id);
    if ($ok) audit($uid, null, 'alert_resolve', 'alert#' . $id);
    json_out(['ok' => $ok]);
}

if ($action === 'rule_delete') {
    $pid = (int)($_POST['policy_id'] ?? 0);
    if ($pid <= 0) json_err('policy_id required', 400);
    // The `policies` table is shared with agent-facing operational policies (allow_remote, deny,
    // allowed_tools, metrics_interval_s, …). Only act on a policy that actually carries alert
    // thresholds, and if it ALSO carries operational keys, strip just the thresholds rather than
    // deleting the whole row (which would silently change fleet-wide policy resolution).
    $sel = db()->prepare('SELECT doc_json FROM policies WHERE id=?');
    $sel->execute([$pid]);
    $cur = $sel->fetchColumn();
    if ($cur === false) json_err('Not found', 404);
    $doc = json_decode((string)$cur, true);
    if (!is_array($doc) || !isset($doc['thresholds'])) json_err('Not an alert rule', 400);
    unset($doc['thresholds']);
    if ($doc === []) {
        db()->prepare('DELETE FROM policies WHERE id=?')->execute([$pid]);   // cascade removes the assignment
    } else {
        db()->prepare('UPDATE policies SET doc_json=? WHERE id=?')
            ->execute([json_encode($doc, JSON_UNESCAPED_SLASHES), $pid]);
    }
    audit($uid, null, 'alert_rule_delete', 'policy#' . $pid);
    json_out(['ok' => true]);
}

if ($action === 'rule_save') {
    // Scope.
    $scopeType = (string)($_POST['scope_type'] ?? 'global');
    if (!in_array($scopeType, ['global', 'client', 'site', 'group', 'device'], true)) json_err('Bad scope', 400);
    $scopeId = null;
    if ($scopeType !== 'global') {
        $scopeId = (int)($_POST['scope_id'] ?? 0);
        if ($scopeId <= 0) json_err('scope target required', 400);
        $tbl = ['client' => 'clients', 'site' => 'sites', 'group' => 'device_groups', 'device' => 'agents'][$scopeType];
        $chk = db()->prepare("SELECT id FROM $tbl WHERE id=?");
        $chk->execute([$scopeId]);
        if (!$chk->fetch()) json_err('scope target not found', 404);
    }

    // Thresholds — validate server-side (never trust the client's shape).
    $rawT  = json_decode((string)($_POST['thresholds'] ?? ''), true);
    $clean = [];
    if (is_array($rawT)) {
        $i = 0;
        foreach ($rawT as $k => $v) {
            if ($i++ >= 100) break;
            if (!is_string($k) || !preg_match('/^[a-z][a-z0-9_]{0,39}(:[A-Za-z0-9 :._\-]{1,64})?$/', $k)) continue;
            if (!is_array($v)) continue;
            $op    = in_array(($v['op'] ?? 'gt'), ['gt', 'lt', 'gte', 'lte', 'eq'], true) ? $v['op'] : 'gt';
            $entry = ['op' => $op];
            if (isset($v['warning'])  && is_numeric($v['warning']))  $entry['warning']  = (float)$v['warning'];
            if (isset($v['critical']) && is_numeric($v['critical'])) $entry['critical'] = (float)$v['critical'];
            $entry['for_min'] = max(0, (int)($v['for_min'] ?? 0));
            if (isset($v['clear_min']) && is_numeric($v['clear_min'])) $entry['clear_min'] = max(0, (int)$v['clear_min']);
            if (array_key_exists('enabled', $v)) $entry['enabled'] = (bool)$v['enabled'];
            if (!isset($entry['warning']) && !isset($entry['critical'])) continue;   // nothing to compare
            $clean[$k] = $entry;
        }
    }
    if (!$clean) json_err('No valid thresholds provided', 400);

    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 128);
    if ($name === '') {
        $name = 'Alerts — ' . ($scopeType === 'global' ? 'Global' : ucfirst($scopeType) . ' #' . $scopeId);
    }
    $isEnabled = array_key_exists('is_enabled', $_POST) ? (int)!!$_POST['is_enabled'] : 1;

    $pdo      = db();
    $policyId = (int)($_POST['policy_id'] ?? 0);
    if ($policyId > 0) {
        // Read-modify-write: set ONLY the thresholds key, preserving any operational keys the policy
        // already carries (this policy row is shared with agent-facing policy resolution).
        $sel = $pdo->prepare('SELECT doc_json FROM policies WHERE id=?');
        $sel->execute([$policyId]);
        $cur     = $sel->fetchColumn();
        $decoded = ($cur !== false) ? json_decode((string)$cur, true) : [];
        if (!is_array($decoded)) $decoded = [];
        $decoded['thresholds'] = $clean;
        $doc = json_encode($decoded, JSON_UNESCAPED_SLASHES);
        $pdo->prepare('UPDATE policies SET name=?, doc_json=? WHERE id=?')->execute([$name, $doc, $policyId]);
        $pdo->prepare('UPDATE policy_assignments SET scope_type=?, scope_id=?, is_enabled=? WHERE policy_id=?')
            ->execute([$scopeType, $scopeId, $isEnabled, $policyId]);
    } else {
        $doc = json_encode(['thresholds' => $clean], JSON_UNESCAPED_SLASHES);
        $pdo->prepare('INSERT INTO policies (name, description, doc_json, created_by) VALUES (?,?,?,?)')
            ->execute([$name, 'Alert thresholds', $doc, $uid]);
        $policyId = (int)$pdo->lastInsertId();
        try {
            $pdo->prepare('INSERT INTO policy_assignments (policy_id, scope_type, scope_id, priority, is_enabled) VALUES (?,?,?,?,?)')
                ->execute([$policyId, $scopeType, $scopeId, 100, $isEnabled]);
        } catch (PDOException $e) { /* uq_assign collision — assignment already exists */ }
    }
    audit($uid, null, 'alert_rule_save', 'policy#' . $policyId . ' ' . $scopeType . ($scopeId ? (':' . $scopeId) : ''));
    json_out(['ok' => true, 'policy_id' => $policyId]);
}

json_err('Unknown action', 400);
