<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/patch.php';
enforce_https();
$user = require_login();
$csrf = csrf_token();
$isAdmin = ($user['role'] ?? '') === 'admin';
layout_header('Patches', $user);
?>
<div class="page-head"><h2>Patches</h2></div>
<?php if (!patch_enabled()): ?>
  <div class="alert">Patch management is off. Set <code>patch.enabled</code> in <code>config.php</code> to use rollouts.</div>
<?php endif; ?>

<section class="card">
  <div class="card-head">
    <h3>Rollouts</h3>
    <?php if ($isAdmin): ?><button class="btn-sm btn-ghost" id="ro-add">+ New rollout</button><?php endif; ?>
  </div>
  <p class="muted small">A rollout installs auto-approved updates ring by ring, only inside its maintenance window,
     and auto-advances after a bake period if healthy (else halts). Set each device's ring in <b>Patch policy</b> below.</p>
  <div id="ro-list"></div>

  <?php if ($isAdmin): ?>
  <form id="ro-editor" class="rule-editor" hidden>
    <div class="rule-editor-row">
      <label>Name<input id="ro-name" placeholder="July security rollout"></label>
      <label>Scope
        <select id="ro-scope-type"><option value="global">Global</option><option value="client">Client</option><option value="site">Site</option><option value="group">Group</option><option value="device">Device</option></select>
      </label>
      <label id="ro-target-wrap" hidden>Target<select id="ro-scope-id"></select></label>
      <label>Window<select id="ro-window"><option value="">(any active window)</option></select></label>
    </div>
    <div class="rule-editor-row">
      <label class="chk"><input type="checkbox" class="ro-ring" value="canary" checked> canary</label>
      <label class="chk"><input type="checkbox" class="ro-ring" value="early"> early</label>
      <label class="chk"><input type="checkbox" class="ro-ring" value="broad" checked> broad</label>
      <label>Bake (min)<input type="number" id="ro-advance" value="1440" step="1" style="width:90px"></label>
      <label>Auto-halt at failure %<input type="number" id="ro-maxfail" value="20" step="1" style="width:80px"></label>
    </div>
    <div class="rule-editor-actions">
      <button type="submit" class="btn-sm btn-primary">Create rollout</button>
      <button type="button" class="btn-sm btn-ghost" id="ro-cancel">Cancel</button>
      <span class="muted small" id="ro-msg"></span>
    </div>
  </form>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head">
    <h3>Patch policy</h3>
    <?php if ($isAdmin): ?><button class="btn-sm btn-ghost" id="pr-add">+ Add rule</button><?php endif; ?>
  </div>
  <p class="muted small">Ring, auto-approved classifications, and reboot policy — inherited Global → Client → Site → Group → Device.
     Defaults: ring <b>broad</b>, auto-approve <b>Security + Critical</b>, reboot <b>if required, in window</b>.</p>
  <div id="pr-list"></div>

  <?php if ($isAdmin): ?>
  <form id="pr-editor" class="rule-editor" hidden>
    <input type="hidden" id="pr-policy-id" value="">
    <div class="rule-editor-row">
      <label>Name<input id="pr-name" placeholder="optional"></label>
      <label>Scope
        <select id="pr-scope-type"><option value="global">Global</option><option value="client">Client</option><option value="site">Site</option><option value="group">Group</option><option value="device">Device</option></select>
      </label>
      <label id="pr-target-wrap" hidden>Target<select id="pr-scope-id"></select></label>
      <label>Ring<select id="pr-ring"><option value="canary">canary</option><option value="early">early</option><option value="broad">broad</option><option value="exclude">exclude</option></select></label>
      <label class="chk"><input type="checkbox" id="pr-enabled" checked> Enabled</label>
    </div>
    <div class="rule-editor-row">
      <label>Auto-approve
        <span style="display:flex;flex-wrap:wrap;gap:8px">
          <?php foreach (['SecurityUpdates'=>'Security','CriticalUpdates'=>'Critical','UpdateRollups'=>'Rollups','Updates'=>'Updates','Drivers'=>'Drivers','FeaturePacks'=>'Feature'] as $k=>$lbl): ?>
            <label class="chk"><input type="checkbox" class="pr-auto" value="<?= e($k) ?>"> <?= e($lbl) ?></label>
          <?php endforeach; ?>
        </span>
      </label>
    </div>
    <div class="rule-editor-row">
      <label>Reboot<select id="pr-reboot"><option value="if_required">if required (in window)</option><option value="never">never (report only)</option><option value="force">force (any time)</option></select></label>
      <label>Grace (min)<input type="number" id="pr-grace" value="60" step="1" style="width:80px"></label>
      <label class="chk"><input type="checkbox" id="pr-prompt" checked> Prompt user</label>
    </div>
    <div class="rule-editor-actions">
      <button type="submit" class="btn-sm btn-primary">Save policy</button>
      <button type="button" class="btn-sm btn-ghost" id="pr-cancel">Cancel</button>
      <span class="muted small" id="pr-msg"></span>
    </div>
  </form>
  <?php endif; ?>
</section>

<script>
(function () {
  const CSRF = <?= json_encode($csrf) ?>, ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
  let data = { rollouts: [], rules: [], scopes: {}, windows: [] };
  const $ = (id) => document.getElementById(id);
  const el = (t, c, x) => { const n = document.createElement(t); if (c) n.className = c; if (x !== undefined && x !== null) n.textContent = x; return n; };
  const btn = (l, c, f) => { const b = el('button', c, l); b.type = 'button'; b.addEventListener('click', f); return b; };

  async function post(p) { p.csrf = CSRF; const r = await fetch('patches_action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(p) }); return r.json().catch(() => ({ ok: false })); }
  async function load() {
    try { const r = await fetch('patches_data.php', { headers: { 'Accept': 'application/json' } }); if (!r.ok) return; data = await r.json(); renderRollouts(); renderRules(); fillScopeSelects(); } catch (e) {}
  }

  const RO_STATUS_TAG = { running: 'tag-sev-info', paused: 'tag-st-acked', halted: 'tag-sev-critical', completed: 'tag-st-resolved', draft: 'tag-st-acked' };
  function renderRollouts() {
    const w = $('ro-list'); w.textContent = '';
    if (!data.rollouts.length) { w.appendChild(el('p', 'muted small', 'No rollouts yet.')); return; }
    const tb = el('table', 'grid mini'), th = el('thead'), htr = el('tr');
    ['Rollout', 'Scope', 'Ring', 'Status', 'Progress', ''].forEach(h => htr.appendChild(el('th', null, h)));
    th.appendChild(htr); tb.appendChild(th);
    const body = el('tbody');
    for (const ro of data.rollouts) {
      const tr = el('tr');
      tr.appendChild(el('td', null, ro.name));
      tr.appendChild(el('td', 'muted', ro.scope_label));
      tr.appendChild(el('td', 'muted', (ro.current_ring || '—') + ' / ' + (ro.ring_order || []).join('→')));
      const st = el('td'); st.appendChild(el('span', 'tag ' + (RO_STATUS_TAG[ro.status] || 'tag-st-acked'), ro.status)); tr.appendChild(st);
      const p = ro.progress || {};
      tr.appendChild(el('td', 'muted small', `${p.verified || 0}✓ ${p.failed || 0}✗ ${(p.pending || 0) + (p.installing || 0) + (p.installed || 0)}⋯ / ${ro.total}`));
      const act = el('td');
      if (ADMIN) {
        if (ro.status === 'draft' || ro.status === 'paused') act.appendChild(btn('Start', 'btn-sm btn-primary', () => post({ action: 'rollout_status', id: ro.id, to: 'running' }).then(load)));
        if (ro.status === 'running') act.appendChild(btn('Pause', 'btn-sm btn-ghost', () => post({ action: 'rollout_status', id: ro.id, to: 'paused' }).then(load)));
        if (ro.status === 'running' || ro.status === 'paused') act.appendChild(btn('Halt', 'btn-sm btn-ghost', () => { if (confirm('Halt this rollout?')) post({ action: 'rollout_status', id: ro.id, to: 'halted' }).then(load); }));
        act.appendChild(btn('Delete', 'btn-sm btn-danger', () => { if (confirm('Delete this rollout?')) post({ action: 'rollout_delete', id: ro.id }).then(load); }));
      }
      tr.appendChild(act); body.appendChild(tr);
    }
    tb.appendChild(body); w.appendChild(tb);
  }

  function renderRules() {
    const w = $('pr-list'); w.textContent = '';
    if (!data.rules.length) { w.appendChild(el('p', 'muted small', 'No custom patch policy — built-in defaults apply (ring broad, auto-approve Security+Critical).')); return; }
    const tb = el('table', 'grid mini'), th = el('thead'), htr = el('tr');
    ['Rule', 'Scope', 'Ring', 'Auto-approve', 'Reboot', 'On', ''].forEach(h => htr.appendChild(el('th', null, h)));
    th.appendChild(htr); tb.appendChild(th);
    const body = el('tbody');
    for (const r of data.rules) {
      const ps = r.patch_settings || {};
      const tr = el('tr');
      tr.appendChild(el('td', null, r.name));
      tr.appendChild(el('td', 'muted', r.scope_label));
      tr.appendChild(el('td', null, ps.ring || 'broad'));
      tr.appendChild(el('td', 'muted small', (ps.auto_approve || []).join(', ') || '—'));
      tr.appendChild(el('td', 'muted small', (ps.reboot && ps.reboot.policy) || 'if_required'));
      tr.appendChild(el('td', null, r.is_enabled ? 'yes' : 'no'));
      const act = el('td');
      if (ADMIN) {
        act.appendChild(btn('Edit', 'btn-sm btn-ghost', () => openRule(r)));
        act.appendChild(btn('Delete', 'btn-sm btn-danger', () => { if (confirm('Delete this patch rule?')) post({ action: 'prule_delete', policy_id: r.policy_id }).then(load); }));
      }
      tr.appendChild(act); body.appendChild(tr);
    }
    tb.appendChild(body); w.appendChild(tb);
  }

  function optionsFor(type) { return { client: data.scopes.clients, site: data.scopes.sites, group: data.scopes.groups, device: data.scopes.agents }[type] || []; }
  function fillScopeSelects() {
    if (!ADMIN) return;
    const ws = $('ro-window'); if (ws) { ws.length = 1; for (const w of (data.windows || [])) { const o = el('option', null, w.name); o.value = w.id; ws.appendChild(o); } }
  }
  function fillTarget(typeSel, wrapId, idSel) {
    const type = $(typeSel).value, wrap = $(wrapId), sel = $(idSel);
    if (type === 'global') { wrap.hidden = true; return; }
    wrap.hidden = false; sel.textContent = '';
    for (const o of optionsFor(type)) { const opt = el('option', null, o.name); opt.value = o.id; sel.appendChild(opt); }
  }

  function openRule(r) {
    $('pr-editor').hidden = false; $('pr-msg').textContent = '';
    $('pr-policy-id').value = r ? r.policy_id : '';
    $('pr-name').value = r ? r.name : '';
    $('pr-scope-type').value = r ? r.scope_type : 'global';
    $('pr-enabled').checked = r ? !!r.is_enabled : true;
    const ps = (r && r.patch_settings) || {};
    $('pr-ring').value = ps.ring || 'broad';
    const auto = ps.auto_approve || (r ? [] : ['SecurityUpdates', 'CriticalUpdates']);
    document.querySelectorAll('.pr-auto').forEach(c => { c.checked = auto.indexOf(c.value) !== -1; });
    $('pr-reboot').value = (ps.reboot && ps.reboot.policy) || 'if_required';
    $('pr-grace').value = (ps.reboot && ps.reboot.grace_min != null) ? ps.reboot.grace_min : 60;
    $('pr-prompt').checked = ps.reboot ? !!ps.reboot.prompt_user : true;
    fillTarget('pr-scope-type', 'pr-target-wrap', 'pr-scope-id');
    if (r && r.scope_id) $('pr-scope-id').value = r.scope_id;
    $('pr-editor').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  if (ADMIN) {
    $('ro-add').addEventListener('click', () => { $('ro-editor').hidden = false; $('ro-msg').textContent = ''; fillTarget('ro-scope-type', 'ro-target-wrap', 'ro-scope-id'); });
    $('ro-cancel').addEventListener('click', () => { $('ro-editor').hidden = true; });
    $('ro-scope-type').addEventListener('change', () => fillTarget('ro-scope-type', 'ro-target-wrap', 'ro-scope-id'));
    $('ro-editor').addEventListener('submit', async (e) => {
      e.preventDefault();
      const rings = Array.from(document.querySelectorAll('.ro-ring')).filter(c => c.checked).map(c => c.value);
      const p = { action: 'rollout_create', name: $('ro-name').value, scope_type: $('ro-scope-type').value, window_id: $('ro-window').value, ring_order: rings.join(','), advance_after_min: $('ro-advance').value, max_failure_pct: $('ro-maxfail').value };
      if (p.scope_type !== 'global') p.scope_id = $('ro-scope-id').value || '';
      const res = await post(p);
      if (res && res.ok) { $('ro-editor').hidden = true; load(); } else { $('ro-msg').textContent = (res && res.error) || 'Failed.'; }
    });
    $('pr-add').addEventListener('click', () => openRule(null));
    $('pr-cancel').addEventListener('click', () => { $('pr-editor').hidden = true; });
    $('pr-scope-type').addEventListener('change', () => fillTarget('pr-scope-type', 'pr-target-wrap', 'pr-scope-id'));
    $('pr-editor').addEventListener('submit', async (e) => {
      e.preventDefault();
      const p = { action: 'prule_save', policy_id: $('pr-policy-id').value || '', name: $('pr-name').value, scope_type: $('pr-scope-type').value, ring: $('pr-ring').value, reboot_policy: $('pr-reboot').value, grace_min: $('pr-grace').value, is_enabled: $('pr-enabled').checked ? 1 : 0, prompt_user: $('pr-prompt').checked ? 1 : 0 };
      if (p.scope_type !== 'global') p.scope_id = $('pr-scope-id').value || '';
      const auto = Array.from(document.querySelectorAll('.pr-auto')).filter(c => c.checked).map(c => c.value);
      const body = new URLSearchParams(p); auto.forEach(a => body.append('auto_approve[]', a)); body.append('csrf', CSRF);
      const res = await fetch('patches_action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body }).then(r => r.json()).catch(() => ({ ok: false }));
      if (res && res.ok) { $('pr-editor').hidden = true; load(); } else { $('pr-msg').textContent = (res && res.error) || 'Failed.'; }
    });
  }

  load();
  setInterval(load, 30000);
})();
</script>
<?php layout_footer();
