# Design System — Milestone 2 Contract

Full application rollout, platform-owner theme editor, chart theming, and
chat background remediation.

**Status: contract only. No implementation has occurred under this
document. Merging this contract does NOT authorize any implementation —
each rollout slice (§9) requires its own separate, explicit
authorization, exactly like every prior contract in this repository.**

---

## 0. Governance

- This document is drafted on an isolated branch (`chore/design-system-m2-contract`)
  in an isolated worktree, based on `main` at the commit that already
  contains Milestone 1 (`fb5c823`, PR #84).
- Drafting this contract makes **zero** application changes. No `resources/`,
  `app/`, `database/`, or `routes/` file is touched by this branch — only
  this document.
- Once merged, implementation of **Slice 1 only** (§9) may begin under a
  separate, explicit authorization. Slices 2 onward (§8) are a locked
  rollout *map*, not yet allowlisted — each future slice gets its own
  correction-round-bounded implementation contract or a scoped extension
  of this one, following the same discipline used for every RFC-004/
  RFC-005/Design-System-M1 slice in this repository's history.
- `maximum_correction_rounds: 2` applies to this contract, identical to
  every prior contract.
- Any path required during Slice 1 implementation but absent from §9's own
  numbered allowlist is a stop-and-report condition — not a silent
  workaround.

---

## 1. Mandatory preflight — verified

- Read `CLAUDE.md` (Senior Laravel Engineer workflow, reuse-first,
  services/jobs discipline, documentation-on-behavior-change).
- Read `docs/automation/DESIGN-SYSTEM-CONTRACT.md` (Milestone 1) and its
  merged implementation on `main` — the 12 locked color tokens, the
  typography/spacing/radii/shadow/motion token system, the 13-component
  Blade library, the icon seam (`<x-ds-icon>`), and the exact retokenization
  mechanism (`bootstrap-extended/_variables.scss` importing
  `base/tokens` at its own top, which every compiled SCSS entry reaches).
- Confirmed Milestone 1 is merged to `main` (commit `fb5c823`) — this
  contract is drafted against that state, not against the pre-M1 baseline.
- Performed the three-track mandatory repository audit required by this
  contract's own governance before writing a single requirement (§3).

---

## 2. This contract's own exact file scope

Only one path may change on `chore/design-system-m2-contract`:

1. `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` (this file, new)

---

## 3. Mandatory repository audit — findings

Conducted as three parallel, read-only research passes against `main` at
`fb5c823`. Full findings are reproduced in condensed form below; anything
cited with a `file:line` reference was independently confirmed.

### 3.1 Remaining rollout surface

- **374** total Blade view files under `resources/views/`.
- **2** migrated to the Milestone-1 component library:
  `customer/business/usage-billing/show.blade.php`,
  `admin/usage-billing/provider-events/index.blade.php`.
- **372** remaining, unmigrated.
- **223 files / 796 occurrences** of `data-feather="..."` remain
  repository-wide (the two migrated pages are clean).
- **55 files** are 500+ lines; two are extreme outliers —
  `admin/SendingServer/create.blade.php` (**4,306 lines**, the single
  largest view in the app) and `customer/SendingServer/create.blade.php`
  (**2,316 lines**) — both bundles of dozens of gateway-specific
  conditional form blocks. Each is called out as its own dedicated
  future slice (§8), never folded into a general "Sending Servers"
  estimate.
- Complexity signals across the unmigrated surface: 53 files use
  DataTables, 26 use Bootstrap modals, 19 use tabs, 8 build charts, 33
  handle file upload.
- `resources/views/emails/**` (10 files) and `resources/views/vendor/mail/**`
  (16 files) render through Laravel's markdown-mail component system,
  entirely outside the browser CSS/SCSS pipeline this design system
  governs. **Explicitly out of scope for this entire rollout** — flagged
  for a possible future, separately-authorized initiative if ever wanted.
- The complete module-by-module breakdown (file globs, counts, complexity
  notes) is the basis for the rollout map in §8.

### 3.2 Chart color audit

- Four PHP call sites construct `ArielMejiaDev\LarapexCharts\LarapexChart`
  directly (`app/Http/Controllers/Admin/AdminBaseController.php`,
  `app/Http/Controllers/User/UserController.php`,
  `app/Http/Controllers/Customer/ReportsController.php`,
  `app/Http/Controllers/Admin/ReportsController.php`). **None** call
  `->setColors([...])` — every chart's actual series colors are applied
  entirely client-side.
- Series colors are hardcoded, ad hoc, and duplicated across at least
  eight Blade `<script>` blocks: `admin/dashboard.blade.php`,
  `customer/dashboard.blade.php`, `customer/Reports/charts.blade.php`,
  `customer/Reports/analyze.blade.php`, `admin/Reports/overview.blade.php`,
  `admin/Reports/dashboard.blade.php`,
  `customer/Campaigns/overview.blade.php`,
  `customer/Automations/overview.blade.php`. The legacy Vuexy purple
  `#7367F0` appears repeatedly as a literal (e.g.
  `admin/dashboard.blade.php:488,710,799`;
  `customer/Reports/charts.blade.php:218,647`;
  `customer/Automations/overview.blade.php:120`).
- `resources/js/core/app.js:5-16` defines `window.colors` — a **static,
  hand-written JS literal** (not SCSS-derived, not build-generated) whose
  `solid.primary` is also the legacy `#7367F0`. A few charts read
  `window.colors.solid.primary` instead of re-hardcoding the hex, but
  most still hardcode directly.
- **No centralized chart-color config exists anywhere** — no
  `config/chart*.php`, no shared JS module, no shared Blade partial.
- Charts are entirely disconnected from the Milestone-1 token system —
  `resources/scss/base/plugins/charts/chart-apex.scss` and
  `resources/scss/base/components/chart.scss` style only chrome
  (tooltip/legend/gridlines) via Bootstrap variables, never series colors,
  and never reference `--color-*`. This is architecturally expected:
  compile-time SCSS variables cannot be read by request-time PHP arrays
  or already-shipped JS — a genuinely new runtime bridge is required
  (§6.4).
- The "delivered/enroute/expired/undelivered/rejected/accepted/skipped/
  failed" status-color object (`customer/Campaigns/overview.blade.php:94-118`,
  duplicated at `admin/Reports/overview.blade.php:97-118`) is **semantic**
  (status meaning, not brand identity) and is confirmed out of scope for
  retokenization, per the human instruction's own carve-out.

### 3.3 Chat background audit

- The chat feature lives at `customer/ChatBox/{index,new,_sidebar}.blade.php`
  and `customer/ChatBox/partials/_chat_list.blade.php`, routed through
  `App\Http\Controllers\Customer\ChatBoxController`
  (`routes/customer.php:400-411`).
- The repeating icon-pattern background is **not** a separate image file.
  It is an inline base64-encoded SVG data URI hardcoded as two SCSS string
  variables: `$chat-bg-light` (`resources/scss/base/bootstrap-extended/_variables.scss:664`)
  and `$chat-bg-dark` (same file, line 665), applied by
  `resources/scss/base/pages/app-chat.scss:206-217`
  (`.chat-app-window .start-chat-area, .user-chats { background-image:
  url($chat-bg-light); background-color: $chat-image-back-color;
  background-repeat: repeat; background-size: 210px; }`) and referenced a
  second time for dark mode at
  `resources/scss/base/themes/dark-layout.scss:1725`.
  Decoding the payload confirms it is exactly the classic tiled grey-icon
  Vuexy "doodle" pattern the human instruction describes.
- Five on-disk files at `resources/images/backgrounds/chat-bg*.{svg,png,jpg}`
  (mirrored in `public/images/backgrounds/`) are **dead assets** — not
  referenced by any SCSS/Blade/PHP file in the repository. They are not
  part of this milestone's scope (nothing to remove them *for*; they are
  simply unused and may be left alone or removed as harmless cleanup at
  implementation time, not a functional requirement).
- Outbound message bubbles (`resources/scss/base/pages/app-chat-list.scss:51-73`)
  use a **purple gradient** (`gradient-directional(map-get($primary-color,'base'),
  map-get($primary-color,'lighten-2'), 80deg)`) with white text — the same
  legacy purple family the human instruction elsewhere requires removed.
  Inbound bubbles (`app-chat-list.scss:75-88`) use `lighten($white, 18%)`
  with `$body-color` text.
- There is **no separate Blade partial for individual message bubbles** —
  bubble HTML is built via inline JS template-literal strings inside
  `customer/ChatBox/index.blade.php`, in three near-duplicate places:
  history load (`:344-377`), optimistic send (`:459-476`), and the
  Echo/Pusher live-message listener (`:778-827`). A "preserve every
  existing text string/attribute the test suite depends on" allowlist
  item for this file must therefore cover JS template literals, not only
  Blade `@if`/`@foreach` markup.
- Must-preserve elements (all in `index.blade.php`): the composer
  textarea's custom fixed-height restyle (`:60-79,148`), the file-input
  attachment control and its image/video/audio branching (`:150-155,346-356,
  403-418,471-473,779-791`), the SMS-template picker (`:161-166,217-274`),
  the send button (`:170-173`), the `.chat-time` timestamp paragraph on
  every bubble (`:371,808,823`, reused in the sidebar list), the unread
  notification badge/counter pair (`:_sidebar.blade.php:74-80`), the pin/
  block/delete controls and their SweetAlert2 confirms (`:118-131,518-743`),
  the empty/start-chat state (`:95-106`), and — critically — the
  **conditional guard around the entire Echo/Pusher wiring**
  (`config('broadcasting.connections.pusher.app_id')`, `:192-194,745-839`)
  which must not be assumed always-configured.

### 3.4 Platform configuration storage audit

- `App\Models\AppConfig` (table `app_config`, singular) has the schema
  `id, setting (text), value (text, nullable), timestamps` only —
  unchanged since 2018 (`database/migrations/2018_07_26_134739_create_app_config_table.php:15-20`).
  It is a flat row-per-global-setting key/value store with **no** actor,
  no `is_active`, no structured-value support beyond one existing
  precedent (`setting = 'tax'`, a hand-`json_encode`d blob with no audit
  trail — `AppConfig.php:438-498`).
- It is read/written from at least 24 files, via two **inconsistent**
  parallel mechanisms — the DB table itself, and direct `.env` rewrites
  through `AppConfig::setEnv()` (`AppConfig.php:397-409`). The *existing*
  `Admin\ThemeCustomizerController` (navbar color / layout / skin) already
  persists **entirely to `.env`**, bypassing the DB table altogether —
  confirming there is no reliable live precedent for storing theme-like
  data in `app_config`.
- The codebase has an **established, idiomatic, in-house pattern** for
  exactly the "who changed this and when" need this milestone requires —
  append-only tables such as `workspace_entitlement_transitions`
  (`database/migrations/2026_08_13_120006_...php:26-44`) and
  `business_feature_toggles`
  (`database/migrations/2026_08_13_120005_...php:17-26`): a plain scalar
  `*_user_id` column with **no foreign-key constraint** (by deliberate
  design, documented in the migration's own comment, so it can never
  block an unrelated user-deletion feature), plus `created_at` (and
  sometimes `reason`).
- **Decision (§6.1): a new, dedicated table is used — not `AppConfig`.**
  Reasoning: `app_config`'s schema cannot carry structured, audited data
  without adding columns meaningless to its other ~65 unrelated rows; its
  one JSON-blob precedent has no audit trail; and this codebase already
  has a proven, idiomatic append-only pattern for exactly this need. Using
  it keeps this feature consistent with how the rest of the app already
  solves the same problem, rather than inventing a new one or overloading
  an unsuited existing one.

### 3.5 Platform-owner authorization audit

- No `is_platform_owner` flag, `super-admin` role name, or dedicated
  permission string exists anywhere distinguishing "the platform owner"
  from "an admin staff member with broad permissions." The only sharper-
  than-`is_admin` primitive in the whole codebase is
  `EloquentAccountRepository::hasPermission():207-210`'s unconditional
  `users.id === 1` bypass — an ID-based special case, not a named concept.
- The established, reusable pattern for "one settings page, one
  permission string" already exists: `config/permissions.php:374-377`
  defines `'general settings'`, checked by
  `Admin\ThemeCustomizerController::index()` (`$this->authorize('general
  settings')`) and its sibling `ThemeCustomizerRequest::authorize()`
  (`return $this->user()->can('general settings');`).
- **Decision (§6.2):** gate the new theme editor behind (a) the blanket
  `routes/admin.php` group's existing `auth` + `can:access backend` +
  `twofactor` middleware stack (inherited automatically, same as every
  other admin route), (b) `EnsureUserIsAdministrator::class` (defense in
  depth, `is_admin` check, same pattern already used for the Business/
  Opportunity/Workspace admin sub-groups), and (c) a **new**, dedicated
  permission string `'manage theme'` added to `config/permissions.php`
  under a new "Appearance" category, checked in both the controller and
  its FormRequest exactly like `'general settings'`. This correctly
  satisfies "accessible only to the platform administrator — not ordinary
  Workspace owners, members or customers" (all of whom are structurally
  incapable of reaching any `routes/admin.php` route at all, regardless
  of this new permission) while staying consistent with the codebase's
  own existing settings-permission convention rather than inventing an
  unprecedented "single true owner" concept the codebase has no other
  example of. `'manage theme'` is intentionally **not** granted by
  `'general settings'` — it is its own assignable permission, so a
  reseller/operator can withhold it from ordinary admin staff even while
  granting other settings access, which is the practical meaning of
  "the owner of the software" here: whoever the operator's own role
  configuration grants it to, not a hardcoded ID.

### 3.6 Runtime CSS injection point audit

- `resources/views/panels/styles.blade.php:12` is the single file that
  emits `<link rel="stylesheet" href="{{ asset(mix('css/core.css')) }}"/>`
  — the compiled bundle carrying Milestone 1's build-time
  `:root { --color-*: ...; }` block. Nothing loaded after it in that same
  file (`dark-layout.css`, `bordered-layout.css`, `semi-dark-layout.css`,
  `overrides.css`, `style.css`, `custom-rtl.css`, `style-rtl.css`,
  lines 13-42) redefines any `--color-*` custom property — confirmed by
  grep; they only *consume* `var(--color-...)`.
- `panels/styles.blade.php` is `@include`d, inside `<head>`, by exactly
  three root layout files:
  `resources/views/layouts/contentLayoutMaster.blade.php:28`,
  `detachedLayoutMaster.blade.php:27`, `fullLayoutMaster.blade.php:26`.
- **Decision (§6.3):** append a new, server-rendered `<style>` block to
  the end of `resources/views/panels/styles.blade.php` (after its current
  last line), populated from the currently-active `platform_theme_settings`
  row. This is the single minimal insertion point: it is textually after
  `core.css` (so it wins on cascade order at equal specificity), it is
  included by all three `<head>`-owning layouts without editing each one,
  and — because it renders server-side before `</head>`, before `<body>`
  — there is no flash of default theme and no separate async request.

---

## 4. Locked requirements (verbatim scope, from the human instruction)

Reproduced in full below for traceability; §6 translates each into an
implementation-ready technical decision, and §9 allowlists exactly the
files needed to build it.

### 4.1 Platform-owner theme editor — colors

> Create a global Appearance/Brand Theme settings page accessible only to
> the platform administrator... Expose a deliberately limited set of
> editable colors: Primary brand color; Secondary/accent color; Primary
> chart color; Secondary chart color; Optional chart-neutral color.
> Default values must preserve the approved design: Primary `#B5524C`;
> Primary dark/active `#980E0E`; Primary hover `#A83D38`; Soft primary
> background `#F4E6E4`; Primary border `#E3C3BF`; Application background
> `#F7F6F2`; Sidebar background `#F2F0EB`; Surface `#FFFFFF`; Secondary
> surface `#FBFAF7`; Neutral border `#E5E1DA`; Text `#262522`; Muted text
> `#6F6D67`. Hover, active, soft-background, border and readable
> foreground variants should be derived automatically and deterministically.

Requirements: runtime CSS custom properties (no recompile); color pickers
+ validated hex inputs; live preview before saving; Cancel / Save theme /
Restore approved defaults; consistent application to navigation, buttons,
links, selected states, focus rings, badges, charts, brand accents;
preserve fixed warm-neutral canvas/surfaces/typography/spacing *unless a
later separately authorized contract expands those controls* — superseded
for backgrounds/surfaces by §4.4 below, delivered in the same instruction;
reject invalid colors; enforce accessible contrast, refuse unsafe derived
combinations; never permit custom CSS/JS/arbitrary HTML; record actor +
timestamp of every change; fail safely to approved defaults if stored
config is missing/invalid; no flash of default theme; authorization/
validation/persistence/audit/isolation/rendering tests; repository audit
to decide the storage mechanism (§3.4); no Agency/Workspace-level
overrides this milestone (recorded as a future white-label extension
point, §7).

### 4.2 Chart theming

> Remove hardcoded purple and unrelated legacy chart colors where they
> represent brand series. All chart constructors must consume centralized
> runtime chart tokens. Semantic colors... may remain distinct where their
> meaning requires it. Charts must remain readable with multiple series,
> tooltips, legends, hover states and empty states.

### 4.3 Chat background

> Remove the legacy chat/conversation background image containing
> decorative icons. Use the same warm application background/surface
> system as the rest of the software: no repeating icon pattern; no
> decorative image; no visual mismatch; preserve inbound/outbound
> distinction, readability, timestamps, delivery states, attachments,
> composer controls, responsive behavior.

### 4.4 Editable background and surface colors (addendum — supersedes the
### "preserve fixed... surfaces" line in §4.1 for these seven roles only)

> Expand the Theme Editor to expose: Application canvas/background;
> Sidebar background; Header/navigation background; Primary card/surface
> background; Secondary/subtle surface background; Input and control
> background; Overlay, dropdown and modal background.
> Approved defaults: canvas `#F7F6F2`; sidebar `#F2F0EB`; header/nav
> *derived from the primary brand color*; primary surface `#FFFFFF`;
> secondary surface `#FBFAF7`; inputs `#FFFFFF`; overlays `#FFFFFF`;
> neutral border `#E5E1DA`.

Requirements: a dedicated "Surfaces" section; live preview showing
sidebar/canvas/cards/inputs/modal/representative text; runtime CSS custom
properties; the chat background must consume the saved canvas/surface
token, never a separate image; remove the existing chat icon-pattern
background entirely; auto-calculate readable foreground text/border;
validate minimum contrast between canvas/surfaces/controls/borders/text;
reject indistinguishable combinations; **warn** (non-blocking) when
technically-valid surfaces provide very little visual separation;
independent "Restore approved defaults" for the whole theme and for
Surfaces alone; no arbitrary background images/gradients/CSS/JS; every
customer/admin/auth/error/chat/modal surface must consume these tokens;
tests proving no legacy hardcoded white/off-white/yellowish/patterned
chat background can bypass the configured theme roles.

### 4.5 Full rollout scope

> Cover every remaining customer and admin interface... No business
> logic, permissions, routes, tenant isolation or data-flow behavior may
> change. If too large for one allowlist, divide into mechanically
> complete module-based slices... define the entire rollout map and exact
> first implementation slice... audit first, create a path-level
> allowlist, and stop before implementation.

---

## 5. Reading note on an apparent tension in the instruction

§4.1 says surfaces/typography/spacing stay fixed "unless a later
separately authorized contract expands those controls," and §4.4 — sent
in the same message — is precisely that expansion for seven background/
surface roles (not typography, not spacing, which remain fixed and
outside this contract's scope entirely). This contract treats §4.4 as
authoritative and additive to §4.1, not contradictory: the Theme Editor
ships in this milestone with **two** sections, Colors (§4.1's five
fields) and Surfaces (§4.4's seven roles), both governed by the same
derivation/contrast/audit/runtime machinery.

---

## 6. Architecture decisions

### 6.1 Data model — dedicated table, append-only

New table `platform_theme_settings` (audit basis: §3.4):

```
id                   bigint, PK
colors_json          json          -- {primary, secondary, chart_primary, chart_secondary, chart_neutral}
surfaces_json        json          -- {canvas, sidebar, header, surface, surface_secondary, input, overlay, border}
derived_json         json          -- fully computed cache: hover/active/soft_bg/border/foreground pairs for every role above, recomputed on every save, never hand-edited
is_active            boolean       default false
change_scope         string        -- 'full' | 'colors' | 'surfaces' | 'restore_full' | 'restore_surfaces' (what this row represents, for the audit trail)
created_by_user_id   unsignedBigInteger, nullable, NO foreign key (matches workspace_entitlement_transitions' own deliberate no-FK convention, §3.4)
created_at           timestamp only (append-only / immutable — no updated_at, matching workspace_entitlement_transitions)
```

Every save (including "restore defaults") **inserts a new row** and, in
the same transaction, flips `is_active` off on the previous row and on
for the new one. This gives the required actor+timestamp audit trail for
free, as an ordinary consequence of the storage shape, rather than a
bolt-on log — exactly mirroring the existing `workspace_entitlement_transitions`
precedent. "The current theme" is always `WHERE is_active = true LIMIT 1`.
No row is ever updated or deleted (no admin-facing destructive action
exists for this table).

### 6.2 Authorization

- New permission string `'manage theme'` in `config/permissions.php`
  (new `"Appearance"` category), assignable per-role exactly like
  `'general settings'`.
- Route group in `routes/admin.php`: wrapped in **both**
  `EnsureUserIsAdministrator::class` and a `can:manage theme` gate check
  (belt-and-braces, matching the two-layer pattern §3.5 found already in
  use for Business/Opportunity/Workspace admin routes).
- Controller and FormRequest both independently check
  `$this->user()->can('manage theme')`, mirroring
  `ThemeCustomizerController`/`ThemeCustomizerRequest`'s own shape.

### 6.3 Runtime CSS injection (no recompilation, no FOUC)

- `PlatformThemeManager::currentStyleBlock(): ?string` returns a
  pre-rendered `<style id="platform-theme-overrides">:root{--color-...:
  ...;}</style>` string, or `null` if no active row exists or its JSON
  fails validation at read time (fail-safe: `null` means "render nothing,
  let Milestone 1's compiled `core.css` defaults stand unchanged" —
  those defaults already equal the approved palette, so "missing/invalid
  config" and "approved defaults" produce visually identical output).
- Appended (via a tiny `@if` guard, not unconditionally) to the end of
  `resources/views/panels/styles.blade.php`, per §3.6's decision.
- Because this executes server-side, inside `<head>`, before `<body>`
  ever paints, there is no flash of default theme — this is a property of
  the chosen insertion point, not a separate mechanism to build.
- The block sets **only** the `--color-*` custom properties governed by
  this contract (§6.5's full property list) — never arbitrary CSS
  selectors, never `<script>`, never raw HTML, by construction (it is
  built from a fixed Blade template with interpolated hex values only,
  never from a stored "raw CSS" field, because no such field exists in
  the schema).

### 6.4 Chart token bridge (new — no equivalent exists today)

Chart series colors are evaluated in the browser (PHP builds chart *data*,
JS/Blade `<script>` blocks currently hardcode chart *colors* — §3.2).
Since SCSS variables are compile-time-only and cannot reach either PHP or
already-shipped JS, a small **runtime-readable bridge** is introduced:

- `_colors.scss` gains three new build-time-default custom properties,
  read at runtime exactly like every other `--color-*` token:
  `--color-chart-primary`, `--color-chart-secondary`,
  `--color-chart-neutral` (nullable/optional per §4.1 — when unset, chart
  code falls back to `--color-chart-primary`/`secondary` alone).
- New `resources/js/core/chart-tokens.js` exports a single
  `getChartColors()` helper that reads these three custom properties via
  `getComputedStyle(document.documentElement)` at call time (so it always
  reflects whatever the theme-override `<style>` block last set — build-
  time default or platform-owner override, transparently).
- The eight chart-bearing Blade views (§3.2) are edited to call
  `getChartColors()` instead of hardcoding hex arrays. Semantic
  status-color objects (delivered/failed/success/warning/etc.) are left
  exactly as they are — confirmed out of scope by §4.2's own carve-out.
- `resources/js/core/app.js`'s `window.colors.solid.primary` (a static
  literal, confirmed by audit to be unrelated to the SCSS build) is
  updated to source its value from the same runtime custom property
  rather than a second hardcoded `#7367F0` copy, so the two known
  "primary color" sources in the JS layer stop disagreeing.

### 6.5 Full editable-property list (Colors + Surfaces, §4.1 + §4.4)

| Field | Approved default | Section | Auto-derived from it |
|---|---|---|---|
| Primary | `#B5524C` | Colors | hover, dark/active, soft-bg, border, chart-primary (if unset) |
| Secondary/accent | *(§7.1 open decision)* | Colors | hover/soft variant, chart-secondary (if unset) |
| Chart primary | *(defaults to Primary, §7.1)* | Colors | — |
| Chart secondary | *(§7.1 open decision)* | Colors | — |
| Chart neutral (optional) | *(§7.1 open decision, nullable)* | Colors | — |
| Application canvas | `#F7F6F2` | Surfaces | — |
| Sidebar background | `#F2F0EB` | Surfaces | — |
| Header/nav background | *derived from Primary* | Surfaces | computed, not directly editable as a separate hex unless the owner overrides it (§7.2) |
| Primary surface (cards) | `#FFFFFF` | Surfaces | — |
| Secondary surface | `#FBFAF7` | Surfaces | — |
| Input/control background | `#FFFFFF` | Surfaces | — |
| Overlay/dropdown/modal background | `#FFFFFF` | Surfaces | — |
| Neutral border | `#E5E1DA` | Surfaces | — |

Text (`#262522`) and muted text (`#6F6D67`) are **not** editable fields —
they remain the fixed Milestone-1 compile-time tokens. "Automatically
calculate readable foreground text" (§4.1/§4.4) means: for any
owner-chosen background role, the *foreground text/icon color rendered on
top of it* (e.g. button label text on a Primary-colored button) is picked
at derivation time between the fixed dark text token and white, by
contrast ratio — never a new editable base text color.

### 6.6 Deterministic derivation algorithm

All derivation is computed server-side (PHP, `ThemeColorDerivationService`)
in HSL space, and cached into `derived_json` (§6.1) on every save — never
recomputed client-side for anything but the optimistic live preview:

1. **Hover** = input color with lightness reduced by a fixed delta and
   saturation nudged up slightly (tuned constants, verified — see below).
2. **Dark/active** = input color with lightness reduced further and
   saturation increased more than hover (tuned constants, verified).
3. **Soft background** = input color blended toward the *chosen* canvas/
   surface at a fixed low mix ratio (tint, not a flat-opacity overlay —
   avoids translucency artifacts when composited over non-white surfaces
   now that surfaces are also editable, §4.4).
4. **Border** = input color blended toward canvas at a mix ratio between
   soft-background's and the full color's (a saturated but light tint).
5. **Foreground-on-color** = `argmax(contrastRatio(candidate, background))`
   over the two candidates `{fixed dark text `#262522`, white `#FFFFFF`}`
   — the standard, well-known safe-foreground-picking rule.
6. **Header/nav background** (§6.5) = derived from Primary using the same
   "soft background" formula as item 3, unless the owner explicitly
   overrides it with its own hex in the Surfaces section (§7.2).

**Implementation-time verification requirement (not optional):** the
exact numeric HSL deltas used in steps 1-4 must be tuned so that applying
them to the *default* Primary (`#B5524C`) reproduces the approved
Hover (`#A83D38`), Dark/active (`#980E0E`), and Soft background
(`#F4E6E4`) values within a small, stated tolerance (ΔE₀₀ < 2, a standard
"imperceptible difference" perceptual-color threshold). This reproduction
is asserted by a dedicated unit test (§10) *before* the same formula is
trusted for arbitrary owner-chosen colors — the approved palette is the
formula's own acceptance test, not a coincidence to hope for.

### 6.7 Contrast validation — two tiers

`ThemeContrastValidator` computes WCAG 2.1 relative luminance and
contrast ratio (standard, well-documented formula — no new dependency
needed, hand-implemented as a small pure function, PHP-side authoritative).

- **Hard reject** (blocks save, returned as a validation error): any
  foreground/background pairing the theme actually renders text on top of
  — e.g. derived foreground vs. Primary/soft-bg/surfaces — falls below
  **4.5:1** (WCAG AA, normal text) or, for large-text/UI-component-only
  pairings (borders, focus rings), below **3:1**.
- **Soft warning** (non-blocking, shown to the owner, save still allowed):
  adjacent surface pairs that are valid but visually close — e.g. canvas
  vs. primary surface, or surface vs. its own border — below a
  configurable low-separation threshold distinct from and above the hard
  minimum (contract sets this at **1.15:1** absolute luminance-ratio
  floor for canvas/surface/input/overlay pairs specifically, tunable at
  implementation time but never below AA on anything text bears).
- The validator returns a structured `{errors: [...], warnings: [...]}`
  result; the controller blocks the save only on `errors`.
- Client-side, the live preview applies colors **optimistically** (pure
  CSS custom-property swap, no validation gate — instant, matches "show a
  live preview before saving") while a debounced AJAX call to the same
  PHP validator surfaces errors/warnings inline before the owner clicks
  Save. The PHP validator is the single source of truth; nothing about
  contrast math is duplicated in JS.

### 6.8 "Never permit custom CSS/JS/arbitrary HTML"

Enforced structurally, not by a blocklist: the FormRequest accepts
**only** a fixed, named set of hex-string fields (`^#[0-9A-Fa-f]{6}$`,
strict regex, `required_without` rules for the optional chart-neutral
field), one boolean-ish `change_scope` action field, and nothing else.
There is no free-text, no CSS, no HTML field anywhere in the schema or
the request — the theme cannot express anything the fixed template in
§6.3 doesn't already parameterize.

---

## 7. Open technical decisions (category-3 — resolved at implementation
## time within the constraints stated here, never silently guessed)

1. **Secondary/accent, chart-primary, chart-secondary, chart-neutral
   defaults.** The human-supplied approved palette (§4.1) defines hex
   values for Primary and every one of its own derived/neutral roles, but
   states no default hex for "Secondary/accent," "Primary chart color,"
   "Secondary chart color," or "chart-neutral" — because the approved
   M1 palette is a single-hue (brick-red) family with no second brand hue
   at all. Proposed resolution, to be confirmed (not silently assumed)
   before Slice 1 implementation begins: **Chart primary defaults to
   Primary itself** (`#B5524C`, zero new color introduced); **Secondary/
   accent and Chart secondary default to the same new, single proposed
   hex** — a muted warm gold/amber (exact value to be proposed in the
   implementation-authorization step, chosen only for (a) sufficient hue
   distance from the brick-red primary to remain distinguishable as a
   second chart series and (b) passing §6.7's contrast rules against the
   default canvas/surfaces) — never invented and shipped silently;
   **Chart neutral defaults to unset** (nullable, §4.1's own "Optional"
   wording), falling back to the fixed muted-text token
   (`#6F6D67`) wherever a neutral chart series color is needed.
2. **Header/nav background editability.** §4.4 lists it as a controlled
   Surfaces role with a *derived* default, but doesn't say whether the
   owner may override it with an independent hex or whether it is
   display-only (always mirrors Primary). Proposed resolution: expose it
   as an **optional** override field (nullable — unset means "keep
   deriving from Primary," set means "use this exact hex instead"),
   consistent with how chart-neutral is already optional.
3. **Live-preview scope.** "Show a live preview before saving" — resolved
   as: the same admin page re-skins its own chrome (a representative
   sidebar/nav/card/button/input/modal cluster rendered inline on the
   settings page itself) rather than opening a second preview surface or
   requiring the owner to navigate away and back. Exact preview markup
   composition is an implementation-time UI detail within this constraint.

---

## 8. Full rollout map (all slices — this contract allowlists Slice 1
## only; Slices 2+ are locked scope definitions, not yet implementation-
## ready, and each needs its own future authorization)

No module below is omitted. File counts are from §3.1's audit.

| # | Slice | Scope (globs) | Files | Notes |
|---|---|---|---|---|
| **1** | **Foundation** (§9, this contract's own allowlist) | Theme editor (new), chart tokens (new), chat background fix, remaining shared-chrome icon migration (8 of 9 `panels/` files — `scripts.blade.php` stays untouched, same as Milestone 1), errors | 46 | **Implementation-ready this contract.** No `layouts/*.blade.php` file needs editing — the runtime injection point (§6.3) is `panels/styles.blade.php`, already `@include`d by all three layout shells. |
| 2 | Authentication & Profile | `auth/**` (excl. `auth/payment/**`), `Installer/**` | 27 | Shared, pre-dashboard first impression |
| 3 | Dashboards | `customer/dashboard.blade.php`, `admin/dashboard.blade.php`, `admin/hot_leads.blade.php`, `admin/ai_analytics.blade.php`, `admin/ai-settings.blade.php` | 5 | |
| 4 | Reports & Analytics | `customer/Reports/**`, `admin/Reports/**` | 12 | Consumes Slice-1 chart tokens |
| 5 | Contacts & CRM | `customer/Contacts/**`, `customer/contactGroups/**`, `customer/Blacklists/**`, `customer/opportunities/**`, `admin/opportunities/**`, `admin/Blacklists/**` | 30 | `contactGroups/show.blade.php` (1,573 lines) flagged for decomposition, not a straight port |
| 6 | ChatBox / Conversations (full componentization) | `customer/ChatBox/**` | 4 | Beyond Slice 1's token-level background fix |
| 7a | Campaigns — quick-send variants | `customer/Campaigns/*QuickSend.blade.php` | 6 | |
| 7b | Campaigns — builders | `customer/Campaigns/*CampaignBuilder.blade.php` | 7 | Five are near-duplicate per-channel copies; pattern transfers once one converts |
| 7c | Campaigns — overview/list/modals | remaining `customer/Campaigns/**` | 13 | |
| 8 | Automations | `customer/Automations/**` | 6 | |
| 9 | Templates | `customer/Templates/**`, `admin/Templates/**`, `admin/TemplateTags/**` | 6 | |
| 10 | Numbers/SenderID/Keywords/Compliance | `customer/{Numbers,SenderID,keywords}/**`, `admin/{PhoneNumbers,SenderID,BlockSenderID,keywords,SpamWord}/**` | 29 | |
| 11a | Sending Servers (customer, excl. create) | `customer/SendingServer/**` minus `create.blade.php` | 4 | |
| 11b | Sending Servers (customer, create) | `customer/SendingServer/create.blade.php` | 1 | 2,316 lines — dedicated |
| 11c | Sending Servers (admin, excl. create) | `admin/SendingServer/**` minus `create.blade.php` | 7 | |
| 11d | Sending Servers (admin, create) | `admin/SendingServer/create.blade.php` | 1 | 4,306 lines — dedicated, largest file in the app |
| 12 | Billing, Payments & Accounts | `customer/{Accounts,Payments}/**`, `customer/business/edit.blade.php`, `customer/business/usage-billing/partials/payment-method.blade.php`, `auth/payment/**` | 30 | |
| 13 | Sub-Accounts & Workspaces | `customer/{SubAccounts,workspaces}/**`, `admin/workspaces/**` | 7 | |
| 14 | Onboarding | `customer/onboarding/**` | 9 | |
| 15 | Developer/API Docs | `customer/Developers/**` | 22 | Mostly static — good low-risk late slice |
| 16 | Admin Tenant Management | `admin/{customer,businesses}/**` | 22 | Heaviest single admin module |
| 17 | Plans, Pricing & Catalog | `admin/{plans,workspace-plan-catalog,currency,taxes}/**` | 17 | `workspace-plan-catalog/index.blade.php` is NOT already migrated despite the name overlap with the M1 reference page |
| 18 | Invoices & Subscriptions | `admin/{Invoices,subscriptions}/**` | 6 | |
| 19 | Admin Users, Roles & Announcements | `admin/{Administrator,AdminRoles,Announcements}/**` | 8 | |
| 20 | Plugins & legacy Theme Customizer | `admin/{Plugins,ThemeCustomizer}/**` | 3 | The legacy `ThemeCustomizerController` (navbar/skin, `.env`-backed) and this milestone's new theme editor are **separate features**; whether to deprecate/fold the legacy one is an explicit open decision for *this* slice, not Slice 1 |
| 21 | System Settings | `admin/settings/**` | 26 | Largest settings tree; `PaymentMethods/show.blade.php` (1,937 lines) flagged for dedicated handling within this slice |
| — | Transactional email templates | `resources/views/emails/**`, `resources/views/vendor/mail/**` | 26 | **Out of scope for this entire rollout** (§3.1) |

Total remaining after Slice 1: ~326 files across slices 2-21, matching
372 total minus Slice 1's ~46.

---

## 9. Exact implementation allowlist (Slice 1 — the only implementation-
## ready scope in this contract)

**Closed, numbered, path-level. Any additional path required during
Slice 1 implementation is a STOP-and-report condition (§13), identical in
kind to every prior contract's own discipline in this repository.**

### Theme editor — schema, domain, service layer (9 new)

1. `database/migrations/{timestamp}_create_platform_theme_settings_table.php` — the table from §6.1.
2. `app/Models/PlatformThemeSetting.php`
3. `app/Repositories/Contracts/PlatformThemeSettingRepository.php`
4. `app/Repositories/Eloquent/EloquentPlatformThemeSettingRepository.php`
5. `app/Library/Theme/ThemeColorDerivationService.php` — §6.6.
6. `app/Library/Theme/ThemeContrastValidator.php` — §6.7.
7. `app/Library/Theme/PlatformThemeManager.php` — orchestrates validate → derive → persist → activate → `currentStyleBlock()` (§6.3) → restore-defaults (full and Surfaces-only).
8. `app/Library/Theme/Exceptions/InvalidThemeColorException.php`
9. `app/Library/Theme/Exceptions/UnsafeThemeContrastException.php`

### Theme editor — HTTP surface (3 new, 1 modified)

10. `app/Http/Controllers/Admin/PlatformThemeSettingController.php`
11. `app/Http/Requests/Admin/UpdatePlatformThemeSettingRequest.php`
12. `app/Http/Requests/Admin/RestorePlatformThemeSettingRequest.php`
13. `routes/admin.php` — modified: new `theme-settings` route section (index/update/restore), wrapped per §6.2. No existing route line changed or reordered.

### Theme editor — authorization config (1 modified)

14. `config/permissions.php` — modified: new `'manage theme'` entry under a new `"Appearance"` category, additive only, no existing entry changed.

### Theme editor — views + runtime injection (2 new, 1 modified)

15. `resources/views/admin/theme-settings/index.blade.php` — Colors + Surfaces sections, color pickers + hex inputs, live preview, Cancel/Save/Restore(×2) actions, built entirely from the existing 13-component library (§9 of the M1 contract) — no new bespoke UI primitives.
16. `resources/js/scripts/pages/theme-settings.js` — color-picker wiring, optimistic CSS-custom-property preview, debounced AJAX contrast-check calls (§6.7).
17. `resources/views/panels/styles.blade.php` — modified: appends the theme-override `<style>` block per §6.3, guarded by an `@if`, at the end of the existing file. No existing `<link>` line removed or reordered.

### Chart token bridge (2 new, up to 9 modified)

18. `resources/scss/base/tokens/_colors.scss` — modified: adds the three new `--color-chart-*` custom properties (§6.4) as build-time defaults, alongside the existing 12.
19. `resources/js/core/chart-tokens.js` — new: `getChartColors()`.
20. `resources/views/admin/dashboard.blade.php` — modified: chart color arrays sourced from `getChartColors()`; semantic status colors untouched.
21. `resources/views/customer/dashboard.blade.php` — modified: same discipline.
22. `resources/views/customer/Reports/charts.blade.php` — modified: same discipline.
23. `resources/views/customer/Reports/analyze.blade.php` — modified: same discipline.
24. `resources/views/admin/Reports/overview.blade.php` — modified: same discipline.
25. `resources/views/admin/Reports/dashboard.blade.php` — modified: same discipline.
26. `resources/views/customer/Campaigns/overview.blade.php` — modified: brand-series colors only; the semantic delivery-status object (§3.2) stays untouched.
27. `resources/views/customer/Automations/overview.blade.php` — modified: same discipline.
28. `resources/js/core/app.js` — modified: `window.colors.solid.primary` sourced from the runtime custom property instead of a second hardcoded `#7367F0` literal; every other key in `window.colors` left untouched (they are semantic/status colors, not brand).

### Chat background remediation (3 modified)

29. `resources/scss/base/bootstrap-extended/_variables.scss` — modified: removes the `$chat-bg-light`/`$chat-bg-dark` base64 SVG string variables (§3.3). No other variable in this file changes.
30. `resources/scss/base/pages/app-chat.scss` — modified: `.start-chat-area`/`.user-chats` background rules replaced with `background-color: var(--color-canvas)` (or the nearer-matching surface token, decided at implementation time by which reads better against message bubbles); `background-image`/`background-repeat`/`background-size` removed. Also retokenizes the outbound-bubble gradient off `var(--color-accent-primary)`/`var(--color-accent-hover)` and the inbound-bubble fill off a surface token, per §3.3's finding that both are currently tied to the legacy purple map — kept in this one file if the existing selectors live here, split into item 31 if they in fact live in `app-chat-list.scss` (confirmed at implementation time; both files are already allowlisted so this is not a scope question, only a "which exact file" bookkeeping question).
31. `resources/scss/base/pages/app-chat-list.scss` — modified: bubble color retokenization per the above, if not already covered by item 30.

**Explicit, deliberate exception to the Milestone-1 "dark-theme bundle is
never touched" default (documented, not silent):**

32. `resources/scss/base/themes/dark-layout.scss` — modified: removes the
    `$chat-bg-dark` reference (§3.3) and adjusts the corresponding dark-
    mode bubble color overrides (`:1742-1751,1784`) to the same
    token-driven scheme as the light bundle. This is the one place in
    this milestone where touching the dark bundle is *required*, not
    incidental — Milestone 1's stop condition ("dark-theme bundle appears
    to require touching") is intentionally, explicitly overridden here by
    this contract's own §4.3 requirement, and nowhere else in Slice 1.

### Remaining Feather→Lucide icon migration in shared chrome (7 modified)

33. `resources/views/panels/navbar.blade.php`
34. `resources/views/panels/sidebar.blade.php`
35. `resources/views/panels/footer.blade.php`
36. `resources/views/panels/breadcrumb.blade.php`
37. `resources/views/panels/submenu.blade.php`
38. `resources/views/panels/horizontalMenu.blade.php`
39. `resources/views/panels/horizontalSubmenu.blade.php`

(Milestone 1 already gave these files "minimal safe additive class
changes" — transition utilities, one typography class — but explicitly
left their `data-feather="..."` icons unconverted, per its own report.
This item completes that, replacing each with `<x-ds-icon>`, preserving
every existing `id`/`data-*`/route/text.)

### Errors module (7 modified)

40. `resources/views/errors/401.blade.php`
41. `resources/views/errors/403.blade.php`
42. `resources/views/errors/404.blade.php`
43. `resources/views/errors/419.blade.php`
44. `resources/views/errors/429.blade.php`
45. `resources/views/errors/500.blade.php`
46. `resources/views/errors/503.blade.php`

(Small, static, no forms/tables/JS logic — confirmed by audit as the
lowest-risk page-level slice; good proof that the component library
applies cleanly outside the two M1 reference pages before Slice 2's
larger, stateful Authentication module.)

**Total: 46 files** (23 new, 23 modified). Any path beyond this list
required during Slice 1 implementation is a required-47th-path-shaped
stop condition (§13).

---

## 10. Test contract (Slice 1)

New test files (exact class names indicative, finalized at implementation
time within this scope):

- **Authorization**: `PlatformThemeSettingAuthorizationTest` — a customer,
  a Workspace owner/member, and an admin *without* `'manage theme'` are
  all denied (403/redirect); an admin *with* it succeeds. Proves §4.1's
  "not ordinary Workspace owners, members or customers" and §6.2's
  permission-based design.
- **Validation**: `PlatformThemeSettingValidationTest` — invalid hex
  strings rejected; missing required fields rejected; a color combination
  that fails §6.7's hard-reject contrast threshold is rejected with a
  specific error, not a generic one; a combination that only trips the
  soft-warning threshold is *not* blocked but the warning is present in
  the response.
- **Derivation accuracy**: `ThemeColorDerivationServiceTest` — asserts
  §6.6's implementation-time verification requirement: deriving from the
  default Primary reproduces the approved Hover/Dark/Soft-background hex
  values within the stated tolerance.
- **Persistence**: `PlatformThemeSettingPersistenceTest` — save creates a
  new row and correctly flips `is_active`; "Restore approved defaults"
  (both whole-theme and Surfaces-only) creates its own new row with the
  approved constants and its own `change_scope`.
- **Audit**: `PlatformThemeSettingAuditTest` — every save/restore records
  the acting admin's user id and a timestamp; two different admins
  produce two distinguishable rows.
- **Isolation**: `PlatformThemeSettingIsolationTest` — confirms no
  Agency/Workspace-scoped override exists or is reachable this milestone
  (§4.1's explicit exclusion) — a negative test proving the deferred
  scope genuinely isn't half-built.
- **Rendering / fail-safe**: `PlatformThemeRuntimeRenderingTest` —
  with an active row, the rendered `<head>` contains the matching
  `<style id="platform-theme-overrides">` values; with **no** active row
  (fresh install) or a row whose JSON fails validation, the block is
  absent entirely and the page still renders using Milestone 1's compiled
  defaults — proving §4.1's "fail safely" and "no flash" requirements
  structurally, not just by inspection.
- **Chart tokens**: a mechanical content test (not a full browser test)
  asserting none of the eight files in §9 items 20-27 contain the literal
  string `7367F0` (case-insensitive) any more, while the semantic
  status-color objects (e.g. `delivered`/`failed`) are still present
  unchanged.
- **Chat background**: `ChatBackgroundTokenTest` — asserts
  `_variables.scss` no longer defines `$chat-bg-light`/`$chat-bg-dark`;
  asserts `app-chat.scss`/`app-chat-list.scss`/`dark-layout.scss` contain
  no `background-image` rule referencing a `data:image` URI anywhere in
  the chat-related selectors; asserts the message-bubble-distinguishing
  classes (`.chat-left`, etc.) and every must-preserve element from §3.3
  (composer, attachment input, template picker, send button, timestamp,
  notification badge, pin/block/delete, empty state, Pusher guard) are
  still present, byte-for-byte, in `ChatBox/index.blade.php`.
- Full existing suite re-run with the exact pass count compared against
  the pre-Slice-1 baseline (currently 2,724 passed / 8,672 assertions,
  per the Milestone-1 implementation report) — zero regressions permitted.

---

## 11. Mechanical searches (Slice 1)

1. `grep -rniE "anthropic|claude"` across every path in §9 → zero matches
   (same legal/identity boundary as Milestone 1, mechanically enforced).
2. `grep -c "7367F0"` (case-insensitive) across §9 items 20-28 → zero.
3. `grep -n "chat-bg-light\|chat-bg-dark"` across §9 items 29-32 → zero.
4. `grep -rn "data-feather" resources/views/panels/{navbar,sidebar,footer,breadcrumb,submenu,horizontalMenu,horizontalSubmenu}.blade.php` → zero (items 33-39 fully converted).
5. `git diff --stat -- app database routes` compared against a curated
   allowlist subset (only §9 items 1-14 may appear) → any other path in
   `app/`, `database/`, or `routes/` is a violation.
6. `git diff --stat -- resources/scss/base/themes/{bordered-layout,semi-dark-layout}.scss resources/scss/base/custom-rtl.scss resources/assets/scss/style-rtl.scss` → empty (only `dark-layout.scss`, item 32, is the deliberate, documented exception — the other three theme/RTL bundles remain genuinely untouched, unlike item 32).
7. Final changed-path set (`git diff --name-only` + `git ls-files --others --exclude-standard`) equals §9's exact 46-item allowlist.
8. `grep -rn "'manage theme'"` across `config/permissions.php`, the new controller, and the new FormRequests → present and consistent in all three (no permission-string typo drift).
9. `php artisan test` full-suite pass count compared against the
   pre-Slice-1 baseline, reported exactly, never estimated (same
   discipline as every prior milestone in this repository).

---

## 12. Stop conditions

Slice 1 implementation must stop, leave the working tree unstaged, and
report rather than proceed, if:

- Any path beyond §9's 46-item allowlist is required.
- Any change to `app/`, `database/`, or `routes/` beyond §9 items 1-14
  appears necessary.
- The `bordered-layout`/`semi-dark-layout`/RTL bundles (as opposed to
  `dark-layout.scss`, item 32's documented exception) appear to require
  touching.
- §7's open technical decisions (secondary/accent and chart default hex
  values, header/nav override editability, live-preview composition) have
  not been explicitly confirmed before implementation begins — these are
  not silently resolvable defaults, they are proposals awaiting
  confirmation.
- Any derived color pair required to render legible text fails §6.7's
  hard-reject threshold even for the *approved default* palette — this
  would mean the derivation formula itself (§6.6) is wrong, not that the
  palette is wrong, and must be fixed before proceeding, never worked
  around by loosening the threshold.
- Any existing test fails for a reason not fixable within Slice 1's own
  allowlist.
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is
  found necessary to reference (carried forward from Milestone 1's own
  boundary, §3.1 of that contract).
- A theme value would need to be persisted as anything other than one of
  the fixed, named hex-string fields in §6.1's schema (i.e., anything
  resembling a "custom CSS" or "raw HTML" field becoming necessary) —
  this would violate §4.1/§4.4's explicit prohibition and must stop
  rather than be added.

---

## 13. Contract self-audit

1. Every locked requirement in the human instruction (§4.1-§4.5) is
   addressed by a numbered decision in §6 and, where implementation-ready,
   a numbered path in §9. ✓
2. The three genuinely ambiguous specifics (no default hex given for
   secondary/accent, chart-primary, chart-secondary; header/nav override
   editability; live-preview composition) are flagged as open decisions
   in §7 with reasoned proposals, not silently resolved. ✓
3. The storage-mechanism decision (§6.1) and the authorization decision
   (§6.2) are both traced directly to specific audit findings (§3.4,
   §3.5), not asserted without basis. ✓
4. The one deliberate exception to Milestone 1's "never touch the dark
   bundle" default (item 32) is named explicitly, reasoned, and scoped to
   exactly one file — not a general loosening of that rule. ✓
5. **Machine-verified**, not just visually cross-checked: `find
   resources/views/{customer,admin,auth,errors,layouts,panels,emails,
   vendor,Installer,plugins,components} -name "*.blade.php" | wc -l`
   reproduces the audit agent's exact per-directory counts (147/127/32/7/
   7/9/10/16/3/1/15, summing to 374). Of the 374, 15 are the component
   library itself (not rollout targets) and 2 are already migrated,
   leaving 357 remaining Blade views. §9's Slice 1 allowlist is a mix of
   23 brand-new/non-Blade files (migration, models, services, controller,
   requests, routes/config edits, 2 new JS files, 5 SCSS edits) plus 23
   *existing* Blade views it touches (8 of 9 `panels/*`, all 7 `errors/*`,
   8 chart-bearing views). The reconciliation is exact:
   **23 (Slice 1's existing-view touches) + 308 (§8's slices 2-21,
   summed) + 26 (out-of-scope `emails`/`vendor/mail`) = 357** — the full
   remaining-view count, with no file double-counted or omitted.
6. Slice 1's own scope (§9) is genuinely implementable as a closed,
   coherent unit — it does not depend on any Slice 2+ file, and no
   Slice 2+ module depends on anything *other* than Slice 1's runtime
   token/permission infrastructure being in place first (i.e., Slice 1 is
   correctly the foundation, not an arbitrary first pick). ✓
7. No business logic, permission model (beyond the one new additive
   `'manage theme'` string), route behavior, tenant isolation, or data-
   flow of any existing feature changes anywhere in §9. ✓
8. This document itself is the only file changed on this branch (§2). ✓

---

## 14. Verification and publication (this document only)

1. `git status` on `chore/design-system-m2-contract` shows exactly one
   changed path: this file.
2. Stage individually (`git add docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`),
   never `git add -A`/`.`.
3. Commit message: `docs: prepare design system Milestone 2 contract`.
4. Push to `origin chore/design-system-m2-contract`.
5. Open a PR against `main` (or provide the compare URL if `gh` is
   unavailable in the environment).
6. **Do not merge.** Do not begin Slice 1 implementation. Both require
   separate, explicit, future authorization — merging this contract only
   locks the rollout map and Slice 1's allowlist; it does not start work.
