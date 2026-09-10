# Mainline theme-asset publication remediation

A clean checkout of `main` could not render a single authenticated page,
and could not render the application in its own typeface.

```
Illuminate\Foundation\MixFileNotFoundException:
Unable to locate Mix file: /js/core/theme-tokens.js.
```

was thrown by `resources/views/panels/scripts.blade.php` on every request
that reached it — 108 errors in the Security suite alone. Underneath that,
`public/css/core.css` predated the Geist work entirely: no `@font-face`
rule at all, `Montserrat` still declared as the primary Bootstrap font, and
the two self-hosted WOFF2 files it should have pointed at never published.

This branch publishes every first-party compiled asset that was missing,
repairs the build that could not produce them, and gives the repository a
stable package identity so its lockfile stops recording whichever directory
someone happened to install in.

**This document does not claim the whole front-end pipeline is repaired.**
It claims exactly what §6 proves. §7 records what is still wrong.

---

## 1. Root cause

Three layers, all mechanically verified.

### 1.1 The outputs were regenerated locally and deliberately thrown away

This repository **tracks its compiled front-end output**: ~1 294 files under
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
exception for genuinely *new* outputs, it deleted the assets those features
had just added — every time, for every lane. Each developer's working tree
was correct; the repository never was.

### 1.2 The build could not have produced them anyway

At `559a8200`, `npm ci` succeeds and **`npm run production` aborts before
compiling anything**. Two independent incompatibilities, each re-derived by
probing published versions rather than assumed:

| Failure | Cause | Boundary |
|---|---|---|
| `Cannot find module 'webpack/lib/SizeFormatHelpers'` | `laravel-mix@6.0.49`'s `BuildOutputPlugin.js:6` requires that path; webpack renamed it to `lib/util/formatSize.js` | present through **5.106.2**, gone from **5.107.0** |
| `Progress Plugin has been initialized using an options object that does not match the API schema` (`name`, `color`, `reporter`, `reporters`) | `webpackbar@5.0.2`, itself a `laravel-mix` dependency, subclasses webpack's `ProgressPlugin` with its own options | accepted through **5.105.4**, rejected from **5.106.0** |

`package.json` constrained only `laravel-mix: ^6.0.6` and never webpack, so
`package-lock.json` had drifted to webpack **5.109.2**. The effective
ceiling for `laravel-mix@6.0.49` — the **latest laravel-mix release
overall**, so upgrading past the problem is not available — is **5.105.4**.

```json
"overrides": {
    "webpack": "~5.105.0"
}
```

### 1.3 The lockfile recorded a directory, not a package

`package.json` declared **no `name`**. npm therefore derived the lockfile's
root identity from whatever directory the install ran in: `"public_html"`
in the base lockfile, and `"mainline-theme-assets-publication-worktree"` —
a throwaway Git worktree — in this branch's first attempt. That is
checkout-local state leaking into a committed file, and it would have
recorded a different name for every machine.

Fixed by declaring a stable identity:

```json
"name": "os-ai"
```

`package-lock.json` regenerated from it now carries `"name": "os-ai"` both
at the root and in `packages[""]`.

---

## 2. Every missing source → rule → output → manifest relationship

All 91 asset references a tracked view can make were cross-checked — the 90
distinct `mix()` keys plus the one key the fresh-manifest helper reads
directly — and the Geist chain was traced from `_typography.scss` to the
served file.

| Required key | Tracked source | `webpack.mix.js` rule | Output on `main` | Manifest on `main` | Required by |
|---|---|---|---|---|---|
| `/js/core/theme-tokens.js` | `resources/js/core/theme-tokens.js` (52 lines) | line 71, `.js('resources/js/core/theme-tokens.js', 'public/js/core')` | **absent** | **absent** | `panels/scripts.blade.php` lines 28–42, via the fresh-manifest helper |
| `/js/scripts/pages/theme-settings.js` | `resources/js/scripts/pages/theme-settings.js` (363 lines) | line 53, `mixAssetsDir('js/scripts/**/*.js', …)` | **absent** | **absent** | `admin/theme-settings/index.blade.php` line 239, via `mix()` |
| `/css/core.css` | `resources/scss/core.scss` → `base/tokens/_typography.scss` | line 77, `.sass('resources/scss/core.scss', 'public/css', …)` | present but **stale** — 0 `@font-face`, 0 `Geist`, `Montserrat` primary | present, value unchanged | `panels/styles.blade.php`, via `mix('css/core.css')` |
| `/fonts/geist/geist-latin-wght-normal.woff2` | `resources/fonts/geist/geist-latin-wght-normal.woff2` | line 65, `mixAssetsDir('fonts', (src, dest) => mix.copy(src, dest))` | **absent** | **absent** | `_typography.scss` line 13, `src: url('/fonts/geist/geist-latin-wght-normal.woff2')` |
| `/fonts/geist/geist-latin-ext-wght-normal.woff2` | `resources/fonts/geist/geist-latin-ext-wght-normal.woff2` | same rule | **absent** | **absent** | `_typography.scss` line 22 |
| `/fonts/geist/LICENSE` | `resources/fonts/geist/LICENSE` | same rule | **absent** | **absent** | SIL OFL 1.1 requires the licence to travel with the redistributed font |

`panels/scripts.blade.php` is the only place in the repository that reads
the manifest itself rather than calling `mix()`. It does so deliberately —
`Illuminate\Foundation\Mix::__invoke()` caches the decoded manifest in a
`static $manifests` array for the life of the PHP process, so a process
whose first lookup predates a rebuild keeps serving a stale snapshot. That
helper is **not** weakened here: it still throws under `APP_DEBUG`, still
`report()`s otherwise, and no `APP_DEBUG` bypass was added. The tracked
tree simply now contains what it asks for.

### 2.1 Geist is the application's real font, not a preference

`resources/scss/base/tokens/_typography.scss` declares both faces and sets
`$font-family-app: $font-family-geist`, exposed as `--font-family-base` and
`--font-family-app`, which `bootstrap-extended/_variables.scss` resolves
`$font-family-sans-serif` **and** `$font-family-monospace` through.
`UpdatePlatformThemePresetRequest` accepts `font_bundled_key` values
`geist` and `system-ui`, and `theme-settings.js` defaults the field to
`geist`. Every one of those paths pointed at a font the repository did not
ship.

---

## 3. What is committed, and why it belongs in version control

| Path | Why |
|---|---|
| `public/js/core/theme-tokens.js` | Required by every authenticated render. 676 bytes |
| `public/js/scripts/pages/theme-settings.js` | Required by the admin theme-settings page. 7 768 bytes |
| `public/css/core.css` | Rebuilt from current tracked source so it carries the two Geist `@font-face` rules and the Geist primary stack |
| `public/fonts/geist/geist-latin-wght-normal.woff2` | The latin face `_typography.scss` points at. 29 400 bytes |
| `public/fonts/geist/geist-latin-ext-wght-normal.woff2` | The latin-ext face. 16 512 bytes |
| `public/fonts/geist/LICENSE` | SIL OFL 1.1, copied by the same rule and emitted into the manifest by the build |
| `public/mix-manifest.json` | **Five added lines across two commits** — the two JS entries and the three Geist entries, verbatim from the build, at the positions mix itself places them |
| `package.json`, `package-lock.json` | The webpack constraint (§1.2) and the stable package identity (§1.3) |
| `tests/Feature/Assets/ThemeAssetPublicationTest.php` | The guard that was missing (§5) |
| `docs/automation/MAINLINE-THEME-ASSET-PUBLICATION-REMEDIATION.md` | This document |

They belong in version control because this repository's deployment model
already puts compiled output there, with `public/mix-manifest.json` itself
tracked. Nothing about the model is being changed; a gap in it is closed.

Cache-busting semantics are preserved exactly: `mix.version()` is commented
out in `webpack.mix.js`, so the manifest maps every key to itself with no
hash or query string, and every added entry follows that same form.

---

## 4. Deterministic-build evidence

Three production builds, `npm ci` from the regenerated lock each time.

| Build | Starting state |
|---|---|
| #1 | this worktree, `public/` restored to the branch head |
| #2 | same worktree, `public/` restored again to the intended committed baseline |
| #3 | a **separate worktree** checked out fresh at the branch head, carrying over only `package.json` and `package-lock.json` — the authorized dependency correction, never a generated file |

| Output | Source | Rule | SHA-256 — builds #1, #2 **and** #3 | Bytes |
|---|---|---|---|---|
| `public/css/core.css` | `resources/scss/core.scss` | `webpack.mix.js:77` | `6cb0d7ce4a39cd323b130e3f18bf6b7317f2a4c1b359b1dcf6ad735821e1ba98` | 392 170 |
| `public/fonts/geist/geist-latin-wght-normal.woff2` | `resources/fonts/geist/…` | `webpack.mix.js:65` | `19f9c92546aa300c312235e3125af1b81394d8db9a4bc4a425cd5b641d2d54e1` | 29 400 |
| `public/fonts/geist/geist-latin-ext-wght-normal.woff2` | `resources/fonts/geist/…` | `webpack.mix.js:65` | `824f485b5d26e2f2da3c2b236132ece1bc8e4e43373452950bb0e40548b4313f` | 16 512 |
| `public/fonts/geist/LICENSE` | `resources/fonts/geist/LICENSE` | `webpack.mix.js:65` | `03f7731e1f962a91216320e38a5fe00a3236b19a15b486b629e65aa82010907c` | 4 579 |
| `public/js/core/theme-tokens.js` | `resources/js/core/theme-tokens.js` | `webpack.mix.js:71` | `2e9e037c1fec20445942d27675344bae73fcb2045098a03065d65eb227544549` | 676 |
| `public/js/scripts/pages/theme-settings.js` | `resources/js/scripts/pages/theme-settings.js` | `webpack.mix.js:53` | `5a1fb6028eeba658f436d8eff47b5131419113747c772881b1d55f01404b37ee` | 7 768 |

Identical across all three, byte for byte, and all three emitted the same
manifest mappings — every key mapping to itself. The build produced the
**exact filenames** `_typography.scss` references; nothing was renamed or
hashed. No output contains an absolute local path, a machine name or a
`sourceMappingURL`, and the build emitted no `.map` files.

---

## 5. Build pollution found and excluded

A full production build leaves **533** paths dirty. Every one was
classified individually — modified files by comparing against their `HEAD`
blob with carriage returns stripped, so line-ending churn is separated from
real content change.

| Class | Count | Disposition |
|---|---|---|
| Required output, new | **3** | **committed** — the three `public/fonts/geist/*` files |
| Required output, rebuilt | **1** | **committed** — `public/css/core.css` |
| Already committed by this branch, reproduced byte-identically | 2 | the two JS assets; the build changes nothing about them |
| Third-party re-minification (`public/vendors/**`) | 443 | restored |
| Regenerated CSS other than `core.css` (`public/css/**`, `public/css-rtl/**`) | 62 | restored, see §7.1 |
| Regenerated JS (`public/js/**`) | 23 | restored, see §7.1 |
| `bootstrap/cache/packages.php`, `services.php` | 2 | regenerated during verification, restored before commit per `AGENTS.md`; see §7.2 |

### 5.1 The manifest was not committed wholesale

A regenerated manifest differs from the branch head by **+8 / −2**, and only
three of those additions are this correction's:

* **+3** Geist entries — **committed**.
* **+5** entries for `/images/branding/default-*.svg` and two
  `/images/logo/*` files: already tracked, unrelated to this fix, excluded.
* **−2** would drop `/js/scripts/customizer.js` and
  `/images/backgrounds/plugin.svg`. Both are **already dangling on `main`**
  — no source, no tracked file, no file on disk, and no `mix()` caller — but
  removing them is unrelated churn and they are deliberately retained.

So the tracked manifest received exactly the three key/value pairs the build
emits, at the positions mix itself places them: all three immediately after
`/fonts/flag-icon-css/sass/variables.scss` and before
`/images/backgrounds/chat-bg-2.png`.

### 5.2 No runtime uploads were swept in

`mix.copyDirectory('resources/images', 'public/images')` indexes its own
destination, so a build run after a test suite sweeps uploaded files into
the manifest. Every build here started from a `public/` verified equal to
the tracked tree, and the resulting manifests carried **0** entries under
`/images/websites/` or `/images/branding/logo*/`.
`ThemeAssetPublicationTest` asserts this permanently, and the uploads the
suites leave behind were deleted before the audit.

---

## 6. Verification

Database: **`ultimatesms_testing_geist`**, a dedicated sibling accepted by
`Tests\Support\TestDatabaseSafety`, used by no other lane. Reset with
`migrate:fresh`: **250 migrations ran, 0 pending**.

### 6.1 The guard actually guards

`ThemeAssetPublicationTest` — 13 tests, 127 assertions, green. More
usefully, it was proven to **fail** under every condition it exists to
catch. Each was broken in turn, the suite re-run, and the condition
restored:

| Condition injected | Result |
|---|---|
| A required Geist WOFF2 deleted | 4 failures |
| `core.css` reverted to the stale Montserrat build | 2 failures |
| The three Geist manifest entries removed | 2 failures |
| Package identity set to a worktree directory name | 1 failure |
| `package.json` `name` removed entirely | 1 failure |
| A mandatory JavaScript asset deleted | 4 failures |
| **all restored** | **13 tests, 127 assertions, green** |

### 6.2 Focused suites

| Suite | Result |
|---|---|
| `tests/Feature/Assets/ThemeAssetPublicationTest` | 13 tests, 127 assertions, **green** |
| `tests/Feature/Theme` | 85 tests, 477 assertions, **green** |
| `tests/Feature/Security` | 172 tests, 1 302 assertions, **green** — **108 errors on `main`** |
| `tests/Feature/Auth` | 33 tests, 437 assertions, **green** |
| `tests/Feature/Dashboards` | 14 tests, 41 assertions, **green** |
| `tests/Feature/Settings` | 57 tests, 172 assertions, **green** |
| `tests/Feature/Branding` | 53 tests, 412 assertions, 2 failures — §6.4 |

### 6.3 Clean-checkout authenticated render

Traced end to end through the tracked tree, with no local build in the
loop:

```
panels/styles.blade.php  →  mix('css/core.css')
    →  manifest "/css/core.css": "/css/core.css"     → file exists
    →  2 @font-face rules
    →  url(/fonts/geist/geist-latin-wght-normal.woff2)      → file exists
    →  url(/fonts/geist/geist-latin-ext-wght-normal.woff2)  → file exists
    →  Montserrat present: false
```

`AuthPageRenderTest` + `DashboardRenderTest`: 10 tests, 17 assertions,
green.

### 6.4 Full suite

Run on `ultimatesms_testing_geist` after `migrate:fresh`:

| | before this correction | **after** |
|---|---|---|
| Tests | 5 185 | **5 191** |
| Assertions | 26 288 | **26 380** |
| Errors | 2 | **2** |
| Failures | 19 | **19** |

The +6 tests and +92 assertions are `ThemeAssetPublicationTest` growing
from 7 tests to 13. The 21 remaining problems are the **same 21 names** as
before, so the Geist publication and the package-identity change introduce
nothing.

**This is not a green suite, and this branch does not claim one.** For
comparison, `main` at the merge base produces roughly **970 errors**, almost
all of them the missing theme asset. Every remaining problem is classified,
and none is caused by this branch:

| Group | Count | Evidence |
|---|---|---|
| Isolated-database family | 10 failures + 1 error | 8 `EntitlementManagerConcurrencyTest`, `WorkspaceManagerConcurrencyTest`, `WorkspaceManagerTest`, and the `WorkspaceTransitionsMigrationSchemaTest` error. Each names its own cause — *"resolved database is [ultimatesms_testing_geist], expected [ultimatesms_testing]"*. **Already fixed on current `origin/main`** by PR #232, which converted all eight canonical-database pins; this branch simply predates that merge |
| Pre-existing, reproduced identically | 7 failures | Previously re-run with this branch's assets removed and the manifest reverted: the same seven names failed — `BrandingUploadValidationTest::test_a_valid_png_logo_upload…`, `BusinessKnowledgeProfileHoursTest::test_no_change_row_for_a_true_hours_no_op`, `BusinessKnowledgeProfileSeamTest::test_only_the_manager_writes_the_tracked_tables`, `WebsiteIndexingTest::test_robots_txt_is_untouched…`, `WebsiteDraftPageServiceSeamTest::test_store_page…` and `::test_update_page…`, `WebsiteDraftPublishTest::test_rollback_repoints…`. `BrandingUploadValidationTest` is also touched by PR #233 on current `main` |
| Pre-existing, previously **masked** | 2 failures | `BrandingAdminFooterRenderTest::test_admin_footer_renders_the_company_name_exactly_once_with_no_login_link` and `BusinessKnowledgeProfileControllerTest::test_missing_stale_and_present_fields_are_all_displayed`. Both **errored** with `MixFileNotFoundException` before the assets were published and never reached an assertion; they now run and fail on their own content assertions, which have nothing to do with theme assets |
| Pre-existing error | 1 error | `OpportunityManagerBeginRunTest::test_heartbeat_one_second_past_the_timeout_cutoff_is_abandoned` — `RunAlreadyActiveException` |

### 6.5 Environment-file integrity

| | |
|---|---|
| `.env` before | md5 `3fe3b8f6e1e91967425b8ea07c19b212`, 1 760 bytes |
| `.env.testing` | absent in this worktree, before and after |

`AppConfig::setEnv()` resolves `base_path('.env')`, which no test seam in
this branch redirects, so the suites rewrite three keys —
`NOCAPTCHA_SITEKEY`, `FACEBOOK_CLIENT_ID`, `APP_TIME_FORMAT` — and drop the
trailing newline. The file affected is this worktree's **own gitignored
copy**; the developer's `.env` lives in the main checkout and the other
worktrees and was never opened. It is restored path-specifically to its
pre-test bytes before the audit, and §7.2's `bootstrap/cache` files are
restored the same way. **`origin/main` has since fixed this defect
outright** in PR #233, which makes `AppConfig::setEnv()` address the active
environment file.

No credential, database, preview configuration, cache file or runtime
upload was modified or committed, no other worktree was touched, and no
blanket `git add -A` was run — every path was staged explicitly.

---

## 7. Remaining asset-pipeline risk

Geist is **no longer** on this list — §2, §4 and §6 record it as fixed.
What remains:

### 7.1 The rest of the compiled tree is still stale against its source

Excluding `core.css`, a production build rewrites 62 further CSS files, 23
JS files and 443 vendor files with genuine content differences.
`public/js/core/app.js` as committed contains **no** `PlatformTheme`
reference while `resources/js/core/app.js` contains two, so at least that
file is demonstrably behind its source. This branch publishes the assets a
clean checkout provably cannot render or style without, and repairs the
build; it does not audit or re-publish the remainder, and nothing here says
that remainder is current.

### 7.2 `bootstrap/cache/*.php` are tracked and stale

The committed `bootstrap/cache/packages.php` does not mention
`blade-ui-kit`. Once the Mix exception stopped short-circuiting rendering,
that staleness surfaced as HTTP 500s —
`Unresolvable dependency resolving [Parameter #1 [ <required> string $manifestPath ]] in class BladeUI\Icons\IconsManifest`
— across the rendering suites. `php artisan clear-compiled && php artisan
package:discover` clears it, and `composer install` runs `package:discover`
through `post-autoload-dump`, so a real deployment regenerates these files
and is unaffected. The staleness bites only a checkout that runs tests
against a tracked cache without a dependency install. These files are
excluded from the commit by this lane's contract and restored to `HEAD`.
**Still deferred.**

### 7.3 Two manifest entries on `main` are already dangling

`/js/scripts/customizer.js` and `/images/backgrounds/plugin.svg` have no
source, no tracked `public/` file and no file on disk, yet both have
manifest entries. Nothing calls `mix()` for either, so neither breaks a
render. They are left exactly as `main` has them.
