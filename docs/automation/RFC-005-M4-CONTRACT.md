# RFC-005 Milestone 4 — Additional-Slot Agreement and Add-ons

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This contract authorizes drafting this one document only.** Merging it
authorizes the bounded M4 implementation this document specifies — to be
made as its own separate, later, explicitly bounded implementation PR.
Merging this document does **not** itself write any `app/`, `database/`,
`routes/`, `config/`, or `resources/` file, does not provision live Stripe
credentials, does not authorize any production/live charge, and does not
begin M5 (metered feature classification) or M6 (conformance/tag) in any
way.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m4-contract`, in an isolated linked
  worktree (`../rfc-005-m4-contract-worktree`), based on `origin/main` at
  `e3ea1674cb32985426100a6d605aa16a0aa8d3ff` (merge of PR #102, RFC-004
  Amendment 2 — the catalog-pricing operator surface).
- **Both of M4's own named cross-RFC prerequisites are now merged:**
  - RFC-004 Amendment 1 (payment-verified additional-slot allocation seam)
    — PR #98, merge commit `74546b585c7cc74ec37a414f5589490b62b773a0`.
  - RFC-004 Amendment 2 (catalog-pricing operator surface) — PR #102,
    merge commit `e3ea1674cb32985426100a6d605aa16a0aa8d3ff`.
  Confirmed by direct read of the currently-merged
  `app/Library/Entitlement/EntitlementManager.php`: both
  `allocateAdditionalBusinessSlotsFromVerifiedPayment()` (§8 below) and
  `updateCatalogPricing()` (§9 below) exist, exactly as each amendment's
  own contract specified, with no outstanding correction round pending on
  either (Amendment 1: 1 of 2 rounds consumed; Amendment 2: 1 of 2 rounds
  consumed — both closed).
- `maximum_correction_rounds: 2` applies to this contract, matching every
  prior RFC-004/RFC-005 milestone contract's own convention.
- Any path required during the future implementation but absent from
  §25's own numbered allowlist is a stop-and-report condition — not a
  silent workaround. The stop threshold is the allowlist's own final
  count **plus one** (§29).
- Drafting this contract makes **zero** application changes. No `app/`,
  `database/`, `routes/`, `config/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by this
  branch — only this one new document (§2).
- **Audit discipline — bounded, M4-only, no repository-wide exploration
  and no delegated research agent, exactly as instructed:** RFC-005 §18,
  §22, §24–§31, §35–§39 (read in full); the merged M3 contract's §4 (status
  model), §6 (exact M3 scope), §23 (allowlist), §27/§28 (stop
  conditions/self-audit) sections specifically, as the precedent this
  document's own structure and status model mirror; the current, merged M3
  implementation files M4 concretely extends or must widen
  (`UsageBillingCheckoutManager.php`, `ProcessPaymentProviderEvent.php`,
  `StripeWebhookController.php`, `Contracts/PaymentProviderGateway.php`,
  `AppServiceProvider.php`, `app/Console/Kernel.php`, `routes/customer.php`,
  `routes/admin.php`); the merged RFC-004 Amendment 1 and Amendment 2
  contracts/corrections (already fully read and, in this session,
  authored/implemented in full — not re-read verbatim here); the two exact
  RFC-004 `EntitlementManager` seams M4 calls
  (`allocateAdditionalBusinessSlotsFromVerifiedPayment()`,
  `updateCatalogPricing()`) plus the one existing read-only method M4
  needs for quote construction (`listPlanCatalogSummaries()`,
  `getWorkspaceEntitlementSummary()`); and only the specific existing
  tests/routes/controllers/jobs/gateway files named throughout this
  document. No unrelated legacy UI or unrelated module was inspected.

---

## 1. Mandatory preflight — verified

- `origin/main` at `e3ea1674c...` is a genuine ancestor of this branch's
  own base (`git merge-base --is-ancestor` — trivially true, this branch
  *is* `origin/main`).
- `ultimatesms_testing` remains the only test database this contract's
  future implementation may use, matching every prior contract.
- `bcmath` remains confirmed enabled (RFC-005 M1 contract, re-confirmed
  unchanged by M2/M3, not re-verified here — no new PHP extension
  dependency is introduced by M4).
- No RFC-004 file is modified by this document, and none is authorized to
  be modified by the future M4 implementation (§8/§9, §27).

---

## 2. This contract's own exact file scope

Exactly one file, this document:
`docs/automation/RFC-005-M4-CONTRACT.md`.

---

## 3. Mandatory repository audit — findings

Direct reads performed for this contract, each finding relied upon below:

1. **`EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`
   exists exactly as Amendment 1 specified** (`app/Library/Entitlement/EntitlementManager.php:778`):
   ```php
   public function allocateAdditionalBusinessSlotsFromVerifiedPayment(
       Workspace $workspace,
       int $additionalSlotsToAdd,
       int $requestingCustomerUserId,
       int $paymentVerifiedForWorkspaceId,
       string $paymentIdempotencyKey,
       string $paymentProviderReference,
       ?string $reason = null,
   ): WorkspacePlanAssignment
   ```
2. **`EntitlementManager::updateCatalogPricing()` exists exactly as
   Amendment 2 (Correction Round 1) specified**, persisting
   `price`/`currency_id`/`additional_business_slot_price_ratio` through
   `WorkspacePlanCatalogRepository::update()`, platform-admin-only,
   durably audited via `workspace_plan_catalog_pricing_changes`. M4 never
   calls this method — catalog pricing is read-only from M4's perspective
   (§9).
3. **`EntitlementManager::listPlanCatalogSummaries(): array`** (existing,
   unmodified) returns one `WorkspacePlanCatalogSummary` per seeded tier —
   `id`, `tier`, `price` (nullable string), `currencyId` (nullable int),
   `additionalBusinessSlotPriceRatio` (nullable string),
   `isActive` — exactly the fields M4's quote construction needs, already
   public, already unrestricted to call from outside `EntitlementManager`.
4. **`EntitlementManager::getWorkspaceEntitlementSummary(Workspace $workspace)`**
   (existing, unmodified) returns a `WorkspaceEntitlementSummary` exposing
   `tier` (nullable `WorkspacePlanTier`) and `isComplimentary` (nullable
   bool) — the two facts M4 needs to determine which catalog row applies
   to a given Workspace and whether a paid agreement is even the correct
   flow for it (§8.1).
5. **No RFC-004 repository is reachable from outside `EntitlementManager`
   and its own six repositories** (RFC-004 §20, unchanged, re-confirmed by
   the class's own docblock: "this class and its repositories are the
   only authorized readers/writers of the six RFC-004 tables"). M4 must
   therefore reach every RFC-004 fact exclusively through the three public
   methods named above — never `WorkspacePlanCatalogRepository`,
   `WorkspacePlanAssignmentRepository`, or any other RFC-004 repository
   directly, and never a raw query against `workspace_plan_catalog`,
   `workspace_plan_assignments`, or `workspace_entitlement_transitions`.
6. **`UsageBillingCheckoutManager` already declares itself the eventual M4
   host**, verbatim in its own class docblock: "Additional-slot-agreement/
   renewal/add-on-purchase responsibility is explicitly out of scope (M3
   contract §7) — a future M4 contract extends this same class
   incrementally, exactly as M2 extended `UsageWalletManager`." M4 extends
   this one class; it does not create a second manager.
7. **`PaymentProviderGateway`'s `createOffSessionPaymentIntent()` already
   accepts an opaque `array $metadata`** — confirmed by direct read of both
   the interface and `UsageBillingCheckoutManager::initiateCharge()`'s own
   existing call
   (`['app_subject_kind' => 'funding_attempt', 'app_subject_id' => ...,
   'app_operation_id' => ...]`). The metadata *shape* is already fully
   generic; only the *values* M4 supplies differ. **Exactly two new
   `app_subject_kind` values are introduced: `slot_agreement` and
   `slot_renewal_charge` — RFC-005 §17.C's own canonical set, confirmed by
   direct re-read.** Add-on purchases deliberately charge through the
   existing `funding_attempt` machinery
   (`business_usage_addon_purchases.funding_attempt_id` is the sole
   authoritative link, §18) — an add-on's outbound PaymentIntent metadata
   remains `app_subject_kind: 'funding_attempt'`, distinguished from a
   `ManualTopUp`/`AutoRecharge` attempt only by its own persisted
   `purpose = FundingAttemptPurpose::AddonPurchase` (§15, §21) — never a
   fourth subject kind.
7a. **(This refinement) The prior claim that `PaymentProviderGateway`,
   `StripePaymentProviderGateway`, and `FakePaymentProviderGateway`
   require zero changes was wrong — confirmed by direct read of the
   merged interface: it exposes SetupIntent create/retrieve, off-session
   PaymentIntent create/retrieve, and webhook verification only. No
   Checkout Session create/retrieve seam exists anywhere in this
   codebase.** RFC-005 §22 explicitly requires the initial additional-slot
   purchase to be a **customer-present initial authorization**, and
   `additional_business_slot_agreements.provider_session_or_intent_reference`
   is explicitly documented as "the initial Checkout Session id" (§18) —
   a fundamentally different Stripe object than an off-session
   PaymentIntent, with its own id shape, its own `status`/`payment_status`
   pair, its own `url` redirect field, and its own `amount_total` (not
   `amount`) field. **The gateway genuinely requires a narrow, explicit
   extension for M4** (§15a, §25) — reusing `createOffSessionPaymentIntent()`
   for the initial purchase would silently replace RFC-005's own
   customer-present Checkout Session design with an off-session charge,
   which this contract does not authorize.
7b. **(This refinement) `StripePaymentProviderGateway::verifyWebhookSignature()`
   reads `$object->amount ?? null`** (`app/Library/Usage/StripePaymentProviderGateway.php:190`,
   confirmed by direct read) **— a field that does not exist on a Stripe
   Checkout Session object** (whose own amount field is `amount_total`).
   Every `checkout.session.*` webhook event would therefore normalize to
   `amountMinorUnits: null` under the current, unwidened implementation.
   This method requires a narrow widening (§15a) to also check
   `amount_total` when `amount` is absent — existing PaymentIntent webhook
   normalization (`$object->amount`) is unchanged for every event type
   that already carries it.
7c. **No `CheckoutSessionResult`-shaped DTO exists** — confirmed by a
   listing of `app/Library/Usage/*Result.php`; the closest existing shape,
   `PaymentIntentResult`, has no `redirectUrl`/`paymentStatus`/
   `providerCustomerId` fields and cannot represent a Checkout Session
   without conflating two genuinely different provider objects. A new DTO
   is required (§25).
7d. **No manager method exists for a mid-period slot increase, a
   scheduled-renewal charge, a lapsed/failed-renewal owner retry, or a
   shared verified-allocation routine** — confirmed by direct re-read of
   this contract's own prior §21 draft: it named `quoteAdditionalSlotAgreement()`,
   `initiateSlotAgreementCheckout()`, `confirmSlotAgreementFromReturn()`,
   `confirmSlotAgreementFromWebhook()`, `retrySlotRenewalAsAdministrator()`,
   `allocateSlotAgreementAsAdministrator()`, `requestSlotAgreementCancellation()`,
   and `initiateAddonPurchase()` — leaving §11's own mid-period-increase
   prose, §11's own scheduled-renewal-creation prose, §13's own
   owner-recovery prose, and §22's own `ReconcileSlotAgreementAllocation`/
   `FinalizeSlotAgreementCancellation` jobs with no named manager entry
   point to call, in direct tension with this contract's own "no
   controller/job ever writes an M4 table directly" claim (§16). Corrected
   in §21 below — no new file, an addition to the same already-allowlisted
   manager (item 41).
7e. **`ReconcileSlotAgreementAllocation`'s own prior description named a
   "system-actor" call into `allocateSlotAgreementAsAdministrator()`-equivalent
   logic** — a synthetic-administrator pattern this contract's own §8
   explicitly forbids elsewhere ("no fake administrator, synthetic actor
   ... is authorized anywhere in M4"). Corrected in §21/§22 below via one
   shared internal routine neither reconciliation nor the real
   administrator action needs a fake identity to reach.
7f. **No RFC-005 §22/§23 text defines an executable trigger, job, gateway
   call, or manager method reaching `additional_business_slot_agreements.state`
   values `refund_pending`/`refunded`** — confirmed by a targeted re-read
   of §22–§23 in full: the only appearance of either value anywhere in
   the RFC is the `state` column's own enum listing (§18). §23's own body
   is entirely about the wallet ledger's four entry types
   (`ManualCredit`/`Refund`/`DisputeChargeback`/`UsageChargeReversal`, all
   wallet-balance-level, unrelated to a slot agreement's own state
   machine) plus receipts/tax boundary. **These two states are reserved
   schema values, not an M4-owned executable feature** (§8a below) — this
   contract's own prior draft's "Refund paths ... remain their own
   distinct states" phrasing is corrected to say this explicitly rather
   than imply an executable path this contract does not actually define.
7g. **(This refinement) Stripe's own Checkout Session API requires `mode:
   'payment'` to carry at least one `line_items` entry — confirmed against
   Stripe's own documented request shape.** The prior draft's §15a sketch
   passed `amount`/`currency` directly to the Session create call with no
   `line_items` at all — a request Stripe would reject outright. Corrected
   to one non-adjustable line item built from inline `price_data`
   (`currency`, `unit_amount`, `product_data.name`), never a
   separately-created Stripe Price/Product — `unit_amount` derives only
   from the frozen agreement amount, never an independent retail price
   (§15a).
7h. **(This refinement) `CheckoutSessionResult::$redirectUrl` was declared
   non-nullable, but Stripe's own Checkout Session `url` field exists only
   while the Session is `open`** — a `retrieveCheckoutSession()` call
   against an already-`complete` Session legitimately returns `url: null`,
   confirmed against Stripe's own documented object shape. Corrected to
   `?string` (§15a); `createCheckoutSession()` itself still fails closed if
   a **newly-created** Session unexpectedly carries no `url`, since the
   browser cannot continue without one.
7i. **(This refinement) No field on any existing or drafted DTO captures
   the actual `PaymentMethod` a completed Checkout Session used.** The
   Session's own `payment_intent` reference alone is insufficient — the
   underlying PaymentIntent must itself be resolved (via expansion or an
   equivalent exact retrieval) to learn which PaymentMethod the customer
   ultimately paid with, since hosted Checkout does not guarantee the
   locally-assumed default card is the one actually charged.
   `CheckoutSessionResult` gains `public ?string $providerPaymentMethodId`
   (§15a).
7j. **(This refinement) `PaymentInstrumentManager`'s existing methods
   (`resolveProviderCustomer()`, `confirmSetupIntentAndAttach()`,
   `detachInstrument()`, `setDefaultInstrument()`) are all `Business`-scoped,
   gated by `assertChargeCausingConsent()`'s Business-payer-authority
   split** — confirmed by direct read of
   `app/Library/Usage/PaymentInstrumentManager.php`. No Workspace-owner-only
   method exists to reconcile a Checkout-selected `PaymentMethod` into
   `business_payment_instruments` as the Workspace's own default
   usage-billing instrument. This is a genuine gap: hosted Checkout can
   result in a card different from any previously-saved default, and M4
   must not later display or renew against the wrong instrument. A
   narrowly-scoped new method is authorized (§15c, §21, §25 item 94),
   reusing the class's own existing repository/gateway calls verbatim
   (`PaymentProviderCustomerRepository::findActiveByWorkspaceId()`,
   `PaymentProviderGateway::retrievePaymentMethod()`,
   `BusinessPaymentInstrumentRepository::findByProviderPaymentMethodId()`/
   `create()`/`clearDefaultForProviderCustomer()`) — confirmed sufficient
   without any schema change and without touching any other M3
   payment-instrument path.
7k. **(This refinement) M4's own prior §14 draft described
   `retrySlotRenewalAsAdministrator()` as mirroring
   `retryFundingAttemptAsAdministrator()`'s exact shape** — confirmed by
   direct read that M3's method (already merged, unchanged this milestone)
   only re-**retrieves** an already-created PaymentIntent from the
   provider; it never re-drives payment. That is correct, sufficient
   reconciliation behavior for M3's own scope, but it cannot satisfy M4's
   own "3 attempts" retry requirement, which requires a genuine second
   provider-side attempt after a card decline — merely re-retrieving an
   already-failed PaymentIntent can never turn it into a success. No
   gateway method exists to re-confirm an existing PaymentIntent against a
   (possibly new) payment method. A narrow `confirmPaymentIntent()`
   gateway addition is authorized (§15a), added to the already-allowlisted
   gateway files (items 88–90, no new path) — M3's own
   `retryFundingAttemptAsAdministrator()` remains completely unmodified.
7l. **(This refinement) `UsageBillingCheckoutManager` has no
   `assertPlatformAdministrator()`-equivalent helper of its own** —
   confirmed absent by a direct, targeted search of the file. The
   established precedent for this exact check already exists elsewhere in
   the same `app/Library/Usage/*` family:
   `UsageWalletManager::assertPlatformAdministrator()`, a direct
   `users.is_admin` read mirroring `EntitlementManager::assertPlatformAdministrator()`'s
   exact shape (RFC-004 §20). M4's own three "AsAdministrator"-named
   manager methods must not rely solely on being reached through the admin
   route/middleware — each must independently verify the supplied actor is
   a real platform administrator before any mutation or provider call
   (§21).
7m. **(This refinement) Test #86's own drafted description asserts an
   impossible repository-wide claim** — "no file outside
   `EntitlementManager` and six repositories references
   `workspace_plan_catalog`/`workspace_plan_assignments`/
   `workspace_entitlement_transitions`" is false on its face, since
   RFC-004's own models, migrations, and tests legitimately reference their
   own tables. Corrected to the actual, narrower M4 source-boundary
   invariant: no *M4-authorized production path* imports, resolves, or
   raw-queries an RFC-004 repository or table directly (§25 item 86).
8. **`ProcessPaymentProviderEvent.php` hardcodes `'funding_attempt'` as the
   only recognized `app_subject_kind`** (`app/Jobs/Usage/ProcessPaymentProviderEvent.php:68`:
   `($metadata['app_subject_kind'] ?? null) !== 'funding_attempt'` →
   `markFailed('missing_or_unrecognized_metadata')`). **This file must be
   widened for M4** — confirmed by direct read, not assumed — exactly the
   class of pre-existing-file omission this contract's own allowlist
   reconciliation (§26) is required to catch before drafting the final
   allowlist, per the explicit instruction not to repeat the "prose
   requires it but allowlist omitted it" pattern M3's own two correction
   rounds already exhibited.
9. **`StripeWebhookController.php` requires no change** — confirmed by
   direct read: it performs signature verification and raw event
   persistence only, dispatching `ProcessPaymentProviderEvent` with no
   subject-kind awareness of its own. All new routing logic belongs
   exclusively in the one job named above.
10. **`ReconcileProviderPendingState.php` is funding-attempt-specific**
    (confirmed by direct read: queries only `BusinessFundingAttempt` rows)
    and requires no change — M4's own stuck-allocation reconciliation is a
    new, separate job (`ReconcileSlotAgreementAllocation`, §22), not a
    widening of this one, matching RFC-005 §29's own distinct naming.
11. **`app/Console/Kernel.php` is where M3's own recurring jobs are
    registered** (`$schedule->job(new PurgeExpiredWebhookPayloads())->hourly()`,
    `$schedule->job(new ReconcileProviderPendingState())->everyFiveMinutes()`)
    — confirmed by direct read. **This file must be widened for M4's own
    three new scheduled jobs** (§22).
12. **`app/Providers/AppServiceProvider.php`'s existing `$bindings` array**
    is where every RFC-004/RFC-005 repository is bound (confirmed by
    direct read of the existing `BusinessFundingAttemptRepository`/
    `PaymentProviderEventRepository` entries) — **must be widened for M4's
    seven new repository pairs** (§25).
13. **`routes/customer.php`'s existing Usage Billing routes are
    Business-scoped** (`{workspaceUid}/businesses/{businessUid}/usage-billing/...`),
    while the additional-slot agreement is explicitly **Workspace**-scoped
    (RFC-005 §22: `workspace_id`, "paid via a Workspace-owned instrument
    only"). Confirmed by direct read: the only existing non-Business-nested
    customer route is `{workspaceUid}` → `Workspace\WorkspaceController@show`
    (`routes/customer.php:580`). **M4's new customer routes/controller
    therefore belong in the `Customer\Workspace\*` namespace, not
    `Customer\Business\*`** — a locked design decision, not left
    discretionary, stated here with its exact reasoning rather than
    silently assumed.
14. **`routes/admin.php`'s existing `provider-events` routes establish the
    exact admin-route convention M4's own new admin routes must match**
    (confirmed by direct read, `routes/admin.php:695-696`): no literal
    `admin/` path segment, no `admin.`-prefixed route name, a dedicated
    controller (`PaymentProviderEventController`), matching RFC-004's own
    `WorkspacePlanCatalogController` convention. **No admin Usage Billing
    controller of any kind exists yet** (confirmed by a targeted search —
    `Admin\UsageBillingController`, named provisionally by RFC-005 §30, was
    never created by M1–M3). M4 does **not** create that broader,
    all-milestone unified controller — doing so would pull in M1/M2/M3
    admin responsibilities (view balance, issue credit, set billing
    status, dispose exhausted webhook events) that no milestone has
    contracted yet. M4 creates a narrowly-scoped
    `Admin\AdditionalBusinessSlotAgreementController` covering exactly
    M4's own three admin capabilities (§13, §25) — recorded explicitly as
    a scope-narrowing decision, not a silent omission.
15. **No `InitiateSlotAgreementCheckoutRequest`-shaped Form Request
    convention gap** — confirmed by direct read of
    `app/Http/Requests/Customer/Business/InitiateTopUpRequest.php` as the
    exact precedent: one dedicated Form Request per charge-causing action.
    M4 follows this convention under `Customer\Workspace\*`/`Admin\*`
    (§25).
16. **No wallet-flow event from RFC-005 §29's own 17-event list has
    actually been dispatched by any milestone yet** — confirmed by a
    direct listing of `app/Events/Usage/`: only 5 of the 12 M1–M3-owned
    events exist (`BusinessBillingContactChanged`,
    `BusinessFundingAttemptFailed`, `BusinessFundingAttemptSucceeded`,
    `BusinessPayerChanged`, `BusinessWalletBillingStatusChanged`).
    `BusinessWalletCredited`/`Debited`/`DebtIncurred`/`DebtCleared`,
    `BusinessUsageReserved`/`Committed`/`ReservationReleased` do not exist.
    **This is a pre-existing M1–M3 scope gap, not M4's to fix** — recorded
    here explicitly (§7) so a future reader does not mistake M4's own
    5-event scope (§22) for an oversight; M4 creates exactly the 5 events
    RFC-005 §29 names as new-this-round and owned by M4's own tables, and
    no other.
17. **`AdvanceUsagePeriodBoundaries`, `SendLowBalanceNotification`,
    `SendAutoRechargeDisabledNotification`, and `SendReceiptNotification`
    (all named in RFC-005 §29's aggregate job list) do not exist in code**
    — confirmed by a targeted search. All four are M1–M3-owned wallet/
    recharge/receipt concerns, structurally unrelated to the additional-
    slot-agreement/add-on tables M4 owns. **Recorded as a pre-existing
    scope gap, not fixed by this contract** (§7) — matching the identical
    treatment the M3 contract's own §6 gave `business_billing_receipts`
    ("Stripe-hosted receipts authoritative for v1... left for a future
    milestone's own contract to schedule, once a genuine local-receipt
    requirement is identified").
18. **No mechanical source-boundary test exists yet** — confirmed by a
    targeted search across `tests/` for any file proving RFC-005 code
    never directly mutates an RFC-004 table. M4 must create the first one,
    scoped to its own new call sites (§25 item 86).
19. **`business_usage_addon_catalog` is confirmed, by direct re-read of
    RFC-005 §18, to launch with zero seeded rows** — no seeder of any kind
    is authorized by this contract (§10).

---

## 4. Contract status model

Two independent, separately tracked states, mirroring the M3 contract's
own §4 structure exactly:

**M4 test-mode implementation readiness: READY**, with one exception
narrower than "blocked" — see the qualifier below. Every M4 requirement
this document specifies — the seven-table schema, the extended
`UsageBillingCheckoutManager`, the Option A quote/checkout/renewal/
proration/cancellation/dunning/recovery lifecycle, the add-on
catalog/purchase/audit machinery, the widened webhook subject routing, the
narrowed administrator authority, and the full test suite (§27) — can be
deterministically implemented and verified with 100% faked/mocked provider
responses and zero live network calls, exactly matching M3's own
already-proven test-mode discipline.

**Qualifier — `payment_lapsed` grandfathered-capacity revocation (RFC-005
§39 item 13) is explicitly out of scope, not merely deferred within
scope.** M4 implements every RFC-defined mechanical consequence of a
lapsed payment — `payment_lapsed`, `payment_lapsed_at`, stopped automatic
renewals, manual/owner recovery, `payment_lapsed_cleared_at`, forward-only
`next_renewal_at` recomputation — fully test-mode-verifiable today. **It
does not implement any Business-slot revocation**, because RFC-005 itself
does not yet define one. This is not a blocker on M4's own deterministic
test-mode implementation; it is a permanent, explicit non-goal until a
separate, future, human-authorized decision defines a revocation policy
(§13).

**Production live-charging readiness: BLOCKED for both M4 features, on
independent, non-identical gate sets — corrected this refinement to
resolve a structural contradiction between this section and §5's own,
already-correct, per-item classification.** The prior draft listed the
add-on roster/pricing decision inside one unified "every one of the
following" list alongside the universal gates, which read as "unresolved
add-ons block all live charging" — contradicting §5 item 8's own explicit
"blocks live commercial launch of add-ons only." Corrected: **zero seeded
add-ons is itself a valid, permanent M4 launch state, in production
exactly as in test-mode** (§10) — an unresolved add-on roster never blocks
going live with the additional-slot agreement feature alone.

**Universal gates — block any live charge of any kind (slot-agreement or
add-on), none may be inferred from test-mode success:**

1. Live Stripe API keys are provisioned and stored via the platform
   operator's own secret-management process — never generated, requested,
   or handled by an implementation session.
2. A human explicitly authorizes switching the configured `mode`/
   `environment` from `test` to `live` in a real deployment environment
   (the same M3-shipped configuration surface, §19 of the M3 contract —
   M4 introduces no new mode/environment concept).
3. Production tax/VAT legal sufficiency is separately resolved (RFC-005
   §23, open item 6) — this contract is explicitly not legal advice and
   M4 makes no tax determination of any kind.
4. A separate, explicit human decision authorizes production use for this
   specific deployment, distinct from and in addition to any contract
   merge.

**Additional-slot-agreement-specific live gate — beyond the four universal
gates above, before any production Workspace may carry a real recurring
additional-slot charge:**

5. A separate, explicit human decision resolves RFC-005 §39 item 13 (the
   `payment_lapsed` revocation policy) **before** any production Workspace
   is permitted to remain in a lapsed state indefinitely without
   consequence — test-mode implementation does not require this
   resolution (no revocation is implemented either way, §4 qualifier
   above), but a live deployment carrying real, uncollected recurring
   charges indefinitely is a business-risk decision this contract does
   not make on a human's behalf. **This gate applies only to the
   additional-slot-agreement feature** — add-ons are one-time purchases
   with no renewal/lapse concept (§18), so this gate has no bearing on
   whether add-ons may go live.

**Add-on-specific live gate — independent of the additional-slot gate
above, blocks only offering a real, purchasable add-on, never the
additional-slot-agreement feature:**

6. The exact v1 add-on roster and pricing (RFC-005 §39 item 8) remains
   unresolved. This blocks only the live commercial launch of add-on
   purchases specifically — a live deployment may run the
   additional-slot-agreement feature in production, fully live, with
   `business_usage_addon_catalog` correctly holding zero rows
   indefinitely; that is not a degraded or partial state, it is exactly
   what RFC-005 §18 itself specifies. Resolving this gate means a human
   choosing a real `addon_key`/price/currency/fulfillment product and
   inserting it — an action this contract does not perform and does not
   invent a placeholder for merely to appear "resolved."

**Contract merge never authorizes production Stripe keys, live charges, a
live-mode deployment, an add-on catalog seed, or a `payment_lapsed`
revocation policy, under any circumstance.**

---

## 5. Open-decision classification (all 14 RFC-005 open items vs. M4)

| # | RFC-005 open item | Classification vs. M4 | Resolution |
|---|---|---|---|
| 1 | Exact initial retail usage rates per metered feature | Not M4 scope | M4 activates zero metered features. No effect. |
| 2 | Exact default Business monthly spend cap | Not M4 scope | M1/M2-owned, unaffected. |
| 3 | Exact default per-feature limits | Not M4 scope | M1/M2-owned, unaffected. |
| 4 | Exact auto-recharge default threshold | Not M4 scope | M3-owned mechanism, no new default invented by M4. |
| 5 | Owner/operator complimentary Agency Workspace metered-usage subsidy | Not M4 scope | M5/policy scope. No effect. |
| 6 | Invoice/tax/VAT operational provider and legal sufficiency | **Live-production gate only** | `NON-IMPLEMENTATION-READY` for production launch; does not block M4 test-mode implementation, since M4 causes no real charge. Explicit live gate (§4 item 3). |
| 7 | Timing of Agency client rebilling | Not M4 scope | `agency_rebill` remains inert. No effect. |
| 8 | **Exact v1 add-on roster and pricing** | **Blocks nothing structural; blocks live commercial launch of add-ons only** | M4 implements the full catalog/purchase/audit machinery with **zero** seeded rows (§10) — exactly what RFC-005 §18 itself specifies at M4 launch. No `addon_key`, display name, price, or fulfillment product is invented anywhere in this contract or its future implementation. Explicit live gate (§4 item 6). |
| 9 | Exact initial per-feature platform safety-limit ceilings | Not M4 scope | M2-owned, no metered feature exists yet. |
| 10 | v1 settlement currency and multi-currency scope | Not M4 scope | M1-resolved (wallet-scoped `currency_id`); M4 introduces no new currency concept, every M4 amount validated against the same already-resolved wallet/catalog currency. |
| 11 | The first actual metered feature(s) | Not M4 scope | M5-named. No effect. |
| 12 | Exact default monthly auto-recharge cap | Not M4 scope | M3-owned, unaffected by M4. |
| 13 | **Additional-slot `payment_lapsed` grandfathered-capacity revocation policy** | **Blocks no structural M4 implementation; explicit permanent non-goal until separately authorized** | M4 implements every RFC-defined lapse/recovery mechanism (§4 qualifier, §13) but performs **zero** slot revocation. A future revocation policy requires its own explicit human decision/authorization — not invented, not silently deferred without a named blocker (§4 item 5, §27). |
| 14 | **Cross-RFC additional-slot allocation authority blocker** | **Fully resolved before this contract — the prerequisite this contract itself confirms satisfied (§0)** | Resolved via RFC-004 Amendment 1 (merged, PR #98) — `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()` is the sole allocation entry point M4 calls (§8). |

**No item above prevents M4's own deterministic test-mode implementation.**
Items 8 and 13 are the only ones with any bearing on M4's own scope at
all, and both are satisfied by M4 shipping the full mechanism with zero
invented commercial/policy content — the identical "no financial default,
no policy invented" discipline M1–M3 already applied throughout.

---

## 6. Exact M4 scope

Derived exclusively from RFC-005 §36 item 4, §18, and §22:

- **Schema** — the seven tables named by RFC-005 §36 item 4 (§18 below):
  `additional_business_slot_agreements`,
  `additional_business_slot_agreement_transitions`,
  `additional_business_slot_renewal_charges`,
  `additional_business_slot_renewal_charge_transitions`,
  `business_usage_addon_catalog`, `business_usage_addon_purchases`,
  `business_usage_addon_purchase_transitions`.
- **Additional-slot agreement quote/checkout** — customer-present initial
  authorization via a genuine Stripe **Checkout Session** (§15a, never an
  off-session `PaymentIntent` for this one initial step), a Workspace-owned
  saved instrument for every subsequent renewal charge, quote snapshot of
  catalog price × ratio (§9).
- **Payment-verified allocation** — after local + provider-verified
  payment success, the manager calls RFC-004's
  `allocateAdditionalBusinessSlotsFromVerifiedPayment()` exactly once per
  logical purchase, never a direct entitlement mutation (§8).
- **Renewal** — `scheduled_renewal` and `mid_period_increase` charge
  kinds, exact-second proration, `change_operation_id`-anchored
  idempotency for repeated increases (§11).
- **Cancellation** — `cancel_at_period_end`, `cancellation_effective_at`,
  `FinalizeSlotAgreementCancellation` (§12).
- **Dunning, `payment_lapsed`, and recovery** — bounded retries, forward-
  only `next_renewal_at` recomputation on recovery, zero revocation
  (§13).
- **Add-on catalog/purchase/audit machinery** — structural only, zero
  seeded rows (§10).
- **Narrowed administrator authority extension** — resume/retry a stuck
  renewal, manual allocation action, mandatory-reason cancellation (§14).
- **Webhook subject-kind widening** — `ProcessPaymentProviderEvent`
  recognizes exactly two new subject kinds, `slot_agreement` and
  `slot_renewal_charge`, alongside the existing `funding_attempt`, whose
  own confirmation path becomes purpose-aware to also finalize an
  `AddonPurchase`-purpose attempt (§15).
- **Customer/admin HTTP surfaces** — narrowly scoped to M4's own three
  admin capabilities and the Workspace-level customer checkout/manage
  surface (§13, §17).
- **Local test-mode preview** — extending M3's own established preview
  procedure to the new checkout/renewal/cancellation flows.

---

## 7. Explicit M4 exclusions

Excluded, since RFC-005 does not assign them to M4, or because a targeted
audit (§3) confirmed they remain a pre-existing gap outside this
contract's own scope:

- Any Stripe/payment-provider/checkout/webhook infrastructure change
  beyond the two named widenings — `ProcessPaymentProviderEvent.php` (§3
  item 8) and the narrow, exact Checkout Session gateway extension (§3
  items 7a–7c, §15a) — no other gateway method, and no change to how
  SetupIntent/off-session-PaymentIntent flows already work, is authorized.
- Any `addon_key`, display name, retail price, currency, or fulfillment
  product for `business_usage_addon_catalog` — zero rows, permanently,
  until a separate future decision (§10).
- Any `payment_lapsed` grandfathered-capacity revocation logic of any kind
  (§4 qualifier, §13).
- M5 (metered feature classification) or M6 (conformance/tag) work of any
  kind.
- Any RFC-004 file change — catalog pricing and slot allocation are
  consumed exclusively through `EntitlementManager`'s three existing
  public methods (§8, §9).
- `AdvanceUsagePeriodBoundaries`, `SendLowBalanceNotification`,
  `SendAutoRechargeDisabledNotification`, `SendReceiptNotification`,
  `business_billing_receipts`, or any other M1–M3-owned wallet/recharge/
  receipt concern left unimplemented by M1–M3 (§3 items 16–17) — a
  pre-existing scope gap, not silently expanded into by this contract.
- A unified `Admin\UsageBillingController` covering M1–M3 administrative
  capabilities (view balance, issue credit, set billing status, dispose
  exhausted webhook events) — none of these exist yet and none is M4's to
  build; M4 creates only its own narrowly-scoped admin controller (§3
  item 14).
- Any change to `WorkspacePlanCatalogController`, `WorkspacePlanCatalogRepository`,
  `EloquentWorkspacePlanCatalogRepository`, `CurrencyRepository`, or
  `EntitlementManager` itself — every one is reused exactly as it exists
  today (§8, §9).

---

## 8. Required cross-RFC allocation boundary — locked

1. **No controller, route, webhook job, or repository may mutate an
   RFC-004 entitlement table directly, under any circumstance.** The
   *only* code path in the entire M4 implementation permitted to touch
   `workspace_plan_assignments`, `workspace_entitlement_transitions`, or
   any other RFC-004 table is a call to
   `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`.
   No fake administrator, synthetic actor, direct repository mutation, or
   `EntitlementManager` bypass of any kind is authorized anywhere in M4.
2. **`UsageBillingCheckoutManager` is the sole M4 caller** of that method.
   No job, controller, or event listener calls it directly — every
   allocation attempt routes through the manager's own new method (§21),
   matching the identical "manager is sole authority" discipline RFC-004
   §20 and every prior RFC-005 milestone already establish.
3. **Local durable evidence is verified first, in this exact order, before
   any allocation attempt:**
   1. The agreement's own `state` is `payment_succeeded` or
      `allocation_pending` (never `quote_created`, `checkout_pending`,
      `payment_failed`, `refund_pending`, `refunded`, or `canceled`) —
      verified against the agreement's own already-persisted row, not a
      caller-supplied claim.
   2. The initiating payment's own local record
      (`provider_session_or_intent_reference`) has already been
      **provider-verified** successful — either by the synchronous
      browser-return confirmation path or by a validated webhook event
      (§16), mirroring M3's own `confirmAttemptFromReturn()`/
      `confirmAttemptFromWebhook()` split exactly. Allocation is never
      attempted from a caller's unverified assertion that payment
      succeeded.
   3. Only once both checks pass does the manager call
      `allocateAdditionalBusinessSlotsFromVerifiedPayment()`, supplying:
      - `$workspace` — the agreement's own `workspace_id`, loaded fresh,
        never a caller-supplied Workspace instance from an unrelated
        request.
      - `$additionalSlotsToAdd` — the agreement's own `paid_delta`.
      - `$requestingCustomerUserId` — the agreement's own
        `requesting_customer_user_id`, the real Workspace owner who
        originated the checkout — **never** a platform administrator's
        own id, even when an administrator performs the manual allocation
        action (§14) on the payer's behalf; the payer's own identity is
        always what RFC-004's own audit trail records as "who this
        allocation was for."
      - `$paymentVerifiedForWorkspaceId` — the same Workspace id, the
        redundant same-Workspace check Amendment 1's own signature
        requires.
      - `$paymentIdempotencyKey` — a deterministic key derived from the
        agreement's own `local_idempotency_key` (never re-derived per
        call, never randomly generated), so a genuine retry of the same
        logical allocation — whether from `ReconcileSlotAgreementAllocation`
        re-attempting a stuck `allocation_pending` agreement, or from an
        administrator's manual retry — reuses the identical key and is
        absorbed by Amendment 1's own idempotency guarantee (§8 item 4
        below), never creating a second transition row.
      - `$paymentProviderReference` — the agreement's own
        `provider_session_or_intent_reference`.
      - `$reason` — `null` for the ordinary payment-triggered path
        (matching Amendment 1's own null-actor system provenance for this
        exact case); a real, non-empty string only for the administrator
        manual-allocation action (§14), which itself always supplies one.
4. **Idempotency/replay composes correctly with Amendment 1's own existing
   behavior, verified by direct re-read of Amendment 1's committed
   logic:** a genuine replay (same key, same Workspace, same requesting
   customer, same recorded delta) returns the current assignment unchanged
   and writes no second transition row or event — exactly Amendment 1's
   own corrected comparison (its own Correction Round 1 fix). M4 never
   works around this by varying the key per attempt; every retry of the
   *same* logical allocation reuses the *same* `local_idempotency_key`-derived
   value, by construction.
5. **State transitions remain exactly as RFC-005 §22 specifies, each
   distinct, each durably written to `additional_business_slot_agreement_transitions`:**
   `payment_succeeded` (the payment itself confirmed, allocation not yet
   attempted) → `allocation_pending` (the allocation call is about to be
   or has been attempted and not yet confirmed committed) → `completed`
   (the allocation call returned successfully) — **never** collapsed into
   one step, matching RFC-005 §22's own explicit "the agreement is never
   marked `completed` before allocation succeeds" saga rule. A failed
   allocation attempt (an exception from `EntitlementManager` for any
   reason — pricing became undefined, catalog row inactive, or any other
   RFC-004-owned invariant failure) transitions the agreement to
   `allocation_failed`, dispatches `AdditionalBusinessSlotAllocationFailed`,
   and is surfaced to `ReconcileSlotAgreementAllocation` (§22) and the
   admin manual-allocation action (§14) as the two available recovery
   paths — never silently retried inline, never silently swallowed.
6. **`allocateAdditionalBusinessSlotsFromVerifiedPayment()` is called from
   exactly one shared, internal manager routine, reused by every trigger,
   never a synthetic actor.** §21's `performVerifiedAllocation()` (new,
   private) is the single place §8 items 1–5 above are implemented; the
   ordinary payment-confirmation path, `ReconcileSlotAgreementAllocation`,
   and the administrator's own manual-allocation action all call this one
   routine — never a copy of its logic, never a fake administrator id
   standing in for a real one (§21).
7. **(This refinement) The initial Checkout Session's own actual
   `PaymentMethod` — not necessarily the Workspace's previously-saved
   default — is reconciled into `business_payment_instruments` before the
   agreement is permitted to reach `completed`.** `confirmSlotAgreementFromReturn()`/
   `confirmSlotAgreementFromWebhook()` (§21) call
   `PaymentInstrumentManager::syncWorkspaceCheckoutPaymentMethod()` (§15c)
   immediately after the Checkout Session's own payment is verified
   `paid` and its actual PaymentMethod resolved — strictly before
   `performVerifiedAllocation()` is invoked. `PaymentInstrumentManager`
   remains the sole write authority for `business_payment_instruments`
   (unchanged M3 discipline) — no other M4 code path ever writes to that
   table. The agreement's own `payment_method_display_snapshot` (§18) is
   provisional while `quote_created`/`checkout_pending`; this call updates
   it exactly once, to the actual paid card's display value, and it is
   then frozen for historical display, matching every other snapshot
   column's own frozen-after-first-confirmation discipline.

---

## 8a. Refund states — reserved, not executable in M4

**RFC-005 §22–§23, re-read in full for this refinement: no executable
trigger, job, gateway call, or manager method for reaching
`refund_pending`/`refunded` is defined anywhere in the RFC.** The only
appearance of either value in the entire RFC is
`additional_business_slot_agreements.state`'s own enum listing (§18). §23
("Refunds, disputes, chargebacks, invoices, receipts, and tax/VAT
boundary") is, in its entirety, about the **wallet ledger's** four entry
types (`ManualCredit`/`Refund`/`DisputeChargeback`/`UsageChargeReversal`,
§13, all wallet-*balance*-level) plus the receipts/tax boundary — not
about a slot agreement's own state machine.

**This contract does not invent a refund policy or mechanism M4 does not
actually own.** Accordingly:

- `refund_pending` and `refunded` remain part of the `SlotAgreementState`
  enum (§18, §19) — schema-complete, because a future amendment or
  milestone may define how they are reached, and the column must already
  be able to store that value when it does — but **no code path in this
  contract's own allowlist ever writes either value**. No job, manager
  method, controller action, or webhook branch transitions an agreement
  to `refund_pending` or `refunded`.
- **No `PaymentProviderGateway` refund method is added.** A Stripe refund
  call (`refunds->create()` or equivalent) is explicitly out of scope —
  RFC-005 does not request one for this milestone, and inventing an
  executable refund operation the RFC itself never defines would be
  exactly the kind of unauthorized policy invention this contract must
  not perform.
- **If a genuine business need for slot-agreement refunds arises**, it
  requires its own separate, explicit RFC-005 amendment or correction
  defining the trigger, the authority (customer-initiated? admin-only,
  mandatory reason? provider-initiated via a `charge.refunded` webhook?),
  and the exact state transition — not a detail this M4 contract
  resolves unilaterally.
- Every other M4 state (`quote_created` through `canceled`, excluding
  these two) has an exact, executable, allowlisted path reaching it,
  confirmed section-by-section throughout §8–§13 — these two are the sole
  exception, and are named as such explicitly rather than silently
  implied to be reachable.

---

## 9. Required catalog-pricing boundary — locked

1. **M4 never calls `updateCatalogPricing()` and never mutates any
   `workspace_plan_catalog` column.** Catalog writes remain
   platform-administrator-only, exclusively through RFC-004's own
   `EntitlementManager::updateCatalogPricing()` — a capability M4 does not
   expose, extend, or reach from any M4 controller, job, or manager
   method.
2. **Quote construction reads, once, at quote-creation time, and computes
   the per-slot price as the base plan price multiplied by the ratio —
   never the base plan price alone:** the Workspace's current tier and
   complimentary status via `getWorkspaceEntitlementSummary()` (§3 item
   4), then the matching `WorkspacePlanCatalogSummary` via
   `listPlanCatalogSummaries()` (§3 item 3) filtered to that tier. From
   that summary, the quote computes and snapshots, in one atomic write
   inside the agreement-creation transaction:
   - `ratio_snapshot` — the summary's own `additionalBusinessSlotPriceRatio`,
     copied verbatim (already an exact `DECIMAL(6,4)`-shaped string, per
     Amendment 2's own `normalizeRatio()` discipline — never re-derived or
     re-rounded here).
   - `currency_id_snapshot`/`plan_catalog_id_snapshot`/`plan_tier_snapshot`
     — copied verbatim from the summary.
   - the summary's own base `price` converted to exact micro-units via the
     same currency-exponent resolution
     `UsageBillingCheckoutManager::microToMinorUnits()`'s own inverse
     already establishes — reused, not reinvented — call this
     `basePlanPriceMicro`.
   - `price_per_slot_micro_snapshot = bcRoundHalfUp(bcmul(basePlanPriceMicro,
     ratio_snapshot), '1')` — the base plan price **multiplied by the
     ratio**, rounded half-up to an integer micro amount via the exact
     same `bcRoundHalfUp()`/`bcmath` discipline every other monetary
     computation in this codebase already uses. **This is precisely the
     quantity Amendment 2's own `additional_business_slot_price_ratio`
     column exists for M4 to consume — an additional slot is never priced
     at the full base-plan price unless the configured ratio itself
     mathematically produces that result (e.g. a ratio of `1.0000`); a
     `0.5000` ratio (RFC-004's own seeded Core/Growth value) halves it, by
     construction, not by a separate discount step.**
   - `total_amount_micro_snapshot = paid_delta × price_per_slot_micro_snapshot`,
     computed exactly once via the same `bcRoundHalfUp()`/`bcmath`
     discipline.
3. **A catalog row with `null` `price`/`currency_id` (RFC-004 §12.5's
   "undefined pricing" case) or `null` `additional_business_slot_price_ratio`
   for a Core/Growth tier produces no quote at all** — the quote-creation
   step fails closed with a typed, M4-owned exception before any agreement
   row is written, mirroring RFC-004's own `UndefinedPlanPricingException`
   posture at the read boundary (M4 does not catch or reference that
   RFC-004 exception class directly — it independently fails closed on the
   same `null` observation, keeping the two RFCs' exception namespaces
   separate).
4. **Already-created agreements never rederive their historical initial
   price from a later catalog edit.** Every subsequent read of "what did
   this Workspace originally agree to pay per slot" is satisfied
   exclusively from the agreement's own `*_snapshot` columns — never a
   fresh call to `listPlanCatalogSummaries()`/`updateCatalogPricing()`'s
   read side, and never a live join back to `workspace_plan_catalog` for
   historical display. This holds by construction: no M4 code path
   re-derives a snapshot value after the row that owns it has been
   written.
5. **Renewal price-change behavior follows RFC-005's existing rule
   exactly:** a `scheduled_renewal` charge **re-snapshots**
   `plan_catalog_id_snapshot`/`plan_tier_snapshot`/`ratio_snapshot`/
   `currency_id_snapshot` fresh, from the catalog's *current* state at
   that renewal's own creation time, and **recomputes its own
   `amount_micro_snapshot` using the identical price × ratio formula item
   2 above defines** (`bcRoundHalfUp(bcmul(basePlanPriceMicro,
   ratio_snapshot), '1')`, then multiplied by the currently-allocated slot
   count for a `scheduled_renewal`, or proportioned per §11 for a
   `mid_period_increase`) — never the prior period's own frozen amount
   copied forward, and never the base price alone. This is the one place
   a later catalog price *or ratio* edit legitimately does affect a future
   charge, exactly as RFC-005 §22 specifies ("Price changes apply only to
   future, properly-notified renewals"), and exactly as
   `additional_business_slot_renewal_charges`' own schema already
   distinguishes from the parent agreement's frozen *original* snapshot
   (§18) — a change to `additional_business_slot_price_ratio` alone (price
   unchanged) still produces a different renewal amount, since the ratio
   is one of the two multiplicands, not a fixed constant. `SendSlotAgreementPriceChangeNotice`
   (§22) fires whenever a computed renewal amount differs from the prior
   period's own recorded amount, before the off-session charge is
   attempted.

---

## 10. Add-ons: structural machinery only, zero invention

- `business_usage_addon_catalog`, `business_usage_addon_purchases`, and
  `business_usage_addon_purchase_transitions` are created with their exact
  RFC-005 §18 schema (§18 below) and full repository/model support.
- **No seeder, migration data-insert, or any other mechanism populates
  `business_usage_addon_catalog` with any row.** The table exists,
  correctly, with **zero** rows — exactly RFC-005 §18's own explicit
  statement ("Not seeded at M4 launch (zero rows)").
- **No `addon_key`, `display_name`, `price_micro`, `currency_id`, or
  `fulfillment_mode` value is invented anywhere in this contract or its
  future implementation** — not as a code default, not as a test fixture
  "example," not as a migration seed.
- `UsageBillingCheckoutManager`'s new add-on-purchase method(s) (§21) are
  fully implemented and fully testable **against a test-created catalog
  row a test itself inserts directly** (never a shipped seed) — this is
  the exact pattern `AddonPurchaseTransitionAuditTest` (§27) and every
  other add-on test uses, matching how M3's own tests create their own
  fixture data rather than relying on any commercial seed.
- RFC-005 §39 item 8 (exact v1 add-on roster and pricing) remains
  unresolved and does not block this structural implementation, exactly
  as RFC-005 itself already states — the RFC's own zero-seeded-rows
  design is what makes the structural/commercial questions genuinely
  separable.

---

## 11. Renewal — scheduled and mid-period-increase, exact

- **`InitiateSlotAgreementRenewal`** (new, `App\Jobs\Usage\*`, scheduled,
  **every 5 minutes exactly** — §22, locked, not an implementation-time
  choice) finds agreements with `next_renewal_at <= now() AND
  cancel_at_period_end = false AND payment_lapsed = false AND state =
  'completed'`, and for each, calls
  `UsageBillingCheckoutManager::createScheduledRenewalCharge(AdditionalBusinessSlotAgreement
  $agreement): SlotRenewalChargeResult` (§21, new) — the job itself never
  creates or updates a renewal-charge row; it only selects the due
  agreements and calls this one manager method per agreement. The manager
  method creates a `charge_kind: scheduled_renewal`,
  `initiated_by: scheduled_job` renewal-charge row with
  `local_idempotency_key = sha256(agreement_id . ':' . 'scheduled' . ':' .
  period_start_iso8601)` (RFC-005 §22's exact derivation), then charges the
  Workspace's saved instrument off-session via the same
  `PaymentProviderGateway::createOffSessionPaymentIntent()` M3 already
  established, with `metadata = ['app_subject_kind' => 'slot_renewal_charge',
  'app_subject_id' => (string) $renewalCharge->id, 'app_operation_id' =>
  $localIdempotencyKey]`. **The Stripe-facing `idempotency_key` argument
  itself is a distinct, per-attempt value, corrected this refinement
  (§13, §23) — `sha256($localIdempotencyKey . ':attempt:1')` for this
  initial call, never the bare `$localIdempotencyKey`.** `local_idempotency_key`
  (the column) and `app_operation_id` (the metadata) are completely
  unaffected by this — both remain exactly the row-identity value already
  derived above; only the value Stripe itself receives as `idempotency_key`
  is ordinal-suffixed, so that each of up to 3 real provider attempts
  against the same logical charge gets Stripe's own independent
  request-level idempotency, while local routing/dedup continues to key
  off the unchanged, unsuffixed `local_idempotency_key`.
- **`requires_action` handling** mirrors M3's own funding-attempt shape
  exactly — a `requires_action` PaymentIntent status leaves the charge row
  in a pending state awaiting either an owner-driven confirmation or the
  webhook's own eventual `.succeeded`/`.payment_failed` resolution, never a
  synchronous assumption of success.
- **Mid-period increase — `UsageBillingCheckoutManager::requestSlotAgreementIncrease(AdditionalBusinessSlotAgreement
  $agreement, int $targetAllocationCount, string $changeOperationId, int
  $actorUserId): SlotRenewalChargeResult`** (§21, new — the manager method
  §3 item 7d's audit found missing) — a Workspace owner requesting
  `target_allocation_count` above `current_allocation_count` mid-period
  creates a `charge_kind: mid_period_increase`,
  `initiated_by: owner_initiated` (or `admin_retry` if a stuck attempt is
  later manually resumed, §14) row. **`change_operation_id` is generated
  exactly once, client-side, by the Workspace increase view/form itself
  (§17, §25 view) — never by the manager, never by the controller, never
  regenerated per HTTP request.** The view embeds one UUID as a hidden
  field the instant the increase form is rendered; submitting the form
  (including a genuine retry of a failed/stuck submission — the browser
  resubmits the *same* rendered form, carrying the *same* hidden value)
  posts that identical value; a request for a **distinct** increase (the
  owner reloads or re-opens the increase view) receives a **new** UUID
  from a fresh render. `RequestSlotAgreementIncreaseRequest` (§25) only
  **validates** `change_operation_id` is present and UUID-shaped — it
  never generates one. `requestSlotAgreementIncrease()` itself never
  regenerates or overwrites the caller-supplied value, by construction —
  the manager's own idempotency guarantee (below) depends entirely on
  receiving the identical value across retries, which is the request
  contract's job to guarantee, not the manager's to enforce after the
  fact. `local_idempotency_key = sha256(agreement_id . ':' . 'increase' .
  ':' . change_operation_id)` — the exact RFC-005 §22 fix ensuring two
  distinct increases within one billing period never collide, while a
  genuine retry of the *same* increase (identical `change_operation_id`,
  guaranteed by the view/form contract above) is absorbed as a no-op.
- **Proration — exact-second arithmetic, never a "days" approximation:**
  `remaining_seconds = period_end_utc - now()`, `total_seconds =
  period_end_utc - period_start_utc` (both derived from the agreement's
  own governing calendar-month period boundaries, constructed via the same
  algorithm RFC-005 §15 already establishes for wallet periods, applied at
  the Workspace level), `amount_micro_snapshot = bcRoundHalfUp(bcmul(
  price_per_slot_micro_snapshot × additional_slots_being_added,
  remaining_seconds), total_seconds)`, using the exact same `bcRoundHalfUp()`
  helper every other monetary computation in this codebase already uses —
  never re-implemented, never approximated in native float arithmetic.
- **Decreasing `target_allocation_count` mid-period never retroactively
  refunds** the current period's already-paid charge; it reduces the
  amount computed at the next `scheduled_renewal` only.
- **`requesting_customer_email_snapshot` on every renewal charge is
  copied, frozen, from the parent agreement's own original value at
  charge-creation time** — never independently re-derived from the
  Workspace's *current* billing contact or owner email, matching RFC-005
  §22's own explicit distinction from the pricing/payment-method snapshots
  (which correctly reflect current state at each renewal).

---

## 12. Cancellation — exact, period-end effective

1. On a cancellation request (Workspace owner, or platform administrator
   with mandatory reason, §14 — never an origination): set
   `cancel_at_period_end = true`, `cancellation_requested_at = now()`,
   `cancellation_effective_at = <the agreement's current `next_renewal_at`
   value, frozen at this exact moment>`. **`next_renewal_at` itself is
   left unchanged** — RFC-005 §22's own corrected model, explicitly
   replacing an earlier, rejected "clear `next_renewal_at` immediately"
   design.
2. `InitiateSlotAgreementRenewal`'s own query (§11) already excludes
   `cancel_at_period_end = true` — no renewal is ever initiated for an
   agreement with a pending cancellation, even while `next_renewal_at`
   remains technically non-null. Cancellation itself is requested via
   `UsageBillingCheckoutManager::requestSlotAgreementCancellation()`
   (§21, existing in this contract's own prior draft, unchanged).
3. **`FinalizeSlotAgreementCancellation`** (new, scheduled, **every 5
   minutes exactly** — §22, locked) finds agreements with
   `cancel_at_period_end = true AND cancellation_effective_at <= now() AND
   state != 'canceled'`, and for each calls
   `UsageBillingCheckoutManager::finalizeSlotAgreementCancellation(AdditionalBusinessSlotAgreement
   $agreement): AdditionalBusinessSlotAgreement` (§21, new) — the job
   itself only selects due agreements and calls this one manager method
   per agreement; the manager method sets `state: canceled`,
   `next_renewal_at: null`, records the transition, and dispatches
   `AdditionalBusinessSlotAgreementCanceled`.
4. Already-allocated slots for the current, already-paid period remain
   until `cancellation_effective_at` genuinely passes — no early,
   speculative slot revocation of any kind.

---

## 13. Dunning, `payment_lapsed`, and recovery — exact, no revocation

- **Maximum 3 total provider payment attempts per logical renewal charge
  — corrected this refinement to remove the "3 retries" ambiguity (§3
  item 7k):** the initial provider attempt (`scheduled_job`/
  `owner_initiated` creation, §11) **is attempt 1**; at most **2**
  subsequent retries follow (`owner_initiated` via `retrySlotRenewalAsOwner()`
  or `admin_retry` via `retrySlotRenewalAsAdministrator()`, in either
  order/mix); **failure of attempt 3 causes `payment_lapsed`**. This is
  never phrased as "3 retries" (which would mean 4 total). Locked,
  matching RFC-005 §22's own recorded recommendation ("recommended: 3")
  read as 3 total attempts, not 3 retries beyond an initial one — this is
  no longer an implementation-time choice; a future change requires its
  own separate authorization/configuration.
- **RFC-005 §22 itself confirms retries are manually triggered, not a
  separate automatic dunning scheduler** — its own recovery language
  names only "a manually-triggered `admin_retry`" and "the Workspace
  owner's own `owner_initiated` retry," never a scheduled retry job; no
  such job is authorized or created by M4 (§25's allowlist has exactly
  four new jobs, none of them a retry scheduler).
- **A retry must actually drive the payment provider again — merely
  retrieving an already-failed PaymentIntent is reconciliation, not a
  retry (§3 item 7k).** `retrySlotRenewalAsOwner()`/
  `retrySlotRenewalAsAdministrator()` (§21) call the new
  `PaymentProviderGateway::confirmPaymentIntent()` (§15a) against the
  charge's own existing `provider_session_or_intent_reference` and the
  Workspace's **current** default payment instrument — never merely
  `retrievePaymentIntent()` — so a retry after the owner has corrected
  their payment method can genuinely succeed. This deliberately diverges
  from M3's own already-shipped `retryFundingAttemptAsAdministrator()`
  shape (retrieve-only, unmodified this milestone, §14) — that method's
  own scope is resuming a stuck-but-still-viable attempt, not recovering
  from a definite decline.
- **Attempt-ordinal derivation — exact, deterministic, crash-safe, using
  only the existing `additional_business_slot_renewal_charge_transitions`
  table, no new schema column (§23 has the full algorithm).**
- **While retries for a due period are in progress, `next_renewal_at`
  remains unchanged**, still pointing at that same already-due period — no
  new `scheduled_renewal` row is created for a later period until the
  current one either succeeds or is exhausted (all 3 attempts fail).
- **After attempt 3 fails:** `payment_lapsed = true`,
  `payment_lapsed_at = now()`, and `next_renewal_at` is explicitly set to
  `null` — no further attempts are possible while lapsed (a 4th attempt
  is never reachable — §23's own ordinal cap enforces this independently
  of caller behavior). `AdditionalBusinessSlotAgreementLapsed` dispatches.
- **Recovery — `UsageBillingCheckoutManager::retrySlotRenewalAsOwner(AdditionalBusinessSlotRenewalCharge
  $charge, int $actorUserId): SlotRenewalChargeResult`** (§21, new — the
  owner-recovery manager method §3 item 7d's audit found missing; distinct
  from `retrySlotRenewalAsAdministrator()`, which remains admin-only,
  mandatory-reason, and now explicitly verifies the actor is a real
  platform administrator before acting, §3 item 7l/§21). The moment any
  subsequent renewal charge succeeds (an `admin_retry` via
  `retrySlotRenewalAsAdministrator()`, §14, or the owner's own
  `owner_initiated` retry via `retrySlotRenewalAsOwner()` after updating
  their payment method), `payment_lapsed = false` and
  `payment_lapsed_cleared_at = now()` are set, `next_renewal_at` is
  **recomputed one billing cadence forward from the recovery moment —
  never retroactively from the missed period**, and
  `AdditionalBusinessSlotAgreementPaymentRecovered` dispatches. Missed
  periods are **skipped**, never accumulated or back-billed — no
  `additional_business_slot_renewal_charges` row is ever created for a
  period that was simply skipped due to lapse.
- **`payment_lapsed`'s own audit is exactly the two timestamp columns on
  the agreement row itself** (`payment_lapsed_at`/`payment_lapsed_cleared_at`),
  per RFC-005 §18's own explicit, bounded scope statement — recording only
  the most recent lapse/clear instant, not a full multi-cycle history. No
  additional transition-table row type is invented for this.
- **M4 never revokes an already-allocated RFC-004 Business slot merely
  because payment becomes lapsed.** `workspace_plan_assignments.additional_business_slots`
  is never decremented, and
  `EntitlementManager::setAdditionalBusinessSlots()` is never called, by
  any M4 code path reacting to `payment_lapsed`. A lapsed Workspace keeps
  its already-granted capacity indefinitely under this contract — any
  future revocation behavior requires its own explicit, separate human
  decision/authorization (RFC-005 §39 item 13), never invented here.

---

## 14. Administrator authority — narrowed, M4's own three capabilities

Restating RFC-005 §24's own table, narrowed to exactly the three rows M4
introduces or extends — every other row in that table is M1–M3 scope,
unaffected:

| Capability | Workspace owner | Platform administrator |
|---|---|---|
| Originate a new additional-slot agreement checkout | Yes | **No** — never a fresh origination, matching §16's narrowing |
| Resume/retry an already-created, payer-authorized slot renewal charge | Yes, own agreement | **Yes, mandatory reason** — the sole surviving admin charge-adjacent capability, since the payer's own prior action already supplied consent |
| Cancel an additional-slot agreement (effective at period end) | Yes | **Yes, mandatory reason** |
| Perform the manual additional-slot allocation action — follows an already-succeeded payment, not a fresh charge | No | **Yes only, mandatory reason, the administrator's own real identity** — never a synthetic actor, and the allocation call itself still records the original requesting customer as `$requestingCustomerUserId` (§8 item 3), not the administrator |

- **No broad "administrator can charge anybody" capability exists anywhere
  in M4.** Every administrator action above either resumes an
  already-payer-authorized attempt or acts on an already-succeeded
  payment — never originates a new charge, never bypasses the Workspace
  owner's own initial consent.
- **Administrator identity provenance — exact, two separate records, never
  conflated.** RFC-004's `allocateAdditionalBusinessSlotsFromVerifiedPayment()`
  signature itself carries no actor parameter at all (§8 item 3) — its own
  `workspace_entitlement_transitions` row it writes therefore always
  records `actor_user_id = null`, the real `requesting_customer_user_id`
  (never the administrator's id, §8 item 3), and the same payment
  idempotency key, **regardless of whether the call was reached via the
  ordinary payment-triggered path or the administrator's manual allocation
  action** — this is unchanged by, and unaffected by, who triggered the
  M4-side call. **M4's own `additional_business_slot_agreement_transitions`
  row for that same manual-allocation action is a separate record**, and
  *that* row's own `actor_user_id` durably records the administrator's own
  real user id (§18) — the one place the administrator's identity is
  actually persisted. The mandatory admin `$reason` string passes through
  into RFC-004's own `$reason` parameter on that call (§8 item 3), but the
  administrator's identity itself is never passed as, and never
  substitutes for, `$requestingCustomerUserId`.
- **`retrySlotRenewalAsAdministrator()` (§21) — corrected this refinement
  (§3 item 7k): it does NOT mirror `retryFundingAttemptAsAdministrator()`'s
  retrieve-only shape.** M3's method (already merged, unmodified this
  milestone) only re-retrieves an already-created PaymentIntent — correct
  for its own resume-a-stuck-attempt scope, but incapable of recovering a
  genuinely *declined* charge. `retrySlotRenewalAsAdministrator()` instead
  calls the new `PaymentProviderGateway::confirmPaymentIntent()` (§15a)
  against the Workspace's current default instrument, a real second
  provider-side attempt — see §13/§21/§23 for the exact mechanism.
- **Every M4 "AsAdministrator"-named method independently, explicitly
  verifies the supplied actor is a real platform administrator before any
  mutation or provider call — never relying solely on the admin
  route/middleware (§3 item 7l).** `retrySlotRenewalAsAdministrator()`,
  `allocateSlotAgreementAsAdministrator()`, and the administrator branch
  of `requestSlotAgreementCancellation()` each call a new private
  `assertPlatformAdministrator(int $actorUserId): void` helper added to
  `UsageBillingCheckoutManager` (§21, §25 item 41 — an authorized internal
  addition to the already-allowlisted file, no new path), mirroring
  `UsageWalletManager::assertPlatformAdministrator()`'s exact shape (a
  direct `users.is_admin` read) and throwing
  `UnauthorizedSlotAgreementActionException` (§19, already allowlisted)
  on failure — before the mandatory-`$reason` check and before any
  mutation or outbound call.

---

## 15. Provider/webhook boundary — two required widenings

**Corrected this refinement: M4 requires exactly two provider/webhook-
boundary widenings, not the "zero gateway changes" this contract
previously claimed** (§3 items 7a–7c) — `ProcessPaymentProviderEvent.php`'s
subject-kind dispatch (this section) and the narrow Checkout Session
gateway extension (§15a). `StripeWebhookController.php` and
`ReconcileProviderPendingState.php` remain the **only** two
provider/webhook-adjacent files confirmed to need zero change (§3 items
9–10).

`ProcessPaymentProviderEvent.php` (§3 item 8, existing, modified) is
widened from a single hardcoded `'funding_attempt'` subject-kind check to
a small, explicit dispatch over **exactly three** recognized kinds —
RFC-005 §17.C's own canonical set, confirmed by direct re-read:

```php
match ($metadata['app_subject_kind'] ?? null) {
    'funding_attempt' => $this->processFundingAttempt($event, $metadata, ...),
    'slot_renewal_charge' => $this->processSlotRenewalCharge($event, $metadata, ...),
    'slot_agreement' => $this->processSlotAgreementInitialCheckout($event, $metadata, ...),
    default => $eventRepository->markFailed($event->id, 'missing_or_unrecognized_metadata'),
};
```

**No `addon_purchase` subject kind exists, is emitted, or is accepted
anywhere in M4.** An add-on's own outbound PaymentIntent metadata is
identical in shape to a `ManualTopUp`/`AutoRecharge` attempt's own —
`app_subject_kind: 'funding_attempt'`, `app_subject_id: (string)
$attempt->id`, `app_operation_id: $attempt->local_idempotency_key` — set
by `UsageBillingCheckoutManager::initiateCharge()` exactly as it already
does for every other purpose (§21); nothing in the outbound call, the
webhook payload, or this job's own routing ever names `addon_purchase` as
a kind.

- **The `funding_attempt` branch becomes purpose-aware, after — never
  instead of — the existing full pre-mutation validation sequence.** The
  branch first re-implements the identical validation M3 already
  established (provider object id match, operation id match, amount
  match, currency match, customer match — all against the attempt's own
  frozen, persisted expectations, never trusting the event's own claims)
  exactly as before; only once every check has passed does it inspect the
  now-validated local attempt's own `purpose` column: `ManualTopUp`/
  `AutoRecharge` route to the manager's existing confirmation call
  unchanged; `AddonPurchase` routes to the same confirmation call, whose
  own internal behavior is now purpose-aware (§21) rather than
  unconditionally crediting the wallet.
- `slot_agreement` and `slot_renewal_charge` are the two genuinely new
  kinds. `slot_agreement` routes the **initial Checkout Session's** own
  webhook confirmation (`checkout.session.completed`, `payment_succeeded`
  transition, §8 item 5) — **never** a PaymentIntent event for this one
  step, matching §15a's own Checkout Session design; `slot_renewal_charge`
  routes every renewal/mid-period-increase charge's own off-session
  PaymentIntent webhook confirmation. Both branches independently
  re-implement the identical pre-mutation validation sequence — no branch
  is permitted to skip a check another branch performs, and no branch
  introduces an `event_type`-as-local-purpose shortcut (the exact defect
  class RFC-005 §21/§35 already names and tests against for
  `funding_attempt`). Both ultimately call `UsageBillingCheckoutManager`'s
  own new methods (§21), never `EntitlementManager` directly from this
  job.
- **`app_subject_kind` remains an untrusted routing hint only** — exactly
  M3's own established discipline, restated and re-applied to both new
  kinds, never elevated to a trust boundary of its own.
- `StripeWebhookController.php` and `ReconcileProviderPendingState.php`
  require **zero** changes (§3 items 9–10) — confirmed by direct read, not
  assumed. `PaymentProviderGateway` and both its implementations **do**
  require the narrow extension §15a defines — the prior "zero changes"
  claim for these three files is withdrawn (§3 items 7a–7c).

---

## 15a. Checkout Session gateway extension — narrow, exact

**Authorized because RFC-005 §22 requires a genuine customer-present
Checkout Session for the initial additional-slot purchase, and no such
seam exists in the merged `PaymentProviderGateway`** (§3 items 7a–7c,
7g–7k). This remains the **only** addition to the gateway boundary M4
authorizes — every existing method (SetupIntent, off-session PaymentIntent,
customer, payment-method) is unchanged.

**New interface methods**, added to `PaymentProviderGateway` (§25):

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

public function retrieveCheckoutSession(string $providerCheckoutSessionId): CheckoutSessionResult;

public function confirmPaymentIntent(
    string $providerPaymentIntentId,
    string $providerPaymentMethodId,
    string $idempotencyKey,
): PaymentIntentResult;
```

**`confirmPaymentIntent()`** (§3 item 7k) is the exact, narrow addition
that makes an M4 renewal retry a genuine second provider-side attempt,
never a bare re-retrieval — see §21/§23 for the deterministic 3-attempt
mechanism that calls it. It reuses the existing `PaymentIntentResult` DTO
unchanged (§25 items 88–90 — no new path for any of the three new
methods; the same three already-allowlisted gateway files absorb all of
it).

**Corrected Checkout Session request shape (§3 item 7g)** — Stripe's own
`mode: 'payment'` requires at least one `line_items` entry; the amount is
represented as **one non-adjustable line item, built from inline
`price_data`, never a separately-created Stripe Price/Product**:

```php
'mode' => 'payment',
'customer' => $providerCustomerId,
'payment_method_types' => ['card'],
'line_items' => [[
    'price_data' => [
        'currency' => strtolower($currencyCode),
        'unit_amount' => $amountMinorUnits,
        'product_data' => [
            'name' => $lineItemName,
        ],
    ],
    'quantity' => 1,
]],
'payment_intent_data' => [
    'setup_future_usage' => 'off_session',
],
'success_url' => $successUrl,
'cancel_url' => $cancelUrl,
'metadata' => $metadata,
```

`unit_amount` derives **only** from the frozen agreement amount, converted
through the exact currency rules §12 (M3 contract) already establishes —
no independent Stripe retail price is invented, no adjustable quantity, no
promotion code, no customer-editable amount. `payment_intent_data.setup_future_usage:
'off_session'` is kept because this initial customer-present payment is
explicitly establishing a card for later off-session renewal (§11).
`initiateSlotAgreementCheckout()` (§21) supplies `$lineItemName = 'Additional
Business slots'` — the already-defined feature's own truthful name, not a
new commercial product or price.

**New normalized DTO**, `App\Library\Usage\CheckoutSessionResult` (§25,
new — no existing DTO can represent this shape without conflating two
different provider objects, §3 item 7c) — **corrected this refinement for
nullability and actual-payment-method capture (§3 items 7h–7i):**

```php
final readonly class CheckoutSessionResult
{
    public function __construct(
        public string $providerCheckoutSessionId,
        public string $status,                    // Stripe Checkout Session's own 'status': open|complete|expired
        public string $paymentStatus,             // Stripe's own 'payment_status': unpaid|paid|no_payment_required
        public ?string $redirectUrl,              // the Session's own 'url' — null once the Session is no longer 'open' (§3 item 7h)
        public int $amountMinorUnits,              // from 'amount_total', never 'amount' (§15b)
        public string $currencyCode,
        public string $providerCustomerId,
        public ?string $providerPaymentIntentId,  // the Session's own 'payment_intent', for later traceability only — never re-read for pricing (§9 item 4)
        public ?string $providerPaymentMethodId,  // the actual PaymentMethod that paid, resolved on retrieve of a completed Session (§3 item 7i)
    ) {
    }
}
```

**`redirectUrl` nullability rule — exact:** `createCheckoutSession()`
**fails closed** (throws `ProviderInvalidRequestException`, the identical
existing gateway exception every other malformed-response case already
uses) if the newly-created Session unexpectedly carries no `url` — the
browser cannot continue without one, so a null `redirectUrl` from a
*create* call is always a hard error, never silently passed through.
`retrieveCheckoutSession()` **may legitimately return `redirectUrl: null`**
for an already-`complete` Session — callers (`confirmSlotAgreementFromReturn()`/
webhook confirmation, §21) never depend on it being present.

**`providerPaymentMethodId` resolution — exact:** on `retrieveCheckoutSession()`
of a `complete` Session, the gateway resolves the actual PaymentMethod
via Stripe's own PaymentIntent expansion (`expand: ['payment_intent.payment_method']`)
or an equivalent exact retrieval — **never assumed to be the Workspace's
locally-saved default**, since hosted Checkout does not guarantee that.
For an `open`/`expired` Session (no completed payment yet),
`providerPaymentMethodId` is `null`.

**Required-verified conditions — exact, all of the following, before M4
treats a Checkout Session payment as verified (§3 item 7h, §21):**

1. `status === 'complete'`;
2. `payment_status === 'paid'`;
3. the retrieved Session's own id matches the agreement's own expected
   `provider_session_or_intent_reference`;
4. the confirmed `amountMinorUnits` matches the agreement's own frozen
   `total_amount_micro_snapshot` (converted, §9 item 4);
5. the confirmed `currencyCode` matches the agreement's own frozen
   `currency_id_snapshot`;
6. `providerCustomerId` matches the Workspace's own expected provider
   customer;
7. `providerPaymentIntentId` is non-null;
8. `providerPaymentMethodId` is non-null.

Any condition failing leaves the agreement in its current pending state —
no partial advance, no allocation, no payment-instrument sync. **The
webhook path (`slot_agreement` branch, §15) additionally requires
`payment_status === 'paid'` explicitly** — `checkout.session.completed`
firing by itself is not, on its own, sufficient payment evidence (Stripe
dispatches this event even for a Session that completed with
`payment_status: 'unpaid'`, e.g. a free/zero-amount edge case that cannot
occur here given §9's own pricing floor, but the check is asserted
unconditionally rather than relying on that floor never changing).

**`StripePaymentProviderGateway`** (§25, modified) implements all three
new methods — `createCheckoutSession()`/`retrieveCheckoutSession()` via
`$this->client->checkout->sessions->create([...])`/`->retrieve()` (the
corrected request/response shape above); `confirmPaymentIntent()` via
`$this->client->paymentIntents->confirm($providerPaymentIntentId,
['payment_method' => $providerPaymentMethodId], ['idempotency_key' =>
$idempotencyKey])`, returning the same `PaymentIntentResult` shape
`createOffSessionPaymentIntent()`/`retrievePaymentIntent()` already use.

**`FakePaymentProviderGateway`** (§25, modified) implements a
deterministic, test-controlled equivalent of all three methods, mirroring
its own existing `createOffSessionPaymentIntent()`/`registerPaymentMethod()`
test-configuration pattern exactly — tests configure the fake's next
Checkout Session result (status/payment_status/amount/payment method) and
each `confirmPaymentIntent()` call's own outcome before exercising a
manager method, the identical established convention. The fake's own
`confirmPaymentIntent()` implementation records each call it receives
(idempotency key and payment method), so a test can assert a genuine
second call actually occurred — never merely that the same PaymentIntent
was re-retrieved (§3 item 7k).

### 15b. Webhook amount normalization — Checkout Session widening

`StripePaymentProviderGateway::verifyWebhookSignature()` (§3 item 7b,
§25, modified — the same file, no new path) currently reads
`$object->amount ?? null` unconditionally
(`app/Library/Usage/StripePaymentProviderGateway.php:190`), which does not
exist on a Checkout Session object. Corrected to:

```php
$amountMinorUnits = $object->amount ?? $object->amount_total ?? null;
```

— checking the Checkout Session's own `amount_total` only when the
PaymentIntent/Charge-shaped `amount` field is absent. **Every existing
PaymentIntent-event amount normalization is unchanged** — `$object->amount`
is still read first and still wins whenever it is present; `amount_total`
is consulted only as the fallback a Checkout Session event actually needs.
`WebhookVerificationResult`'s own shape (§25, unchanged — no new field) is
sufficient as-is; only the extraction logic inside this one method
changes.

### 15c. Payment-instrument reconciliation — the actual Checkout card

**Authorized because §3 item 7j confirmed a genuine gap: hosted Checkout
can result in a different card being used than any Workspace-saved
default, and M4 must not later display or renew against the wrong
instrument.** `PaymentInstrumentManager` (§25 item 94, modified) remains
the **sole write authority** for `business_payment_instruments` —
unchanged M3 discipline; M4 adds exactly one new, narrowly-scoped public
method to this already-existing class:

```php
public function syncWorkspaceCheckoutPaymentMethod(
    Workspace $workspace,
    int $actorUserId,
    string $providerPaymentMethodId,
): BusinessPaymentInstrument
```

**Locked behavior — reuses the class's own existing repository/gateway
calls verbatim, no schema change, no other M3 payment-instrument path
touched (§3 item 7j):**

1. **Workspace owner only** — `$actorUserId === $workspace->owner_user_id`,
   or `UnauthorizedPayerAssignmentException` (the identical, existing
   exception type this class already throws) — narrower than
   `assertChargeCausingConsent()`'s dual Business/Workspace-payer split,
   since M4's own additional-slot flow is unconditionally Workspace-scoped
   (RFC-004). **No platform-admin bypass** — this method is never called
   from an administrator-actor context.
2. Resolve the Workspace's own existing active provider customer via
   `PaymentProviderCustomerRepository::findActiveByWorkspaceId()`
   (existing method, already used by `resolveProviderCustomer()`) — throws
   if none exists (a Checkout Session cannot have completed without one).
3. Retrieve the PaymentMethod via `PaymentProviderGateway::retrievePaymentMethod()`
   (existing method).
4. **Require the retrieved PaymentMethod's own `providerCustomerId` to
   match the resolved provider customer's `provider_customer_id`
   exactly** — the identical mismatch guard `confirmSetupIntentAndAttach()`
   already applies, reused verbatim.
5. **Idempotently reuse the local instrument** if
   `BusinessPaymentInstrumentRepository::findByProviderPaymentMethodId()`
   already finds one (existing method) — no duplicate row.
6. **Otherwise create it** through the repository's own existing
   `create()` authority (existing method, identical attribute shape
   `confirmSetupIntentAndAttach()` already writes).
7. **Make it the provider customer's current default usage-billing
   instrument** — `clearDefaultForProviderCustomer()` then `update(...,
   ['is_default' => true])` (both existing methods, the identical pair
   `setDefaultInstrument()` already uses) — because this Checkout is the
   Workspace owner's own explicit customer-present authorization for later
   off-session renewals (§11), not merely an incidental attach.

**Called by exactly `confirmSlotAgreementFromReturn()`/
`confirmSlotAgreementFromWebhook()`** (§8 item 7, §21), after the
Checkout Session's payment is verified `paid` (§15a's eight conditions)
and strictly before `performVerifiedAllocation()` — never called from any
other M4 site, never called for a renewal/retry confirmation (renewal
retries select the *existing* current default instrument, §21/§23; they
do not change it).

---

## 16. Authorization and tenant isolation

Extending RFC-005 §24's table exactly as §14 above states. Unrelated
Workspace/Business resources fail closed with a 404-shaped response, never
a 403 — the identical, unmodified posture every prior milestone already
establishes. No raw query against any M4 table is permitted outside its
owning manager (`UsageBillingCheckoutManager`) and its own seven new
repositories, except an immutable migration script — enforced by this
contract's own new mechanical source-boundary test (§25 item 86, §26).
Permission category — unchanged: `Business Usage Billing` (no new
permission category is introduced by M4).

---

## 17. HTTP surfaces

- **Customer surface (new, Workspace-scoped):**
  `App\Http\Controllers\Customer\Workspace\AdditionalBusinessSlotAgreementController`
  — `show` (quote/current-agreement view), `checkout` (initiate, calls
  `initiateSlotAgreementCheckout()`, §21/§15a), `confirmFromReturn`
  (browser-return confirmation, calls `confirmSlotAgreementFromReturn()`,
  mirroring M3's own `confirmAttemptFromReturn()` pattern exactly),
  `requestIncrease` (calls `requestSlotAgreementIncrease()`, §21 — the
  controller only orchestrates: authenticate, load the agreement, pass
  the request's own already-validated `change_operation_id` through
  unchanged, §11), `retryRenewal` (owner-side, calls
  `retrySlotRenewalAsOwner()`, §13/§21 — distinct from the admin action
  below), `requestCancellation` (calls
  `requestSlotAgreementCancellation()`). **Every action is a thin
  orchestration layer — authenticate, load the row, call exactly one
  `UsageBillingCheckoutManager` method, render/redirect the result — no
  action creates or updates an M4 table row itself** (§16).
- **Admin surface (new, narrowly scoped, §3 item 14):**
  `App\Http\Controllers\Admin\AdditionalBusinessSlotAgreementController`
  — `index`/`show` (read), `retryRenewal` (calls
  `retrySlotRenewalAsAdministrator()`, mandatory reason), `allocate` (the
  manual allocation action, calls `allocateSlotAgreementAsAdministrator()`,
  mandatory reason, §8 item 6's shared internal routine underneath),
  `cancel` (calls `requestSlotAgreementCancellation()` with the
  administrator's own actor id, mandatory reason). Identical
  thin-orchestration discipline as the customer surface above.
- **Webhook route — unchanged.** `routes/public.php`'s existing
  `webhooks.stripe.usage-billing` route and `StripeWebhookController`
  require no change (§3 item 9); every new subject kind routes through the
  same existing endpoint.
- **Observability — unchanged**, extended only by whatever the new
  admin `index`/`show` views themselves render (no new logging/metrics
  infrastructure is introduced by M4).

---

## 18. Schema

Exact columns, types, constraints, and write authority reproduced from
RFC-005 §18/§22, **verbatim** — no shorthand, no invented column. All
`restrictOnDelete()` on tenancy-scoping foreign keys, never `cascade`; no
native `ENUM` column anywhere.

### `additional_business_slot_agreements`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `workspace_id` | `unsignedBigInteger`, FK `workspaces.id`, `restrictOnDelete()` | No | — | |
| `current_allocation_count` | `unsigned tinyint` | No | — | billing-side view only; RFC-004's `workspace_plan_assignments.additional_business_slots` remains sole authoritative entitlement value |
| `target_allocation_count` | `unsigned tinyint` | No | — | billing-side view only |
| `paid_delta` | `unsigned tinyint` | No | — | `target - current` at agreement-creation time |
| `price_per_slot_micro_snapshot` | `bigint` | No | — | |
| `total_amount_micro_snapshot` | `bigint` | No | — | `paid_delta × price_per_slot_micro_snapshot`, computed once via `bcRoundHalfUp()`; validated against the Checkout's confirmed amount before advancing past `payment_succeeded`; the sole value ever used for historical display |
| `currency_id_snapshot` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `ratio_snapshot` | `decimal(6,4)` | No | — | |
| `plan_catalog_id_snapshot` | `unsignedBigInteger`, FK `workspace_plan_catalog.id`, `restrictOnDelete()` | No | — | |
| `plan_tier_snapshot` | `string(16)` | No | — | |
| `requesting_customer_user_id` | `unsigned bigint`, no FK | No | — | |
| `requesting_customer_email_snapshot` | `string(191)` | No | — | |
| `provider_customer_id` | `unsignedBigInteger`, FK `payment_provider_customers.id` (Workspace-owned), `restrictOnDelete()` | No | — | |
| `payment_method_display_snapshot` | `string(64)` | No | — | |
| `local_idempotency_key` | `string(191)`, unique | No | — | |
| `provider_session_or_intent_reference` | `string(191)`, nullable, unique | Yes | `NULL` | initial Checkout Session id |
| `billing_cadence` | `string(16)`, enum-backed (`SlotAgreementBillingCadence`) | No | `monthly` | independent of RFC-004's own uncast `workspace_plan_catalog.billing_cycle` |
| `next_renewal_at` | `timestamp`, nullable | Yes | `NULL` | never cleared merely by a cancellation request (§12) |
| `cancel_at_period_end` | `boolean` | No | `false` | |
| `cancellation_requested_at` | `timestamp`, nullable | Yes | `NULL` | |
| `cancellation_effective_at` | `timestamp`, nullable | Yes | `NULL` | frozen at request time to whatever `next_renewal_at` already held |
| `payment_lapsed` | `boolean` | No | `false` | |
| `payment_lapsed_at` | `timestamp`, nullable | Yes | `NULL` | |
| `payment_lapsed_cleared_at` | `timestamp`, nullable | Yes | `NULL` | |
| `state` | `string(20)`, enum-backed (`SlotAgreementState`) | No | `quote_created` | `quote_created` \| `checkout_pending` \| `payment_succeeded` \| `allocation_pending` \| `completed` \| `payment_failed` \| `allocation_failed` \| `refund_pending` \| `refunded` \| `canceled` |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | mutable — transitions recorded separately |

### `additional_business_slot_agreement_transitions`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `agreement_id` | `unsignedBigInteger`, FK `additional_business_slot_agreements.id`, `restrictOnDelete()` | No | — | |
| `from_state` | `string(20)`, enum-backed (`SlotAgreementState`) | No | — | |
| `to_state` | `string(20)`, enum-backed (`SlotAgreementState`) | No | — | |
| `source` | `string(24)`, enum-backed (`TransitionSource`, reused) | No | — | |
| `provider_event_id` | `unsignedBigInteger`, FK `payment_provider_events.id`, nullable | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

### `additional_business_slot_renewal_charges`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `agreement_id` | `unsignedBigInteger`, FK `additional_business_slot_agreements.id`, `restrictOnDelete()` | No | — | |
| `charge_kind` | `string(24)`, enum-backed (`SlotRenewalChargeKind`) | No | — | `scheduled_renewal` \| `mid_period_increase` |
| `period_start` | `timestamp` | No | — | exact UTC instant |
| `period_end` | `timestamp` | No | — | exact UTC instant |
| `amount_micro_snapshot` | `bigint` | No | — | |
| `requesting_customer_email_snapshot` | `string(191)` | No | — | frozen from the parent agreement's own original value, never re-derived |
| `payer_type_snapshot` | `string(16)`, enum-backed (`PayerType`, reused) | No | `workspace` | always `workspace` for this flow |
| `provider_customer_external_id_snapshot` | `string(191)` | No | — | |
| `payment_method_display_snapshot` | `string(64)` | No | — | |
| `currency_id_snapshot` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `plan_catalog_id_snapshot` | `unsignedBigInteger`, FK `workspace_plan_catalog.id`, `restrictOnDelete()` | No | — | re-snapshotted fresh at this renewal's own creation time |
| `plan_tier_snapshot` | `string(16)` | No | — | |
| `ratio_snapshot` | `decimal(6,4)` | No | — | |
| `initiated_by` | `string(16)`, enum-backed (`SlotRenewalChargeInitiatedBy`) | No | `scheduled_job` | `scheduled_job` \| `owner_initiated` \| `admin_retry` |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | set for `owner_initiated`/`admin_retry` |
| `change_operation_id` | `string(191)`, nullable, unique | Yes | `NULL` | for `mid_period_increase` only; `NULL` for `scheduled_renewal` |
| `local_idempotency_key` | `string(191)`, unique | No | — | derivation branches on `charge_kind` (§11) |
| `provider_session_or_intent_reference` | `string(191)`, nullable, unique | Yes | `NULL` | this renewal's own PaymentIntent id |
| `state` | `string(16)`, enum-backed (`FundingAttemptState`, reused) | No | `created` | |
| `failure_reason` | `text`, nullable | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

### `additional_business_slot_renewal_charge_transitions`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `renewal_charge_id` | `unsignedBigInteger`, FK `additional_business_slot_renewal_charges.id`, `restrictOnDelete()` | No | — | |
| `from_state` | `string(16)`, enum-backed (`FundingAttemptState`, reused) | No | — | |
| `to_state` | `string(16)`, enum-backed (`FundingAttemptState`, reused) | No | — | |
| `source` | `string(24)`, enum-backed (`TransitionSource`, reused) | No | — | |
| `provider_event_id` | `unsignedBigInteger`, FK `payment_provider_events.id`, nullable | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

### `business_usage_addon_catalog`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `addon_key` | `string(64)`, unique | No | — | never populated by this contract (§10) |
| `display_name` | `string(191)` | No | — | |
| `price_micro` | `bigint` | No | — | |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `fulfillment_mode` | `string(24)`, enum-backed (`AddonFulfillmentMode`) | No | — | `wallet_credit` \| `direct_deliverable` |
| `is_active` | `boolean` | No | `true` | |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | |

**Zero rows at M4 launch — no seeder of any kind is authorized (§10).**

### `business_usage_addon_purchases`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — | plain FK |
| `addon_key` | `string(64)` | No | — | |
| `price_micro` | `bigint` | No | — | snapshot at purchase time |
| `funding_attempt_id` | `unsignedBigInteger`, FK `business_funding_attempts.id`, unique, `restrictOnDelete()` | No | — | sole authoritative direction |
| `status` | `string(16)`, enum-backed (`AddonPurchaseStatus`) | No | `pending` | `pending` \| `completed` \| `failed` |
| `requested_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `completed_at` | `timestamp`, nullable | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

### `business_usage_addon_purchase_transitions`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `purchase_id` | `unsignedBigInteger`, FK `business_usage_addon_purchases.id`, `restrictOnDelete()` | No | — | |
| `from_status` | `string(16)`, enum-backed (`AddonPurchaseStatus`) | No | — | |
| `to_status` | `string(16)`, enum-backed (`AddonPurchaseStatus`) | No | — | |
| `source` | `string(24)`, enum-backed (`TransitionSource`, reused) | No | — | |
| `provider_event_id` | `unsignedBigInteger`, FK `payment_provider_events.id`, nullable | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | |
| `failure_reason` | `text`, nullable | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

**Interesting note, an add-on purchase's own charge routes through
`business_funding_attempts`** (`funding_attempt_id`, unique FK) — reusing
M3's own existing funding-attempt machinery rather than inventing a
parallel charge primitive, exactly as RFC-005 §18 itself specifies. This
means `UsageBillingCheckoutManager::initiateCharge()`'s own private
sequence (§3 item 6) is reused for add-on purchases via a thin new public
wrapper (§21), not duplicated.

---

## 19. PHP enums/value objects/models

**Six new enums** (every other M4-relevant enum — `TransitionSource`,
`FundingAttemptState`, `PayerType` — is reused unmodified, confirmed by
RFC-005 §26's own "reused"/"reused shape" language and by direct code
read, §3):

- `App\Enums\Usage\SlotAgreementBillingCadence` — `monthly` only, v1.
- `App\Enums\Usage\SlotAgreementState` — the ten values listed in §18.
- `App\Enums\Usage\SlotRenewalChargeKind` — `scheduled_renewal` \|
  `mid_period_increase`.
- `App\Enums\Usage\SlotRenewalChargeInitiatedBy` — `scheduled_job` \|
  `owner_initiated` \| `admin_retry`.
- `App\Enums\Usage\AddonFulfillmentMode` — `wallet_credit` \|
  `direct_deliverable`.
- `App\Enums\Usage\AddonPurchaseStatus` — `pending` \| `completed` \|
  `failed`.

**Four new readonly value objects**, matching M3's own established
per-manager-method-result pattern (`FundingAttemptResult`,
`PaymentIntentResult`, etc. — none of which RFC-005 §26 names individually
either, confirming this is genuinely an implementation-level, not
RFC-level, DTO layer):

- `App\Library\Usage\SlotAgreementQuoteResult`
- `App\Library\Usage\SlotAgreementCheckoutResult`
- `App\Library\Usage\SlotRenewalChargeResult`
- `App\Library\Usage\AddonPurchaseResult`

**Seven new Eloquent models**, one per table in §18, each casting its own
enum columns exactly as every existing Usage model already does.

**Two new exceptions**, matching the existing `App\Exceptions\Usage\*`
typed-exception convention (`FundingAttemptNotResumableException`,
`UnauthorizedPayerAssignmentException`):

- `App\Exceptions\Usage\SlotRenewalChargeNotResumableException` — mirrors
  `FundingAttemptNotResumableException`'s exact shape, for §14's
  resume/retry action.
- `App\Exceptions\Usage\UnauthorizedSlotAgreementActionException` —
  mirrors `UnauthorizedPayerAssignmentException`'s exact shape, for
  checkout/increase/cancellation consent checks (§8, §14).

---

## 20. Repository contracts

One contract + one Eloquent implementation per table in §18 — **seven
pairs, fourteen files** — bound in `AppServiceProvider`'s existing
`$bindings` array, identically to every prior RFC-004/RFC-005 repository
(§3 item 12). Each is a plain data-access contract, append-only where the
table itself is append-only (the two transition tables and
`business_usage_addon_purchase_transitions` — no `update()` method,
matching `WorkspaceEntitlementTransitionRepository`'s own
"deliberately no `update()`" precedent exactly).

---

## 21. Manager extension — exact new methods on `UsageBillingCheckoutManager`

Extending the existing class (§3 item 6) — no new manager is created.
Every new public method follows the identical "authority check → local
evidence check → provider call strictly outside any open transaction →
confirm/record" shape M3's own `initiateTopUp()`/`confirmAttemptFromReturn()`/
`confirmAttemptFromWebhook()` already establish. **Corrected this
refinement: every controller/job mutation §11–§17 describe now has a
named manager method here — §3 item 7d's audit found four missing
entirely** (`requestSlotAgreementIncrease()`, the scheduled-renewal
creation method, `retrySlotRenewalAsOwner()`,
`finalizeSlotAgreementCancellation()`) **and one requiring a shared
routine to avoid a synthetic administrator id** (§3 item 7e). **This
refinement additionally corrects: the two retry methods to genuinely
re-drive payment rather than merely re-retrieve (§3 item 7k); a new
private `assertPlatformAdministrator()` helper explicit inside every
"AsAdministrator"-named method (§3 item 7l); and the Checkout confirmation
methods to verify all eight of §15a's required conditions and reconcile
the actual paid PaymentMethod via `PaymentInstrumentManager` before
allocation (§3 items 7h–7j).**

**Quote/checkout/confirmation:**

- `quoteAdditionalSlotAgreement(Workspace $workspace, int $targetAllocationCount, int $actorUserId): SlotAgreementQuoteResult`
  — owner-only (§8 item 3's consent check, reusing
  `assertChargeCausingConsent()`-equivalent logic narrowed to "Workspace
  owner only," §16 of RFC-005), reads catalog/tier via §9's two
  `EntitlementManager` calls, computes the price × ratio snapshot (§9
  item 2), creates the agreement row in `state: quote_created`.
- `initiateSlotAgreementCheckout(AdditionalBusinessSlotAgreement $agreement, int $actorUserId): SlotAgreementCheckoutResult`
  — creates the initial **Checkout Session** via
  `PaymentProviderGateway::createCheckoutSession()` (§15a), supplying
  `$lineItemName = 'Additional Business slots'` — **never**
  `createOffSessionPaymentIntent()`, preserving RFC-005's own
  customer-present design — transitions to `checkout_pending`.
- `confirmSlotAgreementFromReturn(AdditionalBusinessSlotAgreement $agreement): SlotAgreementCheckoutResult`
  — mirrors `confirmAttemptFromReturn()` exactly, via
  `retrieveCheckoutSession()` (§15a); never trusts the redirect alone.
  **Corrected this refinement (§3 items 7h–7j, §15a/§15c):** advances past
  `payment_succeeded` only once all eight of §15a's required-verified
  conditions hold (`status === 'complete'`, `payment_status === 'paid'`,
  matching session id/amount/currency/provider customer, non-null
  PaymentIntent and PaymentMethod references); on success, calls
  `PaymentInstrumentManager::syncWorkspaceCheckoutPaymentMethod()` (§15c,
  §8 item 7) with the resolved `providerPaymentMethodId` **before**
  `performVerifiedAllocation()`, and updates the agreement's own
  `payment_method_display_snapshot` exactly once to the actual paid
  card's display value.
- `confirmSlotAgreementFromWebhook(AdditionalBusinessSlotAgreement $agreement, PaymentProviderEvent $event): void`
  — identical §15a eight-condition verification and §15c payment-instrument
  sync as `confirmSlotAgreementFromReturn()` above (corrected this
  refinement), additionally requiring `payment_status === 'paid'`
  explicitly rather than trusting `checkout.session.completed` alone (§15a)
  — then transitions to `payment_succeeded` and calls the shared
  allocation routine below.

**Renewal:**

- `createScheduledRenewalCharge(AdditionalBusinessSlotAgreement $agreement): SlotRenewalChargeResult`
  — new (§3 item 7d, §11) — the method `InitiateSlotAgreementRenewal`
  calls once per due agreement; the job itself creates no row.
- `requestSlotAgreementIncrease(AdditionalBusinessSlotAgreement $agreement, int $targetAllocationCount, string $changeOperationId, int $actorUserId): SlotRenewalChargeResult`
  — new (§3 item 7d, §11) — owner-only consent check, exact-second
  proration (§11), never regenerates `$changeOperationId` (§11's own
  view/form contract supplies it already-stable).
- `retrySlotRenewalAsAdministrator(AdditionalBusinessSlotRenewalCharge $charge, int $actorUserId, string $reason): SlotRenewalChargeResult`
  — **corrected this refinement (§3 items 7k–7l, §13, §14): does not
  mirror `retryFundingAttemptAsAdministrator()`'s retrieve-only shape.**
  Calls the new private `assertPlatformAdministrator($actorUserId)`
  helper first (§14, throws `UnauthorizedSlotAgreementActionException` if
  not a real admin), requires `$reason` non-empty, then re-drives payment
  via `confirmPaymentIntent()` (§15a/§23) against the Workspace's current
  default instrument at the next attempt ordinal — never a mere
  `retrievePaymentIntent()` call.
- `retrySlotRenewalAsOwner(AdditionalBusinessSlotRenewalCharge $charge, int $actorUserId): SlotRenewalChargeResult`
  — new (§3 item 7d, §13) — the Workspace owner's own lapse/failure
  recovery path after correcting their payment method; owner-only
  consent check, no reason required (mirrors the owner's own original
  consent, not an administrator override). **Corrected this refinement
  (§3 item 7k, §13, §23): calls `confirmPaymentIntent()` against the
  Workspace's current default instrument at the next attempt ordinal —
  the identical real-retry mechanism `retrySlotRenewalAsAdministrator()`
  uses, minus the admin check/mandatory reason.**

**Cancellation:**

- `requestSlotAgreementCancellation(AdditionalBusinessSlotAgreement $agreement, int $actorUserId, ?string $reason = null): AdditionalBusinessSlotAgreement`
  — `$reason` mandatory when `$actorUserId` is an administrator, optional
  for the owner's own agreement (§12/§14). **Corrected this refinement
  (§3 item 7l):** the administrator branch calls the same private
  `assertPlatformAdministrator($actorUserId)` helper before accepting the
  action as an administrator cancellation — an actor who is not a real
  platform administrator and not the agreement's own Workspace owner is
  rejected outright, never silently treated as "owner, reason optional."
- `finalizeSlotAgreementCancellation(AdditionalBusinessSlotAgreement $agreement): AdditionalBusinessSlotAgreement`
  — new (§3 item 7d, §12) — the method `FinalizeSlotAgreementCancellation`
  calls once per due agreement; the job itself creates no row.

**Allocation — one shared routine, no synthetic actor:**

- `performVerifiedAllocation(AdditionalBusinessSlotAgreement $agreement, ?int $administratorActorUserId = null, ?string $reason = null): WorkspacePlanAssignment`
  — **new, the one place §8 items 1–5 are implemented** (§8 item 6, §3
  item 7e). Verifies the agreement's own durable local evidence (§8 item
  2), then calls
  `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`
  with the exact parameter provenance §8 item 3 locks —
  `$requestingCustomerUserId` is **always** the agreement's own
  `requesting_customer_user_id`, **never** `$administratorActorUserId`,
  regardless of which caller invoked this routine. Records the M4
  `additional_business_slot_agreement_transitions` row with
  `actor_user_id: $administratorActorUserId` (`null` for the ordinary
  payment-triggered and reconciliation paths, the real administrator id
  only when supplied, §14's own identity-provenance split). **Called by
  exactly three sites, never duplicated:**
  1. `confirmSlotAgreementFromWebhook()` (ordinary payment confirmation,
     `$administratorActorUserId: null`, `$reason: null`);
  2. `ReconcileSlotAgreementAllocation` (§22 — calls this routine
     directly, `$administratorActorUserId: null`, `$reason: null` — **no
     fake/system administrator id of any kind**, closing §3 item 7e's own
     finding);
  3. `allocateSlotAgreementAsAdministrator()` (below — the real
     administrator's own id and mandatory reason passed through).
- `allocateSlotAgreementAsAdministrator(AdditionalBusinessSlotAgreement $agreement, int $actorUserId, string $reason): WorkspacePlanAssignment`
  — the manual allocation action (§8 item 3, §14) — a thin wrapper
  around `performVerifiedAllocation($agreement, $actorUserId, $reason)`,
  never a fresh charge, never its own separate allocation logic.
  **Corrected this refinement (§3 item 7l):** calls
  `assertPlatformAdministrator($actorUserId)` first, before `$reason` is
  even checked for non-emptiness — never relies solely on the admin
  route/middleware to have already enforced this.

**Internal authority helper (new private method, §3 item 7l — an
authorized addition to the already-allowlisted manager file, item 41, no
new path):**

```php
private function assertPlatformAdministrator(int $actorUserId): void
```

Mirrors `UsageWalletManager::assertPlatformAdministrator()`'s exact shape
(a direct `users.is_admin` read via `DB::table('users')`) but throws
`UnauthorizedSlotAgreementActionException` (§19, already allowlisted,
M4's own typed exception) rather than M3's `UnauthorizedUsageBillingManagementException`
— called at the start of `retrySlotRenewalAsAdministrator()`,
`allocateSlotAgreementAsAdministrator()`, and the administrator branch of
`requestSlotAgreementCancellation()`, before any mutation or provider
call.

**Add-ons:**

- `initiateAddonPurchase(Business $business, string $addonKey, int $actorUserId): AddonPurchaseResult`
  — looks up the (test-created only, §10) active catalog row by
  `addon_key`, fails closed if absent or inactive, then calls
  `initiateCharge()` with `purpose: FundingAttemptPurpose::AddonPurchase`
  (already defined at the enum level by M3 but never set by any M3 code
  path, confirmed by the merged M3 contract's own §6 finding — M4 is the
  first to actually set it). **The outbound PaymentIntent's own metadata
  remains `app_subject_kind: 'funding_attempt'`** (§15) — this method
  introduces no new metadata shape.
- **`initiateCharge()`'s own existing private sequence (§3 item 6) is
  internally extended, inside this already-allowlisted file, with an
  optional post-attempt-creation hook** — a narrow, authorized internal
  refactor, not a new path (§25 item 41) — so that, for
  `purpose: AddonPurchase` only, the exact ordering below holds, closing
  the webhook race the naive "create attempt, call provider, create
  purchase row afterward" sequence would otherwise leave open:
  1. create the local `business_funding_attempts` row (unchanged,
     existing step);
  2. **immediately, still inside that same transaction**, create the
     linked `business_usage_addon_purchases` row
     (`business_id`, `addon_key`, `price_micro` snapshotted from the
     catalog row, `funding_attempt_id: $attempt->id`,
     `requested_by_user_id: $actorUserId`, `status: pending`) — the new
     hook's own sole responsibility;
  3. commit that local transaction (unchanged, existing step);
  4. only then perform the outbound `createOffSessionPaymentIntent()`
     call, strictly outside any open transaction/lock (unchanged, exactly
     M3's own existing discipline, §8/§16 of the M3 contract);
  5. persist the returned provider reference/state (unchanged, existing
     step).
  For `ManualTopUp`/`AutoRecharge`, the hook is simply absent — their own
  existing behavior, ordering, and observable outcome are **entirely
  unchanged**.
- **`confirmSucceeded()` (private, existing) becomes purpose-aware,
  replacing its own current unconditional "anything that isn't
  `AutoRecharge` is `PaidTopUp`" ternary** — the exact defect this
  refinement corrects (a validated `AddonPurchase` attempt must never fall
  through to an unconditional wallet credit). The corrected dispatch:
  `AutoRecharge` → credit the wallet with a `UsageLedgerEntryType::AutoRecharge`
  entry (unchanged); `ManualTopUp` → credit the wallet with a
  `UsageLedgerEntryType::PaidTopUp` entry (unchanged); `AddonPurchase` →
  locate the unique `business_usage_addon_purchases` row by
  `funding_attempt_id`, then finalize it (below) — **never** an
  unconditional wallet credit. `ManualTopUp`/`AutoRecharge`'s own
  observable behavior — same entry type, same amount, same
  `local_idempotency_key.':credit'` idempotency suffix — is byte-for-byte
  unchanged by this refactor; only `AddonPurchase` gains new behavior
  where none existed before (M3 never set this purpose, §3 item 6).
- **Add-on purchase finalization (new private logic, inside the same
  already-allowlisted manager)** — idempotent: if the purchase row is
  already `completed`, no-op. Otherwise, transitions the purchase to
  `completed`, records the transition with the correct `source`
  (`TransitionSource::SyncResponse` for a synchronous provider-succeeded
  result, `TransitionSource::WebhookEvent` plus the real
  `provider_event_id` for a webhook confirmation — reusing `TransitionSource`
  exactly as every other M4 transition table already does) and applies
  the catalog row's own `fulfillment_mode`: a wallet credit via the
  existing `UsageWalletManager` ledger-insert mechanism **only** when
  `fulfillment_mode: wallet_credit`; a pure state-machine completion, no
  wallet mutation, when `fulfillment_mode: direct_deliverable` (no actual
  delivery mechanism is invented by this contract, §10). **Because both
  `confirmAttemptFromReturn()`'s own synchronous path and
  `confirmAttemptFromWebhook()`'s own webhook path already converge on
  the identical `confirmSucceeded()` call (confirmed by direct read of
  the merged M3 code, §3 item 6), a synchronous provider-succeeded result
  and a later webhook success necessarily converge on this exact same
  finalization logic by construction — no separate convergence point is
  needed or added.**
- **The "attempt succeeded, purchase still pending" replay hole is
  closed by widening `confirmAttemptFromReturn()`'s and
  `confirmAttemptFromWebhook()`'s own existing "already `Succeeded`" early
  return — for `AddonPurchase` only.** Confirmed by direct read of the
  merged M3 code: both methods currently return immediately once
  `$attempt->state === Succeeded`, before ever re-checking the linked
  purchase — if the process crashes between the funding attempt reaching
  `Succeeded` and the purchase reaching `completed` (§3's own scenario:
  attempt succeeds → crash → webhook retries → sees `Succeeded` → returns
  → purchase stays pending forever), no code path ever revisits it. The
  corrected guard, in both methods:
  ```php
  if ($attempt->state === FundingAttemptState::Succeeded) {
      if ($attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
          $this->finalizeAddonPurchaseIfPending($attempt, $source, $providerEventId);
      }
      return;
  }
  ```
  `finalizeAddonPurchaseIfPending()` reuses the identical idempotent
  finalization logic the bullet above already defines (no-op if already
  `completed`) — it does **not** re-record the funding attempt's own
  `Succeeded` transition a second time, only re-attempts the purchase's
  own finalization. This one guard correctly handles every scenario named
  in the requirement: synchronous return followed by a later webhook
  (webhook's own guard now finalizes what the sync path may have missed);
  webhook followed by browser return (return's own guard finalizes what
  the webhook may have missed); duplicate webhook delivery (finalization's
  own no-op-if-`completed` idempotency absorbs it); and process failure
  strictly between the two writes (whichever confirmation path runs next —
  sync or webhook, in either order — finalizes the still-pending purchase).
  **`ManualTopUp`/`AutoRecharge`'s own existing already-`Succeeded`
  no-op behavior is completely unchanged** — the new inner check only
  ever executes for `purpose === AddonPurchase`; every other purpose's
  early return is the identical bare `return` it already is today.

Every method above validates every applicable persisted expectation
(provider object id, operation id, amount, currency, customer) before any
mutation, identical to M3's own `ProcessPaymentProviderEvent`-adjacent
discipline — restated here rather than left implicit.

---

## 22. Jobs, events, scheduling

**Four new jobs**, all `App\Jobs\Usage\*`, extending `App\Jobs\Base`,
`ShouldQueue` + `ShouldQueueAfterCommit` where dispatched from within a
request-handling transaction:

- `InitiateSlotAgreementRenewal` — scheduled **every 5 minutes** (§11) —
  selects due agreements, calls `createScheduledRenewalCharge()` (§21)
  once per agreement; creates no row itself.
- `FinalizeSlotAgreementCancellation` — scheduled **every 5 minutes**
  (§12) — selects due agreements, calls
  `finalizeSlotAgreementCancellation()` (§21) once per agreement; creates
  no row itself.
- `ReconcileSlotAgreementAllocation` — scheduled **hourly** — finds
  agreements stuck in `allocation_pending` past a bounded threshold and
  calls `UsageBillingCheckoutManager::performVerifiedAllocation($agreement)`
  **directly, with both `$administratorActorUserId` and `$reason` left
  `null`** (§8 item 6, §21) — **no system-actor or fake-administrator id
  of any kind is passed or constructed anywhere in this job** (§3 item
  7e's own finding, corrected). Never a fresh charge, never a new
  idempotency key — `performVerifiedAllocation()`'s own reuse of the
  agreement's existing `local_idempotency_key`-derived value (§8 item 3)
  is what makes this call safely, natively idempotent with the ordinary
  payment-confirmation path.
- `SendSlotAgreementPriceChangeNotice` — dispatched (not scheduled),
  fired whenever `createScheduledRenewalCharge()` computes a renewal
  amount differing from the prior period's own recorded amount (§9 item
  5).

**Five new events**, all `App\Events\Usage\*`, `implements
ShouldDispatchAfterCommit`, carrying IDs/scalars only:

- `AdditionalBusinessSlotAgreementCompleted`
- `AdditionalBusinessSlotAllocationFailed`
- `AdditionalBusinessSlotAgreementLapsed`
- `AdditionalBusinessSlotAgreementCanceled`
- `AdditionalBusinessSlotAgreementPaymentRecovered`

No add-on-purchase-specific event is created — RFC-005 §29's own 17-event
list names none, and `business_usage_addon_purchase_transitions` already
provides the durable audit (§10, §18).

**Scheduling — locked, not an implementation-time choice** —
`app/Console/Kernel.php` (§3 item 11, modified) gains exactly three new
`$schedule->job(...)` registrations: `InitiateSlotAgreementRenewal`
**every 5 minutes**, `FinalizeSlotAgreementCancellation` **every 5
minutes**, `ReconcileSlotAgreementAllocation` **hourly** — these three
exact values, matching RFC-005's own recorded recommendation and the
scheduler shape this contract already describes; a future change requires
its own separate authorization/configuration, never an ad hoc
implementation-time decision. `SendSlotAgreementPriceChangeNotice` is
event/job-dispatched, never scheduled.

---

## 23. Concurrency, lock order, idempotency, and retry rules

- **Canonical lock order — unchanged in shape**, extended: agreement-row
  lock (`findForUpdate`-equivalent) before any renewal/increase/
  cancellation mutation, exactly mirroring the wallet-row-lock pattern M3
  already establishes for funding attempts.
- **New idempotency keys**, all enforced at the schema level (§18):
  `additional_business_slot_agreements.local_idempotency_key`/
  `provider_session_or_intent_reference` (both unique);
  `additional_business_slot_renewal_charges.local_idempotency_key`/
  `provider_session_or_intent_reference`/`change_operation_id` (all
  unique).
- **Forced-race test scenarios (§27):** two concurrent
  `quoteAdditionalSlotAgreement()`/`initiateSlotAgreementCheckout()` calls
  for the same Workspace; two workers racing to process the same
  `slot_renewal_charge`/`slot_agreement` webhook event; two distinct
  mid-period increases racing within the same billing period (must never
  collide on `local_idempotency_key`, per RFC-005 §22's own named
  scenario); a genuine retry of the *same* increase (same
  `change_operation_id`) racing its own original attempt; concurrent
  `ReconcileSlotAgreementAllocation` and administrator manual-allocation
  attempts for the same stuck agreement (must compose via Amendment 1's
  own idempotency guarantee, never double-allocate, §8 item 4); a genuine
  retry racing a crash/re-entry of a prior retry for the same renewal
  charge (must reuse the same attempt ordinal, never skip or double-count,
  below).

### Attempt-ordinal derivation — exact, durable, no new schema column (§3 item 7k)

**Confirmed by a targeted read of `additional_business_slot_renewal_charges`/
`additional_business_slot_renewal_charge_transitions`' own schema (§18):
neither table has an attempt-counter, retry-ordinal, or lease column — the
per-instruction bar for using the existing transitions table rather than
inventing one.** The following algorithm is durable and crash-safe using
only existing columns:

1. **The current attempt ordinal for a renewal charge = 1 + the count of
   already-recorded `to_state = 'failed'` rows in
   `additional_business_slot_renewal_charge_transitions` for that exact
   `renewal_charge_id`**, read within the same agreement/charge-row lock
   (§23's own canonical lock order) that begins the attempt — **never**
   cached in job/process memory, **never** inferred from any other column,
   always freshly computed from the durable, append-only transitions
   table (this table has no `update()` method, §20 — a `failed` transition,
   once written, is a permanent historical fact, never revised).
2. **A `failed` transition is written only strictly after Stripe's own
   definitive failure response for that specific attempt** — identical
   discipline to M3's own `markFailed()` (never written speculatively
   before the provider call, never written for a merely-pending/
   `requires_action` outcome).
3. **The Stripe-facing `idempotency_key` for attempt N is
   `sha256($renewalCharge->local_idempotency_key . ':attempt:' . N)`**
   (§11) — attempt 1 uses `createOffSessionPaymentIntent()`; attempts 2
   and 3 use the new `confirmPaymentIntent()` (§15a) against the charge's
   own existing `provider_session_or_intent_reference` and the Workspace's
   **current** default instrument (which may have changed since attempt
   1, §15c).
4. **Crash/re-entry safety — by construction, not by extra bookkeeping:**
   if a retry crashes before Stripe's response is known, no `failed`
   transition was written, so a re-entry recomputes the identical ordinal
   N and the identical idempotency key; Stripe's own idempotency guarantee
   returns the cached result of the original request (success or failure)
   rather than performing a second charge attempt — the local write then
   completes correctly on this re-entry. **The worker restarting never, by
   itself, advances the ordinal** — only a definitively-recorded `failed`
   transition does.
5. **Cap:** once the count of `failed` transitions for a charge reaches 3
   (i.e., attempt 3 itself has failed), no further attempt is possible —
   `retrySlotRenewalAsOwner()`/`retrySlotRenewalAsAdministrator()` instead
   observe `payment_lapsed = true` (§13) and return without calling the
   gateway again; this cap is enforced by the ordinal computation itself
   (ordinal would compute to 4), independent of any caller's own
   discipline.
6. **A race between two concurrent retry invocations for the same charge**
   (e.g., a double-submitted owner retry) is absorbed at two independent
   layers: the agreement/charge-row lock (§23's canonical order) serializes
   the ordinal *read*, and even if both somehow computed the same ordinal
   before serialization, Stripe's own idempotency guarantee on the
   *identical* derived key ensures at most one real charge attempt
   results.

---

## 24. Test-mode preview

Extends M3's own already-established local Stripe-test-mode preview
procedure (M3 contract §20) — same Stripe CLI forwarding command, same
test-mode publishable/secret keys, same `ultimatesms_testing` database —
to the new checkout/renewal/cancellation click paths. No new preview
infrastructure is introduced; the exact preview steps (URL, click path,
test card numbers, expected state transitions, cleanup) are the future
implementation's own responsibility to document, following M3's own §20
as the template, not re-specified line-by-line in this contract.

---

## 25. Exact implementation allowlist

**Closed, numbered, path-level. Any additional path required during
implementation is a stop-and-report condition (§29). 95 paths total —
corrected this refinement from 93: the Checkout Session
line-item/PaymentMethod-capture/`confirmPaymentIntent()` corrections
(§3 items 7g–7k) are absorbed entirely by the already-allowlisted gateway
files (items 88–90, no new path), but the payment-instrument
reconciliation fix (§3 item 7j, §15c) genuinely requires two further
paths — one modified manager-adjacent file and one modified test (items
94–95) — that could not honestly have been part of the prior count, since
that gap was not yet found.**

### Migrations (7 new)

1. `database/migrations/{impl_date}_create_additional_business_slot_agreements_table.php`
2. `database/migrations/{impl_date}_create_additional_business_slot_agreement_transitions_table.php`
3. `database/migrations/{impl_date}_create_additional_business_slot_renewal_charges_table.php`
4. `database/migrations/{impl_date}_create_additional_business_slot_renewal_charge_transitions_table.php`
5. `database/migrations/{impl_date}_create_business_usage_addon_catalog_table.php`
6. `database/migrations/{impl_date}_create_business_usage_addon_purchases_table.php`
7. `database/migrations/{impl_date}_create_business_usage_addon_purchase_transitions_table.php`

### Enums (6 new)

8. `app/Enums/Usage/SlotAgreementBillingCadence.php`
9. `app/Enums/Usage/SlotAgreementState.php`
10. `app/Enums/Usage/SlotRenewalChargeKind.php`
11. `app/Enums/Usage/SlotRenewalChargeInitiatedBy.php`
12. `app/Enums/Usage/AddonFulfillmentMode.php`
13. `app/Enums/Usage/AddonPurchaseStatus.php`

### Value objects / DTOs (4 new)

14. `app/Library/Usage/SlotAgreementQuoteResult.php`
15. `app/Library/Usage/SlotAgreementCheckoutResult.php`
16. `app/Library/Usage/SlotRenewalChargeResult.php`
17. `app/Library/Usage/AddonPurchaseResult.php`

### Exceptions (2 new)

18. `app/Exceptions/Usage/SlotRenewalChargeNotResumableException.php`
19. `app/Exceptions/Usage/UnauthorizedSlotAgreementActionException.php`

### Models (7 new)

20. `app/Models/AdditionalBusinessSlotAgreement.php`
21. `app/Models/AdditionalBusinessSlotAgreementTransition.php`
22. `app/Models/AdditionalBusinessSlotRenewalCharge.php`
23. `app/Models/AdditionalBusinessSlotRenewalChargeTransition.php`
24. `app/Models/BusinessUsageAddonCatalog.php`
25. `app/Models/BusinessUsageAddonPurchase.php`
26. `app/Models/BusinessUsageAddonPurchaseTransition.php`

### Repository contracts (7 new)

27. `app/Repositories/Contracts/AdditionalBusinessSlotAgreementRepository.php`
28. `app/Repositories/Contracts/AdditionalBusinessSlotAgreementTransitionRepository.php`
29. `app/Repositories/Contracts/AdditionalBusinessSlotRenewalChargeRepository.php`
30. `app/Repositories/Contracts/AdditionalBusinessSlotRenewalChargeTransitionRepository.php`
31. `app/Repositories/Contracts/BusinessUsageAddonCatalogRepository.php`
32. `app/Repositories/Contracts/BusinessUsageAddonPurchaseRepository.php`
33. `app/Repositories/Contracts/BusinessUsageAddonPurchaseTransitionRepository.php`

### Eloquent repositories (7 new)

34. `app/Repositories/Eloquent/EloquentAdditionalBusinessSlotAgreementRepository.php`
35. `app/Repositories/Eloquent/EloquentAdditionalBusinessSlotAgreementTransitionRepository.php`
36. `app/Repositories/Eloquent/EloquentAdditionalBusinessSlotRenewalChargeRepository.php`
37. `app/Repositories/Eloquent/EloquentAdditionalBusinessSlotRenewalChargeTransitionRepository.php`
38. `app/Repositories/Eloquent/EloquentBusinessUsageAddonCatalogRepository.php`
39. `app/Repositories/Eloquent/EloquentBusinessUsageAddonPurchaseRepository.php`
40. `app/Repositories/Eloquent/EloquentBusinessUsageAddonPurchaseTransitionRepository.php`

### Manager (1 modified)

41. `app/Library/Usage/UsageBillingCheckoutManager.php` — extended with
    **thirteen** new public methods (§21, corrected this refinement from
    eight — §3 item 7d's audit found four missing entirely:
    `createScheduledRenewalCharge()`, `requestSlotAgreementIncrease()`,
    `retrySlotRenewalAsOwner()`, `finalizeSlotAgreementCancellation()`;
    plus the new shared allocation routine, `performVerifiedAllocation()`
    — public in visibility, since `ReconcileSlotAgreementAllocation` must
    call it directly as an external caller, §22, but narrowly scoped and
    named to signal it is not a general-purpose entry point, matching the
    "shared internal routine" role §8 item 6 requires), plus three
    authorized internal refactors to this same file's own existing
    private methods, neither of which is a new path: `initiateCharge()`
    gains an optional post-attempt-creation, still-in-transaction hook
    (used only for `AddonPurchase`, §21's exact 5-step ordering);
    `confirmSucceeded()` becomes purpose-aware (§21), replacing its own
    unconditional `AutoRecharge`-or-`PaidTopUp` ternary with a three-way
    dispatch that adds an `AddonPurchase` branch, and both
    `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`'s own
    already-`Succeeded` early-return guards gain the narrow
    `AddonPurchase`-only re-finalization check (§21, the replay-hole
    fix); a new private `assertPlatformAdministrator()` helper is added
    (§3 item 7l, §21, §14), called by `retrySlotRenewalAsAdministrator()`,
    `allocateSlotAgreementAsAdministrator()`, and the administrator branch
    of `requestSlotAgreementCancellation()`. **`ManualTopUp`/`AutoRecharge`'s
    own observable behavior is unchanged** — same wallet-credit entry
    type, same amount, same idempotency suffix, same ordering, same
    already-`Succeeded` no-op; only `AddonPurchase` (never set by any M3
    code path, §3 item 6) gains
    new behavior where none previously existed.

### Jobs (4 new)

42. `app/Jobs/Usage/InitiateSlotAgreementRenewal.php`
43. `app/Jobs/Usage/FinalizeSlotAgreementCancellation.php`
44. `app/Jobs/Usage/ReconcileSlotAgreementAllocation.php`
45. `app/Jobs/Usage/SendSlotAgreementPriceChangeNotice.php`

### Job widening (1 modified)

46. `app/Jobs/Usage/ProcessPaymentProviderEvent.php` — widened per §15;
    the existing `funding_attempt` branch's own behavior is unchanged.

### Events (5 new)

47. `app/Events/Usage/AdditionalBusinessSlotAgreementCompleted.php`
48. `app/Events/Usage/AdditionalBusinessSlotAllocationFailed.php`
49. `app/Events/Usage/AdditionalBusinessSlotAgreementLapsed.php`
50. `app/Events/Usage/AdditionalBusinessSlotAgreementCanceled.php`
51. `app/Events/Usage/AdditionalBusinessSlotAgreementPaymentRecovered.php`

### Controllers (2 new)

52. `app/Http/Controllers/Customer/Workspace/AdditionalBusinessSlotAgreementController.php`
53. `app/Http/Controllers/Admin/AdditionalBusinessSlotAgreementController.php`

### Form Requests (6 new)

54. `app/Http/Requests/Customer/Workspace/InitiateSlotAgreementCheckoutRequest.php`
55. `app/Http/Requests/Customer/Workspace/RequestSlotAgreementIncreaseRequest.php`
56. `app/Http/Requests/Customer/Workspace/CancelSlotAgreementRequest.php`
57. `app/Http/Requests/Admin/RetrySlotRenewalAsAdministratorRequest.php`
58. `app/Http/Requests/Admin/AllocateSlotAgreementAsAdministratorRequest.php`
59. `app/Http/Requests/Admin/CancelSlotAgreementAsAdministratorRequest.php`

### Views (3 new)

60. `resources/views/customer/workspace/additional-business-slots/show.blade.php`
61. `resources/views/admin/additional-business-slot-agreements/index.blade.php`
62. `resources/views/admin/additional-business-slot-agreements/show.blade.php`

### Routes (2 modified)

63. `routes/customer.php` — adds the Workspace-scoped checkout/increase/
    cancellation routes (§3 item 13); no existing route's behavior
    changes.
64. `routes/admin.php` — adds the narrowly-scoped
    `AdditionalBusinessSlotAgreementController` routes, matching the
    existing `provider-events` convention exactly (§3 item 14); no
    existing route's behavior changes.

### Scheduler (1 modified)

65. `app/Console/Kernel.php` — registers the three new scheduled jobs
    (§22); no existing job's schedule changes.

### Dependency injection (1 modified)

66. `app/Providers/AppServiceProvider.php` — adds the seven new repository
    bindings (§3 item 12, §20); no existing binding changes.

### Schema tests (7 new)

67. `tests/Feature/Usage/AdditionalBusinessSlotAgreementSchemaTest.php`
68. `tests/Feature/Usage/AdditionalBusinessSlotAgreementTransitionSchemaTest.php`
69. `tests/Feature/Usage/AdditionalBusinessSlotRenewalChargeSchemaTest.php`
70. `tests/Feature/Usage/AdditionalBusinessSlotRenewalChargeTransitionSchemaTest.php`
71. `tests/Feature/Usage/BusinessUsageAddonCatalogSchemaTest.php`
72. `tests/Feature/Usage/BusinessUsageAddonPurchaseSchemaTest.php`
73. `tests/Feature/Usage/BusinessUsageAddonPurchaseTransitionSchemaTest.php`

### RFC-005 §35-named behavior tests (6 new)

74. `tests/Feature/Usage/AdditionalBusinessSlotAgreementCancellationTest.php`
75. `tests/Feature/Usage/AdditionalBusinessSlotAgreementProrationTest.php`
76. `tests/Feature/Usage/AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php`
    — **strengthened this refinement (§11, §4):** asserts
    `change_operation_id` is supplied by the caller (simulating the
    view/form's own one-UUID-per-render contract) and is never generated
    or overwritten by `requestSlotAgreementIncrease()` itself; a second
    call reusing the identical `change_operation_id` (simulating a genuine
    client retry of the same form submission) reuses the identical
    `local_idempotency_key` and is absorbed as a no-op, while a call with
    a *different* `change_operation_id` (simulating a fresh render)
    produces a genuinely distinct renewal-charge row.
77. `tests/Feature/Usage/AdditionalBusinessSlotAgreementFailedPeriodTest.php`
    — **strengthened this refinement (§13, §23, §3 item 7k):** asserts
    **exactly 3 total attempts** (the initial attempt plus 2 retries, never
    phrased as "3 retries") — a 4th attempt is explicitly asserted to be
    unreachable once `payment_lapsed` is set (the ordinal-cap mechanism
    itself, §23, not merely a caller-side check). **Asserts each retry is
    a genuine second provider-side call** — via the fake gateway's own new
    call-recording (§15a), a retry's `confirmPaymentIntent()` invocation
    is asserted to actually occur, with the ordinal-derived idempotency
    key, not merely that the existing PaymentIntent was re-retrieved.
78. `tests/Feature/Usage/AdditionalBusinessSlotAgreementRenewalContactSnapshotTest.php`
79. `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` —
    **strengthened this refinement (§21, §3 item 3):** in addition to its
    own existing transition-audit assertions, directly proves the
    "attempt succeeded, purchase still pending" replay hole is closed —
    a funding attempt is driven to `Succeeded` with its linked purchase
    deliberately left `pending` (simulating a crash between the two
    writes), then `confirmAttemptFromWebhook()` is called again (a
    simulated webhook retry): the purchase reaches `completed`, exactly
    once, with the correct transition `source`. Repeated for the
    equivalent `confirmAttemptFromReturn()` path, and for a duplicate
    webhook delivery against an already-`completed` purchase (asserted
    idempotent, no second transition row). `ManualTopUp`/`AutoRecharge`'s
    own already-`Succeeded` no-op behavior is asserted unchanged by the
    same test run.

### Additional M4-specific tests (8 new)

80. `tests/Feature/Usage/SlotAgreementAllocationSagaTest.php` — §8's full
    `payment_succeeded` → `allocation_pending` → `completed`/
    `allocation_failed` saga, against the real, merged
    `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`.
    **Strengthened this refinement (§8 item 6, §21):** asserts all three
    callers of `performVerifiedAllocation()` (the ordinary webhook
    confirmation path, a direct reconciliation-style call, and the
    administrator manual-allocation action) produce the identical
    `requesting_customer_user_id` on the resulting RFC-004 transition —
    the original Workspace owner in every case, never the calling
    context's own identity. **Further strengthened this refinement (§3
    item 7j, §8 item 7, §15c):** the fake gateway is configured so the
    Checkout Session's actual PaymentMethod differs from the Workspace's
    previously-saved default instrument; asserts
    `syncWorkspaceCheckoutPaymentMethod()` reconciles it as the new
    default in `business_payment_instruments`, and that the agreement's
    own `payment_method_display_snapshot` reflects the **actual paid
    card**, not the stale prior default — proving the snapshot
    corresponds to the card genuinely charged.
81. `tests/Feature/Usage/SlotAgreementQuoteSnapshotImmutabilityTest.php` —
    §9 items 2/4/5: **explicitly proves the price × ratio formula** —
    two quotes taken against catalog rows differing only in
    `additional_business_slot_price_ratio` (base price held constant)
    produce two different `price_per_slot_micro_snapshot`/
    `total_amount_micro_snapshot` values, in the exact proportion the
    ratio itself dictates (e.g. a `1.0000` ratio prices the slot at the
    full base plan price; a `0.5000` ratio exactly halves it) — the
    direct regression test for this refinement's own correction. Then
    proves immutability: editing the catalog's `price`/ratio **after** an
    agreement already exists changes neither that agreement's own frozen
    `*_snapshot` columns nor its already-computed
    `total_amount_micro_snapshot`, while a **renewal charge** created
    after that same edit correctly re-snapshots and recomputes from the
    *new* price/ratio (§9 item 5) — proving the frozen-original-vs.-
    fresh-renewal distinction directly, not merely asserted in prose.
82. `tests/Feature/Usage/SlotAgreementAdminAuthorityTest.php` — §14's full
    table: origination denied for administrators; resume/retry, manual
    allocation, and cancellation each succeed only with a mandatory
    reason. **Asserts the exact identity-provenance split (§14):** the
    resulting RFC-004 `workspace_entitlement_transitions` row has
    `actor_user_id === null` and `requesting_customer_user_id` equal to
    the *original* Workspace owner who created the agreement — never the
    administrator's own id — while the corresponding M4
    `additional_business_slot_agreement_transitions` row's own
    `actor_user_id` equals the *administrator's* real id, proving the two
    records are genuinely separate, never conflated, and the
    administrator is never substituted for `requesting_customer_user_id`.
    **Strengthened this refinement (§3 item 7l, §21):** a non-admin actor
    directly invoking `retrySlotRenewalAsAdministrator()`,
    `allocateSlotAgreementAsAdministrator()`, or the administrator branch
    of `requestSlotAgreementCancellation()` **on the manager itself** —
    bypassing the HTTP layer and its admin-only middleware entirely — is
    asserted denied by the manager's own internal
    `assertPlatformAdministrator()` check, proving the authority check
    does not depend on route/middleware placement.
83. `tests/Feature/Usage/WebhookSlotAgreementSubjectRoutingTest.php` — §15:
    `ProcessPaymentProviderEvent` correctly routes exactly the three
    canonical `app_subject_kind` values (`funding_attempt`,
    `slot_agreement`, `slot_renewal_charge`), still fails closed on
    missing/unrecognized metadata (including a probe asserting
    `app_subject_kind: 'addon_purchase'` is itself unrecognized and fails
    closed — never a fourth accepted kind), and **explicitly proves an
    add-on purchase's own webhook event arrives through the existing
    `funding_attempt` subject and is correctly distinguished, after full
    validation, by the attempt's own persisted `purpose ===
    FundingAttemptPurpose::AddonPurchase` — never by a separate subject
    kind.** Also asserts `ManualTopUp`/`AutoRecharge`'s own existing
    confirmation behavior is observably unchanged by `confirmSucceeded()`'s
    new purpose-aware dispatch (§21) — a direct regression check against
    M3's own existing `WebhookMetadataMismatchTest`-equivalent assertions.
    **Strengthened this refinement (§15b):** a `checkout.session.completed`-
    shaped event (carrying `amount_total`, no `amount` field) routed
    through `slot_agreement` normalizes the correct confirmed amount via
    `verifyWebhookSignature()`'s corrected fallback — proving the
    `amount`-vs-`amount_total` fix directly, alongside a PaymentIntent-
    shaped event (carrying `amount`, no `amount_total`) asserted to
    continue normalizing exactly as before.
84. `tests/Feature/Usage/SlotAgreementConcurrencyTest.php` — §23's forced-
    race scenarios: concurrent checkout, concurrent webhook processing,
    two distinct mid-period increases, a genuine increase retry, and
    concurrent reconciliation-vs-administrator allocation — **asserting
    both converge on the same `performVerifiedAllocation()` routine and
    the same idempotency guarantee, never a duplicate allocation, never a
    synthetic administrator id anywhere in the reconciliation path** (§8
    item 6, §22).
85. `tests/Feature/Usage/SlotAgreementLapseRecoveryTest.php` — §13:
    bounded retries, `payment_lapsed`/`payment_lapsed_at` set, no further
    automatic attempt, forward-only recomputed `next_renewal_at` on
    recovery, **and an explicit assertion that
    `workspace_plan_assignments.additional_business_slots` is never
    decremented and `EntitlementManager::setAdditionalBusinessSlots()` is
    never called** by any lapse-reacting code path (§4 qualifier, §13's
    own no-revocation requirement, made directly testable rather than
    merely asserted in prose).
86. `tests/Feature/Usage/EntitlementCatalogSourceBoundaryTest.php` — the
    required mechanical source-boundary test (§3 item 18). **Corrected
    this refinement (§3 item 7m):** the prior description asserted an
    impossible repository-wide claim (no file *anywhere* references the
    three RFC-004 tables — false on its face, since RFC-004's own models,
    migrations, and tests legitimately reference their own tables). The
    actual, narrower M4 source-boundary invariant this test proves,
    scoped exclusively to §25's own M4-authorized production PHP paths:
    (a) no M4 production file imports or resolves any RFC-004 repository
    contract directly; (b) no M4 production file performs raw
    `DB::table(...)`, query-builder, Eloquent, or repository access
    against `workspace_plan_catalog`, `workspace_plan_assignments`, or
    `workspace_entitlement_transitions`; (c) `UsageBillingCheckoutManager`
    calls only the three specifically-authorized public `EntitlementManager`
    seams (§3 items 1/3/4); (d) no M4 controller or job calls
    `allocateAdditionalBusinessSlotsFromVerifiedPayment()` directly
    (only `performVerifiedAllocation()`, §8 item 6, may); (e) no M4 code
    calls `updateCatalogPricing()` anywhere (§9).
87. `tests/Feature/Usage/AddonCatalogZeroSeedTest.php` — §10:
    `business_usage_addon_catalog` contains exactly zero rows immediately
    after every M4 migration runs, and no seeder class of any kind exists
    for this table.

### Gateway extension (4 new/modified — §15a/§15b; corrected this
refinement: appended rather than inserted mid-list, so items 1–87 above
keep their exact original numbers; the prior draft claimed the gateway
required zero changes, §3 items 7a–7c found this false)

88. `app/Library/Usage/Contracts/PaymentProviderGateway.php` — modified;
    adds `createCheckoutSession()` (with the `$lineItemName` parameter
    and corrected line-item request shape, §15a, corrected this
    refinement — §3 item 7g), `retrieveCheckoutSession()`, and
    `confirmPaymentIntent()` (new this refinement — §3 item 7k, §15a); no
    existing method signature changes.
89. `app/Library/Usage/StripePaymentProviderGateway.php` — modified;
    implements all three new methods (§15a, corrected this refinement for
    the `line_items`/`price_data` request shape, nullable-`redirectUrl`
    fail-closed-on-create rule, PaymentMethod-expansion-on-retrieve
    resolution, and `confirmPaymentIntent()`'s `paymentIntents->confirm()`
    call); widens `verifyWebhookSignature()`'s amount extraction to fall
    back to `amount_total` when `amount` is absent (§15b); existing
    PaymentIntent-event normalization and every other existing method are
    unchanged.
90. `app/Library/Usage/FakePaymentProviderGateway.php` — modified;
    implements a deterministic, test-controlled equivalent of all three
    new methods, mirroring its own existing test-configuration pattern
    (§15a) — including recording each `confirmPaymentIntent()` call
    (idempotency key, payment method) so a test can assert a genuine
    second attempt actually occurred (§3 item 7k); every existing method
    is unchanged.
91. `app/Library/Usage/CheckoutSessionResult.php` — new DTO (§15a, §3
    items 7c, 7h–7i) — **corrected this refinement:** `$redirectUrl` is
    `?string` (nullable), and a new `?string $providerPaymentMethodId`
    field is added.

### Gateway tests (2 modified)

92. `tests/Feature/Usage/FakePaymentProviderGatewayTest.php` — modified;
    extended with Checkout Session create/retrieve coverage against the
    fake (§15a), including nullable-`redirectUrl` and
    `providerPaymentMethodId` assertions, and `confirmPaymentIntent()`
    call-recording coverage (§3 item 7k); every existing test is
    unchanged.
93. `tests/Feature/Usage/StripePaymentProviderGatewayCompatibilityTest.php`
    — modified; extended with Checkout Session create/retrieve coverage
    and the `amount`-vs-`amount_total` webhook normalization fix (§15b).
    **Corrected/strengthened this refinement (§3 items 7g–7k):** asserts
    the exact `line_items`/`price_data` request shape §15a locks — a
    Checkout Session create request built without the required
    `line_items` structure is asserted to fail this test; asserts
    `createCheckoutSession()` fails closed (throws) when the fake/mock
    response carries no `url`; asserts `retrieveCheckoutSession()` against
    a `complete` Session with `url: null` succeeds and resolves
    `providerPaymentMethodId`; asserts `confirmPaymentIntent()`'s own
    request shape (`payment_method`, `idempotency_key`); every existing
    test is unchanged.

### Payment-instrument reconciliation (2 modified — §15c, §3 item 7j)

94. `app/Library/Usage/PaymentInstrumentManager.php` — modified; adds
    `syncWorkspaceCheckoutPaymentMethod()` (§15c) — Workspace-owner-only,
    reuses the class's own existing repository/gateway calls verbatim, no
    schema change; every existing method (`resolveProviderCustomer()`,
    `createSetupIntent()`, `confirmSetupIntentAndAttach()`,
    `detachInstrument()`, `setDefaultInstrument()`) is unchanged.
95. `tests/Feature/Usage/PaymentInstrumentAttachDetachTest.php` —
    modified; extended with `syncWorkspaceCheckoutPaymentMethod()`
    coverage only (§15c) — owner-only consent, provider-customer-mismatch
    rejection, idempotent reuse of an already-attached instrument,
    creation of a genuinely new one, and it becoming the provider
    customer's default; no platform-admin bypass path exists to test, by
    construction. Every existing test in this file is unchanged.

**Explicitly not authorized by this allowlist:** any RFC-004 file (§7);
any change to `PaymentProviderGateway`, `StripePaymentProviderGateway`, or
`FakePaymentProviderGateway` **beyond exactly the Checkout Session
extension (including its corrected line-item shape and PaymentMethod
capture), `confirmPaymentIntent()`, and the webhook amount-fallback
widening items 88–90 define** — no other method, no behavior change to
SetupIntent/off-session-PaymentIntent flows; any change to
`PaymentInstrumentManager` beyond exactly
`syncWorkspaceCheckoutPaymentMethod()` (item 94) — no change to
`resolveProviderCustomer()`/`confirmSetupIntentAndAttach()`/
`detachInstrument()`/`setDefaultInstrument()`, no new schema column, no
platform-admin bypass of any kind; any change to `StripeWebhookController`
(§3 item 9) or `ReconcileProviderPendingState.php` (§3 item 10); any
add-on catalog seed data or migration data-insert (§10); any executable
refund trigger, job, manager method, or gateway call reaching
`refund_pending`/`refunded` (§8a — reserved schema states only); any
`business_billing_receipts`, `AdvanceUsagePeriodBoundaries`,
`SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`, or
`SendReceiptNotification` work (§3 items 16–17, a pre-existing M1–M3 gap);
a unified `Admin\UsageBillingController` (§3 item 14); any
`payment_lapsed` revocation logic (§4, §13); any scheduler interval or
attempt-count value other than the four values §13/§22 now lock exactly
(1 initial attempt + 2 retries = 3 total, never "3 retries"; 5-minute/
5-minute/hourly scheduling); any attempt-ordinal tracking mechanism other
than the exact §23 algorithm (no new column, no process-memory counter,
no unreliable inference from any column other than the count of `failed`
transitions).

---

## 26. Mechanical searches — reconciliation performed before finalizing §25

Performed, each finding folded into §3/§25 above, per the explicit
instruction to mechanically reconcile every M4 prose requirement against
the actual current repository before writing the final allowlist:

1. `grep -rn "updateCatalogPricing\|allocateAdditionalBusinessSlotsFromVerifiedPayment" app/` — confirmed exactly the two RFC-004 call sites this contract itself authorizes (§8, §9), no pre-existing caller anywhere else.
2. `grep -rn "app_subject_kind" app/` — found exactly one match
   (`ProcessPaymentProviderEvent.php`), confirming §15's widening is the
   *only* subject-kind-aware file in the entire codebase (§3 item 8).
3. `grep -rln "FundingAttemptPurpose::AddonPurchase" app/` — confirmed the
   enum case exists (M3-shipped) but is never referenced by any M3 code
   path, confirming M4 is genuinely the first consumer (§21).
4. `find app/Jobs/Usage -type f` / `find app/Events/Usage -type f` —
   confirmed the exact existing job/event inventory (§3 items 16–17),
   distinguishing genuinely-new-for-M4 from pre-existing-but-unbuilt
   M1–M3 scope.
5. `grep -n "schedule->job" app/Console/Kernel.php` — confirmed the exact
   scheduling convention and the two existing registrations M4's own
   three new ones sit alongside (§3 item 11).
6. `grep -n "Repository::class =>" app/Providers/AppServiceProvider.php`
   — confirmed the exact binding-array convention and location (§3 item
   12).
7. `grep -n "usage-billing" routes/customer.php` / targeted search of
   `routes/admin.php` — confirmed the existing route shape/convention and
   the complete absence of any admin Usage Billing controller (§3 items
   13–14).
8. Targeted search across `tests/` for any file already covering M4's own
   named concerns (subject-kind routing, source-boundary, lapse
   revocation) — confirmed none exists; every test in §25's "Additional
   M4-specific tests" group is genuinely new, not a duplicate of existing
   coverage.
9. `find app/Http/Requests -iname "*TopUp*"` — confirmed the exact
   Form-Request-per-action convention and its exact directory shape (§3
   item 15).

**This refinement's own bounded audit (Checkout/payment-instrument/retry
files only, per the explicit instruction — no broad repository audit, no
research agent):**

10. Direct read of `app/Library/Usage/Contracts/PaymentProviderGateway.php`,
    `StripePaymentProviderGateway.php`, `FakePaymentProviderGateway.php`,
    and `PaymentIntentResult.php` — confirmed no `line_items`-shaped
    Checkout Session request exists, no `confirmPaymentIntent()`-shaped
    method exists, and `verifyWebhookSignature()`'s exact prior code
    (§3 items 7g, 7k).
11. Direct read of `app/Library/Usage/PaymentInstrumentManager.php`,
    `app/Models/BusinessPaymentInstrument.php`, and
    `app/Repositories/Contracts/BusinessPaymentInstrumentRepository.php`
    — confirmed all existing methods are Business-scoped, confirmed the
    exact existing repository methods `syncWorkspaceCheckoutPaymentMethod()`
    reuses, confirmed no schema change is required (§3 item 7j, §15c).
12. Targeted re-read of RFC-005's own `additional_business_slot_renewal_charges`/
    `additional_business_slot_renewal_charge_transitions` schema (§18,
    already reproduced in this contract) — confirmed neither table has an
    attempt-counter, retry-ordinal, or lease column, and confirmed the
    `to_state = 'failed'`-count algorithm is derivable from the existing
    append-only transitions table alone (§3 item 7k, §23).
13. `grep -n "assertPlatformAdministrator" app/Library/Usage/UsageBillingCheckoutManager.php`
    — zero matches, confirming the helper does not yet exist; direct read
    of `UsageWalletManager::assertPlatformAdministrator()`'s exact
    existing shape as the precedent to mirror (§3 item 7l).
14. Direct read of `app/Library/Usage/UsageBillingCheckoutManager.php:297`
    (`retryFundingAttemptAsAdministrator()`) — confirmed it only calls
    `retrievePaymentIntent()`, never re-drives payment, confirming the
    prior draft's §14 claim that M4's own retry methods "mirror" this
    shape was incorrect for M4's own genuine-retry requirement (§3 item
    7k).

No repository-wide, unbounded search was performed at any point — every
search above was scoped to a specific M4 prose claim, matching the
explicit efficiency instruction.

---

## 27. Test contract

**Focused M4 tests** — every test in §25's test groups (67–87, 92–93,
95), run together as the first, narrowest gate.

**Complete regression** — `php artisan test tests/Unit/Usage
tests/Feature/Usage`, zero failures required, exact passing/assertion
count reported by that run, never assumed.

**Workspace/Entitlement regression** — `php artisan test
tests/Unit/Entitlement tests/Feature/Entitlement tests/Unit/Workspace
tests/Feature/Workspace`, zero failures required — the cross-RFC seam
(§8, §9) requires this exact regression, matching the identical
Amendment 1/Amendment 2 precedent of re-running RFC-004's own gates after
any change that calls into `EntitlementManager`, even though M4 itself
modifies no RFC-004 file.

**Full suite** — `php artisan test --stop-on-failure`, must exit `0`.

**Concurrency/idempotency** — §23's forced-race scenarios (test 84) —
**corrected this refinement: these are future tests that must pass
during implementation, not tests already confirmed passing** (no M4 test
exists yet; nothing has run).

**Mechanical source-boundary** — test 86 — **corrected this refinement:
must pass during implementation**, the direct proof for §8/§9's own
locked boundary claims once written and run.

**Before any of the above:** `.env.testing`'s `APP_NAME` and any branding
overrides confirmed neutral first (this session's own established
local-environment hazard, unrelated to M4's own code) if any Branding
test is included in a full-suite run; a real `.env` file confirmed
present in the implementation worktree (this session's own established
`AppConfig`/`file()`-read hazard, §3 gap unrelated to M4); built
frontend assets (`public/mix-manifest.json` and its referenced files)
confirmed present, restoring — never committing — them if a fresh
worktree lacks them (this session's own established, already-documented
local build-state hazard, identical to the one Amendment 1's own
Correction Round 1 first recorded).

---

## 28. Acceptance criteria and implementation sequence

Matching RFC-005 §37's own corrected framing exactly: M4's own conformance
document proves only the tests within M4's own authorized scope (§27)
pass — not the full aggregate RFC-005 §35 test set, which remains M6's own
exclusive responsibility. M4 introduces no feature-accessibility change
(RFC-005 §37's own M1–M4 invariant, unaffected by M4's own schema/manager
work). Implementation sequence: schema (§18) → enums/models/repositories
(§19–§20) → manager extension (§21) → jobs/events/scheduling (§22) →
webhook widening (§15) → HTTP surfaces (§17) → tests (§27), mirroring
M1–M3's own established bottom-up sequencing.

---

## 29. Stop conditions

Future implementation must stop, leave the working tree unstaged, and
report rather than proceed, if:

- Any path beyond §25's 95 is required — the **96th** path.
- Any RFC-004 file requires a change of any kind (§7).
- Any code path is found necessary to mutate an RFC-004 entitlement/
  catalog table directly, bypass `EntitlementManager`, or use a fake/
  synthetic administrator actor (§8).
- Any `payment_lapsed`-triggered Business-slot revocation is found
  necessary to satisfy a requirement believed to be M4 scope (§4, §13) —
  it is never in scope under this contract.
- Any `addon_key`, display name, retail price, or fulfillment product is
  found necessary to invent or hard-code anywhere (§10).
- Live Stripe credentials, a live-mode switch, or a live network call is
  needed for any step this contract authorizes.
- `PaymentProviderGateway`, `StripePaymentProviderGateway`, or
  `FakePaymentProviderGateway` is found to require any change beyond
  exactly the Checkout Session extension (line-item shape and
  PaymentMethod capture included), `confirmPaymentIntent()`, and the
  webhook amount-fallback widening §15a/§15b/items 88–90 authorize; or
  `StripeWebhookController` is found to require any change at all (§3
  item 9).
- `PaymentInstrumentManager` is found to require any change beyond
  exactly `syncWorkspaceCheckoutPaymentMethod()` (§15c, item 94) — or a
  schema change is found necessary to implement it (§3 item 7j locks that
  none is required; a future finding otherwise is itself a stop
  condition, not license to invent one).
- The attempt-ordinal derivation is found not to be reliably recoverable
  from the existing `additional_business_slot_renewal_charge_transitions`
  table by the exact §23 algorithm — a genuine need for a new
  schema column requires its own separate authorization, never a
  process-memory counter or an unreliable inference substituted quietly.
- Any executable refund trigger, job, gateway method, or manager method
  reaching `refund_pending`/`refunded` is found necessary — these remain
  reserved schema states only (§8a); a genuine need for one requires its
  own separate RFC-005 amendment/correction, not invention here.
- A different scheduler interval or attempt-count value than the four
  §13/§22 now lock exactly (1 initial + 2 retries = 3 total; 5-minute/
  5-minute/hourly scheduling) is found necessary — a change requires its
  own separate authorization.
- A unified, all-milestone `Admin\UsageBillingController` (or any M1–M3
  admin capability — balance view, credit issuance, billing-status
  toggle, webhook-event disposition) is found necessary to satisfy an M4
  requirement — a pre-existing gap, not M4's to fix (§3 item 14, §7).
- Any of the four §27 regression gates fails for a reason not fixable
  within the 95-path allowlist.
- `ultimatesms_testing` cannot be confirmed as the effective test
  database.
- An M5 (metered feature) or M6 (conformance/tag) concept becomes
  necessary to satisfy a requirement believed to be M4 scope.

---

## 30. Contract self-audit

1. **M4 test-mode and live-production readiness are separate, and the two
   M4-specific live gates are themselves independent of each other and of
   the four universal gates** — §4, corrected this refinement to resolve
   a structural contradiction: the additional-slot-agreement gate
   (`payment_lapsed` revocation, item 5) and the add-on gate (roster/
   pricing, item 6) no longer read as one unified "any unresolved item
   blocks everything" list; zero seeded add-ons is stated explicitly as a
   valid *production*, not merely test-mode, launch state.
2. **No live charging is authorized** — §0, §4, §7, §24, §29 each
   independently restate this.
3. **Both cross-RFC prerequisites are confirmed merged, by exact commit,
   not assumed** — §0.
4. **Every open decision is classified** — §5's table covers all 14
   RFC-005 items explicitly, none silently skipped.
5. **No financial/commercial default is invented** — §5 items 8/13, §10,
   §4.
6. **The allocation boundary is locked, with exact call-site
   requirements, exact parameter provenance, and exact state-machine
   discipline** — §8, independently testable via test 80.
7. **The catalog-pricing boundary is locked, with exact snapshot fields
   and an explicit immutability guarantee** — §9, independently testable
   via test 81.
8. **Add-ons ship as structure only, zero rows, zero invented content** —
   §10, independently testable via test 87.
9. **`payment_lapsed` implements every RFC-defined mechanism and zero
   revocation, made directly testable rather than merely asserted** — §13,
   test 85.
10. **Renewal/cancellation/proration/dunning match RFC-005 §22 exactly**
    — §11–§13, tests 74–78.
11. **Administrator authority is narrowed to exactly three resume/retry-
    shaped capabilities, no fresh origination** — §14, test 82.
12. **The one required M3 file widening is identified by direct code
    read, not assumed** — §3 item 8, §15, test 83 — the exact class of
    finding this contract's own mechanical-reconciliation step (§26) was
    required to surface before finalizing the allowlist.
13. **Every other M3 file M4 might plausibly need to touch was
    individually checked and confirmed to need no change, with the
    specific evidence recorded** — §3 items 7, 9, 10 (gateway/webhook
    controller/reconciliation job).
14. **Pre-existing M1–M3 scope gaps are named and explicitly excluded, not
    silently absorbed into M4's own scope** — §3 items 16–17, §7.
15. **Every path is individually allowlisted** — §25, numbered 1–95
    (items 88–93 appended in the prior refinement, items 94–95 appended
    this refinement — §26 items 10–14), the stop threshold is the
    required 96th path (§29).
16. **Counts match** — §25: 7 migrations + 6 enums + 4 DTOs + 2 exceptions
    + 7 models + 7 contracts + 7 Eloquent repositories + 1 modified
    manager + 4 new jobs + 1 modified job + 5 events + 2 controllers + 6
    requests + 3 views + 2 modified routes + 1 modified scheduler + 1
    modified DI + 7 schema tests + 6 RFC-named tests + 8 additional tests
    + 4 gateway paths (3 modified, 1 new DTO) + 2 modified gateway tests
    + 2 payment-instrument-reconciliation paths (1 modified, 1 modified
    test) = 95.
17. **Four regression gates are exact, sequential, and named precisely**
    — §27, matching the task's own required gate list exactly.
18. **Contract merge does not start implementation** — §0, stated
    explicitly.
19. **No production/test file is changed by this document** — confirmed
    by §31's own verification commands before staging (this document
    changes exactly one file).
20. **(This refinement) The quote/renewal price formula is corrected to
    price × ratio, never price alone** — §9 items 2/5, §18's schema
    unchanged (no new column — `ratio_snapshot` already existed, it was
    only unused by the formula), independently testable via test 81's own
    strengthened assertion.
21. **(This refinement) `app_subject_kind` is corrected to exactly the
    three RFC-005 §17.C canonical values** — §3 item 7, §6, §15; add-on
    purchases are confirmed to route through the existing
    `funding_attempt` machinery, distinguished only by their own
    persisted `purpose`, never a fourth subject kind — independently
    testable via test 83's own strengthened assertion, including an
    explicit probe that `addon_purchase` itself is rejected as
    unrecognized.
22. **(This refinement) `confirmSucceeded()`'s own pre-existing
    unconditional wallet-credit behavior for any non-`AutoRecharge`
    purpose is corrected before M4 can safely set `AddonPurchase`** — §21,
    §25 item 41 — authorized as an internal refactor to the
    already-allowlisted manager file, not a new path;
    `ManualTopUp`/`AutoRecharge`'s own observable behavior is explicitly
    preserved, only `AddonPurchase` gains new (previously unreachable)
    behavior. The pre-provider-call creation ordering (attempt row →
    purchase row → commit → provider call → persist reference) closes the
    webhook race the naive ordering would otherwise leave open.
23. **(This refinement) Administrator identity provenance is made
    explicit as two separate, non-conflated records** — §14: RFC-004's own
    transition always records `actor_user_id = null` regardless of who
    triggered the M4-side call (the method itself accepts no actor
    parameter, confirmed by direct re-read of its signature, §8 item 3);
    M4's own agreement-transition row is the one place the
    administrator's real identity is durably recorded — independently
    testable via test 82's own strengthened assertion.
24. **(This refinement) §4's live-readiness structure no longer
    contradicts §5's own per-item classification** — the additional-slot
    and add-on live gates are stated as independent, non-unified
    conditions; zero seeded add-ons is confirmed a valid *production*
    launch state, not merely a test-mode one; no add-on product is
    invented merely to appear "resolved."
25. **(Superseded by item 26 below — a later refinement genuinely
    required new paths.)** The implementation allowlist count is unchanged
    at 87 — every correction above was satisfied entirely through prose
    changes to already-allowlisted paths (the manager file, §25 item 41;
    the webhook job, §25 item 46; existing test descriptions, §25 items
    81–83) — no path was added, removed, or renumbered (§25, self-audit
    item 16 recomputed and reconfirmed unchanged below).
26. **(This refinement) The gateway boundary is corrected honestly — the
    prior "zero changes" claim is withdrawn, not preserved.** §3 items
    7a–7c found a genuine Checkout Session gap; §15a authorizes the exact,
    narrow extension; §25 items 88–93 add exactly six new/modified paths
    (three gateway files, one new DTO, two gateway tests); the count is
    honestly recomputed to 93, not forced to remain 87, per the explicit
    instruction that 87 is no longer a number to preserve.
27. **(This refinement) Every controller/job mutation named in §11–§17 now
    has a corresponding named `UsageBillingCheckoutManager` method** — §3
    item 7d's audit found four missing entirely
    (`createScheduledRenewalCharge()`, `requestSlotAgreementIncrease()`,
    `retrySlotRenewalAsOwner()`, `finalizeSlotAgreementCancellation()`);
    all four are added to §21, item 41's own allowlist description
    updated to the corrected total of thirteen public methods — no new
    manager path, exactly as instructed.
28. **(This refinement) No system path uses a fake/synthetic administrator
    id anywhere.** §3 item 7e's own finding (the prior draft's
    `ReconcileSlotAgreementAllocation` description implied a "system-actor"
    call into an administrator-shaped method) is corrected: one shared
    `performVerifiedAllocation()` routine (§8 item 6, §21) is called with
    `$administratorActorUserId: null` by both the ordinary payment path
    and reconciliation, and with the real administrator id only by the
    genuine administrator action — independently testable via tests 80/84's
    own strengthened assertions.
29. **(This refinement) The add-on success-replay hole is closed, with the
    exact scenario named in the requirement made directly testable** —
    §21's widened `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`
    early-return guards, test 79's own strengthened assertion;
    `ManualTopUp`/`AutoRecharge`'s own already-`Succeeded` no-op behavior
    is explicitly confirmed unchanged, both in §21's own prose and in
    test 79 itself.
30. **(This refinement) `change_operation_id` is genuinely retry-stable —
    generated once, client-side, by the increase view/form itself, never
    by the manager or controller** — §11, §17; test 76's own strengthened
    assertion proves the manager never regenerates a caller-supplied
    value.
31. **(Superseded by item 37 below — "3 retries" was itself an ambiguous
    phrasing this later refinement corrected to "3 total attempts.")**
    Retry count and scheduler intervals are locked to exact values, no
    longer implementation-time choices — §13 (3 retries), §22 (5 min / 5
    min / hourly) — test 77's own strengthened assertion proves exactly
    3, not merely "bounded."
32. **(This refinement) Refund states are honestly disclosed as reserved,
    not executable** — §8a, added after a targeted re-read of RFC-005
    §22–§23 found no executable trigger, job, gateway call, or manager
    method for `refund_pending`/`refunded` anywhere in the RFC; no refund
    policy is invented; the prior draft's "Refund paths ... remain their
    own distinct states" phrasing (§8 item 5) is corrected to avoid
    implying an executable path this contract does not define.
33. **(This refinement) The Checkout Session request is now genuinely
    Stripe-valid** — §3 item 7g, §15a: `mode: 'payment'` carries exactly
    one non-adjustable `line_items` entry built from inline `price_data`,
    never a separately-created Stripe Price/Product; `unit_amount` derives
    only from the frozen agreement amount; test 93's own strengthened
    assertion proves a request missing `line_items` fails.
34. **(This refinement) `CheckoutSessionResult`'s nullability and
    actual-payment-method capture are corrected** — §3 items 7h–7i, §15a:
    `redirectUrl` is `?string` (a completed Session legitimately has none);
    `createCheckoutSession()` still fails closed if a newly-created
    Session lacks one; a new `providerPaymentMethodId` field is resolved
    on retrieve of a completed Session, never assumed to be the locally-
    saved default. Eight exact conditions (§15a) gate "verified" — status,
    payment_status, session id, amount, currency, provider customer,
    PaymentIntent reference, PaymentMethod reference — independently
    testable via test 93's own strengthened assertions; the webhook path
    additionally requires `payment_status === 'paid'` explicitly (§15a,
    §21).
35. **(This refinement) The actual Checkout-selected card is reconciled
    into `business_payment_instruments`, never left mismatched with the
    agreement's own display snapshot** — §3 item 7j, §8 item 7, §15c: a
    new, narrowly-scoped `PaymentInstrumentManager::syncWorkspaceCheckoutPaymentMethod()`
    reuses 100% of the class's own existing repository/gateway calls, no
    schema change, no other M3 payment-instrument path touched, Workspace
    owner only, no admin bypass — independently testable via test 80's own
    strengthened assertion that the frozen snapshot matches the card
    actually charged, and test 95's own dedicated coverage.
36. **(This refinement) A renewal retry now genuinely re-drives payment**
    — §3 item 7k, §13, §15a, §21, §23: the new `confirmPaymentIntent()`
    gateway method re-confirms the existing PaymentIntent against the
    Workspace's current default instrument; M3's own
    `retryFundingAttemptAsAdministrator()` is confirmed unmodified; the
    exact deterministic attempt-ordinal algorithm (1 + count of `failed`
    transitions for that charge, no new schema column, crash-safe by
    construction) is locked in §23 — independently testable via test 77's
    own strengthened assertion that a genuine second provider call
    occurs.
37. **(This refinement) "3 attempts" is now unambiguous — 1 initial + 2
    retries, never "3 retries"** — §13, superseding item 31 above; failure
    of attempt 3 causes `payment_lapsed`, enforced by the ordinal
    computation itself (§23), not merely by caller discipline.
38. **(This refinement) Every M4 "AsAdministrator"-named manager method
    independently verifies real platform-administrator identity** — §3
    item 7l, §14, §21: a new private `assertPlatformAdministrator()`
    helper (mirroring `UsageWalletManager`'s own existing shape) is called
    by `retrySlotRenewalAsAdministrator()`, `allocateSlotAgreementAsAdministrator()`,
    and the administrator branch of `requestSlotAgreementCancellation()`,
    before the mandatory-reason check and before any mutation — never
    relying solely on route/middleware placement; independently testable
    via test 82's own strengthened assertion (direct manager invocation,
    HTTP layer bypassed).
39. **(This refinement) Test #86's own description is corrected from an
    impossible repository-wide absence claim to the actual, narrower
    M4-scoped source-boundary invariant** — §3 item 7m, §25 item 86; §27's
    own "independently confirmed passing" language for tests 84/86 is
    corrected to "must pass during implementation" throughout, since no
    M4 test exists yet and nothing has actually run.
40. **(This refinement) The allowlist count is honestly recomputed to 95,
    stop threshold to 96** — §25, §26 items 10–14, §29: items 94–95 (the
    `PaymentInstrumentManager` reconciliation fix, §3 item 7j) are the
    only genuinely new paths this refinement requires; the Checkout
    Session/`confirmPaymentIntent()`/admin-check corrections (§3 items
    7g–7l, 7k) are absorbed entirely by prose changes to already-
    allowlisted paths (items 88–90/93, 41) — confirmed by direct
    re-audit, not assumed.

---

## 31. Verification and publication (this document only)

- `git diff --check` — clean.
- `git status --short` — exactly
  `M docs/automation/RFC-005-M4-CONTRACT.md` (this is the fourth commit
  to this Draft contract on this branch — the file is already tracked
  from the initial draft and two prior refinements; it is not untracked).
- `git diff --name-only` — exactly one path:
  `docs/automation/RFC-005-M4-CONTRACT.md`.
- `git diff --cached --name-only` — empty before staging.
- Stage the one file by its exact path only (never `git add -A`/
  `git add .`).
- Commit with a descriptive message identifying this as the third
  in-place refinement (Checkout Session line-item/PaymentMethod-capture
  correction, payment-instrument reconciliation, genuine renewal-retry
  mechanism with a deterministic attempt-ordinal algorithm, explicit
  in-manager administrator checks, corrected source-boundary test
  description) — not the generic message prior commits used, which
  described only their own prior scope.
- Push normally to `origin chore/rfc-005-m4-contract` (the existing PR
  #103 branch). No force push. Do not push `main`.
- This is a Draft contract refinement, not a merge event — PR #103
  remains open and unmerged after this push.
- PHP/JS tests are not required for this one-file docs-only change and
  are not run — reported honestly as not run, no count fabricated.

---

*End of RFC-005 Milestone 4 contract. This document authorizes drafting
itself only. The implementation it specifies requires its own separate,
later, explicitly bounded implementation PR (§18–§27), after which the
full §27 gate set must be run in full. RFC-005's own design document,
RFC-004's own tagged foundation and both its merged amendments, and every
prior merged M1–M3 contract are unmoved and unmodified by this document.*
