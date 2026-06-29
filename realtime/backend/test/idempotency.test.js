'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const { bootBackend, MockAgent, signedRequest } = require('./helpers');

const GOOD = {
  ok: true, agent_id: 42, client_id: 7, site_id: 3, site: 'HQ',
  hostname: 'DESK-7', display_name: 'DESK-7', is_archived: false, policy_etag: 'p-1',
};

test('a redelivered cmd_result for an already-terminal job is acked (already:true) and safe', async () => {
  let calls = 0;
  const ctx = await bootBackend({
    portalHandlers: {
      verify_agent: () => ({ ...GOOD }),
      job_result: () => {
        calls += 1;
        // Portal is idempotent by job_id: first write applies, later writes hit a
        // non-running row -> already:true. Simulate that here.
        return { ok: true, already: calls > 1 };
      },
    },
  });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { token: 'tok' });
    await agent.waitFor('welcome');

    await signedRequest(ctx.httpBase, 'POST', '/internal/dispatch', { agent_id: 42, job_id: 501, job_type: 'cmd', payload: 'x' });
    const cmd = await agent.waitFor('command');

    // First result.
    agent.send('cmd_result', { job_id: 501, status: 'done', exit_code: 0, output: 'a' }, { ref: cmd.id });
    const ack1 = await agent.waitFor('cmd_result_ack');
    assert.strictEqual(ack1.d.persisted, true);

    // Redelivered identical result (agent never saw the ack, retries).
    agent.send('cmd_result', { job_id: 501, status: 'done', exit_code: 0, output: 'a' }, { ref: cmd.id });
    const ack2 = await agent.waitFor('cmd_result_ack');
    assert.strictEqual(ack2.d.job_id, 501);
    assert.strictEqual(ack2.d.persisted, true);

    // Both results reached the portal (idempotent there); the backend acked both.
    assert.strictEqual(calls, 2);
    agent.close();
  } finally {
    await ctx.close();
  }
});

test('when job_result persist fails, no ack is sent (REST fallback is the backstop)', async () => {
  const ctx = await bootBackend({
    portalHandlers: {
      verify_agent: () => ({ ...GOOD }),
      job_result: () => {
        const e = new Error('portal down');
        e.code = 'NETWORK_ERROR';
        throw e;
      },
    },
  });
  try {
    const agent = new MockAgent(ctx.wsUrl);
    await agent.open();
    agent.send('hello', { token: 'tok' });
    await agent.waitFor('welcome');

    await signedRequest(ctx.httpBase, 'POST', '/internal/dispatch', { agent_id: 42, job_id: 9, job_type: 'cmd', payload: 'x' });
    const cmd = await agent.waitFor('command');
    agent.send('cmd_result', { job_id: 9, status: 'done', exit_code: 0, output: '' }, { ref: cmd.id });

    // No cmd_result_ack should arrive (persist failed).
    await assert.rejects(agent.waitFor('cmd_result_ack', 500), /timeout/);
    agent.close();
  } finally {
    await ctx.close();
  }
});
