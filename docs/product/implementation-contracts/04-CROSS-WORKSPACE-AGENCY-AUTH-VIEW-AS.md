# Implementation Contract 04 — Cross-Workspace Agency Authorization / View As

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contract 01 (Agency↔Client relationship) being merged first.

## 1. Objective

Add a **new**, additive cross-Workspace View As path so an authorized
Agency team member can view a linked Client Workspace's Business through
the Contract 01 relationship — without changing the existing same-Workspace
View As path at all (that path is retired only in Contract 14). This is
the single hardest rewrite identified across every prior audit pass in
this project: `ViewAsManager` today is structurally same-Workspace-only,
not merely conventionally so.

## 2. Governing authority

**Corrected citations (the original draft cited a stale "Addendum §6" for
the View-As-preserves-tenancy/wallet/STOP-DND guarantee — §6 is actually
"Location downgrade/archive," unrelated; re-verified against the merged
Addendum's real section list below):**
- Addendum §2 (Agency↔Client Workspace relationship — the authorization
  link this slice's cross-Workspace path is built on).
- Addendum §3 (Global User identity — "authorization belongs to the active
  Workspace context... never leaks or inherits across Workspaces," the
  isolation guarantee View As must not violate).
- Blueprint §28 (Agency Product — states the View As product guarantee:
  "preserves the viewed client's normal tenancy, security, wallet, and
  STOP/DND rules exactly as if the client were using it themselves") and
  §32 (Security and Audit — restates the same guarantee as a cross-cutting
  security rule, and "real acting person" audit requirement).
- Blueprint §2 (corrected: View As is not owner-only — any active Agency
  team member qualifies; see §6's implementation-time authority correction).
- Roadmap Slice 2 (as corrected in the Phase A pass).
- Contract 01 (the relationship this slice consumes).

## 3. Current repository reality

**`app/Library/ViewAs/ViewAsManager.php`** (full 256-line file read):
- `start(User $actor, string $workspaceUid, string $businessUid, ?string
  $reason)`: looks up the Workspace, checks `actorMayView()`, then resolves
  the Business via `$this->workspaceRepository->businessesForWorkspace($workspace)
  ->firstWhere('uid', $businessUid)` — **structurally same-Workspace-only**;
  there is no code path to look up a Business belonging to a different
  Workspace.
- `actorMayView(int $actorId, $workspace)`: `workspace.owner_user_id ===
  $actorId` OR an active membership with `role === WorkspaceMembershipRole::Admin`
  — **Staff is categorically excluded**, even with permissions. This is the
  existing same-Workspace admin-viewing-a-Business-in-their-own-Workspace
  authority (i.e., today's Agency owner/admin viewing one of the many
  Businesses still living inside their own Agency Workspace, under the
  pre-Addendum model) — a different scenario from the new cross-Workspace
  case this contract adds.
- `accessChainStillHolds(ViewAsSession $session, User $actor)`: re-checks,
  on **every** read via `current()`: Workspace active; Business active AND
  `business.workspace_id === session.workspace_id` (hard equality); actor
  still passes `actorMayView()`; actor still passes
  `WorkspaceManager::userCanAccessBusiness()`.
- **Critical finding: `userCanAccessBusiness()` cannot be reused for the
  cross-Workspace case at all.** Its full body (read in this and a prior
  pass) derives the Business's own Workspace from `business.workspace_id`
  and then checks ordinary tenancy against *that* Workspace (owner,
  or active membership with Business-access-scope) — an Agency actor is
  correctly **never** the Client Workspace's owner or member (Addendum §2:
  authority must never be inferred from Client-Workspace membership), so
  this method would always — correctly, from an ordinary-tenancy
  standpoint, but wrongly for this feature — return `false` for a
  legitimate Agency View As. The cross-Workspace path needs its **own**
  validity check built on the Contract 01 relationship, not this method.
- **`app/Models/ViewAsSession.php`** / migration
  (`database/migrations/..._create_view_as_sessions_table.php`, full file
  read): `actor_user_id`, `workspace_id` (**cascadeOnDelete**, unlike most
  of this schema's `restrictOnDelete` convention elsewhere — noted, not
  changed here), `business_id` (also `cascadeOnDelete`), `reason`,
  `started_at`/`expires_at`/`ended_at`/`end_reason`, `refusals` (JSON).
  `workspace_id` today conflates two concepts that are the same Workspace
  in the old model but **different** Workspaces in the new one: "the
  Workspace being viewed" and "the Workspace whose membership grants the
  actor authority." This conflation must be resolved by a new column
  (§5), not by redefining what `workspace_id` means.
- `END_REASON_*` constants on `ViewAsSession`: `EXIT | EXPIRED | LOGOUT |
  REPLACED | ACCESS_LOST` — this slice adds one more.
- `ViewAsContext` (readonly DTO, full file read): `sessionId, uid,
  actorUserId, actorDisplayName, workspaceId, workspaceUid, businessId,
  businessUid, businessName, startedAt, expiresAt` — no Agency-specific
  field yet.
- `WorkspaceManager::userCanAccessBusiness()` (re-confirmed, full body):
  the method this slice must **not** call for the cross-Workspace
  authorization decision (see above) — still correct and unchanged for the
  existing same-Workspace path.

## 4. Delta from current state to target

**Changes:** `ViewAsManager` gains a new public entry point (see §5's
signature design) for cross-Workspace Agency View As, built alongside
`start()`/`accessChainStillHolds()`, not replacing them; `ViewAsSession`
gains one new nullable column; `ViewAsContext` gains one new nullable
field; one new `END_REASON_*` constant.

**Explicitly does NOT change:** `start()`, `actorMayView()`,
`accessChainStillHolds()`'s existing same-Workspace logic, or any of their
existing test coverage — the existing path is fully preserved byte-for-byte
until Contract 14 retires it. `WorkspaceManager::userCanAccessBusiness()`
is read, never modified — the Roadmap's original note about "an explicit
second entry point on `WorkspaceManager`" is **corrected here**: no change
to `WorkspaceManager` is needed at all, since the cross-Workspace check is
built entirely from the Contract 01 relationship, not from
`userCanAccessBusiness()`.

## 5. Data model contract

**`view_as_sessions` — one new column:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `viewing_agency_workspace_id` | `unsignedBigInteger`, FK → `workspaces.id`, `restrictOnDelete()` (deliberately not `cascadeOnDelete`, unlike the table's other two FKs — an Agency Workspace being deleted must not silently cascade-delete audit history) | Yes | `NULL` | `NULL` for the existing same-Workspace path (unchanged meaning); set to the Agency Workspace's ID for the new cross-Workspace path. `workspace_id` continues to mean "the Workspace being viewed" in both cases — never redefined. |

**Two new `END_REASON_*` constants on `ViewAsSession`, kept distinct
because they have different root causes and different operational
responses** (relationship termination is an Agency-initiated or
Platform-Owner-initiated action; entitlement loss is a billing/plan
event):
- `END_REASON_RELATIONSHIP_ENDED = 'relationship_ended'` — the Contract 01
  relationship itself is terminated mid-session.
- `END_REASON_AGENCY_ENTITLEMENT_LOST = 'agency_entitlement_lost'` — the
  relationship is still `Active`, but the Agency Workspace has lost
  management eligibility: its plan tier is no longer
  `WorkspacePlanTier::Agency`, **or** its effective account is Locked,
  Inactive or Suspended per `CustomerAccountAccessResolver` (§6's
  eligibility check). No separate lifecycle end reason exists.

Both are distinct from `ACCESS_LOST`, which remains for the ordinary
same-Workspace tenancy-revoked case, so reporting/audit can distinguish
all three root causes.

**`ViewAsContext` — one new nullable field:** `viewingAgencyWorkspaceId`
and `viewingAgencyWorkspaceUid` (mirroring the existing
`workspaceId`/`workspaceUid` pairing), both `null` for the same-Workspace
path.

**New method signature, `startAgencyView()` (not an overload of `start()`
— a deliberately separate, explicitly-named method so the two
authorization paths can never be confused at a call site):**
```php
public function startAgencyView(
    User $actor,
    string $agencyWorkspaceUid,
    string $clientWorkspaceUid,
    ?string $reason = null,
): ViewAsSession
```
**No `businessUid` parameter** — a deliberate design correction from the
Roadmap's original framing. Under V1, a Client Workspace provisioned by
Contract 07 has exactly one Business by construction (even before Contract
13's DB enforcement, since Contract 07 only ever creates properly-shaped
1-Business Client Workspaces); the Business to view is resolved internally
as the Client Workspace's sole Business
(`businessesForWorkspace($clientWorkspace)->first()`, asserting exactly one
is found — if more than one is ever found, that is a data-integrity
contradiction, not a silent "pick one," and must `abort(404)` the same way
every other existence-disclosure-safe failure in this class already does).

## 6. Authority / security contract

**Explicit IDOR threat model (deep-dive requirement):**

| Threat | Mitigation |
|---|---|
| Actor supplies an arbitrary `clientWorkspaceUid` hoping any Workspace works | `startAgencyView()` requires an **Active** Contract 01 relationship between the resolved Agency Workspace and the resolved Client Workspace — no relationship, no access, same `abort(404)` existence-disclosure-safe failure the existing `start()` already uses (never a 403 that confirms the Workspace exists). |
| Actor supplies an `agencyWorkspaceUid` they don't belong to | New `assertActorMayManageAgencyRelationships()`-equivalent check (reusing Contract 01's own authority method, not a duplicate — see below) must pass first; a Workspace the actor has no standing in fails here, before the relationship lookup even runs. |
| Actor is not an ACTIVE Admin/Staff member of exactly this Agency Workspace (inactive, removed, a member of a different Agency only, or no membership), or relies on a customer permission, platform status or user id 1 | Same check as above — Contract 01's `actorHasAgencyAuthority()` derives authority only from the exact owner or an active Admin/Staff membership of that Agency Workspace; this slice calls that **exact** method, never a looser or parallel one, so the two authority definitions cannot drift apart. |
| Actor's relationship existed at session start but was terminated mid-session | `accessChainStillHolds()`'s cross-Workspace variant (below) re-checks relationship status as `Active` on **every** `current()` read, exactly matching the existing same-Workspace path's own "re-validate on every read, never trust row existence alone" discipline. |
| **Agency Workspace's relationship is still `Active`, but the Agency has since lost Agency management eligibility** — downgraded off the Agency tier, **or** its account became Locked, Inactive or Suspended (per Contract 01's own explicit note that relationship existence is structural, never entitlement proof) | **A distinct, separate check from relationship status** — `startAgencyView()` additionally asserts Contract 01's `agencyWorkspaceHasManagementEligibility($agencyWorkspace)` at session start (Agency tier **and** a usable account per `CustomerAccountAccessResolver`: Trial/Active/Grace eligible, Locked/Inactive/Suspended not), and the per-read chain re-asserts the same fact on **every** `current()` read alongside the relationship check — not once at start only. Losing either the relationship **or** eligibility independently ends the session (`END_REASON_RELATIONSHIP_ENDED` for the former; `END_REASON_AGENCY_ENTITLEMENT_LOST` for the latter, so audit/reporting can distinguish the two root causes) — both fail closed, neither substitutes for the other. |
| Actor tries to use `startAgencyView()` to reach a Business belonging to their *own* Agency Workspace (self-targeting to bypass the ordinary same-Workspace `actorMayView()` Admin-only rule via a looser path) | Rejected structurally — a relationship row can never have `agency_workspace_id === client_workspace_id` (Contract 01's own self-link prevention), so `startAgencyView()` cannot resolve a relationship where the "client" is the actor's own Agency Workspace. |
| Actor uses View As to reach AgencyRebill/payer-consent actions | **Never granted by View As at all** — `ViewAsContext` carries no payer authority, and Contract 09's AgencyRebill consent check is keyed to `Workspace.owner_user_id` directly (never to "is currently viewing via an active `ViewAsContext`"), so a View As session structurally cannot escalate into financial consent, independent of any check this contract adds. Stated here as an explicit non-goal boundary, verified again in Contract 09's own authority contract. |
| A terminated relationship's stale `viewing_agency_workspace_id` is reused to forge a session row directly at the DB layer (out-of-band, not through the API) | Out of scope for application-layer authorization (this is a database-integrity/access concern, not a `ViewAsManager` concern) — noted for completeness, not mitigated by this contract. |

**Authority table:**

| Actor | May start cross-Workspace View As |
|---|---|
| Agency Workspace owner | Yes, if an Active relationship exists to the target Client Workspace |
| Active Agency Admin/Staff member of exactly that Agency Workspace | Yes, same condition — by active membership alone (no customer permission; see the correction note below) |
| Inactive member, a member of a different Agency Workspace only, a User with no membership, or user id 1 that neither owns nor belongs to the Agency | No |
| Anyone from an unrelated Workspace | No (relationship lookup fails) |
| The Client Workspace's own owner/staff | No — this method is Agency-side only; a client never "views as" themselves through this path |
| Platform Owner/Administrator | **Not via this method** — Platform-level impersonation/support access, if it exists, is a distinct, separately authorized concern (out of scope here; do not extend this method to cover it) |

**Implementation-time authority correction (decided before PR).** Two
rules supersede this contract's original wording wherever it mentioned an
"Agency-management permission" or a tier-only entitlement check:
1. **No customer permission.** The original `manage_agency_clients`
   customer Gate is User-global, not Workspace-specific, so it cannot be V1
   cross-Workspace Agency authority; it is removed. Ordinary Agency
   management (including View As) belongs to the exact Agency Workspace
   owner and its ACTIVE Admin/Staff members, derived only from that
   Workspace's owner and membership rows. Owner-only rules — relationship
   termination and every AgencyRebill consent/payer/funding authority — are
   unchanged.
2. **Eligibility is tier AND a usable account.** The Agency must be on the
   Agency tier and its effective account must be usable per
   `CustomerAccountAccessResolver` (Trial/Active/Grace yes;
   Locked/Inactive/Suspended no), both at start and on every read. Contract
   01's relationship establishment applies the same eligibility.

**Reuse, not duplication:** this slice's authority check **must** call
Contract 01's `AgencyClientRelationshipManager`'s own authority method
(e.g. a new public `assertActorMayViewClient()` added to that manager in
Contract 01's own class, or exposed via a shared read-only helper) rather
than re-implementing "owner or permitted Admin/Staff" logic a second time
— per Addendum's own repeated "no second tenancy algorithm" principle,
extended here to Agency authority specifically. **Flag:** Contract 01 as
written exposes `create()`/`terminate()` authority checks but not
necessarily a public "may this actor act as this Agency for read/View-As
purposes" method — implementation should add that as a small, additive
extension to Contract 01's manager (or confirm it already covers this
shape) rather than inventing a parallel check in `ViewAsManager`.

## 7. Transaction / concurrency boundary

`startAgencyView()` performs only reads plus one `ViewAsSession::create()`
insert (matching `start()`'s existing shape exactly — no row locking is
needed here since starting a View As session doesn't mutate the Client
Workspace, the relationship, or the Business; it only reads them and
writes an audit row). No new race condition beyond what `start()` already
tolerates (a relationship terminated in the instant between the check and
the insert is caught on the very next `current()` read via
`accessChainStillHolds()`, matching the existing pattern's own tolerance
for the analogous same-Workspace race).

## 8. Migration / backfill

**Policy:** none. Additive nullable column; every existing `view_as_sessions`
row simply has `viewing_agency_workspace_id = NULL`, which correctly means
"this was a same-Workspace session" (accurate for every row that exists
today, since the cross-Workspace path doesn't exist until this slice
ships).

## 9. Backwards compatibility

The entire existing `start()`/`current()`/`exit()`/`accessChainStillHolds()`/
`actorMayView()` surface is preserved unchanged — every existing test must
still pass unmodified. The new `startAgencyView()` path and its own
`accessChainStillHolds()` variant (call it
`agencyAccessChainStillHolds()`, a new private method, not a branch bolted
into the existing one — keeping the two paths' logic textually separate
makes it easy to delete only the old one in Contract 14 without touching
the new one) are purely additive. **Contract 14 removes**: `start()`,
`actorMayView()`, `accessChainStillHolds()` (the same-Workspace variant),
and the old same-Workspace View As route/controller entry point — this
slice does not remove anything.

## 10. Events / audit

No new domain event beyond the existing `ViewAsSession` row itself, which
already serves as the durable audit record (per its own docblock: "one row
per session... start, expiry, end... and the prohibited actions refused").
The new `viewing_agency_workspace_id` column and
`END_REASON_RELATIONSHIP_ENDED` reason extend that same existing audit
shape rather than requiring a parallel event stream. **Real actor
preservation**, already guaranteed structurally by this class's own
existing design ("Identity: `Auth::id()` never changes... this object is a
narrowing layer over the real actor, never a substitute identity" — from
`ViewAsContext`'s own docblock) — this slice inherits that guarantee
without needing to re-implement it.

## 11. Billing/provider safety

Not directly applicable — this slice adds no payer/wallet/provider
interaction. The IDOR threat model (§6) explicitly proves View As can
never reach AgencyRebill/payer-consent actions, which is the one
billing-adjacent guarantee this slice is responsible for upholding
structurally (by omission — no payer-related method is added to
`ViewAsContext` or `ViewAsManager`).

## 12. Exact implementation allowlist

**New files:**
- `database/migrations/2026_09_20_100009_add_viewing_agency_workspace_id_to_view_as_sessions_table.php` (final merged V1 sequence position: after Contract 01's `100006` and Contract 06's `100007`/`100008`)
- `tests/Feature/Workspace/AgencyViewAsTest.php`

**Existing files modified:**
- `app/Library/ViewAs/ViewAsManager.php` — add `startAgencyView()` and the cross-Workspace revalidation chain (implemented as `agencyAccessChainEndReason()`, returning the distinct end reason rather than a bare boolean so `relationship_ended` / `agency_entitlement_lost` / `access_lost` can be recorded), plus two small private helpers (`activeRelationshipLinks()`, `soleBusinessOf()`). `start()`, `actorMayView()` and `accessChainStillHolds()` are unchanged; `current()` gains only the branch that sends a session with a non-null `viewing_agency_workspace_id` through the new chain and fills the two new `ViewAsContext` fields (same-Workspace sessions take exactly their existing path).
- `app/Models/ViewAsSession.php` — add `viewing_agency_workspace_id` to `$fillable`; add `END_REASON_RELATIONSHIP_ENDED` and `END_REASON_AGENCY_ENTITLEMENT_LOST` constants.
- `app/Library/ViewAs/ViewAsContext.php` — add the two new nullable fields; constructor signature grows (additive, default-null-safe at every existing call site since `start()`'s own construction of `ViewAsContext` in `current()` simply passes `null` for the new fields on the old path).
- `app/Library/Workspace/AgencyClientRelationshipManager.php` (from Contract 01) — add the public authority-check method this slice needs (§6's "reuse, not duplication" note), if Contract 01 did not already expose one in the needed shape. As implemented: two read-only primitives, `actorHasAgencyAuthority(int $actorUserId, Workspace $agencyWorkspace): bool` (the one definition of ordinary Agency-side authority — the exact owner, or an ACTIVE Admin/Staff membership of exactly that Agency Workspace, with no Gate or customer permission; Contract 01's private create-authority assertion delegates to it) and `agencyWorkspaceHasManagementEligibility(Workspace $agencyWorkspace): bool` (Agency tier AND a usable account per `CustomerAccountAccessResolver`; Contract 01's establishment gate delegates to it). `terminate()` and `createForMigration()` authority are unchanged; `create()` now follows the corrected authority and eligibility rules (§6 correction note).
- `app/Exceptions/Workspace/AgencyWorkspaceNotEligibleException.php` (from Contract 01) — gains an optional `accessState`, so a refusal for an unusable Agency account is distinguishable from a wrong tier.
- `config/customer-permissions.php` — the obsolete `manage_agency_clients` entry Contract 01 added is removed (no remaining runtime consumer).
- `tests/Feature/Workspace/AgencyClientRelationshipManagerTest.php` (from Contract 01) — updated to the corrected authority and eligibility rules.

**No existing controller/route/middleware changed** — this slice adds
library-layer capability only; wiring a controller/route to
`startAgencyView()` is Contract 07/08(a)'s job (the "Clients" UI needing an
actual HTTP entry point), not this one.

## 13. Required tests

`AgencyViewAsTest.php`: every §6 threat-model row as an explicit test
(including the entitlement-loss row, as its own dedicated test, distinct
from the relationship-termination test — an Active relationship with a
downgraded Agency tier must fail exactly like a terminated relationship,
via a different, distinguishable `END_REASON`); happy path (Agency owner
starts a cross-Workspace session, `current()` resolves it correctly with
the new fields populated); active-Admin and active-Staff happy paths (by
membership alone);
relationship-terminated-mid-session forces `END_REASON_RELATIONSHIP_ENDED`
on the next `current()` read; Agency-downgraded-mid-session (relationship
still `Active`) forces `END_REASON_AGENCY_ENTITLEMENT_LOST` on the next
`current()` read; the existing same-Workspace `ViewAsClientTest.php`/`ViewAsAccessLossTest.php`/
`ViewAsRouteBoundaryTest`-style coverage re-run unmodified to confirm zero
regression.

## 14. Acceptance criteria

1. `startAgencyView()` passes every §6 threat-model test.
2. Every existing View As test still passes with zero assertion changes.
3. A terminated relationship ends an in-progress cross-Workspace session
   on its very next request, not merely on a future one.
4. An Agency Workspace losing Agency-tier entitlement — with its Contract
   01 relationship still `Active` — independently ends an in-progress
   cross-Workspace session on its very next request, with a distinct
   `END_REASON` from relationship termination.
5. View As never grants AgencyRebill/payer authority, proven by a test
   that attempts exactly that and expects refusal.
6. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not build any controller/route/UI (Contract 07/08a). Does not touch
Platform-Owner-level impersonation/support access, if any exists elsewhere
in the codebase — explicitly out of scope (§6). Does not remove the
same-Workspace path (Contract 14). Does not implement AgencyRebill
(Contract 09) — only proves View As cannot reach it.

## 16. Merge prerequisites

Contract 01 merged (hard dependency — this slice consumes its relationship
model and, likely, adds one method to its manager class).

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 01 | `AgencyClientRelationshipManager.php` (adds one method) | **Serialize** — Contract 01 must be merged first; this slice's addition to that file should be a small, additive, low-conflict change |
| Contract 02, 03 | none | Safe concurrent |
| Contract 05 | reads View As only conceptually (composes lifecycle, doesn't touch this code) | Safe concurrent once Contract 01 is merged |
| Contract 07 | consumes `startAgencyView()` from a new controller | Serialize, downstream |
| Contract 14 | removes the old same-Workspace path this slice deliberately left untouched | Serialize, far downstream |

## 18. Implementation prompt

```
You are implementing Slice 2 of the V1 architecture migration for the
os-creator1/os-ai repository: cross-Workspace Agency authorization and
View As, per docs/product/implementation-contracts/
04-CROSS-WORKSPACE-AGENCY-AUTH-VIEW-AS.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contract 01 (Agency<->Client Workspace relationship) is merged
   to main -- this is a hard prerequisite. If it is not merged, STOP and
   report that prerequisite is missing; do not proceed by guessing its
   shape.
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-02-cross-workspace-view-as).
4. Re-read the full contract, especially SS6's IDOR threat model -- it is
   the authoritative security specification for this slice.
5. Inspect the actual merged Contract 01 code (AgencyClientRelationship
   Manager's real method names and signatures) -- this contract's SS6
   flagged that the exact authority-check method it needs may not exist
   yet in the exact shape assumed. Confirm what actually exists; add the
   minimal additive method to that manager if needed, reusing its
   existing authority logic rather than duplicating it.
6. Inspect ViewAsManager, ViewAsSession, ViewAsContext in their current
   actual state -- if anything has changed from this contract's evidence,
   STOP and report the contradiction.

Implement exactly the scope in this contract: the new column, the new
startAgencyView()/agencyAccessChainStillHolds() methods, the ViewAsContext
extension. Do NOT modify start(), actorMayView(), or the existing
accessChainStillHolds() in any way -- they must remain byte-for-byte
behaviorally unchanged. Do NOT build any controller, route, or UI for this
new capability -- that is a later slice. Do NOT touch
WorkspaceManager::userCanAccessBusiness().

After implementing:
- Run the new focused test file for this slice, covering every SS6 threat-
  model row explicitly.
- Re-run every existing View As test file to confirm zero regression.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, and explicit confirmation that every existing View As test
passed with unchanged assertions. Do NOT begin or authorize Contract 05,
07, or any other later slice.
```
