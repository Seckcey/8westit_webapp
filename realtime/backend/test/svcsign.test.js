'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const crypto = require('crypto');
const { computeSign, verify, NonceStore, safeEqual } = require('../src/serviceSign');

const SECRET = 'b'.repeat(64);

function makeHeaders({ secret = SECRET, method = 'POST', path = '/api/svc/job_result.php', body = '{}', ts, nonce } = {}) {
  ts = ts || String(Math.floor(Date.now() / 1000));
  nonce = nonce || crypto.randomUUID();
  const sign = computeSign(secret, method, path, ts, nonce, body);
  return {
    headers: {
      'x-milepost-service': secret,
      'x-milepost-timestamp': ts,
      'x-milepost-nonce': nonce,
      'x-milepost-sign': sign,
    },
    method,
    path,
    body,
  };
}

test('sign/verify round-trips a valid request', () => {
  const h = makeHeaders();
  const store = new NonceStore(300);
  const res = verify({ secret: SECRET, method: h.method, path: h.path, body: h.body, headers: h.headers, windowSecs: 300, nonceStore: store });
  assert.strictEqual(res.ok, true);
});

test('a tampered body breaks the signature', () => {
  const h = makeHeaders({ body: '{"a":1}' });
  const store = new NonceStore(300);
  const res = verify({ secret: SECRET, method: h.method, path: h.path, body: '{"a":2}', headers: h.headers, windowSecs: 300, nonceStore: store });
  assert.strictEqual(res.ok, false);
  assert.strictEqual(res.code, 'bad_signature');
});

test('a wrong secret is rejected (constant-time compare)', () => {
  const h = makeHeaders();
  h.headers['x-milepost-service'] = 'c'.repeat(64);
  const store = new NonceStore(300);
  const res = verify({ secret: SECRET, method: h.method, path: h.path, body: h.body, headers: h.headers, windowSecs: 300, nonceStore: store });
  assert.strictEqual(res.ok, false);
  assert.strictEqual(res.code, 'unauthorized');
});

test('a stale timestamp is rejected', () => {
  const old = String(Math.floor(Date.now() / 1000) - 1000);
  const h = makeHeaders({ ts: old });
  const store = new NonceStore(300);
  const res = verify({ secret: SECRET, method: h.method, path: h.path, body: h.body, headers: h.headers, windowSecs: 300, nonceStore: store });
  assert.strictEqual(res.ok, false);
  assert.strictEqual(res.code, 'stale');
});

test('a malformed nonce is rejected', () => {
  const h = makeHeaders({ nonce: 'not-a-uuid' });
  // Re-sign with the bad nonce so only the nonce shape is wrong.
  h.headers['x-milepost-sign'] = computeSign(SECRET, h.method, h.path, h.headers['x-milepost-timestamp'], 'not-a-uuid', h.body);
  h.headers['x-milepost-nonce'] = 'not-a-uuid';
  const store = new NonceStore(300);
  const res = verify({ secret: SECRET, method: h.method, path: h.path, body: h.body, headers: h.headers, windowSecs: 300, nonceStore: store });
  assert.strictEqual(res.ok, false);
  assert.strictEqual(res.code, 'bad_nonce');
});

test('a replayed nonce is rejected the second time', () => {
  const h = makeHeaders();
  const store = new NonceStore(300);
  const first = verify({ secret: SECRET, method: h.method, path: h.path, body: h.body, headers: h.headers, windowSecs: 300, nonceStore: store });
  assert.strictEqual(first.ok, true);
  const second = verify({ secret: SECRET, method: h.method, path: h.path, body: h.body, headers: h.headers, windowSecs: 300, nonceStore: store });
  assert.strictEqual(second.ok, false);
  assert.strictEqual(second.code, 'replay');
});

test('safeEqual handles unequal lengths without throwing', () => {
  assert.strictEqual(safeEqual('abc', 'abcd'), false);
  assert.strictEqual(safeEqual('abc', 'abc'), true);
});
