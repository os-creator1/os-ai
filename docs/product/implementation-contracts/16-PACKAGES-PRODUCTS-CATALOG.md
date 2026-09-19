# Implementation Contract 16 — Packages & Products Catalog

**Status:** Planning contract only. Does not authorize implementation.
Contracts 1–14 (Workspace/Agency tenancy migration) are complete on `main`
as of this contract's writing (`3dbb1e11`); this slice reuses the Location
ACL/ownership model they established and does not reopen that migration.
Five independently mergeable sub-slices (§12/§18, A–E) implement this
contract in dependency order; **no sub-slice below may start without its
own separate, explicit human authorization**, matching this repository's
route-3 governance (`CLAUDE.md`). This slice is rated Low risk/L
complexity by the Roadmap — materially simpler than Slice 15 (Calendar):
no hard double-booking-style invariant, no external-provider integration,
and every schema/domain decision below mirrors an existing, already-proven
convention in this codebase rather than inventing one. The one place this
contract's own correctness bar is high is §7's catalog-item serialization
lock and §6's three-gate customer authorization chain — both stated
explicitly and precisely rather than asserted away.

## 1. Objective

Design and, across five dependency-ordered sub-slices, build the V1
Packages & Products catalog: one canonical Business-wide catalog, per-
Location enable/disable and optional price override, and an immutable
snapshot mechanism that Slice 17 (Proposal/Contract/e-signature) will
consume so a later catalog or price change can never retroactively alter
a past transactional document. Entirely net-new; does not build Slice 17
itself.

## 2. Governing authority

- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §17 (Packages & Products),
  §5 (Business-Wide vs Location-Bound Matrix, which places "Package/
  Product catalog (§17)" in the Business-wide column while classifying
  the *transactions* that reference it as Location-bound — the exact
  distinction §5.3/§6 below build on), and §36 (sensible implementation
  defaults are permitted for minor UX/presentation choices when no
  document states one; "BLOCKING UNRESOLVED PRODUCT DECISIONS: NONE").
- `docs/product/V1-IMPLEMENTATION-ROADMAP.md`, "Slices 15–18" entry —
  Slice 16 is Net-new, L complexity, Low risk, independent of Slices
  1–14 except for reusing Location/Business scoping.
- `docs/product/V1-AUTHORITY-TRACEABILITY-MATRIX.md` row 14 — "NOT YET
  IMPLEMENTED... Net-new; build Business-wide catalog + Location
  enable/override + immutable snapshot from the start."
- `docs/product/V1-ACCEPTANCE-MATRIX.md` row 31 ("Sell from a catalog,
  collect signed & paid agreements") — classifies this domain **BW
  catalog / LB transactions** (Business-wide catalog, Location-bound
  transactions — §5.3/§6's load-bearing distinction), requires the
  "package/price immutably" snapshot, and states the permission boundary
  as **"Owner + staff per feature permission"** — an explicit customer
  capability gate, not "any active Workspace membership" (§6).
- `docs/rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md` §14 (Packages/
  Products) — the sole, self-contained, three-sentence authority this
  entire contract implements verbatim (quoted in full, §3.1).
- `docs/rfcs/RFC-004` — a planned/unbuilt `PlatformFeature` must never
  become customer-executable through plan mapping or a direct route
  before its registry entry is flipped to `Available` (§11/§12 sequencing).

## 3. Current repository reality — recon findings (as of this contract's writing, on `main` @ `3dbb1e11`)

### 3.1 Addendum §14, verbatim — the entire normative authority for this slice

> The canonical Package/Product catalog is Business-wide. Locations
> **MAY** enable/disable a package and **MAY** apply an optional price
> override; the canonical catalog **MUST NOT** be duplicated per
> Location. Every proposal/invoice/booking **MUST** store an immutable
> snapshot of the actual package/price used.

Blueprint §17 restates the same three obligations in near-identical
language and adds one line this contract treats as load-bearing: *"later
catalog or price changes never retroactively alter a past transactional
document."* No other document states a fourth requirement — this
contract's scope is exactly these three obligations (Business-wide
catalog; Location enable/override; immutable snapshot) plus the
Acceptance Matrix's explicit permission boundary (§2), nothing broader.

### 3.2 Confirmed absent on `main` — genuinely net-new

Exhaustive grep found **zero** `Package`, `Product`, `Offer`, or
`LineItem` model, migration, controller, route, or view; **zero** `sku`
column anywhere in the schema. The codebase's own prior audit confirms
this independently (`V1-AUTHORITY-TRACEABILITY-MATRIX.md` row 14: "No
`Package`/`Product` model found"). Slice 17 (Proposal/Contract/
e-signature) is equally absent in code — Roadmap explicitly schedules it
after Slice 16 specifically because it "depends on Slice 16 for package
snapshots" (Roadmap lines 281, 328) — confirming this contract's
snapshot design is the one thing a later slice will build directly on.

No `PlatformFeature` case, `config/customer-permissions.php` entry, or
`CatalogItemPricingResolver`-equivalent exists yet either — all three are
this contract's own responsibility to introduce (§6, §11, §12).

### 3.3 Reusable existing conventions — mirrored, not invented, in §5–§7

| Concern | Precedent | Reuse |
|---|---|---|
| Money representation | `CrmOpportunity.value_minor` — **`unsignedBigInteger`** (not signed `bigInteger` — confirmed by reading the actual migration, `database/migrations/2026_09_13_160003_create_crm_opportunities_table.php`) + `currency_code char(3)` (plain string, no FK), migration docblock: *"the Business currency captured at creation so a later currency change does not silently re-denominate existing deals"* | The exact rationale this slice needs for its own price/currency snapshot, including the exact column type: `unsignedBigInteger` throughout (§5.1–§5.3), never signed — no document authorizes a negative sale price, and using the cited precedent's actual type is the whole point of citing it. Chosen over Usage's `*_micro bigint + currency_id FK` convention (a different bounded context — sub-cent metered billing, not a static catalog price) and over `BusinessService.starting_price decimal(12,2)` (an earlier, superseded-in-spirit convention). |
| Business's own currency | `businesses.currency_code` (NOT NULL, no default) | The single source of a catalog item's default currency at creation time, and — for a quote-only item with no fixed price — the source of a snapshot's currency at the moment of the transaction (§5.3, §6). Read directly (`$business->currency_code`); no shared resolver class exists to call into, and this slice does not build one for that single direct-read field. |
| Ordering | `CrmPipelineStage.position` (`unsignedSmallInteger default 0`) + `CrmPipelineService::applyOrder()`'s full-reindex-on-reorder (submit the complete ordered id list, rewrite every row to its 0-based array index in one pass, under `lockForUpdate()`), `orderBy('position')->orderBy('id')` as the canonical read order | Mirrored exactly for catalog items (§5.1, §7). |
| Archive vs delete | `BusinessLocation.lifecycle_state` (enum, not fillable) + `archived_at` (nullable timestamp, not fillable), with `archive()`/`reactivate()` as the *only* write path in the repository | Mirrored exactly (§5.1, §6) — never Laravel's native `SoftDeletes`. |
| Immutable snapshot | `website_revisions` — dedicated table, `const UPDATED_AT = null`, no soft delete, no status column, denormalized copy of the mutable source at the moment of the event, docblock: *"nothing in this codebase may UPDATE a row in this table after insert."* Read in full for this correction (`app/Models/WebsiteRevision.php`): the model itself has **no** special DB- or Eloquent-level mutation lock beyond `const UPDATED_AT = null` — the invariant is enforced entirely by "no production code path calls `update()`/`save()` on an existing row," proved by a source-boundary-style test (the same technique `BusinessLocationBoundaryTest`/T-LOC-9 already uses in this repo), not by a mechanism that makes mutation impossible. | The literal shape §5.3's `package_snapshots` table mirrors, **including that same enforcement technique** (§12.D, §13) — this contract does not claim a stronger guarantee than its own precedent actually provides. |
| Location→Business FK shape | `contacts.location_id`/`chat_boxes.location_id` (`restrictOnDelete`, indexed) | Every FK to `business_locations` in this slice's schema follows the same `restrictOnDelete` convention uniformly (§5.2). |
| Business/Location-scoped write authorization | `LocationAccessGuard::assertUserCanAccessLocation()` (Location-scoped writes); ordinary active `WorkspaceMembership` lookup (Business-wide tenancy) | §6 — no new ACL algorithm; layered under a customer-capability gate the Acceptance Matrix requires (§6). |
| Customer capability gate | `config/customer-permissions.php` — a flat map of capability keys, each `['display_name' => ..., 'category' => ..., 'default' => bool]` (e.g. `automations`, `website`); checked independently of, and in addition to, tenant authorization — never a substitute for it (`view_google_business_profile`/`manage_google_business_profile`'s own docblock: a capability answers "may this actor use this FEATURE at all", tenancy answers "may this actor reach this SPECIFIC Business/Location") | §6/§12/§18 — this slice adds exactly one new key, `packages_products`, the simple single-capability shape `automations`/`website` already use — not a CRUD-matrix of separate create/edit/archive/reorder/override permissions, which no document authorizes and which would be a materially larger permission surface than the Acceptance Matrix's one-line "Owner + staff per feature permission" calls for. |
| Entitlement/nav wiring | `PlatformFeature` enum + `PlatformFeatureRegistry` (`PlatformFeatureAvailability::Planned`/`Available`) + `CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES`, and the *"one row per PlatformFeature case, backfill migration throws if any case lacks one, merged migrations are not edited"* discipline (`app/Enums/Entitlement/PlatformFeature.php` docblock on `MessagingTransport`) | §11/§12 — **unlike Calendar** (which already had a `Planned` `PlatformFeature::Calendar` case and a seeded `workspace_plan_features` row waiting to be flipped), grep confirms **no existing case for this catalog**. Per RFC-004 (§2), the case, registry entry, backfill migration and `workspace_plan_features` seed row must all exist and stay `Planned` **before** any customer-executable route can exist — so this contract now introduces that inert plumbing in Sub-slice A, not Sub-slice E (§11/§12, corrected). |

## 4. Delta from current state to target

Pure additive build — no retrofit, no legacy model to migrate off of. The
adjacent legacy tables found by recon (`plans`, `invoices`, `customer_
based_pricing_plans`, `plan_sending_credit_prices`) are Ultimate-SMS's own
SaaS-subscription/SMS-credit billing, a different bounded context this
slice does not touch, extend, or migrate (§15). `workspace_plan_catalog`
(the platform's own subscription-tier catalog) is precedent-only, never a
dependency — it is a different domain (what a Workspace pays the
platform) from what this slice builds (what a Business sells its own
customers).

## 5. Canonical domain model

One canonical catalog table, one Location-override table, one immutable
snapshot table. All FKs to `business_locations` use `restrictOnDelete`
(§3.3). Money is `unsignedBigInteger` minor-units + `char(3)` currency
code, co-nullable as one invariant on the source table (§5.1) — never
signed, per §3.3's corrected precedent reading.

### 5.1 `catalog_items`

**Naming note, stated explicitly rather than left implicit**: Blueprint
§17 and Addendum §14 both use "package" as their one operative noun
throughout every normative sentence — neither document ever describes a
Package as containing or bundling multiple Products, or draws any
operational distinction between the two beyond the compound nav label
"Packages & Products" (Blueprint line 173). This contract therefore
models **one** canonical catalog-item table — not a Package-contains-
Products bundling relationship, which no authoritative document
describes and which this contract does not invent. The table is named
generically (`catalog_items`, model `CatalogItem`) rather than
`packages`, specifically to avoid the confusing `type = 'package'` row
on a table called `packages`; a `type` column (`product`/`package`)
exists purely for the UI's own display categorization, matching the
feature's compound nav label, and carries no behavioral difference
anywhere else in this schema. If a future slice needs true bundling (a
Package composed of multiple Product rows with quantities), that is new
product behavior requiring its own explicit authorization.

```
id
uid                       uuid, unique
business_id               FK -> businesses, restrictOnDelete, NOT NULL
type                       string(16): product | package   -- display/categorization only, no bundling relationship
name                       string(160)
description                text, nullable
price_minor                 unsignedBigInteger, nullable
currency_code                char(3), nullable
position                     unsignedSmallInteger, default 0
lifecycle_state               string(16): active | archived, default 'active', NOT fillable
archived_at                    timestamp, nullable, NOT fillable
created_by_user_id              FK -> users, nullOnDelete, nullable
timestamps

index (business_id, lifecycle_state)
index (business_id, position)
```

**`price_minor`/`currency_code` are both nullable, one invariant enforced
at the application layer** (both null, or both set — never one alone),
mirroring `workspace_plan_catalog`'s identical *"both-null-or-both-
populated invariant enforced at app layer"* precedent exactly. Nullable
because a catalog item MAY be a fully custom/quote-only offering with no
fixed base price (`BusinessService.starting_price` is nullable for the
same reason) — in that case every transactional document referencing it
must supply an explicit price at snapshot time, resolved under §6's
explicit-price rule, never silently defaulting to zero.

**Writing to this table is gated by the `packages_products` customer
capability plus ordinary Business-wide tenancy (§6) — this row has no
Location axis of its own, so `LocationAccessGuard` is never consulted for
it.**

No slug, no `unique(business_id, name)` — Blueprint does not require
unique catalog-item names, and this contract does not invent that
constraint.

### 5.2 `catalog_item_location_overrides`

**Sparse-override design — this contract's locked V1 implementation
default, not an open product question.** The table holds **only** rows
for Locations that deviate from the Business-wide default — no row
present for a given `(catalog_item_id, business_location_id)` pair means
the item is enabled at every ACL-authorized Location at the Business-wide
default price. This mirrors the existing Location-ACL pivot philosophy
(`workspace_membership_locations` stores only explicit grants, never a
row for every Location a member *could* reach) and is the more useful
default given the catalog is explicitly Business-wide (Blueprint §5): an
owner adding a new catalog item expects it usable everywhere immediately,
not hidden until each Location opts in. The Master Blueprint states
**"BLOCKING UNRESOLVED PRODUCT DECISIONS: NONE"** and its §36 permits a
sensible implementation default for exactly this kind of minor,
non-normative UX choice when no document states one — Blueprint §17's
"may enable or disable" does not itself pick a default, so this contract
picks one and locks it in as V1 behavior. It is **not** a human blocker
awaiting a decision; a later change to opt-in-by-default would be a
product behavior change requiring its own explicit authorization, not a
correction to this contract.

```
id
catalog_item_id             FK -> catalog_items, cascadeOnDelete
business_location_id         FK -> business_locations, restrictOnDelete
is_enabled                    boolean, default true
price_minor_override            unsignedBigInteger, nullable
timestamps

unique (catalog_item_id, business_location_id)
index (business_location_id)
```

`cascadeOnDelete` on `catalog_item_id` (not `business_location_id`) —
this row's only meaning is "this Location deviates from this catalog
item's default"; it has no independent audit value once the catalog item
itself is gone (which, per §5.1's lifecycle-state discipline, only
happens through an explicit hard-delete path this contract does not
build — catalog items are archived, never deleted, in ordinary
operation, so this FK direction is not expected to fire in practice).

**No `currency_code_override` column** — a Location's price override
changes the *amount* only, never the currency; currency is a Business-
wide property (`business.currency_code`, §3.3), and no document
authorizes per-Location multi-currency pricing. Not invented here.

**Writing to this table is gated by the `packages_products` customer
capability, ordinary Business-wide tenancy (the actor must reach the
catalog item's own Business), AND `LocationAccessGuard::
assertUserCanAccessLocation()` for the specific `business_location_id`
being overridden (§6)** — every gate, none substituting for another.

**Effective-price resolution and the active/enabled invariants it must
enforce** (the one small domain service this table requires, consumed by
§5.3 and by Sub-slice C's own read paths): `CatalogItemPricingResolver`
is not a numeric fallback helper alone — before resolving anything it
**refuses** when the catalog item is archived, when the supplied Location
belongs to a different Business than the catalog item (a data-integrity
refusal, not an ACL one — checked before `LocationAccessGuard` is even
consulted, per §6), or — once past those checks — reports that the item
is not offered at that Location because an override row exists with
`is_enabled = false`. Only past all three does it resolve a price:
override row with a non-null `price_minor_override` → that is the
effective price; else the catalog item's own `price_minor` (Business-wide
default) → that; else no fixed price exists (the item is quote-only at
that Location). This resolution logic — invariants included — lives in
exactly one place and is never duplicated or bypassed, including by
`PackageSnapshotService` (§6, §12.D).

### 5.3 `package_snapshots` — the immutable record Slice 17 will consume

Mirrors `website_revisions`'s exact write-once discipline (§3.3): no
`updated_at`, no soft delete, no status column, a denormalized copy of
every field a later transactional document needs, captured once and
never touched again — enforced the same way its precedent enforces it
(§3.3, §12.D, §13): no production code path calls `update()`/`save()` on
an existing row, proved by a source-boundary test, not by a claim that a
model method makes mutation impossible.

```
id
uid                          uuid, unique
business_id                   FK -> businesses, restrictOnDelete, NOT NULL
catalog_item_id                 FK -> catalog_items, restrictOnDelete, NOT NULL
business_location_id             FK -> business_locations, restrictOnDelete, NOT NULL
name_at_snapshot                   string(160)
description_at_snapshot             text, nullable
price_minor_at_snapshot               unsignedBigInteger, NOT NULL
currency_code_at_snapshot               char(3), NOT NULL
schema_version                            unsignedSmallInteger, default 1
created_by_user_id                          FK -> users, nullOnDelete, nullable
created_at                                    timestamp only

const UPDATED_AT = null   -- no updates, ever, after insert
-- no soft delete, no status column

index (catalog_item_id)
index (business_id)
index (business_location_id)
```

**`business_location_id` is `NOT NULL`**, and it is corrected here from an
earlier, mistaken nullable draft. It does not merely record "there
happened to be a Location override" — it records **the Location of the
transaction itself**. The Acceptance Matrix classifies this whole domain
as Business-wide catalog / **Location-bound transactions** (§2): every
proposal, invoice, or booking this snapshot mechanism serves occurs at a
specific Location, so every snapshot names it — **even when the
Business-wide default price applied and no
`catalog_item_location_overrides` row existed for that Location at all.**
Conflating "no override row" (§5.2's sparse-default semantics, about
whether a *deviation* exists) with "no Location" (this column, about
where the *transaction* happened) would silently lose the exact Location
context under which enable/disable was resolved, price was resolved, and
the transaction occurred — precisely the context Blueprint §17 means by
"the actual package/price actually used." `PackageSnapshotService::
snapshot()` therefore requires a real `BusinessLocation`, never an
optional one (§6, §12.D).

**`price_minor_at_snapshot`/`currency_code_at_snapshot` are NOT
nullable** — a snapshot represents money that was *actually used* for a
real transactional document. Which of the two source facts populates
`currency_code_at_snapshot` depends on which pricing path resolved
(§6's explicit-price rule): when a canonical fixed price exists (the
Business-wide default or a Location override amount), the currency comes
from the catalog item's own captured `currency_code` — preserving §3.3's
"currency captured at creation" rationale even under a Location override,
since §5.2 forbids per-Location currency divergence. When the item is
quote-only at that Location (no fixed price resolves at all) and the
caller supplies an explicit price, the currency instead comes from the
**Business's current `currency_code`** at the moment of the snapshot —
the only source of truth available, since a quote-only item was never
denominated in any currency of its own. `PackageSnapshotService::
snapshot()` (§6, §12.D) refuses to create a snapshot without a price
resolved through exactly one of those two paths, never a null or a
silent zero.

**`created_by_user_id` is nullable, `nullOnDelete`** — corrected here
from an earlier, mistaken `NOT NULL` draft. A User id when a staff or
customer-portal actor created the snapshot; `NULL` for a public/system
transactional flow. A public self-booking (Slice 15's own customer-facing
booking path, or an equivalent future flow) has no authenticated staff
User to attribute the snapshot to, and Blueprint/Addendum still require
one to be created — inventing a fake "system" User account to satisfy a
`NOT NULL` constraint is not authorized by any document and is not done
here. `PackageSnapshotService::snapshot()`'s actor parameter is therefore
`?User $actor = null` (§12.D).

**No `package_items_at_snapshot` JSON blob** — since §5.1 explicitly does
not model bundling, there is no child-line-item structure to denormalize;
if Slice 17 needs to represent "N units of this catalog item on one
proposal," that quantity/line-item concept belongs to Slice 17's own
schema (an Order/Proposal line item referencing this snapshot's `uid`),
not to this table.

## 6. Authority / security contract

**Three independent gates for every customer-reachable write, plus a
fourth for Location-scoped writes — none substitutes for another, and
each is checked explicitly, in the order below, by every controller this
slice ever builds (all of them in Sub-slice E, per §12's corrected
sequencing).**

1. **Tenant authorization** — the actor must ordinarily reach the target
   Business (Business-wide `catalog_items` writes: an active
   `WorkspaceMembership`, Admin or Staff, role-blind — matching
   `LocationAccessGuard`'s own precedent of not distinguishing Admin from
   Staff — or that Workspace's owner). A cross-Workspace actor must never
   see or mutate another Business's catalog; a guessed foreign Business
   uid is refused here, before either gate below is even asked.
2. **Customer capability** — the `packages_products` capability
   (`config/customer-permissions.php`, the simple single-key shape
   `automations`/`website` already use: `display_name`, `category`,
   `default`) must be granted to the acting customer. This is the
   Acceptance Matrix's explicit **"Owner + staff per feature permission"**
   boundary (§2) — a real, checkable customer-feature gate, not merely
   "any active Workspace membership," which the original draft of this
   contract wrongly treated as sufficient on its own. Capability
   authorization is **never** tenant authorization by itself: holding the
   capability with no tenancy to the target Business/Location is refused
   by gate 1 (or gate 4) regardless, and reaching the Business/Location
   without the capability is refused here regardless of tenancy. One new
   key only — not a CRUD-matrix of separate create/edit/archive/reorder/
   override permissions, which no document authorizes.
3. **Platform entitlement** — `EntitlementManager` must permit
   `PlatformFeature::PackagesProducts` for the resolved Business/Workspace
   (§11). While the feature's registry entry is `Planned` this gate always
   refuses, which is the entire mechanism that keeps Sub-slices B/C/D's
   domain code non-customer-executable until Sub-slice E flips it (§11,
   §12).
4. **Location ACL** (Location-scoped writes only —
   `catalog_item_location_overrides` CRUD, and any Location-scoped read
   this slice builds) — `LocationAccessGuard::
   assertUserCanAccessLocation($userId, $location)`, called fresh on every
   write, the existing mechanism, unmodified, per Addendum §4's "never
   trust a route-bound model" rule. A cross-Business override attempt (a
   Location belonging to a different Business than the catalog item) is
   refused by a plain FK-domain-integrity check (the Location's own
   `business_id` must equal the catalog item's `business_id`) **before**
   `LocationAccessGuard` is even consulted — not an ACL failure, a
   data-integrity one, exactly as the original draft already specified.

`package_snapshots` is write-once by the service that creates them (§5.3,
§12.D); the service itself performs no authorization of its own — its
caller (this slice's own tests today; Slice 17's own controllers once they
exist) is responsible for having already passed gates 1–4 for the
underlying action being snapshotted. Read authorization for a snapshot
follows whatever actor is allowed to view the transactional document that
references it — a concern for Slice 17, not this contract.

**Adversarial coverage this contract requires** (§13): capability granted
without tenancy → refused; tenancy present without capability → refused;
capability and tenancy both present but the feature is still `Planned`/
unentitled → refused; a guessed foreign Business uid → refused at gate 1;
a guessed foreign Location uid (belonging to a different Business) →
refused by the FK-domain-integrity check ahead of gate 4; a granted
capability and valid tenancy and an `Available` entitlement together
succeed. No single test may substitute for proving each gate independently
— a test that only ever grants all four together cannot show that any one
of them is actually being checked.

## 7. Transaction / concurrency boundary

**One serialization seam, stated as a single rule rather than three
separate "no race" claims** (the original draft's claim that snapshot
creation has no shared mutable state to race was false — a snapshot reads
the catalog item's own price/lifecycle state, its Location override, and
its currency, all of which another request can be mutating at the same
moment):

> **Every write path that can change snapshot-relevant catalog-item state
> — an item edit, an archive/reactivate, a Location override enable/
> disable, or a Location price override — must first take an exclusive
> lock on the authoritative `catalog_items` row (`lockForUpdate()`, inside
> a DB transaction) before reading or writing anything derived from it.
> `PackageSnapshotService::snapshot()` takes the same lock before it reads
> anything.** Because every mutator and the snapshot service all
> serialize through that one row's lock, a snapshot can never observe a
> half-applied combination of old-price/new-lifecycle or old-override/
> new-price — it observes either the fully-old or the fully-new coherent
> state, whichever transaction commits first.

Concretely, `PackageSnapshotService::snapshot()`'s required sequence
(§12.D):

1. begin a DB transaction;
2. re-load and `lockForUpdate()` the authoritative `catalog_items` row —
   never trust a caller-supplied Eloquent model's already-loaded fields,
   per Addendum §4's "never trust a route-bound model" rule, extended
   here to "never trust a passed-in model" generally;
3. re-derive the authoritative Business from the locked row;
4. re-derive the authoritative Location from the caller-supplied
   `BusinessLocation` (§5.3 — required, not optional) and re-verify it
   still belongs to the same Business as the locked catalog item (the
   same FK-domain-integrity check §6 requires elsewhere);
5. verify the item is still active (not archived);
6. read the Location's override row, still inside the same transaction and
   still under the item-row lock;
7. verify the item is enabled at that Location (§5.2's resolver
   invariants);
8. resolve the exact effective price and currency per §6's explicit-price
   rule;
9. insert the immutable `package_snapshots` row;
10. commit.

Two ordinary concerns remain, both already precedented and unaffected by
the seam above:

- **Reordering** (`position`, §5.1): mirrors `CrmPipelineService::
  applyOrder()`'s exact algorithm — the caller submits the complete
  ordered list of a Business's active catalog-item ids, and every row is
  rewritten to its 0-based array index inside one transaction, with the
  full active set locked (`lockForUpdate()`, scoped by `business_id`) for
  the duration of the rewrite. This is itself one instance of the same
  serialization rule above (a reorder is a catalog-item mutation).
- **`catalog_item_location_overrides` upsert**: the `unique(catalog_
  item_id, business_location_id)` DB constraint (§5.2) remains the
  concurrency mechanism for two concurrent writes to the *same* override
  row; the catalog-item lock above is what serializes an override write
  against a concurrent item edit/archive/snapshot, which the unique
  constraint alone cannot do.

**Required adversarial tests** (§13, Sub-slice D): a snapshot racing a
concurrent catalog-item price edit; a snapshot racing a concurrent
Location-override edit; a snapshot racing a concurrent archive; each
proving the snapshot's own recorded state is always one coherent
before-or-after combination, never a mix of fields from two different
transactions.

## 8. Migration / backfill

None for `catalog_items`/`catalog_item_location_overrides`/`package_
snapshots` — all new, no predecessor rows, built in Sub-slice A. Sub-slice
A **also** requires a **new** migration to backfill the `platform_feature_
usage_classifications` row for the new `PlatformFeature::PackagesProducts`
case — moved here from the original draft's Sub-slice E per §11's
corrected sequencing, since RFC-004 requires the feature's inert identity
to exist before any dependent sub-slice builds domain code (§11, §12.A).
The existing backfill migration is never edited, per this codebase's own
stated discipline for that exact scenario. Sub-slice E adds no new
migration of its own — only the registry flip, the capability config
entry, and the nav wiring (§12.E).

## 9. Backwards compatibility

Not applicable in the retire-an-old-model sense — pure net-new addition.
Explicit non-interaction, stated so a future reader doesn't assume
otherwise: the legacy `plans`/`invoices`/`customer_based_pricing_plans`
tables (§3.2/§4) are not modified, extended, or migrated by this slice.

## 10. Events / audit

No events are strictly required by any governing document for this
slice (contrast Slice 15, where Blueprint §12 explicitly names five
lifecycle events for Automations §13 consumption — no equivalent
sentence exists in Blueprint §17 or Addendum §14). This contract adds
none speculatively; if a future Automations trigger needs "catalog item
archived" or similar, that is new product behavior requiring its own
authorization, not inferred here.

**Corrected wording**: `created_at`/`updated_at` on `catalog_items` and
`catalog_item_location_overrides` are **not** an audit trail — they are
ordinary row-timing columns, present on every table in this schema, and
they record only the current row's own last-write time, not a history of
what it used to be or who changed what. No dedicated mutation audit/
history table is required by any current governing product authority for
these two tables, and this contract does not add one speculatively to
paper over that absence. The one place true immutable provenance matters
— the actual package/price used in a real transaction — is
`package_snapshots` itself (§5.3): that table *is* the provenance record,
by construction, precisely because it is never updated after insert; it
is not "audit" in the mutation-history sense, it is the authoritative
transactional fact.

## 11. Billing/provider safety

**Entitlement, corrected sequencing (§12)**: unlike Calendar, no
`PlatformFeature` case existed for this catalog before this contract.
Per RFC-004, a planned/unbuilt feature must never become customer-
executable through plan mapping or a direct route — so **Sub-slice A**,
not Sub-slice E, introduces `PlatformFeature::PackagesProducts =
'packages_products'` (or equivalent), a `PlatformFeatureRegistry` entry
starting at `Planned`, the required new backfill migration (§8), and a
`workspace_plan_features` seed row. Blueprint §21 places Packages &
Products in **all three** plan tiers (Core, Growth, Agency) — the seed
row must reflect that, not a higher-tier gate. Because
`PlatformFeatureRegistry` checks availability before plan mapping,
nothing customer-executable can pass entitlement while the case stays
`Planned` — which is exactly the state it stays in through Sub-slices
B/C/D, since none of them build a customer HTTP surface (§12). **Sub-
slice E** owns the customer HTTP/UI/routes/nav, the `packages_products`
capability config entry (§6), `EntitlementManager` enforcement at every
route, and the **final** `Planned`→`Available` flip — performed only
once Sub-slices A–D are merged and the catalog is genuinely usable
end-to-end (mirroring the same precedent Contract 15 follows for
Calendar), never before.

**No payment/wallet side effect of any kind** in this slice — creating,
pricing, enabling, or archiving a catalog item never touches
`UsageWalletManager` or any billing path; money here is descriptive
(what a Business *could* charge), not a transaction. Money only becomes
a real paid side effect once Slice 17 issues a proposal/invoice against a
snapshot — outside this contract's scope entirely.

## 12. Exact implementation allowlist — five dependency-ordered sub-slices

**Corrected shape**: Sub-slices B and C now build domain managers only —
**no customer HTTP/UI of any kind** — so that no executable route can
exist before Sub-slice E's capability/entitlement gates are in place
(§6, §11). This is the direct fix for RFC-004's ordering rule: the
original draft let B and C ship live controllers/routes while the
`PlatformFeature` case did not yet exist at all, which would have made
the catalog reachable with no entitlement gate whatsoever for however
long B/C were merged ahead of E.

### Sub-slice A — Schema/domain foundation + inert entitlement identity

- **Files/domains**: migrations for the three tables (§5); `CatalogItem`,
  `CatalogItemLocationOverride`, `PackageSnapshot` Eloquent models with
  casts/relations only (no services, controllers, or views); the new
  `PlatformFeature::PackagesProducts` case; a new `PlatformFeatureRegistry`
  entry starting at `Planned`; the new backfill migration for
  `platform_feature_usage_classifications` (§8, never editing the
  existing merged one); a new `workspace_plan_features` seed row covering
  Core, Growth and Agency (§11).
- **Prerequisites**: none beyond Contracts 1–14 (already merged).
- **Schema**: exactly §5.1–§5.3, plus the one entitlement backfill
  migration named above.
- **Tenancy/security**: N/A at this layer (no read/write paths exposed
  yet, and the entitlement gate stays `Planned`, so nothing here is
  customer-executable regardless).
- **Concurrency**: N/A (no write paths yet).
- **Tests**: migration/constraint existence tests (FK `restrictOnDelete`/
  `cascadeOnDelete` exactly as §5 specifies — `unsignedBigInteger` on
  every money column, `NOT NULL` on `package_snapshots.business_location_id`,
  nullable+`nullOnDelete` on `package_snapshots.created_by_user_id` — and
  the `unique` constraints; the co-nullable `price_minor`/`currency_code`
  pair on `catalog_items` is NOT enforced at the DB layer in this
  sub-slice, that check belongs to Sub-slice B's service, tested there);
  model factory smoke tests; `PlatformFeatureRegistryTest`-style
  confirmation the new case exists and starts `Planned`.
- **Risk**: Low.
- **Model**: Sonnet 5 sufficient.

### Sub-slice B — Catalog domain manager (Business-wide CRUD + reorder; no customer HTTP/UI)

- **Files/domains**: `CatalogItemManager` (or equivalent) in
  `app/Library/Catalog/`, enforcing the `price_minor`/`currency_code`
  co-nullable invariant (§5.1), the `archive()`/`reactivate()`-only write
  path for `lifecycle_state`/`archived_at` (mirroring `BusinessLocation`
  exactly, never allowing those columns through ordinary mass-assignment),
  and — per §7's corrected concurrency rule — every mutation (`create`,
  `update`, `archive`, `reactivate`, `reorder`) taking `lockForUpdate()` on
  the affected `catalog_items` row(s) first. **No controller, route, or
  view in this sub-slice** — this is domain-service code only, exercised
  by its own tests directly, sitting behind Sub-slice A's still-`Planned`
  entitlement gate.
- **Prerequisites**: A (hard).
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: **corrected** — this manager takes no actor
  parameter and performs no authentication-derived authorization at all.
  Its own, narrower duty is domain integrity: every method re-derives the
  `CatalogItem` fresh from persistence, under lock, and proves it belongs
  to the given `Business`, never trusting a caller-supplied model. §6's
  ordinary Business-wide tenancy check (active `WorkspaceMembership`,
  Workspace owner), the `packages_products` capability check, and
  `EntitlementManager` enforcement are all Sub-slice E controller
  responsibilities — none of the three exists at this layer, and this
  sub-slice's own tests exercise the domain-integrity re-derivation
  described above, not authenticated tenancy.
- **Concurrency**: §7's reorder algorithm and the catalog-item
  serialization lock, implemented and tested here for every mutation this
  manager exposes.
- **Tests**: manager-level CRUD correctness; the domain-integrity
  refusal — a `CatalogItem` that does not belong to the given `Business`
  (however it was obtained) must never be read, mutated, or trusted on
  any field except its id (**corrected**: this is a re-derivation-from-
  persistence test, not an authenticated-actor/role test — no actor
  parameter exists at this layer, per the Tenancy/security correction
  above); reorder correctness under a submitted full ordered list,
  including the "list must contain every active item exactly once"
  validation (mirroring `CrmPipelineService`'s own validation);
  archive/reactivate round-trip; the co-nullable price/currency invariant
  rejected when violated; a concurrent edit-vs-edit test proving the
  `lockForUpdate()` serialization actually blocks/serializes rather than
  racing.
- **Risk**: Low.
- **Model**: Sonnet 5 sufficient.

### Sub-slice C — Location override manager + pricing resolution (no customer HTTP/UI)

- **Files/domains**: `CatalogItemLocationOverrideManager` (or
  equivalent) for override CRUD, taking the same `catalog_items`-row
  `lockForUpdate()` before mutating an override (§7); `CatalogItemPricingResolver`
  (§5.2) implementing the active/enabled invariants **and** the
  enable/override/default resolution logic as the one place this logic
  lives. **No controller, route, or view in this sub-slice** — same
  reasoning as Sub-slice B.
- **Prerequisites**: A, B (hard — needs catalog items to exist before
  they can be overridden per Location).
- **Schema**: none new — consumes A's `catalog_item_location_overrides`.
- **Tenancy/security**: **corrected** — like Sub-slice B's manager, this
  manager takes no actor parameter and calls `LocationAccessGuard` at no
  point; it does not exercise §6's Location-scoped chain itself. Its own
  duty is domain integrity only: re-derive the `CatalogItem` fresh under
  lock and prove it belongs to the given `Business`, then re-derive the
  `BusinessLocation` fresh and prove IT belongs to that SAME Business (the
  FK-domain-integrity check) — never trusting either object a caller
  supplies. `LocationAccessGuard::assertUserCanAccessLocation()` is
  mandatory at the Sub-slice E HTTP boundary for every customer-reachable
  Location override route once one exists (§6 is unchanged and is not
  weakened by this correction); the `packages_products` capability and
  `EntitlementManager` gates are likewise Sub-slice E boundary
  responsibilities, not this manager's.
- **Concurrency**: §7's catalog-item lock, taken by every override
  mutation before it touches the override row; the `unique(catalog_
  item_id, business_location_id)` constraint remains the mechanism for
  two concurrent writes to the same override pair.
- **Tests**: override CRUD correctness; the domain-integrity refusals —
  a foreign `CatalogItem` and a `BusinessLocation` belonging to a
  different Business than the catalog item (**corrected**: exercised as
  re-derivation-from-persistence tests against this manager directly, not
  as a `LocationAccessGuard`/actor-ACL test — that guard is exercised by
  Sub-slice E's own controller tests instead, once those controllers
  exist); `CatalogItemPricingResolver`
  correctness across all three resolution cases (no override row →
  Business default; override row with `is_enabled=false` → not offered;
  override row with a `price_minor_override` → that price) **and** its
  active/enabled invariants (archived item → refused; foreign-Business
  Location → refused; disabled-at-Location → reported as not offered,
  never silently resolved to a price); the sparse-table "absence means
  enabled at default" semantics stated explicitly in §5.2, exercised as
  this contract's locked implementation default, not tested as an open
  question.
- **Risk**: Low.
- **Model**: Sonnet 5 sufficient.

### Sub-slice D — Immutable snapshot service

- **Files/domains**: `PackageSnapshotService` (or equivalent) in
  `app/Library/Catalog/`, exposing one method —
  `snapshot(CatalogItem $item, BusinessLocation $location, ?User $actor =
  null, ?int $explicitPriceMinor = null): PackageSnapshot` — implementing
  §7's exact locked sequence (lock the catalog-item row, re-derive
  Business and Location, verify the Location belongs to the Business,
  verify the item is active and enabled at that Location, resolve the
  effective price per §6's explicit-price rule, insert one immutable
  `package_snapshots` row, commit, never update it afterward). `$location`
  is required, never optional (§5.3, corrected). `$actor` is nullable, for
  the public/system snapshot case (§5.3, corrected). `$explicitPriceMinor`
  is accepted **only** when the resolver reports the item quote-only at
  that Location (no canonical fixed price exists) — supplying it when a
  canonical price already resolved is a refusal, not a silent override
  (§6). Deliberately kept small and isolated so Slice 17 has a stable,
  minimal dependency to build against without waiting on UI work.
- **Prerequisites**: A, B, C (hard).
- **Schema**: none new — consumes A's `package_snapshots`.
- **Tenancy/security**: the service itself performs no authorization —
  callers (this slice's own tests, and eventually Slice 17) are
  responsible for having already passed §6's gates for the underlying
  action; the service's only job is producing a correct, immutable
  record under §7's locked, coherent sequence.
- **Concurrency**: §7's full locked sequence — the one place in this
  contract where correctness depends on it, and where the adversarial
  concurrency tests below live.
- **Tests**: snapshot correctness (every field matches the resolved
  state at the moment of the call, including `business_location_id`
  always populated and `currency_code_at_snapshot` sourced from the
  correct one of the two paths in §5.3); immutability, proved the same
  way its `website_revisions` precedent is proved — a source-boundary
  test confirming no production code path calls `update()`/`save()` on an
  existing `PackageSnapshot` row (never a test whose only assertion is
  "we personally did not call update" in the test itself); the "no fixed
  price, no explicit price supplied" refusal case; the "canonical price
  exists, explicit price supplied anyway" refusal case (§6); a snapshot
  taken before and after a later catalog-item price change proves the
  earlier snapshot is unaffected (the direct proof of Blueprint §17's own
  acceptance language); the three §7 adversarial concurrency tests
  (snapshot racing a price edit, an override edit, and an archive — each
  proving the recorded state is always one coherent combination, never
  mixed).
- **Risk**: Low — the one place correctness genuinely matters most in
  this contract (Slice 17 will trust this completely), but the
  mechanism itself is a simple, fully precedented, now fully specified
  locked write-once insert.
- **Model**: Sonnet 5 sufficient.

### Sub-slice E — Customer HTTP/UI + permission gate + entitlement gate + final flip

- **Files/domains**: authenticated controllers/routes/Blade views for
  catalog CRUD/reorder (fronting Sub-slice B's manager) and per-Location
  enable/disable/price-override management (fronting Sub-slice C's
  manager and resolver — likely surfaced from within the Location
  management UI, not the catalog list; exact placement is a UI decision
  for this sub-slice, not predetermined here); the new `packages_products`
  entry in `config/customer-permissions.php` (the simple single-key
  shape §3.3/§6 describe); every new controller enforcing §6's full gate
  chain (tenancy → capability → entitlement, plus `LocationAccessGuard`
  for Location-scoped actions) in that order; nav entry via
  `CustomerMenuBuilder` (adding the feature key to
  `ENTITLEMENT_GATED_FEATURES`, mirroring the exact pattern used for
  Calendar in Contract 15 §12.D); and the final `Planned`→`Available`
  flip, performed only once A–D are merged and verified end-to-end.
- **Prerequisites**: A, B, C, D (hard — the flip must not happen before
  the feature is genuinely usable, §11, and no route in this sub-slice
  may be merged ahead of the capability/entitlement gates it depends on).
- **Schema**: none — the entitlement identity/migration already landed in
  Sub-slice A (§8, §11, corrected); this sub-slice only adds the
  `config/customer-permissions.php` entry, which is not a migration.
- **Tenancy/security**: this sub-slice is where §6's full gate chain is
  first exercised end-to-end over real HTTP: tenant authorization,
  `packages_products` capability, `EntitlementManager`, and
  `LocationAccessGuard` for Location-scoped routes — every gate checked
  independently, per §6's adversarial-coverage requirement.
- **Concurrency**: none new — routes call straight through to Sub-slices
  B/C/D's already-locked managers/service.
- **Tests**: full §6 adversarial matrix over real HTTP routes (capability
  without tenancy; tenancy without capability; both present but feature
  `Planned`/unentitled; guessed foreign Business; guessed foreign
  Location; all four gates satisfied together succeeds);
  `PlatformFeatureRegistryTest`-style entitlement tests (the feature
  starts `Planned`/unavailable, then `Available` after the flip); nav-
  visibility tests per plan tier (all three tiers see the item once
  available, per Blueprint §21).
- **Risk**: Low — mechanical wiring over already-tested domain code, but
  the one sub-slice where an authorization mistake would be immediately
  customer-facing, hence the explicit adversarial matrix above.
- **Model**: Sonnet 5 sufficient.

## 13. Required tests

Beyond each sub-slice's own tests (§12): once D ships, an end-to-end test
proving Blueprint §17's exact acceptance language — a catalog item's
price changes after a snapshot was taken, and the snapshot's own
`price_minor_at_snapshot` is provably unaffected. The §7 concurrency
adversarial set (snapshot racing a price edit / a Location-override edit
/ an archive) and the §6 adversarial authorization matrix (capability
without tenancy, tenancy without capability, both present but
unentitled, guessed foreign Business, guessed foreign Location) are both
required, not optional, given §12.D and §12.E's own test lists already
name them. No cross-domain regression suite is required beyond the
Location-ACL domain tests already covered by Sub-slice C's own suite
(this slice does not touch Workspace, Agency, Conversations, Contacts, or
Opportunities — Contract 14's own five-domain regression is not re-run
here, since nothing in those domains is modified).

## 14. Acceptance criteria

1. Exactly one canonical, Business-wide catalog exists per Business — no
   per-Location duplication of a catalog item's own row (§5.1, §5.2).
2. A Location can enable/disable a catalog item and set an optional
   price override without altering the Business-wide default (§5.2, §7).
3. Every `package_snapshots` row is genuinely immutable — no production
   code path updates one after insert (§5.3, §12.D's source-boundary
   test).
4. A later change to a catalog item's price or a Location's override
   never alters an already-created snapshot, including under concurrent
   mutation (§7, §13's end-to-end and adversarial proofs).
5. `LocationAccessGuard` is the only Location-authorization mechanism
   used anywhere in this slice (§6) — no parallel ACL logic.
6. Every customer-reachable write requires tenant authorization, the
   `packages_products` capability, and (once flipped) platform
   entitlement — each independently provable, none substituting for
   another (§6, §13).
7. `PlatformFeature::PackagesProducts` exists and stays `Planned` from
   Sub-slice A onward, and is flipped to `Available` only after
   Sub-slices A–D are merged and verified (§11, §12).
8. A snapshot's `business_location_id` is always populated with the
   transaction's own Location, never null (§5.3).
9. An explicit price is accepted only when no canonical fixed price
   resolves for the item at that Location; when one does, it is always
   used, never silently replaced by a caller-supplied amount (§6).
10. `git diff --check` clean and a clean working tree at the end of each
    sub-slice's own commit.

## 15. Non-goals

- **Package-contains-Products bundling** — no authoritative document
  describes this relationship; not built (§5.1).
- **Per-Location currency divergence** — currency is a Business-wide
  property; overrides change amount only (§5.2).
- **Any Automations event for catalog lifecycle** — no document
  requires one, unlike Calendar's explicit five-event requirement
  (§10).
- **A CRUD-matrix of separate catalog permissions** (create vs. edit vs.
  archive vs. reorder vs. override, each its own capability key) — the
  Acceptance Matrix's boundary is one feature-level permission, and this
  contract does not invent a larger surface than that (§6).
- **A negotiated-price/discount system** — an explicit price at snapshot
  time is accepted only for a genuinely quote-only item with no canonical
  fixed price; it is never a caller's licence to override a resolved
  Business-wide or Location price (§6).
- **Opt-in-by-default Location enablement** — the sparse-override,
  enabled-by-default design is this contract's locked V1 default, not an
  open question (§5.2); changing it later is a product behavior change.
- **Building Slice 17 (Proposal/Contract/e-signature)** or any part of
  it — this contract produces only the snapshot mechanism Slice 17 will
  later consume.
- **Migrating or touching the legacy `plans`/`invoices`/`customer_
  based_pricing_plans` tables**, or `workspace_plan_catalog` (the
  platform's own unrelated subscription-tier catalog) — a different
  bounded context, untouched (§4).
- **Reopening or modifying the Workspace/Agency tenancy migration**
  (Contracts 1–14) in any way.

## 16. Merge prerequisites

None hard at the whole-slice level — Slice 16 is explicitly independent
of Slices 1–14 per the Roadmap, beyond those already being merged (they
are, as of `3dbb1e11`). Per-sub-slice hard prerequisites are stated in
each block of §12 (A before B before C before D before E — corrected:
E now depends on A **and** D, not merely "A–D" loosely, since E is the
first sub-slice to expose any customer-executable surface at all and must
not merge ahead of any of the domain code it fronts).

## 17. Conflict map

| Other work | Shared file/table | Posture |
|---|---|---|
| Slice 15 (Calendar) | none — Roadmap explicitly notes 15–18 "do not touch `WorkspaceManager`, `CustomerAccountAccessResolver`, `EntitlementManager`, or the Agency relationship table"; both slices independently add their own `CustomerMenuBuilder`/`ENTITLEMENT_GATED_FEATURES` lines, an ordinary low-conflict merge, not a hard serialization | Parallel-safe |
| Slice 15 (Calendar) — cross-slice snapshot rule, stated precisely | Slice 15's current `Booking` model does not reference `CatalogItem` at all, so Slice 15 remains fully parallel-safe with this contract as written; Blueprint §17's snapshot obligation is scoped narrowly to "every proposal, invoice, or booking **that references a package**" (§3.1) — not to every booking unconditionally. **If a future change adds package selection/reference to Calendar's booking flow, that integration cannot ship without also creating and storing this contract's immutable `package_snapshots` row** — it would become a hard consumer of Sub-slice D at that point, exactly like Slice 17 below, and would need its own explicit authorization to add that reference. Documented here precisely so neither a future Calendar change nor a future reader of this contract mistakes "Slice 15 is parallel-safe today" for "Slice 15 can add package references without this contract's snapshot mechanism." | Parallel-safe today; conditional hard dependency the moment Calendar references a package |
| Slice 17 (Proposal/Contract/e-signature) | `package_snapshots` (read-only consumer, once Slice 17 exists) | Serialize only in the sense that Slice 17 must land after Sub-slice D — not a file conflict, since Slice 17 has no files yet. Slice 17 remains the first **known** hard consumer of the snapshot service; Calendar is a conditional future one (see row above). |
| `CustomerMenuBuilder.php` (`ENTITLEMENT_GATED_FEATURES`, §12.E) | additive line, same low-conflict shape as every prior slice's own nav addition | Low risk |
| `config/customer-permissions.php` (`packages_products`, §12.E) | additive single-key entry, same low-conflict shape as every prior feature's own capability addition | Low risk |

## 18. Implementation prompts

Each sub-slice is handed to a fresh session independently, once
explicitly authorized. Every prompt below assumes Contracts 1–14 and
every lower-lettered Sub-slice already merged to `main`.

### 18.A — Schema/domain foundation + inert entitlement identity

```
You are implementing Sub-slice A of Slice 16 (Packages & Products
catalog) for the os-creator1/os-ai repository, per docs/product/
implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md SS5, SS8, SS11
and SS12.A. This is schema/model plus inert entitlement plumbing only --
no controllers, routes, or UI, and the feature must remain unreachable by
any customer request when you are done.

Before writing code:
1. Fetch latest origin/main and verify it is at or after 3dbb1e11.
2. Re-read SS5 (Canonical domain model), SS8, SS11, and SS12.A in full.
3. Create a fresh worktree/branch for this sub-slice only (e.g.
   agent/v1-slice16a-catalog-schema).

Implement exactly the three tables in SS5.1-SS5.3, as migrations, plus
their Eloquent models with casts/relations only. Follow this repository's
existing conventions precisely: restrictOnDelete vs cascadeOnDelete
exactly as SS5 specifies, every money column unsignedBigInteger (never
signed bigInteger -- confirm against the actual CrmOpportunity migration
before writing your own), package_snapshots.business_location_id NOT
NULL, package_snapshots.created_by_user_id nullable with nullOnDelete,
lifecycle_state/archived_at NOT fillable on CatalogItem (mirroring
BusinessLocation), const UPDATED_AT = null on PackageSnapshot (mirroring
WebsiteRevision), no soft-delete trait anywhere.

Also add the new PlatformFeature::PackagesProducts case, a
PlatformFeatureRegistry entry starting at Planned, a NEW migration
backfilling platform_feature_usage_classifications for it (read the
MessagingTransport docblock in app/Enums/Entitlement/PlatformFeature.php
first -- do NOT edit the existing merged backfill migration), and a
workspace_plan_features seed row covering Core, Growth and Agency. The
feature must stay Planned -- do not add any controller, route, or
customer-facing surface that could make it reachable.

Do not write any controller, route, service class, or view in this
sub-slice.

Tests: migration/constraint existence tests (including the corrected
unsignedBigInteger/NOT NULL/nullable points above), model factory smoke
tests, a PlatformFeatureRegistryTest-style check that the new case exists
and starts Planned.

After implementing: run the new tests, run `git diff --check`, commit,
and push to the fresh branch. Do NOT create a pull request. Do NOT merge.
Return: starting/final SHA, exact files created, exact tests run and
counts, confirmation every FK/constraint matches SS5 exactly and the
feature is Planned and unreachable.
```

### 18.B — Catalog domain manager (no customer HTTP/UI)

```
You are implementing Sub-slice B of Slice 16, per docs/product/
implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md SS12.B. Hard
prerequisite: Sub-slice A merged. This sub-slice builds a domain SERVICE
only -- no controller, route, or view. Do not expose any customer-facing
surface; the feature's PlatformFeature case must remain Planned and
untouched by this sub-slice.

Implement CatalogItemManager enforcing SS5.1's price_minor/currency_code
co-nullable invariant and the archive()/reactivate()-only write path for
lifecycle_state/archived_at (mirroring BusinessLocation's repository
exactly -- read app/Repositories/Eloquent/EloquentBusinessLocationRepository.php
first). Implement the SS7 reorder algorithm (mirroring
CrmPipelineService::applyOrder() -- read app/Library/Crm/CrmPipelineService.php
first) AND SS7's general catalog-item serialization rule: every mutation
this manager exposes (create, update, archive, reactivate, reorder) must
take lockForUpdate() on the affected catalog_items row(s) inside a DB
transaction before reading or writing anything derived from them.

Tests per SS12.B, including a concurrent edit-vs-edit test proving the
lockForUpdate() serialization actually serializes. Run tests, git diff
--check, commit, push to a fresh branch off A's merged state. Do NOT
create a PR. Do NOT merge. Return: SHA, files, test counts, confirmation
no controller/route/view was added.
```

### 18.C — Location override manager + pricing resolution (no customer HTTP/UI)

```
You are implementing Sub-slice C of Slice 16, per docs/product/
implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md SS12.C. Hard
prerequisites: Sub-slices A and B merged. This sub-slice builds domain
SERVICES only -- no controller, route, or view. The feature's
PlatformFeature case must remain Planned.

Implement override CRUD taking the same catalog_items-row lockForUpdate()
(SS7) before mutating an override row, then authorized via
LocationAccessGuard::assertUserCanAccessLocation() plus the
FK-domain-integrity check that the Location's business_id matches the
catalog item's (SS6 -- integrity check before the ACL call, not after).
Implement CatalogItemPricingResolver per SS5.2: it must FIRST refuse when
the item is archived or the Location belongs to a different Business,
report "not offered" when an override row has is_enabled=false, and only
then resolve price_minor_override -> the item's own price_minor -> no
fixed price (quote-only). No caller may bypass these invariants.

Tests per SS12.C, especially the sparse-table "absence means enabled at
default" semantics (exercised as this contract's locked default, not an
open question) and the cross-Business override refusal happening before
the ACL check. Run tests, git diff --check, commit, push to a fresh
branch. Do NOT create a PR. Do NOT merge. Return: SHA, files, test
counts, confirmation no controller/route/view was added.
```

### 18.D — Immutable snapshot service

```
You are implementing Sub-slice D of Slice 16, per docs/product/
implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md SS12.D -- the
piece Slice 17 will directly depend on, so correctness here matters more
than in any other sub-slice of this contract. Hard prerequisites:
Sub-slices A, B, C merged.

Implement PackageSnapshotService::snapshot(CatalogItem $item,
BusinessLocation $location, ?User $actor = null, ?int
$explicitPriceMinor = null): PackageSnapshot exactly per SS5.3/SS6/SS7:
$location is REQUIRED, never nullable. Inside one DB transaction: lock
the catalog_items row (lockForUpdate()), re-derive the authoritative
Business from the locked row, re-verify $location belongs to that
Business, verify the item is active and enabled at that Location via
Sub-slice C's CatalogItemPricingResolver, resolve the effective price --
using the resolver's canonical price when one exists (an $explicitPriceMinor
supplied in that case is a REFUSAL, not a silent override), or requiring
$explicitPriceMinor only when the item is genuinely quote-only at that
Location -- resolve currency per SS5.3 (catalog item's captured
currency_code when a canonical price applies; the Business's current
currency_code when the explicit-price quote-only path applies), insert
one immutable package_snapshots row, commit. Never update a
PackageSnapshot afterward -- no method on the model should permit a later
mutation, and no other production code path may call update()/save() on
one; prove this the same way the website_revisions precedent is proved
(a source-boundary test scanning for mutation call patterns, matching the
existing BusinessLocationBoundaryTest/T-LOC-9 technique -- read that test
first), not by asserting a stronger DB-level guarantee this codebase does
not actually have.

Tests per SS12.D, specifically including: a test that changes a catalog
item's price AFTER taking a snapshot and proves the snapshot's own
price_minor_at_snapshot is unaffected (the single most important test in
this contract); the "no fixed price, no explicit price supplied" refusal;
the "canonical price exists, explicit price supplied anyway" refusal; and
the three SS7 adversarial concurrency tests -- a snapshot racing a
concurrent price edit, a concurrent Location-override edit, and a
concurrent archive -- each proving the snapshot's recorded state is
always one coherent before-or-after combination, never mixed.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT
create a PR. Do NOT merge. Return: SHA, files, test counts, and explicit
confirmation the price-change-after-snapshot test and all three
concurrency adversarial tests passed.
```

### 18.E — Customer HTTP/UI + permission gate + entitlement gate + final flip

```
You are implementing Sub-slice E of Slice 16, per docs/product/
implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md SS6, SS11 and
SS12.E. Hard prerequisites: Sub-slices A, B, C, D merged. This is the
FIRST sub-slice of this contract permitted to add any controller, route,
or customer-facing view -- do not add one in any earlier sub-slice's
branch.

Add the new packages_products entry to config/customer-permissions.php
(the simple single-key shape automations/website already use --
display_name, category, default). Build authenticated controllers/
routes/Blade views for catalog CRUD/reorder (fronting Sub-slice B's
manager) and per-Location enable/disable/price-override management
(fronting Sub-slice C's manager/resolver). Every controller action must
check, in order: (1) ordinary tenant authorization to the resolved
Business/Location, (2) the packages_products capability, (3)
EntitlementManager for PlatformFeature::PackagesProducts, and (4) for
Location-scoped actions, LocationAccessGuard for the specific Location --
no gate substituting for another. Add the nav entry via
CustomerMenuBuilder, including adding the new feature key to
ENTITLEMENT_GATED_FEATURES (omitting this silently hides the item
forever, per the same lesson already documented in Contract 15).

Only as the LAST step, once you have verified Sub-slices A-D actually
work end-to-end, flip the registry entry from Planned to Available.

Tests per SS12.E and SS6's full adversarial matrix over real HTTP routes:
capability without tenancy refused; tenancy without capability refused;
both present but feature Planned/unentitled refused; a guessed foreign
Business uid refused; a guessed foreign Location uid refused; all four
gates satisfied together succeeds. Run tests, git diff --check, commit,
push to a fresh branch. Do NOT create a PR. Do NOT merge. Return: SHA,
files, test counts, and explicit confirmation of what you verified before
flipping the entitlement, plus confirmation every adversarial case in the
matrix above was tested independently.
```
