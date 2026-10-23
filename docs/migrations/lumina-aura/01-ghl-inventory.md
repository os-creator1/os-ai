# 01 · GHL inventory (read-only export)

Source: GHL location `7HLIWryaYyed99beyIB7` ("Lumina Aura Events", company `0nKuVZCXsff0PUBzzLqJ`).
Discovered from the public site's media paths (`assets.cdn.filesafe.space/<locationId>/…`) and
confirmed by `GET /locations/{id}`. Access: the connected integration token (location-scoped).
Every call was a `GET`. GHL was not modified.

| Dataset | HTTP | Rows | Notes |
|---|---|---|---|
| Location profile | 200 | 1 | America/Denver, Thornton CO 80260 |
| Contacts | 200 | **51** | matches the supplied CSV count |
| Opportunities | 200 | **37** | 10 open · 7 won · 20 lost; summed `monetaryValue` across all 37 = 14,000; no currency set |
| Pipelines | 200 | 2 | Inquiries (9 stages), Bookings (5 stages) |
| Tags | 200 | 12 | |
| Custom fields | 200 | 11 | |
| Custom values | 200 | 25 | incl. Phone, Email, Service Location(s), legal links |
| Conversations | 200 | **45** | |
| Messages | 200 | **733** | SMS 365 (225 out/140 in) · email 94 · calls 72 · internal notes 11 · activity 189 · SMS reactions 2 |
| Users | 200 | 3 | all `admin` role |
| Calendars | 200 | 5 | 0 appointments/events in range 2020–2030 |
| Forms / Surveys | 200 | 2 / 1 | Check Availability Form (+ "2"); Event Questionnaire - Photo Booth |
| Workflows (list) | 200 | **26** | 21 published, 5 draft |
| Workflow **definitions** | **404** | – | GHL's public API exposes no workflow detail endpoint — UNVERIFIED |
| Email/SMS templates | 200 | 0 | none stored as templates |
| Funnels / websites | 200 | 5 | 4× "Photo Booth #n" website, "Booking Form" funnel |
| Media | 200 | 17 | all 17 originals downloaded (27.4 MB) |
| Phone numbers | 200 | 1 | +17205028306, `origin: twilio`, SMS/MMS/voice, default number |
| Products | 200 | 0 | |
| Invoice templates | 401 | – | token lacks scope |
| A2P / Trust Center | 404 | – | not exposed by the public API — UNVERIFIED |
| Notes / tasks per contact | 200 | – | exported |

## Data-quality findings (from the dry-run planner)

* **No duplicate phone numbers** among the 51 contacts (the planner would flag `duplicate_phone`; earliest canonical, never auto-merged). 47 have a valid E.164 phone.
* **4 contacts have no phone** (cannot receive SMS/calls), **13 no email**.
* **1 contact has `dnd: true`** although every per-channel DND entry reads `inactive`. The planner imports them as **suppressed on all channels** until a human confirms.
* No contact has a recorded SMS/email **consent** — only absence of DND. They import as consent `unknown`, never "opted in".
* Opportunities have **no currency**; the planner assumes USD and flags each one.
* GHL's own automations were **failing**: workflow emails show `Insufficient funds to send email`; workflow SMS show `Unable to send message, please contact support!` (17 failed messages in the export). Worth knowing before judging "current behaviour".

## Reproduce

```
GHL_PRIVATE_INTEGRATION_TOKEN=… GHL_LOCATION_ID=7HLIWryaYyed99beyIB7 \
  php artisan ghl:export-snapshot            # → storage/app/ghl-snapshots/<id>/ (git-ignored)
php artisan ghl:plan-import storage/app/ghl-snapshots/7HLIWryaYyed99beyIB7
```
