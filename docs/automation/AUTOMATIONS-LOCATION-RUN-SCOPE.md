# AUTOMATIONS — LOCATION RUN-SCOPE

Authority: `AUTOMATIONS-V2-WORKFLOW-ENGINE-CONTRACT.md` and
`AUTOMATIONS-MERGED-FOUNDATIONS-INTEGRATION.md` §6, which deferred this. Base:
`origin/main` at `f161c491`. No engine redesign: one nullable column on the
published version, one on the enrollment, and the existing enrollment door.

## 1. Model

A workflow has one of three scopes (the final V1 pass added the third):

* **Whole business** — no Location bound; the fact's Location (if any) is pinned.
* **One Location** — only facts at that Location.
* **Selected Locations** — only facts at one of up to 100 chosen Locations. A run is
  **always pinned to ONE factual Location**, never to the list.

| Where | Column | Meaning |
|---|---|---|
| `automation_workflow_versions.scope_mode` | `business` \| `one` \| `selected` (default `business`) | the scope this version was published with |
| `automation_workflow_versions.business_location_id` | nullable FK → `business_locations` | the Location of a `one` scope; NULL otherwise |
| `automation_workflow_version_locations` | pivot (version, Location), unique pair | the Locations of a `selected` scope |
| `automation_enrollments.business_location_id` | nullable FK → `business_locations` | the Location the journey is pinned to; NULL = the fact had none |

The runtime reads one value object, `WorkflowLocationScope` (`AutomationWorkflowVersion::scope()`),
and it fails closed: a bound scope that names nothing admits nothing, and an unknown mode
reads as bound-to-nothing. Drafts carry `scope_mode`, `business_location_id` and
`business_location_ids` in the trigger node's config; a legacy draft with only
`business_location_id` reads as "one" and the migration backfills existing bound versions to
`one`. A selected scope with no fact Location is never enrolled.

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

### Existing workflows: one gate for every operation

`ResolvesAutomationWorkflows::resolveWorkflow()` — which every customer workflow
endpoint goes through — now asks `WorkflowLocationAuthority::mayOperate()`: open,
settings, draft show/autosave/publish/discard, pause, resume, archive, enrollment
history, enrollment logs, stop-all, manual enrollment, simulate and the test-contact
picker. (There is no delete.) An actor outside the authority gets what an unknown uid
gets — **404** — before any contact is queried, any job queued or any run row read, so
a forged workflow, contact or enrollment id also fails closed (an enrollment is only
ever resolved inside a workflow the actor already passed).

* **Bound workflow** → the actor must reach its Location.
* **Business-wide workflow** → the actor must reach every Location, for the same reason
  only they may publish one.
* The scope is the **published version's** own column; never inferred from a contact
  (a contact who has since moved changes nothing), never from node config.
* A workflow never published has no live scope: a draft bound to a Location needs
  reach of it; an unscoped draft belongs to its creator (who must be able to open it
  to choose a Location) and to full-reach actors.
* The workflow **list** is narrowed in the page query itself (the actor's reach, plus a
  `NOT EXISTS` for "reaches every Location"), so an unreachable or Business-wide
  workflow is not even named. Owners and full-reach actors are unchanged.

The Location ACL reads are filed as shared authority (`WorkflowFeatureQueryScope::shared`),
like tenancy resolution, so the §18 feature-owned budgets (list 2, builder 4) are
unchanged.

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
* **Send SMS** — a Contact who left is skipped, as above. **The Location-aware sender
  blocker is closed** (final V1 pass) by the smallest canonical seam:
  `business_messaging_number_locations` assigns a managed number to Locations
  (Settings → Text messaging → "Locations that use this number"), and
  `BusinessMessagingIdentityResolver::numberServes()` is the one rule:
  *assigned* → exactly those Locations; *unassigned* → a Business with at most one
  active Location serves it implicitly, while with several Locations only a
  Business-wide workflow may use it. The run's pinned Location travels in a
  `LocationSendContext` through the existing quick-send → managed dispatch path, and
  `ManagedMessageDispatcher` refuses before any operation, measurement or provider call
  when the number cannot be shown to speak for it. The idempotency key gains the Location
  fragment, so billing and opt-out stay the one managed path. A bring-your-own sender has
  no assignment, so a Location-limited workflow of a multi-Location Business with a BYO
  sender is refused (`location_sender_unavailable`) — documented limit, not a guess.
  Publish refuses such a workflow up front for a plain-words reason. Business-wide
  journeys keep the unassigned number exactly as before.
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

One control on the trigger: **Where it applies — Whole business / One location / Selected
locations** (active Locations of the Business; an archived one already chosen stays visible
and marked; the selected list is a scrolling checkbox list). The trigger card summary
appends the Location(s); the workflow list's "Applies to" column names one Location or
"N locations" (a subselect on the page query; no extra query). The actor must reach
**every** Location the scope names (Business-wide needs full reach) to save, publish, pause,
resume, archive, run or see the workflow; a selected scope is hidden from, and closed to,
staff who miss any of its Locations. The simulator and manual enrollment use the same scope
object. The bundle was rebuilt with the repository's `laravel-mix`.

## 7. Schema

Two additive migrations, no backfill (every existing workflow is Business-wide and a
Location is never guessed after the fact):

* `2026_10_27_090001_add_business_location_id_to_automation_workflow_versions_table`
* `2026_10_27_090002_add_business_location_id_to_automation_enrollments_table`

Both are idempotent and reversible (`down()` drops FK, index, column).

The final V1 pass added three more (each justified by one table or column):

* `2026_10_28_090001_add_scope_mode_to_automation_workflow_versions_table` —
  `scope_mode`, backfilled to `one` where a version already named a Location.
* `2026_10_28_090002_create_automation_workflow_version_locations_table` — the selected list.
* `2026_10_28_090003_create_business_messaging_number_locations_table` — which Locations a
  managed number serves (the Location-aware sender seam).

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

* ~~Location-aware Send SMS~~ — **closed** in the final V1 pass (see §5). Remaining limit:
  a bring-your-own sender cannot be assigned to Locations.
* Backfilling a Location onto pre-existing enrollments.
* A checkpoint that holds a journey whose pinned Location is later archived (see above).
* Per-node Location overrides and multi-Location fan-out (out of scope by design; a
  selected scope is a filter, and each run pins one Location).

## 10. Verification

Own disposable database: `ultimatesms_testing_automations_location_scope`. New tests:
`tests/Feature/Automations/Workflow/Location/{WorkflowLocationScopeTest,
LocationTriggerRulesTest,LocationAppointmentTriggersTest,LocationRuntimeAndActionsTest,
LocationBuilderTest,LocationActorAuthorityTest,LocationActionDriftTest,
LocationNotificationRecipientsTest}.php`.

The final V1 pass added (database `ultimatesms_testing_automations_v1_final`):
`Location/SelectedLocationsScopeTest.php` (the selected scope end to end: builder data,
save, publish, list visibility, operation ACL, enrollment, simulator),
`Location/LocationSmsSenderTest.php` (the sender rule across implicit / assigned /
unassigned / BYO), `tests/Feature/Messaging/LocationAwareManagedSendTest.php` (the dispatcher
refuses before any operation or provider call), and
`tests/Feature/Business/TextMessagingNumberLocationsTest.php` (the settings card). The
cross-domain suites are under `Workflow/CrossDomain/`.
