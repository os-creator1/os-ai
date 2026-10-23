# 06 · Validation — exactly what was run

Database: disposable local MariaDB 10.11, database **`ultimatesms_testing_lane_ghl`** (a `TestDatabaseSafety`-accepted sibling), all migrations applied by `RefreshDatabase`. No external service was contacted by any test (`Http::fake`).

| Suite | Result |
|---|---|
| `tests/Unit/Migration` (planner + exporter) | **8 tests, 48 assertions, 0 failures** |
| `tests/Feature/NicheBlueprint/PhotoBoothBusinessPackV3Test` | **6 tests, 44 assertions, 0 failures** |
| `tests/Feature/NicheBlueprint/PhotoBoothBusinessPackV3InstallTest` | **3 tests, 0 failures** (real publisher + installer + adapters) |
| `tests/Feature/NicheBlueprint` (whole directory) | 323 tests · **23 failures, identical set to a clean checkout of this branch** (306 tests / 23 failures at baseline) → **0 regressions** from the adapter extension. The 23 pre-existing failures were not investigated. |
| Dry-run of the planner on the real 51-contact snapshot | 51 contacts · 47 with phone · 37 opportunities (all stages mapped, 0 unmapped) · 733 messages · 42 issues (4 no-phone, 1 DND, 37 currency-unset) |

## Coverage against the requested Phase 9 list

| Area | Status |
|---|---|
| Contact import / duplicate prevention | Planner-level only (E.164, dedupe flagging, deterministic plan). **DB writer not built**, so no import-into-MotionGrove test exists. |
| Historical conversations | Planner emits `historical`, `resend:false`, `triggers_automation:false`, original timestamp/direction/provenance (tested). **No Conversations-UI test** – writer not built. |
| Opportunity mapping | Stage/status/value mapping tested at planner level. |
| Consent / opt-outs | DND → suppressed, absence → `unknown` (tested). Runtime enforcement is the existing executors' (not re-tested here). |
| Automation execution / reply detection | Pack workflows install as drafts with correct stage triggers and branching guards (tested). **Not executed** – nothing was published or run. |
| Template merging | Test restricts merge fields to registered ones; rendering not run. |
| Tenant isolation | Not specifically tested; no new tenant-crossing code path was added (the only DB-touching change, stage resolution, filters by `business_id`). |
| Form submissions, website rendering, broken links, mobile layout | **Not run** – the website has not been generated (no staging Business). |
| Webhook auth / idempotency, phone assignment readiness | **Not run** – port-in/webhook handling is not built (see 04 §7). |
