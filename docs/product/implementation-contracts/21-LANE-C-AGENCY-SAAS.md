# Implementation Contract 21 — Lane C: Agency SaaS Revenue

**Status:** authoritative implementation contract for money lane C. Extends
Implementation Contract 21 (Commercial Payments Completion); §§0–17 of that
document remain in force unchanged and are not restated here.

**Base:** `main` at `236462bf` (PR #366 — lane A merged).

**Branch:** `agent/final-payments-c-agency-saas`.

This is not a planning document. Every claim about existing code below was
verified mechanically against the tree at `236462bf` and is cited by path. Where
an older planning document disagrees with the merged code, the merged code wins
and the disagreement is called out.

---

## C0. What lane C is, in one paragraph

An Agency on the **Agency** tier pays the Platform Owner through lane A. That
Agency then resells the product to its own clients. Each client pays **the
Agency**, through the **Agency's own connected Stripe account**. That money is
the Agency's revenue and never touches the Platform Owner's Stripe balance.
The client's payment drives the client's **own** canonical Workspace plan
assignment and its **own** independent lifecycle.

```
Agency ──$497/mo (lane A)──▶ Platform Owner Stripe
Client ──$349/mo (lane C)──▶ AGENCY's connected Stripe        ← this contract
Client's end customer ──$X (lane B)──▶ CLIENT's connected Stripe
anyone ──usage top-up (lane D)──▶ platform wallet
```

**AgencyRebill is not lane C.** AgencyRebill (Addendum §10, Contract 09) is an
Agency paying for a client's *usage* out of the platform wallet — lane D with a
different payer. It is not SaaS revenue, it produces no Agency subscription, and
no lane-C record may be derived from it or vice versa.

---

## C1. Isolation — what lane C may never touch

Lane C code **must not** create, mutate, or read-as-authority:

| Artifact | Lane | Why not |
|---|---|---|
| `platform_subscriptions`, `platform_subscription_events` | A | The Agency's own subscription to us. A client's payment is not the Agency's payment. |
| `workspace_plan_catalog`, its pricing-change rows | A | The **Platform Owner's** commercial catalog. Agency resale pricing is the Agency's, not ours. |
| `business_documents`, `business_document_payments`, `business_document_refunds`, `business_payment_events` | B | A Business's own customer revenue. |
| `business_stripe_connections` | B | A *Business's* connected account. An Agency's SaaS-revenue account is a different commercial identity even when the underlying Stripe account is physically the same one. |
| `business_usage_wallets`, `business_usage_ledger_entries`, `payment_provider_customers`, `payment_provider_events` | D | Usage funding. |

And the reverse: no lane-A/B/D code may read a lane-C table to decide anything.

**One refusal-only exception, mirroring §C6.1 (four-lane conformance).** Lane C
reads `platform_subscriptions` purely to refuse an offer to a client that still
pays us directly. The mirror image is equally necessary: lane A's
`PlatformSubscriptionManager::startResubscribeCheckout()` reads
`agency_client_subscriptions` purely to **refuse** "start again" for a
Workspace its Agency is billing (any row not `offered`, `canceled` or
`incomplete_expired`). Without it a client whose old platform subscription had
ended could pay the platform *and* its agency for one Workspace. It creates,
mutates and resolves nothing of lane C's and references no
`App\Library\AgencyBilling` code. Platform reporting (Contract 21 §2.1) may
likewise read lane C to keep an agency client's grace/lock out of lane-A counts.

**A Stripe account may legitimately serve more than one purpose.** An Agency
that also sells to its own end customers may connect the same Stripe account for
lane B and lane C. That is the *merchant's* business. What must stay distinct is
the **record**: lane C keeps its own connection row, its own customer ids, its
own subscription ids, its own event stream and its own idempotency keys, so that
no lane-B document payment can ever be mistaken for lane-C subscription revenue
in either direction, and no lane-B event can resolve to a lane-C subscription.

**Namespace rule.** Lane C lives in `App\Library\AgencyBilling\*`. Nothing in
that namespace may reference `App\Library\PlatformBilling\*`,
`App\Library\Payments\*` or `App\Library\Usage\*`, and none of those may
reference `App\Library\AgencyBilling\*`. The one shared dependency is
`App\Library\Money\StripeMinorUnits` (§C3.4), which carries no commercial
identity at all.

---

## C2. Verified inventory of what already exists

Confirmed present and merged at `236462bf`:

| Thing | Path | Lane C uses it how |
|---|---|---|
| `AgencyClientWorkspaceRelationship` (+ `AgencyClientRelationshipStatus`) | `app/Models/AgencyClientWorkspaceRelationship.php` | THE authorization link. Lane C never invents a second one. |
| `AgencyClientRelationshipManager` | `app/Library/Workspace/AgencyClientRelationshipManager.php` | `actorHasAgencyAuthority()`, `agencyWorkspaceHasManagementEligibility()`, `findActiveForAgencyWorkspace()`, `findActiveForClientWorkspace()` — reused verbatim. |
| `ClientWorkspaceInvitation` + `ClientInvitationManager` + `AgencyClientProvisioningManager` | `app/Library/Workspace/` | The existing invite → claim → provision flow. Lane C attaches an *offer* to it; it does not replace it. |
| `AgencyClientsController` + `customer.workspaces.clients.*` routes | `app/Http/Controllers/Customer/Agency/` | Extended, not replaced. |
| `CustomerAccountAccessResolver` incl. `composeWithManagingAgency()` | `app/Library/Entitlement/` | Already implements Contract 05 exactly. Lane C **changes nothing here** and must not. |
| `EntitlementManager::assignFirstPlanFromVerifiedSubscription()` / `changePlanFromVerifiedSubscription()` / `startProviderConfirmedTrial()` / `enterGracePeriod()` / `lockForNonPayment()` / `recoverAccess()` | `app/Library/Entitlement/EntitlementManager.php` | THE only entitlement/lifecycle writers lane C ever calls. The "verified subscription" wrappers are lane-agnostic by construction — they take a durable subscription uid and a provider reference, not a lane. |
| `StripeConnectGateway` / `StripeConnectManager` / `BusinessStripeConnection` / `StripeConnectionStatus` | `app/Library/Payments/`, `app/Models/` | **Pattern reused, code not.** Lane C copies the proven onboarding shape (create account → hosted onboarding link → re-read capabilities → status) into its own boundary, because a `BusinessStripeConnection` row is a lane-B artifact and must never become the lane-C subscription authority. |
| Lane A's provider/checkout/webhook/finalizer design | `app/Library/PlatformBilling/` | **Pattern reused, code not** — every lane-C provider call additionally carries the Agency's connected account. |

**Correction to older planning documents.** `V1-IMPLEMENTATION-ROADMAP.md` and
Contract 07 §209 both describe the SaaS-plan step as possibly carrying "its own
narrower authority rule … to be confirmed". This contract confirms it: §C6.

---

## C3. Data model

Five new tables. Every one is lane-C-only and named so.

### C3.1 `agency_stripe_connections`

One Agency Workspace has **one active** SaaS-revenue Stripe connection in V1.

`uid`, `agency_workspace_id`, `stripe_account_id`, `status`
(`pending|onboarding|active|restricted|disconnected`), `charges_enabled`,
`payouts_enabled`, `details_submitted`, `default_currency`,
`requirements_disabled_reason`, `connected_by_user_id`, `connected_at`,
`disconnected_by_user_id`, `disconnected_at`, `last_synced_at`, `lock_version`.

- A **partial-unique** rule enforced in code under a Workspace row lock: at most
  one row per `agency_workspace_id` whose status is not `disconnected`.
- History is preserved. Disconnect never hard-deletes.
- `stripe_account_id` is a provider identifier, not a secret. **No secret key,
  no webhook secret and no OAuth token is ever stored in this table or rendered
  to the Agency** (Contract 21 §5.1 applies unchanged).

### C3.2 `agency_saas_plans`

The Agency's own resale catalog. Deliberately **not** `workspace_plan_catalog`.

`uid`, `agency_workspace_id`, `agency_stripe_connection_id`, `name`,
`description`, `tier` (a canonical `WorkspacePlanTier`), `price`,
`currency_id`, `currency_code`, `billing_cycle`, `trial_enabled`,
`trial_days`, `is_published`, `provider_price_id`, `created_by_user_id`,
`published_at`, timestamps.

- `tier` maps the resale plan onto a **canonical V1 capability tier**, because
  the product only knows how to entitle Core/Growth/Agency. The Agency chooses
  the name, the price and the story; it does not invent capabilities.
- An Agency may **not** map a resale plan to `Agency`: reselling the Agency tier
  would let a client manage its own clients through someone else's lane-A
  subscription. Allowed tiers are `Core` and `Growth`.
- `provider_price_id` must be a recurring Price **on that Agency's connected
  account** and must match `price`/`currency_code`/`billing_cycle` exactly
  (§C5.2).

### C3.3 `agency_saas_plan_pricing_changes`

`uid`, `agency_saas_plan_id`, `changed_by_user_id`, `from_price`, `to_price`,
`from_currency_code`, `to_currency_code`, `from_billing_cycle`,
`to_billing_cycle`, `from_provider_price_id`, `to_provider_price_id`, `reason`,
`created_at`.

**Changing a plan's advertised price never reprices an existing subscriber.**
Existing subscribers hold their own snapshot (§C3.4); this table is the audit of
what the Agency published, when, and why.

### C3.4 `agency_client_subscriptions`

The canonical lane-C commercial record. One row per (agency, client) pair —
`unique(client_workspace_id)`, because a Client Workspace has 0 or 1 managing
Agency (Addendum §2) and therefore 0 or 1 lane-C subscription.

Identity and binding:
`uid`, `agency_workspace_id`, `client_workspace_id`, `agency_saas_plan_id`,
`agency_stripe_connection_id`, `connected_account_id`, `local_idempotency_key`.

Commercial snapshot, captured at purchase and never rewritten by a catalog edit:
`price_snapshot`, `currency_id`, `currency_code`, `billing_cycle_snapshot`,
`trial_days_snapshot`.

Provider truth, written only by the finalizer:
`status`, `provider_customer_id`, `provider_subscription_id`,
`provider_price_id`, `provider_checkout_session_id`, `current_period_start`,
`current_period_end`, `trial_ends_at`, `cancel_at_period_end`, `canceled_at`,
`ended_at`, `last_event_id`, `last_event_at`, `last_reason`.

Checkout attempt identity (lane A §7.3 pattern):
`checkout_attempt_uid`, `checkout_attempt_price_id`,
`checkout_attempt_started_at`, `checkout_attempt_generation`.

Durable plan-change operation (lane A §10.2.1 pattern):
`pending_operation_uid`, `pending_kind`, `pending_plan_id`,
`pending_price_id`, `pending_price_snapshot`, `pending_currency_id`,
`pending_currency_code`, `pending_billing_cycle`, `pending_effective_at`.

Re-subscribe history: `retired_provider_subscription_ids` (json).

**`connected_account_id` is denormalized onto the row on purpose.** Every
provider call and every inbound event is checked against it, so a subscription
can never be driven by an event from a different Agency's account even if the
connection row is later changed.

There is deliberately **no card attribute of any kind**.

### C3.5 `agency_client_subscription_events`

`uid`, `agency_client_subscription_id`, `agency_workspace_id`,
`connected_account_id`, `provider_event_id` (unique), `event_type`,
`provider_subscription_id`, `provider_customer_id`, `provider_created_at`,
`payload_encrypted`, `state`, `attempts`, `processing_started_at`,
`lease_expires_at`, `last_attempt_at`, `completed_at`, `last_error`.

`unique(provider_event_id)` is what makes duplicate delivery a no-op at the
database, not at the application's discretion.

### C3.6 Shared, identity-free helper

`App\Library\PlatformBilling\CurrencyMinorUnits` moves to
`App\Library\Money\StripeMinorUnits`, unchanged in behaviour.

This is the one thing both lanes share, and sharing it is deliberate: it encodes
Stripe's zero-decimal list and the ISK/UGX whole-unit rule, and two lanes
holding two copies of that would eventually disagree about what the provider
will actually charge. It has no commercial identity — no account, no customer,
no record — so it breaks no isolation rule. Lane A's references are updated in
the same change; behaviour is byte-identical and lane A's suite proves it.

---

## C4. Provider boundary

`App\Library\AgencyBilling\AgencyStripeGateway` (interface) +
`StripeApiAgencyGateway` (the only lane-C class permitted to touch the Stripe
SDK).

**Every method that touches Agency revenue takes the connected account id as its
first argument and sends it as `Stripe-Account`.** There is no method that could
accidentally operate on the platform account, and no call site can omit it.

```
createAccount(country, email, agencyWorkspaceUid)         → AgencyAccountSnapshot
createOnboardingLink(accountId, refreshUrl, returnUrl)    → string
retrieveAccount(accountId)                                → AgencyAccountSnapshot
retrievePrice(accountId, priceId)                         → AgencyPriceSnapshot
createPrice(accountId, ...terms, idempotencyKey)          → AgencyPriceSnapshot
createSubscriptionCheckout(accountId, ...)                → AgencyCheckoutSessionResult
retrieveCheckoutSession(accountId, sessionId)             → AgencyCheckoutSessionResult
expireCheckoutSession(accountId, sessionId)               → AgencyCheckoutSessionResult
retrieveSubscription(accountId, subscriptionId)           → AgencySubscriptionSnapshot
changeSubscriptionPrice(accountId, ..., idempotencyKey)   → AgencySubscriptionSnapshot
setCancelAtPeriodEnd(accountId, subscriptionId, bool)     → AgencySubscriptionSnapshot
createBillingPortalSession(accountId, customerId, ...)    → string
verifyWebhookPayload(rawPayload, signatureHeader)         → array
configurationStatus()                                     → array
```

Only normalized value objects cross this boundary. No `Stripe\*` object, no raw
payload, no API key and no provider error text ever reaches a caller — lane C
reuses lane A's closed reason-code vocabulary shape in its own
`AgencyBillingException`.

**Direct charges on the connected account.** Lane C sets `Stripe-Account` and
sets **no** `application_fee_amount` and **no** `transfer_data`. The platform
takes no cut of the Agency's resale revenue in V1, exactly as Contract 17 §11.2
forbids taking a cut of a Business's revenue.

### C4.1 Webhooks — a fourth endpoint and a fourth secret

`POST /stripe/webhook/agency-subscriptions`, signed with
`services.stripe.agency_subscription_webhook.secret`
(`STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_SECRET`). Four lanes, four endpoints, four
secrets. Environment configuration only — never a database column, never
rendered to a browser.

Because these are **Connect** events, every event body carries an `account`
field. Processing requires all of:

1. the raw-body signature verifies;
2. `account` is present and resolves to a **known, non-disconnected**
   `agency_stripe_connections` row;
3. the resolved local subscription's `connected_account_id` equals that
   `account`;
4. the provider subscription id is not in that row's
   `retired_provider_subscription_ids`.

Any failure is recorded with a reason code and processed no further. An event
for Agency X can never move Agency Y's subscription, and a lane-B Connect event
arriving here cannot resolve at all.

---

## C5. Agency Stripe connection and SaaS plans

### C5.1 Connection

`AgencyStripeConnectManager`: `liveConnection()`, `connect()`,
`resumeOnboarding()`, `syncFromProvider()`, `disconnect()`, `isChargeReady()`,
`history()` — the proven `StripeConnectManager` shape, on lane-C rows.

- **Authorization: the Agency Workspace OWNER only.** Not Admin, not Staff. This
  is the account that receives the Agency's revenue; Blueprint §2 already
  reserves financial consent/payer selection to the owner, and §26 keeps Staff
  out of billing entirely. Agency team membership grants client *management*,
  never revenue configuration.
- **Never through View As.** `ViewAsManager`'s active session blocks every
  lane-C financial mutation, exactly as it does for AgencyRebill.
- Readiness is **re-read from the provider**, never assumed from a stored flag
  older than the current operation.
- **Disconnect is deterministic and honest:** it marks the connection
  `disconnected` and **does not** cancel existing client subscriptions at the
  provider — we do not have the right to end the Agency's billing relationships
  on its behalf. It *does* immediately refuse every new lane-C charge, surfaces
  the affected subscriptions to the Agency, and leaves each client's own
  lifecycle untouched until real provider truth arrives.

#### C5.1.1 "May it sell?" and "whose event is this?" are different questions

Because disconnecting leaves existing subscriptions running, they keep renewing,
failing and cancelling at the provider — and every one of those events names the
account that has since been disconnected. Resolving event identity through the
*current* connection list alone failed them as "unknown account", and each
affected client's independent lifecycle silently stopped updating: a client
could be charged, or cancelled, and the product would never learn of it.

So the two questions are answered by two methods:

| Question | Method | Scope |
|---|---|---|
| May this connection take a **new** charge? | `chargeableConnection()` | Current statuses only, plus `charges_enabled` re-read from the provider. A disconnected account can never sell again. |
| **Whose** event is this? | `findOwningConnectionForEvent()` | Any connection the Agency has ever held for that account id, **including disconnected**, newest first. |

The historical lookup answers ownership and stops. It does not reconnect,
re-enable or resurrect anything, it never makes a connection chargeable, and no
selling path consults it. The webhook still independently proves that the
event's account matches the stored subscription's account, that the subscription
belongs to the Agency owning that connection, that the provider identities line
up, that the event type is in lane C's closed set, and that the subscription is
not a retired one — so an arbitrary Stripe account named in an event still
resolves to nothing.

**If access has actually been revoked at Stripe**, provider truth cannot be
retrieved and the event stays visibly **Failed** with the safe reason code
`provider_failed`. Nothing is taken from the unverified payload: no renewal, no
cancellation, no Active state. `Failed` is also recoverable — the claim's own
`WHERE` admits a failed row — so the same event can be re-driven once access is
restored.

### C5.2 Price parity

Before a plan may be published, its `provider_price_id` is retrieved **from that
Agency's connected account** and must satisfy all of:
`active`, `type = recurring`, `currency` equal, `unit_amount` equal to
`StripeMinorUnits::toMinor(price, currency)`, `recurring.interval` matching the
billing cycle, `recurring.interval_count = 1`, `livemode` matching the platform
mode.

A Price that lives on the **platform** account, or on another Agency's account,
is simply not retrievable with that Agency's `Stripe-Account` header — which is
what makes cross-account substitution structurally impossible rather than merely
checked.

The Agency may also have the Price **generated** for it from the terms it typed
(`createPrice`), which is the path that needs no Stripe dashboard visit. Either
way parity is verified before publication.

---

## C6. Enrollment and the client's financial consent

**The Agency may never fabricate the client's consent.** This is the central
rule of lane C and it is not negotiable for product convenience.

Canonical flow:

```
Agency publishes a SaaS plan
  → Agency selects an existing managed client, or invites a new one
  → Agency OFFERS that plan to that client            (no charge, no card)
  → client signs in and REVIEWS the real terms        (name, price, cycle, trial)
  → CLIENT authorizes payment in Stripe-hosted Checkout, on the Agency's account
  → provider confirms
  → client's own workspace_plan_assignment is assigned/changed
  → client's own lifecycle becomes usable
  → client reaches Home
```

Rules:

- Creating an offer is a **proposal**, not a subscription. It writes an
  `offered` lane-C row with the terms snapshotted, and reaches no provider.
- Only a **client-side authorized actor** — the Client Workspace owner, or an
  active Admin of it with account-frame authority — may start Checkout. The
  Agency owner cannot, Agency Staff cannot, a Platform administrator cannot, and
  a View As actor cannot. Financial consent belongs to the payer.
- **View As is blocked structurally**, not by convention: the consent endpoint
  refuses whenever a View As session is active, and the test suite proves it.
- Sending an invitation charges nothing.
- **Existing clients keep everything** — Workspace, Business, Locations,
  members, data and the Agency relationship. Enrollment never re-provisions.

### C6.1 Eligibility and refusal — the explicit policy

A managed client may already be paying us directly (lane A) or be carrying a
complimentary assignment. Creating a second paid authority silently is how a
customer ends up charged twice for one account, so:

| Client's current state | Lane C offer | Why |
|---|---|---|
| No plan assignment | **Allowed** | Nothing competes. |
| Assignment, no live lane-A subscription (e.g. provisioned by the Agency) | **Allowed** | The Agency is already the commercial owner. |
| Live lane-A subscription (`trialing`/`active`/`past_due`/`unpaid`/`paused`) | **Refused**, reason `client_has_platform_subscription` | Two paid authorities for one Workspace. The client must cancel their direct subscription first; we will not do it for them, because we are not their agent. |
| Lane-A subscription that has fully ended (`canceled`/`incomplete_expired`) | **Allowed** | Nothing live competes; the ended lane-A row is history and is left intact for audit. |
| `is_complimentary` assignment | **Refused**, reason `client_is_complimentary` | The Platform Owner is deliberately carrying that account; an Agency may not start charging for something we are giving away without an explicit Platform Owner decision. |
| Already has a live lane-C subscription | **Refused**, reason `client_already_subscribed` | §C7's plan-change path exists for that. |

Refusals are surfaced to the Agency as plain copy with the reason, never as a
silent no-op. There is deliberately **no automatic migration** from lane A to
lane C: cancelling a customer's direct subscription on their behalf is a
financial action nobody in this system is authorized to take.

---

## C7. Subscription lifecycle

Lane C reuses lane A's proven mechanics, each scoped to the connected account.

- **Checkout attempt identity** — `agency-subscription:{uid}:attempt:{attempt_uid}`,
  with `checkout_attempt_generation` as the compare-and-swap token, bounded
  retry, orphan-session expiry, and no DB lock held across a provider call. At
  most one payable session per client subscription.
- **Activation without the browser** — the webhook alone finishes enrollment.
- **Durable plan-change operations** — `agency-subscription:{uid}:change:{op_uid}`,
  persisted before the provider is touched; upgrade immediate, downgrade at the
  period boundary on the terms agreed **at request time**; convergence gated on
  provider truth showing the target Price active; entitlement never widened on
  intent alone.
- **Re-subscribe after termination** — same Workspace/Business/Locations, new
  provider subscription, old subscription id retired and thereafter refused by
  the finalizer's cross-check.
- **Lifecycle mapping**, through `EntitlementManager` and nothing else:

| Provider status | Canonical effect on the CLIENT Workspace |
|---|---|
| `trialing` | `startProviderConfirmedTrial()` — usable, provider-confirmed trial end |
| `active` | `recoverAccess()` |
| `past_due` / `unpaid` / `paused` | `enterGracePeriod()` — one 3-day window |
| `canceled` / `incomplete_expired` | `lockForNonPayment()` |
| `incomplete` | nothing |

- **Client data is never deleted** by any lane-C transition.

### C7.1 Isolation of lifecycles

- One client's non-payment affects **that client only**. Lane C never reads or
  writes another client's assignment, and never touches the Agency's own
  `platform_subscriptions` row or the Agency's own assignment.
- The Agency's lane-A delinquency composes as an **upstream prerequisite**
  through `CustomerAccountAccessResolver::composeWithManagingAgency()`, which
  already exists and is already correct. Lane C **adds nothing** to that
  composition and must not: a locked Agency makes its clients' *effective*
  access unusable without overwriting a single byte of any client's own
  lifecycle state, and recovery restores each client according to its own state.

---

## C8. Surfaces

**Agency** (owner unless stated; Agency team members may *view* what Blueprint
§28 already lets them view):

- Stripe connection: connect, resume onboarding, status, refresh, disconnect
- SaaS Plans: list, create, edit, generate/verify the Stripe Price, publish,
  unpublish, subscriber count, pricing history
- Clients: each client's lane-C subscription status and any failed-payment
  attention item
- Offer a plan to a managed client / invite a new client with a plan attached
- Agency SaaS revenue, **labelled as the Agency's own revenue**, never mixed
  with platform revenue and never presented as ours

**Client** (Client Workspace owner or active Admin with account-frame
authority — never Staff, never the Agency, never View As for the money actions):

- Review the Agency's offer: plan name, description, price, currency, cycle,
  trial, and who is charging them
- Consent and pay through hosted Checkout
- See subscription, trial and billing status
- Update payment method (Agency-hosted Stripe Billing Portal)
- Cancel / resume
- Recover from Grace/Locked
- Re-subscribe after termination

---

### C8.2 A locked client must be able to pay their way out

A Client Workspace reaches **Locked** exactly as any other does: the renewal
failed, grace elapsed, or the subscription ended. That the money is owed to
their *agency* rather than to us changes who is paid and nothing about the
customer's right to reach the one action that ends the lock.

`CustomerAccountAccessGate`'s exact-name allowlist therefore carries five
lane-C names alongside lane A's five:

| Route | Why |
|---|---|
| `customer.workspaces.agency-plan.show` | what they owe, and to whom |
| `customer.workspaces.agency-plan.payment-method` | the agency-hosted Billing Portal — the action that ends the lock |
| `customer.workspaces.agency-plan.checkout` | pay a **fresh offer** (see below) |
| `customer.workspaces.agency-plan.resubscribe` | buy again after it ended |
| `customer.workspaces.agency-plan.return` | confirm either of the two above |

**`.return` must stay reachable while still Locked.** The browser comes back
from hosted Checkout *before* provider confirmation has converged, so at that
instant the account is still locked. A gate that bounced it would strand a
customer who has just paid.

**`.checkout` is here for a real case, not for symmetry.** A client who was
locked, and whose agency then offers them a FRESH plan, holds an `offered`
subscription — so `.resubscribe`, which requires a terminal one, correctly
refuses them. Paying that offer is their only way out. It is allowlisted only
because the proofs the gate cannot make are already made elsewhere:
`AgencyPlanController`'s owner-or-active-Admin rule, and the domain's checks
that the offer exists, that the managing relationship is active, that the plan
is sellable, that the agency's account can actually take money, and that the
actor is not inside a View As session.

**Deliberately NOT allowlisted:** `.change`, `.cancel`, `.resume`. Those are
ordinary billing mutations, not recovery, and a locked account has no business
making them.

Allowlisting grants no authority and no operational access: Staff, strangers
and the managing agency are all still refused by the controller and the domain,
and every other route on a locked Workspace stays gated.

**A re-offer retires the ended subscription.** One row per client means a
customer being offered a fresh plan reuses the row that still named the
provider subscription of the life that ended. Leaving it there made the new
purchase unconfirmable — the finalizer's cross-check compared the stored id
against the newly created one, saw a mismatch, and correctly refused, so the
client would pay and never be activated. `offer()` now moves that id to
`retired_provider_subscription_ids`, which both keeps the audit trail and keeps
the guard honest: a late event from the old subscription is still refused,
now because it is retired rather than because it happens to be current.

### C8.3 An agency's own lapse is never the client's payment failure

When the managing agency's own account is locked, the client's page reads
**Unavailable**, explains that it is the agency's account status, and shows no
"we could not take your payment" alert and no CTA implying the client can fix
it — because they cannot. Paying the agency again does not lift an
agency-caused lock, and the client's own `locked_at`/`grace_started_at` are
never written by it. Contract 05's composition, unchanged, is what produces
this; lane C only translates it.

## C8.1 Deployment prerequisites

Three, and none of them is optional:

1. **`STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_SECRET`** must be set, and a Stripe
   **Connect** webhook endpoint must point at
   `POST /stripe/webhook/agency-subscriptions` with lane C's closed event set.
   Without it lane C accepts no provider truth at all.
2. **The Platform Owner's canonical catalog must have Core and Growth priced.**
   `EntitlementManager::assignFirstPlan()` asserts base pricing before it will
   assign a non-complimentary plan, and lane C deliberately does **not** bypass
   that: the catalog row carries the capacity and slot rules the assignment
   depends on, so an unpriced tier is an unconfigured tier whoever is paying. A
   deployment that has not priced them cannot enroll agency clients onto them,
   and says so rather than assigning something half-configured.
3. **Each Agency must connect its own Stripe account** and finish Stripe's
   hosted onboarding before it can publish a plan. Nothing in lane C falls back
   to the platform account, ever.

## C9. Exit condition for lane C

Lane C is complete only when an Agency can connect Stripe, publish a plan,
enroll a client, have that client **explicitly** subscribe through hosted
Checkout, receive the recurring revenue in the **Agency's** Stripe account, and
have the client's independent Workspace plan and lifecycle operate correctly
through trial, renewal, failure, Grace, Locked, recovery, plan change,
cancellation and re-subscribe — with no unfinished Agency UI, no unfinished
client UI, no unfinished provider activation, and no second entitlement or
subscription authority anywhere.
