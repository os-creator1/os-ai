# AUTOMATIONS — LOCATION RUN-SCOPE

Authority: `AUTOMATIONS-V2-WORKFLOW-ENGINE-CONTRACT.md` and
`AUTOMATIONS-MERGED-FOUNDATIONS-INTEGRATION.md` §6, which deferred this. Base:
`origin/main` at `f161c491`. No engine redesign: one nullable column on the
published version, one on the enrollment, and the existing enrollment door.

## 1. Model

A workflow is either **Business-wide** or **bound to one Location**.

| Where | Column | Meaning |
|---|---|---|
| `automation_workflow_versions.business_location_id` | nullable FK → `business_locations` | the scope this version was published with; NULL = Business-wide |
| `automation_enrollments.business_location_id` | nullable FK → `business_locations` | the Location the journey is pinned to; NULL = the fact had none |

The scope lives on the **version** because a version is immutable once published
and enrollments pin their version. Changing a workflow's Location is therefore a new
draft and a new publish, never a mutation of history, and journeys already running
keep the scope (and Location) they started under. The draft carries the choice in
its trigger node's config (`business_location_id`), like every other trigger
setting, so autosave, revisions and undo already work; **publish promotes it** to
the version column and the runtime reads **only the column** — never node config.
There is no per-step Location.

## 2. Publish

`WorkflowCompiler::validate()` (trigger node): shape in `NodeTypeRegistry`
(positive id or null), tenancy against the one `WorkflowReferenceCatalog` read
(Locations ride its existing `UNION ALL`; no extra query). The Location must belong
to the workflow's Business and be **active**; foreign, missing and archived all
refuse publish. Foreign and missing read identically.

## 2a. Who may scope a workflow (actor Location ACL)

The platform's own `LocationAccessGuard` decides — no second permission system —
through `WorkflowLocationAuthority`, on the **server** at save and publish (the
builder only stops offering what the server would refuse).

| Actor | Picker | Save draft | Publish |
|---|---|---|---|
| owner / "all locations" member (reaches **every** Location the Business has now) | every Location, and Whole business | any Location of the Business | bound to any, or Business-wide |
| member with **selected** Locations | only their Locations, **no** Whole business | a Location outside their reach is refused (422) even if forged into the JSON; an unscoped draft is allowed (nothing is live) | only bound to one of their Locations |
| no Location reach | nothing | refused | refused |

**Selected-Location staff may not publish a Business-wide workflow**, because that is
authority over every Location (it enrolls facts from all of them and acts on their
contacts); the generic `automations` capability does not buy it. "Full reach" is
computed against the Locations that exist **now**, so a member whose selected set
happens to cover every Location loses it the day a new Location is added. The publish
check runs inside the publisher's transaction against the very draft being promoted
(no TOCTOU); an unattended publish (no actor) skips it. View As is handled by the
guard itself. Execution has no actor and never comes through here.

## 3. Enrollment — the one door

`EnrollmentService::enroll(workflow, contact, key, depth = 0, ?int $locationId = null)`.
The caller passes the **triggering fact's** Location. The service:

* bound version → enrolls only a fact of exactly that Location. A `null` Location is
  refused, so a source that forgets to pass one **fails closed** (the old 3-argument
  call can never enroll a bound workflow);
* any Location offered must be one of **this Business's** — a forged id from any
  source, or a tampered version column, enrolls nobody;
* stores the Location on the enrollment (a Business-wide workflow pins the fact's, or
  NULL — none is invented).

Occurrence keys, `enrollment_key` uniqueness, causation depth and loop prevention are
unchanged; the Location is deliberately **not** in the key (occurrence keys are
already unique per fact, and a fact has one Location).

## 4. What each trigger offers as the fact's Location

| Trigger | Location |
|---|---|
| tag added / removed | the event's `locationId` — the Contact's **at the mutation**, captured under TagManager's lock; never the tag's (tags are Business-wide), never re-read |
| form submitted | the immutable `form_submissions.business_location_id` |
| appointment booked / cancelled / rescheduled | the appointment row's `business_location_id` (fixed for its life; the source reads the row, not the event's claim) |
| opportunity created / stage / won / lost | the deal's `crm_opportunities.location_id` as of the change |
| contact created, manual enrollment | the contact's own `location_id` |
| contact date reached | the contact's own; a bound workflow also filters `contacts.location_id` in SQL so capped runs are not spent on contacts that would be refused |
| message received | the CONVERSATION's `chat_boxes.location_id` when exactly one conversation exists for the number; ambiguous or missing = NULL (a message belongs to its thread, and the Contact's Location is not a proxy) |

Manual enrollment additionally refuses, up front (422, nothing queued), contacts
outside a bound workflow's Location, instead of queueing jobs that quietly no-op.

## 5. Runtime

The pin is written once and read from the row. Nothing re-derives it from the
Contact: not the advancer, the wake sweep, recovery or any executor. It survives
immediate advancement, queue hand-off, wait, wake, recovery and the simulator (which
refuses a contact a bound workflow would never take, `contact_outside_workflow_location`).

**Contact-location drift.** A Contact moving after enrollment changes nothing about
the journey: it stays pinned to the original Location. Actions that are
Location-sensitive honour the pin or fail closed:

* **Send email** passes the pinned Location through `BusinessEmailSendRequest::$location`
  (the foundation's explicit-Location seam; it still requires an active Location of
  the Business). The email is sent from, and attributed to, the run's Location even
  after the Contact moved or when the Contact has none. A pinned Location that no
  longer resolves fails (`email_location_unavailable` or the sender's own refusal) and
  **never falls back** to the Contact's. An unpinned run keeps the foundation's own
  rules (Contact → single active Location → `location_required`).
* **Add / Remove tag** and **Update contact field** — a **bound** workflow acts only on
  a Contact still at that Location and on a journey pinned to its own version's
  Location (`PinnedRunLocation`); a Contact who left is skipped
  (`contact_outside_workflow_location`; `run_location_mismatch` if the pin and the
  version disagree), never written under a scope they have left and never
  re-scoped to their new Location. Business-wide journeys are unchanged.
* **Send SMS** — a Contact who left is skipped, as above. And a bound journey **cannot
  text at all yet**: the messaging foundation's sending identities and numbers
  (`BusinessMessagingIdentity`, `Senderid`, `PhoneNumbers`, BYO channels) are
  Business-level and carry no Location, so no sender can be proven to belong to the
  pinned Location, and sending from the Business-wide one could speak for another
  Location. It fails closed (`location_sender_unavailable`), and publish refuses a bound
  workflow containing a text step with a plain-words message. **Dependency:** a
  Location-aware sender seam in messaging (numbers/identities assigned to Locations)
  — until then use a Business-wide workflow or an email. Business-wide journeys are
  unchanged.
* **Internal notification** — the note names the contact and the workflow, so for a
  journey **pinned to a Location** (bound workflow, or a Business-wide workflow whose
  fact had a Location) the audience is the Business owner plus active members whom
  `LocationAccessGuard` lets into that Location; inactive and foreign members stay
  excluded, and an unresolvable pinned Location tells only the owner. A journey with
  no pinned Location keeps the Business-level audience.
* **Contact has tag** stays Business-wide membership logic.

**Test workflow.** The contact picker is narrowed in the query: an actor who reaches
every Location sees every contact, otherwise only contacts in a Location they reach (a
contact with no Location is not reachable to them), and a bound workflow's picker offers
only that Location's contacts. `simulate` resolves the contact under the same rule — a
contact outside the actor's reach, forged uid included, reads as unknown (404). The
simulator itself never infers or reassigns a Location.

**Pinned Location later archived (disposition).** No hold is implemented, and none is
needed for safety: nothing falls back or drifts. Send email fails closed (the
foundation refuses an inactive Location), a bound journey cannot text, tag and field
actions act only on a contact still at the bound Location, and notifications resolve
their audience from the pinned Location. A journey simply continues without side
effects it cannot prove, or fails visibly. Holding such journeys remains deferred.

## 6. Builder

One control on the trigger: **Where it applies — Whole business / a Location**
(active Locations of the Business; an archived one already chosen stays visible and
marked). The trigger card summary appends the Location; the workflow list gains an
"Applies to" column (a subselect on the page query; no extra query). Nothing else in
the builder changed. The bundle was rebuilt with the repository's `laravel-mix`.

## 7. Schema

Two additive migrations, no backfill (every existing workflow is Business-wide and a
Location is never guessed after the fact):

* `2026_10_27_090001_add_business_location_id_to_automation_workflow_versions_table`
* `2026_10_27_090002_add_business_location_id_to_automation_enrollments_table`

Both are idempotent and reversible (`down()` drops FK, index, column).

## 8. Historical branch

`agent/automations-location-run-scope-foundation` was **not merged, cherry-picked or
copied**. It modelled multi-Location scopes (`location_scope` + a version-locations
table), required a Location for every enrollment, and carried two backfills and a
notification ACL (the correction pass re-implemented the actor and recipient ACLs on `LocationAccessGuard` rather than porting that code). This lane's brief is one Location or Business-wide, null preserved,
no backfill — so only the ideas were reused: the version as the home of the scope,
`business_location_id` pinned on the enrollment by the one door, the fact-specific
Location sources (conversation for messages, deal for CRM, contact for contact-based
triggers), and "no Location, no bound run".

## 9. Deferred

* Location-aware Send SMS (needs a messaging seam that assigns numbers/identities to
  Locations); bound workflows cannot text until then.
* Actor Location ACL on the other workflow operations (pause, resume, archive, stop-all,
  manual enrollment of contacts): only scope, save, publish and Test workflow are
  covered here.
* Backfilling a Location onto pre-existing enrollments.
* A checkpoint that holds a journey whose pinned Location is later archived (see above).
* Per-node Location overrides and multi-Location fan-out (out of scope by design).

## 10. Verification

Own disposable database: `ultimatesms_testing_automations_location_scope`. New tests:
`tests/Feature/Automations/Workflow/Location/{WorkflowLocationScopeTest,
LocationTriggerRulesTest,LocationAppointmentTriggersTest,LocationRuntimeAndActionsTest,
LocationBuilderTest,LocationActorAuthorityTest,LocationActionDriftTest,
LocationNotificationRecipientsTest}.php`.
