# RFC-005 Webhook Payload Retention — Test Alignment Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a narrowly bounded, documentation-only test-alignment correction discovered during manual implementation verification of `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md` (Remediation 10, PR [#165](https://github.com/os-creator1/os-ai/pull/165)). This is **not** a correction to that already-merged contract — it is not modified by this document — and it is **not** a new remediation against production behavior. It is a single, pre-existing test's own stale setup, exposed only once Remediation 10's product fix made the test's implicit dependency on the fixed defect visible.

Human merge of this contract does **not** itself resume implementation — a human must separately, explicitly instruct that implementation resume on the existing branch named in §0.

This is **contract-authoring only**. No product code, test code, config, schema, route, or view file is touched by this branch. `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md` is not reopened, contradicted, or reinterpreted. **RFC-005 Milestone 6's own release-readiness PR (#164) remains frozen** — not merged, closed, edited, tagged, or deployed by this contract or by anything it authorizes.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-webhook-payload-retention-test-alignment-correction-contract`, in an isolated linked worktree (`../rfc-005-webhook-payload-retention-test-alignment-correction-contract-worktree`), based on `origin/main` at `e658ab2c52fde809b757da7d7df829079eee6183` — the Remediation 10 contract's own merge commit (PR #165), confirmed via `git fetch origin && git rev-parse origin/main` at the start of this pass. `origin/main` has not moved since Remediation 10's contract merged.
- **This new contract-authoring branch is fully isolated from the existing implementation branch and worktree** (`agent/rfc-005-webhook-payload-retention-correction`, `../agent-rfc-005-webhook-payload-retention-correction-worktree`). That worktree's two current uncommitted, in-progress changes (§4 below) are left completely untouched by this pass — not read-modified, not committed, not reset, not stashed.
- **Existing implementation branch (unchanged, resumed only after this contract's human merge and a further, separate, explicit human instruction): `agent/rfc-005-webhook-payload-retention-correction`.** This contract does not create a new implementation branch — it extends the authorized scope of the existing one.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - **PR #164 (M6 release-readiness) remains frozen** — not merged, not closed, not edited, no tag created or moved.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch.
  - **Resuming implementation requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-TEST-ALIGNMENT-CORRECTION-CONTRACT.md`. It does **not** modify `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md` in any way.
- **Sequence position:** this is a narrow scope-extension of Remediation 10, discovered during that same remediation's own manual verification pass, before its implementation was committed or pushed. It does not introduce a new, independently numbered remediation, and does not reopen or supersede Remediation 10's own contract or design (§2 there, unchanged).

---

## 1. Discovery — mechanically established during Remediation 10 verification

Remediation 10's own two-file implementation (`app/Jobs/Usage/PurgeExpiredWebhookPayloads.php`, `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php`) was implemented exactly per its contract, independently verified twice against the exact base commit (`e658ab2`), and confirmed correct:

- Focused test (`WebhookEventDispositionAndPurgeTest.php`): **8 passed (27 assertions)** — 4 pre-existing methods unchanged, 4 new methods added exactly as contracted.
- Test-quality proof, independently re-run twice against the pre-fix job restored from `git show e658ab2:app/Jobs/Usage/PurgeExpiredWebhookPayloads.php`: **4 failed, 4 passed (19 assertions)** — exactly the 4 new methods fail, each on a genuine "payload was wrongly purged" assertion (`Failed asserting that '...' is null` at the new tests' own `assertNull($event->payload_purged_at)`/`assertNotNull($event->payload_encrypted)` lines), the original 4 methods unaffected. This confirms the new tests exercise the real defect, not an implementation detail.
- Full Usage domain regression (`tests/Feature/Usage tests/Unit/Usage`), run against the corrected implementation: **1 failed, 922 passed (4140 assertions)**.

**The sole failure, independently reproduced twice:**

```
FAILED  Tests\Feature\Usage\PaymentProviderEventDurableAuditTest > normalized attribution survives payload purge
Failed asserting that '{"data":{"object":{"id":"ch_fake_a4","payment_intent":null,"amount_refunded":200,"currency":"usd"}}}' is null.
at tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php:207
```

**Confirmed by direct read of `tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php` (lines 195–210, unchanged since this file's own creation under RFC-005 Remediation 8 — "Remediation #6" in that remediation's own self-declared numbering, per its file docblock):**

```php
public function test_normalized_attribution_survives_payload_purge(): void
{
    [$customer, $business] = $this->businessWithProviderCustomer();
    $this->topUpAttempt($business, $customer->user_id, 5_000_000, 'ch_fake_a4');
    $fresh = $this->process($this->refundEvent('ch_fake_a4', 2_000_000));

    DB::table('payment_provider_events')->where('id', $fresh->id)->update([
        'completed_at' => now()->subDays(400),
    ]);
    app(PurgeExpiredWebhookPayloads::class)->handle(app(PaymentProviderEventRepository::class));

    $afterPurge = PaymentProviderEvent::find($fresh->id);
    $this->assertNull($afterPurge->payload_encrypted);
    $this->assertSame('refund_applied', $afterPurge->normalized_outcome);
    $this->assertSame(2_000_000, (int) $afterPurge->normalized_outcome_delta_micro);
}
```

**Why this is stale test setup, not a product regression.** This test's own actual, documented intent (per its class docblock, lines 32–37: *"...survival across payload purge..."*) is to prove that `normalized_outcome`/`normalized_outcome_delta_micro`/`normalized_status`/`normalized_reason` (the durable audit-attribution fields Remediation 8 added) remain intact after `payload_encrypted` is purged — nothing about *how* the purge is triggered is part of what this test is actually verifying. Before Remediation 10, calling `PurgeExpiredWebhookPayloads::handle()` with `usage_billing.webhook_event.retention_days` left unset was, by accident, a convenient way to force an unconditional purge: `(int) null === 0` made `purgeable(0)`'s cutoff resolve to `now()`, so any already-`completed_at`-backdated row (this test sets `completed_at` to 400 days in the past at line 202) was always purged, regardless of any retention window. This test never *intended* to exercise "what happens when retention is unset" — it simply never needed to set a retention value, because the pre-Remediation-10 bug made any retention value (including none) behave as "purge everything already completed." **Remediation 10's own fix (`docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md` §1/§2) correctly closes exactly this behavior** — unset retention now correctly disables purging — which means this test's own incidental reliance on the bug now needs one explicit line to keep proving what it always meant to prove.

**Confirmed this is the only place in the entire repository with this dependency.** A full grep for every call site of `PurgeExpiredWebhookPayloads` across `app/` and `tests/` (re-run this pass, independent of Remediation 10's own equivalent grep) found exactly three: `app/Console/Kernel.php`'s own scheduling (unaffected — no test), `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php`'s 6 in-file call sites (all already correctly set an explicit `retention_days` value, per Remediation 10's own contract §2/§3), and this single call site in `PaymentProviderEventDurableAuditTest.php`. No other test anywhere in the repository invokes this job.

---

## 2. Design — the narrowest correction that satisfies the original test's own intent

**Locked design: one line, inside one existing method, in one file.** Immediately before the existing `app(PurgeExpiredWebhookPayloads::class)->handle(...)` call (line 204), add:

```php
config(['usage_billing.webhook_event.retention_days' => 30]);
```

This is the **identical pattern** the two pre-existing, already-correct purge tests in `WebhookEventDispositionAndPurgeTest.php` already use (`test_disposed_past_retention_payload_is_purged_while_audit_metadata_survives`, `test_a_merely_exhausted_undispositioned_event_is_never_purged`, both setting `config(['usage_billing.webhook_event.retention_days' => 30])` before their own call to the same job) — not a new convention invented for this correction. `30` is chosen for the identical reason those two tests chose it: the fixture's own `completed_at` is backdated 400 days (line 202), so any retention value well under 400 unambiguously exercises the purge-eligible path; `30` is simply consistency with the sibling tests' own already-established value, not a new commercial or configuration decision.

**No other line of this test, or of any other test in this file, is touched.** The three existing assertions (lines 207–209) remain exactly as written — once retention is explicitly configured, the purge occurs exactly as this test originally, correctly expected, and every downstream assertion (`normalized_outcome`, `normalized_outcome_delta_micro` surviving the purge) is unaffected and continues to prove exactly what it always proved.

**Why no other file or design is authorized.** This is documentation-only test alignment, not a redesign:

- No change to `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` beyond what Remediation 10 already authorizes and has already implemented (§4 below) — this correction adds no new production behavior of any kind.
- No change to `config/usage_billing.php` — `retention_days`'s own `null`-by-default shape remains exactly as Remediation 10's own contract already established as correct and intentional.
- No change to any other method in `PaymentProviderEventDurableAuditTest.php` — a full read of the file (confirmed this pass) shows no other test method calls `PurgeExpiredWebhookPayloads` at all; only this one method is affected.
- No change to `WebhookEventDispositionAndPurgeTest.php` beyond what Remediation 10 already authorizes and has already implemented — that file's own 8 methods are unaffected by this discovery.

---

## 3. Exact production allow-list

**No production path is authorized or required by this contract beyond what Remediation 10 already authorizes.** `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` remains on Remediation 10's own allow-list, unchanged by anything in this document — this contract adds no new production behavior.

## 4. Exact test allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php` | Already authorized by Remediation 10 (unchanged by this contract) | Existing, in-progress, uncommitted change in `../agent-rfc-005-webhook-payload-retention-correction-worktree`: 4 pre-existing methods unmodified, 4 new methods added exactly per Remediation 10 §3. Not touched by this contract. |
| 2 | `tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php` | **NEWLY AUTHORIZED by this contract** | Exactly one new line (§2) inside `test_normalized_attribution_survives_payload_purge()`, immediately before the existing `PurgeExpiredWebhookPayloads::handle()` call. No other line of this file is touched. |

**Exactly 2 test paths, once this contract is merged and implementation resumes — 1 already authorized by Remediation 10, 1 newly authorized here.**

**Final implementation scope, once this contract's own correction is applied on top of Remediation 10's already-drafted, already-verified two-file change:**

1. `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` (Remediation 10, already implemented in the working tree, unchanged by this contract)
2. `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php` (Remediation 10, already implemented in the working tree, unchanged by this contract)
3. `tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php` (this contract, one line, not yet applied)

---

## 5. Resumption procedure — locked, for the record

This contract does not perform implementation. Once merged and implementation is separately, explicitly resumed on `agent/rfc-005-webhook-payload-retention-correction`:

1. The existing, already-verified changes to `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` and `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php` remain exactly as already implemented — no re-verification of their own content is required, only re-confirmation they are still present and unmodified in the working tree.
2. Apply exactly the one-line change in §2 to `tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php`.
3. Re-run `php artisan test tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php` — the specific file, to confirm the corrected test passes in isolation.
4. Re-run the full previously-required regression sequence from scratch (Remediation 10 contract §6): the focused `WebhookEventDispositionAndPurgeTest.php` file; the complete `tests/Feature/Usage tests/Unit/Usage` domain; the repository's own documented six-command regression gate (`tests/Unit/Usage tests/Feature/Usage`; `tests/Unit/Entitlement tests/Feature/Entitlement`; `tests/Unit/Workspace tests/Feature/Workspace`; `tests/Unit/Business tests/Feature/Business`; `tests/Unit/Opportunity tests/Feature/Opportunity`; `php artisan test --stop-on-failure`) — every command must report zero failures before proceeding.
5. Mechanically verify the final diff against `origin/main` contains **exactly** the 3 paths named in §4 — no more, no fewer.
6. Commit, push (no force push, never to `main`, never to `agent/rfc-005-m6`), and report exact evidence, exactly as Remediation 10's own contract §6/§9 already requires.
7. No automatic advancement to PR #164, tagging, or any next remediation.

---

## 6. Excluded scopes — restated

This correction does not implement, design, or absorb any of the following:

- Any change to `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md` — not reopened, not modified, not reinterpreted.
- Any change to `config/usage_billing.php`, `EloquentPaymentProviderEventRepository`, the `PaymentProviderEventRepository` interface, any migration/schema, any route/controller/view.
- Any change to `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` beyond what Remediation 10 already authorizes.
- Any assertion, fixture, or method in `PaymentProviderEventDurableAuditTest.php` other than the single new `config()` line in the single named method.
- Any merge, close, edit, tag, or deployment action against PR #164.
- Any RFC-005 §39 open commercial/product decision, the tax/VAT item, or Conversations pilot activation.
- Milestone 6's own conformance/tag governance process.
- Starting or resuming implementation without a further, separate, explicit human instruction.

---

## 7. Confirmations

- **No schema/migration/config change is required or authorized by this correction.**
- `AI-AUTONOMY-STATE.json` is untouched by this branch.
- **PR #164 remains frozen.**
- The existing implementation worktree (`../agent-rfc-005-webhook-payload-retention-correction-worktree`) and its two current uncommitted changes are completely untouched by this contract-authoring pass — not read-modified, not committed, not reset, not stashed, not discarded.
- No product, test, config, or route file is touched by this branch. This governance branch changes exactly one file.
- No implementation has occurred under this contract. This is its first draft: `0 of 2` ordinary correction rounds consumed.
- `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md` is not reopened, contradicted, or reinterpreted anywhere above — its own design (§2, the guarded `resolvedRetentionDays()` correction) remains exactly as authorized and already implemented.

---

## 8. Open items

**No open item blocks authorizing this correction's own bounded scope** (§4's one-line, one-file addition).

**Resuming implementation is not authorized by this contract's own merge alone** (§0, §5) — it requires a further, separate, explicit human instruction, exactly matching Remediation 10's own contract's authorization model.
