# RFC-005 Remediation #6 — Provider Refund/Dispute Outcome Handling Contract

## Governance

- `maximum_correction_rounds`: 2 — **2 of 2 ordinary correction rounds consumed. 0 remaining.** This document is an **Exceptional post-review correction** on top of the completed Correction Round 2 — it is explicitly **not** Correction Round 3; the ordinary counters are unchanged.
- `human_only_merge`: true — this document, and any implementation branch built from it, is merged only by a human.
- **M6 remains frozen.** Nothing in this document authorizes any M6 work — no conformance document, no deployment guide, no release/tag work of any kind.
- No tag is authorized by this document.
- No deployment, live Stripe action, refund, dispute simulation against production, rate activation, meter activation, or pilot activation is authorized by this document or by authoring it.
- `docs/automation/AI-AUTONOMY-STATE.json` is untouched by this document and must remain untouched by any commit on this branch.
- This remediation is sequenced **before** remediation #7 (RFC-005 §35 test-coverage completion).
- **Authoring only.** This document locks a design. Implementing it is a separately, explicitly authorized future phase — no product or test code accompanies it.

**Base SHA:** `a8d2a0f9c03f8295262a9ec7b36374b4b0437294` — PR #148's merge commit, confirmed as `origin/main` before the branch was first created.

**Branch:** `chore/rfc-005-provider-refund-dispute-outcome-handling-contract`.

**No human product decision remains open.**

---

## Exceptional post-review correction record (informational — every rule below is restated live; nothing depends on this record)

Four blockers and one advisory, independently reproduced against current `main`, corrected this pass:

1. **The available-balance cap still let used or promotional credit be cashed out.** Excluding `ManualCredit`/`PromotionalCredit` from `findCreditEntryForFundingAttempt()` did not close the hole — those entries still inflate the single pooled `available_balance_micro` the refund formula capped against. **Corrected:** a new, wallet-level `refundable_paid_available_micro` counter, fed by an exact, deterministic paid-first consumption allocation threaded through `reserve()`, `commit()`, `release()`, `creditFromFunding()`, and `issueManualCredit()` (§6, §7), with a durable per-reservation `paid_attributable_amount_micro` snapshot. A refund's own headroom is now `min(unrefunded amount for the attempt, refundable_paid_available_micro, available_balance_micro)` — never total available balance alone.
2. **Out-of-order cumulative refunds could produce a negative delta.** Corrected to an exact, unconditional `max(0, ...)` clamp around the entire subtraction (§6), computed via `bcmath`, never native arithmetic.
3. **The durable audit amount semantics were internally contradictory** — one ambiguous pair could not simultaneously mean provider-cumulative amount, newly-recorded outcome progress, and wallet-balance movement. **Corrected:** four distinct, named fields — `normalized_reported_amount_micro`, `normalized_outcome_delta_micro`, `normalized_wallet_delta_micro`, `normalized_policy_excess_micro` — carried by a renamed, unambiguous `ProviderOutcomeResult` (§18).
4. **The retry index did not support the locked retry query** — a single `(state, attempts)` index cannot serve a three-branch `OR` query that also filters `received_at`/`lease_expires_at`. **Corrected:** the single `OR`-based query is replaced by three separately-indexed, separately-limited branch queries, merged and deduplicated under one hard overall limit (§19); `recentOutcomes()` is clamped on both sides.
5. **Advisory — the notification guarantee overclaimed exact-once external delivery.** Corrected everywhere to the honest guarantee this codebase's own existing notification precedent actually supports: at most one automatic dispatch *decision*, best-effort external delivery (§11).

Also corrected: the §19/§22 grace-interval contradiction (one accurate statement, §19); the malformed `"...are ever dispatched"` test name (§24); every stale count.

### Second exceptional post-review correction (this pass)

Still **not** Correction Round 3 — the ordinary counters remain **2 of 2 consumed, 0 remaining**. Two linked defects in §19's own three-branch retry design, independently reproduced against current `main`, corrected this pass:

6. **Fixed concatenation order could starve two retry classes.** `$received->concat($failed)->concat($staleProcessing)->take($limit)` discarded `$failed`/`$staleProcessing` entirely whenever `$received` alone already filled `$limit` — a sustained received backlog could starve the other two classes indefinitely. **Corrected:** a fair, deterministic round-robin interleave across the three independently fetched branches replaces fixed concatenation as the actual fairness mechanism (§19).
7. **`ORDER BY id` alone did not match any of the three composite indexes.** Each index places a range/filter column ahead of `id` — ordering by `id` alone after that is not the index's own native order, so the prior claim that these reads were fully index-supported was false. **Corrected:** each branch is now ordered by its own index's exact column sequence — `received_at, id`; `attempts, id`; `attempts, lease_expires_at, id` (§19).

### Third exceptional post-review correction (this pass)

Still **not** Correction Round 3 — the ordinary counters remain **2 of 2 consumed, 0 remaining**. One further, confirmed-remaining blocker in §19's own stale-processing query, independently reproduced against current `main`, corrected this pass:

8. **The stale-processing query was still not genuinely work-bounded.** After the equality prefix on `state`, `attempts < $maxAttempts` was the only predicate MySQL could use to navigate the B-tree range scan for that query — `lease_expires_at < now()` could not also be used as a navigable range in the same access and was instead applied as a residual filter while scanning the entire `attempts` range in index order. A large population of non-expired `processing` rows at a low `attempts` value could therefore force an arbitrarily large scan before an expired row at a higher `attempts` value was ever reached; `LIMIT` bounds returned rows, never examined rows, and "a genuinely small subset in a healthy system" was an unproven assumption, not a mechanical bound. **Corrected:** the stale-processing branch is now queried once per eligible attempt bucket (`attempts = $attempt`, for each `$attempt` from `0` to `$maxAttempts − 1`), turning the second predicate into a genuine equality and leaving `lease_expires_at < now()` as the query's own single, index-navigable range — fully sargable against the unchanged `(state, attempts, lease_expires_at, id)` index. The `$maxAttempts` per-bucket results are merged by the same round-robin interleave helper used across state classes, applied at a second, inner level, before that fair `staleProcessing` collection re-enters the unchanged outer three-class interleave. The total database-read bound is corrected from `3 × $limit` to `(2 + $maxAttempts) × $limit` — `$maxAttempts` being the existing, already-configured `usage_billing.webhook_event.max_attempts` value, not a new or invented ceiling.

---

## 1. Required reading, confirmed by direct re-read this pass

Everything read for Correction Rounds 1/2 remains re-confirmed. **Newly, directly re-read this pass, specifically to derive the four blockers' corrections:** `app/Library/Usage/UsageWalletManager.php`'s own `reserve()` (lines 285–530: the `available_balance_micro < reservedAmountMicro` admission check, the `Reservation` ledger insert, the wallet `UPDATE`), `commit()` (lines 533–760: the `chargedPortion = min($finalAmountMicro, $reservedAmountMicro)` committed-charge shape, the `$overage`/`$overageFromAvailable`/`$overageToDebt` split, the `$unused = $reservedAmountMicro - $finalAmountMicro` unused-release shape, all three confirmed to be exactly reusable as the basis for a parallel paid-attributable calculation with zero change to any existing column or formula), `release()` (lines 774–862: the full-reservation-amount restore shape); `app/Repositories/Contracts/PaymentProviderEventRepository.php`/its Eloquent implementation (confirmed no index exists on `state`/`attempts`/`received_at`/`lease_expires_at`/`normalized_recorded_at` beyond what Correction Round 2 itself proposed); `app/Jobs/Usage/SendLowBalanceNotification.php` (re-confirmed `$tries = 1`, `ShouldQueueAfterCommit`, and that this codebase has never once claimed "exactly-once external delivery" for any notification it sends — only "at most one dispatch decision").

---

## 2. Stripe object/event facts

**`Charge` object:** `id`, `payment_intent` (nullable), `customer`, `amount`, `amount_refunded` (cumulative, monotonically non-decreasing), `refunded` (boolean, true only once fully refunded), `currency`, `disputed`. `metadata` is independent, never inherited from the originating PaymentIntent.

**`Dispute` object:** `id`, `charge`, `payment_intent` (nullable), `amount` (the cardholder's *claimed* disputed amount — not a reliable statement of actual funds movement, §8), `currency`, `status`, `reason`, and **`balance_transactions`** — a list of zero, one, or two balance transactions.

**`BalanceTransaction` object:** `id` (`txn_...`), `amount` (signed, minor units), `currency`, `net`, `type`.

**`Refund` object:** `id` (`re_...`), `amount`, `currency`, `charge` (nullable), `payment_intent` (nullable), `status`, `balance_transaction`.

**Locked event routing:**

| Event type | Drives mutation? | Handling |
|---|---|---|
| `charge.refunded` | Yes — the sole cumulative-refund mutation authority | §6 |
| `charge.dispute.funds_withdrawn` | Yes | §8 |
| `charge.dispute.funds_reinstated` | Yes | §9 |
| `charge.dispute.created` / `.updated` / `.closed` | No | Audit-only, §16 |
| `refund.created` / `refund.updated` / `refund.failed` / `charge.refund.updated` | No | Audit-only, §17 |

---

## 3. Identification — two independent provider references, with an explicit ambiguity failure mode

`payment_intent` is nullable on both `Charge` and `Dispute`. Two already-normalized result DTOs expose a second, independent identifier at zero additional provider-call cost: `CheckoutSessionResult::$providerPaymentIntentId`/`$receiptChargeId`, `PaymentIntentResult::$receiptChargeId`.

**Locked:** two new nullable, independently-`UNIQUE`-when-populated columns on `business_funding_attempts`: `provider_payment_intent_reference`, `provider_charge_reference`. Populated synchronously, inside the transaction that finalizes the attempt to `Succeeded`, from data already in hand — never a new provider round-trip.

| Purpose/path | `provider_payment_intent_reference` | `provider_charge_reference` |
|---|---|---|
| `AutoRecharge` via `confirmAttemptFromReturn()`/`retryFundingAttemptAsAdministrator()` (both already call `retrievePaymentIntent()`) | `$attempt->provider_session_or_intent_reference` | `$paymentIntent->receiptChargeId` — already fetched |
| `AutoRecharge` via the ordinary webhook path | `$attempt->provider_session_or_intent_reference` | Left `NULL` |
| `ManualTopUp`/`AddonPurchase`, any confirmation path (all already call `retrieveCheckoutSession()`) | `$session->providerPaymentIntentId` | `$session->receiptChargeId` — already fetched |

**Routing:** resolve both `payment_intent` and (`Charge.id`/`Dispute.charge`/`Refund.charge`) independently. Both resolve to the same attempt → proceed. Both resolve to different attempts → `markFailed('cross_reference_ambiguity')`, zero mutation. Exactly one resolves → use it. Neither resolves → `markFailed('no_matching_local_record')`, zero mutation.

---

## 4. Which entries can ever be the subject of a provider refund — the wallet-backed determination and the promotional/manual-credit boundary

`findCreditEntryForFundingAttempt()` finds the original wallet-crediting entry, scoped exclusively to `entry_type IN ('paid_top_up', 'auto_recharge')`. `ManualTopUp`/`AutoRecharge` attempts always have one; an `AddonPurchase` attempt has one only when `fulfillment_mode = wallet_credit`.

**`ManualCredit`/`PromotionalCredit` are never eligible to be selected as refundable provider funding**, and — corrected this pass, §6 — **never inflate the wallet's own `refundable_paid_available_micro` counter either.** Excluding them from `findCreditEntryForFundingAttempt()` alone was insufficient, since that method only ever governed *which funding attempt* a refund concerns, never *how much of the wallet's pooled money is cash-refundable in the first place*. Both gates now apply together.

`$walletBacked`, resolved once per attempt from `findCreditEntryForFundingAttempt() !== null`, controls whether a mutation touches `available_balance_micro`/`debt_balance_micro`/`refundable_paid_available_micro` at all (§10) — never whether the outcome row is written, whether an accumulator advances, whether the audit records the outcome, or whether state is recomputed (§13, §18).

**A refunded/disputed `direct_deliverable` AddonPurchase remains historically `Completed` forever** — no refund/dispute code path calls `finalizeAddonPurchaseIfPending()`, `completeAddonPurchaseUnderLock()`, or writes `business_usage_addon_purchases.status`, or touches `refundable_paid_available_micro`.

---

## 5. Dispute balance-transaction shape — locked to zero/one/two, exact validation

Locked to exactly Stripe's own documented shape:

- **Zero entries** — no balance impact yet; audit-only (§16).
- **One entry, negative** — the ordinary withdrawal shape; drives §8.
- **One entry, positive, on `funds_reinstated` with no accompanying negative entry** — fails closed per §9's own rule.
- **Two entries, one negative and one positive** — the documented withdrawal-then-reinstatement shape.
- **More than two entries / two of the same sign / duplicate `id` values** → `markFailed('malformed_balance_transaction_array')`, zero mutation.
- **A currency mismatch on any entry** → `markFailed('currency_mismatch')`, zero mutation.

---

## 6. Refund policy — a provider-confirmed Refund can never create debt, and can never exceed unconsumed, provider-paid credit

**Binding policy, restated and corrected this pass:** valid, consumed wallet credit is non-refundable; `ManualCredit`/`PromotionalCredit` have no cash value and can never become cash-refundable — including indirectly, by later inflating the same pooled balance a prior payment's own refund is capped against. A Refund ledger entry never carries a non-zero `debt_delta_micro`.

### The hole in the prior design, and why it existed

Correction Round 2 capped a refund at `lockedWallet.available_balance_micro` — the wallet's single, fungible total. **Mechanical counterexample, reproduced and confirmed:** a Business pays in 100, consumes all 100 (available balance returns to 0), then receives a 100 `PromotionalCredit` (available balance returns to 100, entirely non-cash). Capping against `available_balance_micro` alone would permit refunding the *original, already-consumed* 100 payment — the promotional grant has been laundered into cash. Excluding `ManualCredit`/`PromotionalCredit` from `findCreditEntryForFundingAttempt()` (which governs *which attempt* a refund event concerns) does nothing to prevent this, since the pooled *balance* those entries inflate is untouched by that exclusion.

### The corrected mechanism — a wallet-level refundable-paid-available counter, with exact, deterministic, paid-first allocation

**Locked: `business_usage_wallets.refundable_paid_available_micro`** — a new bigint counter, wallet-scoped (pooled, not per-attempt or per-credit-lot — this design still introduces no credit-lot/source-level attribution among fungible funds; it distinguishes exactly two categories, paid and non-paid, at the wallet level, nothing finer). It is always `≤ available_balance_micro` and always `≥ 0`, maintained as an invariant by construction across every mutation site below (§23).

**Allocation rule, locked, applied everywhere: paid-first consumption.** Whenever money leaves `available_balance_micro` for any reason (a reservation, a committed overage, a refund, a dispute withdrawal), it is drawn from `refundable_paid_available_micro` first, up to what that counter currently holds — the remainder, if any, is drawn from non-paid (promotional/manual/already-non-refundable) balance, which never affects the counter. Whenever money returns to `available_balance_micro` (a release, an unused-reservation restore, a dispute reinstatement), only the portion that was **originally, durably recorded as paid-attributable** is ever restored to the counter — never more, and never a portion re-derived by guessing after the fact.

### Exact formulas, every existing mutation site

**`creditFromFunding()`** (`ManualTopUp`, `AutoRecharge`, `AddonPurchase` with `fulfillment_mode = wallet_credit`) — unchanged existing formula (`$debtCleared`, `$remainder`), one addition:

```
wallet.refundable_paid_available_micro += $remainder   // the identical amount already added to available_balance_micro
```

`$debtCleared` never affects the counter, exactly as it never affects `available_balance_micro`.

**`issueManualCredit()`** (`ManualCredit`/`PromotionalCredit`) — **no change whatsoever to `refundable_paid_available_micro`.** `available_balance_micro` increases exactly as today; the counter does not follow it.

**`reserve()`** — one addition, computed under the already-held wallet lock, before the existing admission checks return:

```
$paidAttributable = min($reservedAmountMicro, max(0, $wallet->refundable_paid_available_micro));
wallet.refundable_paid_available_micro -= $paidAttributable;
```

**Locked, new, durable column: `business_usage_reservations.paid_attributable_amount_micro`** — set once, at insert, to `$paidAttributable` — the exact snapshot `commit()`/`release()` will later consume or restore. Never re-derived.

**`release()`** (a `Pending` reservation released or expired before commit, full restore) — one addition:

```
wallet.refundable_paid_available_micro += $reservation->paid_attributable_amount_micro;
```

**`commit()`** — three additions, each at the exact point the existing formula already computes the corresponding value:

- **Committed portion** (the `$chargedPortion = min($finalAmountMicro, $reservedAmountMicro)` branch): **no wallet-counter mutation** — this money's paid-attributable share was already removed from the counter at `reserve()` time; committing it merely converts a reservation into a permanent charge, exactly as `reserved_delta_micro`/`available_delta_micro` already reflect (the committed portion was never restored to `available_balance_micro` in the first place — it moves reserved→spent, not available→spent).
- **Unused portion** (the `$unused = $reservedAmountMicro - $finalAmountMicro` branch, only when `$finalAmountMicro < $reservedAmountMicro`):
  ```
  $unusedPaidPortion = $reservation->paid_attributable_amount_micro - min($finalAmountMicro, $reservation->paid_attributable_amount_micro);
  wallet.refundable_paid_available_micro += $unusedPaidPortion;
  ```
  Derived by treating actual final usage as consuming the reservation's own paid-attributable snapshot first, identically to the reservation's own original paid-first allocation — exact, no proportional/fractional math, no rounding risk.
- **Overage portion** (the `$overageFromAvailable = min($overage, max(0, $wallet->available_balance_micro))` branch, only when `$finalAmountMicro > $reservedAmountMicro`) — this money was never pre-reserved, so it draws directly against the wallet's *current* counter, not the reservation's own snapshot:
  ```
  $overagePaidPortion = min($overageFromAvailable, max(0, $wallet->refundable_paid_available_micro));
  wallet.refundable_paid_available_micro -= $overagePaidPortion;
  ```

**`Refund` (§10)** — the headroom formula itself is corrected (this is the actual fix to the hole):

```
refundHeadroomMicro = min(max(0, lockedWallet.available_balance_micro), max(0, lockedWallet.refundable_paid_available_micro))
walletDebitMicro     = min(providerRefundDelta, refundHeadroomMicro)
policyExcessMicro    = providerRefundDelta − walletDebitMicro
```

```
wallet.available_balance_micro -= walletDebitMicro
wallet.refundable_paid_available_micro -= walletDebitMicro   // exact, equal reduction — a refund is definitionally cash leaving via the paid channel
```

**`DisputeChargeback` (§8)** — the existing `$chargebackFromAvailable = min($amt, max(0, $wallet->available_balance_micro))` debit portion additionally draws the counter, bounded by what it currently holds (a chargeback may legitimately remove money the counter no longer attributes as paid, if non-paid funds have already displaced it):

```
$chargebackPaidPortion = min($chargebackFromAvailable, max(0, $wallet->refundable_paid_available_micro));
wallet.refundable_paid_available_micro -= $chargebackPaidPortion;
```

**`CorrectionReversal` (§9)** — restores the counter only for the paid-attributable amount the *original* `DisputeChargeback` actually removed from it, and only to the extent the reinstatement produces genuine available credit (never for the debt-clearing portion):

```
$originalPaidPortionRemoved = abs($originalChargebackEntry->refundable_paid_delta_micro);   // §12's own signed audit field on that exact row
$reinstatePaidPortion = min($remainder, $originalPaidPortionRemoved);
wallet.refundable_paid_available_micro += $reinstatePaidPortion;
```

**Zero-delta `direct_deliverable` outcomes** — `refundable_paid_available_micro` is never touched, in either direction, under any circumstance (§10).

### The out-of-order/negative-delta fix (corrects a second, independent defect)

```
boundedProviderCumulative = min(providerCumulativeRefundMicro, attempt.expected_amount_micro)
providerRefundDelta = max(0, bcsub(boundedProviderCumulative, alreadyRecordedRefundGrossMicro, 0))
```

computed entirely in `bcmath` (`bcsub()`, `bccomp()`), never native subtraction. **Any provider cumulative amount less than or equal to already-recorded gross progress — whether exactly equal (an ordinary replay) or strictly lower (a genuinely out-of-order, older event delivered after a newer one) — produces `providerRefundDelta = 0` and is a complete no-op:** no ledger row, no wallet/refundable-paid mutation, no state transition, no suspension, no notification. The prior formula's own unclamped subtraction could produce a negative value for the out-of-order case specifically (never for the ordinary equal-cumulative replay, which was already correctly `0`) — this is corrected everywhere this formula is used or referenced.

### Normal, policy-compliant refund (`policyExcessMicro === 0`)

The `Refund` ledger row (§12): `gross_amount_micro = providerRefundDelta`; `available_delta_micro = -walletDebitMicro`; `reserved_delta_micro = 0`; `debt_delta_micro = 0` unconditionally; `refundable_paid_delta_micro = -walletDebitMicro`.

### Externally issued over-refund (`policyExcessMicro > 0`)

Unchanged in shape from Correction Round 2, restated exactly: debit only `walletDebitMicro` (now itself correctly capped by *both* `available_balance_micro` and `refundable_paid_available_micro`, §above); never create debt; write one idempotent `Refund` row with `gross_amount_micro = providerRefundDelta` (the complete newly-confirmed delta, driving §13's own gross-progress state recomputation) and `available_delta_micro = -walletDebitMicro`; recompute state from gross refund progress; suspend billing in the same transaction via `BillingStatusTransitionSource::ProviderRefundMismatch` (a new, precise enum case — `DisputeWebhook`/`AdminAction` are never repurposed), guarded against a redundant transition row exactly as §11 locks for dispute suspension; record `normalized_outcome = refund_exceeds_refundable_balance` and `normalized_policy_excess_micro`; mark the event terminally `processed`, never `failed` — retrying cannot undo a refund Stripe has already issued; surface it in the existing, widened administrator audit table (§18); dispatch no auto-recharge, no receipt, and no dedicated chargeback notification (§17); replays and different-event-id reports of the same cumulative refund produce no additional row, debit, suspension, or notification.

### Low-balance marker and wallet events

`lowBalanceMarkerUpdate()` applies using the wallet's resulting available balance after `walletDebitMicro` is actually removed; `SendLowBalanceNotification` dispatches after commit only when the marker rule requests it. `BusinessWalletDebited` dispatches only for the actual `walletDebitMicro` — never when it is `0`.

---

## 7. Refund exposure is computed independently of dispute activity — unaffected by §6

`sumRefundedMicroForFundingAttempt()` (§14) sums only `Refund` rows for the attempt; dispute processing never reads it, and it never reads `DisputeChargeback`/`CorrectionReversal` rows. Structurally independent, exactly as Correction Round 2 already locked.

---

## 8. Dispute withdrawal/reinstatement — signed, per-transaction, per-dispute; `DisputeChargeback` may still create debt

**Restated explicitly: nothing in §6's binding policy affects `DisputeChargeback` or `CorrectionReversal`.** A `DisputeChargeback` may still create debt, exactly as the RFC's own §13 delta table specifies (`-min(amt, avail)`/`0`/`+max(0, amt-avail)`). On `charge.dispute.funds_withdrawn`, for the negative-`amount` entry not yet applied, apply exactly one `DisputeChargeback` entry for `abs(amount)`.

**Per-dispute bounding, independent of the refund accumulator and of any other dispute:** `provider_reference` is set to the dispute's own `id` on every `DisputeChargeback`/`CorrectionReversal` row:

```
withdrawn  = SUM(gross_amount_micro) WHERE funding_attempt_id = ? AND provider_reference = ? AND entry_type = 'dispute_chargeback'
reinstated = SUM(gross_amount_micro) WHERE funding_attempt_id = ? AND provider_reference = ? AND entry_type = 'correction_reversal'
```

Exact SQL-aggregate strings (§14).

---

## 9. Reinstatement — current wallet state, mechanically resolved lineage, bounded to the specific dispute

**Never a negation of the original entry's own stored deltas.** Formula, from the wallet's currently-locked state:

```
debtCleared = min(reinstatementAmountMicro, max(0, wallet.debt_balance_micro))
remainder   = reinstatementAmountMicro − debtCleared
available_delta_micro = +remainder      (0 if not wallet-backed)
debt_delta_micro      = −debtCleared    (0 if not wallet-backed)
```

**Refundable-paid restoration** — §6's own formula, restated: `min($remainder, abs($originalChargebackEntry->refundable_paid_delta_micro))`, never more than what the original chargeback actually removed, never for the debt-clearing portion.

**Bounded to the specific dispute's own actual withdrawn amount** — `min(reportedBalanceTransactionAmount, withdrawn − reinstated)`, clamped to `0`.

**Lineage — mechanically resolved, never guessed:** the `funds_reinstated` event's own `balance_transactions[]` array carries both the reinstatement entry and its own withdrawal counterpart (§5's documented two-entry shape). The withdrawal entry's own `id` deterministically identifies its correlation key (`'dispute_chargeback:'.$attempt->id.':'.$withdrawalBalanceTransactionId`); `findByCorrelationKey()` (existing, unmodified) resolves the exact original `DisputeChargeback` row — including a zero-delta one (§10). `reversed_entry_id` is set to that row's own id. Missing or ambiguous lineage → `markFailed('missing_original_chargeback_reference')`, zero mutation.

- **Never produces negative debt.**
- **Dispatches `BusinessWalletCredited`/`BusinessWalletDebtCleared`** when wallet-backed; dispatches neither when not.

---

## 10. Wallet-backed versus zero-delta outcome rows

Every genuinely new provider financial outcome writes an append-only ledger outcome row, whether or not the original funding attempt ever credited the wallet. `$walletBacked` (§4) controls:

| | Wallet-backed | Zero-delta (`direct_deliverable`) |
|---|---|---|
| `available_delta_micro`/`debt_delta_micro`/`refundable_paid_delta_micro` (`Refund`) | `-walletDebitMicro` / `0` / `-walletDebitMicro` (§6) | `0` / `0` / `0` |
| `available_delta_micro`/`debt_delta_micro`/`refundable_paid_delta_micro` (`DisputeChargeback`) | `-min(amt,avail)` / `+max(0,amt-avail)` / `-chargebackPaidPortion` (§8) | `0` / `0` / `0` |
| `available_delta_micro`/`debt_delta_micro`/`refundable_paid_delta_micro` (`CorrectionReversal`) | Per §9 | `0` / `0` / `0` |
| Wallet row `UPDATE` (all three balance-shaped columns) | Applied | **Never applied** |
| `lowBalanceMarkerUpdate()` | Applied | **Never touched** |
| Wallet balance events | Dispatched per formula, only when the corresponding delta is non-zero | **Never dispatched** |
| Billing-status suspension (`DisputeChargeback` or refund policy-excess only) | Applied | **Still applied — a risk-control decision, not conditional on wallet-credit fulfillment** |
| Chargeback notification (`DisputeChargeback` only, §11) | Dispatched (decision made) | **Still dispatched (decision made), for the identical reason** |
| Funding-attempt state recomputation | Applied | **Still applied, identically** |
| Durable audit attribution | Applied | **Still applied, identically** |

A reinstatement of a zero-delta chargeback remains zero-delta — it never credits the wallet, and never touches `refundable_paid_available_micro`.

---

## 11. Idempotency — deterministic correlation keys

1. **Event-identity layer:** `payment_provider_events.UNIQUE(provider, provider_event_id)`.
2. **Outcome layer:** `Refund`: `'refund:'.$attempt->id.':'.$boundedProviderCumulative` — keyed to the bounded cumulative figure this event reports, not the delta, so both an exact replay and an out-of-order lower-cumulative delivery collide on an already-applied or lower value and compute `providerRefundDelta = 0` before any write is attempted. `DisputeChargeback`: `'dispute_chargeback:'.$attempt->id.':'.$balanceTransactionId`. `CorrectionReversal`: `'dispute_reversal:'.$attempt->id.':'.$balanceTransactionId`.

Both layers apply identically to wallet-backed and zero-delta rows.

---

## 12. Ledger row shapes — every field locked, including the new refundable-paid audit field

**`Refund`:**

```php
[
    'business_id' => $attempt->business_id,
    'wallet_id' => $wallet->id,
    'funding_attempt_id' => $attempt->id,
    'entry_type' => UsageLedgerEntryType::Refund->value,
    'available_delta_micro' => $walletBacked ? -$walletDebitMicro : 0,
    'reserved_delta_micro' => 0,
    'debt_delta_micro' => 0,
    'refundable_paid_delta_micro' => $walletBacked ? -$walletDebitMicro : 0,
    'gross_amount_micro' => $providerRefundDelta,
    'currency_id' => $wallet->currency_id,
    'correlation_key' => 'refund:'.$attempt->id.':'.$boundedProviderCumulative,
    'provider_reference' => $providerChargeReference,
    'actor_user_id' => null,
    'reason' => "Provider-confirmed refund of {$providerRefundDelta} micro-units against charge {$providerChargeReference}.",
    'reversed_entry_id' => null,
    'created_at' => now(),
]
```

**`DisputeChargeback`:** identical shape, `entry_type = dispute_chargeback`, deltas per §8/§10, `refundable_paid_delta_micro = -$chargebackPaidPortion` (or `0` when not wallet-backed), `provider_reference = $providerDisputeId`, `reversed_entry_id = null`.

**`CorrectionReversal`:** `entry_type = correction_reversal`, deltas per §9/§10, `refundable_paid_delta_micro = +$reinstatePaidPortion` (or `0`), `provider_reference = $providerDisputeId`, `reversed_entry_id = $originalChargebackEntry->id`.

**Every other entry type this correction touches** — `Reservation`, `ReservationRelease`, `UsageCharge`, `UsageOverageCharge`, `PaidTopUp`, `AutoRecharge` — gains the identical `refundable_paid_delta_micro` field, populated per §6's own exact formula for that mutation site; every entry type this correction does not touch (`ManualCredit`, `PromotionalCredit`, and all others) leaves it `NULL`.

No `Refund`-writing code path ever assigns a non-zero `debt_delta_micro`. `reason` is never null for `Refund`/`DisputeChargeback`/`CorrectionReversal`.

---

## 13. Funding-attempt state — recomputed from every outstanding outcome, under lock

```
refunded = sumRefundedMicroForFundingAttempt(attemptId)                                  // gross figure, exact string
anyDisputeOutstanding = hasOutstandingDisputeExposureForFundingAttempt(attemptId)         // §20, one bounded SQL query

state = match (true) {
    anyDisputeOutstanding => Disputed,
    bccomp(refunded, attempt.expected_amount_micro, 0) >= 0 => Refunded,
    default => Succeeded,
};
```

`refunded` sums `gross_amount_micro` — for a policy-excess refund row, the **complete** confirmed delta, not merely `walletDebitMicro` — so a fully-confirmed refund still reaches `Refunded` even though part exceeded the refundable-paid cap. The write is skipped when the recomputed state equals the currently-persisted one.

---

## 14. Amount/currency conversion

`UsageBillingCheckoutManager::minorUnitsToMicro()`/`expectedMicroForMinorUnits()` invert `microToMinorUnits()`'s own existing exact `bcmath` exponent table.

---

## 15. Bounded SQL-aggregate reads

Both refund progress and dispute exposure remain computed entirely in SQL, exact-decimal-string, never PHP-collection-reduced, unchanged in mechanism from Correction Round 2 — `sumRefundedMicroForFundingAttempt()`, `sumDisputeMicroForFundingAttemptAndDispute()`, `hasOutstandingDisputeExposureForFundingAttempt()` (§20), `findCreditEntryForFundingAttempt()`.

---

## 16. Dispute audit-only events

`charge.dispute.created`/`.updated`/`.closed`, and a `funds_withdrawn`/`funds_reinstated` event whose own `balance_transactions[]` is empty, produce zero mutation, are durably recorded (`normalized_outcome = dispute_audit_only`, §18), and are marked `ignored`.

---

## 17. Exclusions — restated in full, live

This design never:

- Originates a provider-side refund, dispute response, or evidence submission.
- Introduces any new customer- or administrator-facing action to request, initiate, or simulate a refund or dispute; any future such action must itself validate the §6 refundable-paid cap before ever calling Stripe, as its own, separately authorized contract.
- Introduces any new HTTP route beyond the one widened, reused read (§18).
- Un-completes, reverses, or rolls back any entitlement or deliverable an `AddonPurchase` may have unlocked (§4).
- Performs any live provider call to backfill historical data (§21) — an unresolvable historical reference fails closed into the existing exhausted-event queue.
- Performs any M6 work.
- Fabricates a new "successful payment" receipt for a refund or dispute of any kind.
- Dispatches `EvaluateBusinessAutoRecharge` from any refund or dispute code path, compliant or policy-excess.
- Calls `SendReceiptNotification` or writes a receipt from any refund/dispute code path.
- Treats `ManualCredit`/`PromotionalCredit` as refundable provider funding, or lets them inflate `refundable_paid_available_micro` (§4, §6).
- Introduces credit-lot or source-level attribution finer than the single paid/non-paid wallet-level distinction §6 locks.
- Claims exact-once external email delivery (§11) — only an honest, at-most-one dispatch-decision guarantee is made.

---

## 18. Durable, administrator-visible, Business-attributed audit records — four distinct amount fields, unambiguous

`PaymentProviderEventController::index()` today renders only `exhausted()` rows. **Locked — widen the existing `payment_provider_events` table, never a new table.** Eleven new, nullable columns: `business_id` (no FK), `funding_attempt_id` (no FK), `normalized_outcome` (`string(32)`), `normalized_status` (`string(32)`), **`normalized_reported_amount_micro`**, **`normalized_outcome_delta_micro`**, **`normalized_wallet_delta_micro`**, **`normalized_policy_excess_micro`** (each bigint), `normalized_currency_code` (`string(3)`), `normalized_reason` (`string(64)`), `normalized_recorded_at` (timestamp).

**Corrected this pass — the prior single ambiguous pair (`reported`/`applied`) could not simultaneously represent three different quantities. Four fields, each with one, unambiguous meaning:**

- **`normalized_reported_amount_micro`** — the provider's own reported figure, exactly as Stripe stated it, bounded only where explicitly documented (for a refund, `min(providerCumulativeRefundMicro, expected_amount_micro)` — the **cumulative** total-to-date, unchanged by whether this specific event turns out to be a replay; for a dispute movement, the balance transaction's own reported `abs(amount)`).
- **`normalized_outcome_delta_micro`** — the newly-accepted, idempotent financial-outcome progress this specific processing run recorded; `0` on a pure replay; equals the newly-written `Refund`/`DisputeChargeback`/`CorrectionReversal` row's own `gross_amount_micro`, **including** for a `direct_deliverable` zero-wallet-delta outcome (a real outcome was recorded, even though no wallet column moved).
- **`normalized_wallet_delta_micro`** — the actual, positive-magnitude wallet-balance-column movement this event caused (`available`+`debt` combined, whichever direction applies) — `0` for `direct_deliverable` and for a replay; strictly less than `normalized_outcome_delta_micro` for a policy-excess refund (`= walletDebitMicro`, while the outcome delta carries the complete confirmed figure).
- **`normalized_policy_excess_micro`** — only the newly-accepted refund delta that could not be honored as a cash refund; `0` otherwise.

**Exact worked values, locked:**

| Scenario | `reported` | `outcome_delta` | `wallet_delta` | `policy_excess` |
|---|---|---|---|---|
| Compliant partial refund, cumulative reaches 100, 60 already recorded | `100` | `40` | `40` | `0` |
| Replay of an already-fully-applied cumulative 100 | `100` | `0` | `0` | `0` |
| `direct_deliverable` refund, cumulative 100, first time seen | `100` | `100` | `0` | `0` |
| Policy-excess refund, cumulative 100, only 60 was available | `100` | `100` | `60` | `40` |

**A typed outcome result drives the write — never a job-side recomputation.** `App\Library\Usage\ProviderOutcomeResult` (renamed fields, unambiguous):

```php
final readonly class ProviderOutcomeResult
{
    public function __construct(
        public string $normalizedOutcome,
        public int $reportedAmountMicro,
        public int $outcomeDeltaMicro,
        public int $walletDeltaMicro,
        public int $policyExcessMicro,
        public ?int $ledgerEntryId,
        public FundingAttemptState $resultingState,
    ) {}
}
```

`ProcessPaymentProviderEvent` reads these five fields directly into `$attribution` — it never independently recomputes any of them.

**Admin surface widened, not duplicated:** `recentOutcomes(int $limit = 50): Collection`, its accepted limit clamped on **both** sides — `max(1, min($limit, self::MAX_RECENT_OUTCOMES_LIMIT = 100))`. Ordered `normalized_recorded_at DESC, id DESC` (a deterministic tie-break, §19). The view renders all four amount columns distinctly labeled ("Reported," "Outcome delta," "Wallet delta," "Policy excess"), plus Business, funding attempt, outcome, status, currency, reason, recorded-at. The existing exhausted-events table and disposition form are unmodified.

**Bounded database work** — §19's own three-branch retry index design, plus `(normalized_recorded_at, id)` for `recentOutcomes()`.

**Attribution survives payload purge** — unchanged, `PurgeExpiredWebhookPayloads` never touches any of the eleven new columns.

---

## 19. Retry/reclaim — index-supported branch queries, one per eligible processing-attempt bucket, fairly interleaved at two levels

**Corrected: a single `(state, attempts)` index cannot support a three-branch `OR` query that also filters on `received_at` and `lease_expires_at` — a `LIMIT` clause alone does not make that query bounded, it only bounds the *result*, not the *work MySQL performs to find it*.** The prior design's own claim that `retryable()` was "operationally index-bounded" on that one index was false.

**Three linked defects have now been found and corrected across this section's revisions:**

1. **Fixed concatenation order could starve two branches.** `$received->concat($failed)->concat($staleProcessing)->take($limit)` discards `$failed`/`$staleProcessing` entirely whenever `$received` alone already returns `$limit` rows. **Corrected** to a fair, deterministic round-robin interleave that is the actual fairness mechanism, never concatenation order.
2. **`ORDER BY id` alone did not match any of the three composite indexes.** Each index places a range/filter column ahead of `id`. **Corrected**: every branch orders by its own index's exact column sequence.
3. **The stale-processing branch's own query was still not genuinely work-bounded — confirmed and corrected this pass.** `WHERE state = 'processing' AND attempts < $maxAttempts AND lease_expires_at < now()`, ordered `attempts, lease_expires_at, id`, against index `(state, attempts, lease_expires_at, id)`: after the equality prefix on `state`, `attempts < $maxAttempts` is the *only* predicate MySQL can use to navigate the B-tree range scan — `lease_expires_at < now()` cannot also be used as a navigable range boundary in that same access, so it is applied as a residual filter *while scanning every row in the `attempts` range, in `(attempts, lease_expires_at, id)` order*. A large population of non-expired `processing` rows at a low `attempts` value therefore forces MySQL to examine all of them — failing the `lease_expires_at` filter one by one — before it ever reaches a genuinely expired row sitting at a higher `attempts` value later in that same scan order. `LIMIT` bounds only what is *returned*, never what is *examined*; "a genuinely small subset in a healthy system" was an assumption, not a mechanical bound, and is withdrawn. **Corrected below**, by converting the `attempts` range into one equality query per eligible attempt value, so every remaining predicate is a genuine, single, index-navigable range.

### Clamp

`retryable()`'s accepted `$limit` is clamped to a positive locked maximum before any query executes; its accepted `$maxAttempts` is clamped to a non-negative integer:

```
$limit = max(1, min($limit, self::MAX_RETRYABLE_LIMIT));   // MAX_RETRYABLE_LIMIT = 200, a plain class constant on EloquentPaymentProviderEventRepository, matching the scanner's own BATCH_LIMIT
$maxAttempts = max(0, $maxAttempts);
```

**No new, invented configuration ceiling is required or introduced.** `$maxAttempts` is not new to this design — it is the exact, already-existing `usage_billing.webhook_event.max_attempts` config value (default `5`, `USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS`-overridable), already read as a plain `(int)` and already the sole authority governing retry eligibility everywhere else in this codebase: `ProcessPaymentProviderEvent::handle()`'s own `claim()` call, `PaymentProviderEventController`'s `exhausted()`/disposition calls, and the `attempts < $maxAttempts` predicate already present in both the `failed` branch above and the prior stale-processing query. The set of eligible `processing`-state attempt values is exactly the integers `0` through `$maxAttempts − 1` — a finite set whose size is `$maxAttempts` itself, mechanically bounded by the identical, already-trusted operational parameter this codebase already relies on, not a newly invented assumption. No safe finite ceiling was missing from current code — this value already is one.

### Per-branch queries — the stale-processing branch queried once per eligible attempt bucket, each fully sargable

```php
public function retryable(int $maxAttempts, int $receivedGraceMinutes, int $limit): Collection
{
    $limit = max(1, min($limit, self::MAX_RETRYABLE_LIMIT));
    $maxAttempts = max(0, $maxAttempts);

    $received = DB::table('payment_provider_events')
        ->where('state', 'received')
        ->where('received_at', '<', now()->subMinutes($receivedGraceMinutes))
        ->orderBy('received_at')->orderBy('id')
        ->limit($limit)->get();

    $failed = DB::table('payment_provider_events')
        ->where('state', 'failed')
        ->where('attempts', '<', $maxAttempts)
        ->orderBy('attempts')->orderBy('id')
        ->limit($limit)->get();

    $staleProcessingBuckets = [];

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $staleProcessingBuckets[] = DB::table('payment_provider_events')
            ->where('state', 'processing')
            ->where('attempts', $attempt)
            ->where('lease_expires_at', '<', now())
            ->orderBy('lease_expires_at')->orderBy('id')
            ->limit($limit)->get();
    }

    $staleProcessing = $this->interleaveRetryBranches($staleProcessingBuckets, $limit);

    return $this->interleaveRetryBranches([$received, $failed, $staleProcessing], $limit);
}
```

| Branch | Query order | Supporting index | Predicate shape |
|---|---|---|---|
| Received recovery | `ORDER BY received_at ASC, id ASC` | `(state, received_at, id)` | Equality (`state`) + one range (`received_at`) |
| Failed recovery | `ORDER BY attempts ASC, id ASC` | `(state, attempts, id)` | Equality (`state`) + one range (`attempts`) |
| Stale-processing recovery, **one query per eligible `attempts` value `0..$maxAttempts-1`** | `ORDER BY lease_expires_at ASC, id ASC` | `(state, attempts, lease_expires_at, id)` | Equality (`state`, `attempts`) + one range (`lease_expires_at`) |

Every branch query now has **exactly one** range predicate, positioned immediately after its index's equality prefix — the standard, fully sargable "equality-prefix-plus-one-trailing-range" shape. For the stale-processing branch specifically: `state` and `attempts` are both bound to single, exact values, so `lease_expires_at < now()` is the *only* remaining predicate, and it is now genuinely index-navigable — MySQL seeks directly to the qualifying range within that one `(state, attempts)` group instead of scanning every row across the full `attempts < $maxAttempts` range first. **No claim anywhere in this document states or implies that a query with two unresolved range/filter predicates after its index's equality prefix is fully index-bounded** — every remaining query in this section has exactly one.

### Two-level fair interleaving merge — attempt buckets first, then state classes

**Level 1 — across attempt buckets, within stale-processing.** The `$maxAttempts` per-bucket collections (each already index-ordered and `LIMIT $limit`-bounded) are merged by the identical round-robin `interleaveRetryBranches()` helper (below), capped at `$limit`, producing one fair `$staleProcessing` collection. A row cannot appear in two different attempt-bucket results simultaneously — an event has exactly one `attempts` value at any instant — so deduplication here, too, is defensive, never load-bearing.

**Level 2 — across state classes.** `[$received, $failed, $staleProcessing]` are merged by the same helper, capped at `$limit`, exactly as the prior pass locked. `received`/`failed`/`processing` remain mutually exclusive by `state`, so this level's deduplication is likewise defensive only.

```php
private function interleaveRetryBranches(array $branches, int $limit): Collection
{
    $branches = array_map(fn ($branch) => $branch->values(), $branches);
    $cursors = array_fill(0, count($branches), 0);
    $selected = collect();
    $seen = [];

    while ($selected->count() < $limit) {
        $madeProgress = false;

        foreach ($branches as $i => $branch) {
            if ($selected->count() >= $limit) {
                break;
            }
            if ($cursors[$i] >= $branch->count()) {
                continue;
            }

            $row = $branch[$cursors[$i]];
            $cursors[$i]++;
            $madeProgress = true;

            if (! isset($seen[$row->id])) {
                $seen[$row->id] = true;
                $selected->push($row);
            }
        }

        if (! $madeProgress) {
            break;
        }
    }

    return $selected->values();
}
```

The same generic helper serves both levels unmodified — no second implementation is introduced. At Level 1, one candidate is taken from attempt-bucket `0`, then `1`, then `2`, … up to `$maxAttempts − 1`, repeating; a saturated low-`attempts` bucket can never starve a sparsely populated higher-`attempts` bucket, since every bucket with a remaining candidate is offered exactly one slot per round regardless of how large another bucket's own result set is. At Level 2, the identical guarantee applies across `received`/`failed`/`staleProcessing`, unchanged from the prior pass. No branch or bucket is ever permanently preferred by construction at either level.

### Exact bounds, locked — corrected this pass

The prior "three queries, `3 × $limit` total" bound no longer holds, since the stale-processing branch is now `$maxAttempts` separate queries, not one.

- **Per-query database-read bound:** every individual query — the one `received` query, the one `failed` query, and each of the `$maxAttempts` stale-processing bucket queries — is independently `LIMIT $limit`-bounded and fully served by its own declared index, with exactly one navigable range predicate. No single query ever reads more than `$limit` rows.
- **Total database-read bound for one `retryable()` call: `(2 + $maxAttempts) × $limit` rows** — one bounded, indexed read for `received`, one for `failed`, and one per eligible stale-processing attempt bucket (`$maxAttempts` of them), each individually bounded. `$maxAttempts` is the existing, already-configured, operator-set `usage_billing.webhook_event.max_attempts` value (default `5` ⇒ default total bound `7 × $limit`) — a small, finite, pre-existing operational parameter, never attacker- or request-controlled, and never larger than the retry ceiling every event in the system is already subject to.
- **Dispatch bound: exactly `$limit`**, unchanged — identical to the scanner's own `BATCH_LIMIT` when the scanner calls `retryable(..., self::BATCH_LIMIT)`. Both interleave levels' own `while ($selected->count() < $limit)` guard enforce this exactly; either level returns fewer than `$limit` only when its own inputs are exhausted first, never more.
- **Degenerate case:** if `$maxAttempts` is configured to `0` or a negative value, the `for` loop issues zero stale-processing queries and `$staleProcessing` is empty — safe, and consistent with the identical `attempts < $maxAttempts` predicate already matching zero rows elsewhere in the codebase under the same configuration.

**Three indexes, unchanged in DDL from the prior pass, each still derived directly from the branch that uses it (Migration 5, §25 — no schema change required by this pass):**

- **Received-recovery branch:** `(state, received_at, id)`.
- **Failed-recovery branch:** `(state, attempts, id)`.
- **Stale-processing-recovery branch:** `(state, attempts, lease_expires_at, id)` — now used with an equality predicate on `attempts` (one query per value) rather than a range, which is exactly what lets the trailing `lease_expires_at` predicate become genuinely index-navigable. The index's own column order was already correct; only the query shape using it was wrong.

- **`RetryStuckPaymentProviderEvents`**, `everyFiveMinutes()` (matching the existing `webhook_event.lease_minutes` config value, reused as the received-row grace interval — the scanner's own batch limit (`self::BATCH_LIMIT = 200`) is a plain class constant; the grace interval is **not** a class constant and **not** a new config key — it is the existing `usage_billing.webhook_event.lease_minutes` value, read exactly as the existing claim algorithm already reads it). Performs the `(2 + $maxAttempts)` bounded, index-ordered reads above, interleaves them fairly at both levels, and issues zero-or-more `dispatch()` calls — no accounting mutation inside the scanner.
- **Concurrency-safe with no new code in `ProcessPaymentProviderEvent`** — the claim statement's own atomicity is unchanged and remains the sole authority.
- **`processed`/`ignored`/`disposed` events are never redispatched** — none matches any state-class branch or attempt bucket.

---

## 20. `hasOutstandingDisputeExposureForFundingAttempt()` — one bounded scalar query

Unchanged from Correction Round 2:

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
        ->limit(1)->get()->isNotEmpty();
}
```

Supported by the composite index `(funding_attempt_id, entry_type, provider_reference)` (§22 Migration 3), which also supports `sumRefundedMicroForFundingAttempt()`/`sumDisputeMicroForFundingAttemptAndDispute()`.

---

## 21. Backfill — a safe, mechanically honest migration policy

M6 is frozen; no historical source attribution may be guessed. **Locked:**

- **Every existing wallet's `refundable_paid_available_micro` is backfilled to `0`, unconditionally** — this codebase's current schema has never tracked which portion of a wallet's own single fungible `available_balance_micro` originated from a paid source versus a non-paid one, and reconstructing it would require replaying every historical ledger row under a paid-first rule that did not exist when those rows were written — an exact reconstruction is not possible, and a guessed one is exactly what this correction exists to prevent. **This is a deliberately conservative, safe default: it can only ever under-approximate genuine refundability (denying a refund that would, in fact, have been honest), never over-approximate it (never permitting a cash-out that shouldn't happen).**
- **Every existing `Pending` reservation's `paid_attributable_amount_micro` is backfilled to `0`**, for the identical reason.
- **New provider-paid credit arriving after this migration establishes refundable-paid provenance normally**, from that point forward, via §6's own unmodified formulas.
- **No provider call is used for backfill**, matching the unchanged rule for `provider_charge_reference` (§3).

**Refund headroom is therefore, exactly and always:**

```
min(
    unrefunded amount for the funding attempt,          // providerRefundDelta's own upstream bound
    locked wallet refundable_paid_available_micro,
    locked wallet available_balance_micro
)
```

**never total available balance alone.**

---

## 22. Locked design — exact production allow-list

**30 files: 12 new + 18 modified.**

| # | Path | Status | Content |
|---|---|---|---|
| 1 | `database/migrations/2026_08_29_120001_add_provider_references_to_business_funding_attempts_table.php` | NEW | §3. |
| 2 | `database/migrations/2026_08_29_120002_backfill_provider_payment_intent_reference_for_auto_recharge_attempts.php` | NEW | §21. |
| 3 | `database/migrations/2026_08_29_120003_add_dispute_refund_aggregate_index_to_business_usage_ledger_entries_table.php` | NEW | Composite index `(funding_attempt_id, entry_type, provider_reference)` (§20). |
| 4 | `database/migrations/2026_08_29_120004_add_normalized_outcome_columns_to_payment_provider_events_table.php` | NEW | The eleven columns in §18 (four distinct amount fields, not the prior pair). |
| 5 | `database/migrations/2026_08_29_120005_add_retry_and_recent_outcomes_indexes_to_payment_provider_events_table.php` | NEW | The three branch-specific retry indexes plus `(normalized_recorded_at, id)` (§19). |
| 6 | `database/migrations/2026_08_29_120006_add_refundable_paid_available_micro_to_business_usage_wallets_table.php` | NEW | The new wallet-level counter, default `0` (§6, §21). |
| 7 | `database/migrations/2026_08_29_120007_add_paid_attributable_amount_micro_to_business_usage_reservations_table.php` | NEW | The new per-reservation snapshot, default `0` (§6, §21). |
| 8 | `database/migrations/2026_08_29_120008_add_refundable_paid_delta_micro_to_business_usage_ledger_entries_table.php` | NEW | The new signed, nullable audit field (§12). |
| 9 | `app/Jobs/Usage/RetryStuckPaymentProviderEvents.php` | NEW | §19. |
| 10 | `app/Jobs/Usage/SendChargebackDisputeNotification.php` | NEW | §11. |
| 11 | `app/Notifications/Usage/ChargebackDisputeNotification.php` | NEW | §11. |
| 12 | `app/Library/Usage/ProviderOutcomeResult.php` | NEW | §18, with the corrected, unambiguous field set. |
| 13 | `app/Enums/Usage/BillingStatusTransitionSource.php` | MODIFIED | Adds `ProviderRefundMismatch = 'provider_refund_mismatch'` (§6, §11). |
| 14 | `app/Models/BusinessFundingAttempt.php` | MODIFIED | `$fillable` gains both new reference columns. |
| 15 | `app/Models/PaymentProviderEvent.php` | MODIFIED | `$fillable` gains the eleven new columns. |
| 16 | `app/Models/BusinessUsageWallet.php` | MODIFIED | `$fillable`/`$casts` gain `refundable_paid_available_micro` (integer). |
| 17 | `app/Models/BusinessUsageReservation.php` | MODIFIED | `$fillable`/`$casts` gain `paid_attributable_amount_micro` (integer). |
| 18 | `app/Models/BusinessUsageLedgerEntry.php` | MODIFIED | `$fillable`/`$casts` gain `refundable_paid_delta_micro` (nullable integer). |
| 19 | `app/Repositories/Contracts/BusinessFundingAttemptRepository.php` | MODIFIED | `findByProviderPaymentIntentReference()`, `findByProviderChargeReference()`. |
| 20 | `app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php` | MODIFIED | Implements both. |
| 21 | `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` | MODIFIED | `sumRefundedMicroForFundingAttempt()`, `sumDisputeMicroForFundingAttemptAndDispute()`, `hasOutstandingDisputeExposureForFundingAttempt()`, `findCreditEntryForFundingAttempt()` — unchanged method set from Correction Round 2. |
| 22 | `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php` | MODIFIED | Implements all four. |
| 23 | `app/Repositories/Contracts/PaymentProviderEventRepository.php` | MODIFIED | `markProcessed()`/`markIgnored()` gain `array $attribution = []`; `retryable(int $maxAttempts, int $receivedGraceMinutes, int $limit): Collection` (§19, corrected shape: one `received` query, one `failed` query, one query per eligible stale-processing attempt bucket, two-level fairly interleaved); `recentOutcomes(int $limit = 50): Collection` (§18, clamped both sides). |
| 24 | `app/Repositories/Eloquent/EloquentPaymentProviderEventRepository.php` | MODIFIED | Implements `retryable()`'s index-ordered `received`/`failed` queries, the per-attempt-bucket stale-processing queries (`0..$maxAttempts-1`, each a single equality-plus-one-range, index-sargable query), the `MAX_RETRYABLE_LIMIT`/`$maxAttempts` clamps, and the single private `interleaveRetryBranches()` round-robin fairness helper reused at both the attempt-bucket level and the state-class level (§19); implements the corrected `recentOutcomes()`. |
| 25 | `app/Library/Usage/UsageWalletManager.php` | MODIFIED | **Five existing methods gain the `refundable_paid_available_micro` bookkeeping in §6:** `reserve()` (the `$paidAttributable` deduction and the reservation's own `paid_attributable_amount_micro` snapshot); `commit()` (the committed/unused/overage paid-portion formulas); `release()` (the full-restore formula); `creditFromFunding()` (the `+= $remainder` addition); `issueManualCredit()` (explicitly unmodified in this respect — confirmed, not merely assumed). **Three reversal methods, corrected:** `applyProviderRefund()` (the corrected `refundHeadroomMicro`/`providerRefundDelta` formulas, §6, never a non-zero `debt_delta_micro`, the `ProviderRefundMismatch` suspension); `applyDisputeWithdrawal()` (the `$chargebackPaidPortion` reduction, §8, unchanged debt-creation behavior); `reinstateDisputedFunds()` (the `$reinstatePaidPortion` restoration, §9). |
| 26 | `app/Library/Usage/UsageBillingCheckoutManager.php` | MODIFIED | `minorUnitsToMicro()`/`expectedMicroForMinorUnits()`; `confirmSucceeded()`/`finalizeFundingAttemptState()` widened with the two reference parameters and the defensive assertion (§3); `applyRefundOutcome()`, `applyDisputeChargebackOutcome()`, `applyDisputeReinstatementOutcome()`, each returning the corrected `ProviderOutcomeResult` (§18), computing the corrected `max(0, ...)`-clamped delta (§6), locking the wallet row, calling exactly one `UsageWalletManager` method, calling state recomputation, all inside one outer `DB::transaction()`. |
| 27 | `app/Jobs/Usage/ProcessPaymentProviderEvent.php` | MODIFIED | `handle()`'s own `match` per §2/§16/§17; dual-identifier resolution, currency/shape validation, terminal calls carrying `$attribution` built directly from the returned `ProviderOutcomeResult`'s five fields. |
| 28 | `app/Console/Kernel.php` | MODIFIED | One new line, `$schedule->job(new RetryStuckPaymentProviderEvents())->everyFiveMinutes();`. |
| 29 | `app/Http/Controllers/Admin/PaymentProviderEventController.php` | MODIFIED | `index()` gains `recentOutcomes`. |
| 30 | `resources/views/admin/usage-billing/provider-events/index.blade.php` | MODIFIED | One new `x-card`/`x-table` section, four distinctly labeled amount columns (§18). |

**NOT_REQUIRED, explicitly confirmed, corrected this pass:**

| Path/category | Reason |
|---|---|
| Any file under `app/Http/Controllers/Admin/**`/`resources/views/**` beyond items 29/30 | Existing surfaces are entry-type/state-value generic. |
| `routes/public.php`, `app/Providers/AppServiceProvider.php` | Unchanged reasoning. |
| Any brand-new PHP enum type | Only one new case on one existing enum (item 13). |
| Any new exception class | Every failure path is an existing `markFailed()` string-reason branch or `UsageWalletNotFoundException`. |
| Gateway/DTO boundary, `ReconcileProviderPendingState.php`, `PurgeExpiredWebhookPayloads.php`, `BusinessBillingReceipt.php`/`ensureFundingReceipt()`, `BusinessUsageAddonPurchase`-related methods, `EvaluateBusinessAutoRecharge` (production behavior) | Unchanged reasoning. |
| `config/usage_billing.php` | **Corrected this pass, replacing the prior contradiction:** the scanner's batch limit is a plain class constant; the received-row grace interval is neither a class constant nor a new config key — it reuses the existing `usage_billing.webhook_event.lease_minutes` value (§19). No new config key of any kind is introduced. |
| `app/Repositories/Contracts/BusinessUsageWalletRepository.php`, `BusinessUsageReservationRepository.php` (contracts/Eloquent pairs) | Both already accept arbitrary attribute arrays via their existing `create()`/`update()` methods — the new columns require no new repository method, only new keys in already-existing call sites inside `UsageWalletManager`. |

---

## 23. Preserved invariants

- `committed_spend_this_period_micro`/`reserved_spend_this_period_micro` are never touched by any refund/dispute method.
- `recharged_this_period_micro` is never decremented by `Refund`/`DisputeChargeback`/`CorrectionReversal`.
- `business_usage_ledger_entries` remains append-only.
- Outstanding-debt-denies-reservations remains centrally enforced by `reserve()`'s own unmodified admission check.
- No raw query against a billing table outside its owning repository.
- Debt can never go negative (`reinstateDisputedFunds()`).
- **`Refund` can never create or increase debt** — `debt_delta_micro` is a literal `0` in every branch of §6's own formula.
- **`refundable_paid_available_micro` is always `≥ 0` and always `≤ available_balance_micro`, maintained by construction:** every mutation site either changes both counters by an equal amount (`creditFromFunding()`, a compliant `Refund`), changes `refundable_paid_available_micro` by an amount bounded above by the corresponding `available_balance_micro` change (`reserve()`'s `min(reservedAmountMicro, ...)`, `commit()`'s overage `min(overageFromAvailable, ...)`, `DisputeChargeback`'s `min(chargebackFromAvailable, ...)`), or changes only `available_balance_micro` and never `refundable_paid_available_micro` (`issueManualCredit()`) — never the reverse. The base case (a newly initialized wallet, both `0`) and every subsequent step preserve `refundable_paid_available_micro ≤ available_balance_micro` by induction.
- **Consumed paid credit never becomes refundable again merely because a later, unrelated credit (paid or non-paid) increases `available_balance_micro`** — `refundable_paid_available_micro` is decremented at the moment of consumption (`reserve()`) and only ever restored by the exact, durably-snapshotted amount a specific reservation's own later `release()`/unused-`commit()` returns — never re-inflated by an unrelated credit.
- `EvaluateBusinessAutoRecharge` is never dispatched, and no receipt is ever fabricated, by any code path this design authorizes.

---

## 24. Exact test allow-list

**No existing test file requires modification.**

**12 new files, 172 methods.**

| # | File | Methods |
|---|---|---|
| 1 | `tests/Feature/Usage/ProviderPaymentIdentifierResolutionTest.php` | 12 |
| 2 | `tests/Feature/Usage/ProviderRefundOutcomeTest.php` | 21 |
| 3 | `tests/Feature/Usage/ProviderDisputeOutcomeTest.php` | 21 |
| 4 | `tests/Feature/Usage/DisputeBalanceTransactionValidationTest.php` | 7 |
| 5 | `tests/Feature/Usage/DirectDeliverableProviderOutcomeTest.php` | 11 |
| 6 | `tests/Feature/Usage/UsageWalletManagerReversalTest.php` | 23 |
| 7 | `tests/Feature/Usage/ProviderRefundDisputeSurfaceBoundaryTest.php` | 7 |
| 8 | `tests/Feature/Usage/PaymentProviderEventRetryReclaimTest.php` | 29 |
| 9 | `tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php` | 12 |
| 10 | `tests/Feature/Usage/ProviderRefundDisputeConcurrencyTest.php` | 3 |
| 11 | `tests/Feature/Usage/SendChargebackDisputeNotificationTest.php` | 12 |
| 12 | `tests/Feature/Usage/RefundablePaidAvailableAccountingTest.php` | 14 |

**Total: 172 methods across 12 files.**

### `ProviderPaymentIdentifierResolutionTest.php` (12) — unchanged from Correction Round 2, proves §3

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

### `ProviderRefundOutcomeTest.php` (21) — proves §6, §12, §13, §17; +1 method this pass for the strictly-lower out-of-order case (Blocker 2)

1. `test_a_refund_within_available_balance_debits_available_only_and_creates_no_debt`
2. `test_a_full_refund_after_partial_usage_consumption_removes_only_the_remaining_available_balance_creates_no_debt_records_policy_excess_and_suspends_billing`
3. `test_a_refund_when_available_balance_is_zero_creates_no_debt_or_wallet_debit_event_but_records_the_outcome_and_policy_excess`
4. `test_no_refund_ledger_row_can_ever_have_a_non_zero_debt_delta_micro`
5. `test_a_second_partial_refund_event_applies_only_the_incremental_delta_since_the_first`
6. `test_an_equal_cumulative_replayed_refund_event_produces_zero_additional_mutation`
7. `test_a_strictly_lower_out_of_order_cumulative_refund_event_produces_a_clamped_zero_delta_never_a_negative_one`
8. `test_a_refund_is_bounded_and_never_exceeds_the_original_funding_attempts_expected_amount`
9. `test_a_wallet_credit_addon_purchase_refund_follows_the_identical_refundable_paid_cap`
10. `test_a_refund_event_missing_both_payment_intent_and_charge_fails_with_no_mutation`
11. `test_a_refund_event_for_an_unresolvable_reference_fails_with_no_mutation`
12. `test_a_refund_event_with_a_mismatched_currency_fails_with_no_mutation`
13. `test_refund_progress_is_computed_solely_from_refund_entries_and_is_unaffected_by_a_dispute_on_the_same_attempt`
14. `test_a_refund_never_affects_an_unrelated_businesss_wallet`
15. `test_refund_reason_is_never_null_and_matches_the_deterministic_template`
16. `test_consumed_usage_and_committed_spend_history_are_never_reversed_by_a_refund`
17. `test_manual_credit_and_promotional_credit_entries_are_never_treated_as_refundable_provider_funding`
18. `test_a_policy_excess_refund_event_is_marked_terminally_processed_rather_than_retried`
19. `test_a_replayed_policy_excess_refund_event_creates_no_second_suspension_transition`
20. `test_a_policy_excess_refund_never_dispatches_evaluate_business_auto_recharge_send_receipt_notification_or_the_dedicated_chargeback_notification`
21. `test_a_full_refund_after_a_policy_excess_partial_still_recomputes_the_attempt_to_refunded_from_gross_progress`

### `ProviderDisputeOutcomeTest.php` (21) — unchanged from Correction Round 2, proves §8, §9, §11, §13, §20

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

### `DisputeBalanceTransactionValidationTest.php` (7) — unchanged, proves §5, §9

1. `test_a_dispute_with_the_documented_single_withdrawal_transaction_is_processed`
2. `test_a_dispute_carrying_the_documented_withdrawal_then_reinstatement_two_transaction_shape_processes_both_correctly`
3. `test_more_than_two_balance_transactions_fails_closed_as_malformed`
4. `test_two_balance_transactions_of_the_same_sign_fail_closed_as_malformed`
5. `test_duplicate_balance_transaction_ids_in_the_array_fail_closed_as_malformed`
6. `test_a_reinstatement_resolves_the_original_chargeback_via_the_withdrawal_transactions_own_correlation_key_and_sets_the_exact_reversed_entry_id`
7. `test_a_reinstatement_with_no_matching_withdrawal_present_in_the_array_fails_closed_with_zero_mutation`

### `DirectDeliverableProviderOutcomeTest.php` (11) — proves §4, §10, §17; method 6 renamed this pass (malformed "ever"→"never")

1. `test_a_partial_direct_deliverable_refund_leaves_the_attempt_succeeded_with_zero_wallet_deltas_and_a_recorded_outcome_row`
2. `test_a_full_direct_deliverable_refund_transitions_the_attempt_to_refunded_with_zero_wallet_deltas`
3. `test_a_replayed_direct_deliverable_refund_event_is_a_no_op`
4. `test_a_direct_deliverable_dispute_withdrawal_writes_a_zero_delta_dispute_chargeback_and_suspends_billing`
5. `test_a_direct_deliverable_dispute_reinstatement_writes_a_zero_delta_correction_reversal_and_never_credits_the_wallet`
6. `test_zero_wallet_balance_events_are_never_dispatched_for_any_direct_deliverable_outcome_row`
7. `test_a_direct_deliverable_dispute_withdrawal_dispatches_the_chargeback_notification_decision_despite_zero_wallet_deltas`
8. `test_a_direct_deliverable_refund_is_never_classified_as_a_wallet_credit_over_refund`
9. `test_clearing_the_final_direct_deliverable_dispute_exposure_returns_the_attempt_to_refunded_or_succeeded_per_refund_progress`
10. `test_two_different_provider_event_ids_reporting_the_same_direct_deliverable_outcome_apply_it_exactly_once`
11. `test_a_refunded_direct_deliverable_addon_purchase_remains_historically_completed`

### `UsageWalletManagerReversalTest.php` (23) — unchanged in count, methods 1–2/8 corrected in substance this pass to reflect the refundable-paid cap

1. `test_apply_provider_refund_debits_available_balance_only_when_sufficient_and_within_the_refundable_paid_cap`
2. `test_apply_provider_refund_debits_only_the_lesser_of_available_balance_and_refundable_paid_available_and_records_policy_excess_for_the_remainder`
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

### `ProviderRefundDisputeSurfaceBoundaryTest.php` (7) — unchanged, proves §17, §22

1. `test_reversal_and_dispute_manager_methods_are_never_called_from_a_controller`
2. `test_process_payment_provider_event_never_calls_a_charge_originating_manager_method`
3. `test_no_new_production_file_contains_a_raw_billing_table_query_outside_the_two_eloquent_repositories`
4. `test_apply_outcome_orchestration_methods_are_never_called_outside_process_payment_provider_event`
5. `test_no_new_admin_controller_action_or_route_is_introduced_beyond_the_widened_provider_events_index`
6. `test_none_of_the_three_reversal_methods_ever_references_evaluate_business_auto_recharge`
7. `test_none_of_the_three_reversal_methods_ever_references_send_receipt_notification_or_attach_funding_receipt`

### `PaymentProviderEventRetryReclaimTest.php` (29) — proves §19; +2 methods in the first exceptional pass for index-presence and sparse/large-table behavior (Blocker 4); +9 methods in the second pass for the fair-interleave/index-ordering correction (Defects 1–2); +6 methods this pass for the per-attempt-bucket stale-processing correction, method 13 strengthened in place

1. `test_a_failed_event_below_max_attempts_is_redispatched_by_the_scanner`
2. `test_a_stale_processing_event_past_its_lease_is_reclaimed_by_the_scanner`
3. `test_an_event_at_max_attempts_is_never_redispatched_by_the_scanner`
4. `test_an_exhausted_event_becomes_administrator_visible_in_the_existing_exhausted_events_queue`
5. `test_processed_ignored_and_disposed_events_are_never_redispatched_by_the_scanner`
6. `test_the_scanner_batch_is_bounded_by_its_own_limit_across_the_fairly_interleaved_branches`
7. `test_the_scanner_performs_no_accounting_mutation_itself`
8. `test_a_received_event_older_than_the_grace_interval_is_redispatched_by_the_scanner`
9. `test_a_freshly_received_event_is_not_redispatched_before_the_grace_interval_elapses`
10. `test_the_persistence_before_dispatch_failure_leaves_a_received_row_that_only_the_scanner_recovers`
11. `test_a_redelivered_webhook_for_an_already_received_event_returns_200_without_a_second_row_and_the_original_remains_scanner_recoverable`
12. `test_a_scanner_redispatch_racing_the_original_dispatch_for_the_same_received_event_applies_the_outcome_exactly_once`
13. `test_each_retry_query_including_every_stale_processing_attempt_bucket_query_is_supported_by_its_own_dedicated_index_and_ordered_to_match_it`
14. `test_the_scanner_remains_bounded_and_correctly_index_ordered_when_the_table_contains_a_large_number_of_terminal_rows_alongside_sparse_matching_candidates`
15. `test_when_all_three_branches_have_at_least_batch_limit_candidates_the_selected_batch_contains_all_three_states_and_never_exceeds_batch_limit`
16. `test_a_sustained_received_backlog_exceeding_the_limit_never_starves_the_failed_or_stale_processing_branches`
17. `test_interleaving_selects_from_both_populated_branches_when_only_received_and_failed_have_candidates`
18. `test_interleaving_selects_from_both_populated_branches_when_only_received_and_stale_processing_have_candidates`
19. `test_interleaving_selects_from_both_populated_branches_when_only_failed_and_stale_processing_have_candidates`
20. `test_only_the_received_branch_populated_returns_exactly_its_own_candidates_up_to_the_limit`
21. `test_only_the_failed_branch_populated_returns_exactly_its_own_candidates_up_to_the_limit`
22. `test_only_the_stale_processing_branch_populated_returns_exactly_its_own_candidates_up_to_the_limit`
23. `test_retryables_accepted_limit_is_clamped_to_the_locked_maximum_regardless_of_the_requested_value`
24. `test_a_large_number_of_non_expired_processing_rows_at_a_lower_attempt_count_never_blocks_recovery_of_an_expired_row_at_a_higher_attempt_count`
25. `test_stale_processing_candidates_are_fairly_interleaved_across_attempt_buckets_when_every_eligible_bucket_has_at_least_limit_candidates`
26. `test_a_saturated_lower_attempt_bucket_never_starves_a_sparsely_populated_higher_attempt_bucket`
27. `test_outer_state_class_fairness_across_received_failed_and_stale_processing_remains_intact_after_the_two_level_stale_processing_merge`
28. `test_the_number_of_stale_processing_bucket_queries_never_exceeds_the_configured_max_attempts_value`
29. `test_a_non_positive_configured_max_attempts_value_issues_zero_stale_processing_bucket_queries_and_mutates_nothing`

### `PaymentProviderEventDurableAuditTest.php` (12) — proves §18; +2 methods this pass, methods renamed/split for the four-field audit semantics (Blocker 3)

1. `test_a_processed_refund_outcome_is_attributed_with_business_and_funding_attempt_identity`
2. `test_an_ignored_dispute_created_event_is_attributed_with_normalized_status_and_reason`
3. `test_a_direct_deliverable_addon_refund_is_durably_audited_with_the_actual_outcome_delta_despite_a_zero_wallet_delta`
4. `test_normalized_attribution_survives_payload_purge`
5. `test_the_provider_events_admin_surface_lists_recent_normalized_outcomes_ordered_by_normalized_recorded_at_then_id`
6. `test_recent_outcomes_clamps_its_accepted_limit_to_the_locked_maximum_and_minimum_regardless_of_the_requested_value`
7. `test_a_refund_object_event_is_recorded_as_audit_only_with_no_wallet_mutation`
8. `test_the_administrator_audit_renders_reported_outcome_delta_wallet_delta_and_policy_excess_amounts_exactly_for_a_policy_excess_refund`
9. `test_a_compliant_partial_refund_records_reported_100_outcome_delta_40_and_wallet_delta_40`
10. `test_a_replayed_refund_records_reported_100_with_outcome_delta_0_and_wallet_delta_0`
11. `test_a_direct_deliverable_refund_records_reported_100_outcome_delta_100_and_wallet_delta_0`
12. `test_a_policy_excess_refund_records_reported_100_outcome_delta_100_wallet_delta_60_and_policy_excess_40`

### `ProviderRefundDisputeConcurrencyTest.php` (3) — unchanged, proves §11, using the established subprocess/causal-barrier convention

1. `test_two_different_provider_event_ids_reporting_the_same_cumulative_refund_amount_debit_the_wallet_exactly_once`
2. `test_two_different_provider_event_ids_reporting_the_same_balance_transaction_apply_the_dispute_chargeback_exactly_once`
3. `test_two_different_provider_event_ids_reporting_the_same_policy_excess_refund_apply_it_exactly_once_with_no_duplicate_suspension`

### `SendChargebackDisputeNotificationTest.php` (12) — proves §11; every method retains its own coverage, renamed this pass for the honest at-most-one/best-effort guarantee (advisory)

1. `test_the_dispatch_decision_is_made_only_after_the_outer_transaction_commits`
2. `test_the_dispatch_decision_is_made_at_most_once_for_the_correlation_key_winner`
3. `test_no_dispatch_decision_is_made_for_a_replayed_withdrawal_event`
4. `test_no_dispatch_decision_is_made_for_the_correlation_key_loser_of_a_concurrent_write`
5. `test_no_dispatch_decision_is_made_when_the_outer_transaction_rolls_back`
6. `test_a_direct_deliverable_withdrawal_still_produces_a_dispatch_decision_despite_zero_wallet_deltas`
7. `test_no_dispatch_decision_is_made_for_a_reinstatement`
8. `test_no_dispatch_decision_is_made_for_a_policy_excess_refund_outcome`
9. `test_delivery_is_skipped_when_no_billing_contact_is_configured`
10. `test_delivery_is_skipped_when_the_contact_has_opted_out`
11. `test_delivery_is_skipped_when_the_resolved_email_is_blank`
12. `test_the_notification_content_states_the_exact_dispute_id_amount_currency_and_that_billing_is_suspended`

### `RefundablePaidAvailableAccountingTest.php` (14) — NEW FILE, proves §6, §21, §23; every method number matches the corresponding numbered requirement from this correction's own Blocker 1

1. `test_paid_100_consumed_100_then_granted_promotional_credit_100_leaves_zero_refundable_headroom`
2. `test_paid_100_consumed_100_then_granted_manual_credit_100_leaves_zero_refundable_headroom`
3. `test_a_later_unrelated_paid_top_up_never_lets_the_system_refund_more_than_the_globally_tracked_unconsumed_paid_amount`
4. `test_promotional_or_manual_credit_alone_can_never_be_refunded_for_cash`
5. `test_reserve_removes_the_exact_paid_attributable_amount_from_refundability`
6. `test_release_restores_the_exact_paid_attributable_amount`
7. `test_a_partial_commit_restores_only_the_unused_paid_allocation`
8. `test_overage_consumes_refundable_paid_available_under_the_same_allocation_rule`
9. `test_refund_decrements_refundable_paid_available_and_never_creates_debt`
10. `test_dispute_withdrawal_and_reinstatement_update_refundable_paid_provenance_without_making_consumed_credit_refundable`
11. `test_direct_deliverable_outcomes_never_touch_refundable_paid_available`
12. `test_historically_ambiguous_balances_fail_closed_with_zero_backfilled_refundable_paid_available`
13. `test_forced_concurrent_refund_attempts_cannot_over_refund_the_refundable_paid_counter`
14. `test_refundable_paid_available_remains_non_negative_and_never_exceeds_available_balance`

Method 13 uses the established subprocess/causal-barrier convention (`ConcurrentTopUpConcurrencyTest.php`'s own infrastructure) — a replay test alone is not proof of concurrent processing.

---

## 25. Schema/migration decisions — exact DDL

**Migrations 1–3:** unchanged from Correction Round 2 (§3, §20).

**Migration 4 (corrected — four distinct amount columns, not two):**

```php
Schema::table('payment_provider_events', function (Blueprint $table) {
    $table->unsignedBigInteger('business_id')->nullable()->after('provider_object_id');
    $table->unsignedBigInteger('funding_attempt_id')->nullable()->after('business_id');
    $table->string('normalized_outcome', 32)->nullable()->after('funding_attempt_id');
    $table->string('normalized_status', 32)->nullable()->after('normalized_outcome');
    $table->bigInteger('normalized_reported_amount_micro')->nullable()->after('normalized_status');
    $table->bigInteger('normalized_outcome_delta_micro')->nullable()->after('normalized_reported_amount_micro');
    $table->bigInteger('normalized_wallet_delta_micro')->nullable()->after('normalized_outcome_delta_micro');
    $table->bigInteger('normalized_policy_excess_micro')->nullable()->after('normalized_wallet_delta_micro');
    $table->string('normalized_currency_code', 3)->nullable()->after('normalized_policy_excess_micro');
    $table->string('normalized_reason', 64)->nullable()->after('normalized_currency_code');
    $table->timestamp('normalized_recorded_at')->nullable()->after('normalized_reason');
});
```

**Migration 5 (corrected — three retry-branch indexes, not one):**

```php
Schema::table('payment_provider_events', function (Blueprint $table) {
    $table->index(['state', 'received_at', 'id'], 'ppe_state_received_at_id_index');
    $table->index(['state', 'attempts', 'id'], 'ppe_state_attempts_id_index');
    $table->index(['state', 'attempts', 'lease_expires_at', 'id'], 'ppe_state_attempts_lease_expires_id_index');
    $table->index(['normalized_recorded_at', 'id'], 'ppe_normalized_recorded_at_id_index');
});
```

**Migration 6 (new):**

```php
Schema::table('business_usage_wallets', function (Blueprint $table) {
    $table->bigInteger('refundable_paid_available_micro')->default(0)->after('available_balance_micro');
});
```

**Migration 7 (new):**

```php
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->bigInteger('paid_attributable_amount_micro')->default(0)->after('reserved_amount_micro');
});
```

**Migration 8 (new):**

```php
Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
    $table->bigInteger('refundable_paid_delta_micro')->nullable()->after('debt_delta_micro');
});
```

No `FOREIGN KEY` on `business_id`/`funding_attempt_id` (Migration 4). No other schema/migration change is authorized or required.

---

## 26. Complete transaction/lock/crash-recovery sequence

1. The event's signature is verified and persisted; dual-reference resolution runs before any lock is acquired.
2. The wallet row is locked first (`findForUpdateByBusinessId()`), inside one `DB::transaction()`.
3. `$walletBacked` is determined once. For a refund, `refundHeadroomMicro`/`walletDebitMicro`/`policyExcessMicro` are computed from the locked wallet's own current `available_balance_micro` **and** `refundable_paid_available_micro` together (§6).
4. The unique outcome row is inserted; the wallet `UPDATE` (all three balance-shaped columns where wallet-backed, including `lowBalanceMarkerUpdate()`, and, for a `DisputeChargeback` or a policy-excess `Refund`, the redundant-suspend-guarded suspension) applies.
5. The funding attempt's own row is locked a second time, inside the same outer transaction, immediately before state recomputation writes.
6. The outer transaction commits.
7. **After** commit: the low-balance notification (if requested) and the dedicated chargeback/dispute notification (if a genuinely new `DisputeChargeback` was the row just inserted, never for any refund outcome) are dispatched — a dispatch *decision*, at most one per genuinely new outcome, best-effort external delivery under this codebase's existing one-attempt notification convention; never before commit, never at all on rollback.
8. The provider event is marked `processed`/`ignored`, carrying `$attribution` built directly from the returned `ProviderOutcomeResult`'s five fields.

**Crash between steps 6 and 8:** the outcome row and any wallet/refundable-paid mutation have already durably committed. On retry, processing re-enters from the top; the correlation-key idempotency (§11) finds the outcome already applied and computes a delta of `0` (via the corrected `max(0, ...)` clamp, §6); no duplicate financial, refundable-paid, or state effect occurs; no notification dispatch decision fires twice; step 8 completes correctly. **No notification dispatch decision is ever made on rollback.**

---

## 27. Guarantee-by-guarantee mapping

1. **A refund/dispute event resolves to exactly one Business, never ambiguously.** §3; `ProviderPaymentIdentifierResolutionTest` methods 6–10.
2. **A provider-confirmed Refund can never create or increase wallet debt, and can never exceed unconsumed, provider-paid credit — used usage and promotional/manual credit can never be laundered into cash via a later, unrelated credit.** §6, §12, §23; `ProviderRefundOutcomeTest` methods 1–4, 9, 16, 17; `RefundablePaidAvailableAccountingTest` in full.
3. **`DisputeChargeback` may still create debt — unaffected by the refund policy.** §8, §10; `ProviderDisputeOutcomeTest` method 3; `UsageWalletManagerReversalTest` method 12.
4. **An out-of-order or replayed cumulative refund report, whether equal to or strictly lower than already-recorded progress, is an exact, non-negative no-op — never a negative delta.** §6; `ProviderRefundOutcomeTest` methods 6–7.
5. **Dispute mutation is keyed to the actual, signed, validated balance transaction.** §5, §8; `ProviderDisputeOutcomeTest` methods 1–2; `DisputeBalanceTransactionValidationTest` methods 1–5.
6. **A reinstatement never produces negative debt, is bounded to the specific dispute's own withdrawn amount, and mechanically resolves its own lineage.** §9; `ProviderDisputeOutcomeTest` methods 7–11; `DisputeBalanceTransactionValidationTest` methods 6–7.
7. **Every genuinely new outcome — wallet-backed or zero-delta — writes an outcome row, advances its accumulator, is audited, and drives state recomputation identically.** §4, §10; `DirectDeliverableProviderOutcomeTest` in full.
8. **Funding-attempt state reflects every outstanding dispute and gross refund progress.** §13, §20; `ProviderDisputeOutcomeTest` methods 13–15; `ProviderRefundOutcomeTest` method 21.
9. **Duplicate delivery, a stranded `received` row, and genuine concurrency never apply the same financial, refundable-paid, or state effect twice; a stranded event is actually recovered by a genuinely work-bounded, index-navigable scanner; and no retry-eligible state class or processing-attempt bucket can be starved by another, regardless of relative backlog size.** §19, §26; `PaymentProviderEventRetryReclaimTest` in full (methods 13–14 for index-ordering, 15–23 for state-class fair-interleave/no-starvation, 24–29 for the per-attempt-bucket correction and its own bucket-level fairness/no-starvation/bound guarantees); `ProviderRefundDisputeConcurrencyTest` in full; `RefundablePaidAvailableAccountingTest` method 13.
10. **No fabricated receipt, and no automatic auto-recharge origination, from any refund/dispute code path.** §17; `UsageWalletManagerReversalTest` methods 7, 17, 18, 23; `ProviderRefundDisputeSurfaceBoundaryTest` methods 6–7.
11. **The low-balance marker participates correctly, and only, in wallet-backed mutations.** §10; `UsageWalletManagerReversalTest` methods 6, 22.
12. **Every outcome is durably recorded with four unambiguous, distinctly meaningful amount fields, remaining administrator-visible after payload purge.** §18; `PaymentProviderEventDurableAuditTest` in full.
13. **The dedicated chargeback notification's dispatch decision is made at most once, chargeback-only, with an honest, non-overclaiming external-delivery guarantee.** §11; `SendChargebackDisputeNotificationTest` in full; `UsageWalletManagerReversalTest` method 10.
14. **No new admin surface beyond the one widened read; no provider-side origination; no entitlement/deliverable rollback; `ManualCredit`/`PromotionalCredit` never refundable, directly or indirectly.** §4, §17; `ProviderRefundDisputeSurfaceBoundaryTest` in full; `ProviderRefundOutcomeTest` method 17; `DirectDeliverableProviderOutcomeTest` method 11; `RefundablePaidAvailableAccountingTest` methods 1–4.
15. **Every wallet-mutating write is authority-correct, lock-ordered, and every mandatory-reason row is non-blank.** §26, §12; `UsageWalletManagerReversalTest`; `ProviderRefundOutcomeTest` method 15.

---

## 28. Bounded reads

Every new read is either genuinely `LIMIT`-and-index-bounded (`retryable()`'s own queries — one `received`, one `failed`, and one per eligible stale-processing attempt bucket, each individually indexed, individually limited, carrying exactly one navigable range predicate after its index's equality prefix, and ordered to match its own index's exact column sequence — total read bound `(2 + $maxAttempts) × $limit`, dispatch bound exactly `$limit` after two-level fair interleaving, §19; `recentOutcomes()`, `(normalized_recorded_at, id)`) or scoped to `(funding_attempt_id[, provider_reference])` and supported by the composite index (§20). No claim of "bounded" work stands on a `LIMIT` clause alone without a supporting index for every predicate — and every `ORDER BY` clause — that query actually uses, and no query in this document carries more than one unresolved range/filter predicate after its index's equality prefix.

---

## 29. Regression commands

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
12. `php artisan test tests/Feature/Usage/RefundablePaidAvailableAccountingTest.php`
13. `php artisan test tests/Feature/Usage tests/Unit/Usage` (complete Usage-domain suite — this is the regression backstop for the five modified core methods, §22 item 25)
14. One complete `php artisan test --stop-on-failure` run (full repository suite)
15. `git diff --check`

No live-provider-hitting command is ever part of this suite.

---

## 30. Coverage matrix

| Question | Answered in |
|---|---|
| Which provider refund/dispute webhook/event types enter the system? | §2 |
| How is each event authenticated, normalized, deduplicated, retained, disposed? | §11, §19 |
| How is the affected Business/attempt identified without ambiguity? | §3 |
| Can a Refund ever create debt, or exceed unconsumed paid credit? | §6, §23 — never, on both counts |
| Can promotional/manual credit, or a later unrelated top-up, ever make already-consumed credit refundable? | §6, §21, §23 — never |
| Which exact ledger entry types are produced; row shapes | §12 |
| How are amounts bounded, including against out-of-order delivery? | §6 |
| What idempotency boundary applies? | §11 |
| Insufficient available balance on a refund vs. a dispute? | §6 (capped, never debt) vs. §8/§9 (may create/reverse debt) |
| Domain events/jobs/notifications/audit records | §11, §18 |
| Outcomes requiring no mutation but still requiring durable audit | §16, §17, §18 |
| Retry/reconciliation/locking/transaction/crash-recovery boundaries, genuinely index-bounded | §19, §26, §28 |
| Which existing admin surface is reused? | §18 — widened, not duplicated |
| What intentionally remains out of scope? | §17 |
| Multi-dispute / funding-attempt state correctness | §13, §20 |
| Direct-deliverable outcome persistence, idempotency, state | §4, §10 |
| Dispute balance-transaction cardinality and reversal lineage | §5, §9 |
| Low-balance marker / auto-recharge / receipt boundaries | §10, §17 |
| The chargeback/dispute notification — honest guarantee | §11 |
| Durable audit value semantics — four distinct fields | §18 |
| Backfill policy for historical accounts | §21 |
| Reserve/commit/release/funding-credit exact formulas for the new counter | §6 |

---

## 31. Validation performed before commit

- Full document read back for internal consistency.
- Searched for `available_balance_micro` used as the sole refund cap — every remaining occurrence is paired with `refundable_paid_available_micro` in a `min()`, never alone.
- Searched for "No credit-lot or source-level attribution" — the phrase is retained only in its correctly-scoped form (§6: no attribution *finer than* the single paid/non-paid wallet-level distinction), never as a justification for capping against total available balance alone.
- Searched for `providerRefundDelta` without a `max(0, ...)` wrapper — none remains; every definition and every reference uses the corrected clamp.
- Searched for `normalized_applied_amount_micro` — no occurrence remains; replaced everywhere by the four-field split (`reported`/`outcome_delta`/`wallet_delta`/`policy_excess`).
- Searched for "exact"/"exactly-once" email-delivery claims — none remain; every guarantee and test description states "at most one automatic dispatch decision," "best-effort delivery."
- Searched for `(state, attempts)` claimed as the sole retry index — no occurrence remains; §19/§25 state the three-branch index design.
- Searched for the prior 24-path/11-file/138-method counts — no occurrence remains as a live claim.
- Searched for `"...are ever dispatched"` — corrected to `"...are never dispatched"` (§24, `DirectDeliverableProviderOutcomeTest` method 6).
- Confirmed every new wallet/reservation/ledger provenance field (`refundable_paid_available_micro`, `paid_attributable_amount_micro`, `refundable_paid_delta_micro`) and every mutation site that writes it (`reserve()`, `commit()`, `release()`, `creditFromFunding()`, `issueManualCredit()` — explicitly unmodified — `applyProviderRefund()`, `applyDisputeWithdrawal()`, `reinstateDisputedFunds()`) is named in §22 item 25 and tested in `RefundablePaidAvailableAccountingTest`.

**Second exceptional post-review correction (this pass) — additional validation:**

- Searched for `concat($failed)` and `concat($staleProcessing)` — no occurrence remains; `retryable()` now returns `$this->interleaveRetryBranches(...)`, a round-robin merge, never a fixed concatenation (§19).
- Searched every branch-level `orderBy('id')` for a missing preceding indexed ordering field — none remains; each branch now states its full, index-matching `ORDER BY` sequence (`received_at, id`; `attempts, id`; `attempts, lease_expires_at, id`), and no remaining claim anywhere in the document states or implies `ORDER BY id` alone is index-supported.
- Confirmed the per-branch database-read bound (`$limit` per branch, `3 × $limit` total) and the dispatch bound (exactly `$limit`, matching the scanner's `BATCH_LIMIT`) are both explicitly stated in §19 and §28.
- Confirmed `retryable()`'s accepted `$limit` is clamped (`max(1, min($limit, self::MAX_RETRYABLE_LIMIT))`) before any query executes (§19), and that this clamp is independently tested (`PaymentProviderEventRetryReclaimTest` method 23).
- Confirmed production/test path membership is unchanged from the prior pass except where mechanically required: no new production file — items 23–24 in §22 are updated in description only (`retryable()`'s existing contract/implementation now carries the corrected query/interleave shape; no new method signature, no new file); the test allow-list gains 9 methods, all within the existing `PaymentProviderEventRetryReclaimTest.php` file (14 → 23), with no new test file.
- Every production/test path recounted mechanically: **30 production paths (12 new + 18 modified) — unchanged from the prior pass**, **166 test methods across 12 files (157 → 166, +9, all within `PaymentProviderEventRetryReclaimTest.php`)**.
- `git diff --check` — clean.
- `git diff --name-only origin/main...HEAD` — exactly this one file.
- Confirmed no product, test, schema, config, route, or RFC-source file changed; `docs/automation/AI-AUTONOMY-STATE.json` untouched.
- Confirmed the now-approved financial design (§6–§18: `refundable_paid_available_micro`/paid-first allocation, reservation paid attribution, no-refund-debt, promotional/manual-credit non-refundability, direct-deliverable behavior, the four audit amount fields, chargeback notification policy, transaction/locking design) is untouched by this pass — every edit in this correction is confined to §19 and its dependent references in §22, §24, §26, §28, and this section.

**Third exceptional post-review correction (this pass) — additional validation:**

- Re-derived the stale-processing query directly from current, already-existing production code, not invented: confirmed `usage_billing.webhook_event.max_attempts` (default `5`, `USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS`-overridable) in `config/usage_billing.php`, and confirmed it is already read as a plain `(int)` and used as `$maxAttempts` in `App\Jobs\Usage\ProcessPaymentProviderEvent::handle()` and twice in `App\Http\Controllers\Admin\PaymentProviderEventController`; confirmed the `attempts` column's existing eligibility semantics (`attempts < $maxAttempts`) directly from `EloquentPaymentProviderEventRepository::claim()`/`exhausted()`. No new or invented configuration ceiling was required — `$maxAttempts` itself is the safe, finite, already-existing bound this correction needed; no blocker is reported.
- Searched for any remaining query in §19 carrying two unresolved range/filter predicates after its index's equality prefix — none remains; the stale-processing branch's single `attempts < $maxAttempts AND lease_expires_at < now()` query is fully replaced by `$maxAttempts` per-bucket queries, each with exactly one range predicate (`lease_expires_at < now()`) after an equality prefix on `state` and `attempts`.
- Searched for `attempts`, `'<'`, `$maxAttempts` co-occurring in a stale-processing/`processing`-state query context — the only remaining `attempts < $maxAttempts` range predicate is in the `failed` branch, which has no second range/filter predicate after it and was never defective.
- Searched for "genuinely small subset in a healthy system" and any other claim that scan cost for the stale-processing branch is bounded without a supporting single-range predicate — the phrase is removed; §19 explicitly states this assumption is withdrawn.
- Searched for the prior `3 × $limit` total-read-bound claim — no occurrence remains as a live claim; every reference now states `(2 + $maxAttempts) × $limit`.
- Confirmed the two-level interleave reuses the single existing `interleaveRetryBranches()` helper unmodified at both levels — no second merge implementation, no new production method beyond the corrected `retryable()` body itself; §22 items 23–24 updated in description only, no new production file.
- Confirmed §25's migration DDL requires no change — the same three indexes, unmodified, support both the withdrawn and the corrected query shapes; only the query's own predicate/ordering shape changed, never the index definitions.
- Every production/test path recounted mechanically: **30 production paths (12 new + 18 modified) — unchanged**, **172 test methods across 12 files (166 → 172, +6, all within `PaymentProviderEventRetryReclaimTest.php`, now 29 methods)**.
- `git diff --check` — clean.
- `git diff --name-only origin/main...HEAD` — exactly this one file.
- Confirmed no product, test, schema, config, route, or RFC-source file changed; `docs/automation/AI-AUTONOMY-STATE.json` untouched.
- Confirmed the approved financial design (§6–§18) remains untouched by this pass, and that dispute accounting and chargeback notification policy (§8–§11) are likewise untouched — every edit in this correction is confined to §19 and its dependent references in §22, §24, §26, §28, and this section.
