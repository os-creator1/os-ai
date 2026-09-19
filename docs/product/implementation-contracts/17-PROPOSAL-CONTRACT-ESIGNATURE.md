# Implementation Contract 17 — Proposal / Contract / E-Signature / Invoice

**Status:** Planning contract only. Does not authorize implementation.
Written against `main` @ `30ad21c7`. Contracts 1–14 (Workspace/Agency
tenancy migration) and Contract 16 (Packages & Products catalog) are
merged as documents; **Contract 16 is merged as a document only and is not
yet implemented** (§3.2 — verified mechanically: no `catalog_items`,
`package_snapshots`, `app/Library/Catalog/`, or `PackageSnapshot` model
exists on `main`). Seven dependency-ordered sub-slices (§12/§18, A–G)
implement this contract; **no sub-slice below may start without its own
separate, explicit human authorization**, matching this repository's
route-3 governance (`CLAUDE.md`). Two sub-slices additionally carry
decisions this contract deliberately refuses to make on the product
owner's behalf and which must be answered before they ship (§6.5, §11.2,
§15) — a Stripe Connect account-type/liability decision, and the
legal/compliance posture of the e-signature evidence model.

This slice handles **money lane B** (Addendum §12) and nothing else. The
single most important rule in this document is §4: every existing payment
artifact in this repository belongs to lane A or lane D, and **none of it
may be reused**.

## 1. Objective

Build the V1 Payments & Contracts module: a Business authors a Proposal
from its Contract 16 catalog, sends it to an end customer over a secure
non-guessable link, the customer signs it and pays — deposit plus balance
or in full — through **that Business's own connected Stripe account**;
with automated reminders, expiration, refunds, and a full, auditable
document lifecycle. Every transactional document is Location-attributed
and carries immutable Contract 16 package/price snapshots, so a later
catalog change can never alter a document already issued.

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
  bind §8 and §11.4), §37 (the V1 acceptance clause).
- `docs/rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md` **§12** (Money lanes)
  — the hard architectural rule, quoted in full in §3.1; plus §9 and §10,
  referenced in §4 as the lanes this slice must never touch, and §5
  (Location ownership of operational records).
- `docs/product/V1-ACCEPTANCE-MATRIX.md` — the Business Owner row "Sell
  from a catalog, collect signed & paid agreements" and the **End
  Customer / Lead** row "Sign/pay a document", both quoted in §3.1. The
  End Customer row is the only authority that states the public
  permission boundary ("Possession of the secure link") and that the link
  is emailed.
- `docs/product/V1-AUTHORITY-TRACEABILITY-MATRIX.md` **row 15** — "PARTIALLY
  ALIGNED … Invoicing/payment exists; Proposal/Contract/e-signature layer
  is largely net-new". That row is explicitly flagged by its own document
  (lines 57–61) as a *lighter existence-check pass* that "should be
  re-verified with a full read before an implementation slice depends on
  their exact current shape" — §3.2/§4 below is that full read, and it
  materially corrects the row's implication (§3.3).
- `docs/product/implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md`
  — merged. Its §5.3 (`package_snapshots`), §12.D
  (`PackageSnapshotService::snapshot()`), and §15 (non-goals) bind this
  contract directly and are quoted in §3.4.
- `docs/product/V1-IMPLEMENTATION-ROADMAP.md` — Slice 17, XL complexity,
  Medium risk, Wave 2 Lane E, whose only stated dependency is "F/Wave1's
  package snapshots" (i.e. Contract 16 Sub-slice D).

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
Stripe, invoices, or customer revenue. Read together with the End Customer
row's `Costs the Business`, the only coherent reading is: **the
wallet-checked paid side effect is the *delivery* of the document (sending
the link by SMS/email), not the customer's card charge.** This contract
adopts that reading explicitly (§11.3) rather than leaving it inferred.

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

Contract 16 itself is **not implemented**: no `catalog_items`,
`catalog_item_location_overrides`, or `package_snapshots` migration; no
`app/Library/Catalog/` directory; no `CatalogItem` or `PackageSnapshot`
model. This is a hard prerequisite, not a formality (§16).

**No "Signature" match in the codebase relates to human signing.** The
matches are Artisan `$signature` command declarations, PHP method-signature
prose, a payment-gateway `signature_key` config field, an OAuth state
signature, and webhook signature verification (Twilio `RequestValidator`,
Stripe `verifyWebhookSignature()`, Telnyx Ed25519).

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
   Location at all." This
   slice's document Location attribution therefore flows *into* the
   snapshot, not merely alongside it.
2. **`$actor` is nullable specifically for the public/unauthenticated
   flow** — Contract 16 §5.3: "inventing a fake 'system' User account to
   satisfy a `NOT NULL` constraint is not authorized by any document and
   is not done here." That parameter exists for exactly this slice's
   secure-link signer.
3. **Line items are explicitly assigned to this slice** — Contract 16
   §5.3: "if Slice 17 needs to represent 'N units of this catalog item on
   one proposal,' that quantity/line-item concept belongs to Slice 17's
   own schema (an Order/Proposal line item referencing this snapshot's
   `uid`), not to this table." Note: **by `uid`, not `id`** (§5.3).
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
| Write-once immutable row | `website_revisions` — `const UPDATED_AT = null`, `$table->timestamp('created_at')->nullable()` alone, `version_number` + `unique([parent_id, version_number])`, `json` snapshot + `schema_version`; migration docblock: "write-once and immutable … nothing in the implementation may UPDATE a row in this table after insert" | §5.3/§5.5/§5.6 mirror this exactly, proved the same way Contract 16 proves it: a source-boundary test, not a self-referential "we did not call update" assertion. |
| Mutable draft → immutable on state change | `automation_workflow_versions` — mutable while `state = draft`, immutable once it leaves; child node/edge tables have no `updated_at` "so an attempt fails loudly"; plus `definition_revision` optimistic-concurrency counter and generated-column uniqueness guards (`draft_guard`/`published_guard` `storedAs(CASE WHEN state=…)`) | §5.3/§7 — the generated-column guard is how "at most one draft version per document" is enforced in the database, since MySQL has no partial unique index. |
| Webhook idempotency | `payment_provider_events` — `UNIQUE(provider, provider_event_id)`; signature verified over the raw body *before* any insert (400 on failure, zero side effects); duplicate insert caught on SQLSTATE `23000` → `200`; atomic conditional-`UPDATE` claim with lease/attempts; terminal writes guarded `WHERE state='processing'`; `app_operation_id` metadata round-trip cross-check | §8 mirrors the **pattern** in a lane-B-owned table. The table itself is forbidden (§4.4). |
| Idempotent side effect | `UNIQUE(business_id, <key>)` + insert-and-catch `UniqueConstraintViolationException` + scoped read-back (`EloquentBusinessUsageMeasurementRepository::recordOnce()`); deterministic keys derived from durable row identity, never `Str::uuid()` per call (`ManagedDispatchDelegate`: "A random per-call key is not idempotency, it is the appearance of idempotency") | §8.1/§8.4 — every reminder, receipt and provider call in this slice uses a deterministic, durable key derived from a document/payment/refund UUID, stored under a `unique(business_id, local_idempotency_key)` tenant-scoped index. |
| Durable "already notified" marker | `business_usage_wallets.low_balance_notified_at` — owned by the manager, never written by the job | §8.4 — reminder dedupe markers live on the document/schedule row, not in the job. |
| No provider call under a lock | `ManagedMessageDispatcher` — writes the attempt row in its own short committed transaction, calls the adapter **outside** any transaction, finalizes in a second short transaction | §7 adopts this verbatim as a hard rule. |
| Scheduled sweep | `SweepExpiredOpportunitySnoozes` + its two test classes — manager owns the logic, command owns flag-gate/`--limit` validation/`self::INVALID`, bounded batch (never drain-to-empty), per-row transaction + `lockForUpdate()` + re-verify precondition under the lock + audit row with `actor_type = System`, `Throwable` per row logged and loop continues; schedule registered unconditionally with the flag owned by the command | §12.F mirrors this exactly, including the `ReflectionMethod`-based schedule-registration test. |
| Outbound to an end customer | Email: `Notification::route('mail', $email)->notify(...)` from a `Base`-extending job `implements ShouldQueueAfterCommit`, scalar ids only, dispatched **after commit** ("a recipient must never be emailed a claim link for a row that a later failure rolled back"). SMS: `CampaignRepository::checkQuickSendValidation()` then `quickSend()` — "exactly one door … billing is inherited, not built" | §11.3/§12.C. The SMS path requires this slice to pass its **own** durable `managed_operation_key`; the fallback is a content hash that would collapse two legitimately distinct sends into one. |
| Timeline | `TimelineSource` + `ContactActivityTimeline::SOURCES_TAG` — the interface's own docblock names "**invoices**, payments" as intended future sources | §12.G registers a `DocumentActivitySource`; the Conversations screen does not change. |
| Money | Contract 16 `package_snapshots.price_minor_at_snapshot` (`unsignedBigInteger` minor units) + `currency_code_at_snapshot` `char(3)`; `CrmOpportunity.value_minor`/`currency_code` | §5.1 — integer **minor units** throughout, matching Contract 16 exactly (no conversion at the boundary) and matching Stripe's own wire format. Never micro-units (that is lane D's convention, for sub-cent metering this slice does not have). |
| UID safety | `HasUid::generateUid()` mints `uniqid()` — time-ordered, trivially predictable. Newer models override it (`WebsiteRevision`, `AutomationWorkflow`, …); `routes/public.php`'s own comment: "`Business.uid` unsafe: it is generated via `uniqid()`, not a real UUID, despite its column type" | Every model in this slice overrides `generateUid()` to `(string) Str::uuid()`, **and** `uid` alone never authorizes access to a document (§6.3). |

### 3.6 What does not exist and must be decided, not assumed

- **No PDF capability.** No library in `composer.json` or anywhere in
  `composer.lock`. `config/filesystems.php` has three stock disks and the
  `public` disk is symlinked into `public/` — world-readable with no
  authorization check, so a signed document must never land there. §5.6
  resolves this without a new dependency.
- **`stripe/stripe-php: ^7.76`** (`composer.json:73`) is old. Connect
  onboarding/Account Links and current `Refund` shapes may require a major
  bump — a dependency change requiring explicit authorization, gated in
  §12.D.
- **No named e-signature vendor, in any document.** A case-insensitive
  sweep of the entire `docs/` tree returns zero matches for every major
  vendor. §5.5 designs the smallest provider-neutral model and §6.5
  separates what this contract can establish from what it cannot.

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
(§8.1/§8.4); the `app_operation_id` metadata round-trip
cross-check; the "lock the parent row as the idempotency mechanism" receipt
pattern; the currency-exponent knowledge (zero/two/three-decimal currency
lists and Stripe's minor-unit bounds), which currently lives **private**
inside the forbidden `UsageBillingCheckoutManager` and is therefore
re-derived into a **lane-neutral** `App\Library\Money\CurrencyExponent`
value object in §12.A rather than duplicated or reached into.

## 5. Canonical domain model

All money is **integer minor units** (`unsignedBigInteger`) plus a
`char(3)` `currency_code` — matching Contract 16's `package_snapshots`
exactly (so no conversion happens at that boundary) and matching Stripe's
wire format. Never micro-units. Every table is prefixed `business_*`,
deliberately, so lane-B tables are visually distinguishable from lane-D's
`payment_provider_*` at every call site. Every model overrides
`generateUid()` to `(string) Str::uuid()` (§3.5).

### 5.1 Proposal, Contract and Invoice are **one versioned document with stages**

This is the contract's single most consequential modelling decision. **It
is a reasonable default, flagged — not a resolution the authority
documents compel.** The evidence that points this way:

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

Modelling Proposal and Contract as two rows would invent a persisted entity,
a linkage, and a second lifecycle that no authority describes — strictly
more structure than Blueprint §18 authorizes.

**The open question this default answers silently, recorded so it is
visible:** under this model a signed proposal is itself the thing that gets
paid, so nothing ever converts an accepted proposal into a separate
`invoice`-kind document — there is no parent/child linkage, no conversion
action, and no column relating one document to another. Blueprint §9's
"Invoice Sent" pipeline stage is satisfied in V1 by sending an
`invoice`-kind document directly. If the product owner wants a genuine
Proposal → Invoice conversion, that is new product behavior requiring its
own authorization (§15), and it is an additive change: a nullable
`converted_from_document_id` plus a conversion action, with nothing in §5.3
or §5.5 restructured.

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
current_version_id                       FK -> business_document_versions, nullOnDelete, nullable
sent_at / signed_at / paid_at / expired_at / voided_at   timestamps, nullable
expires_at                                  timestamp, nullable
void_reason                                  string(255), nullable
access_token_hash                             string, nullable
access_token_expires_at                        timestamp, nullable
access_token_rotated_at                         timestamp, nullable
last_viewed_at                                   timestamp, nullable        -- flagged default, §10
expiry_reminder_last_sent_at                      timestamp, nullable       -- durable dedupe marker, §8.4
expiry_reminder_count                              unsignedTinyInteger, default 0
created_by_user_id                                  FK -> users, nullOnDelete, nullable
timestamps

index (business_id, status)
index (business_location_id, status)
index (contact_id)
index (crm_opportunity_id)
index (expires_at)            -- the expiration sweep's own driving index
```

`business_location_id` is **NOT NULL** — Blueprint §18 ("every
transactional document carrying Location attribution"), Addendum §5, and
the Acceptance Matrix's Business Owner row (line 31) classifying
transactions as `LB`. It is also the Location
passed to `PackageSnapshotService::snapshot()` (§3.4).

`currency_code` is fixed on the document at creation from the Business's
own `currency_code` and never changes — mirroring
`business_usage_wallets.currency_id`'s "immutable accounting snapshot"
discipline and `CrmOpportunity`'s stated rationale ("so a later currency
change does not silently re-denominate existing deals"). A document whose
line snapshots resolve to a different currency is a refusal, not a
conversion (§7).

`access_token_hash` lives on the document (a single active link, rotated
on re-send) rather than in a separate issuance table. Blueprint §18 says
"a secure, non-guessable link" — singular — and V1 has one recipient per
document. Rotation on re-send **invalidates every previously issued link**,
which is stated to the sender in the UI. *(Alternative considered: a
`business_document_access_tokens` child table supporting multiple
concurrent links and a full issuance audit. Rejected as more structure than
V1 needs; it is a clean additive migration later if multi-recipient
sending is ever authorized.)*

### 5.3 `business_document_versions` — the immutability boundary

```
id
uid                        uuid, unique
business_document_id        FK -> business_documents, cascadeOnDelete, NOT NULL
version_number               unsignedInteger
state                         string(16): draft | issued | superseded
content                        json            -- rendered body/terms, denormalized
content_hash                    char(64)       -- sha256 over canonical serialization of `content` + line items + frozen schedule terms (§5.3.1)
subtotal_minor                   unsignedBigInteger
total_minor                       unsignedBigInteger
currency_code                      char(3)
schema_version                      unsignedSmallInteger, default 1
issued_at                            timestamp, nullable
created_by_user_id                    FK -> users, nullOnDelete, nullable
created_at                             timestamp only        -- const UPDATED_AT = null once issued
draft_guard        unsignedBigInteger nullable  storedAs("CASE WHEN state='draft' THEN business_document_id ELSE NULL END")

unique (business_document_id, version_number)
unique (draft_guard)          -- at most ONE draft version per document, enforced by the DB
index (business_document_id, state)
```

**What becomes immutable, and exactly when.** Editing a `draft` document
mutates its single `draft` version in place (`definition_revision`-style
optimistic concurrency is unnecessary here; the draft guard plus §7's row
lock is sufficient). **At send, the draft version transitions to `issued`
and becomes write-once forever**: its `content`, its line items, its
`content_hash` and its totals can never change. A subsequent edit of an
already-sent document creates a **new draft version** (`version_number + 1`);
sending again issues it and marks the prior version `superseded` — and
because the token rotates (§5.2), the customer's old link stops working
rather than silently showing stale terms.

The `draft_guard` generated column is the `automation_workflow_versions`
technique (§3.5): MySQL has no partial unique index, so "at most one draft
per document" is enforced by a stored generated column plus a plain unique
key, not by application discipline alone.

#### 5.3.1 The three immutability boundaries, stated exhaustively

Blueprint §18 requires immutable package/price snapshots and a tracked
lifecycle; it does not say what else freezes when. This contract states it
once, here, so no implementer has to infer it:

**At send (`draft` version → `issued`)** the following become write-once
and may never be updated by any code path afterwards:
- the version's `content`, `content_hash`, `subtotal_minor`, `total_minor`,
  `currency_code` and `schema_version`;
- every `business_document_line_items` row of that version, including each
  `package_snapshot_uid` (the Contract 16 snapshot itself is already
  write-once by Contract 16 §5.3);
- every `business_document_payment_schedule_items` row's commercial terms —
  `sequence`, `kind`, `amount_minor`, `currency_code` and `due_at`. **The
  schedule is part of what was agreed, so it freezes with the version**;
  only its own progress fields (`status`, `paid_at`, `reminder_last_sent_at`,
  `reminder_count`) remain mutable afterwards. `content_hash` is computed
  over the canonical serialization of `content` + line items + the frozen
  schedule terms, so a post-issue schedule mutation is detectable, not
  merely forbidden by convention;
- the document's own identity fields: `business_id`,
  `business_location_id`, `contact_id`, `kind` and `currency_code` — the
  identity the snapshot and any later signature are taken against.

**At sign** nothing further freezes, because everything the signer saw was
already frozen at send — that is the entire reason the freeze happens at
send rather than at sign. What sign adds is the evidence binding
(§5.5): `business_document_version_id` + `signed_content_hash` pin the
signature to one exact issued version. After a signature exists, the
document may no longer be revised at all: §7 refuses a new draft version on
a `signed` document (a signed agreement is renegotiated by voiding and
issuing a new document, not by superseding the version someone signed).

**At pay** nothing further freezes on the document or version; a payment
only advances schedule-item progress and, when every item settles, the
document's `status`. A terminal document never moves backward (§8.3).

A post-issue mutation of any frozen field is a defect, and §12.C/§12.E
carry the tests that prove each one is refused — including specifically
that a schedule item's `amount_minor` cannot be changed after a signature
exists.

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
may contain lines that do not. Without custom lines a standalone invoice could only ever bill
catalog items, which would make the `invoice` kind close to unusable. A
custom line is a **new** line with its own price and **never** a
modification of a catalog item's snapshotted price — that distinction is
what keeps it clear of Contract 16 §15's forbidden
negotiated-price/discount system. *If the product owner prefers
catalog-only documents in V1, deleting the `custom` enum value and the
`source` column is a one-line change to §12.B.*

**No discount, tax, or installment-plan modelling.** Discounts are
forbidden by Contract 16 §15. Tax is mentioned in no authority document
and is not invented. Installments beyond deposit+balance are explicitly
V2 (Blueprint §34).

### 5.5 `business_document_signatures` — technical signing evidence

Write-once. One row per completed signature; V1 has exactly one signer per
document.

```
id
uid                              uuid, unique
business_document_id              FK -> business_documents, restrictOnDelete, NOT NULL
business_document_version_id       FK -> business_document_versions, restrictOnDelete, NOT NULL
signed_content_hash                 char(64)      -- copy of the version's content_hash as displayed
signer_name                          string(160)
signer_email                          string(255)
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
row alone. A signature attached to mutable content would be worthless,
which is why §5.3 freezes the version at send rather than at sign.

`consent_statement` is stored **verbatim**, not by reference to a template
that could later change — same reasoning.

### 5.6 Rendered artifact strategy — no PDF, no new dependency

No PDF library exists (§3.6), the `public` disk is world-readable, and
**Blueprint never says "PDF"** — it says the customer accesses the document
"via a secure, non-guessable link". V1 therefore renders the document as a
Blade page from the **issued version's frozen `content` + line items**,
exactly as `Public\WebsiteController` renders from a `WebsiteRevision`
snapshot. Nothing is generated, stored, or served as a file.

Adding a PDF renderer is a new composer dependency and a new private
storage disk — an explicit authorization decision (§15), not an
implementation detail. If it is ever authorized, it renders **from the
frozen version**, never from live data, and is served through an
authorization-checking controller on `Storage::disk('local')` (the
`PlatformThemeFontController` precedent), never the public disk.

### 5.7 `business_stripe_connections` — one per Business

```
id
uid                            uuid, unique
business_id                     FK -> businesses, restrictOnDelete, NOT NULL
stripe_account_id                string(64)          -- acct_...
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

unique (business_id)              -- Addendum §12: "1 Business = 1 connected Stripe account in V1"
unique (stripe_account_id)
```

The `unique(business_id)` constraint is the database-level expression of
Addendum §12's closing sentence. Per-Location Stripe accounts are
explicitly V2 (Blueprint §34).

**No secret key is ever stored.** Direct charges on a connected account are
made with the *platform's* API key plus the `Stripe-Account` header
naming `stripe_account_id`; the connected account's own credentials never
exist in this system. This is a material difference from
`business_google_connections` (which stores an encrypted refresh token) and
is stated here so no implementer copies that shape reflexively.

**Charge type: direct charges on the connected account.** Addendum §12
lane B is "the Business's end customer → **that** Business's connected
Stripe account" — the money must never transit the platform's balance. No
authority document authorizes an `application_fee_amount`, so none is ever
set (§15).

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

### 5.9 Payment schedule, payments and refunds

```
business_document_payment_schedule_items
  id
  uid                            uuid, unique
  business_document_id            FK -> business_documents, cascadeOnDelete, NOT NULL
  sequence                         unsignedTinyInteger      -- 1 or 2
  kind                              string(16): full | deposit | balance
  amount_minor                       unsignedBigInteger        -- frozen at issue (§5.3.1)
  currency_code                       char(3)                  -- frozen at issue (§5.3.1)
  due_at                               timestamp, nullable     -- frozen at issue (§5.3.1)
  status                                string(16): pending | paid | refunded | void
  paid_at                                timestamp, nullable
  reminder_last_sent_at                   timestamp, nullable   -- durable dedupe marker (§8.4)
  reminder_count                           unsignedTinyInteger, default 0
  timestamps

  unique (business_document_id, sequence)
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
  status                                     string(24): requires_payment | processing | succeeded | failed | canceled
  failure_code                                string(64), nullable
  succeeded_at                                 timestamp, nullable
  receipt_sent_at                               timestamp, nullable   -- durable receipt dedupe marker (§8.4)
  timestamps

  unique (business_id, local_idempotency_key)
  unique (provider_payment_intent_id)       -- unique when populated: replay can never create a second payment
  unique (provider_charge_id)
  index (business_document_id, status)

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
```

**Deposit + balance, and nothing more.** Blueprint §34 puts "Deposit +
balance (§18)" in V1 and "Complex installment plans" in V2. A document
therefore has **either** one `full` item **or** exactly two items
(`deposit` then `balance`) — enforced by application validation plus the
`unique(business_document_id, sequence)` key and a `sequence ∈ {1,2}`
check.

**"Partial payment" means one schedule item settled and the other
outstanding — never an arbitrary part-amount against a single item.** A
Stripe PaymentIntent is created for a schedule item's exact amount; there
is no under-payment path, and an amount mismatch on an inbound event is a
fail-closed refusal (§8.3), never a silent acceptance.

**The schedule must account for the whole document.** The schedule items'
`amount_minor` values must sum **exactly** to the issued version's
`total_minor`, and every item's `currency_code` must equal the document's.
This is checked as a send precondition (§7) and re-checked before any
PaymentIntent, so a document can never reach `paid` having collected less
than the agreed total.

**Payment progress is a separate axis from document lifecycle**, derived
from the schedule items rather than stored as extra `status` values. This
is not an invention: Blueprint §9 states that an Opportunity carries its
value and its "**payment state** as two independent facts". It also avoids
adding a `partially_paid` state that Blueprint §18's six-state lifecycle
does not contain. `documents.status` becomes `paid` only when **every**
schedule item is `paid`.

**Receipt.** A "receipt" in this contract is exactly one thing: a
payment-succeeded notification sent to the paying customer, dispatched from
the `DocumentPaymentSucceeded` event and deduplicated by the durable
`business_document_payments.receipt_sent_at` marker (§8.4). It is **not** a
stored document, not a PDF, and not a mirror of a Stripe-hosted receipt URL
(that is lane D's `business_billing_receipts`, forbidden by §4.3). This
definition exists because §8.3, §12.E and §14 all require "no duplicate
receipt" and would otherwise be requiring a test for an artifact the
contract never authorized anyone to build.

## 6. Authority / security contract

### 6.1 Internal (Business-side) authorization

Two axes, exactly as Blueprint §26 and the Acceptance Matrix's Business
Owner "Sell from a catalog…" row (line 31) define them, and exactly as
Contract 16 §6 implemented them:

- **One feature-level permission** for Payments & Contracts. Per Contract
  16 §15's precedent, this contract does **not** invent a CRUD matrix of
  separate capability keys (create vs. send vs. void vs. refund). *One
  exception, deliberate:* **issuing a refund** is gated to the Workspace
  owner or a staff member holding the same single feature permission
  **plus** an explicit confirmation step — a refund moves real money out
  of the Business's account, and a UI confirmation is a presentation
  detail this contract may set as a reasonable default (Addendum §19).
- **Location ACL** via `LocationAccessGuard::assertUserCanAccessLocation()`,
  called fresh on every read and write of a document, using the document's
  own `business_location_id`. Never a route-bound model, never a cached
  scope (Addendum §4: "Knowing or binding a record ID **MUST NEVER** bypass
  Location authorization").

**Connecting or disconnecting the Business's Stripe account (§5.7):
owner-only — a flagged reasonable default, not a compelled rule.** No
authority document sets the permission boundary for connecting a
Business's own Stripe account; the Acceptance Matrix's stated boundary for
this whole module is "Owner + staff per feature permission", and §6.1's
own first bullet commits this contract to not inventing capability keys
beyond that one permission. Narrowing connect/disconnect below that stated
boundary is therefore a proposal, reasoned by analogy to Addendum §10's
principle that financial consent belongs to an owner rather than to staff.
**The product owner may instead place it behind the single feature
permission**; that is a one-line change to §12.D.

### 6.2 The end customer has no account and no authorization

Per the Acceptance Matrix's End Customer row, the permission boundary is
**"Possession of the secure link"** — no account, no authentication. The
public surface is therefore strictly limited to: view the document, sign
it, pay it. It exposes no list, no search, no other document, and no
Business data beyond the document's own frozen content.

### 6.3 The secure link

Mirrors `client_workspace_invitations` exactly (§3.5):

- Route shape `GET documents/{uid}/{token}` — `{uid}` locates the row,
  `{token}` authenticates it. The split exists **because the token is
  hashed and therefore cannot be a lookup key**.
- `Str::random(64)` plaintext, existing only in the delivered link;
  `Hash::make()` stored in `access_token_hash`; verified only via
  `Hash::check()`. The plaintext is never stored, never logged, and never
  recoverable.
- `access_token_expires_at` = the document's own `expires_at` when one is
  set (the document's expiry always wins — a link must never outlive the
  document it opens), otherwise `now()` plus
  `config('documents.link_ttl_days')`. `config/documents.php` is created in
  Sub-slice A (§12.A) so Sub-slice C can read it without going outside its
  own allowlist.
- Rotation on re-send (§5.2); revocation by voiding the document or
  clearing the hash — both take effect immediately.
- **One uniform refusal** for every failure reason (expired, revoked,
  wrong token, unknown uid, voided document): one generic "this link is no
  longer valid" response, never disclosing which — the
  `ClientInvitationManager` discipline, which exists specifically to
  prevent existence disclosure.
- Three deltas this slice adds over that precedent, because a signing/
  paying link is a far higher-value target than an invitation:
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

The `GET` is safe, side-effect-free and revisitable (it may record
`last_viewed_at` — a flagged default, §10 — which is not a state
transition). Signing and paying are separate `POST`s that re-validate
everything under their own lock.

### 6.4 Entitlement

`PlatformFeature` has **no** case for this module today (`AgencyPackageCapabilities`
is an unrelated Agency-tier gate). Sub-slice A adds one — inert, `Planned` —
together with the **new** backfill migration for
`platform_feature_usage_classifications` that this repository's own stated
discipline requires ("adding this case necessarily creates the row on any
fresh migrate, and merged migrations are not edited"), and a
`workspace_plan_features` seed row for **all three tiers** (Blueprint §21
line 434: Core, Growth and Agency all carry Payments & Contracts). The
`Planned → Available` flip happens only in Sub-slice G, once the flow
genuinely works end to end.

### 6.5 E-signature: what this contract establishes, and what it does not

**Separating these two is a requirement of this contract, not a caveat.**

*Technical signing evidence (established here, §5.5):* the exact immutable
version displayed, a sha256 of that content, the verbatim consent statement
and its hash, the signer's typed name, their stated name and email, IP,
user agent, and a server-side timestamp — all written once and never
mutated, bound to a link whose possession was the access control.

*Legal and compliance posture (NOT established here):* whether that
evidence satisfies ESIGN, UETA, eIDAS or any other regime; what identity
assurance is required; retention duration; whether a countersignature,
certificate, or tamper-evident sealed artifact is needed; and what the
document's own terms must say about electronic execution. **No authority
document in this repository addresses any of it, and no e-signature vendor
is named anywhere in `docs/`.** Addendum §19 permits implementation agents
to pick reasonable defaults for "minor UX, copy, filter, and presentation
details" — a legal-validity posture is not one of those.

This contract therefore builds a **provider-neutral, first-party evidence
model that is technically sufficient for Blueprint §18's stated capability
and makes no legal claim**, and records that the product owner must decide
the compliance posture before Sub-slice C ships.

**What that decision costs, honestly.** A *vendor* outcome needs no
restructuring: `signature_method` gains a value and the provider's own
reference is added alongside. A **countersignature** outcome does:
§5.5's `unique(business_document_id)` and §7's sign precondition both
assume exactly one signer, and both would have to change. That is the one
open item in this list carrying a schema cost, and it is named here so the
cost is visible when the decision is made rather than discovered during
Sub-slice C.

## 7. Transaction / concurrency boundary

**Hard rule, adopted verbatim from `ManagedMessageDispatcher`: no provider
network call ever happens inside a database transaction or while any row
lock is held.** Every provider interaction is a three-step sequence — write
the local intent row in its own short committed transaction, call Stripe
outside any transaction, finalize in a second short transaction. There is
no case in this slice where holding a lock across a network call is
justified.

Locked sequences, each in its own short transaction:

- **Editing the open draft version** — `lockForUpdate()` the document,
  verify it has a `draft` version (document `status ∈ {draft, sent}`),
  mutate that version, its lines and its schedule items, commit.
- **Revising an already-sent document** — lock the document, verify
  `status = sent` (a `signed`, `paid`, `expired` or `void` document is
  refused: a signed agreement is renegotiated by voiding and issuing a new
  document, never by superseding the version someone signed), verify no
  signature row exists, create a new `draft` version at
  `version_number + 1` — the `draft_guard` unique column (§5.3) makes a
  concurrent second attempt lose at the database — copying the current
  issued version's lines and schedule as the starting point, commit. The
  document stays `sent` and the existing link keeps working until the new
  version is actually sent, at which point the token rotates.
- **Send** — lock the document, verify `status ∈ {draft, sent}`; verify a
  `draft` version exists with at least one line and a resolvable total;
  verify the schedule is valid (§5.9: exactly one `full` item, or exactly
  two, `deposit` then `balance`) **and that the schedule items'
  `amount_minor` sum equals the version's `total_minor` and every item's
  `currency_code` equals the document's**; transition the draft version to
  `issued` (and any prior `issued` version to `superseded`), set
  `current_version_id`, freeze everything §5.3.1 lists as frozen at send,
  generate and hash the token (rotating any prior one), set `status = sent`
  and `sent_at`, commit. **Only then**, after commit, dispatch the delivery
  job — the `ClientInvitationManager` rule: "a recipient must never be
  emailed a claim link for a row that a later failure inside the
  transaction rolled back."
- **Sign** — lock the document, re-verify `status = sent`, not expired, not
  void, `requires_signature`, and that no signature row exists; insert the
  signature bound to `current_version_id`; set `status = signed`,
  `signed_at`; commit. The `unique(business_document_id)` key on the
  signature table makes a double-submit impossible even under a race.
- **Payment finalization** — lock the payment row, apply the terminal state
  only from a non-terminal state, update the schedule item, recompute
  whether every item is paid, and only then move the document to `paid`.
- **Refund** — lock the payment, verify `succeeded`, verify the requested
  amount does not exceed the payment's amount less refunds already
  succeeded, insert a `pending` refund row with its deterministic key,
  commit, then call Stripe.
- **Void (cancellation)** — lock the document; permitted from `draft`,
  `sent` and `signed`, **refused** from `paid`, `expired` and `void`
  (terminal states never move, §8.3). **Refused outright while any
  `business_document_payments` row for the document is `succeeded` with an
  unrefunded balance** — money already captured must be refunded first, so
  that voiding can never be a way to abandon a settled obligation without a
  refund record. On success: set `status = void`, `voided_at`,
  `void_reason`; set every `pending` schedule item to `void`; clear
  `access_token_hash` so the customer's link stops working immediately
  (§6.3); commit; emit `DocumentVoided` after commit. A void is terminal —
  a voided document is never revived, only superseded by issuing a new
  document.

**Currency coherence.** A line whose snapshot resolves to a currency other
than the document's frozen `currency_code` is a refusal at add-time, not a
conversion — there is no FX in this slice and none is authorized.

**Concurrent edit of a draft** is resolved by the lock plus the
`draft_guard` unique column; a second concurrent attempt to create a draft
version loses at the database, not in application logic.

## 8. Provider integration, idempotency and replay safety

### 8.1 Outbound calls are idempotent by construction

Every Stripe call carries an `idempotency_key` derived from a **durable
local identity**, never `Str::uuid()` per call — the rule
`ManagedDispatchDelegate` states plainly: "A random per-call key is not
idempotency, it is the appearance of idempotency." Keys:

- PaymentIntent: `doc:{document_uid}:item:{sequence}:attempt:{ordinal}` —
  persisted in `business_document_payments.local_idempotency_key`
  (`unique`), and round-tripped through Stripe `metadata.app_operation_id`.
- Refund: `payment:{payment_uid}:refund:{ordinal}`, persisted in
  `business_document_refunds.local_idempotency_key` (`unique`).
- The `:attempt:{ordinal}` suffix exists so a *deliberate* retry is a
  genuine second provider attempt rather than silently returning the first
  attempt's result — the `UsageBillingCheckoutManager` precedent.

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
   message (the lane-D precedent, which avoids leaking provider detail
   into a durable row).

### 8.3 What a replay must never do — and the mechanism that prevents each

| Replay must not | Prevented by |
|---|---|
| Duplicate a payment | `unique(provider_payment_intent_id)` on `business_document_payments`; an event for an already-recorded intent updates that row or is ignored, never inserts |
| Duplicate a state transition | Terminal writes guarded by the current state; a transition whose precondition no longer holds is recorded as ignored, never re-applied |
| Duplicate a refund | `unique(provider_refund_id)` plus `unique(local_idempotency_key)` on `business_document_refunds` |
| Duplicate a reminder or receipt | The durable marker on the owning row (`reminder_last_sent_at`, `reminder_count`) — owned by the manager, never written by the job (§8.4) |
| Move a terminal document backward | `paid`, `void` and `expired` accept **no** inbound transition. A late or replayed event against a terminal document is recorded `ignored` with a reason code. A refund never moves a document out of `paid`; it is recorded on the payment and surfaced as refund state |

**Cross-checks before any mutation**, mirroring lane D's own list: the
event's `account` must match the resolved connection; `metadata.app_operation_id`
must equal the persisted `local_idempotency_key`; the provider object id,
amount and currency must match the local row. Any mismatch is a
fail-closed `failed` disposition with a reason code
(`operation_id_mismatch`, `amount_mismatch`, `currency_mismatch`,
`account_mismatch`, `no_matching_local_record`), never a best-effort
guess.

**Refund and dispute events are routed by `event_type`, not by metadata** —
lane D discovered and documented the underlying Stripe behaviour: "a
refund/dispute/refund-object event never carries this app's own
`app_subject_kind` metadata (Charge/Dispute/Refund metadata is
independent, never inherited from the originating PaymentIntent)".
Resolution is by provider reference (`provider_charge_id` /
`provider_payment_intent_id`), and an ambiguous resolution is
`cross_reference_ambiguity`, fail-closed.

### 8.4 Reminder idempotency

Reminders and receipts are **not** made idempotent by the job. The durable
markers live on the rows themselves — `business_document_payment_schedule_items`
(`reminder_last_sent_at`, `reminder_count`) for payment reminders,
`business_documents` (`expiry_reminder_last_sent_at`,
`expiry_reminder_count`) for expiry warnings, and
`business_document_payments.receipt_sent_at` for the payment receipt
(§5.9) — each written by the manager inside the same locked transaction
that selects the row, following the `low_balance_notified_at` precedent
("the manager owns the durable marker and the dispatch decision; the job
never writes the table"). The send itself additionally carries a
deterministic `managed_operation_key` of the form
`document:{uid}:reminder:{n}`, because the `quickSend()` fallback key is a
content hash that would silently collapse two legitimately distinct
reminders into one.

**On key scoping.** The keys in §8.1 and here are derived from a document,
payment or refund **UUID**, so they are globally unique by construction and
need no tenant prefix to avoid the cross-tenant collision §5.8 warns about.
Tenant scoping is nonetheless applied where the key is *stored* —
`unique(business_id, local_idempotency_key)` on both
`business_document_payments` and `business_document_refunds` (§5.9) — so a
caller-supplied key can never reach across Businesses even if a future
caller derives one less carefully.

### 8.5 Provider truth vs local truth

Stated explicitly, because conflating them is the classic failure mode:

- **Stripe is authoritative** for: whether a charge succeeded, the charge/
  intent/refund identifiers, the settled amount and currency, and the
  connected account's capability flags (`charges_enabled`, `payouts_enabled`).
- **This system is authoritative** for: the document, its versions, lines
  and totals; the signature and its evidence; the payment *schedule*; which
  schedule item a payment belongs to; document lifecycle state; and every
  Location/Contact/permission attribution.
- **Local state changes only on a verified inbound event or a
  direct, verified API response** — never on a browser redirect. The legacy
  `PaymentController` confirms payment by reading
  `Session::get('session_id')` after a redirect; that is exactly the
  pattern this slice must not reproduce (§4.1). A post-payment redirect may
  render an optimistic "thank you" page, but it is never the source of a
  state transition.

## 9. Backwards compatibility

Not applicable in the retire-an-old-model sense — pure net-new addition.
Two explicit non-interactions: the legacy `invoices`/`plans`/`payment_methods`/
`PaymentController` stack (lane A) and the entire `App\Library\Usage`
namespace and its tables (lane D) are neither modified nor read (§4, §15).

## 10. Events / audit

Unlike Contract 16 — where no document required an event and none was
invented — **Blueprint §13 (line 321) names a "proposal-sent follow-up" as
a starter automation**, and Blueprint §31 forbids a module reaching into
another module's tables where a canonical event exists. Events are
therefore required here:

Each event is listed with the authority it derives from, so no reader has
to take the set on trust — numeric ids and scalar fields only, no PII in
the payload, matching this codebase's existing event-payload convention:

| Event | Emitted in | Derives from |
|---|---|---|
| `DocumentSent` | §12.C | Blueprint §13's "proposal-sent follow-up" starter automation — the one event an authority document explicitly requires |
| `DocumentSigned` | §12.C | Blueprint §18 lifecycle transition (`sent → signed`) |
| `DocumentPaymentSucceeded` | §12.E | Blueprint §24 "payment events" reach the Activity Center |
| `DocumentFullyPaid` | §12.E | Blueprint §18 lifecycle transition (`signed/sent → paid`) |
| `DocumentExpired` | §12.F | Blueprint §18 lifecycle transition (`→ expired`) |
| `DocumentVoided` | §12.B | Blueprint §18 lifecycle transition (`→ void`) |
| `DocumentRefunded` | §12.F | Blueprint §24 "payment events"; Blueprint §18 names refunds |
| `DocumentViewed` | §12.C | **Flagged reasonable default — derives from nothing.** "Viewed" is not in Blueprint §18's lifecycle and is not a payment event; end-customer view tracking is product behavior no authority document describes. It is included because a sender needs to know whether a document was opened before chasing it, and it is the cheapest possible form of that (one timestamp, no separate table). *Removal path: drop this event and `business_documents.last_viewed_at` (§5.2); nothing else depends on either.* |

**Emitting these events is in scope (Sub-slices B, C, E and F, per the
table above). Wiring them into the Automations *builder vocabulary* is
not** — `tests/Feature/Automations/Workflow/Builder/NoUnsupportedVocabularyTest.php`
asserts the builder's JS and Blade files contain none of `booking`,
`appointment`, `payment_received`, `invoice`, `quote`, `crm_stage`,
`webhook_action`, `ai_node`, `tag_added`, `email_to_contact` as
case-insensitive substrings. Note precisely what that means for this
slice: **`invoice`, `quote` and `payment_received` are forbidden;
`proposal` is not on either list.** Exposing any document trigger whose
builder vocabulary uses those words is therefore a deliberate change to
the Automations domain's own guardrail test, and belongs to a separately
authorized follow-on (§15).

Audit: document lifecycle transitions are auditable from the versions,
signature, payments, refunds and event rows — every one of which is
write-once or append-only. No separate history table is added ("every table
must have a purpose").

## 11. Billing/provider safety

### 11.1 Lane discipline is a test obligation, not just prose

§4's forbidden list is enforced by a **source-boundary test** (§13). A
prose rule that nothing checks is a rule that erodes, so both sides of the
test are enumerated here rather than left to an implementer's judgement.

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

### 11.2 The connected-account decision this contract will not make

Stripe Connect offers materially different account types (commonly
Standard, Express and Custom) that differ in **who bears liability for
disputes and negative balances, who provides support, who owns the
onboarding and dashboard experience, and what the platform's own
obligations are**. No authority document in this repository names one, and
the choice is a commercial/compliance decision with real financial
consequence — precisely the category Addendum §19's "reasonable defaults"
latitude does *not* cover, and the category the Roadmap elsewhere calls a
decision that "must never be invented by an implementation agent."

**Sub-slice D does not ship until the product owner states the account
type and the dispute/negative-balance liability posture.** The schema in
§5.7 is deliberately account-type-agnostic so the decision changes the
onboarding flow, not the data model.

### 11.3 The wallet touches this slice in exactly one place

Sending a document or a reminder **by SMS** goes through the one sanctioned
door (`CampaignRepository::checkQuickSendValidation()` → `quickSend()`) and
is therefore *measured* under lane D's existing rules — a
`business_usage_measurements` row, idempotent on this slice's own operation
key; no reservation, no ledger entry, no wallet debit under current
configuration. That is the whole of the `Paid? = Yes` obligation on
the Acceptance Matrix's Business Owner row (line 31, §3.1), and it applies
to **delivery**, never to
the customer's card charge. Email delivery touches the wallet not at all.

**No other wallet, payer, entitlement-charge or AgencyRebill interaction
exists anywhere in this slice.**

### 11.4 Connected-account readiness is rechecked, never assumed

Before creating any PaymentIntent, the connection must be re-read and
`status = active` with `charges_enabled = true`. A document may be sent
before Stripe is connected (it can still be signed); it simply cannot be
paid, and the public page says so plainly rather than failing at the
moment the customer tries to pay.

## 12. Exact implementation allowlist — seven dependency-ordered sub-slices

Seven rather than the six sketched in the task brief, for one
evidence-driven reason: **Stripe Connect onboarding (D) is separated from
charging (E)**. Onboarding carries its own SDK-version gate (§3.6) and its
own unresolved commercial decision (§11.2), and it can ship, be verified,
and be reviewed entirely on its own; fusing it with the charge path would
put the contract's single riskiest surface behind an unrelated blocker.

### Sub-slice A — Schema/domain foundation + money value object + inert entitlement identity

- **Files/domains**: migrations for all nine tables (§5.2–§5.9); Eloquent
  models with casts/relations only; the lane-neutral
  `App\Library\Money\CurrencyExponent` value object (§4.6) carrying the
  zero/two/three-decimal currency lists, the minor-unit bounds check, and a
  **fail-closed** refusal for an unlisted currency code; the new inert
  `PlatformFeature` case + `Planned` registry entry + the new
  `platform_feature_usage_classifications` backfill migration + the
  `workspace_plan_features` seed row for all three tiers (§6.4); and
  `config/documents.php`, shaped like `config/opportunity.php` (`enabled`,
  `queue`, `link_ttl_days`, sweep limits and reminder offsets) — created
  here, not in §12.F, because §12.C reads `link_ttl_days` two sub-slices
  earlier (§6.3).
- **Prerequisites**: Contracts 1–14 merged. **Not** Contract 16 — this
  sub-slice's schema references `package_snapshots` only by `uid` (a plain
  `uuid` column, no FK), so it can land independently.
- **Schema**: all nine tables, §5.2–§5.9, in full.
- **Tenancy/security**: none exposed at this layer.
- **Concurrency**: none; but every constraint §7 and §8 depend on
  (`draft_guard`, all `unique` keys) must exist here so no later sub-slice
  needs a schema-altering migration for a correctness reason.
- **Tests**: migration/constraint existence including every `unique` and
  the generated `draft_guard`; `const UPDATED_AT = null` on every
  write-once model; `generateUid()` returns a real UUIDv4 on every model;
  `CurrencyExponent` unit tests including the unlisted-currency refusal.
- **Risk**: Low. **Model**: Sonnet 5 sufficient.

### Sub-slice B — Document authoring (draft) + Contract 16 snapshot consumption

- **Files/domains**: `App\Library\Documents\DocumentManager` — create,
  edit the open draft version, add/remove/reorder lines, compute totals,
  validate the schedule (§5.9), and **void** (§7's Void sequence, emitting
  `DocumentVoided`); calls `PackageSnapshotService::snapshot()` for
  every catalog line (§3.4) with the document's own `business_location_id`
  and the acting `User`; `App\Events\DocumentVoided`; authenticated
  controllers/routes/Blade for the document list and editor. **No sending,
  no public surface, no payment.**
- **Prerequisites**: A (hard); **Contract 16 Sub-slices A, B, C and D
  merged and implemented** (hard). Note this is stricter than the Roadmap's
  one-line "needs package snapshots": Contract 16 §12.D's own prerequisites
  are A, B and C, and this sub-slice's `$explicitPriceMinor` test depends
  on Contract 16 Sub-slice C's pricing resolver specifically.
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: §6.1 — the one feature permission plus
  `LocationAccessGuard` on every read and write.
- **Concurrency**: §7's draft-edit lock and the `draft_guard`.
- **Tests**: authoring CRUD × Location-ACL boundary (ungranted Location →
  404); a catalog line produces exactly one `package_snapshot` with the
  document's Location and the correct actor; a later catalog price change
  provably does not alter an existing line (the direct proof of Blueprint
  §17); `$explicitPriceMinor` is passed **only** for a quote-only item and
  refused otherwise (Contract 16 §6/§15); schedule validation accepts
  `full` or exactly `deposit + balance` and rejects anything else;
  **the schedule-sum rule — a schedule whose amounts do not sum to the
  version total, or whose currency differs from the document's, is
  refused** (§5.9, §7); currency coherence refusal; **void permitted from
  `draft`/`sent`/`signed`, refused from `paid`/`expired`/`void`, refused
  while an unrefunded succeeded payment exists, and a void clears the
  access token and voids pending schedule items** (§7).
- **Risk**: Medium. **Model**: Sonnet 5 sufficient.

### Sub-slice C — Secure send, public view, and e-signature

- **Files/domains**: token generation/rotation/verification in the manager;
  the send transaction and post-commit delivery job (email via
  `Notification::route('mail', …)`, SMS via the single sanctioned
  `quickSend()` door with this slice's own `managed_operation_key`); the
  public controller and Blade page rendering the **frozen issued version**;
  the signing `POST` and `business_document_signatures` write; **the
  revise-a-sent-document path (§7's "Revising an already-sent document"
  sequence — creating version N+1 on a `sent` document), which lives here
  rather than in B because it only becomes reachable once sending exists**;
  the `DocumentSent` / `DocumentViewed` / `DocumentSigned` events.
- **Prerequisites**: A, B (hard). **Gate: §6.5's legal/compliance posture
  decision must be answered before this ships.**
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: the whole of §6.2 and §6.3, including the three
  deltas over the `client_workspace_invitations` precedent and the uniform
  refusal.
- **Concurrency**: §7's send and sign sequences; delivery dispatched only
  after commit.
- **Tests**: adversarial public-surface tests — wrong token, expired
  token, rotated (old) token, voided document, unknown uid, and a valid
  token for a *different* document each produce the **byte-identical**
  refusal; the plaintext token never appears in any stored row or log;
  `throttle:` is enforced; an unknown uid is 404 not 500; signing twice is
  impossible (unique key) and the second attempt is a clean refusal; the
  signature binds the exact version and content hash; editing a sent
  document creates a new version, supersedes the old, rotates the token,
  and the old link stops working; **revising a `signed` document is
  refused** (§7); **a schedule item's `amount_minor` cannot be changed once
  its version is issued, and specifically not after a signature exists**
  (§5.3.1); the issued version is immutable (a source-boundary test, per
  Contract 16 §12.D's standard).
- **Risk**: **High** — an unauthenticated, high-value surface where a
  signature bound to mutable content would be worthless.
- **Model**: **Opus 5 warranted.**

### Sub-slice D — Stripe Connect onboarding

- **Files/domains**: `App\Library\Payments\StripeConnectGateway` — the
  **lane-B-owned** Stripe boundary (§4.2), owner-only connect/disconnect
  flow, Account Links onboarding, capability sync into
  `business_stripe_connections`.
- **Prerequisites**: A (hard). **Two gates: (1) §11.2's account-type and
  liability decision must be answered; (2) the `stripe/stripe-php ^7.76`
  version must be verified sufficient for the Connect APIs used — if a
  major bump is required, that is a dependency change needing its own
  explicit authorization, and the implementer must STOP and report rather
  than bumping it unilaterally.**
- **Schema**: none new — consumes A's `business_stripe_connections`.
- **Tenancy/security**: owner-only per §6.1's flagged default (if the
  product owner instead places it behind the single feature permission,
  that is the one-line change §6.1 names); no connected-account secret is
  ever stored (§5.7).
- **Concurrency**: §7's no-network-call-under-lock rule; `lock_version` for
  optimistic capability sync.
- **Tests**: `unique(business_id)` enforces one connection per Business;
  a non-owner cannot connect or disconnect; capability flags round-trip;
  a restricted/disabled account is reflected and blocks charging (§11.4);
  **an instrumentation test proving no gateway call is made while
  `DB::transactionLevel() > 0`** (the executable form of §7's hard rule,
  and of acceptance criterion 6).
- **Risk**: **High** — new provider surface plus an unresolved commercial
  decision. **Model**: **Opus 5 warranted.**

### Sub-slice E — Payment schedule, PaymentIntents, and webhook ingestion

- **Files/domains**: `App\Library\Payments\PaymentManager` (create the
  intent for a schedule item on the connected account via direct charge,
  §5.7); the public payment `POST`; the lane-B webhook route, controller
  and `App\Jobs\BusinessPayments\ProcessBusinessPaymentEvent` job
  implementing §8.2 in full; the receipt notification job dispatched from
  `DocumentPaymentSucceeded` and deduplicated by
  `business_document_payments.receipt_sent_at` (§5.9, §8.4); the
  `DocumentPaymentSucceeded` / `DocumentFullyPaid` events;
  `VerifyCsrfToken` exception and the `STRIPE_CONNECT_WEBHOOK_SECRET`
  config entry.
- **Prerequisites**: A, B, C, D (hard).
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: §6.2 for the public pay action; §11.4's readiness
  recheck immediately before every intent.
- **Concurrency**: §7 in full — the three-step no-lock-across-network
  sequence, and §8's claim/lease.
- **Tests**: the entire §8.3 replay table, each proved by actually
  replaying the same event — no duplicate payment, no duplicate transition,
  no duplicate refund, no duplicate receipt, no terminal document moved
  backward; every fail-closed cross-check (`amount_mismatch`,
  `currency_mismatch`, `account_mismatch`, `operation_id_mismatch`,
  `no_matching_local_record`); an invalid signature yields 400 with zero
  rows written; a duplicate delivery yields 200 with zero re-processing; a
  deposit-paid document is not `paid` until the balance settles; a browser
  redirect alone never transitions state (§8.5); **every PaymentIntent is
  created with the `Stripe-Account` header naming the resolved connection
  and with no `application_fee_amount`, asserted against the fake gateway's
  recorded request options** (acceptance criterion 2); **a schedule whose
  amounts do not sum to the version total is refused before any intent is
  created** (§5.9); **paying a document linked to an Opportunity leaves
  that opportunity's stage unchanged** (acceptance criterion 8, Blueprint
  §9); the §11.1 lane source-boundary test.
- **Risk**: **Critical** — real customer money, replay safety, and the lane
  boundary all land here. **Model**: **Opus 5 warranted.**

### Sub-slice F — Reminders, expiration, refunds

- **Files/domains**: two scheduled commands following the
  `SweepExpiredOpportunitySnoozes` convention exactly (§3.5) —
  `documents:expire-due` and `documents:dispatch-due-reminders`, kept
  separate because they select disjoint row sets; refund issuance in
  `App\Library\Payments\PaymentManager` (§5.9) plus the refund webhook
  handling routed by `event_type` (§8.3); the sweep/reminder keys added to
  the `config/documents.php` that Sub-slice A created; the
  `DocumentExpired` / `DocumentRefunded` events.
- **Prerequisites**: A, B, C, E (hard).
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: refunds gated per §6.1's single named exception.
- **Concurrency**: per-row transaction + `lockForUpdate()` + re-verify the
  precondition under the lock; `Throwable` per row logged, loop continues.
- **Tests**: both commands' full convention suite (exit codes, exact
  output strings, default option read off the definition, `--limit`
  honored, **double-run idempotency**, disabled → exact message + zero
  mutation + a bound fake that throws if invoked, every invalid `--limit`
  form → `self::INVALID` + zero mutation, manager exception not swallowed)
  plus the `ReflectionMethod` schedule-registration test; a reminder is
  never sent twice for the same schedule item and window; an expired
  document cannot be signed or paid; a refund is idempotent under replay
  and never moves the document out of `paid`; a refund exceeding the
  refundable balance is refused.
- **Risk**: Medium. **Model**: Sonnet 5 sufficient.

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
- **Tenancy/security**: §6.1 — the single feature permission plus
  `LocationAccessGuard` on every Activity Center, Global Search and
  timeline read. Neither surface may return, or even hint at the existence
  of, a document outside the viewing actor's granted Locations (Blueprint
  §24's "filtered to what the viewing actor is permitted to see", §26).
  The `DocumentActivitySource` must follow `AutomationActivitySource`'s own
  guard: return `[]` rather than guess when the subject's Contact does not
  belong to the Business.
- **Concurrency**: none — read-only surfaces plus a single registry flip.
- **Tests**: nav visibility per tier (all three, Blueprint §21); timeline
  items appear with past-tense titles and Location filtering; search and
  Activity Center never reveal a document the actor could not open; the
  entitlement flip test.
- **Risk**: Low. **Model**: Sonnet 5 sufficient.

## 13. Required tests

Beyond each sub-slice's own suite:

1. **The lane source-boundary test** (§11.1) — mechanical, and the single
   most important test in this contract.
2. **The end-to-end acceptance path**, matching the Acceptance Matrix
   verbatim: an owner sends a proposal, a customer signs and pays it via
   the link, and both parties see it — plus the End Customer row's own
   statement, "A customer signs and pays via the emailed link."
3. **The immutability proof**: a catalog price change after issuance
   provably does not alter the issued document, its lines, or the
   signature's bound content hash.
4. A Location-ACL regression across every authenticated surface this slice
   adds.

## 14. Acceptance criteria

1. No file in this slice references any lane-A or lane-D payment artifact
   enumerated in §11.1 (which mirrors §4 in full), proved by the §11.1
   source-boundary test.
2. Every charge is a direct charge on the Business's own connected account;
   no `application_fee_amount` is ever set; `unique(business_id)` holds on
   `business_stripe_connections`.
3. Every transactional document is Location-attributed (`NOT NULL`) and
   every catalog line carries a Contract 16 `package_snapshot_uid`.
4. An issued version, its line items, and a signature are immutable —
   proved by a source-boundary test, not a self-referential assertion.
5. Replaying any webhook event produces no duplicate payment, transition,
   refund or receipt, and never moves a terminal document backward (§8.3).
6. No provider network call occurs inside a transaction or under a row
   lock (§7).
7. The public link is non-guessable, hashed at rest, expiring, rotatable,
   throttled, and returns one uniform refusal for every failure reason.
8. Document lifecycle changes never auto-advance a CRM pipeline stage
   (Blueprint §9).
9. The entitlement flips to `Available` only after A–F are merged and the
   end-to-end path passes.
10. `git diff --check` clean and a clean working tree per sub-slice commit.

## 15. Non-goals

- **Any lane A, C, or D money.** No platform Stripe, no Agency Stripe, no
  usage wallet, no `PayerType`/`EffectivePayer`/AgencyRebill, no
  `payment_provider_customers`, no `business_payment_instruments`, no
  auto-recharge — and no reuse of the legacy `invoices`/`plans`/
  `payment_methods`/`PaymentController` stack (§4).
- **A dedicated client portal** — explicitly V2 (Blueprint §18, §34); the
  per-document link is the V1 mechanism.
- **Proposal → Invoice conversion, or any persisted relationship between
  two documents** — no authority document describes one; Blueprint §9's
  "Invoice Sent" pipeline stage is satisfied in V1 by sending an
  `invoice`-kind document directly (§5.1). A conversion action would be new
  product behavior requiring its own authorization.
- **Complex installment plans** — V2 (Blueprint §34). Deposit + balance
  only.
- **Per-Location Stripe accounts** — V2 (Blueprint §34).
- **Discounts or negotiated prices** — forbidden by Contract 16 §15;
  `$explicitPriceMinor` is passed only for a genuinely quote-only item.
- **Tax calculation** — named in no authority document; not invented.
- **A PDF artifact** — no capability exists, none is authorized, and adding
  one is a new dependency decision (§5.6).
- **Naming or integrating an e-signature vendor** — none is named in any
  document (§6.5).
- **Establishing a legal/compliance posture for electronic signatures** —
  outside this repository's source authority; a product-owner decision
  (§6.5).
- **Choosing the Stripe Connect account type / liability model** — a
  commercial decision this contract refuses to invent (§11.2).
- **Auto-advancing an Opportunity pipeline stage from payment progress** —
  Blueprint §9 forbids it explicitly.
- **Wiring proposal events into the Automations builder vocabulary** — the
  events are emitted (§10), but changing Automations' own
  `NoUnsupportedVocabularyTest` guardrail is a separately authorized
  follow-on.
- **A standalone tasks/reminders module** — V2 (Blueprint §34); this slice
  builds only document-scoped reminders.
- **Multi-recipient document sending** — one recipient per document in V1
  (§5.2).
- **Reopening the Workspace/Agency tenancy migration** (Contracts 1–14) or
  modifying Contract 16's own tables.

## 16. Merge prerequisites

- **Contract 16 Sub-slices A, B, C and D merged *and implemented*** —
  hard, for Sub-slice B onward. Contract 16 is currently a document only
  (§3.2); `PackageSnapshotService` does not exist. Note this is stricter
  than the Roadmap's one-line "needs package snapshots": Contract 16 §12.D
  names A, B and C as its own hard prerequisites, and Contract 16 Sub-slice
  C's pricing resolver is what decides whether an item is quote-only —
  which this slice's `$explicitPriceMinor` rule depends on. Sub-slice A of
  this slice may proceed independently (§12.A).
- Contracts 1–14 merged (they are, as of `30ad21c7`) for
  `LocationAccessGuard` and the entitlement/nav machinery.
- Per-sub-slice prerequisites and the two decision gates are stated in
  §12.

## 17. Conflict map

| Other work | Shared file/table | Posture |
|---|---|---|
| Contract 16 (Packages & Products) | `package_snapshots` — **read-only consumer**, plus calls to `PackageSnapshotService::snapshot()`; this slice never writes that table | Serialize: Sub-slice B needs Contract 16 A+D implemented |
| Slice 15 (Calendar) — no contract document exists yet | none — both would independently add a `CustomerMenuBuilder`/`ENTITLEMENT_GATED_FEATURES` line and a `PlatformFeature` case; ordinary low-conflict merges | Parallel-safe |
| RFC-005 usage billing (lane D) | **none by construction** — separate tables, separate route, separate gateway class, separate webhook secret; enforced by the §11.1 source-boundary test | Must never converge |
| Automations | `NoUnsupportedVocabularyTest` (only if a follow-on wires the proposal trigger) | Deferred; not touched by this slice |
| `AppServiceProvider` (`SOURCES_TAG`), `CustomerMenuBuilder` | additive lines | Low risk |

## 18. Implementation prompts

Each sub-slice is handed to a fresh session independently, once explicitly
authorized. Every prompt assumes Contracts 1–14 and every lower-lettered
sub-slice are already merged.

### 18.A — Schema/domain foundation + money value object + inert entitlement

```
You are implementing Sub-slice A of Slice 17 (Proposal / Contract /
e-signature / Invoice) for os-creator1/os-ai, per docs/product/
implementation-contracts/17-PROPOSAL-CONTRACT-ESIGNATURE.md SS5 and
SS12.A. Schema and models only -- no managers, controllers, routes, UI,
or provider code.

Before writing code:
1. Fetch origin/main and verify it is at or after 30ad21c7.
2. Read SS4 (money lanes) and SS5 (canonical domain model) in full. SS4
   is the most important section in the contract: every existing payment
   artifact in this repository belongs to a forbidden lane.
3. Create a fresh worktree/branch for this sub-slice only.

Implement exactly the nine tables in SS5.2-SS5.9 as migrations, plus
Eloquent models with casts/relations only. Non-negotiable details:
- Integer MINOR units everywhere (unsignedBigInteger) + char(3)
  currency_code. Never micro-units -- that is lane D's convention.
- Every model overrides generateUid() to (string) Str::uuid(); the
  HasUid trait mints uniqid(), which is time-ordered and guessable.
- Write-once models get const UPDATED_AT = null and a created_at-only
  migration column (never $table->timestamps()) -- mirror
  app/Models/WebsiteRevision.php and its migration.
- The draft_guard generated column on business_document_versions, per
  SS5.3 -- copy the technique from
  database/migrations/*create_automation_workflow_versions_table*.
- Every unique key in SS5 must exist now; later sub-slices depend on them
  for correctness and must not need a schema-altering migration. That
  explicitly includes the durable dedupe markers later sub-slices rely on
  (business_documents.expiry_reminder_last_sent_at / _count,
  business_document_payment_schedule_items.reminder_last_sent_at / _count,
  business_document_payments.receipt_sent_at) and the tenant-scoped
  unique(business_id, local_idempotency_key) keys on the payments and
  refunds tables.

Also create config/documents.php, shaped like config/opportunity.php --
at minimum `enabled`, `queue` and `link_ttl_days`. Sub-slice C reads
link_ttl_days, so it belongs here, not in Sub-slice F.

Also create App\Library\Money\CurrencyExponent -- a lane-NEUTRAL value
object (NOT under App\Library\Usage, and it must not call into it)
carrying the zero/two/three-decimal currency lists and Stripe's
minor-unit bounds, failing closed on an unlisted currency code. The
equivalent logic today is private inside the forbidden
UsageBillingCheckoutManager; re-derive it, do not reach into it.

Also add the inert entitlement identity: a new PlatformFeature case, a
PlatformFeatureRegistry entry at Planned (NOT Available), a NEW backfill
migration for platform_feature_usage_classifications (never edit the
existing merged one -- see the MessagingTransport docblock in
app/Enums/Entitlement/PlatformFeature.php), and a workspace_plan_features
seed row for all three tiers.

Tests per SS12.A. Run them, run git diff --check, commit, push to the
fresh branch. Do NOT create a PR. Do NOT merge. Return: starting/final
SHA, exact files created, exact tests run and counts, and confirmation
every constraint in SS5 exists.
```

### 18.B — Document authoring + Contract 16 snapshot consumption

```
You are implementing Sub-slice B of Slice 17, per SS12.B of docs/product/
implementation-contracts/17-PROPOSAL-CONTRACT-ESIGNATURE.md.

HARD PREREQUISITE: Contract 16 Sub-slices A, B, C and D must be merged AND
IMPLEMENTED. Verify first that ALL of these actually exist: the
catalog_items, catalog_item_location_overrides and package_snapshots
tables; app/Library/Catalog/PackageSnapshotService; and Contract 16
Sub-slice C's pricing resolver (the class that decides whether an item is
quote-only at a Location -- your own $explicitPriceMinor rule depends on
it). If any is missing, STOP and report -- Contract 16 is merged as a
document only, and this sub-slice cannot be built against services that do
not exist.

Read SS3.4 (what Contract 16 binds this slice to) before writing code.
Call the service with its exact signature:
  snapshot(CatalogItem $item, BusinessLocation $location, ?User $actor = null, ?int $explicitPriceMinor = null): PackageSnapshot
passing the DOCUMENT's own business_location_id. The service performs no
authorization -- this slice owns the entire gate (SS6.1): one feature
permission plus LocationAccessGuard::assertUserCanAccessLocation() on
every read and write.

Build DocumentManager (create/edit-draft/lines/totals/schedule
validation/void) and the authenticated list + editor UI. Line items
belong to the VERSION, not the document, and reference the snapshot by
UID (not id), per SS5.4.

Do NOT build sending, the public surface, tokens, signatures, or any
payment code -- those are C, D and E.

$explicitPriceMinor may be passed ONLY for a genuinely quote-only item.
It is never a discount mechanism -- Contract 16 SS15 forbids that
explicitly. Schedule validation accepts exactly one `full` item or
exactly two (`deposit` then `balance`); anything else is a refusal.

Tests per SS12.B, especially: a later catalog price change provably does
not alter an existing document line.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT
create a PR. Do NOT merge.
```

### 18.C — Secure send, public view, e-signature

```
You are implementing Sub-slice C of Slice 17, per SS12.C. Hard
prerequisites: Sub-slices A and B merged.

GATE: SS6.5 records that the legal/compliance posture of the e-signature
evidence model is a product-owner decision this contract deliberately did
not make. If the product owner has not stated it, STOP and report before
writing any signature code -- do not build and push a signing flow whose
legal sufficiency nobody has ruled on. (This matches SS12.C's stated gate
and 18.D's handling of its own two gates; an earlier draft of this prompt
softened it, which was the defect.)

Read SS6.3 in full and mirror app/Library/Workspace/ClientInvitationManager
exactly: two-segment route {uid}/{token}, Str::random(64) plaintext,
Hash::make() stored as token_hash, verified only via Hash::check(), never
queried by plaintext, one uniform refusal for every failure reason.
Add the three deltas that precedent lacks: throttle: on every public
route (annotate the number's justification, per routes/public.php:40-42),
->missing(fn () => abort(404)), and hash_equals() for non-bcrypt
comparisons.

The send transaction (SS7) freezes the draft version to `issued` and
freezes the document's identity fields; the delivery job is dispatched
ONLY after commit. The public page renders the FROZEN issued version --
never live data. The signature row binds business_document_version_id
and the displayed content hash (SS5.5); a signature attached to mutable
content would be worthless, which is the whole reason the version freezes
at send.

SMS delivery goes through exactly one door -- CampaignRepository::
checkQuickSendValidation() then quickSend() -- passing your OWN durable
managed_operation_key. Never call ManagedMessageDispatcher or an adapter
directly. Email uses Notification::route('mail', $email) from a
Base-extending job implementing ShouldQueueAfterCommit.

Tests per SS12.C -- the adversarial public-surface set is the point of
this sub-slice: every failure mode must produce a byte-identical refusal,
and the plaintext token must never appear in any stored row or log.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT
create a PR. Do NOT merge. Report any residual security concern you could
not fully close rather than asserting confidence you do not have.
```

### 18.D — Stripe Connect onboarding

```
You are implementing Sub-slice D of Slice 17, per SS12.D. Hard
prerequisite: Sub-slice A merged.

TWO GATES, both of which you must check before writing provider code:
1. SS11.2 -- the Stripe Connect account type (Standard/Express/Custom)
   and the dispute/negative-balance liability posture are a commercial
   decision this contract deliberately refuses to invent. If the product
   owner has not stated it, STOP and report.
2. SS3.6 -- composer.json pins stripe/stripe-php ^7.76, which is old.
   Verify it supports the Connect APIs you need. If a major bump is
   required, STOP and report: a dependency change needs its own explicit
   authorization and is NOT yours to make unilaterally.

Read SS4 in full first. app/Library/Usage/StripePaymentProviderGateway
calls itself "the sole class permitted to reference a Stripe\* SDK class"
-- SS4.2 explains why that is a lane-D scoping statement that cannot bind
lane B, and why you are creating a SECOND, lane-B-owned gateway. Do not
extend, call, or implement anything under App\Library\Usage.

Build App\Library\Payments\StripeConnectGateway plus owner-only
connect/disconnect and Account Links onboarding, syncing capability flags
into business_stripe_connections. Store NO connected-account secret --
direct charges use the platform key plus the Stripe-Account header
(SS5.7). unique(business_id) is the database-level expression of Addendum
SS12's "1 Business = 1 connected Stripe account in V1".

No provider network call inside a transaction or under a row lock (SS7).

Tests per SS12.D. Run tests, git diff --check, commit, push. Do NOT
create a PR. Do NOT merge. Report explicitly which SDK version you
verified against and how.
```

### 18.E — Payment schedule, PaymentIntents, webhook ingestion

```
You are implementing Sub-slice E of Slice 17, per SS12.E -- the highest-
risk sub-slice in this contract: real customer money, replay safety, and
the money-lane boundary all land here. Hard prerequisites: Sub-slices A,
B, C, D merged.

Read SS4, SS7, SS8 and SS11 in full before writing any code.

Non-negotiables:
- Direct charges on the Business's connected account. No
  application_fee_amount -- no document authorizes one.
- Recheck connection readiness (status active, charges_enabled)
  immediately before every PaymentIntent (SS11.4).
- Every provider call carries an idempotency key derived from a DURABLE
  local identity, persisted and unique, and round-tripped through
  metadata.app_operation_id. A random per-call key is not idempotency.
- Three-step sequence for every provider interaction: short committed
  transaction to write local intent, network call OUTSIDE any
  transaction, short committed transaction to finalize. No exceptions.
- Webhook: verify the Connect signature over the raw body BEFORE any
  insert (400, zero side effects on failure); duplicate insert caught on
  SQLSTATE 23000 returns 200 with zero re-processing; the job claims via
  one atomic conditional UPDATE with a lease and returns immediately when
  it claims nothing; terminal writes guarded WHERE state='processing';
  last_error stores an exception CLASS or reason code, never a message.
- Refund/dispute events route by event_type, not metadata (SS8.3 explains
  the Stripe behaviour behind this).
- A browser redirect NEVER transitions state. The legacy PaymentController
  confirms payment from a session value after redirect; that is exactly
  the pattern you must not reproduce.

The SS8.3 replay table is your test plan: prove each row by actually
replaying the same event. Also implement the SS11.1 source-boundary test
asserting no file in this slice references App\Library\Usage\*,
App\Models\Invoices, PaymentMethods, payment_provider_*,
business_payment_instruments, PayerType or EffectivePayer.

Run tests, git diff --check, commit, push. Do NOT create a PR. Do NOT
merge. Return exact replay-test results and state explicitly any race or
replay case you could not fully close.
```

### 18.F — Reminders, expiration, refunds

```
You are implementing Sub-slice F of Slice 17, per SS12.F. Hard
prerequisites: Sub-slices A, B, C, E merged.

Build two SEPARATE scheduled commands -- documents:expire-due and
documents:dispatch-due-reminders -- because they select disjoint row
sets. Follow app/Console/Commands/SweepExpiredOpportunitySnoozes.php
exactly: domain logic in the manager, the command owns the config
feature-flag no-op (exact message + self::SUCCESS + zero mutation and
zero manager invocation), strict --limit validation returning
self::INVALID on anything that is not a positive integer, a BOUNDED batch
that never drains to empty, per-row transaction + lockForUpdate() +
re-verify the precondition under the lock, Throwable per row logged and
the loop continues. Register both unconditionally in Kernel::schedule()
with a comment justifying the cadence. Add your sweep and reminder keys to
the config/documents.php Sub-slice A already created, shaped
like config/opportunity.php.

Reminder idempotency lives in the MANAGER on a durable marker
(reminder_last_sent_at / reminder_count), never in the job -- mirror the
low_balance_notified_at precedent. The send additionally carries a
deterministic managed_operation_key of the form
document:{uid}:reminder:{n}.

Refund issuance does not exist anywhere in this repository today; build
it per SS5.9 and SS8. A refund is idempotent under replay, never moves a
document out of `paid`, and an amount exceeding the refundable balance is
refused.

Write BOTH test classes per command, per SS12.F -- behavior and
ReflectionMethod-based schedule registration.

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
SS18.E.

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
reveal a document the viewing actor could not open.

ONLY as the last step, once you have verified the end-to-end path (owner
sends a proposal, customer signs and pays via the link, both parties see
it), flip the PlatformFeature registry entry from Planned to Available.

Run tests, git diff --check, commit, push. Do NOT create a PR. Do NOT
merge. Return explicit confirmation of what you verified end-to-end
before flipping the entitlement.
```
