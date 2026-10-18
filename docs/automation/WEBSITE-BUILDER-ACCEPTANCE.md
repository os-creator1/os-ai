# Website Builder — end-to-end readiness and acceptance

Branch `agent/website-builder-acceptance`, based on the integrated preview `1aefbd5b`
(`preview/v1-polished-final`). No PR, no merge. Nothing here made a paid AI request, published
anything publicly, or touched the preview database.

## 1. Why the preview says "Website generation isn't available in this environment right now"

**Exact blocker: the platform AI kill switch is off.** The preview `.env` has `OPENAI_ACTIVE=false`.

* `AiGateway::complete()` Gate 1 refuses every call with `AiRefusalReason::AiDisabled` when
  `config('services.openai.active')` is falsy — before any plan lookup, reservation or provider call.
* `GuidedWebsiteGenerationClient::lastCallWasUnavailable()` maps that refusal to the customer sentence;
  `GuidedGenerationCommitService` records it as the attempt's `failure_reason`; the Review screen
  (`wizard/steps/generating.blade.php`) shows the same notice up front from the same flag.
* Evidence in the preview DB: Chicago Booth Co (website #3) has two failed attempts (Oct 8 and Oct 10), both
  with exactly that reason. No guided generation has ever succeeded there.

Ruled out, with evidence: queue/worker (generation is inline in the request; the worker is up, 0 failed
jobs), stuck lease (none on any site), plan/budget (all 8 workspaces have an active plan; caps are configured),
provider adapter (never reached).

**This is an intentional safety gate, not a bug, and was not bypassed.** To enable real generation, the owner
of the environment must set, in that environment's own `.env` only: `OPENAI_ACTIVE=true` and a valid
`OPENAI_API_KEY`. The key currently present is 14 characters long, which is not a plausible provider key, so
it must be replaced. Per-call and per-period cost controls (already in code):

| Control | Value |
| --- | --- |
| Route | `website_generation`, `gpt-4o-mini` |
| Max output / input tokens | 8,000 / 12,000 |
| Hard cap per request | 6,700 micro-USD (about $0.0067) |
| One generation + its single corrective retry | about $0.0132 worst case |
| Workspace monthly cap | trial $1.50, core $5, growth $10, agency $25 |

## 2. What already existed (reused, not rebuilt)

Guided wizard and questionnaire; Knowledge Profile; canonical services, packages and Locations;
`WebsitePageStrategy`; `GuidedGenerationCommitService` with the `WebsiteGenerationCoordinator` lease
(compare-and-swap fenced, stale-lease recovery); output validation and media binding; page editor (a real
section builder); photos and responsive variants; Forms; publish and revisions; Health; SEO; domains; the CRM
(Contact, Opportunity, attribution); external-website mode. 123 generation-reliability tests (lease,
fencing, concurrency, idempotent retry, AI envelope, timeout, validator, media binding, rebuild) all pass.

## 3. Defects found and fixed here

1. **A generated quote form had no Location, so every real inquiry was refused.** The wizard's
   `WebsiteSetupAnswerApplier::applyForm()` created the form without `location_id`; Forms V1 fails closed
   ("a form with no Location accepts nothing"). A single-Location owner now gets the form connected
   automatically, and generation heals an older unconnected form (never replacing a chosen Location).
2. **A multi-Location owner was never told.** Health gains a `quote_form_location` check — *Fail*, plain
   words, with a "Choose a location" link to Forms — and the Studio overview's Health section is open by
   default whenever something needs fixing now. The product still never guesses among several Locations.
3. **A hero button could not link to the owner's own Contact page** ("The URL must be https, tel:, or mailto:
   only"). Hero buttons now accept the same bounded same-site path a `cta` button already did; the designed
   templates resolve it to the real page address.
4. **No feedback while generating.** Generation is one request (up to 60 s per AI call, plus one retry) and the
   button did nothing visible. Every place that calls the AI (Review "Generate", full rebuild, Studio "Rebuild
   now") now disables its button and shows the MotionGrove loader with one plain sentence. The generation lease
   remains the real concurrency protection.
5. **An external-website business could start the hosted wizard by address** (`/website/build`), creating a
   shell Website and a setup session. The entry now redirects to the external overview before anything is
   created.
6. **A service added after generation was claimed to be "still listed on your Services page"** when it is on no page (page copy is written once). Health now says it is not on the website yet and links to Rebuild.
7. A stale assertion in `WebsiteFullSiteAcceptanceTest` (it still looked for the look card on the Studio
   overview, which the earlier UX-polish lane moved to Settings) was corrected.

## 4. Found, not changed (decisions or prerequisites needed)

* **Real AI copy is unverified** until a valid key and an approved cost ceiling are provided (§6).
* **Pre-wizard starter-draft sites have no way into the guided setup.** The four preview sites (and any
  legacy site) have pages but no setup answers; "Edit setup answers" can only say "No setup answers were
  found", Health reports them mostly "good", and nothing tells the owner that services, photos and package
  details are missing. They arise only from the legacy `POST /website` path; a business using "Build with
  MotionGrove" never gets one. Fixing it needs a decision: starting guided setup over an existing draft
  replaces the draft pages on Generate (the live site is untouched until publish).
* **An in-progress setup session has no exit.** While one exists, every Website entry redirects back into the
  wizard; `Abandoned` exists as a status but nothing uses it.
* Package prices render in the catalog's canonical format ("USD 699.00"), not "$699".
* The lead-attribution touch records `business_location_id = null` although the submission, Contact and
  Opportunity all carry the Location (an existing test pins this).
* The "Your answers are saved" failure sentence is inaccurate for a legacy site that has no answers.
* Published snapshots store absolute asset URLs, so a changed `APP_URL` needs a re-publish to refresh images.

## 5. Acceptance harness

* `tests/Feature/Website/Acceptance/WebsiteOwnerJourneyToCrmTest.php` — answers → generate (deterministic
  `AcceptanceFakeAi`) → review every page → edit text and CTA → package change → look-only rebuild keeps
  edits → internal publish → unpublished edits stay private → real visitor inquiry with attribution → Contact,
  Opportunity and Location; with and without a CRM pipeline; multi-Location completion task.
* `WebsiteFullSiteAcceptanceTest` (existing bot) crawls every page of every template.
* Browser: an isolated smoke database and server (`ultimatesms_testing_wb_acceptance_smoke`, own port, AI off),
  three disposable tenants (guided journey, legacy starter draft, external mode), a session minted
  server-side, no credentials typed.

## 6. Real-provider smoke — needs explicit approval

Not run. Requested: one generation for one disposable fixture business, in the isolated smoke environment
only, with a key supplied by the environment owner; ceiling **$0.05 in total** (the code caps one generation
and its retry at about $0.0132). Nothing is published publicly.

## 7. Test evidence

* Generation reliability (guided generation, lease/fencing/concurrency, AI envelope, rebuild): 123 tests, all pass.
* `WebsiteOwnerJourneyToCrmTest`: 5 tests; external-mode regression added to `ExternalWebsiteHttpTest`.
* Whole `tests/Feature/Website`, `tests/Feature/ExternalSite`, `tests/Unit/Website`: 901 tests, 5 failures and 1 error, every one reproduced on the untouched starting commit `1aefbd5b`: `WebsiteTenancyTest::test_suspended_plan_denies_website_show`, `WebsiteDraftPublishTest::test_rollback_...`, two `WebsiteDraftPageServiceSeamTest` tests (JSON key order), `WebsiteFormTest::test_recheck_blacklisting_...`, `WebsiteMigrationsTest::test_migrations_reverse_and_replay_...`.
