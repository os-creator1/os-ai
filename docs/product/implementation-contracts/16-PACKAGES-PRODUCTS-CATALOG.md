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
no hard concurrency invariant, no external-provider integration, and
every schema/domain decision below mirrors an existing, already-proven
convention in this codebase rather than inventing one.

## 1. Objective

Design and, across five dependency-ordered sub-slices, build the V1
Packages & Products catalog: one canonical Business-wide catalog, per-
Location enable/disable and optional price override, and an immutable
snapshot mechanism that Slice 17 (Proposal/Contract/e-signature) will
consume so a later catalog or price change can never retroactively alter
a past transactional document. Entirely net-new; does not build Slice 17
itself.

## 2. Governing authority

- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §17 (Packages & Products)
  and §5 (Business-Wide vs Location-Bound Matrix, which places "Package/
  Product catalog (§17)" explicitly in the Business-wide column).
- `docs/product/V1-IMPLEMENTATION-ROADMAP.md`, "Slices 15–18" entry —
  Slice 16 is Net-new, L complexity, Low risk, independent of Slices
  1–14 except for reusing Location/Business scoping.
- `docs/product/V1-AUTHORITY-TRACEABILITY-MATRIX.md` row 14 — "NOT YET
  IMPLEMENTED... Net-new; build Business-wide catalog + Location
  enable/override + immutable snapshot from the start."
- `docs/product/V1-ACCEPTANCE-MATRIX.md` row 31 ("Sell from a catalog,
  collect signed & paid agreements") — the "package/price immutably"
  snapshot requirement and "Owner + staff per feature permission"
  boundary.
- `docs/rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md` §14 (Packages/
  Products) — the sole, self-contained, three-sentence authority this
  entire contract implements verbatim (quoted in full, §3.1).

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
catalog; Location enable/override; immutable snapshot), nothing broader.

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

### 3.3 Reusable existing conventions — mirrored, not invented, in §5–§7

| Concern | Precedent | Reuse |
|---|---|---|
| Money representation | `CrmOpportunity.value_minor` (integer minor-unit, e.g. cents) + `currency_code char(3)` (plain string, no FK), migration docblock: *"the Business currency captured at creation so a later currency change does not silently re-denominate existing deals"* | The exact rationale this slice needs for its own price/currency snapshot. Chosen over Usage's `*_micro bigint + currency_id FK` convention (a different bounded context — sub-cent metered billing, not a static catalog price) and over `BusinessService.starting_price decimal(12,2)` (an earlier, superseded-in-spirit convention; the codebase moved to integer minor-units for money captured at a point in time after `BusinessService` was built). |
| Business's own currency | `businesses.currency_code` (NOT NULL, no default) | The single source of a catalog item's default currency; read directly (`$business->currency_code`), no shared resolver class exists to call into — this slice does not build one either, since none is needed for a single direct-read field. |
| Ordering | `CrmPipelineStage.position` (`unsignedSmallInteger default 0`) + `CrmPipelineService::applyOrder()`'s full-reindex-on-reorder (submit the complete ordered id list, rewrite every row to its 0-based array index in one pass), `orderBy('position')->orderBy('id')` as the canonical read order | Mirrored exactly for catalog items (§5.1, §7). |
| Archive vs delete | `BusinessLocation.lifecycle_state` (enum, not fillable) + `archived_at` (nullable timestamp, not fillable), with `archive()`/`reactivate()` as the *only* write path in the repository | Mirrored exactly (§5.1, §6) — never Laravel's native `SoftDeletes` (used exactly once in this codebase, for an unrelated file-upload registry, not a domain-entity pattern). |
| Immutable snapshot | `website_revisions` — dedicated table, `const UPDATED_AT = null`, no soft delete, no status column, denormalized copy of the mutable source at the moment of the event, docblock: *"write-once and immutable... nothing in the implementation may UPDATE a row in this table after insert"* | The literal shape §5.3's `package_snapshots` table mirrors — the closest and only true "row-level immutable copy of a mutable source" precedent in this codebase. |
| Location→Business FK shape | `contacts.location_id`/`chat_boxes.location_id` (`restrictOnDelete`, indexed) | Every FK to `business_locations` in this slice's schema follows the same `restrictOnDelete` convention uniformly (§5.2). |
| Business/Location-scoped write authorization | `LocationAccessGuard::assertUserCanAccessLocation()` (Location-scoped writes only); ordinary active `WorkspaceMembership` lookup (Business-wide writes) | §6 — no new ACL algorithm; the split between the two is itself a resolved design decision (§6). |
| Entitlement/nav wiring | `PlatformFeature` enum + `PlatformFeatureRegistry` + `CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES`, and the *"one row per PlatformFeature case, backfill migration throws if any case lacks one, merged migrations are not edited"* discipline (`app/Enums/Entitlement/PlatformFeature.php` docblock on `MessagingTransport`) | §11/§12.E — **unlike Calendar** (which already had a `Planned` `PlatformFeature::Calendar` case and a seeded `workspace_plan_features` row waiting to be flipped), grep confirms **no existing case for this catalog** — `PlatformFeature::AgencyPackageCapabilities` is a different, unrelated Agency-tier capability gate, not this feature. Sub-slice E must add the case, the registry entry, a **new** backfill-style migration for `platform_feature_usage_classifications` (never editing the existing merged one), and the `workspace_plan_features` seed row — genuinely more setup than Calendar's single-line flip, though still Low risk (fully mechanical, fully precedented). |

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
(§3.3). Money is `bigInteger` minor-units + `char(3)` currency code,
co-nullable as one invariant (§5.1).

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
price_minor                 bigInteger, nullable
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
must supply an explicit price at snapshot time (§5.3), never silently
default to zero.

No slug, no `unique(business_id, name)` — Blueprint does not require
unique catalog-item names, and this contract does not invent that
constraint.

### 5.2 `catalog_item_location_overrides`

**Sparse-override design, stated explicitly**: the table holds **only**
rows for Locations that deviate from the Business-wide default — no row
present for a given `(catalog_item_id, business_location_id)` pair means
the item is enabled at every ACL-authorized Location at the Business-wide
default price. This mirrors the existing Location-ACL pivot philosophy
(`workspace_membership_locations` stores only explicit grants, never a
row for every Location a member *could* reach) and is the more useful
default given the catalog is explicitly Business-wide (Blueprint §5): an
owner adding a new catalog item expects it usable everywhere immediately,
not hidden until each Location opts in. **This reading of Blueprint §17's
"may enable or disable" is the one genuinely ambiguous product decision
in this contract** — the alternative (opt-in, disabled everywhere by
default) is a one-line default-value change if the human disagrees;
flagged here rather than silently assumed.

```
id
catalog_item_id             FK -> catalog_items, cascadeOnDelete
business_location_id         FK -> business_locations, restrictOnDelete
is_enabled                    boolean, default true
price_minor_override            bigInteger, nullable
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

**Effective-price resolution** (the one small domain service this table
requires, consumed by §5.3 and by Sub-slice C's read paths): for a given
`(catalog_item, business_location)` pair — if an override row exists and
`is_enabled = false`, the item is not offered at that Location; else if
an override row exists with a non-null `price_minor_override`, that is
the effective price; else the catalog item's own `price_minor` (Business-
wide default) applies. This resolution logic lives in one place
(`CatalogItemPricingResolver` or equivalent) and is never duplicated.

### 5.3 `package_snapshots` — the immutable record Slice 17 will consume

Mirrors `website_revisions`'s exact write-once discipline (§3.3): no
`updated_at`, no soft delete, no status column, a denormalized copy of
every field a later transactional document needs, captured once and
never touched again.

```
id
uid                          uuid, unique
business_id                   FK -> businesses, restrictOnDelete, NOT NULL
catalog_item_id                 FK -> catalog_items, restrictOnDelete, NOT NULL
business_location_id             FK -> business_locations, restrictOnDelete, nullable
name_at_snapshot                   string(160)
description_at_snapshot             text, nullable
price_minor_at_snapshot               bigInteger, NOT NULL
currency_code_at_snapshot               char(3), NOT NULL
schema_version                            unsignedSmallInteger, default 1
created_by_user_id                          FK -> users, restrictOnDelete, NOT NULL
created_at                                    timestamp only

const UPDATED_AT = null   -- no updates, ever, after insert
-- no soft delete, no status column

index (catalog_item_id)
index (business_id)
```

**`business_location_id` is nullable, meaningfully**: it records *which*
Location's override (if any) produced `price_minor_at_snapshot` — null
means the Business-wide default price applied, with no Location-specific
override in effect at the moment of the snapshot. This is the one field
that could not simply be re-derived later even in principle (unlike
name/description/price, which are denormalized purely for immutability,
not because they couldn't otherwise be looked up) — it is itself part of
"the actual package/price actually used," per Blueprint §17's own
phrasing.

**`price_minor_at_snapshot`/`currency_code_at_snapshot` are NOT
nullable**, unlike the source `catalog_items.price_minor` — a snapshot
represents money that was *actually used* for a real transactional
document; if the source catalog item had no fixed price, the caller
(Slice 17, or any Sub-slice C/D caller) must supply an explicit resolved
price before a snapshot can be created. `PackageSnapshotService::
snapshot()` (§12.D) refuses to create a snapshot without one, rather than
writing a null into a column whose entire purpose is to be authoritative.

**No `package_items_at_snapshot` JSON blob** — since §5.1 explicitly does
not model bundling, there is no child-line-item structure to denormalize;
if Slice 17 needs to represent "N units of this catalog item on one
proposal," that quantity/line-item concept belongs to Slice 17's own
schema (an Order/Proposal line item referencing this snapshot's `uid`),
not to this table.

## 6. Authority / security contract

**Two distinct authorization paths, deliberately not unified into one**,
because the two tables have genuinely different scope:

- **`catalog_items` (Business-wide) CRUD**: any actor with an active
  `WorkspaceMembership` (Admin or Staff — role-blind, matching
  `LocationAccessGuard`'s own precedent of not distinguishing Admin from
  Staff, §3.3 of Contract 15) in the catalog item's own Business's
  Workspace, or that Workspace's owner. **No `LocationAccessGuard` call
  here** — a catalog item has no Location axis on its own row, and a
  Staff member restricted to one of five Locations should not be blocked
  from viewing/naming/pricing the Business-wide catalog, which is
  explicitly not Location data (Blueprint §5 matrix).
- **`catalog_item_location_overrides` CRUD**: `LocationAccessGuard::
  assertUserCanAccessLocation($userId, $location)` for the specific
  `business_location_id` being overridden, called fresh on every write —
  the existing mechanism, unmodified, per Addendum §4's "never trust a
  route-bound model" rule. A cross-Business override attempt (a Location
  belonging to a different Business than the catalog item) is refused by
  a plain FK-domain-integrity check (the Location's own `business_id`
  must equal the catalog item's `business_id`) before `LocationAccessGuard`
  is even consulted — not an ACL failure, a data-integrity one.
- **`package_snapshots`**: write-once by the service that creates them
  (§5.3, §12.D); read authorization for a snapshot follows whatever actor
  is allowed to view the transactional document that references it — a
  concern for Slice 17, not this contract, since no such document exists
  yet. This contract's own test surface (§13) only proves the snapshot
  service itself produces correct, immutable rows.

## 7. Transaction / concurrency boundary

**Genuinely low risk, unlike Slice 15** — no hard cross-record invariant
comparable to double-booking exists here. Two ordinary concerns, both
already precedented in this codebase:

- **Reordering** (`position`, §5.1): mirrors `CrmPipelineService::
  applyOrder()`'s exact algorithm — the caller submits the complete
  ordered list of a Business's active catalog-item ids, and every row is
  rewritten to its 0-based array index inside one transaction, with the
  full active set locked (`lockForUpdate()`, scoped by `business_id`) for
  the duration of the rewrite so a concurrent reorder or a concurrent
  archive can't interleave and produce a duplicate/gapped position.
- **`catalog_item_location_overrides` upsert**: the `unique(catalog_
  item_id, business_location_id)` DB constraint (§5.2) is the entire
  concurrency mechanism — a plain upsert (create-or-update inside a
  transaction) racing another request for the same pair resolves via
  ordinary DB-constraint conflict handling, no application-level lock
  needed, since (unlike Calendar's overlapping-interval problem) there is
  no "two different rows can conflict with each other" case here, only
  "the same row written twice," which the unique key already prevents.
- **`package_snapshots` creation**: a pure read-then-insert with no
  shared mutable state being raced — two simultaneous snapshots of the
  same catalog item at the same moment are each independently valid,
  correct, immutable rows; there is no conflict to prevent, unlike a
  booking slot. No locking required.

## 8. Migration / backfill

None for `catalog_items`/`catalog_item_location_overrides`/`package_
snapshots` — all new, no predecessor rows. Sub-slice E's entitlement
wiring requires a **new** migration to backfill the `platform_feature_
usage_classifications` row for the new `PlatformFeature` case (§3.3) —
the existing backfill migration is never edited, per this codebase's own
stated discipline for that exact scenario.

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
none speculatively. If a future Automations trigger needs "catalog item
archived" or similar, that is new product behavior requiring its own
authorization, not inferred here. Audit for `catalog_items`/`catalog_
item_location_overrides` mutations is the ordinary `created_at`/
`updated_at` trail already present on every table in this schema; audit
for the one place true immutability matters — the actual package/price
used in a transaction — is `package_snapshots` itself (§5.3), which is
the audit record by construction.

## 11. Billing/provider safety

**Entitlement**: unlike Calendar, no `PlatformFeature` case exists yet
for this catalog (§3.3) — Sub-slice E adds `PlatformFeature::
PackagesProducts = 'packages_products'` (or equivalent), a
`PlatformFeatureRegistry` entry starting at `Planned`, the required new
backfill migration (§8), and a `workspace_plan_features` seed row.
Blueprint §21 places Packages & Products in **all three** plan tiers
(Core, Growth, Agency) — the seed row must reflect that, not a
higher-tier gate. The `Planned`→`Available` flip happens only once
Sub-slices A–D are merged and the catalog is genuinely usable
end-to-end (mirroring the same precedent Contract 15 follows for
Calendar) — not before.

**No payment/wallet side effect of any kind** in this slice — creating,
pricing, enabling, or archiving a catalog item never touches
`UsageWalletManager` or any billing path; money here is descriptive
(what a Business *could* charge), not a transaction. Money only becomes
a real paid side effect once Slice 17 issues a proposal/invoice against a
snapshot — outside this contract's scope entirely.

## 12. Exact implementation allowlist — five dependency-ordered sub-slices

### Sub-slice A — Schema/domain foundation

- **Files/domains**: migrations for the three tables (§5); `CatalogItem`,
  `CatalogItemLocationOverride`, `PackageSnapshot` Eloquent models with
  casts/relations only (no services, controllers, or routes).
- **Prerequisites**: none beyond Contracts 1–14 (already merged).
- **Schema**: exactly §5.1–§5.3.
- **Tenancy/security**: N/A at this layer (no read/write paths exposed
  yet).
- **Concurrency**: N/A (no write paths yet).
- **Tests**: migration/constraint existence tests (FK `restrictOnDelete`/
  `cascadeOnDelete` exactly as §5 specifies, the `unique` constraints,
  the co-nullable `price_minor`/`currency_code` pair is NOT enforced at
  the DB layer in this sub-slice — that check belongs to Sub-slice B's
  service, tested there), model factory smoke tests.
- **Risk**: Low.
- **Model**: Sonnet 5 sufficient.

### Sub-slice B — Catalog management (Business-wide CRUD + reorder)

- **Files/domains**: `CatalogItemManager` (or equivalent) in
  `app/Library/Catalog/`, enforcing the `price_minor`/`currency_code`
  co-nullable invariant (§5.1) and the `archive()`/`reactivate()`-only
  write path for `lifecycle_state`/`archived_at` (mirroring `BusinessLocation`
  exactly, never allowing those columns through ordinary mass-assignment);
  authenticated controllers/routes for create/edit/archive/reactivate/
  reorder; Blade views (list/create/edit — no Location-override UI yet).
- **Prerequisites**: A (hard).
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: §6's Business-wide path — active `WorkspaceMembership`
  or Workspace owner, no `LocationAccessGuard` call.
- **Concurrency**: §7's reorder algorithm (mirroring `CrmPipelineService::
  applyOrder()` exactly), implemented and tested here.
- **Tests**: CRUD × role (owner/admin/staff, all should succeed — role-
  blind per §6) × cross-Workspace boundary (a member of a different
  Workspace must never see or mutate another Business's catalog);
  reorder correctness under a submitted full ordered list, including the
  "list must contain every active item exactly once" validation
  (mirroring `CrmPipelineService`'s own validation); archive/reactivate
  round-trip; the co-nullable price/currency invariant rejected when
  violated.
- **Risk**: Low.
- **Model**: Sonnet 5 sufficient.

### Sub-slice C — Location enable/override + pricing resolution

- **Files/domains**: `CatalogItemLocationOverrideManager` (or
  equivalent) for override CRUD; `CatalogItemPricingResolver` (§5.2)
  implementing the enable/override/default resolution logic as the one
  place this logic lives; authenticated controller/routes/views for
  per-Location enable/disable and price-override management (likely
  surfaced from within the Location management UI, not the catalog
  list — exact placement is a UI decision for this sub-slice, not
  predetermined here).
- **Prerequisites**: A, B (hard — needs catalog items to exist before
  they can be overridden per Location).
- **Schema**: none new — consumes A's `catalog_item_location_overrides`.
- **Tenancy/security**: §6's Location-scoped path —
  `LocationAccessGuard::assertUserCanAccessLocation()` on every write,
  plus the FK-domain-integrity check that the Location belongs to the
  same Business as the catalog item.
- **Concurrency**: §7's upsert-via-unique-constraint; no new mechanism.
- **Tests**: override CRUD × Location-ACL boundary (granted vs
  ungranted Location → 404, matching the existing convention); cross-
  Business override attempt refused; `CatalogItemPricingResolver`
  correctness across all three cases (no override row → Business
  default; override row with `is_enabled=false` → not offered; override
  row with a `price_minor_override` → that price), including the sparse-
  table "absence means enabled at default" semantics stated explicitly
  in §5.2.
- **Risk**: Low.
- **Model**: Sonnet 5 sufficient.

### Sub-slice D — Immutable snapshot service

- **Files/domains**: `PackageSnapshotService` (or equivalent) in
  `app/Library/Catalog/`, exposing one method —
  `snapshot(CatalogItem $item, ?BusinessLocation $location, User $actor,
  ?int $priceMinorOverride = null): PackageSnapshot` — that resolves the
  effective price via Sub-slice C's resolver (unless an explicit price is
  supplied, for the "catalog item has no fixed price, caller must
  supply one" case in §5.3), writes one `package_snapshots` row, and
  never updates it afterward. Deliberately kept small and isolated so
  Slice 17 has a stable, minimal dependency to build against without
  waiting on UI work.
- **Prerequisites**: A, B, C (hard).
- **Schema**: none new — consumes A's `package_snapshots`.
- **Tenancy/security**: the service itself performs no authorization —
  callers (this slice's own tests, and eventually Slice 17) are
  responsible for having already authorized the underlying action; the
  service's only job is producing a correct, immutable record.
- **Concurrency**: none (§7 — no shared mutable state to race).
- **Tests**: snapshot correctness (every field matches the resolved
  state at the moment of the call); immutability (no code path ever
  calls `update()`/`save()` on an existing `PackageSnapshot`, verified by
  a test that attempts a mutation and confirms the model refuses it or
  that no such method is exposed); the "no fixed price, no explicit
  price supplied" refusal case; a snapshot taken before and after a
  later catalog-item price change proves the earlier snapshot is
  unaffected (the direct proof of Blueprint §17's own acceptance
  language, "later catalog or price changes never retroactively alter a
  past transactional document").
- **Risk**: Low — the one place correctness genuinely matters most in
  this contract (Slice 17 will trust this completely), but the
  mechanism itself is a simple, fully precedented write-once insert.
- **Model**: Sonnet 5 sufficient.

### Sub-slice E — Entitlement/nav wiring

- **Files/domains**: new `PlatformFeature::PackagesProducts` case, new
  `PlatformFeatureRegistry` entry (starting `Planned`), new backfill
  migration for `platform_feature_usage_classifications` (§8 — never
  editing the existing one), new `workspace_plan_features` seed row
  covering all three tiers (§11), nav entry via `CustomerMenuBuilder`
  (adding the feature key to `ENTITLEMENT_GATED_FEATURES`, mirroring the
  exact pattern used for Calendar in Contract 15 §12.D), and the final
  `Planned`→`Available` flip once A–D are merged and verified.
- **Prerequisites**: A, B, C, D (hard — the flip must not happen before
  the feature is genuinely usable, §11).
- **Schema**: the one new migration named above; no application table
  changes.
- **Tenancy/security**: none new — this sub-slice only gates visibility
  of the UI Sub-slices B/C already built.
- **Concurrency**: none.
- **Tests**: `PlatformFeatureRegistryTest`-style entitlement tests (the
  feature starts `Planned`/unavailable, then `Available` after the
  flip); nav-visibility tests per plan tier (all three tiers see the
  item once available, per Blueprint §21).
- **Risk**: Low — fully mechanical, fully precedented by the exact same
  flip already performed for `WebsiteGeneration`/`GoogleBusinessProfileModule`/
  `AiCooBasic`/Calendar.
- **Model**: Sonnet 5 sufficient.

## 13. Required tests

Beyond each sub-slice's own tests (§12): once D ships, an end-to-end test
proving Blueprint §17's exact acceptance language — a catalog item's
price changes after a snapshot was taken, and the snapshot's own
`price_minor_at_snapshot` is provably unaffected. No cross-domain
regression suite is required beyond the Location-ACL domain tests already
covered by Sub-slice C's own suite (this slice does not touch Workspace,
Agency, Conversations, Contacts, or Opportunities — Contract 14's own
five-domain regression is not re-run here, since nothing in those domains
is modified).

## 14. Acceptance criteria

1. Exactly one canonical, Business-wide catalog exists per Business — no
   per-Location duplication of a catalog item's own row (§5.1, §5.2).
2. A Location can enable/disable a catalog item and set an optional
   price override without altering the Business-wide default (§5.2, §7).
3. Every `package_snapshots` row is genuinely immutable — no code path
   updates one after insert (§5.3, §12.D's test).
4. A later change to a catalog item's price or a Location's override
   never alters an already-created snapshot (§13's end-to-end proof).
5. `LocationAccessGuard` is the only Location-authorization mechanism
   used anywhere in this slice (§6) — no parallel ACL logic.
6. `PlatformFeature::PackagesProducts` is flipped to `Available` only
   after Sub-slices A–D are merged and verified (§11).
7. `git diff --check` clean and a clean working tree at the end of each
   sub-slice's own commit.

## 15. Non-goals

- **Package-contains-Products bundling** — no authoritative document
  describes this relationship; not built (§5.1).
- **Per-Location currency divergence** — currency is a Business-wide
  property; overrides change amount only (§5.2).
- **Any Automations event for catalog lifecycle** — no document
  requires one, unlike Calendar's explicit five-event requirement
  (§10).
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
each block of §12 (A before B before C before D; E needs A–D).

## 17. Conflict map

| Other work | Shared file/table | Posture |
|---|---|---|
| Slice 15 (Calendar) | none — Roadmap explicitly notes 15–18 "do not touch `WorkspaceManager`, `CustomerAccountAccessResolver`, `EntitlementManager`, or the Agency relationship table"; both slices independently add their own `CustomerMenuBuilder`/`ENTITLEMENT_GATED_FEATURES` lines, an ordinary low-conflict merge, not a hard serialization | Parallel-safe |
| Slice 17 (Proposal/Contract/e-signature) | `package_snapshots` (read-only consumer, once Slice 17 exists) | Serialize only in the sense that Slice 17 must land after Sub-slice D — not a file conflict, since Slice 17 has no files yet |
| `CustomerMenuBuilder.php` (`ENTITLEMENT_GATED_FEATURES`, §12.E) | additive line, same low-conflict shape as every prior slice's own nav addition | Low risk |

## 18. Implementation prompts

Each sub-slice is handed to a fresh session independently, once
explicitly authorized. Every prompt below assumes Contracts 1–14 and
every lower-lettered Sub-slice already merged to `main`.

### 18.A — Schema/domain foundation

```
You are implementing Sub-slice A of Slice 16 (Packages & Products
catalog) for the os-creator1/os-ai repository, per docs/product/
implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md SS5 and SS12.A.
This is schema/model only -- no services, controllers, routes, or UI.

Before writing code:
1. Fetch latest origin/main and verify it is at or after 3dbb1e11.
2. Re-read SS5 (Canonical domain model) and SS12.A in full.
3. Create a fresh worktree/branch for this sub-slice only (e.g.
   agent/v1-slice16a-catalog-schema).

Implement exactly the three tables in SS5.1-SS5.3, as migrations, plus
their Eloquent models with casts/relations only. Follow this repository's
existing conventions precisely: restrictOnDelete vs cascadeOnDelete
exactly as SS5 specifies (never the other way, even if it seems
equivalent -- the contract's own reasoning for each choice is in SS5),
lifecycle_state/archived_at NOT fillable on CatalogItem (mirroring
BusinessLocation), const UPDATED_AT = null on PackageSnapshot (mirroring
WebsiteRevision), no soft-delete trait anywhere.

Do not write any controller, route, service class, or view in this
sub-slice.

Tests: migration/constraint existence tests, model factory smoke tests.

After implementing: run the new tests, run `git diff --check`, commit,
and push to the fresh branch. Do NOT create a pull request. Do NOT merge.
Return: starting/final SHA, exact files created, exact tests run and
counts, confirmation every FK/constraint matches SS5 exactly.
```

### 18.B — Catalog management

```
You are implementing Sub-slice B of Slice 16, per docs/product/
implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md SS12.B. Hard
prerequisite: Sub-slice A merged.

Implement CatalogItemManager enforcing SS5.1's price_minor/currency_code
co-nullable invariant and the archive()/reactivate()-only write path for
lifecycle_state/archived_at (mirroring BusinessLocation's repository
exactly -- read app/Repositories/Eloquent/EloquentBusinessLocationRepository.php
first). Implement Business-wide CRUD (owner or active WorkspaceMembership,
role-blind, no LocationAccessGuard call -- SS6) and the SS7 reorder
algorithm (mirroring CrmPipelineService::applyOrder() -- read
app/Library/Crm/CrmPipelineService.php first).

Tests per SS12.B. Run tests, git diff --check, commit, push to a fresh
branch off A's merged state. Do NOT create a PR. Do NOT merge. Return:
SHA, files, test counts.
```

### 18.C — Location enable/override + pricing resolution

```
You are implementing Sub-slice C of Slice 16, per docs/product/
implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md SS12.C. Hard
prerequisites: Sub-slices A and B merged.

Implement override CRUD authorized via
LocationAccessGuard::assertUserCanAccessLocation() (SS6) plus the
FK-domain-integrity check that the Location's business_id matches the
catalog item's. Implement CatalogItemPricingResolver exactly per SS5.2's
three-case resolution (no row -> Business default; is_enabled=false ->
not offered; price_minor_override set -> that price).

Tests per SS12.C, especially the sparse-table "absence means enabled at
default" semantics and the cross-Business override refusal. Run tests,
git diff --check, commit, push to a fresh branch. Do NOT create a PR.
Do NOT merge. Return: SHA, files, test counts.
```

### 18.D — Immutable snapshot service

```
You are implementing Sub-slice D of Slice 16, per docs/product/
implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md SS12.D -- the
piece Slice 17 will directly depend on, so correctness here matters more
than in any other sub-slice of this contract. Hard prerequisites:
Sub-slices A, B, C merged.

Implement PackageSnapshotService::snapshot() exactly per SS5.3/SS12.D:
resolves effective price via Sub-slice C's CatalogItemPricingResolver
unless an explicit price is supplied, refuses to write a snapshot when
no price can be resolved (never defaults to zero), writes one
package_snapshots row, and never updates it afterward -- no method on
PackageSnapshot should permit a later mutation.

Tests per SS12.D, specifically including a test that changes a catalog
item's price AFTER taking a snapshot and proves the snapshot's own
price_minor_at_snapshot is unaffected -- this is the direct proof of
Blueprint SS17's own acceptance language and the single most important
test in this contract.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT
create a PR. Do NOT merge. Return: SHA, files, test counts, and explicit
confirmation the price-change-after-snapshot test passed.
```

### 18.E — Entitlement/nav wiring

```
You are implementing Sub-slice E of Slice 16, per docs/product/
implementation-contracts/16-PACKAGES-PRODUCTS-CATALOG.md SS12.E. Hard
prerequisites: Sub-slices A, B, C, D merged.

Add a new PlatformFeature case (e.g. PackagesProducts = 'packages_products')
and PlatformFeatureRegistry entry starting at Planned. Per this
repository's own stated discipline (see the MessagingTransport docblock
in app/Enums/Entitlement/PlatformFeature.php), write a NEW migration to
backfill platform_feature_usage_classifications for this case -- do NOT
edit the existing merged backfill migration. Add a workspace_plan_features
seed row covering all three tiers (Core, Growth, Agency) per Blueprint
SS21. Add the nav entry via CustomerMenuBuilder, including adding the new
feature key to ENTITLEMENT_GATED_FEATURES (omitting this silently hides
the item forever, per the same lesson already documented in Contract 15).

Only as the LAST step, once you have verified Sub-slices A-D actually
work end-to-end, flip the registry entry from Planned to Available.

Tests per SS12.E. Run tests, git diff --check, commit, push to a fresh
branch. Do NOT create a PR. Do NOT merge. Return: SHA, files, test
counts, and explicit confirmation of what you verified before flipping
the entitlement.
```
