# Questionnaire walkthrough — the answers actually used in the passing fixture generations

**Fixture data, not client data.** These are the exact questions (text read from `PhotoboothWebsiteSetupQuestionnaireV3Seeder::steps()`, i.e. what the
wizard shows) and the exact answers submitted by `ReferenceTemplatesGuidedPathTest` / `ReferenceTemplatesFormsFunnelTest` through the wizard's own
autosave routes. The three templates were generated from the **same** answers; only the template choice and the business name differ.
Authentic client-account runs (Kaleb Hill, Booths To The Max, Greenbelt) have **not** been performed; see the last section.

Template is chosen at "Change template" / initial setup: `photo_booth_reference_kaleb` (Luxury Photo Booth), `photo_booth_reference_booths`
(Event Spotlight), `photo_booth_reference_greenbelt` (Party Editorial). Source column: **P** = prefilled from existing MotionGrove data,
**M** = entered manually, **U** = image upload, **D** = default text the wizard offers and the owner keeps.

| # | Exact question | Answer used | Appears on the generated site | Source |
|---|---|---|---|---|
| 1 | Business name | `Glow Booths` | Header wordmark, footer, titles, schema | P (Business) |
| 2 | Phone number | `555-0100` | Header strip, footer, contact block | P |
| 3 | Booking / contact email | `hello@glowbooths.test` | Footer, contact block | P |
| 4 | Logo | *(none uploaded)* | Wordmark text is used | U (skipped) |
| 5 | Where are you based? | Irvine, CA, US | Intro/hero wording, location facts | P (primary location) |
| 6 | Where do you provide your services? | Irvine, CA · Tustin, CA · Costa Mesa, CA | Areas section, footer areas, hero supporting line | M |
| 7 | What photo booth experiences do you offer? | Open-Air Photo Booth ($399 USD, photo) · Mirror Photo Booth (photo) | Booth cards, service pages | M + U + D |
| 8 | What types of events do you serve? | Weddings (photo) · Corporate Events (photo) | Event cards (with photos), event pages | M + U + D |
| 9 | How do you price your photo booth services? | Fixed package pricing | Package tiers and price wording | M |
| 10 | What packages do you offer? | Essential $499 (2 hours) · Signature $799 (3 hours, Props; featured) | Package tiers | M |
| 11 | Do you offer any extras? | Extra hours, $75 | Add-ons band | M + D |
| 12 | Do you offer different backdrops? | Yes | Backdrops rows/page | M |
| 13 | Show your backdrops | Floral wall (photo) | Backdrops rows and page | M + U |
| 14 | Show off your best event photos | 6 photos uploaded; first = homepage cover | Hero photo, gallery | U |
| 15 | Confirm your photos | confirmed | Publish gate | M |
| 16 | What style fits your business? | Playful & fun | Copy tone only; the template stays the chosen reference | M |
| 17 | Tell visitors about your business | The wizard's default About text for the confirmed facts | Intro / founder / About page | D |
| 18 | Your homepage headline | `Headline from the owner for <template fixture name>` | Hero heading | M (new) |
| 19 | Announcement bar text | `Now booking 2026 and 2027` | Announcement bar on every page; Greenbelt strip | M (new) |
| 20 | Small line above the homepage headline | `Owner tagline` | Hero eyebrow (Kaleb: script line) | M (new) |
| 21 | Supporting text under the headline | `Supporting line typed by the owner.` | Hero supporting text | M (new) |
| 22 | Where should the hero photo be centred? | Top | Hero photo focal point | M (new) |
| 23 | Introduction heading | *(blank → design default)* | Intro band heading | — |
| 24 | Booths section heading | `Our booth line-up` | Booth section heading | M (new) |
| 25–30 | Features / Event types / Packages / Gallery / Reviews / Service areas heading | *(blank → design defaults)* | Section headings | — |
| 31 | FAQ heading | `Common questions` | FAQ heading | M (new) |
| 32 | Closing call-to-action heading | *(blank → default)* | Final inquiry band | — |
| 33 | Who should visitors meet? | `Casey` | "Hey, I'm Casey!" founder block (Booths, Greenbelt) | M (new) |
| 34 | Your Google rating (optional) | `4.9` | Reviews badge (and Greenbelt hero badge) | M (new) |
| 35 | Your number of Google reviews (optional) | `31` | Reviews badge | M (new) |
| 36 | What makes your service special? | Digital photo sharing · Premium equipment | Feature/inclusion cards, facts strip | M |
| 37 | Would you like to feature customer reviews? | 2 fixture reviews (`Fixture review one/two.`) | Reviews | M |
| 38 | What should visitors do when they want to book? | Request a quote | Routing intent; "Check Availability" label comes from a live Forms-module form | M |
| 39 | What should your quote form ask for? | Name, phone, email, event date, event type, event location, message | Legacy field choice; the Forms-module form is authoritative | M |
| 40 | What questions should your website answer? | The wizard's default FAQ for the confirmed facts | FAQ | D |

Also entered in the generation test: service-area details for Costa Mesa (venues `Fixture Hall One`, `Fixture Hall Two`) through the wizard's "Add local details" → Greenbelt venue cards.

**Questions added by this work (13 + 5):** #18–35 above, all optional and one per page. Every one is a fact that cannot come from existing MotionGrove data (owner-written
headline/announcement/tagline, founder first name, real Google rating and count) or a fixed vocabulary (focal point). A blank answer always falls back to the design's
default wording; nothing is invented, and a rating without a count (or vice versa) is never shown. Event types also gained a **photo** field (row field `cover`) because the
Booths and Greenbelt event cards are photographic; it reuses the existing service-photo store.

## Signature-component fixtures (`ReferenceSignatureComponentsTest`)
Same questions as above, plus these uploads (fixture JPEGs). The gallery question's photo categories gained **"Award or press badge"**: a photo tagged this way is shown only as Kaleb's badge strip, never in the gallery, hero or photo slots.

| Template | Extra input | Where it appears |
|---|---|---|
| Kaleb | 3 gallery photos categorised "Award or press badge" (400×400) | awards strip directly under the hero |
| Kaleb | booth "Mirror Photo Booth": cover + 2 extra photos (only the first extra is shown) | photo-led booth row beside its text |
| Booths | 6 gallery photos (the cover is the hero) | two distinct non-hero photos in the intro collage; hashtag derived from the business name |
| Greenbelt | announcement text `Now booking 2026 and 2027`; 6 gallery photos; 2 event types (Weddings, Corporate Events) | promo strip (photo tile + announcement + CTA); event-category ticker |

## Image checklist used (fixture JPEGs, deterministic seeds)
| Upload | Where | Position on the site |
|---|---|---|
| `open-air` 1400×1000 | Booth: Open-Air Photo Booth | Booth card / service page |
| `mirror` 1400×1000 | Booth: Mirror Photo Booth | Booth card / service page |
| `wedding-event`, `corporate-event` 900–1400 px | Event types: Weddings, Corporate Events | Event cards, event pages |
| `floral` 1400×1000 | Backdrop: Floral wall | Backdrops rows / page |
| `event-0 … event-5` 1600×1067 | Gallery; `event-0` is the cover | Hero photo (focal point = Top), gallery, trust imagery |
| *(no logo)* | — | Wordmark |

## What an authentic client run needs (not done)
For each of Kaleb Hill, Booths To The Max and Greenbelt the owner (or an authorised operator) must confirm and upload: logo, hero/cover photo with a focal point, one photo per booth, event-type
photos, backdrop photos with names, 8+ gallery photos, real package names/features/prices, add-ons, verified testimonials, FAQ answers, announcement text, founder first name, and (only if they want the
badge) their real Google rating and count. Starting values may be taken from `docs/migrations/customer-websites/*/website-content.json` **only after the owner confirms each one**. The reference sites'
photographs, logos and badges must not be reused for anyone else.
