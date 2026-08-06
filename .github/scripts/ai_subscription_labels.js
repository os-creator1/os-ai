'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const LABELS = Object.freeze({
  implement: 'ai:implement',
  testing: 'ai:testing',
  awaitingCodex: 'ai:awaiting-codex',
  codexReviewed: 'ai:codex-reviewed',
  readyForHuman: 'ai:ready-for-human',
  needsHuman: 'ai:needs-human',
  paused: 'ai:paused',
});

const LABEL_DEFINITIONS = Object.freeze({
  [LABELS.implement]: ['1D76DB', 'Claude may implement the locked current slice.'],
  [LABELS.testing]: ['C5DEF5', 'A pushed Claude commit is waiting for the deterministic GitHub test gate.'],
  [LABELS.awaitingCodex]: ['FBCA04', 'Waiting for a Codex review of the current PR head.'],
  [LABELS.codexReviewed]: ['5319E7', 'A trusted Codex review is ready for Claude to process.'],
  [LABELS.readyForHuman]: ['0E8A16', 'All automated gates passed; a human may decide whether to merge.'],
  [LABELS.needsHuman]: ['D93F0B', 'Automation stopped because a bounded guard requires human judgment.'],
  [LABELS.paused]: ['6E7781', 'Subscription automation is paused.'],
});

const DEFAULT_CODEX_LOGINS = Object.freeze([
  'chatgpt-codex-connector',
  'chatgpt-codex-connector[bot]',
]);

const MANAGED_LABELS = Object.freeze(Object.values(LABELS));
const COMMANDS = new Set(['start', 'resume', 'pause', 'review-submitted']);

function implementationCommandIsAuthorized(command, state) {
  return !['start', 'resume'].includes(command) || state?.implementation_authorized === true;
}

function loadAutomationState() {
  const statePath = process.env.AI_AUTONOMY_STATE_PATH
    || path.join(process.cwd(), 'docs/automation/AI-AUTONOMY-STATE.json');
  return JSON.parse(fs.readFileSync(statePath, 'utf8'));
}

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

function parsePrNumber(raw) {
  const value = Number.parseInt(String(raw || ''), 10);

  if (!Number.isSafeInteger(value) || value < 1 || String(value) !== String(raw).trim()) {
    throw new Error(`Invalid pull request number: ${raw}`);
  }

  return value;
}

function transitionFor(command, currentLabels) {
  const current = new Set(currentLabels);

  if (!COMMANDS.has(command)) {
    throw new Error(`Unsupported command: ${command}`);
  }

  if (
    command === 'review-submitted'
    && (current.has(LABELS.paused) || current.has(LABELS.needsHuman))
  ) {
    return { status: 'ignored', transition: 'review-blocked', add: [], remove: [] };
  }

  if (command === 'review-submitted' && !current.has(LABELS.awaitingCodex)) {
    return { status: 'ignored', transition: 'review-not-awaited', add: [], remove: [] };
  }

  const target = command === 'pause'
    ? LABELS.paused
    : command === 'review-submitted'
      ? LABELS.codexReviewed
      : LABELS.implement;

  return {
    status: 'transitioned',
    transition: `${command}->${target}`,
    add: [target],
    remove: MANAGED_LABELS.filter((label) => label !== target && current.has(label)),
  };
}

async function ensureLabel(github, owner, repo, label) {
  const [color, description] = LABEL_DEFINITIONS[label];

  try {
    await github.rest.issues.getLabel({ owner, repo, name: label });
  } catch (error) {
    if (error.status !== 404) {
      throw error;
    }

    try {
      await github.rest.issues.createLabel({ owner, repo, name: label, color, description });
    } catch (createError) {
      if (createError.status !== 422) {
        throw createError;
      }
    }
  }
}

async function removeLabelIfPresent(github, owner, repo, issueNumber, label) {
  try {
    await github.rest.issues.removeLabel({ owner, repo, issue_number: issueNumber, name: label });
  } catch (error) {
    if (error.status !== 404) {
      throw error;
    }
  }
}

async function loadCurrentLabels(github, owner, repo, issueNumber) {
  const response = await github.paginate(github.rest.issues.listLabelsOnIssue, {
    owner,
    repo,
    issue_number: issueNumber,
    per_page: 100,
  });

  return response.map((label) => label.name);
}

async function control({ github, context, core }) {
  const command = process.env.CONTROL_COMMAND;
  const prNumber = parsePrNumber(process.env.CONTROL_PR_NUMBER);
  const { owner, repo } = context.repo;

  if (!COMMANDS.has(command)) {
    throw new Error(`Unsupported command: ${command}`);
  }

  if (!implementationCommandIsAuthorized(command, loadAutomationState())) {
    throw new Error(
      `Command ${command} is disabled because the locked state authorizes verification only.`,
    );
  }

  const { data: pullRequest } = await github.rest.pulls.get({
    owner,
    repo,
    pull_number: prNumber,
  });

  if (pullRequest.state !== 'open') {
    throw new Error(`PR #${prNumber} is not open.`);
  }

  if (pullRequest.base.ref !== 'main') {
    throw new Error(`PR #${prNumber} targets ${pullRequest.base.ref}, not main.`);
  }

  if (pullRequest.head.repo.full_name !== `${owner}/${repo}`) {
    throw new Error(`PR #${prNumber} comes from an external repository.`);
  }

  if (command === 'review-submitted') {
    const reviewer = context.payload.review?.user?.login;

    if (!reviewerIsTrusted(reviewer, process.env.CODEX_REVIEWER_LOGIN)) {
      core.notice(`Ignoring review from untrusted reviewer ${reviewer || '(missing)'}.`);
      return { prNumber, status: 'ignored', transition: 'untrusted-reviewer' };
    }

    if (!reviewMatchesHead(context.payload.review?.commit_id, pullRequest.head.sha)) {
      core.notice('Ignoring a trusted review that is not attached to the current PR head.');
      return { prNumber, status: 'ignored', transition: 'stale-review' };
    }
  }

  const currentLabels = await loadCurrentLabels(github, owner, repo, prNumber);
  const transition = transitionFor(command, currentLabels);

  if (transition.status === 'ignored') {
    core.notice(`No state change: ${transition.transition}.`);
    return { prNumber, ...transition };
  }

  const labelsToEnsure = command === 'review-submitted' ? transition.add : MANAGED_LABELS;
  for (const label of labelsToEnsure) {
    await ensureLabel(github, owner, repo, label);
  }

  for (const label of transition.remove) {
    await removeLabelIfPresent(github, owner, repo, prNumber, label);
  }

  if (command === 'start' || command === 'resume' || command === 'review-submitted') {
    const mode = command === 'review-submitted' ? 'correction' : 'implementation';
    await github.rest.issues.createComment({
      owner,
      repo,
      issue_number: prNumber,
      body: [
        '[AI-SUBSCRIPTION-LOOP LEASE]',
        `mode: ${mode}`,
        `before_sha: \`${pullRequest.head.sha}\``,
        'This marker is written before the trigger label so the test gate can prove that Claude pushed real progress.',
      ].join('\n'),
    });
  }

  await github.rest.issues.addLabels({
    owner,
    repo,
    issue_number: prNumber,
    labels: transition.add,
  });

  core.info(`Applied ${transition.transition} to PR #${prNumber}.`);
  return { prNumber, ...transition };
}

function selftest() {
  assert.equal(reviewerIsTrusted('chatgpt-codex-connector[bot]', ''), true);
  assert.equal(reviewerIsTrusted('ChatGPT-Codex-Connector', ''), true);
  assert.equal(reviewerIsTrusted('codex-lookalike', ''), false);
  assert.equal(reviewerIsTrusted('approved-reviewer[bot]', 'approved-reviewer[bot]'), true);
  assert.equal(reviewerIsTrusted('chatgpt-codex-connector[bot]', 'approved-reviewer[bot]'), false);
  assert.equal(
    reviewMatchesHead(
      '513d21388707a05662b3e07a6cc85c2d07f7f1e1',
      '513d21388707a05662b3e07a6cc85c2d07f7f1e1',
    ),
    true,
  );
  assert.equal(reviewMatchesHead('513d213', '513d213'), false);
  assert.equal(
    reviewMatchesHead(
      '513d21388707a05662b3e07a6cc85c2d07f7f1e1',
      '613d21388707a05662b3e07a6cc85c2d07f7f1e1',
    ),
    false,
  );

  assert.equal(parsePrNumber('2'), 2);
  assert.throws(() => parsePrNumber('2x'), /Invalid pull request number/);
  assert.throws(() => parsePrNumber('0'), /Invalid pull request number/);
  assert.equal(implementationCommandIsAuthorized('start', { implementation_authorized: true }), true);
  assert.equal(implementationCommandIsAuthorized('resume', { implementation_authorized: false }), false);
  assert.equal(implementationCommandIsAuthorized('pause', { implementation_authorized: false }), true);
  assert.equal(implementationCommandIsAuthorized('review-submitted', { implementation_authorized: false }), true);

  assert.deepEqual(
    transitionFor('start', [LABELS.paused, 'unrelated']),
    {
      status: 'transitioned',
      transition: 'start->ai:implement',
      add: [LABELS.implement],
      remove: [LABELS.paused],
    },
  );

  assert.deepEqual(
    transitionFor('review-submitted', [LABELS.awaitingCodex]),
    {
      status: 'transitioned',
      transition: 'review-submitted->ai:codex-reviewed',
      add: [LABELS.codexReviewed],
      remove: [LABELS.awaitingCodex],
    },
  );

  assert.deepEqual(
    transitionFor('review-submitted', [LABELS.readyForHuman]),
    {
      status: 'ignored',
      transition: 'review-not-awaited',
      add: [],
      remove: [],
    },
  );

  assert.deepEqual(
    transitionFor('review-submitted', [LABELS.awaitingCodex, LABELS.needsHuman]),
    {
      status: 'ignored',
      transition: 'review-blocked',
      add: [],
      remove: [],
    },
  );

  assert.equal(new Set(MANAGED_LABELS).size, MANAGED_LABELS.length);
  console.log(`PASS: ${20} subscription-loop checks`);
}

if (require.main === module) {
  if (process.argv[2] !== '--selftest') {
    throw new Error('Only --selftest is supported when this file is run directly.');
  }

  selftest();
}

module.exports = control;
module.exports._test = {
  LABELS,
  MANAGED_LABELS,
  implementationCommandIsAuthorized,
  parsePrNumber,
  reviewMatchesHead,
  reviewerIsTrusted,
  transitionFor,
};
