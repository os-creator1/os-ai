# V1 Final Lead Lifecycle Acceptance — cross-module

Branch `agent/v1-lead-lifecycle-final`, from `origin/agent/v1-completion-integration`
`6c42beb3` (Integrate: Booking Notifications V1). Database for every run:
`ultimatesms_testing_v1_lead_lifecycle` (disposable sibling accepted by `TestDatabaseSafety`).
Browser acceptance ran against a second disposable sibling,
`ultimatesms_testing_v1_lead_lifecycle_browser`. No live provider is called anywhere.

**Scope.** Not another module acceptance: this proves the journey a local business's customer
actually takes across modules, on one Photo Booth Business with two Locations (Location A /
Location B), through each module's own public seam. Integration defects only; no module redesign.

New tests: `tests/Feature/V1Acceptance/LeadLifecycle/` — 8 tests, 96 assertions, real Core plan,
real tenancy, real Location guard (the same stance as the Calendar acceptance).

## Defects found and fixed

| # | Defect | Fix |
|---|---|---|
| L1 | **Automations could not write a canonical custom field.** The only "set custom field" action wrote the legacy per-list store (`contacts_custom_field`) and the compiler refused to publish it on any trigger that is not a contact-group trigger — so a **form submitted / booking / deal-stage** workflow could never set a field, and a value an automation did set was invisible to merge fields and the Contact page. | `update_contact_field` accepts `custom_field_id` (a Business custom field) as well as the legacy `field_id`. Publish checks the field is an active Contact field of this Business and needs no contact group; the executor writes through the one canonical writer `CustomFieldValueService::applyAnswer` (type-validated, archived/foreign refused, blank skipped, idempotent). The builder drawer offers a "Business custom fields" group; the legacy per-list path is unchanged. Files: `NodeTypeRegistry`, `WorkflowCompiler`, `UpdateContactFieldNodeExecutor`, `drawer.js`, `summaries.js` (+ the committed bundle `public/js/automations/workflow-builder.js`). |
| L2 | **Stale test contradicting the CRM Location ACL.** `CustomFieldsHttpTest::…ungranted_location` still expected a 200 for a Contact at a Location the staff member was not granted; CRM acceptance 02 (D2) made it 404 on purpose. | Test updated to assert the 404 (test-only change). |

## Matrix

| Requirement | Result | Evidence | Notes |
|---|---|---|---|
| A. Embedded Form: public page frameable, submit → one Contact + one Opportunity (first stage), double submit idempotent | ACCEPTED | `LeadLifecycleJourneyTest::test_flow_a_…` | No `X-Frame-Options`; replayed token creates nothing |
| A. Custom fields from the form are canonical values on the Contact | ACCEPTED | same | `custom_field_values`, shown on the Contact page |
| A. Owner can find the lead | ACCEPTED | same + browser | CRM board and People |
| B. Public booking by the same person → same Contact, one appointment, one confirmation | ACCEPTED | `test_flows_a_b_d_…` | Identity is the phone as typed/normalised **within the Location** |
| B. Calendar shows it; confirmation recorded once | ACCEPTED | same + browser | |
| C. Create deal (CRM form) → drag stage (double drop) → edit → staff-book an appointment | ACCEPTED | `ManualCrmAndLocationJourneyTest::test_flow_c_…` | Deal takes the Contact's Location; one stage change = one run |
| D. Form submitted / appointment scheduled / stage changed → canonical Business Automation fires once; replay changes nothing | ACCEPTED | journey tests + existing `AutomationsV1JourneyTest` | tag, move-deal and set-field actions verified; email/SMS/proposal/payment actions by the existing journey |
| D. Set custom field from automation | FIXED + ACCEPTED (L1) | `CanonicalCustomFieldsJourneyTest` (4 tests) | |
| Custom fields: Forms / Automations / Contact page / merge fields agree | ACCEPTED | `CanonicalCustomFieldsJourneyTest::test_form_automation_contact_page_…` | One store: `custom_field_values` |
| Two Locations: leads, deals, bookings, form deployments, staff access never mix | ACCEPTED | `test_two_locations_…`, `test_a_form_switched_off_…` | Same phone at A and B = two Contacts by contract; booking at B reuses B's lead, creates nothing at A; staff granted only A get 404 on B's contact/deal/schedule/appointment and do not see B's deal on the board |
| Mobile 375px: CRM board, Contact, Calendar, Form | ACCEPTED | browser | No page-level horizontal overflow (the board's stage columns scroll inside their own container). Live form submission created a Contact and a deal |
| Mobile 375px: Conversation | ACCEPTED (empty state) | browser | A lead with no messages has an empty list — correct |
| Builder drawer (L1) | ACCEPTED | browser | Lists per-list and Business fields, preselects the saved one, saves `custom_field_id` |

## Deferred / not changed (none blocking; not invented)

1. **Website-builder form (legacy, separate from Forms V1)** raises no `form_submitted` event, so no
   form-triggered workflow fires for a website quote form (it still creates the Contact and the
   deal, and `opportunity_created` fires), and it maps only `name`/`phone` — event date/type stay in
   the submission row, not custom fields. By design today (`WebsiteFormSubmissionService`
   documents it); unifying it with Forms V1 is a module decision, not an integration fix. This
   acceptance walked the Forms V1 embed (the iframe snippet the Forms module offers per Location).
2. **A public booking is not tied to the lead's deal** (`appointments.crm_opportunity_id` stays
   NULL on the public path). Not required for the journey: booking automations re-find the
   Contact's single open deal (`MoveOpportunity` refuses an ambiguous one). Linking is a new
   feature.
3. **Same person typing the phone differently across modules** (`+1 (415) …` vs `415-…`) is a
   different stored identity by the existing rule (CRM acceptance D5 / Calendar H); the form and
   booking only reuse the Contact when the normalised number matches.
4. **Subscription status asymmetry:** a Contact created by a Form is `unsubscribed` (anonymous
   inquiry, no consent); one created by a booking is `subscribed`. A form lead who later books
   stays `unsubscribed`, so SMS from automations/reminders is skipped for them (email is
   unaffected). Consent semantics are a product decision.
5. **Contact timeline** is per conversation (Conversations module); the form submission, deal and
   appointment appear there only once a conversation exists, and appointments/deals are not
   timeline sources.
6. **`AppointmentCompleted` / `AppointmentNoShow`** are not Automation triggers.
7. Per-Location **sender** isolation is covered by the existing Automations Location suites
   (`tests/Feature/Automations/Workflow/Location`), not re-proved here.

## Evidence

* New: `tests/Feature/V1Acceptance/LeadLifecycle` — 8 tests / 96 assertions, all pass.
* Regression of touched code: `tests/Feature/Automations/Workflow` + `tests/Feature/CustomFields` —
  810 tests; the one failure (L2, stale expectation) is fixed and `CustomFieldsHttpTest` is 11/11.
* A second broad run (V1Acceptance, Forms, Crm, Calendar booking, WebsiteForm) was stopped on
  request after 293 passing tests and 0 failures; it was not completed and is not claimed as a
  full pass.
* Browser at 375 px (disposable DB, local server): CRM board, Contact, Calendar week, public Form
  (live submit), Conversations; builder drawer at desktop width.
