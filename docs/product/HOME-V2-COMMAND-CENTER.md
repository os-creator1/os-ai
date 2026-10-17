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

## Home redesign — dynamic Home and the Ask card (`agent/home-ai-experience-redesign`)

One dynamic Home, not separate dashboards. `HomeCommandCenter` still decides the state; the templates only place what it built.

* **Greeting and status.** The greeting row carries a status pill from `HomeCommandCenter::status()`: *N things need you*, *All
  caught up · nothing needs you*, *Setup still in progress* or *Data temporarily unavailable*. "All caught up" is said only when no
  source failed and nothing needs the customer.
* **Next Best Move — conditional.** Rendered only when there is a move (or it could not be worked out). A quiet Business gets no
  empty urgent-action container; the page rebalances around the Ask card. The waiting-customers move shows its own figure (the
  awaiting-reply count the Conversations band already read, "of N conversations" only when coherent) and, when the actor's plan
  includes the assistant and `draft_reply` is available, a *Draft replies with MotionGrove* button that sends through the normal
  assistant send (it prepares a reply; nothing is sent). *Why this?* and "Nothing else needs your attention" sit in the card footer.
  The attention card appears only when it has its own items or could not check.
* **Business performance.** The same headlines band and range logic. The range control is a segmented *7 days / This month /
  30 days / Custom* (the shared `analytics._range` partial with `segmented`; Last 90 days / Last month still work when chosen
  via URL and show as an extra active segment). Figures sit in one bordered row; the contacts chart title is *New contacts per day*.
  When the series has no contact in the period the chart is replaced by *No activity to chart yet*, never a flat line.
* **Bottom row.** *While it's quiet* (create a booking type, add a website form, add a contact — each only if its page exists for the
  actor; shown only when the status is "all caught up"), *What MotionGrove is handling*, and *Automations* (the existing band's
  figures, or "No automations ran …" with *Set one up* where the actor may create a workflow). Automations left the "More detail" fold.
* **Order.** greeting → (setup / lifted attention) → move and attention → Ask → growth queue → performance → bottom row → More detail.
* **Ask card.** Compact: one input, *Ask*, up to four suggestion chips (only questions the assistant can answer for this actor), and
  *Continue your conversation* when a saved conversation exists. Opening it expands the same card into the fullscreen conversation — see
  `ASK-MOTIONGROVE-V1.md` ("Fullscreen conversation and history").

Not included: thumbs-up/down feedback (no backend), comparison badges beyond the existing headline sentences, and date filters beyond
the presets and custom range above.
## Ask MotionGrove (closed registry; the no-JavaScript path)

The form still posts to `HomeActionsController@ask` without JavaScript. With JavaScript the card talks to the assistant instead (above). A request box over a **closed registry** (`HomeAskRegistry`). It is not a chatbot
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

## Final Home (`agent/home-v2-final-experience`) — supersedes the layout above

The approved final design. `HomeCommandCenter` still decides state, primary move, attention and status; the new
`HomeV2Presenter` (pure, no queries) turns that model plus the Headlines band into one `v2` block, and
`customer/dashboard/home/command-center.blade.php` + `home/v2/*` only place it. Nothing is computed in a view, no figure is invented
and every number is one an existing seam already produced (B5 `BusinessAnalyticsQueries`, the conversation read model, the booking
reader).

**Desktop, two columns.** Left: greeting, *Needs you*, one compact *Ask MotionGrove* bar (1–2 chips), *Is my business growing?*
(three cards and one short chart). Right: *Done for you*, *Suggested next*. Tablet and mobile stack the right column under the left.
Everything the earlier Home showed that is not on this page (Visibility, Conversations, Automations, Quick actions) stays one fold
down in *More detail*; the cached "What we notice" summary and "Explain this change" sit collapsed under the chart.

**Needs you.** Highest-priority item from the existing attention / Next Best Move engine, with one action that goes straight to the
records: waiting customers → the inbox's own `filter=waiting` list (the same predicate and Location rule as the count);
*Draft replies to review* hands the question to the existing assistant (it prepares, nothing is sent). Billing keeps its strip
above and is not repeated. A failed move read says so. *You're all caught up* is shown only when every read that could contradict
it succeeded and nothing needs the customer.

**Growth cards** (7 days / This month / 30 days / Custom, the shared range control; one async region):

| Card | Source | Opens |
|------|--------|-------|
| Bookings | `HomeBookingReader` (appointments booked, never revenue) | Calendar, week view, for the period (7- and 14-day windows only) |
| New contacts | B5 new-contacts headline (labelled "contacts", never "leads") | People list filtered by `created_from` / `created_to` |
| New conversations | conversation read model | Inbox filtered by the same two dates |

Data rules (one place: `HomeV2Presenter::card()`): **—** and a short reason when nothing was ever collected; **First month /
First period** when there is no earlier period to compare; **Same** for an observed zero in both periods; green **▲ n** / amber
**▼ n** (the difference, information not an error); **Updating…** while a contact import runs (comparison held back); a source
that failed is *unavailable*, never zero. Revenue is never a metric: a quiet "Track revenue by connecting payments → Connect" line.

**Chart.** "New contacts per day" from the existing series endpoint for the same range. Hidden behind "Your trend will appear
after your first week." with under seven observed days, and when every day is zero. There is no Leads/Bookings switch because no
bookings series exists.

**Filtered destinations.** `created_from` / `created_to` (Business-local dates) are read by `HomeDestinationRange`, which builds the
same `AnalyticsDateRange` the card used; anything invalid (malformed, reversed, too long) is ignored and the list is unfiltered.
`ChatBox` also gained `filter=waiting` (`BusinessConversationReadModel::awaitingReplyBoxIds`).

**Done for you** shows only real completed events with dates (`RecentWorkReader`) and *See activity*; **Suggested next** shows at
most three real setup suggestions (website, follow-ups, payments, email, booking) and never a completed one.

**New business.** When the Business is still in the setup state, has no activity and no failed read, Home shows *Welcome / Get set
up* instead: a progress count and guided steps derived from saved state through `HomeSetupJourney` plus email, packages and
follow-ups (`HomeV2Facts`: four single-statement existence checks), one highlighted step, the Ask card and a quiet "Your results will
show here" note. Nothing is done because a button was pressed; a step the actor cannot act on is listed without a button.

**Cost.** `DashboardQueryBudgetTest` pins the Home at 30 Dashboard-owned reads and 8 analytics reads (was 25 / 7): five single
existence checks plus B5's import-running check, all flat in rows.

**Tests.** `HomeV2ExperienceTest` (layout, setup Home, dash/Same/First month/▲/▼, destination links, permission, failed source, chart
gating, filtered lists) plus the updated Dashboards, Assistant and Interactions suites.
