# V1 Messaging / Conversations / Email — final acceptance

Branch `agent/v1-messaging-final`, based on `origin/agent/v1-completion-integration` (6c42beb3).
Fake providers only; browser smoke on a disposable preview DB with a fake messaging adapter.

## Defects found and fixed

| # | Defect | Fix |
|---|--------|-----|
| 1 | **A Business whose only sender is its managed number could not send Automation, Booking or Document-link texts.** `BusinessSmsSendingPath::originator()` required a `Senderid` or `phone_numbers` row; managed provisioning writes neither, so the path resolved to `no_business_sending_path`. Every earlier suite hid it by also giving the Business a legacy Senderid. | `originator()` falls back to the Business's own active primary managed number; `EloquentCampaignRepository::validateQuickSendOriginatorValue()` accepts a managed number only for the Business that owns it. A Business with no sender of any kind still fails closed. |
| 2 | Inbox tabs Unread / Read / All were unordered (primary-key order: oldest first; newest threads on later pages). | Every tab is `updated_at desc, id desc`. |
| 3 | Inbox search matched phone numbers only, though the list shows names; `%` / `_` acted as wildcards. | Search also matches the Business's own Contacts' first/last names (every word must match); wildcards are literal. Display-only, Business-scoped. |
| 4 | Empty inbox / empty filter / empty search was a blank pane. | Plain-language empty states (first page only). |
| 5 | Four stale `TextMessagingStatusTest` cases (they predate the Approved-registration requirement for "Ready"). | Fixtures/assertions updated to the current page. |

Tests: `ManagedOnlySenderPathTest` (5), `InboxListFinalAcceptanceTest` (5), corrected `TextMessagingStatusTest`.

## Verified, no change needed

- **One conversation authority.** Manual replies, Automation sends, campaigns and Booking texts all write through `ConversationHistoryWriter`; one thread per (Business number, person); another Business never shares it.
- **Billing.** Per-Business wallet; insufficient balance refuses before any provider contact; a replayed operation key neither resends nor charges twice; a provider rejection releases the hold. Browser: a View-As send charged only the client's wallet (one segment); the Agency wallet was untouched.
- **Agency View As.** Conversations list and sends belong to the viewed client only.
- **Email.** Provider errors are mapped and sanitized, logs carry ids only, tests assert provider detail never leaks. Booking confirmation/reminder mail uses platform transport and does not depend on a connected mailbox.
- **Opt-out at the action boundary.** Automations and Booking re-check Contact status at send time.

## Known gaps / decisions for the owner (not changed here)

1. **STOP on a managed number.** Only Outreach (`OutreachStopClassifier`) turns an inbound STOP into a blacklist/stop. For an ordinary Business the reply is recorded in Conversations but the Contact stays subscribed and no Blacklist row is written; the carrier-level STOP keyword (`optoutKeywords: STOP, UNSUBSCRIBE`) blocks further sends from the provider side. A Business-wide consent ledger is a product decision (see managed-messaging contract E-30).
2. **BYO sends from Automations/Booking do not appear in Conversations** unless the sending server is two-way with a phone-number originator (legacy rule in `EloquentCampaignRepository::quickSend`). Managed sends always do.
3. **Booking email has no Reply-To**: a guest reply goes to the platform address. A connected Business mailbox could supply one; not done (it would couple transactional mail to the mailbox).
4. **View As banner/Exit is absent on Conversations in this base**; `agent/agency-v1-final` fixes it and is not merged here.
5. The inbox list is not refreshed after a send until the next poll/reload (preview text and order).
