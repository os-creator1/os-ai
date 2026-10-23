# 03 · Website rebuild

Live site: `https://luminaauraevents.com/` — a GoHighLevel website (SSR'd SPA, 485 KB home page).

## Pages found

| URL | Status | Purpose |
|---|---|---|
| `/` | 200 | Home: hero, 360 booth, backdrops, add-ons, event types, "what makes us different", check-availability CTA, FAQs |
| `/backdrops` | 200 | Ivy backdrops, inflatable enclosure (10×10×9 ft, 16-colour LED strip) |
| `/gallery` | 200 | Gallery grid |
| `/brochure` | 200 | **Hidden pricing page** (not linked from nav) |
| `/Check-availability` | 200 | Form: event date, event type, contact, two SMS/email consent checkboxes |
| `/thank-you` | 200 | Post-submit confirmation |
| `/Privacy-Policy`, `/terms-of-service`, `/Terms-Conditions` | 200 | Legal (text in `source/`) |
| `/sitemap.xml`, `/robots.txt` | 200 | **Empty bodies** |
| `/about`, `/contact`, `/faq` | 404 | do not exist — no redirects needed |

## SEO state (all absent)

No `<title>` and no meta description on **any** page; empty sitemap and robots. This is a
net-new SEO build, not a migration — the rebuilt site must supply titles, descriptions,
LocalBusiness/FAQPage schema and a real sitemap.

## Verified content (reusable as-is)

* Tagline "Lights, Camera, Memories!" · "Modern & Fun Video Booth Experiences in Denver"
* 360° booth copy (weddings, corporate, birthdays, brand activations; custom overlays, music, instant sharing)
* Backdrops: ivy, 6 ft tall, up to 8 ft long, 3/5 ft sections; neon signage + fairy lights available
* Add-ons: custom vinyl, inflatable enclosure; branding & custom filters for an extra cost
* Event types: any party, wedding, corporate
* "Open to sponsorship and social media collaborations"
* FAQs (6) from the home page · setup 1 h before + ½ h pack-down · 10×10 ft space, level ground, 8 ft stands
* Service areas: Denver Metro, Colorado Springs · Phone +1 720-502-8306 · luminauraevents@gmail.com

## Facts flagged — NOT published until the owner confirms

| Issue | Evidence |
|---|---|
| **Pricing is ambiguous.** `/brochure` lists 360 booth: 2 h $250 · "4 Hour Rental" **$400** · "4 Hour Rental" **$500** · full day (8+) $600; inflatable booth $125/$175/$225/$275; backdrop $75/$100/$125/$150 | Two lines both labelled "4 Hour Rental"; the page is for an older multi-booth template and is not linked. No Packages section is generated until confirmed. |
| Brochure FAQ says "3 hour package"; home FAQ says "2 hour package" | Contradiction |
| Brochure FAQ says an attendant is always on site; home FAQ says "depends on the package" | Contradiction |
| Phone number differs: `/backdrops` shows **(720) 917-8741** (= the GHL location profile phone); every other page shows **+1 720-502-8306** | Confirm which is public |
| Testimonials / reviews | None found. `reviews` custom value is empty. **No testimonials will be shown.** |
| Logo | `logo` custom value points to a bucket folder, not a file; no logo file in media |
| Social | `Instagram` custom value empty; `#luminaauraevents` hashtag only |
| Image `f28673cb…jpeg` is stored under a *different* GHL location (`36SFu6Qj8RrW1UQCBSUa`) | Provenance/rights unclear — excluded from the gallery pending owner confirmation |
| Footer "© 2024" | Update to current year in the rebuild |
| SMS consent checkboxes (transactional + marketing) on the form | Preserve both, separately recorded, unchecked by default |

## Assets (retrieved, original quality, not hot-linked)

* 17 files from GHL Media Storage via authorised API (27.4 MB): 12 images, 5 MP4 (1.4–6.6 MB).
* 14 files referenced by the live pages (incl. 3 MP4s, a 2.9 MB JPEG) fetched from the public `filesafe` CDN at original size; 5 duplicate library files and 9 are **not** in the API media list (UUID-named uploads), so the public CDN was the only programmatic source for them.
* Stored in the scratchpad during this run — **not committed** (binary, 27 MB). The provisioning step must ingest them through `WebsiteAssetUploadService` into MotionGrove-managed storage; the generated site must reference only MotionGrove URLs.

## Preview status — BUILT (isolated staging, platform path only)

Built by `php artisan lumina:build-website` (`LuminaWebsiteBuilder`) from the existing starter/page/asset/gallery/Forms/publisher services — **no AI generation and no metered API**. Published to the platform preview path `/sites/<public_id>`; **no custom domain, no DNS, no Forge domain** (asserted in tests).

| Page | Slug |
|---|---|
| Home (hero + CTA, 360 booth, event types, add-ons & backdrops, what makes us different, gallery, service areas, FAQ, CTA, contact) | `/` |
| 360 Video Booth | `/360-video-booth` |
| Backdrops & add-ons (ivy backdrops, inflatable enclosure) | `/backdrops` |
| Gallery (8 event photos) | `/gallery` |
| FAQ (6 verbatim) | `/faq` |
| Check availability (real quote form) | `/check-availability` |
| Privacy Policy / Terms of Service / Terms & Conditions (customer's existing text, verbatim) | `/privacy-policy`, `/terms-of-service`, `/terms-and-conditions` |

* **Single primary booth**: one service ("360 Video Booth") with add-ons as quote-only catalog items; the three-booth minimum was not satisfied by inventing booths — the page set is hand-composed from the template's own section types.
* **No prices, packages or testimonials** appear anywhere (asserted). Every price surface says "Request a quote".
* **Phone**: the site uses **+1 720-502-8306** (the GHL "Phone" custom value and LC Phone number). The profile phone +1 720-917-8741 appears nowhere (asserted on every page). Discrepancy still needs the owner's confirmation before launch.
* **Images**: 20 of the customer's own originals stored in MotionGrove-managed media with responsive WebP variants; no GHL/filesafe URLs in any page (asserted). Asset provenance is in `website-manifest.json` (binaries are not committed; the builder resolves them by SHA-256).
* **SEO**: unique title + meta description on every marketing page (none existed before), OG tags, canonical per page from the platform.
* **Rendering checked** at desktop (1366 px) and mobile (390 px) with headless Chromium against a local server; every page returns 200 in tests.

### The quote form is real
`Check Availability` is a Forms-module form deployed to the website and as a direct link: name, phone, email, event date, event type (multi-select with the GHL option list), notes, and the **two GHL consent statements verbatim as separate, optional consent fields**. A submission through the public route creates/matches the Contact (Business-scoped), writes Event date / Event type custom values, opens an opportunity in **Inquiries → New Lead**, and — because staging has no published workflow and no sending path — sends nothing (tested through the real HTTP route).

### Not done / owner-dependent
* Videos (5 MP4) were retrieved but the website asset store accepts images only; no video section exists in the generator.
* Old-URL redirects: the platform has no redirect table. Paths were kept lowercase (GHL matches case-insensitively); case-variants such as `/Check-availability` need a platform redirect feature or an edge rule at cutover.
* The hero image is a real guest photo (identifiable people) and "Memories!" in the brand copper has modest contrast over it; confirm guest-photo release and consider a darker overlay.
* The embedded form iframe's auto-height was not verified in a real interactive browser (static screenshot clips it).
* Legal text is imported verbatim and still contains the source's blanks ("brand owned by [blank]", empty email) and a 2023 effective date — **needs owner/legal review before launch**.
* Pricing, FAQ contradictions, logo variants, testimonials: unchanged (see table above).
