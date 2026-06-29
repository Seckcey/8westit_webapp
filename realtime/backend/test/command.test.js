'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const { bootBackend, MockAgent, signedRequest } = require('./helpers');

const GOOD = {
  ok: true, agent_id: 42, client_id: 7, site_id: 3, site: 'HQ',
  hostname: 'DESK-7', display_name: 'DESK-7', is_archived: false, policy_etag: 'p-1',
};

async function onlineAgent(ctx, token = 'tok') {
  const agent = new MockAgent(ctx.wsUrl);
  await agent.open();
  agent.send('hello', { token });
  await agent.waitFor('welcome');
  return agent;
}

test('full command round-trip: dispatch → command → cmd_ack → cmd_result → job_result → cmd_result_ack', async () => {
  const jobResults = [];
  const ctx = await bootBackend({
    portalHandlers: {
      verify_agent: () => ({ ...GOOD }),
      job_result: (payload) => {
        jobResults.push(payload);
        return { ok: true, already: false };
      },
    },
  });
  try {
    const agent = await onlineAgent(ctx);

    // Portal "Run now" → /internal/dispatch.
    const disp = await signedRequest(ctx.httpBase, 'POST', '/internal/dispatch', {
      agent_id: 42,
      job_id: 501,
      job_type: 'powershell',
      payload: 'Get-Service Spooler',
      timeout_secs: 300,
      not_after: null,
      tool_action_id: null,
    });
    assert.strictEqual(disp.status, 200);
    assert.strictEqual(disp.json.delivered, true);

    // Agent receives the command.
    const cmd = await agent.waitFor('command');
    assert.strictEqual(cmd.d.job_id, 501);
    assert.strictEqual(cmd.d.job_type, 'powershell');
    assert.strictEqual(cmd.d.payload, 'Get-Service Spooler');

    // Agent acks then results.
    agent.send('cmd_ack', { job_id: 501, accepted: true, reason: '' }, { ref: cmd.id });
    agent.send('cmd_result', { job_id: 501, status: 'done', exit_code: 0, output: 'Running', truncated: false }, { ref: cmd.id });

    // Backend persisted to portal and acked.
    const ack = await agent.waitFor('cmd_result_ack');
    assert.strictEqual(ack.d.job_id, 501);
    assert.strictEqual(ack.d.persisted, true);

    assert.strictEqual(jobResults.length, 1);
    assert.deepStrictEqual(
      { agent_id: jobResults[0].agent_id, job_id: jobResults[0].job_id, status: jobResults[0].status, exit_code: jobResults[0].exit_code },
      { agent_id: 42, job_id: 501, status: 'done', exit_code: 0 }
    );
    agent.close();
  } finally {
    await ctx.close();
  }
});

test('dispatch to an offline agent returns delivered:false', async () => {
  const ctx = await bootBackend({ portalHandlers: { verify_agent: () => ({ ...GOOD }) } });
  try {
    const disp = await signedRequest(ctx.httpBase, 'POST', '/internal/dispatch', {
      agent_id: 999, job_id: 1, job_type: 'cmd', payload: 'echo hi',
    });
    assert.strictEqual(disp.status, 200);
    assert.strictEqual(disp.json.delivered, false);
  } finally {
    await ctx.close();
  }
});

test('cmd_result status is normalized to done|error', async () => {
  const got = [];
  const ctx = await bootBackend({
    portalHandlers: {
      verify_agent: () => ({ ...GOOD }),
      job_result: (p) => { got.push(p.status); return { ok: true, already: false }; },
    },
  });
  try {
    const agent = await onlineAgent(ctx);
    await signedRequest(ctx.httpBase, 'POST', '/internal/dispatch', { agent_id: 42, job_id: 7, job_type: 'cmd', payload: 'x' });
    const cmd = await agent.waitFor('command');
    // Send a bogus status; backend must coerce non-"error" to "done".
    agent.send('cmd_result', { job_id: 7, status: 'weird', exit_code: 0, output: '' }, { ref: cmd.id });
    await agent.waitFor('cmd_result_ack');
    assert.deepStrictEqual(got, ['done']);

    await signedRequest(ctx.httpBase, 'POST', '/internal/dispatch', { agent_id: 42, job_id: 8, job_type: 'cmd', payload: 'x' });
    const cmd2 = await agent.waitFor('command');
    agent.send('cmd_result', { job_id: 8, status: 'error', exit_code: 1, output: 'boom' }, { ref: cmd2.id });
    await agent.waitFor('cmd_result_ack');
    assert.deepStrictEqual(got, ['done', 'error']);
    agent.close();
  } finally {
    await ctx.close();
  }
});
