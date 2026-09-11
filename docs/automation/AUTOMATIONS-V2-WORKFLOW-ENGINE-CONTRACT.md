# AUTOMATIONS V2 — VISUAL MULTI-STEP WORKFLOW ENGINE CONTRACT

**Status:** Reconnaissance and implementation contract. **No product code,
migration, route, configuration, view, asset or dependency is changed by this
document.** It authorizes nothing by itself; each slice in §16 starts only when
separately authorized.

**Base:** `origin/main` at `f6cfd8897b45a4be63073561a9050f080fdbee05`
(PR #249). Customer Experience Slice 3 (managed messaging) is merged; CX
Slice 5 (wallet/payer) is merged; CX Slice 6 (managed default-sender) is
**not** merged; Redesign Slice 2B's `chat_boxes.business_id`
(`2026_09_14_100001`/`100002`) is merged.

**Route:** a separately, explicitly human-authorized manual lane (CLAUDE.md
route 3, AGENTS.md §B). The autonomous loop stays paused
(`implementation_authorized: false`, `gate_label: "ai:paused"`); nothing here
activates, resumes or bypasses it, and no field in
`docs/automation/AI-AUTONOMY-STATE.json` is changed.

---

## 0. AUTHORITY, SUPERSESSION AND RECONCILIATION

### 0.1 What this contract supersedes

The product owner has decided that the B4 single-step model is no longer the
long-term product. This contract is the explicit, recorded amendment that
decision requires. It supersedes, **for the product direction only**:

| Prior statement | Where | Disposition |
|---|---|---|
| "Explicitly **not**: … a visual graph engine, multi-step branching, a conditions tree" | `B4-BUSINESS-AUTOMATIONS-CONTRACT.md` §1 | **Superseded as product direction.** B4 §1.1's *reasoning* remains correct and is the foundation of this contract: the inherited `automations` row genuinely cannot represent a graph, which is why v2 adds new tables rather than contorting it (§4) |
| Wait/condition primitives are "a genuine architecture change to B4's locked single-step model" requiring "its own contract amendment to B4" (recorded as C-8) | `AUTOMATION-TRIGGER-EVENT-AND-GUIDED-RECIPE-EXPANSION-CONTRACT.md` §11 Slice 7, §13 | **This contract is that amendment.** C-8 is satisfied here |
| "Advanced builder — no new work; the existing `form.blade.php` custom path already satisfies this" | same, §11 Slice 9 | **Superseded.** The owner has chosen a visual builder; `form.blade.php` is retired in §15 |

### 0.2 What this contract preserves, verbatim in force

Every B4 safety invariant carries into v2 and is re-proven, not assumed
(§7, §17): Business-scoped tenancy and its per-request resolution order
(B4 §2.2); `EntitlementManager::decide()` never `decideForWorkspace()`
(B4 §2.4); background identity = `$business->customer_id`, never `Auth::id()`
in a job (B4 §2.5); **at-most-once external action, claimed durably before
any provider call, never automatically re-sent** (B4 §5.1); provider calls
outside every DB transaction; a final authoritative checkpoint before each
action (B4 §5.5); **bulk-import-created Contacts fire nothing** (B4 §6.B);
SMS sends only through `CampaignRepository::quickSend()`, never a parallel
provider path (B4 §7.A); `$tries = 1`, no backoff.

Lane F's loop and abuse rules (`AUTOMATION-TRIGGER-…-CONTRACT.md` §6) carry
in unchanged: causation-depth cap of 3; STOP/consent re-checked at the action
boundary, never cached from claim time; `chunkById(50)` fan-out bounding;
exactly one `business_id` verified at every re-check point.

### 0.3 What this contract does not re-decide

Lane F's feasibility classifications (§3/§4 of that contract) are
**re-verified against current main and confirmed**, with two updates recorded
where main has moved (§9). The CX contract's §15.1 prohibition on adapting the
legacy `App\Events\MessageReceived` broadcast stands. CX §28.8's "defer tags
until a real tag entity exists" stands. The Website contract's test-enforced
absence of forms (`tests/Feature/Website/WebsiteBoundaryTest.php`) stands.

---

## 0.4 THE DESIGN IN SIMPLE PRODUCT TERMS

**What a customer sees.** An Automations list of named workflows with a
trigger, a status and a last-run time. Opening one shows a canvas: the
trigger at the top, steps flowing downward, a **+** between every pair of
steps, and an **If / Else** step that splits the flow into a "Yes" lane and a
"No" lane side by side. Clicking any step opens a panel on the right to
configure it. Changes save automatically as a draft. **Publish** makes the
workflow live.

**The one structural rule that makes everything else simple.** A workflow is
a **tree**: it starts at one trigger, and every If / Else splits into two
lanes that never join back together and never loop. That single rule is what
lets the canvas lay itself out with no drawing tool, lets the database prove
there are no loops, and lets each step run at most once per contact.

**Drafts and live versions.** Editing never touches what is live. Publishing
freezes the draft into a numbered, permanent version. A contact who entered
on version 3 finishes on version 3 even if version 4 is published halfway
through their journey. New contacts always start on the newest version.

**A contact's journey is an "enrollment."** It has a bookmark pointing at the
step it is on. Each step records exactly one run, enforced by the database, so
a text message cannot be sent twice even if the server hiccups. A **Wait**
step parks the enrollment with a wake-up time that is checked every minute —
it survives restarts and never depends on anyone's browser.

**What it can do at launch.** Start when a contact is created, when a contact
date arrives (birthdays, anniversaries), or when someone is enrolled by hand.
Send a text, update a contact field, notify the team, wait, branch on a
condition, or end. **Message received** follows in its own slice.

**What it honestly cannot do yet, and why.** Forms, appointments, customer
payments, tags, email, sales pipelines and "assign to user" have **no
underlying feature in this product today** — there is nothing for a workflow
to listen to or act on. Each is listed with the specific missing piece (§9,
§10) rather than faked.

**How it is built.** No new frontend framework. The canvas is a nested list
drawn with the tools the app already ships — Bootstrap 5 and plain
JavaScript — because a tree needs no graph library.

---

## 1. EXACT CURRENT IMPLEMENTATION INVENTORY

Every entry below was read on `f6cfd88`.

### 1.1 B4 engine (shipped, live)

| Concern | Path | What it is |
|---|---|---|
| Definition model | `app/Models/Automation.php` (156 lines) | B4 definition/state model; no longer `extends SendCampaignSMS` |
| Execution ledger | `app/Models/AutomationExecution.php` | One row per (automation × contact × occurrence) |
| Trigger enum | `app/Enums/Automation/AutomationTriggerType.php` | **Two cases:** `contact_date_reached`, `contact_created` |
| Action enum | `app/Enums/Automation/AutomationActionType.php` | **Two cases:** `send_message`, `update_contact_field` |
| Execution status | `app/Enums/Automation/AutomationExecutionStatus.php` | `pending`, `succeeded`, `failed`, `skipped` |
| Trigger evaluation | `app/Library/Automation/AutomationTriggerEvaluator.php` (114) | `dueForDateReached()` — Business-timezone date match; `automationsForCreatedContact()` |
| Claim service | `app/Library/Automation/AutomationExecutionClaimService.php` (215) | UNIQUE-key claim + locked stale-definition checkpoint + start claim |
| Eligibility | `app/Library/Automation/AutomationEligibility.php` (184) | Final authoritative checkpoint (B4 §5.5) |
| Definition validation | `app/Library/Automation/AutomationDefinitionValidator.php` (243) | Per-trigger/per-action config validation |
| Dispatch | `app/Library/Automation/AutomationActionDispatcher.php` (39) | Routes to one of two action classes |
| SMS action | `app/Library/Automation/Actions/SendMessageAction.php` (213) | Requires an active Business `CustomerBasedSendingServer` (line 62), then `CampaignRepository::quickSend()` (line 139) |
| Field action | `app/Library/Automation/Actions/UpdateContactFieldAction.php` (73) | Writes one custom field on the trigger Contact |
| Sweep command | `app/Console/Commands/RunAutomation.php` | `automation:run`: active `contact_date_reached` rows, `chunkById(50)`, dispatches `AutomationJob::forDateSweep()` |
| Evaluator job | `app/Jobs/AutomationJob.php` | `forDateSweep()` / `forContactCreated()`, queue `automation` |
| Action job | `app/Jobs/SendAutomationMessage.php` | Claims start, final checkpoint, dispatches the action |
| Controller | `app/Http/Controllers/Customer/Business/AutomationsController.php` (385) | listing/create/store/show/edit/update/enable/disable/destroy |
| Legacy entry | `app/Http/Controllers/Customer/AutomationsController.php` (74) | Business-picker entry, never guesses a Business |
| Request | `app/Http/Requests/Automations/AutomationDefinitionRequest.php` | |
| Repository | `app/Repositories/Eloquent/EloquentAutomationsRepository.php` | |
| Views | `resources/views/customer/Automations/{entry,index,form,overview}.blade.php` | `form.blade.php` is a single page with trigger and action selectors |
| Routes | `routes/customer.php:811-820` | `{workspaceUid}/businesses/{businessUid}/automations` group; entry at `:548` |
| Permission | `config/customer-permissions.php:25` | One key: `automations` |

**Scheduling, mechanically verified** (`app/Console/Kernel.php`):

* `:93` — `automation:run` **every five minutes**.
* `:78` — `queue:work --queue=automation,default,batch --timeout=120 --tries=1 --max-time=180 --stop-when-empty` **every minute**.

**The queue worker is cron-driven, not a supervisor.** It starts each minute,
drains, and exits. Effective scheduling resolution is therefore one minute,
and any design relying on a long-lived worker is invalid here. Queue driver:
`config('queue.default')` = `env('QUEUE_CONNECTION', 'database')`.

### 1.2 Schema (shipped)

| Migration | Effect |
|---|---|
| `2023_02_26_124444_create_automations_table.php` | Legacy table |
| `2026_09_07_120001_add_business_fields_to_automations_table.php` | B4: `business_id`, `trigger_type`, `trigger_config`, `action_type`, `action_config` |
| `2026_09_07_120002_backfill_business_id_for_automations.php` | B4 deterministic backfill |
| `2026_09_07_120003_create_automation_executions_table.php` | B4 ledger, UNIQUE `idempotency_key` |

### 1.3 Downstream readers of the B4 ledger

`automation_executions` is read outside the engine by
`app/DTO/Analytics/AutomationKpis.php`, `app/DTO/Analytics/BusinessAnalyticsViewModel.php`,
`app/Library/Analytics/BusinessAnalyticsQueries.php`,
`app/Library/Dashboard/DashboardStatusReader.php` and
`app/Library/GoogleBusinessProfile/GoogleBusinessProfileConnectionManager.php`.
**v2 must not break these** (§15.4, slice V2-H).

### 1.4 Adjacent domains — what exists

| Domain | Exists? | Evidence |
|---|---|---|
| Contacts | **Yes**, Business-scoped | `app/Models/Contacts.php`; `business_id`; **single** `group_id` (`:32`, `:48`); phone unique **per group** (`:291`) |
| Contact groups | **Yes** | `app/Models/ContactGroups.php`; fields belong to a group (`contact_group_fields.contact_group_id`) |
| Contact custom fields | **Yes** | `contacts_custom_field.value` keyed by `field_id` |
| Contact creation seams | **Yes** | `EloquentContactsRepository::storeContact()` (`:233`, B4 dispatch `:263`) and `createContactFromRequest()` (`:660`, B4 dispatch `:715`) — the latter has **four** callers: `API/ContactsController.php:83`, `API/ContactsHTTPController.php:102`, `Customer/ContactsController.php:866` (in-app), `Customer/ContactsController.php:1542` (public opt-in form) |
| Group copy/move | **Yes, no event** | `batchContactCopy()` (`:501`), `batchContactMove()` (`:528`) |
| Contact update | **Yes, no event** | `updateContact()` (`:381`), `updateContactStatus()` (`:344`) |
| Bulk import | **Yes, raw SQL** | `ContactGroups::import()` via `App\Jobs\ImportContacts` — B4 policy: fires nothing |
| Conversations | **Yes, now Business-scoped** | `chat_boxes.business_id` added by `2026_09_14_100001`, backfilled by `…100002` |
| Inbound message event | **Broadcast only** | `App\Events\MessageReceived` is `ShouldBroadcastNow`, dispatched once from `DLRController.php:1063`; the managed path (`InboundWebhookAttributionResolver::persistInbound`, `:468`) emits nothing |
| Managed messaging | **Yes, merged** | Slice 3; `quickSend()` delegates managed sends via `ManagedDispatchDelegate` |
| Internal notifications | **Yes** | `notifications` table (`2021_04_08_140645`); Laravel `Notification` classes in `app/Notifications/` |
| After-commit event convention | **Yes** | 70+ events implement `ShouldDispatchAfterCommit` (e.g. `App\Events\Opportunity\*`, `App\Events\Business\*`) |
| Forms | **No — and test-enforced absent** | No model, table or POST route; `WebsiteBoundaryTest` asserts absence |
| Calendar / appointments | **No** | No model, table or migration |
| Business→client payments | **No** | Payment tables are platform billing and wallet funding (Lane F §3.4) |
| Tags | **No real entity** | `Contacts::getTags()` decodes a JSON string; CX §28.8 defers tags |
| Sales pipeline / deals | **No** | `Opportunity` is the AI-COO recommendation engine (`worker_key`, `fingerprint`, `impact`, `urgency`, `confidence`, `priority_score`) — not a CRM |
| Contact assignee | **No** | No owner/assignee column on `contacts` |
| Business→contact email | **No** | Every `Mail`/`notify()` use is platform→user (receipts, low balance) |
| Outbox / event envelope | **No** | Lane F Slice 1 was never built |

### 1.5 Frontend stack

`package.json` dependencies: `bootstrap ~5.1.0`, `flatpickr`, `laravel-echo`,
`pusher-js`, `@fontsource-variable/geist`; dev: `jquery ^3.6`, `laravel-mix ^6`,
`axios`, `lodash`, `sass`. **No component framework** (no Vue, React, Alpine
or Svelte). Build: `webpack.mix.js` — page scripts under
`resources/js/scripts/**` are concatenated by `mix.scripts()` (no module
bundling); bundled entries use explicit `.js()` calls (`:70-73`). Design-system
Blade components exist under `resources/views/components/` (`button`, `card`,
`dialog`, `empty-state`, `input`, `menu`, `select`, `switch-toggle`, `table`,
`tabs`, `badge`, `alert`, `pagination`). **Bootstrap 5.1 ships `offcanvas`**,
which is exactly the configuration-drawer primitive the builder needs.

---

## 2. CURRENT LIMITATIONS

1. **One trigger, one action, no sequence.** The definition is five columns on
   one row; there is nowhere to put a second step.
2. **No wait.** Nothing can pause a contact's progress and resume it later.
3. **No condition.** Nothing branches.
4. **No versioning.** Editing an active automation mutates the definition any
   in-flight run reads. B4 mitigates this with a stale-definition lock
   (B4 §5.2), which is correct for one step and does not scale to a journey
   lasting weeks.
5. **No per-step history.** The ledger records one action per run.
6. **Managed Businesses cannot send from B4.** `SendMessageAction` requires an
   active `CustomerBasedSendingServer` (line 62) before reaching `quickSend()`;
   a managed Business has none. CX Slice 6 owns the fix and has not merged.
7. **The builder is a form**, not a canvas.
8. **No manual enrollment.**

---

## 3. TARGET ARCHITECTURE

```
            ┌──────────────────── builder (vanilla JS + Bootstrap offcanvas) ───────────────┐
            │  edits a DRAFT document  ──autosave──►  automation_workflow_versions (draft)  │
            └───────────────────────────────────────────────┬───────────────────────────────┘
                                                            │ Publish
                                                            ▼
                               WorkflowCompiler: validate → tree-check → tenancy-check
                                                            │
                         immutable automation_workflow_nodes + automation_workflow_edges
                                                            │
   trigger sources ──after-commit──► WorkflowEnrollmentService ──► automation_enrollments
   (contact created,                  (UNIQUE enrollment_key,        (cursor + status)
    date sweep, manual,                active-contact guard)                │
    later: message received)                                                ▼
                                          AdvanceWorkflowEnrollment job ── WorkflowAdvancer
                                          (one node per claim; UNIQUE(enrollment, node))
                                                            │
                       ┌──────────────┬─────────────┬───────┴──────┬──────────────┐
                    Send SMS     Update field   Notification     Wait          If / Else
                  (quickSend)                                (resume_at)     (branch)
                                                            │
                               automation:workflows-resume-due  (every minute)
                               wakes WAITING enrollments + recovers interrupted ones
```

**Three layers, three rules.**

* **Definition** (what the owner edits): a JSON document, validated on every
  save, stored only on version rows. **The runtime never reads it.**
* **Compiled graph** (what runs): relational node and edge rows, written once
  at publish, never updated. The runtime reads only these.
* **Execution** (what happened): enrollments and step runs, each step claimed
  by a database unique key before any side effect.

This split is the direct answer to "do not create brittle JSON-only runtime
semantics": structure is relational and FK-enforced; only each node's leaf
configuration is JSON, validated by a code-backed registry — the same pattern
B4 already uses for `trigger_config`/`action_config`.

---

## 4. SCHEMA

Six new tables. All additive. **No B4 table is altered** by any v2 slice except
the single status value in §15.2, which uses the existing string column.

Every table carries `business_id` (NOT NULL, FK `businesses`,
`restrictOnDelete`, indexed) — denormalised deliberately, following B4's
`automation_executions` precedent, so tenancy is checkable on every row without
a join through a possibly-deleted parent.

### 4.1 `automation_workflows` — stable identity

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `id()` | no | |
| `uid` | `uuid`, unique | no | Public identifier |
| `business_id` | FK `businesses`, `restrictOnDelete` | no | A workflow belongs to exactly one Business |
| `name` | `string(120)` | no | |
| `status` | `string(16)` | no | `draft` \| `published` \| `paused` \| `archived` |
| `published_version_id` | FK `automation_workflow_versions`, `nullOnDelete` | yes | The version new enrollments use |
| `enrollment_policy` | `string(32)` | no | §7.5; default `once_ever` |
| `failure_policy` | `string(16)` | no | `halt` (default) — §7.6 |
| `legacy_automation_id` | FK `automations`, `nullOnDelete`, **unique** | yes | Set only by the B4 converter (§15); prevents double conversion |
| `created_by_user_id` | FK `users`, `nullOnDelete` | yes | Audit only — never authorization |
| `timestamps`, `archived_at` | | | |

Indexes: `business_id`; `(business_id, status)`.

### 4.2 `automation_workflow_versions` — drafts and immutable published versions

| Column | Type | Null | Notes |
|---|---|---|---|
| `id`, `uid` | | no | |
| `workflow_id` | FK, `cascadeOnDelete` | no | |
| `business_id` | FK, `restrictOnDelete` | no | Denormalised |
| `version_number` | `unsignedInteger` | no | 1, 2, 3… per workflow |
| `state` | `string(16)` | no | `draft` \| `published` \| `superseded` |
| `definition` | `json` | no | The editor document (§5.3); **never read by the runtime** |
| `definition_revision` | `unsignedInteger` | no | Optimistic-concurrency counter for autosave (§14.4) |
| `definition_hash` | `char(64)` | yes | SHA-256 of the canonical document, set at publish |
| `trigger_type` | `string(32)` | yes | Denormalised from the root node at publish, for the trigger index |
| `node_count` | `unsignedSmallInteger` | yes | Set at publish |
| `published_at` | `timestamp` | yes | |
| `published_by_user_id` | FK `users`, `nullOnDelete` | yes | |
| `timestamps` | | | |

Uniqueness, using the repository's proven **STORED generated guard column +
ordinary UNIQUE** pattern (`2026_08_16_140001_create_payment_provider_customers_table.php`,
`2026_09_12_100001_create_business_messaging_identities_table.php`) — MySQL
has no partial unique index:

* `UNIQUE(workflow_id, version_number)`.
* `draft_guard` = `CASE WHEN state = 'draft' THEN workflow_id ELSE NULL END`,
  `UNIQUE(draft_guard)` — **at most one draft per workflow, DB-enforced.**
* `published_guard` = `CASE WHEN state = 'published' THEN workflow_id ELSE NULL END`,
  `UNIQUE(published_guard)` — **at most one published version per workflow,
  DB-enforced.**

Index: `(business_id, trigger_type, state)` — the trigger-ingestion lookup.

### 4.3 `automation_workflow_nodes` — compiled, immutable

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `id()` | no | |
| `version_id` | FK `automation_workflow_versions`, `cascadeOnDelete` | no | |
| `business_id` | FK, `restrictOnDelete` | no | Denormalised |
| `node_key` | `string(36)` | no | Stable key from the editor document; survives re-publish |
| `node_type` | `string(32)` | no | Code-backed enum (§5.2) |
| `config` | `json` | no | Validated per node type by the registry |
| `depth` | `unsignedTinyInteger` | no | If/Else nesting depth; root = 0 |
| `created_at` | `timestamp` | no | No `updated_at` — rows are never updated |

`UNIQUE(version_id, node_key)`. Index `version_id`.

### 4.4 `automation_workflow_edges` — compiled, immutable

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `id()` | no | |
| `version_id` | FK, `cascadeOnDelete` | no | |
| `from_node_id` | FK `automation_workflow_nodes`, `cascadeOnDelete` | no | |
| `to_node_id` | FK `automation_workflow_nodes`, `cascadeOnDelete` | no | |
| `edge_kind` | `string(8)` | no | `next` \| `yes` \| `no` |

* `UNIQUE(from_node_id, edge_kind)` — **a node has at most one outgoing edge
  of each kind, DB-enforced.**
* `UNIQUE(to_node_id)` — **every node has at most one parent, DB-enforced.**
  In-degree ≤ 1 is what makes the graph a tree: no merges, no joins. Together
  with the compiler's reachability proof (§5.4), cycles are impossible.

A future "go to step" or merge feature would drop the second index in its own
contract. It is recorded here so the constraint is not mistaken for an
accident.

### 4.5 `automation_enrollments` — one contact's journey through one pinned version

| Column | Type | Null | Notes |
|---|---|---|---|
| `id`, `uid` | | no | |
| `business_id` | FK, `restrictOnDelete` | no | |
| `workflow_id` | FK, `restrictOnDelete` | no | Archived, never deleted, while enrollments exist |
| `version_id` | FK `automation_workflow_versions`, `restrictOnDelete` | no | **The pin.** A version cannot be deleted while anyone is enrolled on it |
| `contact_id` | FK `contacts`, `cascadeOnDelete` | no | Matches B4 `automation_executions.contact_id` |
| `status` | `string(16)` | no | §7.1 |
| `current_node_id` | FK `automation_workflow_nodes`, `restrictOnDelete` | yes | **The cursor.** NULL once terminal |
| `resume_at` | `timestamp` | yes | Set only while `waiting` |
| `trigger_type` | `string(32)` | no | Denormalised |
| `trigger_occurrence_key` | `string(191)` | no | e.g. `2026` for a yearly date, a message id, a manual-request uid |
| `enrollment_key` | `string(191)`, **UNIQUE** | no | §7.5 — the enrollment idempotency claim |
| `causation_depth` | `unsignedTinyInteger` | no | Lane F §6.1; default 0 |
| `step_count` | `unsignedSmallInteger` | no | Defense-in-depth runaway guard |
| `enrolled_at`, `last_advanced_at`, `completed_at` | `timestamp` | mixed | |
| `exit_reason` | `string(64)` | yes | Bounded, human-safe |
| `timestamps` | | | |

* `active_contact_guard` = `CASE WHEN status IN ('active','waiting') THEN contact_id ELSE NULL END`,
  `UNIQUE(workflow_id, active_contact_guard)` — **a contact can never be
  in the same workflow twice at once, under any enrollment policy,
  DB-enforced.**
* Indexes: `(status, resume_at)` — the resume sweep; `(status, last_advanced_at)`
  — the recovery sweep; `(workflow_id, created_at)` — enrollment history;
  `(business_id, created_at)` — analytics; `contact_id`.

### 4.6 `automation_step_runs` — one row per node executed

| Column | Type | Null | Notes |
|---|---|---|---|
| `id`, `uid` | | no | |
| `business_id` | FK, `restrictOnDelete` | no | |
| `enrollment_id` | FK, `cascadeOnDelete` | no | |
| `node_id` | FK `automation_workflow_nodes`, `restrictOnDelete` | no | |
| `node_type` | `string(32)` | no | Denormalised for display |
| `status` | `string(16)` | no | §7.2 |
| `branch_taken` | `string(8)` | yes | `yes` \| `no` for If / Else |
| `started_at`, `completed_at` | `timestamp` | mixed | `started_at` is set in the claim transaction |
| `safe_result_summary`, `safe_error_summary` | `string(255)` | yes | B4 §4.3 content rules: no provider bodies, credentials or full message text |
| `timestamps` | | | |

**`UNIQUE(enrollment_id, node_id)`** — because the graph is a tree, a node is
visited at most once per enrollment. This one index is simultaneously the step
idempotency key and the **DB-enforced at-most-once guarantee**, inheriting B4
§5.1 directly.

Index: `(enrollment_id, created_at)` — execution log.

### 4.7 Migration rules

* Timestamps are chosen **at implementation start** from the actual latest
  merged migration (`2026_09_14_100002` at this contract's base). This value is
  historical evidence of the base, not implementation guidance.
* `down()` drops in reverse dependency order: step runs → enrollments → edges →
  nodes → versions → workflows. Destructive of v2 run history by definition;
  each migration docblock says so (B4 §3.6 precedent).
* No B4 table, column or row is dropped, renamed or repurposed.

---

## 5. GRAPH MODEL

### 5.1 The tree rule

* Exactly one **root**, always a trigger node.
* Every non-root node has **exactly one parent** (`UNIQUE(to_node_id)`).
* An `if_else` node has exactly two outgoing edges, `yes` and `no`, each
  possibly empty (an empty lane ends immediately).
* Every other non-terminal node has at most one `next` edge.
* A node with no outgoing edge ends that path; the enrollment completes.
* **No loops, no merges, no "go to."**

### 5.2 Node types — code-backed registry

`App\Enums\Automation\Workflow\WorkflowNodeType`:

| Value | Kind | Side-effect class | Launch |
|---|---|---|---|
| `trigger` | root | none | Yes |
| `send_sms` | action | **external** | Yes |
| `update_contact_field` | action | idempotent DB | Yes |
| `internal_notification` | action | **external** | Yes |
| `wait` | logic | none | Yes |
| `if_else` | logic | none | Yes |
| `end` | logic | none | Yes |

The **side-effect class** is load-bearing: it decides recovery behaviour
(§7.4). Every node type is registered in
`App\Library\Automation\Workflow\NodeTypeRegistry` with its config validator,
its executor and its side-effect class. **An unregistered type is rejected at
validation, at compile and at execution.** Adding a node type is one enum case
plus one registry entry plus one executor — the AI seam in §19 is exactly that.

### 5.3 The definition document

```json
{
  "schema_version": 1,
  "root": {
    "key": "n_trigger",
    "type": "trigger",
    "config": { "trigger_type": "contact_created", "contact_group_id": 12 },
    "next": [
      { "key": "n_1", "type": "send_sms", "config": { "body": "Welcome, {first_name}!" } },
      { "key": "n_2", "type": "wait", "config": { "mode": "duration", "amount": 2, "unit": "days" } },
      { "key": "n_3", "type": "if_else",
        "config": { "match": "all", "conditions": [
          { "subject": "contact.replied_since_enrollment", "operator": "is_false" } ] },
        "yes": [ { "key": "n_4", "type": "send_sms", "config": { "body": "Just checking in…" } } ],
        "no":  [ { "key": "n_5", "type": "end", "config": {} } ] }
    ]
  }
}
```

A **nested list** — each node's `next` is an ordered array, and `if_else`
carries `yes` and `no` arrays. This shape *is* a tree: it cannot express a
merge or a cycle, so the editor cannot produce one. Node `key`s are
client-generated UUIDs, stable across saves and re-publishes, and they are
what validation errors point at.

### 5.4 Compilation (publish-time)

`App\Library\Automation\Workflow\WorkflowCompiler` turns the document into
rows. Pure, deterministic, unit-testable, and it enforces:

1. `schema_version` recognised; document ≤ 256 KB.
2. Exactly one root of type `trigger`.
3. Every node type registered; every config valid for its type.
4. Keys unique within the document.
5. **Limits** (§8.4): node count, If / Else depth, condition count.
6. **Tenancy** (§14.2): every referenced group, field, sender/channel and
   notification recipient belongs to the workflow's Business.
7. **Reachability**: every emitted node reachable from the root; `nodes =
   edges + 1`. With `UNIQUE(to_node_id)` this proves a tree.
8. Trigger/action compatibility (e.g. B4 §7.B: `update_contact_field` requires
   an explicit trigger group).

Output is inserted in **one transaction** with bulk inserts: all nodes, then
all edges.

---

## 6. VERSIONING AND PUBLISH MODEL

### 6.1 Lifecycle

```
 (new workflow) ──► DRAFT v1 ──Publish──► PUBLISHED v1
                                              │ edit
                                              ▼
                                  DRAFT v2 (cloned from v1's document)
                                              │ Publish
                                              ▼
                        PUBLISHED v2      v1 → SUPERSEDED (kept while pinned)
```

* **At most one draft and at most one published version** — both DB-enforced
  (§4.2).
* Editing a published workflow creates a draft by cloning the published
  document if none exists. **The published version is never touched.**
* **Publish** is one transaction: lock the workflow row `FOR UPDATE`; re-validate
  and compile the draft; insert nodes and edges; set the draft `state =
  published`, `published_at`, `definition_hash`, `node_count`; set the previous
  published version `superseded`; set `workflows.published_version_id` and
  `status = published`. Any failure rolls back everything; the prior version
  stays live.
* **Published and superseded versions are immutable.** Their node/edge rows are
  never updated (no `updated_at` column). A test asserts that no code path
  issues an `UPDATE` against them.

### 6.2 Pinning

* An enrollment records `version_id` at enrollment and **never changes it.**
* In-flight enrollments finish on their pinned version, regardless of later
  publishes.
* New enrollments use `workflows.published_version_id` at enrollment time.
* A superseded version is retained while any enrollment references it
  (`restrictOnDelete`) and for history thereafter.

### 6.3 Pause, resume, archive

| Action | New enrollments | In-flight enrollments |
|---|---|---|
| **Pause** | Stopped | **Held** — no step executes while paused; each resumes where it stopped |
| **Resume** | Restart | Continue from their cursor |
| **Archive** | Stopped permanently | Cancelled with `exit_reason = workflow_archived` |
| **Stop all active** (explicit, confirmed) | Unaffected | Cancelled with `exit_reason = stopped_by_user` |

Pause takes the workflow row lock `FOR UPDATE`; every step claim takes it in
**shared** mode (§7.3). Shared locks do not block each other, so enrollments
advance concurrently, but a pause commits either before a claim (the claim sees
`paused` and does not execute) or after it (that one step completes; the next
sees `paused`). **Pause is therefore fully serialized against execution with
no throughput cost in the unpaused case.**

---

## 7. EXECUTION STATE MACHINE

### 7.1 Enrollment states

```
                 enroll: INSERT, UNIQUE(enrollment_key) + active_contact_guard
                                      │
                                      ▼
      ┌──────────────────────────► ACTIVE ◄─────────────────────────────┐
      │                              │                                   │
      │        wait node reached     │     resume_at ≤ now (sweep,       │
      │    ─────────────────────► WAITING ──expected-status UPDATE)─────┘
      │                              │
      │   next node exists           ├──(no next edge / end node)──────► COMPLETED
      └──(step succeeded)────────────┤
                                     ├──(step failed, failure_policy=halt)► FAILED
                                     ├──(contact unsubscribed / deleted /
                                     │    Business inactive / lifetime) ─► EXITED
                                     └──(archive / stop all) ─────────────► CANCELLED
```

Terminal: `completed`, `failed`, `exited`, `cancelled`. On any terminal
transition `current_node_id` and `resume_at` become NULL.

### 7.2 Step-run states

`started` → `succeeded` | `failed` | `skipped`, plus `waiting` for a wait node
between arrival and wake-up. A step run is created **already `started`** in the
claim transaction (§7.3); there is no separate reserved state, because the
enrollment row lock already serializes duplicate job delivery.

### 7.3 Advancing one step — exact ordering

For an enrollment whose cursor is node `N`:

1. **Lock-free pre-check** (may be slow): workflow `published`, Business and
   Workspace active, `EntitlementManager::decide(…, Automations, $business->customer_id)`
   allowed, contact exists and `contact.business_id` matches. If the workflow is
   paused or entitlement is denied, **stop without claiming** — the enrollment
   stays `active` at `N` and is picked up after resume. If the contact is gone,
   unsubscribed (for a send), or the Business is gone, transition to `exited`.
2. **Claim transaction** (short, no I/O): lock the workflow row in **shared**
   mode; lock the enrollment `FOR UPDATE`; re-verify `workflow.status =
   published`, `enrollment.status = active`, `enrollment.current_node_id = N`,
   and `business_id` on every row; `INSERT` the step run for `(enrollment, N)`
   with `status = started`, `started_at = now`. Commit. A
   `UniqueConstraintViolationException` means another worker owns this step:
   **return without doing anything.**
3. **Execute** node `N` **outside any transaction**, receiving only objects
   re-read after step 2. External actions re-check consent at this boundary,
   never from claim time (Lane F §6.1).
4. **Record-and-advance transaction:** set the step run's terminal status and
   summaries; compute the successor (`next`, or `yes`/`no` for If / Else);
   then
   `UPDATE automation_enrollments SET current_node_id = :next, step_count = step_count + 1, last_advanced_at = now()`
   `WHERE id = :id AND current_node_id = :N AND status = 'active'`.
   **Zero affected rows means someone else moved it** (stop all, archive,
   another worker) — do nothing further. This **expected-cursor predicate** is
   deliberate: the Slice 3 final adversarial review found exactly this class of
   lost-update race in the managed delivery-status path, and v2 does not repeat
   it.
5. If the successor is executable now, loop — up to
   `MAX_STEPS_PER_ADVANCE_JOB` (§8.4) — then re-dispatch.

Why a single claim suffices where B4 needed two: B4 needed a separate start
claim because two workers could both process the same *execution row*. Here the
enrollment row lock in step 2 serializes them, and the second worker's `INSERT`
loses on `UNIQUE(enrollment_id, node_id)` before any side effect.

**Accepted, bounded window (B4 §5.5 precedent):** an entitlement revocation
landing between steps 1 and 2 lets that one step execute. This is a checkpoint
guarantee, not atomicity with external revocation, and is stated in the code.

### 7.4 Interrupted steps — recovery by side-effect class

A process can die after step 2 commits and before step 4. The step run stays
`started`, the cursor stays at `N`, and `last_advanced_at` goes stale. The
recovery sweep (§8.2) finds these after a threshold and applies:

| Side-effect class | Recovery |
|---|---|
| **none** (`trigger`, `if_else`, `wait`, `end`) | Safe to re-derive: re-evaluate, update the existing step-run row to terminal, advance. No side effect can have happened twice |
| **idempotent DB** (`update_contact_field`) | Safe to re-apply: the write sets a value to its configured value |
| **external** (`send_sms`, `internal_notification`) | **Never re-executed.** Step run → `failed` with `safe_error_summary = interrupted_outcome_unknown`; enrollment follows its failure policy. This is B4 §5.1 rule 4's accepted tradeoff, applied only where it is actually needed |

### 7.5 Enrollment policy and keys

| Policy | `enrollment_key` | Meaning |
|---|---|---|
| `once_ever` (**default**) | `wf:{workflow_id}:c:{contact_id}` | A contact goes through this workflow once, ever |
| `once_per_occurrence` | `wf:{workflow_id}:c:{contact_id}:o:{trigger_occurrence_key}` | Once per trigger occurrence (a yearly date, a distinct message) |

Under **every** policy the `active_contact_guard` also applies, so overlapping
enrollments are impossible. Keys are composed only from server-derived values —
never from request input.

`contact_date_reached` uses the B4 occurrence-year rule unchanged (the
four-digit year of the offset-adjusted local occurrence date).

### 7.6 Failure policy

Default `halt`: a failed action ends the enrollment as `failed`, so no
follow-up is sent after a failed first message. This is the conservative
reading of B4's "a duplicate automated message is worse than a missed one."
A `continue` policy is an owner decision (§22) and is not built by default.

---

## 8. SCHEDULER AND QUEUE STRATEGY

### 8.1 Principle

**Delayed queue jobs are never used for waits.** The worker is cron-driven
(§1.1), and a delayed job lives in the `jobs` table, where a flush, a truncate
or a failed deploy removes it silently — the pre-Slice-0 `GET /remove-jobs`
route truncated exactly that table. Durable state lives on the enrollment row.

### 8.2 Jobs and commands

| Unit | Kind | Queue / schedule | Purpose |
|---|---|---|---|
| `App\Jobs\Automation\Workflow\EnrollWorkflowContact` | job | `automation` | Resolves published workflows for a trigger occurrence; inserts enrollments |
| `App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment` | job | `automation` | Runs §7.3 for one enrollment |
| `automation:workflows-resume-due` | command | **every minute** | Wakes `waiting` enrollments with `resume_at ≤ now`; recovers stale `active` ones (§7.4); expires lifetime-exceeded ones |
| `automation:workflows-date-sweep` | command | every five minutes | v2 port of `automation:run` for date triggers |

All jobs: `$tries = 1`, no `backoff()` (Lane F §6.1). Every sweep uses
`chunkById(50)` and a per-run cap (§8.4).

**Wake-up is itself claimed:**
`UPDATE automation_enrollments SET status = 'active', resume_at = NULL WHERE id = :id AND status = 'waiting' AND resume_at <= now()`
— one affected row wins the wake-up; the waiting step run is marked
`succeeded`; the cursor moves to the wait node's successor; an advance job is
dispatched.

### 8.3 Latency

Resolution is one minute. A trigger enrolls within about a minute of commit;
a "wait 5 minutes" resumes between five and six minutes later. This is stated
in the UI and is appropriate for small-business follow-up. Sub-minute
precision is a non-goal.

### 8.4 Structural limits

| Limit | Default | Enforced at | Rationale |
|---|---|---|---|
| Nodes per version | **50** | compile | Keeps publish, the canvas and review tractable |
| If / Else nesting depth | **5** | compile | Beyond this a flow is unreadable on a canvas |
| Conditions per If / Else | **5**, one `all`/`any` level | validate | Nesting is expressed by nesting If / Else, not expressions |
| Minimum wait | 1 minute | validate | Scheduler resolution |
| Maximum single wait | 365 days | validate | Covers yearly journeys |
| Maximum enrollment lifetime | 400 days | recovery sweep → `exited: lifetime_exceeded` | Bounds orphaned journeys |
| Steps per advance job | 10 | advancer | Keeps one job well inside `--timeout=120` |
| Definition document size | 256 KB | save | Storage abuse bound |
| Causation depth | 3 | enrollment | Lane F §6.1 |
| New enrollments per workflow per hour | 1,000 | enrollment → `skipped: rate_limited` | Fan-out fuse; owner decision on the number |
| Manual enrollment per request | 500 contacts, explicit confirmation | request | B4 §6.B's import-safety reasoning |
| Published workflows per Business | 200 | publish | Safety bound; owner decision |
| Sweep enrollments per run | 2,000 | command | Bounds one minute's work |
| Autosave | 30 / minute / user | route throttle | |

Every limit is a named constant in one class,
`App\Library\Automation\Workflow\WorkflowLimits`, so a change is one reviewed
line.

---

## 9. TRIGGER MATRIX

Classifications reuse Lane F's vocabulary and are re-verified on `f6cfd88`.

| Trigger | Current source/event? | Required new event? | Business-scoping seam? | Complexity | v2 launch? |
|---|---|---|---|---|---|
| **Contact created** | **Yes** — `storeContact()` `:263`, `createContactFromRequest()` `:715`, both already `afterCommit()` | No — reuse the two B4 seams | `contacts.business_id`; NULL-business fires nothing | Low | **Yes — V2-C** |
| Contact created, **source: opt-in form** | Partial — the opt-in form is one of four `createContactFromRequest()` callers (§1.4) | No event; an explicit `source` argument passed by each of the four callers | Same | Low | **Yes, as a trigger filter — V2-C** |
| **Date/time reached** | **Yes** — B4 `dueForDateReached()` | No — port the sweep | `business_id` + group + field ownership | Low | **Yes — V2-C** |
| **Manual enrollment** | No seam, trivially buildable | No — an authorized HTTP action | Route resolution order (B4 §2.2) | Low | **Yes — V2-C** |
| **Message received** | **Changed since Lane F:** `chat_boxes.business_id` now exists. Still broadcast-only and emitted from the legacy path alone | **Yes** — new after-commit `App\Events\Conversation\InboundMessageReceived`, emitted from `DLRController` inbound (legacy) and `InboundWebhookAttributionResolver::persistInbound()` (managed), **only on authoritative attribution** | Business from the attributed identity/ChatBox; contact resolved by phone **within the Business** | Medium: phone is unique per group, not per Business | **Yes — its own slice, V2-F** |
| Contact field changed | Data exists, no producer: `updateContact()` `:381` | Yes, after-commit, at every update seam | `contacts.business_id` | Medium-high: must not re-trigger from v2's own `update_contact_field` (loop) | **No** — later slice, with a causation guard |
| Contact added to group | Data exists, no producer: `batchContactCopy()` `:501`, `batchContactMove()` `:528` | Yes, after-commit | Group `business_id` | Medium: batch operations are fan-out | **No** — later slice |
| Contact tag added | **No tag entity** | Needs a Tags domain first | — | High | **No** — CX §28.8 defers tags |
| Form submitted | **No forms domain**, and its absence is test-enforced | Needs a Forms contract first | — | High | **No** — hard-blocked |
| Appointment booked / cancelled | **No calendar domain** | Needs a Calendar contract first | — | High | **No** — hard-blocked |
| Payment received | **No Business→client payments.** Existing payment tables are platform billing and the Business funding its own wallet | Needs a Business invoicing contract first | — | High | **No** — hard-blocked |

**Launch set: four triggers** (contact created, with an opt-in source filter;
date reached; manual enrollment), with message received following in V2-F.
Nothing is claimed that has no source.

**Message-received contact resolution (V2-F).** Because phone uniqueness is per
group, one inbound number can match several Contacts in one Business. The rule
is conservative: **enroll only if exactly one subscribed Contact in the Business
matches; otherwise record `skipped: ambiguous_contact`.** A per-contact,
per-workflow cooldown of 24 hours prevents ping-pong with a contact-side
auto-responder.

---

## 10. ACTION MATRIX

| Action | Current capability? | Seam | v2 launch? |
|---|---|---|---|
| **Send SMS** | **Yes** | B4 `SendMessageAction` → `CampaignRepository::quickSend()`, which now delegates managed sends (Slice 3) | **Yes — V2-B** |
| **Update contact field** | **Yes** | B4 `UpdateContactFieldAction` | **Yes — V2-B** |
| **Internal notification** | Partial: the `notifications` table and Laravel `Notification` exist; no automation notification class | New `WorkflowInternalNotification`, `database` + `mail` channels, recipients = the Business owner and active Workspace members with access to that Business | **Yes — V2-B** |
| **Wait** | Engine primitive | §12 | **Yes — V2-A** |
| **If / Else** | Engine primitive | §11 | **Yes — V2-A** |
| **End** | Engine primitive | | **Yes — V2-A** |
| Send email to a contact | **No** Business→contact email transport | Needs an email-transport decision; must not invent provider plumbing | **No** |
| Add / remove tag | **No** tag entity | Needs a Tags domain | **No** |
| Move to group | Exists (`batchContactMove`) but **unsafe**: fields belong to a group, so moving orphans the contact's custom-field values; phone uniqueness is per group | — | **No** |
| Create / update opportunity | **Not a CRM** — `Opportunity` is the AI-COO engine, explicitly outside this contract | — | **No** |
| Assign contact to user | **No** assignee column | Needs a contact-ownership domain | **No** |
| Webhook | Buildable but an SSRF and exfiltration surface | Signed, allowlisted, no redirects — its own contract | **No** |

### 10.1 Send SMS — sender resolution and the CX Slice 6 dependency

B4 requires a BYO `CustomerBasedSendingServer` before calling `quickSend()`,
which refuses managed Businesses (§2 item 6). v2's executor **does not
pre-require a BYO channel**: it resolves the sending path at execution time —
the Business's managed identity when one is active (which `quickSend()`
already delegates to), otherwise its active assigned BYO channel — and never
stores a channel id that could go stale or cross Businesses.

If CX Slice 6 has merged when V2-B starts, V2-B **reuses** its resolver and adds
nothing parallel. If it has not, V2-B carries only the minimum above and
records the handoff. It must never build a second sender-selection UI.

**Billing is inherited, not built** (B4 §7.A): whatever `quickSend()` does —
managed measurement today, wallet reservation when a retail rate activates —
happens exactly once, in one place. v2 adds no `sms_unit` path and no
reservation of its own.

**Every automation send is tagged** as automation-originated (an
`automation_step_run_id` reference on the resulting `Reports` row, added in
V2-F's migration) so the message-received trigger can apply Lane F's
self-reply rule.

---

## 11. CONDITION MODEL

A condition is `{ subject, operator, operand? }`. **No expressions, no code,
no raw SQL, no customer-authored query.** Every subject is an entry in a
code-backed registry, `App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry`,
which declares its type, its allowed operators and exactly how it is read.

| Subject | Type | Operators | Launch |
|---|---|---|---|
| `contact.first_name`, `last_name`, `email`, `company` | string | `equals`, `not_equals`, `contains`, `not_contains`, `is_empty`, `is_not_empty` | Yes |
| `contact.subscribed` | boolean | `is_true`, `is_false` | Yes — the compliance-relevant condition |
| `contact.in_group` | group reference | `equals`, `not_equals` | Yes |
| `contact.custom_field:{field_id}` | by field type | string ops; date `before`, `after`, `on` | Yes — a contact outside the field's group reads as empty |
| `contact.replied_since_enrollment` | boolean | `is_true`, `is_false` | **V2-F** — needs the inbound producer and automation-send tagging |

* Operands are validated against the subject's type and bounded (strings ≤
  255 characters).
* Every referenced `group_id` and `field_id` must belong to the workflow's
  Business — checked at compile **and** re-checked at evaluation.
* `match` is `all` or `any` over at most five conditions. Deeper logic is
  expressed by nesting If / Else.
* Evaluation is read-only and goes through Eloquent with bound parameters.
* **Opportunity subjects are excluded** (§10). Conversation subjects beyond
  `replied_since_enrollment` are deferred until a first-class Conversation
  model exists (Lane F §3.2).

---

## 12. WAIT AND DELAY MODEL

| Mode | Config | `resume_at` |
|---|---|---|
| `duration` | `amount` 1–N, `unit` `minutes`/`hours`/`days` | arrival + duration, bounded by §8.4 |
| `until_datetime` | ISO-8601 local date-time | that instant in `businesses.timezone`; **if already past on arrival, resume immediately** |
| `until_business_hours` | — | **Non-goal** for initial v2 |

* Timezone: `businesses.timezone` (B4 §6.A precedent), falling back to
  `config('app.timezone')`.
* Computed with Carbon in the Business timezone, so DST is handled; stored in
  UTC.
* On arrival the wait step run becomes `waiting`, the enrollment becomes
  `waiting` with `resume_at` set, in the record-and-advance transaction.
* The sweep wakes it (§8.2). **No browser, session or delayed job is
  involved.**

---

## 13. VISUAL BUILDER — FRONTEND RECOMMENDATION

### 13.1 Recommendation: no graph library

Because the graph is a tree (§5.1), it lays itself out: a vertical list of
steps, with If / Else rendering its two lanes as side-by-side columns. That is
nested DOM — an ordered list of lists — with no layout engine, no edge-routing
maths and no canvas library. It is also **accessible by construction**: a
nested `<ol>` has native reading order and keyboard focus, which a free-form
canvas has to fake.

**Build:** one bundled ES module, `resources/js/automations/workflow-builder/index.js`,
added as an explicit `.js()` entry in `webpack.mix.js` (the existing pattern at
`:70-73`), because `mix.scripts()` concatenates and cannot bundle imports.
Compiled output is committed with its `public/mix-manifest.json` entry, per the
repository's committed-asset discipline.

| Need | How |
|---|---|
| Node rendering | Nested `<ol>`; each step a card from the design system's `card` styles |
| Branches | If / Else renders a two-column flex row labelled **Yes** / **No** |
| Add step | A **+** button between every pair and at the end of each lane, opening a step-type menu (`menu` component) |
| Configure a step | **Bootstrap 5.1 `offcanvas`** drawer, already in the stack; one server-rendered form partial per node type |
| Zoom / pan | CSS `transform: scale()` + `translate()` on the canvas container; wheel/pinch zoom, drag or scrollbar pan; **zoom-to-fit** and **reset** buttons |
| Reorder | **Move up / Move down** in each step's menu — keyboard-accessible and valid by construction. Drag-to-reorder is deferred (§13.3) |
| Autosave | Debounced 1.5 s `PUT` of the whole document with `definition_revision`; 409 on a stale revision (§14.4) |
| Undo / redo | A client-side snapshot stack of the document (bounded to 50), restored then autosaved |
| Validation | The server returns errors keyed by `node_key`; the canvas marks those steps and the drawer shows the message |
| Saved state | "Saving…", "Saved", "Offline — changes kept locally" |
| Test workflow | Opens a contact picker and renders the simulated path (§16, V2-A) — **no side effects** |
| Keyboard | Arrow keys move between steps; Enter opens the drawer; Escape closes it; Delete removes a step after confirmation |
| Viewport | Designed for 1280 px and up; usable at 1024 px; below that a read-only list with a "use a larger screen to edit" notice |

### 13.2 Libraries evaluated and rejected

| Library | License | Why not |
|---|---|---|
| React Flow / xyflow | MIT | Requires React, a second framework in a jQuery/Blade app; built for free-form DAGs |
| Svelte Flow | MIT | Requires Svelte |
| Drawflow | MIT, no deps | Free-form node positioning — the wrong model for an auto-laid-out vertical flow; every position becomes state to persist |
| Rete.js | MIT | Plugin-heavy, framework-coupled renderers, dataflow-oriented |
| JointJS | MPL-2.0 core; commercial JointJS+ | License complexity for the useful features |
| AntV X6 | MIT | Large surface for a problem a tree does not have |
| dagre / elkjs | MIT / EPL-2.0 | Layout engines for general graphs — unnecessary for a tree |

**No dependency is added by this contract.** If drag-to-reorder is later
wanted, SortableJS (MIT, no dependencies) is the candidate, adopted in its own
reviewed change.

### 13.3 Pages

`Automations` list (design-system `table`, `empty-state`, **+ New workflow**) →
**New workflow** opens a chooser: *Start from a recipe* (Lane F §7 recipes
become **workflow templates** — a pre-filled draft document) or *Start from
scratch* — reconciling CX §14.1's "What would you like to automate?" entry
with a canvas → **Builder** with tabs **Builder · Settings · Enrollment
history · Execution logs** (`tabs` component); top bar: back, name, saved
state, undo/redo, Test workflow, Draft/Publish.

---

## 14. TENANCY AND SECURITY

### 14.1 Resolution order — every request

B4 §2.2 verbatim: Workspace by UID → Business by UID inside it →
`WorkspaceManager::userCanAccessBusiness()` → `EntitlementManager::decide(…, Automations, …)`
→ workflow **scoped to that Business**. A foreign identifier fails exactly like
a nonexistent one (404). Routes:
`/workspaces/{workspaceUid}/businesses/{businessUid}/automations/workflows/…`.

### 14.2 Reference integrity

Every reference in a node config — contact group, custom field, sender or
channel, notification recipient, and (in V2-C) a manually enrolled contact —
must satisfy `business_id == workflow.business_id`:

1. at **save**, for immediate feedback;
2. at **compile**, authoritatively; and
3. at **execution**, re-read fresh (B4 §5.5).

A reference that fails at execution is a `skipped` step with a safe reason,
never a cross-Business action.

### 14.3 Background execution

Jobs carry ids only. They never read `Auth::id()`. The entitlement
`$actorUserId` is `$business->customer_id` with B4 §2.5's comment. Every job
re-derives the Business from the enrollment row.

### 14.4 Editing safety

* Autosave sends `definition_revision`; the update is
  `WHERE id = ? AND definition_revision = ?`. Zero rows → **409**, and the
  builder offers "reload the latest draft" rather than overwriting another
  tab's work.
* Autosave writes only the draft row; it can never touch a published version.
* CSRF on every mutation; `throttle` on autosave.
* The document is validated on every save; an invalid document is still saved
  as a draft with its errors, so work is never lost, but **it cannot publish**.
* Message bodies are stored as plain text; merge tags are an allowlist
  (`{first_name}`, `{last_name}`, `{company}`, `{business_name}`) substituted
  server-side, with no template-language evaluation.

### 14.5 Permissions

v2 reuses the existing `automations` permission key. Splitting it into view,
edit and publish is an owner decision (§22) and is not built by default.

---

## 15. MIGRATION STRATEGY FROM B4

### 15.1 Coexistence

B4 keeps running its own rows, unchanged, until they are converted. v2 runs in
parallel on its own tables. **No B4 file is edited before V2-G.**

### 15.2 Conversion — V2-G

`php artisan automation:migrate-b4-workflows {--dry-run} {--business=}`,
idempotent:

1. For each `automations` row with `business_id` NOT NULL and both trigger and
   action set, and no workflow yet carrying its `legacy_automation_id`:
   create a workflow and a **published** version with two nodes — the trigger
   (same type and config) and one action (same type and config). Same name;
   `published` if the B4 row was active, else `paused`.
2. **Carry idempotency across the boundary.** For every existing
   `automation_executions` row of that automation, insert a terminal
   `completed` enrollment (`exit_reason = migrated_from_b4`) whose
   `enrollment_key` matches the key v2 would compute — `contact_created` →
   `once_ever`; `contact_date_reached` → `once_per_occurrence` with the year
   parsed from the B4 key. **No contact the B4 automation already acted on can
   be acted on again by the converted workflow.**
3. Set the B4 row's `status` to `migrated`. B4's sweep selects only `active`,
   so it stops.
4. Log a per-row resolved/skipped summary, following `BusinessDataTenancyBackfillV1`.

NULL-business B4 rows are never converted, never reassigned, and remain inert
(B4 §3.5).

### 15.3 Retirement

Only after every eligible row is converted **and** a parity period passes, a
final V2-G step removes the B4 runtime: `automation:run`'s scheduler line,
`AutomationJob`, `SendAutomationMessage`, the B4 dispatches at
`EloquentContactsRepository.php:263` and `:715`, and `form.blade.php`. The B4
**tables stay** for history.

### 15.4 Analytics continuity — V2-H

B5 analytics and the dashboard read `automation_executions` (§1.3). V2-H extends
those readers to union `automation_step_runs` so KPIs stay continuous across
the migration. Until V2-H merges, converted workflows produce step runs that
analytics does not count — stated in the V2-G report, not hidden.

---

## 16. PHASED IMPLEMENTATION SLICES

Optimized for parallel lanes after approval. **V2-0 is the only serial
prerequisite**; it fixes every interface the others build against, so A, B, C,
D and E run in parallel.

```
                              ┌──► V2-A  runtime engine ──────┐
                              ├──► V2-B  action executors ────┤
   V2-0  foundation ──────────┼──► V2-C  trigger sources ─────┼──► V2-G  B4 migration + retirement
   (schema, models, enums,    ├──► V2-D  builder UI ──────────┤         │
    registry, compiler,       └──► V2-E  HTTP + routes ───────┘         └──► V2-H analytics
    publisher, interfaces)                     │
                                               └──► V2-F  message received (after A, B, C)
```

| Slice | Delivers | Depends on | Parallel with |
|---|---|---|---|
| **V2-0 Foundation** | Six migrations; six models; enums; `NodeTypeRegistry`; `WorkflowDefinitionValidator`; `WorkflowCompiler`; `WorkflowPublisher`; `WorkflowDraftService`; `WorkflowLimits`; and **interfaces**: `NodeExecutor`, `TriggerSource`, `EnrollmentService`, `ConditionSubject`. No runtime, UI or routes | This contract | — |
| **V2-A Runtime engine** | `WorkflowEnrollmentService`; `WorkflowAdvancer`; `WorkflowStepClaimService`; `WorkflowCheckpoint`; `ConditionEvaluator` + subject registry; `WaitScheduler`; executors for `wait`, `if_else`, `end`, `trigger`; `AdvanceWorkflowEnrollment`; `automation:workflows-resume-due` + its scheduler line; `WorkflowSimulator` (Test workflow) | V2-0 | B, C, D, E |
| **V2-B Action executors** | `SendSmsNodeExecutor` (§10.1), `UpdateContactFieldNodeExecutor`, `InternalNotificationNodeExecutor` + `WorkflowInternalNotification` | V2-0 | A, C, D, E |
| **V2-C Trigger sources** | `ContactCreatedTriggerSource` (+ `source` argument at the four callers); `DateReachedTriggerSource` + `automation:workflows-date-sweep`; `ManualEnrollmentTriggerSource`; `EnrollWorkflowContact` job; v2 dispatch added beside B4's at `:263`/`:715` | V2-0 | A, B, D, E |
| **V2-D Builder UI** | List, chooser, builder shell, canvas module, drawer partials per node type, autosave, undo/redo, validation display, zoom/pan, recipe templates | V2-0 document schema; integrates with E | A, B, C, E |
| **V2-E HTTP layer** | `AutomationWorkflowsController` (list/create/show/settings/archive/pause/resume), `AutomationWorkflowDraftController` (show/autosave/publish/discard), `AutomationWorkflowEnrollmentsController` (history/logs/manual enroll/stop-all), requests, routes, policy wiring | V2-0 | A, B, C, D |
| **V2-F Message received** | `InboundMessageReceived` after-commit event at both inbound paths; `MessageReceivedTriggerSource`; ambiguity + cooldown rules; `reports.automation_step_run_id` tag; `contact.replied_since_enrollment` subject | A, B, C | — |
| **V2-G B4 migration + retirement** | Converter command, idempotency carry-over, B4 status `migrated`; later, B4 runtime removal | A, B, C proven | — |
| **V2-H Analytics continuity** | Union of step runs into B5 KPIs and the dashboard status reader | A | G |

**Integration seam between D and E:** the endpoint contract in §20.2 is fixed
by V2-0, so D can build against fixtures while E builds the real controllers.

---

## 17. TESTS

Every slice runs its own files, then `tests/Feature/Automations/**` and
`tests/Feature/Automations/Workflow/**` as regression, against a
`TestDatabaseSafety`-validated sibling distinct from any concurrent lane's,
named in the report. Zero discovered tests is a failure. Concurrency tests use
the repository's deterministic second-session lock pattern
(`AutomationsClaimConcurrencyTest::test_definition_edit_cannot_commit_through_the_claim_lock`),
**never sleeps**.

| # | Invariant | Slice |
|---|---|---|
| T-WF-1 | At most one draft and one published version per workflow — raw inserts violate the generated-column guards | 0 |
| T-WF-2 | `UNIQUE(to_node_id)` rejects a second parent; `UNIQUE(from_node_id, edge_kind)` rejects a second `next` | 0 |
| T-WF-3 | Compiler rejects: two roots, non-trigger root, unregistered type, invalid config, duplicate keys, > 50 nodes, depth > 5, > 5 conditions, oversize document, unreachable node | 0 |
| T-WF-4 | Compiler rejects a group, field, channel or recipient belonging to another Business | 0 |
| T-WF-5 | Publish is atomic: an injected failure leaves the prior version live and no orphan node/edge rows | 0 |
| T-WF-6 | Published and superseded node/edge rows are never updated — asserted over every code path | 0 |
| T-WF-7 | An in-flight enrollment on v1 finishes on v1's nodes after v2 publishes; a new enrollment uses v2 | A |
| T-WF-8 | `UNIQUE(enrollment_id, node_id)`: a duplicated advance job produces one step run and **one** provider call | A, B |
| T-WF-9 | Expected-cursor `UPDATE`: a concurrent stop-all between execute and advance makes the advance a no-op — deterministic via a second session | A |
| T-WF-10 | Pause serialization: a pause committed before a claim prevents it; a claim committed before a pause completes exactly that step — second-session lock test | A |
| T-WF-11 | Wait wake-up is claimed once: two sweeps produce one wake-up | A |
| T-WF-12 | **No delayed queue job is dispatched anywhere by v2** — asserted with `Queue::fake()` over every path | A |
| T-WF-13 | Recovery: an interrupted `send_sms` step is failed, never re-sent; an interrupted `if_else` is re-derived and advances | A |
| T-WF-14 | Lifetime exceeded → `exited`; runaway `step_count` → `exited` | A |
| T-WF-15 | Conditions: every operator per subject; foreign-Business field or group reference evaluates as a skip, never cross-Business | A |
| T-WF-16 | Simulator writes nothing — row counts identical before and after, `Http::assertNothingSent()` | A |
| T-WF-17 | Send SMS goes only through `quickSend()`; a managed Business sends without a BYO channel; an unsubscribed contact is not sent to, **re-checked at the action boundary** | B |
| T-WF-18 | Internal notification reaches only users with access to that Business | B |
| T-WF-19 | Contact created enrolls after commit; a rolled-back create enrolls nothing; **a bulk-imported contact enrolls nothing** | C |
| T-WF-20 | `once_ever` enrolls once; `once_per_occurrence` once per occurrence; the active-contact guard blocks overlap under both | C |
| T-WF-21 | Manual enrollment rejects a foreign-Business contact with 404-equivalent failure; > 500 rejected | C |
| T-WF-22 | Every route: foreign Workspace, foreign Business, foreign workflow → 404; no permission → denied; entitlement denied → denied | E |
| T-WF-23 | Autosave with a stale revision → 409 and the stored draft unchanged | E |
| T-WF-24 | Builder renders a 50-node, depth-5 tree within the query budget (§18) | D, E |
| T-WF-25 | Message received: ambiguous contact → skipped; cooldown enforced; an automation-originated send never triggers its own workflow | F |
| T-WF-26 | Converter: a contact already actioned by B4 is not actioned again; the command is idempotent; NULL-business rows untouched | G |
| T-WF-27 | Analytics totals continuous across conversion | H |

---

## 18. PERFORMANCE AND QUERY BUDGETS

| Operation | Budget | How |
|---|---|---|
| Workflow list | ≤ 8 queries for any page size | One query with `withCount` / latest-run subselect; paginated |
| Builder load | ≤ 10 queries **independent of node count** | The draft document is one row; the published graph is two queries (nodes, edges) |
| Autosave | 2 queries | One conditional `UPDATE` plus the revision read |
| Publish, 50 nodes | ≤ 20 queries | In-memory compile; two bulk inserts; one transaction |
| Trigger ingestion | 1 lookup per event | `(business_id, trigger_type, state)` index |
| One advanced step | ≤ 8 queries plus the action's own | Pre-check, claim transaction, record-and-advance |
| Resume sweep | Index range scan only | `(status, resume_at)`; `chunkById(50)`; never touches terminal rows |
| Recovery sweep | Index range scan only | `(status, last_advanced_at)` |
| Enrollment history / logs | Paginated, indexed | `(workflow_id, created_at)`, `(enrollment_id, created_at)` |

Each budget is asserted with a query-count test in its slice.

---

## 19. EXPLICIT NON-GOALS FOR INITIAL V2

Loops, "go to step", branch merges; N-way split and A/B split; goal/exit
events ("stop when the contact replies" is expressed with a condition instead);
wait until business hours; recurring schedules beyond date-reached;
cross-workflow triggers ("added to another workflow"); bulk-import fan-out;
Workspace- or Agency-level workflows; real-time collaborative editing; a mobile
editor; real sends from **Test workflow** (it is a simulation only);
drag-to-reorder; forms, appointments, customer payments, tags, email, sales
pipelines, contact assignment and webhooks (§9, §10); and **any AI node**.

**The AI seam, recorded and not built.** An AI node — decision, draft or
classification — would be one `WorkflowNodeType` case, one registry entry and
one executor, and would inherit the monthly-limit and kill-switch mechanisms
Lane F §6.1 requires. **AI COO, AI Workforce and the Opportunity engine are not
part of the workflow engine and must not be coupled to it.**

---

## 20. EXACT FILE AND PATH OWNERSHIP PER SLICE

Every slice is limited to its own paths. A slice needing another path stops and
reports. Common to all slices, prohibited: `.env`, `.env.testing`, dependency
manifests (except V2-D's `webpack.mix.js` and compiled output), `AGENTS.md`,
`CLAUDE.md`, `docs/automation/AI-AUTONOMY-STATE.json`, every B4 file (until
V2-G), every other slice's paths.

### 20.1 Allowlists

**V2-0 Foundation**
```
database/migrations/<ts>_create_automation_workflows_table.php
database/migrations/<ts>_create_automation_workflow_versions_table.php
database/migrations/<ts>_create_automation_workflow_nodes_table.php
database/migrations/<ts>_create_automation_workflow_edges_table.php
database/migrations/<ts>_create_automation_enrollments_table.php
database/migrations/<ts>_create_automation_step_runs_table.php
app/Models/AutomationWorkflow.php
app/Models/AutomationWorkflowVersion.php
app/Models/AutomationWorkflowNode.php
app/Models/AutomationWorkflowEdge.php
app/Models/AutomationEnrollment.php
app/Models/AutomationStepRun.php
app/Enums/Automation/Workflow/**
app/Library/Automation/Workflow/NodeTypeRegistry.php
app/Library/Automation/Workflow/WorkflowLimits.php
app/Library/Automation/Workflow/WorkflowDefinitionValidator.php
app/Library/Automation/Workflow/WorkflowCompiler.php
app/Library/Automation/Workflow/WorkflowPublisher.php
app/Library/Automation/Workflow/WorkflowDraftService.php
app/Library/Automation/Workflow/Contracts/**
tests/Feature/Automations/Workflow/Foundation/**
tests/Unit/Automations/Workflow/**
docs/automation/AUTOMATIONS-V2-WORKFLOW-ENGINE-CONTRACT.md   (status update only)
```

**V2-A Runtime engine**
```
app/Library/Automation/Workflow/Runtime/**
app/Library/Automation/Workflow/Conditions/**
app/Library/Automation/Workflow/Executors/{Trigger,Wait,IfElse,End}NodeExecutor.php
app/Library/Automation/Workflow/WorkflowSimulator.php
app/Jobs/Automation/Workflow/AdvanceWorkflowEnrollment.php
app/Console/Commands/Automation/ResumeDueWorkflowEnrollments.php
app/Console/Kernel.php                                   (one schedule line)
tests/Feature/Automations/Workflow/Runtime/**
```

**V2-B Action executors**
```
app/Library/Automation/Workflow/Executors/{SendSms,UpdateContactField,InternalNotification}NodeExecutor.php
app/Notifications/WorkflowInternalNotification.php
tests/Feature/Automations/Workflow/Actions/**
```

**V2-C Trigger sources**
```
app/Library/Automation/Workflow/Triggers/**
app/Jobs/Automation/Workflow/EnrollWorkflowContact.php
app/Console/Commands/Automation/SweepDateReachedWorkflows.php
app/Console/Kernel.php                                   (one schedule line)
app/Repositories/Eloquent/EloquentContactsRepository.php (the v2 dispatch beside :263 and :715 only)
app/Http/Controllers/API/ContactsController.php          (source argument only, :83)
app/Http/Controllers/API/ContactsHTTPController.php      (source argument only, :102)
app/Http/Controllers/Customer/ContactsController.php     (source argument only, :866 and :1542)
tests/Feature/Automations/Workflow/Triggers/**
```

**V2-D Builder UI**
```
resources/views/customer/Automations/Workflows/**
resources/js/automations/workflow-builder/**
resources/scss/base/pages/automations-workflow-builder.scss
webpack.mix.js                                           (one .js() entry)
public/js/automations/workflow-builder.js                (compiled)
public/css/base/pages/automations-workflow-builder.css   (compiled; confirm exact output path)
public/mix-manifest.json
resources/lang/en/automations.php                        (new keys only)
tests/Feature/Automations/Workflow/Builder/**
```

**V2-E HTTP layer**
```
app/Http/Controllers/Customer/Business/AutomationWorkflowsController.php
app/Http/Controllers/Customer/Business/AutomationWorkflowDraftController.php
app/Http/Controllers/Customer/Business/AutomationWorkflowEnrollmentsController.php
app/Http/Requests/Automations/Workflow/**
routes/customer.php                                      (one new route group beside :811)
tests/Feature/Automations/Workflow/Http/**
```

**V2-F Message received**
```
app/Events/Conversation/InboundMessageReceived.php
app/Library/Automation/Workflow/Triggers/MessageReceivedTriggerSource.php
app/Library/Automation/Workflow/Conditions/Subjects/RepliedSinceEnrollment.php
app/Http/Controllers/Customer/DLRController.php          (one dispatch at the authoritative inbound write)
app/Library/Messaging/InboundWebhookAttributionResolver.php (one dispatch in persistInbound)
database/migrations/<ts>_add_automation_step_run_id_to_reports_table.php
tests/Feature/Automations/Workflow/MessageReceived/**
```

**V2-G B4 migration and retirement**
```
app/Console/Commands/Automation/MigrateB4Workflows.php
tests/Feature/Automations/Workflow/Migration/**
— retirement step only, separately authorized: —
app/Console/Kernel.php, app/Console/Commands/RunAutomation.php, app/Jobs/AutomationJob.php,
app/Jobs/SendAutomationMessage.php, app/Repositories/Eloquent/EloquentContactsRepository.php (:263, :715),
resources/views/customer/Automations/form.blade.php, routes/customer.php (:811-820)
```

**V2-H Analytics continuity**
```
app/Library/Analytics/BusinessAnalyticsQueries.php
app/DTO/Analytics/AutomationKpis.php
app/Library/Dashboard/DashboardStatusReader.php
tests/Feature/Analytics/**                               (new files; existing assertions extended, never weakened)
```

### 20.2 Endpoint contract (fixed by V2-0, consumed by D and E)

All under `/workspaces/{workspaceUid}/businesses/{businessUid}/automations/workflows`:

| Verb | Path | Purpose |
|---|---|---|
| GET | `/` | List |
| POST | `/` | Create (from scratch or recipe) |
| GET | `/{workflowUid}` | Builder shell |
| GET | `/{workflowUid}/draft` | Draft document + revision + validation errors (JSON) |
| PUT | `/{workflowUid}/draft` | Autosave `{definition, definition_revision}` → 200 `{revision, errors}` or 409 |
| POST | `/{workflowUid}/publish` | Publish → 200, or 422 with errors keyed by `node_key` |
| POST | `/{workflowUid}/discard-draft` | Discard draft |
| POST | `/{workflowUid}/simulate` | Test workflow `{contact_uid}` → simulated path (JSON), no side effects |
| POST | `/{workflowUid}/pause`, `/resume`, `/archive`, `/stop-all` | State changes |
| GET | `/{workflowUid}/settings`, `/enrollments`, `/enrollments/{enrollmentUid}/logs` | Tabs |
| POST | `/{workflowUid}/enrollments` | Manual enrollment `{contact_uids[]}` (≤ 500, confirmed) |

---

## 21. LIMITS, LOOPS AND RUNAWAY PREVENTION — SUMMARY

* **Loops are structurally impossible**: the document shape cannot express one,
  the compiler proves reachability, and `UNIQUE(to_node_id)` forbids merges.
* **Each node runs at most once per enrollment**: `UNIQUE(enrollment_id, node_id)`.
* **A contact is never in one workflow twice at once**: the active-contact guard.
* **Cross-workflow cascades are capped**: causation depth 3 (Lane F).
* **Fan-out is capped**: hourly enrollment fuse, 500-contact manual cap, bulk
  import excluded, `chunkById(50)` sweeps.
* **Time is bounded**: 365-day maximum wait, 400-day maximum lifetime.
* **Retries are bounded at zero** for external actions: `$tries = 1`, no
  backoff, interrupted sends failed rather than repeated.
* All numbers live in `WorkflowLimits` (§8.4).

---

## 22. OWNER DECISIONS REQUIRED

| # | Decision | Recommended default |
|---|---|---|
| **D1** | Approve the tree rule (no merges, no loops, no "go to") for initial v2 | Approve |
| **D2** | Default failure policy | `halt` |
| **D3** | Default enrollment policy for new workflows | `once_ever` |
| **D4** | Pause semantics | Pause holds in-flight enrollments; resume continues them |
| **D5** | The §8.4 numbers — especially 50 nodes, depth 5, 1,000 enrollments/hour, 200 workflows/Business | Approve as proposed |
| **D6** | Message-received ambiguous contact rule | Enroll only on exactly one match; 24 h cooldown |
| **D7** | Authorize a **Tags** domain as its own parallel contract | Recommended — GoHighLevel-style workflows lean heavily on tags, and it is the largest capability gap |
| **D8** | Split the `automations` permission into view / edit / publish | Keep one key initially |
| **D9** | B4 retirement timing after conversion | After one full month of parity |
| **D10** | Email to contacts: choose a transport so a Send email node can be contracted | Separate contract |

---

## 23. VALIDATION RECORD

* Every file:line citation in §1 was read on `f6cfd8897b45a4be63073561a9050f080fdbee05`.
* The three governance files were confirmed unchanged against their previously
  read revision.
* The trigger and action classifications of
  `AUTOMATION-TRIGGER-EVENT-AND-GUIDED-RECIPE-EXPANSION-CONTRACT.md` §3/§4 were
  re-verified; the changes since it was written are recorded in §9 (Business-scoped
  `chat_boxes`; Slice 3 merged).
* No package was installed, no dependency added, and no product file,
  migration, route, view, asset or configuration changed. The only change on
  this branch is this document.

`AUTOMATIONS V2 WORKFLOW ENGINE — CONTRACT READY FOR REVIEW`
