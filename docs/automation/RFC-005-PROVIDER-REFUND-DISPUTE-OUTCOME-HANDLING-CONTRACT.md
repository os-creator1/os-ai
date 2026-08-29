# RFC-005 Remediation #6 — Provider Refund/Dispute Outcome Handling Contract

## Governance

- `maximum_correction_rounds`: 2 — **2 of 2 ordinary correction rounds consumed. 0 remaining.**
- `human_only_merge`: true — this document, and any implementation branch built from it, is merged only by a human.
- **M6 remains frozen.** Nothing in this document authorizes any M6 work — no conformance document, no deployment guide, no release/tag work of any kind.
- No tag is authorized by this document.
- No deployment, live Stripe action, refund, dispute simulation against production, rate activation, meter activation, or pilot activation is authorized by this document or by authoring it.
- `docs/automation/AI-AUTONOMY-STATE.json` is untouched by this document and must remain untouched by any commit on this branch.
- This remediation is sequenced **before** remediation #7 (RFC-005 §35 test-coverage completion).
- **Authoring only.** This document locks a design. Implementing it is a separately, explicitly authorized future phase — this contract does not itself grant implementation authority, and no product or test code accompanies it.

**Base SHA:** `a8d2a0f9c03f8295262a9ec7b36374b4b0437294` — PR #148's merge commit, confirmed as `origin/main` before the branch was first created.

**Branch:** `chore/rfc-005-provider-refund-dispute-outcome-handling-contract`.

**No human product decision remains open.** The dedicated chargeback/dispute email (§11) is resolved: send it. The wallet-refund debt policy (§6) is a binding human directive, applied throughout this document — not a decision this document leaves open.

---

## Correction history (informational only — no live design, guarantee, allow-list row, or test instruction below depends on this section; every normative rule is restated live in its own section)

- **Correction Round 1** corrected twelve blockers in the original authoring pass, spanning identification, dispute accounting authority, reinstatement mechanics, accumulator isolation, state-machine correctness, ledger row completeness, the retry mechanism's actual existence, audit visibility, unhandled event families, and false-open human decisions.
- **Correction Round 2 (this document)** corrected ten further blockers confirmed independently against current `main` and Stripe's own current documentation — self-containment (no rule depends on deleted text), `direct_deliverable` outcome persistence and idempotency, a received-row recovery hole in the retry scanner, an unbounded dispute-reference scan replaced with one bounded query, dispute balance-transaction cardinality locked to Stripe's own documented shape with mechanical reversal lineage, the low-balance/auto-recharge/receipt boundary, the one open human decision (resolved: send a dedicated notification), ambiguous audit amounts split into reported/applied fields backed by a typed outcome result, the complete transaction/crash-recovery sequence, and every allow-list/test count recomputed from zero — **and, mid-round, one binding human policy directive, applied throughout this same round: a provider-confirmed Refund can never create or increase wallet debt.** Previously consumed usage remains consumed and non-refundable; an externally-issued over-refund (bypassing this platform's own policy directly against Stripe) is recorded, capped at currently-available balance, and flagged for administrator review — never retried indefinitely, never quietly absorbed as debt. `DisputeChargeback` is explicitly unaffected by this policy and may still create debt, exactly as the RFC's own §13 delta table already specifies.

---

## 1. Required reading, confirmed by direct re-read this round

- The authoritative RFC (`docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`), specifically §12 (ledger invariants), §13 (the twelve-entry-type delta table, mandatory-reason rule, `reversed_entry_id`), §17 (provider-customer identity), §20/§21 (Stripe boundary, webhook verification/claim/disposition), §23 (refunds/disputes/receipts), §24 (authorization table), §25–§30 (schema/enums/managers/jobs/events).
- Every merged RFC-005 milestone/correction contract whose exclusions mention refunds/disputes/reversals — every load-bearing exclusion from these is restated in full, in this document's own words, in §17 below; none is referenced by pointer only.
- Current production code, re-read directly: `app/Http/Controllers/StripeWebhookController.php`; `app/Jobs/Usage/ProcessPaymentProviderEvent.php`; `app/Jobs/Usage/ReconcileProviderPendingState.php`; `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php`; `app/Jobs/Base.php` (`$tries = 1; $maxExceptions = 1;`); `app/Console/Kernel.php`; `app/Http/Controllers/Admin/PaymentProviderEventController.php`; `app/Repositories/Contracts/PaymentProviderEventRepository.php` and its Eloquent implementation; `app/Library/Usage/UsageWalletManager.php` in full (`lowBalanceMarkerUpdate()`'s exact contract; every existing debit call site's exact `EvaluateBusinessAutoRecharge`/`SendLowBalanceNotification` pattern in `reserve()` and `commit()`'s own overage branch; `setBillingStatus()` is not self-idempotent); `app/Library/Usage/UsageBillingCheckoutManager.php` in full; `app/Jobs/Usage/SendLowBalanceNotification.php` and `app/Notifications/Usage/LowBalanceNotification.php` (the exact template this document's new notification job/class mirror); `app/Repositories/Contracts/BusinessBillingContactRepository.php`; `app/Enums/Usage/BillingStatusTransitionSource.php` (currently `DisputeWebhook`\|`AdminAction` — this document adds one new case, §11); every relevant migration under `database/migrations/2026_08_16_*`/`2026_08_27_*`/`2026_08_28_*` (`business_usage_ledger_entries.provider_reference` already exists, nullable, unused; `payment_provider_events` carries only its own `UNIQUE(provider, provider_event_id)` index); the full existing webhook/provider-event test suite (`tests/Feature/Usage/PaymentProviderEventSchemaTest.php`'s three methods require no modification; `tests/Feature/Usage/SendLowBalanceNotificationTest.php`'s own thirteen-method shape is the sizing precedent for the new notification test file); `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` (the subprocess/causal-barrier convention this document's forced-concurrency tests reuse).
- Stripe's own current official documentation, fetched live: `docs.stripe.com/api/charges/object`, `docs.stripe.com/api/disputes/object` (*"a list of zero, one, or two balance transactions"* — locked exactly, §5), `docs.stripe.com/api/balance_transactions/object`, `docs.stripe.com/api/refunds/object`, `docs.stripe.com/api/events/types`.

---

## 2. Stripe object/event facts

**`Charge` object:** `id`, `payment_intent` (nullable), `customer`, `amount`, `amount_refunded` (cumulative, monotonically non-decreasing), `refunded` (boolean, true only once fully refunded), `currency`, `disputed`. `metadata` is independent, never inherited from the originating PaymentIntent.

**`Dispute` object:** `id`, `charge`, `payment_intent` (nullable), `amount` (the cardholder's *claimed* disputed amount — not a reliable statement of actual funds movement, §7), `currency`, `status`, `reason`, and **`balance_transactions`** — *"a list of zero, one, or two balance transactions that show funds withdrawn and reinstated to your Stripe account as a result of this dispute."* Locked to exactly that documented shape.

**`BalanceTransaction` object:** `id` (`txn_...`), `amount` (signed, minor units — negative = funds sent away from the platform's balance, positive = funds credited to it), `currency`, `net`, `type`.

**`Refund` object:** `id` (`re_...`), `amount`, `currency`, `charge` (nullable), `payment_intent` (nullable), `status`, `balance_transaction`.

**Locked event routing:**

| Event type | Drives mutation? | Handling |
|---|---|---|
| `charge.refunded` | Yes — the sole cumulative-refund mutation authority | §6 |
| `charge.dispute.funds_withdrawn` | Yes | §8 |
| `charge.dispute.funds_reinstated` | Yes | §9 |
| `charge.dispute.created` / `.updated` / `.closed` | No | Audit-only, §15 |
| `refund.created` / `refund.updated` / `refund.failed` / `charge.refund.updated` | No | Audit-only, §16 |

---

## 3. Identification — two independent provider references, with an explicit ambiguity failure mode

`payment_intent` is nullable on both `Charge` and `Dispute`. Two already-normalized result DTOs expose a second, independent identifier at zero additional provider-call cost: `CheckoutSessionResult::$providerPaymentIntentId`/`$receiptChargeId`, `PaymentIntentResult::$receiptChargeId`.

**Locked:** two new nullable, independently-`UNIQUE`-when-populated columns on `business_funding_attempts`: `provider_payment_intent_reference`, `provider_charge_reference`. Populated synchronously, inside the transaction that finalizes the attempt to `Succeeded`, from data already in hand:

| Purpose/path | `provider_payment_intent_reference` | `provider_charge_reference` |
|---|---|---|
| `AutoRecharge` via `confirmAttemptFromReturn()`/`retryFundingAttemptAsAdministrator()` (both already call `retrievePaymentIntent()`) | `$attempt->provider_session_or_intent_reference` | `$paymentIntent->receiptChargeId` — already fetched |
| `AutoRecharge` via the ordinary webhook path (trusts the raw, verified payload directly, never fetches a `PaymentIntentResult`) | `$attempt->provider_session_or_intent_reference` | Left `NULL` — no new provider call is introduced; harmless, PI-reference resolution for AutoRecharge never fails |
| `ManualTopUp`/`AddonPurchase`, any confirmation path (all already call `retrieveCheckoutSession()`) | `$session->providerPaymentIntentId` | `$session->receiptChargeId` — already fetched |

**At-least-one-identifier precondition** is already structurally guaranteed (`fundingAttemptCheckoutVerified()` already refuses a null `providerPaymentIntentId`; AutoRecharge requires `provider_session_or_intent_reference` non-null before any status check). `finalizeFundingAttemptState()` adds one defensive assertion restating this.

**Routing:** resolve both `payment_intent` and (`Charge.id`/`Dispute.charge`/`Refund.charge`) independently.

- **Both resolve, same attempt** → proceed.
- **Both resolve, different attempts** → `markFailed('cross_reference_ambiguity')`, zero mutation.
- **Exactly one resolves** → use it.
- **Neither resolves** → `markFailed('no_matching_local_record')`, zero mutation.

---

## 4. Which entries can ever be the subject of a provider refund — the wallet-backed determination and the promotional/manual-credit boundary

`findCreditEntryForFundingAttempt(int $fundingAttemptId): ?BusinessUsageLedgerEntry` finds the original wallet-crediting entry for a funding attempt, scoped **exclusively** to `entry_type IN ('paid_top_up', 'auto_recharge')`. `ManualTopUp`/`AutoRecharge` attempts always have one; an `AddonPurchase` attempt has one only when `fulfillment_mode = wallet_credit`.

**Locked, explicit boundary — restated because a future maintainer must never widen this scope:** `ManualCredit` and `PromotionalCredit` ledger entries are **never** eligible to be selected as refundable provider funding. Neither entry type has a provider payment behind it — `issueManualCredit()` never associates a `funding_attempt_id` with the entries it writes, and no refund/dispute code path this document authorizes ever queries `entry_type IN ('manual_credit', 'promotional_credit')` for any purpose. Complimentary and promotional credit has no cash value and is not cash-refundable through this or any mechanism this document introduces.

**`$walletBacked`**, resolved once per attempt from `findCreditEntryForFundingAttempt() !== null` and held for every reversal call against that attempt, controls whether a mutation touches `available_balance_micro`/`debt_balance_micro` at all (§10) — it never controls whether the outcome's own ledger row is written, whether an accumulator advances, whether the durable audit records the outcome, or whether the funding attempt's own state is recomputed (§13, §18).

**A refunded/disputed `direct_deliverable` AddonPurchase remains historically `Completed` forever.** No refund/dispute code path calls `finalizeAddonPurchaseIfPending()`, `completeAddonPurchaseUnderLock()`, or writes `business_usage_addon_purchases.status`. The later outcome is recorded entirely through the funding attempt's own state (§13), the zero-delta ledger row (§10), and the durable audit (§18) — never by un-completing the purchase or reversing any deliverable. No entitlement/deliverable-rollback mechanism exists or is introduced. Commercial eligibility for refunding an already-delivered digital product remains an entirely external, separately governed business decision — this design only ever records the provider's own already-settled outcome, and a `direct_deliverable` outcome is never classified as a wallet-credit "over-refund" (§6), since no wallet credit ever existed for that money in the first place.

---

## 5. Dispute balance-transaction shape — locked to zero/one/two, exact validation

`Dispute.amount`/`.status` are claims, not settlement facts. The authority is `Dispute.balance_transactions[]`, locked to exactly the documented shape:

- **Zero entries** — no balance impact yet. Zero mutation; audit-only (§15).
- **One entry, negative** — the ordinary withdrawal shape. Drives §8.
- **One entry, positive, on `funds_reinstated` with no accompanying negative entry** — unexpected; fails closed per §9's own explicit rule, never guessed.
- **Two entries, one negative and one positive** — the documented withdrawal-then-reinstatement shape.
- **More than two entries** → `markFailed('malformed_balance_transaction_array')`, zero mutation.
- **Two entries of the same sign** → `markFailed('malformed_balance_transaction_array')`, zero mutation.
- **Duplicate `id` values** → `markFailed('malformed_balance_transaction_array')`, zero mutation.
- **A currency mismatch on any entry** → `markFailed('currency_mismatch')`, zero mutation.

Every applied amount converts via `expectedMicroForMinorUnits()` (§14).

---

## 6. Refund policy — locked, binding: a provider-confirmed Refund can never create or increase wallet debt

**Binding human policy, applied throughout this document:** valid, consumed wallet credit is non-refundable. Previously consumed usage remains consumed. A Refund ledger entry **never** carries a non-zero `debt_delta_micro`, under any circumstance — this supersedes every general "debt formation on insufficient balance" pattern this codebase otherwise uses (`UsageOverageCharge`, `DisputeChargeback`, which are explicitly and deliberately **not** affected by this policy, §8). This design still does not authorize any application-side refund-origination action of any kind — refund initiation remains entirely external (a Stripe dashboard action, a bank-initiated return, or any other provider-side event) and separately governed; any future application-side refund-initiation feature would itself need to validate this same available-balance cap before ever calling Stripe, as its own, separately authorized contract.

**Exact formula, computed inside the locked transaction (wallet row locked first, §20):**

```
providerRefundDelta   = min(providerCumulativeRefundMicro, attempt.expected_amount_micro) − alreadyRecordedRefundGrossMicro
refundableAvailableMicro = max(0, lockedWallet.available_balance_micro)
walletDebitMicro       = min(providerRefundDelta, refundableAvailableMicro)
policyExcessMicro      = providerRefundDelta − walletDebitMicro
```

`alreadyRecordedRefundGrossMicro` is `sumRefundedMicroForFundingAttempt()`'s own existing exact-string SQL aggregate (§12) — unchanged mechanism from the accumulator already locked earlier in this correction round. `providerRefundDelta === 0` is a pure replay/no-op: no row is written, `normalized_outcome = refund_already_applied` (§18), and no further step in this section executes.

**This cap applies to every funding attempt that originally credited the wallet** — `ManualTopUp`, `AutoRecharge`, and `AddonPurchase` with `fulfillment_mode = wallet_credit` alike. **It does not apply to a `direct_deliverable` AddonPurchase at all** — no wallet credit ever existed for that money, so there is no "used credits" calculation to perform; that case remains the unconditional zero-delta design of §10, and is never classified as an over-refund.

**No credit-lot or source-level attribution is introduced.** The wallet's current `available_balance_micro` is a single fungible pool — this design makes no attempt to trace whether "this specific top-up's own money" is still present versus already spent on usage or already mixed with other credits; the cap is simply the wallet's own current total available balance at the moment of processing, exactly matching how every other wallet-debiting method in this codebase already treats `available_balance_micro`.

### Normal, policy-compliant refund (`policyExcessMicro === 0`)

The `Refund` ledger row (§12):

```php
[
    'gross_amount_micro' => $providerRefundDelta,
    'available_delta_micro' => -$walletDebitMicro,
    'reserved_delta_micro' => 0,
    'debt_delta_micro' => 0,   // unconditional — no Refund code path ever writes a positive value here
    // correlation_key, provider_reference, reason: §12, unchanged in shape
]
```

### Externally issued over-refund (`policyExcessMicro > 0`)

The application cannot prevent a bypass of this policy directly against Stripe (a provider-dashboard refund exceeding what this platform would itself allow). Because the webhook arrives only after Stripe has already returned the money, this design does not pretend the outcome never happened, and does not retry it indefinitely — it is recorded once, contained, and surfaced for administrator review:

1. **Debit only the currently available amount** (`walletDebitMicro`, computed above) — never more.
2. **Never create wallet debt** — `debt_delta_micro = 0`, identically to the compliant path.
3. **Still write one idempotent `Refund` outcome row:** `gross_amount_micro = providerRefundDelta` (the complete newly-confirmed delta, including the unrecoverable portion — this is what drives §13's own state recomputation, so a provider-confirmed full refund can still reach `Refunded` even though part of it violated this platform's own policy); `available_delta_micro = -walletDebitMicro` (only the amount actually removable); `debt_delta_micro = 0`.
4. **Funding-attempt state recomputes from gross refund progress** (§13) — unchanged mechanism; `sumRefundedMicroForFundingAttempt()` already sums `gross_amount_micro`, which already reflects the full confirmed delta regardless of how much of it was actually removable from the wallet.
5. **Suspend billing, in the same transaction, as a protective control** — via `UsageWalletManager::setBillingStatus(..., WalletBillingStatus::Suspended, BillingStatusTransitionSource::ProviderRefundMismatch, null, $reason)`. **A new, precise enum case** — `BillingStatusTransitionSource::ProviderRefundMismatch` (`'provider_refund_mismatch'`) — is added to the existing, otherwise-unmodified `BillingStatusTransitionSource` enum (currently `DisputeWebhook`\|`AdminAction`) specifically for this outcome; `DisputeWebhook` and `AdminAction` are never repurposed for it. The identical redundant-suspend guard already locked for dispute withdrawals (§8, §11) applies here too — a repeated policy-excess outcome on an already-suspended Business writes no second transition row.
6. **Durable audit:** `normalized_outcome = refund_exceeds_refundable_balance`; `normalized_policy_excess_micro` (§18, a new audit column) persists `policyExcessMicro` exactly, as an integer-micro value, never a float.
7. **Terminal state: `processed`, never `failed`.** Retrying cannot undo a refund Stripe has already issued — this event's own processing is complete and correct the moment the outcome row is written and billing is suspended; it is never left to retry indefinitely, and it never appears in the exhausted-events queue for this reason.
8. **Surfaced in the existing administrator audit table** (`admin.provider-events.index`'s own widened `recentOutcomes` section, §18) for manual investigation — the same reused surface, not a new one.
9. **No auto-recharge, no receipt, no dedicated chargeback notification.** `EvaluateBusinessAutoRecharge` is never dispatched by any refund path (§17); no receipt is ever written or sent; the dedicated notification authorized in §11 is chargeback/dispute-only and is **never** dispatched for a refund-policy-excess outcome — an excessive externally-issued refund is an administrator-facing audit fact, never disguised as a chargeback.
10. **Replays and different-event-id reports of the same cumulative refund produce no additional ledger row, wallet debit, suspension transition, or notification** — identical correlation-key idempotency to the compliant path (§11), keyed to the same cumulative reported figure.

### Low-balance marker and wallet events, both paths

`lowBalanceMarkerUpdate()` is applied using the wallet's resulting available balance after `walletDebitMicro` is actually removed (§11); `SendLowBalanceNotification` dispatches after commit only when the marker rule itself requests it. `BusinessWalletDebited` dispatches only for the actual `walletDebitMicro` — when `walletDebitMicro === 0` (available balance was already exhausted), no debit event is dispatched at all, though the outcome row, state recomputation, and (if `policyExcessMicro > 0`) suspension still occur.

---

## 7. Dispute exposure — signed, per-transaction, per-dispute; unaffected by the refund policy

**Restated explicitly, since §6's binding policy applies to `Refund` only:** `DisputeChargeback` may still create debt, exactly as the RFC's own §13 delta table specifies (`-min(amt, avail)`/`0`/`+max(0, amt-avail)`) — nothing in this correction round removes that behavior. On `charge.dispute.funds_withdrawn`, for the (at most one, §5) negative-`amount` entry not yet applied, apply exactly one `DisputeChargeback` entry for `abs(amount)`.

**Per-dispute bounding, independent of the refund accumulator (§6) and of any other dispute on the same attempt:** `provider_reference` (`business_usage_ledger_entries`'s own existing, previously-unused nullable column) is set to the dispute's own `id` on every `DisputeChargeback`/`CorrectionReversal` row:

```
withdrawn  = SUM(gross_amount_micro) WHERE funding_attempt_id = ? AND provider_reference = ? AND entry_type = 'dispute_chargeback'
reinstated = SUM(gross_amount_micro) WHERE funding_attempt_id = ? AND provider_reference = ? AND entry_type = 'correction_reversal'
```

Exact SQL-aggregate strings (§14), one dispute at a time — never combined with any other dispute's own figures, and never combined with the refund accumulator (§6).

---

## 8. Reinstatement — current wallet state, mechanically resolved lineage, bounded to the specific dispute

**Never a negation of the original entry's own stored deltas** — a chargeback creates debt; an unrelated later top-up clears it; negating the original entry's own deltas on reinstatement would drive debt negative.

**Locked formula, from the wallet's currently-locked state, mirroring `creditFromFunding()`'s own debt-clear-first shape:**

```
debtCleared = min(reinstatementAmountMicro, max(0, wallet.debt_balance_micro))
remainder   = reinstatementAmountMicro − debtCleared
available_delta_micro = +remainder      (0 if not wallet-backed, §10)
debt_delta_micro      = −debtCleared    (0 if not wallet-backed, §10)
```

**Bounded to the specific dispute's own actual withdrawn amount** (§7's two sums): `min(reportedBalanceTransactionAmount, withdrawn − reinstated)`, clamped to `0`.

**Lineage — mechanically resolved, never guessed:** a `funds_reinstated` event's own `balance_transactions[]` array carries, per §5's documented two-entry shape, both the reinstatement entry and its own withdrawal counterpart. The withdrawal entry's own `id` deterministically identifies its correlation key (`'dispute_chargeback:'.$attempt->id.':'.$withdrawalBalanceTransactionId`, §11); `findByCorrelationKey()` (existing, unmodified) resolves the exact original `DisputeChargeback` row. `reversed_entry_id` is set to that row's own id.

- **Missing or ambiguous withdrawal lineage fails closed:** no negative-`amount` entry present, or `findByCorrelationKey()` finds nothing → `markFailed('missing_original_chargeback_reference')`, zero mutation. No arbitrary row is ever chosen.
- **Never produces negative debt.**
- **Dispatches `BusinessWalletCredited`/`BusinessWalletDebtCleared`** when wallet-backed; dispatches neither when not (§10).

---

## 9. Wallet-backed versus zero-delta outcome rows

**Every genuinely new provider financial outcome writes an append-only ledger outcome row, whether or not the original funding attempt ever credited the wallet.** `$walletBacked` (§4) controls only:

| | Wallet-backed | Zero-delta (`direct_deliverable`) |
|---|---|---|
| `available_delta_micro`/`debt_delta_micro` (`Refund`) | `-walletDebitMicro` / `0` (§6, always) | `0` / `0` |
| `available_delta_micro`/`debt_delta_micro` (`DisputeChargeback`) | `-min(amt,avail)` / `+max(0,amt-avail)` (§7) | `0` / `0` |
| `available_delta_micro`/`debt_delta_micro` (`CorrectionReversal`) | Per §8 | `0` / `0` |
| `gross_amount_micro` | The exact applied/confirmed delta | Identical |
| Wallet row `UPDATE` | Applied | **Never applied** — the wallet row is still locked first (§20), balance columns untouched |
| `lowBalanceMarkerUpdate()` | Applied | **Never touched** |
| Wallet balance events | Dispatched per formula, and only when the corresponding delta is non-zero | **Never dispatched** |
| Billing-status suspension (`DisputeChargeback` or refund policy-excess only) | Applied | **Still applied — a risk-control decision, not conditional on wallet-credit fulfillment** |
| Chargeback notification (`DisputeChargeback` only, §11) | Dispatched | **Still dispatched, for the identical reason** |
| Refund policy-excess suspension/audit (§6) | Applied | **Not applicable — no wallet credit ever existed, so `walletDebitMicro`/`policyExcessMicro` are both always `0`; the outcome is always `refund_recorded_no_wallet_effect` (§18), never `refund_exceeds_refundable_balance`** |
| Funding-attempt state recomputation (§13) | Applied | **Still applied, identically** |
| Durable audit attribution (§18) | Applied | **Still applied, identically** |

**A reinstatement of a zero-delta chargeback remains zero-delta — it never credits the wallet.** `reinstateDisputedFunds()` receives the same `$walletBacked` flag the original withdrawal was recorded with.

---

## 10. Idempotency — deterministic correlation keys

1. **Event-identity layer:** `payment_provider_events.UNIQUE(provider, provider_event_id)`.
2. **Outcome layer**, keyed to the outcome, not the delivering event:
   - `Refund`: `'refund:'.$attempt->id.':'.$newCumulativeAmountRefundedMicro` — identical whether the outcome is policy-compliant or policy-excess (§6), so a replay of either is a guaranteed no-op.
   - `DisputeChargeback`: `'dispute_chargeback:'.$attempt->id.':'.$balanceTransactionId`.
   - `CorrectionReversal`: `'dispute_reversal:'.$attempt->id.':'.$balanceTransactionId`.

Both layers apply identically to wallet-backed and zero-delta rows.

---

## 11. Billing-status suspension/resumption and the dedicated chargeback/dispute notification

**Suspension** fires for two, and only two, reasons: (a) every genuinely new `DisputeChargeback` write, wallet-backed or not (`BillingStatusTransitionSource::DisputeWebhook`); (b) every genuinely new refund outcome whose `policyExcessMicro > 0` (`BillingStatusTransitionSource::ProviderRefundMismatch`, §6). Both call the identical, existing `UsageWalletManager::setBillingStatus()`, gated by the identical redundant-suspend guard (`$wallet->billing_status !== WalletBillingStatus::Suspended` checked first) — a second dispute, or a second policy-excess refund, against an already-suspended Business writes no additional transition row, regardless of which of the two reasons suspended it first.

**Resumption after a won dispute (or after an administrator resolves a policy-excess flag) remains administrator-only, unconditionally.** RFC §24's own authorization table reserves *"Set/clear `billing_status = 'suspended'`"* to the platform administrator with no automatic-resume row. The already-built `admin.businesses.usage-billing.resume` action is reused unmodified.

**The dedicated notification — one new job, `App\Jobs\Usage\SendChargebackDisputeNotification`, one new notification class, `App\Notifications\Usage\ChargebackDisputeNotification`** — mirrors `SendLowBalanceNotification`/`LowBalanceNotification`'s own exact recipient-resolution shape (billing-contact lookup, `notification_opt_in`, `contact_user_id`-or-`contact_email` fallback, blank-email skip). **It is chargeback/dispute-only.** It is dispatched **only** by the correlation-key winner of a genuinely new `DisputeChargeback` write (`->afterCommit()`, from inside `UsageWalletManager`'s own method, mirroring `confirmSucceeded()`'s own loser-never-dispatches pattern) — never by a refund outcome of any kind, compliant or policy-excess. Dispatched identically for a `direct_deliverable` withdrawal (the decision is keyed to "a new `DisputeChargeback` row was written," not to "the wallet was debited," §9).

**No dispatch** for: a replayed event; the correlation-key loser of a race; a zero/clamped applied amount; a malformed event; any audit-only event; a reinstatement; **any refund outcome, including a policy-excess one** — a refund-driven suspension is shown only to administrators via the audit surface (§18), never disguised as a chargeback email.

**Content, locked:** identifies that a provider chargeback/dispute caused the action; states the exact affected amount and currency as strings (never a float conversion); states that billing is now suspended; directs the Business to contact support/administration. Not a receipt; never claims a new charge occurred.

---

## 12. Ledger row shapes — every field locked

The RFC's own §13 table states `reason` is mandatory for `ManualCredit`, `UsageChargeReversal`, `CorrectionReversal`, and `Refund` — not `DisputeChargeback`. This design populates a deterministic, non-blank reason for all three entry types it writes; `reason` is never null for any row this design writes.

**`Refund`** (§6 — `debt_delta_micro` is always `0`, unconditionally, on every branch):

```php
[
    'business_id' => $attempt->business_id,
    'wallet_id' => $wallet->id,
    'funding_attempt_id' => $attempt->id,
    'entry_type' => UsageLedgerEntryType::Refund->value,
    'available_delta_micro' => $walletBacked ? -$walletDebitMicro : 0,
    'reserved_delta_micro' => 0,
    'debt_delta_micro' => 0,
    'gross_amount_micro' => $providerRefundDelta,
    'currency_id' => $wallet->currency_id,
    'correlation_key' => 'refund:'.$attempt->id.':'.$newCumulativeAmountRefundedMicro,
    'provider_reference' => $providerChargeReference,
    'actor_user_id' => null,
    'reason' => "Provider-confirmed refund of {$providerRefundDelta} micro-units against charge {$providerChargeReference}.",
    'reversed_entry_id' => null,
    'created_at' => now(),
]
```

**`DisputeChargeback`:** identical shape, `entry_type = dispute_chargeback`, deltas per §7/§9 (may create debt when wallet-backed), `provider_reference = $providerDisputeId`, `reason = "Provider dispute {$providerDisputeId} withdrew funds ({$disputeReasonOrGeneral})."`, `reversed_entry_id = null`.

**`CorrectionReversal`:** `entry_type = correction_reversal`, deltas per §8/§9, `provider_reference = $providerDisputeId`, `reversed_entry_id = $originalChargebackEntry->id` (§8's own mechanically-resolved lineage), `reason = "Provider dispute {$providerDisputeId} funds reinstated."`.

No method signature accepts a nullable `?string $reason`. **No `Refund`-writing code path ever assigns a non-zero `debt_delta_micro`, under any circumstance.**

---

## 13. Funding-attempt state — recomputed from every outstanding outcome, under lock, after every mutation

```
refunded = sumRefundedMicroForFundingAttempt(attemptId)                                  // §6, gross figure, exact string
anyDisputeOutstanding = hasOutstandingDisputeExposureForFundingAttempt(attemptId)         // §19, one bounded SQL query, boolean

state = match (true) {
    anyDisputeOutstanding => Disputed,
    bccomp(refunded, attempt.expected_amount_micro, 0) >= 0 => Refunded,
    default => Succeeded,
};
```

`refunded` sums `gross_amount_micro` — for a policy-excess refund row, this is the **complete** confirmed delta (§6), not merely `walletDebitMicro` — so a provider-confirmed full refund still correctly reaches `Refunded` even though part of it exceeded this platform's own refundable-balance policy. State recomputation never itself distinguishes wallet-backed from zero-delta, or compliant from policy-excess — it is driven entirely by the gross accumulator and the dispute-exposure query, both already correct for every case by construction. The write, and the transition row, are skipped when the recomputed state equals the currently-persisted one.

---

## 14. Amount/currency conversion

`UsageBillingCheckoutManager::minorUnitsToMicro()`/`expectedMicroForMinorUnits()` invert `microToMinorUnits()`'s own existing exact `bcmath` exponent table — no new currency table, no new exponent logic.

---

## 15. Dispute audit-only events

`charge.dispute.created`/`.updated`/`.closed`, and a `funds_withdrawn`/`funds_reinstated` event whose own `balance_transactions[]` is empty, produce zero mutation, are durably recorded (`normalized_outcome = dispute_audit_only`, §18), and are marked `ignored`.

---

## 16. `refund.*`/`charge.refund.updated` — explicit audit-only handling

Recognized explicitly, before the metadata-based default; attempts the identical dual-identifier resolution (§3) against the `Refund` object's own `payment_intent`/`charge` fields, best-effort; durably recorded (`normalized_outcome = refund_object_audit_only`); marked `ignored`, never `failed`. Zero wallet/ledger mutation.

---

## 17. Exclusions — restated in full, live

This design never:

- **Originates a provider-side refund, dispute response, or evidence submission.** Every code path processes an inbound, already-settled outcome; none calls a Stripe refund-creation, dispute-response, or evidence-submission endpoint.
- **Introduces any new customer- or administrator-facing action to request, initiate, or simulate a refund or dispute**, and any future such action would itself need to independently validate the §6 available-balance cap before ever calling Stripe, as its own, separately authorized contract.
- **Introduces any new HTTP route beyond the one widened, reused read** (§18).
- **Un-completes, reverses, or rolls back any entitlement or deliverable an `AddonPurchase` may have unlocked** (§4).
- **Performs any live provider call to backfill historical data** — `provider_charge_reference` for pre-existing Checkout-backed attempts is never backfilled; a refund/dispute event for an unbackfilled attempt fails closed into the existing exhausted-event queue.
- **Performs any M6 work.**
- **Fabricates a new "successful payment" receipt** for a refund or dispute of any kind — `business_billing_receipts`'s sole write authority is never invoked by any code path this document authorizes. Stripe's own hosted receipt page is live and already reflects a refund.
- **Dispatches `EvaluateBusinessAutoRecharge` from any refund or dispute code path, compliant or policy-excess** — a provider-driven refund or chargeback is not usage consumption; automatically originating a fresh charge in direct response to money Stripe just moved would be actively harmful, particularly against a payment method already under dispute or already the subject of a refund.
- **Calls `SendReceiptNotification` or writes a receipt from any refund/dispute code path.**
- **Treats `ManualCredit`/`PromotionalCredit` as refundable provider funding** (§4).
- **Introduces credit-lot or source-level attribution among fungible available wallet credits** (§6).

---

## 18. Durable, administrator-visible, Business-attributed audit records

**Locked — widen the existing `payment_provider_events` table, never a new table.** Ten new, nullable columns: `business_id` (no FK, matching `disposed_by_user_id`'s own precedent), `funding_attempt_id` (no FK), `normalized_outcome` (`string(32)`), `normalized_status` (`string(32)`), `normalized_reported_amount_micro` (bigint), `normalized_applied_amount_micro` (bigint), **`normalized_policy_excess_micro`** (bigint — new this correction), `normalized_currency_code` (`string(3)`), `normalized_reason` (`string(64)`), `normalized_recorded_at` (timestamp — the dedicated ordering column for `recentOutcomes()`).

**Exact semantics, per event family — every value an exact integer-micro amount, never a float:**

| `normalized_outcome` | `normalized_reported_amount_micro` | `normalized_applied_amount_micro` | `normalized_policy_excess_micro` |
|---|---|---|---|
| `refund_applied` (compliant, wallet-backed, `policyExcessMicro = 0`) | `providerRefundDelta` | `walletDebitMicro` (`= providerRefundDelta`) | `0` |
| `refund_recorded_no_wallet_effect` (`direct_deliverable`, §9) | `providerRefundDelta` | `0` | `0` |
| `refund_already_applied` (replay, `providerRefundDelta = 0`) | `0` | `0` | `0` |
| `refund_exceeds_refundable_balance` (`policyExcessMicro > 0`) | `providerRefundDelta` (the complete newly-confirmed delta) | `walletDebitMicro` (may be `0`) | `policyExcessMicro` |
| `dispute_withdrawal` | The balance transaction's own `abs(amount)` | The amount actually applied (§7, may be less if bounded by remaining exposure) | `0` |
| `dispute_reinstatement` | The balance transaction's own `amount` | The amount actually applied (§8) | `0` |
| `dispute_audit_only` | `null` (or the Dispute's own claimed `amount`, informational only — never drives mutation) | `0` | `0` |
| `refund_object_audit_only` | `null` | `0` | `0` |

**A typed outcome result drives the write — never a job-side recomputation.** `App\Library\Usage\ProviderOutcomeResult` (new readonly value object):

```php
final readonly class ProviderOutcomeResult
{
    public function __construct(
        public string $normalizedOutcome,
        public int $reportedAmountMicro,
        public int $appliedAmountMicro,
        public int $policyExcessMicro,
        public ?int $ledgerEntryId,
        public FundingAttemptState $resultingState,
    ) {}
}
```

`UsageBillingCheckoutManager::applyRefundOutcome()`/`applyDisputeChargebackOutcome()`/`applyDisputeReinstatementOutcome()` each return this DTO. `ProcessPaymentProviderEvent` reads its fields directly into `$attribution` — it never independently recomputes any of the four amount/outcome fields.

**Populated at the exact moment the event reaches its terminal state** — `markProcessed()`/`markIgnored()` each gain one new trailing optional parameter, `array $attribution = []`. A policy-excess refund is marked `processed` (§6 item 7) — never `failed`, never left to retry.

**Admin surface widened, not duplicated:** `PaymentProviderEventController::index()` gains `recentOutcomes` from a new bounded method, `recentOutcomes(int $limit = 50): Collection`, its accepted limit clamped internally to a locked maximum of 100 (`min($limit, self::MAX_RECENT_OUTCOMES_LIMIT)`), ordered by `normalized_recorded_at DESC`, filtered `WHERE normalized_outcome IS NOT NULL`. The view's new section renders Business, funding attempt, outcome, status, reported amount, applied amount, **policy-excess amount**, currency, reason, recorded-at — a `refund_exceeds_refundable_balance` row is visually distinguishable (its own non-zero policy-excess column) for prompt administrator investigation. The existing exhausted-events table and disposition form are unmodified.

**Bounded database work:** two new indexes — `(state, attempts)` (supports the scanner's `retryable()`, §19) and `(normalized_recorded_at)` (supports `recentOutcomes()`).

**Attribution survives payload purge** — `PurgeExpiredWebhookPayloads` (unmodified) only ever nulls `payload_encrypted`/sets `payload_purged_at`.

---

## 19. Retry/reclaim — recovers a stranded `received` row

`StripeWebhookController::handle()` persists the event row (`state: received`) and only then dispatches `ProcessPaymentProviderEvent`. If that dispatch throws, or the process dies immediately after persistence, the row is stuck at `state = received, attempts = 0` forever under a two-branch (`failed`/stale-`processing`) scanner — a Stripe redelivery then hits the `UNIQUE` constraint and returns `200` without ever redispatching the existing row.

**Corrected — `retryable()` selects three branches:**

```sql
WHERE (state = 'received' AND received_at < NOW() - INTERVAL ? MINUTE)
   OR (state = 'failed' AND attempts < ?)
   OR (state = 'processing' AND lease_expires_at < NOW() AND attempts < ?)
ORDER BY id
LIMIT ?
```

The `received` branch is **deliberately narrower** than the underlying claim statement's own unconditional `state = 'received'` match — it requires the row to be older than one claim-lease interval (the existing `webhook_event.lease_minutes` config, reused). It is shape-identical to the claim statement only for the `failed`/stale-`processing` branches.

- **`RetryStuckPaymentProviderEvents`** (`everyFiveMinutes()`, matching the claim-lease duration and `ReconcileProviderPendingState`'s own cadence) performs exactly one bounded read (`retryable($maxAttempts, $leaseMinutes, self::BATCH_LIMIT = 200)`) and zero-or-more `dispatch()` calls — no accounting mutation inside the scanner.
- **Concurrency-safe with no new code in `ProcessPaymentProviderEvent`** — the claim's own atomicity guarantees at most one caller ever matches; every other concurrent dispatch hits the existing `if ($claimed === 0) { return; }`.
- **Once `attempts >= maxAttempts`,** a `failed`/stale-`processing` row matches neither `retryable()` nor the claim statement — it surfaces in the existing `exhausted()` admin queue.
- **`processed`/`ignored`/`disposed` events are never redispatched.**

---

## 20. Complete transaction/lock/crash-recovery sequence

1. The event's signature is verified and persisted; dual-reference resolution (§3) runs before any lock is acquired.
2. The wallet row is locked first (`findForUpdateByBusinessId()`), inside one `DB::transaction()`.
3. `$walletBacked` (§4) is determined once and held for the remainder of the call.
4. The unique outcome row is inserted (§9, §12); the wallet `UPDATE` (including `lowBalanceMarkerUpdate()`, and, for a `DisputeChargeback` or a policy-excess `Refund`, the redundant-suspend-guarded suspension, §11) applies only when `$walletBacked` is true for the balance columns, and applies to suspension regardless.
5. The funding attempt's own row is locked a second time, inside the same outer transaction, immediately before state recomputation (§13) writes.
6. The outer transaction commits.
7. **After** commit: the low-balance notification (if requested) and the dedicated chargeback notification (if a genuinely new `DisputeChargeback` was the row just inserted, never for any refund outcome, §11) are dispatched — never before commit, never at all on rollback.
8. The provider event is marked `processed`/`ignored`, carrying `$attribution` built directly from the returned `ProviderOutcomeResult` (§18).

**Crash between steps 6 and 8:** the outcome row, any wallet mutation, and the state recomputation have already durably committed. On retry (§19), processing re-enters from the top; §10's own correlation-key idempotency finds the outcome already applied and computes a delta of `0`; no duplicate financial or state effect occurs; no notification fires twice (§11's correlation-key-winner rule); step 8 completes correctly. **No notification ever fires on rollback** — every dispatch in step 7 is `->afterCommit()`.

---

## 21. `hasOutstandingDisputeExposureForFundingAttempt()` — one bounded scalar query

**Locked — one SQL query, grouped by `provider_reference`, returning a boolean:**

```php
public function hasOutstandingDisputeExposureForFundingAttempt(int $fundingAttemptId): bool
{
    return DB::table('business_usage_ledger_entries')
        ->select('provider_reference')
        ->where('funding_attempt_id', $fundingAttemptId)
        ->whereIn('entry_type', ['dispute_chargeback', 'correction_reversal'])
        ->whereNotNull('provider_reference')
        ->groupBy('provider_reference')
        ->havingRaw(
            "SUM(CASE WHEN entry_type = 'dispute_chargeback' THEN gross_amount_micro ELSE 0 END) > ".
            "SUM(CASE WHEN entry_type = 'correction_reversal' THEN gross_amount_micro ELSE 0 END)"
        )
        ->limit(1)
        ->get()
        ->isNotEmpty();
}
```

No PHP-side list or reduction; complete across every dispute for the attempt in one query; supported by the composite index `(funding_attempt_id, entry_type, provider_reference)` (§22 Migration 3).

---

## 22. Locked design — exact production allow-list

**24 files: 9 new + 15 modified.**

| # | Path | Status | Content |
|---|---|---|---|
| 1 | `database/migrations/2026_08_29_120001_add_provider_references_to_business_funding_attempts_table.php` | NEW | §3. |
| 2 | `database/migrations/2026_08_29_120002_backfill_provider_payment_intent_reference_for_auto_recharge_attempts.php` | NEW | §17. |
| 3 | `database/migrations/2026_08_29_120003_add_dispute_refund_aggregate_index_to_business_usage_ledger_entries_table.php` | NEW | Composite index `(funding_attempt_id, entry_type, provider_reference)` (§21). |
| 4 | `database/migrations/2026_08_29_120004_add_normalized_outcome_columns_to_payment_provider_events_table.php` | NEW | The ten columns in §18. |
| 5 | `database/migrations/2026_08_29_120005_add_scanner_and_recent_outcomes_indexes_to_payment_provider_events_table.php` | NEW | `(state, attempts)`, `(normalized_recorded_at)` (§18, §19). |
| 6 | `app/Jobs/Usage/RetryStuckPaymentProviderEvents.php` | NEW | §19. |
| 7 | `app/Jobs/Usage/SendChargebackDisputeNotification.php` | NEW | §11. |
| 8 | `app/Notifications/Usage/ChargebackDisputeNotification.php` | NEW | §11. |
| 9 | `app/Library/Usage/ProviderOutcomeResult.php` | NEW | §18. |
| 10 | `app/Enums/Usage/BillingStatusTransitionSource.php` | MODIFIED | Adds one new case, `ProviderRefundMismatch = 'provider_refund_mismatch'` (§6, §11) — `DisputeWebhook`/`AdminAction` are unmodified. |
| 11 | `app/Models/BusinessFundingAttempt.php` | MODIFIED | `$fillable` gains both new reference columns. |
| 12 | `app/Models/PaymentProviderEvent.php` | MODIFIED | `$fillable` gains the ten new columns. |
| 13 | `app/Repositories/Contracts/BusinessFundingAttemptRepository.php` | MODIFIED | `findByProviderPaymentIntentReference()`, `findByProviderChargeReference()` (§3). |
| 14 | `app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php` | MODIFIED | Implements both. |
| 15 | `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` | MODIFIED | `sumRefundedMicroForFundingAttempt()` (§6); `sumDisputeMicroForFundingAttemptAndDispute()` (§7); `hasOutstandingDisputeExposureForFundingAttempt()` (§21); `findCreditEntryForFundingAttempt()`, scoped to `paid_top_up`/`auto_recharge` only (§4). |
| 16 | `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php` | MODIFIED | Implements all four. |
| 17 | `app/Repositories/Contracts/PaymentProviderEventRepository.php` | MODIFIED | `markProcessed()`/`markIgnored()` gain `array $attribution = []`; `retryable(int $maxAttempts, int $receivedGraceMinutes, int $limit): Collection` (§19); `recentOutcomes(int $limit = 50): Collection` (§18). |
| 18 | `app/Repositories/Eloquent/EloquentPaymentProviderEventRepository.php` | MODIFIED | Implements the widened signatures and both new methods. |
| 19 | `app/Library/Usage/UsageWalletManager.php` | MODIFIED | `applyProviderRefund(BusinessFundingAttempt $attempt, int $providerRefundDelta, string $correlationKey, ?string $providerChargeReference, bool $walletBacked): ?BusinessUsageLedgerEntry` (§6, §9, §12 — never writes a non-zero `debt_delta_micro`; suspends billing with `ProviderRefundMismatch` only when `policyExcessMicro > 0`); `applyDisputeWithdrawal(...): ?BusinessUsageLedgerEntry` (§7, §9, §11, §12 — unchanged, still may create debt); `reinstateDisputedFunds(...): ?BusinessUsageLedgerEntry` (§8, §9, §12 — unchanged). |
| 20 | `app/Library/Usage/UsageBillingCheckoutManager.php` | MODIFIED | `minorUnitsToMicro()`/`expectedMicroForMinorUnits()`; `confirmSucceeded()`/`finalizeFundingAttemptState()` widened with the two new trailing optional parameters and the defensive assertion (§3); `applyRefundOutcome()`, `applyDisputeChargebackOutcome()`, `applyDisputeReinstatementOutcome()`, each returning `ProviderOutcomeResult` (§18), each computing §6/§7/§8's own formulas, locking the wallet row, calling exactly one `UsageWalletManager` method, calling state recomputation (§13), all inside one outer `DB::transaction()`. |
| 21 | `app/Jobs/Usage/ProcessPaymentProviderEvent.php` | MODIFIED | `handle()`'s own `match` widened per §2/§15/§16; dual-identifier resolution, currency/shape validation, exactly one `UsageBillingCheckoutManager` call for the two mutating families, terminal calls carrying `$attribution` from the returned `ProviderOutcomeResult`. |
| 22 | `app/Console/Kernel.php` | MODIFIED | One new line, `$schedule->job(new RetryStuckPaymentProviderEvents())->everyFiveMinutes();`. |
| 23 | `app/Http/Controllers/Admin/PaymentProviderEventController.php` | MODIFIED | `index()` gains `recentOutcomes`. |
| 24 | `resources/views/admin/usage-billing/provider-events/index.blade.php` | MODIFIED | One new `x-card`/`x-table` section, including the policy-excess column. |

**NOT_REQUIRED, explicitly confirmed:**

| Path/category | Reason |
|---|---|
| Any file under `app/Http/Controllers/Admin/**`/`resources/views/**` beyond items 23/24 | Existing surfaces are entry-type/state-value generic. |
| `routes/public.php` | The webhook route is already event-type-agnostic. |
| `app/Providers/AppServiceProvider.php` | Both widened repository contracts are already bound. |
| Any brand-new PHP enum type | Only one new **case** on one existing enum (item 10) is introduced; no new enum class. |
| Any new exception class | Every failure path is an existing `markFailed()` string-reason branch or the existing `UsageWalletNotFoundException`; bounding is by clamping, never throwing. |
| `PaymentProviderGateway`/`StripePaymentProviderGateway`/`FakePaymentProviderGateway`/`CheckoutSessionResult`/`PaymentIntentResult` | The verified, signed webhook payload is trusted directly; no new gateway method, no new DTO field. |
| `ReconcileProviderPendingState.php`, `PurgeExpiredWebhookPayloads.php` | Already event-type-agnostic. |
| `BusinessBillingReceipt.php`, `ensureFundingReceipt()` | Never invoked by any refund/dispute code path. |
| `BusinessUsageAddonPurchase`, `finalizeAddonPurchaseIfPending()`, `completeAddonPurchaseUnderLock()` | Never invoked by any refund/dispute code path (§4). |
| `EvaluateBusinessAutoRecharge` (production behavior unmodified) | Never dispatched by any refund/dispute code path (§17). |
| `config/usage_billing.php` | The scanner's batch limit/grace interval are plain class constants. |

---

## 23. Preserved invariants

- `committed_spend_this_period_micro`/`reserved_spend_this_period_micro` are never touched by any method in §22 item 19.
- `recharged_this_period_micro` is never decremented by `Refund`/`DisputeChargeback`/`CorrectionReversal`.
- `business_usage_ledger_entries` remains append-only.
- Outstanding-debt-denies-reservations remains centrally enforced by `reserve()`'s own unmodified check.
- No raw query against a billing table outside its owning repository.
- **Debt can never go negative** (`reinstateDisputedFunds()`, §8).
- **`Refund` can never create or increase debt, under any circumstance — the binding policy this correction round adds** (§6, §12) — enforced both by construction (`debt_delta_micro` is a literal `0` in every branch of §6's own formula) and by a dedicated test asserting no `Refund` row this design ever writes can carry a non-zero `debt_delta_micro`.
- `EvaluateBusinessAutoRecharge` is never dispatched, and no receipt is ever fabricated, by any code path this design authorizes.

---

## 24. Exact test allow-list

**No existing test file requires modification** — re-confirmed by direct re-read: `PaymentProviderEventSchemaTest.php`'s three methods assert specific field values, never a closed column list.

**11 new files, 138 methods.**

| # | File | Methods |
|---|---|---|
| 1 | `tests/Feature/Usage/ProviderPaymentIdentifierResolutionTest.php` | 12 |
| 2 | `tests/Feature/Usage/ProviderRefundOutcomeTest.php` | 20 |
| 3 | `tests/Feature/Usage/ProviderDisputeOutcomeTest.php` | 21 |
| 4 | `tests/Feature/Usage/DisputeBalanceTransactionValidationTest.php` | 7 |
| 5 | `tests/Feature/Usage/DirectDeliverableProviderOutcomeTest.php` | 11 |
| 6 | `tests/Feature/Usage/UsageWalletManagerReversalTest.php` | 23 |
| 7 | `tests/Feature/Usage/ProviderRefundDisputeSurfaceBoundaryTest.php` | 7 |
| 8 | `tests/Feature/Usage/PaymentProviderEventRetryReclaimTest.php` | 12 |
| 9 | `tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php` | 10 |
| 10 | `tests/Feature/Usage/ProviderRefundDisputeConcurrencyTest.php` | 3 |
| 11 | `tests/Feature/Usage/SendChargebackDisputeNotificationTest.php` | 12 |

**Total: 138 methods across 11 files.**

### `ProviderPaymentIdentifierResolutionTest.php` (12) — proves §3

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

### `ProviderRefundOutcomeTest.php` (20) — proves §6, §12, §13, §17 (wallet-backed refunds)

1. `test_a_refund_within_available_balance_debits_available_only_and_creates_no_debt`
2. `test_a_full_refund_after_partial_usage_consumption_removes_only_the_remaining_available_balance_creates_no_debt_records_policy_excess_and_suspends_billing`
3. `test_a_refund_when_available_balance_is_zero_creates_no_debt_or_wallet_debit_event_but_records_the_outcome_and_policy_excess`
4. `test_no_refund_ledger_row_can_ever_have_a_non_zero_debt_delta_micro`
5. `test_a_second_partial_refund_event_applies_only_the_incremental_delta_since_the_first`
6. `test_an_out_of_order_replayed_refund_event_reporting_an_already_applied_cumulative_amount_produces_zero_additional_mutation`
7. `test_a_refund_is_bounded_and_never_exceeds_the_original_funding_attempts_expected_amount`
8. `test_a_wallet_credit_addon_purchase_refund_follows_the_identical_available_balance_cap`
9. `test_a_refund_event_missing_both_payment_intent_and_charge_fails_with_no_mutation`
10. `test_a_refund_event_for_an_unresolvable_reference_fails_with_no_mutation`
11. `test_a_refund_event_with_a_mismatched_currency_fails_with_no_mutation`
12. `test_refund_progress_is_computed_solely_from_refund_entries_and_is_unaffected_by_a_dispute_on_the_same_attempt`
13. `test_a_refund_never_affects_an_unrelated_businesss_wallet`
14. `test_refund_reason_is_never_null_and_matches_the_deterministic_template`
15. `test_consumed_usage_and_committed_spend_history_are_never_reversed_by_a_refund`
16. `test_manual_credit_and_promotional_credit_entries_are_never_treated_as_refundable_provider_funding`
17. `test_a_policy_excess_refund_event_is_marked_terminally_processed_rather_than_retried`
18. `test_a_replayed_policy_excess_refund_event_creates_no_second_suspension_transition`
19. `test_a_policy_excess_refund_never_dispatches_evaluate_business_auto_recharge_send_receipt_notification_or_the_dedicated_chargeback_notification`
20. `test_a_full_refund_after_a_policy_excess_partial_still_recomputes_the_attempt_to_refunded_from_gross_progress`

### `ProviderDisputeOutcomeTest.php` (21) — proves §7, §8, §11, §13, §21 (unaffected by the refund policy; `DisputeChargeback` may still create debt)

1. `test_a_funds_withdrawn_event_applies_the_signed_balance_transaction_amount_as_a_dispute_chargeback`
2. `test_a_funds_withdrawn_event_uses_the_balance_transaction_amount_not_the_disputed_claim_amount`
3. `test_a_dispute_exceeding_available_balance_clears_available_balance_and_creates_debt`
4. `test_a_replayed_funds_withdrawn_event_for_the_same_balance_transaction_produces_zero_additional_mutation`
5. `test_a_funds_withdrawn_event_with_an_empty_balance_transactions_array_produces_no_mutation_and_is_durably_audited`
6. `test_a_funds_reinstated_event_applies_the_signed_balance_transaction_amount_as_a_correction_reversal`
7. `test_a_partial_reinstatement_clears_only_part_of_the_outstanding_dispute_exposure_and_leaves_the_attempt_disputed`
8. `test_a_full_reinstatement_after_debt_was_already_cleared_by_an_intervening_top_up_credits_available_balance_without_creating_negative_debt`
9. `test_a_reinstatement_clears_current_debt_first_then_credits_any_remainder_to_available_balance`
10. `test_a_reinstatement_is_bounded_to_the_actual_withdrawn_amount_for_that_specific_dispute`
11. `test_a_duplicate_reinstatement_event_for_the_same_balance_transaction_produces_zero_additional_mutation`
12. `test_a_reinstatement_dispatches_business_wallet_credited_and_or_debt_cleared_matching_the_current_state_based_split`
13. `test_many_dispute_references_for_the_same_attempt_with_one_still_outstanding_leaves_the_attempt_disputed`
14. `test_all_disputes_for_the_attempt_cleared_falls_back_to_the_refund_progress_based_state`
15. `test_a_lost_dispute_leaves_the_attempt_disputed_permanently_with_no_reversal`
16. `test_a_dispute_created_event_is_durably_recorded_and_ignored_with_no_mutation`
17. `test_a_dispute_updated_event_is_durably_recorded_and_ignored_with_no_mutation`
18. `test_a_dispute_closed_event_is_durably_recorded_and_ignored_with_no_mutation_regardless_of_status`
19. `test_a_dispute_event_for_an_unresolvable_reference_fails_with_no_mutation`
20. `test_a_dispute_never_affects_an_unrelated_businesss_wallet`
21. `test_a_second_dispute_while_billing_is_already_suspended_writes_no_redundant_suspended_transition`

### `DisputeBalanceTransactionValidationTest.php` (7) — proves §5, §8

1. `test_a_dispute_with_the_documented_single_withdrawal_transaction_is_processed`
2. `test_a_dispute_carrying_the_documented_withdrawal_then_reinstatement_two_transaction_shape_processes_both_correctly`
3. `test_more_than_two_balance_transactions_fails_closed_as_malformed`
4. `test_two_balance_transactions_of_the_same_sign_fail_closed_as_malformed`
5. `test_duplicate_balance_transaction_ids_in_the_array_fail_closed_as_malformed`
6. `test_a_reinstatement_resolves_the_original_chargeback_via_the_withdrawal_transactions_own_correlation_key_and_sets_the_exact_reversed_entry_id`
7. `test_a_reinstatement_with_no_matching_withdrawal_present_in_the_array_fails_closed_with_zero_mutation`

### `DirectDeliverableProviderOutcomeTest.php` (11) — proves §4, §9, §17

1. `test_a_partial_direct_deliverable_refund_leaves_the_attempt_succeeded_with_zero_wallet_deltas_and_a_recorded_outcome_row`
2. `test_a_full_direct_deliverable_refund_transitions_the_attempt_to_refunded_with_zero_wallet_deltas`
3. `test_a_replayed_direct_deliverable_refund_event_is_a_no_op`
4. `test_a_direct_deliverable_dispute_withdrawal_writes_a_zero_delta_dispute_chargeback_and_suspends_billing`
5. `test_a_direct_deliverable_dispute_reinstatement_writes_a_zero_delta_correction_reversal_and_never_credits_the_wallet`
6. `test_zero_wallet_balance_events_are_ever_dispatched_for_any_direct_deliverable_outcome_row`
7. `test_a_direct_deliverable_dispute_withdrawal_dispatches_the_chargeback_notification_despite_zero_wallet_deltas`
8. `test_a_direct_deliverable_refund_is_never_classified_as_a_wallet_credit_over_refund`
9. `test_clearing_the_final_direct_deliverable_dispute_exposure_returns_the_attempt_to_refunded_or_succeeded_per_refund_progress`
10. `test_two_different_provider_event_ids_reporting_the_same_direct_deliverable_outcome_apply_it_exactly_once`
11. `test_a_refunded_direct_deliverable_addon_purchase_remains_historically_completed`

### `UsageWalletManagerReversalTest.php` (23) — proves §6, §7, §8, §9, §11, §17 at the manager layer

1. `test_apply_provider_refund_debits_available_balance_only_when_sufficient`
2. `test_apply_provider_refund_debits_only_the_remaining_available_balance_and_records_policy_excess_when_available_balance_is_insufficient`
3. `test_apply_provider_refund_never_writes_a_non_zero_debt_delta_micro`
4. `test_apply_provider_refund_returns_null_and_mutates_nothing_for_a_non_positive_amount`
5. `test_apply_provider_refund_writes_a_zero_delta_row_when_not_wallet_backed`
6. `test_apply_provider_refund_sets_the_low_balance_marker_when_the_debit_drops_the_balance_to_or_below_threshold`
7. `test_apply_provider_refund_never_dispatches_evaluate_business_auto_recharge`
8. `test_apply_provider_refund_suspends_billing_using_the_provider_refund_mismatch_source_only_when_policy_excess_exists`
9. `test_apply_provider_refund_does_not_re_suspend_an_already_suspended_wallet_on_a_repeated_policy_excess_outcome`
10. `test_apply_provider_refund_dispatches_business_wallet_debited_only_for_the_actual_debit_never_when_it_is_zero`
11. `test_apply_dispute_withdrawal_debits_available_balance_when_sufficient`
12. `test_apply_dispute_withdrawal_creates_debt_when_available_balance_is_insufficient`
13. `test_apply_dispute_withdrawal_dispatches_business_wallet_debited_and_or_debt_incurred_matching_the_split`
14. `test_apply_dispute_withdrawal_suspends_billing_status`
15. `test_apply_dispute_withdrawal_does_not_re_suspend_an_already_suspended_wallet`
16. `test_apply_dispute_withdrawal_suspends_billing_even_when_not_wallet_backed`
17. `test_apply_dispute_withdrawal_never_dispatches_evaluate_business_auto_recharge`
18. `test_apply_dispute_withdrawal_never_dispatches_send_receipt_notification_or_writes_a_receipt_row`
19. `test_reinstate_disputed_funds_clears_current_debt_before_crediting_available_balance`
20. `test_reinstate_disputed_funds_never_produces_negative_debt_when_debt_was_already_cleared`
21. `test_reinstate_disputed_funds_dispatches_business_wallet_credited_and_or_debt_cleared_matching_current_state`
22. `test_reinstate_disputed_funds_clears_the_low_balance_marker_on_recovery`
23. `test_reinstate_disputed_funds_remains_zero_delta_and_never_credits_the_wallet_when_not_wallet_backed`

### `ProviderRefundDisputeSurfaceBoundaryTest.php` (7) — proves §17, §22

1. `test_reversal_and_dispute_manager_methods_are_never_called_from_a_controller`
2. `test_process_payment_provider_event_never_calls_a_charge_originating_manager_method`
3. `test_no_new_production_file_contains_a_raw_billing_table_query_outside_the_two_eloquent_repositories`
4. `test_apply_outcome_orchestration_methods_are_never_called_outside_process_payment_provider_event`
5. `test_no_new_admin_controller_action_or_route_is_introduced_beyond_the_widened_provider_events_index`
6. `test_none_of_the_three_reversal_methods_ever_references_evaluate_business_auto_recharge`
7. `test_none_of_the_three_reversal_methods_ever_references_send_receipt_notification_or_attach_funding_receipt`

### `PaymentProviderEventRetryReclaimTest.php` (12) — proves §19

1. `test_a_failed_event_below_max_attempts_is_redispatched_by_the_scanner`
2. `test_a_stale_processing_event_past_its_lease_is_reclaimed_by_the_scanner`
3. `test_an_event_at_max_attempts_is_never_redispatched_by_the_scanner`
4. `test_an_exhausted_event_becomes_administrator_visible_in_the_existing_exhausted_events_queue`
5. `test_processed_ignored_and_disposed_events_are_never_redispatched_by_the_scanner`
6. `test_the_scanner_batch_is_bounded_by_its_own_limit`
7. `test_the_scanner_performs_no_accounting_mutation_itself`
8. `test_a_received_event_older_than_the_grace_interval_is_redispatched_by_the_scanner`
9. `test_a_freshly_received_event_is_not_redispatched_before_the_grace_interval_elapses`
10. `test_the_persistence_before_dispatch_failure_leaves_a_received_row_that_only_the_scanner_recovers`
11. `test_a_redelivered_webhook_for_an_already_received_event_returns_200_without_a_second_row_and_the_original_remains_scanner_recoverable`
12. `test_a_scanner_redispatch_racing_the_original_dispatch_for_the_same_received_event_applies_the_outcome_exactly_once`

### `PaymentProviderEventDurableAuditTest.php` (10) — proves §18

1. `test_a_processed_refund_outcome_is_attributed_with_business_and_funding_attempt_identity`
2. `test_an_ignored_dispute_created_event_is_attributed_with_normalized_status_and_reason`
3. `test_a_direct_deliverable_addon_refund_is_durably_audited_with_the_actual_applied_progress_despite_zero_wallet_mutation`
4. `test_normalized_attribution_survives_payload_purge`
5. `test_the_provider_events_admin_surface_lists_recent_normalized_outcomes_ordered_by_normalized_recorded_at`
6. `test_recent_outcomes_clamps_its_accepted_limit_to_the_locked_maximum_regardless_of_the_requested_value`
7. `test_a_refund_object_event_is_recorded_as_audit_only_with_no_wallet_mutation`
8. `test_a_partial_refunds_reported_amount_differs_from_its_applied_amount`
9. `test_a_replayed_refund_records_a_reported_amount_with_an_applied_amount_of_zero`
10. `test_the_administrator_audit_renders_reported_applied_and_policy_excess_amounts_exactly_for_a_policy_excess_refund`

### `ProviderRefundDisputeConcurrencyTest.php` (3) — proves §10, using the established subprocess/causal-barrier convention

1. `test_two_different_provider_event_ids_reporting_the_same_cumulative_refund_amount_credit_the_wallet_exactly_once`
2. `test_two_different_provider_event_ids_reporting_the_same_balance_transaction_apply_the_dispute_chargeback_exactly_once`
3. `test_two_different_provider_event_ids_reporting_the_same_policy_excess_refund_apply_it_exactly_once_with_no_duplicate_suspension`

### `SendChargebackDisputeNotificationTest.php` (12) — proves §11

1. `test_dispatched_only_after_the_outer_transaction_commits`
2. `test_dispatched_exactly_once_for_the_correlation_key_winner`
3. `test_not_dispatched_for_a_replayed_withdrawal_event`
4. `test_not_dispatched_for_the_correlation_key_loser_of_a_concurrent_write`
5. `test_not_dispatched_when_the_outer_transaction_rolls_back`
6. `test_dispatched_for_a_direct_deliverable_withdrawal_despite_zero_wallet_deltas`
7. `test_not_dispatched_for_a_reinstatement`
8. `test_not_dispatched_for_a_policy_excess_refund_outcome`
9. `test_skips_when_no_billing_contact_is_configured`
10. `test_skips_when_the_contact_has_opted_out`
11. `test_skips_when_the_resolved_email_is_blank`
12. `test_sends_with_the_exact_dispute_id_amount_and_currency_content`

---

## 25. Schema/migration decisions — exact DDL

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

**Migration 2:** the AutoRecharge-only, pure local-data backfill `UPDATE` (§17).

**Migration 3:**

```php
Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
    $table->index(['funding_attempt_id', 'entry_type', 'provider_reference'], 'ledger_funding_attempt_entry_type_provider_reference_index');
});
```

**Migration 4:**

```php
Schema::table('payment_provider_events', function (Blueprint $table) {
    $table->unsignedBigInteger('business_id')->nullable()->after('provider_object_id');
    $table->unsignedBigInteger('funding_attempt_id')->nullable()->after('business_id');
    $table->string('normalized_outcome', 32)->nullable()->after('funding_attempt_id');
    $table->string('normalized_status', 32)->nullable()->after('normalized_outcome');
    $table->bigInteger('normalized_reported_amount_micro')->nullable()->after('normalized_status');
    $table->bigInteger('normalized_applied_amount_micro')->nullable()->after('normalized_reported_amount_micro');
    $table->bigInteger('normalized_policy_excess_micro')->nullable()->after('normalized_applied_amount_micro');
    $table->string('normalized_currency_code', 3)->nullable()->after('normalized_policy_excess_micro');
    $table->string('normalized_reason', 64)->nullable()->after('normalized_currency_code');
    $table->timestamp('normalized_recorded_at')->nullable()->after('normalized_reason');
});
```

No `FOREIGN KEY` on `business_id`/`funding_attempt_id`.

**Migration 5:**

```php
Schema::table('payment_provider_events', function (Blueprint $table) {
    $table->index(['state', 'attempts'], 'payment_provider_events_state_attempts_index');
    $table->index('normalized_recorded_at', 'payment_provider_events_normalized_recorded_at_index');
});
```

No other schema/migration change is authorized or required.

---

## 26. Guarantee-by-guarantee mapping

1. **A refund/dispute event resolves to exactly one Business, never ambiguously.** §3; `ProviderPaymentIdentifierResolutionTest` methods 6–10.
2. **A provider-confirmed Refund can never create or increase wallet debt; an externally-issued over-refund is capped, recorded, and flagged, never absorbed as debt or retried indefinitely.** §6, §12, §23; `ProviderRefundOutcomeTest` methods 1–4, 17–19; `UsageWalletManagerReversalTest` methods 1–3, 8–9.
3. **`DisputeChargeback` may still create debt — unaffected by the refund policy.** §7, §9; `ProviderDisputeOutcomeTest` method 3; `UsageWalletManagerReversalTest` method 12.
4. **Dispute mutation is keyed to the actual, signed, validated balance transaction — never the claimed amount/status, never a malformed shape.** §5, §7; `ProviderDisputeOutcomeTest` methods 1–2; `DisputeBalanceTransactionValidationTest` methods 1–5.
5. **A reinstatement never produces negative debt, is bounded to the specific dispute's own withdrawn amount, and mechanically resolves its own lineage.** §8; `ProviderDisputeOutcomeTest` methods 7–11; `DisputeBalanceTransactionValidationTest` methods 6–7.
6. **Every genuinely new outcome — wallet-backed or zero-delta — writes an outcome row, advances its accumulator, is audited, and drives state recomputation identically.** §4, §9; `DirectDeliverableProviderOutcomeTest` in full.
7. **Funding-attempt state reflects every outstanding dispute and gross refund progress, computed by bounded queries.** §13, §21; `ProviderDisputeOutcomeTest` methods 13–15; `ProviderRefundOutcomeTest` method 20.
8. **Duplicate delivery, a stranded `received` row, and genuine concurrency — including two different event ids reporting the identical outcome — never apply the same financial or state effect twice, and a stranded event is recovered.** §19, §20; `PaymentProviderEventRetryReclaimTest` in full; `ProviderRefundDisputeConcurrencyTest` in full.
9. **No fabricated receipt, and no automatic auto-recharge origination, from any refund/dispute code path.** §17; `UsageWalletManagerReversalTest` methods 7, 17, 18, 23; `ProviderRefundDisputeSurfaceBoundaryTest` methods 6–7.
10. **The low-balance marker participates correctly, and only, in wallet-backed mutations.** §9, §11; `UsageWalletManagerReversalTest` methods 6, 22.
11. **Every outcome is durably recorded with unambiguous reported/applied/policy-excess amounts and remains administrator-visible after payload purge.** §18; `PaymentProviderEventDurableAuditTest` in full.
12. **The dedicated chargeback notification dispatches exactly once, chargeback-only, never for any refund outcome.** §11; `SendChargebackDisputeNotificationTest` in full; `UsageWalletManagerReversalTest` method 10.
13. **No new admin surface beyond the one widened read; no provider-side origination; no entitlement/deliverable rollback; `ManualCredit`/`PromotionalCredit` never refundable.** §4, §17; `ProviderRefundDisputeSurfaceBoundaryTest` in full; `ProviderRefundOutcomeTest` method 16; `DirectDeliverableProviderOutcomeTest` method 11.
14. **Every wallet-mutating write is authority-correct, lock-ordered, and every mandatory-reason row is non-blank.** §20, §12; `UsageWalletManagerReversalTest`; `ProviderRefundOutcomeTest` method 14.

---

## 27. Bounded reads

Every new read is either `LIMIT`-bounded and index-supported (`retryable()`, `recentOutcomes()`) or scoped to `(funding_attempt_id[, provider_reference])` and supported by the single composite index. `hasOutstandingDisputeExposureForFundingAttempt()` replaces any PHP-side list with one SQL query bounded by `LIMIT 1`.

---

## 28. Regression commands

1. `php artisan test tests/Feature/Usage/ProviderPaymentIdentifierResolutionTest.php`
2. `php artisan test tests/Feature/Usage/ProviderRefundOutcomeTest.php`
3. `php artisan test tests/Feature/Usage/ProviderDisputeOutcomeTest.php`
4. `php artisan test tests/Feature/Usage/DisputeBalanceTransactionValidationTest.php`
5. `php artisan test tests/Feature/Usage/DirectDeliverableProviderOutcomeTest.php`
6. `php artisan test tests/Feature/Usage/UsageWalletManagerReversalTest.php`
7. `php artisan test tests/Feature/Usage/ProviderRefundDisputeSurfaceBoundaryTest.php`
8. `php artisan test tests/Feature/Usage/PaymentProviderEventRetryReclaimTest.php`
9. `php artisan test tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php`
10. `php artisan test tests/Feature/Usage/ProviderRefundDisputeConcurrencyTest.php`
11. `php artisan test tests/Feature/Usage/SendChargebackDisputeNotificationTest.php`
12. `php artisan test tests/Feature/Usage tests/Unit/Usage` (complete Usage-domain suite)
13. One complete `php artisan test --stop-on-failure` run (full repository suite)
14. `git diff --check`

No live-provider-hitting command is ever part of this suite.

---

## 29. Coverage matrix

| Question | Answered in |
|---|---|
| Which provider refund/dispute webhook/event types enter the system? | §2 |
| How is each event authenticated, normalized, deduplicated, retained, disposed? | §10, §19 |
| How is the affected Business/attempt identified without ambiguity? | §3 |
| Full/partial/repeated refunds; dispute created/updated/won/lost/reinstated | §6, §7, §15 |
| Which outcomes change wallet available balance or debt? | §9 |
| Can a Refund ever create debt? | §6, §23 — never |
| Which exact ledger entry types are produced; row shapes | §12 |
| How are amounts bounded? | §6, §7, §8 |
| What idempotency boundary applies? | §10 |
| Insufficient available balance on a refund vs. a dispute? | §6 (capped, never debt) vs. §7/§8 (may create/reverse debt) |
| Receipts/evidence | §17 |
| Domain events/jobs/notifications/audit records | §11, §18 |
| Outcomes requiring no mutation but still requiring durable audit | §15, §16, §18 |
| Retry/reconciliation/locking/transaction/crash-recovery boundaries | §19, §20 |
| Which existing admin surface is reused? | §18 — widened, not duplicated |
| What intentionally remains out of scope? | §17 |
| Multi-dispute / funding-attempt state correctness | §13, §21 |
| Direct-deliverable outcome persistence, idempotency, state | §4, §9 |
| Dispute balance-transaction cardinality and reversal lineage | §5, §8 |
| Low-balance marker / auto-recharge / receipt boundaries | §9, §11, §17 |
| The chargeback/dispute notification | §11 |
| Durable audit value semantics (reported/applied/policy-excess) | §18 |
| ManualCredit/PromotionalCredit refund eligibility | §4, §17 |
| Externally issued over-refund handling | §6 |

---

## 30. Validation performed before commit

- Full document read back for internal consistency — every cross-reference resolves to a section stated above it; no section depends on deleted content from any prior round.
- Searched for `debtIncurred`, `debt_delta_micro`, "creates debt", "insufficient available", `applyProviderRefund`, `refund_exceeds_refundable_balance`, `normalized_policy_excess_micro`, `EvaluateBusinessAutoRecharge`, `promotional`, `complimentary` — every remaining `Refund`-context match is consistent with the binding no-debt policy (§6); every `DisputeChargeback`-context match correctly still permits debt formation (§7, §9); no statement anywhere claims a `Refund` row may carry non-zero `debt_delta_micro`.
- Searched for `original pass`, `original-pass`, `distinctDisputeReferencesForFundingAttempt`, the prior two-branch-only `retryable()` description, the prior single `normalized_amount_micro` column name, and the prior 19/23-path or 80/123-method counts as live claims — none remain; every count in §22/§24 is freshly, mechanically stated.
- Every production/test path recounted mechanically: **24 production paths (9 new + 15 modified)**, **138 test methods across 11 new files**.
- `git diff --check` — clean.
- `git diff --name-only origin/main...HEAD` — exactly this one file.
- Confirmed no product, test, schema, config, route, or RFC-source file changed; `docs/automation/AI-AUTONOMY-STATE.json` untouched.
