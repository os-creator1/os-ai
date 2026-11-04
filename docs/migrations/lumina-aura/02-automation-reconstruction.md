# 02 · Automation reconstruction

## What could and could not be read

* `GET /workflows/` lists all **26** workflows (name, status, version, timestamps). 
* **No definition endpoint exists** in GHL's public API (`/workflows/{id}` → 404 for all 26).
  Triggers, actions, branches and delays are therefore **UNVERIFIED** from the API.
* The screenshots of *New Inquiry Received* were referenced in the brief but **were not
  attached to this session**, and no authorised browser session to the GHL UI exists here.
  The only description of that workflow available is the one in the brief; it is used below
  and marked *per brief, unverified*.
* Observed behaviour is recoverable from message history: 189 activity rows and 365 SMS /
  94 email rows carry `source: workflow`, subject lines and bodies, which verify *what was sent*
  (not the branching that led there).

## The 26 GHL workflows

| # | GHL workflow | GHL status | MotionGrove target | State |
|---|---|---|---|---|
| 1 | 1. New Inquiry Received (v4) | published | `pb3_wf_new_inquiry` | draft · logic **per brief** |
| 2 | 2. If Moved to "Didn't Pick Up" Stage | published | `pb3_wf_missed_call` (Missed Call Follow-Up) | draft · logic inferred from name |
| 3 | 3. If Moved to "No Contact Made" Stage | published | `pb3_wf_inquiry_followup` | draft · inferred |
| 4 | 4. If Moved to "Nurture" Stage | published | `pb3_wf_inactive_nurture` | draft · inferred |
| 5 | If Moved to "Proposal Sent" Stage | published | `pb3_wf_proposal_followup` | draft · inferred |
| 6 | 5. Lost | published | — (a Lost deal is a status; no outbound by default) | **needs GHL definition** |
| 7 | 6. Payment Confirmed / 6.1 Payment Failed | published | trigger `payment_succeeded` exists in engine; not packaged | **needs GHL definition** |
| 8 | 7. Booking Comfirmed (Sends Questionaire) | published (v3) | `pb3_wf_booking_confirmed` | draft · inferred |
| 9 | 8. Questionnaire Submitted | published | `pb3_wf_questionnaire_submitted` | draft · inferred |
| 10 | Reactivate Interest | published | `pb3_wf_inactive_nurture` (partial) | needs definition (tag `sms 1 reactive interest responded` suggests reply handling) |
| 11 | 1. Post Event Guest SMS | **draft** | — | not migrated: guest-facing SMS needs consent design |
| 12 | Missed Call Text-Back Automation | **draft** | — | not migrated: no inbound-missed-call trigger in engine |
| 13–23 | "… Pipeline (add tag)" ×9, Cold/Didn't Pick Up/Follow Up/In Contact/Lost/No Contact/Nurture/Proposal Sent | published | **retired**: GHL used workflows only to tag by stage. MotionGrove stage-trigger workflows replace the tag-for-stage pattern; the tags are still imported | by design |
| 24–26 | Brochure Page Opened / Proposal Viewed / Proposal Signed Notification; two unnamed drafts | published / draft | document triggers exist (`document_sent`/`document_signed`); not packaged | needs definition |

Nothing is activated. Every installed workflow lands as a **draft**; the owner publishes it.

## New Inquiry Received — brief vs MotionGrove

| Brief (unverified) | `pb3_wf_new_inquiry` | Notes |
|---|---|---|
| Form submitted trigger | `form_submitted` | Dedupe is the engine's per-contact enrollment policy |
| Opportunity create/update | Form's `create_opportunity: true` | Form component, not a workflow step |
| Contact tagging | `add_tag Inquiry` | |
| Internal notification | `internal_notification` | |
| Business-hours check + branches | **GAP** | No business-hours condition in the engine. Not faked. |
| Automatic email | `send_email` (after consent guard) | |
| Delay before SMS | `wait 5 minutes` | |
| SMS confirmation | `send_sms` | SMS executor enforces opt-out, billing, provider |
| Manual call task | `internal_notification` (day 1) | No task node in the engine |
| After-hours follow-up | **GAP** (needs business-hours condition) | |

## Engine capabilities added for the migrated workflows (built and tested)

| Requirement | Implementation |
|---|---|
| Business hours | `BusinessSendWindow`: uses the primary Location's recorded `hours`; **if none are recorded, "unknown" is never treated as closed**. Lumina's GHL account holds no hours, so business-hours gating is inert for Lumina until the owner enters hours. |
| Quiet hours | Always on for pack messages: nothing is sent 21:00–08:00 local (`config/send_window.php`). DST-safe (tested across both 2026/2027 transitions). |
| America/Denver | Read from the Business timezone (set at provisioning); the unit tests assert Denver local-time behaviour, including that 15:00 UTC is 08:00 local. |
| Wait for a good time | Wait node mode `until_send_window` (builder summary updated). |
| Stop on reply | `contact.replied_since_enrollment` guard, before every follow-up. |
| Stop on opt-out | `contact.subscribed` guard + the existing SMS executor's own opt-out enforcement. |
| Stop on booked / lost | new `contact.has_open_deal` fact: a won or lost deal ends the journey. |
| Stop when a questionnaire arrives | tag guard (`Questionnaire Submitted`). |
| Manual call tasks | `contact_tasks` table + `ContactTaskManager`; the notify step can carry a `task` (title, due-in-hours). Idempotent per enrollment+step; visible on the contact timeline. **No task inbox page or completion UI yet** (`ContactTaskManager::complete()` exists). |
| Review / questionnaire links | merge fields `{{business.review_link}}` (saved review link) and `{{business.questionnaire_link}}` (first active form named "questionnaire"); `end_if_no_review_link` / `end_if_no_questionnaire_link` guards mean a message that promises a link is never sent without one. |
| Retries and logs | engine at-most-once step claims (existing); new `FailurePolicy::Continue` so one failed channel (e.g. no mailbox) is logged and the journey carries on to the next step instead of dying. Replaying a finished journey sends/creates/logs nothing (tested). |
| Imported records never trigger automations | the importer writes rows directly: no domain event, no queue, no enrolment (tested: 0 enrollments, nothing pushed). |

All 11 workflows install as **drafts**; `published workflows = 0` is asserted by the provisioning safety audit.

## Verified by execution (not just installed)

`tests/Feature/Migration/PhotoBoothAutomationsRuntimeTest` publishes the real workflows and drives them with the real advancer and wake sweep under a frozen America/Denver clock; only the provider edge is a recorder. Covered: New Inquiry tagging + call task + one text after the delay; reply cancelling the text and the second task; opt-out contact never messaged by six different workflows; 22:30 enrollment held and sent at 08:00 local; won deal stopping follow-ups; links rendered only when set up; replay safety; a real stage move enrolling the published workflow.

## Still not reproducible from GHL

* **Browser inspection of GHL was not possible.** No authenticated GHL browser session exists in this environment and signing in would need the owner's credentials/MFA; I did not attempt it. Workflow definitions therefore remain **unverified**, and the per-workflow "inferred" labels above stand. The *New Inquiry Received* screenshots were not attached.
* "After-hours follow-up" in the brief maps to quiet hours + (when set) business hours; the exact GHL after-hours branch text is unknown.

## Form bindings and guard timing (review fixes)

- **New Inquiry Received** starts only from the pack's `Check Availability` form and **Questionnaire Submitted** only from the `Event questionnaire` form (`trigger_form` binds each `form_submitted` workflow to one form by name). The questionnaire is a single-page form, so the multi-page-only `questionnaire_submitted` trigger could never fire for it, and an unbound `form_submitted` would have treated a questionnaire response as a new inquiry.
- Every reply / opt-out / closed-deal / tag guard now sits **after** each send-window wait, immediately before the message, and every send is preceded by an opt-out check after the last wait. A reply, opt-out or won/lost deal that lands while a message is parked until morning cancels it (`PhotoBoothAutomationsRuntimeTest`).
