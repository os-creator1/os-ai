# Implementation Contract 10 — Agency Data Migration

**Status:** Planning contract only. Does not authorize implementation.
This is the deepest contract in the factory, per its own explicit
deep-dive requirement. Depends on Contracts 01, 02, 04, 07, 08A, and **09**
(hard — Roadmap Phase A correction A4/A5) all being merged and stable
first.

## 1. Objective

For every existing Agency Workspace holding multiple Businesses today,
move each non-primary Business (the client) into its own new Client
Workspace, establish an active Contract 01 relationship, and preserve
every dependent record — without ever silently changing who pays for
anything (Roadmap A5) or duplicating a Location that already exists
(Roadmap A4).

## 2. Governing authority

- Addendum §18 step 4; Blueprint §35.
- Roadmap Slice 10, **as rewritten by the Phase A pass (A4, A5)** — that
  rewrite is the authoritative design this contract implements in full
  mechanical detail; this contract does not re-derive A4/A5's conclusions,
  it operationalizes them.
- Contracts 01 (relationship target), 07 (the provisioning shape a
  migrated client should end up matching), 09 (AgencyRebill, required
  before the payer matrix's `workspace`-type rows can be resolved).

## 3. Current repository reality — full entity inventory (deep-dive requirement)

Every table this migration touches, its FK shape, and whether the
Business's own `id` (the pivot every other table hangs off) is preserved
by simply reassigning `workspace_id` — confirmed by direct inspection,
not assumed:

| Entity | FK shape | ID preserved by a plain `workspace_id` reassignment? | Notes |
|---|---|---|---|
| `Workspace` | — | N/A (new row created) | A **new** Client Workspace row is created (Contract 01/07's `createWorkspace()`, reused unchanged); the Agency's own Workspace row is untouched. |
| `Business` | `workspace_id` (no unique constraint — confirmed original audit) | **Yes** — the *only* write this migration makes to the Business row itself is `workspace_id`; `id`, `uid`, `customer_id`, every other column stays identical | The single pivot write every other table's preservation depends on. |
| `BusinessLocation` | `business_id` (`onDelete('cascade')`) | **Yes, automatically** — keys on `business_id`, not `workspace_id`; **zero rows touched** by this migration (Roadmap A4) | Primary-location repair only if genuinely none exists (A4's `upsertPrimaryLocation()` rule) — checked, not assumed necessary. |
| `WorkspaceMembership` (Agency's own) | `workspace_id` | N/A | The Agency's own memberships are untouched; the migrated Business's former co-membership grants (if any staff had Business-scoped access to it) are removed, not moved — see next row. |
| `workspace_membership_businesses` (Business-access grants) | `workspace_membership_id` (→ Agency Workspace), `business_id` | **Removed, not moved** — a grant scoped to an Agency-Workspace membership has no meaning once the Business leaves that Workspace; use the **existing** `WorkspaceMembershipBusinessRepository::removeAllForBusinessInWorkspace()` method (confirmed to exist, purpose-built for exactly this cleanup — `WorkspaceManager::reassignBusiness()` already calls it for the general cross-Workspace-move case) | No new repository method needed — this is the one piece of the migration that has a **direct, existing, purpose-built precedent method** to reuse verbatim. |
| `workspace_plan_assignments` | `business_id`? **No** — confirmed `workspace_id` (unique) | N/A — belongs to the *Workspace*, not the Business | The new Client Workspace needs its **own** plan assignment (via the existing `EntitlementManager`/onboarding path Contract 07 already established), not a copy of the Agency's. This is a **new** row, not a migrated one — flagged explicitly since it's easy to assume plan state "moves with the Business" when it does not. |
| `business_usage_wallets` | `business_id` (unique, `restrictOnDelete`) | **Yes, automatically** — keys on `business_id`; zero rows touched | Confirmed in this pass: `2026_08_16_120001_create_business_usage_wallets_table.php`. |
| `business_payer_assignments` | `business_id` (unique, `restrictOnDelete`) | **Yes, automatically** for the row's existence — but its **meaning** changes with the Workspace move; this is Roadmap A5's payer matrix, restated precisely in §5 below | The one entity requiring active logic, not just a pass-through. |
| `business_payment_instruments` | Referenced by `business_payer_assignments.effective_payment_instrument_id` (currently no FK — deferred per RFC-005 §16's own docblock, confirmed) | **Yes, automatically** — keys on `business_id` per the payer-assignment table's own comment | Not independently touched. |
| `Website`/`WebsitePage`/`WebsiteAsset`/`WebsiteRevision` | `business_id` (confirmed: `Website.php` `belongsTo(Business::class)`) | **Yes, automatically** — zero rows touched | |
| `Contacts` | `business_id` (own, independently tracked per this session's own prior-task evidence) | **Yes, automatically** | |
| `Opportunity`/`OpportunityRun` | `business_id` (confirmed `app/Models/Opportunity.php`) | **Yes, automatically** | |
| `ChatBox` (Conversations) | `business_id` (nullable, confirmed Contract 06) | **Yes, automatically** | `location_id` (Contract 06, if landed) also untouched — keys on `business_id`/`location_id`, not `workspace_id`. |
| `Automation`/`AutomationWorkflow`/`AutomationEnrollment`/`AutomationExecution`/`AutomationStepRun` | `business_id` (confirmed `Automation.php`) | **Yes, automatically** | |
| `BusinessMessagingNumber` | `business_messaging_identity_id` → (ultimately) `business_id` (confirmed Contract 06 §3) | **Yes, automatically** | |
| Connected Stripe account reference | **Not located precisely in this evidence pass** — no dedicated `*Stripe*`/`*ConnectedAccount*` model file found; presumed to be a column on `Business` itself or a Business-keyed settings table, consistent with every other entity's pattern above | **Flagged for implementation-time confirmation**, not assumed | This is the one row in this table not independently verified — implementation must locate and confirm before relying on "automatic, `business_id`-scoped" for this specific fact. |
| Audit history (`workspace_transitions`) | `workspace_id`, `business_id`, `from_workspace_id` (all confirmed from the original architecture audit) | **New row required** — a `BusinessReassigned` transition, exactly the shape `WorkspaceManager::reassignBusiness()` already writes for the general case | Reuse `reassignBusiness()`'s transition-writing shape (or the method itself — see §4). |
| View As sessions (historical, `view_as_sessions`) | `workspace_id`, `business_id` (both `cascadeOnDelete`, confirmed Contract 04) | **Left as historical fact, not rewritten** — a past View As session correctly recorded "this Business, in that Workspace, at that time"; rewriting it to point at the new Client Workspace would falsify history | Explicit decision: do **not** touch existing `view_as_sessions` rows. |
| `additional_business_slot_agreements` | `workspace_id` (confirmed Phase A/original audit) | N/A — belongs to the Agency's own Workspace, not the migrated Business | Untouched by this migration; Contract 11 handles retirement/treatment separately, **after** this migration (Roadmap order). |

## 4. Delta from current state to target

**Corrected step order (this remediation) — two bugs fixed from the
original draft:**

1. **Bug fixed: capacity-check ordering.** `WorkspaceManager::
   reassignBusiness()` internally calls `EntitlementManager::
   assertCanCreateAnotherBusiness($lockedTargetWorkspace)` (confirmed by
   direct read, line ~1021), which **throws `WorkspacePlanUnassignedException`**
   (confirmed via `assertCanCreateAnotherBusiness()`'s own `match` on
   `decideBusinessSlotCapacity()`'s denial reasons) if the target Workspace
   has no plan assignment yet. A bare `createWorkspace()` call produces
   exactly such an unassigned Workspace. Calling `reassignBusiness()`
   immediately after `createWorkspace()` — the original draft's step
   order — would **fail every single migration attempt** with this
   exception. **Plan assignment must happen between Workspace creation and
   Business reassignment, never after.**
2. **Bug fixed: relationship-creation chicken-and-egg.** The original
   draft created the Contract 01 relationship **last** (step 6), but its
   own payer-matrix halt case (§5) required the Agency owner to
   "re-establish `AgencyRebill` consent via Contract 09's flow, pointed
   at the new relationship" — a relationship that, at that point in the
   sequence, **does not exist yet**. Contract 09's `isManagingAgencyOwner()`
   requires an **Active** relationship to resolve at all; there was no
   way to satisfy that requirement while the relationship's own creation
   was still three steps away. **The relationship must be created
   immediately after the Business moves, before the payer decision.**

**Corrected per-Business sequence, in one transaction (§7):**
1. Create the Client Workspace (`WorkspaceManager::createWorkspace()`, unchanged).
2. **Assign a plan to the new Client Workspace** (Contract 07's own
   provisioning shape — not a copy of the Agency's plan) — **before** step 3,
   fixing bug 1 above.
3. Reassign the Business's `workspace_id` — **reuse
   `WorkspaceManager::reassignBusiness()` itself**, not a new bespoke
   write, since it already performs exactly the required sub-steps in the
   right order: locks both Workspaces (ascending ID), asserts capacity on
   the target (now genuinely satisfied, per step 2), calls
   `WorkspaceMembershipBusinessRepository::removeAllForBusinessInWorkspace()`
   (the exact cleanup row 4 of §3's table needs), writes the
   `workspace_transitions` row, dispatches `BusinessReassignedToWorkspace`.
   **This is the single biggest reuse opportunity this contract found** —
   the general-purpose method the old architecture already built for
   "move a Business between two ordinary Workspaces" does almost
   everything this migration's per-Business core step needs, with zero
   modification.
4. **Create the Contract 01 relationship** (Agency Workspace → new Client
   Workspace) **immediately after reassignment**, via Contract 01's
   manager, actor = the human operator running this migration (recorded
   honestly as the actor, not a system user pretending to be the Agency
   owner — see §10) — **before** the payer decision, fixing bug 2 above.
5. Run the primary-location repair check (§3, `BusinessLocation` row —
   only if genuinely absent).
6. Resolve the payer matrix (§5) — the relationship now exists (step 4),
   so the `workspace`-under-Agency case can reference it immediately
   rather than halting the whole migration.

**Explicitly does NOT change:** any table row keyed on `business_id`
(§3's "automatic" rows) — this migration's writes are narrowly: the
Business's own `workspace_id`, one new Workspace row, one new plan
assignment row, `workspace_membership_businesses` deletions (via the
reused method), one new relationship row, and — for the flagged payer
case — a `business_payer_assignments` update per §5's corrected design.

## 5. Payer migration matrix (executable, consent-preserving — corrected in this remediation)

**Schema prerequisite, owned exclusively by Contract 09 — Contract 10
creates no migration of its own for it.** `business_payer_assignments`
gains two nullable columns — `agency_rebill_consented_at` and
`agency_rebill_consented_by_user_id` — mirroring the exact standing-
consent pattern `auto_recharge_consented_at`/`_by_user_id` already
establishes elsewhere in this codebase. **Contract 09 alone owns this
schema change** (it already owns `managing_agency_relationship_id` on
the same table, and these two columns are added in that same migration,
per Contract 09 §12); Contract 10 only **writes** to these already-
existing columns as part of its own cutover (§4 step 6 below), and its
own preflight (§8) verifies they exist before executing — there is
exactly one schema owner for `business_payer_assignments` across both
contracts. **Meaning:** for any row with `payer_type = 'agency_rebill'`, `NULL` means
*provisionally* AgencyRebill-typed but **not yet consented by the real
Agency owner** — every charge-causing check must treat a `NULL` here
identically to "no provider customer found" (the existing, already-safe
`initiateCharge()` fail-closed path, confirmed: `if ($providerCustomer
=== null) return new FundingAttemptResult(0, Failed, 'no_provider_customer')`)
— **no new paid activity may start** for such a row. Contract 09's own
*normal*, owner-initiated `assignPayer(business, AgencyRebill, ...)` call
sets both fields to `now()`/the acting owner's ID **immediately, in the
same write** (authorization *is* consent, synchronously, in that flow) —
only a *migration-initiated* conversion (this contract) ever leaves them
`NULL` after the write.

| Current `business_payer_assignments.payer_type` | Migration action |
|---|---|
| `business` | No change — self-pay, `business_id` FK unaffected by the Workspace move. |
| `workspace`, under the Agency-tier Workspace, for this (non-primary/client) Business | **No longer a halt.** Immediately set `payer_type = 'agency_rebill'` and `managing_agency_relationship_id` to the relationship created in §4 step 4 (which now exists) — but leave `agency_rebill_consented_at`/`_by_user_id` **`NULL`**. This is the durable "pre-consent/migration-intent" record the remediation calls for: the payer type is correctly AgencyRebill-shaped from the moment the Business moves (never a stale `workspace` value that would silently self-pay against the wrong new Workspace), but **zero new paid activity can occur** until the real Agency owner visits Contract 09's consent flow and it sets the two consent columns. The migration's own report lists every such Business as "pending Agency confirmation," with a direct link/reference the Agency owner can act on. |
| No assignment row at all | Preflight failure — data-integrity stop condition (should not occur; M2 backfill is documented complete). |

**This resolves every one of the remediation's explicit requirements:**
no fabricated Agency-owner consent (the timestamp is only ever set by the
real owner, via Contract 09's own authorization path); no temporary
accidental Client-paid state (`payer_type` is never left as `workspace`
pointing at the wrong Workspace, even transiently); no ambiguous paid
activity (the `NULL`-consent state fails every charge-causing check
closed, reusing an existing, proven mechanism rather than inventing a new
one); resumable (§7); Business ID and Locations preserved (§3, unchanged
by this correction); explicit Agency-owner consent required before Agency
funding *continues* (the whole point of the two new columns).

**This table is the migration's entire payer-handling logic.** Any row
not matching one of these three cases is added to the preflight's
unresolved list and halts, never guessed.

## 6. Authority / security contract

This is an **operator-run migration command**, not an end-user-facing
action — it runs under the same route-3 governance as every other
migration in this project (a human explicitly authorizes running it
against a specific database). It is **not** invoked by any Agency actor
through the product UI. Every Contract 01 relationship it creates records
the **real human operator** running the migration as
`established_by_user_id` (§10) — never a fabricated "the Agency owner did
this" actor, since the Agency owner did not, in fact, take this action;
this is honest per Addendum's own "real acting User" discipline extended
to a migration context.

## 7. Transaction / concurrency boundary

**Per-Agency-Workspace transaction, not one giant transaction across every
Agency** — matches Roadmap's own "SERIAL ONLY, one Agency at a time, with
verification between batches" framing. Within one Agency's transaction:
lock the Agency Workspace row first (`findForUpdate`), then process each
non-primary Business sequentially (each reassignment internally re-uses
`reassignBusiness()`'s own two-Workspace ascending-lock-order discipline).
**Resumability, explicitly required by the deep-dive:** the migration
command must be safely re-runnable — track per-Business completion (e.g.
a `migrated_at` marker this migration command itself manages, or simply
detecting "this Business's `workspace_id` is no longer the original Agency
Workspace's ID" as the completion signal, which is sufficient given §3's
inventory shows no table needs a bespoke migration-tracking column of its
own) so a second run skips already-migrated Businesses and only processes
the remainder — never leaving "some clients in the old shape, some in the
new" for one Agency indefinitely, per the Roadmap's own adversarial test
theme.

## 8. Migration / backfill

**Full preflight/dry-run/execution/verification/resumability/rollback
posture, exactly as required:**

1. **Preflight report** (read-only, no writes): **first**, a schema
   check — confirm `business_payer_assignments.managing_agency_relationship_id`,
   `.agency_rebill_consented_at`, and `.agency_rebill_consented_by_user_id`
   all exist (Contract 09's migration, §5/§12/§16) — fail the preflight
   immediately, before any per-Agency reporting, if any is missing; **then**
   per Agency Workspace — Business count, primary-less Business count
   (§3's `BusinessLocation` check), and §5's payer-matrix classification
   per Business. Output as a structured report a human reviews before any
   execution run.
2. **Dry-run mode**: executes every read and every decision branch (§4's
   corrected six-step sequence) without committing writes, reporting
   exactly what *would* happen per Business, including which Businesses
   would land in §5's pending-Agency-confirmation state.
3. **Execution**: per-Agency transaction (§7), real writes, resumable.
4. **Verification**: post-run, assert zero remaining Businesses under the
   processed Agency Workspace(s) other than the Agency's own primary one;
   assert every migrated Business's `business_payer_assignments` row is
   either unchanged (`business` case) or correctly converted to
   `agency_rebill` with both consent columns `NULL` (`workspace` case,
   §5) — never silently left as a stale `workspace` value, and never
   fabricated as already-consented.
5. **Resumability**: §7.
6. **Rollback/backout posture**: this migration does **not** provide an
   automated rollback (matching this codebase's own established
   precedent — `WorkspaceBackfillV1`'s `down()` is a documented
   intentional no-op, "can't safely distinguish backfilled rows from
   later real ones," and this migration's writes are similarly
   entangled with subsequent real activity, e.g. a new Client Workspace
   may have already received its own plan assignment or even usage by the
   time a rollback might be considered). A **backout** for one specific
   Business, if ever needed, is a **manual, individually-reviewed**
   `reassignBusiness()` call moving it back — not an automated inverse of
   this migration.

## 9. Backwards compatibility

Every Agency Workspace not yet processed by this migration continues to
function exactly as it does on `main` today (multiple Businesses, old
View As path, old switcher) — this migration is opt-in per Agency, run by
the operator, not a `main`-wide behavior change on deploy.

## 10. Events / audit

Reuses `BusinessReassignedToWorkspace`, `WorkspaceMembershipBusinessUnassigned`
(both already dispatched by the reused `reassignBusiness()` call),
`WorkspaceCreated`, and Contract 01's `AgencyClientRelationshipEstablished`
— all with the real migration operator as actor (§6). No new event type.
The existing `workspace_transitions` row `reassignBusiness()` already
writes is the durable "this Business moved, and when" record — sufficient,
not duplicated.

## 11. Billing/provider safety

The entire point of §5. Additionally: this migration must **not** trigger
any provider call (no Stripe operation, no Telnyx operation) — it is a
pure database-relationship migration; any Business whose connected Stripe
account reference location is confirmed (§3's flagged row) to be
Business-keyed needs no provider-side change at all, since the Business
`id` itself never changes.

## 12. Exact implementation allowlist

**New files:**
- `app/Console/Commands/MigrateAgencyClientBusinesses.php` (or equivalent Artisan command — the operator entry point, supporting `--dry-run`)
- `app/Library/Workspace/Migration/AgencyBusinessMigrationV1.php` (the actual logic class, mirroring `WorkspaceBackfillV1`'s own "versioned, immutable, invoked by a thin wrapper" pattern)
- `tests/Feature/Workspace/AgencyBusinessMigrationV1Test.php`

**No migration file in this contract's allowlist.** `managing_agency_relationship_id`,
`agency_rebill_consented_at`, and `agency_rebill_consented_by_user_id` are
all created by **Contract 09's** migration exclusively (§5) — Contract 10
never creates or duplicates that schema. This contract's own preflight
(§8) includes an explicit check that all three columns already exist on
`business_payer_assignments` before any migration write runs, failing the
preflight (not silently proceeding) if Contract 09's migration has not
actually been applied to the target database — this is how Contract 10
verifies its hard prerequisite on Contract 09 (§16) mechanically, not
merely by documentation cross-reference.

**Existing files NOT modified:** `WorkspaceManager.php` (its existing
`reassignBusiness()` is called, not changed), `WorkspaceMembershipBusinessRepository.php`
(its existing `removeAllForBusinessInWorkspace()` is called, not changed),
`app/Library/Usage/BillingProfileManager.php` (Contract 09's file —
consumed as a schema/consent-flow prerequisite only, never modified by
this contract).

**Flagged, not allowlisted:** whatever file holds the connected-Stripe-
account reference (§3) — confirm at implementation time before assuming
no file needs touching.

## 13. Required tests

`AgencyBusinessMigrationV1Test.php`: every §3 table's "preserved
automatically" claim, individually asserted (not just trusted); §5's
three payer cases, each with its own test — the `business` case (no
change), the `workspace`-under-Agency case (relationship created, payer
converted to `agency_rebill` with **both consent columns `NULL`**, zero
new paid activity possible — proven by attempting a charge against that
Business immediately after migration and asserting it fails closed with
`no_provider_customer`-equivalent, never silently succeeding against
either the old or new Workspace's instrument), and the no-assignment-row
preflight-failure case; **step-order regression**: assert plan assignment
happens before `reassignBusiness()` is called (a direct test that
`WorkspacePlanUnassignedException` is never thrown during a real
migration run); **relationship-before-payer-decision regression**: assert
the Contract 01 relationship row exists and is `Active` before the payer
matrix step ever runs for that Business; §3's primary-location repair —
both "already has one, no duplicate created" and "genuinely none, one
created" branches; §7's resumability (interrupt mid-Agency, rerun, assert
exactly the remaining Businesses are processed, not the already-done ones
again — including a Business already converted to pending-`agency_rebill`
being correctly skipped, not reprocessed or double-converted); dry-run
mode makes zero writes.

## 14. Acceptance criteria

1. Every §3 entity's preservation claim is proven by test, not assumed.
2. §5's payer matrix executes to completion for every case, including
   `workspace`-under-Agency — **no case blocks the whole migration**, and
   the pending-consent state fails closed at charge time, proven by test.
3. The corrected step order (§4) is proven by test — no
   `WorkspacePlanUnassignedException`, and the relationship exists before
   the payer decision runs.
4. Resumability proven by test, including the pending-`agency_rebill`
   case's own idempotency.
5. Zero provider calls made by this migration, proven by test (mock/spy
   on any Stripe/Telnyx client asserting zero invocations).
6. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not retire `additional_business_slot_agreements` (Contract 11,
strictly after this one). Does not touch non-Agency multi-Business
Workspaces (Contract 12). Does not enforce the DB 1:1 constraint
(Contract 13). Does not rewrite historical `view_as_sessions` rows
(§3, explicit decision). Does not attempt an automated rollback (§8).

## 16. Merge prerequisites

Contracts 01, 02, 04, 07, 08A, and **09** (hard, per Roadmap A4/A5 —
`AgencyRebill` must already be a legal target and Contract 09's own
`agency_rebill_consented_at`/`_by_user_id` columns and consent flow must
already exist for this migration's §5 conversion and the Agency owner's
follow-up confirmation to both be possible).

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 01, 07, 08A, 09 | consumed, not modified | Serialize (prerequisites) |
| Contract 11 | reads the same Agency Workspaces this migration touches | **Serialize**, strictly after |
| Contract 12 | different Workspace population (non-Agency) | Safe concurrent once both are ready, but Roadmap orders them serially anyway |

## 18. Implementation prompt

```
You are implementing Slice 10 of the V1 architecture migration for the
os-creator1/os-ai repository: Agency data migration, per docs/product/
implementation-contracts/10-AGENCY-DATA-MIGRATION.md -- the deepest and
highest-risk contract in this series. Treat every step with the caution
this repository's own AGENTS.md/CLAUDE.md route-3 governance requires for
data migrations: no production-looking database, explicit human
authorization for any real run, dry-run before execution.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contracts 01, 02, 04, 07, 08A, and 09 are ALL merged to main --
   every one is a hard prerequisite. If any is missing, STOP and report.
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-10-agency-data-migration).
4. Re-read the full contract, especially SS4's corrected six-step
   sequence (plan assignment before reassignment; relationship creation
   before the payer decision) and SS5's executable payer matrix -- both
   are authoritative and must not be re-derived, reordered, or
   second-guessed. The step order in SS4 fixes two real bugs found in an
   earlier draft (a capacity-check failure and a chicken-and-egg
   dependency) -- do not revert to a simpler-looking order.
5. Re-verify SS3's inventory against actual current main: confirm
   WorkspaceManager::reassignBusiness(), EntitlementManager::
   assertCanCreateAnotherBusiness()'s WorkspacePlanUnassignedException
   behavior, and WorkspaceMembershipBusinessRepository::
   removeAllForBusinessInWorkspace() still exist with the signatures this
   contract assumes; confirm Contract 09's agency_rebill_consented_at/
   _by_user_id columns exist on business_payer_assignments (SS5's hard
   dependency); locate the connected-Stripe-account reference this
   contract flagged as unconfirmed. If anything differs, STOP and report
   before proceeding.

Implement exactly the scope in this contract: the Artisan command with
--dry-run, the AgencyBusinessMigrationV1 class reusing reassignBusiness()
and removeAllForBusinessInWorkspace() verbatim, the full preflight/dry-
run/execution/verification/resumability posture in SS8, and SS5's
payer-conversion behavior (immediate agency_rebill conversion with both
consent columns left NULL -- never a halt of the whole Business's
migration, never a fabricated consent timestamp). Do NOT modify
WorkspaceManager or WorkspaceMembershipBusinessRepository. Do NOT
implement an automated rollback -- SS8 explicitly says this migration
does not provide one. Do NOT run this migration against any real or
production-looking database yourself -- this contract authorizes building
the tool, not running it against live data.

After implementing:
- Run the new focused test file, covering every SS3 preservation claim,
  every SS5 payer-matrix case individually (including the fail-closed
  charge attempt against a pending-consent AgencyRebill Business), and
  the SS13 step-order regression tests.
- Run the broader Workspace-domain regression.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files (plus
  whatever the Stripe-reference confirmation in step 5 required).
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, what you found for the connected-Stripe-account reference,
and explicit proof from your tests that (a) every automatic-preservation
claim holds, (b) the payer-matrix conversion makes zero writes to already-
consented state and fails closed for new charges, and (c) the migration is
resumable. Do NOT begin or authorize Contract 11 or any other later slice,
and do NOT run this migration against any real data.
```
