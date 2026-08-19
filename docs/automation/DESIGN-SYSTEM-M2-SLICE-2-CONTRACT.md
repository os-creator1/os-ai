# Design System — Milestone 2, Slice 2 Contract: Authentication & Profile

**This document is fully self-contained.** No section below requires
consulting an earlier commit, the Milestone 1 contract, or the Milestone
2 contract to understand Slice 2's complete rules — every requirement,
architecture decision, and path is restated here in full.

**Status: contract only. No implementation has occurred under this
document. Merging this contract does NOT authorize implementation —
Slice 2 implementation requires its own separate, explicit human
authorization, exactly like Slice 1 (`agent/design-system-m2-slice1`,
PR #87) required its own.**

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice2-contract`, in an
  isolated linked worktree (`../design-system-m2-slice2-contract-worktree`),
  based on `origin/main` at `fac4e69954e75d68a68deeaf2b9e5a89f6b17df8`.
- Verified before drafting: Design System M1 is merged (PR #84,
  `b240715`); Design System M2 Slice 1 implementation PR #87 is merged
  (`fac4e69`); the verified Slice 1 product head
  `a65602ebe3d11b63b704ccff635a88f8162383ca` is an ancestor of
  `origin/main` (`git merge-base --is-ancestor` confirmed true); the
  Slice 1 manual-verification PR #88 is merged (`d17ca59`).
- Drafting this contract makes **zero** application changes. No
  `resources/`, `app/`, `database/`, or `routes/` file is touched by
  this branch — only this one document.
- Slice 2 is the rollout-map group named **"Authentication & Profile"**
  in `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` §8: globs
  `resources/views/auth/**` (excluding `resources/views/auth/payment/**`)
  and `resources/views/Installer/**`. That table's own historical file
  count for this slice was **27** — this contract does not assume that
  figure is still correct; §3.1 below re-derives it mechanically from
  the current `origin/main` tree and confirms it independently.
- `maximum_correction_rounds: 2` applies to this contract, unchanged.
- Any path required during Slice 2 implementation but absent from §9's
  own numbered allowlist is a stop-and-report condition — not a silent
  workaround. The stop threshold is the **37th** path (36 allowlisted +
  1).
- This contract authorizes **only** Slice 2. It does not authorize
  Dashboards (Slice 3), Reports & Analytics (Slice 4), Contacts & CRM
  (Slice 5), ChatBox (Slice 6), Campaigns (Slices 7a-c), Automations
  (Slice 8), Templates (Slice 9), Numbers/SenderID/Keywords/Compliance
  (Slice 10), Sending Servers (Slices 11a-d), Billing/Payments/Accounts
  (Slice 12, which explicitly owns `auth/payment/**` — excluded here for
  exactly that reason), Sub-Accounts & Workspaces (Slice 13), Onboarding
  (Slice 14), Developer/API Docs (Slice 15), Admin Tenant Management
  (Slice 16), Plans/Pricing/Catalog (Slice 17), Invoices & Subscriptions
  (Slice 18), Admin Users/Roles/Announcements (Slice 19), Plugins/Legacy
  Theme Customizer (Slice 20), System Settings (Slice 21), or
  transactional email templates (permanently out of scope for the
  entire rollout, per the M2 contract's own §3.1 finding).

---

## 1. Mandatory preflight — verified

1. `git fetch origin` — `origin/main` confirmed at exactly
   `fac4e69954e75d68a68deeaf2b9e5a89f6b17df8`.
2. `git log origin/main --oneline -15` confirms, in order:
   `fac4e69` merges PR #87 (`agent/design-system-m2-slice1`); `d17ca59`
   merges PR #88 (the Slice 1 manual-verification record); `b0cb468`
   records that verification in `AI-AUTONOMY-STATE.json`'s
   `manual_verification` field; `a65602e` is the exact verified Slice 1
   product head (Mix-manifest + contrast-validator + `app.js` fixes);
   `fb5c823` merges PR #84 (`agent/design-system-m1`).
3. `git merge-base --is-ancestor a65602ebe3d11b63b704ccff635a88f8162383ca origin/main`
   exits `0` — confirmed a genuine ancestor, not merely "present
   somewhere in history."
4. No local or remote branch named
   `chore/design-system-m2-slice2-contract` existed before this branch
   was created (`git branch --list` and
   `git ls-remote --heads origin` both empty). The intended worktree
   path (`../design-system-m2-slice2-contract-worktree`) did not exist.
5. The primary implementation worktree
   (`C:\AI-Business-OS\ultimate-sms\public_html`, branch
   `agent/design-system-m2-slice1`, HEAD `a65602e...`) was confirmed
   untouched both before and after this drafting session — `git status
   --short` shows only the pre-existing untracked `.claude/` directory,
   nothing staged, nothing modified.
6. Read in full: `AGENTS.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md`
   (Milestone 1), `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`
   (Milestone 2, Slice 1 + rollout map), `docs/automation/AI-SUBSCRIPTION-LOOP.md`,
   current `docs/automation/AI-AUTONOMY-STATE.json` (confirmed idle:
   `active_pull_request: null`, `active_rfc: "RFC-003"`, unrelated to
   this Design System track — its own `manual_verification` field
   records Slice 1's evidence and is left untouched here).

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**:
`docs/automation/DESIGN-SYSTEM-M2-SLICE-2-CONTRACT.md` (this document).
No `resources/`, `app/`, `database/`, `routes/`, or `AI-AUTONOMY-STATE.json`
file is touched by drafting this contract.

---

## 3. Mandatory repository audit — findings

### 3.1 Mechanical view enumeration (re-derived, not assumed)

Direct `Glob`/`find` against `resources/views/auth/**` returned **32**
files. Excluding `resources/views/auth/payment/**` (8 files —
`authorize_net.blade.php`, `braintree.blade.php`, `easypay.blade.php`,
`fedapay.blade.php`, `nowpayments.blade.php`, `offline.blade.php`,
`stripe.blade.php`, `vodacom_mpesa.blade.php` — Slice 12's own scope,
per the M2 rollout map) leaves **24**. `resources/views/Installer/**`
contains **3** files (`welcome.blade.php`,
`update/welcome.blade.php`, `update/overview.blade.php`) — confirmed by
direct search there is no separate, differently-named Installer view
directory anywhere else in the tree (`admin/Plugins/install.blade.php`
is a *plugin* installer, an unrelated view under `admin/Plugins/**`,
Slice 20's scope, not this one).

**24 + 3 = 27 total files under Slice 2's own globs** — independently
re-derived from the current tree, not copied from the M2 contract's
§8 table. It happens to equal that table's own historical figure, but
this figure is confirmed current, not assumed carried forward.

### 3.2 Rendered / stale classification (direct controller and route audit)

Every file was traced to its actual render call site:

- **24 of 24** `auth/**` (excl. payment) files are genuinely rendered.
  Full trace: `login.blade.php` ← `LoginController::showLoginForm()`;
  `register.blade.php` ← `RegisterController::showRegistrationForm()`;
  `verify.blade.php` ← `VerificationController::verificationNotice()`;
  `twoFactor.blade.php` ← `TwoFactorController::index()`;
  `twoFactorBackUp.blade.php` ← `TwoFactorController::backUpCode()`;
  `passwords/email.blade.php` ← `ForgotPasswordController::showLinkRequestForm()`;
  `passwords/reset.blade.php` ← `ResetPasswordController::showResetForm()`;
  `subAccount/acceptInvitation.blade.php` ← `VerificationController::subAccountAccept()`;
  `termsOfUses.blade.php` ← `PublicController::termsOfUse()`;
  `privacyPolicy.blade.php` ← `PublicController::privacyPolicy()`;
  `loggedAs.blade.php` ← `@include`d unconditionally by
  `panels/breadcrumb.blade.php` (an impersonation-banner partial, shown
  wherever breadcrumbs render and an impersonation session is active);
  `profile/index.blade.php` ← `AccountController::index()`;
  `profile/_accounts.blade.php`, `_security.blade.php`,
  `_notifications.blade.php`, `_information.blade.php`, `_webhook.blade.php`,
  `_two_factor_authentication.blade.php`, `_dlt_entity_id.blade.php`,
  `_dlt_telemarketer_id.blade.php` ← all `@include`d as tab-panel content
  by `profile/index.blade.php` itself (confirmed by direct read of that
  file's `<div class="tab-pane">` structure); `_update_avatar.blade.php`
  ← `@include`d by `profile/_accounts.blade.php`;
  `_announcements.blade.php`, `_update_two_factor_auth.blade.php`,
  `_view_announcement.blade.php` ← each returned directly by a distinct
  `AccountController` action (`announcement()`,
  `generateTwoFactorAuthenticationCode()`/`updateTwoFactorAuthentication()`,
  `viewAnnouncement()`).
- **2 of 3** `Installer/**` files are genuinely rendered:
  `Installer/welcome.blade.php` ← `InstallerController::welcome()`
  (the very first page of a fresh install, reachable whenever
  `storage/installed` does not exist — §3.3/§7 below); `Installer/update/welcome.blade.php`
  ← `UpdateController::welcome()` (reachable only on an **already
  installed** application checking for a pending version update, gated
  by `canUpdate` middleware).
- **1 of 3** `Installer/**` files is **stale/unreachable**:
  `Installer/update/overview.blade.php` has zero references anywhere in
  `app/`, `resources/`, or `routes/` — confirmed by exhaustive grep
  against every controller, view, and route file. `UpdateController`
  has exactly two public actions (`welcome()`, `verifyProduct()`),
  neither of which renders this view. **Explicit treatment (per this
  contract's own required classification): this file is read-only and
  excluded from Slice 2's implementation allowlist — it is dead code,
  not modified, and not counted toward the 27-file rendered total.**
  Deleting genuinely dead code is itself a decision this presentation-
  parity contract does not make unilaterally; it is named here so it is
  never silently forgotten, exactly as Milestone 1 named its own
  deferred items (§6 of that contract).

**Net Slice 2 rendered-view total: 26** (24 auth + 2 Installer).

### 3.3 Installer pre-database rendering — critical finding

Directly traced the exact request lifecycle of a fresh install:
`canInstall` middleware (`app/Http/Middleware/canInstall.php`) gates
every `install/*` route on a single check —
`file_exists(storage_path('installed'))` — **not** on any database or
schema state. On a genuinely fresh install (no `storage/installed`
marker, no migrations run, no tables), `GET /install` is fully
reachable and dispatches to `InstallerController::welcome()`, which
returns `view('Installer.welcome', ...)`.

`Installer/welcome.blade.php` extends `layouts/fullLayoutMaster`, whose
`<head>` unconditionally `@include`s `panels/styles.blade.php`. Direct
read of that file's tail (appended by the merged Slice 1 PR #87,
`docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` §6.3/§9 item 27)
confirms it unconditionally executes:

```blade
@php $platformThemeStyleBlock = app(\App\Library\Theme\PlatformThemeManager::class)->currentStyleBlock(); @endphp
```

`PlatformThemeManager::currentStyleBlock()`
(`app/Library/Theme/PlatformThemeManager.php:40-51`) calls
`$this->presets->findActive()` with **no** `try/catch`, no
`Schema::hasTable()` guard, wrapped only in `Cache::rememberForever`.
`findActive()` queries the `platform_theme_presets` table directly.
**On a fresh install, before `InstallerController::database()` ever
runs `$this->databaseManager->migrateAndSeed()`, this table does not
exist.** The query throws an uncaught
`Illuminate\Database\QueryException` ("Base table or view not found"),
which propagates up through the `@php` block, `panels/styles.blade.php`,
`fullLayoutMaster.blade.php`, and crashes the entire `Installer.welcome`
render with a 500 error.

**This is a genuine, pre-existing, already-merged regression** —
inherited from Slice 1's PR #87, not introduced by anything in this
contract, and not something Slice 1's own contract or tests exercised
(Slice 1's test suite runs entirely post-migration, against
`ultimatesms_testing`, and never simulates a pre-database request).
It silently breaks every fresh installation of this application, today,
on `main`. Because Slice 2's own scope is exactly the file group that
first makes this reachable in a real deployment scenario (nothing before
Slice 2 ever rendered an Installer page as part of its own contract's
concern), this is the correct, narrowly-justified place to fix it — not
a scope-creep addition. §7 resolves this with an exact required
production-code path, per this task's own explicit instruction not to
hide the dependency in Blade.

`Installer/update/welcome.blade.php` is **not** exposed to the same
failure mode: `canUpdate` middleware requires
`canInstall::alreadyInstalled()` to be true first (i.e., the app is
already fully installed, meaning `platform_theme_presets` already
exists from that prior install), so this specific crash path is
Installer-welcome-specific, not both Installer views.

### 3.4 Token, component, and icon infrastructure audit (merged, reusable)

Directly enumerated the merged M1/M2-Slice-1 foundation this slice must
build on, not duplicate:

- **Token files**, all present at `resources/scss/base/tokens/`:
  `_colors.scss`, `_typography.scss`, `_spacing.scss`, `_radii.scss`,
  `_shadows.scss`, `_motion.scss`, `_runtime-bindings.scss`, aggregated
  by `tokens.scss`. Full semantic taxonomy from M2 §4/§6.5 already
  emitted as CSS custom properties.
- **Runtime-bindings retrofit layer**
  (`resources/scss/base/tokens/_runtime-bindings.scss`, M2 §6.10)
  already retokenizes, at minimum: `.btn-primary`, `.btn-secondary`,
  `.btn-outline-primary`, `.btn-flat-primary`/`.btn-flat-secondary`,
  `.form-control`, `.form-select`, `.form-check-input`,
  `.form-check-primary`, `.alert-*`, `.badge`/`.badge-light-*`,
  `.bg-*`/`.bg-light-*`, `.border-*`, `.text-*`, `.dropdown-item`,
  `.nav-link.active`, `.nav-pills`, `.nav-tabs`, `.page-item.active`,
  `.page-link`, `.progress-bar` — directly confirmed by grep against the
  file. **This means the vast majority of native Bootstrap markup
  already used throughout the 26 rendered Slice 2 views (buttons, form
  controls, alerts, badges, the `profile/index.blade.php` tab-pill
  navigation, the Installer requirement/permission-status badges) is
  already runtime-theme-live without any further SCSS work** — Slice 2's
  own required SCSS change (§9 item 28) is narrow, not foundational.
- **Blade component library**, all present at
  `resources/views/components/`: `alert`, `badge`, `button`, `card`,
  `dialog`, `ds-icon`, `empty-state`, `input`, `menu`, `pagination`,
  `select`, `switch-toggle`, `table`, `tabs`, `tooltip` (14 files).
- **Canonical icon seam**: `<x-ds-icon name="..." size="18"
  strokeWidth="1.75" />` (`resources/views/components/ds-icon.blade.php`),
  backed by `svg('lucide-' . $name, ...)` via
  `technikermathe/blade-lucide-icons` on top of `blade-ui-kit/blade-icons`
  — the single existing seam this slice must migrate its own
  `data-feather="..."` markup onto (not a new component to invent).
- **Shared chrome** (`panels/navbar.blade.php`, `sidebar.blade.php`,
  `footer.blade.php`, `breadcrumb.blade.php`, `submenu.blade.php`,
  `horizontalMenu.blade.php`, `horizontalSubmenu.blade.php`,
  `styles.blade.php`) and both master layouts Slice 2 depends on
  (`fullLayoutMaster.blade.php`, `contentLayoutMaster.blade.php`) are
  already fully migrated (Milestone 1 font wiring + Slice 1 icon
  migration, M2 §9 items 27, 54-60) — **read-only dependencies for
  Slice 2, not modified again here.**

### 3.5 Icon-migration surface (mechanical count)

- `grep -rn "data-feather"` across the 24 in-scope `auth/**` files
  (excl. payment): **77 occurrences**, 28 distinct icon names
  (`alert-circle`, `bell`, `check`, `chevron-left`, `chevron-right`,
  `credit-card`, `eye`, `facebook`, `github`, `headphones`,
  `help-circle`, `home`, `info`, `key`, `link`, `list`, `lock`,
  `log-in`, `mail`, `map-pin`, `save`, `stop-circle`, `trash`,
  `trash-2`, `twitter`, `upload`, `user`, `x`). All 28 exist in Lucide's
  icon set under matching or near-identical names (Lucide is the
  direct, actively-maintained continuation of the Feather icon family,
  confirmed by the M1 contract's own §3.5 finding); exact per-icon
  name mapping is a mechanical implementation-time task (§9 item 29's
  own scope), never hand-guessed here.
- Across the 2 rendered `Installer/**` files: **28 occurrences**, 10
  distinct names (`check`, `check-square`, `chevron-left`,
  `chevron-right`, `database`, `eye`, `save`, `server`, `shield-off`,
  `user`) — all standard Lucide-equivalent glyphs, same migration
  pattern.
- **Social/OAuth provider icons — resolved, not silently guessed.**
  `login.blade.php`'s social-login buttons (`.btn-facebook`,
  `.btn-twitter`, `.btn-google`, `.btn-github`) currently render
  **generic Feather glyphs** (`facebook`, `twitter`, `github`, and for
  Google, oddly, a plain `mail` envelope icon) inside brand-colored
  button classes — **not** official third-party brand logos/SVGs in any
  form today. **Decision**: Slice 2 preserves this exact existing visual
  approach — swap each generic Feather glyph for its direct Lucide
  equivalent through the canonical `<x-ds-icon>` seam (Lucide ships
  `facebook`/`twitter`/`github` line-art glyphs in the same generic
  style Feather does), with no behavior change to which icon represents
  which provider. **Introducing genuine official brand-logo SVG assets
  is explicitly out of Slice 2's scope** — that is a new asset-sourcing
  and licensing decision (trademark usage terms per provider), not a
  presentation-parity icon-seam migration, and is named here so it is
  never silently conflated with "preserve official identity-provider
  logos" (which this slice satisfies by not regressing the current,
  already-non-official-logo, presentation).
- `.btn-facebook` (`#3b5998`), `.btn-twitter` (`#55acee`), `.btn-github`
  (`#444444`), `.btn-google` (`#dd4b39`) are defined in
  `resources/scss/base/components/bootstrap-social.scss`, a vendored,
  globally-imported plugin file (imported once via `components.scss`,
  used by dozens of provider button classes beyond the four active
  here). These specific four colors are **official third-party brand
  identity colors**, not the legacy Vuexy purple (`#7367F0`) and not
  part of this app's own owner-configurable palette — correctly
  hardcoded by design, since a platform owner changing their own brand
  primary color must never silently recolor Facebook's or Google's own
  button. **Decision: this file is read-only, explicitly not
  modified.**

### 3.6 Hardcoded-color and font-family audit (Slice 2 scope)

Direct grep for `#[0-9A-Fa-f]{3,8}` across all 26 rendered Slice 2 view
files: **zero genuine color literals** — the only matches
(`resources/views/auth/profile/index.blade.php`,
`resources/views/auth/register.blade.php`) are CSS ID selector
fragments (`#accountActivation`, `#account`, `#addressField`-shaped
strings), confirmed false positives by direct inspection, not colors.
Direct grep for `font-family` (case-insensitive): **zero matches**
anywhere in the 26 files. **Neither requirement (§5) requires any Blade
color/font literal removal in Slice 2 — none exist.** This is
consistent with the M1 contract's own §3.2 finding that inline
hardcoded styling is a negligible surface app-wide; the real color
surface for this slice lives in `authentication.scss` (§3.7) and the
already-covered runtime-bindings layer (§3.4).

### 3.7 The dedicated per-page SCSS file

`resources/scss/base/pages/authentication.scss` (173 lines) is the one
SCSS file all `auth-wrapper`/`auth-cover`/`auth-inner`/
`register-multi-steps-wizard` structural markup in the 26 rendered
views (and Installer's own reuse of the same wrapper classes) depends
on. Direct read confirms: **zero hardcoded hex colors, zero
`$primary`/`$purple`/`7367F0` references, zero `font-family`
declarations** in this file today — it is purely structural/layout
SCSS (flex layout, spacing, the `bs-stepper` wizard chrome). Its
`.auth-basic` variant (decorative base64-encoded PNG corner
illustrations) is confirmed **unused anywhere in Slice 2's 26 rendered
views** (all of them use `.auth-cover` exclusively, verified by grep) —
that dead code path is left untouched, not a Slice 2 concern.

**Decision: this file requires a narrow, structural modification** —
not for colors (none exist), but because two of the rendered views
(`Installer/welcome.blade.php`, `Installer/update/welcome.blade.php`)
each currently embed an **identical, duplicated, page-specific inline
`<style>` block** (rendering the requirements/permissions checklist
table). Migrating that raw `<table>` markup to the canonical
`<x-table>` component (§5's own "consistent... tables" requirement, and
M1's own "no page-specific copies of global styling" architecture
principle) removes the need for this duplicated inline CSS entirely —
both inline `<style>` blocks are deleted from the two Blade files, with
no replacement rule needed in `authentication.scss` or anywhere else
(`<x-table>`'s own styling, already token-driven, supersedes it).

### 3.8 Password-visibility-toggle JS — confirmed unaffected

The global click handler for `.form-password-toggle .input-group-text`
(`resources/js/core/app.js:886`) is keyed to CSS class/DOM structure,
never to `data-feather`. Swapping the wrapped `<i data-feather="eye">`
for `<x-ds-icon name="eye">` inside the existing `.input-group-text`
element does not require any JS change — confirmed by direct read, not
assumed. `app.js` is a read-only dependency for Slice 2.

### 3.9 JS-embedded icon reference — explicit scope boundary

`profile/index.blade.php`'s own inline `@section('page-script')` block
contains one DataTables cell-renderer building an icon via JavaScript
directly: `feather.icons['trash'].toSvg({class: 'font-medium-4'})`
(building a delete-action glyph for each dynamically-rendered
notification row). This is **not** a static `data-feather="..."`
Blade attribute — it is JS-generated markup, built at runtime from
data the DataTables AJAX response returns, which the `<x-ds-icon>`
Blade component (a compile-time server construct) cannot express inside
a JavaScript template string. **Decision: this one JS-embedded call is
explicitly out of the "eliminate `data-feather` markup" requirement's
scope**, which this contract defines precisely as the static
`data-feather="..."` HTML-attribute convention in the 26 Blade files
(§9 item 30's own mechanical search is scoped exactly this way, §11
item 4). Feather's own JS bundle and its global
`feather.replace({width: 14, height: 14})` bootstrap call
(`layouts/fullLayoutMaster.blade.php`'s own `<script>` block) must
therefore **remain loaded and functional** after Slice 2 — consistent
with Milestone 1's own established precedent of leaving Feather's
runtime available for not-yet-migrated call sites, now including this
JS-embedded one, which is out of scope for every rollout slice unless a
future slice explicitly targets DataTables' own icon-rendering
convention app-wide.

### 3.10 Existing test coverage audit

Exhaustive search of `tests/` for any reference to
`route('login')`/`route('register')`, `LoginController`,
`RegisterController`, `VerificationController`, `TwoFactorController`,
`ForgotPasswordController`, `ResetPasswordController`,
`InstallerController`, `UpdateController`, or any `assertViewIs('auth...`
call: **exactly one match**, `tests/Feature/ExampleTest.php`:

```php
public function test_an_unauthenticated_visitor_is_redirected_to_login(): void
{
    $response = $this->get('/');
    $response->assertRedirect(route('login'));
}
```

This test asserts only a redirect **target** (the route name), never
renders or inspects the login view's own content, markup, or CSS
classes — it is unaffected by any presentation change Slice 2 makes and
requires **zero modification**. **No other existing test anywhere in
the suite exercises any of the 27 Slice 2 files, their controllers, or
their routes.** This means Slice 2 carries essentially no existing-test
regression risk, but also that this slice must build its own test
coverage from nothing (§8/§10) — there is no prior safety net to lean
on for validation/CSRF/redirect-behavior proof, unlike Slice 1's own
richer pre-existing Theme test surface.

### 3.11 Read-only dependencies (confirmed, not modified)

Controllers: `app/Http/Controllers/Auth/LoginController.php`,
`RegisterController.php`, `VerificationController.php`,
`TwoFactorController.php`, `ForgotPasswordController.php`,
`ResetPasswordController.php`; `app/Http/Controllers/User/AccountController.php`;
`app/Http/Controllers/PublicController.php`;
`app/Http/Controllers/UpdateController.php`. Middleware:
`app/Http/Middleware/canInstall.php`, `canUpdate.php`. Routes:
`routes/auth.php`, `routes/public.php` (install/update route groups
only), `routes/web.php`. No FormRequest class exists for any of these
controllers — validation is inline `Validator::make()`/hand-written
rules throughout, confirmed by direct read, and is not touched by this
presentation-only slice regardless of its current shape. Inline SVG
illustration assets (`resources/images/pages/create-account.svg` and
siblings, loaded via `<img src="...">`) remain the same confirmed,
honestly-scoped-out gap the M2 contract's own §3.8/§6.11 already named
— cannot inherit `currentColor`/CSS custom properties in their current
form, unchanged by Slice 2, not silently claimed as solved here either.

---

## 4. Locked Slice 2 scope

- `resources/views/auth/**`, excluding `resources/views/auth/payment/**`
  (24 files, §3.1/§3.2).
- `resources/views/Installer/**` (2 genuinely-rendered files; 1
  stale/unreachable file explicitly excluded, §3.2).
- The narrow, evidence-justified shared-integration paths named in §3.3
  and §3.7: `app/Library/Theme/PlatformThemeManager.php`,
  `app/Http/Controllers/InstallerController.php`,
  `resources/scss/base/pages/authentication.scss`.
- No other path. Dashboards, Reports, CRM, ChatBox, Campaigns,
  Automations, Templates, Numbers/Compliance, Sending Servers, Billing
  (including `auth/payment/**`), Sub-Accounts, Onboarding, Developer
  Docs, Admin Tenant Management, Plans/Catalog, Invoices, Admin
  Users/Roles, Plugins/Theme Customizer, and System Settings remain
  entirely out of scope (§0).

---

## 5. Visual and component requirements

Slice 2 implementation must, across its 26 rendered views:

1. **Use the merged design tokens and runtime active-theme values** —
   already substantially satisfied by the runtime-bindings layer for
   native Bootstrap classes (§3.4); no new token infrastructure is
   authorized or required by this slice.
2. **Use the canonical merged Blade component library where suitable**
   — `<x-card>` for the auth panel containers, `<x-button>` for
   submit/social/wizard-navigation buttons, `<x-input>` for text/email/
   password fields, `<x-select>` for the Installer's database-driver
   and timezone dropdowns, `<x-alert>` for validation-summary and
   impersonation-banner (`loggedAs.blade.php`) messaging, `<x-badge>`
   for the Installer's requirement/permission status pills, `<x-table>`
   for the Installer's requirement/permission tables (§3.7), `<x-tabs>`
   for `profile/index.blade.php`'s account/security/notifications tab
   navigation. Not every component fits every view — do not force
   adoption where a component's own contract (e.g. `<x-dialog>`,
   `<x-pagination>`, `<x-empty-state>`, `<x-tooltip>`) has no genuine
   match among these 26 views; state explicitly in the implementation
   report which components were adopted where and why any were not.
3. **Use the canonical merged Lucide icon seam** (`<x-ds-icon>`)
   for all 105 static `data-feather="..."` occurrences across the 26
   views (§3.5) — never introduce a second icon-rendering convention.
   The one JS-embedded DataTables icon call (§3.9) is explicitly
   excluded from this requirement, not silently missed.
4. **Remove Slice 2's hardcoded colors and font-family declarations** —
   §3.6 confirms none exist in the 26 view files today; this
   requirement is satisfied by that audit finding zero, not by
   speculative edits invented to have something to remove.
5. **Eliminate legacy Vuexy purple (`#7367F0`) from Slice 2** —
   confirmed absent from every in-scope file already (§3.6); the
   runtime-bindings layer (§3.4) already prevents its reintroduction
   through native Bootstrap classes.
6. **Preserve official third-party identity-provider logos where
   legally and semantically required** — resolved exactly per §3.5: the
   current app uses generic (non-official) Feather glyphs for
   Facebook/Twitter/GitHub/Google; Slice 2 preserves this exact
   approach via Lucide equivalents, and does not introduce new,
   unlicensed, or unverified "official" logo assets.
7. **Preserve the approved Geist typography, spacing, radius, shadow,
   and motion systems** — already wired at the layout level (M1 §9
   items 36-38); Slice 2 introduces no competing type/spacing scale.
8. **Provide consistent cards, forms, buttons, links, alerts,
   validation messages, and loading states** — per item 2's component
   adoption; loading/disabled states use `<x-button>`'s own existing
   disabled/loading affordances (confirmed present in that component,
   not invented new here), consistent with the wizard/AJAX-submission
   pattern already used by `register.blade.php` and both Installer
   views.
9. **Support 375px mobile, 768px tablet, and 1440px desktop widths** —
   using the existing Bootstrap breakpoints already established by M1
   §3.2 (`sm:576px, md:768px, xl:1200px, xxl:1440px`); no new breakpoint
   system.
10. **Provide visible keyboard focus, correct labels, and accessible
    error associations** — every `<label for="...">`/`id="..."` pairing
    currently present in these views (§6's own preservation
    requirement) must remain intact through the component-adoption
    pass; `<x-input>`'s own existing focus-ring styling (already
    runtime-token-bound, §3.4) satisfies visible-focus without new CSS.
11. **Preserve light-theme fallback and active-preset behavior** — per
    §7's resolved Installer fail-safe boundary; normal (post-install)
    authentication pages already correctly receive
    `PlatformThemeManager::currentStyleBlock()`'s output today via the
    shared `panels/styles.blade.php` injection point (M2 §6.3), unaffected
    by Slice 2 beyond the §7 fix itself.
12. **Inject no arbitrary CSS, JavaScript, or HTML** — every markup
    change in Slice 2 is a fixed Blade template edit against components
    already validated by M1/M2's own schema-level "no free-text
    CSS/HTML field" guarantees (M2 §6.8); nothing in Slice 2 adds a new
    user-facing input surface of any kind.

Authentication screens must look intentional and trustworthy — achieved
here through consistent component usage, real semantic icons instead of
mismatched placeholders (the Google "envelope" icon, §3.5, is corrected
to a proper Lucide equivalent as part of the icon migration, not left
as-is), and removal of the duplicated inline `<style>` blocks (§3.7),
not through any new decorative asset this contract does not otherwise
authorize.

---

## 6. Preserve all behavior

This is a presentation rollout, not an authentication rewrite. No
controller, route, request, middleware, authentication provider, or
business rule may change merely for styling convenience. Slice 2
implementation must preserve exactly:

- **Form actions and HTTP methods** — every `<form>`'s `action`/route
  target and `method` (or `_method` spoofing) unchanged; §3.11 confirms
  no FormRequest exists to accidentally diverge from.
- **CSRF protection** — every `@csrf` directive preserved verbatim.
- **Input names and submitted payloads** — every `name="..."` attribute
  on every form field preserved exactly (§10's own test contract proves
  this mechanically, not by inspection alone).
- **Validation and old-input behavior** — every `old('...')` call and
  `$errors`-rendering location preserved; error messages continue to
  render in their current logical position relative to each field,
  restyled via `<x-alert>`/`<x-input>`'s own error-slot convention
  rather than relocated.
- **Password visibility controls** — the `.form-password-toggle`
  structure and its unaffected JS handler (§3.8) preserved exactly.
- **Remember-me behavior** — `login.blade.php`'s remember-me checkbox
  `name` attribute and default-checked state unchanged.
- **Login, logout, and redirect behavior** — zero change to
  `LoginController`, its `throttleKey()`, or any redirect target.
- **Registration rules** — zero change to `RegisterController::validator()`
  or `register()`'s payment-gateway branching logic (§0's own explicit
  exclusion of `auth/payment/**` reinforces this: the payment-selection
  branch inside `register()` itself is read-only for Slice 2 exactly as
  the payment views themselves are).
- **Password reset and email-verification flows** — zero change to
  `ForgotPasswordController`, `ResetPasswordController`,
  `VerificationController`.
- **Two-factor and recovery flows** — zero change to
  `TwoFactorController`, including its backup-code and resend actions.
- **Social/OAuth provider behavior** — zero change to
  `redirectToProvider()`/`handleProviderCallback()`; only the visual
  glyph representing each provider changes (§3.5/§5 item 6), never the
  `route('social.login', $provider)` targets or provider-config gating.
- **Localization keys and translated text behavior** — every
  `__('locale...')` call site and its argument preserved exactly; no
  translated string's key changes, only its surrounding markup.
- **Rate limiting, authorization, and middleware** — zero change to any
  route-group middleware stack (`auth`, `verified`, `throttle:6,1`,
  `signed`, `install`, `update`) or to `EnsureUserIsAdministrator`-style
  guards anywhere in this surface (none apply here — these are
  unauthenticated/self-service routes by design, and stay that way).
- **Installer step order, prerequisites, environment checks, and
  failure behavior** — zero change to the wizard step sequence (System
  Compatibility → Permissions → Environment Settings → Profile
  Settings), to `RequirementsChecker`/`PermissionsChecker`'s own logic,
  or to `canInstall`/`canUpdate`'s own gating rules — only the §7 fail-
  safe addition to `PlatformThemeManager`/`InstallerController`, which
  changes zero installer *business* logic, only theme-injection
  resilience.
- **Existing element IDs and `data-*` hooks** — every `id="..."` and
  `data-*` attribute consumed by JS (`#environment_form`,
  `#profile_form`, `#system_configuration`, `data-target="..."`,
  `data-bs-toggle="tab"`, `data-action-url`-shaped attributes
  throughout) preserved byte-for-byte; only the icon markup nested
  inside these elements, and the outer structural wrapper classes where
  a component genuinely supersedes hand-written markup, may change.

---

## 7. Installer safety boundary — resolved, not deferred

§3.3 established the exact defect: `PlatformThemeManager::currentStyleBlock()`
crashes on a fresh install because it queries `platform_theme_presets`
before that table exists, with no guard. This section resolves it
completely, per this task's own instruction not to hide the dependency
in Blade.

**Root-cause-correct fix (two coordinated parts, both required
together — fixing only one reintroduces a different failure mode):**

**Part A — fail safe when the table is missing.**
`PlatformThemeManager::currentStyleBlock()` gains an explicit
`Illuminate\Support\Facades\Schema::hasTable('platform_theme_presets')`
guard **inside** the existing `Cache::rememberForever` closure, checked
before `$this->presets->findActive()` is ever called. If the table does
not exist, the closure returns `null` immediately (`Illuminate\Support\Facades\Schema`
import added) — the exact same fail-safe value already used for "no
active preset" (M2 §6.3's own documented contract: `null` means "render
nothing, let compiled `core.css` defaults stand"). This is a direct,
auditable, minimal addition to the *existing* method — not a new
mechanism, not a `try/catch`-swallowed exception, not a Blade-level
`@if` working around the dependency.

**Part B — do not let the pre-database fail-safe outlive the install.**
Because Part A's `null` result is cached via the *same* permanent
`Cache::rememberForever` key (`platform_theme:active_style_block`) that
also serves the legitimate steady-state cache, and because the initial
Factory-preset seeding
(`database/seeders/PlatformThemePresetSeeder.php`, already merged in
Slice 1) does **not** go through `PlatformThemeManager::activate()`'s
own `DB::afterCommit` cache-invalidation path (M2 §6.13) — a fresh
install would otherwise cache `null` during the pre-install welcome-page
render and **never see its own Factory theme** until something else
happens to clear the cache. `PlatformThemeManager` gains one new public
method, `invalidateCache(): void` (a thin, reusable wrapper around
`Cache::forget(self::CACHE_KEY)` — the same constant `currentStyleBlock()`
already uses, not a duplicated magic string). `InstallerController::database()`
calls `app(\App\Library\Theme\PlatformThemeManager::class)->invalidateCache();`
immediately after `(new FinalInstallManager())->runFinal();` (the exact
point migrations and seeding are already known to have completed
successfully in that method) — forcing the very next page render (the
`login` redirect target) to find the schema now genuinely present and
cache the real Factory-preset style block from then on.

**Why this boundary, not an alternative**: a `Schema::hasTable()` call
on *every* request forever (checked unconditionally outside the cache)
would add a metadata query to every single page load permanently — an
unacceptable, unbounded cost for a condition that is only ever true
during one narrow pre-install window. Checking it *inside* the existing
cache closure means it only ever executes when the cache is genuinely
cold (a fresh install, or immediately after Part B's explicit
invalidation) — zero steady-state cost, evidence-based, not assumed.

This resolution requires exactly two production files beyond the 26
Blade views, both named exactly, both justified above, both already
part of §9's numbered allowlist — no path is hidden in Blade, and no
Installer business logic (§6) changes.

---

## 8. Test contract (Slice 2)

Given §3.10's finding of zero existing coverage, Slice 2 must establish
its own focused suite from nothing, under a new `tests/Feature/Auth/`
and `tests/Feature/Installer/` directory pair (matching this
repository's own established per-feature-area test-directory
convention, e.g. `tests/Feature/Theme/`, `tests/Feature/Workspace/`).
No existing test file requires modification (§3.10) — none is added to
the allowlist for that reason, consistent with this task's own
instruction to add existing test paths only when their assertions must
legitimately change.

1. **`tests/Feature/Auth/AuthPageRenderTest.php`** — every one of the
   24 routed `auth/**` entry points (login, register, password-reset
   request/reset-form, email-verify notice, two-factor index/backup,
   terms-of-use, privacy-policy, profile index as an authenticated
   customer, sub-account accept-invitation) renders through its correct
   named route with the correct HTTP status (200 for guest-reachable
   forms, 302/redirect for auth-gated routes hit as a guest, matching
   current behavior exactly — this test proves no route silently
   started requiring/rejecting authentication it didn't before).
2. **`tests/Feature/Auth/AuthFormContractTest.php`** — for
   login/register/password-email/password-reset forms: exact `action`
   route, exact `method`, presence of `@csrf`, and the exact,
   unabbreviated set of `name="..."` attributes present before
   restyling remains present after — a byte-for-byte behavioral-
   contract proof, not a visual one.
3. **`tests/Feature/Auth/AuthValidationDisplayTest.php`** — submitting
   invalid login/register/password-reset data still redirects back with
   the expected validation-error session state and old-input
   repopulation; the rendered response still contains the translated
   error text (§6's own localization-preservation requirement, proven
   here, not merely asserted).
4. **`tests/Feature/Auth/AuthDesignSystemContentTest.php`** — mechanical
   content proof, run against all 26 rendered Slice 2 view files: zero
   remaining `data-feather="..."` attributes (§3.9's JS-embedded
   exception explicitly out of this file-based search's scope); zero
   hardcoded hex color literals; zero `font-family` declarations;
   `<x-ds-icon` present at least once per file that used to contain
   `data-feather` (proving genuine adoption, not merely deletion).
5. **`tests/Feature/Auth/AuthActiveThemeRenderingTest.php`** — with a
   non-Factory preset activated (reusing the existing
   `PlatformThemePresetSeeder`/activation path from Slice 1's own test
   suite, a read-only dependency here), the login page's rendered
   `<head>` contains that preset's `<style id="platform-theme-overrides">`
   block; with no active preset (or the Factory preset), the page still
   renders successfully with the compiled default palette standing in
   — proving §5 item 11's active-theme/fallback requirement end-to-end
   through a genuinely unauthenticated, pre-login page for the first
   time (Slice 1's own tests only ever proved this through
   authenticated admin routes).
6. **`tests/Feature/Installer/InstallerPreDatabaseRenderTest.php`** —
   the single most important new test in this slice, directly proving
   §7's fix. **Must not drop or alter any real table** (MySQL DDL like
   `DROP TABLE` implicitly commits and breaks `RefreshDatabase`'s
   transaction-rollback isolation for every subsequent test in the same
   process — an unacceptable, unnecessary risk this contract explicitly
   forbids as an implementation technique). Instead: partially mock the
   `Schema` facade
   (`Schema::shouldReceive('hasTable')->once()->with('platform_theme_presets')->andReturn(false)`,
   with every other `Schema` call passing through normally) and assert
   `PlatformThemeManager::currentStyleBlock()` returns `null` directly;
   separately, assert a full `GET /install` HTTP request (with no
   `storage/installed` marker present, exercising the real
   `canInstall` middleware) returns `200`, not `500`, proving the fix
   holds at the actual entry point a real fresh install uses.
7. **`tests/Feature/Installer/InstallerAlreadyInstalledRedirectTest.php`**
   — behavior-preservation regression test: with `storage/installed`
   present, `GET /install` redirects/aborts exactly per
   `config('installer.installedAlreadyAction')`'s current configured
   behavior, unchanged by this slice.

**Regression baseline**: the full existing suite (Slice 1's own
manually-verified baseline: 61 Theme tests / 213 assertions, and the
complete suite reported as passed with exit code 0, exact count not
retained per `AI-AUTONOMY-STATE.json`'s own `manual_verification`
record) must be re-run at Slice 2's own final head, with the 7 new
files above added and passing, and zero regression in any pre-existing
test — reported with the exact new complete-suite count this time,
never estimated.

---

## 9. Exact implementation allowlist (Slice 2)

**Closed, numbered, path-level, no wildcards, no duplicate path,
exactly 36 unique sequential entries. Any additional path required
during Slice 2 implementation is a required-37th-path-shaped stop
condition (§12).**

### Authentication views (10 modified)

1. `resources/views/auth/login.blade.php`
2. `resources/views/auth/register.blade.php`
3. `resources/views/auth/verify.blade.php`
4. `resources/views/auth/twoFactor.blade.php`
5. `resources/views/auth/twoFactorBackUp.blade.php`
6. `resources/views/auth/passwords/email.blade.php`
7. `resources/views/auth/passwords/reset.blade.php`
8. `resources/views/auth/subAccount/acceptInvitation.blade.php`
9. `resources/views/auth/termsOfUses.blade.php`
10. `resources/views/auth/privacyPolicy.blade.php`

### Impersonation banner (1 modified)

11. `resources/views/auth/loggedAs.blade.php`

### Profile views (13 modified)

12. `resources/views/auth/profile/index.blade.php`
13. `resources/views/auth/profile/_accounts.blade.php`
14. `resources/views/auth/profile/_security.blade.php`
15. `resources/views/auth/profile/_notifications.blade.php`
16. `resources/views/auth/profile/_information.blade.php`
17. `resources/views/auth/profile/_webhook.blade.php`
18. `resources/views/auth/profile/_two_factor_authentication.blade.php`
19. `resources/views/auth/profile/_dlt_entity_id.blade.php`
20. `resources/views/auth/profile/_dlt_telemarketer_id.blade.php`
21. `resources/views/auth/profile/_update_avatar.blade.php`
22. `resources/views/auth/profile/_announcements.blade.php`
23. `resources/views/auth/profile/_update_two_factor_auth.blade.php`
24. `resources/views/auth/profile/_view_announcement.blade.php`

### Installer views (2 modified)

25. `resources/views/Installer/welcome.blade.php` — modified: icon
    migration, component adoption, and deletion of the inline `<style>`
    block (§3.7) in favor of `<x-table>`.
26. `resources/views/Installer/update/welcome.blade.php` — modified:
    same discipline as item 25.

### Installer pre-database safety fix (2 modified)

27. `app/Library/Theme/PlatformThemeManager.php` — modified: adds the
    `Schema::hasTable()` guard inside `currentStyleBlock()`'s existing
    cache closure (§7 Part A) and the new `invalidateCache(): void`
    public method (§7 Part B). No other method in this class changes.
28. `app/Http/Controllers/InstallerController.php` — modified: adds one
    `PlatformThemeManager::invalidateCache()` call inside `database()`,
    immediately after `(new FinalInstallManager())->runFinal();` (§7
    Part B). No other method in this class changes.

### Shared per-page SCSS (1 modified)

29. `resources/scss/base/pages/authentication.scss` — modified: no
    color/font token change (§3.6 confirms none needed); structural
    adjustment only if `<x-card>`/`<x-input>`/`<x-button>` adoption
    requires narrow spacing/layout reconciliation with the existing
    `.auth-wrapper`/`.auth-cover`/`register-multi-steps-wizard` rules —
    if no such conflict is found at implementation time, this file is
    confirmed unmodified in the implementation report rather than
    touched speculatively, exactly matching Milestone 1's own §9 item
    10/11 precedent for conditional narrow files.

### New focused tests (7 new)

30. `tests/Feature/Auth/AuthPageRenderTest.php`
31. `tests/Feature/Auth/AuthFormContractTest.php`
32. `tests/Feature/Auth/AuthValidationDisplayTest.php`
33. `tests/Feature/Auth/AuthDesignSystemContentTest.php`
34. `tests/Feature/Auth/AuthActiveThemeRenderingTest.php`
35. `tests/Feature/Installer/InstallerPreDatabaseRenderTest.php`
36. `tests/Feature/Installer/InstallerAlreadyInstalledRedirectTest.php`

**Counts** — Production: **29** (26 modified views + 2 modified
integration/safety PHP files + 1 modified shared SCSS file). Test:
**7** (all new). New: **7**. Modified: **29**. **Overall total: 36.**
**Stop threshold: 37** (36 + 1).

`resources/views/Installer/update/overview.blade.php` (§3.2) is
deliberately **not** listed above — confirmed stale/unreachable,
explicitly excluded, not modified.

---

## 10. Mechanical searches (Slice 2)

1. `grep -rniE "anthropic|claude"` across every path in §9 → zero
   matches (identical legal/identity boundary enforced by every prior
   contract in this repository).
2. `grep -c "data-feather" ` across §9 items 1-26 → zero (§3.9's
   JS-embedded exception in `profile/index.blade.php` is a
   `feather.icons[...]` JS call, not a `data-feather="..."` attribute,
   and correctly does not match this pattern).
3. `grep -rn "data-feather" resources/views/auth/profile/index.blade.php`
   inspected manually (not purely mechanically) to confirm the one
   surviving match, if any, is exclusively inside the
   `@section('page-script')` JS block, never in the Blade markup body.
4. `grep -rnoE "#[0-9A-Fa-f]{3,8}"` across §9 items 1-26 → zero genuine
   color literals (CSS ID-selector false positives, if any reappear,
   must be individually confirmed non-color, not blanket-ignored).
5. `git diff --stat -- app database routes` compared against §9 items
   27-28 only → any other path in `app/`, `database/`, or `routes/` is
   a violation.
6. `grep -n "Schema::hasTable" app/Library/Theme/PlatformThemeManager.php`
   → present, guarding the `findActive()` call specifically, inside the
   `Cache::rememberForever` closure.
7. `grep -n "invalidateCache" app/Library/Theme/PlatformThemeManager.php app/Http/Controllers/InstallerController.php`
   → present in both files, the same `self::CACHE_KEY` constant reused,
   never a duplicated magic string.
8. `git diff --stat -- resources/views/auth/payment` → empty — confirms
   the excluded payment views were never touched.
9. Final changed-path set (`git diff --name-only` +
   `git ls-files --others --exclude-standard`) equals §9's exact,
   sequential 1-36 allowlist — mechanically diffed, not eyeballed.
10. `php artisan test` full-suite pass count compared against the
    pre-Slice-2 baseline, reported exactly, never estimated (§8).

---

## 11. Stop conditions

Slice 2 implementation must stop, leave the working tree unstaged, and
report rather than proceed, if:

- Any path beyond §9's 36-item allowlist is required — the **37th**
  path.
- Any change to `app/`, `database/`, or `routes/` beyond §9 items 27-28
  appears necessary.
- Any `auth/payment/**` file appears to require a change for Slice 2 to
  render or behave correctly (a signal this slice's own boundary with
  Slice 12 was drawn incorrectly, not a reason to silently cross it).
- Any form's `action`, `method`, CSRF token, input `name`, or validation
  rule changes as a side effect of restyling.
- `Schema::hasTable()`'s addition is found to run outside the existing
  `Cache::rememberForever` closure (reintroducing the unconditional
  per-request cost §7 explicitly rejects), or `invalidateCache()` is
  found to use a cache key string that does not match
  `PlatformThemeManager::CACHE_KEY` exactly.
- The `GET /install` pre-database HTTP proof (§8 item 6) still returns
  a 500 after the fix — the fix is incomplete, not merely unverified.
- Any existing test fails for a reason not fixable within Slice 2's own
  allowlist.
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is
  found necessary to reference.
- A genuine "official third-party brand logo" asset is found to be
  required (as opposed to the existing generic-glyph approach §3.5/§5
  item 6 already resolves) — that is a new asset-sourcing/licensing
  decision this contract does not authorize.

---

## 12. Contract self-audit

1. Every locked requirement (§5) is addressed by a numbered path in §9
   or an explicit, evidence-based "already satisfied" finding (§3.4,
   §3.6). ✓
2. Every behavior-preservation item (§6) is traceable to a specific
   read-only controller/route/middleware confirmed unmodified (§3.11)
   or to a specific §8 test proving it mechanically. ✓
3. The Slice 2 view count (27) is independently re-derived from the
   current tree (§3.1), not copied from the M2 contract's own §8
   table, even though it happens to match. ✓
4. The one stale Installer file is explicitly classified and excluded,
   not silently dropped or accidentally allowlisted (§3.2). ✓
5. The Installer pre-database crash is diagnosed to its exact root
   cause, with both halves of its fix (fail-safe *and* cache-
   invalidation-timing) resolved together — fixing only one would
   reintroduce a different, subtler failure (§7). ✓
6. No open decision is silently resolved — the social-icon question
   (§3.5) states its resolution and its exact deferred boundary rather
   than guessing or ignoring it. ✓
7. Test-suite impact is evidence-based: §3.10's direct search found
   exactly one, unaffected, existing test — not assumed. ✓
8. **Allowlist total is exactly 36, numbered 1-36, sequential, no
   duplicate path** — verified in §14 before commit. ✓
9. The stop threshold is explicitly the 37th path, stated consistently
   in §0, §9, and §11. ✓
10. This contract authorizes only Slice 2 — every other rollout-map
    slice is explicitly named as out of scope (§0), not merely
    unmentioned. ✓
11. No business logic, permission model, route behavior, or
    authentication rule changes anywhere in §9 beyond the two narrowly-
    scoped, individually-justified theme-injection-safety files (§7,
    §9 items 27-28). ✓
12. This document remains the only file changed on this branch (§2). ✓

---

## 13. Verification and publication

Performed, in order, before commit:

1. Markdown structural check — every numbered §9 item follows the
   pattern `N. `path``, no broken heading levels, no unclosed code
   fences.
2. §9's numbered items counted mechanically and confirmed equal to
   exactly 36, sequential, no gap, no repeated number.
3. Every path listed in §9 checked for uniqueness — no path string
   appears twice.
4. `git diff --check` — clean, no whitespace-error or conflict-marker
   findings.
5. `git diff --name-only` — exactly one path:
   `docs/automation/DESIGN-SYSTEM-M2-SLICE-2-CONTRACT.md`.
6. `git status --short` — exactly one untracked/modified entry, the
   same path.
7. `git diff --cached --name-only` — empty before staging.
8. Stage individually
   (`git add docs/automation/DESIGN-SYSTEM-M2-SLICE-2-CONTRACT.md`),
   never `git add -A`/`.`.
9. Commit message: `docs: define Design System M2 Slice 2 rollout`.
10. Push to `origin chore/design-system-m2-slice2-contract` — a normal
    push, never force-pushed.
11. Open a draft PR into `main` if `gh` is available; otherwise report
    the exact GitHub comparison URL.
12. **Do not merge. Do not begin Slice 2 implementation.** Both require
    separate, explicit, future human authorization. No test is run for
    this docs-only change — reported honestly as not run, no count
    fabricated.

---

*End of Design System M2 Slice 2 Contract. Implementation requires a
separate, explicit human instruction. This contract's own merge does
not start or resume it.*
