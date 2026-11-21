# Slice 9 — Tutoring consultation booking and confirmed service areas

Builds on Slice 8 (questionnaire-driven Tutoring setup, four looks, student inquiry form). Fake AI only; no copy-quality claim.

## Product changes

| Area | Change |
|---|---|
| **Booking setup** | The owner can configure the consultation booking type the Tutoring blueprint installs (inactive, nobody assigned). New `booking_type` wizard target module and `Website\Setup\ConsultationBookingSetup`: found through the business's own blueprint installation record (never by name), it applies only what the owner answered — length, notice, days, a daily window for themselves (their own weekly hours), assigns the owner through the Calendar's own eligibility rule — and switches the type on **only if** `BookingTypeManager::publicBookingReadiness` says a guest would reach a working page. Otherwise it stays off, and the owner is told why in the Generate message. "No" changes nothing. Idempotent; nothing is created or deleted. |
| **Booking CTA** | Unchanged resolver (`WebsiteCtaResolver`): booking first, then the contact page with the student form, then phone/email. The site shows "Book now" only when the live booking type is really bookable; otherwise the student inquiry form stays the main path. |
| **Questionnaire** | Published as a NEW version of the Tutoring definition (sessions mid-setup and generated sites keep theirs): five optional booking questions (one choice screen, one details screen), and the service-area list now collects owner-confirmed local details instead of "mention only". |
| **Area pages** | Only for an area with owner-confirmed details (existing `LocalAreaUsefulness` rule: the base city and bare names never get a page). The local-details panel is reworded for the niche through a new validated `area_labels` step setting (defaults unchanged for Photo Booth). |
| **A Photo Booth assumption removed** | Every area page ended with "Planning an event in X?" (`MediaBindingService::linkAreaPages`). A niche with the neutral vocabulary (Tutoring) now gets "Ready to get started in X?"; Photo Booth keeps its wording. |

## Tests (`Acceptance/TutoringBookingAndAreasAcceptanceTest`, 5 tests)

1. **End to end:** setup (booking + one confirmed area) -> Generate (message says booking is on) -> the SAME installed booking type is active with the owner as staff, Monday/Wednesday hours -> only `serving-hattiesburg-ms` is a page (Laurel is only listed, Petal is the base city) -> publish -> area page: own title, self canonical, indexable after release, breadcrumbs, sitemap entry, owner's venue/detail verbatim, links to contact and services, linked from home; `/serving-laurel-ms` 404 -> "Book now" on home, contact and area pages points to the real booking page, the student form is still on /contact -> a visitor books through the published page (dates and slots only on the owner's days, 201, one appointment, one Contact; the same time cannot be booked twice).
2. Booking never configured: no booking link anywhere, `/book/...` 404, the form is the main button.
3. "Yes" without any day: booking stays off, the owner is told "Choose the days...", the form stays the main button.
4. "No" changes nothing; the service reports not-ready / not-installed honestly and creates nothing.
5. The definition validator accepts the shipped steps and refuses malformed booking fields and misplaced `area_labels`.

## Evidence

`tutoring-home-4looks-390.jpg`, `tutoring-area-4looks-390.jpg`, `tutoring-contact-4looks-390.jpg`, `tutoring-home-4looks-1440.jpg`, `tutoring-area-4looks-1440.jpg`
(modern, editorial, luxury, conversion left to right; 1440 px at 40%). Rendered by the test (`ACCEPTANCE_REPORT_DIR=/tmp/x`), served statically, measured in Chromium:
all 24 renders (4 looks x home/area/contact x 390/1440 px) have 0 px overflow and one H1; "Book now" appears in the top bar and footer of every look; the contact page keeps the lead-form frame.
The embedded form shows a 404 box in static captures only (no Forms frame is served). Hash strings ("Ref 82e9…") and "We have set up at…" lines come from the shared fake AI.

## Limitations

* The blog's closing call to action still says "Planning an event?" for every niche (existing shared blog copy, out of scope here).
* The booking questions configure only the owner as the person taking consultations (more staff, Zoom/phone meeting types and holiday rules stay in Calendar).
* "No" does not turn off a booking type the owner already runs in Calendar.
* Fake AI: nothing here assesses real copy quality.
