# Implementation Contract 15 — Calendar / Booking Types / Availability

**Status:** Planning contract only. Does not authorize implementation. This
is a **first-pass recon + contract** document, produced per its own task
instruction, before any Calendar code is written. Contracts 1–14 (the
Workspace/Agency tenancy migration) are complete on `main` as of this
contract's writing (`3dbb1e11`); this slice does **not** reopen that
migration and depends on it only for the Location ACL and single-Business-
per-Workspace invariants it already established. Six independently
mergeable sub-slices (§12/§18, A–F) implement this contract in dependency
order; **no sub-slice below may start without its own separate, explicit
human authorization**, matching this repository's route-3 governance
(`CLAUDE.md`) and the Roadmap's own top-of-document authority note.

## 1. Objective

Design and, across six dependency-ordered sub-slices, build the V1
Calendar module: Booking Types, staff Availability, the booking engine
(Appointments) with cross-Location double-booking prevention, an
authenticated Business day/week calendar UI, a public self-booking flow,
and per-User Google/Outlook calendar integration — entirely net-new,
Location-bound from the first migration, reusing the existing Location
ACL/ownership model rather than inventing a parallel one.

## 2. Governing authority

- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §12 (Calendar) — the
  product spec, quoted in full in §3 below.
- `docs/product/V1-IMPLEMENTATION-ROADMAP.md`, "Slices 15–18" entry — Slice
  15 is explicitly Net-new, Location-bound, independent of Slices 1–14
  except for reusing Location/Business scoping, XL complexity, Medium risk.
- `docs/product/V1-AUTHORITY-TRACEABILITY-MATRIX.md` row 9 — "NOT YET
  IMPLEMENTED... Net-new module; build Location-bound from the start, not
  retrofitted."
- `docs/product/V1-ACCEPTANCE-MATRIX.md` — "Take bookings" (Business Owner
  actor) and "Book an appointment" (End Customer/Lead actor) rows.
- `docs/rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md` §4 (Location staff ACL)
  and §5 (Operational Location ownership) — Appointment is explicitly named
  in §5's list of record types that must belong to exactly one Location.
- Contract 02 (Location ACL foundation) and Contract 08B (Location ACL
  consumer wiring) — both already merged; 08B explicitly declined to wire
  Calendar ("module does not exist yet... **Not in scope for this
  slice** — nothing to wire"), so this contract's own §6 must do that
  wiring itself, for its own new controllers, from scratch.

## 3. Current repository reality — recon findings (as of this contract's writing, on `main` @ `3dbb1e11`)

### 3.1 Blueprint §12, verbatim

> Covers **Calendar** (the day/week view), **Booking Types** (what can be
> booked and for how long), and **Availability** (who is bookable, when).
> Every booking type and calendar entry belongs to one Location; staff
> availability is set per staff member and constrained to the Location(s)
> they're granted (§26, Addendum §4).
>
> Each staff member connects their own Google or Outlook calendar once,
> globally to their User identity — not once per Workspace — and that
> connection's busy/free data is consulted for every Location they're
> scheduled into, so cross-Location conflicts are prevented by construction
> rather than checked after the fact. Round-robin distributes bookings
> across available staff at a Location. An appointment's lifecycle is
> Scheduled → Reschedule/Cancel are explicit actions → Completed or
> No-show, each firing the corresponding automation event (§13). A public
> scheduler page is reachable from the website (§14) and from links sent in
> Conversations (§11).

Additional normative Blueprint mentions found by an exhaustive grep of
`docs/product/*.md` for calendar/booking/appointment/availability/Google/
Outlook/Zoom/double-book/buffer/capacity/staff-scheduling vocabulary
(none contradict §12; each is folded into the relevant section below):

- §5 (Business-Wide vs Location-Bound matrix): **Appointment is
  Location-bound.**
- §9 (Opportunities): *"**Booked** is not a pipeline stage — it is a
  separate state/badge an Opportunity carries once a qualifying Appointment
  (§12) exists, independent of which pipeline stage the Opportunity is
  in."* — this is the authoritative definition of the CRM-Opportunity/
  Calendar relationship (§5.7 and §9 below).
- §11 (Conversations): quick action "book appointment"; a Contact with a
  booked Appointment carries a broadcast-safeguard badge.
- §21 (Plan Model): Calendar is in the baseline (Core+) tier feature set.
- §24 (+Create global action): "new Appointment" is a global quick-create.
- Zero mentions anywhere in `docs/product/` of buffer time, booking
  capacity/group bookings, or Zoom. **These are therefore not authorized
  by any governing document and are explicit non-goals (§15).**

### 3.2 Confirmed absent on `main` — genuinely net-new

Exhaustive grep across `app/`, `database/migrations`, `routes/`,
`resources/`, `tests/` found **zero** `BookingType`, `Appointment`, or
`Availability`/`StaffAvailability` model, migration, controller, route, or
view; **zero** Google Calendar API, Outlook/Microsoft Graph, or Zoom
integration of any kind; **zero** `booked_at`/`scheduled_at`-style columns
scoped to an actual calendar entity (the one `booked_at` that exists,
`agency_prospects.booked_at`, is unrelated — see §3.4). The codebase's own
prior audits independently confirm this: `V1-AUTHORITY-TRACEABILITY-
MATRIX.md` row 9 ("No BookingType/Appointment/availability model found"),
`V1-ACCEPTANCE-MATRIX.md` ("Not yet implemented (row 9)"), and a
controller docblock at `app/Http/Controllers/Customer/Workspace/
AgencyProspectingController.php:263` ("no calendar/booking model of any
kind exists anywhere in this codebase"). Automations V2's own negative-
guardrail tests (`tests/Feature/Automations/Workflow/Builder/
NoUnsupportedVocabularyTest.php:78,92,160`) assert the workflow builder
**forbids** `booking`/`appointment` trigger vocabulary today — confirming
this is deliberately unbuilt, not accidentally missing.

### 3.3 Reusable existing infrastructure

| Infrastructure | File(s) | Reuse |
|---|---|---|
| `PlatformFeature::Calendar` enum case + `Planned`-availability registry entry + seeded `workspace_plan_features` catalog row | `app/Enums/Entitlement/PlatformFeature.php:16`, `app/Library/Entitlement/PlatformFeatureRegistry.php:63`, `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php:92` | The feature key and plan-catalog wiring already exist end-to-end. This slice flips `Planned`→`Available` (mirroring the exact mechanism already used for `WebsiteGeneration`/`GoogleBusinessProfileModule`/`AiCooBasic`) once real functionality exists — not before. |
| `LocationAccessGuard::userCanAccessLocation()`/`assertUserCanAccessLocation()` | `app/Library/Workspace/LocationAccessGuard.php` | The **sole** authorization mechanism this contract uses for every Location-scoped read/write — never reimplemented. Fails closed; re-derives Location→Business→Workspace fresh every call; precedence: cross-Workspace Agency View-As → direct Business owner → Workspace owner → active membership (`business_access_scope` then `location_access_scope`, `All`/`Selected`). Notably **role-blind** — `WorkspaceMembershipRole::Admin` and `::Staff` are treated identically; only scope matters (see §6). |
| `WorkspaceMembershipLocationRepository` + `workspace_membership_locations` pivot | `app/Repositories/Eloquent/EloquentWorkspaceMembershipLocationRepository.php`, migration `2026_09_20_100004_...` | Exact schema shape (`restrictOnDelete` FKs, composite unique `[membership_id, business_location_id]`, two-step `guardAssignable()` check) to mirror for any new Calendar-side staff↔Location grant table, if one proves necessary beyond what `LocationAccessGuard` already answers. |
| `business_locations` / `contacts.location_id` / `chat_boxes.location_id` FK pattern | migrations for those tables | `unsignedBigInteger('location_id')->nullable() [or NOT NULL for new tables]`, indexed, `restrictOnDelete` (not cascade — "Location attribution is audit-relevant"). This is the exact pattern `booking_types`/`appointments`/`staff_availability_rules` follow, made NOT NULL since every new row here has a Location from creation (no legacy backfill state). |
| `business_google_connections` OAuth pattern | migration `2026_09_09_120001_create_business_google_connections_table.php` | Encrypted refresh token (`encrypted` cast), single-use `oauth_state_nonce`/`oauth_state_expires_at`, `granted_scopes` json, `connected_at`/`disconnected_at`/`revoked_at`/`last_refreshed_at`, `failure_classification`, optimistic-lock `lock_version` — the template `external_calendar_connections` (§5.5) follows. **Pattern only, not the table itself**: that table is per-Business; Blueprint §12 requires per-User, a materially different shape. |
| `AnalyticsDateRange`'s local-day-math discipline | `app/Library/Analytics/AnalyticsDateRange.php` | The timezone-correctness contract this slice's availability-window math must follow: never a fixed offset, always derive from the Business's own IANA `timezone` string per local calendar date, correct across DST boundaries. |
| `business.timezone` (non-nullable string, e.g. `America/New_York`) | `businesses` table, `app/Models/Business.php` | The single source of timezone truth for every Location under that Business (no per-Location override exists or is needed — see §5.4). Read defensively as `$business->timezone ?: config('app.timezone', 'UTC')` everywhere else in the app; this slice follows the same idiom. |
| `OpportunityManager::beginRun()`'s per-key `SELECT ... FOR UPDATE` serialization | `app/Library/Opportunity/OpportunityManager.php:164` | The exact **locking technique** (not the same locked row) the booking engine's double-booking invariant mirrors — see §7. |
| `AgencyProspectingController::lockWorkspaceProspect()` + STOP-dominance-under-lock pattern | same controller, `markProspectBooked()`/`stopProspect()` | Precedent for "re-read the authoritative row under a lock inside the same transaction as the state check and the write," reused for reschedule/cancel races. |
| `public/vendors/js/calendar/fullcalendar.min.js` | already vendored | Pre-selected front-end calendar widget; sub-slice D should confirm it is still current/appropriate before building the day/week view on it, not assume. |
| `CustomerMenuBuilder::businessFrame()` / `ENTITLEMENT_GATED_FEATURES` | `app/Library/Navigation/CustomerMenuBuilder.php:96-106,191-199,544-617` | Exact pattern for adding the new "Calendar" nav item, gated by `entitled('calendar', ...)`; `'calendar'` **must** be added to `ENTITLEMENT_GATED_FEATURES` or the item is silently hidden forever even once entitled. |

### 3.4 Adjacent domains — confirmed NOT the same "Booked," not touched by this slice

Recon found **three unrelated "Opportunity"-shaped things** in this
codebase; "Booked" exists in only one, and it is not the one Blueprint §9
means:

1. **`App\Models\Opportunity`** (AI COO/Business Advisor recommendation
   engine) — no stage, no booked/booking column, no date/time semantics at
   all. Not applicable.
2. **`App\Models\AgencyProspect`** (Agency Prospecting) — has a real
   `booked_at` column and `AgencyProspectStatus::Booked`/
   `AgencyProspectStage::Booked` values, but this is a **manual,
   human-only, no-event-dispatched** suppression-status marker on a
   Workspace-level prospecting funnel record (`markProspectBooked()`,
   `AgencyProspectingController.php:281-306`), with `location` as a free
   *string* column, not a `BusinessLocation` FK. Its own docblock (lines
   260-264) explicitly names Slice 15 as the seam that will eventually
   replace this manual fallback — but **replacing it is out of this
   slice's scope** (§15): Blueprint's "Booked remains compatible with the
   existing Opportunity semantics" instruction names *Opportunity*
   (capitalized, §9's CRM concept), not AgencyProspect.
3. **`App\Models\CrmOpportunity`** (CRM sales-deal pipeline) — this is the
   real target of Blueprint §9's "Booked is... a separate state/badge."
   Current schema: `status` (`Open|Won|Lost` only, no `Booked`),
   `CrmPipelineStage` (free-text per-Business stages, no fixed `Booked`
   semantic key), `CrmOpportunityHistoryEvent`
   (`Created|StageChanged|Won|Lost|Reopened|ContactStatusChanged`, no
   `Booked`). **The Booked badge itself does not exist in the schema yet**
   — Blueprint §9 describes target state, not current-main state. See §5.7
   for how this contract resolves the dependency direction without
   building CRM's own badge computation as part of Calendar.

### 3.5 Second recon pass — the exact seams this revision's corrections rest on

This contract's architecture/concurrency correction pass re-ran targeted
read-only recon against `main` @ `3dbb1e11` to resolve five questions the
first pass had left open or answered by assumption. All five are resolved
from source below, and every correction in this revision cites them rather
than restating an assumption:

1. **`business_locations.hours` is Knowledge-Profile content, not a
   scheduling authority — resolved, not deferred.** Defined only by
   `database/migrations/2026_09_09_120002_add_hours_and_provenance_to_business_locations_table.php`
   (`hours` json nullable, `hours_source` string(24) nullable,
   `hours_verification_status` string(24) NOT NULL default `unverified`,
   `hours_verified_by_user_id`, `hours_verified_at`) — cite the full
   filename, because a second, unrelated migration shares the
   `2026_09_09_120002` prefix. Exactly one writer exists,
   `BusinessKnowledgeProfileManager::updateLocationHours()`
   (`app/Library/Business/BusinessKnowledgeProfileManager.php:193`),
   reachable only from the Knowledge-Profile controller
   (`app/Http/Controllers/Customer/Business/BusinessKnowledgeProfileController.php:147`,
   route `routes/customer.php:1196`), which always passes source
   `website_setup`; `updateFields()` explicitly refuses the key
   (`:410-414`). Its readers are profile-freshness computation and two
   Blade views. **No scheduling, open/closed, booking or availability
   consumer exists anywhere in `app/`**, `business_locations` has **no
   timezone column**, and no document under `docs/` states that these
   hours are an authority for scheduling. See §5.3, §12.B, §15, §18.B.
2. **Contact identity is Location-local.** Addendum §5
   (`docs/rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md:108-111`): "Contacts
   belong to one Location; the same real person **MAY** have separate
   Contact records in different Locations." The Acceptance Matrix says the
   same twice independently (`docs/product/V1-ACCEPTANCE-MATRIX.md:25`,
   "Contacts are captured once per Location"; `:82`, "A form submission on
   a Location's page creates that Location's Contact"). See §5.8, §12.E,
   §18.E.
3. **Throttling already has an established mechanism.** `'throttle' =>
   ThrottleRequests::class` (`app/Http/Kernel.php:109`); the one throttled
   public unauthenticated route in the repository is
   `routes/public.php:40-42` (`throttle:600,1`), whose sizing rationale is
   written into the route comment at `:31-39`; the only named limiter is
   `RateLimiter::for('api')`
   (`app/Providers/RouteServiceProvider.php:113-118`), applied solely to
   `api/http/*` and `api/v3/*`; every other throttle in `routes/` is an
   inline per-route literal or a class constant
   (`WorkflowLimits::MAX_AUTOSAVES_PER_MINUTE`, `routes/customer.php:926`).
   No config key drives any route throttle. See §12.E, §15, §18.E.
4. **Webhook authenticity is verified per controller, fail-closed, with no
   reusable middleware or trait to inherit.** Telnyx verifies an Ed25519
   signature within a bounded timestamp window before parsing anything;
   Stripe uses `Webhook::constructEvent`; Agency Prospecting requires an
   application-key-backed HMAC token in the URL itself; the GBP OAuth
   callback requires a single-use signed state nonce. **No
   Microsoft/Outlook/Graph code exists on `main` at all.** See §11, §12.F,
   §18.F.
5. **Domain events are not this codebase's audit mechanism.** RFC-002 §41
   (`docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md:1155-1157`): the transitions
   table "is the durable business audit log — domain events are a
   notification mechanism, not a substitute for it," written "in the
   *same transaction* as the state change." RFC-003 §19 (`:688`) gives the
   reason: "events can be missed by a listener." Mechanically confirmed on
   `main`: of 88 event classes under `app/Events/`, 68 have no listener at
   all; nothing persists any event to any table; there is no activity-log
   package, no `Auditable` trait, no model observer and no wildcard
   listener; and the two queued listeners that exist run with `$tries = 1`.
   See §5.4, §10, §12.C, §15.

### 3.6 Third recon pass — the executability seams

A final targeted pass resolved six mechanical questions that the corrected
architecture raised but did not yet answer. Each is load-bearing for a
correction below:

1. **`contacts` still has no unique index of any kind, and one cannot be
   added.** The complete constraint inventory is a primary key plus five
   indexes and four foreign keys, across the four migrations that touch the
   table; there is no unique index on `uid`, on `phone`, or on any
   composite. More importantly, a unique index **cannot retroactively be
   added** on `(location_id, phone)`: group cloning
   (`ContactsController.php:583-589`, `ReplicateContacts.php:64-70`,
   `batchContactCopy()` at `EloquentContactsRepository.php:518-535`), CSV
   import (`ContactGroups.php:857`), paste import
   (`ContactsController.php:1174-1177`) and inbound keyword opt-in across
   several groups (`DLRController.php:1219-1236`) all produce duplicate
   `(location_id, phone)` rows as their **ordinary, intended behaviour** on
   any single-active-Location Business, and the Location backfill converts
   historical cross-group duplicates into colliding non-NULL pairs. The
   codebase asserts this is legitimate in five tests and five code comments
   (e.g. `MessageReceivedTriggerSource.php:33-36`: "Phone is unique per
   group, not per Business, so one number can be several contacts";
   `ChatBoxBusinessBackfillV1Test.php:96-107`,
   `test_several_contacts_inside_one_business_are_duplicates_not_ambiguity`).
   See §5.8.
2. **There is no canonical phone normalizer for `contacts`.** True E.164
   normalizers exist (`E164Normalizer`, `AgencyProspectPhoneNormalizer`) but
   **none is on the Contacts write path**. What `contacts.phone` actually
   stores is `str_replace(['+', '-', '(', ')', ' '], '', $raw)`, plus
   `trim()` at the one canonical site (`EloquentContactsRepository.php:695`,
   `:709`); the column is `string` (VARCHAR) and the model casts it to
   `integer` on read only. See §5.8.
3. **The canonical ensure-then-lock precedent is
   `AiUsageLedgerManager::lockOrCreatePeriod()`**
   (`app/Library/Ai/AiUsageLedgerManager.php:441-474`) over
   `ai_usage_periods`, whose migration calls it "the lock-and-counter row"
   and whose docblock explains the gap-lock deadlock that makes the
   unlocked probe mandatory. All three `insertOrIgnore` call sites in
   `app/` are backed by a real unique key — that is what makes INSERT
   IGNORE idempotent, and nothing else does. See §5.8, §7.2.
4. **Genuinely random public identifiers have an exact precedent.**
   `HasUid`'s default generator is `uniqid()`
   (`app/Library/Traits/HasUid.php:27-30`); 20 models override
   `generateUid()` with `(string) Str::uuid()`. The public Website
   identifier is a **separate column** — `$table->uuid('public_id')->unique();`
   (`2026_09_07_130001_create_websites_table.php:22`) — filled by its own
   `booted()` creating hook (`app/Models/Website.php:60-67`), deliberately
   independent of `HasUid::boot()`. See §5.1, §6.
5. **A `Planned` feature already fails closed at the decision layer.**
   `EntitlementManager::snapshotBusinessFeatureDecisions()` returns
   `new EntitlementDecision(false, 'platform_feature_unavailable')` before
   any database read (`EntitlementManager.php:201-205`), and `decide()` is
   a thin wrapper over it. Route gating is done **in-controller** — there is
   no feature middleware anywhere — canonically via
   `ResolvesBusinessTenancy::resolveEntitledBusinessTenancy()`
   (`app/Http/Controllers/Customer/Business/Concerns/ResolvesBusinessTenancy.php:82-99`),
   which `abort(404)`s on refusal. Nav hiding is confirmed **purely
   cosmetic**. See §6, §12.B/D/E.
6. **OAuth: no access token is persisted, and Users are hard-deletable.**
   `business_google_connections` has `refresh_token_encrypted` (text,
   nullable, `encrypted` cast, `$hidden`) and **no access-token column at
   all**, by explicit design; the access token is derived per unit of work
   and discarded (`GoogleBusinessProfileConnectionManager::accessTokenFor()`,
   `:266-291`). Separately, `users` has **no SoftDeletes** and ten hard-delete
   paths exist in `app/`; the repository's canonical stated rule is that an
   infrastructure table "must never block a legitimate user-deletion feature
   elsewhere in the system"
   (`2026_07_31_120001_create_workspace_transitions_table.php:15-19`), which
   explicitly exempts Users from RFC-003 §17's no-hard-delete rule. See
   §5.5, §7.2.

## 4. Delta from current state to target

Pure additive build — no retrofit, no migration-off-a-legacy-model (there
is no legacy Ultimate SMS scheduling code proven reusable; recon found only
unrelated bulk-SMS **campaign** scheduling (`ScheduleCampaign`,
`Tool::currentTimezone()`) which this slice explicitly does not reuse,
since its timezone convention (`Auth::user()->timezone`) is the *legacy*
one superseded everywhere else in the app by `business.timezone`). Six
sub-slices land independently on `main` in dependency order (§12/§18):
schema/domain foundation → Booking Types + Availability →
booking engine/concurrency → authenticated Business UI → public
self-booking → external Google/Outlook integration.

## 5. Canonical domain model

Operational and audit-relevant Location-owned records use `restrictOnDelete`
foreign keys to `business_locations` — Location attribution is audit-
relevant, per the `contacts`/`chat_boxes` precedent. Purely technical
lock/cache rows with no independent audit value may use `cascadeOnDelete`
where this contract explicitly says so (`booking_contact_identity_locks`,
§5.8.3, is the one such table — disposable serialization infrastructure, not
an operational or audit record). All new tables use the existing `HasUid` trait
convention for any row an external URL or API response references, and all
store date/times as UTC timestamps with timezone-aware math done at read
time via `business.timezone` (§3.3), never a stored offset.

### 5.1 `booking_types`

```
id
uid                          uuid, unique
public_booking_uuid          uuid, NOT NULL, unique
business_location_id         FK -> business_locations, restrictOnDelete, NOT NULL
name                         string(120)
description                  text, nullable
duration_minutes             unsignedSmallInteger
color                        string(16), nullable (calendar UI)
is_active                    boolean, default true
created_by_user_id           FK -> users, nullOnDelete, nullable
timestamps

index (business_location_id, is_active)
```

**`public_booking_uuid` is the public scheduler's only address, and it is
not `uid`.** §6 requires that no public surface resolve a Business or
Location by a `HasUid` value, because `HasUid::generateUid()` is
`$this->uid = uniqid();` (`app/Library/Traits/HasUid.php:27-30`) — guessable,
not random — and this repository already rejected that shape for public
addressing in writing (`routes/public.php:210-215`). An earlier draft stated
that rule but created no replacement identifier, leaving Sub-slice E with
nothing to route on. This column is that identifier.

It follows the `websites.public_id` precedent exactly, which exists for the
identical reason:

- **Column**: `$table->uuid('public_booking_uuid')->unique();` — the same
  shape as `$table->uuid('public_id')->unique();`
  (`database/migrations/2026_09_07_130001_create_websites_table.php:22`).
  NOT NULL: every Booking Type has one from creation, there is no legacy
  backfill state.
- **Generation**: a genuinely random v4 UUID via `(string) Str::uuid()`,
  assigned in the model's own `booted()` creating hook, exactly as
  `app/Models/Website.php:60-67` does it. That hook is deliberately
  **separate** from `HasUid`'s own `creating()` hook, which only ever
  touches `uid`; `Model::bootIfNotBooted()` runs `boot()` and `booted()` as
  two independent steps, so both fire (Website's own docblock, `:52-59`,
  records this).
- **Routing**: the public scheduler route binds this column and constrains
  it with `->whereUuid(...)`, mirroring
  `->whereUuid('website')` at `routes/public.php:230-237`, so a malformed
  identifier is rejected before any query runs.
- **Resolution direction**: the route resolves the **Booking Type**, and the
  Location is derived from that persisted row's own
  `business_location_id` — never from a Location identifier in the URL, and
  never from anything the request supplies.

**This identifier is discovery-resistant addressing only. It is not
authorization.** Holding a valid `public_booking_uuid` proves nothing except
that the row exists; every one of §6's six public checks still runs, on both
the page render and the booking write. It never substitutes for the account,
entitlement, Location-active, Booking-Type-active or staff-eligibility
checks, and a leaked URL therefore grants nothing that a locked, unentitled
or archived target would otherwise refuse.

Sub-slice A owns the column and its generation; Sub-slice E consumes it
(§12.A, §12.E).

**Every Schema A model that uses `HasUid` must override `generateUid()`.**
`HasUid`'s default is `uniqid()`, so a model that merely `use`s the trait
gets a guessable `uid` despite the `uuid` column type — the exact defect
`app/Models/Website.php:18-20` names about `Business.uid`. Each new model
overrides it as the 20 existing models do:
`public function generateUid() { $this->uid = (string) Str::uuid(); }`.

`booking_type_staff` (pivot — which staff offer this Booking Type, the
round-robin pool):

```
id
booking_type_id              FK -> booking_types, cascadeOnDelete
staff_user_id                FK -> users, cascadeOnDelete
timestamps

unique (booking_type_id, staff_user_id)
index (staff_user_id)
```

`cascadeOnDelete` on `booking_type_id` here (not the Location FK) because
this pivot's only meaning is "this Booking Type currently offers this
staff member" — it has no independent audit value once the Booking Type
itself is gone, unlike the Location FKs elsewhere in this schema.

**`cascadeOnDelete` on `staff_user_id` too, corrected from an earlier
`restrictOnDelete` draft.** This row is explicitly configuration intent
with no independent audit value (stated two paragraphs below) — a deleted
staff User must not remain attached to a Booking Type, and must not block
a legitimate User deletion merely because that configuration intent once
existed. This repository hard-deletes Users (§7.2's own evidence — no
`SoftDeletes` on `users`); a lock/pivot row that carries no history of its
own is never the reason a User becomes undeletable. This is unlike
`appointments.staff_user_id`/`staff_availability_rules.staff_user_id`/
`staff_time_off.staff_user_id`, which remain `restrictOnDelete` because
those rows *are* historically meaningful (§7.2).

**`booking_type_staff` is configuration intent, never authorization.** A
row here means only "an authorized configurer nominated this staff member
for this Booking Type." It is never, at any point, proof that the staff
member may currently be scheduled at that Location — see §6's
eligibility rule, which re-derives that fact from canonical persistence at
both configuration time and booking time.

### 5.1.1 `booking_type_round_robin_state` (durable rotation cursor)

Blueprint §12 requires round-robin distribution across available staff at
a Location. That needs durable, serializable state: an in-memory pointer,
PHP process state, a cache entry, or "count each staff member's historical
appointments and pick the lowest" are all rejected — the first three do not
survive a request or a second worker, and the last silently re-derives a
cursor from an unrelated, mutable history (cancellations, manual bookings
and imported rows would all move it).

```
id
booking_type_id              FK -> booking_types, cascadeOnDelete, NOT NULL
last_assigned_staff_user_id  FK -> users, nullOnDelete, nullable
last_assigned_at             timestamp, nullable
timestamps

unique (booking_type_id)
```

One row per Booking Type, holding exactly one fact: **which staff member
received the last successfully committed round-robin assignment**. The row
is created in the same transaction as its Booking Type (Sub-slice B), and
every reader additionally applies the same idempotent ensure-then-lock
sequence `staff_booking_locks` uses (§7.2), so a missing row can never
become a race or a nondeterministic failure.

`cascadeOnDelete` on `booking_type_id`: like `booking_type_staff`, this row
is meaningless once its Booking Type is gone and carries no independent
audit value. `nullOnDelete` on `last_assigned_staff_user_id`: a deleted
User must not block the Booking Type, and a null cursor is a defined state
(§7.3 step 2 — rotation simply starts at the lowest eligible id).

### 5.2 `staff_availability_rules` (recurring weekly windows)

```
id
business_location_id         FK -> business_locations, restrictOnDelete, NOT NULL
staff_user_id                FK -> users, restrictOnDelete, NOT NULL
day_of_week                  tinyint unsigned (0=Sunday..6=Saturday)
start_time                   time
end_time                     time
timestamps

index (staff_user_id, business_location_id, day_of_week)
```

Multiple rows per `(staff_user_id, business_location_id, day_of_week)` are
allowed (split shifts). `start_time`/`end_time` are **local time-of-day in
that Location's Business's timezone** (§3.3's `business.timezone`,
resolved via `business_location.business`) — never converted to a fixed
UTC offset at write time, so DST transitions never corrupt a recurring
rule (mirrors `AnalyticsDateRange`'s discipline).

### 5.3 `staff_time_off` (availability exceptions)

```
id
staff_user_id                FK -> users, restrictOnDelete, NOT NULL
start_at                     timestamp (UTC)
end_at                       timestamp (UTC)
reason                       string(255), nullable
created_by_user_id           FK -> users, nullOnDelete, nullable
timestamps

index (staff_user_id, start_at, end_at)
```

**Deliberately staff-scoped, not Location-scoped**: Blueprint §12 frames
availability as "who is bookable, when" — a property of the staff member,
consistent with the cross-Location conflict-prevention design (§7). A
staff member on time-off is unavailable at **every** Location they're
otherwise granted, not just one — a deliberate, User-global exception to
this slice's otherwise Location-bound schema, which §14's acceptance
wording states outright rather than papering over.

**Location-wide closures are out of this schema, and the reason is now
resolved rather than deferred.** `business_locations.hours` /
`hours_source` (§3.5.1) is **Business Knowledge Profile content and its
provenance** — a published-hours fact written by exactly one method,
`BusinessKnowledgeProfileManager::updateLocationHours()`
(`app/Library/Business/BusinessKnowledgeProfileManager.php:193`), always
with source `website_setup`, and read only by profile-freshness
computation and two Blade views. **Nothing in `app/` consumes it for
scheduling, open/closed, booking or availability**, and no document under
`docs/` makes it a scheduling authority. It is therefore **not** an outer
bound on staff windows in this slice, and Sub-slice B has nothing left to
verify about it.

**`staff_availability_rules` + `staff_time_off` are the booking
authority.** A Location with no staff availability is unbookable because
no staff member is available there, not because a separate closure concept
said so. Should the product later want published hours to bound bookings,
that is a deliberate future decision requiring its own authority — and one
that would additionally have to resolve those hours against
`business.timezone` (§3.3), since `business_locations` carries no timezone
column of its own.

### 5.4 `appointments`

```
id
uid                          uuid, unique
business_location_id         FK -> business_locations, restrictOnDelete, NOT NULL
booking_type_id               FK -> booking_types, restrictOnDelete, NOT NULL
staff_user_id                 FK -> users, restrictOnDelete, NOT NULL
contact_id                    FK -> contacts, restrictOnDelete, NOT NULL
crm_opportunity_id            FK -> crm_opportunities, nullOnDelete, nullable
created_by_user_id            FK -> users, nullOnDelete, nullable (null = created via public self-booking)
status                        string(16): scheduled | cancelled | completed | no_show
start_at                      timestamp (UTC), NOT NULL
end_at                        timestamp (UTC), NOT NULL
reschedule_count               unsignedInteger, default 0
resolved_at                   timestamp, nullable (set once status leaves 'scheduled')
resolved_by_user_id           FK -> users, nullOnDelete, nullable
cancellation_reason           string(255), nullable
timestamps

index (staff_user_id, status, start_at, end_at)   -- the conflict-check query
index (business_location_id, start_at, end_at)    -- the calendar day/week view query
index (contact_id)
index (crm_opportunity_id)
```

No `timezone` column (derived via `business_location_id → business_id →
business.timezone`, §3.3). No provider-specific columns of any kind — see
§5.5's boundary rule.

**No reschedule-history table, and no claim that anything replaces one.**
An earlier draft of this section called the `AppointmentRescheduled` event
"this slice's audit mechanism" and cited Contract 04 as precedent for
event-based audit. Both statements were wrong and are withdrawn: Contract
04 §10 says the opposite — the `ViewAsSession` **row itself** "already
serves as the durable audit record" — and RFC-002 §41 is explicit that
"domain events are a notification mechanism, not a substitute for" a
durable audit log (§3.5.5, §10). What this row does and does not preserve,
stated plainly:

- **Recoverable from the row**: the current booking (`staff_user_id`,
  `start_at`, `end_at`), the outcome (`status`, `resolved_at`,
  `resolved_by_user_id`, `cancellation_reason`), who created it
  (`created_by_user_id`, null = public self-booking), and **how many
  times** it was rescheduled (`reschedule_count`).
- **Not recoverable from anything this slice persists**: the *previous*
  `start_at`/`end_at`/`staff_user_id` of each reschedule. A reschedule
  overwrites them, and the event carrying the old values is transient.

That gap is named here rather than hidden, and it is **decided, not
deferred**. No governing Slice 15 authority — Blueprint §12, Blueprint §32
(which enumerates ownership transfer, Agency relationship termination and
payer changes), RFC-002 §41, RFC-003 §19, the Acceptance Matrix, Addendum
§5 — requires durable Appointment history. **V1 therefore ships no
`appointment_transitions` table** (§15), and nothing in this slice blocks
on that question:

- the `appointments` row stores current state plus `reschedule_count`;
- the five domain events of §10 are **transient integration/automation
  events only** — their consumers are Automations §13, not an auditor;
- the previous interval and previous staff member of a reschedule are
  **not durably queryable** once the event has been delivered, and this
  contract says so rather than implying otherwise;
- adding durable Appointment history later is separate product scope, and
  the shape it would take is already known if it is ever authorized —
  `app/Repositories/Contracts/WorkspaceTransitionRepository.php` (`create()`
  plus `for*()` readers, deliberately no `update()`), written in the same
  transaction as the state change.

Sub-slice C is not gated on this and must not stop to ask.

### 5.5 `external_calendar_connections` (per-User, per-provider)

```
id
uid                            uuid, unique
user_id                        FK -> users, cascadeOnDelete, NOT NULL
provider                       string(16): google | outlook
state                          string(16), NOT NULL, default 'pending': pending | active | disconnected | revoked
external_account_email          string(191), nullable
refresh_token_encrypted         text, nullable, `encrypted` cast, in $hidden
granted_scopes                  string(512), nullable
sync_cursor                     string(255), nullable (provider's own delta/sync token)
last_synced_at                  timestamp, nullable
last_sync_failure_at            timestamp, nullable
sync_failure_count               unsignedInteger, default 0
failure_classification           string(64), nullable
oauth_state_nonce                string(64), nullable, unique
oauth_state_expires_at           timestamp, nullable
connected_at                    timestamp, nullable
disconnected_at                 timestamp, nullable
revoked_at                       timestamp, nullable
last_refreshed_at                timestamp, nullable
lock_version                    unsignedInteger, default 0

active_user_id                  bigint unsigned, VIRTUAL generated column:
                                `case when state in ('pending', 'active') then user_id end`

unique (active_user_id)
index  (user_id, provider)
```

**Generated-column materialization.** `active_user_id` is **VIRTUAL**, not STORED. On the MySQL version used by this application, a STORED generated column that depends on `user_id` cannot coexist with the required `ON DELETE CASCADE` foreign key on `user_id` (the FK is rejected with errno 1215). A VIRTUAL generated column preserves the actual invariant this column exists for — MySQL-computed conditional value plus a UNIQUE secondary index, so at most one `pending|active` row exists per User while terminal rows yield NULL — and keeps the deliberate User-delete cascade. The implementation is required to test the generated expression and the uniqueness behavior rather than relying on materialization type.

**No access token is ever persisted.** An earlier draft listed both an
`access_token` and a `refresh_token` column and claimed to mirror
`business_google_connections` "field-for-field". It did not: that table has
**no access-token column at all**, deliberately, and its migration says so
verbatim — "There is deliberately NO access-token column: an access token is
derived from the refresh token per unit of work and never persisted (§9.7)"
(`database/migrations/2026_09_09_120001_create_business_google_connections_table.php:17-21`,
repeated on the model at `app/Models/BusinessGoogleConnection.php:20-21`).
This slice follows the real precedent:

- `refresh_token_encrypted` is the only stored credential — `text`,
  nullable, Laravel's built-in `encrypted` cast, and listed in the model's
  `$hidden`, exactly as `BusinessGoogleConnection` does it.
- An access token is obtained **in memory, per provider operation**, from
  the encrypted refresh token, used, and discarded — the shape of
  `GoogleBusinessProfileConnectionManager::accessTokenFor()`
  (`app/Library/GoogleBusinessProfile/GoogleBusinessProfileConnectionManager.php:266-291`),
  whose only database write is the bookkeeping columns
  `last_refreshed_at`/`failure_classification`. It is never written back,
  never logged and never serialized.
- Nullable on purpose: a row in `pending` has no refresh token yet.
- Disconnect and revoke clear `refresh_token_encrypted`, `granted_scopes`,
  `sync_cursor`, `oauth_state_nonce` and `oauth_state_expires_at`, keeping
  every timestamp as durable audit — the same column set
  `GoogleBusinessProfileConnectionManager::disconnect()` clears (`:331-340`).
- `lock_version` is the optimistic-lock guard on every state transition,
  mirroring that manager's `transition()` (`:411-446`): update `WHERE
  lock_version = ?`, set `lock_version + 1`, and raise a concurrency
  exception when the affected-row count is not exactly 1.

**`user_id` is `cascadeOnDelete`, corrected from an earlier `restrictOnDelete`
draft.** This row holds operational OAuth credentials (an encrypted refresh
token) and a technical connection slot, not an independently audit-relevant
record — the same posture `business_google_connections` already takes with
its own owning entity. `restrictOnDelete` here would make a User
undeletable for the sole reason that they once connected a calendar, and
this repository hard-deletes Users (§7.2's own evidence: no `SoftDeletes` on
`users`, ten distinct hard-delete paths in `app/`). Deleting a User
therefore removes their `external_calendar_connections` row; its
`external_calendar_busy_blocks` rows cascade through the connection (§5.6)
so no encrypted credential or synced cache is ever orphaned; and the
technical one-connection slot is released automatically as a consequence of
the row being gone, not as a separate step. **Connection history does not
survive deletion of the User** — every `external_calendar_connections` row,
live or terminal, is retained only for as long as that User exists; this
corrects any earlier statement implying otherwise.

**If a provider mechanically requires different persistent credentials**,
Sub-slice F may add provider-specific storage only after verifying that
requirement against the provider's current documentation, and must report it
(§18.F). Persisting an access token is **not** pre-authorized by this
contract, and a plaintext credential column is never authorized.

**`state` drives the one-connection slot, not the timestamps.** The
generated column keys on `state in ('pending', 'active')`, which is the
exact idiom `business_messaging_identities` already uses for
pending-or-active slot semantics —
`CASE WHEN status IN ('pending','active') THEN business_id ELSE NULL END`
(`database/migrations/2026_09_12_100001_create_business_messaging_identities_table.php:49-60`).
An in-flight connect therefore holds the User's one slot, which is
deliberate: it is what refuses a second simultaneous initiation.

Mirrors `business_google_connections` field-for-field (§3.3), keyed to
`user_id` instead of `business_id` per Blueprint §12's explicit "globally
to their User identity — not once per Workspace."

**Cardinality: exactly ONE active external calendar connection per User,
total — not one per provider.** Blueprint §12 (`V1-MASTER-PRODUCT-
BLUEPRINT.md:290`) is the only authoritative statement on this and reads:
"Each staff member connects their own Google **or** Outlook calendar
once, globally to their User identity — not once per Workspace." A
repository-wide grep of `docs/` for Outlook/Google connection vocabulary
found no other authoritative source and **nothing anywhere stating that
both providers may be connected concurrently** (the only other hits are
this contract's own text). Simultaneous Google **and** Outlook connections
for one User are therefore an unsupported product expansion and are not
contracted: `provider` is `google | outlook`, and a User holds at most one
active connection of either.

Enforced by the same **stored generated column + unique index** pattern
Contract 01 already proved in this repository for conditional uniqueness
(`agency_client_workspace_relationships.active_client_workspace_id`):
`active_user_id` is `user_id` only while the row's own `state` is `pending`
or `active`, and NULL once `state` transitions to `disconnected` or
`revoked` — **`state` is the uniqueness authority; `disconnected_at` and
`revoked_at` are audit metadata that accompany that transition, never the
mechanism that drives it.** The generated column's own definition (above)
keys on `state`, not on either timestamp column being set. MySQL's unique
index ignores NULLs — so at most one live connection per User is a database
guarantee, while every historical, disconnected connection row is retained
unlimited-ly for audit.

**Pending-connection lifecycle — an abandoned OAuth attempt must never
permanently consume the slot.** Because a `pending` row occupies
`active_user_id`, the failure paths have to be specified, not assumed:

1. **Initiation** creates (or re-uses, per 4 below) exactly one row in
   `pending` for that User, writing `oauth_state_nonce` and
   `oauth_state_expires_at`. The nonce is single-use and its TTL is bounded,
   following `GoogleOAuthStateSigner` (`issue()` at `:41-44`, `consume()`'s
   conditional update at `:111-124`, default TTL 600s clamped to
   [60, 3600]).
2. **A second simultaneous initiation for that User is refused** while a
   live (non-expired) `pending` row exists, and while an `active` row
   exists. The unique index on the generated column is the hard backstop;
   the application refuses first, with a clear message, so the user sees a
   refusal rather than a constraint violation.
3. **A successful callback** transitions `pending → active` under
   `lock_version`, writing `refresh_token_encrypted`, `granted_scopes`,
   `connected_at`, `last_refreshed_at`, and clearing the nonce and its
   expiry. A callback that yields no refresh token **fails closed and leaves
   the row `pending`** — the same rule `completeConnect()` applies
   (`:229-238`).
4. **An expired, failed or abandoned attempt is released, not stranded.**
   When a User initiates a connection and their existing row is `pending`
   with `oauth_state_expires_at <= now()`, that row's `state` is first
   atomically transitioned `pending → disconnected` (nonce and expiry
   cleared, no credential to clear, `disconnected_at = now()` written as
   the accompanying audit timestamp) — **it is this state transition, not
   the timestamp write, that NULLs `active_user_id`** and frees the unique
   index — and only then is the new `pending` row inserted. Both steps
   happen in one transaction under `lock_version`, so two concurrent
   initiations cannot both reclaim.
5. **Reconnect and provider switch then proceed normally** (below), and
   **every terminal row is retained as durable audit while the User exists**:
   ordinary disconnect/revoke flows never overwrite or delete that history;
   deleting the owning User deliberately cascades the connection row, per the
   FK posture above, so connection history does not survive User deletion.

No scheduled sweep is specified, and none is needed: the only operation the
slot blocks is that same User's own next initiation, and step 4 releases it
at exactly that moment. A stranded `pending` row therefore inconveniences
nobody — it is not a global lock, and it never blocks another User. §12.F
and §13 require a test proving an abandoned or expired attempt does not
block the User forever, and a second test proving two live/pending
connections still cannot coexist.

**Provider switch / reconnect semantics** (Sub-slice F), all inside one
transaction holding that User's `staff_booking_locks` row (§7.5, so a
booking can never read a half-switched cache):

1. Atomically transition the current live connection's `state` to
   `disconnected` (or `revoked` when the provider reported revocation),
   writing `disconnected_at = now()` (or `revoked_at = now()`) as the
   accompanying audit timestamp. Clear `refresh_token_encrypted`,
   `granted_scopes`, `sync_cursor`, and `oauth_state_nonce`/
   `oauth_state_expires_at` if either is still set, leaving every other
   column (including the now-terminal `disconnected_at`/`revoked_at`) as
   durable audit. There is no `access_token` column anywhere in this
   schema (§5.5 above) — nothing of that name is ever cleared, because
   nothing of that name is ever stored. **It is the `state` transition
   itself that NULLs the generated `active_user_id` column and frees the
   unique index — the timestamps are audit metadata, not the mechanism.**
2. **Delete every `external_calendar_busy_blocks` row for that
   connection** in the same transaction (§5.6) — a disconnected provider's
   cached busy intervals must never keep blocking bookings.
3. Insert the new connection row (same User, new or same `provider`), with
   a fresh `sync_cursor = NULL`, forcing the next sync to be a full read
   rather than a delta against a stale cursor.

Reconnecting the same provider follows the identical sequence; there is no
"update the existing row in place" path, so the audit trail of when a
connection existed is never overwritten.

### 5.6 `external_calendar_busy_blocks` (sync cache — advisory only)

```
id
external_calendar_connection_id  FK -> external_calendar_connections, cascadeOnDelete, NOT NULL
provider_event_id                 string(255)
start_at                          timestamp (UTC)
end_at                             timestamp (UTC)
busy_type                         string(24), nullable (e.g. busy/tentative, if the provider distinguishes)
synced_at                         timestamp
timestamps

unique (external_calendar_connection_id, provider_event_id)
index (external_calendar_connection_id, start_at, end_at)
```

`cascadeOnDelete` here (unlike every Location FK above) — this table is
purely disposable synced-derived data with no independent audit value; it
is deleted along with its connection. The `unique(connection_id,
provider_event_id)` constraint is the idempotency mechanism (§7, §12.F):
every sync — full, delta, or webhook-triggered — is an upsert keyed by the
provider's own event id, so processing the same notification twice is a
no-op, never a duplicate or a double-counted busy interval. **No
provider-specific column ever appears on `appointments`** — this table is
consulted only as one additional read-only source unioned into the
conflict-check query (§7); the canonical Appointment record's authority
never depends on it being present, current, or even ever having existed.

**Upsert alone is insufficient: deletions must be applied.** An external
event that is deleted or cancelled at the provider must stop blocking
local bookings; a busy block that outlives its source event is a permanent
false conflict no one can see or clear from inside this product. Sub-slice
F therefore implements all four of the following, and §12.F/§13 test each
(the local write side of every one of them runs under the staff lock of
§7.5):

1. **Delta tombstones.** A delta/incremental read that reports an event as
   deleted or cancelled — Google Calendar's `status: "cancelled"` entries
   in an incremental `events.list`, Microsoft Graph's `@removed`
   annotation in a delta response — **deletes** the corresponding
   `external_calendar_busy_blocks` row by
   `(connection_id, provider_event_id)`. A tombstone for an id that is
   already absent is a successful no-op, so replaying a deletion any
   number of times is idempotent.
2. **Full-sync reconciliation, only after a complete successful read.**
   A full sync collects every `provider_event_id` the provider returned
   for an explicit time window, and only once the whole paginated read has
   succeeded does it delete this connection's rows inside that window
   whose id was not returned. A partial page-through, an HTTP error, an
   expired token or an aborted job **never** reaches the reconciliation
   step.
3. **A failed or partial sync never wipes the prior good cache.** The
   delete-by-absence in (2) is conditional on the successful completion
   flag from (2); on any failure the job records
   `last_sync_failure_at`/`sync_failure_count`/`failure_classification`
   (§11) and leaves every existing busy block exactly as it was —
   fail-safe-stale, the same choice §11 already makes for reads.
4. **The cursor advances with the data, never ahead of it.**
   `sync_cursor`/`last_synced_at` are written in the **same transaction**
   as the create/update/delete set they describe. A crash between applying
   rows and advancing the cursor therefore re-delivers the same delta,
   which is idempotent by (1) and the upsert key; a crash cannot advance
   the cursor past changes that were never applied.

Provider switch and disconnect purge this cache for the affected
connection in the same transaction that ends the connection (§5.5).

### 5.7 Opportunity linkage — dependency direction

`appointments.crm_opportunity_id` (nullable) is the only coupling point.
Calendar's own obligation, and only obligation, in this slice: fire
`AppointmentScheduled`/`AppointmentRescheduled`/`AppointmentCancelled`/
`AppointmentCompleted`/`AppointmentNoShow` events (§10) carrying
`crm_opportunity_id` when set. **Computing or writing any "Booked" badge
on `CrmOpportunity` itself is explicitly not this slice's job** (§15) —
that schema doesn't exist yet on `main` (§3.4) and adding it is CRM-domain
work that should consume these events as a listener, keeping the
dependency direction correct (Calendar publishes; CRM optionally
subscribes) rather than Calendar reaching into `crm_opportunities` to
compute a badge it doesn't own.

### 5.8 Contact identity for bookings — Location-local, never Business-local

No new table. This subsection exists because an earlier draft left the
dedup rule "to be confirmed at implementation time," and the authoritative
answer is available now — and is **not** what that draft's parenthetical
("phone/email match within the Location's Business") assumed.

**The rule.** A booking's Contact is identified **within the Location being
booked**, never across the Business. Addendum §5
(`docs/rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md:108-111`) is explicit:
"Contacts belong to one Location; the same real person **MAY** have
separate Contact records in different Locations." The Acceptance Matrix
says it twice independently (`docs/product/V1-ACCEPTANCE-MATRIX.md:25`,
`:82`). One person booking at two Locations of the same Business is
therefore **two Contact rows, and that is correct** — a booking flow that
"helpfully" reuses a sibling Location's Contact row violates a `MUST`.

**Four facts about the existing Contacts seam that this rule collides
with, all verified on `main`, none of which Sub-slice E may assume away:**

1. **The canonical creation seam is group-keyed, not Location-keyed.**
   `EloquentContactsRepository::createContactFromRequest(ContactGroups
   $contactGroups, array $input, ContactCreationSource $creationSource)`
   (`app/Repositories/Eloquent/EloquentContactsRepository.php:677`) is the
   only creation path with live callers, and one of them is already a
   public, unauthenticated opt-in page
   (`app/Http/Controllers/Customer/ContactsController.php:1648`,
   `ContactCreationSource::OptInForm`) — the closest precedent this slice
   has. Its dedup is `firstOrNew(['phone' => trim($phone)])` on the
   group's own relation (`:708-710`) plus a `group_id`-scoped
   `Rule::unique` (`:697-703`). Sub-slice E **reuses this seam**, and
   resolving which `ContactGroups` row a booked Contact belongs to is part
   of its own work; it never writes `contacts` directly, and it never
   mistakes group-scoped uniqueness for Location-scoped identity.
2. **`contacts.location_id` exists, but the rule that populates it gives
   up in exactly the case a booking page faces.** The column
   (`database/migrations/2026_09_21_100001_add_location_id_to_contacts_table.php:35`,
   nullable by design) is written by five sites, every one through
   `Contacts::singleActiveLocationIdFor(?int $businessId)`
   (`app/Models/Contacts.php:85-99`), which returns a Location **only when
   the Business has exactly one active Location** and NULL otherwise.
   Sub-slice E writes the **Location actually being booked**, which it
   knows exactly, and must not call that helper: a booking at the second
   Location of a two-Location Business must never land a NULL
   `location_id`.
3. **There is no email dedup, and there cannot be a direct one.**
   `contacts` has no email column (dropped by
   `database/migrations/2024_03_05_162536_update_contacts_table.php:14`);
   email survives only as a `contacts_custom_field` row tagged `EMAIL`.
   Booking identity is therefore **phone within the booked Location**, and
   the draft's "phone/email" phrasing is withdrawn.
4. **`contacts` carries no unique index of any kind**, and one cannot be
   added retroactively (§3.6.1). Uniqueness is validation-only and
   group-scoped, so two simultaneous public bookings from the same phone
   can both pass a `SELECT`-then-`INSERT` check.

**An earlier draft said Sub-slice E could get a hard guarantee from
`insertOrIgnore`. That was wrong and is withdrawn.** `INSERT IGNORE`
suppresses a duplicate-key error; with no unique key to violate it
suppresses nothing and prevents no duplicate. Every one of the three
`insertOrIgnore` call sites in `app/` is backed by a real unique constraint
— `ai_usage_periods` (`2026_09_16_100002:41`), `business_home_visits`
(`2026_09_16_100001:45`), `opportunity_producer_dispatches`
(`2026_09_16_100001:59`) — and `opportunity_producer_dispatches`' own
migration comment says exactly why: "the uniqueness that makes the
insert-then-conditional-update claim safe under concurrency" is "the unique
key … not a check-then-insert". §7.2's use of the idiom is sound precisely
because `staff_booking_locks.staff_user_id` **is** the primary key.
`contacts` has no such key, so the idiom does not transfer.

#### 5.8.1 Why a unique index on `contacts` is not the answer either

Adding `unique (location_id, phone)` (or `(business_id, location_id,
phone)`) to the existing table is mechanically unsafe and is rejected
(§15). It would fail at migration time on real data, because duplicate
`(location_id, phone)` rows are produced today by ordinary, intended
behaviour on any single-active-Location Business: group cloning
(`ContactsController.php:583-589`, `ReplicateContacts.php:64-70`),
`batchContactCopy()` (`EloquentContactsRepository.php:518-535`), CSV import
(`ContactGroups.php:857`), paste import (`ContactsController.php:1174-1177`)
and multi-group inbound keyword opt-in (`DLRController.php:1219-1236`) —
each of which de-duplicates by `group_id` only. The Location backfill then
stamps one Location id across those rows, converting historical
cross-group duplicates into colliding non-NULL pairs. Five existing tests
and five code comments assert that this duplication is legitimate
(§3.6.1). The index would also constrain nothing where it matters most:
MySQL's unique index ignores NULLs, and `location_id` is NULL for every
Contact of a multi-Location Business today.

#### 5.8.2 The normalized phone this slice keys on

Calendar's identity key is the **stored form**, because it has to match
rows that already exist:

```
normalizedPhone = trim(str_replace(['+', '-', '(', ')', ' '], '', $raw))
```

That is exactly what `EloquentContactsRepository.php:695` computes and
`:709` stores, and it is `StringHelper::removeExtraCharacters()`
(`app/Library/StringHelper.php:194-197`) plus a `trim()`. **Sub-slice E must
not use `E164Normalizer` or `AgencyProspectPhoneNormalizer`**: both exist
and both are genuinely better normalizers, but their output (a leading `+`,
or region-inferred digits) does not match what `contacts.phone` holds, so
using either would silently fail to find existing Contacts and create
duplicates instead. Two honest limits of inheriting this form: it does not
strip tabs, dots, slashes or non-ASCII digits, and the model's
`'phone' => 'integer'` cast means reads come back as integers. Both are
pre-existing properties of the whole codebase, which this slice inherits
rather than fixes (§15).

#### 5.8.3 `booking_contact_identity_locks` — the serialization row

One new table, whose only purpose is to serialize Location-local Contact
resolution. It is the narrow dedicated lock the concurrency requirement
needs, and it locks nothing that another domain uses:

```
booking_contact_identity_locks
  id
  business_location_id   FK -> business_locations, cascadeOnDelete, NOT NULL
  normalized_phone       string(32), NOT NULL
  timestamps

  unique (business_location_id, normalized_phone)
```

It carries **no `contact_id` and no other data**: a second pointer to the
resolved Contact would be a second source of truth that can drift from
`contacts`. `cascadeOnDelete` because the row is pure infrastructure with
no audit value — an archived Location's lock rows are meaningless. The
unique key is not decoration: it is what makes the `insertOrIgnore` below
idempotent, per §5.8's own argument above.

#### 5.8.4 Resolution algorithm

Modelled on `AiUsageLedgerManager::lockOrCreatePeriod()`
(`app/Library/Ai/AiUsageLedgerManager.php:441-474`), this repository's
canonical ensure-then-lock for a composite key, **including its ordering**,
which exists for a documented reason: InnoDB answers `SELECT ... FOR UPDATE`
for a missing row with a shared gap lock, and the insert each waiter then
needs takes a conflicting insert-intention lock in that same gap, so
concurrent callers deadlock. The unlocked probe first is what avoids it.

Inside the booking transaction, after §7.4's locks are held:

1. **Probe, unlocked** — an ordinary MVCC read for the
   `(business_location_id, normalized_phone)` row. Takes no locks at all.
2. **If absent, `insertOrIgnore`** that pair. Whoever loses the race simply
   finds the winner's row at step 3; no exception either way.
3. **Lock** — `->where('business_location_id', ...)->where('normalized_phone',
   ...)->lockForUpdate()->firstOrFail()`. From here, exactly one request at
   a time holds this identity.
4. **Resolve under the lock** —
   `Contacts::query()->where('location_id', $locationId)
   ->where('phone', $normalizedPhone)->orderBy('id')->first()`.
5. **Reuse or create.** Found → return it unchanged: never rewrite an
   existing Contact's `group_id`, `business_id` or `location_id`, and never
   attach a Contact whose `location_id` is anything other than the booked
   Location. Absent → create it (§5.8.5) with `location_id` set explicitly
   to the booked Location.

The lock is released with the booking transaction either way, so a refused
booking leaves no Contact behind. What happens to the lock **row** itself
depends on which state it was in, and this is stated precisely rather than
generalized: an already-existing lock row (found at step 1, or inserted by
an earlier, successfully committed attempt) simply remains — it was never
part of the transaction that rolled back. A **first-use** lock row —
`insertOrIgnore`d at step 2 of *this same* transaction — rolls back with it
if the booking is later refused, exactly like any other row this
transaction wrote. That is correct and harmless, not a bug to guard
against: the row is a reusable identity, not a claim, and the next attempt
recreates it idempotently through the identical ensure-then-lock sequence
(steps 1–2 above) — `insertOrIgnore` succeeds whether the row is genuinely
absent or was rolled back a moment ago. Either way, no Contact ever survives
a refused or rolled-back booking.

**Several existing Contacts may legitimately match at step 4** (§3.6.1), so
`orderBy('id')->first()` is specified rather than left open: Calendar takes
the **oldest deterministically**. This differs on purpose from
Conversations and the automation trigger, which refuse to choose among
same-number Contacts because displaying one of two people's names would be
a guess (`ChatBox.php:151-154`,
`MessageReceivedTriggerSource.php:33-36`). The consequence differs too: for
a booking, refusing would mean a real customer cannot book because of a
legacy import artifact they know nothing about, which is worse than
attaching the appointment to the older of two rows. Calendar never merges,
rewrites or deletes the other duplicates.

#### 5.8.5 The Contacts seam

**`createContactFromRequest()` cannot be reused unchanged**, for four
mechanical reasons, each verified: (a) its match is
`$contactGroups->subscribers()->firstOrNew(['phone' => trim($phone)])`
(`:708-710`) — `group_id`-scoped, not Location-scoped; (b) it sets
`location_id` only for a new subscriber and only via
`Contacts::singleActiveLocationIdFor()` (`:729-735`), which returns NULL for
every multi-Location Business; (c) its `Rule::unique` is evaluated against
the **raw** submitted string while `firstOrNew` matches the **stripped**
one (`:697-703` vs `:709`), so the two disagree; and (d) it sends the
group's welcome/signup SMS (`:761-805`) through a `$phoneUtil->parse()` that
can throw an uncaught `NumberParseException` — a public booking must not
spend money on an SMS nobody asked for, nor fail because one could not be
sent.

So Sub-slice E adds **one narrow method on the existing
`EloquentContactsRepository`** — not a new parallel Contacts service, and
never a raw write from the controller:

```
findOrCreateForBooking(
    BusinessLocation $location,
    ContactGroups $contactGroups,
    string $rawPhone,
    array $input = [],
): Contacts
```

It reuses, rather than reimplements, every existing behaviour that still
applies:

- **Blacklist**: `Contacts::isListedInBlacklist()`
  (`app/Models/Contacts.php:176-179`), the same suppression check
  `createContactFromRequest()` makes. A blacklisted number is refused, which
  means it cannot self-book — the same outcome the existing public opt-in
  page already produces for such a number, and stated here so it is a known
  product consequence rather than a surprise.
- **Custom fields**: `Contacts::updateFields($input)`
  (`app/Models/Contacts.php:291-325`) — never a hand-rolled
  `contacts_custom_field` write. Note this method re-writes `phone` when a
  `PHONE` tag is present (`:319-322`), so the seam passes the normalized
  value or omits the tag.
- **Row shape on create**: `group_id`, `customer_id` and `business_id` from
  the group, `status = 'subscribe'` — identical to
  `createContactFromRequest()` (`:724-727`) — and **`location_id` set
  explicitly to `$location->id`**, which is the one deliberate divergence.
- **Automation dispatch**: the same `AutomationJob::forContactCreated()` /
  `EnrollWorkflowContact::forContactCreated()` `afterCommit()` dispatches
  guarded by `wasRecentlyCreated && business_id !== null` (`:746-757`), with
  an existing `ContactCreationSource` case. No new enum case is added unless
  Sub-slice E first confirms it does not collide with the automation
  builder's negative-vocabulary guardrail
  (`NoUnsupportedVocabularyTest.php:78,92,160`, §3.2).
- **Not reused**: the welcome/signup SMS, deliberately (reason (d) above).

**Which `ContactGroups`.** `contact_groups` has `business_id` but **no
`location_id`** — it is a Business-level container with no Location axis, so
the group is never the identity key; `location_id` is. Sub-slice E resolves
the Business's group deterministically (oldest by id) and, when the Business
has none, creates one through the existing seam
`EloquentContactsRepository::store()` — the same path
`ContactDirectoryController::createFirstList()` uses for its idempotent
first list named `Contacts`
(`app/Http/Controllers/Customer/Business/ContactDirectoryController.php:97-114`).
It never invents a per-Location group concept.

No Location-scoped Contact resolver exists today to reuse: the closest thing
on `main` is `private` and Business-scoped
(`MessageReceivedTriggerSource::theOneSubscribedContact()`,
`app/Library/Automation/Workflow/Triggers/MessageReceivedTriggerSource.php:173-188`).
The seam above is the first of its kind; it is identity resolution only, it
is not a second ACL, and it never decides authorization.

## 6. Authority / security contract

Every Location-scoped read or write — Booking Type CRUD, availability
rule CRUD, appointment CRUD, the calendar view query itself — calls
`LocationAccessGuard::assertUserCanAccessLocation($userId, $location)`
(or `userCanAccessLocation()` for a filtering read) **fresh, every time**,
re-deriving Location→Business→Workspace from repositories exactly as that
guard already does (Addendum §4: "Knowing or binding a record ID must
never bypass Location authorization... never trust a route-bound model").
No new parallel ACL algorithm is written.

**Role resolution, explicitly stated because the existing guard doesn't
give it for free**: `LocationAccessGuard` is role-blind — Admin and Staff
memberships are checked identically (`business_access_scope`/
`location_access_scope` only). Per the Acceptance Matrix's permission
boundary ("Owner + staff per Location ACL"), this slice deliberately does
**not** invent a new Admin-only gate for Booking Type management that the
existing ACL model has no precedent for — any Workspace member (Admin or
Staff, `WorkspaceMembershipRole` has no separate "Owner" case; ownership is
`Workspace.owner_user_id`/`Business.customer_id`, checked before
membership per the guard's own precedence) who is authorized for a
Location may manage that Location's Booking Types and see/manage every
appointment at it.

**Availability authority is settled for V1** — it is not left as an
implementation-time question, and Sub-slice B does not stop to ask. The one
genuine new distinction Blueprint §12 itself draws is that "staff
availability is set **per staff member**"; who may set it for whom is:

| Actor | May manage availability for |
|---|---|
| **Workspace or Business Owner** — `Workspace.owner_user_id` or `Business.customer_id`, the two branches the guard already checks before membership | **any staff member currently eligible for the target Location** |
| **Admin** (`WorkspaceMembershipRole::Admin`) | **themselves only**, in V1 |
| **Staff** (`WorkspaceMembershipRole::Staff`) | **themselves only** |

The Owner row is not an escalation this contract invents: governing
architecture already gives the Business Workspace Owner full authority over
that Business, including its staff, and `LocationAccessGuard` already
grants owners unconditional Location access ahead of any membership check
(`app/Library/Workspace/LocationAccessGuard.php:115-121`). Onboarding a
staff member's opening hours is exactly that authority.

The Admin row is deliberately the narrower of the two readings: no
authoritative document grants Admins team-availability management, and the
Acceptance Matrix's permission boundary is "Owner + staff per Location
ACL". If a later authoritative source explicitly grants Admins
team-availability management, widening this row is a one-line change to
this table — but it is not assumed here.

Three conditions bind **every** row of that table, without exception:

1. the actor must pass the ordinary Location authorization for the path
   they are using (`LocationAccessGuard`, §6's opening rule) — owner
   authority over staff is not authority over a Location they cannot
   reach;
2. the **target** staff member's eligibility for that Location is
   re-derived at write time through the same guard (§6's eligibility rule),
   so availability can never be written for someone who is not currently
   eligible there; and
3. `staff_time_off` is User-global (§5.3), so an Owner writing time off for
   a staff member removes them from **every** Location they are granted,
   not only the one the Owner reached them through — the write surface must
   say so plainly to the actor.

**Staff eligibility is re-derived, never inherited from the pivot.**
`booking_type_staff` (§5.1) records configuration intent only. A staff
member is *eligible* for a Booking Type at a given moment only when
`LocationAccessGuard::userCanAccessLocation($staffUserId, $location)`
returns true for that Booking Type's own `business_location_id`, re-read
from persistence at that moment. This is checked in **both** places:

- **Configuration time** (Sub-slice B, attaching a staff member to a
  Booking Type): a candidate who is not currently eligible for that
  Location is refused — an unrelated Workspace's User cannot be attached
  by supplying a raw uid, because the guard re-derives
  Location → Business → Workspace and finds no ownership or active
  membership.
- **Booking time** (Sub-slice C, every round-robin candidate and every
  explicitly requested staff member, authenticated or public): each
  candidate is filtered through the same call inside the booking
  transaction. A member whose Workspace membership was deactivated or
  deleted, whose `business_access_scope`/`location_access_scope` no longer
  reaches that Location, or whose `workspace_membership_locations` grant
  was revoked is **immediately** ineligible — even while a
  `booking_type_staff` row, a `staff_availability_rules` row, or both,
  remain in place. Stale configuration rows never restore access, and this
  slice never deletes them to achieve that: eligibility is a currently-true
  fact, not a stored one.

No second ACL algorithm is written for any of this — the guard is the only
authority, exactly as §6's opening paragraph already requires. Two
properties of the existing guard make it directly usable for a *third
party's* eligibility, and both were verified on `main` rather than assumed
(`app/Library/Workspace/LocationAccessGuard.php`):

- it re-derives the Location, its Business and its Workspace from their
  repositories on every call and defaults to false at every branch; and
- its cross-Workspace Agency View-As branch is asked **only** about the
  authenticated actor — `BusinessRouteAccess::viewedBusinessIdFor($actorId)`
  returns null whenever `$actorId` is not the signed-in user
  (`app/Library/Workspace/BusinessRouteAccess.php:130-152`, whose own
  docblock records why asking it about another user is actively harmful).
  Evaluating a *staff member's* eligibility while an Agency actor is
  viewing therefore falls through to that staff member's own ordinary
  tenancy rules, which is exactly the intended answer.

**The public booking surface has no session, and therefore must call every
authority the middleware stack would otherwise have called for it.**
`routes/public.php` is grouped with `['web',
RecordLegacyWebhookUsage::class]` only
(`app/Providers/RouteServiceProvider.php:64-65`), and
`CustomerAccountAccessGate` — the account-lifecycle security boundary for
authenticated customers — returns early for a guest
(`app/Http/Middleware/CustomerAccountAccessGate.php:184-192`). A public
booking route therefore inherits **no** account gate, **no** entitlement
gate and **no** Location check by virtue of being routed. The earlier
draft's claim that "the booking engine's own Location-bound schema is what
prevents cross-Business leakage" is withdrawn: schema prevents
*mis-attribution*, not *unauthorized use of a locked or unentitled
account*.

The precedent for doing this correctly already exists and is followed
exactly — `WebsitePublicEntitlementGate`
(`app/Library/Website/WebsitePublicEntitlementGate.php:12-66`), the only
public Business-scoped surface in the repository, consulted by
`WebsiteController::resolveSnapshotOrAbort()` before any cache read
(`app/Http/Controllers/Public/WebsiteController.php:77-90`). Every public
booking read **and** every public booking write re-runs this ordered stack,
fresh, with nothing memoized between the page render and the booking POST:

1. **Resolve the Location from a dedicated public identifier**, then refuse
   unless `lifecycle_state = BusinessLocationLifecycleState::Active`
   (`app/Enums/Business/BusinessLocationLifecycleState.php`). Neither
   `Business.uid` nor `BusinessLocation.uid` may be that identifier:
   `HasUid` generates them with `uniqid()`
   (`app/Library/Traits/HasUid.php:29`), and this repository already
   rejected them for exactly this purpose — `routes/public.php:210-215`
   records that the public Website surface uses a separate, independently
   generated UUID, "never `Business.uid`". Sub-slice E provides that
   identifier explicitly (§12.E) rather than inheriting a `uniqid()` value.
2. **Business `status = Active` and Workspace `is_active`** — the
   always-fresh, never-cached layer of the Website gate's own shape.
3. **Account lifecycle**:
   `CustomerAccountAccessGuard::decisionForBusiness(?Business $business)`
   (`app/Library/Entitlement/CustomerAccountAccessGuard.php:54`), the
   existing reusable seam for exactly this situation — no web session
   required. A Locked, Inactive or Suspended account, including one locked
   *through* its managing Agency (which `CustomerAccountAccessResolver`
   composes automatically), cannot take public bookings. The Website gate
   reaches this only indirectly, through entitlement precedence; this slice
   calls the account authority directly instead of relying on that side
   effect.
4. **Entitlement**: `EntitlementManager::decide($workspace, $business,
   PlatformFeature::Calendar->value, (int) $business->customer_id)`
   (`app/Library/Entitlement/EntitlementManager.php:148`). Both argument
   shapes are verified rather than assumed: `decide()` takes a **raw
   string** feature key (pass `->value`; only
   `enableBusinessFeature()`/`disableBusinessFeature()` take the enum), and
   the actor is the Business's real persistence owner
   `(int) $business->customer_id` — a public request must never call
   `Auth::id()` or fabricate an actor, per the Website gate's own stated
   rule (`WebsitePublicEntitlementGate.php:12-26`).
5. **Booking Type**: active, and its `business_location_id` equal to the
   resolved Location's id — re-read from persistence, never taken from the
   request.
6. **Staff eligibility**: every candidate re-derived through
   `LocationAccessGuard::userCanAccessLocation()` exactly as above. Public
   booking gets no weaker eligibility rule than staff-initiated booking.

**Every refusal in this stack is a 404**, never a 403 and never a
distinguishable error, matching `WebsiteController`'s
`abort_unless($this->gate->allows($website), 404)`: a public caller must
not be able to tell "this Location exists but its account is locked" from
"no such Location". Steps 1–6 run again inside the booking write path
(§7.4), because a page rendered a minute ago proves nothing about the
account's state at the moment of the write.

**Every authenticated Calendar route is entitlement-gated from the moment it
exists — hiding the nav item is not a gate.** Sub-slices B and D add
authenticated customer routes while `PlatformFeature::Calendar` is still
`Planned` (§11), and a Planned feature must not become executable because
someone guessed or kept a URL. Nav hiding does not achieve that: recon
confirmed `CustomerMenuBuilder::entitled()` only omits a `MenuItem` from the
array the view renders, touches nothing in the routing layer, and leaves
every route registered and reachable
(`app/Library/Navigation/CustomerMenuBuilder.php:537-551`). The repository
states the same rule in a test docblock: "a forged direct request is refused
SERVER-SIDE. Navigation is irrelevant: the request never renders a link"
(`tests/Feature/GoogleBusinessProfile/GoogleBusinessProfileEntitlementTest.php:176-179`).

Every Calendar HTTP route and action created before Sub-slice E therefore
independently requires all three of:

1. **Ordinary Workspace/Business tenancy** for the actor;
2. **`LocationAccessGuard`** for the exact Location, wherever the action is
   Location-scoped (§6's opening rule); and
3. **An `EntitlementManager` decision for `PlatformFeature::Calendar`.**

The mechanism is the existing one, not a new one. There is **no feature
middleware anywhere in this repository** — every gated surface does it
in-controller — and the canonical seam is
`ResolvesBusinessTenancy::resolveEntitledBusinessTenancy($workspaceUid,
$businessUid, PlatformFeature::Calendar->value)`
(`app/Http/Controllers/Customer/Business/Concerns/ResolvesBusinessTenancy.php:82-99`),
the same trait Website generation, GBP, Automations and CRM already use. It
`abort(404)`s on refusal — never 403, never a redirect, never a flash — and
this slice matches that exactly. Note the argument is a **raw string** key,
so pass `PlatformFeature::Calendar->value`.

**While Calendar is `Planned`, those routes fail closed automatically, with
no extra code.** `EntitlementManager::snapshotBusinessFeatureDecisions()`
returns `new EntitlementDecision(false, 'platform_feature_unavailable')`
before any database read (`app/Library/Entitlement/EntitlementManager.php:201-205`),
and `decide()` is a thin wrapper over it. A platform admin cannot even
override it: an Allow override for an unavailable feature is refused at write
time (`:1933`). So the gate is real from Sub-slice B onward, and **Sub-slice
E's final `Planned → Available` flip (§11) is precisely what makes the
already-built authenticated surfaces executable.** The flip does not move
earlier for any reason.

`'calendar'` is also added to
`CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES`
(`app/Library/Navigation/CustomerMenuBuilder.php:96-106`) in Sub-slice D, so
the nav entry appears only when entitled — a cosmetic complement to the
server-side gate above, never a substitute for it.

§13 requires the pair of tests that prove this, and they are genuinely
novel: recon found **no existing test anywhere in `tests/`** that proves a
Planned feature's authenticated route is refused, because no Planned feature
has a route today. The nearest analogue to copy is
`GoogleBusinessProfileEntitlementTest.php:145-190`, which proves the same
shape for an *unentitled-by-plan* feature.

External calendar connections (§5.5) are strictly per-User and never
exposed across Workspaces — a User's Google/Outlook tokens are never
readable or actionable by any Workspace admin, only by the connecting User
themself and the sync job acting on their behalf.

## 7. Transaction / concurrency boundary

**Double-booking prevention is a hard invariant, enforced in application
code with row locking — MySQL 8.4 has no native temporal-exclusion
constraint** to express "no two Scheduled rows for the same staff member
may overlap in time" as pure DDL. The mechanism mirrors
`OpportunityManager::beginRun()`'s exact locking technique
(`app/Library/Opportunity/OpportunityManager.php:164`, "An active run
already exists for business [X], worker [Y]"): before any insert or
reschedule, lock a single serialization point for that staff member via
`SELECT ... FOR UPDATE` inside the same transaction as the overlap check
and the write.

**The locked row is a dedicated `staff_booking_locks` row (one per
`staff_user_id`), not the `users` row itself** — locking `users` directly
would create contention with every unrelated concurrent write to that
table (profile edits, auth, etc.); mirroring `OpportunityManager`'s
*technique* without reusing its *exact locked table* (which was safe there
because `businesses` has no comparable unrelated write pressure inside a
booking transaction).

### 7.1 The three serialization points

| Tier | Row | Locked by | Purpose |
|---|---|---|---|
| 1 | `booking_type_round_robin_state` for the Booking Type | any operation that performs round-robin assignment | serializes the rotation cursor (§5.1.1) |
| 2 | `staff_booking_locks` for **every** staff member the operation could bind | every scheduling and lifecycle mutation, and external busy-cache writes (§7.5) | serializes one staff member's whole timeline across **all** Locations — what makes cross-Location conflict prevention structural rather than a per-Location check (Blueprint §12) |
| 3 | the `appointments` row(s) the operation mutates | reschedule, cancel, complete, no-show | serializes the lifecycle state machine of one appointment |

### 7.2 `staff_booking_locks`: race-free first use

The lock row must exist before it can be locked, and two first-ever
bookings for the same staff member must not be able to (a) both conclude
no row exists, (b) bypass serialization, or (c) fail nondeterministically
on a duplicate-key insert. "Created lazily on first use" is therefore
specified exactly, not left to the implementer:

```
staff_booking_locks
  staff_user_id   FK -> users, cascadeOnDelete, PRIMARY KEY (one row per staff member)
  timestamps
```

**`cascadeOnDelete`, deliberately unlike every other user FK in this
schema.** This row is pure serialization infrastructure: it holds no data,
carries no audit value, and means nothing once the staff member is gone.
`restrictOnDelete` here would make a User undeletable for the sole reason
that someone once booked them — and this repository hard-deletes Users. It
has **no SoftDeletes** on `users` (`app/Models/User.php:52-54`, no
`deleted_at` column anywhere) and ten distinct hard-delete paths in `app/`,
including admin customer delete and sub-account delete. Its own canonical
rule is explicit that Users are exempt from the no-hard-delete policy that
covers Workspaces and Businesses: an infrastructure table "must never block
a legitimate user-deletion feature elsewhere in the system"
(`database/migrations/2026_07_31_120001_create_workspace_transitions_table.php:15-19`;
RFC-003 §17 names only `Workspace`, `WorkspaceMembership` and `Business`).
The closest existing analogue — `business_home_visits`, a per-(user,
business) marker row — uses `cascadeOnDelete()` on both parents
(`database/migrations/2026_09_16_100001_create_business_home_visits_table.php:31-32`).

**Some other user FKs in this schema keep a stricter posture, and that is a
deliberate, stated consequence.** `appointments.staff_user_id`,
`staff_availability_rules.staff_user_id` and `staff_time_off.staff_user_id`
remain `restrictOnDelete`, because those rows *are* historically
meaningful — which does mean a staff member who has ever been booked, or who
has an availability rule or time-off record on file, cannot be hard-deleted
until those rows are dealt with. That is the correct trade for operational
history and it is recorded here rather than discovered later.

**`booking_type_staff.staff_user_id` (§5.1) and
`external_calendar_connections.user_id` (§5.5) are `cascadeOnDelete`, not
`restrictOnDelete` — corrected from an earlier draft that grouped them with
the historically-meaningful FKs above.** Neither carries independent audit
value the way a completed Appointment or a recorded availability rule does:
`booking_type_staff` is configuration intent only (a nomination, re-derived
from canonical persistence at booking time, never itself authorization —
see below), and `external_calendar_connections` holds operational OAuth
state, not a transactional record. Both are exempted from the
historical-meaningfulness trade above for the same reason the lock row is:
none of the three protects anything that would be lost by letting a User
deletion remove it.

1. **Ensure, outside the transaction** (a single autocommitted statement,
   before `DB::beginTransaction()`), for **every** staff member the
   operation could bind — the one explicit staff member, or the whole
   round-robin candidate set — in one batch:
   `DB::table('staff_booking_locks')->insertOrIgnore([...rows...])`
   (`INSERT IGNORE`). This is idempotent by construction: a duplicate is
   ignored, never an error, so two concurrent first-ever bookings both
   succeed here and neither sees an exception. Running it outside the
   transaction is deliberate — an `INSERT` of the same key *inside* two
   concurrent transactions would make one wait on the other's
   insert-intention lock for the whole transaction, turning first use into
   a latency cliff.
2. **Lock, inside the transaction**, in ascending `staff_user_id` order
   (§7.4 tier 2): `SELECT ... FROM staff_booking_locks WHERE staff_user_id
   = ? FOR UPDATE`.
3. If step 2 returns no row — only possible if the row was removed between
   the two steps — re-run step 1 for that id and re-select **once**. If it
   is still absent, raise a domain exception and abort the transaction.
   The booking never proceeds unserialized, and never retries unboundedly.

§12.C/§13 require an explicit **first-use concurrency test**: two
concurrent processes booking a brand-new staff member who has no
`staff_booking_locks` row at all must produce exactly one appointment, no
duplicate-key error, and no unserialized second write.

### 7.3 Round-robin: deterministic, durable, non-starving

Inputs: the Booking Type's `booking_type_staff` pool (§5.1), filtered to
those **currently eligible** (§6's re-derivation, never the pivot alone)
and **available** for the requested interval (availability rules and time
off, §5.2/§5.3). Call that set `E`, always ordered by `staff_user_id`
ascending — a stable, persisted key, never insertion order, name, or
appointment counts.

1. Lock the Booking Type's `booking_type_round_robin_state` row (tier 1,
   same ensure-then-lock discipline as §7.2), then the tier-2 locks for
   every member of `E` in ascending order. The `insertOrIgnore` ensure runs
   OUTSIDE the transaction and the tier-1 `lockForUpdate` is the
   transaction's FIRST statement: under REPEATABLE READ the first plain
   SELECT pins the snapshot, and a snapshot pinned before the tier-1 wait
   ends cannot see the booking the previous holder committed, so the
   overlap check would miss it and double-book. No plain read may precede
   any tier's lock inside the transaction.
2. Let `c = last_assigned_staff_user_id`. The **candidate order** is every
   member of `E` with `staff_user_id > c`, ascending, followed by every
   member with `staff_user_id <= c`, ascending — i.e. the rotation
   continues at the successor of whoever was last assigned and wraps
   exactly once. When `c` is NULL (or its User is no longer in `E`), the
   order is simply `E` ascending. This is a pure function of persisted
   state, so two workers computing it under the same lock compute the same
   answer.
3. Walk that order and run §7.6's overlap check for each candidate. The
   first candidate with no overlap wins.
4. On success — and only then — write the appointment **and**
   `last_assigned_staff_user_id = winner`, `last_assigned_at = now()` in
   the same transaction, and commit. **The cursor advances only on a
   committed booking**: a refusal, an exception, or a rolled-back
   transaction leaves it exactly as it was.
5. If every candidate overlaps (or `E` is empty), raise
   `NoEligibleStaffAvailableException`, write nothing, and leave the cursor
   untouched.

**No starvation**: the cursor records the last staff member actually
*assigned*, not a positional index, and skipping never moves it. A staff
member who is unavailable this time keeps their place in the rotation and
is tried again at their normal turn on the next booking, rather than being
permanently passed over. Adding or removing staff cannot corrupt the
cursor either — the successor rule is computed over ids present in `E`
right now, so a removed cursor id simply means "start at the lowest".

Because tier 1 is held for the whole assignment, two concurrent
round-robin requests for the same Booking Type cannot both read the same
"next" and both act on it: the second waits, then computes its successor
from the first's committed result.

### 7.4 One canonical lock order for every scheduling and lifecycle mutation

Every mutation below acquires a **prefix of the same total order**:
**tier 1 → tier 2 (ascending `staff_user_id`) → tier 3 (ascending
`appointments.id`)**, skipping tiers it does not need but never reordering
them. A lock is never acquired after a lower-numbered tier has been
skipped and then needed later, so no cycle can form between appointment
rows, staff locks and the rotation cursor.

| Mutation | Tier 1 | Tier 2 | Tier 3 | Required source state |
|---|---|---|---|---|
| Create, explicit staff | — | that staff member | — | n/a |
| Create, round-robin | the Booking Type's state row | every candidate in `E`, ascending | — | n/a |
| Reschedule (same staff) | — | that staff member | the appointment | `scheduled` |
| Reschedule (moving staff) | — | old **and** new staff, ascending | the appointment | `scheduled` |
| Cancel | — | the appointment's staff member | the appointment | `scheduled` |
| Complete | — | the appointment's staff member | the appointment | `scheduled` |
| No-show | — | the appointment's staff member | the appointment | `scheduled` |
| External busy-cache write (§7.5) | — | the connection's User | — | n/a |

Inside the transaction, after the locks are held, every lifecycle mutation
follows the identical shape:

1. **Re-read `status` from persistence under the tier-3 lock** — never
   trust a status read before the lock, a route-bound model, or a value
   carried in the request. The same re-read also confirms the appointment's
   `staff_user_id` is still the staff member whose tier-2 lock was taken
   (which had to be chosen from the caller's model, before any lock). If a
   competing reschedule moved it, the mutation fails closed with
   `AppointmentStaffChangedException`, writing and dispatching nothing; it
   never locks the newly discovered staff member, because tier 2 after
   tier 3 would break the canonical order. The caller re-reads and retries.
2. **Validate the allowed source state.** Every transition above requires
   `scheduled`; `cancelled`, `completed` and `no_show` are terminal, with
   no transition out of them. A mutation whose source state no longer
   holds raises a domain exception and writes nothing.
3. Perform the single state change (and, for reschedule, the interval
   re-check of §7.6 against the **new** interval).
4. **Dispatch the corresponding event exactly once**, from the transaction
   that actually performed the transition, and only after it commits (§10
   — the five events implement `ShouldDispatchAfterCommit`, so a
   rolled-back or refused mutation emits nothing).

Consequences, each an explicit adversarial test in §12.C/§13:

- **Reschedule racing cancel** has exactly one valid outcome. Whichever
  transaction takes the tier-3 lock first commits its transition; the
  other re-reads the status under the same lock and sees the result. If
  cancel won, the reschedule refuses and the appointment stays cancelled
  with its original times. If reschedule won, the cancel proceeds against
  the rescheduled appointment. There is no interleaving that produces a
  half-rescheduled, half-cancelled row.
- **Cancel cannot fire twice**: the second transaction sees `cancelled`,
  fails step 2, writes nothing and dispatches nothing.
- **Complete and no-show cannot both succeed**: both require `scheduled`,
  so the second is refused — exactly one terminal state and exactly one
  event.
- **Two concurrent reschedules** serialize on tier 3; the second re-reads
  the current times and re-runs the full interval check for its own new
  interval.
- **A failed reschedule leaves the original appointment semantically
  unchanged, byte for byte**: the refusal is raised before any `UPDATE`,
  and the transaction rolls back regardless.

Every one of these transactions is wrapped in `DB::transaction(..., 3)`,
the same bounded-retry-on-genuine-deadlock precedent Contract 01 already
uses — the total order above means these paths cannot deadlock each other,
and the retry exists only for a victim chosen because of unrelated
concurrent work on the same parent rows.

### 7.5 External busy-cache writes share tier 2

External calendar sync (Sub-slice F) is **not** independent of this lock,
and §12.F's earlier claim that it never interacts with C's staff lock is
withdrawn. Without coordination a real local race exists: a booking
transaction holds the staff lock and reads the busy cache, while a
concurrent sync inserts a busy interval that was already true at the
provider — the booking then commits against a cache state that changed
underneath its own check.

The rule, for the **local** consistency boundary only:

1. **Every provider HTTP call happens outside the database transaction and
   outside any lock** — no network I/O is ever performed while holding
   tier 2 (a provider timeout must never hold a staff member's booking
   timeline hostage).
2. Once the delta or full page-through has been fetched into memory,
   apply it locally: ensure-then-lock that connection's **User's**
   `staff_booking_locks` row (tier 2, §7.2), then transactionally apply
   the busy-block creates/updates/deletes **and** the
   `sync_cursor`/`last_synced_at` advance (§5.6), then commit — releasing
   the lock.
3. Booking reads the busy cache while holding that same tier-2 lock (§7.6
   step 3), so a booking check and a sync application of the same staff
   member's external state are strictly serialized.

This cannot conjure knowledge of a provider event the platform has not yet
received — nothing can — but it does guarantee that provider state which
**is** being applied locally can never interleave into the middle of a
booking's own check-and-write.

### 7.6 The check itself, and its extensible busy sources

With the locks of §7.4 held, inside the same transaction:

1. Query for any existing `status = 'scheduled'` appointment for that
   `staff_user_id` whose `[start_at, end_at)` interval overlaps the
   candidate interval — across every Location.
2. Union in any `external_calendar_busy_blocks` row for that staff
   member's connection overlapping the same interval (§5.6) — advisory,
   additive, never authoritative on its own; if none exists or sync is
   stale, this union contributes nothing and internal-appointment checking
   alone remains fully authoritative (§11's fail-safe-stale rule).
3. If any overlap is found, refuse (raise a domain exception; no partial
   write).
4. Otherwise insert (or, for reschedule, update `start_at`/`end_at` in
   place) and commit.

**Reschedule** runs this identical check against the *new* interval, under
the same locks, in the same transaction as updating the existing row — so
a reschedule is atomically all-or-nothing: either the new interval clears
every check the way a fresh booking would, or the original appointment is
left completely untouched.

The overlap query is written as an **extensible union of "busy sources"**
from the start, so Sub-slice F is additive on the *read* side — it
registers `external_calendar_busy_blocks` as a second source without
touching the locking/transaction structure Sub-slice C already shipped and
tested using only internal appointments. (Its *write* side is §7.5, which
Sub-slice C's lock design must anticipate but need not implement.)

## 8. Migration / backfill

None. Every table in §5 is new; there is no legacy row set to migrate or
backfill (§3.2/§4 — nothing on `main` today is a predecessor schema for
any of these tables). Contract 13's "precondition-check-then-DDL" pattern
does not apply here for the same reason it didn't for most of Contracts
1–12's additive migrations: no existing rows to reconcile.

## 9. Backwards compatibility

Not applicable in the retire-an-old-model sense (§14's framing) — this is
pure net-new addition. Two explicit non-interactions, stated so a future
reader doesn't assume otherwise: (1) `AgencyProspect.booked_at`/
`AgencyProspectStatus::Booked` (§3.4.2) is **not** modified, replaced, or
wired to Calendar by this slice; (2) `CrmOpportunity`'s schema is **not**
modified by this slice (§5.7) — both are named explicitly in §15 as
non-goals rather than left ambiguous.

## 10. Events / audit

New events, one per lifecycle transition named in Blueprint §12 (for
Automations §13 consumption, per the Blueprint's own "each firing the
corresponding automation event" line):

- `AppointmentScheduled` — `appointmentId, businessLocationId, bookingTypeId, staffUserId, contactId, crmOpportunityId (nullable), startAt, endAt, createdByUserId (nullable)`
- `AppointmentRescheduled` — `appointmentId, previousStaffUserId, newStaffUserId, previousStartAt, previousEndAt, newStartAt, newEndAt, rescheduledByUserId (nullable)`
- `AppointmentCancelled` — `appointmentId, staffUserId, cancelledByUserId (nullable), reason (nullable)`
- `AppointmentCompleted` — `appointmentId, staffUserId, completedByUserId (nullable)`
- `AppointmentNoShow` — `appointmentId, staffUserId, markedByUserId (nullable)`

**`AppointmentRescheduled` carries both staff ids, always.** §7.4 lists
"Reschedule (moving staff)" as a supported mutation and locks **old and
new** staff rows for it, so a single `staffUserId` field would be
ambiguous exactly when it matters most — a consumer could not tell whether
the appointment moved in time, moved between staff, or both. The two
fields are therefore mandatory and always populated: on a same-staff
reschedule they are equal, and a consumer detects a staff move by
comparing them rather than by inspecting which optional field was set. No
consumer is required to infer the previous staff member from anything
else, because nothing else records it (§5.4).

All numeric ids only (no PII in the payload), matching this codebase's
existing event-payload convention (e.g. `LocationAccessDeniedException`,
`WorkspaceMembershipBusinessAssigned`).

**These events are explicitly NOT an audit trail.** RFC-002 §41
(`docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md:1155-1157`) is the governing rule
this repository already follows everywhere: the durable transitions table
"is the durable business audit log — domain events are a notification
mechanism, not a substitute for it," written "in the *same transaction* as
the state change." RFC-003 §19 (`:688`) states the reason — "events can be
missed by a listener" — and the facts on `main` bear it out: 68 of the 88
classes under `app/Events/` have **no listener at all**, nothing persists
any event to any table, there is no activity-log package, `Auditable`
trait, model observer or wildcard listener, and the two queued listeners
that do exist run with `$tries = 1`, so a failed delivery is never retried.
`WorkspaceMembershipBusinessAssigned`, cited above only as a *payload
shape* precedent, is itself one of the 68: the facts it carries survive
because `workspace_transitions` holds them, not because the event was
dispatched.

Consequently, an `AppointmentRescheduled` listener is the only way anything
learns the previous interval or previous staff member, and **this slice
persists no appointment history** (§5.4). That is a settled V1 decision,
not an open question: no governing authority requires durable Appointment
history, so `appointment_transitions` is a stated non-goal (§15) and
Sub-slice C proceeds without it. What is not permitted, in this document or
in any implementation of it, is describing these transient events as the
audit trail.

**Dispatch discipline, once per committed mutation**: each event implements
`ShouldDispatchAfterCommit` (the convention every `App\Events\Workspace\*`
class already follows) and is dispatched **after** the transaction that
performed the mutation commits, exactly once, per §7.4's four-step mutation
shape. A refused or rolled-back mutation dispatches nothing.

## 11. Billing/provider safety

**Entitlement**: `PlatformFeature::Calendar` stays `Planned` through
Sub-slices A–D; it is flipped to `Available` only at the end of Sub-slice
E (§12.E), once the full "customer books a slot and both parties see it"
acceptance statement (Acceptance Matrix, "Take bookings") is actually
true end-to-end — mirroring the documented precedent for
`WebsiteGeneration`/`GoogleBusinessProfileModule`/`AiCooBasic`, each only
flipped once real, executable functionality existed behind it.

**External provider safety (Sub-slice F)**: OAuth tokens
encrypted-at-rest (Laravel `encrypted` cast, §5.5). The booking engine's
conflict check (§7) never makes a live external API call inside a booking
transaction — it reads only `external_calendar_busy_blocks`, a local sync
cache. **Failure behavior when Google/Outlook is unavailable**: the sync
job catches provider exceptions, records `last_sync_failure_at`/
`sync_failure_count`/`failure_classification` (mirroring
`business_google_connections`), and leaves existing busy blocks
untouched — stale-but-present data continues to be honored by the
conflict check. This is a deliberate **fail-safe-stale** choice over both
fail-open (silently ignoring the external calendar, risking a real
double-booking) and fail-closed (refusing all bookings for that staff
member whenever a third-party API happens to be down, an availability
regression disproportionate to the failure). The exact
consecutive-failure threshold at which a connection is flagged for
staff re-authorization is an implementation-time decision for Sub-slice F
(§12.F), not invented here without a authoritative source for a specific
number.

**Idempotency**: every sync write (full sync, incremental delta sync, or
webhook-triggered resync) is an upsert keyed by
`(connection_id, provider_event_id)` (§5.6) — processing the same
notification twice is a no-op by construction, not by a separate dedup
ledger. Idempotency alone is **not sufficient**: §5.6's four deletion and
reconciliation rules (delta tombstones; full-sync reconciliation only after
a complete successful paginated read; never wiping the prior good cache on
a failed or partial sync; the cursor written in the same transaction as the
data it describes) are provider-safety requirements of this contract, not
optional refinements. A busy block that outlives its source event is a
permanent false conflict that nobody can see or clear from inside this
product.

**Webhook authenticity is fail-closed, and a webhook is never trusted as
data.** Recon found no reusable verified-webhook middleware or trait to
inherit (§3.5.4); every existing inbound endpoint verifies authenticity in
its own controller, and all of them verify **before** parsing or acting —
Telnyx checks an Ed25519 signature within a bounded timestamp window before
touching the body, Stripe uses `Webhook::constructEvent`, Agency
Prospecting requires an application-key-backed HMAC token in the URL
itself, and the GBP OAuth callback requires a single-use signed state
nonce. Sub-slice F follows that precedent exactly:

- The provider's authenticity proof (Google Calendar's channel token and
  channel/resource identity, Microsoft Graph's `clientState` and validation
  token) is verified **first** — before any parsing, any content-keyed
  database read, and any side effect of any kind.
- A missing, malformed, unverifiable or expired proof is **rejected with no
  side effect and no information disclosure**: no busy block written, no
  sync scheduled, no connection touched, no failure counter attributed to
  the connection a forged request happened to name, and a response that
  does not distinguish "unknown channel" from "bad signature".
- A verified webhook is a **trigger, not a payload**. It never supplies
  event data. It causes an authenticated pull from the provider using that
  connection's own stored credentials, and only that pull's result — via
  §5.6's upsert and deletion rules, under §7.5's lock — may change busy
  blocks. This matches how both providers actually notify (a change signal,
  not the changed data), and it means a forged or replayed notification can
  at worst cause a redundant authenticated re-read.
- Ingestion is per-connection and never lets a request name its own User:
  the connection is resolved from the verified channel identity, and a
  webhook resolving to a disconnected or revoked connection is discarded.

## 12. Exact implementation allowlist — six dependency-ordered sub-slices

Each sub-slice is its own branch/PR, its own explicit human authorization
gate (per this contract's own Status line), and — except where a hard
prerequisite is named — independently reviewable.

### Sub-slice A — Schema/domain foundation

- **Files/domains**: new migrations for **all ten tables** in the roster
  below; Eloquent models (`BookingType`, `BookingTypeRoundRobinState`,
  `StaffAvailabilityRule`, `StaffTimeOff`, `Appointment`,
  `ExternalCalendarConnection`, `ExternalCalendarBusyBlock`,
  `StaffBookingLock`, `BookingContactIdentityLock`) with casts/relations
  only — no services, no controllers, no routes. `booking_type_staff` is an
  ordinary pivot and needs no dedicated model if a `belongsToMany` relation
  covers it; the **table** is still mandatory.
- **Prerequisites**: none beyond Contracts 1–14 (already merged).
- **Schema — the complete Sub-slice A roster, enumerated so none can be
  missed**:

  | # | Table | Defined in |
  |---|---|---|
  | 1 | `booking_types` (including `public_booking_uuid`, §5.1) | §5.1 |
  | 2 | `booking_type_staff` (pivot) | §5.1 |
  | 3 | `booking_type_round_robin_state` | §5.1.1 |
  | 4 | `staff_availability_rules` | §5.2 |
  | 5 | `staff_time_off` | §5.3 |
  | 6 | `appointments` | §5.4 |
  | 7 | `external_calendar_connections` | §5.5 |
  | 8 | `external_calendar_busy_blocks` | §5.6 |
  | 9 | `staff_booking_locks` | §7.2 |
  | 10 | `booking_contact_identity_locks` | §5.8.3 |

  Earlier drafts of this contract said "six tables", which predated
  `booking_type_round_robin_state` (§5.1.1) and
  `booking_contact_identity_locks` (§5.8.3), and never counted the pivot or
  the lock table. **Ten is the number.** An implementation that ships nine of
  these is incomplete; `booking_type_round_robin_state` and
  `booking_contact_identity_locks` are the two most likely to be missed.

- **Identifier generation (§5.1)**: `booking_types.public_booking_uuid` is a
  `Str::uuid()` value assigned in `BookingType::booted()`'s creating hook,
  independent of `HasUid` — and **every** model here that uses `HasUid`
  overrides `generateUid()` with `(string) Str::uuid()`, because the trait's
  default is `uniqid()`.
- **Tenancy/security**: N/A at this layer (no read/write paths exposed
  yet) — but every FK/index from §5/§6 must be present so later sub-slices
  never need a schema-altering migration for an ACL reason.
- **Concurrency**: N/A (no write paths yet); the `staff_booking_locks`
  table itself is created here so Sub-slice C doesn't also need a schema
  change.
- **Tests**: migration-only tests (table/column/constraint existence,
  FK `restrictOnDelete`/`cascadeOnDelete` behavior per §5), model
  factory smoke tests.
- **Risk**: Low — mechanical, fully precedented by §3.3's existing
  patterns.
- **Model**: Sonnet 5 sufficient.

### Sub-slice B — Booking Types + Availability

- **Files/domains**: `BookingTypeManager`/`StaffAvailabilityService`
  (or equivalent) in `app/Library/Calendar/`; authenticated
  controllers/routes for Booking Type CRUD and availability-rule/
  time-off CRUD; Blade views (list/create/edit, no calendar grid yet).
- **Prerequisites**: A (hard).
- **Schema**: none new — consumes A's tables.
- **Tenancy/security**: every controller action calls
  `LocationAccessGuard::assertUserCanAccessLocation()` per §6; the
  "staff may edit only their own availability" rule (§6) enforced here.
  Attaching a staff member to a Booking Type re-derives that candidate's
  eligibility through the same guard (§6) — a `booking_type_staff` row
  records configuration intent, never authorization. **There is nothing
  left to verify about `business_locations.hours`** (§3.5.1, §5.3): it is
  Business Knowledge Profile content with no scheduling consumer anywhere
  in `app/`, and staff availability is this slice's sole booking
  authority.
  **Every route added here is entitlement-gated** through
  `ResolvesBusinessTenancy::resolveEntitledBusinessTenancy(...,
  PlatformFeature::Calendar->value)` (§6), which `abort(404)`s. Because
  Calendar is still `Planned`, these routes are therefore inert until
  Sub-slice E's flip — that is intended, and it is what makes building them
  now safe.
- **Concurrency**: none beyond ordinary single-row CRUD (no
  double-booking surface yet — that's C).
- **Tests**: feature tests per CRUD action × role (owner/admin/staff) ×
  Location-ACL boundary (granted vs ungranted Location → 404, matching
  the existing `LocationAccessDeniedException`/404 convention); the
  availability-authority table of §6 proven row by row (Owner may write a
  currently-eligible staff member's availability; Admin and Staff may write
  only their own; every actor still needs Location authorization; a target
  who is not currently eligible for the Location is refused); and the
  **entitlement gate proven while Planned** — a fully authorized owner with
  a valid Location receives 404 on every route added here (§13 proof 8).
- **Risk**: Low — ordinary Location-scoped CRUD, fully precedented by
  Contract 08B's consumer-wiring pattern (even though 08B itself declined
  Calendar, its wiring pattern for other controllers is the template).
- **Model**: Sonnet 5 sufficient.

### Sub-slice C — Booking engine / concurrency

- **Files/domains**: `AppointmentBookingService` (or equivalent) in
  `app/Library/Calendar/` implementing §7's exact transaction sequence;
  the five events in §10; round-robin staff-assignment logic (Blueprint
  §12) selecting among a Booking Type's eligible, available staff. No
  controllers/UI yet — exercised only via direct service-level tests
  (and, where useful, `php artisan tinker`-style manual verification) in
  this sub-slice.
- **Prerequisites**: A, B (hard — needs Booking Types/Availability to
  determine who's eligible/available before the round-robin runs).
- **Schema**: none new.
- **Tenancy/security**: booking creation still requires
  `LocationAccessGuard`-authorized access to the target Location for
  staff-initiated bookings (public self-booking's own permission model is
  Sub-slice E's concern, since it has no authenticated actor at all).
- **Concurrency**: this sub-slice's entire purpose — §7 in full: the three
  serialization tiers and the single canonical lock order (§7.1, §7.4)
  applied identically to create, reschedule, cancel, complete and no-show;
  the race-free first use of `staff_booking_locks` (§7.2); and durable
  round-robin over `booking_type_round_robin_state` (§5.1.1, §7.3), whose
  cursor advances only on commit. Concurrent-request tests must prove that
  two simultaneous overlapping-booking attempts for the same staff member
  never both succeed, that **two concurrent first-ever bookings for a staff
  member with no lock row yet** still serialize (§7.2), and that a
  reschedule racing a cancel resolves deterministically by lock order —
  mirroring `WorkspaceOwnershipTransferTest`'s own concurrency-test style
  (transaction-boundary, row-lock-order assertions) from the already-merged
  Contract series.
- **Appointment history is settled, and does not gate this sub-slice**: V1
  ships no `appointment_transitions` table (§5.4, §10, §15). The lifecycle
  is implemented without one; what is **not** permitted is implementing it
  while describing the five transient events as the audit trail.
- **Tests**: concurrent-write tests (parallel processes/threads attempting
  overlapping bookings), round-robin distribution correctness, reschedule
  atomicity (interval check runs against the *new* interval only, original
  untouched on failure), **reschedule with a staff move** (old and new
  staff both locked per §7.4, the new staff member's own cross-Location
  overlap re-checked, and `AppointmentRescheduled` carrying
  `previousStaffUserId` ≠ `newStaffUserId`), the same-staff reschedule
  carrying the two ids equal, and event-payload correctness for all five
  lifecycle events.
- **Risk**: **High** — the one hard concurrency invariant in this
  contract; a subtle bug here is a real double-booking in production,
  not a cosmetic defect.
- **Model**: Sonnet 5 is likely sufficient **given it mirrors an
  existing, already-proven locking pattern in this exact codebase**
  (`OpportunityManager::beginRun()`, §7) rather than designing novel
  concurrency control from scratch — but this sub-slice should get a
  mandatory close human/adversarial review of the transaction sequence
  before merge regardless of which model implements it, given the
  consequence of getting it wrong.

### Sub-slice D — Authenticated Business calendar UI

- **Files/domains**: day/week calendar view (confirm and build on the
  already-vendored `fullcalendar.min.js`, §3.3), appointment
  create/reschedule/cancel/complete/no-show actions wired to Sub-slice
  C's service, nav entry via `CustomerMenuBuilder` (§3.3 — add `'calendar'`
  to `ENTITLEMENT_GATED_FEATURES`), a Location picker for Businesses with
  more than one Location (no existing precedent for this UI pattern per
  recon — built fresh here, falling back to the existing
  `singleActiveLocationIdFor()`-style auto-default for the common
  single-Location case).
- **Prerequisites**: A, B, C (hard).
- **Schema**: none new.
- **Tenancy/security**: every view/action re-checks
  `LocationAccessGuard` per §6; the calendar view itself must never
  render an appointment from a Location the viewer isn't authorized for,
  even if linked to directly by id (Addendum §4). **Every route also carries
  the `PlatformFeature::Calendar` entitlement gate** of §6, so the whole
  authenticated calendar stays inert while the feature is `Planned` and
  becomes executable only at Sub-slice E's flip. Adding `'calendar'` to
  `ENTITLEMENT_GATED_FEATURES` hides the nav entry; it is **not** the gate,
  and neither is it optional — omitting it hides the item forever once
  entitled.
- **Concurrency**: none new — consumes C's already-safe service; UI-level
  optimistic-locking/stale-view handling (e.g., a reschedule attempted
  against a slot another request just filled) surfaces C's own refusal
  as a user-facing error, never retried silently in a way that could
  bypass C's checks.
- **Tests**: feature/HTTP tests per view and action × Location-ACL boundary;
  the **entitlement gate proven while Planned** (authorized owner + valid
  Location + Calendar `Planned` → 404 on every calendar route, §13 proof 8);
  and a browser-level smoke pass per this repository's own UI-verification
  convention.
- **Risk**: Medium — UI complexity and the new Location-picker pattern
  are the main novelty; the underlying engine is already proven by C.
- **Model**: Sonnet 5 sufficient.

### Sub-slice E — Public self-booking flow

- **Files/domains**: unauthenticated public controller/routes (a scheduler
  page reachable from the website (§14) and Conversations links (§11), per
  Blueprint §12), routed on `booking_types.public_booking_uuid` with a
  `->whereUuid(...)` constraint and **never** on a `HasUid` `uniqid()` value
  (§5.1, §6 step 1), with the Location derived from the resolved Booking
  Type's own `business_location_id`; Contact find-or-create per §5.8's
  Location-local rule, through the one new narrow seam
  `EloquentContactsRepository::findOrCreateForBooking()` (§5.8.5) — never a
  raw `contacts` write from the controller, and never
  `createContactFromRequest()` unchanged, which cannot satisfy the rule
  (§5.8.5 (a)–(d)); and the `PlatformFeature::Calendar` `Available` flip
  (§11) as this sub-slice's last step, which is what finally makes
  Sub-slices B and D's authenticated surfaces executable (§6).
- **Prerequisites**: A, B, C, **D — all hard**. D is a hard prerequisite,
  not a recommendation: this flow's own acceptance statement is "a customer
  books a slot and **both parties see it**," and until the authenticated
  calendar exists there is no surface on which the Business side of "both
  parties" can be demonstrated, nor any way for staff to see, reschedule or
  cancel what the public has booked. A public write endpoint whose results
  nobody in the Business can view is not an acceptable intermediate state.
  The dependency order is therefore a strict chain: **A → B → C → D → E**.
- **Schema**: none new (reuses `contacts`, `appointments`).
- **Tenancy/security**: no authenticated actor, and therefore **no
  inherited gate** — `routes/public.php` carries only `['web',
  RecordLegacyWebhookUsage]`, and `CustomerAccountAccessGate` no-ops for
  guests. This sub-slice calls §6's six-step public authority stack itself,
  in order, on **both** the page render and the booking write, refusing
  every failure with an indistinguishable 404: active Location → active
  Business + active Workspace →
  `CustomerAccountAccessGuard::decisionForBusiness()` not locked →
  `EntitlementManager::decide(..., PlatformFeature::Calendar->value, (int)
  $business->customer_id)` allowed → Booking Type active and belonging to
  that Location → staff eligibility re-derived through
  `LocationAccessGuard`.
  **Write throttling uses the existing mechanism, not a new one**: the
  public booking write route carries an inline
  `->middleware('throttle:N,1')`, which is this repository's only
  established pattern both for a public unauthenticated endpoint
  (`routes/public.php:40-42`, whose sizing rationale is written into the
  route comment at `:31-39`) and for mutating authenticated actions
  (`throttle:10,1` / `20,1` / `30,1` across `routes/customer.php`). No new
  named `RateLimiter::for()` limiter and no new config key are introduced;
  if the bound must be tunable it is a class constant, following
  `WorkflowLimits::MAX_AUTOSAVES_PER_MINUTE` (`routes/customer.php:926`).
  The chosen number's reasoning is recorded in a route comment in the same
  shape as `routes/public.php:31-39`. CAPTCHA and bot-detection remain out
  of scope (§15) — not because abuse does not matter, but because no
  authoritative document specifies one and the mechanism above already
  exists and is precedented.
- **Concurrency**: consumes C's service as-is for the booking itself — a
  public booking takes the exact same locks in the same order as an
  authenticated one (§7.4). The **one new concurrency surface** is Contact
  identity: `contacts` has no unique index and none can be added (§5.8.1), so
  §5.8.3's `booking_contact_identity_locks` row is ensured-then-locked on
  `(business_location_id, normalized_phone)` following
  `AiUsageLedgerManager::lockOrCreatePeriod()`'s exact probe → `insertOrIgnore`
  → `lockForUpdate()` ordering (§5.8.4), inside the booking transaction, so
  two simultaneous bookings from the same phone at the same Location resolve
  to one Contact and a refused booking leaves no orphan.
- **Tests**: end-to-end public-booking tests (slot shown → booked → both
  Business and customer see it, matching the Acceptance Matrix's own
  acceptance statement verbatim); §6's authority stack proven refusal by
  refusal (archived Location, inactive Business, inactive Workspace,
  locked/inactive/suspended account, Agency-caused lock, unentitled plan,
  Booking Type belonging to another Location, ineligible staff — each a
  404, none distinguishable from another); Location-local Contact dedup per
  §5.8, including the two-Locations-one-person case producing two rows, the
  several-active-Locations case writing the booked `location_id` rather than
  NULL, and **two concurrent bookings from the same phone at the same
  Location producing exactly one Contact** (§5.8.4); a blacklisted number
  refused (§5.8.5); the throttle middleware present on the write route; a
  malformed `public_booking_uuid` rejected by the route constraint before any
  query; and entitlement-flip verification
  (`PlatformFeatureRegistryTest`-style) **paired with proof that the
  previously-404 authenticated routes of B and D now succeed for an
  authorized actor** (§13 proof 8).
- **Risk**: Medium — public/unauthenticated surface raises the stakes of
  any Location-boundary bug beyond what an authenticated UI would.
- **Model**: Sonnet 5 sufficient.

### Sub-slice F — External Google/Outlook calendar integration

- **Files/domains**: OAuth connect/disconnect flow (mirroring
  `business_google_connections`' controller and manager pattern, §3.3/§5.5)
  including §5.5's pending-connection lifecycle — **no persisted access
  token, ever**; `refresh_token_encrypted` only, with the access token
  derived per operation and discarded, exactly as
  `GoogleBusinessProfileConnectionManager::accessTokenFor()` does it. Plus:
  full + incremental sync job (`app/Console/Commands/`, matching this
  codebase's existing command conventions), webhook ingestion endpoints for
  both providers, and wiring `external_calendar_busy_blocks` into C's
  overlap query as the second "busy source" (§7's extensibility point —
  should require no change to C's transaction structure itself).
- **Prerequisites**: A, C (hard — needs the extensible busy-source union
  point C already built); D and E not required, so F may land concurrently
  with either once C is stable. F is the one sub-slice outside the
  A → B → C → D → E chain.
- **Schema**: none new (uses A's `external_calendar_connections`/
  `external_calendar_busy_blocks`).
- **Tenancy/security**: connections are strictly per-User (§6); the connect
  flow must verify the connecting User's own identity, never a
  Workspace-level actor, and the callback must confirm the attempt belongs
  to the acting User before consuming the nonce — the ordering
  `GoogleBusinessProfileController::callback()` already uses. Credentials:
  `refresh_token_encrypted` with Laravel's `encrypted` cast and the
  attribute in `$hidden`; no access-token column exists to leak (§5.5).
- **Concurrency**: sync writes are idempotent upserts **plus** §5.6's
  deletion and reconciliation rules — safe under concurrent polling and
  webhook delivery for the same connection. The earlier claim of "no
  interaction with C's per-staff booking lock" is **withdrawn**: per §7.5
  every local busy-cache mutation for a staff member is applied under that
  staff member's own `staff_booking_locks` row — the same tier-2 lock the
  booking check takes — so a booking can never read a half-applied sync.
  All provider HTTP happens **outside** the transaction and outside the
  lock; only the resulting local write set is applied under it.
- **Tests**: OAuth flow tests (refresh-token storage/encryption, state-nonce
  single-use, and an assertion that **no access token is persisted
  anywhere**); the pending-connection lifecycle of §5.5 — an abandoned or
  expired attempt does not block the User forever, and two live/pending
  connections still cannot coexist; the one-active-connection-per-User
  database guarantee (§5.5), including provider switch and reconnect; sync
  idempotency (same
  delta/webhook applied twice → no duplicate rows); **deletion handling**
  (a provider tombstone removes the busy block; a full sync reconciles away
  a vanished event; a failed or partial sync leaves the prior cache intact;
  a crash before the cursor advances re-applies harmlessly); **webhook
  authenticity fail-closed** (missing, forged, replayed and expired proofs
  each produce no side effect and an indistinguishable response, and no
  verified webhook is ever trusted as data);
  failure-classification/stale-data behavior (§11); conflict-check
  correctness once an external busy block is present; and busy-cache writes
  serializing against a concurrent booking check for the same staff member
  (§7.5).
- **Risk**: **High** — the most architecturally novel sub-slice; no
  existing precedent in this codebase for inbound webhook ingestion or
  delta/sync-token handling (the `business_google_connections` table is
  OAuth-only, with no busy/free sync logic to mirror). Provider-specific
  quirks (token refresh edge cases, clock skew, partial-failure
  semantics, rate limits) carry real risk of a subtle bug silently
  corrupting availability data.
- **Model**: **Opus 5 warranted** — this is the one sub-slice designing
  genuinely novel integration logic from scratch rather than mirroring an
  established in-repo pattern, and a hard-to-detect idempotency or
  clock-handling bug here degrades the double-booking invariant Sub-slice
  C worked to guarantee.

## 13. Required tests

Beyond each sub-slice's own tests (§12): once E ships, a full end-to-end
regression proving the Acceptance Matrix's exact acceptance statements —
"A customer books a slot and both parties see it" (Take bookings) and "A
public booking link produces a confirmed appointment at the correct
Location" (Book an appointment) — plus the existing Workspace/Agency
regression domains (Contract 14's own required five: Workspace, Agency,
Conversations, Contacts, Opportunities) re-run once, after F, to confirm
zero regression in the Location-ACL layer every sub-slice depends on but
none of them are meant to modify.

Seven proofs are required somewhere in the slice, named here so that no
sub-slice can assume another one covered them:

1. **Two concurrent first-ever bookings** for a staff member with no
   `staff_booking_locks` row yet serialize correctly (§7.2) — the race the
   lock table itself introduces, distinct from the ordinary overlap test.
2. **Round-robin survives restarts and concurrency** (§5.1.1, §7.3): the
   cursor is read from and written to `booking_type_round_robin_state`
   under the tier-1 lock, advances only on commit, and starves no eligible
   staff member.
3. **One canonical lock order holds for every lifecycle mutation** (§7.4)
   — create, reschedule, cancel, complete, no-show, plus the external-sync
   write path (§7.5) — proven by a reschedule-versus-cancel race resolving
   deterministically rather than deadlocking.
4. **Staff eligibility is re-derived, never inherited** (§6): a staff
   member whose membership or Location grant is revoked becomes immediately
   unbookable while their `booking_type_staff` and
   `staff_availability_rules` rows remain in place.
5. **The public authority stack refuses every way it can** (§6, §12.E),
   each refusal an indistinguishable 404.
6. **Contact identity is Location-local** (§5.8): one person booking at two
   Locations of one Business yields two Contact rows, and a booking at a
   Business with several active Locations writes the booked `location_id`
   rather than NULL.
7. **External sync deletions and authenticity** (§5.6, §11): tombstone and
   reconciliation removal, no cache wipe on failure, and fail-closed
   webhook verification with no side effect on an unverified request.
8. **A `Planned` feature's authenticated routes are refused server-side**
   (§6): a fully authorized owner, with a valid Location and an entitled
   plan, receives **404** on every Calendar route while
   `PlatformFeature::Calendar` is `Planned` — and the **same** request
   succeeds after Sub-slice E's `Available` flip. This test does not exist
   anywhere in the repository today (no Planned feature currently has a
   route), so it is written fresh, copying the shape of
   `GoogleBusinessProfileEntitlementTest.php:145-190`.
9. **Concurrent Contact identity** (§5.8.3, §5.8.4): two simultaneous public
   bookings from the same phone at the same Location produce exactly **one**
   Contact, with no duplicate-key error and no unserialized second write —
   the same shape as proof 1, against the identity lock rather than the
   staff lock.
10. **The availability-authority table holds** (§6): Owner may write a
    currently-eligible staff member's availability; Admin and Staff may
    write only their own; every actor still needs Location authorization;
    and a target who is not currently eligible for the Location is refused.

## 14. Acceptance criteria

1. Every Booking Type, Appointment and **recurring availability rule**
   belongs to exactly one Location, enforced by a NOT NULL FK (§5), never
   a soft/optional attribution (Addendum §5). **`staff_time_off` is the one
   deliberate exception and carries no Location FK at all** (§5.3): it is
   User-global by design, so time off removes a staff member from every
   Location they are granted at once. Addendum §5's enumeration covers
   "operational staff assignment **where applicable**"; an absence is not
   an assignment, and this contract states the exception rather than
   implying a Location column that does not exist.
2. `LocationAccessGuard` is the only Location-authorization mechanism used
   anywhere in this slice's code (§6) — no parallel ACL logic — and staff
   eligibility is re-derived through it at both configuration time and
   booking time, never read from `booking_type_staff` (§6).
3. Two concurrent overlapping-booking attempts for the same staff member,
   across any combination of Locations, never both succeed (§7), verified
   by an actual concurrent-write test rather than a single-threaded
   simulation — including the first-ever-booking case where the lock row
   does not exist yet (§7.2).
4. Every appointment lifecycle mutation takes the same locks in the same
   order (§7.4), and round-robin assignment is durable across processes
   (§5.1.1, §7.3).
5. The public booking surface consults the account-lifecycle and
   entitlement authorities itself, on both read and write, refusing with a
   404 (§6, §12.E), and its write route carries the existing throttle
   middleware (§12.E).
6. A customer can self-book a slot through the public scheduler and both
   the Business and the customer see the resulting appointment (Acceptance
   Matrix, verbatim) — which is why D precedes E (§12.E).
7. A booking's Contact is identified within the booked Location, never
   across the Business (§5.8, Addendum §5), and two simultaneous bookings
   from one phone at one Location create exactly one Contact (§5.8.4) —
   serialized by a dedicated identity lock, never by an `insertOrIgnore`
   against a unique key that `contacts` does not have (§5.8.1).
8. Every authenticated Calendar route is refused server-side with a 404
   while `PlatformFeature::Calendar` is `Planned`, for an otherwise fully
   authorized actor, and succeeds after the flip (§6, §13 proof 8) — nav
   hiding is never the gate.
9. The public scheduler is addressed only by
   `booking_types.public_booking_uuid`, a `Str::uuid()` value, never by any
   `HasUid` `uniqid()` identifier (§5.1, §6), and holding that identifier
   authorizes nothing.
10. No access token is persisted anywhere by this slice; only
    `refresh_token_encrypted` is stored, encrypted at rest (§5.5), and an
    abandoned OAuth attempt never permanently consumes the User's one
    connection slot (§5.5).
11. `PlatformFeature::Calendar` is flipped to `Available` only after that
    full flow is true end-to-end (§11).
12. External calendar sync never blocks or breaks internal booking when the
    provider API is unavailable (§11's fail-safe-stale behavior, verified by
    a test that simulates a provider outage), applies deletions and
    reconciliation (§5.6), and rejects unverified webhooks with no side
    effect (§11).
13. No text in this slice — contract, code comment or commit message —
    describes a transient domain event as an audit trail (§10), and no
    `appointment_transitions` table is created (§5.4, §15): the previous
    interval and previous staff member of a reschedule are knowingly not
    durably queryable in V1.
14. `staff_booking_locks` never blocks a User deletion (§7.2), and the
    availability-authority table of §6 is enforced exactly as written.
15. `git diff --check` clean and a clean working tree at the end of each
    sub-slice's own commit.

## 15. Non-goals

- **Buffer-before/buffer-after handling** — zero mentions anywhere in
  `docs/product/`; not authorized, not built.
- **Booking capacity / group bookings** — zero mentions in the
  authoritative docs; every Booking Type is single-booking-per-slot only.
- **Zoom or any video-conferencing integration** — zero mentions anywhere
  in `docs/product/`.
- **A new Location-closure/holiday-hours concept** — not built, and no
  longer deferred pending a verification: `business_locations.hours` is
  settled as Business Knowledge Profile content with no scheduling consumer
  anywhere in `app/` (§3.5.1, §5.3), and staff availability is this slice's
  booking authority. Making published hours bound bookings is a future
  product decision needing its own authority, not an unresolved question
  inside this contract.
- **Migrating or touching `AgencyProspect.booked_at`/`AgencyProspectStatus::
  Booked`** — a separate, unrelated domain (§3.4.2); explicitly untouched.
- **Computing or persisting a "Booked" badge/state on `CrmOpportunity`**
  — CRM-domain work that should consume this slice's events, not be
  built as part of it (§5.7).
- **A finer-grained "staff can only manage their own appointments" ACL
  axis** — the existing role-blind, scope-based `LocationAccessGuard`
  model is reused as-is (§6); not extended with a new per-appointment-
  owner permission layer absent from every other domain in this
  codebase.
- **CAPTCHA, bot-detection, or any *new* rate-limiting mechanism** — no
  authoritative document specifies one, and none is invented. This is a
  non-goal in the sense of *new mechanism only*: the public booking write
  route does carry the existing `throttle:` middleware this repository
  already uses for its one public unauthenticated endpoint and for every
  mutating authenticated action (§3.5.3, §12.E). What is excluded is a new
  named limiter, a new config surface, and any human-verification
  challenge.
- **An `appointment_transitions` history table, or any durable Appointment
  history** — **settled as a V1 non-goal**, because no governing Slice 15
  authority requires it (§5.4, §10). The `appointments` row carries current
  state plus `reschedule_count`; the five domain events are transient
  integration/automation events only; the previous interval and previous
  staff member of a reschedule are knowingly not durably queryable after
  the event is gone. Adding durable Appointment history later is separate
  product scope. This does not gate Sub-slice C.
- **A unique index on `contacts`, in any column combination** — mechanically
  unsafe to add retroactively (§5.8.1): existing, intended behaviour already
  produces duplicate `(location_id, phone)` rows, five tests assert that
  duplication is legitimate, and MySQL's NULL semantics would exempt exactly
  the multi-Location rows that matter. Location-local identity is enforced by
  §5.8.3's serialization row instead.
- **Fixing the codebase's phone normalization** — Calendar deliberately keys
  on the *existing* stored form (§5.8.2) so it matches existing rows.
  Introducing E.164 normalization for `contacts` would be a repo-wide data
  migration touching six writers and several read paths; it is not in scope,
  and Calendar must not half-introduce it.
- **Merging, rewriting or deleting duplicate Contacts** — §5.8.4 picks the
  oldest deterministically and leaves every other row untouched.
- **Persisting an OAuth access token** — never authorized here (§5.5); only
  `refresh_token_encrypted` is stored.
- **Reopening or modifying the Workspace/Agency tenancy migration**
  (Contracts 1–14) in any way.

## 16. Merge prerequisites

None hard at the whole-slice level — Slice 15 is explicitly independent
of Slices 1–14 per the Roadmap, beyond those already being merged (they
are, as of `3dbb1e11`). Per-sub-slice hard prerequisites are stated in each
block of §12: **A → B → C → D → E is a strict chain**, every link hard —
including D before E (§12.E) — and **F requires A and C only**, so F may
land concurrently with D or E once C is stable.

## 17. Conflict map

| Other work | Shared file/table | Posture |
|---|---|---|
| Slice 16 (Packages & Products) | none identified — Roadmap explicitly notes 15–18 "do not touch `WorkspaceManager`, `CustomerAccountAccessResolver`, `EntitlementManager`, or the Agency relationship table" | Parallel-safe |
| Slice 17 (Proposal/Contract/e-signature) | none identified in this recon pass; Slice 17 depends on Slice 16, not on 15 | Parallel-safe |
| Any future CRM-domain slice building the "Booked" badge (§5.7, §15) | `crm_opportunities` (read of `appointments`' new events, not of Calendar's own tables) | Serialize only in the sense that it must land after this slice's events exist to consume — not a file conflict |
| `CustomerMenuBuilder.php` (`ENTITLEMENT_GATED_FEATURES`, §12.D) | additive line, same low-conflict shape as Contract 01's `AppServiceProvider.php` edit | Low risk, mergeable alongside any other slice touching the same file via ordinary merge, not a hard serialization |

## 18. Implementation prompts

Each sub-slice is handed to a fresh session independently, once explicitly
authorized. Every prompt below assumes Contracts 1–14 and every
lower-lettered Sub-slice already merged to `main`.

### 18.A — Schema/domain foundation

```
You are implementing Sub-slice A of Slice 15 (Calendar / Booking Types /
Availability) for the os-creator1/os-ai repository, per docs/product/
implementation-contracts/15-CALENDAR-BOOKING-AVAILABILITY.md SS5 and
SS12.A. This is schema/model only -- no services, controllers, routes, or
UI.

Before writing code:
1. Fetch latest origin/main and verify it is at or after 3dbb1e11.
2. Re-read SS5 (Canonical domain model) and SS12.A in full.
3. Create a fresh worktree/branch for this sub-slice only (e.g.
   agent/v1-slice15a-calendar-schema).

Implement exactly TEN tables as migrations. Count them before you start and
count them again before you commit -- an earlier draft of this contract said
"six", and that number is wrong:

   1. booking_types            (SS5.1) -- INCLUDING public_booking_uuid
   2. booking_type_staff       (SS5.1, pivot)
   3. booking_type_round_robin_state (SS5.1.1) -- often missed
   4. staff_availability_rules (SS5.2)
   5. staff_time_off           (SS5.3)
   6. appointments             (SS5.4)
   7. external_calendar_connections (SS5.5)
   8. external_calendar_busy_blocks (SS5.6)
   9. staff_booking_locks      (SS7.2)
  10. booking_contact_identity_locks (SS5.8.3) -- often missed

Plus their Eloquent models with casts/relations only (no business logic):
BookingType, BookingTypeRoundRobinState, StaffAvailabilityRule,
StaffTimeOff, Appointment, ExternalCalendarConnection,
ExternalCalendarBusyBlock, StaffBookingLock, BookingContactIdentityLock.
booking_type_staff needs no dedicated model if a belongsToMany relation
covers it, but the TABLE is mandatory.

IDENTIFIERS -- get this right or Sub-slice E cannot be built:
- booking_types.public_booking_uuid is uuid, NOT NULL, UNIQUE, generated as
  (string) Str::uuid() in BookingType::booted()'s creating hook -- copy
  app/Models/Website.php:60-67 exactly. It is a SEPARATE column from uid and
  must NOT come from HasUid.
- EVERY model here that uses HasUid must override generateUid() with
  `$this->uid = (string) Str::uuid();`. HasUid's default is uniqid()
  (app/Library/Traits/HasUid.php:27-30), which is guessable -- 20 existing
  models already override it for this reason.

FK POSTURE -- four of these are deliberately NOT restrictOnDelete:
- staff_booking_locks.staff_user_id is cascadeOnDelete (SS7.2). It is pure
  serialization infrastructure and must never make a User undeletable; this
  repository hard-deletes Users and has no SoftDeletes on the users table.
- booking_contact_identity_locks.business_location_id is cascadeOnDelete
  (SS5.8.3), same reasoning -- purely technical lock infrastructure, not an
  audit-relevant Location record.
- booking_type_staff.staff_user_id is cascadeOnDelete (SS5.1). It is
  configuration intent only, never authorization, and carries no
  independent audit value once the staff member is gone.
- external_calendar_connections.user_id is cascadeOnDelete (SS5.5). It holds
  operational OAuth state (an encrypted refresh token), not an
  independently audit-relevant record; deleting a User cascades its
  connection, and that connection's external_calendar_busy_blocks rows
  cascade through it in turn (SS5.6) -- no credential or synced cache is
  ever orphaned.
- Everything else keeps the posture SS5 states -- including
  appointments.staff_user_id, staff_availability_rules.staff_user_id and
  staff_time_off.staff_user_id, which remain restrictOnDelete because those
  rows ARE historically meaningful. Do not "harmonize" them. Follow this repository's existing conventions
precisely: HasUid trait where noted, restrictOnDelete vs cascadeOnDelete
exactly as SS5 specifies (never the other way, even if it seems
equivalent -- the contract's own reasoning for each choice is in SS5/SS7),
encrypted casts for OAuth token columns (SS5.5).

Do not write any controller, route, service class, or view in this
sub-slice -- that is explicitly out of scope (SS12.A).

Tests: migration/constraint existence tests, model factory smoke tests
(SS12.A "Tests").

After implementing: run the new tests, run `git diff --check`, commit,
and push to the fresh branch. Do NOT create a pull request. Do NOT merge.
Return: starting/final SHA, exact files created, exact tests run and
counts, confirmation every FK/constraint matches SS5 exactly (quote any
deviation and why, if you found one necessary).
```

### 18.B — Booking Types + Availability

```
You are implementing Sub-slice B of Slice 15, per docs/product/
implementation-contracts/15-CALENDAR-BOOKING-AVAILABILITY.md SS12.B.
Hard prerequisite: Sub-slice A merged.

Do NOT re-open business_locations.hours. It is settled (SS3.5.1, SS5.3):
it is Business Knowledge Profile content and provenance, written only by
BusinessKnowledgeProfileManager::updateLocationHours() and read only by
profile-freshness computation and two Blade views. Nothing in app/ consumes
it for scheduling, and no document makes it a scheduling authority.
staff_availability_rules + staff_time_off are this slice's only booking
authority. Do not make Availability respect it, and do not invent a closure
concept.

Attaching a staff member to a Booking Type must re-derive that staff
member's eligibility through LocationAccessGuard::userCanAccessLocation()
(SS6) -- a booking_type_staff row records configuration intent, never
authorization.

Implement Booking Type CRUD and availability-rule/time-off CRUD, each action
authorizing via LocationAccessGuard::assertUserCanAccessLocation() (SS6) --
never a new ACL algorithm.

EVERY route you add must ALSO carry the Calendar entitlement gate (SS6):
resolveEntitledBusinessTenancy($workspaceUid, $businessUid,
PlatformFeature::Calendar->value) from
app/Http/Controllers/Customer/Business/Concerns/ResolvesBusinessTenancy.php
-- the same trait Website generation, GBP, Automations and CRM already use.
It abort(404)s. Note the argument is a RAW STRING, so pass ->value. There is
no feature middleware in this repository; do not invent one.

Because PlatformFeature::Calendar is still Planned, EVERY route you build
here will correctly return 404 until Sub-slice E flips it. That is the
intended end state of this sub-slice, NOT a bug -- do not "fix" it, and do
NOT flip the availability flag early.

Availability authority is SETTLED (SS6) -- implement the table exactly, and
do not stop to ask: the Workspace/Business Owner may manage availability for
ANY staff member currently eligible for the target Location; an Admin may
edit only their OWN availability in V1; Staff may edit only their own. Every
actor still needs Location authorization for their path, and the TARGET staff
member's eligibility is re-derived through LocationAccessGuard at write time.
staff_time_off is User-global, so the write surface must tell the actor that
it removes that person from every Location, not just this one.

Tests per SS12.B and SS13: CRUD x role (owner/admin/staff) x Location-ACL
boundary (granted vs ungranted -> 404); the availability-authority table
proven row by row; and the entitlement gate proven while Planned -- a fully
authorized owner with a valid Location gets 404 on every route you added
(SS13 proof 8). No such test exists in the repository today; copy the shape
of tests/Feature/GoogleBusinessProfile/GoogleBusinessProfileEntitlementTest.php:145-190.

After implementing: run tests, git diff --check, commit, push to a fresh
branch off A's merged state. Do NOT create a PR. Do NOT merge. Return:
SHA, files, and test counts.
```

### 18.C — Booking engine / concurrency

```
You are implementing Sub-slice C of Slice 15, per docs/product/
implementation-contracts/15-CALENDAR-BOOKING-AVAILABILITY.md SS7 and
SS12.C -- the highest-risk sub-slice in this contract. Hard prerequisites:
Sub-slices A and B merged.

Re-read SS7 in full -- SS7.1 through SS7.6 -- before writing any code.
Implement exactly what it specifies, not a simplification of it:

- The ONE canonical lock order (SS7.4), applied identically to create,
  reschedule, cancel, complete and no-show: the round-robin state row
  first, then staff_booking_locks rows ascending by staff_user_id, then
  appointments ascending by id. Never a different order for a different
  mutation, even when only one tier is needed.
- Race-free first use of staff_booking_locks (SS7.2): insertOrIgnore
  outside the transaction, SELECT ... FOR UPDATE inside it, exactly one
  re-ensure, then fail closed. Two concurrent first-ever bookings for the
  same staff member must serialize -- write that test.
- Durable round-robin (SS5.1.1, SS7.3) over
  booking_type_round_robin_state. The cursor is persisted, advances only on
  commit, and is never held in memory, in a cache, or inferred by counting
  historical appointments.
- The overlap query against scheduled appointments across ALL Locations for
  that staff member, unioned with external busy blocks through an
  extensible source list (SS7.6), even though no external source exists
  until Sub-slice F. Refuse on any overlap; otherwise write and commit.
  Reschedule runs the identical sequence against the new interval under the
  same lock.
- Staff eligibility re-derived for every candidate through
  LocationAccessGuard::userCanAccessLocation() inside the transaction
  (SS6). A booking_type_staff row is configuration intent, never
  authorization.
- The four-step mutation shape of SS7.4: re-read status under the lock,
  validate the source state, perform the write, dispatch exactly once after
  commit.

Dispatch the five events in SS10 with exactly the payloads listed, after
commit -- including AppointmentRescheduled's previousStaffUserId and
newStaffUserId, which are REQUIRED because SS7.4 supports reschedule with
a staff move. Do NOT describe or document these events as an audit trail:
SS10 and SS5.4 are explicit that they are not one, and that the previous
interval and previous staff member of a reschedule are recoverable from
nothing this slice persists. Do NOT add an appointment_transitions table --
V1 ships without one by decision (SS5.4, SS15), and this question does not
gate your work. Do not stop to ask about it.

This sub-slice is service-level only -- no controller/UI required to
exercise it; write direct service tests.

Tests per SS12.C and SS13, especially: an actual concurrent-write test
(parallel attempts at overlapping bookings for the same staff member must
never both succeed), the first-use lock race of SS7.2 (two concurrent
first-ever bookings, no lock row yet), round-robin durability across
processes with no starvation, reschedule atomicity, reschedule-versus-
cancel resolving deterministically by lock order, a revoked-membership
staff member becoming immediately ineligible while their configuration
rows remain, and event payload correctness.

After implementing, this sub-slice's transaction design should be treated
as needing a close review before merge given the contract's own risk
rating -- flag anything in your own implementation you are not fully
confident is race-free, rather than asserting confidence you don't have.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT
create a PR. Do NOT merge. Return: SHA, files, exact concurrency test
results, and an explicit statement of any residual race condition you
were unable to fully close, if any.
```

### 18.D — Authenticated Business calendar UI

```
You are implementing Sub-slice D of Slice 15, per docs/product/
implementation-contracts/15-CALENDAR-BOOKING-AVAILABILITY.md SS12.D. Hard
prerequisites: Sub-slices A, B, C merged.

First confirm public/vendors/js/calendar/fullcalendar.min.js is still
present and suitable before building on it (SS3.3) -- do not assume.

Build the day/week calendar view and appointment create/reschedule/
cancel/complete/no-show actions wired to Sub-slice C's service (never
re-implement its logic). Add the nav entry via CustomerMenuBuilder,
including adding 'calendar' to ENTITLEMENT_GATED_FEATURES (SS3.3 --
omitting this silently hides the item forever). Build a Location picker
for multi-Location Businesses; fall back to the existing
singleActiveLocationIdFor()-style auto-default for the single-Location
case. Every view/action re-checks LocationAccessGuard per SS6.

Adding 'calendar' to ENTITLEMENT_GATED_FEATURES hides the NAV ENTRY. It is
NOT a gate: recon confirmed nav hiding is purely cosmetic and leaves every
route registered and reachable by direct URL. So every route and action you
add here must ALSO carry the Calendar entitlement gate of SS6
(resolveEntitledBusinessTenancy(..., PlatformFeature::Calendar->value),
abort(404)). Calendar is still Planned, so your whole calendar will correctly
404 until Sub-slice E flips it -- that is the intended end state, and you must
NOT flip the availability flag early to make your own browser check pass.
Verify the UI by temporarily entitling in a TEST, never by changing the
registry.

The AppointmentRescheduled event carries previousStaffUserId AND
newStaffUserId (SS10); if your UI supports moving an appointment to another
staff member, it goes through Sub-slice C's service unchanged and both ids
differ.

Tests per SS12.D. Verify in a real browser preview per this session's own
UI-verification workflow before reporting complete.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT
create a PR. Do NOT merge. Return: SHA, files, test counts, and
screenshots/description of the browser verification performed.
```

### 18.E — Public self-booking flow

```
You are implementing Sub-slice E of Slice 15, per docs/product/
implementation-contracts/15-CALENDAR-BOOKING-AVAILABILITY.md SS12.E. Hard
prerequisites: Sub-slices A, B, C and D ALL merged. D is hard, not
recommended (SS12.E): this sub-slice's acceptance statement is "both
parties see it," and without the authenticated calendar there is no
Business-side surface on which that can be true.

Route the scheduler on booking_types.public_booking_uuid with a
->whereUuid(...) route constraint (SS5.1), and derive the Location from the
resolved Booking Type's own business_location_id. NEVER route on Business.uid
or BusinessLocation.uid -- HasUid generates those with uniqid(), and this
repository already rejected that shape for public addressing
(routes/public.php:210-215). Holding the uuid authorizes NOTHING; every check
below still runs.

Contact identity is SETTLED and Location-local (SS5.8, Addendum SS5) -- do
not re-derive it, and do not use the earlier draft's "phone/email within the
Business" phrasing. Identify the Contact by normalized phone WITHIN THE
BOOKED LOCATION. The same person booking at two Locations of one Business is
two Contact rows, and that is correct. Specifics you must not improvise:

- NORMALIZATION (SS5.8.2): use trim(str_replace(['+','-','(',')',' '], '',
  $raw)) -- the form contacts.phone actually stores. Do NOT use
  E164Normalizer or AgencyProspectPhoneNormalizer: they are better
  normalizers, but their output does not match the stored column, so they
  would silently create duplicates.
- CONCURRENCY (SS5.8.3, SS5.8.4): contacts has NO unique index and one CANNOT
  be added (SS5.8.1), so insertOrIgnore on contacts guarantees NOTHING.
  Serialize on the new booking_contact_identity_locks row keyed
  (business_location_id, normalized_phone), following
  AiUsageLedgerManager::lockOrCreatePeriod()
  (app/Library/Ai/AiUsageLedgerManager.php:441-474) EXACTLY, including its
  ordering: unlocked existence probe, then insertOrIgnore, then
  lockForUpdate(). The probe-first order is not stylistic -- that docblock
  explains the InnoDB gap-lock deadlock it avoids. CAUTION (found in the
  Sub-slice C concurrency review): run that probe and the insertOrIgnore
  OUTSIDE the transaction (as StaffBookingLockManager::ensure() does), never
  as plain reads inside it -- an in-transaction plain SELECT pins the
  REPEATABLE READ snapshot before the lock wait ends and hides rows the
  previous lock holder committed.
- RESOLUTION (SS5.8.4): under the lock, query contacts by location_id +
  normalized phone, orderBy('id')->first(). Several legitimate matches can
  exist; take the OLDEST deterministically. Do not merge, rewrite or delete
  the others, and never attach a Contact from a sibling Location.
- SEAM (SS5.8.5): createContactFromRequest() CANNOT be reused unchanged --
  its match is group-scoped, it sets location_id only via
  singleActiveLocationIdFor() (NULL for any multi-Location Business), its
  Rule::unique checks the raw string while firstOrNew matches the stripped
  one, and it sends the group's welcome SMS through a parse that can throw
  uncaught. Add ONE narrow method on the existing EloquentContactsRepository,
  findOrCreateForBooking(...), reusing the existing blacklist check
  (Contacts::isListedInBlacklist()), the existing custom-field writer
  (Contacts::updateFields()) and the existing contact-created automation
  dispatches. Do NOT send the welcome/signup SMS. Do NOT write the contacts
  table from the controller. Write contacts.location_id explicitly to the
  booked Location.
- GROUP: contact_groups has business_id but NO location_id, so the group is
  never the identity key. Resolve the Business's group deterministically
  (oldest by id) and, if it has none, create one through the existing
  EloquentContactsRepository::store() seam -- the same path
  ContactDirectoryController::createFirstList() uses.
- There is no email column on contacts, so there is no email dedup.

Build the unauthenticated public scheduler flow (page + booking action),
reusing Sub-slice C's service unchanged. It inherits NO gate: routes/
public.php carries only ['web', RecordLegacyWebhookUsage], and
CustomerAccountAccessGate no-ops for guests. Implement SS6's six-step
public authority stack yourself, in order, on BOTH the page render and the
booking write, every refusal an indistinguishable 404: active Location ->
active Business + active Workspace ->
CustomerAccountAccessGuard::decisionForBusiness() not locked ->
EntitlementManager::decide($workspace, $business,
PlatformFeature::Calendar->value, (int) $business->customer_id) allowed ->
Booking Type active and belonging to that Location -> staff eligibility
re-derived through LocationAccessGuard. Never call Auth::id() and never
fabricate an actor. Do not resolve the Location from Business.uid or
BusinessLocation.uid -- HasUid generates those with uniqid(), and this
repository already rejected them as public identifiers
(routes/public.php:210-215).

Throttle the booking write route with the EXISTING mechanism: an inline
->middleware('throttle:N,1') on the route, sized like this repository's
existing mutating-action limits, with the reasoning written into a route
comment in the same shape as routes/public.php:31-39. Do NOT add a named
RateLimiter::for() limiter, do NOT add a config key, and do NOT build a
CAPTCHA or bot-detection mechanism (SS15).

As the last step of this sub-slice, flip PlatformFeature::Calendar from
Planned to Available (SS11), mirroring the exact precedent already used
for WebsiteGeneration/GoogleBusinessProfileModule/AiCooBasic -- only after
verifying the full "customer books a slot, both parties see it" flow
actually works end-to-end.

Tests per SS12.E and SS13, matching the Acceptance Matrix's own acceptance
statements verbatim (SS14 items 6 and 8), and including every refusal in SS6's
authority stack proven separately as a 404, the Location-local Contact
dedup cases of SS5.8, and the presence of the throttle middleware on the
write route.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT
create a PR. Do NOT merge. Return: SHA, files, test counts, and explicit
confirmation of the entitlement flip plus the exact test proving it's
now safe to flip.
```

### 18.F — External Google/Outlook calendar integration

```
You are implementing Sub-slice F of Slice 15, per docs/product/
implementation-contracts/15-CALENDAR-BOOKING-AVAILABILITY.md SS12.F --
the most architecturally novel sub-slice in this contract (no existing
in-repo precedent for inbound webhook/delta sync). Hard prerequisites:
Sub-slices A and C merged.

Re-read SS5.5, SS5.6, SS7.5 and SS11's failure-behavior requirements in
full before writing code. Mirror business_google_connections' OAuth pattern
(SS3.3) for connect/disconnect, but per-User as SS5.5 specifies. The
cardinality question is CLOSED: exactly ONE active external calendar
connection per User in TOTAL, not one per provider, enforced by the stored
generated column plus unique index of SS5.5 -- the same conditional-
uniqueness pattern Contract 01 already proved in this repository. Blueprint
SS12 ("Each staff member connects their own Google OR Outlook calendar
once, globally to their User identity") is the only authoritative statement
on this, and nothing anywhere authorizes two concurrent providers.
Implement the three-step provider switch/reconnect sequence of SS5.5 exactly,
including deleting that connection's busy blocks in the same transaction.

CREDENTIAL STORAGE IS ALSO CLOSED (SS5.5). Store refresh_token_encrypted
ONLY -- text, nullable, Laravel's `encrypted` cast, attribute in $hidden.
There is NO access_token column and you must not add one: derive an access
token in memory per provider operation from the refresh token, use it,
discard it. Copy GoogleBusinessProfileConnectionManager::accessTokenFor()
(app/Library/GoogleBusinessProfile/GoogleBusinessProfileConnectionManager.php:266-291),
whose only DB write is last_refreshed_at/failure_classification. An earlier
draft of this contract listed an access_token column; it was wrong and is
withdrawn. If a provider MECHANICALLY requires different persistent
credentials, verify that against the provider's current documentation and
REPORT it -- do not assume it, and never store a plaintext credential.

Implement SS5.5's pending-connection lifecycle exactly: initiation creates
one pending row holding the User's single slot; a second simultaneous
initiation is refused; a successful callback transitions pending -> active
under lock_version; a callback with no refresh token fails closed and leaves
the row pending; and an EXPIRED pending row is transitioned to a terminal
disconnected state (clearing the nonce, releasing active_user_id) before the
new attempt is inserted, in one transaction. Test that an abandoned/expired
attempt does not block the User forever, and that two live/pending
connections still cannot coexist.

Implement full + incremental sync (a console command, matching this repo's
existing command conventions) and webhook ingestion for both providers,
writing to external_calendar_busy_blocks via idempotent upsert keyed by
(connection_id, provider_event_id) -- verify with an actual test that
processing the same delta/webhook twice produces no duplicate rows. Upsert
alone is NOT sufficient: implement all four deletion and reconciliation
rules of SS5.6 (delta tombstones; full-sync reconciliation only after a
complete successful paginated read; never wiping the prior good cache on a
failed or partial sync; the cursor written in the same transaction as the
data it describes), and test each one.

Webhook authenticity is fail-closed (SS11). There is no reusable verified-
webhook middleware or trait in this repository to inherit -- every existing
endpoint verifies in its own controller, before parsing anything. Verify
the provider's proof FIRST, before any parsing, any content-keyed read and
any side effect. Reject a missing, malformed, unverifiable or expired proof
with NO side effect and NO information disclosure, and with a response that
does not distinguish an unknown channel from a bad signature. Treat a
verified webhook as a TRIGGER, never as data: it causes an authenticated
pull using that connection's own stored credentials, and only that pull's
result may change busy blocks. Note that no Microsoft/Outlook/Graph code
exists in this repository at all -- you are writing that provider from
scratch.

Wire the busy-block table into Sub-slice C's overlap query as an additional
source WITHOUT modifying C's transaction/locking structure -- if you find
you need to change that structure, STOP and report why, since SS7 was
explicitly designed to make this sub-slice additive. Local busy-cache
writes DO share C's per-staff lock (SS7.5): apply the resulting write set
under that staff member's staff_booking_locks row, with all provider HTTP
outside the transaction and outside the lock.

Implement SS11's fail-safe-stale behavior exactly: a provider outage
during sync must never block or fail an internal booking; verify this
with a test that simulates the provider being unavailable.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT create
a PR. Do NOT merge. Return: SHA, files, exact idempotency test results,
exact deletion/reconciliation test results, exact webhook-authenticity
refusal test results, exact outage-simulation test results, and
confirmation that Sub-slice C's transaction structure was not modified (or,
if it was, exactly why).
```
