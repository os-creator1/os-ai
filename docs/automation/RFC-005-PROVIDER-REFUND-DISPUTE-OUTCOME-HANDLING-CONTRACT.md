# RFC-005 Remediation #6 — Provider Refund/Dispute Outcome Handling Contract

## Correction Round 1 record — 1 of 2 consumed, 1 remaining

Twelve independently confirmed blockers corrected this round: (1) the production path count was false (11 paths existed, not 8 — recomputed mechanically below, no repository pair grouped into one count); (2) payment-intent-only identification was incomplete (Stripe marks `payment_intent` nullable on both `Charge` and `Dispute`) — a second identifier, `provider_charge_reference`, is now persisted alongside `provider_payment_intent_reference`, both synchronously at confirmation time from data the existing DTOs already expose, with an explicit conflicting-match failure mode; (3) dispute accounting keyed off `Dispute.amount`/`.status`, which are not reliable statements of actual funds movement — redesigned to key off `Dispute.balance_transactions[]`'s own signed `amount`/`id`; (4) `reverseChargebackEntry()` exactly negated the original entry's stored deltas, which can drive debt negative — redesigned to clear *current* debt first and credit any remainder, exactly mirroring `creditFromFunding()`'s own formula; (5) a single "net reversed" accumulator incorrectly let dispute activity suppress/reopen refund progress — split into two independent, SQL-aggregate (not PHP-collection-reduced) accumulators; (6) funding-attempt state was transitioned per-event instead of recomputed from every outstanding dispute — redesigned as a full recomputation under lock after every mutation; (7) ledger row shapes were under-specified and violated the RFC's own mandatory-reason rule for `Refund`/`CorrectionReversal` — every field is now locked, with a redundant-suspend guard added; (8) the claimed retry/reclaim path does not exist in current code (`tries=1`, no scheduled scanner) — a real bounded scanner job is now designed and allow-listed; (9) `ignored`/`processed` events were not administrator-visible and their identity thins to unattributed columns after purge — the existing `payment_provider_events` table gains normalized attribution columns and the existing admin surface gains a bounded recent-outcomes read; (10) `refund.*`/`charge.refund.updated` events were left unhandled, which would poison the retry/exhaustion queue since intake is event-type-agnostic — now explicitly routed to audit-only handling; (11) the test plan is fully re-derived (8 files, 80 methods, including genuine forced-concurrency coverage using the repository's own established subprocess/causal-barrier convention); (12) three of four "open" human decisions were not genuinely open (each resolves mechanically from the RFC's own authorization table or from this round's own redesign) and are now locked; exactly one genuine, unresolvable-from-the-RFC product decision remains open in §12.

---

## 0. Governance

- `maximum_correction_rounds`: 2 — **this is Correction Round 1 of 2. 1 consumed, 1 remaining.**
- `human_only_merge`: true — this document, and any implementation branch built from it, is merged only by a human.
- **M6 remains frozen.** Nothing in this document authorizes any M6 work (conformance, deployment, tag).
- No tag is authorized by this document.
- No deployment, live Stripe action, refund, dispute simulation against production, rate activation, meter activation, or pilot activation is authorized by this document or by authoring it.
- `docs/automation/AI-AUTONOMY-STATE.json` is untouched by this document and must remain untouched by any commit on this branch.
- This remediation is sequenced **before** remediation #7 (RFC-005 §35 test-coverage completion).
- **Authoring only.** This document locks a design. Implementing it is a separately, explicitly authorized future phase — this contract does not itself grant implementation authority, and no product or test code accompanies it.

**Base SHA (unchanged from the original authoring pass):** `a8d2a0f9c03f8295262a9ec7b36374b4b0437294` — PR #148's merge commit.

**Branch:** `chore/rfc-005-provider-refund-dispute-outcome-handling-contract`.

---

## 1. Required reading — re-confirmed this round, plus the additional facts this correction required

Everything read for the original pass (RFC §12/§13/§17/§20/§21/§23/§24/§25–§30, the ten exclusion-mentioning contracts, the Admin Usage Billing Surface implementation, the full webhook/checkout/wallet/ledger/receipt/reconciliation code, every relevant migration/enum) remains read and re-confirmed. **Newly read this round, to resolve the twelve blockers:**

- `docs.stripe.com/api/disputes/object` — re-fetched specifically for `balance_transactions` (*"List of zero, one, or two balance transactions that show funds withdrawn and reinstated to your Stripe account as a result of this dispute"*) and re-confirmed `payment_intent` is nullable.
- `docs.stripe.com/api/balance_transactions/object` — `id`, `amount` (signed, *"a positive value represents funds charged to another party, and a negative value represents funds sent to another party"*), `currency`, `net`, `type`.
- `docs.stripe.com/api/refunds/object` — the `Refund` object's own `payment_intent`/`charge`/`status` fields, confirming both cross-reference fields the correction requires are present there too.
- `app/Jobs/Base.php` — confirmed `public int $tries = 1; public int $maxExceptions = 1;`, the exact fact underlying blocker 8.
- `app/Console/Kernel.php` — confirmed the exact scheduling convention (`$schedule->job(new ReconcileProviderPendingState())->everyFiveMinutes();`) this correction's new scanner job mirrors, and confirmed no scanner for `payment_provider_events` exists today.
- `app/Jobs/Usage/ReconcileProviderPendingState.php` — the exact "load a bounded candidate set, redispatch/reprocess, no direct mutation" shape this correction's new job mirrors.
- `app/Http/Controllers/Admin/PaymentProviderEventController.php`, `app/Repositories/Contracts/PaymentProviderEventRepository.php` — confirmed `index()` renders only `exhausted()` rows; no "recent processed/ignored" read exists anywhere.
- `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` — the exact, established subprocess/causal-barrier convention (`Symfony\Component\Process\Process`, a `WAITING`-handshake before a shared signal file is written, no `RefreshDatabase`, manual fixture teardown) this correction's own forced-concurrency tests reuse verbatim, per the correction instruction.
- Direct re-read of `business_usage_ledger_entries`'s own migration confirming `provider_reference` (`string(191)`, nullable) already exists on that table, unused by any write path — the field this correction uses to key per-dispute/per-refund attribution, avoiding a new ledger column entirely.

---

## 2. Exact prior exclusion language — unchanged from the original pass, re-confirmed still accurate

No exclusion-language finding from the original authoring pass is altered by this round's corrections — every quoted exclusion in the original §2 remains accurate; the redesign below corrects *how* this remediation implements what those exclusions already left open, not *whether* it may.

---

## 3. Stripe object/event facts — corrected and extended this round

**`Charge` object:** `id`, `payment_intent` (**nullable** — re-confirmed, blocker 2), `customer`, `amount`, `amount_refunded` (cumulative), `refunded` (boolean, full-refund only), `currency`, `disputed`. `metadata` is independent, not inherited (unchanged from the original pass).

**`Dispute` object:** `id`, `charge`, `payment_intent` (**nullable**), `amount` (the *claimed* disputed amount — **corrected this round: not a reliable statement of actual funds movement**, blocker 3), `currency`, `status`, `reason`, **`balance_transactions`** (array of zero, one, or two `BalanceTransaction` objects — **new this round, the actual authority for what happened to the money**).

**`BalanceTransaction` object** (new to this contract this round): `id` (`txn_...`), `amount` (integer, minor units, **signed** — *"a positive value represents funds charged to another party, and a negative value represents funds sent to another party"*), `currency`, `net`, `type`. For a dispute, a negative-`amount` entry represents funds withdrawn; a positive-`amount` entry represents funds reinstated. Stripe's own documentation confirms a dispute may carry **zero** (not yet resolved to any balance impact), **one** (withdrawal only, still open or lost), or **two** (withdrawal, then reinstatement) such entries — never more assumed, but the design below (§4.3) does not hard-code a maximum of two, since Stripe does not guarantee that ceiling in every edge case (e.g. partial-then-partial).

**`Refund` object:** `id` (`re_...`), `amount`, `currency`, `charge` (nullable), `payment_intent` (nullable), `status` (`pending`\|`requires_action`\|`succeeded`\|`failed`\|`canceled`), `balance_transaction`. **Both cross-reference fields this contract's identification design needs (`payment_intent`, `charge`) are present here too** — used identically for audit-only attribution (§4.10).

**Locked design consequence, corrected this round:** `charge.refunded` remains the sole cumulative-refund mutation authority (unchanged — its own `amount_refunded` field is still the correct, monotonic accumulator). `charge.dispute.funds_withdrawn`/`.funds_reinstated` now drive mutation from the verified event's own `data.object.balance_transactions[]` entries, never from `Dispute.amount`. `charge.dispute.created`/`.updated`/`.closed` and the entire `refund.*`/`charge.refund.updated` event family are audit-only (§4.3, §4.10) — **explicitly handled, never left to fall through to a generic failure branch (blocker 10).**

---

## 4. Mechanical findings — corrected and extended this round

### 4.1 Identification — two independent identifiers, with an explicit ambiguity failure mode (blocker 2)

**Corrected: a single `payment_intent`-based identifier is not a complete guarantee** — Stripe's own documentation marks `payment_intent` nullable on both `Charge` and `Dispute`. Both already-normalized result DTOs this codebase owns expose a **second**, independent identifier at zero additional provider-call cost:

- `CheckoutSessionResult::$providerPaymentIntentId` and `CheckoutSessionResult::$receiptChargeId` (`app/Library/Usage/CheckoutSessionResult.php:24,26`).
- `PaymentIntentResult::$receiptChargeId` (`app/Library/Usage/PaymentIntentResult.php:12`) — the PaymentIntent id itself is already `$attempt->provider_session_or_intent_reference` for AutoRecharge, no DTO field needed for that half.

**Locked design:** two new nullable, independently-`UNIQUE`-when-populated columns on `business_funding_attempts`: `provider_payment_intent_reference` and `provider_charge_reference`. Populated synchronously, inside the same transaction that finalizes the attempt, from data already in hand at every existing call site — never a new provider round-trip:

| Purpose | Confirmed via | `provider_payment_intent_reference` | `provider_charge_reference` |
|---|---|---|---|
| `AutoRecharge`, confirmed via `confirmAttemptFromReturn()`/`retryFundingAttemptAsAdministrator()` | both branches already call `retrievePaymentIntent()`, discarding the result today | `$attempt->provider_session_or_intent_reference` (already known, trivial) | `$paymentIntent->receiptChargeId` — **already fetched, in hand** |
| `AutoRecharge`, confirmed via the ordinary webhook path (`confirmAttemptFromWebhook()`) | that branch trusts the raw, signature-verified payload directly and never fetches a `PaymentIntentResult` (M4's own established, unchanged design) | `$attempt->provider_session_or_intent_reference` (still trivially known — never requires the fetch) | **left `NULL`** — no additional provider call is introduced to obtain it (governance); harmless in practice because PI-reference resolution for AutoRecharge never fails (below) |
| `ManualTopUp`/`AddonPurchase`, any of the three confirmation paths | every path already calls `retrieveCheckoutSession()` | `$session->providerPaymentIntentId` | `$session->receiptChargeId` — **already fetched, in hand** |

**At-least-one-identifier precondition (blocker 2's own explicit requirement) is already structurally guaranteed, not newly enforced:** `fundingAttemptCheckoutVerified()` already refuses to proceed when `$session->providerPaymentIntentId === null` (confirmed, `UsageBillingCheckoutManager.php:984,1298`) — a Checkout-backed attempt can never reach `Succeeded` with a null PaymentIntent id. For AutoRecharge, `provider_session_or_intent_reference` is itself required non-null before a status check is even possible. **This contract adds one explicit, defensive assertion inside `finalizeFundingAttemptState()`** — refuse to persist `state = Succeeded` if the resolved `provider_payment_intent_reference` value about to be written is null — restating this already-true precondition as code, not introducing new fallible logic.

**Routing/resolution algorithm, corrected and extended (blocker 2):** for a `charge.refunded`/`charge.dispute.*`/`refund.*` event, read **both** `payment_intent` and (`Charge.id` for a Charge-object event, `charge` for a Dispute/Refund-object event) from the verified payload. Resolve each independently via `findByProviderPaymentIntentReference()`/`findByProviderChargeReference()` (§5). Then:

- **Both resolve, to the same attempt** → proceed.
- **Both resolve, to different attempts** → `markFailed('cross_reference_ambiguity')`, zero mutation. This is a genuine, structural mismatch (never expected in a correctly-functioning system) and is treated exactly like every other pre-mutation validation failure — logged, retryable, never silently resolved by picking one.
- **Exactly one resolves** (the other field was absent from the payload, or present but matches no local row) → use the one that resolved.
- **Neither resolves** → `markFailed('no_matching_local_record')`, zero mutation (unchanged from the original pass).

### 4.2 Full/partial/repeated refunds — unchanged in semantics from the original pass, now isolated from dispute activity (blocker 5, §4.5 below)

No change to the customer-requested-vs-provider-confirmed distinction, the charge-reversal-is-not-a-separate-category finding, or the full-vs-partial mechanical distinction. The **accumulator** feeding this logic is corrected in §4.5.

### 4.3 Dispute withdrawal/reinstatement — corrected to key off signed balance transactions, never `Dispute.amount`/`.status` alone (blocker 3)

**Corrected:** `Dispute.amount` is *"usually the amount of the charge, but it can differ"* — Stripe's own documentation, and the correction's own worked examples (a dispute can be partial; can exceed the original payment; a partially-refunded charge can be disputed for its full remaining amount; `funds_reinstated` may restore only part of what was withdrawn; more than one dispute can exist for one payment) all confirm `Dispute.amount`/`.status` are claims, not settlement facts.

**Locked design:** on `charge.dispute.funds_withdrawn`, read `data.object.balance_transactions[]` from the verified payload. For every entry whose `amount` is **negative** and whose own `id` has not already been applied (idempotency key, below), apply exactly one `DisputeChargeback` ledger entry for `abs(amount)` (converted to micro via `expectedMicroForMinorUnits()`, currency-verified against `entry.currency`). On `charge.dispute.funds_reinstated`, identically, for every entry whose `amount` is **positive** and not already applied, apply exactly one `CorrectionReversal` (§4.4). A dispute event carrying an **empty** `balance_transactions` array (a mere inquiry/warning with no balance impact yet) produces **zero** mutation from either event type — durably recorded as audit-only (§4.10), exactly as if it were `.created`/`.updated`. `charge.dispute.created`/`.updated`/`.closed` remain audit-only regardless of `balance_transactions` content, per the original pass's own §4.3 reasoning (unchanged) — the two event types Stripe fires specifically when a balance transaction newly appears are the only ones this contract keys mutation to, exactly per the correction's own instruction.

**Idempotency, corrected (feeds blocker 3 and blocker 5):** the correlation key for a `DisputeChargeback` row is `'dispute_chargeback:'.$attempt->id.':'.$balanceTransactionId` — keyed to the **provider balance-transaction id itself**, not a computed cumulative figure (there is no cumulative figure for disputes — each balance transaction is its own discrete, permanent fact). Identically, `'dispute_reversal:'.$attempt->id.':'.$balanceTransactionId` for a `CorrectionReversal` row. `provider_reference` (the existing, currently-unused `business_usage_ledger_entries.provider_reference` column, §4.6) is set to the **dispute's own id** (`Dispute.id`, e.g. `du_...`) on both entry types — grouping key for §4.4/§4.6's own per-dispute bounding, distinct from the row's own per-balance-transaction correlation key.

### 4.4 Reinstatement uses current wallet state, never a negation of the original entry (blocker 4)

**Corrected:** the original design's `reverseChargebackEntry()` negated the original `DisputeChargeback` row's own stored `debt_delta_micro`/`available_delta_micro` exactly. Worked counter-example, confirmed correct by the correction instruction: a chargeback creates €100 debt; a later, unrelated top-up clears that debt (via `creditFromFunding()`'s own debt-clear-first formula, entirely independent of the dispute); the dispute is later won and funds are reinstated — negating the *original* entry's own `debt_delta_micro = +100` would write `debt_delta_micro = -100` against a wallet whose debt is already `0`, driving debt negative, a value the schema and every other write path in this codebase treats as structurally impossible.

**Locked design — `UsageWalletManager::reinstateDisputedFunds()`** (renamed from the original pass's `reverseChargebackEntry()`), computed from the wallet's **currently locked** state, exactly mirroring `creditFromFunding()`'s own debt-clear-first formula, never the original entry's stored deltas:

```
debtCleared = min(reinstatementAmountMicro, max(0, wallet.debt_balance_micro))
remainder   = reinstatementAmountMicro - debtCleared
available_delta_micro = +remainder
debt_delta_micro      = -debtCleared
```

- **Never produces negative debt** — `debtCleared` is bounded to the wallet's own current `debt_balance_micro`, exactly as `creditFromFunding()`'s identical clamp already guarantees for every other crediting path in this codebase.
- **`reversed_entry_id`** is set to the **original `DisputeChargeback` entry's own id** for full audit lineage — even across a partial reinstatement, and even if a second, later reinstatement event for the *same* dispute (a further balance transaction) also references the same original entry id (§4.3's own per-balance-transaction idempotency already prevents any double-application; multiple `CorrectionReversal` rows sharing one `reversed_entry_id` is the correct, auditable shape for a dispute reinstated in more than one Stripe-side movement).
- **Bounded to the actual withdrawn amount for that specific dispute (blocker 3's own explicit bullet), never the attempt's own `expected_amount_micro`:** before applying, `sumDisputeMicroForFundingAttemptAndDispute($attemptId, $disputeId, DisputeChargeback)` and the identical call for `CorrectionReversal` (§5, both new SQL-aggregate reads, §4.5's own discipline) compute, for **this dispute only**, `withdrawn` and `alreadyReinstated`. The amount actually applied is `min(reportedBalanceTransactionAmount, withdrawn − alreadyReinstated)`, clamped to a minimum of `0`. A clamped-to-zero result is a no-op — no row written, no event dispatched — the mechanism that makes a duplicate/replayed reinstatement event idempotent by construction, independent of the correlation-key `UNIQUE` constraint (which remains the second, structural line of defense, matching the refund design's own two-layer discipline).
- **Dispatches `BusinessWalletCredited` (if `remainder > 0`) and/or `BusinessWalletDebtCleared` (if `debtCleared > 0`)** — the credit-direction pair, matching `creditFromFunding()`'s own dispatch shape exactly (corrected from the original pass's incorrect negated-shape guess, which would have dispatched the *debit*-direction pair for a *crediting* operation).

### 4.5 Refund and dispute accumulators are independent — never one shared "net reversed" figure (blocker 5)

**Corrected:** the original design's single formula, `Σ Refund + Σ DisputeChargeback − Σ CorrectionReversal`, let dispute activity change how much refund headroom an unrelated, ordinary partial-refund sequence has left — a genuine correctness defect, not merely an inefficiency.

**Locked design — two structurally independent bounded SQL aggregates, both returning exact decimal strings (never a native PHP-integer sum, matching the margin-aggregate's own already-established `DECIMAL`-string discipline from the Admin Usage Billing Surface Contract), computed entirely in SQL, never by loading rows into PHP for reduction:**

- **Refund progress**, scoped to the funding attempt alone, entirely independent of any dispute ever raised against it: `already_refunded = SUM(gross_amount_micro) WHERE funding_attempt_id = ? AND entry_type = 'refund'` (`BusinessUsageLedgerEntryRepository::sumRefundedMicroForFundingAttempt()`, §5). `refund_delta = min(cumulativeAmountRefundedMicro, expected_amount_micro) − already_refunded`, clamped to a minimum of `0`, computed via `bcsub()`/`bccomp()` on the SQL-returned strings, never native subtraction on a PHP-cast value.
- **Dispute withdrawal/reinstatement progress**, scoped to the funding attempt **and** one specific dispute id (§4.4's own `sumDisputeMicroForFundingAttemptAndDispute()`), entirely independent of the attempt's own refund progress and of any *other* dispute.

**Neither accumulator ever reads the other's entry type.** A refund is computed exclusively from `Refund` rows; a dispute's own exposure is computed exclusively from `DisputeChargeback`/`CorrectionReversal` rows filtered to that one dispute's own `provider_reference`. The two are combined only at the very end, when recomputing the attempt's own overall `state` (§4.6) — never when computing either one's own delta.

### 4.6 Funding-attempt state is recomputed from every outstanding outcome, under lock, after every mutation — never transitioned per-event (blocker 6)

**Corrected:** the original design transitioned `state` directly in response to whichever single event had just been processed (e.g. "a `funds_reinstated` event transitions the attempt back to `Succeeded`"). Stripe permits multiple disputes against one payment; reinstating one cannot correctly force `Succeeded` while a second, unrelated dispute against the same charge remains outstanding.

**Locked design — `UsageBillingCheckoutManager::recomputeFundingAttemptState()`**, called once, under the attempt's own row lock, as the final step of `applyRefundOutcome()`/`applyDisputeChargebackOutcome()`/`applyDisputeReinstatementOutcome()` alike, inside the same outer transaction as the wallet mutation it follows:

```
refunded = sumRefundedMicroForFundingAttempt(attemptId)                         // §4.5, exact string
disputeReferences = distinctDisputeReferencesForFundingAttempt(attemptId)        // §5, bounded, realistically 0-3 rows
anyDisputeOutstanding = false
foreach (disputeReferences as $disputeId) {
    withdrawn   = sumDisputeMicroForFundingAttemptAndDispute(attemptId, $disputeId, DisputeChargeback)
    reinstated  = sumDisputeMicroForFundingAttemptAndDispute(attemptId, $disputeId, CorrectionReversal)
    if (bccomp(withdrawn, reinstated, 0) > 0) { anyDisputeOutstanding = true; break; }
}

state = match (true) {
    anyDisputeOutstanding => Disputed,
    bccomp(refunded, attempt.expected_amount_micro, 0) >= 0 => Refunded,
    default => Succeeded,
};
```

- **One dispute's reinstatement does not erase another's** — `anyDisputeOutstanding` is evaluated across *every* distinct dispute ever raised against this attempt, not just the one the current event concerns.
- **Partial reinstatement leaves `Disputed`** while exposure remains — `bccomp(withdrawn, reinstated, 0) > 0` is exact-string comparison, never a native numeric truthiness check.
- **A fully-refunded attempt returns to `Refunded`, not `Succeeded`,** once its final outstanding dispute exposure clears — the `match` checks `anyDisputeOutstanding` first, and falls through to the `refunded`-vs-`expected_amount_micro` comparison only once no dispute exposure remains at all.
- **A lost dispute remains `Disputed` permanently** — its own withdrawal is never matched by a `CorrectionReversal` (none is ever written for a lost dispute, §4.3), so `withdrawn > reinstated` (`> 0`) holds forever for that dispute id, and `state` never advances past `Disputed` for this attempt again (unless a *different*, later dispute against the same charge is itself fully reinstated and no other exposure remains — the recomputation is correct for that case too, by construction, since it evaluates every dispute id independently every time).
- **The write is skipped, and no `business_funding_attempt_transitions` row is inserted, when the recomputed state equals the currently-persisted state** — avoiding a redundant transition row on every single mutation event (mirroring the identical discipline §4.9 below locks for billing-status transitions).

### 4.7 Whether a wallet mutation applies at all — unchanged from the original pass

The `AddonPurchase`/`direct_deliverable` nuance (§4.6 of the original pass) is unchanged: `findCreditEntryForFundingAttempt()` (§5, retained) still gates whether any refund mutation is attempted at all. **Corrected this round only in that a `direct_deliverable` refund's own zero-wallet-mutation outcome is now durably, normalizedly audited (§4.10, blocker 9) rather than leaving only the bare `payment_provider_events` row this contract's original pass relied on.**

### 4.8 Idempotency/uniqueness boundary — corrected to reflect the balance-transaction-keyed design

Two independent layers, restated exactly as the original pass's §4.7, with the outcome-layer correlation keys corrected per §4.3/§4.4/§4.5 above (refund: `'refund:'.$attempt->id.':'.$newCumulativeAmountRefundedMicro`, unchanged; dispute withdrawal/reinstatement: keyed to the provider balance-transaction id, corrected from the original pass's dispute-id-only key). **Row-lock ordering is unchanged** — the wallet row is locked first, inside one `DB::transaction()`, before any delta computation or ledger insert; the funding attempt's own row is locked a second time, inside the same outer transaction, immediately before `recomputeFundingAttemptState()` writes (§4.6) — never before the wallet lock, matching this codebase's own consistent wallet-then-attempt lock order already established by `finalizeFundingAttemptState()`'s own existing call shape inside `confirmSucceeded()`.

### 4.9 Billing-status suspension/resumption — corrected to avoid a redundant transition row (blocker 7's own explicit bullet)

`setBillingStatus()` is confirmed, by direct read, to be **not** idempotent on its own — it unconditionally inserts a transition row and dispatches `BusinessWalletBillingStatusChanged` even when `$fromStatus === $status`. **Locked design:** the new `UsageWalletManager` method that applies a dispute withdrawal (§5) checks `$wallet->billing_status !== WalletBillingStatus::Suspended` **before** calling `setBillingStatus(..., Suspended, DisputeWebhook, null, $reason)` — a second (or later) dispute against a Business whose billing is already suspended writes its own `DisputeChargeback` ledger entry exactly as before, but produces **zero** additional billing-status transition rows. Resumption after a won dispute remains administrator-only (§12 — no longer an open decision, locked this round).

### 4.10 Ledger row shapes — every field locked, reason never null for `Refund`/`CorrectionReversal` (blocker 7)

The RFC's own §13 table (quoted in the original pass's §2) states `reason` is mandatory (manager-enforced) for `ManualCredit`, `UsageChargeReversal`, `CorrectionReversal`, and `Refund` — **not** for `DisputeChargeback`. This design populates a deterministic, non-blank system reason for **all three** entry types it writes, for audit consistency, even though the RFC leaves `DisputeChargeback`'s own reason technically optional — `reason` is never actually null in practice for any row this contract writes.

**`Refund`:**

```php
[
    'business_id' => $attempt->business_id,
    'wallet_id' => $wallet->id,
    'funding_attempt_id' => $attempt->id,
    'entry_type' => UsageLedgerEntryType::Refund->value,
    'available_delta_micro' => -$debitFromAvailable,   // -min(amountMicro, wallet.available_balance_micro)
    'reserved_delta_micro' => 0,
    'debt_delta_micro' => $debtIncurred,                // +max(0, amountMicro - wallet.available_balance_micro)
    'gross_amount_micro' => $amountMicro,               // the exact refund delta this row applies
    'currency_id' => $wallet->currency_id,
    'correlation_key' => 'refund:'.$attempt->id.':'.$newCumulativeAmountRefundedMicro,
    'provider_reference' => $providerChargeReference,   // the Charge id this refund concerns, when known
    'actor_user_id' => null,
    'reason' => "Provider-confirmed refund of {$amountMicro} micro-units against charge {$providerChargeReference}.",
    'reversed_entry_id' => null,
    'created_at' => now(),
]
```

**`DisputeChargeback`:** identical shape, `entry_type = dispute_chargeback`, `available_delta_micro`/`debt_delta_micro` per the identical `-min`/`+max` formula against the balance-transaction's own `abs(amount)`, `provider_reference = $providerDisputeId`, `reason = "Provider dispute {$providerDisputeId} withdrew funds ({$disputeReasonOrGeneral})."`, `reversed_entry_id = null`.

**`CorrectionReversal`:** `entry_type = correction_reversal`, deltas per §4.4's own current-wallet-state formula, `provider_reference = $providerDisputeId`, `reversed_entry_id = $originalChargebackEntry->id`, `reason = "Provider dispute {$providerDisputeId} funds reinstated."`.

Every field above is locked; no method signature in §5 accepts a nullable `?string $reason` for any of these three writes.

### 4.11 The claimed retry/reclaim path does not exist — a real one is designed (blocker 8)

**Confirmed, mechanically:** `app/Jobs/Base.php` sets `public int $tries = 1;`. `ProcessPaymentProviderEvent::handle()`'s own `markFailed()` branches return normally (no exception thrown) — the job reports success to the queue regardless of outcome. The scheduled `queue:work ... --tries=1 --stop-when-empty` command (`Kernel.php`) never redispatches a job that already ran once and returned. **No scheduled command or job anywhere in this codebase queries `payment_provider_events` for retryable `failed`/stale-`processing` rows.** The original pass's claim that "the existing bounded retry/reclaim system is fully reusable" was false — the *claim algorithm* (§14 of the M3 contract, the atomic `UPDATE ... WHERE state = 'received' OR (state = 'failed' AND attempts < 5) OR ...`) exists and is correct, but **nothing ever calls it a second time** for an event that already failed once.

**Locked design — one new job, `App\Jobs\Usage\RetryStuckPaymentProviderEvents`, scheduled `everyFiveMinutes()`** (identical cadence to `ReconcileProviderPendingState`, the established precedent for "find stuck things and redrive them"):

```php
class RetryStuckPaymentProviderEvents extends Base
{
    private const BATCH_LIMIT = 200;

    public function handle(PaymentProviderEventRepository $eventRepository): void
    {
        $maxAttempts = (int) config('usage_billing.webhook_event.max_attempts');

        foreach ($eventRepository->retryable($maxAttempts, self::BATCH_LIMIT) as $candidate) {
            ProcessPaymentProviderEvent::dispatch($candidate->id);
        }
    }
}
```

- **Bounded query, new repository method** — `PaymentProviderEventRepository::retryable(int $maxAttempts, int $limit): Collection`: `WHERE (state = 'failed' AND attempts < ?) OR (state = 'processing' AND lease_expires_at < NOW() AND attempts < ?) ORDER BY id LIMIT ?` — the identical `WHERE` shape as the existing claim statement's own retry/reclaim branches, so a row this query returns is, by construction, exactly the set of rows the claim statement would itself still match.
- **Batch limit, locked at 200** — a plain class constant (matching `ReconcileProviderPendingState::STUCK_AFTER_MINUTES`'s own precedent of a private const, not a new config key), bounding one scheduler tick's own work regardless of how large the retryable backlog grows.
- **Cadence, locked at every five minutes** — matching the existing 5-minute claim lease exactly, so a stale-`processing` row becomes reclaimable at almost exactly the same cadence this scanner checks for it.
- **Maximum-attempt behavior** — once `attempts >= maxAttempts`, a row matches neither `retryable()`'s own `WHERE` clause nor the claim statement's — it stops being redispatched and instead surfaces in the existing `exhausted()` admin query, unchanged.
- **No accounting mutation inside the scanner** — the scanner's own `handle()` performs exactly one bounded read and zero-or-more `dispatch()` calls; every actual claim/validate/mutate step still happens exclusively inside `ProcessPaymentProviderEvent::handle()`, unmodified in this respect.
- **Concurrency-safe by construction, no new code required in `ProcessPaymentProviderEvent`** — if the scanner and an original webhook-triggered dispatch (or two overlapping scanner ticks) both queue the same event id, each queued job independently calls the claim UPDATE; the claim's own atomicity (a single, `WHERE`-gated SQL statement) guarantees only one ever matches, and the existing `if ($claimed === 0) { return; }` early-return (already present, unmodified) makes every other concurrent dispatch a silent, correct no-op.
- **`processed`/`ignored`/`disposed` events are never redispatched** — none matches `retryable()`'s own `WHERE` clause, identically to how none matches the claim statement's.

### 4.12 Durable, administrator-visible, Business-attributed audit records (blocker 9)

**Confirmed, mechanically:** `PaymentProviderEventController::index()` renders only `exhausted()` rows (`app/Http/Controllers/Admin/PaymentProviderEventController.php:26-33`) — a `processed`/`ignored` row is never displayed anywhere, and its `payload_encrypted` is eventually purged (§4.13 of the original pass, unchanged), leaving only `event_type`/`provider_object_id`/timestamps — no `business_id`, no `funding_attempt_id`, no normalized amount/currency/dispute reason. This is not a complete Business-attributed audit record, and the `direct_deliverable` AddonPurchase refund case (§4.7) especially needs it, since that case intentionally writes zero wallet ledger entry — without this widening, that refund would leave no attributable trace anywhere once its payload is purged.

**Locked design — widen the existing `payment_provider_events` table itself (mechanically appropriate: it is already the permanent, purge-exempt-for-key-columns identity record for this exact event; a second, parallel table would duplicate that identity for no benefit), never a new table:**

Seven new, nullable columns: `business_id` (unsigned bigint, **no FK** — matching `disposed_by_user_id`'s own established "audit column, no FK" precedent on this exact table, so a later, unrelated Business-record change can never be blocked by an audit row), `funding_attempt_id` (unsigned bigint, no FK, identical reasoning), `normalized_outcome` (`string(32)` — e.g. `refund_applied`, `refund_ignored_no_wallet_entry`, `dispute_withdrawn`, `dispute_reinstated`, `dispute_audit_only`, `refund_object_audit_only`), `normalized_status` (`string(32)` — the verified Dispute's/Refund's own `status` value), `normalized_amount_micro` (bigint), `normalized_currency_code` (`string(3)`), `normalized_reason` (`string(64)` — the verified Dispute's own `reason` value, where present).

**Populated at the exact moment the event reaches its terminal state** — `PaymentProviderEventRepository::markProcessed()`/`markIgnored()` each gain one new, trailing, backward-compatible optional parameter, `array $attribution = []`, merged into the same terminal `UPDATE` those methods already issue (the established "widen with a new trailing optional parameter" pattern, reused a third time on this exact codebase's own convention). No new repository method for the write side — only for the new bounded read (below).

**Admin surface widened, not duplicated (per the correction's own explicit instruction to reuse, not create a second module):** `PaymentProviderEventController::index()` gains a second view-data key, `recentOutcomes`, from a new bounded repository method `recentOutcomes(int $limit = 50): Collection` (`WHERE normalized_outcome IS NOT NULL ORDER BY id DESC LIMIT ?`); `resources/views/admin/usage-billing/provider-events/index.blade.php` gains one new `x-card`/`x-table` section rendering it (Business, funding attempt, outcome, status, amount, currency, reason, received-at) — the existing exhausted-events table and disposition form are entirely unmodified.

**Proof that attribution survives payload purge:** `PurgeExpiredWebhookPayloads` (unmodified) only ever nulls `payload_encrypted`/sets `payload_purged_at` — it never touches any other column, including the seven new ones, exactly as it already never touches `event_type`/`provider_object_id`/`state`/`attempts` today.

### 4.13 `refund.*`/`charge.refund.updated` events — explicit audit-only handling, not silence (blocker 10)

**Confirmed mechanically required, not optional:** webhook intake is event-type-agnostic (`StripeWebhookController::handle()` persists and dispatches for processing regardless of `event_type`, unchanged). If a Stripe webhook endpoint is ever configured to also deliver `refund.created`/`refund.updated`/`refund.failed`/`charge.refund.updated`, `ProcessPaymentProviderEvent`'s own existing default branch (`missing_or_unrecognized_metadata`) would mark every one of them `failed` — genuinely poisoning the retry/exhaustion queue with events this system was never going to act on financially in the first place (`charge.refunded`'s own cumulative field already supersedes them for every mutation guarantee, §3/§12 item 4).

**Locked design:** `ProcessPaymentProviderEvent::handle()`'s own `match` recognizes `refund.created`, `refund.updated`, `refund.failed`, `charge.refund.updated` explicitly, **before** falling through to the metadata-based default. Each: attempts the identical dual-identifier resolution (§4.1) against the `Refund` object's own `payment_intent`/`charge` fields, best-effort (if it resolves, the normalized-audit row, §4.12, is attributed to that Business/attempt with `normalized_outcome = refund_object_audit_only`; if it does not resolve, the event is still durably recorded, attribution columns left null) — and is marked `ignored`, **never** `failed`, in both cases. No wallet/ledger mutation of any kind is ever performed for this event family.

---

## 5. Locked design — exact production allow-list, recomputed path by path, no pair grouped into one count

**19 files: 5 new + 14 modified.**

| # | Path | Status | Justified by |
|---|---|---|---|
| 1 | `database/migrations/2026_08_29_120001_add_provider_references_to_business_funding_attempts_table.php` | NEW | §4.1 — adds **both** `provider_payment_intent_reference` and `provider_charge_reference` (each nullable `string(191)`) in one `Schema::table()` call, then **both** `UNIQUE` indexes in a second `Schema::table()` call (§7) — one coherent, two-column identification change for one table, not a grouping of unrelated concerns. |
| 2 | `database/migrations/2026_08_29_120002_backfill_provider_payment_intent_reference_for_auto_recharge_attempts.php` | NEW | §4.1 — unchanged from the original pass: pure local-data copy for AutoRecharge only; `provider_charge_reference` is deliberately **not** backfilled here (§4.1's own table — it is not reliably known for historical AutoRecharge rows without a new provider call, which governance does not authorize). |
| 3 | `database/migrations/2026_08_29_120003_add_funding_attempt_id_index_to_business_usage_ledger_entries_table.php` | NEW | §4.5/§4.6/§4.4 — unchanged from the original pass. |
| 4 | `database/migrations/2026_08_29_120004_add_normalized_outcome_columns_to_payment_provider_events_table.php` | NEW | §4.12 — the seven new nullable audit columns. |
| 5 | `app/Jobs/Usage/RetryStuckPaymentProviderEvents.php` | NEW | §4.11 — the retry/reclaim scanner job. |
| 6 | `app/Models/BusinessFundingAttempt.php` | MODIFIED | §4.1 — `$fillable` gains both new columns. |
| 7 | `app/Models/PaymentProviderEvent.php` | MODIFIED | §4.12 — `$fillable` gains the seven new columns; `$casts` unchanged (all plain scalars). |
| 8 | `app/Repositories/Contracts/BusinessFundingAttemptRepository.php` | MODIFIED | §4.1 — two new methods: `findByProviderPaymentIntentReference(string $reference): ?BusinessFundingAttempt`, `findByProviderChargeReference(string $reference): ?BusinessFundingAttempt`. |
| 9 | `app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php` | MODIFIED | Implements both. |
| 10 | `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` | MODIFIED | §4.5/§4.6/§4.7 — four new methods, all bounded SQL-aggregate/DISTINCT reads returning exact strings, none loading an unbounded row set into PHP: `sumRefundedMicroForFundingAttempt(int $fundingAttemptId): string`; `sumDisputeMicroForFundingAttemptAndDispute(int $fundingAttemptId, string $providerDisputeId, UsageLedgerEntryType $entryType): string`; `distinctDisputeReferencesForFundingAttempt(int $fundingAttemptId): array`; `findCreditEntryForFundingAttempt(int $fundingAttemptId): ?BusinessUsageLedgerEntry` (retained, unchanged in purpose, from the original pass). |
| 11 | `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php` | MODIFIED | Implements all four — `sumRefundedMicroForFundingAttempt()`/`sumDisputeMicroForFundingAttemptAndDispute()` via `SELECT COALESCE(SUM(gross_amount_micro), 0) ...` returned as a string (`DB::table()`, mirroring the margin-aggregate's own exact-string discipline, never `$this->query()`/Eloquent's own `integer`-cast attribute pipeline for these two, for the identical reason the margin aggregate itself avoids it). |
| 12 | `app/Repositories/Contracts/PaymentProviderEventRepository.php` | MODIFIED | §4.11/§4.12 — `markProcessed()`/`markIgnored()` each gain one new trailing optional parameter, `array $attribution = []`; two new methods, `retryable(int $maxAttempts, int $limit): Collection`, `recentOutcomes(int $limit = 50): Collection`. |
| 13 | `app/Repositories/Eloquent/EloquentPaymentProviderEventRepository.php` | MODIFIED | Implements the widened signatures and both new methods. |
| 14 | `app/Library/Usage/UsageWalletManager.php` | MODIFIED | §4.4/§4.9/§4.10 — three public methods (renamed/re-scoped from the original pass's two): `applyProviderRefund(BusinessFundingAttempt $attempt, int $amountMicro, string $correlationKey, ?string $providerChargeReference): ?BusinessUsageLedgerEntry` (§4.10's own exact `Refund` row shape; returns `null`, mutates nothing, for `$amountMicro <= 0`); `applyDisputeWithdrawal(BusinessFundingAttempt $attempt, int $amountMicro, string $providerDisputeId, string $correlationKey, ?string $disputeReason): ?BusinessUsageLedgerEntry` (§4.10's own `DisputeChargeback` row shape; suspends billing per §4.9's own redundant-suspend guard; dispatches `BusinessWalletDebited`/`BusinessWalletDebtIncurred`); `reinstateDisputedFunds(BusinessUsageLedgerEntry $originalChargebackEntry, int $amountMicro, string $providerDisputeId, string $correlationKey): ?BusinessUsageLedgerEntry` (§4.4's own current-wallet-state formula; returns `null` for `$amountMicro <= 0`; dispatches `BusinessWalletCredited`/`BusinessWalletDebtCleared`). |
| 15 | `app/Library/Usage/UsageBillingCheckoutManager.php` | MODIFIED | §4.1/§4.5/§4.6 — (a) `minorUnitsToMicro()`/`expectedMicroForMinorUnits()`, unchanged from the original pass's own design; (b) `confirmSucceeded()`/`finalizeFundingAttemptState()` each gain **two** new trailing optional parameters this round (`?string $resolvedProviderPaymentIntentReference = null, ?string $resolvedProviderChargeReference = null`), both threaded from the three existing call sites exactly as the original pass's single-parameter widening was; (c) the defensive assertion (§4.1) inside `finalizeFundingAttemptState()`; (d) three new public orchestration methods, `applyRefundOutcome(BusinessFundingAttempt $attempt, int $cumulativeAmountRefundedMicro, ?int $providerEventId): void`, `applyDisputeChargebackOutcome(BusinessFundingAttempt $attempt, string $providerDisputeId, array $withdrawalBalanceTransactions, ?int $providerEventId): void`, `applyDisputeReinstatementOutcome(BusinessFundingAttempt $attempt, string $providerDisputeId, array $reinstatementBalanceTransactions, ?int $providerEventId): void` — each locks the wallet row, computes the appropriate independent accumulator (§4.5), calls exactly one `UsageWalletManager` method per genuinely-new balance transaction (§4.3/§4.8's own per-transaction idempotency), then calls the new private `recomputeFundingAttemptState()` (§4.6) once, all inside one outer `DB::transaction()`. |
| 16 | `app/Jobs/Usage/ProcessPaymentProviderEvent.php` | MODIFIED | §3/§4.1/§4.3/§4.12/§4.13 — `handle()`'s own `match` widened to recognize `charge.refunded`, `charge.dispute.funds_withdrawn`, `charge.dispute.funds_reinstated`, `charge.dispute.created`/`.updated`/`.closed`, and `refund.created`/`refund.updated`/`refund.failed`/`charge.refund.updated` — all **before** the existing metadata-based default. New private methods: `processChargeRefund()`, `processDisputeWithdrawal()`, `processDisputeReinstatement()`, `processDisputeAuditOnlyEvent()`, `processRefundObjectAuditOnlyEvent()` — each performs the dual-identifier resolution (§4.1), the ambiguity/no-match failure branches, currency verification, and (for the two mutating methods) calls exactly one `UsageBillingCheckoutManager` orchestration method; every terminal call (`markProcessed()`/`markIgnored()`) passes the new `$attribution` array (§4.12). |
| 17 | `app/Console/Kernel.php` | MODIFIED | §4.11 — one new line, `$schedule->job(new RetryStuckPaymentProviderEvents())->everyFiveMinutes();`, placed alongside the existing `ReconcileProviderPendingState`/`PurgeExpiredWebhookPayloads` scheduling. |
| 18 | `app/Http/Controllers/Admin/PaymentProviderEventController.php` | MODIFIED | §4.12 — `index()` gains one new view-data key, `recentOutcomes`. |
| 19 | `resources/views/admin/usage-billing/provider-events/index.blade.php` | MODIFIED | §4.12 — one new `x-card`/`x-table` section; the existing exhausted-events table and disposition form are byte-for-byte unmodified. |

**NOT_REQUIRED, unchanged from the original pass, plus one addition:**

| Path | Reason |
|---|---|
| Every original-pass NOT_REQUIRED entry (admin controllers/views beyond item 18/19 above, `routes/public.php`, `AppServiceProvider.php`, any new PHP enum, any new exception class, any new `App\Events\Usage\*`/`App\Notifications\Usage\*` class, the gateway/DTO boundary, `ReconcileProviderPendingState.php`/`PurgeExpiredWebhookPayloads.php` bodies, `BusinessBillingReceipt.php`/`ensureFundingReceipt()`) | Unchanged reasoning — none of this round's corrections requires any of them. |
| `config/usage_billing.php` | The new scanner's batch limit is a plain class constant (§4.11, matching `ReconcileProviderPendingState::STUCK_AFTER_MINUTES`'s own precedent), not a new config key. |

---

## 6. Exact test allow-list — recomputed mechanically, 8 new files, 80 methods, no existing test file modified

**No existing test file requires modification** — re-confirmed; the new columns/indexes remain additive and invisible to every existing assertion, and no existing test constructs any of the event fixtures this contract introduces.

| # | Path | Methods |
|---|---|---|
| 1 | `tests/Feature/Usage/ProviderPaymentIdentifierResolutionTest.php` | 12 |
| 2 | `tests/Feature/Usage/ProviderRefundOutcomeTest.php` | 14 |
| 3 | `tests/Feature/Usage/ProviderDisputeOutcomeTest.php` | 22 |
| 4 | `tests/Feature/Usage/UsageWalletManagerReversalTest.php` | 12 |
| 5 | `tests/Feature/Usage/ProviderRefundDisputeSurfaceBoundaryTest.php` | 5 |
| 6 | `tests/Feature/Usage/PaymentProviderEventRetryReclaimTest.php` | 7 |
| 7 | `tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php` | 6 |
| 8 | `tests/Feature/Usage/ProviderRefundDisputeConcurrencyTest.php` | 2 |

**Total: 80 methods across 8 files.**

### `ProviderPaymentIdentifierResolutionTest.php` (12) — proves §4.1

1. `test_provider_payment_intent_reference_is_persisted_for_a_checkout_backed_success`
2. `test_provider_charge_reference_is_persisted_for_a_checkout_backed_success`
3. `test_provider_payment_intent_reference_is_persisted_for_an_auto_recharge_success`
4. `test_provider_charge_reference_is_persisted_when_already_available_for_an_auto_recharge_success_via_sync_return`
5. `test_provider_charge_reference_remains_null_for_an_auto_recharge_success_confirmed_via_the_ordinary_webhook_path`
6. `test_an_event_resolving_by_both_payment_intent_and_charge_to_the_same_attempt_is_processed`
7. `test_an_event_whose_payment_intent_and_charge_resolve_to_different_attempts_fails_closed_with_zero_mutation`
8. `test_an_event_resolving_only_by_charge_reference_is_processed`
9. `test_an_event_resolving_only_by_payment_intent_reference_is_processed`
10. `test_an_event_resolving_by_neither_reference_fails_closed`
11. `test_both_provider_reference_columns_enforce_uniqueness`
12. `test_the_auto_recharge_backfill_migration_copies_the_existing_local_reference_with_no_provider_call`

### `ProviderRefundOutcomeTest.php` (14) — proves §4.2, §4.5, §4.7, §4.10

1. `test_a_full_refund_event_writes_a_refund_ledger_entry_and_recomputes_the_attempt_to_refunded`
2. `test_a_partial_refund_event_writes_a_refund_ledger_entry_for_the_partial_amount_and_leaves_the_attempt_succeeded`
3. `test_a_second_partial_refund_event_applies_only_the_incremental_delta_since_the_first`
4. `test_an_out_of_order_replayed_refund_event_reporting_an_already_applied_cumulative_amount_produces_zero_additional_mutation`
5. `test_a_refund_exceeding_available_balance_clears_available_balance_and_creates_debt`
6. `test_a_refund_is_bounded_and_never_exceeds_the_original_funding_attempts_expected_amount`
7. `test_a_refund_for_an_addon_purchase_with_direct_deliverable_fulfillment_produces_no_wallet_mutation_but_is_durably_audited`
8. `test_a_refund_for_an_addon_purchase_with_wallet_credit_fulfillment_reverses_the_original_credit`
9. `test_a_refund_event_missing_both_payment_intent_and_charge_fails_with_no_mutation`
10. `test_a_refund_event_for_an_unresolvable_reference_fails_with_no_mutation`
11. `test_a_refund_event_with_a_mismatched_currency_fails_with_no_mutation`
12. `test_refund_progress_is_computed_solely_from_refund_entries_and_is_unaffected_by_a_dispute_on_the_same_attempt`
13. `test_a_refund_never_affects_an_unrelated_businesss_wallet`
14. `test_refund_reason_is_never_null_and_matches_the_deterministic_template`

### `ProviderDisputeOutcomeTest.php` (22) — proves §4.3, §4.4, §4.6, §4.9

1. `test_a_funds_withdrawn_event_applies_the_signed_balance_transaction_amount_as_a_dispute_chargeback`
2. `test_a_funds_withdrawn_event_uses_the_balance_transaction_amount_not_the_disputed_claim_amount`
3. `test_a_dispute_exceeding_available_balance_clears_available_balance_and_creates_debt`
4. `test_a_replayed_funds_withdrawn_event_reporting_an_already_applied_balance_transaction_produces_zero_additional_mutation`
5. `test_a_funds_withdrawn_event_with_an_empty_balance_transactions_array_produces_no_mutation_and_is_durably_audited`
6. `test_a_funds_reinstated_event_applies_the_signed_balance_transaction_amount_as_a_correction_reversal`
7. `test_a_partial_reinstatement_clears_only_part_of_the_outstanding_dispute_exposure_and_leaves_the_attempt_disputed`
8. `test_a_full_reinstatement_after_debt_was_already_cleared_by_an_intervening_top_up_credits_available_balance_without_creating_negative_debt`
9. `test_a_reinstatement_clears_current_debt_first_then_credits_any_remainder_to_available_balance`
10. `test_a_reinstatement_is_bounded_to_the_actual_withdrawn_amount_for_that_specific_dispute`
11. `test_a_duplicate_reinstatement_event_for_the_same_balance_transaction_produces_zero_additional_mutation`
12. `test_a_reinstatement_dispatches_business_wallet_credited_and_or_debt_cleared_matching_the_current_state_based_split`
13. `test_multiple_disputes_for_the_same_attempt_are_tracked_independently`
14. `test_reinstating_one_of_two_disputes_leaves_the_attempt_disputed_while_the_other_remains_outstanding`
15. `test_the_attempt_returns_to_refunded_after_the_final_outstanding_dispute_exposure_clears_on_a_fully_refunded_attempt`
16. `test_a_lost_dispute_leaves_the_attempt_disputed_permanently_with_no_reversal`
17. `test_a_dispute_created_event_is_durably_recorded_and_ignored_with_no_mutation`
18. `test_a_dispute_updated_event_is_durably_recorded_and_ignored_with_no_mutation`
19. `test_a_dispute_closed_event_is_durably_recorded_and_ignored_with_no_mutation_regardless_of_status`
20. `test_a_dispute_event_for_an_unresolvable_reference_fails_with_no_mutation`
21. `test_a_dispute_never_affects_an_unrelated_businesss_wallet`
22. `test_a_second_dispute_while_billing_is_already_suspended_writes_no_redundant_suspended_transition`

### `UsageWalletManagerReversalTest.php` (12) — proves §5 item 14

1. `test_apply_provider_refund_debits_available_balance_when_sufficient`
2. `test_apply_provider_refund_creates_debt_when_available_balance_is_insufficient`
3. `test_apply_provider_refund_returns_null_and_mutates_nothing_for_a_non_positive_amount`
4. `test_apply_dispute_withdrawal_debits_available_balance_when_sufficient`
5. `test_apply_dispute_withdrawal_creates_debt_when_available_balance_is_insufficient`
6. `test_apply_dispute_withdrawal_dispatches_business_wallet_debited_and_or_debt_incurred_matching_the_split`
7. `test_apply_dispute_withdrawal_suspends_billing_status`
8. `test_apply_dispute_withdrawal_does_not_re_suspend_an_already_suspended_wallet`
9. `test_reinstate_disputed_funds_clears_current_debt_before_crediting_available_balance`
10. `test_reinstate_disputed_funds_never_produces_negative_debt_when_debt_was_already_cleared`
11. `test_reinstate_disputed_funds_sets_reversed_entry_id_to_the_original_chargeback_entry`
12. `test_reinstate_disputed_funds_dispatches_business_wallet_credited_and_or_debt_cleared_matching_current_state`

### `ProviderRefundDisputeSurfaceBoundaryTest.php` (5) — proves §9

1. `test_reversal_and_dispute_manager_methods_are_never_called_from_a_controller`
2. `test_process_payment_provider_event_never_calls_a_charge_originating_manager_method`
3. `test_no_new_production_file_contains_a_raw_billing_table_query_outside_the_two_eloquent_repositories`
4. `test_apply_outcome_orchestration_methods_are_never_called_outside_process_payment_provider_event`
5. `test_no_new_admin_controller_action_or_route_is_introduced_beyond_the_widened_provider_events_index`

### `PaymentProviderEventRetryReclaimTest.php` (7) — proves §4.11

1. `test_a_failed_event_below_max_attempts_is_redispatched_by_the_scanner`
2. `test_a_stale_processing_event_past_its_lease_is_reclaimed_by_the_scanner`
3. `test_an_event_at_max_attempts_is_never_redispatched_by_the_scanner`
4. `test_an_exhausted_event_becomes_administrator_visible_in_the_existing_exhausted_events_queue`
5. `test_processed_ignored_and_disposed_events_are_never_redispatched_by_the_scanner`
6. `test_the_scanner_batch_is_bounded_by_its_own_limit`
7. `test_the_scanner_performs_no_accounting_mutation_itself`

### `PaymentProviderEventDurableAuditTest.php` (6) — proves §4.12, §4.13

1. `test_a_processed_refund_outcome_is_attributed_with_business_and_funding_attempt_identity`
2. `test_an_ignored_dispute_created_event_is_attributed_with_normalized_status_and_reason`
3. `test_a_direct_deliverable_addon_refund_is_durably_audited_despite_zero_wallet_mutation`
4. `test_normalized_attribution_survives_payload_purge`
5. `test_the_provider_events_admin_surface_lists_recent_normalized_outcomes_bounded_by_limit`
6. `test_a_refund_object_event_is_recorded_as_audit_only_with_no_wallet_mutation`

### `ProviderRefundDisputeConcurrencyTest.php` (2) — proves §4.8, using the established subprocess/causal-barrier convention

Mirrors `ConcurrentTopUpConcurrencyTest.php`'s own exact infrastructure verbatim (`Symfony\Component\Process\Process`, a `WAITING`-handshake before a shared signal file releases both child processes, no `RefreshDatabase`, manual fixture teardown) — a replay test alone is not proof of concurrent processing; these two prove it with genuinely independent OS processes racing the identical row.

1. `test_two_different_provider_event_ids_reporting_the_same_cumulative_refund_amount_credit_the_wallet_exactly_once`
2. `test_two_different_provider_event_ids_reporting_the_same_balance_transaction_apply_the_dispute_chargeback_exactly_once`

---

## 7. Schema/migration decisions — exact DDL, corrected and extended

**Migration 1:**

```php
Schema::table('business_funding_attempts', function (Blueprint $table) {
    $table->string('provider_payment_intent_reference', 191)->nullable()->after('provider_session_or_intent_reference');
    $table->string('provider_charge_reference', 191)->nullable()->after('provider_payment_intent_reference');
});
Schema::table('business_funding_attempts', function (Blueprint $table) {
    $table->unique('provider_payment_intent_reference', 'bfa_provider_payment_intent_reference_unique');
    $table->unique('provider_charge_reference', 'bfa_provider_charge_reference_unique');
});
```

**Migration 2** — unchanged from the original pass (AutoRecharge-only, pure local-data `UPDATE`, no provider call).

**Migration 3** — unchanged from the original pass (index on `business_usage_ledger_entries.funding_attempt_id`).

**Migration 4:**

```php
Schema::table('payment_provider_events', function (Blueprint $table) {
    $table->unsignedBigInteger('business_id')->nullable()->after('provider_object_id');
    $table->unsignedBigInteger('funding_attempt_id')->nullable()->after('business_id');
    $table->string('normalized_outcome', 32)->nullable()->after('funding_attempt_id');
    $table->string('normalized_status', 32)->nullable()->after('normalized_outcome');
    $table->bigInteger('normalized_amount_micro')->nullable()->after('normalized_status');
    $table->string('normalized_currency_code', 3)->nullable()->after('normalized_amount_micro');
    $table->string('normalized_reason', 64)->nullable()->after('normalized_currency_code');
});
```

No `FOREIGN KEY` on `business_id`/`funding_attempt_id` — matching this exact table's own established `disposed_by_user_id` precedent (nullable audit reference, deliberately no FK).

No other schema/migration change is authorized or required by this contract.

---

## 8. Preserved invariants — re-verified against the corrected design

Every invariant from the original pass's §8 (`committed_spend_this_period_micro` never touched; `recharged_this_period_micro` never decremented by `Refund`/`DisputeChargeback`/`CorrectionReversal`; `business_usage_ledger_entries` remains append-only; outstanding-debt-denies-reservations centrally enforced; no raw query outside an owning repository) is re-verified against the **corrected** write paths in §5 item 14 and holds identically — `applyProviderRefund()`/`applyDisputeWithdrawal()`/`reinstateDisputedFunds()` write only `available_balance_micro`/`debt_balance_micro`, exactly as their §4 predecessors did, and the new bounded SQL-aggregate reads (§5 item 10/11) are the only new raw-query-capable code, confined to the two already-authorized Eloquent repository implementations.

**One invariant newly re-verified this round, specific to the correction:** debt can never go negative. `reinstateDisputedFunds()`'s own `debtCleared = min(reinstatementAmountMicro, max(0, wallet.debt_balance_micro))` makes this true by construction, independent of what the *original* `DisputeChargeback` entry's own stored deltas happened to be — proven by `UsageWalletManagerReversalTest::test_reinstate_disputed_funds_never_produces_negative_debt_when_debt_was_already_cleared`.

---

## 9. Guarantee-by-guarantee mapping — corrected

1. **A refund/dispute event resolves to exactly one Business, never ambiguously — or fails closed on a genuine conflict.** §4.1; proven by `ProviderPaymentIdentifierResolutionTest` methods 6–10.
2. **Full, partial, and repeated partial refunds are each handled correctly, idempotently, and independent of any dispute on the same attempt.** §4.2, §4.5; proven by `ProviderRefundOutcomeTest` methods 1–4, 12.
3. **Dispute mutation is keyed to the actual, signed balance transaction — never the claimed dispute amount or status alone.** §4.3; proven by `ProviderDisputeOutcomeTest` methods 1–2.
4. **A reinstatement never produces negative debt and is bounded to the specific dispute's own actual withdrawn amount.** §4.4; proven by `ProviderDisputeOutcomeTest` methods 7–11, `UsageWalletManagerReversalTest` methods 9–10.
5. **Funding-attempt state reflects every outstanding dispute, not just the most recently processed event.** §4.6; proven by `ProviderDisputeOutcomeTest` methods 13–16.
6. **Duplicate webhook delivery and concurrent processing — including two different provider event ids reporting the identical outcome — can never apply the same financial effect twice.** §4.8; proven by `ProviderRefundOutcomeTest::test_an_out_of_order_replayed_refund_event_...`, `ProviderDisputeOutcomeTest::test_a_replayed_funds_withdrawn_event_...`, and — genuinely, not merely by replay — `ProviderRefundDisputeConcurrencyTest`'s two forced-race methods.
7. **No fabricated successful-payment receipt is ever created for a refund/dispute.** §4.10 of the original pass, unchanged; enforced by construction.
8. **Every outcome, mutating or not — including the entire `refund.*` object family — is durably recorded and remains Business-attributed and administrator-visible after payload purge.** §4.12, §4.13; proven by `PaymentProviderEventDurableAuditTest`.
9. **A failed or stale event is actually retried/reclaimed, actually reaches exhaustion, and actually becomes administrator-visible — not merely claimed to.** §4.11; proven by `PaymentProviderEventRetryReclaimTest`.
10. **No new admin surface beyond the one widened, reused read; no new customer surface; no origination of any provider-side action.** §4.14 of the original pass (unchanged) plus §4.12's own widening, which reuses rather than duplicates; proven by `ProviderRefundDisputeSurfaceBoundaryTest`.
11. **Every wallet-mutating write is authority-correct, lock-ordered identically to every existing wallet-mutating write, and every mandatory-reason row is genuinely non-blank.** §4.8, §4.10, §5 item 14; proven by `UsageWalletManagerReversalTest`, `ProviderRefundOutcomeTest::test_refund_reason_is_never_null_...`.

---

## 10. Bounded reads — restated and extended

All six new `BusinessUsageLedgerEntryRepository`/`PaymentProviderEventRepository` reads (§5 items 10, 12) are either `LIMIT`-bounded (`retryable()`, `recentOutcomes()`) or scoped to a single `funding_attempt_id`/`(funding_attempt_id, provider_dispute_id)` pair and indexed (Migration 3) — none is a table scan, none requires pagination, and the two SQL-aggregate sums are computed entirely by MySQL as exact `DECIMAL`/`BIGINT` results returned as PHP strings, never by loading a row set for PHP-side reduction (corrected per blocker 5).

---

## 11. Regression commands — for the future, separately authorized implementation phase

1. `php artisan test tests/Feature/Usage/ProviderPaymentIdentifierResolutionTest.php`
2. `php artisan test tests/Feature/Usage/ProviderRefundOutcomeTest.php`
3. `php artisan test tests/Feature/Usage/ProviderDisputeOutcomeTest.php`
4. `php artisan test tests/Feature/Usage/UsageWalletManagerReversalTest.php`
5. `php artisan test tests/Feature/Usage/ProviderRefundDisputeSurfaceBoundaryTest.php`
6. `php artisan test tests/Feature/Usage/PaymentProviderEventRetryReclaimTest.php`
7. `php artisan test tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php`
8. `php artisan test tests/Feature/Usage/ProviderRefundDisputeConcurrencyTest.php`
9. `php artisan test tests/Feature/Usage tests/Unit/Usage` (complete Usage-domain suite)
10. One complete `php artisan test --stop-on-failure` run (full repository suite)
11. `git diff --check`

No command beyond these is authorized; no live-provider-hitting command is ever part of this suite (`FakePaymentProviderGateway` only, unchanged).

---

## 12. Human decisions — three false-open items resolved and locked this round; exactly one genuine decision remains

**Resolved and locked this round (no longer open):**

1. ~~Should billing_status automatically resume when a dispute is won?~~ **Locked: no — administrator-only, unconditionally.** RFC §24's own authorization table reserves *"Set/clear `billing_status = 'suspended'`"* to the platform administrator with no automatic-resume row in either direction; this is not a judgment call this contract is entitled to leave open, and §4.9 above implements it as a hard design decision, not a default subject to override.
2. ~~Should historical Checkout-backed `Succeeded` attempts be backfilled with a live provider call?~~ **Locked: no, explicitly excluded.** Governance authorizes no live Stripe action by this document or its implementation; any future backfill is its own, separately authorized remediation, not a variant of this one.
3. ~~Should `refund.*`/`charge.refund.updated` events be ingested?~~ **Locked: yes, as normalized audit-only events, per §4.13 — mechanically required, not a preference.** `charge.refunded` remains the sole financial-mutation authority for refunds.

**The one genuine, unresolvable-from-the-RFC-or-from-mechanics decision, kept open:**

**Should a chargeback debit and/or billing-status suspension email the Business?**

- **Recommended choice: yes.** `applyDisputeWithdrawal()` can both create debt and immediately suspend billing in the same transaction, with no other change in the Business's own product experience at that instant — silence at the moment of a debt-creating, service-suspending event risks the Business discovering the interruption only when their own usage is denied (RFC's own outstanding-debt-denies-reservations rule, unchanged), with no prior explanation. This is a materially different risk profile from an ordinary usage overage (which also does not notify, but never suspends billing outright) — the precedent this contract's original pass leaned on for a "no notification" default does not actually cover the suspension case.
- **Alternative choice: no**, for strict consistency with every other existing debit path's own silence, deferring entirely to the administrator-visible audit trail (§4.12) and the existing dashboard for after-the-fact discovery.
- **This document does not assume either answer.** Implementing either requires a small, additive extension to §5 item 14/16 (a new notification dispatch call from inside `applyDisputeWithdrawal()`, or from `ProcessPaymentProviderEvent`'s own `processDisputeWithdrawal()` after commit) — not a redesign of anything locked above. **Stopping here for the human decision.**

---

## 13. Coverage matrix — unchanged in shape from the original pass; every entry now points at the corrected section

| Question | Answered in |
|---|---|
| Which provider refund/dispute webhook/event types enter the system? | §3 |
| How is each event authenticated, normalized, deduplicated, retained, disposed? | §4.8, §4.11, §4.13 (retry/reclaim now real, not merely claimed) |
| How is the affected Business/attempt/charge/wallet/entry identified without cross-Business ambiguity? | §4.1 (two identifiers, explicit mismatch failure) |
| Full/partial/repeated refunds; dispute created/updated/won/lost/reinstated | §4.2, §4.3 |
| Which outcomes change wallet available balance or debt? | §4.3, §4.4, §4.7 |
| Which exact ledger entry types are produced; row shapes | §4.10 |
| How are amounts bounded? | §4.4, §4.5 (two independent accumulators) |
| What database uniqueness/idempotency boundary applies? | §4.8 |
| Insufficient available balance? | §4.4, §4.10 (debt formation, never negative) |
| Receipts/evidence | §4.10 of the original pass, unchanged |
| Domain events/jobs/notifications/audit records | §4.12, §12 (one open decision) |
| Outcomes requiring no mutation but still requiring durable audit | §4.3, §4.12, §4.13 |
| Retry/reconciliation/locking/transaction/crash-recovery boundaries | §4.8, §4.11 (now a real, allow-listed mechanism) |
| Which existing admin surface is reused; is any new HTTP/admin action required? | §4.12 — widened, not duplicated |
| What intentionally remains out of scope? | §4.14 of the original pass, unchanged |
| Multi-dispute / funding-attempt state correctness | §4.6 |

---

## 14. Validation performed before commit

- Full document read back for internal consistency — every cross-reference in §5–§13 resolves to a §4 finding actually stated above it; every blocker in the correction record above maps to a specific, named subsection.
- Every production/test path recounted mechanically, one row at a time, with no repository pair collapsed into a single count: **19 production paths (5 new + 14 modified)**, **80 test methods across 8 new files**.
- `git diff --check` — clean.
- `git diff --name-only origin/main...HEAD` — exactly this one file.
- Confirmed no product, test, schema, config, route, or RFC-source file changed; `docs/automation/AI-AUTONOMY-STATE.json` untouched.
