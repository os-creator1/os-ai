# Implementation Contract 17 — Proposal / Contract / E-Signature / Invoice

**Status:** Planning contract only. Does not authorize implementation.
Recon was performed against `main` @ `30ad21c7`; §3.7 records what has
changed on `main` since, without rewriting the dated evidence. Seven
dependency-ordered sub-slices (§12/§18, A–G) implement this contract;
**no sub-slice below may start without its own separate, explicit human
authorization**, matching this repository's route-3 governance
(`CLAUDE.md`).

**No sub-slice is blocked on an unanswered product decision any more.**
Two decisions an earlier revision deferred are now locked: the Stripe
Connect commercial/funds-flow posture (§11.2 — SaaS direct-charge, the
connected Business is merchant of record) and the e-signature engineering
scope (§6.5 — first-party typed evidence, implementable now, with the
legal/compliance review moved to a release gate rather than a
Sub-slice C blocker).

This slice handles **money lane B** (Addendum §12) and nothing else. The
single most important rule in this document is §4: every existing payment
artifact in this repository belongs to lane A or lane D, and **none of it
may be reused**.

## 1. Objective

Build the V1 Payments & Contracts module: a Business authors a Proposal
from its Contract 16 catalog, sends it to an end customer over a secure
non-guessable emailed link, the customer signs it and pays — deposit plus
balance or in full — through **that Business's own connected Stripe
account**; with automated reminders, offer expiration, and refunds, and a
document lifecycle whose evidence is durable (§10 states precisely what
that evidence is and is not). Every transactional document is
Location-attributed and carries immutable Contract 16 package/price
snapshots, so a later catalog change can never alter a document already
issued.

## 2. Governing authority

- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` **§18** (Payments &
  Contracts) — the product spec, quoted in full in §3.1. Also §5 (the
  Business-Wide vs Location-Bound matrix, whose only Payments row is
  "Connected Stripe account (§12, §18)" on the Business-wide side), §9
  (the rule that payment progress never advances a pipeline stage), §13
  (a "proposal-sent follow-up" starter automation, which requires a
  canonical proposal-sent event), §21 (Payments & Contracts is in all
  three tiers), §24 (payment events reach the Activity Center; documents
  are searchable), §26 (the two staff-access axes: feature permission +
  Location ACL — the "one permission, not a CRUD matrix" cardinality is
  Contract 16 §15's precedent, not §26's rule, see §6.1), §34 (the V1/V2
  boundary rows quoted in §3.1), §36 (Implementation Principles, whose
  "recheck every paid side effect immediately before execution" and "use
  idempotency for every paid or otherwise non-repeatable action" rules
  bind §7, §8 and §11.4), §37 (the V1 acceptance clause).
- `docs/rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md` **§12** (Money lanes)
  — the hard architectural rule, quoted in full in §3.1; plus §9 and §10,
  referenced in §4 as the lanes this slice must never touch, and §5
  (Location ownership of operational records).
- `docs/product/V1-ACCEPTANCE-MATRIX.md` — the Business Owner row "Sell
  from a catalog, collect signed & paid agreements" and the **End
  Customer / Lead** row "Sign/pay a document", both quoted in §3.1. The
  End Customer row is the only authority that states the public
  permission boundary ("Possession of the secure link") and that the link
  is **emailed** — which is why email is the canonical V1 delivery path
  (§11.3).
- `docs/product/V1-AUTHORITY-TRACEABILITY-MATRIX.md` **row 15** — "PARTIALLY
  ALIGNED … Invoicing/payment exists; Proposal/Contract/e-signature layer
  is largely net-new". That row is explicitly flagged by its own document
  (lines 57–61) as a *lighter existence-check pass* that "should be
  re-verified with a full read before an implementation slice depends on
  their exact current shape" — §3.2/§4 below is that full read, and it
  materially corrects the row's implication (§3.3).
- `docs/product/implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md`
  — merged, and its Sub-slice A now implemented (§3.7). Its §5.3
  (`package_snapshots`), §12.D (`PackageSnapshotService::snapshot()`), and
  §15 (non-goals) bind this contract directly and are quoted in §3.4.
- `docs/product/V1-IMPLEMENTATION-ROADMAP.md` — Slice 17, XL complexity,
  Medium risk, Wave 2 Lane E, whose only stated dependency is "F/Wave1's
  package snapshots" (i.e. Contract 16's snapshot service — §16 states the
  real, stricter gate).

## 3. Current repository reality — recon findings (`main` @ `30ad21c7`)

### 3.1 The complete normative authority, verbatim

Blueprint §18 in full (lines 381–391) — nine lines of prose that are the
entire product authority for this slice:

> ## 18. Payments & Contracts
>
> Covers Proposal → Contract (with e-signature) → Invoice, payment links,
> deposit-plus-balance collection, automated reminders, expiration, and
> refunds — all through the Business's own connected Stripe account
> (Addendum §12, one per Business in V1), with every transactional document
> carrying Location attribution (§17) and immutable package/price snapshots.
> The document lifecycle (draft → sent → signed/paid → expired/void) is
> tracked per document; access to an unsigned/unpaid document is via a secure,
> non-guessable link. A dedicated client-facing portal is **V2/low priority**
> for V1 (§34) — the secure per-document link is the V1 mechanism.

Addendum §12 in full (lines 233–246):

> ## 12. Money lanes
>
> These **MUST** remain separate ledgers/Stripe relationships:
>
> - **A. SaaS subscription:** customer or Agency → platform Stripe.
> - **B. Business customer revenue:** the Business's end customer → that
>   Business's connected Stripe account.
> - **C. Agency SaaS client subscription:** Client → the Agency's connected
>   Stripe account.
> - **D. Usage:** internal prepaid Business wallet plus the central
>   provider/Telnyx, with payer resolved through the canonical payer authority
>   (§9, §10).
>
> 1 Business = 1 connected Stripe account in V1.

Acceptance Matrix, Business Owner (line 31) and End Customer / Lead
(line 85):

> | Sell from a catalog, collect signed & paid agreements | Package catalog, proposal/contract/invoice flow with e-signature and payment | Core+ | BW (catalog) / LB (transactions) | Owner + staff per feature permission | Document lifecycle (§18) | Yes | Every transactional document must snapshot the package/price immutably (Addendum §14) | Owner can send a proposal, get it signed, and collect payment without leaving the product | Not yet implemented — no Package/Proposal/Contract model found (rows 14, 15) |

> | Sign/pay a document | Secure link, sign and pay without an account | N/A | LB | Possession of the secure link | Document lifecycle (§18) | Costs the Business | Link must be non-guessable | A customer signs and pays via the emailed link | Not yet implemented (row 15) |

Blueprint §34's V1/V2 boundary rows, which bound this slice's scope more
tightly than §18 alone does:

> | Secure per-document links (§18) | Richer dedicated client portal |
> | Deposit + balance (§18) | Complex installment plans |
> | One Stripe account per Business (§18) | Per-Location Stripe accounts |

**Reading the `Paid? = Yes` column.** Per the Acceptance Matrix's own
column key, `Paid?` means "triggers a wallet-checked paid side effect
(Blueprint §20)" — the lane-D wallet. Blueprint §20 never mentions lane B,
Stripe, invoices, or customer revenue, and **this slice creates no
wallet side effect at all** (§11.3): V1 document delivery is email, which
touches no wallet. This contract therefore does not invent a wallet
interpretation for that column; it records that the column is not
satisfied by anything in this slice, and leaves it to whatever later,
separately authorized integration lets a user share a document link
through Conversations.

### 3.2 Confirmed absent on `main` — genuinely net-new

Exhaustive search found **zero** of: a `Proposal`, `Contract` (in the
commercial-document sense — `app/Repositories/Contracts/*` and
`app/Library/**/Contracts/*` are PHP interface-namespace noise), `Agreement`
(the only matches, `AdditionalBusinessSlotAgreement` and
`AdditionalBusinessSlotAgreementTransition`, are RFC-005 platform-Stripe
billing records — lane A/D, §4.3), `Signature`, `Signer`, or `Document`
model; **zero** e-signature vendor in `composer.json`/`composer.lock` (210
locked packages — 164 production plus 46 dev — searched for DocuSign,
Dropbox Sign, HelloSign, Adobe Sign, SignWell, PandaDoc, and any `*sign*`
package); **zero** Stripe Connect capability anywhere (§4.2); **zero**
refund *issuance* capability in any lane (§4.5); **zero** PDF generation
capability (§3.6); and **zero** persisted `line_item`/`LineItem` domain
concept — the only `line_items` matches repo-wide are Stripe Checkout
Session request payload keys in `StripePaymentProviderGateway` and five
legacy `Eloquent*Repository` classes (lane A/D), never a local model,
table or column.

Contract 16 itself is **not implemented** as of `30ad21c7`: no
`catalog_items`, `catalog_item_location_overrides`, or `package_snapshots`
migration; no `app/Library/Catalog/` directory; no `CatalogItem` or
`PackageSnapshot` model. *(This paragraph is dated recon evidence and is
left as written; §3.7 records that Contract 16 Sub-slice A has since
shipped.)*

**No "Signature" match in the codebase relates to human signing.** The
matches are Artisan `$signature` command declarations, PHP method-signature
prose, a payment-gateway `signature_key` config field, an OAuth state
signature, and webhook signature verification (Twilio `RequestValidator`,
Stripe `verifyWebhookSignature()`, Telnyx Ed25519).

**Contacts carry no `email` column.** Contact email is a custom-field
identity value, not a first-class column. That is why §5.2 gives the
document its own frozen recipient snapshot rather than resolving a
recipient from live Contact identity at send time (§7, §11.3).

### 3.3 Correcting Traceability row 15

Row 15 reads "Invoicing/payment exists; Proposal/Contract/e-signature
layer is largely net-new," and its own document flags it as an unverified
existence-check. The full read (§4) corrects it: `app/Models/Invoices.php`,
both `InvoiceController`s, and `PaymentController` **do** exist, but every
one of them is **lane A** — the platform selling its own products (sender
IDs, keywords, phone numbers, SMS credit, SaaS subscriptions) to platform
users. `Invoices` has `user_id`, not `business_id`; its four `TYPE_*`
constants are the entire domain; its `amount`/`tax`/`total_amount` columns
are `string`. **Nothing about it is reusable for lane B**, and this
contract does not extend it (§4.1, §15). The accurate statement is:
*Proposal/Contract/e-signature/lane-B invoicing is entirely net-new; the
existing invoicing code is a different money lane that must not be
touched.*

### 3.4 What Contract 16 binds this slice to

Contract 16 §12.D's exact service signature — this slice is its first and
only known hard consumer:

```php
snapshot(CatalogItem $item, BusinessLocation $location, ?User $actor = null, ?int $explicitPriceMinor = null): PackageSnapshot
```

Four obligations this contract inherits, in Contract 16's own words:

1. **`$location` is required, never optional** — `package_snapshots.
   business_location_id` is NOT NULL because "it records **the Location of
   the transaction itself**", "even when the Business-wide default price
   applied and no `catalog_item_location_overrides` row existed for that
   Location at all." This slice's document Location attribution therefore
   flows *into* the snapshot, not merely alongside it.
2. **`$actor` is nullable specifically for the public/unauthenticated
   flow** — Contract 16 §5.3: "inventing a fake 'system' User account to
   satisfy a `NOT NULL` constraint is not authorized by any document and
   is not done here." That parameter exists for exactly this slice's
   secure-link signer.
3. **Line items are explicitly assigned to this slice** — Contract 16
   §5.3: "if Slice 17 needs to represent 'N units of this catalog item on
   one proposal,' that quantity/line-item concept belongs to Slice 17's
   own schema (an Order/Proposal line item referencing this snapshot's
   `uid`), not to this table." Note: **by `uid`, not `id`** (§5.4).
4. **The service performs no authorization** — Contract 16 §12.D:
   "callers … are responsible for having already passed §6's gates." This
   slice owns the entire authority gate for every snapshot it triggers
   (§6).

And two Contract 16 §15 non-goals that constrain this slice:

- **No negotiated-price/discount system.** `$explicitPriceMinor` "is never
  a caller's licence to override a resolved Business-wide or Location
  price." This slice may pass it **only** for a genuinely quote-only item
  (§5.4).
- **The legacy `invoices`/`plans` tables are "a different bounded context,
  untouched."** Contract 16 ring-fenced them without resolving whether
  this slice reuses them. §4.1/§15 resolves it: it does not.

### 3.5 Reusable existing conventions — mirrored, not invented

| Concern | Precedent | Reuse |
|---|---|---|
| Secure public link | `client_workspace_invitations` + `ClientInvitationManager` — two-segment route `{uid}/{token}`, `Str::random(64)` plaintext, `Hash::make()` stored as `token_hash`, `expires_at` from config, revocation by status flip under `lockForUpdate()`, and one uniform refusal for every failure reason ("never disclosing which") | §5.2/§6.3 mirror this exactly. It is the **only** pattern in this codebase with hashing-at-rest, expiry and revocation. Three deltas added (§6.3): `throttle:`, `->missing(fn () => abort(404))`, and `hash_equals()` for any non-bcrypt comparison. |
| Immutable commercial content | `website_revisions` — `const UPDATED_AT = null`, `$table->timestamp('created_at')->nullable()` alone, `version_number` + `unique([parent_id, version_number])`, `json` snapshot + `schema_version` | §5.3/§5.5 mirror the discipline, with one honest difference stated in §5.3.2: a document version's `state` **must** transition `issued → superseded`, so the immutability claim is scoped to commercial content, not to the physical row. |
| Mutable draft → immutable on state change | `automation_workflow_versions` — mutable while `state = draft`, immutable once it leaves; child tables have no `updated_at` "so an attempt fails loudly"; plus generated-column uniqueness guards (`draft_guard`/`published_guard` `storedAs(CASE WHEN state=…)`) | §5.3/§7 — the generated-column guard is how "at most one draft version per document" is enforced in the database, since MySQL has no partial unique index. §5.7/§5.9 reuse the same technique for "one live Stripe connection per Business" and "one active payment attempt per schedule item". |
| Canonical JSON | `app/Library/Opportunity/CanonicalJson.php` — its own docblock calls it "the general-purpose canonical JSON primitive": recursively sorts map keys by byte order, NFC-normalizes strings, never reorders or dedupes a list, refuses non-finite floats and unsupported types, encodes with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR`; tested at `tests/Unit/Opportunity/CanonicalJsonTest.php` | §5.3.2 — those are exactly this slice's required hashing rules. Because the class self-describes as general-purpose, §12.A extracts it to a neutral namespace **behavior-preservingly** (keeping Opportunity working through it) rather than Documents depending semantically on the Opportunity domain. |
| Webhook idempotency | `payment_provider_events` — `UNIQUE(provider, provider_event_id)`; signature verified over the raw body *before* any insert (400 on failure, zero side effects); duplicate insert caught on SQLSTATE `23000` → `200`; atomic conditional-`UPDATE` claim with lease/attempts; terminal writes guarded `WHERE state='processing'`; `app_operation_id` metadata round-trip cross-check | §8 mirrors the **pattern** in a lane-B-owned table. The table itself is forbidden (§4.4). |
| Idempotent side effect | `UNIQUE(business_id, <key>)` + insert-and-catch `UniqueConstraintViolationException` + scoped read-back (`EloquentBusinessUsageMeasurementRepository::recordOnce()`); deterministic keys derived from durable row identity, never `Str::uuid()` per call (`ManagedDispatchDelegate`: "A random per-call key is not idempotency, it is the appearance of idempotency") | §8.1/§8.4 — every provider call, reminder and receipt key in this slice is derived from a durable row UUID and stored under a tenant-scoped unique index. |
| Durable "already notified" marker | `business_usage_wallets.low_balance_notified_at` — owned by the manager, never written by the job | §8.4 — reminder and receipt dedupe markers live on the owning row, not in the job. |
| No provider call under a lock | `ManagedMessageDispatcher` — writes the attempt row in its own short committed transaction, calls the adapter **outside** any transaction, finalizes in a second short transaction | §7 adopts this verbatim as a hard rule. |
| Customer capability | `config/customer-permissions.php` declares each key with `display_name`/`category`/`default`; `AuthServiceProvider::boot()` turns them into Gates; they are **persisted per customer** as a JSON list in `customers.permissions`, written once at creation from `Customer::customerPermissions()` and read by `EloquentAccountRepository::hasPermission()`. Adding a config key therefore grants it to FUTURE customers only — which is why `2026_09_09_120006_backfill_google_business_profile_view_permission.php` exists | §6.1/§12.A — `payments_contracts` is declared in that config **and** backfilled by a new migration following that exact precedent, or existing customers are refused on a surface their plan entitles them to. |
| Scheduled sweep | `SweepExpiredOpportunitySnoozes` + its two test classes — manager owns the logic, command owns flag-gate/`--limit` validation/`self::INVALID`, bounded batch (never drain-to-empty), per-row transaction + `lockForUpdate()` + re-verify precondition under the lock, `Throwable` per row logged and loop continues; schedule registered unconditionally with the flag owned by the command | §12.F mirrors this exactly, including the `ReflectionMethod`-based schedule-registration test. |
| Outbound email to a non-User address | `Notification::route('mail', $email)->notify(...)` from a `Base`-extending job `implements ShouldQueueAfterCommit`, scalar ids only, dispatched **after commit** ("a recipient must never be emailed a claim link for a row that a later failure rolled back") | §11.3/§12.C. Email is the canonical V1 delivery path; no messaging/wallet path is used (§11.3). |
| Timeline | `TimelineSource` + `ContactActivityTimeline::SOURCES_TAG` — the interface's own docblock names "**invoices**, payments" as intended future sources | §12.G registers a `DocumentActivitySource`; the Conversations screen does not change. |
| Money | Contract 16 `package_snapshots.price_minor_at_snapshot` (`unsignedBigInteger` minor units) + `currency_code_at_snapshot` `char(3)`; `CrmOpportunity.value_minor`/`currency_code` | §5 — integer **minor units** throughout, matching Contract 16 exactly (no conversion at the boundary) and matching Stripe's own wire format. Never micro-units (that is lane D's convention, for sub-cent metering this slice does not have). |
| UID safety | `HasUid::generateUid()` mints `uniqid()` — time-ordered, trivially predictable. Newer models override it (`WebsiteRevision`, `AutomationWorkflow`, …); `routes/public.php`'s own comment: "`Business.uid` unsafe: it is generated via `uniqid()`, not a real UUID, despite its column type" | Every model in this slice overrides `generateUid()` to `(string) Str::uuid()`, **and** `uid` alone never authorizes access to a document (§6.3). |

### 3.6 What does not exist and how this contract resolves it

- **No PDF capability.** No library in `composer.json` or anywhere in
  `composer.lock`. `config/filesystems.php` has three stock disks and the
  `public` disk is symlinked into `public/` — world-readable with no
  authorization check, so a signed document must never land there. §5.6
  resolves this without a new dependency: V1 has no PDF.
- **`stripe/stripe-php: ^7.76`** (`composer.json:73`) is old and must not
  be allowed to dictate a legacy Connect design. §11.5 **authorizes**
  Sub-slice D to upgrade it to the current stable version the supported
  Connect/Accounts API requires, under stated rules.
- **No named e-signature vendor, in any document.** A case-insensitive
  sweep of the entire `docs/` tree returns zero matches for every major
  vendor. §6.5 locks a first-party, provider-neutral typed-signature
  evidence model that is implementable now; none is added.

### 3.7 Current-main amendment

Recon above is dated to `30ad21c7`. At coordinator review `main` is
**`e1296382`**, and the following has landed since — recorded here rather
than folded back into §3.2, so the dated evidence stays honest:

- **Contract 15 (Calendar) is merged** as
  `docs/product/implementation-contracts/15-CALENDAR-BOOKING-AVAILABILITY.md`.
  §17's conflict map is corrected accordingly: it is a merged contract
  document, not an absent one.
- **Contract 18 (SEO expansion)** and **Contract 20 (Niche Blueprint
  versioning)** are merged as contract documents.
- **Contract 16 Sub-slice A is implemented**: `catalog_items`,
  `catalog_item_location_overrides`, `package_snapshots` and their models
  exist, together with the Packages & Products entitlement identity. The
  real remaining gate for this slice's Sub-slice B is therefore **Contract
  16 Sub-slices B + C + D** (§16), not "Contract 16 is a document only."

No conclusion in §4–§18 depends on the stale wording; every implementer
still re-verifies at implementation time (§18).

## 4. Money lanes — the reusable/forbidden map

Addendum §12 says these lanes "**MUST** remain separate ledgers/Stripe
relationships," with no exception. This slice is **lane B only**. Every
payment artifact currently in the repository belongs to lane A or lane D.

### 4.1 Lane A — legacy platform SaaS revenue (`LEGACY — DO NOT EXTEND`)

`app/Models/Invoices.php` and the `invoices` table (`user_id`,
`currency_id`, `amount`/`tax`/`total_amount` as **`string`**, and four
`TYPE_*` constants — `senderid`, `keyword`, `subscription`, `number` — that
are its entire domain); `app/Http/Controllers/Customer/InvoiceController.php`;
`app/Http/Controllers/Admin/InvoiceController.php` (whose `approve()`
grants platform entitlements: `sms_unit`, sender-ID activation,
`Subscription::STATUS_ACTIVE`); `app/Http/Controllers/Customer/PaymentController.php`
(12,888 lines, 35+ gateways, confirming payment by reading
`Session::get('session_id')` after a browser redirect — **no webhook, no
signature verification, no idempotency**); `payment_methods` +
`app/Models/PaymentMethods.php` (an **untenanted, platform-admin-configured
global row per gateway**, read as `PaymentMethods::where('type', TYPE_STRIPE)->first()`);
`app/Http/Controllers/User/AccountController.php`,
`Customer/KeywordController.php`, `Customer/NumberController.php`,
`Auth/RegisterController.php`, `EloquentSubscriptionRepository`; and the
legacy gateway libraries `CoinPayments`, `NowPaymentsAPI`, `MPesa`, `MPGS`.

**None of it is touched, extended, read, or written by this slice.**

### 4.2 Lane A/D — the RFC-005 platform Stripe boundary (`FORBIDDEN`)

`app/Library/Usage/StripePaymentProviderGateway.php` — whose own docblock
calls it "the sole class in this repository permitted to reference a
`Stripe\*` SDK class" — constructs exactly one `StripeClient` from
`config('services.stripe.secret')` (= `env('STRIPE_SECRET')`), asserts the
key's `sk_{mode}_` prefix, and **never passes a `stripe_account` request
option**. Its interface `app/Library/Usage/Contracts/PaymentProviderGateway.php`
exposes eleven methods, **none** accepting a connected-account id and none
creating or linking an account. `app/Enums/Usage/PaymentProvider.php` is
`Stripe only, v1`.

**Consequence, stated plainly: there is no Stripe Connect capability in
this repository.** A case-insensitive search across `app/` and `database/`
for `stripe_account`, `acct_`, `Stripe-Account`, `stripeAccount`,
`application_fee`, `on_behalf_of`, `transfer_data`, `AccountLink`, and
`accounts->create` returns **zero matches**. `businesses` has no Stripe
column. Lane B's connected-account infrastructure is built from zero
(§5.7, §12.D).

That docblock's claim ("the sole class … permitted to reference a `Stripe\*`
SDK class") is a **lane-D scoping statement, not a platform-wide
monopoly** — it cannot bind lane B, because obeying it literally would
force lane-B charges through the platform's own Stripe account, which is
precisely the Addendum §12 violation. §12.D therefore creates a **second,
lane-B-owned gateway class**, and this contract records the reasoning here
so the apparent conflict is resolved in writing rather than rediscovered
during implementation.

### 4.3 Lane D — the usage wallet (`FORBIDDEN`)

Every class in `App\Library\Usage` and every table it owns:
`UsageWalletManager`, `UsageBillingCheckoutManager`, `PaymentInstrumentManager`,
`EffectivePayerResolver`, `BillingProfileManager`, `FakePaymentProviderGateway`,
and all DTOs; tables `business_usage_wallets`, `business_usage_rates`,
`business_usage_reservations`, `business_usage_ledger_entries`,
`business_funding_attempts`, `business_billing_receipts`,
`business_payer_assignments`, `business_billing_contacts`,
`business_usage_addon_*`, `additional_business_slot_*`, `usage_meters`,
`ai_usage_*`.

Two tables carry **deliberately misleading names** and are called out
explicitly so no implementer mistakes them for lane B:
`payment_provider_customers` and `business_payment_instruments` are the
cards a *Business/Workspace/Agency uses to pay the platform*, stored on the
**platform's** Stripe customer objects and written only by
`PaymentInstrumentManager` against the `EffectivePayer`. A lane-B end
customer's card lives on the **Business's connected account** and has no
representation in either table.

`PayerType`, `AgencyRebill`, auto-recharge, and `EffectivePayerResolver`
are lane-D payer concepts and are **never** consulted for a lane-B charge:
in lane B the payer is the end customer and the recipient is the Business,
both by construction.

### 4.4 Lane D — the webhook stack (`pattern REUSABLE, table FORBIDDEN`)

`payment_provider_events` + `StripeWebhookController` +
`ProcessPaymentProviderEvent` + `EloquentPaymentProviderEventRepository`
implement a complete, well-specified idempotency mechanism (§3.5). The
**table** is forbidden: rows carry nullable, deliberately un-FK'd lane-D
attribution columns (`business_id`, `funding_attempt_id` — the migration
docblock states "No FK on business_id/funding_attempt_id") plus lane-D
`normalized_*_micro` wallet columns, the processing job dispatches only
over lane-D subject kinds (`funding_attempt`, `slot_renewal_charge`,
`slot_agreement`), and the controller hardcodes the single platform
webhook secret. A lane-B event routed there would place end-customer money
into the usage-wallet accounting path — exactly the §12 violation.

The **pattern** is reused verbatim in a lane-B-owned table (§8). Note the
existing route is already namespaced `stripe/webhook/usage-billing`; lane B
gets its own path (§5.8).

### 4.5 Refunds

Refund **ingestion** exists in lane D (ten event types routed by
`event_type` rather than metadata, because "Charge/Dispute/Refund metadata
is independent, never inherited from the originating PaymentIntent" — a
real Stripe behaviour this slice must respect, §8.3). Refund **issuance
does not exist anywhere**: `grep -rn "refunds->|Refund::create|->refund("`
over `app/` returns zero matches, and `PaymentProviderGateway` has no
refund method. Blueprint §18 requires refunds, so §5.9/§12.F builds
issuance from scratch.

### 4.6 What *is* genuinely reusable

Only patterns and neutral logic — never a call into `App\Library\Usage`:
the webhook claim/lease shape (§8); deterministic idempotency keys derived
from durable row identity, stored under a tenant-scoped unique index
(§8.1/§8.4); the `app_operation_id` metadata round-trip cross-check; the
"lock the parent row as the idempotency mechanism" pattern; the
canonical-JSON primitive, extracted behavior-preservingly to a neutral
namespace (§5.3.2, §12.A); and the currency-exponent knowledge
(zero/two/three-decimal currency lists and Stripe's minor-unit bounds),
which currently lives **private** inside the forbidden
`UsageBillingCheckoutManager` and is therefore re-derived into a
**lane-neutral** `App\Library\Money\CurrencyExponent` value object in
§12.A rather than duplicated or reached into.

## 5. Canonical domain model

All money is **integer minor units** (`unsignedBigInteger`) plus a
`char(3)` `currency_code` — matching Contract 16's `package_snapshots`
exactly (so no conversion happens at that boundary) and matching Stripe's
wire format. Never micro-units. Every table is prefixed `business_*`,
deliberately, so lane-B tables are visually distinguishable from lane-D's
`payment_provider_*` at every call site. Every model overrides
`generateUid()` to `(string) Str::uuid()` (§3.5).

### 5.1 Proposal, Contract and Invoice are **one versioned document with stages**

This is the contract's single most consequential modelling decision, and
it is **accepted architecture** — not reopened here. The evidence:

- Blueprint §18 says "The document lifecycle (draft → sent → signed/paid →
  expired/void) is tracked **per document**", with `signed` and `paid` in
  **one** lifecycle — i.e. the same document is signed and then paid.
- Blueprint §9's default pipeline (lines 230–231) names exactly two §18
  transactional-document stages — `Proposal Sent` and `Invoice Sent` — and
  no "Contract Sent" stage.
- No document anywhere describes a Contract as a separately persisted
  artifact with its own identity, lifecycle, or schema.

**Resolution:** one `business_documents` row with `kind ∈ {proposal,
invoice}`. **"Contract" is not a third kind** — it is the *signed state* of
a `proposal`-kind document: the immutable version that was displayed, plus
the signature evidence attached to it (§5.5). An `invoice`-kind document
simply requires no signature and goes `draft → sent → paid`.

**Recorded so it stays visible:** under this model a signed proposal is
itself the thing that gets paid, so nothing converts an accepted proposal
into a separate `invoice`-kind document — there is no parent/child
linkage and no conversion action. Blueprint §9's "Invoice Sent" stage is
satisfied in V1 by sending an `invoice`-kind document directly. A genuine
Proposal → Invoice conversion is a §15 non-goal.

### 5.2 `business_documents`

```
id
uid                            uuid, unique (Str::uuid(), never HasUid's uniqid())
business_id                     FK -> businesses, restrictOnDelete, NOT NULL
business_location_id             FK -> business_locations, restrictOnDelete, NOT NULL
contact_id                        FK -> contacts, restrictOnDelete, NOT NULL
crm_opportunity_id                 FK -> crm_opportunities, nullOnDelete, nullable
kind                                string(16): proposal | invoice
status                               string(16): draft | sent | signed | paid | expired | void
requires_signature                    boolean, default true for proposal, false for invoice
title                                  string(200)
currency_code                           char(3), NOT NULL
current_version_id                       unsignedBigInteger, nullable  -- FK added in a LATER migration, §5.3.3
recipient_name_snapshot                   string(191), nullable
recipient_email_snapshot                   string(255), nullable while draft; REQUIRED and frozen at send
recipient_phone_snapshot                    string(32), nullable
sent_at / signed_at / paid_at / expired_at / voided_at   timestamps, nullable
expires_at                                     timestamp, nullable   -- OFFER expiry only, §8.6
void_reason                                     string(255), nullable
access_token_hash                                string, nullable
access_token_expires_at                           timestamp, nullable
access_token_rotated_at                            timestamp, nullable
expiry_reminder_last_sent_at                        timestamp, nullable   -- durable dedupe marker, §8.4
expiry_reminder_count                                unsignedTinyInteger, default 0
created_by_user_id                                    FK -> users, nullOnDelete, nullable
timestamps

index (business_id, status)
index (business_location_id, status)
index (contact_id)
index (crm_opportunity_id)
index (current_version_id)
index (expires_at)            -- the expiration sweep's own driving index
```

`business_location_id` is **NOT NULL** — Blueprint §18 ("every
transactional document carrying Location attribution"), Addendum §5, and
the Acceptance Matrix's Business Owner row (line 31) classifying
transactions as `LB`. It is also the Location passed to
`PackageSnapshotService::snapshot()` (§3.4). §6.6 states the integrity
rules binding `business_location_id`, `contact_id` and
`crm_opportunity_id` together — individually valid foreign keys are
**not** sufficient.

`currency_code` is fixed on the document at creation from the Business's
own `currency_code` and never changes — mirroring
`business_usage_wallets.currency_id`'s "immutable accounting snapshot"
discipline and `CrmOpportunity`'s stated rationale ("so a later currency
change does not silently re-denominate existing deals"). A line whose
snapshot resolves to a different currency is a refusal, not a conversion
(§7).

**Recipient snapshots (`recipient_*_snapshot`).** Contacts carry no
`email` column (§3.2) — email is a custom-field identity value — while the
Acceptance Matrix requires "a customer signs and pays via the **emailed**
link". The document therefore stores its own delivery identity:

- a draft may prefill these from the current Contact profile/custom
  fields;
- `recipient_email_snapshot` is **required and validated before send**;
- all three are **frozen at send** (§5.3.1);
- **every** delivery — first send, resend, reminders, payment receipts —
  uses the document's own snapshot, and never re-reads live Contact
  identity;
- a later Contact edit therefore can never redirect an already-issued
  document to a different address;
- the signer's own `signer_name`/`signer_email` (§5.5) are separate
  signature evidence and **may legitimately differ** from the delivery
  recipient.

`access_token_hash` lives on the document (a single active link, rotated
on re-send) rather than in a separate issuance table. Blueprint §18 says
"a secure, non-guessable link" — singular — and V1 has one recipient per
document. Rotation on re-send **invalidates every previously issued
link**. *(Alternative considered: a `business_document_access_tokens`
child table supporting multiple concurrent links. Rejected as more
structure than V1 needs; a clean additive migration later.)*

### 5.3 `business_document_versions` — the immutability boundary

```
id
uid                        uuid, unique
business_document_id        FK -> business_documents, restrictOnDelete, NOT NULL
version_number               unsignedInteger
state                         string(16): draft | issued | superseded
content                        json            -- rendered body/terms, denormalized
content_hash                    char(64)       -- sha256 over the canonical bytes defined in §5.3.2
subtotal_minor                   unsignedBigInteger
total_minor                       unsignedBigInteger
currency_code                      char(3)
schema_version                      unsignedSmallInteger, default 1
issued_at                            timestamp, nullable
superseded_at                         timestamp, nullable
created_by_user_id                     FK -> users, nullOnDelete, nullable
created_at                              timestamp only
draft_guard        unsignedBigInteger nullable  storedAs("CASE WHEN state='draft' THEN business_document_id ELSE NULL END")

unique (business_document_id, version_number)
unique (draft_guard)          -- at most ONE draft version per document, enforced by the DB
index (business_document_id, state)
```

**Parent FK implementation note.** MySQL forbids `ON DELETE CASCADE` on
`business_document_id` because it is a base column of the STORED
`draft_guard` generated column. `restrictOnDelete` is therefore required,
not optional, and matches the existing `automation_workflow_versions`
precedent. Physical deletion is not the document lifecycle mechanism:
documents move to void or other terminal states, while RESTRICT preserves
their audit/history. Any future physical purge must explicitly delete
dependent rows in order rather than rely on a parent cascade.

The `draft_guard` generated column is the `automation_workflow_versions`
technique (§3.5): MySQL has no partial unique index, so "at most one draft
per document" is enforced by a stored generated column plus a plain unique
key, not by application discipline alone.

#### 5.3.1 What is immutable, stated mechanically honestly

An earlier revision of this contract said an issued version "becomes
write-once forever" **and** that a prior issued version is UPDATEd to
`state = superseded`. Both cannot be true. The rule, stated once:

**After issue, all COMMERCIAL CONTENT of a version is immutable forever:**
- `content`, `content_hash`, `subtotal_minor`, `total_minor`,
  `currency_code`, `schema_version`;
- every `business_document_line_items` row of that version, including each
  `package_snapshot_uid` (the Contract 16 snapshot is itself write-once by
  Contract 16 §5.3);
- every `business_document_payment_schedule_items` row's **commercial
  terms** — `sequence`, `kind`, `amount_minor`, `currency_code`, `due_at`.

**Version lifecycle metadata is not commercial content**, and may make
exactly one authorized transition: **`issued → superseded`** (with
`superseded_at`), when a later version of the same document is
successfully issued. Nothing else about an issued version may ever be
updated — not `content`, not a total, not a line, not a schedule term.

Schedule-item **progress** fields (`status`, `paid_at`,
`reminder_last_sent_at`, `reminder_count`) are likewise not commercial
content and remain mutable for the version that is currently payable.

The document's own identity fields — `business_id`,
`business_location_id`, `contact_id`, `kind`, `currency_code` — and its
`recipient_*_snapshot` values are frozen at the first send, because they
are the identity the snapshot, the delivery and any later signature are
taken against.

**At sign**, nothing further freezes: everything the signer saw was
already frozen at send, which is the whole reason the freeze happens at
send. Sign adds the evidence binding (§5.5) and closes revision — §7
refuses a new draft version on a `signed` document.

**At pay**, nothing freezes; a payment advances schedule-item progress
and, when every item of the current version settles, the document's
`status`. A terminal document never moves backward (§8.3).

**The source-boundary tests therefore prove a precise claim:** no
production code path modifies the commercial fields of an `issued` or
`superseded` version, nor the commercial terms of its line items or
schedule items. They do **not** claim the Eloquent row is physically
update-impossible, because `state` must transition. Overclaiming that
would be a test that either lies or blocks a required transition.

#### 5.3.2 `content_hash` — canonical bytes, defined

A hash over an undefined serialization is not a hash. The canonical input
is built as follows, and `content_hash` is `sha256` over its UTF-8 bytes:

- **Encoding rules** are exactly the existing canonical-JSON primitive's
  (§3.5): map keys recursively sorted by byte order; list order
  **preserved**, never reordered or deduplicated; strings NFC-normalized;
  integers stay integers; non-finite floats and unsupported types
  **refused**, never coerced.
- **`content`** is included as its canonical object.
- **Line items** are included as a list ordered by `position`, then by
  `uid` as a stable tie-break, each contributing only its commercial
  fields (`source`, `package_snapshot_uid`, `name`, `description`,
  `quantity`, `unit_price_minor`, `line_total_minor`, `currency_code`).
- **Schedule items** are included as a list ordered by `sequence`, each
  contributing only its commercial terms (`sequence`, `kind`,
  `amount_minor`, `currency_code`, `due_at`).
- **Totals** (`subtotal_minor`, `total_minor`, `currency_code`) and
  `schema_version` are included.
- **Excluded, deliberately:** every progress/mutable field — schedule
  `status`/`paid_at`/reminder markers, any payment or refund id, any
  provider reference, `state`, `superseded_at`, and all row timestamps.
  Payment progress must never change a hash a signature is bound to.

**On reuse.** The existing primitive is `app/Library/Opportunity/CanonicalJson.php`,
whose own docblock calls it "the general-purpose canonical JSON
primitive." Documents must not depend semantically on the Opportunity
domain merely because the class currently lives there, so §12.A
**extracts it to a neutral namespace behavior-preservingly** — Opportunity
keeps working through the extracted primitive, its existing unit test
continues to pass — or, if extraction proves mechanically unsafe, creates
a Documents-local equivalent enforcing the identical, identically tested
rules. Either way the rules above are the contract.

#### 5.3.3 The circular FK is staged DDL, not an implementation surprise

`business_documents.current_version_id` references
`business_document_versions`, whose `business_document_id` references
`business_documents`. No table-creation order can declare both inline.
Sub-slice A therefore stages the DDL explicitly:

1. create `business_documents` with `current_version_id` as a **nullable
   scalar plus index, and no FK**;
2. create `business_document_versions` with its FK to `business_documents`;
3. add the `current_version_id` FK in a **later, ordered migration** via
   `Schema::table` (`nullOnDelete`) — or another mechanically equivalent
   ordered-DDL design;
4. the `down()` path drops that FK **before** dropping
   `business_document_versions`.

A test proves the resulting FK actually exists (§12.A).

### 5.4 `business_document_line_items`

Belongs to a **version**, not to the document — that is what makes an
issued document's commercial content immutable.

```
id
uid                             uuid, unique
business_document_version_id     FK -> business_document_versions, cascadeOnDelete, NOT NULL
position                          unsignedSmallInteger, default 0
source                             string(16): catalog | custom
package_snapshot_uid                uuid, nullable        -- Contract 16 §5.3: reference by uid, never id
name                                 string(200)
description                           text, nullable
quantity                               unsignedInteger, default 1
unit_price_minor                        unsignedBigInteger
line_total_minor                         unsignedBigInteger    -- quantity * unit_price_minor, stored
currency_code                             char(3)
created_at                                 timestamp only

index (business_document_version_id, position)
index (package_snapshot_uid)
```

`source = catalog` requires a non-null `package_snapshot_uid` produced by
`PackageSnapshotService::snapshot()` at the moment the line is added, and
copies `name`/`unit_price_minor`/`currency_code` from that snapshot. The
line stores its own denormalized copy **in addition to** the snapshot
reference so that rendering an issued document never depends on another
module's current row shape — consistent with Blueprint §31's
module-boundary posture, though §31's own rule is specifically about
transitions and canonical events, not read joins — while the snapshot
remains the canonical immutable record Addendum §14 requires.

`quantity` is explicitly authorized by Contract 16 §5.3 ("N units of this
catalog item on one proposal").

**`source = custom` is this contract's one exercise of Addendum §19's
reasonable-default latitude, and it is flagged rather than buried.** No
authority document mentions ad-hoc lines; equally, none restricts a
document to catalog items, and Blueprint §17's phrasing ("Every proposal,
invoice, or booking **that references a package**") implies such documents
may contain lines that do not. Without custom lines a standalone invoice
could only ever bill catalog items, which would make the `invoice` kind
close to unusable. A custom line is a **new** line with its own price and
**never** a modification of a catalog item's snapshotted price — that
distinction is what keeps it clear of Contract 16 §15's forbidden
negotiated-price/discount system. *If the product owner prefers
catalog-only documents in V1, deleting the `custom` enum value and the
`source` column is a one-line change to §12.B.*

**No discount, tax, or installment-plan modelling.** Discounts are
forbidden by Contract 16 §15. Tax is mentioned in no authority document
and is not invented. Installments beyond deposit+balance are explicitly
V2 (Blueprint §34).

### 5.5 `business_document_signatures` — technical signing evidence

Write-once. One row per completed signature; V1 has exactly one signer per
document and no countersignature (§6.5).

```
id
uid                              uuid, unique
business_document_id              FK -> business_documents, restrictOnDelete, NOT NULL
business_document_version_id       FK -> business_document_versions, restrictOnDelete, NOT NULL
signed_content_hash                 char(64)      -- copy of the version's content_hash as displayed
signer_name                          string(160)  -- signer-entered; may differ from recipient snapshot
signer_email                          string(255) -- signer-entered; may differ from recipient snapshot
typed_name                             string(160) -- the mark the signer typed
signature_method                        string(16): typed
consent_statement                        text        -- the exact agreement text shown, stored verbatim
consent_statement_hash                    char(64)
ip_address                                 string(45)
user_agent                                  string(512), nullable
signed_at                                    timestamp
created_at                                    timestamp only

unique (business_document_id)     -- one signature per document in V1
index (business_document_version_id)
```

`business_document_version_id` + `signed_content_hash` are the load-bearing
fields: they bind the signature to **the exact immutable version that was
displayed**, so "what did they actually agree to" is answerable from the
row alone. `consent_statement` is stored **verbatim**, never by reference
to a template that could later change.

### 5.6 Rendered artifact strategy — no PDF, no new dependency

No PDF library exists (§3.6), the `public` disk is world-readable, and
**Blueprint never says "PDF"** — it says the customer accesses the document
"via a secure, non-guessable link". V1 therefore renders the document as a
Blade page from the **issued version's frozen `content` + line items**,
exactly as `Public\WebsiteController` renders from a `WebsiteRevision`
snapshot. Nothing is generated, stored, or served as a file.

Adding a PDF renderer is a new composer dependency and a new private
storage disk — an explicit authorization decision (§15), not an
implementation detail. If ever authorized, it renders **from the frozen
version**, never from live data, and is served through an
authorization-checking controller on `Storage::disk('local')` (the
`PlatformThemeFontController` precedent), never the public disk.

### 5.7 `business_stripe_connections` — historical records, one live connection

An earlier revision put `unique(business_id)` on this table. That is
wrong: it would permit exactly one connection row for a Business's entire
lifetime, so changing the connected account would force rewriting
`stripe_account_id` in place — corrupting the attribution of every
historical payment that FKs to that row. Connections are therefore
**historical records**, with a generated-column guard enforcing one live
one.

```
id
uid                            uuid, unique
business_id                     FK -> businesses, restrictOnDelete, NOT NULL   -- plain FK, NOT unique
stripe_account_id                string(64)      -- acct_... ; immutable once provider identity is established
status                            string(24): pending | onboarding | active | restricted | disconnected
charges_enabled                    boolean, default false
payouts_enabled                     boolean, default false
details_submitted                    boolean, default false
requirements_disabled_reason          string(120), nullable
default_currency                       char(3), nullable
connected_at / disconnected_at          timestamps, nullable
last_synced_at                           timestamp, nullable
lock_version                              unsignedInteger, default 0
timestamps
active_business_id   unsignedBigInteger nullable
    storedAs("CASE WHEN status IN ('pending','onboarding','active','restricted') THEN business_id ELSE NULL END")

unique (stripe_account_id)
unique (active_business_id)    -- at most ONE non-terminal connection per Business
index (business_id, status)
```

`unique(active_business_id)` is the database-level expression of Addendum
§12's "1 Business = 1 connected Stripe account in V1" — read correctly as
**one live connected relationship, not one lifetime database row**.
Per-Location Stripe accounts remain V2 (Blueprint §34).

Rules:

- **disconnect** makes the current row terminal (`disconnected`,
  `disconnected_at`), which frees `active_business_id` for a future
  connection;
- connecting a **different** Stripe account creates a **new** row;
- reconnecting the **same** provider account may reactivate its historical
  row **only** where that is mechanically safe (the row's
  `stripe_account_id` is unchanged and its state machine permits it);
- `stripe_account_id` on an existing row is **never** rewritten to a
  different `acct_`;
- `business_document_payments.business_stripe_connection_id` keeps its
  exact historical value **forever**;
- **creating a new PaymentIntent requires the current active connection**;
- **webhook finalization and refunds do NOT** require the historical
  connection to still be the Business's current one — an event for an
  older, disconnected account may still finalize or refund an older
  payment for that account (§8.3, §5.9's refund rule).

**No secret key is ever stored.** Direct charges on a connected account are
made with the *platform's* API key plus the `Stripe-Account` header
naming `stripe_account_id`; the connected account's own credentials never
exist in this system. This is a material difference from
`business_google_connections` (which stores an encrypted refresh token) and
is stated so no implementer copies that shape reflexively.

### 5.8 `business_payment_events` — lane-B webhook ingestion

```
id
business_stripe_connection_id     FK -> business_stripe_connections, restrictOnDelete, nullable
stripe_account_id                  string(64)      -- the event's own `account` field, captured raw
provider_event_id                   string(191)
event_type                           string(120)
state                                 string(16): received | processing | processed | ignored | failed
attempts                               unsignedSmallInteger, default 0
processing_started_at / lease_expires_at / last_attempt_at / completed_at   timestamps, nullable
last_error                              string(120), nullable   -- exception CLASS or a reason code, never a message
payload_encrypted                        text            -- `encrypted` cast
payload_hash                              char(64)
payload_purged_at                          timestamp, nullable
created_at                                  timestamp only

unique (stripe_account_id, provider_event_id)
index (state, lease_expires_at)
```

Uniqueness is scoped by `stripe_account_id`, not global — following the
codebase's own corrections, where both `business_messaging_operations` and
`business_usage_measurements` had to **add** tenant scoping after a
caller-chosen key collided across tenants. `business_stripe_connection_id`
is nullable so an event for an unrecognized account is still recorded and
ignored, rather than lost or crashing intake.

Route: `POST stripe/webhook/business-payments` — a **separate path** from
lane D's `stripe/webhook/usage-billing`, added to `VerifyCsrfToken::$except`,
with its own Connect webhook secret (`STRIPE_CONNECT_WEBHOOK_SECRET`). For
Stripe Connect, one platform-level endpoint receives events for all
connected accounts and each event carries its own `account` field — that
field, not a per-Business secret, is what routes an event to a Business.
The exact API/event vocabulary is verified against current Stripe docs at
implementation time (§11.6).

### 5.9 Payment schedule, payments and refunds

**The schedule belongs to the version, not the document.** §5.3.1 makes
schedule commercial terms part of what freezes when a version is issued
and part of `content_hash`; a document-scoped schedule cannot implement
that. Every schedule read and write therefore resolves through an exact
Document Version.

```
business_document_payment_schedule_items
  id
  uid                              uuid, unique
  business_document_version_id      FK -> business_document_versions, cascadeOnDelete, NOT NULL
  sequence                           unsignedTinyInteger      -- 1 or 2
  kind                                string(16): full | deposit | balance
  amount_minor                         unsignedBigInteger      -- commercial term, frozen at issue (§5.3.1)
  currency_code                         char(3)                -- commercial term, frozen at issue
  due_at                                 timestamp, nullable   -- commercial term, frozen at issue
  status                                  string(16): pending | paid | refunded | void
  paid_at                                  timestamp, nullable
  reminder_last_sent_at                     timestamp, nullable   -- durable dedupe marker (§8.4)
  reminder_count                             unsignedTinyInteger, default 0
  timestamps

  unique (business_document_version_id, sequence)
  index (status, due_at)

business_document_payments
  id
  uid                              uuid, unique
  business_id                       FK -> businesses, restrictOnDelete, NOT NULL   -- denormalized for tenant-scoped keys
  business_document_id               FK -> business_documents, restrictOnDelete, NOT NULL
  schedule_item_id                    FK -> business_document_payment_schedule_items, restrictOnDelete, NOT NULL
  business_stripe_connection_id        FK -> business_stripe_connections, restrictOnDelete, NOT NULL
  local_idempotency_key                 string(191)
  provider_payment_intent_id             string(191), nullable
  provider_charge_id                      string(191), nullable
  amount_minor                             unsignedBigInteger
  currency_code                             char(3)
  status                                     string(24): created | requires_action | processing | succeeded | failed | canceled
  failure_code                                string(64), nullable
  succeeded_at                                 timestamp, nullable
  receipt_sent_at                               timestamp, nullable   -- durable receipt dedupe marker (§8.4)
  timestamps
  active_schedule_item_id   unsignedBigInteger nullable
      storedAs("CASE WHEN status IN ('created','requires_action','processing') THEN schedule_item_id ELSE NULL END")

  unique (business_id, local_idempotency_key)
  unique (active_schedule_item_id)          -- at most ONE live attempt per schedule item (§7.2)
  unique (provider_payment_intent_id)       -- unique when populated: replay can never create a second payment
  unique (provider_charge_id)
  index (business_document_id, status)
  index (schedule_item_id, status)

business_document_refunds
  id
  uid                                uuid, unique
  business_id                         FK -> businesses, restrictOnDelete, NOT NULL
  business_document_payment_id         FK -> business_document_payments, restrictOnDelete, NOT NULL
  local_idempotency_key                 string(191)
  provider_refund_id                     string(191), nullable
  amount_minor                            unsignedBigInteger
  reason                                   string(255), nullable
  status                                    string(16): pending | succeeded | failed
  initiated_by_user_id                       FK -> users, nullOnDelete, nullable
  succeeded_at                                timestamp, nullable
  timestamps

  unique (business_id, local_idempotency_key)
  unique (provider_refund_id)
  index (business_document_payment_id, status)
```

**There is deliberately no `client_secret` column, and never will be.** A
PaymentIntent's `client_secret` is transient presentation material handed
to one browser (§7.2.1); the durable local identity of a payment is
`uid` + `provider_payment_intent_id` + `business_stripe_connection_id`,
which is everything any later retrieval, finalization or refund needs
(§11.8).

**`status` is a local vocabulary, not Stripe's.** The six values above are
ours; the provider's own status strings are mapped onto them in exactly
one place, the lane-B gateway seam (§11.8). No provider-specific status
string appears anywhere in domain code, manager code, or this schema.

**Deposit + balance, and nothing more.** Blueprint §34 puts "Deposit +
balance (§18)" in V1 and "Complex installment plans" in V2. A version
therefore has **either** one `full` item **or** exactly two items
(`deposit` then `balance`) — enforced by application validation plus the
`unique(business_document_version_id, sequence)` key and a
`sequence ∈ {1,2}` check.

**The schedule must account for the whole version.** The items'
`amount_minor` must sum **exactly** to that version's `total_minor`, and
every item's `currency_code` must equal the document's. Checked as a send
precondition (§7.1) and re-checked before any PaymentIntent (§7.2).

**Only the current version's schedule is payable.** Payment and reminders
target **only** the schedule of `business_documents.current_version_id`
(§7.2, §8.4). When a revised version is issued:

- the prior version becomes `superseded` and its **commercial terms are
  never mutated**;
- its still-`pending` schedule rows cease to be payable **by virtue of
  belonging to a superseded version** — no old public payment operation
  becomes valid merely because it still holds a schedule-item id;
- historical issued versions and their schedules remain fully
  reconstructable, which is the point of not mutating them.

**"Partial payment" means one schedule item settled and the other
outstanding — never an arbitrary part-amount against a single item.** A
PaymentIntent is created for a schedule item's exact amount; an amount
mismatch on an inbound event is a fail-closed refusal (§8.3).

**Payment progress is a separate axis from document lifecycle**, derived
from the current version's schedule items rather than stored as extra
`status` values — Blueprint §9 states an Opportunity carries its value and
its "**payment state** as two independent facts". It also avoids inventing
a `partially_paid` state Blueprint §18's six-state lifecycle does not
contain. `documents.status` becomes `paid` only when **every** schedule
item of the current version is `paid`.

**Refund semantics — partial refunds are precise (§8.7 covers admission).**
- the document remains `paid` after any refund; a refund never moves a
  terminal document backward;
- a schedule item stays `paid` while cumulative **succeeded** refunds are
  **less than** its captured amount;
- it becomes `refunded` **only** when cumulative succeeded refunds equal
  the full captured amount for that item — never on a first partial
  refund;
- cumulative succeeded refunds may never exceed the captured amount;
- refund provider calls target the **same historical**
  `business_stripe_connection` / `stripe_account_id` used by the original
  payment, never whatever account happens to be connected now (§5.7).

**Receipt.** A "receipt" here is exactly one thing: a payment-succeeded
email to the document's `recipient_email_snapshot`, dispatched from
`DocumentPaymentSucceeded` and deduplicated by the durable
`business_document_payments.receipt_sent_at` marker (§8.4). It is **not** a
stored document, not a PDF, and not a mirror of a Stripe-hosted receipt
URL (that is lane D's `business_billing_receipts`, forbidden by §4.3).

## 6. Authority / security contract

### 6.1 Internal (Business-side) authorization

**The capability is named.** An earlier revision said "one feature
permission" without ever defining it. Locked:

- **customer capability key:** `payments_contracts`
- **category:** `Payments & Contracts`

It follows the existing simple customer-permission convention (§3.5), not
a CRUD matrix: one key for the module, consistent with Contract 16 §15's
precedent against inventing per-operation capability keys. Because
customer permissions are **persisted per customer** at creation, adding
the config key alone would grant it to future customers only — so
Sub-slice A also ships the **backfill migration**, following
`2026_09_09_120006_backfill_google_business_profile_view_permission.php`
exactly (§12.A).

**The canonical authenticated gate, in order. No gate substitutes for
another:**

1. **authoritative Workspace/Business tenancy** — the actor genuinely
   reaches this Business through the canonical tenancy authority;
2. **`payments_contracts` capability** — the customer permission Gate;
3. **`EntitlementManager` allows the exact Payments & Contracts
   `PlatformFeature`** — see §6.4;
4. **`LocationAccessGuard::assertUserCanAccessLocation()`** for the exact
   `business_location_id` whenever the operation is document-scoped, re-read
   fresh, never from a route-bound model or a cached scope (Addendum §4:
   "Knowing or binding a record ID **MUST NEVER** bypass Location
   authorization");
5. **operation-specific owner check** where this contract requires one
   (§6.2's Stripe connect/disconnect).

**Every authenticated route built before Sub-slice G carries gate 3.**
While the `PlatformFeature` is `Planned`, a directly guessed route
therefore **fails closed**. Navigation hiding is never the control — G
adds the nav entry, not the gate.

**Refunds** are gated by the same single capability plus an explicit
confirmation step (a presentation-level reasonable default, Addendum §19)
— a refund moves real money out of the Business's account.

### 6.2 Stripe connect/disconnect is owner-only

Establishing or terminating the Business's Stripe relationship (§5.7) is
**owner-only** — a flagged reasonable default, reasoned by analogy to
Addendum §10's principle that financial consent belongs to an owner rather
than to staff. No authority document sets this boundary, and the
Acceptance Matrix's stated module boundary is "Owner + staff per feature
permission". **The product owner may instead place it behind the single
`payments_contracts` capability**; that is a one-line change to §12.D.

### 6.3 The end customer, and the secure link

Per the Acceptance Matrix's End Customer row the permission boundary is
**"Possession of the secure link"** — no account, no authentication. The
public surface is strictly: view the document, sign it, pay it. It exposes
no list, no search, no other document, and no Business data beyond the
document's own frozen content.

The link mirrors `client_workspace_invitations` exactly (§3.5):

- Route shape `GET documents/{uid}/{token}` — `{uid}` locates the row,
  `{token}` authenticates it. The split exists **because the token is
  hashed and therefore cannot be a lookup key**.
- `Str::random(64)` plaintext, existing only in the delivered link;
  `Hash::make()` stored in `access_token_hash`; verified only via
  `Hash::check()`. The plaintext is never stored, never logged, never
  recoverable.
- `access_token_expires_at` = the document's own `expires_at` when one is
  set (the document's expiry always wins — a link must never outlive the
  document it opens), otherwise `now()` plus
  `config('documents.link_ttl_days')`. `config/documents.php` is created in
  Sub-slice A (§12.A) so Sub-slice C can read it inside its own allowlist.
- Rotation on re-send (§5.2); revocation by voiding the document or
  clearing the hash — both effective immediately.
- **One uniform refusal** for every failure reason (expired, revoked,
  wrong token, unknown uid, voided document, account not permitted): one
  generic "this link is no longer valid" response, never disclosing which —
  the `ClientInvitationManager` discipline, which exists specifically to
  prevent existence disclosure.
- Three deltas over that precedent, because a signing/paying link is a far
  higher-value target than an invitation:
  1. **`throttle:` on every public route** — the precedent has none. Use
     the annotated-throttle style of `routes/public.php:40-42`, stating
     the number's justification in a comment.
  2. **`->missing(fn () => abort(404))`** — without it an unresolved
     binding renders a **500**, as `PublicContactOptInTest` documents
     happening today on the opt-in route.
  3. **`hash_equals()`** for any non-bcrypt comparison.
- **Two named non-precedents**, recorded so nobody cites them: the public
  contact opt-in link (bare `uniqid()`, guessable, no token segment; its
  recaptcha is an admin-toggled config flag, not part of the link's own
  security) and `HasUid::generateUid()` itself (`uniqid()` is time-ordered
  and carries zero security value).

The `GET` is **safe, side-effect-free and revisitable** — it records
nothing (§10: `DocumentViewed`/`last_viewed_at` are not in V1). Signing
and paying are separate `POST`s that re-validate everything under §7's
locks.

#### 6.3.1 The link is authorization, not an account/entitlement bypass

Possession of the token authorizes **this end customer's access to this
document**. It does **not** make a suspended, locked or unentitled account
executable forever. **Every** public request — `GET` view, `POST` sign,
`POST` pay — rechecks from persistence, in this order, before doing
anything:

1. the document exists and the token is valid;
2. the owning **Business/Workspace account lifecycle** currently permits
   customer-facing operation — resolved through the existing canonical
   account-access authority, **never** a second lifecycle resolver
   invented here;
3. the **Payments & Contracts entitlement is currently allowed** for that
   Business — via `EntitlementManager`, the same authority gate 3 uses;
4. the document's Location is active/usable;
5. the document's own lifecycle permits the requested operation (§7.3's
   payability rules).

**No customer feature-permission check is applied to the end customer** —
they hold no capability and are not a platform user. Every failure above
returns the same uniform, non-enumerating refusal as §6.3.

#### 6.3.2 The public payment surface: a read-only page and one POST

The public surface has exactly two payment-relevant endpoints, and the
split is load-bearing:

- **`GET documents/{uid}/{token}`** — renders the frozen issued version
  and, additionally, the payment *state*: the amount due, **which schedule
  item is currently payable** (§7.3), whether the Business is
  payment-ready (§11.4), and a **Pay action**. It **creates no
  PaymentIntent, makes no provider call and mutates nothing** (§10). A
  customer may open, refresh and revisit it any number of times with zero
  side effects.
- **`POST` payment-start** — the *only* entry point permitted to invoke
  §7.2's PAY START. It is authenticated by the same `{uid}/{token}`
  possession, re-runs §6.3.1's five rechecks, and returns §7.2.1's
  transient browser material.

**The Laravel endpoint never accepts card data.** It has no card number,
expiry, CVC, or raw payment-method field in its request schema; such input
is not "validated and discarded", it simply has no place in the contract
and any request carrying it is rejected by the endpoint's own strict input
schema. Card details are collected **exclusively** by Stripe.js in the
customer's browser (§11.8) and never enter a Laravel request body, log,
exception, or this database.

### 6.4 Entitlement

`PlatformFeature` has no case for this module. Sub-slice A adds one —
inert, `Planned` — together with the **new** backfill migration for
`platform_feature_usage_classifications` that this repository's own stated
discipline requires ("adding this case necessarily creates the row on any
fresh migrate, and merged migrations are not edited"), and a
`workspace_plan_features` seed row for **all three tiers** (Blueprint §21
line 434: Core, Growth and Agency all carry Payments & Contracts). The
`Planned → Available` flip happens only in Sub-slice G, once the flow
genuinely works end to end — and because every authenticated route and
every public request checks the entitlement (§6.1 gate 3, §6.3.1 step 3),
everything built before G is unreachable in production until that flip.

### 6.5 E-signature: engineering scope locked, legal review is a release gate

**The engineering model is implementable now.** Sub-slice C is not blocked
on a product-owner decision. V1 scope:

- first-party, **provider-neutral typed** electronic-signature evidence;
- exactly **one signer**; **no countersignature**;
- bound to an **immutable issued version** and its exact `content_hash`;
- typed name; signer-entered name and email;
- verbatim consent statement plus its hash;
- IP address; user agent; server-side timestamp;
- the secure-token possession that authorized the act.

**This is a technical signing record.** The product **must not** describe
or market it as a qualified signature, an advanced signature, an
identity-verified signature, or as guaranteed legally sufficient in any
jurisdiction. **No legal conclusion is made by this contract.**

Legal terms, retention periods and commercial reliance should receive
jurisdiction-appropriate legal review **before production launch**. That is
a **release/compliance gate, not a blocker on implementing Sub-slice C**.

No DocuSign/Dropbox Sign/other vendor is added. If a vendor is ever
adopted, `signature_method` gains a value and the provider's reference is
stored alongside — no restructuring. A **countersignature** outcome would
cost schema (§5.5's `unique(business_document_id)` and §7.1's sign
precondition both assume one signer); that is named here so the cost is
visible rather than discovered later.

### 6.6 Contact, Location and Opportunity integrity

A document is Location-bound and a Contact is Location-local. Individually
valid foreign keys are **not** sufficient identity — at document creation
and at **every** identity-setting path, the manager re-derives and
requires all of the following, refusing otherwise:

**BusinessLocation**
- belongs to the exact `business_id` of the document;
- is currently valid under the applicable Location lifecycle rule.

**Contact**
- `contact.business_id === document.business_id`;
- `contact.location_id === document.business_location_id`;
- a **NULL** Contact `location_id` is **refused** for a new transactional
  document (Addendum §5 treats a null Location only as a transitional
  backfill state, never an operating mode);
- a **sibling** Location is refused;
- a **foreign** Business is refused.

**CrmOpportunity (optional)**
- `opportunity.business_id === document.business_id`;
- `opportunity.location_id === document.business_location_id`;
- `opportunity.contact_id === document.contact_id`;
- NULL, ambiguous or foreign Location is refused.

Adversarial tests cover **every** mismatch listed (§12.B).

## 7. Transaction / concurrency boundary

**Hard rule, adopted verbatim from `ManagedMessageDispatcher`: no provider
network call ever happens inside a database transaction or while any row
lock is held.** Every provider interaction is a three-step sequence — write
the local intent row in its own short committed transaction, call Stripe
outside any transaction, finalize in a second short transaction. There is
no case in this slice where holding a lock across a network call is
justified.

### 7.0 One canonical lock order — no exceptions

An earlier revision contained a deadlock cycle: payment finalization
locked the payment then touched the document, while void/revision locked
the document then inspected payments. **Whenever more than one row is
locked, locks are acquired in exactly this order:**

1. `business_documents`
2. `business_document_versions` / `business_document_payment_schedule_items`,
   **ascending id** within each
3. `business_document_payments`, **ascending id**
4. `business_document_refunds`, **ascending id**

A path **may skip** tiers it does not need. A path may **never reverse**
them.

- **Webhook finalization** may resolve the payment id **unlocked** first,
  then must acquire locks in the canonical order (document → schedule item
  → payment) before mutating anything.
- **Refund-only finalization** that never mutates a document may lock
  payment → refund (tiers 3 → 4), **provided it never afterwards takes the
  document lock**. If it needs the document, it must restart from tier 1.

Adversarial deadlock/race tests are required (§12.E): payment success vs
void; payment success vs resend/revision; two callbacks for one payment;
deposit and balance attempts racing.

### 7.1 Document lifecycle sequences

- **Editing the open draft version** — lock the document, verify it has a
  `draft` version (document `status ∈ {draft, sent}`), mutate that
  version, its lines and **its** schedule items, commit.
- **Revising an already-sent document** — lock the document; verify
  `status = sent` (a `signed`, `paid`, `expired` or `void` document is
  refused: a signed agreement is renegotiated by voiding and issuing a new
  document, never by superseding the version someone signed); verify no
  signature row exists; create a new `draft` version at
  `version_number + 1` — `draft_guard` makes a concurrent second attempt
  lose at the database — and **copy the current issued version's lines and
  its schedule commercial terms into new rows belonging to the new
  version**, leaving the prior version's rows untouched. The document
  stays `sent` and the existing link keeps working until the new version is
  sent.
- **Send** — lock the document; verify `status ∈ {draft, sent}`; verify a
  `draft` version exists with at least one line and a resolvable total;
  verify **that version's** schedule is valid (§5.9) **and sums exactly to
  the version's `total_minor` in the document's currency**; verify
  `recipient_email_snapshot` is present and valid (§5.2); transition the
  draft version to `issued` (and any prior `issued` version to
  `superseded`, setting `superseded_at`); set `current_version_id`; freeze
  everything §5.3.1 lists; generate and hash the token, rotating any prior
  one; set `status = sent`, `sent_at`; commit. **Only then**, after commit,
  dispatch the email delivery job — the `ClientInvitationManager` rule: "a
  recipient must never be emailed a claim link for a row that a later
  failure inside the transaction rolled back."
- **Sign** — lock the document; re-verify `status = sent`, not expired,
  not void, `requires_signature`, the §6.3.1 account/entitlement rechecks,
  and that no signature row exists; insert the signature bound to
  `current_version_id` and its `content_hash`; set `status = signed`,
  `signed_at`; commit. `unique(business_document_id)` makes a double-submit
  impossible even under a race.
- **Void (cancellation)** — lock the document (tier 1), then any payments
  it must inspect (tier 3, ascending id). Permitted from `draft`, `sent`
  and `signed`; **refused** from `paid`, `expired` and `void` (terminal
  states never move, §8.3). **Refused while any payment for the document
  is `succeeded` with an unrefunded balance** — captured money must be
  refunded first, so voiding can never abandon a settled obligation
  without a refund record. On success: set `status = void`, `voided_at`,
  `void_reason`; set every `pending` schedule item of the current version
  to `void`; clear `access_token_hash`; commit; emit `DocumentVoided` after
  commit. A void is terminal.

### 7.2 PAY START — one active attempt per schedule item

An ordinal-suffixed key cannot prevent two concurrent first clicks from
each choosing an ordinal, inserting a row, and making a real charge. The
DB-backed invariant is `unique(active_schedule_item_id)` (§5.9); the
algorithm is:

1. begin transaction;
2. **lock the document first** (§7.0 tier 1);
3. verify the exact current issued version (`current_version_id`);
4. **lock the exact schedule item** (tier 2);
5. re-check that the item belongs to `current_version_id` — an item of a
   superseded version is never payable (§5.9);
6. re-check document payability state (§7.3) and the §6.3.1 account/
   entitlement rechecks;
7. re-check the previous `sequence` where applicable (§7.3's deposit-first
   rule);
8. re-check Stripe connection readiness — the **current active**
   connection, `status = active` and `charges_enabled` (§5.7, §11.4);
9. inspect the existing **active** payment attempt for this item;
10. **if one exists, return / re-drive THAT SAME attempt — never create
    another**;
11. otherwise insert exactly **one** payment row with `status = created`;
12. derive the provider idempotency key from **that durable row's UID**;
13. commit;
14. **provider call OUTSIDE any transaction**;
15. finalize through the shared idempotent finalizer (§8.2/§8.3), which
    re-acquires locks in canonical order.

**Provider idempotency key: `document-payment:{payment_uid}`** — never an
independently guessed ordinal.

- A **deliberate retry after a terminal `failed`/`canceled`** attempt
  creates a **new** payment row, and therefore a new UID and a new
  provider key. (The terminal status frees `active_schedule_item_id`.)
- A **retry after an uncertain network result** must re-drive/retrieve the
  **same** local row and the **same** provider key. It must never
  originate a second charge merely because an HTTP response was lost.

Forced-concurrency tests are required (§12.E): two first clicks → one
active row and one provider operation; lost provider response → retry uses
the same key; only a terminal failure/cancel permits a new row.

#### 7.2.1 What PAY START returns, and the two re-drive cases

§7.2 creates or re-drives the one durable attempt. Only after the provider
PaymentIntent exists does the **public payment-start POST** respond with
the **minimum transient browser material** needed to confirm it:

- `payment_uid` (the durable local identity);
- the PaymentIntent **`client_secret`**;
- the **connected-account context** current Stripe.js requires, derived
  **server-side** from the row's own `business_stripe_connection_id`
  (§7.2.2);
- the **publishable-key / config identity** appropriate to that context.

**The platform secret key is never returned.**

**Re-driving an existing active attempt (step 10) has exactly two shapes:**

- **Case A — `provider_payment_intent_id` is known.** Retrieve/reconcile
  **that same** PaymentIntent on the connected account recorded on the row
  (§7.2.2), and return **that same** intent's `client_secret`. A second
  PaymentIntent is never created.
- **Case B — the creation call returned an uncertain result, so the row
  does not yet know the intent id.** Repeat the creation request with the
  **same `document-payment:{payment_uid}` Stripe idempotency key**.
  Stripe's own idempotency returns the original intent, which is
  reconciled into that **same** local row, and its `client_secret` is
  returned.

In neither case is a random retry key used, and in neither case does a
second active local row come into existence.

#### 7.2.2 The connected account is server-derived and browser-immutable

The connected-account context handed to the browser is derived
**server-side** from `business_document_payments.business_stripe_connection_id`
— the exact connection used to create that PaymentIntent (§5.7). Confirmation
must target that same account.

**A browser can never choose, submit, or influence which connected account
is used.** No request parameter names an account, and no account identifier
supplied by a client is ever trusted; the server reads the persisted
connection row. This is enforced by test (§12.E).

### 7.3 Signature is a payability gate

- **`requires_signature = true`** — payable **only** when
  `status = signed`. A payment `POST` while `status = sent` is **refused**.
- **`requires_signature = false`** — payable from `status = sent`.
- **Deposit + balance** — `sequence 1` (`deposit`) must be **succeeded**
  before `sequence 2` (`balance`) becomes payable. "Pay the balance early"
  is not an implicit product feature.
- Every payment initiation re-checks all of this **under the document
  lock** (§7.2 steps 6–7), and always against the schedule of
  `current_version_id`.

### 7.4 Refund sequence

Lock the payment (tier 3), then its refunds (tier 4). Verify the payment
is `succeeded`; compute admission per §8.7 **under that lock**; insert one
`pending` refund row with its durable key; commit; then call Stripe
outside the transaction, against the payment's **historical** connection
(§5.7). This path must not take the document lock afterwards (§7.0).

**Currency coherence.** A line whose snapshot resolves to a currency other
than the document's frozen `currency_code` is a refusal at add-time, not a
conversion — there is no FX in this slice and none is authorized.

### 7.5 Abandoned attempts — bounded reconciliation, one authority

A customer who closes the tab mid-Payment-Element leaves a local attempt
in `created` or `requires_action`, still holding
`active_schedule_item_id`. Two rules resolve this without inventing a
second payment lifecycle:

**Reopening is not a new attempt.** Returning to the secure document and
pressing Pay again runs §7.2, finds the active row at step 9, and
re-drives it per §7.2.1 Case A/B. A refresh, a back-button, or a second
tab therefore never creates a second PaymentIntent.

**A stale attempt cannot hold the schedule forever.** A bounded
reconciliation sweep (§12.F's command surface, `config/documents.php`
threshold) picks up attempts that have sat in a non-terminal local status
past that threshold and, for each one under §7.0's lock order:

- **retrieves the authoritative PaymentIntent from Stripe** on the row's
  own recorded connection, and
- resolves the row **through the same shared idempotent finalizer** every
  other path uses (§8.2/§8.3), with the same
  amount/currency/account/`app_operation_id` cross-checks.

The sweep therefore **introduces no second authority**: it does not decide
outcomes, it only asks the provider and hands the answer to the existing
finalizer. Specifically it must **never** locally mark an attempt `failed`
or `canceled` merely because time passed — a customer may complete an
authentication step late, and a locally-invented terminal state would
release `active_schedule_item_id` while a real charge was still live.
Only a provider-verified terminal outcome (or a provider-confirmed
cancellation the sweep requests explicitly) frees the slot, after which
§7.2 permits exactly one new deliberate attempt.

## 8. Provider integration, idempotency and replay safety

### 8.1 Outbound calls are idempotent by construction

Every Stripe call carries an `idempotency_key` derived from a **durable
row UUID**, never a per-call random value and never a guessed ordinal —
the rule `ManagedDispatchDelegate` states plainly: "A random per-call key
is not idempotency, it is the appearance of idempotency."

- **PaymentIntent:** `document-payment:{payment_uid}` — persisted in
  `business_document_payments.local_idempotency_key`, round-tripped
  through Stripe `metadata.app_operation_id`.
- **Refund:** `document-refund:{refund_uid}` — persisted in
  `business_document_refunds.local_idempotency_key`.

A new provider attempt happens only by way of a **new durable row**
(§7.2), which is what makes "deliberate retry" and "retry after an
uncertain response" mechanically distinguishable.

**Re-driving reuses the key, never regenerates it.** Because the key is
derived from the row's own UID, §7.2.1's Case B — repeating a creation
call whose result was uncertain — necessarily sends the **same**
`document-payment:{payment_uid}` value, so Stripe's own idempotency
returns the original PaymentIntent rather than creating a second one.
Case A does not re-create at all; it retrieves the known intent. Neither
path may mint a fresh key "to be safe": that would convert a lost response
into a real second charge, which is exactly what this rule exists to
prevent.

### 8.2 Inbound events: the claim/lease pattern

Mirroring §3.5's proven mechanism, in the lane-B-owned table:

1. Verify the Connect webhook signature over the **exact raw body before
   any row is inserted**; a verification failure returns `400` with zero
   side effects.
2. Insert identity + encrypted payload + `payload_hash`. A duplicate
   delivery violates `unique(stripe_account_id, provider_event_id)`, is
   caught on SQLSTATE `23000`, and returns **`200` with zero
   re-processing**.
3. Dispatch a job that **claims** the row with one atomic conditional
   `UPDATE` (`state='processing'`, `attempts + 1`, `lease_expires_at`)
   whose `WHERE` admits only `received`, a retryable `failed`, or a
   `processing` row whose lease has expired. `$claimed === 0` → return
   immediately.
4. Terminal writes are guarded `WHERE id = ? AND state = 'processing'`.
5. `last_error` stores an exception **class** or a reason code, never a
   message.
6. Mutation happens only after re-acquiring row locks in §7.0's canonical
   order.

### 8.3 What a replay must never do — and the mechanism that prevents each

| Replay must not | Prevented by |
|---|---|
| Duplicate a payment | `unique(provider_payment_intent_id)`; plus `unique(active_schedule_item_id)` means a second live attempt for one item cannot exist at all (§7.2) |
| Duplicate a state transition | Terminal writes guarded by current state under the canonical lock order; a transition whose precondition no longer holds is recorded `ignored`, never re-applied |
| Duplicate a refund | `unique(provider_refund_id)` plus `unique(business_id, local_idempotency_key)` on `business_document_refunds` |
| Duplicate a reminder or receipt | Durable markers on the owning rows (`reminder_last_sent_at`/`reminder_count`, `expiry_reminder_*`, `receipt_sent_at`) written by the manager, never by the job (§8.4) |
| Move a terminal document backward | `paid`, `void` and `expired` accept **no** inbound transition. A late or replayed event against a terminal document is recorded `ignored` with a reason code. A refund never moves a document out of `paid` (§5.9) |
| Pay a superseded version | Every pay path re-checks the item belongs to `current_version_id` under the document lock (§7.2 step 5) |
| Mark a payment succeeded because a browser came back | A Stripe return/redirect carries **no authority** (§8.5, §11.8). The return route re-renders persisted state only; success is written solely by the verified webhook path or a verified server-to-Stripe retrieval through the same finalizer |
| Create a second intent from a refresh, an SCA step, or an abandoned tab | The active row is re-driven, never re-created (§7.2.1, §7.5); `unique(active_schedule_item_id)` makes a second live attempt impossible |

**Cross-checks before any mutation**, mirroring lane D's own list: the
event's `account` must match the **connection recorded on the local row**
(not necessarily the Business's current connection — §5.7);
`metadata.app_operation_id` must equal the persisted
`local_idempotency_key`; the provider object id, amount and currency must
match. Any mismatch is a fail-closed `failed` disposition with a reason
code (`operation_id_mismatch`, `amount_mismatch`, `currency_mismatch`,
`account_mismatch`, `no_matching_local_record`), never a best-effort
guess.

**Refund and dispute events are routed by `event_type`, not by metadata** —
lane D discovered and documented the underlying Stripe behaviour: "a
refund/dispute/refund-object event never carries this app's own
`app_subject_kind` metadata (Charge/Dispute/Refund metadata is
independent, never inherited from the originating PaymentIntent)".
Resolution is by provider reference (`provider_charge_id` /
`provider_payment_intent_id`); an ambiguous resolution is
`cross_reference_ambiguity`, fail-closed.

### 8.4 Reminder and receipt idempotency

Reminders and receipts are **not** made idempotent by the job. The durable
markers live on the rows themselves — the current version's schedule items
(`reminder_last_sent_at`, `reminder_count`) for payment reminders,
`business_documents` (`expiry_reminder_last_sent_at`,
`expiry_reminder_count`) for offer-expiry warnings, and
`business_document_payments.receipt_sent_at` for the payment receipt —
each written by the **manager** inside the same locked transaction that
selects the row, following the `low_balance_notified_at` precedent ("the
manager owns the durable marker and the dispatch decision; the job never
writes the table").

Reminders target **only** the schedule of the currently payable version
(§5.9). Delivery is **email** to `recipient_email_snapshot` (§11.3).

**On key scoping.** Keys are derived from a document, payment or refund
**UUID**, so they are globally unique by construction; tenant scoping is
applied where the key is *stored* —
`unique(business_id, local_idempotency_key)` on payments and refunds
(§5.9) — so a caller-supplied key can never reach across Businesses even
if a future caller derives one less carefully.

### 8.5 Provider truth vs local truth

- **Stripe is authoritative** for: whether a charge succeeded, the charge/
  intent/refund identifiers, settled amount and currency, and the
  connected account's capability flags.
- **This system is authoritative** for: the document, its versions, lines
  and totals; the signature and its evidence; the payment *schedule* and
  which version owns it; which schedule item a payment belongs to;
  document lifecycle state; and every Location/Contact/permission
  attribution.
- **Local state changes only on a verified inbound event or a direct,
  verified API response** — never on a browser redirect. The legacy
  `PaymentController` confirms payment by reading
  `Session::get('session_id')` after a redirect; that is exactly the
  pattern this slice must not reproduce (§4.1). A post-payment redirect may
  render an optimistic "thank you" page, but it is never the source of a
  state transition.

### 8.6 Expiration must not strand signed or partially paid money

`business_documents.expires_at` is an **OFFER / unpaid-document expiry**,
nothing more. The sweep may expire a document **only** when all hold:

- `status = sent`;
- **no** signature row exists;
- **zero** succeeded payments exist.

Therefore:

- a `requires_signature` proposal, **once signed, never becomes `expired`**
  from `expires_at`;
- a no-signature invoice, **after any payment succeeds, never transitions
  to `expired`**;
- **deposit paid, balance outstanding** — the document remains
  `signed`/`sent` as applicable, the balance remains due per its schedule
  `due_at`, balance reminders continue, and expiry **must not strand the
  collected deposit**;
- `paid`, `void` and `expired` remain terminal;
- an unpaid, unsigned document in `sent` may expire.

Tests prove signed and partially-paid documents are never swept (§12.F).

### 8.7 Refund admission is concurrency safe

Subtracting only already-**succeeded** refunds would let refund A (pending)
and refund B (admitted concurrently) together exceed the captured amount.
Admission therefore happens **under the payment row lock** (§7.4), and:

```
available_refundable =
      captured_amount
    - SUM(amount of PENDING refunds)
    - SUM(amount of SUCCEEDED refunds)
```

A requested refund greater than `available_refundable` is **refused**.
Under the same lock, exactly one `pending` refund row is inserted, the
transaction commits, and only then is the provider called.

- an **uncertain** provider response re-drives/retrieves the **same** row
  and key;
- a **terminal `failed`** refund **releases** its reserved capacity;
- a **`succeeded`** refund consumes it, and updates the schedule item per
  §5.9's partial-refund rule.

A forced race test proves two simultaneous refunds cannot reserve beyond
the captured amount (§12.F).

## 9. Backwards compatibility

Not applicable in the retire-an-old-model sense — pure net-new addition.
Two explicit non-interactions: the legacy `invoices`/`plans`/`payment_methods`/
`PaymentController` stack (lane A) and the entire `App\Library\Usage`
namespace and its tables (lane D) are neither modified nor read (§4, §15).

One deliberate, behavior-preserving touch outside this slice's own files:
§12.A extracts the existing canonical-JSON primitive to a neutral
namespace (§5.3.2). Opportunity keeps its behavior and its existing unit
test; nothing about Opportunity's semantics changes.

## 10. Events / audit

**Blueprint §13 (line 321) names a "proposal-sent follow-up" as a starter
automation**, and Blueprint §31 forbids a module reaching into another
module's tables where a canonical event exists. Events are therefore
required — each listed with the authority it derives from, carrying
numeric ids and scalar fields only, no PII:

| Event | Emitted in | Derives from |
|---|---|---|
| `DocumentSent` | §12.C | Blueprint §13's "proposal-sent follow-up" starter automation — the one event an authority document explicitly requires |
| `DocumentSigned` | §12.C | Blueprint §18 lifecycle transition (`sent → signed`) |
| `DocumentPaymentSucceeded` | §12.E | Blueprint §24 "payment events" reach the Activity Center |
| `DocumentFullyPaid` | §12.E | Blueprint §18 lifecycle transition (`signed/sent → paid`) |
| `DocumentExpired` | §12.F | Blueprint §18 lifecycle transition (`→ expired`) |
| `DocumentVoided` | §12.B | Blueprint §18 lifecycle transition (`→ void`) |
| `DocumentRefunded` | §12.F | Blueprint §24 "payment events"; Blueprint §18 names refunds |

**`DocumentViewed` and `business_documents.last_viewed_at` are not in
V1.** An earlier revision admitted the event derived from no authority and
included it anyway. It is not needed for the acceptance path and it adds
write, event, test and privacy surface to an unauthenticated endpoint, so
it is removed: the public `GET` is genuinely side-effect-free (§6.3). Open
tracking, if ever wanted, is a deliberate separate feature.

**What the durable evidence actually is — stated honestly.** Laravel
domain events are **transient notification/integration events**. They are
not an audit log, and this contract does not imply that emitting
`DocumentSent`/`DocumentSigned`/etc. persists "event rows". V1's durable
evidence is:

- `business_documents`' current lifecycle state and its `*_at` timestamps;
- immutable `issued`/`superseded` versions with their frozen commercial
  content, line items and schedule terms (§5.3.1);
- `business_document_signatures`;
- schedule-item payment progress;
- `business_document_payments` and `business_document_refunds`;
- verified `business_payment_events` for provider ingress.

**This slice therefore does not create a full append-only lifecycle
transition history.** No `document_transitions` table is required by
current authority, and none is invented merely to make this wording
stronger. Reconstructing "who moved this document, and when" beyond the
above is out of scope.

## 11. Billing/provider safety

### 11.1 Lane discipline is a test obligation, not just prose

§4's forbidden list is enforced by a **source-boundary test** (§13). A
prose rule that nothing checks is a rule that erodes, so both sides are
enumerated.

**The files under test — this slice's own surface:**
`app/Library/Documents/**`, `app/Library/Payments/**`,
`app/Library/Money/CurrencyExponent.php`,
`app/Library/Timeline/Sources/DocumentActivitySource.php`,
`app/Models/BusinessDocument*.php`, `app/Models/BusinessStripeConnection.php`,
`app/Models/BusinessPaymentEvent.php`, `app/Http/Controllers/**/*Document*`,
`app/Http/Controllers/**/*BusinessPayment*`, `app/Jobs/**/*Document*`,
`app/Jobs/**/*BusinessPayment*`, `app/Console/Commands/**/*Document*`,
`app/Events/Document*.php`, `database/migrations/*business_document*`,
`database/migrations/*business_stripe_connection*`,
`database/migrations/*business_payment_event*`, and this slice's routes and
Blade views.

**What none of them may reference** — the full §4 list, not a subset:
`App\Library\Usage\*` (any class in that namespace, including the gateway
interface and its implementations); `App\Models\Invoices`;
`App\Models\PaymentMethods`; `App\Models\PaymentProviderCustomer`;
`App\Models\BusinessPaymentInstrument`; `App\Models\BusinessBillingReceipt`;
`App\Http\Controllers\Customer\PaymentController`;
`App\Http\Controllers\Admin\InvoiceController`;
`App\Http\Controllers\Customer\InvoiceController`;
`App\Http\Controllers\StripeWebhookController`;
`App\Jobs\Usage\*`; `App\Enums\Usage\*`; `PayerType`; `EffectivePayer`;
`App\Library\CoinPayments`, `App\Library\NowPaymentsAPI`,
`App\Library\MPesa`, `App\Library\MPGS`; and — as raw strings, so a
migration or raw query cannot evade the namespace check — the table names
`invoices`, `plans`, `payment_methods`, `payment_provider_events`,
`payment_provider_customers`, `business_payment_instruments`,
`business_billing_receipts`, `business_usage_*`, `business_funding_*`,
`business_payer_*`, `additional_business_slot_*`, `usage_meters`,
`ai_usage_*`.

The one deliberate exception: this slice's own `app/Library/Payments/**`
may reference the `Stripe\*` SDK directly, because §4.2 establishes it as
the second, lane-B-owned Stripe boundary.

### 11.2 The Stripe Connect commercial posture — LOCKED

The earlier "pick Standard / Express / Custom" owner decision is
**removed**. It framed the choice around legacy v1 account types, which is
not the current recommended shape for this product and would have blocked
Sub-slice D on a question the coordinator has since resolved against
current official Stripe guidance:

- SaaS platforms are a **direct-charge** use case;
- Stripe's current SaaS guide uses **Accounts v2**;
- under Stripe-owned pricing the **connected merchant is merchant of
  record**, pays Stripe's fees, and assumes its own negative-balance
  liability, processing **direct charges**;
- Stripe explicitly states direct charges are **not recommended** for
  legacy v1 Express/Custom accounts.

**Locked V1 commercial/funds-flow posture:**

- SaaS / **direct-charge** model;
- the **connected Business is merchant of record**;
- funds settle in the **connected Business's** account;
- the connected Business bears its own Stripe fees, refund and chargeback
  balance effects under the selected Stripe-owned-pricing posture;
- **no `application_fee_amount` in V1**;
- the platform **does not intermediate** customer revenue;
- full Stripe-hosted Dashboard access where the current Accounts-v2
  configuration supports it;
- merchant / card-payments configuration required.

**Account API:** use Stripe's **current recommended SaaS connected-account
API at implementation time, preferring Accounts v2.** Verify directly
against the official sources at implementation time —
`https://docs.stripe.com/connect/saas`,
`https://docs.stripe.com/connect/charges`,
`https://docs.stripe.com/connect/accounts-v2`.

If Accounts v2 is still preview, or carries a current SDK/API constraint,
when Sub-slice D is implemented: verify current Stripe docs, **preserve the
commercial posture above**, and **STOP only if no production-supported API
path can satisfy it safely**. Do **not** silently fall back to a legacy
Express/Custom architecture.

### 11.3 Delivery is email; this slice creates no wallet side effect

**Email is the canonical V1 delivery path**, because the Acceptance
Matrix's End Customer row says the customer "signs and pays via the
**emailed** link". Delivery, resend, reminders and receipts all send email
to the document's own `recipient_email_snapshot` (§5.2), via
`Notification::route('mail', …)` from a `Base`-extending job
`implements ShouldQueueAfterCommit`.

**SMS delivery is not required by Slice 17 and is not built here.** An
earlier revision routed document delivery through the messaging
`quickSend()` door and then interpreted the Acceptance Matrix's
`Paid? = Yes` column as being about that SMS send. Both are removed: this
slice spends no messaging credits merely to satisfy Blueprint §18, and
this contract does **not** invent a wallet side effect to explain that
column (§3.1). A user may later share a secure document link through
Conversations via a **separately authorized** integration; that is not
this slice.

**Consequently: no wallet, payer, entitlement-charge, AgencyRebill or
messaging-metering interaction exists anywhere in this slice.**

### 11.4 Connected-account readiness is rechecked, never assumed

Before creating any PaymentIntent, the **current active** connection
(§5.7) is re-read and must be `status = active` with
`charges_enabled = true` (§7.2 step 8). A document may be sent and signed
before Stripe is connected; it simply cannot be paid, and the public page
says so plainly rather than failing at the moment the customer tries.

Webhook finalization and refunds deliberately do **not** require the
historical connection to still be current (§5.7).

### 11.5 The Stripe PHP SDK upgrade is authorized in Sub-slice D

`composer.json` pins `stripe/stripe-php ^7.76`. That old dependency must
not dictate a legacy Connect design, so **Sub-slice D is authorized to
upgrade it** to the current stable version required by the current
supported Stripe Connect/Accounts API. Rules:

- verify the current stable version **at implementation time**;
- keep the dependency-change portion **isolated** within D;
- read the official migration notes before changing code;
- run **all** existing Stripe/payment/usage webhook compatibility suites;
- do **not** modify existing lane-A/lane-D behavior merely to make tests
  pass;
- **no broad dependency upgrades** — this authorization covers
  `stripe/stripe-php` and whatever its own upgrade strictly requires;
- if a legacy callsite needs a mechanical SDK compatibility adaptation,
  keep it **behavior-preserving** and report it explicitly.

This contract deliberately **does not hardcode a guessed version number**.

### 11.6 Provider/webhook version is verified at implementation time

Neither D nor E may overfit to an obsolete SDK or event shape. Both verify
current official Stripe documentation and the API version selected for the
Connect integration. **Regardless of provider version, these invariants
hold:**

- verify webhook authenticity **before** any persistence or mutation;
- immutable provider event identity (`unique(stripe_account_id, provider_event_id)`);
- exact connected-account identity on every event;
- provider-object reference cross-check before mutation;
- duplicate delivery is idempotent;
- **no browser redirect is ever payment truth**;
- provider network calls happen **outside** DB locks;
- historical connected account is used for historical payment/refund
  operations (§5.7).

### 11.7 Disputes are not a V1 document feature

Under the locked direct-charge posture (§11.2) the connected Business owns
the payment relationship and its Stripe balance. Blueprint §18 requires
**refunds**, not a platform dispute-management product. Locked for V1:

- **no** dispute-management UI;
- **no** dispute-response/evidence API;
- **no** automatic document state mutation from a dispute;
- a dispute **never** moves a `paid` document backward.

If the verified Stripe event stream includes dispute events, they may be
**durably recorded or ignored** as provider operational evidence in
`business_payment_events` (safe ingestion, no corruption). The Business
handles the dispute in its own Stripe Dashboard. Any future in-app dispute
workflow is separate scope.

### 11.8 How the customer actually pays — Stripe.js Payment Element

The PaymentIntent architecture (§7.2, §8) said *what* is created and *who*
is authoritative, but not how the end customer supplies a payment method.
Locked for V1, without redesigning around Checkout Sessions:

**The customer pays with Stripe.js + the Stripe Payment Element**, mounted
in the public document page against the Business's **connected account** in
the direct-charge context (§11.2), initialized with the `client_secret`
returned by the payment-start POST (§7.2.1).

- **This application never collects raw card numbers, expiry or CVC.** No
  card data enters a Laravel request body, log, exception, cache, session
  or this database (§6.3.2). The Element talks to Stripe directly.
- **Stripe.js performs the confirmation**, including **SCA / 3-D Secure or
  any other customer authentication**. That is Stripe's client-side flow;
  **this server never implements card authentication**, and an
  authentication step is not a new payment attempt — the same local row and
  same PaymentIntent carry through it (§7.5).
- **The exact current Stripe.js connected-account initialization API is
  verified against official Stripe documentation when Sub-slice E is
  implemented** (§11.6). This contract deliberately does **not** freeze a
  guessed JS option name; it fixes the *posture*, not the parameter
  spelling.
- **Any Stripe return/redirect URL** may bring the customer back to the
  secure document route, but **carries no authority whatsoever**: arriving
  there transitions nothing. The page simply re-renders, or re-polls,
  persisted state (§8.5, §8.3).

**Provider status → local status is mapped in exactly one seam.** The
lane-B gateway (`App\Library\Payments\**`) is the only place that knows
Stripe's status vocabulary; it maps onto this contract's six local values
(§5.9), and **no provider status string may leak into manager, domain,
controller or Blade code**:

| Situation | Local status |
|---|---|
| Intent created, awaiting browser confirmation | `created` |
| Customer authentication required (SCA/3DS or equivalent) | `requires_action` |
| Provider is processing / async settlement in flight | `processing` |
| Verified success (webhook or verified retrieval) | `succeeded` |
| Terminal provider failure | `failed` |
| Terminal cancellation | `canceled` |

The mapping table above is the contract; the exact provider strings it
reads are whatever the verified current API reports at implementation time
(§11.6). Adding a provider status this table does not cover is a
fail-closed condition — the gateway raises rather than guessing a local
state.

## 12. Exact implementation allowlist — seven dependency-ordered sub-slices

Seven, because **Stripe Connect onboarding (D) is separated from charging
(E)**: onboarding carries its own SDK-upgrade work (§11.5) and can ship,
be verified and be reviewed on its own, while E is the highest-risk money
surface.

### Sub-slice A — Schema/domain foundation, money + canonical-JSON primitives, inert identities

- **Files/domains**: migrations for all nine tables (§5.2–§5.9) using the
  staged circular-FK sequence (§5.3.3); Eloquent models with
  casts/relations only; the lane-neutral
  `App\Library\Money\CurrencyExponent` value object (§4.6); the
  **behavior-preserving extraction** of the canonical-JSON primitive to a
  neutral namespace (§5.3.2) with Opportunity still working through it and
  its existing unit test still passing; `config/documents.php`
  (`enabled`, `queue`, `link_ttl_days`, sweep limits, reminder offsets) —
  created here because §12.C reads `link_ttl_days`; the inert
  `PlatformFeature` case + `Planned` registry entry + the new
  `platform_feature_usage_classifications` backfill migration + the
  `workspace_plan_features` seed row for all three tiers (§6.4); and the
  **`payments_contracts` customer capability** — its
  `config/customer-permissions.php` entry **plus** the backfill migration
  following the GBP precedent exactly (§6.1, §3.5).
- **Prerequisites**: Contracts 1–14 merged. **Not** Contract 16 — this
  schema references `package_snapshots` only by `uid` (a plain `uuid`
  column, no FK), so A is parallel-safe and may be implemented now.
- **Schema**: all nine tables, §5.2–§5.9, in full, including every
  generated column (`draft_guard`, `active_business_id`,
  `active_schedule_item_id`) and every unique key.
- **Tenancy/security**: none exposed at this layer; the capability and
  entitlement identities added here are inert.
- **Concurrency**: none; but every constraint §7 and §8 rely on must exist
  now so no later sub-slice needs a schema-altering migration for a
  correctness reason.
- **Tests**: migration/constraint existence for every unique key and all
  three generated columns; **the `current_version_id` FK exists after the
  staged migrations, and `down()` drops it before dropping versions**
  (§5.3.3); schedule items are version-scoped with
  `unique(business_document_version_id, sequence)` and carry **no**
  `business_document_id`; `const UPDATED_AT = null` on write-once models;
  `generateUid()` returns a real UUIDv4 on every model; `CurrencyExponent`
  unit tests including the unlisted-currency refusal; canonical-JSON
  extraction is behavior-preserving (the existing Opportunity test passes
  unchanged); the capability key exists and the backfill grants it to a
  pre-existing customer.
- **Risk**: Low–Medium (the extraction and staged DDL are the only
  non-trivial parts). **Model**: Sonnet 5 sufficient.

### Sub-slice B — Document authoring (draft) + Contract 16 snapshot consumption

- **Files/domains**: `App\Library\Documents\DocumentManager` — create,
  edit the open draft version, add/remove/reorder lines, compute totals,
  build and validate **that version's** schedule (§5.9), enforce §6.6's
  identity integrity, and **void** (§7.1's Void sequence under §7.0's lock
  order, emitting `DocumentVoided`); calls
  `PackageSnapshotService::snapshot()` for every catalog line (§3.4) with
  the document's own `business_location_id`; `App\Events\DocumentVoided`;
  authenticated controllers/routes/Blade for the document list and editor,
  **each carrying the full §6.1 gate chain including the entitlement
  gate**. **No sending, no public surface, no payment.**
- **Prerequisites**: A (hard); **Contract 16 Sub-slices B, C and D merged
  and implemented** (hard — A already exists on `main`, §3.7). C's pricing
  resolver is what decides "quote-only", which this sub-slice's
  `$explicitPriceMinor` rule depends on.
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: §6.1's five-step chain in order; §6.6's integrity
  rules on every identity-setting path.
- **Concurrency**: §7.1's draft-edit lock and `draft_guard`; §7.0's lock
  order for void.
- **Tests**: authoring CRUD × Location-ACL boundary (ungranted Location →
  404); **the §6.1 chain — tenancy without capability denied, capability
  without tenancy denied, tenancy+capability while the feature is
  `Planned` denied** (§6.4); **every §6.6 mismatch refused** (foreign
  Business Contact, sibling-Location Contact, NULL-Location Contact,
  Location of another Business, Opportunity whose business/location/contact
  disagrees); a catalog line produces exactly one `package_snapshot` with
  the document's Location and the correct actor; a later catalog price
  change provably does not alter an existing line; `$explicitPriceMinor`
  only for a quote-only item; schedule validation accepts one `full` or
  exactly `deposit + balance`, **sums to the version total in the
  document's currency**, and is refused otherwise; void permitted from
  `draft`/`sent`/`signed`, refused from terminal states, refused while an
  unrefunded succeeded payment exists, and a void clears the token and
  voids that version's pending schedule items.
- **Risk**: Medium. **Model**: Sonnet 5 sufficient.

### Sub-slice C — Secure send, public view, and e-signature

- **Files/domains**: recipient-snapshot validation and freezing (§5.2);
  token generation/rotation/verification; the send transaction (§7.1) and
  the post-commit **email** delivery job (§11.3 — no SMS, no messaging
  path); the public controller and Blade page rendering the **frozen
  issued version**, with §6.3.1's account/entitlement rechecks on every
  request; the signing `POST` and `business_document_signatures` write;
  **the revise-a-sent-document path** (§7.1 — version N+1 copying the
  prior issued version's lines and schedule commercial terms); the
  `DocumentSent` / `DocumentSigned` events.
- **Prerequisites**: A, B (hard). **No product-owner gate** — §6.5 locks
  the engineering scope; legal review is a release gate, not a blocker.
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: the whole of §6.3 and §6.3.1, including the three
  deltas over the `client_workspace_invitations` precedent and the uniform
  non-enumerating refusal.
- **Concurrency**: §7.0's order; §7.1's send, revise and sign sequences;
  delivery dispatched only after commit.
- **Tests**: adversarial public-surface set — wrong token, expired token,
  rotated (old) token, voided document, unknown uid, and a valid token for
  a *different* document each produce the **byte-identical** refusal;
  **a suspended/unentitled account produces that same refusal** (§6.3.1),
  proving the link is not an account bypass; the plaintext token never
  appears in any stored row or log; `throttle:` enforced; unknown uid is
  404 not 500; **the public `GET` writes nothing** (§10); signing twice is
  impossible and the second attempt is a clean refusal; the signature binds
  the exact version and content hash; **a `requires_signature` document
  cannot be paid while `sent`** (§7.3); revising a sent document creates
  version N+1, **copies** the schedule into the new version, leaves the old
  version's terms untouched, supersedes it on send, rotates the token, and
  **the superseded version's pending schedule rows are no longer payable**;
  revising a `signed` document is refused; `content_hash` behaves per
  §5.3.2 (key-order-independent → same hash; a meaningful line or schedule
  change → different hash; payment/reminder progress → **no** hash change);
  a source-boundary test proving no production path mutates an issued
  version's commercial fields or its line/schedule commercial terms, while
  the authorized `issued → superseded` transition still works (§5.3.1).
- **Risk**: **High** — an unauthenticated, high-value surface where a
  signature bound to mutable content would be worthless.
- **Model**: **Opus 5 warranted.**

### Sub-slice D — Stripe Connect onboarding (+ authorized SDK upgrade)

- **Files/domains**: `App\Library\Payments\StripeConnectGateway` — the
  **lane-B-owned** Stripe boundary (§4.2); owner-only connect/disconnect
  (§6.2); onboarding via the current recommended SaaS connected-account
  API, preferring Accounts v2 (§11.2); capability sync into
  `business_stripe_connections`; **the isolated `stripe/stripe-php`
  upgrade** (§11.5).
- **Prerequisites**: A (hard). **No unresolved commercial gate** — §11.2
  is locked. The only stop condition is §11.2's last clause: if no
  production-supported API path can satisfy the locked posture safely,
  STOP and report.
- **Schema**: none new — consumes A's `business_stripe_connections`.
- **Tenancy/security**: owner-only per §6.2; no connected-account secret is
  ever stored (§5.7).
- **Concurrency**: §7's no-network-call-under-lock rule; `lock_version` for
  optimistic capability sync.
- **Tests**: **`unique(active_business_id)` permits only one non-terminal
  connection per Business, while historical terminal rows coexist**;
  disconnect frees `active_business_id` and a new account creates a **new
  row**; `stripe_account_id` is never rewritten on an existing row; a
  non-owner cannot connect or disconnect; capability flags round-trip; a
  restricted/disabled account blocks charging (§11.4); an instrumentation
  test proving no gateway call occurs while `DB::transactionLevel() > 0`;
  and the full existing Stripe/payment/usage webhook suites still pass
  after the SDK upgrade (§11.5).
- **Risk**: **High** — new provider surface plus a dependency upgrade.
- **Model**: **Opus 5 warranted.**

### Sub-slice E — Payment schedule execution, PaymentIntents, webhook ingestion

- **Files/domains**: `App\Library\Payments\PaymentManager` implementing
  **§7.2's PAY START algorithm verbatim** (including §7.2.1's Case A/B
  re-drive and the server-derived connected-account context of §7.2.2) and
  the shared idempotent finalizer; **the provider→local status mapping
  seam in the lane-B gateway (§11.8), the only place Stripe status strings
  exist**; the **public payment-start `POST`** (§6.3.2 — with §6.3.1's
  rechecks and §7.3's payability gate, a strict input schema that accepts
  **no** card fields, and §7.2.1's transient response); the public
  document page's payment-state rendering and **Stripe.js Payment Element
  front-end** (§11.8), plus the no-authority return route; the lane-B
  webhook route, controller and
  `App\Jobs\BusinessPayments\ProcessBusinessPaymentEvent` implementing
  §8.2 in full under §7.0's lock order; the receipt email job dispatched
  from `DocumentPaymentSucceeded`, deduped by `receipt_sent_at`; the
  `DocumentPaymentSucceeded` / `DocumentFullyPaid` events;
  `VerifyCsrfToken` exception and `STRIPE_CONNECT_WEBHOOK_SECRET`.
  **Verify the current Stripe.js connected-account initialization API
  against official Stripe documentation before writing it** (§11.6,
  §11.8) — this contract fixes the posture, not the option spelling.
- **Prerequisites**: A, B, C, D (hard).
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: §6.3.1 for the public pay action; §11.4's
  readiness recheck immediately before every intent.
- **Concurrency**: §7.0's canonical order, §7.2's algorithm, §8.2's
  claim/lease. **This is the sub-slice where concurrency correctness is
  the deliverable.**
- **Tests**: the entire §8.3 replay table, each proved by actually
  replaying the same event; **§7.2's forced-concurrency set — two
  simultaneous first clicks produce exactly one active payment row and one
  provider operation; a lost provider response re-drives the same row and
  the same `document-payment:{payment_uid}` key; only a terminal
  failed/canceled attempt permits a new row**; **§7.0's adversarial
  deadlock/race set — payment success vs void, payment success vs
  resend/revision, two callbacks for one payment, deposit and balance
  racing**; **paying a superseded version's schedule item is refused**;
  **`requires_signature` + `sent` payment refused; balance before a
  succeeded deposit refused** (§7.3); every fail-closed cross-check
  (`amount_mismatch`, `currency_mismatch`, `account_mismatch`,
  `operation_id_mismatch`, `no_matching_local_record`); invalid signature →
  400 with zero rows; duplicate delivery → 200 with zero re-processing; a
  deposit-paid document is not `paid` until the balance settles; a browser
  redirect never transitions state; every PaymentIntent carries the
  `Stripe-Account` header for the resolved connection and **no
  `application_fee_amount`**, asserted against the fake gateway's recorded
  options; **a webhook for a now-disconnected historical account still
  finalizes its own older payment** (§5.7); paying a document linked to an
  Opportunity leaves that opportunity's stage unchanged (Blueprint §9); the
  §11.1 lane source-boundary test.
  **Plus the payment-collection set (§11.8), all against the fake
  gateway — no live network test:**
  (a) the public `GET` document page creates **zero** payment rows and
  makes **zero** provider calls;
  (b) payment-start returns a `client_secret` belonging to the **exact**
  durable attempt identified by `payment_uid`;
  (c) the Laravel endpoint **rejects** any request carrying card
  number/expiry/CVC-shaped fields — they are not in its schema;
  (d) `client_secret` is **never** persisted to any table, written to any
  log, included in an exception message, or placed in a timeline/audit
  row (assert across the payments table, the events table and the log
  sink);
  (e) refresh/reopen re-drives the **same** local row and the **same**
  PaymentIntent (§7.2.1 Case A);
  (f) an uncertain creation response re-drives with the **same**
  `document-payment:{payment_uid}` key and yields one intent
  (§7.2.1 Case B);
  (g) a browser-supplied account identifier **cannot** change the
  connected account used — it is read from the persisted connection row
  (§7.2.2);
  (h) a `requires_action`/SCA cycle creates **no** second attempt;
  (i) hitting the Stripe return URL alone **never** marks a payment
  succeeded;
  (j) the verified webhook completes **that same** row;
  (k) after a terminal provider failure, exactly **one** new deliberate
  attempt is permitted;
  (l) each provider status maps to the correct local status per §11.8's
  table, and an unmapped provider status **fails closed** rather than
  guessing.
- **Risk**: **Critical** — real customer money, replay safety, lock
  ordering and the lane boundary all land here.
- **Model**: **Opus 5 warranted.**

### Sub-slice F — Reminders, offer expiration, refunds

- **Files/domains**: **three** scheduled commands following the
  `SweepExpiredOpportunitySnoozes` convention exactly (§3.5) —
  `documents:expire-due`, `documents:dispatch-due-reminders`, and
  `documents:reconcile-stale-payments` (§7.5) — separate because they
  select disjoint row sets; refund issuance and admission in
  `App\Library\Payments\PaymentManager` (§7.4, §8.7) plus refund webhook
  handling routed by `event_type` (§8.3); the sweep/reminder/stale-payment
  keys added to the `config/documents.php` Sub-slice A created; the
  `DocumentExpired` / `DocumentRefunded` events.
- **Prerequisites**: A, B, C, E (hard).
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: refunds per §6.1 (capability + confirmation).
- **Concurrency**: per-row transaction + `lockForUpdate()` + re-verify the
  precondition under the lock, §7.0's order, §7.4's refund sequence;
  `Throwable` per row logged, loop continues.
- **Tests**: both commands' full convention suite (exit codes, exact output
  strings, default option read off the definition, `--limit` honored,
  double-run idempotency, disabled → exact message + zero mutation + a
  bound fake that throws if invoked, every invalid `--limit` form →
  `self::INVALID` + zero mutation, manager exception not swallowed) plus
  the `ReflectionMethod` schedule-registration test; **§8.6's expiration
  set — a signed proposal is never swept; an invoice with any succeeded
  payment is never swept; a deposit-paid document is never swept and its
  deposit is never stranded; an unsigned unpaid `sent` document is swept**;
  reminders target only the current version's schedule and are never sent
  twice for the same item and window; **§5.9's partial-refund set — a
  partial refund leaves the schedule item `paid`, only a full cumulative
  refund marks it `refunded`, and the document stays `paid` throughout**;
  **§8.7's admission set — pending + succeeded refunds both reserve
  capacity, an over-refund is refused, a failed refund releases capacity,
  and a forced race of two simultaneous refunds cannot reserve beyond the
  captured amount**; a refund targets the payment's **historical**
  connection; refunds are idempotent under replay; **§7.5's stale-payment
  set — the sweep retrieves the provider intent and resolves it through
  the shared finalizer, never inventing a terminal state; an abandoned
  `created`/`requires_action` row does not block its schedule item
  forever; and a row whose customer completes authentication late is
  finalized correctly rather than having been locally killed**.
- **Risk**: Medium–High (the refund concurrency set is the hard part).
- **Model**: **Opus 5 warranted** for the refund-admission, expiration and
  stale-payment invariants.

### Sub-slice G — Integration hardening and the entitlement flip

- **Files/domains**: nav entry via `CustomerMenuBuilder` **including adding
  the new key to `ENTITLEMENT_GATED_FEATURES`** (omitting it silently hides
  the item forever — the lesson recorded in Contract 16 §18.E);
  `App\Library\Timeline\Sources\DocumentActivitySource implements
  TimelineSource` registered against `ContactActivityTimeline::SOURCES_TAG`;
  payment events surfaced in the Activity Center and documents in Global
  Search, both Location-filtered (Blueprint §24, §26); the
  `Planned → Available` entitlement flip as the **last** step.
- **Prerequisites**: A–F (hard).
- **Schema**: none new.
- **Tenancy/security**: §6.1 — capability plus `LocationAccessGuard` on
  every Activity Center, Global Search and timeline read. Neither surface
  may return, or hint at the existence of, a document outside the viewing
  actor's granted Locations. `DocumentActivitySource` follows
  `AutomationActivitySource`'s guard: return `[]` rather than guess when
  the subject's Contact does not belong to the Business.
- **Concurrency**: none — read-only surfaces plus a single registry flip.
- **Tests**: nav visibility per tier (all three, Blueprint §21); timeline
  items with past-tense titles and Location filtering; search and Activity
  Center never reveal a document the actor could not open; **flipping the
  feature to `Available` in a test makes the authorized authenticated path
  work, and it is refused while `Planned`** (§6.4).
- **Risk**: Low. **Model**: Sonnet 5 sufficient.

## 13. Required tests

Beyond each sub-slice's own suite:

1. **The lane source-boundary test** (§11.1) — mechanical, and the single
   most important test in this contract.
2. **The end-to-end acceptance path**, matching the Acceptance Matrix
   verbatim: an owner sends a proposal, a customer signs and pays it via
   the **emailed** link, and both parties see it.
3. **The immutability proof**: a catalog price change after issuance
   provably does not alter the issued version, its lines, its schedule
   commercial terms, or the signature's bound content hash — while the
   authorized `issued → superseded` transition still functions (§5.3.1).
4. **The version-scoped schedule proof**: revise → re-send → the prior
   version's schedule terms are unchanged and its pending rows are no
   longer payable, while the new version's schedule is.
5. **The money-serialization proof**: §7.2's forced-concurrency set and
   §8.7's refund-admission race.
6. **The payment-collection proof** (§12.E's (a)–(l), §11.8): the public
   `GET` makes no provider call; the Laravel endpoint accepts no card
   data; `client_secret` is never persisted or logged; refresh, SCA and
   abandonment all re-drive the same attempt rather than creating another;
   a browser return alone never marks a payment succeeded; and the
   connected account cannot be influenced by browser input. All against
   the fake gateway — **no live network test anywhere in this slice**.
7. A Location-ACL and §6.1-gate-chain regression across every
   authenticated surface this slice adds.

## 14. Acceptance criteria

1. No file in this slice references any lane-A or lane-D payment artifact
   enumerated in §11.1 (which mirrors §4 in full), proved by the §11.1
   source-boundary test.
2. Every charge is a direct charge on the Business's own connected account
   under §11.2's locked posture; no `application_fee_amount` is ever set;
   `unique(active_business_id)` holds, with historical connection rows
   preserved and never rewritten.
3. Every transactional document is Location-attributed (`NOT NULL`),
   satisfies §6.6's Contact/Location/Opportunity integrity rules, and every
   catalog line carries a Contract 16 `package_snapshot_uid`.
4. An issued version's **commercial content** — content, totals, lines and
   schedule commercial terms — is immutable, proved by a source-boundary
   test; the only permitted metadata transition is `issued → superseded`.
5. The payment schedule belongs to the version; only the current version's
   schedule is payable; a superseded version's pending rows can never be
   paid.
6. At most one live payment attempt exists per schedule item, enforced by
   `unique(active_schedule_item_id)`, and two concurrent first clicks
   produce exactly one provider operation. A refresh, an SCA step, an
   abandoned tab, or an uncertain provider response all **re-drive that
   same attempt** and never create a second PaymentIntent (§7.2.1, §7.5).
7. Card data is collected only by Stripe.js in the browser; no card field
   is accepted by any endpoint in this slice, and `client_secret` is never
   persisted, logged, or placed in an exception, timeline or audit row
   (§6.3.2, §11.8). The connected account used for confirmation is
   server-derived from the payment row and cannot be influenced by the
   browser (§7.2.2).
8. Provider status strings exist only in the lane-B gateway seam and map
   onto this contract's six local statuses; an unmapped provider status
   fails closed (§11.8).
9. Replaying any webhook event produces no duplicate payment, transition,
   refund or receipt, and never moves a terminal document backward (§8.3).
   A Stripe return/redirect alone transitions nothing (§8.5, §11.8).
10. All multi-row locking follows §7.0's canonical order; no provider
    network call occurs inside a transaction or under a row lock.
11. A `requires_signature` document cannot be paid before it is signed, and
    a balance cannot be paid before its deposit succeeds (§7.3).
12. Cumulative succeeded refunds never exceed a payment's captured amount,
    and admission accounts for pending refunds (§8.7).
13. `expires_at` expires only unsigned, unpaid `sent` documents (§8.6), and
    an abandoned payment attempt is reconciled from the provider rather
    than locally invented, so it neither blocks its schedule item forever
    nor kills a late-completing authentication (§7.5).
14. The public link is non-guessable, hashed at rest, expiring, rotatable,
    throttled, returns one uniform refusal for every failure reason, and
    **re-checks account lifecycle and entitlement on every request**
    (§6.3.1).
15. Every authenticated route carries the full §6.1 gate chain, including
    the entitlement gate, from the sub-slice that introduces it.
16. Document lifecycle changes never auto-advance a CRM pipeline stage
    (Blueprint §9).
17. The entitlement flips to `Available` only after A–F are merged and the
    end-to-end path passes.
18. `git diff --check` clean and a clean working tree per sub-slice commit.

## 15. Non-goals

- **Any lane A, C, or D money.** No platform Stripe, no Agency Stripe, no
  usage wallet, no `PayerType`/`EffectivePayer`/AgencyRebill, no
  `payment_provider_customers`, no `business_payment_instruments`, no
  auto-recharge — and no reuse of the legacy `invoices`/`plans`/
  `payment_methods`/`PaymentController` stack (§4).
- **Any wallet side effect or messaging spend for document delivery** —
  email only (§11.3). SMS/Conversations sharing of a document link is a
  separately authorized future integration.
- **A dedicated client portal** — explicitly V2 (Blueprint §18, §34); the
  per-document link is the V1 mechanism.
- **Proposal → Invoice conversion, or any persisted relationship between
  two documents** — no authority describes one (§5.1).
- **Complex installment plans** — V2 (Blueprint §34). Deposit + balance
  only.
- **Per-Location Stripe accounts** — V2 (Blueprint §34).
- **Discounts or negotiated prices** — forbidden by Contract 16 §15.
- **Tax calculation** — named in no authority document; not invented.
- **A PDF artifact** — no capability exists, none is authorized (§5.6).
- **Naming or integrating an e-signature vendor**, and **making any legal
  claim** about the signature evidence — §6.5 locks the engineering scope
  and routes legal review to a release gate.
- **Countersignature / multiple signers** — one signer in V1 (§5.5, §6.5).
- **`DocumentViewed` / open tracking** — removed from V1 (§10).
- **A full append-only lifecycle transition history** — §10 states exactly
  what durable evidence V1 does and does not have; no
  `document_transitions` table is invented.
- **Dispute management** beyond safe ingestion (§11.7).
- **Auto-advancing an Opportunity pipeline stage from payment progress** —
  Blueprint §9 forbids it.
- **Wiring document events into the Automations builder vocabulary** — the
  events are emitted (§10), but `NoUnsupportedVocabularyTest` forbids
  `invoice`, `quote` and `payment_received` as case-insensitive substrings
  in the builder's JS and Blade (`proposal` is **not** on either list), so
  changing that guardrail is a separately authorized follow-on.
- **Multi-recipient document sending** — one recipient per document (§5.2).
- **Broad dependency upgrades** — §11.5 authorizes `stripe/stripe-php`
  only.
- **Reopening the Workspace/Agency tenancy migration** (Contracts 1–14) or
  modifying Contract 16's own tables.

## 16. Merge prerequisites

- **Contract 16 Sub-slices B, C and D merged *and implemented*** — hard,
  for Sub-slice B onward. **Sub-slice A of Contract 16 is already
  implemented on `main`** (§3.7), so the remaining gate is B + C + D:
  Contract 16 §12.D names A, B and C as its own hard prerequisites, and
  Sub-slice C's pricing resolver is what decides whether an item is
  quote-only — which this slice's `$explicitPriceMinor` rule depends on.
- **Slice 17 Sub-slice A is parallel-safe and may be implemented now** — it
  references `package_snapshots` only by `uid`, with no FK (§12.A).
- Contracts 1–14 merged for `LocationAccessGuard`, the customer-permission
  convention and the entitlement/nav machinery.
- Per-sub-slice prerequisites are stated in §12. **No sub-slice is gated on
  an unanswered product decision** (§11.2, §6.5).

## 17. Conflict map

| Other work | Shared file/table | Posture |
|---|---|---|
| Contract 16 (Packages & Products) | `package_snapshots` — **read-only consumer**, plus calls to `PackageSnapshotService::snapshot()`; this slice never writes that table | Serialize: Sub-slice B needs Contract 16 B + C + D implemented (A already is) |
| Contract 15 (Calendar) — **merged contract document** (§3.7) | none — both independently add a `CustomerMenuBuilder`/`ENTITLEMENT_GATED_FEATURES` line and a `PlatformFeature` case; ordinary low-conflict merges | Parallel-safe |
| Contracts 18 (SEO) and 20 (Niche Blueprint) — merged contract documents (§3.7) | none identified | Parallel-safe |
| Opportunity domain | `app/Library/Opportunity/CanonicalJson.php` — **moved** to a neutral namespace behavior-preservingly by §12.A, Opportunity left working through it | Coordinate: one mechanical, behavior-preserving extraction in Sub-slice A |
| RFC-005 usage billing (lane D) | **none by construction** — separate tables, route, gateway class and webhook secret; enforced by the §11.1 source-boundary test. One exception: §11.5's SDK upgrade requires re-running lane-A/D webhook suites without changing their behavior | Must never converge |
| Automations | `NoUnsupportedVocabularyTest` (only if a follow-on exposes a document trigger) | Deferred; not touched by this slice |
| `AppServiceProvider` (`SOURCES_TAG`), `CustomerMenuBuilder`, `config/customer-permissions.php` | additive lines | Low risk |

## 18. Implementation prompts

Each sub-slice is handed to a fresh session independently, once explicitly
authorized. Every prompt assumes Contracts 1–14 and every lower-lettered
sub-slice are already merged, and every implementer **re-verifies current
`main` rather than trusting this document's dated recon**.

### 18.A — Schema/domain foundation, money + canonical-JSON primitives, inert identities

```
You are implementing Sub-slice A of Slice 17 (Proposal / Contract /
e-signature / Invoice) for os-creator1/os-ai, per docs/product/
implementation-contracts/17-PROPOSAL-CONTRACT-ESIGNATURE.md SS5 and
SS12.A. Schema, models and primitives only -- no managers, controllers,
routes, UI, or provider code.

Before writing code:
1. Fetch origin/main and record the SHA you are building on.
2. Read SS4 (money lanes) and SS5 (canonical domain model) in full. SS4 is
   the most important section: every existing payment artifact in this
   repository belongs to a forbidden lane.
3. Create a fresh worktree/branch for this sub-slice only.

Implement exactly the nine tables in SS5.2-SS5.9 as migrations, plus
Eloquent models with casts/relations only. Non-negotiable details:
- Integer MINOR units everywhere (unsignedBigInteger) + char(3)
  currency_code. Never micro-units -- that is lane D's convention.
- business_document_payment_schedule_items belongs to
  business_document_version_id (FK -> business_document_versions,
  cascadeOnDelete, NOT NULL) with unique(business_document_version_id,
  sequence). It has NO business_document_id column. SS5.9 explains why.
- THREE stored generated columns, all required now: draft_guard on
  versions, active_business_id on business_stripe_connections, and
  active_schedule_item_id on business_document_payments. Copy the
  storedAs(CASE WHEN ...) technique from
  database/migrations/*create_automation_workflow_versions_table*.
- business_stripe_connections has NO unique(business_id) -- connections
  are historical records and uniqueness lives on active_business_id and
  stripe_account_id (SS5.7).
- Payment status enum is exactly: created, requires_action, processing,
  succeeded, failed, canceled.
- The circular FK is STAGED per SS5.3.3: create business_documents with a
  nullable current_version_id scalar + index and NO FK; create
  business_document_versions with its FK; add the current_version_id FK in
  a LATER ordered migration via Schema::table; down() drops that FK before
  dropping versions. Write a test proving the FK exists.
- Every model overrides generateUid() to (string) Str::uuid(); the HasUid
  trait mints uniqid(), which is time-ordered and guessable.
- Write-once models get const UPDATED_AT = null and a created_at-only
  migration column -- mirror app/Models/WebsiteRevision.php. NOTE: version
  rows are NOT write-once as rows (state must transition issued ->
  superseded, SS5.3.1), so business_document_versions keeps timestamps.
- Every unique key and durable dedupe marker later sub-slices rely on must
  exist now (expiry_reminder_last_sent_at/_count on documents,
  reminder_last_sent_at/_count on schedule items, receipt_sent_at on
  payments, and unique(business_id, local_idempotency_key) on payments and
  refunds).

Also create config/documents.php (enabled, queue, link_ttl_days, sweep
limits, reminder offsets) -- Sub-slice C reads link_ttl_days.

Also create App\Library\Money\CurrencyExponent -- a lane-NEUTRAL value
object (NOT under App\Library\Usage, and it must not call into it)
carrying the zero/two/three-decimal currency lists and Stripe's
minor-unit bounds, failing closed on an unlisted currency code.

Also EXTRACT the canonical-JSON primitive to a neutral namespace,
BEHAVIOR-PRESERVINGLY (SS5.3.2). app/Library/Opportunity/CanonicalJson.php
self-describes as "the general-purpose canonical JSON primitive"; move it
so Documents does not depend semantically on the Opportunity domain, leave
Opportunity working through the extracted class, and keep
tests/Unit/Opportunity/CanonicalJsonTest.php passing (relocate/alias it as
needed without weakening it). If extraction proves mechanically unsafe,
STOP and report rather than silently forking a second copy.

Also add the inert identities:
- a new PlatformFeature case + PlatformFeatureRegistry entry at Planned
  (NOT Available), a NEW backfill migration for
  platform_feature_usage_classifications (never edit the merged one -- see
  the MessagingTransport docblock in app/Enums/Entitlement/PlatformFeature.php),
  and a workspace_plan_features seed row for all three tiers;
- the payments_contracts customer capability in
  config/customer-permissions.php (category "Payments & Contracts") PLUS a
  backfill migration modelled exactly on
  2026_09_09_120006_backfill_google_business_profile_view_permission.php --
  customer permissions are persisted per customer, so a config key alone
  grants it to future customers only.

Tests per SS12.A. Run them, run git diff --check, commit, push to the
fresh branch. Do NOT create a PR. Do NOT merge. Return: starting/final
SHA, exact files created/moved, exact tests run and counts, and
confirmation every constraint and generated column in SS5 exists.
```

### 18.B — Document authoring + Contract 16 snapshot consumption

```
You are implementing Sub-slice B of Slice 17, per SS12.B of docs/product/
implementation-contracts/17-PROPOSAL-CONTRACT-ESIGNATURE.md.

HARD PREREQUISITE: Contract 16 Sub-slices B, C and D must be merged AND
IMPLEMENTED (Sub-slice A already is). Verify that ALL of these exist
before writing code: catalog_items, catalog_item_location_overrides and
package_snapshots; app/Library/Catalog/PackageSnapshotService; and
Contract 16 Sub-slice C's pricing resolver (the class that decides whether
an item is quote-only at a Location -- your $explicitPriceMinor rule
depends on it). If any is missing, STOP and report.

Read SS3.4, SS6.1, SS6.6 and SS5.9 before writing code.

Call the snapshot service with its exact signature:
  snapshot(CatalogItem $item, BusinessLocation $location, ?User $actor = null, ?int $explicitPriceMinor = null): PackageSnapshot
passing the DOCUMENT's own business_location_id. The service performs no
authorization -- this slice owns the entire gate.

Build DocumentManager (create / edit the open draft version / lines /
totals / schedule / void) and the authenticated list + editor UI.
Non-negotiables:
- Line items AND schedule items belong to the VERSION, not the document.
  Line items reference the snapshot by UID, not id.
- Enforce SS6.6's integrity rules on EVERY identity-setting path: Location
  belongs to the Business and is valid; Contact business_id and
  location_id both match the document exactly; a NULL Contact location is
  REFUSED; a sibling Location is refused; a foreign Business is refused;
  an optional Opportunity must match business + location + contact. Do not
  infer identity merely because foreign keys individually exist.
- Every authenticated route carries the FULL SS6.1 gate chain in order:
  tenancy, payments_contracts capability, EntitlementManager check for the
  Payments & Contracts PlatformFeature, LocationAccessGuard for the exact
  Location, plus any owner check. The feature is still Planned, so guessed
  routes must fail closed. Never rely on navigation hiding.
- Schedule validation: exactly one `full` item, or exactly two (`deposit`
  then `balance`); amounts must sum EXACTLY to the version total in the
  document's currency; anything else is a refusal.
- Void per SS7.1 under SS7.0's lock order, emitting DocumentVoided.

$explicitPriceMinor may be passed ONLY for a genuinely quote-only item. It
is never a discount mechanism -- Contract 16 SS15 forbids that.

Do NOT build sending, tokens, the public surface, signatures, or any
payment code -- those are C, D and E.

Tests per SS12.B, especially the SS6.1 gate-chain matrix and every SS6.6
mismatch.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT
create a PR. Do NOT merge.
```

### 18.C — Secure send, public view, e-signature

```
You are implementing Sub-slice C of Slice 17, per SS12.C. Hard
prerequisites: Sub-slices A and B merged.

There is NO product-owner gate on this sub-slice. SS6.5 locks the
engineering scope: first-party provider-neutral TYPED signature evidence,
one signer, no countersignature, bound to an immutable issued version and
its exact content hash, with typed name, signer-entered name/email,
verbatim consent statement + hash, IP, user agent, server timestamp and
the token possession that authorized it. This is a TECHNICAL signing
record. Do NOT write UI copy, docs or comments claiming it is a qualified
or advanced signature, identity-verified, or legally sufficient anywhere.
Legal review is a release gate, not your blocker. Add no vendor.

Read SS5.2 (recipient snapshots), SS5.3.1/SS5.3.2 (what freezes; the
canonical hash), SS6.3 and SS6.3.1, and SS7.0/SS7.1 before coding.

Non-negotiables:
- Delivery is EMAIL to the document's recipient_email_snapshot. Validate
  and freeze the recipient fields at send. NEVER re-read live Contact
  identity for delivery, resend, reminders or receipts. Do NOT use
  quickSend(), ManagedMessageDispatcher, or any messaging/wallet path --
  SS11.3 removes SMS from this slice entirely.
- Mirror app/Library/Workspace/ClientInvitationManager exactly for the
  link: two-segment route {uid}/{token}, Str::random(64) plaintext,
  Hash::make() stored as token_hash, verified only via Hash::check(),
  never queried by plaintext, ONE uniform non-enumerating refusal for
  every failure reason. Add the three deltas the precedent lacks:
  throttle: on every public route (annotate the number, per
  routes/public.php:40-42), ->missing(fn () => abort(404)), and
  hash_equals() for non-bcrypt comparisons.
- EVERY public request (GET view, POST sign, POST pay) rechecks, from
  persistence, per SS6.3.1: token validity, the owning account's lifecycle
  via the existing canonical account-access authority (do NOT write a
  second lifecycle resolver), the Payments & Contracts entitlement via
  EntitlementManager, Location usability, and document lifecycle. The link
  is authorization for one document -- never an account or entitlement
  bypass. Failures return the same uniform refusal.
- The public GET is SIDE-EFFECT-FREE. There is no DocumentViewed event and
  no last_viewed_at column (SS10). Do not add view tracking.
- Send (SS7.1) freezes the version and the document identity/recipient
  fields, rotates the token, and dispatches email ONLY after commit.
- Revising a sent document creates version N+1 and COPIES the prior issued
  version's lines and schedule COMMERCIAL TERMS into new rows for the new
  version. Never mutate the old version's terms. Once the new version is
  issued the old one is superseded and its pending schedule rows are no
  longer payable.
- content_hash uses SS5.3.2's canonical bytes exactly, over content + line
  items (position then uid) + schedule commercial terms (by sequence) +
  totals, EXCLUDING all progress/status/reminder/payment fields.

Tests per SS12.C. The adversarial public-surface set is the point of this
sub-slice, including a suspended/unentitled account getting the same
byte-identical refusal, and the hash tests in SS5.3.2.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT create
a PR. Do NOT merge. Report any residual security concern you could not
fully close rather than asserting confidence you do not have.
```

### 18.D — Stripe Connect onboarding (+ authorized SDK upgrade)

```
You are implementing Sub-slice D of Slice 17, per SS12.D. Hard
prerequisite: Sub-slice A merged.

The commercial posture is LOCKED in SS11.2 -- there is no
Standard/Express/Custom decision to ask about any more. Read SS11.2,
SS11.5 and SS5.7 in full first, then VERIFY current official Stripe
guidance directly at:
  https://docs.stripe.com/connect/saas
  https://docs.stripe.com/connect/charges
  https://docs.stripe.com/connect/accounts-v2

Locked posture you must preserve: SaaS/direct-charge; the connected
Business is merchant of record; funds settle in the connected Business's
account; the connected Business bears its own Stripe fees/refund/
chargeback balance effects; NO application_fee_amount; the platform does
not intermediate customer revenue; full Stripe-hosted Dashboard access
where the current Accounts-v2 configuration supports it; merchant/
card-payments configuration required.

Use Stripe's CURRENT recommended SaaS connected-account API, preferring
Accounts v2. If Accounts v2 is still preview or carries an SDK/API
constraint, preserve the commercial posture and STOP only if no
production-supported path can satisfy it safely. Do NOT silently fall back
to a legacy Express/Custom architecture.

SS11.5 AUTHORIZES the stripe/stripe-php upgrade: verify the current stable
version at implementation time, keep the dependency change isolated in
this sub-slice, read the official migration notes, run ALL existing
Stripe/payment/usage webhook compatibility suites, and do NOT change
lane-A/lane-D behavior to make tests pass. No broad dependency upgrades.
If a legacy callsite needs a mechanical compatibility adaptation, keep it
behavior-preserving and report it explicitly.

Read SS4 before touching Stripe code: app/Library/Usage/
StripePaymentProviderGateway calls itself "the sole class permitted to
reference a Stripe\* SDK class"; SS4.2 explains why that is lane-D scoping
that cannot bind lane B, and why you build a SECOND, lane-B-owned gateway.
Do not extend, call or implement anything under App\Library\Usage.

Build App\Library\Payments\StripeConnectGateway plus owner-only
connect/disconnect and onboarding, syncing capability flags into
business_stripe_connections. Store NO connected-account secret -- direct
charges use the platform key plus the Stripe-Account header (SS5.7).

Connections are HISTORICAL records (SS5.7): business_id is a plain FK;
uniqueness is unique(stripe_account_id) plus unique(active_business_id);
disconnect makes the row terminal and frees active_business_id; a
different Stripe account creates a NEW row; stripe_account_id is NEVER
rewritten on an existing row; old payments keep their connection id
forever.

No provider network call inside a transaction or under a row lock (SS7).

Tests per SS12.D. Run tests, git diff --check, commit, push. Do NOT create
a PR. Do NOT merge. Report the exact SDK version you upgraded to, how you
verified the Stripe API shape, and every legacy callsite you adapted.
```

### 18.E — Payment schedule execution, PaymentIntents, webhook ingestion

```
You are implementing Sub-slice E of Slice 17, per SS12.E -- the highest-
risk sub-slice in this contract: real customer money, replay safety, lock
ordering and the money-lane boundary all land here. Hard prerequisites:
Sub-slices A, B, C, D merged.

Read SS4, SS7.0, SS7.2, SS7.3, SS8 and SS11 in full before writing code.

Implement SS7.2's PAY START algorithm VERBATIM, in order: begin
transaction; lock the document FIRST; verify the exact current issued
version; lock the exact schedule item; re-check it belongs to
current_version_id; re-check document payability and SS6.3.1's
account/entitlement rechecks; re-check the previous sequence where
applicable; re-check the CURRENT ACTIVE Stripe connection readiness;
inspect the existing active payment attempt; if one exists RETURN/RE-DRIVE
THAT SAME ATTEMPT and never create another; otherwise insert exactly ONE
payment row with status `created`; derive the provider key from that row's
UID; commit; call the provider OUTSIDE the transaction; finalize through
the shared idempotent finalizer.

HOW THE CUSTOMER ACTUALLY PAYS (SS11.8) -- read it before building the
public surface:
- The customer pays via Stripe.js + the Stripe Payment Element, against
  the Business's CONNECTED account in the direct-charge context. Do NOT
  redesign around Checkout Sessions.
- VERIFY the current Stripe.js connected-account initialization API
  against official Stripe docs before writing it (SS11.6). This contract
  fixes the posture, not the option spelling -- do not copy a guessed
  option name from memory.
- This application NEVER collects raw card number/expiry/CVC. Your
  payment-start endpoint's input schema has no card fields at all; a
  request carrying them is rejected. No card data may reach a Laravel
  request body, log, exception, cache, session or the database.
- Two public endpoints only (SS6.3.2): the GET document page renders
  amount due, which schedule item is payable, payment readiness and a Pay
  action, and makes ZERO provider calls and ZERO mutations; the
  payment-start POST is the ONLY thing that may invoke PAY START.
- The payment-start response returns ONLY (SS7.2.1): payment_uid, the
  PaymentIntent client_secret, the connected-account context current
  Stripe.js requires, and the publishable-key/config identity. NEVER the
  platform secret key.
- client_secret is TRANSIENT. It must not be stored in
  business_document_payments or business_payment_events, logged, put in an
  exception message, written to a timeline/audit row, or cached "for
  convenience". Durable identity is uid + provider_payment_intent_id +
  business_stripe_connection_id.
- Re-drive has exactly two shapes (SS7.2.1). Case A: the intent id is
  known -> retrieve/reconcile THAT SAME intent on the row's recorded
  connection and return its client_secret. Case B: the creation result was
  uncertain and the id is unknown -> repeat creation with the SAME
  document-payment:{payment_uid} key so Stripe returns the original
  intent. Never a fresh key, never a second active row.
- The connected-account context is derived SERVER-SIDE from the row's
  business_stripe_connection_id (SS7.2.2). No request parameter may name
  an account; never trust a client-supplied account identifier.
- SCA/3DS belongs to Stripe's client-side confirmation. Your server
  implements no card authentication, and requires_action is NOT a new
  attempt -- same row, same intent.
- Any Stripe return/redirect URL may bring the customer back to the secure
  document route but carries NO authority: arriving there transitions
  nothing. Re-render or re-poll persisted state.
- Map provider statuses to our six local statuses in ONE gateway seam
  (SS11.8's table). No Stripe status string may appear in manager, domain,
  controller or Blade code. An unmapped provider status fails closed.

Provider keys are document-payment:{payment_uid} and
document-refund:{refund_uid}. NEVER an independently guessed ordinal. A
deliberate retry after a terminal failed/canceled attempt creates a NEW
row (and therefore a new key); a retry after an UNCERTAIN network result
re-drives the SAME row and SAME key and must never originate a second
charge because an HTTP response was lost.

SS7.0's canonical lock order is absolute: documents -> versions/schedule
items (ascending id) -> payments (ascending id) -> refunds (ascending id).
Skip tiers you don't need; NEVER reverse them. Webhook finalization may
resolve the payment id unlocked, then must take locks in that order before
mutating.

SS7.3 is a payability gate: a requires_signature document is payable ONLY
when signed; a POST while `sent` is refused. A no-signature invoice is
payable from `sent`. The deposit (sequence 1) must be succeeded before the
balance (sequence 2) is payable. Payment always targets the schedule of
current_version_id -- a superseded version's item is never payable.

Webhook: verify the Connect signature over the raw body BEFORE any insert
(400, zero side effects); duplicate insert caught on SQLSTATE 23000
returns 200 with zero re-processing; the job claims via one atomic
conditional UPDATE with a lease and returns immediately when it claims
nothing; terminal writes guarded WHERE state='processing'; last_error
stores an exception CLASS or reason code, never a message. Cross-check the
event account against the connection RECORDED ON THE LOCAL ROW -- a
webhook for a now-disconnected historical account must still finalize its
own older payment (SS5.7). Refund/dispute events route by event_type, not
metadata. A browser redirect NEVER transitions state.

Dispute events are ingested safely and never mutate document state
(SS11.7). Build no dispute UI or evidence API.

Verify current Stripe docs/API version per SS11.6 rather than overfitting
to an obsolete event shape.

Tests per SS12.E: the whole SS8.3 replay table proved by actual replay;
SS7.2's forced-concurrency set (two first clicks -> one active row and one
provider operation; lost response -> same key; only terminal failure
permits a new row); SS7.0's adversarial deadlock/race set (payment success
vs void, vs resend/revision, two callbacks for one payment, deposit and
balance racing); superseded-version payment refused; SS7.3's ordering
refusals; every fail-closed cross-check; and the SS11.1 source-boundary
test.

Run tests, git diff --check, commit, push. Do NOT create a PR. Do NOT
merge. Return exact replay and concurrency test results, and state
explicitly any race or replay case you could not fully close.
```

### 18.F — Reminders, offer expiration, refunds

```
You are implementing Sub-slice F of Slice 17, per SS12.F. Hard
prerequisites: Sub-slices A, B, C, E merged.

Read SS8.4, SS8.6, SS8.7, SS7.4 and SS5.9's refund rules before coding.

Build THREE SEPARATE scheduled commands -- documents:expire-due,
documents:dispatch-due-reminders and documents:reconcile-stale-payments --
because they select disjoint row sets. Follow
app/Console/Commands/SweepExpiredOpportunitySnoozes.php exactly: domain
logic in the manager; the command owns the config feature-flag no-op
(exact message + self::SUCCESS + zero mutation and zero manager
invocation); strict --limit validation returning self::INVALID on anything
not a positive integer; a BOUNDED batch that never drains to empty;
per-row transaction + lockForUpdate() + re-verify the precondition under
the lock; Throwable per row logged and the loop continues. Register all
three unconditionally in Kernel::schedule() with a comment justifying the
cadence. Add your sweep/reminder/stale-payment keys to the
config/documents.php that Sub-slice A created.

STALE PAYMENTS (SS7.5): documents:reconcile-stale-payments picks up
payment attempts sitting in a non-terminal local status past the
configured threshold. For each, under SS7.0's lock order, RETRIEVE the
authoritative PaymentIntent from Stripe on the row's OWN recorded
connection and resolve it through the SAME shared idempotent finalizer
Sub-slice E built -- with the same amount/currency/account/
app_operation_id cross-checks. This command decides nothing itself: it
asks the provider and hands the answer to the existing finalizer, so it
introduces no second payment lifecycle authority. It must NEVER locally
mark an attempt failed or canceled merely because time passed -- a
customer may complete authentication late, and inventing a terminal state
would free active_schedule_item_id while a real charge was still live.
Only a provider-verified terminal outcome (or a provider-confirmed
cancellation you explicitly request) frees the slot.

EXPIRATION (SS8.6): expires_at is an OFFER expiry. Expire ONLY documents
that are status=sent AND have no signature AND have zero succeeded
payments. A signed proposal never expires from expires_at. An invoice with
any succeeded payment never expires. A deposit-paid document is never
swept and its deposit is never stranded -- the balance stays due per its
schedule due_at. paid/void/expired stay terminal.

REMINDERS: target ONLY the current version's schedule. Idempotency lives
in the MANAGER on durable markers (reminder_last_sent_at/reminder_count on
schedule items; expiry_reminder_last_sent_at/_count on documents), never
in the job -- mirror the low_balance_notified_at precedent. Delivery is
EMAIL to the document's recipient_email_snapshot. No SMS, no wallet.

REFUNDS: issuance does not exist anywhere in this repository today. Build
it per SS7.4 and SS8.7. Admission happens UNDER THE PAYMENT ROW LOCK:
available_refundable = captured_amount - SUM(pending) - SUM(succeeded).
A request above that is refused. Insert one pending row, commit, then call
the provider outside the transaction, against the payment's HISTORICAL
connection/stripe_account_id -- never whatever account is currently
connected. An uncertain response re-drives the same row and key; a
terminal failed refund releases its reserved capacity; a succeeded refund
consumes it.

PARTIAL REFUNDS (SS5.9): the document stays `paid`. A schedule item stays
`paid` while cumulative succeeded refunds are LESS than its captured
amount, and becomes `refunded` ONLY when they equal it. Never mark it
refunded on a first partial refund. Cumulative succeeded refunds never
exceed the captured amount.

Tests per SS12.F, including the expiration set, the partial-refund set,
the forced race proving two simultaneous refunds cannot reserve beyond the
captured amount, and the stale-payment set (an abandoned attempt is
resolved from the provider and never locally invented; it does not block
its schedule item forever; a late-completing authentication still
finalizes correctly). Write BOTH test classes for EACH of the three
commands -- behavior and ReflectionMethod-based schedule registration. Use
the fake gateway; no live network test.

Run tests, git diff --check, commit, push. Do NOT create a PR. Do NOT
merge.
```

### 18.G — Integration hardening and the entitlement flip

```
You are implementing Sub-slice G of Slice 17, per SS12.G. Hard
prerequisites: Sub-slices A-F merged.

Add the nav entry via CustomerMenuBuilder AND add the new feature key to
ENTITLEMENT_GATED_FEATURES -- omitting the second step silently hides the
item forever even when entitled, a lesson already recorded in Contract 16
SS18.E. Remember the nav is presentation only: the real gate is SS6.1's
chain, already carried by every route from the sub-slice that added it.

Register App\Library\Timeline\Sources\DocumentActivitySource implementing
TimelineSource against ContactActivityTimeline::SOURCES_TAG in
AppServiceProvider. Follow AutomationActivitySource exactly: guard that
the subject's contact belongs to the business and return [] rather than
guess; a FIXED number of queries regardless of row count; past-tense
finished sentences ("Proposal sent", "Proposal signed", "Invoice paid");
customer-safe language only, never a raw status code. The Conversations
screen itself does not change.

Surface payment events in the Activity Center and documents in Global
Search, both Location-filtered per Blueprint SS24/SS26 -- neither may
reveal, or hint at the existence of, a document the viewing actor could
not open.

ONLY as the last step, once you have verified the end-to-end path (owner
sends a proposal by email, customer signs and pays via the link, both
parties see it), flip the PlatformFeature registry entry from Planned to
Available. Include a test proving the authorized authenticated path works
once Available and is refused while Planned.

Run tests, git diff --check, commit, push. Do NOT create a PR. Do NOT
merge. Return explicit confirmation of what you verified end-to-end before
flipping the entitlement.
```
