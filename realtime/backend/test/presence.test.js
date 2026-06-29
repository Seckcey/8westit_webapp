'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const { bootBackend, MockAgent, signedRequest, plainGet, TEST_SECRET } = require('./helpers');

const GOOD = {
  ok: true, agent_id: 42, client_id: 7, site_id: 3, site: 'HQ',
  hostname: 'DESK-7', display_name: 'DESK-7', is_archived: false, policy_etag: 'p-1',
};

test('connect → presence online → /internal/presence reports live metrics', async () => {
  const ctx = await bootBackend({ portalHandlers: { verify_agent: () => ({ ...GOOD }) } });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { token: 'tok' });
    await agent.waitFor('welcome');

    // Push a metrics frame.
    agent.send('metrics', { cpu: 13.5, mem: 42.0, disk_c: 68.3, uptime_secs: 864000, logged_user: 'DESK-7\\frank', local_ip: '10.0.0.7' }, { id: null });
    // Give the server a tick to record it.
    await new Promise((r) => setTimeout(r, 100));

    const res = await signedRequest(ctx.httpBase, 'GET', '/internal/presence?agent_ids=42,43', undefined);
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.json.ok, true);
    assert.strictEqual(res.json.agents['42'].online, true);
    assert.strictEqual(res.json.agents['42'].cpu, 13.5);
    assert.strictEqual(res.json.agents['42'].disk_c, 68.3);
    assert.strictEqual(res.json.agents['43'].online, false);
    agent.close();
  } finally {
    await ctx.close();
  }
});

test('/internal/presence requires the service signature', async () => {
  const ctx = await bootBackend({ portalHandlers: { verify_agent: () => ({ ...GOOD }) } });
  try {
    // Unsigned plain GET → 401.
    const res = await plainGet(ctx.httpBase, '/internal/presence?agent_ids=42');
    assert.strictEqual(res.status, 401);
    assert.strictEqual(res.json.ok, false);

    // Wrong secret → 401.
    const bad = await signedRequest(ctx.httpBase, 'GET', '/internal/presence?agent_ids=42', undefined, 'f'.repeat(64));
    assert.strictEqual(bad.status, 401);
  } finally {
    await ctx.close();
  }
});

test('/healthz is public and reports connection count', async () => {
  const ctx = await bootBackend({ portalHandlers: { verify_agent: () => ({ ...GOOD }) } });
  try {
    let res = await plainGet(ctx.httpBase, '/healthz');
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.json.ok, true);
    assert.strictEqual(res.json.connections, 0);

    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { token: 'tok' });
    await agent.waitFor('welcome');

    res = await plainGet(ctx.httpBase, '/healthz');
    assert.strictEqual(res.json.connections, 1);
    assert.strictEqual(res.json.portal_reachable, true);
    agent.close();
  } finally {
    await ctx.close();
  }
});

test('disconnect flags the agent offline in presence', async () => {
  const ctx = await bootBackend({ portalHandlers: { verify_agent: () => ({ ...GOOD }) } });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { token: 'tok' });
    await agent.waitFor('welcome');
    agent.send('bye', { reason: 'service_stop' }, { id: null });
    await agent.waitClose();
    await new Promise((r) => setTimeout(r, 100));

    const res = await signedRequest(ctx.httpBase, 'GET', '/internal/presence?agent_ids=42', undefined);
    assert.strictEqual(res.json.agents['42'].online, false);
  } finally {
    await ctx.close();
  }
});
