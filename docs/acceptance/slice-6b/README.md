# Slice 6B — Homepage trust strip from owner-confirmed facts

Captures (`cmp-1440`, `cmp-768`, `cmp-390`, `cmp-320`): Kaleb Hill v3 reference home (1440/390 only), the generated home **before** (genuine clean `main`, isolated
autoloader), and **after** in `photo_booth_modern` and `photo_booth_editorial`. Slice 4 deterministic fixture, fake AI, same six confirmed facts in every build.
Reproduce: `ACCEPTANCE_FACTS="a|b|c|d|e|f" [ACCEPTANCE_TEMPLATE=photo_booth_editorial] ACCEPTANCE_REPORT_DIR=/tmp/x php artisan test tests/Feature/Website/GeneratorAcceptanceTest.php --filter=benchmarks`.

## What it does

* **`trust_strip` section** (new closed-set type; never AI-authored, never offered to the AI): two to four short owner-confirmed advantages, verbatim, in a slim band
  **directly under the hero** (placement enforced for every template by `WebsiteDesign::orderHomeSections`).
* **Deterministic selection** (`GuidedGeneration\TrustFacts`, no AI): the only input is the owner-**confirmed** differentiators the generator already receives (unverified or
  stale ones never arrive). Takes up to four of at most 70 characters, in the owner's order, distinct in meaning (equal or contained facts count once). Fewer than two suitable
  facts means **no strip and no empty band**. Facts that are too long are never shortened; they remain available to the service pages.
* **No repetition:** a service page's inquiry panel (Slice 6A) shows only the confirmed facts the strip does **not** show. With no strip, panels behave as before.
* **Frozen like any section:** the facts are stored in the page, so a published page is fixed by its revision; changing the business's facts later changes neither the draft nor
  the live page. Regeneration is the only thing that re-selects. The owner can edit the four facts or remove the section in the page editor; Preview shows the draft.
* **Reusable styling:** the template's tint tone and colours; four columns on a wide screen, a balanced 2 x 2 on a tablet, one column on a phone. Non-photo-booth niches get the same
  strip (tested with a tutoring site).

## Rendered-browser results (Chromium, home page)

| width | before | after (modern and editorial) |
|---|---|---|
| 1440 | hero then services | hero, **strip (1 row of 4, 39 px)**, services |
| 768 | — | 2 x 2 (68 px) |
| 390 / 320 | — | one column (126 / 145 px) |

No horizontal overflow, no clipped items, one H1, no extra CTA band, at all four widths in both templates.

## Tests

`TrustFactsTest` (5): determinism, cap of four, distinctness, minimum two, long facts kept whole, non-text ignored, every template places the strip under the hero.
`GeneratorAcceptanceTest` (17, enforced in CI): strip directly under the hero and panels show only the rest; fewer than two facts means no strip and no empty band; long facts go
to the panels untruncated; **unconfirmed facts reach nothing**; a site with no facts has no strip and no invented rating/statistic/award/guarantee; frozen at publish, same after
republish, owner edit published only at the next publish, removal leaves no strip or band; Preview shows the draft; editorial template. `NicheSafeUrlsTest` (6): a tutoring site gets
the strip from its own facts. `WebsiteBoundaryTest` (closed section-type set) updated 15 -> 16 deliberately.

## Regression (genuinely isolated baseline)

Clean `origin/main` in its own checkout with its own vendor copy and autoloader (verified to load `main`'s classes): Website, Unit/Website, Forms, NicheBlueprint, DogBoarding ->
65 failed, 1,865 passed. This branch, same set: 65 failed, **1,877 passed** (+12 new tests). The failing classes and counts are identical (all environmental, none touch this slice).

## Limitations

* Only the owner's confirmed **differentiators** feed the strip. Other confirmed profile fields (for example years in business) are deliberately not used, so no experience or statistic
  claim can appear. Owners with none confirmed see no strip.
* Facts longer than 70 characters never appear in the strip.
* The strip is generated for the home page only; it does not auto-refresh when facts change (regenerate to re-select).
* The reference's richer strip (icons, the numbered "details" row) is not built; the strip is plain checkmarked text.
* Fake AI only; real-AI copy is not assessed. Dog Boarding's own end-to-end tests fail locally on clean `main`, so its strip was not browser-verified (same binding step by code).
