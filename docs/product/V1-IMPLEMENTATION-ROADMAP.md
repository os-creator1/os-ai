# V1 Implementation Roadmap

**Status:** Planning document only. This is **not** implementation
authorization — no slice below may start without its own separate,
explicit human authorization under this repository's route-3 governance
(`CLAUDE.md`). Built from
[`V1-MASTER-PRODUCT-BLUEPRINT.md`](./V1-MASTER-PRODUCT-BLUEPRINT.md) and
[`V1-AUTHORITY-TRACEABILITY-MATRIX.md`](./V1-AUTHORITY-TRACEABILITY-MATRIX.md)
against current `main`, honoring the dependency order already locked in
[`../rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md`](../rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md)
§18. **The DB-level Workspace:Business 1:1 constraint (Slice 12) is never
enforced early** — every slice before it is ordered specifically to make
that safe.

## How to read a slice

Each slice states what it does and why it sits where it does in dependency
order; it does not restate product requirements already in the Blueprint or
architecture rules already in the Addendum — both are cited, not repeated.

---

## Slice catalog

### Slice 1 — Agency↔Client Workspace relationship (foundation)
- **Objective:** add the canonical relationship table/model (Addendum §2): `agency_workspace_id`, `client_workspace_id`, explicit lifecycle/status, created/ended timestamps, audit history.
- **Why here:** everything Agency-facing in the Blueprint (§2, §28) depends on this existing first; it is purely additive.
- **Sections:** Blueprint §2, §28; Addendum §2, §18 step 1.
- **Code domains:** new model + migration under `app/Models`/`database/migrations`; a new manager class (e.g. `AgencyClientRelationshipManager`) rather than adding methods to `WorkspaceManager`, to avoid contention with Slice 3/9's edits to that file.
- **Schema impact:** new table only. No existing table altered.
- **Tenancy/security impact:** none yet — relationship exists but nothing consumes it for authorization until Slice 2.
- **Billing impact:** none.
- **Prerequisites:** none (Addendum merged).
- **Blocks:** Slices 2, 4, 5, 10.
- **Concurrent with:** Slices 3, 6.
- **Migration/backfill:** none — net-new table, empty at creation.
- **Adversarial test themes:** relationship cannot be created pointing a Client Workspace at itself or at more than one active Agency simultaneously; client cannot end its own relationship (Addendum §2).
- **Acceptance criteria:** relationship CRUD exists behind the new manager; ended relationships are never hard-deleted; no existing route/controller changed.
- **Complexity:** M. **Risk:** Low. **Lane:** A.

### Slice 2 — Cross-Workspace Agency authorization + View As extension
- **Objective:** extend authorization so an Agency Workspace owner/admin can act on a linked Client Workspace via the Slice 1 relationship; extend `ViewAsManager` to resolve a viewed Business across Workspaces through that relationship instead of only `businessesForWorkspace($workspace)`.
- **Why here:** the Blueprint's View As guarantee (§28, §32) and every later Agency-facing slice need this before any client-facing Agency UI is safe to build.
- **Sections:** Blueprint §28, §32; Addendum §2, §6 (View As).
- **Code domains:** `app/Library/ViewAs/ViewAsManager.php`, `WorkspaceManager::userCanAccessBusiness()` (add an explicit second entry point rather than changing its existing single-Workspace contract).
- **Schema impact:** none beyond Slice 1's table.
- **Tenancy/security impact:** **High** — this is the seam identified as the single hardest rewrite in the prior architecture audit; changes must be additive (new cross-Workspace path alongside the existing same-Workspace path, not a replacement) until Slice 5 retires the old path.
- **Billing impact:** none directly.
- **Prerequisites:** Slice 1.
- **Blocks:** Slices 4, 5, 7.
- **Concurrent with:** Slice 3 (different subsystem), not with Slice 1 (hard dependency).
- **Migration/backfill:** none.
- **Adversarial test themes:** an Agency actor without an active relationship row must be refused, not merely unlisted; a terminated relationship must immediately lose View As and management authority while historical audit stays intact (Addendum §2); the pre-existing same-Workspace View As path must still work unchanged for Core/Growth.
- **Acceptance criteria:** both View As paths pass their respective test suites; no regression in `ViewAsRouteBoundaryTest`-style coverage.
- **Complexity:** L. **Risk:** Critical. **Lane:** A (serial after Slice 1).

### Slice 3 — Location ACL foundation
- **Objective:** add `location_access_scope` (All\|Selected) and the canonical equivalent of `workspace_membership_locations`, per Addendum §4, as a sibling authority to Business tenancy — new table/model, new authorization class, not a change to `WorkspaceMembership.business_access_scope`.
- **Why here:** independent of Agency work; purely additive; needed before Slice 8 wires it into every Location-bound controller.
- **Sections:** Blueprint §4, §26; Addendum §4.
- **Code domains:** new model/migration; new `LocationAccessGuard`-style class (mirroring the re-derive-from-repository pattern already used by `WorkspaceManager::userCanAccessBusiness()`, per Addendum §4).
- **Schema impact:** new table only.
- **Tenancy/security impact:** none yet — additive, not wired into any controller until Slice 8.
- **Billing impact:** none.
- **Prerequisites:** none.
- **Blocks:** Slice 8.
- **Concurrent with:** Slices 1, 6.
- **Migration/backfill:** none.
- **Adversarial test themes:** knowing a Location-bound record's ID must not bypass a Selected-scope staff member's grant (Addendum §4) — this is a fail-closed default, tested before any consumer exists.
- **Acceptance criteria:** grant CRUD exists; owner-always-all-Locations rule enforced; no controller changed yet.
- **Complexity:** M. **Risk:** Low. **Lane:** B.

### Slice 4 — Account lifecycle: Grace/Locked derivation
- **Objective:** add `grace_started_at`/`locked_at` timestamps to `workspace_plan_assignments`; extend `CustomerAccountAccessResolver` to derive Trial/Active/Grace/Locked/Inactive from the existing base status plus these timestamps (Addendum §7) — no second resolver.
- **Why here:** independent of Agency/Location work; needed before Slice 5 can compose Agency-upstream eligibility (Addendum §8), since that composition needs Grace/Locked to exist as derivable states first.
- **Sections:** Blueprint §27; Addendum §7.
- **Code domains:** migration on `workspace_plan_assignments`; `app/Library/Entitlement/CustomerAccountAccessResolver.php`.
- **Schema impact:** two nullable timestamp columns, additive.
- **Tenancy/security impact:** Medium — every existing gate consuming the resolver's output must be re-verified against the new derived states, since Grace changes from "not modeled" to "modeled but still permits full access."
- **Billing impact:** none directly (lifecycle only, not money movement).
- **Prerequisites:** none.
- **Blocks:** Slice 5 (Agency-eligibility composition needs Grace/Locked to exist).
- **Concurrent with:** Slices 1, 3, 6.
- **Migration/backfill:** existing `Suspended` rows are **not** reinterpreted as Grace/Locked (Addendum §7) — no backfill needed, timestamps simply start null.
- **Adversarial test themes:** Grace must still grant full access (not silently equivalent to Locked); Suspended must remain distinct and never derived from Grace/Locked timestamps.
- **Acceptance criteria:** every existing `CustomerAccountAccessGate`/`CustomerAccountAccessApiGate`/`CustomerAccountAccessGuard` test still passes unmodified in behavior for the existing three states, plus new coverage for Grace/Locked.
- **Complexity:** M. **Risk:** Medium. **Lane:** C.

### Slice 5 — Agency non-payment composition
- **Objective:** compose Agency-Workspace effective access as an upstream prerequisite for its linked Client Workspaces, without mutating any Client Workspace's own lifecycle state (Addendum §8) — extend `CustomerAccountAccessResolver`'s inputs, not a second authority.
- **Why here:** needs Slice 1 (relationship) and Slice 4 (Grace/Locked) both merged.
- **Sections:** Blueprint §28; Addendum §8.
- **Code domains:** `CustomerAccountAccessResolver` (extend `resolveForContext()` or add one new composed input).
- **Schema impact:** none beyond Slices 1 and 4.
- **Tenancy/security impact:** High — a composition bug here either leaks paid access to a delinquent Agency's clients or wrongly locks a paying client.
- **Billing impact:** High — this is the mechanism the money-flow risk in the Addendum's own B3 analysis identified.
- **Prerequisites:** Slices 1, 4.
- **Blocks:** none directly, but should land before Slice 9 (AgencyRebill) since AgencyRebill's standing-consent checks reference both Client and Agency effective access (Addendum §10).
- **Concurrent with:** Slice 2 is a different subsystem but shares the relationship table — land Slice 2 first or coordinate closely if run concurrently.
- **Migration/backfill:** none.
- **Adversarial test themes:** Client B must remain unaffected when Client A or the Agency itself is delinquent; Client Workspace's own row must never be written by this composition, only read.
- **Acceptance criteria:** `CustomerAccountAccessResolver` test suite covers all combinations of (Client state × Agency state).
- **Complexity:** M. **Risk:** High. **Lane:** C (serial after Slice 4; coordinate with Slice 2 on shared relationship table).

### Slice 6 — Conversation Location-scoping
- **Objective:** make `ChatBox`/`ChatBoxMessage` Location-bound (Blueprint §11, Addendum §5) — currently confirmed user-scoped, not Business/Location-scoped (Navigation Contract §11.2).
- **Why here:** fully independent of Agency/lifecycle work; can start immediately; several guided-automation recipes are already blocked on this per the Navigation Contract's own finding, so it has value on its own critical path too.
- **Sections:** Blueprint §11, §13; Addendum §5.
- **Code domains:** `app/Models/ChatBox.php`, `ChatBoxMessage.php`, `ChatBoxController`, inbound/outbound number routing.
- **Schema impact:** add `location_id` to the relevant tables.
- **Tenancy/security impact:** Medium — must compose with Location ACL (Slice 3/8) once that lands, but can ship its own Location binding first.
- **Billing impact:** none directly.
- **Prerequisites:** none.
- **Blocks:** the Reply-to-new-lead automation recipe reaching guided-catalogue readiness (Navigation Contract §11.2), and Slice 8's Location-ACL wiring for this module.
- **Concurrent with:** Slices 1, 3, 4.
- **Migration/backfill:** backfill existing conversations' `location_id` from their Contact's Location (Addendum §5) — a one-time, reversible-where-realistic data migration.
- **Adversarial test themes:** inbound number determines Location correctly even when a Contact has moved Locations previously (Addendum §5 transfer-preserves-history rule, §9 analog).
- **Acceptance criteria:** every Conversation resolves exactly one Location; STOP/DND enforcement unaffected.
- **Complexity:** L. **Risk:** Medium. **Lane:** D.

### Slice 7 — Client Workspace provisioning flow
- **Objective:** build the "Agency creates a new client" flow that provisions a full Workspace + Business + Primary Location and establishes the Slice 1 relationship in one operation (Blueprint §6, §28).
- **Why here:** needs Slices 1 and 2 merged and stable.
- **Sections:** Blueprint §6, §28; Addendum §1, §2.
- **Code domains:** new Agency-facing controller/flow; reuses existing signup provisioning logic (`BusinessOnboardingController`/`WorkspaceManager::createWorkspace()`) rather than duplicating it.
- **Schema impact:** none new.
- **Tenancy/security impact:** Medium — must not allow provisioning a Client Workspace without an Agency actor holding relationship-management authority (Slice 2).
- **Billing impact:** Low — client's own plan/trial starts per §6/§27, independent of the Agency.
- **Prerequisites:** Slices 1, 2.
- **Blocks:** Slice 8.
- **Concurrent with:** Slices 3, 4, 6 if not already merged; otherwise Slice 11/13 (independent product modules).
- **Migration/backfill:** none.
- **Adversarial test themes:** a non-owner Agency team member without relationship authority cannot provision a client (Addendum §2).
- **Acceptance criteria:** provisioning a client produces a Workspace indistinguishable in shape from an organic Core/Growth signup, plus one active relationship row.
- **Complexity:** M. **Risk:** Medium. **Lane:** A (serial after Slice 2).

### Slice 8 — Agency "Clients" UI + Location ACL consumer wiring
- **Objective:** two related but separable efforts landed together for coordination efficiency: (a) the Agency "Clients" list/management screen (Blueprint §28) against Slice 1/2/7; (b) wire Slice 3's Location ACL into Contacts/Opportunities/Calendar/Conversations controllers (Blueprint §26, Addendum §4).
- **Why here:** (a) needs Slice 7; (b) needs Slice 3, and benefits from Slice 6 already having landed Location-bound Conversations.
- **Sections:** Blueprint §4, §26, §28.
- **Code domains:** (a) new Agency UI/controller; (b) every controller currently checking Business tenancy for a Location-bound record.
- **Schema impact:** none new.
- **Tenancy/security impact:** High for (b) — this is the point where "knowing a record ID must never bypass Location authorization" (Addendum §4) actually gets enforced for the first time; every touched controller needs adversarial ID-guessing coverage.
- **Billing impact:** none.
- **Prerequisites:** (a) Slice 7; (b) Slice 3 (and Slice 6 recommended first).
- **Blocks:** none further, but should land before Slice 12 (DB enforcement) since it's part of "consumers migrated" per Addendum §18.
- **Concurrent with:** these two efforts can run as separate lanes (different files) within the same wave.
- **Migration/backfill:** for (b), existing staff `business_access_scope = Selected` memberships need an equivalent Location grant seeded so nobody's access silently widens or narrows on cutover.
- **Adversarial test themes:** (b) is exactly the repeat of the Task-3-style multi-resource-route ID-mismatch defense already proven necessary once this session for Business tenancy — the same class of test must be written per Location-bound controller.
- **Acceptance criteria:** (a) an Agency owner can list/open/View-As every linked client; (b) every Location-bound controller has an adversarial cross-Location-ID test.
- **Complexity:** XL (b is the larger share). **Risk:** High. **Lane:** A for (a), B for (b) — run concurrently as two lanes.

### Slice 9 — AgencyRebill activation
- **Objective:** add the two missing RFC-005 §16 consent rules and activate `PayerType::AgencyRebill` under them (Addendum §10): only the managing Agency Workspace owner may set/revoke it or configure funding, gated on an active Slice 1 relationship, using the existing standing-consent mechanism.
- **Why here:** structurally requires Slice 1 (relationship) and benefits from Slice 5 (Agency/Client effective-access composition) already existing, since every automated Agency-funded effect must check both.
- **Sections:** Blueprint §20, §28; Addendum §10.
- **Code domains:** `app/Enums/Usage/PayerType.php` (no change needed, already defined), `BillingProfileManager::changePayer()`, `UsageBillingCheckoutManager`, RFC-005 §16 consent table (doc update alongside code).
- **Schema impact:** none new — `business_payer_assignments.payer_type` already supports the value; add the FK/invariant tying an `agency_rebill` assignment to the specific relationship row from Slice 1 (Addendum §10 Q3 invariant).
- **Tenancy/security impact:** none beyond existing payer-consent pattern.
- **Billing impact:** Critical — this is real money movement; every existing wallet/cap/entitlement/STOP-DND/provider-readiness/idempotency check must apply unchanged (Addendum §10).
- **Prerequisites:** Slice 1 (hard); Slice 5 (recommended).
- **Blocks:** none.
- **Concurrent with:** Slice 8, Slice 11.
- **Migration/backfill:** none — inert until this slice, no existing data to migrate.
- **Adversarial test themes:** Agency Admin/Staff, Client owner/staff, and Platform Administrator must each be independently proven unable to originate AgencyRebill consent or charges (Addendum §10); revocation must block only new activity while ledgering already-incurred costs.
- **Acceptance criteria:** full RFC-005 §16-style consent/charge-authority test matrix for the new payer type, mirroring the existing `workspace`/`business` coverage.
- **Complexity:** L. **Risk:** Critical. **Lane:** C (serial after Slice 1/5).

### Slice 10 — Agency data migration (existing multi-Business Agency accounts)
- **Objective:** for every existing Agency Workspace holding multiple Businesses today, create a Client Workspace + Primary Location per Business and an active Slice 1 relationship row, then repoint payer/wallet/history to the new Client Workspace without losing it.
- **Why here:** needs Slices 1, 2, 7, 8(a) all merged and stable — this is the point where real (if test/staging) data moves.
- **Sections:** Addendum §18 step 4; Blueprint §35.
- **Code domains:** one-shot migration command, not a schema migration file — mirrors the caution already applied to `WorkspaceBackfillV1` (RFC-003 §27's own migration-authoring policy: query-builder based, not mutable Eloquent events).
- **Schema impact:** none new; heavy data movement across existing tables.
- **Tenancy/security impact:** Critical during the migration window — must be transactional per Agency, resumable, and never leave a Business without a Workspace.
- **Billing impact:** Critical — existing wallets/payers (`business_id`-scoped already, per traceability matrix row 17) move with the Business, requiring no re-keying, which is exactly why Addendum §9's "wallet already Business-scoped" finding matters here.
- **Prerequisites:** Slices 1, 2, 7, 8(a).
- **Blocks:** Slice 11.
- **Concurrent with:** none — **SERIAL ONLY**, run against one Agency account at a time with verification between batches.
- **Migration/backfill:** this slice *is* the migration.
- **Adversarial test themes:** a migration interrupted mid-Agency must be safely resumable, not leave a half-migrated Agency with some clients in the old shape and some in the new.
- **Acceptance criteria:** zero Agency Workspaces with >1 Business remain; every migrated client passes the same acceptance checks as an organically Slice-7-provisioned client.
- **Complexity:** XL. **Risk:** Critical. **Lane:** SERIAL ONLY.

### Slice 11 — Retire additional-business-slots purchase flow
- **Objective:** freeze new sales of `additional_business_slots`/`additional_business_slot_agreements` and decide existing-holder treatment (Addendum §18 step 5, Blueprint §35).
- **Why here:** must follow Slice 10 — cannot retire the mechanism Agency accounts still depend on before they're migrated off it.
- **Sections:** Blueprint §21, §35; Addendum §17 (RFC-004 §13/§17 superseded), §18.
- **Code domains:** `EntitlementManager::assertCanCreateAnotherBusiness()`/`setAdditionalBusinessSlots()`/`allocateAdditionalBusinessSlotsFromVerifiedPayment()`, the checkout routes.
- **Schema impact:** none removed yet (Slice 13 does removal); this slice stops new writes only.
- **Tenancy/security impact:** none.
- **Billing impact:** High — existing paid holders need an explicit, human-decided treatment (refund, grandfathering, or conversion), not a silent freeze.
- **Prerequisites:** Slice 10.
- **Blocks:** Slice 12.
- **Concurrent with:** Slice 9 if not already landed, and any pure product-module slice (§14/§15/§18/§19 style work).
- **Migration/backfill:** none beyond Slice 10's.
- **Adversarial test themes:** an existing holder's already-purchased slots must not silently vanish before the human-decided treatment executes.
- **Acceptance criteria:** no new `additional_business_slots` purchase is possible; existing holders' treatment is executed per the separate human decision this slice defers to, not invented here.
- **Complexity:** M. **Risk:** Medium. **Lane:** SERIAL ONLY (billing-sensitive).

### Slice 12 — Backfill remaining non-Agency multi-Business Workspaces
- **Objective:** any Core/Growth Workspace still holding >1 Business (expected to be rare/zero per the traceability matrix's plan-catalog finding) gets backfilled the same way as Slice 10, minus the Agency relationship step.
- **Why here:** must follow Slice 11 to avoid a customer buying a slot for a Workspace about to be split.
- **Sections:** Addendum §18 step 6.
- **Code domains:** same migration-command pattern as Slice 10.
- **Schema impact:** none new.
- **Tenancy/security impact:** Medium.
- **Billing impact:** Low (expected small/zero population per traceability matrix row 20).
- **Prerequisites:** Slice 11.
- **Blocks:** Slice 13.
- **Concurrent with:** none — **SERIAL ONLY**.
- **Migration/backfill:** this slice *is* the migration.
- **Adversarial test themes:** same resumability requirement as Slice 10.
- **Acceptance criteria:** zero Workspaces with >1 Business remain anywhere.
- **Complexity:** M. **Risk:** High. **Lane:** SERIAL ONLY.

### Slice 13 — Enforce DB-level Workspace:Business 1:1
- **Objective:** add the unique constraint on `businesses.workspace_id` (Addendum §1, §18 step 7).
- **Why here:** the very last schema step, only safe once Slices 10 and 12 have verified zero remaining multi-Business Workspaces.
- **Sections:** Addendum §1, §18.
- **Code domains:** one migration.
- **Schema impact:** the constraint itself.
- **Tenancy/security impact:** none if Slices 10/12 verified clean; Critical if run early (this is exactly why it's last).
- **Billing impact:** none directly.
- **Prerequisites:** Slices 10, 12, verified zero-remaining-violations immediately before running.
- **Blocks:** Slice 14.
- **Concurrent with:** none — **SERIAL ONLY**.
- **Migration/backfill:** a pre-flight assertion (zero violating rows) is part of the migration itself, mirroring RFC-003's own `enforce_business_workspace_constraint` migration's pattern of asserting zero NULLs/dangling FKs before tightening.
- **Adversarial test themes:** the migration must fail loudly (not silently skip) if a violating row exists at run time.
- **Acceptance criteria:** constraint live; `WorkspaceManager::createBusinessInWorkspace()` for an existing Workspace now fails at the DB layer as a final backstop even if application code has a bug.
- **Complexity:** S. **Risk:** Critical (moment of enforcement). **Lane:** SERIAL ONLY.

### Slice 14 — Dead-model cleanup
- **Objective:** remove `createBusinessInWorkspace()`'s existing-Workspace path, `reassignBusiness()`, `SwitchBusinessAction`, the Business-switcher UI, `workspace_membership_businesses`, `additional_business_slot_*` tables, and the same-Workspace-only View As path now fully superseded by Slice 2 (Addendum §18 step 8).
- **Why here:** only safe once nothing depends on the old shape — last slice.
- **Sections:** Addendum §18; Blueprint §35.
- **Code domains:** the files named above.
- **Schema impact:** table drops.
- **Tenancy/security impact:** Low if truly dead by this point; verify via a repo-wide reference search before deleting.
- **Billing impact:** none (Slice 11 already handled the billing wind-down).
- **Prerequisites:** Slice 13.
- **Blocks:** none.
- **Concurrent with:** independent product-module slices can continue alongside cleanup.
- **Migration/backfill:** table drops only after confirming zero rows/zero references.
- **Adversarial test themes:** none — this is deletion, not new behavior; run the full focused regression on every touched area instead.
- **Acceptance criteria:** repo-wide search confirms zero remaining references before each deletion.
- **Complexity:** M. **Risk:** Low. **Lane:** A or B, either is fine at this point.

### Slices 15–18 — Independent product modules (Blueprint §12, §14, §15, §17, §18, §22, §23)
These are **not tenancy/security/money seams** and do not depend on Slices
1–14 except for using Location/Business scoping that already exists today.
They may start as soon as a lane is free and should not be serialized
against the Agency/lifecycle work above:

| Slice | Module | Blueprint § | Complexity | Risk | Notes |
|---|---|---|---|---|---|
| 15 | Calendar / Booking Types / Availability | §12 | XL | Medium | Net-new (traceability matrix row 9); build Location-bound from the start |
| 16 | Packages & Products catalog | §17 | L | Low | Net-new (row 14); Business-wide catalog + Location override + immutable snapshot together from the start, not retrofitted |
| 17 | Proposal / Contract / e-signature | §18 | XL | Medium | Net-new on top of existing Invoice/Payment (row 15); depends on Slice 16 for package snapshots |
| 18 | SEO expansion (GBP, Citations, Reviews, technical SEO) | §15 | L | Low | Existing Keywords module extended (row 12) |

Each may be its own lane, concurrent with everything in Waves 1–5 below,
since none touch `WorkspaceManager`, `CustomerAccountAccessResolver`,
`EntitlementManager`, or the Agency relationship table.

---

## FASTEST SAFE BUILD PLAN

Six lanes assumed (A–F). Foundational tenancy/security/money seams are
serialized where a real conflict exists; independent product modules run
concurrently from Wave 1 onward. No wave assigns two lanes to the same
central file in the same wave.

### WAVE 0 — Gate
Confirm the Addendum and Blueprint are merged to `main` (already true as of
this roadmap). No code lane starts before this.

### WAVE 1
| Lane | Work |
|---|---|
| A | Slice 1 — Agency↔Client relationship |
| B | Slice 3 — Location ACL foundation |
| C | Slice 4 — Account lifecycle Grace/Locked |
| D | Slice 6 — Conversation Location-scoping |
| E | Slice 15 — Calendar/Booking (net-new, independent) |
| F | Slice 16 — Packages & Products (net-new, independent) |

**Serial prerequisites:** none beyond Wave 0. **Merge barrier:** all six
land on `main` independently as they finish (no need to wait for the
slowest lane — these are non-overlapping files). **Verification gate:**
each lane's own focused tests pass; no shared-file conflicts expected, but
run a full-repo `git diff --check` and migration dry-run before any Wave 2
lane starts consuming a Wave 1 table.

### WAVE 2
| Lane | Work |
|---|---|
| A | Slice 2 — Cross-Workspace Agency authorization + View As (needs A/Wave1) |
| B | Slice 8(b) — Location ACL consumer wiring (needs B/Wave1, benefits from D/Wave1) |
| C | Slice 5 — Agency non-payment composition (needs A+C/Wave1) |
| D | (Slice 6 overflow / hardening if not finished in Wave 1) |
| E | Slice 17 — Proposal/Contract/e-signature (needs F/Wave1's package snapshots) |
| F | Slice 18 — SEO expansion (independent) |

**Serial prerequisites:** Wave 1 Lanes A and C merged before Wave 2 Lane A
starts; Wave 1 Lane C (lifecycle) merged before Wave 2 Lane C starts. **Merge
barrier:** Wave 1 fully green on `main` before Wave 2 Lane A/C begin (both
read schema Wave 1 just added). **Verification gate:** Slice 2's adversarial
View As tests and Slice 5's lifecycle-composition matrix both pass before
Wave 3 begins.

### WAVE 3
| Lane | Work |
|---|---|
| A | Slice 7 — Client Workspace provisioning (needs Wave2 A) |
| B | Slice 8(a) — Agency "Clients" UI (needs A/Wave3 in progress — coordinate hand-off) |
| C | Slice 9 — AgencyRebill activation (needs Wave1 A, Wave2 C) |
| D–F | Continue/finish any Wave 1–2 product-module overflow, or start further independent modules as the Blueprint requires |

**Serial prerequisites:** Wave 2 fully merged. **Merge barrier:** Slice 2
and Slice 5 both stable on `main`. **Verification gate:** an Agency owner
can provision a client, View As it, and (if configured) fund its usage —
full end-to-end coverage before Wave 4.

### WAVE 4 — Data migration (mostly serial)
| Lane | Work |
|---|---|
| A (SERIAL ONLY) | Slice 10 — Agency data migration |
| E, F | Continue independent product modules unaffected by the migration |

**Serial prerequisites:** Wave 3 Lanes A/B/C merged and stable. **Merge
barrier:** freeze further changes to `WorkspaceManager`/relationship tables
while Slice 10 runs. **Verification gate:** zero Agency Workspaces with >1
Business remain; every migrated client passes Slice 7's own acceptance
checks.

### WAVE 5 — Retirement and enforcement (serial)
| Lane | Work |
|---|---|
| A (SERIAL ONLY) | Slice 11 → Slice 12 → Slice 13, in strict order |
| E, F | Independent product modules continue |

**Serial prerequisites:** Slice 10 verified complete. **Merge barrier:**
each of Slice 11/12/13 is its own merge barrier for the next — Slice 13
must not start until Slice 12's zero-violation check passes. **Verification
gate:** the DB constraint is live and a deliberate attempt to violate it
fails at the database layer, not just in application code.

### WAVE 6 — Cleanup
| Lane | Work |
|---|---|
| A or B | Slice 14 — dead-model cleanup |
| Others | Continue any remaining product modules (§19 messaging depth, §22 niche blueprint versioning, §23 AI COO) |

**Serial prerequisites:** Slice 13 merged. **Merge barrier:** none further —
this is the tail of the migration, not a new foundation. **Verification
gate:** repo-wide reference search returns zero hits for each deleted
symbol/table before its deletion lands.

---

## Notes for a coordinator issuing prompts from this roadmap

- Never assign two lanes to `WorkspaceManager.php`, `CustomerAccountAccessResolver.php`, or the Slice 1 relationship model in the same wave — every slice above that touches one of these three is explicitly serialized against the others that do.
- Slices 15–18 (and any further independent product modules the Blueprint names) are the safe default filler for any lane with spare capacity in any wave — they carry no tenancy/security/billing risk relative to the Agency/lifecycle work.
- Do not start Slice 9 (AgencyRebill) before Slice 1 is merged — Addendum §10's own invariant requires the relationship to exist first.
- Do not start Slice 13 (DB enforcement) before Slices 10 and 12 both report zero remaining violations — this is the one constraint the entire roadmap is organized to protect.
