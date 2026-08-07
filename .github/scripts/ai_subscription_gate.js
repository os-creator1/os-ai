'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const ANSI_ESCAPE = /[\u001B\u009B][[\]()#;?]*(?:(?:[a-zA-Z\d]*(?:;[-a-zA-Z\d\/#&.:=?%@~_]+)*)?\u0007|(?:(?:\d{1,4}(?:[;:]\d{0,4})*)?[\dA-PR-TZcf-nq-uy=><~]))/g;
const SAFE_IDENTIFIER = /^[A-Za-z_][A-Za-z0-9_\\-]*$/;
const SAFE_TEST_PATH = /^tests\/(Feature|Unit)\/[A-Za-z0-9_./-]+\.php$/;
const SAFE_TEST_DIRECTORY = /^tests\/(Feature|Unit)\/[A-Za-z0-9_./-]+$/;
const SAFE_PHP_TEST_RUNNERS = new Set([
  'tests/Feature/Workspace/Support/run_historical_m1a_suite.php',
  'tests/Feature/Workspace/Support/run_workspace_enforcement_suite.php',
]);
const SAFE_NODE_SELFTESTS = new Set([
  '.github/scripts/ai_subscription_autostart.js',
  '.github/scripts/ai_subscription_gate.js',
  '.github/scripts/ai_subscription_labels.js',
  '.github/scripts/fire_claude_routine.js',
]);

const DEFAULT_CODEX_LOGINS = Object.freeze([
  'chatgpt-codex-connector',
  'chatgpt-codex-connector[bot]',
]);

function parseReviewerLogins(configured) {
  const values = String(configured || '')
    .split(',')
    .map((value) => value.trim().toLowerCase())
    .filter(Boolean);
  return new Set(values.length > 0 ? values : DEFAULT_CODEX_LOGINS);
}

function reviewerIsTrusted(login, configured) {
  return parseReviewerLogins(configured).has(String(login || '').toLowerCase());
}

function reviewMatchesHead(reviewCommitId, headSha) {
  return /^[0-9a-f]{40}$/i.test(String(reviewCommitId || ''))
    && String(reviewCommitId).toLowerCase() === String(headSha || '').toLowerCase();
}

function cleanCodexCommentMatchesHead(body, headSha) {
  const text = String(body || '');
  if (!/Codex Review:\s*Didn't find any major issues/i.test(text)) {
    return false;
  }
  const reviewed = text.match(/\*\*Reviewed commit:\*\*\s*\`([0-9a-f]{10,40})\`/i)?.[1];
  return Boolean(reviewed)
    && String(headSha || '').toLowerCase().startsWith(reviewed.toLowerCase());
}

function findTrustedCodexResult({
  reviews = [],
  comments = [],
  headSha,
  configuredLogins = '',
}) {
  const review = reviews.find((candidate) =>
    reviewerIsTrusted(candidate.user?.login, configuredLogins)
    && reviewMatchesHead(candidate.commit_id, headSha)
  );
  if (review) {
    return { kind: 'review', id: String(review.id) };
  }
  const comment = comments.find((candidate) =>
    reviewerIsTrusted(candidate.user?.login, configuredLogins)
    && cleanCodexCommentMatchesHead(candidate.body, headSha)
  );
  return comment ? { kind: 'clean-comment', id: String(comment.id) } : null;
}


function loadState(statePath) {
  const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
  const requiredStrings = [
    'repository',
    'base_branch',
    'head_branch',
    'current_slice',
    'gate_label',
    'success_label',
    'failure_label',
  ];

  for (const key of requiredStrings) {
    if (typeof state[key] !== 'string' || state[key].trim() === '') {
      throw new Error(`State field ${key} must be a non-empty string.`);
    }
  }

  if (typeof state.implementation_authorized !== 'boolean') {
    throw new Error('implementation_authorized must be a boolean.');
  }

  const hasActivePullRequest = Number.isSafeInteger(state.active_pull_request)
    && state.active_pull_request > 0;
  if (state.active_pull_request !== null && !hasActivePullRequest) {
    throw new Error('active_pull_request must be null or a positive integer.');
  }
  if (state.implementation_authorized === true && !hasActivePullRequest) {
    throw new Error('Implementation state must name an active pull request.');
  }

  if (state.merge_policy !== 'human_only' || state.advance_automatically !== false) {
    throw new Error('Automation state must retain human-only merge and advancement policy.');
  }

  if (
    state.implementation_authorized === false
    && !/^[0-9a-f]{40}$/i.test(String(state.expected_head_sha || ''))
  ) {
    throw new Error('Verification-only state must pin a full expected_head_sha.');
  }

  if (state.require_exact_scope !== undefined && typeof state.require_exact_scope !== 'boolean') {
    throw new Error('require_exact_scope must be a boolean when present.');
  }

  if (
    state.start_automatically_after_contract_merge !== undefined
    && typeof state.start_automatically_after_contract_merge !== 'boolean'
  ) {
    throw new Error('start_automatically_after_contract_merge must be a boolean when present.');
  }

  if (
    state.start_automatically_after_contract_merge === true
    && (
      state.implementation_authorized !== true
      || state.status !== 'ready_to_implement_after_human_contract_merge'
      || !/^[0-9a-f]{40}$/i.test(String(state.expected_head_sha || ''))
    )
  ) {
    throw new Error('Automatic start requires a locked implementation state and full target SHA.');
  }

  for (const key of ['allowed_paths', 'required_new_paths', 'required_test_commands']) {
    if (!Array.isArray(state[key]) || state[key].length === 0) {
      throw new Error(`State field ${key} must be a non-empty array.`);
    }
    if (state[key].some((value) => typeof value !== 'string' || value.trim() === '')) {
      throw new Error(`State field ${key} contains an invalid value.`);
    }
  }

  if (new Set(state.allowed_paths).size !== state.allowed_paths.length) {
    throw new Error('allowed_paths contains a duplicate.');
  }

  for (const requiredPath of state.required_new_paths) {
    if (!state.allowed_paths.includes(requiredPath)) {
      throw new Error(`Required path is not allowed: ${requiredPath}`);
    }
  }

  for (const command of state.required_test_commands) {
    if (!isSafeTestCommand(command)) {
      throw new Error(`Unsafe required test command: ${command}`);
    }
  }

  return state;
}

function splitCommand(command) {
  if (/['"`$;&|<>\n\r]/.test(command)) {
    return [];
  }
  return command.trim().split(/\s+/).filter(Boolean);
}

function isSafeTestCommand(command) {
  const tokens = splitCommand(command);
  if (
    tokens.length === 3
    && tokens[0] === 'node'
    && SAFE_NODE_SELFTESTS.has(tokens[1])
    && tokens[2] === '--selftest'
  ) {
    return true;
  }

  if (
    tokens.length === 2
    && tokens[0] === 'php'
    && SAFE_PHP_TEST_RUNNERS.has(tokens[1])
  ) {
    return true;
  }

  if (tokens.length !== 4 || tokens[0] !== 'php' || tokens[1] !== 'artisan' || tokens[2] !== 'test') {
    return false;
  }

  const selector = tokens[3];
  if (selector.startsWith('--filter=')) {
    return SAFE_IDENTIFIER.test(selector.slice('--filter='.length));
  }
  return SAFE_TEST_PATH.test(selector) || SAFE_TEST_DIRECTORY.test(selector);
}

function discoveredTestCount(output) {
  const clean = String(output || '').replace(ANSI_ESCAPE, '');
  if (/\bno tests found\b/i.test(clean)) {
    return 0;
  }

  const lines = clean.split(/\r?\n/).reverse();
  const statusPattern = /(\d+)\s+(?:passed|failed|errored|errors?|failures?|risky|skipped|incomplete|warnings?)/gi;
  for (const line of lines) {
    if (!/Tests:/i.test(line)) {
      continue;
    }
    const counts = [...line.matchAll(statusPattern)].map((match) => Number(match[1]));
    if (counts.length > 0) {
      return counts.reduce((sum, value) => sum + value, 0);
    }
    if (/\bTests:\s*0\b/i.test(line)) {
      return 0;
    }
  }

  for (const pattern of [
    /\bPASS:\s*(\d+)\s+[A-Za-z0-9 -]+\s+checks\b/i,
    /\bOK\s*\(\s*(\d+)\s+tests?\b/i,
    /\b(\d+)\s+tests?\s+passed\b/i,
    /\bTests:\s*(\d+)\s*,\s*Assertions:\s*\d+\b/i,
  ]) {
    const match = clean.match(pattern);
    if (match) {
      return Number(match[1]);
    }
  }

  return null;
}

function checkScope(state, changedFiles, repositoryRoot) {
  const allowed = new Set(state.allowed_paths);
  const changed = changedFiles.map((value) => value.trim()).filter(Boolean);
  const forbidden = changed.filter((value) => !allowed.has(value));
  const missingExpected = state.require_exact_scope === true
    ? state.allowed_paths.filter((value) => !changed.includes(value))
    : [];
  const missing = state.required_new_paths.filter(
    (value) => !fs.existsSync(path.join(repositoryRoot, value)),
  );

  return {
    ok: changed.length > 0
      && forbidden.length === 0
      && missingExpected.length === 0
      && missing.length === 0,
    changed_files: changed,
    forbidden_files: forbidden,
    missing_expected_files: missingExpected,
    missing_required_files: missing,
  };
}

function runTests(state, repositoryRoot) {
  const results = [];

  for (const command of state.required_test_commands) {
    if (!isSafeTestCommand(command)) {
      results.push({ command, status: 'rejected', test_count: 0, reason: 'unsafe command' });
      continue;
    }

    const argv = splitCommand(command);
    const processResult = spawnSync(argv[0], argv.slice(1), {
      cwd: repositoryRoot,
      encoding: 'utf8',
      shell: false,
      timeout: 600_000,
      maxBuffer: 20 * 1024 * 1024,
    });
    const output = `${processResult.stdout || ''}\n${processResult.stderr || ''}`;
    const testCount = discoveredTestCount(output);
    let status = 'passed';
    let reason = '';

    if (processResult.error) {
      status = 'failed';
      reason = processResult.error.code === 'ETIMEDOUT' ? 'timed out' : processResult.error.message;
    } else if (processResult.status !== 0) {
      status = 'failed';
      reason = `exited with code ${processResult.status}`;
    } else if (testCount === 0) {
      status = 'failed';
      reason = 'discovered zero tests';
    } else if (testCount === null) {
      status = 'failed';
      reason = 'no recognizable positive test summary';
    }

    results.push({
      command,
      status,
      exit_code: processResult.status,
      test_count: testCount || 0,
      reason,
      output_tail: output.replace(ANSI_ESCAPE, '').slice(-6000),
    });
  }

  return {
    verified: results.length > 0 && results.every((result) => result.status === 'passed'),
    results,
  };
}

function readFlag(name, fallback = null) {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
}

function selftest() {
  assert.equal(isSafeTestCommand('node .github/scripts/ai_subscription_autostart.js --selftest'), true);
  assert.equal(isSafeTestCommand('node .github/scripts/ai_subscription_gate.js --selftest'), true);
  assert.equal(isSafeTestCommand('node .github/scripts/unknown.js --selftest'), false);
  assert.equal(isSafeTestCommand('node .github/scripts/ai_subscription_gate.js --selftest extra'), false);
  assert.equal(isSafeTestCommand('php artisan test --filter=WorkspaceOverviewHttpTest'), true);
  assert.equal(isSafeTestCommand('php artisan test tests/Feature/Workspace/FooTest.php'), true);
  assert.equal(isSafeTestCommand('php artisan test tests/Feature/Workspace'), true);
  assert.equal(
    isSafeTestCommand('php tests/Feature/Workspace/Support/run_historical_m1a_suite.php'),
    true,
  );
  assert.equal(
    isSafeTestCommand('php tests/Feature/Workspace/Support/run_workspace_enforcement_suite.php'),
    true,
  );
  assert.equal(isSafeTestCommand('php tests/Feature/Workspace/Support/arbitrary.php'), false);
  assert.equal(isSafeTestCommand('php artisan test'), false);
  assert.equal(isSafeTestCommand('php artisan test --filter=Foo;env'), false);
  assert.equal(isSafeTestCommand('composer test'), false);
  assert.equal(discoveredTestCount('Tests:    20 passed (54 assertions)'), 20);
  assert.equal(discoveredTestCount('Tests:  2 failed, 13 passed (40 assertions)'), 15);
  assert.equal(discoveredTestCount('PASS: 15 automatic-start checks'), 15);
  assert.equal(discoveredTestCount('OK (7 tests, 21 assertions)'), 7);
  assert.equal(discoveredTestCount('INFO  No tests found.'), 0);
  assert.equal(discoveredTestCount('command completed'), null);
  const head = '4acce6f6ce368ba599db8f7b41a38548ae6c75ec';
  assert.equal(reviewerIsTrusted('chatgpt-codex-connector[bot]', ''), true);
  assert.equal(reviewerIsTrusted('lookalike[bot]', ''), false);
  assert.equal(reviewMatchesHead(head, head), true);
  assert.equal(reviewMatchesHead('4acce6f6ce', head), false);
  assert.equal(
    cleanCodexCommentMatchesHead(
      "Codex Review: Didn't find any major issues.\n\n**Reviewed commit:** \`4acce6f6ce\`",
      head,
    ),
    true,
  );
  assert.equal(
    cleanCodexCommentMatchesHead(
      "Codex Review: Didn't find any major issues.\n\n**Reviewed commit:** \`4e9a5dbb57\`",
      head,
    ),
    false,
  );
  assert.deepEqual(
    findTrustedCodexResult({
      reviews: [{ id: 7, commit_id: head, user: { login: 'chatgpt-codex-connector[bot]' } }],
      comments: [],
      headSha: head,
    }),
    { kind: 'review', id: '7' },
  );
  assert.deepEqual(
    findTrustedCodexResult({
      reviews: [],
      comments: [{
        id: 8,
        user: { login: 'chatgpt-codex-connector[bot]' },
        body: "Codex Review: Didn't find any major issues.\n\n**Reviewed commit:** \`4acce6f6ce\`",
      }],
      headSha: head,
    }),
    { kind: 'clean-comment', id: '8' },
  );

  const state = {
    allowed_paths: ['a.php', 'b.php'],
    required_new_paths: ['b.php'],
  };
  const fixtureRoot = fs.mkdtempSync(path.join(process.env.RUNNER_TEMP || '/tmp', 'ai-gate-'));
  fs.writeFileSync(path.join(fixtureRoot, 'b.php'), 'fixture');
  assert.deepEqual(checkScope(state, ['a.php', 'b.php'], fixtureRoot), {
    ok: true,
    changed_files: ['a.php', 'b.php'],
    forbidden_files: [],
    missing_expected_files: [],
    missing_required_files: [],
  });
  assert.equal(checkScope(state, ['outside.php'], fixtureRoot).ok, false);
  assert.equal(checkScope({ ...state, require_exact_scope: true }, ['a.php'], fixtureRoot).ok, false);
  const idleStatePath = path.join(fixtureRoot, 'idle-state.json');
  const idleState = {
    repository: 'owner/repo',
    active_pull_request: null,
    base_branch: 'main',
    head_branch: 'none',
    current_slice: 'Idle',
    status: 'automation_ready_pending_next_locked_contract',
    merge_policy: 'human_only',
    implementation_authorized: false,
    expected_head_sha: '513d21388707a05662b3e07a6cc85c2d07f7f1e1',
    advance_automatically: false,
    start_automatically_after_contract_merge: false,
    allowed_paths: ['a.php'],
    required_new_paths: ['a.php'],
    required_test_commands: ['node .github/scripts/ai_subscription_gate.js --selftest'],
    gate_label: 'ai:testing',
    success_label: 'ai:ready-for-human',
    failure_label: 'ai:needs-human',
  };
  fs.writeFileSync(idleStatePath, JSON.stringify(idleState));
  assert.equal(loadState(idleStatePath).active_pull_request, null);
  fs.writeFileSync(idleStatePath, JSON.stringify({
    ...idleState,
    implementation_authorized: true,
  }));
  assert.throws(() => loadState(idleStatePath), /must name an active pull request/);
  fs.rmSync(fixtureRoot, { recursive: true, force: true });
  console.log('PASS: 32 subscription-gate checks');
}

function main() {
  const command = process.argv[2];
  if (command === '--selftest') {
    selftest();
    return;
  }

  const statePath = readFlag('--state');
  if (!statePath) {
    throw new Error('--state is required.');
  }
  const state = loadState(statePath);

  if (command === 'validate-state') {
    console.log(`PASS: state contract for ${state.current_slice} is valid`);
    return;
  }

  if (command === 'check-scope') {
    const changedFilesPath = readFlag('--changed-files');
    const repositoryRoot = readFlag('--repo-root', process.cwd());
    const outputPath = readFlag('--output');
    const changedFiles = fs.readFileSync(changedFilesPath, 'utf8').split(/\r?\n/);
    const result = checkScope(state, changedFiles, repositoryRoot);
    fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`);
    if (!result.ok) {
      throw new Error(`Scope gate failed: ${JSON.stringify(result)}`);
    }
    console.log(`PASS: ${result.changed_files.length} changed files are inside the locked scope`);
    return;
  }

  if (command === 'run-tests') {
    const repositoryRoot = readFlag('--repo-root', process.cwd());
    const outputPath = readFlag('--output');
    const result = runTests(state, repositoryRoot);
    fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`);
    for (const test of result.results) {
      console.log(`${test.status.toUpperCase()}: ${test.command} (${test.test_count} tests)`);
      if (test.status !== 'passed' && test.output_tail) {
        const safeTail = test.output_tail.replace(/^::/gm, ' ::');
        console.error(`--- failure output tail: ${test.command} ---`);
        console.error(safeTail);
        console.error('--- end failure output tail ---');
      }
    }
    if (!result.verified) {
      throw new Error('One or more required test commands failed the positive-test gate.');
    }
    return;
  }

  throw new Error(`Unsupported command: ${command}`);
}

if (require.main === module) {
  main();
}

module.exports = {
  checkScope,
  cleanCodexCommentMatchesHead,
  findTrustedCodexResult,
  discoveredTestCount,
  isSafeTestCommand,
  loadState,
  runTests,
};
