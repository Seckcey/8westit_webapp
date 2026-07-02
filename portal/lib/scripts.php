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
 * Returns the new job id, or 0 if the script/agent is missing. Caller enforces admin + CSRF.
 */
function script_run_on_agent(int $scriptId, int $agentId, int $uid): int
{
    $s = script_get($scriptId);
    if (!$s || $agentId <= 0) return 0;
    db()->prepare('INSERT INTO jobs (agent_id, created_by, job_type, payload) VALUES (?,?,?,?)')
        ->execute([$agentId, $uid, $s['language'], $s['body']]);
    $jid = (int)db()->lastInsertId();
    db()->prepare('UPDATE scripts SET run_count=run_count+1 WHERE id=?')->execute([$scriptId]);
    return $jid;
}
