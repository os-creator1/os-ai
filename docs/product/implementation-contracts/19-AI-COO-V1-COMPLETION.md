# Implementation Contract 19 — AI COO V1 Completion

**Status:** Planning contract only. Does not authorize implementation. No
production code accompanies this document. Written against `main` @
`30ad21c7b33034618f3ccba0d9098035133983ff` (the recon basis; later `main`
commits do not invalidate any finding cited below, each of which names its
file and line). Nine independently authorizable sub-slices (§12/§18,
`19.A`–`19.H`) complete Blueprint §23 in dependency order; **no sub-slice
below may start without its own separate, explicit human authorization**,
matching this repository's route-3 governance (`CLAUDE.md`). Unlike
Contract 16, this slice is **not** net-new: a mature AI framework, a
cached AI insight pipeline, a deterministic recommendation engine and a
server-side approve-then-execute lifecycle all already exist on `main`.
This contract's central rule is therefore **extend the existing seams,
invent no second AI framework** (§3.4, §17).

**BLOCKING UNRESOLVED PRODUCT DECISIONS: NONE.** Where the merged COO
decision-engine contract and the later, authoritative V1 Master Blueprint
disagree, the Blueprint governs and this contract is itself the additive
amendment that reconciles them (§3.7). Every original safety intent of the
superseded wording is preserved verbatim as an enforceable rule.

## 1. Objective

Close the distance between Blueprint §23's AI COO and what `main`
actually does. Today the COO **explains** (cached AI insight on the
Business Home performance band, plus one customer-triggered "Explain
this change") and **recommends deterministically** (`NextBestMoveSelector`,
no AI in the loop). It cannot draft, cannot estimate what a paid action
will cost the customer, cannot offer an approvable action, has no
Location scope, records no View-As actor attribution, has no end-to-end
"AI said → human approved → system executed" audit chain, has no typed
entry point, and serves exactly one of §23's three authorization
contexts.

This contract specifies the completion of the remaining verbs — **draft**,
**estimate cost before any paid action**, **accept a bounded typed
question**, **execute only on explicit approval**, and **serve
Business/Agency/Platform as one component with three authorization
contexts** — entirely on top of the existing `AiGateway`, `coo_insights`
and RFC-002 approval machinery.

Its scope boundary is exact: **this contract governs the AI COO and
nothing else.** Other AI features in the product are governed by their own
Blueprint sections and are untouched here (§3.6).

## 2. Governing authority

- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §23 (AI COO) — the sole
  product-level authority for this slice, quoted in full in §3.1. Its
  second paragraph carries the only **MUST NOT** in the section and is
  the hard safety boundary this entire contract is built around.
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §8 (Home) — "An **Ask
  COO** entry point (§23) sits on Home for any actor with access to it,
  offering to explain, recommend, or draft against exactly what Home is
  already showing." This fixes the primary UI anchor (§12.G) and fixes
  the COO's context envelope to *what Home is already showing* (§5.2).
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §34 — places
  "Text/typed interaction with AI COO" in the **V1** column (browser
  voice interaction is the V2 counterpart). This is a governing V1
  obligation, not an optional extra, and it is satisfied by the bounded
  one-turn surface specified in §5.9a and built by `19.G`.
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §29 (Agency Outreach) —
  the **boundary authority**: it independently governs a different AI
  feature and establishes that not every AI use in the product is an AI
  COO action (§3.6).
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §20, §26 (budgets, caps,
  permissions, Location scope) and §32 (audit) — cited by §23 itself as
  the constraints the COO inherits rather than redefines (§6, §10).
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §21 (Plan Model) — "|
  Basic AI COO | ✓ | ✓ | ✓ |" across Core, Growth and Agency. The
  entitlement identity `PlatformFeature::AiCooBasic` is already
  `Available` in `app/Library/Entitlement/PlatformFeatureRegistry.php:75`;
  this contract adds **no** new AI entitlement (§6.3, §11).
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §30 (Platform Owner) —
  the Platform Owner's Home "follows the same five-question shape (§8) at
  platform scope", which is what makes §23's third authorization context
  a real surface rather than a slogan (§5.6, §12.H).
- `docs/product/V1-ACCEPTANCE-MATRIX.md` line 33 ("Understand what to do
  next") — the only AI acceptance row in the matrix; quoted verbatim in
  §3.1. Load-bearing: scope **BW**, entitlement **Basic AI COO: Core+**,
  actor **"Owner + staff (permission-gated)"**, and the explicit
  invariant "AI COO must never execute a paid/consequential action
  without approval (§23)".
- `docs/automation/UNIFIED-BUSINESS-HOME-AND-COO-DECISION-ENGINE-CONTRACT.md`
  — the merged, largely **shipped** contract that owns the deterministic
  COO pipeline, the AI escalation architecture, the AI budget ledger and
  model routing. Its slices AI-1, AI-2, AI-3, C-2, C-3 and H-1…H-5 are
  on `main`. This contract **extends** it, and where the later Blueprint
  requires something that contract's earlier wording forbade, this
  contract is the additive amendment (§3.7).
- `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md` §13.1, §28, §30, §31 — the
  only server-side approve-then-execute lifecycle in the product, and
  therefore the only lawful home for a COO-offered action (§5.4, §6.5).
- `docs/rfcs/RFC-005` (usage billing and wallets) — the sole authority
  for customer money; the customer-facing cost estimate in §5.3 is
  derived from its seams and from `EffectivePayerResolver`, never
  invented.
- `docs/product/implementation-contracts/04-CROSS-WORKSPACE-AGENCY-AUTH-VIEW-AS.md`
  — the View-As authorization boundary. This contract **reads** it and
  widens nothing in it (§6.4).
- `docs/rfcs/RFC-004` — a planned/unbuilt `PlatformFeature` must never
  become customer-executable before its registry entry is `Available`.
  Applied here in reverse: `AiCooBasic` is *already* `Available`, so
  every sub-slice below must assume its surfaces are customer-reachable
  the moment a route exists, and must gate accordingly (§11, §12).
- `docs/product/V1-AUTHORITY-TRACEABILITY-MATRIX.md` row 22 — **stale**.
  It still reads "Not inspected in this pass | NOT YET IMPLEMENTED /
  evidence not re-verified". That is no longer true of `main` and must
  not be cited by any implementer as current state; correcting it is an
  explicit deliverable of `19.A` (§12).
- `docs/product/V1-IMPLEMENTATION-ROADMAP.md`, Wave 6 — "| Others |
  Continue any remaining product modules (§19 messaging depth, §22 niche
  blueprint versioning, **§23 AI COO**) |". This contract takes the
  number **19** to match the Roadmap's own slice identifier;
  `V1-IMPLEMENTATION-CONTRACT-INDEX.md` indexes 01–14 only and is not
  amended by this document (§15).

## 3. Current repository reality — recon findings (recon basis: `main` @ `30ad21c7`)

### 3.1 The governing authorities, verbatim

Blueprint §23 in full (`docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md:480-499`):

> ## 23. AI COO
>
> An embedded assistant anchored on Home (§8) and reachable elsewhere in
> context. It explains what it's looking at, recommends a next action,
> drafts content (messages, automation copy, proposal text), and
> estimates cost before any paid action. It surfaces risks (wallet
> running low, an automation failing, a stalled Opportunity)
> proactively rather than only on request.
>
> The AI COO **MUST NOT** execute a consequential or externally-visible
> action (sending a message, changing a live automation, spending wallet
> funds) without explicit user approval — it drafts and recommends, the
> human confirms. It respects the same budgets, caps, permissions, and
> Location scope as the human it's assisting (§20, §26); every approved
> action it takes is logged like any other actor's action (§32). The
> same mechanism serves three contexts at different scope: a Business
> owner/staff member, an Agency owner (scoped to Agency operations and,
> through View As, one client at a time), and the Platform Owner (scoped
> to platform operations) — one component, three authorization contexts,
> never three implementations.

Acceptance matrix row (`docs/product/V1-ACCEPTANCE-MATRIX.md:33`):

> | Understand what to do next | Home + AI COO surface attention items
> and one recommendation | Basic AI COO: Core+ | BW | Owner + staff
> (permission-gated) | — | No | AI COO must never execute a
> paid/consequential action without approval (§23) | Home never shows an
> empty billing card with nothing to act on (§8) | Not verified in this
> pass |

Blueprint §8's anchor (`docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md:216-218`):

> An **Ask COO** entry point (§23) sits on Home for any actor with access
> to it, offering to explain, recommend, or draft against exactly what
> Home is already showing.

Blueprint §34's V1 row (`docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md:732`):

> | Text/typed interaction with AI COO | Browser voice interaction |

(left column = V1, right column = explicitly V2.)

Blueprint §29 in full (`docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md:630-643`),
quoted because it is this contract's scope boundary, not its subject:

> ## 29. Agency Outreach
>
> A campaign-centric prospecting workflow, separate from any individual
> client's own Conversations (§11) — Outreach targets prospective new
> clients, not existing customers' leads. Covers: a prospect list, a
> message sequence with defined timing, canned Q&A responses with an AI
> fallback for anything outside the canned set, explicit stop conditions
> (reply received, opted out, booked), and handling once a prospect books
> a meeting (handoff out of the automated sequence). Campaign enrollment
> is always an explicit action — no prospect is auto-enrolled from a
> passive list. Analytics cover sequence performance (reply rate,
> booked-meeting rate) per campaign. Outreach sends obey the same
> STOP/DND and messaging safety rules as every other outbound channel in
> the product (§19) — there is no separate, weaker safety path for
> prospecting.

### 3.2 Already shipped on `main` — what a completion contract must build **on**, not replace

- **A single, hard-budgeted AI chokepoint.** `AiGateway::complete(AiRequest): AiResult`
  (`app/Library/Ai/AiGateway.php:30`) applies six ordered gates — provider
  kill switch, zero-cap budget policy, `EntitlementManager::decide(...,
  PlatformFeature::AiCooBasic, ...)`, dormancy (`AiBusinessActivityGate::isDormant`,
  `app/Library/Ai/AiBusinessActivityGate.php:45`), route affordability +
  input ceiling, and per-request cost ceiling — then takes a locked
  reservation in `ai_usage_periods`, calls the provider **outside** any
  transaction, and commits or releases. The merged contract's §10.1 is
  explicit that this is "the **only** way the application reaches a
  model", and `tests/Feature/Security/AiGatewayBypassArchitectureTest.php`
  enforces it mechanically: no provider client and no model literal may
  exist outside `app/Library/Ai/Providers/**`.
- **A provider-cost ledger with real idempotency.** `AiUsageLedgerManager`
  (`:95` `idempotencyFamily()`, `:129` `reserve()`, `:307` `settle`,
  `:344` `expireStaleReservations`), `ai_usage_ledger` with a UNIQUE
  `idempotency_key` (`database/migrations/2026_09_16_100003_create_ai_usage_ledger_table.php:46`),
  and a scheduled sweep (`app/Jobs/Ai/ExpireStaleAiReservations.php:16`,
  `app/Console/Kernel.php:170`).
- **A scope-shaped period table.** `ai_usage_periods`
  (`database/migrations/2026_09_16_100002_...`) is already keyed on
  `(scope_type, scope_id, period_key)`, with its own docblock stating the
  design intent: "`scope_type`/`scope_id` avoids a nullable unique key".
  This is the single most important existing fact for §12.H0: the period
  table is **already** scope-generic; only its `workspace_id` column
  (NOT NULL, deliberately **no FK**) is Workspace-shaped.
- **Model routing that domain code never sees.** `AiModelRouter`
  (`:50` route choice, `:108` `estimateCostMicrousd`, `:119`
  `actualCostMicrousd`) is the only reader of `config('ai.routes')`.
- **A canonical, cached, fingerprinted, invalidatable AI insight.**
  `coo_insights` (`database/migrations/2026_09_19_100001_create_coo_insights_table.php:36-63`)
  carries `signal_fingerprint`, `facts_snapshot`, `output`,
  `prompt_version`, `policy_version`, `model_route`, `provider_model`,
  `ai_usage_ledger_entry_id`, `generated_at`/`expires_at`,
  `invalidated_at`/`invalidation_reason`, and a UNIQUE on
  `(business_id, kind, subject_type, subject_id, signal_fingerprint, prompt_version)`.
  `CooInsightFacts` (`:177-234`) owns canonicalisation, bucketing,
  fingerprinting and `factRefs`; `CooInsightOutputValidator` (`:48`) is an
  all-or-nothing gate; `CooInsightInvalidator` (`:27`) is the sole writer
  of `invalidated_at`; `CooInsightDisplayReader::forHome()` (`:46`) is
  read-never-generate.
- **Grounded-output discipline.** Validator-enforced `{class, text,
  fact_refs}` with `KNOWN`/`LIKELY`/`UNKNOWN` labelling and a causal-language
  ban; facts are "aggregates and registry facts only" — no contact names,
  phone numbers or email addresses reach a prompt (merged contract §8.3,
  §8.4).
- **A deterministic recommendation.** `NextBestMoveSelector`
  (`app/Library/Coo/NextBestMoveSelector.php:10-31`, `:49`) is a pure
  function over a fixed seven-step pool with a deterministic `WhyThis`.
  The merged contract §6.5 states plainly: "**No AI.**"
- **A real server-side approve-then-execute lifecycle.**
  `OpportunityManager::requestApproval()` (`:866`), `confirmApproval()`
  (`:1032`), `beginExecutionAttempt()` (`:1708`) +
  `assertPendingExecutionAttemptIsValid()` (`:1482`),
  `OpportunityActionHash` + `CanonicalJson`, a server-derived idempotency
  key over a UNIQUE column (`OpportunityManager.php:1057-1060`;
  `database/migrations/2026_07_19_120004_create_opportunity_action_executions_table.php`),
  the `opportunity_transitions` audit ledger, and
  `ExecuteOpportunityAction`'s outer-transaction + nested-savepoint
  pattern. RFC-002 §30 states the invariant verbatim: "there is no code
  path that mutates `Business`/`BusinessLocation`/`BusinessService`
  without an execution row and therefore without explicit customer
  approval."
- **A canonical platform-admin authority flag.**
  `app/Http/Middleware/EnsureUserIsAdministrator.php` checks
  `users.is_admin` and documents precisely why the permission-string path
  is *not* equivalent: it "treats `users.id === 1` as an unconditional
  super-admin bypass regardless of account type". §6.7 builds on the
  account-type flag for exactly this reason.
- **Entitlement identity, live.** `PlatformFeature::AiCooBasic`
  (`ai_coo_basic`) is `Available` and packaged into Core, Growth and
  Agency (`app/Library/Entitlement/PlatformFeatureRegistry.php:75`).

### 3.3 Confirmed absent on `main` — the real gap surface

Each line below was confirmed by direct file inspection, not inference.

1. **No drafting anywhere in the COO.** `CooInsightOutputValidator`
   accepts exactly the statement keys `['class', 'text', 'fact_refs']`
   (`:99`) within ≤3 statements of ≤280 characters (`:36-42`). There is
   no draft store, no draft review/edit surface and no draft category.
2. **No customer-facing cost estimate of any kind.**
   `AiUsageCostEstimate` (`app/Library/Ai/AiUsageCostEstimate.php:14-24`)
   is provider micro-USD, constructed and consumed inside
   `AiGateway::complete()`, and declared admin-only by the merged
   contract §15.4. Nothing in the recommendation domain estimates the
   *customer's* money: `grep` for cost/estimate/price/spend/budget across
   `app/Library/Opportunity/**` returns nothing.
3. **The COO cannot offer an approvable action.** Its output is read-only
   by contract ("Nothing the model writes can trigger an action", merged
   contract §12), and the only executor supports exactly one key —
   `OpportunityActionExecutor.php:27`, `private const SUPPORTED_ACTION_KEY = 'add_phone';`
   — while `OpportunityActionRegistry.php:30-117` declares eleven. The
   other ten can never reach approval.
4. **No Location scope in the COO or in the recommendation domain.**
   `coo_insights` has `business_id`/`workspace_id` and no Location
   column; `CooInsightDisplayReader` filters on `business_id`/`kind`
   only; `opportunities` is `business_id`-scoped
   (`database/migrations/2026_07_19_120002_create_opportunities_table.php:13`).
   `LocationAccessGuard` (`:73`, `:152`) exists and is simply not used by
   either subsystem.
5. **No authorization-scope identity on a cached insight at all.** There
   is no column, fingerprint or filter expressing *which actor scope* an
   insight was generated for. Two staff users with different Location
   subsets and different capability sets are, to the cache, the same
   reader. This is the single most dangerous gap in the slice and is
   closed by §5.8.
6. **View As is handled by prohibition, not attribution.**
   `CooInsightExplainController.php:56-58` is `abort(403)` when
   `CustomerContext::isViewingAsClient()`; `customer.opportunities.` is
   denylisted in `ViewAsRouteClassification.php:122`. Neither
   `coo_insights` nor `ai_usage_ledger` nor `opportunity_transitions`
   records the real-actor/viewed-actor pair.
7. **No audit chain from "AI said" to "human approved" to "system
   executed".** `ai_usage_ledger` stores spend with no subject reference
   and no content; `coo_insights` stores content with **no actor column
   at all** (migration `:36-55`); `opportunity_transitions`
   (`2026_07_19_120005_...:10-26`) records no IP, user agent, session,
   View-As linkage, model or prompt version.
8. **Approval hardening gaps in the one lifecycle that exists.** No
   entitlement check and no wallet/budget check at approval or at
   execution anywhere under `app/Library/Opportunity/**`; no per-route
   permission middleware on the approval routes
   (`routes/customer.php:650-685`); approval never expires (freshness is
   checked in exactly one place, `OpportunityManager.php:812`
   `attestComplete`); `retryFailedExecution` (`:1138-1227`) re-executes a
   previously approved mutating action without passing back through
   approval; `initiated_by_type` is decorative — only the literal
   `'customer'` is ever written (`:1090`, `:1194`, `:1370`);
   `opportunity_action_executions.started_at` is declared, cast and
   rendered but never written; the `config('opportunity.enabled')`
   kill-switch check is asymmetric across lifecycle methods; and the
   customer surface bypasses `CustomerContext` entirely, calling
   `Business::primary`-style resolution at
   `OpportunityController.php:71, 103, 140, 355`.
9. **Only one authorization context is served.** `coo_insights.business_id`
   is `NOT NULL` (`migration :37`) and `subject_type` is only ever
   written as `'business'`; there is no Agency-level AI insight
   (`resources/views/customer/dashboard/agency-home.blade.php`) and no
   Platform/admin-level AI insight (the only admin AI surface is the
   read-only usage ledger).
10. **No platform-scoped AI path exists at all.** `AiRequest`
    (`app/Library/Ai/AiRequest.php:19-31`) requires a non-nullable
    `Workspace`; `AiBudgetPolicyResolver::resolveFor(Workspace)` resolves
    from customer plans; `ai_usage_ledger.workspace_id` is NOT NULL
    (`migration :28`); `ai_usage_periods.workspace_id` is NOT NULL
    (`migration :32`) even though its unique key is already scope-generic.
    There is no legitimate "Platform Workspace" to borrow.
11. **The AI can only produce text.** `AiCompletionRequest` carries
    route/provider/model/messages/`maxOutputTokens`/json-mode only — no
    tool/function calling, therefore no action-execution boundary to
    secure at the provider layer (which is, for V1, a *safety asset*, and
    §6.5 keeps it that way).
12. **`AiRequest` carries no Location scope and no permission context**
    (`app/Library/Ai/AiRequest.php:19-31`).
13. **No typed entry point.** There is one POST route
    (`routes/customer.php:742`) that triggers a fixed "explain this
    change" generation; it accepts no user-supplied question. Blueprint
    §34 puts typed interaction in V1.
14. **Proactive risk surfacing is entirely deterministic, and the AI is
    structurally suppressed when it matters most.**
    `CooInsightFacts::hasDeterministicExplanation()` (`:72-85`) suppresses
    the AI whenever **any** Attention item is raised. §23's three named
    risks map onto deterministic Attention types today — and the third,
    "a stalled Opportunity", sits behind
    `config/opportunity.php:4`, `'enabled' => env('OPPORTUNITY_ENGINE_ENABLED', false)`.
15. **The explain rate limit is cache-only, business-keyed and
    controller-only.** `CooInsightExplainLimiter.php:22-30` uses
    `Cache::add`/`Cache::has` keyed on business — not durable, not
    auditable, not per-actor — and the generator's own condition for
    `ExplainThisChange` is unconditionally `true`
    (`CooInsightGenerator.php:199-201`).
16. **`policy_version` is not part of the database UNIQUE key**, only of
    the fingerprint and the selection filter (`migration :57-60`).
17. **Two idempotency keys in the AI stack are random.**
    `WebsiteAiGenerationClient.php:83` and `CampaignController.php:3001`
    pass `Str::uuid()`, which makes the ledger's UNIQUE column decorative
    for those callers.
18. **No provider timeout, retry policy or circuit breaker.**
    `OpenAiCompletionClient.php:34` constructs the client with no timeout
    or HTTP options.

### 3.4 Reusable existing conventions — mirrored, not reinvented, in §5–§11

`AiGateway::complete` · `AiRequest`/`AiResult` · `AiUsageCategory` +
`config('ai.category_routes')` · `AiLane::Interactive` and its hard-capped
share · `AiModelRouter` · `AiUsageLedgerManager::idempotencyFamily/reserve/settle`
· `AiBudgetPolicyResolver::resolveFor` · `AiBudgetPolicy` ·
`ai_usage_periods`' existing `scope_type`/`scope_id` design ·
`AiRefusalReason`'s nine typed refusals · `AiBusinessActivityGate::isDormant`
· `AiCompletionClient`/`FakeAiCompletionClient` · `CooInsightFacts`
(canonicalJson/bucketed/fingerprint/factRefs) · `CooInsightFactsReader`
· `CooInsightPromptBuilder` · `CooInsightOutputValidator` ·
`CooInsightInvalidator` · `CooInsightDisplayReader::forHome` ·
`CooInsightTrigger::lane()/category()/isDormancyGated()` ·
`GenerateCooInsight`'s queued, `tries=1`, `ShouldQueueAfterCommit`
pattern · `SignalComparator` + `config('coo.materiality.*')` ·
`NextBestMoveSelector`/`WhyThis` · `OpportunityManager` ·
`OpportunityActionRegistry` · `OpportunityActionExecutor::supports()` ·
`OpportunityActionHash` + `CanonicalJson` ·
`OpportunityRepository::findOwnedForUpdate()` ·
`OpportunityTransitionRepository` · `ExecuteOpportunityAction` ·
`EntitlementManager::decide` + `PlatformFeature::AiCooBasic` ·
`LocationAccessGuard` · `UsageWalletManager::reserve/commit/release` +
`EffectivePayerResolver` · `CustomerContext` + `ResolveCustomerContext` ·
`ViewAsRouteClassification` + `ViewAsProhibitedActions` ·
`EnsureUserIsAdministrator` / `users.is_admin` · `DashboardLinkGate` ·
`MenuEntitlements::allows()` · `DashboardSnapshot` band registry +
`band-failed.blade.php` · `window.AsyncRegion` · the `x-card`/`x-badge`/
`x-button`/`x-alert`/`x-empty-state`/`x-dialog` design-system components.

**Contract rule R-0:** every sub-slice below is a consumer or extender of
the list above. A sub-slice that introduces a second gateway, a second
insight table, a second approval lifecycle, a second audit ledger, a
second entitlement identity, a second AI budget mechanism or a second
Location ACL is out of contract by definition, regardless of how
convenient it is.

### 3.5 Positions this contract keeps, and positions it amends

The merged COO contract locked three positions that a §23 completion must
either keep or amend. Stated explicitly so no implementer silently
overrides — or silently preserves — the wrong one.

| Locked position | Where | Disposition |
|---|---|---|
| "Nothing the model writes can trigger an action. Actions stay behind the existing Opportunity approval flow." | merged contract §12 | **KEPT, unchanged, and hardened into a mechanical test** (§6.5 R-10). §23 is satisfied by letting the COO *offer* an action a human approves through RFC-002 — the model still triggers nothing. |
| "**One Business per prompt.** A prompt contains facts from exactly one Business. There is no cross-client prompt, including for the Agency Account Home." | merged contract §15.2 | **AMENDED additively** (§3.7 A-1). The safety intent — never mix several clients' Business facts into one prompt — is preserved verbatim as rules R-14…R-17 and is *strengthened*, not relaxed. |
| View-as sessions "never start an interactive AI request, and never trigger explain-this-change" | merged contract §15.5 | **AMENDED narrowly** (§3.7 A-2). Read/explain/draft permitted under View As **with real-actor attribution**; approval and execution of consequential actions remain prohibited (§6.4). |
| Decision D-5 — interactive/typed COO deferred, slice AI-4 unscheduled | merged contract | **SUPERSEDED for the bounded one-turn surface only** (§3.7 A-3), because Blueprint §34 places typed interaction in V1. Multi-turn memory and the reserved thread tables stay deferred (§5.9a, §15). |

### 3.6 Scope boundary — §23 governs the AI COO, not every AI feature

Blueprint §23 defines the AI COO: an embedded assistant that explains,
recommends, drafts and estimates, and whose **MUST NOT** binds *the AI
COO* to "drafts and recommends, the human confirms".

Blueprint §29 independently defines Agency Outreach: "a message sequence
with defined timing, canned Q&A responses **with an AI fallback for
anything outside the canned set**", entered only by explicit enrollment,
with defined stop conditions, and bound to "the same STOP/DND and
messaging safety rules as every other outbound channel in the product
(§19) — there is no separate, weaker safety path for prospecting."

**Rule R-18 (feature boundary).** An AI feature is governed by the
Blueprint section that defines it. Using a model does not make a feature
an AI COO action, and §23's approval boundary does not reach a feature
§29 independently authorizes. Agency Outreach's AI fallback is an
Outreach capability governed by §29 plus §19 messaging safety; its
human-consent model is *explicit campaign enrollment plus stop
conditions*, not per-message COO approval.

**Consequences for this contract, binding:**

- Contract 19 touches **no** Agency Outreach / prospecting implementation.
  `app/Jobs/AgencyProspectingRespondJob.php` and every other Outreach
  file are outside this slice entirely (§15, §17).
- No sub-slice proposes a Blueprint exception, carve-out or remediation
  for Agency Outreach.
- AI COO compliance is claimed from AI COO behavior alone (§14), never
  conditioned on an unrelated AI feature.
- The structural boundary this contract *does* enforce is narrow and
  exact: nothing under `app/Library/Coo/**`, no COO controller and no COO
  job may itself send, publish, spend, or change permissions (§6.5 R-10).
  That test constrains the COO's own code, not the rest of the product.

### 3.7 Authority reconciliations this contract makes

The V1 Master Blueprint is the later, authoritative product document. Where
the merged COO decision-engine contract's earlier wording conflicts with
it, this contract is the additive amendment. Three reconciliations, each
preserving the original safety intent:

**A-1 — Agency and Platform scope.** §23 requires "one component, three
authorization contexts, never three implementations". The merged
contract's "one Business per prompt … no cross-client prompt, including
for the Agency Account Home" predates it. **Amendment:** the rule is
reformulated as a *content* rule rather than a *scope* rule — a prompt
that contains Client Business facts contains exactly one Business's
facts, always; Agency-scope prompts contain the Agency's own operational
facts only; Platform-scope prompts contain platform-operational facts
only. No prompt in any scope ever contains more than one Client
Business's facts. The original prohibition on an Agency portfolio prompt
over several clients is preserved **verbatim in force** (R-16). Encoded
as rules R-14…R-17 (§5.6) with their own test battery (§13.3).

**A-2 — View As.** §23 requires the Agency owner to reach "one client at
a time" through View As. The merged contract's blanket "view-as sessions
never start an interactive AI request" predates it. **Amendment:**
contextual *assistance* (read, explain, draft) is permitted under View As
with the real actor attributed; *consequential* approval and execution
remain prohibited exactly as today (§6.4). Contract 04's financial and
security boundaries are read, not widened.

**A-3 — Typed interaction.** Blueprint §34 places "Text/typed interaction
with AI COO" in the V1 column; merged decision D-5 deferred interactive
COO. **Amendment:** the smallest compliant V1 surface is specified
(§5.9a) — one bounded typed turn, stateless, contextual to the authorized
envelope. No conversation memory, no thread tables, no tool calling, no
autonomous action. `coo_threads`/`coo_thread_messages` remain reserved
for a future multi-turn slice and are **not** claimed by this contract.

## 4. Gap map — every Blueprint §23 requirement, classified

Classification vocabulary as required: **complete** / **partial** /
**missing** / **incompatible** (implemented but architecture-incompatible).

| # | §23 requirement (source phrase) | Class | Evidence on the recon basis | Closed by |
|---|---|---|---|---|
| 1 | "anchored on Home (§8)" | **partial** | One cached insight block nested inside the headlines band (`bands/headlines.blade.php:76-98`), bound to the analytics period key; not a first-class COO surface, and §8's "Ask COO" entry point does not exist | `19.G` |
| 2 | "and reachable elsewhere in context" | **missing** | Exactly one read on Home (`BusinessHomePresenter.php:713-719`) and one POST route (`routes/customer.php:742`) scoped to the performance band | `19.G` |
| 3 | "explains what it's looking at" | **complete** | Cached "What we notice" + customer-triggered "Explain this change"; validator-enforced grounding; `CooInsightDisplayReader` read-never-generate | — (preserved) |
| 4 | "recommends a next action" | **complete (deterministic)** | `NextBestMoveSelector.php:49` fixed seven-step pool + `WhyThis`; merged contract §6.5 "**No AI.**" | — (preserved; `19.C` adds an AI *explanation of* it, never a competing AI recommendation) |
| 5 | …but the recommendation is unlinkable to the AI that explains it | **incompatible** | `NextBestMove.php:13-15` — "nothing is persisted: a move is recomputed on every render"; `CooInsightKind::move_explanation` is reserved and deliberately unused (`CooInsightKind.php:8-14`); no FK, no shared shape | `19.C` |
| 6 | "drafts content (messages, automation copy, proposal text)" | **missing** | No draft store, no draft surface, no draft category; validator output is `{class,text,fact_refs}` only (`CooInsightOutputValidator.php:99`) | `19.F` |
| 7 | "estimates cost before any paid action" | **missing** | Only provider micro-USD exists, admin-only by merged contract §15.4 (`AiUsageCostEstimate.php:14-24`); nothing estimates customer money anywhere in the recommendation domain | `19.E` |
| 8 | "surfaces risks … proactively rather than only on request" | **partial** | Deterministic Attention items cover wallet/automation/website/Google; the AI is *suppressed* whenever any Attention item is raised (`CooInsightFacts.php:72-85`); the "stalled Opportunity" risk is behind `config/opportunity.php:4` default-false | `19.C` (surfacing), `19.D` (engine readiness) |
| 9 | "**MUST NOT** execute … without explicit user approval" | **complete** | Merged contract §12: "Nothing the model writes can trigger an action"; the gateway is text-only (`AiCompletionRequest.php:14-22`), so there is no tool-calling path to secure | — (preserved; made mechanical by `19.F`'s R-10 test) |
| 10 | "Text/typed interaction with AI COO" (§34, V1, same component) | **missing** | One fixed-purpose POST route that accepts no user question (`routes/customer.php:742`) | `19.G` |
| 11 | "it drafts and recommends, the human confirms" — a server-side approval authority | **partial** | RFC-002 has a real lifecycle (`requestApproval` `:866`, `confirmApproval` `:1032`, `beginExecutionAttempt` `:1708`, action hash, idempotency, transitions) but it is unreachable from any COO path, supports one action key (`OpportunityActionExecutor.php:27`), and has no entitlement/permission/wallet/Location check | `19.D` |
| 12 | "No stale pre-approval decision grants future permission" | **partial** | `OpportunityActionHash` genuinely binds the approved parameters; but approvals never expire, and `retryFailedExecution` (`:1138-1227`) re-executes without re-approval | `19.D` |
| 13 | "respects the same … budgets, caps" (§20) | **partial for AI spend, missing for customer money** | `AiBudgetPolicyResolver`/`AiUsageLedgerManager` are real and enforced for Workspace scope; no wallet/balance/cap check exists anywhere in the approval or execution path; no platform AI budget path exists at all | `19.D` (guard chain), `19.E` (estimate), `19.H0` (platform cap) |
| 14 | "…permissions" (§26) | **partial** | The gateway checks entitlement; `DashboardLinkGate`/`MenuEntitlements` gate Home links; but the Opportunity approval routes carry no per-route capability check (`routes/customer.php:650-685`) and `OpportunityController` has no `authorize()` call | `19.B`, `19.D` |
| 15 | "…and Location scope" (§26) | **missing** | No Location column, filter or guard in `app/Library/Coo/**`, `coo_insights`, `opportunities`, or `AiRequest`; and no authorization-scope identity on the cache at all (§3.3(5)) | `19.A`, `19.B` |
| 16 | "every approved action it takes is logged like any other actor's action (§32)" | **missing** | Three unrelated stores, no actor column on `coo_insights`, no request context on `opportunity_transitions`, no join from spend → content → approval → execution | `19.A` (attribution), `19.D` (chain) |
| 17 | "a Business owner/staff member" context | **complete** | The only shipped context | — |
| 18 | "an Agency owner (scoped to Agency operations and, through View As, one client at a time)" | **incompatible** | `coo_insights.business_id` is `NOT NULL` with no `scope` discriminator; View As is a hard `abort(403)` on the one interactive entry (`CooInsightExplainController.php:56-58`) | `19.A` (schema + attribution), `19.H` (activation) |
| 19 | "the Platform Owner (scoped to platform operations)" | **incompatible** | Same schema constraint, **plus** no platform-capable AI path exists: `AiRequest` requires a Workspace, the budget resolver resolves customer plans, and both AI billing tables have `workspace_id NOT NULL` (§3.3(10)) | `19.H0` (foundation) → `19.H` (activation) |
| 20 | "one component, three authorization contexts, never three implementations" | **missing** | No scope abstraction exists; the pipeline hard-codes Business | `19.A` (`CooScope`), `19.H0`/`19.H` (activation) |

Summary: of twenty obligations — **4 complete** (3, 4, 9, 17),
**6 partial** (1, 8, 11, 12, 13, 14), **7 missing** (2, 6, 7, 10, 15, 16,
20) and **3 architecture-incompatible** (5, 18, 19). None is blocked on a
product decision. Row 5 is the unlinked deterministic-move / AI-explanation
pair, fixed cheaply by `19.C`. Rows 18 and 19 share the Business-only
`coo_insights` schema as one root cause, closed by `19.A`; row 19 carries
a second, purely mechanical root cause — the absence of any platform AI
authorization/budget/ledger path — which is why `19.H0` exists.

## 5. Canonical domain model

### 5.1 One recommendation vocabulary — no third mechanism

There are already two recommendation mechanisms and one AI-output
mechanism. This contract adds **none**. It binds them:

| Concept | Canonical home | Role after this contract |
|---|---|---|
| *What the customer should do next* | `NextBestMoveSelector` → `NextBestMove` + `WhyThis` | Unchanged. Still deterministic, still the only thing that decides **what** is shown, still never written by AI. |
| *Why, in plain language* | `coo_insights`, `kind = move_explanation` (reserved at `CooInsightKind.php:8-14`, activated by `19.C`) | The AI's entire contribution to recommendation: an explanation **of** the deterministic move, cached, fingerprinted, invalidatable, grounded in `fact_refs`. |
| *An action the human may approve* | RFC-002 `Opportunity` + `opportunity_action_executions` | The only approvable, executable record in the product. A COO "offer" is an Opportunity action, never a new kind of thing. |
| *Content the AI wrote for a human to edit* | `coo_drafts` (net-new, `19.F`) | The one genuinely new table, justified in §5.5. |
| *A typed question and its one-turn answer* | `coo_insights`, `kind = ask_response`, `origin = on_demand` (`19.G`) | No new table. No thread. No memory. (§5.9a) |

**Rule R-1 (no competing recommendation).** The AI must never select,
rank, reorder, suppress or invent a next action. `19.C` renders an AI
explanation *attached to* the deterministic move; if the explanation is
absent, refused or invalidated, the move still renders. This preserves
merged contract §7.4 ("AI **does not** create, rank, reorder or hide
opportunities in v1") exactly.

To make the binding possible, `19.C` writes `coo_insights` rows with
`subject_type = 'next_best_move'` and `subject_id` = a deterministic,
stable identifier for the selected move (its pool key plus its subject
reference), rather than persisting `NextBestMove` itself. Nothing about
`NextBestMove`'s recompute-per-render design changes.

### 5.2 The context envelope — `CooContextEnvelope`

Every COO request, at every stage, carries one immutable value object.
It is assembled once from `CustomerContext` (or, at Platform scope, from
the admin boundary) and never widened downstream.

| Field | Source | Purpose |
|---|---|---|
| `scope` | `CooScope::Business` \| `Agency` \| `Platform` | The one component's three authorization contexts (§5.6). |
| `workspace_id` | `CustomerContext::frameWorkspace()` | Budget owner and entitlement subject at Business and Agency scope. **Null only at Platform scope.** |
| `business_id` | `CustomerContext::selectedBusiness()` at Business scope; the Agency Workspace's own sole canonical Business at Agency scope | **Non-null at Business and Agency scope** (Workspace : Business is 1:1 under the frozen topology, Contract 13). Null only at Platform scope. |
| `business_location_id` | an explicitly pinned single Location, when one is pinned | A *pin*, not an authorization key. `null` does **not** mean "all Locations" for cache purposes — see `authorization_scope_fingerprint`. |
| `authorized_location_ids` | `LocationAccessGuard`, sorted ascending | The actual set of Locations this actor may read. Feeds the fingerprint (§5.8). |
| `capability_keys` | the customer-permission snapshot, sorted, restricted to keys fact composition actually consults | Which facts may be composed at all. Feeds the fingerprint (§5.8). |
| `authorization_scope_fingerprint` | derived (§5.8) | **The cache identity.** Non-null in every scope. |
| `actor_user_id` | the authenticated human | The real human. Never the viewed client. |
| `view_as_session_id` | `CustomerContext` View-As state | Nullable. Non-null means an Agency user is viewing a client (§6.4). |
| `view_as_target_business_id` | View-As state | Part of the fingerprint, so a View-As read can never reuse an ordinary read's cache entry or vice versa. |
| `entitlements` | `MenuEntitlements::allows()` snapshot | Same snapshot Home already uses; never re-derived ad hoc. |
| `platform_admin` | `users.is_admin`, re-read from the database (§6.7) | Required true at Platform scope; always false otherwise. |
| `subject_type` / `subject_id` | the surface | What the COO is looking at. |
| `surface_route` | the current route name | Classified in `ViewAsRouteClassification` (§6.4) and recorded in audit. |
| `facts_fingerprint` | `CooInsightFacts::fingerprint()` | Signal-staleness identity (§5.7). |
| `prompt_version` / `policy_version` | `config/coo.php` | Retirement identity (§5.7). |

**Rule R-2 (assemble once, narrow only).** No COO component may read
tenancy or permission state directly. It receives the envelope. A
component may narrow the envelope (e.g. pin a Location); nothing may
widen it. An envelope is re-assembled — never restored from storage —
at approval time and again at execution time, and compared to the
recorded one (§6.5).

### 5.3 Cost estimate semantics — two costs, never conflated

| | Provider cost | **Action cost** (net-new) |
|---|---|---|
| Measures | tokens the platform buys | the customer's money or credits the approved action will spend |
| Unit | micro-USD | the payer's currency minor units and/or a unit count (e.g. SMS segments) |
| Authority | `AiModelRouter::estimateCostMicrousd` → `AiUsageCostEstimate` | RFC-005 seams: the existing pricing/segmentation readers + `EffectivePayerResolver` + `UsageWalletManager` |
| Audience | platform admin only (merged contract §15.4) | **the approving human, before approval** |
| Computed by | the gateway | deterministic server code — **never the model** |

`ActionCostEstimate` (value object, `19.E`) carries: `payer_type`,
`payer_workspace_id`, `currency_code`, `amount_minor_upper_bound`,
`unit_count` + `unit_kind` where applicable, `basis`
(`exact` \| `upper_bound`), `price_version`, `estimated_at`,
`expires_at`, and `wallet_sufficient` (a boolean derived from the payer's
balance at estimate time, never a promise).

**Rule R-3 (the estimate is a ceiling, and it is re-checked).** The
estimate shown before approval is stored on the approval record as an
approved ceiling. At execution time the estimate is recomputed from
live state. If the recomputed cost exceeds the approved ceiling, or the
`price_version` changed, or the payer changed, execution **refuses** and
the action returns to `awaiting_approval` with a typed reason. A ceiling
is never silently raised. This is the concrete form of "no stale
pre-approval decision grants future permission".

**Rule R-4 (no model-authored numbers).** A cost, price, balance, count
or date shown to a customer is never taken from model output. The
validator's `fact_refs` mechanism already enforces grounding for
insights; `19.E`'s estimator is plain deterministic code and its output
never passes through a prompt.

### 5.4 Approval / request / action lifecycle — reused, hardened, extended

No new lifecycle. The RFC-002 state machine is the lifecycle. What
`19.D` adds:

1. **`initiated_by_type` becomes real.** Today only `'customer'` is ever
   written (`OpportunityManager.php:1090, :1194, :1370`). It gains
   `'coo'` — meaning *the COO proposed this* — and the invariant that
   **the confirming principal is always a human**: an approval whose
   `confirmed_by_user_id` is absent, or whose confirming principal type
   is `'coo'`, is rejected. Self-approval by a human (same person
   requests and confirms) stays permitted — §23 requires *a* human
   confirmation, not four eyes.
2. **A guard chain at approval and again at each execution attempt**, in
   this fixed order: kill switch → tenancy (`findOwnedForUpdate`) →
   capability (the actor's feature permission for the action's domain) →
   Location (`LocationAccessGuard` for a Location-bound action) →
   entitlement (`EntitlementManager::decide`) → action-hash match →
   approval freshness → **paid-effect guards** (payer resolution, wallet
   sufficiency, cost ceiling — §5.3) → idempotency claim. Every gate is
   re-evaluated at execution; none is inherited from the approval.
3. **`approval_expires_at`.** An approval that is not executed within
   its window expires and must be re-requested. Default window in
   `config/opportunity.php`, never a literal.
4. **Retry re-enters approval.** `retryFailedExecution` may retry a
   *non-mutating, non-paid* action under its original approval; a
   mutating or paid-effect action that failed must be re-approved.
5. **`paid_effect` becomes a first-class registry flag** on
   `OpportunityActionRegistry` alongside the existing
   `approval_required`/`mutates_business` flags. `paid_effect = true`
   requires an `ActionCostEstimate` on the approval record; the executor
   refuses to run a `paid_effect` action that has none.
6. **The executor becomes registry-driven.** `SUPPORTED_ACTION_KEY`
   (`OpportunityActionExecutor.php:27`) is replaced by a handler map
   resolved from the registry, preserving `supports()` (`:61`) as the
   capability predicate the manager already consults, and preserving both
   hash-mismatch guards (`:184`, `:195`) unchanged. **No new action key
   is added by `19.D`** — the map is introduced with exactly the one
   existing handler, so the hardening lands without widening what can
   execute.
7. **`started_at` is written**, and the kill-switch check is made
   symmetric across every lifecycle method.
8. **The customer surface routes through `CustomerContext`** instead of
   primary-Business resolution (`OpportunityController.php:71, 103, 140, 355`).

### 5.5 `coo_drafts` — the one new table, and why it is not `coo_insights`

A draft is not an insight. An insight is a cached, fingerprint-keyed,
machine-invalidated *description* that a human never edits and that is
displayed or not displayed. A draft is customer-facing *content* with a
human editing lifecycle, an author distinction (model vs. human), and a
consumption event. Storing drafts as insight rows would corrupt the
insight table's unique key (a human edit does not change the signal
fingerprint), its invalidation semantics (invalidating a draft a human
already edited would destroy their work), and its all-or-nothing
validator (a 280-character, three-statement budget cannot hold a
message body).

**Columns.** `uid`; `scope` (`business` \| `agency` \| `platform`);
`workspace_id` (nullable — **Platform only**); `business_id` (nullable —
**Platform only**); `business_location_id` (nullable pin);
`authorization_scope_fingerprint` (NOT NULL, §5.8); `draft_kind`
(`message` \| `automation_copy` \| `proposal_text`); `subject_type` /
`subject_id`; `model_body` (immutable — what the AI produced);
`edited_body` (nullable — what the human made of it); `status`
(`drafted` \| `edited` \| `discarded` \| `consumed`); `consumed_by_type` /
`consumed_by_id`; `actor_user_id` (**NOT NULL** — a draft is always
produced on an explicit human request); `view_as_session_id` (nullable);
`ai_usage_ledger_entry_id`; `prompt_version`; `policy_version`;
`facts_fingerprint`; timestamps + `expires_at`.

**Scope invariants (writer-enforced, tested — §13.6):**

| Scope | `workspace_id` | `business_id` | Requires |
|---|---|---|---|
| `business` | NOT NULL | NOT NULL | ordinary Workspace AI authority |
| `agency` | NOT NULL (Agency Workspace) | NOT NULL (that Workspace's sole canonical Business) | ordinary Workspace AI authority (§5.6) |
| `platform` | **NULL** | **NULL** | `19.H0`'s platform gateway/budget path (§5.7a) |

**Rule R-5 (a draft is inert).** Nothing consumes a draft automatically.
A draft becomes an outbound message, automation copy or proposal text
only through that feature's own existing, human-initiated path, with
that path's own authorization, and — where the path is paid — with an
`ActionCostEstimate` shown first. A draft carries no schedule, no
recipient commitment and no send permission.

**Rule R-6 (drafting prompts carry placeholders, not people).** The
merged contract already bars contact names, phone numbers and email
addresses from prompts (§8.3). Drafting does not get an exemption: a
drafting prompt receives role placeholders (`{{first_name}}`,
`{{business_name}}`, `{{booking_url}}`) and the merge happens
deterministically at consumption time in the consuming feature. This
keeps the existing PII boundary intact and makes a draft reusable across
recipients.

**Rule R-19 (no fake tenant).** A platform-scope draft stores NULL in
`workspace_id` and `business_id`. It never stores `0`, a sentinel id, a
"platform" Workspace row, or any other fabricated tenant.

### 5.6 Scope — one component, three authorization contexts

`CooScope` is an enum plus a resolver that answers four questions for a
given envelope: *which facts reader*, *which entitlement and AI-budget
authority*, *which offerable action set*, *which surfaces*.

| Scope | Facts | AI authority / budget | Offerable actions | Foundation needed |
|---|---|---|---|---|
| `Business` | `CooInsightFactsReader`, exactly one Business, actor-capability and Location filtered | the Business's Workspace; `AiCooBasic` against that Business | Business-scoped Opportunity actions | none (`19.A`–`19.G`) |
| `Agency` | the Agency's **own operational facts only** — client counts and relationship-level aggregates the Agency Workspace genuinely owns, never any client's Business facts | **the Agency Workspace and its sole canonical Business** — `AiCooBasic` decided against that Agency Business, budget drawn from that Workspace's ordinary AI policy | Agency-scoped actions only | none — reuses existing Workspace AI authority (`19.H`) |
| `Platform` | platform-operational aggregates only (§30's five-question shape at platform scope) | **no tenant**: `AiScope::Platform`, platform-admin authority, finite platform cap | platform actions only | **`19.H0`** |

**Why Agency needs no new AI authority.** Under the frozen V1 topology
(Contract 13), Workspace : Business is 1:1 and an Agency Workspace holds
exactly one Agency Business. An Agency-scope COO request therefore has a
real Workspace *and* a real Business to present to the existing gateway:
`AiRequest` needs no nullable Business, `EntitlementManager::decide` gets
a genuine Business, `AiBudgetPolicyResolver::resolveFor` gets a genuine
Workspace, and both AI billing tables get their existing NOT NULL
columns. **No second Agency AI budget is invented.**

**The four scope-content rules — the preserved safety intent of the
superseded "one Business per prompt" wording:**

- **R-14.** A prompt that contains Client Business facts contains the
  facts of **exactly one** Business. Always. In every scope.
- **R-15.** An Agency-scope prompt contains the Agency's own operational
  facts only. It never contains any client's Business facts — not one
  client's, and not an anonymised or aggregated blend of several.
- **R-16.** There is **no cross-client portfolio prompt**, for the Agency
  Account Home or anywhere else. This prohibition is carried forward
  unchanged from the superseded wording and is strengthened by being
  tested (§13.3).
- **R-17.** An Agency actor reaches one managed Client's Business COO
  **only** through View As, which resolves the envelope to
  `CooScope::Business` for that one client, with the real Agency actor
  attributed (§6.4). It does not produce an Agency-scope prompt about
  that client.

Additionally, the Agency Business's *own* ordinary customer-facing
operational facts (its own campaigns, its own contacts) are **not**
automatically Agency-scope facts. A fact enters Agency scope only if it
is genuinely part of the Agency context — agency operations, client
relationships, agency-level risk. The Agency Business's own Business-scope
COO remains the Business-scope surface, reached the ordinary way.

### 5.7 Staleness and invalidation

Existing mechanism, extended — not replaced:

1. **Signal change** → `CooInsightFacts::fingerprint()` differs → a new
   row, old row simply not selected. Unchanged.
2. **Workflow/event change** → `CooInsightInvalidator` (`:27`) remains the
   sole writer of `invalidated_at`; `19.C`/`19.F`/`19.G` add reasons to
   its existing vocabulary rather than new writers.
3. **Prompt/policy retirement** → `prompt_version`/`policy_version`.
   `19.A` adds `policy_version` to the database UNIQUE identity, closing
   §3.3(16): today two rows differing only by `policy_version` collide.
4. **Authorization-scope change** → `authorization_scope_fingerprint`
   differs → the old row is instantly unselectable for the changed actor,
   with no invalidation sweep required and no risk of a stale grant being
   honoured (§5.8).
5. **Draft staleness** → a draft whose `facts_fingerprint` no longer
   matches is marked stale on read and shown with an explicit "facts
   changed since this was drafted" notice; it is never silently
   regenerated and never silently consumed.
6. **Approval staleness** → `OpportunityActionHash` (parameters) +
   `approval_expires_at` (time) + the re-run guard chain (authority) +
   the recomputed cost ceiling (money). All four, every execution
   attempt (§5.4, §6.5).

#### 5.7a Platform AI scope foundation — the design `19.H0` implements

Platform scope is the only context that cannot reuse a tenant. The
extension below is the smallest one that keeps **the same** gateway,
router, reservation protocol, ledger and settle path.

**A. `AiRequest` gains an explicit authorization/budget scope
discriminator.** A new `App\Library\Ai\Enums\AiScope` with cases
`Workspace` and `Platform`. `AiRequest::$workspace` becomes `?Workspace`
and a new promoted property `public AiScope $scope = AiScope::Workspace`
is **appended after the existing `jsonMode` parameter**, so every one of
the four existing positional call sites compiles and behaves byte-identically
with no edit. The constructor asserts the invariant:

- `AiScope::Workspace` ⟺ `workspace !== null` (business may be null, as
  today);
- `AiScope::Platform` ⟺ `workspace === null` **and** `business === null`
  **and** `actorUserId !== null`.

**B. Backwards compatibility is structural, not promised.** Named
constructors `AiRequest::forWorkspace(...)` and `AiRequest::forPlatform(...)`
are added for clarity; the raw constructor keeps its current parameter
order. No existing caller changes.

**C. Platform authorization.** A platform request carries no Workspace
and no Business, so **no customer `EntitlementManager` Business-feature
decision is fabricated for it**. Authority is a dedicated
`PlatformAiAuthority` service that resolves `actorUserId` to a freshly
read `User` and requires `is_admin` — the account-type flag, the same one
`EnsureUserIsAdministrator` uses and for the reason that middleware's own
docblock gives: the permission-string path "treats `users.id === 1` as an
unconditional super-admin bypass regardless of account type". The check is
performed **inside the gateway, server-side, immediately before the
reservation and therefore before the provider call** — never inherited
from an HTTP middleware that already ran, and never trusted from a job
payload.

**D. `AiBudgetPolicyResolver` gains a platform policy path.**
`resolveForPlatform(): AiBudgetPolicy` returns the existing
`AiBudgetPolicy` shape with a `policyKey` of `platform`, an explicit
`policyVersion`, the current `periodKey`, and a **finite**
`workspaceCapMicrousd` read from `config('ai.platform.monthly_cap_microusd')`.
No customer plan lookup occurs. There is **no unlimited, no default-infinite
and no missing-config-means-unlimited path**: an absent or non-positive
configured cap resolves to `0`, which the existing gateway already treats
as "refuse every call" — the same zero-cap semantics `AiBudgetPolicy`'s
own docblock already documents.

**E. `ai_usage_periods`.** The unique key `(scope_type, scope_id,
period_key)` is **already** scope-generic and needs no change. Platform
rows use `scope_type = 'platform'` and a single canonical
`scope_id` constant defined in `config/ai.php` (documented as an opaque
scope identifier, never a Workspace id and never an FK). The one required
DDL change: `workspace_id` becomes **nullable** — it carries no FK today,
precisely so it can survive independently, so this is a column-nullability
change with no referential consequence. Existing rows are untouched and
keep their exact current semantics.

**F. `ai_usage_ledger`.** Platform rows must not be encoded as
`workspace_id = 0` or any other magic id (R-19). The ledger gains explicit
scope attribution: `scope_type` (NOT NULL, default `'workspace'`) and
`scope_id` (NOT NULL). Existing rows backfill to
`scope_type = 'workspace'`, `scope_id = workspace_id`, which reproduces
today's semantics exactly. `workspace_id` becomes **nullable** (it has no
FK today) and `business_id` is already nullable. A new index
`(scope_type, scope_id, period_key)` is added; the three existing
`workspace_id` indexes are left in place and continue to serve every
existing query unchanged. `idempotency_key` stays globally UNIQUE across
all scopes.

**G. Reservation, idempotency and reporting.**
`AiUsageLedgerManager::reserve/settle/releaseAsFailed/expireStaleReservations`
take the scope from the request rather than from a Workspace, and the
period row they lock is selected by `(scope_type, scope_id, period_key)`
— which is what the table's unique key already is. `idempotencyFamily()`
incorporates the scope so a platform key can never collide with a
Workspace key. `AiUsageReadModel`/`AiUsagePresenter` learn to report
platform rows as a distinct scope; **no existing customer-budget query,
threshold, band or admin figure changes**.

**H. One gateway.** Platform AI still goes `AiGateway` → router →
reservation → provider → settle. No second gateway, no second ledger, no
second budget mechanism, no provider client outside
`app/Library/Ai/Providers/**` (the existing architecture test continues
to enforce that unchanged).

**I. Adversarial tests required (§13.7).** A non-admin cannot create a
platform request; a forged `actorUserId` is denied **before** any
reservation or provider call; the finite platform cap is enforced and a
zero/absent cap refuses; platform and Workspace period rows never collide;
no platform ledger row contains a fabricated Workspace or Business;
existing Core/Growth/Agency budget tests remain green and unmodified;
provider failure releases/settles identically at platform scope;
idempotency remains globally unique across scopes.

### 5.8 Cache identity — `authorization_scope_fingerprint`

`business_location_id = NULL` meaning "all Locations the actor may read"
is **insufficient and unsafe**. Two staff users can hold different
multi-Location subsets and different capability sets while both present a
null Location pin. A cached insight generated under one subset must never
become readable under another merely because the bucketed aggregates
happened to fingerprint the same way.

**Definition.** `authorization_scope_fingerprint` is a non-null
`char(64)` SHA-256 over a `CanonicalJson`-canonicalised document
containing exactly:

```
{
  "v": <fingerprint schema version>,
  "scope": "business" | "agency" | "platform",
  "workspace_id": <int|null>,
  "business_id": <int|null>,
  "location_ids": [<sorted ascending authorized Location ids>],
  "capability_keys": [<sorted capability keys consulted by fact composition>],
  "view_as_target_business_id": <int|null>,
  "pinned_location_id": <int|null>
}
```

**Rules:**

- **R-20.** It is computed from authorization inputs only — never from
  display text, never from mutable copy, never from anything a customer
  can type.
- **R-21.** It contains **no** session token, cookie, CSRF value, API key
  or other secret. Every input is an identifier or a permission key.
- **R-22.** It is **recomputed** from the live envelope on every read and
  compared; a stored value is never trusted as proof of authorization.
- **R-23.** `location_ids` is the actor's *authorized set*, not a
  display filter. An owner with all Locations and a staff member with a
  subset produce different fingerprints even when the visible numbers are
  identical.
- **R-24.** `capability_keys` lists only the capability keys that fact
  composition actually consults, sorted — so removing a capability
  immediately changes the fingerprint and instantly strands the richer
  cached row.

**Where it participates:**

- the `coo_insights` UNIQUE identity and every display selection (§8);
- the AI idempotency family, so two actors with different authorization
  scopes can never share a reservation or a cached provider result;
- `coo_drafts` scope identity (§5.5);
- the approval record's recorded envelope, re-derived and compared at
  execution (§6.5).

`business_location_id` survives as a useful *pin* for an explicitly
single-Location view, and is part of the fingerprint input — but it is
never, by itself, the authorization cache key.

### 5.9 Actor attribution vs. cache reuse — honest by construction

`coo_insights.actor_user_id` is **audit attribution**. It must never be
rewritten to make a cached row appear to belong to whoever read it next.

| Origin | `origin` column | `actor_user_id` | Reuse rule |
|---|---|---|---|
| Background/system generation (the existing passive "What we notice" path) | `system` | **NULL** | May be displayed to **any** actor whose live `authorization_scope_fingerprint` matches the row's, in the same scope. Nothing is re-attributed, because nothing was attributed. |
| Explicit human request — Ask, Explain, Draft | `on_demand` | **the real actor's id, NOT NULL** | Selectable **only** for that same `actor_user_id`, with a matching fingerprint. Never served to a different actor. |

- **R-25.** `origin` and `actor_user_id` are immutable after insert. No
  read path, presenter, job or repository may update either. Enforced by
  the repository's immutable-field allowlist and by a test (§13.5).
- **R-26.** The on-demand cache key therefore includes both `origin` and
  the actor: the UNIQUE identity and the display selection both carry
  them (§8). A second actor asking the same question under the same
  authorization scope produces a **second row**, attributed to them, at
  the ordinary bounded interactive-lane cost — not a silent
  re-attribution of the first.
- **R-27.** A `system` row is never promoted to `on_demand`, and an
  `on_demand` row is never demoted to `system`, to widen its audience.

#### 5.9a The bounded typed surface (Blueprint §34)

The smallest surface that satisfies §34 without inventing a chat system:

- **One turn.** A bounded typed question or instruction is submitted,
  answered once, and the exchange ends. The request is stateless: it
  carries the envelope and the question, nothing else.
- **Stored as** a `coo_insights` row, `kind = ask_response`,
  `origin = on_demand`, `subject_type`/`subject_id` = whatever the
  surface was showing, question digest folded into `signal_fingerprint`
  so an identical question under an identical scope and identical facts
  reuses the row for that same actor.
- **No** `coo_threads`, **no** `coo_thread_messages`, **no** conversation
  memory, **no** history replay, **no** prior-turn context. Those tables
  stay reserved for a future multi-turn slice (§15).
- **No** tool calling and **no** autonomous action: an answer may explain,
  may recommend the deterministic move, may produce a draft (§5.5) and
  may show an `ActionCostEstimate` (§5.3) — and every consequential
  outcome still goes through §5.4's approval lifecycle.
- **Input is untrusted data.** The typed question is bounded in length,
  never interpolated into a system instruction, never permitted to
  restate authorization, and never able to widen the envelope (R-2). The
  validator's grounding and causality rules apply to the answer exactly
  as they do to any other insight.
- **Rate-limited and attributed** by `19.G`'s durable per-actor limiter
  (§12.G).

## 6. Authority / security contract

### 6.1 The hard safety boundary, stated as enforceable rules

**S-1.** The AI may read authorized context, explain, recommend, draft
and estimate. The AI may not send, publish, alter a live automation,
charge or spend, modify payer or billing state, change permissions, or
take any other externally visible consequential action.

**S-2.** The model has no tool-calling surface and does not acquire one
in this slice (`AiCompletionRequest` stays text/JSON only). Model output
is data that a validator accepts or rejects — never a command.

**S-3.** Approval is server-side authority. A UI confirmation is
evidence that a human clicked; it is not authorization. Authorization is
the guard chain in §5.4(2), evaluated on the server, at approval **and**
again at execution.

**S-4.** No stale pre-approval decision grants future permission. All
four staleness dimensions (§5.7(6)) are re-checked at execution.

**S-5.** An action the approving human was not permitted to take
themselves at execution time cannot execute, regardless of what was
approved earlier.

**S-6.** The COO offers only actions in the source-controlled
`OpportunityActionRegistry`. Model output never names an action key, an
action parameter, a route, a recipient or an amount that is not already
resolvable from deterministic facts.

**S-7.** These rules bind the AI COO. Other AI features are governed by
their own Blueprint sections (§3.6 R-18); this contract neither exempts
them nor extends itself over them.

### 6.2 Permission and Location filtering

- Facts are composed only from sources the actor may read.
  `CooInsightFactsReader` takes the envelope and applies
  `LocationAccessGuard` (`:73`, `:152`); a fact source with no Location
  support is either provably Business-wide-and-safe or excluded — never
  included "because it is only an aggregate".
- The authorization scope is carried by
  `authorization_scope_fingerprint` (§5.8), which is part of the insight's
  UNIQUE identity, every display selection, and the AI idempotency
  family. Cache reuse across differing authorization scopes is
  structurally impossible, not merely discouraged.
- Every offered link or action passes `DashboardLinkGate`, which is
  already permission- and entitlement-aware
  (`BusinessHomePresenter.php:792`).
- **Rule R-7 (never narrate what the actor cannot see).** If a fact is
  excluded by permission or Location, the COO does not reference it,
  hint at it, or explain its absence.

### 6.3 Entitlement

`PlatformFeature::AiCooBasic` is the only AI COO entitlement, at Business
and Agency scope alike. No sub-slice adds a second. It is already
`Available`, so any route a sub-slice ships is customer-reachable
immediately and must carry its gate on day one (§11). At Agency scope the
decision is taken against the Agency Workspace's own sole canonical
Business (§5.6) — a real entitlement decision about a real Business, not
a synthesised one. At Platform scope **no customer entitlement decision is
taken at all**; authority is platform-admin identity (§6.7).

### 6.4 View As — attribution, not expansion

- Every COO record (`coo_insights`, `coo_drafts`, the approval and
  execution rows, the transitions) carries `actor_user_id` (the real
  human) and nullable `view_as_session_id`. Audit answers "who really
  did this" without inference.
- **Read, explain, draft and the typed one-turn ask are permitted under
  View As**, with attribution. This is precisely what §23's "through View
  As, one client at a time" asks for: contextual AI *assistance* about
  one client at a time, resolved to `CooScope::Business` for that client
  (R-17).
- **Approval and execution of a consequential action remain prohibited
  under View As**, inheriting the existing classification
  (`customer.opportunities.` is already denied at
  `ViewAsRouteClassification.php:122`). An Agency user who needs to take
  such an action exits View As and acts through an ordinary authorized
  path where one exists.
- **Stated plainly, so the product result is not misread:** permitting
  contextual assistance under View As satisfies §23's View-As clause. It
  is **not** a claim that View As confers financial or otherwise
  consequential authority. Contract 04's boundaries are read and
  unchanged by this slice; nothing here widens them.
- **Rule R-8.** Every new COO route is classified in
  `ViewAsRouteClassification`/`ViewAsProhibitedActions` in the same
  commit that creates it, or the existing security suite fails. No route
  reaches `main` unclassified.
- **Rule R-9.** Attribution is captured at the HTTP boundary into the
  envelope and carried into jobs. A queued job never re-derives actor
  identity from the tenant record, because `CustomerAccountAccessGate`
  is HTTP-only and does not re-apply inside a job.
- `view_as_target_business_id` is part of the authorization fingerprint
  (§5.8), so a View-As read can never reuse a non-View-As cache entry, and
  an Agency-scope row can never be served into a View-As Business-scope
  read.

### 6.5 The action execution boundary

There is exactly one: `ExecuteOpportunityAction` →
`OpportunityActionExecutor`, entered only through
`OpportunityManager::beginExecutionAttempt()`.

**Rule R-10 (architecture test, `19.F`).** No class under
`app/Library/Coo/**`, no COO controller and no COO job may reference a
message sender, a publish/automation-activation API, a wallet debit, a
billing/payer mutation, or a permission mutation. Mirrors the existing
`AiGatewayBypassArchitectureTest` and is enforced the same mechanical
way. Proposed name: `CooActionBoundaryArchitectureTest`. Its subject is
the COO's own code — it makes no claim about, and imposes no constraint
on, other AI features (§3.6).

**Rule R-11.** Model output never reaches an executor. A draft reaches a
send path only as human-edited content through that path's own
authorization (R-5).

### 6.6 Idempotency

- AI calls: `AiUsageLedgerManager::idempotencyFamily()` with a key
  **derived** from the envelope — scope, `authorization_scope_fingerprint`,
  `origin`, actor (for on-demand), subject, facts fingerprint, prompt and
  policy version. **Rule R-12: no COO code path may pass a random
  idempotency key.** (`19.G` additionally repairs the two existing random
  callers noted in §3.3(17), as a bounded, in-scope correction of the
  same rule.) The family is globally unique across Workspace and Platform
  scope (§5.7a G).
- Actions: the existing server-derived `idempotency_key` over its UNIQUE
  column (`OpportunityManager.php:1057-1060`). Never accepted from the
  browser, a header, or model output (RFC-002 §31).
- Drafts: a draft is not idempotent and does not need to be; a duplicate
  draft costs a bounded interactive-lane call and is discardable.

### 6.7 Platform authority

- Platform scope requires `users.is_admin` on a **freshly read** `User`
  row for `actor_user_id`, checked inside the gateway immediately before
  the reservation (§5.7a C).
- The permission-string path is explicitly **not** accepted as platform
  authority, for the reason `EnsureUserIsAdministrator`'s own docblock
  gives: it treats `users.id === 1` as an unconditional super-admin
  bypass regardless of account type.
- HTTP platform-COO routes additionally sit behind
  `EnsureUserIsAdministrator` — defense in depth, exactly as that
  middleware's docblock describes for admin Business routes.
- A platform request never fabricates a Workspace, a Business, a plan, or
  an entitlement decision (R-19).

## 7. Transaction / concurrency boundary

1. **No provider call inside a transaction.** Already the gateway's
   shape (reserve → call outside → settle); preserved everywhere,
   including at Platform scope.
2. **Reserve-before-call, settle-after.** Unchanged
   (`AiUsageLedgerManager::reserve/settle`), including the existing
   stale-reservation sweep, which learns platform rows in `19.H0`.
3. **The period row is the AI serialization point**, selected and locked
   by `(scope_type, scope_id, period_key)` — the table's existing unique
   key. Platform and Workspace periods are different rows by
   construction and cannot contend.
4. **Approval and execution take the row lock first.**
   `OpportunityRepository::findOwnedForUpdate()` before any guard that
   reads mutable state, so the guard chain cannot be raced.
5. **The idempotency claim is the action serialization point.** Two
   concurrent execution attempts: one claims the UNIQUE key, the other
   observes the claim and returns the first's outcome. Never two effects.
6. **Cost ceiling check happens inside the locked section**, against
   state read under the lock, immediately before the effect.
7. **Draft edits are last-write-wins on `edited_body` only**;
   `model_body` is immutable after insert, and a draft transitions to
   `consumed` exactly once, enforced by a conditional update on `status`.
8. **Insight writes stay `ShouldQueueAfterCommit`, `tries = 1`**
   (`GenerateCooInsight.php:33-64`). A failed AI generation is never
   retried at cost.

## 8. Migration / backfill

**`19.A` — `coo_insights` scope, attribution and cache identity.**

Columns added: `scope` (string 16, NOT NULL, default `'business'`);
`authorization_scope_fingerprint` (char 64, NOT NULL, backfilled per
below); `origin` (string 16, NOT NULL, default `'system'`);
`actor_user_id` (nullable, no FK — attribution survives user deletion,
mirroring `ai_usage_ledger.actor_user_id`); `view_as_session_id`
(nullable); `business_location_id` (nullable pin). `business_id` and
`workspace_id` become **nullable** to admit platform rows.

Scope invariants, writer-enforced and tested (§13.6):

| `scope` | `workspace_id` | `business_id` |
|---|---|---|
| `business` | NOT NULL | NOT NULL |
| `agency` | NOT NULL (Agency Workspace) | NOT NULL (its sole canonical Business) |
| `platform` | **NULL** | **NULL** |

**The UNIQUE key, and why it is mechanically valid under MySQL.** MySQL
treats NULLs as distinct in a UNIQUE index, so a key containing the now
nullable `business_id`, `subject_id` or `business_location_id` would
silently stop preventing duplicates for exactly the rows that need it
most. The key is therefore rebuilt over **NOT NULL columns only**:

```
UNIQUE (
  scope,
  authorization_scope_fingerprint,
  origin,
  actor_key,          -- STORED generated: COALESCE(actor_user_id, 0)
  kind,
  subject_type_key,   -- STORED generated: COALESCE(subject_type, '')
  subject_id_key,     -- STORED generated: COALESCE(subject_id, 0)
  signal_fingerprint,
  prompt_version,
  policy_version
)
```

`workspace_id`, `business_id`, `business_location_id` and
`view_as_target_business_id` are **inputs to**
`authorization_scope_fingerprint` (§5.8) and therefore do not need to
appear in the key: two rows with different tenancy or different
authorized Location sets cannot share a fingerprint. The three
`*_key` columns are **STORED generated columns used only as index
surrogates** — never read by application code, never used as foreign
keys, never presented, and never a fake tenant id (they stand in for
`NULL`-safe comparison, which is precisely what MySQL's UNIQUE semantics
lack). Their definition is stated in the migration with this rationale.
`policy_version` joins the key here, closing §3.3(16).

Display index: `(scope, authorization_scope_fingerprint, origin, kind,
period_key, generated_at)`.

Backfill: every existing row is `scope = 'business'`, `origin =
'system'`, `actor_user_id = NULL`, `business_location_id = NULL`, and its
`authorization_scope_fingerprint` is computed from its own
`workspace_id`/`business_id` with an empty `location_ids` and an empty
`capability_keys` — the honest representation of "generated by the
background path before authorization scope existed". Such a row is
therefore selectable only by a reader whose live fingerprint matches,
which for a pre-existing all-scope background insight is the ordinary
case; anything else regenerates at bounded cost on the next trigger.

**`19.D`** — `paid_effect` on the action registry (source-controlled, not
a column); `approval_expires_at` and the approval-side cost snapshot
columns on the approval/execution rows; `opportunity_transitions` gains
`actor_user_id`, `view_as_session_id`, `initiated_by_type` and a nullable
`coo_insight_id`/`coo_draft_id` provenance reference. No backfill of
historical transitions: absent attribution stays absent rather than being
fabricated.

**`19.E`** — cost snapshot columns are written only for `paid_effect`
actions; existing rows keep `NULL`, which the executor treats as "not a
paid-effect action" — never as "unlimited".

**`19.F`** — create `coo_drafts` (§5.5), with the same scope invariants
and the same NOT-NULL-only unique/index discipline.

**`19.H0`** — `ai_usage_periods.workspace_id` becomes nullable (no FK
today, so no referential consequence); its unique key is unchanged
because it is already `(scope_type, scope_id, period_key)`.
`ai_usage_ledger` gains `scope_type` (NOT NULL, default `'workspace'`)
and `scope_id` (NOT NULL), backfilled as `scope_id = workspace_id`;
`workspace_id` becomes nullable (no FK today); a
`(scope_type, scope_id, period_key)` index is added; the three existing
`workspace_id` indexes and the global `idempotency_key` UNIQUE are left
exactly as they are.

**Rule R-13.** No migration in this slice edits a previously merged
migration file. Every change is a new migration, per this repository's
existing convention.

## 9. Backwards compatibility

- Home's rendered output is unchanged until `19.C`, and after `19.C` the
  deterministic move still renders identically when no explanation
  exists. `CooInsightDisplayReader::forHome()` keeps its
  read-never-generate contract.
- Existing cached insights remain displayable across `19.A`'s UNIQUE-key
  change for readers whose live authorization scope matches the
  backfilled fingerprint; anything else regenerates on the next trigger
  at bounded cost. **This is a deliberate, safe-direction behavior
  change:** the failure mode of the new key is "regenerate", never
  "serve to the wrong scope".
- `19.D` changes no behavior for the single existing action key beyond
  adding gates that the one existing action already satisfies — with one
  deliberate exception: an approval older than the new expiry window can
  no longer execute. That is the intended behavior change, and it is the
  point of §5.4(3).
- `19.H0` is additive to the AI billing tables: every existing row keeps
  its exact semantics through the `scope_type = 'workspace'`,
  `scope_id = workspace_id` backfill; every existing index survives; no
  existing customer budget, cap, threshold, refusal or admin figure
  changes. `AiRequest`'s four existing call sites are not edited at all
  (§5.7a B).
- The Opportunity engine remains behind `config/opportunity.php`'s
  default-false kill switch; nothing in this slice flips it.
- Agency Outreach and every other non-COO AI feature are untouched (§3.6).

## 10. Events / audit

The audit obligation in §23 is "logged like any other actor's action
(§32)". Implementation: **extend the existing ledger, add no parallel
audit store.**

The chain, after `19.D`:

`ai_usage_ledger` entry (what the call cost, in which scope) → referenced
by `coo_insights.ai_usage_ledger_entry_id` /
`coo_drafts.ai_usage_ledger_entry_id` (what was said or drafted, by which
prompt/policy version, for which actor, under which View-As session, at
which authorization scope) → referenced by the approval record's
provenance column (what the human was shown) → `opportunity_transitions`
(who approved, when, under what attribution) →
`opportunity_action_executions` (what actually ran, idempotency key,
attempt number, `started_at`, outcome).

Every hop is a real column. "Who told the customer to do this, who
approved it, and what did it cost" is answerable with joins, not
forensics. Attribution is never rewritten on read (R-25).

No new domain events are introduced. Existing
`CooInsightInvalidator`-triggering events and the Opportunity transition
vocabulary are extended where needed.

## 11. Billing / provider safety

- **Platform provider spend** is governed entirely by
  `AiBudgetPolicyResolver` caps and the reserve/settle ledger — for
  Workspace scope by the existing plan-derived policy, and for Platform
  scope by `19.H0`'s finite, versioned, config-derived cap with
  zero-means-refuse semantics (§5.7a D). **No unlimited AI spend path
  exists in any scope after this slice.**
- Each new COO surface declares an `AiUsageCategory` case and a route in
  `config/ai.php` — never a model literal in domain code, never a call
  without a category (merged contract §10.2 defines a category-less call
  as a bug).
- **Lane assignment.** Human-initiated explain, typed ask and draft draw
  on `AiLane::Interactive` and inherit its existing hard-capped share.
  Nothing in this slice adds a background AI lane consumer.
- **Customer money** is never touched by AI code. `19.E` *reads* pricing
  and balance through RFC-005 seams to produce an estimate; only the
  executor, inside the locked section, reserves or commits anything
  through `UsageWalletManager`.
- **Provider robustness (`19.G`).** An explicit provider timeout is set
  on `OpenAiCompletionClient` (`:34` currently has none). Retry policy
  stays `tries = 1`: a failed AI call degrades, it does not re-bill.
- **Entitlement ordering.** `AiCooBasic` is already `Available`;
  therefore, unlike Contract 16, this slice cannot use "the feature is
  still `Planned`" as a safety net. Every sub-slice that ships a route
  ships its gate in the same commit.

## 12. Exact implementation allowlist — nine dependency-ordered sub-slices

Sub-slice identifiers are prefixed `19.` deliberately: `AI-1…AI-4`,
`C-1…C-4`, `H-1…H-6`, `A-1`, `A-2` and `T-1` are all already assigned in
the merged COO contract §17 and must not be reused.

### 19.A — Scope-capable context envelope + scope-safe insight schema (no new customer surface)

- **Files/domains**: new `CooContextEnvelope`, `CooScope` and the
  `authorization_scope_fingerprint` calculator in `app/Library/Coo/`; one
  migration per §8 against `coo_insights`; model casts/relations and the
  immutable-field allowlist for `origin`/`actor_user_id`; envelope
  assembly from `CustomerContext` at the one existing COO entry point;
  correction of `V1-AUTHORITY-TRACEABILITY-MATRIX.md` row 22 to reflect
  actual `main`.
- **Explicitly not in scope**: writing any `agency` or `platform` scope
  row; any new route; any behavior change on Home.
- **Tenancy/security**: envelope assembly only — R-2, R-9, R-20…R-24.
- **Concurrency**: none (no new write paths).
- **Tests**: the §13.4 fingerprint battery; the §13.5 attribution
  battery; the §13.6 scope-invariant battery; UNIQUE-key validity proven
  under nullable tenancy columns (two platform-shaped rows differing only
  in fingerprint do not collide, and two identical ones do); existing
  insights still display unchanged for a matching reader; the envelope
  carries the real actor under View As and never the viewed client.
- **Risk**: Medium-High (schema change to a shipped, cached table, plus
  the new cache-identity primitive).
- **Model**: Opus 5.

### 19.B — Permission- and Location-aware fact composition

- **Files/domains**: `CooInsightFactsReader` takes the envelope and
  applies `LocationAccessGuard`; `CooInsightDisplayReader` selects by
  `authorization_scope_fingerprint` + `origin` (+ actor for on-demand);
  fact sources without Location support are explicitly classified as
  safe-Business-wide or excluded, in code, with a comment naming which.
- **Prerequisites**: `19.A` merged.
- **Tenancy/security**: R-7 is the acceptance bar — an insight generated
  under one authorization scope must be unreadable under another.
- **Tests**: §13.3's cross-scope battery; the differing-Location-subset
  and removed-capability cases from §13.4; query-count budget unchanged
  (no N+1 introduced into Home's bounded read).
- **Risk**: **High** — this is the data-leak surface of the whole slice.
- **Model**: Opus 5.

### 19.C — `move_explanation`: AI explains the deterministic recommendation

- **Files/domains**: activate `CooInsightKind::move_explanation`; a
  subject identity for the selected `NextBestMove` (§5.1); prompt builder
  extension; validator reuse unchanged; trigger + `AiUsageCategory` case
  + `config/ai.php` route; Home renders the explanation **attached to**
  the existing deterministic band, never as a second recommendation;
  narrow the §3.3(14) suppression so an explanation may accompany a
  raised Attention item while the deterministic text stays primary.
- **Prerequisites**: `19.A`, `19.B` merged.
- **Tests**: the move renders identically when the explanation is
  absent, refused, expired or invalidated; the AI never changes which
  move is selected (mutation test: a model returning a different action
  changes nothing on screen); grounding — every explanation statement
  carries a `fact_ref` resolvable to a fact in the snapshot; no provider
  call on a Home render.
- **Risk**: Medium.
- **Model**: Sonnet 5.

### 19.D — Approval-lifecycle hardening (no COO dependency, no new action key)

- **Files/domains**: `OpportunityManager` (guard chain, expiry, retry
  rule, `initiated_by_type`, kill-switch symmetry, `started_at`);
  `OpportunityActionRegistry` (`paid_effect` flag);
  `OpportunityActionExecutor` (registry-driven handler map replacing
  `SUPPORTED_ACTION_KEY`, both hash guards preserved);
  `OpportunityController` (route through `CustomerContext`); route-level
  capability middleware; migration per §8.
- **Prerequisites**: none on the COO side. **Hard prerequisite for
  `19.E` and `19.F`.**
- **Tenancy/security**: the §5.4(2) chain, re-evaluated at execution;
  `LocationAccessGuard` for Location-bound actions; `EntitlementManager`
  at both stages.
- **Concurrency**: §7.4–§7.6.
- **Tests**: adversarial approval matrix — capability revoked between
  approval and execution; entitlement lost; Location access revoked;
  parameters mutated (hash mismatch); approval expired; retry of a
  mutating action without re-approval refused; two concurrent executions
  produce one effect; kill switch honoured by every lifecycle method; the
  single existing `add_phone` action still executes end-to-end unchanged.
- **Risk**: **Critical** — touches the only live approve-then-execute
  path in the product.
- **Model**: Opus 5.

### 19.E — Customer-facing action cost estimate

- **Files/domains**: `ActionCostEstimate` value object and a
  deterministic estimator reading RFC-005 pricing seams and
  `EffectivePayerResolver`; approval-record snapshot columns; executor
  ceiling re-check (R-3); the pre-approval UI affordance showing payer,
  amount/units, basis and wallet sufficiency.
- **Prerequisites**: `19.D` merged.
- **Tenancy/security**: the estimate names the **effective payer**, which
  under agency rebilling is not necessarily the Business's own
  Workspace; computed server-side, never accepted from the client.
- **Concurrency**: §7.6.
- **Tests**: estimate matches a deterministic fixture exactly; a price
  change between approval and execution refuses and returns to
  `awaiting_approval`; a payer change refuses; a `paid_effect` action
  with no estimate refuses; an insufficient wallet is surfaced before
  approval, not discovered at execution; no estimate value originates
  from model output (R-4).
- **Risk**: **High** (customer money).
- **Model**: Opus 5.

### 19.F — COO drafting

- **Files/domains**: `coo_drafts` migration + model; `CooDraftGenerator`
  through the gateway with a new `AiUsageCategory` case on
  `AiLane::Interactive`; a draft-specific output validator (length and
  safety bounds appropriate to content, reusing the existing
  all-or-nothing pattern, **not** the 3×280 insight budget); the
  placeholder-only prompt rule (R-6); human edit/discard surface; the
  consumption handshake into each consuming feature's existing
  human-initiated path (R-5); `CooActionBoundaryArchitectureTest` (R-10).
- **Prerequisites**: `19.A`, `19.B`, `19.D`, `19.E` merged.
- **Tenancy/security**: a draft is inert (R-5); drafting is permitted
  under View As with attribution; consuming is subject to the consuming
  path's own authorization and, when paid, to `19.E`'s estimate; scope
  invariants per §5.5 with no fake tenant (R-19).
- **Tests**: a draft is never auto-consumed by any code path (architecture
  test plus a source-boundary test); no PII reaches a drafting prompt;
  `model_body` immutable after insert; a stale draft is flagged and never
  silently regenerated; consumption happens exactly once under
  concurrency; a draft outside the reader's authorization scope is
  unreadable.
- **Risk**: **High**.
- **Model**: Opus 5.

### 19.G — Bounded typed "Ask COO", contextual reach, durable limiting, provider robustness

- **Files/domains**: the §8 "Ask COO" affordance on Home bound to exactly
  what Home is showing; the bounded one-turn typed surface of §5.9a
  (`kind = ask_response`, `origin = on_demand`) satisfying Blueprint §34;
  contextual explain/draft on a small, enumerated set of surfaces; all
  new routes classified per R-8; replace `CooInsightExplainLimiter`'s
  cache-only claim with a durable, per-actor, per-subject, auditable
  limit enforced in the generator as well as the controller (§3.3(15));
  provider timeout (§11); repair the two random idempotency keys
  (R-12, §3.3(17)).
- **Prerequisites**: `19.C`, `19.F` merged.
- **Tenancy/security**: R-8; entitlement + capability gate on every new
  route on day one (§11); typed input is untrusted data and cannot widen
  the envelope (§5.9a, R-2).
- **Explicitly not in scope**: `coo_threads`, `coo_thread_messages`,
  multi-turn history, conversation memory, tool calling.
- **Tests**: every new route is classified; the limiter survives a cache
  flush; an unentitled or uncapable actor sees no affordance and gets a
  typed refusal if they post directly; two actors asking the same
  question under different authorization scopes never share a row; a
  question cannot restate or widen authorization; Home still renders with
  the AI provider hard-down.
- **Risk**: Medium-High (first user-supplied text into a prompt).
- **Model**: Opus 5.

### 19.H0 — Platform AI scope foundation (gateway / budget / ledger)

- **Files/domains**: `AiScope` enum; `AiRequest` nullable Workspace +
  appended `scope` + invariant + named constructors; `PlatformAiAuthority`;
  `AiBudgetPolicyResolver::resolveForPlatform`; `AiGateway`'s platform
  branch (authority recheck before reserve); `AiUsageLedgerManager` scope
  awareness incl. `idempotencyFamily`; `AiUsageReadModel`/`AiUsagePresenter`
  platform reporting; `ExpireStaleAiReservations` platform awareness;
  `config/ai.php` platform cap + canonical platform scope id; the two
  migrations in §8.
- **Prerequisites**: none. Independent of every COO sub-slice; may run in
  its own lane.
- **Tenancy/security**: §6.7 — `users.is_admin` on a freshly read row,
  checked inside the gateway before reservation; no fabricated
  Workspace/Business/plan/entitlement (R-19).
- **Concurrency**: §7.1–§7.3.
- **Tests**: the full §13.7 battery, plus proof that the four existing
  `AiRequest` call sites are unedited and that existing Core/Growth/Agency
  budget tests are unmodified and green.
- **Risk**: **Critical** (shared AI billing tables and the one gateway).
- **Model**: Opus 5.

### 19.H — Agency and Platform COO contexts

- **Files/domains**: activate `CooScope::Agency` (facts reader over
  Agency operational facts; entitlement and budget against the Agency
  Workspace's own sole Business) and `CooScope::Platform` (facts reader
  over platform-operational aggregates; `AiScope::Platform` authority and
  budget); Agency Home and Platform Home surfaces; acceptance-matrix rows
  for the Agency Owner and Platform Owner actors, which do not exist
  today (§3.3(9)).
- **Prerequisites**: `19.A`–`19.G` merged for both halves; **`19.H0`
  merged for the Platform half only.** The Agency half depends on no new
  AI infrastructure (§5.6) and may ship first as `19.H` (Agency) with the
  Platform half following once `19.H0` lands.
- **Tenancy/security**: R-14…R-17 — no cross-client prompt ever; one
  client at a time only through View As resolving to Business scope; no
  fake tenant at Platform scope (R-19).
- **Tests**: §13.3's cross-scope battery at full strength; an Agency-scope
  prompt provably contains zero client Business facts; a View-As Business
  read never reuses an Agency-scope row; platform surfaces refuse a
  non-admin before any reservation.
- **Risk**: **Critical**.
- **Model**: Opus 5.

## 13. Required tests

Beyond each sub-slice's own list (§12), the slice as a whole requires:

1. **The §23 MUST-NOT proof.** An end-to-end test showing that a COO
   recommendation with a `paid_effect` action produces **no** external
   effect until a human confirms, and that the confirmation is validated
   server-side with the full guard chain — not merely that a UI dialog
   appeared.
2. **The stale-approval proof.** Approve, then change authority /
   parameters / price / time, then attempt execution: four separate
   refusals, four typed reasons.
3. **The cross-scope battery.** An Agency-scope prompt contains zero
   client Business facts (R-15); no prompt in any scope contains two
   Businesses' facts (R-14); no cross-client portfolio prompt can be
   constructed, and the attempt fails loudly rather than silently
   truncating (R-16); an Agency actor's route to one client is View As
   resolving to Business scope, attributed (R-17); a View-As Business
   read never reuses an Agency-scope insight.
4. **The authorization-fingerprint battery** (§5.8):
   - user A authorized for Locations [1, 2] and user B for [2, 3]:
     neither can read the other's insight, in either direction;
   - a Location grant added or removed makes the prior insight
     immediately unselectable for that actor, with no sweep;
   - a capability removed makes the prior, richer insight immediately
     unselectable;
   - two scopes producing **numerically identical** bucketed aggregates
     still do not collide;
   - an all-Locations owner's insight is never served to a subset staff
     member;
   - the fingerprint contains no secret or session value (asserted over
     its canonical input document).
5. **The attribution battery** (§5.9): a `system` row has NULL
   `actor_user_id` and is reusable across matching-scope actors; an
   `on_demand` row is served only to its own actor; `origin` and
   `actor_user_id` are never updated by any read path (repository-level
   immutability test); a second actor's identical question creates a
   second attributed row rather than re-attributing the first.
6. **The scope-invariant battery** (§8): each of the three scope shapes
   inserts successfully; every mismatched shape (e.g. `platform` with a
   `workspace_id`, `agency` with a NULL `business_id`) is rejected by the
   writer; no row anywhere contains a sentinel/fake tenant id (R-19);
   the generated index-surrogate columns are never read by application
   code.
7. **The platform-foundation battery** (§5.7a I): non-admin refused;
   forged `actorUserId` denied before reservation and before the provider
   call; finite cap enforced; zero/absent configured cap refuses every
   call; platform and Workspace period rows never collide; no platform
   ledger row carries a fabricated Workspace or Business; existing
   customer budget tests unmodified and green; provider failure
   releases/settles identically; idempotency globally unique across
   scopes.
8. **The boundary architecture test** (R-10), mechanical, in the style of
   `AiGatewayBypassArchitectureTest`, scoped to the COO's own code.
9. **The audit-chain test**: from a single approved action, join backwards
   to the approving human, the View-As session if any, the insight or
   draft shown, the prompt/policy version and the ledger entry — in one
   query path, with no nulls on the load-bearing hops.
10. **Graceful degradation**: provider down, budget exhausted, entitlement
    absent, dormant Business, platform cap exhausted — Home renders, the
    deterministic move still appears, and each case produces a distinct
    `AiRefusalReason` rather than a generic failure.
11. **Query-budget flatness** on Home after `19.B`/`19.C`, re-pinned
    explicitly if the observed count changes, in the existing dashboard
    query-budget style.

No full-repository suite run is required by this contract; each
sub-slice names its own focused set, plus the existing COO, Opportunity,
AI-gateway and View-As security suites its files touch.

## 14. Acceptance criteria

1. The COO explains, recommends, drafts, estimates cost and accepts a
   bounded typed question — every §23 verb plus §34's typed interaction
   reachable from Home for an entitled, permitted actor.
2. No AI COO code path produces an externally visible effect. The only
   executor is `OpportunityActionExecutor` behind
   `beginExecutionAttempt()` (R-10, proved mechanically).
3. Approval is server-side authority: a forged or replayed confirmation
   without the guard chain cannot execute (S-3).
4. Every execution re-checks authority, parameters, price and freshness;
   no approval grants a future permission (S-4, §13.2).
5. Every paid-effect action shows the approving human the payer, the
   amount or units, and the basis, before approval — and refuses if the
   live cost exceeds the approved ceiling (R-3).
6. The COO never references a fact the actor may not read, in any scope
   or Location, and no cached output is ever reused across differing
   authorization scopes (R-7, R-20…R-24, §13.4).
7. Audit attribution is honest: a `system` row is never attributed to a
   reader, an `on_demand` row is never served to another actor, and
   neither field is ever rewritten on read (R-25…R-27, §13.5).
8. Every COO record names the real human actor and, under View As, the
   session — and approval/execution of consequential actions remains
   prohibited under View As, with Contract 04's boundaries unchanged
   (§6.4).
9. No prompt in any scope contains more than one Client Business's facts,
   and no cross-client portfolio prompt can be constructed (R-14…R-17,
   §13.3).
10. Platform AI runs through the same gateway, router, reservation,
    provider and settle path, under a finite configured cap, with
    platform-admin authority rechecked server-side before reservation,
    and with no fabricated Workspace, Business, plan or entitlement
    anywhere (§5.7a, R-19, §13.7).
11. The audit chain from spend → content → approval → execution is
    complete and joinable (§10, §13.9).
12. No second gateway, insight table, approval lifecycle, audit ledger,
    entitlement identity, AI budget mechanism or Location ACL exists
    anywhere in the slice (R-0, §17).
13. Blueprint §23's "one component, three authorization contexts" is
    structurally true: `CooScope` resolves all four questions in §5.6 and
    all three scopes are live.
14. AI COO compliance is asserted from AI COO behavior alone and is never
    conditioned on, nor extended over, another AI feature (R-18, §3.6).
15. `git diff --check` clean and a clean working tree at the end of each
    sub-slice's own commit.

## 15. Non-goals

- **Agency Outreach, prospecting, and every other non-COO AI feature.**
  Governed by their own Blueprint sections (§29 and §19 messaging safety
  for Outreach). This contract touches none of their files and proposes
  no exception, carve-out or remediation for them (§3.6 R-18).
- **Multi-turn conversation, conversation memory, or the reserved
  `coo_threads`/`coo_thread_messages` tables.** §34 is satisfied by the
  bounded one-turn surface in §5.9a. A future multi-turn slice may claim
  those tables; this contract does not.
- **Tool/function calling at the provider layer.** Deliberately not
  added; its absence is a safety property of V1 (S-2).
- **Any AI role in *selecting* the next best move.** The selector stays
  deterministic and source-controlled (R-1); changing its fixed order
  still requires an amendment to the merged contract, not configuration.
- **AI in the automations/workflow builder.** No authority requires it
  in V1.
- **Enabling the Opportunity engine.** `config/opportunity.php`'s
  default-false kill switch is not flipped by this slice.
- **Adding new Opportunity action keys.** `19.D` makes the executor
  registry-driven with exactly the one existing handler. Every new action
  key is its own separately authorized change with its own guard, cost
  and test obligations.
- **Widening View-As authorization.** Contract 04's financial and
  security boundaries are read, never modified (§6.4).
- **A per-actor, per-Location or per-feature customer AI budget UI.**
  Workspace caps stay plan-derived and env-configured; the platform cap
  stays config-derived. No administration surface is required in V1.
- **Rewriting the admin AI usage surfaces** beyond teaching them to
  report platform-scope rows (§5.7a G).
- **A "Platform Workspace" or any other fabricated tenant.** Explicitly
  forbidden (R-19).
- **Amending `V1-IMPLEMENTATION-CONTRACT-INDEX.md`.** It indexes
  Contracts 01–14 by design; adding rows for 16 and 19 is a separate
  documentation change.
- **Reopening the Workspace/Agency tenancy migration (Contracts 1–14)**
  in any way.

## 16. Merge prerequisites

No product decision blocks any sub-slice. Dependencies are purely
technical:

```
19.A ──► 19.B ──► 19.C ──┐
                          ├──► 19.G ──┐
19.D ──► 19.E ──► 19.F ──┘            ├──► 19.H (Agency half)
        (19.F also needs A, B)         │
                                       │
19.H0 ─────────────────────────────────┴──► 19.H (Platform half)
```

- `19.A` → `19.B` → `19.C`: strict serial (schema → readers → surface).
- `19.D` → `19.E`: strict serial. `19.D` has no COO prerequisite and may
  run in a parallel lane from day one.
- `19.F` requires `19.A`, `19.B`, `19.D` and `19.E` — it is the join
  point of the two lanes.
- `19.G` requires `19.C` and `19.F`.
- `19.H0` requires nothing and may run in a third lane at any time.
- `19.H` Agency half requires `19.A`–`19.G`. **It does not require
  `19.H0`** — Agency scope reuses the Agency Workspace's ordinary AI
  authority and budget (§5.6).
- `19.H` Platform half requires `19.A`–`19.G` **and** `19.H0`.
- Lane safety: the COO lane (`19.A`/`19.B`/`19.C`), the Opportunity lane
  (`19.D`/`19.E`) and the AI-infrastructure lane (`19.H0`) touch disjoint
  files and are safe to run concurrently. Do not run two lanes *inside*
  the Opportunity subsystem or *inside* `app/Library/Ai/**` (§17).

## 17. Conflict map

| Other work | Shared file/table | Posture |
|---|---|---|
| Merged COO contract's unscheduled AI-4 (interactive/multi-turn COO) | `coo_insights`, `config/coo.php`, `AiUsageCategory`, the reserved `coo_threads`/`coo_thread_messages` names | `19.G` builds the bounded one-turn surface §34 requires and does **not** claim the reserved thread tables. A future AI-4 extends `19.G`'s surface with history rather than replacing it. |
| Merged COO contract §18's serial chain on `BusinessHomePresenter.php` / `business-home.blade.php` (H-1→…→C-2→AI-3) | both files | `19.C` and `19.G` extend the end of that chain; treat as a coordination point, never a concurrent edit. |
| `AttentionType` | C-2 is the declared sole writer | `19.C` reads only. Adding a case is a coordination point, not a free edit. |
| RFC-002 Opportunity engine work of any kind | `OpportunityManager`, `OpportunityActionRegistry`, `OpportunityActionExecutor`, `OpportunityController`, the four Opportunity tables | `19.D`/`19.E` are invasive here. Serialize against any other Opportunity work; never two lanes inside this subsystem. |
| Any other work inside `app/Library/Ai/**` or on `ai_usage_periods`/`ai_usage_ledger` | `AiRequest`, `AiGateway`, `AiUsageLedgerManager`, `AiBudgetPolicyResolver`, both AI billing tables | `19.H0` is invasive here. Serialize; never two lanes inside the AI subsystem. |
| RFC-005 usage billing / wallet work | `UsageWalletManager`, `EffectivePayerResolver`, pricing seams | `19.E` **reads** only; it introduces no wallet write. Parallel-safe as long as that stays true. |
| Agency Outreach / prospecting work | none | **Fully disjoint.** This contract touches no Outreach file (§3.6). |
| Contract 16 (Packages & Products) and Slice 17 | none | Parallel-safe. |
| `CustomerMenuBuilder` / `ENTITLEMENT_GATED_FEATURES` | additive line if `19.G` adds nav | Low risk, same shape as every prior slice's nav addition. |
| `ViewAsRouteClassification` / `ViewAsProhibitedActions` | every new route in `19.G`/`19.H` | Additive, but mandatory in the same commit (R-8); the existing security suite is the enforcement. |
| `config/ai.php` (`category_routes`, platform cap), `AiUsageCategory` | one case + one route per new surface (`19.C`, `19.F`, `19.G`); platform keys (`19.H0`) | Additive; never a sibling enum (merged contract §10.2). |
| Admin AI usage surfaces | `AiUsageReadModel`, `AiUsagePresenter` | `19.H0` adds platform-scope reporting without changing any existing customer figure; coordinate with any concurrent admin-reporting work. |

## 18. Implementation prompts

Each sub-slice is handed to a fresh session independently, once
explicitly authorized. Every prompt assumes Contracts 01–14, the merged
COO contract's shipped slices, and every prerequisite sub-slice already
merged to `main`. Every prompt inherits this repository's standing rules:
branch-only work, a disposable test database, no PR and no merge, and no
unverified claims.

### 18.A — Scope-capable envelope + scope-safe insight schema

```
You are implementing Sub-slice 19.A per docs/product/implementation-
contracts/19-AI-COO-V1-COMPLETION.md SS5.2, SS5.6, SS5.8, SS5.9, SS8 and
SS12.19.A. This is plumbing only: no new route, no new customer-visible
behavior, no 'agency' or 'platform' scope row written anywhere.

Before writing code, read the contract's SS3, SS5.8 and SS8, then
app/Library/Coo/Insight/CooInsightFacts.php, CooInsightDisplayReader.php,
the coo_insights migration, and app/Library/Navigation/CustomerContext.php.

Build CooContextEnvelope, CooScope and the authorization_scope_fingerprint
calculator in app/Library/Coo/, plus one new migration per SS8 (never edit
the merged coo_insights migration).

Two things are load-bearing and must not be simplified:
1. The UNIQUE key must be built over NOT NULL columns only, using the
   STORED generated surrogate columns SS8 specifies, because MySQL treats
   NULLs as distinct in a UNIQUE index. Those surrogates are index-only:
   never read them from application code, never use them as foreign keys,
   never present them.
2. authorization_scope_fingerprint must be RECOMPUTED from the live
   envelope on every read and compared -- a stored value is never trusted
   as proof of authorization (R-22).

Also mark origin and actor_user_id immutable after insert, and correct
V1-AUTHORITY-TRACEABILITY-MATRIX.md row 22, which is stale.

Tests: SS13.4, SS13.5 and SS13.6 in full. Home's rendered output must be
byte-identical before and after for a reader whose scope matches.
```

### 18.B — Permission- and Location-aware fact composition

```
You are implementing Sub-slice 19.B per SS6.2 and SS12.19.B. 19.A is
merged.

This sub-slice is the data-leak surface of the whole slice. Its
acceptance bar is rule R-7: the COO never references a fact the actor may
not read.

Make CooInsightFactsReader and CooInsightDisplayReader envelope-driven.
Selection is by authorization_scope_fingerprint plus origin (plus actor
for on_demand rows) -- NOT by business_id/location_id alone. Apply
LocationAccessGuard for the authorized Location set.

For every fact source, classify it IN CODE as provably Business-wide-safe
or excluded, with a comment naming why. Do not include a source "because
it is only an aggregate".

Write the SS13.3 and SS13.4 batteries before the implementation. Keep
Home's bounded query budget flat; if the observed count changes, re-pin it
explicitly with a docblock naming each added read.
```

### 18.C — move_explanation

```
You are implementing Sub-slice 19.C per SS5.1 and SS12.19.C. 19.A and
19.B are merged.

Activate CooInsightKind::move_explanation. The AI explains the move the
deterministic selector already chose; it never chooses, reorders,
suppresses or invents one (rule R-1). Add the AiUsageCategory case and its
config/ai.php route; never name a model in domain code.

Render the explanation attached to the existing deterministic band. If the
explanation is absent, refused, expired or invalidated, the band must
render exactly as it does today.

Include the mutation test: a model returning a different action must
change nothing on screen.
```

### 18.D — Approval-lifecycle hardening

```
You are implementing Sub-slice 19.D per SS5.4, SS6.5, SS7 and SS12.19.D.
This is one of the highest-risk sub-slices in the contract: it edits the
only live approve-then-execute path in the product.

Read RFC-002 SS13.1, SS28, SS30, SS31 and all of app/Library/Opportunity/
OpportunityManager.php before changing anything.

Add the SS5.4(2) guard chain at approval AND at every execution attempt,
approval expiry, the retry re-approval rule, a real initiated_by_type, the
paid_effect registry flag, kill-switch symmetry, started_at, and
CustomerContext-based resolution in the controller. Replace
SUPPORTED_ACTION_KEY with a registry-driven handler map containing EXACTLY
the one existing handler -- adding an action key is explicitly out of
scope.

Preserve both hash-mismatch guards unchanged. The existing add_phone
action must still execute end-to-end identically. Write the adversarial
approval matrix first.
```

### 18.E — Customer-facing action cost estimate

```
You are implementing Sub-slice 19.E per SS5.3 and SS12.19.E. 19.D is
merged.

Build ActionCostEstimate and a DETERMINISTIC estimator over RFC-005's
existing pricing seams and EffectivePayerResolver. No cost value may
originate from model output (rule R-4). No wallet write belongs in this
sub-slice -- read only.

Snapshot the estimate on the approval record as an approved ceiling. At
execution, inside the locked section, recompute and refuse if the cost
exceeds the ceiling, the price_version changed, or the payer changed
(rule R-3).

Show payer, amount/units, basis and wallet sufficiency BEFORE approval.
Tests per SS12.19.E, including all four refusal paths.
```

### 18.F — COO drafting

```
You are implementing Sub-slice 19.F per SS5.5, SS6.5 and SS12.19.F.
19.A, 19.B, 19.D and 19.E are merged.

Create coo_drafts per SS5.5 -- including its scope invariants: a platform
draft stores NULL workspace_id and NULL business_id and NEVER a sentinel
or fabricated tenant id (rule R-19). Apply the same NOT-NULL-only unique
and index discipline SS8 specifies for coo_insights.

Build CooDraftGenerator through AiGateway on AiLane::Interactive with its
own AiUsageCategory case. A drafting prompt receives role placeholders
only -- never a contact name, phone number or email address (rule R-6);
the merge happens deterministically in the consuming feature at
consumption time.

A draft is inert (rule R-5): nothing consumes it automatically, and it
reaches a send path only as human-edited content through that path's own
authorization and, when paid, 19.E's estimate.

Write CooActionBoundaryArchitectureTest (rule R-10) FIRST, in the style of
tests/Feature/Security/AiGatewayBypassArchitectureTest.php: no class under
app/Library/Coo/**, no COO controller and no COO job may reference a
message sender, a publish/automation-activation API, a wallet debit, a
billing/payer mutation or a permission mutation. That test constrains the
COO's own code only -- it makes no claim about other AI features.
```

### 18.G — Bounded typed Ask COO, contextual reach, durable limiting

```
You are implementing Sub-slice 19.G per SS5.9a, SS6.4, SS11 and
SS12.19.G. 19.C and 19.F are merged.

Blueprint SS34 places typed interaction with the AI COO in V1, so this
sub-slice BUILDS it -- but only the smallest compliant surface: ONE
bounded typed turn, stateless, stored as a coo_insights row with
kind = ask_response and origin = on_demand, contextual to the authorized
envelope. No coo_threads, no coo_thread_messages, no conversation memory,
no history replay, no tool calling, no autonomous action. Those tables
stay reserved.

Treat the typed question as untrusted data: bound its length, never
interpolate it into a system instruction, never let it restate or widen
authorization (rule R-2). The existing grounding and causality rules apply
to the answer unchanged.

Also add the Home "Ask COO" affordance bound to exactly what Home is
showing, plus contextual explain/draft on a small ENUMERATED set of
surfaces -- list them in the PR description. Classify every new route in
ViewAsRouteClassification and ViewAsProhibitedActions in the same commit
(rule R-8): read/explain/draft/ask permitted under View As with
attribution; approval and execution prohibited.

Replace CooInsightExplainLimiter's cache-only claim with a durable,
per-actor, per-subject, auditable limit enforced in the generator as well
as the controller. Set an explicit provider timeout on
OpenAiCompletionClient. Repair the two random idempotency keys named in
SS3.3(17). Keep tries=1.
```

### 18.H0 — Platform AI scope foundation

```
You are implementing Sub-slice 19.H0 per SS5.7a, SS6.7, SS8 and
SS12.19.H0. This sub-slice edits the single AI gateway and both shared AI
billing tables: treat it as Critical and serialize against all other work
inside app/Library/Ai/**.

Read SS5.7a in full, then app/Library/Ai/AiGateway.php, AiRequest.php,
AiBudgetPolicy.php, AiBudgetPolicyResolver.php, AiUsageLedgerManager.php,
both ai_usage_* migrations, and app/Http/Middleware/
EnsureUserIsAdministrator.php before changing anything.

Non-negotiables:
- Append the new AiScope parameter AFTER the existing jsonMode parameter
  so all four existing AiRequest call sites compile and behave identically
  with NO edit. Prove this in the tests.
- Platform authority is users.is_admin on a FRESHLY READ User row, checked
  INSIDE the gateway immediately before the reservation -- never inherited
  from HTTP middleware, never trusted from a job payload. Do NOT use the
  permission-string path: EnsureUserIsAdministrator's own docblock
  explains that it treats users.id === 1 as an unconditional bypass.
- The platform cap is finite and config-derived. Absent or non-positive
  config resolves to 0, which the gateway already treats as refuse-all.
  There is no unlimited path.
- NEVER encode platform as workspace_id = 0 or any other magic id. Add
  scope_type/scope_id to ai_usage_ledger, backfill existing rows to
  scope_type='workspace' and scope_id=workspace_id, and make workspace_id
  nullable (it has no FK today). ai_usage_periods' unique key is already
  (scope_type, scope_id, period_key) and must NOT change; only its
  workspace_id becomes nullable.
- One gateway, one router, one reservation protocol, one ledger, one
  settle path. No second anything.

Tests: the full SS13.7 battery, plus proof that existing Core/Growth/
Agency budget tests are unmodified and green.
```

### 18.H — Agency and Platform COO contexts

```
You are implementing Sub-slice 19.H per SS5.6 and SS12.19.H. 19.A-19.G
are merged. If you are implementing the Platform half, 19.H0 is also
merged; the Agency half does NOT depend on 19.H0.

Agency scope uses the Agency Workspace's OWN sole canonical Business for
AI authority: AiCooBasic is decided against that Business and the budget
is that Workspace's ordinary AI policy. Do not invent a second Agency AI
budget and do not make Business nullable for this case -- Workspace :
Business is 1:1 under the frozen topology.

The four scope-content rules are absolute and tested (SS13.3):
- R-14: a prompt with Client Business facts contains exactly ONE
  Business's facts, always, in every scope.
- R-15: an Agency-scope prompt contains the Agency's OWN operational facts
  only -- never any client's Business facts, not one client's and not a
  blend.
- R-16: there is NO cross-client portfolio prompt, for the Agency Account
  Home or anywhere else. An attempt to build one must fail loudly.
- R-17: an Agency actor reaches one managed Client's Business COO ONLY
  through View As, which resolves the envelope to Business scope with the
  real Agency actor attributed.

Platform scope uses AiScope::Platform from 19.H0. No fabricated
Workspace, Business, plan or entitlement anywhere (rule R-19).

Add the missing Agency Owner and Platform Owner acceptance-matrix rows.
```
