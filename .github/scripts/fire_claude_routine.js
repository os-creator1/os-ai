'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');

function isAllowedRoutineUrl(value) {
  try {
    const url = new URL(String(value || ''));
    return url.protocol === 'https:'
      && url.hostname === 'api.anthropic.com'
      && /^\/v1\/claude_code\/routines\/trig_[A-Za-z0-9_-]+\/fire$/.test(url.pathname)
      && url.search === ''
      && url.hash === '';
  } catch {
    return false;
  }
}

async function fireRoutine({ url, token, fetchImpl = global.fetch }) {
  if (!isAllowedRoutineUrl(url)) {
    throw new Error('CLAUDE_ROUTINE_URL is missing or is not an official Claude Routine /fire URL.');
  }
  if (typeof token !== 'string' || token.trim() === '') {
    throw new Error('CLAUDE_ROUTINE_TOKEN is missing.');
  }
  const response = await fetchImpl(url, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'anthropic-beta': 'experimental-cc-routine-2026-04-01',
      'anthropic-version': '2023-06-01',
      'Content-Type': 'application/json',
    },
    body: '{}',
  });
  if (!response.ok) {
    throw new Error(`Claude Routine trigger returned HTTP ${response.status}.`);
  }
  const result = await response.json();
  if (
    result?.type !== 'routine_fire'
    || !/^https:\/\/claude\.ai\/code\/session\//.test(String(result.claude_code_session_url || ''))
  ) {
    throw new Error('Claude Routine trigger returned an unexpected response.');
  }
  return result;
}

async function selftest() {
  assert.equal(
    isAllowedRoutineUrl('https://api.anthropic.com/v1/claude_code/routines/trig_01ABC_def/fire'),
    true,
  );
  assert.equal(isAllowedRoutineUrl('https://example.com/v1/claude_code/routines/trig_01ABC/fire'), false);
  assert.equal(isAllowedRoutineUrl('https://api.anthropic.com/v1/claude_code/routines/x/fire'), false);
  assert.equal(isAllowedRoutineUrl(''), false);
  let request;
  const result = await fireRoutine({
    url: 'https://api.anthropic.com/v1/claude_code/routines/trig_01ABC/fire',
    token: 'test-token',
    fetchImpl: async (url, options) => {
      request = { url, options };
      return {
        ok: true,
        status: 200,
        json: async () => ({
          type: 'routine_fire',
          claude_code_session_url: 'https://claude.ai/code/session/session_test',
        }),
      };
    },
  });
  assert.equal(request.options.headers.Authorization, 'Bearer test-token');
  assert.equal(request.options.headers['anthropic-beta'], 'experimental-cc-routine-2026-04-01');
  assert.equal(request.options.body, '{}');
  assert.equal(result.type, 'routine_fire');
  console.log('PASS: 8 Claude Routine trigger checks');
}

async function main() {
  if (process.argv[2] === '--selftest') {
    await selftest();
    return;
  }
  const result = await fireRoutine({
    url: process.env.CLAUDE_ROUTINE_URL,
    token: process.env.CLAUDE_ROUTINE_TOKEN,
  });
  if (process.env.GITHUB_OUTPUT) {
    fs.appendFileSync(process.env.GITHUB_OUTPUT, `session_url=${result.claude_code_session_url}\n`);
  }
  console.log(`PASS: Claude Routine started: ${result.claude_code_session_url}`);
}

if (require.main === module) {
  main().catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
  });
}

module.exports = { fireRoutine, isAllowedRoutineUrl };
