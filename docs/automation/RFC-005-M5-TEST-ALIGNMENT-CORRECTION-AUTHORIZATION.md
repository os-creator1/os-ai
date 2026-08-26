# RFC-005 Milestone 5 — Post-Verification Entitlement-Reason Test-Alignment Correction Authorization

**This document is fully self-contained.** No section below requires
consulting an earlier commit or any other contract to understand this
exception's complete rules — every requirement, authorization, and
constraint is stated here in full.

**Status: authorization only. Drafting and merging this document makes
zero application changes. It does not itself perform the correction it
authorizes — the correction requires its own separate commit, on
PR #128 itself, made only after this document is merged to `main`.**

**This document does NOT extend, reopen, renew, or reinterpret ordinary
Correction Round 1 (PR #128 commit `6b553ea`), ordinary Correction
Round 2 (PR #128 commit `ea473de`), or the exceptional correction
authorized by PR #130 and performed as PR #128 commit `50d1a83`. All
three remain fully consumed. This is a new, independently-scoped
exception addressing a single test-proof gap discovered only by the
final independent review conducted after the PR #130 exceptional
correction's own required verification had already passed in full.**

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m5-test-alignment-correction`, in an
  isolated linked worktree
  (`../rfc-005-m5-test-alignment-correction-worktree`), based on
  `origin/main` at `3f180bfa253787a8b113ad33b3a95e538af2b56c` (the merge
  of the prior exceptional-correction authorization, PR #130).
- Concerns RFC-005 Milestone 5 CONTRACT
  (`docs/automation/RFC-005-M5-CONTRACT.md`), implemented on **PR #128**
  (`agent/rfc-005-m5`), current implementation head
  **`50d1a8394a8ccc476b69d1849eed3b5686a58e22`** (the result of the
  PR #130 exceptional correction).
- This document changes **exactly one file**: this document itself. No
  `app/`, `database/`, `tests/`, `resources/`, `config/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting
  it, and it does not touch PR #128's own branch at all.

---

## 1. Correction budget — exhausted at every prior tier, all consumed

- **`maximum_correction_rounds` = 2** (`docs/automation/AI-AUTONOMY-STATE.json`).
- **Ordinary Correction Round 1** (PR #128 commit `6b553ea`, consumed
  1/2).
- **Ordinary Correction Round 2** (PR #128 commit `ea473de`, consumed
  2/2).
- **First exceptional correction**, authorized by
  `docs/automation/RFC-005-M5-EXCEPTIONAL-CORRECTION-AUTHORIZATION.md`
  (PR #130, merged), consumed 1/1, performed as PR #128 commit
  `50d1a83`: fixed the qualifying-send response-status mapping
  (production defect), completed the entitlement-independence proof's
  previously-unexecuted states, extended excluded-channel coverage to
  all five named channels, and added real HTTP-level §6.1
  token-lifecycle proofs.

**All three remain consumed and are not reopened, extended, or reset by
this document.** This is a new, separately-scoped exception, addressing
a single test-proof gap the final independent review found only after
the PR #130 correction's own required verification (all focused
commands, and a genuinely clean full `tests/Feature/Usage`,
`tests/Feature/Entitlement`, `tests/Feature/Workspace`, all green on
the first attempt) had already passed.

---

## 2. Source-audit-proven gap — test proof only, no production defect

**Confirmed by direct re-reading of
`test_entitlement_decision_is_identical_across_every_wallet_and_meter_state()`
at the current implementation head, `ConversationsPlainSmsMeteringTest.php`:**
the PR #130 correction's own authorizing document (§3.3) states the
required behavior precisely:

> Every one of the six [states] must produce the identical
> `EntitlementDecision` (same `allowed`, same `reason`) given identical
> plan/override entitlement inputs.

The test as actually written establishes a baseline correctly:

```php
$baseline = $decideNow();
$this->assertTrue($baseline->allowed);
$this->assertNull($baseline->reason);
```

but every subsequent state re-decision asserts only:

```php
$this->assertTrue($decideNow()->allowed, '...');
```

`App\Library\Entitlement\EntitlementDecision` declares two independent,
readonly public properties:

```php
public bool $allowed,
public ?string $reason,
```

An `allowed === true` result is not structurally guaranteed to carry
the identical `reason` value across calls — `$decision->reason` is
never inspected again after the baseline assertion, for any of the
eight subsequent states (insufficient balance, suspended wallet,
outstanding debt, `is_metered = false`, `active_rate_id = null`, pilot
meter entirely absent, and both legacy-classification-row states). The
exact same-`reason` regression proof the PR #130 authorization itself
required is therefore still missing.

**This is a test-proof gap only.** No production behavior has been
shown to be incorrect, and this document does not claim one.
`EntitlementManager::decide()` itself is not implicated and is not
authorized to change under this document.

---

## 3. Authorization — one exceptional test-only correction, and only one

This document **explicitly authorizes exactly one new exceptional
correction**, beyond and outside the exhausted ordinary 2/2 budget and
the already-consumed first exceptional correction (PR #130), to
PR #128 (`agent/rfc-005-m5`), current head `50d1a83`, to be performed
**only after this document is merged to `main`**, as a human decision.

### 3.1 Path scope — exactly one file, test-only

The correction's implementation scope is **exactly one path**, already
inside the existing 18-path allowlist:

1. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`

**No other path may be touched under this exception's authority —
no other test file, no production file (including, explicitly,
`app/Repositories/Eloquent/EloquentCampaignRepository.php` and
`app/Library/Entitlement/EntitlementManager.php`), no `config/`,
`resources/`, `database/`, or `docs/automation/` path, and no 19th
path.**

### 3.2 Required resulting behavior

Inside `test_entitlement_decision_is_identical_across_every_wallet_and_meter_state()`,
every one of the eight non-baseline re-decisions currently already
present in the test — insufficient balance, suspended wallet,
outstanding debt, pilot meter `is_metered = false`, pilot meter
`active_rate_id = null`, pilot meter entirely absent, legacy
classification row `is_metered = false`, legacy classification row
`is_metered = true` — must assert **both**:

```php
$this->assertSame($baseline->allowed, $decision->allowed, '...');
$this->assertSame($baseline->reason, $decision->reason, '...');
```

for the identical `EntitlementDecision` object each `$decideNow()` call
already produces at each point in the test. A small local helper or
assertion closure wrapping both comparisons (e.g.
`$assertIdenticalToBaseline(fn () => $decideNow(), 'label')`) is
explicitly permitted if it keeps the test readable — the requirement is
the two comparisons occurring for every state, not any particular
syntactic form.

**No new state needs to be added.** The eight states the PR #130
correction already executes are the complete set §3.3 of that
authorization required; this document only strengthens the assertion
made against each already-executed state's own decision. **No change
to the test's own fixture setup, wallet/meter mutation sequence, or
state coverage is authorized beyond what is needed to add the missing
`reason` comparison** — this is not a license to restructure the test.

### 3.3 Constraints

- **`EntitlementManager.php` may not be modified.**
- **No application/production behavior may change** as a result of
  this correction — it is a test-file-only strengthening.
- **`AI-AUTONOMY-STATE.json` may not be modified.**
- No governance document may be added to or modified on PR #128 itself.

---

## 4. Required verification after the correction

Locked order, on PR #128, after applying §3.2 on top of the current
head `50d1a83`, before any commit:

1. **Local environment preparation** (mirroring the already-established
   precedent for this repository): `vendor/`, `.env.testing`, and
   `node_modules/` present (`npm ci` if `node_modules/` is absent);
   `php artisan package:discover --ansi` if bootstrap cache regeneration
   is needed; local-only `npm install webpack@5.76.0 --no-save
   --no-package-lock` followed by `npm run production`. **No change to
   `package.json` or `package-lock.json` under any circumstance.**
2. **`php artisan migrate:fresh --env=testing -vvv`** — must pass, on
   `ultimatesms_testing` only.
3. **Every currently-required command in
   `docs/automation/AI-AUTONOMY-STATE.json`**, each reporting a genuine
   positive test count with **zero failures**:
   - `php artisan test tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`
   - `php artisan test tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`
   - `php artisan test tests/Feature/Usage/ActivateConversationsUsageRateCommandTest.php`
   - `php artisan test tests/Feature/Usage/ResolveAmbiguousUsageReservationCommandTest.php`
   - `php artisan test tests/Feature/Usage/ConversationsConcurrencyTest.php`
   - `php artisan test tests/Feature/Usage` (full)
   - `php artisan test tests/Feature/Entitlement` (full)
   - `php artisan test tests/Feature/Workspace` (full)

   A genuinely clean run of the full `tests/Feature/Usage` suite is
   required, matching the precedent already established across both
   prior correction rounds.
4. **`git diff --check`** — must be clean.
5. **Discard all generated output** before commit: restore
   `bootstrap/cache/packages.php`, `bootstrap/cache/services.php`, and
   the entire `public/**` build/cache diff to their exact HEAD content
   (`git restore --source=HEAD`); remove any newly-generated untracked
   build paths.
6. **Confirm `package.json` and `package-lock.json` are byte-identical
   to HEAD** after the environment preparation in step 1.
7. **The cumulative diff of PR #128 against `origin/main` must equal
   exactly the original eighteen-path allowlist** already locked by
   `docs/automation/AI-AUTONOMY-STATE.json` — no more, no fewer, and the
   correction commit itself must change exactly the one file in §3.1.

---

## 5. No further correction under this exception

This exception authorizes **exactly one** implementation correction
pass, scoped to the single file in §3.1 and the behavior in §3.2. It is
not renewable, does not reset or extend the exhausted ordinary 2/2
budget or the already-consumed first exceptional correction, and does
not authorize a second attempt if this one is incomplete.

**If, during or after this correction, any required command in §4
still fails, or another genuine defect is found** — whether a new
issue or one this document's own audit missed — **PR #128 must stop
and be reported for a new, separate human decision. It must not be
modified again under this document's authority.** A further defect
requires its own new exception document (or other explicit human
authorization).

---

## 6. Stop conditions

The correction must stop, leave the working tree unstaged beyond what
is already committed, and report rather than proceed, if:

- Any path beyond the single one named in §3.1 is found necessary.
- `EntitlementManager.php`, or any production/config/resource/migration
  path, is found to require a change.
- `AI-AUTONOMY-STATE.json` is found to require any change.
- Any required command in §4 still fails after the correction, for the
  same or a different reason.
- A genuine production defect (as opposed to a test-proof gap) is
  discovered — that requires its own new authorization, not this one.

---

## 7. PR #128 status

**PR #128 remains Draft.** It must not be merged until:

1. This document is itself merged to `main`, and
2. The single authorized correction (§3) is performed on PR #128, and
3. All required verification (§4) passes, with exact counts and a
   genuinely clean `tests/Feature/Usage` run reported.

No merge decision is authorized by this document. Merging PR #128
remains a separate, explicit, future human decision. Human-only merge
applies to both this document and PR #128. Codex review and the
automatic AI Subscription Loop remain disabled — manual Claude Code
work, human-reviewed, with no automatic follow-up assumed or planned.

---

## 8. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path, this document,
   nothing else, nothing staged.
2. Stage the one file by its exact path only, never `git add -A`/`.`.
3. Commit exactly:
   `docs: authorize RFC-005 Milestone 5 test-alignment correction`.
4. Push normally to
   `origin chore/rfc-005-m5-test-alignment-correction`. No force push.
   Do not push `main`.
5. Open a Draft PR into `main` if tooling permits; otherwise report the
   exact GitHub comparison URL.
6. **Do not merge. Do not begin the correction this document
   authorizes.** Both require this document to be merged first, and the
   correction itself remains its own separate, explicit action against
   PR #128, not performed as part of this commit.
7. PHP/JS tests are not required for this one-file docs-only change and
   are not run — reported honestly as not run, no count fabricated.

---

*End of RFC-005 Milestone 5 Post-Verification Entitlement-Reason
Test-Alignment Correction Authorization. This document authorizes
exactly one exceptional test-only correction to PR #128, strictly
scoped to the single file named in §3.1, based on the test-proof gap
recorded in §2, with no further correction permitted under its own
authority (§5). PR #128 remains Draft until the correction and its
verification are complete (§7).*
