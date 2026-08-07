'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const STATE_PATH = 'docs/automation/AI-AUTONOMY-STATE.json';
const READY_STATUS = 'ready_to_implement_after_human_contract_merge';
const BLOCKED_TARGET_LABELS = new Set([
  'ai:implement',
  'ai:testing',
  'ai:awaiting-codex',
  'ai:codex-reviewed',
  'ai:needs-human',
  'ai:paused',
]);

function contractPathFromSource(source) {
  const value = String(source || '');
  const separator = value.indexOf('#');
  if (separator < 1 || separator === value.length - 1) {
    throw new Error('contract_source must name a Markdown file and anchor.');
  }
  const contractPath = value.slice(0, separator);
  const anchor = value.slice(separator + 1);
  if (
    !/^docs\/automation\/[A-Za-z0-9._-]+\.md$/.test(contractPath)
    || !/^[a-z0-9][a-z0-9-]*$/.test(anchor)
  ) {
    throw new Error('contract_source is outside the trusted automation documentation boundary.');
  }
  return contractPath;
}

function preflightContractMerge({ state, repository, defaultBranch, mergedPullRequest, changedFiles }) {
  if (mergedPullRequest?.merged !== true || mergedPullRequest?.base?.ref !== defaultBranch) {
    return { status: 'ignored', reason: 'not-a-default-branch-merge' };
  }
  if (mergedPullRequest?.head?.repo?.full_name !== repository) {
    return { status: 'ignored', reason: 'external-contract-repository' };
  }

  const changed = [...new Set((changedFiles || []).map((value) => String(value)))];
  if (!changed.includes(STATE_PATH)) {
    return { status: 'ignored', reason: 'state-file-unchanged' };
  }
  if (state?.start_automatically_after_contract_merge !== true) {
    return { status: 'ignored', reason: 'automatic-start-disabled' };
  }

  if (mergedPullRequest?.merged_by?.type !== 'User') {
    throw new Error('A start-authorizing contract must be merged by a human GitHub user.');
  }

  if (
    state.repository !== repository
    || state.base_branch !== defaultBranch
    || state.merge_policy !== 'human_only'
    || state.advance_automatically !== false
    || state.implementation_authorized !== true
    || state.status !== READY_STATUS
    || !Number.isSafeInteger(state.active_pull_request)
    || state.active_pull_request < 1
    || !/^[0-9a-f]{40}$/i.test(String(state.expected_head_sha || ''))
  ) {
    throw new Error('Merged state does not satisfy the locked automatic-start policy.');
  }
  if (state.active_pull_request === mergedPullRequest.number) {
    throw new Error('The contract pull request cannot also be the implementation target.');
  }

  const contractPath = contractPathFromSource(state.contract_source);
  const expected = [STATE_PATH, contractPath].sort();
  if (changed.length !== expected.length || changed.sort().some((value, index) => value !== expected[index])) {
    throw new Error('A start-authorizing contract merge must change exactly the state and named contract.');
  }

  return {
    status: 'authorized',
    targetPrNumber: state.active_pull_request,
    contractPath,
  };
}

function validateTarget({ state, repository, targetPullRequest }) {
  const labels = new Set((targetPullRequest?.labels || []).map((label) => label.name));
  if (
    targetPullRequest?.state !== 'open'
    || targetPullRequest?.draft !== true
    || targetPullRequest?.base?.ref !== state.base_branch
    || targetPullRequest?.head?.ref !== state.head_branch
    || targetPullRequest?.head?.repo?.full_name !== repository
    || targetPullRequest?.head?.sha?.toLowerCase() !== state.expected_head_sha.toLowerCase()
  ) {
    throw new Error('The implementation pull request does not match the locked automatic-start target.');
  }
  const blocked = [...BLOCKED_TARGET_LABELS].filter((label) => labels.has(label));
  if (blocked.length > 0) {
    throw new Error(`The implementation pull request is already active or blocked: ${blocked.join(', ')}.`);
  }
  return { status: 'authorized', targetPrNumber: state.active_pull_request };
}

async function authorize({ github, context, core }) {
  const repository = `${context.repo.owner}/${context.repo.repo}`;
  const statePath = process.env.AI_AUTONOMY_STATE_PATH || path.join(process.cwd(), STATE_PATH);
  const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
  const mergedPullRequest = context.payload.pull_request;
  const files = await github.paginate(github.rest.pulls.listFiles, {
    ...context.repo,
    pull_number: mergedPullRequest.number,
    per_page: 100,
  });
  const preflight = preflightContractMerge({
    state,
    repository,
    defaultBranch: context.payload.repository.default_branch,
    mergedPullRequest,
    changedFiles: files.map((file) => file.filename),
  });
  if (preflight.status !== 'authorized') {
    core.notice(`No automatic start: ${preflight.reason}.`);
    return preflight;
  }

  const { data: targetPullRequest } = await github.rest.pulls.get({
    ...context.repo,
    pull_number: preflight.targetPrNumber,
  });
  validateTarget({ state, repository, targetPullRequest });
  core.info(
    `Authorized automatic start for PR #${preflight.targetPrNumber} from ${preflight.contractPath}.`,
  );
  return preflight;
}

function selftest() {
  const repository = 'owner/repo';
  const sha = '513d21388707a05662b3e07a6cc85c2d07f7f1e1';
  const contractPath = 'docs/automation/RFC-003-M4-SLICE-4A-CONTRACT.md';
  const state = {
    repository,
    active_pull_request: 22,
    base_branch: 'main',
    head_branch: 'feature/rfc-003-m4',
    status: READY_STATUS,
    merge_policy: 'human_only',
    implementation_authorized: true,
    expected_head_sha: sha,
    advance_automatically: false,
    start_automatically_after_contract_merge: true,
    contract_source: `${contractPath}#locked-slice-4a-contract`,
  };
  const mergedPullRequest = {
    number: 21,
    merged: true,
    merged_by: { type: 'User', login: 'human-owner' },
    base: { ref: 'main' },
    head: { repo: { full_name: repository } },
  };
  const changedFiles = [STATE_PATH, contractPath];
  const targetPullRequest = {
    state: 'open',
    draft: true,
    base: { ref: 'main' },
    head: { ref: 'feature/rfc-003-m4', sha, repo: { full_name: repository } },
    labels: [{ name: 'ai:ready-for-human' }],
  };

  assert.equal(contractPathFromSource(state.contract_source), contractPath);
  assert.throws(() => contractPathFromSource('docs/automation/CONTRACT.md'), /file and anchor/);
  assert.throws(() => contractPathFromSource('../CONTRACT.md#locked'), /documentation boundary/);
  assert.deepEqual(
    preflightContractMerge({ state, repository, defaultBranch: 'main', mergedPullRequest, changedFiles }),
    { status: 'authorized', targetPrNumber: 22, contractPath },
  );
  assert.equal(
    preflightContractMerge({
      state,
      repository,
      defaultBranch: 'main',
      mergedPullRequest,
      changedFiles: [contractPath],
    }).reason,
    'state-file-unchanged',
  );
  assert.equal(
    preflightContractMerge({
      state: { ...state, start_automatically_after_contract_merge: false },
      repository,
      defaultBranch: 'main',
      mergedPullRequest,
      changedFiles,
    }).reason,
    'automatic-start-disabled',
  );
  assert.equal(
    preflightContractMerge({
      state,
      repository,
      defaultBranch: 'main',
      mergedPullRequest: { ...mergedPullRequest, merged: false },
      changedFiles,
    }).reason,
    'not-a-default-branch-merge',
  );
  assert.equal(
    preflightContractMerge({
      state,
      repository,
      defaultBranch: 'main',
      mergedPullRequest: {
        ...mergedPullRequest,
        head: { repo: { full_name: 'fork/repo' } },
      },
      changedFiles,
    }).reason,
    'external-contract-repository',
  );
  assert.throws(
    () => preflightContractMerge({
      state,
      repository,
      defaultBranch: 'main',
      mergedPullRequest: { ...mergedPullRequest, merged_by: { type: 'Bot', login: 'merge-bot[bot]' } },
      changedFiles,
    }),
    /merged by a human/,
  );
  assert.throws(
    () => preflightContractMerge({
      state: { ...state, advance_automatically: true },
      repository,
      defaultBranch: 'main',
      mergedPullRequest,
      changedFiles,
    }),
    /locked automatic-start policy/,
  );
  assert.throws(
    () => preflightContractMerge({
      state: { ...state, implementation_authorized: false },
      repository,
      defaultBranch: 'main',
      mergedPullRequest,
      changedFiles,
    }),
    /locked automatic-start policy/,
  );
  assert.throws(
    () => preflightContractMerge({
      state,
      repository,
      defaultBranch: 'main',
      mergedPullRequest,
      changedFiles: [...changedFiles, 'app/Unsafe.php'],
    }),
    /change exactly/,
  );
  assert.throws(
    () => preflightContractMerge({
      state: { ...state, active_pull_request: 21 },
      repository,
      defaultBranch: 'main',
      mergedPullRequest,
      changedFiles,
    }),
    /cannot also be the implementation target/,
  );
  assert.deepEqual(validateTarget({ state, repository, targetPullRequest }), {
    status: 'authorized',
    targetPrNumber: 22,
  });
  assert.throws(
    () => validateTarget({
      state,
      repository,
      targetPullRequest: { ...targetPullRequest, draft: false },
    }),
    /does not match/,
  );
  assert.throws(
    () => validateTarget({
      state,
      repository,
      targetPullRequest: {
        ...targetPullRequest,
        head: { ...targetPullRequest.head, sha: '613d21388707a05662b3e07a6cc85c2d07f7f1e1' },
      },
    }),
    /does not match/,
  );
  assert.throws(
    () => validateTarget({
      state,
      repository,
      targetPullRequest: { ...targetPullRequest, labels: [{ name: 'ai:paused' }] },
    }),
    /already active or blocked/,
  );
  console.log('PASS: 16 automatic-start checks');
}

if (require.main === module) {
  if (process.argv[2] !== '--selftest') {
    throw new Error('Only --selftest is supported when this file is run directly.');
  }
  selftest();
}

module.exports = {
  authorize,
  contractPathFromSource,
  preflightContractMerge,
  validateTarget,
};
