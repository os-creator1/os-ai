# Design System M2 Slice 3 — Dashboard Security Remediation Contract

**Status: contract only. No implementation has occurred under this document. Implementation requires its own separate, explicit human authorization. Merging this contract does NOT satisfy the Slice-3 prerequisite named in `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-CONTRACT.md` §0/§7 — that prerequisite is satisfied only when this remediation's own implementation is separately human-merged (§19).**

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice3-dashboard-security-contract`, in the isolated linked worktree `../design-system-m2-slice3-contract-worktree`, based on `origin/main` at `5a629c6270be4d23dcf2cfec4fe8b4573cf3eb2b` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` at the start of this drafting pass. This SHA is PR #170's merge commit (`Merge pull request #170 from os-creator1/chore/design-system-m2-slice3-contract`), which merged the Design System M2 Slice 3 Dashboards contract itself (including its own Correction Round 1, commit `d338066`). No other work has landed on `main` since.
- Neither this contract's file nor its branch existed before this pass: confirmed via `git show origin/main:docs/automation/DESIGN-SYSTEM-M2-SLICE-3-DASHBOARD-SECURITY-REMEDIATION-CONTRACT.md` (fails, absence confirmed) and `git branch -a | grep dashboard-security` (empty) before this branch was created.
- This is the mandatory prerequisite named by the merged Slice-3 contract's §0/§7/§11: *"a separate, dedicated dashboard-security-remediation contract must be drafted, human-reviewed, and human-merged, and its own implementation must likewise be human-merged, before Slice 3 implementation may be authorized."* This document is that contract. It does not authorize Slice 3 implementation, does not authorize its own implementation, and does not authorize any other RFC or initiative.
- `maximum_correction_rounds: 2` applies to this contract, `advance_automatically: false`, `start_automatically_after_contract_merge: false` (§19).
- This contract's own scope is strictly the three-controller/route defect documented in the merged Slice-3 contract's §3.9/§7 — `HotLeadController`, `AiAnalyticsController`, `AiSettingsController`, and the routes that reach them. It does not become a whole-application security audit (§17).
- Drafting this contract makes **zero** application changes. Only the one new file named in §2 is touched by this branch.
- **Correction Round 2 (this pass, the final allowed round).** Independent review of the completed-but-unmerged implementation (branch `agent/design-system-m2-slice3-dashboard-security`) found the production security code correct and within the exact 9-path allowlist, but exposed two contract defects that must be corrected before the implementation may be approved: (A) §12/§14's binding response-code claims (302 redirect / 403 forbidden) do not match this repository's actual, verified exception-rendering behavior — `app/Exceptions/Handler.php` renders both `AuthenticationException` and `AuthorizationException` as HTTP 401 via `errors.401` whenever `config('app.env') != 'local'`, which includes the PHPUnit `testing` environment and ordinary non-local production; (B) §14 as written could be read as authorizing `markTestSkipped()` for the core tenant-isolation/data-mutation assertions merely because the legacy AI schema (`ai_settings`, `ai_box_campaign_map`, several `chat_boxes` AI-related columns) has no tracked migration — defeating §14's own stated purpose of proving real HTTP/data-outcome behavior. Both are corrected below (§12, §14, §15, §16, §18, §20) without changing the 9-item allowlist (§13), the authorization architecture (§7), the middleware design, the permission set, or the actor model (§6). This is the second and final correction round this contract's own `maximum_correction_rounds: 2` permits.

---

## 1. Mandatory preflight — verified

1. `git fetch origin --tags` — `origin/main` confirmed at exactly `5a629c6270be4d23dcf2cfec4fe8b4573cf3eb2b`.
2. No local or remote branch named `chore/design-system-m2-slice3-dashboard-security-contract`, and no file named `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-DASHBOARD-SECURITY-REMEDIATION-CONTRACT.md` on `origin/main`, existed before this branch was created (§0).
3. Read in full before drafting: `CLAUDE.md`, `AGENTS.md`, `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-CONTRACT.md` (the full, merged, corrected contract — §3.9's exact evidence and §7's exact prerequisite text), `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`, and current `docs/automation/AI-AUTONOMY-STATE.json` (confirmed idle: `active_pull_request: null`, `status: "rfc_005_complete_tagged"`; unrelated to and untouched by this track).
4. Re-audited, directly, the current source at this contract's own base SHA — not assumed from the merged Slice-3 contract's own prose, though every finding independently re-confirms it (§3 below cites current file:line evidence throughout).

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**: `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-DASHBOARD-SECURITY-REMEDIATION-CONTRACT.md` (this document). No `resources/`, `app/`, `database/`, `routes/`, or `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting this contract.

---

## 3. Mandatory repository audit — findings

All findings below are from direct repository inspection at `origin/main` `5a629c6270be4d23dcf2cfec4fe8b4573cf3eb2b`, this drafting pass.

### 3.1 The exact mechanism — re-confirmed unchanged from §3.9 of the merged Slice-3 contract

`app/Providers/RouteServiceProvider.php` (`mapWebRoutes()`) registers five groups:

| Route file | Middleware | Namespace | Name prefix |
|---|---|---|---|
| `routes/web.php` | `web` only | `App\Http\Controllers` | none |
| `routes/public.php` | `web` only | `App\Http\Controllers` | none |
| `routes/admin.php` | `web, auth, can:access backend, ValidProduct, twofactor` | `...\Admin`, prefixed `config('app.admin_path')` | `admin.` |
| `routes/auth.php` | `web, twofactor` | `App\Http\Controllers` | none |
| `routes/customer.php` | `web, auth, can:access_backend, ValidProduct, twofactor` | `...\Customer` | `customer.` |
| `routes/plugin.php` | `web, auth, can:access backend, ValidProduct, twofactor` | `...\Admin`, prefixed `config('app.admin_path')` | `admin.` |

All six routes reaching the three affected controllers are declared directly in `routes/web.php` (lines 14, 17, 18, 23-24, 65-66, 80-81) — carrying **only** the bare `web` middleware (session/CSRF), confirmed by direct read of the file (§3.5 below has the exact inventory). This is the unaltered mechanism §3.9 of the merged Slice-3 contract already documented, re-confirmed unchanged at this SHA.

### 3.2 The permission architecture actually governing this repository

`app/Providers/AuthServiceProvider.php::boot()` (lines 47-59) dynamically registers a Laravel `Gate::define($key, ...)` for **every** key in `config('permissions')` (admin-account permissions) and **every** key in `config('customer-permissions')` (customer-account permissions), each resolving through the single shared `EloquentAccountRepository::hasPermission(User $user, string $name)` (`app/Repositories/Eloquent/EloquentAccountRepository.php:203-227`):

- `$user->id === 1` → always `true` (confirmed super-admin bypass, §3.8 of the merged Slice-3 contract, unchanged).
- Customer accounts (`$user->is_customer`) → permissions resolved from `json_decode($user->customer->permissions, true)` — a per-customer-account JSON permission list.
- Admin/staff accounts (`$user->is_admin`) → permissions resolved from `$user->getPermissions()`.

`config('customer-permissions')` defines `access_backend` (line 11) and `chat_box` (line 268) as customer-account permission keys. `config('permissions')` defines `access backend` (line 11, admin) and `manage ai_settings` (line 414, admin) as separate, distinctly-named admin permission keys. **`access_backend` (customer) and `access backend` (admin) are two different Gate names resolving through two different permission lists** — this is not a typo to correct; it is the existing, working, dual-gate architecture every other customer route and every other admin route in this repository already relies on.

### 3.3 Actor-model determination — Hot Leads and AI Analytics are genuinely customer-facing, not admin-only

Direct read of `app/Helpers/Helper.php::menuData()` (called exactly once, from `app/Providers/MenuServiceProvider.php:31`, which itself documents the array's own shape: `['admin' => [...], 'customer' => [...]]`) confirms the top-level array keys at line 553 (`'admin' => [`) and line 944 (`'customer' => [`). The Hot Leads and AI Analytics menu entries (lines 1232-1249) are both located **after** line 944 — inside the `'customer'` array, not the `'admin'` array — placed immediately after the customer's own `Chat Box` entry (`url('chat-box')`, line 1224-1231):

```
['url' => url('chat-box'),        'name' => 'Chat Box',     'access' => 'chat_box'],
['url' => url('admin/hot-leads'), 'name' => 'Hot Leads',    'access' => 'chat_box'],
['url' => url('admin/ai-analytics'), 'name' => 'AI Analytics', 'access' => 'chat_box'],
```

All three share the identical `'access' => 'chat_box'` gate — the same customer-permission key already used, and already `$this->authorize('chat_box')`-enforced, by the repository's own existing, correctly-implemented, already-tested `App\Http\Controllers\Customer\ChatBoxController` (`app/Http/Controllers/Customer/ChatBoxController.php` lines 55, 107, 163, 403). This is direct, first-party evidence: the `admin/` path segment in these two controllers' URLs and namespace is a **naming/placement artifact only** — the human-observable, menu-driven intent is that these are customer-tenant features, an extension of the customer's own Chat Box/Conversations feature, gated the same way. No admin-side menu entry, anywhere in the `'admin'` array or any other view, links to either page (confirmed by repository-wide grep for `admin/hot-leads`, `admin/ai-analytics`, `hot-leads`, `ai-analytics` across `app/` and `resources/views/` — the only matches are the customer-menu entries above and the two views' own internal self-references).

**Conclusion: Hot Leads and AI Analytics are genuinely customer-facing operational surfaces and must remain reachable by an authenticated customer-tenant account holding the `chat_box` permission — not converted to admin-only.**

### 3.4 Actor-model determination — AI Settings/AI Brain is genuinely a platform-wide, admin-only configuration surface

Repository-wide grep for `ai-brain` and for `admin.ai-settings`-style references (`app/`, `resources/views/`, `routes/`) finds **zero menu link, zero button, zero anchor anywhere** pointing at `GET /admin/ai-brain`. It is reachable only by a visitor who already knows or guesses the literal URL — it is not wired into either the customer menu or the admin menu.

`admin/ai-settings.blade.php` (rendered by `AiSettingsController`) is confirmed, per the merged Slice-3 contract's own §3.6 Finding 1 (re-verified directly this pass), to be an entirely separate surface from `resources/views/admin/settings/AllSettings/ai-settings.blade.php` (rendered by `SettingsController::aiSettings()`, route `settings.ai-settings`, under the full `routes/admin.php` group, gated by `can:access backend` **plus** an explicit `$this->authorize('manage ai_settings')` call, `app/Http/Controllers/Admin/SettingsController.php:884`). That properly-authorized sibling surface toggles `OPENAI_ACTIVE` via `AppConfig::setEnv()` — a different concern from `AiSettingsController`'s own `ai_settings` database table (`system_prompt`, `model` columns), but the **same permission family**: `manage ai_settings` already exists (`config/permissions.php:414`, `'display_name' => 'ai_settings', 'category' => 'Settings'`) specifically to govern AI-configuration administration in this repository, and `app/Http/Requests/Settings/OpenAISettingsRequest.php` already demonstrates the exact working `FormRequest` pattern for a `model`-field AI-settings write: `authorize()` returns `$this->user()->can('manage ai_settings')`; `rules()` validates `model` (among others) as `required|string`.

No migration file anywhere in this repository creates the `ai_settings` table (confirmed by repository-wide search — `grep -rl "ai_settings" database/` returns nothing, and no `.sql` file exists in the tracked repository at all). `AiSettingsController::index()`'s `DB::table('ai_settings')->first()` (no `where` clause of any kind) and `save()`'s unconditional `DB::table('ai_settings')->update([...])` (no `where` clause) are the only evidence of this table's shape in the codebase; both calls are consistent only with a single, global, platform-wide configuration row — not a per-tenant table (there is no candidate ownership column anywhere in the controller's own code, and no migration exists to check against). **This is recorded honestly as a schema-visibility limit, not resolved by invention (§3.7 below); it does not block this remediation, since the fix required is authorization, not a schema-dependent query change.**

**Conclusion: AI Settings/AI Brain is genuinely a single, platform-wide configuration surface, with no evidence it was ever intended to be customer-reachable. The narrowest existing, already-established administrator permission that fits it is `manage ai_settings` — the same permission already governing the adjacent, correctly-authorized AI-configuration surface. Ordinary tenant users must not gain access merely because Hot Leads/AI Analytics are customer-facing (§3.3) — this contract does not force one authorization model across all three surfaces.**

### 3.5 Endpoint/action inventory — exhaustive, every route to all three controllers

| # | Method + Path | Route name | Controller action | `routes/web.php` line |
|---|---|---|---|---|
| 1 | `GET /admin/hot-leads` | (unnamed) | `HotLeadController::index` | 17 |
| 2 | `POST /admin/hot-leads/mark-called` | (unnamed) | `HotLeadController::markCalled` | 18 |
| 3 | `GET /admin/ai-analytics` | (unnamed) | `AiAnalyticsController::index` | 14 |
| 4 | `POST /admin/ai-analytics/book/{id}` | `admin.ai.booked` | `AiAnalyticsController::markBooked` | 65-66 |
| 5 | `POST /admin/ai-variants/update` | `admin.ai_variants.update` | `AiAnalyticsController::updateVariants` | 23-24 |
| 6 | `GET /admin/ai-brain` | (unnamed) | `AiSettingsController::index` | 80 |
| 7 | `POST /admin/ai-brain` | (unnamed) | `AiSettingsController::save` | 81 |

**Row 5 is confirmed dead, not exploitable, and deliberately excluded from this remediation's own change set** (§17): `AiAnalyticsController::updateVariants()` does not exist anywhere in the class (confirmed by direct grep of the full controller source, re-confirmed this pass — the class has exactly two public methods, `index` and `markBooked`). Invoking this route always throws a fatal `Error` (undefined method) before any data access occurs, **regardless of authentication state** — it carries zero present exploitability, and it is not linked from any view. Per this task's own instruction ("if an adjacent route is dead/broken rather than exploitable... decide whether it belongs in the remediation based on direct security relevance. Do not opportunistically clean unrelated dead code."), adding middleware to a route that can never execute past a fatal error either way would not close any real exposure — it is recorded here accurately and left untouched, remaining a separately-deferred hygiene item (§17), not part of the 6 routes this contract's allowlist (§13) remediates.

**Affected/remediated endpoint count: 6** (rows 1-4, 6-7). Row 5 is audited, confirmed non-exploitable, and explicitly out of this contract's own change set.

### 3.6 Data-isolation trace — the existing, working, already-correct precedent this remediation reuses

`database/migrations/2021_03_31_125855_create_chat_boxes_table.php` confirms `chat_boxes.user_id` (`unsignedBigInteger`, `foreign('user_id')->references('id')->on('users')->onDelete('cascade')`) — a real, migrated, FK-enforced ownership column. `database/migrations/2021_03_01_062609_create_campaigns_table.php` confirms the identical pattern for `campaigns.user_id`.

`App\Http\Controllers\Customer\ChatBoxController::index()` (lines 62-66) already scopes its own `chat_boxes` read against exactly this column: `ChatBox::where('user_id', Auth::id())->where('pinned', true)->...->get()`. **This is the directly analogous, already-shipped, already-tested precedent this remediation reuses verbatim** — the same table, the same ownership column, the same `Auth::id()` scoping idiom, already proven correct in production for the very feature (Chat Box/Conversations) that Hot Leads and AI Analytics are a menu-adjacent extension of (§3.3). This remediation does not invent a Workspace/Business-abstraction scoping layer; it mirrors the one already established and working for this exact table.

**A genuine schema-visibility gap, recorded honestly, not fabricated over:** no migration file anywhere creates the `ai_box_campaign_map` table (used via raw `DB::table('ai_box_campaign_map as map')` joins in `AiAnalyticsController::index()`, columns `box_id`/`campaign_id` only, both already proven to exist functionally by the controller's own working `leftJoin` usage). Likewise, `chat_boxes.ai_stage`, `.called`, `.website_sent_at`, `.followup_sent`, `.followup_at`, `.reply_by_customer` (this last one *is* migrated — `2023_05_07_163338_add_reply_by_customer_to_chat_boxes.php`), and `.ai_replied` have no migration defining most of them, despite being read/written throughout both controllers. **This remediation does not depend on knowing these undocumented columns' exact types** — every fix in §8/§9/§13 below adds a `WHERE user_id = ?` clause to an existing query or an existing single-row update, using only the one column (`user_id`) that is mechanically proven to exist via a real migration on both tables involved. No schema change of any kind is authorized or required by this contract (§20 confirms zero schema-blocking gap for the remediation actually being authorized).

### 3.7 Existing validation infrastructure — the exact, directly reusable precedent

`app/Http/Requests/Settings/OpenAISettingsRequest.php` (full file, 27 lines) is the established repository pattern for validating an AI-settings write: a dedicated `FormRequest` under `app/Http/Requests/<Area>/`, `authorize()` delegating to `$this->user()->can('<permission>')`, `rules()` a flat array of `required|string`/`nullable|string` rules. `app/Http/Requests/ChatBox/SentRequest.php` demonstrates the identical pattern gated by `chat_box`: `return $this->user()->can('chat_box')`. **This remediation's two new `FormRequest` classes (§13 items 5-6) are built directly from these two existing, already-shipped classes' own shape** — not an invented validation architecture.

`app/Http/Requests/Admin/` already exists as a populated directory (14 existing classes, e.g. `IssueManualWalletCreditRequest.php`, `ActivatePlatformThemePresetRequest.php`) using a consistent verb-first naming convention — the two new classes this remediation adds follow that same directory and naming convention.

### 3.8 Existing test coverage — exhaustive, re-confirmed

Repository-wide, targeted search for `HotLead`, `AiAnalytics`, `AiSettings`, `ai-brain`, `hot-leads`, `ai-analytics`, `manage ai_settings`, and `OpenAISettings` inside `tests/` finds **zero existing test coverage of any kind** for any of the three controllers, their routes, or the `manage ai_settings`/`chat_box` gates as applied to them — confirmed unchanged from the merged Slice-3 contract's own §3.10 finding. `chat_box`/`ChatBox` matches exist only in `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` and `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php` — unrelated metering tests, not authorization tests, and not modified by this contract. No existing test requires updating; every test this remediation adds is new (§14).

`tests/Feature/` has no `Security/` directory today (existing top-level directories: `Auth`, `Branding`, `Business`, `Entitlement`, `Installer`, `Opportunity`, `Theme`, `Usage`, `Workspace`) — consistent with this repository's own established per-feature-area test-directory convention, this remediation introduces `tests/Feature/Security/` (distinct from, and not colliding with, the merged Slice-3 contract's own reserved `tests/Feature/Dashboards/` directory, which remains exclusively for Slice 3's future design-system tests).

---

## 4. Proven vulnerability statement

**Re-confirmed, current `origin/main` `5a629c6270be4d23dcf2cfec4fe8b4573cf3eb2b`, unchanged in substance from the merged Slice-3 contract's own §3.9 finding:** `HotLeadController`, `AiAnalyticsController`, and `AiSettingsController` are reached exclusively through six routes declared in `routes/web.php` (§3.5), carrying only the bare `web` middleware — no `auth`, no `can:...`, no `authorize()` call anywhere in any of the three controller classes. The consequence, confirmed by direct trace of the actual query code:

- `HotLeadController::index()`'s `chat_boxes` read and `AiAnalyticsController::index()`'s `chat_boxes`/`ai_box_campaign_map`/`campaigns` reads are entirely unscoped by `user_id` — returning every tenant's hot-leads, AI-conversation-stage, and campaign-name data to any unauthenticated visitor.
- `HotLeadController::markCalled()` and `AiAnalyticsController::markBooked()` mutate an arbitrary `chat_boxes` row by numeric ID with no ownership check — any visitor can mutate any tenant's record.
- `AiSettingsController::save()` lets any visitor, authenticated or not, overwrite the platform-wide `ai_settings.system_prompt`/`.model` with arbitrary, unvalidated text.
- `AiAnalyticsController::index()`'s "no campaign selected" fallback additionally applies a hardcoded numeric threshold (`map.campaign_id >= 430` / `>= 425`, lines 38/67) instead of any real ownership scoping — not a tenant boundary of any kind, a separate, confirmed non-functional filter this remediation replaces.

---

## 5. Endpoint/action inventory

Restated from §3.5: 6 routes are remediated by this contract's future allowlist (§13); 1 (`admin.ai_variants.update`) is confirmed dead, non-exploitable, and explicitly deferred, unchanged (§17).

---

## 6. Intended actor matrix

| Surface | Read actor | Write actor | Basis |
|---|---|---|---|
| Hot Leads (`HotLeadController`) | Authenticated customer-tenant user holding `chat_box` | Same, and only for a `chat_boxes` row it owns | §3.3, §3.6 |
| AI Analytics (`AiAnalyticsController`) | Authenticated customer-tenant user holding `chat_box` | Same, and only for a `chat_boxes` row it owns; campaign filter restricted to campaigns it owns | §3.3, §3.6, §9 |
| AI Settings/AI Brain (`AiSettingsController`) | Authenticated admin holding `manage ai_settings` | Same | §3.4 |

**Intended ordinary actor model, as designed by this remediation** — no surface is intentionally customer-and-admin-dual-access: Hot Leads/AI Analytics are gated for an authenticated customer account holding `chat_box`; AI Settings is gated for an authenticated admin account holding `manage ai_settings`. No surface requires a Workspace/staff-role distinction beyond what these existing permission gates already express — this repository's own permission architecture (§3.2) already resolves staff/sub-account access through the same `$user->customer->permissions` JSON list `ChatBoxController` already relies on; this remediation introduces no new role concept.

**Corrected, Correction Round 1 — this is distinct from the repository's existing, inherited super-admin bypass.** `EloquentAccountRepository::hasPermission()` (§3.2) unconditionally returns `true` for every Gate check, on every permission string, whenever `$user->id === 1` — a repository-wide bypass that predates this remediation and governs every `can:`/`$this->authorize()` call in the entire application, not something introduced or specific to these three surfaces. Under that pre-existing bypass, the account with `id === 1` can reach all three surfaces regardless of the `chat_box`/`manage ai_settings` split described above — this remains true after this remediation, exactly as it is true today for every other Gate-protected route in the application. **This remediation does not redesign, narrow, or remove that bypass, and does not add a second permission or alter the middleware design to compensate for it (§17, §18)** — it is out of this remediation's own bounded scope (§0), inherited unchanged like every other existing Gate consumer in the codebase.

---

## 7. Exact authorization model

For Hot Leads and AI Analytics (routes 1-4, §3.5), each route gains, applied directly via Laravel's per-route `->middleware([...])` fluent method on its existing declaration in `routes/web.php` (no route file relocation — §13 explains why):

```
->middleware(['auth', 'can:access_backend', 'ValidProduct', 'twofactor'])
```

— the identical middleware set `routes/customer.php`'s own route group already applies to every other customer route (§3.1), applied inline rather than by physically moving the route registration. Each controller action additionally gains `$this->authorize('chat_box')` as its first statement — mirroring `ChatBoxController`'s own exact, already-shipped pattern (§3.6) — a second, defense-in-depth layer independent of the route-level gate, consistent with this repository's own established double-authorization convention (`SettingsController::aiSettings()` already does the same: a route-group `can:` gate plus an explicit in-controller `$this->authorize()` call).

For AI Settings/AI Brain (routes 6-7, §3.5):

```
->middleware(['auth', 'can:access backend', 'ValidProduct', 'twofactor'])
```

— the identical middleware set `routes/admin.php`'s own route group already applies (§3.1), applied inline the same way. Each controller action additionally gains `$this->authorize('manage ai_settings')` as its first statement — mirroring `SettingsController::aiSettings()`'s own exact pattern (§3.4).

`EnsureUserIsAdministrator` is deliberately **not** added to any of the six routes: its own docblock (`app/Http/Middleware/EnsureUserIsAdministrator.php`) scopes its purpose explicitly to "Business admin data" routes (RFC-001 §16/§19), and the properly-authorized sibling AI-settings surface (`SettingsController::aiSettings()`) does not use it either — adding it here would be a stricter boundary than this repository's own existing precedent for the same permission (`manage ai_settings`) already establishes, not required by any evidence in §3, and therefore not authorized (§17).

`'web'` middleware is not added explicitly to any of the six routes — `RouteServiceProvider::mapWebRoutes()` already applies it to the entire `routes/web.php` file (§3.1); adding it again would be redundant, not incorrect, but unnecessary.

---

## 8. Exact tenant/data-isolation model

Every `chat_boxes` read and write reachable through `HotLeadController` and `AiAnalyticsController` gains a `WHERE user_id = ?` (or Eloquent-equivalent) clause bound to `Auth::id()`, mirroring `ChatBoxController::index()`'s own exact idiom (§3.6):

- `HotLeadController::index()` — `DB::table('chat_boxes')->where('user_id', Auth::id())->where('ai_stage', 4)->where('called', 0)->orderByDesc('website_sent_at')->get()`.
- `HotLeadController::markCalled()` — the update is scoped `->where('id', $id)->where('user_id', Auth::id())`; if the resulting affected-row count is zero (record does not exist, or exists but belongs to another tenant — indistinguishable by design, §10), the controller returns `abort(404)` rather than a silent no-op redirect.
- `AiAnalyticsController::index()`'s `$stageQuery`/`$recentQuery` — the existing `map.campaign_id >= 430` / `>= 425` fallback (§4) is removed entirely and replaced with an unconditional `cb.user_id = Auth::id()` clause, applied regardless of whether a campaign filter is also present (§9) — tenant isolation is never made conditional on whether the actor happened to supply a filter.
- `AiAnalyticsController::index()`'s `$campaigns` list (populated into the filter `<select>`) — scoped `->where('user_id', Auth::id())`, closing a second, confirmed cross-tenant leak of campaign names this audit found beyond the original §3.9 finding (§4 last bullet).
- `AiAnalyticsController::markBooked($id)` — identical scoped-update-then-404-on-zero-rows pattern as `HotLeadController::markCalled()`.

`AiSettingsController` reads/writes a single, global, platform-wide row (§3.4) — no `user_id`/tenant clause applies or is added; its isolation model is purely the authorization boundary in §7, not a data-scoping clause.

---

## 9. Exact campaign isolation model

`AiAnalyticsController::index()`'s optional `campaign_id` request parameter is validated for ownership before being applied as a filter: `Campaigns::where('id', $campaignId)->where('user_id', Auth::id())->exists()`. If the supplied `campaign_id` is absent, non-numeric, does not exist, or exists but belongs to another tenant, it is treated identically to "no filter supplied" — the view renders the actor's own unfiltered-but-still-`user_id`-scoped data (§8), with no error and no distinguishing response between "doesn't exist" and "not yours" (§10). This is a read-path GET filter, not a mutation; silently falling back to the safe default is the deterministic, non-leaking, framework-consistent behavior — no new validation-failure UI is invented for it.

---

## 10. Exact AI-settings authorization model

`AiSettingsController::index()` and `AiSettingsController::save()` both gain `$this->authorize('manage ai_settings')` (§7) — the same permission already governing the adjacent, correctly-implemented `SettingsController::aiSettings()`/`OpenAISettingsRequest` surface (§3.4, §3.7). No new permission string is invented; no `user_id`/tenant scoping applies (§8) — this surface's isolation is authorization-only, matching its confirmed single-row, platform-wide nature.

---

## 11. Exact write-validation rules

Both new `FormRequest` classes (§13 items 5-6) are built directly from the existing `OpenAISettingsRequest`/`SentRequest` shape (§3.7):

- **`app/Http/Requests/Admin/MarkHotLeadCalledRequest.php`** — `authorize()`: `$this->user()->can('chat_box')`. `rules()`: `'id' => 'required|integer'` — **deliberately no `exists:chat_boxes,id` rule (corrected, Correction Round 1).** An `exists:` rule would resolve existence at the validation layer, before the controller runs, while ownership is resolved separately and later in the controller's own scoped update (§8) — producing two different failure paths (a validation redirect for "does not exist" versus a 404 for "exists but not yours") that leak resource existence and directly contradict §12/§14's own required indistinguishability. **Existence and ownership are deliberately resolved together, in one place**: the controller's already-contracted `WHERE id = ? AND user_id = Auth::id()` scoped update (§8) is the single boundary for both a nonexistent ID and another tenant's existing ID — `affected rows == 0` triggers `abort(404)` regardless of which of the two caused it, guaranteeing one shared response shape (§12).
- **`app/Http/Requests/Admin/UpdateAiBrainSettingsRequest.php`** — `authorize()`: `$this->user()->can('manage ai_settings')`. `rules()`: `'system_prompt' => 'required|string', 'model' => 'required|string'` — the exact two columns `AiSettingsController::save()` already writes (§3.4's citation of the current, unvalidated implementation), no additional field invented.

**Corrected, Correction Round 1 — route-parameter-binding accuracy:** `AiAnalyticsController::markBooked($id)` takes its only input from the route's own `{id}` URL segment. `{id}` is a **raw scalar route segment**, not a typed Eloquent parameter — the action's own signature (`public function markBooked($id)`) declares no model type-hint, and no `Route::model()`/implicit route-model binding is configured or relied upon anywhere for this route (§9's own citation confirms `AiAnalyticsController` has no such binding infrastructure at all). The prior wording describing this as "Laravel's own implicit route-parameter binding" was factually incorrect and is struck. The existence/ownership boundary for this `{id}` value is exclusively the controller's own scoped update — `WHERE id = ? AND user_id = Auth::id()`, `affected rows == 0 → abort(404)` (§8, §12) — identical in kind to `MarkHotLeadCalledRequest`'s own corrected boundary above. No dedicated `FormRequest` is required or authorized for `markBooked`, since there is no request-body field to validate for this action — only the route segment, which the controller's own scoped update already resolves for both existence and ownership together. No route-model binding, no new route constraint, and no new request file are introduced by this correction — only the prose describing the existing, already-contracted mechanism is fixed.

---

## 12. Fail-closed behavior

**Corrected, Correction Round 2 — actual HTTP status codes, not generic Laravel defaults.** Direct inspection of `app/Exceptions/Handler.php::render()` (lines 77-92) proves that whenever `config('app.env') != 'local'` — which includes the PHPUnit `testing` environment `phpunit.xml` sets for every test run, and ordinary non-local production — **both** `Illuminate\Auth\AuthenticationException` **and** `Illuminate\Auth\Access\AuthorizationException` are rendered through `response()->view('errors.401', compact('exception'), 401)`: one unified HTTP **401**, never a 302 redirect and never a 403. This is this repository's own, already-shipped, pre-existing exception-rendering convention — unrelated to and unmodified by this remediation. The original wording below (redirect-to-login for unauthenticated, 403 for unauthorized) was a generic-Laravel-defaults assumption that does not match this repository's actual behavior, and is corrected here rather than repeated.

| Condition | Response | Basis |
|---|---|---|
| Unauthenticated request to any of the 6 routes, in a non-`local` environment (including `testing`) | Blocked by the `auth` middleware; `AuthenticationException` → `app/Exceptions/Handler.php` renders `errors.401`, **HTTP 401** | Corrected, Correction Round 2 — this repository's own existing exception-rendering convention (`app/Exceptions/Handler.php:81-83`), not a Laravel generic default; unmodified by this remediation |
| Authenticated, lacks the broad `access_backend`/`access backend` gate | Route-level `can:` middleware throws `AuthorizationException` before controller code runs → `app/Exceptions/Handler.php` renders `errors.401`, **HTTP 401** | Corrected, Correction Round 2 — same Handler.php mapping; `AuthorizationException` is handled identically to `AuthenticationException` in this repository, not split into a separate 403 |
| Authenticated, lacks the specific `chat_box`/`manage ai_settings` permission | Controller's `$this->authorize()` throws `AuthorizationException` → `app/Exceptions/Handler.php` renders `errors.401`, **HTTP 401** | Corrected, Correction Round 2 — same Handler.php mapping; matches `ChatBoxController`/`SettingsController::aiSettings()`'s own existing behavior in this environment |
| Actor targets another tenant's `chat_boxes` row by ID (mark-called/mark-booked) | Controller's `WHERE id = ? AND user_id = Auth::id()` scoped update affects 0 rows → `abort(404)` | §8, §11 (corrected, Correction Round 1) — this is the **only** existence/ownership boundary; the request never reaches this point without first passing basic input validation (§11), so the 404 here is produced solely by the controller, never by a `FormRequest` existence check |
| Nonexistent record | Identical path to the row above: the same scoped update also affects 0 rows → `abort(404)` | §8, §11 (corrected, Correction Round 1) — mechanically the same code path as "another tenant's row," not merely a documentation claim of similarity; both a nonexistent ID and a foreign-tenant ID reach the controller (neither is rejected earlier by `exists:`, §11) and are turned away by the identical `affected rows == 0` check, guaranteeing one shared response |
| Invalid or unauthorized `campaign_id` filter | Silently ignored; safe unfiltered-but-tenant-scoped fallback | §9 |
| Malformed write input (`id` missing/non-integer on mark-called; `system_prompt`/`model` missing on the AI Brain save) | Standard Laravel `FormRequest` validation failure → 302 redirect-back with `$errors` | Matches every existing `FormRequest` in this repository (e.g. `SentRequest`); `id`'s rule is `required|integer` only (§11) — this layer never resolves existence or ownership, only input shape. **Unaffected by the Correction Round 2 fix above**: a `FormRequest` validation failure is a `ValidationException`, not an `AuthenticationException`/`AuthorizationException`, and is not touched by `Handler.php`'s 401 mapping — it keeps Laravel's standard redirect-back-with-errors behavior. |

No new response shape, status code, or error-page convention is invented anywhere in this table — every entry is either this repository's own existing, verified `Handler.php` behavior, or its existing, unmodified `FormRequest` validation behavior.

---

## 13. Exact implementation allowlist

**Closed, numbered, path-level, no wildcards, no duplicate path, exactly 9 unique sequential entries. Any additional path required during this remediation's implementation is a required-10th-path-shaped stop condition (§18).**

### Routes and controllers (4 modified)

1. `routes/web.php` — modified: add `->middleware([...])` (§7) to the 6 existing route declarations at lines 14, 17, 18, 65-66, 80, 81 (§3.5 rows 1-4, 6-7). Zero route path, route name, or controller binding changes to any of these 6 lines. Line 23-24 (`admin.ai_variants.update`, the confirmed-dead row 5, §3.5) is explicitly **not** touched.
2. `app/Http/Controllers/Admin/HotLeadController.php` — modified: `$this->authorize('chat_box')` added to both actions; `index()`'s query gains `where('user_id', Auth::id())` (§8); `markCalled()` re-typed to accept `MarkHotLeadCalledRequest` (item 5) instead of raw `Request`, its update scoped by `user_id` with a 404 fallback (§8, §12).
3. `app/Http/Controllers/Admin/AiAnalyticsController.php` — modified: `$this->authorize('chat_box')` added to both actions; `index()`'s magic-number fallback (§4) removed and replaced with unconditional `cb.user_id = Auth::id()` scoping plus validated campaign-ownership filtering (§8, §9); `$campaigns` list scoped by `user_id` (§8); `markBooked()`'s update scoped by `user_id` with a 404 fallback (§8, §12).
4. `app/Http/Controllers/Admin/AiSettingsController.php` — modified: `$this->authorize('manage ai_settings')` added to both actions; `save()` re-typed to accept `UpdateAiBrainSettingsRequest` (item 6) instead of raw `Request` (§10, §11).

### New FormRequests (2 new)

5. `app/Http/Requests/Admin/MarkHotLeadCalledRequest.php` — new (§11).
6. `app/Http/Requests/Admin/UpdateAiBrainSettingsRequest.php` — new (§11).

### New focused security tests (3 new)

7. `tests/Feature/Security/HotLeadsSecurityTest.php` — new (§14).
8. `tests/Feature/Security/AiAnalyticsSecurityTest.php` — new (§14).
9. `tests/Feature/Security/AiSettingsSecurityTest.php` — new (§14).

**Counts** — Route/controller: **4** (all modified, zero new files). Request: **2** (all new). Test: **3** (all new). **Overall total: 9. Stop threshold: 10** (9 + 1).

No `database/` path is listed above — this remediation authorizes **zero schema changes** (§3.6, §20). No Design System view (`resources/views/**`) is listed above — this remediation is entirely read-only with respect to presentation (§17).

---

## 14. Exact focused test requirements

Each new test file (§13 items 7-9) must assert actual HTTP behavior and data outcomes — never a superficial middleware-string assertion. **Corrected, Correction Round 2 — every assertion below must actually execute and pass; none of the core security assertions in this section may be skipped.** The final focused-suite run must produce **zero skipped tests and zero failed tests** (§15, §18).

1. **`tests/Feature/Security/HotLeadsSecurityTest.php`** — a guest (no authenticated user) requesting `GET /admin/hot-leads` or posting `POST /admin/hot-leads/mark-called` is blocked with **HTTP 401** (§12, corrected Correction Round 2 — never a 302 redirect or 403 in this repository's own environment); an authenticated customer lacking `chat_box` receives **HTTP 401**; an authenticated customer holding `chat_box` receives 200 and sees only `chat_boxes` rows where `user_id` matches their own account, proven by seeding two distinct tenants' rows and asserting tenant A's response never contains tenant B's data. **The following three cases must all exercise the same corrected boundary (§11, §12) and must be asserted together, not merely individually:** tenant A posting `mark-called` with **tenant B's real, existing row ID** receives `404`, and that row's `called` column is confirmed unchanged; tenant A posting `mark-called` with **an ID that does not exist at all** also receives `404`, with the same response shape/body as the cross-tenant case (proving the two are genuinely indistinguishable, not merely both "404" by coincidence — since neither request may be rejected earlier by a FormRequest `exists:` check, §11); tenant A posting with **its own row ID** successfully sets `called = 1` and redirects (the legitimate-actor path must still work, proving no over-restriction). A fourth case — posting a non-integer/missing `id` — must be asserted separately as a distinct `FormRequest` validation-redirect outcome (§11, §12), never conflated with the 404 pair above.
2. **`tests/Feature/Security/AiAnalyticsSecurityTest.php`** — identical guest/unauthorized-actor **HTTP 401** assertions (§12) for `GET /admin/ai-analytics` and `POST /admin/ai-analytics/book/{id}`; tenant-isolation proof for `stageCounts`/`recentBoxes` (seed two tenants, assert cross-tenant absence) and for the `$campaigns` filter list (tenant A's dropdown never contains tenant B's campaign names); supplying another tenant's `campaign_id` falls back to the unfiltered-but-own-tenant-scoped view with no error (§9); `markBooked` against another tenant's real row returns `404` and leaves it unmutated; `markBooked` against a route `{id}` segment that does not exist at all (e.g. a very large or non-existent integer) also returns `404` with no mutation, via the identical scoped-update boundary (§8, §11 corrected, Correction Round 1) — `{id}` is a raw route segment with no model binding of any kind, so this case and the cross-tenant case share the same code path; `markBooked` against the actor's own row succeeds and redirects with the existing success message (legitimate-actor path preserved).
3. **`tests/Feature/Security/AiSettingsSecurityTest.php`** — a guest, and an authenticated user lacking `manage ai_settings` (including an ordinary customer account, proving customers do **not** gain access merely because the other two surfaces are customer-facing, §3.4/§6), both receive **HTTP 401** (§12, corrected Correction Round 2) for `GET`/`POST /admin/ai-brain`; an authenticated admin holding `manage ai_settings` receives 200 on `GET` and can successfully update `system_prompt`/`model` via `POST`, with the `ai_settings` table row reflecting the new values (legitimate-actor path preserved); posting with a missing `system_prompt` or `model` field is rejected by validation with no mutation to the existing row (§11, §12).

No test in this list is a brittle full-page snapshot; every assertion is a targeted status-code, redirect-target, or data-outcome check, consistent with this repository's own established preference against snapshot testing (already noted, unchanged, in the merged Slice-3 contract's own §8 item 3).

### 14.1 Test-only ephemeral schema fixtures — authorized, Correction Round 2

§3.6/§3.8 above document a genuine, mechanically-confirmed schema-visibility gap: `chat_boxes.ai_stage`, `.called`, `.website_sent_at`, and the other legacy AI-related columns `markBooked()` writes (`.followup_sent`, `.followup_at`, `.ai_replied`), plus the entire `ai_box_campaign_map` and `ai_settings` tables, have no tracked migration anywhere in this repository, and are therefore absent even from a freshly, fully migrated disposable `ultimatesms_testing` database. **Correction Round 1's own §12/§14 text, and the original Correction Round 2 review, both confirmed this gap does not contradict any authorization or tenant-scoping assumption this remediation depends on** — `chat_boxes.user_id` and `campaigns.user_id`, the only columns this remediation's own logic (§8, §9) actually requires, are both real, migrated, FK-enforced columns (§3.6). The gap is a pre-existing test-*environment* limitation, not a defect in this remediation's design.

**The three already-allowlisted security test files (§13 items 7-9) are authorized — and required — to establish, at the start of the affected test method or class, the minimal runtime-compatible schema fixture needed to exercise the existing, unmodified controller code for real, whenever the legacy object in question is absent from the disposable `ultimatesms_testing` schema:**

- `Schema::table('chat_boxes', ...)`, adding only the specific missing legacy columns `HotLeadController`/`AiAnalyticsController` directly read or write (`ai_stage`, `called`, `website_sent_at`, `followup_sent`, `followup_at`, `ai_replied`), with a shape mechanically inferable only from how the existing, unmodified controller code already uses each column (e.g. `ai_stage` takes small integer values 1-6/99 per `AiAnalyticsController::index()`'s own `$stageCountsArray` keys; `called`/`followup_sent`/`ai_replied` are written as `0`/`1` flags; `website_sent_at`/`followup_at` are read/written as timestamps, `followup_at` explicitly nullable per the controller's own `'followup_at' => null` write).
- `Schema::create('ai_box_campaign_map', ...)`, with only the `box_id`/`campaign_id` columns the existing `leftJoin('ai_box_campaign_map as map', 'cb.id', '=', 'map.box_id')` and `->where('map.campaign_id', ...)` calls already require.
- `Schema::create('ai_settings', ...)`, with only the `system_prompt`/`model` columns `AiSettingsController::index()`/`save()` already read and write.

**Rules governing this authorization, all binding:**

- **This does not authorize any product/database migration.** No `database/` path may be added, and none is added by §13's own 9-item allowlist, unchanged. These are test-runtime-only fixtures, created and (where the test harness itself created them) torn down entirely inside the three already-allowlisted test files — never a tracked migration file, never shipped, never affecting any environment outside a single test run.
- **The fixture shape must be derived only from current controller use and other repository evidence named above** — no invented product semantics, no new business meaning, no column beyond what the existing, unmodified controller code already reads or writes.
- **If an object already exists in the test database, the test must use it as-is, never replace or redefine it.** A conditional check (e.g. `Schema::hasTable(...)`/`Schema::hasColumn(...)`) must gate every fixture creation.
- **The test harness must clean up any schema object it itself created**, so that running these focused tests does not leave `ultimatesms_testing` in a different state for the subsequent full-suite run (§15) than it found it in.
- **A genuine schema contradiction remains a stop condition (§18)** — for example, `chat_boxes.user_id` or `campaigns.user_id` turning out not to exist, or `ai_settings` turning out to have a real per-tenant ownership column contrary to §3.4's audited global-row model. The already-documented *absence of a migration* for the legacy AI objects named above is explicitly **not** such a contradiction, and is **not**, by itself, a reason to skip any of this section's required assertions.
- **`markTestSkipped()` may not be used for any of the core tenant-isolation, mutation-boundary, or AI-settings data-outcome assertions this section requires**, on the grounds that the legacy schema lacks a tracked migration — that gap is resolved by the fixture authorized above, not by skipping the test it would otherwise block.

---

## 15. Exact test commands and full-suite regression requirement

Focused (run first, per `AGENTS.md`'s own stated order):

```
php artisan test tests/Feature/Security/HotLeadsSecurityTest.php tests/Feature/Security/AiAnalyticsSecurityTest.php tests/Feature/Security/AiSettingsSecurityTest.php
```

**Corrected, Correction Round 2 — pass/fail bar for this command, binding:** this run must report **zero skipped and zero failed tests**. §14.1's ephemeral-schema-fixture authorization exists specifically so this bar is achievable without a schema contradiction — a skip or a failure here means either the fixture needs correction (within the same 3 already-allowlisted test files) or a genuine stop condition (§18) has been hit; it does not mean the assertion may be quietly dropped.

Full-suite regression, at this remediation's own final implementation head, against the `ultimatesms_testing` database only (`AGENTS.md`):

```
php artisan test
```

**Corrected, Correction Round 2 — this run may not be reported or treated as a clean pass while it contains any failed test, regardless of whether that failure appears unrelated to this remediation's own 9-path allowlist.** The required completion sequence is: (1) stabilize the local, ignored (non-tracked) test environment first — `.env` values, build artifacts, and any other local-only setup — so that unrelated pre-existing gaps do not surface as failures; (2) rerun the focused security suite (above) until it reports zero skipped and zero failed; (3) rerun the complete suite. The expected, preferred outcome is an actually zero-failure full-suite run. **If a pre-existing, unrelated full-suite failure cannot be eliminated through environment-only correction (no tracked-file change, no allowlist path beyond §13's own 9), implementation must STOP and report that exact failure with its full evidence (test name, assertion, exact error) rather than calling the run clean, silently accepting it, or omitting it from the report.** Whether independently-proven, base-equivalent failure evidence is nonetheless acceptable for merge is a governance decision reserved to a human — the implementation agent may report the evidence but may not make that acceptance decision itself, and may not broaden §13's allowlist to fix an unrelated test in order to force a clean run.

The exact complete-suite pass/skip/fail/assertion counts must be reported at implementation time, never estimated or copied from any prior contract's own baseline — matching the same discipline the merged Slice-3 contract's own §8 established. **This contract itself does not run either command** — it is a docs-only contract-drafting pass; both are reported here as required future commands only.

---

## 16. Static/mechanical security checks

1. `git diff --stat -- database` compared against §13 (which contains no `database/` path) → must be completely empty; any non-empty result is an automatic violation (§3.6, §20).
2. `git diff --stat -- resources/views` compared against §13 (which contains no view path) → must be completely empty (§17).
3. `git diff --name-only` + `git ls-files --others --exclude-standard`, restricted to this remediation's own implementation commits, must equal §13's exact 9-item allowlist — mechanically diffed, not eyeballed.
4. `grep -n -- "->middleware(" routes/web.php` (corrected, Correction Round 1 — the leading `-` in the search pattern is passed as a literal argument via `--`, so GNU grep cannot misparse it as an option) → must show the 6 new middleware chains on exactly the 6 lines named in §13 item 1, and must **not** show one on the `admin.ai_variants.update` line (§3.5 row 5, §17).
5. `grep -n "authorize(" app/Http/Controllers/Admin/HotLeadController.php app/Http/Controllers/Admin/AiAnalyticsController.php app/Http/Controllers/Admin/AiSettingsController.php` → each of the 6 remediated actions (§3.5 rows 1-4, 6-7) shows exactly one `$this->authorize(...)` call with the exact permission name specified in §7.
6. `grep -n "user_id" app/Http/Controllers/Admin/HotLeadController.php app/Http/Controllers/Admin/AiAnalyticsController.php` → present in every location §8 names; `grep -n "user_id" app/Http/Controllers/Admin/AiSettingsController.php` → must remain absent (§10 — no tenant scoping is authorized or expected on this surface).
7. `grep -n "manage ai_settings" app/Http/Requests/Admin/UpdateAiBrainSettingsRequest.php` and `grep -n "'chat_box'" app/Http/Requests/Admin/MarkHotLeadCalledRequest.php` → present, confirming the exact permission strings from §11 were actually used, not a newly invented string.
8. `php artisan route:list` (or equivalent) diffed before/after → the exact same 7 URIs from §3.5 must still resolve to the exact same controller actions; zero URI, zero controller-action binding change anywhere.
9. `php artisan test` full-suite pass count compared against this remediation's own pre-implementation baseline, reported exactly (§15).
10. **Corrected, Correction Round 2.** `php artisan test tests/Feature/Security/HotLeadsSecurityTest.php tests/Feature/Security/AiAnalyticsSecurityTest.php tests/Feature/Security/AiSettingsSecurityTest.php` → the reported summary line must show **0 skipped, 0 failed** (§14.1, §15); any nonzero skip or failure count here is a stop-and-report condition, not a result to report as-is and proceed past.

---

## 17. Forbidden scope

This remediation must not, under any circumstance:

- Restyle, retheme, or otherwise touch any Design System dashboard view (`resources/views/customer/dashboard.blade.php`, `resources/views/admin/dashboard.blade.php`, `resources/views/admin/hot_leads.blade.php`, `resources/views/admin/ai_analytics.blade.php`, `resources/views/admin/ai-settings.blade.php`) — that is exclusively Slice 3's own future scope, locked behind its own separate implementation authorization.
- Migrate icons, tokenize colors, add/adopt Blade components, or add the `ai-settings.blade.php` layout wrapper — all exclusively Slice 3's own scope (§0).
- Fix, wire up, or otherwise touch `AiAnalyticsController::updateVariants()`/`admin.ai_variants.update` (§3.5 row 5, §13 item 1) — confirmed dead, non-exploitable, deliberately excluded, separately deferred.
- Redesign AI outreach-stage meaning, booking semantics, hot-lead meaning, AI-analytics metrics, campaign behavior, AI prompt/model product behavior, CRM behavior, billing, SEO, COO, or Workspace architecture — none of §13's 9 items touches any of these; every change is authorization/validation/scoping only.
- Expand into a whole-application security audit or fix any security issue outside the three named controllers and their six remediated routes (§3.5) — no other route, controller, or middleware file may be touched.
- Author or authorize any schema change (`database/` path) — none is required (§3.6, §13) and none is authorized even if one is later claimed to be convenient.
- Begin Slice 3 implementation, Slice 4 or any later Design System slice, or any other RFC/initiative (COO, SEO, CRM, Outreach, Website, Calendar, Ads) — this contract authorizes only its own, later, separately-authorized implementation (§19).
- Deploy, force-push, push directly to `main`, or merge anything.

---

## 18. Stop conditions

This remediation's implementation must stop, leave the working tree unstaged, and report rather than proceed, if:

- Any path beyond §13's 9-item allowlist is required — the **10th** path.
- `php artisan route:list` shows any of the 7 URIs in §3.5 resolving to a different path, or a different controller action, than it did before implementation began (§16 item 8).
- The `ai_settings`, `chat_boxes`, `campaigns`, or `ai_box_campaign_map` tables are found, at implementation time, to have a column shape materially different from what §3.4/§3.6 document (e.g. `ai_settings` turns out to have a real tenant-ownership column after all) — implementation must stop and re-audit rather than silently keep or silently add a scoping assumption not proven at contract-drafting time. **Corrected, Correction Round 2 — this is distinct from, and must not be confused with, the already-documented absence of a tracked migration for `ai_settings`, `ai_box_campaign_map`, or the legacy `chat_boxes` AI columns: that absence is not a stop condition, and §14.1 authorizes the exact test-only fixture that resolves it.** Only a genuine contradiction of an assumption this remediation's own logic depends on (e.g. `chat_boxes.user_id`/`campaigns.user_id` not existing, or `ai_settings` proving to be per-tenant) triggers this bullet.
- Any existing test outside §13's own 3 new files fails for a reason not fixable within this remediation's own allowlist, **and that failure cannot be eliminated through environment-only correction (§15) — in which case implementation must STOP and report the exact failure evidence rather than declaring the full-suite run clean.**
- The focused security suite (§15) reports any skipped test not resolved by §14.1's fixture authorization, or any failed test, after the fixture and environment corrections available within §13's own allowlist have been attempted.
- Any `chat_box`/`manage ai_settings` permission string, or any Gate/permission-config path (`config/permissions.php`, `config/customer-permissions.php`, `app/Providers/AuthServiceProvider.php`), is found to require a change — none is authorized; this remediation reuses existing permission strings exactly as they exist today (§7, §10, §16 item 7).
- A genuine, mechanically-proven need for `EnsureUserIsAdministrator` (or any authorization primitive beyond §7's exact two middleware sets plus two `$this->authorize()` calls) is found — implementation must stop and report rather than add it unilaterally (§7's own reasoning must be re-examined by a human, not overridden in-flight).
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is found necessary to reference.

---

## 19. Governance closing — human-only merge, correction rounds, and the Slice-3 handoff

- **Human-only merge.** No automatic merge of this contract, and no automatic merge of its own future implementation, under any condition.
- **`maximum_correction_rounds: 2`** applies to this contract, unchanged from every other contract in this repository's own established convention.
- **`advance_automatically: false`, `start_automatically_after_contract_merge: false`.** Merging this contract does not begin its own implementation. A separate, explicit, future human instruction is required to authorize this remediation's implementation, exactly as every prior contract in this repository (RFC-005's own milestones, every Design System slice) has required.
- **Merging this contract does NOT satisfy the Slice-3 prerequisite.** The merged Slice-3 contract's own §0/§7/§11 requires this remediation's **implementation** — not merely this contract — to be human-merged before Slice 3 implementation may be authorized. Merging this document alone leaves Slice 3 exactly as blocked as it was before this document existed.
- **Post-remediation handoff, explicit.** Once this remediation's own implementation is separately authorized, implemented, and human-merged, the exact resulting merge SHA must be reported so that Slice 3's own later, separate implementation authorization can pin both: (a) that exact remediation-implementation merge SHA, and (b) the exact then-current `origin/main` SHA Slice 3 implementation would be based on — exactly as the merged Slice-3 contract's own §7/§11 already requires. This contract does not, and cannot, supply that SHA now, since the implementation it describes does not yet exist.

---

## 20. Contract self-audit

1. Every actor-model question the governing instruction raised (§0 of that instruction: "do not blindly assume all three are admin-only," "do not blindly assume all three are customer-facing") is answered from direct, current-repository evidence, not assumption — §3.3 (menu-array-position trace) for Hot Leads/AI Analytics, §3.4 (zero-menu-link + permission-family match) for AI Settings. ✓
2. The tenant-isolation model (§8) reuses an already-shipped, already-correct precedent (`ChatBoxController::index()`) rather than inventing a new scoping abstraction — no Workspace/Business layer was introduced where a simpler, already-proven `user_id` scope suffices. ✓
3. The authorization model (§7) reuses existing Gate names (`chat_box`, `access_backend`, `manage ai_settings`, `access backend`) exactly as they already exist in `config('permissions')`/`config('customer-permissions')` — zero new permission string invented. ✓
4. The schema-visibility gaps found (`ai_settings`, `ai_box_campaign_map`, and most of `chat_boxes`' AI-related columns having no migration) are recorded honestly (§3.4, §3.6) and confirmed not to block this remediation, since every fix needs only the one migrated column (`user_id`) already proven to exist on both `chat_boxes` and `campaigns`. ✓
5. The one dead/non-exploitable adjacent route (`admin.ai_variants.update`) is recorded accurately and deliberately excluded, per this task's own explicit instruction against opportunistic dead-code cleanup (§3.5 row 5, §17). ✓
6. Allowlist total is exactly 9, numbered 1-9, sequential, no duplicate path (§13), independently recounted before commit (§21). ✓
7. The stop threshold is explicitly the 10th path, stated consistently in §13 and §18. ✓
8. No Design System view, no schema, no unrelated route/controller, no other RFC/initiative is touched or authorized anywhere in this document (§17). ✓
9. This document does not authorize its own implementation, and states that fact explicitly and repeatedly (title block, §0, §19). ✓
10. **Correction Round 1.** Independent review found one blocking internal contradiction (§11's original `exists:chat_boxes,id` rule leaked resource existence, contradicting §12/§14's own required 404 indistinguishability) and three bounded accuracy defects (an incorrect "implicit route-parameter binding" claim for `markBooked($id)`; an unusable `grep -n "->middleware("` command that GNU grep could misparse as an option string; an overbroad "no surface is customer-and-admin-dual-access" claim that did not account for the already-documented, pre-existing `$user->id === 1` super-admin Gate bypass). All four are corrected in §11, §12, §14, §16, and §6 respectively, without changing the 9-item allowlist (§13), the authorization architecture (§7), the middleware design, or the permission set. ✓
11. **Correction Round 2 (final allowed round).** Independent review of the completed-but-unmerged implementation found the production code correct and in-allowlist, but found two contract defects: (A) §12/§14's response-code claims (302/403) did not match this repository's actual, directly-verified `app/Exceptions/Handler.php` behavior (unified HTTP 401 for both `AuthenticationException` and `AuthorizationException` outside the `local` environment) — corrected throughout §12/§14 with a direct file:line citation, no Handler.php change authorized or made. (B) §14 could be read as permitting the core tenant-isolation/mutation/AI-settings assertions to be skipped merely because the already-documented legacy-schema migration gap exists — corrected by §14.1's new, explicit, narrowly-bounded test-only ephemeral-schema-fixture authorization (derived only from existing controller use, no product migration, mandatory cleanup, use-existing-if-present, and an explicit list of what remains a genuine stop condition versus what does not). §15/§16/§18 were updated to make the zero-skip/zero-fail bar for the focused suite, and the no-silent-clean-full-suite-with-a-failure rule, mechanically enforceable rather than aspirational. Neither correction changes the 9-item allowlist (§13), the authorization architecture (§7), the middleware design, the permission set, or the actor model (§6). ✓

---

## 21. Verification and publication

Performed, in order, before commit:

1. Markdown structural check — every numbered §13 item follows the pattern `N. `path``, no broken heading levels, no unclosed code fences.
2. §13's numbered items counted mechanically and confirmed equal to exactly 9, sequential, no gap, no repeated number.
3. Every path listed in §13 checked for uniqueness — no path string appears twice.
4. `git diff --check` — clean, no whitespace-error or conflict-marker findings.
5. `git diff --name-only` — exactly one path: `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-DASHBOARD-SECURITY-REMEDIATION-CONTRACT.md`.
6. `git status --short` — exactly one entry, the same path.
7. Stage individually (`git add docs/automation/DESIGN-SYSTEM-M2-SLICE-3-DASHBOARD-SECURITY-REMEDIATION-CONTRACT.md`), never `git add -A`/`.`.
8. Commit message: `docs: define dashboard security remediation`.
9. Push to `origin chore/design-system-m2-slice3-dashboard-security-contract` — a normal push, never force-pushed.
10. Open a draft PR into `main` if `gh` is available; otherwise report the exact GitHub comparison URL.
11. **Do not merge. Do not begin this remediation's implementation. Do not begin Slice 3 or any later Design System slice. Do not begin any other RFC or initiative.** All require separate, explicit, future human authorization. No test is run for this docs-only change — reported honestly as not run, no count fabricated.

---

*End of Design System M2 Slice 3 — Dashboard Security Remediation Contract. Implementation requires a separate, explicit human instruction. This contract's own merge does not start or resume it, and does not by itself satisfy the Slice-3 prerequisite named in `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-CONTRACT.md` §0/§7/§11 — only this remediation's own subsequently human-merged implementation does that.*
