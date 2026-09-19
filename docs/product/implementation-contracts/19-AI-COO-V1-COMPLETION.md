# Implementation Contract 19 — AI COO V1 Completion

**Status:** Planning contract only. Does not authorize implementation. No
production code accompanies this document. Written against `main` @
`30ad21c7b33034618f3ccba0d9098035133983ff`. Nine independently
authorizable sub-slices (§12/§18, `19.P0` and `19.A`–`19.H`) complete
Blueprint §23 in dependency order; **no sub-slice below may start
without its own separate, explicit human authorization**, matching this
repository's route-3 governance (`CLAUDE.md`). Unlike Contract 16, this
slice is **not** net-new: a mature AI framework, a cached AI insight
pipeline, a deterministic recommendation engine and a server-side
approve-then-execute lifecycle all already exist on `main`. This
contract's central rule is therefore **extend the existing seams, invent
no second AI framework** (§3.4, §17). Two items below are not
engineering decisions and are escalated rather than resolved: the live
§23 violation in `19.P0` and the product-locked amendment blocking
`19.H` (§3.6, §3.7).

## 1. Objective

Close the distance between Blueprint §23's AI COO and what `main`
actually does. Today the COO **explains** (cached AI insight on the
Business Home performance band, plus one customer-triggered "Explain
this change") and **recommends deterministically** (`NextBestMoveSelector`,
no AI in the loop). It cannot draft, cannot estimate what a paid action
will cost the customer, cannot offer an approvable action, has no
Location scope, records no View-As actor attribution, has no end-to-end
"AI said → human approved → system executed" audit chain, and serves
exactly one of §23's three authorization contexts. This contract
specifies the completion of the remaining four verbs — **draft**,
**estimate cost before any paid action**, **execute only on explicit
approval**, and **serve Business/Agency/Platform as one component with
three authorization contexts** — entirely on top of the existing
`AiGateway`, `coo_insights` and RFC-002 approval machinery.

It also states, precisely, the one thing this contract must not let
anyone claim: §23 compliance cannot be asserted while `main` still
autonomously sends an AI-authored SMS with no human in the loop (§3.6).

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
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §20, §26 (budgets, caps,
  permissions, Location scope) and §32 (audit) — cited by §23 itself as
  the constraints the COO inherits rather than redefines (§6, §10).
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §21 (Plan Model) — "|
  Basic AI COO | ✓ | ✓ | ✓ |" across Core, Growth and Agency. The
  entitlement identity `PlatformFeature::AiCooBasic` is already
  `Available` in `app/Library/Entitlement/PlatformFeatureRegistry.php:75`;
  this contract adds **no** new AI entitlement (§6.3, §11).
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §34 — places "Text/typed
  interaction with AI COO" in the V1 column. This conflicts with a
  locked owner decision in the merged COO contract; surfaced, not
  resolved, in §3.7.
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
  on `main`. This contract **extends** it and does not restate it; the
  two places where completing §23 requires *amending* it are named
  explicitly (§3.5, §3.7).
- `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md` §13.1, §28, §30, §31 — the
  only server-side approve-then-execute lifecycle in the product, and
  therefore the only lawful home for a COO-offered action (§5.4, §6.5).
- `docs/rfcs/RFC-005` (usage billing and wallets) — the sole authority
  for customer money; the customer-facing cost estimate in §5.3 is
  derived from its seams and from `EffectivePayerResolver`, never
  invented.
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

## 3. Current repository reality — recon findings (as of this contract's writing, on `main` @ `30ad21c7`)

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
- **Entitlement identity, live.** `PlatformFeature::AiCooBasic`
  (`ai_coo_basic`) is `Available` and packaged into Core, Growth and
  Agency (`app/Library/Entitlement/PlatformFeatureRegistry.php:75`).

### 3.3 Confirmed absent on `main` — the real gap surface

Each line below was confirmed by direct file inspection, not inference.

1. **No drafting anywhere in the COO.** `CooInsightOutputValidator`
   accepts exactly the statement keys `['class', 'text', 'fact_refs']`
   (`:99`) within ≤3 statements of ≤280 characters (`:36-42`). There is
   no draft store, no draft review/edit surface and no draft category.
   The three drafting capabilities that do exist
   (`AiUsageCategory::WebsiteGeneration`, campaign copy, conversation
   compaction) belong to other features, not to the COO.
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
5. **View As is handled by prohibition, not attribution.**
   `CooInsightExplainController.php:56-58` is `abort(403)` when
   `CustomerContext::isViewingAsClient()`; `customer.opportunities.` is
   denylisted in `ViewAsRouteClassification.php:122`. Neither
   `coo_insights` nor `ai_usage_ledger` nor `opportunity_transitions`
   records the real-actor/viewed-actor pair. §23 explicitly requires the
   Agency owner to use the COO "through View As, one client at a time",
   so prohibition alone cannot satisfy it.
6. **No audit chain from "AI said" to "human approved" to "system
   executed".** `ai_usage_ledger` stores spend with no subject reference
   and no content; `coo_insights` stores content with **no actor column
   at all** (migration `:36-55`); `opportunity_transitions`
   (`2026_07_19_120005_...:10-26`) records no IP, user agent, session,
   View-As linkage, model or prompt version. Three unrelated stores,
   no join.
7. **Approval hardening gaps in the one lifecycle that exists.** No
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
8. **Only one authorization context is served.** `coo_insights.business_id`
   is `NOT NULL` (`migration :37`) and `subject_type` is only ever
   written as `'business'`; there is no Agency-level AI insight
   (`resources/views/customer/dashboard/agency-home.blade.php`) and no
   Platform/admin-level AI insight (the only admin AI surface is the
   read-only usage ledger).
9. **The AI can only produce text.** `AiCompletionRequest` carries
   route/provider/model/messages/`maxOutputTokens`/json-mode only — no
   tool/function calling, therefore no action-execution boundary to
   secure at the provider layer (which is, for V1, a *safety asset*, and
   §6.5 keeps it that way).
10. **`AiRequest` carries no Location scope and no permission context**
    (`app/Library/Ai/AiRequest.php:19-31`).
11. **Proactive risk surfacing is entirely deterministic, and the AI is
    structurally suppressed when it matters most.**
    `CooInsightFacts::hasDeterministicExplanation()` (`:72-85`) suppresses
    the AI whenever **any** Attention item is raised. §23's three named
    risks map onto deterministic Attention types today — and the third,
    "a stalled Opportunity", sits behind
    `config/opportunity.php:4`, `'enabled' => env('OPPORTUNITY_ENGINE_ENABLED', false)`.
12. **The explain rate limit is cache-only, business-keyed and
    controller-only.** `CooInsightExplainLimiter.php:22-30` uses
    `Cache::add`/`Cache::has` keyed on business — not durable, not
    auditable, not per-actor — and the generator's own condition for
    `ExplainThisChange` is unconditionally `true`
    (`CooInsightGenerator.php:199-201`).
13. **`policy_version` is not part of the database UNIQUE key**, only of
    the fingerprint and the selection filter (`migration :57-60`).
14. **Two idempotency keys in the AI stack are random.**
    `WebsiteAiGenerationClient.php:83` and `CampaignController.php:3001`
    pass `Str::uuid()`, which makes the ledger's UNIQUE column decorative
    for those callers.
15. **No provider timeout, retry policy or circuit breaker.**
    `OpenAiCompletionClient.php:34` constructs the client with no timeout
    or HTTP options.

### 3.4 Reusable existing conventions — mirrored, not reinvented, in §5–§11

`AiGateway::complete` · `AiRequest`/`AiResult` · `AiUsageCategory` +
`config('ai.category_routes')` · `AiLane::Interactive` and its hard-capped
share · `AiModelRouter` · `AiUsageLedgerManager::idempotencyFamily/reserve/settle`
· `AiBudgetPolicyResolver::resolveFor` · `AiRefusalReason`'s nine typed
refusals · `AiBusinessActivityGate::isDormant` ·
`AiCompletionClient`/`FakeAiCompletionClient` · `CooInsightFacts`
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
`DashboardLinkGate` · `MenuEntitlements::allows()` ·
`DashboardSnapshot` band registry + `band-failed.blade.php` ·
`window.AsyncRegion` · the `x-card`/`x-badge`/`x-button`/`x-alert`/
`x-empty-state`/`x-dialog` design-system components.

**Contract rule R-0:** every sub-slice below is a consumer or extender of
the list above. A sub-slice that introduces a second gateway, a second
insight table, a second approval lifecycle, a second audit ledger, a
second entitlement identity or a second Location ACL is out of contract
by definition, regardless of how convenient it is.

### 3.5 What completing §23 requires *amending*, not merely extending

The merged COO contract deliberately locked three positions that §23's
full text cannot be satisfied under. They are listed here so that no
implementer silently overrides a locked owner decision:

| Locked position | Where | Why §23 cannot be met under it | Handled by |
|---|---|---|---|
| "**One Business per prompt.** A prompt contains facts from exactly one Business. There is no cross-client prompt, including for the Agency Account Home." | merged contract §15.2 | §23 requires an Agency-owner context "scoped to Agency operations" and a Platform-Owner context | `19.H` — **blocked** on owner amendment (§3.7, §16) |
| "Nothing the model writes can trigger an action. Actions stay behind the existing Opportunity approval flow." | merged contract §12 | This is **kept**, not amended. §23 is satisfied by letting the COO *offer* an action that a human approves through RFC-002 — the model still triggers nothing | `19.D`–`19.F` (§6.5) |
| View-as sessions "never start an interactive AI request, and never trigger explain-this-change" | merged contract §15.5 | §23 requires the Agency owner to use the COO "through View As, one client at a time" | `19.A`+`19.G` — read/explain/draft permitted **with attribution**; approval and execution stay prohibited under View As (§6.4) |

### 3.6 A live Blueprint §23 violation exists on `main` — isolated and escalated, not absorbed

`app/Jobs/AgencyProspectingRespondJob.php` calls the AI at `:98`
(`$aiClient->complete(...)`), takes the model's reply as the message body
at `:163` (`$body = $decision->reply;`), and at `:195` calls
`$sender->send($claim['channel'], $claim['channel']->sender_number, $claim['prospect']->phone, $claim['body'])`.
No human approval exists anywhere in that path. Verified by direct
inspection of the file at `30ad21c7`.

That is an autonomous, externally-visible, AI-authored send — the exact
act §23 names in its **MUST NOT**. It is **outside** the COO subsystem,
and this contract does not silently fold it into a COO sub-slice. It is
raised as `19.P0` (§12) because of what it means for acceptance: **no
sub-slice below may be used to claim Blueprint §23 compliance for the
product while this path exists as written** (§14.11, §16). Whether
agency prospecting auto-reply is an intended, separately-authorized
exception to §23 is a product decision for the owner, not an engineering
judgement — it is listed as a blocking unresolved product decision in
§3.7.

### 3.7 Blocking unresolved product decisions

Unlike Contract 16 ("BLOCKING UNRESOLVED PRODUCT DECISIONS: NONE"), this
slice has three. Each blocks exactly one sub-slice and nothing else.

- **P-1 — Agency/Platform COO scope.** Blueprint §23 requires three
  authorization contexts; merged COO contract §15.2 forbids any
  cross-Business prompt. An owner amendment to §15.2 is required before
  `19.H` may be authorized. This contract proposes the *shape* of that
  amendment (§5.6) and builds nothing under it.
- **P-2 — Typed/interactive COO.** Blueprint §34 places "Text/typed
  interaction with AI COO" in the V1 column; merged contract decision
  D-5 defers interactive chat and leaves its slice AI-4 unscheduled,
  with `coo_threads`/`coo_thread_messages` reserved. This contract builds
  **no** chat surface (§15) and treats §23's "Ask COO" as a bounded,
  one-shot explain/draft affordance (§12.G). If the owner rules that §34
  governs, AI-4 must be scheduled under the merged contract, not
  retrofitted here.
- **P-3 — Agency prospecting auto-send.** §3.6. Either it is remediated
  (`19.P0`) or it is explicitly declared an authorized exception to §23
  and documented as such in the Blueprint. It cannot remain
  undocumented and unremediated while the product claims §23.

## 4. Gap map — every Blueprint §23 requirement, classified

Classification vocabulary as required: **complete** / **partial** /
**missing** / **incompatible** (implemented but architecture-incompatible).

| # | §23 requirement (source phrase) | Class | Evidence on `main` @ `30ad21c7` | Closed by |
|---|---|---|---|---|
| 1 | "anchored on Home (§8)" | **partial** | One cached insight block nested inside the headlines band (`bands/headlines.blade.php:76-98`), bound to the analytics period key; not a first-class COO surface, and §8's "Ask COO" entry point does not exist | `19.G` |
| 2 | "and reachable elsewhere in context" | **missing** | Exactly one read on Home (`BusinessHomePresenter.php:713-719`) and one POST route (`routes/customer.php:742`) scoped to the performance band | `19.G` |
| 3 | "explains what it's looking at" | **complete** | Cached "What we notice" + customer-triggered "Explain this change"; validator-enforced grounding; `CooInsightDisplayReader` read-never-generate | — (preserved) |
| 4 | "recommends a next action" | **complete (deterministic)** | `NextBestMoveSelector.php:49` fixed seven-step pool + `WhyThis`; merged contract §6.5 "**No AI.**" | — (preserved; `19.C` adds an AI *explanation of* it, never a competing AI recommendation) |
| 5 | …but the recommendation is unlinkable to the AI that explains it | **incompatible** | `NextBestMove.php:13-15` — "nothing is persisted: a move is recomputed on every render"; `CooInsightKind::move_explanation` is reserved and deliberately unused (`CooInsightKind.php:8-14`); no FK, no shared shape | `19.C` |
| 6 | "drafts content (messages, automation copy, proposal text)" | **missing** | No draft store, no draft surface, no draft category; validator output is `{class,text,fact_refs}` only (`CooInsightOutputValidator.php:99`) | `19.F` |
| 7 | "estimates cost before any paid action" | **missing** | Only provider micro-USD exists, admin-only by merged contract §15.4 (`AiUsageCostEstimate.php:14-24`); nothing estimates customer money anywhere in the recommendation domain | `19.E` |
| 8 | "surfaces risks … proactively rather than only on request" | **partial** | Deterministic Attention items cover wallet/automation/website/Google; the AI is *suppressed* whenever any Attention item is raised (`CooInsightFacts.php:72-85`); the "stalled Opportunity" risk is behind `config/opportunity.php:4` default-false | `19.C` (surfacing), `19.D` (engine readiness) |
| 9 | "**MUST NOT** execute … without explicit user approval" — as it applies to the COO | **complete** | Merged contract §12: "Nothing the model writes can trigger an action"; the gateway is text-only (`AiCompletionRequest.php:14-22`), so there is no tool-calling path to secure | — (preserved and made explicit as an architecture test in `19.F`) |
| 10 | …as it applies to the **product** | **incompatible** | `AgencyProspectingRespondJob.php:98 → :163 → :195` autonomously sends an AI-authored SMS | `19.P0` (§3.6) |
| 11 | "it drafts and recommends, the human confirms" — a server-side approval authority | **partial** | RFC-002 has a real lifecycle (`requestApproval` `:866`, `confirmApproval` `:1032`, `beginExecutionAttempt` `:1708`, action hash, idempotency, transitions) but it is unreachable from any COO path, supports one action key (`OpportunityActionExecutor.php:27`), and has no entitlement/permission/wallet/Location check | `19.D` |
| 12 | "No stale pre-approval decision grants future permission" (contract requirement over §23) | **partial** | `OpportunityActionHash` genuinely binds the approved parameters; but approvals never expire, and `retryFailedExecution` (`:1138-1227`) re-executes without re-approval | `19.D` |
| 13 | "respects the same … budgets, caps" (§20) | **partial for AI spend, missing for customer money** | `AiBudgetPolicyResolver`/`AiUsageLedgerManager` are real and enforced; no wallet/balance/cap check exists anywhere in the approval or execution path | `19.D` (guard chain), `19.E` (estimate) |
| 14 | "…permissions" (§26) | **partial** | The gateway checks entitlement; `DashboardLinkGate`/`MenuEntitlements` gate Home links; but the Opportunity approval routes carry no per-route capability check (`routes/customer.php:650-685`) and `OpportunityController` has no `authorize()` call | `19.B`, `19.D` |
| 15 | "…and Location scope" (§26) | **missing** | No Location column, filter or guard in `app/Library/Coo/**`, `coo_insights`, `opportunities`, or `AiRequest` | `19.A`, `19.B` |
| 16 | "every approved action it takes is logged like any other actor's action (§32)" | **missing** | Three unrelated stores, no actor column on `coo_insights`, no request context on `opportunity_transitions`, no join from spend → content → approval → execution | `19.A` (attribution), `19.D` (chain) |
| 17 | "a Business owner/staff member" context | **complete** | The only shipped context | — |
| 18 | "an Agency owner … and, through View As, one client at a time" | **incompatible** | `coo_insights.business_id` is `NOT NULL`; merged contract §15.2 forbids cross-client prompts; View As is a hard `abort(403)` on the one interactive entry (`CooInsightExplainController.php:56-58`) | `19.A` (attribution) + `19.H` (**blocked**, P-1) |
| 19 | "the Platform Owner (scoped to platform operations)" | **incompatible** | Same schema constraint; the only admin AI surface is the read-only usage ledger | `19.H` (**blocked**, P-1) |
| 20 | "one component, three authorization contexts, never three implementations" | **missing** | No scope abstraction exists; the pipeline hard-codes Business | `19.A` (`CooScope`), `19.H` (activation) |

Summary: of twenty §23 obligations — **4 complete** (rows 3, 4, 9, 17),
**6 partial** (1, 8, 11, 12, 13, 14), **6 missing** (2, 6, 7, 15, 16,
20) and **4 architecture-incompatible** (5, 10, 18, 19). Two of the four
incompatible items (18, 19) share a single root cause — the Business-only
`coo_insights` schema plus the §15.2 one-Business-per-prompt lock — and
are therefore both blocked behind the same product decision (P-1). The
third (5) is the unlinked deterministic-move / AI-explanation pair, fixed
cheaply by `19.C`. The fourth (10) is the live violation in §3.6 and is
not an engineering decision at all.

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
It is assembled once from `CustomerContext` and never widened downstream.

| Field | Source | Purpose |
|---|---|---|
| `scope` | `CooScope::Business` \| `Agency` \| `Platform` | The one component's three authorization contexts (§5.6). `Business` is the only value any sub-slice before `19.H` may construct. |
| `workspace_id` | `CustomerContext::frameWorkspace()` | Budget owner, entitlement subject. |
| `business_id` | `CustomerContext::selectedBusiness()` | Nullable only under `Agency`/`Platform` scope (`19.H`). |
| `business_location_id` | the actor's effective Location scope via `LocationAccessGuard` | `null` means *all Locations the actor may read*; a value means a single-Location actor. Part of the fingerprint and of the read filter (§5.3, §6.2). |
| `actor_user_id` | the authenticated human | The real human. Never the viewed client. |
| `view_as_session_id` | `CustomerContext` View-As state | Nullable FK. Non-null means the actor is an Agency user viewing a client (§6.4). |
| `capabilities` | the existing customer-permission snapshot | The set of feature permissions the actor actually holds; facts and offers are filtered by it (§6.2). |
| `entitlements` | `MenuEntitlements::allows()` snapshot | Same snapshot Home already uses; never re-derived ad hoc. |
| `subject_type` / `subject_id` | the surface | What the COO is looking at. |
| `surface_route` | the current route name | Classified in `ViewAsRouteClassification` (§6.4) and recorded in audit. |
| `facts_fingerprint` | `CooInsightFacts::fingerprint()` | Staleness identity (§5.7). |
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
   `confirmed_by_user_id` is absent, or whose initiating principal equals
   its confirming principal *type* `'coo'`, is rejected. Self-approval by
   a human (same person requests and confirms) stays permitted — §23
   requires *a* human confirmation, not four eyes.
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

`coo_drafts` columns: `uid`; `workspace_id`; `business_id` (nullable,
per §5.6); `business_location_id` (nullable); `draft_kind`
(`message` \| `automation_copy` \| `proposal_text`); `subject_type` /
`subject_id`; `model_body` (immutable — what the AI produced);
`edited_body` (nullable — what the human made of it); `status`
(`drafted` \| `edited` \| `discarded` \| `consumed`); `consumed_by_type` /
`consumed_by_id` (the record that used it); `actor_user_id`;
`view_as_session_id` (nullable); `ai_usage_ledger_entry_id`;
`prompt_version`; `policy_version`; `facts_fingerprint`; timestamps +
`expires_at`.

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

### 5.6 Scope — one component, three authorization contexts

`CooScope` is an enum plus a resolver that answers four questions for a
given envelope: *which facts reader*, *which entitlement subject*,
*which budget owner*, *which action set is offerable*.

| Scope | Facts | Budget/entitlement subject | Offerable actions | Status |
|---|---|---|---|---|
| `Business` | `CooInsightFactsReader`, one Business, actor-permission and Location filtered | the Business's Workspace | Business-scoped Opportunity actions | built (`19.A`–`19.G`) |
| `Agency` | Agency operations only — the Agency's own Workspace/Business plus relationship-level aggregates. **Never a cross-client prompt.** One client at a time is reached through View As, which resolves to `Business` scope with attribution, not to a portfolio prompt | the Agency Workspace | Agency-scoped actions only | **blocked** (P-1) |
| `Platform` | platform operations aggregates only | platform | platform actions only | **blocked** (P-1) |

The proposed shape of the P-1 amendment, for the owner's decision and
**not** implemented here: *§15.2's "one Business per prompt" remains
true for every prompt that contains Business facts; an Agency- or
Platform-scoped prompt contains no Business facts at all, only
operations-level aggregates about the Agency's or the platform's own
entities.* That keeps the original rule's intent — no cross-client data
mixing — while permitting the two contexts §23 requires. Schema
consequence: `coo_insights.business_id` becomes nullable with a `scope`
discriminator and a writer-enforced invariant (`scope = business` ⟺
`business_id` non-null), plus a matching UNIQUE key change. `19.A`
prepares the columns; only `19.H` may write a non-`business` scope.

### 5.7 Staleness and invalidation

Existing mechanism, extended — not replaced:

1. **Signal change** → `CooInsightFacts::fingerprint()` differs → a new
   row, old row simply not selected. Unchanged.
2. **Workflow/event change** → `CooInsightInvalidator` (`:27`) remains the
   sole writer of `invalidated_at`; `19.C`/`19.F` add reasons to its
   existing vocabulary rather than new writers.
3. **Prompt/policy retirement** → `prompt_version`/`policy_version`.
   `19.A` adds `policy_version` to the database UNIQUE key, closing
   §3.3(13): today two rows differing only by `policy_version` collide.
4. **Scope change** → `business_location_id` joins both the fingerprint
   and the UNIQUE key, so a Location-scoped actor can never read an
   all-Location row and vice versa (§6.2).
5. **Draft staleness** → a draft whose `facts_fingerprint` no longer
   matches is marked stale on read and shown with an explicit "facts
   changed since this was drafted" notice; it is never silently
   regenerated and never silently consumed.
6. **Approval staleness** → `OpportunityActionHash` (parameters) +
   `approval_expires_at` (time) + the re-run guard chain (authority) +
   the recomputed cost ceiling (money). All four, every execution
   attempt (§5.4, §6.5).

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

### 6.2 Permission and Location filtering

- Facts are composed only from sources the actor may read.
  `CooInsightFactsReader` takes the envelope and applies
  `LocationAccessGuard` (`:73`, `:152`); a fact source with no Location
  support is either provably Business-wide-and-safe or excluded — never
  included "because it is only an aggregate".
- The envelope's `business_location_id` is part of the fingerprint and
  the UNIQUE key, so insights do not leak across Location scope through
  the cache (§5.7(4)).
- Every offered link or action passes `DashboardLinkGate`, which is
  already permission- and entitlement-aware
  (`BusinessHomePresenter.php:792`).
- **Rule R-7 (never narrate what the actor cannot see).** If a fact is
  excluded by permission or Location, the COO does not reference it,
  hint at it, or explain its absence.

### 6.3 Entitlement

`PlatformFeature::AiCooBasic` is the only AI entitlement. No sub-slice
adds a second. It is already `Available`, so any route a sub-slice ships
is customer-reachable immediately and must carry its gate on day one
(§11). The gateway already enforces it for categories that require it;
HTTP surfaces additionally check `MenuEntitlements::allows()` exactly as
`CooInsightExplainController.php:81` does today.

### 6.4 View As — attribution, not expansion

- Every COO record (`coo_insights`, `coo_drafts`, the approval and
  execution rows, the transitions) carries `actor_user_id` (the real
  human) and nullable `view_as_session_id`. Audit answers "who really
  did this" without inference.
- **Read, explain and draft are permitted under View As**, with
  attribution — this is what §23's "through View As, one client at a
  time" requires, and `19.A`+`19.G` deliver it.
- **Approval and execution of a consequential action remain prohibited
  under View As in V1**, inheriting the existing classification
  (`customer.opportunities.` is already denied at
  `ViewAsRouteClassification.php:122`). View As changes *who is
  attributed*; it never widens *what may be executed*.
- **Rule R-8.** Every new COO route is classified in
  `ViewAsRouteClassification`/`ViewAsProhibitedActions` in the same
  commit that creates it, or the existing security suite fails. No route
  reaches `main` unclassified.
- **Rule R-9.** Attribution is captured at the HTTP boundary into the
  envelope and carried into jobs. A queued job never re-derives actor
  identity from the tenant record, because
  `CustomerAccountAccessGate` is HTTP-only and does not re-apply inside
  a job.

### 6.5 The action execution boundary

There is exactly one: `ExecuteOpportunityAction` →
`OpportunityActionExecutor`, entered only through
`OpportunityManager::beginExecutionAttempt()`.

**Rule R-10 (architecture test, `19.F`).** No class under
`app/Library/Coo/**`, no COO controller and no COO job may reference a
message sender, a publish/automation-activation API, a wallet debit, a
billing/payer mutation, or a permission mutation. Mirrors the existing
`AiGatewayBypassArchitectureTest` and is enforced the same mechanical
way. Proposed name: `CooActionBoundaryArchitectureTest`.

**Rule R-11.** Model output never reaches an executor. A draft reaches a
send path only as human-edited content through that path's own
authorization (R-5).

### 6.6 Idempotency

- AI calls: `AiUsageLedgerManager::idempotencyFamily()` with a key
  **derived** from the envelope (scope, subject, fingerprint, prompt and
  policy version). **Rule R-12: no COO code path may pass a random
  idempotency key.** (`19.G` additionally repairs the two existing random
  callers noted in §3.3(14), as a bounded, in-scope correction of the
  same rule.)
- Actions: the existing server-derived `idempotency_key` over its UNIQUE
  column (`OpportunityManager.php:1057-1060`). Never accepted from the
  browser, a header, or model output (RFC-002 §31).
- Drafts: a draft is not idempotent and does not need to be; a duplicate
  draft costs a bounded interactive-lane call and is discardable.

## 7. Transaction / concurrency boundary

1. **No provider call inside a transaction.** Already the gateway's
   shape (reserve → call outside → settle); preserved everywhere.
2. **Reserve-before-call, settle-after.** Unchanged
   (`AiUsageLedgerManager::reserve/settle`), including the existing
   stale-reservation sweep.
3. **Approval and execution take the row lock first.**
   `OpportunityRepository::findOwnedForUpdate()` before any guard that
   reads mutable state, so the guard chain cannot be raced.
4. **The idempotency claim is the serialization point.** Two concurrent
   execution attempts: one claims the UNIQUE key, the other observes the
   claim and returns the first's outcome. Never two effects.
5. **Cost ceiling check happens inside the locked section**, against
   state read under the lock, immediately before the effect.
6. **Draft edits are last-write-wins on `edited_body` only**;
   `model_body` is immutable after insert, and a draft transitions to
   `consumed` exactly once, enforced by a conditional update on `status`.
7. **Insight writes stay `ShouldQueueAfterCommit`, `tries = 1`**
   (`GenerateCooInsight.php:33-64`). A failed AI generation is never
   retried at cost.

## 8. Migration / backfill

- `19.A`: add `business_location_id` (nullable), `actor_user_id`
  (nullable, `nullOnDelete`), `view_as_session_id` (nullable,
  `nullOnDelete`), `scope` (default `'business'`) to `coo_insights`;
  make `business_id` nullable; replace the UNIQUE key with
  `(scope, business_id, business_location_id, kind, subject_type,
  subject_id, signal_fingerprint, prompt_version, policy_version)`.
  Existing rows backfill to `scope = 'business'`, `business_location_id
  = null`, `actor_user_id = null` — semantically correct, since every
  existing row was generated Business-wide by a system trigger.
- `19.D`: add `paid_effect` to the action registry (source-controlled,
  not a column), `approval_expires_at` and the approval-side cost
  snapshot columns to the approval/execution rows; extend
  `opportunity_transitions` with `actor_user_id`, `view_as_session_id`,
  `initiated_by_type` and a nullable `coo_insight_id`/`coo_draft_id`
  provenance reference. No backfill of historical transitions: absent
  attribution stays absent rather than being fabricated.
- `19.E`: cost snapshot columns are written only for `paid_effect`
  actions; existing rows keep `null`, which the executor treats as "not
  a paid-effect action" — never as "unlimited".
- `19.F`: create `coo_drafts` (§5.5).
- **Rule R-13.** No migration in this slice edits a previously merged
  migration file. Every change is a new migration, per this repository's
  existing convention.

## 9. Backwards compatibility

- Home's rendered output is unchanged until `19.C`, and after `19.C` the
  deterministic move still renders identically when no explanation
  exists. `CooInsightDisplayReader::forHome()` keeps its
  read-never-generate contract.
- Existing cached insights remain displayable across `19.A`'s UNIQUE-key
  change (the backfilled discriminators reproduce today's semantics
  exactly); a row that cannot be reproduced under the new key is simply
  regenerated on the next trigger, at bounded cost.
- `19.D` changes no behavior for the single existing action key beyond
  adding gates that the one existing action already satisfies — with one
  deliberate exception: an approval older than the new expiry window can
  no longer execute. That is the intended behavior change, and it is the
  point of §5.4(3).
- The Opportunity engine remains behind `config/opportunity.php`'s
  default-false kill switch; nothing in this slice flips it.
- Admin AI usage surfaces, budgets and the ledger are untouched.

## 10. Events / audit

The audit obligation in §23 is "logged like any other actor's action
(§32)". Implementation: **extend the existing ledger, add no parallel
audit store.**

The chain, after `19.D`:

`ai_usage_ledger` entry (what the call cost) → referenced by
`coo_insights.ai_usage_ledger_entry_id` / `coo_drafts.ai_usage_ledger_entry_id`
(what was said or drafted, by which prompt/policy version, for which
actor, under which View-As session) → referenced by the approval record's
provenance column (what the human was shown) → `opportunity_transitions`
(who approved, when, under what attribution) →
`opportunity_action_executions` (what actually ran, idempotency key,
attempt number, `started_at`, outcome).

Every hop is a real column. "Who told the customer to do this, who
approved it, and what did it cost" is answerable with joins, not
forensics.

No new domain events are introduced. Existing
`CooInsightInvalidator`-triggering events and the Opportunity transition
vocabulary are extended where needed.

## 11. Billing / provider safety

- **Platform spend** is governed entirely by the existing
  `AiBudgetPolicyResolver` caps and the reserve/settle ledger. Each new
  COO surface declares an `AiUsageCategory` case and a route in
  `config/ai.php` — never a model literal in domain code, never a call
  without a category (merged contract §10.2 defines a category-less call
  as a bug).
- **Lane assignment.** Human-initiated explain and draft draw on
  `AiLane::Interactive` and inherit its existing hard-capped share.
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

### 19.P0 — Remediate the live §23 violation (separately authorized; not a COO sub-slice)

- **Files/domains**: `app/Jobs/AgencyProspectingRespondJob.php` only.
- **Substance**: either (a) interpose a human approval step before
  `:195`'s send, reusing the approval lifecycle rather than inventing
  one, or (b) — if the owner rules it an authorized exception — leave the
  code unchanged and amend the Blueprint to document the exception
  explicitly. **This is decision P-3 (§3.7); engineering picks neither
  option unilaterally.**
- **Prerequisites**: owner decision P-3.
- **Tests**: a regression test proving no AI-authored outbound send
  occurs without an approval record (option a), or an explicit
  documented-exception test asserting the narrow scope of the exception
  (option b).
- **Risk**: Critical (live customer-visible sends).
- **Model**: Opus 5.
- **Blocking**: §23 compliance cannot be claimed product-wide until this
  closes (§14.11, §16).

### 19.A — Envelope, attribution, Location and scope columns (no new customer surface)

- **Files/domains**: new `CooContextEnvelope` and `CooScope` in
  `app/Library/Coo/`; one migration per §8 against `coo_insights`; model
  casts/relations; envelope assembly from `CustomerContext` at the one
  existing COO entry point; correction of
  `V1-AUTHORITY-TRACEABILITY-MATRIX.md` row 22 to reflect actual `main`.
- **Explicitly not in scope**: writing any non-`business` scope value;
  any new route; any behavior change on Home.
- **Tenancy/security**: envelope assembly only — R-2, R-9.
- **Concurrency**: none (no new write paths).
- **Tests**: UNIQUE-key change proven (two rows differing only by
  `policy_version` now coexist; two rows differing only by
  `business_location_id` now coexist); existing insights still display
  unchanged; envelope carries the real actor under View As and never the
  viewed client; a non-`business` scope write is rejected at this stage.
- **Risk**: Medium (schema change to a shipped, cached table).
- **Model**: Sonnet 5.

### 19.B — Permission- and Location-aware fact composition

- **Files/domains**: `CooInsightFactsReader` takes the envelope and
  applies `LocationAccessGuard`; `CooInsightDisplayReader` filters by the
  envelope's Location scope; fact sources without Location support are
  explicitly classified as safe-Business-wide or excluded, in code, with
  a comment naming which.
- **Prerequisites**: `19.A` merged.
- **Tenancy/security**: R-7 is the acceptance bar — an insight generated
  for an all-Location actor must be unreadable by a single-Location
  actor and vice versa.
- **Tests**: adversarial matrix — Location-scoped actor vs all-Location
  insight; all-Location actor vs Location-scoped insight; actor missing
  the capability behind a fact source; cache-poisoning attempt across
  Location scope; query-count budget unchanged (no N+1 introduced into
  Home's bounded read).
- **Risk**: **High** — this is the data-leak surface of the whole slice.
- **Model**: Opus 5.

### 19.C — `move_explanation`: AI explains the deterministic recommendation

- **Files/domains**: activate `CooInsightKind::move_explanation`; a
  subject identity for the selected `NextBestMove` (§5.1); prompt builder
  extension; validator reuse unchanged; trigger + `AiUsageCategory` case
  + `config/ai.php` route; Home renders the explanation **attached to**
  the existing deterministic band, never as a second recommendation;
  narrow the §3.3(11) suppression so an explanation may accompany a
  raised Attention item while the deterministic text stays primary.
- **Prerequisites**: `19.A`, `19.B` merged.
- **Tests**: the move renders identically when the explanation is
  absent, refused, expired or invalidated; the AI never changes which
  move is selected (mutation test: a model returning a different action
  changes nothing on screen); grounding — every explanation statement
  carries a `fact_ref` resolvable to a fact in the snapshot; no provider
  call on a Home render (the existing T-COO-1-style proof, extended).
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
- **Prerequisites**: none on the COO side. **This sub-slice is the hard
  prerequisite for `19.E` and `19.F`.**
- **Tenancy/security**: the §5.4(2) chain, re-evaluated at execution;
  `LocationAccessGuard` for Location-bound actions; `EntitlementManager`
  at both stages.
- **Concurrency**: §7.3–§7.5.
- **Tests**: adversarial approval matrix — capability revoked between
  approval and execution; entitlement lost between approval and
  execution; Location access revoked; parameters mutated (hash
  mismatch); approval expired; retry of a mutating action without
  re-approval refused; two concurrent executions produce one effect;
  kill switch honoured by every lifecycle method; the single existing
  `add_phone` action still executes end-to-end unchanged.
- **Risk**: **Critical** — this touches the only live approve-then-execute
  path in the product.
- **Model**: Opus 5.

### 19.E — Customer-facing action cost estimate

- **Files/domains**: `ActionCostEstimate` value object and a
  deterministic estimator in `app/Library/Coo/` (or
  `app/Library/Opportunity/`, wherever the registry lives), reading
  RFC-005 pricing seams and `EffectivePayerResolver`; approval-record
  snapshot columns; executor ceiling re-check (R-3); the pre-approval UI
  affordance showing payer, amount/units, basis and wallet sufficiency.
- **Prerequisites**: `19.D` merged.
- **Tenancy/security**: the estimate names the **effective payer**, which
  under agency rebilling is not necessarily the Business's own
  Workspace; the estimate is computed server-side and never accepted
  from the client.
- **Concurrency**: §7.5.
- **Tests**: estimate matches a deterministic fixture exactly; a price
  change between approval and execution refuses execution and returns
  to `awaiting_approval`; a payer change refuses; a `paid_effect` action
  with no estimate refuses; an insufficient wallet is surfaced before
  approval, not discovered at execution; no estimate value ever
  originates from model output (R-4, enforced by the architecture test
  in `19.F`).
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
  path's own authorization and, when paid, to `19.E`'s estimate.
- **Tests**: a draft is never auto-consumed by any code path (proved by
  the architecture test plus an exhaustive grep-style source-boundary
  test, mirroring the existing gateway-bypass test); no PII reaches a
  drafting prompt (placeholders only); `model_body` immutable after
  insert; a stale draft is flagged and never silently regenerated;
  consumption happens exactly once under concurrency; a draft in a
  Location the actor cannot access is unreadable.
- **Risk**: **High** (this is the sub-slice a reviewer will assume
  violates §23; its tests exist to prove it does not).
- **Model**: Opus 5.

### 19.G — "Ask COO" entry point, contextual reach, durable limiting, provider robustness

- **Files/domains**: the §8 "Ask COO" affordance on Home bound to
  exactly what Home is showing (§5.2); contextual explain/draft on a
  small, enumerated set of surfaces; all new routes classified per R-8;
  replace `CooInsightExplainLimiter`'s cache-only claim with a durable,
  per-actor, per-subject, auditable limit and enforce it in the
  generator as well as the controller (§3.3(12)); provider timeout
  (§11); repair the two random idempotency keys (R-12, §3.3(14)).
- **Prerequisites**: `19.C`, `19.F` merged.
- **Tenancy/security**: R-8; entitlement + capability gate on every new
  route on day one (§11).
- **Tests**: every new route is classified (the existing View-As security
  suite is the enforcement); the limiter survives a cache flush; an
  unentitled or uncapable actor sees no affordance and gets a typed
  refusal if they post directly; Home still renders with the AI provider
  hard-down.
- **Risk**: Medium.
- **Model**: Sonnet 5.

### 19.H — Agency and Platform scope — **BLOCKED**

- **Prerequisites**: owner decision **P-1** (§3.7) amending merged
  contract §15.2, **plus** `19.A`–`19.G` merged.
- **Files/domains**: activate `CooScope::Agency` and `CooScope::Platform`
  (facts readers, budget/entitlement subjects, offerable action sets);
  Agency Home and Platform Home surfaces; acceptance-matrix rows for the
  Agency Owner and Platform Owner actors, which do not exist today
  (§3.3(8)).
- **Tenancy/security**: no cross-client prompt under any circumstance;
  one client at a time is reached through View As, which resolves to
  `Business` scope with attribution (§5.6) — not to a portfolio prompt.
- **Risk**: **Critical**.
- **Model**: Opus 5.
- **Status**: may not be authorized, scheduled or started until P-1 is
  decided in writing.

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
3. **The leak matrix** (`19.B`): Location × capability × entitlement ×
   View-As, asserted on what the COO *says*, not only on HTTP status.
4. **The boundary architecture test** (R-10), mechanical, in the same
   style as `AiGatewayBypassArchitectureTest`.
5. **The audit-chain test**: from a single approved action, join
   backwards to the approving human, the View-As session if any, the
   insight or draft shown, the prompt/policy version and the ledger
   entry — in one query path, with no nulls on the load-bearing hops.
6. **Graceful degradation**: provider down, budget exhausted, entitlement
   absent, dormant Business — Home renders, the deterministic move still
   appears, and each case produces a distinct `AiRefusalReason` rather
   than a generic failure.
7. **Query-budget flatness** on Home after `19.B`/`19.C`, re-pinned
   explicitly if the observed count changes, in the existing dashboard
   query-budget style.

No full-repository suite run is required by this contract; each
sub-slice names its own focused set, plus the existing COO, Opportunity,
AI-gateway and View-As security suites its files touch.

## 14. Acceptance criteria

1. The COO explains, recommends, drafts and estimates cost — all four
   §23 verbs reachable from Home for an entitled, permitted actor.
2. No AI code path produces an externally visible effect. The only
   executor is `OpportunityActionExecutor` behind
   `beginExecutionAttempt()` (R-10, proved mechanically).
3. Approval is server-side authority: a forged or replayed confirmation
   without the guard chain cannot execute (§6.1 S-3).
4. Every execution re-checks authority, parameters, price and freshness;
   no approval grants a future permission (§6.1 S-4, §13.2).
5. Every paid-effect action shows the approving human the payer, the
   amount or units, and the basis, before approval — and refuses if the
   live cost exceeds the approved ceiling (§5.3 R-3).
6. The COO never references a fact the actor may not read, in any scope
   or Location (§6.2 R-7).
7. Every COO record names the real human actor and, under View As, the
   session — and approval/execution of consequential actions remains
   prohibited under View As (§6.4).
8. The audit chain from spend → content → approval → execution is
   complete and joinable (§10, §13.5).
9. No second gateway, insight table, approval lifecycle, audit ledger,
   entitlement identity or Location ACL exists anywhere in the slice
   (R-0, §17).
10. Blueprint §23's "one component, three authorization contexts" is
    structurally true (`CooScope` resolves all four questions in §5.6)
    even while `Agency`/`Platform` remain unactivated pending P-1.
11. **Product-level §23 compliance is claimed only after `19.P0`
    closes** (§3.6). No sub-slice below may be reported as satisfying
    §23 while an AI-authored SMS can be sent with no human in the loop.
12. `git diff --check` clean and a clean working tree at the end of each
    sub-slice's own commit.

## 15. Non-goals

- **A conversational / typed COO surface.** Deferred by merged contract
  D-5 (slice AI-4, with `coo_threads`/`coo_thread_messages` reserved);
  Blueprint §34's conflicting placement is escalated as P-2, not
  resolved by building a chat.
- **Any AI role in *selecting* the next best move.** The selector stays
  deterministic and source-controlled (R-1); changing its fixed order
  still requires an amendment to the merged contract, not configuration.
- **Tool/function calling at the provider layer.** Deliberately not
  added; its absence is a safety property of V1 (§6.1 S-2).
- **AI in the automations/workflow builder.** No authority requires it
  in V1.
- **Enabling the Opportunity engine.** `config/opportunity.php`'s
  default-false kill switch is not flipped by this slice.
- **Adding new Opportunity action keys.** `19.D` makes the executor
  registry-driven with exactly the one existing handler. Every new
  action key is its own separately authorized change with its own guard,
  cost and test obligations.
- **A per-actor, per-Location or per-feature AI budget UI.** Caps stay
  env-configured; no authority requires an administration surface in V1.
- **Rewriting `ai_usage_ledger`, `ai_usage_periods` or the admin usage
  surfaces.**
- **Amending merged contract §15.2 (one Business per prompt).** This
  contract proposes the amendment's shape (§5.6) and builds nothing
  under it.
- **Deciding P-3.** Whether agency prospecting auto-send is an
  authorized exception to §23 is the owner's call (§3.7).
- **Amending `V1-IMPLEMENTATION-CONTRACT-INDEX.md`.** It indexes
  Contracts 01–14 by design; adding rows for 16 and 19 is a separate
  documentation change.
- **Reopening the Workspace/Agency tenancy migration (Contracts 1–14)**
  in any way.

## 16. Merge prerequisites

- Whole slice: none from Contracts 01–14 beyond their already-merged
  state; the merged COO contract's AI-1/AI-2/AI-3/C-2/C-3/H-1…H-5 are
  already on `main` and are hard prerequisites in the sense that this
  contract assumes them.
- Ordering: `19.A` → `19.B` → `19.C`; `19.D` is independent of A–C and
  may proceed in parallel; `19.E` needs `19.D`; `19.F` needs A, B, D and
  E; `19.G` needs C and F; `19.H` needs A–G **and** decision P-1.
- `19.P0` is independent of every other sub-slice and needs decision
  P-3. It gates **acceptance claims**, not code merges (§14.11).
- Parallel-safety: `19.D`/`19.E` (Opportunity domain) and `19.A`/`19.B`/
  `19.C` (COO domain) touch disjoint files and are safe to run in two
  lanes; `19.F` is the join point and must not start until both lanes
  have merged.

## 17. Conflict map

| Other work | Shared file/table | Posture |
|---|---|---|
| Merged COO contract's unscheduled AI-4 (interactive COO) | `coo_insights`, `config/coo.php`, `AiUsageCategory`, the reserved `coo_threads`/`coo_thread_messages` names | Serialize. `19.G`'s bounded "Ask COO" affordance must not claim the reserved thread tables or pre-empt AI-4's design. If P-2 resolves in favour of §34, AI-4 supersedes `19.G`'s surface. |
| Merged COO contract §18's serial chain on `BusinessHomePresenter.php` / `business-home.blade.php` (H-1→…→C-2→AI-3) | both files | `19.C` and `19.G` extend the end of that chain; treat as a coordination point, never a concurrent edit. |
| `AttentionType` | C-2 is the declared sole writer | `19.C` reads only. Adding a case is a coordination point, not a free edit. |
| RFC-002 Opportunity engine work of any kind | `OpportunityManager`, `OpportunityActionRegistry`, `OpportunityActionExecutor`, `OpportunityController`, the four Opportunity tables | `19.D`/`19.E` are invasive here. Serialize against any other Opportunity work; do not run two lanes inside this subsystem. |
| RFC-005 usage billing / wallet work | `UsageWalletManager`, `EffectivePayerResolver`, pricing seams | `19.E` **reads** only; it introduces no wallet write. Parallel-safe as long as that stays true. |
| Contract 16 (Packages & Products) and Slice 17 | none | Parallel-safe. No shared file or table. |
| `CustomerMenuBuilder` / `ENTITLEMENT_GATED_FEATURES` | additive line if `19.G` adds nav | Low risk, same shape as every prior slice's nav addition. |
| `ViewAsRouteClassification` / `ViewAsProhibitedActions` | every new route in `19.G` | Additive, but mandatory in the same commit (R-8); the existing security suite is the enforcement. |
| `config/ai.php` (`category_routes`), `AiUsageCategory` | one case + one route per new surface (`19.C`, `19.F`) | Additive; never a sibling enum (merged contract §10.2). |
| `app/Jobs/AgencyProspectingRespondJob.php` | `19.P0` only | Isolated; no COO sub-slice touches it. |

## 18. Implementation prompts

Each sub-slice is handed to a fresh session independently, once
explicitly authorized. Every prompt assumes Contracts 01–14, the merged
COO contract's shipped slices, and every prerequisite sub-slice already
merged to `main`. Every prompt below inherits this repository's standing
rules: branch-only work, a disposable test database, no PR and no merge,
and no unverified claims.

### 18.P0 — Remediate or document the agency prospecting auto-send

```
You are implementing 19.P0 per docs/product/implementation-contracts/
19-AI-COO-V1-COMPLETION.md SS3.6, SS3.7 (P-3) and SS12.

Read SS3.6 first, then app/Jobs/AgencyProspectingRespondJob.php in full.

The owner's decision on P-3 will be supplied to you with this prompt. Do
not choose between the two options yourself.

If the decision is REMEDIATE: interpose an explicit human approval step
before the send at :195, reusing the existing approval lifecycle. Do not
invent a second approval mechanism. Do not change what the AI drafts.

If the decision is DOCUMENT-AS-EXCEPTION: change no application code;
amend the Blueprint to state the exception, its exact scope and why it
does not generalize.

Either way, add the regression test named in SS12.19.P0. Scope is this
one job file (plus the Blueprint for option b) and its tests. Touch
nothing in app/Library/Coo or app/Library/Opportunity.
```

### 18.A — Envelope, attribution, Location and scope columns

```
You are implementing Sub-slice 19.A per docs/product/implementation-
contracts/19-AI-COO-V1-COMPLETION.md SS5.2, SS5.6, SS5.7, SS8 and
SS12.19.A. This is plumbing only: no new route, no new customer-visible
behavior, no non-'business' scope value written anywhere.

Before writing code, read: the contract's SS3 and SS5; app/Library/Coo/
Insight/CooInsightFacts.php; CooInsightDisplayReader.php; the
coo_insights migration; app/Library/Navigation/CustomerContext.php.

Build: CooContextEnvelope and CooScope in app/Library/Coo/; one new
migration per SS8 (never edit the merged coo_insights migration); model
casts/relations; envelope assembly at the single existing COO entry
point, capturing the REAL actor and the View-As session id.

Also correct V1-AUTHORITY-TRACEABILITY-MATRIX.md row 22, which is stale.

Tests per SS12.19.A. Home's rendered output must be byte-identical
before and after.
```

### 18.B — Permission- and Location-aware fact composition

```
You are implementing Sub-slice 19.B per SS6.2 and SS12.19.B of the same
contract. 19.A is merged.

This sub-slice is the data-leak surface of the whole slice. Its
acceptance bar is rule R-7: the COO never references a fact the actor
may not read.

Make CooInsightFactsReader and CooInsightDisplayReader envelope-driven
and apply LocationAccessGuard. For every fact source, classify it IN
CODE as provably Business-wide-safe or excluded, with a comment naming
why. Do not include a source "because it is only an aggregate".

Write the adversarial matrix in SS12.19.B before the implementation.
Keep Home's bounded query budget flat; if the observed count changes,
re-pin it explicitly with a docblock naming each added read.
```

### 18.C — move_explanation

```
You are implementing Sub-slice 19.C per SS5.1 and SS12.19.C. 19.A and
19.B are merged.

Activate CooInsightKind::move_explanation. The AI explains the move the
deterministic selector already chose; it never chooses, reorders,
suppresses or invents one (rule R-1). Add the AiUsageCategory case and
its config/ai.php route; never name a model in domain code.

Render the explanation attached to the existing deterministic band. If
the explanation is absent, refused, expired or invalidated, the band
must render exactly as it does today.

Include the mutation test in SS12.19.C: a model returning a different
action must change nothing on screen.
```

### 18.D — Approval-lifecycle hardening

```
You are implementing Sub-slice 19.D per SS5.4, SS6.5, SS7 and
SS12.19.D. This is the highest-risk sub-slice in the contract: it edits
the only live approve-then-execute path in the product.

Read RFC-002 SS13.1, SS28, SS30, SS31 and all of app/Library/
Opportunity/OpportunityManager.php before changing anything.

Add the SS5.4(2) guard chain at approval AND at every execution
attempt, approval expiry, the retry re-approval rule, a real
initiated_by_type, the paid_effect registry flag, kill-switch symmetry,
started_at, and CustomerContext-based resolution in the controller.
Replace SUPPORTED_ACTION_KEY with a registry-driven handler map
containing EXACTLY the one existing handler -- adding an action key is
explicitly out of scope.

Preserve both hash-mismatch guards unchanged. The existing add_phone
action must still execute end-to-end identically. Write the adversarial
approval matrix in SS12.19.D first.
```

### 18.E — Customer-facing action cost estimate

```
You are implementing Sub-slice 19.E per SS5.3 and SS12.19.E. 19.D is
merged.

Build ActionCostEstimate and a DETERMINISTIC estimator over RFC-005's
existing pricing seams and EffectivePayerResolver. No cost value may
originate from model output (rule R-4). No wallet write belongs in this
sub-slice -- read only.

Snapshot the estimate on the approval record as an approved ceiling.
At execution, inside the locked section, recompute and refuse if the
cost exceeds the ceiling, the price_version changed, or the payer
changed (rule R-3).

Show payer, amount/units, basis and wallet sufficiency BEFORE approval.
Tests per SS12.19.E, including the four refusal paths.
```

### 18.F — COO drafting

```
You are implementing Sub-slice 19.F per SS5.5, SS6.5 and SS12.19.F.
19.A, 19.B, 19.D and 19.E are merged.

Create coo_drafts per SS5.5 and CooDraftGenerator through AiGateway on
AiLane::Interactive with its own AiUsageCategory case. A drafting prompt
receives role placeholders only -- never a contact name, phone number
or email address (rule R-6); the merge happens deterministically in the
consuming feature at consumption time.

A draft is inert (rule R-5): nothing consumes it automatically, and it
reaches a send path only as human-edited content through that path's own
authorization and, when paid, 19.E's estimate.

Write CooActionBoundaryArchitectureTest (rule R-10) FIRST, in the style
of tests/Feature/Security/AiGatewayBypassArchitectureTest.php: no class
under app/Library/Coo/**, no COO controller and no COO job may reference
a message sender, a publish/automation-activation API, a wallet debit, a
billing/payer mutation or a permission mutation.
```

### 18.G — Ask COO, contextual reach, durable limiting, provider robustness

```
You are implementing Sub-slice 19.G per SS6.4, SS11 and SS12.19.G. 19.C
and 19.F are merged.

Add the Blueprint SS8 "Ask COO" affordance on Home, bound to exactly
what Home is already showing, plus contextual explain/draft on a small
ENUMERATED set of surfaces -- list them in the PR description. This is
not a chat surface; decision P-2 is unresolved and coo_threads /
coo_thread_messages are reserved for the deferred AI-4. Do not claim
those names.

Classify every new route in ViewAsRouteClassification and
ViewAsProhibitedActions in the same commit (rule R-8): read/explain/
draft permitted under View As with attribution; approval and execution
prohibited.

Replace CooInsightExplainLimiter's cache-only claim with a durable,
per-actor, per-subject, auditable limit enforced in the generator as
well as the controller. Set an explicit provider timeout on
OpenAiCompletionClient. Repair the two random idempotency keys named in
SS3.3(14). Keep tries=1.
```

### 18.H — Agency and Platform scope (BLOCKED)

```
DO NOT START. Sub-slice 19.H is blocked on owner decision P-1 (SS3.7):
an amendment to UNIFIED-BUSINESS-HOME-AND-COO-DECISION-ENGINE-CONTRACT
SS15.2, which today forbids any cross-Business prompt including for the
Agency Account Home.

If and when P-1 is decided in writing and 19.A-19.G are merged, this
prompt is reissued with the amendment's exact text attached. Until then
no Agency-scoped or Platform-scoped COO code may be written, and no
non-'business' CooScope value may be persisted.
```
