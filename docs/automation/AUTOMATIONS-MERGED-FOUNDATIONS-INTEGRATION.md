# AUTOMATIONS — MERGED FOUNDATIONS INTEGRATION

Authority: `AUTOMATIONS-V2-WORKFLOW-ENGINE-CONTRACT.md` (the engine; unchanged in
architecture) and the contracts of the four foundations this consumes. This is
**not** an engine rewrite: the registry, compiler, runtime, executors and event
vocabulary are the existing ones, extended with the facts and actions the merged
foundations now make real. Base: `origin/main` at `10cf1e46`.

## 1. Scope

Consumed: Contact Tags (`TagManager`, `ContactTagAdded` / `ContactTagRemoved`),
Business Email (`BusinessEmailSender`), Calendar (`AppointmentScheduled` /
`Cancelled` / `Rescheduled`), Forms (`FormSubmissionRecorded`).

Not touched: Payments, Invoices, Documents, Proposals, Contracts, Agency, Website,
SEO, Ads, Platform Owner, inbound email, SMS consent, any new workflow node.

## 2. Vocabulary added

| Kind | Value | Source of truth |
|---|---|---|
| Trigger | `contact_tag_added`, `contact_tag_removed` | Tags events (optional `tag_id` filter) |
| Trigger | `form_submitted` | `FormSubmissionRecorded` (optional `form_id` filter) |
| Trigger | `appointment_scheduled`, `appointment_cancelled`, `appointment_rescheduled` | Calendar events |
| Action | `send_email` (`subject`, `body`) | `BusinessEmailSender::send()` |
| Action | `add_tag`, `remove_tag` (`tag_id`) | `TagManager::attachTag()` / `detachMembership()` |
| Condition | `contact.has_tag:{tag_id}` with `is_true` / `is_false` | canonical `contact_tags` membership |

There is **no "confirmed" appointment trigger**: `AppointmentStatus` has no
confirmed state (scheduled / cancelled / completed / no_show), and a state is not
invented to fill a list. Completed and no-show events exist but are outside this
vocabulary. Every new trigger defaults to `once_per_occurrence`.

## 3. Seams reused unchanged

`WorkflowTriggerType` / `WorkflowNodeType` enums, `TriggerSourceRegistry`,
`NodeExecutorRegistry`, `NodeTypeRegistry` (shape), `WorkflowCompiler` (tenancy),
`WorkflowReferenceCatalog(+Loader)`, `ConditionSubjectRegistry`,
`WorkflowEnrollmentService` (the one door, `UNIQUE(enrollment_key)`),
`WorkflowAdvancer` (claim, checkpoint, failure policy), `WorkflowSimulator`,
`causation_depth` / `MAX_CAUSATION_DEPTH`, the queued `automation` listener shape
of `EnrollFromCrmOpportunityEvent`.

New engine code is additive: `ListeningWorkflows` (the CRM source's two-step read,
shared), `FoundationTriggerSource` (the common enrollment tail), three sources
(`ContactTagTriggerSource`, `FormSubmittedTriggerSource`,
`AppointmentTriggerSource`), three listeners, three executors, one condition
subject, `ClaimedStepRun` and `ContactMergeFields` (both lifted unchanged out of
`SendSmsNodeExecutor` so a second message step does not carry a second copy).

## 4. Occurrence and idempotency

Each trigger enrolls under the **owning domain's own occurrence key**, which
`EnrollmentService` turns into `enrollment_key` under the version's policy:

| Trigger | Occurrence key |
|---|---|
| tag added / removed | `contact_tag_added:{membership id}` / `contact_tag_removed:{membership id}` (a never-reused row id: remove-then-re-add is a new occurrence) |
| form submitted | `form_submission:{submission uid}` (one immutable submission) |
| appointment booked | `appointment_scheduled:{appointment id}` |
| appointment cancelled | `appointment_cancelled:{appointment id}` (terminal state) |
| appointment rescheduled | `appointment_rescheduled:{appointment id}:{reschedule_count}` |

A redelivered event composes the same key and loses the same unique claim.

**Minimal additions at the owning seams** (no new events, no lifecycle change):
`AppointmentScheduled/Cancelled/Rescheduled::occurrenceKey()`; `AppointmentRescheduled`
gains an optional trailing `rescheduleCount` (the booking transaction's own
`reschedule_count + 1`, taken under the appointment lock), with a deterministic
digest fallback for an older caller. `TagManager::attachTag()` gains an optional
opaque `$origin`, `detachMembership()` is the one detach implementation
(`detachTag()` is its boolean view), and `ContactTagEvent` carries `origin`. The
booking engine's locking, round-robin and DST behaviour is untouched.

Action replay:
* **Send email** — operation key `automation:{workflow id}:{step run id}:email`,
  from the claimed step run alone (unique per enrollment+node). Never a timestamp,
  random id or rendered text. No claimed step run → no deterministic identity →
  nothing is sent.
* **Add / Remove tag** — `TagManager`'s own idempotency: a held tag is not
  re-attached, an absent one is not re-detached, and neither emits an event.

## 5. Loop prevention (existing mechanism, applied to tags)

An automation's tag action tells `TagManager` its causation reference
(`origin = automation_step_run:{id}`), carried on the resulting event.
`ContactTagTriggerSource` resolves it, **inside the event's Business**, to the
producing workflow and its `causation_depth`, then (a) never re-triggers the
producing workflow off its own output, and (b) enrolls any other workflow at
`depth + 1`, which `EnrollmentService` refuses beyond `MAX_CAUSATION_DEPTH`
(3). "A: tag added → remove it" with "B: tag removed → add it" therefore runs
depths 0,1,2,3 and stops. A person's own change has no origin: depth 0, never
suppressed. This is the engine's existing causation rule (MessageReceived uses the
same one), not a boolean or a new table.

## 6. Tenancy, Location and authority

* Business is re-derived everywhere: `ListeningWorkflows` filters on
  `w.business_id`; the Contact is re-read scoped to the fact's Business; tags
  forms and appointments are re-read filtered on the Business (the appointment also
  on its Location, via `business_locations.business_id`).
* Forms: the submission row must agree with the event on Business, Location, Form,
  FormVersion, Contact and occurrence key; a submission without a Contact enrolls
  nobody. Answers are never read from the Form's current version and nothing is
  written to `form_sessions` / `form_submissions`.
* Calendar: cancelled / rescheduled events carry no Contact; it is derived from
  the appointment. "Cancelled" additionally requires the row to be cancelled.
* Tags: tags are Business-wide; the event's `locationId` is the Contact's at the
  moment of the change and is informational, never an authority.
* Send email: the sender re-derives the Contact, the Business's own connected
  mailbox and the Location. The node stores neither account, address nor
  Location (keys carrying them are ignored).
* Compile-time: every tag / form id in a trigger filter, tag action or `has_tag`
  condition must be in the Business's `WorkflowReferenceCatalog`; execution
  re-derives them (a tampered pinned config writes nothing).
* View As does not enter this path: automation execution has no actor; the
  checkpoint (active Business and Workspace, `automations` entitlement) is the
  existing one.

**Workflow-level Location run-scope was deferred by this lane and has since landed**
(`AUTOMATIONS-LOCATION-RUN-SCOPE.md`): `FoundationTriggerSource::enrollListening()`
now passes each fact's Location to `EnrollmentService`, which pins it on the
enrollment and refuses a mismatched Location-bound workflow. The paragraph above
describes what this lane enforced on its own; the run-scope contract supersedes the
"not yet Location-bound" caveat.

## 7. Send email semantics (Business Email contract §8, preserved)

| Foundation result | Step outcome |
|---|---|
| refused (`BusinessEmailSendRefusedException`; no row) | failed, `email_refused: {category}` |
| `accepted` | succeeded (`Email sent to p***@domain`) |
| `failed` (retryable or not) | failed, `email_failed: {category}` — an External step is never re-executed by the engine, so no retry schedule is invented |
| `unconfirmed` | failed, `email_unconfirmed` — **never re-sent**; surfaced for a person |
| `queued` / `sending` (another worker) | failed, `email_in_progress`; nothing is re-sent |

Caps (`per_contact_per_hour`, `per_business_per_hour`), `idempotency_conflict` and
`location_required` come from the foundation untouched. The run state stores a
bounded, non-secret summary (masked address, category) — never body, token or
provider response.

## 8. Builder exposure

The builder is hand-wired (constants, drawer, summaries, Blade partials, a
committed webpack bundle), not registry-driven, so the minimum was added: trigger
choices (grouped Tags / Forms / Appointments) with a tag filter and a form filter,
three step-picker entries with forms (`send_email`: subject + body with the SMS
merge chips; `add_tag` / `remove_tag`: one select), a "Tags" group in the If / Else
subject list, card summaries, and `tags` / `forms` in the one-statement reference
catalog (extra `UNION ALL` halves; no extra query). Tags and forms are chosen from
the Business's own catalog, a bounded select — no async picker was built.
`public/js/automations/workflow-builder.js` was regenerated with the repository's
`laravel-mix` (a dev-mode webpack build of `resources/js/automations/workflow-builder`).

## 9. Deferred (explicit)

* Payments / Invoices / Documents / Proposals / Contracts triggers (separate lanes).
* ~~Workflow-level Location run-scope~~ — landed: `AUTOMATIONS-LOCATION-RUN-SCOPE.md`.
* Email suppression / unsubscribe — a declared dependency of the Business Email
  foundation before any promotional-style automation email; inbound / reply triggers.
* Contact-timeline entries for the new action types (`AutomationActivitySource`).
* Booking-type filter on appointment triggers; submitted-value conditions for forms.
* Engine retry of a retryable email failure (External steps are never re-run).

## 10. Verification

Own disposable database: `ultimatesms_testing_automations_integration` (accepted by
`Tests\Support\TestDatabaseSafety`). No provider is reachable: Business Email runs
against its in-memory fake under `Http::preventStrayRequests()`. New tests:
`tests/Feature/Automations/Workflow/Foundations/{ContactTagIntegrationTest,
SendEmailActionTest,FormSubmittedTriggerTest,AppointmentTriggerTest,
FoundationVocabularyTest}.php`. Pinned-vocabulary tests that listed the old
non-goals (`NoUnsupportedVocabularyTest`, `NodeRegistryCoexistenceTest`,
`DrawerPartialsTest`, `TriggerArchitectureTest` and the registry-listing tests)
were updated in the same change.
