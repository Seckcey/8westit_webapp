'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const { bootBackend, MockAgent } = require('./helpers');

const GOOD_IDENTITY = {
  ok: true,
  agent_id: 42,
  client_id: 7,
  site_id: 3,
  site: 'HQ',
  hostname: 'DESK-7',
  display_name: 'DESK-7',
  is_archived: false,
  policy_etag: 'p-9f3a2c',
};

test('hello with a valid token is welcomed', async () => {
  const ctx = await bootBackend({
    portalHandlers: { verify_agent: () => ({ ...GOOD_IDENTITY }) },
  });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { agent_uid: 'u-1', token: 'rawtoken', agent_version: '1.1.0', caps: ['metrics', 'exec'] });
    const welcome = await agent.waitFor('welcome');
    assert.strictEqual(welcome.d.agent_id, 42);
    assert.strictEqual(welcome.d.metrics_interval, 60);
    assert.strictEqual(welcome.d.ping_interval, 1); // test cfg
    assert.ok(welcome.d.session_id.startsWith('s-'));
    assert.strictEqual(ctx.portal.callsTo('verify_agent').length, 1);
    assert.strictEqual(ctx.portal.callsTo('verify_agent')[0].payload.token, 'rawtoken');
    agent.close();
  } finally {
    await ctx.close();
  }
});

test('hello with an unknown token is rejected and closed 4401', async () => {
  const ctx = await bootBackend({
    portalHandlers: { verify_agent: () => ({ ok: false, error: 'unknown token' }) },
  });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { token: 'badtoken' });
    const err = await agent.waitFor('error');
    assert.strictEqual(err.d.code, 'not_authenticated');
    const closed = await agent.waitClose();
    assert.strictEqual(closed.code, 4401);
  } finally {
    await ctx.close();
  }
});

test('hello when the portal is unreachable closes 1011 (transient)', async () => {
  const ctx = await bootBackend({
    portalHandlers: {
      verify_agent: () => {
        const e = new Error('boom');
        e.code = 'NETWORK_ERROR';
        throw e;
      },
    },
  });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { token: 'rawtoken' });
    const closed = await agent.waitClose();
    assert.strictEqual(closed.code, 1011);
  } finally {
    await ctx.close();
  }
});

test('a non-hello frame before welcome is rejected with not_authenticated', async () => {
  const ctx = await bootBackend({
    portalHandlers: { verify_agent: () => ({ ...GOOD_IDENTITY }) },
  });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('metrics', { cpu: 5 }, { id: null });
    const err = await agent.waitFor('error');
    assert.strictEqual(err.d.code, 'not_authenticated');
    agent.close();
  } finally {
    await ctx.close();
  }
});

test('missing hello within the deadline closes 4408', async () => {
  const ctx = await bootBackend({
    cfg: { HELLO_TIMEOUT_SECS: 1 },
    portalHandlers: { verify_agent: () => ({ ...GOOD_IDENTITY }) },
  });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    const closed = await agent.waitClose(3000);
    assert.strictEqual(closed.code, 4408);
  } finally {
    await ctx.close();
  }
});

test('verify result is cached (second hello does not re-hit the portal)', async () => {
  const ctx = await bootBackend({
    portalHandlers: { verify_agent: () => ({ ...GOOD_IDENTITY }) },
  });
  try {
    const a1 = new MockAgent(ctx.wsUrl);
    await a1.open();
    a1.send('hello', { token: 'sametoken' });
    await a1.waitFor('welcome');

    const a2 = new MockAgent(ctx.wsUrl);
    await a2.open();
    a2.send('hello', { token: 'sametoken' });
    await a2.waitFor('welcome'); // second agent (a2 evicts a1 via 4409)

    // Only one portal verify call despite two hellos with the same token.
    assert.strictEqual(ctx.portal.callsTo('verify_agent').length, 1);
    a2.close();
  } finally {
    await ctx.close();
  }
});

test('a duplicate connection for the same agent closes the older socket 4409', async () => {
  const ctx = await bootBackend({
    portalHandlers: { verify_agent: () => ({ ...GOOD_IDENTITY }) },
  });
  try {
    const a1 = new MockAgent(ctx.wsUrl);
    await a1.open();
    a1.send('hello', { token: 'tok' });
    await a1.waitFor('welcome');

    const a2 = new MockAgent(ctx.wsUrl);
    await a2.open();
    a2.send('hello', { token: 'tok' });
    await a2.waitFor('welcome');

    const closed = await a1.waitClose(2000);
    assert.strictEqual(closed.code, 4409);
    a2.close();
  } finally {
    await ctx.close();
  }
});
