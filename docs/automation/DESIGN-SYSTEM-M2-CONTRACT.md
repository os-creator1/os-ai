# Design System — Milestone 2 Contract

Full application rollout, platform-owner complete theme control (named
presets, colors + font), chart theming, and chat background remediation.

**This document is fully self-contained.** It supersedes and replaces
every prior version in git history (the original draft at `d077ccc`,
Correction Round 1 at `c50aa6d`, and Correction Round 2 at `73f74b5`,
merged to `main` at `2b2e53f`). No section below depends on checking out
an earlier commit to understand the complete requirements — a defect the
merged `73f74b5` version had (several sections read "unchanged, see
Correction Round 1" instead of containing the actual text) and which this
repair corrects, per its own explicit "make the contract fully
self-contained" requirement.

**Status: contract only. No implementation has ever occurred under this
document, across any drafting pass. Merging this repair does NOT
authorize any implementation — each rollout slice (§9) requires its own
separate, explicit authorization, exactly like every prior contract in
this repository.**

---

## 0. Governance

- This document is drafted on branch
  `chore/design-system-m2-contract-verification-fix`, in an isolated
  worktree, based on `main` at `2b2e53f` (the merge of PR #85, which
  carried `73f74b5` — Correction Round 2 — into `main`).
- This is a **docs-only publication repair completing Correction Round
  2**, not a third correction round: the merged `73f74b5` content itself
  contained real defects (an allowlist item silently dropped during
  reorganization, a numbering gap left by an incompletely-executed
  removal, and several sections compressed into cross-references to an
  earlier commit instead of standing complete on their own) rather than
  representing a deliberate, considered design this repair is now
  reopening. Nothing about the *architecture* Correction Round 2 actually
  decided — presets, their lifecycle, font-reference guarding, starter
  presets — is being redesigned from scratch here; it is being restated
  completely and correctly, with the specific mechanical and structural
  defects fixed and the architecture strengthened exactly where this
  repair's own instructions identify a concrete gap (locking, cache-
  invalidation timing, edit-in-place protection, Simple/Advanced token
  control).
- Drafting this repair makes **zero** application changes. No
  `resources/`, `app/`, `database/`, or `routes/` file is touched by this
  branch — only this document.
- Once merged, implementation of **Slice 1 only** (§9) may begin under a
  separate, explicit authorization. Slices 2 onward (§8) remain a locked
  rollout *map*, not yet allowlisted.
- `maximum_correction_rounds: 2` applies to this contract, unchanged.
  This repair does not consume a third round — it completes the second.
- Any path required during Slice 1 implementation but absent from §9's
  own numbered allowlist is a stop-and-report condition — not a silent
  workaround. The stop threshold is the **68th** path (67 allowlisted
  + 1).

---

## 1. Mandatory preflight — verified

- Read `CLAUDE.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md`
  (Milestone 1) and its merged implementation.
- Confirmed Milestone 1 is merged to `main` (`fb5c823`).
- Confirmed PR #85 is real and its merge commit is `2b2e53f`
  (`git cat-file -t 2b2e53f`, `git log --oneline`).
- **Confirmed the merge itself introduced no corruption**: `git diff
  73f74b5 origin/main -- docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`
  is empty, and both revisions are 904 lines — the merged content is
  byte-identical to what Correction Round 2 pushed. Every defect
  repaired here therefore originates in the Correction Round 2 drafting
  itself, not in the merge or publication process, and is corrected
  accordingly rather than attributed to a merge fault that did not occur.
- **Mechanically confirmed both specific defects** before writing a
  single word of repair:
  - `grep -n "panels/styles.blade.php"` against the merged file's §9
    returns zero numbered-allowlist matches — it appears only in §3.6's
    prose narrative. It was allowlisted in Correction Round 1 (as the
    runtime-injection point every implementation must modify) and was
    silently dropped when §9 was reorganized for presets.
  - The merged file's §9 numbering runs `1`...`26`, then `28`...`67` —
    item `27` was written as a struck-through, prose-only "removed" note
    (`~~app/Http/Requests/Admin/RestorePlatformThemeSettingRequest.php~~`)
    that does not match the numbered-path pattern every other item
    follows, leaving only **66** real, addressable paths despite the
    document's own claimed total of 67.
- Both defects are corrected together in §9 below: restoring
  `panels/styles.blade.php` as a genuine, real item at position 27
  produces an exact, unique, sequential 1-67 list with no dead slots —
  the same fix closes both findings at once.

---

## 2. This contract's own exact file scope

Only one path has ever changed across every commit on this contract's
lineage, including this repair:

1. `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` (this file)

---

## 3. Mandatory repository audit — findings

### 3.1 Remaining rollout surface

- **374** total Blade view files under `resources/views/` (machine-
  verified: per-directory counts 147/127/32/7/7/9/10/16/3/1/15 across
  customer/admin/auth/errors/layouts/panels/emails/vendor/Installer/
  plugins/components, summing to 374).
- **2** migrated to the Milestone-1 component library
  (`customer/business/usage-billing/show.blade.php`,
  `admin/usage-billing/provider-events/index.blade.php`).
- **357** remaining Blade views (374 − 15 component-library files − 2
  already migrated).
- **223 files / 796 occurrences** of `data-feather="..."` remain
  repository-wide (the two migrated pages are clean).
- **55 files** are 500+ lines; `admin/SendingServer/create.blade.php`
  (4,306 lines) and `customer/SendingServer/create.blade.php` (2,316
  lines) are extreme outliers, each its own dedicated future slice (§8).
- Complexity signals: 53 files use DataTables, 26 use Bootstrap modals,
  19 use tabs, 8 build charts, 33 handle file upload.
- `resources/views/emails/**` (10) and `resources/views/vendor/mail/**`
  (16) render through Laravel's markdown-mail system, outside the
  browser CSS/SCSS pipeline — **out of scope for the entire rollout**.

### 3.2 Chart color audit (series colors)

Four PHP call sites (`AdminBaseController`, `UserController`,
`Customer\ReportsController`, `Admin\ReportsController`) build
`ArielMejiaDev\LarapexCharts\LarapexChart` objects; none call
`->setColors()`. Series colors are hardcoded, ad hoc, and duplicated
across at least eight Blade `<script>` blocks (`admin/dashboard.blade.php`,
`customer/dashboard.blade.php`, `customer/Reports/{charts,analyze}.blade.php`,
`admin/Reports/{overview,dashboard}.blade.php`,
`customer/Campaigns/overview.blade.php`,
`customer/Automations/overview.blade.php`), with the legacy Vuexy purple
`#7367F0` repeated throughout. `resources/js/core/app.js:5-16`'s
`window.colors` is a static literal also carrying `#7367F0`. No
centralized chart-color config exists anywhere. Charts are entirely
disconnected from the Milestone-1 token system — compile-time SCSS
cannot reach request-time PHP or shipped JS without a new runtime bridge
(§6.4). The delivered/enroute/expired/undelivered/rejected/accepted/
skipped/failed status-color object is semantic, not brand, and stays out
of scope per the human instruction's own carve-out.

### 3.3 Chat background audit

The chat feature lives at `customer/ChatBox/{index,new,_sidebar}.blade.php`
and `customer/ChatBox/partials/_chat_list.blade.php`, routed through
`Customer\ChatBoxController` (`routes/customer.php:400-411`). The
repeating icon-pattern background is an inline base64-encoded SVG data
URI in two SCSS string variables, `$chat-bg-light`/`$chat-bg-dark`
(`bootstrap-extended/_variables.scss:664-665`), applied by
`app-chat.scss:206-217` and referenced again for dark mode at
`dark-layout.scss:1725`. Five on-disk `chat-bg*.{svg,png,jpg}` files are
confirmed dead assets, referenced nowhere. Outbound bubbles use a purple
gradient off `$primary-color` (`app-chat-list.scss:51-73`); inbound
bubbles use `lighten($white,18%)` (`:75-88`). There is no separate
Blade partial for bubbles — built via inline JS template-literal strings
in `ChatBox/index.blade.php`, in three places (history load, optimistic
send, Echo listener). Must-preserve elements: the composer textarea's
custom restyle, the attachment file input and image/video/audio
branching, the SMS-template picker, the send button, the `.chat-time`
timestamp on every bubble, the unread notification badge/counter, the
pin/block/delete controls and their SweetAlert2 confirms, the empty/
start-chat state, and — critically — the conditional guard around the
entire Echo/Pusher wiring (`config('broadcasting.connections.pusher.app_id')`),
which must never be assumed always-configured.

### 3.4 Platform configuration storage audit

`App\Models\AppConfig` (table `app_config`, singular): schema
`id, setting (text), value (text, nullable), timestamps` only, unchanged
since 2018. A flat row-per-global-setting key/value store with no actor,
no active/inactive concept, no structured-value support beyond one
existing precedent (`setting = 'tax'`, a hand-`json_encode`d blob with no
audit trail). Read/written from at least 24 files via two inconsistent
mechanisms — the DB table, and direct `.env` rewrites through
`AppConfig::setEnv()`. The *existing* `Admin\ThemeCustomizerController`
(navbar color/layout/skin) already bypasses the DB table entirely,
persisting to `.env` instead — confirming there is no reliable live
precedent for storing theme-like data in `app_config`. This codebase has
an established, idiomatic append-only pattern for "who changed this and
when" — `workspace_entitlement_transitions`, `business_feature_toggles`:
a plain scalar `*_user_id` column with **no foreign-key constraint** (by
deliberate design, so it can never block an unrelated user-deletion
feature), plus `created_at`. **Decision: a dedicated set of tables is
used — not `AppConfig`** (§6.1). `app_config`'s schema cannot carry
structured, audited, multi-row preset data without adding columns
meaningless to its ~65 other unrelated rows; the codebase's own existing
append-only idiom is reused instead of invented.

### 3.5 Platform-owner authorization audit

No `is_platform_owner` flag, `super-admin` role name, or dedicated
permission string exists anywhere distinguishing "the platform owner"
from "an admin staff member with broad permissions." The only sharper-
than-`is_admin` primitive in the codebase is
`EloquentAccountRepository::hasPermission()`'s unconditional
`users.id === 1` bypass — an ID-based special case, not a named concept.
The established, reusable pattern is "one settings page, one permission
string": `config/permissions.php` defines `'general settings'`, checked
by the existing `Admin\ThemeCustomizerController`/`ThemeCustomizerRequest`.
**Decision: a new `'manage theme'` permission** (§6.2), gated by (a) the
blanket `routes/admin.php` group's existing `auth` + `can:access
backend` + `twofactor` middleware, (b) `EnsureUserIsAdministrator::class`
(defense in depth, the same pattern already used for Business/
Opportunity/Workspace admin sub-groups), and (c) this new permission
string itself, checked independently by both the controller and its
FormRequests. This correctly satisfies "accessible only to the platform
administrator — not ordinary Workspace owners, members or customers"
(all structurally incapable of reaching any `routes/admin.php` route at
all) while staying consistent with the codebase's own existing
settings-permission convention. `'manage theme'` governs every preset
action too (create/edit/activate/rename/duplicate/delete) — confirmed
directly (§6.15), no second permission string is introduced.

### 3.6 Runtime CSS injection point audit

`resources/views/panels/styles.blade.php:12` is the single file that
emits `<link rel="stylesheet" href="{{ asset(mix('css/core.css')) }}"/>`
— the compiled bundle carrying Milestone 1's build-time
`:root { --color-*: ...; }` block. Nothing loaded after it in that same
file (`dark-layout.css`, `bordered-layout.css`, `semi-dark-layout.css`,
`overrides.css`, `style.css`, `custom-rtl.css`, `style-rtl.css`)
redefines any `--color-*` custom property — confirmed by grep; they only
*consume* `var(--color-...)`. `panels/styles.blade.php` is `@include`d,
inside `<head>`, by exactly three root layout files:
`contentLayoutMaster.blade.php:28`, `detachedLayoutMaster.blade.php:27`,
`fullLayoutMaster.blade.php:26`. **Decision: append a new, server-
rendered `<style>` block to the end of `panels/styles.blade.php`**,
populated from whichever preset currently has `status = 'active'`
(§6.3). This is the single minimal insertion point: textually after
`core.css` (wins on cascade order at equal specificity), included by all
three `<head>`-owning layouts without editing each one, and — because it
renders server-side before `</head>`, before `<body>` — there is no
flash of default theme and no separate async request. **This file is a
required, numbered allowlist item (§9 item 27)** — its earlier omission
from Correction Round 2's §9 was the exact defect this repair corrects
(§1).

### 3.7 Font-family and font-upload architecture audit

Beyond the two Milestone-1 tokenized variables (`$font-family-sans-serif`,
`$font-family-monospace`, both pointed at Geist), **four other locations
independently reference `$font-family-monospace`**:
`core/menu/_navigation.scss:137,143` (sidebar nav + nav header),
`bootstrap-extended/_navbar.scss:22` (top navbar), and
`plugins/forms/form-quill-editor.scss:95` (Quill's editor *container*
font). A font-swap that only rewrites `$font-family-sans-serif` would
silently miss the sidebar, navbar, and Quill editor container — fixed by
one canonical `--font-family-app` custom property (+ Sass variable) both
`$font-family-sans-serif` and `$font-family-monospace` resolve through
(§6.9), touching only `_typography.scss`, not the three independent call
sites.

KaTeX (Quill's math-formula toolbar extension, loaded via
`resources/views/plugins/editor.blade.php:3` on three admin settings
pages) is a separate, unrelated, bundled font system, structurally
incapable of being repointed at an arbitrary uploaded webfont —
explicitly out of scope. No active icon font exists anywhere (Feather is
confirmed inline-SVG-only via `feather.replace()`; the dormant Feather/
jQuery-contextMenu/Swiper icon-font files are unreferenced dead bytes).
Five of six audited third-party plugins (Select2, Flatpickr, DataTables,
SweetAlert2 mostly, ApexCharts) inherit or already track the app's own
font variable; only Quill's content-authoring font-picker (a deliberate,
out-of-scope content choice) hardcodes unrelated font names with no
backing files.

**File-upload/storage architecture** — the single most consequential
finding for font-upload safety: `config/filesystems.php`'s `public`
disk / `storage:link` convention is configured but architecturally
unused (exactly one `Storage::disk('public')` reference in `app/`,
inside a demo-data seeder, not any real feature). Every real upload
feature (logo/favicon, language-file zip, chat MMS attachments,
avatars) writes raw files directly to `public_path(...)` via bare PHP,
served as permanent, unauthenticated static assets, with content-hashed,
timestamp-based, or user-ID-based filenames — none of which are real
access control. **No file anywhere in this codebase validates actual
binary/magic-byte content** — `finfo`/`exif_imagetype` are used only as
outbound MIME-type labels for already-stored files, never as an
upload-time security gate; `Intervention\Image`'s implicit decode-or-
throw is the only incidental content check found, with no equivalent for
font binaries. **No `Policy` class or per-file authorization pattern
exists anywhere** (`app/Policies/` doesn't exist); the one upload with
any per-request gating (`AccountController::avatar()`) only requires
*any* authenticated session, not ownership. The strictest existing
precedent, ChatBox's MMS upload (`mimes:mp4,mov,...|max:20000`),
combines an extension/MIME whitelist with a size cap but still no
content-signature check. **Decision: webfont upload is architecturally
supportable, but requires building — not reusing — real content
validation and a real per-route authorization gate** (§6.9), exceeding
every existing precedent rather than merely matching one.

### 3.8 Expanded hardcoded-color and third-party-component audit

`bootstrap-extended/_variables.scss` still carries raw hex for the
entire grayscale ramp, and — critically —
`$success`/`$warning`/`$danger`/`$info`/`$secondary`/`$dark`, which
alone drive the entire procedurally-generated status/badge/border/
button system (`core/colors/_palette.scss` generates every
`.badge-light-{color}`/`.border-{color}`/`.btn-{color}`/
`.overlay-{color}`/glow-shadow rule from them — one untokenized hex per
status color, not dozens of hand-written rules). Shadow/overlay/
dropdown/modal/popover/toast/input-focus colors bypass the Milestone-1
shadow token entirely (raw `rgba($black,...)`, `$black` itself a plain
hex); tooltip background (`$tooltip-bg: #323232`) is a standalone
literal with no token. No `$focus-ring-color` override exists anywhere —
it silently inherits Bootstrap core's own default
`rgba($primary, .25)`. Dark mode is a live, shipped, currently-
toggleable feature (via the existing `ThemeCustomizerController`), with
its own second, fully separate set of ~24 hardcoded literals in
`_variables-dark.scss` plus scattered overrides in `dark-layout.scss`,
none referencing any token (deferred, §6.10 boundary). Third-party
plugin overrides (SweetAlert2, Toastr, Select2, Flatpickr, pickadate,
DataTables, Quill) all consume SCSS `$variables` — fine at compile time,
but none emit `var(--color-*)`, so none respond to a runtime theme
change without a rebuild (explicitly deferred to per-slice elimination,
§6.11). Chart grid/axis/tooltip chrome is hardcoded per-file, separately
from the series-color problem, with no chart configuring a tooltip
background/text color at all. Icons are confirmed clean
(`currentColor`/inherited-color behavior for both Lucide and Feather —
no hardcoded icon fill/stroke exists in SCSS). Inline `style="..."`
color attributes are a negligible surface (215 total `style=`
attributes app-wide, only ~4 carry any color value). Inline SVG
illustration assets (login/register/error-page art, up to 267 hex
occurrences per file) are loaded via `<img src="...">`, not inlined into
the DOM, and therefore **cannot** inherit `currentColor` or read CSS
custom properties in their current form regardless of how complete the
token system becomes — a confirmed, honestly-scoped gap (§6.11 note).

### 3.9 Critical finding: compile-time vs. runtime color resolution

Empirically verified, not assumed: the committed `public/css/core.css`'s
`.btn-primary` rule reads `background-color:#7367f0;border-color:#7367f0;
color:#fff` — a fully baked literal hex, not `var(--bs-primary)` or any
custom property. By contrast, Milestone 1's own new component library
(`resources/scss/base/components/ds-components.scss`) was verified to
use `var(--color-*)` 32 times and zero raw Sass color-variable
references. **This means Bootstrap's own native compiled component
classes — `.btn-primary`, `.badge-light-*`, `.dropdown-menu`, `.table`,
`.modal-*`, `.nav-link.active`, `.form-control:focus`, and every class
like them, used across the vast majority of the app's 357 remaining
pages — do not respond to a post-load `:root { --color-*: ...; }`
override at all.** Without a fix, "no rebuild required" would be false
for nearly the entire application. **Decision: a new, explicit "runtime
bindings" SCSS layer is required as Slice-1 infrastructure** (§6.10) — a
bounded, auditable set of override rules mapping the semantic Bootstrap
classes actually in use to `var(--color-*)`, closing this gap for
Bootstrap's native classes while explicitly, honestly deferring
third-party plugin chrome (§6.11) to per-slice elimination as later
slices touch pages that use each plugin.

---

## 4. Locked requirements — complete scope

Every color used anywhere in the customer/admin application resolves
through an owner-configurable semantic token, at minimum:

**Brand & interaction**: primary, secondary, accent, link, link-hover,
focus-ring, selection, button-primary-bg, button-secondary-bg,
button-text, hover/active/selected/disabled states, nav-hover,
nav-active.

**Text & icons**: primary/secondary/muted/inverse/disabled text;
default/muted/inverse icon (icons inherit their semantic context color —
already true today, confirmed clean by §3.8's audit).

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

**Charts**: an explicit multi-slot series palette (8 slots, sized to the
app's own largest real chart, found by direct audit — §7 item 1),
chart-neutral, chart-grid, chart-axis/label, chart-tooltip background +
text, positive/negative data. No silent fallback to Vuexy purple or any
other hardcoded legacy value anywhere.

**No locked palette.** The current approved warm-red design is the
factory fallback/reset target, never a forced constraint — the owner may
create a completely different theme; no color is architecturally locked;
missing/corrupt/incomplete config fails safely to the factory theme.
"All colors editable" means controlled semantic tokens, never arbitrary
CSS/selector-level injection.

**Token editing model — Simple and Advanced modes.** The platform owner
normally edits only a small set of base colors per category (brand
primary/secondary, five status base colors, eight chart series slots,
five surface base roles, one border base role) — every other token in
the taxonomy above **derives automatically and deterministically**
(§6.6) from these bases in **Simple mode**, the default. **Advanced
controls** additionally expose **every individual semantic token** in
the full taxonomy for direct, explicit override — an owner who wants,
say, `focus-border` to be a specific hex distinct from what derivation
would produce may set it directly, without that choice being silently
overwritten the next time any *other* field changes and derivation
re-runs (§6.6's own override-preservation rule). Advanced overrides are
validated by the exact same contrast/security rules as every derived
value (§6.7/§6.8) — Advanced mode changes *which* values are hand-set
versus computed, never *how* they are validated or *what* they may
contain.

**Global font control.** One "Application font" setting, applied
consistently to body/headings/sidebar/nav/buttons/forms/tables/cards/
dropdowns/modals/chat/badges/charts/chart-labels/dynamically-created
client-side UI, at runtime, no rebuild. Approved bundled choices, plus
owner-uploaded webfont where the audited storage architecture supports
it safely (§3.7 confirms it can, with new capability — §6.9). Factory
font remains Geist Sans as fallback/reset only, never permanently
locked.

**Theme editor experience**: grouped semantic-token controls (Simple by
default, Advanced on demand), color picker + validated hex entry, font
selector + safe upload, immediate client-side preview (temporary until
Save), Save/Cancel/Reset, validation errors, contrast warnings,
unsaved-change warning, audit info (who/when). Preview demonstrates
sidebar/nav, header, canvas + layered surfaces, text hierarchy, buttons
+ states, inputs, table, statuses, charts, chat, dropdown, modal/
overlay, desktop and narrow/mobile layouts.

**Accessibility & safety**: validate contrast for every meaningful
semantic pair (text/background, icon/background, control-state
visibility); hard-reject combinations breaking essential text,
navigation, form controls, focus indicators, destructive actions, or
status meaning — enforced at both save and activation (§6.7); warn
(non-blocking) below-preferred-but-not-broken combinations, visible both
during editing and before activation; never rely on color alone for
status/chart-series meaning (preserve labels/icons/patterns); the
editor itself must remain usable even against a poor saved theme; a
protected recovery/reset path (the Factory preset, §6.17) always exists.
No arbitrary CSS/HTML/JS/selectors/remote imports/executable font
content, ever.

**Runtime application**: validated runtime CSS custom properties at the
existing shared injection point (§3.6/§6.3); applies to both portals;
applies before visible rendering (no flash); works across server- and
client-rendered elements; charts and JS components consume the same
canonical theme source (§6.12); the active-theme cache is invalidated
immediately, correctly, after commit (§6.13) on every authorized
activation. Chat background fully removed — configurable surfaces only,
no embedded pattern, no replacement image, regardless of which preset is
active (§6.17).

### 4.6 Theme presets

The authorized platform owner can: create a new preset; duplicate an
existing one (including Factory); name and rename presets; edit every
configurable color and the application font within a non-protected
preset; preview a preset without activating it; save it without
affecting users; activate a saved preset; return to the previously
active preset; delete inactive, non-factory presets; distinguish clearly
between Draft, Saved, and Active **lifecycle states**, with Factory
tracked as an **independent protected marker** orthogonal to those
states (§6.1). Exactly one preset is active at a time. Activation is
atomic (colors and font switch together), serialized against concurrent
activation attempts (§6.14), invalidates the shared cache correctly
after commit (§6.13), and preserves the previous theme as a Saved,
recoverable preset — never deleted, never silently discarded.

**Lifecycle progression is enforced, not merely conventional**: a preset
must pass through Draft, then Saved, before it can ever be Activated —
Activate rejects any target still in Draft. **Active and Factory presets
can never be edited in place** — to change what is currently live, the
owner duplicates or creates a new Draft, edits and saves *that*, and
then Activates it (which demotes the previously-active preset to Saved,
unedited, exactly as it was when it went live).

**Factory theme**: the approved warm-red design is permanently marked
`is_factory = true` on one, and only one, row — always available for
recovery, never directly editable or deletable regardless of its own
current lifecycle `status`, duplicable and customizable (the only way to
derive an editable preset from it), and activating it restores its
complete colors and Geist Sans together. It must not restrict the owner
from creating a completely different theme.

**Validation & authorization**: only `'manage theme'` may create, edit,
activate, rename, duplicate, or delete presets (§6.15, no new
permission string); every preset passes the same schema/security
validation before it can be saved; hard accessibility failures prevent
both save **and** activation; non-blocking warnings are visible during
editing and before activation; previewing never mutates the active
theme or shared cache (§6.13); every creation/edit/activation/rollback/
rename/deletion records actor and timestamp (§6.1's event log); an
active preset is never deletable.

**Font references**: a preset references a bundled or uploaded font;
deleting an uploaded font referenced by the active theme is prevented;
deleting a font referenced by *any other* saved preset is also prevented
(§6.16's resolution: prevent uniformly, never silently reconcile); the
public font route exposes only the active public theme's font; an
authenticated, `'manage theme'`-protected preview mechanism may serve an
inactive preset's font to the editor without making every uploaded font
publicly enumerable (§6.18); activating a preset switches its font and
colors together atomically.

**Starter presets** (optional, bundled, for convenient testing — not
locked palettes; every preset remains fully editable): Warm Red
(Factory), Clear Red (`#C74444`/`#A80D12`/`#F8E3E3`), Warm Brown
(`#936047`/`#68402B`/`#F0E5DE`), Dark Chocolate (canvas `#140500`, deep
canvas `#0E0301`, primary `#A83E00`, primary hover `#C24B00`,
incoming-bubble surface `#492723`, secondary bubble `#673D3A`, raised
surface `#21100C`, border/detail `#5D2C26`, primary text `#FFF8F5`,
muted text `#BEB5B2`). Dark Chocolate uses clean solid theme surfaces
only — no chat illustration, embedded base64 pattern, or other
decorative background image is ever reintroduced, for this or any
preset (§6.17: structurally guaranteed, not a per-preset special case).

---

## 5. Reading note

Nothing in this repair reopens or narrows any requirement established by
the original contract or Correction Round 1 — the complete semantic
token taxonomy, no-locked-palette principle, global font control with
safe upload, theme editor experience, accessibility & safety rules, and
runtime application requirements above are restated here in full,
unchanged in substance, specifically to make this document self-
contained (§0). Typography's *scale/spacing* (page-title/heading/body/
label/caption sizing, established in Milestone 1) remains fixed and
untouched — only the *font-family* is owner-controlled. Presets (§4.6)
are additive: a management layer over the same token/runtime/font
architecture, not a replacement for any part of it.

---

## 6. Architecture decisions

### 6.1 Data model

```
platform_theme_presets
  id
  name                  string, required; unique among non-deleted presets; Factory's name is fixed ("Warm Red") and immutable
  status                string enum: 'draft' | 'saved' | 'active'   -- three TRUE lifecycle states only; exactly one row may ever be 'active' (transaction-enforced, §6.14)
  is_factory            boolean, default false  -- an INDEPENDENT, orthogonal protected marker, not a fourth status value: the Factory preset progresses through the SAME three lifecycle states as any other preset (it is seeded 'active', and would demote to 'saved' like any other preset if a different preset were activated) — is_factory is checked separately, everywhere edit/delete is attempted, regardless of the row's current status. Exactly one row ever has this true, seeded once (§6.17), never created a second time.
  input_tokens_json     json    -- the owner-entered Simple-mode base values (brand/status/chart/surface/border bases)
  overrides_json         json    -- Advanced-mode explicit per-token overrides (flat map: css-custom-property-name => value); any key present here wins over the corresponding auto-derived value when derived_tokens_json is computed (§6.6)
  derived_tokens_json     json    -- the fully computed, final token set actually emitted at runtime (§6.3): input_tokens_json run through derivation (§6.6), then overrides_json applied on top; recomputed on every edit, never hand-edited directly
  font_choice              string: 'bundled' | 'uploaded'
  font_bundled_key          string, nullable
  font_uploaded_id            unsignedBigInteger, nullable, NO foreign key (matches this codebase's own established no-FK audit-column convention, §3.4)
  created_by_user_id           unsignedBigInteger, nullable, NO foreign key
  created_at                    timestamp
  updated_by_user_id             unsignedBigInteger, nullable, NO foreign key  -- presets are mutable in Draft/Saved status; last-editor tracked distinctly from creator
  updated_at                      timestamp

platform_theme_preset_events    -- append-only, immutable — this codebase's own established audit-trail idiom (§3.4), scoped to preset lifecycle events
  id
  preset_id              unsignedBigInteger, NO foreign key
  event_type              string enum: 'created' | 'duplicated' | 'edited' | 'renamed' | 'activated' | 'rolled_back' | 'deleted'
  metadata_json            json, nullable  -- e.g. {old_name, new_name} for renames; {source_preset_id} for duplicates; {previous_active_preset_id} for activations
  actor_user_id             unsignedBigInteger, NO foreign key
  created_at                 timestamp only, immutable — no updated_at

platform_theme_fonts    -- unchanged in schema from its original design; only the deletion-guard query changes (§6.16)
  id
  safe_filename          string  -- server-generated (sha256 of validated bytes + validated extension), never client input
  original_filename       string  -- display only, never used to build a path
  mime_type                 string
  file_size_bytes            unsignedInteger
  storage_path                 string  -- private `local` disk, `theme-fonts/{safe_filename}`
  uploaded_by_user_id           unsignedBigInteger, nullable, NO foreign key
  created_at                     timestamp only (append-only; deletion sets deleted_at rather than hard-deleting, so historical preset-event metadata referencing it stays legible after the bytes are gone)
  deleted_at                      timestamp, nullable
```

`status` holding only three genuine lifecycle values, with `is_factory`
as a wholly separate boolean, directly satisfies this repair's own
"model Draft/Saved/Active as lifecycle states and Factory as an
independent protected marker" requirement — every guard that must treat
Factory specially (edit, delete) checks `is_factory` explicitly, never
by branching on a `status` value that doesn't actually describe Factory's
lifecycle position.

### 6.2 Authorization

`'manage theme'` permission (new, added to `config/permissions.php`
under a new `"Appearance"` category), gated by `EnsureUserIsAdministrator::class`
plus the blanket `routes/admin.php` middleware stack, checked
independently by every relevant controller action and FormRequest.
Confirmed (§6.15) to require no second permission string for preset
actions.

### 6.3 Runtime CSS injection

`PlatformThemeManager::currentStyleBlock(): ?string` returns a
pre-rendered `<style id="platform-theme-overrides">:root{--color-...:
...;}...@font-face{...}</style>` string sourced from
`platform_theme_presets WHERE status = 'active' LIMIT 1`'s
`derived_tokens_json` and font choice, or `null` if no active row exists
or its JSON fails validation at read time (fail-safe: `null` means
"render nothing, let Milestone 1's compiled `core.css` defaults stand" —
those defaults already equal the approved Factory palette, so "missing/
invalid config" and "Factory active" produce visually identical output).
Appended, via a guarding `@if`, to the end of `panels/styles.blade.php`
(§3.6, §9 item 27) — textually after `core.css`, included by all three
`<head>`-owning layouts, rendered server-side before `<body>`, so there
is no flash of default theme and no separate async request.

### 6.4 Chart token bridge

`_colors.scss` gains build-time-default custom properties for the 8
chart series slots plus chart-neutral/grid/axis/tooltip (§4). New
`resources/js/core/theme-tokens.js` (§6.12) exports helpers reading
these via `getComputedStyle(document.documentElement)` at call time, so
they always reflect whichever preset is currently active. The eight
chart-bearing Blade views are edited to call these helpers instead of
hardcoding hex arrays; semantic status-color objects (delivered/failed/
etc.) are left untouched. `window.colors.solid.primary` in
`resources/js/core/app.js` sources its value from the same runtime
property instead of a second hardcoded `#7367F0` literal.

### 6.5 Full editable-property list

Simple-mode base fields (owner normally edits only these; everything
else derives, §6.6, unless explicitly overridden in Advanced mode):
brand primary `#B5524C` (Factory default) + secondary/accent (§7 item 3);
five status base colors (success/warning/danger/info/pending); eight
chart series-slot colors (§7 item 1) + optional chart-neutral; five
surface bases (canvas `#F7F6F2`, sidebar `#F2F0EB`, primary surface
`#FFFFFF`, secondary surface `#FBFAF7`, input/control `#FFFFFF`); one
border base (`#E5E1DA`). Header/nav background derives from primary
unless explicitly overridden (§7 item 2). Text (`#262522`) and muted
text (`#6F6D67`) are fixed Milestone-1 tokens, not preset-editable
fields, in Simple mode — Advanced mode may still override any specific
derived text-role token directly (§4's own Advanced-mode allowance)
without turning the base text tokens themselves into new Simple-mode
fields.

### 6.6 Deterministic derivation algorithm

Computed server-side (`ThemeColorDerivationService`, PHP, HSL space) for
every base color a preset holds — brand, each of the five status colors,
and (where unset) chart series slots — producing hover/active/soft-
background/border/foreground variants via one shared formula, reused
everywhere a "base color → full variant set" transformation is needed.
**Override preservation**: derivation always computes the *complete* set
from `input_tokens_json` first, then applies `overrides_json` on top —
any token present in `overrides_json` is never recomputed away by a
later, unrelated edit to some other base field, which is precisely what
makes Advanced-mode overrides durable rather than a one-time cosmetic
tweak. Implementation-time verification requirement (unchanged, not
optional): the exact numeric HSL deltas must be tuned so that deriving
from the Factory default Primary reproduces the approved Hover
(`#A83D38`), Dark/active (`#980E0E`), and Soft background (`#F4E6E4`)
values within a small, stated tolerance (ΔE₀₀ < 2) — asserted by a
dedicated unit test (§10) before the same formula is trusted for
arbitrary owner-chosen colors.

### 6.7 Contrast validation — enforced at both save and activation

`ThemeContrastValidator` computes WCAG 2.1 relative luminance/contrast
ratio (hand-implemented, no new dependency). **Hard reject** (blocks the
operation): any foreground/background pairing the theme actually
renders text on falls below 4.5:1 (normal text) or 3:1 (large text/UI
components); this is checked when a preset is **saved** (so a broken
preset is never persisted) and checked **again, identically, when a
preset is activated** — a deliberate, non-redundant belt-and-braces
duplication: activation is the final, non-bypassable gate before
anything reaches real users, guaranteeing an *active* preset can never
carry a hard-reject failure even if some future code path ever
manages to persist one outside the normal save endpoint. **Soft
warning** (non-blocking): technically-valid-but-low-separation surface
pairs, visible both while editing and immediately before activation.
The validator returns `{errors, warnings}`; only `errors` block. Live
preview applies colors optimistically (pure client-side CSS
custom-property swap, no validation gate, no server call) while a
debounced AJAX call to the same PHP validator surfaces errors/warnings
inline before Save — the PHP validator is the single source of truth;
no contrast math is duplicated in JS.

### 6.8 "Never permit custom CSS/JS/arbitrary HTML"

Enforced structurally: FormRequests accept only a fixed, named set of
hex-string fields (`^#[0-9A-Fa-f]{6}$`) or a separate validated
alpha-capable format for overlay/shadow roles, one font-choice enum, one
dedicated file-upload field with its own validation pipeline (§6.9), and
nothing else. No free-text/CSS/HTML field exists anywhere in the schema
or any request — a preset cannot express anything the fixed runtime
template (§6.3) doesn't already parameterize, in Simple or Advanced mode
alike.

### 6.9 Font architecture: bundled selection + safe upload

**Bundled choices**: a small, fixed, pre-vetted, properly-licensed list
(Geist as Factory default, plus 2-4 others, §7 item 2), self-hosted the
same way Geist already is, selected via a plain dropdown. **Upload
pipeline** (built new — §3.7 confirmed nothing reusable exists):
`'font_file' => 'required|file|mimes:woff,woff2|max:5000'`, plus a new
`ValidFontFileRule` rejecting anything whose first bytes don't match the
WOFF2 (`wOF2`) or WOFF (`wOFF`) magic signature regardless of claimed
extension/MIME. Filename is never client-derived
(`sha256($bytes) . '.' . $extension`, computed server-side). Stored on
the **private** `local` disk (not `public` — confirmed unused/unsafe
elsewhere in this app, §3.7) under `theme-fonts/{safe_filename}`.
**Serving is split by trust boundary**: a public, unauthenticated
`GET theme-font/{safeId}` route (`routes/public.php`) streams bytes only
if `safeId` matches the *currently-active* preset's `font_uploaded_id`,
otherwise 404 — satisfying that an active font must be fetchable by
every anonymous browser while nothing else is; upload/replace/delete/
select all live behind `'manage theme'`-gated admin routes, never
publicly reachable. `font-display: swap` and the same safe fallback
stack Milestone 1 established for Geist are always present, bundled or
uploaded. Replacement/rollback needs no separate versioning system —
switching fonts is just another preset edit+activate (§6.14). Removal
soft-deletes a `platform_theme_fonts` row, permitted only when not
referenced by *any* preset (§6.16).

### 6.10 Runtime-bindings retrofit layer

A new SCSS partial, `resources/scss/base/tokens/_runtime-bindings.scss`,
imported immediately after the token definitions in `tokens.scss`,
containing explicit, targeted override rules mapping every semantic
Bootstrap-native class actually in use across the app (`.btn-primary`,
`.badge-light-*`, `.dropdown-menu`, `.modal-content`, `.table*`,
`.nav-link.active`, `.form-control:focus`, etc. — the exact, exhaustive
list is a mechanical grep task at implementation time, §7 item 4) to
`var(--color-*)`, for exactly the property that needs to be
runtime-live. This is a bounded, auditable, closed set — not a rewrite
of vendored Bootstrap SCSS, which stays completely untouched. What makes
theming runtime-live is that these rules' right-hand sides are
`var(--color-*)` references, re-resolved by the browser on every paint
whenever the `:root` override (§6.3) changes — the same standard CSS
custom-property cascade `ds-components.scss` already correctly uses,
now extended to Bootstrap's own native classes.

### 6.11 Third-party plugin color scope / inline SVG illustrations
### (explicit deferrals)

SweetAlert2, Toastr, Select2, Flatpickr, pickadate, DataTables, and
Quill's own chrome consume the tokenized `$success`/`$warning`/`$danger`/
`$info`/`$body-color`/`$border-color` Sass variables — a rebuild
correctly re-colors them, but none respond live at runtime (§3.8).
Retrofitting these seven vendor-override files is **deliberately not
Slice-1 infrastructure** — secondary/tertiary chrome, not the primary
brand surfaces the requirements explicitly enumerate, and each plugin is
only rendered within specific rollout modules. Every later slice that
touches a page using one of these plugins must retrofit that plugin's
own override file as part of eliminating that slice's hardcoded colors
(§8) — deferred, bounded, tracked work, not an unaddressed gap. Inline
SVG illustration assets (`resources/images/pages/*.svg`, loaded via
`<img src="...">`, not inlined into the DOM) cannot inherit
`currentColor` or CSS custom properties in their current form, regardless
of token-system completeness — a confirmed, honestly-scoped-out gap, not
silently claimed as solved.

### 6.12 Canonical JS runtime-theme-token source

`resources/js/core/theme-tokens.js`: a single module exporting
`getThemeToken(name)`/`getChartColors()`/`getStatusColors()` helpers,
all backed by one shared `getComputedStyle(document.documentElement)`
read. Every JS consumer needing a color at runtime — the eight
chart-bearing views, `window.colors` in `app.js`, any dynamically-created
client-side UI — reads through this one module, never re-implementing
its own `getComputedStyle` call. This module has no awareness of
"presets" as a concept and needs none: by the time any browser-side code
runs, exactly one preset's values are already the ones rendered into
`:root`.

### 6.13 Caching and invalidation — `DB::afterCommit`

`PlatformThemeManager::currentStyleBlock()` (§6.3) is cached under one
constant key (`platform_theme:active_style_block`) via the app's
already-configured default cache store — no new infrastructure
dependency. **Invalidation is registered with `DB::afterCommit(fn () =>
Cache::forget('platform_theme:active_style_block'))`, called from
within the same database transaction that performs an activation, but
whose actual cache-forget callback Laravel defers until that transaction
has genuinely committed** — not invoked synchronously mid-transaction.
This is a deliberate correctness fix, not a stylistic preference:
forgetting the cache *before* commit would leave a window where a
concurrent request, seeing the stale-but-not-yet-invalidated cache
entry, could repopulate it from data that the not-yet-committed
transaction hasn't actually made visible to other connections yet —
`DB::afterCommit` closes that window structurally. Three preset actions
interact with this cache distinctly: **Preview** (client-side only,
§6.7) never touches it — structurally incapable of doing so, since it
makes no persistence call. **Save** on a Draft/Saved (non-active) preset
never touches it either — editing and saving an inactive preset must
never be visible to any other user before that preset is deliberately
activated. **Activate** (and its `rolled_back` twin) always invalidates
it, via `DB::afterCommit`, every time, with no exception.

### 6.14 Preset lifecycle operations, locking, and edit protection

All orchestrated by `PlatformThemeManager`:

- **Create**: inserts a new row, `status = 'draft'`, seeded from
  Factory's current values or left at empty defaults; records a
  `created` event.
- **Duplicate**: inserts a new row copying an existing preset's
  (including Factory's) full token/font state, `status = 'draft'`, a
  distinct auto-generated name (immediately owner-renameable); records a
  `duplicated` event with `{source_preset_id}`. The **only** way to
  derive an editable preset from Factory.
- **Edit / Save**: updates a preset's `input_tokens_json`/
  `overrides_json` in place, recomputes `derived_tokens_json` (§6.6),
  runs the save-time contrast gate (§6.7); records an `edited` event.
  **Rejected outright — at the authorization layer, before any
  validation runs — if `is_factory = true` or `status = 'active'`**:
  neither the Factory row nor whichever preset is currently live can
  ever be edited in place. To change what's live, the owner must
  duplicate-or-create a Draft, edit that, Save it, then Activate it.
- **Rename**: updates only `name`; rejected for Factory (fixed name);
  records a `renamed` event with `{old_name, new_name}`. Renaming the
  active preset's *name* (not its token values) is permitted — naming is
  metadata, not theme content, and isn't covered by the edit-in-place
  restriction above.
- **Activate**: requires the target's `status = 'saved'` — **a Draft can
  never be activated directly** (Draft → Saved → Active is an enforced
  progression, checked and rejected, not merely conventional).
  Serialized against concurrent activation attempts with a **stable
  `lockForUpdate` mutex**: the transaction's first statement is
  `PlatformThemePreset::where('is_factory', true)->lockForUpdate()->first()`
  — the Factory row is guaranteed to exist exactly once and never be
  deleted, making it a safe, always-available lock target even though
  "the currently active row" can't itself be the lock target (it isn't
  known with certainty until the lock is already held). Only after
  acquiring this lock does the transaction: re-run the activation-time
  contrast gate (§6.7) against the target, demote the current `active`
  row (if any) to `status = 'saved'` — never deleted, preserving it as a
  recoverable preset — and promote the target to `status = 'active'`,
  atomically switching its colors and font together; register the
  `DB::afterCommit` cache invalidation (§6.13); record an `activated`
  event with `{previous_active_preset_id}`.
- **Rollback ("return to the previously active preset")**: the same
  Activate operation, targeting the preset most recently demoted from
  `active` to `saved` (read from the most recent `activated`/
  `rolled_back` event's own `previous_active_preset_id`). Records its
  own `rolled_back` event type — distinct from `activated` even though
  the underlying mechanism is identical — purely so the audit trail
  distinguishes "the owner picked this deliberately" from "the owner
  explicitly undid the last switch," at zero extra mechanism cost.
- **Delete**: permitted only when **all** of: not `is_factory`, not
  `status = 'active'` (checked directly, never inferred), and not
  referenced by any other preset's font relationship (§6.16). A deleted
  preset's final `name` is retained in its `deleted` event's
  `metadata_json` for audit legibility; the row itself is genuinely
  removed (unlike `platform_theme_fonts`, nothing else ever references a
  deleted preset's own id).

### 6.15 Authorization confirmation (no new permission string)

Every operation in §6.14 is gated by the same `'manage theme'`
permission (§6.2) at the same two layers (route middleware +
FormRequest `authorize()`). Zero new permission strings are introduced
by presets — a deliberate, confirmed non-change, not an oversight.

### 6.16 Font-reference guarding across multiple presets

With multiple independently-existing presets, an uploaded font can be
referenced by several rows at once. **Decision: prevent deletion
uniformly** whenever a font is referenced by the active preset *or* by
any other saved preset — never attempt "safe reconciliation" (silently
re-pointing some other, untouched preset to a different font), which
would be a surprising, unrequested mutation inconsistent with this
contract's own "never permit... arbitrary" posture and its general
preference for hard, predictable boundaries over best-effort magic. The
delete-font guard queries `platform_theme_presets WHERE
font_uploaded_id = ?` across every status (not only `active`) and
rejects with an error naming, by name, every preset that still
references it.

### 6.17 Starter presets and seeding

`PlatformThemePresetSeeder` creates Factory directly: `name = 'Warm
Red'`, `is_factory = true`, `status = 'active'` (so a fresh install has
a working, live theme identical to the approved defaults from the first
request), token JSON populated from the exact approved defaults,
`font_choice = 'bundled'`, `font_bundled_key = 'geist'`. The same seeder
also creates the three additional starter presets (Clear Red, Warm
Brown, Dark Chocolate) as `status = 'saved'` (not active — activating
one is a deliberate, separate owner action), `is_factory = false` (fully
owner-editable/renameable/deletable immediately). "For convenient
testing" is read as "ready to activate or duplicate immediately," not a
recipe the owner must hand-type, at negligible extra cost through the
same, already-necessary seeder. **Dark Chocolate's "clean solid theme
surfaces, no decorative background image" requirement is structurally
guaranteed, not a special case**: the chat background is fully removed
at the runtime-binding/SCSS layer (§9 items 51-53) for *every* preset —
no token in §4/§6.5's taxonomy is "background-image," only solid colors
and validated alpha-capable overlay/shadow tints, so no preset, Dark
Chocolate included, has any mechanism by which it could reintroduce one.

### 6.18 Preview isolation and font-preview access

"Previewing must not mutate the active theme or shared cache" is
satisfied structurally by §6.13's own split — preview is pure
client-side CSS manipulation with no persistence call to make. For
**font** preview specifically, the public `theme-font/{safeId}` route
(§6.9) is deliberately scoped to the active preset only, so it cannot
serve an inactive preset's font. A second route,
`GET admin/theme-presets/{preset}/font-preview` (a new action on the
already-existing `PlatformThemeFontController`, not a new controller
file), lives inside the `routes/admin.php` group, inheriting the full
admin auth stack plus an explicit `'manage theme'` check — satisfying
"authenticated, `manage theme`-protected preview mechanism" precisely,
and "must not make every uploaded font publicly enumerable" by
construction, since this route is never reachable unauthenticated at
all, unlike the public active-font route, which by necessity is.

---

## 7. Open technical decisions (category-3 — resolved at implementation
## time within the constraints stated here, never silently guessed)

1. **Chart series-slot default palette.** 8 explicit slots, set on
   direct evidence (the app's own largest real chart uses 8 distinct
   series). Default hex values chosen for maximum pairwise
   distinguishability while each passing §6.7's contrast rules against
   the default canvas — confirmed before Slice 1 implementation begins,
   never silently invented.
2. **Bundled font list beyond Geist, and header/nav-background override
   editability.** 2-4 additional pre-vetted, properly-licensed font
   choices, and whether the derived header/nav background may be
   independently overridden (proposed: yes, as an optional field,
   consistent with chart-neutral's own optionality) — both confirmed
   before implementation, not assumed.
3. **Secondary/accent default and live-preview markup composition** —
   the approved palette defines no default hex for a second brand hue;
   proposed resolution and the exact preview-widget composition are
   confirmed before implementation begins.
4. **Exact runtime-bindings class list (§6.10).** A mechanical,
   exhaustive grep against the real 374-view corpus at implementation
   time, not hand-enumerated here — the contract fixes the mechanism and
   scope boundary (Bootstrap-native classes, never third-party plugin
   chrome), not a hand-typed list that would drift from reality.

---

## 8. Full rollout map (unchanged in module boundaries)

| # | Slice | Scope (globs) | Files | Notes |
|---|---|---|---|---|
| **1** | **Foundation** (§9, this contract's own allowlist) | Theme presets + tokens (new), chart tokens (new), chat background fix, remaining shared-chrome icon migration (7 of 9 `panels/` files touched by icon migration; `panels/styles.blade.php` touched separately for runtime injection; `scripts.blade.php` stays untouched, same as Milestone 1), errors | 67 | **Implementation-ready this contract.** |
| 2 | Authentication & Profile | `auth/**` (excl. `auth/payment/**`), `Installer/**` | 27 | |
| 3 | Dashboards | `customer/dashboard.blade.php`, `admin/dashboard.blade.php`, `admin/hot_leads.blade.php`, `admin/ai_analytics.blade.php`, `admin/ai-settings.blade.php` | 5 | |
| 4 | Reports & Analytics | `customer/Reports/**`, `admin/Reports/**` | 12 | Consumes Slice-1 chart tokens |
| 5 | Contacts & CRM | `customer/{Contacts,contactGroups,Blacklists,opportunities}/**`, `admin/{opportunities,Blacklists}/**` | 30 | `contactGroups/show.blade.php` (1,573 lines) flagged for decomposition |
| 6 | ChatBox / Conversations (full componentization) | `customer/ChatBox/**` | 4 | Beyond Slice 1's token-level background fix |
| 7a | Campaigns — quick-send variants | `customer/Campaigns/*QuickSend.blade.php` | 6 | |
| 7b | Campaigns — builders | `customer/Campaigns/*CampaignBuilder.blade.php` | 7 | Five near-duplicate per-channel copies |
| 7c | Campaigns — overview/list/modals | remaining `customer/Campaigns/**` | 13 | |
| 8 | Automations | `customer/Automations/**` | 6 | |
| 9 | Templates | `customer/Templates/**`, `admin/{Templates,TemplateTags}/**` | 6 | |
| 10 | Numbers/SenderID/Keywords/Compliance | `customer/{Numbers,SenderID,keywords}/**`, `admin/{PhoneNumbers,SenderID,BlockSenderID,keywords,SpamWord}/**` | 29 | |
| 11a-d | Sending Servers (customer/admin × excl./create) | `{customer,admin}/SendingServer/**` | 13 | `create.blade.php` files (2,316 / 4,306 lines) each dedicated |
| 12 | Billing, Payments & Accounts | `customer/{Accounts,Payments}/**`, `customer/business/edit.blade.php`, usage-billing payment partial, `auth/payment/**` | 30 | |
| 13 | Sub-Accounts & Workspaces | `customer/{SubAccounts,workspaces}/**`, `admin/workspaces/**` | 7 | |
| 14 | Onboarding | `customer/onboarding/**` | 9 | |
| 15 | Developer/API Docs | `customer/Developers/**` | 22 | Mostly static |
| 16 | Admin Tenant Management | `admin/{customer,businesses}/**` | 22 | |
| 17 | Plans, Pricing & Catalog | `admin/{plans,workspace-plan-catalog,currency,taxes}/**` | 17 | |
| 18 | Invoices & Subscriptions | `admin/{Invoices,subscriptions}/**` | 6 | |
| 19 | Admin Users, Roles & Announcements | `admin/{Administrator,AdminRoles,Announcements}/**` | 8 | |
| 20 | Plugins & legacy Theme Customizer | `admin/{Plugins,ThemeCustomizer}/**` | 3 | Legacy `ThemeCustomizerController` and the new theme presets are separate features; reconciliation is this slice's own open decision |
| 21 | System Settings | `admin/settings/**` | 26 | `PaymentMethods/show.blade.php` (1,937 lines) flagged for dedicated handling |
| — | Transactional email templates | `resources/views/emails/**`, `resources/views/vendor/mail/**` | 26 | **Out of scope for the entire rollout** |

**Standing mandate for every slice from Slice 2 onward**: each slice's
own implementation must eliminate that slice's own hardcoded colors and
font-family declarations as it migrates — retokenizing any plugin-chrome
override file (§6.11) actually exercised by its pages, converting any
remaining `data-feather` icons, and confirming via its own mechanical
search that zero hardcoded hex/font-family literals remain in its
touched files.

---

## 9. Exact implementation allowlist (Slice 1 — the only implementation-
## ready scope in this contract)

**Closed, numbered, path-level, exactly 67 unique, sequential entries.
Any additional path required during Slice 1 implementation is a
required-68th-path-shaped stop condition (§12).**

### Theme presets — schema, domain, service layer (11)

1. `database/migrations/{timestamp}_create_platform_theme_presets_table.php` — §6.1.
2. `database/migrations/{timestamp}_create_platform_theme_fonts_table.php`
3. `database/migrations/{timestamp}_create_platform_theme_preset_events_table.php`
4. `app/Models/PlatformThemePreset.php`
5. `app/Models/PlatformThemeFont.php`
6. `app/Models/PlatformThemePresetEvent.php`
7. `app/Repositories/Contracts/PlatformThemePresetRepository.php`
8. `app/Repositories/Eloquent/EloquentPlatformThemePresetRepository.php`
9. `app/Repositories/Contracts/PlatformThemeFontRepository.php`
10. `app/Repositories/Eloquent/EloquentPlatformThemeFontRepository.php` — deletion-guard query is cross-preset (§6.16).
11. `database/seeders/PlatformThemePresetSeeder.php` — §6.17.

### Theme presets — services and exceptions (8)

12. `app/Library/Theme/ThemeColorDerivationService.php` — §6.6, including override preservation.
13. `app/Library/Theme/ThemeContrastValidator.php` — §6.7, invoked at both save and activation.
14. `app/Library/Theme/PlatformThemeManager.php` — §6.13/§6.14: full preset-lifecycle orchestration, `lockForUpdate` mutex, `DB::afterCommit` cache invalidation.
15. `app/Library/Theme/ThemeFontValidator.php` — §6.9 magic-byte validation.
16. `app/Library/Theme/Exceptions/InvalidThemeColorException.php`
17. `app/Library/Theme/Exceptions/UnsafeThemeContrastException.php`
18. `app/Library/Theme/Exceptions/InvalidThemeFontException.php`
19. `app/Library/Theme/Exceptions/InvalidThemePresetOperationException.php` — §6.14: editing/deleting Factory or the active preset, activating a Draft, deleting a still-referenced font.

### Theme presets — HTTP surface (11)

20. `app/Http/Controllers/Admin/PlatformThemePresetController.php` — index/store/show/update/duplicate/rename/activate/destroy.
21. `app/Http/Controllers/Admin/PlatformThemeFontController.php` — upload/replace/delete/serve-active-font, plus the new `servePreview()` action (§6.18).
22. `app/Http/Requests/Admin/UpdatePlatformThemePresetRequest.php`
23. `app/Http/Requests/Admin/CreatePlatformThemePresetRequest.php`
24. `app/Http/Requests/Admin/DuplicatePlatformThemePresetRequest.php`
25. `app/Http/Requests/Admin/RenamePlatformThemePresetRequest.php`
26. `app/Http/Requests/Admin/ActivatePlatformThemePresetRequest.php` — also used for the rollback action (§6.14: mechanically the same operation).
27. `resources/views/panels/styles.blade.php` — modified: appends the theme-override `<style>` block (§6.3/§3.6) at the file's end, guarded by `@if`. No existing `<link>` line removed or reordered. **(Restored — the exact item this repair's own audit found missing, §1.)**
28. `app/Http/Requests/Admin/UploadPlatformThemeFontRequest.php`
29. `app/Rules/ValidFontFileRule.php` — §6.9 magic-byte validation.
30. `routes/admin.php` — modified: `theme-presets` route section (index/store/show/update/duplicate/rename/activate/destroy) + the `theme-fonts` section (upload/replace/delete/serve/preview), wrapped per §6.2/§6.15. No existing unrelated route line changed or reordered.

### Theme presets — public route + authorization config (2)

31. `routes/public.php` — modified: one `theme-font/{safeId}` route (§6.9) resolving "the active font" via `platform_theme_presets WHERE status='active'`. No existing route line changed or reordered.
32. `config/permissions.php` — modified: new `'manage theme'` entry under a new `"Appearance"` category, additive only.

### Theme presets — views + JS (3)

33. `resources/views/admin/theme-settings/index.blade.php` — Simple/Advanced token controls (§4), font selector + upload, live preview, Cancel/Save/Activate/Delete actions per the open preset's own state, unsaved-change warning, audit info. Built entirely from the existing 13-component library.
34. `resources/views/admin/theme-settings/_preset-list.blade.php` — the preset switcher: every preset's name + Draft/Saved/Active/Factory status badge, and create/duplicate/rename/activate/delete entry points.
35. `resources/js/scripts/pages/theme-settings.js` — picker wiring, Simple/Advanced toggle, optimistic preview, debounced contrast-check calls, unsaved-change guard, preset-switching/create/duplicate/rename/delete-confirm wiring.

### Runtime-bindings retrofit layer (2)

36. `resources/scss/base/tokens/_runtime-bindings.scss` — §6.10, the critical fix making Bootstrap's own native compiled classes respond to runtime token changes.
37. `resources/scss/base/tokens.scss` — modified: one new `@import 'tokens/runtime-bindings';` line, positioned after the color/typography token imports. No existing import removed or reordered.

### Token taxonomy expansion (2)

38. `resources/scss/base/tokens/_colors.scss` — modified: full semantic token set (§4/§6.5) as build-time-default CSS custom properties.
39. `resources/scss/base/tokens/_typography.scss` — modified: one canonical `--font-family-app` property (+ Sass variable) both `$font-family-sans-serif` and `$font-family-monospace` resolve through, fixing the §3.7 alias-drift bug without touching the three independent call sites.

### Bootstrap variable retokenization (1)

40. `resources/scss/base/bootstrap-extended/_variables.scss` — modified, consolidated: (a) removes `$chat-bg-light`/`$chat-bg-dark` (§3.3); (b) retokenizes `$success`/`$warning`/`$danger`/`$info`/`$secondary`/`$dark` and the shadow/dropdown/modal/popover/toast/input-focus/tooltip colors (§3.8); (c) adds an explicit `$focus-ring-color`. No unrelated variable changes.

### Chart token bridge (10)

41. `resources/js/core/theme-tokens.js` — §6.12.
42. `resources/views/admin/dashboard.blade.php`
43. `resources/views/customer/dashboard.blade.php`
44. `resources/views/customer/Reports/charts.blade.php`
45. `resources/views/customer/Reports/analyze.blade.php`
46. `resources/views/admin/Reports/overview.blade.php`
47. `resources/views/admin/Reports/dashboard.blade.php`
48. `resources/views/customer/Campaigns/overview.blade.php` — brand-series colors only; semantic delivery-status object untouched.
49. `resources/views/customer/Automations/overview.blade.php`
50. `resources/js/core/app.js` — `window.colors.solid.primary` sourced from `theme-tokens.js`; every other key untouched.

### Chat background remediation (3)

51. `resources/scss/base/pages/app-chat.scss` — background rules token-driven; bubble colors retokenized.
52. `resources/scss/base/pages/app-chat-list.scss` — bubble color retokenization, if not already fully covered by item 51.
53. `resources/scss/base/themes/dark-layout.scss` — the one deliberate, documented exception to the Milestone-1 "never touch the dark bundle" default: removes `$chat-bg-dark` and retokenizes the corresponding dark-mode bubble overrides only. The rest of `_variables-dark.scss`'s palette is explicitly not touched (deferred, §6.10/§8's Slice-20 note).

### Remaining Feather→Lucide icon migration in shared chrome (7)

54. `resources/views/panels/navbar.blade.php`
55. `resources/views/panels/sidebar.blade.php`
56. `resources/views/panels/footer.blade.php`
57. `resources/views/panels/breadcrumb.blade.php`
58. `resources/views/panels/submenu.blade.php`
59. `resources/views/panels/horizontalMenu.blade.php`
60. `resources/views/panels/horizontalSubmenu.blade.php`

### Errors module (7)

61. `resources/views/errors/401.blade.php`
62. `resources/views/errors/403.blade.php`
63. `resources/views/errors/404.blade.php`
64. `resources/views/errors/419.blade.php`
65. `resources/views/errors/429.blade.php`
66. `resources/views/errors/500.blade.php`
67. `resources/views/errors/503.blade.php`

**Total: 67 files, numbered 1-67, sequential, no gaps, no duplicates —
mechanically verified (§14).** Any path beyond this list required during
Slice 1 implementation is a required-**68th**-path-shaped stop
condition (§12).

---

## 10. Test contract (Slice 1)

- **Preset CRUD & lifecycle**: `PlatformThemePresetLifecycleTest` —
  create/duplicate/edit/rename behave per §6.14; **editing is rejected
  for `is_factory = true` and for `status = 'active'` alike**; renaming
  the active preset's name (not its tokens) succeeds; **activating a
  `status = 'draft'` preset is rejected** (Draft → Saved → Active
  enforced).
- **Activation atomicity & locking**: `PlatformThemePresetActivationTest` —
  after activating B while A was active, exactly one row is `active` (A
  is `saved`, not deleted); colors and font switch together; concurrent
  activation attempts (simulated via two processes racing for the
  Factory-row `lockForUpdate` mutex) serialize correctly with no lost
  update and no moment where zero or two rows are `active`; a
  hard-reject-contrast preset cannot be activated even though it could
  be saved.
- **Rollback**: reactivates the correct prior preset, recording a
  distinct `rolled_back` event.
- **Deletion guards**: deleting the active preset, Factory, or an
  uploaded font referenced by the active preset or by any other saved
  preset are all rejected, the last naming the blocking preset(s).
- **Preview isolation & cache**: editing/previewing/saving a non-active
  preset never changes the shared cache key or any other user's
  rendered theme; `DB::afterCommit` is confirmed to defer the
  cache-forget call until the activation transaction has actually
  committed (not invoked synchronously mid-transaction); the admin
  font-preview route requires `'manage theme'`; the public font route
  never serves a non-active preset's font.
- **Advanced overrides**: an Advanced-mode override on one specific
  token survives an unrelated Simple-mode base-color edit and
  re-derivation (§6.6's override-preservation rule), and is itself
  subject to the same contrast validation as any derived value.
- **Starter seeding**: exactly one `is_factory = true, status = 'active'`
  row exists after seeding with the exact approved default values; the
  three starter presets exist as `status = 'saved', is_factory = false`.
- **Derivation accuracy**: deriving from the Factory default Primary
  reproduces the approved Hover/Dark/Soft-background values within the
  stated tolerance.
- **Authorization**: a customer, a Workspace owner/member, and an admin
  without `'manage theme'` are denied every preset/font action; an admin
  with it succeeds.
- **Chart tokens / chat background / runtime-bindings**: content tests
  confirming zero remaining `7367F0` literals in the chart-bearing files,
  zero `chat-bg-light`/`chat-bg-dark` references, and a runtime-bindings
  rule present for every class identified by §7 item 4's mechanical grep.
- **Font upload validation/serving**: a renamed non-font file is
  rejected by magic-byte checking despite a passing extension/MIME
  claim; the public route serves only the active font and 404s
  otherwise.
- Full existing suite re-run, exact pass count compared against the
  pre-Slice-1 baseline (2,724 passed / 8,672 assertions) — zero
  regressions permitted.

---

## 11. Mechanical searches (Slice 1)

1. `grep -rniE "anthropic|claude"` across every path in §9 → zero matches.
2. `grep -c "7367F0"` (case-insensitive) across §9 items 42-50 → zero.
3. `grep -n "chat-bg-light\|chat-bg-dark"` across §9 items 40, 51-53 → zero.
4. `grep -rn "data-feather"` across §9 items 54-60 → zero.
5. `git diff --stat -- app database routes` compared against §9 items 1-32 only → any other path in `app/`, `database/`, or `routes/` is a violation.
6. `git diff --stat -- resources/scss/base/themes/{bordered-layout,semi-dark-layout}.scss resources/scss/base/custom-rtl.scss resources/assets/scss/style-rtl.scss` → empty (only `dark-layout.scss`, item 53, is the deliberate exception).
7. A runtime/database-level check (not static grep): after any sequence of preset operations in the test suite, `SELECT COUNT(*) FROM platform_theme_presets WHERE status = 'active'` is always exactly `1`.
8. `grep -c "is_factory" app/Library/Theme/PlatformThemeManager.php` → present in both the edit-guard and delete-guard code paths.
9. `grep -n "DB::afterCommit"` in `PlatformThemeManager.php` → present, wrapping the cache-invalidation call specifically.
10. `grep -n "lockForUpdate"` in `PlatformThemeManager.php` → present, targeting the Factory row specifically.
11. `grep -rn "'manage theme'"` across `config/permissions.php`, both new controllers, and every new FormRequest → present and consistent.
12. Final changed-path set (`git diff --name-only` + `git ls-files --others --exclude-standard`) equals §9's exact, sequential 1-67 allowlist — mechanically diffed, not eyeballed.
13. `php artisan test` full-suite pass count compared against the pre-Slice-1 baseline, reported exactly, never estimated.

---

## 12. Stop conditions

Slice 1 implementation must stop, leave the working tree unstaged, and
report rather than proceed, if:

- Any path beyond §9's 67-item allowlist is required — the **68th**
  path.
- Any change to `app/`, `database/`, or `routes/` beyond §9 items 1-32
  appears necessary.
- The `bordered-layout`/`semi-dark-layout`/RTL bundles (as opposed to
  `dark-layout.scss`, item 53's documented exception) appear to require
  touching.
- More than one `platform_theme_presets` row is ever observed with
  `status = 'active'` at rest.
- Any code path permits editing the `is_factory = true` row, editing a
  `status = 'active'` row, activating a `status = 'draft'` row, deleting
  an active or Factory preset, or deleting an uploaded font still
  referenced by any preset.
- Cache invalidation is found to run synchronously inside the activation
  transaction rather than via `DB::afterCommit`, or to fire for a
  Draft/Saved (non-active) preset's own save.
- §7's open decisions (chart-palette defaults, bundled-font list +
  header-override editability, secondary/accent default + preview
  composition, the exact runtime-bindings class list) remain unconfirmed
  before implementation begins.
- Any derived color pair required to render legible text fails §6.7's
  hard-reject threshold even for the Factory default palette.
- Any existing test fails for a reason not fixable within Slice 1's own
  allowlist.
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is
  found necessary to reference.
- A theme value would need to be persisted as anything other than one of
  the fixed, named, typed fields in §6.1's schema.

---

## 13. Contract self-audit

1. Every locked requirement (§4, including §4.6) is addressed by a
   numbered decision in §6 and a numbered path in §9. ✓
2. This document is genuinely self-contained — no section instructs the
   reader to consult an earlier commit for the complete text of any
   requirement or architecture decision (§0's own stated defect from the
   prior merged version is corrected throughout, not merely noted). ✓
3. Both mechanically-confirmed defects from the merged `73f74b5`
   version — the missing `panels/styles.blade.php` allowlist entry and
   the non-sequential 1-26/28-67 numbering — are fixed by the same,
   single change: restoring the file as a real, numbered item 27 (§1,
   §9). ✓
4. Draft/Saved/Active are modeled as three genuine lifecycle `status`
   values; Factory is modeled as an independent `is_factory` boolean
   checked everywhere edit/delete is attempted, never as a fourth status
   value (§6.1). ✓
5. Active and Factory presets are both structurally prevented from
   in-place editing (§6.14); Draft→Saved→Active progression is enforced,
   not conventional (§6.14); activation is serialized via a stable
   `lockForUpdate` mutex on the permanent Factory row (§6.14); cache
   invalidation is registered via `DB::afterCommit`, never synchronously
   mid-transaction (§6.13); Simple-mode automatic derivation and
   Advanced-mode explicit per-token override coexist, with overrides
   durable across unrelated re-derivation (§6.6). ✓
6. Font-reference deletion guarding covers every preset, not only the
   active one (§6.16); the active-font public route and the
   `manage theme`-protected inactive-font preview route are both
   present and distinctly scoped (§6.9/§6.18). ✓
7. **Allowlist total is exactly 67, numbered 1-67, sequential, with no
   duplicate path anywhere** — mechanically verified before commit
   (§14), not asserted. This is unchanged in *count* from the merged
   version's own claimed total, but is now actually, mechanically true
   of the list itself, which it previously was not. ✓
8. The stop threshold is explicitly the 68th path, stated in §0, §9,
   and §12 consistently. ✓
9. Every Correction Round 1 architecture decision this repair was
   instructed not to change — the complete token taxonomy, the
   runtime-bindings retrofit, font-upload safety mechanics, the
   contrast-validation formula, the canonical JS token source, and the
   21-slice rollout map — is present in full and unaltered in substance
   (§3-§8), only made self-contained. ✓
10. This repair completes Correction Round 2 rather than consuming a
    third round, per its own explicit framing (§0) — the contract's
    `maximum_correction_rounds: 2` governance is unaffected. ✓
11. No business logic, permission model (beyond the one additive
    `'manage theme'` string), route behavior, tenant isolation, or
    data-flow of any existing feature changes anywhere in §9. ✓
12. This document remains the only file changed on this branch (§2). ✓

---

## 14. Verification and publication

Performed, in order, before commit:

1. `git status` shows exactly one changed path.
2. Section 9's numbered items counted mechanically
   (`grep -oE "^[0-9]+\. " ` within §9's own bounds) and confirmed equal
   to exactly 67, with no gap and no repeated number in the sequence
   1-67.
3. Every path listed in §9 checked for uniqueness — no path string
   appears twice.
4. `git diff --check` run against the staged file — no whitespace-error
   or conflict-marker findings.
5. Stage individually (`git add docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`),
   never `git add -A`/`.`.
6. Commit message: `docs: repair merged design system M2 preset contract`.
7. Push to `origin chore/design-system-m2-contract-verification-fix` —
   a normal push, never force-pushed.
8. Open a PR into `main`.
9. **Do not merge. Do not begin Slice 1 implementation.** Both require
   separate, explicit, future authorization.
