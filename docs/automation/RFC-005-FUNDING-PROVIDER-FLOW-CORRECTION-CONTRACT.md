# RFC-005 Funding Provider-Flow Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction to `UsageBillingCheckoutManager`'s manual-top-up and add-on-purchase flows, replacing an incorrect off-session-PaymentIntent charge with the RFC-005-mandated one-time Checkout Session, and correcting the webhook-processing gap that would otherwise make a correctly-routed Checkout Session funding event unrecognizable. Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch named in §0 below, exactly as every RFC-005 milestone and correction contract before it (most recently the Reservation Admission correction, [`RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md`](./RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md), merged PR #134) has required.

This correction exists because a narrow, read-only provider/funding-flow correction-design audit — performed after Milestones 1–5 had already closed and after Reservation Admission (remediation #1 of 7) had already merged — discovered that `UsageBillingCheckoutManager::initiateTopUp()` and `initiateAddonPurchase()` both route through the same private `initiateCharge()` helper, which unconditionally requires a pre-saved default payment instrument and unconditionally calls `PaymentProviderGateway::createOffSessionPaymentIntent()`. RFC-005 §20 explicitly locks a different design: *"top-up/add-on purchase as one-time Checkout Sessions; auto-recharge as an off-session PaymentIntent."* M6 itself remains **BLOCKED** under its own merged Gap Rule (`docs/automation/RFC-005-M6-CONTRACT.md` §3) pending this and five other independently governed corrections (Reservation Admission has already merged). This contract is remediation #2 of 7; it does not by itself unblock M6.

---

## Correction Round 1 record

Independent review of the initial draft (head `97ce2722489c9c52b41b0c0583cd8569437d25d8`) found three genuine contradictions and several precision gaps, all resolved below by direct re-audit of the cited source files — no schema change was found necessary. Summary of every substantive change this round made:

1. **`payment_method_display_snapshot` (NOT NULL, `string(64)`) had no valid value for a Checkout-backed attempt at creation time.** Resolved by reusing the exact, already-shipped `additional_business_slot_agreements` precedent: write the literal sentinel `'Pending Checkout'` at attempt creation, replace it exactly once with the actual verified PaymentMethod's safe display string after Checkout confirmation succeeds — §5.
2. **§9 (no saved instrument required) and §17 (the existing `no_provider_customer` denial test "remains valid as-is") directly contradicted each other** — the original draft simultaneously claimed a lazy-created provider customer and an unmodified denial-if-absent test. Resolved in favor of **Option 1**: the existing `no_provider_customer` denial is preserved unmodified for `ManualTopUp`/`AddonPurchase`; no lazy provider-customer creation is introduced. This is also the only choice consistent with M3 contract §11's literal "before *any* Stripe call is made" ordering rule, which a lazy `createOrRetrieveCustomer()` call inside `initiateCharge()` would otherwise violate — §9.
3. **§12's claim that `confirmAttemptFromWebhook()` merely "changes which gateway retrieval method it uses" was factually false** — direct re-reading of `UsageBillingCheckoutManager.php` confirms `confirmAttemptFromWebhook()` currently performs **no gateway retrieval of any kind**; it applies `ProcessPaymentProviderEvent`'s already-verified webhook evidence directly. Corrected, and one exact design locked (mirroring the additional-slot-agreement's own already-shipped `confirmSlotAgreementFromWebhook()` pattern): for a Checkout-backed purpose only, `confirmAttemptFromWebhook()` gains its own authoritative `retrieveCheckoutSession()` re-fetch and re-verification before mutation (needed regardless, to obtain the real PaymentMethod for item 1's display-snapshot finalization); `AutoRecharge`'s webhook confirmation gains no new provider call and is otherwise byte-for-byte unchanged — §12.
4. `createCheckoutSession()`'s exact new-parameter placement is now locked explicitly (appended last, defaulted, after the existing `array $metadata` parameter — the only placement that is valid PHP without disturbing any existing positional call site) — §8.
5. The top-up return route's Business-isolation check is now locked mechanically, using only the existing `BusinessFundingAttemptRepository::findById()` read method — §6.
6. Every test-file verdict in §17 is now exactly one of `REQUIRED` / `PARTIALLY REQUIRED` / `NOT REQUIRED` — the prior draft's one "likely REQUIRED" hedge is resolved to a determination, and every new test is assigned an exact existing file (no new test file is authorized).
7. The dangling `§15.B` cross-reference in the opening paragraph is corrected to reference §0, where the future implementation branch name is now stated as its own explicit, locked fact.
8. §16's production-path allowlist is fully re-derived against the corrected design (not preserved by inertia) — `PaymentInstrumentManager.php` and `CheckoutSessionResult.php` remain confirmed **not required**, for reasons restated with the corrected design's own logic.

**Correction rounds: 1 of 2 consumed by this round.** One ordinary round remains.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-funding-provider-flow-correction-contract`, in an isolated linked worktree (`../rfc-005-funding-provider-flow-correction-contract-worktree`), based on `origin/main` at `311bf0bf08cd4bf6c0939aec0cdf45962c4bb9de` — the Reservation Admission correction's own merge commit (PR [#135](https://github.com/os-creator1/os-ai/pull/135)), reconfirmed the current tip of `main` via `git fetch`/`git rev-parse` before this correction round, unchanged since initial drafting.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-funding-provider-flow-correction`.** This is the one and only branch name this contract authorizes for the eventual bounded implementation; nothing in this document authorizes creating it now.
- Confirmed before drafting and reconfirmed this round: no `docs/automation/RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md` exists anywhere in `origin/main`'s history other than this same branch's own prior commit, and no `agent/rfc-005-funding-provider-flow-correction` branch exists on `origin`.
- `agent/rfc-005-m6` is confirmed, at this correction round, to remain unmodified: a **local-only branch — never pushed to `origin`**, carrying **zero authored commits**, and a direct ancestor of current `origin/main`. This contract does not touch, reset, or recreate that branch in any way.
- **This is a new, independently bounded pre-M6 correction contract — not a correction round against M1–M6's own contracts, and not a correction round against the already-merged Reservation Admission contract.** Its `maximum_correction_rounds: 2` budget is its own. **1 of 2 consumed by this correction round; 1 ordinary round remains.** No counter is borrowed or altered on any other contract.
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

**Why this was never caught:** `initiateCharge()`'s docblock (M3 contract §11) describes it as "the manual top-up state machine," written before M4 introduced the additional-slot Checkout Session pattern; M4 correctly built a *separate* Checkout Session code path (`initiateSlotAgreementCheckout()`/`confirmSlotAgreementChecked()`/`processSlotAgreementInitialCheckout()`) for the slot agreement, but never revisited `initiateCharge()` to bring `ManualTopUp`/`AddonPurchase` in line with the same corrected design. Every M3/M4 regression gate passed because every existing test for `initiateTopUp()`/`initiateAddonPurchase()` was itself written against the (incorrect) off-session-PaymentIntent behavior, so the tests and the code agreed with each other while both disagreed with RFC-005 §20.

Do not describe this as an accounting, ledger, entitlement, or reservation-admission defect. It is exclusively a provider-object selection and webhook-classification defect, fully external to `UsageWalletManager`.

---

## 2. Entitlement / wallet-admission boundary — untouched, unaffected

This correction touches only `UsageBillingCheckoutManager` (funding-attempt creation and confirmation), `PaymentProviderGateway` (the Stripe boundary), and `ProcessPaymentProviderEvent` (webhook classification). It has no interaction whatsoever with `App\Library\Usage\UsageWalletManager` (reservation admission, spend caps, feature limits, safety limits — already corrected by remediation #1) or `App\Library\Entitlement\EntitlementManager::decide()`/`RealUsageAuthorizationGateway::check()` (feature availability). A funded wallet's *availability to spend* is entirely orthogonal to *how the wallet gets funded* — this correction only changes the second. No entitlement, reservation, or spend-admission code path is read, called, or modified by this correction.

---

## 3. Funding-attempt model — authoritative, unchanged, no schema

`business_funding_attempts` remains the sole durable model for every funding-causing charge (`ManualTopUp` | `AutoRecharge` | `AddonPurchase`, RFC-005 §17.C). Its `provider_session_or_intent_reference` column (`string(191)`, nullable, unique — confirmed directly against `database/migrations/2026_08_16_140003_create_business_funding_attempts_table.php`) already carries no type constraint of its own — it is documented (RFC-005 §17.C) to hold "either the initial Checkout Session id" or a PaymentIntent id, according to purpose. **No migration or schema change is required or authorized by this correction**, confirmed by direct re-audit of that migration and of §5 below's resolution for `payment_method_display_snapshot` — the one column initially flagged as a possible obstacle is resolved entirely within its existing `NOT NULL string(64)` definition, reusing the exact sentinel-then-finalize pattern `additional_business_slot_agreements.payment_method_display_snapshot` already uses in production code today (`quoteAdditionalSlotAgreement()`'s `'Pending Checkout'` literal, confirmed at `UsageBillingCheckoutManager.php:718`, replaced by `confirmSlotAgreementChecked()`'s `formatInstrumentDisplay($instrument)` call, confirmed at line 834). The correction only changes *which kind* of provider-object reference this column receives for two of the three existing purposes — a pure behavioral change inside `UsageBillingCheckoutManager`, never a new model, never a new table, never a new column.

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

## 5. `payment_method_display_snapshot` — exact resolution (Blocker A, resolved)

**The obstacle, confirmed by direct schema re-audit:** `business_funding_attempts.payment_method_display_snapshot` is `string(64)` **`NOT NULL`**, with no default (`database/migrations/2026_08_16_140003_create_business_funding_attempts_table.php:27`). `initiateCharge()` currently populates it unconditionally from `formatInstrumentDisplay($instrument)` — a value that does not exist for a `ManualTopUp`/`AddonPurchase` attempt once the pre-saved-instrument requirement is removed (§9).

**Resolution — reusing the already-shipped `additional_business_slot_agreements` precedent exactly, no schema change:**

1. **At attempt creation**, for `ManualTopUp`/`AddonPurchase` only: write the literal sentinel string `'Pending Checkout'` — the identical literal `quoteAdditionalSlotAgreement()` already writes into `additional_business_slot_agreements.payment_method_display_snapshot` before its own Checkout Session exists. `AutoRecharge` is unaffected — it continues to snapshot its already-saved instrument's real display string via `formatInstrumentDisplay($instrument)` at creation time, exactly as today, since an `AutoRecharge` attempt still requires a pre-saved instrument (§9).
2. **After authoritative Checkout confirmation succeeds** (via `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`, §12): retrieve the actual PaymentMethod used, via the already-existing, unmodified `PaymentProviderGateway::retrievePaymentMethod(string $providerPaymentMethodId): PaymentMethodResult` (the identical gateway method `PaymentInstrumentManager::confirmSetupIntentAndAttach()` already calls) — never `PaymentInstrumentManager`, and never `business_payment_instruments` (§8's "no reusable instrument side effect" rule). `PaymentMethodResult` already carries exactly `brand`/`lastFour`/`expiryMonth`/`expiryYear` — the same fields `formatInstrumentDisplay()` already formats from a `BusinessPaymentInstrument` model. A second private formatter, `formatPaymentMethodDisplay(PaymentMethodResult $method): string`, is added alongside the existing `formatInstrumentDisplay()` in `UsageBillingCheckoutManager.php`, producing the byte-identical display shape (`"{brand} •••• {lastFour}, exp {MM}/{YYYY}"`) from `PaymentMethodResult`'s scalar `$method->type` in place of `formatInstrumentDisplay()`'s `$instrument->type->value` enum access (the only structural difference between the two input shapes) — no new file, no new public surface.
3. `$this->attemptRepository->update($attempt, ['payment_method_display_snapshot' => $realDisplay, ...])` replaces the sentinel **exactly once**, in the same update call that already transitions the attempt's `state` to `succeeded`-bound (mirroring `confirmSlotAgreementChecked()`'s own single combined `update()` call).
4. **After that successful replacement, the display snapshot is historical and never re-derived again** — the already-Succeeded early-return guard in both `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()` means this replacement can only ever execute once per attempt, matching every other snapshot column's own "frozen at the moment it is first meaningfully known" semantics.
5. **A failed or expired Checkout Session never reaches step 2 or 3** — `markFailed()`/`markAttemptFailedFromWebhook()` are entirely unaffected by this change and never touch `payment_method_display_snapshot`; a failed/expired attempt's display snapshot remains the `'Pending Checkout'` sentinel permanently, which is itself an accurate historical record (no payment method was ever successfully used).

**M3 contract §11's "frozen at attempt creation" wording, reconciled:** that wording describes the snapshot columns' general *purpose* (an immutable historical record, never a live re-lookup) as they applied to the M3-era, instrument-known-at-creation design. It does not, and cannot, override RFC-005 §20's own later-introduced Checkout Session requirement, under which the actual payment method is genuinely not yet known at creation time — exactly the same gap M4's slot-agreement design already resolved, for the identical reason, with the identical two-step sentinel/finalize pattern this correction reuses. This correction's contribution is applying that already-established resolution to `business_funding_attempts`, not inventing a new one.

---

## 6. Manual top-up — corrected HTTP lifecycle

```
payer-authorized actor submits amount
  → assertChargeCausingConsent() (unchanged, §16 payer-consent gate)
  → provider-customer lookup (READ-ONLY, unchanged — see §9: still denies
    no_provider_customer if absent; no lazy creation)
  → local funding attempt created (state: created, payment_method_display_snapshot:
    'Pending Checkout'), inside a short transaction, no provider call yet
  → OUTSIDE that transaction: gateway->createCheckoutSession(
        providerCustomerId, minorUnits, currencyCode,
        lineItemName: 'Wallet top-up' (or equally truthful, non-invented label),
        successUrl: the new dedicated confirmation route (below),
        cancelUrl: the existing Usage Billing dashboard route,
        idempotencyKey: the attempt's own local_idempotency_key,
        metadata: { app_subject_kind: 'funding_attempt', app_subject_id: <attempt id>, app_operation_id: <local_idempotency_key> },
        setupFutureUsageOffSession: false (§8),
    )
  → Session id persisted on provider_session_or_intent_reference; state → provider_pending
  → FundingAttemptResult.redirectUrl returned to the controller
  → controller redirects the browser to the Stripe-hosted Checkout URL (never renders/handles payment details itself)
  → browser returns to the new confirmation route — the return is NOT trusted alone
  → confirmAttemptFromReturn() retrieves the Checkout Session via gateway->retrieveCheckoutSession()
  → verifies the same eight conditions confirmSlotAgreementChecked()/slotAgreementCheckoutVerified() already
    verify for the slot-agreement Checkout Session (status: complete, payment_status: paid, matching
    Session id/amount/currency/provider-customer, non-null PaymentIntent + PaymentMethod references)
  → on success: retrieves the real PaymentMethod display (§5), finalizes the snapshot, then
    confirmSucceeded() — the existing, single accounting-success path (creditFromFunding())
  → duplicate return/webhook remains idempotent via the existing already-Succeeded early return
```

**Locked: the Checkout-selected PaymentMethod is never synced into `business_payment_instruments` and never becomes the Business's/Workspace's new default instrument for a one-time top-up** (§8 explains why). The corrected verification step retrieves the PaymentMethod only to read its safe display metadata (§5) — it never calls `PaymentInstrumentManager::syncWorkspaceCheckoutPaymentMethod()` or any equivalent, and `PaymentInstrumentManager.php` itself is not modified by this correction (§16).

**New route, reusing the existing controller and the existing Business-scoped Usage Billing routing convention** — mirroring `additional-business-slots/{agreement}/confirm`'s own exact shape:

```
Route::get('{workspaceUid}/businesses/{businessUid}/usage-billing/top-up/{attempt}/confirm',
    'Business\UsageBillingTopUpController@confirmFromReturn')
    ->name('businesses.usage-billing.top-up.confirm')
    ->whereNumber('attempt');
```

**Business isolation for this route — locked mechanically (Blocker G, resolved):**

```
public function confirmFromReturn(int $attempt, string $workspaceUid, string $businessUid): RedirectResponse
{
    $actorUserId = (int) Auth::id();
    $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId); // existing method, unchanged

    $fundingAttempt = $this->attemptRepository->findById($attempt); // existing read method, no new repository path

    if ($fundingAttempt === null || (int) $fundingAttempt->business_id !== (int) $business->id) {
        abort(404);
    }

    $result = $this->checkoutManager->confirmAttemptFromReturn($fundingAttempt);
    // ... existing flash-message/redirect handling, mirroring initiate()'s own shape
}
```

Step order is exact and non-negotiable: (1) resolve Workspace + Business under the existing access rules (`resolveViewableBusiness()`, unchanged — already 404s on an inaccessible Workspace/Business); (2) load the funding attempt by id, via the existing `BusinessFundingAttemptRepository::findById()` — no new repository method; (3) require `attempt.business_id === resolved Business.id`, otherwise `abort(404)` — never a 403, matching RFC-005 §24's existing "unrelated resources fail closed with a 404-shaped response" rule verbatim; (4) only then call `confirmAttemptFromReturn()`. No raw `DB::table()` access is introduced in the controller.

`UsageBillingTopUpController` gains exactly this one new action — no new controller class. `initiate()` itself is corrected to redirect to `$result->redirectUrl` (via `redirect()->away(...)`) instead of the current synchronous "Top-up initiated" success flash, since a Checkout Session flow cannot complete synchronously within the initiating request.

**No pre-saved default instrument required.** `initiateSlotAgreementCheckout()` — the RFC's own existing, correctly-conforming Checkout Session flow — never calls `instrumentRepository->findDefaultForProviderCustomer()` at all. The corrected `initiateTopUp()`/`initiateAddonPurchase()` path drops the `no_payment_instrument` pre-check entirely — a Checkout Session collects payment information customer-present, at Stripe's own hosted page, exactly as the slot-agreement flow already proves works without any pre-saved card (§9 locks this exactly, and §9 separately locks that the *provider-customer* requirement, unlike the instrument requirement, is **not** removed).

---

## 7. Add-on purchase — HTTP remains non-blocking, provider object corrected

**Preserved conclusion, unchanged by this correction:** M4 shipped `business_usage_addon_catalog` with zero seeded rows and no HTTP surface by deliberate design (M4 contract §10) — a real commercial add-on catalog is a human product decision, not a technical gap. **This correction creates no new add-on route, controller, view, `addon_key`, price, or seeded catalog row.**

**What this correction does change:** `initiateAddonPurchase()` → `initiateCharge()` with `purpose: AddonPurchase` now creates a Checkout Session (§4/§6), with the identical `'Pending Checkout'`-then-finalize snapshot lifecycle (§5), and the resulting `redirectUrl` is threaded through `AddonPurchaseResult` (§8's DTO note) so that a **future**, separately authorized add-on HTTP caller can redirect to it without ever needing to reach into the gateway or into `UsageBillingCheckoutManager`'s own internals.

Because `initiateAddonPurchase()`'s own `postAttemptCreationHook` already creates the linked `business_usage_addon_purchases` row inside the same short transaction, *before* the outbound Checkout Session call (M4 Correction Round 1's own closed replay-hole fix, §D) — this ordering is unaffected by the provider-object change and requires no modification.

---

## 8. `setup_future_usage`, redirect-result DTOs, and the exact gateway signature

### A. `setup_future_usage` — locked resolution

**RFC-005 §20, quoted exactly:** *"...the additional-slot agreement's initial charge as a Checkout Session with `setup_future_usage: 'off_session'`, every renewal as an off-session PaymentIntent."* `setup_future_usage: 'off_session'` is scoped, by the RFC's own words, to the additional-slot agreement's initial charge alone — it is never mentioned for top-up or add-on purchase, which §20's own preceding clause independently and separately describes as "one-time Checkout Sessions," with no future-use qualifier of any kind.

**Resolution: Option B — a one-time payment, never automatically establishing a new future-use instrument.**

- **Why:** the slot agreement's `setup_future_usage: 'off_session'` exists for exactly one documented reason — it has a *known, designed future off-session renewal* (§22's own recurring renewal-charge machinery). A manual top-up or add-on purchase has no such designed recurrence; RFC-005 §19 draws its own hard line here — auto-recharge is a distinct, separately-consented, ongoing authorization (§16's "consent extended to every charge-causing action" rule; §19's own narrowed platform-administrator posture: *"an administrator may never unilaterally enable auto-recharge for a Business on the customer's behalf"*).
- **Payer-consent implication:** a payer completing a one-time top-up Checkout Session consents only to that one charge. Establishing reusable off-session authority is a materially different consent, already gated by its own distinct action (`configureAutoRecharge()`/`PaymentInstrumentManager::createSetupIntent()`+attach).
- **No existing instrument is required or consumed** (§9).
- **The Checkout-selected PaymentMethod is retrieved only for its safe display metadata (§5) and is never synced locally, never inserted into `business_payment_instruments`, and never becomes any default instrument** — retrieving `PaymentMethodResult` via `retrievePaymentMethod()` is a pure read against Stripe, with no local write of any kind beyond `payment_method_display_snapshot` itself.
- **Auto-recharge is never silently enabled** — `configureAutoRecharge()` remains the sole, separately-consented path to enabling it, entirely untouched by this correction (§13).

### B. Exact gateway signature (Blocker E, resolved)

`createCheckoutSession()`'s **current, exact** signature (all eight parameters required, confirmed against `app/Library/Usage/Contracts/PaymentProviderGateway.php`):

```php
public function createCheckoutSession(
    string $providerCustomerId,
    int $amountMinorUnits,
    string $currencyCode,
    string $lineItemName,
    string $successUrl,
    string $cancelUrl,
    string $idempotencyKey,
    array $metadata,
): CheckoutSessionResult;
```

**Locked corrected signature — the new parameter appended last, defaulted, after `$metadata`, the only placement that is valid PHP (an optional parameter can never precede a required one) and the only placement that leaves every existing eight-argument call site — including `initiateSlotAgreementCheckout()`'s own — syntactically unchanged unless explicitly updated:**

```php
public function createCheckoutSession(
    string $providerCustomerId,
    int $amountMinorUnits,
    string $currencyCode,
    string $lineItemName,
    string $successUrl,
    string $cancelUrl,
    string $idempotencyKey,
    array $metadata,
    bool $setupFutureUsageOffSession = false,
): CheckoutSessionResult;
```

- `StripePaymentProviderGateway::createCheckoutSession()` includes `payment_intent_data.setup_future_usage` in the outbound Stripe request **only when** `$setupFutureUsageOffSession === true`.
- `initiateSlotAgreementCheckout()` (the only existing caller) is updated to pass `setupFutureUsageOffSession: true` **explicitly** — its own outbound Stripe request remains byte-for-byte identical to today's.
- `initiateCharge()`'s new Checkout-Session branch (`ManualTopUp`/`AddonPurchase`) passes `setupFutureUsageOffSession: false`, or omits the argument, taking the default.
- `FakePaymentProviderGateway::createCheckoutSession()` accepts the widened signature and **records** the received value in a new `public array $createCheckoutSessionCalls` field — the identical existing pattern `confirmPaymentIntentCalls` already establishes for the identical "prove a specific call happened with specific parameters" purpose (M4 contract §3 item 7k) — so a test can assert which value each caller actually passed, rather than merely that no error occurred. No other observable behavior of the fake changes; its `checkoutSessionOutcomes` fixture-configuration mechanism is untouched.

### C. Redirect-result DTOs — smallest coherent change

- `FundingAttemptResult` gains one new field, `public ?string $redirectUrl`, populated only by the Checkout-Session-creating branch (`ManualTopUp`/`AddonPurchase`), `null` for every other path (`AutoRecharge`, and any already-succeeded/failed/no-provider-customer/no-instrument early return) — mirroring `SlotAgreementCheckoutResult`'s own precedent exactly.
- `AddonPurchaseResult` gains the identical nullable `redirectUrl`, propagated from the underlying `FundingAttemptResult` `initiateAddonPurchase()` already receives internally.
- No other DTO (`CheckoutSessionResult`, `SlotAgreementCheckoutResult`, `PaymentIntentResult`, `PaymentMethodResult`) requires any change — each already carries every field this correction's logic needs.

---

## 9. Provider-customer and saved-instrument requirements — resolved independently (Blocker B, resolved)

**The contradiction, confirmed:** the initial draft simultaneously claimed (§9) that `ManualTopUp`/`AddonPurchase` would lazily create an absent provider customer, and (§17) that `TopUpStateMachineTest::test_no_provider_customer_denies_the_attempt` "remains valid as-is" — an unmodified denial test cannot coexist with a new lazy-create path that would make that same scenario succeed instead of deny. Both cannot be true; the initial draft never should have asserted both.

**Resolved in favor of Option 1 — no behavior change to provider-customer resolution, for any purpose:**

| Requirement | `ManualTopUp` / `AddonPurchase` | `AutoRecharge` (unchanged) |
|---|---|---|
| Pre-existing `payment_provider_customers` row | **Required — denies `no_provider_customer` if absent, exactly as today** | Required — denies `no_provider_customer` if absent, unchanged |
| Pre-saved default `business_payment_instruments` row | **No longer required — this is the actual defect this correction fixes** | Required — denies `no_payment_instrument` if absent, unchanged (§9 table below) |

`initiateCharge()`'s existing `$providerCustomer = $this->providerCustomerRepository->findActiveByWorkspaceId(...)`/`findActiveByBusinessId(...)` read-only lookup, and its existing `return new FundingAttemptResult(0, FundingAttemptState::Failed, 'no_provider_customer')` denial, are **not modified** by this correction for any purpose. Only the **instrument** lookup immediately following it moves to apply exclusively on the `AutoRecharge` branch (§9's saved-instrument table below).

**Why Option 1, not Option 2 (lazy `PaymentInstrumentManager::resolveProviderCustomer()` call):** M3 contract §11 states, verbatim, that the local funding-attempt row is created "**before any Stripe call is made**" — not merely before the charge-creating call. `PaymentInstrumentManager::resolveProviderCustomer()` itself calls `gateway->createOrRetrieveCustomer()` when no provider customer exists yet — a genuine outbound Stripe call. Invoking it from inside `initiateCharge()` before the `business_funding_attempts` row exists would place a real Stripe call ahead of local attempt creation, directly violating M3 §11's own literal ordering rule for this specific method and this specific table. (The existing `additional_business_slot_agreements` precedent, which does perform provider-customer lazy-creation before its own row exists, is a different table introduced by a later milestone with no equivalent "before any Stripe call" constraint of its own — it is not a license to relax M3 §11's rule for `business_funding_attempts`.) Option 1 avoids this conflict entirely: since no Stripe call of any kind is introduced into the pre-attempt-creation path, M3 §11's ordering rule is trivially satisfied, unchanged from today.

**Product consequence, stated explicitly:** a Business/Workspace with a configured payer but genuinely no `payment_provider_customers` row yet (true first-ever payment setup) cannot complete a top-up via Checkout until a provider customer has been established through the existing, separate payment-method-setup action (`PaymentInstrumentManager::createSetupIntent()`, which itself calls `resolveProviderCustomer()`). This is a **pre-existing** characteristic of the system this correction does not change or worsen — today, the *only* way a `payment_provider_customers` row is ever created is through that same setup-intent flow. Redesigning customer onboarding is outside this correction's bounded scope (§13's "no hidden accounting redesign" rule, extended here to no hidden onboarding redesign).

**Exact saved-instrument requirement table, locked independently per purpose:**

| Purpose | Requires a pre-saved default instrument? |
|---|---|
| `ManualTopUp` | **No** — Checkout Session collects payment information customer-present, exactly as the already-conforming slot-agreement flow proves |
| `AddonPurchase` | **No** — identical reasoning |
| `AutoRecharge` | **Yes, unchanged** — an off-session charge is structurally impossible without an already-attached, reusable payment instrument; this requirement is intrinsic to off-session PaymentIntent charging, not an artifact of the defect this correction fixes |

The corrected `initiateCharge()` moves the `instrumentRepository->findDefaultForProviderCustomer()` lookup (and its `no_payment_instrument` denial) to apply **only** on the `AutoRecharge` branch.

---

## 10. Webhook raw-evidence validation — exact per-object-family rules

`ProcessPaymentProviderEvent::processFundingAttempt()` is corrected to branch on the **already-loaded** `$attempt->purpose` (never `event_type`) to select the expected provider-object family, mirroring `processSlotAgreementInitialCheckout()`'s own already-correct pattern:

| `$attempt->purpose` | Amount field | Success event-type suffix(es) | Failure/cancellation event-type suffix(es) |
|---|---|---|---|
| `AutoRecharge` | `amount` (unchanged) | `.succeeded` (unchanged) | `.payment_failed`, `canceled` (unchanged) |
| `ManualTopUp` / `AddonPurchase` | `amount_total` | `.completed`, `.async_payment_succeeded` | `.expired` |

**Never trust metadata alone, and never treat "some payload has `amount_total`" as sufficient on its own** — every existing validation step is preserved unconditionally, regardless of provider-object family, and must **all** pass before any mutation: the already-persisted provider object reference (`provider_session_or_intent_reference` match against `event->provider_object_id`), the operation id (`app_operation_id` match against `local_idempotency_key`), amount, currency, customer, local scope (Business ownership via the attempt row itself), and local expected state (a valid forward transition). **Missing required evidence still fails closed** — a `ManualTopUp`/`AddonPurchase` event missing both `amount` and `amount_total` marks the event `failed` with `missing_required_evidence`, exactly as `processSlotAgreementInitialCheckout()`'s own existing `! array_key_exists('amount', ...) && ! array_key_exists('amount_total', ...)` guard already does — this correction reuses that exact guard shape, not a weaker one.

This field/suffix-level validation is the **first** line of defense (unchanged from the existing architecture in every respect except the purpose-based branch). §12 below locks a **second**, independent line of defense specific to Checkout-backed purposes.

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

**A PaymentIntent event can never confirm a Checkout-backed (`ManualTopUp`/`AddonPurchase`) attempt, and a Checkout Session event can never confirm an `AutoRecharge` attempt** — enforced structurally, not just by event-type-suffix matching: `processFundingAttempt()`'s purpose-based branch (§10) selects the amount field and suffix set from `$attempt->purpose` *before* any event-type comparison runs, so an event of the wrong provider-object family for the loaded attempt's own purpose fails the amount-field-presence check (a PaymentIntent event has no `amount_total`; a Checkout Session event has no top-level `amount`) and is marked `missing_required_evidence`/`amount_mismatch` before it could ever reach a suffix comparison. `checkout.session.async_payment_succeeded` is included in the "confirms success" suffix set as the direct sibling of `processSlotAgreementInitialCheckout()`'s own existing handling of the identical event for the slot-agreement flow — reused, not invented. `checkout.session.expired`'s failure path reuses the existing, purpose-agnostic `markAttemptFailedFromWebhook()` method verbatim — no new manager method required. Signed-webhook verification (`PaymentProviderGateway::verifyWebhookSignature()`) is entirely unaffected by this correction.

---

## 12. Return + webhook convergence — corrected design (Blocker C, resolved)

**The factual correction, first:** the initial draft's claim that `confirmAttemptFromReturn()` and `confirmAttemptFromWebhook()` "merely change which gateway retrieval method they use according to purpose" was wrong for the webhook path. Direct re-reading of `UsageBillingCheckoutManager.php` confirms:

- `confirmAttemptFromReturn()` **does** call `gateway->retrievePaymentIntent()` today, unconditionally, for every purpose.
- `confirmAttemptFromWebhook()` performs **no gateway retrieval of any kind** today — it applies `ProcessPaymentProviderEvent::processFundingAttempt()`'s already-verified webhook evidence directly, calling `confirmSucceeded()` immediately.

**Locked design — Option B for Checkout-backed purposes, mirroring the additional-slot agreement's own already-shipped precedent exactly:**

- **`confirmAttemptFromReturn()`** is corrected to branch on `$attempt->purpose`: `AutoRecharge` keeps calling `retrievePaymentIntent()`, unchanged; `ManualTopUp`/`AddonPurchase` now calls `retrieveCheckoutSession()` and verifies the same eight conditions `slotAgreementCheckoutVerified()` already checks (adapted to a `BusinessFundingAttempt`, via a new private helper mirroring its exact shape), then finalizes the display snapshot (§5) before `confirmSucceeded()`.
- **`confirmAttemptFromWebhook()`** gains a purpose-based branch, matching `confirmSlotAgreementFromWebhook()`'s own already-shipped design exactly: for `ManualTopUp`/`AddonPurchase`, it now independently calls `gateway->retrieveCheckoutSession()` and re-verifies the identical eight conditions, **before** finalizing the display snapshot (§5) and calling `confirmSucceeded()` — never trusting `ProcessPaymentProviderEvent`'s own field-level webhook validation (§10) as sufficient on its own for a Checkout-backed mutation, exactly as the slot-agreement flow already does not trust it alone. `AutoRecharge`'s branch of `confirmAttemptFromWebhook()` is **completely unchanged** — no new provider call is added to PaymentIntent-backed webhook confirmation of any kind.
- **Why the asymmetry is correct, not an inconsistency:** a PaymentIntent event's own signed webhook payload, once `processFundingAttempt()`'s field-level validation passes, already **is** sufficient authoritative evidence of success for `AutoRecharge` — there is no further data to fetch (the instrument was already known and snapshotted at creation time). A Checkout Session, by contrast, requires fetching data the webhook payload's own top-level fields do not carry in the shape this correction needs — the actual PaymentMethod used (§5) — so an authoritative re-fetch is required regardless, and reusing it to also re-verify the eight conditions is the same defense-in-depth the slot-agreement flow already applies, not an invented new requirement.
- **Exact failure/reconciliation behavior when the re-fetch or re-verification fails** — mirroring `processSlotAgreementInitialCheckout()`'s own already-shipped behavior exactly, not inventing new semantics: if `confirmAttemptFromWebhook()`'s internal re-verification does not pass (an anomaly, since `processFundingAttempt()`'s own upstream field-level checks already passed), no mutation of any kind occurs — no state transition, no display-snapshot finalization, no credit — and the calling job (`ProcessPaymentProviderEvent::processFundingAttempt()`) still marks the event `processed` (its own upstream validation genuinely did succeed; the event itself was correctly matched and evidenced). The attempt remains in its prior state, to be recovered by the existing, already-authorized `ReconcileProviderPendingState` job (RFC-005 §29) — the identical recovery path the slot-agreement flow already relies on for the identical class of anomaly. This correction introduces no new "mark failed on internal re-verification mismatch" branch that does not already exist in the codebase for this exact situation.

No double wallet credit, no double ledger entry, no double funding transition, no double add-on completion — all already proven by the existing `UniqueConstraintViolationException`-guarded `creditFromFunding()` correlation-key mechanism and the already-Succeeded/already-Completed early returns, none of which this correction touches.

**One additional method requires the identical purpose-aware branching: `retryFundingAttemptAsAdministrator()`.** It currently calls `$this->gateway->retrievePaymentIntent(...)` unconditionally — correct today only because every attempt is PaymentIntent-backed. Once `ManualTopUp`/`AddonPurchase` attempts become Checkout-Session-backed, an administrator resuming a stuck one (state: `provider_pending`, never confirmed) must retrieve a **Checkout Session**, not a PaymentIntent. This correction extends `retryFundingAttemptAsAdministrator()` with the identical purpose-based branch (`retrieveCheckoutSession()` + the same eight-condition verification + display-snapshot finalization for `ManualTopUp`/`AddonPurchase`; `retrievePaymentIntent()` unchanged for `AutoRecharge`) — a mechanically necessary, narrowly-scoped extension of an already-authorized method, not a new capability or a new authorization posture (§9/§16's platform-administrator resume-only rule is completely unchanged).

---

## 13. Auto-recharge — must not regress

`initiateAutoRecharge()`'s own dispatch to `initiateCharge()` with `purpose: AutoRecharge` is **unchanged** — it continues to require a pre-saved default instrument and continues to call `createOffSessionPaymentIntent()`/`retrievePaymentIntent()`. `confirmAttemptFromWebhook()`'s `AutoRecharge` branch gains no new provider call (§12). The only code `AutoRecharge` shares with the corrected `ManualTopUp`/`AddonPurchase` branches is the already-existing outer scaffolding (`initiateCharge()`'s wallet lookup, provider-customer resolution, outstanding-attempt idempotency check, attempt-row creation, transition recording) — none of which changes shape, only which inner branch executes for a given `purpose`. Every existing threshold, monthly-recharge-cap, outstanding-attempt-protection, `requires_action`, and payer/instrument behavior for `AutoRecharge` remains byte-for-byte unchanged, and the required regression proof (§18) is every existing `AutoRecharge`-scoped test passing unmodified.

---

## 14. Explicitly excluded — Receipts

`business_billing_receipts`, any receipt model/repository, any `receiptUrl` DTO property, any `latest_charge` expansion, and `SendReceiptNotification` are **out of scope**. This correction's provider-object and webhook corrections touch only provider-object retrieval/verification, the display-snapshot finalization (§5, an already-existing column, not a new receipt-adjacent field), and the existing `confirmSucceeded()` credit path — no receipt-specific branch of any kind is introduced. Receipt Boundary (remediation #3) extends this corrected foundation afterward, entirely independently.

---

## 15. Explicitly excluded — Funding events

`BusinessFundingAttemptSucceeded`/`BusinessFundingAttemptFailed` (RFC-005 §29) are **not** dispatched by this correction. They remain owned by Job/Event Dispatch Completion (remediation #4). This correction's `confirmSucceeded()`/`markFailed()` methods gain no new `Event::dispatch()` call of any kind.

---

## 16. Production path allowlist — re-derived against the corrected design

| Path | Verdict | Reason |
|---|---|---|
| `app/Library/Usage/UsageBillingCheckoutManager.php` | **REQUIRED** | `initiateCharge()` purpose-aware dispatch (§4/§9), `formatPaymentMethodDisplay()` addition (§5), `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()` purpose-aware re-fetch+verify (§12), `retryFundingAttemptAsAdministrator()` purpose-aware resume (§12), `UsageBillingTopUpController` constructor/route wiring is a controller-side change tracked separately below |
| `app/Library/Usage/Contracts/PaymentProviderGateway.php` | **REQUIRED** | `createCheckoutSession()` gains the one new `bool $setupFutureUsageOffSession = false` parameter (§8.B) |
| `app/Library/Usage/StripePaymentProviderGateway.php` | **REQUIRED** | Implements the widened interface; conditionally includes `payment_intent_data.setup_future_usage` (§8.B); `initiateSlotAgreementCheckout()`'s own call site is inside `UsageBillingCheckoutManager.php`, tracked there |
| `app/Library/Usage/FakePaymentProviderGateway.php` | **REQUIRED** | Accepts the widened interface signature and adds the `createCheckoutSessionCalls` recording array (§8.B) |
| `app/Library/Usage/FundingAttemptResult.php` | **REQUIRED** | Adds nullable `redirectUrl` (§8.C) |
| `app/Library/Usage/AddonPurchaseResult.php` | **REQUIRED** | Adds the identical nullable `redirectUrl` (§8.C/§7) |
| `app/Library/Usage/PaymentInstrumentManager.php` | **NOT REQUIRED** | Confirmed unmodified — Option 1 (§9) means `UsageBillingCheckoutManager` never calls `resolveProviderCustomer()` or `syncWorkspaceCheckoutPaymentMethod()`; the corrected flow's only interaction with a `PaymentInstrumentManager`-adjacent concept is a direct `PaymentProviderGateway::retrievePaymentMethod()` call (already on the gateway interface, already unmodified) from inside `UsageBillingCheckoutManager` itself |
| `app/Jobs/Usage/ProcessPaymentProviderEvent.php` | **REQUIRED** | `processFundingAttempt()` purpose-aware amount-field/event-type-suffix branching (§10/§11) |
| `app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php` | **REQUIRED** | New `confirmFromReturn()` action with the exact Business-isolation check (§6); `initiate()` redirects to `$result->redirectUrl` instead of a synchronous success flash (§6) |
| `routes/customer.php` | **REQUIRED** | One new `GET .../top-up/{attempt}/confirm` route (§6) |
| `app/Library/Usage/CheckoutSessionResult.php` | **NOT REQUIRED** | Already carries every field the corrected verification logic needs (`redirectUrl`, `status`, `paymentStatus`, `amountMinorUnits`, `currencyCode`, `providerCustomerId`, `providerPaymentIntentId`, `providerPaymentMethodId`) |
| `app/Library/Usage/PaymentMethodResult.php` | **NOT REQUIRED** | Already carries every field `formatPaymentMethodDisplay()` (§5) needs (`brand`, `lastFour`, `expiryMonth`, `expiryYear`) |

**Explicitly out of scope, none required:** database migrations (§3/§5 — confirmed no schema change resolves this correction), new add-on HTTP/admin HTTP of any kind (§7), receipt code (§14), wallet admission code (§2), job/event completion code (§15), refund/dispute code (a separate remediation, #6), `docs/automation/AI-AUTONOMY-STATE.json`, package files.

**If, during implementation, any of the above turns out to genuinely require a schema migration or a path not named REQUIRED here, the implementer must STOP and report rather than silently broadening this correction** (§0's correction-round discipline governs any such contradiction).

---

## 17. Test authority — every file exactly one verdict, no discretionary new file

| File | Verdict | Reasoning |
|---|---|---|
| `tests/Feature/Usage/TopUpStateMachineTest.php` | **REQUIRED — modification** | `test_successful_top_up_transitions_created_to_provider_pending_to_succeeded` asserts a synchronous `created → provider_pending → succeeded` sequence a Checkout Session cannot produce; corrected to assert `created → provider_pending`, then a separate `confirmAttemptFromReturn()` call reaching `succeeded`. `test_declined_card_transitions_to_failed_with_a_reason` and `test_requires_action_leaves_the_attempt_pending_authentication` assert mid-*creation* PaymentIntent outcomes inapplicable to Session creation; corrected or removed as the Checkout-Session lifecycle dictates (a decline/3DS-equivalent outcome for Checkout surfaces at confirmation, not creation — asserted instead via `checkoutSessionOutcomes`). `test_no_payment_instrument_denies_the_attempt_without_creating_a_provider_call` is corrected to reflect §9's removed instrument requirement for `ManualTopUp` (superseded by the new `test_manual_top_up_succeeds_without_a_pre_saved_default_instrument`, §17 below). `test_no_provider_customer_denies_the_attempt` and `test_repeat_commit_on_an_already_succeeded_attempt_is_idempotent` remain valid **unmodified** (§9's Option 1 preserves this exact behavior). |
| `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` | **REQUIRED — modification** | Every crash/replay test assumes `initiateAddonPurchase()` reaches `Succeeded` synchronously inside the initiating call — true only for the current off-session-PaymentIntent design. Each test's own setup is corrected to drive an explicit `confirmAttemptFromWebhook()`/`confirmAttemptFromReturn()` call (configuring `checkoutSessionOutcomes` for a paid/complete Session) before asserting the crash/replay behavior; the replay-hole proof itself (§12) remains valid once the setup reflects the corrected two-step lifecycle. `test_manual_top_up_already_succeeded_no_op_behavior_is_unchanged` requires the identical setup correction. |
| `tests/Feature/Usage/FundingAttemptPayerConsentTest.php` | **PARTIALLY REQUIRED** | `test_workspace_owner_can_initiate_a_top_up_when_workspace_pays`, `test_direct_business_owner_cannot_initiate_a_top_up_when_workspace_pays`, `test_direct_business_owner_can_initiate_a_top_up_when_business_pays` remain valid **unmodified** — the payer-consent check runs before any provider-object decision, and §9's Option 1 keeps `no_provider_customer` byte-for-byte unchanged. `test_platform_administrator_can_resume_a_stuck_attempt` **requires correction** — it forces `paymentIntentOutcomes = ['*' => 'requires_action']` on a `ManualTopUp` attempt, a mechanism a Checkout-Session-backed attempt cannot reach; corrected to instead configure a `provider_pending`, not-yet-`complete` `checkoutSessionOutcomes` entry and exercise `retryFundingAttemptAsAdministrator()`'s new Checkout-Session branch (§12). |
| `tests/Feature/Usage/FakePaymentProviderGatewayTest.php` | **REQUIRED** | Determined, not hedged: the widened `createCheckoutSession()` signature (§8.B) adds a recording field the fake must expose; one new test asserts the recorded `setupFutureUsageOffSession` value for a given call (§17 new-tests list). Every existing test in this file remains valid unmodified — none constructs a `createCheckoutSession()` call whose assertions are affected by the ninth, defaulted parameter. |
| `tests/Feature/Usage/WebhookDuplicateEventReplayTest.php` | **NOT REQUIRED** | Tests generic `provider_event_id` deduplication via `UNIQUE(provider, provider_event_id)`, entirely orthogonal to funding-attempt purpose or provider-object family. |
| `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` | **REQUIRED — fixture-level correction only** | `createPendingAttempt()`'s mechanism for producing a "pending, not yet confirmed" `ManualTopUp` attempt (`paymentIntentOutcomes = ['*' => 'requires_action']`) no longer applies once `initiateTopUp()` never calls `createOffSessionPaymentIntent()`; corrected to configure a `provider_pending`, not-yet-`complete` `checkoutSessionOutcomes` entry instead. The race/lock proof itself — two workers racing `confirmAttemptFromReturn()` against the same Business, each crediting exactly once — requires no redesign. |

**No new test file is authorized.** Every new proof below is assigned to an exact existing file — the implementer may not create any test path not named in this table.

| New test | Assigned file |
|---|---|
| `test_manual_top_up_creates_a_checkout_session_not_a_payment_intent` | `TopUpStateMachineTest.php` |
| `test_manual_top_up_succeeds_without_a_pre_saved_default_instrument` | `TopUpStateMachineTest.php` |
| `test_initiate_top_up_returns_a_hosted_redirect_url` | `TopUpStateMachineTest.php` |
| `test_confirm_from_return_never_trusts_the_query_string_alone` | `TopUpStateMachineTest.php` |
| `test_confirm_from_return_rejects_a_business_scope_mismatch` | `TopUpStateMachineTest.php` (or a `UsageBillingTopUpControllerTest.php` HTTP-level test **only if one already exists** — if no such controller test file exists today, this proof is asserted at the manager/repository level inside `TopUpStateMachineTest.php` instead of authorizing a new HTTP test file) |
| `test_checkout_backed_attempt_starts_with_the_pending_checkout_sentinel_and_finalizes_it_on_confirmation` | `TopUpStateMachineTest.php` |
| `test_failed_or_expired_checkout_never_finalizes_a_payment_method_display_snapshot` | `TopUpStateMachineTest.php` |
| `test_checkout_session_completed_webhook_confirms_a_manual_top_up` | `TopUpStateMachineTest.php` |
| `test_addon_purchase_creates_a_checkout_session_not_a_payment_intent` | `AddonPurchaseTransitionAuditTest.php` |
| `test_checkout_session_completed_webhook_confirms_an_addon_purchase` | `AddonPurchaseTransitionAuditTest.php` |
| `test_auto_recharge_still_creates_an_off_session_payment_intent` | `FundingAttemptPayerConsentTest.php` |
| `test_auto_recharge_webhook_confirmation_performs_no_new_provider_call` | `FundingAttemptPayerConsentTest.php` |
| `test_platform_administrator_cannot_originate_a_fresh_top_up_via_checkout_session` | `FundingAttemptPayerConsentTest.php` |
| `test_completing_a_top_up_never_enables_auto_recharge` | `FundingAttemptPayerConsentTest.php` |
| `test_checkout_amount_total_is_validated_against_the_expected_amount` | `TopUpStateMachineTest.php` |
| `test_wrong_amount_currency_customer_object_or_operation_is_rejected_for_checkout_events` | `TopUpStateMachineTest.php` |
| `test_a_checkout_session_event_cannot_confirm_an_auto_recharge_attempt` | `FundingAttemptPayerConsentTest.php` |
| `test_a_payment_intent_event_cannot_confirm_a_manual_top_up_or_addon_purchase_attempt` | `TopUpStateMachineTest.php` |
| `test_duplicate_webhook_and_browser_return_credit_a_checkout_backed_top_up_exactly_once` | `ConcurrentTopUpConcurrencyTest.php` |
| `test_existing_slot_agreement_checkout_flow_is_unaffected_by_the_widened_gateway_signature` | `FakePaymentProviderGatewayTest.php` |
| `test_create_checkout_session_records_the_setup_future_usage_flag_per_call` | `FakePaymentProviderGatewayTest.php` |

**Do not push general §35 cleanup into this correction** — every test change above is scoped exclusively to the provider-object/webhook-classification/display-snapshot defect this contract locks; no unrelated test in any of these files may be rewritten or weakened.

---

## 18. Implementation test gates

At minimum, the future implementation must run and report:

```
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan migrate:fresh --env=testing
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Unit/Usage tests/Feature/Usage
git diff --check
```

`migrate:fresh --env=testing` is required as environment hygiene despite this correction authorizing no schema change (§3/§5/§16) — it guarantees a clean baseline before the focused suite runs, and cheaply confirms the "no migration" claim by construction: if a migration were mistakenly introduced, this same command would surface it. No live Stripe call is permitted in any automated test — every test uses `FakePaymentProviderGateway`. **These focused gates do not replace M6's later six-gate regression.** After every pre-M6 correction eventually merges, M6 will rerun all six gates from corrected `main` (§20).

---

## 19. Relationship to the other five remaining remediations

This correction does **not** authorize, and its merge does **not** by itself unblock M6 or begin: Receipt Boundary (#3), Job/Event Dispatch Completion (#4), Admin Usage Billing Surface (#5), Provider Refund/Dispute Outcome Handling (#6), §35 Test-Coverage Completion (#7). Each remains a separate, independently governed future document. M6 remains frozen until every required remediation is merged and a fresh static conformance audit passes.

---

## 20. M6 resumption rule — unchanged

This contract does not touch `agent/rfc-005-m6`. After **all** separately-authorized pre-M6 corrections are eventually merged: discard/reset the zero-commit old `agent/rfc-005-m6`, recreate it fresh from corrected `origin/main`, repeat full static conformance from scratch, rerun all six M6 regression gates, write both M6 documents, obtain human merge, run the post-merge exact-tag-candidate gate, and only then seek separate explicit human authorization for the annotated tag. This contract does not itself resume M6.

---

## 21. Contract PR scope

This governance branch changes exactly **one** file:

```
docs/automation/RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md
```

Not modified by this PR: `docs/automation/RFC-005-M6-CONTRACT.md`, `docs/automation/AI-AUTONOMY-STATE.json`, `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`, any `app/**`, `tests/**`, `database/**`, `routes/**`, `config/**`, `resources/**`, workflow, or package file.

Before commit: `git diff --check` clean; exactly one changed path confirmed via `git diff --name-only origin/main`.

Commit title (this correction round): `docs: correct RFC-005 funding provider-flow contract`.

Push normally to `chore/rfc-005-funding-provider-flow-correction-contract`. No force push. Draft PR (or the exact compare URL if `gh` is unavailable) remains open, not marked Ready, not merged.

---

## 22. Future implementation gates — restated for the implementation PR

The eventual, separately-authorized implementation PR on `agent/rfc-005-funding-provider-flow-correction` must, at minimum:

1. Confirm the cumulative diff is a subset of exactly the REQUIRED paths in §16 (production) and the REQUIRED/PARTIALLY-REQUIRED files in §17 (tests) — no eleventh production path, no test file and no new test name beyond those explicitly listed in §17's two tables.
2. Run `migrate:fresh --env=testing`, then `artisan test tests/Unit/Usage tests/Feature/Usage`, then `git diff --check` (§18), recording exact test/assertion/runtime counts, zero failures, exit 0.
3. Confirm every `AutoRecharge`-scoped existing test still passes unmodified (§13's regression proof).
4. Confirm the existing slot-agreement Checkout Session flow's own tests still pass unmodified.
5. Confirm `AI-AUTONOMY-STATE.json`, package files, migrations, and RFC/governance docs remain untouched.
6. Confirm this correction's own two-round budget (§0) remains at 1/2 unless a genuine contradiction required a second, human-reviewed correction round.

This implementation-time gate does not replace M6's later six-gate regression (§18/§20).
