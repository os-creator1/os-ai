# Slice 6C — FAQ presentation and contextual blog navigation

Captures (`faq-*`, `homefaq-1440`, `homeguides-*`, `serviceguides-*`): the same Slice 4 deterministic fixture rendered **before** (genuine clean `main`: its own vendor copy,
autoloader and stylesheets) and **after**, in all four templates (`photo_booth_modern`, `_editorial`, `_luxury`, `_conversion`) at 390 and 1440 px. Fake AI; static serving.
Reproduce: `ACCEPTANCE_TEMPLATE=<key> ACCEPTANCE_REPORT_DIR=/tmp/x php artisan test tests/Feature/Website/GeneratorAcceptanceTest.php --filter="benchmarks|native_blog"`.

## What already existed (left alone)

* The FAQ is already an accessible `<details>` accordion (+/- marker, per-template styling, visible focus ring, tap targets >= 59 px), with FAQPage JSON-LD built from what is rendered.
* Service/commercial pages already listed the published articles that **support** them ("Helpful guides"), articles already link back to the page they support, show "Keep reading"
  cards and a service call to action. Blog visibility, indexing, publishing and the reserved `blog` slug are untouched: nothing here creates a page, writes an article or publishes one.

## Gaps found against the Kaleb Hill v3 reference, and fixed

| Gap | Before | After |
|---|---|---|
| FAQ answers lost their paragraph breaks (two paragraphs ran together) | `white-space: normal` | paragraphs kept, comfortable measure, room for the marker — in all four templates |
| A FAQ on another page (home) was a dead end; the reference offers "All questions & answers →" | no link | links to the FAQ page at its **current** address (niche slug aware: `photo-booth-faq`, `faqs`, `faq`), never on the FAQ page itself, never when the site has no FAQ page |
| Supported pages showed a bare bulleted link | `<ul>` of titles | compact cards: title (same anchor), short excerpt, "Read the guide →" |
| The home page had no way into the blog (the reference has an "Ideas and checklists" row) | nothing | "Latest guides": the newest published guides (up to three) as cards, only when a live blog exists |

## Rules enforced (`Website\Blog\WebsiteRelatedArticles`, tested)

Only articles that are **published** (never draft, scheduled or archived), **indexable**, and **this website's own**; none without a live blog entry; none in the owner's Preview;
addresses come from the blog navigation entry of the surface being rendered (custom domain or platform path), so nothing is a stale hard-coded route; at most three; a page no
article supports shows nothing (except the home page's latest-guides row).

## Tests

`FaqAndGuideLinksTest` (8): newest-three cards at current addresses with excerpts; no band and **no `/blog` link** on a site without published guides; draft/scheduled/archived/noindex
and **another tenant's** guide never linked; supported page lists only its own guides; platform-path addresses; Preview resolves none (and none without a blog entry); answers keep
paragraphs; FAQ link follows the FAQ page's current address, disappears with the page and never self-links. `GeneratorAcceptanceTest` (17) additionally asserts the live site shows
"Helpful guides" and "Latest guides" once a guide is published. Existing `PublicBlogTest`, local-SEO acceptance and the full-site audit pass unchanged.

## Regression (isolated baseline)

Clean `origin/main` in its own checkout (own vendor copy and autoloader; verified to load `main`'s classes), Website + Unit/Website + Forms + NicheBlueprint + DogBoarding + Seo/Content:
65 failed, 2,081 passed. This branch, same set: 65 failed, **2,089 passed** (+8 new tests). Failing classes and counts identical (environmental, none touch this slice).

## Limitations

* The guide row and FAQ link read the live blog / navigation at render time, like the existing "Helpful guides"; they are not part of the frozen page snapshot (a newly published
  guide appears without republishing, an unpublished one disappears). Page copy itself stays frozen.
* Home shows the newest guides, not "most relevant"; relevance on commercial pages is the existing owner-set "supports this page" link.
* No automatic FAQ answers: the generator still writes only what it is given, and nothing was added to prompts.
* The reference's richer card imagery and per-category chips are not built; excerpts come from the article's own excerpt or opening text.
* Fake AI only; Dog Boarding end-to-end tests fail on clean `main` too, so its pages were not browser-verified.
