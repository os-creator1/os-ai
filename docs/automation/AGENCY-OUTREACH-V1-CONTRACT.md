# Agency Outreach V1 — Contract

Base: stacked on `29920dc2` (Custom Fields + canonical merge-field engine) on top of main `6ac3e19c`.
Branch: `agent/agency-outreach-v1`. No PR, no merge.

Agency Outreach is the Agency owner's OWN acquisition outreach: SMS prospecting of local
service businesses, driven by a fixed sales process whose COPY the Agency owns. It is
niche-neutral (HVAC, dental, roofing, gyms, salons, law, photographers, photo booths …).
Nothing in product code, defaults or templates names a niche, a price, a commission or a
company. Those live only in an Agency's saved settings.

## 1. Decision: evolve Agency Prospecting in place

Agency AI Prospecting (PRs #202/#203/#272) already owns Workspace-scoped prospects,
campaigns, members, messages and settings, the `ProspectOutreach` entitlement, the
`prospecting.*` routes and the nav entry. Outreach **extends it**; no parallel module.
The user-facing name becomes **Outreach**; route names and the entitlement key stay
(`customer.workspaces.prospecting.*`, `PlatformFeature::ProspectOutreach`) so nothing breaks.

What changes:

| Concern | Before (Prospecting) | Outreach V1 |
|---|---|---|
| Stage decision | LLM JSON + transition guard | Fixed deterministic machine (§4) |
| Reply text | LLM free text | Agency script + deterministic FAQ answers; AI only for an unclassified question (§5) |
| Sender | own BYO Twilio/Telnyx `SendingServer` channel | Canonical managed messaging of the Agency's OWN Business (§6) |
| Billing | none | Canonical per-segment wallet billing (§7) |
| Inbox | `agency_prospect_messages` only | Canonical Conversations (`chat_boxes`) **plus** the Outreach ledger (§8) |
| STOP | detector flag on the prospect | `Blacklists` row for the Agency Business (§9) |
| Tokens | `strtr` template | Canonical merge engine, `agency.*` + `prospect.*` groups (§3) |

The BYO-channel runtime (`AgencyProspectingChannel*`, provider webhooks, `AgencyProspectingRespondJob`, `AgencyProspectingFollowUpJob`,
`AgencyProspectingInitialSendJob`, `ProviderAgencyProspectingMessageSender`) is **left in place and byte-unchanged** (removal needs approval, and
`AgencyProspectingRuntimeTest` is pinned to main by a Slice 3 tripwire). It keeps serving campaigns with `sending_mode = 'channel'` (the column
default — every existing campaign). Outreach adds `agency_prospect_campaigns.sending_mode = 'managed'`: such a campaign uses the new deterministic
engine and canonical messaging through NEW classes (`OutreachRespondJob`, `OutreachFollowUpJob`, `OutreachInitialSendJob`) in `App\Library\AgencyOutreach`
and `App\Jobs\Outreach`. The Outreach UI only ever creates `managed` campaigns. A `managed` campaign never needs a channel.

## 2. Ownership and tenancy

- Owner of Outreach data = the Agency **Workspace** (`workspace_id`), as today.
- The **sending Business** is the Agency Workspace's own single Business, resolved by
  `AgencyOutreachBusinessResolver::forWorkspace(Workspace): ?Business` (exactly one Business of the Workspace,
  `BusinessStatus::Active`; otherwise `null` = a readiness blocker). Never a client Business, never a global lookup.
- The wallet, payer, sending number, A2P state and Conversations are that Business's, through the existing
  canonical surfaces. There is no outreach wallet, number or balance table.
- Inbound matching is `(business.workspace_id, canonical phone)`; a conversation of any other Business is ignored.
- Access = existing `ResolvesAgencyProspectingWorkspace` (owner or active Workspace Admin, entitlement, not Staff, View-As refuses mutations).

## 3. Script, settings and tokens

`agency_prospecting_settings` (one row per Workspace) gains (additive, nullable, guarded migration):

`website_url`, `message_1`, `message_2`, `message_3`, `pricing_answer`, `location_answer`, `found_you_answer`,
`what_we_do_answer`, `website_answer`, `clarify_answer`, `followup_enabled` (default true), `followup_message`,
`ai_enabled` (default true), `scheduling_mode` (`calendar_link` | `conversational_scheduling`, default `calendar_link`),
`script_version` (unsigned int, default 1, incremented on every script save).

Reused existing columns: `agency_name`, `booking_url` (= **calendar URL**), `follow_up_delay_hours` (default 24),
`offer` (= offer summary), `niche` (= target customer description), `qualification_context` (= qualification notes).

`OutreachScriptDefaults` supplies neutral, niche-free copy when a field is null (never persisted until saved):
message_1 introduces `{{agency.name}}` and a generic "we help local businesses get booked customers, you only pay when we deliver",
message_2 the short-call ask, message_3 `... book a quick call here {{agency.calendar_link}}`,
a follow-up with the calendar link, and neutral FAQ answers. No price, commission, platform, city or niche appears.

**Tokens use the canonical engine** (`App\Library\Merge\MergeFieldResolver`, grammar `{{group.key}}`, no aliases):

`{{agency.name}}` `{{agency.website}}` `{{agency.calendar_link}}` `{{prospect.first_name}}` `{{prospect.full_name}}` `{{prospect.company}}`

`MergeContext` gains optional `$prospect` and `$outreach`; the resolver proves both belong to the context
Business's Workspace and treats a mismatch as absent. `agency.calendar_link` / `agency.website` merge only a valid
http(s) URL. Unknown/empty tokens render blank, never as raw `{{…}}`. The product vocabulary the owner may type —
`{{agency_name}}`, `{{website}}`, `{{calendar_link}}` — is rewritten to the canonical tokens **when the script is
saved** (`OutreachScriptTokens::canonicalise`), so stored text and rendering are purely canonical. Message 3 (and the
follow-up when it links) must contain the calendar token; the editor warns and readiness fails otherwise.

## 4. Stage machine (fixed)

Stored values are the existing `AgencyProspectStage` integers; **no row is reinterpreted**.

| Value | Canonical | Meaning | On the prospect's next reply |
|---|---|---|---|
| 1 | INTRO | opener sent (or none), awaiting first reply | send message 1 → 2 |
| 2 | (message 1 sent) | pitch delivered | send message 2 (call ask) → 3 |
| 3 | CALL_ASK | call asked | send message 3 (calendar link) → 4, schedule the one follow-up |
| 4 | BOOKING | link sent | no scripted message; answer a FAQ only; a scheduling/time reply gets the calendar link again once |
| 5 | SCHEDULING | reserved for conversational scheduling (not used in V1) | — |
| 6 | BOOKED | booked | **terminal**, never replies |
| 99 | REJECTED / opted out | stopped | **terminal**, never replies |

Legacy mapping (the uploaded cron responder, `ai_stage` on `chat_boxes`): legacy 1→1, legacy 3 (message 1 sent)→2,
legacy 4 (message 2 sent)→3, legacy 5 (link sent)→4, legacy 6→6, legacy 99→99; legacy 2 was never produced and maps to 2.
The legacy columns/tables (`chat_boxes.ai_stage`, `ai_box_campaign_map`, `cg_ai_*`) are never read or written.

`OutreachStageMachine::decide(stage, classification): Decision` is pure and exhaustively tested.
Transitions apply only under a member row lock and compare-and-set on the stage the decision was made from.

## 5. Reply composition and FAQ

Order, as in the legacy script: **answer the prospect's question first, then the exact current-stage message.**

1. `OutreachIntentClassifier` (deterministic, no model) detects: pricing/cost/commission, location (incl. "are you based in X"),
   how-did-you-find-me, website, business name, what-do-you-do/how-does-it-work, specific-request misunderstanding
   (date/weekend/booking-type questions — the legacy "event" clarification, generic wording from `clarify_answer`), or none.
2. Known FAQ → the Agency's field (token-rendered), then the stage message. **No model call.**
   Name → "We're {{agency.name}}." (field-less).
3. A question the classifier cannot place and `ai_enabled` → ONE bounded `AiGateway` call (category `AgencyProspectReply`,
   routine route, last ≤ 8 messages, ≤ 80 output tokens, temperature low) that must return ONE short sentence answering
   using only the Agency facts supplied (agency name, offer, target customer, the five answers, calendar link). Facts missing →
   the sentence must be a deferral to the call. URLs in model text are stripped (`AgencyProspectUrlPolicy`).
4. The final text is `[answer sentence] + exact stage message`. If the model fails, refuses, times out, returns an empty/over-long
   sentence, or the final text does not contain the exact stage message → **the stage message alone**.
5. No question → the stage message alone (the legacy "Got it." prefix is dropped).
6. Duplicate guard preserved: a reply identical to the last outbound is not sent (the inbound is still marked handled).

Calendar-link mode only in V1 (§10).

## 6. Sending (canonical)

`OutreachMessageSender` (one interface, two drivers):

- **Canonical** (campaign without channel): `CampaignRepository::checkQuickSendValidation()` + `quickSend()` with `business_id` = the Agency's own Business,
  `managed_operation_key`, `require_managed = true`. That gives managed number resolution, the `Blacklists` check, conversation history,
  usage measurement and §7 billing. No second sender, no direct provider calls.
- There is **no** channel driver in the Outreach engine: BYO-channel campaigns do not go through it at all (§1).

Eligibility is re-checked at send time under the member lock: Workspace active, entitlement, campaign Active, prospect Active,
member non-terminal, AI not paused, Business resolvable, `Blacklists` clear. Anything else → no send, reason recorded.

## 7. Wallet / usage (canonical, platform-wide)

Managed SMS was zero-rated (a measurement only). V1 adds the missing canonical behaviour once, in the dispatcher:

`ManagedTransportBilling` (`app/Library/Messaging`) wraps `UsageWalletManager::reserve/commit/release` for the
`messaging_transport` meter, called by `ManagedMessageDispatcher::dispatch()`:
reserve before the provider call (idempotent on the operation key; a refusal throws `MessagingInsufficientFundsException`
with zero provider calls and no operation row), commit on provider acceptance, release on rejection/exception.
Payer, spend caps, debt, paused activity, safety limits, auto-recharge and the ledger are the existing `UsageWalletManager`
rules — the Agency Workspace's own Business wallet is the payer by default. **No price is chosen by this work:** while the
`messaging_transport` meter is unmetered or has no active rate the call is a no-op (the documented Slice 3 contract, T-MSG-36),
so every existing send is unchanged until the platform owner activates a rate with the existing rate tooling.
Conversations maps the refusal to "insufficient balance". Outreach treats it as **pause, not fail** (§11).

## 8. Conversations, ledger, audit

- Canonical inbox = Conversations. Managed inbound already writes `chat_boxes`/`chat_box_messages`; Outreach adds no second inbox.
- `agency_prospect_campaign_members.chat_box_id` (nullable FK, set on first matched inbound/outbound) links a member to its conversation.
- A tagged `ConversationContextSection` shows, for a prospect's conversation: prospect, company, campaign, stage, AI/Manual state,
  booked/rejected state, and **Pause AI / Resume AI**. The Outreach › Conversations tab lists prospect conversations with those filters
  and links into the canonical conversation.
- `agency_prospect_messages` stays the Outreach ledger and gains `source` (`deterministic|ai|manual|followup|opener`), `stage_from`,
  `stage_to`, `script_version`, `actor_user_id`, `failure_reason`. Existing `status` is the send result. No chain-of-thought is stored.
- `agency_prospects` gains `stop_reason` (`opt_out|rejected|manual`).

## 9. Inbound, opt-out, rejection

- A queued listener on `App\Events\Conversation\InboundMessageReceived` (registered in `EventServiceProvider` beside the existing
  listener) reads the latest incoming message of the matching conversation, matches the prospect (§2) and writes the inbound ledger row with
  `operation_key = outreach:in:{occurrenceKey}` (unique) — a redelivered event is a no-op. It links `chat_box_id`, sets `last_inbound_at`,
  cancels a pending follow-up (`followup_cancelled_at`), and dispatches `AgencyProspectingRespondJob`.
- `OutreachRespondJob` (new) is the responder; the old `AgencyProspectingRespondJob` is untouched and only ever dispatched by the BYO webhook for `channel` campaigns. Reply key `outreach:reply:{inboundMessageId}`. A prospect with both kinds of membership is answered by the managed engine only when the inbound arrived through canonical messaging.
- **Hard opt-out is platform-controlled and cannot be disabled.** `OutreachStopClassifier` (word-boundary, case-insensitive; "nonstop"/"unstoppable" do not match):
  - **opt_out** — `stop`, `stop all`, `unsubscribe`, `remove me`, `don't/do not text`, `leave me alone`, `wrong number`, `cancel`, `quit`, `end`:
    write a `Blacklists` row (`business_id` = the Agency Business, `number`, reason) idempotently, mark the prospect stopped (`stop_reason=opt_out`),
    member stage 99, cancel the follow-up, send nothing.
  - **rejected_hard** — `not interested`, `please stop` variants: stage 99, `stop_reason=rejected`, no `Blacklists` row (a polite no is not a carrier opt-out).
  - **rejected_soft** — the legacy soft list (`no thanks`, `nah`, `i'm good`, `not now`, `maybe later`, `already have`, …) ends the conversation **only once message 1 has been sent (stage ≥ 2)**, as in the legacy script.
  - Managed inbound does not process STOP anywhere on main (only Telnyx campaign keywords do); Outreach therefore does its own for prospect conversations. A global fix is out of scope and is listed as a remaining item.
- Prospects not in any campaign, or conversations of other Businesses, are never touched.

## 10. Booking

Calendar-link mode only. Message 3 ends with `{{agency.calendar_link}}`; the Agency pastes ANY external booking URL
(`booking_url`, http/https only). The settings page also offers a one-click "use my Booking Type page" that fills the URL from a
Booking Type of the Agency's own Business (`route('public.booking.show', $type->public_booking_uuid)`). No Calendly API, no credentials.
`scheduling_mode` stores `calendar_link`; `conversational_scheduling` is **not selectable in V1** and is documented as deferred:
main has no slot-availability service (the logic is private inside `PublicBookingController::show`), no idempotency key on
`AppointmentBookingService::book`, and no timezone resolver / phone→timezone helper. Stage 5 (SCHEDULING) is reserved. The legacy
hardcoded area-code timezone map is **not** ported. Booked state: "Mark booked" (existing) moves stage 6 and stops all outreach.

## 11. Follow-up

After message 3 is sent: `followup_at = now + follow_up_delay_hours` (default 24) when `followup_enabled`. One durable job
(`OutreachFollowUpJob`, delayed) **plus** a scheduled sweeper command (`outreach:dispatch-due-followups`, every 5 min) that
re-dispatches rows with `followup_at <= now AND followup_sent_at IS NULL AND followup_cancelled_at IS NULL`. Exactly once per member:
claim under the member lock, `operation_key = outreach:followup:{memberId}`. Not sent when: booked, rejected/opted out, AI paused,
a later inbound reply exists, the campaign is paused, the prospect is on `Blacklists`, or `followup_enabled` is off. The text is `followup_message`.

## 12. Readiness, campaigns, low balance

`AgencyOutreachReadiness::forWorkspace(Workspace): OutreachReadiness` is the one checklist (each item `ok|blocked` with an exact reason and a link):
script configured (messages 1–3, calendar URL, agency name), own Business resolvable, sending number ready, verification/A2P complete,
wallet payer allows paid activity and balance available (auto-recharge shown), prospects enrolled, compliance (STOP handling is always on).
Number/verification state comes from `MessagingReadinessReader`, extracted from `TextMessagingController::situation()` (the controller now
delegates to it; behaviour unchanged). Wallet state is read from `UsageBillingPresenter` / `EffectivePayerResolver`, never recomputed.

- A `managed` campaign cannot become **Active** unless every item is ok; the reason is shown.
- No number / not verified → do not start/send. Insufficient funds (`MessagingInsufficientFundsException`) → the send is **paused**, not failed:
  the member keeps its stage, the message ledger row is `status=paused, failure_reason=insufficient_balance`, and the campaign shows
  "Paused — add funds"; funding + resume re-sends under the same key (idempotent). Opted-out prospects never receive a message.
- Outreach surfaces link to the canonical Text messaging and Usage & billing pages of the Agency's own Business; nothing is duplicated.

## 13. Manual takeover

`agency_prospect_campaign_members.ai_paused_at` / `ai_paused_by_user_id`. Pause AI → the responder and follow-up do nothing and
record `manual_hold`; the owner replies from Conversations (canonical, billed); Resume AI clears the pause. A manual outbound in the
conversation after the latest inbound also suppresses an automatic reply to that inbound (no double reply). `OutreachTakeoverService`
is the only writer; every change is audited on the ledger with `actor_user_id`.

## 14. Idempotency keys

| Step | Key |
|---|---|
| inbound processed once | `outreach:in:{occurrenceKey}` (unique on `agency_prospect_messages.operation_key`) |
| reply generated/sent once | `outreach:reply:{inboundMessageId}` (ledger) → managed key `outreach:reply:{inboundMessageId}` |
| AI call once | AiGateway idempotency `agency_prospect_reply:{inboundMessageId}` |
| follow-up once | `outreach:followup:{memberId}` |
| opener once | existing `AgencyProspectingInitialSendJob` key |
| booking marked once | member lock + terminal stage |

## 15. Initial outbound

`opening_message` of the campaign is sent when it starts: `AgencyProspectingInitialSendJob` (unchanged) for `channel` campaigns; the new
`OutreachInitialSendJob` for `managed` campaigns, through the canonical sender and the §12 gates (member lock, `operation_key = outreach:opener:{memberId}`). The opening message is rendered through the canonical engine. The Ultimate SMS
`quickSend` ai_stage hook is untouched.

## 16. Metrics (Overview, real data only)

Active prospects, replies (distinct prospects with an inbound), calls booked, reply rate = replied ÷ messaged, booking rate = booked ÷ replied. No estimates.

## 17. Out of scope / remaining

Conversational scheduling; a global Managed-inbound STOP handler; Outreach on behalf of a client Business; prospect CSV import; per-niche
script templates; per-SMS price activation (platform-owner action); removal of the BYO channel code.
