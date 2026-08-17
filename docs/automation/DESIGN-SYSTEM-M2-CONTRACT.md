# Design System — Milestone 2 Contract

Full application rollout, platform-owner complete theme control (named
presets, colors + font), chart theming, and chat background remediation.

**Correction Round 2 (final round under this contract's own 2-round
budget)** — adds named theme presets (create/duplicate/rename/edit/
preview/save/activate/rollback/delete, with Draft/Saved/Active/Factory
states) on top of Correction Round 1's complete semantic-token, runtime-
binding, font-control, upload-safety, accessibility, and rollout
architecture, which this round does **not** change. See §16 for exactly
what changed and why.

**Status: contract only. No implementation has occurred under this
document, in any of the three drafting passes. Merging this contract does
NOT authorize any implementation — each rollout slice (§9) requires its
own separate, explicit authorization, exactly like every prior contract
in this repository.**

---

## 0. Governance

- This document is drafted on branch `chore/design-system-m2-contract` in
  an isolated worktree, based on `main` at the commit that already
  contains Milestone 1 (`fb5c823`, PR #84).
- Drafting and correcting this contract makes **zero** application
  changes, across all three passes. No `resources/`, `app/`, `database/`,
  or `routes/` file is touched by this branch — only this document.
- Once merged, implementation of **Slice 1 only** (§9) may begin under a
  separate, explicit authorization. Slices 2 onward (§8) are a locked
  rollout *map*, not yet allowlisted.
- `maximum_correction_rounds: 2` applies to this contract. **This is
  correction round 2 of 2 — the final correction round this contract's
  own governance permits.** Any further change after this round requires
  either a fresh contract or an explicit, separately-authorized extension
  of this budget, exactly like every prior multi-round contract in this
  repository's history.
- Any path required during Slice 1 implementation but absent from §9's
  own numbered allowlist is a stop-and-report condition — not a silent
  workaround.

---

## 1. Mandatory preflight — verified (all three drafting passes)

- Read `CLAUDE.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md`
  (Milestone 1) and its merged implementation.
- Confirmed Milestone 1 is merged to `main` (`fb5c823`).
- **Original pass**: three-track audit (rollout inventory, chart/chat,
  config-storage/authorization/runtime-injection) — §3.1-3.6.
- **Correction Round 1**: two additional audit tracks (complete
  hardcoded-color surface including third-party components; font
  declarations and file-upload/storage architecture) — §3.7-3.9.
- **Correction Round 2 (this pass)**: no new repository audit was
  required or requested — theme presets are a management-layer
  architecture decision built entirely on top of Round 1's
  already-audited token/runtime/font/authorization foundation. This
  round is verified instead by re-reading Round 1's own §6 architecture
  in full and tracing every preset requirement to either an already-
  established mechanism it can reuse unchanged, or a specific,
  identified extension point — recorded in §6.14-§6.18 and reconciled in
  §9's own closing paragraph.

---

## 2. This contract's own exact file scope

Only one path has ever changed on `chore/design-system-m2-contract`,
across all three drafting passes:

1. `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` (this file)

---

## 3. Mandatory repository audit — findings (unchanged since Correction
## Round 1; reproduced for continuity, not re-verified this round)

### 3.1 Remaining rollout surface

- **374** total Blade view files under `resources/views/` (machine-
  verified). **2** migrated to the Milestone-1 component library.
  **357** remaining Blade views.
- **223 files / 796 occurrences** of `data-feather="..."` remain.
- **55 files** are 500+ lines; two extreme outliers each get their own
  dedicated future slice (§8).
- `resources/views/emails/**` and `resources/views/vendor/mail/**`
  remain **out of scope for the entire rollout**.

### 3.2 Chart color audit (series colors)

Four PHP call sites build `LarapexChart` objects with no `->setColors()`
call; series colors are hardcoded client-side across at least eight
Blade `<script>` blocks, with the legacy Vuexy purple `#7367F0` repeated
throughout. No centralized chart-color config exists. Semantic
status-color objects (delivered/failed/etc.) stay out of scope.

### 3.3 Chat background audit

The repeating icon-pattern background is an inline base64 SVG data URI
in `$chat-bg-light`/`$chat-bg-dark` (`_variables.scss:664-665`), applied
by `app-chat.scss:206-217`, referenced again for dark mode at
`dark-layout.scss:1725`. Five on-disk `chat-bg*` files are confirmed dead
assets. Outbound bubbles use a purple gradient; inbound bubbles use
`lighten($white,18%)`. Bubble HTML is built via inline JS template
literals in `ChatBox/index.blade.php`, not a Blade partial — the full
must-preserve element list (composer, attachment input, template picker,
send button, timestamp, notification badge, pin/block/delete, empty
state, and the conditional Pusher/Echo guard) carries forward unchanged.

### 3.4 Platform configuration storage audit

`AppConfig` is flat, unaudited, and its one JSON-blob precedent has no
audit trail; the existing `ThemeCustomizerController` already bypasses it
entirely. This codebase's established idiom for "who changed this and
when" is an append-only table with a plain scalar `*_user_id` column, no
FK. **Decision (§6.1, now generalized to presets): a dedicated set of
tables is used — not `AppConfig`** — reasoning unchanged, strengthened
by the larger token set and now the preset lifecycle.

### 3.5 Platform-owner authorization audit

No `is_platform_owner` concept exists beyond an `id===1` bypass. The
established, reusable pattern is "one settings page, one permission
string" (`'general settings'`). **Decision (§6.2, unchanged): a new
`'manage theme'` permission**, gated by `EnsureUserIsAdministrator::class`
plus the blanket admin middleware — and, per this round's own explicit
instruction, this **same** permission now governs every preset action
(create/edit/activate/rename/duplicate/delete), confirmed as requiring
no new permission string (§6.15).

### 3.6 Runtime CSS injection point audit

`panels/styles.blade.php:12` emits the `core.css` `<link>`; nothing after
it redefines any `--color-*` custom property; it is `@include`d by all
three root layout files. **Decision (§6.3, unchanged insertion point):**
a server-rendered `<style>` block appended to `panels/styles.blade.php`,
now sourced from **whichever preset currently has `status = 'active'`**
rather than a single settings row (§6.14) — the insertion mechanism
itself, and its fail-safe/no-flash properties, are entirely unchanged.

### 3.7 Font-family and font-upload architecture audit

Four locations independently reference `$font-family-monospace`
(sidebar nav, top navbar, Quill editor container) — a font-swap that only
rewrites `$font-family-sans-serif` would silently miss them; fixed via
one canonical `--font-family-app` token (§6.9, unchanged this round).
KaTeX (Quill's math toolbar) is a separate, unrelated, out-of-scope font
system. No active icon font exists anywhere. No file in this codebase
validates upload binary content or gates per-file access for any upload
type — font upload (§6.9) is built from scratch against this evidence,
unchanged this round except for the new multi-preset reference-guarding
logic (§6.16).

### 3.8 Expanded hardcoded-color and third-party-component audit

`_variables.scss` still carries raw hex for the grayscale ramp and,
critically, `$success/$warning/$danger/$info/$secondary/$dark`, which
alone drive the entire procedurally-generated status/badge/border/button
system. Shadow/dropdown/modal/popover/toast/focus-ring colors bypass the
token system entirely; tooltip background is a standalone literal. Dark
mode is a live, separately-toggleable feature with its own fully
separate ~24-literal palette (deferred, §6.10 boundary, unchanged).
Third-party plugins (SweetAlert2, Toastr, Select2, Flatpickr, pickadate,
DataTables, Quill) consume Sass variables, not runtime custom
properties — explicitly deferred to per-slice elimination (§6.11,
unchanged). Icons are confirmed clean (`currentColor` inheritance).
Inline SVG illustrations are a confirmed, honestly-scoped gap the token
system cannot close as designed (§6.11 note, unchanged).

### 3.9 Critical finding: compile-time vs. runtime color resolution

Empirically verified: the committed `public/css/core.css`'s
`.btn-primary` rule is `background-color:#7367f0` — a **baked literal**,
not a custom property — while Milestone 1's own new component library
correctly uses `var(--color-*)` throughout. **Decision (§6.10, unchanged
this round): a new "runtime bindings" SCSS layer** maps Bootstrap's
native compiled classes to the token custom properties, closing the gap
for the classes used across nearly the entire application. This finding
and its fix are foundational, load-bearing infrastructure for presets
too: without it, activating a different preset would not visibly change
the vast majority of the app's actual rendered colors, regardless of how
correct the preset-switching mechanism itself is.

---

## 4. Locked requirements — corrected, complete scope (§4 of Correction
## Round 1, plus this round's addition, §4.6)

§4.1-§4.5 (complete semantic token taxonomy, no locked palette, global
font control with safe upload, theme editor experience, accessibility &
safety, runtime application) are **unchanged from Correction Round 1** —
reproduced in full there, not restated here to avoid drift between two
copies of the same text. This round adds:

### 4.6 Theme presets (new)

> Add named theme presets so the platform owner can safely create and
> test completely different designs without overwriting the active
> theme.

The authorized platform owner must be able to: create a new preset;
duplicate an existing one; name and rename presets; edit every
configurable color and the application font within a preset; preview a
preset without activating it; save it without affecting users; activate
a saved preset; return to the previously active preset; delete inactive
custom presets; distinguish clearly between Draft, Saved, Active, and
Factory states. Exactly one theme is active at a time. Activation is
atomic, invalidates relevant caches immediately, and preserves the
previous theme as a saved, recoverable preset.

**Factory theme**: the approved warm-red design is a protected factory
fallback — always available for recovery, never directly editable or
deletable, duplicable and customizable, and activating it restores its
complete colors and Geist Sans together. It must not restrict the owner
from creating a completely different theme.

**Validation & authorization**: only `'manage theme'` may create, edit,
activate, rename, duplicate, or delete presets; every preset passes the
same schema/security validation before it can be saved; hard
accessibility failures prevent activation; non-blocking warnings are
visible during editing and before activation; previewing never mutates
the active theme or shared cache; every creation/edit/activation/
rollback/rename/deletion records actor and timestamp; an active preset
is never deletable.

**Font references**: a preset references a bundled or uploaded font;
deleting an uploaded font referenced by the active theme is prevented;
deleting a font referenced by another saved preset is prevented (§7.1's
resolution of the two options offered); the public font route exposes
only the active public theme's font; an authenticated, `'manage theme'`-
protected preview mechanism may serve an inactive preset's font to the
editor, without making every uploaded font publicly enumerable;
activating a preset switches its font and colors together atomically.

**Starter presets** (optional, bundled, for convenient testing — not
locked palettes, every preset remains fully editable): Warm Red
(factory), Clear Red (`#C74444`/`#A80D12`/`#F8E3E3`), Warm Brown
(`#936047`/`#68402B`/`#F0E5DE`), Dark Chocolate (a dark palette:
canvas `#140500`, deep canvas `#0E0301`, primary `#A83E00`, primary
hover `#C24B00`, incoming-bubble surface `#492723`, secondary bubble
`#673D3A`, raised surface `#21100C`, border/detail `#5D2C26`, primary
text `#FFF8F5`, muted text `#BEB5B2`). Dark Chocolate uses clean solid
theme surfaces only — the chat illustration/embedded base64 pattern/any
decorative background image stays removed regardless of which preset is
active (§6.17).

---

## 5. Reading note (unchanged from Correction Round 1)

Correction Round 1 fully superseded the original contract's limited
palette. This round does not reopen or narrow anything Round 1
established — it adds a management layer (named presets) *on top of* the
same complete token/runtime/font/accessibility architecture, per its own
explicit instruction: "Do not change the complete token, runtime-
binding, font-control, upload-safety, accessibility, or rollout
architecture established by Correction Round 1."

---

## 6. Architecture decisions

### 6.1 Data model — presets as first-class mutable entities, plus a
### separate append-only lifecycle-event log

Correction Round 1's `platform_theme_settings` table was designed as a
pure append-only activation history — each save/restore inserted a new
immutable row. Presets need a genuinely different shape: a named entity
that can be **created once and edited in place** while not (or even
while) active, independent of any activation event. Reconciling this
(§9's own closing paragraph tallies every resulting file change):

```
platform_theme_presets          -- RENAMED + reshaped from platform_theme_settings; a real, mutable entity table now, not append-only
  id
  name                  string, required; unique among non-deleted presets; Factory's name is fixed ("Warm Red") and immutable
  status                string enum: 'draft' | 'saved' | 'active' | 'factory'   -- exactly one row may ever be 'active'; enforced by wrapping every activation in one DB transaction that demotes the previous active row and promotes the target together, never by a bare unique index alone (a transaction is required regardless, since the two writes must be atomic)
  is_factory            boolean, default false  -- exactly one row, seeded once (§6.17), permanently protected from edit/delete by application-level guards, never a second time created
  input_tokens_json     json    -- the owner-entered base values (§6.5, unchanged shape from Round 1)
  derived_tokens_json   json    -- the fully computed token set (§6.6), recomputed whenever input_tokens_json changes, never hand-edited
  font_choice            string: 'bundled' | 'uploaded'
  font_bundled_key        string, nullable
  font_uploaded_id         unsignedBigInteger, nullable, NO foreign key (unchanged convention)
  created_by_user_id       unsignedBigInteger, nullable, NO foreign key
  created_at                timestamp
  updated_by_user_id        unsignedBigInteger, nullable, NO foreign key  -- new: presets are mutable, so last-editor is tracked distinctly from creator
  updated_at                 timestamp

platform_theme_preset_events    -- NEW — append-only/immutable, carrying forward Round 1's original audit-trail shape, now scoped to preset *lifecycle events* rather than the presets' own storage
  id
  preset_id              unsignedBigInteger, NO foreign key (same convention)
  event_type              string enum: 'created' | 'duplicated' | 'edited' | 'renamed' | 'activated' | 'rolled_back' | 'deleted'
  metadata_json            json, nullable  -- e.g. {old_name, new_name} for renames; {source_preset_id} for duplicates; {previous_active_preset_id} for activations
  actor_user_id             unsignedBigInteger, NO foreign key
  created_at                 timestamp only, immutable — no updated_at
```

`platform_theme_fonts` (Round 1) is **unchanged in schema** — only the
reference-guarding *logic* inside `PlatformThemeManager` changes, to
check every preset's `font_uploaded_id`, not a single active row's
(§6.16).

This directly satisfies "distinguish clearly between Draft, Saved,
Active, and Factory states" (the `status` enum, an explicit, named,
four-value field — not an inference from other columns), "record actor
and timestamp of every creation/edit/activation/rollback/rename/
deletion" (the six `event_type` values map 1:1 onto the six named
requirements), and "exactly one theme active at a time" (the transaction
discipline, not merely a constraint).

### 6.2 Authorization (unchanged from Correction Round 1)

`'manage theme'`, `EnsureUserIsAdministrator::class`, blanket admin
middleware — confirmed by this round (§6.15) to already cover every
preset action with no new permission string needed.

### 6.3 Runtime CSS injection (unchanged mechanism, updated source query)

Same insertion point, same fail-safe/no-flash properties as Round 1.
`PlatformThemeManager::currentStyleBlock()` now queries
`platform_theme_presets WHERE status = 'active' LIMIT 1` instead of
`platform_theme_settings WHERE is_active = true` — a one-line internal
change to an already-allowlisted method, not a new mechanism.

### 6.4 Chart token bridge (unchanged from Correction Round 1)

### 6.5 Full editable-property list (unchanged from Correction Round 1)

Every category, default value, and "owner enters a small base set,
variants derive automatically" principle from Round 1 applies unchanged
to **each preset independently** — a preset's `input_tokens_json` holds
exactly the same bounded field set Round 1 already specified.

### 6.6 Deterministic derivation algorithm (unchanged from Correction
### Round 1)

Applied per-preset, on every edit to that preset's `input_tokens_json` —
recomputing that preset's own `derived_tokens_json` only, never any
other preset's.

### 6.7 Contrast validation — clarified: save vs. activate gating

Round 1 established the two-tier {errors, warnings} validator. This
round clarifies, per its own explicit instruction ("Hard accessibility
failures must prevent activation"), that hard-reject failures are
checked at **both** points, not only one: **saving** a preset's edits
runs the same validation Round 1 always ran (so a preset already known
to be broken is never persisted in the first place); **activating** a
preset re-runs the identical check as a second, final, non-bypassable
gate immediately before it can become the live theme — because a
preset's field values could not otherwise be edited by anything else
between save and activate, this is a deliberate belt-and-braces
duplication, not redundant work: it guarantees an *active* preset can
never fail hard-reject contrast even if some future code path ever
manages to persist one without going through the normal save endpoint.
Non-blocking warnings remain visible both during editing (Round 1) and
now explicitly **before activation** too (an owner activating a preset
they haven't touched in a while sees the same warnings they'd see if
editing it right now).

### 6.8 "Never permit custom CSS/JS/arbitrary HTML" (unchanged)

### 6.9 Font architecture: bundled selection + safe upload (unchanged
### mechanism from Correction Round 1; reference-scope changes, §6.16)

### 6.10 Runtime-bindings retrofit layer (unchanged from Correction
### Round 1)

### 6.11 Third-party plugin color scope / inline SVG illustrations
### (unchanged deferrals from Correction Round 1)

### 6.12 Canonical JS runtime-theme-token source (unchanged from
### Correction Round 1)

`theme-tokens.js` continues to read whatever the current `<style>`
override block set — it has no awareness of "presets" as a concept at
all, and needs none: by the time any browser-side code runs, exactly one
preset's values are already the ones rendered into `:root`. This is a
clean separation of concerns Round 1's own design already provided for
free — no changes needed to this file's own responsibilities.

### 6.13 Caching and invalidation — clarified: preview vs. save vs.
### activate

Round 1 already required immediate, explicit cache invalidation on "an
authorized change," keyed on a single constant cache key representing
"the active theme's rendered style block." This round makes explicit,
per its own instruction ("Previewing must not mutate the active theme or
shared cache"), which of the three preset actions actually touch that
key:

- **Preview** (client-side, optimistic CSS custom-property swap in the
  editor's own browser tab, §4.1's existing mechanism) never calls the
  server's cache-invalidation path at all — it is purely local DOM/CSS
  manipulation, structurally incapable of affecting the shared cache
  because it makes no persistence call.
- **Save** (persisting edits to a preset's `input_tokens_json`/
  `derived_tokens_json`) does **not** invalidate the shared
  `platform_theme:active_style_block` cache key **unless the preset
  being saved is the currently-active one** — editing and saving an
  inactive Draft/Saved preset must not affect what any other user sees,
  per the explicit "save it without affecting users" requirement.
- **Activate** (and its `rolled_back` twin, §6.14) always invalidates the
  cache key immediately, inside the same transaction that flips
  `status`, regardless of which preset is involved — this is the one
  action explicitly named as needing immediate invalidation.

### 6.14 NEW — Preset lifecycle operations

All orchestrated by `PlatformThemeManager` (already allowlisted,
expanded responsibility, no new file):

- **Create**: inserts a new `platform_theme_presets` row,
  `status = 'draft'`, seeded either from the Factory preset's current
  values (a fresh start) or left at sensible empty defaults for the
  owner to fill in; records a `created` event.
- **Duplicate**: inserts a new row copying an existing preset's (including
  Factory's) full `input_tokens_json`/font choice, `status = 'draft'`,
  a distinct name (e.g. "{original} copy", owner-renameable
  immediately); records a `duplicated` event with `{source_preset_id}`.
  This is the **only** way to derive an editable preset from Factory,
  satisfying "it can be duplicated and customized" while Factory itself
  never becomes directly mutable.
- **Edit / Save**: updates an existing non-factory preset's
  `input_tokens_json` in place (`updated_by_user_id`/`updated_at` set),
  recomputes `derived_tokens_json` (§6.6), runs the save-time contrast
  gate (§6.7); records an `edited` event. Rejected outright, at the
  authorization layer before any validation even runs, if
  `is_factory = true` — satisfying "it cannot be edited... directly."
  A preset may be edited regardless of its `status` (draft/saved, or
  even the currently-active one — editing and re-saving the active
  preset is a legitimate way to tune the live theme in place, distinct
  from switching to a *different* preset via Activate); only Factory is
  ever unconditionally protected.
- **Rename**: updates only `name`; records a `renamed` event with
  `{old_name, new_name}`. Rejected for Factory (fixed name).
- **Activate**: the one place two rows change together, in one
  transaction: the currently-`active` row (if any) is demoted to
  `status = 'saved'` — never deleted, satisfying "preserve the previous
  theme as a saved, recoverable preset" — and the target row is promoted
  to `status = 'active'`; the activation-time contrast gate (§6.7) runs
  first and blocks the whole transaction on failure; the shared cache is
  invalidated (§6.13); records an `activated` event with
  `{previous_active_preset_id}`.
- **Rollback ("return to the previously active preset")**: not a
  separate code path — it is Activate, called with the target being the
  preset most recently demoted from `active` to `saved` (readable
  directly from the most recent `activated` event's own
  `previous_active_preset_id`, or equivalently the preset referenced by
  the most recent `activated` event that isn't the current one). Records
  its own `rolled_back` event (distinct `event_type` from `activated`,
  even though the underlying operation is identical) purely so the audit
  trail can distinguish "the owner deliberately picked this preset" from
  "the owner explicitly asked to undo the last switch" — a meaningful
  distinction for anyone reading the history later, at zero extra
  mechanism cost.
- **Delete**: permitted only when **all** of: not `is_factory`, not
  `status = 'active'` (an active preset is never deletable, per the
  explicit requirement — checked directly against `status`, not
  inferred), and not referenced by any *other* preset's pending font
  relationship in a way §6.16 would block. Soft-deletes are not used for
  presets themselves (unlike `platform_theme_fonts`, §6.9) — a deleted
  preset's own `deleted` event retains its final `name` in
  `metadata_json` for audit legibility, and the row is genuinely removed
  since nothing else ever needs to reference a deleted preset's own id
  (font references point the other way, preset → font, never font →
  preset).

### 6.15 NEW — Authorization confirmation (no new permission string)

Traced directly against §3.5/§6.2: every preset action above is gated by
the **same** `'manage theme'` permission Round 1 already established, at
the same two layers (route middleware + FormRequest `authorize()`).
This round introduces **zero** new permission strings — confirmed as a
deliberate non-change, not an oversight, exactly matching the
correction's own explicit instruction that this single permission covers
create/edit/activate/rename/duplicate/delete.

### 6.16 NEW — Font-reference guarding across multiple presets

Round 1 only had one active theme to check before permitting an uploaded
font's deletion. With multiple independently-existing presets, a font
can now be referenced by several rows at once. The correction's own
instruction offers two explicit options — "prevent deletion... referenced
by the active theme" (hard requirement) and "prevent **or** safely
reconcile deletion... referenced by another saved preset" (a choice).
**Decision: prevent uniformly, for both cases, rather than attempt
"safe reconciliation."** Reasoning: reconciliation would mean silently
falling some *other* preset the owner didn't touch back to a different
font, which is a surprising, unrequested mutation of a preset the owner
may return to later expecting it unchanged — inconsistent with this
whole contract's own "never permit... arbitrary" posture and with
Round 1's existing preference for hard, predictable boundaries over
best-effort magic. `PlatformThemeManager`'s delete-font guard therefore
queries `platform_theme_presets WHERE font_uploaded_id = ?` (all
statuses, not only `active`) and rejects the deletion with a specific
error naming which preset(s) — by name — still reference it, so the
owner can deliberately switch those presets to a different font first if
they truly want the upload gone.

### 6.17 NEW — Starter presets and seeding

The Factory preset **must** exist from first install — it is the
system's own recovery target and, per §6.14, the only source a truly
"different" preset can ever be duplicated from without hand-entering
every value. A new seeder, `PlatformThemePresetSeeder` (§9), creates it
directly: `name = 'Warm Red'`, `is_factory = true`, `status = 'active'`
(so a fresh install has a working, live theme identical to the approved
defaults from the very first request — a natural, zero-extra-mechanism
consequence of Factory simply being seeded as the initial active row),
`input_tokens_json`/`derived_tokens_json` populated from the exact
approved defaults (§6.5), `font_choice = 'bundled'`,
`font_bundled_key = 'geist'`.

The three additional starter presets (Clear Red, Warm Brown, Dark
Chocolate) are **also seeded by the same seeder**, as `status = 'saved'`
(not active — activating one is a deliberate, separate owner action),
`is_factory = false` (fully owner-editable/renameable/deletable from the
moment they exist, per "starter presets are convenience examples, not
locked palettes"). This resolves the correction's own "may define...
optional" language toward actually seeding them: "for convenient
testing" reads most naturally as "ready to activate or duplicate
immediately," not "a recipe the owner must hand-type" — and seeding
them costs nothing architecturally beyond three more rows through the
same, already-necessary seeder.

**Dark Chocolate's own requirement — "clean solid theme surfaces... do
not restore the chat illustration, embedded base64 pattern, or another
decorative background image" — is automatically satisfied, not a special
case to implement**: the chat background is fully removed at the
*runtime-binding/SCSS* layer by Slice 1's own §9 items (chat.scss/
chat-list.scss/dark-layout.scss no longer reference any background image
at all, for *any* preset, per Correction Round 1's own architecture).
Dark Chocolate is simply a set of token *values* like any other preset —
it has no mechanism by which it could reintroduce a background image
even if it wanted to, since no token in §6.5's taxonomy is
"background-image," only solid colors and validated alpha-capable
overlay/shadow tints (§4). This is confirmed here explicitly so it is
never mistaken for a gap requiring its own separate fix.

### 6.18 NEW — Preview isolation and font-preview access

"Previewing must not mutate the active theme or shared cache" is
satisfied structurally by §6.13's own split (preview is pure client-side
CSS manipulation, no persistence call exists for it to make). For **font**
preview specifically — rendering real text in an inactive preset's
chosen uploaded font while editing it — the public, unauthenticated
`theme-font/{safeId}` route (Round 1, §6.9) is deliberately scoped to
serve **only** the currently-active preset's font, so it cannot be
(ab)used for this. A second route is added:
`GET admin/theme-presets/{preset}/font-preview` (new controller action
on the already-allowlisted `PlatformThemeFontController`, not a new
controller file), living inside the `routes/admin.php` group and
therefore inheriting the full existing admin auth stack plus an explicit
`'manage theme'` check — satisfying "an authenticated, `manage
theme`-protected preview mechanism" precisely, and "must not make every
uploaded font publicly enumerable" by construction: this route is never
reachable by an unauthenticated request at all, unlike the public
active-font route, which by necessity is.

---

## 7. Open technical decisions (category-3 — resolved at implementation
## time within the constraints stated here, never silently guessed)

Carried forward, unchanged, from Correction Round 1: chart series-slot
default palette (8 values), bundled font list beyond Geist,
secondary/accent + header-override + live-preview-composition defaults,
and the exact runtime-bindings class list (a mechanical grep task at
implementation time).

**This round resolves, rather than leaves open, the one genuine choice
its own instructions explicitly offered** — font-reference deletion
guarding, resolved to "prevent uniformly" over "safely reconcile," with
reasoning, in §6.16. No new open decisions are introduced by presets
themselves; every preset-specific question (seed the three non-factory
starters or not; separate `rolled_back` event type or reuse `activated`;
soft- vs. hard-delete for presets) is resolved directly in §6.14/§6.17
with stated reasoning, rather than deferred.

---

## 8. Full rollout map (unchanged from Correction Round 1)

The 21-slice module map and its per-slice "eliminate your own hardcoded
colors/fonts as you migrate" mandate are entirely unaffected by adding
presets — presets are a Slice-1 management-layer concern sitting above
the same runtime token-emission mechanism every later slice already
consumes unchanged. No slice boundary, glob, or file count in §8 changes
this round.

---

## 9. Exact implementation allowlist (Slice 1 — reconciled for presets;
## the only implementation-ready scope in this contract)

**Closed, numbered, path-level. Any additional path required during
Slice 1 implementation is a STOP-and-report condition (§12).**

Six Correction-Round-1 items are **renamed** (same file slot, new name
and/or expanded schema/responsibility, explained inline); one is
**removed** (superseded by more general preset operations); nine are
**new**. Every other Round-1 item is unchanged in name, count, and role.

### Theme presets — schema, domain, service layer (11: 9 renamed/unchanged, 2 new)

1. `database/migrations/{timestamp}_create_platform_theme_presets_table.php` — **renamed and reschema'd** from `..._create_platform_theme_settings_table.php` (§6.1). Since no code has been written under any prior draft of this contract, this is a correction to the plan itself, not a migration-of-a-migration.
2. `database/migrations/{timestamp}_create_platform_theme_fonts_table.php` — unchanged.
3. `database/migrations/{timestamp}_create_platform_theme_preset_events_table.php` — **new** (§6.1).
4. `app/Models/PlatformThemePreset.php` — **renamed** from `PlatformThemeSetting.php`.
5. `app/Models/PlatformThemeFont.php` — unchanged.
6. `app/Models/PlatformThemePresetEvent.php` — **new**.
7. `app/Repositories/Contracts/PlatformThemePresetRepository.php` — **renamed** from `PlatformThemeSettingRepository.php`, expanded with create/duplicate/rename/activate/delete/findByStatus-style methods.
8. `app/Repositories/Eloquent/EloquentPlatformThemePresetRepository.php` — **renamed**, same expansion.
9. `app/Repositories/Contracts/PlatformThemeFontRepository.php` — unchanged.
10. `app/Repositories/Eloquent/EloquentPlatformThemeFontRepository.php` — unchanged file; its deletion-guard query is now cross-preset (§6.16), a logic change inside an already-allowlisted file, not a new path.
11. `database/seeders/PlatformThemePresetSeeder.php` — **new** (§6.17): seeds Factory (active) + the three optional starter presets (saved).

### Theme presets — services and exceptions (7: 6 unchanged, 1 new)

12. `app/Library/Theme/ThemeColorDerivationService.php` — unchanged, applied per-preset (§6.6).
13. `app/Library/Theme/ThemeContrastValidator.php` — unchanged; now invoked at both save and activate (§6.7).
14. `app/Library/Theme/PlatformThemeManager.php` — unchanged file; expanded with the full preset-lifecycle orchestration in §6.14.
15. `app/Library/Theme/ThemeFontValidator.php` — unchanged.
16. `app/Library/Theme/Exceptions/InvalidThemeColorException.php` — unchanged.
17. `app/Library/Theme/Exceptions/UnsafeThemeContrastException.php` — unchanged.
18. `app/Library/Theme/Exceptions/InvalidThemeFontException.php` — unchanged.
19. `app/Library/Theme/Exceptions/InvalidThemePresetOperationException.php` — **new** (§6.14: covers editing/deleting Factory, deleting the active preset, deleting a font another preset still references).

### Theme presets — HTTP surface (9: 3 renamed/unchanged, 1 removed, 4 new, 1 unchanged-with-new-action)

20. `app/Http/Controllers/Admin/PlatformThemePresetController.php` — **renamed** from `PlatformThemeSettingController.php`; gains index (list all presets + states)/store (create)/show/update (edit+save)/duplicate/rename/activate/destroy actions.
21. `app/Http/Controllers/Admin/PlatformThemeFontController.php` — unchanged file; gains one new `servePreview(PlatformThemePreset $preset)` action (§6.18) alongside its Round-1 upload/replace/delete/serve-active-font actions.
22. `app/Http/Requests/Admin/UpdatePlatformThemePresetRequest.php` — **renamed** from `UpdatePlatformThemeSettingRequest.php`; same hex/alpha/font-choice validation shape, now targeting one named preset instead of the implicit single theme.
23. `app/Http/Requests/Admin/CreatePlatformThemePresetRequest.php` — **new**.
24. `app/Http/Requests/Admin/DuplicatePlatformThemePresetRequest.php` — **new**.
25. `app/Http/Requests/Admin/RenamePlatformThemePresetRequest.php` — **new**.
26. `app/Http/Requests/Admin/ActivatePlatformThemePresetRequest.php` — **new**; also the request class used for the "return to previously active preset" rollback action, per §6.14 (same endpoint, no separate request class needed for what is mechanically the same operation).
27. ~~`app/Http/Requests/Admin/RestorePlatformThemeSettingRequest.php`~~ — **removed**. Its job (resetting the active theme back to the approved defaults) is now fully covered by two more general operations that already exist for every preset — Activate (targeting the Factory preset directly restores the exact approved defaults) and Duplicate (forking Factory into a new editable starting point) — so a bespoke "restore" endpoint would be a second, narrower code path doing what two already-necessary, more general ones already do.
28. `app/Http/Requests/Admin/UploadPlatformThemeFontRequest.php` — unchanged.
29. `app/Rules/ValidFontFileRule.php` — unchanged.
30. `routes/admin.php` — modified: the `theme-settings` route section (Round 1) becomes a `theme-presets` route section (index/store/show/update/duplicate/rename/activate/destroy) plus the new `manage theme`-gated font-preview route (§6.18); the existing `theme-fonts` route section (Round 1) is otherwise unchanged. No existing unrelated route line changed or reordered.
31. `routes/public.php` — modified: the one `theme-font/{safeId}` route (Round 1, unchanged in count) now resolves "the active font" via `platform_theme_presets WHERE status='active'` instead of the old table — an internal query change to an already-allowlisted route, not a new path.

### Theme presets — authorization config (1, unchanged)

32. `config/permissions.php` — unchanged from Correction Round 1; confirmed (§6.15) to need no new entry — every preset action reuses `'manage theme'`.

### Theme presets — views + JS (3: 2 unchanged-with-expanded-content, 1 new)

33. `resources/views/admin/theme-settings/index.blade.php` — unchanged file, expanded content: a preset-aware editor (loads whichever preset is currently open for editing, not implicitly "the one theme"), all six token categories (§4), font selector + upload, live preview, Cancel/Save/Activate/Delete actions as appropriate to the open preset's own state, unsaved-change warning, audit info. Still built entirely from the existing 13-component library.
34. `resources/views/admin/theme-settings/_preset-list.blade.php` — **new**: the preset switcher — lists every preset with its name and `status` badge (Draft/Saved/Active/Factory, visually distinguished per the explicit requirement), and the create/duplicate/rename/activate/delete entry points.
35. `resources/js/scripts/pages/theme-settings.js` — unchanged file, expanded: preset-switching, create/duplicate/rename/delete-confirm wiring, alongside the Round-1 picker/preview/contrast-check wiring.

### Runtime-bindings retrofit layer (2, unchanged from Correction Round 1)

36. `resources/scss/base/tokens/_runtime-bindings.scss`
37. `resources/scss/base/tokens.scss`

### Token taxonomy expansion (2, unchanged from Correction Round 1)

38. `resources/scss/base/tokens/_colors.scss`
39. `resources/scss/base/tokens/_typography.scss`

### Bootstrap variable retokenization (1, unchanged from Correction Round 1)

40. `resources/scss/base/bootstrap-extended/_variables.scss`

### Chart token bridge (9, unchanged from Correction Round 1)

41. `resources/js/core/theme-tokens.js`
42. `resources/views/admin/dashboard.blade.php`
43. `resources/views/customer/dashboard.blade.php`
44. `resources/views/customer/Reports/charts.blade.php`
45. `resources/views/customer/Reports/analyze.blade.php`
46. `resources/views/admin/Reports/overview.blade.php`
47. `resources/views/admin/Reports/dashboard.blade.php`
48. `resources/views/customer/Campaigns/overview.blade.php`
49. `resources/views/customer/Automations/overview.blade.php`
50. `resources/js/core/app.js`

### Chat background remediation (3, unchanged from Correction Round 1)

51. `resources/scss/base/pages/app-chat.scss`
52. `resources/scss/base/pages/app-chat-list.scss`
53. `resources/scss/base/themes/dark-layout.scss` — the one deliberate, documented exception to the Milestone-1 "never touch the dark bundle" default, unchanged in scope.

### Remaining Feather→Lucide icon migration in shared chrome (7, unchanged)

54. `resources/views/panels/navbar.blade.php`
55. `resources/views/panels/sidebar.blade.php`
56. `resources/views/panels/footer.blade.php`
57. `resources/views/panels/breadcrumb.blade.php`
58. `resources/views/panels/submenu.blade.php`
59. `resources/views/panels/horizontalMenu.blade.php`
60. `resources/views/panels/horizontalSubmenu.blade.php`

### Errors module (7, unchanged)

61. `resources/views/errors/401.blade.php`
62. `resources/views/errors/403.blade.php`
63. `resources/views/errors/404.blade.php`
64. `resources/views/errors/419.blade.php`
65. `resources/views/errors/429.blade.php`
66. `resources/views/errors/500.blade.php`
67. `resources/views/errors/503.blade.php`

**Total: 67 files** (Correction Round 1's 59, minus 1 removed
[`RestorePlatformThemeSettingRequest.php`], plus 9 new
[preset-events migration, `PlatformThemePresetEvent` model,
`PlatformThemePresetSeeder`, `InvalidThemePresetOperationException`,
three preset FormRequests (Create/Duplicate/Rename/Activate — four,
not three; see exact count below), `_preset-list.blade.php` partial] —
reconciled exactly: 59 − 1 + 9 = 67). No file touched by any *other*
mechanism — chart tokens, chat background, runtime-bindings retrofit,
icon migration, errors — changes in count; every one of those sections'
paths is identical to Correction Round 1. Any path beyond this 67-item
list required during Slice 1 implementation is a required-68th-path-
shaped stop condition (§12).

*(Exact new-item count check: migration #3, model #6, seeder #11,
exception #19, requests #23/#24/#25/#26 [four], view #34 = 9 new items;
removed: #27's predecessor = 1. 59 − 1 + 9 = 67, matching the total
above.)*

---

## 10. Test contract (Slice 1 — expanded for presets)

Every test from Correction Round 1's §10 (Authorization, Validation,
Derivation accuracy, Persistence, Audit, Isolation, Rendering/fail-safe,
Chart tokens, Chat background, Font upload validation/serving/runtime
application, Runtime-bindings retrofit, Status-color derivation, Cache
invalidation) is unchanged in kind, now exercised per-preset where
relevant, **plus**:

- **Preset CRUD**: `PlatformThemePresetLifecycleTest` — create seeds a
  `draft` row + `created` event; duplicate (including duplicating
  Factory) seeds a new `draft` row with copied values + a `duplicated`
  event referencing the source; edit updates in place + an `edited`
  event; rename updates only `name` + a `renamed` event and is rejected
  for Factory.
- **Activation atomicity**: `PlatformThemePresetActivationTest` — after
  activating preset B while A was active, exactly one row has
  `status = 'active'` (A is `'saved'`, not deleted); colors and font
  switch together; an `activated` event is recorded with the correct
  `previous_active_preset_id`; a hard-reject-contrast preset cannot be
  activated even though it could be saved.
- **Rollback**: confirms "return to the previously active preset"
  reactivates the correct prior preset and records a distinct
  `rolled_back` event type.
- **Deletion guards**: `PlatformThemePresetDeletionTest` — deleting the
  active preset is rejected; deleting Factory is rejected; deleting an
  inactive, non-factory preset succeeds; deleting an uploaded font
  referenced by the active preset is rejected; deleting one referenced
  by a different *saved* (non-active) preset is also rejected, naming
  that preset in the error (§6.16).
- **Preview isolation**: `PlatformThemePresetPreviewIsolationTest` —
  editing/previewing a non-active preset never changes the shared cache
  key or any other user's rendered theme; the admin font-preview route
  (§6.18) requires `'manage theme'` and 401/403s otherwise; the public
  font route never serves a non-active preset's font regardless of its
  `safeId`.
- **Starter seeding**: `PlatformThemePresetSeederTest` — after seeding,
  exactly one `is_factory = true, status = 'active'` row exists with the
  exact approved default values, and the three starter presets exist as
  `status = 'saved', is_factory = false`.
- Full existing suite re-run, exact pass count compared against the
  pre-Slice-1 baseline (2,724 passed / 8,672 assertions) — zero
  regressions permitted, unchanged discipline.

---

## 11. Mechanical searches (Slice 1 — expanded for presets)

All searches from Correction Round 1's §11 (unchanged in kind) **plus**:

15. A runtime/query-level check (not a static grep — this is a database
    invariant): after any sequence of preset operations in the test
    suite, `SELECT COUNT(*) FROM platform_theme_presets WHERE
    status = 'active'` is always exactly `1`, never `0` or `2+`.
16. `grep -c "is_factory" app/Library/Theme/PlatformThemeManager.php` →
    present in both the edit-guard and delete-guard code paths (proves
    Factory's protection is enforced in the domain layer, not only
    incidentally by a FormRequest rule that a future direct-model call
    could bypass).
17. Final changed-path set equals this contract's own **67-item**
    allowlist (§9), not Correction Round 1's 59.

---

## 12. Stop conditions

All conditions from Correction Round 1's §12 (unchanged in kind, now
evaluated against the 67-item allowlist) **plus**:

- More than one `platform_theme_presets` row is ever observed with
  `status = 'active'` at rest (i.e., outside the single transaction that
  briefly touches two rows during an activation) — the atomicity
  guarantee has failed and must be fixed before proceeding.
- Any code path permits editing or deleting the `is_factory = true` row
  directly, or deleting a `status = 'active'` row, or deleting an
  uploaded font still referenced by any preset regardless of that
  preset's own status.
- Saving or previewing a non-active preset is found to invalidate the
  shared active-theme cache key, or to be visible to any user other than
  the editor before that preset is actually activated.
- §7's carried-forward open decisions remain unconfirmed before
  implementation begins (unchanged from Correction Round 1).

---

## 13. Contract self-audit

1. Every requirement in §4.6 is addressed by a numbered decision in
   §6.14-§6.18 and a numbered path in §9. ✓
2. Correction Round 1's complete token/runtime-binding/font-control/
   upload-safety/accessibility/rollout architecture (§6.1-§6.13, §8) is
   preserved — this round's changes to §6.1/§6.3/§6.7/§6.9/§6.13 are
   explicitly marked as either "unchanged mechanism, updated source
   query" or "clarified, not altered," never a silent redefinition. ✓
3. The one genuine open choice the correction's own instructions offered
   (font-deletion guarding: prevent vs. reconcile) is resolved with
   stated reasoning (§6.16), not left open and not silently picked
   without explanation. ✓
4. No new permission string was introduced — confirmed explicitly
   (§6.15), not merely assumed, by tracing every preset action against
   the existing `'manage theme'` gate. ✓
5. **Allowlist reconciliation is exact and shown, not asserted**: 59
   (Correction Round 1) − 1 removed (`RestorePlatformThemeSettingRequest.php`,
   superseded by Activate+Duplicate, §9 item 27's own note) + 9 new
   (§9's own itemized list, cross-checked against §9's closing
   parenthetical) = **67**. ✓
6. Presets are confirmed as a Slice-1 management-layer addition only —
   §8's 21-slice rollout map, its module boundaries, and every later
   slice's own "eliminate hardcoded colors/fonts as you migrate" mandate
   are unaffected, since no rollout-slice file anywhere in §9 changes
   this round. ✓
7. No business logic, permission model, route behavior, tenant
   isolation, or data-flow of any existing feature changes anywhere in
   §9. ✓
8. This document remains the only file changed on this branch, across
   all three drafting passes (§2). ✓
9. This is correction round 2 of this contract's own stated
   `maximum_correction_rounds: 2` — the final round available under this
   contract's own governance, noted explicitly in §0. ✓

---

## 14. Verification and publication (this document only)

1. `git status` on `chore/design-system-m2-contract` shows exactly one
   changed path: this file.
2. Stage individually (`git add docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`),
   never `git add -A`/`.`.
3. Commit message: `docs: add theme presets to design system M2 contract`.
4. This correction is committed **separately** from both prior commits
   (original + Correction Round 1), never amended/squashed into either.
5. Push to `origin chore/design-system-m2-contract` — a normal push,
   never force-pushed.
6. Provide the compare URL (no `gh` available in this environment).
7. **Do not merge. Do not implement Slice 1. Do not edit production or
   test files. Do not run migrations. Do not start any later rollout
   slice.** All require separate, explicit, future authorization.

---

## 15. What Correction Round 1 changed (unchanged summary, for continuity)

Replaced the original 5-color-plus-7-surface palette with a complete
semantic-token system plus global font control; found and fixed the
critical compile-time-vs-runtime color gap (§3.9/§6.10); designed font
upload from scratch against direct evidence of no existing content-
validation or per-file authorization pattern; generalized the data model
to a flat semantic-token map; grew the allowlist from 46 to 59 files,
fully reconciled.

## 16. What Correction Round 2 changed, and why (this round)

- **Added**: named theme presets — create/duplicate/rename/edit/preview/
  save/activate/rollback/delete, Draft/Saved/Active/Factory states,
  atomic activation with immediate cache invalidation and previous-theme
  preservation, a protected Factory preset, four optional starter
  presets (including the dark "Dark Chocolate" palette, confirmed to
  need no special handling since the chat-background/decorative-image
  removal is already structural, not per-preset), font-reference
  guarding across multiple presets, and an authenticated font-preview
  mechanism for inactive presets.
- **Data model**: `platform_theme_settings` (Round 1's append-only
  activation log) reconciled into `platform_theme_presets` (a genuinely
  mutable, named entity table) plus a new, separate append-only
  `platform_theme_preset_events` table carrying forward Round 1's
  original "immutable audit trail" role in a form that actually fits
  multiple independently-existing presets.
- **Explicitly unchanged**: every Round 1 architecture decision governing
  the token taxonomy, the runtime-bindings retrofit, font upload safety
  mechanics, contrast-validation formulas, the canonical JS token source,
  and the 21-slice rollout map — per this round's own explicit
  instruction not to touch any of it.
- **Allowlist**: 59 → **67** files (1 removed as genuinely superseded, 9
  added, all traced to a specific new requirement in §4.6), recalculated
  and shown exactly in §9's own closing paragraph and §13's self-audit,
  not asserted.
