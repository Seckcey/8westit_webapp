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

  // Performance history chart on the agent detail page (dependency-free SVG line chart).
  // Reads metrics_history.php: a dynamic % family (CPU/Memory + every disk volume) is charted,
  // and the non-% gauges (network / SMART health / temperature) show as a latest-value readout.
  var chartCard = document.querySelector('[data-metric-history]');
  if (chartCard) {
    var histId    = chartCard.getAttribute('data-metric-history');
    var canvas    = chartCard.querySelector('[data-chart-canvas]');
    var legendEl  = chartCard.querySelector('[data-chart-legend]');
    var extrasEl  = chartCard.querySelector('[data-chart-extras]');
    var emptyEl   = chartCard.querySelector('[data-chart-empty]');
    var rangeBtns = chartCard.querySelectorAll('.mp-range button');
    var curRange  = '24h';
    var reqSeq    = 0; // drop out-of-order responses when the range is switched quickly
    var DISK_PALETTE = ['var(--green)', 'var(--blue-light)', 'var(--red)', 'var(--slate)', 'var(--amber)'];

    var pad2 = function (n) { return (n < 10 ? '0' : '') + n; };
    function timeLabel(ts, range) {
      var d = new Date(ts * 1000);
      if (range === '7d') return (d.getMonth() + 1) + '/' + d.getDate();
      return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }
    // Stable color per series: CPU/Memory fixed (match the live tiles), disks cycle a palette.
    function colorize(pct) {
      var di = 0;
      return (pct || []).map(function (s) {
        var c = s.key === 'cpu' ? 'var(--blue)'
              : s.key === 'mem' ? 'var(--amber)'
              : DISK_PALETTE[(di++) % DISK_PALETTE.length];
        return { s: s, color: c };
      });
    }
    function hasPct(series) { return series.some(function (it) { return it.s.points && it.s.points.length; }); }

    function buildSvg(d, series) {
      var W = 720, H = 220, padL = 30, padR = 10, padT = 10, padB = 22;
      var plotW = W - padL - padR, plotH = H - padT - padB;
      var from = d.from, now = Math.floor(Date.now() / 1000);
      var span = Math.max(1, now - from);
      function x(ts) { return padL + ((ts - from) / span) * plotW; }
      function y(v) { return padT + (1 - Math.max(0, Math.min(100, v)) / 100) * plotH; }
      var p = ['<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Performance history">'];
      [0, 25, 50, 75, 100].forEach(function (g) {
        var yy = y(g).toFixed(1);
        p.push('<line class="mp-grid" x1="' + padL + '" y1="' + yy + '" x2="' + (W - padR) + '" y2="' + yy + '"/>');
        p.push('<text class="mp-axis" x="' + (padL - 4) + '" y="' + (y(g) + 3).toFixed(1) + '" text-anchor="end">' + g + '</text>');
      });
      var ticks = 4;
      for (var i = 0; i <= ticks; i++) {
        var ts = from + (span * i / ticks), xx = x(ts).toFixed(1);
        var anchor = i === 0 ? 'start' : (i === ticks ? 'end' : 'middle');
        p.push('<text class="mp-axis" x="' + xx + '" y="' + (H - 6) + '" text-anchor="' + anchor + '">' + timeLabel(ts, d.range) + '</text>');
      }
      // Colors come from a fixed client-side palette (never server strings) so this innerHTML is safe.
      series.forEach(function (it) {
        var pts = it.s.points || [];
        if (!pts.length) return;
        if (pts.length === 1) {
          // A lone sample renders invisibly as a polyline — draw a dot so it is visible.
          p.push('<circle cx="' + x(pts[0][0]).toFixed(1) + '" cy="' + y(pts[0][1]).toFixed(1) + '" r="2.6" fill="' + it.color + '"/>');
          return;
        }
        var line = pts.map(function (pt) { return x(pt[0]).toFixed(1) + ',' + y(pt[1]).toFixed(1); }).join(' ');
        p.push('<polyline class="mp-line" style="stroke:' + it.color + '" points="' + line + '"/>');
      });
      p.push('</svg>');
      return p.join('');
    }

    // Legend + extras are built as DOM nodes (textContent) so server-derived labels/instances can
    // never inject markup, even though the portal already sanitizes metric keys/instances on ingest.
    function renderLegend(series) {
      legendEl.textContent = '';
      series.forEach(function (it) {
        var pts = it.s.points || [];
        var last = pts.length ? pts[pts.length - 1][1] : null;
        var span = document.createElement('span'); span.className = 'mp-leg';
        var dot = document.createElement('span'); dot.className = 'dot'; dot.style.background = it.color;
        var b = document.createElement('b'); b.textContent = (last === null ? '—' : fmtPct(last) + '%');
        span.appendChild(dot);
        span.appendChild(document.createTextNode(' ' + it.s.label + ' '));
        span.appendChild(b);
        legendEl.appendChild(span);
      });
    }
    function fmtRate(kbps) {
      if (kbps >= 1000) return (kbps / 1000).toFixed(1) + ' Mbps';
      return Math.round(kbps) + ' kbps';
    }
    function fmtGb(g) { return (g >= 100 ? Math.round(g) : Math.round(g * 10) / 10) + ' GB'; }
    function chip(label, value) {
      var el = document.createElement('span'); el.className = 'mp-extra';
      var k = document.createElement('span'); k.className = 'mp-extra-k'; k.textContent = label;
      var b = document.createElement('b'); b.textContent = value;
      el.appendChild(k); el.appendChild(b); return el;
    }
    function renderExtras(latest) {
      if (!extrasEl) return;
      extrasEl.textContent = '';
      if (!latest || !latest.length) { extrasEl.hidden = true; return; }
      var by = {};
      latest.forEach(function (l) { (by[l.key] = by[l.key] || []).push(l); });
      var chips = [];
      if (by.net_down_kbps || by.net_up_kbps) {
        var down = by.net_down_kbps ? by.net_down_kbps[0].value : 0;
        var up = by.net_up_kbps ? by.net_up_kbps[0].value : 0;
        chips.push(chip('Network', '↓ ' + fmtRate(down) + '   ↑ ' + fmtRate(up)));
      }
      (by.disk_free_gb || []).forEach(function (d) {
        chips.push(chip('Disk ' + d.instance + ' free', fmtGb(d.value)));
      });
      (by.disk_health || []).forEach(function (h) {
        var multi = (by.disk_health || []).length > 1;
        chips.push(chip('Disk health' + (multi ? ' ' + h.instance : ''), h.value >= 1 ? 'OK' : 'At risk'));
      });
      (by.temp_c || []).forEach(function (t) {
        var multi = (by.temp_c || []).length > 1;
        chips.push(chip('Temp' + (multi ? ' ' + t.instance : ''), Math.round(t.value) + '°C'));
      });
      if (!chips.length) { extrasEl.hidden = true; return; }
      chips.forEach(function (c) { extrasEl.appendChild(c); });
      extrasEl.hidden = false;
    }

    function loadChart() {
      var myReq = ++reqSeq;
      fetch('metrics_history.php?id=' + histId + '&range=' + curRange, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (myReq !== reqSeq || !d || !d.ok) return; // ignore stale/out-of-order responses
          var series = colorize(d.pct);
          var anyPct = hasPct(series);
          var anyLatest = d.latest && d.latest.length;
          if (!anyPct && !anyLatest) {
            canvas.innerHTML = ''; legendEl.textContent = '';
            if (extrasEl) { extrasEl.hidden = true; extrasEl.textContent = ''; }
            if (emptyEl) emptyEl.hidden = false;
            return;
          }
          if (emptyEl) emptyEl.hidden = true;
          renderLegend(series);
          canvas.innerHTML = anyPct ? buildSvg(d, series) : '';
          renderExtras(d.latest);
        }).catch(function () {});
    }
    for (var bi = 0; bi < rangeBtns.length; bi++) {
      rangeBtns[bi].addEventListener('click', function (ev) {
        var b = ev.currentTarget;
        curRange = b.getAttribute('data-range');
        for (var j = 0; j < rangeBtns.length; j++) {
          var on = rangeBtns[j] === b;
          rangeBtns[j].classList.toggle('active', on);
          rangeBtns[j].setAttribute('aria-pressed', on ? 'true' : 'false');
        }
        loadChart();
      });
    }
    loadChart();
    setInterval(loadChart, 60000);
  }
})();
