# Browser walkthroughs (opt-in)

These drive the real wizard in headless Chromium. They are **not** part of the PHPUnit
suites or CI; run them by hand against a disposable test database.

1. Point the app at a disposable database accepted by `Tests\Support\TestDatabaseSafety`
   (`ultimatesms_testing`), run `php artisan migrate`, and serve it, e.g.
   `NOCAPTCHA_IN_LOGIN=false php -S 127.0.0.1:8011 -t public server.php`.
2. Seed the fixture (commits one disposable customer + Photo Booth business and writes
   credentials to a file):
   `WIZARD_E2E_SEED=1 WIZARD_E2E_OUT=/tmp/fixture.json vendor/bin/phpunit tests/Feature/Website/E2e/WizardBrowserFixtureTest.php`
3. Run a walkthrough (needs `playwright-core` resolvable via `PW_MODULE_DIR`, and the
   Chromium that ships with Playwright):
   `PW_MODULE_DIR=/path/to/node_modules WIZARD_E2E_OUT=/tmp/fixture.json node tests/e2e/wizard-navigation.mjs`

Each script prints PASS/FAIL per check and exits non-zero on any failure.
