# Niche Blueprint V2 — the Blueprint Workspace

Status: implemented on `agent/niche-blueprint-v2` (base `ad69a748`). Extends
Implementation Contract 20 (`implementation-contracts/20-NICHE-BLUEPRINT-VERSIONING.md`),
which remains the authority for identity, versioning, entitlement filtering and
the installer's transaction boundary. Nothing here changes those rules.

## 1. What changed in one paragraph

A Platform Owner opens **Niche Blueprints → Photo Booth → Enter Blueprint
Workspace** and configures the niche on normal-looking product surfaces (CRM,
Forms, Calendar, Packages, Documents, Automations, Website, SEO, Citations),
saves a draft, and publishes a version. A new Business in that niche receives
the configuration automatically through the one idempotent installer. Existing
Businesses keep what they were given; if a later version differs they are told
"update available" and nothing is replaced.

## 2. Architecture — "Blueprint Scope"

* **No fake Business, Workspace or Location.** The Workspace is a set of
  configuration surfaces over the niche's single *draft version*. Runtime rows
  (tags, forms, workflows…) exist only when a real Business is provisioned.
  (Decision approved 2026-10-04.)
* **One authoring authority.** Every write goes
  `NicheBlueprintWorkspaceController` (thin) → `BlueprintWorkspaceService` →
  `NicheBlueprintPublisher` (Contract 20 §6: admin check, blueprint lock,
  draft-only guard, publish gates). No route touches a `niche_blueprint_*`
  table directly.
* **One adapter per component type** (Contract 20 §10). V2 adds nine adapters,
  each delegating to the module's canonical "create a Business-owned row"
  seam — none contains its own copying logic where a seam exists.
* **Optional adapter capabilities** (separate interfaces, so existing adapters
  and test fakes are unaffected): `BlueprintComponentDefinition` (surface,
  copy/live policy, authoring form, summary), `FingerprintsInstalledComponent`
  (owner-modification detection), `PublishesLiveBlueprintComponent` (live sync
  at publish).
* **Authoring UI is spec-driven**: each adapter declares its form fields and
  parses/prints its own payload, so a surface page is one generic renderer.
  List-shaped fields are "one item per line" (`Name | key`), which keeps the
  descriptors strict and the screens small.

Files: `app/Library/NicheBlueprint/{Adapters,Workspace,Safety}`,
`app/Http/Controllers/Admin/NicheBlueprintWorkspaceController.php`,
`app/Http/Middleware/EnterBlueprintMode.php`,
`resources/views/admin/niche-blueprints/{_overview,workspace/*}.blade.php`.

## 3. Versioning

`Draft → Published → Superseded` (unchanged). New in V2:

* **Enter Blueprint Workspace** (POST) resumes the draft, or creates one **as a
  copy of the published version** (`createDraftFromPublished`) — same
  `component_key`s, so a niche evolves instead of being re-authored.
* **Save Draft** saves notes; every component form already persists to the draft.
* **Publish** supersedes the incumbent and promotes the draft in one transaction
  (live components sync inside the same transaction).
* New Businesses resolve the **latest published** version. A Business is pinned
  to the version it was provisioned from (`installed_from_version`).

## 4. Saveable matrix

| Surface | Component type | Feature gate | What is saved |
|---|---|---|---|
| CRM | `crm_pipeline` | `crm` | pipeline name, stages + semantic keys |
| CRM | `crm_tag_set` | `crm` | tag names |
| CRM | `crm_custom_field` | `crm` | field *definition*: label, type, choices |
| Automations | `automation_workflow` | `automations` | trigger, ordered steps (add tag, wait, SMS, email, team note) |
| Forms | `form` | `forms` | name, intro, fields + custom-field mappings, style, create-opportunity flag |
| Website | `website_config` | `website_generation` | preferred template key, page strategy + sections, navigation, content prompts |
| SEO | `seo_strategy` | `seo_module` | keyword-intent patterns, FAQ topics, schema types/notes, internal-link defaults |
| Citations | `citation_recommendations` | `seo_module` | recommended directories, importance, guidance |
| Packages | `package_template` | `packages_products` | package / add-on template: name, description, *optional* suggested price |
| Calendar | `booking_type` | `calendar` | name, duration, notice, buffers, slot interval, window, instructions |
| Documents | `document_template` | `payments_contracts` | reference to a platform proposal/contract template + payment-term defaults |
| Growth | — | — | **Deferred**, see §11 |

## 5. Forbidden-data matrix

Never stored in a Blueprint, enforced two ways (`BlueprintDataBoundary`):
**structurally** (only registered adapter shapes exist — there is no adapter for
any of these) and **defensively** (publish and every Workspace save scan every
payload; refusal, not silent stripping).

| Forbidden | Refused as |
|---|---|
| Contacts, Opportunities, Conversations, Appointments, Form submissions | key names (`contacts`, `opportunities`, `submissions`…) |
| Invoices, payments, signed contracts, wallet state | key names |
| OAuth tokens, provider credentials, API keys, secrets | any key containing token/secret/password/credential/api_key… |
| Google Ads / Meta Ads data, rank observations, reviews | key names |
| Users, sessions, business/workspace/customer ids | key names |
| Real emails, phone numbers, provider tokens (`sk_live_`, `ya29.`…) | value patterns (merge tokens `{{contact.first_name}}` and UUIDs pass) |

Provisioning is also tested to leave every operational table empty.

## 6. Copy/version vs live matrix

| Component | Policy | What a later niche change does to an existing Business |
|---|---|---|
| Pipelines, tag sets, custom fields | **Copy** | "update available"; owner copy untouched |
| Automations, Forms | **Copy** (installed as drafts) | "update available" |
| Booking types, Package/add-on templates | **Copy** | "update available" |
| Website defaults | **Copy** | pinned version is what the Website seam returns; "update available" |
| SEO strategy | **Live** | Business reads the latest published strategy via `BlueprintConfigReader::seoStrategy()` |
| Citation recommendations | **Live** | publish syncs `seo_niche_citation_recommendations` (the table `GrowthCitationFactReader` already reads live); dropped directories are withdrawn |
| Proposal/contract templates | **Live** (reference) | `RecommendedPlatformTemplates::forBusiness` (existing) reads the published version |

`BlueprintUpdateDetector::forBusiness()` reports per component:
`current | update_available | live | removed_upstream | unknown`, plus
`owner_modified`, plus `new_components` (added in a later version, never
auto-installed). **It only reads.** Applying an update is a deliberate act that
this lane does not build (see §11).

## 7. Provisioning

One path, unchanged from Contract 20 §7/§9: `BusinessCreated` and first
`WorkspacePlanAssigned` → `InstallNicheBlueprintForBusiness` →
`NicheBlueprintInstaller::installForBusiness`. Per component: Business row
lock → installation record check (`business_id, blueprint_id, component_key`
unique) → entitlement decision → adapter install → provenance written, all in
one transaction. Repeated runs install nothing twice (tested). Components run
in `position` order and the Workspace assigns positions from the surface rank
(CRM → Forms → Calendar → Packages → Documents → Automations → Website → SEO →
Citations) so dependencies (tags and custom fields before forms/automations)
are satisfied. Cross-references are by **name resolved inside the Business**,
never by id.

Install behaviour worth knowing: automations and forms land as **drafts** (the
owner reviews, then publishes/activates — publishing is what starts messaging);
booking types land **inactive**; a booking type needs a Location, and a
Business with none yet gets that one component `failed` (retryable by
`blueprint:install-missing`) rather than an invented Location.

## 8. Stable component identity and provenance

* Identity = `(blueprint_id, component_key)`. The Workspace generates the key
  once (`<type>_<random>`), never from a name, and never changes it; editing a
  label does not make a "different" component. Draft-from-published copies keys.
* Migration `2026_11_02_100001`: two nullable hashes on
  `business_blueprint_component_installations`:
  `source_checksum` (sha256 of the installed descriptor) and
  `installed_fingerprint` (adapter-defined fingerprint of the Business-owned
  copy). Justification: "update available" and "owner modified it" are not
  derivable without them. Both are written only by the installer.
* A Business keeps its niche identity (industry / vertical) and, via the
  installation records, the blueprint, the version it was provisioned from and
  per-component provenance (`installed_record_type/id`).

## 9. Blueprint Safety Mode

Entering any Workspace route (`EnterBlueprintMode` middleware) sets
`BlueprintMode` for the request and always releases it. While active,
`BlueprintSafetyGuard` refuses (throws `BlueprintModeRefusedException`):

* generically — every Laravel mail send, every notification, every `Http::`
  request (`AppServiceProvider::boot`);
* at the domain choke points — SMS dispatch (`ManagedMessageDispatcher::dispatch`),
  payments (`PaymentManager::start`), Stripe Connect / provider onboarding
  (`StripeConnectManager::connect/resumeOnboarding`), Google Ads mutations,
  website publish (`WebsitePublisher::publish`), booking (`AppointmentBookingService::book`).

Outside Blueprint mode each check is a single boolean test. Honest scope: the
Workspace has no Business, so these guards are defence in depth — they make
"the Workspace can never reach a real person" independent of what future
surfaces get added. A new external seam must add its own
`BlueprintSafetyGuard::check()`.

## 10. Photo Booth blueprint

`php artisan blueprint:seed-photo-booth` (v1 pipeline) →
`documents:seed-photo-booth-templates` (platform templates + document
components) → `blueprint:seed-photo-booth-v2` (this configuration; idempotent;
`--draft-only` available). Content lives in
`app/Library/NicheBlueprint/PhotoBoothBlueprintV2.php`:

* CRM — the v1 sales pipeline; 8 tags (Wedding, Birthday, Corporate, School
  Event, Hot Lead, Deposit Paid, Past Client, Review Requested); custom fields
  Event date, Event type, Venue name, Guest count.
* Forms — "Check availability" (name, email, phone, event date/type, venue,
  guests, message; mapped to the custom fields; violet style; creates an
  opportunity).
* Automations (drafts) — new-inquiry follow-up (tag, team note, SMS, email),
  planning-call-booked confirmation, post-event review request.
* Website — `photo_booth_editorial` template; Home/Packages/Gallery/Check
  availability page strategy with section lists; navigation; content prompts.
* SEO — keyword-intent patterns, FAQ topics, LocalBusiness/FAQPage/Service
  schema strategy, internal-link defaults.
* Citations — 7 recommendations (WeddingWire/The Knot, GigSalad, Eventective,
  Bark, The Bash, Facebook, Yelp) with guidance.
* Calendar — "Planning call" booking type (20 min, 4 h notice, buffers, 30 min slots).
* Documents — proposal + event-agreement template references; proposal
  payment-term defaults (25 % deposit, balance 14 days before).
* Packages — Classic and Premium package templates, Custom backdrop and Extra
  hour add-ons. **No prices.**

## 11. Deferred / not built (deliberate)

* **Growth thresholds.** `config/growth.php` and the Growth contract state V1
  has no per-Business override and one global `GrowthThresholds`. A niche
  override would change Growth's signature; left for the Growth owner. The
  Workspace has no Growth surface.
* **Applying an update.** Detection, ownership flags and "new components
  available" exist and are shown to the Platform Owner (per Business, on the
  niche page). A Business-facing "apply this update" action is not built:
  it must be an explicit per-component, owner-modification-aware choice and is
  a separate slice.
* **No Business-facing "Updates available" screen** in the customer shell
  (Contract 20 §12.E remains unbuilt on this base); the data is available via
  `BlueprintUpdateDetector`.
* **Meta Ads** has no module on this base; the generic HTTP guard covers it.
* The legacy raw component editor on the niche page is untouched ("Advanced").

## 12. Website seam (concurrent Website final work)

This lane does **not** edit the renderer, `websites`, `website_pages`,
`WebsiteStarter*`, or the questionnaire. It publishes one read seam:

```php
app(\App\Library\NicheBlueprint\Workspace\BlueprintConfigReader::class)->websiteConfig($business);
// ['template_key' => 'photo_booth_editorial',
//  'pages' => [['page' => 'home', 'title' => 'Home', 'sections' => ['hero', ...]], ...],
//  'navigation' => [...], 'content_prompts' => [...]]   // null when the Business has none
```

Validated at publish: `template_key` must be an active `website_templates.key`.
If the final Website work renames template keys or changes the page/section
vocabulary, the adapter needed is a mapping inside `WebsiteConfigComponentAdapter`
(validation + a small translation in the reader) — no Website code changes.

### 12.1 How the Website consumes it (integrated)

`App\Library\Website\WebsiteBlueprintDefaults` is the Website module's one consumer. It only
translates and suggests; it never writes to a Website, so an existing or customised Website is never
overwritten by a Blueprint, and a Business with no Blueprint behaves exactly as before.

* **Template** — the wizard's "Choose a style" step pre-selects the Blueprint's preferred template
  when the Business has no style yet and the Website really offers it (active, for the niche). A
  Website shell that already has a template keeps its own; the owner still chooses.
* **Page strategy / sections / content** — guided generation receives `niche_defaults` in its prompt
  (the Blueprint's content prompts, plus suggested sections per planned page). Sections are translated
  to the Website's vocabulary (`packages` and `add_ons` -> `services`) and intersected with each page's
  `allowed_section_types`; a page that is not in the Website's own plan is never suggested, and nothing
  is invented. They are framed to the model as suggestions that never override confirmed facts. The
  prompt is byte-for-byte unchanged for a Business without Blueprint defaults.

If the Website renames template keys or page / section types, adapt `WebsiteBlueprintDefaults`
(`SECTION_MAP` and the template check) — not the renderer and not the Blueprint data.

## 13. Tests

`tests/Feature/NicheBlueprint/V2/*`: provisioning, versioning/updates/owner
modification/live propagation/config seams, data boundary (incl. provisioning
copies no operational rows), safety mode, Workspace HTTP flow, adapters
(incl. 18 malformed-descriptor cases), niche isolation / two niches.
