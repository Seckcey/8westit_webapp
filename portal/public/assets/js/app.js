/* 8 West IT RMM — dashboard interactivity (vanilla JS, no build step). */
(function () {
  'use strict';

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

  // Live status refresh on the dashboard grid.
  var grid = document.getElementById('agents-grid');
  if (grid) {
    setInterval(function () {
      fetch('status.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) return;
          d.agents.forEach(function (a) {
            var row = grid.querySelector('tr a[href="agent.php?id=' + a.id + '"]');
            if (!row) return;
            var tr = row.closest('tr');
            var dot = tr.querySelector('.dot');
            if (dot) dot.className = 'dot ' + (a.online ? 'dot-on' : 'dot-off');
            var cells = tr.querySelectorAll('td');
            if (cells[6]) cells[6].textContent = a.last_seen;
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
})();
