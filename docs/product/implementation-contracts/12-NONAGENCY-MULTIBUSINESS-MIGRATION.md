# Implementation Contract 12 — Non-Agency Multi-Business Migration

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contract 11 being merged first.

## 1. Objective

Backfill/split any remaining Core/Growth (non-Agency) Workspace still
holding more than one Business — **after first proving, via a real data
report, whether any exist at all**, not assuming "rare/zero" (deep-dive
requirement, explicit).

## 2. Governing authority

- Addendum §18 step 6.
- Roadmap Slice 12.
- Contract 10 (the migration mechanics this slice reuses for its own,
  simpler case).

## 3. Current repository reality

**Why "rare/zero" is a hypothesis, not a fact, and must be checked, not
assumed:** the traceability matrix's original finding was that
`workspace_plan_catalog`'s Core/Growth rows are seeded with
`business_slot_included = 1, business_slot_max = 1` **per a documented
prior correction** (`docs/automation/CUSTOMER-EXPERIENCE-MANAGED-
MESSAGING-AUTOMATIONS-CONTRACT.md` §7.4, cited in the original session
audit) — but this is an **entitlement-layer** constraint on *creating a
new* Business, enforced by `EntitlementManager::assertCanCreateAnotherBusiness()`.
It says nothing about:
- Workspaces that acquired a second Business **before** that correction
  shipped (grandfathered data);
- Workspaces where `additional_business_slots` was set to a non-zero
  value in error or via an admin override, and a Core/Growth Workspace
  therefore did legitimately create additional Businesses under the old
  rules;
- any data-integrity anomaly outside the entitlement layer's own
  enforcement (a direct DB write, a bug, a backfill artifact).

**No column or count of "how many non-Agency Workspaces currently have
>1 Business" was queried in this evidence pass** — that is precisely
this slice's own first deliverable (§8), not a fact this contract already
has.

## 4. Delta from current state to target

**Step 1 (this slice's actual first action): a read-only data report** —
`SELECT workspace_id, COUNT(*) FROM businesses GROUP BY workspace_id
HAVING COUNT(*) > 1`, then joined against `workspace_plan_catalog.tier` to
exclude Agency-tier Workspaces (already handled by Contract 10) and
report the remainder.

**Step 2, conditional on Step 1's result:**
- **Zero non-Agency Workspaces found with >1 Business:** this slice is
  complete after the report — nothing to migrate, and this fact itself
  should be recorded (so Contract 13's own preflight can cite it rather
  than re-deriving it from scratch).
- **One or more found:** migrate each using the **same** mechanics
  Contract 10 already built (`reassignBusiness()`,
  `removeAllForBusinessInWorkspace()`, primary-location repair-if-none,
  and — per the same Workspace-creation-needs-a-plan-first ordering
  Contract 10's own §4 corrected — `EntitlementManager::assignFirstPlan()`
  assigning the new Workspace a fresh `Core`, complimentary,
  zero-additional-slot plan, mechanically identical to Contract 10's own
  Client Workspace treatment; the SOURCE Workspace's own existing plan
  assignment is never touched), but **without** Contract 01's Agency
  relationship step (these are ordinary Workspaces, not Agencies) — each
  non-primary Business gets its own new plain Workspace, no relationship
  row. The payer matrix (Roadmap A5) does not apply here either —
  non-Agency `payer_type = 'workspace'` rows are, per the M2 backfill
  default (Phase A A5's own citation), already the *expected*
  self-pay-via-the-single-Workspace case, and since each split Workspace
  ends up with exactly one Business, the payer assignment row is **never
  written by this slice**. The correct invariant is: the payer
  TYPE/economic rule remains "the Workspace *containing this Business*
  pays" (`EffectivePayerResolver::fromAssignment()` resolves
  `PayerType::Workspace` via the Business's own, current `workspace_id`
  at read time) — after reassignment, that is the **newly created**
  Workspace, not the original one. Saying "the same Workspace still pays"
  is inaccurate: no Workspace "still" pays anything here, since the
  Business itself no longer sits in the original Workspace at all — no
  halt condition is needed for this slice's case, unlike Contract 10's
  Agency case, and no payer row of any kind is ever rewritten.

## 5. Data model contract

No new table/column — same schema Contract 10 already uses. This slice's
only new deliverable is the Step-1 report query and, conditionally, reuse
of Contract 10's migration mechanics at a smaller scope.

## 6. Authority / security contract

Same as Contract 10 §6 — an operator-run command, not end-user-facing.

## 7. Transaction / concurrency boundary

Same per-Workspace transaction shape as Contract 10 §7, scoped to
whichever specific Workspaces Step 1's report actually names.

## 8. Migration / backfill — full posture (deep-dive requirement)

1. **Data report (Step 1 above)** — run first, always, regardless of
   expectation.
2. **Dry-run** (only if Step 1 found any) — mirrors Contract 10 §8's
   dry-run shape.
3. **Execution** — per-Workspace transaction, reusing Contract 10's core
   reassignment mechanics minus the Agency-relationship step.
4. **Verification** — assert zero non-Agency Workspaces remain with >1
   Business.
5. **Resumability** — same discipline as Contract 10 §7.
6. **Rollback/backout** — same no-automated-rollback posture as Contract
   10 §8, same reasoning.

## 9. Backwards compatibility

If Step 1 finds zero rows, this slice makes **no** behavioral change to
`main` at all beyond adding the reusable report command itself — a
genuinely possible, not merely convenient, outcome.

## 10. Events / audit

Same event reuse as Contract 10 §10 (`BusinessReassignedToWorkspace`,
`WorkspaceMembershipBusinessUnassigned`, `WorkspaceCreated`), minus
Contract 01's relationship event (not applicable here).

## 11. Billing/provider safety

Lower risk than Contract 10 — no payer-matrix halt case applies (§4). No
provider call is made, same as Contract 10 §11.

## 12. Exact implementation allowlist

**New files:**
- `app/Console/Commands/ReportNonAgencyMultiBusinessWorkspaces.php` — Step
  1's report, an independently runnable, read-only command with no
  dependency on any migration decision. It never claims to be running
  against any particular environment (local/test vs. real target); it
  reports the environment/connection/database it is actually connected
  to and leaves verification to the operator.
- `app/Library/Workspace/Migration/NonAgencyBusinessSplitV1.php` (only
  exercised if Step 1 finds rows) — the authoritative service; owns every
  migration decision (candidate selection, payer classification,
  transaction boundary, verification).
- `app/Console/Commands/MigrateNonAgencyMultiBusinessWorkspaces.php` — the
  thin operator-facing wrapper around `NonAgencyBusinessSplitV1`'s own
  `preflight()`/`run()`, mirroring Contract 10's
  `MigrateAgencyClientBusinesses` pattern (§6/§8's same operator-run
  command posture: preflight/dry-run/execute modes, a required real
  `--operator`, never a fabricated system actor). It owns **no** business
  logic of its own — it resolves CLI options, calls the service, and
  prints its report.
- `tests/Feature/Workspace/NonAgencyMultiBusinessReportTest.php`
- `tests/Feature/Workspace/NonAgencyBusinessSplitV1Test.php`

**No existing production file modified** — this slice, like Contract 10,
reuses `WorkspaceManager`/`WorkspaceMembershipBusinessRepository`/
`EntitlementManager` unchanged. (The three new files above are all new,
not modifications to any existing file.)

## 13. Required tests

`NonAgencyMultiBusinessReportTest.php`: correctly excludes Agency-tier
Workspaces; correctly reports a genuine non-Agency multi-Business
Workspace if one exists in test fixtures; zero-row case reports cleanly.

`NonAgencyBusinessSplitV1Test.php`: mirrors Contract 10's own test shape
at this simpler scope — reassignment preserves every `business_id`-keyed
table (same inventory as Contract 10 §3, re-asserted here since this is a
distinct code path even though it shares mechanics); no relationship row
is created (confirming this slice correctly excludes Contract 01's step);
`workspace`-type payer assignments are provably unaffected — the
assignment row itself is never rewritten, and the economic rule "the
Workspace containing this Business pays" continues to resolve correctly
for both outcomes of the split: the retained primary Business (whose
containing Workspace is unchanged, the original source Workspace, now
holding only that one Business) and each moved Business (whose
containing Workspace is now its own newly created one) —
`EffectivePayerResolver` proves both cases from the Business's current
`workspace_id`, never from a rewritten payer row.

Focused coverage for `MigrateNonAgencyMultiBusinessWorkspaces` (the
operator wrapper) proves only wrapper-level concerns, not the migration
algorithm itself (already proven above): preflight/dry-run remain
zero-write; `--execute` reaches the real service and migrates a genuine
seeded candidate; a missing `--operator` fails clearly for write modes;
an invalid `--workspace` uid fails; any `blocked`/`failed`/
`verification_failed` Workspace status fails the command's exit code; a
clean no-candidate run succeeds.

## 14. Acceptance criteria

1. The Step-1 report is proven accurate against a seeded fixture with a
   real non-Agency multi-Business Workspace (not merely "zero found in an
   empty test DB," which would prove nothing).
2. If any Workspace is found, every Contract-10-style preservation claim
   holds for the simpler non-Agency case too.
3. Zero non-Agency Workspaces with >1 Business remain after this slice.
4. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not touch Agency Workspaces (Contract 10's scope). Does not enforce
the DB 1:1 constraint (Contract 13). Does not assume its own outcome —
the report in §4 Step 1 must actually run and be reported, even if this
contract's own hypothesis (near-zero) turns out correct.

## 16. Merge prerequisites

Contract 11 merged (Roadmap ordering — Contract 12 follows Contract 11 so
a customer isn't mid-purchase-flow for a slot while a split is running).

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 10 | shares reused mechanics (`WorkspaceManager`/`WorkspaceMembershipBusinessRepository`), different Workspace population | Safe concurrent in principle; Roadmap orders them serially for operational simplicity, not because of a real code conflict |
| Contract 13 | this slice's zero-remaining-violations result is one of Contract 13's own preflight inputs | **Serialize**, strictly before |

## 18. Implementation prompt

```
You are implementing Slice 12 of the V1 architecture migration for the
os-creator1/os-ai repository: non-Agency multi-Business migration, per
docs/product/implementation-contracts/12-NONAGENCY-MULTIBUSINESS-
MIGRATION.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contract 11 is merged -- hard prerequisite. If missing, STOP
   and report.
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-12-nonagency-multibusiness-migration).
4. Re-read the full contract, especially SS4's two-step design -- report
   first, migrate only if the report finds something. Do not skip
   straight to writing migration logic on the assumption the report will
   find nothing.
5. Re-verify WorkspaceManager::reassignBusiness() and
   WorkspaceMembershipBusinessRepository::removeAllForBusinessInWorkspace()
   still exist with the signatures Contract 10 (and this contract) assume.
   If they've changed, STOP and report.

Implement exactly the scope in this contract: the report command (build
and run it against your test database as part of your own verification,
and include its actual output in your report), and the conditional
migration logic only if warranted. Do NOT touch any Agency Workspace. Do
NOT enforce any DB constraint (Contract 13). Do NOT modify WorkspaceManager
or WorkspaceMembershipBusinessRepository.

After implementing:
- Run the new focused test files, including a fixture that seeds a real
  non-Agency multi-Business Workspace to prove the report and migration
  actually work, not just that they run without error on empty data.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, and the actual report output from your test database (or
explicitly state it was run only against seeded fixtures, not real data).
Do NOT begin or authorize Contract 13 or any other later slice.
```
