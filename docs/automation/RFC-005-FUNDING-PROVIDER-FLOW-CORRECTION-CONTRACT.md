# RFC-005 Funding Provider-Flow Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction to `UsageBillingCheckoutManager`'s manual-top-up and add-on-purchase flows, replacing an incorrect off-session-PaymentIntent charge with the RFC-005-mandated one-time Checkout Session, and correcting the webhook-processing gap that would otherwise make a correctly-routed Checkout Session funding event unrecognizable. Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch named in §0 below, exactly as every RFC-005 milestone and correction contract before it (most recently the Reservation Admission correction, [`RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md`](./RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md), merged PR #134) has required.

This correction exists because a narrow, read-only provider/funding-flow correction-design audit — performed after Milestones 1–5 had already closed and after Reservation Admission (remediation #1 of 7) had already merged — discovered that `UsageBillingCheckoutManager::initiateTopUp()` and `initiateAddonPurchase()` both route through the same private `initiateCharge()` helper, which unconditionally requires a pre-saved default payment instrument and unconditionally calls `PaymentProviderGateway::createOffSessionPaymentIntent()`. RFC-005 §20 explicitly locks a different design: *"top-up/add-on purchase as one-time Checkout Sessions; auto-recharge as an off-session PaymentIntent."* M6 itself remains **BLOCKED** under its own merged Gap Rule (`docs/automation/RFC-005-M6-CONTRACT.md` §3) pending this and five other independently governed corrections (Reservation Admission has already merged). This contract is remediation #2 of 7; it does not by itself unblock M6.

---

## Correction Round 1 record

Independent review of the initial draft (head `97ce2722489c9c52b41b0c0583cd8569437d25d8`) found three genuine contradictions, resolved as follows — no schema change was found necessary:

1. **`payment_method_display_snapshot` (NOT NULL, `string(64)`) had no valid value for a Checkout-backed attempt at creation time.** Resolved by reusing the exact, already-shipped `additional_business_slot_agreements` precedent: write the literal sentinel `'Pending Checkout'` at attempt creation, replace it exactly once with the actual verified PaymentMethod's safe display string after Checkout confirmation succeeds.
2. **§9 (no saved instrument required) and §17 (the existing `no_provider_customer` denial test "remains valid as-is") directly contradicted each other.** Resolved in favor of **Option 1**: the existing `no_provider_customer` denial is preserved unmodified for `ManualTopUp`/`AddonPurchase`; no lazy provider-customer creation is introduced — the only choice consistent with M3 contract §11's literal "before *any* Stripe call is made" ordering rule.
3. **The claim that `confirmAttemptFromWebhook()` merely "changes which gateway retrieval method it uses" was factually false** — it currently performs **no gateway retrieval of any kind**. Corrected: for a Checkout-backed purpose only, `confirmAttemptFromWebhook()` gains its own authoritative `retrieveCheckoutSession()` re-fetch and re-verification before mutation, mirroring the additional-slot-agreement's own already-shipped `confirmSlotAgreementFromWebhook()` pattern; `AutoRecharge`'s webhook confirmation gains no new provider call.

Also locked this round: the exact `createCheckoutSession()` new-parameter placement; the top-up return route's mechanical Business-isolation check; deterministic (non-hedged) test-file verdicts; the dangling `§15.B` cross-reference corrected to `§0`; the production-path allowlist re-derived rather than preserved by inertia. **Correction rounds after Round 1: 1 of 2 consumed.**

---

## Correction Round 2 record

Prior head: `d3d3a6225ceea29e9e5f542b96da679bbf4275a0`. Independent review found the Round-1 test allowlist materially incomplete (multiple existing tests outside it encode the old PaymentIntent-backed `ManualTopUp` behavior and would fail once this correction lands), three unresolved design gaps (AddonPurchase's Checkout Session was missing its required `lineItemName`/`successUrl`/`cancelUrl` values; the webhook re-fetch failure semantics were stated too broadly; the exact write shape for finalizing `payment_method_display_snapshot` was internally ambiguous), and one correctness risk (a naive PaymentMethod-customer-linkage check that would wrongly reject a legitimately unattached, deliberately-not-saved one-time Checkout PaymentMethod). All resolved below by direct mechanical re-audit of the entire `tests/Feature/Usage` suite and direct re-reading of every affected source file — **no schema change was found necessary; every resolution below fits within the same design Round 1 already locked.**

Exact issues resolved this round:

1. **Test allowlist completeness (Blocker B).** A mechanical grep for `initiateTopUp(`/`initiateAddonPurchase(` across `tests/Unit/Usage` and `tests/Feature/Usage` found exactly 14 calling files (§17 below lists all 14, plus 2 files not calling either method but assigned new proofs this round, plus the 5 already carried forward from Round 1 that don't call these methods directly — 21 files total, exhaustively verdicted, zero remaining as "verify at implementation time").
2. **AddonPurchase's Checkout Session required-argument values (Blocker E), locked** — reuses the already-known `business_usage_addon_catalog.display_name` for `lineItemName`, and the existing Usage Billing dashboard route (already `resolveViewableBusiness()`-scoped) for both `successUrl`/`cancelUrl`, since no add-on-specific HTTP surface is authorized (§7).
3. **Webhook re-fetch failure semantics, split into the two mechanically distinct cases** the current `ProcessPaymentProviderEvent::handle()` try/catch actually produces (§12): a logical verification failure (no exception) vs. a thrown provider exception (`ProcessPaymentProviderEvent::handle()`'s own catch already marks the event `failed`).
4. **`payment_method_display_snapshot`'s exact write shape locked** — `confirmSucceeded()` gains one optional `?string $verifiedPaymentMethodDisplay = null` parameter, folded into its own existing single `attemptRepository->update()` call; no second success routine (§5).
5. **The PaymentMethod-customer-linkage rule corrected** — a one-time Checkout PaymentMethod created without `setup_future_usage` may legitimately have no Customer attachment at all; the authoritative ownership check remains the already-verified Checkout Session's own `providerCustomerId`, never a fabricated requirement that the unsaved PaymentMethod itself be pre-attached (§5).
6. **Every test whose existing semantics change now has an exact, named resolution** — renamed/reframed while preserving its real invariant, re-scoped to the purpose that still legitimately exercises it (several fixtures move from `ManualTopUp` to `AutoRecharge`, the only purpose still PaymentIntent-shaped), or corrected in its assertion only. No "corrected or removed as dictated" discretion remains anywhere in §17.
7. **Production allowlist re-recounted mechanically, confirmed unchanged from Round 1** — 9 REQUIRED, 4 explicitly NOT REQUIRED (adding `ReconcileProviderPendingState.php`, confirmed purpose-agnostic and needing zero changes since it already delegates entirely to the corrected `confirmAttemptFromReturn()`).

**Correction rounds: 2 of 2 consumed by this round. Zero ordinary correction rounds remain.** No genuinely unresolved contradiction was found this round that could not be mechanically resolved from authoritative repository/RFC evidence — every blocker raised had a direct, evidence-backed answer, so this report does not invoke the "stop and report" escape hatch.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-funding-provider-flow-correction-contract`, in an isolated linked worktree (`../rfc-005-funding-provider-flow-correction-contract-worktree`), based on `origin/main` at `311bf0bf08cd4bf6c0939aec0cdf45962c4bb9de` — the Reservation Admission correction's own merge commit (PR [#135](https://github.com/os-creator1/os-ai/pull/135)), reconfirmed the current tip of `main` via `git fetch`/`git rev-parse` before this correction round, unchanged since initial drafting.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-funding-provider-flow-correction`.**
- Confirmed before this round: no `docs/automation/RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md` exists anywhere in `origin/main`'s history other than this same branch's own prior commits, and no `agent/rfc-005-funding-provider-flow-correction` branch exists on `origin`.
- `agent/rfc-005-m6` is confirmed, at this correction round, to remain unmodified: a **local-only branch — never pushed to `origin`**, carrying **zero authored commits**, and a direct ancestor of current `origin/main`. This contract does not touch, reset, or recreate that branch in any way.
- **This is a new, independently bounded pre-M6 correction contract — not a correction round against M1–M6's own contracts, and not a correction round against the already-merged Reservation Admission contract.** Its `maximum_correction_rounds: 2` budget is its own. **2 of 2 consumed as of this round; zero ordinary rounds remain.** No counter is borrowed or altered on any other contract.
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

A second, independent defect compounds the first: even if `initiateCharge()` were corrected to create a Checkout Session for `ManualTopUp`/`AddonPurchase`, the webhook consumer would still silently fail to recognize its success. `ProcessPaymentProviderEvent::processFundingAttempt()` (the branch that handles every `funding_attempt`-subject-kind event, i.e. every `ManualTopUp`/`AutoRecharge`/`AddonPurchase` webhook) unconditionally requires `array_key_exists('amount', $object)` — the PaymentIntent-only field name — and classifies success/failure only by `.succeeded`/`.payment_failed`/`canceled` `event_type` suffixes. A Checkout Session's own success event, `checkout.session.completed`, carries `amount_total` (never a top-level `amount`) and ends in `.completed`, not `.succeeded`. **A correctly-routed Checkout Session funding webhook, as written today, falls through every recognized branch and silently reaches `markIgnored()`.** This second defect is confirmed **not** hypothetical: `ProcessPaymentProviderEvent::processSlotAgreementInitialCheckout()` (the already-correct sibling branch for the initial slot-agreement Checkout Session) already implements the exact `amount`/`amount_total` fallback and `.completed`/`.async_payment_succeeded` classification this branch is missing.

**Why this was never caught:** `initiateCharge()`'s docblock (M3 contract §11) describes it as "the manual top-up state machine," written before M4 introduced the additional-slot Checkout Session pattern; M4 correctly built a *separate* Checkout Session code path for the slot agreement, but never revisited `initiateCharge()`. Every M3/M4 regression gate passed because every existing test for `initiateTopUp()`/`initiateAddonPurchase()` was itself written against the (incorrect) off-session-PaymentIntent behavior.

Do not describe this as an accounting, ledger, entitlement, or reservation-admission defect. It is exclusively a provider-object selection and webhook-classification defect, fully external to `UsageWalletManager`.

---

## 2. Entitlement / wallet-admission boundary — untouched, unaffected

This correction touches only `UsageBillingCheckoutManager` (funding-attempt creation and confirmation), `PaymentProviderGateway` (the Stripe boundary), and `ProcessPaymentProviderEvent` (webhook classification). It has no interaction with `App\Library\Usage\UsageWalletManager` (reservation admission — already corrected by remediation #1) or `App\Library\Entitlement\EntitlementManager::decide()`/`RealUsageAuthorizationGateway::check()` (feature availability). A funded wallet's *availability to spend* is entirely orthogonal to *how the wallet gets funded*.

---

## 3. Funding-attempt model — authoritative, unchanged, no schema

`business_funding_attempts` remains the sole durable model for every funding-causing charge. Its `provider_session_or_intent_reference` column (`string(191)`, nullable, unique — confirmed against `database/migrations/2026_08_16_140003_create_business_funding_attempts_table.php`) already carries no type constraint of its own. **No migration or schema change is required or authorized by this correction** — confirmed by direct re-audit of that migration and of §5's resolution for `payment_method_display_snapshot`, which fits entirely within the existing `NOT NULL string(64)` column via the same sentinel-then-finalize pattern `additional_business_slot_agreements.payment_method_display_snapshot` already uses in production code today.

---

## 4. Purpose → provider-object dispatch — the one authoritative rule

**Locked, exactly:**

| `FundingAttemptPurpose` | Provider object | Gateway method |
|---|---|---|
| `ManualTopUp` | Checkout Session | `createCheckoutSession()` |
| `AddonPurchase` | Checkout Session | `createCheckoutSession()` |
| `AutoRecharge` | off-session PaymentIntent | `createOffSessionPaymentIntent()` (**unchanged**) |

`purpose` is the persisted local authority for this dispatch — never `event_type`.

**Subject to:** one durable funding-attempt state machine (`FundingAttemptState`, unchanged); one authoritative accounting-success path (`confirmSucceeded()`, extended per §5, never duplicated); no duplicate add-on-finalization implementation; no controller may call Stripe/the gateway directly.

---

## 5. `payment_method_display_snapshot` and the confirmation write shape — exact resolution

### A. The obstacle and the sentinel/finalize lifecycle (Round 1)

`business_funding_attempts.payment_method_display_snapshot` is `string(64)` **`NOT NULL`**, with no default. `initiateCharge()` currently populates it unconditionally from `formatInstrumentDisplay($instrument)` — a value that does not exist for a `ManualTopUp`/`AddonPurchase` attempt once the pre-saved-instrument requirement is removed (§9).

1. **At attempt creation**, for `ManualTopUp`/`AddonPurchase` only: write the literal sentinel string `'Pending Checkout'` — the identical literal `quoteAdditionalSlotAgreement()` already writes into `additional_business_slot_agreements.payment_method_display_snapshot`. `AutoRecharge` is unaffected — it continues to snapshot its already-saved instrument's real display string at creation time.
2. **After authoritative Checkout confirmation succeeds:** retrieve the actual PaymentMethod used, via the already-existing, unmodified `PaymentProviderGateway::retrievePaymentMethod(string $providerPaymentMethodId): PaymentMethodResult`. A second private formatter, `formatPaymentMethodDisplay(PaymentMethodResult $method): string`, is added alongside the existing `formatInstrumentDisplay()`, producing the byte-identical display shape from `PaymentMethodResult`'s scalars.
3. **After a successful replacement, the display snapshot is historical and never re-derived again** — enforced structurally by §5.B's write shape below, since it only ever executes once per attempt (inside the single already-Succeeded-guarded success path).
4. **A failed or expired Checkout Session never finalizes a display snapshot** — `markFailed()`/`markAttemptFailedFromWebhook()` never touch `payment_method_display_snapshot`; a failed/expired attempt's display snapshot remains the `'Pending Checkout'` sentinel permanently — itself an accurate historical record.

**M3 contract §11's "frozen at attempt creation" wording, reconciled:** that wording describes the snapshot columns' general *purpose* (immutable, never a live re-lookup), not a claim that every snapshot must be *known* at creation — RFC-005 §20's later Checkout Session requirement makes the actual payment method genuinely unknown at creation time, exactly the gap M4's slot-agreement design already resolved identically.

### B. Exact write shape (Blocker G, resolved)

**Locked design — `confirmSucceeded()` gains one optional parameter, folded into its own single existing `attemptRepository->update()` call; no second success routine:**

```php
private function confirmSucceeded(
    BusinessFundingAttempt $attempt,
    TransitionSource $source,
    ?int $providerEventId,
    ?int $actorUserId = null,
    ?string $verifiedPaymentMethodDisplay = null,
): void {
    $fromState = $attempt->state;

    $updateAttributes = ['state' => FundingAttemptState::Succeeded->value];

    if ($verifiedPaymentMethodDisplay !== null) {
        $updateAttributes['payment_method_display_snapshot'] = $verifiedPaymentMethodDisplay;
    }

    $this->attemptRepository->update($attempt, $updateAttributes);
    $this->recordTransition($attempt, $fromState, FundingAttemptState::Succeeded, $source, $providerEventId, $actorUserId);

    // ... unchanged: AddonPurchase finalization / creditFromFunding(), exactly as today.
}
```

- **`ManualTopUp`/`AddonPurchase` successful confirmation** (via `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`/`retryFundingAttemptAsAdministrator()`, §12) calls `confirmSucceeded()` with the verified display string obtained per §5.C below — one attempt-row `UPDATE`, both `state` and `payment_method_display_snapshot` together.
- **`AutoRecharge`** calls `confirmSucceeded()` exactly as today, with `$verifiedPaymentMethodDisplay` omitted (`null`) — its display snapshot, already written at creation time, is never touched again.
- Transition recording, `AddonPurchase` finalization, and the ledger credit continue through this same single `confirmSucceeded()` authority, entirely unchanged in shape.

**Race semantics — unchanged from today, not weakened.** The existing exactly-once accounting guarantee (`FundingAttemptExactlyOnceWalletCreditTest`, proven today for two independent PaymentIntent-shaped confirmation paths) rests on the already-Succeeded early-return guard in `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`, backed by `creditFromFunding()`'s own `UniqueConstraintViolationException`-guarded correlation-key uniqueness at the ledger level (`finalizeAddonPurchaseIfPending()` already relies on exactly this backstop) — the true concurrency-safety mechanism proven by `ConcurrentTopUpConcurrencyTest` is the wallet-row lock inside `UsageWalletManager::creditFromFunding()`, not attempt-row locking. This correction adds no new field, table, or write path that participates in that race calculus; `payment_method_display_snapshot`'s finalization rides inside the identical single `state`-transitioning `UPDATE` that already governs the race today.

### C. Safe PaymentMethod display source and the customer-linkage rule (Blocker H, resolved)

A one-time Checkout Session created **without** `setup_future_usage` (§8.A) may legitimately produce a PaymentMethod that Stripe never attaches to any Customer at all — that is the documented effect of omitting `setup_future_usage`, not an anomaly. Therefore:

- **The authoritative ownership/amount/currency evidence for the confirmation remains the already-verified Checkout Session itself** (§12's eight-condition check, including `session->providerCustomerId` matching the attempt's own expected provider customer) — this check is unchanged and remains mandatory.
- `retrievePaymentMethod($session->providerPaymentMethodId)` is called **only** to obtain safe display metadata (brand/last-four/expiry) for §5.A/§5.B — it is **never** used as an additional ownership gate for `ManualTopUp`/`AddonPurchase`. Unlike `PaymentInstrumentManager::confirmSetupIntentAndAttach()`/`syncWorkspaceCheckoutPaymentMethod()` (both of which *do* require `paymentMethod->providerCustomerId` to match, because their job is literally to attach/save the instrument for reuse), this one-time flow's job is display-only.
- **Do not require `PaymentMethodResult->providerCustomerId` to equal the expected provider-customer id.** It may legitimately be empty/unattached.
- **If `PaymentMethodResult->providerCustomerId` is present and non-empty, it must equal the expected provider-customer id — a present-but-contradictory value fails closed for the display-finalization step only:** the display snapshot finalization (§5.B) is skipped for this confirmation (the `'Pending Checkout'` sentinel is left in place rather than overwritten with data that may belong to a different customer's card), while the overall funding confirmation (state → `succeeded`, wallet credit) proceeds normally, since that determination already rests entirely on the independently-verified Checkout Session, not on this display-only lookup. This is the narrowest resolution that never fabricates a display string on contradictory evidence, and never holds a customer's already-verified successful payment hostage over a cosmetic display-string edge case.

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
        lineItemName: 'Wallet top-up',
        successUrl: the new dedicated confirmation route (below),
        cancelUrl: the existing Usage Billing dashboard route,
        idempotencyKey: the attempt's own local_idempotency_key,
        metadata: { app_subject_kind: 'funding_attempt', app_subject_id: <attempt id>, app_operation_id: <local_idempotency_key> },
        setupFutureUsageOffSession: false (§8.A),
    )
  → Session id persisted on provider_session_or_intent_reference; state → provider_pending
  → FundingAttemptResult.redirectUrl returned to the controller
  → controller redirects the browser to the Stripe-hosted Checkout URL
  → browser returns to the new confirmation route — the return is NOT trusted alone
  → confirmAttemptFromReturn() retrieves the Checkout Session via gateway->retrieveCheckoutSession()
  → verifies the same eight conditions confirmSlotAgreementChecked()/slotAgreementCheckoutVerified() already
    verify for the slot-agreement Checkout Session
  → on success: retrieves the real PaymentMethod display (§5), finalizes the snapshot, then
    confirmSucceeded() — the existing, single accounting-success path (creditFromFunding())
  → duplicate return/webhook remains idempotent via the existing already-Succeeded early return
```

**New route:**

```
Route::get('{workspaceUid}/businesses/{businessUid}/usage-billing/top-up/{attempt}/confirm',
    'Business\UsageBillingTopUpController@confirmFromReturn')
    ->name('businesses.usage-billing.top-up.confirm')
    ->whereNumber('attempt');
```

**Business isolation for this route — locked mechanically:**

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

Step order: (1) resolve Workspace + Business under existing access rules; (2) load the funding attempt via the existing `BusinessFundingAttemptRepository::findById()`; (3) require `attempt.business_id === resolved Business.id`, otherwise `abort(404)` — never 403, matching RFC-005 §24's existing rule verbatim; (4) only then call `confirmAttemptFromReturn()`.

**Exact HTTP proof-of-isolation location (Blocker D, resolved):** `tests/Feature/Usage/UsageBillingDashboardAuthorizationTest.php` — the repository's own existing, already-authoritative actor/action/isolation matrix file for this exact dashboard surface (M2 contract §7). It gains one new test, `test_top_up_confirm_route_rejects_a_cross_business_attempt`: an actor who can view Business A cannot use Business A's own top-up-confirm route to confirm a funding attempt actually belonging to Business B — response is 404, Business B's attempt is completely unchanged, and (via a call-recording addition to `FakePaymentProviderGateway`, or by asserting no state-transition occurred) no provider confirmation call reaches the gateway. No new test file — this file already exists and already owns exactly this class of proof for this exact controller surface.

**Exact hosted-redirect HTTP proof location:** `tests/Feature/Usage/UsageBillingDashboardStripeIntegrationTest.php` — the repository's own existing end-to-end "is this actually wired, not dead code" file for this exact HTTP surface (its own docblock: "end-to-end proof that the dashboard's M3 integration is wired and reachable"). It gains one new test, `test_initiating_a_top_up_redirects_to_the_hosted_checkout_url`: `POST .../top-up` returns an HTTP redirect whose `Location` matches the fake gateway's own hosted Checkout URL shape (`https://checkout.fake.stripe.test/...`), proving the controller genuinely redirects rather than returning synchronously to the dashboard.

**No pre-saved default instrument required** for `ManualTopUp`/`AddonPurchase` — the corrected `initiateTopUp()`/`initiateAddonPurchase()` path drops the `no_payment_instrument` pre-check entirely, mirroring `initiateSlotAgreementCheckout()`'s own already-conforming behavior (§9 locks the exact per-purpose table).

---

## 7. Add-on purchase — HTTP remains non-blocking, provider object corrected, required arguments locked

**Preserved conclusion, unchanged:** M4 shipped `business_usage_addon_catalog` with zero seeded rows and no HTTP surface by deliberate design (M4 contract §10). **This correction creates no new add-on route, controller, view, `addon_key`, price, or seeded catalog row.**

### Exact `createCheckoutSession()` argument values for `AddonPurchase` (Blocker E, resolved)

`createCheckoutSession()` requires non-empty `lineItemName`/`successUrl`/`cancelUrl` — the initial draft left these unresolved for `AddonPurchase`. Resolved using only already-known, already-authorized data, with **no** new route, controller, or view:

| Argument | Exact value |
|---|---|
| `lineItemName` | `$catalogRow->display_name` — the add-on catalog row's own existing, already-truthful `business_usage_addon_catalog.display_name` column, already loaded by `initiateAddonPurchase()`'s own existing `addonCatalogRepository->findActiveByAddonKey($addonKey)` call. No invented label. |
| `successUrl` | `route('customer.workspaces.businesses.usage-billing.show', [$business->workspace->uid, $business->uid]) . '?session_id={CHECKOUT_SESSION_ID}'` — the existing Usage Billing dashboard route, mirroring the slot-agreement flow's own `?session_id={CHECKOUT_SESSION_ID}` convention (inert today — no controller reads it — and available for a future authorized add-on confirmation caller without requiring any change here) |
| `cancelUrl` | The identical dashboard route, without the query suffix — mirrors `initiateTopUp()`'s own reuse of the dashboard as its cancel destination |
| `setupFutureUsageOffSession` | `false` (§8.A) |

**Why the dashboard, not a dedicated add-on page:** no add-on HTTP surface is authorized by this or any prior contract, so there is no honest dedicated landing page to send the browser to even if one were built — reusing the existing, already-Business-scoped dashboard is the only truthful destination available, and it is exactly consistent with the reviewer's own preferred resolution: rely on the existing webhook/reconciliation machinery (already corrected, §10–§12) to actually finalize a completed add-on purchase, with the dashboard link serving only as a safe, honest landing point. `initiateAddonPurchase()` has direct access to `$business` (and therefore `$business->workspace`) already, since it receives `Business $business` as its own first parameter — no new dependency, no new query.

**What this correction does change:** `initiateAddonPurchase()` → `initiateCharge()` with `purpose: AddonPurchase` now creates a Checkout Session (§4/§6), with the identical `'Pending Checkout'`-then-finalize snapshot lifecycle (§5), and the resulting `redirectUrl` is threaded through `AddonPurchaseResult` (§8.C) so that a **future**, separately authorized add-on HTTP caller can redirect to it without ever needing to reach into the gateway or into `UsageBillingCheckoutManager`'s own internals.

Because `initiateAddonPurchase()`'s own `postAttemptCreationHook` already creates the linked `business_usage_addon_purchases` row inside the same short transaction, *before* the outbound Checkout Session call (M4 Correction Round 1's own closed replay-hole fix) — this ordering is unaffected and requires no modification.

**Exact `AddonPurchaseResult.redirectUrl` proof:** `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` gains one new test, `test_initiate_addon_purchase_creates_a_checkout_session_and_returns_a_hosted_redirect_url`, asserting: a Checkout Session (not a PaymentIntent) is created; the result state is `provider_pending`; `AddonPurchaseResult->redirectUrl` is non-null; no saved default instrument is required beforehand; no fulfillment (no wallet credit, no `AddonPurchaseStatus::Completed`) occurs merely from Session creation.

---

## 8. `setup_future_usage`, redirect-result DTOs, and the exact gateway signature

### A. `setup_future_usage` — locked resolution

**RFC-005 §20, quoted exactly:** *"...the additional-slot agreement's initial charge as a Checkout Session with `setup_future_usage: 'off_session'`, every renewal as an off-session PaymentIntent."* Scoped, by the RFC's own words, to the additional-slot agreement's initial charge alone.

**Resolution: Option B — a one-time payment, never automatically establishing a new future-use instrument.** Why: the slot agreement's `setup_future_usage: 'off_session'` exists because it has a *known, designed future off-session renewal* (§22's own machinery); a manual top-up or add-on purchase has no such recurrence, and RFC-005 §19 requires a distinct, separately-consented action to establish any ongoing off-session authority. No existing instrument is required or consumed (§9). The Checkout-selected PaymentMethod is retrieved only for safe display metadata (§5.C) and is never synced locally, never inserted into `business_payment_instruments`, never becomes any default instrument. Auto-recharge is never silently enabled (§13).

### B. Exact gateway signature

**Current, exact signature** (all eight parameters required):

```php
public function createCheckoutSession(
    string $providerCustomerId, int $amountMinorUnits, string $currencyCode,
    string $lineItemName, string $successUrl, string $cancelUrl,
    string $idempotencyKey, array $metadata,
): CheckoutSessionResult;
```

**Locked corrected signature — the new parameter appended last, defaulted, after `$metadata` (the only placement that is valid PHP and leaves every existing eight-argument call site syntactically unchanged unless explicitly updated):**

```php
public function createCheckoutSession(
    string $providerCustomerId, int $amountMinorUnits, string $currencyCode,
    string $lineItemName, string $successUrl, string $cancelUrl,
    string $idempotencyKey, array $metadata,
    bool $setupFutureUsageOffSession = false,
): CheckoutSessionResult;
```

- `StripePaymentProviderGateway::createCheckoutSession()` includes `payment_intent_data.setup_future_usage` only when `$setupFutureUsageOffSession === true`.
- `initiateSlotAgreementCheckout()` (the only existing caller) passes `setupFutureUsageOffSession: true` **explicitly** — its own outbound Stripe request remains byte-for-byte identical to today's.
- `initiateCharge()`'s new Checkout-Session branch (`ManualTopUp`/`AddonPurchase`) passes `false` (or omits the argument).
- `FakePaymentProviderGateway::createCheckoutSession()` accepts the widened signature and **records** the received value in a new `public array $createCheckoutSessionCalls` field — mirroring the identical existing `confirmPaymentIntentCalls` pattern (M4 contract §3 item 7k).

**Exact slot-agreement `setupFutureUsageOffSession === true` proof location (Blocker J, resolved):** `tests/Feature/Usage/WebhookSlotAgreementSubjectRoutingTest.php` — the repository's own existing file whose `checkoutPendingAgreement()` helper already calls `UsageBillingCheckoutManager::initiateSlotAgreementCheckout()` directly, the real manager call site (not merely the gateway fake in isolation). It gains one new test, `test_initiate_slot_agreement_checkout_passes_setup_future_usage_true`, asserting `$gateway->createCheckoutSessionCalls`'s entry for that real call has `setupFutureUsageOffSession === true`. The sibling assertion — that `ManualTopUp`/`AddonPurchase` calls record `false` — lives in `TopUpStateMachineTest.php`/`AddonPurchaseTransitionAuditTest.php` respectively (§17). No new test file for either.

### C. Redirect-result DTOs

- `FundingAttemptResult` gains `public ?string $redirectUrl`, populated only by the Checkout-Session-creating branch, `null` otherwise — mirroring `SlotAgreementCheckoutResult`'s own precedent.
- `AddonPurchaseResult` gains the identical nullable `redirectUrl`.
- No other DTO requires any change.

---

## 9. Provider-customer and saved-instrument requirements — resolved independently

**Resolved in favor of Option 1 — no behavior change to provider-customer resolution, for any purpose:**

| Requirement | `ManualTopUp` / `AddonPurchase` | `AutoRecharge` (unchanged) |
|---|---|---|
| Pre-existing `payment_provider_customers` row | **Required — denies `no_provider_customer` if absent, exactly as today** | Required — unchanged |
| Pre-saved default `business_payment_instruments` row | **No longer required — the actual defect this correction fixes** | Required — denies `no_payment_instrument` if absent, unchanged |

**Why Option 1, not a lazy `resolveProviderCustomer()` call:** M3 contract §11 states the local funding-attempt row is created "before any Stripe call is made" — not merely before the charge-creating call. `PaymentInstrumentManager::resolveProviderCustomer()` itself may call `gateway->createOrRetrieveCustomer()`, a genuine outbound Stripe call; invoking it before the `business_funding_attempts` row exists would violate M3 §11's literal ordering rule. Option 1 avoids this entirely — no Stripe call of any kind is introduced into the pre-attempt-creation path.

**Product consequence, stated explicitly:** a Business/Workspace with a configured payer but genuinely no `payment_provider_customers` row yet cannot complete a top-up via Checkout until a provider customer has been established through the existing, separate `PaymentInstrumentManager::createSetupIntent()` flow — a pre-existing characteristic this correction does not change or worsen.

**Exact saved-instrument requirement table:**

| Purpose | Requires a pre-saved default instrument? |
|---|---|
| `ManualTopUp` | **No** |
| `AddonPurchase` | **No** |
| `AutoRecharge` | **Yes, unchanged** — an off-session charge is structurally impossible without an already-attached, reusable payment instrument |

The corrected `initiateCharge()` moves the `instrumentRepository->findDefaultForProviderCustomer()` lookup (and its `no_payment_instrument` denial) to apply **only** on the `AutoRecharge` branch.

---

## 10. Webhook raw-evidence validation — exact per-object-family rules

`ProcessPaymentProviderEvent::processFundingAttempt()` is corrected to branch on the **already-loaded** `$attempt->purpose` to select the expected provider-object family:

| `$attempt->purpose` | Amount field | Success event-type suffix(es) | Failure/cancellation event-type suffix(es) |
|---|---|---|---|
| `AutoRecharge` | `amount` (unchanged) | `.succeeded` (unchanged) | `.payment_failed`, `canceled` (unchanged) |
| `ManualTopUp` / `AddonPurchase` | `amount_total` | `.completed`, `.async_payment_succeeded` | `.expired` |

Every existing field-level validation step is preserved unconditionally: provider object reference match, operation id match, amount, currency, customer, local scope, local expected state. **Missing required evidence still fails closed** — reusing `processSlotAgreementInitialCheckout()`'s own existing guard shape exactly, never a weaker one. This is the **first** line of defense; §12 locks a second, independent one specific to Checkout-backed purposes.

---

## 11. Checkout success/failure event classification — exact audit

| Event | Confirms success | Terminal failure/cancellation | Ignored (reconciliation-only) |
|---|---|---|---|
| `checkout.session.completed` | **Yes**, for `ManualTopUp`/`AddonPurchase` | — | — |
| `checkout.session.async_payment_succeeded` | **Yes** | — | — |
| `checkout.session.expired` | — | **Yes** | — |
| `payment_intent.succeeded` | **Yes**, for `AutoRecharge` only | — | — |
| `payment_intent.payment_failed` | — | **Yes**, for `AutoRecharge` only | — |
| `payment_intent.requires_action` | — | — | **Yes**, unchanged for every purpose |

**A PaymentIntent event can never confirm a Checkout-backed attempt, and a Checkout Session event can never confirm `AutoRecharge`** — enforced structurally by §10's purpose-based branch running *before* any event-type comparison: an event of the wrong family fails the amount-field-presence check first. `checkout.session.expired`'s failure path reuses the existing, purpose-agnostic `markAttemptFailedFromWebhook()` verbatim. Signed-webhook verification is entirely unaffected.

---

## 12. Return + webhook convergence — corrected design

**Locked design — Option B for Checkout-backed purposes, mirroring the additional-slot agreement's own already-shipped precedent exactly:**

- **`confirmAttemptFromReturn()`** branches on `$attempt->purpose`: `AutoRecharge` keeps calling `retrievePaymentIntent()`, unchanged; `ManualTopUp`/`AddonPurchase` calls `retrieveCheckoutSession()`, verifies the same eight conditions `slotAgreementCheckoutVerified()` already checks (adapted to `BusinessFundingAttempt` via a new private helper mirroring its exact shape), retrieves the PaymentMethod for display purposes (§5.C), then calls `confirmSucceeded()`.
- **`confirmAttemptFromWebhook()`** gains the identical purpose-based branch, matching `confirmSlotAgreementFromWebhook()`'s own already-shipped design: for `ManualTopUp`/`AddonPurchase`, it independently calls `retrieveCheckoutSession()` and re-verifies the identical eight conditions **before** finalizing the display snapshot and calling `confirmSucceeded()` — never trusting `ProcessPaymentProviderEvent`'s own field-level webhook validation alone for a Checkout-backed mutation, exactly as the slot-agreement flow already does not. `AutoRecharge`'s branch is **completely unchanged** — no new provider call.
- **Why the asymmetry is correct:** a PaymentIntent event's own signed payload, once §10's field-level validation passes, already *is* sufficient authoritative evidence for `AutoRecharge` — there is no further data to fetch. A Checkout Session requires fetching data the webhook payload's own top-level fields do not carry in the shape needed (the actual PaymentMethod, §5) — an authoritative re-fetch is required regardless, and reusing it to re-verify the eight conditions is the same defense-in-depth the slot-agreement flow already applies.

### Exact webhook re-fetch failure semantics — two mechanically distinct cases (Blocker F, resolved)

`ProcessPaymentProviderEvent::handle()` wraps subject-kind processing in a `try`/`catch`, so the two failure modes below are genuinely different, not a single "still processed" outcome:

**CASE 1 — the provider call itself succeeds, but the eight-condition verification fails** (an anomaly, since §10's own upstream field-level checks already passed): no funding-attempt mutation, no display finalization, no credit; `confirmAttemptFromWebhook()` returns normally (no exception); `processFundingAttempt()` subsequently reaches its own unconditional `$eventRepository->markProcessed($event->id)` line, exactly mirroring `processSlotAgreementInitialCheckout()`'s own already-shipped behavior for the identical class of anomaly. The attempt remains in its prior state, recoverable by the existing `ReconcileProviderPendingState` job (§16 confirms this job needs zero changes).

**CASE 2 — `retrieveCheckoutSession()` or `retrievePaymentMethod()` throws** (e.g. `ProviderApiUnavailableException` or another provider exception): the exception propagates up through `confirmAttemptFromWebhook()` (uncaught there, exactly as today) to `ProcessPaymentProviderEvent::handle()`'s own existing `catch (\Throwable $e)` block, which marks the event `failed` with the exception's classification — the identical existing behavior the job already has for any other exception during processing. The attempt remains unchanged; the bounded provider-event retry mechanism (§21's own claim/lease algorithm, unmodified) or `ReconcileProviderPendingState` may recover it later.

No exception swallowing is introduced to force both cases into the same outcome. `AutoRecharge` webhook semantics are unaffected by either case, since `AutoRecharge`'s own branch performs no new provider call at all.

**One additional method requires the identical purpose-aware branching: `retryFundingAttemptAsAdministrator()`.** It currently calls `retrievePaymentIntent()` unconditionally. This correction extends it with the identical purpose-based branch (`retrieveCheckoutSession()` + the same eight-condition verification + display-snapshot finalization for `ManualTopUp`/`AddonPurchase`; `retrievePaymentIntent()` unchanged for `AutoRecharge`) — a mechanically necessary extension of an already-authorized method, not a new capability (§9/§16's platform-administrator resume-only rule is unchanged).

No double wallet credit, no double ledger entry, no double funding transition, no double add-on completion — all already proven by the existing `UniqueConstraintViolationException`-guarded correlation-key mechanism and the already-Succeeded/already-Completed early returns (§5.B).

---

## 13. Auto-recharge — must not regress

`initiateAutoRecharge()`'s dispatch to `initiateCharge()` with `purpose: AutoRecharge` is **unchanged** — continues to require a pre-saved default instrument, continues to call `createOffSessionPaymentIntent()`/`retrievePaymentIntent()`. `confirmAttemptFromWebhook()`'s `AutoRecharge` branch gains no new provider call (§12). The only code `AutoRecharge` shares with the corrected branches is the already-existing outer scaffolding — none of which changes shape. Every existing threshold, monthly-recharge-cap, outstanding-attempt-protection, `requires_action`, and payer/instrument behavior for `AutoRecharge` remains byte-for-byte unchanged.

---

## 14. Explicitly excluded — Receipts

Out of scope: `business_billing_receipts`, any receipt model/repository, any `receiptUrl` DTO property, any `latest_charge` expansion, `SendReceiptNotification`. Receipt Boundary (remediation #3) extends this corrected foundation afterward, entirely independently.

---

## 15. Explicitly excluded — Funding events

`BusinessFundingAttemptSucceeded`/`BusinessFundingAttemptFailed` are **not** dispatched by this correction — owned by Job/Event Dispatch Completion (remediation #4).

---

## 16. Production path allowlist — mechanically recounted, unchanged from Round 1

| Path | Verdict | Reason |
|---|---|---|
| `app/Library/Usage/UsageBillingCheckoutManager.php` | **REQUIRED** | `initiateCharge()` purpose-aware dispatch, `initiateAddonPurchase()`'s locked argument values (§7), `formatPaymentMethodDisplay()` addition, `confirmSucceeded()`'s new optional parameter (§5.B), `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`/`retryFundingAttemptAsAdministrator()` purpose-aware re-fetch+verify (§12) |
| `app/Library/Usage/Contracts/PaymentProviderGateway.php` | **REQUIRED** | `createCheckoutSession()` gains `bool $setupFutureUsageOffSession = false` (§8.B) |
| `app/Library/Usage/StripePaymentProviderGateway.php` | **REQUIRED** | Implements the widened interface |
| `app/Library/Usage/FakePaymentProviderGateway.php` | **REQUIRED** | Accepts the widened signature; adds `createCheckoutSessionCalls` recording |
| `app/Library/Usage/FundingAttemptResult.php` | **REQUIRED** | Adds nullable `redirectUrl` |
| `app/Library/Usage/AddonPurchaseResult.php` | **REQUIRED** | Adds the identical nullable `redirectUrl` |
| `app/Library/Usage/PaymentInstrumentManager.php` | **NOT REQUIRED** | Option 1 (§9) means `UsageBillingCheckoutManager` never calls `resolveProviderCustomer()` or `syncWorkspaceCheckoutPaymentMethod()`; the only interaction is a direct, already-unmodified `PaymentProviderGateway::retrievePaymentMethod()` call from inside `UsageBillingCheckoutManager` itself |
| `app/Jobs/Usage/ProcessPaymentProviderEvent.php` | **REQUIRED** | `processFundingAttempt()` purpose-aware branching (§10/§11) |
| `app/Jobs/Usage/ReconcileProviderPendingState.php` | **NOT REQUIRED** | Confirmed by direct read: purpose-agnostic, delegates entirely to `confirmAttemptFromReturn()` for every stuck attempt regardless of purpose — the corrected dispatch inside that method is sufficient; this job needs zero changes |
| `app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php` | **REQUIRED** | New `confirmFromReturn()` action with the exact Business-isolation check (§6); `initiate()` redirects to `$result->redirectUrl` |
| `routes/customer.php` | **REQUIRED** | One new `GET .../top-up/{attempt}/confirm` route |
| `app/Library/Usage/CheckoutSessionResult.php` | **NOT REQUIRED** | Already carries every field needed |
| `app/Library/Usage/PaymentMethodResult.php` | **NOT REQUIRED** | Already carries every field `formatPaymentMethodDisplay()` needs |

**9 REQUIRED, 4 NOT REQUIRED — mechanically recounted, identical to Round 1's own count (the reviewer's own independent count of 9 REQUIRED matches exactly).** No tenth production path is needed. No schema, model, or repository change of any kind is needed anywhere in this correction.

---

## 17. Test authority — every affected file exhaustively enumerated, every verdict exact

**Mechanical audit performed this round:** every file under `tests/Unit/Usage` and `tests/Feature/Usage` was searched for `initiateTopUp(`/`initiateAddonPurchase(` — the two calls whose current fixture behavior (off-session PaymentIntent, `paymentIntentOutcomes`, synchronous `Succeeded`) becomes false once this correction lands. **Exactly 14 files call one of these two methods; all 14 are verdicted below, individually, with no "verify at implementation time" hedge remaining.** Two further files not calling either method are assigned new proofs this round because they are the repository's own existing, correct home for an HTTP-level or real-call-site proof this correction specifically needs (§6/§8.B).

| File | Verdict | Exact resolution |
|---|---|---|
| `tests/Feature/Usage/TopUpStateMachineTest.php` | **REQUIRED — modification** | `test_successful_top_up_transitions_created_to_provider_pending_to_succeeded` **renamed and reframed** to `test_successful_top_up_creates_a_checkout_session_reaching_provider_pending_then_succeeded_on_confirmation`, preserving the transition-sequence invariant across two steps (`created → provider_pending`, then a separate `confirmAttemptFromReturn()` call reaching `succeeded`) instead of one synchronous call. `test_declined_card_transitions_to_failed_with_a_reason` and `test_requires_action_leaves_the_attempt_pending_authentication` are **removed** — the behavior they proved (a PaymentIntent declining or requiring 3DS at *creation* time) does not exist for Checkout Session creation, which itself does not fail on card issues; a Checkout Session's eventual failure surfaces at confirmation time via `checkout.session.expired` (already covered by a new test below), not at creation. `test_no_payment_instrument_denies_the_attempt_without_creating_a_provider_call` is **removed** (its invariant is now false) and **replaced by** the new `test_manual_top_up_succeeds_without_a_pre_saved_default_instrument`. `test_no_provider_customer_denies_the_attempt` and `test_repeat_commit_on_an_already_succeeded_attempt_is_idempotent` remain **unmodified** (§9's Option 1 preserves this exact behavior). |
| `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` | **REQUIRED — modification** | Every crash/replay test's setup is corrected to drive an explicit `confirmAttemptFromWebhook()`/`confirmAttemptFromReturn()` call (configuring `checkoutSessionOutcomes` for a paid/complete Session) before asserting the crash/replay behavior, since `initiateAddonPurchase()` no longer reaches `Succeeded` synchronously. The replay-hole proof itself is **preserved, reframed against the two-step lifecycle**, not weakened. `test_manual_top_up_already_succeeded_no_op_behavior_is_unchanged` requires the identical setup correction. |
| `tests/Feature/Usage/FundingAttemptPayerConsentTest.php` | **PARTIALLY REQUIRED** | `test_workspace_owner_can_initiate_a_top_up_when_workspace_pays`, `test_direct_business_owner_cannot_initiate_a_top_up_when_workspace_pays`, `test_direct_business_owner_can_initiate_a_top_up_when_business_pays` remain **unmodified**. `test_platform_administrator_can_resume_a_stuck_attempt` is **corrected**: replaces its `paymentIntentOutcomes = ['*' => 'requires_action']` fixture with a `provider_pending`, not-yet-`complete` `checkoutSessionOutcomes` entry, exercising `retryFundingAttemptAsAdministrator()`'s new Checkout-Session branch (§12). |
| `tests/Feature/Usage/FakePaymentProviderGatewayTest.php` | **REQUIRED** | One new test, `test_create_checkout_session_records_the_setup_future_usage_flag_per_call`, asserts `createCheckoutSessionCalls` records the received boolean. Every existing test remains valid unmodified. |
| `tests/Feature/Usage/WebhookDuplicateEventReplayTest.php` | **NOT REQUIRED** | Generic `provider_event_id` deduplication, orthogonal to purpose or provider-object family. |
| `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` | **REQUIRED — fixture-level correction only** | `createPendingAttempt()`'s mechanism is corrected to configure a `provider_pending`, not-yet-`complete` `checkoutSessionOutcomes` entry instead of `paymentIntentOutcomes`. The race/lock proof itself requires no redesign. |
| `tests/Feature/Usage/UsageBillingDashboardStripeIntegrationTest.php` | **REQUIRED** | `test_dashboard_renders_payment_method_and_funding_history_when_provider_is_configured` is **corrected** to call `confirmAttemptFromReturn()` after `initiateTopUp()` (with the fake's default complete/paid Session outcome) before asserting `assertSee('succeeded')`, preserving its own real intent (a succeeded row genuinely renders). `test_no_live_stripe_network_call_occurs_anywhere_in_this_flow` is **corrected** to assert `FundingAttemptState::ProviderPending` immediately after `initiateTopUp()` alone, then to also call `confirmAttemptFromReturn()` and assert `Succeeded` — proving the *entire* flow, not only its first half, completes without a live call. `test_dashboard_still_renders_the_honest_placeholder_when_provider_is_not_configured` and `test_every_new_route_is_isolated_by_the_existing_resolveviewablebusiness_pattern` remain **unmodified** (neither asserts on funding-attempt state). One new test, `test_initiating_a_top_up_redirects_to_the_hosted_checkout_url` (§6). |
| `tests/Feature/Usage/UsageBillingDashboardAuthorizationTest.php` | **REQUIRED** | One new test, `test_top_up_confirm_route_rejects_a_cross_business_attempt` (§6). Every existing test remains unmodified — none calls `initiateTopUp()`/`initiateAddonPurchase()`. |
| `tests/Feature/Usage/PayerChangeDuringPendingAttemptTest.php` | **REQUIRED — fixture correction, invariant preserved** | Both tests' `paymentIntentOutcomes = ['*' => 'requires_action']` fixture is replaced with a `provider_pending`, not-yet-`complete` `checkoutSessionOutcomes` entry; the actual invariant under test (the attempt's frozen `payer_type_snapshot` is unaffected by a later payer change; a new attempt under the old payer's authority is blocked once the change commits) is **fully preserved**, reframed against the Checkout-Session lifecycle. |
| `tests/Feature/Usage/FundingAttemptExactlyOnceWalletCreditTest.php` | **REQUIRED — fixture correction, invariant preserved** | Identical fixture correction; the exactly-once-credit invariant across two independent confirmation paths (synchronous return + a later redundant webhook) is **fully preserved**. |
| `tests/Feature/Usage/InstrumentDetachDuringPendingChargeTest.php` | **REQUIRED — re-scoped, invariant preserved** | Both tests are **re-scoped from `initiateTopUp()`/`ManualTopUp` to `initiateAutoRecharge()`/`AutoRecharge`** — the only purpose that still (a) requires a pre-saved instrument and (b) actually consumes it through confirmation, which is what both tests' real invariants are about. `test_detaching_an_instrument_with_a_pending_attempt_does_not_fail_that_attempt`'s invariant (a detach does not retroactively fail an already-in-flight charge) and `test_a_detached_instrument_is_never_selected_for_a_new_attempt`'s invariant (`no_payment_instrument` denial) both remain **fully meaningful and true** for `AutoRecharge`, and would otherwise become either vacuous or false for a Checkout-backed `ManualTopUp`. This is not a weakening — a Checkout-Session flow never references the local instrument row at all once the Session is created, so the original invariant was never really about `ManualTopUp` in the first place. |
| `tests/Feature/Usage/RedirectBeforeWebhookConfirmationTest.php` | **REQUIRED — fixture correction, invariant preserved** | `paymentIntentOutcomes = ['*' => 'requires_action']` replaced with a `provider_pending` `checkoutSessionOutcomes` entry; the "bare redirect return with no confirmation call never credits the wallet" invariant is **fully preserved**. |
| `tests/Feature/Usage/WebhookAmountCurrencyCustomerMismatchTest.php` | **REQUIRED — re-scoped, invariant preserved** | `createPendingAttempt()` is **re-scoped from `initiateTopUp()` to `initiateAutoRecharge()`** — the purpose that remains genuinely PaymentIntent-shaped, so the existing `payment_intent.succeeded`/`amount`-keyed webhook fixtures stay accurate rather than becoming a stale hybrid. The amount/currency/customer-mismatch-produces-zero-mutation invariant is **fully preserved**, now honestly scoped. The Checkout-Session-shaped sibling of this exact invariant (`amount_total`, `checkout.session.completed`) is new coverage in `TopUpStateMachineTest.php` (below), avoiding duplication. |
| `tests/Feature/Usage/WebhookMetadataSpoofMismatchTest.php` | **REQUIRED — re-scoped, invariant preserved** | Identical re-scoping of `createPendingAttempt()` to `initiateAutoRecharge()`, for the identical reason. The metadata-spoof-produces-zero-mutation invariant is **fully preserved**. |
| `tests/Feature/Usage/AmountCurrencyConversionTest.php` | **REQUIRED — assertion-only correction** | `test_bc_round_half_up_at_representative_and_boundary_values` (a pure static-method test) and `test_conversion_fails_closed_for_an_unrecognized_currency_code` (validated before the purpose-based branch splits) remain **unmodified**. `test_conversion_is_never_hard_coded_to_usd_and_succeeds_for_a_zero_decimal_currency`'s assertion is **corrected** from `FundingAttemptState::Succeeded` to `FundingAttemptState::ProviderPending` — the currency-conversion invariant itself (a zero-decimal currency converts and proceeds past the exponent lookup without failing) is **fully preserved**; only the terminal state assertion changes, since Checkout Session creation cannot synchronously reach `Succeeded`. |
| `tests/Feature/Usage/StripeAmountMinMaxValidationTest.php` | **REQUIRED — assertion-only correction** | `test_an_amount_below_the_minimum_is_rejected_before_any_provider_call` and `test_an_amount_above_the_eight_digit_maximum_is_rejected` remain **unmodified** (validated before the branch splits). `test_an_amount_at_exactly_the_minimum_succeeds` and `test_an_amount_at_exactly_the_eight_digit_maximum_succeeds` have their assertion **corrected** from `Succeeded` to `ProviderPending` — the boundary-value invariant itself is **fully preserved**. |
| `tests/Feature/Usage/CrossBusinessPaymentIsolationTest.php` | **NOT REQUIRED — verified, not modified** | `test_a_business_owned_instrument_is_never_visible_from_a_different_businesss_dashboard` never calls `initiateTopUp()`. `test_funding_history_repository_lookup_is_business_scoped` calls it but asserts only row **counts** and dashboard-visibility isolation, never the attempt's `state` value — confirmed by direct re-read to be entirely unaffected by which provider object is used. |
| `tests/Feature/Usage/WebhookSlotAgreementSubjectRoutingTest.php` | **REQUIRED** | One new test, `test_initiate_slot_agreement_checkout_passes_setup_future_usage_true` (§8.B). Every existing test remains unmodified — none is affected by the `ManualTopUp`/`AddonPurchase` provider-object correction. |

**No new test file is authorized anywhere in this contract.** Every new proof is assigned to one of the 18 files above.

**Full new-test list, each with its exact assigned file:**

| New test | File |
|---|---|
| `test_manual_top_up_creates_a_checkout_session_not_a_payment_intent` | `TopUpStateMachineTest.php` |
| `test_manual_top_up_succeeds_without_a_pre_saved_default_instrument` | `TopUpStateMachineTest.php` |
| `test_initiate_top_up_returns_a_hosted_redirect_url` | `TopUpStateMachineTest.php` |
| `test_confirm_from_return_never_trusts_the_query_string_alone` | `TopUpStateMachineTest.php` |
| `test_checkout_backed_attempt_starts_with_the_pending_checkout_sentinel_and_finalizes_it_on_confirmation` | `TopUpStateMachineTest.php` |
| `test_failed_or_expired_checkout_never_finalizes_a_payment_method_display_snapshot` | `TopUpStateMachineTest.php` |
| `test_checkout_session_completed_webhook_confirms_a_manual_top_up` | `TopUpStateMachineTest.php` |
| `test_checkout_session_expired_marks_the_attempt_failed` | `TopUpStateMachineTest.php` |
| `test_checkout_amount_total_is_validated_against_the_expected_amount` | `TopUpStateMachineTest.php` |
| `test_wrong_amount_currency_customer_object_or_operation_is_rejected_for_a_checkout_backed_event` | `TopUpStateMachineTest.php` |
| `test_a_payment_intent_event_cannot_confirm_a_manual_top_up_attempt` | `TopUpStateMachineTest.php` |
| `test_create_checkout_session_records_setup_future_usage_false_for_manual_top_up` | `TopUpStateMachineTest.php` |
| `test_completing_a_checkout_backed_top_up_never_creates_or_changes_a_reusable_instrument` | `TopUpStateMachineTest.php` |
| `test_a_paymentmethod_with_no_customer_attachment_still_finalizes_the_display_snapshot` | `TopUpStateMachineTest.php` |
| `test_a_paymentmethod_with_a_contradictory_customer_skips_display_finalization_but_still_credits` | `TopUpStateMachineTest.php` |
| `test_addon_purchase_creates_a_checkout_session_not_a_payment_intent` | `AddonPurchaseTransitionAuditTest.php` |
| `test_checkout_session_completed_webhook_confirms_an_addon_purchase` | `AddonPurchaseTransitionAuditTest.php` |
| `test_initiate_addon_purchase_creates_a_checkout_session_and_returns_a_hosted_redirect_url` | `AddonPurchaseTransitionAuditTest.php` |
| `test_create_checkout_session_records_setup_future_usage_false_for_addon_purchase` | `AddonPurchaseTransitionAuditTest.php` |
| `test_auto_recharge_still_creates_an_off_session_payment_intent_and_snapshots_the_instrument_at_creation` | `FundingAttemptPayerConsentTest.php` |
| `test_auto_recharge_webhook_confirmation_performs_no_new_provider_call` | `FundingAttemptPayerConsentTest.php` |
| `test_platform_administrator_cannot_originate_a_fresh_top_up_via_checkout_session` | `FundingAttemptPayerConsentTest.php` |
| `test_completing_a_top_up_never_enables_auto_recharge` | `FundingAttemptPayerConsentTest.php` |
| `test_a_checkout_session_event_cannot_confirm_an_auto_recharge_attempt` | `FundingAttemptPayerConsentTest.php` |
| `test_create_checkout_session_records_the_setup_future_usage_flag_per_call` | `FakePaymentProviderGatewayTest.php` |
| `test_top_up_confirm_route_rejects_a_cross_business_attempt` | `UsageBillingDashboardAuthorizationTest.php` |
| `test_initiating_a_top_up_redirects_to_the_hosted_checkout_url` | `UsageBillingDashboardStripeIntegrationTest.php` |
| `test_initiate_slot_agreement_checkout_passes_setup_future_usage_true` | `WebhookSlotAgreementSubjectRoutingTest.php` |
| `test_duplicate_webhook_and_browser_return_credit_a_checkout_backed_top_up_exactly_once` | `ConcurrentTopUpConcurrencyTest.php` |

`test_auto_recharge_still_creates_an_off_session_payment_intent_and_snapshots_the_instrument_at_creation` (in `FundingAttemptPayerConsentTest.php`) is the exact, named answer to "prove `AutoRecharge` still freezes the selected saved default instrument display at attempt creation" — it asserts `payment_method_display_snapshot` immediately after `initiateAutoRecharge()` matches `formatInstrumentDisplay()`'s expected value for the already-saved instrument, with no additional test method needed elsewhere.

`test_completing_a_checkout_backed_top_up_never_creates_or_changes_a_reusable_instrument` (in `TopUpStateMachineTest.php`) is the exact, direct no-reusable-instrument-side-effect proof: captures `business_payment_instruments` count and the provider-customer's default-instrument id before a Checkout completion, completes it, asserts the count is unchanged, the default is unchanged, `payment_method_display_snapshot` becomes the truthful safe display string, and `auto_recharge_enabled` remains unchanged/false.

**Preserved invariant coverage, explicitly confirmed present somewhere in the corrected suite:** amount boundaries (`StripeAmountMinMaxValidationTest.php`, assertion-corrected), currency conversion (`AmountCurrencyConversionTest.php`, assertion-corrected), payer-snapshot stability (`PayerChangeDuringPendingAttemptTest.php`, fixture-corrected), cross-Business isolation (`CrossBusinessPaymentIsolationTest.php`, unmodified; `UsageBillingDashboardAuthorizationTest.php`, new top-up-confirm-route test), redirect-alone-causes-no-credit (`RedirectBeforeWebhookConfirmationTest.php`, fixture-corrected), exactly-once credit (`FundingAttemptExactlyOnceWalletCreditTest.php`, fixture-corrected; `ConcurrentTopUpConcurrencyTest.php`, new Checkout-backed sibling test), metadata-spoof rejection (`WebhookMetadataSpoofMismatchTest.php`, re-scoped to `AutoRecharge`), amount/currency/customer-mismatch rejection (`WebhookAmountCurrencyCustomerMismatchTest.php`, re-scoped to `AutoRecharge`; new Checkout-shaped sibling in `TopUpStateMachineTest.php`), instrument-detach semantics for the flow that actually consumes stored instruments (`InstrumentDetachDuringPendingChargeTest.php`, re-scoped to `AutoRecharge`).

**Do not push general §35 cleanup into this correction** — every test change above is scoped exclusively to the provider-object/webhook-classification/display-snapshot defect this contract locks.

---

## 18. Implementation test gates

```
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan migrate:fresh --env=testing
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Unit/Usage tests/Feature/Usage
git diff --check
```

`migrate:fresh --env=testing` is required as environment hygiene despite no schema change (§3/§5). No live Stripe call is permitted in any automated test. **§17's expanded, exhaustively-enumerated allowlist is designed to be sufficient to make the entire `tests/Unit/Usage tests/Feature/Usage` suite pass green without touching any test path this contract does not name** — the mechanical grep in §17's own header confirms no fifteenth calling file exists. **These focused gates do not replace M6's later six-gate regression.**

---

## 19. Relationship to the other five remaining remediations

Not authorized by this correction, and not unblocked by its merge: Receipt Boundary (#3), Job/Event Dispatch Completion (#4), Admin Usage Billing Surface (#5), Provider Refund/Dispute Outcome Handling (#6), §35 Test-Coverage Completion (#7). M6 remains frozen until every required remediation is merged and a fresh static conformance audit passes.

---

## 20. M6 resumption rule — unchanged

This contract does not touch `agent/rfc-005-m6`. After **all** separately-authorized pre-M6 corrections are eventually merged: discard/reset the zero-commit old `agent/rfc-005-m6`, recreate it fresh from corrected `origin/main`, repeat full static conformance from scratch, rerun all six M6 regression gates, write both M6 documents, obtain human merge, run the post-merge exact-tag-candidate gate, and only then seek separate explicit human authorization for the annotated tag.

---

## 21. Contract PR scope

This governance branch changes exactly **one** file:

```
docs/automation/RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md
```

Not modified: `docs/automation/RFC-005-M6-CONTRACT.md`, `docs/automation/AI-AUTONOMY-STATE.json`, `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`, any `app/**`, `tests/**`, `database/**`, `routes/**`, `config/**`, `resources/**`, workflow, or package file.

Before commit: `git diff --check` clean; exactly one changed path confirmed via `git diff --name-only origin/main`.

Commit title (this correction round): `docs: finalize RFC-005 funding provider-flow contract`.

Push normally to `chore/rfc-005-funding-provider-flow-correction-contract`. No force push. Not marked Ready. Not merged.

---

## 22. Future implementation gates — restated for the implementation PR

1. Confirm the cumulative diff is a subset of exactly the 9 REQUIRED production paths (§16) and the exactly-named REQUIRED/PARTIALLY-REQUIRED test files and test names (§17) — no eleventh production path, no test file and no test name beyond §17's two tables.
2. Run `migrate:fresh --env=testing`, then `artisan test tests/Unit/Usage tests/Feature/Usage`, then `git diff --check` (§18), recording exact test/assertion/runtime counts, zero failures, exit 0.
3. Confirm every `AutoRecharge`-scoped existing test still passes (§13).
4. Confirm the existing slot-agreement Checkout Session flow's own tests still pass unmodified.
5. Confirm `AI-AUTONOMY-STATE.json`, package files, migrations, and RFC/governance docs remain untouched.
6. Confirm this correction's own two-round budget (§0) is fully consumed (2/2) — this contract carries zero remaining ordinary correction rounds into implementation; any implementation-time contradiction requires a fresh, separately-authorized governance decision, not a silent broadening.

This implementation-time gate does not replace M6's later six-gate regression (§18/§20).
