# RFC-005 Receipt Boundary Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction that gives RFC-005 §23's `business_billing_receipts` table, §28's `UsageWalletManager` receipt write authority, and §29's `App\Jobs\Usage\SendReceiptNotification` job their first real implementation contract. Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch named in §0, exactly as every RFC-005 milestone and correction contract before it (most recently the Funding Provider-Flow correction, [`RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md`](./RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md), merged PR [#137](https://github.com/os-creator1/os-ai/pull/137)) has required.

This correction exists because the M6 static conformance audit found Receipt Boundary to be a real blocking gap: `business_billing_receipts` has no migration, model, or repository; no `SendReceiptNotification` job exists; and neither `PaymentIntentResult` nor `CheckoutSessionResult` exposes any receipt evidence. This contract is remediation #3 of 7; it does not by itself unblock M6.

---

## Correction Round 1 record

Independent pre-merge review of the initial draft (head `9b7b62ed56878152d36e93bb60b6cdad00f4829f`) found one architectural blocker and ten supporting defects, all resolved below by direct re-audit of the current repository. **This independent review failure consumes Correction Round 1 — correction rounds are consumed by a failing review, not only by a post-merge failure.** `maximum_correction_rounds: 2` is unchanged. **1 of 2 ordinary correction rounds consumed; 1 ordinary round remains.** The contract remains `PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE`; no implementation authorization exists at any point during this correction.

Exact issues resolved this round:

1. **Architectural blocker (§C of the review): a synchronous `AutoRecharge` success path was omitted entirely.** Direct code re-read of `UsageBillingCheckoutManager::driveOffSessionPaymentIntentAttempt1()` confirms `confirmSucceeded()` is called immediately, in the initiation call itself, when `createOffSessionPaymentIntent()` synchronously returns `status === 'succeeded'` — there is no later browser-return or webhook step for this path. The Round-0 draft's "fetch receipt before `confirmSucceeded()`'s locked transaction" language also incorrectly implied an outer transaction around `confirmSucceeded()` that does not exist in the current code. **Resolved by replacing the entire receipt-recovery architecture** (§3 below): the receipt job is now dispatched from inside `UsageWalletManager::creditFromFunding()`'s own existing transaction — the one method every receipt-eligible credit already passes through, regardless of which of the five entry points reached it — rather than from any purpose-specific confirmation branch. This mechanically covers all five financial-success entry points (ManualTopUp Checkout confirmation, AddonPurchase Checkout confirmation, AutoRecharge webhook confirmation, AutoRecharge administrator/reconciliation confirmation, AutoRecharge synchronous success) with zero purpose-specific code, and mechanically excludes every ineligible flow (`direct_deliverable` add-ons, all slot-agreement charges, refunds/disputes) because none of them ever calls `creditFromFunding()` — re-confirmed unchanged from Round 0's own audit.
2. **`SendReceiptNotification`'s job design inverted (§E).** The job no longer owns table writes or provider calls directly. Its constructor now carries `fundingAttemptId`+`ledgerEntryId` (not a pre-resolved receipt id, since no receipt is guaranteed to exist yet when the job is dispatched). It orchestrates: validate inputs, delegate the provider-facing read and receipt persistence to one new `UsageBillingCheckoutManager::ensureFundingReceipt()` method (§6), then evaluate notification preferences only after a receipt row is confirmed to exist.
3. **Recoverability claim corrected to match actual code (§F).** `App\Jobs\Base` sets `$tries = 1`, `$maxExceptions = 1` — confirmed by direct read. The Round-0 draft's "standard Laravel queue retry" was false; no retry count is invented. Recovery is instead the existing, real `failed_jobs` table/mechanism (confirmed present via `database/migrations/2019_08_19_000000_create_failed_jobs_table.php` and `config/queue.php`'s `'failed'` block) plus the job's own structural idempotency, so a manual re-dispatch (via `queue:retry` where the deployed queue connection populates `failed_jobs`, or a direct re-dispatch with the same two ids in any deployment) is always safe and correct, never assumed to be a specific automatic retry count.
4. **Checkout-backed verification-vs-receipt conflation removed (§G).** Under the corrected architecture, receipt-evidence retrieval never shares a call with the original payment-verification retrieval — `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`'s own existing `retrieveCheckoutSession()`/`retrievePaymentIntent()` calls are **completely unmodified** by this correction. `ensureFundingReceipt()` (§6) makes its own, separate, later, asynchronous provider call, invoked only from the queued job, only after accounting has already committed — so a failure there can never affect payment verification or accounting, by construction, not by added failure-branching logic.
5. **`UsageWalletManager` receipt-write locking corrected to use the repository convention (§H).** `attachFundingReceipt()` no longer issues a raw query; it uses a new `BusinessUsageLedgerEntryRepository::findForUpdateById()` method, mirroring the existing `findForUpdateByBusinessId()` wallet-locking convention. `findLedgerEntryIdByCorrelationKey()` is removed — mechanically unnecessary, since `creditFromFunding()` already holds the just-created `BusinessUsageLedgerEntry` model (Eloquent's `create()` populates `id` immediately) and can capture it directly, confirmed by direct read of `EloquentBusinessUsageLedgerEntryRepository::create()`.
6. **`UNIQUE(ledger_entry_id)` removed as a decision point (§I).** Human review decision: do not add it; the RFC §23 schema does not define it, and the ledger-row `FOR UPDATE` lock is the sole idempotency mechanism. No longer listed as unresolved. The `business_id` convenience index is also removed — not present in the RFC's own table definition, added in Round 0 by unauthorized precedent-copying, not RFC evidence.
7. **Exact migration filename locked (§J):** `database/migrations/2026_08_27_120001_create_business_billing_receipts_table.php`, confirmed non-colliding against the current migration sequence (latest existing: `2026_08_24_120003_...`).
8. **`FakePaymentProviderGateway` design corrected to match actual current code (§K).** Direct read confirms `$paymentIntentOutcomes` is `array<string, string>` (a status map only) and `retrievePaymentIntent()` currently ignores it entirely, always returning a hardcoded succeeded result — the Round-0 draft's "extend `paymentIntentOutcomes` with receipt keys" was not possible against the actual type. Corrected: a new `registerPaymentIntentResult(PaymentIntentResult $result): void` + registry, checked first by `retrievePaymentIntent()`, mirroring the already-existing `registerCheckoutSessionResult()`/`retrieveCheckoutSession()` pattern exactly. `checkoutSessionOutcomes`'s existing meaning is untouched; its unregistered-fallback path is extended with deterministic default receipt-evidence fields so no existing test needs new setup.
9. **`PaymentProviderGateway.php` removed from the production allowlist (§M).** Confirmed by design: no interface method signature changes; a doc-comment-only change is not a required production diff.
10. **`StripePaymentProviderGatewayCompatibilityTest.php` added to the test allowlist (§N).** Confirmed by direct read: line 87 asserts the literal substring `"'expand' => ['payment_intent.payment_method']"`, which becomes false once the array gains a second element. Corrected assertion and two new tests locked in §10.
11. **Test-file discretion eliminated (§O); the impossible exact-once-mail guarantee replaced with an honest one (§P).** Every reused/new file is now named exactly, every proof assigned exactly one method name (§10). The duplicate-dispatch guarantee is restated as application-level dispatch idempotency (one ledger row → one dispatch; a duplicate replay cannot create a second ledger row) plus an explicit, honest disclaimer that at-least-once queue/mail delivery semantics mean exact-once email delivery is not claimed, consistent with `business_billing_receipts`'s own RFC-defined schema carrying no notification-state column.

No genuinely unresolved contradiction remains that could not be mechanically resolved from direct repository evidence; every blocker raised had a direct, evidence-backed answer (§11 restates only the three genuine human-review decisions the assigning review itself already resolved, so none remain open).

---

## 0. Governance

- Drafted on branch `chore/rfc-005-receipt-boundary-correction-contract`, in an isolated linked worktree (`../rfc-005-receipt-boundary-contract-worktree`), based on `origin/main` at `1eba17ae4112a1e5e832627d44c185a0ee3f56ca` — the Funding Provider-Flow correction's own merge commit (PR [#137](https://github.com/os-creator1/os-ai/pull/137)), reconfirmed via `git fetch origin main && git rev-parse origin/main` at the start of this correction round, unchanged since initial drafting.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-receipt-boundary-correction`.**
- Confirmed at this correction round: no `agent/rfc-005-receipt-boundary-correction` branch exists on `origin`; no product/test/config/route/RFC-source file has been touched by this branch at any point.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - **Correction rounds: 1 of 2 consumed as of this round; 1 ordinary round remains.**
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - `M6 remains frozen` — untouched, not resumed, no M6 document created or modified.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch.
  - **Implementation requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This is a new, independently bounded pre-M6 correction contract — not a correction round against M1–M6's own contracts, and not a correction round against the already-merged Reservation Admission or Funding Provider-Flow contracts. Its `maximum_correction_rounds: 2` budget is its own; no counter is borrowed or altered on any other contract.
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`.

---

## 1. Preserved Round-0 findings (re-confirmed unchanged; no contradiction found)

**§23 — `business_billing_receipts`, confirmed verbatim, unchanged this round:**

| Column | Type | Nullable | Default |
|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — |
| `ledger_entry_id` | `unsignedBigInteger`, FK `business_usage_ledger_entries.id`, `restrictOnDelete()` | No | — |
| `provider_receipt_url` | `string(2048)` | No | — |
| `provider_reference` | `string(191)` | No | — |
| `created_at` | `timestamp` | No | `now()` |

No `updated_at`. No `UNIQUE` index beyond the implicit PK — **preserved exactly; not added to, per §5 below.**

1. Stripe-hosted receipts remain authoritative for v1.
2. Legacy `invoices` are never reused.
3. `business_billing_receipts` retains exactly the six-column shape above.
4. `provider_receipt_url` comes from Stripe `Charge.receipt_url`.
5. `provider_reference` is the Stripe Charge id (`ch_...`), never the PaymentIntent or Checkout Session id.
6. Receipt-eligible charged flows remain exactly: `ManualTopUp`, `AutoRecharge`, `AddonPurchase` with `wallet_credit` fulfillment — re-confirmed this round by the same `creditFromFunding()` call-site audit (exactly three call sites: `UsageBillingCheckoutManager.php` lines 651 and 691, the latter guarded by `fulfillment_mode === 'wallet_credit'`; zero call sites anywhere in slot-agreement code).
7. These remain **NO-receipt** in this correction: `AddonPurchase` `direct_deliverable`, initial additional-slot agreement, scheduled slot renewal, mid-period slot increase, `Refund`, `DisputeChargeback`. **This is now a closed human-review decision, not an open one** (§11).
8. `UsageWalletManager` remains sole write authority for `business_billing_receipts`.
9. `notification_opt_in = false` prevents notification but never prevents receipt persistence — the job now enforces this by construction, since persistence (via `ensureFundingReceipt()`) happens **before** notification-preference evaluation (§7).
10. No local invoice/receipt document is generated.

**Provider receipt evidence source, preserved unchanged:** the Stripe `Charge.receipt_url` object, reached via `expand: ['latest_charge']` (PaymentIntent) or `expand: ['payment_intent.latest_charge']` (Checkout Session) — confirmed supported by the installed `stripe/stripe-php: ^7.76` SDK (`composer.json`/`composer.lock`).

---

## 2. Mechanical fact re-confirmed this round: every receipt-eligible credit passes through exactly one method

Direct re-read of `UsageWalletManager::creditFromFunding()` (current signature: `creditFromFunding(int $businessId, UsageLedgerEntryType $entryType, int $amountMicro, int $fundingAttemptId, string $correlationKey): void`) confirms it:

- opens one `DB::transaction()`, locks the wallet row (`findForUpdateByBusinessId()`), computes debt-clearing,
- calls `$this->ledgerRepository->create([...])`, whose Eloquent implementation (`EloquentBusinessUsageLedgerEntryRepository::create()`) calls `$entry->save()` and returns `$entry` — the created model, with `id` populated immediately (standard Eloquent auto-increment population on insert), confirmed by direct read,
- updates the wallet balances,
- and is the **exact and only** method called by all three receipt-eligible flows (§1 item 6).

Direct re-read of every call site that can reach `confirmSucceeded()` — `confirmAttemptFromReturn()`, `confirmAttemptFromWebhook()`, `retryFundingAttemptAsAdministrator()`, and `driveOffSessionPaymentIntentAttempt1()`'s own direct synchronous call — confirms **all five** financial-success entry points ultimately call `creditFromFunding()` (directly, for `ManualTopUp`/`AutoRecharge`; via `finalizeAddonPurchaseIfPending()`, for `AddonPurchase` `wallet_credit`). This is the mechanical anchor for §3's corrected architecture.

---

## 3. Corrected receipt-dispatch architecture (replaces Round 0's "fetch before confirmSucceeded" design entirely)

**Locked:** `UsageWalletManager::creditFromFunding()` is widened to capture the ledger entry it already creates and, after the wallet update succeeds (still inside the same transaction), dispatch the receipt job:

```php
public function creditFromFunding(
    int $businessId,
    UsageLedgerEntryType $entryType,
    int $amountMicro,
    int $fundingAttemptId,
    string $correlationKey,
): void {
    DB::transaction(function () use ($businessId, $entryType, $amountMicro, $fundingAttemptId, $correlationKey) {
        // ...existing wallet lock, debt-clearing computation (unchanged)...

        $ledgerEntry = $this->ledgerRepository->create([ /* ...unchanged attributes... */ ]);

        // ...existing wallet balance update (unchanged)...

        \App\Jobs\Usage\SendReceiptNotification::dispatch($fundingAttemptId, (int) $ledgerEntry->id)
            ->afterCommit();
    });
}
```

**Exact behavior, locked:**

- The dispatch is registered from inside the accounting transaction: a rollback (e.g., the wallet row not found, throwing `UsageWalletNotFoundException`) means the job is never queued at all; a successful commit is what makes the job eligible to run (`->afterCommit()`).
- No provider call of any kind occurs inside this transaction or method — `creditFromFunding()`'s own outbound-call-free character (already required, unchanged from M1) is preserved exactly.
- **Return type, parameters, and every other line of `creditFromFunding()` are otherwise byte-for-byte unchanged.** No caller's existing invocation changes.
- This single change mechanically covers **all five** financial-success entry points named in the assigning review (§2), with zero purpose-specific branching added anywhere in `UsageBillingCheckoutManager`, and mechanically excludes every ineligible flow because none of them calls this method at all — re-confirmed, not assumed.

**Receipt-evidence retrieval itself is never performed here.** It happens later, asynchronously, only inside the queued job's own delegation to `UsageBillingCheckoutManager::ensureFundingReceipt()` (§6) — fully decoupled in time and code path from both the accounting transaction above and the original payment-verification calls in `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`, which this correction does not modify at all.

---

## 4. Receipt-producing flow matrix (unchanged from Round 0's own audit; re-confirmed)

| # | Flow | Provider object family | Creates a ledger entry? | Ledger entry type | Receipt row? | `SendReceiptNotification`? |
|---|---|---|---|---|---|---|
| 1 | `ManualTopUp` | Checkout Session → Charge | Yes | `PaidTopUp` | **YES** | **YES** |
| 2 | `AutoRecharge` (all four entry points: return / webhook / admin-resume / synchronous) | PaymentIntent → Charge | Yes | `AutoRecharge` | **YES** | **YES** |
| 3 | `AddonPurchase` — `wallet_credit` | Checkout Session → Charge | Yes (`PaidTopUp`, reused) | `PaidTopUp` | **YES** | **YES** |
| 4 | `AddonPurchase` — `direct_deliverable` | Checkout Session → Charge | **No** | — | **NO — mechanically impossible** | **NO** |
| 5 | Initial additional-slot agreement Checkout | Checkout Session → Charge | **No** | — | **NO — mechanically impossible** | **NO** |
| 6 | Scheduled additional-slot renewal | PaymentIntent → Charge | **No** | — | **NO — mechanically impossible** | **NO** |
| 7 | Mid-period additional-slot increase | PaymentIntent → Charge | **No** | — | **NO — mechanically impossible** | **NO** |
| 8 | `Refund` | Charge refund | Yes (`Refund` entry) | `Refund` | **NO — closed human-review decision** | **NO** |
| 9 | `DisputeChargeback` | Stripe-initiated clawback | Yes (`DisputeChargeback` entry) | `DisputeChargeback` | **NO — closed human-review decision** | **NO** |
| 10 | Any other RFC-005-defined provider-backed path | — | — | **NONE FOUND** | — | — |

Rows 8–9 are now a **closed** decision per the assigning review (§11) — this correction does not create receipts for refunds/chargebacks; a future correction may design that separately.

---

## 5. Receipt cardinality / idempotency — no `UNIQUE` constraint, no convenience index

**Locked, closed decision:** `business_billing_receipts` gains **no** additional index beyond its implicit PK — no `UNIQUE(ledger_entry_id)`, no `index('business_id')`. The RFC §23 schema defines neither, and Round 0's addition of both was unauthorized precedent-copying, not RFC evidence.

**Mechanism (unchanged from Round 0's own reasoning, re-confirmed as sufficient without any index):** `UsageWalletManager::attachFundingReceipt()` (§6) locks the **already-existing** `business_usage_ledger_entries` row via a new repository method, `findForUpdateById()` (§9) — a genuine row lock on a real PK, mirroring the existing `findForUpdateByBusinessId()` wallet-locking convention exactly, not a new index or a lock on a not-yet-existing row. Every caller that ever attempts to attach a receipt for a given `ledger_entry_id` — the queued job's first run, a manual operator re-dispatch after a prior failure, a theoretical duplicate dispatch — serializes on this same row lock, because they all name the same `ledger_entry_id`. Whichever transaction commits first wins the "no existing receipt" check inside the lock; every subsequent one sees the row and returns it unchanged.

Since exactly one ledger entry is created per receipt-eligible charge (§1 item 6, §2), and exactly one dispatch is registered per ledger entry (§3), and `attachFundingReceipt()` converges on repeat invocation (this section), the cardinality invariant — "one successful receipt-bearing ledger entry produces at most one `business_billing_receipts` row" — holds without any new schema constraint.

---

## 6. `UsageWalletManager` and `UsageBillingCheckoutManager` — exact method signatures

**`UsageWalletManager`, three methods (write authority preserved exactly — no other class ever writes `business_billing_receipts`):**

```php
public function attachFundingReceipt(
    int $ledgerEntryId,
    int $fundingAttemptId,
    int $businessId,
    string $providerReceiptUrl,
    string $providerReference,
): BusinessBillingReceipt
```

- Opens one `DB::transaction()`. Locks `business_usage_ledger_entries` via `$this->ledgerRepository->findForUpdateById($ledgerEntryId)`. If null, throws `\InvalidArgumentException` — a caller must only ever pass an id it already knows exists (the job always resolves it from `creditFromFunding()`'s own dispatch payload).
- Validates, throwing `\InvalidArgumentException` on any mismatch: `$ledgerEntry->business_id === $businessId` and `$ledgerEntry->funding_attempt_id === $fundingAttemptId`. This is a defensive, fail-closed cross-check against a caller passing inconsistent ids — never expected to trigger under correct operation.
- Checks `BusinessBillingReceiptRepository::findByLedgerEntryId($ledgerEntryId)`. If found, returns it unchanged (the sole idempotency convergence point, §5).
- Otherwise creates and returns one new row: `business_id`, `ledger_entry_id`, `provider_receipt_url`, `provider_reference`, `created_at: now()`.
- Never called with an open outbound-provider call in flight — the caller (`UsageBillingCheckoutManager::ensureFundingReceipt()`) always completes its Stripe re-fetch before invoking this method.

```php
public function findFundingReceipt(int $ledgerEntryId): ?BusinessBillingReceipt
```

Thin, unlocked read wrapper over `BusinessBillingReceiptRepository::findByLedgerEntryId()` — used by `ensureFundingReceipt()` to avoid a wasted Stripe call when a receipt is already known to exist. The authoritative convergence check remains `attachFundingReceipt()`'s own locked re-check.

`creditFromFunding()` itself: unchanged signature and body except for the two additions in §3 (capturing `$ledgerEntry` and the trailing dispatch). `findLedgerEntryIdByCorrelationKey()` from Round 0 is **removed** — not needed (§2).

**`UsageBillingCheckoutManager`, one new method — the sole provider-facing boundary for receipt evidence:**

```php
public function ensureFundingReceipt(
    BusinessFundingAttempt $attempt,
    int $ledgerEntryId,
): ?BusinessBillingReceipt
```

Exact behavior, locked:

1. `$existing = $this->walletManager->findFundingReceipt($ledgerEntryId);` — if non-null, return it immediately. **No provider call.**
2. Branch by `$attempt->purpose`:
   - `ManualTopUp` / `AddonPurchase`: `$session = $this->gateway->retrieveCheckoutSession($attempt->provider_session_or_intent_reference);` then require `$session->providerCheckoutSessionId === $attempt->provider_session_or_intent_reference && $session->status === 'complete' && $session->paymentStatus === 'paid'`.
   - `AutoRecharge`: `$paymentIntent = $this->gateway->retrievePaymentIntent($attempt->provider_session_or_intent_reference);` then require `$paymentIntent->providerPaymentIntentId === $attempt->provider_session_or_intent_reference && $paymentIntent->status === 'succeeded'`.
   - Any provider exception from either call is **not caught here** — it propagates to the job (§7), which is the layer responsible for treating it as "evidence unavailable" and failing cleanly.
3. If the object-id/status check in step 2 fails (should not happen for an already-`Succeeded` attempt, but not assumed impossible — e.g. transient provider-side inconsistency), return `null`.
4. Read `$receiptUrl`/`$receiptChargeId` off the returned DTO's two new fields (§8). If either is empty/null, return `null` — **evidence unavailable, not an error**.
5. Otherwise call `$this->walletManager->attachFundingReceipt($ledgerEntryId, $attempt->id, $attempt->business_id, $receiptUrl, $receiptChargeId)` and return its result.
6. **Never calls `BusinessBillingReceiptRepository` directly** — every write goes through step 5.

This method makes exactly one provider call per invocation, only when no receipt yet exists, and never while any lock is held (steps 2 happen entirely before step 5's own locked write) — preserving §20's "no outbound Stripe call while a database row lock is held" rule exactly.

---

## 7. `SendReceiptNotification` — exact job design

```php
public function __construct(
    private readonly int $fundingAttemptId,
    private readonly int $ledgerEntryId,
) {}
```

`extends App\Jobs\Base` — inherits `ShouldQueue` (already implemented by `Base`; this class does not redundantly re-declare it), `$tries = 1`, `$maxExceptions = 1`, `$failOnTimeout = true`, all **unchanged, not overridden**. Dispatched via `->afterCommit()` from inside `creditFromFunding()`'s own transaction (§3).

**`handle()`, exact sequence, locked:**

1. Load `$attempt = BusinessFundingAttemptRepository::findById($this->fundingAttemptId)`. If null, throw `\RuntimeException` (a dispatch always follows a real, committed attempt; a missing row is a genuine integrity fault, correctly surfaced as a failed job).
2. Require `$attempt->state === FundingAttemptState::Succeeded` — throw `\RuntimeException` otherwise (defensive; this job is only ever dispatched from the credit path itself, which only runs for an attempt already transitioning to `Succeeded`).
3. Load `$ledgerEntry = BusinessUsageLedgerEntryRepository::findById($this->ledgerEntryId)` (new read method, §9). If null, or if `$ledgerEntry->funding_attempt_id !== $attempt->id`, or if `$ledgerEntry->business_id !== $attempt->business_id`, throw `\InvalidArgumentException` — the job's own pre-check, independent of and in addition to `attachFundingReceipt()`'s own internal cross-check (§6), both fail-closed.
4. `$receipt = $this->checkoutManager->ensureFundingReceipt($attempt, $this->ledgerEntryId);`
5. **If `$receipt === null`:** throw `App\Exceptions\Usage\ReceiptEvidenceUnavailableException` — the job fails clearly (its single try is exhausted, `Base`'s `$tries = 1` applies), landing in the existing `failed_jobs` mechanism (§9's confirmation). **Accounting is never touched by this failure** — it already committed in a separate, earlier transaction (§3). If a genuine provider exception (e.g. `ProviderApiUnavailableException`) escaped `ensureFundingReceipt()` uncaught, that exception itself is what fails the job — no additional catch/rethrow is added, since the failed-job mechanism records the real underlying exception either way.
6. **Receipt now exists (either just-created or found pre-existing).** Only now does notification-preference evaluation begin — receipt persistence has already completed, satisfying §1 item 9's ordering requirement structurally, not by a documentation promise alone.
7. Load the billing contact: `BusinessBillingContactRepository::findByBusinessId($attempt->business_id)`. **Missing contact:** log at `info`, return — no send, no exception (a Business with no configured billing contact is a valid, non-error state per §17.A).
8. **`notification_opt_in === false`:** log at `info`, return — no send.
9. **Recipient resolution:** `contact_email` if non-null; else the `contact_user_id`-linked `User`'s own email if `contact_user_id` is set. If neither resolves to a usable email, log at `warning`, return — no send, no exception.
10. Send via `Notification::route('mail', $email)->notify(new \App\Notifications\Usage\ReceiptAvailableNotification($receipt->provider_receipt_url));` — a plain Laravel `Notification` (`via(): ['mail']`), sending only the Stripe-hosted link, no local document.

**Recoverability — honest, evidence-based, per the assigning review's own correction:**

- `App\Jobs\Base`'s `$tries = 1`/`$maxExceptions = 1` are **not overridden**. A failure at step 5 exhausts the job's one attempt.
- `database/migrations/2019_08_19_000000_create_failed_jobs_table.php` and `config/queue.php`'s `'failed' => ['driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'), ...]` confirm the standard Laravel failed-jobs mechanism is present in this repository's own schema/config — genuine, pre-existing tooling, not invented by this correction. Whether a given deployment's actual `QUEUE_CONNECTION` populates `failed_jobs` (true for `database`/`redis`/etc.) or executes inline (`sync`, `.env.example`'s own default, where an uncaught exception instead propagates to whatever dispatched it and the application's existing exception-reporting pipeline) is a deployment-configuration fact this contract does not control and does not need to, because:
- **The job is fully idempotent from either recovery path**, by construction: re-dispatching `new SendReceiptNotification($fundingAttemptId, $ledgerEntryId)` with the same two ids — whether via `php artisan queue:retry {uuid}` against a captured `failed_jobs` row, or a direct manual re-dispatch — re-enters at step 1, and step 4's `ensureFundingReceipt()` immediately returns the existing receipt with **zero provider call** if one was already attached, or safely retries the provider fetch if not. **Financial accounting is never re-touched by any retry, under any circumstance** — it is not part of this job at all.
- No specific automatic retry count, and no specific operator-facing retry UI, is claimed or built. This is the honest, narrow recovery guarantee: *manual or operator-triggered re-dispatch is always safe and correct; nothing about this correction guarantees it happens automatically more than once.*

**Duplicate-dispatch/delivery semantics — restated honestly, per the assigning review's correction, since `business_billing_receipts` carries no notification-state column and none is added:**

- **Application dispatch idempotency (claimed, guaranteed):** one new receipt-eligible ledger entry causes exactly one `SendReceiptNotification::dispatch()` call (§3). A duplicate financial confirmation replay (return-then-webhook, webhook-then-admin-resume, etc.) cannot create a second ledger row for the same funding attempt (pre-existing, unmodified `correlation_key UNIQUE` guarantee) and therefore cannot trigger a second normal dispatch.
- **Queue/mail delivery semantics (not claimed as exact-once):** the underlying queue and mail transport are ordinary at-least-once systems; a worker crash after a mail provider has accepted a send but before the job records completion could, in principle, result in a duplicate send on a manual operator retry. **Exact-once email delivery is not claimed by this correction** and is not achievable without durable notification state that RFC §23's own schema does not define and this correction does not invent (§1 item 3, preserved).

---

## 8. DTO / gateway boundary — exact additions (unchanged design from Round 0, re-confirmed backward-compatible)

- **`PaymentIntentResult`** — add trailing `public ?string $receiptUrl = null, public ?string $receiptChargeId = null`.
- **`CheckoutSessionResult`** — add the same two trailing nullable fields.
- Confirmed by direct grep: exactly four construction sites for these two DTOs across the entire repository (`FakePaymentProviderGateway.php`, `StripePaymentProviderGateway.php`, `AddonPurchaseTransitionAuditTest.php`, `TopUpStateMachineTest.php`) — none broken by an appended optional trailing parameter.
- **`PaymentProviderGateway.php` — no change.** Removed from the allowlist (Round-0 correction §9 above); confirmed no interface signature changes.
- **`StripePaymentProviderGateway`** — `retrievePaymentIntent()` gains `['expand' => ['latest_charge']]`. `retrieveCheckoutSession()`'s existing `['expand' => ['payment_intent.payment_method']]` becomes `['expand' => ['payment_intent.payment_method', 'payment_intent.latest_charge']]` (both expansions in one call — Stripe's API permits multiple `expand` paths per request; each is independently within its 4-level nesting cap). Both call sites map evidence **fail-closed**: `receiptChargeId` is populated only from the expanded Charge object's own real `id`; `receiptUrl` only from that same Charge's non-empty `receipt_url`. If `latest_charge` is absent, not expanded, not object-like, or its `receipt_url` is empty, **both** fields are `null` — never a locally-constructed URL, never a Charge id inferred from the PaymentIntent id.
- **`FakePaymentProviderGateway`** — corrected design (Round-0 correction §8 above):
  - New `registerPaymentIntentResult(PaymentIntentResult $result): void`, storing by `$result->providerPaymentIntentId`. `retrievePaymentIntent()` checks this registry first; if not found, preserves its existing hardcoded-succeeded fallback (now also carrying deterministic default `receiptUrl`/`receiptChargeId` values, e.g. `'https://fake.stripe.test/receipts/'.$providerPaymentIntentId` / `'ch_fake_'.Str::random(16)`, so no existing test needs new setup).
  - `checkoutSessionOutcomes`'s existing meaning and keys are **unchanged**. `retrieveCheckoutSession()`'s own unregistered-fallback and outcome-driven branches gain the same two deterministic default receipt fields when the outcome does not explicitly override them via two new optional outcome keys, `receiptUrl`/`receiptChargeId` (absent ⇒ deterministic default, exactly mirroring the existing `providerPaymentMethodId` optional-key pattern already in this method).
  - `registerCheckoutSessionResult()` is unchanged in shape (it already accepts a full `CheckoutSessionResult`, which now simply carries the two new fields when a test explicitly constructs one with specific receipt evidence).
  - No new call-recording array is required: `ensureFundingReceipt()`'s own provider calls are already observable through the existing `createCheckoutSessionCalls`/`confirmPaymentIntentCalls`-style pattern is not needed here since `retrievePaymentIntent()`/`retrieveCheckoutSession()` are retrieval, not creation, calls with no existing call-recording precedent; a test proving "no provider call occurred" (the already-has-a-receipt short-circuit) asserts this by registering a receipt directly via `UsageWalletManager::attachFundingReceipt()` in its own arrange step and confirming no exception/state change occurred from a subsequent `ensureFundingReceipt()` call against a Fake configured to throw if either retrieval method is invoked (a one-off anonymous class or Mockery spy at the test's own discretion — a test-authoring detail, not a contract-level fixture addition).

---

## 9. Model / repository / migration — exact paths (finalized, no placeholder)

- **Migration:** `database/migrations/2026_08_27_120001_create_business_billing_receipts_table.php` — creates exactly the six §1 columns; `$table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();`; `$table->foreign('ledger_entry_id')->references('id')->on('business_usage_ledger_entries')->restrictOnDelete();`; `$table->timestamp('created_at')->useCurrent();`. **No index beyond the implicit PK** (§5).
- **Model:** `app/Models/BusinessBillingReceipt.php` — `protected $table = 'business_billing_receipts'; public $timestamps = false;`, `$fillable = ['business_id', 'ledger_entry_id', 'provider_receipt_url', 'provider_reference', 'created_at']`, `belongsTo(Business::class)`, `belongsTo(BusinessUsageLedgerEntry::class, 'ledger_entry_id')`.
- **Repository contract:** `app/Repositories/Contracts/BusinessBillingReceiptRepository.php extends BaseRepository` — `findById(int $id): ?BusinessBillingReceipt`, `findByLedgerEntryId(int $ledgerEntryId): ?BusinessBillingReceipt`, `create(array $attributes): BusinessBillingReceipt`. No `update()` — create-only, matching the absence of `updated_at`.
- **Eloquent implementation:** `app/Repositories/Eloquent/EloquentBusinessBillingReceiptRepository.php` — direct implementation, no deviation.
- **`AppServiceProvider` binding:** one new line, `\App\Repositories\Contracts\BusinessBillingReceiptRepository::class => \App\Repositories\Eloquent\EloquentBusinessBillingReceiptRepository::class,`.
- **`BusinessUsageLedgerEntryRepository` (existing contract) — two new read-only methods**, mechanically required by §6:
  - `findById(int $id): ?BusinessUsageLedgerEntry`
  - `findForUpdateById(int $id): ?BusinessUsageLedgerEntry` — issues the row-level `SELECT ... WHERE id = ? FOR UPDATE`, mirroring `BusinessUsageWalletRepository::findForUpdateByBusinessId()`'s own existing convention exactly.
  - Both added to `EloquentBusinessUsageLedgerEntryRepository.php` as well. The contract's existing "append-only, no update()/delete()" character is unchanged — these are reads.
- **New exception:** `app/Exceptions/Usage/ReceiptEvidenceUnavailableException.php` — a narrow, purpose-built domain exception (mirroring the existing `Exceptions\Usage\*` convention, e.g. `ProviderApiUnavailableException`, `FundingAttemptNotResumableException`), thrown only by `SendReceiptNotification::handle()` step 5 when `ensureFundingReceipt()` returns `null`. The mismatch/integrity checks in §6/§7 use PHP's built-in `\InvalidArgumentException`/`\RuntimeException` — no additional new exception class is introduced for those, minimizing new-file footprint.

---

## 10. Test ownership — exact paths and exact method names (zero discretion)

**Reused existing files, exact new/modified method names:**

- **`tests/Feature/Usage/TopUpStateMachineTest.php`** — new: `test_manual_top_up_success_dispatches_exactly_one_send_receipt_notification()`.
- **`tests/Feature/Usage/FundingAttemptPayerConsentTest.php`** — extend `test_auto_recharge_still_creates_an_off_session_payment_intent_and_snapshots_the_instrument_at_creation()` (the synchronous-success test) with a `Queue::fake()` assertion of exactly one `SendReceiptNotification` dispatch carrying the correct `fundingAttemptId`/`ledgerEntryId`; extend `test_auto_recharge_webhook_confirmation_performs_no_new_provider_call()` identically for the webhook path; new: `test_platform_administrator_resuming_a_stuck_auto_recharge_attempt_dispatches_the_receipt_job()` — the admin/reconciliation entry point, exercising `retryFundingAttemptAsAdministrator()` against an `AutoRecharge`-purpose attempt (not currently covered by the file's existing `test_platform_administrator_can_resume_a_stuck_attempt()`, which is `ManualTopUp`-scoped).
- **`tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php`** — new: `test_wallet_credit_addon_purchase_dispatches_exactly_one_send_receipt_notification()`; new: `test_direct_deliverable_addon_purchase_dispatches_no_receipt_notification()`.
- **`tests/Feature/Usage/FundingAttemptExactlyOnceWalletCreditTest.php`** — new: `test_repeated_receipt_attachment_for_the_same_ledger_entry_is_idempotent()`.
- **`tests/Feature/Usage/CrossBusinessPaymentIsolationTest.php`** — new: `test_attach_funding_receipt_rejects_a_mismatched_business_id()`.
- **`tests/Feature/Usage/RedirectBeforeWebhookConfirmationTest.php`** — new: `test_missing_receipt_evidence_fails_the_notification_job_without_reversing_accounting()`; new: `test_duplicate_return_and_webhook_confirmation_dispatches_the_receipt_job_exactly_once()`.
- **`tests/Feature/Usage/FakePaymentProviderGatewayTest.php`** — new: `test_registered_payment_intent_result_is_returned_verbatim()`; new: `test_unregistered_payment_intent_retrieval_still_returns_deterministic_default_receipt_fields()`; new: `test_checkout_session_receipt_fields_are_returned_verbatim_when_explicitly_registered()`.
- **`tests/Feature/Usage/StripePaymentProviderGatewayCompatibilityTest.php`** — correct `test_gateway_source_fails_closed_on_a_missing_redirect_url_and_resolves_payment_method_via_expansion()`'s final assertion from the now-false full-array literal to two separate substring assertions: `assertStringContainsString("'payment_intent.payment_method'", $source)` and `assertStringContainsString("'payment_intent.latest_charge'", $source)`; new: `test_payment_intent_retrieval_expands_latest_charge_for_receipt_evidence()` asserting the source contains `"'expand' => ['latest_charge']"`.

**New test file — no existing file honestly owns these proofs:**

- **`tests/Feature/Usage/ReceiptBoundaryTest.php`**:
  - `test_business_billing_receipts_schema_matches_the_rfc_exactly()` — six columns, both FKs' `restrictOnDelete()`, no `updated_at`.
  - `test_business_billing_receipts_has_no_extra_unique_or_convenience_index()` — no `UNIQUE(ledger_entry_id)`, no `business_id` index, beyond the implicit PK.
  - `test_slot_agreement_flows_never_create_a_business_billing_receipt()` — runs an initial slot-agreement Checkout confirmation (reusing the existing slot-agreement test fixture pattern) and asserts zero `business_billing_receipts` rows and zero `SendReceiptNotification` dispatches.
  - `test_ensure_funding_receipt_resolves_evidence_for_a_checkout_backed_attempt()`.
  - `test_ensure_funding_receipt_resolves_evidence_for_a_payment_intent_backed_attempt()`.
  - `test_ensure_funding_receipt_returns_null_and_makes_no_write_when_a_receipt_already_exists()` — proves the no-provider-call short-circuit.
  - `test_a_manually_redispatched_send_receipt_notification_persists_the_receipt_after_a_prior_evidence_failure()` — the exact recovery proof for §7.
  - `test_notification_opt_out_still_persists_the_receipt_but_sends_no_mail()`.
  - `test_missing_billing_contact_still_persists_the_receipt_but_sends_no_mail()`.
  - `test_no_code_path_in_this_correction_references_the_legacy_invoices_table()` — a source-scan assertion over the exact file list in §12.
  - `test_provider_receipt_url_is_always_the_verbatim_stripe_value_never_locally_constructed()`.
  - `test_receipt_boundary_tests_bind_only_the_fake_gateway_never_the_real_stripe_gateway()` — a source-scan assertion over this file's own `setUp()`, matching the same convention every other `tests/Feature/Usage/*` file already follows.

No test file beyond the eight reused files and this one new file is authorized.

---

## 11. Human-review decisions — now closed (per the assigning review's own authority)

1. **`Refund` receipt rows: NO in this correction.** Closed.
2. **`DisputeChargeback` receipt rows: NO in this correction.** Closed.
3. **`UNIQUE(ledger_entry_id)`: NO. RFC §23 schema preserved exactly.** Closed.
4. **The knowingly-unrecoverable `AutoRecharge` receipt gap from Round 0: NOT ACCEPTED, and resolved.** The corrected architecture (§3/§7) gives every receipt-eligible flow, including every `AutoRecharge` entry point, the identical, fully recoverable job-based path — no purpose-specific gap remains.

No other human decision is open. No new decision point was discovered during this correction that the assigning review had not already resolved.

---

## 12. Explicit exclusions (unchanged from Round 0)

Job/Event Dispatch Completion work except `SendReceiptNotification` itself; `BusinessFundingAttemptSucceeded`/`BusinessFundingAttemptFailed` event dispatch; `SendLowBalanceNotification`/`SendAutoRechargeDisabledNotification`; `ExpireStaleUsageReservations` scheduling; Admin Usage Billing Surface; `ManualCredit`/`PromotionalCredit`/`UsageChargeReversal`/`CorrectionReversal` admin surface; executable provider refund/dispute handling beyond §4 rows 8–9's closed determination; add-on HTTP routes/controllers/views; M6 conformance docs; deployment docs; tag work; Conversations pilot activation; tax/VAT implementation; legacy `invoices` table changes; a dedicated receipt-evidence backfill/reconciliation job (superseded — no longer needed at all, since the queued job itself is now the recovery mechanism, §7); any new `UNIQUE`/index addition to `business_billing_receipts` (§11, closed as "no").

---

## 13. Exact future production allowlist — 16 files, individually listed, no grouping, no placeholder

1. `database/migrations/2026_08_27_120001_create_business_billing_receipts_table.php` — new.
2. `app/Models/BusinessBillingReceipt.php` — new.
3. `app/Repositories/Contracts/BusinessBillingReceiptRepository.php` — new.
4. `app/Repositories/Eloquent/EloquentBusinessBillingReceiptRepository.php` — new.
5. `app/Providers/AppServiceProvider.php` — one new binding line.
6. `app/Library/Usage/UsageWalletManager.php` — `creditFromFunding()` widened (§3); `attachFundingReceipt()`, `findFundingReceipt()` added (§6).
7. `app/Library/Usage/UsageBillingCheckoutManager.php` — `ensureFundingReceipt()` added (§6). No other method modified.
8. `app/Library/Usage/StripePaymentProviderGateway.php` — `retrievePaymentIntent()`/`retrieveCheckoutSession()` expand widened; DTO mapping populates two new fields (§8).
9. `app/Library/Usage/FakePaymentProviderGateway.php` — `registerPaymentIntentResult()` + registry; deterministic default receipt fields (§8).
10. `app/Library/Usage/PaymentIntentResult.php` — two new trailing nullable fields.
11. `app/Library/Usage/CheckoutSessionResult.php` — two new trailing nullable fields.
12. `app/Jobs/Usage/SendReceiptNotification.php` — new.
13. `app/Notifications/Usage/ReceiptAvailableNotification.php` — new.
14. `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` — `findById()`, `findForUpdateById()` added (§9).
15. `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php` — same two methods implemented.
16. `app/Exceptions/Usage/ReceiptEvidenceUnavailableException.php` — new.

**`app/Library/Usage/Contracts/PaymentProviderGateway.php` is confirmed NOT REQUIRED** — no interface signature change (§8). **`app/Jobs/Usage/ProcessPaymentProviderEvent.php` is confirmed NOT REQUIRED** — it delegates entirely to `UsageBillingCheckoutManager::confirmAttemptFromWebhook()`, which this correction does not modify. **`app/Jobs/Usage/ReconcileProviderPendingState.php` is confirmed NOT REQUIRED** — it delegates entirely to `confirmAttemptFromReturn()`, likewise unmodified.

No production path beyond these 16 is authorized without a further human-authorized correction round.

---

## 14. Exact future test/support allowlist — 9 files, individually listed

1. `tests/Feature/Usage/TopUpStateMachineTest.php` — reused, 1 new method.
2. `tests/Feature/Usage/FundingAttemptPayerConsentTest.php` — reused, 2 extended methods + 1 new method.
3. `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` — reused, 2 new methods.
4. `tests/Feature/Usage/FundingAttemptExactlyOnceWalletCreditTest.php` — reused, 1 new method.
5. `tests/Feature/Usage/CrossBusinessPaymentIsolationTest.php` — reused, 1 new method.
6. `tests/Feature/Usage/RedirectBeforeWebhookConfirmationTest.php` — reused, 2 new methods.
7. `tests/Feature/Usage/FakePaymentProviderGatewayTest.php` — reused, 3 new methods.
8. `tests/Feature/Usage/StripePaymentProviderGatewayCompatibilityTest.php` — reused, 1 corrected assertion + 1 new method.
9. `tests/Feature/Usage/ReceiptBoundaryTest.php` — new file, 12 methods (§10).

No test/support path beyond these 9 is authorized.

---

## 15. Future implementation gates (unchanged, locked verbatim)

```
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan migrate:fresh --env=testing
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Unit/Usage tests/Feature/Usage
git diff --check
```

Only `ultimatesms_testing` may be used. No real Stripe credentials. No live Stripe network request — every reused/new test binds `FakePaymentProviderGateway` exclusively, confirmed mechanically by `ReceiptBoundaryTest.php`'s own dedicated proof (§10).

---

## 16. Contract-branch validation (run and confirmed before this round's commit)

```
git status --short
git diff --check
git diff --name-only origin/main...HEAD
```

The only changed tracked path is `docs/automation/RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md` — confirmed in the final report below.

---

*This contract authorizes drafting review only. Implementation on `agent/rfc-005-receipt-boundary-correction` requires a separate, explicit human instruction issued after this document is human-merged.*
