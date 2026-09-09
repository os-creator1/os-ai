# Mainline theme-asset publication remediation

A clean checkout of `main` could not render a single authenticated page.
`resources/views/panels/scripts.blade.php` threw

```
Illuminate\Foundation\MixFileNotFoundException:
Unable to locate Mix file: /js/core/theme-tokens.js.
```

on every request that reached it, which is why the Security suite alone
produced **108 errors**. The admin theme-settings page failed the same way
on a different asset.

This branch publishes the two first-party compiled assets that were
missing, and repairs the build that could not produce them.

**This document does not claim the front-end pipeline is repaired.** It
claims exactly what §6 proves: two assets are published, the build runs,
and the suites that were failing on those two assets pass. §7 records what
is still wrong.

---

## 1. Root cause

Two layers, both mechanically verified at `origin/main`
`559a8200de6d0e66bef6a0397868774303073b3c`.

### 1.1 The outputs were regenerated locally and deliberately thrown away

This repository **tracks its compiled front-end output**: 1 294 files under
`public/`, including `public/mix-manifest.json`, 62 CSS and 26 JS files.
Only `/public/hot` and `/public/storage` are ignored. A clean checkout is
supposed to be servable without a build step.

`docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-COUNT-CONTACTS-EXCEPTION.md`
§7 nevertheless told lanes to treat the theme assets as a **local**
prerequisite:

> `npx mix --production` (regenerates the local `public/mix-manifest.json`
> with its `/js/core/theme-tokens.js` entry).

and then, two items later:

> All generated `public/` and `bootstrap/cache/` artifacts must again be
> reverted (`git checkout -- public/ bootstrap/cache/` …) **before** that
> future commit […] no `public/` […] change of any kind.

The revert discipline is right for build churn. Applied without an
exception for genuinely *new* outputs, it deleted the two assets those
features had just added — every time, for every lane. Each developer's
working tree was correct; the repository never was.

### 1.2 The build could not have produced them anyway

On `559a8200`, `npm ci` succeeds and **`npm run production` aborts before
compiling anything**. Two independent incompatibilities, each re-derived
here by probing published versions rather than assumed:

| Failure | Cause | Boundary |
|---|---|---|
| `Cannot find module 'webpack/lib/SizeFormatHelpers'` | `laravel-mix@6.0.49`'s `BuildOutputPlugin.js:6` requires that path; webpack renamed it to `lib/util/formatSize.js` | present through **5.106.2**, gone from **5.107.0** |
| `Progress Plugin has been initialized using an options object that does not match the API schema` (`name`, `color`, `reporter`, `reporters`) | `webpackbar@5.0.2`, itself a `laravel-mix` dependency, subclasses webpack's `ProgressPlugin` with its own options | accepted through **5.105.4**, rejected from **5.106.0** |

`package.json` constrained only `laravel-mix: ^6.0.6` and never webpack,
so `package-lock.json` had drifted to webpack **5.109.2**. The effective
ceiling for `laravel-mix@6.0.49` — which is the **latest laravel-mix
release overall**, so upgrading past the problem is not available — is
therefore **5.105.4**.

**The fix:** one narrow constraint on the transitive dependency.

```json
"overrides": {
    "webpack": "~5.105.0"
}
```

`package-lock.json` regenerated under it changes **exactly one package
version** — `webpack 5.109.2 → 5.105.4` — plus the five transitive
packages the older webpack swaps in (5 added, 5 removed; 69 lines added,
127 removed). Committing the dependency pair is unavoidable: without it a
clean checkout still cannot build, so the outputs below could not be
"produced by the current build configuration" as required.

---

## 2. Every missing source → rule → output → manifest relationship

All 91 required asset references were cross-checked — the 90 distinct
`mix()` keys in tracked Blade and PHP, plus the one key the fresh-manifest
helper reads directly. **Exactly two** were broken, and both were broken
in the same way: correct tracked source, correct compilation rule, no
published output, no manifest entry.

| Required key | Tracked source | `webpack.mix.js` rule | Output at `559a8200` | Manifest at `559a8200` | Referenced by |
|---|---|---|---|---|---|
| `/js/core/theme-tokens.js` | `resources/js/core/theme-tokens.js` (52 lines) | line 71, `.js('resources/js/core/theme-tokens.js', 'public/js/core')` | **absent** | **absent** | `resources/views/panels/scripts.blade.php` lines 28–42, via the fresh-manifest helper |
| `/js/scripts/pages/theme-settings.js` | `resources/js/scripts/pages/theme-settings.js` (363 lines) | line 53, `mixAssetsDir('js/scripts/**/*.js', (src, dest) => mix.scripts(src, dest))` | **absent** | **absent** | `resources/views/admin/theme-settings/index.blade.php` line 239, via `mix()` |

The other 89 keys all resolve: manifest entry present, target file present
on disk, target file tracked.

`panels/scripts.blade.php` is the only place in the repository that reads
the manifest itself rather than calling `mix()`. It does so deliberately —
`Illuminate\Foundation\Mix::__invoke()` caches the decoded manifest in a
`static $manifests` array for the life of the PHP process, so a process
whose first lookup predates a rebuild keeps serving a stale snapshot. That
helper is **not** weakened here: it still throws under `APP_DEBUG`, still
`report()`s otherwise, and no `APP_DEBUG` bypass was added. The tracked
tree simply now contains what it asks for.

---

## 3. What is committed, and why it belongs in version control

| Path | Why |
|---|---|
| `public/js/core/theme-tokens.js` | Required by every authenticated render. 676 bytes |
| `public/js/scripts/pages/theme-settings.js` | Required by the admin theme-settings page. 7 768 bytes |
| `public/mix-manifest.json` | **Two added lines only** — the exact key/value pairs the build emits, inserted at the positions mix itself places them |
| `package.json`, `package-lock.json` | Without the webpack constraint a clean checkout cannot build at all (§1.2) |
| `tests/Feature/Assets/ThemeAssetPublicationTest.php` | The guard that was missing (§5) |
| `docs/automation/MAINLINE-THEME-ASSET-PUBLICATION-REMEDIATION.md` | This document |

They belong in version control because this repository's deployment model
already puts compiled output there — 1 294 tracked files under `public/`,
with `public/mix-manifest.json` itself tracked. These two assets are the
only first-party compiled outputs that were missing from that set. Nothing
about the model is being changed; a gap in it is being closed.

Cache-busting semantics are preserved exactly: `mix.version()` is
commented out in `webpack.mix.js`, so the manifest maps every key to
itself with no hash or query string, and the two added entries follow that
same form.

---

## 4. Deterministic-build evidence

Three production builds, `npm ci` from the regenerated lock each time.

| Build | Starting state |
|---|---|
| #1 | this worktree, `public/` exactly the tracked tree |
| #2 | same worktree, `public/` restored to the tracked tree with `git checkout -- public/ && git clean -fd public/` |
| #3 | a **separate worktree** checked out fresh at `559a8200`, carrying over only `package.json` and `package-lock.json` — source inputs, never a generated file |

| Output | Source | Rule | SHA-256 — builds #1, #2 **and** #3 | Bytes |
|---|---|---|---|---|
| `public/js/core/theme-tokens.js` | `resources/js/core/theme-tokens.js` | `webpack.mix.js:71` | `2e9e037c1fec20445942d27675344bae73fcb2045098a03065d65eb227544549` | 676 |
| `public/js/scripts/pages/theme-settings.js` | `resources/js/scripts/pages/theme-settings.js` | `webpack.mix.js:53` | `5a1fb6028eeba658f436d8eff47b5131419113747c772881b1d55f01404b37ee` | 7 768 |

Identical across all three, byte for byte. The manifest agreed too: builds
#1 and #2 produced the same 1 187 keys in the same order with identical
values, and build #3 emitted the same two entries.

Neither output contains an absolute local path, a machine name, or a
`sourceMappingURL`; the build emitted **no** `.map` files anywhere.

---

## 5. Build pollution found and excluded

A full production build leaves **534** paths dirty. Every one was
classified individually — modified files by comparing against their `HEAD`
blob with carriage returns stripped, so line-ending churn is separated from
real content change.

| Class | Count | Disposition |
|---|---|---|
| Required new output | **2** | **committed** — `theme-tokens.js`, `theme-settings.js` |
| New but not required — `public/fonts/geist/{LICENSE, geist-latin-wght-normal.woff2, geist-latin-ext-wght-normal.woff2}` | 3 | excluded, see §7.1 |
| Line-ending-only churn | 15 | restored |
| Genuine content churn, third-party re-minification (`public/vendors/**`) | 443 | restored |
| Genuine content churn, regenerated CSS (`public/css/**`, `public/css-rtl/**`) | 49 | restored, see §7.1 |
| Genuine content churn, regenerated JS (`public/js/**`, excluding the two new files) | 22 | restored |
| `bootstrap/cache/packages.php`, `services.php` | 2 | regenerated during verification, restored before commit per `AGENTS.md`; see §7.2 |

### 5.1 The manifest was not committed wholesale

A regenerated manifest differs from the tracked one by **+10 / −2**, and
only two of those additions are this branch's:

* **+3** Geist font entries would point at `public/fonts/geist/*`, which is
  **untracked** — committing the regenerated manifest would introduce three
  *new* dangling entries.
* **+5** entries for `/images/branding/default-*.svg` and two
  `/images/logo/*` files: already tracked, unrelated to this fix.
* **−2** would drop `/js/scripts/customizer.js` and
  `/images/backgrounds/plugin.svg`. Both are **already dangling on `main`
  today** — neither has a source, a tracked `public/` file, or a file on
  disk — but removing them is unrelated churn and is left alone (§7.3).

So the tracked manifest received exactly the two key/value pairs the build
emits, verbatim, at the positions mix itself places them: `theme-tokens.js`
immediately after `/js/core/app-menu.js`, `theme-settings.js` immediately
after `/js/scripts/pages/content-sidebar.js`. The committed manifest
contains **no** runtime-upload entry and **no** dangling target introduced
by this branch.

### 5.2 No runtime uploads were swept in

`mix.copyDirectory('resources/images', 'public/images')` indexes its own
destination, so a build run after a test suite sweeps uploaded files into
the manifest. Every build here started from a `public/` verified equal to
the tracked tree (0 untracked, 0 modified), and the resulting manifests
carried **0** entries under `/images/websites/` or
`/images/branding/logo*/`. `ThemeAssetPublicationTest` now asserts this
permanently.

---

## 6. Verification

Database: **`ultimatesms_testing_theme`**, an isolated sibling accepted by
`Tests\Support\TestDatabaseSafety` and owned by no other lane. Reset with
`migrate:fresh`: **250 migrations ran, 0 pending**.

### 6.1 Focused suites

Each of these was failing on `main` purely through the missing assets.

| Suite | Result |
|---|---|
| `tests/Feature/Assets/ThemeAssetPublicationTest` *(new)* | 7 tests, 34 assertions, **green** |
| `tests/Feature/Security` | 172 tests, 1 302 assertions, **green** — **108 errors on `main`** |
| `tests/Feature/Theme` (incl. admin theme-settings render) | 85 tests, 477 assertions, **green** |
| `tests/Feature/Auth` login/theme render | 9 tests, 15 assertions, **green** |
| `tests/Feature/Dashboards` (authenticated customer pages) | 14 tests, 41 assertions, **green** |
| `tests/Feature/Settings` | 57 tests, 172 assertions, **green** |

### 6.2 A second blocker, surfaced not caused

Once the Mix exception stopped short-circuiting rendering, every rendering
suite went red again with HTTP 500 and

```
Unresolvable dependency resolving [Parameter #1 [ <required> string $manifestPath ]]
in class BladeUI\Icons\IconsManifest
```

— 37 failures in Security, 14 in Theme, 8 in Dashboards, 5 in Auth. The
cause is §7.2: the tracked `bootstrap/cache/packages.php` does not list
`blade-ui-kit`. `php artisan clear-compiled && php artisan package:discover`
clears it and all four suites pass, as recorded in §6.1. Those cache files
are excluded from this commit and restored to `HEAD`.

### 6.3 Full suite

Run on `ultimatesms_testing_theme` after `migrate:fresh`:

| | |
|---|---|
| Tests | **5 185** |
| Assertions | **26 288** |
| Errors | **2** |
| Failures | **19** |

**This is not a green suite, and this branch does not claim one.** For
comparison, `main`'s own full run produces roughly **970 errors**, almost
all of them the missing theme asset. Every one of the 21 remaining
problems is classified below, and none is caused by this branch.

| Group | Count | Evidence |
|---|---|---|
| Isolated-database family | 10 failures + 1 error | 8 `EntitlementManagerConcurrencyTest`, `WorkspaceManagerConcurrencyTest`, `WorkspaceManagerTest`, plus the `WorkspaceTransitionsMigrationSchemaTest` error. Each names its own cause — *"resolved database is [ultimatesms_testing_theme], expected [ultimatesms_testing]"* — because `Workspace/Support/TemporaryTestDatabase.php` and the Entitlement runner pin the literal canonical name. `AGENTS.md` itself records that `TemporaryTestDatabase` still does |
| Pre-existing, reproduced identically | 7 failures | Re-run with this branch's two assets removed and the manifest reverted: **the same seven test names fail**, byte-for-byte the same set — `BrandingUploadValidationTest::test_a_valid_png_logo_upload…`, `BusinessKnowledgeProfileHoursTest::test_no_change_row_for_a_true_hours_no_op`, `BusinessKnowledgeProfileSeamTest::test_only_the_manager_writes_the_tracked_tables`, `WebsiteIndexingTest::test_robots_txt_is_untouched…`, `WebsiteDraftPageServiceSeamTest::test_store_page…` and `::test_update_page…`, `WebsiteDraftPublishTest::test_rollback_repoints…` |
| Pre-existing, previously **masked** | 2 failures | `BrandingAdminFooterRenderTest::test_admin_footer_renders_the_company_name_exactly_once_with_no_login_link` (footer name appears 0 times, expected 1) and `BusinessKnowledgeProfileControllerTest::test_missing_stale_and_present_fields_are_all_displayed`. Both **errored** at the base asset state and never reached an assertion; the fix lets them run, and they fail on their own content assertions, which have nothing to do with theme assets |
| Pre-existing error | 1 error | `OpportunityManagerBeginRunTest::test_heartbeat_one_second_past_the_timeout_cutoff_is_abandoned` — `RunAlreadyActiveException` |

### 6.4 The controlled comparison

Eight test files covering all nine content failures, run twice on the same
machine and database, differing only by this branch's diff:

| | with the fix | assets removed, manifest reverted |
|---|---|---|
| Tests | 84 | 84 |
| Assertions | 3 942 | 3 894 |
| **Errors** | **0** | **21** — every one `MixFileNotFoundException` |
| Failures | 9 | 7 |

The seven baseline failures are identical by name in both columns. The two
extra failures in the left column are two of the twenty-one tests that
errored in the right column. So the fix removes 21 errors, lets 12
previously-erroring tests pass, and turns **no** passing test red.

### 6.5 Environment protection

| | |
|---|---|
| `.env` before | md5 `3fe3b8f6e1e91967425b8ea07c19b212`, 1 760 bytes |
| `.env` after the suites | md5 `531c9e5e372ee3ed969b3f3c49bdf797`, 1 703 bytes — **rewritten** |
| Restored | byte-identical to the snapshot before the final audit |

The suite rewrote three keys — `NOCAPTCHA_SITEKEY`, `FACEBOOK_CLIENT_ID`,
`APP_TIME_FORMAT` — and dropped the file's trailing newline. That is the
known `AppConfig::setEnv()` defect, which resolves `base_path('.env')`
where no test seam redirects it. It is a **separate remediation** and this
branch deliberately does not expand into that production-code change.

The file affected is this worktree's **own gitignored copy**. The
developer's `.env` lives in the main checkout and the other worktrees and
was never opened by this lane. No credential, database, preview
configuration, cache file or runtime upload was modified or committed, and
only processes this lane started were run.

---

## 7. Remaining asset-pipeline risk

Three findings that this branch deliberately does **not** fix. Each is
real, each is recorded with its evidence, and none of them causes a
`MixFileNotFoundException` or a failing test.

### 7.1 The committed CSS predates the bundled Geist font

`resources/scss/base/tokens/_typography.scss` declares two `@font-face`
blocks for `Geist Variable`, and `resources/fonts/geist/` is tracked (3
files). A fresh build emits those faces into `public/css/core.css` as
`url(/fonts/geist/geist-latin-wght-normal.woff2)` and its `latin-ext`
sibling, and copies the WOFF2 files to `public/fonts/geist/`.

**The committed `public/css/core.css` contains zero `url(...geist...)`
references, and `public/fonts/geist/` is untracked.** So the bundled Geist
option — an accepted value of `font_bundled_key` in
`UpdatePlatformThemePresetRequest` — cannot work in a clean checkout.

It is the same defect class as §2, but fixing it means committing the
regenerated CSS: 49 files of genuine content churn, far outside this
branch's stated scope. Recorded here as the next change.

### 7.2 `bootstrap/cache/*.php` are tracked and stale

The committed `bootstrap/cache/packages.php` does not mention
`blade-ui-kit`. Once the Mix exception stopped short-circuiting rendering,
that staleness surfaced immediately as HTTP 500s —
`Unresolvable dependency resolving [Parameter #1 [ <required> string $manifestPath ]] in class BladeUI\Icons\IconsManifest`
— across the Security, Theme, Auth and Dashboard suites.
`php artisan clear-compiled && php artisan package:discover` clears it, and
`composer install` runs `package:discover` through `post-autoload-dump`, so
a real deployment regenerates these files and is unaffected. The staleness
bites only a checkout that runs tests against a tracked cache without a
dependency install. These files are excluded from the commit by this
lane's contract and restored to `HEAD`.

### 7.3 Two manifest entries on `main` are already dangling

`/js/scripts/customizer.js` and `/images/backgrounds/plugin.svg` have no
source, no tracked `public/` file and no file on disk, yet both have
manifest entries. Nothing calls `mix()` for either, so neither breaks a
render today. They are left exactly as `main` has them rather than
silently removed as part of an unrelated change.

### 7.4 What this branch does not establish

`public/js/core/app.js` as committed contains **no** `PlatformTheme`
reference, while `resources/js/core/app.js` contains two. The committed JS
is therefore stale against its own source in at least that one place, as
the 22 regenerated-and-restored JS files in §5 suggest more broadly. This
branch publishes two missing assets and repairs the build; it does not
audit or re-publish the rest of the compiled tree, and nothing here should
be read as saying that tree is current.
