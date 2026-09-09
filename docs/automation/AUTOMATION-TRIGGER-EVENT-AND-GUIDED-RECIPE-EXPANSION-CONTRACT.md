# AUTOMATION TRIGGER, EVENT-PRODUCER AND GUIDED-RECIPE EXPANSION CONTRACT

## 0. STATUS AND AUTHORITY

**Status:** Audit and roadmap only. No product code, migration, route, configuration
or dependency change is authorized by this document. This is Lane F.

**Revision — Correction Round 1.** Corrects a false semantic conflation of the
Opportunity Engine with a sales CRM pipeline (§3.6, §4, §14); replaces the
binary "zero recipes can ship" conclusion with a three-state classification
(technically-runnable / product-blocked / no-substrate) applied consistently
to all seventeen recipes, and fully specifies every recipe rather than only
Recipe 1 (§7); separates the immutable event envelope from outbox-delivery
state and automation-execution state, which had been incorrectly collapsed
into one `delivery_state` field (§5); corrects the kill-switch scope from an
invented platform-global mandatory prerequisite to the reusable Business/
Workspace-scoped controls another lane's unmerged Slice 5 work is producing,
and removes the recommendation to adapt `GoogleBusinessProfileCallBudget`
into a new spending/usage-control system before existing wallet/cap
mechanisms are shown insufficient (§6, §10, §11); fixes seven test IDs that
were listed against more than one owning slice (§12); and updates every
downstream conclusion, priority ranking and open-decision row this
correction touches (§7, §11, §14). No file other than this one changed to
produce this revision — every correction below is documentation-only,
verified against the same repository state as the original pass (`origin/main`
unchanged since Round 1; no new fetch was needed because no claim in this
correction depends on repository state that could have moved).

**Verified base (unchanged from Round 1):** `origin/main` at `6c820c801da08ecfd6165d1d3a52ae6336606f0c`
(`Merge pull request #219 from os-creator1/agent/telnyx-managed-messaging-architecture-decision`),
confirmed by `git fetch origin main` and `git rev-parse origin/main` from a fresh
worktree. PR #219 is the tip commit itself, so it is trivially present in
`origin/main`'s ancestry. Working tree was clean immediately after worktree
creation (no generated/cache diffs — `bootstrap/cache/*.php` matched exactly what
`origin/main` carries, unlike the pre-existing `preview-main-worktree` and
`mainline-baseline-remediation-worktree`, which is expected: this is a brand-new
`git worktree add`, not a reused one).

**Branch:** `agent/automation-trigger-recipe-expansion-contract`, created from
`origin/main` at the SHA above via `git worktree add ../automation-trigger-recipe-expansion-contract-worktree -b agent/automation-trigger-recipe-expansion-contract origin/main`.

**Every path, symbol and line number cited below was read at this commit**, either
directly or through a read-only research pass whose file reads are reproduced with
citations. No file outside `docs/automation/` was modified to produce this
document.

**Upstream contracts this document builds on, and does not duplicate or
contradict:**

- `docs/automation/B4-BUSINESS-AUTOMATIONS-CONTRACT.md` (base SHA
  `2d94c18f`, merged as PR #207, `ff9c9d5`) — the execution engine this
  document extends. **Fully implemented** at current `main` (verified: the
  claim service, evaluator, dispatcher, enums, `automation_executions` table
  and `tests/Feature/Automations/**` all exist exactly as contracted — §2
  below).
- `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`
  (base SHA `7d235cfb`, PR #214, Correction Round 1 applied) — **not yet
  implemented past Slice 2** at current `main` (its own §21 slice table; see
  §2.4 below for the mechanical confirmation). This document's §14/§15/§16
  already define an initial recipe catalogue, a trigger/action capability
  matrix, and outbox requirements. **This document does not re-litigate
  those — it is the deeper mechanical audit and delivery plan for exactly
  Slices 7, 8 and part of 6 of that contract's own §21 table, expanded with
  the fuller local-business recipe set (photobooths, roofing companies,
  service businesses) the task requires**, and it inventories trigger/action
  families (calendar, payments, website forms, business operations) that the
  CX contract classifies at the "does this exist" level (§15.1/§15.2) but
  does not design producers, envelopes or a delivery plan for.
- `docs/automation/TELNYX-MANAGED-MESSAGING-ARCHITECTURE-DECISION.md`
  (2026-09-08, Correction Round 2, merged as this baseline's own tip PR
  #219) — resolves *how* the Business's default messaging identity will be
  resolved (`BusinessMessagingIdentity`, Candidate B). **Not yet built**
  (confirmed absent in §2.4 below). This document's cost-safety and
  default-sender sections (§8, §10) depend on it landing first and say so
  explicitly rather than assuming it exists.

**Note on the task's own citation of
`docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`:**
this file exists at the exact path named in the task (confirmed by direct
read, 1,909 lines). An earlier `find`/`grep` pass in this session returned a
false negative for it before the worktree environment settled to the correct
working directory; this is recorded so the false negative is not mistaken for
a real gap.

---

## 1. PRODUCT GOAL — RESTATED AGAINST LOCKED TERMINOLOGY

Automations must be usable by an ordinary local-business owner. The default
experience is outcome-based recipes ("Reply to a new lead"), never
trigger/action/provider configuration. This is not a new decision — it is
already locked by the CX contract's §14.1 ("What would you like to
automate?") and §4 (locked terminology). This document adopts that
terminology exactly:

- **"Business phone"**, never "sender ID" / "sending server" / "messaging
  channel" / "Telnyx" / "Twilio", in any customer-facing surface (CX §4,
  forbidden-terms list).
- **"Usage balance"**, never "wallet", "meter", "micro", "reservation".
- The system resolves the Business's **default messaging identity**
  automatically (CX §10.4) — this document's §8 and §10 depend on that
  mechanism (`BusinessMessagingIdentity`, CX Slice 6) and are explicit about
  its current absence rather than assuming it.
- Advanced/custom automation remains available separately: the existing
  When/Then form (`resources/views/customer/Automations/form.blade.php`)
  becomes the **custom** path, reached only from the last catalogue entry
  (CX §14.1, Appendix A row "Automation *When / Then* first screen").

---

## 2. MECHANICAL REPOSITORY AUDIT

### 2.1 Current trigger/action inventory — exactly two of each, both real

Per `app/Enums/Automation/AutomationTriggerType.php` and
`AutomationActionType.php`, there are **exactly two triggers and two
actions**, both mechanically real (not enum-only stubs):

| Trigger | Internal key | Producer | Dispatch site | Payload | Business attribution | Idempotency | Retry | Runnable? | UI-exposed? | Disposition |
|---|---|---|---|---|---|---|---|---|---|---|
| Contact date reached | `contact_date_reached` | `app/Console/Commands/RunAutomation.php` — five-minute sweep, `automation:run` | `AutomationJob::forDateSweep()` → `AutomationTriggerEvaluator::dueForDateReached()` → `AutomationExecutionClaimService::claim()` | `[contact, occurrence_year]` pairs, computed in Business timezone | `automations.business_id`, re-verified at claim and at the post-start-claim checkpoint | `contact_date_reached:{automation}:{contact}:{occurrence_year}`, UNIQUE DB constraint | None — `$tries = 1` on both `AutomationJob` and `SendAutomationMessage` (`app/Jobs/Base.php:21`), no `failed()` override, no `ShouldBeUnique` | Yes, verified by `tests/Feature/Automations/AutomationsRuntimeTest.php` | Yes — create/edit form | **Keep. Reuse as-is.** |
| Contact created | `contact_created` | After-commit hook at the two in-scope `EloquentContactsRepository` creation seams (`storeContact()`, `createContactFromRequest()`) | `AutomationJob::forContactCreated()` → `AutomationTriggerEvaluator::automationsForCreatedContact()` → claim | Contact id only | Same claim service | `contact_created:{automation}:{contact}`, UNIQUE | Same (`$tries=1`, no retry) | Yes | Yes | **Keep. Reuse as-is.** |

| Action | Internal key | Handler | Mechanism | Idempotency | Runnable? | UI-exposed? | Disposition |
|---|---|---|---|---|---|---|---|
| Send message | `send_message` | `app/Library/Automation/Actions/SendMessageAction.php` | Resolves `CustomerBasedSendingServer` + `SendingServer` from **stored `action_config`** (`sender_id`, `sending_server`), fails closed if either is absent (`SendMessageAction.php:49-52`, cited as evidence item E-27 in the CX contract); calls `CampaignRepository::checkQuickSendValidation()` then `quickSend()`, outside any DB transaction | Same `AutomationExecutionClaimService` claim + start-claim | Yes, mechanically | Yes — but the create/edit form still asks the customer to pick **Channel type / Sender / Messaging channel** (`resources/views/customer/Automations/form.blade.php:115-137`), which is exactly the F-3/E-26 defect the CX contract already names | **Keep the mechanism. The provider-selection UI is a locked defect (CX §27 row C-4) already scheduled for correction by CX Slice 6 (default-identity resolution) — this document does not re-authorize that correction, it depends on it (§8, §10).** |
| Update contact field | `update_contact_field` | `app/Library/Automation/Actions/UpdateContactFieldAction.php` | DB-only, `ContactsCustomField::updateOrCreate()`, re-verifies field-to-Business/group binding at execution time | Same claim path | Yes | Yes | **Keep as-is.** |

**No enum case or comment was treated as an implemented producer anywhere in
this audit.** Every row above cites a producer that a research pass traced
to a real dispatch call, a real queue job, and a real database write —
consistent with the CX contract's own §3 evidence-table discipline (classes
W/UX/P/C/M), which this document reuses.

### 2.2 Execution engine internals (new evidence beyond the two upstream contracts)

- **Dispatcher:** `app/Library/Automation/AutomationActionDispatcher.php:31-36`
  — a hardcoded `match` on `action_type`, two cases, `default →
  AutomationActionResult::skipped('unknown_action_type')`. No
  registry/plugin lookup, no stored class name — exactly the "code-backed
  enum, never a callable" discipline the B4 contract locks (§8).
- **Result bounding:** `AutomationActionResult::bound()`
  (`AutomationActionResult.php:35-44`) strips tags, collapses whitespace,
  and hard-truncates every summary to `mb_substr($clean, 0, 255)` before
  persistence — this is the mechanism that keeps `automation_executions`
  free of provider payloads (B4 §4.3, §11).
- **Definition validation:** `AutomationDefinitionValidator.php` re-queries
  every referenced entity scoped to `$business->id` (sender, channel, field,
  group) at *definition* time, and both action handlers re-verify the same
  bindings again at *execution* time (`UpdateContactFieldAction.php:45-51`
  re-checks `field->contact_group_id === $contact->group_id` even though
  the same rule was already enforced at definition time) — defense in
  depth, not a single-point check.
- **Eligibility:** `AutomationEligibility::resolve()`
  (`AutomationEligibility.php:36-82`) and `resolveForExecution()`
  (`:100-138`) are the two re-check points behind the B4 contract's §9.1
  four-point pause/disable re-check discipline; `entitled()`
  (`:169-183`) documents in its own comment that the actor-id parameter it
  passes to `EntitlementManager::decide()` is "structurally-required,
  behaviorally-inert" — matching B4 §2.5's own locked decision verbatim.
  `EntitlementManager::decide(Workspace, Business, string $featureKey, int
  $actorUserId)` (`app/Library/Entitlement/EntitlementManager.php:111-187`)
  runs an 8-step precedence chain (unknown key → unavailable → wrong-scope
  → Business/Workspace consistency, throwing `BusinessWorkspaceMismatchException`
  on divergence → plan assignment exists → override-or-plan-mapping →
  per-Business feature toggle → assignment status → the usage-authorization-
  gateway check at lines 180-183); `PlatformFeature::Automations`
  (`app/Enums/Entitlement/PlatformFeature.php:18`) is marked `Available` in
  `PlatformFeatureRegistry` (`app/Library/Entitlement/PlatformFeatureRegistry.php:38`),
  alongside `Crm`, `Conversations`, `ProspectOutreach`, `WebsiteGeneration`
  and `GoogleBusinessProfileModule` — every other feature relevant to this
  document's proposed trigger families (`Calendar`, `Forms`) is registered
  as `Planned`, not `Available`, independently corroborating §3.3/§3.5's
  "requires-another-feature-first" classification from the entitlement
  layer's own perspective, not only from the absence of a model.
- **Queue/retry:** both `AutomationJob` and `SendAutomationMessage` extend
  `App\Jobs\Base`, which sets `$tries = 1`, `$maxExceptions = 1`,
  `$failOnTimeout = true` (`app/Jobs/Base.php:20-22`) and neither job
  overrides `$tries`, defines `backoff()`, or defines `failed()`. **There is
  no automatic retry anywhere in the B4 engine** — this is the conservative
  at-most-once policy (B4 §5) expressed at the queue layer, not an
  oversight, and it is the correct behavior this document's new producers
  (§5, §11) must preserve.
- **Scheduler:** `automation:run` runs every five minutes with **no
  `withoutOverlapping()` / `onOneServer()`** (`app/Console/Kernel.php:90`);
  this is safe only because every dispatch downstream is idempotency-keyed —
  a fact this document's new time-based triggers (appointment reminders,
  invoice-overdue sweeps) must replicate exactly, not assume. The only
  `queue:work` invocation anywhere in the repository is a single scheduled
  line, `queue:work --queue=automation,default,batch --timeout=120
  --tries=1 --max-time=180 --stop-when-empty`, running every minute
  (`Kernel.php:78`) — there is no supervisor/Procfile-managed persistent
  worker process (confirmed absent by a repo-wide search). Only
  `AutomationJob`/`SendAutomationMessage` explicitly target the `automation`
  queue; every other scheduled job in the Kernel (18 further entries,
  spanning `campaign:*`, `subscription:check`, `opportunity:sweep-expired-
  snoozes`, and ten `Usage/**` reconciliation/expiry jobs) resolves to
  `default` or its own configured queue, and all are drained by this same
  single `queue:work` line. New producers this document authorizes must
  either reuse the `automation` queue or add themselves to this one
  scheduled `queue:work` invocation — there is no second worker process to
  register against.
- **Controllers/IDOR:** the B4 contract's §12 confirmed-defect list
  (unscoped `batchEnable`/`batchDisable`/`batchDelete`, unscoped `delete()`)
  is **closed** in current code: `app/Repositories/Contracts/AutomationsRepository.php`
  and its Eloquent implementation carry **no batch methods at all** (removed,
  not re-secured — B4 §12.2's own decision), and
  `AutomationsController::resolveBusinessAutomation()`
  (`app/Http/Controllers/Customer/Business/AutomationsController.php:273-283`)
  scopes every single-record lookup through `where('business_id',
  $business->id)` before `where('uid', ...)`. `routes/customer.php` carries
  no batch-action route. Tests
  (`tests/Feature/Automations/AutomationsTenancyTest.php`, ~25 methods)
  assert exactly this.
- **Views:** four files, all on M2 components (`x-card`, `x-table`,
  `x-badge`, `x-button`), matching B4 §14's UI scope exactly:
  `entry.blade.php` (chooser), `index.blade.php` (list), `form.blade.php`
  (create/edit — the future "custom" path), `overview.blade.php`
  (detail/history, masks contact phone to last 4 digits).

### 2.3 What B4 does not have that this document must not assume

- **No shared "is this Business/Workspace active" primitive.**
  `WorkspaceManager::userCanAccessBusiness()`
  (`app/Library/Workspace/WorkspaceManager.php:97-138`) re-fetches the
  Business and Workspace fresh and checks `$workspace->is_active` (line
  110) before any ownership check; `AutomationEligibility::resolve()`
  independently re-derives the same two checks
  (`$business->status !== BusinessStatus::Active` at line 61,
  `! $workspace->is_active` at line 69) rather than calling into
  `WorkspaceManager`. No `assertBusinessIsActive()`/`isWorkspaceActive()`
  helper exists anywhere in `app/` — every feature, including B4, duplicates
  this check inline. This document's new producers (§5, §11) must do the
  same (re-derive fresh, never trust a passed-in object), not assume a
  shared helper exists to call.
- **No `app/Events/Automation` namespace.** B4 uses its own claim/execution
  ledger (`AutomationExecution`) rather than the `ShouldDispatchAfterCommit`
  convention 72 of the repository's 74 other event classes use (confirmed:
  every file under `app/Events/Business`, `Entitlement`,
  `GoogleBusinessProfile`, `Opportunity`, `Usage`, `Website`, `Workspace`
  implements it). New producers this document authorizes (§11 Slice 2 and
  beyond) should follow that dominant 97%-of-the-codebase convention, not
  invent a third pattern alongside B4's ledger and the Events convention.
- **No wallet hook on any automation send.** `SendMessageAction::run()`
  never calls `UsageWalletManager::reserve()` — confirmed by the same
  absence the Telnyx decision document itself records ("No usage-wallet
  hook exists on any message send today," repository-impact section) and by
  direct reading of `SendMessageAction.php`, which checks
  `activeSubscription()`/`Plan` coverage but performs no reservation. **Any
  recipe that sends a message today has no wallet-backed cost control** —
  this is the single most important gap for §10 of this document.
- **No quiet-hours symbol.** Confirmed absent (`grep -rn "quiet_hours"
  app/` — zero matches); CX contract E-29 records the same.
- **No causation/correlation tracking of any kind on `automation_executions`**
  — the table (B4 §4.1) has no `causation_id`/`correlation_id` column. This
  document's §6 loop-prevention design is therefore additive schema, not a
  reuse of an existing column.

### 2.4 CX Managed Messaging contract implementation status (mechanical confirmation)

The CX contract's own §21 slice table gates default-identity resolution
(Slice 6) behind Slices 3, 4 and 5. Confirmed at current `main`:

- **No `BusinessMessagingIdentity` model exists** (`find app/Models -iname
  "*MessagingIdentity*"` — no results; also confirmed negatively by the
  Telnyx decision document's own repository-impact section, written against
  this same baseline).
- **No `app/Library/Messaging/**` namespace exists** (`ls app/Library` —
  confirmed absent).
- **No `config/messaging.php` or `app/Enums/Messaging/**` exist.**
- The automation create/edit form still renders `sms_type`/`sender_id`/
  `sending_server` selects (`form.blade.php:115-137`), i.e. **CX Slice 6 has
  not shipped** and the E-26/E-27 defects it is meant to fix are still live
  in the current tree, exactly as the CX contract's own evidence table
  states.

**Conclusion carried into §11 (delivery plan):** this document's own "safe
default-sender action integration" slice is not new work this document
invents — it is CX Slice 6, and it is correctly gated behind Slices 3
(provider foundation, now unblocked per the Telnyx decision) and 5 (wallet/
payer UX). This document does not shorten that dependency chain.

---

## 3. TRIGGER FAMILIES — FEASIBILITY AND CLASSIFICATION

Legend (matching the CX contract's own §15 classification, extended with the
task's required categories): **existing-and-usable** · **existing-but-unsafe/
incomplete** · **data-exists-no-producer** · **requires-another-feature-first**
· **deferred**.

### 3.1 Contacts and leads

| Trigger | Classification | Evidence |
|---|---|---|
| Contact created | existing-and-usable | §2.1 above |
| Lead/form submitted | requires-another-feature-first | No `Form`/`LeadForm`/`WebsiteForm` model, controller or migration exists. `App\Http\Controllers\Public\WebsiteController` exposes only `home()/page()/sitemap()` GET renders (`app/Http/Controllers/Public/WebsiteController.php:25-107`); no POST route exists in `routes/public.php`. **Contractually forbidden today**, not merely unbuilt: `tests/Feature/Website/WebsiteBoundaryTest.php` actively asserts no form-builder routes/tables/section-type exist in Website Slice A (`docs/automation/WEBSITE-GUIDED-GENERATION-CONTRACT.md:73`). |
| Contact added to group | data-exists-no-producer | `EloquentContactsRepository::batchContactCopy()` (`:501-518`) and `batchContactMove()` (`:528-543`) exist and are Business-scoped, but neither fires any event or after-commit hook — they would need the identical after-commit seam pattern B4 §6.B already established for `contact_created`. |
| Tag added | deferred | No real tag entity: `Contacts::getTags()` decodes a JSON string column (`app/Models/Contacts.php:158-239`); no `Tag` model, table or pivot. CX contract §28.8 already locks this as **defer until a real tag entity exists** — this document does not reopen that decision. |
| Contact updated | data-exists-no-producer | Contact field writes happen through multiple repository methods; none fires an event. Same after-commit-hook pattern as "added to group" would apply. Lower priority than the others in this section — no recipe in §7 requires it. |
| Lead source recorded | requires-another-feature-first | No `lead_source` column exists anywhere; a test (`tests/Feature/Analytics/AnalyticsContactKpiTest.php:81`) actively asserts its absence from Analytics rendering. Would need its own schema addition before any producer is possible. |
| No response after a configured period | requires-another-feature-first | This is a *derived* trigger (absence of an event within a window), not a producer at all — it requires a scheduled sweep over `ChatBox`/`ChatBoxMessage` timestamps (themselves user-scoped, not Business-scoped — see §3.2) or, more soundly, over a future Business-scoped Conversation model. Blocked on the same Business-scoping gap as "inbound message received." |

### 3.2 Conversations and messaging

| Trigger | Classification | Evidence |
|---|---|---|
| Inbound message received | existing-but-unsafe/incomplete | `App\Events\MessageReceived` (`app/Events/MessageReceived.php:12-32`) is dispatched from exactly one site, `DLRController.php:629`, is `ShouldBroadcastNow` (a websocket broadcast, not a durable domain event), carries untyped `$user, $message, $data` with **no `business_id` property**, and its `$data` (a `ChatBox` row) has no `business_id` column in any of its migrations. **An adapter over this event is not acceptable** (CX contract §15.1 states this explicitly, and this audit independently confirms it) — a new, transactional, Business-scoped producer is required (§5, §11 Slice 2). |
| Outbound message delivered | data-exists-no-producer | `DLRController::updateDLR()` (`:44-102`) writes `Reports::update(['status' => ...])` and calls `Campaigns::updateCache()` — direct model mutation, zero event dispatch anywhere in the delivery-status callback chain (confirmed: no `event(new` or `::dispatch(` call in that method). |
| Outbound message failed | data-exists-no-producer | Same mechanism as "delivered" — `status` is a string value on the same `Reports` row; no separate failure event. |
| Missed call | requires-another-feature-first | Zero voice-webhook ingestion exists anywhere in the repository (confirmed: no controller, no route, no model). This is a net-new feature, not a wiring gap — and it is explicitly out of scope of every current slice (CX §10.5: "Voice/calling is explicitly out of scope for every slice"). |
| Conversation started | requires-another-feature-first | No `Conversation` model exists; `ChatBox`/`ChatBoxMessage` is the closest analog and is user-scoped, not Business-scoped (no `business_id` column in any ChatBox migration). |
| Conversation inactive | requires-another-feature-first | Same dependency as above — a time-based sweep over a Business-scoped Conversation entity that does not yet exist. |
| STOP/unsubscribe received | existing-and-usable **as enforcement**, data-exists-no-producer **as a trigger** | STOP enforcement itself is real and mechanically confirmed: `EloquentCampaignRepository::quickSend()` (`:254-263`) checks `Blacklists::where('business_id', ...)->where('number', $phone)->first()` before every send, and `SendMessageAction::run()` separately rejects `$contact->status !== Contacts::STATUS_SUBSCRIBE` (`:56-58`). But nothing fires an event *when* a STOP arrives — `DLRController.php:877-887` sets `$contact->status = 'unsubscribe'` and inserts a `Blacklists` row via direct calls with no event dispatch. A future "notify me when someone opts out" recipe would need a new producer at that exact call site. |
| Customer replied after campaign outreach | data-exists-no-producer | `quickSend()`'s `conversationContext` flag (`EloquentCampaignRepository.php:95,117-123`) is an idempotency-token fast path, not a conversation-thread entity; correlating "this inbound message replies to that campaign send" would require new schema, not an adapter. |

### 3.3 Calendar and appointments

**Every trigger in this family is requires-another-feature-first, without
exception.** Zero `Appointment`/`Booking`/`Calendar` model, migration,
controller, event or reminder job exists anywhere in the repository
(confirmed by a repo-wide case-insensitive search returning only incidental
matches like "calendar year" in unrelated date-window comments). The CX
contract's own evidence table (E-31) and explicit exclusions list (§26,
"Building Calendar, Booking... — §15 classifies them as future-only") both
independently confirm this. **This is not a wiring gap this document can
close with an adapter — it is a net-new domain** requiring its own model,
migrations, controller, and its own contract before any producer can exist:

| Trigger | Classification |
|---|---|
| Appointment booked | requires-another-feature-first |
| Appointment rescheduled | requires-another-feature-first |
| Appointment cancelled | requires-another-feature-first |
| Appointment reminder due | requires-another-feature-first |
| Appointment starting soon | requires-another-feature-first |
| Appointment completed | requires-another-feature-first |
| No-show recorded | requires-another-feature-first |

### 3.4 Payments and invoices

| Trigger | Classification | Evidence |
|---|---|---|
| Payment received (Business's client paying the Business) | requires-another-feature-first | Two entirely separate payment subsystems exist. (1) Legacy `Invoices`/`PaymentMethods` (`app/Models/Invoices.php`) is **`user_id`-scoped, not `business_id`-scoped**, and is the platform's own plan-subscription billing to the customer — not a Business-issued client invoice. (2) The modern, Business-scoped, event-emitting `UsageWalletManager` pipeline (`reserve()`/`commit()`/`release()`, `app/Library/Usage/UsageWalletManager.php:285/544/810`, with real events `BusinessUsageReserved`/`Committed`/`ReservationReleased`) is the customer **funding their own usage balance** — not a payment from *their* client. Neither subsystem represents "a Business's client paid the Business," which is the actual product meaning of this trigger (matching CX §15.1's identical finding for the same reason: `BusinessFundingAttemptSucceeded` is the customer topping up their own wallet, E-37). |
| Payment failed | requires-another-feature-first | Same as above — `BusinessFundingAttemptFailed` describes the wrong subject. |
| Invoice created | requires-another-feature-first | `Invoices` model exists (W) but is not Business-scoped and has no dedicated "mark paid" transition — status is written directly per call site (e.g. `RegisterController.php:194`). No Business→client invoicing feature exists at all. |
| Invoice due soon / overdue | requires-another-feature-first | `grep -rn -i "overdue" app/` returns zero matches anywhere in the repository — the concept does not exist. |
| Refund issued | data-exists-no-producer | The Stripe webhook pipeline (`app/Jobs/Usage/ProcessPaymentProviderEvent.php:38-49,428-435`) already routes ten distinct refund/dispute event types (`charge.refunded`, `charge.dispute.*`, `refund.*`) with real signature verification and DB-unique-constraint idempotency (`routes/public.php:152`, `StripeWebhookController.php:43-62`) — but this is entirely inside the **wallet-funding** domain (a customer's own refund on their own top-up), not a refund the Business issues to *its* client. A genuine "Business refunds a client" producer does not exist and depends on the same missing Business→client invoicing feature above. |
| Deposit received | requires-another-feature-first | No `deposit` concept exists anywhere (`grep -rn -i "deposit"` — zero matches in `app/Models`/`app/Library`). |
| Remaining balance due | requires-another-feature-first | No analog exists; the only "balance" concepts in the repository are the legacy `$user->sms_unit` counter and the modern wallet's `available_balance_micro` — both are the Business's *own* usage balance, not a balance a client owes the Business. |

**This entire family is blocked on one missing upstream feature: Business→
client invoicing/payments (distinct from the existing Business-funds-its-own-
wallet system).** No amount of adapter work over `UsageWalletManager` or the
legacy `Invoices` model produces this family; it needs its own product
surface and its own contract, exactly as the CX contract's §26 exclusions
list already states for "customer-issued Invoicing."

### 3.5 Website and forms

| Trigger | Classification | Evidence |
|---|---|---|
| Contact form submitted | requires-another-feature-first | See §3.1 "Lead/form submitted" — no Form model exists, and its absence in Website Slice A is test-enforced (`tests/Feature/Website/WebsiteBoundaryTest.php`), not merely unbuilt. |
| Quote request submitted | requires-another-feature-first | No Quote model exists (CX E-32). |
| Booking request submitted | requires-another-feature-first | Depends on the same missing Calendar/Booking domain as §3.3. |
| Website lead created | requires-another-feature-first | Same as "Contact form submitted" — this would in practice be the *effect* of a form submission creating a Contact, which cannot happen until the form-capture feature exists. |
| Specific form completed | requires-another-feature-first | Same dependency. |

### 3.6 Business operations

| Trigger | Classification | Evidence |
|---|---|---|
| Service completed | requires-another-feature-first | No "service" or job-completion entity exists distinct from the missing Appointment/Booking domain (§3.3). If a future Booking feature adds a completion status, this trigger becomes trivially derivable from it — it is not independently blocked beyond §3.3's dependency. |
| Customer marked won/lost | requires-another-feature-first | **Correction Round 1 — the original classification of this row conflated two unrelated systems and is withdrawn.** §3.6a below mechanically establishes what `Opportunity`/`OpportunityManager` actually represent (a platform-generated, business-profile-completeness recommendation engine, not a sales pipeline), and confirms no `won`/`lost` concept, no `Deal`/`Pipeline`/`Stage` model, and no CRM sales-pipeline entity of any kind exists anywhere in the repository. A genuine "customer marked won/lost" trigger requires a real CRM deal/pipeline entity to be built first — it is not a near-ready adapter over an existing domain. |
| Review request due | data-exists-no-producer | Would be a derived, time-based trigger off Appointment/Booking completion (§3.3) — not independently blocked once that domain exists, but has no substrate today. |
| Review received | requires-another-feature-first | No review ingestion exists at all; Google Business Profile Slice A is explicitly read-only and excludes reviews (CX §15.1, "Review received" row). |
| Usage limit approached | data-exists-no-producer | `UsageWalletManager::setSpendCap()`/`setFeatureLimit()`/`setSafetyLimit()` (`:1184/1225/1293`) are real, Business-scoped limit mechanisms with real enforcement, but no domain event fires when a limit is *approached* (as opposed to hit) — a genuine, buildable near-term producer, **subject to §10.2's reuse rule once CX Slice 5 merges**. |
| Wallet balance low | data-exists-no-producer | Same wallet subsystem; `BusinessUsageWallet.available_balance_micro` is a real, queryable column, but no "balance crossed a low-water-mark" event exists. Straightforward to add as a scheduled sweep, following the exact `automation:run` five-minute-sweep pattern already proven safe by B4, **and reusing the authoritative low-balance/alert mechanism from CX Slice 5 once merged, never a duplicate one (§10.2)**. |
| Phone/compliance setup requires action | requires-another-feature-first | Depends entirely on `BusinessMessagingIdentity` and the onboarding/compliance state machine from the Telnyx decision document (§8's `status` column) — none of which exists yet (§2.4). Trivial to add once that foundation lands; blocked until then. |

### 3.6a Correction — what the Opportunity Engine actually is (not a CRM pipeline)

**Mechanically inspected:** `app/Models/Opportunity.php`,
`app/Enums/Opportunity/OpportunityStatus.php`,
`app/Enums/Opportunity/OpportunityActionExecutionStatus.php`,
`app/Library/Opportunity/OpportunityTypeRegistry.php`, and the RFC-002
contract this subsystem implements.

- **`OpportunityStatus` has exactly six cases** (`Opportunity.php` cast,
  `app/Enums/Opportunity/OpportunityStatus.php`): `Open`,
  `AwaitingApproval`, `InProgress`, `Snoozed`, `Completed`, `Dismissed`.
  **There is no `Won` or `Lost` case, and no sales-pipeline-shaped case of
  any kind.**
- **`Opportunity`'s own fillable fields** (`app/Models/Opportunity.php`)
  are `worker_key`, `type`, `fingerprint`/`fingerprint_version`,
  `context_key`, `title`, `summary`, `status`, `freshness`, `impact`,
  `urgency`, `effort`, `confidence`, `goal_relevance_rank`,
  `evidence_freshness_rank`, `priority_score`, `evidence`,
  `recommended_action` — the shape of a scored, evidence-backed
  **recommendation**, not a deal record (no `value`, no `stage`, no
  `close_date`, no `contact_id` as the deal's counterparty).
- **`OpportunityTypeRegistry::DEFINITIONS`**
  (`app/Library/Opportunity/OpportunityTypeRegistry.php:26+`) is a closed,
  source-controlled list of exactly the 11 RFC-002-derived types the engine
  may ever produce, and every one of them is a **business-profile-
  completeness nudge**: `missing_phone` ("Add your business phone
  number"), `missing_email`, `missing_website`, `missing_description`,
  `missing_location`/`incomplete_location`, `missing_service`/
  `confirm_primary_service`, `missing_gbp_url`, `missing_facebook_url`,
  `missing_instagram_url`, and their siblings — every `title_template` in
  the registry is a "you're missing X, add it" prompt. **None of the 11
  types represents a sales lead, a deal, a quote, a job, or any
  customer-facing pipeline stage.**
- **`opportunity_action_executions`** (`database/migrations/2026_07_19_120004_create_opportunity_action_executions_table.php`)
  is the execution ledger for the platform *acting on its own
  recommendation* (e.g. actually writing the missing phone number once the
  Business owner approves the suggested action) — `action_key`,
  `recommended_action_hash`, `idempotency_key` (unique),
  `completion_policy`. It is a **claim/execution ledger for a self-service
  onboarding nudge**, not a sales-pipeline-transition record. B4 §4 cites
  it only as a structural precedent for its own `automation_executions`
  shape (unique idempotency key, status lifecycle) — that citation was
  never a claim about what an Opportunity *means*, and this document's
  original §3.6/§4 rows incorrectly extended it into one.
- **No CRM sales-pipeline entity exists anywhere in the repository.** A
  targeted search for `Deal`, `Pipeline`, `Stage` models under `app/Models`
  returns nothing, and a case-insensitive search for `won`/`lost` across
  `app/Models` returns no relevant match (one incidental substring hit in
  an unrelated file, not a sales concept).

**Conclusion:** the Opportunity Engine remains exactly what RFC-002 built it
to be — the platform's own recommendation/action-execution system for
nudging a Business toward a more complete profile. **This document does not
repurpose it into a sales CRM brain**, and "Customer marked won/lost"
correctly reclassifies as `requires-another-feature-first`, on the same
footing as the rest of the missing CRM/Pipeline/Quote domain in §3.5.

### 3.7 Revised trigger prioritization (Correction Round 1)

With the false Opportunity shortcut removed (§3.6a), priorities are
recalculated against customer value, actual substrate, dependencies, safety,
implementation cost, and reuse across local-business verticals — never
because a similarly-named model happens to exist:

| Rank | Trigger | Why this rank |
|---|---|---|
| 1 | **New Lead Instant Reply, after managed identity/wallet integration** | Highest customer value (every local-business vertical in §7 wants this), and the *only* recipe with real trigger **and** action substrate today (§7.0 state 1/2) — the entire remaining cost is product-readiness work (CX Slices 3, 5, 6, §10.3), not new engine work. |
| 2 | **Inbound-message owner notification, after a Business-scoped message producer exists** | Second-highest value (every vertical wants to know when a customer texts in) and the cheapest state-3 item to close: it needs one new producer (§11 Slice 2) plus one new, narrowly-scoped action (notify-staff) — no new product domain, unlike appointments/payments/reviews. |
| 3 | **Wallet/usage threshold events (low balance, limit approached), only by reusing authoritative Slice 5 behavior once merged** | Real substrate exists today (`UsageWalletManager`), but this document explicitly forbids building a parallel low-balance/alert mechanism (§10.2) — so this item's priority is conditional on Slice 5 merging first, not on this document's own schedule. Ranked above appointment/payment work because, once unblocked, it requires no new domain at all. |
| 4 | **Website lead/form events, after a real capture path exists** | High customer value for every vertical, but strictly blocked on the Website Guided Generation product actively and deliberately excluding a form-builder today (`tests/Feature/Website/WebsiteBoundaryTest.php`, §3.5) — this is a product decision this document does not have standing to reverse, so it ranks behind items whose blocking dependency is merely "not yet built" rather than "actively excluded." |
| — | **Appointment and client-payment triggers** | Remain blocked until those product domains exist (§3.3, §3.4) — not ranked among the near-term candidates above; each needs its own separately-authorized contract before any priority ordering among their recipes is meaningful (§11 Slices 3/4). |

**What this ranking deliberately does not do:** it does not promote
"Customer marked won/lost" on the strength of a superficially mature-looking
model name (§3.6a's correction), and it does not treat "requires another
product domain" items (appointments, payments, reviews, CRM pipeline) as
comparable in cost to items that only need a new producer or action wired
into an existing domain.

---

## 4. ACTION FAMILIES — FEASIBILITY AND CLASSIFICATION

| Action | Classification | Evidence |
|---|---|---|
| Send SMS/MMS through the default Business identity | existing-but-unsafe/incomplete | The `send_message` action is real (§2.1) but currently requires a stored `sender_id`/`sending_server` in `action_config` rather than resolving a default identity — this is CX §27 row C-4's already-locked correction, gated on CX Slices 3–6, which do not exist yet (§2.4). This document does not re-derive that correction; it inherits it. |
| Send email | requires-another-feature-first | No Business-scoped transactional email send path exists in the automation engine (confirmed: `AutomationActionType` has no `send_email` case, and no `App\Library\Mail\**` Business-scoped send seam was found matching the messaging engine's shape). |
| Wait/delay | data-exists-no-producer | No primitive exists today. The closest precedent is `CONTACT_DATE_REACHED`'s offset-adjusted occurrence computation (`AutomationTriggerEvaluator::dueForDateReached()`), which proves the pattern (a durable, re-evaluatable delay keyed by a deterministic idempotency key) but is trigger-side, not a general action-chain primitive. A real wait/delay action requires a new execution-state shape (§11 Slice 7) — B4's model is strictly single-step (B4 §1: "single-step automations," explicitly "not... multi-step branching"). |
| Notify owner/staff | requires-another-feature-first | No internal-notification delivery mechanism exists in the automation engine (no in-app notification model wired to automations; email/SMS-to-staff would reuse the same send action once it can target a staff member rather than a Contact — not currently representable, since `UpdateContactFieldAction`/`SendMessageAction` both hard-target "the trigger Contact," per B4 §7.B's own locked "never a configurable contact id" rule). |
| Create task | requires-another-feature-first | No Task model exists (CX E-32). |
| Update contact field | existing-and-usable | §2.1 above. |
| Add/remove group | data-exists-no-producer | `batchContactMove()`/`batchContactCopy()` exist and are Business-scoped (§3.1) but are batch operations, not a single-contact automation action; wiring one as an `AutomationActionType` case is a bounded, buildable addition once prioritized. |
| Add/remove tag | deferred | No real tag entity — same CX §28.8 deferral as the trigger. |
| Create/update opportunity | requires-another-feature-first | **Correction Round 1.** §3.6a mechanically establishes that `Opportunity`/`OpportunityManager` is the platform's own closed-catalogue, source-controlled recommendation engine (11 fixed profile-completeness types, e.g. "Add your business phone number") — it is not an arbitrary record an automation could create or update on the customer's behalf, and no automation should be authorized to write into it. The original row's framing ("Create/update opportunity" as a generic CRM-style action) does not correspond to anything this subsystem actually does; this action family requires a genuine CRM deal/pipeline entity, exactly as its trigger counterpart now does. |
| Create appointment | requires-another-feature-first | Depends on the missing Calendar/Booking domain (§3.3). |
| Send payment link | requires-another-feature-first | Depends on the missing Business→client payments domain (§3.4). |
| Request review | requires-another-feature-first | Depends on the missing review-ingestion domain (§3.6). |
| Call webhook under strict security controls | requires-another-feature-first | No outbound-webhook action exists anywhere in the automation engine; B4 §12.4 explicitly confirms the engine performs no outbound HTTP today and states no webhook action is in v1. The CX contract's own §15.2 already scopes this precisely (Settings → Advanced only, SSRF protection, allowlist, signed payloads, timeouts, no secrets in the URL, S-9) — this document adopts that scope unchanged rather than relaxing it. |
| Stop automation | data-exists-no-producer | Not a distinct action today — enable/disable (`AutomationsRepository::enable()`/`disable()`) is a whole-automation state change, not a mid-chain "stop" primitive. Only becomes meaningful once a multi-step primitive (wait/delay, conditional branch) exists, since B4 v1 automations are single-step by design and therefore have nothing to "stop" mid-execution. |
| Conditional branch | requires-another-feature-first | B4 §1 explicitly locks "no conditions tree" as out of scope for the current engine; a conditional-branch action would require the same multi-step execution-state redesign as wait/delay. |
| AI-drafted response with explicit budget and safety limits | requires-another-feature-first | CX §15.2 already classifies this **D** (future-only, "requires an explicit AI budget, a separate meter, and content safety constraints. Not authorized here.") — this document does not reopen that. |

**Recipes whose required action is not runnable today are never exposed** —
this governs every entry in §7's catalogue (CX §14.2's own discipline,
adopted unchanged: "A recipe whose trigger or action is not yet backed is
not shown... never shown disabled, never shown as 'coming soon'").

---

## 5. EVENT AND TRIGGER ARCHITECTURE

### 5.1 Event envelope, outbox delivery, and execution — five separated stages
(Correction Round 1)

**The original event-envelope table incorrectly collapsed delivery state
into the immutable event record.** A single `delivery_state` field
(`pending`/`delivered`/`dead_letter`) sitting on the same row as the event's
own identity and business facts meant the "fact that occurred" and "whether
it has been delivered yet" were the same mutable row — which makes the event
record not actually immutable, and conflates two things every mature
event-sourcing/outbox design keeps apart. This is corrected by naming five
distinct stages explicitly, each owning only the state proper to it, none of
them writing back into an earlier stage's record:

| Stage | Owns | Never owns |
|---|---|---|
| **1. Domain event** (immutable) | Event identity and business facts only: `id`, `event_type`, `business_id`, `subject_type`/`subject_id`, `occurred_at`, `producer`, `correlation_id`, `causation_id`, `idempotency_key`, bounded `payload`, `sensitivity`, `retention_days`. Written once, at the moment the underlying state change commits, and never updated again. | Delivery status, attempt counts, lease/next-attempt time, execution outcome. |
| **2. Outbox delivery** (mutable, references stage 1 by `event_id`) | `delivery_state` (`pending`/`delivered`/`dead_letter`), `attempts`, `leased_at`/`leased_by` (which dispatcher worker currently holds it, preventing double-dispatch), `next_attempt_at`, `delivered_at`. | The event's own facts (never edits stage 1's payload); automation execution outcome (stage 3+). |
| **3. Automation execution** (`AutomationExecution`, B4's existing table, unchanged) | `status` (`pending`/`succeeded`/`failed`/`skipped`), `action_claimed_at`, `started_at`, `completed_at`, `safe_result_summary`/`safe_error_summary` — exactly as B4 §4.1 already locks. A new producer's outbox delivery (stage 2) feeds a claim into this existing table; it does not gain new columns for delivery bookkeeping. | Outbox delivery state; provider-call detail. |
| **4. Action execution** (inside the bounded action handler, e.g. `SendMessageAction`) | The handler's own internal result (`AutomationActionResult::succeeded()`/`failed()`/`skipped()`, already real, §2.2) — reduces to stage 3's `safe_result_summary`/`safe_error_summary`, never a separate persisted row. | Provider-side confirmation (stage 5). |
| **5. Provider operation** (external, observed via response/webhook) | The provider's own confirmation of what actually happened (e.g. a Telnyx `message.finalized` webhook, once §11 Slice 5 exists) — read by stage 4 to decide `commit()` vs. `release()` (§10.1), never assumed from local persistence alone (the Telnyx decision document's own rule, already cited in §10.1). | Nothing upstream — it is authoritative only about the external send itself. |

**No single status field represents more than one stage.** A dead-lettered
*outbox delivery* (stage 2) does not imply a failed *automation execution*
(stage 3) — an event can fail to deliver to its consumer for purely
infrastructural reasons (a worker crash mid-lease) while the eventual,
successfully-redelivered copy still produces exactly one `succeeded`
execution, and the reverse also holds (a delivered event can still produce a
`skipped` execution because §5.2's eligibility re-check failed). Keeping
these separate is what makes §5.2 rule 3 ("automation execution remains
idempotent... a redelivered outbox event must produce, at most, one
successful claim") provable rather than assumed.

**Stage 1 schema (the domain event, immutable):**

No generic outbox/domain-event table exists anywhere in the repository
today — confirmed twice independently: a repo-wide case-insensitive search
for `outbox|domain_event|event_log` matches only two documentation files
(this one's sibling contracts), no migration or model. The closest existing
conventions are (a) the after-commit-dispatch, `ShouldDispatchAfterCommit`
pattern, used by 72 of the repository's 74 event classes (every file under
`app/Events/Business`, `Entitlement`, `GoogleBusinessProfile`,
`Opportunity` — 16 files, `Usage` — 17 files, `Website`, `Workspace`), and
(b) `opportunity_action_executions`
(`database/migrations/2026_07_19_120004_create_opportunity_action_executions_table.php:10-33`)
— genuinely execution-ledger-shaped (unique `idempotency_key`, an
`attempt_number` counter, `status` lifecycle, `started_at`/`completed_at`),
already cited by the B4 contract itself as its own adapted, not copied,
precedent for its own execution ledger (B4 §4) — a citation about schema
shape, not about what an Opportunity *means* (§3.6a). Neither is an outbox
in the transactional-envelope sense this section requires — the table below
is genuinely new schema, not a reuse of either. The CX contract's own §16
requirements are adopted as binding and now expressed as this concrete,
delivery-state-free column list:

| Field | Type | Notes |
|---|---|---|
| `id` | `uuid`, unique | Public event identifier. |
| `event_type` | `string`, versioned (`domain.subject.verb.v1`) | Never a raw class name in the payload — a stable string contract, matching the enum-backed-identity discipline B4 already locks for trigger/action types. |
| `business_id` | `foreignId` → `businesses`, `restrictOnDelete()` | Every event carries exactly one; a consumer must never infer tenancy any other way (CX §16 rule 5, restated here as schema). |
| `workspace_id` | **not stored** | Following B4 §3.3's own locked precedent exactly: Workspace is always reached as `$business->workspace_id`, never duplicated as an independently-driftable column. |
| `subject_type` / `subject_id` | `string` / `unsignedBigInteger` | The domain entity the event is about (e.g. `Contacts`/`{id}`, future `Appointment`/`{id}`). |
| `occurred_at` | `timestamp` | The moment the underlying state change committed — never the moment a delivery attempt is made. |
| `producer` | `string` | The exact class/method that wrote the row, for operator diagnostics — never used for authorization. |
| `correlation_id` | `uuid`, nullable | Groups events from one originating action (e.g. one inbound webhook delivery). |
| `causation_id` | `uuid`, nullable, FK to another event's `id` | The event that directly caused this one — the primitive §6's causation-chain depth limit is built on. |
| `idempotency_key` | `string(191)`, UNIQUE | Deterministic, reproducible from `(event_type, subject_id, occurred_at-bucket)` or an equivalent stable formula per producer — following B4 §5's exact pattern, never a random UUID reused as the dedup key. |
| `payload` | `json`, bounded | No provider payloads, no credentials, no message bodies beyond what a trigger needs, no personal data beyond the contact reference — CX §16 rule 4, unchanged. |
| `sensitivity` | `string`, closed enum (`internal`, `pii-minimal`) | Governs retention and who may read raw payloads via an operator diagnostics view (§6). |
| `retention_days` | `unsignedSmallInteger` | Time-bounded; this document does not set the number (owner decision, §14) but locks that one must exist rather than retaining forever. |

**Stage 2 schema (outbox delivery, mutable, a separate table referencing
stage 1 by `event_id`):**

| Field | Type | Notes |
|---|---|---|
| `event_id` | `foreignId` → the stage-1 table, `cascadeOnDelete()` | One delivery row per event; never merged into the event row itself. |
| `delivery_state` | `string`, closed enum (`pending`, `delivered`, `dead_letter`) | Drives the dispatcher sweep and the dead-letter view (§6). |
| `attempts` | `unsignedSmallInteger` | Bounded, following the `AutomationExecution` precedent of never encoding an automatic-retry semantic beyond what is explicitly authorized. |
| `leased_at` / `leased_by` | `timestamp` / `string`, nullable | Prevents two dispatcher workers from processing the same delivery concurrently — mirrors the row-lock discipline `AutomationExecutionClaimService::claimStart()` already proves out (B4 §5.4), applied at the delivery layer instead of the execution layer. |
| `next_attempt_at` | `timestamp`, nullable | Set when a delivery attempt fails transiently. |
| `delivered_at` | `timestamp`, nullable | Set once the consumer (the automation claim service) has successfully processed the event — not merely once it was read. |

### 5.2 Transactional-outbox requirements for new producers

Locked, adopting CX §16 in full and restating it as implementation-ready
rules for every class-C producer this document's §11 delivery plan
authorizes:

1. **Producer state change and outbox write are atomic.** The outbox row is
   written in the *same* database transaction as the state change it
   reports (e.g. the `ChatBoxMessage` insert and its new outbox row commit
   together, or neither commits). This mirrors the exact discipline B4 §6.B
   already proved out for `contact_created` (`DB::afterCommit(...)` /
   `ShouldDispatchAfterCommit`, matching `App\Events\Opportunity\*`).
2. **Event delivery is at-least-once. No exactly-once claim is made
   anywhere in this document or any future implementation of it.**
3. **Automation execution remains idempotent** via the existing
   `AutomationExecutionClaimService` — a redelivered outbox event must
   produce, at most, one successful claim, exactly as B4 §5 already
   guarantees for the two existing triggers. New producers do not get a new
   idempotency mechanism; they feed the same one.
4. **Duplicate provider webhooks cannot duplicate actions.** The Stripe
   webhook pipeline is the proven in-repo precedent:
   `StripeWebhookController.php:47-62` inserts a `payment_provider_events`
   row guarded by a UNIQUE `(provider, provider_event_id)` index
   (`database/migrations/2026_08_16_140004_create_payment_provider_events_table.php:39`),
   catches the constraint violation, and answers `200` with zero dispatch on
   a duplicate — this is the exact shape every new webhook-sourced producer
   in §11 must copy.
5. **Cross-Business payloads fail closed.** No consumer infers tenancy from
   anything but the event's own `business_id` — matching the Telnyx decision
   document's webhook-routing chain (§9 of that document), which this
   document treats as the house pattern for any future provider-webhook
   producer, explicitly *not* `DLRController`'s number-to-user-1 fallback,
   which both documents independently name as the defect to avoid.
6. **Deleted/archived/inactive Businesses do not execute.** Reused verbatim
   from B4 §9.1/§9.2 — the same four-point re-check discipline (at trigger
   evaluation, immediately before claim, after the start claim, inside the
   bounded action handler) applies to every new trigger family this
   document adds, not only the original two.
7. **Authorization is evaluated from authoritative current state**, never a
   stale snapshot — B4 §9.2, unchanged, extended to cover any new producer's
   own eligibility re-check.
8. **Replay cannot bypass current STOP/blacklist rules.** A redelivered or
   replayed outbox event must re-check `Blacklists`/contact status at
   execution time, not trust a state captured when the event was first
   produced — this is a new explicit lock this document adds, since none of
   the two existing triggers currently need to reason about replay (their
   idempotency key already forecloses a second execution outright; a
   redelivered *event* that reaches a *fresh* claim attempt, e.g. because
   the first claim's execution row was `skipped`, must not be treated as
   grounds to bypass consent).

---

## 6. LOOP AND ABUSE PREVENTION

### 6.1 Threats and mitigations

| Threat | Mitigation |
|---|---|
| Inbound-message automation replying to its own outbound message | The new inbound-message producer (§11 Slice 2) must tag every outbound automation send with a machine marker (e.g. a reserved prefix in `Reports`/`ChatBoxMessage` or a new boolean column) that the inbound producer checks and refuses to treat as a fresh "customer replied" trigger source when the immediately-preceding outbound message in the same thread was automation-generated within a short window. |
| Two automations triggering one another forever | **Maximum execution depth**, tracked via the event envelope's `causation_id` chain (§5.1): an execution whose causation chain exceeds a fixed depth (recommended default: 3) is refused at claim time with a distinct `skipped` reason, never silently executed. Depth is computed by walking `causation_id` back through the outbox table, bounded by the same fixed limit so the check itself cannot become the runaway cost. |
| Repeated payment/webhook events | Solved structurally by §5.2 rule 4 — the UNIQUE `(provider, provider_event_id)` pattern already proven by the Stripe pipeline. |
| Rapid booking/reschedule loops | Once a Booking domain exists (§3.3), a per-contact-per-automation **cooldown** (a minimum interval between two executions of the same automation against the same contact, distinct from the yearly `contact_date_reached` key) prevents a rapid reschedule storm from producing a storm of reminder sends. |
| STOP followed by delayed queued messages | Enforced today by `SendMessageAction`'s `$contact->status !== STATUS_SUBSCRIBE` check, which runs at execution time (after the final checkpoint, before the provider call — B4 §5.5), not at claim time — so a STOP that arrives after a claim but before the action still blocks the send. This document locks that this remains true for every new trigger family: consent is always re-checked at the action boundary, never cached from claim time (§5.2 rule 8). |
| Duplicate review requests | Solved structurally once §3.6's review-request producer exists, by giving it its own deterministic idempotency key (e.g. `review_request:{automation}:{contact}:{appointment_occurrence}`), following the exact `contact_date_reached` key-construction pattern. |
| Excessive AI calls | Not applicable today — no AI-drafted action exists (§4, "requires-another-feature-first"); when one is authorized, it inherits the monthly-limit and kill-switch mechanisms below rather than inventing its own. |
| Runaway wait/retry cycles | The queue-layer `$tries = 1` / no-`backoff()` discipline (§2.2) is preserved for every new job this document's delivery plan introduces — no new automation-adjacent job may set `$tries > 1` without an explicit, separately-contracted justification, matching B4 §5.1 rule 4's accepted at-most-once tradeoff. |
| High-cost fan-out | A single trigger occurrence (e.g. one bulk contact-group tag change) must never fan out to an unbounded number of executions in one pass — the existing `chunkById(50, ...)` pattern in `RunAutomation.php:28-42` is the proven house convention for bounding batch size, and this document requires every new sweep-based producer to use it identically. |
| Business A causing actions in Business B | Structurally foreclosed by the same mechanism as every existing B4 check: every claim, every eligibility re-check, and every event envelope carries exactly one `business_id`, verified against the resolved Business at every one of B4's four re-check points (§9.1) — extended to every new trigger/action this document's §11 authorizes. |

### 6.2 Bounded limits — reusing existing/authoritative mechanisms first
(Correction Round 1 — see also §10.2)

**Every limit below must first be checked against a mechanism this document
did not have standing to duplicate.** Two corrections apply throughout this
subsection:

1. **No new spending/usage-control system may be authorized here.** Before
   any rolling-hour execution budget, spending cap, or kill switch is
   designed as new schema, this document must show why the following,
   already-real mechanisms cannot enforce the limit: the existing
   `AutomationExecutionClaimService` claim (B4 §5, prevents duplicate
   execution outright); RFC-005 `UsageWalletManager` reservations and
   limits (`reserve()`/`setFeatureLimit()`/`setSpendCap()`, §10.1); Business
   spending caps and Workspace aggregate caps (the audited-base finding was
   that the Workspace aggregate cap did not exist — see the reconciliation
   note below); per-contact cooldowns (§6.1, a genuinely new but narrow
   addition, not a financial control); and the UNIQUE `idempotency_key`
   constraint already governing every claim. Only a genuinely distinct,
   non-financial concern — how many *executions* (not dollars) a Business's
   automations may attempt per rolling hour, to bound infrastructural load
   rather than spend — is a candidate for a new mechanism, and it is scoped
   narrowly below for exactly that reason, not authorized as a general
   budget system.
2. **Business and Workspace/Account emergency controls are reused, not
   reinvented, once merged (§10.2).** The original recommendation to adapt
   `GoogleBusinessProfileCallBudget` into "a new per-Business automation-
   execution budget" is withdrawn as stated — `GoogleBusinessProfileCallBudget`
   remains a valid **concurrency precedent** (its row-locked reserve-before-
   call pattern), not automatic authorization to build a second
   spending/usage-control table. See §10.2 for the full reconciliation with
   the in-flight, unmerged Slice 5 wallet/kill-switch work.

- **Maximum causation-chain depth: 3.** Beyond this, `skipped` with reason
  `causation_depth_exceeded`. This is new, narrow abuse-prevention state
  (§5.1's `causation_id` chain) with no existing analog to reuse — not a
  financial control, so it is not affected by point 1 above.
- **Per-contact, per-automation cooldown:** recommended default 5 minutes
  for message-sending actions, configurable per automation only within a
  platform-enforced floor (never zero, never customer-disableable below the
  floor). Same status as the depth limit — new, narrow, non-financial.
- **Per-Business execution-rate throttle (distinct from spend):** if a
  rolling-hour cap on automation-execution *attempts* is still recommended
  after point 1's mechanisms are shown insufficient for a purely
  infrastructural (non-financial) concern, it follows the row-locked
  reserve-before-call *pattern* `GoogleBusinessProfileCallBudget::reserve()`
  already proves safe (`docs/automation/GOOGLE-BUSINESS-PROFILE-CONTRACT.md`
  §24.3/§24.9) — but it must be its own narrowly-scoped counter for
  execution attempts, explicitly not a dollar-denominated limit, and it
  does not ship until the point-1 review is actually performed against
  current `main` at implementation time, not assumed necessary now.
- **Monthly and spend-related limits:** enforced by the existing, real
  `UsageWalletManager::setFeatureLimit()`/`setSpendCap()`
  (`UsageWalletManager.php:1184/1225`) once automation sends are wired
  through wallet reservation at all (§10 — currently they are not, §2.3).
  **No automation-specific spend limit of any kind may be introduced** —
  every dollar-denominated cap an automation send is subject to is the same
  cap every other wallet-reserving feature is subject to.
- **Kill-switch — corrected scope.** No platform-wide or Business-scoped
  emergency kill switch exists at the audited base (confirmed by the CX
  contract's own E-20 finding, "No `kill_switch` / `emergency` control
  exists"). **The original recommendation that this document mandate a new
  platform-global kill switch is withdrawn.** No contract this document has
  read requires a platform-operator global shutdown; inventing one as a
  hard prerequisite would itself be an unauthorized new control. The locked
  current scope is **Business-scoped and Workspace/Account-scoped**
  emergency controls — see §10.2 for the authoritative source of these
  controls (another lane's in-flight, unmerged Slice 5 work) and the reuse
  rule that governs them. A platform-operator global shutdown remains a
  plausible **future owner decision** (§14), not something this document
  invents or makes mandatory.
- **Dead-letter handling:** the stage-2 outbox delivery record's
  `delivery_state = dead_letter` (§5.1) plus its bounded `attempts` counter
  — never the stage-1 event record, which stays immutable; an
  operator-visible list view is required before any class-C producer ships
  (§11 Slice 1), not deferred to later.
- **Operator diagnostics:** every stage-1 event, every stage-2 delivery row,
  and every `AutomationExecution` row remains queryable by an operator for
  a bounded retention window (§5.1's `retention_days`), with the same
  safe-summary-only redaction discipline B4 §11 already locks for execution
  history.

---

## 7. GUIDED RECIPE CATALOGUE

The CX contract's own §14.2 already locks a smaller starter catalogue (11
rows) gated behind its Slice 7. This section is the deeper, local-business-
oriented expansion the task requires (photobooths, roofing companies, and
similar service businesses), cross-referenced against that table rather than
duplicating it.

### 7.0 Three-state classification (Correction Round 1 — replaces the
binary "Can ship now?" model)

**The original binary conclusion — "zero of the seventeen recipes can ship
today" — was imprecise and is withdrawn.** It correctly identified that no
recipe is ready for the intended *managed, guided* customer experience, but
it stated that as if the underlying trigger/action mechanics were absent,
which is not true for Recipe 1: `contact_created → send_message` is a real,
mechanically-runnable pair today (§2.1). Conflating "not the final product
experience" with "does not run" understates what B4 actually built and
overstates what is missing. Every recipe below is now classified against
three independent states, and a recipe's "Upstream features required" column
and its classification must never contradict each other (the original
document's own Recipe 1 row did exactly that: "None" in one column, "blocked
on default-identity work" in the next):

| State | Meaning |
|---|---|
| **1 — Technically runnable today** | Both the trigger and the action mechanically execute now, through the existing (legacy, technical) B4 configuration path — i.e. an administrator could create this automation today via the current When/Then form, using a stored `sender_id`/`sending_server`, and it would fire. This says nothing about whether it is *safe or acceptable to expose in the guided catalogue* — see state 2. |
| **2 — Product/release blocked** | State 1 holds, but the recipe is not acceptable to ship in the guided, managed-messaging product experience the task requires, because it still needs one or more of: default Business messaging-identity resolution (CX Slice 6); the new managed-provider foundation (CX Slice 3); wallet reservation/cost estimation (§10, CX Slice 5); safe guided activation (CX §14.3); removal of sender/server technical choices from the customer-facing form (CX §27 row C-4). A recipe in this state is **not shown** in the guided catalogue (§4's "never shown disabled" rule) until every blocking item clears — the *legacy* When/Then path remains technically capable of it in the meantime, which is a fact about the engine, not an endorsement of exposing it there. |
| **3 — No producer/action substrate** | The trigger, the action, or both do not exist as real, dispatchable code at all (§3/§4's `requires-another-feature-first`/`data-exists-no-producer`/`deferred` classifications). Nothing about CX Slices 3–6 unblocks this state — a net-new domain (Calendar/Booking, Business→client payments, Reviews, CRM pipeline, a Conversation model, a notify-staff action) must be built first. |

Applying this consistently: **Recipe 1 is state 1 and state 2
simultaneously** — it runs today through legacy configuration, and it is
withheld from the guided catalogue until CX Slices 3, 5 and 6 land. Recipes
2–17 are all **state 3** — no amount of default-identity or wallet work
unblocks them, because their trigger or action (or both) has no substrate at
all. This replaces the original "zero can ship" framing with the accurate
one: **one recipe (#1) is real but withheld pending product-readiness work;
sixteen recipes have no runnable substrate at all and are withheld pending
new domains.** No recipe in this document is shown in the guided catalogue
in state 1 alone — state 2's product-readiness bar governs every entry,
including Recipe 1.

### 7.1 Summary table

| # | Recipe | Trigger substrate | Action substrate | State | Product-shippable once |
|---|---|---|---|---|---|
| 1 | New lead instant reply | Exists (`contact_created`) | Exists (`send_message`, legacy config) | **1 + 2** | CX Slices 3, 5, 6 land (§9) |
| 2 | Missed-call text back | Does not exist (no voice ingestion) | Exists | **3** | Voice-event ingestion is built (net-new, out of scope, CX §10.5) |
| 3 | Appointment confirmation | Does not exist (no Booking domain) | Exists | **3** | A Calendar/Booking contract is authorized and built (§3.3) |
| 4 | Appointment reminder | Does not exist | Exists | **3** | Same |
| 5 | Appointment rescheduled | Does not exist | Exists | **3** | Same |
| 6 | Appointment cancelled | Does not exist | Exists | **3** | Same |
| 7 | No-show follow-up | Does not exist | Exists | **3** | Same |
| 8 | Payment received thank-you | Does not exist (no Business→client payments) | Exists | **3** | A Business→client payments contract is authorized and built (§3.4) |
| 9 | Payment failed reminder | Does not exist | Exists | **3** | Same |
| 10 | Deposit received confirmation | Does not exist | Exists | **3** | Same |
| 11 | Remaining balance reminder | Does not exist | Exists | **3** | Same |
| 12 | Inbound-message owner notification | Exists but unsafe (§3.2) | Does not exist (no notify-staff action) | **3** | §11 Slice 2 (Business-scoped message producer) **and** a new notify-staff action both land |
| 13 | Lead has not replied | Does not exist (needs a Conversation model) | Exists | **3** | A Business-scoped Conversation model exists (§3.1/§3.2) |
| 14 | Post-service review request | Does not exist | Does not exist (no review-request action) | **3** | Calendar/Booking **and** review-ingestion domains both exist (§3.3, §3.6) |
| 15 | Quote request follow-up | Does not exist (no Quote/Pipeline) | Exists | **3** | A CRM Quote/Pipeline domain is authorized and built |
| 16 | Customer reactivation | Does not exist (same dependency as #13) | Exists | **3** | Same as #13 |
| 17 | Wallet/compliance action required | Does not exist (`BusinessMessagingIdentity` status) | Does not exist (no notify-staff action) | **3** | CX Slices 3/4 land (`BusinessMessagingIdentity`) **and** a notify-staff action exists |

**Corrected conclusion:** one recipe (#1) is technically runnable today and
is withheld only for product-readiness reasons (state 2); the remaining
sixteen have no runnable substrate in any form (state 3) and are withheld
because a net-new domain or action does not exist. This distinction — real
engine, incomplete product experience, versus no engine at all — is the
corrected central finding of this section, and it changes the delivery-plan
framing in §11: Recipe 1 is a **near-term, well-understood** target once
CX Slices 3/5/6 land; recipes 2–17 are not.

### 7.2 Full specification — all seventeen recipes

Every recipe below carries all thirteen required fields. For a state-3
recipe, the fields describe the *intended* product once its dependency
exists — they are explicitly labelled as unbuilt, never presented as
current runtime behavior.

---

**Recipe 1 — New lead instant reply**

- **Plain-language title:** "Reply to a new lead."
- **Description:** "The moment someone becomes a new contact for your
  business, send them an instant text so they know you got their
  information." Concrete example message: *"Hi {first_name}, thanks for
  reaching out to {business_name}! We'll be in touch shortly."*
- **Trigger:** Contact created (`contact_created`) — exists, runs today.
- **Action(s):** Send SMS (`send_message`) — exists, runs today through
  stored `sender_id`/`sending_server` configuration (legacy path); the
  guided product path instead resolves the Business's default identity
  automatically (CX §10.4, pending CX Slice 6).
- **Upstream dependencies:** none for state-1 (legacy, technical)
  operation — an administrator can build this today via the custom
  When/Then form. **For guided-catalogue exposure (state 2):** CX Slice 3
  (provider foundation), CX Slice 5 (wallet/payer controls), CX Slice 6
  (default-identity resolution and removal of sender/server fields from
  the automation form).
- **Information the customer must supply:** nothing required to activate;
  message text is editable.
- **Safe defaults:** the example message above; no audience restriction
  (any group); send immediately (no delay).
- **Editable fields:** message text, audience (contact group), whether to
  restrict to a specific creation source once one exists (§3.1).
- **Estimated-cost behavior:** cannot be shown honestly today — automation
  sends do not reserve against the wallet (§2.3, §10). Once CX Slice 5 and
  this document's §10 wiring land: one SMS segment per new contact, shown
  as "≈ {N} sends/month based on your recent contact volume" (CX §14.3).
- **Consent/STOP implications:** a brand-new contact has not yet had the
  chance to opt out; the recipe's own first message doubles as the
  practical first consent-relevant touch, so its copy must include a clear
  opt-out instruction per the Telnyx decision document's 10DLC
  sample-message requirement. Enforced today at execution time by
  `SendMessageAction`'s `STATUS_SUBSCRIBE` check (§3.2), which the guided
  path inherits unchanged.
- **Activation checklist (guided path):** default messaging identity
  exists (CX §14.3); usage balance sufficient for a realistic month's
  volume; consent copy present in the message.
- **Blocked/empty state:** in the guided catalogue, simply **not shown**
  until state 2 clears (§4's "never shown disabled" rule) — never
  shown-and-disabled. In the existing custom/advanced form, it is already
  fully buildable today (state 1).
- **Technically runnable today?** **Yes** — via the legacy/custom
  configuration path.
- **Product-shippable?** **No** — withheld from the guided catalogue until
  CX Slices 3, 5 and 6 land.

---

**Recipe 2 — Missed-call text back**

- **Plain-language title:** "Text back a missed call."
- **Description:** "When your business phone gets a call nobody could
  answer, automatically text the caller so they know you'll follow up."
- **Trigger:** Missed call — does not exist; zero voice-webhook ingestion
  of any kind exists in the repository (§3.2).
- **Action(s):** Send SMS — action mechanism exists, but has no caller
  identity to target without a missed-call event carrying one.
- **Upstream dependencies:** a net-new voice-event ingestion capability.
  Explicitly out of scope of every current slice (CX §10.5: "Voice/calling
  is explicitly out of scope for every slice") — this is not a near-term
  item.
- **Information the customer must supply (once built):** nothing beyond
  activating the recipe; message text editable.
- **Safe defaults (once built):** *"Sorry we missed your call! Text us
  here or we'll call you back shortly."*
- **Editable fields (once built):** message text, whether to also notify
  staff.
- **Estimated-cost behavior (once built):** one SMS segment per missed
  call, same wallet-reservation dependency as Recipe 1.
- **Consent/STOP implications:** a missed call is not itself proof of
  consent to receive SMS; this recipe would need its own consent-basis
  review before this document's future amendment authorizes it — flagged,
  not resolved here.
- **Activation checklist (once built):** voice ingestion connected; default
  identity resolved; consent basis confirmed.
- **Blocked/empty state:** not shown in any catalogue; no path exists to
  create it today, guided or custom.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3, blocked on a net-new,
  explicitly out-of-scope domain.

---

**Recipe 3 — Appointment confirmation**

- **Plain-language title:** "Confirm a new booking."
- **Description:** "The moment a customer books an appointment, send them
  a confirmation text with the date and time."
- **Trigger:** Appointment booked — does not exist (§3.3, no Calendar/
  Booking model, migration, controller or event of any kind).
- **Action(s):** Send SMS — action mechanism exists, but has no
  appointment entity to read a date/time from.
- **Upstream dependencies:** a Calendar/Booking product domain, requiring
  its own separately-authorized contract (CX §26 excludes it explicitly;
  §11 Slice 3 of this document does not path-list it for exactly this
  reason).
- **Information the customer must supply (once built):** nothing beyond
  activation; message text and included fields (date/time/location)
  editable.
- **Safe defaults (once built):** *"Hi {first_name}, your appointment with
  {business_name} is confirmed for {date} at {time}."*
- **Editable fields (once built):** message text, which appointment fields
  to include, delay before sending (immediate vs. a short buffer).
- **Estimated-cost behavior (once built):** one SMS segment per booking,
  same wallet dependency as Recipe 1.
- **Consent/STOP implications:** a customer actively booking has given a
  clear transactional consent basis; still subject to the same
  `STATUS_SUBSCRIBE`/blacklist check as every other send.
- **Activation checklist (once built):** Booking domain exists; default
  identity resolved.
- **Blocked/empty state:** not shown anywhere; no producer path exists.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 4 — Appointment reminder**

- **Plain-language title:** "Remind a customer before their appointment."
- **Description:** "Automatically text a reminder a set amount of time
  before an upcoming appointment."
- **Trigger:** Appointment approaching (a time-based sweep against an
  appointment's start time) — does not exist (§3.3).
- **Action(s):** Send SMS — mechanism exists; no substrate to schedule
  against.
- **Upstream dependencies:** Calendar/Booking domain, as Recipe 3. Once it
  exists, this recipe additionally needs quiet-hours (§9, does not exist
  today) so a reminder never lands outside a customer's acceptable hours,
  and the stale-reminder guard §9 already locks (a reminder must re-verify
  the appointment's current state at claim time, mirroring B4 §5.2's
  stale-definition guard, so a cancelled/moved appointment never gets an
  obsolete reminder).
- **Information the customer must supply (once built):** how long before
  the appointment to send (e.g. "1 day before," "2 hours before," reusing
  the existing `AutomationTriggerEvaluator::OFFSET_ALLOWLIST` vocabulary,
  §9).
- **Safe defaults (once built):** 24 hours before; *"Reminder: you have an
  appointment with {business_name} tomorrow at {time}."*
- **Editable fields (once built):** offset, message text.
- **Estimated-cost behavior (once built):** one SMS segment per reminder,
  same wallet dependency as Recipe 1.
- **Consent/STOP implications:** same as Recipe 3.
- **Activation checklist (once built):** Booking domain exists; quiet
  hours configured; default identity resolved.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 5 — Appointment rescheduled**

- **Plain-language title:** "Notify a customer their appointment moved."
- **Description:** "When an appointment's date or time changes, text the
  customer the new details automatically."
- **Trigger:** Appointment rescheduled — does not exist (§3.3).
- **Action(s):** Send SMS — mechanism exists; no substrate.
- **Upstream dependencies:** Calendar/Booking domain (Recipe 3), plus §9's
  obsolete-job-cancellation rule (a pending Recipe 4 reminder tied to the
  old time must not also fire).
- **Information the customer must supply (once built):** nothing beyond
  activation.
- **Safe defaults (once built):** *"Your appointment with {business_name}
  has been moved to {new_date} at {new_time}."*
- **Editable fields (once built):** message text.
- **Estimated-cost behavior (once built):** one SMS segment per
  reschedule, same wallet dependency.
- **Consent/STOP implications:** same as Recipe 3.
- **Activation checklist (once built):** Booking domain exists; the
  reminder-cancellation interaction with Recipe 4 is proven (§9).
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 6 — Appointment cancelled**

- **Plain-language title:** "Confirm a cancelled appointment."
- **Description:** "When an appointment is cancelled, text the customer a
  confirmation so there's no confusion."
- **Trigger:** Appointment cancelled — does not exist (§3.3).
- **Action(s):** Send SMS — mechanism exists; no substrate.
- **Upstream dependencies:** Calendar/Booking domain (Recipe 3), plus the
  same obsolete-reminder-cancellation rule as Recipe 5.
- **Information the customer must supply (once built):** nothing beyond
  activation.
- **Safe defaults (once built):** *"Your appointment with {business_name}
  on {date} has been cancelled. Reply if you'd like to rebook."*
- **Editable fields (once built):** message text.
- **Estimated-cost behavior (once built):** one SMS segment per
  cancellation, same wallet dependency.
- **Consent/STOP implications:** same as Recipe 3.
- **Activation checklist (once built):** Booking domain exists.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 7 — No-show follow-up**

- **Plain-language title:** "Follow up after a missed appointment."
- **Description:** "When a customer doesn't show up for their
  appointment, automatically text them to reschedule."
- **Trigger:** No-show recorded — does not exist (§3.3); no-show is a
  status a Booking domain would need to support explicitly.
- **Action(s):** Send SMS — mechanism exists; no substrate.
- **Upstream dependencies:** Calendar/Booking domain (Recipe 3), including
  an explicit no-show status distinct from cancellation.
- **Information the customer must supply (once built):** nothing beyond
  activation.
- **Safe defaults (once built):** *"We missed you for your appointment
  today — no worries, reply here to find a new time."*
- **Editable fields (once built):** message text, delay after the missed
  slot before sending.
- **Estimated-cost behavior (once built):** one SMS segment per no-show,
  same wallet dependency.
- **Consent/STOP implications:** same as Recipe 3.
- **Activation checklist (once built):** Booking domain exists with a
  no-show status.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 8 — Payment received thank-you**

- **Plain-language title:** "Thank a customer after they pay."
- **Description:** "When a customer's payment to your business is
  received, automatically text them a thank-you."
- **Trigger:** Payment received (the Business's client paying the
  Business) — does not exist (§3.4). Neither the legacy `Invoices`
  model (`user_id`-scoped, not Business-scoped) nor the modern
  `UsageWalletManager`/`BusinessFundingAttemptSucceeded` pipeline (the
  Business funding its own usage balance) represents a client paying the
  Business.
- **Action(s):** Send SMS — mechanism exists; no substrate.
- **Upstream dependencies:** a net-new Business→client payments/invoicing
  domain, requiring its own separately-authorized contract (CX §26
  excludes "customer-issued Invoicing" explicitly).
- **Information the customer must supply (once built):** nothing beyond
  activation.
- **Safe defaults (once built):** *"Thanks for your payment of {amount},
  {first_name}! We appreciate your business."*
- **Editable fields (once built):** message text, whether to include the
  amount.
- **Estimated-cost behavior (once built):** one SMS segment per payment,
  same wallet dependency as Recipe 1.
- **Consent/STOP implications:** same as Recipe 3.
- **Activation checklist (once built):** Business→client payments domain
  exists.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 9 — Payment failed reminder**

- **Plain-language title:** "Remind a customer their payment failed."
- **Description:** "When a customer's payment to your business fails,
  automatically text them so they can retry."
- **Trigger:** Payment failed (client→Business) — does not exist, same
  dependency as Recipe 8.
- **Action(s):** Send SMS — mechanism exists; no substrate.
- **Upstream dependencies:** same as Recipe 8.
- **Information the customer must supply (once built):** nothing beyond
  activation.
- **Safe defaults (once built):** *"We weren't able to process your
  payment to {business_name}. Reply here or try again when convenient."*
- **Editable fields (once built):** message text.
- **Estimated-cost behavior (once built):** one SMS segment per failure,
  same wallet dependency.
- **Consent/STOP implications:** same as Recipe 3.
- **Activation checklist (once built):** same as Recipe 8.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 10 — Deposit received confirmation**

- **Plain-language title:** "Confirm a deposit was received."
- **Description:** "When a customer's deposit is received, automatically
  text them a confirmation."
- **Trigger:** Deposit received — does not exist; no `deposit` concept
  exists anywhere in the repository (§3.4).
- **Action(s):** Send SMS — mechanism exists; no substrate.
- **Upstream dependencies:** same Business→client payments domain as
  Recipe 8, which would need to introduce a deposit concept specifically
  (not merely a generic payment).
- **Information the customer must supply (once built):** nothing beyond
  activation.
- **Safe defaults (once built):** *"We've received your deposit of
  {amount}. Thanks, {first_name}!"*
- **Editable fields (once built):** message text.
- **Estimated-cost behavior (once built):** one SMS segment per deposit,
  same wallet dependency.
- **Consent/STOP implications:** same as Recipe 3.
- **Activation checklist (once built):** Business→client payments domain
  exists with a deposit concept.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 11 — Remaining balance reminder**

- **Plain-language title:** "Remind a customer about their remaining
  balance."
- **Description:** "Automatically text a customer when they still owe a
  remaining balance."
- **Trigger:** Remaining balance due — does not exist; no analog exists
  (the only "balance" concepts in the repository are the Business's *own*
  usage balance, never a balance a client owes the Business, §3.4).
- **Action(s):** Send SMS — mechanism exists; no substrate.
- **Upstream dependencies:** same Business→client payments domain as
  Recipe 8, with an explicit remaining-balance concept.
- **Information the customer must supply (once built):** nothing beyond
  activation.
- **Safe defaults (once built):** *"You have a remaining balance of
  {amount} with {business_name}. Reply here if you have questions."*
- **Editable fields (once built):** message text, reminder cadence.
- **Estimated-cost behavior (once built):** one SMS segment per reminder,
  same wallet dependency, plus a cooldown (§6) so a standing balance does
  not generate repeated reminders faster than a configured interval.
- **Consent/STOP implications:** same as Recipe 3.
- **Activation checklist (once built):** Business→client payments domain
  exists with a remaining-balance concept.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 12 — Inbound-message owner notification**

- **Plain-language title:** "Notify me about an important message."
- **Description:** "When a customer texts your business, automatically
  notify you or your staff."
- **Trigger:** Inbound message received — the current
  `App\Events\MessageReceived` is user-scoped, `ShouldBroadcastNow`, and
  non-transactional (§3.2, §8); **not** usable as an automation trigger.
  A new, Business-scoped, transactional producer is required (§11 Slice 2,
  §8 below).
- **Action(s):** Notify owner/staff — does not exist as an automation
  action at all; `SendMessageAction`/`UpdateContactFieldAction` both
  hard-target "the trigger Contact," never a staff member (§4).
- **Upstream dependencies:** §11 Slice 2 (the new Business-scoped message
  producer) **and** a new notify-staff action, neither of which exists
  today.
- **Information the customer must supply (once built):** which staff
  member/channel to notify (e.g. email, in-app, or a Business phone
  distinct from the customer-facing one).
- **Safe defaults (once built):** notify the Business owner via their
  account email; message: *"New message from {contact_name}: {excerpt}."*
- **Editable fields (once built):** recipient, notification channel,
  whether to include a message excerpt (a privacy-sensitivity choice).
- **Estimated-cost behavior (once built):** if the notification channel is
  SMS/email with its own cost, same wallet-dependency shape as Recipe 1;
  if in-app only, no cost.
- **Consent/STOP implications:** none on the inbound side (the customer
  already messaged the Business); the notification itself does not message
  the customer, so STOP/blacklist does not apply to this action.
- **Activation checklist (once built):** Business-scoped message producer
  live; notify-staff action exists; recipient configured.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 13 — Lead has not replied**

- **Plain-language title:** "Follow up when a lead goes quiet."
- **Description:** "If a new lead hasn't responded after a set amount of
  time, automatically send a follow-up text."
- **Trigger:** No response after a configured period — a *derived* trigger
  (absence of an event within a window); requires a Business-scoped
  Conversation model to compute "no response" against (§3.1/§3.2), which
  does not exist (`ChatBox` is user-scoped, not Business-scoped).
- **Action(s):** Send SMS — mechanism exists; no substrate to evaluate the
  "no response" condition against.
- **Upstream dependencies:** a Business-scoped Conversation model — a
  smaller, more tractable dependency than Calendar/Payments, but still
  net-new (§3.1).
- **Information the customer must supply (once built):** how long to wait
  before following up.
- **Safe defaults (once built):** 24 hours; *"Just following up —
  {business_name} is still here if you have questions!"*
- **Editable fields (once built):** wait duration, message text.
- **Estimated-cost behavior (once built):** one SMS segment per follow-up,
  same wallet dependency, plus a per-contact cooldown so this never fires
  more than once per lead per configured window (§6).
- **Consent/STOP implications:** same as Recipe 1 (the lead already
  consented via their initial contact).
- **Activation checklist (once built):** Conversation model exists; wait
  primitive exists (§11 Slice 7, itself gated on a B4 contract amendment).
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 14 — Post-service review request**

- **Plain-language title:** "Ask for a review after service."
- **Description:** "After completing an appointment or job, automatically
  text the customer a request for a review."
- **Trigger:** Appointment/service completed — does not exist (§3.3, and
  §3.6's "service completed" row).
- **Action(s):** Request review — does not exist as an automation action;
  no review-ingestion capability exists anywhere (§3.6; Google Business
  Profile Slice A is explicitly read-only and excludes reviews).
- **Upstream dependencies:** **both** the Calendar/Booking domain and a
  review-ingestion/request capability, neither of which exists — the
  double dependency named explicitly in the original catalogue is
  confirmed unchanged by this correction.
- **Information the customer must supply (once built):** which review
  platform to link to (once a review capability exists).
- **Safe defaults (once built):** send 1 day after completion; *"Thanks
  for choosing {business_name}! We'd love a quick review: {review_link}."*
- **Editable fields (once built):** delay, message text, review link.
- **Estimated-cost behavior (once built):** one SMS segment per request,
  same wallet dependency, plus the duplicate-review-request idempotency
  key already designed in §6.1.
- **Consent/STOP implications:** same as Recipe 3.
- **Activation checklist (once built):** both dependency domains exist.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 15 — Quote request follow-up**

- **Plain-language title:** "Follow up on a quote."
- **Description:** "After a customer accepts or declines a quote,
  automatically text them a follow-up."
- **Trigger:** Quote accepted / not accepted — does not exist; no Quote
  model exists (CX E-32).
- **Action(s):** Send SMS — mechanism exists; no substrate.
- **Upstream dependencies:** a net-new CRM Quote/Pipeline domain, requiring
  its own separately-authorized contract.
- **Information the customer must supply (once built):** nothing beyond
  activation.
- **Safe defaults (once built):** *"Thanks for reviewing our quote! Let us
  know if you have any questions."* (accepted path); *"No worries if now
  isn't the right time — we're here whenever you're ready."* (declined
  path).
- **Editable fields (once built):** message text per branch.
- **Estimated-cost behavior (once built):** one SMS segment per event,
  same wallet dependency.
- **Consent/STOP implications:** same as Recipe 3.
- **Activation checklist (once built):** CRM Quote/Pipeline domain exists.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 16 — Customer reactivation**

- **Plain-language title:** "Win back a quiet customer."
- **Description:** "If an existing customer hasn't engaged in a while,
  automatically send a re-engagement text."
- **Trigger:** No response after a configured period — same dependency as
  Recipe 13 (a Business-scoped Conversation model), applied to existing
  customers rather than new leads.
- **Action(s):** Send SMS — mechanism exists; no substrate.
- **Upstream dependencies:** same as Recipe 13.
- **Information the customer must supply (once built):** how long to wait
  before reactivating; which contact group counts as "existing customer."
- **Safe defaults (once built):** 90 days; *"It's been a while! Reply here
  if there's anything {business_name} can help with."*
- **Editable fields (once built):** wait duration, audience group, message
  text.
- **Estimated-cost behavior (once built):** one SMS segment per
  reactivation attempt, same wallet dependency, plus a per-contact cooldown
  so a quiet customer isn't repeatedly "reactivated" every sweep cycle.
- **Consent/STOP implications:** same as Recipe 3; an existing customer's
  historical opt-in must still be valid (not superseded by an intervening
  STOP).
- **Activation checklist (once built):** same as Recipe 13.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

**Recipe 17 — Wallet/compliance action required**

- **Plain-language title:** "Alert me when my business phone needs
  attention."
- **Description:** "Automatically notify you when your business phone
  setup needs action — a compliance step, a low balance, or a paused
  number."
- **Trigger:** Phone/compliance setup requires action — depends entirely
  on `BusinessMessagingIdentity` and its `status` state machine from the
  Telnyx decision document (§2.4), neither of which exists yet.
- **Action(s):** Notify owner/staff — does not exist (same gap as Recipe
  12).
- **Upstream dependencies:** CX Slices 3/4 (`BusinessMessagingIdentity`
  and the compliance state machine) **and** the same notify-staff action
  Recipe 12 needs.
- **Information the customer must supply (once built):** notification
  channel/recipient.
- **Safe defaults (once built):** notify the Business owner via account
  email immediately on any status change requiring action.
- **Editable fields (once built):** recipient, which status changes
  trigger a notification.
- **Estimated-cost behavior (once built):** in-app/email notification, no
  telecom cost; **explicitly must reuse the authoritative Business/
  Workspace low-balance and compliance-alert mechanism once CX Slice 5
  merges (§10.2), never a second, automation-specific alert pipeline.**
- **Consent/STOP implications:** none — this notifies the Business owner
  about their own account, not a customer send.
- **Activation checklist (once built):** `BusinessMessagingIdentity`
  exists; notify-staff action exists.
- **Blocked/empty state:** not shown anywhere.
- **Technically runnable today?** **No.**
- **Product-shippable?** **No** — state 3.

---

## 8. SIMPLIFIED CREATION EXPERIENCE

The target flow the task specifies is already locked, verbatim, by the CX
contract's §14.1–§14.3 and is not re-derived here — this document confirms it
and adds the one missing mechanical detail (what "the system resolves"
concretely means today):

1. "What do you want to happen?" → CX §14.1, outcome-first entry.
2. Choose a recipe → CX §14.2's catalogue, this document's §7 extends it.
3. See what starts it → plain-language trigger description, no internal key
   ever rendered (CX §4 forbidden-terms list: *sub-account, sending server,
   sender ID, messaging profile, connection ID, feature key, meter, micro,
   reservation, tenant, entitlement*).
4. Confirm audience and timing → contact group + offset/send-time picker,
   reusing the exact allowlisted offset vocabulary already proven safe by
   `AutomationTriggerEvaluator::OFFSET_ALLOWLIST`.
5. Review message/content → the example-message pattern from §7.1.
6. See estimated cost and missing setup → CX §14.3's cost-estimate
   requirement; "missing setup" surfaces exactly the activation-checklist
   failures named per recipe (§7.1).
7. Test safely → CX §14.3's test-send rule: metered and billed like a real
   send, explicitly labelled as a test in the ledger (S-11).
8. Activate → draft until explicit activation; drafts never execute (CX
   §14.3).

**What the system resolves automatically, and what currently blocks each:**

| Resolved item | Mechanism | Exists today? |
|---|---|---|
| Business | Already-resolved request-scoped Business (B4 §2.2's mandatory resolution chain) | Yes |
| Channel | `BusinessMessagingIdentity.provider` (Telnyx decision doc §8) | **No** |
| Default phone/sender | `BusinessMessagingIdentity.phone_number` | **No** |
| Payer | `BillingProfileManager`/payer model (CX §12.4) | Yes, but not yet wired to automation sends (§10) |
| Usage balance | `BusinessUsageWallet` | Yes, but not yet reserved by automation sends (§2.3) |
| Timezone | `businesses.timezone` | Yes — already used by `AutomationTriggerEvaluator::dueForDateReached()` |
| Compliance status | `BusinessMessagingIdentity.status` | **No** |

Advanced/technical overrides (explicit sender selection, raw provider
fields) remain available **only** in the existing When/Then custom form,
reached from the catalogue's last entry — never surfaced in the guided path
(CX §14.1).

---

## 9. TIMING AND SCHEDULING SEMANTICS

- **Business-local timezone:** `businesses.timezone`, the same column
  `AutomationTriggerEvaluator::dueForDateReached()` already uses
  (`:58`, falling back to `config('app.timezone', 'UTC')`). This document
  requires every new time-based trigger (appointment reminders, invoice-due
  sweeps, wallet-low-balance checks) to resolve time the same way — never a
  request-time browser timezone, never a legacy per-record timezone column
  (B4 §6.A already rejected `automations.timezone` and `$user->timezone` for
  exactly this reason).
- **Daylight-saving behavior:** inherited for free from `CarbonImmutable`'s
  own timezone-aware arithmetic, exactly as the existing evaluator already
  relies on (`$localNow->setTimezone($timezone)` then `->modify('+' .
  $offset)`) — no new DST-handling code is introduced by this document; new
  producers must use the identical Carbon-timezone pattern rather than
  manual offset math.
- **Quiet hours:** does not exist today (CX E-29, confirmed independently).
  A **hard prerequisite** for any recipe in §7 that sends outbound messages
  on a schedule the customer does not directly control (i.e. every
  appointment/payment/review recipe) — CX §14.4 already locks this as
  mandatory, "defaulting to a conservative window."
- **Send windows:** derived from quiet hours once it exists; not a separate
  mechanism.
- **Delay calculation:** the existing offset-adjusted-occurrence pattern
  (`AutomationTriggerEvaluator::dueForDateReached()` lines 70-72) is the
  proven house pattern: compute the occurrence in Business-local time,
  derive the idempotency key's year component from the *occurrence*, never
  from "now" — this document requires every new delay-based trigger
  (appointment reminders, invoice-due-soon) to use the identical pattern,
  not reinvent one.
- **Appointment changes after scheduling / cancellation of obsolete jobs:**
  cannot be answered today — the Calendar/Booking domain does not exist
  (§3.3). This document locks the *rule* for whenever that domain lands:
  **no automation may send an obsolete reminder after an appointment is
  cancelled or moved.** Mechanically, this means a reminder-sweep producer
  must re-verify the appointment's current state at claim time (mirroring
  B4 §5.2's stale-definition guard exactly — the automation-definition
  re-check under a row lock, immediately before the claim insert) rather
  than trusting the state captured when the reminder was first scheduled.
- **Long-delay persistence:** the existing `automation_executions` ledger
  with a durable `idempotency_key` already proves the pattern for
  indefinitely-safe re-evaluation (the five-minute sweep can run forever
  without ever double-firing) — no new persistence mechanism is needed, only
  new producers that plug into the same claim service.
- **Retry windows:** none exist and none are introduced — consistent with
  B4's locked no-automatic-retry policy (§2.2, §6.2).
- **Clock skew / duplicate scheduler runs:** already solved structurally by
  the UNIQUE `idempotency_key` constraint plus the absence of
  `withoutOverlapping()` being safe *because* of that constraint (§2.2) —
  this document requires every new sweep to rely on the same structural
  safety rather than adding `withoutOverlapping()` as a substitute for a
  real idempotency key.
- **Date-only versus timestamp fields:** `contact_date_reached` already
  demonstrates the correct pattern (a date-only custom field value compared
  against a timestamp-derived local date via `DATE_FORMAT`/`STR_TO_DATE`);
  new time-based triggers must be explicit about which of their own fields
  are date-only (e.g. an invoice due-date) versus true timestamps (e.g. an
  appointment start time), since the correct comparison logic differs.

---

## 10. MESSAGING AND COST SAFETY

**This is the audit's most consequential finding, and it is why Recipe 1
(§7) is technically runnable but not product-shippable: automation sends
currently bypass the wallet system entirely.**

- `SendMessageAction::run()` never calls `UsageWalletManager::reserve()`
  (confirmed by direct reading of the file, §2.3) — it checks plan/country
  coverage but reserves nothing and debits nothing through the modern
  wallet. The B4 contract itself locked this as deliberate for v1 ("B4
  **inherits** whatever accounting the B1 Business Outreach send core
  performs... No UsageWallet redesign, no billing cutover, in B4" — B4
  §7.A), and B1's own send core (`CampaignRepository::quickSend()`) does not
  reserve wallet balance either — confirmed by the Telnyx decision
  document's own repository-impact finding, written against this same
  baseline: "No usage-wallet hook exists on any message send today."
- **Consequence for this document's delivery plan:** no recipe that sends a
  message can honestly display a pre-activation cost estimate (CX §14.3's
  own required field) until automation sends reserve against the wallet.
  This is not a cosmetic gap — without it, "estimated cost per run" is
  fiction, and C-1 of the CX contract's own cost-control invariants ("No
  external provider call without prior authorization **and** an available
  budget") is not actually enforced for automation-originated sends today.

### 10.1 Design, reusing the existing wallet primitives without duplicating them

| Requirement | Mechanism (existing, to be wired, not reinvented) |
|---|---|
| Cost estimate before activation | Derived from the recipe's expected monthly volume (§7.1) × the Business's active retail rate — not gated on §28.1a's rate-card approval existing yet, since an estimate can be shown as a range/placeholder until a rate is active, exactly as CX §14.3 already requires without assuming a rate exists |
| Reservation immediately before action | `UsageWalletManager::reserve()` (`:285`), called from inside `SendMessageAction::run()` immediately before the `CampaignRepository::quickSend()` call — never earlier (claim time), never inside the same transaction as the provider call (CX §19, "Provider calls — never inside a database transaction") |
| Release on non-send | `UsageWalletManager::release()` (`:810`) — called whenever `SendMessageAction` returns `skipped()` or `failed()` before the provider call actually fires |
| Settlement from provider-confirmed outcome | `UsageWalletManager::commit()` (`:544`) — called only after the provider response (or, once it exists, a delivery-status webhook) confirms the true outcome, never from local persistence alone (the Telnyx decision document's own rule: "a database row saying 'sent' is never itself proof a message left the platform") |
| Monthly Business cap | `setSpendCap()`/`setFeatureLimit()` (`:1184`/`:1225`) against the real `monthly_spend_cap_micro`/`monthly_recharge_cap_micro` columns on `business_usage_wallets` (`database/migrations/2026_08_16_120001_create_business_usage_wallets_table.php:38,47`), already real |
| Workspace aggregate cap | Does not exist today (CX E-19, confirmed) — a CX Slice 5 dependency, not something this document can build around |
| Kill switch | Does not exist today (§6.2) — hard prerequisite, not automation-specific |
| Insufficient-funds behavior | Fail closed, no queued retry — matching CX §14.4's locked rule exactly ("Fail closed, alert the payer, do not queue an unbounded retry"); `UsageWalletManager::reserve()` already blocks on `WalletBillingStatus::Suspended` and on `debt_balance_micro > 0` (`UsageWalletManager.php:351-357`), the exact fail-closed behavior a wired-in automation send inherits for free |
| Agency-paid versus Business-paid | `App\Enums\Usage\PayerType` has three cases: `Business`, `Workspace` (the Agency-level payer), and `AgencyRebill` — the last one documented in its own enum docblock as "never customer-selectable and never assigned by any M2 code path — inert until a future, separately authorized milestone." `BillingProfileManager::assignInitialPayer()`-style logic (`app/Library/Usage/BillingProfileManager.php:66`) already picks `Business` vs. `Workspace` by plan tier. Automation sends simply reserve against whichever Business's wallet is already resolved; the payer attribution is already correct by construction once reservation is wired in (§2.3), with no automation-specific payer logic of its own |
| BYO transport | Automation sends over a BYO-configured channel must never reserve or debit the wallet for transport (CX §11.5's locked measurement-vs-charging distinction) — the automation engine does not need to know whether a channel is managed or BYO; that distinction lives entirely in `BusinessMessagingIdentity`/the provider adapter, once built |
| AI/non-transport costs | Not applicable — no AI action exists today (§4) |
| Customer-visible explanation | Plain-language, matching CX §17.3's empty-state discipline — never a feature key, never a classification value |

**No provider credential or raw meter key ever belongs in the recipe UI** —
this is already locked as a blocking invariant (CX §18 row S-1) and this
document adds nothing to it beyond confirming the automation engine today
has zero surface where such a value could leak (no credential value is read
by any file under `app/Library/Automation/**`).

### 10.2 Reconciliation with the in-flight Slice 5 wallet/kill-switch work
(Correction Round 1)

**At the audited base (`origin/main` at `6c820c80...`), the controls this
section depends on — Business spending controls, Workspace aggregate
controls, Business and Workspace kill switches, spending-threshold alerts,
and wallet/payer UX — were confirmed absent** (§2.3, §6.2, the CX contract's
own E-19/E-20 findings). Since that audit, another lane has reportedly
produced an **unmerged** branch implementing exactly these controls as CX
Slice 5. This document does not inspect, cite as merged fact, or build any
design decision on the content of that unmerged branch — doing so would
describe unreviewed, unmerged work as repository truth, which this
correction round explicitly prohibits. The following rules govern how §11
Slice 5 of this document's own delivery plan must treat that situation:

1. **At the audited base, the controls were absent.** Every "does not
   exist" finding in this document about kill switches, Workspace aggregate
   caps, and spending-threshold alerts (§2.3, §6.2, §14) describes the
   state of `origin/main` at the SHA this document audited, not a
   permanent architectural gap.
2. **This document's automation-expansion work depends on the authoritative
   Slice 5 controls once merged** — not on a parallel implementation this
   document might otherwise have been tempted to design in §6.2 or here.
3. **The implementation slice that wires automation sends to wallet
   reservation (§11 Slice 5 of this document) must re-audit current `main`
   before adding any wallet-threshold producer or kill-switch integration.**
   The mechanical facts this document cites (which columns exist, which
   methods exist, which controls are absent) are a snapshot, not a
   standing guarantee — they must be re-verified against whatever `main`
   looks like at implementation time, which may already include the merged
   Slice 5 work.
4. **The implementation must reuse the merged Business/Workspace controls
   rather than create a second system.** Once Slice 5 merges, "wallet
   balance low" (§3.6), "usage limit approached" (§3.6), and any
   kill-switch integration this document's producers need (§6.2) must call
   into whatever authoritative service/table Slice 5 introduces — never a
   second low-balance sweep, a second spending-alert command, a second
   kill-switch table, or a second limit mechanism, regardless of how
   closely this document's own §6.2/§10.1 tables describe the *shape* of
   what's needed. This document's tables describe **requirements** an
   implementation must satisfy, not a schema to build independently of
   whatever Slice 5 actually ships.
5. **No duplicate low-balance sweep, spending-alert command, kill-switch
   table, or limit mechanism may be introduced by this document's own
   delivery plan** — §11 Slice 5's own row is corrected accordingly.

### 10.3 The exact dependency chain Recipe 1 sits behind (Correction Round 1)

Stated plainly, once more, so it cannot be read as a single "one thing is
missing" gap: Recipe 1 (§7) is technically runnable today, but **six**
distinct things must all be true before it is commercially safe to expose in
the guided catalogue, and none of them may be skipped:

1. **CX Slice 3** — provider identity, secure per-Business attribution, and
   measurement foundation (`BusinessMessagingIdentity`, §2.4) must exist.
2. **CX Slice 4** — real number/compliance provisioning, gated on Slice 3
   and 5, must have run for the sending Business.
3. **CX Slice 5** — wallet, payer, and spending controls (Business/
   Workspace caps, kill switches) must be merged and authoritative (§10.2).
4. **CX Slice 6 / this document's Slice 5** — default messaging identity
   resolution, removing the sender/server fields from the automation form
   (§2.4, §8).
5. **This document's own wallet-wiring addition** — `SendMessageAction`
   must, in order: estimate cost before activation; check the resolved
   payer and usage balance; enforce whatever Business/Workspace limits and
   kill switches are active (reused from Slice 5, never duplicated);
   reserve wallet balance immediately before the provider call; call the
   provider **outside** any wallet-reservation database transaction;
   commit or release the reservation based on the provider-confirmed
   outcome, never from local persistence alone (§5.1 stage 5); and
   re-check STOP/blacklist state immediately before sending, not from a
   value cached at claim time (§5.2 rule 8, §6.1). None of this exists
   today (§2.3) — it is not implied by Slices 3–6 alone and must be built
   as part of wiring the send action to the wallet.
6. **This document's Slice 6** — the guided recipe catalogue itself,
   which is what actually exposes Recipe 1 to a customer once 1–5 hold.

**This document does not, anywhere, claim Recipe 1 is commercially safe to
expose today or at any point before all six of the above are true.** Its
state-1 "technically runnable" classification (§7.0) describes only the
existing engine's mechanical capability through the legacy/custom
configuration path — never a claim about product readiness.

---

## 11. PRIORITIZED DELIVERY PLAN

Ten slices, dependency-ordered, adjusted from the task's recommended shape
against the repository evidence above. **Every slice below requires its own
contract before implementation — none is authorized by this document.** Each
slice is explicitly mapped to the CX contract's own §21 slice table where one
already exists, so the two documents never contradict each other on
ownership or ordering.

| # | Slice | Prerequisites | Candidate paths | Schema | Production behavior | Tests | Interaction with existing contracts | Stop conditions | Parallel? |
|---|---|---|---|---|---|---|---|---|---|
| **1** | Event-envelope and outbox foundation | None | New: `app/Library/Events/**`, `app/Models/OutboxEvent.php` (stage 1) and `app/Models/OutboxDelivery.php` (stage 2), two migrations per §5.1's separated column lists | Additive only, two new tables | No producer yet — this slice only builds the durable envelope + a generic dispatcher sweep + the dead-letter view | Envelope round-trips; stage-1/stage-2 separation proven (a delivery retry never mutates the stage-1 event row); dedup on `idempotency_key`; dead-letter view renders | Supersedes nothing; this is the missing infrastructure both this document's §5/§6 and the CX contract's §16 already require but neither has built | Must ship with the operator dead-letter view (§6.2). **Correction Round 1: does not require a platform-global kill switch** — that requirement is withdrawn (§6.2); it requires only that Business/Workspace-scoped emergency controls, once Slice 5 merges (§10.2), can pause this slice's own dispatcher sweep the same way they pause any other wallet-reserving feature | No — every later class-C producer depends on this |
| **2** | Inbound-message producer | 1 | New: `app/Events/Conversation/MessageReceived.php` (Business-scoped, distinct from the legacy `App\Events\MessageReceived`), a new `business_id`-carrying column or table for the inbound-message subject, dispatch site alongside `DLRController.php:629` (adds to, does not replace, the existing websocket broadcast) | Additive migration for Business-scoped conversation subject reference | Transactional, after-commit; never replaces `DLRController`'s existing chat-UI broadcast | Duplicate webhook delivery produces one outbox row; cross-Business payload rejected; self-reply loop guard (§6.1) proven | This is the CX contract's own explicitly-named first class-C producer (§15.1, §21 Slice 8) — this slice **is** that work, scoped precisely | Must not ship until `Blacklists`/STOP re-check (§5.2 rule 8) is proven against a redelivered event | No |
| **3** | Appointment producers | 1, **and its own separate product contract for the Calendar/Booking domain itself** | Out of scope for this document to path-list — no Appointment model exists (§3.3) | Net-new domain, not additive to anything existing | N/A until the Booking feature itself is contracted and built | N/A | This document does not authorize or path-list Calendar/Booking — CX §26 already excludes it explicitly | Cannot start until a Booking/Calendar contract exists and is separately authorized | No — hard-blocked |
| **4** | Payment/invoice producers | 1, **and its own separate product contract for Business→client invoicing** | Out of scope — no such domain exists (§3.4) | Net-new domain | N/A | N/A | CX §26 already excludes "customer-issued Invoicing" | Cannot start until that domain is separately contracted | No — hard-blocked |
| **5** | Safe default-sender action integration | CX Slices 3, 4, 5 (unchanged — this document does not shorten that chain, §2.4). **Correction Round 1: this slice must re-audit current `main` for the merged CX Slice 5 wallet/kill-switch controls before implementation and reuse them exactly (§10.2) — it must not build a parallel spending or kill-switch mechanism under any circumstances.** | This **is** CX Slice 6 (`SendMessageAction` resolves `BusinessMessagingIdentity` at execution, form selectors removed) | None new beyond what CX Slice 6 already specifies | Wallet reservation wired in per §10.1, calling the **authoritative, merged** `UsageWalletManager`/Slice-5 controls (re-verified at implementation time, §10.2) — this document's one addition beyond CX Slice 6's own scope, since CX Slice 6 does not itself mention the wallet gap | `T-SENDER-1`/`T-SENDER-2` (already specified by the CX contract) plus this document's own `T-COST-11` (wallet reserved before every automation send, §12) | Directly implements CX §21 Slice 6 and closes CX §27 row C-4 | Cannot start before CX Slices 3–5 land; cannot start before the merged Slice 5 controls are confirmed present on `main` (§10.2) | No |
| **6** | Recipe catalogue and guided UI | 5 | This **is** CX Slice 7, using this document's §7 catalogue as the fuller target list once each recipe's upstream dependency independently clears | None beyond CX Slice 7's own scope | Only recipe #1 (§7) is realistically shippable at this slice's start, since 2–17 each need a domain from Slices 3/4 or a feature not yet contracted | CX's own `T-AUTO-1..6` | Directly implements CX §21 Slice 7 | A recipe never ships before both its trigger and action are existing-and-usable (§3/§4) | No |
| **7** | Wait/condition primitives | 6 | New: an execution-state extension to `AutomationExecution` (or a sibling table) representing a paused, resumable step; **this is a genuine architecture change to B4's locked single-step model (B4 §1)** and requires its own contract amendment to B4, not a silent extension | Additive, but structurally significant | Every existing at-most-once/no-retry guarantee (§2.2) must be re-proven for a resumable execution, not assumed to carry over | New concurrency tests mirroring `AutomationsClaimConcurrencyTest.php`'s pattern, extended for a paused-then-resumed execution | **Requires a correction to B4-BUSINESS-AUTOMATIONS-CONTRACT.md** (§13 below records this as C-8, a new required amendment) | Must not ship without the existing single-step engine's safety invariants (claim, start-claim, final checkpoint) being proven to still hold for a multi-step execution | No |
| **8** | Review/reactivation recipes | 6, 7, and the review-ingestion / Conversation-model dependencies named per-recipe in §7 | Out of scope to path-list until those land | Net-new, per dependency | N/A | N/A | Depends on domains CX §26 and this document's §3.6 both mark as not yet existing | Hard-blocked on upstream domains | No |
| **9** | Advanced builder | 6 | No new work — the existing `resources/views/customer/Automations/form.blade.php` custom path already satisfies this once recipes exist to contrast it with (§8) | None | Already built | Already covered by `tests/Feature/Automations/**` | Confirms, does not change, the CX §14.1 "custom path" decision | None | Yes — independent of 7/8 |
| **10** | Reliability and accessibility pass | All others | Matches CX §21 Slice 10 exactly | None | Full regression, i18n/a11y sweep | CX's own `T-A11Y-1..3` plus this document's full §12 matrix as a regression | Directly implements CX §21 Slice 10 | None | No — last |

**Hard dependency restated plainly:** Slices 3, 4 and 8 of this table are not
implementation work this document can schedule — they are new product
domains (Calendar/Booking, Business→client payments, Reviews) that do not
exist in any form today and each needs its own contract before any of this
document's trigger/action work touches them. This document's own
deliverable is therefore realistically Slices 1, 2, 5, 6, 7, 9, 10 — the
messaging-and-recipe expansion the repository can actually support — with
3/4/8 recorded as scoped-out dependencies for the owner to prioritize
separately (§14).

### 11.1 Dependency and parallelization summary (Correction Round 1)

- **Exact prerequisites per slice:** listed in the table's own
  "Prerequisites" column above; none is implicit.
- **May run in parallel:** Slice 9 only (independent of 6/7/8, since it
  requires no new work beyond confirming the existing custom form still
  satisfies its role). Every other slice is serialized behind its listed
  prerequisite(s) — this document does not authorize any other parallel
  pairing, correcting any impression the original "Parallel?" column alone
  might have left about slices 1–8 and 10.
- **Depends on current in-flight (unmerged) work:** **Slice 5 only.** It
  depends on another lane's unmerged Slice 5 wallet/kill-switch branch
  landing on `main` first (§10.2) — this document does not inspect or cite
  that branch, and Slice 5 of this table may not start implementation
  until that work is confirmed merged.
- **Must re-audit `main` after an upstream merge before proceeding:**
  **Slice 5 only**, and specifically for the CX Slice 5 wallet/kill-switch
  controls (§10.2, rule 3). No other slice in this table depends on
  currently in-flight, unmerged work, so no other slice carries this
  requirement.
- **Product domains that do not exist and are not path-listed as
  approved architecture:** Calendar/Booking (Slice 3), Business→client
  payments/invoicing (Slice 4), and review-ingestion (part of Slice 8's
  dependency set). This document explicitly does **not** design schema,
  controllers, or migrations for any of the three — each requires its own
  separately-authorized contract before any implementation path exists,
  and nothing in this table should be read as pre-approving their eventual
  architecture.
- **Source contracts requiring later amendments:** `B4-BUSINESS-
  AUTOMATIONS-CONTRACT.md` (Slice 7's multi-step architecture change, §13
  row F-1) and `CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-
  CONTRACT.md` (§13 rows F-2/F-3/F-4) — none of these amendments is made by
  this document itself; each is recorded in §13 as a future, separately
  authorized correction.

---

## 12. EXACT TEST MATRIX

Test IDs use fresh prefixes to avoid colliding with the CX contract's own
`T-AUTO-*`/`T-EVENT-*`/`T-SENDER-*`/`T-STOP-*` IDs; where this document's
tests extend an existing CX-contract assertion, the CX ID is reused and
numbered onward (e.g. `T-EVENT-5` continues CX's `T-EVENT-1..4`).

**Correction Round 1 — ownership rule.** Every test ID below has **exactly
one** owning slice: the slice whose implementation first makes the assertion
provable. A test that exercises behavior spanning two slices belongs to the
**later** slice (the one that completes the behavior the test actually
proves), and the earlier slice's own contribution is covered instead by that
slice's own narrower test(s). `T-REGRESSION-1` is intentionally listed
separately, below the table, as a **regression command** run at the end of
every slice — it is not a jointly-owned test ID, it is the same fixed
existing suite re-run each time, exactly as the B4 contract's own §20 "run,
not re-authored" rows already establish the convention for. The seven rows
the original matrix listed against two slices are corrected below, each
resolved to its single, later-completing owner; every other row is
unchanged.

| ID | Assertion | Owning slice |
|---|---|---|
| T-OUTBOX-1 | Every implemented producer dispatches from a real, traced state-transition call site — never an enum case or comment alone | 1 |
| T-OUTBOX-2 | A stage-1 outbox event row is written in the same transaction as its state change; a rolled-back transaction leaves no row (§5.1) | 1 |
| T-EVENT-5 | A redelivered stage-2 outbox delivery (simulated at-least-once redelivery of the same stage-1 event) produces exactly one downstream effect | 1 |
| T-OUTBOX-3 | A duplicate provider webhook (same `provider_event_id`) produces zero additional stage-1 event rows, following the Stripe UNIQUE-constraint pattern | 1 |
| T-OUTBOX-4 | A stage-2 delivery retry never mutates the stage-1 event row's payload or facts (proves the immutable/mutable separation, §5.1) | 1 |
| T-TENANCY-1 | A stage-1 event carrying no `business_id`, or a `business_id` foreign to the resolving context, is rejected | 1 |
| **T-TENANCY-2** | An inactive Business's due producers/sweeps execute nothing | **2** *(corrected from 1, 2 — Slice 1 ships no producer of its own to exercise this against; Slice 2's inbound-message producer is the first real producer this assertion can run against)* |
| **T-STOP-3** | A STOP recorded after an execution is claimed, but before the action, still blocks the send (extends CX `T-STOP-1`) | **5** *(corrected from 2, 5 — Slice 2's producer has no send action of its own to block; the assertion is only provable once Slice 5 wires a real send path the new trigger family can reach)* |
| T-RESCHED-1 | An appointment rescheduled/cancelled between claim and action produces `skipped`, never a stale reminder (once Slice 3 exists) | 3 |
| T-PAYDUP-1 | A duplicate or refunded payment event produces exactly one automation effect, never two (once Slice 4 exists) | 4 |
| **T-LOOP-1** | An inbound-message automation never treats its own immediately-preceding automation-originated outbound send as a fresh trigger | **6** *(corrected from 2, 6 — the loop can only be exercised once a recipe (Slice 6) actually connects the Slice 2 producer to a send action)* |
| **T-LOOP-2** | A causation chain exceeding the configured maximum depth is refused with a distinct `skipped` reason, and the depth check itself is bounded | **1** *(corrected from 1, 6 — the causation-chain primitive is entirely Slice 1's own outbox schema (§5.1); it is fully provable with synthetic chained events and does not require a real recipe to exist)* |
| **T-QUIET-1** | No automation sends outside the Business's configured quiet-hours window, across a DST transition | **6** *(corrected from 6, 7 — quiet hours gate every message-sending recipe (§9), which is Slice 6's own scope; Slice 7's wait/delay primitive is unrelated to quiet-hours enforcement)* |
| T-COST-11 | An automation send reserves wallet balance before the provider call, and only after the call, in a separate step, never inside one transaction | 5 |
| T-COST-12 | Reservation is released, not committed, when the send is `skipped` or `failed` before the provider call | 5 |
| T-COST-13 | Insufficient funds fails the send closed, alerts the payer, and queues no retry | 5 |
| **T-COST-14** | A recipe requiring an action whose default identity is unresolved fails closed with zero provider calls | **5** *(corrected from 5, 6 — identity resolution is entirely Slice 5's own scope; Slice 6 only decides whether a recipe is shown, it does not own the resolution failure mode itself)* |
| **T-KILLSWITCH-1** | Activating the Business-scoped or Workspace/Account-scoped emergency control (owned by the merged CX Slice 5 work, reused per §10.2) stops new automation-originated provider work for that Business/Workspace | **5** *(corrected: this document does not own or introduce a kill switch — it owns only the assertion that automation sends honor whichever emergency control Slice 5 ships; the original "platform-wide" framing is withdrawn, §6.2)* |
| T-KILLSWITCH-2 | A Business-scoped pause stops only that Business's automation work, never another Business's | 5 |
| T-WORKSPACECAP-1 | The Workspace aggregate automation-spend cap admits the exact boundary and refuses one unit above it (once the cap exists, CX Slice 5) | 5 |
| T-PAYER-5 | An Agency-paid Business's automation sends attribute cost to the correct payer, never the Business itself | 5 |
| T-BYO-5 | An automation send over a BYO-configured channel never reserves or debits the wallet | 5 |
| **T-SENDER-3** | A recipe created before any default identity exists remains a valid draft and becomes sendable once one exists (extends CX `T-SENDER-1`) | **5** *(corrected from 5, 6 — identity resolution and its draft/sendable state transition are Slice 5's own scope; the recipe UI in Slice 6 only reads that state, it does not own the transition)* |
| T-RECIPE-1 | A recipe whose trigger or action is not yet backed is absent from the catalogue and cannot be created by any path, including the API | 6 |
| T-RECIPE-2 | Every visible recipe's activation checklist is fully evaluated before activation is permitted | 6 |
| T-RECIPE-3 | A test send is metered/billed/ledger-labelled exactly like a real send (extends CX `S-11`) | 6 |
| T-MULTISTEP-1 | A paused (waiting) execution cannot be claimed a second time by a concurrent worker (extends the `AutomationsClaimConcurrencyTest` pattern to a resumable step) | 7 |
| T-MULTISTEP-2 | A wait/delay step's resume is idempotency-keyed identically to the existing single-step claim, with no new retry semantic introduced | 7 |
| T-ACCESS-1 | Every new customer-facing recipe/catalogue surface passes the CX contract's own `T-A11Y-1..3` and `T-I18N-1` regression | 10 |

**Regression command (not a jointly-owned test ID):**

| Command | Assertion | Run at the end of |
|---|---|---|
| T-REGRESSION-1 | The full existing `tests/Feature/Automations/**` suite (§2.2) passes unchanged | every slice |

**Every test ID above now maps to exactly one owning slice.** Mechanically
recounted after this correction: **29 distinct test IDs** (28 from the
original matrix plus the new `T-OUTBOX-4` added to prove the immutable/
mutable separation §5 now requires), each with a single owning slice, plus
one regression command (`T-REGRESSION-1`) that is explicitly not a
slice-owned test ID.

---

## 13. RECORDED FUTURE AMENDMENTS TO OTHER CONTRACTS

Per the task's instruction not to edit existing contracts in this pass, every
correction this audit surfaced beyond what the CX contract's own §27 already
records is listed here instead:

| # | Document | Correction required | Blocking? |
|---|---|---|---|
| **F-1** | `docs/automation/B4-BUSINESS-AUTOMATIONS-CONTRACT.md` | §1's locked "single-step automations" scope must be explicitly amended, not silently extended, before any wait/delay or conditional-branch primitive (this document's §11 Slice 7) is implemented — the amendment must re-derive B4's own at-most-once/claim/start-claim guarantees for a resumable execution state, not assume they still hold | Yes — blocks Slice 7 |
| **F-2** | `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md` | §21 Slice 6's entry/exit criteria (§21.1 row "6") do not currently mention wiring automation sends to `UsageWalletManager::reserve()`/`commit()`/`release()` — this document's §10 finding (automation sends bypass the wallet entirely today) should be folded into Slice 6's own exit criteria rather than left implicit | No — but should be resolved before Slice 6 is declared complete |
| **F-3** | `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md` | §15.1's trigger matrix classifies only 18 triggers; this document's §3 covers 33 across six families using the same evidence-table discipline. A future revision of the CX contract's §15 could adopt this document's fuller table by reference rather than maintaining two divergent trigger inventories | No |
| **F-4** | `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md` | §26's exclusions list names Calendar/Booking/Forms/Quotes/Pipelines/Reviews/Tasks/Invoicing as future-only but does not assign any of them an owning future contract or slice number — this document's §11 (Slices 3, 4, 8) recommends they each become their own numbered, separately-authorized contract rather than remaining an undifferentiated exclusions list | No — owner-prioritization decision, §14 |

No already-merged migration, route, or product code is touched by any row
above — every row is a documentation recommendation for a future, separately
authorized pass.

---

## 14. OPEN DECISIONS FOR THE OWNER

None of the following block this document's own completion; each blocks a
specific future slice named in §11.

1. **Whether a platform-operator global shutdown control is wanted at
   all, beyond the Business/Workspace-scoped emergency controls the merged
   CX Slice 5 work already provides (§6.2, §10.2).** **Correction Round 1:
   this decision is narrowed from the original document's framing**, which
   incorrectly treated a new platform-global kill switch as a hard,
   self-evident prerequisite this document could mandate. It cannot: no
   contract read for this audit requires one, and inventing it would itself
   be an unauthorized new control. The Business/Workspace-scoped controls
   are the locked current scope and are not an open decision — reusing them
   is mandatory (§10.2). What remains genuinely open is only whether the
   owner separately wants a platform-operator-level shutdown on top of
   them, and if so, whether it also pauses in-flight `pending` executions
   or only refuses new claims — a product-risk tradeoff this document does
   not have standing to decide.
2. **Which of Calendar/Booking, Business→client payments, or Reviews should
   be contracted first** — this document's §7 shows all three gate the
   majority of the requested seventeen-recipe catalogue equally; the owner's
   commercial priorities (photobooth vs. roofing vs. general service
   business emphasis) should decide the order, not engineering convenience.
3. **Whether "Wallet balance low" and "Usage limit approached" should be
   pulled forward as class-C producers ahead of Slice 2's inbound-message
   work** (§3.6, §7.1's summary table), since both adapt the existing,
   mature `UsageWalletManager` domain rather than requiring a new domain
   from zero — a genuinely cheaper near-term option, **subject to §10.2's
   reuse rule: any such producer must call the merged, authoritative
   wallet/limit mechanism, never a parallel one.** This document does not
   reorder §11's slice table on its own authority, since the task's own
   recommended shape puts the inbound-message producer first; it is
   flagged here as a plausible cheaper-first alternative for the owner to
   weigh. **Correction Round 1: the equivalent "Customer marked won/lost"
   option is withdrawn** — §3.6a establishes that no CRM deal/pipeline
   substrate exists to adapt, so it is not a near-term option at all, only
   a `requires-another-feature-first` item like the rest of §3.5's missing
   CRM domain.
4. **Maximum causation-chain depth and per-contact cooldown duration**
   (§6.2) — recommended defaults are stated, but each is a product-risk
   tradeoff (a too-low cooldown annoys customers' own customers; a too-high
   one defeats the abuse protection) that this document does not have
   standing to lock unilaterally. **Correction Round 1: the original
   third item in this decision, "per-Business hourly execution budget," is
   narrowed** — §6.2 now states that a new execution-rate throttle is only
   a candidate at all after existing mechanisms (the claim service, wallet
   limits, cooldowns) are shown insufficient for a purely infrastructural
   concern; whether that review ever justifies a new throttle, and if so
   its exact rate, remains open, but it is no longer framed as a limit
   this document simply needs a number for.
5. **Outbox event retention window** (§5.1's `retention_days`) — a
   compliance/storage-cost tradeoff, not stated here.

---

## 15. VALIDATION PERFORMED BEFORE COMMIT

- Every cited repository path and symbol in this document was either read
  directly (B4 contract, both automation enums, the trigger evaluator, the
  execution claim service, the CX contract's full §1–§4, §10.4, §14–§21,
  §24 excerpt, §26–§29, the Telnyx decision document in full, the GBP
  contract's §24 excerpt, `DLRController.php` STOP-handling excerpt,
  `Contacts.php` status constants) or reported back by a background research
  pass with an explicit file:line citation this document quotes verbatim.
- Every claimed dispatch site (`AutomationJob`, `SendAutomationMessage`,
  `RunAutomation`, the Stripe webhook pipeline, `DLRController`'s STOP path,
  `EloquentCampaignRepository::quickSend()`'s blacklist check) was traced to
  an actual method call, not inferred from a class name.
- Every current trigger/action was verified against its enum definition,
  its evaluator/dispatcher code, and its test suite's method names — no
  enum case or comment was treated as an implemented producer anywhere in
  this document (§2.1).
- Internal cross-references (§ numbers, table rows) were checked for
  consistency against the final structure of this file.
- Every test ID in §12 maps to exactly one owning slice, with the single
  documented exception of the cross-slice regression guard, itself labelled
  as such rather than left ambiguous.

### 15.1 Correction Round 1 — validation performed before this commit

1. **Occurrence sweep.** Every occurrence of `Opportunity`, `won`, `lost`,
   `kill switch`/`kill-switch`, `budget`/`Budget`, `outbox`, `dead_letter`,
   "zero recipes", "can ship", and `Recipe` was located (`grep -n`) and
   individually re-read in context; every one either already reflected the
   correction or was rewritten in this pass.
2. **Subject verification.** `Opportunity`/`OpportunityManager`/
   `OpportunityStatus`/`OpportunityTypeRegistry` were re-read directly
   (`app/Models/Opportunity.php`, `app/Enums/Opportunity/OpportunityStatus.php`,
   `app/Library/Opportunity/OpportunityTypeRegistry.php`) and confirmed to
   contain no `Won`/`Lost` case and no sales-pipeline field shape (§3.6a);
   a targeted search for `Deal`/`Pipeline`/`Stage` models and for `won`/
   `lost` across `app/Models` confirmed no CRM sales-pipeline entity exists.
3. **Recipe completeness.** All 17 recipes in §7.2 were checked against the
   task's 13 required fields; each carries plain-language title,
   description, trigger, action(s), upstream dependencies, customer-
   supplied information, safe defaults, editable fields, estimated-cost
   behavior, consent/STOP implications, activation checklist, blocked/
   empty state, and explicit technically-runnable/product-shippable
   answers.
4. **Test-ID ownership.** Every row in §12's matrix was re-counted; the
   seven originally dual-owned rows (`T-TENANCY-2`, `T-STOP-3`, `T-LOOP-1`,
   `T-LOOP-2`, `T-QUIET-1`, `T-COST-14`, `T-SENDER-3`) now carry exactly one
   slice each, each with an inline note explaining the resolution; the new
   `T-OUTBOX-4` was added for the immutable/mutable separation (§5.1) and
   also carries exactly one owner; `T-REGRESSION-1` is explicitly
   distinguished as a regression command, not a slice-owned test ID.
5. **No unmerged branch cited as merged fact.** §10.2 was written, and this
   sweep re-confirms, without inspecting, reading, or citing any content
   from the other lane's unmerged Slice 5 branch — every statement about it
   is limited to "unmerged, not inspected, must be reused once merged."
6. **No duplicate control authorized.** §6.2 and §10.2 were re-read
   together to confirm neither authorizes a new kill-switch table, a new
   spending/limit mechanism, or a new low-balance/spending-alert command —
   each explicitly requires reuse of the authoritative Slice 5 mechanism
   once merged, and §6.2's own point 1 requires proving existing mechanisms
   insufficient before even the narrow, non-financial execution-rate
   throttle is built.
7. **Repository paths and symbols re-verified.** The new citations this
   correction round introduces (`OpportunityStatus`'s six cases,
   `OpportunityTypeRegistry::DEFINITIONS`'s eleven profile-completeness
   types) were read directly from source, not carried over from memory of
   the prior pass.
8. **Internal references checked.** Every new `§` cross-reference this
   round introduces (§3.6a, §3.7, §7.0, §7.1, §7.2, §10.2, §10.3, §11.1)
   was checked against the file's own final heading structure.
9. **`git diff --check`** was run against the staged change: clean (exit
   0; the only warning was a benign LF→CRLF line-ending notice, not an
   error).
10. **Secret-shaped-string sweep** (API-key/secret/PEM-header/Stripe-key/
    AWS-key/Slack-token patterns) was run against the file: zero matches.
11. **Exactly one documentation file changed** — `git status --short`
    before commit shows a single modified path:
    `docs/automation/AUTOMATION-TRIGGER-EVENT-AND-GUIDED-RECIPE-EXPANSION-CONTRACT.md`.
12. **No source code, migration, configuration, dependency, or generated
    asset changed** — confirmed by the same `git status` output; this
    correction round touched only the one documentation file above.
