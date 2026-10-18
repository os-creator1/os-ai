# Platform Owner — customer account management and deletion

Platform Owner → Users → *account* is the single administrative entry point for
a customer: identity, every owned Workspace with its Businesses/Locations, plan
and subscription state, staff memberships, Agency relationships, the existing
support actions, and a separate **Danger zone**. The Workspace and Business
detail pages remain; nothing here duplicates those records.

## One authority

`App\Library\PlatformOwner\AccountDeletion\PlatformAccountClosureManager` is the
only code that removes a customer's user, Workspaces or Businesses. The legacy
`Admin\CustomerController::destroy()` hard-deletes invoices and subscription
rows without cancelling anything with a provider, so it now refuses any user
that owns a Workspace (or has a closure) and points to this flow.

Related classes (same namespace): `PlatformAccountAssessor` (read-only preflight
and account overview, the same data the confirmation page and the manager's
re-check use), `AccountRecordGraph` (finds and removes account-owned rows),
`ProtectedAccountRecords` (what may never be erased).
State lives in `platform_account_closures` (unique per `user_uid`; survives the
user row as a tombstone).

## Lifecycle

`request()` → authority re-check (database, not the session) → refuses the acting
user, any administrator, a mismatched typed email, a reason under 3 characters,
and a stale review (the form carries a fingerprint of what was shown) → opens or
resumes the one closure row → `advance()` (claim-guarded, idempotent):

1. **block_access** — status off, sessions/tokens/remember/API token revoked,
   seats in other people's Workspaces deactivated.
2. **cancel_billing** — every live subscription is read from the provider first
   (an uncertain earlier outcome is converged, not repeated), then cancelled
   through its gateway with a closure-scoped idempotency key:
   Platform (lane A) `PlatformStripeGateway::cancelSubscriptionNow`; Agency
   client subscriptions (lane C, both directions, on the Agency's connected
   account) `AgencyStripeGateway::cancelSubscriptionNow`; Additional-Business
   slot agreements via the existing `requestSlotAgreementCancellation`
   (renewal sweeps already skip `cancel_at_period_end`). Wallet auto-recharge is
   switched off. A local subscription row is never deleted or relabelled
   without provider confirmation.
3. **halt_activity** — Workspaces inactive, Businesses inactive, paid activity
   paused, Agency relationships terminated (client Workspaces untouched),
   legacy campaigns cancelled, scheduled messages removed, automations and
   workflows paused, outreach paused, websites archived, forms disabled, and
   Google / Meta / mailbox / calendar connections disconnected through their
   own managers.
4. **finalize** — only with nothing outstanding:
   * no protected history → **DELETED** (all account-owned rows erased in
     foreign-key order, inside one transaction, re-checked inside it);
   * protected history → **CLOSED — records retained** (user and Workspaces
     remain, locked; nothing financial erased, no constraint weakened);
   * anything outstanding → stays **CLOSING / Pending cancellation** with a
     visible list and a Retry button. `platform:advance-account-closures`
     (every 10 minutes) retries it. A failed provider cancellation is never
     reported as complete.

Ownership vs membership: only `workspaces.owner_user_id` rows are retired. A
member loses their seat; an Agency owner's client Workspaces are separate
accounts and are never selected.

## How "owned" and "protected" are decided

The delete order is read from the database's own foreign-key graph
(`information_schema`), single and composite keys, starting at the user and the
owned Workspaces. Keys out of `users` are followed only if listed in
`AccountRecordGraph::USER_OWNED`; every other non-`SET NULL` key into a user,
and `businesses.customer_id`, are *blockers* (rows owned by someone else, so
the account is closed instead of erased). Rows in `ProtectedAccountRecords`
(provider-linked subscriptions, ledgers, wallets with balance, receipts,
payments/refunds, funding, slot agreements, provider customers, unreleased
carrier numbers, legacy invoices, audit trail, …) force CLOSED. Tables that
name a Workspace/Business with no foreign key are declared either retained or
owned. `PlatformAccountDeletionTest` pins all of this against the live schema
(every FK into `users` classified, every `workspace_id`/`business_id` table
covered, the whole reachable graph orderable).

## Known limits (not hidden)

* **Messaging numbers** held at the carrier have no automatic release path for
  an administrator (release needs the human-gated Messaging Numbers lifecycle).
  The closure stays *Pending cancellation* until they are released, then Retry.
* **Remote OAuth revocation**: Google and Meta disconnect destroys stored
  credentials locally (the existing managers' documented behaviour); only the
  mailbox provider attempts a provider-side grant revocation.
* **Stripe connected accounts** (Business and Agency) and Stripe customers are
  never deleted; the local links are retained and force CLOSED.
* **Queued jobs** are not enumerated (payloads are not queryable by owner).
  They are refused at their existing checkpoints because the Business/Workspace
  is inactive (`AutomationEligibility`, workflow checkpoints).
* A client's lane-C subscription is cancelled when its *Agency* is closed, so
  the client is not billed by a closed Agency; the client keeps its Workspace
  and must pick a new plan.
