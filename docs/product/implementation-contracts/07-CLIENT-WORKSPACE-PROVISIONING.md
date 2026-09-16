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

**Changes — now two coordinated flows, not one, per §5's final design:**
(1) a new `ClientInvitationManager` handling invitation
create/send/revoke against the new `client_workspace_invitations` table
(no Workspace/Business touched); (2) a new
`AgencyClientProvisioningManager` (a new class — not more methods bolted
onto `WorkspaceManager`) invoked only at **acceptance** time, once a real
authenticated `User`/`Customer` exists, that: (a) calls
`WorkspaceManager::createWorkspace()` unchanged, with the real User's ID;
(b) calls a Business-creation path taking that Customer directly
(mirroring `applyIdentity()`'s CREATE branch's shape but without its
onboarding-session assumptions — `createOrUpdateOnboardingBusiness()`
itself is still not reusable, for the same reason as before); (c) calls
`BusinessLocationManager::upsertPrimaryLocation()` unchanged; (d) calls
Contract 01's `AgencyClientRelationshipManager::create()` unchanged; (e)
marks the invitation `Accepted` — all inside one transaction with
rollback-on-any-failure (§7).

**Explicitly does NOT change:** `createWorkspace()`,
`resolveLegacyOnboardingWorkspace()` (untouched, still serving its
existing legacy-onboarding callers), `upsertPrimaryLocation()`, Contract
01's manager. This slice is a new **orchestrator** over existing and
Contract-01 primitives, not a modification of any of them.

## 5. Data model contract

**Canonical V1 decision (no open option remains — invitation-based,
resolved against the evidence in §3):**

**`config/auth.php`** (checked in this remediation pass): the framework's
own password-reset broker is configured at `table: 'password_resets',
expire: 60 (minutes), throttle: 60 (seconds)` — the closest existing
secure precedent this codebase has for "a hashed, expiring, single-use
token tied to an email," reused as the template below rather than
inventing a new token scheme.

**New table `client_workspace_invitations`:**

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | |
| `uid` | `uuid` | No | `HasUid`, matching every other entity's convention |
| `agency_workspace_id` | FK → `workspaces.id`, `restrictOnDelete()` | No | |
| `invited_by_user_id` | `unsignedBigInteger`, no FK (actor-column precedent) | No | |
| `email` | `string` | No | The prospective client's email — **not** a `user_id` FK, since no User need exist yet |
| `token_hash` | `string` | No | `Hash::make()` of a random token, mirroring `password_resets`' own hashed-at-rest convention — the plaintext token exists only in the emailed link, never stored |
| `intended_business_name` | `string` | Yes | The Agency's provisioning intent — what Business the invitation will create on acceptance |
| `status` | `string(16)`, enum-backed | No | `ClientInvitationStatus`: `Pending \| Accepted \| Expired \| Revoked` |
| `expires_at` | `timestamp` | No | Mirrors the 60-minute precedent above, or a longer client-appropriate window (a business decision, not an architecture one — flagged, not invented, matching this contract's own posture elsewhere) |
| `accepted_at` | `timestamp` | Yes | `NULL` until claimed |
| `created_client_workspace_id` | `unsignedBigInteger`, nullable FK → `workspaces.id` | Yes | Set only on successful acceptance — the durable link from the invitation record to the Workspace it produced |
| `created_at`/`updated_at` | `timestamp` | No | |

**This is the "durable pre-consent/migration-intent record"** the
remediation calls for: it exists, and is fully reviewable/revocable,
**before** any Workspace, Business, Location, or Contract 01 relationship
is created — which is exactly what resolves the original three-option
dilemma's hardest problem. `workspaces.owner_user_id` stays `NOT NULL` and
**no placeholder/fake owner is ever created**, because the Workspace
itself is not created at invitation time at all — only this intent
record is.

**Acceptance flow (the atomic creation moment):**
1. Agency enters the prospective client's email + `intended_business_name`
   → this slice's manager creates one `client_workspace_invitations` row
   (`Pending`) and sends an email containing the plaintext token in a
   claim link. No Workspace/Business/User is touched yet.
2. The recipient opens the link. The acceptance page **requires
   authentication before completing anything** — same behavior whether or
   not `email` already matches an existing `User`, to avoid unnecessary
   account-existence disclosure (mirroring this codebase's own established
   existence-disclosure discipline from this session's PR #302 work): a
   generic "sign in or create an account to continue" screen, never "this
   email is already registered" or "no account found."
   - **New email:** the person registers a new account through this
     codebase's ordinary registration path, choosing their **own**
     password — the Agency never sees or sets it.
   - **Existing email:** the person must **explicitly log in** (prove
     account ownership via their own password) — acceptance is never
     completed merely because the invitation's `email` field matches an
     existing account; that would silently attach a real person's
     existing account to an Agency relationship they never confirmed.
3. **Only once a real, authenticated `User`/`Customer` exists** does this
   slice's orchestrator run (§4's five-step atomic transaction), with
   `owner_user_id` set to that real, already-existing User's ID from the
   very first `createWorkspace()` call — the `NOT NULL` constraint is
   satisfied naturally, never worked around.
4. On success: `client_workspace_invitations.status = Accepted`,
   `accepted_at` set, `created_client_workspace_id` recorded. The Contract
   01 relationship is established in the same transaction, actor =
   `invited_by_user_id` (the Agency actor who sent the invitation, not the
   accepting client — the Agency is who established the management
   relationship's authority side, per Contract 01 §6).
5. **The same global User may accept while retaining independent
   memberships elsewhere** (Addendum §3, Blueprint §3) — nothing in this
   flow touches any of that User's other Workspace memberships; ownership
   of the new Client Workspace is simply one more fact about that User,
   exactly like any other Workspace they already own or belong to.

**Expiry/revocation:** an `expires_at`-past or `Revoked` invitation's
claim link fails closed (generic "this invitation is no longer valid," no
further disclosure); the Agency may revoke a still-`Pending` invitation
before acceptance (updates `status = Revoked`, no Workspace side effect
since none was ever created).

## 6. Authority / security contract

| Actor | May send/revoke a client invitation | May accept an invitation |
|---|---|---|
| Agency Workspace owner | Yes | N/A — not the accepting party |
| Active Agency Admin/Staff member of the exact Agency Workspace, by membership alone (Contract 01's `actorHasAgencyAuthority()`; no additional Agency-management permission) | Yes — per the corrected Blueprint §2 rule (A1): provisioning is **not** owner-only, since Addendum §2 restricts only *termination* to the owner, not creation | N/A |
| Inactive Agency member, or a member of a different Agency Workspace only | No | N/A |
| Anyone outside the Agency Workspace | No | N/A |
| The invited person (any authenticated User, new or existing) | N/A | Yes, once authenticated per §5's flow — this is the **only** actor who can complete acceptance; the Agency cannot complete it on the client's behalf |

`ClientInvitationManager::send()`/`revoke()` call Contract 01's own
authority-check method directly (the same one Contract 01's `create()`
already uses internally) — **not** a duplicate check.
`AgencyClientProvisioningManager`'s acceptance-time step requires no
Agency-side authority check at all (the Agency already acted, at
invitation time); it requires only that the accepting User is
authenticated (§5).

**A resold SaaS plan assignment, if bundled into provisioning:** any
sub-step that commits the Agency to a financial obligation on the
client's behalf (money lane C, Addendum §12) may carry its own narrower
authority requirement — flagged here as a dependency on whatever
Blueprint §28's SaaS Plans surface eventually specifies, not resolved by
this contract.

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
needed for the acceptance-time creation step — the orchestration is fully
described by the sequence of these three existing/Contract-01 events
firing together. **Two distinct real actors are preserved, never
conflated:** `WorkspaceCreated`'s `owner_user_id` and
`AgencyClientRelationshipEstablished`'s target are the accepting client;
the relationship's `established_by_user_id` (Contract 01) is the Agency
user who originally sent the invitation (`invited_by_user_id` on the
invitation row) — the Agency initiated the relationship, the client
initiated their own Workspace's existence, and both facts are recorded
accurately rather than attributing everything to one actor.

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
- `database/migrations/2026_09_2x_100013_create_client_workspace_invitations_table.php`
- `app/Models/ClientWorkspaceInvitation.php`
- `app/Enums/Workspace/ClientInvitationStatus.php`
- `app/Repositories/Contracts/ClientWorkspaceInvitationRepository.php` + `app/Repositories/Eloquent/EloquentClientWorkspaceInvitationRepository.php`
- `app/Library/Workspace/ClientInvitationManager.php` (send/revoke, §5)
- `app/Library/Workspace/AgencyClientProvisioningManager.php` (acceptance-time atomic creation, §4/§5)
- A Business-creation method usable without an existing session-scoped `Customer` (either a new method on `BusinessManager` or a small new class — **flagged for implementation-time judgment**, since this contract's evidence pass did not find a clean existing seam for it; used only inside `AgencyClientProvisioningManager`'s acceptance-time step, §5)
- Standard registration/login controller wiring for the acceptance page (reuses this codebase's existing registration/login controllers per §5 — new route(s)/thin controller only, not a new auth system)
- A `ClientInvitationNotification` mailable, mirroring Laravel's own password-reset notification pattern (§5)
- `tests/Feature/Workspace/ClientInvitationManagerTest.php`
- `tests/Feature/Workspace/AgencyClientProvisioningTest.php`

**Existing files NOT modified:** `WorkspaceManager.php`,
`BusinessLocationManager.php`, `RegisterController.php` — all read-only
precedents for this slice, none altered (the acceptance flow reuses
registration/login as existing entry points, not by modifying
`RegisterController` itself).

## 13. Required tests

`ClientInvitationManagerTest.php`: authorization matrix per §6 (send/
revoke); token hashed at rest, never logged in plaintext; expiry enforced;
generic failure response for expired/revoked/invalid tokens (no
existence disclosure).

`AgencyClientProvisioningTest.php`: full acceptance flow — new-email case
(registration then atomic creation) and existing-email case (explicit
login then atomic creation) both produce a Workspace/Business/Location
indistinguishable in shape from organic signup, plus one active Contract
01 relationship; **existing-email case specifically asserts acceptance
never completes without the person proving account ownership via login**
(a crafted request merely naming a matching email, with no valid session,
must fail); **atomicity**: force a failure at the relationship-creation
step (e.g. a concurrent duplicate) and assert the Workspace/Business/
Location rows do **not** persist (transaction rolled back, no orphan);
the same global User accepting one invitation retains their other,
unrelated Workspace memberships untouched.

## 14. Acceptance criteria

1. Invitation send/accept/expire/revoke all pass §13's tests.
2. Acceptance never completes without real authentication — proven by the
   existing-email adversarial test.
3. Provisioning (the acceptance-time creation step) is atomic — proven by
   the rollback test in §13.
4. Provisioning/invitation authority matches §6 exactly (not owner-only
   for sending; accepting-User-only for acceptance).
5. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not build the Agency "Clients" **UI** (Contract 08A — this slice is
its prerequisite, not the same work). Does not implement SaaS Plan
assignment or any Agency-Stripe billing step. Does not migrate
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
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-07-client-workspace-provisioning).
4. Re-read the full contract end to end -- SS5's invitation-based design
   is final, not an open question; implement it as specified, not a
   variant of it.
5. Inspect the actual current state of WorkspaceManager::createWorkspace(),
   BusinessLocationManager::upsertPrimaryLocation(), config/auth.php's
   password-reset broker settings, and Contract 01's actual merged shape
   -- if anything differs from this contract's evidence, STOP and report
   the contradiction.

Implement exactly the scope in this contract: the invitation table/model/
manager, the acceptance-time provisioning orchestrator, both inside their
own correct transaction boundaries per SS7, reusing existing registration/
login entry points rather than building a new auth system. The Agency
must never see or set the client's password. Acceptance for an existing
email must require real login, never complete merely because the email
matches. Do NOT modify WorkspaceManager, BusinessLocationManager, or
RegisterController. Do NOT build the Clients UI (Contract 08A). Do NOT
implement SaaS Plan assignment or Agency billing.

After implementing:
- Run the new focused test files, including the atomicity/rollback test
  and the existing-email-requires-login adversarial test.
- Run the broader Workspace-domain regression.
- Run git diff --check.
- Verify the diff touches only this contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, and explicit proof of atomicity from your rollback test
and of the existing-email-requires-login guarantee. Do NOT begin or
authorize Contract 08A or any other later slice.
```
