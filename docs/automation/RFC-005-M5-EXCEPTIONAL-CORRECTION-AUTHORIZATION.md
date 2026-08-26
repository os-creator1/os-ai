# RFC-005 Milestone 5 — Response-Mapping, Entitlement-Proof, and Channel-Coverage Exceptional Correction Authorization

**This document is fully self-contained.** No section below requires
consulting an earlier commit or any other contract to understand this
exception's complete rules — every requirement, authorization, and
constraint is stated here in full.

**Status: authorization only. Drafting and merging this document makes
zero application changes. It does not itself perform the correction it
authorizes — the correction requires its own separate commit(s), on
PR #128 itself, made only after this document is merged to `main`.**

**This document does NOT reopen, extend, reinterpret, or reset ordinary
Correction Round 1 (PR #128 commit `6b553ea`) or ordinary Correction
Round 2 (PR #128 commit `ea473de`). Both are fully consumed and remain
so. This is a new, independently-scoped exception addressing defects
discovered only by the final independent review conducted after
Correction Round 2's own required verification had already passed.**

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m5-exceptional-correction`, in an
  isolated linked worktree
  (`../rfc-005-m5-exceptional-correction-worktree`), based on
  `origin/main` at `b6f20313ae9a22f568b93edcec8327a05deb52a6` (the merge
  of the M5 implementation-authorization state pin, PR #129).
- Concerns RFC-005 Milestone 5 CONTRACT
  (`docs/automation/RFC-005-M5-CONTRACT.md`), implemented on **PR #128**
  (`agent/rfc-005-m5`), current implementation head
  **`ea473dedd4a81d094a54092c2aff900284fba02f`** (the result of ordinary
  Correction Round 2).
- This document changes **exactly one file**: this document itself. No
  `app/`, `database/`, `tests/`, `resources/`, `config/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting
  it, and it does not touch PR #128's own branch at all.

---

## 1. Correction budget — exhausted, both ordinary rounds consumed

- **`maximum_correction_rounds` = 2** (`docs/automation/AI-AUTONOMY-STATE.json`).
- **Ordinary Correction Round 1** (PR #128 commit `6b553ea`, consumed
  1/2): corrected the Twilio/TwilioCopilot outcome vocabulary,
  business-namespaced the idempotency key, fixed the step-0/legacy
  ordering, added the required `EntitlementManager::decide()` call,
  fixed the null-primaryBusiness fail-closed handling, corrected the
  `reserve()` race-catch transaction boundary, and rewrote the
  resolution command to its locked spec.
- **Ordinary Correction Round 2** (PR #128 commit `ea473de`, consumed
  2/2): replaced reflection/source-inspection-only test proofs with
  direct execution of the real, unmodified production `quickSend()`
  method for every money-critical path, and upgraded the concurrency
  proof to invoke the real production decision rather than a
  restatement of it. **Zero production code was changed in Round 2.**

**Both ordinary rounds remain consumed and are not reopened, extended,
or reset by this document.** This is a new, separately-scoped exception,
addressing defects the final independent review found only after
Round 2's own required verification (full `tests/Feature/Usage`,
`tests/Feature/Entitlement`, `tests/Feature/Workspace`, all green) had
already passed.

---

## 2. Source-audit-proven defects

Each defect below was independently re-verified by direct reading of
`app/Repositories/Eloquent/EloquentCampaignRepository.php` and
`tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` at the
current implementation head before this document was drafted — not
accepted on the strength of the review alone.

### 2.1 Defect 1 — the qualifying-send HTTP/JSON response status is wrong for two of the three settlement outcomes

**Confirmed by direct source reading, `EloquentCampaignRepository.php`,
current `quickSend()`:** after `settleConversationsMeterReservation()`
correctly settles the reservation (`accepted` → `commit()`,
`definitive_rejection` → `release()`, `ambiguous_exception`/absent →
leave `Pending`), the response returned to the caller is still built by
the same, unmodified legacy branch:

```php
if (substr_count($data->status, 'Delivered') == 1) {
    // ... success branch: 'status' => 'success'
} else {
    // ... 'status' => 'info', regardless of which M5 outcome occurred
}
```

`$m5TokenAction` is correctly appended to either branch, but the
`status` string itself is never adjusted for the M5 outcome. The result,
confirmed by inspection (and confirmed absent from Round 2's own test
assertions — `test_real_quicksend_definitive_rejection_releases_and_does_not_charge`
never asserts `$payload->status` at all, so this defect passed
unnoticed):

| Settlement outcome | Current response | Contract-locked response |
|---|---|---|
| `accepted` | `success` + `clear` | `success` + `clear` (already correct) |
| `definitive_rejection` | `info` + `clear` | **`error`** + `clear` |
| `ambiguous_exception` | `info` + `retain` | **`processing`** + `retain` |
| absent/unrecognized marker | `info` + `retain` | **`processing`** + `retain` |

This is a genuine product-correctness defect in the response the
ChatBox UI receives, not merely a test gap — the accounting/settlement
side (reservation, ledger, wallet) is already correct and is not part
of this defect.

### 2.2 Defect 2 — the entitlement/meter-independence proof does not execute the meter-absent case

**Confirmed by direct reading of
`test_entitlement_decision_is_identical_across_every_wallet_and_meter_state()`**
(added in Round 2): it exercises healthy wallet, zero balance, suspended
billing status, outstanding debt, `is_metered = false`, and
`active_rate_id = null` — six real states — but for the seventh
(pilot `usage_meters` row entirely absent), the test contains only a
comment asserting the result is "mechanically guaranteed" from reading
`EntitlementManager::decide()`'s own source, and does not execute it.
It also does not exercise the legacy
`platform_feature_usage_classifications.conversations.is_metered`
column's two states. The merged contract's own §13 requires an executed
proof, not a sourced claim, for every listed state.

### 2.3 Defect 3 — non-plain/unicode channel-exclusion coverage is incomplete

**Confirmed by direct reading of
`QuickSendNonConversationCallersUnaffectedTest.php`:** exactly one
non-plain/unicode channel (`whatsapp`) is behaviorally proven to stay on
legacy billing regardless of M5 metering state. The merged contract's
§5.2 names five such channels — MMS, WhatsApp, Viber, OTP, Voice — and
§13 requires the exclusion proven for the channels the contract names,
not for one representative example.

### 2.4 Token lifecycle — reported gap, not a newly-found defect

Round 2's own report already disclosed, honestly, that dedicated
HTTP-level `new()`/`sent()`/`reply()` token-lifecycle behavioral tests
were not completed; this is not a new discovery. It is folded into this
authorization's scope (§3.4) rather than issued as a separate document,
since it targets the same already-authorized test files as Defects 2
and 3.

---

## 3. Authorization — one exceptional implementation correction, and only one

This document **explicitly authorizes exactly one new exceptional
implementation correction**, beyond and outside the exhausted ordinary
2/2 budget, to PR #128 (`agent/rfc-005-m5`), current head `ea473de`, to
be performed **only after this document is merged to `main`**, as a
human decision.

### 3.1 Path scope — narrowed subset of the existing 18-path allowlist only

The correction's implementation scope remains **inside the existing
18-path allowlist** already locked by
`docs/automation/AI-AUTONOMY-STATE.json`. The expected change is limited
to the following narrowed subset — **no other of the 18 paths may be
touched under this exception's authority, and no 19th path may be
added**:

1. `app/Repositories/Eloquent/EloquentCampaignRepository.php` — Defect 1
   only (§3.2).
2. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` — Defect 1
   proof update, Defect 2 completion (§3.3).
3. `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`
   — Defect 3 completion (§3.4).

If, during the correction, the token-lifecycle verification in §3.4
proves genuinely impossible inside these three paths (or another of the
already-existing 18, if one is a closer fit — no new file), the
correction must stop and report exactly which additional already-listed
path it needs and why, rather than silently expand into it unreported.
**Under no circumstance may a path outside the original eighteen be
added.**

### 3.2 Required resulting behavior — Defect 1 (production)

In `EloquentCampaignRepository.php`, the qualifying-send response
builder must select `status` from the settlement outcome, not from the
legacy `Delivered` substring check, whenever `$m5TokenAction !== null`
(i.e. only for a qualifying M5 send that reached settlement):

| `$m5TokenAction` from `settleConversationsMeterReservation()` | Required `status` |
|---|---|
| `clear` following a commit (`accepted`) | `success` |
| `clear` following a release (`definitive_rejection`) | `error` |
| `retain` (`ambiguous_exception` or absent marker) | `processing` |

**Constraints:**
- **Legacy (non-M5) response semantics are completely unchanged** for
  every call where `$m5TokenAction === null` — the existing
  `Delivered`-substring branch continues to govern `status` exactly as
  today for every non-qualifying and non-ChatBox-origin call.
- **No change to `SendCampaignSMS.php`'s outcome-classification marker
  vocabulary** (`accepted`/`definitive_rejection`/`ambiguous_exception`)
  unless a fresh audit performed during the correction itself finds a
  genuine, separate defect there — the current vocabulary is already
  correct and is not itself part of this authorization's target.
- **No provider-abstraction redesign, no change to how or when
  `sendPlainSMS()` is invoked.**
- The minimum correction is to the response-status selection alone —
  reservation, ledger, and wallet settlement logic (already correct) is
  not to be touched.

### 3.3 Required resulting behavior — Defect 2 (test-only)

Inside `ConversationsPlainSmsMeteringTest.php`, extend (or add
alongside) `test_entitlement_decision_is_identical_across_every_wallet_and_meter_state()`
so it actually executes, with real `EntitlementManager::decide()` calls
and otherwise identical plan/override entitlement inputs:

1. Pilot meter exists, `is_metered = true`, active rate present
   (already covered — the baseline case).
2. Pilot meter exists, `is_metered = false`.
3. Pilot meter exists, `active_rate_id = null`.
4. Pilot meter row **entirely absent** (deleted or never created) for
   this Business — this is the case Round 2 declined to execute; it
   must now actually run, not merely be asserted safe from source.
5. Legacy `platform_feature_usage_classifications` row for
   `conversations`, `is_metered = false`.
6. Same legacy row, `is_metered = true`.

Every one of the six must produce the identical `EntitlementDecision`
(same `allowed`, same `reason`) given identical plan/override inputs.
**`EntitlementManager` itself may not be modified.**

### 3.4 Required resulting behavior — Defect 3 and token lifecycle (test-only)

Inside `QuickSendNonConversationCallersUnaffectedTest.php` (or, for the
token-lifecycle sub-items only, whichever already-authorized test file
most naturally hosts an HTTP-level assertion — no new file):

1. For each of MMS, Viber, OTP, and Voice (WhatsApp already proven in
   Round 2), a trusted ChatBox/Conversation-context call with that
   `sms_type`, using a provider double as needed, must prove: zero
   RFC-005 reservation, zero RFC-005 ledger charge, and unchanged legacy
   `sms_unit`/provider-dispatch behavior. This is **regression proof
   only** — it does not and must not expand M5 metering to any of these
   channels.
2. Where practical inside the existing 18-path scope, verify the §6.1
   token lifecycle:
   - Two independent `new()` loads mint two different UUIDs.
   - A retained `sent()` retry preserves the identical token and
     restores `sending_server`/`country_code`/`sender_id`/`recipient`/
     `message` via the existing `withInput()`/`old()` wiring.
   - `reply()` with a missing token returns HTTP 422 and never reaches
     `quickSend()`/the provider.
   - `reply()` with an invalid (non-UUID) token returns HTTP 422 and
     never reaches `quickSend()`/the provider.
   - `reply()` with a valid token passes `conversationContext = true`
     through to `quickSend()`.
   - Per-conversation reply-token retain/clear behavior for one
     conversation does not affect another conversation's own token
     state.

   If genuinely impossible to verify inside the existing 18-path scope
   without a new file or a change to a path outside §3.1, the
   correction must report exactly that — including which specific
   sub-item is blocked and why — rather than silently skip it or expand
   scope.

---

## 4. Required verification after the correction

Locked order, on PR #128, after applying §3.2–§3.4 on top of the
current head `ea473de`, before any commit:

1. **Local environment preparation** (mirroring the already-established
   precedent for this repository): `vendor/`, `.env.testing`, and
   `node_modules/` present (`npm ci` if `node_modules/` is absent);
   `php artisan package:discover --ansi` if bootstrap cache regeneration
   is needed; local-only `npm install webpack@5.76.0 --no-save
   --no-package-lock` (the repository's own known,
   already-authorized-elsewhere `package-lock.json`/`laravel-mix`
   incompatibility workaround) followed by `npm run production`. **No
   change to `package.json` or `package-lock.json` under any
   circumstance.**
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

   **A genuinely clean run of the full `tests/Feature/Usage` suite is
   required**, matching the Round 2 precedent exactly. The
   already-documented, pre-existing, unrelated OS-process timing
   flakiness in this repository's own legacy concurrency tests (never
   in any of the 18 M5 paths) may be reported honestly if encountered,
   but the required command must still be re-run until a genuinely
   clean result is obtained before advancing — a flaky failure is not
   an acceptable substitute for a clean pass.
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
   `docs/automation/AI-AUTONOMY-STATE.json` — no more, no fewer, and no
   governance file (`AI-AUTONOMY-STATE.json` itself, or any
   `docs/automation/*.md` contract/authorization document) may appear
   in that diff.
8. Direct source verification, before commit, that the response-status
   mapping in §3.2 is implemented exactly as the table states — not
   merely that the tests pass.

---

## 5. No further correction under this exception

This exception authorizes **exactly one** implementation correction
pass, scoped to the three files in §3.1 and the behaviors in §3.2–§3.4.
It is not renewable, does not reset or extend the exhausted ordinary
2/2 budget, and does not authorize a second attempt if this one is
incomplete.

**If, during or after this correction, any required command in §4
still fails, a required path proves insufficient, or another genuine
defect is found** — whether a new issue or one this document's own
audit missed — **PR #128 must stop and be reported for a new, separate
human decision. It must not be modified again under this document's
authority.** A further defect requires its own new exception document
(or other explicit human authorization).

---

## 6. Stop conditions

The correction must stop, leave the working tree unstaged beyond what
is already committed, and report rather than proceed, if:

- Any path beyond the three named in §3.1 is found necessary (except
  the single already-existing-18-path substitution explicitly permitted
  in §3.4 for the token-lifecycle verification, if needed — never a
  19th path).
- A migration, schema change, or route change outside the existing
  allowlist is found necessary.
- The correction would require a provider-abstraction redesign, an
  entitlement-architecture change, a multi-Business attribution
  feature, or a global rewrite of legacy `sms_unit` billing.
- `EntitlementManager.php` or `SendCampaignSMS.php`'s outcome-marker
  vocabulary is found to require a change.
- `AI-AUTONOMY-STATE.json` is found to require any change.
- Any required command in §4 still fails after the correction, for the
  same or a different reason.
- The §3.4 token-lifecycle verification cannot be completed inside the
  scope this document authorizes.

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
   `docs: authorize RFC-005 Milestone 5 exceptional correction`.
4. Push normally to `origin chore/rfc-005-m5-exceptional-correction`.
   No force push. Do not push `main`.
5. Open a Draft PR into `main` if tooling permits; otherwise report the
   exact GitHub comparison URL.
6. **Do not merge. Do not begin the correction this document
   authorizes.** Both require this document to be merged first, and the
   correction itself remains its own separate, explicit action against
   PR #128, not performed as part of this commit.
7. PHP/JS tests are not required for this one-file docs-only change and
   are not run — reported honestly as not run, no count fabricated.

---

*End of RFC-005 Milestone 5 Response-Mapping, Entitlement-Proof, and
Channel-Coverage Exceptional Correction Authorization. This document
authorizes exactly one exceptional implementation correction to
PR #128, strictly scoped per §3, based on the source-audit-proven
defects recorded in §2, with no further correction permitted under its
own authority (§5). PR #128 remains Draft until the correction and its
verification are complete (§7).*
