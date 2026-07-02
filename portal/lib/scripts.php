<?php
/**
 * Milepost — Phase 4 (Automation), increment 1: the Script Library.
 *
 * Reusable, versioned scripts. Running a saved script on a device = enqueue a job with the script's
 * language (powershell|cmd) as job_type and its body as payload (the agent's existing JobRunner runs
 * it). Foundation for scheduled + event-driven automation. Management is admin-only (see automation.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function scripts_list(): array
{
    try {
        return db()->query('SELECT id, name, description, language, version, run_count, updated_at FROM scripts ORDER BY name')->fetchAll();
    } catch (Throwable $e) { return []; }
}

function script_get(int $id): ?array
{
    try { $st = db()->prepare('SELECT * FROM scripts WHERE id=?'); $st->execute([$id]); return $st->fetch() ?: null; }
    catch (Throwable $e) { return null; }
}

/** Create (id<=0) or update a script. Returns ['ok'=>bool, 'id'|'error'=>…]. */
function script_save(int $id, string $name, string $desc, string $language, string $body, int $uid): array
{
    $name = mb_substr(trim($name), 0, 160);
    if ($name === '')        return ['ok' => false, 'error' => 'Name is required.'];
    if (trim($body) === '')  return ['ok' => false, 'error' => 'Script body is required.'];
    if (!in_array($language, ['powershell', 'cmd'], true)) $language = 'powershell';
    $desc = mb_substr($desc, 0, 500);
    try {
        if ($id > 0) {
            db()->prepare('UPDATE scripts SET name=?, description=?, language=?, body=?, version=version+1 WHERE id=?')
                ->execute([$name, $desc, $language, $body, $id]);
        } else {
            db()->prepare('INSERT INTO scripts (name, description, language, body, created_by) VALUES (?,?,?,?,?)')
                ->execute([$name, $desc, $language, $body, $uid]);
            $id = (int)db()->lastInsertId();
        }
        return ['ok' => true, 'id' => $id];
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return ['ok' => false, 'error' => 'A script with that name already exists.'];
        return ['ok' => false, 'error' => 'Save failed.'];
    }
}

function script_delete(int $id): void
{
    try { db()->prepare('DELETE FROM scripts WHERE id=?')->execute([$id]); } catch (Throwable $e) {}
}

/**
 * Dispatch a saved script to an agent as a job (job_type = the script's language, payload = its body).
 * Returns the new job id, or 0 if the script/agent is missing. $uid null = a system/scheduled run.
 * Caller enforces admin + CSRF for human runs.
 */
function script_run_on_agent(int $scriptId, int $agentId, ?int $uid): int
{
    $s = script_get($scriptId);
    if (!$s || $agentId <= 0) return 0;
    db()->prepare('INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,?,?,?)')
        ->execute([$agentId, $uid, $s['language'], $s['body']]);
    $jid = (int)db()->lastInsertId();
    db()->prepare('UPDATE scripts SET run_count=run_count+1 WHERE id=?')->execute([$scriptId]);
    return $jid;
}

/* ── Scheduled scripts (increment 2) ─────────────────────────────────────────────────────────────
   A 1-min cron (cron/script_dispatch.php) fires due schedules → one job per in-scope agent. UTC. */

/** Schedules joined with their script name, for the UI. Tolerant of a pre-migration DB. */
function schedules_list(): array
{
    try {
        return db()->query(
            'SELECT ss.*, s.name AS script_name, s.language
               FROM script_schedules ss JOIN scripts s ON s.id = ss.script_id
              ORDER BY ss.name, ss.id'
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Non-archived agent ids in a schedule's scope (global/client/site/group/device). */
function schedule_scope_agents(string $type, ?int $id): array
{
    if ($type === 'global') {
        $rows = db()->query('SELECT id FROM agents WHERE is_archived=0')->fetchAll();
    } else {
        $col = ['client' => 'client_id', 'site' => 'site_id', 'group' => 'group_id', 'device' => 'id'][$type] ?? null;
        if ($col === null || $id === null) return [];
        $st = db()->prepare("SELECT id FROM agents WHERE is_archived=0 AND $col = ?");
        $st->execute([$id]);
        $rows = $st->fetchAll();
    }
    return array_map(static fn($r) => (int)$r['id'], $rows);
}

/**
 * Is this schedule due to run now? interval: every interval_min since last_run. daily/weekly: once
 * per day when now passes at_time (weekly only on matching dow). Catches up a single missed run if the
 * cron was down past the scheduled time (last_run < today's scheduled time).
 */
function schedule_is_due(array $s, int $nowTs): bool
{
    $rec  = (string)$s['recurrence'];
    $last = !empty($s['last_run_at']) ? strtotime($s['last_run_at'] . ' UTC') : 0;

    if ($rec === 'interval') {
        $iv = max(1, (int)$s['interval_min']) * 60;
        return $last === 0 || ($nowTs - $last) >= $iv;
    }
    // daily / weekly
    if ($rec === 'weekly' && (int)gmdate('w', $nowTs) !== (int)$s['dow']) return false;
    $at    = $s['at_time'] ?: '00:00:00';
    $sched = strtotime(gmdate('Y-m-d', $nowTs) . ' ' . $at . ' UTC');
    return $sched !== false && $nowTs >= $sched && $last < $sched;
}

/** Create (id<=0) or update a schedule. Returns ['ok'=>bool, 'id'|'error'=>…]. */
function schedule_save(array $in, ?int $uid): array
{
    $scriptId = (int)($in['script_id'] ?? 0);
    if ($scriptId <= 0 || !script_get($scriptId)) return ['ok' => false, 'error' => 'Pick a script.'];
    $scopeType = in_array($in['scope_type'] ?? 'global', ['global', 'client', 'site', 'group', 'device'], true) ? (string)$in['scope_type'] : 'global';
    $scopeId   = $scopeType === 'global' ? null : ((int)($in['scope_id'] ?? 0) ?: null);
    if ($scopeType !== 'global' && $scopeId === null) return ['ok' => false, 'error' => 'Pick a scope target.'];
    $rec = in_array($in['recurrence'] ?? 'daily', ['interval', 'daily', 'weekly'], true) ? (string)$in['recurrence'] : 'daily';
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 160);

    $atTime = null; $dow = null; $intervalMin = null;
    if ($rec === 'interval') {
        $intervalMin = max(1, (int)($in['interval_min'] ?? 60));
    } else {
        $t = trim((string)($in['at_time'] ?? ''));
        $atTime = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t) ? (strlen($t) === 5 ? $t . ':00' : $t) : '00:00:00';
        if ($rec === 'weekly') $dow = max(0, min(6, (int)($in['dow'] ?? 0)));
    }
    $enabled = array_key_exists('is_enabled', $in) ? (int)!!$in['is_enabled'] : 1;
    $id = (int)($in['id'] ?? 0);
    try {
        if ($id > 0) {
            db()->prepare('UPDATE script_schedules SET script_id=?,name=?,scope_type=?,scope_id=?,recurrence=?,at_time=?,dow=?,interval_min=?,is_enabled=? WHERE id=?')
                ->execute([$scriptId, $name, $scopeType, $scopeId, $rec, $atTime, $dow, $intervalMin, $enabled, $id]);
        } else {
            db()->prepare('INSERT INTO script_schedules (script_id,name,scope_type,scope_id,recurrence,at_time,dow,interval_min,is_enabled,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$scriptId, $name, $scopeType, $scopeId, $rec, $atTime, $dow, $intervalMin, $enabled, $uid]);
            $id = (int)db()->lastInsertId();
        }
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'Save failed.']; }
}

function schedule_delete(int $id): void
{
    try { db()->prepare('DELETE FROM script_schedules WHERE id=?')->execute([$id]); } catch (Throwable $e) {}
}

function schedule_set_enabled(int $id, bool $on): void
{
    try { db()->prepare('UPDATE script_schedules SET is_enabled=? WHERE id=?')->execute([$on ? 1 : 0, $id]); } catch (Throwable $e) {}
}
