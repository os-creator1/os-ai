# Photo Booth: three deterministic reference templates (implementation and acceptance)

## Scope and fidelity claim

These opt-in **reusable** templates follow the visual direction and section ordering of three business reference sites. They do not copy external HTML, CSS, photographs, logos or another business's factual claims. Client-owned/authorized assets are required. Three separate identities are used, without altering any existing website template:

| Template key | Owner-facing choice | Structural reference | Family |
|---|---|---|---|
| `photo_booth_reference_kaleb` | Luxury Photo Booth | https://kalebhillphotobooth.com/ | Black/gold, cinematic events |
| `photo_booth_reference_booths` | Event Spotlight | https://www.boothstothemax.com/home | Navy/blue, events and booth features |
| `photo_booth_reference_greenbelt` | Party Editorial | https://greenbeltphotography.com/ | Plum/peach, editorial packages and corporate |

> **Update:** see [`ACCEPTANCE-MATRIX.md`](ACCEPTANCE-MATRIX.md) (page-by-page fixture comparison, screenshots, remaining differences) and
> [`QUESTIONNAIRE-WALKTHROUGH.md`](QUESTIONNAIRE-WALKTHROUGH.md) (the exact questions and answers used). The statements below that the wizard lacks hero/heading inputs and that
> no browser comparison exists are superseded; authentic client-account acceptance and pixel-level parity are still **not** certified.

**Status:** Code supplies fixed section order, reusable component styling, and Forms-module inquiry integration. **Pixel-level visual parity, full source-page equivalence, and live account testing are not yet certified.** Completing those requirements is a separate, mandatory browser comparison and production-readiness gate; GitHub CI tests alone cannot prove it.

Source structure already inventoried in the repository:
- `docs/migrations/customer-websites/kaleb-hill/website-content.json` — 13 reference pages, 10 home sections
- `docs/migrations/customer-websites/booths-to-the-max/website-content.json` — 13 reference pages, 11 home sections
- `docs/migrations/customer-websites/greenbelt/website-content.json` — 9 reference pages, 14 home sections

These migration assets are **reference inventories**, not permission to overwrite an existing customer's owner-edited website. Use them to verify section intent, URL semantics, and the *owner-confirmed* source facts. The guided generator currently decides its own page budget and may omit pages with insufficient confirmed facts; it cannot yet promise a 13- or 9-page 1:1 recreation.

## Wizard operation: precise screen-by-screen instructions

Select the desired opt-in reference choice at **Website → Change template** or initial website setup. Start a **new draft or authorized rebuild**; never overwrite a published revision. The steps below refer to `PhotoboothWebsiteSetupQuestionnaireV3Seeder::steps()`, not a hypothetical wizard. An existing questionnaire response remains version-pinned and might show the older v1/v2 screens; migrating those answers must be an explicit separate action.

| Screen / stored answer key | What to enter / do | What this controls |
|---|---|---|
| Confirm business details — `business_name`, `phone`, `email`, `logo` | Use the business's own confirmed name, phone, booking email; upload **its own transparent logo** | Header, footer, contact schema |
| `base_location` | Real city, state and postal region, not a popular nearby city unless that's actually the base | Local headline and service-area context |
| `service_area_cities` | Enter genuinely served cities/areas in preferred priority order; optional real venues, logistics and city-specific photos | Area mentions and eligible local pages |
| `booth_services` | One item per **real** type of booth. Add truthful feature description and an authentic photo showing that exact booth; mark visible | Booth/service cards and service detail |
| `event_types` | Select only events actually served; add a real event-specific description if requesting an event landing page | Event cards and event pages |
| `pricing_strategy` | `fixed_packages`, `starting_from` or `custom_quote` — **must match public pricing** | Package blocks and price wording |
| `packages` | For fixed/starting price: select real active packages in Packages & Products; provide actual amounts/currency and images | Pricing cards and package page |
| `addons` | Only authentic optional services/equipment, with actual descriptions/prices | Add-on blocks |
| `offers_backdrops` / `backdrops` | Choose yes only if backdrops are offered; list each available backdrop and its actual photograph | Backdrops page/cards |
| `gallery` | Upload event images the owner has rights to use; choose an intentional **home cover image**. Use diverse crops at desktop/phone widths | Hero, gallery, image/text blocks |
| `photo_rights` | Confirm only after verifying ownership/permission for *every* photo | Asset provenance |
| `brand_personality` | Choose actual desired tone: `elegant`, `bold`, `playful`, or `warm`; **select a reference template explicitly** so hint defaults do not silently swap the design | Copy tone, not the locked reference layout |
| `about_story` | Owner-approved factual About copy, including real differentiators and the team's role | Intro/story block and About |
| `advantages` | Only confirmed advantages (attendant, prints, custom design etc. if actually included) | Feature/benefit cards |
| `testimonials` | Enter verbatim genuine reviews and attributable names only with permission; otherwise leave empty | Real proof, never fabricated |
| `primary_cta` | The current v3 dropdown includes `quote_request`, `call`, `book_now`. For these templates choose **quote_request**; actual “Check Availability” presentation is determined by an active, verified Forms lead form, not the dropdown wording | Routing intent; the live form decides the actual CTA |
| `contact_form_fields` | Select name, phone, email, date, type, location, message as appropriate; **these are legacy Website-form fields**, not permission to edit the existing Forms module form | Existing wizard form choice; Forms-module `pb3_form_lead` configuration remains authoritative |
| `faq_items` | Edit each question/answer against actual policies, travel fees, setup and availability; reject invented guarantees | FAQ content |

**Critical form preflight:** install the Photo Booth Business Pack v3 `pb3_form_lead` or compatible v2 `photo_booth_form_availability`; confirm the form belongs to the current Business, activate it, and enable its **Website-source** deployment on an active Location through the Forms module. **Only an eligible live form earns the “Check Availability” CTA** and a `forms_module_form` embed. No legacy Website-owned duplicate form may be fabricated by these reference templates. Submitting this form is an **inquiry**, not a confirmed booking or date availability guarantee.

**Current wizard limitations:** It does **not** have independently named `hero_headline`, `hero_subtitle`, or per-block editorial heading questions. Exact text from the source sites still requires deliberate draft-editor changes, or a future backward-compatible wizard extension. The generator currently uses some safe fallback headlines and can omit sections when supporting facts/photos do not exist. These omissions are not visual parity.

## Verified-source example choices (NOT auto-applied to client accounts)

The values below come from the repository's customer-site migration inventories. **Verify each one with the owner before using it.** Do not copy their values to unrelated customers.

| Wizard item | Kaleb-style reference | Booths-style reference | Greenbelt-style reference |
|---|---|---|---|
| Business name | Kaleb Hill Photography | Booths To The Max | Greenbelt Photography |
| Base | Petal, Mississippi | Orange County, California area — obtain exact primary city | Boise, Idaho |
| Service/booth | Magic Mirror Photo Booth | DSLR photo booth rental | Luxury photo booth rental |
| Event focus | Weddings, corporate events, parties | Weddings, corporate events, birthdays, private celebrations | Weddings, corporate events, birthdays, school dances |
| Suggested service areas (confirm) | Petal, Hattiesburg, Ocean Springs and other truly served Mississippi cities | Orange County, Anaheim and other truly served cities | Boise, Meridian, Eagle, Nampa, Caldwell, Kuna |
| Site section emphasis | Hero → owner story → booth → inclusions → real gallery → reviews → areas → FAQs → Check Availability | Hero → intro → booth → features → event types → gallery → reviews → areas → FAQs → Check Availability | Hero → intro → event categories → differentiation → reviews → packages → corporate feature → gallery → FAQs → areas → Check Availability |
| Brand/accent | Black/bright gold | Navy/blue | Deep plum/peach |
| Pricing | Only owner-confirmed packages or custom quote | Only owner-confirmed package facts | Only owner-confirmed current catalog pricing |
| Hero/media | Authorized Magic Mirror event imagery | Authorized event/DSLR-booth imagery | Authorized real booth and corporate imagery |

**Do not copy** the business's existing source-site legal texts, claims, testimonials, logos, or photographs into a different business's website. In particular, Greenbelt's inventory reports missing usable legal text/links: owner-provided Privacy Policy and Terms must be confirmed before publishing forms that collect visitor data.

### Required images and content to reach high fidelity

The current v3 wizard needs: 1 logo, at least 1 deliberate homepage hero/cover, at least 1 photo per visible booth, 4–8 distinct event photos for the homepage/gallery, the genuine backdrop photos if backdrops are enabled, and optionally package photos. Without correct originals, the renderer cannot reproduce image composition or crop. Keep original dimensions and license/owner permission; verify appearance at all viewports. Image counts are **operational recommendations**, not evidence the existing sources have exactly that many assets.

For the three source designs, reproduce the original page types where factual content allows: Home, booth/service, event landing pages, Gallery, Backdrops when offered, Service Areas, FAQ, Check Availability, Packages where applicable, About when the plan includes one, and legal pages where owner text is available. Do not automatically create dozens of near-identical city pages.

## Native Blog visibility

The native Website Blog already supports `/blog`, `/blog/{slug}`, published-article SEO, same-brand template rendering, and noindex rules for preview. In these **three opt-in templates**, the **Content Autopilot/Content Articles enabled** flag also controls the Blog navigation and homepage related-guide entry **when at least one published article exists**. Turning it off hides discovery; already-published article URLs remain accessible and in the sitemap to avoid destructive SEO changes. Draft/scheduled articles never become public. No new fake blog page is generated.

If the product requirement is instead “toggle off must immediately return 404 for all previously published articles,” that is **not** implemented, because it would orphan indexed links and require an explicit owner decision.

## Mandatory finish gate — NOT completed in this branch

Do **not** call this feature pixel-perfect, release-ready, or tested in customer accounts until all are completed:

1. Use isolated staging tenant accounts *authorized* for Kaleb, Booths To The Max and Greenbelt. Do not edit live customer data, or share credentials.
2. Complete the **real v3 wizard screens** with the documented answers and authorized business-specific uploads. Capture answers and the exact selected assets.
3. Create drafts for each reference template; verify each section against its source inventory and each source live page.
4. Browser screenshot at **1440, 768, 390, 320px** for Home, key services, inquiry page, FAQ, Gallery, Blog-on/Blog-off. Compare side by side to contemporaneous screenshots of the source sites.
5. Record pixel-difference metrics, layout shifts, H1, image focal points, form submission, CTA destination, responsive nav/overflow and broken links; fix deviations. Do not use identical screenshot sizes as proof of visual parity.
6. Submit a test inquiry through each **real Forms-module deployment**, verify the correct Contact, Opportunity and consent records; test cross-tenant refusal and duplicate-submit protection. Test Blog on/off with published and zero-article cases.
7. Obtain independent review and CI green, then owner approval before any production deploy or website publication.

**Current status:** Structural/backend implementation under review. Real live reference screenshot captures, authenticated account journeys, and pixel comparisons have **not been performed** in this environment. This file is an operational test contract, not a QA pass certificate.
