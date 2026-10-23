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

## Build approach (single 360 booth)

Use the existing `photo_booth_editorial` template and `PhotoBoothBlueprintV2` website config.
Because Lumina advertises **one** booth, the site must be configured as one primary service
("360 Video Booth") with add-ons and backdrops — **not** three booths to satisfy the
generator's usual minimum. Whether the generator can emit a single-service site without
override has not been verified here (needs a running app and Business); that check is a
blocker listed in 07-owner-actions.md.

Forms: the Business Pack's *Check availability* form creates a contact and an opportunity
(`create_opportunity: true`) in the Inquiries pipeline; the *Event questionnaire* form
replaces GHL's survey `ESqkDS9Gztup8R`.

Redirects: keep paths working — `/Check-availability`→`/check-availability`, `/Privacy-Policy`→`/privacy`, `/terms-of-service`, `/Terms-Conditions` (case-variants) and `/brochure`→`/` until pricing is confirmed.
