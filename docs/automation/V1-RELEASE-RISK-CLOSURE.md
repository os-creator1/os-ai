# V1 release-risk closure — final code hardening pass

Base: `origin/agent/v1-completion-integration` @ `124a8666`. Branch: `agent/v1-release-risk-closure`.
Scope: exactly the seven known release risks below. No features, no redesign.

Regression suites: `tests/Feature/Security/V1ReleaseRiskClosureTest.php` (items 1, 4, 5, 6, 7),
`tests/Feature/PlatformBilling/V1ReleaseRiskOnboardingTest.php` (items 2, 3),
`tests/Unit/ContactPhoneTest.php` (item 7 unit level).

| # | Risk | Verdict on `124a8666` | Authority used for the fix |
|---|------|-----------------------|----------------------------|
| 1 | Managed inbound STOP | REAL + FIXED | `App\Library\Messaging\InboundOptOutHandler`, called from `InboundWebhookAttributionResolver::persistInbound()` (same transaction as the history bridge) |
| 2 | Subscription webhook reopens onboarding | REAL (narrow) + FIXED | `V1SignupManager::handOffToOnboarding()` hands off only a `Draft` Business |
| 3 | Unpaid onboarding activation | REAL + FIXED | `BusinessManager::activateForCompletedOnboarding()` requires `CustomerAccountAccessResolver::hasActiveSubscription()` under the Workspace lock |
| 4 | Unsigned legacy inbound / DLR | REAL + FIXED (79 disabled, 6 kept) | `DisableUnsupportedLegacyWebhooks` middleware + `LegacyWebhookRouteRegistry::SUPPORTED`; Twilio signature on `dlr.twilio` and `inbound.webhook` |
| 5 | Sub-account privilege ceiling | REAL + FIXED | `EloquentSubAccountRepository::permissionsWithinParentCeiling()` (store + update) |
| 6 | Public subscribe/unsubscribe throttling | REAL + FIXED | named limiter `public-contact-consent` (`RouteServiceProvider`), applied in `routes/public.php` |
| 7 | Phone normalization / Contact matching | REAL + FIXED | `App\Library\Contacts\ContactPhone` |

## 1. Managed inbound STOP

The managed webhook (`inbound/telnyx-managed`) stored the message and stopped: the sender stayed
`contacts.status = subscribe` and on no blacklist. The legacy path (`DLRController::inboundDLR`) always honoured
STOP. The managed path now applies the same two authorities, Business-scoped:

* `contacts.status = unsubscribe` for every Contact of the *receiving Business* whose phone matches
  (`ContactPhone::candidates()`); this is the state automation SMS, campaigns and `contact.subscribed` read.
* a `blacklists` row (`business_id` = the Business, `user_id` = its customer, canonical digits) — the gate
  `EloquentCampaignRepository::quickSend()` checks before any individual send. Created only when missing.

A STOP is the **whole message** being `STOP`, `STOPALL`, `UNSUBSCRIBE`, `CANCEL`, `END` or `QUIT`
(case/punctuation-insensitive). "please stop by at 3" is a normal reply. Duplicate STOP and provider
redelivery change nothing further. The conversation and inbound message are kept.
START/resubscribe is deliberately **not** handled: re-consent in this product is explicit (the owner re-enabling a
Contact — refused while a blacklist row exists — or a keyword opt-in on a group), so nothing is invented here.
Email consent is not read or written.

## 2. Renewal must not reopen onboarding

Every provider-confirmed event (renewal `invoice.paid`, `customer.subscription.updated`, replays) reaches
`V1SignupManager::activateFromConfirmedSubscription()`, which always ran the onboarding hand-off. For an Active
Business that has no onboarding row (a repaired / historical account) that created a new *required* onboarding row and
sent the customer back into the wizard. The hand-off now runs only for a `Draft` Business. Initial paid signup is
unchanged (`V1SignupOnboardingHandoffTest`), replay stays idempotent.

## 3. Unpaid activation bypass

A signup that never paid still owns a Workspace and a Draft Business. A signed-in user could open `/onboarding`
(opening it starts a voluntary row), walk the wizard — adopting the Draft Business — and `complete` it, which moved
the Business to Active with no plan assignment (proved on `124a8666`). Activation now requires
`hasActiveSubscription()` (assigned plan, not locked) under the Workspace lock; otherwise
`WorkspacePlanUnassignedException` / `InactiveWorkspacePlanException` is thrown, the completion transaction rolls back,
and the controller redirects with the existing safe capacity message. Test fixtures that complete onboarding now give
the Workspace a plan (`assignPaidPlanFixture`), exactly as a paid customer has.

## 4. Legacy inbound / DLR routes

Inventory on the current tree: **85** public routes whose first path segment is `inbound` or `dlr` (48 inbound,
37 dlr), all declared in `routes/public.php`.

V1's customer-facing providers are Twilio and Telnyx (`BusinessMessagingProviderCatalog`) plus the platform-run managed
Telnyx route. The other gateways cannot be connected by a V1 Business and never verified their caller.

**Remain publicly reachable (6)**

| Route | Authentication |
|-------|----------------|
| `POST inbound/telnyx-managed` | Telnyx Ed25519 signature (`MessagingProviderAdapter::verifyInboundSignature`), dual-signal attribution, `throttle:600,1` |
| `inbound/twilio/{gateway?}` | `X-Twilio-Signature` HMAC against an active Twilio sending server's auth token |
| `inbound/twilio-copilot/{gateway?}` | same, Twilio Copilot server |
| `inbound/webhook/{user}` | same; now verified **before** the owner's `webhook_url` is POSTed to (it used to forward first) |
| `dlr/twilio` | same; **new** — it updated delivery reports with no verification |
| `inbound/telnyx/{gateway?}` | none possible for BYO Telnyx: always rejected without state change (`hasVerifiableTelnyxAuthenticity()` is `false`) |

**Disabled (79)** — every other `inbound/*` / `dlr/*` route answers `410 {"status":"disabled"}` from
`DisableUnsupportedLegacyWebhooks` before its controller runs, so nothing a forged request carries can reach a
Business, Contact, conversation or report. The block is default-deny on the route URI, so a newly added
`inbound/*` / `dlr/*` route is refused until it is listed in `LegacyWebhookRouteRegistry::SUPPORTED` with its
authentication. Usage measurement (`RecordLegacyWebhookUsage`, `terminate()`) still counts hits on refused routes,
so decommission evidence keeps accumulating. The route declarations were left in place (registry, measurement and
`route()` names unchanged); removing them is a later cleanup.

Not part of this family and unchanged: `webhooks/prospecting/*` (HMAC token + Twilio signature) and
`webhooks/calendar/*` (HMAC token), `stripe/webhook/*` (Stripe signature).

## 5. Sub-account privilege ceiling

`EloquentSubAccountRepository::store()/update()` saved `array_values($input['permissions'])` verbatim: a parent could
grant any string — a permission it did not hold, an unknown/admin-looking key — by posting it directly (the form
only *offered* the parent's set). Both now call `permissionsWithinParentCeiling()` first: every requested permission
must be a string, be in the parent's own held set (its session permissions, which every `can:` gate reads; falling
back to `customers.permissions`) **and** be a defined `config/customer-permissions` key. Anything else is a
validation error (fail closed — no account is created or changed). Tenant scoping was already enforced
(`ownedSubAccountOrAbort()` → 404) and `is_admin`/`parent_id`/`status` were already whitelisted out; sub-accounts
cannot reach these routes at all (`customer.sub_only`).

## 6. Public subscribe / unsubscribe throttling

`POST contacts/{contact}/subscribe-url` and `POST contacts/{contact}/unsubscribe-url` were unthrottled. They now use
the named limiter `public-contact-consent`: 10/min per visitor **per group** and 120/min per group. Group, captcha and
validation logic is untouched; the keys include the group uid so one Business's traffic never spends another's
allowance. (This application's handler renders a 429 as its 404 page outside `local`, as it does for every other
throttled route — pre-existing, unchanged.) Public unsubscribe now also matches the number through
`ContactPhone`, so a person typing `(415) 555-1234` can actually withdraw consent for the Contact stored as
`14155551234`.

## 7. Canonical phone normalization

`contacts.phone` stores digits with the country code and no `+`. Every seam stripped `+ - ( )` and spaces only, so
`+1 (415) 555-1234` (→ `14155551234`) and `415-555-1234` (→ `4155551234`) created two Contacts.
`ContactPhone` is now the one path:

* explicit international numbers keep their E.164 digits (so existing rows are byte-identical — nothing is rewritten);
* a number written without a country code resolves against the Location's, else the Business's, country **only if
  libphonenumber says it is valid there**; with no usable country it stays exactly as typed — never guessed;
* `candidates()` returns every stored form an equivalent number may carry (canonical, as-typed digits, and the
  historical national form for a number local to the Business's country) so a lookup finds old rows too.

Used by: public booking, standalone Forms and Website forms (`EloquentContactsRepository::findOrCreateFor*`), manual /
Add-contact (`createContactFromRequest`, including a duplicate check against historical national-form rows), public
unsubscribe, inbound STOP, and the message-received trigger's Contact lookup. All matching is Business (and, for
forms/booking, Location) scoped *first*; normalization only decides equivalence inside that scope.
`MessageReceivedTriggerSource::normalizePhone()` (the conversation-thread key) keeps its digits-only behaviour, now
delegating to `ContactPhone::legacyDigits()`.

Related isolation fix: the form/booking seams checked "is this number blacklisted" by number alone
(`Contacts::isListedInBlacklist()`), i.e. across every Business. A managed STOP is recorded per Business, so left as
is, Business A's STOP would have blocked Business B's booking form. They now check
`blacklistedForBusiness()` (own rows, the customer's un-attributed legacy rows, platform-admin rows).

## Known residuals (not changed)

* `inbound.telnyx` stays routed but is inert (no verification material for BYO Telnyx).
* `dlr.twilio` validates against *any* active Twilio sending server's token (the existing validator); a Twilio account
  holder would still need another tenant's unguessable `MessageSid` to affect its report.
* The 79 disabled route declarations remain in `routes/public.php` (answering 410) pending a measured removal.
