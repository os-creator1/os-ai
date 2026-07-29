# RFC-002 — Opportunity Engine: Worker Extension Guide

**Companion to:** `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md` (the frozen specification) and `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE-DEPLOYMENT.md` (operator-facing deployment/rollout).

This guide is **developer-facing**, subordinate to RFC-002, and separate in purpose from the deployment guide. It describes the **existing** producer extension protocol as already implemented through Milestone 6 Slice 1. It is **not** authorization to add a new production worker, and it is **not** evidence that RFC-002's final acceptance pass or Git tagging is complete — see §15.

---

## 1. Purpose and scope

Explains how a future Opportunity producer (a "worker") integrates with the existing worker/type registry and the run/candidate/finalization protocol, so a future implementer can extend the system correctly without re-deriving these rules from source. It documents behavior that already exists in this codebase; it introduces no new behavior itself.

---

## 2. Existing architecture

```
Producer (implements OpportunityProducer)
  → OpportunityCandidateData (immutable DTO, no persistence)
  → OpportunityManager::beginRun()
  → OpportunityManager::stageCandidate()   (called once per yielded candidate)
  → OpportunityManager::finalizeSuccessfulRun()
```

On any failure during production/staging/finalization, the driving job catches the exception and calls `OpportunityManager::failRun(OpportunityRun $run, string $safeErrorSummary): void` in a separate transaction (verified against `app/Library/Opportunity/OpportunityManager.php`, and against the only real caller, `app/Jobs/Opportunity/RunBusinessAdvisorOpportunityProducer.php::handle()`, which passes the fixed literal `'We could not refresh your opportunities right now. Please try again later.'` as `$safeErrorSummary` — never the caught exception's own message).

The producer supplies candidate data. The current `BusinessAdvisorOpportunityProducer` performs no Opportunity persistence, and every future producer must follow the same architectural rule. Candidate persistence, Opportunity reconciliation, and transition recording are performed through `OpportunityManager` and its repositories; producers must never write these records directly.

---

## 3. Worker-key and type-registry model

`App\Enums\Opportunity\OpportunityWorkerKey` is a backed string enum with six cases: `BusinessAdvisor`, `Seo`, `Content`, `Sales`, `Reputation`, `Website`. Only `BusinessAdvisor` currently has a real producer, a real job, and real `OpportunityTypeRegistry` entries. **The other five cases exist in the enum but are otherwise unused** — they have no registered types, no producer, and no job. Do not describe them as "reserved," "available," or approved for implementation; RFC-002 does not say that. A future worker author must not assume ownership of one of these cases merely because it compiles — confirm intended ownership and register real type definitions before using one (see §11).

`App\Library\Opportunity\OpportunityTypeRegistry` is a `final` class with a single hardcoded `DEFINITIONS` array keyed by type, each entry naming its owning worker via `OpportunityTypeRegistry::forWorker($workerKey)`. Every `(worker_key, type)` pair a producer's candidates use must exist in this array before `stageCandidate()` will accept it.

---

## 4. OpportunityProducer contract

```php
interface OpportunityProducer
{
    public function workerKey(): OpportunityWorkerKey;

    /** @return iterable<OpportunityCandidateData> */
    public function produce(Business $business): iterable;
}
```

(`app/Library/Opportunity/OpportunityProducer.php`, verbatim.) This is the entire contract. The interface shape does not mechanically prevent I/O or persistence — nothing in PHP stops an implementer from writing arbitrary code inside `produce()`. Architectural compliance is provided by the DTO/registry/manager pipeline, code review, and executable tests, not by the interface itself. Within the producer protocol, producers return `OpportunityCandidateData`; `OpportunityManager` and its repositories own candidate persistence and successful-run reconciliation. Separate customer/admin manager operations also own workflow mutations, but none of those operations belongs inside a producer. Producer authors are trusted to follow §12, exactly as `BusinessAdvisorOpportunityProducer` already does (it performs no writes, no transactions, no repository calls, no external I/O — see its own docblock).

---

## 5. Canonical candidate requirements

`App\Library\Opportunity\OpportunityCandidateData` (`app/Library/Opportunity/OpportunityCandidateData.php`) is the only shape `produce()` may yield. It is immutable and structurally carries no `title`, `summary`, `actionKey`, `fingerprint`, `fingerprintVersion`, `priorityScore`, `status`, `freshness`, `occurrenceNumber`, action-schema metadata, or any handler/validator/callback/class-name property — those fields do not exist on this class at all; they are always `OpportunityManager`/registry output. `OpportunityCandidateData::fromArray()` additionally discards any unknown top-level key on a raw payload before it ever reaches the constructor — proven by `tests/Unit/Opportunity/OpportunityCandidateDataTest.php::test_from_array_discards_unknown_top_level_keys_including_an_injected_priority_score`.

The manager, not the producer, computes: the canonical fingerprint (`OpportunityFingerprint`, canonical-JSON based), `title`/`summary` (rendered from the registry's own templates), evidence-item `summary` text (from `evidence_summary_templates`), and priority score (`OpportunityScorer`).

---

## 6. Run lifecycle

1. `OpportunityManager::beginRun(Business $business, OpportunityWorkerKey $workerKey, int $producerVersion): OpportunityRun` — locks the Business row, checks for an existing healthy `running` run for `(business_id, worker_key)`, and either rejects (`RunAlreadyActiveException`) or begins a new run. Throws `OpportunityEngineDisabledException` first if `config('opportunity.enabled')` is false.
2. For each item yielded by `produce($business)`: `OpportunityManager::stageCandidate(OpportunityRun $run, OpportunityCandidateData $data): OpportunityRunCandidate`. Validates ranges, template parameters, context, action parameters, goal keys, evidence, and the `(worker_key, type)` registry membership; computes and persists the canonical fingerprint; touches only the run/candidate tables.
3. `OpportunityManager::finalizeSuccessfulRun(OpportunityRun $run): void` — applies every staged candidate to `opportunities` atomically, reconciles (stales) any current Opportunity for that `(business, worker_key)` not confirmed by this run, and marks the run `succeeded`. All in one transaction; any failure rolls back everything and the run is left `running` for the caller to route to `failRun()`.

**Zero-candidate runs are valid and supported** — a run that stages nothing still finalizes successfully (proven by `tests/Feature/Opportunity/OpportunityProducerConformanceTest.php::test_zero_candidate_fake_producer_finalizes_successfully_under_a_distinct_worker_key`).

---

## 7. Failure lifecycle

`OpportunityManager::failRun(OpportunityRun $run, string $safeErrorSummary): void` — the only method that ever marks a run `failed` outside the abandonment path in `beginRun()`. `$safeErrorSummary` must be a short, fixed, customer-safe literal string chosen by the caller (the job), never the caught exception's own message and never any raw exception text. The current real caller's exact literal is quoted in §2. Producer failure is recorded **only** in `opportunity_runs`; it never touches `opportunity_action_executions` (that table is exclusively for action-execution failures, an unrelated later stage of the protocol).

---

## 8. Concurrency and worker isolation

Active-run uniqueness is scoped by `(business_id, worker_key)`, not by Business alone. A healthy `running` run for one worker key does not block `beginRun()` for a *different* worker key on the same Business — proven by `OpportunityProducerConformanceTest::test_cross_worker_active_runs_may_coexist_for_the_same_business`. A second `beginRun()` call for the **same** `(business, worker_key)` while a healthy run is active throws `RunAlreadyActiveException` (existing coverage: `tests/Feature/Opportunity/OpportunityManagerBeginRunTest.php`). A stale `running` run (heartbeat older than `config('opportunity.run_timeout_minutes')`) is abandoned — marked `failed`, `abandoned_at` set, fixed `reason_code`/`safe_error_summary` — and superseded by a new run in the same transaction (existing coverage: `OpportunityManagerBeginRunTest.php`, e.g. `test_heartbeat_one_second_past_the_timeout_cutoff_is_abandoned`). This guide does not re-derive that coverage; it only confirms a *different* worker key is unaffected by it.

---

## 9. Feature flag and queue boundaries

`config('opportunity.enabled')` gates `beginRun()` (and several other manager entry points — see the deployment guide §4). A producer or its job must not attempt to bypass this by calling `stageCandidate()`/`finalizeSuccessfulRun()` without first successfully calling `beginRun()` on the same enabled state. Any real job dispatching a producer's work should be pushed to `config('opportunity.queue')` (default `default`), exactly as `RunBusinessAdvisorOpportunityProducer` already does via `$this->onQueue(config('opportunity.queue', 'default'))` in its constructor — see the deployment guide for the full queue-worker/Horizon requirements. This guide does not restate that operational detail.

---

## 10. Current Business Advisor implementation

`App\Jobs\Opportunity\RunBusinessAdvisorOpportunityProducer::handle()` currently type-hints the **concrete** `BusinessAdvisorOpportunityProducer` class as a container-injected `handle()` parameter, not the `OpportunityProducer` interface. **This is intentional current scope, not a defect.** It means this specific job class can only ever drive `BusinessAdvisorOpportunityProducer` — it is not a generic multi-producer dispatcher today. A future second real worker would require its own job class (or a separately designed and reviewed generic dispatcher) — **this guide does not authorize converting the existing Business Advisor job into a generic dispatcher**, and doing so is out of scope for this document.

---

## 11. Future worker implementation checklist

1. Confirm or add the intended `OpportunityWorkerKey` case (§3 — do not assume an existing unused case is available without confirming ownership).
2. Define the worker's supported Opportunity types.
3. Register each exact `(worker_key, type)` definition in `OpportunityTypeRegistry`.
4. Define that worker's allowed evidence facts and source types for each type.
5. Define any allowed `action_key` (in `OpportunityActionRegistry`, if the type has an action) and allowed goal keys.
6. Implement `OpportunityProducer` for the new worker.
7. Emit only canonical `OpportunityCandidateData` from `produce()` — never a raw array, never manager-owned fields.
8. Add a worker-specific execution job, or a separately approved dispatcher design (§10 — not implied or authorized by this guide).
9. Apply the feature-flag and configured-queue boundaries (§9).
10. Use the existing `beginRun()`/`stageCandidate()`/`finalizeSuccessfulRun()`/`failRun()` lifecycle for producer execution. Any proposed new manager API requires separate architectural review and is not authorized by this guide.
11. Add a producer conformance test suite for the new worker, mirroring `OpportunityProducerConformanceTest.php`'s structure.
12. Run focused, Opportunity-regression, and full-suite validation (§16 of this guide).
13. Update the deployment/operations documentation **only if** new runtime configuration (a new env var, a new queue, a new scheduled command) is introduced — a new worker sharing existing configuration needs no deployment-doc change.

This checklist describes a hypothetical future worker. **No second production worker is introduced by this document.**

---

## 12. Prohibited implementation patterns

A producer must **not**:

- Write `opportunities`, `opportunity_run_candidates`, or `opportunity_transitions` rows directly — only `OpportunityManager` does.
- Mutate customer workflow state (`status`, `freshness`, `snoozed_until`, `dismissed_at`, `completed_at`, etc.) under any circumstance.
- Directly configure, request/confirm approval, execute, retry, dismiss, snooze, reopen, or attest-complete an Opportunity — those are customer/admin workflow actions on `OpportunityManager`, entirely unrelated to the producer protocol.
- Persist a raw exception message, stack trace, SQL fragment, or file path anywhere — only fixed, safe, source-controlled summary strings are ever recorded (§7).
- Bypass `OpportunityTypeRegistry` or `OpportunityCandidateData`, such as by passing arbitrary arrays directly into persistence code or inventing an unregistered `(worker_key, type)` pair. Using the DTO constructor or `OpportunityCandidateData::fromArray()` is permitted only when the resulting DTO follows the canonical schema and is passed through `stageCandidate()`.
- Supply `priority_score`, `fingerprint`, `title`, rendered evidence `summary` text, or any other manager/registry-owned field — these are structurally absent from `OpportunityCandidateData` and discarded by `fromArray()` even if present in a raw payload (§5).

---

## 13. Executable conformance suite

`tests/Feature/Opportunity/OpportunityProducerConformanceTest.php`, backed by the test-only `tests/Feature/Opportunity/Support/FakeOpportunityProducer.php` (a `final` class implementing `OpportunityProducer`, constructed with a fixed worker key and a fixed candidate list — no persistence, no job/event dispatch, no manager logic).

**What these tests prove:**
- The full begin→produce→stage→finalize protocol works when driven by a producer class *other than* `BusinessAdvisorOpportunityProducer` — i.e. the manager's protocol methods are genuinely producer-agnostic, not hardcoded to the concrete class.
- Zero-, one-, and multiple-candidate runs all finalize correctly.
- A second, distinct worker key can hold an active run concurrently with an active `business_advisor` run for the same Business, without interference.
- An unregistered `(worker_key, type)` pair is rejected (`UnsupportedOpportunityTypeException`) with nothing persisted.
- `beginRun()` rejects when the feature flag is disabled, before any row is created.

**What these tests intentionally do not duplicate or prove:** same-worker concurrency locking, abandoned-stale-run recovery, maximum-candidate-count enforcement, full candidate schema validation, atomic-finalization rollback under a forced failure, action execution, workflow-state preservation across reruns, or safe-failure-string exactness — all already covered by the existing manager test suites cited throughout this guide and in §14. These tests also do **not** prove anything about a real external job, a real queue worker, or a real future production worker — they exercise the manager's protocol methods directly, in-process, with a fake producer.

---

## 14. RFC §52 acceptance evidence map

Traceability only. Following the final acceptance validation recorded in §16, every §52 row now has direct supporting evidence. This map is not itself a release decision — tagging remains subject to every gate in §15, none of which is waived by this table.

| RFC §52 requirement | Implemented mechanism | Primary evidence | Status | Notes |
|---|---|---|---|---|
| Every Opportunity belongs to exactly one Business; tenant ownership derives through `business_id → businesses.customer_id → users.id` | `findOwned()`/`findOwnedForUpdate()` compound predicate; `assertOpportunityOwnership()` | `tests/Feature/Opportunity/OpportunityRepositoryTest.php`, `OpportunityManagerDismissTest.php` (ownership-rejection cases) | Covered | |
| Customers can never access another tenant's Opportunities/runs/candidates/transitions/executions | Tenant-scoped repository queries; no global-by-id lookup in any customer controller | `tests/Feature/Opportunity/OpportunityQueueHttpTest.php`, `OpportunityMutationHttpTest.php` (cross-tenant 404 cases) | Covered | Runs/candidates have no customer-facing route at all — nothing to test there |
| Admin cross-tenant access requires both `EnsureUserIsAdministrator` and the feature-specific permission | Double middleware/permission boundary on every admin route | `tests/Feature/Opportunity/AdminOpportunityControllerTest.php`, `AdminOpportunityRunControllerTest.php` | Covered | |
| Workers never mutate live Opportunities during a run — only `stageCandidate()` is reachable mid-run | `stageCandidate()` touches only run/candidate tables (per its own docblock) | `tests/Feature/Opportunity/OpportunityManagerStageCandidateTest.php`; producer-agnosticism via `OpportunityProducerConformanceTest.php` | Covered | |
| A failed or abandoned run has zero customer-visible effect | Failed/abandoned runs never create/alter `opportunities` rows | `tests/Feature/Opportunity/OpportunityManagerBeginRunTest.php` (abandonment), `OpportunityManagerFailRunTest.php` | Covered | |
| Every successful run applies atomically; a partial application is impossible | Single transaction in `finalizeSuccessfulRun()` | `tests/Feature/Opportunity/OpportunityManagerFinalizeSuccessfulRunTest.php` (rollback cases) | Covered | |
| Workflow status and freshness remain independent state machines; no `failed` workflow status exists | `OpportunityStatus` enum has no `Failed` case; freshness handled separately | `app/Enums/Opportunity/OpportunityStatus.php` (source); `OpportunityManagerFinalizeSuccessfulRunTest.php` | Covered | |
| Producer failure lives only in `opportunity_runs`; execution failure lives only in `opportunity_action_executions` | Separate `failRun()` vs. execution-result recording paths | `OpportunityManagerFailRunTest.php`; `tests/Feature/Opportunity/ExecuteOpportunityActionJobTest.php` | Covered | |
| Customer workflow decisions survive worker reruns except the one narrow, audited recurrence rule | Action-revision/recurrence handling in `finalizeSuccessfulRun()` | `OpportunityManagerFinalizeSuccessfulRunTest.php` | Covered | |
| `opportunity_transitions` durably records every workflow/freshness change, in the same transaction | Every mutating manager method writes exactly one transition row in its own transaction | `OpportunityManagerDismissTest.php`/`SnoozeTest.php`/`ReopenTest.php` (exact-transition-fields cases) | Covered | |
| Priority is always deterministic, versioned, server-computed, reconstructable, including `evidence_freshness_rank` via §34's exact formula | `OpportunityScorer` | `tests/Unit/Opportunity/OpportunityScorerTest.php` | Covered | |
| Every Opportunity carries at least one validated evidence item; `evidence` is `NOT NULL` and non-empty on both `opportunities` and `opportunity_run_candidates` | Migration column constraint; `OpportunityEvidenceValidator` | `OpportunityRepositoryTest.php::test_evidence_column_is_json_not_null_without_a_default`, `OpportunityRunCandidateRepositoryTest.php` (same test name), `tests/Unit/Opportunity/OpportunityEvidenceValidatorTest.php` | Covered | |
| Every evidence item's `summary` is registry-rendered, never worker/AI-supplied free text | `evidence_summary_templates` in `OpportunityTypeRegistry`; candidate staging rejects a supplied `summary` | `tests/Unit/Opportunity/OpportunityTypeRegistryTest.php`; manager staging tests for evidence-summary provenance rejection | Covered | |
| `uid` is unique on `opportunity_runs`, `opportunities`, `opportunity_run_candidates`, `opportunity_action_executions` | `HasUid` trait + unique DB index | `OpportunityRunRepositoryTest.php`, `OpportunityRepositoryTest.php`, `OpportunityRunCandidateRepositoryTest.php`, `OpportunityActionExecutionRepositoryTest.php` (each has `test_uid_is_automatically_generated_and_unique`) | Covered | |
| No class, route, callback, command, field, handler, or validator is ever selected from AI output, request data, environment variables, or database records | Registries (`OpportunityActionRegistry`, `OpportunityTypeRegistry`) are `final`, hardcoded; `OpportunityCandidateData::fromArray()` discards unknown keys | `tests/Unit/Opportunity/OpportunityCandidateDataTest.php::test_from_array_discards_unknown_top_level_keys_including_an_injected_priority_score`; `tests/Unit/Opportunity/OpportunityActionRegistryTest.php`, `OpportunityTypeRegistryTest.php` | Covered | |
| Every enum backing value fits its database column (e.g. `completion_policy varchar(32)` against `customer_attested`/`external_verified`) | `OpportunityEnumColumnFitTest` reads live `information_schema.COLUMNS` (`TABLE_SCHEMA = DATABASE()`, live `DATA_TYPE`/`CHARACTER_MAXIMUM_LENGTH`) and checks every case of each mapped backed enum against its actual column, across all nine persisted enum-column mappings in the RFC-002 domain | `tests/Feature/Opportunity/OpportunityEnumColumnFitTest.php` | Covered | 9 tests passed, 99 assertions during final acceptance validation (§16) |
| Existing repository/library conventions are followed; no new generic service layer; RFC-001 is unmodified and its tests remain green | Architectural review (this and prior slices); RFC-001 test suite untouched throughout RFC-002's implementation | Targeted evidence: `tests/Unit/Business tests/Feature/Business` passed. Release evidence: the fresh, complete application suite via `php artisan test --stop-on-failure` passed at 1333 tests, 4529 assertions (§16) | Covered | The targeted RFC-001 Business regression and the complete application suite are distinct checks; both passed in the current commit state (`725c0f1`), so this is no longer a partial result |
| Feature is fully disableable without a rollback | `config('opportunity.enabled')` gates every customer/admin route, relevant manager methods, both jobs, and the sweep command | `OpportunityQueueHttpTest.php`, `AdminOpportunityControllerTest.php`, `RunBusinessAdvisorOpportunityProducerJobTest.php`, `ExecuteOpportunityActionJobTest.php`, `SweepExpiredOpportunitySnoozesCommandTest.php` (each has a disabled-flag case) | Covered | |

---

## 15. Release and tag gate

`rfc-002-opportunity-engine` must **not** be applied until all of the following hold. As of this document, the executable evidence requirements below (items 2–7) are satisfied per the final acceptance validation in §16 — the remaining items (1, 8, 9, 10) still remain before tagging:

1. This documentation change is reviewed and committed.
2. Every §14 row is `Covered` — satisfied as of this document (§16 validation).
3. Focused producer-conformance suite passes — satisfied (6 passed, 40 assertions, §16).
4. The Opportunity regression suite passes — satisfied (1117 passed, 3966 assertions, §16).
5. A fresh, complete application test suite passes — satisfied (1333 passed, 4529 assertions, §16).
6. `bootstrap/cache/config.php` is confirmed absent before PHPUnit runs, so `.env.testing` loads correctly — satisfied for the §16 run.
7. The disposable `ultimatesms_testing` database is confirmed as the database PHPUnit actually used — satisfied for the §16 run.
8. `git status` shows a clean working tree — must be re-confirmed at the exact commit intended to be tagged, which is not necessarily this documentation-only commit.
9. The exact commit intended to be tagged is identified.
10. An explicit final-acceptance review approves tagging.

This document does not perform that review and does not apply any tag. RFC-002 is not marked tagged or released by this document.

---

## 16. Validation commands (reference only — not executed by this document)

```
php artisan config:clear
```
Confirm `bootstrap/cache/config.php` is absent before proceeding.

```
php artisan test tests/Feature/Opportunity/OpportunityProducerConformanceTest.php --stop-on-failure
```

```
php artisan test tests/Unit/Opportunity tests/Feature/Opportunity --stop-on-failure
```

```
php artisan test --stop-on-failure
```

PHPUnit must be allowed to load `.env.testing`, and the configured testing database must be the disposable `ultimatesms_testing` database — never the production database.

**Final acceptance validation record — 2026-07-29**, at code commit `725c0f1` (this documentation-only closure edit was made after that commit and is not itself the tag commit — see §15):

- `php artisan config:clear` was run first; `bootstrap/cache/config.php` was confirmed absent before any PHPUnit run.
- `.env.testing` used the disposable `ultimatesms_testing` database for every run below; the production-looking `jazmisuh_jasmin` database was not used.
- Producer conformance: **6 passed, 40 assertions**.
- Enum/database-column fit: **9 passed, 99 assertions**.
- Opportunity regression: **1117 passed, 3966 assertions**.
- RFC-001 Business regression: **passed** (separate assertion/test count not recorded in the completion summary this document draws from — not invented here).
- Complete application suite: **1333 passed, 4529 assertions**.
