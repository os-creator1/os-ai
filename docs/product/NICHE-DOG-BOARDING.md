# Dog Boarding niche blueprint

A customer-facing niche for a dog boarding / daycare business that uses MotionGrove for **its own** website,
inquiries and growth. It is unrelated to the Dog Business white-label marketing-site preset (PRs #624/#625).

Identifier: `dog_boarding` (vertical-bound, like Tutoring and Kids Ceramics; the `BusinessIndustry` enum is locked).
A business selects it as its **Business specialty** (Knowledge Profile `vertical_key`).

## Install (operator, once per environment)

```bash
php artisan blueprint:seed-niche dog_boarding
```

Idempotent. It ensures: the `dog_boarding` vertical row, three website templates, the versioned setup questionnaire
(`dog_boarding_website_setup`) and the published Niche Blueprint version. It deploys nothing and sends nothing.

## What a business receives (Blueprint, installed through the existing installer)

| Area | Installed |
|---|---|
| CRM pipeline "Stay Requests" | New Inquiry, Awaiting Pet Details, Meet & Greet / Assessment, Pending Approval, Booking Confirmed, Stay Completed. Declined / canceled / closed = the Opportunity's **lost** status (canonical CRM semantics). Stages stay owner-editable. |
| Contact custom fields | Dog name, breed, size, number of dogs, requested arrival / departure, requested services, preferred contact method |
| Forms module forms | "Request a Stay", "Request a Meet & Greet" |
| Tags | Boarding inquiry, Meet & greet requested, Confirmed stay, Past boarder, Review requested, Pet details needed |
| Automation (all **drafts**, never published on install) | New Stay Request, New Meet & Greet Request, Missing Pet Details, Meet & Greet Scheduling, Pending Approval Review (internal only), Stay Confirmed, After the Stay |
| Booking type | "Meet & greet" (30 min). Installs only where the `calendar` feature is available; it is currently *Planned*, so the installer reports `skipped_unavailable`. No provider is assumed connected. |
| SEO / citations | Keyword patterns (`dog boarding {city}` …), FAQ topics, schema notes, citation recommendations |

Installation records template defaults separately from owner changes (existing installer behaviour); re-running it never
duplicates a pipeline, form or workflow and never overwrites an owner-customised one.

## Website generation (deterministic, no AI call)

`QuestionnaireResolver` resolves a business to its niche questionnaire from its active `vertical_key` first, otherwise from
its industry exactly as before. The Dog Boarding writer (`DogBoardingSiteBlueprint`) fills the planner's page set:

Home · Our services · one page per service (e.g. Overnight Dog Boarding, Dog Daycare) · Rates & Packages · About Us ·
Gallery · FAQs · Boarding Policies · Request a Stay.

Fixed pages use niche addresses (`about-us`, `faqs`, `request-a-stay`) through `WebsiteNicheVocabulary`; no `photo-booth-*`
address is ever published for this niche. Three CSS-backed looks are offered (Warm Kennel, Fresh Field, Happy Tails).

Copy is composed only from the owner's answers. A section with nothing real behind it is omitted. Policies, vaccination
rules, supervision, capacity, credentials and prices appear **only as the owner typed them**.

## Inquiry and booking truthfulness

* The public form is the website's `quote_request` form, named "Request a Stay". It routes into the Stay Requests pipeline
  and creates a contact (matched by phone) and one opportunity at the business's location.
* A repeat submission from the same person while a request is open is recorded but **does not open a second opportunity**.
* A stay is a **request** the owner confirms. The site never shows availability, never says "instant" and never claims a
  confirmed stay. MotionGrove has no kennel-occupancy data and none was invented.
* The form deliberately has no medication / medical / behaviour field. Private care detail is collected later from a known
  contact. Submission data is private to the business's workspace.

## Not supported (blockers, stated plainly)

* Real-time availability or capacity: there is no authoritative occupancy source.
* Automated pre-stay reminders **by date**: the workflow engine has no "N days before a custom date" trigger; the pre-stay
  message is sent when the owner moves a request to Booking Confirmed.
* Mapping the public website form's fields onto contact custom fields: the website form stores the submission privately; the
  Forms-module form (which does map custom fields) is not embeddable in the generated site today.
* Calendar booking until the `calendar` feature is available.
