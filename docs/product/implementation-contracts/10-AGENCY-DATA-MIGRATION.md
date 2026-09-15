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

For each Business being migrated, in one transaction (§7):
1. Create the Client Workspace (`WorkspaceManager::createWorkspace()`, unchanged).
2. Reassign the Business's `workspace_id` — **reuse
   `WorkspaceManager::reassignBusiness()` itself**, not a new bespoke
   write, since it already performs exactly the required sub-steps in the
   right order: locks both Workspaces (ascending ID), asserts capacity on
   the target (harmlessly satisfied — a brand-new empty Workspace always
   has capacity), calls
   `WorkspaceMembershipBusinessRepository::removeAllForBusinessInWorkspace()`
   (the exact cleanup row 4 of §3's table needs), writes the
   `workspace_transitions` row, dispatches `BusinessReassignedToWorkspace`.
   **This is the single biggest reuse opportunity this contract found** —
   the general-purpose method the old architecture already built for
   "move a Business between two ordinary Workspaces" does almost
   everything this migration's per-Business core step needs, with zero
   modification.
3. Assign a plan to the new Client Workspace (Contract 07's own
   provisioning shape — not a copy of the Agency's plan).
4. Run the primary-location repair check (§3, `BusinessLocation` row —
   only if genuinely absent).
5. Resolve and, if needed, halt on the payer matrix (§5).
6. Create the Contract 01 relationship (Agency Workspace → new Client
   Workspace), via Contract 01's manager, actor = the human operator
   running this migration (recorded honestly as the actor, not a system
   user pretending to be the Agency owner — see §10).

**Explicitly does NOT change:** any table row keyed on `business_id`
(§3's "automatic" rows) — this migration's writes are narrowly: the
Business's own `workspace_id`, one new Workspace row, one new plan
assignment row, `workspace_membership_businesses` deletions (via the
reused method), one new relationship row, and — only for the flagged
payer case — a `business_payer_assignments` update.

## 5. Payer migration matrix (restated precisely from Roadmap A5, the authoritative source — not re-derived, operationalized here)

| Current `business_payer_assignments.payer_type` | Action |
|---|---|
| `business` | No change — self-pay, `business_id` FK unaffected by the Workspace move. |
| `workspace`, under the Agency-tier Workspace, for this (non-primary/client) Business | **Halt this Business's migration.** Report it (Workspace, current allocation, next renewal if relevant) for the Agency owner to explicitly re-establish `AgencyRebill` consent via Contract 09's flow, pointed at the **new** relationship, before this Business's migration proceeds. **Do not** silently convert to `agency_rebill` (no consent actor) and **do not** silently leave as `workspace` (would silently flip to self-pay once the Business moves — the exact "must not silently change who pays" failure this whole matrix exists to prevent). |
| No assignment row at all | Preflight failure — data-integrity stop condition (should not occur; M2 backfill is documented complete). |

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

1. **Preflight report** (read-only, no writes): per Agency Workspace —
   Business count, primary-less Business count (§3's `BusinessLocation`
   check), and §5's payer-matrix classification per Business. Output as a
   structured report a human reviews before any execution run.
2. **Dry-run mode**: executes every read and every decision branch (§4's
   steps 1–6) without committing writes, reporting exactly what *would*
   happen per Business, including which Businesses would halt on §5's
   payer case.
3. **Execution**: per-Agency transaction (§7), real writes, resumable.
4. **Verification**: post-run, assert zero remaining Businesses under the
   processed Agency Workspace(s) other than the Agency's own primary one;
   assert every migrated Business's `business_payer_assignments` row is
   either unchanged (`business` case) or was correctly halted-and-reported
   (`workspace` case) — never silently converted.
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

**Existing files NOT modified:** `WorkspaceManager.php` (its existing
`reassignBusiness()` is called, not changed), `WorkspaceMembershipBusinessRepository.php`
(its existing `removeAllForBusinessInWorkspace()` is called, not changed).

**Flagged, not allowlisted:** whatever file holds the connected-Stripe-
account reference (§3) — confirm at implementation time before assuming
no file needs touching.

## 13. Required tests

`AgencyBusinessMigrationV1Test.php`: every §3 table's "preserved
automatically" claim, individually asserted (not just trusted); §5's
three payer cases, each with its own test, including the halt case
producing a report entry and **zero** write to that Business's payer
assignment; §3's primary-location repair — both "already has one, no
duplicate created" and "genuinely none, one created" branches; §7's
resumability (interrupt mid-Agency, rerun, assert exactly the remaining
Businesses are processed, not the already-done ones again); §9 dry-run
mode makes zero writes.

## 14. Acceptance criteria

1. Every §3 entity's preservation claim is proven by test, not assumed.
2. §5's payer matrix is fully implemented with a real halt-and-report
   path for the `workspace`-under-Agency case — zero silent conversions.
3. Resumability proven by test.
4. Zero provider calls made by this migration, proven by test (mock/spy
   on any Stripe/Telnyx client asserting zero invocations).
5. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not retire `additional_business_slot_agreements` (Contract 11,
strictly after this one). Does not touch non-Agency multi-Business
Workspaces (Contract 12). Does not enforce the DB 1:1 constraint
(Contract 13). Does not rewrite historical `view_as_sessions` rows
(§3, explicit decision). Does not attempt an automated rollback (§8).

## 16. Merge prerequisites

Contracts 01, 02, 04, 07, 08A, and **09** (hard, per Roadmap A4/A5 —
the payer matrix's halt case requires `AgencyRebill`'s consent flow to
already exist for the Agency owner to use).

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
4. Re-read the full contract, especially SS3's entity inventory and SS5's
   payer matrix -- both are authoritative and must not be re-derived or
   second-guessed.
5. Re-verify SS3's inventory against actual current main: confirm
   WorkspaceManager::reassignBusiness() and WorkspaceMembershipBusiness
   Repository::removeAllForBusinessInWorkspace() still exist with the
   signatures this contract assumes; locate the connected-Stripe-account
   reference this contract flagged as unconfirmed. If anything differs,
   STOP and report before proceeding.

Implement exactly the scope in this contract: the Artisan command with
--dry-run, the AgencyBusinessMigrationV1 class reusing reassignBusiness()
and removeAllForBusinessInWorkspace() verbatim, the full preflight/dry-
run/execution/verification/resumability posture in SS8, and the payer
matrix's halt-and-report behavior in SS5 with zero silent conversions.
Do NOT modify WorkspaceManager or WorkspaceMembershipBusinessRepository.
Do NOT implement an automated rollback -- SS8 explicitly says this
migration does not provide one. Do NOT run this migration against any
real or production-looking database yourself -- this contract authorizes
building the tool, not running it against live data.

After implementing:
- Run the new focused test file, covering every SS3 preservation claim
  and every SS5 payer-matrix case individually.
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
claim holds, (b) the payer-matrix halt case makes zero writes, and (c) the
migration is resumable. Do NOT begin or authorize Contract 11 or any other
later slice, and do NOT run this migration against any real data.
```
