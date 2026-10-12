# Home V2 — Business Command Center

Status: implemented on `agent/home-v2-command-center` (stacked on `origin/staging` 077eca84).
Scope: **Business Home only.** Platform Owner Home and Agency portfolio Home are untouched.

## What changed, in one paragraph

Business Home used to be a stack of independent bands (Growth, activity, next
best move, performance, visibility, conversations, automations, recent work,
quick actions) in which the Growth band *replaced* the next-best-move band and
neither could say which one thing mattered. It is now one coordinated page.
`BusinessHomePresenter` still reads every fact from the seam that owns it;
`HomeCommandCenter` (new, pure, no queries) turns those bands into one
presentation model, and `customer/dashboard/home/*` renders it. The old band
stack remains as the fallback if the coordinator itself fails.

## Sections

| # | Section | Source of truth | Notes |
|---|---------|-----------------|-------|
| A | Greeting + briefing | activity band counts (`HomeVisitMarker` window), `HomeBriefing` | Template sentence from canonical counts. Never "leads"; automation runs are "automated tasks". Quiet and first-visit states are truthful. No AI. |
| B | Your Next Best Move | `NextBestMoveSelector` (unchanged order), enriched by the Growth item for the *same* Opportunity | Exactly one. Growth items only enrich or queue behind it. WHAT / WHY / IMPACT (registry `expected` text only) / STATUS / ACTION. No effort estimate (none is reliably available). |
| — | Secondary queue | Growth reader's own items (ACL-scoped), minus the primary | "Also worth a look" / "Ways to grow from here". |
| C | Needs your attention | status row + conversations + automation failures + `BookingTypeManager::publicBookingReadiness` + Growth items awaiting approval | Problem / Approval / Info. The primary problem is removed (no duplicates). All-clear only when the sources were actually read. 3 visible, rest behind "Show more". |
| D | Business performance | existing headlines band (B5 + conversation read model) + **Appointments booked** | Appointments are a booking *event* (created, not cancelled), Location-scoped, with previous-period comparison. Never paid revenue. |
| E | What MotionGrove is handling | `RecentWorkReader` persisted rows + Opportunity engine status | Completed / Failed / In progress / Waiting for approval. A queued job is never "completed". |
| F | Ask MotionGrove | `HomeAskRegistry` (closed registry) → `HomeActionRouter` | See below. |

## Four states (one layout)

`HomeCommandCenter::state`:

* `setup` — essential steps incomplete (no Location, no active booking type, or
  no bookable booking type, when Calendar is entitled). The guided journey
  (`HomeSetupJourney`) is counted from persisted facts; nothing in
  `localStorage`, no percentages. It leaves Home the moment the essentials are true.
* `broken` — billing exception, lost Google connection, failing automations, or
  no bookable booking type. Attention is lifted above the move.
* `growing` — nothing urgent and there is an Opportunity move or queue.
* `normal` — otherwise.

## Action drawers

Each drawer is an HTML fragment served by `HomeActionsController@drawer`
(GET, read-only). **No drawer writes anything itself.** Its form posts to the
module's own endpoint, so tenancy, entitlement, permission, Location ACL,
validation and idempotency are the module's, never Home's.

| Drawer | Reads | Write (module endpoint) | Done from Home |
|--------|-------|-------------------------|----------------|
| `follow-ups` | `BusinessConversationReadModel::awaitingReplyConversations` (same predicate and Location rule as the inbox) | `conversations.reply` (JSON, idempotency token) | Yes |
| `booking-type` | accessible Locations | `calendar.booking-types.store` (now also answers JSON) | Yes |
| `appointment` | active booking types, eligible staff, bookable-contact search | `calendar.appointments.store` (now also answers JSON incl. engine refusals as 422) | Yes — engine decides conflicts / staff / Location |
| `upcoming` | `CalendarScheduleService::appointmentsForRange` (next 14 days) | — | Read |
| `ads` | Growth Opportunities from the Ads Decision Engine (persisted decision + evidence) | — | Review only; action opens the provider page |

Always real links without JavaScript: every trigger is an `<a href>` to the
module page; `public/js/home/command-center.js` upgrades it to a drawer.

## Ask MotionGrove

A request box over a **closed registry** (`HomeAskRegistry`). It is not a chatbot
and calls no AI provider. Typing a request resolves, deterministically and
server-side (`HomeActionsController@ask`), to one of:

* `drawer` — one of the drawers above (only if `HomeActionRouter` — the same
  `DashboardLinkGate` four-way rule as every Home link — allows it);
* `link` — the exact module page (website, add contact);
* `post` — the Advisor's closed-question form ("Explain this month's results"),
  an explicit confirm-first POST that may use AI allowance, never on GET;
* `unsupported` — says so plainly and lists what it can do. Nothing is executed.

Suggestions the actor cannot use are absent (house rule: absent, never disabled).

## Deliberate behaviour changes

* The next-best-move band is **no longer suppressed** when Growth renders; the
  selector's pick is the one move. Cost: the cached MoveExplanation read (+1
  query) — see `DashboardQueryBudgetTest`.
* The awaiting-reply count Home uses is now Location-scoped for the actor
  (`awaitingReplyCount(..., $actorUserId)`), matching the inbox, so a
  Location-restricted staff member is never counted another Location's customers.
* The legacy Visibility / Conversations / Automations bands and Quick actions are
  kept, one fold down under "More detail".

## Known limitations / missing dependencies

* **Website / content approval drawer:** the website editor's draft → publish
  flow has no JSON/fragment boundary; it needs one before an inline preview can
  be honest. Deep link only.
* **Contact add:** contact creation is group-based (`ContactsController`) with no
  Business-level create service; deep link only.
* **Paid revenue / closed customers KPIs:** no single canonical, Location-safe
  reader exists for Home; not shown rather than approximated.
* **Messaging channel step in setup:** channel readiness is agency-gated with no
  Business-level reader; not part of the journey.
* **Handled-work breadth:** only persisted outcomes are listed (website
  versions, completed recommendations, Google operations, automation failures).
  Reminder / confirmation sends have no per-Business outcome ledger to read.
* **Booking attribution:** "booked" counts the booking event; there is no
  lead→booking attribution, so Home makes none.
