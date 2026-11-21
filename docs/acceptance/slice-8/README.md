# Slice 8 — guided generation for a non-Photo Booth niche (Tutoring)

Question: can a Tutoring owner complete a questionnaire-driven setup, generate, preview, edit, publish, reload, and receive a real inquiry?
Answer: **yes — and before this slice they could not even start**: a Tutoring business resolved to *no* setup questionnaire (the wizard showed "setup unavailable")
and was offered *no* website looks (the four designs belong to Photo Booth). Fake AI only; copy quality is not claimed.

## Photo Booth assumptions that blocked Tutoring, and what changed

| Blocker | Change (product behaviour) |
|---|---|
| No setup questionnaire for the niche (`QuestionnaireResolver` found none) | `TutoringWebsiteSetupQuestionnaireSeeder`: a versioned definition (niche `tutoring_exam_prep`) written as data over the wizard's existing question types. It asks what the Tutoring blueprint's own website intent asks for (subjects/grades, exam preparation, how a first lesson works, real testimonials only, one-on-one/small groups only if true). Lesson presets are the blueprint's own student-form subjects. No Photo Booth label, slug, presentation-answer key or form-field step; the visitor form is the blueprint's `tutoring_form_student_inquiry`. |
| No looks offered (`WebsiteTemplate` rows are niche-bound; all four belong to Photo Booth) | Four niche looks (`tutoring_modern / editorial / luxury / conversion`) in `WebsiteTemplateSeeder::nicheTemplates()`, mapped to the four existing structural designs in `WebsiteDesigns` (no new CSS). |
| Neither is installed for a niche automatically | `blueprint:seed-niche tutoring_exam_prep` now seeds both, idempotently, the way Dog Boarding already does. |

Everything else — shared-fact steps, services/areas/FAQ/testimonial/custom-section application, niche-neutral URLs, the lead-form binding, publishing, SEO — was reused unchanged.

## Tests (`tests/Feature/Website/Acceptance/TutoringGuidedSetupAcceptanceTest.php`, 4 tests)

1. The wizard serves the seeded Tutoring questionnaire (no Photo Booth step keys) and starts on the niche's own first look.
2. **The owner journey**, over HTTP: answers every screen -> Review offers only the four Tutoring looks -> Generate -> real Services/knowledge facts -> niche-safe page plan -> Preview of every page (one H1, `noindex`, no invented claim) -> the owner's own FAQ and "how a lesson works" text verbatim -> contact page = the blueprint's student form + phone/email -> an owner edit -> publish (domain 404s before, serves the snapshot after) -> links/CTAs resolve -> generated pages stay `noindex` until the owner releases them, then are indexable -> a later draft edit stays out of the published site -> titles, descriptions, canonicals, `LocalBusiness`, breadcrumbs, sitemap -> **a visitor submits the published student form through the real Forms pipeline**: one submission (a replay creates none), one Contact, one Opportunity in *Student Enrollment / New inquiry*, no appointment, thank-you without personal data -> each of the four looks keeps every page, the edit and the form, and publishes its own design.
3. The niche seed is idempotent and leaves Photo Booth looks/questionnaire untouched and installs no Dog Boarding looks.
4. A Photo Booth business still gets the Photo Booth questionnaire and looks while the Tutoring niche is seeded.

Shared fake-AI code moved to `tests/Feature/Website/Concerns/FakesGuidedWebsiteAi.php` (used by `PublishesGeneratedSites`, behaviour unchanged).

## Visual evidence

`tutoring-home-4templates-390.jpg`, `tutoring-contact-4templates-390.jpg`, `tutoring-faq-4templates-390.jpg`, `tutoring-home-4templates-1440.jpg`, `tutoring-contact-4templates-1440.jpg`
(modern, editorial, luxury, conversion left to right; 1440 px at 40%). Rendered by the test (`ACCEPTANCE_REPORT_DIR=/tmp/x`), served statically, measured in Chromium:
all 24 renders (4 looks x home/contact/FAQ x 390/1440 px) have 0 px overflow, one H1, tel and mailto links, and the contact page embeds the lead-form frame.
The "Error response 404" box inside the contact captures is the static server lacking the Forms frame, not a site defect (the test asserts the frame, deployment uid and the real form page).
Copy such as "Book … for your event", the booth-worded home FAQ and the hash suffixes in titles come from the **shared fake AI**, not the product.

## Limitations

* Fake AI: nothing here assesses real copy quality, and the generated home FAQ/service blurbs are fixture text.
* The Tutoring questionnaire has no pricing/packages step (no price is ever invented), no photo requirement, and no booking-type step: inquiries come through the student form; the optional consultation booking type from the blueprint is not wired into the wizard.
* Per-area pages are intentionally not generated (areas are mentioned, never given a page).
* The owner switches the student form on in Forms (as for every niche); until then generation warns and shows phone/email only.
* Dog Boarding's end-to-end inquiry tests (4) fail identically on a clean `main` in this environment; they are not part of this change.
