<?php
/**
 * Milepost — Phase 4 (Automation), increment 3: event-driven automations + self-healing playbooks.
 *
 * An automation fires a saved script on the alerting device when an OPEN alert matches its
 * rule/severity/scope, subject to a per-agent cooldown and a per-automation daily cap. The engine is
 * cron/automation_run.php. Master kill-switch config.php automation.enabled (default off).
 */
declare(strict_types=1);
require_once __DIR__ . '/scripts.php';   // script_run_on_agent + script_get (pulls bootstrap)

function automation_enabled(): bool
{
    $a = cfg('automation', []);
    return is_array($a) && !empty($a['enabled']);
}

function automations_list(): array
{
    try {
        return db()->query('SELECT a.*, s.name AS script_name FROM automations a JOIN scripts s ON s.id = a.script_id ORDER BY a.name, a.id')->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Create (id<=0) or update an automation. Returns ['ok'=>bool, 'id'|'error'=>…]. */
function automation_save(array $in, ?int $uid): array
{
    $scriptId = (int)($in['script_id'] ?? 0);
    if ($scriptId <= 0 || !script_get($scriptId)) return ['ok' => false, 'error' => 'Pick a script.'];
    $scopeType = in_array($in['scope_type'] ?? 'global', ['global', 'client', 'site', 'group', 'device'], true) ? (string)$in['scope_type'] : 'global';
    $scopeId   = $scopeType === 'global' ? null : ((int)($in['scope_id'] ?? 0) ?: null);
    if ($scopeType !== 'global' && $scopeId === null) return ['ok' => false, 'error' => 'Pick a scope target.'];
    $sev  = in_array($in['match_severity'] ?? 'any', ['any', 'warning', 'critical'], true) ? (string)$in['match_severity'] : 'any';
    $rule = mb_substr(trim((string)($in['match_rule'] ?? '')), 0, 80);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 160);
    $cool = max(0, (int)($in['cooldown_min'] ?? 60));
    $maxd = max(0, (int)($in['max_per_day'] ?? 10));
    $en   = array_key_exists('is_enabled', $in) ? (int)!!$in['is_enabled'] : 1;
    $id   = (int)($in['id'] ?? 0);
    try {
        if ($id > 0) {
            db()->prepare('UPDATE automations SET name=?,match_rule=?,match_severity=?,scope_type=?,scope_id=?,script_id=?,cooldown_min=?,max_per_day=?,is_enabled=? WHERE id=?')
                ->execute([$name, $rule, $sev, $scopeType, $scopeId, $scriptId, $cool, $maxd, $en, $id]);
        } else {
            db()->prepare('INSERT INTO automations (name,match_rule,match_severity,scope_type,scope_id,script_id,cooldown_min,max_per_day,is_enabled,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$name, $rule, $sev, $scopeType, $scopeId, $scriptId, $cool, $maxd, $en, $uid]);
            $id = (int)db()->lastInsertId();
        }
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'Save failed.']; }
}

function automation_delete(int $id): void
{
    try { db()->prepare('DELETE FROM automations WHERE id=?')->execute([$id]); } catch (Throwable $e) {}
}

function automation_set_enabled(int $id, bool $on): void
{
    try { db()->prepare('UPDATE automations SET is_enabled=? WHERE id=?')->execute([$on ? 1 : 0, $id]); } catch (Throwable $e) {}
}

/** Does automation $a apply to open alert $al (rule substring + severity + the alert agent's scope)? */
function automation_matches(array $a, array $al): bool
{
    $rule = (string)$a['match_rule'];
    if ($rule !== '' && stripos((string)$al['rule_key'], $rule) === false) return false;
    if ($a['match_severity'] !== 'any' && (string)$al['severity'] !== (string)$a['match_severity']) return false;

    $type = (string)$a['scope_type'];
    if ($type === 'global') return true;
    $aid = (int)$al['agent_id'];
    $sid = $a['scope_id'] !== null ? (int)$a['scope_id'] : 0;
    if ($type === 'device') return $aid === $sid;

    static $cache = [];
    if (!isset($cache[$aid])) {
        $st = db()->prepare('SELECT client_id, site_id, group_id FROM agents WHERE id=?');
        $st->execute([$aid]);
        $cache[$aid] = $st->fetch() ?: [];
    }
    $ag = $cache[$aid];
    $col = ['client' => 'client_id', 'site' => 'site_id', 'group' => 'group_id'][$type] ?? null;
    return $col !== null && (int)($ag[$col] ?? 0) === $sid;
}

/**
 * Ready-to-add self-healing playbook scripts (admin one-click "Add to library"). Concrete + safe
 * remediations; the admin then wires an automation (e.g. clear_temp on a disk_free/disk_pct alert).
 */
function automation_playbook_templates(): array
{
    return [
        ['key' => 'clear_temp', 'name' => 'Clear temporary files', 'language' => 'powershell',
         'desc' => 'Deletes %TEMP% and C:\\Windows\\Temp contents to reclaim disk. Self-heal for low-disk alerts.',
         'body' => "Get-ChildItem -Path \$env:TEMP,'C:\\Windows\\Temp' -Recurse -Force -ErrorAction SilentlyContinue | " .
                   "Remove-Item -Recurse -Force -ErrorAction SilentlyContinue\nWrite-Output 'Temp files cleared.'"],
        ['key' => 'flush_dns', 'name' => 'Flush DNS cache', 'language' => 'powershell',
         'desc' => 'Clears the DNS resolver cache. Self-heal for name-resolution problems.',
         'body' => "Clear-DnsClientCache\nWrite-Output 'DNS cache flushed.'"],
        ['key' => 'restart_spooler', 'name' => 'Restart Print Spooler', 'language' => 'powershell',
         'desc' => 'Restarts the Print Spooler service (example service self-heal).',
         'body' => "Restart-Service -Name Spooler -Force -ErrorAction SilentlyContinue\nWrite-Output 'Print Spooler restarted.'"],
    ];
}
