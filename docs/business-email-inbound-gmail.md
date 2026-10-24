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

## Robustness notes
- Bodies/headers are converted to valid UTF-8 (declared charset, else Windows-1252) and bodies are capped at
  `business_email.inbound.max_body_bytes` (60,000 bytes) before storage.
- A message that cannot be parsed/stored is skipped, not retried forever: `business_email_accounts.inbound_skipped_count`,
  `inbound_last_skipped_id/_reason/_at` record the Gmail id and reason only (never content).
- History is consumed whole records at a time; the cursor never passes an unfetched message. Continuation is a
  `ShouldBeUniqueUntilProcessing` job re-queued only while more remains; the 5-minute scheduler is the safety net.
- Google restricted-scope (`gmail.readonly`) production verification is an external compliance requirement.
