# Implementation Contract 23 — Citations V1 (complete)

**Status:** completes SEO → Citations (Contract 18 §8.5 / Sub-slice E) from a
manually tracked demo into a listings-management surface. Builds on the
dashboard redesign (`5457a7f7`). Amends Contract 18 §8.5 where stated; nothing
else in Contract 18 changes.

**One sentence:** Business OS tells an owner *where to be listed*, *what matters
most for their niche*, *what is done*, *what does not match* and *what to do
next* — and is truthful that, apart from the Google row, **nothing is checked
automatically**.

## 1. Directory model — three sources, one list

| Source | Where it lives | Who edits it | Shown to |
|---|---|---|---|
| **Platform core** | `seo_citation_directories` (`business_id` NULL, `is_platform_core`) | Platform Owner — *Citation Directories* screen | every Business |
| **Niche recommendation** | `seo_niche_citation_recommendations` → references a catalog directory | Platform Owner — *Citation Niches* screen | Businesses whose `businesses.industry` equals `niche_key`, **live** |
| **Business custom** | `seo_citation_directories` (`business_id` set) | the owner | that Business (all Locations, or one) |

The Citations page builds one list per Location from those three plus any
directory the Business already holds a citation for (history). **No per-Business
rows are pre-created and nothing is copied**: a recommended directory with no
`seo_citations` row renders "Needs setup". A niche edit therefore reaches every
existing Business of the niche immediately.

*Why niche recommendations are not a Niche Blueprint component:* a Blueprint
installs by copying components into a Business once, so a later edit would never
reach existing Businesses (contrary to this lane's requirement). The niche key
is the same one the Website questionnaire and templates already use
(`businesses.industry`, `BusinessIndustry`), so no new niche concept exists.

### Catalog columns (global, never per-Business state)
`key` (stable slug) · `name` · `website_url` · `claim_url` (official, https, may be
NULL) · `category` · `icon` · `importance` (default) · `tracking_mode` ·
`setup_guidance` · `country_scope` · `is_platform_core` · `is_active` ·
`sort_order`. Custom rows additionally carry `business_id` and optionally
`business_location_id`; platform rows never do.

### Importance (owner-facing)
`Essential`, `Recommended`, `Optional`. Product guidance that orders the page and
the next-steps list. **Not** an authority score and not a ranking promise. A niche
recommendation may override the directory's default importance for that niche.

### Tracking mode — what Business OS can really do
| Mode | Meaning | Today |
|---|---|---|
| `connected` | an authorized official connection supplies facts | **Google row only** (GBP read model); never a stored directory |
| `automatic_check` | an official API reader on a schedule | none — reserved for the seam (§6) |
| `assisted` | official claim URL known; owner claims there and records what it shows | every catalog directory with a verified claim URL |
| `manual` | owner supplies everything | every custom directory; catalog rows with no verified claim URL |

Copy says **"Manually tracked"** for assisted/manual and "Checked automatically"
only for a row that actually read data. No screen can set `connected` or
`automatic_check` (the Platform Owner screen offers `assisted`/`manual` only).

## 2. The shipped catalog (verified October 2026)

Verification means the directory's own page was loaded. The fetches ran from
outside the US; claim URLs are worth a periodic recheck. A directory whose claim
page could not be verified is listed **without** a claim URL and tracked manually.

| Directory | Importance | Mode | Claim / manage URL | Markets | Verification notes |
|---|---|---|---|---|---|
| Apple Business (Apple Maps) | Essential | assisted | https://business.apple.com | US (varies by country) | loaded; renamed from Business Connect; Partner API is approved-partners only |
| Bing Places for Business | Essential | assisted | https://www.bing.com/forbusiness/management | global | verified in Contract 18E; Bing Places API is trusted-partner only |
| Yelp for Business | Essential | assisted | https://biz.yelp.com/claim | US/CA + others | loaded; listing APIs reserved for contracted partners |
| Facebook Business Page | Recommended | assisted | https://www.facebook.com/pages/create | global | loaded (login-gated); Pages API needs app review, not a listing-data API |
| Data Axle | Recommended | assisted | https://leads.dataaxleusa.com/landing/updatelisting.aspx | US | loaded; upstream data aggregator; no public API found |
| Foursquare | Recommended | assisted | https://app.foursquare.com/venue/claim | global | loaded; Places API is read/search only |
| Nextdoor Business Page | Recommended | assisted | https://business.nextdoor.com/local | US | loaded; free page; no listing API found |
| Better Business Bureau | Recommended | assisted | https://www.bbb.org/get-listed | US/CA | loaded; free profile, paid accreditation is separate/optional |
| Yellow Pages | Recommended | manual | — (unverified, 403) | US | real directory; claim page unverifiable, so no URL shipped |
| MapQuest | Optional | manual | — (unverified) | US/CA | real directory; claim page unverifiable, so no URL shipped |

**Google Business Profile** is the eleventh Essential source, shown as the
synthetic Google row (§5), not a catalog row.

Considered and **not** seeded: TomTom (US availability unverified), HERE (community
map editing), Alignable (networking), Angi/Thumbtack (lead marketplaces),
Manta / ChamberofCommerce.com / Waze (claim URLs unverified, weak or upsell-heavy),
Trustpilot (reviews). **No** directory outside Google has a plausible self-serve
write/read API; Apple, Bing and Yelp require partner agreements, Foursquare and
Yelp Fusion are read/search only. They are therefore *assisted*, with the exact
future requirement recorded here, and Citations V1 does not wait on them.

### Photo Booth niche (`photo_booth_service`)
Recommended directly from each vendor's own pages:

| Directory | Importance | Why | Cost (as documented) | Claim URL |
|---|---|---|---|---|
| WeddingWire & The Knot (WeddingPro) | Recommended | Photo booth is a listed category with prices and quote requests; one vendor program covers both sites | free plan documented; paid tiers not stated on the loaded page | https://pros.weddingpro.com/ |
| GigSalad | Recommended | dedicated photo-booth category; strongest non-wedding channel | free limited listing; booking fee not stated on the loaded page | https://www.gigsalad.com/join (named by GigSalad's own help article) |
| Eventective | Recommended | long-running event marketplace; free listing shows prices | free plan; paid plans from $57/mo documented | https://www.eventective.com/addlisting |
| Bark | Optional | inbound quote requests | free to join; pay-per-lead credits documented | https://www.bark.com/en/us/sellers/create/ |
| The Bash | Optional | party/entertainment marketplace with a photo-booth page | not clearly documented | https://www.thebash.com/signup/landing |

Rejected or not seeded: Thumbtack, Zola (sign-up pages not verifiable), PartySlate
(paid tiers, no self-serve form), Here Comes The Guide (vendor directory closed),
Wedding Spot / Peerspace (venue marketplaces), Joy, Cvent, Alignable.

## 3. Custom directories

`+ Add custom directory` (owner, `manage_seo`): name, listing link, optional
claim/manage link, status and the listed name/phone/address/website, applied to
all Locations or only the selected one. Always `manual`, always labelled **Custom**,
never platform-certified, `importance = optional`, never `is_platform_core`. Owner
may rename, change the claim link, record/edit details, mark Not applicable and
**archive** (history is kept). A safety ceiling of 25 per Business
(`seo.citations.max_custom_directories`, fail-closed). Another Business's custom
directory — or a platform key via the custom routes — is a plain 404.

## 4. NAP comparison

Compared against the **selected Location's** canonical data only; never mixed
across Locations. `Name`, `Phone`, `Address` use `SeoNapComparator` unchanged
(address only where `addressPermittedForLocation()`); **Website** is new and is
compared only where *both* the Business website and a recorded listing website
exist (otherwise it is simply absent — many directories store no website):

* scheme (http/https), a leading `www.`, host case, trailing slash and `#fragment`
  are not differences; the path and query are; no fuzzy matching (subdomains differ).
* A missing listing value is **"Not checked"**, never a mismatch.
* Display: `3 / 3 match`, `Phone differs`, or `Not checked`.

**Two badges, two meanings.** The *setup* badge is the owner's own progress
(Needs setup · In progress · Listed · Needs attention · Not applicable). The *NAP*
badge says whether what they recorded matches. A mismatch does **not** change the
stored status.

## 5. Google Business Profile (the one connected row)

Reuses the existing connection and read model — **no second OAuth/token authority**.
`GoogleLocationStatus` gained `mirrorName`, `mirrorPhone`, `mirrorWebsite` (null
unless the mirror is fresh). Citations compares them against the business profile
**at read time and stores nothing** (Google content may not be kept past the
mirror's 30-day retention). Street address is never compared (the mirror has none).
States: **Connected / Not linked / Connection lost**. "Checked automatically" is
shown only while a fresh mirror was actually read; an expired mirror claims no
automatic check.

## 6. Automatic checking — the seam, deliberately empty

No directory other than Google offers an accessible read API (§2), so V1 ships **no
scheduler and no provider** and the Citations code contains no HTTP/queue/cache
(source-scan tested). The seam a future integration plugs into:
* a `CitationCheckProvider` contract `check(Location, Directory): observed facts`,
  run from a queued job per (Location, directory), never at page load;
* persisted `last_checked_at`, a safe error state and observed values (only where
  the provider's terms allow storing them);
* default weekly cadence; a failed check leaves the previous good observation
  visible;
* `verification_source` (single-valued today) widened with that provider, and
  `SeoCitationsBoundaryTest`'s no-HTTP/queue scan amended for the provider class only.

**Future partner requirements (not built):** Google Business Profile API access
approval (60-day verified profile, access request, OAuth) is the nearest; Apple
Business Partner API, Bing Places API and Yelp Data Ingestion/Listing Management
are partner/contract-only.

## 7. Progress and completion — defined, not implied

* A row is **complete** iff the owner marked it **Listed** *and* recorded something
  (a listing link, a value or a check date). Merely existing in the catalog, or
  "Listed" with nothing recorded, is not complete. The Google row is complete when
  connected.
* **Not applicable** rows leave every count and denominator.
* Cards: *Listings tracked*, *Completed*, *Needs attention*, *NAP matches* (`matched / compared`,
  both values present), plus the strip *Essential x / y completed*, *Recommended x / y
  completed*, *Not checked n*. No score, no "visibility %", no authority number.

## 8. "What to do next" and review reminders

`SeoCitationLocationSection::attentionItems()`, in priority order: 1 Essential
missing (**Claim** when a verified claim URL exists, else **Record details**,
**Connect** for Google) · 2 Essential inaccurate (**Review**) · 3 Recommended
missing · 4 stale manual listing (**Review**) · 5 Optional/Custom issues. Absent
when there is nothing to do. A manually tracked listing last checked more than
`seo.citations.review_after_days` (default 90, range 7–365) ago shows **Review
recommended** — an in-product reminder only; nothing is sent.

*Opportunity seam:* `attentionItems()` is a pure, I/O-free reader (kinds `missing`,
`inaccurate`, `stale`) that a future `OpportunityProducer` for
`OpportunityWorkerKey::Seo` can consume. No opportunity storage is duplicated here.

## 9. Not applicable and history

Owner marks a directory Not applicable / restores it (`citations.applicability`);
the recorded values are kept. Core Google cannot be marked not applicable (it is
not a row). A platform-disabled directory leaves new Businesses but stays readable,
read-only, wherever a record exists; removing a niche recommendation deletes no
Business data.

## 10. Entitlement (no flip needed on this base)

`PlatformFeature::SeoModule` is **already Available** (Growth + Agency) and gates
Citations, Reviews and the audit; `SeoBasicVisibility` (Core+) gates Overview and
keywords. This lane adds **no** feature key or packaging. Verified without the test
bypass (`SeoCitationsReachabilityTest`): a Growth owner reaches Citations and
Reviews; a Core Business gets a plain 404; every write route runs the same step.
Search Console — the genuinely unfinished child — has no route at all, so becoming
reachable never exposes it. Child gates: `view_seo` (reads) / `manage_seo` (writes);
Location access via `LocationAccessGuard` unchanged.

## 11. Platform Owner screens

* **Citation Directories** (`admin.citation-directories.*`): name, active, core?,
  default importance, tracking (assisted/manual), website, claim link, markets,
  icon, sort order, setup guidance. No credentials; automation capability is shown
  read-only (no integration exists to configure).
* **Citation Niches** (`admin.citation-niches.*`): per niche — add directory,
  remove, importance override, order, enabled, short niche guidance. A niche **cannot**
  change a directory's website, claim link or automation capability.

Both sit behind `can:access backend` + `EnsureUserIsAdministrator`, and
`SeoCitationCatalogManager` re-checks `users.is_admin` on every call.

## 12. Copy rules

Never "synced", "live", "real-time" or "automatically monitored" for a manual
listing; never "more citations improve rankings" or a guaranteed outcome. Allowed:
"Manually tracked", "Checked automatically" (Google, real read only), "Consistent
business information helps customers and platforms identify your business
correctly."

## 13. Deferred

Automatic checkers for any directory other than Google · Search Console ·
per-directory logos beyond a generic icon · e-mail/SMS review reminders · a
Growth/Opportunity producer (the reader seam is in place) · niches beyond Photo
Booth (add through the Platform Owner screens, no code change).
