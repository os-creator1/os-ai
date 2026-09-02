# Design System — Milestone 2, Slice 5 Contract: Contacts & CRM

**This document is fully self-contained.** No section below requires consulting an earlier commit, the Milestone 1 contract, the Milestone 2 contract, or any other slice contract to understand Slice 5's complete rules — every requirement, architecture decision, and path is restated here in full.

**Status: contract only. No implementation has occurred under this document. Merging this contract does NOT authorize implementation. In addition, per §7 below, Slice 5 implementation cannot be authorized AT ALL until a separate, dedicated CRM tenant-isolation security remediation is drafted, human-reviewed, human-merged, and its own implementation human-merged — a materially stronger precondition than a normal "implementation needs its own authorization" gate, because the defect found here sits directly inside the controllers this slice would restyle.**

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice5-contacts-crm-contract`, in an isolated linked worktree (`../design-system-m2-slice5-contract-worktree`), based on `origin/main` at `437f12a51b8d036db055ba6ddafd89ab2ec9199a` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` at the start of this drafting pass, and re-confirmed unchanged (`git rev-parse HEAD` == `git rev-parse origin/main`) immediately before this document was finalized. This SHA is the Design System M2 Slice 3 (Dashboards) implementation-correction merge (PR #174, `git log` message "Merge pull request #174 from os-creator1/agent/design-system-m2-slice3-dashboard").
- **Slice 4 (Reports & Analytics) is deliberately, explicitly skipped by human choice.** This is not an oversight or a numbering accident — the human authorizing this contract selected Contacts & CRM as the next visual rollout ahead of Reports & Analytics. This contract does not touch, contract, or authorize anything under `customer/Reports/**` or `admin/Reports/**`.
- Slice 5 is the rollout-map group named **"Contacts & CRM"** in `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` §8, historically listed there as **30 files** across globs `customer/{Contacts,contactGroups,Blacklists,opportunities}/**`, `admin/{opportunities,Blacklists}/**`. That historical figure is **not trusted** — §3.1 below mechanically re-derives the current tree from scratch, including every nested directory and partial (`Contacts/import/**`, `admin/opportunities/runs/**`, every `contactGroups/**` partial). The re-derived count happens to match the historical figure exactly (30 files), but only because it was independently re-counted, not because the old number was assumed correct.
- `maximum_correction_rounds: 2` applies to this contract. `advance_automatically: false`. `start_automatically_after_contract_merge: false`. Merging this contract never starts implementation by itself, human or automated.
- Any path required during Slice 5 implementation but absent from §9's own numbered allowlist is a stop-and-report condition — not a silent workaround. The stop threshold is the **35th** path (34 allowlisted + 1).
- This contract authorizes **only** drafting this one document. It does not authorize Reports & Analytics (Slice 4), ChatBox/Conversations (Slice 6), Campaigns (Slices 7a-c), Automations (Slice 8), Templates (Slice 9), Numbers/SenderID/Keywords/Compliance (Slice 10), Sending Servers (Slices 11a-d), Billing/Payments/Accounts (Slice 12), Sub-Accounts & Workspaces (Slice 13), Onboarding (Slice 14), Developer/API Docs (Slice 15), Admin Tenant Management (Slice 16), Plans/Pricing/Catalog (Slice 17), Invoices & Subscriptions (Slice 18), Admin Users/Roles/Announcements (Slice 19), Plugins/Legacy Theme Customizer (Slice 20), System Settings (Slice 21), or transactional email templates (permanently out of scope for the entire rollout). It also does not authorize any COO, SEO, Outreach, Website Generator, Calendar, Ads, or any other new RFC/initiative outside the Design System track entirely, and it makes zero change to `docs/automation/AI-AUTONOMY-STATE.json`.
- **This contract does not fix, and is not authorized to fix, the severe pre-existing cross-tenant authorization gap documented in §3.8 below.** That gap is recorded as an explicit, isolated finding, exhaustively documented as audit evidence of the state found at this contract's own base SHA — not silently folded into this presentation-migration slice, and not silently left unmentioned. **A separate, dedicated CRM tenant-isolation security-remediation contract must be drafted, human-reviewed, and merged, and its own implementation must be human-merged, before Slice 5 implementation may be authorized** (§7). This mirrors the Design System M2 Slice 3 dashboard-security precedent exactly (`docs/automation/DESIGN-SYSTEM-M2-SLICE-3-DASHBOARD-SECURITY-REMEDIATION-CONTRACT.md`), and is, if anything, a stricter case: the Slice 3 defect lived in three *adjacent* pages reachable from the dashboard; this slice's defect lives directly inside the very controllers (`ContactsController`, `BlacklistsController`) whose views this slice would restyle.

---

## 1. Mandatory preflight — verified

1. `git fetch origin --tags` — `origin/main` confirmed at exactly `437f12a51b8d036db055ba6ddafd89ab2ec9199a`.
2. `git worktree add -b chore/design-system-m2-slice5-contacts-crm-contract ../design-system-m2-slice5-contract-worktree origin/main` — clean, no collision; `HEAD` confirmed equal to `origin/main` both immediately after creation and again immediately before this document's own commit.
3. Read in full before drafting: `CLAUDE.md`, `AGENTS.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md` (Milestone 1 — token/component/icon foundation, 42-item Milestone 1 allowlist), `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` (Milestone 2 architecture: theme presets, runtime CSS injection, the 67-item Slice 1 foundation allowlist, and the full 21-slice rollout map), `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-CONTRACT.md` (the most recent, directly-analogous prior slice contract — its audit methodology, security-blocker section shape (§7), and allowlist/test-contract conventions are the direct template this document follows, adapted to this slice's own actual findings, never copied blindly).
4. Read, directly, the actual merged component library and token/icon infrastructure — not assumed from contract prose. Specifically read in full: all 19 files currently in `resources/views/components/*.blade.php` (`alert`, `badge`, `branding-favicon`, `branding-footer`, `branding-illustration`, `branding-logo`, `button`, `card`, `dialog`, `ds-icon`, `empty-state`, `input`, `menu`, `pagination`, `select`, `switch-toggle`, `table`, `tabs`, `tooltip` — **5 more than the 14 read during the Slice 3 audit**: `dialog`, `menu`, `pagination`, `tabs`, `tooltip` did not exist in Slice 3's own component count table, and the four `branding-*` components are new since Slice 3 and unrelated to CRM adoption, confirmed by grep — zero references to any `branding-*` component anywhere in `resources/views/customer/Contacts`); `resources/js/core/theme-tokens.js`'s real shipped API (`window.PlatformTheme.color(name, fallback)`/`.primary()`/`.secondary()`/`.chartPalette()`/`.chartNeutral()`/`.chartGrid()`/`.chartAxis()`/`.chartTooltipBg()`/`.chartTooltipText()`); `resources/scss/base/tokens/_colors.scss`, confirming Design System M2 Slice 1 (theme presets, tokens, the `_runtime-bindings.scss` retrofit layer) is genuinely merged into `origin/main` at this contract's own base SHA (`resources/scss/base/tokens/_runtime-bindings.scss` exists on disk, `database/migrations/2026_08_17_160001_create_platform_theme_presets_table.php` and its two sibling migrations exist, `app/Library/Theme/PlatformThemeManager.php` and its four sibling classes exist) — this is a materially important, directly-verified fact this contract's own scope decisions depend on (§3.4).
5. Mechanically re-derived the complete current Slice-5 file inventory directly from the filesystem (§3.1) — never trusted the M2 Milestone contract's own historical "30" figure without independent re-verification.
6. Delegated the bulk per-file mechanical inventory, and the tenant-isolation/security audit, to three parallel, isolated-worktree research agents (matching the established multi-agent audit pattern this repository's own prior Design System contracts have used), with the most consequential findings — the security/tenant-isolation conclusions (§3.8), the `_settings.blade.php` file-count discrepancy, the icon count, and the current component library's exact contents — independently re-verified first-hand in this same drafting pass, not taken on the agents' word alone.

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` (this document). No `resources/`, `app/`, `database/`, `routes/`, or `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting this contract. No other existing docs file is edited.

---

## 3. Mandatory repository audit — findings

All findings below are from direct repository inspection at `origin/main` `437f12a51b8d036db055ba6ddafd89ab2ec9199a`, this drafting pass — file:line citations throughout where available, cross-checked by independent, first-hand mechanical commands run directly in this worktree, not summarized from memory or from any prior document's claims.

### 3.1 Current file inventory — mechanically re-derived, independently confirmed

Direct `find`/`wc -l` across all six owned globs, confirmed with no other Contacts/Blacklist/Opportunity-named directory existing anywhere else in `resources/views` (checked via `find resources/views -iname "*contact*" -o -iname "*blacklist*" -o -iname "*opportunit*" -type d`):

**`resources/views/customer/Contacts/` — 8 files, 1,290 lines:**

| # | File | Lines |
|---|---|---|
| 1 | `create.blade.php` | 209 |
| 2 | `import.blade.php` | 48 |
| 3 | `import/mapping.blade.php` | 154 |
| 4 | `import_file.blade.php` | 147 |
| 5 | `paste_text.blade.php` | 166 |
| 6 | `show.blade.php` | 209 |
| 7 | `subscribe_form.blade.php` | 252 |
| 8 | `unsubscribe_form.blade.php` | 105 |

**`resources/views/customer/contactGroups/` — 12 files, 3,262 lines:**

| # | File | Lines |
|---|---|---|
| 1 | `_contacts.blade.php` | 228 |
| 2 | `_fields.blade.php` | 182 |
| 3 | `_form_fields.blade.php` | 76 |
| 4 | `_import_history.blade.php` | 60 |
| 5 | `_message.blade.php` | 105 |
| 6 | `_opt_in_keywords.blade.php` | 39 |
| 7 | `_opt_out_keywords.blade.php` | 39 |
| 8 | `_segments.blade.php` | **0 (empty file — confirmed dead stub, §3.6)** |
| 9 | `_settings.blade.php` | 172 |
| 10 | `create.blade.php` | 137 |
| 11 | `index.blade.php` | 651 |
| 12 | `show.blade.php` | 1,573 |

`_settings.blade.php` was flagged mid-audit as a possible transcription gap against the M2 rollout map's own historical "30" figure; it is now directly, mechanically confirmed present on disk (`ls resources/views/customer/contactGroups/` — 12 entries) and is genuinely `@include`d by `show.blade.php` (§3.6). It is real, in scope, and numbered in this inventory.

**`resources/views/customer/Blacklists/` — 2 files, 513 lines:** `create.blade.php` (109), `index.blade.php` (404).

**`resources/views/customer/opportunities/` — 2 files, 457 lines:** `index.blade.php` (93), `show.blade.php` (364).

**`resources/views/admin/opportunities/` — 4 files, 580 lines:** `index.blade.php` (97), `show.blade.php` (278), `runs/index.blade.php` (74), `runs/show.blade.php` (131).

**`resources/views/admin/Blacklists/` — 2 files, 505 lines:** `create.blade.php` (111), `index.blade.php` (394).

**Total: 30 files, 6,607 lines** (1,290 + 3,262 + 513 + 457 + 580 + 505 = 6,607) — independently re-derived and confirmed equal to the M2 Milestone contract's own historical figure, but by direct re-count, not by trusting that document.

### 3.2 Current Design System component library — re-verified directly, 5 more components than Slice 3 saw

19 components exist in `resources/views/components/` today (up from 14 at Slice 3's own drafting time): `alert`, `badge`, `branding-favicon`, `branding-footer`, `branding-illustration`, `branding-logo`, `button`, `card`, `dialog`, `ds-icon`, `empty-state`, `input`, `menu`, `pagination`, `select`, `switch-toggle`, `table`, `tabs`, `tooltip`. `dialog`, `menu`, `pagination`, `tabs`, and `tooltip` are the 5 new ones (all shipped as part of Milestone 1's own original 42-item allowlist but not yet exercised by any prior slice audit's own component table); the 4 `branding-*` components are Platform Branding & Assets-era additions, confirmed by grep to have zero references anywhere in this slice's own 30 files — not relevant to CRM adoption.

Each component's exact prop API, re-read directly from source in this drafting pass:

- **`<x-card :title :padded>`** — optional header (`title` + `actions` slot), body wrapped in `.card-body` unless `padded=false`, optional `footer` slot. Emits `ds-card` marker class.
- **`<x-table :headers>`** — wraps `<table>` in `.table-responsive`, optional `<thead>` built from a flat `headers` array, `<tbody>` is always the caller's own slot content. Merges caller-supplied classes (e.g. `class="datatables-basic"` survives alongside `ds-table`). Emits `ds-table` marker class.
- **`<x-alert :variant :icon :dismissible>`** — `variant` ∈ `neutral|accent|success|warning|danger`, flat icon+slot layout, no separate heading region. Emits `ds-alert` marker class.
- **`<x-badge :variant>`** — `variant` ∈ `neutral|accent|success|warning|danger`, renders a `rounded-pill` light-tint badge.
- **`<x-select :label :name :options :selected :help>`** — labeled `<select class="form-select">`, `options` is a flat `value => text` array.
- **`<x-input :label :name :type :help :error>`** — labeled single-line `<input>`. **No `textarea` type support** — confirmed by direct read, `type` is passed straight to the `<input type="...">` attribute, which cannot express a multi-line control.
- **`<x-button :variant :size :type :href :icon :disabled>`** — `variant` ∈ `primary|secondary|outline|ghost|danger`. **No `success`/`warning`/`info` variant** — confirmed absent from the `match()` block, same gap Slice 3 found and left unclosed (extending the shared library is out of any single slice's own authority).
- **`<x-empty-state :icon :title :description>`** plus optional `action` slot. Emits `ds-empty-state` marker class.
- **`<x-ds-icon :name :size :strokeWidth>`** — the one centralized Lucide rendering seam, backed by `technikermathe/blade-lucide-icons` `v3.166.0` (confirmed in `composer.lock`) on `blade-ui-kit/blade-icons` `v1.10.1`.
- **`<x-dialog :id :title :size>`** — Bootstrap-native modal markup, `sm|md|lg` size, optional `footer` slot. Emits `ds-dialog` marker class.
- **`<x-menu :label :icon :align>`** — Bootstrap-native dropdown markup. Emits `ds-menu`/`ds-menu-list` marker classes.
- **`<x-tabs :tabs :active :id>`** — Bootstrap-native nav-tabs markup, `tabs` is a flat `key => label` array. Emits `ds-tabs` marker class. **Not a nav-pills component** — this slice's own tab UI (`contactGroups/show.blade.php`, §3.6) uses nav-*pills*, a related but visually distinct Bootstrap pattern `<x-tabs>` does not directly emit (§6 adoption decision).
- **`<x-tooltip :text :placement>`** — wraps arbitrary slot content in a `data-bs-toggle="tooltip"` span. Emits `ds-tooltip-trigger` marker class.
- **`<x-pagination :paginator>`** — accepts a `LengthAwarePaginator` directly, renders a compact "X of Y" + prev/next control. **Native Laravel-paginator shaped** — a clean, direct fit for `customer/opportunities/index.blade.php` and `admin/opportunities/index.blade.php`/`runs/index.blade.php`, all three of which already use `$collection->appends(...)->links()` (§3.9), not DataTables.

No textarea, no native `<input type="file">`/Dropzone wrapper, no DataTables-shell wrapper, and no Select2/Flatpickr-aware component exist among the 19 — confirmed, and unchanged in kind from Slice 3's own confirmed gap (§6 adoption decisions).

### 3.3 Color/token audit — zero hardcoded literals found, zero new SCSS required

Both audit agents independently confirmed, file-by-file, across all 30 files: **zero** hardcoded hex/`rgb()`/`rgba()`/`hsl()` color literals, and **zero** `font-family` declarations, anywhere in this slice's scope. The only `style="..."` attributes found are non-color, layout-only (`max-width: 260px`/`max-width: 200px` on two opportunity-detail form controls, `width: 1%`/`100px`/`28%` column-width hints in `_fields.blade.php`/`_form_fields.blade.php`, one small `.customized_select2` CSS block in `contactGroups/show.blade.php` using only unitless/rem values). **This means Slice 5 requires zero new token file, zero `_colors.scss` change, and zero `_runtime-bindings.scss` change** — a materially different, simpler starting position than Slice 3's own 4-hardcoded-literal finding, made possible because Design System M2 Slice 1 (confirmed merged at this contract's own base SHA, §1 item 4) already retokenizes every native Bootstrap class this slice's markup uses (`.btn-*`, `.badge-light-*`, `.form-control`, `.table`, `.dropdown-menu`, `.modal-content`, etc.) via `_runtime-bindings.scss`. `grep -rn "PlatformTheme"` across all 30 files returns zero matches — no chart/JS-level color token bridging is needed anywhere in this slice, since none of these 30 files render any chart.

### 3.4 Icon audit — exhaustive, mechanically verified

`grep -roE 'data-feather="[a-zA-Z0-9-]*"'` across all six owned directories: **79 total occurrences, 30 distinct icon names**:

`calendar`, `check`, `check-square`, `clock`, `copy`, `download`, `edit-3`, `eye`, `file-text`, `hash`, `info`, `message-circle`, `move`, `pie-chart`, `plus-circle`, `refresh-cw`, `save`, `server`, `settings`, `square`, `stop-circle`, `trash`, `trash-2`, `type`, `upload`, `user-check`, `user-minus`, `user-x`, `users`, `x`.

Per-file breakdown (only files with at least one occurrence shown; every other file in §3.1's inventory has zero):

| File | Occurrences |
|---|---|
| `customer/contactGroups/_contacts.blade.php` | 16 |
| `customer/contactGroups/show.blade.php` | 13 |
| `customer/contactGroups/_fields.blade.php` | 12 |
| `customer/contactGroups/index.blade.php` | 5 |
| `customer/contactGroups/_form_fields.blade.php` | 6 |
| `admin/Blacklists/index.blade.php` | 3 |
| `customer/Contacts/paste_text.blade.php` | 3 |
| `customer/Contacts/import.blade.php` | 2 |
| `customer/contactGroups/_import_history.blade.php` | 2 |
| `customer/contactGroups/_opt_in_keywords.blade.php` | 2 |
| `customer/contactGroups/_opt_out_keywords.blade.php` | 2 |
| `customer/Blacklists/create.blade.php` | 2 |
| `customer/Blacklists/index.blade.php` | 2 |
| `admin/Blacklists/create.blade.php` | 2 |
| `customer/Contacts/create.blade.php`, `import/mapping.blade.php`, `import_file.blade.php`, `show.blade.php`, `contactGroups/_message.blade.php`, `_settings.blade.php`, `create.blade.php` | 1 each |
| **`customer/opportunities/*`, `admin/opportunities/*` (all 6 files)** | **0 — confirmed zero icons anywhere in the Opportunity surface** (uses an HTML `&larr;` entity instead of an icon for its one back-link). |
| `customer/Contacts/subscribe_form.blade.php`, `unsubscribe_form.blade.php` | **0 — image/branding-asset-based, not icon-font-based** (`{{asset(config('app.logo'))}}` plus a theme-conditional illustration SVG). |

`technikermathe/blade-lucide-icons` is pinned at `v3.166.0` (confirmed in `composer.lock`, same version Slice 3 confirmed). `vendor/` is not installed in this docs-only drafting worktree (this contract makes zero code changes and does not need it) — **implementation must mechanically verify every one of the 30 distinct names above against `vendor/technikermathe/blade-lucide-icons/resources/svg/*.svg` before mapping, never guess.** One name is flagged as a specific, elevated risk of a compound-name reordering, following the exact same failure pattern Slice 3 flagged for `x-circle`/`check-circle` (Lucide has, in some releases, reordered `adjective-noun` compound icon names to `noun-adjective`, while typically preserving the old name as a working alias): **`check-square`** is the one name in this slice's own 30-name list sharing that exact `adjective-noun` shape; `edit-3`/`trash-2` (numeric-suffixed) and `user-x`/`user-check`/`user-minus` (`noun-adjective` shape already) are lower-risk by the same precedent's own reasoning, but all 30 must still be mechanically verified, not merely the flagged one.

### 3.5 DataTables / Select2 / Flatpickr / SweetAlert2 / Dropzone / modal / tabs audit

Both audit agents confirmed, file-by-file, the following plugin usage (no plugin is loaded-but-unused anywhere in this slice, unlike Slice 3's own finding for a `buttons.html5.min.js` dead include on `customer/Blacklists/index.blade.php` — noted here since it recurs: that dead vendor include is confirmed present but never instantiated with a `buttons:` key; it is a pre-existing, harmless, out-of-scope inclusion, not touched by this slice):

- **DataTables (server-side, `serverSide:true`)**: `contactGroups/index.blade.php` (`.datatables-basic`, AJAX → `customer.contacts.search`), `contactGroups/show.blade.php` (`.datatables-basic`, AJAX → `customer.contact.search`, plus the `colvis` button extension), `Blacklists/index.blade.php` ×2 (customer AJAX → `customer.blacklists.search`; admin AJAX → `admin.blacklists.search`, with an extra `user_id`/"listed by" column). All four use the `datatables.checkboxes` extension for bulk row-selection and a custom `responsive.details.display` modal renderer.
- **DataTables (client-side, no `serverSide`)**: `contactGroups/show.blade.php`'s two keyword tables (`.opt-in-keywords`, `.opt-out-keywords`), operating on pre-rendered `<tbody>` markup from `_opt_in_keywords.blade.php`/`_opt_out_keywords.blade.php`.
- **Native Laravel paginator (no DataTables at all)**: `customer/opportunities/index.blade.php`, `admin/opportunities/index.blade.php`, `admin/opportunities/runs/index.blade.php` — all three render a plain server-side `@foreach`/`@forelse` table plus `{{ $collection->appends(...)->links() }}` (§3.2's `<x-pagination>` fit).
- **Select2**: `Contacts/import/mapping.blade.php` (one per CSV-column mapping dropdown, dynamic count), `Contacts/subscribe_form.blade.php`, `contactGroups/_message.blade.php` (×2, merge-tag helper + message-type select), `contactGroups/show.blade.php` (bulk copy/move modals' group-picker selects, plus the export modal's multi-select field picker), `contactGroups/_settings.blade.php` (not deep-audited in this pass — flagged for implementation-time direct confirmation, since it was outside the original 8-partial audit scope until its existence was independently confirmed, §3.1).
- **Flatpickr**: `Contacts/create.blade.php`, `Contacts/show.blade.php` (both `.datetime`/`.date` custom-field inputs), `Contacts/subscribe_form.blade.php`.
- **SweetAlert2**: `contactGroups/index.blade.php` (copy/delete/bulk-enable/bulk-disable/bulk-delete — 5 flows), `contactGroups/show.blade.php` (**11 distinct flows**: delete subscriber, delete custom field, bulk subscribe/unsubscribe/copy/move/delete, add/delete opt-in keyword, add/delete opt-out keyword), `Blacklists/index.blade.php` ×2 (single-delete + bulk-delete, both portals).
- **Dropzone.js**: `Contacts/import_file.blade.php` only (`maxFilesize:500MB`, `acceptedFiles:".csv"`, `maxFiles:1`), chained via its own success callback into a second AJAX call that fetches and injects `import/mapping.blade.php`'s rendered HTML.
- **Bootstrap modal**: `contactGroups/_contacts.blade.php`'s `#exportContactModal` (CSV field-picker export form) is the **only** genuine Bootstrap `.modal` markup anywhere in this slice — a direct `<x-dialog>` fit (§6).
- **Tabs (nav-pills, not Bootstrap nav-tabs)**: `contactGroups/show.blade.php` (8 panes: contact/settings/message/segments-orphaned/fields/opt-in/opt-out/import-history) and `Contacts/import.blade.php`/`paste_text.blade.php` (a 2-item nav-pills pair, one of which is actually a cross-page link, not an in-page tab toggle, per the audit's own finding). None of these use `<x-tabs>`'s underlying nav-*tabs* markup (§3.2) — a genuine, disclosed non-adoption (§6).
- **Tooltips (`data-bs-toggle="tooltip"`)**: exactly one, in `contactGroups/_contacts.blade.php` (the "force export phone number" info icon) — a direct `<x-tooltip>` fit.

### 3.6 `contactGroups/show.blade.php` — decomposition analysis and decision

1,573 lines, confirmed by direct read: lines 1-34 are `@extends`/style/page-style (one small, non-color inline `<style>` block); lines 36-234 are the nav-pills tab-header markup plus 8 `@include`/tab-pane wrappers; lines 238-256 are `vendor-script` tags; **lines 260-1572 (≈1,313 lines, 83% of the file) are a single, uninterrupted `page-script` block** containing 3 DataTables initializations, 11 SweetAlert2 confirmation flows, ~10 distinct AJAX call sites, and the Select2/Flatpickr/merge-tag setup.

**Partial cross-reference, confirmed directly:** the file `@include`s 8 partials — `_contacts`, `_settings`, `_message`, `_segments`, `_fields`, `_opt_in_keywords`, `_opt_out_keywords`, `_import_history` — plus fetches a 9th, `_form_fields.blade.php`, exclusively via a client-side AJAX call to `ContactsController::contactSampleField()` (never via Blade `@include` anywhere in the repository, confirmed by an exhaustive grep). All 9 partials named in this slice's own §3.1 inventory are therefore genuinely reachable, live, and correctly attributed to this one parent page.

**Decision, per the default "prefer existing boundaries, do not invent partials for aesthetics" instruction:** the existing 9-partial tab decomposition is already correct and sufficient — **no new Blade partial is authorized or required.** The one genuinely justified structural change is extracting the ≈1,313-line inline `page-script` block into a dedicated JS asset file, `resources/js/scripts/pages/contact-group-show.js`, enqueued from the page in place of the inline block. This is **not** a new Blade partial (so it does not conflict with the "no new partials for aesthetics" default) — it is a JS-file extraction, directly precedented by Design System M2 Slice 1's own `resources/js/scripts/pages/theme-settings.js` (that Milestone's own §9 item 35), and justified on its own facts here: no other file in this entire 30-file slice comes close to an inline script this size (the next-largest, `contactGroups/index.blade.php`, has a page-script section well under half this length within a 651-line file), and 1,313 lines in one `<script>` block is difficult to review, test, and safely restyle in place. The extraction must be byte-for-byte behavior-preserving — every AJAX URL, DataTables selector/column/init option, and SweetAlert2 flow moves verbatim; only its file location changes.

**`_segments.blade.php` (0 bytes)** is confirmed genuinely empty and dead: its tab pane exists and is `@include`d, but the nav-pill `<li>` that would link to `#segments` is entirely Blade-commented-out, and `ContactsController.php` carries a large docblock (lines ~1595-1660) documenting a planned-but-never-shipped Segments feature schema. **Decision: `_segments.blade.php` is not modified by this slice** — there is zero content to restyle, and building out the unshipped Segments feature is a product decision entirely outside a presentation-only Design System slice's authority. It is not in §9's allowlist, named here so its exclusion is a documented decision, not a silent omission.

### 3.7 Public form determination — `subscribe_form.blade.php` / `unsubscribe_form.blade.php`

Mechanically confirmed, not assumed: both views are reached exclusively through `routes/public.php` (`GET/POST contacts/{contact}/subscribe-url` → `contacts.subscribe_url`; `GET/POST contacts/{contact}/unsubscribe-url` → `contacts.unsubscribe_url`), which `RouteServiceProvider::mapWebRoutes()` wraps in **`Route::middleware('web')` only** — no `auth`, no `can:`, no `ValidProduct`, no `twofactor`. `routes/public.php`'s own header comment states: *"All public routes listed here. No middleware will not affect these routes."* Neither `ContactsController::subscribeURL()`/`insertContactBySubscriptionForm()` nor `unsubscribeURL()`/`postUnsubscribeURL()` calls `$this->authorize()` or checks `Auth::check()` anywhere. Both views already `@extends('layouts/fullLayoutMaster')` — the same public-page layout pattern login/register/reset-password pages use, **not** `contentLayoutMaster` (the authenticated dashboard-chrome layout every other file in this slice uses).

**Decision: these two views remain on `fullLayoutMaster`, unwrapped in customer sidebar/nav/dashboard chrome.** Slice 5 applies the same token/component treatment every other public-facing `fullLayoutMaster` page in this codebase already receives (button/input/select styling, DS icon usage where applicable — currently none, §3.4), never the authenticated-portal chrome. This is a presentation-parity decision, not a security decision: these two forms are intentionally, correctly anonymous-actor self-service surfaces (an SMS recipient opting in/out via a link containing the list's `uid`), and forcing dashboard chrome onto them would misrepresent the page to its actual, non-authenticated audience regardless of any other finding in this contract.

### 3.8 Authorization / tenant-isolation audit — CRITICAL FINDING (see §7 for the binding governance decision)

**Independently confirmed twice** — once via a dedicated security-focused research agent, cross-checked directly against `app/Providers/RouteServiceProvider.php`, `app/Providers/AuthServiceProvider.php`, `app/Repositories/Eloquent/EloquentAccountRepository.php`, `app/Models/ContactGroups.php`, `app/Http/Controllers/Customer/ContactsController.php`, `app/Http/Controllers/Customer/BlacklistsController.php`, and `app/Http/Controllers/Admin/BlacklistsController.php` in this same drafting pass.

**Foundational mechanism.** All authorization in this codebase runs through `Gate::define($key, fn($user) => $accountRepository->hasPermission($user, $key))`, looped over `config('permissions')`/`config('customer-permissions')` — a pure **role/permission-type** check ("can this account do this kind of thing"), never a **record-ownership** check ("does this specific record belong to this account"). There is no Eloquent global scope anywhere in the application (`grep -rn addGlobalScope app` → zero results) and no `Policy` class registered for `ContactGroups`, `Contacts`, or `Blacklists` (`AuthServiceProvider::$policies` registers only `UserPolicy`).

**Confirmed defect — Contacts / ContactGroups.** `ContactGroups::getRouteKeyName()` returns `'uid'`, with no scoping. Every single-record customer action — `ContactsController::show()`, `update()` (via `UpdateContactGroup::authorize() { return $this->user()->can('update_contact_group'); }`), `destroy()`, `activeToggle()`, `copy()`, and every individual-subscriber mutation that trusts the already-resolved `ContactGroups $contact` binding (`updateContactStatus()`, `updateContact()`, `deleteContact()`, the whole `batchActionContact()` family) — authorizes purely via `$this->authorize('<permission>')`/Gate, **never** comparing `$contact->customer_id` to `Auth::user()->id`. The **worst instance**: `ContactsController::batchAction()` (route `POST contacts/batch_action`) delegates to `EloquentContactsRepository::batchDestroy()/batchActive()/batchDisable()`, each executing a bare, **fully unscoped** `ContactGroups::whereIn('uid', $ids)->delete()/update()` — any customer holding the routine `delete_contact_group`/`update_contact_group` permission string can delete, enable, or disable **any other tenant's** contact groups platform-wide simply by supplying their `uid`s. (List/search endpoints — `search()`, `searchContact()` — **are** correctly scoped by `customer_id`; the gap is specific to single-record and batch mutation/read paths.)

**Confirmed defect — Blacklists.** `Blacklists` also binds by `uid` with no scope. `BlacklistsController::destroy()` (both customer and admin controllers) and `batchAction()` share the same unscoped repository pattern: `EloquentBlacklistsRepository::batchDestroy()` executes `whereIn('uid', $ids)->delete()` with **no `user_id` filter of any kind** — any customer holding `delete_blacklist` can delete **any other customer's** blacklist entries platform-wide, which additionally re-subscribes whatever `Contacts` row anywhere in the system currently matches that phone number, again with no tenant filter. (Customer `search()` **is** correctly scoped to `Blacklists::where('user_id', Auth::user()->id)`.) Admin's `search()`/`export()` are **intentionally, architecturally global by design** (no `user_id` filter, and the admin index explicitly resolves and displays the owning customer per row) — that half is not a defect, it is documented platform-operator tooling; the shared unscoped `destroy()`/`batchAction()` repository code is the actual defect, reachable from both the customer and admin controllers identically.

**Contrast case — Opportunities is correctly scoped, confirmed clean.** Every customer Opportunity read/mutation resolves through `OpportunityController::resolveMutationTarget()` → `findPrimaryByCustomer($customer->user_id)` then `EloquentOpportunityRepository::findOwned($id, $businessId)` (`->where('id', $id)->where('business_id', $businessId)`) — a genuine two-hop tenant-ownership chain (`Opportunity.business_id → Business.customer_id → auth()->user()`), enforced on every single action, not merely on list/search. Admin Opportunity routes are correctly, **deliberately** cross-tenant (an explicit in-repo route-file comment, plus the independent `EnsureUserIsAdministrator` middleware layered on top of the base gate) — the same pattern Blacklists' admin side uses correctly, but Opportunities additionally gets zero customer-side IDOR anywhere. **No remediation is needed for the Opportunity surface** — it is cited here only as the working contrast case proving the codebase does have a correct pattern for this exact problem (`findOwned`-shaped tenant resolution), one Contacts/ContactGroups/Blacklists simply never adopted.

**Two related, secondary findings, also flagged (not fixed, not in scope for this docs-only pass):**
- **Stored-XSS-shaped pattern**: `contactGroups/show.blade.php` interpolates `$contact_groups`/`$remain_opt_in_keywords`/`$remain_opt_out_keywords` via `{!! !!}` directly into inline JS, which is then passed to `Swal.fire({html: ...})` — SweetAlert2's `html` option renders raw HTML. Since contact-group **names** are customer-entered free text, a group named e.g. `<img src=x onerror=...>` would round-trip unescaped into a live `Swal.fire({html:...})` call (lines ~919, ~1009 for the copy/move modals; ~1176/~1249 for the keyword-add modals, lower risk since keyword text is more platform-controlled).
- **Stored-XSS-shaped pattern (admin)**: `Admin\BlacklistsController::search()` builds a raw `<a>...</a>` HTML string embedding `$blacklist->user->displayName()` (customer-controllable) and returns it as a DataTables JSON cell with no explicit non-HTML `render` override, so DataTables' default HTML-rendering behavior executes it as markup.

**Zero existing automated test coverage exists for Contacts, ContactGroups, or Blacklists anywhere in the suite** — confirmed by an exhaustive `tests/` grep/glob sweep (`ContactGroup|Blacklist` → zero matches repository-wide; the only `*Contact*`-named test files found belong to the unrelated `BusinessBillingContact` billing/usage feature). This is a distinct, separate governance gap from the IDOR finding itself: there is no regression net of any kind protecting this surface today, in either direction.

### 3.9 Opportunity-surface preservation — existing coverage is extensive, already the working pattern

`tests/Feature/Opportunity/` contains **37 files**, confirmed by direct enumeration (35 HTTP/repository/manager-level Feature tests plus 2 supporting non-test helper files), covering, among many other things: `AdminOpportunityControllerTest`/`AdminOpportunityRunControllerTest` (admin read-only index/detail/runs HTTP coverage), `OpportunityQueueHttpTest`/`OpportunityMutationHttpTest`/`OpportunityManualStateHttpTest`/`OpportunityRetryHttpTest`/`OpportunityExecutionStatusHttpTest`/`OpportunityDashboardHttpTest` (customer HTTP coverage across every mutation route named in §3.5/§3.8), and `OpportunityRepositoryTest` (direct `findOwned`/tenant-scoping proof). `tests/Unit/Opportunity/` adds 10 further pure-logic files. **This surface already has the deep, tenant-isolation-proving coverage the other three areas lack — Slice 5 must re-run this coverage unmodified (§8) and must not re-derive new authorization tests for a surface this well-proven already.** No product-functionality expansion (new statuses, new fields, new visibility rules) is authorized anywhere in this slice for Opportunities — presentation only, exactly as for every other surface.

### 3.10 Form/mutation preservation — highest-complexity surfaces named explicitly

Every form's exact `action`/method/CSRF/field-name/hidden-field set, as inventoried in full detail by this audit's own agents, must be preserved byte-for-byte; the following are named here because they carry the most restyle risk:

- **`contactGroups/show.blade.php`** — 2 native `<form>`s (fields-management, message) plus **11** SweetAlert2-driven AJAX mutation flows (subscriber delete, field delete, bulk subscribe/unsubscribe/copy/move/delete, add/delete opt-in keyword, add/delete opt-out keyword) — every route target, `_token`, and payload shape (`action`, `ids`, etc.) must survive the JS-extraction (§3.6) verbatim.
- **`customer/opportunities/show.blade.php`** — 7 distinct `<form>`s (configure-action, request-approval, confirm-approval, snooze, dismiss, reopen, retry), each conditionally rendered by `$opportunity->status`, plus one `fetch()`-based execution-status poller with its own client-side payload-shape validation and exponential backoff.
- **`admin/opportunities/show.blade.php`** — 3 `<form>`s (snooze, dismiss, reopen), all wrapped in one `@can('edit opportunities')` block.
- **`Contacts/import_file.blade.php` → `import/mapping.blade.php`** — a 2-step Dropzone-upload-then-AJAX-mapping-fetch chain; the mapping partial's own field-name pattern (`mapping[...]`) and its client-side "phone field must be mapped" validation gate must survive unchanged.
- **`contactGroups/_contacts.blade.php`'s `#exportContactModal`** — the sole native Bootstrap-modal form in this slice (§3.5), a direct `<x-dialog>` adoption target; its `contact_fields[]`/`include_phone` fields and `select-all` UX must be preserved exactly.

No route, controller method, FormRequest, validation rule, redirect target, or response shape changes anywhere in this slice (§6) — every item above is a markup/class/icon/component-wrapping change only.

---

## 4. Locked Slice 5 scope

- The 30 files inventoried in §3.1, **minus** `customer/contactGroups/_segments.blade.php` (0 bytes, confirmed dead stub, explicitly excluded per §3.6's own decision) — **29 existing Blade views** are candidates for modification.
- One new JS asset file, `resources/js/scripts/pages/contact-group-show.js` (§3.6), extracting `contactGroups/show.blade.php`'s existing inline script verbatim.
- Four new, mechanically-derived test files (§8).
- No controller, route, middleware, FormRequest, model, or migration file. No `app/`, `database/`, or `routes/` path of any kind — every controller named in §3.8/§3.10 is a confirmed read-only dependency for this slice.
- No other path. Every other rollout-map slice, and every non-Design-System initiative, remains entirely out of scope (§0).

---

## 5. Component adoption

Adoption decisions are grouped by recurring surface pattern (list-index pages, create/edit forms, detail/show pages) rather than repeated 30 times, since the same component fits/mismatches recur across near-identical files; per-file exceptions are called out explicitly.

**Adopted, with reasoning:**

- **`<x-card>`** — every page's single outer `.card` wrapper (all 29 files use exactly one top-level card, confirmed by both audit agents) adopts cleanly; no KPI-widget-shaped mismatch exists anywhere in this slice (unlike Slice 3's dashboard stat cards).
- **`<x-table>`** — every DataTables-shell table (§3.5) and every native-paginator table adopts the component for its outer `<table>`/`<thead>` shell, preserving `class="datatables-basic"` (or no extra class, for the native-paginator tables) via the component's own attribute-merge behavior; `<tbody>` content is unchanged in every case (DataTables-injected or `@foreach`-rendered exactly as today).
- **`<x-button>`** — every `primary`/`secondary`/`outline`/`danger`-shaped button across all 29 files adopts cleanly (confirmed: this slice uses no `btn-success`/`btn-warning`/`btn-info` semantic-status buttons anywhere, unlike Slice 3 — so the component's own confirmed variant gap, §3.2, never actually blocks an adoption in this slice).
- **`<x-badge>`** — the single nested-ternary status pill in `_import_history.blade.php` (`done`/`failed`/`running`/other → `bg-success`/`bg-danger`/`bg-info`/`bg-secondary`) adopts as `success`/`danger`/`accent`/`neutral` respectively — a genuine simplification of existing inline conditional logic, not merely a class rename; the Opportunity status/freshness badges (`customer/opportunities/index.blade.php`, `show.blade.php`) likewise adopt cleanly (`bg-light-primary`→`accent`, `bg-light-success`/`bg-light-warning`→`success`/`warning`).
- **`<x-alert>`** — the Opportunity empty-state `alert-secondary` block (`customer/opportunities/index.blade.php`) and the flash/validation-error alerts on both Opportunity `show.blade.php` files adopt cleanly (flat icon+slot layout matches; none of these use the two-part `alert-heading` structure Slice 3 found a mismatch for).
- **`<x-select>`/`<x-input>`** — every native, non-Select2/non-Flatpickr `<select>`/single-line `<input>` (opportunity filter dropdowns, blacklist create's `reason` field, contact single-line custom fields) adopts cleanly.
- **`<x-empty-state>`** — the Opportunity "No opportunities are available right now." / "No opportunities found." messages (already plain block-level, not table-cell-nested) adopt directly; DataTables' own `sZeroRecords` empty states (every DataTables-backed table in this slice) are **not** touched — they are a runtime JS-rendered string, not static Blade markup, and restyling them is a DataTables-language-object CSS/class concern, not a component-adoption concern.
- **`<x-dialog>`** — `_contacts.blade.php`'s `#exportContactModal` (§3.10), the one genuine Bootstrap-modal fit in this slice.
- **`<x-tooltip>`** — `_contacts.blade.php`'s single `data-bs-toggle="tooltip"` info icon.
- **`<x-pagination>`** — `customer/opportunities/index.blade.php`, `admin/opportunities/index.blade.php`, `admin/opportunities/runs/index.blade.php` (§3.5) — all three already pass a `LengthAwarePaginator` through `->links()`, a direct, clean fit.
- **`<x-ds-icon>`** — all 79 static `data-feather="..."` occurrences (§3.4) plus the JS-embedded `feather.icons[...].toSvg(...)` runtime calls found in `contactGroups/index.blade.php` and `show.blade.php`'s DataTables action-column render callbacks (these must be migrated to a client-side Lucide-equivalent rendering call at implementation time — mechanically confirmed, not silently left as `feather.icons[...]`, since leaving them would mean half-migrated icon rendering on the exact same page).

**Not adopted, with reasoning stated explicitly:**

- **`<x-tabs>`** — every tab-shaped UI in this slice (`contactGroups/show.blade.php`'s 8-pane nav-pills, `Contacts/import.blade.php`/`paste_text.blade.php`'s 2-item nav-pills pair) uses Bootstrap nav-**pills**, not nav-**tabs** — `<x-tabs>`'s own markup emits `nav nav-tabs`, a visually and structurally different pattern. Forcing it would change the visual treatment beyond a restyle. **Left as native nav-pills markup**, restyled only via the same token/spacing/radius system every other native Bootstrap element already inherits through `_runtime-bindings.scss` (§3.3) — not a defect, a genuine, disclosed API-shape mismatch.
- **Native `<textarea>`** (paste-text `recipients`, message-template body, blacklist `number`, custom-field textarea type) — no textarea component exists among the 19 (§3.2); left as native `form-control` markup, already runtime-token-bound.
- **Native `<input type="file">` / Dropzone dropzone-area** (`import_file.blade.php`) — no file-upload component exists among the 19; Dropzone's own visual chrome is third-party plugin chrome, explicitly deferred to per-slice elimination by the M2 Milestone contract's own §6.11 boundary (not this slice's authority to build a new component for).
- **Select2/Flatpickr-rendered controls** — third-party plugin chrome, same M2 Milestone §6.11 deferral; these already consume the tokenized `$success`/`$warning`/`$danger`/`$border-color` Sass variables at compile time (rebuild-reactive, not runtime-reactive) per that contract's own audit, unchanged here.
- **`<x-menu>`** — no genuine Bootstrap-dropdown-as-navigation-menu pattern exists in this slice; the bulk-actions dropdowns (`contactGroups/index.blade.php`, `Blacklists/index.blade.php` ×2) are action pickers, not navigation menus, and their exact per-item `data-feather` icon + permission-gated visibility shape does not map cleanly onto `<x-menu>`'s own `label`/`icon`/slotted-list API without restructuring the bulk-action wiring itself — **left as native Bootstrap dropdown markup**, already token-bound.

No component is forced anywhere its real, read API does not match the existing markup's shape, matching the identical discipline Slice 3 established.

---

## 6. Preserve all behavior

This is a presentation rollout, not a CRM-feature or business-logic rewrite. No controller, route, request, middleware, authorization rule, cache key, or query's actual filtering/scoping logic may change merely for styling convenience. **Route, controller, and security behavior are entirely read-only to this presentation implementation**, with the sole, explicit exception that Slice 5 implementation cannot even *begin* until the separate remediation named in §7 exists and is merged — at which point Slice 5 preserves *that* remediation's resulting behavior, never the insecure state §3.8 documents as historical audit evidence. Slice 5 implementation must preserve exactly:

- Every route, its exact HTTP method(s), and its exact middleware stack, as they exist on Slice 5's own authorized implementation baseline (post-remediation, §7).
- Every controller/repository method's exact data-building and mutation logic — including, explicitly, the exact scoping (or, pre-remediation, exact lack of scoping — not this slice's concern to alter) of every query, as established by the separate remediation, not by this document.
- Every AJAX endpoint, every plain form action/method, every SweetAlert2 confirmation flow's exact payload shape, and every DataTables `ajax.url`/column/order/checkbox/responsive configuration named in §3.5 — unchanged targets, unchanged methods, unchanged CSRF handling (`@csrf`/`csrf_field()`/`_token` preserved verbatim wherever already present).
- Every existing element `id`, `name`, and `data-*` attribute any JS in these files (or, post-remediation, any new test) depends on.
- Every `@can`/`@canany` visibility gate exactly as currently written — restyling a button/card must never change which permission gate wraps it.
- Every localization key already present (`__('locale.labels.*')` calls) — preserved exactly. The confirmed hardcoded-English gaps found by the audit (`_fields.blade.php`'s table headers and field-type button labels, `_form_fields.blade.php`'s duplicated inline-style literal) are **not** converted to translated `__()` strings by this contract — a real, disclosed, deliberately deferred localization-hygiene gap, not silently fixed as a side effect of restyling, since introducing new translation keys is a localization-infrastructure change outside "presentation/design-system migration" as this contract defines it.
- The exact, current subscribe/unsubscribe form field set, consent wording, reCAPTCHA wiring, and success-state behavior (§3.7) — no wording, validation, or consent-flow change of any kind.
- The exact opt-in/opt-out keyword and contact-group `uid`-based linking behavior — no change to how a subscribe/unsubscribe link resolves to a `ContactGroups`/`Contacts` row.
- The dead/broken `_segments.blade.php` tab pane and its commented-out nav-link (§3.6) — confirmed unreferenced by any live UI trigger; not touched, not fixed, not removed, not un-commented.

---

## 7. The authorization gap — mandatory pre-Slice-5-implementation prerequisite

§3.8 above contains the full audit evidence: **every single-record and batch mutation/read path across `ContactsController`/`EloquentContactsRepository` (for `ContactGroups` and `Contacts`) and the shared `destroy()`/`batchAction()` path in both the customer and admin `BlacklistsController`/`EloquentBlacklistsRepository` is authorized purely by Gate/permission-string role checks, with zero record-ownership verification — meaning any authenticated customer holding the routine, non-privileged permission string for that action can view, modify, or delete another tenant's contact groups, individual contacts, or blacklist entries, platform-wide, by supplying that tenant's `uid`.** This is a genuine, severe, already-live, pre-existing security defect, independently confirmed by direct code trace during this drafting pass. It predates this Design System initiative entirely and is not introduced by, or a consequence of, anything in this contract.

**The binding decision:** this security defect remains outside this contract's own implementation allowlist (§9) — this contract does not authorize, and is not the correct instrument for, fixing it. **This is a mandatory prerequisite, not an open sequencing question, mirroring the Design System M2 Slice 3 dashboard-security precedent exactly**: a separate, dedicated CRM tenant-isolation security-remediation contract must be drafted, human-reviewed, and human-merged, and its own implementation must likewise be human-merged, **before** Slice 5 implementation may be authorized. No path in §9's allowlist adds any ownership check, global scope, Policy class, or other authorization mechanism to `ContactGroups`, `Contacts`, or `Blacklists` — that remains exclusively the separate remediation's own scope. The two secondary stored-XSS-shaped findings (§3.8) should be evaluated as part of that same remediation's own scope, since both live inside the same controllers/repositories, though this contract does not mandate that they must be fixed in the same PR as the tenant-isolation gap — that judgment belongs to the remediation contract's own author.

**Once that remediation's implementation is merged, its resulting behavior becomes authoritative.** Slice 5's own future implementation authorization (a separate, later, explicit human instruction) must pin both: (a) the exact merge SHA of the security-remediation implementation, and (b) the exact then-current `origin/main` SHA Slice 5 implementation is based on. Slice 5 implementation must then preserve the remediated tenant-isolation behavior exactly as it exists on that pinned baseline — never the insecure historical boundary §3.8 documents. **This contract does not, and cannot, hard-code a remediation merge SHA that does not yet exist.**

---

## 8. Test contract (Slice 5)

Given §3.8's finding of zero existing coverage for three of the four CRM areas, and §3.9's finding of extensive existing coverage for the fourth (Opportunities), Slice 5 must establish focused new coverage under a new `tests/Feature/DesignSystem/` directory (a new, purpose-matched directory name — this repository's existing `tests/Feature/Theme/` directory is Milestone-2-preset-scoped, not slice-content-scoped, so a new sibling directory is used rather than overloading that one, matching the same per-feature-area convention `tests/Feature/Dashboards/` established for Slice 3). No existing test file requires modification — none is added to the allowlist for that reason.

1. **`tests/Feature/DesignSystem/ContactsCrmDesignSystemContentTest.php`** — raw-source mechanical content proof across all 29 modified files: zero remaining `data-feather="..."` attributes; zero remaining `feather.icons[...]`/`.toSvg()` JS-embedded calls in the two DataTables action-column render callbacks that currently use them; `<x-ds-icon`-equivalent rendered markup present at least once in every file that previously contained `data-feather` (proving genuine adoption, not deletion); zero hardcoded hex/rgb/font-family literals introduced anywhere (confirming §3.3's zero-baseline is not regressed); every DataTables `ajax.url`, Select2/Flatpickr initializer selector, and SweetAlert2 route target named in §3.5/§3.10 still present verbatim in the post-restyle source.
2. **`tests/Feature/DesignSystem/ContactsCrmComponentAdoptionTest.php`** — rendered-output assertions confirming each locked adoption in §5's "Adopted" list emits its component's own stable marker class (`ds-card`/`ds-table`/`ds-badge`/`ds-alert`/`ds-empty-state`/`ds-dialog`/`ds-tooltip-trigger`) on the correct surface — proving real adoption, not merely "no error was thrown." Targeted presence assertions only, not full-page snapshots, matching this repository's own established preference against visual-snapshot testing.
3. **`tests/Feature/DesignSystem/ContactsCrmExistingBehaviorPreservedTest.php`** — since zero prior coverage exists for three of the four CRM areas (§3.8), this test establishes the actual regression net: for each of the 15 surfaces named in §10's behavior-preservation matrix, a request from an actor genuinely able to reach it **on Slice 5's own authorized (post-remediation) implementation baseline** returns the expected response, every named form/AJAX endpoint still accepts its exact existing payload shape and redirects/responds as before, and every element `id`/`data-*` attribute named in §6 is still present. **This test does not assert, and must not assert, anything about tenant-isolation correctness or authorization being present/absent** — that is exclusively the separate security remediation's own concern (§7); whatever tenant-scoping behavior the merged remediation establishes is preserved by inheritance, not re-derived or re-asserted here.
4. **`tests/Feature/DesignSystem/ContactGroupShowScriptExtractionTest.php`** — specific to the one structural change this slice performs (§3.6): confirms `contactGroups/show.blade.php`'s rendered response enqueues `resources/js/scripts/pages/contact-group-show.js` (via the compiled Mix manifest) rather than an inline `<script>` block of the old shape, and that every AJAX URL/DataTables selector/SweetAlert2 flow the pre-extraction audit (§3.6/§3.10) named is present, byte-identical in meaning, in the extracted file.

**Regression baseline**: the full existing suite must be re-run at Slice 5's own final head, with the 4 new files above added and passing, and zero regression in any pre-existing test — most directly all 37 files under `tests/Feature/Opportunity/` (§3.9) and the 10 files under `tests/Unit/Opportunity/` — reported with the exact complete-suite count, never estimated or copied from any prior slice's own baseline. **This contract itself does not run that suite** — it is a docs-only contract-drafting pass, identical, established discipline to every prior Design System contract in this repository.

---

## 9. Exact implementation allowlist (Slice 5)

**Closed, numbered, path-level, no wildcards, no duplicate path, exactly 34 unique sequential entries. Any additional path required during Slice 5 implementation is a required-35th-path-shaped stop condition (§11). This allowlist becomes actionable only after §7's prerequisite is satisfied — it is published now so the audit that produced it is not lost, not because implementation may begin.**

### Contacts views (8 modified)

1. `resources/views/customer/Contacts/create.blade.php`
2. `resources/views/customer/Contacts/import.blade.php`
3. `resources/views/customer/Contacts/import/mapping.blade.php`
4. `resources/views/customer/Contacts/import_file.blade.php`
5. `resources/views/customer/Contacts/paste_text.blade.php`
6. `resources/views/customer/Contacts/show.blade.php`
7. `resources/views/customer/Contacts/subscribe_form.blade.php` — modified: token/component polish only, remains on `fullLayoutMaster`, unwrapped in dashboard chrome (§3.7).
8. `resources/views/customer/Contacts/unsubscribe_form.blade.php` — modified: same constraint as item 7.

### contactGroups views (11 modified — `_segments.blade.php` excluded, §3.6)

9. `resources/views/customer/contactGroups/_contacts.blade.php` — includes `<x-dialog>` adoption for `#exportContactModal` and `<x-tooltip>` adoption.
10. `resources/views/customer/contactGroups/_fields.blade.php`
11. `resources/views/customer/contactGroups/_form_fields.blade.php`
12. `resources/views/customer/contactGroups/_import_history.blade.php` — includes `<x-badge>` adoption replacing the nested-ternary status pill.
13. `resources/views/customer/contactGroups/_message.blade.php`
14. `resources/views/customer/contactGroups/_opt_in_keywords.blade.php`
15. `resources/views/customer/contactGroups/_opt_out_keywords.blade.php`
16. `resources/views/customer/contactGroups/_settings.blade.php`
17. `resources/views/customer/contactGroups/create.blade.php`
18. `resources/views/customer/contactGroups/index.blade.php`
19. `resources/views/customer/contactGroups/show.blade.php` — modified: replaces its ≈1,313-line inline `page-script` block with an enqueue of item 30 below (§3.6); all tab-pane `@include`s unchanged.

### Blacklists views (4 modified)

20. `resources/views/customer/Blacklists/create.blade.php`
21. `resources/views/customer/Blacklists/index.blade.php`
22. `resources/views/admin/Blacklists/create.blade.php`
23. `resources/views/admin/Blacklists/index.blade.php`

### Opportunities views (6 modified)

24. `resources/views/customer/opportunities/index.blade.php` — includes `<x-pagination>` adoption.
25. `resources/views/customer/opportunities/show.blade.php`
26. `resources/views/admin/opportunities/index.blade.php` — includes `<x-pagination>` adoption.
27. `resources/views/admin/opportunities/show.blade.php`
28. `resources/views/admin/opportunities/runs/index.blade.php` — includes `<x-pagination>` adoption.
29. `resources/views/admin/opportunities/runs/show.blade.php`

### New JS asset (1 new)

30. `resources/js/scripts/pages/contact-group-show.js` — new: the extracted `contactGroups/show.blade.php` script, byte-for-byte behavior-preserving (§3.6/§3.10).

### New focused tests (4 new)

31. `tests/Feature/DesignSystem/ContactsCrmDesignSystemContentTest.php`
32. `tests/Feature/DesignSystem/ContactsCrmComponentAdoptionTest.php`
33. `tests/Feature/DesignSystem/ContactsCrmExistingBehaviorPreservedTest.php`
34. `tests/Feature/DesignSystem/ContactGroupShowScriptExtractionTest.php`

**Counts** — Production views: **29** (all modified, zero new Blade files, zero `app/`/`database/`/`routes/` paths). New JS: **1**. Test: **4** (all new). **Overall total: 34. Stop threshold: 35** (34 + 1).

`resources/views/customer/contactGroups/_segments.blade.php` is deliberately **not** listed above (§3.6, §4) — 0 bytes, dead stub, nothing to restyle. `resources/views/admin/settings/AllSettings/*` and every other Design-System-track file already covered by a prior slice's own allowlist are likewise not listed here, named only to prevent confusion with this slice's own scope.

---

## 10. Behavior-preservation matrix

Only rows that actually exist per §3's audit; every "Mutation behavior" cell names the exact controller method the audit traced, per §3.8/§3.10.

| Surface | Actor | Read behavior | Mutation behavior | Critical DOM/JS contract |
|---|---|---|---|---|
| Contacts create (customer) | Authenticated customer, `create_contact` | Dynamic per-field form | `ContactsController::storeContact()` | Flatpickr `.datetime`/`.date`, dynamic `name="{{ $field->tag }}"` |
| Contacts import — file/paste/mapping (customer) | Authenticated customer, `view_contact`/`view_contact_group` | Dropzone upload → AJAX mapping fetch → run | `storeImportFile()`, `importMapping()`, `importRun()`/`importValidate()`, `storeImportContact()` | Dropzone `paramName:"import_file"`, mapping `select2` per CSV column, phone-field-required client validation |
| Contacts edit/show single subscriber (customer) | Authenticated customer, `update_contact`, tenant-scoped subscriber lookup | Dynamic per-field form (pre-filled) | `updateContact()` | Same field-loop template as create (§3's duplicate-file finding) |
| Contacts subscribe form (public) | **Anonymous** | N/A | `insertContactBySubscriptionForm()` | `fullLayoutMaster`, reCAPTCHA v3, Select2/Flatpickr, no dashboard chrome |
| Contacts unsubscribe form (public) | **Anonymous** | N/A | `postUnsubscribeURL()` | `fullLayoutMaster`, reCAPTCHA v3, single `phone` field, no dashboard chrome |
| ContactGroups index (customer) | Authenticated customer, `view_contact_group` | Server-side DataTables, `customer.contacts.search` | copy/delete/bulk enable-disable-delete via `customer.contacts.*` | `feather.icons[...]` JS render callback (migrates to `<x-ds-icon>`-equivalent), SweetAlert2 ×5 |
| ContactGroups create (customer) | Authenticated customer, `create_contact_group`, list-quota-checked | Plain form | `ContactsController::store()` | none beyond standard form |
| ContactGroups show — contacts tab (customer) | Authenticated customer, `view_contact_group` (page) / `view_contact` (tab) | Server-side DataTables, `customer.contact.search` | subscribe/unsubscribe/copy/move/delete via `customer.contact.batch_action`; export via `customer.contact.export` | Extracted JS (item 30); `#exportContactModal` → `<x-dialog>` |
| ContactGroups show — fields/settings/message/keywords tabs (customer) | Authenticated customer, `update_contact_group` | Tab-scoped native forms/tables | `store-contact-field`, `delete-contact-field`, `message`, `optin_keyword`/`optout_keyword` + delete variants | AJAX-fetched `_form_fields.blade.php` fragment, `__index__` placeholder pattern |
| ContactGroups show — import history tab (customer) | Authenticated customer, `create_contact_group` | Plain table, no DataTables | `contacts.download_failed` (conditional link) | `<x-badge>` adoption for status pill |
| Blacklists index+create (customer) | Authenticated customer, `view_blacklist`/`create_blacklist`/`delete_blacklist` | Server-side DataTables, `customer.blacklists.search` (tenant-scoped) | `store()` (tenant-scoped), `destroy()`/`batchAction()` (**unscoped — §3.8**) | SweetAlert2 ×2, delimiter-textarea bulk-paste (no file import) |
| Blacklists index+create (admin) | Admin, `view blacklist`/`create blacklist`/`delete blacklist` | Server-side DataTables, `admin.blacklists.search` (**intentionally global**) + `export` | `store()` (self-scoped on create), `destroy()`/`batchAction()` (**unscoped, shared code — §3.8**) | Raw-HTML `user_id` column (XSS-flagged, §3.8), extra "listed by" column |
| Opportunities index+show (customer) | Authenticated customer, tenant-scoped via `findOwned`/`findPrimaryByCustomer` | Native paginator | 7 forms: configure-action, request-approval, confirm-approval, snooze, dismiss, reopen, retry | `fetch()`-based execution-status poller, `<x-pagination>` adoption |
| Opportunities index+show (admin) | Admin, `view opportunities`/`edit opportunities` + `EnsureUserIsAdministrator`, **intentionally cross-tenant** | Native paginator | 3 forms: snooze, dismiss, reopen | `<x-pagination>` adoption (index only) |
| Opportunity runs index+show (admin) | Admin, `view opportunities` + `EnsureUserIsAdministrator`, cross-tenant | Native paginator, read-only | **None — confirmed zero mutation routes exist** | `<x-pagination>` adoption (index only) |

---

## 11. Responsiveness review

Using only the existing Bootstrap breakpoints already established by Milestone 1 (`sm:576px, md:768px, xl:1200px, xxl:1440px`) — no new breakpoint system. At 375px (mobile) and 768px (tablet), the following wide surfaces need their existing responsive treatment confirmed, not redesigned: every DataTables-backed table (§3.5) already uses the DataTables Responsive extension with a custom modal renderer (confirmed present in all 4 server-side instances) — implementation must confirm this extension continues to function correctly through the component-shell adoption (§5), not merely assume it. Every native-paginator table (`opportunities/index.blade.php` ×2, `runs/index.blade.php`) and every native form-heavy page (`Contacts/create.blade.php`, `show.blade.php`, `contactGroups/_fields.blade.php`) already wraps its table in `.table-responsive` / uses standard `.row`/`.col-md-*` grid classes — confirmed present, not newly introduced. No file in this slice introduces a fixed-width or non-responsive layout element (the only inline `style="..."` attributes found are `max-width` caps on two opportunity-detail controls and column-width hints on two contactGroups field-management tables, §3.3 — all already responsive-safe, none require change).

---

## 12. Forbidden scope

Explicitly out of scope for this contract and for any future Slice 5 implementation authorized under it:

- Any change to product behavior: contact-group creation rules, subscribe/unsubscribe consent wording or flow, import algorithm/validation rules, blacklist opt-in/opt-out semantics, Opportunity scoring/workflow/status semantics.
- Any change to routes, controllers, FormRequests, models, repositories, or migrations of any kind — including, explicitly, the tenant-isolation fix named in §7 (that is exclusively the separate remediation contract's own scope, not this one's, whether or not that remediation has already merged by the time Slice 5 implementation begins).
- Any change to `ChatBox`/Conversations (Slice 6), `Reports & Analytics` (Slice 4), Campaigns, or billing surfaces.
- Any new token architecture, JS framework, or CSS framework — this slice consumes the existing, already-merged Milestone 1/M2 Slice 1 token and component system exactly as-is (§3.3 confirms zero new SCSS is even required).
- Any AI/COO/SEO/Outreach/Website-Generator work of any kind.
- Any change to `docs/automation/AI-AUTONOMY-STATE.json`.
- Automatic advancement to Slice 4, Slice 6, or any other slice/initiative upon this contract's own merge.

---

## 13. Governance block

```
maximum_correction_rounds: 2
advance_automatically: false
start_automatically_after_contract_merge: false
implementation_requires_separate_human_authorization: true
implementation_blocked_until: "CRM tenant-isolation security remediation contract + implementation, both human-merged (§7)"
merge_authority: human_only
```

Merging this contract does not authorize Slice 5 implementation. Implementation additionally requires a separate, later, explicit human instruction pinning both the exact security-remediation implementation merge SHA and the exact then-current `origin/main` SHA implementation is based on (§7).

---

## 14. Mechanical searches (Slice 5, run at implementation time)

1. `grep -rniE "anthropic|claude"` across every path in §9 → zero matches.
2. `grep -c "data-feather"` across §9 items 1-29 → zero.
3. `grep -rnoE "#[0-9A-Fa-f]{3,8}"` across §9 items 1-30 → zero genuine color literals (any CSS ID-selector-shaped false positive must be individually confirmed non-color, not blanket-ignored).
4. `git diff --stat -- app database routes` compared against §9 (which contains no such path at all) → **must be completely empty**; any non-empty result is an automatic violation.
5. `grep -n "@include" resources/views/customer/contactGroups/show.blade.php` → exactly the 8 live partials named in §3.6, `_segments` included as a pane wrapper but its own nav-link still Blade-commented-out.
6. `grep -c "feather.icons\[" resources/views/customer/contactGroups/{index,show}.blade.php` → zero (§8 item 1's JS-embedded-icon migration proof).
7. Every one of the 30 distinct icon names in §3.4 individually confirmed present as `vendor/technikermathe/blade-lucide-icons/resources/svg/{name}.svg` (or its confirmed Lucide-alias equivalent) before use — never guessed, with `check-square` given explicit extra scrutiny per §3.4's own flagged risk.
8. `git diff --stat -- app/Http/Controllers/Customer/ContactsController.php app/Http/Controllers/Customer/BlacklistsController.php app/Http/Controllers/Admin/BlacklistsController.php app/Http/Controllers/Customer/OpportunityController.php app/Http/Controllers/Admin/OpportunityController.php app/Http/Controllers/Admin/OpportunityRunController.php` compared against Slice 5's own authorized (post-remediation) implementation baseline → **must be completely empty** — direct, mechanical proof Slice 5 implementation touches zero business logic on these six controllers relative to whatever the remediation established.
9. Final changed-path set (`git diff --name-only` + `git ls-files --others --exclude-standard`) equals §9's exact, sequential 1-34 allowlist — mechanically diffed, not eyeballed.
10. `php artisan test` full-suite pass count compared against the pre-Slice-5 baseline, reported exactly, never estimated (§8).

---

## 15. Stop conditions

Slice 5 implementation must stop, leave the working tree unstaged, and report rather than proceed, if:

- **Slice 5 implementation must not begin at all unless the separate CRM tenant-isolation security remediation named in §7 is already human-merged and its exact implementation merge SHA is pinned in Slice 5's own later, separate implementation authorization.** If that evidence is missing, incomplete, or unverifiable, STOP before any allowlisted path is touched.
- If the post-remediation current tree changes which 29 views (plus the one new JS file) are the correct rendered-surface set, or causes a genuinely necessary path beyond §9's own 34-item allowlist, STOP and re-audit rather than silently widening scope.
- Any path beyond §9's 34-item allowlist is required — the **35th** path.
- Any change to `app/`, `database/`, or `routes/` appears necessary for any reason, **including** any change that would add, strengthen, narrow, or otherwise alter tenant-isolation/authorization on `ContactGroups`, `Contacts`, or `Blacklists` — that is exclusively the separate security remediation's own scope, never this contract's.
- Any of the 30 flagged icon names (§3.4, especially `check-square`) does not have a confirmed, working equivalent in the pinned Lucide `v3.166.0` package.
- Any existing test — most directly any of the 37 files under `tests/Feature/Opportunity/` — fails for a reason not fixable within this slice's own allowlist.
- Any route, controller data-building/mutation logic, `@can`/`@canany` gate, AJAX/form target, or CSRF handling changes as a side effect of restyling or of the JS extraction (item 30).
- The `contact-group-show.js` extraction is found to require any behavior change (not purely a location/module-wrapping change) to preserve correctness.
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is found necessary to reference.
- A genuine business-logic change is found necessary to make any of the 29 views render or behave correctly under the new design system.

---

## 16. Contract self-audit

1. Full current inventory is present and mechanically re-derived, not copied from the M2 rollout map's historical "30" figure (§3.1). ✓
2. Sequential numbering, no duplicates, stop threshold = 35 (34 + 1), stated consistently in §0, §9, §15. ✓
3. Every path in §9 exists now (confirmed by §3.1's direct inventory) or is explicitly marked NEW (item 30, items 31-34). ✓
4. No invented test, permission, route, component API, or token — every component cited in §5 was read directly from its current source (§1 item 4, §3.2); every token claim is backed by §3.3's direct grep; no new SCSS/token file is invented since none is needed. ✓
5. Public vs. authenticated forms correctly distinguished, with mechanical route-file evidence (§3.7) — `subscribe_form`/`unsubscribe_form` confirmed genuinely public and kept off dashboard chrome. ✓
6. Current auth/tenant behavior is documented exhaustively (§3.8), not assumed — and the one genuine pre-existing security defect found is treated as a hard blocker (§7), never silently preserved or silently fixed inline. ✓
7. DataTables/plugin contracts are documented per-surface (§3.5), not hand-waved. ✓
8. `contactGroups/show.blade.php`'s decomposition decision is explicit: no new Blade partial, one justified JS-file extraction, with its own reasoning stated (§3.6). ✓
9. Component adoption/non-adoption decisions are explicit and reasoned for every recurring pattern (§5), not silently skipped. ✓
10. `docs/automation/AI-AUTONOMY-STATE.json` is untouched (§0, §2, §12). ✓
11. Slice 4 and Slice 6 are untouched and explicitly named as out of scope (§0, §12), not merely unmentioned. ✓
12. No implementation authorization is granted anywhere in this document — restated in §0's opening line, §7, and §13's governance block. ✓
13. This document remains the only file changed on this branch (§2). ✓

---

## 17. Verification and publication

Performed, in order, before commit:

1. Markdown structural check — every numbered §9 item follows the pattern `N. `path``, no broken heading levels, no unclosed code fences.
2. §9's numbered items counted mechanically and confirmed equal to exactly 34, sequential, no gap, no repeated number.
3. Every path listed in §9 checked for uniqueness — no path string appears twice.
4. `git diff --check` — clean, no whitespace-error or conflict-marker findings.
5. `git diff --name-only` — exactly one path: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md`.
6. `git status --short` — exactly one untracked entry, the same path.
7. `git diff --cached --name-only` — empty before staging.
8. Stage individually (`git add docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md`), never `git add -A`/`.`.
9. Commit message: `docs: define Design System M2 Slice 5 contract`.
10. Push to `origin chore/design-system-m2-slice5-contacts-crm-contract` — a normal push, never force-pushed.
11. Open a draft PR into `main` if `gh` is available; otherwise report the exact GitHub comparison URL.
12. **Do not merge. Do not begin Slice 5 implementation. Do not begin Slice 4, Slice 6, or any other slice. Do not begin any other RFC or initiative.** All require separate, explicit, future human authorization. No test is run for this docs-only change — reported honestly as not run, no count fabricated.

---

*End of Design System M2 Slice 5 Contract. Implementation requires a separate, explicit human instruction. This contract's own merge does not start or resume it. Slice 5 implementation is blocked until the separate CRM tenant-isolation security remediation named in §7 is complete — contracted, human-merged, implemented, and human-merged — and its exact merge SHA is pinned in Slice 5's own later, separate implementation authorization.*
