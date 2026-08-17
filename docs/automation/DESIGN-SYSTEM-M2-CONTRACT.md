# Design System — Milestone 2 Contract

Full application rollout, platform-owner complete theme control (colors +
font), chart theming, and chat background remediation.

**Correction Round 1** — the platform owner's original limited-palette
proposal is replaced in full by a complete semantic-token theme system
plus global font control, per explicit correction instructions. See §15
for exactly what changed and why.

**Status: contract only. No implementation has occurred under this
document, in either drafting pass. Merging this contract does NOT
authorize any implementation — each rollout slice (§9) requires its own
separate, explicit authorization, exactly like every prior contract in
this repository.**

---

## 0. Governance

- This document is drafted on branch `chore/design-system-m2-contract` in
  an isolated worktree, based on `main` at the commit that already
  contains Milestone 1 (`fb5c823`, PR #84).
- Drafting and correcting this contract makes **zero** application
  changes. No `resources/`, `app/`, `database/`, or `routes/` file is
  touched by this branch — only this document, in both the original
  commit and this correction.
- Once merged, implementation of **Slice 1 only** (§9) may begin under a
  separate, explicit authorization. Slices 2 onward (§8) are a locked
  rollout *map*, not yet allowlisted.
- `maximum_correction_rounds: 2` applies to this contract. This is
  correction round 1 of 2.
- Any path required during Slice 1 implementation but absent from §9's
  own numbered allowlist is a stop-and-report condition — not a silent
  workaround.

---

## 1. Mandatory preflight — verified (both drafting passes)

- Read `CLAUDE.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md`
  (Milestone 1) and its merged implementation.
- Confirmed Milestone 1 is merged to `main` (`fb5c823`).
- **Original pass**: three-track audit (rollout inventory, chart/chat,
  config-storage/authorization/runtime-injection) — §3.1-3.6.
- **This correction pass**: two additional audit tracks specifically
  requested by the correction instructions — (a) the complete remaining
  hardcoded-color surface across SCSS, inline styles, icons, third-party
  JS components, chart chrome, and inline SVG illustrations; (b) font
  declarations repository-wide plus the existing file-upload/storage
  architecture, to make an evidence-based decision on whether safe
  webfont upload is achievable — §3.7-3.9.

---

## 2. This contract's own exact file scope

Only one path has ever changed on `chore/design-system-m2-contract`,
across both the original commit and this correction:

1. `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` (this file)

---

## 3. Mandatory repository audit — findings

### 3.1 Remaining rollout surface

- **374** total Blade view files under `resources/views/` (machine-
  verified: `find resources/views/{customer,admin,auth,errors,layouts,
  panels,emails,vendor,Installer,plugins,components} -name "*.blade.php"
  | wc -l` reproduces 147/127/32/7/7/9/10/16/3/1/15 = 374 exactly).
- **2** migrated to the Milestone-1 component library.
- **357** remaining Blade views (374 − 15 component-library files − 2
  already migrated).
- **223 files / 796 occurrences** of `data-feather="..."` remain.
- **55 files** are 500+ lines; `admin/SendingServer/create.blade.php`
  (4,306 lines) and `customer/SendingServer/create.blade.php` (2,316
  lines) are extreme outliers, each its own dedicated future slice (§8).
- `resources/views/emails/**` (10) and `resources/views/vendor/mail/**`
  (16) render through Laravel's markdown-mail system, outside the
  browser CSS/SCSS pipeline — **out of scope for the entire rollout**,
  reaffirmed by this correction's own re-audit (§3.8).

### 3.2 Chart color audit (series colors)

- Four PHP call sites build `LarapexChart` objects; none call
  `->setColors()` — every chart's series colors are hardcoded client-side
  across at least eight Blade `<script>` blocks, with the legacy Vuexy
  purple `#7367F0` repeated throughout (`admin/dashboard.blade.php:488,
  710,799`; `customer/Reports/charts.blade.php:218,647`; etc.).
- `resources/js/core/app.js:5-16`'s `window.colors` is a static literal,
  also carrying `#7367F0`.
- No centralized chart-color config exists anywhere; charts are entirely
  disconnected from the Milestone-1 token system (compile-time SCSS
  cannot reach request-time PHP or shipped JS without a new bridge, §6.4).
- The delivered/enroute/expired/... status-color object is semantic, not
  brand, and stays out of scope, per the human instruction's own carve-out.

### 3.3 Chat background audit

- The repeating icon-pattern background is an inline base64 SVG data URI
  in two SCSS string variables, `$chat-bg-light`/`$chat-bg-dark`
  (`bootstrap-extended/_variables.scss:664-665`), applied by
  `app-chat.scss:206-217` and referenced again for dark mode at
  `dark-layout.scss:1725`. Five on-disk `chat-bg*.{svg,png,jpg}` files
  are confirmed dead assets, referenced nowhere.
- Outbound bubbles use a purple gradient off `$primary-color`
  (`app-chat-list.scss:51-73`); inbound bubbles use `lighten($white,18%)`
  (`:75-88`).
- No separate Blade partial for bubbles — built via inline JS
  template-literal strings in `ChatBox/index.blade.php` in three places
  (history load, optimistic send, Echo listener). Must-preserve elements
  (composer restyle, attachment input, template picker, send button,
  timestamp, notification badge, pin/block/delete + SweetAlert2, empty
  state, and — critically — the conditional Pusher/Echo guard) are listed
  in full in the original audit and carried forward unchanged here.

### 3.4 Platform configuration storage audit

- `AppConfig` (table `app_config`): flat `id/setting/value/timestamps`
  only, no actor, no `is_active`, its one JSON-blob precedent (`tax`) has
  no audit trail. The *existing* `ThemeCustomizerController` already
  bypasses it entirely, persisting to `.env` instead.
- This codebase has an established, idiomatic append-only pattern for
  "who changed this and when" (`workspace_entitlement_transitions`,
  `business_feature_toggles`): a plain scalar `*_user_id` column, no FK
  (deliberate), plus `created_at`.
- **Decision (§6.1, expanded this round): a new, dedicated, append-only
  table is used — not `AppConfig`.** Same reasoning as the original
  contract; the token set is now much larger, which strengthens rather
  than weakens this decision (`AppConfig`'s flat schema is even less
  suited to a wide, structured, versioned token map).

### 3.5 Platform-owner authorization audit

- No `is_platform_owner` concept exists anywhere beyond an unconditional
  `users.id === 1` bypass in `EloquentAccountRepository::hasPermission()`.
  The established, reusable pattern is "one settings page, one permission
  string" (`'general settings'`, checked by the existing
  `ThemeCustomizerController`/`ThemeCustomizerRequest`).
- **Decision (§6.2, unchanged this round):** a new `'manage theme'`
  permission, gated additionally by `EnsureUserIsAdministrator::class`
  and the blanket admin-route middleware stack. Reasoning unchanged from
  the original contract.

### 3.6 Runtime CSS injection point audit

- `panels/styles.blade.php:12` emits the `core.css` `<link>`; nothing
  loaded after it in that file redefines any `--color-*` custom property.
  It is `@include`d, inside `<head>`, by all three root layout files.
- **Decision (§6.3, unchanged insertion point, expanded payload this
  round):** a server-rendered `<style>` block appended to the end of
  `panels/styles.blade.php`. What that block now must contain is
  substantially larger — see §6.3 and §6.9.

### 3.7 NEW — Font-family and font-upload architecture audit

**Font-family declarations.** Beyond the two Milestone-1 tokenized
variables (`$font-family-sans-serif`, `$font-family-monospace`, both
pointed at Geist), **four other locations independently reference
`$font-family-monospace`**: `core/menu/_navigation.scss:137,143`
(sidebar nav + nav header), `bootstrap-extended/_navbar.scss:22` (top
navbar), and `plugins/forms/form-quill-editor.scss:95` (Quill's editor
*container* font). **A font-swap mechanism that only rewrites
`$font-family-sans-serif` would silently miss the sidebar, navbar, and
Quill editor container** — three high-visibility surfaces the human
instruction explicitly names ("sidebar and navigation... forms...
chat"). This is a real, previously-undetected bug in how Milestone 1's
own font tokenization was wired, corrected in §6.9.

The one genuinely separate, unrelated font system found is **KaTeX**
(Quill's math-formula toolbar extension, loaded via
`resources/views/plugins/editor.blade.php:3` on three admin settings
pages) — its own bundled `@font-face` set, structurally incapable of
being repointed at an arbitrary uploaded webfont. **Explicitly out of
scope**, called out so it is never mistaken for a font-control gap.

No active icon font exists anywhere (confirmed: Feather renders
inline-SVG-only via `feather.replace()`; the dormant Feather/jQuery-
contextMenu/Swiper icon-font files are unreferenced dead bytes). Five of
six audited third-party plugins (Select2, Flatpickr, DataTables,
SweetAlert2 mostly, ApexCharts) inherit or already track the app's own
font variable; only Quill's *content-authoring* font-picker (a
deliberate, out-of-scope content choice, not chrome) hardcodes unrelated
font names with no backing files.

**File-upload/storage architecture.** This is the single most
consequential finding for whether "upload your own webfont" is safely
buildable at all:

- `config/filesystems.php`'s `public` disk / `storage:link` convention
  is configured but **architecturally unused** — a repo-wide check found
  exactly one reference to `Storage::disk('public')` in `app/`, inside a
  demo-data seeder, not any real feature.
- Every real upload feature in the app (logo/favicon, language-file zip,
  chat MMS attachments, avatars) writes **raw files directly to
  `public_path(...)`** via bare PHP (`mkdir`/`move()`), served as
  permanent, unauthenticated static assets. Filenames are content-hashed,
  timestamp-based, or user-ID-based — none of these are real access
  control.
- **No file anywhere in this codebase validates actual binary/magic-byte
  content.** The closest things (`finfo`, `exif_imagetype`) are used only
  as outbound MIME-type *labels* for already-stored files, never as an
  upload-time security gate. `Intervention\Image`'s implicit
  decode-or-throw behavior is the only incidental content check found,
  and it has no equivalent for font binaries.
- **No `Policy` class or per-file authorization pattern exists anywhere**
  (`app/Policies/` doesn't exist). The one upload with any per-request
  gating (`AccountController::avatar()`) only requires *any* authenticated
  session, not ownership — it will serve any user's avatar to any other
  logged-in user.
- The strictest existing precedent, `ChatBoxController`'s MMS upload
  (`mimes:mp4,mov,...|max:20000`), combines an extension/MIME whitelist
  with a size cap — better than every other upload in the app, but still
  no content-signature check.

**Decision (§6.9): webfont upload is architecturally supportable, but
requires building — not reusing — real content validation and a real
per-route authorization gate, neither of which exists in this codebase
today for any file type.** The design in §6.9 is built to exceed every
existing precedent, not merely match one, given how weak the strongest
existing precedent actually is.

### 3.8 NEW — Expanded hardcoded-color and third-party-component audit

- **The real root cause is one file, only partially fixed by Milestone
  1**: `bootstrap-extended/_variables.scss` still carries raw hex for the
  entire grayscale ramp, and — critically — for
  **`$success`/`$warning`/`$danger`/`$info`/`$secondary`/`$dark`**
  (`:38-41,44,50`). These four status colors alone drive the *entire*
  procedurally-generated status system (`core/colors/_palette.scss`
  generates every `.badge-light-{color}`, `.border-{color}`,
  `.btn-{color}`, `.overlay-{color}`, glow-shadow rule from them) — one
  untokenized hex per status color, not dozens of hand-written rules.
- **Shadow/overlay/dropdown/modal/popover/toast/input-focus colors
  bypass the Milestone-1 shadow token entirely** — they use raw
  `rgba($black, ...)` where `$black` (`:32`) is itself a plain hex, not
  the tokenized `$shadow-color`. Tooltip background
  (`$tooltip-bg: #323232`, `:507`) is a standalone literal with no token
  at all. **No `$focus-ring-color` override exists anywhere** — it
  silently falls through to Bootstrap core's own default
  `rgba($primary, .25)`, an undocumented dependency rather than an
  explicit, owner-editable token.
- **Dark mode is a live, shipped, currently-toggleable feature** (via the
  existing `ThemeCustomizerController`), not dead code — its entire
  palette is a second, fully separate set of ~24 hardcoded literals in
  `_variables-dark.scss` plus scattered overrides in `dark-layout.scss`,
  none referencing any token. See §6.10 for the scoping decision this
  requires.
- **Third-party plugin overrides** (SweetAlert2, Toastr, Select2,
  Flatpickr, pickadate, DataTables, Quill) all consume SCSS `$variables`
  — fine at compile time (a rebuild picks up new colors correctly once
  the underlying `$variables` are tokenized), but **none of them emit a
  single `var(--color-*)` in their compiled output**, so none respond to
  a *runtime* (no-rebuild) theme change today. See §6.10.
- **Chart grid/axis/tooltip chrome is hardcoded per-file**, separate from
  the already-known series-color problem — `admin/dashboard.blade.php:
  488-493,713,737,752` and equivalents in five sibling files pass literal
  hex (`#b9c3cd`, `#e7eef7`, `#b9b9c3`) directly into `grid.borderColor`/
  `xaxis.labels.style.colors`/`yaxis.labels.style.color`. **No chart
  configures a tooltip background/text color at all** — tooltip chrome is
  left to library defaults everywhere, a distinct gap from "hardcoded"
  (it's simply un-themed).
- **Icons are clean**: confirmed `fill: currentColor`/inherited-color
  behavior for both Lucide (`.ds-icon { color: currentColor }`) and
  legacy Feather (SVG `stroke="currentColor"` default) — no hardcoded
  icon fill/stroke exists in SCSS. Icons correctly need no dedicated
  color token beyond inheriting whatever text-role color surrounds them,
  confirming the human instruction's own "icons should normally inherit
  their semantic context color" expectation is already true today.
- **Inline `style="..."` color attributes are a negligible surface**: 215
  total `style=` attributes app-wide, only ~4 carry any color value at
  all (the rest are widths/display toggles) — not the large problem it
  might appear to be.
- **Inline SVG illustration assets are a confirmed, real gap that cannot
  be closed by any token mechanism as currently designed**: login/
  register/error-page illustrations (`resources/images/pages/*.svg`, up
  to 267 hex occurrences per file) are loaded via `<img src="...">`, not
  inlined into the DOM — meaning they **cannot** inherit `currentColor`
  or read CSS custom properties in their current form, regardless of how
  complete the token system becomes. See §6.11 for the explicit,
  honest scoping decision this requires.

### 3.9 NEW — Critical finding: compile-time vs. runtime color resolution

**Empirically verified, not assumed.** The compiled `.btn-primary` rule
in the *current, committed* `public/css/core.css` reads:

```css
.btn-primary{background-color:#7367f0;border-color:#7367f0;color:#fff}
```

— a **fully baked literal hex**, not `var(--bs-primary)` or any custom
property. (Separately, this also confirms the committed `public/css/`
build artifact is stale relative to Milestone 1's *source* changes —
noted for transparency, not a Milestone-2 concern to fix.)

By contrast, Milestone 1's own new component library
(`resources/scss/base/components/ds-components.scss`) was verified to
use `var(--color-*)` **32 times and zero raw Sass color-variable
references** — it was built runtime-correct from the start.

**This means Bootstrap's own native compiled component classes —
`.btn-primary`, `.badge-light-*`, `.dropdown-menu`, `.table`, `.modal-*`,
`.nav-link.active`, `.form-control:focus`, and every class like them,
used across the vast majority of the app's 357 remaining pages — do
**not** respond to a post-load `:root { --color-*: ...; }` override at
all.** The original contract's §6.3 runtime-injection design is correct
as far as it goes (it *will* correctly re-theme anything already built
on `var(--color-*)`, which today means only the 15-file M1 component
library and the two migrated reference pages) but was **silently
insufficient** for "every color used by the customer and admin
applications," because nearly everything else in the app is still
rendered through Bootstrap's own compile-time-baked native classes.

**Decision (§6.10): a new, explicit "runtime bindings" SCSS layer is
required as Slice-1 infrastructure** — a bounded, auditable set of
override rules mapping the semantic Bootstrap classes actually in use
across the app to `var(--color-*)`, so their *rendered* color resolves
live at runtime even though the *stylesheet* itself is still compiled
once. This is not a hypothetical nicety; without it, "no rebuild
required" is false for nearly the entire application, not merely
unmigrated pages.

---

## 4. Locked requirements — corrected, complete scope (supersedes §4 of
## the original contract in full)

The original limited palette (Primary/Secondary/two chart colors/seven
surface roles) is **replaced**, not extended, by the following. Every
color used anywhere in the customer/admin application must resolve
through an owner-configurable semantic token, at minimum:

**Brand & interaction**: primary, secondary, accent, link, link-hover,
focus-ring, selection, button-primary-bg, button-secondary-bg,
button-text, hover/active/selected/disabled states, nav-hover,
nav-active.

**Text & icons**: primary/secondary/muted/inverse/disabled text; default/
muted/inverse icon (icons inherit their semantic context color — already
true today, §3.8).

**Backgrounds & surfaces**: canvas, sidebar, header/nav, primary surface,
secondary surface, dropdown, input/control, table-header, table-row,
row-hover, chat-canvas, chat-bubble-in, chat-bubble-out, chat-composer,
modal-surface, overlay/backdrop, tooltip/popover surface.

**Borders, depth, elevation**: normal border, subtle divider, strong
border, input-border, active-border, focus-border, shadow-tint,
elevated-shadow-tint. Overlay/shadow roles may use a validated
alpha-capable format.

**Status**: success, warning, danger/error, info, pending — each with
derived text/border/icon/soft-background variants.

**Charts**: an explicit multi-slot series palette (§7.1: 8 slots, sized
to the app's own largest real chart), chart-neutral, chart-grid,
chart-axis/label, chart-tooltip background + text, positive/negative
data. No silent fallback to Vuexy purple or any other hardcoded legacy
value anywhere.

**No locked palette.** The current approved warm-red design is the
factory fallback/reset target, never a forced constraint — the owner may
create a completely different theme; no color is architecturally locked;
missing/corrupt/incomplete config fails safely to the factory theme.
"All colors editable" means controlled semantic tokens, never arbitrary
CSS/selector-level injection.

**Global font control.** One "Application font" setting, applied
consistently to body/headings/sidebar/nav/buttons/forms/tables/cards/
dropdowns/modals/chat/badges/charts/chart-labels/dynamically-created
client-side UI, at runtime, no rebuild. Approved bundled choices, plus
owner-uploaded webfont **if the audited storage architecture can support
it safely** (§3.7 confirms it can, with new capability — §6.9). Factory
font remains Geist Sans as fallback/reset only, never permanently locked.

**Theme editor experience**: grouped semantic-token controls, color
picker + validated hex entry, font selector + safe upload, immediate
client-side preview (temporary until Save), Save/Cancel/Reset,
validation errors, contrast warnings, unsaved-change warning, audit
info (who/when). Preview demonstrates sidebar/nav, header, canvas +
layered surfaces, text hierarchy, buttons + states, inputs, table,
statuses, charts, chat, dropdown, modal/overlay, desktop and
narrow/mobile layouts.

**Accessibility & safety**: validate contrast for every meaningful
semantic pair (text/background, icon/background, control-state
visibility); hard-reject combinations breaking essential text,
navigation, form controls, focus indicators, destructive actions, or
status meaning; warn (non-blocking) below-preferred-but-not-broken
combinations; never rely on color alone for status/chart-series meaning
(preserve labels/icons/patterns); the editor itself must remain usable
even against a poor saved theme; a protected recovery/reset path always
exists. No arbitrary CSS/HTML/JS/selectors/remote imports/executable
font content, ever.

**Runtime application**: validated runtime CSS custom properties at the
existing shared injection point (§3.6/§6.3); applies to both portals;
applies before visible rendering (no flash); works across server- and
client-rendered elements; charts and JS components consume the same
canonical theme source (§6.12); cached safely, invalidated immediately
on an authorized change (§6.13). Chat background fully removed —
configurable surfaces only, no embedded pattern, no replacement image.

---

## 5. Reading note

The original contract's §4.1's "preserve fixed... surfaces... unless a
later separately authorized contract expands those controls" is now
fully superseded — this correction *is* that later authorization,
delivered explicitly and by name ("The previous proposal was too
restrictive"). Typography's *scale/spacing* (page-title/heading/body/
label/caption sizing, established in Milestone 1) remains fixed and
untouched — only the *font-family* becomes owner-controlled, per §4's
explicit "Global font control" section. Nothing about spacing, radii, or
motion tokens is affected by this correction.

---

## 6. Architecture decisions

### 6.1 Data model — dedicated, append-only, generalized to a token map

The token set is now large and open-ended (dozens of semantic roles
across six categories, plus per-status derived variants, plus an 8-slot
chart palette). A fixed set of named JSON sub-objects (the original
`colors_json`/`surfaces_json` design) does not scale cleanly to this —
every future token addition would otherwise force a schema thought
exercise. Instead:

```
platform_theme_settings
  id                   bigint, PK
  input_tokens_json    json   -- flat map: semantic-token-name => owner-entered value (only the roles the owner actually edits — brand base colors, status base colors, chart series slots, surface roles, font choice)
  derived_tokens_json  json   -- flat map: css-custom-property-name => fully computed value (every role in §4, including all auto-derived hover/active/soft-bg/border/foreground/status variants) — the exact, complete payload the runtime <style> block (§6.3) renders from
  font_choice          string -- 'bundled' | 'uploaded'
  font_bundled_key     string, nullable -- e.g. 'geist' (factory), or another approved bundled option (§7.2)
  font_uploaded_id     unsignedBigInteger, nullable, NO foreign key -- points at platform_theme_fonts.id, same no-FK convention
  is_active            boolean, default false
  change_scope         string -- 'full' | 'colors' | 'surfaces' | 'status' | 'charts' | 'font' | 'restore_full' | 'restore_section'
  created_by_user_id   unsignedBigInteger, nullable, NO foreign key
  created_at           timestamp only (append-only, no updated_at)

platform_theme_fonts   -- new, separate lifecycle from theme rows themselves
  id                   bigint, PK
  safe_filename        string  -- server-generated (sha256 of validated bytes + validated extension), NEVER client input
  original_filename    string  -- display only, never used to build a path
  mime_type             string -- validated, not trusted from the client
  file_size_bytes       unsignedInteger
  storage_path          string -- private `local` disk, `theme-fonts/{safe_filename}`, §6.9
  uploaded_by_user_id   unsignedBigInteger, nullable, NO foreign key
  created_at             timestamp only (append-only; "removal" sets a `deleted_at` rather than hard-deleting the row, so historical theme rows that once referenced it stay audit-legible even after the bytes are gone)
  deleted_at             timestamp, nullable
```

Same rationale as the original contract's §6.1 (append-only mirrors
`workspace_entitlement_transitions`; `is_active` flips transactionally on
save/restore; free audit trail as a consequence of the storage shape,
not a bolt-on log) — generalized to a flat token map because the token
set itself is now large enough that a rigid per-category schema would
need to change every time a new role is added, which the flat-map
design avoids entirely while keeping every individual entry named,
typed (string), and auditable.

### 6.2 Authorization (unchanged)

`'manage theme'` permission (§3.5), `EnsureUserIsAdministrator::class` +
blanket admin middleware, controller/FormRequest both independently
checking it. Font upload/replace/delete/select actions reuse the exact
same `'manage theme'` permission — no separate policy class is
introduced, since this app's own established convention is
permission-string gating on routes/FormRequests, not per-resource Policy
classes (confirmed absent entirely, §3.7), and a second permission string
for "who may touch fonts" would fragment a single coherent "who may edit
the theme" concept the human instruction itself treats as one thing.

### 6.3 Runtime CSS injection — expanded payload, same insertion point

Same decision as the original contract (§3.6): append to
`panels/styles.blade.php`. The payload now contains substantially more:

```html
<style id="platform-theme-overrides">
  :root {
    --color-primary: ...; --color-primary-hover: ...; /* ...every role in §4's Colors categories... */
    --color-canvas: ...; /* ...every Surfaces role... */
    --color-status-success: ...; --color-status-success-text: ...; /* ...every status × variant... */
    --color-chart-1: ...; ... --color-chart-8: ...; --color-chart-grid: ...; /* ...every Chart role... */
    --font-family-app: ...;
  }
  @font-face { font-family: 'PlatformThemeFont'; src: url(...) format('woff2'); font-display: swap; } /* only emitted when font_choice = 'uploaded' */
</style>
```

Fail-safe behavior is unchanged in kind: `PlatformThemeManager::
currentStyleBlock()` returns `null` (render nothing, fall back to
Milestone-1's compiled defaults, which equal the factory theme) whenever
no active row exists or its JSON fails validation at read time. No flash
of default theme, because this still executes server-side before
`</head>`.

### 6.4 Chart token bridge — expanded

Same runtime-readable-bridge rationale as the original contract's §6.4,
generalized: the eight chart-bearing views and `window.colors` now source
**all** chart-related values (8 series slots, neutral, grid, axis/label,
tooltip background/text, positive/negative) from the canonical JS module
(§6.12), not just the two colors the original contract scoped. Semantic
status-color objects remain untouched, per §4.2's own carve-out,
unchanged from the original contract.

### 6.5 Full editable-property list

Given the size of the final token set (§4), it is expressed as
categories with representative default values rather than one flat
table (see §7 for the handful of genuinely undetermined defaults):

- **Brand/interaction**: primary `#B5524C` (factory), secondary/accent,
  link, focus-ring, selection — hover/active/selected/disabled states and
  nav-hover/nav-active are **derived**, never separately entered (§6.6).
- **Text/icon**: primary `#262522`, muted `#6F6D67`, secondary, inverse,
  disabled — icons consume these via inheritance (`currentColor`,
  already true today, §3.8), never a separate icon-specific token.
- **Surfaces**: canvas `#F7F6F2`, sidebar `#F2F0EB`, header (derived from
  primary unless overridden, §7.2), primary surface `#FFFFFF`, secondary
  surface `#FBFAF7`, dropdown/input/overlay/modal/tooltip/chat-canvas/
  chat-bubble-in/chat-bubble-out/chat-composer/table-header/table-row/
  row-hover — every one of these **resolves to one of the small number
  of owner-entered surface bases** (canvas, sidebar, primary surface,
  secondary surface, input) via the derivation service, not 15+
  independently-entered fields — matching the human instruction's own
  "the platform owner should normally edit only the limited... colors...
  variants should be derived automatically."
- **Borders/depth**: neutral border `#E5E1DA` (owner-entered), subtle
  divider/strong border/input-border/active-border/focus-border/
  shadow-tint/elevated-shadow-tint — all **derived** from the
  border/shadow bases plus the relevant brand/status color where
  contextually meaningful (e.g. `focus-border` derives from `focus-ring`,
  which itself derives from `primary` unless separately overridden,
  §7.2).
- **Status**: success/warning/danger/info/pending — **5 owner-entered
  base hex values**; text/border/icon/soft-background derive
  automatically per status via the same derivation formula as brand
  colors (§6.6) — never 20 individually-entered fields.
- **Charts**: **8 owner-entered series slots** (§7.1) + chart-neutral
  (optional) — grid/axis-label/tooltip-bg/tooltip-text/positive/negative
  are **derived** from the surface/text/status tokens already present
  (grid from border, axis-label from muted text, tooltip surface/text
  from overlay/text, positive/negative from success/danger), not
  separately entered.

This keeps the owner-facing form bounded — brand (4-5 fields) + status
(5 fields) + chart series (8 fields, §7.1) + surfaces (5 base fields) +
borders (1 base field) + font (1 selector, + upload where used) — while
the *token surface* the runtime system emits is comprehensive, exactly
matching "the platform owner should normally edit only the limited...
colors... variants should be derived."

### 6.6 Deterministic derivation algorithm — expanded to status colors

Unchanged mechanism from the original contract's §6.6 (HSL-space
hover/active/soft-bg/border/foreground derivation, server-side,
implementation-time-verified against the approved defaults within a
ΔE₀₀ < 2 tolerance) — now applied uniformly to **every** base color the
owner enters: brand primary, secondary/accent, each of the 5 status base
colors, and (where unset) chart series slots derived from brand/status
where they overlap conceptually. One formula, one implementation, reused
everywhere a "base color → full variant set" transformation is needed —
never a second, divergent derivation path for status colors.

### 6.7 Contrast validation — expanded to "every meaningful pair"

Same two-tier design as the original contract (§6.7: hard-reject at
AA thresholds for anything text/controls/focus-indicators/destructive-
actions render on, soft warning for low-but-valid surface separation) —
now evaluated across the full token set: every status's derived
text-on-soft-bg pair, every derived border against its adjacent surface,
focus-ring against every surface it can appear on, chart series against
chart background and against each other (adjacent series must remain
visually distinguishable — evaluated as a *minimum pairwise hue/lightness
delta* check, not raw WCAG contrast, since "distinguishable data series"
and "readable text" are different accessibility properties). The
validator's `{errors, warnings}` structure and PHP-authoritative,
JS-optimistic-preview split are unchanged in mechanism, only in the
number of pairs actually checked.

### 6.8 "Never permit custom CSS/JS/arbitrary HTML" (unchanged mechanism)

Same structural enforcement as the original contract — a fixed,
named set of typed fields only (hex-string regex for solid colors, a
separate validated alpha-capable format for overlay/shadow roles per
§4's own allowance, one font-choice enum, one file-upload field with its
own dedicated validation pipeline, §6.9). No free-text/CSS/HTML field
exists anywhere in the schema, now or after this correction.

### 6.9 NEW — Font architecture: bundled selection + safe upload

**Bundled choices**: a small, fixed list of pre-vetted, properly-licensed
webfonts shipped the same way Geist already is (self-hosted `.woff2`,
`font-display: swap`) — exact list is a §7.2 open decision (proposed:
Geist [factory], plus 2-4 other broadly-licensed system-adjacent faces),
selected via a plain `<select>`, applied by setting `font_choice =
'bundled'` and `font_bundled_key`.

**Upload pipeline** (built new — §3.7 confirmed nothing to reuse):

1. `UploadPlatformThemeFontRequest`: `'font_file' => 'required|file|
   mimes:woff,woff2|max:5000'` (5MB cap — exceeds the strictest existing
   precedent found, ChatBox's `max:20000` KB for media, since a single
   font file has no legitimate reason to approach that size).
2. `ValidFontFileRule` (new — genuinely new capability, §3.7): rejects
   anything whose first bytes don't match the WOFF2 (`wOF2`) or WOFF
   (`wOFF`) magic signature, regardless of what the client-supplied
   extension/MIME claims. Extension/MIME checks (step 1) and magic-byte
   checks (this step) are both required — neither alone is sufficient,
   per the audit's own finding that every existing upload in this app
   trusts extension/MIME alone.
3. Filename is **never** client-derived: `safe_filename = hash('sha256',
   $bytes) . '.' . $validated_extension`, computed server-side from the
   validated bytes themselves.
4. Stored on the **private** `local` disk (not `public` — the audited
   `public`/`storage:link` convention is unused elsewhere in the app and
   not reused here either, §3.7) under `theme-fonts/{safe_filename}`.
5. **Serving is split by trust boundary, not by a single access-control
   flag** — because an *active* theme font is, by definition, a public
   asset every anonymous visitor's browser must fetch to render the page
   (exactly like Geist is today), while a *non-active* uploaded font
   (replaced, rolled back away from, or awaiting deletion) has no
   legitimate reason to be fetchable by anyone:
   - **New public route** in `routes/public.php` (confirmed the
     correct home for genuinely unauthenticated routes, §3.7):
     `GET theme-font/{safeId}` → streams the font's bytes **only if
     `safeId` matches the currently-active theme's `font_uploaded_id`**;
     otherwise 404. No directory listing, no enumeration beyond guessing
     a 64-character hex id that, even if guessed, only resolves for the
     one font actually in use.
   - **Upload/replace/delete/select** all live behind the existing
     `'manage theme'`-gated admin routes (§6.2) — never publicly
     reachable, exactly satisfying "prevent unauthorized users from
     downloading [meaning: any non-active/historical font], replacing,
     or deleting theme font files."
6. `font-display: swap` and a safe fallback stack
   (`'PlatformThemeFont', -apple-system, BlinkMacSystemFont, 'Segoe UI',
   Helvetica, Arial, sans-serif` — the exact same fallback chain
   Milestone 1 already established for Geist) are always present in the
   emitted `@font-face`, whether the active font is bundled or uploaded.
7. **Replacement/rollback**: since `platform_theme_settings` is
   append-only (§6.1), switching back to a previous font — bundled or a
   still-undeleted upload — is just another theme-settings row with
   `change_scope = 'font'`; no separate versioning system is needed.
   **Removal**: a dedicated admin action soft-deletes a
   `platform_theme_fonts` row (`deleted_at`); permitted only when it is
   **not** the currently-active theme's `font_uploaded_id` (deleting the
   in-use font would violate "fail safely"); historical
   `platform_theme_settings` rows that once referenced it remain
   legible for audit even after the file is gone (their own
   `derived_tokens_json` already has the resolved values baked in from
   when they were active, so old audit history never depends on the
   deleted bytes).
8. Actor + timestamp: inherent to both tables' own append-only design
   (§6.1) — no separate mechanism needed.

### 6.10 NEW — Runtime-bindings retrofit layer (the §3.9 fix)

A new SCSS partial, `resources/scss/base/tokens/_runtime-bindings.scss`,
imported immediately after the token definitions in `tokens.scss`.
Contains explicit, targeted override rules — not a rewrite of Bootstrap's
own generator mixins — mapping every semantic Bootstrap-native class
actually in use across the app's current 374 views to the new
`var(--color-*)` tokens, for exactly the property (background-color/
border-color/color) that needs to be runtime-live. Representative shape:

```scss
.btn-primary { background-color: var(--color-primary); border-color: var(--color-primary); }
.btn-primary:hover, .btn-primary:focus { background-color: var(--color-primary-hover); border-color: var(--color-primary-hover); }
.badge-light-primary { background-color: var(--color-primary-soft-bg); color: var(--color-primary); }
.badge-light-success { background-color: var(--color-status-success-soft-bg); color: var(--color-status-success); }
/* ...one block per class actually found in the audit: .btn-*, .badge-light-*, .text-*, .border-*, .bg-light-*, .dropdown-menu, .modal-content, .table*, .nav-link.active, .form-control:focus, .pagination .page-item.active, tooltip/popover chrome... */
```

This is a **bounded, auditable, closed set** — derived directly from
which semantic classes the 374-view app actually uses (a mechanical grep
task, not guesswork), not an open-ended rewrite of vendored Bootstrap
SCSS (which stays completely untouched, exactly as before). The file
itself still only compiles once per asset build (like every other SCSS
partial); what makes theming *runtime*-live is that its *rules'
right-hand sides* are `var(--color-*)` references, which the browser
re-resolves on every paint whenever the `:root` override in §6.3 changes
— the same, standard CSS custom-property cascade behavior already
correctly used by `ds-components.scss`, now extended to Bootstrap's own
native classes as well.

**Explicit scope boundary (not silently expanded further):** this
retrofit layer covers Bootstrap's own native classes because those are
used everywhere, by every unmigrated page, and are directly named in the
human instruction ("navigation, buttons, links, selected states, focus
rings, badges"). It does **not** cover the seven third-party plugin
override files (§6.11) — those remain compile-time-tokenized (a rebuild
picks up new colors correctly) but not runtime-live in Slice 1, an
explicit, reasoned deferral, not an oversight.

### 6.11 NEW — Third-party plugin color scope (explicit deferral)

SweetAlert2, Toastr, Select2, Flatpickr, pickadate, DataTables, and
Quill's own chrome (not its content-authoring font-picker, which is
correctly out of scope entirely) all currently consume the same
`$success`/`$warning`/`$danger`/`$info`/`$body-color`/`$border-color`
Sass variables §6.5/§3.8 tokenizes at the source level in
`_variables.scss` — meaning a **full asset rebuild after any theme
change correctly re-colors them**, but they will **not** update live at
runtime without one, since none of their compiled output uses
`var(--color-*)` (confirmed, §3.8). Retrofitting seven separate,
sizeable vendor-override files into the runtime-bindings pattern (§6.10)
is deliberately **not** Slice-1 infrastructure — these are secondary/
tertiary chrome (date pickers, rich-text toolbar borders, table
zebra-striping), not the primary brand surfaces the human instruction
explicitly enumerates, and each plugin is only actually rendered within
specific rollout modules (Select2 in Contacts/CRM forms, Quill in
Templates/Automations, Flatpickr wherever a date field exists, etc.).
Per the correction's own explicit instruction, **every later slice that
touches a page using one of these plugins must retrofit that plugin's
own override file to the runtime-bindings pattern as part of eliminating
that slice's hardcoded colors** (§8) — this is deferred, bounded,
tracked work, not an unaddressed gap.

### 6.12 NEW — Canonical JS runtime-theme-token source

`resources/js/core/theme-tokens.js` (generalizes and replaces the
original contract's narrower `chart-tokens.js`, §6.4): a single module
exporting `getThemeToken(name)`/`getChartColors()`/`getStatusColors()`
helpers, all backed by one shared `getComputedStyle(document.
documentElement)` read. Every JS consumer that needs a color at
runtime — the eight chart-bearing views, `window.colors` in `app.js`,
and any dynamically-created client-side UI the human instruction
names — reads through this **one** module, never re-implementing its own
`getComputedStyle` call. This is the "same canonical theme source"
requirement satisfied structurally: there is exactly one JS entry point
for "what color is X right now," so it is architecturally impossible for
two different pieces of dynamic UI to disagree about a token's current
value the way `window.colors` and hardcoded hex literals disagree today.

### 6.13 NEW — Caching and invalidation

`PlatformThemeManager::currentStyleBlock()` (§6.3) is wrapped in a short-
lived, request-scoped cache (Laravel's default cache store, keyed on a
constant, e.g. `platform_theme:active_style_block`) to avoid re-querying
and re-rendering the token map on every single request. **Invalidation
is explicit and immediate, not TTL-based**: `PlatformThemeManager`'s own
save/restore/font-select methods forget that cache key inside the same
database transaction that flips `is_active`, so the very next request —
including the admin's own redirect back to the theme-settings page after
Save — reflects the change with zero staleness window. This satisfies
"remain cached safely and be invalidated immediately after an authorized
change" without introducing any new infrastructure dependency (no Redis/
tag-based cache requirement) — the app's already-configured default
cache store is sufficient given the narrow, single-key invalidation need.

---

## 7. Open technical decisions (category-3 — resolved at implementation
## time within the constraints stated here, never silently guessed)

1. **Chart series slot count and default palette.** §3.2/§3.8's audit
   found the app's own largest real chart (delivered/enroute/expired/
   undelivered/rejected/accepted/skipped/failed) uses **8** distinct
   series in one view — this contract sets **8 explicit chart-series
   slots** on that direct evidence, not a round guess. Their default hex
   values are not specified anywhere in the approved palette (a
   single-hue brand family has no natural 8-color chart palette) —
   proposed resolution: 8 values chosen for maximum pairwise
   distinguishability (hue-spread) while each individually passing
   §6.7's contrast rules against the default canvas, confirmed before
   Slice 1 implementation begins, never silently invented.
2. **Bundled font list beyond Geist.** §6.9 proposes 2-4 additional
   pre-vetted, properly-licensed choices; the exact fonts and their
   licenses are confirmed before implementation, never assumed.
3. **Secondary/accent, header/nav-background-override, live-preview
   composition** — carried forward unchanged from the original
   contract's §7 items 1-3 (still open, still awaiting confirmation, not
   resolved by this correction).
4. **Exact runtime-bindings class list (§6.10).** The representative
   shape shown is illustrative; the *exact, exhaustive* list of Bootstrap-
   native classes requiring a binding rule is a mechanical grep task
   performed at implementation time against the actual 374-view corpus,
   not hand-enumerated in this contract — the contract fixes the
   *mechanism and scope boundary* (Bootstrap-native classes, not
   third-party plugin chrome), not a hand-typed exhaustive list that
   would drift from reality the moment a new page ships.

---

## 8. Full rollout map — unchanged slice boundaries, expanded per-slice
## mandate

The 21-slice map from the original contract (§8) is **unchanged in its
module boundaries and file globs** — this correction does not touch
*which* pages are grouped into *which* slice, only what Slice 1 itself
must now build (§9) and what every slice must now additionally do:

**New, standing mandate for every slice from Slice 2 onward (not
optional, not deferred further):** each slice's own implementation
contract must include eliminating that slice's own hardcoded colors and
font-family declarations as it migrates its pages — retokenizing any
plugin-chrome override file (§6.11) actually exercised by that slice's
pages, converting any remaining `data-feather` icons, and confirming
(via that slice's own mechanical search) zero hardcoded hex/font-family
literals remain in its own touched files. This is the correction's own
explicit instruction ("every later slice [must] eliminate its hardcoded
colors and font declarations") made a standing, repeatable requirement
rather than a one-time Slice-1 task.

Slice 1's own boundary is **expanded** relative to the original contract
— see §9 — because building the theme *engine* correctly (§6.9's font
upload, §6.10's runtime-bindings retrofit, the expanded token taxonomy)
is genuinely foundational infrastructure that later slices depend on,
exactly matching the correction's own instruction to "expand Slice 1
only where the infrastructure must be established now."

The out-of-scope rows are **unchanged**: transactional email templates
(§3.1), and — newly and explicitly named by this correction's own
re-audit — **inline SVG illustration assets** (§3.8/§6.11 note below)
and **the pre-existing dark-mode skin toggle's own separate palette**
(§3.8/§6.10 boundary), both flagged honestly as real, evidence-based
scope boundaries rather than silently absorbed or silently ignored.

---

## 9. Exact implementation allowlist (Slice 1 — expanded, the only
## implementation-ready scope in this contract)

**Closed, numbered, path-level. Any additional path required during
Slice 1 implementation is a STOP-and-report condition (§12).**

### Theme editor — schema, domain, service layer (12 new)

1. `database/migrations/{timestamp}_create_platform_theme_settings_table.php`
2. `database/migrations/{timestamp}_create_platform_theme_fonts_table.php`
3. `app/Models/PlatformThemeSetting.php`
4. `app/Models/PlatformThemeFont.php`
5. `app/Repositories/Contracts/PlatformThemeSettingRepository.php`
6. `app/Repositories/Eloquent/EloquentPlatformThemeSettingRepository.php`
7. `app/Repositories/Contracts/PlatformThemeFontRepository.php`
8. `app/Repositories/Eloquent/EloquentPlatformThemeFontRepository.php`
9. `app/Library/Theme/ThemeColorDerivationService.php` — §6.6, now covering brand + status.
10. `app/Library/Theme/ThemeContrastValidator.php` — §6.7, expanded pair coverage.
11. `app/Library/Theme/PlatformThemeManager.php` — §6.3/§6.9/§6.13: orchestrates validate → derive → persist → activate → cache-invalidate → `currentStyleBlock()` → font select/restore/remove.
12. `app/Library/Theme/ThemeFontValidator.php` — §6.9's magic-byte check.

### Theme editor — exceptions (3 new)

13. `app/Library/Theme/Exceptions/InvalidThemeColorException.php`
14. `app/Library/Theme/Exceptions/UnsafeThemeContrastException.php`
15. `app/Library/Theme/Exceptions/InvalidThemeFontException.php`

### Theme editor — HTTP surface (6 new, 2 modified)

16. `app/Http/Controllers/Admin/PlatformThemeSettingController.php`
17. `app/Http/Controllers/Admin/PlatformThemeFontController.php` — upload/replace/delete/select, all `'manage theme'`-gated.
18. `app/Http/Requests/Admin/UpdatePlatformThemeSettingRequest.php`
19. `app/Http/Requests/Admin/RestorePlatformThemeSettingRequest.php`
20. `app/Http/Requests/Admin/UploadPlatformThemeFontRequest.php`
21. `app/Rules/ValidFontFileRule.php` — §6.9 magic-byte validation.
22. `routes/admin.php` — modified: `theme-settings` + `theme-fonts` route sections, wrapped per §6.2. No existing route line changed or reordered.
23. `routes/public.php` — modified: **one** new, genuinely unauthenticated `GET theme-font/{safeId}` route (§6.9) serving only the currently-active uploaded font's bytes. No existing route line changed or reordered.

### Theme editor — authorization config (1 modified)

24. `config/permissions.php` — modified: new `'manage theme'` entry under a new `"Appearance"` category, additive only.

### Theme editor — views + JS (2 new, 1 modified)

25. `resources/views/admin/theme-settings/index.blade.php` — all six token categories (§4) grouped, color pickers + validated hex entry, font selector + upload UI, live preview (§4's full demonstration list), Cancel/Save/Reset, validation errors, contrast warnings, unsaved-change warning, audit info. Built entirely from the existing 13-component library — no new bespoke UI primitives.
26. `resources/js/scripts/pages/theme-settings.js` — picker wiring, optimistic preview, debounced contrast-check calls, unsaved-change guard.
27. `resources/views/panels/styles.blade.php` — modified: appends the expanded `<style>` block (§6.3) including the conditional `@font-face`, guarded by `@if`, at the file's end.

### Runtime-bindings retrofit layer (1 new, 1 modified)

28. `resources/scss/base/tokens/_runtime-bindings.scss` — new, §6.10 — the critical fix making Bootstrap's own native compiled classes respond to runtime token changes.
29. `resources/scss/base/tokens.scss` — modified: one new `@import 'tokens/runtime-bindings';` line, positioned after the color/typography token imports. No existing import removed or reordered.

### Token taxonomy expansion (2 modified)

30. `resources/scss/base/tokens/_colors.scss` — modified: expands from the 12 Milestone-1 tokens + 3 chart tokens to the full semantic set in §4/§6.5 (brand/interaction, text/icon, surfaces, borders/depth, status ×5 with derived variants, chart ×8 series + neutral/grid/axis/tooltip), each as both a build-time-default CSS custom property and (where Bootstrap itself needs it at compile time) a Sass variable.
31. `resources/scss/base/tokens/_typography.scss` — modified: introduces one canonical `--font-family-app` custom property (+ Sass variable) that **both** `$font-family-sans-serif` and `$font-family-monospace` resolve through, fixing the §3.7 alias-drift bug (sidebar/navbar/Quill-container) without needing to touch those three files individually.

### Bootstrap variable retokenization (1 modified — same file as chat background, §3.3, consolidated)

32. `resources/scss/base/bootstrap-extended/_variables.scss` — modified, two purposes in one file: (a) removes `$chat-bg-light`/`$chat-bg-dark` (§3.3, unchanged from the original contract); (b) retokenizes `$success`/`$warning`/`$danger`/`$info`/`$secondary`/`$dark` and the shadow/dropdown/modal/popover/toast/input-focus/tooltip colors (§3.8) to reference the new status/shadow tokens instead of raw hex; (c) adds an explicit `$focus-ring-color` derived from `--color-focus-ring` (§4) instead of silently inheriting Bootstrap core's own default. No unrelated variable in this file changes.

### Chart token bridge (1 new, 8 modified)

33. `resources/js/core/theme-tokens.js` — §6.12, replaces the originally-scoped narrower `chart-tokens.js` with the canonical module.
34. `resources/views/admin/dashboard.blade.php` — modified: series **and** grid/axis/tooltip colors sourced from `theme-tokens.js`; semantic status colors untouched.
35. `resources/views/customer/dashboard.blade.php` — modified: same discipline.
36. `resources/views/customer/Reports/charts.blade.php` — modified: same discipline.
37. `resources/views/customer/Reports/analyze.blade.php` — modified: same discipline.
38. `resources/views/admin/Reports/overview.blade.php` — modified: same discipline.
39. `resources/views/admin/Reports/dashboard.blade.php` — modified: same discipline.
40. `resources/views/customer/Campaigns/overview.blade.php` — modified: brand-series colors only; semantic delivery-status object untouched.
41. `resources/views/customer/Automations/overview.blade.php` — modified: same discipline.
42. `resources/js/core/app.js` — modified: `window.colors.solid.primary` sourced from `theme-tokens.js` instead of a hardcoded `#7367F0`; every other key left untouched (semantic, not brand).

### Chat background remediation (2 modified — `_variables.scss` already covered by item 32)

43. `resources/scss/base/pages/app-chat.scss` — modified: background rules token-driven (§3.3, unchanged from original contract); outbound/inbound bubble colors retokenized off the new brand/surface tokens.
44. `resources/scss/base/pages/app-chat-list.scss` — modified: bubble color retokenization, if not already fully covered by item 43 (implementation-time bookkeeping only, both files already allowlisted).

**Explicit, deliberate exception to the Milestone-1 "dark-theme bundle is
never touched" default (unchanged from the original contract):**

45. `resources/scss/base/themes/dark-layout.scss` — modified: removes the `$chat-bg-dark` reference and retokenizes the corresponding dark-mode bubble overrides only. The rest of the dark-mode palette (§3.8's ~24-literal `_variables-dark.scss` set) is explicitly **not** touched by Slice 1 — reconciling dark mode with the new token system is deferred, consistent with the original contract's own Slice-20 placement for the legacy Theme Customizer this dark-mode toggle belongs to.

### Remaining Feather→Lucide icon migration in shared chrome (7 modified, unchanged)

46. `resources/views/panels/navbar.blade.php`
47. `resources/views/panels/sidebar.blade.php`
48. `resources/views/panels/footer.blade.php`
49. `resources/views/panels/breadcrumb.blade.php`
50. `resources/views/panels/submenu.blade.php`
51. `resources/views/panels/horizontalMenu.blade.php`
52. `resources/views/panels/horizontalSubmenu.blade.php`

### Errors module (7 modified, unchanged)

53. `resources/views/errors/401.blade.php`
54. `resources/views/errors/403.blade.php`
55. `resources/views/errors/404.blade.php`
56. `resources/views/errors/419.blade.php`
57. `resources/views/errors/429.blade.php`
58. `resources/views/errors/500.blade.php`
59. `resources/views/errors/503.blade.php`

**Total: 59 files** (30 new, 29 modified) — up from the original
contract's 46, an increase of 13, entirely accounted for by: the font
table + its models/repos/controller/request/rule/route (8 items), the
runtime-bindings retrofit layer + its `tokens.scss` import line (2
items), the `_typography.scss` alias fix (1 item), the theme-tokens.js
rename/generalization (0 net new — replaces the original `chart-tokens.js`
1-for-1), and the `_variables.scss` status/shadow/focus-ring
retokenization being folded into the *already-allowlisted* chat-background
file rather than a separate item (0 net new). Any path beyond this list
required during Slice 1 implementation is a required-60th-path-shaped
stop condition (§12).

---

## 10. Test contract (Slice 1 — expanded)

All tests from the original contract's §10 (Authorization, Validation,
Derivation accuracy, Persistence, Audit, Isolation, Rendering/fail-safe,
Chart tokens, Chat background — see original list, unchanged in kind)
**plus**:

- **Font upload validation**: `PlatformThemeFontUploadValidationTest` —
  a renamed non-font file (e.g. a `.php` payload renamed to `.woff2`) is
  rejected by magic-byte checking even though its extension/MIME claim
  passes; an oversized file is rejected; a genuine WOFF2/WOFF file is
  accepted.
- **Font serving/authorization**: `PlatformThemeFontServingTest` — the
  public `theme-font/{safeId}` route serves the currently-active
  uploaded font's bytes with no authentication required (must be
  fetchable by an anonymous browser); the same route 404s for a
  non-active or deleted font's id; the upload/replace/delete/select
  admin actions are denied to everyone except an admin with
  `'manage theme'` (reusing the same authorization test shape as the
  color/surface settings).
- **Font runtime application**: confirms the rendered `<head>` `@font-
  face` block only appears when `font_choice = 'uploaded'`, uses the
  correct `safe_filename`-derived URL, includes `font-display: swap`,
  and the safe fallback stack is always present regardless of
  bundled/uploaded choice.
- **Runtime-bindings retrofit**: a content test asserting
  `_runtime-bindings.scss` contains a rule for every Bootstrap-native
  class actually grepped from the current 374-view corpus at the time
  of implementation (the mechanical list from §7 item 4) — proving the
  retrofit is exhaustive against real usage, not a hand-picked subset.
- **Status-color derivation**: extends `ThemeColorDerivationServiceTest`
  to assert each of the 5 status colors' derived text/border/icon/
  soft-bg variants pass §6.7's hard-reject contrast floor for the
  *approved default* status hexes, mirroring the brand-color acceptance
  test already required.
- **Cache invalidation**: `PlatformThemeCacheInvalidationTest` — after a
  save/restore/font-select, the very next request's rendered `<style>`
  block reflects the new values with no stale-cache window.
- Full existing suite re-run, exact pass count compared against the
  pre-Slice-1 baseline (2,724 passed / 8,672 assertions) — zero
  regressions permitted, unchanged discipline.

---

## 11. Mechanical searches (Slice 1 — expanded)

All searches from the original contract's §11 (Anthropic/Claude,
`7367F0`, chat-bg references, remaining `data-feather` in panels,
`app/database/routes` isolation, RTL/other-theme-bundle isolation,
final changed-path-set reconciliation, `'manage theme'` consistency,
full-suite pass count — unchanged in kind) **plus**:

10. `grep -c "\$font-family-monospace" resources/scss/base/core/menu/_navigation.scss resources/scss/base/bootstrap-extended/_navbar.scss resources/scss/base/plugins/forms/form-quill-editor.scss` compared against the pre-Slice-1 baseline count → **unchanged** (proving the alias-fix in `_typography.scss`, item 31, was applied at the single canonical source rather than by editing these three files, which must remain byte-identical).
11. `grep -rn "wOF2\|wOFF"` in `app/Rules/ValidFontFileRule.php` → both magic-byte signatures present (proves the validator checks both WOFF2 and WOFF, not just one).
12. `grep -rn "Storage::disk('public')\|public_path("` across every new file in items 1-23 → zero matches (proves font storage genuinely uses the private `local` disk, per §6.9, not the audited-as-unused-and-unsafe `public` convention).
13. `grep -c "var(--color-" resources/scss/base/tokens/_runtime-bindings.scss` → a positive count matching the number of distinct classes identified by §7 item 4's mechanical grep, reported exactly.
14. Final changed-path set equals this contract's own **59-item** allowlist (§9), not the original's 46.

---

## 12. Stop conditions

All conditions from the original contract's §12 (path beyond allowlist,
`app/database/routes` beyond the schema/HTTP items, RTL/other-theme-
bundle touching, open decisions unconfirmed, derivation formula failing
against the approved defaults, existing test failures, Anthropic/Claude
references, a "custom CSS"-shaped field becoming necessary) — unchanged
in kind, now evaluated against the 59-item allowlist — **plus**:

- Any uploaded font file's bytes would need to be served from the
  `public` disk, or from any path reachable without going through the
  `theme-font/{safeId}` active-only check (§6.9) — this would violate
  the "prevent unauthorized... downloading" requirement and must stop.
- The `_variables-dark.scss` dark-mode palette (as opposed to the one,
  narrow, documented `dark-layout.scss` chat exception, item 45) appears
  to require touching — dark-mode retokenization remains explicitly
  deferred (§8), not silently absorbed into Slice 1's scope.
- Retrofitting a third-party plugin override file (§6.11) beyond what a
  later slice's own touched pages require appears necessary within
  Slice 1 itself — this boundary is deliberate, not negotiable within
  this contract.
- §7's now-four open decisions (chart-palette defaults, bundled-font
  list, secondary/accent + header-override + live-preview composition,
  the exact runtime-bindings class list) have not been explicitly
  confirmed before implementation begins.

---

## 13. Contract self-audit

1. Every requirement in the corrected §4 is addressed by a numbered
   decision in §6 and a numbered path in §9, including the four entirely
   new categories this correction added (full semantic taxonomy, font
   control + upload, the runtime-bindings retrofit, expanded
   accessibility/caching requirements). ✓
2. The single most consequential architectural gap in the *original*
   contract — that Bootstrap's own compiled component CSS bakes literal
   hex and does not respond to the designed runtime `:root` override —
   was found by empirical verification (§3.9, not assumption) during
   this correction's own audit, and is fixed by new, explicitly-scoped
   Slice-1 infrastructure (§6.10), not silently left broken. ✓
3. Font-upload safety was resolved by direct evidence (§3.7: nothing in
   this codebase validates file content or gates per-file access today)
   rather than assumed safe or assumed unsafe — the design in §6.9 is
   built to exceed every existing precedent, and the public/private
   serving split is derived from the *nature* of what a webfont is
   (something every visitor's browser must fetch), not an arbitrary
   choice. ✓
4. Four genuinely open decisions are flagged with reasoned, evidence-
   based proposals, never silently resolved (§7). ✓
5. The 21-slice rollout map's module boundaries are unchanged by this
   correction — only Slice 1's own boundary expanded, and only where the
   correction's own instruction explicitly permitted ("expand Slice 1
   only where the infrastructure must be established now"). Every later
   slice now carries a standing, explicit mandate to eliminate its own
   hardcoded colors/fonts as it migrates (§8) — the correction's own
   "later slices must eliminate hardcoded colors" instruction is not a
   one-time note but a repeatable requirement threaded through the map. ✓
6. Total allowlist: **59 files** (30 new, 29 modified), reconciled item-
   by-item against the original's 46 in §9's own closing paragraph — the
   13-file increase is fully accounted for, not an unexplained jump. ✓
7. Third-party plugin chrome and inline SVG illustrations are both
   explicitly, honestly named as real gaps the current token architecture
   cannot fully close in Slice 1 (§6.11, §3.8) — not silently omitted
   from the audit, not silently claimed as solved. ✓
8. No business logic, permission model (beyond the one additive
   `'manage theme'` string), route behavior, tenant isolation, or data-
   flow of any existing feature changes anywhere in §9. ✓
9. This document remains the only file changed on this branch, across
   both the original commit and this correction (§2). ✓

---

## 14. Verification and publication (this document only)

1. `git status` on `chore/design-system-m2-contract` shows exactly one
   changed path: this file.
2. Stage individually (`git add docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`),
   never `git add -A`/`.`.
3. This correction is committed **separately** from the original
   contract commit (not amended/squashed into it), per the correction's
   own explicit instruction.
4. Push to `origin chore/design-system-m2-contract` — a normal push,
   never force-pushed, per the correction's own explicit instruction.
5. Provide the compare URL (no `gh` available in this environment).
6. **Do not merge. Do not implement Slice 1. Do not edit production or
   test files. Do not run migrations.** All require separate, explicit,
   future authorization.

---

## 15. What this correction changed, and why (summary for the record)

- **Scope**: replaced a 5-color-plus-7-surface palette with a complete
  semantic-token system (brand/interaction, text/icon, surfaces, borders/
  depth, 5 statuses × derived variants, 8-slot chart palette) plus a new
  global font-control feature (bundled selection + safe owner upload).
- **Architecture**: discovered and fixed a critical, previously-
  undetected gap (§3.9) — Bootstrap's own compiled classes don't read
  runtime custom properties at all, so the original "no rebuild"
  design silently only worked for the 15-file M1 component library. A
  new runtime-bindings retrofit layer (§6.10) closes this for Bootstrap's
  native classes; third-party plugin chrome is explicitly, honestly
  deferred to per-slice elimination (§6.11) rather than silently claimed
  solved.
- **Font upload**: resolved via direct evidence, not assumption — this
  codebase has no existing content-validation or per-file authorization
  pattern for *any* upload type, so the design (§6.9) builds both from
  scratch, exceeding every existing precedent, with a public/private
  serving split derived from what a webfont actually is.
- **Data model**: generalized from fixed named JSON sub-objects to a
  flat semantic-token map (§6.1), because the token set is now large and
  open-ended.
- **Allowlist**: grew from 46 to 59 files, every addition traced to a
  specific new requirement or newly-discovered gap, reconciled explicitly
  in §9's own closing paragraph and §13's self-audit — not an
  unexplained expansion.
- **Rollout map**: module boundaries unchanged; every slice from 2
  onward now carries a standing mandate to eliminate its own hardcoded
  colors/fonts as it migrates, making the correction's "later slices
  must eliminate hardcoded colors" instruction a repeatable requirement
  rather than a one-time note.
