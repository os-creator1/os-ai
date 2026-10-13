# Ask MotionGrove — browser acceptance

Deterministic Launch / Growth / exhausted-trial scenarios on desktop (1280x900) and mobile (390x844), driven by Playwright
against the real Home page. **No real AI provider is called**: `router.php` swaps `AiCompletionClient` for a scripted client.
Use the disposable test database only (`ultimatesms_testing`, see `AGENTS.md`); never run it beside another database-writing lane.

```sh
vendor/bin/phpunit tests/Browser/AskMotionGrove/SeedBrowserFixtures.php        # writes qa.json (commits rows)
php -S 127.0.0.1:8099 -t public tests/Browser/AskMotionGrove/router.php &       # same env as the tests (APP_ENV=testing, test DB)
CHROMIUM_PATH=/opt/pw-browsers/chromium node tests/Browser/AskMotionGrove/browser.js
```

`browser.js` asserts: Ask directly beneath the greeting; contextual chips; no model call on load; answers built from real
module facts; honest handling of missing data; clarifying question; action preview, explicit confirm, real result (and a
double-click that does not duplicate); refresh resumes the conversation; the exhausted-trial message with the real
*Upgrade plan* link while non-AI pages keep working; the first-use AI-data notice; the two-step *Delete conversation* (nothing left to resume, and the server returns no messages afterwards); the existing Home cards still present; no horizontal overflow on
mobile; no console errors and no 5xx. Screenshots land in `shots/`. `qa.json` and `shots/` are git-ignored artefacts.
