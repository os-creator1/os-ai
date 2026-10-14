# ONBOARDING FINAL REVIEW — HANDOFF (REVIEW WORK ONLY — NEVER MERGE)

This branch (`handoff/onboarding-final-review`) exists only to carry review notes and throwaway reproduction tests.
It is branched from the feature SHA under review. **Do not merge, PR or deploy it.** `agent/onboarding-seo-flow` and `staging` are untouched.

## 1. SHAs
- Feature branch `agent/onboarding-seo-flow` under review: `5e3cfc905ad12e8738edd90391e3f407561ee204`
- Previous reviewed feature SHA: `7fd2909c7873c46a5f443fa4d83e265b6a4c56b8`
- Current `origin/staging` (fetched at handoff): `3a5296b671de655b2578ccff3a444463520f75d0` (merge of PR #456)
- Merge base of feature and staging: `51020fd56647b9a7edd93d1f6e3cb18a01432285`
- Merged worktree used: `/tmp/claude-0/merged` = feature `5e3cfc90` + uncommitted `git merge --no-commit origin/staging` (auto-merged cleanly; auto-merged `app/Library/Navigation/CustomerMenuBuilder.php` and `routes/customer.php`; no conflicts). Staging worktree: `/tmp/claude-0/stg` = `3a5296b6`. These paths are ephemeral and may not survive the session transfer.

## 2. Objective and scope (from the user)
FINAL read-only review before an integration PR. Primary focus: the Business Services editor (diff `7fd2909c..5e3cfc90`), 8 checks:
1. Secondary Business can add/rename/activate/deactivate/choose primary BusinessService in its own editor.
2. Home's missing-Service action opens the correct functional editor (never another Business's wizard).
3. Permissions: owners/Workspace Admins allowed, staff denied; agency view-as, inactive Workspace, cross-Business.
4. `BusinessServiceManager` reuses canonical persistence; no duplicate Service/Catalog model; ids/slugs/descriptions/Website-SEO links stable.
5. Idempotent add (case/spacing); concurrent adds/primary changes: no duplicates, no deletions, never >1 active primary.
6. Editor lock compatible with onboarding lock order (editor vs onboarding Services vs onboarding Finish).
7. No-primary Business becomes foundation-ready after add; deactivating primary yields correct readiness/action.
8. Settings nav, route names, authorization conventions, DesignSystem match the app; new and established Businesses.
Plus targeted re-check of earlier contracts (Business-scoped `seo_first_v1`, no retroactive legacy reclassification, five-step atomic onboarding, external audit validity, real form readiness, actor-independent readiness, unknown-status fail-safe, onboarding lock order, conditional geo validation, Dashboard query budget), and merged-tree vs isolated-staging comparison with failure attribution (staging / earlier feature commit / new editor / merge-only).
Final report format required: 1 P0/P1/P2 findings with file:line (or none); 2 editor correctness+authorization verdict; 3 concurrency+data-integrity verdict; 4 customer journey (first and secondary Business); 5 new regressions vs isolated staging; 6 migration/integration readiness; 7 APPROVE or CORRECTIONS REQUIRED. Restrictions: no code edits, commit, push (to feature), subagents, PR, merge, deploy, real AI calls. Do not suggest unrelated features.

## 3. Services editor architecture (read in full)
- `app/Library/Business/BusinessServiceManager.php`: `add/rename/activate/deactivate/makePrimary`; all via private `mutate()` = `DB::transaction(...,3)`: `BusinessRepository::findForUpdate` (Business row lock FIRST, line ~146) → `canManage` re-check (line ~152, throws `AuthorizationException`) → read `$locked->services()` → build full row list `[id,name,status,sort_order,is_primary]` → apply change → `BusinessManager::syncServices($owner,$locked,$rows)` (line ~171) → `EloquentBusinessServiceRepository::syncForBusiness` (never deletes; unlisted → inactive; `resolvePrimary` guarantees one active primary; stable slug; description/price untouched because not in rows). `BusinessServicesSynced` is `ShouldDispatchAfterCommit` → listener `TriggerBusinessAdvisorProducer@handleBusinessServicesSynced` runs only after outer commit.
- Authority = `BusinessLocationManager::canManage` (view-as aware via `BusinessRouteAccess::viewedBusinessIdFor`; else `userCanAccessBusiness` + Workspace owner or active Admin).
- `app/Http/Controllers/Customer/Business/BusinessServicesController.php`: `resolveBusiness` = `WorkspaceRepository::findByUid`, workspace `is_active`, `businessesForWorkspace`, `BusinessRouteAccess::actorMayUseBusinessRoute` → 404 otherwise; service resolved by uid inside the Business → 404; `InvalidArgumentException` → flash_error; `AuthorizationException` is rethrown (app renders it as **401**, same as Locations).
- Routes `customer.workspaces.businesses.services.{index,store,rename,activate,deactivate,primary}` (routes/customer.php next to the locations group, writes `throttle:60,1`). Route has `businessUid` so it classifies as BusinessScoped for view-as; route names do not match `ViewAsProhibitedActions::DELETING_SUFFIXES`.
- Settings hub: `CustomerMenuBuilder::businessSettingsSections` adds 'services' item (permission `access_backend`) after 'locations'; `BUSINESS_SETTINGS_ROUTES` + `SETTINGS_DESCRIPTIONS` updated.
- Home: `app/Library/Dashboard/Home/HomeCommandCenter.php:491` `'service' => $context->canManageWorkspace() ? DashboardLinkGate url(services.index) : null`, label `Add service`. `WorkspaceCandidate::canManage()` = owner or active Admin (matches manager's rule for ordinary tenancy).

## 4. Home → editor → readiness (evidence)
- Implementer's tests + Chromium smoke (not re-run by me): secondary Business Home shows "Add service" → `/workspaces/…/businesses/…/services`, no wizard link; add → primary; Home step disappears.
- Executed by me (probe, merged tree): after the owner deactivates the only primary, Home again shows "Add your primary service" and the link to the editor (PASS).

## 5. Policy / legacy
Not re-reviewed beyond reading the diff: the diff touches none of `HomeSetupJourney` policy logic, `BusinessRepository` policy assignment, migration, or `DashboardStatusReader`. Implementer's `BusinessReadinessPolicyTest` (9) / `HomeLaunchReadinessTest` (29) pass on the feature tree (my own runs on the original tree, before the review phase). NOT yet independently confirmed on the merged tree.

## 6. Persistence / concurrency findings
- Static read: row locking, idempotent name key (`mb_strtolower` + whitespace collapse), reactivate-on-match, rename clash check, primary promotion by `resolvePrimary` fallback (sort_order,id), no deletion path. No defect found statically.
- Executed (original feature tree, earlier in session): `BusinessServicesEditorConcurrencyTest` 2/2 pass with real parallel processes (identical adds; mixed deactivate/primary/add), runner counts rollbacks so a hidden deadlock retry would show.

## 7. Lock ordering (static analysis only — NOT executed)
Onboarding: onboarding row → Workspace → Business → services. Editor: Business → services only; it takes no onboarding or Workspace lock, its authority reads are non-locking, and the event fires after commit. No cycle is possible from this analysis. The requested explicit cross-test (holder locks Business; queue stale onboarding Services submit + editor add + Finish; assert all OK and 0 rollbacks, like `tests/Feature/Business/OnboardingServicesConcurrencyTest.php` + `Support/concurrent_onboarding_services_runner.php` with a new mode calling `BusinessServiceManager`) was NOT written/run.

## 8. Tests already run (all on the ORIGINAL feature tree before the review phase, by the implementer role; sequential, disposable MySQL 8 `ultimatesms_testing`)
BusinessServicesEditorTest 14; BusinessServicesEditorConcurrencyTest 2; OnboardingServicesConcurrencyTest 4; OnboardingSeoFlowTest 38; BusinessReadinessPolicyTest 9; HomeLaunchReadinessTest 29; HomeCommandCenterTest 17; DashboardQueryBudgetTest 7; BusinessHomeNextBestMoveTest 19; BusinessOnboardingHttpTest 28; three Onboarding DesignSystem tests 10/15/8; AgencyViewAsOperationalAuthorityTest 17; CustomerAccountAccessGateTest 26; tests/Unit/Dashboard 40 — all pass.
Failing (all reproduced identically with the editor change stashed, i.e. exist on the previous feature commit): `SettingsHubNavigationTest::test_core_and_growth_get_a_flat_sidebar_with_one_direct_settings_destination`, `BusinessLocationHttpTest::test_the_owner_sees_capacity_in_plain_language_and_can_add_a_location`, `CustomerNavigationTreeTest::test_the_query_count_does_not_grow_with_the_number_of_features`. **Not yet checked on untouched staging** (`/tmp/claude-0/stg`).
Not run by anyone: `tests/Feature/Security/ViewAsRouteBoundaryTest.php`, `CatalogRouteInventoryTest`, other `getRoutes()` inventory tests, `WorkspaceBusiness*` DS tests, `CustomerShellNavigationTest`, SettingsHubNavigationTest on the merged tree (staging commit `f1d036ef` "Settings hub: grouped two-column card layout" may interact with the new hub item), anything on the merged tree except the probe.

## 9. Temporary probe tests (`review-probe/ReviewProbeTest.php.txt`; stored as .txt so phpunit won't pick it up)
Copy to `tests/Feature/ReviewProbe/ReviewProbeTest.php` in the merged worktree; run `php vendor/phpunit/phpunit/phpunit tests/Feature/ReviewProbe` (prints `PROBE ...` lines to STDERR; asserts nothing, observation only).
- `test_admin_scope_inactive_workspace_and_deactivate_home` — EXECUTED on merged tree, results:
  - Workspace Admin with Selected scope NOT assigned to the Business: GET index 404, POST store 404 (PASS: isolation).
  - Same Admin after `assign()`: POST store 302, service row created (PASS: Admin authorised).
  - Owner deactivates the only primary: Home shows "Add your primary service" with the editor link (PASS).
  - Workspace `is_active=0`: GET 404, POST 404 (PASS).
- `test_view_as_client_business` — NOT successfully executed. First run: TypeError (positional args; since fixed to named args). Second run: MySQL "Connection refused" (the database server died with the worker restart; restart `mysqld` — see section 13). It records: client policy, view-as GET index status, Home state/service link, view-as store status, store against the agency's own Business while viewing, mismatched Workspace/Business pair, and an unrelated foreign Business. Expected per code reading: client index 200, client store 302, own-business store 404, mismatch 404, foreign 404. UNVERIFIED.

## 10. Suspected defects (none confirmed as P0/P1)
- **P2 (confirmed by reading, not behaviour):** `resources/views/customer/business/services/index.blade.php` lines 30–36, 48–69 use raw Bootstrap `btn`/`form-control`/`badge` classes and inline `style=` instead of the `x-button` / `x-input` / `x-badge` design-system components that the sibling Locations screens use (`resources/views/customer/business/locations/index.blade.php`, `create.blade.php`). No test enforces it. Cosmetic/consistency only; do not escalate unless a DS guard exists on the merged tree.
- **Possible P2 (UNVERIFIED):** `HomeCommandCenter.php:491` gates the action on `$context->canManageWorkspace()` (frame Workspace owner/admin). During an Agency View As of a client Workspace the real actor is not owner/admin of that Workspace, so Home likely shows the missing-Service step with NO action, although the editor routes and `BusinessServiceManager::canManage` do authorise the viewed Business. Previously that case got a Settings link that could not fix the requirement either. Needs the executed view-as probe to confirm; weigh as P2 at most.
- Staff denial renders 401 (app convention, same as Locations) — not a defect.
- Edge noted, not a defect: `BusinessServiceManager::mutate` does `Customer::where('user_id',$business->customer_id)->firstOrFail()`; a Business whose `customer_id` has no Customer row would 404/500 on write. Unverified whether any creation path produces that.

## 11. Claims NOT verified
Everything in section 8 was run before the review phase and only on the feature tree; nothing was re-verified on merged or staging trees. Lock-order safety is by reading only. View-as behaviour unexecuted. Query-budget on the merged tree unexecuted. Migration safety was verified in earlier rounds only (populated DB rollback/up keeps NULL policy) and the editor adds no migration.

## 12. Remaining work for a verdict
1. Restart MySQL; run `ReviewProbeTest::test_view_as_client_business`; decide the view-as Home-action question.
2. Write + run the explicit editor × onboarding Services × Finish lock-order race (section 7).
3. On the isolated merged tree run sequentially: Business/, DesignSystem/ (all), Dashboards/, tests/Unit/Dashboard, CustomerShell/, Navigation/, Security/ViewAsRouteBoundaryTest + AgencyViewAsOperationalAuthorityTest, Workspace/, Catalog route inventory, PlatformBilling V1Signup* tests, Entitlement/CustomerAccountAccessGateTest.
4. Run the same list on isolated untouched staging (`3a5296b6`) and classify each failure: staging-only / earlier feature commit / new editor / merge-only. Specifically check whether the 3 known failures exist on staging, and whether staging's settings-hub layout change breaks `SettingsHubNavigationTest` expectations for the new `services` module on the merged tree.
5. Produce the 7-part final report.

## 13. Recreate the isolated worktrees (never share `vendor/` with staging)
Earlier shared-vendor symlinks made the staging worktree load the FEATURE branch's classes and invalidated comparisons. Rules: each worktree gets its own `vendor/composer` + `vendor/autoload.php`, its own copy of `vendor/vonage` and of `vendor/phpunit/phpunit`, regenerated autoload, then verify class paths.
```
git fetch origin staging agent/onboarding-seo-flow
git worktree add --detach /tmp/claude-0/stg origin/staging
git worktree add --detach /tmp/claude-0/merged 5e3cfc905ad12e8738edd90391e3f407561ee204
(cd /tmp/claude-0/merged && git merge --no-commit --no-ff origin/staging)
bash review-probe/mkvendor.sh.txt /tmp/claude-0/stg     # script saved here; needs /home/user/os-ai/vendor present (composer install first if not)
bash review-probe/mkvendor.sh.txt /tmp/claude-0/merged
# then, in each worktree: replace the vendor/phpunit symlink with a real dir: copy phpunit/phpunit, symlink its siblings
cp /home/user/os-ai/.env <worktree>/.env      # untracked; never commit
# verify: php -r 'require "vendor/autoload.php"; echo (new ReflectionClass("App\\Library\\Business\\OnboardingManager"))->getFileName(), PHP_EOL, (new ReflectionClass("PHPUnit\\Framework\\TestCase"))->getFileName();'
# staging must NOT have App\Library\Business\BusinessServiceManager; merged must.
# run with: php vendor/phpunit/phpunit/phpunit <path>      (not vendor/bin/phpunit)
```
Database: local MySQL 8.0.46, DB `ultimatesms_testing` only (`TestDatabaseSafety` enforces). One run at a time — a concurrent second phpunit run corrupted the shared DB once already. If mysqld is down: remove stale `/var/lib/mysql/*.pid`, `*.lock`/`mysqld.pid` files then `mysqld_safe &`. Use the `ums` DB user from `.env` (root uses socket auth). Use bracketed `ps | grep "[x]"`, never `pkill -f`, in this shell.

## 14. Restrictions (unchanged)
Independent review only; no production edits; no commit/push to `agent/onboarding-seo-flow` or `staging`; no PR, merge or deploy; no subagents; no real AI calls; report only what was actually run.
