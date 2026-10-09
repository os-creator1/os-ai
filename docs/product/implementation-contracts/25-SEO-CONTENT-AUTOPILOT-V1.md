# Contract 25 — SEO Content Autopilot V1

Status: in implementation on `agent/content-autopilot-v1` (base `agent/seo-content-engine-v1` 124a8666).
Builds on: `24-SEO-CONTENT-ENGINE-V1.md` (the Content Engine this evolves — read it first), `20-NICHE-BLUEPRINT-VERSIONING.md`.

Content Autopilot decides **whether** useful content is warranted for a Business, writes it from the Business's real
facts, validates it, publishes it when it is safe, and maintains what exists. It is not a blog generator: **"do nothing"
is a successful decision**, and a month may legitimately publish zero articles. There is no quota. The maximum of 4 new
Autopilot articles a month is a ceiling, never a target.

## 1. Authority

| Concern | Owner |
|---|---|
| Article record, status, schedule, pending draft, slug history, rendering, sitemap | Unchanged — `ArticleManager` + the Website renderer (Contract 24). Autopilot creates ordinary `website_articles`. |
| Entitlement | Existing `SeoModule` (Growth, Agency). Core keeps manual Articles only. **No new platform feature.** |
| AI calls | `AiGateway` only, category `content_autopilot` (Contract 18 AI budget). |
| Per-Business AI ceiling | `AiBusinessCategoryCeiling` + `config('ai.business_category_ceilings')`. |

## 2. AI budget (Slice 1)

* Category `content_autopilot` (`AiUsageCategory::ContentAutopilot`): always hard-enforced on the Workspace cap, not
  dormancy-gated (Autopilot works while the owner is away), no COO entitlement.
* Routes: the **cheap** `routine` route is the category default (outline/question help, classification, the
  soft-finding judge). The **strong** `content_writer` route (`AiModelRoute::ContentWriter`, `config('ai.routes.content_writer')`)
  is used only for the article draft and meaningful rewrites. No downgrade path targets it: an unaffordable request is
  refused, never silently written by a weaker model.
* **Per-Business ceiling**, enforced inside `AiGateway` for the category: normal target **$0.50** / Business / period,
  hard ceiling **$1.00** (`target_microusd`, `hard_ceiling_microusd`). The ceiling is a safety limit, not a spending
  target, is configuration/plan policy only (not owner-editable), and is checked **and** the reservation taken under one
  lock (`Cache::lock`) so concurrent calls cannot overshoot it. Over the ceiling: refused with
  `category_ceiling_reached` before anything is reserved or any provider call is made.
* Spend = ledger rows for the Business + category + `period_key` (calendar month, UTC): committed at actual cost,
  failed at the billed cost, live reservations at their estimate; released/refused cost nothing.
* `ContentAutopilotAiClient` is Autopilot's only door to a model: `write()` (strong route) / `assist()` (cheap route).
  Every call carries an explicit idempotency key derived from the decision and step, so a retried job never pays twice.
  A budget refusal means "wait for the next period", never "retry now".
* `WebsiteAiGenerationClient` is deliberately **not** modified (a source-pinning test guards its `AiRequest` site).
* Not changed: `enforce_budgets_for_existing_categories` still governs the pre-existing categories. The owner's manual
  "AI draft" button keeps using `website_generation` and `ArticleDraftGenerator` as it did (its tests pin that); only
  Autopilot's own writing uses the `content_autopilot` category, so the per-Business ceiling counts Autopilot spend.
  Moving the manual button onto the same category is a separate, optional change.

Cost model (list prices at `config/ai.php`; assumes ~3k input / ~2.5k output tokens per article):
brief assist ≈ $0.0005, article write ≈ $0.033 (strong) / $0.002 (cheap), judge ≈ $0.0006, section rewrite ≈ $0.02.
0 articles ≈ $0; 1 ≈ $0.034; 2 ≈ $0.07; 4 ≈ $0.14 (worst case with one retry each ≈ $0.27). Deterministic work
(scoring, selection, briefs, claim and duplicate checks, link maintenance, cadence, budget enforcement) costs nothing.

## 3. Fact Pack and Content Profile (Slice 2)

**One canonical pack** — `ContentFactPack::forBusiness()`, read-only, deterministic, no AI, no provider/rank call. It
composes (never copies): `ArticleGroundingFacts` (name, services, real packages and prices, service areas, site summary,
FAQs, niche FAQ topics) + the **Business Knowledge Profile** (differentiators, ideal customers, customers' problems, brand
voice, prohibited claims) + the Content Profile + the stored Google Business Profile mirror (category names, only while the
mirror is fresh; never refreshed from here) + tracked keyword phrases + the Business's existing non-archived articles.
`fact_hash` is a key-order-insensitive digest of the citable facts (existing articles excluded): an unchanged hash means
previously built briefs/classifications are still valid, so no new AI call is needed.

**Correction to the architecture report:** the Knowledge Profile already stores differentiators, years operating,
credentials, guarantees, brand voice and prohibited claims. They are reused, not re-asked. The first-enable flow therefore
asks only what no table holds: *what customers ask most*, *what to emphasise*, *topics to avoid* — plus *what makes you
different* only while the Knowledge Profile's `differentiators` is empty (and that answer is written through
`BusinessKnowledgeProfileManager`, the single authority). Every answer is optional; saving (even empty) completes the flow.

**Owner-confirmed claims.** `ConfirmedBusinessClaims` is the one reader of claim-bearing Knowledge Profile facts, and only
those whose field state is `customer_confirmed`: `years_operating` and verified `credentials`. `ArticleClaimGuard` now lets
"N years of experience" through when N is the confirmed number ("over/more than N" when N is below it); a founding year
("since 2009"), a different number, a guarantee, awards, review scores, counts and the rest remain hard findings. This applies
to manual and Autopilot articles alike. A guarantee is never offered to the writer as a fact. **Reviews and testimonials are
not part of the pack** (no canonical review-text source; the claim guard also forbids ratings).

Storage: `content_autopilot_settings` — one row per Business (`enabled`, `enabled_at/by`, `paused_reason`, `profile` json
`{common_questions, emphasis, avoid_topics}`, `profile_completed_at`). No budget column: the AI ceiling is plan policy.
Owner page: `…/seo/content/autopilot/profile` (SeoModule, 404 without it; `view_seo` reads, `manage_seo` writes).

## 4. Niche Blueprint configures ONE engine (Slice 3)

There is one Autopilot engine; no niche has its own code path. The Niche Blueprint's `seo_strategy` component (read live
through `BlueprintConfigReader::seoStrategy()`) now optionally carries:

* `content_policy` — `risk_tier` (`standard|sensitive|regulated`), `auto_publish` (`allowed|approval_required|never`),
  `prohibited_phrases[]`, `preferred_terms[]`. Validated at publish: `sensitive`/`regulated` cannot be `allowed`.
* per-topic `months[]` (1–12, seasonality) and `stage` (`awareness|consideration|decision`). Both are optional and appear in
  the parsed topic only when set, so existing topic shapes and form lines are unchanged. Topic families, typical questions
  and content types are the existing `cluster`, `faq_topics` and `intent`.

The Blueprint form gains four fields (risk, automatic publishing, never-write phrases, preferred terms) and two trailing
optional topic parts (`| months | stage`). Naming phrases without a tier is stored as `sensitive`, never `standard`.

**Fail closed.** `SeoStrategyComponentAdapter::contentPolicy()` returns `unspecified` + `approval_required` for no Blueprint,
no policy, or an unreadable/invalid one. `ContentPolicy::autoPublishAllowed()` is true only for an explicit `standard` +
`allowed`; even then the scheduler applies the trust ramp (`ContentPolicy::TRUST_RAMP_ARTICLES` = 2 approved articles per
Business first). `ContentPolicy::prohibitedPhrases()` merges the niche's phrases with the owner's Knowledge Profile
`prohibited_claims`; `inSeason()` opens a 2-month lead window and wraps the year end.

**Not done here:** the shipped Photo Booth Blueprint declares no `content_policy` yet, so it is *unspecified* (always
owner-approved) until a platform owner publishes a Blueprint version carrying one through the existing Blueprint workspace
form. That is deliberate: published Blueprint versions are versioned content, not something this lane edits.

## 5. Decisions, scoring and the brief (Slice 4)

Deterministic and free: no AI, no provider call, no rank job. `AutopilotPlanner::evaluate($business, persist, $now)` records
**one** outcome per evaluation in `content_autopilot_decisions` (`--persist` off = dry run, nothing written):

| Decision | Meaning |
|---|---|
| `create` | the best eligible topic, with its structured brief (`state=briefed`) |
| `needs_input` | only one missing fact stands between a topic and `eligible` — ask once (one open question per Business) |
| `hold` | topics exist but are waiting (out of season, thin facts, no page to support) |
| `none` | nothing worth writing, the monthly maximum reached, or no website. **A successful outcome.** |

An identical `hold`/`none` (same reason, topic and fact hash) is recorded once per `idle_record_days`, not every tick. A
question closes itself when its answer exists. A topic is not selected while a decision for it is in flight, nor for
`retry_cooldown_days` (90) after a held/rejected one.

**Score (0–100, `AutopilotScorer`):** relevance 20 · usefulness 15 · support for a commercial page 15 · real gap 15 ·
unique Business facts 15 · rank/search opportunity 10 · season 5 · link fit 5. **Disqualified (never written):** already
covered, strong duplicate of an article, competes with a money page, a topic the owner avoids, a phrase the owner or niche
prohibits, a cost topic with no priced package. A related article costs the gap factor. **Flags** keep a topic out of
`eligible`: `out_of_season`, `thin_facts` (fewer than two distinct fact groups), `no_support_page`. **Bands** (config
`seo.content_autopilot`): ≥70 and no flags eligible · 45–69 hold · <45 skip. Equal scores prefer an angle the site does not
have yet. Matching uses a phrase's **subject** words, never its question words ("how", "cost").

`max_new_articles_per_month` (4) is a **ceiling, not a target**: 0 is honoured, and nothing counts articles to reach a number.
The rank factor currently uses the owner's tracked keyword phrases only; position-based rank signals belong to maintenance
(Slice 8) and reuse `ArticleRankSignals` unchanged.

**Brief (`ArticleBriefBuilder`, stored on the decision as JSON with a content `brief_hash`):** topic, intent, journey stage,
audience, the page it supports and the call to action, only the facts that bear on THIS topic (real prices only for cost or
package topics), questions to answer (the Business's own first, then intent defaults, max 6), the allowed internal links, a
`must_not` list (fixed rules + niche phrases + owner prohibited claims + avoided topics) and the niche risk tier / preferred
terms. `php artisan content:autopilot-evaluate {business} [--persist]` shows the ranked topics and the decision.

## 6. Writing and validation (Slice 5)

`AutopilotArticleWriter::write($decision)` is the only step that spends money, run on the queue as
`WriteAutopilotArticleJob` (unique per decision). It turns a `briefed` decision into an ordinary **Draft** through
`ArticleManager` (`source = autopilot`, `ai_generated`) and **never publishes**:

`briefed → drafting → awaiting_approval` (the article exists) · `deferred_budget` (wait for the next period) · `rejected`
(still failing hard validation after one repair; cooled down 90 days) · `held` (no website; a paid call that lost its result)
· back to `briefed` when the provider is unavailable (retried with a fresh key).

* **Prompt** — `BriefPromptBuilder`: the Content Engine's own grounding/link/structure rules (one copy), plus the brief's
  questions, length, niche terms and `must_not`. The model receives `brief.facts` only, never the whole Business.
* **Spend discipline** — the Business's remaining budget is checked before any call (worst-case writer cost vs the ceiling); one
  strong-route draft and **at most one** rewrite, only for hard findings; each call has the key
  `content_autopilot:{decision uid}:draft:{n}`; a paid attempt is never repeated; a crashed-after-paid draft is held, not re-bought.
* **Validation (`AutopilotArticleValidator`, deterministic — no model judges its own work).** *Hard* (stops the article): everything
  the Content Engine already refuses to publish (unsupported price, years, award, review score, count, guarantee, celebrity —
  the owner's confirmed years allowed), shorter than the brief, a quotation, a phrase the niche or owner prohibits, a strong
  overlap with a page or article. *Soft* (drafted, but never self-publishing — the owner decides): a percentage or superlative,
  a number that is in none of the brief's facts (small counts and this/next year are fine), no internal link when the brief
  offered some. `validation.ready` = no findings at all.
* **Known limit:** locations and proper nouns are not gazetteer-checked; they are controlled by the prompt, the closed fact set
  and the trust ramp. A soft-finding judge (cheap route) is *not* built: it could not remove the owner's approval, so it would
  only add cost.

## 7. The daily run, cadence and self-publishing (Slice 6)

`php artisan content:autopilot-tick` (daily 03:30) is free: it only queues one `RunContentAutopilotJob` per Business that is
switched on and not owner-paused, delayed by a stable per-Business offset across the next hours. `AutopilotRunner::run()` is
also free (no model call); the only paid step is the `WriteAutopilotArticleJob` it may queue.

A run: **gates** (on · not paused · `SeoModule` in the plan · has a website — otherwise it records *why*: `plan`, `no_website`,
`budget`; an owner pause is never overwritten by the system) → **reconcile** (decisions follow the article: published /
scheduled / archived; a scheduled article that `publish-due` returned to Draft waits for the owner and is never re-scheduled
on its own) → **resume** (a dead worker is released; budget-deferred work continues only once the budget period has rolled
over; a provider outage is retried up to 3 times, 6 h apart, then given up) → **publish** → **start**.

**Start** — at most one new article per run, and usually none: nothing is started while work is in flight, while
`max_awaiting_approval` (2) drafts wait for the owner, or within `min_days_between_articles` (5) of the previous one; otherwise the
planner may still decide *none*. The monthly maximum (4) stays a ceiling.

**Self-publishing (`AutopilotPublisher`)** — a draft schedules itself only if ALL hold: validation found nothing at all · the
niche is `standard` with `auto_publish: allowed` (unknown ⇒ no) · **trust ramp over**: the Business already has
`ContentPolicy::TRUST_RAMP_ARTICLES` (2) Autopilot articles published, i.e. the owner approved the first ones themselves.
Otherwise it stays an ordinary Draft awaiting approval. "Self-publishing" is `ArticleManager::schedule` at the next slot — a
weekday 09:00–11:59 in the Business's own time zone, jittered per article, at least `min_days_between_articles` after the
previous Autopilot article — and the existing `articles:publish-due` takes it live and **re-checks it first**. There is no second
publishing path. `AutopilotSwitch` is the only writer of `enabled` / `paused_reason`.

## 8. Owner experience (Slice 7)

**Content → Autopilot** (`…/seo/content/autopilot`) is the owner's home for Content; with the SEO module it is the Content
group's landing. Ordinary owners never choose from a list of SEO topics.

* **Switch** — ON / PAUSED / OFF with Turn on · Pause · Resume · Turn off (`AutopilotSwitch`; off never touches an article).
  Turning on the first time offers the short, optional profile (a skip is fine). A pause by the system says why in plain
  words (this month's allowance used, waiting for the website, not in the plan) and never shows an amount.
* **Next up** — the one thing happening: scheduled (with its date in the Business's time zone), being written, waiting for
  next month's allowance, ready to review, or an honest "nothing worth writing right now".
* **This month** — "X published · Y planned", with "Up to N a month — and fewer is perfectly fine".
* **Needs your input** — only when Autopilot genuinely needs one fact; one question, one box. Answering adds to the Content
  Profile (never replaces it), closes the question and queues a free run.
* **Awaiting your approval** — drafts with the reason each is not publishing itself (first articles, niche policy, or "worth a
  quick look: …"), a Preview and the ordinary editor. **Recently published** — what went live.
* **Manual path** — "Browse topic ideas" (Opportunities) and "Content plan" stay one quiet link away and as muted page tabs.

**Navigation decision.** Sidebar: `Content → Autopilot · Articles` (Core: a single `Content` link to Articles). The Content
Plan and Opportunities are no longer sidebar entries; the routes, pages and AI-draft flow are unchanged, and Autopilot owns
the highlight on those pages. `…/autopilot*`, `…/plan` and `…/opportunities` all select the Autopilot child.

**Sidebar fix (Slice 0).** The Content group is built with `active = false` like SEO and Ads; expansion comes from
`MenuItem::hasActiveChild()` (`open`, `aria-expanded`). Checked in a real browser: parent background neutral, submenu
expanded, only the selected child accented.
