# RFC-005 Receipt Boundary Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction that gives RFC-005 §23's `business_billing_receipts` table, §28's `UsageWalletManager` receipt write authority, and §29's `App\Jobs\Usage\SendReceiptNotification` job their first real implementation contract. Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch named in §0, exactly as every RFC-005 milestone and correction contract before it (most recently the Funding Provider-Flow correction, [`RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md`](./RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md), merged PR [#137](https://github.com/os-creator1/os-ai/pull/137)) has required.

This correction exists because the M6 static conformance audit found Receipt Boundary to be a real blocking gap: `business_billing_receipts` has no migration, model, or repository; no `SendReceiptNotification` job exists; and neither `PaymentIntentResult` nor `CheckoutSessionResult` exposes any receipt evidence. This contract is remediation #3 of 7; it does not by itself unblock M6.

---

## Correction Round 1 record

Independent pre-merge review of the initial draft (head `9b7b62ed56878152d36e93bb60b6cdad00f4829f`) found one architectural blocker (a synchronous `AutoRecharge` success path was omitted) and ten supporting defects, resolved by replacing the receipt-recovery architecture entirely: the receipt job is dispatched from inside `UsageWalletManager::creditFromFunding()`'s own transaction rather than from any purpose-specific confirmation branch, mechanically covering all five financial-success entry points. **1 of 2 ordinary correction rounds consumed by that round.**

---

## Correction Round 2 record

Independent pre-merge review of the Round-1 draft (head `03081784c3d7f41ddc1c1722fb69441a958a09c6`) found eleven further defects, all resolved below by direct mechanical re-audit of the entire `tests/Unit/Usage`/`tests/Feature/Usage` suite, `phpunit.xml`, and every relevant model/manager file. **This independent review failure consumes Correction Round 2 — the final ordinary round. 2 of 2 ordinary correction rounds consumed; 0 ordinary rounds remain.** The contract remains `PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE`; no implementation authorization exists at any point during this correction.

Exact issues resolved this round:

1. **Incomplete test/support allowlist.** Direct evidence: `phpunit.xml` line 37 sets `<server name="QUEUE_CONNECTION" value="sync"/>` for the entire PHPUnit run. Empirically verified (via a throwaway, since-deleted diagnostic job dispatched `->afterCommit()` inside a `DB::transaction()` under `RefreshDatabase`, in this exact repository/Laravel-12 installation) that **an `afterCommit()`-dispatched job on the `sync` queue driver executes inline during a `RefreshDatabase`-wrapped test** — it is not silently discarded at rollback. This means every existing test that reaches a receipt-eligible `creditFromFunding()` call, without `Queue::fake()`, now also executes the new `SendReceiptNotification` job inline, which will fail closed on missing receipt evidence unless that test's own provider fixture supplies it. A full mechanical grep (`registerCheckoutSessionResult(`, `creditFromFunding(`, `Queue::fake(`) across `tests/Unit/Usage` and `tests/Feature/Usage` found exactly six files directly constructing `CheckoutSessionResult` (confirmed exhaustively via `grep -rn "new (\\\\App\\\\Library\\\\Usage\\\\)?CheckoutSessionResult\("`, 8 total matches: 2 production, 6 test), zero files directly constructing `PaymentIntentResult` (2 total matches, both production — no test file ever constructs it), and exactly one file calling `creditFromFunding()` directly. §10 below names every one exactly.
2. **Two required test files were omitted from Round 1's allowlist:** `tests/Feature/Usage/PayerChangeDuringPendingAttemptTest.php` and `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` — both directly construct a successful `CheckoutSessionResult` with no receipt evidence, both reachable under `sync` queue. Added to §10/§14.
3. **Sync-queue fixture incompatibility** — resolved by giving `FakePaymentProviderGateway`'s own default/fallback construction paths deterministic non-null receipt evidence (§8), so every test relying on the Fake's own defaults (the majority of the suite) needs zero fixture changes, and by explicitly correcting every test that bypasses those defaults via direct `CheckoutSessionResult` construction (§10).
4. **Internally contradictory existing-receipt test name** — `test_ensure_funding_receipt_returns_null_and_makes_no_write_when_a_receipt_already_exists` contradicted §6's own documented return behavior (it returns the existing receipt, never `null`). Renamed and corrected (§10/§H below).
5. **Fake receipt identity falsely described as deterministic** — Round 1 proposed `'ch_fake_'.Str::random(16)`, which is not deterministic. Replaced with a stable hash-derived identity keyed to the real provider object id (§8).
6. **Missing `ShouldQueueAfterCommit` marker** — Round 1's job relied only on the inline `->afterCommit()` call at the dispatch site. The repository's own real precedent, `EvaluateBusinessAutoRecharge extends Base implements ShouldQueueAfterCommit` (confirmed by direct read), is now also applied to `SendReceiptNotification`, as static, source-level defense in depth alongside the inline call (§7).
7. **Incorrect FK-index test semantics** — Round 1's planned schema test risked asserting a physical absence of any index on `business_id`/`ledger_entry_id`, which InnoDB may create to support the two required FKs regardless of application DDL. Corrected to a source-level migration-DDL assertion only (§9/§10/§N below).
8. **False "original retrieval completely unmodified" wording** — the gateway's own `retrievePaymentIntent()`/`retrieveCheckoutSession()` methods ARE widened (their `expand` parameter changes); what is actually unmodified is the existing confirmation call sites and the payment-verification *conditions* they apply. Wording corrected (§8/§M below).
9. **Unsafe unnormalized id comparisons** — direct model read confirms `BusinessUsageLedgerEntry`'s and `BusinessFundingAttempt`'s `$casts` do not cast `business_id`/`funding_attempt_id`/`wallet_id` to `integer` (only `expected_amount_micro` and similar amount/enum columns are cast). Every cross-check in §6/§7 now uses explicit `(int)` normalization, never a bare `===`/`!==` against a raw Eloquent attribute.
10. **Missing `funding_attempt_id` mismatch proof** — only a `business_id` mismatch test was named. Added (§10/§Q below).
11. **Incomplete recipient-selection proofs** — only opt-out and missing-contact were proven; the independent-contact-email and user-backed-contact-email resolution paths were not. Added (§10/§R below), grounded in direct read of `BillingProfileManager::updateBillingContact()` (confirmed: when `contact_user_id` is set, `contact_name`/`contact_email` are both forced `null`; when it is `null`, both are stored directly).

One additional, previously-undiscovered mechanical defect found during this round's audit, disclosed per this contract's own zero-discretion standard: **`ConcurrentTopUpConcurrencyTest.php`'s existing `tearDown()` does not delete `business_billing_receipts` rows.** Since that class deliberately does not use `RefreshDatabase` (real committed rows, confirmed by its own docblock) and its three tests all reach a receipt-eligible `Succeeded` confirmation (directly or via a spawned child process), a receipt row will now be committed for real on every run; `tearDown()`'s existing deletion of `business_usage_ledger_entries`/`businesses` (both of which `business_billing_receipts` has a `restrictOnDelete()` FK against) would throw a foreign-key-constraint error without this fix. Resolved in §10.

No genuinely unresolved contradiction was found this round that could not be mechanically resolved from direct repository evidence.

---

## Exceptional post-review factual/test-harness correction

**This is NOT Correction Round 3.** `maximum_correction_rounds: 2` is unchanged. Ordinary correction rounds remain **2 of 2 consumed; 0 ordinary rounds remaining** — this exceptional pass neither resets nor increments that counter, matching the same exceptional-correction mechanism used once before in this exact engagement (the Funding Provider-Flow correction's own post-review exception). It was made under a separate, explicit human authorization covering exactly one further docs-only pass, after the ordinary-round budget was already exhausted, because final independent review found one genuine factual gap in Round 2's own test-harness modeling.

Prior head: `caba083a93c224a88e15177196606f66719e6f37`.

**Reason:** Round 2 correctly established, and empirically verified, that `phpunit.xml`'s `<server name="QUEUE_CONNECTION" value="sync"/>` makes an `afterCommit()`-dispatched job execute inline in the **main PHPUnit process**. It incorrectly generalized that same guarantee to `ConcurrentTopUpConcurrencyTest.php`'s independently spawned Symfony `Process` children, which never read `phpunit.xml` at all — that file is PHPUnit-runner-specific XML, never inherited by a bare `require bootstrap/app.php` script running in its own OS process.

**Empirically re-verified this pass** (via a second throwaway, since-deleted diagnostic script — `scratch_child_queue_probe.php`, written to the worktree root, run directly, then deleted; no scratch artifact was committed): running the exact preamble `baseRunnerPreamble()` already uses (`putenv('APP_ENV=testing')` + `$_ENV`/`$_SERVER` assignment, then `require bootstrap/app.php`) in a **fresh, standalone PHP process** resolves `config('queue.default')` to `sync`. Locating *why* revealed the real gap: this workstation's `.env.testing` happens to itself contain `QUEUE_CONNECTION=sync` (confirmed: `.env.testing` line 19) — but **`.env.testing` is explicitly `.gitignore`d** (`.gitignore` line 2: `.env.*` — and `git ls-files`/`git check-ignore -v .env.testing` both confirm it is untracked, unlike `.env.example`, `phpunit.xml`, or any file this contract's allowlist governs). The child process's `sync` behavior is therefore currently true **only because of this particular workstation's own untracked local file**, not because of anything the repository itself guarantees. A fresh clone, a CI runner, or any other machine whose `.env.testing` omits `QUEUE_CONNECTION` (falling back to `config/queue.php`'s own `database` default) would make the child process **queue** `SendReceiptNotification` instead of running it inline — silently skipping the child's own receipt-evidence fixture, and leaving a real, uncleaned `jobs` table row behind (this test class does not use `RefreshDatabase`).

**Observed throwaway value on this workstation: `sync`** — reported here exactly as observed, per instruction, but explicitly **not** the basis for the correction: the fix below is required regardless of what any given machine happens to report, because the child runner must be deterministic from tracked test source, never from an untracked, machine-local `.env.testing`.

**Corrected requirement, locked (supersedes Round 2's §10 item 6 wherever it did not already say this):** `ConcurrentTopUpConcurrencyTest::baseRunnerPreamble()` must explicitly force and verify the child's own queue connection, independent of whatever `.env.testing` a given machine happens to have:

```php
putenv('QUEUE_CONNECTION=sync');
$_ENV['QUEUE_CONNECTION'] = 'sync';
$_SERVER['QUEUE_CONNECTION'] = 'sync';
```

placed alongside (not replacing) the existing `APP_ENV=testing` lines, **before** `require '{$escapedBootstrap}'`. Immediately **after** `$kernel->bootstrap();`, add a fail-closed runtime guard:

```php
if (config('queue.default') !== 'sync') {
    fwrite(STDERR, "QUEUE_CONNECTION_NOT_SYNC\n");
    exit(1);
}
```

This guard is a single shared addition to `baseRunnerPreamble()` (used by both `confirmRunnerScript()` and `holdUntilSignalRunnerScript()`, matching the class's existing "one preamble, two scripts" structure) — it does not need to be duplicated per script. Its purpose: every spawned confirmation child deterministically runs `SendReceiptNotification` inline, immediately after its own accounting commit, so the already-locked child receipt-evidence fixture (`ch_fake_child_confirm`, §10 below, unchanged) is genuinely exercised, the receipt row is genuinely persisted, and — because the child never queues the job at all — **no `jobs`-table row is ever created by these children, so no `jobs`-table cleanup is required.** `tearDown()`'s Round-2 `business_billing_receipts` cleanup (unchanged, still placed before the existing `business_usage_ledger_entries` deletion) remains sufficient on its own; it is not a workaround for an uncontrolled child queue mode — the queue mode itself is now fixed at the source.

**Explicitly not changed by this exception:** the child runner remains a genuine separate OS process (never converted to an in-process simulation); `Queue::fake()` is never used in the confirming child (the job must genuinely execute there, not merely be recorded as dispatched); the already-locked child (`ch_fake_child_confirm`) and parent (`ch_fake_dup_confirm`) receipt-evidence fixtures are unchanged; the `tearDown()` `business_billing_receipts` cleanup line is unchanged; the real cross-process wallet-lock concurrency proof is unchanged; no new test method or test file is introduced; the production allowlist (16) and test/support allowlist (11) are both unchanged in count. §10 item 6 below is restated in full to include this exact fix, superseding its Round-2 wording.

No product or test code changes and no implementation authorization are granted by this exception. `AI-AUTONOMY-STATE.json` is untouched. M6 remains frozen. This governance branch still changes exactly one file.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-receipt-boundary-correction-contract`, in an isolated linked worktree (`../rfc-005-receipt-boundary-contract-worktree`), based on `origin/main` at `1eba17ae4112a1e5e832627d44c185a0ee3f56ca` — the Funding Provider-Flow correction's own merge commit (PR [#137](https://github.com/os-creator1/os-ai/pull/137)), reconfirmed via `git fetch origin main && git rev-parse origin/main` at the start of this correction round, unchanged since initial drafting.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-receipt-boundary-correction`.**
- Confirmed at this correction round: no `agent/rfc-005-receipt-boundary-correction` branch exists on `origin`; no product/test/config/route/RFC-source file has been touched by this branch at any point (the diagnostic job used to empirically verify §Round-2-item-1 was written, run, and deleted entirely within this session's own throwaway scratch work — never committed, never part of this branch's tracked diff).
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - **Correction rounds: 2 of 2 consumed as of this round; 0 ordinary rounds remain.**
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - `M6 remains frozen` — untouched, not resumed, no M6 document created or modified. **This correction does, however, place one exact requirement on M6's own future deployment/readiness documentation** (§11 item 5) — a requirement stated here, not written into any M6 document by this branch.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch.
  - **Implementation requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This is a new, independently bounded pre-M6 correction contract — not a correction round against M1–M6's own contracts, and not a correction round against the already-merged Reservation Admission or Funding Provider-Flow contracts. Its `maximum_correction_rounds: 2` budget is its own; no counter is borrowed or altered on any other contract.
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`.

---

## 1. Preserved facts (Round 0/1 findings, re-confirmed unchanged this round)

**§23 — `business_billing_receipts`, confirmed verbatim:**

| Column | Type | Nullable | Default |
|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — |
| `ledger_entry_id` | `unsignedBigInteger`, FK `business_usage_ledger_entries.id`, `restrictOnDelete()` | No | — |
| `provider_receipt_url` | `string(2048)` | No | — |
| `provider_reference` | `string(191)` | No | — |
| `created_at` | `timestamp` | No | `now()` |

No `updated_at`.

1. Stripe-hosted receipts remain authoritative for v1; legacy `invoices` never reused.
2. No application-authored `UNIQUE(ledger_entry_id)`.
3. No application-authored convenience `index('business_id')`.
4. `provider_receipt_url` from Stripe `Charge.receipt_url`; `provider_reference` is the Stripe Charge id (`ch_...`), never PaymentIntent/Session id.
5. Receipts: YES for `ManualTopUp`, `AutoRecharge`, `AddonPurchase` `wallet_credit`. NO (closed decision) for `AddonPurchase` `direct_deliverable`, all slot-agreement charges, `Refund`, `DisputeChargeback`.
6. `UsageWalletManager` remains sole write authority for `business_billing_receipts`.
7. `creditFromFunding()` captures the newly-created ledger row and dispatches `SendReceiptNotification` after commit.
8. `SendReceiptNotification` carries `fundingAttemptId`/`ledgerEntryId`.
9. `ensureFundingReceipt()` owns provider retrieval; persistence is delegated to `UsageWalletManager`.
10. Receipt retrieval failure never rolls back already-committed accounting.
11. No local invoice/receipt document; no legacy `invoices` reuse.
12. No new provider-payment flow or payment-instrument behavior.
13. Migration path: `database/migrations/2026_08_27_120001_create_business_billing_receipts_table.php` — re-confirmed non-colliding against current `main`'s migration sequence this round.

---

## 2. Mechanical fact: every receipt-eligible credit passes through exactly one method (unchanged, re-confirmed)

`UsageWalletManager::creditFromFunding()` is the sole method reached by all three receipt-eligible flows (`ManualTopUp`, `AutoRecharge`, `AddonPurchase` `wallet_credit`), across all five financial-success entry points (ManualTopUp/AddonPurchase return confirmation, AutoRecharge webhook confirmation, AutoRecharge administrator/reconciliation resume, AutoRecharge synchronous success) — confirmed unchanged by this round's audit.

---

## 3. Corrected receipt-dispatch architecture (unchanged from Round 1)

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

No outbound provider call occurs inside this transaction or method. Return type, parameters, and every other line of `creditFromFunding()` are otherwise unchanged. Receipt-evidence retrieval never happens here — it happens later, inside the queued job's delegation to `ensureFundingReceipt()` (§6).

**Now empirically confirmed (Round 2 item 1), not merely asserted:** this dispatch executes inline, under the `sync` queue driver PHPUnit uses (`phpunit.xml`), even inside a `RefreshDatabase`-wrapped test — **for the main PHPUnit process.** `phpunit.xml` is PHPUnit-runner-specific configuration; it is never read by a bare `require bootstrap/app.php` script running in an independently spawned OS process. `ConcurrentTopUpConcurrencyTest.php`'s own spawned Symfony `Process` children (see §10 item 6, corrected by the exceptional post-review pass above) therefore need their own explicit, source-level `QUEUE_CONNECTION=sync` propagation and a fail-closed runtime assertion — they cannot rely on `phpunit.xml` at all, and must not rely on whatever an untracked, machine-local `.env.testing` happens to contain either. §10 accounts for both the main-process and child-process cases exhaustively.

---

## 4. Receipt-producing flow matrix (unchanged)

| # | Flow | Receipt row? | `SendReceiptNotification`? |
|---|---|---|---|
| 1 | `ManualTopUp` | **YES** | **YES** |
| 2 | `AutoRecharge` (all four entry points) | **YES** | **YES** |
| 3 | `AddonPurchase` — `wallet_credit` | **YES** | **YES** |
| 4 | `AddonPurchase` — `direct_deliverable` | NO — mechanically impossible | NO |
| 5–7 | Any additional-slot agreement charge (initial/scheduled/mid-period) | NO — mechanically impossible | NO |
| 8 | `Refund` | NO — closed human-review decision | NO |
| 9 | `DisputeChargeback` | NO — closed human-review decision | NO |
| 10 | Any other RFC-005-defined provider-backed path | NONE FOUND | — |

---

## 5. Receipt cardinality / idempotency — no index, row-lock convergence (unchanged)

No `UNIQUE(ledger_entry_id)`, no convenience `business_id` index — application-authored DDL only (§9/§N clarifies the physical-schema distinction). `UsageWalletManager::attachFundingReceipt()` locks the already-existing `business_usage_ledger_entries` row via `findForUpdateById()` (mirroring `findForUpdateByBusinessId()`'s existing convention), then checks/creates the receipt row inside that lock — the sole convergence mechanism, unchanged from Round 1.

---

## 6. `UsageWalletManager` and `UsageBillingCheckoutManager` — exact method signatures (id-normalization corrected this round)

**`UsageWalletManager`:**

```php
public function attachFundingReceipt(
    int $ledgerEntryId,
    int $fundingAttemptId,
    int $businessId,
    string $providerReceiptUrl,
    string $providerReference,
): BusinessBillingReceipt
```

- Opens one `DB::transaction()`. Locks `business_usage_ledger_entries` via `findForUpdateById($ledgerEntryId)`. If null, throws `\InvalidArgumentException`.
- **Validates using integer-normalized comparisons, corrected this round:** `(int) $ledgerEntry->business_id === $businessId` and `(int) $ledgerEntry->funding_attempt_id === $fundingAttemptId` — never a bare `===` against the raw (uncast, string-from-PDO) attribute. **If `$ledgerEntry->funding_attempt_id` is `null`, this fails closed** (a `null` never satisfies the integer-normalized equality against any real `$fundingAttemptId`) — never silently coerced to a valid id. Throws `\InvalidArgumentException` on any mismatch.
- Checks `BusinessBillingReceiptRepository::findByLedgerEntryId($ledgerEntryId)`. If found, **returns the existing row unchanged — never `null`.**
- Otherwise creates and returns one new row.
- Never called with an open outbound-provider call in flight.

```php
public function findFundingReceipt(int $ledgerEntryId): ?BusinessBillingReceipt
```

Thin, unlocked read wrapper — used by `ensureFundingReceipt()` to avoid a wasted provider call when a receipt already exists.

**`UsageBillingCheckoutManager`:**

```php
public function ensureFundingReceipt(
    BusinessFundingAttempt $attempt,
    int $ledgerEntryId,
): BusinessBillingReceipt|null
```

Exact behavior:

1. `$existing = $this->walletManager->findFundingReceipt($ledgerEntryId);` — if non-null, **return it immediately — no provider call, no write.** (Round-2 correction: this is a `?BusinessBillingReceipt` return with a non-null result on the existing-receipt path; `null` is returned only from step 4 below, on genuinely unavailable evidence — the method's overall return type stays `?BusinessBillingReceipt`, but "existing receipt" and "no evidence yet" are never conflated in either code or test naming, per §H.)
2. Branch by `$attempt->purpose`: `ManualTopUp`/`AddonPurchase` call `retrieveCheckoutSession()` and require object-id match plus `status === 'complete' && paymentStatus === 'paid'`; `AutoRecharge` calls `retrievePaymentIntent()` and requires object-id match plus `status === 'succeeded'`. Any provider exception propagates uncaught to the job (§7).
3. If the check in step 2 fails, return `null`.
4. Read `$receiptUrl`/`$receiptChargeId` off the DTO. If either is empty/null, return `null` — evidence unavailable, not an error.
5. Otherwise call `$this->walletManager->attachFundingReceipt($ledgerEntryId, (int) $attempt->id, (int) $attempt->business_id, $receiptUrl, $receiptChargeId)` and return its result. **Round-2 correction:** `$attempt->id`/`$attempt->business_id` are explicitly cast `(int)` here too, even though Eloquent's own primary-key cast already normalizes `id` — the explicit cast is kept for `business_id` (not auto-normalized) and for symmetry/readability with `id`.
6. Never calls `BusinessBillingReceiptRepository` directly.

**Payment-verification wording, corrected this round (§M):** existing confirmation call sites (`confirmAttemptFromReturn()`, `confirmAttemptFromWebhook()`, `retryFundingAttemptAsAdministrator()`) are unchanged, and so are the exact conditions they use to decide payment success — those conditions never read the two new receipt fields. What **does** change is the underlying gateway method these call sites already invoke (`retrievePaymentIntent()`/`retrieveCheckoutSession()`): its own `expand` parameter widens, so the DTO it returns now opportunistically carries receipt evidence too. That evidence is simply unread by the existing verification logic. `ensureFundingReceipt()` (step 2 above) performs its **own**, separate, later call through the same widened gateway methods when receipt persistence is still needed — it does not reuse or depend on the original verification call's own DTO instance.

---

## 7. `SendReceiptNotification` — exact job design (id-normalization and `ShouldQueueAfterCommit` corrected this round)

```php
use App\Jobs\Base;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class SendReceiptNotification extends Base implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly int $fundingAttemptId,
        private readonly int $ledgerEntryId,
    ) {}

    public function handle(...): void { /* below */ }
}
```

**Round-2 correction (§K):** the class now explicitly `implements ShouldQueueAfterCommit`, matching the repository's own real precedent (`EvaluateBusinessAutoRecharge extends Base implements ShouldQueueAfterCommit`, confirmed by direct read) — static, source-level, defense-in-depth alongside the inline `->afterCommit()` call at the dispatch site (§3). **Both are kept; neither is removed as redundant.** `Base` already `implements ShouldQueue`; this class does not redeclare it.

**`handle()`, exact sequence:**

1. Load `$attempt = BusinessFundingAttemptRepository::findById($this->fundingAttemptId)`. If null, throw `\RuntimeException`.
2. Require `$attempt->state === FundingAttemptState::Succeeded` — throw `\RuntimeException` otherwise.
3. Load `$ledgerEntry = BusinessUsageLedgerEntryRepository::findById($this->ledgerEntryId)`. If null, throw `\InvalidArgumentException`. **Round-2 correction — integer-normalized, fail-closed cross-check:** if `(int) $ledgerEntry->funding_attempt_id !== (int) $attempt->id` or `(int) $ledgerEntry->business_id !== (int) $attempt->business_id`, throw `\InvalidArgumentException`. A `null` `funding_attempt_id`/`business_id` on the loaded ledger entry fails this check (never coerced to match).
4. `$receipt = $this->checkoutManager->ensureFundingReceipt($attempt, $this->ledgerEntryId);`
5. **If `$receipt === null`:** throw `App\Exceptions\Usage\ReceiptEvidenceUnavailableException` — the job fails clearly, `Base`'s `$tries = 1` is exhausted. Accounting is never touched.
6. Receipt now exists. Only now does notification-preference evaluation begin.
7. Load billing contact: `BusinessBillingContactRepository::findByBusinessId($attempt->business_id)`. Missing: log `info`, return.
8. `notification_opt_in === false`: log `info`, return.
9. **Recipient resolution — confirmed against `BillingProfileManager::updateBillingContact()`'s own real write behavior (direct read: when `contact_user_id` is set, `contact_name`/`contact_email` are both forced `null`; when `contact_user_id` is `null`, both are stored directly):** use `contact_email` when `contact_user_id === null`; otherwise resolve the `contactUser()`-linked `User`'s own `email`. If that `User` cannot be resolved or has no usable email (a data-integrity edge case, not an assumed-impossible state — the FK does not itself guarantee a non-empty `email`), log `warning`, return — never throws, never invents a fallback address.
10. Send via `Notification::route('mail', $email)->notify(new \App\Notifications\Usage\ReceiptAvailableNotification($receipt->provider_receipt_url));`.

**Recoverability — sync-queue vs. durable-queue boundary, stated precisely this round (§L):**

1. The accounting transaction (§3) commits independently of receipt persistence — always true, regardless of queue connection.
2. The job is idempotently re-dispatchable using `fundingAttemptId`+`ledgerEntryId` alone — always true.
3. **With a durable, asynchronous queue connection** (e.g. `database`, `redis` — `config/queue.php`'s own default fallback is `database` when `QUEUE_CONNECTION` is unset) **whose failures are recorded by Laravel's configured failed-job provider** (the `failed_jobs` table/migration and `config/queue.php`'s `'failed'` block are both confirmed present in this repository): a provider/evidence failure can be operationally retried through the existing failed-job tooling (`php artisan queue:retry`, or a direct manual re-dispatch).
4. **With the `sync` queue connection** (confirmed set for the entire PHPUnit run via `phpunit.xml`; also `.env.example`'s own suggested local-dev default): **no durable failed-job row is guaranteed merely by this contract.** The job's exception surfaces synchronously, in-process, immediately after the accounting transaction has already committed, to whatever code path triggered the credit (an HTTP request, a webhook-processing job, a console command) — that caller's own existing exception handling/reporting applies; this correction does not add a new one.
5. **Therefore: RFC-005 M6's own future deployment/readiness documentation must require the production Usage-billing deployment to use a durable queue connection, not `sync`, before Receipt Boundary is considered operationally recoverable in production.** This correction does **not** write that requirement into any M6 document (M6 remains frozen, §0) and does **not** modify `config/queue.php`, `.env.example`, or `phpunit.xml` — it states the requirement here, for a human to carry into M6's own contract when that milestone is authorized.
6. No specific automatic retry count is claimed. The honest guarantee: manual/operator re-dispatch, however triggered, is always safe and correct, because the job is fully idempotent and financial accounting is never part of it.

**Duplicate-dispatch/delivery semantics — unchanged from Round 1:** application dispatch idempotency is guaranteed (one ledger row → one dispatch); exact-once mail delivery is explicitly not claimed (at-least-once queue/mail transport, no notification-state column in the RFC schema).

---

## 8. DTO / gateway boundary — exact additions (deterministic identity and call-recording corrected this round)

- **`PaymentIntentResult`**/**`CheckoutSessionResult`** — each gain trailing `public ?string $receiptUrl = null, public ?string $receiptChargeId = null`.
- **Corrected DTO-construction audit (§O), mechanically re-verified this round** (`grep -rn "new (\\\\App\\\\Library\\\\Usage\\\\)?CheckoutSessionResult\("`/`PaymentIntentResult\(` across the whole repository): `CheckoutSessionResult` has **8** direct construction sites — `app/Library/Usage/FakePaymentProviderGateway.php`, `app/Library/Usage/StripePaymentProviderGateway.php`, and exactly six test files: `tests/Feature/Usage/TopUpStateMachineTest.php`, `tests/Feature/Usage/PayerChangeDuringPendingAttemptTest.php`, `tests/Feature/Usage/FundingAttemptPayerConsentTest.php`, `tests/Feature/Usage/FundingAttemptExactlyOnceWalletCreditTest.php`, `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php`, `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php`. `PaymentIntentResult` has **2** direct construction sites, both production (`FakePaymentProviderGateway.php`, `StripePaymentProviderGateway.php`) — **no test file constructs it directly**, confirmed by the same mechanical grep. Round 1's "exactly four construction sites" claim is retracted as false (it used a grep pattern that silently missed every fully-qualified-namespace `new \App\Library\Usage\CheckoutSessionResult(...)` call site).
- **`PaymentProviderGateway.php` — no change**, confirmed again this round.
- **`StripePaymentProviderGateway`** — `retrievePaymentIntent()` gains `['expand' => ['latest_charge']]`; `retrieveCheckoutSession()`'s expand becomes `['payment_intent.payment_method', 'payment_intent.latest_charge']`. Fail-closed mapping unchanged from Round 1 (§M restates the wording precisely; behavior is unchanged).
- **`FakePaymentProviderGateway` — corrected this round for deterministic identity and call recording:**
  - **Deterministic receipt-evidence derivation (§J), replacing Round 1's random default:**
    ```php
    private function fakeReceiptChargeId(string $providerObjectId): string
    {
        return 'ch_fake_'.substr(hash('sha256', $providerObjectId), 0, 24);
    }

    private function fakeReceiptUrl(string $providerObjectId): string
    {
        return 'https://fake.stripe.test/receipts/'.$this->fakeReceiptChargeId($providerObjectId);
    }
    ```
    The same `$providerObjectId` (the PaymentIntent id for `retrievePaymentIntent()`; the Checkout Session id for `retrieveCheckoutSession()`) always yields the same `receiptChargeId`/`receiptUrl` — no `Str::random()` anywhere in this derivation. **Explicitly registered `PaymentIntentResult`/`CheckoutSessionResult` values (via the two `register*()` methods) are always returned verbatim, including an explicitly `null` receipt field a test deliberately registers to simulate missing evidence — the deterministic helpers above apply only to the Fake's own unregistered/default construction paths, never overriding an explicit registration.**
  - New `registerPaymentIntentResult(PaymentIntentResult $result): void` + registry keyed by `providerPaymentIntentId`. `retrievePaymentIntent($id)` checks this registry first; if absent, returns `new PaymentIntentResult($id, 'succeeded', null, 0, 'USD', $this->fakeReceiptUrl($id), $this->fakeReceiptChargeId($id))` (its existing hardcoded-succeeded shape, now also deterministically receipt-bearing).
  - `retrieveCheckoutSession()`'s **both** existing fallback branches — the "unknown session" branch and the known-session outcome-driven branch — gain the same two deterministic fields (seeded by `$providerCheckoutSessionId` in the unknown-session branch, and by the session's own id in the outcome-driven branch), applied only when the per-call outcome array does not already carry explicit `receiptUrl`/`receiptChargeId` keys (mirroring the existing optional-key pattern already used for `providerPaymentMethodId`).
  - `registerCheckoutSessionResult()` is unchanged in shape.
  - **New call-recording (§I), removing all test-author discretion:**
    ```php
    public array $retrievePaymentIntentCalls = [];
    public array $retrieveCheckoutSessionCalls = [];
    ```
    `retrievePaymentIntent($id)` appends `$id` to `$retrievePaymentIntentCalls` before returning. `retrieveCheckoutSession($id)` appends `$id` to `$retrieveCheckoutSessionCalls` before returning. No interface change; no new Fake class; no anonymous class or Mockery spy used anywhere in this contract's own test plan.

---

## 9. Model / repository / migration — exact paths (unchanged from Round 1; FK-index semantics clarified)

- **Migration:** `database/migrations/2026_08_27_120001_create_business_billing_receipts_table.php` — creates exactly the six §1 columns, the two `restrictOnDelete()` FKs, no `->unique(`, no standalone `->index(`.
- **Physical-schema clarification (§N), stated explicitly to avoid a false test assertion:** InnoDB may create or reuse a supporting index for either FK column as part of ordinary foreign-key enforcement. **That is a MySQL/InnoDB implementation detail, not an application-authored uniqueness or convenience decision**, and this contract's own "no extra index" commitment (§1 items 2–3) refers strictly to the migration's own Blueprint calls, never to `SHOW INDEX`'s physical output. §10's schema test is written against the migration's own source, not a live `SHOW INDEX` query, so this distinction is enforced mechanically, not just in prose.
- **Model:** `app/Models/BusinessBillingReceipt.php` — `$timestamps = false`, `$fillable`, both `belongsTo()` relations. Unchanged from Round 1.
- **Repository contract/implementation:** `BusinessBillingReceiptRepository`/`EloquentBusinessBillingReceiptRepository` — `findById()`, `findByLedgerEntryId()`, `create()`. No `update()`. Unchanged.
- **`AppServiceProvider`:** one new binding line. Unchanged.
- **`BusinessUsageLedgerEntryRepository`:** two new read-only methods, `findById()` and `findForUpdateById()`. Unchanged from Round 1.
- **New exception:** `app/Exceptions/Usage/ReceiptEvidenceUnavailableException.php`. Unchanged.

---

## 10. Test ownership — exact paths, exact method names, exact existing-fixture corrections (fully re-audited this round)

### VERIFY-ONLY / NOT MODIFIED (documented, not part of the modifiable allowlist, per §W)

- **`tests/Feature/Usage/AutoRechargeLoopPreventionTest.php`** — both of its tests (`test_crediting_from_a_confirmed_auto_recharge_does_not_dispatch_another_evaluation`, `test_crediting_from_a_confirmed_manual_top_up_does_not_dispatch_an_evaluation_either`) already call `Queue::fake()` before their own direct `creditFromFunding()` call, confirmed by direct read. The new `SendReceiptNotification::dispatch()` inside `creditFromFunding()` is therefore captured by the same fake and never executes; both tests assert only `Queue::assertNotPushed(EvaluateBusinessAutoRecharge::class)`, unaffected by an additional, different job also being pushed. **No modification required.**
- **`tests/Feature/Usage/RedirectBeforeWebhookConfirmationTest.php`'s existing method**, `test_a_bare_redirect_return_with_no_confirmation_call_never_credits_the_wallet` — confirmed by direct read: it calls only `initiateTopUp()`, never `confirmAttemptFromReturn()`/a webhook, and asserts the attempt never leaves `ProviderPending` and the ledger stays empty. It never reaches `creditFromFunding()`. **No modification required** (the file itself is still modified — via two new methods, below).

### Reused existing files — every existing fixture/method requiring a change, named exactly

1. **`tests/Feature/Usage/TopUpStateMachineTest.php`** — the shared private helper `registerVerifiedCheckoutOutcome(BusinessFundingAttempt $attempt, ?string $paymentMethodCustomerId = null)` (used by 4 existing call sites: lines exercising `test_checkout_backed_attempt_starts_with_the_pending_checkout_sentinel_and_finalizes_it_on_confirmation` and three tests in the file's own PaymentMethod-linkage section) gains two trailing constructor args on its own `new CheckoutSessionResult(...)` call: `receiptUrl: 'https://fake.stripe.test/receipts/ch_fake_topup_verified'`, `receiptChargeId: 'ch_fake_topup_verified'` — one fixture edit, all 4 call sites fixed. New method: `test_manual_top_up_success_dispatches_exactly_one_send_receipt_notification()`.
2. **`tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php`** — its own shared private helper `registerVerifiedCheckoutOutcome(BusinessFundingAttempt $attempt)` (used by 3 existing call sites) gains the same two trailing stable values (`ch_fake_addon_verified`) — one fixture edit, 3 call sites fixed. New methods: `test_wallet_credit_addon_purchase_dispatches_exactly_one_send_receipt_notification()`, `test_direct_deliverable_addon_purchase_dispatches_no_receipt_notification()`.
3. **`tests/Feature/Usage/FundingAttemptPayerConsentTest.php`** — **two** existing inline `new \App\Library\Usage\CheckoutSessionResult(...)` construction sites, no shared helper, each corrected individually: `test_platform_administrator_can_resume_a_stuck_attempt()`'s own registration gains `receiptUrl: 'https://fake.stripe.test/receipts/ch_fake_admin_resume'`, `receiptChargeId: 'ch_fake_admin_resume'`; `test_completing_a_top_up_never_enables_auto_recharge()`'s own registration gains `receiptUrl: 'https://fake.stripe.test/receipts/ch_fake_no_auto_recharge'`, `receiptChargeId: 'ch_fake_no_auto_recharge'`. Extend `test_auto_recharge_still_creates_an_off_session_payment_intent_and_snapshots_the_instrument_at_creation()` (the synchronous-success test, no explicit registration needed — it relies on the Fake's own deterministic default) and `test_auto_recharge_webhook_confirmation_performs_no_new_provider_call()` with a `Queue::fake()` + `Queue::assertPushed(SendReceiptNotification::class, ...)` assertion each. New method: `test_platform_administrator_resuming_a_stuck_auto_recharge_attempt_dispatches_the_receipt_job()` — covers the admin/reconciliation `AutoRecharge` entry point (not exercised by the file's existing, `ManualTopUp`-scoped admin-resume test), using the Fake's own deterministic `retrievePaymentIntent()` default (no explicit registration needed).
4. **`tests/Feature/Usage/FundingAttemptExactlyOnceWalletCreditTest.php`** — its one existing inline registration, in `test_synchronous_confirmation_then_a_duplicate_webhook_credits_exactly_once()`, gains `receiptUrl: 'https://fake.stripe.test/receipts/ch_fake_exactly_once'`, `receiptChargeId: 'ch_fake_exactly_once'`. New method: `test_repeated_receipt_attachment_for_the_same_ledger_entry_is_idempotent()`.
5. **`tests/Feature/Usage/PayerChangeDuringPendingAttemptTest.php`** (added to the allowlist this round, §D) — its one existing inline registration, in `test_an_in_flight_attempts_payer_snapshot_is_unaffected_by_a_later_payer_change()`, gains `receiptUrl: 'https://fake.stripe.test/receipts/ch_fake_payer_change'`, `receiptChargeId: 'ch_fake_payer_change'`. **No new test method** — the payer-snapshot invariant itself is fully preserved and is the file's only real scope; this is a pure fixture correction.
6. **`tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php`** (added to the allowlist in Round 2; corrected once more by the exceptional post-review pass above — this entry supersedes the Round-2 wording in full) — exactly four changes, no new test method, no new test file:
   - **`baseRunnerPreamble()`** — gains explicit, deterministic queue propagation, independent of whatever any given machine's untracked `.env.testing` contains: `putenv('QUEUE_CONNECTION=sync'); $_ENV['QUEUE_CONNECTION'] = 'sync'; $_SERVER['QUEUE_CONNECTION'] = 'sync';` alongside the existing `APP_ENV=testing` lines, before Laravel bootstrap; and, immediately after `$kernel->bootstrap();`, a fail-closed runtime guard (`if (config('queue.default') !== 'sync') { fwrite(STDERR, "QUEUE_CONNECTION_NOT_SYNC\n"); exit(1); }`). One shared addition, covering both `confirmRunnerScript()` and `holdUntilSignalRunnerScript()`.
   - **`confirmRunnerScript()`** — its own child-process `CheckoutSessionResult` registration (used by all three of the file's tests that spawn a confirming child) gains `receiptUrl: 'https://fake.stripe.test/receipts/ch_fake_child_confirm'`, `receiptChargeId: 'ch_fake_child_confirm'` — unchanged from Round 2, now genuinely exercised because the preamble fix above guarantees the child actually runs the receipt job inline rather than queuing it.
   - **`test_duplicate_webhook_and_browser_return_credit_a_checkout_backed_top_up_exactly_once()`** — its own parent-process registration gains `receiptUrl: 'https://fake.stripe.test/receipts/ch_fake_dup_confirm'`, `receiptChargeId: 'ch_fake_dup_confirm'` — unchanged from Round 2 (this method runs in the main PHPUnit process, already covered by `phpunit.xml`'s own `sync` setting).
   - **`tearDown()`** — gains one new line, placed immediately before the existing `business_usage_ledger_entries` deletion: `DB::table('business_billing_receipts')->whereIn('business_id', $this->createdBusinessIds)->delete();` — otherwise the FK from `business_billing_receipts.ledger_entry_id`/`business_id` blocks the existing cleanup. **No `jobs`-table cleanup is added or required**: the preamble fix above guarantees every child runs `SendReceiptNotification` inline rather than queuing it, so no child ever inserts a `jobs` row in the first place — a broad `jobs`-table cleanup would only be needed to paper over an uncontrolled child queue mode, which is exactly what this exceptional correction fixes at the source instead.

   Per §U, the real cross-process concurrency proof is preserved exactly as-is throughout; receipt-cardinality idempotency under concurrent/repeated invocation is proven separately in `FundingAttemptExactlyOnceWalletCreditTest.php` (item 4 above) and `ReceiptBoundaryTest.php`, never by adding receipt-execution complexity inside this file's own real wallet-lock race.
7. **`tests/Feature/Usage/CrossBusinessPaymentIsolationTest.php`** — new: `test_attach_funding_receipt_rejects_a_mismatched_business_id()`; new: `test_attach_funding_receipt_rejects_a_mismatched_funding_attempt_id()` (§Q — assigned here, alongside the business-id proof, since both are the same class of defensive identity-linkage failure and this file already owns the business-mismatch proof).
8. **`tests/Feature/Usage/RedirectBeforeWebhookConfirmationTest.php`** — new: `test_missing_receipt_evidence_fails_the_notification_job_without_reversing_accounting()`; new: `test_duplicate_return_and_webhook_confirmation_dispatches_the_receipt_job_exactly_once()`.
9. **`tests/Feature/Usage/FakePaymentProviderGatewayTest.php`** — exact final method set (§S, reconciled to avoid redundant proofs): new `test_registered_payment_intent_result_is_returned_verbatim()`; new `test_unregistered_payment_intent_retrieval_returns_stable_deterministic_receipt_fields()`; new `test_repeated_unregistered_payment_intent_retrieval_returns_the_same_receipt_identity()` (proves determinism across two calls for the same id, not just presence); new `test_checkout_session_receipt_fields_are_returned_verbatim_when_explicitly_registered()`; new `test_retrieve_payment_intent_calls_are_recorded()`; new `test_retrieve_checkout_session_calls_are_recorded()`.
10. **`tests/Feature/Usage/StripePaymentProviderGatewayCompatibilityTest.php`** — correct `test_gateway_source_fails_closed_on_a_missing_redirect_url_and_resolves_payment_method_via_expansion()`'s final assertion from the now-false full-array-literal substring to two separate assertions: `assertStringContainsString("'payment_intent.payment_method'", $source)` and `assertStringContainsString("'payment_intent.latest_charge'", $source)`. New: `test_payment_intent_retrieval_expands_latest_charge_for_receipt_evidence()` asserting `assertStringContainsString("'expand' => ['latest_charge']", $source)`.

### New test file

**`tests/Feature/Usage/ReceiptBoundaryTest.php`** — exact method list:

- `test_business_billing_receipts_schema_matches_the_rfc_exactly()` — six columns, both FKs' `restrictOnDelete()`, no `updated_at`.
- `test_receipt_migration_declares_no_extra_unique_or_convenience_index()` — **renamed and re-scoped this round (§N)**: a source-level read of the migration file itself, asserting it contains no `->unique(` and no standalone `->index(` beyond the two required `->foreign(...)` declarations — never a live `SHOW INDEX` assertion.
- `test_slot_agreement_flows_never_create_a_business_billing_receipt()`.
- `test_ensure_funding_receipt_resolves_evidence_for_a_checkout_backed_attempt()`.
- `test_ensure_funding_receipt_resolves_evidence_for_a_payment_intent_backed_attempt()`.
- **`test_ensure_funding_receipt_returns_the_existing_receipt_without_a_provider_call_or_write()`** — **renamed this round (§H)**, correcting the prior name's contradiction of §6's own documented behavior. Asserts: the returned object equals the pre-existing receipt (by id); `business_billing_receipts` row count is unchanged after the call; `$gateway->retrievePaymentIntentCalls` and `$gateway->retrieveCheckoutSessionCalls` (§8's new call-recording) both remain empty. **No `null` return is asserted anywhere in this test.**
- `test_a_manually_redispatched_send_receipt_notification_persists_the_receipt_after_a_prior_evidence_failure()`.
- `test_send_receipt_notification_is_an_after_commit_queue_job()` — **new this round (§K)**: `assertTrue(is_subclass_of(SendReceiptNotification::class, ShouldQueueAfterCommit::class))` and `assertTrue(is_subclass_of(SendReceiptNotification::class, \App\Jobs\Base::class))`, plus a direct property-default assertion that a fresh instance's inherited `$tries`/`$maxExceptions` remain `1` (never overridden) — the exact source-level proof of §L's recoverability rule.
- `test_notification_opt_out_still_persists_the_receipt_but_sends_no_mail()`.
- `test_missing_billing_contact_still_persists_the_receipt_but_sends_no_mail()`.
- **`test_independent_billing_contact_receives_the_receipt_at_contact_email()`** — new this round (§R): a `business_billing_contacts` row with `contact_user_id: null`, explicit `contact_name`/`contact_email` — asserts `Notification::route('mail', $email)` is exercised with that exact address (`Notification::fake()` + `Notification::assertSentOnDemand(...)` or the repository's own established mail-assertion convention).
- **`test_user_backed_billing_contact_receives_the_receipt_at_the_linked_user_email()`** — new this round (§R): `contact_user_id` set, `contact_name`/`contact_email` both `null` (matching `BillingProfileManager`'s own real write behavior) — asserts the notification routes to the linked `User`'s own `email`.
- `test_no_code_path_in_this_correction_references_the_legacy_invoices_table()`.
- `test_provider_receipt_url_is_always_the_verbatim_stripe_value_never_locally_constructed()`.
- `test_receipt_boundary_tests_bind_only_the_fake_gateway_never_the_real_stripe_gateway()`.

No test file beyond these is authorized.

---

## 11. Human-review decisions — closed, plus one new operational requirement stated (not a design gap)

1. `Refund` receipt rows: NO. Closed.
2. `DisputeChargeback` receipt rows: NO. Closed.
3. `UNIQUE(ledger_entry_id)`: NO. Closed.
4. The Round-0 unrecoverable `AutoRecharge` gap: resolved by the Round-1 architecture, re-confirmed this round.
5. **New this round, stated as an operational requirement rather than a design gap:** production Usage-billing deployment must use a durable (non-`sync`) queue connection for Receipt Boundary to be operationally recoverable — to be carried into M6's own future deployment/readiness contract by a human, not written into any file by this branch (§7/§L).

No other human decision is open.

---

## 12. Explicit exclusions (unchanged)

Job/Event Dispatch Completion work except `SendReceiptNotification` itself; `BusinessFundingAttemptSucceeded`/`Failed` event dispatch; `SendLowBalanceNotification`/`SendAutoRechargeDisabledNotification`; `ExpireStaleUsageReservations` scheduling; Admin Usage Billing Surface; `ManualCredit`/`PromotionalCredit`/`UsageChargeReversal`/`CorrectionReversal` admin surface; executable refund/dispute handling beyond §4's closed determination; add-on HTTP routes/controllers/views; M6 conformance docs; deployment docs; tag work; Conversations pilot activation; tax/VAT implementation; legacy `invoices` changes; any dedicated backfill/reconciliation job (superseded); any new `UNIQUE`/index addition; any change to `config/queue.php`, `.env.example`, or `phpunit.xml`.

---

## 13. Exact future production allowlist — 16 files (unchanged from Round 1; re-confirmed this round)

1. `database/migrations/2026_08_27_120001_create_business_billing_receipts_table.php` — new.
2. `app/Models/BusinessBillingReceipt.php` — new.
3. `app/Repositories/Contracts/BusinessBillingReceiptRepository.php` — new.
4. `app/Repositories/Eloquent/EloquentBusinessBillingReceiptRepository.php` — new.
5. `app/Providers/AppServiceProvider.php` — one new binding line.
6. `app/Library/Usage/UsageWalletManager.php` — `creditFromFunding()` widened; `attachFundingReceipt()`, `findFundingReceipt()` added.
7. `app/Library/Usage/UsageBillingCheckoutManager.php` — `ensureFundingReceipt()` added. No other method modified.
8. `app/Library/Usage/StripePaymentProviderGateway.php` — expand widened; DTO mapping populates two new fields.
9. `app/Library/Usage/FakePaymentProviderGateway.php` — `registerPaymentIntentResult()`, deterministic default receipt fields, call-recording arrays (§8).
10. `app/Library/Usage/PaymentIntentResult.php` — two new trailing nullable fields.
11. `app/Library/Usage/CheckoutSessionResult.php` — two new trailing nullable fields.
12. `app/Jobs/Usage/SendReceiptNotification.php` — new.
13. `app/Notifications/Usage/ReceiptAvailableNotification.php` — new.
14. `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` — `findById()`, `findForUpdateById()` added.
15. `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php` — same two methods implemented.
16. `app/Exceptions/Usage/ReceiptEvidenceUnavailableException.php` — new.

**Confirmed NOT REQUIRED, re-audited this round:** `app/Library/Usage/Contracts/PaymentProviderGateway.php` (no interface change); `app/Jobs/Usage/ProcessPaymentProviderEvent.php` (delegates unmodified); `app/Jobs/Usage/ReconcileProviderPendingState.php` (delegates unmodified); `config/queue.php`; `phpunit.xml`; `.env.example` (none of the last three are modified by this correction — §11 item 5 is a future M6-level operational requirement, not a change to any of these files).

No production path beyond these 16 is authorized.

---

## 14. Exact future test/support allowlist — 11 files, individually listed (corrected this round, up from 9)

1. `tests/Feature/Usage/TopUpStateMachineTest.php` — shared helper corrected + 1 new method.
2. `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` — shared helper corrected + 2 new methods.
3. `tests/Feature/Usage/FundingAttemptPayerConsentTest.php` — 2 inline fixtures corrected + 2 existing methods extended + 1 new method.
4. `tests/Feature/Usage/FundingAttemptExactlyOnceWalletCreditTest.php` — 1 inline fixture corrected + 1 new method.
5. `tests/Feature/Usage/PayerChangeDuringPendingAttemptTest.php` — **added this round** — 1 inline fixture corrected, no new method.
6. `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` — **added in Round 2, corrected once more by the exceptional post-review pass** — `baseRunnerPreamble()`'s explicit `QUEUE_CONNECTION=sync` propagation + fail-closed runtime guard, 2 inline receipt fixtures corrected, `tearDown()` corrected — no new method, no new file.
7. `tests/Feature/Usage/CrossBusinessPaymentIsolationTest.php` — 2 new methods.
8. `tests/Feature/Usage/RedirectBeforeWebhookConfirmationTest.php` — 2 new methods (existing method VERIFY-ONLY).
9. `tests/Feature/Usage/FakePaymentProviderGatewayTest.php` — 6 new methods.
10. `tests/Feature/Usage/StripePaymentProviderGatewayCompatibilityTest.php` — 1 corrected assertion + 1 new method.
11. `tests/Feature/Usage/ReceiptBoundaryTest.php` — new file, 14 methods.

**Documented VERIFY-ONLY, not counted as modifiable paths (§W):** `tests/Feature/Usage/AutoRechargeLoopPreventionTest.php` (no change).

No test/support path beyond these 11 is authorized. The full mechanical audit required by this round (`registerCheckoutSessionResult(`, `new CheckoutSessionResult(`/`PaymentIntentResult(`, `creditFromFunding(`, `Queue::fake(`, across the entire `tests/Unit/Usage`/`tests/Feature/Usage` tree) found no further path requiring modification.

---

## 15. Future implementation gates (unchanged, plus one preflight requirement)

```
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan migrate:fresh --env=testing
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Unit/Usage tests/Feature/Usage
git diff --check
```

Only `ultimatesms_testing`. No real Stripe credentials. No live Stripe network request.

**New preflight requirement, locked this round:** before running the full suite, the implementer must confirm — by the exact mechanical audit already performed in this contract (§10) — that every successful funding-confirmation fixture reachable under `phpunit.xml`'s `sync` queue setting is receipt-safe (either via the Fake's own deterministic default, or an explicit fixture correction named in §10). This contract's own §10 is that confirmation for every path existing on the current base commit; the implementer's own job is to apply the §10 corrections exactly as named, not to re-derive them.

---

## 16. Contract-branch validation (run and confirmed before this round's commit)

```
git status --short
git diff --check
git diff --name-only origin/main...HEAD
```

The only changed tracked path is `docs/automation/RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md` — confirmed in the final report.

---

*This contract authorizes drafting review only. Implementation on `agent/rfc-005-receipt-boundary-correction` requires a separate, explicit human instruction issued after this document is human-merged.*
