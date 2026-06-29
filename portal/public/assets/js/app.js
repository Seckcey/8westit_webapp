/* 8 West IT RMM — dashboard interactivity (vanilla JS, no build step). */
(function () {
  'use strict';

  // Appearance: light / dark / system (persisted in localStorage as 'mp-theme').
  var root = document.documentElement;
  function curTheme() { try { return localStorage.getItem('mp-theme') || 'system'; } catch (e) { return 'system'; } }
  function applyTheme(t) {
    root.setAttribute('data-theme', t);
    var btns = document.querySelectorAll('[data-theme-set]');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('active', btns[i].getAttribute('data-theme-set') === t);
    }
  }
  applyTheme(curTheme());
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-theme-set]');
    if (!b) return;
    var t = b.getAttribute('data-theme-set');
    try { localStorage.setItem('mp-theme', t); } catch (e) {}
    applyTheme(t);
  });
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      if (curTheme() === 'system') applyTheme('system'); // re-evaluate the media query
    });
  }

  // Copy-to-clipboard buttons.
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    var el = document.getElementById(btn.getAttribute('data-copy'));
    if (!el) return;
    navigator.clipboard.writeText(el.textContent.trim()).then(function () {
      var t = btn.textContent; btn.textContent = 'Copied';
      setTimeout(function () { btn.textContent = t; }, 1200);
    });
  });

  // Show/hide the command textarea when "Restart" is chosen.
  var jobType = document.getElementById('job_type');
  var payloadWrap = document.getElementById('payload_wrap');
  if (jobType && payloadWrap) {
    var sync = function () { payloadWrap.style.display = jobType.value === 'restart' ? 'none' : ''; };
    jobType.addEventListener('change', sync); sync();
  }

  // Live status refresh on the dashboard (works across client/site folders).
  if (document.querySelector('.agents-grid')) {
    setInterval(function () {
      fetch('status.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) return;
          d.agents.forEach(function (a) {
            var link = document.querySelector('.agents-grid a[href="agent.php?id=' + a.id + '"]');
            if (!link) return;
            var tr = link.closest('tr');
            var dot = tr.querySelector('.dot');
            if (dot) dot.className = 'dot ' + (a.online ? 'dot-on' : 'dot-off');
            var ls = tr.querySelector('.cell-lastseen');
            if (ls) ls.textContent = a.last_seen;
          });
        }).catch(function () {});
    }, 20000);
  }

  // Live job status on the agent detail page.
  var jobsTable = document.querySelector('.jobs-table');
  if (jobsTable) {
    var agentId = jobsTable.getAttribute('data-agent');
    setInterval(function () {
      fetch('job_status.php?agent=' + agentId, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) return;
          var anyRunning = false;
          d.jobs.forEach(function (j) {
            var tr = jobsTable.querySelector('tr[data-job="' + j.id + '"]');
            if (!tr) { anyRunning = true; return; } // new job we don't have a row for
            if (j.status === 'queued' || j.status === 'running') anyRunning = true;
            var tag = tr.querySelector('.tag');
            if (tag) { tag.className = 'tag tag-' + j.status; tag.textContent = j.status; }
          });
          // If a job just finished, reload once to render its output cleanly.
          if (!anyRunning && jobsTable.dataset.wasRunning === '1') location.reload();
          jobsTable.dataset.wasRunning = anyRunning ? '1' : '0';
        }).catch(function () {});
    }, 6000);
  }

  // Live metrics poller on the agent detail page (updates the Live card tiles in place).
  function fmtPct(v) { return String(Math.round(v * 10) / 10); }
  function humanUptime(secs) {
    var s = Math.max(0, Math.floor(secs));
    var d = Math.floor(s / 86400);
    var h = Math.floor((s % 86400) / 3600);
    var m = Math.floor((s % 3600) / 60);
    if (d > 0) return d + 'd ' + h + 'h';
    if (h > 0) return h + 'h ' + m + 'm';
    if (m > 0) return m + 'm';
    return '<1m';
  }
  function humanAge(s) { // mirrors $fmtAge() in agent.php
    s = Math.max(0, Math.floor(s));
    if (s < 60) return s + 's ago';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    return Math.floor(s / 86400) + 'd ago';
  }
  function renderLive(box, d) {
    // The tile grid is always present; just toggle the "no metrics yet" hint so a fresh
    // agent's card becomes live in place (no reload) once data starts flowing.
    var hint = box.querySelector('[data-live-empty]');
    if (hint) hint.hidden = (d.source !== 'none');
    ['cpu', 'mem', 'disk_c'].forEach(function (key) {
      var v = d[key];
      var has = (v !== null && v !== undefined);
      var span = box.querySelector('[data-metric="' + key + '"]');
      var unit = box.querySelector('[data-unit="' + key + '"]');
      var bar = box.querySelector('[data-bar="' + key + '"]');
      if (span) span.textContent = has ? fmtPct(v) : '—';
      if (unit) unit.hidden = !has; // keep the "%" in sync with the value (no "— %" drift)
      if (bar) bar.style.width = (has ? Math.max(0, Math.min(100, v)) : 0) + '%';
    });
    var u = box.querySelector('[data-metric="uptime"]');
    if (u) u.textContent = (d.uptime_secs === null || d.uptime_secs === undefined) ? '—' : humanUptime(d.uptime_secs);
    var us = box.querySelector('[data-metric="user"]');
    if (us) us.textContent = d.logged_user ? d.logged_user : '—';
    var dot = box.querySelector('[data-live-dot]');
    if (dot) dot.className = 'dot ' + (d.online ? 'dot-on' : 'dot-off');
    var f = box.querySelector('[data-live-fresh]');
    if (f) {
      var hb = parseInt(box.getAttribute('data-heartbeat'), 10) || 60;
      var stale = false;
      if (d.source === 'realtime') f.textContent = 'live';
      else if (d.source === 'snapshot' && d.sampled_age_secs != null) {
        f.textContent = 'as of ' + humanAge(d.sampled_age_secs);
        stale = d.sampled_age_secs > Math.max(60, hb) * 2.5;
      } else f.textContent = '—';
      f.classList.toggle('mp-stale', stale);
    }
  }
  var liveBox = document.querySelector('[data-agent-live]');
  if (liveBox) {
    var liveId = liveBox.getAttribute('data-agent-live');
    var pull = function () {
      fetch('agent_live.php?id=' + liveId, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d.ok) return; renderLive(liveBox, d); })
        .catch(function () {});
    };
    pull();
    setInterval(pull, 7000);
  }
})();
