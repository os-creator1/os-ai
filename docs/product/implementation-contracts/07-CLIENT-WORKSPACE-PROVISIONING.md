# Implementation Contract 07 — Client Workspace Provisioning

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contracts 01 and 04 being merged first.

## 1. Objective

Build the Agency-initiated "create a new client" flow: a Workspace +
Business + Primary Location, atomically paired with an active Contract 01
relationship — reusing organic-signup provisioning primitives wherever
they are genuinely reusable, and explicitly **not** reviving
`resolveLegacyOnboardingWorkspace()`'s "reuse an existing owner's
Workspace" behavior (Roadmap's own explicit warning).

## 2. Governing authority

- Blueprint §6 (signup/provisioning shape), §28 (Clients surface).
- Addendum §1 (1 Workspace = 1 Business), §2 (relationship).
- Roadmap Slice 7 (as corrected in the Phase A pass — provisioning
  authority is not owner-only, per A1).
- Contracts 01 (relationship) and 04 (View As — this slice's client
  becomes a View As target).

## 3. Current repository reality

**`WorkspaceManager::createWorkspace(int $ownerUserId, string $name):
Workspace`** (full body re-read): locks the **owner User row** (not a
Workspace row — none exists yet), creates the Workspace with
`is_active: true`, dispatches `WorkspaceCreated`. **Directly, safely
reusable as-is** — no modification needed, and it never reuses an
existing Workspace (unlike the legacy path below).

**`WorkspaceManager::resolveLegacyOnboardingWorkspace(int $ownerUserId):
Workspace`** (re-confirmed from the original architecture audit): **reuses
an existing Workspace** for a second Business under the same owner when
one is found — this is the exact anti-pattern the Roadmap warns against
reviving, and this slice's provisioning flow **must not call this method**
under any circumstance.

**`BusinessManager::createOrUpdateOnboardingBusiness(Customer $customer,
?Business $business, array $attributes): Business`** and its private
`applyIdentity()`: require an **already-existing `Customer`** object —
this method assumes an authenticated user creating their *own* Business,
which is exactly backwards for Agency-initiated provisioning (there is no
client User yet at the moment an Agency starts provisioning them).
**Not directly reusable for the client-creation moment**; reusable only
*after* a client User/Customer already exists (e.g., if an Agency is
onboarding someone who already has a platform account — an edge case, not
the primary flow).

**`BusinessLocationManager::upsertPrimaryLocation(Business $business,
array $attributes): BusinessLocation`** (re-confirmed from Contract 10's
own evidence pass): the canonical, capacity-asserting, create-or-edit
Primary Location method — **directly reusable** for the new Client
Business's Primary Location, exactly as organic onboarding already uses
it.

**Critical, previously-unflagged gap: no reusable "create a new User +
Customer, without payment" path exists.** `app/Http/Controllers/Auth/
RegisterController.php`'s `register()` method (confirmed via method
listing) is one large, monolithic method entangled with
`PayOffline`/`PayNowpayments`-adjacent Subscription/Stripe logic — there
is no cleanly separable "just create the identity" method to call. **No
invite/pending-account mechanism exists anywhere in the codebase**
(confirmed: no `class *Invit*` model found). This means Agency-initiated
client provisioning has a genuine, un-designed gap at the very first step
— creating the client's own login identity — that this contract cannot
responsibly paper over by inventing a design unsupported by evidence.

## 4. Delta from current state to target

**Changes:** one new Agency-facing provisioning flow
(`AgencyClientProvisioningManager` or similar, a new class — not more
methods bolted onto `WorkspaceManager`, to keep it from growing further
and to keep Agency-specific orchestration separate from general Workspace
mechanics) that: (a) resolves or creates the client's User/Customer
identity (§5 — the flagged gap, requiring a decision before
implementation), (b) calls `WorkspaceManager::createWorkspace()` unchanged,
(c) calls `BusinessManager`-equivalent Business creation (not
`createOrUpdateOnboardingBusiness()`, since there's no pre-existing
Customer session context — needs its own creation path taking the new
Customer directly, mirroring `applyIdentity()`'s CREATE branch's shape but
without its onboarding-session assumptions), (d) calls
`BusinessLocationManager::upsertPrimaryLocation()` unchanged, (e) calls
Contract 01's `AgencyClientRelationshipManager::create()` unchanged — all
five inside one transaction with rollback-on-any-failure (§7).

**Explicitly does NOT change:** `createWorkspace()`,
`resolveLegacyOnboardingWorkspace()` (untouched, still serving its
existing legacy-onboarding callers), `upsertPrimaryLocation()`, Contract
01's manager. This slice is a new **orchestrator** over existing and
Contract-01 primitives, not a modification of any of them.

## 5. Data model contract

No new table beyond what Contracts 01 and the standard
Workspace/Business/BusinessLocation schema already provide — this slice
is pure orchestration. The one open data-model question, **flagged, not
invented (§3's gap):**

**How does the new client get a User/Customer row?** Three candidate
designs, presented without silently picking one:
1. **Agency-created credential**: the provisioning flow creates a new
   `User`/`Customer` row directly with a system-generated temporary
   password, and the client is expected to reset it on first login (needs
   a password-reset-token flow — confirm one already exists generically
   in this Laravel app, likely yes via the framework's own
   `Password::sendResetLink()`, but not confirmed in this evidence pass).
2. **Email invitation with claim link**: the provisioning flow creates the
   Workspace/Business/Location/relationship rows **before** any User
   exists, with the Workspace's `owner_user_id` pointing at a placeholder
   or left in a state requiring a new "pending owner" concept — a larger
   schema change (`workspaces.owner_user_id` is `NOT NULL` per RFC-003;
   accepting a not-yet-real owner would require either a nullable owner
   during a pending state, which the existing schema does not support, or
   a genuinely new small "invite" table separate from `Workspace` itself
   that only creates the real Workspace once the client claims it).
3. **Existing-account attach**: the Agency enters the prospective client's
   email; if a `User` already exists with that email, attach directly (no
   new identity created); if not, fall back to option 1 or 2.
**This contract does not choose between these** — doing so would be
inventing product/security behavior no cited evidence resolves. Recommend
a short, explicit product decision before implementation begins (out of
this contract's own authority to make, consistent with this whole
contract-factory task's "do not manufacture decisions" instruction).

## 6. Authority / security contract

| Actor | May provision a new client |
|---|---|
| Agency Workspace owner | Yes |
| Active Agency Admin/Staff with Agency-management permission (Contract 01's authority method) | Yes — per the corrected Blueprint §2 rule (A1): provisioning is **not** owner-only, since Addendum §2 restricts only *termination* to the owner, not creation |
| Agency Admin/Staff without the permission | No |
| Anyone outside the Agency Workspace | No |

This slice's manager calls Contract 01's own authority-check method
directly (the same one Contract 01's `create()` already uses internally)
— **not** a duplicate check, since provisioning a client and establishing
the relationship are, in this design, two steps of one atomic operation
that share the same authority gate.

**If option 2 (§5) or a resold SaaS plan assignment is part of
provisioning:** any sub-step that commits the Agency to a financial
obligation on the client's behalf (money lane C, Addendum §12) may carry
its own narrower authority requirement — flagged here as a dependency on
whatever Blueprint §28's SaaS Plans surface eventually specifies, not
resolved by this contract (matches the equivalent flag already placed in
the Roadmap's own corrected Slice 7 text).

## 7. Transaction / concurrency boundary

**Single outer transaction** wrapping all steps in §4(a)–(e): identity
resolution/creation, `createWorkspace()`, Business creation,
`upsertPrimaryLocation()`, relationship `create()`. **Atomicity
requirement, explicitly required by the deep-dive:** if relationship
creation (the last step) fails for any reason (e.g., the target already
somehow has an active relationship via a race — see Contract 01 §7's own
race-case handling), the entire transaction rolls back — **no orphan
Client Workspace is ever left behind** without its relationship. This is
why all five steps belong in one transaction rather than being run as
separate, individually-committed operations with manual cleanup on
failure.

Lock ordering: within the transaction, `createWorkspace()`'s own internal
owner-row lock happens first (unavoidable, it's the first write); the
relationship creation's own two-Workspace lock (Contract 01 §7) then
locks the brand-new Client Workspace (uncontended, just created in this
same transaction) and the Agency Workspace (contended against any other
concurrent operation on that Agency) in ascending-ID order, matching
Contract 01's own documented discipline.

## 8. Migration / backfill

None — this is a new, forward-only orchestration flow with no existing
data to migrate. (Contract 10 is the migration slice for *existing*
Agency multi-Business data; this slice only handles *new* clients created
after it ships.)

## 9. Backwards compatibility

No old path is changed — organic Core/Growth signup (§3's
`createOrUpdateOnboardingBusiness()`/`applyIdentity()` path) continues
completely unmodified; this slice adds a wholly separate Agency-initiated
path that happens to reuse two of the same lower-level primitives
(`createWorkspace()`, `upsertPrimaryLocation()`) without altering them.

## 10. Events / audit

Reuses existing events unchanged: `WorkspaceCreated` (from
`createWorkspace()`), `BusinessCreated` (from whatever Business-creation
path this slice's orchestrator calls — mirroring
`createOrUpdateOnboardingBusiness()`'s own dispatch, per §4(c)), and
Contract 01's `AgencyClientRelationshipEstablished`. No new event type is
needed — the orchestration is fully described by the sequence of these
three existing/Contract-01 events firing together. Real actor
(the Agency user who initiated provisioning) is preserved throughout, per
every prior contract's same discipline.

## 11. Billing/provider safety

The client's own plan/trial starts independently per Blueprint §6/§27 —
**not** initiated as a financial commitment by the Agency merely by
provisioning the Workspace shell (provisioning creates the container; plan
selection is the client's own subsequent action, or a separate, more
narrowly-authorized SaaS-Plans sub-step per §6/§28, not this contract's
concern). No wallet, payer, or provider call happens during provisioning
itself.

## 12. Exact implementation allowlist

**New files:**
- `app/Library/Workspace/AgencyClientProvisioningManager.php`
- A Business-creation method usable without an existing session-scoped `Customer` (either a new method on `BusinessManager` or a small new class — **flagged for implementation-time judgment**, since this contract's evidence pass did not find a clean existing seam for it)
- `tests/Feature/Workspace/AgencyClientProvisioningTest.php`

**Existing files NOT modified:** `WorkspaceManager.php`,
`BusinessLocationManager.php`, `RegisterController.php` — all read-only
precedents for this slice, none altered.

**Flagged, not allowlisted (§5's open decision):** any User/Customer-
identity-creation code path is **not** listed here as a specific file,
because which design (§5's three options) is chosen determines which
files are actually touched — implementation must not proceed past this
point without that decision being made explicitly, per §15.

## 13. Required tests

`AgencyClientProvisioningTest.php`: happy path (once §5 is resolved) —
provisioning produces a Workspace/Business/Location indistinguishable in
shape from organic signup, plus one active relationship; authorization
matrix per §6; **atomicity**: force a failure at the relationship-creation
step (e.g. a concurrent duplicate) and assert the Workspace/Business/
Location rows do **not** persist (transaction rolled back, no orphan).

## 14. Acceptance criteria

1. The §5 identity-creation design question is explicitly resolved (by a
   human decision, not invented) before this slice is implemented.
2. Provisioning is atomic — proven by the rollback test in §13.
3. Provisioning authority matches §6 exactly (not owner-only).
4. `git diff --check` clean; diff matches §12's allowlist once §5 is
   resolved and the actual identity-creation files are known.

## 15. Non-goals

Does not build the Agency "Clients" **UI** (Contract 08A — this slice is
its prerequisite, not the same work). Does not resolve §5's identity-
creation design — states the options, does not choose. Does not implement
SaaS Plan assignment or any Agency-Stripe billing step. Does not migrate
any existing Agency data (Contract 10).

## 16. Merge prerequisites

Contracts 01 and 04 merged (04 because a newly-provisioned client should
be immediately View-As-able, and this slice's own tests will want to
prove that end-to-end).

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 01 | consumes `create()`/authority method | Serialize (prerequisite) |
| Contract 04 | consumed by this slice's own end-to-end test, not by its production code | Serialize (prerequisite for full test coverage, not a hard code dependency) |
| Contract 08A | consumes this slice's manager | **Serialize** — hard dependency, downstream |
| Contract 10 | migrates existing data into the same shape this slice produces for new data | Serialize, much later |

## 18. Implementation prompt

```
You are implementing Slice 7 of the V1 architecture migration for the
os-creator1/os-ai repository: Client Workspace provisioning, per docs/
product/implementation-contracts/07-CLIENT-WORKSPACE-PROVISIONING.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contracts 01 and 04 are merged to main -- hard prerequisites. If
   either is missing, STOP and report.
3. STOP AND REPORT BACK before writing any identity-creation code: this
   contract's SS5 explicitly flags that how a new client's User/Customer
   identity gets created is an unresolved product/security decision among
   three options, and states that implementation must not proceed past
   that point without an explicit human decision. Do not silently pick
   one of the three options yourself.
4. Once that decision is provided, create a fresh branch for this slice
   only (e.g. agent/v1-slice-07-client-workspace-provisioning).
5. Re-read the full contract end to end.
6. Inspect the actual current state of WorkspaceManager::createWorkspace(),
   BusinessLocationManager::upsertPrimaryLocation(), and Contract 01's
   actual merged shape -- if anything differs from this contract's
   evidence, STOP and report the contradiction.

Implement exactly the scope in this contract: the new orchestrator class,
calling the existing primitives unmodified, inside one atomic transaction
per SS7. Do NOT modify WorkspaceManager, BusinessLocationManager, or
RegisterController. Do NOT build the Clients UI (Contract 08A). Do NOT
implement SaaS Plan assignment or Agency billing.

After implementing:
- Run the new focused test file, including the atomicity/rollback test.
- Run the broader Workspace-domain regression.
- Run git diff --check.
- Verify the diff touches only files consistent with the identity-creation
  decision that was provided plus this contract's allowlist.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, which identity-creation option was implemented and why,
and explicit proof of atomicity from your rollback test. Do NOT begin or
authorize Contract 08A or any other later slice.
```
