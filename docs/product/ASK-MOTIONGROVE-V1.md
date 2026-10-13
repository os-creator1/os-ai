# Ask MotionGrove — V1

The conversational assistant on Business Home. One assistant, inside the Ask card directly beneath the greeting. No global
chat widget, no model call when Home loads.

## How a message is handled

1. **Replay.** The browser sends a `client_message_id`; the same id returns the stored reply (no second model call).
2. **Fast path.** A handful of exact, well-known phrases (the lookup/navigation chips) are answered from the module's own
   reader with no model call (`AssistantFastPath`).
3. **Allowance.** If the Workspace's included AI is not available the customer is told the true state
   (`AiAllowanceReader` → `AssistantAllowanceMessages`) — no model call, no invented summary.
4. **Interpreter.** `AssistantInterpreter` runs a bounded loop (`config/assistant.php`): the model replies with one JSON
   object — `call_tools`, `answer`, `ask_user`, `propose` or `unsupported`. Every call goes through `AiGateway`
   (category `ask_assistant`, route `assistant`, interactive lane); compaction of old turns is its own ledgered call
   (`conversation_compaction`).

## Authority

The model only *names* a tool. `AssistantToolRegistry` is closed; a tool is offered only if `HomeActionRouter` /
permissions / entitlements / Location access allow this actor, arguments are validated against the tool's own rules, and every
read goes through the module's reader. Business, Workspace and actor come from the authenticated route, never the model.
Facts shown to the customer are built from the tool results, not from model text.

### Read tools
`business_snapshot`, `unanswered_conversations`, `schedule`, `growth_recommendations`, `recent_work`, `seo_status`,
`website_and_listings`, `ads_status`, `automations_status`, `find_contact`, `open_page`.

### Action tools (prepare → preview → explicit approval → revalidate → execute → audit)
| Tool | Executes through | Notes |
|---|---|---|
| `create_booking_type` | `BookingTypeManager::create` | Location resolved through `CalendarLocationResolver` |
| `create_appointment` | `AppointmentBookingService::bookWithRoundRobin` | existing contact only; staff auto-assigned; engine decides the slot; preview names who is notified |
| `prepare_automation` | `WorkflowDraftService::createWorkflowWithDraft` | draft only — never published or switched on |
| `draft_reply` | the inbox's own `conversations.reply` | **hand-off**: approving pre-fills Follow-ups; the customer presses Send there |

`AssistantActionService` finds a proposal only by uid + Workspace + Business + approving user; revalidates availability and
a state fingerprint immediately before executing; claims `pending → executing` under a row lock (double click / retry /
concurrent request cannot repeat the effect); runs the domain write and the recording of its result in one transaction; and
appends an immutable `assistant_action_audits` row per step.

### Not supported in V1 (the assistant says so and offers the closest real page)
Publishing or editing website pages from chat; sending a message directly from chat; activating an automation or building its
steps; adding a contact (the Add contact page is opened instead — contact creation has no domain service, only group-scoped
controllers); payments, refunds, invoices, billing or plan changes; changing ads or budgets; any other Business or Workspace.
No billable external AI tool exists in V1, so none needs metering.

## AI allowances (internal provider cost, integer micro-USD)

| Account | Allowance | Period |
|---|---|---|
| Free trial | 3,000,000 | the whole trial, once, per Workspace (`period_key = trial`) |
| Core | 5,000,000 | calendar month |
| Growth | 10,000,000 | calendar month |
| Agency | 25,000,000 Workspace / 6,000,000 per Business | calendar month |

Interactive share is 8000 bps for trial/Core/Growth (Agency unchanged at 3000). Every category is hard-capped
(`AI_ENFORCE_BUDGETS_FOR_EXISTING_CATEGORIES` now defaults to true, and a trial policy never opens the observation bypass).
The trial is derived from the canonical lifecycle (`trial_ends_at` outstanding, no grace/lock); an ended or unconverted trial,
or a lock, gets no AI; grace after a failed *paid* renewal keeps the paid allowance. A provider-confirmed conversion
(`EntitlementManager::recoverAccess`) clears `trial_ends_at`, so the next call resolves the paid policy and opens a
calendar-month period; trial history is untouched. A paid plan *upgrade* inside an open month raises that period's cap
(explicit, upward-only, `AiUsageLedgerManager::adoptUpgradedCap`).
