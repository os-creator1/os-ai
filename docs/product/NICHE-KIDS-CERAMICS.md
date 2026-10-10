# Niche Blueprint — Kids Ceramics Studio / Kids Ceramics Classes

Key and **vertical** `kids_ceramics`, an `business_verticals` catalog row bound to the Blueprint by `vertical_key` (broad-industry bucket `other`; the `BusinessIndustry` enum is locked and has no kids-activities case).
A Business receives it when its Knowledge Profile's vertical is `kids_ceramics`. Seeded with `php artisan blueprint:seed-niche kids_ceramics` (it ensures the vertical row first).
Definition: `App\Library\NicheBlueprint\Niches\KidsCeramicsBlueprint`. Contracts: 25, 26.

A **local, physical** Business: **one** customer pipeline and **one** acquisition purpose (Class Enrollment). There is **no** teacher or recruitment
funnel, label, question or page anywhere in it (a test scans the installed data for the words).

## Components

| Key | Type | Installs |
|---|---|---|
| `ceramics_class_pipeline` | `crm_pipeline` | **Class Enrollment**: New inquiry (`new_inquiry`) → Contacted → Trial class booked → Attended. Enrolled/purchased = the Opportunity's *won* status, Lost = *lost* (canonical CRM semantics). |
| `ceramics_form_class_enquiry` | `form` | Class enquiry (parent name, phone, email, child's age, class interest, message), `create_opportunity` into Class Enrollment (draft). |
| `ceramics_purpose_class_enrollment` | `acquisition_purpose` | The goal: economics questions, local-first guidance, website intent (pages below). |
| `ceramics_booking_trial` | `booking_type` | Trial class (inactive on install). |
| `ceramics_seo` | `seo_strategy` | Local patterns with a `{city}` placeholder — `kids ceramics classes {city}`, `pottery classes for children {city}`, `ceramics birthday workshop {city}`, `after-school ceramics {city}`, `kids activities near me` — filled from the Business's real Location, never a seeded city. |
| `ceramics_citations` | `citation_recommendations` | Bing Places, Apple Business, Facebook Page (recommended), Foursquare (optional). |
| tags / custom fields | | Parent, Birthday workshop, Holiday workshop, Trial class…; "Child age", "Class interest". |

## Economics (calculator `class_enrollment`)

Typical class/session revenue · direct variable cost per session · enrolment **one-off or recurring** · typical paid sessions *(only if recurring)* ·
qualified-inquiry→booking rate · target / hard CPL · target / hard CAC — each with "I don't know yet". `units = 1` for one-off enrolment, so a workshop studio is **not**
forced into recurring-student economics; recurring multiplies by the owner's typical sessions. Nothing numeric is in the Blueprint.

## Emphasis (guidance carried by the goal)

1. **Google Business Profile** and local presence first (address, hours, photos, services, identical name/address/phone everywhere); 2. the **Website**; 3. **local SEO**;
4. **reviews**; 5. **booking** (a clear trial-class button); 6. **citations**; 7. **rank tracking** (existing SEO module); 8. **Meta Ads are optional** demand generation —
judged by enrolments, not clicks.

## Website intent

Pages: Kids ceramics classes · Trial class · Birthday ceramics workshops · Holiday / school-break workshops (only if run) · About the studio · Location & contact (clear physical address, hours, map, booking CTA).
Hosted sites receive these as generation prompts; **external sites are fully supported**: the audit runs unchanged and the same intents appear as acquisition recommendations
("Class Enrollment has no dedicated landing page") and niche page suggestions, separate from technical findings, so technical facts and niche opportunities are never mixed.

## Deferred

A kids-ceramics hosted template and questionnaire; a holiday-workshop automation; GBP-specific recommendation rules beyond the existing GBP/Citations modules; Meta/Google campaign creation.
