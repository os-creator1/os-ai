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

All new tables use `restrictOnDelete` foreign keys to `business_locations`
(never cascade — Location attribution is audit-relevant, per the
`contacts`/`chat_boxes` precedent), all use the existing `HasUid` trait
convention for any row an external URL or API response references, and all
store date/times as UTC timestamps with timezone-aware math done at read
time via `business.timezone` (§3.3), never a stored offset.

### 5.1 `booking_types`

```
id
uid                          uuid, unique
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

`booking_type_staff` (pivot — which staff offer this Booking Type, the
round-robin pool):

```
id
booking_type_id              FK -> booking_types, cascadeOnDelete
staff_user_id                FK -> users, restrictOnDelete
timestamps

unique (booking_type_id, staff_user_id)
index (staff_user_id)
```

`cascadeOnDelete` on `booking_type_id` here (not the Location FK) because
this pivot's only meaning is "this Booking Type currently offers this
staff member" — it has no independent audit value once the Booking Type
itself is gone, unlike the Location FKs elsewhere in this schema.

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
otherwise granted, not just one. **Location-wide closures are explicitly
out of this schema** — `business_locations.hours`/`hours_source` already
exists (migration `2026_09_09_...`) for Location operating hours; whether
it already drives an open/closed check anywhere is not yet confirmed by
this recon pass and must be verified at Sub-slice B implementation time
(§12.B) before deciding whether the booking engine treats it as an outer
bound on staff windows or whether a dedicated closure concept is still
needed. This contract does not invent a new closure table without that
verification.

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
§5.5's boundary rule. No dedicated reschedule-history table: each
reschedule fires an `AppointmentRescheduled` event carrying the old and
new `start_at`/`end_at` (§10), which is this slice's audit mechanism,
consistent with how `WorkspaceOwnershipTransferTest`'s own domain (Contract
04) relies on event-based audit rather than a bespoke history table for an
analogous "who changed what, when" need — a dedicated history table is not
added without a proven read need for it (`CLAUDE.md`: "every table must
have a purpose").

### 5.5 `external_calendar_connections` (per-User, per-provider)

```
id
uid                            uuid, unique
user_id                        FK -> users, restrictOnDelete, NOT NULL
provider                       string(16): google | outlook
external_account_email          string(255)
access_token                   text, encrypted cast
refresh_token                  text, encrypted cast
token_expires_at                timestamp, nullable
granted_scopes                  json
sync_cursor                     string(255), nullable (provider's own delta/sync token)
last_synced_at                  timestamp, nullable
last_sync_failure_at            timestamp, nullable
sync_failure_count               unsignedInteger, default 0
failure_classification           string(64), nullable
oauth_state_nonce                string(64), nullable
oauth_state_expires_at           timestamp, nullable
connected_at                    timestamp, nullable
disconnected_at                 timestamp, nullable
revoked_at                       timestamp, nullable
lock_version                    unsignedInteger, default 0

unique (user_id, provider)
```

Mirrors `business_google_connections` field-for-field (§3.3), keyed to
`user_id` instead of `business_id` per Blueprint §12's explicit "globally
to their User identity — not once per Workspace." **Resolved ambiguity,
flagged explicitly**: Blueprint says a staff member "connects their own
Google or Outlook calendar **once**" — this is read as "once per provider,
not once per Workspace" (the sentence's own contrast), not "only one
provider connection, ever." `unique(user_id, provider)` therefore permits
a User to hold both a Google **and** an Outlook connection simultaneously.
If the intent was stricter (exactly one connection of either provider,
total), that is a one-line constraint change at Sub-slice F's
implementation time — flagged here rather than silently assumed.

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
every sync — full, delta, or webhook-triggered — is a plain upsert keyed
by the provider's own event id, so processing the same notification twice
is a no-op, never a duplicate or a double-counted busy interval. **No
provider-specific column ever appears on `appointments`** — this table is
consulted only as one additional read-only source unioned into the
conflict-check query (§7); the canonical Appointment record's authority
never depends on it being present, current, or even ever having existed.

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
appointment at it. The one genuine new distinction Blueprint §12 itself
draws ("staff availability is set **per staff member**") is: a Staff/Admin
member may create/edit `staff_availability_rules`/`staff_time_off` rows
only for **themselves**; an Owner/Admin acting on another staff member's
availability (e.g. onboarding) is a deliberate escalation this slice does
not authorize without further explicit product direction — flagged as an
open question for the human before Sub-slice B ships, not silently
resolved either way.

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
`staff_user_id`, created lazily on first use), not the `users` row
itself** — locking `users` directly would create contention with every
unrelated concurrent write to that table (profile edits, auth, etc.);
mirroring `OpportunityManager`'s *technique* without reusing its *exact
locked table* (which was safe there because `businesses` has no comparable
unrelated write pressure inside a booking transaction).

Sequence, inside one transaction:

1. `SELECT ... FOR UPDATE` on the `staff_booking_locks` row for
   `staff_user_id` (spanning **all** Locations that staff member could be
   booked at — this is what makes cross-Location conflict prevention
   structural rather than a per-Location check, per Blueprint §12).
2. Query for any existing `status = 'scheduled'` appointment for that
   `staff_user_id` whose `[start_at, end_at)` interval overlaps the
   candidate interval — across every Location.
3. Union in any `external_calendar_busy_blocks` row for that staff
   member's connection(s) overlapping the same interval (§5.6) — advisory,
   additive, never authoritative on its own; if none exists or sync is
   stale, this union contributes nothing and internal-appointment checking
   alone remains fully authoritative (§11's fail-safe-stale rule).
4. If any overlap is found, refuse (raise a domain exception; no partial
   write).
5. Otherwise insert (or, for reschedule, update `start_at`/`end_at` in
   place) and commit.

**Reschedule** runs the identical 1–5 sequence against the *new* interval,
under the same lock, in the same transaction as updating the existing row
— so a reschedule is atomically all-or-nothing: either the new interval
clears every check the way a fresh booking would, or the original
appointment is left completely untouched.

The overlap query (step 2/3) is written as an **extensible union of "busy
sources"** from the start, so Sub-slice F (external calendar) is additive
— it registers `external_calendar_busy_blocks` as a second source without
touching the locking/transaction structure Sub-slice C already shipped and
tested using only internal appointments.

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
- `AppointmentRescheduled` — `appointmentId, staffUserId, previousStartAt, previousEndAt, newStartAt, newEndAt, rescheduledByUserId (nullable)`
- `AppointmentCancelled` — `appointmentId, staffUserId, cancelledByUserId (nullable), reason (nullable)`
- `AppointmentCompleted` — `appointmentId, staffUserId, completedByUserId (nullable)`
- `AppointmentNoShow` — `appointmentId, staffUserId, markedByUserId (nullable)`

All numeric ids only (no PII in the payload), matching this codebase's
existing event-payload convention (e.g. `LocationAccessDeniedException`,
`WorkspaceMembershipBusinessAssigned`). No new audit/history table (§5.4)
— these events are the audit trail.

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
notification twice is a no-op by construction, not by a separate
dedup ledger.

## 12. Exact implementation allowlist — six dependency-ordered sub-slices

Each sub-slice is its own branch/PR, its own explicit human authorization
gate (per this contract's own Status line), and — except where a hard
prerequisite is named — independently reviewable.

### Sub-slice A — Schema/domain foundation

- **Files/domains**: new migrations for all six tables in §5; Eloquent
  models (`BookingType`, `StaffAvailabilityRule`, `StaffTimeOff`,
  `Appointment`, `ExternalCalendarConnection`, `ExternalCalendarBusyBlock`,
  `StaffBookingLock`) with casts/relations only — no services, no
  controllers, no routes.
- **Prerequisites**: none beyond Contracts 1–14 (already merged).
- **Schema**: exactly §5.1–§5.6, plus `staff_booking_locks`
  (`staff_user_id` PK/unique, no other columns needed beyond timestamps)
  for §7's lock target.
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
  **Must verify at this point** whether `business_locations.hours`
  already drives any open/closed logic elsewhere (§5.3) before deciding
  whether Booking Type/Availability need to respect it as an outer bound.
- **Concurrency**: none beyond ordinary single-row CRUD (no
  double-booking surface yet — that's C).
- **Tests**: feature tests per CRUD action × role (owner/admin/staff) ×
  Location-ACL boundary (granted vs ungranted Location → 404, matching
  the existing `LocationAccessDeniedException`/404 convention), plus the
  "staff cannot edit another staff member's availability" boundary.
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
- **Concurrency**: this sub-slice's entire purpose — §7 in full,
  including concurrent-request tests that prove two simultaneous
  overlapping-booking attempts for the same staff member never both
  succeed, and that a reschedule racing a cancel is resolved
  deterministically by lock order, mirroring
  `WorkspaceOwnershipTransferTest`'s own concurrency-test style
  (transaction-boundary, row-lock-order assertions) from the
  already-merged Contract series.
- **Tests**: concurrent-write tests (parallel processes/threads
  attempting overlapping bookings), round-robin distribution correctness,
  reschedule atomicity (interval check runs against the *new* interval
  only, original untouched on failure), event-payload correctness for
  all five lifecycle events.
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
  even if linked to directly by id (Addendum §4).
- **Concurrency**: none new — consumes C's already-safe service; UI-level
  optimistic-locking/stale-view handling (e.g., a reschedule attempted
  against a slot another request just filled) surfaces C's own refusal
  as a user-facing error, never retried silently in a way that could
  bypass C's checks.
- **Tests**: feature/HTTP tests per view and action × Location-ACL
  boundary; a browser-level smoke pass per this repository's own
  UI-verification convention.
- **Risk**: Medium — UI complexity and the new Location-picker pattern
  are the main novelty; the underlying engine is already proven by C.
- **Model**: Sonnet 5 sufficient.

### Sub-slice E — Public self-booking flow

- **Files/domains**: unauthenticated public controller/routes (a
  scheduler page reachable from the website (§14) and Conversations
  links (§11), per Blueprint §12), Contact find-or-create for the
  booking customer (exact dedup rule — phone/email match within the
  Location's Business — to be confirmed against existing Contact-creation
  conventions at implementation time, not invented here without that
  check), and the `PlatformFeature::Calendar` `Available` flip (§11) as
  this sub-slice's last step.
- **Prerequisites**: A, B, C (hard); D not required (the public flow does
  not depend on the authenticated UI existing, only on the booking
  engine), but shipping D first is recommended so Business staff can see
  what customers are booking before the public link goes live.
- **Schema**: none new (reuses `contacts`, `appointments`).
- **Tenancy/security**: no authenticated actor — Location/Business
  identity comes entirely from the public link/slug itself; the booking
  engine's own Location-bound schema (§5) is what prevents cross-Business
  leakage, not a session-based check. Abuse/rate-limiting on an
  unauthenticated write endpoint is a real concern Blueprint §12 does not
  specify a mechanism for — flagged as an open question for the human
  before this sub-slice ships, not silently resolved with an invented
  CAPTCHA/rate-limit design.
- **Concurrency**: consumes C's service as-is; no new concurrency surface
  (a public booking is subject to the exact same lock/overlap sequence as
  an authenticated one).
- **Tests**: end-to-end public-booking tests (slot shown → booked →
  both Business and customer see it, matching the Acceptance Matrix's own
  acceptance statement verbatim), Contact find-or-create correctness,
  entitlement-flip verification (`PlatformFeatureRegistryTest`-style).
- **Risk**: Medium — public/unauthenticated surface raises the stakes of
  any Location-boundary bug beyond what an authenticated UI would.
- **Model**: Sonnet 5 sufficient.

### Sub-slice F — External Google/Outlook calendar integration

- **Files/domains**: OAuth connect/disconnect flow (mirroring
  `business_google_connections`' controller pattern, §3.3/§5.5), full +
  incremental sync job (`app/Console/Commands/`, matching this
  codebase's existing command conventions), webhook ingestion endpoints
  for both providers, wiring `external_calendar_busy_blocks` into C's
  overlap query as the second "busy source" (§7's extensibility point —
  should require no change to C's transaction structure itself).
- **Prerequisites**: A, C (hard — needs the extensible busy-source union
  point C already built); D/E not required, can land concurrently once
  C is stable.
- **Schema**: none new (uses A's `external_calendar_connections`/
  `external_calendar_busy_blocks`).
- **Tenancy/security**: connections are strictly per-User (§6); the
  connect flow must verify the connecting User's own identity, never a
  Workspace-level actor.
- **Concurrency**: sync writes are idempotent upserts (§5.6/§11) — safe
  under concurrent polling + webhook delivery for the same connection;
  no interaction with C's per-staff booking lock beyond reading the
  resulting cache table.
- **Tests**: OAuth flow tests (token storage/encryption, state-nonce
  single-use), sync idempotency (same delta/webhook applied twice →
  no duplicate rows), failure-classification/stale-data behavior (§11),
  conflict-check correctness once an external busy block is present.
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

## 14. Acceptance criteria

1. Every Booking Type, Appointment, and availability rule belongs to
   exactly one Location, enforced by a NOT NULL FK (§5), never a
   soft/optional attribution (Addendum §5).
2. `LocationAccessGuard` is the only Location-authorization mechanism
   used anywhere in this slice's code (§6) — no parallel ACL logic.
3. Two concurrent overlapping-booking attempts for the same staff member,
   across any combination of Locations, never both succeed (§7),
   verified by an actual concurrent-write test, not a single-threaded
   simulation.
4. A customer can self-book a slot through the public scheduler and both
   the Business and the customer see the resulting appointment
   (Acceptance Matrix, verbatim).
5. `PlatformFeature::Calendar` is flipped to `Available` only after that
   full flow is true end-to-end (§11).
6. External calendar sync never blocks or breaks internal booking when
   the provider API is unavailable (§11's fail-safe-stale behavior,
   verified by a test that simulates a provider outage).
7. `git diff --check` clean and a clean working tree at the end of each
   sub-slice's own commit.

## 15. Non-goals

- **Buffer-before/buffer-after handling** — zero mentions anywhere in
  `docs/product/`; not authorized, not built.
- **Booking capacity / group bookings** — zero mentions in the
  authoritative docs; every Booking Type is single-booking-per-slot only.
- **Zoom or any video-conferencing integration** — zero mentions anywhere
  in `docs/product/`.
- **A new Location-closure/holiday-hours concept** — deferred pending the
  Sub-slice B verification of `business_locations.hours`'s existing
  behavior (§5.3); not invented here.
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
- **Rate-limiting/abuse-prevention design for the public booking
  endpoint** — flagged as an open question for the human (§12.E), not
  resolved by invention here.
- **Reopening or modifying the Workspace/Agency tenancy migration**
  (Contracts 1–14) in any way.

## 16. Merge prerequisites

None hard at the whole-slice level — Slice 15 is explicitly independent
of Slices 1–14 per the Roadmap, beyond those already being merged (they
are, as of `3dbb1e11`). Per-sub-slice hard prerequisites are stated in
each block of §12 (A before B before C; D and E both need A+B+C; F needs
A+C).

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

Implement exactly the six tables in SS5.1-SS5.6 plus staff_booking_locks
(SS12.A), as migrations, plus their Eloquent models with casts/relations
only (no business logic). Follow this repository's existing conventions
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

Before writing code, verify whether business_locations.hours already
drives any open/closed scheduling logic elsewhere in the app (SS5.3
flags this as unresolved) -- grep for its consumers and report what you
find before deciding whether Availability must respect it as an outer
bound.

Implement Booking Type CRUD and availability-rule/time-off CRUD, each
action authorizing via LocationAccessGuard::assertUserCanAccessLocation()
(SS6) -- never a new ACL algorithm. Enforce SS6's "staff may edit only
their own availability" rule exactly.

Tests per SS12.B: CRUD x role (owner/admin/staff) x Location-ACL boundary
(granted vs ungranted -> 404), plus the own-availability-only boundary.

After implementing: run tests, git diff --check, commit, push to a fresh
branch off A's merged state. Do NOT create a PR. Do NOT merge. Return:
SHA, files, test counts, and your finding on business_locations.hours.
```

### 18.C — Booking engine / concurrency

```
You are implementing Sub-slice C of Slice 15, per docs/product/
implementation-contracts/15-CALENDAR-BOOKING-AVAILABILITY.md SS7 and
SS12.C -- the highest-risk sub-slice in this contract. Hard prerequisites:
Sub-slices A and B merged.

Re-read SS7 in full before writing any code. Implement the exact
transaction sequence it specifies: SELECT ... FOR UPDATE on the staff's
staff_booking_locks row, overlap query against scheduled appointments
across ALL Locations for that staff member, union external busy blocks
(source list must be written as extensible per SS7's own instruction,
even though no external source exists until Sub-slice F), refuse on any
overlap, otherwise write and commit. Reschedule runs the identical
sequence against the new interval under the same lock. Implement
round-robin staff assignment among a Booking Type's eligible, available
staff. Dispatch the five events in SS10 with exactly the payloads listed.

This sub-slice is service-level only -- no controller/UI required to
exercise it; write direct service tests.

Tests per SS12.C, especially: an actual concurrent-write test (parallel
attempts at overlapping bookings for the same staff member must never
both succeed), reschedule atomicity, round-robin correctness, event
payload correctness.

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
prerequisites: Sub-slices A, B, C merged (D recommended but not required).

Before writing the Contact find-or-create logic, read the existing
Contact model/repository's own creation conventions and confirm the
correct dedup rule (phone/email within the Location's Business) rather
than inventing one (SS12.E flags this explicitly as unconfirmed).

Build the unauthenticated public scheduler flow (page + booking action),
reusing Sub-slice C's service unchanged. Do not invent a rate-limiting/
CAPTCHA mechanism not specified anywhere in the authoritative docs (SS15)
-- if you believe the endpoint needs one, STOP and report it as an open
question rather than building your own design.

As the last step of this sub-slice, flip PlatformFeature::Calendar from
Planned to Available (SS11), mirroring the exact precedent already used
for WebsiteGeneration/GoogleBusinessProfileModule/AiCooBasic -- only after
verifying the full "customer books a slot, both parties see it" flow
actually works end-to-end.

Tests per SS12.E, matching the Acceptance Matrix's own acceptance
statements verbatim (SS14 items 4-5).

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

Re-read SS5.5, SS5.6, SS7's extensibility point, and SS11's failure-
behavior requirements in full before writing code. Mirror
business_google_connections' OAuth pattern (SS3.3) for connect/disconnect,
but per-User as SS5.5 specifies -- resolve the "once per provider, not
once total" ambiguity exactly as SS5.5 states, or flag explicitly in your
report if you believe it should be stricter.

Implement full + incremental sync (a console command, matching this
repo's existing command conventions) and webhook ingestion for both
providers, writing to external_calendar_busy_blocks via idempotent upsert
keyed by (connection_id, provider_event_id) -- verify with an actual
test that processing the same delta/webhook twice produces no duplicate
rows. Wire the busy-block table into Sub-slice C's overlap query as an
additional source WITHOUT modifying C's transaction/locking structure --
if you find you need to change that structure, STOP and report why,
since SS7 was explicitly designed to make this sub-slice additive.

Implement SS11's fail-safe-stale behavior exactly: a provider outage
during sync must never block or fail an internal booking; verify this
with a test that simulates the provider being unavailable.

Run tests, git diff --check, commit, push to a fresh branch. Do NOT
create a PR. Do NOT merge. Return: SHA, files, exact idempotency test
results, exact outage-simulation test results, and confirmation that
Sub-slice C's transaction structure was not modified (or, if it was,
exactly why).
```
