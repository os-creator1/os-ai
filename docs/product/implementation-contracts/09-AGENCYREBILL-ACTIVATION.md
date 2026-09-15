# Implementation Contract 09 — AgencyRebill Activation

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contract 01 (hard) and Contract 05 (recommended) being merged
first.

## 1. Objective

Activate `PayerType::AgencyRebill` under the exact consent/authority rules
Addendum §10 locks — extending `BillingProfileManager`'s existing payer-
change mechanism, never a parallel one.

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

## 4. Delta from current state to target

**Changes:** `business_payer_assignments` gains the new FK column
(Phase A's A3 design); `assignPayer()`'s two guard clauses both extended
to accept `PayerType::AgencyRebill`; `assertBillingResponsibilityAuthority()`
restructured to branch by target payer type — `Business`/`Workspace`
continue through the **existing, unchanged** `isAgencyWideManager()` path;
`AgencyRebill` routes through a **new** `isManagingAgencyOwner()` check
(§6) that resolves the relationship first; RFC-005 §16's documentation
gains the two new consent-rule rows Phase A's original review already
specified.

**Explicitly does NOT change:** `isAgencyWideManager()` itself (still
correct, unchanged, for its existing two payer types); `business_payer_
transitions`, `BusinessPayerChanged`, or any other part of the existing
audit/event mechanism — all reused verbatim; the standing-consent
timestamp columns' own semantics.

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
- `database/migrations/2026_09_2x_100011_add_managing_agency_relationship_id_to_business_payer_assignments_table.php`
- `tests/Feature/Usage/AgencyRebillAuthorityTest.php`

**Existing files modified:**
- `app/Library/Usage/BillingProfileManager.php` — extend both `in_array` guards; add `isManagingAgencyOwner()`; restructure `assertBillingResponsibilityAuthority()`'s branching per §6.
- `app/Models/BusinessPayerAssignment.php` — add `managing_agency_relationship_id` to `$fillable`.
- `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` — add the two new §16 consent rows (documentation update, not a schema/behavior change to the RFC's other content).

**No change to `UsageWalletManager`, `UsageBillingCheckoutManager`,
`business_payer_transitions`, or the `BusinessPayerChanged` event class
itself.**

## 13. Required tests

`AgencyRebillAuthorityTest.php`: every §6 matrix row as an explicit test;
the relationship-resolution-before-ownership-check order (an owner of an
*unrelated* Agency Workspace, correctly resolved as "not the managing
Agency," refused); mid-transaction relationship termination refused (§7);
existing `assignPayer()`/`changePayer()` tests for `business`/`workspace`
re-run unmodified to prove zero regression.

## 14. Acceptance criteria

1. Every §6 matrix row passes.
2. `isAgencyWideManager()` is provably untouched (diff shows zero lines
   changed in that method).
3. A relationship terminated mid-write correctly blocks the assignment
   (§7 test).
4. Zero regression on existing `business`/`workspace` payer tests.
5. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not implement any UI for configuring AgencyRebill (Blueprint §28's
own sub-surface, unscoped here). Does not modify `UsageWalletManager`'s
spend-time checks. Does not migrate any existing data (Contract 10 — and
per the Roadmap's own Phase-A-corrected ordering, Contract 10 depends on
this slice, not the reverse).

## 16. Merge prerequisites

Contract 01 (hard — the relationship this slice's authority check
resolves). Contract 05 (recommended — the composed effective-access check
this slice's spend-time safety, §11, ultimately relies on, though this
slice's own authority-check code does not directly call it).

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 01 | reads relationship table | Serialize (prerequisite) |
| Contract 10 | **depends on this slice** (Roadmap correction A4/A5 — the payer migration matrix requires `AgencyRebill` to already be a legal target) | Serialize — this slice must land first |
| Contract 02, 03, 04, 06 | none | Safe concurrent |

## 18. Implementation prompt

```
You are implementing Slice 9 of the V1 architecture migration for the
os-creator1/os-ai repository: AgencyRebill activation, per docs/product/
implementation-contracts/09-AGENCYREBILL-ACTIVATION.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contract 01 is merged to main -- hard prerequisite. Verify
   Contract 05's status (recommended, not hard) and note it in your
   report either way. If Contract 01 is missing, STOP and report.
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-09-agencyrebill-activation).
4. Re-read the full contract, especially SS3's critical finding that
   isAgencyWideManager() cannot be reused for the agency_rebill case, and
   SS6's full money-authority matrix -- it is the authoritative
   specification.
5. Inspect the actual current state of BillingProfileManager (both
   in_array guard clauses, assertBillingResponsibilityAuthority(),
   isAgencyWideManager(), assignPayer()'s full transactional body) and
   Contract 01's actual merged relationship model -- if anything differs
   from this contract's evidence, STOP and report the contradiction
   rather than guessing.

Implement exactly the scope in this contract: the new column, the two
extended guard clauses, the new isManagingAgencyOwner() check (owner-only,
no Admin/Staff bypass, resolving the relationship before checking
ownership), and the restructured assertBillingResponsibilityAuthority().
Leave isAgencyWideManager() completely untouched -- verify this yourself
before committing (zero diff lines in that method). Do NOT modify
UsageWalletManager or UsageBillingCheckoutManager. Do NOT build any UI.

After implementing:
- Run the new focused test file, covering every SS6 matrix row.
- Re-run every existing BillingProfileManager test to confirm zero
  regression on the business/workspace payer types.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, and explicit confirmation that isAgencyWideManager()'s
diff is empty. Do NOT begin or authorize Contract 10 or any other later
slice.
```
