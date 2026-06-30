'use strict';

// Phase 2: the wider metric set rides a generic `series` array that the backend must carry
// opaquely from a `metrics` frame through to the throttled metrics_snapshot payload — without
// changing the snapshot shape for agents that don't send one (pre-1.1.8).

const { test } = require('node:test');
const assert = require('node:assert');
const { LiveStore } = require('../src/liveStore');

const noopLog = { info() {}, warn() {}, debug() {}, error() {} };

function newStore() {
  const t = 1_700_000_000_000; // fixed ms clock (Date-free, deterministic)
  return new LiveStore({ METRICS_SNAPSHOT_THROTTLE_SECS: 60, SQLITE_PATH: '' }, noopLog, { now: () => t });
}

test('series carries through recordMetrics -> collectDueSnapshots', () => {
  const s = newStore();
  s.setOnline(42);
  s.recordMetrics(42, {
    cpu: 10, mem: 20, disk_c: 30,
    series: [
      { k: 'disk_pct', i: 'C:', v: 30.5 },
      { k: 'net_up_kbps', i: '', v: 123.4 },
    ],
  });
  const items = s.collectDueSnapshots();
  assert.equal(items.length, 1);
  assert.equal(items[0].agent_id, 42);
  assert.ok(Array.isArray(items[0].series), 'series should be forwarded');
  assert.equal(items[0].series.length, 2);
  assert.equal(items[0].series[0].k, 'disk_pct');
  assert.equal(items[0].series[0].i, 'C:');
});

test('snapshot omits series for agents that never send one (back-compat)', () => {
  const s = newStore();
  s.setOnline(7);
  s.recordMetrics(7, { cpu: 5, mem: 6, disk_c: 7 });
  const items = s.collectDueSnapshots();
  assert.equal(items.length, 1);
  assert.equal('series' in items[0], false, 'no series key when none was sent');
});

test('a non-array series is ignored (defensive)', () => {
  const s = newStore();
  s.setOnline(9);
  s.recordMetrics(9, { cpu: 1, series: 'not-an-array' });
  const items = s.collectDueSnapshots();
  assert.equal('series' in items[0], false);
});
