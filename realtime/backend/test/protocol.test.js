'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const protocol = require('../src/protocol');

test('makeEnvelope produces the compact 5-field envelope', () => {
  const env = protocol.makeEnvelope('metrics', { cpu: 1 }, { id: null });
  assert.strictEqual(env.t, 'metrics');
  assert.strictEqual(env.id, null);
  assert.strictEqual(typeof env.ts, 'number');
  assert.strictEqual(env.ref, null);
  assert.deepStrictEqual(env.d, { cpu: 1 });
});

test('encode/decode round-trips an envelope', () => {
  const env = protocol.makeEnvelope('hello', { token: 'x' }, { id: 'abc' });
  const wire = protocol.encode(env);
  const res = protocol.decode(wire);
  assert.strictEqual(res.ok, true);
  assert.strictEqual(res.env.t, 'hello');
  assert.strictEqual(res.env.id, 'abc');
  assert.deepStrictEqual(res.env.d, { token: 'x' });
});

test('decode rejects invalid JSON', () => {
  const res = protocol.decode('{not json');
  assert.strictEqual(res.ok, false);
  assert.strictEqual(res.code, 'bad_message');
});

test('decode rejects a non-object top level', () => {
  const res = protocol.decode('[1,2,3]');
  assert.strictEqual(res.ok, false);
});

test('decode rejects a missing/empty type', () => {
  assert.strictEqual(protocol.decode(JSON.stringify({ d: {} })).ok, false);
  assert.strictEqual(protocol.decode(JSON.stringify({ t: '', d: {} })).ok, false);
});

test('decode tolerates a missing d (defaults to {})', () => {
  const res = protocol.decode(JSON.stringify({ t: 'pong' }));
  assert.strictEqual(res.ok, true);
  assert.deepStrictEqual(res.env.d, {});
});

test('unknown fields are ignored, not rejected (forward-compat)', () => {
  const wire = JSON.stringify({ t: 'metrics', id: null, ts: 1, d: { cpu: 5 }, v: 99, extra: 'x' });
  const res = protocol.decode(wire);
  assert.strictEqual(res.ok, true);
  assert.strictEqual(res.env.t, 'metrics');
  // The normalized envelope keeps only known fields.
  assert.deepStrictEqual(Object.keys(res.env).sort(), ['d', 'id', 'ref', 't', 'ts']);
});

test('unknown type still parses cleanly (dispatcher ignores it later)', () => {
  const res = protocol.decode(JSON.stringify({ t: 'future_type', d: {} }));
  assert.strictEqual(res.ok, true);
  assert.strictEqual(protocol.isAgentType('future_type'), false);
  assert.strictEqual(protocol.isServerType('future_type'), false);
});

test('agent/server type guards classify the real catalog', () => {
  assert.ok(protocol.isAgentType('hello'));
  assert.ok(protocol.isAgentType('cmd_result'));
  assert.ok(protocol.isServerType('welcome'));
  assert.ok(protocol.isServerType('command'));
  assert.ok(!protocol.isAgentType('welcome'));
});

test('uuid() returns a v4-shaped string', () => {
  const id = protocol.uuid();
  assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});
