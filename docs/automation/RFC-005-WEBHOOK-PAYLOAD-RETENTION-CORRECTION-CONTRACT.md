# RFC-005 Webhook Payload Retention Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction that closes a genuine production defect in `PurgeExpiredWebhookPayloads` — leaving `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` unset (its own documented, intentional, fail-closed default) does not disable payload purging as `config/usage_billing.php`'s own docblock and the RFC-005 M6 deployment guide both claim; it silently makes nearly every terminal webhook payload eligible for purge on the very next hourly scheduler run. This defect was independently found by a Codex review of the draft RFC-005 Milestone 6 release-readiness PR ([#164](https://github.com/os-creator1/os-ai/pull/164)) and is independently re-verified from scratch in this contract against current `origin/main`, invoking `docs/automation/RFC-005-M6-CONTRACT.md`'s own gap rule (§3): *"If the read-only conformance audit (§4) discovers that an RFC-005 acceptance criterion, an inherited RFC-001/002/003/004 invariant, or a required test class is actually missing, incorrectly implemented, exhibits schema drift, or lacks the coverage the RFC requires: **STOP**... A human-reviewed amendment to this contract, or a new separately bounded remediation contract (**Remediation 10**, following the same discipline as the nine already closed), is required before any such code/test correction."* This is that Remediation 10.

Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch named in §0, exactly as every prior RFC-005 remediation contract has required.

This is **contract-authoring only**. No product code, test code, schema, route, or config file is touched by this branch. RFC-005 Milestones 1–5, Amendment 1, and all nine prior post-M5 remediations are closed and are not reopened, contradicted, or reinterpreted by anything below. **RFC-005 Milestone 6's own release-readiness PR (#164) is not merged, closed, edited, tagged, or deployed by this contract or by anything it authorizes** — it remains frozen exactly where it is until this remediation is separately contracted, authorized, implemented, and merged (§0, §9).

---

## 0. Governance

- Drafted on branch `chore/rfc-005-webhook-payload-retention-correction-contract`, in an isolated linked worktree (`../rfc-005-webhook-payload-retention-correction-contract-worktree`), based on `origin/main` at `70513f854ff607687b95a957115e24b922bcfaad` — the RFC-005 M6 contract refresh's own merge commit (PR [#163](https://github.com/os-creator1/os-ai/pull/163)), confirmed via `git fetch origin && git rev-parse origin/main` at the start of this contract. This is the same base PR #164 itself branched from; `origin/main` has not moved since.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-webhook-payload-retention-correction`.**
- Confirmed at drafting: no such branch exists on `origin`; `git status --short` in this worktree is empty; no product/test/config/route file has been touched by this branch at any point.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - **PR #164 (M6 release-readiness) remains frozen** — not merged, not closed, not edited, no tag created or moved, no live Stripe/rate/meter/pilot activation occurs. PR #164 must not be merged until this remediation's own implementation is merged (§9).
  - `AI-AUTONOMY-STATE.json` is untouched by this branch.
  - **Implementation requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This is a new, independently bounded pre-M6-completion correction contract — not a correction round against any of M1–M6's own contracts, and not a correction round against any of the nine already-merged remediations. Its `maximum_correction_rounds: 2` budget is its own; no counter is borrowed or altered on any other contract.
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md`.
- **Sequence position:** this is Remediation 10 in the mechanical RFC-005 remediation count (Remediations 1–9 already closed, per `RFC-005-M6-CONTRACT.md`'s own governance-history table), discovered and required after M6's own release-readiness documentation pass had already been drafted (PR #164). It sits between PR #164's draft state and PR #164's eventual mergeability — PR #164 cannot merge until this remediation's implementation merges (§9).
- **Required reading completed before drafting, independently re-verified fresh in this pass against current `origin/main`, not assumed from Codex's own review comment:** `config/usage_billing.php` (full file, 41 lines); `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` (full file, 25 lines); `app/Repositories/Contracts/PaymentProviderEventRepository.php` (`purgeable()`'s interface declaration, no `@param` documentation of any precondition); `app/Repositories/Eloquent/EloquentPaymentProviderEventRepository.php`'s `purgeable()` (lines 158–172) and `purgePayload()` (lines 174–180); `app/Console/Kernel.php`'s scheduling of `PurgeExpiredWebhookPayloads` (line 112, hourly) and its own, directly analogous, already-established `opportunitySnoozeSweepCronMinutes()` validation idiom (lines 136–148); `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php` (full file, all 4 existing methods); `app/Library/Usage/PaymentProviderEventRetryPolicy.php` (the codebase's own precedent for a *shared* normalization policy class, and why that precedent does not mechanically apply here — §2).

---

## 1. The defect — mechanically re-established from scratch

**Confirmed by direct, fresh read of `config/usage_billing.php` (unchanged since M3, 41 lines):**

```php
'webhook_event' => [
    'lease_minutes' => env('USAGE_BILLING_WEBHOOK_LEASE_MINUTES', 5),
    'max_attempts' => env('USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS', 5),
    'retention_days' => env('USAGE_BILLING_WEBHOOK_RETENTION_DAYS'),
],
```

The file's own docblock states the intent precisely: *"No retention default is invented — an unset `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` must fail closed, never silently retain payloads forever or purge immediately."* `retention_days` genuinely evaluates to PHP `null` when the environment variable is unset — confirmed directly, and confirmed absent from `phpunit.xml` and every `.env*` file in this repository, so this is also the real, observed behavior of the test environment, not merely a theoretical production configuration state.

**Confirmed by direct, fresh read of `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` (unchanged since M3, 25 lines):**

```php
public function handle(PaymentProviderEventRepository $eventRepository): void
{
    $retentionDays = (int) config('usage_billing.webhook_event.retention_days');

    foreach ($eventRepository->purgeable($retentionDays) as $event) {
        $eventRepository->purgePayload($event->id);
    }
}
```

`(int) null` is PHP `0` — not a sentinel, not a "disabled" value, not the fail-closed intent the config file's own docblock declares. This `0` is passed unguarded into `purgeable()`.

**Confirmed by direct, fresh read of `EloquentPaymentProviderEventRepository::purgeable()` (lines 158–172, unchanged since M3):**

```php
public function purgeable(int $retentionDays): Collection
{
    $cutoff = now()->subDays($retentionDays);

    return $this->query()
        ->whereNull('payload_purged_at')
        ->where(function ($query) use ($cutoff) {
            $query->where(function ($inner) use ($cutoff) {
                $inner->whereIn('state', ['processed', 'ignored'])->where('completed_at', '<', $cutoff);
            })->orWhere(function ($inner) use ($cutoff) {
                $inner->where('state', 'disposed')->where('disposed_at', '<', $cutoff);
            });
        })
        ->get();
}
```

With `$retentionDays = 0`, `$cutoff = now()->subDays(0) = now()`. The query then selects every `processed`/`ignored` event whose `completed_at` is before *right now* (i.e., every already-completed event, since `completed_at` is only ever set once processing finishes, always in the past relative to the moment this query runs) and every `disposed` event whose `disposed_at` is before *right now* (identically, every already-disposed event) — as long as `payload_purged_at` is still null. **This is not a narrow edge case: it is the query's ordinary behavior for the ordinary, undocumented, currently-unset value of a config key the repository's own docblock explicitly promises will "fail closed."**

**Confirmed by direct, fresh read of `app/Console/Kernel.php` (line 112):** `$schedule->job(new PurgeExpiredWebhookPayloads())->hourly();`, registered unconditionally whenever `config('app.stage') !== 'demo'` — i.e., in every real, non-demo deployment, including the one `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` (PR #164) itself describes.

**Mechanical result, exactly as reported:** an operator who deploys this repository and leaves `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` unset — the config file's own documented, intentional, "fail closed" default — will, within one hour of the first webhook completing, have that webhook's encrypted payload (`payload_encrypted`) silently purged, and every subsequent terminal (processed/ignored/disposed) webhook payload purged within one hour of its own completion thereafter. This directly contradicts:

- `config/usage_billing.php`'s own docblock ("must fail closed... never... purge immediately").
- `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` §3 (PR #164, unmerged): *"This job never purges anything if `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` is left unset."*
- RFC-005 §21's own webhook payload-retention design intent (retention is meant to be an explicit, opt-in operator decision, not an implicit zero-day purge).

**Confirmed by direct, fresh read of `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php` (4 existing methods):** the two methods that exercise `PurgeExpiredWebhookPayloads::handle()` (`test_disposed_past_retention_payload_is_purged_while_audit_metadata_survives`, `test_a_merely_exhausted_undispositioned_event_is_never_purged`) both explicitly set `config(['usage_billing.webhook_event.retention_days' => 30])` before running the job. **No existing test ever runs the job with retention unset, zero, negative, or malformed.** The defect above has zero regression coverage.

---

## 2. Design — the narrowest correction that satisfies all six stated guarantees

**Locked design: the entire fix lives inside `PurgeExpiredWebhookPayloads` alone.** `config/usage_billing.php`, `EloquentPaymentProviderEventRepository::purgeable()`/`purgePayload()`, the `PaymentProviderEventRepository` interface, and every other RFC-005 file are **not modified**.

**Why no config change is required or authorized.** `retention_days`'s own `null`-by-default shape is correct and intentional — the config file's own docblock states the desired behavior precisely; only the *job's* interpretation of that `null` is wrong. Changing the config default (e.g., to some large invented number) would itself violate the file's own documented "no retention default is invented" rule and would not be a correction, but a new, undisclosed policy decision this contract has no authority to make. **No config file is touched.**

**Why no repository change is required or authorized.** `purgeable(int $retentionDays)` has exactly one production call site anywhere in this repository — `PurgeExpiredWebhookPayloads::handle()` — confirmed by a full-repository grep for `purgeable(` and `retentionDays` across `app/` and `tests/`. There is no other current or planned caller this defect could reach through. Hardening `purgeable()` itself against a non-positive `$retentionDays` would be redundant defensive validation at a call boundary that is not, in fact, a system boundary (it is an internal method with one internal, now-corrected caller) — precisely the kind of validation CLAUDE.md's own engineering rules direct against adding "for scenarios that can't happen" once the one real caller is fixed. **No repository or interface file is touched.**

**Why a new shared policy class (mirroring `PaymentProviderEventRetryPolicy`) is not mechanically required.** `PaymentProviderEventRetryPolicy::normalizedMaxAttempts()` exists as a *shared* class specifically because its own docblock names **four independent consumers** that must never disagree (`ProcessPaymentProviderEvent`'s claim eligibility, `PaymentProviderEventController`'s exhausted/disposition eligibility, `RetryStuckPaymentProviderEvents`, and `EloquentPaymentProviderEventRepository::retryable()`). `retention_days` has exactly **one** consumer today. Introducing a shared class for a single consumer would be the premature abstraction CLAUDE.md's own engineering rules direct against ("three similar lines is better than a premature abstraction"). If a second real production consumer of `retention_days` is ever introduced, extracting a shared policy class at that time is the correct, then-justified move — not now.

**The correct precedent to mirror instead: `Kernel::opportunitySnoozeSweepCronMinutes()`.** This exact codebase already solves the identical category of problem — a single-consumer, env-sourced, possibly-string numeric config value that must be validated and must fail safely rather than being blindly cast — with a private method scoped to its one consumer:

```php
private function opportunitySnoozeSweepCronMinutes(): int
{
    $configured = config('opportunity.snooze_sweep_minutes', 15);

    if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
        return 15;
    }

    $minutes = (int) $configured;

    if ($minutes < 1 || $minutes > 59) {
        return 15;
    }

    return $minutes;
}
```

**Locked correction — mirrors this idiom exactly, adapted to a "disabled" (`null`) fallback instead of a numeric default, since retention has no safe non-null fallback value (any invented default would itself violate the config file's own "no retention default is invented" rule):**

```php
class PurgeExpiredWebhookPayloads extends Base
{
    public function handle(PaymentProviderEventRepository $eventRepository): void
    {
        $retentionDays = $this->resolvedRetentionDays();

        if ($retentionDays === null) {
            return;
        }

        foreach ($eventRepository->purgeable($retentionDays) as $event) {
            $eventRepository->purgePayload($event->id);
        }
    }

    /**
     * Mirrors Kernel::opportunitySnoozeSweepCronMinutes()'s own validation
     * idiom (RFC-002 §14/§33): a raw, possibly-string, env-sourced config
     * value must be an int, or a digit-only string representing one,
     * before it is trusted as a retention window. Absent, blank,
     * non-digit, zero, or negative all resolve to null — purging
     * disabled for this run — never a silently cast "purge everything
     * already completed" default (config/usage_billing.php's own
     * docblock: retention must fail closed, never purge immediately).
     */
    private function resolvedRetentionDays(): ?int
    {
        $configured = config('usage_billing.webhook_event.retention_days');

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            return null;
        }

        $days = (int) $configured;

        return $days > 0 ? $days : null;
    }
}
```

**Mechanical verification this design satisfies every stated guarantee:**

- *"null, blank, malformed, zero, or negative retention configuration must fail closed without purging or querying purge candidates."* `resolvedRetentionDays()` returns `null` for all five cases (`is_int`/`ctype_digit` guard rejects blank/malformed/negative; the `$days > 0` guard rejects zero), and `handle()` returns **before** calling `$eventRepository->purgeable()` at all in that case — zero repository calls, zero queries, for any of these five inputs.
- *"a valid positive integer retention period enables the existing bounded purge behavior."* Any int or digit-string representing a positive integer (`"1"`, `"30"`, `30`, etc.) passes both guards unchanged and reaches `purgeable($days)` exactly as today — byte-identical behavior to the current, already-tested valid-retention path.
- *"no terminal payload is purged merely because configuration is absent."* Directly closed — `null` config is exactly the first case the guard rejects.
- *"explicit valid retention continues to purge only eligible processed, ignored, or disposed rows."* `purgeable()`'s own query is completely unmodified.
- *"exhausted but undisposed rows remain unpurged."* `purgeable()`'s own `WHERE` clause (matching only `processed`/`ignored`/`disposed` states) is completely unmodified — a `failed`/stale-`processing` row was never eligible before this correction and is not made eligible by it.
- *"existing durable identity/audit fields remain intact after a valid purge."* `purgePayload()` is completely unmodified.

**Malformed-string edge cases, explicitly verified:** `ctype_digit("5abc")` is `false` (rejected, not silently truncated to `5`); `ctype_digit("5.5")` is `false` (rejected, not silently truncated to `5`); `ctype_digit("-5")` is `false` (the minus sign is not a digit — rejected, matching the "negative" requirement without needing a separate sign check); `ctype_digit("030")` is `true`, `(int) "030" === 30` (a leading zero is accepted and normalized correctly — not a defect, ordinary integer parsing); `ctype_digit("")` is `false` (blank rejected). `is_int(30)` is `true` for a real PHP int already present in config (e.g. a test's own `config(['usage_billing.webhook_event.retention_days' => 30])` call) — the existing valid-path tests are unaffected by this guard.

---

## 3. Exact new regression tests — `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php`

Four new methods, appended to the existing file, reusing the existing `createEvent()` fixture helper verbatim. Each creates one representative event per affected terminal state (`processed`, `ignored`, `disposed`) with an old timestamp, runs the job with the stated retention configuration, and asserts none is purged (`payload_encrypted` remains non-null, `payload_purged_at` remains null) — directly proving the "zero repository calls, zero purge" outcome behaviorally, without asserting internal implementation details (query counts) the existing test file's own style does not use elsewhere.

```php
public function test_unset_retention_never_purges_any_terminal_event(): void
{
    $processedId = $this->createEvent(['state' => 'processed', 'completed_at' => now()->subDays(100)]);
    $ignoredId = $this->createEvent(['state' => 'ignored', 'completed_at' => now()->subDays(100)]);
    $disposedId = $this->createEvent(['state' => 'disposed', 'attempts' => 5, 'disposed_at' => now()->subDays(100), 'disposed_by_user_id' => 1, 'disposition_note' => 'Resolved.']);

    // No config override — exercises the real, unset production default.
    (new PurgeExpiredWebhookPayloads())->handle(app(PaymentProviderEventRepository::class));

    $repository = app(PaymentProviderEventRepository::class);
    foreach ([$processedId, $ignoredId, $disposedId] as $id) {
        $event = $repository->findById($id);
        $this->assertNotNull($event->payload_encrypted);
        $this->assertNull($event->payload_purged_at);
    }
}

public function test_zero_retention_never_purges(): void
{
    $disposedId = $this->createEvent(['state' => 'disposed', 'attempts' => 5, 'disposed_at' => now()->subDays(100), 'disposed_by_user_id' => 1, 'disposition_note' => 'Resolved.']);

    config(['usage_billing.webhook_event.retention_days' => 0]);
    (new PurgeExpiredWebhookPayloads())->handle(app(PaymentProviderEventRepository::class));

    $event = app(PaymentProviderEventRepository::class)->findById($disposedId);
    $this->assertNotNull($event->payload_encrypted);
    $this->assertNull($event->payload_purged_at);
}

public function test_negative_retention_never_purges(): void
{
    $disposedId = $this->createEvent(['state' => 'disposed', 'attempts' => 5, 'disposed_at' => now()->subDays(100), 'disposed_by_user_id' => 1, 'disposition_note' => 'Resolved.']);

    config(['usage_billing.webhook_event.retention_days' => '-5']);
    (new PurgeExpiredWebhookPayloads())->handle(app(PaymentProviderEventRepository::class));

    $event = app(PaymentProviderEventRepository::class)->findById($disposedId);
    $this->assertNotNull($event->payload_encrypted);
    $this->assertNull($event->payload_purged_at);
}

public function test_malformed_retention_never_purges(): void
{
    $disposedId = $this->createEvent(['state' => 'disposed', 'attempts' => 5, 'disposed_at' => now()->subDays(100), 'disposed_by_user_id' => 1, 'disposition_note' => 'Resolved.']);

    config(['usage_billing.webhook_event.retention_days' => 'thirty']);
    (new PurgeExpiredWebhookPayloads())->handle(app(PaymentProviderEventRepository::class));

    $event = app(PaymentProviderEventRepository::class)->findById($disposedId);
    $this->assertNotNull($event->payload_encrypted);
    $this->assertNull($event->payload_purged_at);
}
```

Add `use App\Jobs\Usage\PurgeExpiredWebhookPayloads;` if not already imported (already present in the existing file, confirmed).

**The two pre-existing valid-retention tests (`test_disposed_past_retention_payload_is_purged_while_audit_metadata_survives`, `test_a_merely_exhausted_undispositioned_event_is_never_purged`) are left byte-for-byte unmodified and must continue to pass unchanged** — they are the required regression proof that this correction does not alter the valid-retention path. No new test for "valid retention still purges" is added beyond these two already-existing, already-sufficient methods.

---

## 4. Exact production allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` | REQUIRED | The entire correction (§2) — one new private method (`resolvedRetentionDays()`), one new early-return guard in `handle()`. |

**NOT_REQUIRED, explicitly confirmed:**

| Path | Reason |
|---|---|
| `config/usage_billing.php` | `retention_days`'s own `null`-by-default shape is correct and already matches its own documented intent; only the job's interpretation of `null` is wrong (§2). |
| `app/Repositories/Contracts/PaymentProviderEventRepository.php` / `EloquentPaymentProviderEventRepository.php` | `purgeable()`/`purgePayload()` are reused verbatim, unmodified; exactly one production caller exists and is corrected directly (§2). |
| Any migration or schema file | No schema change of any kind. |
| Any event, job, or notification class other than the one named above | No new domain event, job, or notification is introduced. |
| Any route, controller, or view | No HTTP/admin surface is touched. |

**Exactly 1 production path.**

## 5. Exact test allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php` | REQUIRED (modified existing) | 4 existing methods unchanged, re-confirmed unaffected (§3); exactly 4 new methods added (§3), reusing the existing `createEvent()` fixture helper verbatim. |

**Exactly 1 test path.**

---

## 6. Targeted regression commands

- `php artisan test tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php` — the file itself; expected 8 methods (4 unchanged + 4 new), all passing.
- `php artisan test tests/Feature/Usage tests/Unit/Usage` — the complete Usage domain suite, to confirm zero regression against any other webhook/retry/purge/reconciliation behavior this correction's own file does not touch.
- The repository's own documented six-command regression gate (`RFC-005-M6-CONTRACT.md` §6): `tests/Unit/Usage tests/Feature/Usage`; `tests/Unit/Entitlement tests/Feature/Entitlement`; `tests/Unit/Workspace tests/Feature/Workspace`; `tests/Unit/Business tests/Feature/Business`; `tests/Unit/Opportunity tests/Feature/Opportunity`; `php artisan test --stop-on-failure` (full suite).

---

## 7. Related governance note — PR #164 requires a focused documentation-only correction after this remediation merges

**This is a factual record for the future correction's own scope, not an authorization to edit PR #164 now.** No path under `docs/automation/RFC-005-M6-CONFORMANCE.md` or `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` is on this contract's own allow-list (§4/§5), and neither file is touched by this contract or by anything it authorizes.

Independently re-verified against current `origin/main` during this contract's drafting, both PR #164 documents contain the following confirmed citation defects, to be corrected in a separate, focused, documentation-only PR after this remediation's own implementation merges (that future correction must independently re-verify every citation in both documents, not merely replace the items below by guess):

1. `RFC-005-M6-CONFORMANCE.md` line 324 contains the literal, unresolved placeholder text `GIT_DIFF_CHECK_PLACEHOLDER`, left in place despite the same document's own "Release-gate status" section (line 341) separately claiming `git diff --check` is "clean (recorded above)."
2. `RFC-005-M6-CONFORMANCE.md` line 114 cites six test files that do not exist anywhere in this repository: `WebhookTerminalOutcomeTest.php`, `WebhookEventDispositionTest.php`, `WebhookExhaustedPayloadPurgeTest.php`, `ProviderIdentityUniquenessTest.php`, `ProviderObjectResolutionTest.php`, `WebhookMetadataMismatchTest.php`. Independently confirmed via a full listing of `tests/Feature/Usage/`: the real, existing files covering this ground are `WebhookEventDispositionAndPurgeTest.php` (disposition + purge combined in one file, not two), and `WebhookMetadataSpoofMismatchTest.php` (not `WebhookMetadataMismatchTest.php`). No file named `WebhookTerminalOutcomeTest.php`, `ProviderIdentityUniquenessTest.php`, or `ProviderObjectResolutionTest.php` exists under any name this pass could locate; the closest real candidates are `PaymentProviderEventDurableAuditTest.php`/`BusinessPaymentInstrumentSchemaTest.php` (identity/uniqueness) and `ProviderPaymentIdentifierResolutionTest.php` (object resolution) — the future correction must verify these substitutions directly against test content, not assume them from this contract's own naming-similarity observation.
3. Both `RFC-005-M6-CONFORMANCE.md` (line 112) and `RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` (line 190) cite a nonexistent method `PaymentProviderEventRetryPolicy::resolvedMaxAttempts()`. The real, only method on that class is `normalizedMaxAttempts()`, confirmed by direct read of `app/Library/Usage/PaymentProviderEventRetryPolicy.php`.
4. Both documents attribute the fair round-robin retry/reclaim algorithm to "R5, R8" / "the Reconciliation-Race Correction remediation" (`RFC-005-M6-CONFORMANCE.md` line 110/112; `RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` line 189). Independently confirmed via `git log --follow` on `app/Repositories/Eloquent/EloquentPaymentProviderEventRepository.php` (the file implementing `retryable()`, where this algorithm lives): it was touched by exactly two commits, the original M3 implementation and the Provider Refund/Dispute Outcome Handling implementation (`920e13b2d806505123cda35937cc27adda4d586f`) — never by any Reconciliation-Race Correction commit. `RFC-005-RECONCILIATION-RACE-CORRECTION-CONTRACT.md` itself concerns only `ReconcileProviderPendingState`, an unrelated job. The fair round-robin retry/reclaim algorithm belongs entirely to Provider Refund/Dispute Outcome Handling (self-titled "RFC-005 Remediation #6" in its own contract, mechanically R8 in the M6 documents' own governance-history table) — not to Reconciliation-Race Correction (mechanically R5).
5. Both documents describe Stripe signature verification ambiguously as occurring in `StripeWebhookController@handle` and/or `ProcessPaymentProviderEvent` (`RFC-005-M6-CONFORMANCE.md`'s webhook row; `RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` line 182 states it explicitly as both). Independently confirmed by direct read of both files: `StripeWebhookController::handle()` calls `$this->gateway->verifyWebhookSignature(...)` before any database row is inserted (lines 37–45); `app/Jobs/Usage/ProcessPaymentProviderEvent.php` contains no reference to "signature" anywhere in the file and performs no verification of any kind — it only processes an event the controller has already verified and persisted.
6. `RFC-005-M6-CONFORMANCE.md`'s platform-administrator-surface row cites `app/Library/Usage/UsageBillingPresenter.php`, `UsageBillingCheckoutManager.php`, and `UsageWalletManager.php` as the margin-aggregate (bcmath) evidence. Independently confirmed by direct read: the real margin computation is `EloquentBusinessUsageLedgerEntryRepository::marginAggregateForBusiness()` (lines 103–122), using `bcsub()` — not `bcadd`/`bcmul` as implied — against raw, uncast `DECIMAL` query-builder results (deliberately bypassing Eloquent's own `int` cast on `provider_cost_micro` to avoid truncating the exact string, per that method's own docblock). Display formatting (`formatMicroDisplay()`) lives in `app/Http/Controllers/Admin/UsageBillingController::show()` (lines 68–75), not in any of the three cited Library files. Real test coverage: `AdminUsageBillingControllerTest.php`, `UsageBillingDashboardViewDataTest.php`.

**This pass's own audit was a targeted spot-check of the citations above plus a full cross-check of every `tests/Feature/Usage/*Test.php` filename cited in `RFC-005-M6-CONFORMANCE.md` against the real directory listing (no further filename mismatch found beyond item 2) — it is not a claim that every method-level and line-level citation in both documents was individually re-verified.** The future documentation-only correction must perform that complete verification itself as its own primary deliverable, not treat this list as exhaustive.

---

## 8. Excluded scopes — restated

This correction does not implement, design, or absorb any of the following:

- Any change to `config/usage_billing.php`, `EloquentPaymentProviderEventRepository::purgeable()`/`purgePayload()`, or the `PaymentProviderEventRepository` interface — all confirmed unaffected and unmodified (§2).
- A new shared "retention policy" class — explicitly not mechanically required, per §2's own reasoning (exactly one current production consumer).
- Any correction to `docs/automation/RFC-005-M6-CONFORMANCE.md` or `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` — recorded as required in §7, not performed here.
- Any merge, close, edit, tag, or deployment action against PR #164.
- Any RFC-005 §39 open commercial/product decision, the tax/VAT item, or Conversations pilot activation.
- Milestone 6's own conformance/tag governance process.
- Any migration or schema change.

Do not reopen any of RFC-005's nine already-closed remediations, M1–M5, or Amendment 1 — none is touched, contradicted, or reinterpreted anywhere above.

---

## 9. Confirmations

- **No schema/migration change is required or authorized by this correction.**
- `AI-AUTONOMY-STATE.json` is untouched by this branch.
- **PR #164 remains frozen — not merged, not closed, not edited, not tagged, not deployed — until this remediation's own implementation is separately authorized, implemented, and merged.**
- No product, test, config, or route file is touched by this branch. This governance branch changes exactly one file.
- No implementation has occurred. This is the contract's first draft: `0 of 2` ordinary correction rounds consumed.
- None of RFC-005's nine already-closed remediations is reopened, contradicted, or reinterpreted anywhere above.

---

## 10. Open items

**No open item blocks authorizing or implementing this correction's own bounded scope** (§4/§5's allow-lists, §3's test design).

**One deferred, explicitly-out-of-scope item, recorded for the record (§7):** PR #164's own documentation-correction requirement is not an open item against *this* contract's scope — it is a separately-scoped future action, sequenced after this remediation's implementation merges, per §7.
