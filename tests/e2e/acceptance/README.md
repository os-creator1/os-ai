# Contacts / Conversations / Calling — authenticated-browser acceptance scripts

These drive the **real application** in Chromium (Playwright) against a locally served copy seeded with a
realistic tenant. They are manual acceptance tools, not part of CI, and they never contact a real provider.

1. Use a disposable database (`ultimatesms_testing` or a validated sibling, see `AGENTS.md`), migrated.
2. Copy `SeedAcceptanceFixture.php.stub` to `tests/_dev/SeedVisualFixtureTest.php` (git-excluded) and run it with
   phpunit against that database; it writes `/tmp/seed.json` (logged-in session cookie, workspace/business uids).
3. Serve the app with that database and calling switched on **with fixture values only**:
   `VOICE_CUSTOMER_CALLS_ENABLED=true VOICE_BROWSER_CALLS_ENABLED=true TELNYX_MODE=live TELNYX_API_KEY=fixture
   TELNYX_VOICE_CONNECTION_ID=conn_fixture TELNYX_WEBRTC_CONNECTION_ID=webrtc_fixture TELNYX_WEBHOOK_PUBLIC_KEY=<base64>
   TELNYX_VOICE_WEBHOOK_URL=https://app.example.test/wh PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8000 -t public server.php`
   (several workers, or the race script is not concurrent).
4. Run: `OUT_DIR=./out node tests/e2e/acceptance/<script>.cjs`

| Script | Proves |
|---|---|
| `contacts-conversations-journeys.cjs` | create contact (country/number conflict error, valid LT), search, group filter, All/SMS/Email switching, a real reply attempt reports the provider's true outcome |
| `call-continuity.cjs` | the shell owns the call: the JS realm, SDK call and timer survive two in-app navigations; minimize/restore, keypad, hold, hang-up; the number chooser |
| `wrapup-race.cjs` | 12 parallel wrap-up submits append the note exactly once (needs a finished call row; see the script) |
| `screenshot.cjs` | `node screenshot.cjs app <name> <path> <w> <h>` — authenticated page screenshot (adds `mgframe=0` unless `SHELL_MODE=1`) |

Telnyx itself is **not** reachable from these scripts: the call API and the SDK are answered by the script, so they prove the
browser architecture and UI, not a live call. A live call needs a supervised test with a real Telnyx connection.
