# Ask MotionGrove — PR #460 handoff (round 3 corrections, in progress)

Written because the session was transferred to another Claude account. A new session with ZERO prior context should be able to
continue from this file alone. **This branch is a CHECKPOINT, not a release branch. Do not open a PR from it and do not merge it.**

## 1. Branches and SHAs

| What | Value |
|---|---|
| Repository | `os-creator1/os-ai` |
| PR (already open, do NOT create another) | #460 — https://github.com/os-creator1/os-ai/pull/460 (head `integration/ask-motiongrove-v1`, base `staging`) |
| PR branch | `integration/ask-motiongrove-v1` |
| Last pushed, reviewed PR-branch SHA (all work below sits on top of it) | `5ef372975240758f7ec58bcb5f9eb05c7ace308c` |
| Staging base used for the integration merge | `3a5296b671de655b2578ccff3a444463520f75d0` (staging may have moved since; do not rebase/merge staging unless asked) |
| Approved AI feature branch / SHA | `agent/ask-motiongrove-ai-v1` @ `a85a3b385aa1c1d70f3756f0187f717e5b9ec0d1` |
| Checkpoint branch (this one) | `handoff/ask-motiongrove-pr460` |
| Checkpoint SHA | the commit that contains this file: run `git rev-parse HEAD` on the branch (the SHA cannot be written into the file it is committed in) |

PR-branch history for orientation (oldest first): `57f0783e` merge of the feature into staging base → `9083c74c` AccountRecordGraph::USER_OWNED
classification (the account-deletion integration) → `a444c207` production-prerequisites doc → `7d31757d` first round of review fixes
(upgrade reconcile, view-as approval, first-message idempotency, reply correlation) → `5ef37297` conversation cap on addressed follow-ups.

## 2. The task (verbatim intent) and acceptance requirements

Continue PR #460. The latest automated GitHub review (chatgpt-codex-connector) resolved the previous five findings and raised TWO NEW
unresolved findings. This is a narrowly scoped correction: do not redesign the assistant, change pricing, build features, create
another PR, merge, deploy, use subagents or call real AI providers.

### Finding 1 — P1 — thread `PRRT_kwDOTnPFFc6sO-VG` (review comment id `4268668384`)
File `app/Library/Assistant/AssistantCompactor.php` (~line 52): `AssistantPromptBuilder::history()` replaces *deterministic*
server-rendered replies (the `unanswered_conversations` list: customer names, phone numbers, message snippets; payload
`deterministic: true`) with a safe placeholder, but `AssistantCompactor::maybeCompact()` sent the raw stored body to the external provider,
so withheld data was disclosed and could persist in the generated summary.
Requirements: compaction applies the SAME provider-facing redaction as history; prefer ONE shared narrowly scoped sanitizer so they cannot
drift; no names/phones/snippets from deterministic replies reach the provider; the customer's own conversation UI keeps the full original
reply; ordinary permitted context still compacts (do not disable/weaken compaction); summaries cannot preserve excluded data; tenant
isolation and hard AI spending limits untouched. Regression: a deterministic `unanswered_conversations` reply containing unique test
names/phones/snippets; force compaction through the REAL `AssistantModel`/`AiGateway` boundary with a fake provider; inspect the actual
submitted payload; assert none of those values reaches the provider or the generated summary; assert ordinary information still compacts.

### Finding 2 — P2 — thread `PRRT_kwDOTnPFFc6sO-VM` (review comment id `4268668395`)
File `app/Library/Assistant/Tools/CreateAppointmentTool.php` (~lines 79–105, `prepare()`): for booking types with email/SMS notifications
enabled the preview says approving will send the customer a confirmation. But the assistant's authenticated `bookWithRoundRobin()` path
schedules NO customer email/SMS (confirmations/reminders are only written by the PUBLIC booking flow, `AppointmentNotificationScheduler::
recordPublicBooking`). `AssistantAppointmentSideEffectsTest` already proves no mail/SMS/notification is sent.
Requirements: V1 keeps the safe execution contract (assistant bookings create appointments but do NOT automatically message customers).
Remove the incorrect promise; state accurately that the appointment is booked but no automatic customer confirmation is sent by this action;
preview recipients/effects must match reality; keep availability checks, confirmation approval, fencing, Calendar events and idempotency;
do NOT build a notification pipeline; do not modify Calendar unnecessarily. Tests for booking types with email only, SMS only, both, neither:
every preview accurately describes what the customer will and will not receive.

### Verification the user required
Failing reproductions first, then the smallest fixes. Run SEQUENTIALLY on disposable MySQL 8: the new focused regressions; the Assistant
suite; relevant AI privacy/prompt-compaction tests; the appointment side-effect tests; relevant account-deletion tests; the scripted
fake-provider browser harness where customer-visible behaviour is affected. Compare unrelated failures with the established staging
baseline (section 9) — do NOT fix baseline failures. Preserve all five previous PR corrections. Commit and push to the SAME integration
branch. Reply to the two GitHub threads with the correction SHA and test evidence; resolve ONLY after verification. `git diff --check`
clean and a clean worktree. Report final SHA, tests, remaining unresolved findings, exact merge readiness. STOP — do not merge or deploy.

## 3. Rules (binding)
- No real AI provider (fake provider only; `Http::preventStrayRequests()` is on in the Assistant tests). No subagents. No new PR. No merge,
  no deploy. No unrelated changes. Do not touch AI pricing/gateway budget logic. Do not delete conversations or change the 90-day retention.
- Repo `CLAUDE.md`/`AGENTS.md`: use ONLY the disposable test DB (`ultimatesms_testing`, validated by `Tests\Support\TestDatabaseSafety`);
  never a production-looking DB/credential; do NOT run DB-writing test processes concurrently; never claim tests that were not run.
  (`CLAUDE.md` says Claude never opens PRs; PR #460 was opened only because the user explicitly asked — do not open another.)
- Commit messages must end with the attribution lines the harness gives (Co-Authored-By / Claude-Session). GitHub comments/replies must end
  with the footer `---` + `_Generated by [Claude Code](https://claude.ai/code)_`.
- Do not push partially finished work onto `integration/ask-motiongrove-v1`; only push verified, complete corrections (section 13).

## 4. Files changed on this checkpoint (relative to PR head `5ef37297`)

| File | State | Purpose |
|---|---|---|
| `app/Library/Assistant/AssistantTranscript.php` | NEW | The ONE provider-facing sanitizer. `AssistantTranscript::forProvider(AssistantMessage $row, int $limit): string` returns the constant `SERVER_RENDERED` placeholder (`[The server showed the customer a list of records. Its details are not repeated here.]`) when `payload['deterministic'] === true`, else `Str::limit(trim(body), $limit)`. |
| `app/Library/Assistant/AssistantPromptBuilder.php` | MODIFIED | `history()` now calls `AssistantTranscript::forProvider($row, 600)` instead of its inline placeholder/limit logic (behaviour unchanged). |
| `app/Library/Assistant/AssistantCompactor.php` | MODIFIED | The compaction transcript line now uses `AssistantTranscript::forProvider($m, 500)` instead of `Str::limit(trim($m->body), 500)`. This is the actual P1 fix. (`Str` is still imported and still used further down.) |
| `tests/Feature/Assistant/AssistantCompactionPrivacyTest.php` | NEW | The red regression for P1 (see section 8). **One assertion block still needs correcting** (section 9). |
| `docs/handoffs/ASK-MOTIONGROVE-PR460-HANDOFF.md` | NEW | This document. Do NOT carry it onto the PR branch. |

NOT changed (so P2 has not been started): `app/Library/Assistant/Tools/CreateAppointmentTool.php`, no appointment-preview test exists yet.

## 5. Fully implemented
- The shared sanitizer class and both call sites (history + compaction) — code complete and syntax-checked (`php -l`).
- A genuine failing reproduction for P1 existed and turned (partly) green after the fix (section 8).

## 6. Partially implemented
- `AssistantCompactionPrivacyTest`: the test is written and 19 assertions pass after the fix, but the LAST run ended in one failing
  assertion that is a TEST-DESIGN issue, not (as far as I could tell) a product bug — see section 9.
- Account for a possible follow-up: a summary created before this fix could still hold old data; the feature is unreleased so this was
  judged out of scope (mention in the PR reply if relevant).

## 7. Not started
- P2 (appointment preview) — no code, no test.
- Running the Assistant suite / the other required suites / the browser harness after the P1 fix.
- Commit/push to the PR branch, replies to both review threads, resolving threads.

## 8. Tests created and their exact outcomes so far
`tests/Feature/Assistant/AssistantCompactionPrivacyTest.php` (single test
`test_a_deterministic_reply_never_reaches_the_provider_or_the_summary_through_compaction_while_ordinary_context_does`). How it works: tenant
(Growth) with one unanswered inbound chat message whose text is `ZZSNIPPET-7731 can we move my fitting to Friday`, a contact whose
FIRST_NAME/LAST_NAME custom-field values are `Zephyrine`/`Quillfeather` (names live in `contacts_custom_field` + `contact_group_fields`,
NOT in `contacts` columns) and the chat box's `to` number as the phone; config `assistant.compaction_trigger_chars=50`,
`assistant.history_window_messages=2`; a custom `AiCompletionClient` (bound with `$this->app->instance`) records every
`AiCompletionRequest` and answers a compaction request by echoing its transcript as `SUMMARY OF: ...` (so the summary can only contain what
was sent); turns: (1) chip phrase "Show customers who haven't received a reply" (deterministic, no model call), (2) the permitted question
`ZZPERMITTED-4410 how much is the wedding package`, (3) "Thanks, that is all for now", (4) "One last thing please"; then assertions on every
request, the compaction transcript, the stored summary, and the customer-facing `show` response (still contains the full reply).

Run history (all on MySQL 8):
1. First run: error — `Unknown column 'first_name'` (contacts has no such column). Test fixed to use custom-field values.
2. **RED reproduction (before the sanitizer existed):** the preconditions passed (customer sees `Zephyrine` and `ZZSNIPPET-7731` in the
   deterministic reply; 0 AI calls for that turn; compaction ran through the gateway) and then the test FAILED with
   `'Zephyrine' must never be sent to the provider.` — i.e. the unique customer name reached the fake provider (haystack contained the
   compaction transcript). This is the genuine reproduction of P1.
3. After adding `AssistantTranscript` + wiring history and compaction: error `Cannot modify readonly property AiCompletionRequest::$messages`
   (test bug: `end($request->messages)`), 18 assertions had passed. **Already corrected in the working tree** (lines 132–133 now copy to
   `$compactionMessages` first).
4. LAST run: 19 assertions passed, then 1 failure: `Failed asserting that 'USER: Show customers who haven't received a reply\n...` (a
   `assertStringContainsString` whose haystack is the FIRST compaction transcript). See section 9. No further runs were made.

Previously verified state of the PR branch (`5ef37297`, nothing regressed by the uncommitted work as far as is known, but NOT re-run since):
Assistant 116 tests / 909 assertions / 0 failures; Ai 187 (1 baseline failure); Dashboards 255 pass; Security 502 (1 baseline failure);
`PlatformAccountDeletionTest` 20 pass; browser harness 120 checks / 0 failed.

## 9. The pending test fix — exactly where and how

The `end()`-on-readonly error is ALREADY FIXED (lines 132–133). The remaining failure is in the block at about lines 132–136:

```php
$compactionMessages = $compactions[0]->messages;
$transcript = (string) end($compactionMessages)['content'];
$this->assertStringContainsString('ZZPERMITTED-4410', $transcript);          // line ~134  <- almost certainly the one that fails
$this->assertStringContainsString('The server showed the customer a list of records', $transcript, '...');
$this->assertStringContainsString('ZZPERMITTED-4410', $summary);
```

Diagnosis (reasoned, not yet re-verified by a run): with `history_window_messages=2`, the FIRST compaction happens on turn 3 and folds only
the oldest two messages (the chip question + the deterministic reply). The permitted question is folded by a LATER compaction (turn 4).
So `$compactions[0]` legitimately contains the placeholder but NOT `ZZPERMITTED-4410`. Fix the TEST, not the product:
build one string from ALL compaction requests' last-message contents and assert (a) the placeholder text appears in it, (b) `ZZPERMITTED-4410`
appears in it, (c) none of the secrets appear in it; assert `ZZPERMITTED-4410` is in the final stored summary (the echo provider keeps the first
800 chars of "PREVIOUS SUMMARY + new transcript"; the compactor stores at most `assistant.summary_max_chars`=900 chars — if the marker is cut off,
shorten earlier turns/reorder so it sits early). If after that fix any secret assertion fails, THEN it is a product bug — investigate
`AssistantCompactor`/`AssistantTranscript`.

Also confirm the red reproduction is still meaningful: temporarily revert only the compactor line to `Str::limit(trim((string) $m->body), 500)`
and see the test fail on the secret assertions; restore it.

## 10. Remaining P2 (appointment preview) — plan, nothing written yet
Edit `app/Library/Assistant/Tools/CreateAppointmentTool.php::prepare()` (the `$notifies` computation ~lines 78–88 and the `effects`/`recipients`
arguments ~lines 100–103):
- Remove the claim `'This booking type sends the customer a confirmation by ...'`. `recipients` must ALWAYS be `[]` (nobody is contacted by this
  action; the UI shows "Who will be contacted: …" only when non-empty — `public/js/home/ask-assistant.js` ~line 163).
- Effects text (suggested): keep the calendar-booking line; replace the second with an accurate sentence, e.g. when the type has email/SMS
  confirmations enabled: "This service sends automatic confirmations for online bookings, but not for bookings made here, so
  <customer name> will NOT be sent one automatically — let them know yourself."; when neither is enabled: "No confirmation is sent to the
  customer by this action." Wording is yours; the tests must pin the MEANING (no promise of email/text; explicit "no automatic confirmation").
- Optional, recommended: drop `$type->notifiesByEmail(), $type->notifiesBySms()` from the `fingerprint()` hash (~line 132), since the preview no
  longer depends on them (otherwise toggling a notice flag makes a prepared proposal go stale for no reason). Unreleased feature, no migration.
- Do NOT touch `app/Library/Calendar/*` or `execute()`.
- New test `tests/Feature/Assistant/AssistantAppointmentPreviewTest.php`: reuse the fixture pattern from
  `AssistantAppointmentSideEffectsTest` (Growth tenant, a contact with phone `15557770000` in the business Location, propose
  `create_appointment` with `{contact:'15557770000', date:'2026-09-14', time:'10:00'}` through the fake model — frozen clock is 2026-09 in
  `AssistantTestSupport::bootAssistant`). For each combination email-only / SMS-only / both / neither, set the flags on the booking type(s)
  (`booking_types.notify_email` / `notify_sms`, accessors `BookingType::notifiesByEmail()` default true, `notifiesBySms()` default false — verify
  the exact column names in the migration), prepare, and assert: `recipients === []`; effects never say the customer will get an email/text
  confirmation; effects state that no automatic confirmation is sent; then approve and assert `Mail::assertNothingSent()`,
  `Notification::assertNothingSent()`, `appointment_notifications` count 0 (so preview == reality). Write it RED first (it fails against the
  current promise for email-only/SMS-only/both), then fix.
- Also adjust/confirm `AssistantAppointmentSideEffectsTest` still passes and `docs/product/ASK-MOTIONGROVE-V1.md` ("Appointment booking side
  effects" section) is consistent (it already says no confirmation is sent).

## 11. Environment, processes and restrictions (old machine — will NOT exist for you)
- Cloud container; Docker unavailable. A disposable MySQL **8.0.46** was run from a manually extracted package under `/tmp/claude-0/my8`
  (obtained with `apt-get download` + `dpkg -x` of `mysql-server-core-8.0`, `libevent-pthreads`, `libprotobuf-lite`, `libaio`), listening on
  `127.0.0.1:3308`, database `ultimatesms_testing`, `--secure-file-priv=NULL`. MariaDB is NOT acceptable (JSON casts etc.). It died whenever the
  worker restarted; helper `/tmp/claude-0/startmysql.sh` restarted it. Those `/tmp` scripts are NOT preserved. At the time of this handoff a
  `mysqld` process (pid ~236) was still running on the old machine — irrelevant to you. You must create your own disposable MySQL 8 and obey
  `AGENTS.md` / `Tests\Support\TestDatabaseSafety` (DB must be `ultimatesms_testing` or a validated disposable sibling; never a production-looking DB).
- Env used for every phpunit run (values for the disposable instance; credentials are deliberately NOT recorded here):
  `APP_ENV=testing APP_KEY=<throwaway base64 key> DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3308 DB_DATABASE=ultimatesms_testing DB_USERNAME=<test user> DB_PASSWORD=<test password> EXPECTED_TEST_DATABASE=ultimatesms_testing`.
- PHP 8.3 needs `php8.3-bcmath` (install it if `bccomp()` is undefined). phpunit.xml sets `CACHE_DRIVER=array`.
- Tool gotchas learned the hard way: (1) every phpunit process starts with `migrate:fresh` (~60 s) — run long commands in the background with `nohup` and poll;
  never `pkill -f <pattern>` (it kills your own shell) — use `pgrep -f '^php vendor/bin/phpunit'` and kill by pid; (2) never run two DB-writing processes at once;
  (3) the Assistant tests freeze the application clock, so `Cache::lock(...)->block()` would wait forever in tests — use
  `AssistantRetention::acquireWithin()` (real monotonic clock) for bounded lock waits; (4) do not use `DatabaseTruncation`/`DatabaseMigrations`
  in tests (they wipe seeded catalog data for later tests in the same process); (5) a stop hook nags about uncommitted changes — ignore until verified.
- Browser harness (`tests/Browser/AskMotionGrove`, Playwright + Chromium at `/opt/pw-browsers/chromium`, `NODE_PATH=/opt/node22/lib/node_modules`):
  seed (commits rows, disposable DB only): `vendor/bin/phpunit tests/Browser/AskMotionGrove/SeedBrowserFixtures.php`; serve the scripted fake provider:
  `php -S 127.0.0.1:8099 -t public tests/Browser/AskMotionGrove/router.php` (same env as the tests); run from the harness dir:
  `MYSQL_CMD="mysql -h127.0.0.1 -P3308 -u<user> -p<pw> ultimatesms_testing" CHROMIUM_PATH=/opt/pw-browsers/chromium node browser.js` (writes `results.json`, delete it;
  `qa.json` and `shots/` are git-ignored). Stop the server by the pid shown by `ss -ltnp | grep :8099`. Last good result at `5ef37297`: 120 passed / 0 failed.

## 12. Exact next steps (in order)
1. `git fetch origin && git checkout handoff/ask-motiongrove-pr460` (this branch); confirm `git diff --stat origin/integration/ask-motiongrove-v1` shows only the files in section 4.
   Confirm `origin/integration/ask-motiongrove-v1` is still `5ef37297` (`git rev-parse origin/integration/ask-motiongrove-v1`); if it moved, read the new commits first.
2. Start a disposable MySQL 8 and export the env (section 11). Run `vendor/bin/phpunit tests/Feature/Assistant/AssistantCompactionPrivacyTest.php` and see the current failure.
3. Fix the test per section 9; make it green; prove the red by temporarily reverting the compactor line, then restore.
4. P2: write `AssistantAppointmentPreviewTest` RED (section 10), then change `CreateAppointmentTool::prepare()` (+ optional fingerprint change); make it green.
5. Run, SEQUENTIALLY, each in the background and one at a time: focused new tests → `tests/Feature/Assistant` (expect 116 + new tests, 0 failures) →
   `tests/Feature/Ai` (expect 187, only the baseline failure) → `tests/Feature/Dashboards` (255) → `tests/Feature/Security` (expect only the baseline failure) →
   `tests/Feature/PlatformOwner/PlatformAccountDeletionTest.php` (20) → the browser harness (expect 120/0).
6. Compare every failure with the baseline (section 13b). Do not fix baseline failures. Any NEW failure is yours.
7. Transfer to the PR branch and push (section 13). 8. Reply to BOTH threads and resolve them only after verification (section 14).
9. Final report: SHA, exact test counts, unresolved findings (should be none), exact merge readiness. STOP (no merge/deploy).

## 13. Transferring the verified corrections back to `integration/ask-motiongrove-v1`
```
git fetch origin
git checkout integration/ask-motiongrove-v1          # must be at 5ef37297 (or the newer head if it moved; then merge, don't discard)
git checkout handoff/ask-motiongrove-pr460 -- \
  app/Library/Assistant/AssistantTranscript.php app/Library/Assistant/AssistantPromptBuilder.php app/Library/Assistant/AssistantCompactor.php \
  app/Library/Assistant/Tools/CreateAppointmentTool.php \
  tests/Feature/Assistant/AssistantCompactionPrivacyTest.php tests/Feature/Assistant/AssistantAppointmentPreviewTest.php
# (add any other file you changed; NEVER include docs/handoffs/*)
git status --short && git diff --cached --check
git commit -m "PR #460 review: redact deterministic replies before compaction; accurate appointment preview ..."   # + harness attribution lines
git push origin integration/ask-motiongrove-v1
```
Re-run the focused tests on the integration branch after the checkout (the files are identical, but verify). Optionally delete the checkpoint branch
only if the user agrees (`git push origin --delete handoff/ask-motiongrove-pr460`).

### 13b. Established staging baseline (identical failures on untouched `origin/staging` @ `3a5296b6`; 32 tests) — do NOT fix
Ai 1: `AiRequestPlatformScopeTest::test_all_three_construction_sites_remain_source_unchanged`. Security 1:
`DashboardInvoiceScopeTest::test_no_rendered_response_contains_another_tenants_invoice_totals`. CustomerShell 2:
`CustomerShellAccountProfileCleanupTest::test_core_and_growth_are_never_offered_an_account_home_hop`,
`SettingsHubNavigationTest::test_core_and_growth_get_a_flat_sidebar_with_one_direct_settings_destination`. Navigation 3 (query-count tests +
`TopBarAccountMenuTest::test_the_unread_dot_follows_the_real_unread_state`). Coo 6 (`BusinessSignalReaderTest` 1, `ExplainThisChangeTest` 5).
Entitlement 19 (18 errors in `EntitlementManagerBusinessSlotCapacityTest`/`EntitlementManagerConcurrencyTest`/`WorkspaceEntitlementBackfillV1Test`,
1 failure `NoRawEntitlementTableQueryTest`).

## 14. Replying to / resolving the GitHub review threads
Use the GitHub MCP tools (there is no `gh` CLI): `mcp__github__add_reply_to_pull_request_comment` with `commentId` `4268668384` (P1) and `4268668395` (P2),
`pullNumber` 460, owner `os-creator1`, repo `os-ai`; each reply states the correction SHA (the commit pushed to `integration/ask-motiongrove-v1`),
what was reproduced first, the fix, and the test evidence, and ends with the footer `---` / `_Generated by [Claude Code](https://claude.ai/code)_`.
Then `mcp__github__resolve_review_thread` with threadId `PRRT_kwDOTnPFFc6sO-VG` and `PRRT_kwDOTnPFFc6sO-VM`. Resolve only after verification.
Earlier threads (`PRRT_kwDOTnPFFc6sM6cY`, `…sM6cg`, `…sM6cp`, `…sM6cx`, `…sOYSY`) are already resolved and must stay so.
Load the MCP tools first with ToolSearch (`select:mcp__github__pull_request_read,mcp__github__add_reply_to_pull_request_comment,mcp__github__resolve_review_thread`).

## 15. Things already done on the PR branch that must stay intact (the "five previous corrections" + integration)
Upgrade reconcile (`AiUsageLedgerManager::reconcileUpgradedCaps`, opt-in refusal supersession in `AiUsageReadModel`, `AiAllowanceReader`); view-as approval refusal in
`AssistantActionService::revalidate`; first-message idempotency + conversation cap resolve step in `AssistantService::send` (lock `assistant:resolve:<sha1>`,
`AssistantRetention::acquireWithin`); reply correlation (`hasAnswer`); account-deletion integration (`AccountRecordGraph::USER_OWNED` lists
`assistant_action_proposals.user_id` and `assistant_conversations.user_id`); scheduler `assistant:purge-expired`; browser harness cases (notice, deletion, retry, upgrade, cap).
