# Business Email — inbound Gmail sync

- Scope added: `gmail.readonly` (Google **restricted** scope). Until the Google OAuth app is verified
  (restricted-scope review + security assessment) only listed test users can grant it. Nothing here
  claims production approval.
- Existing connections keep sending; they show "Reauthorize to receive replies" (sync stays off until the
  stored grant contains `gmail.readonly`). Reauthorizing replaces the credential only when consent completes.
- Sync: `business-email:sync-inbound` (every 5 min, scheduler) queues `SyncBusinessEmailInboxJob` per mailbox.
  First run reads at most `business_email.inbound.initial_max_messages` inbox messages from the last
  `initial_days` days, then follows Gmail `historyId` (persisted on `business_email_accounts.inbound_cursor`).
- Inbound rows live in `business_email_messages` (`direction=inbound`, `status=received`), idempotent on
  `inbound:{gmail message id}`. Mail we sent (SENT label / known provider id) is never re-imported.
- Replies: `POST .../email/messages/{uid}/reply` sends via the connected mailbox with `threadId`,
  `In-Reply-To` and `References`. Only senders matched to a Contact can be replied to from the UI.
- Manual Google Cloud setup: enable the Gmail API, add `gmail.readonly` to the consent screen, add test users.
- Not done: the SMS-shaped Conversations inbox (`chat_boxes`) is untouched; email threads appear on
  Settings → Email. Automation triggers for inbound email do not exist yet.
