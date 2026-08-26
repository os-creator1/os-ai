# RFC-005 Funding Provider-Flow Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction to `UsageBillingCheckoutManager`'s manual-top-up and add-on-purchase flows, replacing an incorrect off-session-PaymentIntent charge with the RFC-005-mandated one-time Checkout Session, and correcting the webhook-processing gap that would otherwise make a correctly-routed Checkout Session funding event unrecognizable. Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch locked in §15.B below, exactly as every RFC-005 milestone and correction contract before it (most recently the Reservation Admission correction, [`RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md`](./RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md), merged PR #134) has required.

This correction exists because a narrow, read-only provider/funding-flow correction-design audit — performed after Milestones 1–5 had already closed and after Reservation Admission (remediation #1 of 7) had already merged — discovered that `UsageBillingCheckoutManager::initiateTopUp()` and `initiateAddonPurchase()` both route through the same private `initiateCharge()` helper, which unconditionally requires a pre-saved default payment instrument and unconditionally calls `PaymentProviderGateway::createOffSessionPaymentIntent()`. RFC-005 §20 explicitly locks a different design: *"top-up/add-on purchase as one-time Checkout Sessions; auto-recharge as an off-session PaymentIntent."* M6 itself remains **BLOCKED** under its own merged Gap Rule (`docs/automation/RFC-005-M6-CONTRACT.md` §3) pending this and five other independently governed corrections (Reservation Admission has already merged). This contract is remediation #2 of 7; it does not by itself unblock M6.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-funding-provider-flow-correction-contract`, in an isolated linked worktree (`../rfc-005-funding-provider-flow-correction-contract-worktree`), based on `origin/main` at `311bf0bf08cd4bf6c0939aec0cdf45962c4bb9de` — the Reservation Admission correction's own merge commit (PR [#135](https://github.com/os-creator1/os-ai/pull/135)), confirmed the current tip of `main` via direct `git rev-parse` before drafting, and confirmed to already contain the merged Reservation Admission contract (PR [#134](https://github.com/os-creator1/os-ai/pull/134), merge `208c3da9faceafd0ae330dd6821a481e72f90192`) and its implementation.
- Confirmed before drafting: no `docs/automation/RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md` exists anywhere in `origin/main`'s history, and no `chore/rfc-005-funding-provider-flow-correction-contract` or `agent/rfc-005-funding-provider-flow-correction` branch exists on `origin` — this is a genuinely new governance object, not a duplicate or reopening of prior work.
- `agent/rfc-005-m6` is confirmed, at drafting time, to remain a **local-only branch — never pushed to `origin`**, carrying **zero authored commits**, and confirmed a direct ancestor of current `origin/main` (`git merge-base --is-ancestor agent/rfc-005-m6 origin/main` succeeds) — it is simply stale relative to a `main` that has since fast-forwarded through the Reservation Admission contract and implementation merges, exactly as its own frozen status requires. This contract does not touch, reset, or recreate that branch in any way.
- **This is a new, independently bounded pre-M6 correction contract — not a correction round against M1–M6's own contracts, and not a correction round against the already-merged Reservation Admission contract.** Its `maximum_correction_rounds: 2` budget is its own, freshly opened, **0 of 2 consumed** at initial drafting. No counter is borrowed or altered on any other contract.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
- Does not modify `docs/automation/AI-AUTONOMY-STATE.json`. Does not amend `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`. Does not touch `docs/automation/RFC-005-M6-CONTRACT.md`. Does not resume M6.

---

## 1. Confirmed defect — locked precisely

**RFC-005 §20** locks the authoritative provider-object matrix (quoted verbatim): *"Checkout Session vs. PaymentIntent — unchanged: top-up/add-on purchase as one-time Checkout Sessions; auto-recharge as an off-session PaymentIntent; the additional-slot agreement's initial charge as a Checkout Session with `setup_future_usage: 'off_session'`, every renewal as an off-session PaymentIntent (§22)."*

**Current implementation** (`app/Library/Usage/UsageBillingCheckoutManager.php`):

| Flow | RFC-005 §20 requires | Current code does |
|---|---|---|
| `initiateTopUp()` (manual top-up) | Checkout Session | Routes through `initiateCharge()` → unconditionally requires `instrumentRepository->findDefaultForProviderCustomer()` (denies `no_payment_instrument` if absent) → unconditionally calls `gateway->createOffSessionPaymentIntent()` |
| `initiateAddonPurchase()` (add-on purchase) | Checkout Session | Routes through the identical `initiateCharge()` helper, same off-session-PaymentIntent path |
| `initiateAutoRecharge()` (auto-recharge) | Off-session PaymentIntent | **Correctly** routes through `initiateCharge()` → off-session PaymentIntent — conforming, must not change |
| `initiateSlotAgreementCheckout()` (initial slot agreement) | Checkout Session, `setup_future_usage: off_session` | **Correctly** calls `gateway->createCheckoutSession()` — conforming, must not change |
| `createScheduledRenewalCharge()`/renewal retries | Off-session PaymentIntent | **Correctly** off-session-PaymentIntent based — conforming, must not change |

A second, independent defect compounds the first: even if `initiateCharge()` were corrected to create a Checkout Session for `ManualTopUp`/`AddonPurchase`, the webhook consumer would still silently fail to recognize its success. `ProcessPaymentProviderEvent::processFundingAttempt()` (the branch that handles every `funding_attempt`-subject-kind event, i.e. every `ManualTopUp`/`AutoRecharge`/`AddonPurchase` webhook) unconditionally requires `array_key_exists('amount', $object)` — the PaymentIntent-only field name — and classifies success/failure only by `.succeeded`/`.payment_failed`/`canceled` `event_type` suffixes. A Checkout Session's own success event, `checkout.session.completed`, carries `amount_total` (never a top-level `amount`) and ends in `.completed`, not `.succeeded`. **A correctly-routed Checkout Session funding webhook, as written today, falls through every recognized branch and silently reaches `markIgnored()` — the funding attempt would never converge to `succeeded`, and the wallet would never be credited, regardless of the first defect being fixed.** This second defect is confirmed **not** hypothetical: `ProcessPaymentProviderEvent::processSlotAgreementInitialCheckout()` (the already-correct sibling branch for the initial slot-agreement Checkout Session) already implements the exact `amount`/`amount_total` fallback and the exact `.completed`/`.async_payment_succeeded` event-type classification that `processFundingAttempt()` is missing — proving the correct pattern already exists in this codebase, unreused by the one branch that needs it.

**Why this was never caught:** `initiateCharge()`'s docblock (M3 contract §11) describes it as "the manual top-up state machine," written before M4 introduced the additional-slot Checkout Session pattern; M4 correctly built a *separate* Checkout Session code path (`initiateSlotAgreementCheckout()`/`confirmSlotAgreementChecked()`/`processSlotAgreementInitialCheckout()`) for the slot agreement, but never revisited `initiateCharge()` to bring `ManualTopUp`/`AddonPurchase` in line with the same corrected design — RFC-005 §20's own top-up/add-on requirement predates M4 and was simply never revisited once M4 shipped the first real Checkout Session implementation to model it on. Every M3/M4 regression gate passed because every existing test for `initiateTopUp()`/`initiateAddonPurchase()` was itself written against the (incorrect) off-session-PaymentIntent behavior, so the tests and the code agreed with each other while both disagreed with RFC-005 §20.

Do not describe this as an accounting, ledger, entitlement, or reservation-admission defect. It is exclusively a provider-object selection and webhook-classification defect, fully external to `UsageWalletManager`.

---

## 2. Entitlement / wallet-admission boundary — untouched, unaffected

This correction touches only `UsageBillingCheckoutManager` (funding-attempt creation and confirmation), `PaymentProviderGateway` (the Stripe boundary), and `ProcessPaymentProviderEvent` (webhook classification). It has no interaction whatsoever with:

- `App\Library\Usage\UsageWalletManager` — reservation admission, spend caps, feature limits, safety limits (already corrected by the Reservation Admission remediation, remediation #1).
- `App\Library\Entitlement\EntitlementManager::decide()` / `RealUsageAuthorizationGateway::check()` — feature availability.

A funded wallet's *availability to spend* is entirely orthogonal to *how the wallet gets funded* — this correction only changes the second. No entitlement, reservation, or spend-admission code path is read, called, or modified by this correction.

---

## 3. Funding-attempt model — authoritative, unchanged, no schema

`business_funding_attempts` remains the sole durable model for every funding-causing charge (`ManualTopUp` | `AutoRecharge` | `AddonPurchase`, RFC-005 §17.C). Its `provider_session_or_intent_reference` column already exists as a plain nullable-unique string with no type constraint of its own — it is documented (RFC-005 §17.C) to hold "either the initial Checkout Session id" or a PaymentIntent id, according to purpose. **No migration or schema change is required or authorized by this correction.** The correction only changes *which kind* of provider-object reference this column receives for two of the three existing purposes — a pure behavioral change inside `UsageBillingCheckoutManager`, never a new model, never a new table, never a new column.

---

## 4. Purpose → provider-object dispatch — the one authoritative rule

**Locked, exactly:**

| `FundingAttemptPurpose` | Provider object | Gateway method |
|---|---|---|
| `ManualTopUp` | Checkout Session | `createCheckoutSession()` |
| `AddonPurchase` | Checkout Session | `createCheckoutSession()` |
| `AutoRecharge` | off-session PaymentIntent | `createOffSessionPaymentIntent()` (**unchanged**) |

`purpose` is the persisted local authority for this dispatch — never `event_type` (RFC-005 §17.C's own corrected resolution algorithm already forbids inferring local subject/purpose from `event_type`; this correction extends that same discipline to *provider-object family* selection, not only local-row resolution).

**The correction may refactor `initiateCharge()` internally or split provider initiation into private helpers, subject to:**

- One durable funding-attempt state machine remains (`FundingAttemptState`, unchanged: `created → provider_pending → requires_action → processing → succeeded → failed → canceled → refunded → disputed`).
- One authoritative accounting-success path remains — `confirmSucceeded()`'s own `creditFromFunding()` call, never duplicated.
- No duplicate add-on-finalization implementation — `finalizeAddonPurchaseIfPending()` remains the sole path.
- No controller may call Stripe/the gateway directly — every outbound provider call remains inside `UsageBillingCheckoutManager`, behind `PaymentProviderGateway`.

---

## 5. Redirect-result DTO — the exact, smallest coherent change

**Current facts, confirmed by direct inspection:**

- `FundingAttemptResult` (`app/Library/Usage/FundingAttemptResult.php`): `fundingAttemptId`, `state`, `denialReason`. No redirect field.
- `AddonPurchaseResult` (`app/Library/Usage/AddonPurchaseResult.php`): `addonPurchaseId`, `fundingAttemptId`, `state`, `denialReason`. No redirect field.
- `CheckoutSessionResult` (the gateway's own return shape for `createCheckoutSession()`/`retrieveCheckoutSession()`) already carries `redirectUrl` (nullable — populated only while a Session is `'open'`, per its own existing docblock).
- `SlotAgreementCheckoutResult` already carries `redirectUrl`, populated by `initiateSlotAgreementCheckout()` directly from `$session->redirectUrl` and returned to the controller — this is the exact, already-precedented shape to mirror.

**Locked design:** add `public ?string $redirectUrl` to `FundingAttemptResult`, populated only by the code path that creates a Checkout Session (`ManualTopUp`/`AddonPurchase`'s own `initiateCharge()` branch), `null` for every other path (`AutoRecharge`, and any already-succeeded/failed/no-provider-customer/no-instrument early return) — mirroring `SlotAgreementCheckoutResult`'s own precedent exactly: populated once, at Session-creation time, never repopulated by a later confirmation call. `AddonPurchaseResult` gains the identical nullable `redirectUrl` field, propagated from the underlying `FundingAttemptResult` `initiateAddonPurchase()` already receives internally — one additional constructor argument on each DTO, no new type, no gateway/controller boundary violation, and no other DTO (`CheckoutSessionResult`, `SlotAgreementCheckoutResult`, `PaymentIntentResult`) requires any change.

This directly answers §8's own question: a **future** authorized add-on-purchase HTTP caller reaches the hosted Checkout URL through this same `AddonPurchaseResult->redirectUrl` field — no new type, no gateway/controller boundary violation, and no premature HTTP surface is built now (§8 below).

---

## 6. Manual top-up — corrected HTTP lifecycle

```
payer-authorized actor submits amount
  → assertChargeCausingConsent() (unchanged, §16 payer-consent gate)
  → local funding attempt created (state: created), inside a short transaction, no provider call yet
  → OUTSIDE that transaction: gateway->createCheckoutSession(
        providerCustomerId, minorUnits, currencyCode,
        lineItemName: 'Wallet top-up' (or equally truthful, non-invented label),
        successUrl: a new dedicated confirmation route (below),
        cancelUrl: the existing Usage Billing dashboard route,
        idempotencyKey: the attempt's own local_idempotency_key,
        metadata: { app_subject_kind: 'funding_attempt', app_subject_id: <attempt id>, app_operation_id: <local_idempotency_key> },
    )
  → Session id persisted on provider_session_or_intent_reference; state → provider_pending
  → FundingAttemptResult.redirectUrl returned to the controller
  → controller redirects the browser to the Stripe-hosted Checkout URL (never renders/handles payment details itself)
  → browser returns to the new confirmation route — the return is NOT trusted alone
  → confirmAttemptFromReturn() retrieves the Checkout Session via gateway->retrieveCheckoutSession()
  → verifies the same eight conditions confirmSlotAgreementChecked()/slotAgreementCheckoutVerified() already
    verify for the slot-agreement Checkout Session (status: complete, payment_status: paid, matching
    Session id/amount/currency/provider-customer, non-null PaymentIntent + PaymentMethod references)
  → on success: confirmSucceeded() — the existing, single accounting-success path (creditFromFunding())
  → duplicate return/webhook remains idempotent via the existing already-Succeeded early return
```

**Locked: the Checkout-selected PaymentMethod is never synced into `business_payment_instruments` and never becomes the Business's/Workspace's new default instrument for a one-time top-up** (§9 explains why). `confirmAttemptFromReturn()`'s corrected verification step therefore checks `providerPaymentIntentId !== null` (proof a real charge occurred) but does **not** call `PaymentInstrumentManager::syncWorkspaceCheckoutPaymentMethod()` or any equivalent — that method is deliberately Workspace-scoped and exists specifically to support *future off-session reuse* (slot-agreement renewals), which a one-time top-up must never silently establish.

**New route, reusing the existing controller and the existing Business-scoped Usage Billing routing convention** — mirroring `additional-business-slots/{agreement}/confirm`'s own exact shape:

```
Route::get('{workspaceUid}/businesses/{businessUid}/usage-billing/top-up/{attempt}/confirm',
    'Business\UsageBillingTopUpController@confirmFromReturn')
    ->name('businesses.usage-billing.top-up.confirm')
    ->whereNumber('attempt');
```

`UsageBillingTopUpController` gains one new action, `confirmFromReturn(int $attempt)`, resolving the attempt scoped to the already-viewable Business (reusing `resolveViewableBusiness()`), calling `confirmAttemptFromReturn()`, and redirecting to the existing Usage Billing dashboard with a flash message — no new controller class, matching the task's own explicit preference. `initiate()` itself is corrected to redirect to `$result->redirectUrl` (via `redirect()->away(...)`) instead of the current synchronous "Top-up initiated" success flash, since a Checkout Session flow cannot complete synchronously within the initiating request.

**No pre-saved default instrument required.** `initiateSlotAgreementCheckout()` — the RFC's own existing, correctly-conforming Checkout Session flow — never calls `instrumentRepository->findDefaultForProviderCustomer()` at all; it only requires a resolved `payment_provider_customers` row (created lazily if absent). The corrected `initiateTopUp()`/`initiateAddonPurchase()` path must drop the `no_payment_instrument` pre-check entirely — a Checkout Session collects payment information customer-present, at Stripe's own hosted page, exactly as the slot-agreement flow already proves works without any pre-saved card (§10 locks this exactly).

---

## 7. Add-on purchase — HTTP remains non-blocking, provider object corrected

**Preserved conclusion, unchanged by this correction:** M4 shipped `business_usage_addon_catalog` with zero seeded rows and no HTTP surface by deliberate design (M4 contract §10) — a real commercial add-on catalog is a human product decision, not a technical gap. **This correction creates no new add-on route, controller, view, `addon_key`, price, or seeded catalog row.**

**What this correction does change:** `initiateAddonPurchase()` → `initiateCharge()` with `purpose: AddonPurchase` now creates a Checkout Session (§4/§5), and the resulting `redirectUrl` is threaded through `AddonPurchaseResult` (§5) so that a **future**, separately authorized add-on HTTP caller can redirect to it without ever needing to reach into the gateway or into `UsageBillingCheckoutManager`'s own internals — the manager/controller boundary is preserved for that future caller by construction, not merely by convention.

Because `initiateAddonPurchase()`'s own `postAttemptCreationHook` already creates the linked `business_usage_addon_purchases` row inside the same short transaction, *before* the outbound Checkout Session call (M4 Correction Round 1's own closed replay-hole fix, §D) — this ordering is unaffected by the provider-object change and requires no modification.

---

## 8. `setup_future_usage` — locked resolution

**RFC-005 §20, quoted exactly:** *"...the additional-slot agreement's initial charge as a Checkout Session with `setup_future_usage: 'off_session'`, every renewal as an off-session PaymentIntent."* **`setup_future_usage: 'off_session'` is scoped, by the RFC's own words, to the additional-slot agreement's initial charge alone — it is never mentioned for top-up or add-on purchase**, which §20's own preceding clause independently and separately describes as "one-time Checkout Sessions," with no future-use qualifier of any kind.

**Resolution: Option B — a one-time payment, never automatically establishing a new future-use instrument.**

- **Why:** the slot agreement's `setup_future_usage: 'off_session'` exists for exactly one documented reason — it has a *known, designed future off-session renewal* (§22's own recurring renewal-charge machinery). A manual top-up or add-on purchase has no such designed recurrence; RFC-005 §19 draws its own hard line here — auto-recharge is a distinct, separately-consented, ongoing authorization (§16's "consent extended to every charge-causing action" rule; §19's own narrowed platform-administrator posture: *"an administrator may never unilaterally enable auto-recharge for a Business on the customer's behalf"*). Silently attaching `setup_future_usage` to a one-time top-up's PaymentIntent would establish exactly the kind of standing off-session authority §19 requires a **separate, explicit** payer action to grant.
- **Payer-consent implication:** a payer completing a one-time top-up Checkout Session consents only to that one charge. Establishing reusable off-session authority is a materially different consent, already gated by its own distinct action (`configureAutoRecharge()`/`PaymentInstrumentManager::createSetupIntent()`+attach). Conflating the two would let a top-up silently grant authority the payer never explicitly asked for.
- **No existing instrument is required or consumed** (§10 confirms — the Checkout Session collects payment information customer-present).
- **The Checkout-selected PaymentMethod is not synced locally** (§6) — since it was never authorized for future use, persisting it as a reusable `business_payment_instruments` row would be actively misleading (a card the payer may not have intended to leave on file).
- **The default instrument does not change** as a side effect of a top-up/add-on purchase.
- **Auto-recharge is never silently enabled.** `configureAutoRecharge()` remains the sole, separately-consented path to enabling it, entirely untouched by this correction (§14).

**Exact minimal interface widening:** `PaymentProviderGateway::createCheckoutSession()` gains one new parameter, `bool $setupFutureUsageOffSession = false`, defaulting to the narrower, no-side-effect behavior. `StripePaymentProviderGateway::createCheckoutSession()`'s `payment_intent_data.setup_future_usage` key is included only when this parameter is `true`. `initiateSlotAgreementCheckout()` (the only existing caller) is updated to pass `true` explicitly, preserving its own byte-identical outbound Stripe request; `initiateCharge()`'s new Checkout-Session branch (`ManualTopUp`/`AddonPurchase`) passes `false` (or omits the argument, taking the default). `FakePaymentProviderGateway::createCheckoutSession()` accepts and — since it never actually calls Stripe — safely ignores the same parameter, remaining fully conforming to the widened interface without behavioral change (its existing `checkoutSessionOutcomes` fixture-configuration mechanism already governs every observable test outcome).

---

## 9. Saved-instrument requirement — locked, independently, per purpose

| Purpose | Requires a pre-saved default instrument? |
|---|---|
| `ManualTopUp` | **No** — Checkout Session collects payment information customer-present, exactly as the already-conforming slot-agreement flow proves |
| `AddonPurchase` | **No** — identical reasoning |
| `AutoRecharge` | **Yes, unchanged** — an off-session charge is structurally impossible without an already-attached, reusable payment instrument; this requirement is intrinsic to off-session PaymentIntent charging, not an artifact of the defect this correction fixes |

The corrected `initiateCharge()` moves the `instrumentRepository->findDefaultForProviderCustomer()` lookup (and its `no_payment_instrument` denial) to apply **only** on the `AutoRecharge` branch. The `ManualTopUp`/`AddonPurchase` branch requires only a resolved `payment_provider_customers` row (creating one lazily if absent, mirroring `quoteAdditionalSlotAgreement()`'s own existing lazy-creation pattern) — never an instrument.

---

## 10. Webhook raw-evidence validation — exact per-object-family rules

`ProcessPaymentProviderEvent::processFundingAttempt()` is corrected to branch on the **already-loaded** `$attempt->purpose` (never `event_type`) to select the expected provider-object family, mirroring `processSlotAgreementInitialCheckout()`'s own already-correct pattern exactly:

| `$attempt->purpose` | Amount field | Success event-type suffix(es) | Failure/cancellation event-type suffix(es) |
|---|---|---|---|
| `AutoRecharge` | `amount` (unchanged) | `.succeeded` (unchanged) | `.payment_failed`, `canceled` (unchanged) |
| `ManualTopUp` / `AddonPurchase` | `amount_total` | `.completed`, `.async_payment_succeeded` | `.expired` |

**Never trust metadata alone** — every existing validation step is preserved unconditionally, regardless of provider-object family: provider object id (`provider_session_or_intent_reference` match), operation id (`local_idempotency_key` match against `app_operation_id`), amount, currency, customer, local scope (Business ownership via the attempt row itself), and local expected state (a valid forward transition). **Missing required evidence still fails closed** — a `ManualTopUp`/`AddonPurchase` event missing both `amount` and `amount_total` marks the event `failed` with `missing_required_evidence`, exactly as `processSlotAgreementInitialCheckout()`'s own existing `! array_key_exists('amount', ...) && ! array_key_exists('amount_total', ...)` guard already does — this correction reuses that exact guard shape, not a weaker one.

---

## 11. Checkout success/failure event classification — exact audit

Audited against Stripe's documented Checkout Session and PaymentIntent event families:

| Event | Confirms success | Terminal failure/cancellation | Ignored (reconciliation-only) |
|---|---|---|---|
| `checkout.session.completed` | **Yes**, for `ManualTopUp`/`AddonPurchase` | — | — |
| `checkout.session.async_payment_succeeded` | **Yes** (delayed-payment-method completion, e.g. certain bank debits) | — | — |
| `checkout.session.expired` | — | **Yes** — the Session was never completed and can never be completed later | — |
| `payment_intent.succeeded` | **Yes**, for `AutoRecharge` only (unchanged) | — | — |
| `payment_intent.payment_failed` | — | **Yes**, for `AutoRecharge` only (unchanged) | — |
| `payment_intent.requires_action` | — | — | **Yes** — provider-object lifecycle information only, unchanged for every purpose |

`checkout.session.async_payment_succeeded` is included in the "confirms success" suffix set (`.async_payment_succeeded`) as the direct sibling of `processSlotAgreementInitialCheckout()`'s own existing handling of the identical event for the slot-agreement flow — reused, not invented. `checkout.session.expired`'s failure path reuses the existing `markAttemptFailedFromWebhook()` method verbatim (already purpose-agnostic — it only inspects `$attempt->state`), so no new manager method is required.

**Never route by `event_type` alone.** Every classification above is reached only after `$attempt->purpose` has already selected the expected object family (§10) and every other field-level validation has already passed — `event_type` determines only which lifecycle transition a *already-confirmed-relevant* event represents, exactly as RFC-005 §17.C/§21 already require.

---

## 12. Return + webhook convergence — idempotency, unchanged shape

Both `confirmAttemptFromReturn()` (browser return) and `confirmAttemptFromWebhook()` (webhook) already converge on the identical `confirmSucceeded()` accounting path, and both already share the identical already-Succeeded early-return guard (including its M4-Correction-Round-1 `finalizeAddonPurchaseIfPending()` idempotent repair for the add-on replay hole). **This correction changes nothing about that convergence** — it only changes which gateway method each path calls to retrieve the provider object's current state (`retrieveCheckoutSession()` vs. `retrievePaymentIntent()`, selected by `$attempt->purpose`, mirroring `confirmSlotAgreementFromReturn()`/`confirmSlotAgreementFromWebhook()`'s own existing purpose-free dual-caller convergence onto `confirmSlotAgreementChecked()`). No double wallet credit, no double ledger entry, no double funding transition, no double add-on completion — all already proven by the existing `UniqueConstraintViolationException`-guarded `creditFromFunding()` correlation-key mechanism and the already-Succeeded/already-Completed early returns, none of which this correction touches.

**One additional method requires the identical purpose-aware branching, discovered during this audit and not named by the task's own initial candidate list: `retryFundingAttemptAsAdministrator()`.** It currently calls `$this->gateway->retrievePaymentIntent($attempt->provider_session_or_intent_reference)` unconditionally — correct today only because every attempt is PaymentIntent-backed. Once `ManualTopUp`/`AddonPurchase` attempts become Checkout-Session-backed, an administrator resuming a stuck `ManualTopUp`/`AddonPurchase` attempt (state: `provider_pending`, never confirmed) must retrieve a **Checkout Session**, not a PaymentIntent — calling `retrievePaymentIntent()` against a Checkout Session id would either error or silently misresolve. **This correction extends `retryFundingAttemptAsAdministrator()` with the identical purpose-based branch** (`retrieveCheckoutSession()` + the same eight-condition verification for `ManualTopUp`/`AddonPurchase`, `retrievePaymentIntent()` unchanged for `AutoRecharge`) — a mechanically necessary, narrowly-scoped extension of an already-authorized method, not a new capability or a new authorization posture (§16/§24's platform-administrator resume-only rule is completely unchanged).

---

## 13. Auto-recharge — must not regress

`initiateAutoRecharge()`'s own dispatch to `initiateCharge()` with `purpose: AutoRecharge` is **unchanged** — it continues to require a pre-saved default instrument and continues to call `createOffSessionPaymentIntent()`/`retrievePaymentIntent()`. The only code `AutoRecharge` shares with the corrected `ManualTopUp`/`AddonPurchase` branches is the already-existing outer scaffolding (`initiateCharge()`'s wallet lookup, provider-customer resolution, outstanding-attempt idempotency check, attempt-row creation, transition recording) — none of which changes shape, only which inner branch (instrument-required-PaymentIntent vs. instrument-free-CheckoutSession) executes for a given `purpose`. Every existing threshold, monthly-recharge-cap, outstanding-attempt-protection, `requires_action`, and payer/instrument behavior for `AutoRecharge` remains byte-for-byte unchanged, and the required regression proof (§18) is every existing `AutoRecharge`-scoped test passing unmodified.

---

## 14. Explicitly excluded — Receipts

`business_billing_receipts`, any receipt model/repository, any `receiptUrl` DTO property, any `latest_charge` expansion, and `SendReceiptNotification` are **out of scope**. This correction's `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()` corrections touch only provider-object retrieval/verification and the existing `confirmSucceeded()` credit path — no new field is added to any DTO or model for receipt purposes, and no receipt-specific branch of any kind is introduced. Receipt Boundary (remediation #3) extends this corrected foundation afterward, entirely independently.

---

## 15. Explicitly excluded — Funding events

`BusinessFundingAttemptSucceeded`/`BusinessFundingAttemptFailed` (RFC-005 §29) are **not** dispatched by this correction. They remain owned by Job/Event Dispatch Completion (remediation #4). This correction's `confirmSucceeded()`/`markFailed()` methods gain no new `Event::dispatch()` call of any kind — provider-flow correctness is independently testable, and independently tested (§18), without them.

---

## 16. Candidate production path audit — REQUIRED / NOT REQUIRED, individually

| Path | Verdict | Reason |
|---|---|---|
| `app/Library/Usage/UsageBillingCheckoutManager.php` | **REQUIRED** | `initiateCharge()` purpose-aware dispatch (§4/§9), `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()` purpose-aware verification (§6/§12), `retryFundingAttemptAsAdministrator()` purpose-aware resume (§12) |
| `app/Library/Usage/Contracts/PaymentProviderGateway.php` | **REQUIRED** | `createCheckoutSession()` gains the one new `bool $setupFutureUsageOffSession = false` parameter (§8) |
| `app/Library/Usage/StripePaymentProviderGateway.php` | **REQUIRED** | Implements the widened interface; conditionally includes `payment_intent_data.setup_future_usage` (§8) |
| `app/Library/Usage/FakePaymentProviderGateway.php` | **REQUIRED** | Must accept the widened interface signature to remain conforming (§8) — no behavioral change to its existing fixture mechanism |
| `app/Library/Usage/FundingAttemptResult.php` | **REQUIRED** | Adds nullable `redirectUrl` (§5) |
| `app/Library/Usage/AddonPurchaseResult.php` | **REQUIRED** | Adds the identical nullable `redirectUrl` (§5/§7) |
| `app/Library/Usage/PaymentInstrumentManager.php` | **NOT REQUIRED** | No change needed — the correction's own design (§6/§8) deliberately never calls `syncWorkspaceCheckoutPaymentMethod()` (or any equivalent) for a one-time top-up/add-on; this file is read, not modified |
| `app/Jobs/Usage/ProcessPaymentProviderEvent.php` | **REQUIRED** | `processFundingAttempt()` purpose-aware amount-field/event-type-suffix branching (§10/§11) |
| `app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php` | **REQUIRED** | New `confirmFromReturn()` action; `initiate()` redirects to `$result->redirectUrl` instead of a synchronous success flash (§6) |
| `routes/customer.php` | **REQUIRED** | One new `GET .../top-up/{attempt}/confirm` route (§6) |
| `app/Library/Usage/CheckoutSessionResult.php` | **NOT REQUIRED** | Already carries every field the corrected verification logic needs (`redirectUrl`, `status`, `paymentStatus`, `amountMinorUnits`, `currencyCode`, `providerCustomerId`, `providerPaymentIntentId`, `providerPaymentMethodId`) — confirmed independently of any future Receipt Boundary need |

**Explicitly out of scope, none required:** database migrations (§3), new add-on HTTP/admin HTTP of any kind (§7), receipt code (§14), wallet admission code (§2), job/event completion code (§15), refund/dispute code (a separate remediation, #6), `docs/automation/AI-AUTONOMY-STATE.json`, package files.

---

## 17. Test authority — REQUIRED / NOT REQUIRED, individually, with exact reasoning

| File | Verdict | Reasoning |
|---|---|---|
| `tests/Feature/Usage/TopUpStateMachineTest.php` | **REQUIRED — modification, not only addition** | Every existing test in this file asserts the *current, incorrect* off-session-PaymentIntent lifecycle: `test_successful_top_up_transitions_created_to_provider_pending_to_succeeded` asserts a synchronous `created → provider_pending → succeeded` sequence a Checkout Session cannot produce (a Session is never synchronously `complete` at creation); `test_declined_card_transitions_to_failed_with_a_reason` and `test_requires_action_leaves_the_attempt_pending_authentication` assert mid-*creation* PaymentIntent outcomes that do not apply to Session *creation* (a Session almost always creates successfully; decline/3DS outcomes surface only at confirmation time); `test_no_payment_instrument_denies_the_attempt_without_creating_a_provider_call` asserts a denial rule §9 explicitly removes for `ManualTopUp`. These assertions must be corrected to match the corrected lifecycle (Session creation → `provider_pending` → a separate `confirmAttemptFromReturn()` call → `succeeded`/failure), not left describing behavior this correction deliberately changes. `test_no_provider_customer_denies_the_attempt` and `test_repeat_commit_on_an_already_succeeded_attempt_is_idempotent` remain valid as-is (purpose-agnostic). |
| `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` | **REQUIRED — modification, not only addition** | Every crash/replay test in this file assumes `initiateAddonPurchase()` reaches `Succeeded` **synchronously**, inside the initiating call itself ("the first delivery already completed the purchase synchronously via `confirmSucceeded()` inside `initiateAddonPurchase()` itself") — true only for the current off-session-PaymentIntent design. Once `AddonPurchase` is Checkout-Session-backed, the attempt reaches `provider_pending` at creation and only reaches `Succeeded` via a later, explicit `confirmAttemptFromWebhook()`/`confirmAttemptFromReturn()` call. Each test's own setup must be corrected to drive that explicit confirmation step before asserting the crash/replay behavior it actually tests — the replay-hole proof itself (§12, unchanged) remains valid once the setup reflects the corrected two-step lifecycle. `test_manual_top_up_already_succeeded_no_op_behavior_is_unchanged` requires the identical setup correction. |
| `tests/Feature/Usage/FundingAttemptPayerConsentTest.php` | **PARTIALLY REQUIRED** | `test_workspace_owner_can_initiate_a_top_up_when_workspace_pays`, `test_direct_business_owner_cannot_initiate_a_top_up_when_workspace_pays`, `test_direct_business_owner_can_initiate_a_top_up_when_business_pays` remain valid unmodified — the payer-consent check runs before any provider-object decision and its own assertions (`no_provider_customer`/`UnauthorizedPayerAssignmentException`) are purpose-agnostic. `test_platform_administrator_can_resume_a_stuck_attempt` **requires correction** — it forces `paymentIntentOutcomes = ['*' => 'requires_action']` on a `ManualTopUp` attempt, a state a Checkout-Session-backed attempt cannot reach via that mechanism; it must instead force a `provider_pending`, unconfirmed Checkout Session and exercise `retryFundingAttemptAsAdministrator()`'s new Checkout-Session branch (§12). |
| `tests/Feature/Usage/FakePaymentProviderGatewayTest.php` | **Verify at implementation time; likely REQUIRED for the widened signature only** | Must be checked against the corrected `createCheckoutSession()` signature (§8); if it asserts against the interface's parameter list directly it needs the one new parameter reflected, otherwise unaffected — `FakePaymentProviderGateway`'s own observable behavior is unchanged by this correction. |
| `tests/Feature/Usage/WebhookDuplicateEventReplayTest.php` | **NOT REQUIRED** | Tests generic `provider_event_id` deduplication via `UNIQUE(provider, provider_event_id)`, entirely orthogonal to funding-attempt purpose or provider-object family. |
| `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` | **REQUIRED — fixture-level correction only, proof structure unchanged** | `createPendingAttempt()`'s own mechanism for producing a "pending, not yet confirmed" `ManualTopUp` attempt (`paymentIntentOutcomes = ['*' => 'requires_action']`) no longer applies once `initiateTopUp()` never calls `createOffSessionPaymentIntent()`; it must instead configure a `provider_pending`, not-yet-`complete` `checkoutSessionOutcomes` entry. The race/lock proof itself — two workers racing `confirmAttemptFromReturn()` against the same Business, each crediting exactly once — is unaffected and requires no redesign. |
| Directly related Checkout Session / slot tests (`AdditionalBusinessSlotAgreement*Test`, etc.) | **Precedent only, NOT REQUIRED** | Read for their established Checkout Session fixture/verification patterns; the slot-agreement flow itself is unchanged by this correction and its own tests require no modification. |

**New, narrowly-named tests required** (added to the files above, not a new file, unless a file's own scope genuinely cannot host one cleanly):

- `test_manual_top_up_creates_a_checkout_session_not_a_payment_intent` — asserts the gateway received a `createCheckoutSession()` call (via a `FakePaymentProviderGateway` call-recording addition, or by asserting the resulting attempt's `provider_session_or_intent_reference` matches a `cs_fake_*`-shaped id) and never an off-session PaymentIntent call, for `ManualTopUp`.
- `test_addon_purchase_creates_a_checkout_session_not_a_payment_intent` — identical, for `AddonPurchase`.
- `test_auto_recharge_still_creates_an_off_session_payment_intent` — the explicit regression guard for §13.
- `test_manual_top_up_succeeds_without_a_pre_saved_default_instrument` — the direct regression test for §9's removed `no_payment_instrument` check, superseding `TopUpStateMachineTest`'s own now-incorrect denial test.
- `test_initiate_top_up_returns_a_hosted_redirect_url` — asserts `FundingAttemptResult->redirectUrl` is non-null and matches the fake gateway's own Session URL shape.
- `test_confirm_from_return_never_trusts_the_query_string_alone` — asserts `confirmAttemptFromReturn()` calls `retrieveCheckoutSession()` (via the fake's own call-recording, or by configuring a `checkoutSessionOutcomes` mismatch and asserting denial) rather than trusting any browser-supplied parameter.
- `test_checkout_session_completed_webhook_confirms_a_manual_top_up` / `..._an_addon_purchase` — the direct regression test for §1's second confirmed defect: a `checkout.session.completed` event for a `ManualTopUp`/`AddonPurchase` attempt reaches `succeeded` and credits the wallet exactly once.
- `test_checkout_amount_total_is_validated_against_the_expected_amount` — an `amount_total` mismatch on a `ManualTopUp`/`AddonPurchase` event is rejected exactly as an `amount` mismatch already is for `AutoRecharge`.
- `test_wrong_amount_currency_customer_object_or_operation_is_rejected_for_checkout_events` — the full existing mismatch-rejection matrix, re-asserted against the Checkout-Session shape.
- `test_a_checkout_session_event_cannot_confirm_an_auto_recharge_attempt` / `test_a_payment_intent_event_cannot_confirm_a_manual_top_up_or_addon_purchase_attempt` — the direct regression test for §10's purpose-based (never event-type-based) family selection: an event of the wrong shape for the loaded attempt's own purpose is rejected, never coerced.
- `test_duplicate_webhook_and_browser_return_credit_a_checkout_backed_top_up_exactly_once` — the Checkout-Session-backed sibling of the existing PaymentIntent-backed idempotency proof.
- `test_platform_administrator_cannot_originate_a_fresh_top_up_via_checkout_session` — re-confirms RFC-005 §16's already-locked narrowing is unaffected by the provider-object change (no new administrator capability was introduced).
- `test_completing_a_top_up_never_enables_auto_recharge` — the direct regression test for §8's core invariant.
- `test_existing_slot_agreement_checkout_flow_is_unaffected_by_the_widened_gateway_signature` — the direct regression test for §8's interface-widening safety (the slot agreement's own `setup_future_usage: true` call site still produces the byte-identical outbound request).

**Do not push general §35 cleanup into this correction** — every test change above is scoped exclusively to the provider-object/webhook-classification defect this contract locks; no unrelated test in any of these files may be rewritten or weakened.

---

## 18. Implementation test gates

At minimum, the future implementation must run and report:

```
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan migrate:fresh --env=testing
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Unit/Usage tests/Feature/Usage
git diff --check
```

`migrate:fresh --env=testing` is required as environment hygiene (the established, repeatedly-used pattern throughout this engagement) despite this correction authorizing no schema change — it guarantees a clean baseline before the focused suite runs, and cheaply confirms the "no migration" claim (§3/§16) by construction: if a migration were mistakenly introduced, this same command would surface it. No live Stripe call is permitted in any automated test — every test uses `FakePaymentProviderGateway`, exactly as every existing M3/M4 test already does. **These focused gates do not replace M6's later six-gate regression.** After every pre-M6 correction eventually merges, M6 will rerun all six gates from corrected `main` (§22).

---

## 19. Relationship to the other five remaining remediations

This correction does **not** authorize, and its merge does **not** by itself unblock M6 or begin:

- Receipt Boundary (remediation #3)
- Job/Event Dispatch Completion (remediation #4)
- Admin Usage Billing Surface (remediation #5)
- Provider Refund/Dispute Outcome Handling (remediation #6)
- §35 Test-Coverage Completion (remediation #7)

Each remains a separate, independently governed future document. M6 remains frozen until every required remediation is merged and a fresh static conformance audit passes.

---

## 20. M6 resumption rule — unchanged

This contract does not touch `agent/rfc-005-m6`. After **all** separately-authorized pre-M6 corrections are eventually merged: discard/reset the zero-commit old `agent/rfc-005-m6`, recreate it fresh from corrected `origin/main`, repeat full static conformance from scratch, rerun all six M6 regression gates, write both M6 documents, obtain human merge, run the post-merge exact-tag-candidate gate, and only then seek separate explicit human authorization for the annotated tag. This contract does not itself resume M6, and does not authorize any of those steps.

---

## 21. Contract PR scope

This governance branch changes exactly **one** file:

```
docs/automation/RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md
```

Not modified by this PR: `docs/automation/RFC-005-M6-CONTRACT.md`, `docs/automation/AI-AUTONOMY-STATE.json`, `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`, any `app/**`, `tests/**`, `database/**`, `routes/**`, `config/**`, `resources/**`, workflow, or package file.

Before commit: `git diff --check` clean; exactly one changed path confirmed via `git diff --name-only origin/main`.

Commit title: `docs: define RFC-005 funding provider-flow correction contract`.

Push normally to `chore/rfc-005-funding-provider-flow-correction-contract`. No force push. Open a Draft PR if tooling permits; otherwise provide the exact compare URL. Do not mark Ready. Do not merge.

---

## 22. Future implementation gates — restated for the implementation PR

The eventual, separately-authorized implementation PR on `agent/rfc-005-funding-provider-flow-correction` must, at minimum:

1. Confirm the cumulative diff is a subset of exactly the REQUIRED paths in §16 (production) and the REQUIRED files in §17 (tests) — no eleventh production path, no additional test file beyond the ones named REQUIRED or PARTIALLY REQUIRED above.
2. Run `migrate:fresh --env=testing`, then `artisan test tests/Unit/Usage tests/Feature/Usage`, then `git diff --check` (§18), recording exact test/assertion/runtime counts, zero failures, exit 0.
3. Confirm every `AutoRecharge`-scoped existing test still passes unmodified (§13's regression proof).
4. Confirm the existing slot-agreement Checkout Session flow's own tests still pass unmodified (§17's precedent-only row).
5. Confirm `AI-AUTONOMY-STATE.json`, package files, migrations, and RFC/governance docs remain untouched.
6. Confirm this correction's own two-round budget (§0) remains unconsumed unless a genuine contradiction required a human-reviewed correction round.

This implementation-time gate does not replace M6's later six-gate regression (§18/§20).
