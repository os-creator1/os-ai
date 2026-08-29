# RFC-005 Remediation #6 — Provider Refund/Dispute Outcome Handling Contract

## 0. Governance

- `maximum_correction_rounds`: 2.
- `human_only_merge`: true — this document, and any implementation branch built from it, is merged only by a human.
- **M6 remains frozen.** Nothing in this document authorizes any M6 work (conformance, deployment, tag).
- No tag is authorized by this document.
- No deployment, live Stripe action, refund, dispute simulation against production, rate activation, meter activation, or pilot activation is authorized by this document or by authoring it.
- `docs/automation/AI-AUTONOMY-STATE.json` is untouched by this document and must remain untouched by any commit on this branch.
- This remediation is sequenced **before** remediation #7 (RFC-005 §35 test-coverage completion).
- **Authoring only.** This document locks a design. Implementing it is a separately, explicitly authorized future phase — this contract does not itself grant implementation authority, and no product or test code accompanies it.

**Base SHA (authoritative, confirmed via `git fetch origin && git rev-parse origin/main` before branching):** `a8d2a0f9c03f8295262a9ec7b36374b4b0437294` — PR #148's merge commit (RFC-005 Admin Usage Billing Surface Implementation).

**Branch:** `chore/rfc-005-provider-refund-dispute-outcome-handling-contract`, created via `git worktree add` from the exact SHA above.

---

## 1. Required reading — confirmed read this pass, findings below are mechanically re-derived, not carried over

- `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` in full, with particular attention to §12 (ledger invariants), §13 (entry types and the twelve-row delta table, including `Refund`/`DisputeChargeback`/`UsageChargeReversal`/`CorrectionReversal`), §17 (billing contact and instruments, provider-customer identity), §20/§21 (Stripe boundary, webhook verification/claim/disposition — the exact algorithm §14 of the M3 contract implements verbatim), §23 (refunds/disputes/chargebacks/receipts — the RFC's own thin section on this exact remediation's subject), §24 (authorization table — no row grants a new refund/dispute admin capability), §25–§30 (schema/enums/managers/jobs/events), §29 (existing `BusinessWalletDebited`/`BusinessWalletDebtIncurred`/`BusinessWalletCredited`/`BusinessWalletDebtCleared`/`BusinessWalletBillingStatusChanged` events).
- Every merged RFC-005 milestone/correction contract whose exclusions mention this remediation's subject, found via `grep -il "refund\|dispute\|chargeback\|reversal" docs/automation/RFC-005*.md` — exactly ten files matched: `RFC-005-M1-CONTRACT.md`, `RFC-005-M2-CONTRACT.md`, `RFC-005-M3-CONTRACT.md`, `RFC-005-M4-CONTRACT.md`, `RFC-005-M4-CORRECTION-1.md`, `RFC-005-M4-CORRECTION-2.md`, `RFC-005-DESIGN-CONTRACT.md`, `RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md`, `RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`, `RFC-005-ADMIN-USAGE-BILLING-SURFACE-CONTRACT.md`. The load-bearing exclusions are quoted in §2 below.
- The newly merged `RFC-005-ADMIN-USAGE-BILLING-SURFACE-CONTRACT.md` and its implementation (`app/Http/Controllers/Admin/UsageBillingController.php`, `resources/views/admin/usage-billing/**`, the two correction rounds already applied on top of it) — confirmed, by direct read, that its ledger listing, margin aggregate, funding-attempt list, and billing-status history are all entry-type/state-value-generic and require zero modification to display anything this remediation writes (§9).
- Current provider-event ingestion/disposition code: `app/Http/Controllers/StripeWebhookController.php`, `app/Jobs/Usage/ProcessPaymentProviderEvent.php`, `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php`, `app/Models/PaymentProviderEvent.php`, `app/Repositories/{Contracts,Eloquent}/*PaymentProviderEventRepository.php`, `app/Http/Controllers/Admin/PaymentProviderEventController.php`, `app/Enums/Usage/ProviderEventState.php`.
- Funding-attempt/checkout/wallet/ledger/receipt/reconciliation/job/event/repository code: `app/Library/Usage/UsageBillingCheckoutManager.php` (full file, 2,300+ lines), `app/Library/Usage/UsageWalletManager.php` (full file), `app/Models/BusinessFundingAttempt.php`, `app/Models/BusinessFundingAttemptTransition.php`, `app/Models/BusinessUsageLedgerEntry.php`, `app/Models/BusinessBillingReceipt.php`, `app/Jobs/Usage/SendReceiptNotification.php`, `app/Jobs/Usage/ReconcileProviderPendingState.php` (existence/scope confirmed), `app/Library/Usage/{CheckoutSessionResult,PaymentIntentResult,FakePaymentProviderGateway,StripePaymentProviderGateway,Contracts/PaymentProviderGateway}.php`.
- Current migrations/enums/unique constraints/tests governing provider identifiers, ledger correlation keys, balances, debt, refunds, disputes, transitions: every migration under `database/migrations/2026_08_16_14*` and `2026_08_16_120*`/`2026_08_27_*`/`2026_08_28_*`, `app/Enums/Usage/{UsageLedgerEntryType,FundingAttemptState,FundingAttemptPurpose,BillingStatusTransitionSource,AddonPurchaseStatus,AddonFulfillmentMode,TransitionSource}.php`, and the existing webhook test suite (`tests/Feature/Usage/Webhook*.php`, `PaymentProviderEventSchemaTest.php`).
- The installed payment-provider SDK (`stripe/stripe-php` `v7.128.0`, confirmed via `composer.lock`) and Stripe's own official object/event documentation, fetched live this pass and quoted with the exact field names in §3 below, since the Charge/Dispute object shape and the refund/dispute webhook event catalog cannot be derived from this repository alone (this repository never previously ingested either object family).

---

## 2. Exact prior exclusion language, quoted verbatim

- **RFC-005 M2 contract, §5 (scope):** *"Refunds, chargebacks, or disputes."* — listed as flatly out of scope for M2 (a payer/limits-configuration milestone, no charging capability at all yet).
- **RFC-005 M3 contract, §7 (exclusions), item 12:** *"Refund/reversal behavior — explicitly **not** assigned to M3 (§7); `Refund`/`DisputeChargeback` ledger-entry types exist at the schema level only, never written by M3 code."* And, in §11 (funding/top-up flow), item 12: *"a Stripe-initiated refund/dispute webhook is explicitly out of scope for M3 to *process into a mutation* (§13 step 5's 'unknown event type' branch routes it to reconciliation, never a silent accounting effect) **unless directly re-authorized by a future contract**."* — this remediation is that future contract.
- **RFC-005 M3 contract, §15 (auto-recharge):** *"No headroom reopening from refunds or corrections — identical to §13 of the RFC; `recharged_this_period_micro` is never decremented by a `Refund`/`DisputeChargeback`/`CorrectionReversal` entry"* — a locked invariant this contract's design must not violate (§8 below preserves it: none of the three new/reused entry types this contract writes ever touches `recharged_this_period_micro`).
- **RFC-005 M4 contract / corrections 1–2:** mention `Refund`/`DisputeChargeback` only in the identical, unchanged §13 delta-table restatement — no new exclusion language, no new capability claimed.
- **RFC-005 Design Contract, RFC-005 Job/Event Dispatch Completion Correction Contract, RFC-005 Receipt Boundary Correction Contract:** each mentions `Refund`/`reversed_entry_id`/receipt-boundary language only in the course of restating the already-existing schema (`business_usage_ledger_entries.reversed_entry_id`, `business_billing_receipts`) or in a code comment cross-reference (e.g. `SendReceiptNotification`'s own docblock) — none claims to implement refund/dispute processing, and none excludes it beyond what M2/M3 already state.
- **RFC-005 Admin Usage Billing Surface Contract:** excludes *"Originates a fresh customer charge"* and confirms the dashboard is read-only for auto-recharge and margin — both consistent with, and unaffected by, this remediation (§9 below: zero admin-surface changes).

**Conclusion, mechanically confirmed:** no merged RFC-005 artifact has ever implemented provider-initiated refund/dispute *processing*. The schema/enum surface for it (`Refund`, `DisputeChargeback`, `UsageChargeReversal`, `CorrectionReversal`, `FundingAttemptState::Refunded`/`::Disputed`, `BillingStatusTransitionSource::DisputeWebhook`, `business_usage_ledger_entries.reversed_entry_id`) has existed, unused, since M1/M2/M3. This remediation is the first contract authorized to wire it up.

---

## 3. Stripe object/event facts, confirmed live against official documentation this pass

**`Charge` object** (`stripe/stripe-php` v7.128.0; fetched from `docs.stripe.com/api/charges/object`):

- `id` (`ch_...`), `payment_intent` (nullable string — *"ID of the PaymentIntent associated with this charge, if one exists"*), `customer` (nullable string), `amount` (original charge amount, minor units), `amount_refunded` (minor units, **cumulative**, monotonically non-decreasing across the charge's lifetime), `refunded` (boolean — true only once **fully** refunded; a partial refund leaves this `false`), `currency`, `disputed` (boolean).
- **`metadata` is the Charge's own, independent map** — the documentation gives no automatic-inheritance guarantee from the originating PaymentIntent's metadata, and this repository's own `StripePaymentProviderGateway::verifyWebhookSignature()` already treats `$object->metadata` as whatever is literally present on `data.object` (`app/Library/Usage/StripePaymentProviderGateway.php:210`) — for every event family this codebase has processed to date (`payment_intent.*`, `checkout.session.*`), that object *is* the PaymentIntent/Session we ourselves created with our own metadata attached, so the assumption has always held. **A `charge.refunded`/`charge.dispute.*` event's `data.object` is a `Charge` or `Dispute` object we never created and never attached metadata to — the existing metadata-routing assumption (RFC §21 step 3, M3 contract §13 step 8) does not hold for this event family and is not extended to it by this contract.**

**`Dispute` object** (`docs.stripe.com/api/disputes/object`):

- `id` (`du_...`), `charge` (string — *"ID of the charge that's disputed"*), `payment_intent` (nullable string — *"ID of the PaymentIntent that's disputed"*), `amount` (minor units, the disputed amount), `currency`, `status` (enum: `warning_needs_response`, `warning_under_review`, `warning_closed`, `needs_response`, `under_review`, `won`, `lost`, `prevented`), `reason`, `metadata` (own independent map, empty by default — same non-inheritance caveat as Charge).
- No `customer` field exists directly on `Dispute` — Business identification must route via `payment_intent` (or `charge`), never via a customer-id comparison at this layer.

**Refund/dispute webhook event catalog** (`docs.stripe.com/api/events/types`, confirmed exhaustively — no invented event name is used anywhere in this contract):

| Event type | `data.object` | Balance-impact semantics |
|---|---|---|
| `charge.refunded` | `Charge` | *"Occurs whenever a charge is refunded, **including partial refunds**."* Carries the Charge's own current, cumulative `amount_refunded`/`refunded`. |
| `refund.created` / `refund.updated` / `refund.failed` | `Refund` (separate, per-refund-operation object) | A more granular, per-operation event family layered on top of the same underlying state `charge.refunded` already reports cumulatively. |
| `charge.dispute.created` | `Dispute` | *"Occurs whenever a customer disputes a charge with their bank."* No balance impact by itself — many disputes begin as a `warning_*` inquiry with no funds movement at all. |
| `charge.dispute.funds_withdrawn` | `Dispute` | *"Occurs when funds are removed from your account due to a dispute."* The actual balance-impact event. |
| `charge.dispute.updated` | `Dispute` | Evidence/administrative update — no balance impact. |
| `charge.dispute.funds_reinstated` | `Dispute` | *"Occurs when funds are reinstated to your account after a dispute is closed. This includes partially refunded payments."* The actual balance-impact **reversal** event. |
| `charge.dispute.closed` | `Dispute` | *"Occurs when a dispute is closed and the dispute status changes to `lost`, `warning_closed`, or `won`."* Purely a status notification — `funds_reinstated`/`funds_withdrawn`, not `closed` itself, is what this contract keys any mutation to, since a `closed`/`won` dispute is not guaranteed to fire `funds_reinstated` in the identical delivery, and a `closed`/`lost` dispute reinstates nothing at all. |

**Locked design consequence:** this contract listens to exactly **one** refund event type (`charge.refunded`, whose own cumulative `amount_refunded` field makes the narrower `refund.*`/`charge.refund.updated` family strictly redundant for this system's own bounded-idempotent-delta design, §8) and exactly **two** dispute event types that ever mutate anything (`charge.dispute.funds_withdrawn`, `charge.dispute.funds_reinstated`); `charge.dispute.created`/`.updated`/`.closed` are durably ingested (the `payment_provider_events` row itself *is* the audit record, §9) and marked `ignored` — never a mutation, never silently dropped.

---

## 4. Mechanical findings — what exists, what is genuinely missing

### 4.1 Identification: how a refund/dispute event resolves to exactly one Business, with zero cross-Business ambiguity

**Missing.** The existing metadata-based routing (`ProcessPaymentProviderEvent::handle()`'s `match ($subjectKind)`, `app/Jobs/Usage/ProcessPaymentProviderEvent.php:68`) resolves a local record only via `app_subject_kind`/`app_subject_id`/`app_operation_id` metadata **we ourselves attached to the PaymentIntent/Checkout Session we created** (M3 contract §13 step 8). Per §3 above, a Charge/Dispute event's own metadata is not that metadata. The only field reliably present on **both** a `charge.refunded` Charge object and a `charge.dispute.*` Dispute object, in every case, is `payment_intent`.

`business_funding_attempts.provider_session_or_intent_reference` (`UNIQUE`) is:
- **The PaymentIntent id directly**, for `FundingAttemptPurpose::AutoRecharge` (confirmed, `UsageBillingCheckoutManager.php:1543`, `379`).
- **The Checkout Session id**, for `ManualTopUp`/`AddonPurchase` (confirmed throughout `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`).

For the second group, a refund/dispute event's `payment_intent` value **cannot** be matched against `provider_session_or_intent_reference` — the values are for two different Stripe object types. This is a genuine, structural identification gap, not a metadata-reliability inconvenience.

**However, the PaymentIntent id for a Checkout-backed attempt is already resolved, in memory, at the exact moment that attempt is confirmed** — `CheckoutSessionResult::$providerPaymentIntentId` (`app/Library/Usage/CheckoutSessionResult.php:24`) is populated by every `retrieveCheckoutSession()` call already made inside `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`/`retryFundingAttemptAsAdministrator()` — it is simply never persisted today.

**Locked design:** a new nullable, `UNIQUE`-when-populated column, `business_funding_attempts.provider_payment_intent_reference`, populated **synchronously, inside the same transaction that transitions the attempt to `Succeeded`** (never by an async job, never by a live provider call made specifically for this purpose): for AutoRecharge, the identical value already known (`$attempt->provider_session_or_intent_reference` itself); for Checkout-backed purposes, `$session->providerPaymentIntentId` — already in hand, zero additional provider round-trips. This column, once populated, resolves a refund/dispute event's `payment_intent` field to **exactly one** local funding attempt — and therefore exactly one Business — by the same `UNIQUE`-constraint-backed guarantee `provider_session_or_intent_reference` already provides for the original confirmation flow. Cross-Business ambiguity is structurally impossible, not merely validated.

**Why not `business_billing_receipts.provider_reference` (the Charge id, already captured today)?** Confirmed by direct read of `ensureFundingReceipt()`/`SendReceiptNotification` (`app/Jobs/Usage/SendReceiptNotification.php:30-67`): receipt attachment is **best-effort** — dispatched `ShouldQueueAfterCommit`, `$tries = 1`, and explicitly documented as *"recoverable only via a manual/operator re-dispatch"* on failure. A receipt row's existence is not guaranteed for every succeeded attempt, so it cannot be the sole identification mechanism for a financial-outcome event. It remains useful only as evidence (§4.10), never as the routing key.

### 4.2 Full/partial/repeated refunds — exact semantics, never conflated

- **Provider-confirmed refund vs. customer-requested refund**, explicitly not conflated: this codebase has **no** code path, anywhere, for a customer or a Business to *request* or *originate* a refund (confirmed: M2's own `NoFakePaymentControlsRenderedTest` explicitly bans a "Refund" UI control, and no `initiateRefund()`-shaped method exists on `UsageBillingCheckoutManager`/`PaymentProviderGateway`). This contract processes **only** the inbound fact that Stripe has already executed a refund (whoever asked Stripe for it — dashboard operator, Stripe's own dispute resolution, or a bank-initiated return) — it never originates one. "Customer-requested refund" is, today, an entirely out-of-band, non-code business process; this contract's only concern is the resulting `charge.refunded` webhook.
- **Charge reversal**, as a provider-level concept, is not a distinct event family from a refund at the webhook layer — Stripe reports both as `charge.refunded` with `amount_refunded` reflecting the new cumulative total. No separate handling is introduced for "reversal" as its own category.
- **Full vs. partial, mechanically distinguished:** `Charge.refunded === true` (full) vs. `false` (partial) is read directly off the verified webhook payload — never inferred from the amount alone (Stripe's own field is authoritative and exists precisely so this comparison is never approximated).
- **Repeated/multiple partial refunds:** `amount_refunded` is Stripe's own **cumulative** figure. This contract never tracks "the last refund's own increment" as a first-class fact — it tracks "how much has this system already reversed for this funding attempt" (§4.5) and applies only the **delta** between that and the event's reported cumulative figure, clamped (§4.5). This makes the design correct under out-of-order delivery, redelivery, and an arbitrary number of partial refunds without any per-refund-operation bookkeeping.

### 4.3 Dispute creation, updates, won, lost, reversed — never conflated

- **`charge.dispute.created`** — an inquiry/dispute now exists. No balance impact by itself (a `warning_*`-status dispute may never withdraw funds at all). Durably recorded, `ignored`.
- **`charge.dispute.funds_withdrawn`** — the actual balance-impact event. This is what this contract treats as "a dispute happened," in the RFC's own `DisputeChargeback` sense (§13's delta row).
- **`charge.dispute.updated`** — evidence/administrative change. No balance impact. Durably recorded, `ignored`.
- **`charge.dispute.closed`** — status becomes `lost`, `warning_closed`, or `won`. By itself, **no mutation**: a `lost` dispute's earlier `funds_withdrawn` debit already stands permanently (nothing to reverse); a `warning_closed` dispute never withdrew funds in the first place (nothing to reverse); and a `won` dispute's actual fund reinstatement is reported by the separate `funds_reinstated` event, which this contract keys its reversal to directly, independent of `closed`'s own delivery timing. Durably recorded, `ignored`, regardless of `status`.
- **`charge.dispute.funds_reinstated`** — the actual reversal event. This is what this contract treats as "the dispute was won and Stripe returned the money," and is the only event that ever reverses a `DisputeChargeback`.
- **Internal correction** (`CorrectionReversal`, `UsageChargeReversal`) — always administrator/system-initiated, never provider-driven. This contract writes exactly one `CorrectionReversal` shape (§4.7, reversing a `DisputeChargeback` on `funds_reinstated`) and never writes `UsageChargeReversal` (that entry type remains entirely unauthorized for any code path, exactly as it has been since M1 — no admin action to reverse a usage charge exists, and this contract does not introduce one).

### 4.4 Which outcomes change wallet available balance or debt

| Provider outcome | Wallet mutation? | Ledger entry written |
|---|---|---|
| `charge.refunded`, delta > 0 (§4.5), attempt had a wallet-crediting entry (§4.6) | Yes | `Refund` |
| `charge.refunded`, delta ≤ 0 (already fully applied) | No | none (idempotent no-op) |
| `charge.refunded` for an `AddonPurchase` attempt whose fulfillment was `direct_deliverable` | No | none — nothing was ever credited to the wallet for this money (§4.6) |
| `charge.dispute.funds_withdrawn` | Yes | `DisputeChargeback` |
| `charge.dispute.funds_reinstated` | Yes, if a `DisputeChargeback` exists to reverse | `CorrectionReversal` |
| `charge.dispute.created` / `.updated` / `.closed` | No | none (event row itself is the audit trail, §4.10) |

### 4.5 Amount bounding — cumulative reversals can never exceed the original provider-backed amount

`business_funding_attempts.expected_amount_micro` is this system's own already-verified, frozen amount for the attempt (verified against the provider at confirmation time, M3 contract §13 step 10) — the authoritative ceiling. **Net-already-reversed** for a funding attempt is computed, not cached: a new, bounded repository read (`BusinessUsageLedgerEntryRepository::reversalEntriesForFundingAttempt()`, §7) returns every `Refund`/`DisputeChargeback`/`CorrectionReversal` row for that `funding_attempt_id` (realistically zero to a handful of rows, ever, per attempt — bounded by construction, never a table scan once the new index lands, §7), and `UsageBillingCheckoutManager` reduces them in exact `bcmath` string arithmetic: `net = Σ(Refund.gross_amount_micro) + Σ(DisputeChargeback.gross_amount_micro) − Σ(CorrectionReversal.gross_amount_micro)`. For a refund event, the amount actually applied is `min(cumulativeAmountRefundedMicro, expected_amount_micro) − net`, clamped to a minimum of `0` — never negative, never re-derived by subtracting native PHP integers. If the clamped delta is `0`, nothing is written; this is the refund idempotency mechanism (§4.7), not a separate code path.

### 4.6 Whether a wallet mutation applies at all — the `AddonPurchase`/fulfillment-mode nuance

Confirmed by direct read (`UsageBillingCheckoutManager.php:773-780`): an `AddonPurchase`-purpose attempt credits the wallet (`creditFromFunding(..., PaidTopUp, ...)`) **only when** the purchased addon's catalog row has `fulfillment_mode = wallet_credit`; for `direct_deliverable`, no ledger entry, no wallet credit, is ever written for that money. A refund of a `direct_deliverable` addon purchase therefore has nothing to reverse on the wallet — reversing it anyway would incorrectly debit a wallet that was never credited for that money. **Locked design:** before applying any refund mutation, `BusinessUsageLedgerEntryRepository::findCreditEntryForFundingAttempt()` (§7) checks whether a `PaidTopUp`/`AutoRecharge` entry exists for this `funding_attempt_id` at all. `ManualTopUp` and `AutoRecharge` always have one (unconditional `creditFromFunding()` call, confirmed `UsageBillingCheckoutManager.php:672-684`). If none exists, the refund event is durably recorded and the attempt's own state still transitions (§4.8), but **zero wallet mutation** occurs — correctly, since none occurred in the first place.

### 4.7 Idempotency/uniqueness boundary — preventing duplicate webhook delivery or concurrent processing from applying the same outcome twice

Two independent layers, matching this codebase's own two-layer idempotency discipline (M3 contract §13 step 13) exactly:

1. **Event-identity layer, unchanged, already correct:** `payment_provider_events.UNIQUE(provider, provider_event_id)` rejects a literal Stripe redelivery of the identical event before it is ever claimed a second time (`StripeWebhookController.php:59-65`).
2. **Outcome layer, new, this contract's own responsibility — deterministic correlation keys derived from the *outcome*, not the event id, so even a *different* event id reporting an *already-applied* outcome is a guaranteed no-op:**
   - Refund entries: `'refund:'.$attempt->id.':'.$newCumulativeAmountRefundedMicro` — unique per attempt per distinct cumulative-refunded value ever observed. A redelivered or superseded-but-identical `charge.refunded` event collides on this key (caught `UniqueConstraintViolationException`, treated as an already-applied no-op, mirroring the existing `confirmSucceeded()` catch, `UsageBillingCheckoutManager.php:685-691`) — but §4.5's own delta-clamp already prevents the attempt in the first place, making the `UNIQUE` constraint a second, structural line of defense, not the only one.
   - `DisputeChargeback` entries: `'dispute_chargeback:'.$attempt->id.':'.$providerDisputeId` — one row per real-world dispute, ever (a dispute's own `amount` does not change across its lifetime; only `status` does).
   - `CorrectionReversal` entries (dispute reversal): `'dispute_reversal:'.$attempt->id.':'.$providerDisputeId` — one row per dispute, ever; also locatable directly via the *existing* `findByCorrelationKey()` (already on `BusinessUsageLedgerEntryRepository`, unmodified) to find the original `DisputeChargeback` row being reversed, with zero new lookup method.

**Row-lock ordering, matching every existing wallet-mutating method in this codebase:** the wallet row is locked first (`findForUpdateByBusinessId()`), inside one `DB::transaction()`, before the reversal-amount computation and the ledger insert — identical lock order to `creditFromFunding()`/`commit()`/`issueManualCredit()`. The funding attempt's own terminal state transition (§4.8) happens inside the **same** outer transaction as the wallet mutation, mirroring the Funding Confirmation Concurrency Correction Contract's own "credit and finalize in one transaction" fix — so a crash between the two is impossible to observe as a half-applied outcome; a replayed claim after a crash simply re-enters via the identical delta/correlation-key idempotency and either finds nothing left to do or completes the one remaining half.

### 4.8 Funding-attempt state transitions

`FundingAttemptState::Refunded`/`::Disputed` have existed, unused, since M1. **Locked:**
- A refund whose cumulative `amount_refunded` (clamped to `expected_amount_micro`) equals the full `expected_amount_micro` transitions `state` to `Refunded`. A partial refund leaves `state` at `Succeeded` (no "partially refunded" state exists, and none is added — the ledger itself, not the attempt's own `state` column, is the source of truth for exactly how much has been refunded).
- `charge.dispute.funds_withdrawn` transitions `state` to `Disputed`.
- `charge.dispute.funds_reinstated` transitions `state` back to `Succeeded` — the payment is, once again, simply successful; a subsequent refund of the same charge remains possible and is bounded identically (§4.5 nets `CorrectionReversal` back out of "already reversed").
- Every transition writes a `business_funding_attempt_transitions` row (`source: TransitionSource::WebhookEvent`, `provider_event_id` set) — reusing the existing, unmodified transition table and enum; no new `source` value, no new column.
- `retryFundingAttemptAsAdministrator()`'s own resumable-state whitelist (`ProviderPending`, `RequiresAction`, `Failed`) already excludes `Refunded`/`Disputed` — confirmed, zero change needed there.

### 4.9 Billing-status suspension/resumption

`DisputeChargeback` "also sets `billing_status = 'suspended'`" (RFC §13's own delta-table note) is realized via the **existing, currently-unused-in-production** `UsageWalletManager::setBillingStatus(..., BillingStatusTransitionSource::DisputeWebhook, null, $reason)` — the exact call shape the M2 contract built this enum case for and the one existing test (`UsageWalletBillingStatusTransitionTest::test_dispute_webhook_source_accepts_a_null_actor`) already proves works end-to-end at the manager layer. **Resumption after a won dispute is deliberately left to the existing, unmodified administrator-only `resumeBilling` action** on the just-merged Admin Usage Billing Surface (`admin.businesses.usage-billing.resume`) — reusing an already-built, already-authorized capability rather than inventing an automatic-resume code path the RFC's own authorization table (§24: *"Set/clear `billing_status = 'suspended'`" — Platform administrator, Yes only*) does not contemplate for the reverse direction. This is recorded as a locked default with an open alternative in §12.

### 4.10 Original receipts and refund/dispute evidence — never a fabricated new "successful payment" receipt

Confirmed via Stripe's own documentation (`docs.stripe.com/api/charges/object`, `receipt_url` field): *"The receipt is kept up-to-date to the latest state of the charge, including any refunds."* Stripe's hosted receipt page is **live**, not a static snapshot — the identical, already-stored `business_billing_receipts.provider_receipt_url` (when a receipt row exists at all, §4.1's own best-effort caveat) already reflects a refund the moment a viewer opens it, with zero code change. **Locked: this contract never inserts a new `business_billing_receipts` row for a refund or a dispute** — that table's own sole write authority (`UsageWalletManager`, RFC §25) and its own semantic (*evidence of a successful funding credit*) are preserved exactly; a refund/dispute is not a new successful payment, and representing it as a second receipt row against the same `ledger_entry_id` shape would misstate what happened. Dispute evidence submission (Stripe's own `evidence`/`evidence_details` hash) is an **outbound** action (the platform operator responds to Stripe directly, via Stripe's own dashboard) — explicitly out of scope; this system only needs to durably know a dispute exists and how it resolved, never to manage the evidence-submission workflow.

### 4.11 Domain events, jobs, notifications, administrator-visible audit records

- **Events reused, zero new event class:** `BusinessWalletDebited`/`BusinessWalletDebtIncurred` (refund/chargeback debit and debt formation — the exact pair `commit()`'s own overage-debt-formation branch already dispatches, `UsageWalletManager.php:726-740`, mirrored here for the identical available/debt split shape) and `BusinessWalletCredited`/`BusinessWalletDebtCleared` (a won-dispute reversal's own credit direction). No RFC §29 event name exists for "funding attempt refunded/disputed" as its own event, and none is invented.
- **Job reused, zero new job class:** `ProcessPaymentProviderEvent` — widened routing only (§6).
- **No new notification.** Confirmed by mechanical audit of every existing `App\Notifications\Usage\*`/`SendLowBalanceNotification`/`SendAutoRechargeDisabledNotification`/`SendReceiptNotification`: no precedent exists for notifying a Business of an ordinary debit (a usage overage charge does not notify either) — a chargeback-driven debit is not, by established precedent, treated as notification-worthy at the manager layer. This is flagged, not silently assumed, as an open product decision in §12.
- **Administrator-visible audit — fully reused, zero new admin surface.** The just-merged Admin Usage Billing Surface dashboard already: (a) renders every `UsageLedgerEntryType` case in its entry-type filter dropdown and its ledger table generically (`resources/views/admin/usage-billing/businesses/show.blade.php`, confirmed unmodified-needed by direct re-read), so `Refund`/`DisputeChargeback`/`CorrectionReversal` rows appear automatically; (b) renders `$attempt->state->value` as a plain badge with no enum-specific branching, so `refunded`/`disputed` render automatically and are excluded from the retry-eligible state list automatically (`in_array($attempt->state->value, ['provider_pending','requires_action','failed'], true)` already excludes both); (c) renders billing-status history generically, so a `dispute_webhook`-sourced transition appears automatically. The existing `admin.provider-events.*` surface already reviews/dispositions any exhausted event regardless of `event_type` — a refund/dispute event that fails validation and exhausts its retry budget surfaces there with zero code change.

### 4.12 Outcomes that require no financial mutation but still require durable audit/disposition

`charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed` — every one durably persisted as a `payment_provider_events` row (identity, type, object id, timestamps — the existing, unmodified schema) and marked `ignored` (an existing, unmodified terminal state). No new table, no new column, no new admin action — the event row itself is the complete, permanent audit record, exactly as it already is for every other intentionally-ignored event type this job already produces (e.g. a Checkout Session's own `.expired`/async-failure branch, `ProcessPaymentProviderEvent.php:388-392`).

### 4.13 Retry, reconciliation, locking, transaction, crash-recovery boundaries

- **Retry/lease/disposition:** entirely reused, unmodified (§14 of the M3 contract, §7 above) — refund/dispute events are ordinary `payment_provider_events` rows and flow through the identical bounded claim/retry/exhaustion/disposition algorithm as every other event type, with zero special-casing.
- **Reconciliation:** `ReconcileProviderPendingState` is scoped, by its own name and its own existing implementation, to attempts stuck in `ProviderPending`/`Processing` — a refund/dispute event concerns an already-`Succeeded` (or already-`Refunded`/`Disputed`) attempt, a structurally different concern. **Not required, not extended** — a refund/dispute event that cannot be resolved fails closed into the existing exhausted-event admin queue (§4.12), which is this system's own already-designed recovery path for exactly this shape of problem.
- **Locking/transaction:** §4.7 above, fully specified.
- **Crash recovery:** identical to every other webhook-driven mutation in this codebase — the claim lease (5 minutes, existing config) bounds how long a crashed worker's claim blocks a retry; the outcome-keyed correlation keys (§4.7) make a retried/redelivered event idempotent regardless of how far the crashed attempt got.

### 4.14 What intentionally remains out of scope

- **`additional_business_slot_agreements`/`additional_business_slot_renewal_charges` refunds/disputes.** A structurally separate accounting domain (Workspace-level slot subscriptions, never touching `business_usage_wallets`/the ledger) with its own state machine (`SlotAgreementState`, including its own already-schema'd `refund_pending`/`refunded` states, RFC §22). None of this contract's own required-reading list (wallet, ledger, `UsageChargeReversal`/`CorrectionReversal`, receipts) names this domain, and extending into it would roughly double this contract's own surface for a genuinely distinct reversal mechanism. Left for a separate, future remediation if ever prioritized.
- **Historical backfill of `provider_payment_intent_reference` for pre-existing Checkout-backed `Succeeded` attempts.** Trivial for `AutoRecharge` (pure local-data copy, §7's own migration #2) but would require a live per-row Stripe API call for every pre-existing `ManualTopUp`/`AddonPurchase` `Succeeded` attempt to backfill the other purposes — explicitly not performed by this contract (governance: no live Stripe action is authorized by authoring or implementing this document without further, separate authorization for that specific operation). A refund/dispute event arriving for one of these older, unbackfilled attempts fails closed (`no_matching_local_record`) into the existing admin-reviewable exhausted-event queue — an accepted, bounded, already-designed degradation, not a silent gap.
- **Dispute evidence submission** (§4.10).
- **Refund/dispute origination of any kind** (§4.2) — this contract only ever processes an inbound outcome; it never calls a Stripe refund/dispute-response endpoint.
- **A "you were debited" notification** (§4.11) — recorded as an open decision, §12, not silently declined.
- **Any new admin HTTP action, route, controller, or FormRequest.** Confirmed nothing in §4 requires one.
- **Any new PHP enum, or any new case on an existing enum.** Every enum this design needs (`UsageLedgerEntryType::{Refund,DisputeChargeback,CorrectionReversal}`, `FundingAttemptState::{Refunded,Disputed}`, `BillingStatusTransitionSource::DisputeWebhook`, `ProviderEventState::Ignored`) already exists.
- **Any new exception class.** Every failure path this design needs is expressed as an existing `markFailed($event->id, $reason)` string-reason branch (mirroring every existing validation-failure branch in `ProcessPaymentProviderEvent`) or an existing, reused exception (`UsageWalletNotFoundException`) — bounding is achieved by clamping (§4.5), never by throwing.

---

## 5. Locked design — exact production allow-list

**8 files, 3 new + 5 modified. Every path below is justified by a specific finding in §4; none is guessed.**

| # | Path | Status | Justified by |
|---|---|---|---|
| 1 | `database/migrations/2026_08_29_120001_add_provider_payment_intent_reference_to_business_funding_attempts_table.php` | NEW | §4.1 — nullable `string(191)`, `UNIQUE` when not null, `after('provider_session_or_intent_reference')`. |
| 2 | `database/migrations/2026_08_29_120002_backfill_provider_payment_intent_reference_for_auto_recharge_attempts.php` | NEW | §4.14 — pure local-data `UPDATE ... WHERE purpose = 'auto_recharge' AND state = 'succeeded' AND provider_session_or_intent_reference IS NOT NULL`, no provider call, matching the established data-only-migration convention (`2026_08_16_120009_backfill_business_usage_wallets.php`'s own shape). |
| 3 | `database/migrations/2026_08_29_120003_add_funding_attempt_id_index_to_business_usage_ledger_entries_table.php` | NEW | §4.5/§4.6 — a plain index on the existing, currently-unindexed `funding_attempt_id` column, justified by the two new bounded reads this contract introduces (item 6 below). No column added. |
| 4 | `app/Models/BusinessFundingAttempt.php` | MODIFIED | §4.1 — add `provider_payment_intent_reference` to `$fillable`. No cast needed (plain string). |
| 5 | `app/Repositories/Contracts/BusinessFundingAttemptRepository.php` + `app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php` | MODIFIED (pair) | §4.1 — one new method, `findByProviderPaymentIntentReference(string $reference): ?BusinessFundingAttempt`. |
| 6 | `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` + `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php` | MODIFIED (pair) | §4.5/§4.6 — two new methods: `findCreditEntryForFundingAttempt(int $fundingAttemptId): ?BusinessUsageLedgerEntry` (bounded, `entry_type IN (paid_top_up, auto_recharge)`, one row); `reversalEntriesForFundingAttempt(int $fundingAttemptId): Collection` (bounded, `entry_type IN (refund, dispute_chargeback, correction_reversal)`, ordered by `id`). Both exempt from the raw-query boundary test on the identical, already-established basis every other Eloquent repository implementation is exempt (§9). |
| 7 | `app/Library/Usage/UsageWalletManager.php` | MODIFIED | §4.4/§4.5/§4.7/§4.9/§4.11 — two new public methods: `reverseFundingCredit(BusinessFundingAttempt $attempt, UsageLedgerEntryType $entryType, int $amountMicro, string $correlationKey, ?string $reason = null): ?BusinessUsageLedgerEntry` (writes `Refund`/`DisputeChargeback` per RFC §13's own delta row, `-min(amt,avail)`/`0`/`+max(0,amt-avail)`; returns `null` and mutates nothing for `$amountMicro <= 0`; for `entry_type === DisputeChargeback`, also calls the existing `setBillingStatus(..., Suspended, DisputeWebhook, null, $reason)` inside the same transaction; dispatches `BusinessWalletDebited`/`BusinessWalletDebtIncurred` matching `commit()`'s own overage-branch shape) and `reverseChargebackEntry(BusinessUsageLedgerEntry $originalEntry, string $correlationKey): BusinessUsageLedgerEntry` (writes `CorrectionReversal` as the exact negation of `$originalEntry`'s own `available_delta_micro`/`debt_delta_micro`, sets `reversed_entry_id = $originalEntry->id`, dispatches `BusinessWalletCredited`/`BusinessWalletDebtCleared` matching the negated split). |
| 8 | `app/Library/Usage/UsageBillingCheckoutManager.php` | MODIFIED | §4.1/§4.5/§4.6/§4.8 — (a) one new private inverse-conversion method, `minorUnitsToMicro(int $minorUnits, string $currencyCode): int`, and one new public wrapper, `expectedMicroForMinorUnits(BusinessFundingAttempt $attempt, int $minorUnits): int`, mirroring `microToMinorUnits()`/`expectedMinorUnitsFor()`'s own exact `bcmath` shape (`app/Library/Usage/UsageBillingCheckoutManager.php:1027-1039`); (b) `confirmSucceeded()`/`finalizeFundingAttemptState()` each gain one new, trailing, backward-compatible optional parameter, `?string $resolvedProviderPaymentIntentReference = null`, threaded from the three existing call sites (`confirmAttemptFromReturn()`, `confirmAttemptFromWebhook()`, `retryFundingAttemptAsAdministrator()` — each already has the value in hand, §4.1) into the `$updateAttributes` array `finalizeFundingAttemptState()` already builds — the identical widening pattern already used twice on this exact pair of methods (`$reason`, `$verifiedPaymentMethodDisplay`); (c) three new public orchestration methods: `applyRefundOutcome(BusinessFundingAttempt $attempt, int $cumulativeAmountRefundedMicro, bool $fullyRefunded, ?int $providerEventId): void`, `applyDisputeChargebackOutcome(BusinessFundingAttempt $attempt, string $providerDisputeId, int $disputedAmountMicro, ?int $providerEventId): void`, `reverseDisputeChargebackOutcome(BusinessFundingAttempt $attempt, string $providerDisputeId, ?int $providerEventId): void` — each locks the wallet row first (§4.7), computes the bounded delta (§4.5) or locates the original entry via `findByCorrelationKey()` (existing, unmodified), calls exactly one `UsageWalletManager` method when a delta > 0 (or skips it entirely per §4.6), transitions the attempt's own `state` (§4.8) and records one `business_funding_attempt_transitions` row, all inside one `DB::transaction()`. |
| 9 | `app/Jobs/Usage/ProcessPaymentProviderEvent.php` | MODIFIED | §3/§4.3/§4.12 — `handle()`'s own `match` widened: `charge.refunded` and the two mutating dispute event types are checked **before** falling back to the existing `$subjectKind` metadata match (§4.1 — this event family is never metadata-routed); `charge.dispute.created`/`.updated`/`.closed` route straight to `markIgnored()`. Two new private methods, `processChargeRefund()`/`processChargeDispute()`, each: requires `payment_intent` present in the decoded payload (else `missing_required_evidence`); resolves the attempt via the new repository method (else `no_matching_local_record`); requires the attempt's own state to already be `Succeeded`, `Refunded`, or `Disputed` (else `invalid_local_state_for_transition` — a refund/dispute for an attempt that never locally succeeded is a genuine mismatch, not a race to tolerate); verifies currency against `expectedCurrencyCodeFor($attempt)` (else `currency_mismatch`); converts the payload's own minor-unit amount via `expectedMicroForMinorUnits()`; calls exactly one `UsageBillingCheckoutManager` orchestration method; marks the event `processed`. |

**NOT_REQUIRED, explicitly confirmed (mirroring the Admin Usage Billing Surface Contract's own convention of naming what a reviewer might otherwise expect):**

| Path | Reason |
|---|---|
| Any file under `app/Http/Controllers/Admin/**` or `resources/views/admin/**` | §4.11/§9 — the existing dashboard/provider-event surfaces are entry-type/state-value generic; zero rendering change needed. |
| `routes/public.php` | The webhook route (`webhooks.stripe.usage-billing`) is already event-type-agnostic. |
| `app/Providers/AppServiceProvider.php` | Confirmed by direct read — both widened repository contracts are already bound; adding a method to an existing interface requires no new binding. |
| Any new PHP enum file, or any change to an existing enum file | §4.14 — every enum case this design needs already exists. |
| Any new `App\Exceptions\Usage\*` file | §4.14 — bounding is by clamping, not by throwing; the one reused exception (`UsageWalletNotFoundException`) is unmodified. |
| Any new `App\Events\Usage\*`/`App\Notifications\Usage\*`/`App\Jobs\Usage\*` file | §4.11 — four existing wallet events and the existing job are reused unmodified. |
| `app/Library/Usage/Contracts/PaymentProviderGateway.php`, `StripePaymentProviderGateway.php`, `FakePaymentProviderGateway.php`, `CheckoutSessionResult.php`, `PaymentIntentResult.php` | §3/§9 — the verified, signed webhook payload is trusted directly for this event family (mirroring the existing AutoRecharge/PaymentIntent-webhook precedent, not the Checkout-Session-independent-re-fetch precedent, since no browser-redirect race exists for a server-to-server Charge/Dispute event); no new gateway method, no new DTO field. |
| `app/Jobs/Usage/ReconcileProviderPendingState.php`, `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` | §4.13 — both already event-type-agnostic; a refund/dispute event that exhausts follows the identical existing path. |
| `app/Models/BusinessBillingReceipt.php`, `app/Library/Usage/UsageBillingCheckoutManager::ensureFundingReceipt()` | §4.10 — reused, unmodified; no new receipt row is ever written by this contract. |
| `config/usage_billing.php` | The existing `webhook_event.lease_minutes`/`webhook_event.max_attempts` config already applies generically to any event type. |

---

## 6. Exact test allow-list — 4 new files, 36 methods, no existing test file modified

**No existing test file requires modification** — confirmed by mechanical search: no test asserts an exact, closed column list for `business_funding_attempts` or `business_usage_ledger_entries` (the new column/index are additive and invisible to every existing assertion), and no existing test constructs a `charge.*`/`refund.*`/`dispute.*` webhook fixture.

| # | Path | Methods | Proves |
|---|---|---|---|
| 1 | `tests/Feature/Usage/ProviderRefundOutcomeTest.php` | 12 | §4.2, §4.4, §4.5, §4.6, §4.7 (refund side), §4.8 (refund side) |
| 2 | `tests/Feature/Usage/ProviderDisputeOutcomeTest.php` | 11 | §4.3, §4.4, §4.7 (dispute side), §4.8 (dispute side), §4.9, §4.12 |
| 3 | `tests/Feature/Usage/UsageWalletManagerReversalTest.php` | 9 | §5 item 7 (`reverseFundingCredit()`/`reverseChargebackEntry()`), §4.11's own event-dispatch guarantees, manager-layer authority mirroring `SlotAgreementAdminAuthorityTest.php`'s own established pattern where a direct, non-HTTP call is the correct test shape |
| 4 | `tests/Feature/Usage/ProviderRefundDisputeSurfaceBoundaryTest.php` | 4 | §9 |

**Exactly 36 test methods, named exactly:**

### `ProviderRefundOutcomeTest.php` (12)

1. `test_a_full_refund_event_writes_a_refund_ledger_entry_and_transitions_the_attempt_to_refunded`
2. `test_a_partial_refund_event_writes_a_refund_ledger_entry_for_the_partial_amount_and_leaves_the_attempt_succeeded`
3. `test_a_second_partial_refund_event_applies_only_the_incremental_delta_since_the_first`
4. `test_a_replayed_refund_event_reporting_an_already_applied_cumulative_amount_produces_zero_additional_mutation`
5. `test_a_refund_exceeding_available_balance_clears_available_balance_and_creates_debt`
6. `test_a_refund_is_bounded_and_never_exceeds_the_original_funding_attempts_expected_amount`
7. `test_a_refund_for_an_addon_purchase_with_direct_deliverable_fulfillment_produces_no_wallet_mutation`
8. `test_a_refund_for_an_addon_purchase_with_wallet_credit_fulfillment_reverses_the_original_credit`
9. `test_a_refund_event_missing_the_payment_intent_field_fails_with_no_mutation`
10. `test_a_refund_event_for_an_unresolvable_payment_intent_fails_with_no_mutation`
11. `test_a_refund_event_with_a_mismatched_currency_fails_with_no_mutation`
12. `test_a_refund_never_affects_an_unrelated_businesss_wallet`

### `ProviderDisputeOutcomeTest.php` (11)

1. `test_a_funds_withdrawn_event_writes_a_dispute_chargeback_entry_and_suspends_billing`
2. `test_a_dispute_exceeding_available_balance_clears_available_balance_and_creates_debt`
3. `test_a_replayed_funds_withdrawn_event_for_the_same_dispute_produces_zero_additional_mutation`
4. `test_a_funds_reinstated_event_writes_a_correction_reversal_exactly_negating_the_original_chargeback`
5. `test_a_funds_reinstated_event_transitions_the_attempt_back_to_succeeded`
6. `test_billing_status_remains_suspended_after_a_won_dispute_pending_administrator_resume`
7. `test_a_dispute_created_event_is_durably_recorded_and_ignored_with_no_mutation`
8. `test_a_dispute_updated_event_is_durably_recorded_and_ignored_with_no_mutation`
9. `test_a_dispute_closed_event_is_durably_recorded_and_ignored_with_no_mutation_regardless_of_status`
10. `test_a_dispute_event_for_an_unresolvable_payment_intent_fails_with_no_mutation`
11. `test_a_dispute_never_affects_an_unrelated_businesss_wallet`

### `UsageWalletManagerReversalTest.php` (9)

1. `test_reverse_funding_credit_debits_available_balance_when_sufficient`
2. `test_reverse_funding_credit_creates_debt_when_available_balance_is_insufficient`
3. `test_reverse_funding_credit_dispatches_business_wallet_debited_when_available_balance_is_debited`
4. `test_reverse_funding_credit_dispatches_business_wallet_debt_incurred_when_debt_is_created`
5. `test_reverse_funding_credit_returns_null_and_mutates_nothing_for_a_non_positive_amount`
6. `test_reverse_funding_credit_also_suspends_billing_status_for_a_dispute_chargeback_entry_type`
7. `test_reverse_chargeback_entry_exactly_negates_the_original_entrys_own_deltas`
8. `test_reverse_chargeback_entry_sets_reversed_entry_id_to_the_original_entry`
9. `test_reverse_chargeback_entry_dispatches_business_wallet_credited_and_or_debt_cleared_matching_the_negated_deltas`

### `ProviderRefundDisputeSurfaceBoundaryTest.php` (4)

1. `test_reverse_funding_credit_and_reverse_chargeback_entry_are_never_called_from_a_controller` — greps every file under `app/Http/Controllers/**` for `reverseFundingCredit|reverseChargebackEntry`, asserts zero matches.
2. `test_process_payment_provider_event_never_calls_a_charge_originating_manager_method` — greps `ProcessPaymentProviderEvent.php` for `initiateTopUp|initiateAutoRecharge|initiateAddonPurchase|createOffSessionPaymentIntent|createCheckoutSession`, asserts zero matches — refund/dispute processing never originates a new charge.
3. `test_no_new_production_file_contains_a_raw_billing_table_query_outside_the_two_eloquent_repositories` — greps every file in §5's allow-list except the two Eloquent repository implementations for `DB::table('business_usage_|DB::table('business_funding_attempts`, asserts zero matches.
4. `test_apply_refund_and_dispute_outcome_methods_are_never_called_outside_process_payment_provider_event` — greps every production file except `ProcessPaymentProviderEvent.php` and `UsageBillingCheckoutManager.php` itself for `applyRefundOutcome|applyDisputeChargebackOutcome|reverseDisputeChargebackOutcome`, asserts zero matches.

**56 methods total across the two most recently merged/pushed RFC-005 artifacts (Admin Usage Billing Surface's own 56) is not a target this contract imitates — its own count, 36, is derived solely from the findings in §4, not from any prior contract's own number.**

---

## 7. Schema/migration decisions — restated, exact DDL shape

**Migration 1** (`add_provider_payment_intent_reference_to_business_funding_attempts_table.php`):

```php
Schema::table('business_funding_attempts', function (Blueprint $table) {
    $table->string('provider_payment_intent_reference', 191)->nullable()->after('provider_session_or_intent_reference');
});
Schema::table('business_funding_attempts', function (Blueprint $table) {
    $table->unique('provider_payment_intent_reference', 'bfa_provider_payment_intent_reference_unique');
});
```

(Two `Schema::table()` calls, matching this codebase's own established convention of never mixing a column add with a structural constraint add in one blueprint call, confirmed by direct precedent read, `2026_08_24_120001_tighten_and_contract_business_usage_rates_table.php`'s own Correction-Round-2 comment.)

**Migration 2** (data-only backfill, no schema change):

```php
DB::table('business_funding_attempts')
    ->where('purpose', 'auto_recharge')
    ->where('state', 'succeeded')
    ->whereNotNull('provider_session_or_intent_reference')
    ->whereNull('provider_payment_intent_reference')
    ->update(['provider_payment_intent_reference' => DB::raw('provider_session_or_intent_reference')]);
```

**Migration 3** (`add_funding_attempt_id_index_to_business_usage_ledger_entries_table.php`):

```php
Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
    $table->index('funding_attempt_id', 'business_usage_ledger_entries_funding_attempt_id_index');
});
```

No other schema/migration change is authorized or required by this contract.

---

## 8. Preserved invariants — explicitly re-verified, none loosened

- **§13's own committed-spend-cap invariant:** *"None of these four entry types [`UsageChargeReversal`, `Refund`, `DisputeChargeback`, `CorrectionReversal`] decrements `committed_spend_this_period_micro`."* None of the write paths in §5 item 7 touches that column — confirmed by construction (`reverseFundingCredit()`/`reverseChargebackEntry()` only ever write `available_balance_micro`/`debt_balance_micro`, mirroring `creditFromFunding()`'s own column set exactly, never `committed_spend_this_period_micro`/`reserved_spend_this_period_micro`).
- **M3's own no-headroom-reopening invariant:** *"`recharged_this_period_micro` is never decremented by a `Refund`/`DisputeChargeback`/`CorrectionReversal` entry."* Confirmed by construction — §5 item 7's two new methods never touch `recharged_this_period_micro` (that column is exclusively written by `creditFromFunding()`'s own `AutoRecharge`-specific branch, `UsageWalletManager.php:924-927`, untouched by this contract).
- **`business_usage_ledger_entries` remains append-only.** No `UPDATE`/`DELETE` is introduced against any existing row; `reversed_entry_id` is set only on the *new* `CorrectionReversal` row being inserted, never retrofitted onto the original entry.
- **Outstanding-debt-denies-reservations** remains centrally enforced by `reserve()`'s own existing, unmodified check — a refund/dispute that creates new debt is denied new reservations exactly as any other debt-creating event already is, with zero new enforcement code.
- **No raw query against a billing table outside its owning manager/repository** (RFC §24) — preserved; §5 item 6's two new repository methods are the only new raw-query-capable code, and both live inside the two already-authorized Eloquent repository implementations.

---

## 9. Guarantee-by-guarantee mapping

1. **A refund/dispute event resolves to exactly one Business, never ambiguously.** §4.1, enforced by the `UNIQUE` constraint on `provider_payment_intent_reference` (Migration 1) — a database-level guarantee, not merely a validated one.
2. **Full, partial, and repeated partial refunds are each handled correctly and idempotently.** §4.2, §4.5, §4.7; proven by `ProviderRefundOutcomeTest` methods 1–4.
3. **Dispute creation/update/close/won/lost/reinstated are never conflated.** §4.3; proven by `ProviderDisputeOutcomeTest` methods 4–9.
4. **Cumulative reversals never exceed the original provider-backed amount.** §4.5; proven by `ProviderRefundOutcomeTest::test_a_refund_is_bounded_and_never_exceeds_the_original_funding_attempts_expected_amount`.
5. **A refund/dispute can create debt, but never a negative available balance, and never reopens spend-cap headroom or recharge headroom.** §4.4, §4.5, §8; proven by `ProviderRefundOutcomeTest::test_a_refund_exceeding_available_balance_clears_available_balance_and_creates_debt`, `ProviderDisputeOutcomeTest::test_a_dispute_exceeding_available_balance_clears_available_balance_and_creates_debt`, and §8's own construction proof.
6. **Duplicate webhook delivery and concurrent processing can never apply the same financial outcome twice.** §4.7; proven by `ProviderRefundOutcomeTest::test_a_replayed_refund_event_...`, `ProviderDisputeOutcomeTest::test_a_replayed_funds_withdrawn_event_...`.
7. **No fabricated successful-payment receipt is ever created for a refund/dispute.** §4.10, enforced by construction — no code path in §5 ever calls `attachFundingReceipt()`/writes `business_billing_receipts`.
8. **Every outcome, mutating or not, is durably recorded.** §4.12, enforced by the existing, unmodified `payment_provider_events` schema and claim/disposition algorithm.
9. **No new admin surface, no new customer surface, no origination of any provider-side action.** §4.14/§5's own NOT_REQUIRED table; proven by `ProviderRefundDisputeSurfaceBoundaryTest`.
10. **Every wallet-mutating write is authority-correct and lock-ordered identically to every existing wallet-mutating write.** §4.7, §5 item 7; proven by `UsageWalletManagerReversalTest`.

---

## 10. Bounded reads — restated

`findCreditEntryForFundingAttempt()` and `reversalEntriesForFundingAttempt()` (§5 item 6) are both scoped to a single `funding_attempt_id`, indexed (Migration 3), and return at most a handful of rows by construction (a given funding attempt can be refunded/disputed only a small, real-world-bounded number of times) — neither is a table scan, and neither requires pagination.

---

## 11. Regression commands — for the future, separately authorized implementation phase

Restated here so the implementation phase inherits them exactly, matching this contract-sequence's own established discipline:

1. `php artisan test tests/Feature/Usage/ProviderRefundOutcomeTest.php`
2. `php artisan test tests/Feature/Usage/ProviderDisputeOutcomeTest.php`
3. `php artisan test tests/Feature/Usage/UsageWalletManagerReversalTest.php`
4. `php artisan test tests/Feature/Usage/ProviderRefundDisputeSurfaceBoundaryTest.php`
5. `php artisan test tests/Feature/Usage tests/Unit/Usage` (complete Usage-domain suite — must remain 100% passing; §8's own preserved invariants are exactly what the *existing* suite already guards)
6. One complete `php artisan test --stop-on-failure` run (full repository suite)
7. `git diff --check`

No command beyond these is authorized; no live-provider-hitting command (a real Stripe sandbox/live call) is ever part of this suite, matching every prior RFC-005 contract's own test-design discipline (`FakePaymentProviderGateway` only).

---

## 12. Human decisions — recorded, not guessed

1. **Should a Business be notified (email) when a chargeback debits or puts their wallet into debt?** No existing precedent either way (§4.11) — the RFC's own §29 event/notification list names no such notification, but a chargeback is arguably more consequential to a Business than an ordinary usage overage (which also does not notify). **Recommendation if asked to pick a default: no new notification, for consistency with every existing debit path** — but this is a genuine product judgment, not a fact derivable from the RFC, and is left open pending a human decision rather than assumed.
2. **Should billing_status automatically resume when a dispute is won and funds are reinstated, or does resumption remain administrator-only regardless of outcome?** §4.9 locks the latter (reuse the existing admin-only `resumeBilling` action, add nothing new) as the narrower, more conservative default, consistent with the RFC's own authorization table reserving suspend/resume as an administrator-only capability with no automatic-resume precedent anywhere in the codebase. A human may override this default in favor of automatic resumption if business policy prefers it; doing so would require a small, separately-scoped addition to `reverseDisputeChargebackOutcome()` (an additional `setBillingStatus(..., Active, DisputeWebhook, null, $reason)` call) — not a redesign.
3. **Historical backfill of `provider_payment_intent_reference` for pre-existing Checkout-backed `Succeeded` attempts** (§4.14) — left unbackfilled, failing closed to the existing admin-review queue on first refund/dispute contact. A human may authorize a separate, explicitly-scoped backfill job (bounded, rate-limited, live-Stripe-calling) as its own future remediation if this proves operationally significant; it is not assumed necessary by this contract.
4. **Whether `refund.created`/`refund.updated`/`refund.failed` (the newer, per-operation Refund-object event family, §3) should also be ingested** for finer-grained audit (e.g. a `refund.failed` event, which `charge.refunded` alone would never report, since a failed refund attempt never changes `amount_refunded`) — **not included in this contract's own locked design** (§3's own reasoning: `charge.refunded`'s cumulative field is sufficient for every financial-mutation guarantee this contract makes). A human may authorize ingesting `refund.failed` specifically, purely for audit/administrator visibility of a refund attempt that never took effect, as a small, additive, non-financial-mutation extension if this visibility gap matters operationally.

---

## 13. Coverage matrix — every §4 question, answered, cross-referenced

| Question (from the authoring instruction) | Answered in |
|---|---|
| Which provider refund/dispute webhook/event types enter the system? | §3 |
| How is each event authenticated, normalized, deduplicated, retained, disposed? | §4.7, §4.13 (fully reused, unmodified) |
| How is the affected Business/attempt/charge/wallet/entry identified without cross-Business ambiguity? | §4.1 |
| Full/partial/repeated refunds; dispute created/updated/won/lost/reversed | §4.2, §4.3 |
| Which outcomes change wallet available balance or debt? | §4.4 |
| Which exact ledger entry types are produced; is `UsageChargeReversal`/`CorrectionReversal` required? | §4.3, §4.4, §4.6 — `CorrectionReversal` yes (dispute reversal only); `UsageChargeReversal` no |
| How are amounts bounded? | §4.5 |
| What database uniqueness/idempotency boundary applies? | §4.7 |
| Insufficient available balance? | §4.4, §4.5 (debt formation, identical shape to `UsageOverageCharge`) |
| Receipts/evidence, without fabricating a new successful-payment receipt | §4.10 |
| Domain events/jobs/notifications/audit records | §4.11 |
| Outcomes requiring no mutation but still requiring durable audit | §4.12 |
| Retry/reconciliation/locking/transaction/crash-recovery boundaries | §4.7, §4.13 |
| Which existing admin surface is reused; is any new HTTP/admin action required? | §4.11 — fully reused; none required |
| What intentionally remains out of scope? | §4.14 |

---

## 14. Validation performed before commit

- Full document read back for internal consistency (every cross-reference in §5–§13 resolves to a §4 finding actually stated above it).
- `git diff --check` — clean.
- `git diff --name-only origin/main...HEAD` — exactly this one file.
- Confirmed no product, test, schema, config, route, or RFC-source file changed; `docs/automation/AI-AUTONOMY-STATE.json` untouched.
