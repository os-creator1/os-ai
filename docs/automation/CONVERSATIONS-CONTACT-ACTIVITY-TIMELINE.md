# Conversations — contact activity timeline

Implementation record for the Business Conversations screen moving from an
SMS-only transcript to one chronological activity timeline per person.

| Field | Value |
|---|---|
| Branch | `agent/conversations-contact-activity-timeline` |
| Base | `origin/main` `a6c5f5f4d7f5afe3caf1605b97a609d047cff63b` |
| Governance | Route 3 — a human-authorized manual lane; scope comes from its own task |
| Navigation | Untouched. `CustomerMenuBuilder` and sidebar labels belong to the navigation lane; this page works at its existing route whatever the menu later calls it |

---

## 1. The screen

Three panes, on the existing Business route
`customer.workspaces.businesses.conversations.index`:

| Pane | What it shows |
|---|---|
| **Left** — conversations | The existing list: Recents / Unread / Read / All filters, search, the pinned rail and load-more. Each row now leads with the person — their name when exactly one contact of this Business has the number, otherwise the number — then the latest message and the time. Pinned and loaded rows share one partial, `_chat_row`. There is no Starred filter because nothing stores a star; pinning is the closest real concept and keeps its own rail |
| **Center** — timeline | One chronological timeline for the person, grouped by the viewer's day. Human messages are bubbles and dominate (inbound left, outbound right, with an attribution line when an automation or campaign sent them). Everything else is a compact card. The SMS composer sits underneath, labelled **SMS** — the only channel a conversation has today. No Email option is offered |
| **Right** — contact panel | Name, phone, email, company, group, when added, the Business number the conversation uses, and messaging status (subscribed, unsubscribed, on the block list, or not linked to a contact). "Open contact" links to the person-first profile when the viewer holds `view_contact`. Below the `xl` breakpoint the panel slides over the timeline from a header toggle |

Opening a conversation calls one new action, `timeline`, which returns both
the timeline and the panel **rendered by Blade on the server**. No stored
message is turned into markup in the browser any more; the only client-built
bubble is the existing optimistic one shown straight after a send.

---

## 2. Architecture decision: a read model, not an activity ledger

`App\Library\Timeline\ContactActivityTimeline` merges registered
`TimelineSource`s. Each source runs a bounded, Business-scoped query on the
table that already owns its facts; the merge happens in PHP. **No table is
added and nothing is written.**

Why not a durable `activity_events` ledger:

- every v1 fact already has exactly one owner (`chat_box_messages`, `reports`,
  the automation ledgers, `blacklists`, `contacts`); a ledger would be a second
  copy of each, needing its own producers, backfill and drift handling;
- the Business Home's Recent work (`RecentWorkReader`, Unified Home §14) made the
  same choice for the same reason, and a projection there "would need its own
  contract" — so would one here;
- the read model's cost is fixed: **7 statements** for the timeline however much
  history a person has (`ContactActivityTimelineTest` pins it).

A ledger becomes the right answer only if a future domain has events with no
table of their own, or if the per-source union outgrows its budget. Either would
be a separate, approved contract.

### 2.1 `TimelineItem` — the domain-neutral unit

`App\Library\Timeline\TimelineItem`: `key` (source row identity), `kind`
(`Message` | `Activity`), `at`, `title` (an activity's finished sentence), `body`
(a message's text, read from its canonical row — never copied), `direction`,
`channel` (`sms` today), `media`, `via` (who sent an outbound message when it was
not a person in the inbox), `detail` (one short secondary line), `tone`, `icon`,
`represents` (keys of items this one already stands for) and `sequence`.

Nothing in it is SMS-shaped except the default channel. An email is a `Message`
on channel `email`; a form submission, invoice or payment is an `Activity`.

### 2.2 Merge rules

1. **Bounded.** Each source returns at most 200 items. If one had more, every
   source is cut at that source's oldest kept moment, so the window shown is
   complete for all of them, and a divider says older activity is not shown.
2. **Never twice.** An item whose key another item `represents` is dropped.
3. **Order.** Oldest first; ties by source registration order, then row id.

### 2.3 Who "the person" is

`TimelineSubject` carries the external number (digits) and — only when exactly
one contact of this Business has that number (`ChatBox::resolveDisplayContact()`,
Slice 2B §10) — the Contact. Number-keyed sources always apply; contact-keyed
sources add nothing without a single Contact, because attributing one contact's
automation history to a number two contacts share would be a guess. The panel
reads the same in both "no contact" and "several contacts" cases.

---

## 3. v1 sources — real data only

| Source | Reads | Shows | Why it is never a duplicate or an inference |
|---|---|---|---|
| `ConversationMessagesSource` | `chat_box_messages` of the resolved conversation, joined (in the same statement, each join pinned to the Business) to its managed operation, that operation's campaign report, and the sending automation step | Inbound and outbound bubbles, with media. An outbound bubble says who sent it when the row proves it: "Sent by automation: {workflow}", "Sent by campaign: {name}", "Sent manually" (a person in Conversations); and "Not delivered" when its managed operation failed | The canonical conversation history (legacy inbound, managed inbound since #285, two-way inbox sends, every accepted managed send — §3A). A legacy outbound row with no provenance claims nothing. A managed row `represents` its automation step and its campaign report, so neither appears again |
| `AttributedOutboundMessagesSource` | `reports` for this Business and number, **outgoing**, carrying `automation_step_run_id`, `automation_id` or `campaign_id` | Outbound bubbles: "Sent by automation: {workflow}", "Sent by campaign: {name}", and "Not delivered" when the status says so | Those marks prove the row did not come from the inbox. An inbox send's own unmarked report is never read. A managed campaign send's report is dropped when its conversation message is in the open conversation (that message `represents` it). Every attribution join is pinned to the same Business |
| `AutomationActivitySource` (contact-keyed) | `automation_enrollments`, `automation_step_runs`, `automation_executions` | "Added to automation …", how the journey ended, and the outcome of steps that act on the person or the team: sent / did not send a text, updated contact details, notified the team — with a plain-words reason for known codes | Wait, If/Else, End and the trigger never appear. A V2 text stamped on its report shows as that message (the report `represents` the step). A failed or skipped step always ends the journey, so its card `represents` the ending. Unknown reason codes are left out, never shown raw |
| `ContactRecordSource` | `contacts.created_at` (contact-keyed); `blacklists` for this Business and number | "Added to contacts · Group", "Opted out of texts" (inbound STOP / opt-out keyword), "Blocked from Conversations", "Added to the block list" | Dated rows only. The block list is keyed by number, so an opt-out still shows after STOP deleted the conversation it arrived on |

### 3A. Managed outbound history — one canonical writer

Conversations is the canonical history of what happened with a person, so an
accepted managed send must be in it with its text — never only optimistic,
never gone on reopen, never only operational metadata.

**The seam.** Every managed send crosses `ManagedDispatchDelegate::attempt()`
(both convergence points: `EloquentCampaignRepository::quickSend()` and
`Campaigns::sendSMS()`). After the dispatcher returns an **accepted** result it
calls `App\Library\Conversations\ConversationHistoryWriter::recordManagedOutbound()`.
A refused or failed send records nothing.

| Path | Now recorded | `source` | Attribution on the bubble |
|---|---|---|---|
| Reply or new conversation from Conversations | yes | `conversations` | Sent manually |
| Automations V2 Send SMS (managed) | yes, on the person's conversation | `quick_send` | Sent by automation: {workflow} (from `automation_step_run_id`, read from `AutomationSendContext` exactly as the `reports` stamp is) |
| B4 Send message (managed), Outreach quick send, other single sends | yes | `quick_send` | none claimed |
| Campaign / Outreach bulk (managed) | yes | `campaign` | Sent by campaign: {name} (through the operation's `report_id`) |

**One conversation identity.** The writer owns the managed conversation key #285
introduced — (Business owner, Business, the Business's number, the person's
number), both normalized by `MessageReceivedTriggerSource::normalizePhone()` —
and the managed inbound bridge now calls the same writer. A reply and the
message it answers always share one thread. The Business's number is its single
active primary managed number, resolved as the dispatcher resolves it.

**The text.** The final body the dispatcher sent — spintax already resolved by
the caller (`quickSend()` before dispatch; `Campaigns::send()` before
`sendSMS()`).

**Exactly once.** `chat_box_messages.business_messaging_operation_id` (nullable,
**unique**) holds the send's own operation id. A replayed request, a double
click or a retried job reaches the dispatcher with the same operation key, gets
the recorded result back with no second provider call, and the writer finds the
existing row (or the unique index refuses a racing copy). Never deduplicated by
body, time or number. Two new nullable references sit beside it:
`automation_step_run_id` and `source`; and every managed campaign `reports` row
carries `business_messaging_operation_id` too (see the campaign note below).
Nothing is backfilled; every existing row stays valid.

**When the provider accepted but history could not be written.** The send is
still reported as successful — a failure here must not invite a second send. The
exception is caught and logged as `conversation_history.managed_outbound_not_recorded`
(Business id, a hash of the operation key, the exception class — never the
number or the text). It reconciles: the same logical send replayed returns the
recorded result without a provider call and the writer records it once.

**Unchanged:** usage measurement and wallet behaviour, `reports` (a managed
quick send still writes none; a campaign send still writes its report),
`business_messaging_operations`, the provider adapter, DLR handling, STOP and
the block list, consent checks, and every legacy sending-server path.

**One correction on the reply path.** Sender verification in
`ChatBoxController::reply()` checks that the customer owns the number they chose,
in `phone_numbers`. A managed Business chooses no number — the dispatcher
resolves its own primary number from the Business — and a managed number is
never a `phone_numbers` row, so with verification on (the plan default) every
managed reply was refused before it could be sent. The check is now skipped for
a managed Business only; every other Business is verified exactly as before
(`ManagedOutboundConversationHistoryTest` proves both).

**Consequences to know about.**
- An accepted managed automation or campaign text to someone with no
  conversation yet **creates** that conversation, exactly as a legacy two-way
  send does. For a managed Business this raises the conversation list and the
  Home's "conversations started" count (`BusinessConversationReadModel::startedCount()`
  counts `chat_boxes`) by the recipients of those sends.
- **One managed campaign send, one bubble — however many jobs track it.** One
  managed operation can be reached by more than one campaign job: two contacts
  of the campaign on the same number share one operation key, and a
  redelivered job reaches it again. The dispatcher sends once, but each tracked
  job still gets its own `reports` row, because `track_message()` stores the
  report id in `tracking_logs.message_id`, which is **unique**, and per-recipient
  campaign accounting depends on it. (Reusing one report for both jobs was
  tried and rejected: the second tracking log collides with that index and the
  job retries for up to 30 days.) So `recordLegacyReport()` writes the report
  exactly as before and **stamps** it with its operation —
  `reports.business_messaging_operation_id` (nullable, indexed, no backfill).
  By that identity — never by text or time — the timeline's reports source
  reads only the **first** report of an operation, and not even that one when
  the operation's conversation message is in the open conversation. So the send
  is one bubble in every conversation with the person, including an older
  thread that does not hold its message. Provider calls, measurement, wallet
  behaviour, delivery correlation (`business_messaging_operations.report_id`
  still names the first report), counters and tracking are unchanged.
- Automations V2 self-reply protection reads the `reports` stamp, which a managed
  quick send still does not write. Recorded, not changed.

### Not shown, on purpose

- **Email, Forms, invoices, payments, bookings, CRM opportunity events** — no
  canonical source exists; nothing is faked.
- A **subscription status changed without a block-list row** leaves no dated
  record: the panel shows the current status, the timeline shows no event.

---

## 4. Extension seams

**A future timeline domain** (Email received/sent, Form submitted, Opportunity
moved stage, Invoice sent, Payment received):

1. implement `App\Library\Timeline\Contracts\TimelineSource` — Business-scoped,
   persisted rows only, fixed query count, newest first, at most `$limit`, nothing
   when its rows cannot be tied to this person;
2. tag it `ContactActivityTimeline::SOURCES_TAG` in `AppServiceProvider`;
3. use `represents` if it overlaps another source (an "email sent" event and the
   email message itself).

The Conversations screen does not change. `ContactActivityTimelineTest` proves a
stub source joins the merged timeline, including a `Message` on channel `email`.

**A contact-panel section** (the future CRM Opportunity summary, open invoices,
upcoming bookings): implement
`App\Library\Conversations\Contracts\ConversationContextSection` and tag it
`ConversationContextReader::SECTIONS_TAG`. Nothing is registered today, so the
panel shows no placeholder.

**Composer channels:** the composer carries a channel label, not a selector. A
channel selector belongs with the first second channel that actually sends.

---

## 5. Route and authorization

One route is added to the existing Business family:

```
POST /workspaces/{workspaceUid}/businesses/{businessUid}/conversations/{uid}/timeline
     customer.workspaces.businesses.conversations.timeline → ChatBoxController@timeline
```

It runs the same §9 chain as every other conversation action (Workspace →
Business → access → `chat_box` → `conversations` entitlement → uid AND
business_id). A foreign, NULL-business or nonexistent conversation, a numeric id
in place of a uid, and a Business outside the Workspace are the same 404. It is
read-only and Business-addressed, so view-as narrows it to the viewed pair like
the rest of the family. `messages` keeps serving the raw thread unchanged.

---

## 6. Found during this work — not changed here

These are existing behaviours outside this read model's scope, recorded so they
are not mistaken for timeline defects:

1. ~~A managed Business's own outbound sends were not persisted as messages.~~
   **Resolved in §3A** (PR #299 blocker): every accepted managed send is now
   recorded, once, by `ConversationHistoryWriter`.
2. Outreach quick sends and API sends from a **Sender ID** carry no mark on their
   report and write no conversation message, so they cannot be attributed to a
   person.
3. A **B4** automation text sent through a BYO channel shows as its outcome card
   only: its report carries no automation mark, so its body cannot be tied to it.
4. The theme's `chat.js` search filter hides non-matching rows (pinned included)
   on each keystroke and does not un-hide them when the search is cleared; the
   list reloads from the server, the pinned rail does not. Pre-existing.

Fixed here because it is the list's own filter: **Read** used
`notification = 0`, but `chat_boxes.notification` has no default, so a
conversation never marked unread (`NULL`) was in neither Read nor Unread. Read
now includes `NULL`, grouped inside the Business filter.

---

## 7. Tests

| File | Proves |
|---|---|
| `tests/Feature/Conversations/ContactActivityTimelineTest.php` | Oldest-first merge of messages and activity; an automation text shown once and an inbox reply never doubled; campaign and legacy automation attribution and "Not delivered"; only human-useful automation outcomes, no raw codes; B4 outcomes; contact-keyed activity needs exactly one contact; nothing from another Business, even on the same number or via a foreign stamp; the window is cut at one moment; a future source joins; a flat 7-statement cost |
| `tests/Feature/Conversations/ManagedOutboundConversationHistoryTest.php` | Through the real send core and managed dispatcher (fake provider only): a managed campaign send tracked by two campaign jobs (two contacts on one number), driven as `SendMessage` does — `sendSMS()` then `track_message()` — is one provider send, one conversation message and one timeline bubble, while each job keeps its own report (both stamped with the operation) and its own tracking log; a managed reply from Conversations is recorded with its exact text and shows once on reopen, "Sent manually"; a refused reply records nothing; a replayed reply sends once and records once; when history cannot be written the accepted send is still a success, is logged without number or text, and a replay records it once with no second provider call; an Automations V2 text over managed transport lands on the person's conversation, stamped with its step, one bubble "Sent by automation"; a managed campaign send is one bubble "Sent by campaign" and a retry records nothing more; one person in two Businesses never shares history; sender verification still refuses a Business that is not managed |
| `tests/Feature/Conversations/ConversationTimelineScreenTest.php` | Three panes and an SMS-only composer; the timeline action returns both panes; server-side escaping (including non-http media URLs); a shared number shows the number alone; block-list status; the profile link needs `view_contact`; every tenancy failure is the same 404; the list leads with the name and `messages` is unchanged; the Read filter includes never-unread conversations and stays inside the Business |
| `tests/Feature/DesignSystem/ChatBox*.php` | Updated in place, each change stated in its docblock: one more button and icon (the panel toggle), the shared row partial, the `timeline` action, and server rendering in place of the client-side history and Echo builders |
| `tests/Feature/Security/ChatBoxSecurityTest.php` | Sections E–G updated in place to where safe rendering now lives: no stored message field is read by the page script, the only response fields inserted as HTML are the two server-rendered panes and the unread count, the partials never echo raw, and a real timeline response escapes hostile text and refuses `javascript:` and attribute-breaking media URLs. The optimistic send keeps `safeMessageParagraph()` and its 200px attribute-only image |
