# RFC-002 — Opportunity Engine: Deployment & Operations

**Companion to:** `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md`
**Scope:** Environment variables, migration order, rollout, queue/scheduler operations, smoke tests, and rollback for the Opportunity Engine (Milestones 1–5). Milestone 6 (conformance suite, final release-readiness pass) is explicitly out of scope — see §22.

This document contains no real credentials, database identifiers, API keys, or customer data. Every value below is a placeholder or a documented safe default.

---

## 1. Purpose and scope

This document covers everything an operator needs to deploy, enable, monitor, and — if necessary — roll back the Opportunity Engine as implemented through Milestone 5: the domain/run protocol (M1–M2), the `business_advisor` producer (M3), the customer-facing queue/detail/mutation/polling surface (M4), and the admin inspection/mutation surface (M5). It does not cover the SEO, Content, Sales, Reputation, or Website workers, `external_verified` completion, or any future RFC — none of these exist in this codebase.

---

## 2. Prerequisites

- Laravel 12 / PHP 8.2, matching the rest of this application — no new runtime dependency was introduced.
- A queue connection capable of running `ShouldQueue` jobs (`sync`, `database`, or Redis/Horizon — see §7). `QUEUE_CONNECTION=sync` is this project's own `.env.example` default.
- The Laravel scheduler must be invoked at least once per minute by the hosting environment (see §8) for the expired-snooze sweep to ever run.
- RFC-001 Business Core must already be deployed — the `business_advisor` producer reads `InitialBusinessSnapshotBuilder`'s existing profile-completeness facts and RFC-002's own `BusinessRepository::findForUpdate()` addition, both already present in this codebase.

---

## 3. Environment variables

Verified directly against `config/opportunity.php` and `.env.example` — both already agree exactly; **no `.env.example` change was required or made**.

| Variable | Default (if unset) | Effect |
|---|---|---|
| `OPPORTUNITY_ENGINE_ENABLED` | `false` | Master switch, read by `config('opportunity.enabled')`. When `false`, every customer route, admin route, queued job, and the scheduled sweep command behave as a safe no-op (see §4). |
| `OPPORTUNITY_ENGINE_QUEUE` | `default` | The named queue both `RunBusinessAdvisorOpportunityProducer` and `ExecuteOpportunityAction` are pushed to. `default` matches this application's own baseline queue, so no worker/Horizon configuration change is required unless you choose a different name (see §7). |
| `OPPORTUNITY_RUN_TIMEOUT_MINUTES` | `30` | Heartbeat age past which a `running` producer run is considered abandoned and superseded by a new run (§25 of the RFC). Not read by any job's own retry/backoff settings — those are fixed in code (see §9–§10). |
| `OPPORTUNITY_MAX_CANDIDATES_PER_RUN` | `100` | Hard cap enforced inside `OpportunityManager::stageCandidate()` — a producer run that would exceed this throws and is marked `failed` rather than silently truncating. |
| `OPPORTUNITY_SNOOZE_SWEEP_MINUTES` | `15` | Normalized into a bounded cron step (1–59 minutes) by `Kernel.php`'s `opportunitySnoozeSweepCronMinutes()` — an invalid or out-of-range value silently falls back to `15`, never to a sub-minute cadence (see §8). |

Two of these five names do **not** carry an `ENGINE_` infix (`OPPORTUNITY_RUN_TIMEOUT_MINUTES`, `OPPORTUNITY_MAX_CANDIDATES_PER_RUN`, `OPPORTUNITY_SNOOZE_SWEEP_MINUTES`) — only `OPPORTUNITY_ENGINE_ENABLED` and `OPPORTUNITY_ENGINE_QUEUE` do. This was verified against the actual `env()` calls in `config/opportunity.php` before writing this table; do not assume a uniform naming scheme when scripting deployment.

All five are read exactly once, inside `config/opportunity.php`, via `env()` — this is what makes the feature `config:cache` safe (see §6). `fingerprint_version`/`scoring_version` are also present in that file but are **not** environment-configurable — they are source-controlled algorithm versions; changing either is a code change, not a deployment-time setting.

**`OPPORTUNITY_ENGINE_ENABLED` defaults to `false`.** Deploying this code with no `.env` changes at all reproduces exact pre-Opportunity-Engine behavior for every existing customer.

---

## 4. Feature-flag rollout strategy

`config('opportunity.enabled')` is checked independently at several layers, not just one:

- Customer `OpportunityController` (`abort_unless(..., 404)` — first line of every action).
- Admin `OpportunityController` and `OpportunityRunController` (same pattern, after `authorize()`).
- `OpportunityManager::confirmApproval()`, `beginTrustedAction()`, `retryFailedExecution()`, `attestComplete()`, and `sweepExpiredSnoozes()` (domain-layer guard).
- Both queued jobs (`RunBusinessAdvisorOpportunityProducer`, `ExecuteOpportunityAction`) — a disabled flag causes the job to log and return, not throw or retry.
- `SweepExpiredOpportunitySnoozes` command — logs "disabled; sweep skipped" and exits `SUCCESS`, never fails the schedule.

---

## 5. Migration procedure

Five migrations, in dependency order (Laravel's migrator runs them in timestamp order automatically):

1. `2026_07_19_120001_create_opportunity_runs_table.php`
2. `2026_07_19_120002_create_opportunities_table.php` (FK → `opportunity_runs.id` via `last_confirmed_run_id`)
3. `2026_07_19_120003_create_opportunity_run_candidates_table.php` (FK → `opportunity_runs.id`)
4. `2026_07_19_120004_create_opportunity_action_executions_table.php` (FK → `opportunities.id`, `users.id`)
5. `2026_07_19_120005_create_opportunity_transitions_table.php` (FK → `opportunities.id`, `opportunity_runs.id`, `opportunity_action_executions.id`, `users.id`)

```
php artisan migrate
```

These five tables have no legacy data to reconcile on the way in, but `php artisan migrate:rollback` operates on Laravel's **last migration batch as a whole**, not on a named feature — if any unrelated migration shares that batch, a generic `migrate:rollback` reverses those too. Before rolling back, run `php artisan migrate:status` and confirm exactly which migrations share the target batch; do not assume it contains only these five. See §17 for the full rollback procedure and §18 before rolling back a deployment with real Opportunity data already collected.

**Do not run `migrate:fresh` or any destructive reset against an environment holding real Opportunity, run, candidate, execution, or transition data** — this document never recommends it, and none of the verification steps below require it.

---

## 6. Config/cache refresh procedure

Every value `config/opportunity.php` returns comes from a top-level `env()` call with a hardcoded literal default — safe to cache:

```
php artisan config:cache
```

To verify the cached config reflects your intended values before relying on it in production:

```
php artisan config:clear
php artisan tinker --execute="var_dump(config('opportunity'));"
php artisan config:cache
```

If you change any `OPPORTUNITY_*` value in `.env` after a `config:cache`, you must re-run `php artisan config:cache` (or `config:clear`) — the cached copy does not pick up new `.env` values automatically.

---

## 7. Queue-worker requirements

- Both jobs (`RunBusinessAdvisorOpportunityProducer`, `ExecuteOpportunityAction`) implement `ShouldQueue` and `ShouldQueueAfterCommit` — each is only ever pushed after its enclosing transaction commits; Laravel's queue layer honors this automatically regardless of connection.
- **`tries=3`, `backoff=[10, 60, 300]` on both jobs.** No `$timeout` property is declared on either job in code, so the effective timeout is whatever your queue connection/worker configuration already applies to every other job in this application — this document does not claim a value beyond what is actually configured.
- **Queue name:** defaults to `default` (via `OPPORTUNITY_ENGINE_QUEUE`), which is already watched by this application's existing Horizon supervisors (`config/horizon.php` lists `'queue' => ['default']` / `['default', 'batch']`). If you set `OPPORTUNITY_ENGINE_QUEUE` to any other value, you must add that queue name to the relevant Horizon supervisor's `queue` array, or pass `--queue=<name>` to `queue:work` if not using Horizon — otherwise the job is enqueued but **never processed**. Queue workers do not pick up new queue names automatically.
- **A worker (or Horizon) must actually be running** for either job to execute under any connection other than `sync`. This document does not assume one is already running — verify with `php artisan queue:work --queue=default --once` (or your Horizon dashboard) before relying on asynchronous behavior in a new environment.
- **Restart workers after every deployment or config change** so the job classes and cached config reload in long-running worker processes:
  ```
  php artisan horizon:terminate
  ```
  (Horizon's supervisor restarts the process; if you run plain `queue:work` instead, restart that process manually — a running worker does not pick up code or config changes on its own.)
- **Shared-hosting fallback:** this project's own `.env.example` default is `QUEUE_CONNECTION=sync`, under which every queued job (Opportunity's included) executes inline, in the same request/command process that dispatched it — no separate worker process is required or possible. This is the only queue-worker-free deployment mode this codebase actually supports; no process-manager (supervisord, systemd, pm2, etc.) is introduced or assumed by this feature, because none is used elsewhere in this repository.

---

## 8. Scheduler requirement

Laravel's scheduler must be invoked once per minute by the hosting environment — this is unconditional and identical to every other scheduled command already registered in `app/Console/Kernel.php`, not something new introduced by this feature:

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

This single cron entry is the standard Laravel scheduler mechanism and is itself the shared-hosting-compatible approach (it needs no persistent process, no SSH session, and no process manager) — there is no separate or additional cron requirement for the Opportunity Engine beyond this one line, which this application's scheduler already requires for its other registered commands (`automation:run`, `app:clean-database`).

- `opportunity:sweep-expired-snoozes` is registered **unconditionally** in `Kernel.php`, at a cron step derived from `OPPORTUNITY_SNOOZE_SWEEP_MINUTES` (bounded 1–59 minutes; an invalid value falls back to 15 — never sub-minute, since `schedule:run` itself is only invoked once per minute at best).
- The command's **internal** feature-flag check (not the scheduler's registration) is what makes it a safe no-op when `OPPORTUNITY_ENGINE_ENABLED=false` — it logs "disabled; sweep skipped" and exits `SUCCESS` every time `schedule:run` fires, rather than being conditionally registered.
- The command is **bounded** (`--limit=100` default, never loops until the table is empty) and **idempotent** (each candidate row is independently re-locked and re-verified still-expired-and-snoozed immediately before being reopened, guarding a race with a concurrent explicit un-snooze; a crash mid-sweep simply leaves the remainder for the next minute's invocation).

---

## 9. Producer-job operational behavior

`RunBusinessAdvisorOpportunityProducer` (queue: §7):

- Disabled flag → logs and returns; no run is created or touched.
- Missing `Business` → logs a warning and returns.
- A healthy `running` run already active for that `(business, worker)` pair → logs and returns (a safe, expected no-op — not routed through `failRun()`).
- Any other exception during `produce()`/`stageCandidate()`/`finalizeSuccessfulRun()` → the run (if one was begun) is marked `failed` with the fixed safe summary `"We could not refresh your opportunities right now. Please try again later."`, then the original exception is re-thrown so the queue's own retry/backoff applies.
- No `Business`/`Opportunity`/`OpportunityRun` field is ever set from raw exception content — only the one fixed string above.

---

## 10. Action-execution job operational behavior

`ExecuteOpportunityAction` (queue: §7):

- Disabled flag → logs and returns; the execution row is left untouched (still `pending`).
- Missing execution row → logs a warning and returns.
- Redelivery of an execution that is no longer `pending` (already `running`/`succeeded`/`failed`) → safe no-op, proven retry-safe by the existing test suite.
- A pre-invocation mismatch (the execution's bound action/hash/schema/occurrence no longer matches the live Opportunity) → recorded as a failure with the fixed summary `"Opportunity action state no longer matched the approved action."`; the handler is never invoked.
- A handler exception during the real `BusinessManager` mutation → the mutation's own nested transaction/savepoint rolls back (no half-applied `Business` change), and the execution is recorded failed with one of two fixed summaries (`"Business update could not be verified."` or `"Opportunity action could not be completed."`, chosen by exception type) — never the raw exception message.
- Structured log context on every path: `execution_id`, and the full exception object (server-side logs only, never persisted to any customer/admin-visible column).

---

## 11. Safe rollout order

This mirrors RFC-001 §3, made concrete for this feature:

1. **Deploy code with `OPPORTUNITY_ENGINE_ENABLED` unset (or explicitly `false`).** No behavior change for any existing customer or admin.
2. **Run migrations** (§5).
3. **Refresh config cache** (§6) if your deployment process caches config.
4. **Restart queue workers / Horizon** (§7) so both job classes autoload correctly in long-running worker processes.
5. **Confirm the scheduler cron entry is present** (§8) — it is registered unconditionally, so it is already running (as a safe no-op) even before you enable the flag.
6. **Run the regression suite** (§19) against the deployed build before enabling anything.
7. **Enable internally** (`OPPORTUNITY_ENGINE_ENABLED=true` in a staging/internal-only environment) and complete the smoke-test checklist (§12–§14).
8. **Enable in production.**
9. **Monitor** `opportunity_runs.status='failed'` rates, `opportunity_action_executions.status='failed'` rates, and queue failed-jobs, per §16.

At every step, an Opportunity engine that has never been enabled leaves zero rows in any of the five tables — there is nothing to migrate away from or clean up if you decide not to proceed past step 6.

---

## 12. Smoke-test checklist

Safe, non-destructive checks only — none of these require a destructive command:

- **Feature disabled** (`OPPORTUNITY_ENGINE_ENABLED=false`): `GET /customer/opportunities`, `GET /admin/opportunities`, and `GET /admin/opportunities/runs?business_id={validBusinessId}` all return `404` for an otherwise-authorized user. `business_id` must reference a real, valid Business id even in this disabled-feature check — `AdminOpportunityRunIndexRequest` requires it, and supplying it consistently avoids conflating a validation failure with the feature-flag check.
- **Feature enabled:** the same three routes return `200` for an authorized customer/admin respectively.
- **Producer job dispatches:** `RunBusinessAdvisorOpportunityProducer::dispatch($businessId)` from `php artisan tinker` against a real Business with at least one incomplete profile field (e.g. no phone number) enqueues without error.
- **Job execution, matched to your actual `QUEUE_CONNECTION`:**
  - **`sync` (this project's own `.env.example` default):** the `dispatch()` call above already executed the job inline, synchronously, in the same `tinker` process — there is nothing queued to pick up, and no `queue:work` step applies. Confirm the new row appears in `opportunities` for that Business immediately after the dispatch call returns.
  - **`database`, Redis, or another asynchronous connection:** the job is enqueued but not yet run. A worker (or Horizon) consuming the exact configured queue name must actually be running — `php artisan queue:work --queue=default --once` (substitute your configured `OPPORTUNITY_ENGINE_QUEUE` value if it differs from `default`) — before confirming the new `opportunities` row.
- **Admin can inspect runs/candidates/transitions/executions:** as an admin with `view opportunities`, `/admin/opportunities/{id}`, `/admin/opportunities/runs?business_id={id}`, and `/admin/opportunities/runs/{run}` all render the expected diagnostic tables.
- **Customer can complete the real `add_phone` flow end to end:** configure → request approval → confirm approval → (queue worker processes `ExecuteOpportunityAction`) → poll/refresh shows `completed` with `"Business phone updated and verified."` and the Business's phone number is genuinely updated.
- **Snooze-sweep command runs successfully:** `php artisan opportunity:sweep-expired-snoozes` exits `0` and reports a count (0 is a valid, successful result).
- **Failed execution retry remains explicit and customer-only:** a `failed` execution is never automatically retried by a page refresh or a queue redelivery of a terminal (succeeded/failed) execution — only the customer's own explicit "Retry" action creates a new attempt. No admin retry route or control exists anywhere in the admin surface (see §20).

---

## 13. Customer-surface verification

- Visit `/customer/opportunities` as a customer with a primary Business — confirm the queue renders, filters by `status`/`freshness` work, and pagination preserves them.
- Open one Opportunity's detail page — confirm evidence/recommended-action/execution history render, and that no raw `recommended_action_hash`, `idempotency_key`, `action_key`, or handler/verifier identifier is ever visible.
- Configure, request approval, confirm approval, and observe the accessible polling surface (`role="status" aria-live="polite"`, exponential backoff, no infinite reload loop) through to a terminal state.
- Confirm snooze/dismiss/reopen are only offered in the states `OpportunityManager` actually permits (`open`/`awaiting_approval` for snooze+dismiss; `snoozed`/`dismissed`/`completed` for reopen).
- Confirm a customer without a primary Business is redirected to onboarding, and a cross-tenant Opportunity URL returns `404`, never another customer's data.

---

## 14. Admin-surface verification

- As an admin with `view opportunities` (see §20 for the exact double-authorization boundary), visit `/admin/opportunities` — confirm cross-tenant listing, filters, and pagination.
- Open an Opportunity detail page — confirm business/customer identity, transition history, and execution history render, with the same no-raw-JSON/no-secret redaction as the customer page (plus admin-visible internal identifiers such as execution id and idempotency key, which are customer-redacted but not admin-redacted).
- Visit `/admin/opportunities/runs?business_id={id}` and open one run — confirm staged candidates render identically for `succeeded`, `failed`, and abandoned (`failed` with `abandoned_at` set) runs.
- As an admin with `view opportunities` only (no `edit opportunities`), confirm the detail page shows zero mutation forms.
- As an admin with `edit opportunities`, confirm snooze/dismiss/reopen succeed cross-tenant and each resulting transition row has `actor_type='admin'` and `actor_user_id` equal to that admin's own user id — never the Opportunity's owning customer.
- Confirm no admin approval, configuration, execution, retry, attestation, create, or delete control exists anywhere in the admin surface (§20).

---

## 15. Logging and diagnostic expectations

Structured log context on every producer job and execution (matching RFC-001 §25's own convention): `business_id`, `opportunity_id` (where applicable), `run_id`, `worker_key`, `action_key` (where applicable), `execution_id` (where applicable) — never full request payloads, credentials, or tokens. The admin inspection screens (§14) are the primary operational tool for diagnosing a stuck or failed run or execution; no new metrics vendor or logging destination is introduced by this feature.

---

## 16. Failure/retry behavior

- **Producer runs:** a `failed` run is retained with its `safe_error_summary` and `reason_code` for admin diagnosis; nothing customer-visible is created from a failed run. The next scheduled/dispatched run for that Business proceeds independently — a prior failure never blocks future runs.
- **Action executions:** a `failed` execution is never silently retried — not by a page refresh (which only re-reads the existing row) and not by a redelivered queue message (idempotency-key-guarded, a safe no-op). Only an explicit customer "Retry" click creates a genuinely new attempt (`attempt_number + 1`) and a genuinely new queued job.
- **Expired-snooze sweep:** a single row's re-verification failure (e.g., a race with a concurrent un-snooze) is isolated and does not block the rest of that batch or the next scheduled run.
- **Queue-level retries:** both jobs use Laravel's own `tries`/`backoff` mechanism (§7); after exhausting retries, a job lands in the standard `failed_jobs` table, from which it can be inspected/retried using Laravel's existing, unmodified `queue:failed` / `queue:retry` commands — no Opportunity-specific failed-job tooling was added.

---

## 17. Rollback procedure

- **Disabling instead of rolling back:** in almost every real incident, setting `OPPORTUNITY_ENGINE_ENABLED=false` (and `config:cache`) is sufficient and far lower-risk than a code or migration rollback. It immediately stops new customer/admin route access (both return `404`), new producer/execution job dispatches, and the scheduled sweep's actual work (the command still runs on schedule but no-ops), while leaving every already-collected `opportunity_runs`/`opportunities`/`opportunity_run_candidates`/`opportunity_action_executions`/`opportunity_transitions` row intact for a later re-enable.
- **Code rollback:** safe at any point while the flag is `false` — no data cleanup is required, since a disabled engine never wrote customer-visible data other admin surfaces depend on.
- **Migration rollback — disabling the feature flag (above) remains the preferred rollback, not this.** `php artisan migrate:rollback` reverses Laravel's **entire last migration batch**, not specifically these five tables — if any unrelated migration was deployed in the same batch, a generic `migrate:rollback` removes that too. `migrate:rollback --step=N` does not make the rollback feature-targeted: `--step` rolls back the last N individual migrations in reverse execution order, regardless of which feature or migration filename they belong to, so it may still remove unrelated migrations. Before ever considering a schema rollback:
  1. Run `php artisan migrate:status` and identify exactly which migrations share the batch(es) in question.
  2. If that batch is mixed (contains anything besides the five RFC-002 migrations, §5), do not run any generic Laravel rollback command against it without an environment-specific, reviewed rollback plan and a verified database backup — this document does not provide, and does not recommend improvising, ad hoc production SQL or a hand-written rollback command.
  3. Even when the batch is clean, only proceed if you are also rolling back the code, and only after confirming you understand the consequences for any real Opportunity data already collected — **rolling back these five tables destroys every `opportunity_runs`/`opportunities`/`opportunity_run_candidates`/`opportunity_action_executions`/`opportunity_transitions` row**, irreversibly, for any data created since the tables existed. Rolling back migrations while the code is still deployed will also break the feature outright rather than gracefully degrade it.
  - **Never run `php artisan migrate:fresh`** against any environment holding real data of any kind — it drops every table in the database, not only these five.
- **Queued jobs in flight during a rollback:** both jobs re-validate the live Opportunity/execution state on every run (including retries) and safely no-op or record a safe failure rather than writing stale data — an in-flight job encountering a disabled feature or a since-changed Opportunity will not corrupt state.

---

## 18. Data-preservation rules

- Disabling the feature flag **never deletes or mutates** any existing `opportunity_runs`, `opportunities`, `opportunity_run_candidates`, `opportunity_action_executions`, or `opportunity_transitions` row — it only stops new writes and blocks HTTP/job access while disabled.
- No command, route, or job in this feature ever performs a bulk delete or truncate of any of these five tables.
- Admin has no create or delete capability over any of these tables (§20) — the only way rows are ever created is through the real producer/manager/execution flow, and the only way they are ever removed is the existing cascade-on-delete foreign keys firing if a parent `Business` itself is deleted (an RFC-001-owned operation, not introduced here).

---

## 19. Final validation commands

The complete Opportunity Engine regression suite is the existing test directories built up across Milestones 1–5 — no separate/duplicate suite exists:

```
php artisan test tests/Unit/Opportunity tests/Feature/Opportunity
```

Individual areas, if narrowing down a failure:

```
php artisan test --filter=OpportunityConfigTest
php artisan test --filter=OpportunityRepositoryTest
php artisan test --filter=OpportunityManagerFinalizeSuccessfulRunTest
php artisan test --filter=BusinessAdvisorOpportunityProducerTest
php artisan test --filter=RunBusinessAdvisorOpportunityProducerJobTest
php artisan test --filter=ExecuteOpportunityActionJobTest
php artisan test --filter=OpportunitySnoozeSweepScheduleTest
php artisan test --filter=SweepExpiredOpportunitySnoozesCommandTest
php artisan test --filter=OpportunityQueueHttpTest
php artisan test --filter=OpportunityMutationHttpTest
php artisan test --filter=OpportunityExecutionStatusHttpTest
php artisan test --filter=AdminOpportunityControllerTest
php artisan test --filter=AdminOpportunityRunControllerTest
```

Final Milestone 5 verification pass (config cache round-trip, admin/customer route registration, and the Opportunity regression suite):

```
php artisan config:clear
php artisan config:cache
php artisan route:list --name=admin.opportunities
php artisan route:list --name=customer.opportunities
php artisan config:clear
php artisan test tests/Unit/Opportunity tests/Feature/Opportunity
```

The first `config:clear`/`config:cache` pair only verifies the normal deployment config compiles and the admin/customer Opportunity routes register correctly against it — it is not a prerequisite for the test run that follows. The second `config:clear`, immediately before `php artisan test`, is required: PHPUnit must be allowed to load `.env.testing` on its own, and leaving the normal deployment config cached (`bootstrap/cache/config.php` present) at that point prevents the testing environment configuration from loading correctly and triggers Laravel's destructive-operation confirmation prompt during database-refresh tests, which fails with `BadMethodCallException: Received Illuminate\Console\OutputStyle::askQuestion(), but no expectations were specified`. Where practical, confirm `bootstrap/cache/config.php` is absent immediately before running the test command. If this deployment environment normally serves the application from a cached config, rebuild it (`php artisan config:cache`) once the test run above is finished — do not leave a cleared config cache in place afterward.

**Before running this suite:**
- Do not run the database-refresh test suite while the normal deployment configuration remains cached.
- PHPUnit must be allowed to load `.env.testing`.
- Never run these destructive database tests against the production database.
- The configured testing database must be the disposable `ultimatesms_testing` database.

As of this document, after clearing the config cache and confirming `bootstrap/cache/config.php` was absent, the last verified run of the Opportunity regression suite reported **1102 tests passed, 3827 assertions**.

---

## 20. Security summary

- **Customer tenant isolation:** every customer read/write is scoped through `findOwned()`/`findOwnedForUpdate()` (the compound `(id, business_id)` predicate) or `assertOpportunityOwnership()` — no controller ever fetches an Opportunity globally by id first.
- **Admin double authorization:** every admin route sits behind (1) the route-group's blanket `can:access backend` gate, (2) the independent, explicit `EnsureUserIsAdministrator` account-type middleware (reusing RFC-001 Milestone 6's own convention exactly), and (3) a feature-specific permission check — `view opportunities` for every read, `edit opportunities` for snooze/dismiss/reopen. Admin access is intentionally cross-tenant once authorized, exactly as it already is for admin Business access.
- **No raw exception leakage:** every domain exception a controller catches maps to one fixed, safe string — never `$e->getMessage()`, which always interpolates internal ids.
- **Safe summaries only:** `safe_error_summary`/`safe_result_summary` on runs and executions are always short, fixed, customer-safe strings; the full exception is logged internally (§15), never persisted to a customer/admin-visible column.
- **No admin create or delete:** no route, controller method, or view exists for admin creation or deletion of an Opportunity, run, or candidate.
- **No admin execution/approval controls:** admin cannot configure, request/confirm approval, execute, retry, or attest-complete an Opportunity — those remain customer-only, matching the RFC's own scope for this milestone.
- **Feature-flag behavior:** see §4 for the exact controller, manager, job, and command enforcement boundaries.

---

## 21. Milestone status record

- **Milestone 4 — complete.** Customer queue, detail, configure/approve/execute/poll, snooze/dismiss/reopen, retry, and the snooze-sweep command are implemented and tested.
- **Milestone 5 — implementation complete once this document's validation (§19) passes.** Admin inspection (index/detail/run/candidate) and admin snooze/dismiss/reopen mutation are implemented and tested; this deployment document itself is Milestone 5's final deliverable.
- **Milestone 6 — remains open.** The fake-producer conformance suite and the final RFC-002-wide release-readiness/acceptance pass (RFC §51 M6, §52) have not been started by this document or any prior slice.
- **RFC-002 remains untagged.** No Git tag has been created for RFC-002. Per the precedent set by `rfc-001-business-core` (tagged only once, at that RFC's own full completion including its admin/release-hardening milestone), RFC-002 should not be tagged before Milestone 6 concludes — this document does not create or apply any tag.

---

## 22. Out of scope

Per RFC-002 §4/§51 and this document's own scope, the following are **not** covered here and do not exist in this codebase: the fake-producer conformance suite, any SEO/Content/Sales/Reputation/Website worker, `external_verified` completion (contract-only, rejected at execution), any admin approval/configuration/execution/retry/attestation/create/delete control, and RFC-003 AI Workforce. These belong to Milestone 6 or a future RFC.
