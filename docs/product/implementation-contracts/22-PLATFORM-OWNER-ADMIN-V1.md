# Implementation Contract 22 — Platform Owner / Admin V1

**Status:** completion pass on the existing admin surface. Nothing here adds an
admin-role system, a state, a table or a provider integration. It makes the
**Workspace page the support cockpit** and answers one question with canonical
data: *"why can or can't this customer use the product right now, and what is
the state of everything that could explain it?"*

Base: `c77762d7` (Proposals / Contracts / e-sign V1 completion).
Out of scope, unchanged: Agency mutations and white label, central
Automations, Stripe console/refunds, subscription/plan editing beyond what
RFC-004 M3 already exposes, provider credential editing, View As redesign,
logs/observability, analytics warehouse, support tickets.

## 1. What already existed (and is reused, not rebuilt)

| Concern | Existing authority | Used here as |
|---|---|---|
| Platform Owner marker | `users.is_admin`, enforced by `EnsureUserIsAdministrator`; `EntitlementManager::assertPlatformAdministrator()` | the one rule, now centralised in `PlatformOwnerAuthority` |
| Admin Workspace / Business pages | `Admin\WorkspaceController`, `Admin\BusinessController` (RFC-003 M5, RFC-001 M6) | extended, not replaced |
| Plan mutations | `Admin\WorkspaceEntitlementController` (RFC-004 M3: assign, change, status, complimentary, slots, overrides) | untouched; still the only plan-edit surface |
| Access decision | `CustomerAccountAccessResolver::resolve()` (Contracts 03 / 05) | the diagnostic **is** its output |
| Lifecycle writers | `EntitlementManager::recoverAccess()` / `enterGracePeriod()` / `lockForNonPayment()` | `recoverAccess()` exposed (§5) |
| Audit | `workspace_entitlement_transitions` (append-only, actor + reason) | the audit trail; one new `transition_type` |
| Subscription | `PlatformSubscription`, `PlatformSubscriptionStatus::grantsAccess()` | displayed beside the decision |
| Provider status | `business_stripe_connections`, `business_email_accounts`, `business_google_connections`, `external_calendar_connections` | read through explicit-column selects |
| Agency | `AgencyClientWorkspaceRelationshipRepository`, `AgencyClientSubscription` | read-only |
| Support access | admin "login as customer" (`customers/{customer}/impersonate`) + `auth/loggedAs` banner | linked from the Workspace page, unchanged |

## 2. Authority

`App\Library\PlatformOwner\PlatformOwnerAuthority` is the single rule:

* `allowsRequest()` — authenticated **and** `users.is_admin` **and** no View As
  frame open in the session. `EnsureUserIsAdministrator` now delegates to it,
  so every existing admin group keeps working and every new route inherits it.
* `assertAdministrator($actorUserId)` — re-reads `is_admin` from the database
  by id. Used by write services so they never trust the controller.
* `refuseMissingTarget()` — the `->missing()` callback for `{workspace}` /
  `{business}`. Route-model binding runs before the authority middleware, so
  without it a non-owner saw *refused* for a real target and *not found* (or a
  500) for a missing one. Now they get the same refusal; only a real Platform
  Owner is told *not found*.

Does **not** confer authority: owning or being a member of a Workspace,
owning an Agency Workspace, View As (any kind), the admin "login as customer"
mechanism (it swaps the authenticated user for the customer's non-admin
account), or any request parameter. Per-feature permission strings
(`view workspace`, `manage workspace plans`, `edit business`, …) remain a
second, independent layer. No new permission string was added.

## 3. Pages

| Route | Purpose |
|---|---|
| `admin.platform-owner.overview` | bounded counts of persisted state |
| `admin.workspaces.index` | search + plan/subscription columns (existing, extended) |
| `admin.workspaces.show` | **the cockpit** (existing, extended) |
| `admin.businesses.index` / `show` | Business list + detail (existing, extended) |
| `admin.platform-owner.audit` | Platform Owner actions, newest first |
| `POST admin.platform-owner.workspaces.restore-access` | the one lifecycle control |

The `admin.workspaces.*` route-name family is pinned by
`AdminWorkspaceControllerTest` to the RFC-004 M3 surface, which is why the
restore route lives under `platform-owner.`. Menu: **Platform Owner →
Overview, Workspaces, Businesses, Audit** (admin-only; the menu is a
convenience, every route re-checks authority).

## 4. The access diagnostic

`WorkspaceSupportReader` wraps, and never re-derives:

* the decision — `CustomerAccountAccessResolver::resolve($workspace)`, the same
  call the customer gate makes (state, reason vocabulary, trial/grace hints);
* the plan facts — `EntitlementManager::getWorkspaceEntitlementSummary()`;
* "does the subscription grant access" — `PlatformSubscriptionStatus::grantsAccess()`.

A disagreement between the decision and the subscription is **reported as a
mismatch**, never resolved. No Stripe call is made on render.

**An unassigned Workspace is not blocked on this codebase** — the resolver
returns `usable` (onboarding owns it) — and the page says exactly that rather
than inventing a blocked state.

## 5. Controls actually exposed

1. **Restore access** — `EntitlementManager::recoverAccess()`. Offered only for
   an Active assignment with a *recorded* Grace/Locked timestamp; never for a
   running trial (it would convert it), nor for Inactive/Suspended (owned by the
   existing plan-status control), nor for an Agency-caused block. Requires a
   reason and an explicit confirmation; idempotent (no second audit row).
2. **Business status** — the pre-existing admin write, now through
   `PlatformOwnerAccountActions::changeBusinessStatus()`: authority re-check,
   `findForUpdate`, a **required reason to move to Inactive**, and one audit row
   in the same transaction. Same-status repeats write nothing.

Not exposed (no canonical safe action exists, or out of scope): lock, enter
grace, subscription sync/reconcile, plan assignment (already exists in M3),
Stripe/provider edits, payer or ownership changes.

## 6. Audit

`workspace_entitlement_transitions`, unchanged schema (**no migration**).
`recoverAccess()` already writes `access_restored` with actor and reason; the
Business status change writes the new `business_status_changed` type, with
`{business_id, business_uid, from, to}` in the existing JSON `payload`.
`EntitlementEnumsTest` pins the enum and was updated in the same change.
The audit page lists rows whose actor is a platform administrator
(`users.is_admin`); system and customer-driven rows are excluded. Simple
pagination (no `COUNT` over an append-only table).

## 7. View As / support access

No change to View As. An open View As frame (any kind) makes the Platform Owner
pages unavailable even for an `is_admin` account. A Platform Owner entering a
customer through the existing "login as customer" link becomes that customer;
the Platform Owner pages are unavailable until they return.

## 8. Provider status and secrets

Provider rows are read by `ProviderConnectionStatusReader` with **explicit
column lists that contain no credential column**. A column that is not selected
cannot be rendered, serialized, or decrypted. Shown: provider, state, account
identifier (Stripe `acct_…`, mailbox, Google account), capability flags,
failure classification, connected/synced timestamps.

## 9. Bounds

Members ≤ 25, Businesses ≤ 25, Locations per Business ≤ 25, audit rows ≤ 15
per Workspace page. Every cross-Business lookup is one batched query. The
Workspace index adds plan + subscription for the page in two queries. Overview
figures are grouped aggregates over indexed columns; the blocked list is capped
at 10. Query-count regression tests assert the counts stay flat.

## 10. Deferred

Lock/grace controls; a safe subscription reconcile action (none exists
canonically); audit of the legacy "login as customer" link (the GET writes no
audit row today); Business Email location attribution; Agency mutations and
white-label state; platform-wide failed-queue/log views.
