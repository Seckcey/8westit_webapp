<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();
$csrf = csrf_token();
layout_header('Alerts', $user);
?>
<div class="page-head">
  <h2>Alerts</h2>
  <div class="seg" id="filter-tabs" role="tablist">
    <button class="seg-btn is-on" data-status="active">Active</button>
    <button class="seg-btn" data-status="resolved">Resolved</button>
    <button class="seg-btn" data-status="all">All</button>
  </div>
</div>

<section class="card">
  <table class="grid">
    <thead>
      <tr><th>Severity</th><th>Device</th><th>Condition</th><th>Opened</th><th>Status</th><th></th></tr>
    </thead>
    <tbody id="alerts-body">
      <tr><td colspan="6" class="muted">Loading…</td></tr>
    </tbody>
  </table>
</section>

<section class="card">
  <div class="card-head">
    <h3>Threshold rules</h3>
    <button class="btn-sm btn-ghost" id="rule-add">+ Add rule</button>
  </div>
  <p class="muted small">Rules are inherited <b>Global → Client → Site → Group → Device</b> (the most specific
     wins per metric). Any metric you don't set here falls back to Milepost's built-in defaults, so alerting
     works out of the box.</p>
  <div id="rules-list"></div>

  <form id="rule-editor" class="rule-editor" hidden>
    <input type="hidden" id="r-policy-id" value="">
    <div class="rule-editor-row">
      <label>Name<input id="r-name" placeholder="optional"></label>
      <label>Scope
        <select id="r-scope-type">
          <option value="global">Global (all devices)</option>
          <option value="client">Client</option>
          <option value="site">Site</option>
          <option value="group">Group</option>
          <option value="device">Device</option>
        </select>
      </label>
      <label id="r-target-wrap" hidden>Target<select id="r-scope-id"></select></label>
      <label class="chk"><input type="checkbox" id="r-enabled" checked> Enabled</label>
    </div>
    <table class="grid mini">
      <thead><tr><th>Metric</th><th>Test</th><th>Warning</th><th>Critical</th><th>For (min)</th></tr></thead>
      <tbody id="r-metrics"></tbody>
    </table>
    <div class="rule-editor-actions">
      <button type="submit" class="btn-sm btn-primary">Save rule</button>
      <button type="button" class="btn-sm btn-ghost" id="rule-cancel">Cancel</button>
      <span class="muted small" id="rule-msg"></span>
    </div>
  </form>
</section>

<section class="card">
  <div class="card-head">
    <h3>Maintenance windows</h3>
    <button class="btn-sm btn-ghost" id="mw-add">+ Add window</button>
  </div>
  <p class="muted small">While a window is active, alerting is <b>fully suppressed</b> for matching
     devices (no alerts, no notifications) — use it around planned reboots/patching. Scope inherits like
     rules. All times are <b>UTC</b>.</p>
  <div id="mw-list"></div>

  <form id="mw-editor" class="rule-editor" hidden>
    <input type="hidden" id="mw-id" value="">
    <div class="rule-editor-row">
      <label>Name<input id="mw-name" placeholder="e.g. Sunday patching"></label>
      <label>Scope
        <select id="mw-scope-type">
          <option value="global">Global (all devices)</option>
          <option value="client">Client</option>
          <option value="site">Site</option>
          <option value="group">Group</option>
          <option value="device">Device</option>
        </select>
      </label>
      <label id="mw-target-wrap" hidden>Target<select id="mw-scope-id"></select></label>
      <label class="chk"><input type="checkbox" id="mw-enabled" checked> Enabled</label>
    </div>
    <div class="rule-editor-row">
      <label>Start (UTC)<input type="datetime-local" id="mw-start"></label>
      <label>End (UTC)<input type="datetime-local" id="mw-end"></label>
      <label>Repeat
        <select id="mw-recurrence">
          <option value="none">Once</option>
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
        </select>
      </label>
    </div>
    <div class="rule-editor-actions">
      <button type="submit" class="btn-sm btn-primary">Save window</button>
      <button type="button" class="btn-sm btn-ghost" id="mw-cancel">Cancel</button>
      <span class="muted small" id="mw-msg"></span>
    </div>
  </form>
</section>

<script>
(function () {
  const CSRF = <?= json_encode($csrf) ?>;
  const OPS = { gt: '>', lt: '<', gte: '≥', lte: '≤', eq: '=' };
  let state = 'active';
  let data = { alerts: [], rules: [], scopes: {}, metrics: [] };
  let editThresholds = {};   // the doc of the rule being edited — preserved across save (see collectThresholds)

  const $ = (id) => document.getElementById(id);
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  };

  function humanAge(secs) {
    if (secs === null || secs === undefined) return '';
    if (secs < 60) return secs + 's';
    if (secs < 3600) return Math.floor(secs / 60) + 'm';
    if (secs < 86400) return Math.floor(secs / 3600) + 'h';
    return Math.floor(secs / 86400) + 'd';
  }

  async function post(params) {
    params.csrf = CSRF;
    const r = await fetch('alerts_action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(params)
    });
    return r.json().catch(() => ({ ok: false }));
  }

  async function load() {
    try {
      const r = await fetch('alerts_data.php?status=' + encodeURIComponent(state), { headers: { 'Accept': 'application/json' } });
      if (!r.ok) return;
      data = await r.json();
      renderAlerts();
      renderRules();
      renderWindows();
    } catch (e) { /* transient; next poll retries */ }
  }

  function renderAlerts() {
    const body = $('alerts-body');
    body.textContent = '';
    const list = data.alerts || [];
    if (!list.length) {
      const tr = el('tr'); const td = el('td', 'muted', state === 'active' ? 'No active alerts. All clear.' : 'No alerts.');
      td.colSpan = 6; tr.appendChild(td); body.appendChild(tr);
      return;
    }
    for (const a of list) {
      const tr = el('tr');
      tr.appendChild(td(sevTag(a.severity)));
      const dev = el('td');
      const link = el('a', null, a.device); link.href = 'agent.php?id=' + a.agent_id;
      dev.appendChild(link); tr.appendChild(dev);
      tr.appendChild(el('td', null, a.message));
      tr.appendChild(el('td', 'muted', humanAge(a.age_secs) + ' ago'));
      tr.appendChild(td(statusTag(a)));
      const act = el('td');
      if (a.status === 'open') act.appendChild(btn('Ack', 'btn-sm btn-ghost', () => act1('ack', a.id)));
      if (a.status === 'open' || a.status === 'acked') act.appendChild(btn('Resolve', 'btn-sm btn-ghost', () => act1('resolve', a.id)));
      tr.appendChild(act);
      body.appendChild(tr);
    }
  }

  function td(node) { const c = el('td'); c.appendChild(node); return c; }
  function sevTag(sev) { return el('span', 'tag tag-sev-' + sev, sev); }
  function statusTag(a) {
    const s = el('span', 'tag tag-st-' + a.status, a.status);
    if (a.status === 'acked' && a.acked_by) s.title = 'by ' + a.acked_by;
    return s;
  }
  function btn(label, cls, onclick) { const b = el('button', cls, label); b.type = 'button'; b.addEventListener('click', onclick); return b; }

  async function act1(action, id) {
    const res = await post({ action, id });
    if (res && res.ok) load();
  }

  /* ── Rules list ─────────────────────────────────────────────────────────── */
  function renderRules() {
    const wrap = $('rules-list'); wrap.textContent = '';
    const rules = data.rules || [];
    if (!rules.length) { wrap.appendChild(el('p', 'muted small', 'No custom rules — built-in defaults apply to every device.')); return; }
    const tbl = el('table', 'grid mini');
    const thead = el('thead'); const htr = el('tr');
    ['Rule', 'Scope', 'Metrics', 'On', ''].forEach(h => htr.appendChild(el('th', null, h)));
    thead.appendChild(htr); tbl.appendChild(thead);
    const tb = el('tbody');
    for (const rule of rules) {
      const tr = el('tr');
      tr.appendChild(el('td', null, rule.name));
      tr.appendChild(el('td', 'muted', rule.scope_label));
      tr.appendChild(el('td', 'muted small', Object.keys(rule.thresholds || {}).join(', ')));
      tr.appendChild(el('td', null, rule.is_enabled ? 'yes' : 'no'));
      const act = el('td');
      act.appendChild(btn('Edit', 'btn-sm btn-ghost', () => openEditor(rule)));
      act.appendChild(btn('Delete', 'btn-sm btn-danger', () => { if (confirm('Delete this rule?')) post({ action: 'rule_delete', policy_id: rule.policy_id }).then(load); }));
      tr.appendChild(act);
      tb.appendChild(tr);
    }
    tbl.appendChild(tb); wrap.appendChild(tbl);
  }

  /* ── Rule editor ────────────────────────────────────────────────────────── */
  function fillTargets() {
    const type = $('r-scope-type').value;
    const wrap = $('r-target-wrap'), sel = $('r-scope-id');
    if (type === 'global') { wrap.hidden = true; return; }
    wrap.hidden = false; sel.textContent = '';
    const src = { client: data.scopes.clients, site: data.scopes.sites, group: data.scopes.groups, device: data.scopes.agents }[type] || [];
    for (const o of src) {
      const opt = el('option', null, o.name); opt.value = o.id; sel.appendChild(opt);
    }
  }

  function buildMetricRows(thresholds) {
    const tb = $('r-metrics'); tb.textContent = '';
    for (const m of (data.metrics || [])) {
      const cur = (thresholds && thresholds[m.key]) || {};
      const def = m.default || {};
      const tr = el('tr'); tr.dataset.key = m.key;
      tr.appendChild(el('td', null, m.label + (m.unit ? ' (' + m.unit + ')' : '')));
      // op
      const opTd = el('td'); const opSel = el('select', 'r-op');
      for (const k of Object.keys(OPS)) { const o = el('option', null, OPS[k]); o.value = k; opSel.appendChild(o); }
      opSel.value = cur.op || def.op || 'gt'; opTd.appendChild(opSel); tr.appendChild(opTd);
      // warning / critical / for
      tr.appendChild(numTd('r-warn', cur.warning, def.warning));
      tr.appendChild(numTd('r-crit', cur.critical, def.critical));
      tr.appendChild(numTd('r-for', cur.for_min, def.for_min, true));
      tb.appendChild(tr);
    }
  }
  function numTd(cls, val, placeholder, isInt) {
    const c = el('td'); const i = el('input', cls); i.type = 'number'; if (isInt) i.step = '1';
    if (val !== undefined && val !== null) i.value = val;
    if (placeholder !== undefined && placeholder !== null) i.placeholder = placeholder;
    c.appendChild(i); return c;
  }

  function openEditor(rule) {
    $('rule-editor').hidden = false;
    $('rule-msg').textContent = '';
    $('r-policy-id').value = rule ? rule.policy_id : '';
    $('r-name').value = rule ? rule.name : '';
    $('r-scope-type').value = rule ? rule.scope_type : 'global';
    $('r-enabled').checked = rule ? !!rule.is_enabled : true;
    editThresholds = (rule && rule.thresholds) ? JSON.parse(JSON.stringify(rule.thresholds)) : {};
    fillTargets();
    if (rule && rule.scope_id) $('r-scope-id').value = rule.scope_id;
    buildMetricRows(rule ? rule.thresholds : null);
    $('rule-editor').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function collectThresholds() {
    // Start from the edited rule's existing doc so per-instance keys (e.g. disk_pct:C:) and fields
    // the editor doesn't surface (clear_min, enabled) are PRESERVED, not silently dropped on save.
    const out = JSON.parse(JSON.stringify(editThresholds || {}));
    for (const tr of $('r-metrics').querySelectorAll('tr')) {
      const key = tr.dataset.key;
      const warn = tr.querySelector('.r-warn').value.trim();
      const crit = tr.querySelector('.r-crit').value.trim();
      if (warn === '' && crit === '') { delete out[key]; continue; }   // both cleared -> remove this catalog rule
      const entry = Object.assign({}, out[key] || {});                 // keep clear_min/enabled if already set
      entry.op = tr.querySelector('.r-op').value;
      entry.for_min = parseInt(tr.querySelector('.r-for').value || '0', 10) || 0;
      if (warn !== '') entry.warning = parseFloat(warn); else delete entry.warning;
      if (crit !== '') entry.critical = parseFloat(crit); else delete entry.critical;
      out[key] = entry;
    }
    return out;
  }

  async function saveRule(e) {
    e.preventDefault();
    const thresholds = collectThresholds();
    if (!Object.keys(thresholds).length) { $('rule-msg').textContent = 'Set a warning or critical value on at least one metric.'; return; }
    const params = {
      action: 'rule_save',
      policy_id: $('r-policy-id').value || '',
      name: $('r-name').value,
      scope_type: $('r-scope-type').value,
      is_enabled: $('r-enabled').checked ? 1 : 0,
      thresholds: JSON.stringify(thresholds)
    };
    if (params.scope_type !== 'global') params.scope_id = $('r-scope-id').value || '';
    const res = await post(params);
    if (res && res.ok) { $('rule-editor').hidden = true; load(); }
    else { $('rule-msg').textContent = (res && res.error) ? res.error : 'Save failed.'; }
  }

  /* ── Maintenance windows ────────────────────────────────────────────────── */
  function renderWindows() {
    const wrap = $('mw-list'); wrap.textContent = '';
    const wins = data.windows || [];
    if (!wins.length) { wrap.appendChild(el('p', 'muted small', 'No maintenance windows.')); return; }
    const tbl = el('table', 'grid mini');
    const thead = el('thead'), htr = el('tr');
    ['Name', 'Scope', 'When (UTC)', 'Repeat', 'Status', ''].forEach(h => htr.appendChild(el('th', null, h)));
    thead.appendChild(htr); tbl.appendChild(thead);
    const tb = el('tbody');
    const repeatLbl = { none: 'Once', daily: 'Daily', weekly: 'Weekly' };
    for (const w of wins) {
      const tr = el('tr');
      tr.appendChild(el('td', null, w.name));
      tr.appendChild(el('td', 'muted', w.scope_label));
      tr.appendChild(el('td', 'muted small', (w.starts_at || '').slice(0, 16) + ' → ' + (w.ends_at || '').slice(0, 16)));
      tr.appendChild(el('td', 'muted', repeatLbl[w.recurrence] || w.recurrence));
      const stTd = el('td');
      if (!w.is_enabled)     stTd.appendChild(el('span', 'tag tag-st-resolved', 'off'));
      else if (w.active_now) stTd.appendChild(el('span', 'tag tag-sev-info', 'active'));
      else                   stTd.appendChild(el('span', 'tag tag-st-acked', 'scheduled'));
      tr.appendChild(stTd);
      const act = el('td');
      act.appendChild(btn('Edit', 'btn-sm btn-ghost', () => openWindowEditor(w)));
      act.appendChild(btn('Delete', 'btn-sm btn-danger', () => { if (confirm('Delete this window?')) post({ action: 'mw_delete', id: w.id }).then(load); }));
      tr.appendChild(act);
      tb.appendChild(tr);
    }
    tbl.appendChild(tb); wrap.appendChild(tbl);
  }

  function fillWindowTargets() {
    const type = $('mw-scope-type').value;
    const wrap = $('mw-target-wrap'), sel = $('mw-scope-id');
    if (type === 'global') { wrap.hidden = true; return; }
    wrap.hidden = false; sel.textContent = '';
    const src = { client: data.scopes.clients, site: data.scopes.sites, group: data.scopes.groups, device: data.scopes.agents }[type] || [];
    for (const o of src) { const opt = el('option', null, o.name); opt.value = o.id; sel.appendChild(opt); }
  }

  function openWindowEditor(w) {
    $('mw-editor').hidden = false;
    $('mw-msg').textContent = '';
    $('mw-id').value = w ? w.id : '';
    $('mw-name').value = w ? w.name : '';
    $('mw-scope-type').value = w ? w.scope_type : 'global';
    $('mw-enabled').checked = w ? !!w.is_enabled : true;
    $('mw-recurrence').value = w ? w.recurrence : 'none';
    $('mw-start').value = w ? (w.starts_at || '').slice(0, 16).replace(' ', 'T') : '';
    $('mw-end').value = w ? (w.ends_at || '').slice(0, 16).replace(' ', 'T') : '';
    fillWindowTargets();
    if (w && w.scope_id) $('mw-scope-id').value = w.scope_id;
    $('mw-editor').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  async function saveWindow(e) {
    e.preventDefault();
    const start = $('mw-start').value, end = $('mw-end').value;
    if (!start || !end) { $('mw-msg').textContent = 'Set a start and end time (UTC).'; return; }
    const params = {
      action: 'mw_save',
      id: $('mw-id').value || '',
      name: $('mw-name').value,
      scope_type: $('mw-scope-type').value,
      is_enabled: $('mw-enabled').checked ? 1 : 0,
      starts_at: start,
      ends_at: end,
      recurrence: $('mw-recurrence').value
    };
    if (params.scope_type !== 'global') params.scope_id = $('mw-scope-id').value || '';
    const res = await post(params);
    if (res && res.ok) { $('mw-editor').hidden = true; load(); }
    else { $('mw-msg').textContent = (res && res.error) ? res.error : 'Save failed.'; }
  }

  // Wire up
  $('filter-tabs').addEventListener('click', (e) => {
    const b = e.target.closest('.seg-btn'); if (!b) return;
    for (const x of $('filter-tabs').children) x.classList.remove('is-on');
    b.classList.add('is-on'); state = b.dataset.status; load();
  });
  $('rule-add').addEventListener('click', () => openEditor(null));
  $('rule-cancel').addEventListener('click', () => { $('rule-editor').hidden = true; });
  $('r-scope-type').addEventListener('change', fillTargets);
  $('rule-editor').addEventListener('submit', saveRule);
  $('mw-add').addEventListener('click', () => openWindowEditor(null));
  $('mw-cancel').addEventListener('click', () => { $('mw-editor').hidden = true; });
  $('mw-scope-type').addEventListener('change', fillWindowTargets);
  $('mw-editor').addEventListener('submit', saveWindow);

  load();
  setInterval(load, 20000);
})();
</script>
<?php layout_footer();
