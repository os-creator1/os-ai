# B4 — BUSINESS AUTOMATIONS IMPLEMENTATION CONTRACT

Status: CONTRACT ONLY — no product code authorized by this document itself.
Base SHA: `2d94c18fabe538cc711fd73822cbbd0d31387a44`
Branch: `agent/b4-business-automations-contract`

Every claim in this contract is backed by a mechanical inspection of the
tree at the base SHA. Where a decision was left open by the task, the
resolving evidence is cited inline by file and line.

---

## 1. LOCKED PRODUCT DEFINITION

B4 v1 is **Business-scoped single-step automations**:

> WHEN one supported trigger fires for a Contact belonging to an explicit
> Business, THEN run exactly one bounded action.

Explicitly **not**: a Zapier clone, a visual graph engine, multi-step
branching, a conditions tree, AI COO, AI Workforce, Agency Prospecting, or
any Workspace-level automation class.

Agency AI Prospecting (Workspace-level acquisition of *external* businesses)
remains completely separate and is untouched by B4.

### 1.1 Why single-step is the honest scope

The inherited engine is not a workflow engine. Mechanically:

- There is exactly **one** automation table (`automations`). There are no
  `workflows`, `triggers`, `conditions`, `actions`, or execution tables.
- `App\Models\Automation` **extends `SendCampaignSMS`** — it *is* a campaign
  sender subclass, not a definition model.
- `Automation::start()` (model lines 402–442) implements exactly one
  trigger: a contact birthday/anniversary date match.
- `automations.data` only ever holds
  `{"options":{"before","at","birthday_field"}}`, written by
  `EloquentAutomationsRepository::automationBuilder()` and read by
  `getOptions()`. No other trigger shape is representable.
- There is **no conditions stage at all**; the "condition" is hardcoded into
  the birthday SQL.

A multi-step builder UI would therefore be fiction over an engine that
cannot honour it. B4 keeps the execution plumbing and rebuilds the
definition model honestly.

---

## 2. TENANCY — LOCKED

Automations are **Business-scoped**. This is not a new opinion: the
entitlement layer already classifies it that way.

- `App\Enums\Entitlement\PlatformFeatureScope` docblock names
  `Automations` among features that "execute against an explicit Business".
- `PlatformFeatureRegistry::SCOPE` (line 62) contains **only**
  `ProspectOutreach => Workspace`; `isWorkspaceScoped()` (line 76–79)
  defaults everything else to `PlatformFeatureScope::Business`.

### 2.1 Canonical address

```
/workspaces/{workspaceUid}/businesses/{businessUid}/automations/...
```

Route group shape must mirror B1 exactly
(`routes/customer.php:687`):

```php
Route::prefix('{workspaceUid}/businesses/{businessUid}/automations')
    ->name('businesses.automations.')->group(...)
```

### 2.2 Mandatory per-request resolution order

Every HTTP action, without exception:

1. Resolve Workspace by UID.
2. Resolve Business by UID **inside** that Workspace.
3. `WorkspaceManager::userCanAccessBusiness(actorUserId, $business)`
   (`app/Library/Workspace/WorkspaceManager.php:97`).
4. Entitlement decision (§2.4).
5. Resolve the Automation **scoped to that Business**.

A foreign-Workspace, foreign-Business, or foreign-Automation identifier
must fail closed with the same failure class as a nonexistent one.

### 2.3 Forbidden tenancy mechanics

- `Auth::id()` as tenant identity (capability checks only).
- Customer/`user_id` ownership as authorization.
- Primary-Business inference of any kind.
- `LegacyBusinessResolver`.
- Any Workspace-level automation fallback.

### 2.4 Entitlement seam

Use the existing Business-scoped decision:

```php
EntitlementManager::decide(Workspace $workspace, Business $business,
    PlatformFeature::Automations->value, int $actorUserId): EntitlementDecision
```

Today there are **zero** runtime callers for `PlatformFeature::Automations`
(only the registry entry and one registry test), so B4 introduces the first
real gate. `decideForWorkspace()` must **never** be used for Automations —
`decide()` returns `wrong_feature_scope` for Workspace-scoped features and
the converse mistake would bypass both the per-Business feature toggle and
the usage-authorization gateway.

### 2.5 Background execution identity — RESOLVED MECHANICALLY

Asynchronous execution has no acting browser user. Evidence:

- `EntitlementManager::decide()` accepts `int $actorUserId` but **never
  reads it** anywhere in the method body (verified across lines 111–186).
  It is a signature/audit parameter only, so supplying it cannot alter the
  decision.
- `businesses.customer_id` is a real FK to `users`
  (`2026_07_18_120001_create_businesses_table.php:13`).
- B1 already uses exactly this identity for its own Business-owner needs:
  `$businessOwner = $business->customer->user;`
  (`OutreachController::sendSms()`, line 192).

**Decision:** background execution passes the Business's own persistence
owner — `(int) $business->customer_id` — as `$actorUserId`. It must never
fabricate, impersonate, or default to a browser session user, and must
never call `Auth::id()` from a job. This is a structurally-required,
behaviorally-inert argument, and the implementation must carry a comment
saying so, so no future reader mistakes it for an authorization decision.

---

## 3. SCHEMA — ADDITIVE ONLY

No destructive legacy-column change is authorized. Legacy columns may
become unused but remain physically present, so every migration in B4 is
rollback-safe.

**`running_pid` is explicitly RETAINED.** The reconnaissance recommended
dropping it as proven-dead; this contract rejects that for B4 to keep the
change additive. It stays physically present and permanently inert, and the
implementation must not read or write it.

Likewise retained-but-unused after B4: `automations.sms_type`,
`sender_id`, `media_url`, `language`, `gender`, `dlt_template_id`,
`timezone`, `cache`, `contact_list_id`, `sending_server_id` remain in place;
B4 must not repurpose them with new meanings.

### 3.1 `automations` — new columns

| Column | Type | Null | Notes |
|---|---|---|---|
| `business_id` | `unsignedBigInteger` | **YES** | FK → `businesses(id)`, `restrictOnDelete()`, indexed. Nullable because legacy rows may be unresolvable (§3.3) — never NOT NULL in B4. |
| `trigger_type` | `string(32)` | YES | Code-backed enum value (§6). Nullable so legacy rows stay valid. Indexed together with status (§3.2). |
| `trigger_config` | `json` | YES | Bounded, schema-validated per trigger type. |
| `action_type` | `string(32)` | YES | Code-backed enum value (§7). |
| `action_config` | `json` | YES | Bounded, schema-validated per action type. |

Follow the tenancy-foundation column/index/FK convention already used by
`2026_09_05_120001_add_nullable_business_id_to_tenancy_tables.php`:
nullable column first, then index + `restrictOnDelete` FK named
`automations_business_id_index` / `automations_business_id_foreign`.

`json` is authorized (not `longText`) because MySQL 5.7+ is already assumed
by existing `json` columns in the tree; if the implementation finds a
concrete portability blocker it may use `longText` and record why.

### 3.2 Indexes

- `business_id` (single, per the tenancy-foundation convention).
- A composite supporting the scheduler sweep, e.g.
  `(status, trigger_type)` — the sweep is
  `where status = 'active' and trigger_type = 'contact_date_reached'`.

### 3.3 `workspace_id` is NOT added — RESOLVED MECHANICALLY

`businesses.workspace_id` is authoritative and **enforced**
(`2026_07_30_120004_add_nullable_workspace_id_to_businesses.php`, backfilled
by `…120005`, constrained by
`2026_07_30_120006_enforce_business_workspace_constraint.php`), and
`EntitlementManager::decide()` itself re-derives Workspace from the Business
and throws `BusinessWorkspaceMismatchException` on divergence (lines
137–144).

Adding `automations.workspace_id` would therefore create a second,
independently-driftable copy of an already-enforced fact. **Do not add it.**
Workspace is always reached as `$automation->business->workspace_id`.

### 3.4 Legacy backfill rule

An existing automation row may be mapped to a Business **only** when
deterministically resolvable:

```
automations.contact_list_id → contact_groups.business_id
```

Backfill that `business_id` **only if** the resolved contact group's
`business_id` is non-NULL **and** the group's `customer_id` equals the
automation's `user_id` (owner consistency).

Otherwise leave `business_id` NULL. Never guess a primary Business, never
use `LegacyBusinessResolver`, never pick "the first" Business. This mirrors
`BusinessDataTenancyBackfillV1`, whose own migration
(`2026_09_05_120002`) deliberately leaves unresolvable rows NULL and logs a
per-table resolved/unresolved summary rather than failing. B4's backfill
must log the same way.

### 3.5 NULL-business legacy automations

A legacy automation with `business_id IS NULL` must:

- **never execute** through the B4 runtime (the scheduler sweep filters
  `whereNotNull('business_id')`);
- **never be reachable** through any Business route (it cannot be, since
  every lookup is scoped to a resolved Business, but the implementation must
  prove this with a test);
- **remain preserved** in the table for future/manual remediation;
- **never be silently reassigned** to any Business.

Its legacy cron path is also removed (§10), so it becomes inert-but-intact.

### 3.6 Rollback behaviour

- `automations` migration `down()`: drop the FK, then the indexes, then the
  five new columns. Legacy columns and all legacy rows are untouched, so
  rolling back restores the pre-B4 schema exactly.
- The backfill migration is **data-only** and its `down()` is a documented
  no-op: it must not null out `business_id` (the column itself is dropped by
  the DDL migration's rollback), and it must never delete automation rows.
- `automation_executions` migration `down()`: `dropIfExists`. Because the
  ledger is the only record of B4-era runs, rollback is destructive of run
  history by definition; this must be stated in the migration docblock.

---

## 4. EXECUTION LEDGER — `automation_executions`

B4 introduces the missing primitive. Today the only history is per-message
rows in `tracking_logs`/`reports` plus a denormalised `automations.cache`
counters blob — there is no per-run ledger.

Precedent consulted: `opportunity_action_executions`
(`2026_07_19_120004_create_opportunity_action_executions_table.php`).
Adapted, not copied.

### 4.1 Columns

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `id()` | no | |
| `uid` | `uuid` unique | no | Public identifier (repo-wide `HasUid` convention). |
| `business_id` | `foreignId` → `businesses` | no | `restrictOnDelete()`. Tenancy is denormalised here **deliberately** (unlike §3.3) so history queries and the scoped-history endpoint never need a join through a possibly-deleted automation. |
| `automation_id` | `foreignId` → `automations` | no | `cascadeOnDelete()`. |
| `contact_id` | `foreignId` → `contacts` | no | `cascadeOnDelete()`. |
| `trigger_type` | `string(32)` | no | Denormalised for safe history display. |
| `idempotency_key` | `string(191)` | no | **UNIQUE** — the race-proof claim (§5). |
| `status` | `string(16)` | no | `pending` \| `succeeded` \| `failed` \| `skipped`. Default `pending`. |
| `action_claimed_at` | `timestamp` | yes | Set at claim, before any provider call. |
| `started_at` | `timestamp` | yes | |
| `completed_at` | `timestamp` | yes | |
| `safe_result_summary` | `string(255)` | yes | Human-safe only. |
| `safe_error_summary` | `string(255)` | yes | Human-safe only. |
| `timestamps` | | | |

Indexes: `business_id`, `automation_id`, `(automation_id, created_at)` for
the history view, `status`. Unique: `idempotency_key`.

### 4.2 `attempt_number` is EXCLUDED — RESOLVED

The task asked this to be decided rather than assumed. Under the locked
automatic at-most-once policy (§5) there is, by construction, exactly **one**
automatic attempt per logical action: the ledger row *is* the attempt, and a
second automatic attempt is forbidden rather than counted. Carrying an
`attempt_number` would encode a retry semantic the policy explicitly denies
and would invite a future reader to implement one.

`opportunity_action_executions` includes it legitimately because that flow
permits human-initiated re-execution; B4 v1 has no such flow (§13). If an
explicit human retry is added later, it should add its own column then,
with its own semantics.

### 4.3 Content restrictions

No provider response bodies, no credentials, no API keys, no raw provider
payloads, no full outbound message body in `safe_*` fields. Summaries are
bounded strings intended for display.

---

## 5. CONSERVATIVE AT-MOST-ONCE POLICY — LOCKED

B4 inherits the safety preference established by Agency AI Prospecting
Correction 2:

> A duplicate automated message is worse than a missed automated message.

### 5.1 Rules

1. The logical execution is **claimed durably before** any external
   provider call.
2. Once a row exists for that `idempotency_key` — in **any** status
   (`pending`, `failed`, `succeeded`, `skipped`) — automatic execution
   **must never** call the external messaging provider again for that
   logical action.
3. Provider/network calls happen **outside** DB transactions.
4. If the process dies after claim and before the provider call, the action
   is lost. **This is an accepted v1 tradeoff.** No automatic resend.
5. A future explicit human retry may have different semantics; it is **not**
   in B4 v1.

### 5.2 Claim mechanics

The claim is a single `INSERT` guarded by the `idempotency_key` UNIQUE
constraint, with the duplicate caught explicitly:

```php
try {
    $execution = AutomationExecution::create([... 'idempotency_key' => $key ...]);
} catch (\Illuminate\Database\UniqueConstraintViolationException) {
    return; // already claimed by a concurrent/earlier run — never send
}
```

This is the stronger of the two in-repo precedents: `OpportunityManager`
(lines 1085–1092) claims under a row lock with a check-then-insert, whereas
Agency Prospecting's jobs use the unique-constraint catch, which is race-proof
without depending on lock scope. B4 uses the unique-constraint catch, and
may additionally lock the automation row for state re-checks.

A pre-check (`where('idempotency_key', $key)->exists()`) is permitted as a
fast path but is **never** the guarantee.

### 5.3 DB-only actions

`UPDATE_CONTACT_FIELD` (§7B) has no provider call, but takes the **same**
claim path and the same at-most-once rule: one ledger row per logical
action, and an already-claimed key means no repeat write. The field write
itself is naturally idempotent (setting a value to the configured value),
but the claim must still be recorded so history is complete and a retry
cannot produce a second execution record.

---

## 6. LOCKED V1 TRIGGERS (exactly two)

### 6.A `CONTACT_DATE_REACHED`

Generalises the existing birthday behaviour. It must **not** be named
"birthday" internally — the primitive is *any* Contact date custom field.

Configuration (`trigger_config`), all Business-scoped:

- `contact_group_id` — audience; must satisfy
  `contact_groups.business_id === automation.business_id`.
- `date_field_id` — a `contact_group_fields` row belonging to that group.
  The table carries `label`, `type`, `tag`
  (`2023_12_20_092338_create_contact_group_fields_table.php:18–20`), so a
  date-typed field is identifiable; values live in
  `contacts_custom_field.value`.
- `offset` — a bounded "before/after" offset (the existing
  `Automation::getDelayBeforeOptions()` value vocabulary, e.g. `0 day`,
  `2 days`, `1 week`, `1 month`, is an acceptable starting allowlist).
- `send_at` — time of day, `H:i`.

**Timezone — RESOLVED:** use `businesses.timezone`
(`2026_07_18_120001_create_businesses_table.php`, a real column on the
Business). This supersedes the legacy `automations.timezone` column and the
legacy `$user->timezone` usage. No stronger convention exists in the tree:
the legacy code took timezone from request input and stored it per
automation, which is weaker than the Business's own declared timezone.

Date matching reuses the existing, proven expression shape from
`Automation::start()` (model lines 420–426):
`DATE_FORMAT(STR_TO_DATE(contacts_custom_field.value, config('custom.date_format_sql')), '%m-%d')`
compared against the offset-adjusted local date.

**Deterministic idempotency key:**

```
contact_date_reached:{automation_id}:{contact_id}:{occurrence_year}
```

where `occurrence_year` is the four-digit year of the **offset-adjusted
local occurrence date** in the Business timezone (not the calendar year of
"now", so a January send for a December-offset occurrence cannot collide).
This yields exactly one execution per automation × contact × yearly
occurrence, replacing the legacy `subscribersNotTriggeredThisYear()` LEFT
JOIN heuristic with a durable DB-enforced key.

### 6.B `CONTACT_CREATED`

Fires after a Contact with an explicit `business_id` is **successfully
committed**.

**Creation paths — mechanically enumerated (all of them):**

1. `EloquentContactsRepository::storeContact()` (line 244) — inherits
   `business_id` from `$contactGroups->business_id`. **In scope.**
2. `EloquentContactsRepository::createContactFromRequest()` (line 649) —
   the field-driven create path. **In scope.**
3. `DLRController.php:747` — inbound opt-in keyword auto-creation. It sets
   `business_id` via
   `app(LegacyBusinessResolver::class)->resolveForCustomer(...)`, i.e. a
   *resolved/guessed* Business. **OUT OF SCOPE — must not trigger B4.**

There is **no bulk import path**: `app/Imports/` does not exist and there is
no `Excel::import` / `ToCollection` / `WithHeadingRow` usage anywhere in
`app/` (verified). The task's "import-created Contacts follow the same rule
unless evidence proves unsafe" is therefore moot in v1; if an import path is
added later it must be evaluated separately.

**Integration seam — LOCKED:** an explicit after-commit dispatch at the two
in-scope repository methods (2 call sites), **not** an Eloquent
`static::created()` model hook. Rationale, mechanically grounded:

- A model hook would also fire for the `DLRController` path, whose
  `business_id` comes from `LegacyBusinessResolver` — precisely the
  inference B4 forbids.
- `Contacts::boot()` currently registers only a `creating` uid hook (model
  lines 85–93); a `created` hook fires *inside* any surrounding transaction,
  violating the after-commit requirement.

The dispatch must therefore be wrapped so it runs only after commit —
`DB::afterCommit(...)` or a `ShouldDispatchAfterCommit` event, matching the
existing `App\Events\Opportunity\*` convention (all of which implement
`ShouldDispatchAfterCommit`).

**Rules:**

- No trigger before DB commit.
- `business_id` required; a NULL-business Contact does nothing.
- Trigger evaluation is **queued**; no action/provider work runs inside the
  Contact-creation request.

**Deterministic idempotency key:**

```
contact_created:{automation_id}:{contact_id}
```

One execution per automation × contact, forever.

### 6.C Explicitly excluded from v1

Inbound message, keyword, opportunity, generic recurring cron, webhook,
booking, forms, payments, website, SEO, Ads. These are later expansions and
must not be partially stubbed.

---

## 7. LOCKED V1 ACTIONS (exactly two)

### 7.A `SEND_MESSAGE` (SMS + MMS only)

**Reuse seam — RESOLVED MECHANICALLY.** B4 must send through the same core
B1 uses, with no new provider integration:

- `App\Repositories\Contracts\CampaignRepository`
- `checkQuickSendValidation(array $sendData)` — validates sender id / sms
  type / user
- `quickSend(Campaigns $campaign, array $sendData)`
- with `$campaign->business_id = $business->id` set on the transient
  `Campaigns` instance **before** the call, so every `Reports`/`TrackingLog`
  row inherits `business_id`

This is exactly `OutreachController::sendSms()` lines 219–270. The
`$sendData` shape it builds is the reference:
`business_id`, `user_id` (= `$business->customer_id`), `message`,
`sender_id`, `sms_type`, `country_code`, `recipient`, `region_code`.

**Requirements:**

- Exact explicit Business; never another Business's channel or sender.
- Only Business-assigned **active** sending channels
  (`CustomerBasedSendingServer::where('business_id', …)->where('status', 1)`,
  as B1 checks at line 214), and the underlying `SendingServer` must also be
  active.
- Sender identity must belong to the Business.
- SMS/MMS only.
- No direct credential handling in Automations.
- No Agency Prospecting channel.
- The legacy User-only `Automation::send()` / `SendCampaignSMS` inheritance
  path must not be used.
- No duplicated provider integration.

**Billing — LOCKED:** B4 **inherits** whatever accounting the B1 Business
Outreach send core performs. It must **not** create its own legacy
`sms_unit` decrement path (today `Automation::track_message()` calls
`$this->user->countSMSUnit()`, and `AutomationJob` resolves pricing via
`CustomerBasedPricingPlan`/`PlansCoverageCountries` — that whole path is
abandoned, not extended). **No UsageWallet redesign, no billing cutover, in
B4.** If a coverage/pricing precondition is required, it must come from the
same call the B1 path already makes, not a new one.

No `conversationContext` or equivalent trickery unless B1 itself already
uses it for the same call — it does not.

### 7.B `UPDATE_CONTACT_FIELD`

Updates exactly one Business-scoped Contact custom-field value.

**Requirements:**

- Target is **always** the trigger Contact — never a configurable contact id
  (this structurally removes cross-Business target tampering).
- The custom field must belong to the Contact's own group/Business
  (`contact_group_fields` → `contact_groups.business_id ===
  automation.business_id`), re-verified at action time.
- `action_config` is an explicit allowlisted `{field_id, value}` pair;
  `value` is a bounded string validated against the field definition.
- No arbitrary model/table/column assignment; no dynamic attribute names
  from config.
- Deterministic and idempotent (§5.3).

### 7.C Explicitly excluded from v1

Move opportunity, webhook, email, blacklist, booking, invoice, AI action,
internal notification.

---

## 8. MODEL ARCHITECTURE

`Automation extends SendCampaignSMS` **must not remain**. B4's `Automation`
is a **definition/state model** only.

Authorized new bounded units (minimum viable, no generic framework):

- `App\Enums\Automation\AutomationTriggerType` — backed enum:
  `contact_date_reached`, `contact_created`.
- `App\Enums\Automation\AutomationActionType` — backed enum:
  `send_message`, `update_contact_field`.
- `App\Enums\Automation\AutomationExecutionStatus` — backed enum.
- A trigger-evaluation service (resolves due contacts for a given
  automation; one method per trigger type, selected by a `match` on the
  enum).
- An execution-claim service (owns §5.2 exclusively — one place).
- An action dispatcher (bounded `match` on the action enum → one handler
  per action; each handler receives already-verified state).
- `App\Models\AutomationExecution`.

**Prohibited:** a generic workflow-framework abstraction; storing PHP class
names, function names, or callables in the database; any user-supplied class
resolution. Trigger and action identity are code-backed enums, always.

**Implementation trap to avoid (already cost two prior passes):**
`CustomerBaseController::index()` takes zero parameters, so a subclass
method literally named `index(string $workspaceUid, ...)` is a fatal LSP
error. B1 renamed its own to `compose()` for this reason
(`OutreachController` lines 87–92). The B4 controller's listing method must
not be called `index`.

---

## 9. RUNTIME FLOW — LOCKED

Identical spine for both triggers:

```
TRIGGER FIRES
 → load the explicit Business automation
 → verify automation is active (status = active)
 → verify Business active AND its Workspace active
 → verify Automations entitlement for (Workspace, Business)
 → verify the Contact belongs to the SAME Business
 → compute the deterministic idempotency key (§6)
 → CLAIM: atomically insert the execution row (unique key; catch duplicate)
 → dispatch the action job
 → ACTION JOB: re-fetch ALL authoritative state from the database
 → re-verify: automation still active, Business/Workspace still active,
   entitlement still allowed, Contact still in Business, channel + underlying
   SendingServer still active, action config still valid
 → external provider call, OUTSIDE any DB transaction (SEND_MESSAGE only)
 → short transaction: record status + safe summaries + completed_at
```

### 9.1 Where pause/disable is re-checked

Three points, all required:

1. At trigger evaluation (the sweep/hook only considers `status = active`).
2. **Immediately before the claim**, under a fresh read of the automation
   row — disabling an automation before the claim **must** prevent the send.
3. **Inside the action job**, before the provider call, against re-fetched
   state.

### 9.2 No stale snapshot may authorize execution

State read at trigger time is never sufficient. Any state that changed
between trigger and action — Business deactivated, Workspace deactivated,
entitlement revoked, channel disabled, `SendingServer` disabled, automation
disabled, Contact moved or deleted — must abort before the provider call,
with the execution recorded as `skipped` and a safe reason.

---

## 10. SCHEDULER / QUEUE

**Keep** the existing skeleton; do not build a second queue infrastructure:

- `automation:run` console command
  (`app/Console/Commands/RunAutomation.php`), scheduled every five minutes
  at `app/Console/Kernel.php:88`.
- The dedicated `automation` queue, already drained by
  `queue:work --queue=automation,default,batch` at `Kernel.php:76`.
- The `AutomationJob` worker/batch pattern.

Adapt responsibilities:

- The sweep selects only `status = active`, `trigger_type =
  contact_date_reached`, and **`business_id IS NOT NULL`** — this is what
  makes legacy NULL-business automations inert (§3.5).
- The legacy `Automation::start()` birthday path and the legacy
  `Automation::send()`/`track_message()` path are no longer reachable from
  the command.
- `CONTACT_CREATED` does **not** wait for the five-minute sweep; it enters
  via the after-commit seam (§6.B) and is queued from there.

The scheduler's own cadence stays every five minutes; latency, not
correctness, depends on it (idempotency keys make a re-sweep safe).

---

## 11. HISTORY

`automation_executions` becomes the **authoritative** run/execution ledger.
The prior assumption that `tracking_logs` is the execution history is
retired.

- Business-scoped `TrackingLog`/`Reports` rows produced by the B1 send core
  continue to exist as message/accounting records and are **not** duplicated
  into `automation_executions`.
- `automations.cache` counters and `automations.last_error` are legacy and
  must not be extended; the ledger supersedes them.

UI history exposes only safe summaries: trigger, contact, action, status,
time, safe result/error. Never credentials, provider payloads, or raw
responses.

---

## 12. SECURITY — BLOCKING REQUIREMENTS

The implementation **must** close all mechanically-confirmed IDORs found in
reconnaissance. Current defects, for the record:

- `AutomationsController::show/enable/disable/delete/reports/sendNow`
  (lines 433, 341, 370, 398, 449, 613) accept a route-bound `Automation` and
  verify only the `automations` *capability*, never ownership.
- `EloquentAutomationsRepository::batchEnable/batchDisable/batchDelete`
  (lines 534, 563, 586) run
  `whereIn('uid', $requestIds)->update(...)`/`->delete()` with **no tenant
  filter at all** — a mass cross-tenant destructive IDOR.
- `delete()` (line 517) is likewise unscoped.

### 12.1 Required

Every single-record action (`show`, `enable`, `disable`, `delete`, history)
resolves the automation **through the already-resolved Business**, e.g.
`Automation::where('business_id', $business->id)->where('uid', $uid)`.
A foreign uid must 404 exactly like an unknown one.

### 12.2 Batch actions — DECISION

The M2 list UI (§14) does not require batch operations. Per the task's own
preference, **delete the batch endpoints and leave no replacement**:
`batch_action` route, `AutomationsController::batchAction()`, and
`batchEnable`/`batchDisable`/`batchDelete` in the repository are removed
rather than re-secured. This eliminates the most severe defect by deleting
the surface instead of guarding it.

If a future pass reintroduces batch actions, each must operate strictly
inside one explicitly resolved Business, and a global
`whereIn('uid', $requestIds)` mutation is permanently forbidden.

### 12.3 Mass assignment

`business_id`, `trigger_type`, `trigger_config`, `action_type`,
`action_config` must never be filled from raw request input. Configuration
is validated per trigger/action type and assembled server-side.

### 12.4 No new SSRF/injection surface

The engine performs no outbound HTTP today (verified: no `Http::`, `curl_`,
or `file_get_contents` in the model or either job), and B4 adds none — no
webhook action is in v1. No shell execution, no template evaluation of
user-supplied PHP, no credential rendering in views.

---

## 13. SEND NOW — REMOVED

The inherited `sendNow()` (`AutomationsController:613`) bypasses trigger and
idempotency semantics entirely and can fire and bill **another tenant's**
automation. It must **not** be carried forward.

**Decision: no arbitrary "send now" endpoint in B4 v1.** The route, the
controller method, and its UI affordance are removed.

No "test automation" concept is authorized in v1 either: it is not
mechanically necessary for the locked product, and the safe version of it
(an explicitly supplied test number, never recorded as a normal trigger
execution) would add surface without a proven need. If product UX later
requires it, it needs its own contract.

---

## 14. UI — M2 ONLY

One Business Automations area, three screens.

**List:** name, trigger summary, action summary, status, last execution,
primary "Create Automation" button.

**Create/Edit:** a simple bounded form — (1) name, (2) trigger type,
(3) trigger configuration, (4) action type, (5) action configuration,
(6) enabled/disabled. No node graph, no drag/drop, no conditions tree, no
fake multi-step UI.

**Detail/History:** definition summary, status, safe execution history,
enable/disable/delete.

All screens use existing M2 design-system components (`x-card`, `x-button`,
`x-badge`, `x-ds-icon`) consistent with the Prospecting/Workspace views.

---

## 15. LEGACY UI / ROUTE DISPOSITION

### 15.1 Views — exact mapping

| File | Disposition |
|---|---|
| `resources/views/customer/Automations/index.blade.php` (33 KB) | **REPLACE** with the M2 list. |
| `resources/views/customer/Automations/sayHappyBirthday.blade.php` (42 KB) | **DELETE** — replaced by the bounded create/edit form. Its entire legacy sender-id/phone-number/DLT/voice/MMS/WhatsApp/Viber/OTP matrix is out of scope. |
| `resources/views/customer/Automations/create.blade.php` | **DELETE** — a chooser listing one option; folded into the list page's primary action. |
| `resources/views/customer/Automations/overview.blade.php` (18 KB) | **REPLACE** with the M2 detail/history. |
| `resources/views/customer/Automations/_overview.blade.php` | **DELETE** (partial of the replaced overview). |
| `resources/views/customer/Automations/_reports.blade.php` | **DELETE** (replaced by ledger-backed history). |

### 15.2 Old flat routes

The flat `/automations/...` group (`routes/customer.php:486–503`) is
User-scoped and is the bypass risk. Disposition:

- **Remove** every per-record and mutating flat route: `search`, `create`,
  `say-happy-birthday` (GET+POST), `{automation}/show`, `{automation}/enable`,
  `{automation}/disable`, `{automation}/delete`, `batch_action`,
  `{automation}/reports`, `{automation}/{subscriber}/send`,
  `tags/get-data/{id}`. None may survive as a compatibility shim.
- **Retain** exactly one bare entry route, `GET /automations`, re-pointed to
  a Business chooser following the **B1 entry convention** verbatim
  (`OutreachController::entry()`, lines 70–85):
  - 0 accessible Businesses → empty-state view;
  - exactly 1 → `redirect()->route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid])`;
  - more than 1 → chooser view.
  Never guess or infer a primary Business.

### 15.3 Navigation

`app/Helpers/Helper.php` lines 1047–1053 (customer menu entry, `access =>
'automations'`) keeps pointing at `url('automations')`, which now resolves
through the chooser. This is the **only** `Helper.php` change authorized,
and no admin nav is touched (§17).

---

## 16. PERMISSIONS

Keep the existing customer capability `automations`
(`config/customer-permissions.php:25`, `default => true`). No split, no
A3/Admin RBAC redesign.

**Capability authorization is not tenant authorization.** Every request
still performs the full §2.2 chain (Business access + entitlement). The
existing `$this->authorize('automations')` call remains, but is never
sufficient on its own.

---

## 17. B3 NON-OVERLAP — VERIFIED

Lane B owns `agent/b3-simplified-platform-settings`. B4 must not modify:
`SettingsController`, `EloquentSettingsRepository`, `AppConfig`, admin
settings views, B3 settings requests, B3 settings routes/nav.

**Mechanically verified: there is no overlap.** The entire Automations
surface reads only `config('app.stage')` and `config('app.trai_dlt')` —
plain config files, never `AppConfig` or the settings repository (grep across
`AutomationsController`, `EloquentAutomationsRepository`, and
`Automation`). B4's only shared-file touch is the *customer* nav URL in
`app/Helpers/Helper.php` (§15.3).

---

## 18. IMPLEMENTATION ALLOWLIST

Only these paths may change in the B4 implementation branch:

**Models / enums / services**
- `app/Models/Automation.php`
- `app/Models/AutomationExecution.php` *(new)*
- `app/Enums/Automation/AutomationTriggerType.php` *(new)*
- `app/Enums/Automation/AutomationActionType.php` *(new)*
- `app/Enums/Automation/AutomationExecutionStatus.php` *(new)*
- `app/Library/Automation/**` *(new — trigger evaluation, execution claim, action dispatch; bounded per §8)*

**Controllers / requests / routes / nav**
- `app/Http/Controllers/Customer/Business/AutomationsController.php` *(new)*
- `app/Http/Controllers/Customer/AutomationsController.php` *(reduced to the entry chooser, or deleted with the chooser moved to the new controller)*
- `app/Http/Requests/Automations/**`
- `routes/customer.php`
- `app/Helpers/Helper.php` *(customer Automations nav URL only)*

**Repository / jobs / command**
- `app/Repositories/Contracts/AutomationsRepository.php`
- `app/Repositories/Eloquent/EloquentAutomationsRepository.php`
- `app/Jobs/AutomationJob.php`
- `app/Jobs/SendAutomationMessage.php`
- `app/Console/Commands/RunAutomation.php`

**Contact hook**
- `app/Repositories/Eloquent/EloquentContactsRepository.php` *(after-commit dispatch at the two in-scope creation methods only — no other behavior change)*
- `app/Events/Automation/**` *(new, if an event is used for the after-commit seam)*

**Views**
- `resources/views/customer/Automations/**`

**Migrations (additive only)**
- one migration adding the five `automations` columns + index + FK
- one data-only backfill migration (§3.4)
- one migration creating `automation_executions`

**Tests**
- `tests/Feature/Automations/**` *(new)*

No unspecified broad refactor is authorized. Any file not listed here
requires a new contract.

---

## 19. IMPLEMENTATION STOP-LIST

Explicitly forbidden in B4:

B3 Settings (`SettingsController`, `EloquentSettingsRepository`, `AppConfig`,
admin settings views/requests/routes/nav); Agency Prospecting (all
`AgencyProspect*`); B1/B2 redesign; provider credentials; UsageWallet
redesign; legacy billing cutover; DLR inbound rewiring; ChatBox tenancy
migration; Calendar; Forms; Payments; Website; SEO; Ads; AI COO; AI
Workforce; Opportunities trigger/action; webhook trigger/action; generic
workflow framework; RFC changes; `AI-AUTONOMY-STATE.json`.

Also forbidden: dropping `running_pid` or any other legacy column (§3);
adding `automations.workspace_id` (§3.3); any `attempt_number`-driven
automatic retry (§4.2, §5).

---

## 20. TEST CONTRACT

Focused suite `tests/Feature/Automations/**`. Required coverage:

**Authorization / tenancy**
1. Business authorization: Workspace owner, active Admin, Staff (per the
   existing Business-access convention).
2. Foreign Workspace and foreign Business fail closed (404-equivalent).
3. Inactive Workspace and inactive Business denied.
4. Entitlement denied (`decide()` returns not-allowed) blocks access and
   execution.
5. Legacy NULL-business automation is unreachable via any Business route
   **and** never executes in the sweep.
6. Single-record IDOR attempts (show/enable/disable/delete/history with a
   foreign automation uid) all fail closed and mutate nothing.
7. Batch IDOR: since batch endpoints are removed (§12.2), assert the routes
   no longer exist.

**Triggers**
8. `CONTACT_CREATED` fires only after commit, only for a Contact with an
   explicit `business_id`, and not for the `DLRController`/NULL-business
   path.
9. `CONTACT_DATE_REACHED` matches the correct contacts for the configured
   field/offset/time in the Business timezone.
10. Date-occurrence idempotency: re-running the sweep in the same occurrence
    year produces no second execution.
11. Duplicate trigger race: two concurrent evaluations produce exactly one
    execution (deterministic interleaving, not sleeps).
12. Execution unique-key race: a second insert with the same
    `idempotency_key` is caught and results in no provider call.

**Execution safety**
13. Disabling the automation before the claim prevents the send.
14. Channel disabled before send prevents the send.
15. Business/Workspace deactivated or entitlement revoked between trigger
    and action prevents the send.
16. Business sender/channel tampering: a channel or sender belonging to
    another Business is rejected.
17. External send is at-most-once: an already-claimed key (in `pending`,
    `failed`, **and** `succeeded`) never calls the provider again.
18. Provider failure records `failed` and triggers **no** automatic retry.

**Actions**
19. `UPDATE_CONTACT_FIELD` cross-Business protection: a field not belonging
    to the Contact's Business/group is rejected.
20. `UPDATE_CONTACT_FIELD` is idempotent and recorded once.

**History / routes / separation**
21. Safe execution history exposes only summaries — no credentials or
    provider payloads.
22. Old flat routes cannot bypass tenancy (removed routes 404; the bare
    entry never guesses a Business).
23. Agency Prospecting separation: no `AgencyProspect*` row is created or
    mutated by any automation path.

**Regression (run, not re-authored)**
24. B1 Outreach + B2 MessagingChannels.
25. CRM/Contacts + Conversations/ChatBox.
26. Workspace + Entitlement.
27. Usage.
28. Security suite.
29. **One** full suite at the end.

**Concurrency-test rule:** no timing/sleep-based tests. Where an
interleaving must be proven, use a deterministic hook (e.g. a test double
that mutates state at the exact seam, as Agency Prospecting's
`FakeAgencyProspectingAiClient::$beforeReturn` does) or prove both
serialized orderings explicitly.

**Known baseline:** `BrandingAdminFooterRenderTest` fails identically in
isolation on current main; Usage-wallet concurrency tests are historically
nondeterministic under load. Classify only with direct evidence.

---

## 21. OPEN DECISION FOR THE HUMAN

None blocking. The contract is implementable as written.

One product note, recorded rather than assumed: B4 v1 deliberately ships no
inbound-message/keyword trigger. That trigger is *technically* reachable
(the `keywords` table is Business-scoped and `DLRController` is the inbound
path) but would first require a Business-scoped inbound domain event —
today's `App\Events\MessageReceived` is a `ShouldBroadcastNow` UI broadcast
carrying a legacy `$user`, not a Business. Adding it is a clean follow-on,
not a B4 v1 obligation.
