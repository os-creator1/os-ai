# RFC-001 — Business Core: Deployment & Operations

**Companion to:** `docs/rfcs/RFC-001-BUSINESS-CORE.md`
**Scope:** Environment variables, migration order, rollout, queue operations, and verification for the Business Core feature (Milestones 1–6).

This document contains no real credentials, database identifiers, API keys, or customer data. Every value below is a placeholder or a documented safe default.

---

## 1. Environment variables

Add to your deployment's `.env` (already present as commented-out-by-default entries in `.env.example`):

| Variable | Default (if unset) | Effect |
|---|---|---|
| `BUSINESS_ONBOARDING_ENABLED` | `false` | Master switch. When `false`, the entire onboarding wizard, analysis job, and dashboard redirect middleware behave as if the feature does not exist. Existing customer routes and the registration flow are unaffected. |
| `BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS` | `false` | Only consulted when `BUSINESS_ONBOARDING_ENABLED=true`. When `true`, new registrations get a required (`is_required=true`) onboarding row and are redirected to complete it before reaching the dashboard. Never affects customers who registered before this was turned on. |
| `BUSINESS_ONBOARDING_ANALYSIS_QUEUE` | `default` | The named queue `BuildInitialBusinessSnapshot` is pushed to. `default` matches this application's own `QUEUE_CONNECTION` baseline queue, so no worker/Horizon configuration change is required unless you choose a different name. |

These are read exactly once, inside `config/business.php`, via `env()` — no other file in the Business Core feature calls `env()` directly. This is what makes the feature `config:cache` safe (see §5).

**Both boolean flags default to `false`.** Deploying this code with no `.env` changes at all reproduces the exact pre-Business-Core behavior for every existing customer and for new registrations.

---

## 2. Migration order

Run migrations in the order they were authored (Laravel's migrator does this automatically by timestamp, but the dependency order is worth stating explicitly since a manual/partial rollback must reverse it):

1. `businesses`
2. `business_locations` (FK → `businesses.id`)
3. `business_services` (FK → `businesses.id`)
4. `customer_onboardings` (FK → `users.id`, nullable FK → `businesses.id`)

Rollback reverses this order. All four tables use application-level invariants (single primary business/location/service), not database constraints beyond standard foreign keys — a rollback is a plain `php artisan migrate:rollback`, no data backfill step is required because these are net-new tables with no legacy data to reconcile.

---

## 3. Disabled-safe rollout procedure

This mirrors RFC-001 §30, made concrete for this codebase:

1. **Deploy code with both flags unset (or explicitly `false`).** No behavior change for any existing customer.
2. **Run migrations:**
   ```
   php artisan migrate
   ```
3. **Refresh config cache** (see §5) if your deployment process caches config:
   ```
   php artisan config:cache
   ```
4. **Restart queue workers / Horizon** so the new `BuildInitialBusinessSnapshot` job class autoloads correctly in long-running worker processes:
   ```
   php artisan horizon:terminate
   ```
   (Horizon's supervisor will restart the process; if you run plain `queue:work` instead, restart that process manually.)
5. **Run the regression suite** (§6) against the deployed build before enabling anything.
6. **Test voluntary onboarding internally** — with `BUSINESS_ONBOARDING_ENABLED=true` and `BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS=false`, existing customers can voluntarily open `/customer/onboarding` and `/customer/business`, but nothing redirects them there.
7. **Enable onboarding** (`BUSINESS_ONBOARDING_ENABLED=true`) in production once step 6 is verified.
8. **Test new registration with the mandatory flag still `false`** — confirm registration is unaffected and no onboarding row is force-created.
9. **Enable mandatory onboarding for new customers** (`BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS=true`) once satisfied.
10. **Monitor** analysis job failures (queue failed-jobs table) and onboarding drop-off (`customer_onboardings.status` distribution) after rollout.

At every step, an existing customer with no `customer_onboardings` row is never redirected and keeps full dashboard access — this is enforced by `EnsureRequiredBusinessOnboardingIsComplete` continuing immediately when no row exists, before it even reads the config flags.

---

## 4. Queue worker requirements

- `BuildInitialBusinessSnapshot` implements `ShouldQueue` and `ShouldQueueAfterCommit`: it is only ever pushed after the enclosing database transaction commits, and Laravel's queue layer (`SyncQueue`/`DatabaseQueue`/Horizon's Redis queue alike) honors that automatically — no additional configuration is needed for this guarantee.
- `tries=3`, `backoff=[10, 60, 300]` — a worker must be running with enough time between retries to matter; the default Horizon supervisor timeout is sufficient.
- **Queue name:** defaults to `default`, which is already watched by this application's existing Horizon supervisors (`config/horizon.php`). If you set `BUSINESS_ONBOARDING_ANALYSIS_QUEUE` to any other value, you must add that queue name to the relevant environment's `queue` array in `config/horizon.php` (or pass `--queue=<name>` to `queue:work` if not using Horizon), or the job will never be picked up.
- No external HTTP calls are made from this job — it only reads already-stored `Business`/`BusinessLocation`/`BusinessService` data, so no outbound network/firewall change is required.

---

## 5. Config cache commands

Every value `config/business.php` returns comes from a top-level `env()` call with a hardcoded literal default — no conditional logic, no request/session state. It is safe to cache:

```
php artisan config:cache
```

To verify the cached config reflects your intended flags before relying on it in production:

```
php artisan config:clear
php artisan tinker --execute="var_dump(config('business.onboarding'));"
php artisan config:cache
```

If you change any `BUSINESS_ONBOARDING_*` value in `.env` after a `config:cache`, you must re-run `php artisan config:cache` (or `config:clear`) — like any other Laravel config value, the cached copy does not pick up new `.env` values automatically.

---

## 6. Verification commands

The complete Business Core regression suite is the existing test directories built up across Milestones 1–6 — no separate/duplicate suite exists:

```
php artisan test tests/Unit/Business tests/Feature/Business
```

Individual areas, if narrowing down a failure:

```
php artisan test --filter=InitialBusinessSnapshotBuilderTest
php artisan test --filter=BuildInitialBusinessSnapshotJobTest
php artisan test --filter=OnboardingManagerTest
php artisan test --filter=OnboardingActionExecutorTest
php artisan test --filter=BusinessOnboardingHttpTest
php artisan test --filter=BusinessRepositoryTest
php artisan test --filter=BusinessManagerTest
php artisan test --filter=AdminBusinessControllerTest
php artisan test --filter=BusinessConfigTest
```

Final Milestone 6 verification pass (config cache round-trip, admin route registration, and the release-hardening test groups):

```
php artisan config:clear
php artisan config:cache
php artisan route:list --name=admin.businesses
php artisan test --filter=BusinessConfigTest
php artisan test --filter=BusinessRepositoryTest
php artisan test --filter=AdminBusinessControllerTest
php artisan test tests/Unit/Business tests/Feature/Business
```

Manual smoke checks after enabling the feature in a staging environment:

- Visit `/customer/onboarding` as an existing customer with no onboarding row — confirm it starts fresh and voluntarily (not forced).
- Complete the wizard through to Results and Complete — confirm the dashboard loads with no redirect loop.
- As an authorized admin (with the `view business`/`edit business` permissions — see §7), visit `/admin/businesses` — confirm the list, filters, and pagination render, and that editing a business and changing its status both work.
- As a customer without the `view business`/`edit business` permissions, confirm `/admin/businesses` is inaccessible.

---

## 7. Admin permissions

Two new permission keys were added to `config/permissions.php` (existing app convention — every key in that file becomes a `Gate::define()` ability automatically, resolved through the application's existing `AccountRepository::hasPermission()` check):

- `view business` — permits `index` and `show` (list and view Business records).
- `edit business` — permits `edit`, `update`, and the status-update action (edit identity, change status).

**Deploying this code does not automatically grant either permission to any admin, including existing roles.** A permission key merely becomes available for assignment — it starts unassigned everywhere. Ordinary customers remain blocked before either of these permissions is even considered, by the existing `can:access backend` gate already applied to the whole admin route group; `view business`/`edit business` are a second, narrower check on top of that for admins who do have backend access.

Administrators must assign `view business` and `edit business` to the appropriate role(s) using the application's **existing** permission-management screen (Admin Roles), the same way every other permission in `config/permissions.php` is assigned today. This document does not introduce, and this milestone does not implement, any new role system, seeder, or CLI command for granting permissions — none exists in this codebase, so none was added. After rollout, verify permission assignment by checking the relevant admin role(s) in that screen and confirming `view business`/`edit business` are checked only for the roles intended to manage Business records.

---

## 8. Rollback considerations

- **Code rollback:** safe at any point — with both flags left at their `false` defaults (or simply unset), rolling the code back to pre-Business-Core behavior requires no data cleanup, since existing customers were never forced through onboarding and no other module reads Business Core data yet.
- **Migration rollback:** `php artisan migrate:rollback` reverses cleanly in one step per Milestone-1 acceptance criteria (all four tables roll back without leaving orphaned data, since nothing outside this feature references them). Only do this if you are also rolling back the code — rolling back migrations while the code is still deployed will break the feature, not gracefully degrade it.
- **Disabling instead of rolling back:** in almost every real incident, setting `BUSINESS_ONBOARDING_ENABLED=false` (and `config:cache`) is sufficient and much lower-risk than a migration rollback — it immediately stops new onboarding rows, dashboard redirects, and analysis dispatches, while leaving already-collected `Business`/`BusinessLocation`/`BusinessService`/`CustomerOnboarding` data intact for a later re-enable.
- **Queued jobs in flight during a rollback:** `BuildInitialBusinessSnapshot` re-validates the onboarding's status and analysis version on every run (including retries) and safely no-ops or marks a safe failure rather than writing stale data — an in-flight job encountering a disabled feature or a since-completed/dismissed onboarding will not corrupt state.

---

## 9. Out of scope

Per RFC-001 §4 and the Milestone 6 scope, this deployment does not include, and this document does not cover: Opportunity Engine, AI-generated recommendations, website crawling/external SEO auditing, Google API integrations, or any admin Business creation/deletion capability — none of these exist in this codebase yet.
