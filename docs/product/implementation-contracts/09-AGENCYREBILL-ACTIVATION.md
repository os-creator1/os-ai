# Implementation Contract 09 — AgencyRebill Activation

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contract 01 **and Contract 05** (both now **hard**
prerequisites — Contract 05 upgraded from "recommended" in this
remediation, §16) being merged first.

## 1. Objective

Activate `PayerType::AgencyRebill` under the exact consent/authority rules
Addendum §10 locks — extending a **single canonical payer-resolution
helper** consumed by every existing money manager, never a parallel or
duplicated 3-way branch. **Critical correction (this remediation):** the
original draft only fixed `BillingProfileManager`; a full trace (§3) found
the same unhandled/mishandled `PayerType` branch repeated across **four**
classes, including one genuinely dangerous silent-misresolution site in
`UsageBillingCheckoutManager` that would have charged the **client's own**
instrument for what should be Agency-funded usage.

## 2. Governing authority

- Addendum §10 (the full AgencyRebill rule set), §17 (RFC-005 §16 status update).
- Blueprint §20, §28.
- Roadmap Slice 9 (as corrected in Phase A's A3 — schema impact is real,
  not none).
- Contract 01 (the relationship this slice's authority check depends on).

## 3. Current repository reality

**`app/Library/Usage/BillingProfileManager.php`** (relevant methods read
in full): the complete, exact money-authority mechanism.

- **`assignPayer(Business $business, PayerType $payerType, int
  $actorUserId, string $reason): array`** — the real write path
  (`changePayer()` is a thin wrapper returning only the assignment).
  Contains **two separate points** that currently reject any `payerType`
  outside `{Business, Workspace}`: a guard clause at the very top of the
  method body, and a second, identical check inside
  `assertBillingResponsibilityAuthority()` (called later in the same
  method). **Both** must be extended — extending only one leaves a path
  that still throws.
- **`assertBillingResponsibilityAuthority(Business $business, PayerType
  $payerType, int $actorUserId): void`** (full body read): after the
  enum-membership guard, asserts the Business's Workspace is
  `WorkspacePlanTier::Agency`, then calls `isAgencyWideManager()`.
- **`isAgencyWideManager(Business $business, int $actorUserId): bool`**
  (full body read): `$business->workspace->owner_user_id === $actorUserId`
  **OR** an active membership with `role === Admin` **AND**
  `business_access_scope === All`. **This checks authority against
  `$business->workspace` — the Business's own Workspace.**
- **Critical architectural finding, not previously stated this precisely
  anywhere in this project's prior audits:** `isAgencyWideManager()`'s
  entire design assumes the **pre-V1 model** — an Agency-tier Workspace
  directly containing the Business, so "the Agency-wide manager" and "a
  member of the Business's own Workspace" are the same check. **Under V1,
  a Client Business's own Workspace is the Client Workspace — not the
  Agency's.** The actor with legitimate `agency_rebill` authority (the
  managing Agency Workspace's owner) is **never** a member of
  `$business->workspace` at all (Addendum §2: authority must never be
  inferred from Client-Workspace membership). **`isAgencyWideManager()`
  cannot be reused, even extended, for the `agency_rebill` case** — it
  checks the wrong Workspace entirely. A **new**, separate authority
  check is required, resolving the managing Agency Workspace via Contract
  01's relationship **first**, then checking ownership of *that*
  Workspace — structurally the same "resolve the real authority Workspace
  first, never assume it's the Business's own" lesson Contract 04 already
  learned for View As's `userCanAccessBusiness()`.
- **`assignPayer()`'s full transactional body** (read): locks the payer
  assignment row (`findForUpdateByBusinessId`), self-heals a missing
  assignment via `initializePayerAssignmentForBusiness()`, handles a
  same-type "reaffirmation" as a no-op authorized for the current payer
  or an Agency-wide manager, otherwise calls the authority assertion,
  writes a `business_payer_transitions` row (**already** has exactly the
  audit fields Addendum §10 requires: `from_payer_type`, `to_payer_type`,
  `actor_user_id`, `reason`, timestamp — reused unchanged), updates the
  assignment, dispatches `BusinessPayerChanged` (reused unchanged).
- **`business_payer_assignments` schema** (re-confirmed from Phase A's
  A3): `business_id` (unique FK), `payer_type`, `effective_payment_
  instrument_id` (nullable, no FK yet) — **no column can record which
  Agency is paying**, confirmed again here; Phase A's A3 fix (a new
  nullable `managing_agency_relationship_id` FK to Contract 01's
  relationship table, required exactly when `payer_type = agency_rebill`)
  is the schema this slice implements.
- **Standing consent precedent, confirmed** (Phase A/earlier session
  evidence): `auto_recharge_consented_at`/`auto_recharge_consented_by_
  user_id` columns already exist and already implement exactly the
  "consent once, then automatic execution" pattern Addendum §10 wants
  reused for AgencyRebill.

**Full cross-manager `PayerType` branch inventory (this remediation's
required trace — every site confirmed by direct read, not sampled):**

`git grep "PayerType::" -- app` returns exactly ten files. Of these, **four
contain independent `payer_type` branching logic** that must each be
corrected or replaced — not just `BillingProfileManager`:

1. **`app/Library/Usage/BillingProfileManager.php`** — already covered
   above (`assignPayer()`'s guard, `assertBillingResponsibilityAuthority()`).
2. **`app/Library/Usage/UsageBillingCheckoutManager.php`** — **the
   dangerous one, confirmed by direct read of `initiateCharge()`
   (private, called by every charge-causing path — manual top-up,
   auto-recharge, add-on purchase):**
   ```php
   $providerCustomer = $payerType === PayerType::Workspace
       ? $this->providerCustomerRepository->findActiveByWorkspaceId((int) $business->workspace->id)
       : $this->providerCustomerRepository->findActiveByBusinessId($businessId);
   ```
   This is a **binary** ternary — `Workspace` gets the Workspace's
   provider customer; **anything else, including `AgencyRebill`, silently
   falls into `findActiveByBusinessId($businessId)`** — the **client's
   own** instrument. If `PayerType::AgencyRebill` were merely added to
   `BillingProfileManager`'s enum guard without fixing this method, every
   AgencyRebill-configured client's usage would be silently charged to
   the **client**, not the Agency — exactly the "wrong branch" the
   remediation names, confirmed exact and reproducible from source, not
   inferred. Separately, this same class's private
   `assertChargeCausingConsent(Business $business, int $actorUserId):
   PayerType` (used by `initiateAddonPurchase()`) has the same
   explicit-`if`-`if`-`throw` shape as `BillingProfileManager`'s method of
   the same name (confirmed: it **does** correctly throw for AgencyRebill
   today — a safe rejection, not a silent misresolution, but still needs
   the new branch added).
3. **`app/Library/Usage/UsageWalletManager.php`** — a private
   `isWorkspacePaid(int $businessId): bool` (line ~2698) returns `true`
   only for `payer_type === Workspace`; **every one of its five
   consumers** (confirmed by line-numbered read: auto-recharge
   payer-type selection ~2378, an outstanding-amount aggregation branch
   ~2404/2472/2497, and a `where('payer_type', ...)` query ~2733) treats
   "not workspace-paid" as synonymous with "business-paid" — the same
   binary collapse as site 2, independently implemented. `AgencyRebill`
   usage would be silently aggregated/queried as if it were ordinary
   client self-pay in wallet reporting and auto-recharge eligibility
   checks.
4. **`app/Library/Usage/PaymentInstrumentManager.php`** — contains its
   **own, separate, near-identical copy** of `assertChargeCausingConsent()`
   (confirmed by full read: same method name, same `if (Workspace) ...
   if (Business) ... throw` shape as `BillingProfileManager`'s) —
   **duplicated authority logic in a second file**, the exact
   "3-way payer algorithm duplicated across managers" pattern the
   remediation names. It already safely rejects AgencyRebill today (no
   silent-misresolution risk here), but fixing only `BillingProfileManager`'s
   copy and not this one would leave the two files' consent rules free to
   drift apart the moment either is edited independently in the future.

**A canonical resolution object already exists, unused.**
`app/Library/Usage/EffectivePayer.php` (full file read, 21 lines) is a
`final readonly class` DTO — `payerType`, `businessId`,
`effectivePaymentInstrumentId` — with a docblock stating its purpose
("who currently pays for a Business's usage, resolved from its
business_payer_assignments row"). **`git grep "new EffectivePayer(\|
EffectivePayer::" -- app` returns zero results** — this class is defined
but **never constructed or consumed anywhere**. Every one of the four
sites above independently re-reads `payerAssignmentRepository->
findByBusinessId()` and re-derives its own local `$payerType` variable,
rather than going through this shared type. This is exactly the seam the
remediation's "prefer one canonical payer-resolution object/helper"
instruction points at — the codebase already anticipated the need and
left the DTO in place, but never wired a resolver around it.

## 4. Delta from current state to target

**Changes:** `business_payer_assignments` gains the new FK column
(Phase A's A3 design); `EffectivePayer` (existing, unused DTO) is
extended and a new `EffectivePayerResolver` service class is introduced
as the **single** place `payer_type` is read and branched on (§5);
`BillingProfileManager::assignPayer()`'s two guard clauses both extended
to accept `PayerType::AgencyRebill`; `assertBillingResponsibilityAuthority()`
restructured to branch by target payer type — `Business`/`Workspace`
continue through the **existing, unchanged** `isAgencyWideManager()` path;
`AgencyRebill` routes through a **new** `isManagingAgencyOwner()` check
(§6) that resolves the relationship first; `UsageBillingCheckoutManager::
initiateCharge()`'s dangerous binary ternary is replaced with a call to
`EffectivePayerResolver`; `UsageWalletManager::isWorkspacePaid()` and its
five consumers are replaced with `EffectivePayerResolver` calls;
`PaymentInstrumentManager`'s duplicate `assertChargeCausingConsent()` is
replaced with a call to `BillingProfileManager`'s **single** corrected
version (§6) rather than maintaining its own copy; RFC-005 §16's
documentation gains the two new consent-rule rows Phase A's original
review already specified.

**Explicitly does NOT change:** `isAgencyWideManager()` itself (still
correct, unchanged, for its existing two payer types); `business_payer_
transitions`, `BusinessPayerChanged`, or any other part of the existing
audit/event mechanism — all reused verbatim; the standing-consent
timestamp columns' own semantics; the underlying wallet/cap/entitlement/
STOP-DND/idempotency/provider-readiness checks `UsageWalletManager`/
`UsageBillingCheckoutManager` already perform — only *which provider
customer/instrument* those checks resolve against changes, never the
checks themselves.

## 5. Data model contract

**`business_payer_assignments` — one new column** (Phase A A3, restated
precisely here as the authoritative design for this contract):

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `managing_agency_relationship_id` | `unsignedBigInteger`, FK → `agency_client_workspace_relationships.id` (Contract 01), `restrictOnDelete()` | Yes | `NULL` when `payer_type` is `business`/`workspace`; **required** and application-enforced-valid (must reference an **Active** relationship whose `client_workspace_id` resolves to this Business's own Workspace) when `payer_type = 'agency_rebill'`. Single source of truth for "which Agency is paying" — no code path may accept an arbitrary Workspace ID here. |

**No change** to `payer_type`'s own column definition (already
`string(16)`, already accepts `'agency_rebill'` as a value per the
existing enum — only the **application-layer** guard clauses currently
reject it).

**Canonical payer-resolution object — `EffectivePayer` extended, plus a
new `EffectivePayerResolver` service (the deep-dive's required single
helper):**

```php
final readonly class EffectivePayer
{
    public function __construct(
        public PayerType $payerType,
        public int $businessId,
        public ?int $effectivePaymentInstrumentId,
        public ?int $providerCustomerWorkspaceId,  // NEW — set for Workspace AND AgencyRebill
        public ?int $providerCustomerBusinessId,    // NEW — set for Business only
    ) {
    }
}
```

Exactly one of `providerCustomerWorkspaceId`/`providerCustomerBusinessId`
is non-null, mirroring the exact two-branch shape
`UsageBillingCheckoutManager::initiateCharge()`'s ternary already uses —
the difference is that `AgencyRebill` now correctly sets
`providerCustomerWorkspaceId` to the **managing Agency's** Workspace ID
(resolved through Contract 01's relationship), not the client's own.

```php
final class EffectivePayerResolver
{
    public function __construct(
        private readonly BusinessPayerAssignmentRepository $payerAssignmentRepository,
        private readonly AgencyClientWorkspaceRelationshipRepository $relationshipRepository,
    ) {}

    public function resolve(Business $business): EffectivePayer
    {
        $assignment = $this->payerAssignmentRepository->findByBusinessId((int) $business->id);
        $payerType = $assignment?->payer_type ?? PayerType::Workspace;

        return match ($payerType) {
            PayerType::Workspace => new EffectivePayer($payerType, (int) $business->id, $assignment?->effective_payment_instrument_id, (int) $business->workspace_id, null),
            PayerType::Business  => new EffectivePayer($payerType, (int) $business->id, $assignment?->effective_payment_instrument_id, null, (int) $business->id),
            PayerType::AgencyRebill => $this->resolveAgencyRebill($business, $assignment),
        };
    }

    private function resolveAgencyRebill(Business $business, BusinessPayerAssignment $assignment): EffectivePayer
    {
        $relationship = $this->relationshipRepository->findById((int) $assignment->managing_agency_relationship_id);

        // Defense in depth, mirroring Contract 04's own re-verification
        // discipline: never trust the FK alone -- confirm the relationship
        // is still Active and genuinely targets this Business's own
        // Workspace before resolving a provider customer from it.
        if ($relationship === null
            || $relationship->status !== AgencyClientRelationshipStatus::Active
            || (int) $relationship->client_workspace_id !== (int) $business->workspace_id) {
            throw new AgencyRebillRelationshipInvalidException((int) $business->id);
        }

        return new EffectivePayer(PayerType::AgencyRebill, (int) $business->id, null, (int) $relationship->agency_workspace_id, null);
    }
}
```

**Every one of §3's four sites is rewritten to call
`EffectivePayerResolver::resolve($business)` instead of independently
reading `payer_type`:**
- `UsageBillingCheckoutManager::initiateCharge()`'s ternary becomes:
  `$providerCustomer = $effectivePayer->providerCustomerWorkspaceId !== null
  ? $this->providerCustomerRepository->findActiveByWorkspaceId($effectivePayer->providerCustomerWorkspaceId)
  : $this->providerCustomerRepository->findActiveByBusinessId($effectivePayer->providerCustomerBusinessId);`
  — the **same two existing repository methods**
  (`findActiveByWorkspaceId`/`findActiveByBusinessId`, confirmed exact
  signatures via `PaymentProviderCustomerRepository`), now fed the
  correctly-resolved Workspace ID for all three payer types instead of a
  binary ternary that could only ever mean "the client's own Workspace."
- `UsageWalletManager::isWorkspacePaid()` is deleted; its five consumers
  are updated to call `EffectivePayerResolver::resolve()` and branch on
  `$effectivePayer->payerType` (or `providerCustomerWorkspaceId !== null`)
  directly.
- `PaymentInstrumentManager`'s duplicate `assertChargeCausingConsent()` is
  **deleted**; its one caller is updated to call
  `BillingProfileManager`'s corrected version instead (dependency-inject
  `BillingProfileManager`, or extract that one method to a shared trait/
  class both already depend on — implementation-time judgment, but the
  logic itself must exist in exactly one place, never two).

**Two further new columns on `business_payer_assignments` — required by
Contract 10's migration cutover design, added here since this contract
owns the table's schema:** `agency_rebill_consented_at` and
`agency_rebill_consented_by_user_id`, both nullable, mirroring the exact
`auto_recharge_consented_at`/`_by_user_id` standing-consent shape already
established elsewhere in this codebase (§3). **`BillingProfileManager::
assignPayer()`'s normal, owner-initiated write path MUST set both fields
to `now()`/the authorized `$actorUserId` in the same write, whenever it
successfully writes `payer_type = 'agency_rebill'`** — in the ordinary
flow, `isManagingAgencyOwner()`'s authorization check passing *is*
consent, synchronously, so the columns are never left `NULL` by this
contract's own code path. They exist in `NULL` state only when Contract
10's migration writes `payer_type = 'agency_rebill'` directly (bypassing
`assignPayer()`, since no owner is present to authorize at migration
time) — Contract 10's own contract specifies that case fully; this
contract's job is only to (a) add the columns and (b) ensure its own
normal write path always populates them. **Every charge-causing check
downstream (§11) must additionally verify `agency_rebill_consented_at IS
NOT NULL` before proceeding for an `agency_rebill`-typed assignment** —
treating a `NULL` value identically to "no provider customer found"
(the existing fail-closed path already confirmed in
`UsageBillingCheckoutManager::initiateCharge()`).

## 6. Authority / security contract — full money-authority matrix (deep-dive requirement)

| Actor | Set `payer_type = business`/`workspace` | Set `payer_type = agency_rebill` | Revoke `agency_rebill` | Configure Agency funding instrument/auto-recharge |
|---|---|---|---|---|
| Managing Agency Workspace owner | Yes (existing `isAgencyWideManager()`, unchanged, if the Business's own Workspace happens to still be Agency-tier — legacy path) | **Yes** — via new `isManagingAgencyOwner()` | **Yes** | **Yes** |
| Managing Agency Workspace Admin (`business_access_scope = All`) | Yes (existing, unchanged) | **No** | **No** | **No** |
| Managing Agency Workspace Staff (any permission) | No (existing, unchanged) | **No** | **No** | **No** |
| Client Workspace owner | Yes, for `business` only, as today's `isCurrentPayer()` already allows (existing, unchanged) | **No** | **No** | **No** |
| Client Workspace staff | No | **No** | **No** | **No** |
| Platform Owner/Administrator | No (existing — `assertBillingResponsibilityAuthority` never grants platform-admin bypass today) | **No** — never originates on a customer's behalf (Addendum §10) | **No** | **No** |
| An Agency Workspace owner with **no** active relationship to this Business's Client Workspace | N/A (would already fail the Agency-tier check on the legacy path) | **No** — relationship lookup fails first, before ownership is even checked |

**New `isManagingAgencyOwner(Business $business, int $actorUserId): bool`**
(replacing `isAgencyWideManager()` for the `agency_rebill` branch only):
1. Resolve the Business's own Workspace (as today).
2. Look up Contract 01's **Active** relationship where
   `client_workspace_id` equals that Workspace's ID — no relationship,
   return `false` immediately (never throw a "no relationship" error that
   discloses more than a plain refusal — matches this codebase's existing
   existence-disclosure discipline, e.g. `ViewAsManager`'s `abort(404)`
   pattern, adapted here to a boolean refusal rather than an HTTP abort
   since this is a library-layer check, not a controller).
3. Only then check `relationship.agency_workspace_id`'s `owner_user_id ===
   $actorUserId` — **owner only, no Admin/Staff bypass**, unlike every
   other authority check in `BillingProfileManager`.

**`assertBillingResponsibilityAuthority()`'s corrected shape:**
```php
private function assertBillingResponsibilityAuthority(Business $business, PayerType $payerType, int $actorUserId): void
{
    if ($payerType === PayerType::AgencyRebill) {
        if (! $this->isManagingAgencyOwner($business, $actorUserId)) {
            throw new UnauthorizedPayerAssignmentException(...);
        }
        return;
    }
    // existing Business/Workspace branch, byte-for-byte unchanged below
    ...
}
```

## 7. Transaction / concurrency boundary

Reuses `assignPayer()`'s existing transaction and row-lock
(`findForUpdateByBusinessId`) unchanged — the new
`managing_agency_relationship_id` write happens inside the same lock, no
new lock ordering introduced. One addition: within the same transaction,
after resolving the relationship in `isManagingAgencyOwner()`, the write
itself must **re-verify** the relationship is still `Active` immediately
before writing (not merely at the start of the authority check) — a
relationship terminated in the narrow window between the check and the
write must not leave a stale `agency_rebill` assignment referencing a
now-inactive relationship. This mirrors Contract 01 §7's own "re-check
under lock" discipline.

## 8. Migration / backfill

None — `agency_rebill` has never been assignable, so no existing row uses
it; the new column starts `NULL` for every existing row (both meanings —
"not applicable" for `business`/`workspace` rows, and "not yet used" for
what will eventually be `agency_rebill` rows).

## 9. Backwards compatibility

Every existing `business`/`workspace` payer-change call is unaffected —
`isAgencyWideManager()` is untouched, and the `in_array` guards are
widened (superset), never narrowed. Existing tests for
`assignPayer()`/`changePayer()`/`billingResponsibilityFor()` must all
still pass unmodified.

## 10. Events / audit

Reuses `business_payer_transitions` and `BusinessPayerChanged` unchanged
(§3) — both already carry every field Addendum §10 requires (actor,
timestamp, Business/old payer/new payer, mandatory reason). No new event
type is needed. The new `managing_agency_relationship_id` column itself
is the durable record of "which Agency," queryable directly from the
assignment row — no separate audit table needed for that specific fact.

## 11. Billing/provider safety

This is the slice where billing safety is the entire point. Every
existing check `UsageBillingCheckoutManager`/`UsageWalletManager` already
perform for `business`/`workspace` payers (wallet, cap, entitlement,
STOP/DND, idempotency, provider readiness — confirmed present in this
codebase from earlier session evidence) must apply **identically** when
`payer_type = agency_rebill`, with two additions per Addendum §10: (a) the
Client Workspace's own effective account access (Contract 03) **and** (b)
the managing Agency's effective account access (Contract 05's
composition) must both gate every automated Agency-funded effect — this
slice's job is to ensure the payer-resolution layer correctly identifies
`agency_rebill` and its funding instrument; the actual spend-time checks
themselves are `UsageWalletManager`'s existing responsibility, not
re-implemented here.

## 12. Exact implementation allowlist

**New files:**
- `database/migrations/2026_09_2x_100011_add_managing_agency_relationship_id_to_business_payer_assignments_table.php` — also adds `agency_rebill_consented_at`/`agency_rebill_consented_by_user_id` in the same migration (three new nullable columns total).
- `app/Library/Usage/EffectivePayerResolver.php`
- `app/Exceptions/Usage/AgencyRebillRelationshipInvalidException.php`
- `tests/Feature/Usage/AgencyRebillAuthorityTest.php`
- `tests/Feature/Usage/EffectivePayerResolverTest.php`

**Existing files modified:**
- `app/Library/Usage/EffectivePayer.php` — add the two new
  `providerCustomer*` fields (§5).
- `app/Library/Usage/BillingProfileManager.php` — extend both `in_array`
  guards; add `isManagingAgencyOwner()`; restructure
  `assertBillingResponsibilityAuthority()`'s branching per §6; `assignPayer()`
  sets `agency_rebill_consented_at`/`_by_user_id` in the same write
  whenever it successfully writes `payer_type = 'agency_rebill'` (§5).
- `app/Library/Usage/UsageBillingCheckoutManager.php` — replace
  `initiateCharge()`'s binary ternary with an `EffectivePayerResolver`
  call (§5); add the `AgencyRebill` branch to its own
  `assertChargeCausingConsent()`.
- `app/Library/Usage/UsageWalletManager.php` — delete `isWorkspacePaid()`;
  update its five confirmed consumers (§3) to use
  `EffectivePayerResolver`.
- `app/Library/Usage/PaymentInstrumentManager.php` — delete its duplicate
  `assertChargeCausingConsent()`; its one caller now uses
  `BillingProfileManager`'s corrected version.
- `app/Models/BusinessPayerAssignment.php` — add `managing_agency_relationship_id` to `$fillable`.
- `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` — add the two new §16 consent rows (documentation update, not a schema/behavior change to the RFC's other content).

## 13. Required tests

`EffectivePayerResolverTest.php`: all three `PayerType` cases resolve
`providerCustomerWorkspaceId`/`providerCustomerBusinessId` correctly;
`AgencyRebill` resolution fails closed (throws
`AgencyRebillRelationshipInvalidException`) for a terminated relationship,
a relationship pointing at a different Business's Workspace, and a
missing `managing_agency_relationship_id`.

`AgencyRebillAuthorityTest.php`: every §6 matrix row as an explicit test;
the relationship-resolution-before-ownership-check order (an owner of an
*unrelated* Agency Workspace, correctly resolved as "not the managing
Agency," refused); mid-transaction relationship termination refused (§7);
existing `assignPayer()`/`changePayer()` tests for `business`/`workspace`
re-run unmodified to prove zero regression.

**Cross-manager regression, explicit (per this remediation):** a
dedicated end-to-end test proving `UsageBillingCheckoutManager::
initiateCharge()` resolves the **Agency's** provider customer, not the
client Business's own, for an `AgencyRebill`-configured Business — this
is the single most important test in this contract, since it is the
exact defect (§3) this remediation exists to prevent from ever being
implemented silently wrong. Additional regression: `UsageWalletManager`'s
five updated consumers produce identical output to their pre-change
behavior for `Workspace`/`Business` payers (regression-proven), and
correctly attribute `AgencyRebill` usage to the Agency in
wallet/auto-recharge reporting (new coverage).

## 14. Acceptance criteria

1. Every §6 matrix row passes.
2. `isAgencyWideManager()` is provably untouched (diff shows zero lines
   changed in that method).
3. A relationship terminated mid-write correctly blocks the assignment
   (§7 test).
4. `EffectivePayerResolver` is the **only** place in the codebase that
   reads `business_payer_assignments.payer_type` to decide which provider
   customer to charge — verified by confirming `isWorkspacePaid()` and
   `PaymentInstrumentManager`'s duplicate `assertChargeCausingConsent()`
   no longer exist, and that `initiateCharge()`'s ternary is gone.
5. The cross-manager regression test (§13) proves the Agency's, not the
   client's, provider customer is charged for `AgencyRebill`.
6. Zero regression on existing `business`/`workspace` payer tests.
7. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not implement any UI for configuring AgencyRebill (Blueprint §28's
own sub-surface, unscoped here). Does not change the underlying wallet/
cap/entitlement/STOP-DND/idempotency/provider-readiness check *logic*
itself (only which provider customer/instrument those checks resolve
against). Does not migrate any existing data (Contract 10 — and per the
Roadmap's own Phase-A-corrected ordering, Contract 10 depends on this
slice, not the reverse).

## 16. Merge prerequisites

Contract 01 (hard — the relationship this slice's authority check and
`EffectivePayerResolver` both resolve). **Contract 05 (now hard, upgraded
from "recommended" in this remediation)** — `EffectivePayerResolver`'s
`AgencyRebill` branch resolves the managing Agency Workspace, and every
spend-time check downstream (§11) needs Contract 05's composed effective-
access to correctly gate that Agency-funded spend; treating it as merely
"recommended" understated a real dependency.

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 01 | reads relationship table | Serialize (prerequisite) |
| Contract 05 | consumed by spend-time checks (now hard, §16) | Serialize (prerequisite) |
| Contract 10 | **depends on this slice** (Roadmap correction A4/A5 — the payer migration matrix requires `AgencyRebill` to already be a legal target) | Serialize — this slice must land first |
| Contract 02, 03, 04, 06 | none | Safe concurrent |

## 18. Implementation prompt

```
You are implementing Slice 9 of the V1 architecture migration for the
os-creator1/os-ai repository: AgencyRebill activation, per docs/product/
implementation-contracts/09-AGENCYREBILL-ACTIVATION.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contracts 01 AND 05 are BOTH merged to main -- both are hard
   prerequisites (Contract 05 was upgraded from "recommended" in this
   remediation, SS16). If either is missing, STOP and report.
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-09-agencyrebill-activation).
4. Re-read the full contract, especially SS3's full cross-manager site
   inventory (four classes, not one) and SS5's EffectivePayerResolver
   design -- both are authoritative. SS3's UsageBillingCheckoutManager
   finding is the most safety-critical: verify for yourself, before
   changing anything, that initiateCharge()'s ternary really does
   silently resolve AgencyRebill to the client's own provider customer
   today, exactly as SS3 describes -- this is the defect you are fixing.
5. Inspect the actual current state of BillingProfileManager,
   UsageBillingCheckoutManager, UsageWalletManager,
   PaymentInstrumentManager, and the existing (unused) EffectivePayer DTO
   -- if any site's code has changed from this contract's evidence
   (methods renamed, logic restructured), STOP and report the
   contradiction rather than guessing which is authoritative.

Implement exactly the scope in this contract: the new column,
EffectivePayer's two new fields, the new EffectivePayerResolver class,
and its adoption at all four sites SS3/SS5 name -- isWorkspacePaid() and
PaymentInstrumentManager's duplicate assertChargeCausingConsent() are
DELETED, not left alongside the new resolver. The new
isManagingAgencyOwner() check (owner-only, no Admin/Staff bypass,
resolving the relationship before checking ownership) and the
restructured assertBillingResponsibilityAuthority(). Leave
isAgencyWideManager() completely untouched -- verify this yourself before
committing (zero diff lines in that method). Do NOT change any wallet/
cap/entitlement/STOP-DND/idempotency/provider-readiness check's own
logic -- only which provider customer/instrument it resolves against. Do
NOT build any UI.

After implementing:
- Run the new focused test files, covering every SS6 matrix row and
  EffectivePayerResolver's own test suite.
- Run the cross-manager regression test proving initiateCharge() now
  resolves the Agency's provider customer for an AgencyRebill-configured
  Business -- this is the single most important test result to report.
- Re-run every existing BillingProfileManager/UsageWalletManager/
  UsageBillingCheckoutManager/PaymentInstrumentManager test to confirm
  zero regression on the business/workspace payer types.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, explicit confirmation that isAgencyWideManager()'s diff is
empty, and explicit proof (from your cross-manager regression test) that
AgencyRebill usage now charges the Agency's provider customer, not the
client's. Do NOT begin or authorize Contract 10 or any other later slice.
```
