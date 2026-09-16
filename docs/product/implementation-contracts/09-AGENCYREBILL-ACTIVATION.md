# Implementation Contract 09 — AgencyRebill Activation

**Status:** Implementation contract, **corrected at implementation time**
(see "Implementation-time correction" below). Depends on Contract 01 **and
Contract 05** (both hard prerequisites, §16), both merged to `main`.

## Implementation-time correction (authoritative)

The first implementation attempt stopped before writing code: a mechanical
re-inventory of `main` at `00377d7f` found payer-resolution and payer-
authority branches this contract's original §3 did not list, one
delegation target that did not exist, and one assumption about spend-time
access checks that was false. The corrections below are approved and
supersede the original text wherever they differ:

1. **Missed sites.** Beyond the four sites originally named, `main`
   contained: `PaymentInstrumentManager::resolveProviderCustomer()` (a second
   binary provider-customer resolver that also lazily *creates* provider
   customers); `UsageWalletManager::assertChargeCausingConsentForAutoRecharge()`
   (a **third** consent copy, which let the **client** Business owner
   configure Agency-funded automatic top-up); `BillingProfileManager::
   actorManagesPayerControls()`, `billingResponsibilityFor()` and
   `isCurrentPayer()` (binary payer authority/presentation facts);
   `UsageWalletManager::reserve()`'s Workspace controls lock and its
   `isWorkspacePaid()` aggregate-cap consumer (not in the original five);
   `UsageBillingCheckoutManager::initiateAutoRecharge()`'s own direct
   `payer_type` read; `UsageBillingPresenter::resolvePaymentMethod()`; and
   the payer labels in `WorkspaceController` and `UsageBillingController`.
2. **Non-existent delegation target.** `BillingProfileManager` has no
   `assertChargeCausingConsent()`; `PaymentInstrumentManager`'s copy had
   three callers, not one.
3. **False spend-time assumption.** No money path consulted
   `CustomerAccountAccessResolver` — only HTTP middleware/controllers did.
   Automated effects (the auto-recharge job, background `reserve()` calls)
   never checked account access. A spend-time access gate is therefore a
   required **change** in this slice, not an existing check.
4. **Removed statement.** The original claim that the underlying wallet/
   cap/access checks need no change is withdrawn: payer-sensitive checks
   (Workspace aggregate controls, the new access gate, the consent gate)
   must change, as §5/§11 specify. The payer-independent checks (Business
   cap, feature limits, Business pause, platform safety limit, balance,
   debt, suspension, idempotency, provider readiness) are unchanged.

## 1. Objective

Activate `PayerType::AgencyRebill` under the exact consent/authority rules
Addendum §10 locks, with **one canonical answer to "who pays"**
(`EffectivePayerResolver`) and **one canonical human payer/funding
authority seam** (`BillingProfileManager`), consumed by every money
manager — never a parallel or duplicated branch.

## 2. Governing authority

- Addendum §10 (the full AgencyRebill rule set), §17 (RFC-005 §16 status update).
- Blueprint §20, §28.
- Roadmap Slice 9.
- Contract 01 (the relationship AgencyRebill resolves through).
- Contract 03/05 (the effective account access every Agency-funded paid effect requires).

## 3. Current repository reality (re-inventoried at `00377d7f`)

`git grep "PayerType::" -- app` returns exactly ten files. The complete
payer-branch inventory, every site read in full:

**Payer resolution — which provider customer / instrument pays**
1. `UsageBillingCheckoutManager::initiateCharge()` — binary ternary:
   `Workspace` → the Business's own Workspace provider customer; anything
   else, **including `AgencyRebill`**, → the **client Business's own**
   provider customer and default instrument. The dangerous site.
2. `UsageBillingCheckoutManager::initiateAutoRecharge()` — reads
   `payer_type` directly and passes it into site 1.
3. `PaymentInstrumentManager::resolveProviderCustomer()` — the same binary
   shape for SetupIntent creation, attach, detach and set-default, and it
   lazily **creates** the provider customer it resolves.
4. `UsageBillingPresenter::resolvePaymentMethod()` — display-only `match`
   (`business`/`workspace`, default `null`).

**Payer authority — who may consent / configure / control**
5. `UsageBillingCheckoutManager::assertChargeCausingConsent()` — top-up and
   add-on consent (`Workspace` → Workspace owner, `Business` → direct owner,
   otherwise refuse).
6. `PaymentInstrumentManager::assertChargeCausingConsent()` — identical copy,
   three callers.
7. `UsageWalletManager::assertChargeCausingConsentForAutoRecharge()` — a
   third copy, **binary**: anything other than `Workspace` → the client
   Business's direct owner. Used by `configureAutoRecharge()`.
8. `BillingProfileManager::actorManagesPayerControls()` — `Business` →
   direct owner; everything else → `isAgencyWideManager()` (the **client**
   Workspace). Governs `setSpendCap()`, `setFeatureLimit()`, pause/resume.
9. `BillingProfileManager::billingResponsibilityFor()` / `isCurrentPayer()`
   — binary presentation/authority facts.
10. `BillingProfileManager::assignPayer()` / `assertBillingResponsibilityAuthority()`
    — both reject every payer type outside `{Business, Workspace}`.
    `isAgencyWideManager()` checks the Business's **own** Workspace, which
    under V1 is the Client Workspace, so it can never express Agency
    authority.

**Payer-sensitive spend controls**
11. `UsageWalletManager::isWorkspacePaid()` — binary, consumed by
    `reserve()`'s Workspace aggregate spend cap and by
    `autoRechargeCeilingAdmission()`. `reserve()` also locks and applies the
    Business's own Workspace controls row (pause) for every payer.
    `evaluateAutoRechargeAdmission()` applies the Workspace aggregate
    recharge ceiling only for `Workspace`.

**Presentation labels**
12. `WorkspaceController::billingResponsibilityViewData()` — `workspace` →
    "agency", everything else → "client".
13. `UsageBillingController::responsibilityMessageKey()` — the same binary
    for flash messages (its input is request-limited to Business/Workspace).
14. `resources/views/customer/business/usage-billing/show.blade.php` — two
    `payer_type === 'workspace'` label branches.

**Non-resolution references (unchanged, justified in §5):** the `PayerType`
casts on `BusinessPayerAssignment`, `BusinessPayerTransition`,
`BusinessFundingAttempt`, `AdditionalBusinessSlotRenewalCharge`; the
historical `payer_type_snapshot` values; the Workspace-scoped additional-
slot agreement flows (`payer_type_snapshot = workspace` by construction);
`BillingProfileManager::initializePayerAssignmentForBusiness()`'s default;
`UpdateBusinessPayerRequest` (accepts only `business`/`workspace`).

**Schema.** `business_payer_assignments`: `business_id` (unique FK),
`payer_type` string(16), `effective_payment_instrument_id` (nullable), no
column recording which Agency pays. `business_payer_transitions`: from/to
payer type, from/to instrument, actor, reason, `created_at` — no column
recording the relationship or a consent change.

**Consent precedent.** `business_usage_wallets.auto_recharge_consented_at`/
`_by_user_id` are written by `configureAutoRecharge()` (who enabled it, when)
and cleared on disable. They are not read at charge time;
`auto_recharge_enabled` is the effective gate.

**Account access.** Only HTTP middleware/controllers consume
`CustomerAccountAccessResolver`. No money path does.

## 4. Delta from current state to target

- `business_payer_assignments` gains `managing_agency_relationship_id`,
  `agency_rebill_consented_at`, `agency_rebill_consented_by_user_id`;
  `business_payer_transitions` gains `managing_agency_relationship_id` and
  `agency_rebill_consent` (§5).
- `EffectivePayer` is extended; new `EffectivePayerResolver` is the single
  place `payer_type` is interpreted for provider customer, instrument,
  spend-control scope and Agency-funded spend admission.
- `PayerType` gains two exhaustive semantic methods,
  `isGovernedByOwnWorkspaceControls()` and
  `countsTowardOwnWorkspaceAggregateLimits()` (§5.4).
- `BillingProfileManager` becomes the single human payer/funding authority
  seam: AgencyRebill assignment + standing consent grant, consent
  revocation, funding authority, charge-origination authority, and the
  tri-state `actorManagesPayerControls()`/`billingResponsibilityFor()`/
  `isCurrentPayer()`. `isAgencyWideManager()` is unchanged.
- Sites 1, 2, 3 resolve through `EffectivePayerResolver`; sites 5, 6, 7 are
  **deleted** and replaced by `BillingProfileManager`'s authority methods.
- `UsageWalletManager::isWorkspacePaid()` is **deleted**; its consumers use
  the resolver and the two `PayerType` scope methods (§5.4).
- New spend-time AgencyRebill gate (standing consent + effective account
  access) at `UsageWalletManager::reserve()` and
  `UsageBillingCheckoutManager::initiateCharge()`, plus the auto-recharge
  pre-admission.
- Sites 4, 12, 13, 14 present AgencyRebill truthfully.
- RFC-005 §16 gains the AgencyRebill consent rows.

## 5. Data model and canonical payer resolution

### 5.1 Schema — migration `2026_09_20_100011_add_managing_agency_relationship_id_to_business_payer_assignments_table.php`

`business_payer_assignments`:

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `managing_agency_relationship_id` | `unsignedBigInteger`, FK → `agency_client_workspace_relationships.id`, `restrictOnDelete()` | Yes | `NULL` for `business`/`workspace`. **Required** for `agency_rebill`: an Active relationship whose `client_workspace_id` is this Business's own Workspace. Written only by `BillingProfileManager::assignPayer()` from the server-resolved relationship — never from request input. |
| `agency_rebill_consented_at` | `timestamp` | Yes | Standing consent. Set to `now()` by the managing Agency owner's `assignPayer(… AgencyRebill …)`; cleared by `revokeAgencyRebillConsent()`; `NULL` for other payer types. **No timestamp = no standing consent.** Never fabricated by migration or setup code. |
| `agency_rebill_consented_by_user_id` | `unsignedBigInteger` | Yes | The real consenting user (no FK, matching `auto_recharge_consented_by_user_id`). |

`business_payer_transitions` (the existing audit, reused — no parallel audit):

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `managing_agency_relationship_id` | `unsignedBigInteger`, FK → relationships, `restrictOnDelete()` | Yes | The relationship an AgencyRebill grant/revoke/assignment concerned. |
| `agency_rebill_consent` | `string(16)` | Yes | `granted` / `revoked` for consent changes; `NULL` otherwise. |

No backfill: no row can hold `agency_rebill` today.

### 5.2 `EffectivePayer`

```php
final readonly class EffectivePayer
{
    public function __construct(
        public PayerType $payerType,
        public int $businessId,
        public ?int $effectivePaymentInstrumentId,
        public ?int $providerCustomerWorkspaceId,   // Workspace: Business's own Workspace; AgencyRebill: relationship.agency_workspace_id
        public ?int $providerCustomerBusinessId,    // Business only
        public ?int $managingAgencyRelationshipId = null,  // AgencyRebill only
        public ?CarbonInterface $agencyRebillConsentedAt = null, // AgencyRebill only
    ) {}
}
```

Exactly one of `providerCustomerWorkspaceId`/`providerCustomerBusinessId` is
non-null for every resolved payer.

### 5.3 `EffectivePayerResolver`

- `resolve(Business): EffectivePayer` — plain reads. Missing assignment →
  `Workspace` (existing default). `Workspace` → own Workspace provider
  customer. `Business` → the Business's provider customer. `AgencyRebill` →
  **defense-in-depth revalidation**: relationship id present, relationship
  exists, is Active, targets this Business's own Workspace, names a
  different Workspace as the Agency, and that Agency Workspace **currently**
  holds Agency-tier entitlement (`EntitlementManager`, resolved lazily —
  Contract 01 §6: an Active relationship row is never proof of it);
  otherwise throw `AgencyRebillRelationshipInvalidException`. Provider
  customer = `relationship.agency_workspace_id`. **Never** falls back to the
  client. Because every §6 authority and every paid effect resolves through
  here, a downgraded Agency fails closed everywhere: nothing is charged and
  nobody holds the payer's funding, controls or charge authority.
- `resolveForPaidEffect(Business): EffectivePayer` — for use inside a
  paid-effect transaction after its wallet lock: reads the assignment with a
  **locking read for every payer type**, and for `AgencyRebill` the
  relationship too, so a revocation/termination committed before the lock
  can never authorize the effect. The assignment read must never be a plain
  consistent read: under REPEATABLE READ that would fix the transaction
  snapshot before `reserve()` / `claimAutoRechargeAdmissionUnderLock()` lock
  the Workspace controls row, and the Workspace aggregate spend-cap and
  recharge-ceiling sums would miss concurrently committed reservations and
  attempts (implementation review correction).
- `paidEffectRefusal(Business, EffectivePayer): ?string` — `null` for
  `Business`/`Workspace` (no change). For `AgencyRebill`: missing standing
  consent → `agency_rebill_consent_missing`;
  `CustomerAccountAccessResolver::resolve($business->workspace)` locked →
  `agency_rebill_account_access_locked`. One `resolve()` covers Client
  Locked/Inactive/Suspended and, through Contract 05's composition, Agency
  Locked/Inactive/Suspended; Grace stays usable. The Client/Agency states
  are **not** recomputed here.

### 5.4 Spend-control scope — two exhaustive `PayerType` methods

- `isGovernedByOwnWorkspaceControls()`: `Business`, `Workspace` → `true`;
  `AgencyRebill` → `false`. Whether the Business's own Workspace controls row
  is locked at spend time and its Workspace-wide pause applies.
- `countsTowardOwnWorkspaceAggregateLimits()`: `Workspace` → `true`;
  `Business`, `AgencyRebill` → `false`. Whether spend and automatic top-ups
  count toward that Workspace's aggregate spend cap and recharge ceiling.

Together:

- `Workspace`: unchanged — own Workspace aggregate cap, pause and recharge
  ceiling apply.
- `Business`: unchanged — no aggregate cap/recharge ceiling; the Workspace
  pause still applies as today.
- `AgencyRebill`: the **Client** Workspace's aggregate cap, pause and
  recharge ceiling do **not** apply, and **no** Agency-wide cross-client
  aggregation is created. The Client Business wallet's own Business spend
  cap, feature limits, Business pause, per-Business recharge cap/policy and
  non-negative balance **always** apply.

Agency-wide cross-client ceilings are not part of this contract.

## 6. Authority / security contract

### 6.1 Money-authority matrix

| Actor | Set `business`/`workspace` | Grant AgencyRebill (assign + standing consent) | Revoke AgencyRebill consent | Funding configuration (instruments, auto-recharge) while AgencyRebill | Payer controls (Business cap, feature limits, Business pause) while AgencyRebill | Originate an Agency-funded charge |
|---|---|---|---|---|---|---|
| Managing Agency Workspace owner | Existing rule, unchanged (only if the Business's own Workspace is Agency-tier) | **Yes** | **Yes** | **Yes** | **Yes** | **Yes, with standing consent** |
| Managing Agency Admin / Staff | Existing rule, unchanged | No | No | No | No | No |
| Client Workspace owner / staff, client Business owner | Existing rule, unchanged | No | No | No | No | No |
| Platform Owner / Administrator | No | No | No | No | No | No |
| Owner of an unrelated Agency Workspace | — | No (relationship resolved first) | No | No | No | No |

Business and Workspace payer authority is byte-for-byte preserved:
funding/charge authority = Workspace owner (Workspace payer) or direct
Business owner (Business payer); payer controls = `isAgencyWideManager()`
(Workspace payer) or direct Business owner (Business payer).

View As grants no consent authority: every method takes the real acting
user id; nothing resolves authority from an impersonated context.

### 6.2 `BillingProfileManager` — the single human authority seam

- `assignPayer(Business, PayerType, int $actorUserId, string $reason)` —
  both guards accept `AgencyRebill`. `AgencyRebill` routes through
  `isManagingAgencyOwner()` (resolve the Active relationship for the
  Business's own Workspace **first**, then require that relationship's
  Agency Workspace owner, which must currently be Agency-tier — Contract 01
  requires Agency-only capabilities to re-check entitlement). Inside the
  existing assignment row lock, the relationship is **re-read with a locking
  read** immediately before the write (§7). The write sets `payer_type`,
  `managing_agency_relationship_id`, `agency_rebill_consented_at = now()`,
  `agency_rebill_consented_by_user_id = actor`, records a transition with
  the relationship and `agency_rebill_consent = granted`, and dispatches
  `BusinessPayerChanged`. Re-granting the same relationship while consent is
  present is a true no-op; re-granting after revocation writes a new grant.
  Switching **away** from AgencyRebill uses the unchanged Business/Workspace
  rule and clears all three AgencyRebill columns.
- `revokeAgencyRebillConsent(Business, int $actorUserId, string $reason)` —
  managing Agency owner of the **recorded** (still valid) relationship only.
  Clears the two consent columns, keeps `payer_type = agency_rebill` and the
  relationship (no silent client fallback), records a transition with
  `agency_rebill_consent = revoked`. Revoking already-revoked consent is an
  authorized no-op.
- `authorizedFundingPayer(Business, int): ?EffectivePayer` — funding
  configuration authority (instrument setup/attach/detach/default,
  auto-recharge configuration). AgencyRebill: owner of the recorded
  relationship's Agency Workspace; **no consent required**, so the Agency
  can attach its funding instrument before any charge, and locked account
  access does not block configuration.
- `authorizedChargePayer(Business, int): ?EffectivePayer` — funding
  authority **plus**, for AgencyRebill, standing consent. Used by top-up and
  add-on purchase.
- `actorManagesPayerControls()` — tri-state per §6.1.
- `billingResponsibilityFor()`, `isCurrentPayer()` — tri-state; the facts
  gain `who_pays` (`agency`/`business`).

Callers keep their existing exception types
(`UnauthorizedPayerAssignmentException` for top-up/add-on/instruments,
`UnauthorizedUsageBillingManagementException` for auto-recharge and payer
controls).

## 7. Transaction / concurrency boundary

- `assignPayer()`/`revokeAgencyRebillConsent()`: the existing assignment row
  lock (`findForUpdateByBusinessId`), then a locking read of the
  relationship row before writing. Lock order assignment → relationship; no
  path locks the reverse.
- `reserve()`: wallet row lock first (unchanged); the assignment is then
  read with a locking read for every payer type (`resolveForPaidEffect`) —
  never a plain read before the Workspace controls lock — and for
  AgencyRebill the relationship too, with the consent/access gate under
  those locks; the Client Workspace controls row is not locked for
  AgencyRebill. Lock order wallet → assignment → (Workspace controls |
  relationship); payer changes lock only assignment → relationship and no
  listener takes a wallet or controls lock, so no cycle exists.
- `initiateCharge()`: provider customer is resolved before the transaction
  (unchanged shape); inside the wallet-locked transaction that creates the
  attempt, the payer is re-resolved for the paid effect and the attempt is
  refused (`payer_changed`) if the funding source changed, or if consent/
  access now refuses; for an actor-originated AgencyRebill charge the
  owner authority is re-checked there too. Provider I/O stays outside every
  transaction. An attempt created before a revocation commits is already the
  durable claim; revocation stops **new** effects.

## 8. Migration / backfill

None beyond §5.1's additive nullable columns. `agency_rebill` has never
been assignable, so every existing row keeps `NULL`.

## 9. Backwards compatibility

Every `business`/`workspace` behavior is preserved: authority rules,
provider customer and instrument selection, Workspace aggregate controls
and pause, auto-recharge admission. `isAgencyWideManager()` is untouched.
`UpdateBusinessPayerRequest` still rejects `agency_rebill` — AgencyRebill is
never selectable through the existing Client/legacy payer selector.

## 10. Events / audit

`business_payer_transitions` (with the two §5.1 columns) and
`BusinessPayerChanged` are reused. Every grant/revoke records the real
actor, timestamp, relationship, consent change and mandatory reason.
Historical ledger, funding attempts, receipts and transitions are never
rewritten by revocation or relationship termination.

## 11. Billing / provider safety and the spend-time access gate

For `payer_type = agency_rebill`, every new paid effect requires, in order:
a valid relationship (fail closed), standing consent, and usable effective
account access via `CustomerAccountAccessResolver::resolve($clientBusiness->workspace)`
— enforced at `UsageWalletManager::reserve()`,
`UsageBillingCheckoutManager::initiateCharge()` (and the auto-recharge
pre-admission), in addition to every existing Business-wallet check
(Business cap, feature limits, Business pause, platform safety limit,
balance, debt, suspension, idempotency, provider readiness), which apply
unchanged. The provider customer is always
`relationship.agency_workspace_id`'s Workspace provider customer — never
`business.id`, never `business.workspace_id`, never a guessed or requested
Workspace. A missing Agency provider customer or instrument fails closed.
Read/display paths are never gated.

Consent refusals and account-access refusals are **policy refusals** for
automatic top-up (they never count as payment failures).

## 12. Exact implementation allowlist

**New files:**
- `database/migrations/2026_09_20_100011_add_managing_agency_relationship_id_to_business_payer_assignments_table.php`
- `app/Library/Usage/EffectivePayerResolver.php`
- `app/Exceptions/Usage/AgencyRebillRelationshipInvalidException.php`
- `tests/Feature/Usage/EffectivePayerResolverTest.php`
- `tests/Feature/Usage/AgencyRebillAuthorityTest.php`
- `tests/Feature/Usage/AgencyRebillPaidEffectTest.php`
- `tests/Feature/Usage/AgencyRebillPresentationTest.php`
- `tests/Feature/Usage/Concerns/AgencyRebillFixtures.php` — shared fixtures for the four files above.

**Existing files modified:**
- `app/Enums/Usage/PayerType.php` — `isGovernedByOwnWorkspaceControls()`, `countsTowardOwnWorkspaceAggregateLimits()`.
- `app/Library/Usage/EffectivePayer.php` — §5.2 fields.
- `app/Library/Usage/BillingProfileManager.php` — §6.2.
- `app/Library/Usage/UsageBillingCheckoutManager.php` — sites 1, 2, 5; spend gate.
- `app/Library/Usage/PaymentInstrumentManager.php` — sites 3, 6.
- `app/Library/Usage/UsageWalletManager.php` — sites 7, 11; spend gate; refusal reasons.
- `app/Library/Usage/UsageBillingPresenter.php` — site 4.
- `app/Models/BusinessPayerAssignment.php`, `app/Models/BusinessPayerTransition.php` — new columns.
- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php` — site 12.
- `app/Http/Controllers/Customer/Business/UsageBillingController.php` — site 13.
- `resources/views/customer/business/usage-billing/show.blade.php` — site 14.
- `resources/views/customer/workspaces/show.blade.php` — AgencyRebill shown read-only, never as a selector option.
- `resources/lang/en/locale.php` — AgencyRebill responsibility strings.
- `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` — §16 AgencyRebill consent rows.
- Existing tests whose asserted behavior this slice deliberately changes are
  corrected in place, never deleted: `tests/Feature/Usage/PayerConsentAuthorizationTest.php`
  (`test_agency_rebill_is_never_a_valid_target` becomes
  `test_agency_rebill_is_refused_without_a_managing_agency_relationship` —
  same assertion, corrected premise).

## 13. Required tests

- **`EffectivePayerResolverTest`** — all three payer types' provider-customer
  fields; AgencyRebill fails closed for missing id, terminated relationship,
  relationship targeting another Client Workspace, and a forged/mismatched
  id; locking re-resolution; `paidEffectRefusal()` for missing consent and
  each Client/Agency Locked/Inactive/Suspended state, with Agency Grace
  usable.
- **`AgencyRebillAuthorityTest`** — every §6.1 row (grant, revoke, funding
  configuration, payer controls, charge origination) including Agency
  Admin/Staff, client owner/staff, platform admin and unrelated Agency owner;
  relationship resolved before ownership; mid-transaction termination
  refused; Agency-tier re-check at grant **and** after a later downgrade
  (no funding, controls or charge authority); idempotent grant/revoke; audit rows with
  relationship, actor, consent change and reason; switching away clears the
  AgencyRebill columns; Business/Workspace authority regressions.
- **`AgencyRebillPaidEffectTest`** — the §3 site 1 regression (Client and
  Agency provider customers both exist → Agency charged); Agency instrument
  missing with a Client instrument present → fail closed; Agency owner
  SetupIntent/attach/detach/default against the Agency Workspace provider
  customer and refusal for everyone else; a client instrument can never be
  detached/defaulted through the Agency payer; auto-recharge configuration
  authority; reserve and charge refused for missing/revoked consent, each
  locked access state, terminated/mismatched relationship; Agency Grace
  proceeds; Business cap/feature limit/Business pause still apply; Client
  Workspace aggregate cap/pause/recharge ceiling do not apply to AgencyRebill;
  Workspace payer aggregate controls unchanged; auto-recharge Business
  ceiling applies with no cross-client aggregation; a stale pre-revocation
  payer cannot authorize a charge under the lock; consent/access refusals are
  auto-recharge policy refusals, not payment failures; a downgraded Agency
  stops funding at both boundaries; a Workspace-paid reservation reads the
  assignment only with a locking read before the Workspace controls lock.
- **`AgencyRebillPresentationTest`** — presenter shows the Agency's default
  instrument; responsibility facts, the Usage & Billing page and the Agency
  account frame never label AgencyRebill as client-paid and never offer it
  in the legacy selector.
- Mutation-style proof: reverting any AgencyRebill provider-customer
  selection to the old binary makes the regression tests fail.
- Every existing Billing/Wallet/Checkout/Instrument/payer/account-access test
  re-run.

## 14. Acceptance criteria

1. Every §6.1 row passes.
2. `isAgencyWideManager()` diff is empty.
3. Mid-write relationship termination blocks the assignment (§7).
4. `EffectivePayerResolver` is the only place `payer_type` decides provider
   customer, instrument, spend-control scope or Agency-funded admission, and
   `BillingProfileManager` is the only human payer/funding authority:
   `isWorkspacePaid()` and all three `assertChargeCausingConsent*()` copies
   no longer exist, and `initiateCharge()`'s ternary is gone.
5. The site 1 regression proves the Agency's provider customer is charged.
6. The spend-time gate refuses every locked access state and missing consent
   for AgencyRebill; Grace proceeds.
7. Zero regression for Business/Workspace payers.
8. Fresh migrate, focused rollback/reapply, no unexpected pending migrations.
9. `git diff --check` clean; diff matches §12.

## 15. Non-goals

- No AgencyRebill configuration UI (Blueprint §28's own later surface); the
  existing payer selector never offers AgencyRebill.
- No Agency-wide cross-client spend/recharge ceiling or pause.
- No data migration (Contract 10 depends on this slice).
- No change to payer-independent wallet/provider checks.

## 16. Merge prerequisites

Contract 01 (hard) and Contract 05 (hard) — both merged.

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 01 | reads relationship table | Serialize (prerequisite) |
| Contract 05 | `CustomerAccountAccessResolver` consumed at spend time | Serialize (prerequisite) |
| Contract 08A | may touch `WorkspaceController` / `workspaces/show.blade.php` | Coordinate on those two files |
| Contract 08B | none (merged while this slice was implemented, with migrations `2026_09_21_100001`–`100004` rather than the planned 100009/100010) | No shared file or table; Contract 09 keeps `2026_09_20_100011`, which sorts lexically before 08B's but touches independent tables |
| Contract 10 | depends on this slice | Serialize — this slice first |
| Contract 02, 03, 04, 06 | none | Safe concurrent |

## 18. Implementation prompt

```
You are implementing Slice 9 of the V1 architecture migration for
os-creator1/os-ai: AgencyRebill activation, per docs/product/
implementation-contracts/09-AGENCYREBILL-ACTIVATION.md as corrected at
implementation time.

Before writing code: fetch origin/main; verify Contracts 01 and 05 are
merged; create a fresh branch; re-read this contract in full, especially
the implementation-time correction, §3's fourteen-site inventory and §5-§7;
confirm no new payer-resolution site has appeared (git grep "PayerType::"
and "payer_type" across app/). If one has, STOP and report.

Implement exactly this contract: the §5.1 migration (slot 100011), the
EffectivePayer fields, EffectivePayerResolver (resolve, resolveForPaidEffect,
paidEffectRefusal), the two PayerType scope methods (section 5.4), and
BillingProfileManager as the single human authority seam. Delete
isWorkspacePaid() and all three assertChargeCausingConsent*() copies. Add
the AgencyRebill spend-time gate at reserve() and initiateCharge(). Keep
isAgencyWideManager() byte-for-byte unchanged. Never charge, attach to, or
fall back to a client provider customer for AgencyRebill. Keep
UpdateBusinessPayerRequest rejecting agency_rebill. Build no configuration
UI.

After implementing: run the four new test files and every existing
Usage/Entitlement/Workspace/account-access regression; prove any failure
pre-exists on the starting main; run the mutation-style check; fresh migrate
and rollback/reapply the new migration; git diff --check; rerun the payer
inventory; commit and push. Do NOT create a pull request. Do NOT start
Contract 10.
```
