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
* Not changed: `enforce_budgets_for_existing_categories` still governs the pre-existing categories; the manual
  "AI draft" button keeps using `website_generation` until Slice 5 moves it onto the Autopilot category/ceiling.

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
