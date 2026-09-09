# AUTOMATION TRIGGER, EVENT-PRODUCER AND GUIDED-RECIPE EXPANSION CONTRACT

## 0. STATUS AND AUTHORITY

**Status:** Audit and roadmap only. No product code, migration, route, configuration
or dependency change is authorized by this document. This is Lane F.

**Verified base:** `origin/main` at `6c820c801da08ecfd6165d1d3a52ae6336606f0c`
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
| Customer marked won/lost | data-exists-no-producer | `App\Models\Opportunity` and `OpportunityManager` exist and are Business-scoped with a real execution-ledger precedent (`opportunity_action_executions`, cited by B4 §4 as its own adapted precedent) — an "opportunity stage changed to won/lost" producer is architecturally the closest of any trigger in this document to being a straightforward adapter over an existing, mature, Business-scoped domain, but no such event currently fires (confirmed no `Opportunity*`-named class under `app/Events/`). This is the **best near-term candidate for a new class-C producer outside the messaging domain**, worth flagging to the product owner (§14). |
| Review request due | data-exists-no-producer | Would be a derived, time-based trigger off Appointment/Booking completion (§3.3) — not independently blocked once that domain exists, but has no substrate today. |
| Review received | requires-another-feature-first | No review ingestion exists at all; Google Business Profile Slice A is explicitly read-only and excludes reviews (CX §15.1, "Review received" row). |
| Usage limit approached | data-exists-no-producer | `UsageWalletManager::setSpendCap()`/`setFeatureLimit()`/`setSafetyLimit()` (`:1184/1225/1293`) are real, Business-scoped limit mechanisms with real enforcement, but no domain event fires when a limit is *approached* (as opposed to hit) — a genuine, buildable near-term producer. |
| Wallet balance low | data-exists-no-producer | Same wallet subsystem; `BusinessUsageWallet.available_balance_micro` is a real, queryable column, but no "balance crossed a low-water-mark" event exists. Straightforward to add as a scheduled sweep, following the exact `automation:run` five-minute-sweep pattern already proven safe by B4. |
| Phone/compliance setup requires action | requires-another-feature-first | Depends entirely on `BusinessMessagingIdentity` and the onboarding/compliance state machine from the Telnyx decision document (§8's `status` column) — none of which exists yet (§2.4). Trivial to add once that foundation lands; blocked until then. |

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
| Create/update opportunity | data-exists-no-producer | `OpportunityManager` is Business-scoped and mature (B4 cites its claim mechanics as a design precedent), but no `AutomationActionType` case targets it today. |
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

### 5.1 Event envelope — provider-neutral, Business-scoped

No generic outbox/domain-event table exists anywhere in the repository today
— confirmed twice independently: a repo-wide case-insensitive search for
`outbox|domain_event|event_log` matches only two documentation files (this
one's sibling contracts), no migration or model. The closest existing
conventions are (a) the after-commit-dispatch, `ShouldDispatchAfterCommit`
pattern, used by 72 of the repository's 74 event classes (every file under
`app/Events/Business`, `Entitlement`, `GoogleBusinessProfile`,
`Opportunity` — 16 files, `Usage` — 17 files, `Website`, `Workspace`), and
(b) `opportunity_action_executions`
(`database/migrations/2026_07_19_120004_create_opportunity_action_executions_table.php:10-33`)
— genuinely execution-ledger-shaped (unique `idempotency_key`, an
`attempt_number` counter, `status` lifecycle, `started_at`/`completed_at`),
already cited by the B4 contract itself as its own adapted, not copied,
precedent (B4 §4). Neither is an outbox in the transactional-envelope sense
this section requires — this document's §5.1 table is genuinely new schema,
not a reuse of either. The CX contract's own §16 requirements are adopted as
binding and now expressed as a concrete column list:

| Field | Type | Notes |
|---|---|---|
| `id` | `uuid`, unique | Public event identifier. |
| `event_type` | `string`, versioned (`domain.subject.verb.v1`) | Never a raw class name in the payload — a stable string contract, matching the enum-backed-identity discipline B4 already locks for trigger/action types. |
| `business_id` | `foreignId` → `businesses`, `restrictOnDelete()` | Every event carries exactly one; a consumer must never infer tenancy any other way (CX §16 rule 5, restated here as schema). |
| `workspace_id` | **not stored** | Following B4 §3.3's own locked precedent exactly: Workspace is always reached as `$business->workspace_id`, never duplicated as an independently-driftable column. |
| `subject_type` / `subject_id` | `string` / `unsignedBigInteger` | The domain entity the event is about (e.g. `Contacts`/`{id}`, future `Appointment`/`{id}`). |
| `occurred_at` | `timestamp` | The moment the underlying state change committed — never the moment the outbox row is read. |
| `producer` | `string` | The exact class/method that wrote the row, for operator diagnostics — never used for authorization. |
| `correlation_id` | `uuid`, nullable | Groups events from one originating action (e.g. one inbound webhook delivery). |
| `causation_id` | `uuid`, nullable, FK to another event's `id` | The event that directly caused this one — the primitive §6's causation-chain depth limit is built on. |
| `idempotency_key` | `string(191)`, UNIQUE | Deterministic, reproducible from `(event_type, subject_id, occurred_at-bucket)` or an equivalent stable formula per producer — following B4 §5's exact pattern, never a random UUID reused as the dedup key. |
| `payload` | `json`, bounded | No provider payloads, no credentials, no message bodies beyond what a trigger needs, no personal data beyond the contact reference — CX §16 rule 4, unchanged. |
| `sensitivity` | `string`, closed enum (`internal`, `pii-minimal`) | Governs retention and who may read raw payloads via an operator diagnostics view (§6). |
| `retention_days` | `unsignedSmallInteger` | Time-bounded; this document does not set the number (owner decision, §14) but locks that one must exist rather than retaining forever. |
| `delivery_state` | `string`, closed enum (`pending`, `delivered`, `dead_letter`) | Drives the dispatcher sweep and the dead-letter view (§6). |
| `attempts` | `unsignedSmallInteger` | Bounded, following the `AutomationExecution` precedent of never encoding an automatic-retry semantic beyond what is explicitly authorized. |

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

### 6.2 Bounded limits — locked defaults (subject to owner adjustment, §14)

- **Maximum causation-chain depth: 3.** Beyond this, `skipped` with reason
  `causation_depth_exceeded`.
- **Per-contact, per-automation cooldown:** recommended default 5 minutes for
  message-sending actions, configurable per automation only within a
  platform-enforced floor (never zero, never customer-disableable below the
  floor).
- **Per-Business concurrency:** the proven precedent is
  `GoogleBusinessProfileCallBudget::reserve()`
  (`docs/automation/GOOGLE-BUSINESS-PROFILE-CONTRACT.md` §24.3/§24.9) — a
  short-transaction row-locked counter, checked before every outbound
  provider call, with the network call itself outside the transaction. This
  document requires the same pattern for a per-Business automation-execution
  budget per rolling hour, not a new invention.
- **Monthly limits:** enforced by the existing, real
  `UsageWalletManager::setFeatureLimit()`/`setSpendCap()`
  (`UsageWalletManager.php:1184/1225`) once automation sends are wired
  through wallet reservation at all (§10 — currently they are not, §2.3).
- **Kill-switch:** no platform-wide or Business-scoped emergency kill switch
  exists anywhere in the repository today (confirmed by the CX contract's
  own E-20 finding, "No `kill_switch` / `emergency` control exists" — this
  document independently confirms the same negative). This is a **hard
  prerequisite**, not an enhancement: no new trigger family in §11 should be
  activated in production before a Business-scoped and platform-wide kill
  switch exists, because every mitigation above assumes one is available as
  the last line of defense.
- **Dead-letter handling:** the event envelope's `delivery_state =
  dead_letter` (§5.1) plus a bounded `attempts` counter; an operator-visible
  list view is required before any class-C producer ships (§11 Slice 1),
  not deferred to later.
- **Operator diagnostics:** every outbox row and every `AutomationExecution`
  row remains queryable by an operator for a bounded retention window
  (§5.1's `retention_days`), with the same safe-summary-only redaction
  discipline B4 §11 already locks for execution history.

---

## 7. GUIDED RECIPE CATALOGUE

The CX contract's own §14.2 already locks a smaller starter catalogue (11
rows) gated behind its Slice 7. This section is the deeper, local-business-
oriented expansion the task requires (photobooths, roofing companies, and
similar service businesses), cross-referenced against that table rather than
duplicating it. **No recipe below may ship until its trigger and action are
both existing-and-usable** (§3/§4) — this is restated per recipe as "Can ship
now?".

| # | Recipe | Trigger | Action(s) | Upstream features required | Can ship now? |
|---|---|---|---|---|---|
| 1 | New lead instant reply | Contact created | Send SMS | None — both exist today | **No** — action needs default-identity resolution first (CX Slice 6); mechanically ready the moment that lands |
| 2 | Missed-call text back | Missed call | Send SMS | Voice-event ingestion (§3.2, does not exist) | No |
| 3 | Appointment confirmation | Appointment booked | Send SMS | Calendar/Booking domain (§3.3, does not exist) | No |
| 4 | Appointment reminder | Appointment approaching | Send SMS | Same | No |
| 5 | Appointment rescheduled | Appointment rescheduled | Send SMS | Same | No |
| 6 | Appointment cancelled | Appointment cancelled | Send SMS | Same | No |
| 7 | No-show follow-up | No-show recorded | Send SMS | Same | No |
| 8 | Payment received thank-you | Payment received (client→Business) | Send SMS | Business→client payments domain (§3.4, does not exist) | No |
| 9 | Payment failed reminder | Payment failed (client→Business) | Send SMS | Same | No |
| 10 | Deposit received confirmation | Deposit received | Send SMS | Same | No |
| 11 | Remaining balance reminder | Remaining balance due | Send SMS | Same | No |
| 12 | Inbound-message owner notification | Inbound message received | Notify owner/staff | New Business-scoped message producer (§3.2, §11 Slice 2) **and** a notify-staff action (§4, does not exist) | No |
| 13 | Lead has not replied | No response after configured period | Send SMS | Requires a Business-scoped Conversation model to compute "no response" against (§3.1/§3.2) | No |
| 14 | Post-service review request | Appointment/service completed | Request review | Calendar/Booking **and** review-ingestion domains (§3.3, §3.6, neither exists) | No |
| 15 | Quote request follow-up | Quote accepted / not accepted | Send SMS | Quote/Pipeline domain (does not exist, CX E-32) | No |
| 16 | Customer reactivation | No response after configured period | Send SMS | Same dependency as #13 | No |
| 17 | Wallet/compliance action required | Phone/compliance setup requires action | Notify owner/staff | `BusinessMessagingIdentity` + compliance state machine (§2.4, does not exist) **and** notify-staff action | No |

**Mechanical conclusion: zero of the seventeen recipes above can ship today
without at least one upstream feature landing first.** The two currently-
runnable trigger/action pairs (`contact_created` + `send_message`,
`contact_date_reached` + `send_message`/`update_contact_field`) do not, by
themselves, cover any of the seventeen recipes the task names, because every
one of them either needs the default-identity correction (recipe 1) or a
domain that does not exist yet (2–17). This is the central, load-bearing
finding of this audit and directly drives the delivery-plan ordering in §11.

### 7.1 Required per-recipe fields (template, applied to recipe #1 as the
worked example — the only one close enough to ship to be worth fully
specifying now; the remaining sixteen are specified at the trigger/action
level in §3/§4 and awarded full per-field detail only once their upstream
feature is authorized, so this document does not invent UI copy for
features that do not yet exist)

**Recipe 1 — New lead instant reply**

- **Plain-language title:** "Reply to a new lead."
- **Description:** "The moment someone becomes a new contact for your
  business, send them an instant text so they know you got their
  information." Concrete example message: *"Hi {first_name}, thanks for
  reaching out to {business_name}! We'll be in touch shortly."*
- **Required trigger:** Contact created (`contact_created`, existing).
- **Required action:** Send SMS through the Business's default identity
  (`send_message`, existing mechanism, pending CX Slice 6's identity-
  resolution correction).
- **Required upstream features:** CX Slice 6 (default messaging identity
  resolution) — nothing else.
- **Information the customer must supply:** nothing required to activate;
  message text is editable.
- **Safe defaults:** the example message above; no audience restriction
  (any group); send immediately (no delay).
- **Editable fields:** message text, audience (contact group), whether to
  restrict to a specific creation source once one exists (§3.1).
- **Estimated cost display:** one SMS segment per new contact, shown as "≈
  {N} sends/month based on your recent contact volume" per CX §14.3's
  locked cost-estimate requirement.
- **STOP/consent implications:** a brand-new contact has not yet had the
  chance to opt out; the recipe's own first message doubles as the
  practical first consent-relevant touch, so its copy must include a clear
  opt-out instruction per the Telnyx decision document's 10DLC sample-
  message requirement (§11 of that document: "at least two sample messages
  per campaign, each including... explicit opt-out instructions").
- **Activation checklist:** default messaging identity exists (CX §14.3);
  usage balance sufficient for a realistic month's volume; consent copy
  present in the message.
- **Empty/blocked state:** if no default identity exists yet, the recipe is
  simply **not shown** in the catalogue (§4's "never shown disabled" rule),
  not shown-and-blocked.
- **Can ship now?** No — blocked on CX Slice 6 only; the trigger and the
  underlying action mechanism are both already real.

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

**This is the audit's second most important finding, after §7's "zero
recipes ship today": automation sends currently bypass the wallet system
entirely.**

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
| **1** | Event-envelope and outbox foundation | None | New: `app/Library/Events/**`, `app/Models/OutboxEvent.php`, one migration creating the outbox table (§5.1's column list) | Additive only, one new table | No producer yet — this slice only builds the durable envelope + a generic dispatcher sweep + the dead-letter view | Envelope round-trips; dedup on `idempotency_key`; dead-letter view renders | Supersedes nothing; this is the missing infrastructure both this document's §5/§6 and the CX contract's §16 already require but neither has built | Must not ship without the kill switch (§6.2) already existing, or without the operator dead-letter view | No — every later class-C producer depends on this |
| **2** | Inbound-message producer | 1 | New: `app/Events/Conversation/MessageReceived.php` (Business-scoped, distinct from the legacy `App\Events\MessageReceived`), a new `business_id`-carrying column or table for the inbound-message subject, dispatch site alongside `DLRController.php:629` (adds to, does not replace, the existing websocket broadcast) | Additive migration for Business-scoped conversation subject reference | Transactional, after-commit; never replaces `DLRController`'s existing chat-UI broadcast | Duplicate webhook delivery produces one outbox row; cross-Business payload rejected; self-reply loop guard (§6.1) proven | This is the CX contract's own explicitly-named first class-C producer (§15.1, §21 Slice 8) — this slice **is** that work, scoped precisely | Must not ship until `Blacklists`/STOP re-check (§5.2 rule 8) is proven against a redelivered event | No |
| **3** | Appointment producers | 1, **and its own separate product contract for the Calendar/Booking domain itself** | Out of scope for this document to path-list — no Appointment model exists (§3.3) | Net-new domain, not additive to anything existing | N/A until the Booking feature itself is contracted and built | N/A | This document does not authorize or path-list Calendar/Booking — CX §26 already excludes it explicitly | Cannot start until a Booking/Calendar contract exists and is separately authorized | No — hard-blocked |
| **4** | Payment/invoice producers | 1, **and its own separate product contract for Business→client invoicing** | Out of scope — no such domain exists (§3.4) | Net-new domain | N/A | N/A | CX §26 already excludes "customer-issued Invoicing" | Cannot start until that domain is separately contracted | No — hard-blocked |
| **5** | Safe default-sender action integration | CX Slices 3, 4, 5 (unchanged — this document does not shorten that chain, §2.4) | This **is** CX Slice 6 (`SendMessageAction` resolves `BusinessMessagingIdentity` at execution, form selectors removed) | None new beyond what CX Slice 6 already specifies | Wallet reservation wired in per §10.1 — this document's one addition beyond CX Slice 6's own scope, since CX Slice 6 does not itself mention the wallet gap | `T-SENDER-1`/`T-SENDER-2` (already specified by the CX contract) plus this document's own `T-COST-11` (wallet reserved before every automation send, §12) | Directly implements CX §21 Slice 6 and closes CX §27 row C-4 | Cannot start before CX Slices 3–5 land | No |
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

---

## 12. EXACT TEST MATRIX

Test IDs use fresh prefixes to avoid colliding with the CX contract's own
`T-AUTO-*`/`T-EVENT-*`/`T-SENDER-*`/`T-STOP-*` IDs; where this document's
tests extend an existing CX-contract assertion, the CX ID is reused and
numbered onward (e.g. `T-EVENT-5` continues CX's `T-EVENT-1..4`).

| ID | Assertion | Owning slice |
|---|---|---|
| T-OUTBOX-1 | Every implemented producer dispatches from a real, traced state-transition call site — never an enum case or comment alone | 1 |
| T-OUTBOX-2 | An outbox row is written in the same transaction as its state change; a rolled-back transaction leaves no row | 1 |
| T-EVENT-5 | A redelivered outbox event (simulated at-least-once redelivery) produces exactly one downstream effect | 1 |
| T-OUTBOX-3 | A duplicate provider webhook (same `provider_event_id`) produces zero additional outbox rows, following the Stripe UNIQUE-constraint pattern | 1 |
| T-TENANCY-1 | An outbox event carrying no `business_id`, or a `business_id` foreign to the resolving context, is rejected | 1 |
| T-TENANCY-2 | An inactive Business's due producers/sweeps execute nothing | 1, 2 |
| T-STOP-3 | A STOP recorded after an execution is claimed, but before the action, still blocks the send (extends CX `T-STOP-1`) | 2, 5 |
| T-RESCHED-1 | An appointment rescheduled/cancelled between claim and action produces `skipped`, never a stale reminder (once Slice 3 exists) | 3 |
| T-PAYDUP-1 | A duplicate or refunded payment event produces exactly one automation effect, never two (once Slice 4 exists) | 4 |
| T-LOOP-1 | An inbound-message automation never treats its own immediately-preceding automation-originated outbound send as a fresh trigger | 2, 6 |
| T-LOOP-2 | A causation chain exceeding the configured maximum depth is refused with a distinct `skipped` reason, and the depth check itself is bounded | 1, 6 |
| T-QUIET-1 | No automation sends outside the Business's configured quiet-hours window, across a DST transition | 6, 7 |
| T-COST-11 | An automation send reserves wallet balance before the provider call, and only after the call, in a separate step, never inside one transaction | 5 |
| T-COST-12 | Reservation is released, not committed, when the send is `skipped` or `failed` before the provider call | 5 |
| T-COST-13 | Insufficient funds fails the send closed, alerts the payer, and queues no retry | 5 |
| T-COST-14 | A recipe requiring an action whose default identity is unresolved fails closed with zero provider calls | 5, 6 |
| T-KILLSWITCH-1 | Activating the kill switch stops all new automation-originated provider work immediately, platform-wide | 1 |
| T-KILLSWITCH-2 | Activating a Business-scoped pause stops only that Business's automation work | 1 |
| T-WORKSPACECAP-1 | The Workspace aggregate automation-spend cap admits the exact boundary and refuses one unit above it (once the cap exists, CX Slice 5) | 5 |
| T-PAYER-5 | An Agency-paid Business's automation sends attribute cost to the correct payer, never the Business itself | 5 |
| T-BYO-5 | An automation send over a BYO-configured channel never reserves or debits the wallet | 5 |
| T-SENDER-3 | A recipe created before any default identity exists remains a valid draft and becomes sendable once one exists (extends CX `T-SENDER-1`) | 5, 6 |
| T-RECIPE-1 | A recipe whose trigger or action is not yet backed is absent from the catalogue and cannot be created by any path, including the API | 6 |
| T-RECIPE-2 | Every visible recipe's activation checklist is fully evaluated before activation is permitted | 6 |
| T-RECIPE-3 | A test send is metered/billed/ledger-labelled exactly like a real send (extends CX `S-11`) | 6 |
| T-MULTISTEP-1 | A paused (waiting) execution cannot be claimed a second time by a concurrent worker (extends the `AutomationsClaimConcurrencyTest` pattern to a resumable step) | 7 |
| T-MULTISTEP-2 | A wait/delay step's resume is idempotency-keyed identically to the existing single-step claim, with no new retry semantic introduced | 7 |
| T-ACCESS-1 | Every new customer-facing recipe/catalogue surface passes the CX contract's own `T-A11Y-1..3` and `T-I18N-1` regression | 10 |
| T-REGRESSION-1 | The full existing `tests/Feature/Automations/**` suite (§2.2) passes unchanged after every slice — a regression guard, not a new behavior | every slice |

**Every test above maps to exactly one owning slice**, except
`T-REGRESSION-1`, which is explicitly the cross-slice regression guard and is
run, not re-authored, at the end of every slice — mirroring the exact
convention the B4 contract's own §20 test contract already uses for its "run,
not re-authored" regression rows.

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

1. **Kill-switch design** (platform-wide and Business-scoped) — a hard
   prerequisite for Slice 1, and for every later slice's safety net. No
   engineering default is recommended here because the correct behavior
   (does it also pause in-flight `pending` executions, or only refuse new
   claims?) is a product-risk tradeoff, not a technical one.
2. **Which of Calendar/Booking, Business→client payments, or Reviews should
   be contracted first** — this document's §7 shows all three gate the
   majority of the requested seventeen-recipe catalogue equally; the owner's
   commercial priorities (photobooth vs. roofing vs. general service
   business emphasis) should decide the order, not engineering convenience.
3. **Whether "Customer marked won/lost" (Opportunity-stage-change) and
   "Wallet balance low" should be pulled forward as class-C producers ahead
   of Slice 2's inbound-message work**, since both (§3.6) are architecturally
   simpler — they adapt existing, mature, Business-scoped domains
   (`OpportunityManager`, `UsageWalletManager`) rather than requiring a new
   domain from zero. This document does not reorder §11's slice table on its
   own authority, since the task's own recommended shape puts the inbound-
   message producer first; it is flagged here as a plausible cheaper-first
   alternative for the owner to weigh.
4. **Maximum causation-chain depth, per-contact cooldown duration, and
   per-Business hourly execution budget** (§6.2) — recommended defaults are
   stated, but each is a product-risk tradeoff (a too-low cooldown annoys
   customers' own customers; a too-high one defeats the abuse protection)
   that this document does not have standing to lock unilaterally.
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
