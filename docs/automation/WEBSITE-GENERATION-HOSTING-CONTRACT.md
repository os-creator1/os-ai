# WEBSITE GENERATION + HOSTING — IMPLEMENTATION CONTRACT

Status: CONTRACT ONLY — no product code authorized by this document itself.
Original base SHA: `2425b9f1b4415a6b1dbeab99070191cc3d178b35`
Correction 1 base SHA (current `origin/main`, merged in — B5 Business
Analytics contract, docs-only, zero file overlap):
`b2bedfc91848c90be2f1fc4e8e0ac440c6d4d892`
Branch: `agent/website-generation-hosting-contract`

Every claim in this contract is backed by a mechanical inspection of the
tree at the base SHA (re-verified at Correction 1 against the merged main —
no Website-relevant repository evidence changed between the two SHAs above;
the only intervening change anywhere in the tree is the new, unrelated
`docs/automation/B5-BUSINESS-ANALYTICS-CONTRACT.md` file). Where a decision
was left open by the task, the resolving evidence is cited inline by file
and line. Correction 1 resolves every Slice A product decision this
contract had left open (§26, §21, §13.1, §33) and additionally locks two
narrow predecessor seams a future, separately contracted SEO Slice A will
need (§9.2/§10's `WebsitePublished` domain event, §17.1's
`WebsiteDraftPageService`) — without adding any SEO product code. Slice A
now has zero unresolved product policy; only Slice B's own named
infrastructure questions (§40) remain open, because Slice B is a recorded
boundary, not an authorized implementation. This document contracts
**Slice A** (product and platform publishing) in full, and records
**Slice B**'s (custom domains + host infrastructure) boundary and
prerequisites without designing it.

---

## 1. LOCKED PRODUCT DEFINITION

### 1.1 Greenfield confirmation — RESOLVED MECHANICALLY

There is no existing customer-facing website product anywhere in this
repository:

- Case-insensitive search for `ULANDING`, `landing_page`, `LandingPage`,
  `website_template`, `site_builder`, `page_builder` across `app/`,
  `resources/views/`, `database/migrations/` returns **zero matches**.
- `App\Models\Templates` (`database/migrations/2021_02_27_094439_create_templates_table.php`)
  is an **SMS/messaging** template: fillable `name`, `user_id`,
  `business_id`, `message`, `status`, `sender_id`, `dlt_template_id`,
  `dlt_category`, `approved`. It has no relationship to a website concept
  and must not be reused or renamed.
- `businesses.website_url` (`database/migrations/2026_07_18_120001_create_businesses_table.php`)
  is a nullable string(2048) holding a **customer-owned external URL**
  (their existing site, if any) — a plain profile field, not a generated
  or hosted asset. It is read-only context for Slice A (§18), never
  written by it.
- No admin/M2 component is a public-facing surface today.

**Conclusion:** Website Generation + Hosting is entirely greenfield. No
legacy surface is resurrected, repurposed, or renamed.

### 1.2 Slice split — LOCKED

**Slice A — Product + platform publishing** (this contract, full design):
Business Website domain, pages, bounded sections, assets, AI initial
generation, editing, authenticated preview, immutable publication
revisions, rollback, public rendering on the platform's own existing host.
No arbitrary customer domains.

**Slice B — Custom domains + host infrastructure** (§40, boundary only):
domain ownership verification, DNS instructions, host resolution,
`TrustHosts` strategy, TLS/certificate provisioning, custom-domain routing,
domain lifecycle, conflicts, security. A separate future
implementation/contract. Slice A must not depend on any Slice B mechanism.

---

## 2. TENANCY — LOCKED

Website Generation is **Business-scoped**. This is not a new opinion — the
entitlement layer already classifies it that way:

- `App\Enums\Entitlement\PlatformFeature::WebsiteGeneration` already exists
  (`app/Enums/Entitlement/PlatformFeature.php:19`, value `website_generation`).
- `PlatformFeatureRegistry::SCOPE` (`app/Library/Entitlement/PlatformFeatureRegistry.php:62-64`)
  lists **only** `ProspectOutreach` as `Workspace`; every other key,
  `WebsiteGeneration` included, defaults to `PlatformFeatureScope::Business`
  via `isWorkspaceScoped()`/`isBusinessScoped()` (lines 71-84).
- `PlatformFeatureRegistry::AVAILABILITY` (line 40) currently marks
  `WebsiteGeneration` as `PlatformFeatureAvailability::Planned`, not
  `Available` (§30).

### 2.1 Canonical address

```
/workspaces/{workspaceUid}/businesses/{businessUid}/website/...
```

Singular `website` (not `websites`) in the authenticated path — §6 locks
one Website per Business, so there is no collection to enumerate.

### 2.2 Mandatory per-request resolution order

Every authenticated HTTP action, without exception, mirrors the exact
pattern already established by `App\Http\Controllers\Customer\Business\MessagingChannelsController::resolveAccessibleBusiness()`
(lines 381-396, itself citing the same shape in `OutreachController` and
`UsageBillingController` as "the exact RFC-003 §14.1 boundary"):

1. Resolve Workspace by UID (`WorkspaceRepository::findByUid()`); 404 if
   absent.
2. Resolve Business by UID **inside that Workspace**
   (`$workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid)`);
   404 if absent.
3. `WorkspaceManager::userCanAccessBusiness((int) Auth::id(), $business)`
   (`app/Library/Workspace/WorkspaceManager.php:97-138`); 404 if false.
   This method re-reads Business/Workspace via repositories (never trusts
   an in-memory model) and checks, in order: Workspace exists and
   `is_active`; direct `customer_id` ownership; Workspace `owner_user_id`;
   active `WorkspaceMembership` with `business_access_scope` of `All` or
   `Selected` (verified via `WorkspaceMembershipBusinessRepository::isAssigned()`).
4. Entitlement decision (§26).
5. Resolve the Website **scoped to that Business** (`Website::where('business_id', $business->id)`).

A foreign-Workspace, foreign-Business, or foreign-Website/page/revision/
asset identifier fails closed with the same 404 shape as a nonexistent
one — never a distinguishable 403.

Controller methods take `string $workspaceUid, string $businessUid` and
resolve manually; they never rely on implicit Business/Workspace route
model binding (matching the established convention — `routes/customer.php`
does not `whereUuid()`-constrain `{workspaceUid}`/`{businessUid}`).

### 2.3 Forbidden tenancy mechanics

Mirroring `docs/automation/B4-BUSINESS-AUTOMATIONS-CONTRACT.md`§2.3's own
list verbatim, because the underlying hazards are identical:

- `Auth::id()` as tenant identity (capability checks only).
- `businesses.customer_id` — or any `user_id` column — as HTTP
  authorization.
- Primary-Business inference of any kind.
- `App\Library\Business\LegacyBusinessResolver` (`resolveForCustomer()`,
  explicitly documented across `B4-BUSINESS-AUTOMATIONS-CONTRACT.md` lines
  94/195-206/407/421 as forbidden for any new Business-scoped feature; its
  one remaining legitimate legacy caller, `DLRController.php:747`'s inbound
  opt-in keyword auto-creation, is unrelated and untouched here).
- Cross-Business Website/page/revision/asset lookup — every query that
  resolves one of these four models **must** filter by the already-
  resolved `business_id` (directly, or via `website_id` chained to a
  Website already filtered by `business_id`).

### 2.4 RFC-003 rules this contract inherits unchanged

From `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md`:

- §7.2/§7.3: Workspace ownership, Workspace membership, and direct
  Business ownership are three independent relationships; effective role
  resolves strictly Owner → Admin → Staff → none.
- §7.5: `role` (management authority) and `business_access_scope`
  (`all`/`selected`, Business visibility) are independent axes; role never
  implies scope.
- §14/§14.1: the exact `userCanAccessBusiness` precedence `WorkspaceManager`
  implements.
- §14.2: an inactive Workspace blocks **all** access including direct/owner
  access; inactive memberships confer none; `selected` scope only narrows;
  `workspaces.is_active` is a tenancy flag, not billing state (billing
  lapse is RFC-004/entitlement's concern, §26 here); every mutation
  locks-and-rechecks authoritative rows inside a transaction.

---

## 3. PUBLIC IDENTIFIER STRATEGY — LOCKED

### 3.1 `Business.uid` is unsafe as a public secret — CONFIRMED MECHANICALLY

`App\Library\Traits\HasUid::generateUid()` (`app/Library/Traits/HasUid.php:27-30`)
default implementation is:

```php
public function generateUid()
{
    $this->uid = uniqid();
}
```

`uniqid()` is a low-entropy, time-based, effectively sequential 13-character
hex string — not cryptographically random. `businesses.uid` is declared as
a DB `uuid` column (`2026_07_18_120001_create_businesses_table.php:12`),
but `App\Models\Business` does **not** override `generateUid()`, and
`EloquentBusinessRepository::createForCustomerInWorkspace()` never sets
`uid` explicitly — so it falls through to the unsafe trait default despite
the column's name and type suggesting otherwise.

By contrast, `App\Models\Workspace` (`app/Models/Workspace.php:26-35`) and
`App\Models\PlatformThemePreset` (`app/Models/PlatformThemePreset.php:55-57`)
both explicitly override it to `(string) Str::uuid()`, with `Workspace`'s
own docblock naming exactly this gap as the reason. **`Business.uid`
inherits the unguessable-looking column name without the unguessable
value.** This contract does not attempt to retrofit `Business::generateUid()`
(out of Website scope, would need its own migration/backfill contract) —
it simply never uses `Business.uid` for anything public-facing.

### 3.2 Locked design

`websites` gets its own dedicated column, generated exactly like
`Workspace::generateUid()`:

```php
public function generateUid(): void
{
    $this->uid = (string) Str::uuid();
}
```

Two separate UID-shaped columns on `websites`, with two separate purposes
— never conflated:

- `uid` — the **internal**, HasUid-convention identifier, used only in the
  authenticated `/workspaces/.../website/...` routes exactly like every
  other Business-scoped resource.
- `public_id` — a **second**, independently generated UUID
  (`(string) Str::uuid()`, unique, generated once at Website creation and
  never regenerated implicitly), used **exclusively** by the public
  `/sites/{public_id}` route. Rotatable later (§3.4) without touching
  `uid`, `business_id`, or any authenticated route.

Both are real `Str::uuid()` values — not `uniqid()` — closing the exact gap
found in §3.1 for this new table from day one.

### 3.3 Public route binding

```php
Route::prefix('sites')->group(function () {
    Route::get('{website:public_id}', 'Public\WebsiteController@home')
        ->whereUuid('website')->name('public.website.home');
    Route::get('{website:public_id}/sitemap', 'Public\WebsiteController@sitemap')
        ->whereUuid('website')->name('public.website.sitemap');
    Route::get('{website:public_id}/{slug}', 'Public\WebsiteController@page')
        ->whereUuid('website')->name('public.website.page');
});
```

`{website:public_id}` binds Laravel's implicit route-model-binding to the
`public_id` column specifically (never the default `uid`/route key), and
`whereUuid('website')` additionally constrains the route parameter itself
to the UUID shape before any query runs — defense in depth, mirroring the
existing `whereUuid('preset')`/`whereUuid('workspace')` convention at
`routes/admin.php:680,725,751`. An unknown or malformed `public_id` never
reaches a query; Laravel's binding resolution itself 404s.

### 3.4 Rotation

`public_id` can be regenerated later (an explicit, authenticated,
capability-gated action — not designed in Slice A beyond confirming the
column supports it) without touching Business identity, `uid`, or any
authenticated route. Recording this as a locked design property now, not
scheduling the feature.

---

## 4. ONE WEBSITE PER BUSINESS — LOCKED (v1)

`websites.business_id` is `unique()`. Directly precedented by three
existing "one canonical record per Business" tables, all using the same
FK shape:

```php
$table->foreignId('business_id')->unique()->constrained('businesses')->restrictOnDelete();
```

(`database/migrations/2026_08_16_120001_create_business_usage_wallets_table.php:31`,
`..._130005_create_business_billing_contacts_table.php:20`,
`..._130006_create_business_payer_assignments_table.php:20`.)

Rationale: Business is the location/company execution boundary; a
Workspace can already own many Businesses, so multi-location customers get
explicit per-location Website ownership rather than an implicit
tenant-guessing scheme. No `website_group`/`website_project` concept is
built. A future multi-website-per-Business capability is a distinct,
separately-contracted expansion — not encoded here even as a nullable
column.

---

## 5. SCHEMA — PROPOSED TABLES

Exactly four new **domain tables**, created via **five migration files**
(§33 — `websites` and `website_revisions` reference each other, so the
`websites → website_revisions` forward FK is added in its own, later
migration rather than attempted inside `create_websites_table` itself; the
table count is still four, only the migration-file count is five). No
`website_domains` table (Slice B, §40). No generic CMS/event framework, no
`website_sections` table separate from `website_pages` (sections are
stored inline as JSON per page, §9).

```
websites
website_pages
website_revisions
website_assets
```

No table adds `workspace_id`. Business is the sole authoritative tenancy
column on `websites`; every other table reaches tenancy by joining through
`websites.business_id` (mirroring RFC-004's own established rule that
Business/Website-owned tables never redundantly duplicate a Workspace
column, and matching this repository's existing `business_services`/
`business_usage_wallets`-style tables, none of which duplicate
`workspace_id`).

### 5.1 `websites`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uid` | uuid, unique | internal identifier, `Str::uuid()` (§3.2) |
| `public_id` | uuid, unique | public identifier, `Str::uuid()` (§3.2) |
| `business_id` | FK → `businesses.id`, unique, `restrictOnDelete()` | §4 |
| `name` | string(120) | owner-facing display title only; never rendered publicly |
| `status` | string(16), default `draft` | `App\Enums\Website\WebsiteStatus`: `draft`, `published`, `archived` (§6) |
| `published_revision_id` | nullable FK → `website_revisions.id`, `nullOnDelete()` | §9/§10; added by its own migration after `website_revisions` exists — exact sequence in §33 |
| `theme` | json, nullable | §18 bounded presentation config |
| `created_at`/`updated_at` | timestamps | |

Indexes: `business_id` (from the unique constraint), `public_id` (from its
own unique constraint), `status`.

No database ENUM type — `status` is a plain string column cast through the
code-backed `WebsiteStatus` enum, matching this repository's own
established convention (`businesses.status`/`business_services.status`
are both plain strings backed by `BusinessStatus`/`BusinessServiceStatus`
PHP enums — `app/Enums/Business/BusinessStatus.php`,
`app/Enums/Business/BusinessServiceStatus.php`).

### 5.2 `website_pages`

Flat model. No nested tree.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uid` | uuid, unique | |
| `website_id` | FK → `websites.id`, `cascadeOnDelete()` | |
| `title` | string(150) | |
| `slug` | string(80), nullable | null/ignored when `is_home` (§6) |
| `is_home` | boolean, default false | §6 |
| `sections` | json | ordered array of bounded component blocks (§7) |
| `seo_title` | string(70), nullable | |
| `meta_description` | string(160), nullable | |
| `noindex` | boolean, default false | |
| `sort_order` | unsigned smallint, default 0 | display order in the editor's page list only — has no effect on public URLs |
| `created_at`/`updated_at` | timestamps | |

Indexes: `website_id`, unique `(website_id, slug)` (nullable-safe — MySQL
treats multiple NULLs as distinct under a unique index, which is exactly
right since the homepage's `slug` is null and there is only one homepage
per §6.2), and a partial-in-practice uniqueness for `is_home` enforced at
the **application** layer (§6.2 — MySQL has no native filtered unique
index; RFC-003 §14.2's own established convention of "lock-and-recheck
authoritative rows inside a transaction" is reused here instead of a
database constraint).

### 5.3 `website_revisions`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uid` | uuid, unique | |
| `website_id` | FK → `websites.id`, `cascadeOnDelete()` | |
| `version_number` | unsigned integer | §13 |
| `snapshot` | json | §14 — self-contained, versioned |
| `schema_version` | unsigned smallint | denormalized copy of `snapshot->schema_version` for cheap querying without decoding JSON |
| `created_by` | FK → `users.id`, `restrictOnDelete()` | acting user id at publish time (audit only) |
| `created_at` | timestamp | no `updated_at` — revisions are immutable, so there is nothing to touch after creation |

Indexes: `website_id`, unique `(website_id, version_number)`.

No `updated_at`, no soft delete, no status column — a revision is
write-once. Nothing in the implementation may `UPDATE` a `website_revisions`
row after insert.

### 5.4 `website_assets`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uid` | uuid, unique | |
| `website_id` | FK → `websites.id`, `cascadeOnDelete()` | |
| `disk` | string(32) | e.g. `public`, matching this repo's existing `public_path()`-based convention (§13) |
| `path` | string(255) | server-generated only (§13) — never user input |
| `mime_type` | string(64) | derived from verified magic bytes, never client `Content-Type` |
| `size` | unsigned integer | bytes |
| `width` / `height` | unsigned integer, nullable | from real decode, not headers |
| `alt_text` | string(160), nullable | |
| `content_hash` | string(64), nullable | sha256 of the stored file, for the same write-verify-swap integrity check `BrandingUploadService` already uses |
| `first_published_at` | nullable timestamp | Correction 1 — replaces an earlier `is_referenced_by_published_revision` boolean design. `NULL` until the asset first appears in a successful publish (§9.1 step 6 sets it exactly once, on first appearance only); never cleared, and never updated again by a later publish that omits the asset. This is a durable, monotonic marker, not a "currently referenced" flag — see §13.1 for why. |
| `created_at`/`updated_at` | timestamps | |

Indexes: `website_id`.

`business_id` is deliberately **not** duplicated on `website_assets` —
tenancy is derived by joining through `website_id → websites.business_id`,
per §5's own rule and this table's own single access path (every asset
query is already scoped by an already-resolved Website).

---

## 6. WEBSITE PAGES MODEL — LOCKED

### 6.1 Structure

Flat list per Website. No parent/child page nesting, no arbitrary path
depth. Public URL shape is exactly `/sites/{public_id}` (home) or
`/sites/{public_id}/{slug}` (one segment, ever).

### 6.2 Homepage invariant

Exactly one page per Website has `is_home = true`. Enforced
transactionally (no MySQL filtered-unique-index exists for this): any
mutation that sets `is_home = true` on a page first clears it from
whichever page currently holds it, inside one locked transaction, mirroring
RFC-003 §14.2's own "lock-and-recheck authoritative rows inside a
transaction" convention. A Website is never left with zero homepages: page
delete on the current homepage is rejected unless another existing page is
simultaneously promoted in the same request (§34).

### 6.3 Slugs

- Non-home pages require a `slug`; the homepage's `slug` is always `null`
  and is never resolvable via the `{slug}` public route segment (the home
  route is separate, §3.3).
- Unique within one Website (`(website_id, slug)`).
- Server-side validated against exactly: `^[a-z0-9]+(-[a-z0-9]+)*$`,
  max 80 characters. Non-conforming input (including any non-ASCII
  Unicode) is **rejected**, not silently transliterated — the editor UI
  may offer `Str::slug()` as an authoring convenience, but the stored value
  is always re-validated server-side against this exact regex regardless
  of how it was produced.
- This regex structurally forbids: dot-segments (`.`/`..`), any encoded or
  literal `/`, a leading/trailing/doubled `-`, a protocol prefix
  (`javascript:` etc. cannot match `[a-z0-9-]+`), and any traversal
  sequence.
- No query-string-defined page identity — the slug is the entire page
  identity; query strings are never consulted by the public router.

### 6.4 Reserved slugs

`sitemap`, `robots`, `robots.txt`, `preview`, `admin`, `api`, `assets`,
`home`, `_website` are permanently reserved and rejected at page-create/
-rename time, independent of route-registration order. `sitemap` is
reserved specifically because it is itself a public route segment (§24);
the others are reserved defensively for future platform routes under the
same `/sites/{public_id}/...` prefix.

### 6.5 No per-page draft/publish state

All current pages of a Website are included in every publish (§9); there
is no per-page "exclude from next publish" flag. This keeps the status
model tiny per the task's own instruction and matches the "whole-site
snapshot" architecture in §9/§14.

---

## 7. BOUNDED WEBSITE COMPONENT LIBRARY — LOCKED

The M2 Design System (`resources/views/components/*.blade.php` — `alert`,
`badge`, `button`, `card`, `dialog`, `ds-icon`, `empty-state`, `input`,
`menu`, `pagination`, `select`, `switch-toggle`, `table`, `tabs`, plus the
four `branding-*` components) is the internal admin/SaaS UI. It is **never**
used to render a public Website page (§38). A separate, bounded Website
Component Library governs public content, identified by a code-backed
enum — never a stored class/Blade/JS name:

```php
enum App\Enums\Website\WebsiteSectionType: string
{
    case Hero = 'hero';
    case Text = 'text';
    case ImageText = 'image_text';
    case Services = 'services';
    case Testimonials = 'testimonials';
    case Faq = 'faq';
    case Cta = 'cta';
    case ContactDetails = 'contact_details';
}
```

Global bounds (protect against pathological AI output and abuse, not
ordinary use): max **20 pages** per Website, max **40 sections** per page.

Every section is `{"type": "<enum value>", "data": {...}}` inside a page's
`sections` JSON array, `data` validated against exactly the shape below —
an unknown `type`, or a `data` shape that fails validation, is rejected
outright (§19), never partially persisted.

### 7.1 Real-data sourcing rule — LOCKED

Business has **no** hours, FAQ, reviews, or offers model anywhere in this
codebase (confirmed — see §15). `Services`/`ContactDetails` sections
therefore support an explicit **one-time copy-on-generate** from
`BusinessService`/`Business`/`BusinessLocation` at AI-generation time
(§18/§19) — never a live foreign key or live join. Once written into a
page's `sections` JSON, the content is the Website's own, independently
editable, and immune to a later edit or deletion of the source
`BusinessService`/`BusinessLocation` row. This is required by §14's
self-contained-snapshot rule: a live join would let an unrelated Business
data edit silently mutate an already-published revision.

### 7.2 Per-component field contract

| Type | Fields | Limits | Assets | Links |
|---|---|---|---|---|
| `hero` | `heading`, `subheading?`, `background_image?`, `primary_cta?`, `secondary_cta?` | heading ≤120, subheading ≤240 | `background_image`: one asset ref | `primary_cta`/`secondary_cta`: `{label ≤40, url}` each, URL rules §25 |
| `text` | `heading?`, `body` | heading ≤120, body ≤5000 | none | none |
| `image_text` | `heading?`, `body`, `image`, `image_position` | heading ≤120, body ≤3000 | `image`: one asset ref, required | none |
| `services` | `heading?`, `items[]` | heading ≤120, items ≤12; each item: `name` ≤120 (required), `description` ≤500, `price_label` ≤40, `image?` | each item may hold one asset ref | none |
| `testimonials` | `heading?`, `items[]` | items ≤10; each item: `quote` ≤400 (required), `author_name` ≤80 (required), `author_title` ≤80 | none | none — explicitly authored content, never sourced from any review platform (§7.1, §15) |
| `faq` | `heading?`, `items[]` | items ≤20; each item: `question` ≤200 (required), `answer` ≤1000 (required) | none | none — explicitly authored, no FAQ model exists to source from (§15) |
| `cta` | `heading`, `body?`, `buttons[]` | heading ≤120, body ≤300, buttons ≤2; each: `{label ≤40, url}` | none | URL rules §25 |
| `contact_details` | `show_phone`, `show_email`, `show_address` (each boolean) | — | none | phone/email rendered as `tel:`/`mailto:` links (§25) built server-side from the Business's own real, currently-live `phone`/`email`/primary `BusinessLocation` address at render time — see the one deliberate exception in §7.3 |

Every asset reference is an `asset_uid` string, revalidated against
`website_assets` scoped to the same Website at both draft-save time and
publish/snapshot-build time (defense in depth — an asset may be deleted
between an edit and a later publish).

### 7.3 `contact_details` is the one deliberate live-read exception

Unlike `services`/`testimonials`/`faq` (fully copied Website content),
`contact_details` intentionally re-reads the Business's **current**
`phone`/`email`/primary `BusinessLocation` address at both draft-render and
publish-snapshot time, because a business's real phone/email/address
changing later should be reflected on its live site without a manual
Website edit — this is presentation config (which fields to *show*), not
Website content, and is stored in the snapshot as the three booleans plus
a snapshot-time-resolved copy of the actual values (§14), so the
published-revision-immutability rule is still honored: the *values* are
copied into the snapshot at publish time exactly like every other field,
only the *decision of what to display* re-reads Business state on the next
publish.

### 7.4 No drag-and-drop; no raw content

A form/section editor with add/edit/reorder/delete controls is sufficient
(§20). No visual/drag-and-drop architecture. No custom HTML/JS/CSS/PHP/
Blade/iframe component type exists in this enum, ever (§8).

---

## 8. NO RAW CUSTOM CODE — LOCKED

Website content **must not** support: custom HTML blocks, custom
JavaScript, custom CSS injection, PHP, Blade, iframe embeds, or arbitrary
`<script>` tags — structurally, by the enum in §7 admitting no such
component type, not by a sanitizer trying to catch it after the fact.

This is completely separate from B3's Platform Advanced `custom_script`
setting (`app/Http/Controllers/Admin/SettingsController.php`,
`custom_script`/`sanitizeScript()`) — that is platform/admin-operator
behavior, rendered only on the SaaS's own pages
(`panels/footer.blade.php`, `auth/login.blade.php`), and **must never**
become, or be exposed through, a Website content primitive. No Website
component type reads `custom_script`, and no future Website field is
authorized to accept raw HTML that reaches `{!! !!}` unescaped output.

Plain textual fields (`heading`, `body`, `quote`, `question`, `answer`,
etc.) render through ordinary escaped Blade output (`{{ }}`), never
`{!! !!}}`, never `Blade::render()`, never `eval()`, on any user- or
AI-authored value. If limited rich text is ever authorized in a later
pass, it requires its own contract naming an exact sanitizer and an exact
allowed-tag allowlist — Slice A ships with **plain text only**.

---

## 9. DRAFT / PUBLISH MODEL — LOCKED

`website_pages` is the mutable, editable working state. `website_revisions`
holds immutable, whole-site publication snapshots. `websites.published_revision_id`
points at the exact live one. The public renderer **only** ever reads a
`website_revisions.snapshot` reached through `published_revision_id` — it
never queries `website_pages` directly, structurally preventing a draft
edit from ever reaching a live visitor before an explicit publish.

### 9.1 Publish algorithm

1. Authorize the exact Business's Website (§2.2) and re-check entitlement
   (§26 — publish is a mutation, always re-decided, never cached).
2. Validate every current page/section/asset reference against §6/§7
   (reject the whole publish on any violation — no partial publish).
3. Build the canonical full-Website snapshot (§14) from the current
   `website_pages` rows.
4. Insert one new immutable `website_revisions` row
   (`version_number` = previous max + 1 for this Website, inside the same
   transaction as step 5 to make the increment race-safe).
5. Atomically move `websites.published_revision_id` to the new revision's
   id, and set `status = published` if not already.
6. For every asset referenced anywhere in the just-built snapshot whose
   `first_published_at` is still `NULL`, set it to the current timestamp
   — once, monotonically. An asset already carrying a non-null
   `first_published_at` from an earlier publish is left untouched, even if
   this new snapshot no longer references it (§5.4/§13.1).

Steps 3-6 run inside one DB transaction. **No network operation runs
inside that transaction** — no AI call, no outbound HTTP, no queue
dispatch requiring an external round trip before commit.

### 9.2 Post-publish domain event — `WebsitePublished` (predecessor seam for a future SEO module)

No SEO product code exists in Slice A (§20/§40 remain the SEO boundary and
custom-domain boundary respectively, unchanged). What Slice A **does**
expose is one narrow, already-useful domain seam so a later, separately
contracted SEO module (or any other future bounded consumer) can react to
"this Website's live content changed" **without** Website depending on
SEO, and without a future controller-extraction refactor of the publish
path:

Immediately **after** §9.1's transaction commits (never inside it — no
network/queue side effect is ever added to the transaction itself), dispatch
one event:

```php
App\Events\Website\WebsitePublished
```

carrying exactly three stable, scalar identifiers — never a hydrated model,
never a snapshot payload:

```php
final class WebsitePublished
{
    public function __construct(
        public readonly int $websiteId,
        public readonly int $websiteRevisionId,
        public readonly int $businessId,
    ) {}
}
```

Dispatched via the framework's ordinary `event()`/`Event::dispatch()` —
**no generic event-bus/message-queue architecture is introduced**; this is
a single, already-idiomatic Laravel event, the same mechanism
`App\Events\Entitlement\*` and `App\Events\Workspace\*` already use
elsewhere in this codebase. The event carries **no SEO logic whatsoever**
— Website defines and dispatches it; it is not aware of, and does not
import, anything SEO-shaped. **Publishing succeeds identically whether or
not any listener exists** — a missing listener is not an error condition,
and no listener Website ships with may fail the publish request if it
throws (out of caution, though no listener exists in Slice A at all).

A future SEO Slice A may listen for this event to enqueue its own
post-publish work (e.g., regenerating structured-data hints) — that
listener, its queue, and its own tables belong entirely to SEO's own
future contract, not this one.

---

## 10. REVISION / ROLLBACK — LOCKED

Every successful publish creates exactly one new immutable revision (§5.3,
§9.1). Rollback:

1. Authorize (§2.2) and re-check entitlement (§26).
2. Select an existing `website_revisions` row for this Website by `uid`
   (404 if foreign/absent).
3. Atomically move `websites.published_revision_id` back to that
   revision's id.
4. **After** that commit — never inside it — dispatch `WebsitePublished`
   (§9.2) with the rolled-back-to revision's own `id`. **Locked decision:**
   rollback **does** emit the same event, because the public Website's
   live content has genuinely changed to a different immutable revision,
   and any future consumer (SEO's own audit state, in particular) needs to
   know the live revision changed regardless of whether that change came
   from a forward publish or a rollback. This is not a separate
   "no-event-on-rollback" path — a rollback is, from `WebsitePublished`'s
   own perspective, indistinguishable from any other change of the live
   revision.

Rollback **never** mutates the target revision, never deletes newer
revisions, and never touches `website_pages` (the current draft is left
exactly as it was — rolling back the *public* site does not silently
discard in-progress draft edits). No branching/version-control UI, no
per-user drafts, no merge logic — history is a simple, linear, immutable
publication sequence keyed by `version_number`.

**Rollback asset guarantee:** every asset any retained revision ever
referenced remains permanently retained (§13.1's `first_published_at`
rule) — a rollback to any historical revision is therefore guaranteed to
render every image it originally referenced, with no broken/missing-asset
case in Slice A.

---

## 11. SNAPSHOT CONTENT — LOCKED SCHEMA

Self-contained: a later draft edit, or a later `BusinessService`/
`BusinessLocation` edit, must never alter an already-published revision
(the one deliberate, explicitly-scoped exception is §7.3's
`contact_details` display values, which are still copied at publish time,
never live-joined at render time).

```json
{
  "schema_version": 1,
  "generated_at": "2026-01-01T00:00:00Z",
  "website": {
    "name": "Example Business",
    "theme": { "font": "...", "primary_color": "#...", "secondary_color": "#...", "button_style": "...", "content_width": "...", "header_variant": "...", "footer_variant": "..." }
  },
  "pages": [
    {
      "uid": "...",
      "slug": null,
      "is_home": true,
      "title": "Home",
      "seo": { "seo_title": "...", "meta_description": "...", "noindex": false },
      "sections": [ { "type": "hero", "data": { "...": "..." } } ]
    }
  ],
  "assets": [ { "uid": "...", "url": "https://.../images/websites/.../abc123.jpg", "alt_text": "..." } ]
}
```

No executable class names, no serialized Eloquent models — plain scalars/
arrays/strings only. `schema_version` gates any future render-format
change: the public renderer switches behavior on this integer, never on
inferring shape from presence/absence of keys.

---

## 12. ASSETS — LOCKED

No Business-scoped media library exists today; this is a new, dedicated
primitive (§5.4). Prefer deriving Business via `website_id → websites.business_id`
(§5.4) rather than denormalizing `business_id` onto `website_assets` —
every access path is already Website-scoped.

---

## 13. ASSET SECURITY — LOCKED

Directly reuses the pattern already proven by `App\Library\Branding\BrandingUploadService`
and `App\Rules\ValidBrandingImageRule` (both read in full):

- **Server-generated filenames only** — content-hashed
  (`hash('sha256', $contents) . '.' . $extension`), never client-derived,
  mirroring `BrandingUploadService::store()` line 62.
- **Magic-byte MIME validation**, not client `Content-Type` or filename
  extension — exact signature checks (PNG `\x89PNG\r\n\x1a\n`, JPEG
  `\xFF\xD8\xFF`, WEBP `RIFF....WEBP`), run independently in both the
  FormRequest validation rule and again inside the storage service (never
  trust that upstream validation already ran), mirroring
  `ValidBrandingImageRule::detectExtension()`.
- **Real image decode**, not header-trusting: `getimagesizefromstring()`
  against bounded dimension limits, `@`-suppressed and explicitly checked
  for `false` (undecodable → fail closed).
- **Allowed types: JPEG, PNG, WEBP only.** GIF is excluded (no existing
  safe-decode precedent for animated GIF in this codebase was found; adding
  it is out of Slice A scope). **SVG is excluded** — active-content/XSS risk,
  matching `BrandingUploadService`'s own unconditional SVG rejection.
  No PDF/document library — none is justified by any component in §7.
- **Bounded size**: reuse `BrandingUploadService::MAX_SIZE_BYTES`'s pattern
  (a fixed, hard-coded byte ceiling) — Website images are user-content at
  larger scale than branding assets, so this contract sets an explicit,
  separate ceiling of **8 MB per file**, not the branding 2 MB constant.
- **No user-controlled storage path**: destination is fully server-built —
  `{disk}/images/websites/{website_uid}/{content_hash}.{verified_extension}`
  — no client filename or path segment reaches the filesystem call.
- **No remote URL import** — Slice A never fetches an asset from a
  user-supplied URL (no SSRF surface is introduced).
- **Write-then-verify-then-swap-then-delete** for any asset *replacement*
  flow, mirroring `BrandingUploadService` lines 77-81's sha256
  re-read-and-compare-after-write integrity check.
- Public rendering serves assets by their server-generated `path` under the
  existing `public_path()`-based disk convention — never a raw private
  storage path, and never a path containing a user-controlled segment.

### 13.1 Deletion policy — any ever-published asset is retained for the life of Slice A history

Correction 1 replaces an earlier, weaker design (a
`is_referenced_by_published_revision` boolean recomputed at every publish,
which allowed an asset to become deletable the moment a later publish
stopped referencing it — and since revisions are immutable and never
deleted, §10, a rollback to that earlier revision could then point at a
physically deleted file). That was a real gap: immutable publication
history is only meaningful if a historical revision can always resolve
every asset it originally referenced.

**Locked rule:** `website_assets.first_published_at` (§5.4) is a durable,
monotonic marker, not a "currently referenced" flag:

- **Never published** (`first_published_at IS NULL`): the asset was
  uploaded but has never appeared in a successful publish. It may be
  deleted at any time, subject only to the ordinary "not blocked by a
  current draft reference" check (deleting an asset a draft page is
  actively pointing at is rejected the same way any dangling-reference
  bug would be — reject the delete, do not silently break the draft).
- **First publish that includes the asset:** `first_published_at` is set
  exactly once, to that moment (§9.1 step 6).
- **A later publish that omits the asset:** `first_published_at` is
  **left untouched** — it is never cleared, never recomputed, never
  re-evaluated against "is this the current revision."
- **Any asset with `first_published_at IS NOT NULL` can never be deleted
  in Slice A** — permanently, regardless of whether it is still part of
  the *current* published revision. This guarantees every retained
  historical revision (§10 — revisions are immutable and never deleted)
  can always resolve every asset it originally referenced; a rollback to
  any historical revision is therefore guaranteed to render successfully,
  images included.

This requires **no expensive historical JSON scan on delete** (the check
is a single indexed column read), **no asset reference-count table**, and
**no revision-assets pivot table** in v1 — exactly the same schema
footprint as the design it replaces, just a different column semantic.

**No automatic background orphan-cleanup job** in Slice A (matches the
stop-list's "no generic workflow engine") — a never-published, currently-
unused asset simply accumulates until manually deleted via the editor UI.
**Physical garbage collection of ever-published-but-no-longer-current
assets is explicitly out of Slice A's scope** and requires its own future,
separately contracted revision-retention/asset-retention policy (e.g., "an
asset unreferenced by any revision younger than N months may be purged") —
Slice A's own guarantee is permanent retention, not automatic cleanup, and
the two are not in tension: retention is the safe default; deciding when
it is safe to prune is a distinct, deliberately deferred decision.

---

## 14. AI WEBSITE GENERATION — LOCKED SEAM

Reuses the exact seam B3 preserved and Lane A (Agency Prospecting) already
consumes — `config('services.openai.*')` (`config/services.php:90-97`:
`active`, `api_key`, `model`, `organization`, `project`, `role`). **No new
config keys. No `ai_settings` table. No second API-key store.** A new
`App\Library\Website\WebsiteAiGenerationClient` (or equivalently named
class) follows the exact fail-closed shape of
`App\Library\AgencyProspecting\OpenAiAgencyProspectingClient` (43 lines,
read in full):

```php
if (! config('services.openai.active') || empty(config('services.openai.api_key'))) {
    return null;
}
try {
    $client = OpenAI::client(config('services.openai.api_key'));
    $result = $client->chat()->create([...]);
    return trim($result->choices[0]->message->content ?? '') ?: null;
} catch (Throwable) {
    return null;
}
```

Generation is **user-initiated only** (an explicit authenticated POST,
§26). It writes to **draft** `website_pages` rows only. It never:
publishes; changes domain infrastructure (irrelevant to Slice A — none
exists yet); runs arbitrary code; writes outside the Website's own
Business; fetches an arbitrary remote URL; deletes a published Website.

---

## 15. AI INPUT CONTEXT — CLASSIFIED

| Field | Classification | Source |
|---|---|---|
| Business name | AVAILABLE NOW | `businesses.name` |
| Description | AVAILABLE NOW | `businesses.description` |
| Industry / category | AVAILABLE NOW | `businesses.industry` (cast `App\Enums\Business\BusinessIndustry`), `industry_other` |
| Phone | AVAILABLE NOW | `businesses.phone` |
| Email | AVAILABLE NOW | `businesses.email` |
| Address / location | AVAILABLE THROUGH RELATED MODEL | `BusinessLocation` (`Business::primaryLocation()`) — `address_line_1/2`, `city`, `region`, `postal_code`, `country_code` |
| Timezone | AVAILABLE NOW | `businesses.timezone` |
| Existing external website URL | AVAILABLE NOW | `businesses.website_url` — read-only context, never overwritten |
| Social links | AVAILABLE NOW | `businesses.google_business_profile_url`, `facebook_url`, `instagram_url` |
| Services | AVAILABLE THROUGH RELATED MODEL | `BusinessService` (`Business::services()`) — `name`, `description`, `starting_price`, `currency_code` |
| Branding/logo | NOT PRESENT PER-BUSINESS — platform-level only | logo/branding lives in `AppConfig`/`BrandingUploadService`, not on `Business`; a Business has **no** logo field or relation |
| Hours | NOT PRESENT — DO NOT INVENT | no column, no relation anywhere in the Business domain |
| Offers / promotions | NOT PRESENT — DO NOT INVENT | no column, no relation on Business; the only `offer` field in the codebase belongs to `AgencyProspectingSetting`, a Workspace-level cold-outreach config, unrelated to a customer's own Business |
| Reviews | NOT PRESENT — DO NOT INVENT | no column, no relation anywhere |
| FAQs | NOT PRESENT — DO NOT INVENT | no column, no relation anywhere; the only `faqs_objections` field in the codebase also belongs to `AgencyProspectingSetting` |

The AI generator may prompt the user for additional Website-specific copy
(e.g., a tagline, testimonial quotes, FAQ answers) directly into the
generation flow, but this **creates Website content only** — it must never
create or write a second, competing Business-profile datastore (no shadow
"hours"/"FAQ"/"reviews" table is authorized by this contract; if a future
pass wants those as real Business data, that is a Business-domain
contract, not a Website one).

---

## 16. AI OUTPUT CONTRACT — LOCKED

AI output is structured JSON matching exactly §7's `WebsiteSectionType`
schema. No generated Blade, PHP, arbitrary HTML, JS, or CSS is ever
accepted from a model response, regardless of what the provider's own
"JSON mode"/structured-output feature claims to guarantee — the API's own
shape guarantee is never trusted as authoritative; server-side validation
is.

**v1 simplification (locked):** AI-generated sections carry **no asset
references** — every `image`/`background_image` field is emitted empty/
absent by the generator and filled in afterward by the human editor. This
removes an entire class of "AI hallucinated an asset uid" failure mode by
construction, at the cost of the initial draft having no images — an
accepted, explicit v1 trade-off.

Every AI response is validated server-side against:

- page count ≤20, sections-per-page ≤40 (§7)
- slug rules and reserved-slug rejection (§6.3/§6.4)
- component `type` ∈ `WebsiteSectionType` (§7)
- per-component field types/lengths/counts (§7.2 table)
- asset references: must be absent/empty (per the v1 simplification above)
  — a non-empty asset reference in raw AI output is itself a validation
  failure
- URL rules (§25) for any `cta`/`hero` button URL the model proposes

Invalid output is rejected safely. **At most one automatic, bounded
retry** is permitted (re-prompting with the specific validation errors
appended) before returning a clear failure to the user — no unbounded
retry loop. Unvalidated model output is **never** persisted, not even
transiently, to any executable or renderable field. Tests use a mocked AI
client exclusively (§34) — no live network call in any test.

---

## 17. EDITING UX — LOCKED

A server-driven M2 editor (Blade + the existing 19-component library from
§7's own boundary statement, used for the *editor* UI, never for *public*
rendering — §38). No drag-and-drop requirement, no SPA framework
requirement.

Screens: Website overview/setup; Pages list; Create/edit page; Section
list; Add section; Edit section; Reorder sections; Delete section;
Preview; Publish; Publication history; Rollback. One bounded per-page
update endpoint (§31.1) is preferred over a sprawling per-section CRUD
API, consistent with "the narrowest route set that avoids generic mutation
APIs."

### 17.1 Draft page update service seam — predecessor seam for a future SEO module

`WebsiteController::updatePage()` (§31.1) is a thin HTTP boundary only. The
authoritative mutation logic for a Business-scoped draft `WebsitePage`
lives in one bounded, Website-owned service:

```php
App\Library\Website\WebsiteDraftPageService
```

(name illustrative — an equally bounded name is acceptable at
implementation time; the architectural property below is what is locked,
not the exact class name). The controller calls this service; the service
is not a second, parallel way to reach the same mutation — it **is** the
mutation.

This service is the **sole supported application seam** for changing any
of: `title`, `slug`, `seo_title`, `meta_description`, `noindex`,
`sections` on a Business-scoped draft `WebsitePage` row. It performs every
invariant this contract already locks for a page mutation, in one place:
Business/Workspace tenancy re-check (§2.2), entitlement (§26.2), the
component/field validation of §7, asset-reference validation (§13), the
homepage invariant (§6.2), and slug rules (§6.3/§6.4). No other code path
in the application — present or future — is authorized to
`UPDATE website_pages` for these six fields directly.

**Why this matters now, before any SEO code exists:** a future, separately
contracted SEO Slice A will want to adjust `seo_title`/`meta_description`/
`noindex` (and plausibly propose slug changes) as part of its own
workflow. Exposing this service today — even though nothing outside
Website calls it yet — means that future SEO module calls
`WebsiteDraftPageService` only, after its own independent Business
authorization, and only ever touches **draft** state (never
`website_revisions`, never bypassing publish) — exactly like every other
caller. **SEO must never write to `website_pages` directly**, and this
contract does not design any SEO-specific method on this service now; the
service's contract today is the same six-field, fully-validated draft
update it already needs to serve its own editor UI (§17). No
SEO-specific behavior, no SEO-aware branch, no SEO import exists in this
service in Slice A.

---

## 18. PUBLIC WEBSITE THEMING — LOCKED

A dedicated, bounded Website theme/presentation config — **not**
`PlatformThemePreset` (that is the SaaS interface's own M2 concept,
`app/Models/PlatformThemePreset.php`, and is never coupled to public
Website output). Stored as `websites.theme` JSON (§5.1), copied into every
snapshot (§11). Bounded initial set: font family (allowlist), primary/
secondary color, button style, content width, header variant, footer
variant — no arbitrary CSS input, no custom CSS field anywhere in the
schema. Public pages get their **own** dedicated CSS bundle, layout,
header, and footer — never importing dashboard/sidebar/card styling
(§38).

---

## 19. PREVIEW — LOCKED

Preview renders the **current draft** (`website_pages`), never the
published revision. Authenticated only, at
`/workspaces/{workspaceUid}/businesses/{businessUid}/website/preview/...`,
using the identical §2.2 authorization chain as every other Website
action. **No public/unauthenticated draft URL** in Slice A. If a
shareable preview link is ever wanted, it must use a short-lived signed
URL and must never expose `Business.uid` (§3.1) — not designed further
here; v1 ships authenticated-only preview.

---

## 20. SEO CORE BOUNDARY — LOCKED

**Website Core (Slice A owns):** page slug; document title (`seo_title`
falling back to page `title`); meta description; the per-page `noindex`
field; canonical URL generation
(`https://{app-host}/sites/{public_id}/{slug|home}`); Open Graph title/
description; OG image reference where a page has one; a basic sitemap
endpoint (§21); semantic heading structure in the rendered component
templates.

**Platform-path indexability — LOCKED (§21):** every Slice A public
Website is served under the platform's own `/sites/{public_id}` path, not
a customer domain — this is the pre-custom-domain hosting phase. Slice A
Websites are **publicly viewable but not search-indexed**: §21 locks a
mandatory `noindex` response directive on every public Website response,
independent of and in addition to the per-page `noindex` field above. The
per-page field is not dead weight — it is Slice A's stored, forward-
compatible SEO primitive, and becomes the live, page-specific indexability
rule once Slice B (§40) enables indexable custom-domain URLs and removes
the platform-wide directive for a verified domain. Slice A itself never
promotes a platform-path URL as indexable.

**SEO Module (explicitly later, not built now):** keyword research, rank
tracking, competitor analysis, content scoring, automated optimization,
schema/structured-data strategy, local SEO, internal-link recommendations,
GBP integration, SEO reporting. `PlatformFeature::SeoBasicVisibility` and
`PlatformFeature::SeoModule` already exist as separate, `Planned`,
unrelated feature keys — Slice A does not touch either.

---

## 21. SITEMAP / ROBOTS ROUTING CONSTRAINT — RE-VERIFIED MECHANICALLY

The repo-root `.htaccess` (not `public/.htaccess`) contains:

```apache
RewriteCond %{REQUEST_URI} (\.\w+$) [NC]
RewriteRule ^(.*)$ public/$1
```

Any request URI ending in a dot-plus-word-characters extension (`.xml`,
`.js`, `.png`, ...) is rewritten straight to a literal static-file lookup
under `public/`, **before** `server.php`/Laravel's router ever runs. A
request to `/sites/{public_id}/sitemap.xml` matches this rule, gets
rewritten to `public/sites/{public_id}/sitemap.xml`, finds no such file,
and Apache 404s directly — Laravel never sees the request.

**Locked route:** `/sites/{public_id}/sitemap` — **extensionless** — see
§3.3's exact route. The controller returns
`response($xml, 200, ['Content-Type' => 'application/xml'])`. Search
engines do not require the URL path itself to end in `.xml`; the
`Content-Type` header is sufficient. `sitemap` is a permanently reserved
page slug (§6.4), so it can never collide with a customer page, and its
route is registered ahead of the generic `{slug}` page route (§3.3's
ordering) as defense in depth on top of the reservation.

**No root `.htaccess` rewrite is touched.** Modifying that rule to special-
case `/sites/*` was considered and rejected — it is a global, security-
relevant rewrite affecting the entire application, and the extensionless
route above achieves the same outcome with zero infrastructure risk. The
sitemap route is retained (not merely as an unused artifact) because it
validates publication/page-inclusion behavior end-to-end (§37.3/§37.9) and
is the exact seam a future Slice B custom-domain SEO integration reuses —
**the sitemap route's existence does not itself authorize indexing**; that
is governed entirely by the noindex directive below.

**Robots and indexing — LOCKED:** a single static
`/home/user/os-ai/public/robots.txt` already exists (`User-agent: *` /
`Disallow:` — everything allowed) and is served directly by the webserver
for the bare `/robots.txt` path, never reaching Laravel — the same
static-file-precedence fact as §21's own `.htaccess` finding. **This
global file is not modified, and no per-Website `/robots.txt` is added in
Slice A** — Slice A does not own a customer host root, so a per-site
robots file is meaningless until Slice B (§40).

Instead, indexability is controlled at the response level: every public
Website HTML response (`public.website.home` and `public.website.page`,
§31.2) emits the response header

```
X-Robots-Tag: noindex, follow
```

and, wherever the public layout already has a canonical `<meta>` seam
(§20), the equivalent `<meta name="robots" content="noindex, follow">`
directive. This is unconditional for every Slice A public page — it does
not depend on a page's own `noindex` field (§6/§14), which remains stored
for Slice B's later use (§20). `follow` (not `nofollow`) is used because
there is no reason to block crawlers from following internal links between
a Website's own pages; only indexing of the platform-path URL itself is
suppressed. The sitemap response itself is unaffected (an XML document has
no robots meta/header concept) and continues to enumerate exactly the
published snapshot's pages (§37.9) regardless of the noindex directive on
the pages it lists — a crawler that already has the sitemap can still see
what pages exist, it is simply told not to index the platform-path URLs.

Slice B (§40) is where the platform-wide `noindex` is explicitly lifted
for a page served from a verified custom domain — not designed here, but
named as a required Slice B decision so it is not silently forgotten.

---

## 22. FORMS BOUNDARY — LOCKED

**No native lead-capture form component in Slice A.** Website content may
include CTA buttons, `tel:` links, `mailto:` links, and external booking/
link buttons (§7's `cta`/`hero`/`contact_details` components already cover
all of these). Building a form builder, or a second Contact-ingestion
path parallel to the existing CRM Contacts pipeline, is explicitly out of
scope — Forms is its own future product module. The future integration
point is recorded here: once a Forms module exists, a `form` component
type could be added to `WebsiteSectionType` (§7) that references a
Forms-owned form definition by uid — not designed further now.

---

## 23. ANALYTICS BOUNDARY — LOCKED

No page-view analytics platform, generic event warehouse, tracking-pixel
framework, or pageview-rollup table is built in Slice A. B5 (Business
Analytics) has its own contract now merged
(`docs/automation/B5-BUSINESS-ANALYTICS-CONTRACT.md`, at `origin/main`
commit `b2bedfc91848c90be2f1fc4e8e0ac440c6d4d892`) but its product
implementation still depends on B4 and is entirely untouched by Website
Slice A — no B5 file, table, or code path is read, written, or referenced
anywhere in this contract. Recorded future event names a later,
separately-contracted Website Analytics pass could emit for B5 to consume:
`page_view`, `cta_click`, `form_submission` (the last only once §22's Forms
integration exists) — **not implemented, not scheduled, no table added.**

---

## 24. HOSTING MODEL — LOCKED

Slice A serves public pages from the existing Laravel application on the
platform's own hostname — no static-site export pipeline (no mechanical
evidence requires one). The public renderer reads only the immutable
`website_revisions.snapshot` reached via `published_revision_id` (§9) —
never the mutable `website_pages` draft table.

### 24.1 Caching

Following this repo's own established `Cache::remember()` conventions
(`app/Http/Controllers/User/UserController.php:58,78`:
`"customer_{$userId}_sms_counts"` style keys with a Carbon TTL;
`app/Http/Controllers/Admin/AdminBaseController.php:49,74`: plain integer-
second TTLs) — no cache tags exist anywhere in this codebase (confirmed:
`AdminBaseController.php:31`'s own comment calls tagging an unrealized
aspiration), so this contract does not introduce one.

Two **separate, independently-keyed** cache entries exist for a public
Website request — never conflated into one:

- **Content snapshot cache** — key `"website_public_{$publicId}_v{$versionNumber}"`,
  TTL 300 seconds. Publishing a new revision naturally produces a **new**
  key (the version number changes), so no explicit invalidation call is
  required for the common path — the old key simply ages out under its own
  TTL and is never read again once `published_revision_id` has moved.
- **Entitlement decision cache** — key `"website_public_entitlement_{$business->id}"`,
  TTL 60 seconds (§26.4).

No cache entry of either kind is ever shared between two Websites/
Businesses. **The entitlement cache is always consulted before the
snapshot cache is allowed to serve a response** (§26.4) — a snapshot-cache
hit never short-circuits past the entitlement gate, and a fresh
`decide()` call on entitlement-cache-miss never skips the snapshot
lookup that follows it.

No premature CDN. No aggregate tables. Public render path never invokes
AI (§14) and never reads the draft (`website_pages`) — both are hard
architectural guarantees, not merely performance choices.

---

## 25. PERFORMANCE — LOCKED

Bounded page/component counts (§7) keep the snapshot small and
predictable. Eager-load pages/assets when building/reading a snapshot (no
N+1). Indexes per §5. No aggregate/rollup table is introduced (§23).

**Query-count guarantee — bounded, not a promised exact number.** This
contract does not claim "one database query" for a public request — that
would require redesigning route-model-binding and eager-loading choices
this contract does not make, and an implementation that mechanically
guarantees a specific count is free to do so, but is not required to. What
**is** locked:

- Resolving `{website:public_id}` (§3.3) via implicit route-model binding
  is itself one query; `business`/`workspace` (needed for §26.4's cheap
  state checks and, on cache-miss, for `decide()`) are loaded via eager
  relations on that same binding — bounded, no N+1, not a second round
  trip per field.
- On both caches hit (the common case after warm-up): zero additional
  queries beyond the binding/eager-load above — both the entitlement
  decision and the snapshot content are served from cache.
- On entitlement-cache-miss: exactly one `EntitlementManager::decide()`
  call, whose own internal read chain (§26.2's 8-step plan/override/
  usage-gateway sequence, `EntitlementManager.php:111-186`) is that
  class's existing, unmodified implementation — not redesigned or
  re-bounded by this contract.
- On snapshot-cache-miss: one additional query for the published
  `website_revisions.snapshot` row.
- The public render path **never** invokes AI (§14), **never** reads the
  draft `website_pages` table, and **never** runs a per-request full
  entitlement decision tree when the entitlement cache is warm (§26.4) —
  these three are the hard architectural guarantees; the exact query count
  around them is an implementation-time detail, not a number this contract
  fixes.

---

## 26. ENTITLEMENT — LOCKED

### 26.1 Availability flip — no new packaging or pricing decision needed

`PlatformFeatureRegistry::AVAILABILITY[PlatformFeature::WebsiteGeneration->value]`
moves from `Planned` to `Available` as part of the Slice A implementation
— narrowly, mirroring the exact evidentiary bar already applied to
`ProspectOutreach`'s own flip (`PlatformFeatureRegistry.php`'s class
docblock, lines 19-29: flip only once "a real, executable... controller/
routes/persistence" exists). No other `Planned` feature is touched.

**Confirmed mechanically:** `website_generation` is already packaged into
every Workspace plan tier by the existing
`database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`
(lines 93-100): it is a member of `$coreFeatures`, which
`$growthFeatures` and `$agencyFeatures` both `array_merge()` in full — so
Core, Growth, and Agency all already include it in their
`workspace_plan_features` packaging row, seeded independently of the
registry's own availability lock (that migration's own docblock, lines
12-15, states packaging a `Planned` feature is "a valid, honest seed row,
not a promise of current executability"). **Slice A therefore requires no
new plan-feature packaging migration and no pricing decision merely to
become executable for an existing Workspace on any of these three tiers.**
The single narrow registry change (`Planned` → `Available`) is the entire
availability-side change this contract authorizes, applied only once
implementation lands.

### 26.2 Authenticated mutation gate

Every Website mutation (create, edit, generate, publish, rollback, asset
upload/delete) calls:

```php
$decision = $entitlementManager->decide($workspace, $business, PlatformFeature::WebsiteGeneration->value, (int) Auth::id());
```

(`App\Library\Entitlement\EntitlementManager::decide()`,
`app/Library/Entitlement/EntitlementManager.php:111-186`) and branches on
`$decision->allowed`/`$decision->reason` — never a bare boolean helper.
`decideForWorkspace()` is never used (`WebsiteGeneration` is Business-
scoped, §2). This mutation-path decision is always freshly computed —
never cached. §26.4 covers the one place a cached `decide()` result is
read instead of a fresh one: anonymous public rendering, bounded to a
60-second TTL.

### 26.3 Background/public-render identity — RESOLVED MECHANICALLY

`EntitlementManager::decide()` accepts `int $actorUserId` but never reads
it in the method body (verified across the same lines B4's own contract
already verified this for, `EntitlementManager.php:111-186` — the
parameter is signature/audit-only, confirmed independently in this pass).
Anonymous public rendering has no browser session and must never call
`Auth::id()` or fabricate one. Mirroring the exact precedent B4 already
established and locked (`B4-BUSINESS-AUTOMATIONS-CONTRACT.md` §2.5, citing
`OutreachController::sendSms()`'s own `$business->customer->user` usage):
whenever a background/public code path in this feature needs an
`$actorUserId` argument, it passes **`(int) $business->customer_id`** —
the Business's real persistence owner — never a session user, never a
placeholder like `0` or `1`.

### 26.4 Public rendering — bounded 60-second cached entitlement gate — LOCKED

Public hosting **must respect Website entitlement** — a public route that
never re-checks entitlement at all would let cache expiry (§24.1) do
nothing, since a snapshot cache miss would simply reload and keep serving
the same still-published Website forever. Running the full 8-step
`decide()` chain (`EntitlementManager.php:111-186`) on every anonymous page
view is too expensive to do uncached (§25), so this contract locks a
**short-lived cached decision** instead of either extreme (never-checked,
or checked-uncached-per-request):

1. **Cheap, mandatory, always-fresh state checks** (no caching, negligible
   cost — already-loaded columns on the rows this request resolves
   anyway): `websites.status === 'published'` **and**
   `published_revision_id` set; `businesses.status === BusinessStatus::Active`;
   `workspaces.is_active === true`. Any failure is an immediate 404 (§27).
   These checks are mandatory on **every** request, cache hit or miss, and
   do not replace the entitlement gate below — they are a separate,
   additional layer.
2. **Cached entitlement gate**, keyed per Business:
   `"website_public_entitlement_{$business->id}"`, TTL **60 seconds**.
   - **Cache hit:** use the cached `allowed` boolean.
   - **Cache miss:** call
     ```php
     $decision = $entitlementManager->decide(
         $workspace,
         $business,
         PlatformFeature::WebsiteGeneration->value,
         (int) $business->customer_id,   // §26.3 — never Auth::id(), never fabricated
     );
     ```
     store `$decision->allowed` under that key for 60 seconds, and gate on
     it immediately.
3. If either the cheap checks (step 1) or the cached entitlement gate
   (step 2) is negative, the public route returns **404** — regardless of
   whether a snapshot (§24.1) is otherwise cached and ready to serve. **A
   snapshot-cache hit is never permitted to bypass the entitlement gate**:
   the entitlement check runs before the snapshot is returned, on every
   request, whether the entitlement result itself came from cache or from
   a fresh `decide()` call.

**Consequence, precisely bounded:** entitlement revocation (plan
suspension, a `disabled_for_business` toggle, a workspace override denial,
or the feature going unavailable) takes effect for public rendering within
**at most 60 seconds** of being set — never indefinitely, and never
dependent on the separate, longer-lived snapshot cache (§24.1) expiring.
`websites.status`/`published_revision_id` are not touched by an
entitlement revocation — the underlying data is untouched and rendering
resumes automatically, within the same 60-second bound, if entitlement is
restored. No background revalidation job, no scheduled worker, no new
entitlement system — the existing `Cache` facade with a plain per-Business
key and a 60-second TTL is the entire mechanism, matching this
repository's own established `Cache::remember()` conventions (§24.1).

---

## 27. BUSINESS / WORKSPACE DISABLE SAFETY — LOCKED

| Condition | Public behavior |
|---|---|
| Workspace `is_active = false` | 404 (checked directly, §26.4) |
| Business `status !== Active` | 404 (checked directly, §26.4) |
| Website `status !== published` or `published_revision_id` null | 404 |
| Feature entitlement removed | 404 within at most 60 seconds (§26.4's cached gate) — blocks both future mutation (§26.2, always fresh) and public rendering (§26.4, cached, bounded) |
| Business deleted | no hard-delete route exists for Business anywhere in this codebase today (`routes/admin.php:582-584`: `Route::resource('businesses', ..., ['only' => ['index','show','edit','update']])` explicitly excludes `destroy`; no customer-side destroy route exists either) — this state is **not currently reachable**. The `restrictOnDelete()` FK on `websites.business_id` (§5.1) is a defensive choice for if that ever changes, not a designed-for scenario. |

No destructive auto-delete anywhere in this table. Fail-safe default is
always "public route unavailable," never "serve stale content
indefinitely" — matching the task's own stated preference.

---

## 28. AUTHORIZATION — LOCKED

One new customer capability, mirroring Automations' own single-capability
precedent exactly (`config/customer-permissions.php:25-29`:
`'automations' => ['display_name' => 'automations', 'category' =>
'Automations', 'default' => true]`):

```php
'website' => [
    'display_name' => 'website',
    'category'     => 'Website',
    'default'      => true,
],
```

checked identically to the existing convention
(`app/Http/Controllers/Customer/AutomationsController.php:62`:
`$this->authorize('automations')`) — every Website controller action calls
`$this->authorize('website')`.

**A capability alone is never tenancy.** Every mutation requires, in this
exact order: capability (`$this->authorize('website')`) **+** §2.2's
Workspace/Business access chain **+** the Website resolved scoped to that
Business **+** §26.2's entitlement decision. A foreign `website`/`page`/
`revision`/`asset` uid 404s exactly like a nonexistent one — never a
distinguishable 403 that would leak existence.

---

## 29. URL SECURITY — LOCKED

- Reserved public prefix: `sites` (confirmed zero collision — neither
  `routes/public.php` nor `routes/web.php` defines any `sites`/`site`
  top-level segment today).
- Slug normalization, length, and character rules: §6.3.
- No dot-segments, no encoded/literal slash, no directory traversal — all
  structurally excluded by §6.3's regex.
- No protocol prefix can appear in a slug (excluded by the same regex).
- No open redirect: Slice A has no "return"/"redirect_to" parameter
  anywhere in its public or authenticated surface; none is introduced.
- **CTA/link URL scheme allowlist:** `https`, `tel`, `mailto` only.
  **`http` is not allowed** in Slice A (stricter than the task's own
  "http only if deliberately allowed" — since this is a new, greenfield
  surface with no legacy plain-http requirement, defaulting to https-only
  removes an entire class of mixed-content/spoofing concern for free).
  `javascript:`, `data:`, `file:`, `vbscript:`, and any other scheme are
  rejected outright by an explicit allowlist check (never a denylist) on
  every user- or AI-authored URL field (§7.2's `hero`/`cta` buttons).

---

## 30. XSS / INJECTION — LOCKED

Every text field in §7.2 renders through Blade's default escaped output
(`{{ }}`) — never `{!! !!}}`, never `Blade::render()`, never `eval()`, on
any user- or AI-authored value, full stop (§8). Test coverage (§34)
explicitly proves: script content in text fields, event-handler attribute
strings, `javascript:` links, HTML embedded in AI output, malicious
alt-text/title/meta values, Blade-expression-shaped strings (`{{ }}`,
`{!! !!}}`, `@php`) treated as inert text, path traversal in slugs and
asset requests, and unsafe filenames — all render or are rejected as
**inert data**, never as executable template/markup.

---

## 31. PROPOSED ROUTES — LOCKED

### 31.1 Authenticated (`routes/customer.php`, inside the existing `workspaces` prefix, sibling to B1/B2's own Business-scoped groups)

```php
Route::prefix('{workspaceUid}/businesses/{businessUid}/website')->name('businesses.website.')->group(function () {
    Route::get('/', 'Business\WebsiteController@show')->name('show');
    Route::get('/setup', 'Business\WebsiteController@setup')->name('setup');
    Route::post('/', 'Business\WebsiteController@store')->name('store');

    Route::get('/pages', 'Business\WebsiteController@pages')->name('pages.index');
    Route::get('/pages/create', 'Business\WebsiteController@createPage')->name('pages.create');
    Route::post('/pages', 'Business\WebsiteController@storePage')->name('pages.store');
    Route::get('/pages/{pageUid}/edit', 'Business\WebsiteController@editPage')->name('pages.edit');
    Route::put('/pages/{pageUid}', 'Business\WebsiteController@updatePage')->name('pages.update');
    Route::delete('/pages/{pageUid}', 'Business\WebsiteController@destroyPage')->name('pages.destroy');

    Route::get('/preview/{pageUid?}', 'Business\WebsiteController@preview')->name('preview');

    Route::post('/generate', 'Business\WebsiteController@generate')->name('generate');
    Route::post('/publish', 'Business\WebsiteController@publish')->name('publish');

    Route::get('/history', 'Business\WebsiteController@history')->name('history');
    Route::post('/history/{revisionUid}/rollback', 'Business\WebsiteController@rollback')->name('history.rollback');

    Route::post('/assets', 'Business\WebsiteController@storeAsset')->name('assets.store');
    Route::delete('/assets/{assetUid}', 'Business\WebsiteController@destroyAsset')->name('assets.destroy');
});
```

`updatePage` is the one bounded per-page-update endpoint carrying the
page's full `sections` array in its request body (§17) — no separate
per-section CRUD/reorder endpoint family, keeping the route set narrow per
the task's own instruction.

### 31.2 Public (`routes/public.php`, new prefix — §29 confirms zero collision)

```php
Route::prefix('sites')->group(function () {
    Route::get('{website:public_id}', 'Public\WebsiteController@home')->whereUuid('website')->name('public.website.home');
    Route::get('{website:public_id}/sitemap', 'Public\WebsiteController@sitemap')->whereUuid('website')->name('public.website.sitemap');
    Route::get('{website:public_id}/{slug}', 'Public\WebsiteController@page')->whereUuid('website')->name('public.website.page');
});
```

No public draft route exists anywhere.

---

## 32. NAVIGATION — LOCKED

Website Generation gets exactly one Business-product navigation entry,
shown only once `PlatformFeatureRegistry::isAvailable(PlatformFeature::WebsiteGeneration->value)`
is true and (for a specific Business context) `EntitlementManager::decide()`
allows it. **Not** placed under Admin Settings (B3's architecture is
untouched, per the stop-list). Exact future location: the customer-side
per-Business navigation region of `app/Helpers/Helper.php` (the same
Helper file B3/B4 both note as the one authorized nav-edit surface for
their own single link) — one new entry, no broader navigation refactor.

---

## 33. MIGRATION CONTRACT — LOCKED

**Exactly five migration files** (exact filenames/timestamps assigned at
implementation time, in this repo's existing
`YYYY_MM_DD_HHMMSS_create_..._table.php` convention), creating **exactly
four domain tables** (§5) — the fifth migration adds one nullable FK
column to a table created earlier, resolving the `websites` ↔
`website_revisions` circular reference explicitly rather than leaving it
ambiguous:

1. `create_websites_table` — §5.1, **without** `published_revision_id`.
   `business_id` FK `restrictOnDelete()`, unique. All other columns exactly
   as §5.1. Rollback: `dropIfExists('websites')`.
2. `create_website_pages_table` — §5.2. `website_id` FK
   `cascadeOnDelete()`. Rollback: `dropIfExists('website_pages')`.
3. `create_website_revisions_table` — §5.3. `website_id` FK
   `cascadeOnDelete()`, `created_by` FK to `users.id` `restrictOnDelete()`.
   Rollback: `dropIfExists('website_revisions')`.
4. `add_published_revision_id_to_websites_table` — adds
   `published_revision_id`: nullable `foreignId`, `->constrained('website_revisions')`,
   `->nullOnDelete()`. This is the **only** migration that alters a table
   created by an earlier migration in this set.
   `down()` drops the foreign key constraint **first**, then drops the
   column — never the reverse order, and never via disabling FK checks.
5. `create_website_assets_table` — §5.4. `website_id` FK
   `cascadeOnDelete()`. Rollback: `dropIfExists('website_assets')`.

**Dependency order is unambiguous:** 1 → 2 → 3 → 4 (needs `website_revisions`
from step 3 and `websites` from step 1) → 5. No forward reference exists at
any point — migration 1 never mentions `website_revisions`, and migration
4 runs strictly after both tables it references already exist.

**Full-rollback order** (Laravel's own `migrate:rollback`, running each
migration's `down()` in reverse creation order) is therefore: 5 (drop
`website_assets`) → 4 (drop the FK, then the `published_revision_id`
column) → 3 (drop `website_revisions`) → 2 (drop `website_pages`) → 1
(drop `websites`) — always a clean drop, never a leftover dangling FK,
never a disabled-FK-checks workaround, and never a nullable integer column
left without a real, eventual FK constraint.

No table uses a database ENUM type (§5.1's own rationale). No table adds
`workspace_id`. No table stores a secret. No migration touches any
table that exists before this feature — this is purely additive, mirroring
B4's own "Schema — Additive Only" framing; migration 4 is additive to a
table this same feature just created two migrations earlier, not to any
pre-existing table.

---

## 34. DELETE / ARCHIVE SEMANTICS — LOCKED

- **Website**: no hard delete in v1. `status` supports `archived`
  (§5.1/§6) — an owner-initiated action that stops public serving
  (behaves identically to "unpublished" for §26.4/§27's render checks)
  while retaining all pages/revisions/assets untouched. Reversible (an
  archived Website can be re-published by an authorized owner). No
  evidence in this codebase justifies hard delete for a
  "one canonical record per Business" table (§4's own three precedents —
  wallets, billing contacts, payer assignments — are all similarly
  undeletable in the product).
- **Page**: hard delete is allowed on the draft working set (§6) at any
  time, **except** deleting the current homepage without simultaneously
  promoting a replacement (§6.2), and except deleting a Website's only
  remaining page (a Website must always retain at least one page). Page
  history survives independently inside any already-created revision
  snapshot — deleting a draft page never touches `website_revisions`.
- **Asset**: §13.1.
- **Revision**: never deleted by any code path in Slice A (§10, §5.3).

Business deletion cascade policy: moot per §27's own table — no Business
hard-delete path exists to cascade from.

---

## 35. IMPLEMENTATION ALLOWLIST (for the future Slice A implementation pass)

**Enums**
- `app/Enums/Website/WebsiteStatus.php` *(new)*
- `app/Enums/Website/WebsiteSectionType.php` *(new)*

**Models**
- `app/Models/Website.php` *(new)*
- `app/Models/WebsitePage.php` *(new)*
- `app/Models/WebsiteRevision.php` *(new)*
- `app/Models/WebsiteAsset.php` *(new)*

**Library / services**
- `app/Library/Website/**` *(new — snapshot builder, publish/rollback
  service, section validator, asset upload service, AI generation client
  and prompt/context builder, and `WebsiteDraftPageService` (§17.1) — the
  one sole authorized seam for draft `title`/`slug`/`seo_title`/
  `meta_description`/`noindex`/`sections` mutation)*
- `app/Events/Website/WebsitePublished.php` *(new — §9.2/§10, dispatched
  after commit on both publish and rollback; three scalar identifier
  fields only)*
- `app/DTO/Website/**` *(new, if a typed DTO is preferred over an array
  shape for the snapshot/section payloads)*

**Controllers / requests**
- `app/Http/Controllers/Customer/Business/WebsiteController.php` *(new)*
- `app/Http/Controllers/Public/WebsiteController.php` *(new)*
- `app/Http/Requests/Website/**` *(new)*

**Routes / nav**
- `routes/customer.php` *(new Business-scoped group, §31.1)*
- `routes/public.php` *(new `sites` prefix, §31.2)*
- `app/Helpers/Helper.php` *(customer Website nav entry only, §32)*

**Entitlement**
- `app/Library/Entitlement/PlatformFeatureRegistry.php` *(one line:
  `WebsiteGeneration` `Planned` → `Available`, §26.1 — nothing else in
  this file changes)*
- `config/customer-permissions.php` *(one new `website` capability, §28)*

**Migrations**
- the five migration files (four domain tables) in §33

**Views**
- `resources/views/customer/business/website/**` *(new — authenticated
  editor, M2 components)*
- `resources/views/public/website/**` *(new — public renderer, its own
  layout/CSS, §18/§38)*

**Assets (styling)**
- a dedicated Website-only CSS bundle *(new, narrowly scoped — not a
  design-system project, §38)*

**Tests**
- `tests/Feature/Website/**` *(new)*
- `tests/Unit/Website/**` *(new)*
- `tests/Feature/Security/WebsiteSecurityTest.php` *(new)*

Exact class/file names may adapt to reviewer preference at implementation
time; the **families** above are the authorized surface. No unspecified
broad refactor is authorized.

---

## 36. IMPLEMENTATION STOP-LIST

Explicitly forbidden in the Slice A implementation pass:

B3 Settings (`SettingsController`, `EloquentSettingsRepository`,
`AppConfig`, admin settings views/requests/routes/nav); B4 Automations (all
`Automation*`); B5 Analytics; Agency Prospecting (all `AgencyProspect*`,
`agency_prospect*` tables, `routes/customer.php`'s Prospecting block,
`routes/public.php`'s prospecting webhook block, `AppServiceProvider`
prospecting bindings, `VerifyCsrfToken`'s prospecting exemption); B1
Outreach; B2 Messaging Channels architecture; Usage Wallet/Billing/RFC-005
internals; Calendar; Forms/Surveys; Payments/Invoicing; the SEO module;
Google Business Profile integration; Ads; AI COO / AI Workforce
architecture; the Opportunity Engine; `chat_boxes` tenancy; DLR; provider
credentials; `LegacyBusinessResolver`; any Business-tenancy backfill;
Admin Reports; customer Reports/B5 cleanup; custom-domain/TLS
infrastructure (Slice B, §40); any DNS provider API; a generic CMS; a
generic workflow engine; a generic analytics warehouse; `config/services.php`
(the OpenAI seam is read-only for this feature, §14); `AI-AUTONOMY-STATE.json`;
any RFC document.

Also explicitly forbidden: any SEO-specific method, branch, or import on
`WebsiteDraftPageService` (§17.1) — its contract in Slice A is exactly the
generic six-field draft update its own editor UI needs, nothing SEO-aware;
any code path other than that service writing to `website_pages`'
`title`/`slug`/`seo_title`/`meta_description`/`noindex`/`sections`
columns; any listener on `WebsitePublished` (§9.2) shipped as part of this
feature (Slice A dispatches the event and stops — it does not consume its
own event); and any generic event-bus/message-queue architecture —
`WebsitePublished` is one ordinary Laravel event, not a new pub/sub
system.

No unrelated cleanup.

---

## 37. TEST CONTRACT

### 37.1 Tenancy

Owner access; Workspace admin/staff access; selected-staff-scope
assignment; foreign Workspace; foreign Business; foreign Website uid;
foreign page uid; foreign revision uid; foreign asset uid; inactive
membership; inactive Business; inactive Workspace; entitlement denied
(each of `decide()`'s reason strings reachable: `platform_feature_unknown`,
`platform_feature_unavailable`, `wrong_feature_scope`,
`workspace_plan_unassigned`, `denied_by_workspace_override`,
`not_entitled_by_plan`, `disabled_for_business`, `plan_suspended`,
`plan_inactive`, `usage_unauthorized`).

### 37.2 Domain model

One Website per Business (second create attempt rejected); unique
`public_id`; `public_id` never equal to `Business.uid` in any fixture;
exactly one homepage enforced under concurrent-looking sequential
requests; slug uniqueness within a Website; reserved-slug rejection;
traversal/encoded-slash/dot-segment rejection; non-ASCII slug rejection.

### 37.3 Draft / publish

Draft edit does not change live output; first publish creates an
immutable revision; second publish creates a new revision leaving the
first byte-for-byte unchanged; public renderer reads only the published
snapshot, never `website_pages`; rollback moves the pointer without
mutating any revision row; unpublished Website 404s publicly; archived
Website 404s publicly.

**Draft page update service seam (§17.1):** `WebsiteDraftPageService` (or
its implementation-time equivalent) is the only code path a feature test
can find writing `website_pages`' `title`/`slug`/`seo_title`/
`meta_description`/`noindex`/`sections` columns — proved by exercising
every one of those fields through the controller and asserting the same
tenancy/validation/homepage-invariant rules apply uniformly (no field
bypasses §2.2/§6/§7/§13 by taking a different code path than the others).

**`WebsitePublished` event (§9.2/§10):** dispatched exactly once after a
successful publish transaction commits, carrying the correct
`websiteId`/`websiteRevisionId`/`businessId`; **not** dispatched if the
publish transaction is rolled back (e.g. a mid-transaction validation
failure); dispatched again, with the earlier revision's id, after a
successful rollback (§10's locked "rollback also emits" decision); the
event is dispatched strictly after commit (assert no listener observes it
inside an open transaction, e.g. via `DB::transaction()` boundary
assertions or a fake event listener recording dispatch order relative to
commit).

### 37.4 Public rendering

Home render; page render; unknown site 404; unknown page 404; a page that
exists only in the draft (never published) 404s publicly; Business-
inactive/Workspace-inactive 404 (§27); no `Auth::id()`/session dependency
anywhere on the public render path (grep-provable); cache isolation
between two different Websites' identical version numbers.

### 37.5 Entitlement / public hosting (§26)

- An allowed Business (fresh `decide()` returns `allowed: true`) serves
  its published Website normally.
- `disabled_for_business` → 404 once the 60-second entitlement cache
  (§26.4) has revalidated (prove via cache time-travel/manual cache-store
  manipulation, never `sleep()`).
- `denied_by_workspace_override` → 404 under the same revalidation
  window.
- `plan_suspended` / `plan_inactive` → 404 under the same window.
- `WebsiteGeneration` unavailable (registry flipped back, or a fresh
  install before the flip) → 404.
- No `Auth::id()`/browser session is created or required anywhere on this
  path; the `(int) $business->customer_id` actor-id argument (§26.3) is
  asserted directly in the test, not inferred from a session.
- A cached `allowed: true` result has a bounded TTL: advance test time (or
  manipulate the cache store directly) past 60 seconds with the
  underlying `decide()` now returning `allowed: false`, and assert the
  very next public request 404s — proving the cache cannot serve a stale
  `true` past its bound.
- **Content-cache-cannot-bypass-entitlement regression:** warm the
  300-second snapshot cache (§24.1) with an allowed decision, then flip
  the underlying entitlement to denied and let only the 60-second
  entitlement cache (not the 300-second snapshot cache) expire; assert
  the request now 404s even though the snapshot cache is still warm —
  proving a snapshot-cache hit alone can never serve a response without
  the entitlement gate passing first.

### 37.6 Components

Only allowlisted `WebsiteSectionType` values accepted; malformed `data`
shape rejected per-type; unknown type rejected; per-type field-count/length
limits enforced (§7.2); section order preserved through save/publish.

### 37.7 Security

Text-field XSS escaped; `javascript:`/`data:`/`file:`/`vbscript:` CTA
rejected; raw HTML/script in any field rejected or escaped, never
rendered as markup; Blade-expression-shaped strings treated as inert text;
slug/path traversal blocked; no remote-URL asset fetch path exists to
test against (§13); SVG upload rejected; MIME-spoofed upload (correct
extension, wrong magic bytes) rejected; oversized upload rejected;
cross-Business asset reference rejected even when both Businesses belong
to the same acting user.

### 37.8 AI

Mocked OpenAI client only, no live network call; structured-output
validation rejects an invalid component; AI-authored draft cannot be
auto-published; AI cannot reference a foreign Business's asset (moot given
§16's no-asset-reference rule, but tested to prove the rule holds); the
prompt/context payload sent to the client contains only the §15
AVAILABLE-classified fields for the one Business being generated for,
never another Business's data; no secret (API key, `.env` value) appears
in any stored `website_pages`/`website_revisions` row.

### 37.9 SEO

Title/meta render escaped; canonical URL correct for home and for a
slugged page; the per-page `noindex` field is stored and survives the
snapshot build unchanged (§20/§11) — forward-ready for Slice B, not yet
acted on by the public renderer's own indexability decision (§37.10 covers
that); sitemap includes only pages from the current published snapshot
(never draft-only pages); the sitemap route resolves correctly despite
being extensionless (regression-proves §21's `.htaccess` finding);
`sitemap` as an attempted page slug is rejected at creation time.

### 37.10 Indexing (§21/§20)

- The home response (`public.website.home`) carries the response header
  `X-Robots-Tag: noindex, follow`.
- A slugged page response (`public.website.page`) carries the same header.
- `public/robots.txt` is byte-identical to its pre-implementation content
  (a direct file-content assertion) — proving no per-Website or modified
  global robots file was introduced.
- The sitemap route (§21) continues to resolve and list only the current
  published snapshot's pages, independent of the noindex directive on the
  pages it lists (an XML document, not subject to the header/meta
  directive itself).
- The stored per-page `noindex` field (§37.9) round-trips through a
  publish/snapshot cycle unchanged, proving it is ready for Slice B to act
  on later without a schema change.

### 37.11 Revision asset retention (§13.1/§10)

The exact required regression:

1. Publish revision 1 with an Asset A referenced by one of its sections.
2. Edit the draft to remove Asset A's reference and publish revision 2
   (revision 2's snapshot does not mention Asset A).
3. Attempt to delete Asset A → rejected (its `first_published_at` is
   non-null from step 1 and is never cleared by step 2).
4. Roll back to revision 1.
5. Request the public page containing Asset A → renders successfully,
   asset resolves (proving retention, not merely rejection of the delete
   attempt in isolation).

Additional coverage: a never-published asset (`first_published_at` still
`NULL`) can be deleted once no current draft page references it; deleting
an asset a draft page currently references is rejected independent of
publish history.

### 37.12 Migrations (§33)

- The five-migration dependency sequence runs in order with no forward-
  reference error (`create_websites_table` → `create_website_pages_table`
  → `create_website_revisions_table` → `add_published_revision_id_to_websites_table`
  → `create_website_assets_table`).
- Migration 4's `down()` drops the foreign key constraint before dropping
  the `published_revision_id` column (assert both operations occur, in
  that order, e.g. via a schema-introspection check immediately after
  `down()` partially completes, or by asserting the full rollback below
  succeeds without a dangling-FK database error).
- A full `migrate:rollback` across all five files completes cleanly in
  reverse order (`website_assets` → the `published_revision_id`
  column/FK → `website_revisions` → `website_pages` → `websites`) with no
  disabled-FK-checks workaround and no leftover table/column.

### 37.13 Forms / Analytics boundary

No form-builder route, controller, or component exists (assertion against
the route list and the `WebsiteSectionType` enum); no Website-specific
analytics/event table exists in the schema (assertion against
`information_schema`/migration file list, or simply the absence of any
such migration among §33's five).

### 37.14 Custom-domain boundary

No hostname-based Website resolver exists in Slice A (public resolution is
`public_id`-only, §3); no `website_domains` table exists (§5); no
TLS/DNS/ACME code exists anywhere in the diff.

### 37.15 Regression

Workspace/Business tenancy tests (`tests/Feature/Workspace/**`,
`tests/Feature/Business/**`); Branding; B3 Settings
(`tests/Feature/Settings/**`); M2 component tests
(`tests/Feature/DesignSystem/**`); Entitlement
(`tests/Feature/Entitlement/**` if present, else the registry/decision
tests wherever they live); the AI canonical seam (a targeted assertion
that `config/services.php`'s `openai` block is byte-identical to base,
mirroring B3's own `PlatformSettingsAiSeamTest` pattern); one full suite
run at implementation time.

No sleep-based concurrency test anywhere in this contract's scope — the
entitlement-cache-TTL tests (§37.5) use time travel (e.g. `Carbon::setTestNow()`/
`Date::setTestNow()`) or direct cache-store manipulation, never `sleep()`.

---

## 38. PUBLIC WEBSITE VISUAL BOUNDARY — LOCKED

| | SaaS M2 | Website Component Library |
|---|---|---|
| Audience | Platform operator / Business staff, authenticated | Anonymous public visitor |
| Components | `resources/views/components/*.blade.php` (19 files, §7's own listing) | `WebsiteSectionType`-backed templates, §7 |
| Layout/nav | Admin/customer sidebar, navbar, breadcrumbs | Dedicated public `layout`, `header`, `footer` |
| CSS | Existing dashboard/M2 bundle, theme presets | Dedicated Website-only CSS bundle, §18 |
| Never | Rendered to an anonymous visitor | Imports dashboard/sidebar/card styling, or reuses `PlatformThemePreset` |

No full parallel design-system project is authorized beyond what §7/§18
require — this is a small, bounded rendering layer, not a second product.

---

## 39. GOVERNANCE — WEBSITE AI GENERATION AND OTHER FORBIDDEN COMBINATIONS

Explicitly re-stated for the implementer, cross-referencing where each is
locked above: AI can never auto-publish (§14/§16); raw AI output is never
persisted unvalidated (§16); `Business.uid` is never used as a public
identifier (§3.1); Workspace never owns a Website (§2/§4);
`LegacyBusinessResolver`/primary-Business inference is never used (§2.3);
no arbitrary HTML/JS/CSS content primitive exists (§8); the Platform
Advanced `custom_script` setting is never reachable from Website content
(§8); custom domains and `website_domains` do not exist in Slice A (§40);
no form builder or analytics warehouse is built (§22/§23); no new SEO
module is built (§20); Business data is never duplicated into a shadow
store (§15); the M2 dashboard UI is never reused as public Website output
(§38); no provider credential is introduced (none is needed — the OpenAI
seam is the only external call, §14); uploads are bounded and MIME-
verified, never unbounded (§13); SVG is excluded (§13); the public route
never serves a draft (§9/§19); a live page-level read never bypasses the
revision snapshot except the one named §7.3 exception; no generic
CMS/workflow framework exists (§35's allowlist is exhaustive); no B3/B4/B5
path is edited (§36); no RFC is edited (§36); public rendering never
serves a response without passing the 60-second cached entitlement gate
(§26.4); no Slice A public page is search-indexable (§20/§21); an
ever-published asset is never physically deletable in Slice A (§13.1);
`WebsiteDraftPageService` (§17.1) carries no SEO-specific method and is
the only writer of the six draft-page fields it owns; `WebsitePublished`
(§9.2) carries no SEO logic, is dispatched only after commit, and ships
with zero listeners in Slice A.

---

## 40. CUSTOM DOMAINS — SLICE B BOUNDARY (future contract, not designed here)

Recorded so a future Slice B contract starts from the right blocking
questions rather than re-discovering them:

**Slice B must eventually address** (schema/behavior, not designed now):
`website_domains` table; Business ownership of a domain; global domain
uniqueness; a verification token; DNS instructions (CNAME/A record
strategy, apex-vs-`www`); verification status lifecycle; IDN/punycode
normalization; ownership-takeover protection; host-header validation;
`App\Http\Middleware\TrustHosts` integration (§40.1); reverse-proxy
configuration; automatic TLS issuance/renewal/failure-retry state; domain
deletion/transfer; the "must be published before a domain can attach"
rule; canonical `www`↔apex redirect policy.

### 40.1 `TrustHosts` — confirmed unregistered today

`App\Http\Middleware\TrustHosts` exists (extends the framework middleware,
`hosts()` returns `[$this->allSubdomainsOfApplicationUrl()]`) but is
**absent** from `app/Http/Kernel.php`'s `$middleware` stack (confirmed:
the seven-entry global stack —
`CheckForMaintenanceMode`/`ValidatePostSize`/`TrimStrings`/
`ConvertEmptyStringsToNull`/`TrustProxies`/`HandleCors`/
`PreventRequestsDuringMaintenance` — does not include it). It is inert
today. Slice A's `/sites/{public_id}` route resolves entirely by path, not
by hostname, so this is a non-issue for Slice A — but any future
hostname-based resolution (Slice B's entire premise) requires activating
and correctly configuring `TrustHosts` (or an equivalent) **before**
routing on an arbitrary customer-supplied `Host` header, or the
application is exposed to host-header injection/cache-poisoning-style
attacks the moment it starts trusting a customer domain.

### 40.2 Blocking deployment questions before Slice B can be scoped

- Production ingress / reverse-proxy topology (unknown from application-
  code inspection alone — infrastructure, not this repository).
- Wildcard vs. arbitrary-host routing strategy at that ingress layer.
- Certificate issuer and ACME automation approach.
- TLS termination point (ingress vs. application).
- DNS verification approach (TXT record vs. CNAME-target vs. HTTP file
  challenge).
- The platform's own canonical domain and `www`/apex redirect policy.
- Whether object storage/CDN is introduced for asset serving at that
  scale (Slice A explicitly does not need one, §24).

**This contract does not claim the current shared-host deployment can
automatically issue arbitrary customer TLS certificates** — no evidence in
this repository (application code only) proves or disproves that; it is
an infrastructure/deployment decision entirely outside what a Laravel
codebase inspection can resolve, and is exactly why Slice B is a separate
contract rather than an extension of this one.

---

WEBSITE GENERATION + HOSTING — IMPLEMENTATION CONTRACT READY FOR HUMAN REVIEW
