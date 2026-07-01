<?php
/**
 * Policy inheritance resolution (spec §4.2) — pure PHP, HostGator-safe (no recursive SQL).
 *
 * Inheritance order: global → client → site → group → device (device wins).
 * For one device we gather all ENABLED policy_assignments whose (scope_type, scope_id)
 * match its chain, deep-merge each policy's doc_json in that order (later overrides earlier;
 * within one scope level, higher `priority` wins; explicit `deny` arrays always accumulate
 * and win). The merged result's short hash becomes agents.policy_etag.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/**
 * Built-in defaults — the floor every device inherits before any assignment applies.
 * Mirrors the shape returned by /api/svc/agent_policy.php (`effective`).
 */
function policy_defaults(): array
{
    return [
        'metrics_interval_s' => 60,
        'allow_remote'       => true,
        'allowed_tools'      => [],
        'deny'               => [],
        'auto_approve_tiers' => ['read'],
        'max_blast_radius'   => 1,
    ];
}

/**
 * Recursive deep-merge of policy docs.
 *  - associative arrays merge key-by-key (later wins)
 *  - the special key `deny` is treated as a set and UNIONed (never overwritten)
 *  - lists (non-`deny`) and scalars are replaced wholesale by the later doc
 */
function policy_merge(array $base, array $over): array
{
    foreach ($over as $k => $v) {
        if ($k === 'deny') {
            $existing = isset($base['deny']) && is_array($base['deny']) ? $base['deny'] : [];
            $incoming = is_array($v) ? $v : [];
            $base['deny'] = array_values(array_unique(array_merge($existing, $incoming)));
            continue;
        }
        if (is_array($v) && isset($base[$k]) && is_array($base[$k])
            && policy_is_assoc($v) && policy_is_assoc($base[$k])) {
            $base[$k] = policy_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function policy_is_assoc(array $a): bool
{
    if ($a === []) return false;
    return array_keys($a) !== range(0, count($a) - 1);
}

/**
 * Load the agent's hierarchy chain (client_id, site_id, group_id, id) — tolerant of the
 * new columns being absent on a not-yet-migrated DB.
 */
function policy_agent_chain(int $agentId): ?array
{
    try {
        $stmt = db()->prepare('SELECT id, client_id, site_id, group_id FROM agents WHERE id = ?');
        $stmt->execute([$agentId]);
    } catch (Throwable $e) {
        // Pre-migration DB without site_id/group_id columns.
        $stmt = db()->prepare('SELECT id, client_id FROM agents WHERE id = ?');
        $stmt->execute([$agentId]);
        $r = $stmt->fetch();
        if (!$r) return null;
        $r['site_id'] = null;
        $r['group_id'] = null;
        return $r;
    }
    return $stmt->fetch() ?: null;
}

/**
 * Compute the effective policy for an agent by merging all matching enabled assignments.
 * Returns ['effective'=>array, 'etag'=>string].
 */
function effective_policy_for_agent(int $agentId): array
{
    $chain = policy_agent_chain($agentId);
    if (!$chain) {
        $eff = policy_defaults();
        return ['effective' => $eff, 'etag' => policy_etag($eff)];
    }

    // Build the ordered list of (scope_type, scope_id) we care about.
    $scopes = [['global', null]];
    if (!empty($chain['client_id'])) $scopes[] = ['client', (int)$chain['client_id']];
    if (!empty($chain['site_id']))   $scopes[] = ['site',   (int)$chain['site_id']];
    if (!empty($chain['group_id']))  $scopes[] = ['group',  (int)$chain['group_id']];
    $scopes[] = ['device', (int)$chain['id']];

    // Pull every enabled assignment + its policy doc in one query, then bucket by scope.
    try {
        $stmt = db()->query(
            'SELECT pa.scope_type, pa.scope_id, pa.priority, p.doc_json
               FROM policy_assignments pa
               JOIN policies p ON p.id = pa.policy_id
              WHERE pa.is_enabled = 1'
        );
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Policy tables not migrated yet — return defaults.
        $eff = policy_defaults();
        return ['effective' => $eff, 'etag' => policy_etag($eff)];
    }

    // index: "scope_type:scope_id" => list of [priority, docArray]
    $byScope = [];
    foreach ($rows as $r) {
        $key = $r['scope_type'] . ':' . ($r['scope_id'] === null ? '' : (string)$r['scope_id']);
        $doc = json_decode((string)$r['doc_json'], true);
        if (!is_array($doc)) $doc = [];
        $byScope[$key][] = ['priority' => (int)$r['priority'], 'doc' => $doc];
    }

    $eff = policy_defaults();
    foreach ($scopes as [$type, $sid]) {
        $key = $type . ':' . ($sid === null ? '' : (string)$sid);
        if (empty($byScope[$key])) continue;
        // Within one scope level, lower priority applies first so higher priority wins.
        usort($byScope[$key], static fn($a, $b) => $a['priority'] <=> $b['priority']);
        foreach ($byScope[$key] as $entry) {
            $eff = policy_merge($eff, $entry['doc']);
        }
    }

    // The etag drives the agent/backend policy refetch. `thresholds` is a SERVER-SIDE concern
    // (evaluated in metrics_snapshot.php; the agent never acts on it), so exclude it from the etag
    // — editing an alert threshold must not force every agent to re-pull its policy.
    $forEtag = $eff;
    unset($forEtag['thresholds']);
    return ['effective' => $eff, 'etag' => policy_etag($forEtag)];
}

/** Short, stable 12-char tag for an effective-policy array (fits agents.policy_etag CHAR(12)). */
function policy_etag(array $effective): string
{
    $canon = json_encode($effective, JSON_UNESCAPED_SLASHES);
    return 'p-' . substr(hash('sha256', (string)$canon), 0, 10);
}

/**
 * Recompute and persist agents.policy_etag for a single agent. Call this whenever any
 * assignment in the agent's chain changes so the backend knows to refetch. Returns the etag.
 * Tolerant of a pre-migration DB (no-op persist).
 */
function refresh_agent_policy_etag(int $agentId): string
{
    $res  = effective_policy_for_agent($agentId);
    $etag = $res['etag'];
    try {
        db()->prepare('UPDATE agents SET policy_etag = ? WHERE id = ?')->execute([$etag, $agentId]);
    } catch (Throwable $e) { /* column not present yet */ }
    return $etag;
}
