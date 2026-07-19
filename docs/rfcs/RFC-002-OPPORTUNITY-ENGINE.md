# RFC-002 — Opportunity Engine Foundation

**Status:** Ready for implementation
**Version:** 1.0
**Priority:** P1
**Target framework:** Laravel 12 / PHP 8.2
**Architecture constraint:** Extend the existing Ultimate SMS controller → repository → library → model structure. Do not introduce a new generic service-layer convention.
**Depends on:** RFC-001 Business Core (tagged `rfc-001-business-core`) — read-only dependency, not modified by this RFC
**Enables:** SEO, Content, Sales, Reputation, and Website workers (future RFCs); RFC-003 AI Workforce

---

## 1. Status

Ready for implementation. This RFC has been through an iterative architecture review; every decision below is final for Milestones 1–6. Changes after implementation begins require a follow-up RFC or an explicit correction pass, matching the process RFC-001 used.

---

## 2. Summary

RFC-002 introduces the **Opportunity Engine**: a durable, tenant-scoped domain for "things this Business should do," populated by pluggable workers through one trusted, idempotent staging-and-finalization protocol, scored by a deterministic and fully explainable formula, and surfaced to the customer as a prioritized, evidence-backed work queue. Workers never write live data directly — every worker run stages candidates in an isolated table and only a single atomic finalization step can create or update customer-visible Opportunities. Every claim is evidence-backed; every Business-data mutation requires explicit customer approval and flows through RFC-001's existing `BusinessManager`; every workflow and freshness change is durably audited. RFC-002 implements the domain, the orchestration, and one deterministic producer (`business_advisor`, reusing RFC-001's profile-completeness logic). It does not implement the SEO, Content, Sales, Reputation, or Website workers.

---

## 3. Motivation

RFC-001 gave Ultimate SMS a durable Business identity and one deterministic completeness analysis, but no durable, ongoing "what should this customer do next" record. Without a shared Opportunity domain, every future worker (SEO, content, sales, reputation, website) would independently invent its own storage, its own priority notion, and its own idea of what counts as "done" — producing conflicting customer-facing claims, duplicated detection logic, and no single trustworthy work queue for the dashboard. The Opportunity Engine establishes that one shared aggregate now, before any of those workers exist, so they all plug into the same contract.

---

## 4. Objectives

- Give every Business one durable, prioritized, evidence-backed set of Opportunities.
- Let any future worker produce or update Opportunities through one stable, validated contract.
- Guarantee a worker can never corrupt or bypass another worker's Opportunities, another tenant's data, or the customer's own workflow decisions.
- Make priority fully deterministic and explainable — never a bare AI-generated number.
- Make every Business-data mutation require explicit customer approval, executed only through RFC-001's approved application methods.
- Keep the whole engine safe to release disabled, safe under retries, and safe under partial failure.

---

## 5. Scope

RFC-002 implements:

- The `opportunities`, `opportunity_runs`, `opportunity_run_candidates`, `opportunity_action_executions`, and `opportunity_transitions` tables, models, and repositories.
- `OpportunityManager`: the run protocol (begin/stage/finalize/fail), fingerprinting, deterministic scoring, workflow/freshness/recurrence rules, and transition auditing.
- The worker-facing producer contract, and one real implementation of it: a deterministic `business_advisor` producer reusing RFC-001's `InitialBusinessSnapshotBuilder`.
- The customer Opportunity work queue (list, approve, execute, snooze, dismiss, complete).
- Admin inspection of Opportunities, runs, staged candidates, transitions, and executions.
- `config/opportunity.php`, `.env.example` entries, deployment documentation, and a regression suite.

---

## 6. Non-goals

RFC-002 does not implement:

- The SEO, Content, Sales, Reputation, or Website workers.
- Any external crawling, scraping, or third-party API integration.
- Google Business Profile, Google Analytics, or Search Console integration.
- `external_verified` completion — the contract is defined; no verifier exists.
- Automatic execution of any Business-data mutation without explicit customer approval.
- A generalized recurrence policy beyond the one narrow rule defined in §19.
- Admin creation or deletion of Opportunities.
- A new role/permission system, or a new generic service layer.

---

## 7. Terminology

| Term | Meaning |
|---|---|
| Opportunity | A durable, evidence-backed record of one thing a Business could do, owned by exactly one worker. |
| Worker | A producer of Opportunity candidates, identified by a fixed `worker_key`. Only `business_advisor` exists in RFC-002. |
| Run | One execution attempt by one worker for one Business (`opportunity_runs`), bounded by `beginRun()`/`finalizeSuccessfulRun()`/`failRun()`. |
| Candidate | A validated, run-scoped, not-yet-live proposed Opportunity (`opportunity_run_candidates`), produced by `stageCandidate()`. |
| Fingerprint | The trusted SHA-256 identity of an Opportunity, computed by application code from canonical inputs. |
| Freshness | Whether an Opportunity was reaffirmed by the most recent successful run for its worker (`current`/`stale`) — independent of workflow status. |
| Workflow status | The customer/admin-controlled disposition of an Opportunity (`open`, `awaiting_approval`, `in_progress`, `snoozed`, `completed`, `dismissed`). |
| Evidence | The structured, validated facts supporting an Opportunity's claim — never free narrative. |
| Recommended action | The single structured, registry-owned action a customer may approve to resolve an Opportunity. |
| Execution | One attempt to carry out an approved recommended action (`opportunity_action_executions`), bound to an exact action revision. |
| Transition | One durably audited change to an Opportunity's workflow status or freshness (`opportunity_transitions`). |
| Occurrence | A counter incremented only when a completed Opportunity legitimately recurs (§19). |

---

## 8. Existing-system integration

RFC-002 is additive to RFC-001 and modifies none of it. It reuses, unchanged:

- The `Business` aggregate and its tenant convention (`businesses.customer_id → users.id`).
- `BusinessManager` and the RFC-001 repositories — the *only* code paths permitted to mutate `Business`/`BusinessLocation`/`BusinessService`.
- `InitialBusinessSnapshotBuilder`'s fact/finding computation, called as a library dependency by the Milestone 3 producer. `customer_onboardings.analysis_payload`, `OnboardingActionExecutor`, and the onboarding wizard are untouched — RFC-002's queue is a separate, parallel customer surface, not a replacement for onboarding's own results step.
- `App\Library\Traits\HasUid` for every new table's `uid` column.
- `ShouldDispatchAfterCommit` (events) and `ShouldQueueAfterCommit` (jobs) — the same real after-commit mechanisms, not a reimplementation.
- The `config('x.y.z', $safeDefault)` pattern, so a missing `config/opportunity.php` key never crashes anything.
- The existing admin authorization stack: `can:access backend`, `EnsureUserIsAdministrator`, and `config/permissions.php` — extended with two new keys (`view opportunities`, `edit opportunities`), not a new mechanism.
- The existing `Controller → Repository → app/Library → Model` structure, with the same divide RFC-001 established: repositories are pure data access; all invariants, transactions, and orchestration live in `app/Library/Opportunity/OpportunityManager`.

---

## 9. Architectural principles

- Workers are untrusted. Every worker output is validated before it can affect anything, and can never affect live data directly.
- Priority is always deterministic, versioned, and reconstructable — never an opaque AI float.
- Every Business-data mutation requires an explicit, auditable customer approval and executes only through RFC-001's approved methods.
- A successful run applies atomically, in full, or not at all.
- A failed or abandoned run has zero customer-visible effect.
- Customer workflow decisions (`dismissed`, `completed`, `snoozed`, an in-flight approval) are protected from being silently overwritten by worker reruns.
- Every workflow and freshness change is durably audited in a table, not only via a domain event.
- Configuration carries only trusted scalars. Class names, routes, callbacks, handlers, and validators are always fixed, source-controlled mappings — never environment-, request-, database-, or AI-supplied.

---

## 10. Tenant ownership and authorization

Tenant ownership derives exactly as it does throughout RFC-001:

```text
opportunities.business_id → businesses.customer_id → users.id
```

No Opportunity table stores `customer_id` directly. `opportunity_run_candidates`, `opportunity_transitions`, and `opportunity_action_executions` derive tenancy transitively through their parent `opportunity_runs`/`opportunities` row.

**Customer**: may list/view/act on Opportunities only for their own primary Business, resolved the same way `Customer\BusinessController` resolves it today (never from a request-supplied identifier alone). May never view or act on another tenant's Opportunity, run, candidate, transition, or execution.

**Admin**: reaches the admin Opportunity surface only after passing *both* of the boundaries RFC-001 Milestone 6 established — the route-group `can:access backend` gate *and* the independent `EnsureUserIsAdministrator` account-type middleware — and then the feature-specific gate: `view opportunities` (list/inspect) or `edit opportunities` (dismiss/reopen/snooze on the customer's behalf). Admin access is intentionally cross-tenant once authorized, exactly as it is for admin Business access.

---

## 11. Opportunity domain model

```text
Business
├── hasMany OpportunityRun
└── hasMany Opportunity

OpportunityRun
├── belongsTo Business
└── hasMany OpportunityRunCandidate

Opportunity
├── belongsTo Business
├── belongsTo OpportunityRun (lastConfirmedRun, nullable)
├── hasMany OpportunityTransition
└── hasMany OpportunityActionExecution

OpportunityTransition
├── belongsTo Opportunity
├── belongsTo OpportunityRun (nullable, provenance)
└── belongsTo OpportunityActionExecution (nullable, provenance)

OpportunityActionExecution
└── belongsTo Opportunity
```

`Opportunity` is the aggregate root for its own workflow/freshness state, evidence, and recommended action. `OpportunityRun` is the aggregate root for one worker execution attempt and its staged candidates. Writes spanning both (finalization) are coordinated entirely inside `OpportunityManager`.

---

## 12. Worker registry

Worker keys are a fixed, closed PHP enum — never environment- or database-configurable:

```php
enum OpportunityWorkerKey: string
{
    case BusinessAdvisor = 'business_advisor';
    case Seo = 'seo';
    case Content = 'content';
    case Sales = 'sales';
    case Reputation = 'reputation';
    case Website = 'website';
}
```

Only `BusinessAdvisor` is implemented by RFC-002 (§39). The other five cases exist so the fingerprint namespace, the run protocol, and the conformance suite (Milestone 6) are proven against the full registry shape before any of those workers are built.

---

## 13. Action and opportunity-type registries

Two closed, source-controlled registries — neither a database table, neither ever resolved from a string at runtime beyond a fixed `match()`/array lookup, neither environment- or request-configurable.

### 13.1 Action registry

Maps `action_key` to trusted execution metadata:

```php
final class OpportunityActionRegistry
{
    private const DEFINITIONS = [
        'add_phone' => [
            'schema_version' => 1,
            'mutates_business_data' => true,
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'validator' => AddPhoneActionValidator::class,
            'handler' => AddPhoneActionHandler::class,
            'system_verification_method' => AddPhoneActionHandler::class . '@verify',
        ],
        // ...one entry per allowlisted action, mirroring RFC-001 M5's
        // OnboardingActionExecutor allowlist for the business_advisor actions.
    ];
}
```

An execution request may supply only `action_key` (implicitly, via the Opportunity it targets — never chosen by the request, §30) and registry-validated `parameters`. Everything else — `schema_version`, `mutates_business_data`, `approval_required`, `completion_policy`, `validator`, `handler`, `system_verification_method` — is registry-owned and fixed. An unrecognized `action_key` is rejected wherever it is encountered (staging, display, execution). Two invariants are enforced by a Milestone 6 conformance test against the registry itself: every entry with `mutates_business_data=true` has `approval_required=true`, and every `approval_required=false` entry is trusted and non-mutating.

### 13.2 Opportunity-type registry

Maps `(worker_key, type)` to trusted content and validation metadata — this is what makes "unsupported narrative claims are rejected" (§45) enforceable, rather than aspirational:

```php
final class OpportunityTypeRegistry
{
    private const DEFINITIONS = [
        'business_advisor' => [
            'missing_phone' => [
                'title_template' => 'Add your business phone number',
                'summary_template' => 'Customers and future platform modules need a reliable way to contact the business.',
                'allowed_evidence_source_types' => ['business_profile'],
                'allowed_evidence_fact_keys' => ['phone_blank'],
                'required_evidence_fact_keys' => ['phone_blank'],
                'evidence_summary_templates' => [
                    'phone_blank' => 'The business phone number is not set.',
                ],
                'action_key' => 'add_phone', // nullable — a type may have no action at all
                'context_validator' => NullContextValidator::class,
                'allowed_relevant_goal_keys' => [],
            ],
            // ...one entry per business_advisor type — the full 11-type
            // mapping is locked in §39.
        ],
    ];
}
```

A worker/AI candidate may supply only: validated **template parameters** (where a type's template accepts them — none of RFC-002's 11 `business_advisor` types do; their `title_template`/`summary_template` are fixed constant strings, exactly RFC-001's existing finding text), the **fact data** for each evidence item (`fact_key`, `source_type`, `source_identifier`, `observed_value`, and the structural timestamps/URLs/hash from §27), and **not** the evidence item's own `summary` text. It may never supply final rendered title/summary text (Opportunity-level or evidence-item-level), arbitrary HTML, or a `fact_key`/`source_type`/`action_key`/relevant-goal-key outside this type's own allowlist. `OpportunityManager` renders `title`/`summary` from the registry template (plus any validated parameters) *and* renders each evidence item's `summary` from that `fact_key`'s registered `evidence_summary_templates` entry (§27) — every persisted `title`/`summary` column, at both the Opportunity level (§15.2, §15.3) and inside each `evidence` array entry, is always registry output, never worker-supplied free text. A candidate payload that attempts to supply or override an evidence item's final `summary` is rejected at staging, not silently overwritten and kept. An unrecognized `(worker_key, type)` pair, or a `fact_key` with no registered `evidence_summary_templates` entry, is rejected at staging. A future worker's types and fact definitions require a source-code registry addition here plus new Milestone 6 conformance tests — never a database-, environment-, request-, or AI-configurable addition.

---

## 14. Configuration

`config/opportunity.php`:

```php
return [
    'enabled' => env('OPPORTUNITY_ENGINE_ENABLED', false),
    'queue' => env('OPPORTUNITY_ENGINE_QUEUE', 'default'),
    'run_timeout_minutes' => env('OPPORTUNITY_RUN_TIMEOUT_MINUTES', 30),
    'max_candidates_per_run' => env('OPPORTUNITY_MAX_CANDIDATES_PER_RUN', 100),
    'snooze_sweep_minutes' => env('OPPORTUNITY_SNOOZE_SWEEP_MINUTES', 15),
    'fingerprint_version' => 1,
    'scoring_version' => 1,
];
```

| Variable | Default | Meaning |
|---|---|---|
| `OPPORTUNITY_ENGINE_ENABLED` | `false` | Master switch; disabled means no queue UI, no runs, no jobs. |
| `OPPORTUNITY_ENGINE_QUEUE` | `default` | Queue name for producer jobs. |
| `OPPORTUNITY_RUN_TIMEOUT_MINUTES` | `30` | Heartbeat age past which a `running` run is considered abandoned. |
| `OPPORTUNITY_MAX_CANDIDATES_PER_RUN` | `100` | Hard cap on distinct fingerprints staged per run. |
| `OPPORTUNITY_SNOOZE_SWEEP_MINUTES` | `15` | Scheduler cadence for the expired-snooze sweep. |

`fingerprint_version` and `scoring_version` are present in the config file for single-source-of-truth reading, but are semantic versions of trusted algorithms — they are **not** meant to be changed via `.env` in normal operation; changing either is a code change (a new canonicalization or scoring formula version), documented in this RFC, not an environment toggle. No environment variable, database record, or request value may ever supply a class name, route, callback, validator, or handler — those are fixed in `OpportunityWorkerKey` (§12) and `OpportunityActionRegistry` (§13) only.

---

## 15. Persistence model

Five tables. `opportunities` is the only aggregate root customers and admins interact with directly; the other four are its supporting run/audit/execution machinery.

The hybrid schema decision (unchanged from the approved architecture): **Opportunities are relational**, one row per opportunity — required for fingerprint-unique upserts, workflow/freshness filtering, and `ORDER BY priority_score` in the work queue, none of which a JSON blob supports efficiently. **Evidence and the recommended action remain validated JSON columns** on `opportunities` and `opportunity_run_candidates` — each is bounded, always read and written as a unit with its owning row, and nothing in Milestones 1–6 needs to query evidence across opportunities. If a later RFC needs cross-opportunity evidence queries, that is an additive normalization at that time, not a speculative one now.

### 15.1 `opportunity_runs`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `uid` | uuid | no | auto | `HasUid`; doubles as the external run token |
| `business_id` | bigint unsigned FK | no | — | |
| `worker_key` | varchar(50) | no | — | `OpportunityWorkerKey` |
| `producer_version` | unsigned int | no | — | Worker code version that produced this run |
| `status` | varchar(16) | no | `running` | `running` \| `succeeded` \| `failed` |
| `started_at` | timestamp | no | — | |
| `heartbeat_at` | timestamp | no | — | Refreshed by every `stageCandidate()` call |
| `completed_at` | timestamp | yes | null | Set once, by `finalizeSuccessfulRun()` or `failRun()` |
| `abandoned_at` | timestamp | yes | null | Set only by the `beginRun()` abandonment path |
| `reason_code` | varchar(64) | yes | null | e.g. `heartbeat_timeout` |
| `safe_error_summary` | varchar(255) | yes | null | Safe text only — never a stack trace |
| timestamps | — | no | — | |

Foreign keys: `business_id` → `businesses.id`, cascade delete.
Uniqueness: `uid` unique. There is no separate database uniqueness constraint enforcing "one running run per business/worker" — that invariant is enforced entirely by `beginRun()`'s transactional row lock (§25), since MySQL has no partial/conditional unique index.
Indexes: `business_id`; `(business_id, worker_key, status)`; `(business_id, worker_key, completed_at)`; `(status, heartbeat_at)`.
Deletion behavior: cascades from `businesses`; otherwise never deleted by application code.
Tenant derivation: `business_id → businesses.customer_id → users.id`.
Immutable after creation: `business_id`, `worker_key`, `producer_version`, `started_at`.
Allowed mutation paths: `status`, `heartbeat_at`, `completed_at`, `abandoned_at`, `reason_code`, `safe_error_summary` — only via `beginRun()` (abandonment), `stageCandidate()` (heartbeat only), `finalizeSuccessfulRun()`, and `failRun()`.

### 15.2 `opportunities`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `uid` | uuid | no | auto | `HasUid` |
| `business_id` | bigint unsigned FK | no | — | |
| `worker_key` | varchar(50) | no | — | Identity field |
| `type` | varchar(100) | no | — | Worker-defined type/rule name; identity field |
| `fingerprint_version` | unsigned tinyint | no | — | Identity field |
| `fingerprint` | char(64) | no | — | SHA-256 hex; identity field |
| `context_key` | varchar(191) | yes | null | Bounded, debug/inspection only; identity field |
| `title` | varchar(255) | no | — | Rendered by `OpportunityManager` from the type registry's trusted template (§13) — never worker-supplied free text |
| `summary` | text | no | — | Rendered by `OpportunityManager` from the type registry's trusted template (§13) — never worker-supplied free text |
| `status` | varchar(32) | no | `open` | Workflow status (§17) |
| `freshness` | varchar(16) | no | `current` | `current` \| `stale` (§18) |
| `impact` | tinyint unsigned | no | — | 0–5 |
| `urgency` | tinyint unsigned | no | — | 0–5 |
| `effort` | tinyint unsigned | no | — | 0–5, 5 = highest effort |
| `confidence` | decimal(3,2) | no | — | 0.00–1.00 |
| `goal_relevance_rank` | tinyint unsigned | no | — | 0–5, manager-computed |
| `evidence_freshness_rank` | tinyint unsigned | no | — | 0–5, manager-computed (§34) |
| `priority_score` | tinyint unsigned | no | — | 0–100, manager-computed |
| `scoring_version` | tinyint unsigned | no | — | |
| `scored_at` | timestamp | no | — | Evaluation instant for evidence freshness (§34); persisted before scoring |
| `evidence` | json | **no** | — | Validated, non-empty, bounded (§27) |
| `recommended_action` | json | yes | null | Validated, registry-constructed (§13, §28); null only for a type with no defined action |
| `recommended_action_hash` | char(64) | yes | null | SHA-256 of the constructed action; manager-computed |
| `action_schema_version` | tinyint unsigned | yes | null | From the registry entry |
| `occurrence_number` | unsigned int | no | `1` | Increments only on legitimate recurrence (§19) |
| `last_confirmed_run_id` | bigint unsigned FK | yes | null | See below |
| `last_confirmed_at` | timestamp | no | — | |
| `first_detected_at` | timestamp | no | — | |
| `snoozed_until` | timestamp | yes | null | |
| `completed_at` | timestamp | yes | null | |
| `dismissed_at` | timestamp | yes | null | |
| `stale_at` | timestamp | yes | null | |
| timestamps | — | no | — | |

Foreign keys: `business_id` → `businesses.id`, cascade delete; `last_confirmed_run_id` → `opportunity_runs.id`, **`ON DELETE SET NULL`**. There is no `resolved_run_id` column — completion provenance lives entirely in `opportunity_action_executions` and `opportunity_transitions` (§41), not on the Opportunity itself.
Uniqueness: `uid` unique; `fingerprint` unique.
Indexes: `business_id`; `(business_id, status)`; `(business_id, freshness, status, priority_score)` (default queue); `(business_id, worker_key)`.
Deletion behavior: cascades from `businesses`. `last_confirmed_run_id` is `SET NULL`, never `RESTRICT`, specifically so it can never block or cycle against the `businesses` cascade.
Tenant derivation: `business_id → businesses.customer_id → users.id`.
Immutable after creation: `business_id`, `worker_key`, `type`, `fingerprint_version`, `fingerprint`, `context_key`, `first_detected_at`.
Allowed mutation paths: only inside `OpportunityManager` — `finalizeSuccessfulRun()` (creation, evidence/scoring/action refresh, freshness, recurrence reopen), `OpportunityActionExecutor`/`OpportunityManager` workflow methods (approval routing, execution-triggered completion, dismiss, snooze, reopen), and the snooze-sweep command (`snoozed → open` only). Never written by a controller, request, or worker directly.

### 15.3 `opportunity_run_candidates`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `uid` | uuid | no | auto | `HasUid` |
| `opportunity_run_id` | bigint unsigned FK | no | — | |
| `type` | varchar(100) | no | — | |
| `fingerprint_version` | unsigned tinyint | no | — | |
| `fingerprint` | char(64) | no | — | |
| `context_key` | varchar(191) | yes | null | |
| `title` | varchar(255) | no | — | Registry-rendered (§13) |
| `summary` | text | no | — | Registry-rendered (§13) |
| `impact`/`urgency`/`effort` | tinyint unsigned each | no | — | 0–5 |
| `confidence` | decimal(3,2) | no | — | |
| `goal_relevance_rank` | tinyint unsigned | no | — | |
| `evidence_freshness_rank` | tinyint unsigned | no | — | |
| `priority_score` | tinyint unsigned | no | — | |
| `scoring_version` | tinyint unsigned | no | — | |
| `scored_at` | timestamp | no | — | |
| `evidence` | json | **no** | — | Validated, non-empty, bounded (§27) |
| `recommended_action` | json | yes | null | |
| `recommended_action_hash` | char(64) | yes | null | |
| `action_schema_version` | tinyint unsigned | yes | null | |
| `created_at` / `updated_at` | timestamp | no | — | |

Foreign keys: `opportunity_run_id` → `opportunity_runs.id`, cascade delete.
Uniqueness: `uid` unique; **`unique(opportunity_run_id, fingerprint)`** — a repeat `stageCandidate()` call for the same fingerprint within the same run updates this row (`updated_at` moves).
Indexes: the unique composite above serves all lookups; no additional index required.
Deletion behavior: cascades from `opportunity_runs` (→ `businesses`) only — no standalone deletion path; retained for inspection until that cascade.
Tenant derivation: `opportunity_run_id → opportunity_runs.business_id → businesses.customer_id → users.id`.
Immutable after creation: `opportunity_run_id`, `fingerprint_version`, `fingerprint`, `context_key`, `type`.
Allowed mutation paths: only `stageCandidate()`, via the unique-key upsert.

### 15.4 `opportunity_action_executions`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `uid` | uuid | no | auto | `HasUid` |
| `opportunity_id` | bigint unsigned FK | no | — | |
| `action_key` | varchar(100) | no | — | |
| `recommended_action_hash` | char(64) | no | — | Bound at approval time |
| `action_schema_version` | tinyint unsigned | no | — | Bound at approval time |
| `occurrence_number` | unsigned int | no | — | Bound at approval time |
| `attempt_number` | unsigned int | no | `1` | Increments only on explicit retry |
| `idempotency_key` | char(64) | no | — | Server-derived (§31) |
| `status` | varchar(16) | no | `pending` | `pending` \| `running` \| `succeeded` \| `failed` |
| `initiated_by_user_id` | bigint unsigned FK | no | — | |
| `initiated_by_type` | varchar(16) | no | — | `customer` \| `admin` |
| `completion_policy` | varchar(32) | no | — | Copied from the bound action at approval time — `varchar(32)` because `customer_attested`/`external_verified` exceed 16 characters |
| `safe_result_summary` | varchar(255) | yes | null | |
| `safe_error_summary` | varchar(255) | yes | null | |
| `started_at` | timestamp | yes | null | |
| `completed_at` | timestamp | yes | null | |
| timestamps | — | no | — | |

Foreign keys: `opportunity_id` → `opportunities.id`, cascade delete; `initiated_by_user_id` → `users.id`.
Uniqueness: `uid` unique; `idempotency_key` unique — the latter sufficient alone (no separate composite constraint needed), since the key is deterministically derived from `(opportunity_id, occurrence_number, recommended_action_hash, attempt_number)`.
Indexes: `opportunity_id`; `(opportunity_id, action_key)`; `status`.
Deletion behavior: cascades from `opportunities`.
Tenant derivation: `opportunity_id → opportunities.business_id → businesses.customer_id → users.id`.
Immutable after creation: everything except `status`, `safe_result_summary`, `safe_error_summary`, `completed_at`, `started_at` (set once on `pending→running`).
Allowed mutation paths: only `OpportunityActionExecutor`, forward-only `pending → running → {succeeded, failed}`.

### 15.5 `opportunity_transitions`

Append-only. Both workflow and freshness transitions share this table via `category`.

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `opportunity_id` | bigint unsigned FK | no | — | |
| `category` | varchar(16) | no | — | `workflow` \| `freshness` |
| `from_status` | varchar(32) | yes | null | Null only for the initial creation transition |
| `to_status` | varchar(32) | no | — | |
| `actor_type` | varchar(16) | no | — | `customer` \| `admin` \| `worker` \| `system` |
| `actor_user_id` | bigint unsigned FK | yes | null | |
| `opportunity_run_id` | bigint unsigned FK | yes | null | Provenance for worker/freshness/recurrence transitions |
| `action_execution_id` | bigint unsigned FK | yes | null | Provenance for approval/execution/completion transitions |
| `reason_code` | varchar(64) | no | — | e.g. `recurrence_detected`, `action_revised`, `customer_attested`, `snooze_expired` |
| `safe_note` | varchar(255) | yes | null | Safe, customer/admin-facing text only |
| `created_at` | timestamp | no | — | No `updated_at` — rows are never modified |

Foreign keys: `opportunity_id` → `opportunities.id`, cascade delete; `actor_user_id` → `users.id`, **`ON DELETE SET NULL`**; `opportunity_run_id` → `opportunity_runs.id`, **`ON DELETE SET NULL`**; `action_execution_id` → `opportunity_action_executions.id`, **`ON DELETE SET NULL`**.
Uniqueness: none.
Indexes: `opportunity_id`; `(opportunity_id, created_at)`.
Deletion behavior: cascades from `opportunities`.
Tenant derivation: `opportunity_id → opportunities.business_id → businesses.customer_id → users.id`.
Immutable after creation: all columns — write-once, never updated.
Allowed mutation paths: insert-only, from `OpportunityManager`/`OpportunityActionExecutor`, always in the same transaction as the state change it records.

---

## 16. Migration order

Dependency-ordered, not list-ordered:

1. `opportunity_runs` (needs `businesses`)
2. `opportunities` (needs `businesses`, `opportunity_runs`)
3. `opportunity_run_candidates` (needs `opportunity_runs`)
4. `opportunity_action_executions` (needs `opportunities`, `users`)
5. `opportunity_transitions` (needs `opportunities`, `users`, `opportunity_runs`, `opportunity_action_executions`)

Rollback reverses this order.

---

## 17. Opportunity workflow lifecycle

States: `open`, `awaiting_approval`, `in_progress`, `snoozed`, `completed`, `dismissed`. No `failed` state exists.

| Transition | Actor | Notes |
|---|---|---|
| `(created)` → `open` | worker | Only inside `finalizeSuccessfulRun()`, new fingerprint |
| `open` → `awaiting_approval` | customer | Requests an `approval_required=true` action |
| `open` → `in_progress` | customer | Requests an `approval_required=false` action |
| `awaiting_approval` → `in_progress` | customer | Explicit confirmation; execution created, bound to current revision |
| `awaiting_approval` → `open` | worker | Action definition changed underneath a pending approval (`action_revised`) |
| `awaiting_approval` → `snoozed` \| `dismissed` | customer | |
| `in_progress` → `completed` | system | `system_verified` execution success + confirmed recheck (§32) |
| `open` → `completed` | customer | `customer_attested` explicit self-report — only from `open`/`freshness=current`, never from any other status (§32) |
| `in_progress` → `open` | system | Execution failed, or a pre-invocation revision/registry/ownership mismatch; reverts, never becomes "failed" (§30) |
| `open` → `snoozed` \| `dismissed` | customer | |
| `snoozed` → `open` | system | Scheduled sweep only (§33), or explicit customer un-snooze |
| `snoozed` → `dismissed` | customer | |
| `dismissed` → `open` | customer or admin | Explicit reopen only — never automatic |
| `completed` → `open` | customer/admin (explicit) or worker (recurrence, §19) | |

Producers may only ever perform the initial `(created) → open` transition and the approved recurrence `completed → open` transition (§19). A producer never writes `awaiting_approval` or `in_progress`.

---

## 18. Freshness lifecycle

States: `current`, `stale`. Fully orthogonal to workflow status — every workflow state may co-occur with either freshness value.

| Transition | Actor | Notes |
|---|---|---|
| `current` → `stale` | worker (`finalizeSuccessfulRun`) | Only for Opportunities not present among a run's applied candidates, scoped to the same `business_id`/`worker_key` |
| `stale` → `current` | worker (`finalizeSuccessfulRun`) | A candidate matches an existing stale Opportunity's fingerprint |

---

## 19. Recurrence rules

A `completed` Opportunity automatically reopens, inside `finalizeSuccessfulRun()`'s per-candidate application, only when **all** hold:

1. It was previously verified completed (`status === 'completed'`).
2. A later successful run already failed to reaffirm it, causing `current → stale` (this is the freshness column itself — no separate tracking is needed).
3. A subsequent successful run reports the same fingerprint again (a genuine candidate for it exists in this run).
4. That candidate's validated evidence affirms the condition currently exists (true by construction — it was staged and validated this run).

When all hold: `occurrence_number += 1`, `status = 'open'`, `completed_at = null`, `freshness = 'current'`, `last_confirmed_run_id`/`last_confirmed_at` updated, and a `workflow` transition is written with `from_status='completed'`, `to_status='open'`, `actor_type='worker'`, `reason_code='recurrence_detected'`, `opportunity_run_id` set.

A **continuously reaffirmed** completed Opportunity never satisfies condition 2 and therefore never reopens automatically. A **dismissed** Opportunity is excluded from this rule entirely — it never auto-reopens under any condition, even after disappearing and reappearing.

---

## 20. Worker-run lifecycle

`opportunity_runs.status`: `running` → `succeeded` | `failed`. Both `succeeded` and `failed` are terminal.

- `running` is set only by `beginRun()`.
- `succeeded` is set only by `finalizeSuccessfulRun()`, and only after every candidate has been applied and the staleness sweep has completed, all in the same transaction.
- `failed` is set by `failRun()` (explicit worker/job failure) or by `beginRun()`'s abandonment path (heartbeat timeout, on a prior run, before creating a replacement).

---

## 21. Candidate-staging protocol

```
stageCandidate(OpportunityRun $run, OpportunityCandidateData $data): OpportunityRunCandidate

  DB::transaction(function () use ($run, $data) {
      $locked = OpportunityRun::whereKey($run->id)->lockForUpdate()->first();

      if ($locked === null || $locked->status !== 'running') {
          throw RunNotActiveException;
      }
      if ($locked->heartbeat_at < now()->subMinutes(config('opportunity.run_timeout_minutes'))) {
          throw RunAbandonedException;
      }

      // resolve the type definition for ($locked->worker_key, $data->type) from
      // OpportunityTypeRegistry (§13) — reject if the (worker_key, type) pair
      // is not registered

      // enforce max_candidates_per_run against distinct fingerprints already
      // staged for this run (re-staging an existing fingerprint is an update,
      // not a new candidate, and does not count against the cap)

      // validate impact/urgency/effort/confidence/relevant-goal-keys — reject,
      // never clamp (§34)
      // validate evidence: non-empty, each item within the §27 bounds and
      // fact_key/source_type allowlist for this type, required fact_keys present
      // canonicalize + SHA-256 the fingerprint using $locked->worker_key,
      // canonical JSON (§26)
      // render title/summary from the type registry's trusted template (§13)
      // construct the authoritative recommended_action from the action
      // registry, if this type declares one (§13, §28)
      // persist scored_at = now(); compute evidence_freshness_rank and
      // priority_score using that same scored_at (§34)

      $fingerprint = ...; // computed above

      $existingCandidate = OpportunityRunCandidateRepository::findForRunByFingerprint($locked->id, $fingerprint);
      if ($existingCandidate !== null) {
          // re-staging the same fingerprint within this run — verify identity
          // fields actually match before updating; a mismatch here means the
          // fingerprint computation or the worker's own type/context handling
          // is inconsistent, and must reject rather than silently overwrite
          // an immutable field.
          if ($existingCandidate->type !== $data->type
              || $existingCandidate->context_key !== $normalizedContextKey
              || $existingCandidate->fingerprint_version !== config('opportunity.fingerprint_version')) {
              throw ImmutableCandidateIdentityMismatchException;
          }
      }

      $row = OpportunityRunCandidateRepository::upsertMutableFields($locked->id, $fingerprint, [
          // title/summary/impact/urgency/effort/confidence/goal_relevance_rank/
          // evidence_freshness_rank/priority_score/scoring_version/scored_at/
          // evidence/recommended_action/recommended_action_hash/action_schema_version
          // — never type/context_key/fingerprint_version, which are only ever
          // set on first insert
      ]);

      OpportunityRunRepository::update($locked, ['heartbeat_at' => now()]);

      return $row;
  });
```

`stageCandidate()` never touches `opportunities`. Each call is its own short transaction; because one run's candidates are produced sequentially by one worker job, this only ever serializes correctly against `finalizeSuccessfulRun()`, `failRun()`, and `beginRun()`'s abandonment check (§25) — it does not create contention among legitimate staging calls within the same run.

---

## 22. Atomic successful-finalization protocol

```
finalizeSuccessfulRun(OpportunityRun $run): void

  DB::transaction(function () use ($run) {
      $locked = OpportunityRunRepository::findForUpdate($run->id);
      if ($locked === null) throw RunNotFoundException;
      if ($locked->status === 'succeeded') return;          // idempotent no-op
      if ($locked->status === 'failed') throw RunAlreadyFailedException;
      // status === 'running' — proceed

      foreach (OpportunityRunCandidateRepository::orderedForRun($locked->id) as $candidate) {
          $existing = OpportunityRepository::findByFingerprintForUpdate($candidate->fingerprint);

          if ($existing === null) {
              // create: status=open, freshness=current, occurrence_number=1,
              // last_confirmed_run_id=$locked->id, first_detected_at=now();
              // write a 'workflow' transition (worker, null→open, opportunity_run_id=$locked->id)
          } elseif ($existing->worker_key === $locked->worker_key) {
              // the candidate's worker_key is always $locked->worker_key — a
              // candidate row does not carry its own worker_key column, since
              // every candidate in a run belongs to that run's single worker.
              //
              // update evidence/title/summary/scoring/last_confirmed_*;
              // reaffirm freshness=current if it was stale (+ 'freshness' transition);
              // apply the action-revision rules per status (§29);
              // apply the recurrence rule if eligible (§19);
              // never touch status/snoozed_until/completed_at/dismissed_at otherwise
          } else {
              // cross-worker fingerprint collision — an integrity failure.
              throw CrossWorkerFingerprintCollisionException;   // aborts EVERYTHING
          }
      }

      // staleness sweep, same business_id/worker_key, inside this same transaction:
      foreach (OpportunityRepository::currentMissingFromRunForUpdate($locked->business_id, $locked->worker_key, $locked->id) as $opportunity) {
          // freshness=current → stale, stale_at=now();
          // write a 'freshness' transition (worker, opportunity_run_id=$locked->id)
      }

      OpportunityRunRepository::update($locked, ['status' => 'succeeded', 'completed_at' => now()]);
  });
```

**All-or-nothing.** Every staged candidate has already passed validation at staging time; any invariant failure discovered during finalization — a cross-worker collision, an immutable-field mismatch, malformed persisted data, an action-registry mismatch, any database or scoring invariant failure — throws immediately. Because the entire method (candidate application, staleness sweep, and the final `succeeded` write) is one transaction, throwing rolls back *everything*, and the run is left exactly as it was — still `running` — with no special-case handling required; this falls directly out of ordinary transactional semantics. **A successful run never partially applies, and a rejected candidate is never silently skipped while the rest proceed.** The calling job catches the exception and calls `failRun()` separately, in its own transaction (mirroring RFC-001's job `handle()`/`failed()` split).

`OpportunityRepository::currentMissingFromRunForUpdate()` explicitly includes rows where `last_confirmed_run_id IS NULL` as well as rows where it differs from this run's id — a bare `!=` predicate alone would silently omit `NULL` rows.

**Terminal-state behavior:** `running → succeeded` is the normal path. Re-invoking `finalizeSuccessfulRun()` for an already-`succeeded` run is caught at the top and is a safe idempotent no-op. Invoking it for an already-`failed` run throws `RunAlreadyFailedException` and never alters the run — a failed run cannot be resurrected into succeeded. A missing run throws `RunNotFoundException`. See §23 for the symmetric `failRun()` rule.

---

## 23. Failed-run behavior

```
failRun(OpportunityRun $run, string $safeErrorSummary): void

  DB::transaction(function () use ($run, $safeErrorSummary) {
      $locked = OpportunityRunRepository::findForUpdate($run->id);
      if ($locked === null) throw RunNotFoundException;
      if ($locked->status === 'failed') return;              // idempotent no-op
      if ($locked->status === 'succeeded') throw RunAlreadySucceededException;
      // status === 'running' — proceed

      OpportunityRunRepository::update($locked, [
          'status' => 'failed', 'completed_at' => now(),
          'safe_error_summary' => $safeErrorSummary,
      ]);
  });
```

**Terminal-state behavior**, symmetric with §22: `running → failed` is the normal path. Re-invoking `failRun()` for an already-`failed` run is a safe idempotent no-op — it never overwrites the original `safe_error_summary`/`completed_at`. Invoking it for an already-`succeeded` run throws `RunAlreadySucceededException` and never alters the run — a succeeded run cannot be retroactively marked failed. A missing run throws `RunNotFoundException`. (The one sanctioned exception to "only the run's own owner calls `failRun()`" is `beginRun()`'s abandonment path in §24, which locks and fails a *different*, timed-out run before creating its replacement — that path checks the same `running`-only precondition under its own lock.)

Touches no `opportunities` row, no `opportunity_transitions` row, performs no staleness sweep. Staged candidates from the failed run are retained, inert, for operational inspection, and are removed only through the `opportunity_run_id → opportunity_runs.id` cascade (i.e., only when the parent `Business` is ever cascade-deleted). A failed or abandoned run never creates a customer-visible Opportunity, never modifies live evidence/recommendations/priority/freshness, and never stales anything.

---

## 24. Abandoned-run recovery

Performed as the first half of `beginRun()` (§25), under the same `Business`-row lock:

1. Lock the existing `running` run row for this `(business_id, worker_key)`, if one exists.
2. If `heartbeat_at` is older than `run_timeout_minutes` (30): mark it `failed`, `abandoned_at = now()`, `reason_code = 'heartbeat_timeout'`, `safe_error_summary = 'This run stopped responding and was marked failed.'` — then proceed to create the replacement run in the same transaction.
3. If `heartbeat_at` is still fresh: reject — a healthy active run already exists; the caller (the queued job dispatch) does not create a run and relies on the next scheduled/triggered attempt.

---

## 25. Concurrency and locking

`stageCandidate()`, `finalizeSuccessfulRun()`, `failRun()`, and the abandonment half of `beginRun()` all serialize on the **same lock**: `SELECT ... FOR UPDATE` on the `opportunity_runs` row. Whichever transaction acquires it first holds it until commit; every other contender blocks and re-reads fresh state afterward. This closes the "late job stages into a terminal run" case completely: if `finalizeSuccessfulRun()` locks first, a racing `stageCandidate()` blocks, then sees `status='succeeded'` and rejects before writing; if `stageCandidate()` locks first, `finalizeSuccessfulRun()` blocks until it commits, then correctly includes that candidate.

`beginRun()` additionally locks the owning `Business` row first, as the stable serialization point for that Business (approved as a deliberate simplification — no separate per-`(business, worker)` lock-row table is introduced in RFC-002). Two concurrent `beginRun()` calls for the same Business — even for different `worker_key`s — serialize through this lock; the cost is acceptable since `beginRun()` is infrequent and fast.

No external I/O occurs while any lock is held — all validation, canonicalization, hashing, and scoring inside a locked transaction are pure in-memory computation, per RFC-002's scope (no crawling, no external workers).

Milestone 6 includes a dedicated concurrency test that opens a **second real database connection** explicitly and manually interleaves two transactions around `lockForUpdate()`, asserting the second connection's query genuinely blocks until the first commits — proving actual row-lock serialization, not only application-level logic in isolation.

---

## 26. Fingerprinting and canonicalization

Fingerprints are SHA-256 hashes, stored as `CHAR(64)` lowercase hex, computed only by trusted application code (`OpportunityFingerprint`). A worker or AI response supplies `(type, context)`; it never supplies a fingerprint, a canonical string, or a pre-hashed value.

Fingerprinting uses one unambiguous algorithm: **canonical JSON encoding**, not delimiter-joined string concatenation — delimiter-based serialization is ambiguous for list/array context values and is not used anywhere in RFC-002.

**Canonical object:**

```json
{
  "fingerprint_version": 1,
  "business_id": 123,
  "worker_key": "business_advisor",
  "type": "missing_phone",
  "context": null
}
```

`context` is `null` for every `business_advisor` type in RFC-002 (§39) — each fires at most once per Business. A future worker's `context` may be a normalized scalar, an unordered list, or an associative map, per that worker's type-registry entry (§13).

**Canonical JSON algorithm** (`canonicalJson(mixed $value): string`) — this is the **general-purpose** primitive, shared unmodified between fingerprinting (here) and action hashing (§28). It recursively sorts object/map keys, but it never reorders or deduplicates a list — list handling for values that are semantically *unordered* (such as a fingerprint context representing a set of URLs) is the responsibility of the caller, applied *before* the value reaches this function (see below and §28):

```text
canonicalize(value):
  - null            → JSON null
  - bool             → JSON true/false
  - int              → JSON number, no leading zeros (guaranteed by using a real
                        PHP int, never a numeric string)
  - float            → reject if non-finite (NAN/INF); otherwise a JSON number,
                        encoded with JSON_PRESERVE_ZERO_FRACTION so the integer
                        1 and the decimal 1.0 remain distinguishable in the
                        canonical bytes and therefore never collide when hashed
  - string           → Unicode-normalize to NFC; trim and collapse internal
                        whitespace runs to a single space, and lowercase, only
                        where the field's own contract requires case/whitespace
                        insensitivity (worker_key and type are already
                        lowercased by their closed enum/registry; a context
                        string is normalized per its type's context validator
                        in §13 *before* this function ever sees it)
  - list (array,     → canonicalize() each element, strictly preserving the
    sequential keys)    order and duplicates supplied — the general primitive
                        never sorts or deduplicates a list; re-encode as a
                        JSON array, never delimiter-joined
  - map (associative  → canonicalize() each value; recursively sort keys by
    array)               byte order (ksort, SORT_STRING); encode as a JSON
                        object
  - anything else     → throw UnsupportedCanonicalValueException

canonicalJson(value):
  return json_encode(canonicalize(value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)
```

`fingerprint = hash('sha256', canonicalJson($canonicalObject))`.

**Unordered fingerprint-context lists** (e.g. a context that is inherently a set of several equally-valid values, such multiple page URLs) are normalized by that type's own `context_validator` (§13) *before* `canonicalize()` is ever called: normalize each item per that field's own string rules, remove exact duplicates, then sort the remaining items by their own individual `canonicalJson()` bytes (byte order) — only *then* is the resulting, already-ordered list placed into the `context` value and handed to `canonicalize()`, which preserves that order unchanged, per the general rule above. This keeps the general primitive simple and order-preserving while still making unordered context sets produce the same fingerprint regardless of the order a worker happened to supply them in.

Fingerprint identity fields (`business_id`, `worker_key`, `type`, `fingerprint_version`, `fingerprint`, `context_key`) are immutable on the `opportunities` and `opportunity_run_candidates` rows once created (§15.2, §15.3). Milestone 2 includes deterministic unit test vectors for `canonicalJson()` — fixed input/output pairs covering key ordering, Unicode normalization, list-vs-object handling, the integer `1` versus the decimal `1.0` (proving they never produce the same canonical bytes), an ordered list preserving its input order unchanged, and an unordered fingerprint-context list normalized/deduplicated/sorted by its `context_validator` before hashing — so the algorithm's output is pinned, not just described.

---

## 27. Evidence contract

`evidence` is **mandatory** on both `opportunities` and `opportunity_run_candidates` (§15.2, §15.3 — `NOT NULL`, non-empty array). A candidate with zero evidence items is rejected at staging; RFC-002 has no concept of an unsupported Opportunity. Every claim is backed by at least one validated evidence item.

Each entry in the `evidence` JSON array is validated against this shape before persistence:

```json
{
  "source_type": "business_profile",
  "source_identifier": "business:123:phone",
  "fact_key": "phone_blank",
  "observed_value": null,
  "summary": "The business phone number is not set.",
  "retrieved_at": "2026-07-19T10:00:00Z",
  "observed_at": null,
  "expires_at": null,
  "source_url": null,
  "content_hash": null
}
```

`summary` is **registry-owned**, not worker-supplied (§13.2): a candidate supplies the fact data — `fact_key`, `source_type`, `source_identifier`, `observed_value`, and the structural timestamps/URLs/hash — and `OpportunityManager` renders `summary` from that `fact_key`'s trusted `evidence_summary_templates` entry. Every `business_advisor` evidence summary in RFC-002 is a fixed source-controlled string, mirroring RFC-001's existing finding text exactly (§39).

**Bounds** (source-controlled constants on `OpportunityEvidenceValidator` — never environment-configurable):

| Bound | Value |
|---|---:|
| Maximum evidence items per Opportunity | 20 |
| Maximum `source_type` length | 50 |
| Maximum `source_identifier` length | 191 |
| Maximum `fact_key` length | 100 |
| Maximum `summary` length per item | 500 |
| Maximum `source_url` length | 2048 |
| `content_hash`, when present | lowercase SHA-256, `CHAR(64)`, pattern `^[a-f0-9]{64}$` |
| Maximum canonical JSON size of `observed_value` | 4096 bytes |
| Maximum total canonical JSON size of the `evidence` array | 32768 bytes |

**`observed_value`** may be: `null`; a boolean; an integer; a finite decimal; a bounded plain string (subject to the same content restrictions as every other string field below); or a bounded JSON object/list containing *only* those same safe scalar types, recursively — never an object containing a disallowed type at any depth.

**Rejected outright** (staging fails; nothing is silently normalized, truncated, or stripped-and-kept):
- Evidence array empty, or exceeding 20 items.
- Any field exceeding its length bound above, or `observed_value`/total-evidence canonical JSON exceeding its byte bound.
- `content_hash` present and not a lowercase 64-character hex SHA-256 string.
- `retrieved_at` in the future, or after `scored_at` (§34).
- `observed_at` present and later than `retrieved_at`.
- `expires_at` present and not later than `retrieved_at`.
- `fact_key` or `source_type` not in the type definition's own allowlist (§13), or a required `fact_key` missing from the array entirely, or a `fact_key` with no registered `evidence_summary_templates` entry.
- A candidate payload that supplies its own `summary` value for an evidence item, or otherwise attempts to override the registry-rendered summary — rejected outright, not silently replaced and kept. This is what makes "unsupported narrative claims are forbidden" structurally enforceable: the summary text itself is never worker input to begin with.
- HTML tags or script content in any string field.
- Executable identifiers, credentials, binary payloads, PHP resources/objects, class names, callbacks, or shell commands anywhere in the payload.
- Arbitrary serialized PHP — any string beginning with a PHP `serialize()` signature (`O:`, `a:`, `s:` followed by a length, `<?php`, `<script`) is rejected outright; evidence must always decode as pure JSON.

`evidence_freshness_rank` is computed by `OpportunityManager` from these timestamps, using the exact formula in §34 — never supplied directly by the worker.

---

## 28. Recommended-action contract

The **authoritative** `recommended_action` JSON, constructed by `OpportunityManager` from the validated candidate plus trusted registry metadata (§13) — never accepted verbatim from a worker:

```json
{
  "schema_version": 1,
  "action_key": "add_phone",
  "parameters": { "field": "phone" },
  "approval_required": true,
  "customer_explanation": "Add a phone number so customers can reach you directly."
}
```

**Action-hash canonicalization** reuses the exact same `canonicalJson()` primitive from §26, unmodified — the same general rules apply directly, with no separate algorithm: object/map keys are recursively sorted by byte order; list order is preserved as supplied; strings are NFC-normalized; `null`/boolean/integer types are preserved and `1` versus `1.0` stay distinguishable (`JSON_PRESERVE_ZERO_FRACTION`); non-finite floats are rejected. `parameters` containing an ordered list (e.g. line items where order is meaningful) therefore keeps that order in the hash by default. If a specific `action_key`'s own registered validator (§13) needs a particular `parameters` list treated as unordered, that validator normalizes it itself — same item-normalize/dedupe/sort-by-canonical-bytes discipline as a fingerprint `context_validator` (§26) — *before* the object reaches `canonicalJson()`; the primitive itself never makes that decision.

`recommended_action_hash = hash('sha256', canonicalJson($recommendedAction))`, computed after construction, from the registry-blessed object (§13) — never from raw worker input. `parameters` are validated by the registry's own per-`action_key` validator both at staging time (§21) and again immediately before execution (§30, §31). Milestone 2 includes deterministic unit test vectors for action-hash canonicalization proving: an **ordered action list** (`parameters` containing a list the action's validator does not declare unordered) produces a different hash when reordered; and, symmetrically, an **unordered fingerprint-context list** normalized by its `context_validator` produces the *same* fingerprint regardless of input order (§26).

---

## 29. Action-revision behavior

Applied inside `finalizeSuccessfulRun()`'s per-candidate branch, for an existing Opportunity with the same `worker_key`:

| Status | Rule |
|---|---|
| `open` | Apply the new `recommended_action`/`recommended_action_hash` normally. |
| `awaiting_approval` | Hash unchanged → preserve status and action untouched. Hash changed → apply the new action, transition `awaiting_approval → open`, audit `reason_code=action_revised` — the customer must restart approval. |
| `in_progress` (pending/running execution exists) | Do **not** replace `recommended_action`/`recommended_action_hash`/`action_schema_version`/`occurrence_number`. Evidence/scoring/title/summary/freshness update normally. Once the execution resolves, a later successful run applies a newer revision with no special handling needed. |
| `snoozed` | Update action/evidence/scoring normally; `status` and `snoozed_until` untouched. |
| `dismissed` | Update evidence/scoring/action for inspection only; `status` stays `dismissed`, never reopens from this alone. |
| `completed`, continuously current-then-current | Update evidence/scoring/action for inspection; `status` stays `completed`. |
| `completed`, stale → reaffirmed | The recurrence rule (§19) applies, including applying the new action revision as part of reopening. |

---

## 30. Action approval and execution

Producers never write `awaiting_approval` or `in_progress` (§17). The manager's approval methods (§37) never accept an `action_key` from the request — `action_key` is derived exclusively from the Opportunity's own persisted `recommended_action` (an Opportunity has exactly one recommended action; there is nothing for a request to choose). `recommended_action.approval_required` (registry-owned, never request-overridable) routes the customer's request:

- `true` → `requestApproval()` transitions `open → awaiting_approval` (no execution yet); a **separate**, explicit `confirmApproval()` transitions `awaiting_approval → in_progress`.
- `false` (trusted, non-mutating only) → `beginTrustedAction()` transitions `open → in_progress` directly.

Every action that mutates `Business` data has `approval_required=true` by registry-level invariant (§13) — there is no code path that mutates `Business`/`BusinessLocation`/`BusinessService` without an execution row and therefore without explicit customer approval. The Milestone 3 `business_advisor` producer's actions (mirroring RFC-001 M5's allowlist) are all `approval_required=true`, so `awaiting_approval` is a real, reachable Milestone 4 state even though the producer itself never writes it.

### 30.1 Queued execution

`ExecuteOpportunityAction implements ShouldQueue, ShouldQueueAfterCommit` — the real after-commit queue convention, mirroring `BuildInitialBusinessSnapshot`.

```text
confirmApproval() / beginTrustedAction()  — one transaction:
  1. Create-or-return the server-idempotent pending execution row (§31),
     under an Opportunity row lock.
  2. Transition the Opportunity to in_progress; write the transition with
     action_execution_id set.
  3. If the execution row was newly created (not an existing duplicate),
     dispatch ExecuteOpportunityAction(execution.id) — after commit only.
     If an existing pending/running execution was returned instead, no
     second job is dispatched.
```

Duplicate POSTs resolve to the same execution and never dispatch a second job (§31).

```text
ExecuteOpportunityAction::handle(execution_id):
  1. Lock the Opportunity, then lock the execution (fixed order, matching
     the lock ordering already used elsewhere in OpportunityManager).
  2. If execution.status !== 'pending': return (a redelivered job for an
     already running/succeeded/failed execution is a safe no-op).
  3. Revalidate, before touching anything else: the execution's bound
     recommended_action_hash/action_schema_version/occurrence_number match
     the *live* Opportunity's current values; the action_key still resolves
     in the registry; parameters still pass the registry validator;
     ownership still holds. Any failure here → §30.2's failure path,
     handler never invoked.
  4. Transition execution pending → running.
  5. Invoke the registry handler — see §30.2 for the atomic sequence.
```

### 30.2 Atomic success and safe failure

For an RFC-002 `system_verified` action, **Business mutation, verification, execution success, Opportunity completion, and the transition audit rows all succeed atomically, in one transaction (Transaction A):**

```text
Transaction A:
  - handler calls BusinessManager (or another explicitly registry-approved
    method) to apply the mutation
  - handler performs the live-state verification recheck
  - if verification fails → throw (rolls back the mutation attempt too —
    nothing about the customer's Business is left half-changed)
  - if verification succeeds:
      execution.status = succeeded, completed_at = now(), safe_result_summary set
      opportunity.status: in_progress → completed
      write the 'workflow' transition, action_execution_id set
  - commit
```

If `Transaction A` throws for any reason — verification failure, a handler exception, a late parameter-validation failure — it rolls back in full, including any attempted `Business` mutation. A **separate, follow-up transaction (Transaction B)** then records the failure safely:

```text
Transaction B:
  - lock the execution; status = failed; safe_error_summary set (generic,
    safe text only — the real exception is logged internally, §47)
  - opportunity.status: in_progress → open
  - write the linked 'workflow' transition, action_execution_id set
  - commit
```

Pre-invocation failures (§30.1 step 3 — revision mismatch, registry mismatch, ownership failure) skip straight to Transaction B: the handler is never invoked, so there is nothing to roll back on the `Business` side.

An explicit customer retry after `failed` creates `attempt_number + 1` (§31) and a genuinely new execution row/job. A page refresh or a duplicate queue redelivery of the same job never creates a new attempt — it is caught by step 2's `pending`-only guard.

---

## 31. Action-execution idempotency

Idempotency is entirely server-derived — never accepted from the browser, a request header, or AI output.

```text
idempotency_key = sha256(opportunity_id . ':' . occurrence_number . ':' . recommended_action_hash . ':' . attempt_number)
```

On the first execution attempt (`attempt_number = 1`), `OpportunityManager` creates-or-returns the execution row under an Opportunity row lock (§30.1): if a row with this exact `idempotency_key` already exists, that same row is returned — and, critically, no second `ExecuteOpportunityAction` job is dispatched — rather than a duplicate being created. A duplicate POST or a queue redelivery of the same job therefore always resolves to the same attempt.

An explicit customer retry after a `failed` execution requires `attempt_number` to increment, which changes the derived key and therefore creates a genuinely new execution row and a genuinely new queued job. **A failed Business mutation is never silently retried merely because the customer refreshed the page** — only an explicit retry action increments `attempt_number`; a page refresh replays the same GET and observes the existing `failed` execution, it does not resubmit an attempt.

---

## 32. Completion policies

`recommended_action.completion_policy` (registry-owned) ∈ `system_verified` | `customer_attested` | `external_verified`.

- **`system_verified`** — after a successful execution, `OpportunityManager` performs a synchronous, inline recheck against live `Business`/`BusinessLocation`/`BusinessService` state (mirroring RFC-001's `OnboardingActionExecutor` fingerprint recheck); only if it genuinely confirms resolution does `status → completed` happen, in the same transaction as the execution's success write (see §30's atomic sequence). Transition `actor_type='system'`, `action_execution_id` set. **Implemented in RFC-002** for the `business_advisor` actions whose resolution is directly checkable.
- **`customer_attested`** — a distinct, explicit self-report endpoint, not "any action executing." **Available only when all of the following hold:** `status='open'`; `freshness='current'`; the persisted, registry-owned `recommended_action.completion_policy === 'customer_attested'`; and no `pending`/`running` execution exists for the Opportunity. The request: locks the Opportunity row; rechecks tenant ownership and the persisted policy (defense against a stale display); transitions `open → completed`; sets `completed_at`; writes a workflow transition with `actor_type='customer'`, `actor_user_id` set, `reason_code='customer_attested'`, `safe_note` stating it was not independently verified, and `action_execution_id=null`. **It never creates an `opportunity_action_executions` row and never mutates Business data.** A repeat attestation request for an Opportunity already `completed` at the same `occurrence_number` is a no-op — it returns the existing completed state without writing a duplicate transition. Attestation is **not available** from `awaiting_approval`, `in_progress`, `snoozed`, `dismissed`, or a `stale` Opportunity, or from an Opportunity whose persisted policy is not `customer_attested` — each of those is rejected with a clear "not available in this state" error, never silently ignored. **Implemented in RFC-002.**
- **`external_verified`** — JSON schema value and audit `actor_type` defined now; **contract-only**. An `external_verified` action is rejected as non-executable by the handler dispatch (no verifier exists) unless and until a trusted source-code verifier is registered by a future RFC.

Customer/admin UI terminology (fixed, must never be altered per-request):

| Policy | Label |
|---|---|
| `system_verified` | "System verified" |
| `customer_attested` | "Marked complete by customer" |
| `external_verified` | "Externally verified" |

A `customer_attested` completion must never be displayed or described as independently/system verified.

---

## 33. Snoozing and expiry

No workflow write ever happens during a GET request. A scheduled command, `opportunity:sweep-expired-snoozes`, runs every `snooze_sweep_minutes` (default 15) via Laravel's scheduler. For each Opportunity where `status='snoozed' AND snoozed_until <= now()`, in its own transaction:

1. Lock the Opportunity row.
2. Re-verify it is still `snoozed` and still expired (guards a race with a concurrent explicit un-snooze).
3. Transition `snoozed → open`, clear `snoozed_until`.
4. Write the transition audit row (`actor_type='system'`, `reason_code='snooze_expired'`).

One transaction per Opportunity, not one for the whole sweep — a single bad row cannot block the rest. Idempotent and retry-safe: a crash mid-sweep leaves the remaining rows for the next scheduled run. The work-queue **read** query may filter out expired-but-not-yet-swept snoozes from the default view without writing anything — a read-time filter is permitted; a read-time mutation is not.

---

## 34. Deterministic scoring

Validation is **reject, never clamp**, at staging time:
- `impact`, `urgency`, `effort`: reject unless integer in `[0,5]`.
- `confidence`: reject unless numeric in `[0.00,1.00]`.
- Relevant goal keys (worker-declared, feeding `goal_relevance_rank`): reject unless every key is a member of RFC-001's `BusinessGoal` enum — the trusted goal vocabulary. No AI-invented goal keys.
- `priority_score`: structurally impossible for a worker to supply — `OpportunityCandidateData` has no such property; unknown keys in a raw AI/worker payload are discarded during DTO hydration, before validation even runs.

`OpportunityManager` alone computes `goal_relevance_rank`, `evidence_freshness_rank`, `priority_score`, `scoring_version`, `scored_at`.

**Evidence-freshness formula** (deterministic, using `scored_at` — persisted *before* this calculation runs, and reused unchanged for every evidence item and the whole candidate, so nothing drifts mid-computation):

For each evidence item:
- If `expires_at` is present and `expires_at <= scored_at`: rank **0**.
- Otherwise, `effective_observed_at = observed_at ?? retrieved_at`, and `age = scored_at - effective_observed_at`:

| Age | Rank |
|---|---:|
| ≤ 1 day | 5 |
| > 1 day, ≤ 7 days | 4 |
| > 7 days, ≤ 30 days | 3 |
| > 30 days, ≤ 90 days | 2 |
| > 90 days, ≤ 180 days | 1 |
| > 180 days | 0 |

The Opportunity's `evidence_freshness_rank` is the **minimum** rank among all its evidence items — a compound claim is only as current as its stalest required supporting fact.

Goal-relevance formula:

| Matching stored goals | `goal_relevance_rank` |
|---:|---:|
| 0 | 0 |
| 1 | 3 |
| 2 | 4 |
| 3 or more | 5 |

`scoring_version = 1` formula:

```text
priority_score = round(
    35 * (impact / 5)
  + 20 * (urgency / 5)
  + 15 * (goal_relevance_rank / 5)
  + 15 * confidence
  + 10 * (evidence_freshness_rank / 5)
  +  5 * ((5 - effort) / 5)
)
```

Range: 0–100. Every component, `scoring_version`, and `scored_at` are persisted so the score can be reconstructed exactly, with no recomputation ambiguity.

---

## 35. Work-queue ordering

Default customer ordering:

1. `freshness = current`
2. Actionable workflow states (`open`, `awaiting_approval`, `in_progress`)
3. `priority_score` descending
4. `impact` descending
5. `urgency` descending
6. `first_detected_at` ascending
7. `id` ascending

Stale Opportunities are excluded from this default view but remain inspectable through explicit filters, for both customer and admin.

---

## 36. Repository contracts

`OpportunityManager` owns every transaction and invariant; the Eloquent repositories own query construction, row locking, and persistence — the same division RFC-001 established. Every lock (`FOR UPDATE`) used anywhere in §21–§25 and §30 is issued by a repository method, never by raw Eloquent calls scattered through the manager.

- **`OpportunityRunRepository`**: `findForUpdate(int $id)`, `findRunningForUpdate(int $businessId, OpportunityWorkerKey $workerKey)`, `create(array $attributes)`, `update(OpportunityRun $run, array $attributes)`.
- **`OpportunityRunCandidateRepository`**: `findForRunByFingerprint(int $runId, string $fingerprint)`, `countDistinctForRun(int $runId)`, `upsertMutableFields(int $runId, string $fingerprint, array $attributes)`, `orderedForRun(int $runId)`.
- **`OpportunityRepository`**: `findByFingerprintForUpdate(string $fingerprint)`, `findOwnedForUpdate(int $id, int $businessId)`, `currentMissingFromRunForUpdate(int $businessId, OpportunityWorkerKey $workerKey, int $excludeRunId)` (the staleness-sweep set, already locked and NULL-inclusive per §22), `expiredSnoozesBatch(int $limit)`, `paginateForCustomer(Business $business, array $filters)`, `paginateForAdmin(array $filters)`, `create(array $attributes)`, `update(Opportunity $opportunity, array $attributes)`.
- **`OpportunityActionExecutionRepository`**: `findForUpdate(int $id)`, `findActiveForOpportunity(int $opportunityId)` (`pending`/`running`), `findByIdempotencyKey(string $key)`, `nextAttemptNumberForUpdate(int $opportunityId)`, `create(array $attributes)`, `update(OpportunityActionExecution $execution, array $attributes)`.
- **`OpportunityTransitionRepository`**: `forOpportunity(int $opportunityId)`, `create(array $attributes)` (insert-only — no `update`).

**`beginRun()`'s `Business`-row lock (§25)** is provided by one narrowly additive method on RFC-001's existing `BusinessRepository` contract: `findForUpdate(int $id): ?Business`. This is purely additive — every existing `BusinessRepository` method, its Eloquent implementation, and its RFC-001 tests are unchanged. It follows the exact precedent RFC-001 Milestone 2 (`updateCanonicalDomain()`) and Milestone 6 (`paginateForAdmin()`) already set for approved additive methods on that same contract; it is not a redesign of RFC-001, and no other repository is introduced or bypassed to obtain this lock.

---

## 37. OpportunityManager responsibilities

`app/Library/Opportunity/OpportunityManager` owns every transaction, invariant, and workflow rule:

```php
public function beginRun(Business $business, OpportunityWorkerKey $workerKey, int $producerVersion): OpportunityRun;
public function stageCandidate(OpportunityRun $run, OpportunityCandidateData $data): OpportunityRunCandidate;
public function finalizeSuccessfulRun(OpportunityRun $run): void;
public function failRun(OpportunityRun $run, string $safeErrorSummary): void;

// Customer-initiated — action_key is always derived from the Opportunity's
// own persisted recommended_action, never accepted as a parameter (§30).
public function requestApproval(Opportunity $opportunity, Customer $customer): Opportunity;
public function confirmApproval(Opportunity $opportunity, Customer $customer): OpportunityActionExecution;
public function beginTrustedAction(Opportunity $opportunity, Customer $customer): OpportunityActionExecution;
public function attestComplete(Opportunity $opportunity, Customer $customer): Opportunity;
public function snooze(Opportunity $opportunity, Customer $customer, CarbonInterface $until): Opportunity;
public function dismiss(Opportunity $opportunity, Customer $customer): Opportunity;
public function reopen(Opportunity $opportunity, Customer $customer, string $reasonCode): Opportunity;

// Admin-initiated — on the customer's behalf (§44). The application has no
// separate Administrator model; an authorized backend account is the same
// App\Models\User row as any other, distinguished only by users.is_admin
// (§10, reusing RFC-001 Milestone 6's EnsureUserIsAdministrator convention
// exactly). actor_user_id on the resulting transition is $admin->id.
public function snoozeAsAdmin(Opportunity $opportunity, User $admin, CarbonInterface $until): Opportunity;
public function dismissAsAdmin(Opportunity $opportunity, User $admin): Opportunity;
public function reopenAsAdmin(Opportunity $opportunity, User $admin, string $reasonCode): Opportunity;

// Called only from ExecuteOpportunityAction (§30.1); never from a controller directly.
public function recordExecutionResult(OpportunityActionExecution $execution, bool $succeeded, ?string $safeSummary): Opportunity;
```

Fingerprinting (`OpportunityFingerprint`) and scoring (`OpportunityScorer`) are separate, narrowly-scoped collaborators called by `OpportunityManager` — not a new generic service layer; both live under `app/Library/Opportunity`.

---

## 38. Producer contract

The interface every future worker (§12) implements to supply candidates:

```php
interface OpportunityProducer
{
    public function workerKey(): OpportunityWorkerKey;

    /**
     * @return iterable<OpportunityCandidateData>
     */
    public function produce(Business $business): iterable;
}
```

`OpportunityCandidateData` is an immutable DTO carrying exactly: `type`, `context`, `templateParameters` (validated against the type registry's template, §13.2 — empty for every RFC-002 `business_advisor` type, whose templates are fixed constant strings), `impact`, `urgency`, `effort`, `confidence`, `relevantGoalKeys`, `evidence` (an array of `OpportunityEvidenceFactData` — `source_type`, `source_identifier`, `fact_key`, `observed_value`, `retrieved_at`, `observed_at`, `expires_at`, `source_url`, `content_hash`; deliberately **no `summary` field**, per §27), and optionally `actionParameters`. It structurally has no `title`, evidence `summary`, `actionKey`, `priority_score`, `fingerprint`, `status`, or any other registry-owned property — `title`/`summary` (Opportunity-level) and every evidence item's `summary` are always rendered by `OpportunityManager` from the type registry (§13.2), and `action_key` is always the type registry's own fixed `action_key` for that type (null if the type has no action), never chosen by the worker. None of these can be constructed through this DTO at all. The queued job driving a producer (§40) is the only caller of `beginRun`/`stageCandidate`(loop)/`finalizeSuccessfulRun`/`failRun`.

---

## 39. Deterministic business_advisor producer

`BusinessAdvisorOpportunityProducer implements OpportunityProducer` reuses `InitialBusinessSnapshotBuilder`'s fact/finding computation (RFC-001 §11.4) as a library dependency — called, not modified. `customer_onboardings.analysis_payload` and the onboarding Results step (RFC-001 M5) are untouched — this producer is a second, additive consumer of the same underlying fact/finding logic, not a replacement.

The mapping below is **locked for RFC-002** — Milestone 3 implements exactly this table; it does not invent scoring, urgency, or goal relevance ad hoc during implementation. `context` is `null` for every row (each type fires at most once per Business, matching RFC-001's original single-instance-per-rule design). `confidence = 1.00` for every row (direct, data-backed facts, no inference, matching RFC-001 §12.1). `urgency = 1` for every row — none of these are time-sensitive; `urgency` as a dimension is provisioned for future workers (e.g. "your listing lost verification"), not exercised meaningfully by `business_advisor`. `completion_policy = system_verified` for every row, and each "System verification" cell re-evaluates the *exact same* fact predicate `InitialBusinessSnapshotBuilder` already computes for that rule — reused, never reimplemented. `missing_instagram_url`'s candidate is only ever produced by the producer when the Business's industry is `EventServices`/`Photographer`/`WeddingVendor` (a producer-level eligibility check, matching RFC-001 exactly) — distinct from `relevant goal keys`, which affects scoring, not candidate eligibility.

| Type | Required evidence `fact_key` | Impact | Effort | Relevant goal keys | `action_key` | System verification |
|---|---|---:|---:|---|---|---|
| `missing_phone` | `phone_blank` | 3 | 1 | — | `add_phone` | `Business.phone` is filled |
| `missing_email` | `email_blank` | 1 | 1 | — | `add_email` | `Business.email` is filled |
| `missing_website` | `website_url_blank` | 3 | 3 | `local_seo`, `website_conversion` | `add_website` | `Business.website_url` is filled |
| `missing_description` | `description_too_short` | 3 | 1 | — | `add_description` | `Business.description` trimmed length ≥ 50 |
| `missing_primary_location` | `primary_location_missing` | 5 | 3 | — | `add_location` | `Business.primaryLocation` exists |
| `incomplete_primary_location` | `primary_location_incomplete` | 5 | 3 | — | `complete_location` | Primary location satisfies its service-mode's required fields (RFC-001 §17) |
| `missing_services` | `active_services_missing` | 5 | 3 | — | `add_service` | At least one active `BusinessService` exists |
| `missing_primary_service` | `primary_service_missing` | 5 | 1 | — | `confirm_primary_service` | `Business.primaryService` exists |
| `missing_gbp_url` | `gbp_url_blank` | 3 | 1 | `local_seo`, `reputation` | `add_gbp_url` | `Business.google_business_profile_url` is filled |
| `missing_facebook_url` | `facebook_url_blank` | 1 | 1 | — | `add_facebook_url` | `Business.facebook_url` is filled |
| `missing_instagram_url` | `instagram_url_blank` | 1 | 1 | — | `add_instagram_url` | `Business.instagram_url` is filled |

Title/summary templates (fixed constant strings, §13.2 — the same text RFC-001's findings already use verbatim, no template parameters):

| Type | Title | Summary |
|---|---|---|
| `missing_phone` | "Add your business phone number" | "Customers and future platform modules need a reliable way to contact the business." |
| `missing_email` | "Add a public business email" | "A public email gives customers and platform tools another verified way to reach you." |
| `missing_website` | "Add your website URL" | "A website URL lets customers and future SEO tools find and verify your business online." |
| `missing_description` | "Write a business description of at least 50 characters" | "A short description helps customers and future AI tools understand what your business actually does." |
| `missing_primary_location` | "Add your primary location or service area" | "A primary location is required before local customers or location-based tools can find you." |
| `incomplete_primary_location` | "Finish your primary location details" | "Your location is missing fields required for its service mode, so it cannot be used reliably yet." |
| `missing_services` | "Add at least one service" | "At least one active service is required so customers know what you offer." |
| `missing_primary_service` | "Choose a primary service" | "You have services listed but none marked as primary, so it is unclear what to lead with." |
| `missing_gbp_url` | "Add your Google Business Profile URL" | "A Google Business Profile link helps customers find and verify your business locally." |
| `missing_facebook_url` | "Add your Facebook page URL" | "A Facebook link gives customers another verified way to find your business." |
| `missing_instagram_url` | "Add your Instagram profile URL" | "Instagram is a common discovery channel for event and photo-based businesses like yours." |

`goal_relevance_rank` (§34) — not a fixed table value — is computed per-Business at scoring time from the stored Business/onboarding goals against each row's "Relevant goal keys," exactly as the formula in §34 specifies. Rows with no relevant goal keys always score `goal_relevance_rank = 0`.

---

## 40. Jobs and after-commit semantics

`RunBusinessAdvisorOpportunityProducer` (or equivalently named) `implements ShouldQueue, ShouldQueueAfterCommit`, mirroring `BuildInitialBusinessSnapshot`'s shape exactly: `tries`/`backoff` configured, dispatched only after the triggering transaction commits, `queue(config('opportunity.queue', 'default'))`. `handle()` calls `beginRun()`, loops `produce()` into `stageCandidate()`, and calls `finalizeSuccessfulRun()`; any exception from staging or finalization is caught and routed to `failRun()` with a safe summary, with the full exception logged internally only (never in `safe_error_summary`). The job is safe under retries by construction: `beginRun()`'s concurrency protocol (§25) prevents a concurrent duplicate run. Terminal-state idempotency is asymmetric by design, not "both methods no-op on every terminal state" — `finalizeSuccessfulRun()` is a safe no-op only when the run is already `succeeded` and rejects if already `failed` (§22); `failRun()` is a safe no-op only when the run is already `failed` and rejects if already `succeeded` (§23). A redelivered job therefore always converges on the run's true terminal outcome rather than silently flipping it.

Domain events (§42) use `ShouldDispatchAfterCommit` — the same real mechanism RFC-001 uses, not a reimplementation. No event may publish state from a transaction that later rolls back; because every event dispatch call site sits inside the same `OpportunityManager` transaction as the state change it announces, an event whose transaction rolls back is simply never delivered (the standard `ShouldDispatchAfterCommit` guarantee).

---

## 41. Durable transition audit

`opportunity_transitions` (§15.5) is the durable business audit log — domain events are a notification mechanism, not a substitute for it. Every workflow and freshness transition is written in the *same transaction* as the state change. Provenance is exact, not "run or execution, whichever applies loosely":

- `open → awaiting_approval`: no execution exists yet — `action_execution_id = null`.
- `awaiting_approval → in_progress`: `action_execution_id` set (the execution just created).
- `in_progress → completed` or `in_progress → open` caused by an execution outcome: `action_execution_id` set (§30.2's Transaction A or B).
- `open → completed` via `customer_attested`: `action_execution_id = null` — no execution row is ever created for an attestation (§32).
- Run-created (`(created) → open`), freshness (`current↔stale`), `action_revised`, and recurrence (`completed → open`, `reason_code=recurrence_detected`) transitions: `opportunity_run_id` set, `action_execution_id = null`.
- Purely manual dismiss/snooze/reopen (customer or admin, not triggered by any run or execution): both `opportunity_run_id` and `action_execution_id` are null.

---

## 42. Domain events

Immutable, scalar-payload-only, `ShouldDispatchAfterCommit`:

- `OpportunityRunStarted`, `OpportunityRunSucceeded`, `OpportunityRunFailed`
- `OpportunityCreated`, `OpportunityReaffirmed`
- `OpportunityMarkedStale`, `OpportunityBecameCurrent`
- `OpportunityApprovalRequested`, `OpportunityExecutionStarted`, `OpportunityExecutionSucceeded`, `OpportunityExecutionFailed`
- `OpportunityCompleted`, `OpportunityDismissed`, `OpportunityReopened`, `OpportunitySnoozed`, `OpportunitySnoozeExpired`

No empty listeners are created solely to make an event appear used, matching RFC-001 §15.

---

## 43. Customer work-queue requirements

- A dashboard panel (top N actionable Opportunities by default ordering, §35) plus a full list page with the same ordering and explicit status/freshness filters.
- Every Opportunity's title/summary/evidence is rendered from stored data only — no fabricated claims, matching RFC-001 §23's "no fake SEO/review/ranking/revenue data."
- Approve/confirm/execute/attest-complete/snooze/dismiss/reopen actions map directly to `OpportunityManager` methods (§37) — no controller-level business logic.
- `customer_attested` completions are visibly labeled per §32's fixed terminology.
- Accessible status text for in-progress executions; polling (if used) backs off, mirroring RFC-001 §23's analysis-status pattern.
- All user-facing output escaped.

---

## 44. Admin inspection requirements

- List/filter/paginate Opportunities across tenants (`view opportunities`).
- Inspect a Business's runs, staged candidates (including from failed/abandoned runs, for diagnostics), transitions, and executions.
- Dismiss/reopen/snooze on the customer's behalf (`edit opportunities`), each recorded with `actor_type='admin'`, `actor_user_id` set.
- No admin creation or deletion of Opportunities, runs, or candidates.

---

## 45. Validation and untrusted-input handling

Worker and AI output is untrusted input, validated identically regardless of source, at the earliest possible boundary (`stageCandidate()`): structural schema validation, range/enum validation (reject, never clamp — §34), registry membership validation for `action_key` and goal keys, and evidence timestamp validation (§27). Nothing bypasses this by being "from a trusted worker" — `business_advisor` candidates go through the exact same `stageCandidate()` path as any future worker's would.

---

## 46. Protected fields and mutation boundaries

No request, worker, or AI output may ever supply, directly or indirectly: `priority_score`, `fingerprint`/`fingerprint_version`, `status` (except the two producer-permitted transitions in §17), `freshness` (except via the run protocol), `occurrence_number`, `recommended_action_hash`/`action_schema_version`/`approval_required`/`completion_policy`/`validator`/`handler` (all registry-owned, §13), `idempotency_key` (§31), or any class name, route, callback, command, or field name used to select behavior. Every one of these is either computed by `OpportunityManager`/`OpportunityScorer`/`OpportunityFingerprint`, or fixed in source-controlled enums/registries.

---

## 47. Failure handling and safe errors

`opportunity_runs.safe_error_summary` and `opportunity_action_executions.safe_error_summary` are always short, fixed, customer-safe strings — never a raw exception message, stack trace, SQL fragment, or file path, matching RFC-001 §13's `BuildInitialBusinessSnapshot` convention exactly. The full exception is logged internally (structured, §48) at the point of failure, never persisted to a customer-visible column.

---

## 48. Observability and operational inspection

Structured log context on every producer job and execution: `business_id`, `opportunity_id` (where applicable), `run_id`, `worker_key`, `action_key` (where applicable), `execution_id` (where applicable) — never full request payloads, credentials, or tokens, matching RFC-001 §25. The admin inspection screens (§44) are the primary operational tool for diagnosing a stuck or failed run; no new metrics vendor is introduced.

---

## 49. Testing strategy

Mirrors RFC-001 §31's per-milestone structure:

- **Unit**: `OpportunityFingerprint` canonical JSON test vectors (§26) — fixed input/output pairs covering key ordering, duplicate removal, Unicode normalization, list-vs-object handling; action-hash canonical JSON test vectors (§28), including at least one pair of semantically-different-but-naively-similar list payloads proving they hash differently unless the action declares the list unordered; `OpportunityScorer` formula correctness across the full input range, including the evidence-freshness boundary times (exactly 1/7/30/90/180 days, and one instant past each boundary); registry invariant checks (every mutating action requires approval); every enum backing value (`OpportunityStatus`, `OpportunityFreshness`, `OpportunityCompletionPolicy`, etc.) fits its database column, including `completion_policy`'s `varchar(32)` against `customer_attested`/`external_verified`.
- **Repository**: pure CRUD/uniqueness per table, cross-tenant query rejection, repository lock methods (`findForUpdate`/`findRunningForUpdate`/`findByFingerprintForUpdate`/etc.) exercised end-to-end through `OpportunityManager` rather than only in isolation.
- **Orchestration** (`OpportunityManager`): staging isolation from live data, atomic finalize correctness, all-or-nothing rollback on invariant failure, recurrence conditions (all four, individually falsified), workflow/freshness independence, action-revision rules per status (§29), idempotent re-finalization, `failRun()` idempotency on an already-`failed` run and rejection on an already-`succeeded` run (and the symmetric case for `finalizeSuccessfulRun()`), immutable repeated-candidate identity-mismatch rejection (§21), evidence-required validation (empty array rejected) and evidence size-limit rejection (§27), unsupported type/title/summary rejection (an unregistered `(worker_key, type)` pair, and a candidate attempting to supply free-text Opportunity-level title/summary), and evidence-summary provenance rejection (a candidate attempting to supply or override an evidence item's `summary` — proving the persisted value is always the registry's `evidence_summary_templates` output, never worker input, §13.2, §27).
- **Feature — customer**: full approval→execution→completion flow per policy, `customer_attested`'s exact `open`-and-`current`-only precondition (and rejection from every other state), snooze/dismiss/reopen, tenant isolation, duplicate-submission idempotency (a second POST/queue redelivery never creates a second execution attempt), explicit-retry attempt increment, action-revision mismatch causing `in_progress → open` rather than invoking the handler.
- **Feature — admin**: authorized/unauthorized access (reusing the `EnsureUserIsAdministrator` + permission-split pattern proven in RFC-001 M6), cross-tenant inspection.
- **Concurrency**: real second-connection row-lock serialization test for `beginRun()`/`stageCandidate()`/`finalizeSuccessfulRun()`/`failRun()`.
- **Job/events**: `ExecuteOpportunityAction` dispatches only after the approval/confirmation transaction commits; a duplicate dispatch (same idempotency key) never produces a second attempt or a second job execution; retry safety; after-commit dispatch for domain events; a failed transaction dispatches nothing.

---

## 50. Deployment and rollback

Mirrors RFC-001's deployment posture:

1. Deploy with `OPPORTUNITY_ENGINE_ENABLED=false` (default) — zero behavior change.
2. Run the five migrations in the order in §16.
3. `php artisan config:cache`.
4. Restart queue workers/Horizon.
5. Run the regression suite (`tests/Unit/Opportunity`, `tests/Feature/Opportunity`).
6. Enable internally, verify the customer queue and admin inspection screens.
7. Enable in production.
8. Monitor `opportunity_runs` failure/abandonment rates and `opportunity_action_executions` failure rates.

Rollback: setting `OPPORTUNITY_ENGINE_ENABLED=false` (+ `config:cache`) is the primary, low-risk rollback — it stops new runs/jobs/queue visibility immediately while leaving all collected data intact for re-enable. A migration rollback is reverse §16 order and is only appropriate alongside a full code rollback, per RFC-001's own guidance.

---

## 51. Milestone plan

### Milestone 1 — Persistence, enums, registries, configuration
**Deliverables:** `OpportunityWorkerKey`, `OpportunityStatus`, `OpportunityFreshness`, `OpportunityCompletionPolicy` enums; the `OpportunityActionRegistry` and `OpportunityTypeRegistry` structures (§13); `config/opportunity.php`; all five migrations in dependency order (§16), including `evidence` `NOT NULL` on `opportunities`/`opportunity_run_candidates` and `completion_policy varchar(32)`; models/relationships; repository contracts + Eloquent implementations (pure data access, including the lock-aware methods listed in §36) plus the one additive `BusinessRepository::findForUpdate()` method on RFC-001's existing contract.
**Non-goals:** no orchestration, no scoring, no run protocol logic.
**Tests:** repository CRUD/uniqueness/tenant-scoping per table.
**Stopping point:** tables and repositories exist and are tested; nothing writes real data yet.
**Acceptance criteria:** all five migrations roll back cleanly; enum casts in place; repository tests pass.

### Milestone 2 — Run protocol, staging, finalization, scoring, fingerprinting, audit, recurrence
**Deliverables:** `OpportunityFingerprint` (canonical JSON, §26), `OpportunityScorer` (§34, including the evidence-freshness formula), `OpportunityManager`'s `beginRun`/`stageCandidate`/`finalizeSuccessfulRun`/`failRun`, the concurrency/abandonment protocol (§25), transition-audit writing (§41), recurrence rule (§19), domain events.
**Non-goals:** no real producer yet — tests use a fake producer/DTO.
**Tests:** everything in §49's unit/orchestration/concurrency categories, including the canonical-JSON test vectors and the asymmetric `failRun()`/`finalizeSuccessfulRun()` terminal-state rules (§22, §23).
**Stopping point:** the full run protocol is proven correct against a fake producer; no real worker exists.
**Acceptance criteria:** all-or-nothing finalize proven; recurrence's four conditions individually tested; row-lock serialization proven with a real second connection; fingerprint and action-hash canonicalization pinned by test vectors.

### Milestone 3 — Deterministic business_advisor producer
**Deliverables:** `BusinessAdvisorOpportunityProducer`, `RunBusinessAdvisorOpportunityProducer` job.
**Non-goals:** no other worker; no customer-facing UI yet.
**Tests:** end-to-end producer run correctness; failed/abandoned-run isolation against this real producer; retry safety.
**Stopping point:** real Opportunities exist in the database for a real Business, but nothing customer-facing surfaces them yet.
**Acceptance criteria:** onboarding's own analysis flow (RFC-001 M5) is unmodified and its tests remain green.

### Milestone 4 — Customer queue, approval, execution, completion, snooze sweep
**Deliverables:** controller/routes/views (§43), `OpportunityActionExecutor`, the approval/execution/idempotency protocol, `system_verified`/`customer_attested` completion (fully wired), `external_verified` (contract-only, rejected at execution), the snooze-sweep command.
**Non-goals:** no `external_verified` integration.
**Tests:** full HTTP-level workflow, idempotent duplicate submission, explicit-retry attempt increment, tenant isolation.
**Stopping point:** a customer can see, approve, execute, and complete a real `business_advisor` Opportunity end to end.
**Acceptance criteria:** every Business mutation traced to an execution row; no code path bypasses `BusinessManager`.

### Milestone 5 — Admin inspection and operational hardening
**Deliverables:** admin controller/views/permissions (`view opportunities`/`edit opportunities`, reusing `EnsureUserIsAdministrator`), inspection of runs/candidates/transitions/executions, config finalization, `.env.example`, deployment doc, regression suite definition.
**Non-goals:** no admin create/delete of Opportunities.
**Tests:** mirrors RFC-001 M6's admin authorization test suite exactly (guest/customer/unauthorized-admin/view-only/edit-authorized).
**Stopping point:** full parity with RFC-001's admin surface, applied to Opportunities.
**Acceptance criteria:** admin cross-tenant access proven and proven bounded by both authorization layers.

### Milestone 6 — Conformance, concurrency, idempotency, documentation, release readiness
**Deliverables:** a fake test producer plus an executable conformance suite; worker-registry documentation for future implementers; final acceptance pass.
**Non-goals:** no new worker implementation.
**Tests (all executable, using the fake producer):** valid begin/stage/finalize protocol; failed-run isolation; abandoned-run isolation; retry-safe finalization; real second-connection row-lock serialization; cross-tenant rejection; cross-worker rejection; candidate-limit enforcement; invalid evidence rejection (empty array, oversized fields, disallowed `observed_value` types); a candidate supplying or overriding an evidence item's `summary` is rejected; invalid action rejection; unsupported type/title/summary rejection (unregistered `(worker_key, type)`); worker priority-score injection is structurally impossible; action-execution duplicate-submission idempotency; explicit retry creates a new attempt; action-revision mismatch fails safely without invoking the handler; stale/current transitions are atomic; protected workflow state survives worker reruns; repeated-staging of the same `(opportunity_run_id, fingerprint)` with a mismatched immutable identity field is rejected.
**Stopping point:** RFC-002 is fully implemented and independently verifiable end to end.
**Acceptance criteria:** every bullet in §52 passes; documentation and release readiness accompany the tests, not substitute for them.

---

## 52. Acceptance criteria

- Every Opportunity belongs to exactly one Business; tenant ownership always derives through `business_id → businesses.customer_id → users.id`.
- Customers can never access another tenant's Opportunities, runs, candidates, transitions, or executions.
- Admin cross-tenant access requires both `EnsureUserIsAdministrator` and the feature-specific permission.
- Workers never mutate live Opportunities during a run — only `stageCandidate()` is reachable mid-run.
- A failed or abandoned run has zero customer-visible effect.
- Every successful run applies atomically; a partial application is impossible.
- Workflow status and freshness remain independent state machines; no `failed` workflow status exists.
- Producer failure lives only in `opportunity_runs`; execution failure lives only in `opportunity_action_executions`.
- Customer workflow decisions survive worker reruns except for the one narrow, audited recurrence rule.
- `opportunity_transitions` durably records every workflow/freshness change, in the same transaction as the change.
- Priority is always deterministic, versioned, server-computed, and reconstructable, including `evidence_freshness_rank` via the exact formula in §34.
- Every Opportunity carries at least one validated evidence item — `evidence` is `NOT NULL` and non-empty on both `opportunities` and `opportunity_run_candidates`.
- Every evidence item's `summary` is registry-rendered from a trusted `evidence_summary_templates` entry (§13.2, §27) — never worker- or AI-supplied free text.
- `uid` is unique on `opportunity_runs`, `opportunities`, `opportunity_run_candidates`, and `opportunity_action_executions`.
- No class, route, callback, command, field, handler, or validator is ever selected from AI output, request data, environment variables, or database records.
- Every enum backing value fits its database column (verified by test, §49) — in particular `completion_policy varchar(32)` against `customer_attested`/`external_verified`.
- Existing repository/library conventions are followed; no new generic service layer; RFC-001 is unmodified and its tests remain green.
- Feature is fully disableable without a rollback.

---

## 53. Deferred work

Explicitly out of scope for RFC-002, deferred to future RFCs:

- SEO worker
- Content worker
- Sales worker
- Reputation worker
- Website worker
- `external_verified` completion integrations
- Web crawling or scraping of any kind
- Google Business Profile integration
- Google Analytics / Search Console or other analytics integrations
- Automatic execution of any unapproved Business-data mutation
- Any generalized recurrence policy beyond the single narrow rule defined in §19
