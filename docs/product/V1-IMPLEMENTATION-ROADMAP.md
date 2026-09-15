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
- **Objective:** extend authorization so any authorized Agency team member (owner, admin, or a permitted staff member — Blueprint §2, §28; View As is not owner-only) can act on a linked Client Workspace via the Slice 1 relationship; extend `ViewAsManager` to resolve a viewed Business across Workspaces through that relationship instead of only `businessesForWorkspace($workspace)`.
- **Why here:** the Blueprint's View As guarantee (§28, §32) and every later Agency-facing slice need this before any client-facing Agency UI is safe to build.
- **Sections:** Blueprint §2, §28, §32; Addendum §2, §6 (View As).
- **Code domains:** `app/Library/ViewAs/ViewAsManager.php`, `WorkspaceManager::userCanAccessBusiness()` (add an explicit second entry point rather than changing its existing single-Workspace contract).
- **Schema impact:** none beyond Slice 1's table.
- **Tenancy/security impact:** **High** — this is the seam identified as the single hardest rewrite in the prior architecture audit; changes must be additive (new cross-Workspace path alongside the existing same-Workspace path, not a replacement) until Slice 14 retires the old path.
- **Billing impact:** none directly.
- **Prerequisites:** Slice 1.
- **Blocks:** Slices 4, 5, 7.
- **Concurrent with:** Slice 3 (different subsystem), not with Slice 1 (hard dependency).
- **Migration/backfill:** none.
- **Adversarial test themes:** an Agency actor without an active relationship row must be refused, not merely unlisted; an Agency team member without ordinary Agency-management permission (e.g. a permission-less Staff row) must be refused even with an active relationship, proving the check composes normal Agency-Workspace authorization *with* the relationship rather than substituting for it; a terminated relationship must immediately lose View As and management authority while historical audit stays intact (Addendum §2); an Agency Admin/Staff member must be independently proven unable to reach AgencyRebill consent/payer actions through the View As session (Addendum §10 — View As is a lens, never an escalation path); the pre-existing same-Workspace View As path must still work unchanged for Core/Growth.
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
- **Tenancy/security impact:** Medium — provisioning requires ordinary Agency-management permission (owner, admin, or permitted staff — Blueprint §2, §28; not owner-only, per Addendum §2's silence on who *creates* a relationship versus its explicit owner/Platform-Owner-only *termination* rule) plus the actor's own Agency Workspace authority (Slice 2); an Agency team member with no Agency-management permission at all must still be refused.
- **Billing impact:** Low — client's own plan/trial starts per §6/§27, independent of the Agency; if provisioning also assigns a resold SaaS plan billed through money lane C (Addendum §12), that specific sub-step may carry its own narrower authority rule to be confirmed against SaaS Plans (§28) when that surface is specified — provisioning the Workspace/Business/Location itself is not owner-only.
- **Prerequisites:** Slices 1, 2.
- **Blocks:** Slice 8.
- **Concurrent with:** Slices 3, 4, 6 if not already merged; otherwise Slice 11/13 (independent product modules).
- **Migration/backfill:** none.
- **Adversarial test themes:** an Agency team member with no Agency-management permission cannot provision a client even though they belong to the Agency Workspace; an actor from an unrelated Agency Workspace cannot provision a client under an Agency it has no relationship with.
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
- **Acceptance criteria:** (a) any authorized Agency team member (owner, admin, or permitted staff) can list/open/View-As every linked client, while an Agency team member without Agency-management permission is refused; (b) every Location-bound controller has an adversarial cross-Location-ID test.
- **Complexity:** XL (b is the larger share). **Risk:** High. **Lane:** A for (a), B for (b) — run concurrently as two lanes.

### Slice 9 — AgencyRebill activation
- **Objective:** add the two missing RFC-005 §16 consent rules and activate `PayerType::AgencyRebill` under them (Addendum §10): only the managing Agency Workspace owner may set/revoke it or configure funding, gated on an active Slice 1 relationship, using the existing standing-consent mechanism.
- **Why here:** structurally requires Slice 1 (relationship) and benefits from Slice 5 (Agency/Client effective-access composition) already existing, since every automated Agency-funded effect must check both.
- **Sections:** Blueprint §20, §28; Addendum §10.
- **Code domains:** `app/Enums/Usage/PayerType.php` (no change needed, already defined), `BillingProfileManager::changePayer()`, `UsageBillingCheckoutManager`, RFC-005 §16 consent table (doc update alongside code).
- **Schema impact:** **not none** — confirmed via `database/migrations/2026_08_16_130006_create_business_payer_assignments_table.php`: `business_payer_assignments` today is `id, business_id (unique FK -> businesses, restrictOnDelete), payer_type string(16), effective_payment_instrument_id (nullable, unsignedBigInteger, no FK yet), timestamps` — there is no column anywhere on this table capable of recording *which* Agency Workspace is paying. Add one nullable `managing_agency_relationship_id` (FK to the Slice 1 relationship table, `restrictOnDelete`), with an application-enforced invariant: NULL when `payer_type` is `business`/`workspace`; NOT NULL when `payer_type = 'agency_rebill'`, and its value MUST reference an *active* Slice 1 relationship row whose `client_workspace_id` resolves to this Business's own Workspace. This is the single source of truth for "which Agency is paying" — no second column or table may duplicate it, and no code path may accept an arbitrary Workspace ID in its place.
- **Tenancy/security impact:** none beyond existing payer-consent pattern.
- **Billing impact:** Critical — this is real money movement; every existing wallet/cap/entitlement/STOP-DND/provider-readiness/idempotency check must apply unchanged (Addendum §10).
- **Prerequisites:** Slice 1 (hard); Slice 5 (recommended).
- **Blocks:** Slice 10 (now a hard prerequisite — Slice 10's payer migration matrix requires `AgencyRebill` to already be a legal target).
- **Concurrent with:** Slice 8, Slice 11.
- **Migration/backfill:** none — inert until this slice, no existing data to migrate.
- **Adversarial test themes:** Agency Admin/Staff, Client owner/staff, and Platform Administrator must each be independently proven unable to originate AgencyRebill consent or charges (Addendum §10); revocation must block only new activity while ledgering already-incurred costs.
- **Acceptance criteria:** full RFC-005 §16-style consent/charge-authority test matrix for the new payer type, mirroring the existing `workspace`/`business` coverage.
- **Complexity:** L. **Risk:** Critical. **Lane:** C (serial after Slice 1/5).

### Slice 10 — Agency data migration (existing multi-Business Agency accounts)
- **Objective:** for every existing Agency Workspace holding multiple Businesses today, move each non-primary Business into its own new Client Workspace (reassigning the *existing* Business row, never creating a duplicate) and establish an active Slice 1 relationship — preserving every existing `BusinessLocation`, payer, wallet, and history record exactly as identified below, rather than recreating them.
- **Why here:** needs Slices 1, 2, 7, 8(a) **and 9** merged and stable — Slice 9 is now a hard prerequisite (not merely "benefits from"), because the payer-migration matrix below requires `PayerType::AgencyRebill` to already exist as a legal target.
- **Sections:** Addendum §18 step 4; Blueprint §35.
- **Code domains:** one-shot migration command, not a schema migration file — mirrors the caution already applied to `WorkspaceBackfillV1` (RFC-003 §27's own migration-authoring policy: query-builder based, not mutable Eloquent events).
- **Schema impact:** none new; heavy data movement across existing tables.
- **Tenancy/security impact:** Critical during the migration window — must be transactional per Agency, resumable, and never leave a Business without a Workspace.
- **Billing impact:** Critical. See the **payer migration matrix** below — this is money-movement logic, not a mechanical re-key.

**A4 — Location handling (mechanically verified against `app/Models/BusinessLocation.php` and `database/migrations/2026_07_18_120002_create_business_locations_table.php`):** `business_locations.business_id` is a plain `constrained('businesses')->onDelete('cascade')` FK with no `NOT NULL`-style guarantee that a Business has at least one row, and `is_primary` carries only a plain index, not a unique constraint — the "exactly one primary" invariant is enforced at the application layer only, by `BusinessLocationManager`. The migration therefore **MUST NOT** create a new Primary Location for a Business that already has one. Exact rule:
1. Reassign the existing Business's `workspace_id` to the new Client Workspace (via the existing `WorkspaceManager::reassignBusiness()`-style write, or its Slice-14-era successor) — every existing `business_locations` row, `is_primary` flag, and lifecycle/archived state (`BusinessLocationLifecycleState::Active|Archived`) stays untouched, since these rows key on `business_id`, not `workspace_id`, and the Business's own `id` is preserved.
2. Only if `BusinessLocationRepository::findPrimary($business)` returns null for that Business (a genuinely primary-less legacy row) does the migration invoke the existing, already-authoritative repair path — `BusinessLocationManager::upsertPrimaryLocation()` — and only to *create*, never to overwrite an existing primary's fields with migration-invented data (this method's own documented behavior: "edits the primary location, or — only when the Business has none — creates it"). This is the same method organic onboarding already uses; the migration must not invent a second creation path.
3. No duplicate Location is ever created. Preflight reports the count of primary-less Businesses found so a human can review before any repair runs.

**A5 — Payer migration matrix (mechanically verified against `database/migrations/2026_08_16_130006_create_business_payer_assignments_table.php` and the M2 backfill migration `2026_08_16_130008_backfill_business_payer_assignments.php`):** `business_payer_assignments` is keyed `business_id unique`, so it already travels with the Business unchanged by the Workspace move — but its **meaning** depends on which Workspace it resolves against, which is exactly what moving the Business changes. The M2 backfill's own documented default (M2 contract §6.E) was `payerType = tier === 'agency' ? 'business' : 'workspace'` — i.e. for an Agency-tier Workspace, the *default* was self-pay (`business`), and a `payer_type = 'workspace'` row on a Business under an Agency Workspace is exactly the case where the **Agency's own shared Workspace funding was paying for that specific client's usage** — a real, human-consented `BillingProfileManager::changePayer()` action (RFC-005 §16), not a default. Since the default may since have been explicitly changed, the migration **MUST read each Business's actual current `payer_type`, never assume the backfill default still holds.**

| Current state found | Meaning under the OLD model | Safe migration action |
|---|---|---|
| `payer_type = 'business'` | Self-pay — the Business's own instrument pays | **No change.** Row is already correct for the new Client Workspace; `business_id` FK is unaffected by the Workspace move. |
| `payer_type = 'workspace'`, under an Agency-tier Workspace, for a **non-primary** Business (i.e. a client) | The Agency's shared Workspace funding was paying for this client's usage — functionally identical to what `AgencyRebill` now exists to represent | **STOP condition, not a silent conversion.** Moving the Business to its own Client Workspace would silently flip this row's meaning to "the *client's own new* Workspace pays" — the opposite of the original intent, and a real payer change requires the managing Agency owner's own explicit, reasoned consent (Addendum §10), which a batch migration cannot manufacture on a human's behalf. The migration must list every such Business, **halt that Business's split**, and require the Agency owner to explicitly re-establish `AgencyRebill` consent (through the ordinary Slice 9 consent flow, now pointed at the new Slice 1 relationship) before the split proceeds for that specific Business. |
| `payer_type = 'workspace'`, under a non-Agency Workspace | Ordinary Core/Growth self-pay via the single Workspace (already 1:1) | Not applicable to this slice — these Workspaces are not being split. |
| No `business_payer_assignments` row at all | Should not occur (M2 backfill is complete per `AI-AUTONOMY-STATE.json`'s RFC-005 closure note) | Preflight failure — treat as a data-integrity stop condition, not a case to default-assign silently. |

This table is the migration's **entire** payer-handling logic — no additional inference is permitted. **Do not guess** for any row that does not exactly match one of the rows above; add it to the preflight report as unresolved and stop rather than choosing.

- **Prerequisites:** Slices 1, 2, 7, 8(a), **9**.
- **Blocks:** Slice 11.
- **Concurrent with:** none — **SERIAL ONLY**, run against one Agency account at a time with verification between batches.
- **Migration/backfill:** this slice *is* the migration; see A4/A5 above for its two most consequential rules. Preflight report **MUST** enumerate, per Agency Workspace: Business count, primary-less Business count (A4), and payer-migration-matrix classification per Business (A5) — before any write runs.
- **Adversarial test themes:** a migration interrupted mid-Agency must be safely resumable, not leave a half-migrated Agency with some clients in the old shape and some in the new; a `payer_type = 'workspace'` client Business must never complete its split until its Agency-funding consent is explicitly re-established; a primary-less Business must never end up with two primaries after repair.
- **Acceptance criteria:** zero Agency Workspaces with >1 Business remain; every migrated client passes the same acceptance checks as an organically Slice-7-provisioned client; every pre-migration `business_payer_assignments` row's economic meaning (who actually pays) is provably unchanged post-migration, or the Business's split was correctly halted pending consent.
- **Complexity:** XL. **Risk:** Critical. **Lane:** SERIAL ONLY.

### Slice 11 — Retire additional-business-slots purchase flow
- **Objective:** freeze new sales of `additional_business_slots`/`additional_business_slot_agreements` (Addendum §18 step 5, Blueprint §35). **This is a conditional migration/operations gate, not an open architecture decision** — the architecture (retire the Nth-Business-slot model) is already frozen by the Addendum regardless of what current data holds; only the treatment of any already-*paid* holder is conditional on data reality, and is reconciled below rather than left as a blocker.
- **Why here:** must follow Slice 10 — cannot retire the mechanism Agency accounts still depend on before they're migrated off it.
- **Sections:** Blueprint §21, §35; Addendum §17 (RFC-004 §13/§17 superseded), §18.
- **Code domains:** `EntitlementManager::assertCanCreateAnotherBusiness()`/`setAdditionalBusinessSlots()`/`allocateAdditionalBusinessSlotsFromVerifiedPayment()`, the checkout routes.
- **Schema impact:** none removed yet (Slice 13 does removal); this slice stops new writes only.
- **Tenancy/security impact:** none.
- **Billing impact:** High, conditional on preflight below.

**A7 — reconciling "no blocking decision" with existing paid holders:** `SlotAgreementState` (`app/Enums/Usage/SlotAgreementState.php`) shows `additional_business_slot_agreements.state` reaches `Completed` for a fully paid, allocated slot agreement — the state that represents a genuine existing commercial commitment. The correct posture, matching the "architecture-locked either way" instruction:
1. **Preflight, before this slice writes anything:** `SELECT COUNT(*) FROM additional_business_slot_agreements WHERE state = 'completed' AND cancellation_effective_at IS NULL` (a completed agreement that has not already had its cancellation take effect).
2. **Zero rows found:** no commercial-treatment decision is needed — freeze new sales and proceed; this is the expected case for a pre-launch or low-volume product state and requires no human sign-off beyond the freeze itself.
3. **One or more rows found:** **STOP this slice** before disabling renewal/entitlement for those specific Workspaces. Report the exact list (Workspace, current allocation, next renewal date) and require an explicit, separately authorized commercial decision (refund, grandfather until natural expiry, or convert) **before** proceeding — this is the one piece of this slice that is genuinely a business decision, not an architecture one, and it must never be invented by an implementation agent.
- **Prerequisites:** Slice 10.
- **Blocks:** Slice 12.
- **Concurrent with:** Slice 9 if not already landed, and any pure product-module slice (§14/§15/§18/§19 style work).
- **Migration/backfill:** none beyond Slice 10's; the preflight count above is the only new inspection this slice requires.
- **Adversarial test themes:** an existing holder's already-purchased slots must not silently vanish before the human-decided treatment executes; a zero-row preflight must not require any human sign-off to proceed with the freeze.
- **Acceptance criteria:** no new `additional_business_slots` purchase is possible; if the preflight found zero active agreements, the flow is frozen outright; if it found any, the slice halts at the report and does not silently choose a treatment.
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
Before any implementation lane starts, confirm that the Addendum, the
Blueprint, the Traceability Matrix, this Roadmap, and the Acceptance Matrix
are all merged to `main` — not merely pushed to a planning branch. This is a
standing precondition to re-check at the start of every wave, not a
one-time fact about when this Roadmap was written.

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

### WAVE 3a
Slice 8(a) has a **hard dependency** on Slice 7's provisioning
contract/API, not ordinary concurrency — it must not begin consuming that
interface until Slice 7 is merged and stable. Wave 3 is therefore split
into two sub-waves rather than one wave with an in-flight hand-off.

| Lane | Work |
|---|---|
| A | Slice 7 — Client Workspace provisioning (needs Wave2 A) |
| C | Slice 9 — AgencyRebill activation (needs Wave1 A, Wave2 C) — independent of Slice 7, safe to run alongside it |
| D–F | Continue/finish any Wave 1–2 product-module overflow, or start further independent modules as the Blueprint requires |

**Serial prerequisites:** Wave 2 fully merged. **Merge barrier:** Slice 2
and Slice 5 both stable on `main`. **Verification gate:** Slice 7's
provisioning contract/API is merged and its own acceptance criteria pass
before Wave 3b's Lane B may start.

### WAVE 3b
| Lane | Work |
|---|---|
| B | Slice 8(a) — Agency "Clients" UI (starts only after Wave 3a Lane A/Slice 7 is merged — not concurrent with it) |
| A, C, D–F | Continue Wave 3a work if not yet finished, or move to independent product modules |

**Serial prerequisites:** Slice 7 merged (hard gate for Lane B specifically;
other lanes are not blocked by it). **Merge barrier:** none beyond Slice 7
itself. **Verification gate:** an authorized Agency team member can
provision a client, View As it, and (owner-only, per Addendum §10) fund its
usage — full end-to-end coverage before Wave 4.

### WAVE 4 — Data migration (mostly serial)
| Lane | Work |
|---|---|
| A (SERIAL ONLY) | Slice 10 — Agency data migration |
| E, F | Continue independent product modules unaffected by the migration |

**Serial prerequisites:** Wave 3a/3b's Slices 7, 8(a), and **9 (AgencyRebill,
now a hard prerequisite per Slice 10's payer migration matrix)** all merged
and stable. **Merge barrier:** freeze further changes to
`WorkspaceManager`/relationship tables/`business_payer_assignments` while
Slice 10 runs. **Verification gate:** zero Agency Workspaces with >1
Business remain; every migrated client passes Slice 7's own acceptance
checks; every pre-migration payer row's economic meaning is provably
unchanged or its Business's split was correctly halted pending consent
(Slice 10 A5).

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
