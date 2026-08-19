# Design System M2 — Platform Branding & Assets Contract

**This document is fully self-contained.** No section below requires
consulting an earlier commit or any other contract to understand this
contract's complete rules — every requirement, architecture decision,
and path is stated here in full.

**Status: contract only. No implementation has occurred under this
document. Merging this contract does NOT authorize implementation —
implementation requires its own separate, explicit human authorization,
exactly like Design System M2 Slice 1 (PR #87) and Slice 2 (PR #90)
each required their own.**

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-platform-branding-contract`,
  in an isolated linked worktree
  (`../design-system-m2-platform-branding-contract-worktree`), based on
  `origin/main` at `97c42ced3c849a951d4a3e514f874a1b69a24b51`.
- Verified before drafting: Design System M2 Slice 2 (PR #90,
  `agent/design-system-m2-slice2`) is merged into `origin/main` at
  exactly that commit; the implementation head
  `a5b53466321a706bf35d395ca9d97c51973dcf9e` is a genuine ancestor of
  `origin/main` (`git merge-base --is-ancestor`, exit `0`); no branch or
  worktree named for this contract existed beforehand.
- Drafting this contract makes **zero** application changes. No
  `resources/`, `app/`, `database/`, `routes/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by this
  branch — only this one new document.
- **This is not one of the 21 numbered rollout-map slices** in
  `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` §8. It is a new,
  orthogonal capability area — platform-owner branding configuration and
  its upload/rendering infrastructure — exactly analogous to how M2
  Slice 1 introduced the `admin/theme-settings/**` surface as new
  infrastructure rather than a page-by-page visual migration of an
  existing module. One file in this contract's own allowlist
  (`resources/views/admin/settings/AllSettings/_general.blade.php`) sits
  inside the glob the rollout map assigns to Slice 21 ("System
  Settings," `admin/settings/**`, 26 files) — this contract touches that
  **one** file narrowly for its branding-specific functional fields
  (new upload inputs, safer validation, new form fields), never for
  token/icon/component visual migration of that page or any other System
  Settings file. Slice 21's own full visual migration of the remaining
  25 files in that module, and of this one file's non-branding fields,
  remains entirely its own, separately-authorized future scope.
- `maximum_correction_rounds: 2` applies to this contract.
- Any path required during implementation but absent from §11's own
  numbered allowlist is a stop-and-report condition — not a silent
  workaround. The stop threshold is the **48th** path (47 allowlisted +
  1).

---

## 1. Mandatory preflight — verified

1. `git fetch origin` — `origin/main` confirmed at exactly
   `97c42ced3c849a951d4a3e514f874a1b69a24b51`.
2. PR #90 confirmed merged via the GitHub API (`"state": "closed",
   "merged": true, "merge_commit_sha":
   "97c42ced3c849a951d4a3e514f874a1b69a24b51"`), matching `origin/main`
   exactly.
3. `git merge-base --is-ancestor a5b53466321a706bf35d395ca9d97c51973dcf9e origin/main`
   exits `0` — a genuine ancestor, not merely present somewhere in
   history.
4. No local or remote branch named
   `chore/design-system-m2-platform-branding-contract` existed before
   this branch was created; the intended worktree path did not exist.
5. A sibling-directory linked worktree was created from `origin/main`
   (not nested inside another worktree). No existing worktree was
   modified or removed.
6. Read in full: `AGENTS.md`; `docs/automation/AI-SUBSCRIPTION-LOOP.md`;
   current `docs/automation/AI-AUTONOMY-STATE.json` (confirmed idle:
   `active_pull_request: null`, `active_rfc: "RFC-003"`, unrelated to
   this Design System track; its own `manual_verification` field records
   Slice 2's evidence and is left untouched here);
   `docs/automation/DESIGN-SYSTEM-CONTRACT.md` (Milestone 1);
   `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` (Milestone 2, Slice 1 +
   the full 21-slice rollout map + theme-preset upload/permission/cache
   precedent); `docs/automation/DESIGN-SYSTEM-M2-SLICE-2-CONTRACT.md`
   (Authentication & Profile, the 27-view surface this contract's own
   auth/installer branding adoption extends); the current
   `manual_verification` record for both Slice 1 and Slice 2 (embedded in
   `AI-AUTONOMY-STATE.json`'s history, confirmed via `git log`, since
   this repository has no separate per-slice verification-record file).
7. Read completely: `app/Library/Theme/PlatformThemeManager.php`,
   `app/Models/AppConfig.php`, `app/Http/Controllers/Admin/SettingsController.php`,
   `app/Http/Requests/Settings/PostGeneralRequest.php`,
   `resources/views/admin/settings/AllSettings/_general.blade.php`,
   `config/app.php`, `config/permissions.php`, every master layout, every
   `panels/*.blade.php` partial, every `errors/*.blade.php` view, every
   Slice 2 `auth/**`/`Installer/**` view, `app/Http/Controllers/User/AccountController.php`
   (avatar fallback), and the ten `resources/views/emails/**` templates
   that reference `config('app.logo')`/`config('app.name')`.

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**:
`docs/automation/DESIGN-SYSTEM-M2-PLATFORM-BRANDING-CONTRACT.md` (this
document). No `resources/`, `app/`, `database/`, `routes/`, `.env`, or
`AI-AUTONOMY-STATE.json` file is touched by drafting this contract. No
existing Design System contract is modified.

---

## 3. Mandatory repository audit — findings

### 3.1 An owner-configurable branding mechanism already exists, partially

`resources/views/admin/settings/AllSettings/_general.blade.php`, posted
to `SettingsController::postGeneral()` (`routes/admin.php:381-382`,
`Route::get('settings', ...)->name('settings.general')` /
`Route::post('settings', ...)`), already exposes: `app_name`,
`app_title`, `app_keyword`, `company_address`, `footer_text`, `app_logo`
(file upload), `app_favicon` (file upload), `country`, `timezone`,
`date_format`, `time_format`, `language`, `custom_script`. This is the
correct, existing extension point for platform-owner branding — this
contract extends it, it does not invent a competing surface, per the
top-level "reuse existing functionality whenever possible" mandate.

### 3.2 Two parallel, only-one-live storage mechanisms

- `app_name`, `app_title`, `app_keyword`, `country`, `timezone`,
  `time_format`, `language`, `date_format`, `footer_text` are persisted
  by `AppConfig::setEnv($key, $value)` (`app/Models/AppConfig.php:397-409`)
  — a direct, case-insensitive-substring-matched rewrite of the `.env`
  file's own line for that key, read back everywhere via
  `config('app.*')` (populated from `.env` at boot).
- `app_logo`/`app_favicon` are persisted **twice, redundantly, by
  `AppConfig::uploadFile()`** (`app/Models/AppConfig.php:353-388`): once
  as an `AppConfig` DB row (`AppConfig::where('setting', $name)->update(...)`
  — an `update`, not `updateOrCreate`, a silent no-op if the row does not
  already exist) and once via the same `setEnv()` `.env` rewrite as
  every other field above. **Confirmed by exhaustive grep: every one of
  the 46 Blade call sites that render the logo or favicon reads
  `config('app.logo')` / `config('app.favicon')` — never the `AppConfig`
  DB row.** The DB row is therefore dead for rendering purposes; only the
  `.env`-backed `config()` value is ever actually live. **Decision**:
  this contract's own new upload path continues writing through the same
  live `.env`/`config()` mechanism every other field and every existing
  render call site already depends on — it does not silently redirect
  reads to the dead DB row, and it does not remove the existing DB-row
  write (removing it is an unrelated, out-of-scope behavior change; §7
  item 1 states the boundary explicitly).
- `custom_script` alone is a live-read `AppConfig` DB row with no `.env`
  mirror — unrelated to branding, unchanged by this contract.

### 3.3 The broken-logo/favicon default, confirmed at the source

`config/app.php`:

```
'name'        => env('APP_NAME', 'Ultimate SMS'),
'title'       => env('APP_TITLE', 'Bulk SMS Application For Marketing'),
'keyword'     => env('APP_KEYWORD', 'ultimate sms, codeglen, bulk sms, sms, sms marketing, laravel, framework'),
'logo'        => env('APP_LOGO', ''),
'favicon'     => env('APP_FAVICON', ''),
'footer_text' => env('APP_FOOTER_TEXT', 'Copyright &copy; Codeglen - 2020'),
```

`logo`/`favicon` default to an **empty string** — every `<img
src="{{asset(config('app.logo'))}}">` call site (46 confirmed, §3.6)
renders `src="http://host/"` on any install that has never visited
Settings → General and saved, or on the disposable
`ultimatesms_testing` database used throughout this engagement's own
manual-verification sessions (confirmed directly: this exact broken
state was observed and reported in Design System M2 Slice 2's own
manual-verification record). **This is the "broken logo" the deferred
finding names — a default-value gap, not a missing fallback-image
mechanism** (§3.7 shows a real fallback mechanism already exists for
avatars; none exists today for the platform logo/favicon at all — the
`<img>` tag simply has no `src`). `name`/`title`/`keyword`/`footer_text`
default to literal Ultimate SMS/Codeglen strings — every install that
never visits Settings → General ships those strings to every visitor by
default.

### 3.4 Upload-path security audit (`AppConfig::uploadFile()`, `PostGeneralRequest`)

- `PostGeneralRequest::rules()`: `'app_logo' => 'sometimes|required|image'`
  — Laravel's built-in `image` rule accepts `jpg, jpeg, png, bmp, gif,
  svg, webp` **including `svg`**, with no separate SVG-specific
  sanitization anywhere in this codebase (confirmed, matching the M2
  Slice-1 contract's own §3.7 finding: "No file anywhere in this
  codebase validates actual binary/magic-byte content"). `'app_favicon'
  => 'sometimes|required|mimes:jpeg,bmp,png,ico,jpg'` — narrower, `svg`
  already excluded here, but still relies on Laravel's finfo-based MIME
  guess alone, not an explicit magic-byte signature check.
- `AppConfig::uploadFile()` generates its stored filename as
  `md5_file($file) . '.' . $file->getClientOriginalExtension()` —
  **the extension is client-supplied, not derived from validated
  content.** Combined with the `image` rule's SVG allowance, a
  crafted-but-technically-valid SVG (or any file whose claimed extension
  Laravel's MIME guess happens to accept) is written, with an
  attacker-influenced extension, directly into `public_path('images/logo/')`
  — a permanently public, unauthenticated static directory (Intervention
  Image's `Image::make()`/`->fit()`/`->save()` call, applied to every
  upload, would itself throw for a non-raster SVG in the ordinary case,
  but this is an incidental side effect of image processing, not a
  designed content-signature gate, and is not exercised at all for any
  future field this contract adds that does not route through
  `uploadFile()`).
- No per-request authorization beyond `PostGeneralRequest::authorize()`
  → `$this->user()->can('general settings')` (`config/permissions.php:374`)
  — a real, already-established, adequately-scoped gate (also
  independently checked by `routes/admin.php`'s blanket admin
  middleware group, matching the Slice-1 theme-preset contract's own
  defense-in-depth pattern). This existing gate is reused, not
  duplicated, for every new field this contract adds.
- **Decision**: a new, dedicated `BrandingUploadService` +
  `ValidBrandingImageRule` replace `AppConfig::uploadFile()`'s filename
  and validation logic for every branding upload field, existing and
  new alike — exceeding, not merely matching, the existing precedent
  (§6.3), mirroring exactly why the M2 Slice-1 contract built a new
  `ThemeFontValidator` rather than reusing any existing upload code for
  font files (its own §3.7 finding: "requires building — not reusing —
  real content validation").

### 3.5 Avatar fallback — already functional, confirmed working, not a gap

`AccountController::avatar()` (`app/Http/Controllers/User/AccountController.php:112-135`)
already has a real, working fallback: if the stored user image path is
empty, or `Image::make()` throws `NotReadableException` for a
missing/corrupt file, it clears the broken reference and serves
`public_path('images/profile/profile.jpg')` — a bundled, generic
placeholder image, confirmed present on disk, carrying no Ultimate
SMS/Codeglen branding (a neutral silhouette graphic, part of the
purchased Vuexy base theme). **This mechanism is not broken and is out
of this contract's own scope** — the "broken logo/avatar fallback"
deferred finding's *avatar* half refers to the fact that no equivalent
fallback exists for the **platform logo/favicon** (§3.3), not that the
existing avatar fallback itself is defective. No `AccountController.php`
change is authorized or required by this contract.

### 3.6 Exhaustive logo/favicon/name render-site enumeration

Direct grep, `config\('app\.logo'\)|config\('app\.name'\)|config\('app\.favicon'\)`,
repository-wide: **46 distinct call sites**, classified:

- **Shared chrome (7, already Slice-1-adopted territory)**:
  `panels/sidebar.blade.php` (2 occurrences), `panels/navbar.blade.php`
  (2), `panels/horizontalMenu.blade.php` (1), `panels/footer.blade.php`
  (`footer_text`, 1), `layouts/{fullLayoutMaster,detachedLayoutMaster,contentLayoutMaster}.blade.php`
  (favicon `<link>`, 1 each).
- **Errors module (7, already Slice-1-allowlisted)**: `errors/{401,403,404,419,429,500,503}.blade.php`
  (1 logo `<img>` each).
- **Auth/Installer (11, Slice 2's own just-merged surface)**:
  `auth/login.blade.php`, `auth/register.blade.php`, `auth/verify.blade.php`,
  `auth/twoFactor.blade.php`, `auth/twoFactorBackUp.blade.php`,
  `auth/passwords/email.blade.php`, `auth/passwords/reset.blade.php`,
  `auth/subAccount/acceptInvitation.blade.php`, `Installer/welcome.blade.php`,
  `Installer/update/welcome.blade.php`, `Installer/update/overview.blade.php`.
- **`auth/payment/**` (8, explicitly Slice 12's own future scope per the
  M2 rollout map, excluded here — §3.2 already excludes this glob from
  Slice 2 for the identical reason)**, **`resources/views/emails/**` (10,
  the M2 contract's own standing "out of scope for the entire rollout"
  finding, §3.1 of that document)**, and **4 further individual admin/
  customer views belonging to modules not yet migrated by any merged
  slice** (`admin/Invoices/{view,print}.blade.php`,
  `customer/Accounts/{print,invoice}.blade.php`,
  `customer/Contacts/{subscribe_form,unsubscribe_form}.blade.php` — 6
  files, Slices 12/18 territory) — **all 24 of these are explicitly out
  of this contract's own allowlist** (§9 "excluded scope"), consistent
  with respecting the already-established per-module rollout boundary.
  They benefit automatically, without any file of their own being
  touched, from §5's corrected `config/app.php` defaults (a non-empty,
  neutral fallback logo/favicon/name instead of a broken empty string
  and Ultimate SMS/Codeglen strings) — the same kind of automatic,
  file-untouched improvement Milestone 1's own token layer already gave
  every one of the 358 not-yet-migrated views.

### 3.7 Legacy branding inventory — full classification

Mechanical search for `Ultimate SMS`, `UltimateSMS`, `Codeglen`,
`codeglen`, across the entire repository, filtered to genuinely
user-visible or asset-level hits (excluding the ~90 SCSS/JS files whose
only match is the Vuexy theme's own boilerplate `// Item Name: Ultimate
SMS - Bulk SMS Application For Marketing` file-header comment —
internal, never rendered to any user, confirmed by direct inspection of
a representative sample):

| Finding | File(s) | Classification |
|---|---|---|
| `config('app.name')`/`title`/`keyword`/`footer_text` default values | `config/app.php` | **User-visible branding requiring replacement** — §5, this contract's own core scope. |
| `config('app.logo')`/`config('app.favicon')` default empty-string | `config/app.php` | **User-visible branding requiring replacement (broken-image root cause)** — §5. |
| Page titles `"Ultimate SMS Auto Installer"` / `"Ultimate SMS Update"` | `Installer/welcome.blade.php`, `Installer/update/welcome.blade.php`, `Installer/update/overview.blade.php` | **User-visible branding requiring replacement** — §11 items 39-41 (title text made to read from `config('app.name')` rather than a hardcoded literal). |
| Demo-mode auto-fill using `@codeglen.com` addresses | `auth/login.blade.php` (lines 212, 216, 220) | **User-visible branding requiring replacement** (visible only when `config('app.stage') == 'demo'`, but genuinely rendered to a real visitor in that mode) — §11 item 31, replaced with neutral example addresses (`admin@example.test` etc.), demo-mode gating itself unchanged. |
| `"Thank you for purchasing Ultimate SMS!"` and surrounding purchase-code/license explanatory text | `admin/settings/AllSettings/_license.blade.php` | **Repository/license attribution requiring preservation, flagged for explicit separate human decision** — this text describes the actual Envato/CodeCanyon purchase-code licensing mechanism this application's own activation flow still depends on (`AppConfig` `license`/`license_type` settings, confirmed live and unrelated to this contract). Rewriting product-name references inside genuine license/purchase text risks misrepresenting what was actually licensed. **Not in this contract's allowlist.** Recorded here as a named finding for a human to resolve separately, not silently rewritten. |
| `"uSupport - Support Ticket Plugin for Ultimate SMS"` descriptive text | `admin/Plugins/index.blade.php` | **Internal historical reference / Slice 20's own future scope** ("Plugins & legacy Theme Customizer" per the M2 rollout map) — not in this contract's allowlist. |
| `codeglen/usupport`, `codeglen/ulanding` string comparisons | `admin/Plugins/index.blade.php` | **Unrelated/read-only dependency** — actual installed-plugin package identifiers the view branches on, not rendered text; must never be changed, doing so would break plugin detection. |
| `"...to Ultimate SMS."` / `"Ultimate SMS Inbound SMS"` instructional example text | `admin/SendingServer/create.blade.php` | **Internal historical reference / Slice 11's own future scope** (this file is also independently flagged by the M2 contract, §3.1, as a 4,306-line outlier requiring its own dedicated future slice) — not in this contract's allowlist. |
| `"Your are currently running on Ultimate SMS X.X.X"` | `admin/settings/UpdateApplication/index.blade.php` | **Internal historical reference / Slice 21's own future scope** (`admin/settings/**`) — not in this contract's allowlist. |
| `"Codeglen"` used as an example contact/sender name in sample API request bodies | `customer/Developers/{_http_contact_groups_api,_sms_api,_contact_groups_api}.blade.php` | **Internal historical reference / Slice 15's own future scope** (`customer/Developers/**`, 22 files) — not in this contract's allowlist. |
| Vuexy theme vendor file-header comments (`// Item Name: Ultimate SMS...`) | ~90 `resources/scss/**`/`resources/js/**` files | **Internal historical reference, not user-visible** — source comments only, never rendered, not touched by this or any future contract; explicitly not license/attribution text (Vuexy's own license terms live in the theme's purchase documentation, outside this repository, and are unaffected by a source-comment string). |
| Translation-file occurrences (`resources/lang/**/locale.php`) | 17 language files | **Internal historical reference, not user-visible in the sense of a literal string** — confirmed by direct inspection: these are locale-key *labels* the app's own translator (`__('locale.brand.something')`-shaped, not literal "Ultimate SMS" copy meant for display) or copyright-adjacent boilerplate carried from the base theme; none renders as a bare, unconfigurable "Ultimate SMS"/"Codeglen" string on any authorized page in this contract's own scope. No entry in this contract's allowlist follows from this row; a future slice touching a specific language file's own rendered content is unaffected. |

### 3.8 Inline SVG auth/installer illustrations — already neutral, already reusable as the safe fallback

`login-v2.svg`, `create-account.svg`, `forgot-password-v2.svg`,
`two-steps-verification-illustration.svg`, `reset-password-v2.svg`,
`not-authorized.svg` (and each file's `-dark` variant), all under
`resources/images/pages/`, are generic Vuexy-theme stock illustrations
(people at desks, generic UI mockups) — confirmed by direct inspection,
none reference Ultimate SMS, Codeglen, or any product name, logo, or
wordmark. **Decision**: these remain the platform's own bundled,
legitimate "no illustration configured" fallback for both the
authentication surface and the Installer surface (§6.5) — no new
illustration asset is required to be created by this contract or by its
future implementation, satisfying "the implementation must not require
my final logo or illustrations to exist yet" for illustrations
specifically. A pre-existing, unrelated defect was also observed during
this audit — `subAccount/acceptInvitation.blade.php` references
`images/pages/not-authorized-dark.svg.svg` (a doubled `.svg` extension,
line 28) — noted here as a discovered pre-existing bug, explicitly
**not** in this contract's own scope (it predates this contract, is
unrelated to branding configurability, and fixing it is not required to
satisfy any locked requirement below; a future contract touching that
file for its own reason may correct it).

### 3.9 Storage architecture confirmation (reused, not reinvented)

Matching the M2 Slice-1 contract's own §3.7 finding exactly:
`config/filesystems.php`'s `public` disk/`storage:link` convention
remains architecturally unused; every real upload feature in this
codebase (including `AppConfig::uploadFile()` today) writes directly to
`public_path(...)` as a permanent, unauthenticated static asset. This
contract's new `BrandingUploadService` **continues that same, already-
established storage convention** (`public_path('images/branding/')`) —
introducing a new `Storage::disk()` abstraction solely for this
contract's own uploads, while every sibling upload feature in the
application does not use one, would be an inconsistent, unjustified
architectural divergence. What changes is not *where* files are stored,
but *how safely* the filename and content are validated before they get
there (§6.3).

---

## 4. Locked requirements — complete scope

**Platform-owner branding controls** (§0's own governance: global
platform-owner configuration only in this contract; Agency/Workspace/
Business-level branding overrides are explicitly out of scope, named
here as a separately-governed future capability, never silently
introduced):

- Product/application name (existing `app_name` field, reused).
- Application title (existing `app_title` field, reused, used for
  `<title>`/meta purposes).
- Full desktop logo (existing `app_logo` field, reused, hardened).
- Compact/sidebar logo (**new**).
- Favicon (existing `app_favicon` field, reused, hardened).
- Optional light-background and dark-background logo variants (**new**
  — the existing single `app_logo` becomes the light-background variant
  by definition, matching its current use against this application's
  light sidebar/canvas; a new, optional dark-background variant is
  added).
- Authentication-page illustration (**new**, optional; falls back to
  the existing bundled Vuexy illustrations, §3.8).
- Installer illustration (**new**, optional; same fallback).
- Footer company name (**new** — replaces the free-text portion of the
  existing `footer_text` field's role).
- Footer copyright wording (**new** — a short, owner-editable suffix,
  e.g. "All rights reserved").
- Automatic current copyright year (**new** — computed at render time,
  never stored, never owner-editable as a literal year).

**Neutral, safe fallbacks, until real assets are uploaded**: no
Ultimate SMS/Codeglen branding ever displayed; no broken-image icons
ever rendered; accessible text or a deterministic neutral mark used
instead of an absent image; works on desktop and mobile; works against
light and dark backgrounds.

**Upload/asset security**: deterministic MIME/extension allowlist;
maximum file size; image dimension/aspect-ratio rules; safe
server-generated storage names, never client-derived; defined
replacement and deletion behavior; cache invalidation; a defined
inaccessible/missing-file fallback; authorization and CSRF protection
on every upload route. No executable uploads, no arbitrary JavaScript,
no unsanitized raw SVG upload (SVG is excluded from every owner-upload
field entirely, §6.3 — the bundled fallback SVGs described in §6.1 are
authored, reviewed source files shipped by implementation, never an
owner upload path). Fail closed on any validation or storage failure.
No filesystem path, storage identifier, internal ID, or provider
payload ever exposed in the UI.

**Consistent rendering**: every configured branding value rendered
through a shared presentation seam, never duplicated database/config
lookups scattered across Blade; no Blade view resolves a repository,
manager, or service-container dependency directly (only Blade
components, exactly like `<x-ds-icon>`/`<x-input>` already established
by Milestones 1-2).

**Legacy branding removal**: every user-visible Ultimate SMS/Codeglen
occurrence within this contract's own authorized surface (§3.7's first
five table rows) is removed or replaced. Legally required
license/attribution content is preserved, never silently removed
(§3.7's sixth row, flagged for separate human decision, not
auto-rewritten).

---

## 5. Architecture — configuration defaults

`config/app.php`'s defaults change from Ultimate SMS/Codeglen literals
to neutral AI Business OS values, and from an empty-string
logo/favicon to the new bundled fallback assets (§6.1):

```
'name'        => env('APP_NAME', 'AI Business OS'),
'title'       => env('APP_TITLE', 'Business Operations Platform'),
'keyword'     => env('APP_KEYWORD', 'business operations, automation, ai business os, workspace, crm, messaging'),
'logo'        => env('APP_LOGO', 'images/branding/default-logo.svg'),
'favicon'     => env('APP_FAVICON', 'images/branding/default-favicon.svg'),
```

`footer_text` is **not** kept as a single free-text default — §6.2
replaces its role with the two new structured fields plus the always-
computed year, rendered by the new `<x-branding-footer>` component
(§6.5). The `footer_text` `AppConfig`/`.env` key and its existing
`_general.blade.php` form field are removed from the form (§11 item 16)
in favor of `footer_company_name`/`footer_copyright_text`; no other
call site reads `footer_text` besides `panels/footer.blade.php`
(confirmed, §3.2 of the merged Slice 2 audit's own single-hit grep
result carried forward and reconfirmed here), so this is a safe,
complete replacement, not a partial one leaving a second, orphaned
reader.

**Existing installs that already saved a custom `app_name`/`app_title`
via Settings → General are unaffected** — `env()`'s own default-value
argument only applies when the `.env` key is entirely absent; an
install that already wrote `APP_NAME="My Company"` keeps that value
untouched. Only a fresh install, or one that never visited Settings →
General, receives the new neutral defaults instead of the old branded
ones.

---

## 6. Architecture — new components and services

### 6.1 New bundled neutral fallback assets

Three new, hand-authored SVG files (plain, reviewable XML markup — no
image-editing tooling required, satisfying "the implementation must not
require my final logo or illustrations to exist yet" precisely):

- `public/images/branding/default-logo.svg` — a simple, deterministic
  wordmark/monogram (the product name or its initials) in a rounded
  shape, using the Design-System-M1 token colors so it visually matches
  whichever theme preset (M2 Slice 1) is currently active without
  needing its own separate owner configuration; includes an accessible
  `<title>` element.
- `public/images/branding/default-logo-compact.svg` — the same mark,
  square/icon-only proportions, for the collapsed-sidebar/mobile-header
  slot.
- `public/images/branding/default-favicon.svg` — the same mark, sized
  and simplified for a browser tab (a real static file, deliberately
  **not** a data-URI, since it must also work as the `<img>` source in
  the ten `resources/views/emails/**` templates that reference
  `config('app.logo')` today — most email clients do not reliably
  render inline SVG `data:` URIs, but they do render a normal, absolute
  `<img src="https://.../default-logo.svg">` URL; using one real file
  for both purposes, rather than an SVG file for web and a separate
  data-URI mechanism for email, is the simpler, more robust choice).

These are the "neutral AI Business OS-safe fallback" the locked
requirements name — never Ultimate SMS/Codeglen branding, never a
broken `<img>` reference, and legible against both light and dark
surfaces by using a filled rounded-rect background rather than relying
on transparency.

### 6.2 Domain/storage extension (`AppConfig`, reused table)

New `AppConfig::defaultSettings()` entries (§7 item 1 of the M2 Slice-1
contract's own precedent: `app_logo`/`app_favicon` are already
`defaultSettings()` rows today, this only adds siblings of the same
shape) for informational/seed purposes; the actual live read/write path
for every branding field, new and existing alike, remains
`config('app.*')` backed by `.env` (§3.2's own confirmed "only one
storage is live" finding — this contract does not silently redirect
existing fields to a different storage without cause, and does not
leave new fields split across two disagreeing sources either). New
`.env` keys, all following the identical `AppConfig::setEnv()` pattern
every existing field already uses: `APP_LOGO_COMPACT`, `APP_LOGO_DARK`,
`APP_AUTH_ILLUSTRATION`, `APP_INSTALLER_ILLUSTRATION`,
`APP_FOOTER_COMPANY_NAME`, `APP_FOOTER_COPYRIGHT_TEXT`. Corresponding
new `config('app.*')` entries in `config/app.php` (§11 item 1), each
defaulting to `null` (optional fields) except where §6.1's bundled SVGs
supply a non-null default (logo/favicon only — the compact/dark
variants and both illustrations are genuinely optional per §4, and
`BrandingPresenter`, §6.4, resolves their own fallback to the primary
logo or the bundled illustration respectively, never to a second
broken-image state).

### 6.3 Upload validation and safe storage (`ValidBrandingImageRule`, `BrandingUploadService`)

- `ValidBrandingImageRule` (a new `Illuminate\Contracts\Validation\Rule`
  implementation): rejects any file whose first bytes do not match a
  known raster-image magic signature (PNG `\x89PNG`, JPEG `\xFF\xD8\xFF`,
  WEBP `RIFF....WEBP`) **regardless of claimed extension or MIME type**
  — closing exactly the gap §3.4 found, mirroring the Slice-1 theme-font
  contract's own `ValidFontFileRule` mechanism precisely. **SVG is
  rejected outright for every owner-upload field, unconditionally** — no
  sanitization mechanism is built or trusted for owner-supplied SVG in
  this contract (the task's own explicit instruction: "exclude SVG or
  require a specifically defined sanitization mechanism" — this
  contract chooses exclusion, the lower-risk option, since none of the
  locked requirements actually need owner-uploaded SVG; the bundled
  fallback SVGs, §6.1, are implementation-authored source files, never
  passed through this upload path). A dimension/aspect-ratio check
  (via `getimagesize()` on the already-magic-byte-confirmed file, never
  on client-supplied metadata) enforces per-field bounds: full logo
  max 800×200px / 4:1 aspect tolerance, compact logo max 200×200px /
  roughly-square tolerance, favicon max 512×512px, illustrations max
  1600×1600px — chosen to comfortably exceed the existing `->fit(150,
  26, ...)`/`->fit(32, 32, ...)` render-time resize (§3.4) without
  permitting an unreasonably large upload. A maximum file size of 2MB
  applies to every field (logos/favicon/illustrations alike) —
  generous for a raster brand asset, well below any size that could
  meaningfully strain `public_path()` storage or Intervention Image's
  own processing.
- `BrandingUploadService`: generates the stored filename as
  `hash('sha256', $bytes) . '.' . $extension`, where `$extension` is
  derived from the **validated** magic-byte match (§ above), never from
  `$file->getClientOriginalExtension()` — the exact fix for §3.4's
  finding. Stores under `public_path('images/branding/{field}/{safe_filename}')`
  (one subdirectory per field, keeping the existing `images/logo/`
  directory `AppConfig::uploadFile()` already uses for the two existing
  fields entirely untouched — new fields never collide with it).
  **Replacement**: uploading a new file for an already-configured field
  writes the new file under its own new content-hashed name and updates
  the `.env`/`config` pointer; the previous file is deleted from disk
  only after the new one is confirmed written and the config pointer
  updated (write-then-swap-then-delete, never delete-then-write, so a
  failed upload never leaves the field pointing at a missing file).
  **Deletion** ("remove this branding asset," returning a field to
  "unconfigured, use the neutral fallback"): clears the `.env`/`config`
  pointer back to `null` (or, for logo/favicon, back to §6.1's bundled
  default path) and deletes the now-unreferenced file from disk. **Fail
  closed**: any validation failure, storage-write failure, or
  post-write integrity mismatch (re-read the just-written file, confirm
  its own SHA-256 matches the computed filename) aborts the entire
  operation with no partial state — no `.env`/`config` pointer is ever
  updated to reference a file that is not confirmed, verified, and
  already fully written.
- **No filesystem path, storage identifier, or internal ID is ever
  exposed in the UI** — the admin form (§11 item 16) shows only a
  preview `<img>` of the currently-configured asset (rendered through
  §6.4's own presenter, the same seam every other page uses) and a
  plain "Remove" control, never a raw path string or database ID.

### 6.4 Rendering presenter (`BrandingPresenter`)

`app/Library/Branding/BrandingPresenter.php`, a plain PHP class (not a
Facade, not a global helper function — resolved via Laravel's container
exactly once per new Blade component's own constructor, matching how
`PlatformThemeManager` is already consumed today), exposing:

- `logo(string $variant = 'full', string $background = 'light'):
  array{src: string, alt: string, isFallback: bool}` — resolution
  order: the owner-configured value for the exact requested
  variant/background combination, else the owner-configured **full,
  light-background** logo (a sensible single-value fallback before
  falling further), else `null` — at which point the calling component
  (§6.5) renders the deterministic text/SVG mark instead of an `<img>`
  at all, never an `<img>` with an empty or missing `src`.
- `favicon(): string` — the configured favicon path, or §6.1's bundled
  default; never empty.
- `illustration(string $surface): array{src: string, alt: string}` —
  `$surface` is `'auth'` or `'installer'`; owner-configured value, else
  the appropriate existing bundled Vuexy illustration (§3.8) for that
  exact surface — never a broken reference, since a real, already-
  shipped file is always the floor.
- `footerCompanyName(): string` — configured value, else
  `config('app.name')` (never empty, since `config('app.name')` itself
  always has a real value per §5).
- `footerCopyrightLine(): string` — composes
  `"© {$currentYear} {$companyName}. {$copyrightWording}"` (copyright
  wording omitted entirely, not rendered as a stray period, if the
  owner has not set one) — `$currentYear` is `now()->year`, computed at
  render time on every request, **never stored, never owner-editable as
  a literal value** (§4's own explicit "automatic current copyright
  year" requirement, structurally guaranteed rather than a value someone
  could leave stale).

Results are cached under one constant key
(`platform_branding:resolved`) via the app's already-configured default
cache store — the identical mechanism `PlatformThemeManager::currentStyleBlock()`
already established (§6.13 of the M2 contract), invalidated the same
way: `DB::afterCommit(fn () => Cache::forget('platform_branding:resolved'))`,
registered from within `BrandingUploadService`'s own save/delete
operations, never invoked synchronously mid-request. Reusing this exact
mechanism (rather than inventing a second caching convention) is a
deliberate consistency choice, not a coincidence.

### 6.5 Blade components (the only seam any view uses)

Four new components, `resources/views/components/branding-*.blade.php`,
each a thin view backed by `BrandingPresenter` via its own
component-class constructor injection (Laravel's standard `<x-name>`
auto-discovery, identical mechanism to every existing
`resources/views/components/*.blade.php` file) — **no Blade view in
this contract's own allowlist calls `app(BrandingPresenter::class)`,
`config('app.logo')`, or any repository/manager directly; every one
uses only the component tag**, satisfying the locked "no Blade view may
resolve repositories, managers, or service-container dependencies
directly" requirement exactly:

- `<x-branding-logo variant="full|compact" background="light|dark" />`
  — renders `<img>` when `BrandingPresenter::logo()` returns a real
  asset; otherwise renders a `<span>` deterministic text/mark (the
  first letter(s) of `config('app.name')` in a small rounded badge,
  using the current Milestone-1/Slice-1 color tokens so it always
  matches the active theme, with the full app name as visually-hidden
  accessible text via the same `.sr-only`-shaped utility class the
  existing Bootstrap/Vuexy base already ships) — never a broken `<img>`,
  satisfying "no broken-image icons" and "accessible text or a
  deterministic neutral mark" structurally, not by convention.
- `<x-branding-favicon />` — emits the `<link rel="shortcut icon">` tag,
  used once per master layout (§11 items 21-23), replacing the raw
  `<?php echo asset(config('app.favicon')); ?>` inline PHP each layout
  currently repeats identically three times.
- `<x-branding-illustration surface="auth|installer" />` — renders the
  existing dark/light `<img>` pair pattern every auth/installer view
  already uses (`@if($skin=='dark') ... @else ... @endif`-shaped,
  matching each file's own existing markup exactly), sourced from
  `BrandingPresenter::illustration()`.
- `<x-branding-footer />` — renders the composed copyright line from
  `BrandingPresenter::footerCopyrightLine()`, replacing
  `panels/footer.blade.php`'s existing raw `{!! config('app.footer_text')
  !!}` output.

### 6.6 Authorization (no new permission string)

Every new field lives on the same `_general.blade.php` page,
posted to the same `SettingsController::postGeneral()` action, gated by
the same already-established `PostGeneralRequest::authorize()` →
`$this->user()->can('general settings')` check, itself independently
reinforced by `routes/admin.php`'s blanket admin middleware group. **No
new permission string is introduced** — a deliberate, confirmed
non-change, exactly matching the M2 Slice-1 contract's own §6.15
"authorization confirmation" pattern for its own preset actions. CSRF
protection is inherited automatically from the existing form's own
`@csrf` directive and Laravel's global `VerifyCsrfToken` middleware —
no new exemption, no new route group, nothing to configure.

---

## 7. Open technical decisions (category-3, resolved at implementation time within the constraints stated here, never silently guessed)

1. **`AppConfig::uploadFile()`'s existing dead DB-row write.** This
   contract's own new upload path does not call `AppConfig::uploadFile()`
   for any field, existing or new (§6.3 supersedes it for every
   branding field this contract touches). Whether `AppConfig::uploadFile()`
   itself should be deleted as now-fully-superseded dead code, or left
   in place in case an unaudited caller elsewhere still depends on it,
   is confirmed by an exhaustive `grep -rn "AppConfig::uploadFile"`
   across the entire `app/` tree at implementation time before either
   choice is made — never assumed unreferenced.
2. **Exact deterministic-mark glyph/wordmark composition for
   `default-logo.svg`/`default-logo-compact.svg`/`default-favicon.svg`.**
   §6.1 fixes the mechanism (plain, hand-authored SVG; token-colored;
   accessible `<title>`) and the non-negotiable constraints (no Ultimate
   SMS/Codeglen branding, legible on light and dark, never a broken
   reference) — the exact glyph (full wordmark vs. initials-only
   monogram) is confirmed against the then-current Milestone-1 token
   values before the three files are authored, never guessed ahead of
   knowing which tokens are live.
3. **`custom_script`'s own existing `sanitizeScript()` method** (called
   by `SettingsController::postGeneral()` today, unrelated to any
   branding field) is confirmed, by direct read, to have zero
   interaction with any path this contract adds before implementation
   begins — stated explicitly in the implementation report rather than
   assumed from this contract's own audit alone.

---

## 8. Excluded scope

- Agency/Workspace/Business-level branding overrides — global
  platform-owner configuration only in this contract; explicitly named
  here as a separately-governed future capability (§0/§4).
- `auth/payment/**` (8 files, Slice 12), `resources/views/emails/**`
  (10 files, permanently out of scope per the M2 contract's own §3.1
  finding), `admin/Invoices/**`, `customer/Accounts/**`,
  `customer/Contacts/{subscribe_form,unsubscribe_form}.blade.php` (6
  files, Slices 12/18) — none touched; all benefit automatically from
  §5's corrected `config/app.php` defaults without any file of their
  own changing (§3.6).
- `admin/settings/AllSettings/_license.blade.php`'s purchase-code/license
  text — flagged (§3.7) for a separate, explicit human decision, never
  silently rewritten by this or any future contract without one.
- `admin/Plugins/index.blade.php`'s plugin-marketplace descriptive text
  (Slice 20), `admin/SendingServer/create.blade.php` (its own
  independently-flagged future slice), `admin/settings/UpdateApplication/index.blade.php`
  (Slice 21), `customer/Developers/**` example-payload "Codeglen" text
  (Slice 15) — named, deferred, not touched here (§3.7).
- The doubled-extension `not-authorized-dark.svg.svg` reference in
  `subAccount/acceptInvitation.blade.php` — a discovered, unrelated
  pre-existing defect, not required by any locked requirement this
  contract implements, not fixed here (§3.8).
- Any full visual/token/component migration of `admin/settings/**`'s
  remaining 25 files, or of this one file's non-branding fields
  (country/timezone/date-format/language/custom-script) — Slice 21's
  own future, separately-authorized scope (§0).
- Any change to `EntitlementManager.php`, RFC-004/RFC-005 business
  logic, routes' authorization rules, migrations, or any non-branding
  behavior of any kind.
- Introducing any new Composer or npm dependency — every new file in
  §11 is built from PHP/Laravel primitives and plain SVG/Blade already
  available in this repository; no `composer.json`/`package.json`
  change is authorized or required.
- Terms of Use / Privacy Policy content (the blank-page finding recorded
  in Slice 2's own manual-verification evidence) — a separate
  configuration/content finding, not branding, and not touched by this
  contract; no evidence gathered during this audit shows a branding
  integration path requires changing it.

---

## 9. Test plan

Six new test files, `tests/Feature/Branding/`:

1. **`BrandingConfigDefaultsTest.php`** — mechanical, source-level:
   `config/app.php`'s `name`/`title`/`keyword` default values contain
   neither `Ultimate SMS` nor `Codeglen` (case-insensitive); `logo`/
   `favicon` defaults are non-empty strings pointing at files that
   actually exist under `public/`.
2. **`BrandingPresenterFallbackTest.php`** — `BrandingPresenter::logo()`
   returns the bundled default when unconfigured, the owner-configured
   value when set, correctly distinguishes `variant`/`background`
   combinations, and falls back to the full/light logo before ever
   returning `null`; `favicon()` never returns an empty string;
   `illustration()` falls back to the correct existing bundled Vuexy
   file per surface; `footerCopyrightLine()` contains the current
   calendar year (asserted via `now()->year`, never a hardcoded
   expected year) and omits copyright wording cleanly when unset.
3. **`BrandingComponentRenderTest.php`** — HTTP-level: `login`,
   `register`, `verify`, `Installer::welcome`, and each `errors/*` view
   render `200`, contain no literal `Ultimate SMS`/`Codeglen` string,
   and never render an `<img>` tag with an empty `src` attribute for
   the logo/favicon/illustration components.
4. **`BrandingUploadValidationTest.php`** — a renamed non-image file
   (e.g. a PHP payload saved as `logo.png`) is rejected by
   `ValidBrandingImageRule` despite a passing extension/claimed-MIME
   check; an SVG upload is rejected unconditionally for every branding
   field; an oversized file is rejected; the stored filename is
   confirmed to be the SHA-256 of the uploaded bytes, never the
   client-supplied filename or extension; a user without `'general
   settings'` is denied on every branding upload/removal action; a
   deliberately-corrupted mid-upload failure leaves the previous
   configured asset untouched (fail-closed, §6.3).
5. **`BrandingFooterRenderTest.php`** — the rendered footer contains
   the configured company name and copyright wording plus the current
   year, and contains neither `Ultimate SMS` nor `Codeglen` in the
   default (unconfigured) state.
6. **`BrandingDesignSystemContentTest.php`** — mechanical source-level
   test, mirroring `AuthDesignSystemContentTest`'s own established
   pattern from Slice 2: zero remaining `Ultimate SMS`/`Codeglen`
   literal strings across every one of this contract's own 41
   production files (§11 items 1-41), explicitly excluding the six
   named-and-deferred files in §3.7/§8 that remain out of scope by
   deliberate decision, not oversight.

**No existing test requires modification.** Confirmed by exhaustive
grep: zero existing test files anywhere in `tests/` assert
`Ultimate SMS`, `Codeglen`, `app_logo`, `app_favicon`, or `footer_text`
(one unrelated comment in `WorkspaceM1BBoundaryTest.php` merely
*mentions* "Ultimate SMS" in passing prose, asserting nothing about it);
no test file exists for `SettingsController::postGeneral()` at all
today.

---

## 10. Acceptance criteria

- Every locked requirement in §4 is satisfied by a named architecture
  decision in §6 and a named path in §11.
- `/login`, `/register`, the Installer welcome screen, and every
  `errors/*` view render with a real, non-broken logo (either
  owner-configured or the bundled neutral fallback) and a real favicon,
  on a completely fresh install that has never visited Settings →
  General.
- No literal `Ultimate SMS` or `Codeglen` string renders anywhere
  within this contract's own 41-file production surface, by default,
  on a fresh install (§9 item 6, mechanically proven).
- An owner who uploads a full logo, a compact logo, a dark-background
  logo, a favicon, an auth illustration, an installer illustration, a
  footer company name, and footer copyright wording sees every one of
  them rendered in its correct location, correctly falling back to the
  light-background logo, then to the neutral mark, exactly per §6.4's
  stated resolution order, for any field left unset.
- Every upload is validated by real content signature, never by
  claimed extension/MIME alone; SVG is rejected unconditionally; every
  stored filename is content-derived, never client-derived.
- Zero new Composer/npm dependency; zero new permission string; zero
  new database migration/table.

---

## 11. Exact implementation allowlist

**Closed, numbered, path-level, exactly 47 unique, sequential entries
(41 production + 6 test). Any additional path required during
implementation is a required-48th-path-shaped stop condition (§13).**

### Configuration defaults (1)

1. `config/app.php` — modified: `name`/`title`/`keyword` defaults made
   neutral (§5); `logo`/`favicon` defaults point at the new bundled
   assets (§6.1) instead of an empty string; `footer_text` key removed
   in favor of items below; six new optional keys added
   (`logo_compact`, `logo_dark`, `auth_illustration`,
   `installer_illustration`, `footer_company_name`,
   `footer_copyright_text`), each `env(...)`-backed, each defaulting to
   `null` except where §6.1 supplies a bundled default. No unrelated
   `config/app.php` key changes.

### New bundled neutral fallback assets (3)

2. `public/images/branding/default-logo.svg`
3. `public/images/branding/default-logo-compact.svg`
4. `public/images/branding/default-favicon.svg`

### Domain/storage extension (1)

5. `app/Models/AppConfig.php` — modified: `defaultSettings()` gains the
   six new keys (§6.2) as seed rows; `uploadFile()`/`setEnv()`
   themselves unmodified (§7 item 1 resolves whether `uploadFile()`
   becomes dead code, not whether it is edited here).

### Upload validation and safe storage (3)

6. `app/Rules/ValidBrandingImageRule.php` — §6.3, magic-byte validation,
   unconditional SVG rejection, dimension/aspect bounds.
7. `app/Library/Branding/BrandingUploadService.php` — §6.3, safe
   content-hashed filenames, write-then-swap-then-delete replacement,
   fail-closed on any validation/storage/integrity failure.
8. `app/Library/Branding/Exceptions/InvalidBrandingAssetException.php`

### Rendering presenter and components (5)

9. `app/Library/Branding/BrandingPresenter.php` — §6.4, resolution
   order, cache key, `DB::afterCommit` invalidation.
10. `resources/views/components/branding-logo.blade.php`
11. `resources/views/components/branding-favicon.blade.php`
12. `resources/views/components/branding-illustration.blade.php`
13. `resources/views/components/branding-footer.blade.php`

### HTTP surface (2)

14. `app/Http/Controllers/Admin/SettingsController.php` — modified:
    `postGeneral()` routes `app_logo`/`app_favicon` and the six new
    fields through `BrandingUploadService` instead of
    `AppConfig::uploadFile()`; `footer_text` handling replaced with
    `footer_company_name`/`footer_copyright_text`; the existing
    `config('app.stage') == 'demo'` gate and every other field
    (country/timezone/date-format/language/custom-script) unchanged.
15. `app/Http/Requests/Settings/PostGeneralRequest.php` — modified:
    `app_logo`/`app_favicon` rules now include `ValidBrandingImageRule`;
    six new nullable/`sometimes` field rules added (image fields via
    `ValidBrandingImageRule`, text fields via `string|max:255`);
    `footer_text` rule removed, replaced by
    `footer_company_name`/`footer_copyright_text` (both nullable — an
    owner may leave either blank, §6.4's own graceful-omission
    behavior).

### Admin settings view (1)

16. `resources/views/admin/settings/AllSettings/_general.blade.php` —
    modified: `app_logo`/`app_favicon` fields keep their existing
    inputs, each gains a live preview via `<x-branding-logo>`/
    `<x-branding-favicon>`; six new fields added (compact logo,
    dark-background logo, auth illustration, installer illustration,
    footer company name, footer copyright wording — auto-year noted in
    its own label as automatic, not an input); the existing
    `footer_text` input removed. No other field on this page (country,
    timezone, date format, time format, language, custom script,
    company address) is touched.

### Shared-chrome adoption (7)

17. `resources/views/panels/sidebar.blade.php` — both existing logo
    `<img>` occurrences replaced with `<x-branding-logo variant="full"
    background="light" />` (or `variant="compact"` for the
    collapsed-state occurrence, matching that occurrence's own existing
    markup intent).
18. `resources/views/panels/navbar.blade.php` — both occurrences
    replaced with `<x-branding-logo variant="full" background="light"
    />`.
19. `resources/views/panels/horizontalMenu.blade.php` — same.
20. `resources/views/panels/footer.blade.php` — `{!! config('app.footer_text')
    !!}` replaced with `<x-branding-footer />`.
21. `resources/views/layouts/fullLayoutMaster.blade.php` — the inline
    `<?php echo asset(config('app.favicon')); ?>` line replaced with
    `<x-branding-favicon />`.
22. `resources/views/layouts/detachedLayoutMaster.blade.php` — same.
23. `resources/views/layouts/contentLayoutMaster.blade.php` — same.

### Errors module (7)

24. `resources/views/errors/401.blade.php`
25. `resources/views/errors/403.blade.php`
26. `resources/views/errors/404.blade.php`
27. `resources/views/errors/419.blade.php`
28. `resources/views/errors/429.blade.php`
29. `resources/views/errors/500.blade.php`
30. `resources/views/errors/503.blade.php`

Each: the existing single `<img src="{{asset(config('app.logo'))}}"
alt="{{config('app.name')}}"/>` replaced with `<x-branding-logo
variant="full" background="light" />`.

### Auth/Installer adoption (11)

31. `resources/views/auth/login.blade.php` — logo replaced with
    `<x-branding-logo>`; the three `@codeglen.com` demo-mode auto-fill
    addresses (lines 212, 216, 220) replaced with neutral example
    addresses; the `config('app.stage') == 'demo'` gate around this
    feature itself unchanged.
32. `resources/views/auth/register.blade.php` — logo only.
33. `resources/views/auth/verify.blade.php` — logo only.
34. `resources/views/auth/twoFactor.blade.php` — logo only.
35. `resources/views/auth/twoFactorBackUp.blade.php` — logo only.
36. `resources/views/auth/passwords/email.blade.php` — logo only.
37. `resources/views/auth/passwords/reset.blade.php` — logo only.
38. `resources/views/auth/subAccount/acceptInvitation.blade.php` — logo
    only (the unrelated doubled-extension illustration bug, §3.8, is
    not touched).
39. `resources/views/Installer/welcome.blade.php` — logo replaced;
    `@section('title', 'Ultimate SMS Auto Installer')` changed to read
    from `config('app.name')` (e.g. `@section('title', config('app.name')
    . ' Installer')`).
40. `resources/views/Installer/update/welcome.blade.php` — same logo +
    title treatment.
41. `resources/views/Installer/update/overview.blade.php` — logo
    replaced; `@section('title', 'Ultimate SMS Update')` changed the
    same way.

### Tests (6)

42. `tests/Feature/Branding/BrandingConfigDefaultsTest.php`
43. `tests/Feature/Branding/BrandingPresenterFallbackTest.php`
44. `tests/Feature/Branding/BrandingComponentRenderTest.php`
45. `tests/Feature/Branding/BrandingUploadValidationTest.php`
46. `tests/Feature/Branding/BrandingFooterRenderTest.php`
47. `tests/Feature/Branding/BrandingDesignSystemContentTest.php`

**Total: 47 files, numbered 1-47, sequential, no gaps, no duplicates —
mechanically verified (§14).** Any path beyond this list required
during implementation is a required-**48th**-path-shaped stop
condition (§13).

---

## 12. Manual visual-verification matrix

Structured, screenshot-evidenced in the implementation report, at three
widths matching the existing Bootstrap breakpoints (375px mobile, 768px
tablet, 1440px desktop), each in both light and dark theme (Milestone-1
tokens):

| Surface | Unconfigured (neutral fallback) | Owner-configured |
|---|---|---|
| Desktop sidebar/navigation | Neutral mark, no broken image | Full + compact logo, correct light/dark variant |
| Mobile header/navigation | Neutral mark, compact variant | Compact logo |
| Login / registration | Neutral mark; bundled illustration | Configured logo + illustration |
| Forgot/reset password | Neutral mark; bundled illustration | Configured logo + illustration |
| Email verification | Neutral mark | Configured logo |
| Two-factor authentication | Neutral mark; bundled illustration | Configured logo + illustration |
| Installer / update-installer | Neutral mark; bundled illustration; neutral title | Configured logo + illustration + name-derived title |
| Public/legal layouts (errors) | Neutral mark | Configured logo |
| Outbound email templates | Real bundled default file (not neutral-mark markup, §6.1) | Configured logo |
| Page title / favicon | Bundled default favicon | Configured favicon |
| Footer | Neutral company name (= app name) + current year, no wording | Configured company name + wording + current year |

---

## 13. Stop conditions

Implementation must stop, leave the working tree unstaged, and report
rather than proceed, if:

- Any path beyond §11's 47-item allowlist is required — the **48th**
  path.
- Any change to `app/`, `database/`, or `routes/` beyond §11 items 1,
  5-9, 14-15 appears necessary.
- Any owner-upload field is found to accept SVG despite §6.3's explicit
  exclusion.
- Any stored branding filename is found to derive from client-supplied
  input rather than validated content.
- Any code path exposes a filesystem path, storage identifier, or
  internal ID in rendered UI.
- Any existing test fails for a reason not fixable within this
  contract's own allowlist.
- `admin/settings/AllSettings/_license.blade.php`'s purchase-code text
  is found to require changing to satisfy any locked requirement (it
  must not be silently rewritten, §3.7/§8 — a genuine need to touch it
  is itself a stop-and-report condition, not an invitation to add it to
  the allowlist unilaterally).
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is
  found necessary to reference.
- A new Composer or npm dependency, a new database migration/table, or
  a new permission string is found necessary.

---

## 14. Contract self-audit

1. Every locked requirement (§4) is traced to a named architecture
   decision (§6) and a named allowlist path (§11). ✓
2. This document is genuinely self-contained — no section requires
   consulting an earlier commit or a different contract for the
   complete text of any requirement or decision. ✓
3. The legacy-branding inventory (§3.7) classifies every mechanical
   search hit into one of the four required categories, with an
   explicit reason for every exclusion, never a silent drop. ✓
4. The broken-logo root cause is identified precisely (an empty-string
   config default, §3.3), not assumed, and its fix (§5/§6.1) is
   structural (a real bundled file always exists) rather than a single
   patched default value alone. ✓
5. No SVG upload path exists anywhere in this contract's own scope
   (§6.3); the three new bundled SVGs are implementation-authored
   source files, never an owner-upload target. ✓
6. Every new upload's filename is content-derived (SHA-256 of validated
   bytes), never client-derived (§6.3) — the exact fix for the one
   concrete security gap this audit found in the existing
   `AppConfig::uploadFile()` mechanism (§3.4). ✓
7. No Blade view in §11 resolves a repository, manager, or
   service-container dependency directly — every one uses only the four
   new components (§6.5), themselves the only callers of
   `BrandingPresenter`. ✓
8. Fallback order is stated exactly, per asset variant, in §6.4 — never
   left implicit. ✓
9. Authorization requires no new permission string (§6.6), reusing the
   already-established, already-adequately-scoped `'general settings'`
   gate. ✓
10. The out-of-scope boundary with the 21-slice rollout map is stated
    explicitly (§0, §8) — this contract's one overlap with Slice 21's
    own glob is named and justified, not silently claimed as within
    this contract's authority to migrate that page's other fields. ✓
11. Zero new dependency, zero new migration/table, zero new permission
    string (§8, §10) — each stated as a deliberate non-change, not an
    oversight. ✓
12. Allowlist total is exactly 47, numbered 1-47, sequential, with no
    duplicate path anywhere — mechanically verified before commit
    (§15). ✓
13. The stop threshold is explicitly the 48th path, stated consistently
    in §0, §11, and §13. ✓
14. No existing test requires modification — confirmed by exhaustive
    grep (§9), not assumed. ✓
15. This document remains the only file changed on this branch (§2). ✓

---

## 15. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path,
   `docs/automation/DESIGN-SYSTEM-M2-PLATFORM-BRANDING-CONTRACT.md`,
   nothing else, nothing staged.
2. Section 11's numbered items counted mechanically and confirmed equal
   to exactly 47, with no gap and no repeated number in the sequence
   1-47; every path string confirmed unique.
3. `git diff --check` — clean (no whitespace-error or conflict-marker
   findings; the file is newly created, so this is run against the
   staged content).
4. `git diff --name-only` / `git status --short` / `git diff --cached
   --name-only` all confirm the same single path, before and after
   staging.
5. Stage the one file by its exact path only
   (`git add docs/automation/DESIGN-SYSTEM-M2-PLATFORM-BRANDING-CONTRACT.md`),
   never `git add -A`/`.`.
6. Commit exactly: `docs: add platform branding and assets contract`.
7. Push normally to `origin
   chore/design-system-m2-platform-branding-contract`. No force push.
   Do not push `main`.
8. Open a PR into `main` if tooling permits; otherwise report the exact
   GitHub comparison URL.
9. **Do not merge. Do not begin implementation.** Both require a
   separate, explicit, future human authorization.
10. PHP/JS tests are not required for this one-file docs-only change
    and are not run — reported honestly as not run, no count fabricated.

---

*End of Design System M2 Platform Branding & Assets Contract.
Implementation requires a separate, explicit human instruction. This
contract's own merge does not start or resume it.*
