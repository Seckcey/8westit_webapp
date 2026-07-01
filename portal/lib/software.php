<?php
/**
 * Milepost — Phase 3: fleet software inventory + license tracking.
 *
 * The agent already collects installed software (inventory.data_json['software'] = [{name,version,
 * publisher}]). This lib aggregates it across the fleet and auto-counts license "seats used" by
 * matching each tracked license's match_name against that inventory. Portal-only; no agent change.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Every non-archived agent's decoded software list, read once: [agent_id => [names…]]. */
function software_device_lists(): array
{
    $out = [];
    try {
        foreach (db()->query('SELECT i.agent_id, i.data_json FROM inventory i JOIN agents a ON a.id=i.agent_id WHERE a.is_archived=0') as $row) {
            $data = json_decode((string)$row['data_json'], true);
            $names = [];
            if (is_array($data) && !empty($data['software']) && is_array($data['software'])) {
                foreach ($data['software'] as $s) {
                    if (is_array($s)) $names[] = $s;   // keep {name,version,publisher}
                }
            }
            $out[(int)$row['agent_id']] = $names;
        }
    } catch (Throwable $e) { return []; }
    return $out;
}

/**
 * Aggregate installed software across the fleet → [{name, publisher, devices, versions[]}], most-
 * installed first. Optional case-insensitive substring $search. Each app counts once per device.
 */
function software_fleet_inventory(?string $search = null, int $limit = 1000): array
{
    $search = ($search !== null) ? trim($search) : '';
    $agg = [];
    foreach (software_device_lists() as $names) {
        $seen = [];
        foreach ($names as $s) {
            $name = trim((string)($s['name'] ?? ''));
            if ($name === '') continue;
            $key = mb_strtolower($name);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            if ($search !== '' && stripos($name, $search) === false) continue;
            if (!isset($agg[$key])) $agg[$key] = ['name' => $name, 'publisher' => (string)($s['publisher'] ?? ''), 'devices' => 0, 'versions' => []];
            $agg[$key]['devices']++;
            $v = trim((string)($s['version'] ?? ''));
            if ($v !== '' && count($agg[$key]['versions']) < 12 && !in_array($v, $agg[$key]['versions'], true)) $agg[$key]['versions'][] = $v;
        }
    }
    uasort($agg, static fn($a, $b) => ($b['devices'] <=> $a['devices']) ?: strcasecmp($a['name'], $b['name']));
    return array_slice(array_values($agg), 0, $limit);
}

/** Tracked licenses with computed seats_used (devices whose software matches match_name). */
function licenses_list(): array
{
    try { $rows = db()->query('SELECT * FROM software_licenses ORDER BY product')->fetchAll(); }
    catch (Throwable $e) { return []; }
    if (!$rows) return [];
    // Build one lowercased name-blob per device, then substring-match each license (inventory read once).
    $blobs = [];
    foreach (software_device_lists() as $names) {
        $parts = [];
        foreach ($names as $s) $parts[] = mb_strtolower((string)($s['name'] ?? ''));
        $blobs[] = implode("\n", $parts);
    }
    foreach ($rows as &$r) {
        $needle = mb_strtolower(trim((string)$r['match_name']));
        $used = 0;
        if ($needle !== '') foreach ($blobs as $b) { if ($b !== '' && strpos($b, $needle) !== false) $used++; }
        $r['seats_used'] = $used;
    }
    return $rows;
}
