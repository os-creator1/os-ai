# Customer website builds (Kaleb Hill, Booths To The Max, Greenbelt)

Builds a real, populated photo booth website for an **existing** MotionGrove customer from reviewed content captured from the
owner's own live site, using the existing website builder and publisher. It does not depend on the Photo Booth V2/V3 blueprint or
Business Pack migration, and it never creates a Business, Workspace or Location.

| Key | Business / Workspace | Source site | Colours (primary / secondary) |
|---|---|---|---|
| `kaleb-hill` | #3 / #3 Kaleb Hill Photography | kalebhillphotobooth.com | `#FAC815` / `#232323` |
| `booths-to-the-max` | #4 / #4 Booths To The Max | boothstothemax.com | `#188BF6` / `#061633` |
| `greenbelt` | #6 / #6 Greenbelt Photography | greenbeltphotography.com | `#F4A28F` / `#2A1D45` |
| `transparent` | #5 / #5 Transparent Photo Booths | transparentphotobooths.com/home | `#188BF6` / `#061633` |

Toronto and Lumina are untouched.

### Transparent (#5) notes

- 20 pages (the platform caps a website at 20): Home, Photo Booths (with FAQs), Backdrops, Gallery, Wedding/Corporate/Party, About (Meet Katie), Service Areas, the seven source-supported local pages (Spokane, Spokane Valley, Liberty Lake, Airway, Cheney, Coeur d'Alene, Post Falls), Check availability and the three legal pages. FAQs also appear on Home.
- Two booths only (Digital, Polaroid). No prices (none published). Reviews are the source's three; the longest sits in a text block because testimonials cap at 400 characters.
- Eleven images on the source site are byte-identical to Booths To The Max photos (template carry-over) and were excluded. The Polaroid booth uses the source's own "guests holding custom photo booth prints" photo; confirm with the owner.
- The source identifies `transparent occasion rentals LLC`, +1 425 789 7018, katecraig2002@yahoo.com. The dry run compares the Business record with these; a mismatch blocks `--apply`.

## Secondary colour

Uses the dedicated implementation (`claude/trusting-pascal-x0wpms`: theme key `secondary_brand_color`, `WebsiteLookService::setSecondaryColor`,
`--wd-secondary*` tokens and `.wd-has-secondary` styling, with its own feature tests). This branch only supplies the value per site; it
adds no second setting. Note: that implementation styles secondary *accents* (badges, secondary buttons, footer headings, card rules);
the dark bands keep each template's own dark colour (Booths To The Max `#061633` and Greenbelt `#2A1D45` already match; Classic Gold's
`#1B1B1B` is close to Kaleb's `#232323`).

## What a run writes (website side only)

Website, pages, media assets (binaries stored in MotionGrove media, matched by SHA-256, never hot-linked), gallery titles, the
"Check Availability" Form and its website deployment, catalog items, the service list, theme colours/logo/hero. After the build the
command compares per-business counts of contacts, opportunities, pipelines and locations and fails loudly if any changed. It never
touches plan assignments, billing, DNS, custom domains, automations or invitations; queue, mail and notifications are faked and every
outbound HTTP call is refused. Idempotent: re-running reuses the website and updates pages in place.

A site is published **only** when every image resolved and the content file lists no `blockers`; otherwise it is left as an unpublished
draft and the gap is reported. Publication is to the platform preview path only: `https://getmotiongrove.com/sites/<public_id>`.

## Safe deploy procedure

1. Merge this branch to `main` after review and deploy normally (nothing runs on deploy; no migration).
2. Upload `<key>-assets.tgz` to `/home/forge/`. Their SHA-256 values are pinned in `bundles.sha256` (checked before extraction).
3. Dry run (read-only, takes and validates a DB backup): `bash ~/customer-sites-run.sh kaleb-hill` (upload the script from `docs/migrations/customer-websites/run-on-forge.sh`; the name avoids Toronto's `~/run-on-forge.sh`). It proves target/Business/Workspace/name, compares the Business phone/email with the owner's published details (read-only; a mismatch blocks `--apply` unless `ACCEPT_CONTACT_MISMATCH=1`), lists the plan and any missing asset.
4. Apply: `bash ~/customer-sites-run.sh kaleb-hill --apply` (re-verifies the backup, asks you to type `BUILD CUSTOMER WEBSITES IN PRODUCTION`).
5. Review the printed `REPORT` (pages, assets stored, preview_url) and open the preview on desktop and mobile; submit the form once with a
   test contact you control, then delete that test contact/opportunity.

Rollback: restore the printed backup, or archive/delete the one Website from the studio. Nothing else is modified.

## Known gaps (reported, not papered over)

- **Greenbelt is built as an unpublished draft.** The owner's site has no Privacy Policy / Terms (footer links resolve to empty pages) and
  legal text is never written for an owner. Supply the owner's own legal text or URLs, add them to the content file, re-run.
- Greenbelt testimonials are exactly as published on the owner's site (first names/initials, no source reviews to verify): confirm with the owner.
- Kaleb and Max publish **no prices**, so none are shown ("Request a quote"). Greenbelt's prices are the owner's published package/add-on prices.
- Max: the source footer phone is `+1 424-484-8053`, its backdrops page shows `(310) 920-6447`. The first is used; confirm.
- Kaleb and Max legal pages are the owner's own text, verbatim (they mention the old site address and the owner's legal entity).
- Phone/email on the website come from each Business record (contact details section); compare with the values in the content file
  (`contact`) after the dry run. The builder does not overwrite Business contact fields.
- Excluded on purpose: third-party award/press badges, Google rating badges, GHL stock icons, wedding-invitation flat-lays.
- One Kaleb image (`props`) was AVIF at the source and is stored as a JPEG conversion (format only).
- Per-city landing pages on the old sites are consolidated into one Service Areas page; no DNS/redirect changes are made.
