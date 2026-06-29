'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const { bootBackend, MockAgent, signedRequest } = require('./helpers');

const GOOD = {
  ok: true, agent_id: 42, client_id: 7, site_id: 3, site: 'HQ',
  hostname: 'DESK-7', display_name: 'DESK-7', is_archived: false, policy_etag: 'p-1',
};

test('a refused command (cmd_ack accepted:false) is requeued to poll', async () => {
  const requeues = [];
  const ctx = await bootBackend({
    portalHandlers: {
      verify_agent: () => ({ ...GOOD }),
      job_requeue: (p) => { requeues.push(p); return { ok: true }; },
    },
  });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { token: 'tok' });
    await agent.waitFor('welcome');

    await signedRequest(ctx.httpBase, 'POST', '/internal/dispatch', { agent_id: 42, job_id: 77, job_type: 'restart', payload: '' });
    const cmd = await agent.waitFor('command');
    agent.send('cmd_ack', { job_id: 77, accepted: false, reason: 'unsupported job_type' }, { ref: cmd.id });

    await new Promise((r) => setTimeout(r, 150));
    assert.strictEqual(requeues.length, 1);
    assert.deepStrictEqual(requeues[0], { agent_id: 42, job_id: 77 });
    agent.close();
  } finally {
    await ctx.close();
  }
});

test('an in-flight command is requeued when the agent disconnects', async () => {
  const requeues = [];
  const ctx = await bootBackend({
    portalHandlers: {
      verify_agent: () => ({ ...GOOD }),
      job_requeue: (p) => { requeues.push(p); return { ok: true }; },
    },
  });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { token: 'tok' });
    await agent.waitFor('welcome');

    await signedRequest(ctx.httpBase, 'POST', '/internal/dispatch', { agent_id: 42, job_id: 88, job_type: 'cmd', payload: 'sleep' });
    await agent.waitFor('command');

    // Agent drops without sending a result.
    agent.ws.terminate();
    await new Promise((r) => setTimeout(r, 250));

    assert.strictEqual(requeues.length, 1);
    assert.deepStrictEqual(requeues[0], { agent_id: 42, job_id: 88 });
  } finally {
    await ctx.close();
  }
});

test('a result that arrives clears the outbox (no requeue on later disconnect)', async () => {
  const requeues = [];
  const ctx = await bootBackend({
    portalHandlers: {
      verify_agent: () => ({ ...GOOD }),
      job_result: () => ({ ok: true, already: false }),
      job_requeue: (p) => { requeues.push(p); return { ok: true }; },
    },
  });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { token: 'tok' });
    await agent.waitFor('welcome');

    await signedRequest(ctx.httpBase, 'POST', '/internal/dispatch', { agent_id: 42, job_id: 99, job_type: 'cmd', payload: 'x' });
    const cmd = await agent.waitFor('command');
    agent.send('cmd_result', { job_id: 99, status: 'done', exit_code: 0, output: '' }, { ref: cmd.id });
    await agent.waitFor('cmd_result_ack');

    agent.ws.terminate();
    await new Promise((r) => setTimeout(r, 200));
    assert.strictEqual(requeues.length, 0);
  } finally {
    await ctx.close();
  }
});
