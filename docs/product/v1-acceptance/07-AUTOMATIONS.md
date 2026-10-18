# V1 Final Acceptance 07 — Automations

Branch `agent/automations-location-run-scope-integration`, continuing from
`c91baeca28b4624d854e6fd23e8d4f56e674eccc` (the previous final foundation SHA). Database for
every run: `ultimatesms_testing_automations_v1_final` (disposable sibling accepted by
`TestDatabaseSafety`), `APP_ENV=testing`. Browser acceptance ran against a second disposable
sibling, `ultimatesms_testing_automations_v1_smoke`. No live provider is ever called: Stripe,
Telnyx, Google/Microsoft and the messaging core are faked or doubled at their existing seams.

This is the **final contract** of the Automations V1 product. It does not describe a second
engine: it is the V2 workflow engine (`AUTOMATIONS-V2-WORKFLOW-ENGINE-CONTRACT.md`) joined to the
merged CRM, Forms, Calendar, Documents/Payments, Business Email and Messaging foundations through
their own services. Companion documents: `docs/automation/AUTOMATIONS-LOCATION-RUN-SCOPE.md`
(scope model, in detail) and `docs/automation/AUTOMATIONS-MERGED-FOUNDATIONS-INTEGRATION.md`.

## 1. What a workflow is

One trigger, then steps, with If / Else branches and Waits. A draft is edited in the builder and
autosaved; **Publish** compiles it (shape, tenancy, entitlements, readiness) and promotes it to an
immutable version. A journey (enrollment) pins its version, so editing never changes people already
inside. Everything is per Business.

## 2. Scope — where a workflow applies

| Scope | Meaning | Stored as |
|---|---|---|
| Whole business | every Location, and facts that belong to none | `scope_mode = business` |
| One location | only facts at that Location | `scope_mode = one` + `business_location_id` |
| Selected locations | facts at any of up to 100 chosen Locations | `scope_mode = selected` + pivot `automation_workflow_version_locations` |

**A run is always pinned to ONE factual Location** — the fact's own — never to the list. A bound
workflow never enrolls a fact with no Location, and a contact who later moves keeps the Location the
journey started under; Location-sensitive actions honour the pin or fail closed. The runtime reads
one fail-closed value object (`WorkflowLocationScope`). The same object backs enrollment, the
simulator, manual enrollment and `DATE_REACHED` scans.

**Actor authority.** To save, publish, pause, resume, archive, test, enroll by hand or even see a
workflow, the actor must reach **every** Location it names (Business-wide needs reach of all). A
selected scope is hidden from, and closed to, anyone who misses one of its Locations. Foreign or
unreachable ids read as "not found".

## 3. Triggers (19)

| Group | Trigger | Occurrence key (replay-safe) | Location the run pins |
|---|---|---|---|
| CRM | Contact is created | the contact's id | the contact's Location |
| CRM | Opportunity created / moves stage / won / lost | `crm_opportunity_history:{id}` | the deal's Location |
| CRM | Tag added / removed | the tag event's own key | the contact's Location |
| Messaging | Customer sends a text | the inbound message operation (`operation:{id}`) | the conversation's Location |
| Forms | Form submitted | `form_submission:{uid}` | the submission's Location |
| Forms | Questionnaire submitted (multi-step only, on the completing page) | `form_submission:{uid}` | the submission's Location |
| Calendar | Appointment booked / rescheduled / cancelled | `appointment_scheduled:{id}`, `appointment_cancelled:{id}`, `appointment_rescheduled:{id}:{n}` | the appointment's Location |
| Documents | Proposal or document sent (kind filter: proposals/contracts, invoices, any) | `document_version_sent:{versionId}` | the document's Location |
| Documents | Proposal or document signed | `document_signature:{id}` | the document's Location |
| Payments | Payment succeeded | `document_payment_succeeded:{id}` | the document's Location |
| Payments | Payment failed (a durable attempt row exists) | `document_payment_failed:{id}` | the document's Location |
| Manual / time | Added by hand; Contact date arrives | manual / date keys | the contact's Location |

Every trigger is Business-scoped; the Location is derived from the owning domain's own row, never
trusted from the event payload. A contract is a proposal that requires a signature (there is one
document model). An automation's own document or deal change carries its origin
(`automation_step_run:{id}`), so a workflow never restarts itself and a causation chain stops at
depth 3 (`TriggerCause`).

## 4. Steps (16)

| Group | Step | Does it through | Idempotency | Class |
|---|---|---|---|---|
| Messaging | Send email | `BusinessEmailSender` (key `automation:{wf}:{stepRun}:email`) | at most once | External |
| Messaging | Send text message | the existing managed messaging path, Location-aware (§6) | at most once | External |
| Messaging | Send booking link | public booking page URL, by email and/or text | one delivery per channel | External |
| Messaging | Send form / Send questionnaire | the form deployment's public URL, same Business and Location | one per channel | External |
| CRM | Add tag / Remove tag | `ContactTagService` | idempotent | database |
| CRM | Update contact field | existing field writer | idempotent | database |
| CRM | **Move opportunity** | `CrmOpportunityService::moveToStage` | idempotent | database |
| Documents | **Create & send proposal or contract** | `DocumentManager` (create, catalog line, schedule, edit, send) | at most once (claimed step) | External |
| Payments | **Request payment** | `DocumentManager::resendLink` for the journey's document, or a new invoice built by `DocumentManager` | at most once | External |
| Internal | Notify your team | existing notification audience | idempotent | database |
| Flow | Wait / If / Else / End | the engine | — | none |

* **Move opportunity**: the deal the triggering fact names, else the contact's single open deal in
  that pipeline; none → `opportunity_not_found`, several → `opportunity_ambiguous` (nothing moves);
  a pipeline or stage of another Business, an archived stage or a resource outside the run's Location
  fails closed. Moving writes through the CRM service, so history and downstream triggers are the CRM's own.
* **Send booking link / form / questionnaire**: resolved when the step runs (a renamed or switched-off
  item is never sent from an old address); channels combine (email, text, or both); partial delivery is
  reported honestly ("Link sent by email; text: …"); all channels failing fails the step.
* **Create & send proposal**: from one catalog item and quantity, full payment or a deposit percentage
  split exactly; created at the pinned Location (or the Business's only active one — never guessed);
  linked to the journey's deal when it is the contact's; the recipient is the contact's single email
  address. No hand-built HTML: the Documents domain renders and issues it. The secure link is emailed by
  the Documents domain.
* **Request payment** never charges and never refunds: it only emails the secure payment link.

**Honest limits (V1, documented, not hidden).** There is no document template model, so a proposal is
built from a catalog item rather than a saved template. Documents and payment links are delivered by
**email only** — the secure token is never recoverable, so it cannot be put in a text. Charging a saved
card, refunds and PayPal are out of scope.

## 5. Conditions (If / Else)

No expression language: a closed list. Contact: replied since enrollment, first/last name, email,
company, subscribed to texts, contact group, custom fields, has tag. Event facts, **read live from the
owning domain at evaluation** (so *Wait → if not signed* works): opportunity stage and status, document
status / signed / paid, payment status, appointment status. A condition offered by the builder is
only one the trigger can supply, a missing fact reads as "not set" to every operator, and a forged or
foreign reference asserts nothing. The Test panel notes that event conditions read as not set for a test
contact.

## 6. Location-aware SMS

`business_messaging_number_locations` assigns a managed number to Locations (Settings → Text
messaging → "Locations that use this number"). The one rule, `numberServes()`: **assigned** → exactly
those Locations; **unassigned** → a Business with at most one active Location serves it implicitly; with
several, only a Business-wide workflow may use it. The pinned Location travels as a `LocationSendContext`
through the existing quick-send → managed dispatch path; `ManagedMessageDispatcher` refuses before any
operation, measurement or provider call when the number cannot be shown to speak for the Location.
Billing and opt-out stay the single managed path. Limit: a bring-your-own sender has no assignment, so a
Location-limited workflow of a multi-Location Business using a BYO sender is refused rather than guessed.

## 7. Idempotency, replay and retries

* One enrollment door; `UNIQUE(enrollment_key)` with the trigger's deterministic occurrence key; the
  enrollment policy (once per contact, or every occurrence) is the Business's choice per workflow.
* The advancer claims one step run per (enrollment, node); an External step is never re-run, whether the
  job is redelivered, the process dies, or the same event arrives twice. Document and email dedupe keys
  include the step run.
* Replaying a form submission, an appointment event, a webhook or a document event enrolls nobody twice
  and sends nothing twice (asserted in the integrated journey).
* A failed External step is **not retried automatically** (a retry could double-send); the journey's
  "If a step can't run" setting decides whether it stops or continues, and the history says why.

## 8. Entitlements and readiness

No plan name is hardcoded. `WorkflowCapabilities` reads the account's entitlement snapshot and the
Business's readiness: texting (a ready number), email (a connected mailbox), CRM, Calendar, Forms,
Documents (and the Catalog), Payments (documents plus a charge-ready Stripe account). The builder greys a
step or recipe the account cannot use and says why; **Publish refuses** a configuration the account
cannot execute (`WorkflowActionVerifier`), and a step that stops being executable later fails with a bounded
reason rather than being skipped silently.

## 9. Recipes (starter drafts)

Welcome a new contact · Notify the team about new contacts · Check in after a few days · Contact date
reminder · **New lead follow-up** · **Booking follow-up** · **Proposal follow-up** · **Signed → payment** ·
**Payment complete**. A recipe creates a normal editable **draft**; nothing runs until the person
completes the choices the recipe leaves open (booking type, product, pipeline stage, questionnaire) and
publishes. A recipe the account cannot run is shown disabled with the reason.

## 10. View As / Agency isolation

No new mechanism: the existing tenancy chain. Inside View As the Automations list, builder catalogs
(Locations, booking types, catalog items, forms, pipelines), test contacts and every id resolve against the
**viewed client Business only**; the Agency's own workflows and resources are never listed, and an
Agency-side id pasted into a client's workflow fails publish ("does not belong to this business").

## 11. Customer-readable errors

Every failure surfaced to a customer is a bounded sentence from one reason table
(`AutomationActivitySource`, reused by the history and logs panels and the contact timeline). An unknown
internal code reads "This step could not run." Provider bodies, credentials, tokens and raw exceptions
never reach a response.

## 12. Builder

Triggers are grouped CRM · Messaging · Forms · Calendar · Documents · Payments · Manual / time; steps
Messaging · CRM · Documents · Payments · Internal · Flow. The inspector offers names, never ids, for every
resource; the "Where it applies" control offers the three scopes (selected Locations as a checkbox list);
the **Test** panel shows the path a chosen contact would take with nothing sent or changed;
**Enrollment history** lists who entered (by number) and their status, and **Execution logs** shows each
step with a plain result or reason. The compiled bundle and stylesheet are committed (`public/js/automations/workflow-builder.js`,
`public/css/base/pages/automations-workflow-builder.css`).

## 13. Schema added in the final pass

`automation_workflow_versions.scope_mode` (backfilled to `one` where a Location was already bound),
`automation_workflow_version_locations` (the selected list), `business_messaging_number_locations` (the
Location-aware sender seam). Three migrations, each one table or column with one job.

## 14. Verification

Run on `APP_ENV=testing`, sequentially, against disposable sibling databases; pristine-main
comparisons ran on `6ac3e19c` (the branch's merge base) in a separate disposable database.

| Suite | Result |
|---|---|
| `tests/Feature/Automations` (incl. `Workflow/CrossDomain/*`, `Workflow/Location/*`) | 819 tests, 5,790 assertions in the full run — green; one further test (the simulator note) was added afterwards and ran green with its file and with the adjacent `Http`, `Builder`, `Foundations`, `Location` and `Logic` directories |
| `tests/Unit/Automations` | 30 tests, 114 assertions — green |
| `tests/Feature/Messaging` (incl. `LocationAwareManagedSendTest`) | 412 tests — green |
| `Business/TextMessagingNumberLocationsTest`, `Documents/DocumentConcurrencyTest` | 4 + 4 tests — green |
| CRM 103 · Calendar 348 · Forms 144 · Payments 192 · PaymentsConformance 10 · BusinessEmail 178 · Conversations 152 · Catalog 260 · V1Acceptance 46 · Unit Messaging 13 / Opportunity 151 / Payments 3 / Business 49 | all green |

**Outside-diff failures, identical on pristine main (not caused by this work):**
`Opportunity` 17 (`OpportunityProducerTriggerTest` 9, `OpportunityProducerSweepTest` 7,
`OpportunityExecutionStatusHttpTest` 1) · `Documents` 2 (`DocumentSendIdempotencyAndDeliveryTest`,
`PaymentsContractsAcceptanceTest`) · `Entitlement` 19 · `QueryBudget` 8 · `Business` 30
(capacity/migration/knowledge-profile/text-messaging-status classes). Three tests in the diff's
neighbourhood needed their pins updated for the new vocabulary and are listed in the commit:
`MessagingSchemaInvariantsTest` (the new pivot's migration/table), `OutboundIsolationTest` (the
dispatcher's optional `location` restriction) and `DocumentConcurrencyTest` (counts the link-email job,
not every queued job, now that Automations queues its own trigger job).

**Mutation checks** (each break was caught): scope not enforced at enrollment · sender Location not
enforced · a foreign pipeline stage accepted by Move opportunity.

The integrated journey
(`AutomationsV1JourneyTest`) runs enquiry → welcome email and booking link → booking → deal moved and
proposal emailed → signed → payment link emailed and deal moved → Stripe webhook paid → questionnaire
emailed → questionnaire answered → deal moved, with every provider faked, exactly once, then replays every
event and asserts nothing changes.

## 14a. Browser acceptance

Driven in the in-app browser against a disposable smoke database seeded with one photo-booth Business
(three Locations, two booking types, two packages, a lead form, a questionnaire, a sales pipeline), signed
in with a server-minted session (no credential typed). Twenty-one screens/states were exercised:
list (empty, then populated with "Applies to" naming Locations), the new-workflow chooser (nine recipes),
the builder canvas, the trigger inspector (grouped triggers, form filter, document-kind filter, all three
scopes with the Location checkbox list), the grouped step picker, and the Send email, Send booking link,
Move opportunity (pipeline then dependent stage), Create & send proposal (package, deposit %), Request
payment and If / Else (fact subjects limited to what the trigger supplies) inspectors, Test workflow, Publish
(status, Pause and Publish changes), the Settings tab, **Enrollment history**, **Execution logs**, Settings →
Text messaging "Locations that use this number" (saved), and the Proposal follow-up recipe as an editable draft.
A realistic workflow — *Form submitted (Booth enquiry form, Downtown + Uptown) → email → booking link →
move to Qualified → proposal with 30% deposit* — was created and published entirely in the UI, then run on a
contact with provider fakes: every step reported in plain words (including "There is no open opportunity to
move"). No broken script, empty dropdown, raw id, or dead control remained; the only console errors came from
the browser's own extension. Defects found and fixed during acceptance: the Enrollment history / Execution logs
tabs were placeholders (now real, read-only), and "Request payment" defaulted to a new invoice even after a
document trigger (now defaults to the journey's document). Not exercised: a phone-width layout.

## 15. Deferred (post-V1, none blocking)

* An expression language for conditions; A/B or random-split branches; AI-written steps or AI workflows.
* A document **template** model (proposals from saved templates); multi-recipient documents and signers.
* Delivering a document or payment link by text (needs a recoverable-token design).
* Charging a saved card, refunds from automations, PayPal.
* Automatic retry of a failed External step; a checkpoint that holds journeys whose pinned Location was
  later archived (they fail closed meanwhile).
* BYO sender assignment to Locations; per-node Location overrides; multi-Location fan-out.
* Workflow-level analytics and a cross-workflow activity feed.
* Mobile-specific builder layout beyond the existing responsive behaviour.

Automations V1 is closed. No planned V1 work remains in this module.
