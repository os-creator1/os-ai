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

## Improvements over GHL's pattern (built)

* **Reply detection** – `end_if_replied` (If/Else on `contact.replied_since_enrollment`) before every follow-up.
* **Consent** – `end_if_unsubscribed` (`contact.subscribed`) before outbound steps; imported contacts with DND are suppressed.
* **Follow-up cancellation** – `end_if_tagged` (e.g. Questionnaire Submitted stops reminders).
* **Duplicate events / retry safety / execution logging** – inherited from the engine's at-most-once claim and run log; nothing re-implemented.
* **Imported records never trigger automations** – planner emits `triggers_automation: false` on every record.

## Gaps that need an engine change (own contract)

1. Business-hours and quiet-hours condition subject + send-time deferral.
2. Task/"call the lead" node.
3. Merge field for the questionnaire / review link.
4. Missed-inbound-call trigger.
