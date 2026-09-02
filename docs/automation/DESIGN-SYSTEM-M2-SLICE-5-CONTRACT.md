# Design System — Milestone 2, Slice 5 Contract: Contacts & CRM

**This document is fully self-contained.** No section below requires consulting an earlier commit, the Milestone 1 contract, the Milestone 2 contract, or any other slice contract to understand Slice 5's complete rules — every requirement, architecture decision, and path is restated here in full.

**Status: contract only. No implementation has occurred under this document. Merging this contract does NOT authorize implementation. In addition, per §7 below, Slice 5 implementation cannot be authorized AT ALL until a separate, dedicated CRM tenant-isolation security remediation is drafted, human-reviewed, human-merged, and its own implementation human-merged.**

**Correction Round 1** withdrew an invalid JS-extraction authorization for `contactGroups/show.blade.php`, corrected the icon audit to distinguish static Blade markup from runtime JavaScript calls, moved `<x-pagination>` to a non-adoption, sharpened the security-remediation prerequisite (admin Blacklists' global model must survive; both stored-XSS-shaped findings must be resolved), and reduced the future allowlist to 32 paths.

**Correction Round 2 (final — `maximum_correction_rounds: 2`).** Independent review confirmed Round 1's fixes were correct but found §5's component-adoption claims were still over-broad and, in several places, factually wrong against the actual current component source and the actual current Slice-5 markup. This round mechanically re-audits every proposed adoption and corrects: (A) the false "every page has one card" claim — `_form_fields.blade.php` has zero cards, `_contacts.blade.php` has four (three KPI-shaped stat cards plus one table card), neither forced into `<x-card>`; (B) the false "no `btn-success`/`btn-info`/`btn-warning`" claim, and the unstated fact that `<x-button variant="secondary">` emits *outline* `btn-outline-secondary` (not solid `btn-secondary`) while `variant="danger"` emits *solid* `btn-danger` (not the `btn-outline-danger` actually used in this slice) — real button-class usage is now mechanically enumerated, separating genuine static Blade markup from SweetAlert2 JS-string `customClass` configuration, which is not Blade-adoptable at all; (C) the over-broad input/select claim, replaced with an exact, conservative, mechanically-verified safe subset; (D) the false "direct fit" claim for `_contacts.blade.php`'s export modal, whose `<form>` spans the entire `.modal-content` region including an auto-rendered header `<x-dialog>` cannot represent — moved to non-adoption; (E) the incorrect `running → accent` badge mapping, which loses the existing `info`-vs-`accent` visual distinction the component's enum cannot express — the whole status badge stays native; (F) the unverified table-adoption claim, corrected to exclude every DataTables-shell/plugin-coupled table and the two special-header-class admin history tables, keeping only the three genuinely plain native-paginator tables (further narrowed by this pass's own exception, see below). No conclusion outside these six is reopened by Round 2 itself.

**Human-Approved Post-Round Docs Consistency Exception (this pass).** `maximum_correction_rounds: 2`, `correction_round: 2`, and `correction_round_is_final: true` are unchanged — **this is not Correction Round 3, and it is not a second exception; it is the sole, already-approved post-round docs-only exception, applied here.** The human explicitly approved one narrowly-bounded exception to reconcile five clerical/mechanical contradictions discovered during final review, none of which reopen the contract's architecture or grant any implementation authority: (A) §5.3's header said "7 total" while enumerating 11 controls, and `business_id` was described both as adopted and as an unresolved "judgment call" — corrected to a firm count of 11 (7 select + 4 input) with `business_id` mechanically confirmed and locked adopted, no discretion left open; (B) wording that could be read as freezing native-retained markup so completely that it would forbid the separately-binding icon migration is corrected to make explicit that a native-retention decision freezes only the structural/semantic shell, never an in-scope static `data-feather` child, which still migrates to `<x-ds-icon>` regardless of its container's adoption status; (C) the button audit is re-run classifying every genuine `btn-primary`/`btn-outline-primary`/`btn-outline-secondary` occurrence by actual HTML tag, revealing 15 of the 21 `btn-outline-primary` occurrences are `<label>` elements paired with `btn-check` radio inputs, which `<x-button>` cannot represent — corrected to native retention, changing the final adopted count from an implied 53 to a mechanically exact 38; (D) direct source inspection found `admin/opportunities/index.blade.php` and `admin/opportunities/runs/index.blade.php` both use `<thead class="table-primary">`, the identical incompatibility already used to non-adopt two other tables — both are corrected from "adopted" to native retention, leaving exactly one genuine `<x-table>` adoption in the entire slice, not three; (E) the single "No opportunities are available right now." node was ambiguously eligible for both `<x-alert>` and `<x-empty-state>` — corrected to lock it as `<x-empty-state>` only, with `<x-alert>` reserved for the four genuine, structurally distinct flash/validation-error alert nodes. This exception reconciles only the textual application of these five items; it does not reopen, narrow, or extend anything else Round 2 already established.

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice5-contacts-crm-contract`, in an isolated linked worktree, based on `origin/main` at `437f12a51b8d036db055ba6ddafd89ab2ec9199a` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` at the start of this final correction round. This SHA is the Design System M2 Slice 3 (Dashboards) implementation-correction merge (PR #174).
- **Correction Round 2 of a maximum of 2 (`maximum_correction_rounds: 2`) — this is the final allowed correction round for this contract.**
- **`post_round_docs_consistency_exception: human_approved`** — a one-time, human-approved, docs-only consistency amendment applied after Correction Round 2 completed, reconciling five clerical/mechanical contradictions found during final review (business_id/count consistency, native-retention vs. icon-migration wording, button tag classification, table `thead`-class compatibility, alert/empty-state node ownership). It is **not** a third correction round, does not reopen any architectural decision, and grants no implementation or remediation authority beyond what Round 2 already established.
- **Slice 4 (Reports & Analytics) is deliberately, explicitly skipped by human choice.** This contract does not touch, contract, or authorize anything under `customer/Reports/**` or `admin/Reports/**`.
- Slice 5 is the rollout-map group named **"Contacts & CRM"**, historically listed as **30 files** — not trusted; §3.1 mechanically re-derives the current tree. The re-derived count matches (30 files), independently re-confirmed, not copied.
- `advance_automatically: false`. `start_automatically_after_contract_merge: false`.
- Any path required during Slice 5 implementation but absent from §9's own numbered allowlist is a stop-and-report condition. The stop threshold is the **33rd** path (32 allowlisted + 1) — unchanged from Round 1; this round adds zero new paths.
- This contract authorizes **only** drafting/correcting this one document. It does not authorize Reports & Analytics (Slice 4), ChatBox/Conversations (Slice 6), or any other slice/initiative, and makes zero change to `docs/automation/AI-AUTONOMY-STATE.json`.
- **This contract does not fix, and is not authorized to fix, the severe pre-existing cross-tenant authorization gap documented in §3.8.** A separate, dedicated CRM tenant-isolation security-remediation contract must be drafted, human-reviewed, and merged, and its own implementation must be human-merged, before Slice 5 implementation may be authorized (§7).

---

## 1. Mandatory preflight — verified

1. `git fetch origin --tags` — `origin/main` confirmed at exactly `437f12a51b8d036db055ba6ddafd89ab2ec9199a`, unchanged since this contract's original drafting pass and Round 1.
2. Starting branch HEAD for this correction round confirmed at exactly `b90c5cd6dfe9128808bd43e20715277413b85453` (Round 1's own commit), working tree clean before any edit.
3. Read in full before drafting: `CLAUDE.md`, `AGENTS.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-CONTRACT.md`.
4. This round additionally, directly re-read: `resources/views/components/{card,button,dialog,badge,input,select,table,tooltip,empty-state,ds-icon}.blade.php` in full, and mechanically re-audited every proposed §5 adoption against the actual current markup of every affected Slice-5 view via direct `grep`/`sed` inspection — not assumed from the Round-1 document's own prose. Specifically confirmed: `_form_fields.blade.php` has zero `.card` wrappers and its inputs use bracketed dynamic names (`fields[__index__][label]`) nested inside `.input-group` structures; `_contacts.blade.php` has four `.card` wrappers (three KPI stat cards, one table card) and its `#exportContactModal`'s `<form>` opens immediately inside `.modal-content`, spanning `.modal-header`/`.modal-body`/`.modal-footer` as one unit; of 44 total `btn-primary` occurrences across the slice, exactly 20 are inside SweetAlert2 `confirmButton:`/`cancelButton:` JS-string `customClass` configuration, not static Blade markup (same for 20 of 22 `btn-outline-danger` occurrences); `_import_history.blade.php`'s status badge is a 4-way nested ternary (`done→bg-success`, `failed→bg-danger`, `running→bg-info`, else→`bg-secondary`) with a genuine `info` state `<x-badge>`'s `neutral|accent|success|warning|danger` enum cannot represent without loss.
5. **This pass's own additional mechanical re-verification** (behind the post-round consistency exception, §3.11): every genuine (non-JS-string) occurrence of `btn-primary`, `btn-outline-primary`, and `btn-outline-secondary` across all 29 Slice-5 views, classified by actual enclosing HTML tag (`<button>`/`<a>`/`<label>`); `admin/opportunities/index.blade.php`'s and `admin/opportunities/runs/index.blade.php`'s `<thead>` markup; `admin/opportunities/index.blade.php`'s `business_id` input's complete markup plus a repository-wide search for any JS/CSS selector depending on it; the exact empty-state/flash-alert/table-`@empty`-row nodes across all six Opportunity views.

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` (this document). No `resources/`, `app/`, `database/`, `routes/`, or `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting or correcting this contract.

---

## 3. Mandatory repository audit — findings

Unchanged from Round 1 except where this round's own re-verification (§1 item 4) is cited. §§3.1–3.3, 3.7–3.10 carry zero substantive change this round; reproduced in full below for self-containment, with §3.4–§3.6 unchanged from Round 1 (icon/decomposition corrections already stand) and a new §3.11 added for this round's component-markup findings.

### 3.1 Current file inventory — mechanically re-derived, unchanged this round

**`resources/views/customer/Contacts/` — 8 files, 1,290 lines:** `create.blade.php` (209), `import.blade.php` (48), `import/mapping.blade.php` (154), `import_file.blade.php` (147), `paste_text.blade.php` (166), `show.blade.php` (209), `subscribe_form.blade.php` (252), `unsubscribe_form.blade.php` (105).

**`resources/views/customer/contactGroups/` — 12 files, 3,262 lines:** `_contacts.blade.php` (228), `_fields.blade.php` (182), `_form_fields.blade.php` (76), `_import_history.blade.php` (60), `_message.blade.php` (105), `_opt_in_keywords.blade.php` (39), `_opt_out_keywords.blade.php` (39), `_segments.blade.php` (**0 — dead stub, §3.6**), `_settings.blade.php` (172), `create.blade.php` (137), `index.blade.php` (651), `show.blade.php` (1,573).

**`resources/views/customer/Blacklists/` — 2 files, 513 lines.** **`resources/views/customer/opportunities/` — 2 files, 457 lines.** **`resources/views/admin/opportunities/` — 4 files, 580 lines.** **`resources/views/admin/Blacklists/` — 2 files, 505 lines.**

**Total: 30 files, 6,607 lines.**

### 3.2 Current Design System component library — re-verified directly this round (§1 item 4)

19 components in `resources/views/components/`. Exact prop APIs re-confirmed this round for every component named in a §5 decision:

- **`<x-card :title :padded>`** — optional `.card-header` (title + actions slot), body always wrapped in a `div` (`.card-body` unless `padded=false`), optional `.card-footer`. No support for multiple independent stat/KPI sub-cards inside one wrapper, and no support for a page with zero cards (it is opt-in per usage, not a page-level requirement).
- **`<x-button :variant :size :type :href :icon :disabled>`** — `variant` ∈ `primary|secondary|outline|ghost|danger`, mapping to `btn-primary`, **`btn-outline-secondary`** (not solid `btn-secondary`), `btn-outline-primary`, `btn-flat-secondary`, and **`btn-danger`** (solid, not `btn-outline-danger`) respectively. No `success`/`warning`/`info` variant of any kind.
- **`<x-dialog :id :title :size>`** — `sm|md|lg`, always adds `modal-dialog-centered`; if `title` is supplied, auto-renders a `.modal-header` (with its own auto-generated `btn-close`) **outside** any slot the caller controls; body is the default slot, footer is a separate named slot. **The component owns the header/body/footer DOM structure itself — a caller cannot wrap all three in one enclosing `<form>` element through the component's own API**, since the header is rendered by the component before the caller's slot content, not inside it.
- **`<x-badge :variant>`** — `variant` ∈ `neutral|accent|success|warning|danger`. **No `info` variant.**
- **`<x-input :label :name :type :help :error>`** / **`<x-select :label :name :options :selected :help>`** — both always wrap output in a fresh `<div class="ds-field mb-3">`, always set `id="{{ $name }}"`, and render their own `<label>` when a `label` prop is passed. Neither supports a `name` containing PHP-array bracket syntax being paired with a caller-supplied `id` that differs from it, nor a control that must remain a direct child of a `.input-group`/`.form-check` wrapper the surrounding markup owns.
- **`<x-table :headers>`** — wraps a `<table>` in `.table-responsive`; `<thead>` (if `headers` is non-empty) renders one `<th class="text-label text-uppercase text-muted">` per array entry, with **no mechanism to apply a per-column class (e.g., a DataTables `colvis`-exclusion class) or a `<thead>`-level class (e.g., `table-primary`)**; `<tbody>` is always the caller's own slot content, unaffected either way.
- **`<x-tooltip :text :placement>`** — wraps slot content in a `<span data-bs-toggle="tooltip" data-bs-placement="..." title="...">`. Unchanged, still a clean structural match for this slice's one tooltip trigger (§3.11).
- **`<x-pagination :paginator>`**, **`<x-ds-icon>`**, **`<x-alert>`**, **`<x-empty-state>`** — unchanged from Round 1's own findings.

### 3.3 Color/token audit — unchanged this round

Zero hardcoded hex/`rgb()`/`rgba()`/`hsl()` literals, zero `font-family` declarations, anywhere in the 30 files. Zero new SCSS required.

### 3.4 Icon audit — unchanged this round (Round 1's Correction B stands)

**Category 1 — static Blade `data-feather="..."` (the genuine migration target): 79 total occurrences, 30 distinct names.** **Category 2 — runtime `feather.icons[...].toSvg(...)` JS calls, explicitly preserved: 11 occurrences, 6 distinct names (`copy`, `edit`, `message-square`, `plus-circle`, `send`, `trash`), across 4 files** (`contactGroups/index.blade.php` ×5, `contactGroups/show.blade.php` ×4, `customer/Blacklists/index.blade.php` ×1, `admin/Blacklists/index.blade.php` ×1). **Category 3 — controller-generated dynamic `data-feather` markup, outside Slice-5 view scope: `ContactsController.php` lines 155-156.** `<x-ds-icon>` structurally cannot execute inside JavaScript (confirmed again this round, §3.2); no client-side Lucide package exists; the Feather browser runtime remains loaded for Categories 2 and 3, mirroring the established Slice 2 precedent.

### 3.5 DataTables / Select2 / Flatpickr / SweetAlert2 / Dropzone / modal / tabs audit — unchanged this round

Unchanged from Round 1: 4 server-side + 2 client-side DataTables instances, Select2 in 6 contexts, Flatpickr in 3, SweetAlert2 with 11 flows on `contactGroups/show.blade.php` alone (plus 5 on `contactGroups/index.blade.php`, 2 on each `Blacklists/index.blade.php`), Dropzone in 1 file, one genuine Bootstrap modal (`_contacts.blade.php`'s `#exportContactModal` — its exact `<form>`-spans-everything structure is detailed in §3.11 below and drives §5's Correction D), nav-pills tabs in 2 locations, one tooltip trigger.

### 3.6 `contactGroups/show.blade.php` — decomposition decision — unchanged this round (Round 1's Correction A stands)

No JS extraction, no new JS file, no new Blade partial. The existing 9-partial tab decomposition is sufficient. The inline `page-script` block (≈1,313 of 1,573 lines) remains in place, unrelocated, saturated with Blade-evaluated constructs a static `resources/js/scripts/**` file cannot execute. `_segments.blade.php` (0 bytes) remains excluded, not modified.

### 3.7 Public form determination — unchanged this round

`subscribe_form.blade.php`/`unsubscribe_form.blade.php` remain on `fullLayoutMaster`, unwrapped in dashboard chrome.

### 3.8 Authorization / tenant-isolation audit — unchanged this round (Round 1's Corrections D and E stand)

Confirmed defects in customer Contacts/ContactGroups (unscoped single-record/batch mutation) and customer Blacklists (unscoped `destroy()`/`batchAction()`, shared with the admin controller's own equivalent actions). Admin Blacklists' global list/search/export visibility is intentional, not itself a defect — the future remediation must preserve it while closing the customer-originated exploit path through the shared unscoped repository code (Correction D), and must explicitly resolve, not merely note, both stored-XSS-shaped findings (Correction E): the `contactGroups/show.blade.php` SweetAlert2 `{!! !!}`→`html` pattern, and the admin Blacklists raw-HTML `displayName()` pattern. Opportunities remains the correctly-scoped contrast case, needing no remediation. Zero existing test coverage for Contacts/ContactGroups/Blacklists anywhere.

### 3.9 Opportunity-surface preservation — unchanged this round

37 files under `tests/Feature/Opportunity/`, 10 under `tests/Unit/Opportunity/` — re-run unmodified, no new authorization tests re-derived for this already-proven surface.

### 3.10 Form/mutation preservation — unchanged this round

Every form's exact `action`/method/CSRF/field-name set preserved byte-for-byte, per Round 1's own detailed accounting.

### 3.11 Component-markup compatibility audit — new this round, drives §5's six corrections

Direct, mechanical evidence gathered this round (§1 item 4), organized by the six corrected components:

**Cards.** Per-file `.card` wrapper counts, confirmed by direct grep across all 29 modified views: `_form_fields.blade.php` — **0**. `_contacts.blade.php` — **4** (three KPI-shaped stat/count cards at the top of the file, plus one separate card wrapping the DataTables table). `Contacts/import.blade.php`, `subscribe_form.blade.php`, `unsubscribe_form.blade.php`, `contactGroups/show.blade.php` — **0 at the top level** (each is a tab-shell/panel-only file; any cards belonging to their content live inside the partials/layout they include or extend, not in the file itself). Every other file in the 29 has exactly **1** card. The claim "every page's single outer `.card` wrapper" is therefore false for at least these five files.

**Buttons.** Mechanical `grep` of every `btn-*` class across all 29 files, cross-referenced against whether each occurrence is real static Blade markup or a SweetAlert2 `customClass` JS-string: `btn-primary` — 44 total, **20 inside `confirmButton:`/`cancelButton:` JS strings, 24 genuine static Blade markup**. `btn-outline-danger` — 22 total, **20 inside JS strings** (`cancelButton: 'btn btn-outline-danger ms-1'`, appearing once per SweetAlert2 flow), **only 2 genuine static occurrences** (`customer/opportunities/show.blade.php`'s and `admin/opportunities/show.blade.php`'s "Dismiss opportunity" submit buttons). `btn-outline-primary` (21, genuine), `btn-outline-secondary` (8, genuine) — both real static Blade markup, no JS-string presence found. `btn-secondary` (solid, not outline) — 3 genuine static occurrences (`_contacts.blade.php`'s Import link and its export-modal close button, `_import_history.blade.php`'s disabled `<span>`). `btn-success` — 7 genuine static occurrences (4 "Add New" links, 2 "add keyword" links, 1 "Confirm and start" submit button). `btn-info` — 3 genuine static occurrences (3 "Export" links). `btn-outline-warning` — 2 genuine static occurrences (2 "Reset" buttons on the Blacklists create forms). `btn-relief-{primary,success,info,warning,danger}` — 5 occurrences, all on `<span>` elements in `_fields.blade.php` (field-type pickers driving `data-*`-attribute JS behavior, not `<button>`/`<a>` tags at all).

**Buttons — tag classification (post-round exception, Exception C).** `<x-button>` always renders `<button>` or `<a>` — it cannot render a `<label>`. Mechanically re-classifying every genuine occurrence of the three currently-supported classes by actual enclosing tag:

| Class | Genuine occurrences | `<button>`/`<a>` (tag-compatible) | `<label>` (tag-incompatible) |
|---|---|---|---|
| `btn-primary` | 24 | 24 | 0 |
| `btn-outline-primary` | 21 | 6 (5 `<button>`, 1 `<a>`) | **15** |
| `btn-outline-secondary` | 8 | 8 (6 `<a>`, 2 `<button>`) | 0 |

The 15 `<label>` occurrences are Bootstrap's own radio-button-group pattern — `<label class="btn btn-outline-primary" for="...">` paired with `<input type="radio" class="btn-check">` — found identically in `Contacts/paste_text.blade.php` (5: the delimiter radio group), `Blacklists/create.blade.php` customer (5: the delimiter radio group), and `Blacklists/create.blade.php` admin (5: the same). Replacing any of these with `<x-button>` would break the control group's `for`/`btn-check` pairing and its JS-free toggle behavior. **These 15 remain native `<label>` markup**, correcting the previously-implied adopted total from 53 (24+21+8) to a mechanically exact **38** (24+6+8).

**Dialog.** `_contacts.blade.php`'s `#exportContactModal`: `<div class="modal-dialog modal-lg" role="document"><div class="modal-content"><form method="post" action="...">` — the `<form>` element opens immediately inside `.modal-content` and closes only after `.modal-footer`, meaning it wraps the header, body, and footer as a single submittable unit. The modal also uses plain `modal-lg`, never `modal-dialog-centered`.

**Badge.** `_import_history.blade.php` line 25: `class="badge {{ $job->status == 'done' ? 'bg-success' : ($job->status == 'failed' ? 'bg-danger' : ($job->status == 'running' ? 'bg-info' : 'bg-secondary')) }}"` — four distinct visual states, one of which (`running` → `bg-info`) has no representable `<x-badge>` variant.

**Input/select.** `_form_fields.blade.php` (and, by the same repeatable-row pattern, `_fields.blade.php`): every field name uses PHP-array bracket syntax (`fields[__index__][label]`, `fields[__index__][uid]`, etc.), several inputs are direct children of a `.input-group` alongside a sibling `.input-group-text`, several are `type="hidden"` or `type="checkbox"` inside `.form-check.form-switch` wrappers — none of this is representable by `<x-input>`'s single labeled-field-in-its-own-`ds-field`-div API. By contrast, `Blacklists/create.blade.php`'s `reason` field, `admin/opportunities/index.blade.php`'s `business_id` field, `customer/opportunities/show.blade.php`'s `value` field, and the `status`/`freshness`/`worker_key` filter selects (customer + admin index pages) plus the `duration` selects (both opportunity show pages) are each a standalone, non-plugin, non-bracketed-name, non-`.input-group`-nested control with a flat option list where applicable — genuinely safe adoption candidates.

**`business_id`, mechanically resolved (post-round exception, Exception A).** `admin/opportunities/index.blade.php` lines 46-47: `<input type="number" name="business_id" min="1" class="form-control" placeholder="Business ID" value="{{ $filters['business_id'] }}">` — no `id` attribute, no `.input-group` nesting, no Select2/Flatpickr/plugin class. A repository-wide search of `resources/js/**` and the view itself for any selector referencing `business_id` (by `id`, `name`, or class) found **zero matches**. **Locked decision: adopted, no discretion left open** — this corrects Round 2's own internal contradiction, which listed `business_id` as one of the four adopted `<x-input>` controls in one sentence while calling it an unresolved "judgment call resolved at implementation time" in the next; the mechanical evidence supports only the former, so the latter hedge is withdrawn.

**Table.** The four DataTables-shell tables (`contactGroups/index.blade.php`, `contactGroups/show.blade.php`, `Blacklists/index.blade.php` ×2) rely on per-column classes/behavior `<x-table>`'s flat-string-only `headers` array cannot express (the `colvis`-extension column-visibility toggle on `show.blade.php`, the checkbox-selection column, the responsive-control column). `admin/opportunities/show.blade.php`'s two plain history tables use `<thead class="table-primary">`, a `<thead>`-level class `<x-table>` does not support. **Round 2 initially classified the three native-paginator tables (`customer/opportunities/index.blade.php`, `admin/opportunities/index.blade.php`, `admin/opportunities/runs/index.blade.php`) as safe, based on their plain-text headers (including blank entries for control/link columns, representable as empty strings in a flat `headers` array) and apparent lack of plugin coupling — that classification is superseded below, not a current binding claim.** **Exception D's direct source re-check supersedes that Round-2 conclusion (post-round exception, mechanical, this pass):** `admin/opportunities/index.blade.php` line 56 and `admin/opportunities/runs/index.blade.php` line 37 both actually use `<thead class="table-primary">` — the identical `<thead>`-level-class incompatibility already used above to non-adopt `admin/opportunities/show.blade.php`'s two history tables. Only `customer/opportunities/index.blade.php` (line 49, plain classless `<thead>`) is genuinely compatible. **The current, final finding is: of the three views Round 2 originally listed as safe, only one actually is.**

**Empty-state / alert node ownership, mechanically resolved (post-round exception, Exception E).** Across the six Opportunity views: `customer/opportunities/index.blade.php` lines 42-44 — exactly one `@if ($opportunities->isEmpty())` block-level `<div class="alert alert-secondary mb-0">No opportunities are available right now.</div>` node. `customer/opportunities/show.blade.php` lines 15-20 — two genuine flash/validation nodes (`session('status') === 'success'` → `alert-success`; validation-errors → `alert-danger`). `admin/opportunities/show.blade.php` lines 23-30 — two genuine flash/validation nodes (dynamic `alert-{{ success ? success : danger }}`; validation-errors → `alert-danger`). Every other candidate (`admin/opportunities/index.blade.php` line 82, `admin/opportunities/show.blade.php` lines 213/265, `admin/opportunities/runs/index.blade.php` line 59, `admin/opportunities/runs/show.blade.php` line 118) is a table-cell `@empty` row, never block-level. **Exactly one node in the entire slice is eligible for `<x-empty-state>`; exactly four nodes (all flash/validation, never the empty-result node itself) are eligible for `<x-alert>`; zero table-cell `@empty` rows are eligible for either component**, since every table containing one is itself natively retained (see the corrected Table finding above).

---

## 4. Locked Slice 5 scope

- The 30 files inventoried in §3.1, **minus** `_segments.blade.php` — **29 existing Blade views**.
- **No new production file of any kind.**
- Three new, mechanically-derived test files (§8).
- No controller, route, middleware, FormRequest, model, or migration file. No `app/`, `database/`, or `routes/` path of any kind.
- No other path.

---

## 5. Component adoption

**Final standard, restated:** use a canonical component only where its actual, current, read API is DOM- and behavior-compatible with the existing surface it would replace. Otherwise retain native Bootstrap markup — it is already runtime-token-compliant via `_runtime-bindings.scss`, so a non-adoption is never an incomplete migration, it is a deliberate, correct decision. Three categories are distinguished explicitly below: **component adoption**, **deliberate native token-bound retention**, and **third-party/plugin retention**.

### 5.1 Cards — corrected (Correction A)

**Adopted:** the single `.card` wrapper present in 23 of the 29 files (every file with exactly one card per §3.11) adopts `<x-card>` cleanly.
**Not adopted — deliberate native retention:**
- `_form_fields.blade.php` — has no card at all; not a card-adoption surface of any kind.
- `_contacts.blade.php`'s three KPI-shaped stat/count cards — structurally analogous to the dashboard stat-card pattern Slice 3 deliberately left native (title/count in a non-`<x-card>`-header shape); **not forced into `<x-card>` merely to raise adoption count.** Its fourth card (the table wrapper) **is** adopted, since it is a plain single-card-around-a-table shape matching every other adopted instance.
- `Contacts/import.blade.php`, `subscribe_form.blade.php`, `unsubscribe_form.blade.php`, `contactGroups/show.blade.php` — no top-level card exists in these files; not card-adoption surfaces.

### 5.2 Buttons — corrected (Correction B)

**Adopted, exact subset (38 total — corrected this pass, post-round Exception C):** `variant="primary"` for the 24 genuine static `btn-primary` occurrences, all `<button>`/`<a>` tags; `variant="outline"` for **6** of the 21 genuine `btn-outline-primary` occurrences — only those that are actual `<button>`/`<a>` tags (5 `<button>`, 1 `<a>`); `variant="secondary"` for the 8 genuine `btn-outline-secondary` occurrences, all `<button>`/`<a>` tags (confirmed: the component's `secondary` variant emits `btn-outline-secondary`, an exact match for this specific class, not for solid `btn-secondary`).
**Not adopted — deliberate native retention, with the exact reason:**
- **15 of the 21 genuine `btn-outline-primary` occurrences — corrected this pass, Exception C.** These are `<label class="btn btn-outline-primary" for="...">` elements paired with `<input type="radio" class="btn-check">` (Bootstrap's own radio-button-group pattern), found in `Contacts/paste_text.blade.php` (5), `Blacklists/create.blade.php` customer (5), and `Blacklists/create.blade.php` admin (5). `<x-button>` cannot render a `<label>` or preserve the `for`/`btn-check` pairing — converting these would break the control group's semantics.
- Solid `btn-secondary` (3 occurrences) — no `<x-button>` variant emits a solid secondary button; the component's own `secondary` variant is outline-only.
- `btn-success` (7), `btn-info` (3), `btn-outline-warning` (2) — no matching variant exists in the component's enum at all.
- `btn-outline-danger`, genuine static occurrences only (2, the two "Dismiss opportunity" buttons) — the component's `danger` variant emits **solid** `btn-danger`, not outline; adopting it would change the button's visual weight/semantics, not merely restyle it.
- `btn-relief-*` (5, on `<span>` elements) — the component always renders a real `<button>` or `<a>` tag; these are non-button `<span>` widgets driving JS click-delegation via `data-*` attributes, an incompatible tag/semantics change.
**Not component-adoption-relevant at all:** the 20 `btn-primary`-in-`confirmButton` and 20 `btn-outline-danger`-in-`cancelButton` SweetAlert2 JS-string occurrences — these are JavaScript configuration values, not Blade markup, exactly analogous in kind to §3.4's Category 2 icon findings; they are untouched by this slice regardless of any Blade component decision. Every natively-retained button above keeps its exact class/tag, but any static `data-feather` icon child it contains still migrates to `<x-ds-icon>` (§3.4/§6). `<x-button>` itself is not extended or modified.

### 5.3 Input / Select — corrected (Correction C)

**Adopted, exact safe subset — 11 controls total: 7 `<x-select>` + 4 `<x-input>` (header corrected this pass, post-round Exception A — Round 2's own header read "(7 total)" while enumerating 11):** `<x-select>` for the customer/admin Opportunity `status`/`freshness` filters (2 + 2) and admin's additional `worker_key` filter (1), plus the `duration` select on both Opportunity `show.blade.php` pages (2) — all standalone, flat-option-list, non-plugin, non-bracketed-name selects — **7 total**. `<x-input>` for `Blacklists/create.blade.php`'s `reason` field (customer + admin, 2), `customer/opportunities/show.blade.php`'s `value` field (1), and `admin/opportunities/index.blade.php`'s `business_id` field (1) — **4 total**, all standalone, non-plugin, non-`.input-group`-nested inputs. **`business_id` is firmly locked adopted, corrected this pass** — §3.11's Exception A confirms no `id`, no `.input-group` nesting, and no JS/CSS selector anywhere in the repository depends on its current shape; no discretion is left open. Where the existing markup pairs a control with a separate, external `<label for="...">` element, implementation replaces that native label+control pair as one unit with the component's own `:label` prop — never rendering both.
**Not adopted — deliberate native retention:** every dynamic per-field control in `Contacts/create.blade.php`/`show.blade.php` (Flatpickr-managed, variable control type per `ContactGroupFields::getControlNameByType()`); every bracketed-array-name field in `_fields.blade.php`/`_form_fields.blade.php` (`fields[__index__][...]`, several nested in `.input-group`/`.form-check.form-switch`); every Select2-controlled select (§3.5); the `Contacts/paste_text.blade.php` recipients textarea and its delimiter radio group's `<label>` controls (covered by §5.2's corrected button finding; no textarea component exists either).

### 5.4 Table — corrected (Correction F)

**Adopted: exactly one table — `customer/opportunities/index.blade.php`.** Corrected this pass, post-round Exception D: Round 2 originally claimed three native-paginator tables adopted `<x-table>`, but direct source inspection found two of the three (`admin/opportunities/index.blade.php`, `admin/opportunities/runs/index.blade.php`) both use `<thead class="table-primary">` — the same `<thead>`-level-class incompatibility already used below to non-adopt two other tables. Only `customer/opportunities/index.blade.php`'s plain, classless `<thead>` is genuinely compatible.
**Not adopted — deliberate native retention:**
- The four DataTables-shell tables (`contactGroups/index.blade.php`, `contactGroups/show.blade.php`, `Blacklists/index.blade.php` ×2) — their checkbox-selection column, responsive-control column, and (on `show.blade.php`) `colvis`-extension column-visibility toggling all depend on per-column classes/attributes `<x-table>`'s flat-string-only `headers` API cannot express. Kept as native `<table class="table datatables-basic">` markup, already token-bound.
- `admin/opportunities/show.blade.php`'s two history tables (transition history, execution history) — both use `<thead class="table-primary">`, a `<thead>`-level class the component does not support. Kept native.
- **`admin/opportunities/index.blade.php` and `admin/opportunities/runs/index.blade.php` — corrected this pass, Exception D.** Both use `<thead class="table-primary">`, mechanically identical to the incompatibility immediately above. Kept native.

Every natively-retained table keeps its exact `<thead>`/column structure, but any static `data-feather` icon in its surrounding page markup (outside the table itself) still migrates per §3.4/§6. `<x-table>` itself is not modified.

### 5.5 Dialog — corrected (Correction D)

**Not adopted.** `_contacts.blade.php`'s `#exportContactModal` is moved to explicit non-adoption: its `<form>` spans the entire `.modal-content` region (header through footer) as one submittable unit, while `<x-dialog>` auto-renders its own header outside any slot the caller controls, with no API for a form element to wrap across the component's own header/body/footer boundary. The modal additionally uses plain `modal-lg`, never the component's always-applied `modal-dialog-centered`. Adopting the component would require restructuring form ownership or accepting a visible centering/structure change — outside a presentation-only slice's authority. **The existing Bootstrap modal markup is preserved exactly, already token-bound via `_runtime-bindings.scss`.** `<x-dialog>` itself is not modified. Since dialog is non-adopted, no `ds-dialog` marker-class assertion is required by §8's component-adoption test for this surface.

### 5.6 Badge — corrected (Correction E)

**Not adopted for `_import_history.blade.php`'s status pill.** The existing 4-way state (`done`/`failed`/`running`/other) requires a genuine `info` visual distinct from `accent` (which carries brand-primary styling, a different meaning), and `<x-badge>`'s enum has no `info` variant — mapping `running` to `accent` would be a real semantic change, not a preservation. **The entire nested-ternary badge expression remains native Bootstrap markup, already token-bound.** `<x-badge>` is not extended and no `variant="info"` is invented.
**Adopted, unchanged from the original audit:** the Opportunity status/freshness badges (`customer/opportunities/index.blade.php`/`show.blade.php`) — their existing `bg-light-primary`/`bg-light-success`/`bg-light-warning` states map exactly, without loss, to `accent`/`success`/`warning`.

### 5.7 Alert / Empty-State — corrected this pass (post-round Exception E)

Round 2's own wording ("Opportunity empty-state and flash/validation-error alerts" for `<x-alert>`, "Opportunity empty-message blocks" for `<x-empty-state>`) was ambiguous enough to require both components for the same `customer/opportunities/index.blade.php` empty-result node. §3.11's mechanical audit resolves this: one node receives exactly one component decision.

**Adopted, `<x-empty-state>`: exactly one node** — `customer/opportunities/index.blade.php`'s "No opportunities are available right now." block. **This node is not also treated as an `<x-alert>` candidate.**

**Adopted, `<x-alert>`: exactly four nodes**, all genuine flash/validation-error alerts, structurally distinct from the empty-result node above: `customer/opportunities/show.blade.php`'s session-success and validation-error alerts (2); `admin/opportunities/show.blade.php`'s session-status and validation-error alerts (2).

**Not adopted:** every table-cell `@empty` row across the six Opportunity views — each lives inside a table that is itself natively retained (§5.4), so its row stays native table markup, never restructured into a block-level `<x-empty-state>`. DataTables' own `sZeroRecords` strings are likewise untouched (runtime JS, not static Blade markup).

- **`<x-tooltip>`** — `_contacts.blade.php`'s single tooltip trigger, confirmed this round as still a clean structural match (§3.2).
- **`<x-ds-icon>`** — the 79 Category-1 static `data-feather` occurrences only (§3.4); Category 2/3 explicitly untouched.
- **`<x-pagination>`** — **not adopted anywhere** (Round 1's Correction C, unchanged); the three native-paginator pages keep their existing `->links()` calls exactly.
- **`<x-tabs>`, native `<textarea>`, native `<input type="file">`/Dropzone, Select2/Flatpickr-rendered controls, `<x-menu>`** — all non-adopted, unchanged reasoning from Round 1.

No component is forced anywhere its real, read API does not match the existing markup's shape or behavior. §8's `ContactsCrmComponentAdoptionTest` asserts presence **only** for the exact adoptions locked in §5.1–5.4 and §5.7's Alert/Empty-State/Tooltip/Icon items — never for a non-adopted surface, and never requiring any intentionally-retained native Bootstrap markup to disappear.

---

## 6. Preserve all behavior

Unchanged in substance from Round 1, restated in full: no controller, route, request, middleware, authorization rule, cache key, or query's actual filtering/scoping logic changes for styling convenience. Route/controller/security behavior is read-only to this implementation until §7's prerequisite is satisfied. Slice 5 must preserve exactly: every route/method/middleware stack on the post-remediation baseline; every controller/repository's exact data-building/mutation logic per that baseline; every AJAX endpoint, form action/method, SweetAlert2 payload shape, and DataTables configuration named in §3.5/§3.10; `contactGroups/show.blade.php`'s inline script in place (§3.6); the Feather browser runtime intact for every Category 2/3 call site (§3.4); the existing `$collection->appends(...)->links()` calls unmodified (§5.7); every element `id`/`name`/`data-*` attribute; every `@can`/`@canany` gate; every localization key already present; the exact subscribe/unsubscribe form behavior; the dead `_segments.blade.php` pane, untouched. **New this round:** every native Bootstrap card/button/badge/dialog/table markup explicitly named as "not adopted" in §5 is preserved in its exact current **structural/semantic** shape — a non-adoption decision is itself a preserve-behavior requirement, not merely the absence of one.

**Clarified, post-round exception (Exception B):** "preserved in its exact current structural/semantic shape" means the element's tag/role, Bootstrap variant classes, form ownership, input type/name/value semantics, IDs, `data-*` hooks, plugin selectors/classes, modal/table structure, status-class semantics, and route/method/CSRF/payload behavior — it does **not** mean a natively-retained element's static Category-1 `data-feather` child is frozen too. Every one of the 79 Slice-5-owned static `data-feather="..."` occurrences still migrates to its verified `<x-ds-icon>` equivalent (§3.4) regardless of whether the element containing it is adopted or natively retained — the native-retention decision and the icon-migration decision are independent, evaluated per node. Concretely: a natively-retained `btn-success`/`btn-info`/solid-`btn-secondary` link keeps its exact class but its `data-feather` child still migrates; the natively-retained export modal keeps its exact form/header/footer structure but its `data-feather="info"`/`"check-square"`/`"download"`/`"x"` children still migrate; the natively-retained `_import_history.blade.php` badge expression is untouched (it contains no icon itself), but unrelated static icons elsewhere in that same partial still migrate; the natively-retained DataTables-shell and `table-primary` tables keep their exact `<thead>`/column structure, but any static icon in the surrounding page markup still migrates.

---

## 7. The authorization gap — mandatory pre-Slice-5-implementation prerequisite

Unchanged from Round 1. §3.8 contains the full audit evidence. The security defect remains outside this contract's own implementation allowlist (§9). A separate, dedicated CRM tenant-isolation security-remediation contract must be drafted, human-reviewed, and human-merged, and its own implementation must likewise be human-merged, **before** Slice 5 implementation may be authorized.

**Correction D (Round 1, unchanged):** the remediation must scope every customer single-record/batch Contacts/ContactGroups/Blacklists action to the authenticated customer's own ownership boundary, and must **not** convert admin Blacklists' intentionally global list/search/export visibility into customer-style tenant restriction — it must instead preserve that global model while independently closing the customer-originated exploit path through the currently-shared unscoped repository code.

**Correction E (Round 1, unchanged):** the remediation must explicitly audit and resolve the disposition of both stored-XSS-shaped findings (§3.8) — mechanically concluding non-exploitability or remediating directly — never leaving either unexamined by the time Slice 5 implementation is authorized.

**Correction F (Round 1, refined this round for internal consistency — Correction G of this round).** Once the remediation's implementation is merged, Slice 5's own future implementation authorization must pin: (1) the exact CRM security-remediation implementation merge SHA; (2) the exact then-current `origin/main` SHA Slice 5 implementation is based on; (3) the exact focused security test file(s)/command(s) the remediation introduces. Slice 5 implementation must then: leave those security test files completely unchanged; run them **before** Slice 5's own three new focused tests (§8), requiring zero failures; preserve the remediation's customer tenant-isolation behavior and its intentional admin global-access model exactly. **The no-diff requirement is scoped precisely, refined this round to avoid an internally impossible rule:**
- Every remediation-changed path **outside** Slice 5's own 29-view allowlist (routes, controllers, repositories, requests, policies, and any other non-Blade-view production path) must remain byte-clean (`git diff <post-remediation-base>...HEAD` empty) relative to the authorized post-remediation base throughout Slice 5 implementation — this is the actual, checkable no-diff boundary.
- If the remediation legitimately modified one of Slice 5's own 29 Blade views to resolve an XSS finding (§3.8, Correction E), that view remains eligible to receive its already-authorized §9 presentation changes — **the rule is not, and must not be read as, "that view's diff from the post-remediation base must be empty,"** since the entire purpose of Slice 5 is to modify exactly these 29 views. Instead: the remediation's own security-relevant encoding/escaping change inside that view must be preserved exactly (verified by the remediation's own pinned test(s), re-run per above, plus, where the remediation's own test coverage does not already directly assert the specific escaping behavior inside that view, a targeted addition to `ContactsCrmExistingBehaviorPreservedTest` proving the same encoding/escaping survives the presentation restyle).
- If the remediation adds an entirely new view path outside the current 29-item allowlist that Slice 5 would need to touch, or otherwise changes which 29 views constitute the correct presentation surface, Slice 5 implementation must STOP and re-audit (§15) — unchanged from Round 1.

**This contract does not, and cannot, hard-code a remediation merge SHA that does not yet exist.**

---

## 8. Test contract (Slice 5)

Three new files under `tests/Feature/DesignSystem/` (unchanged count from Round 1). No existing test file requires modification.

1. **`tests/Feature/DesignSystem/ContactsCrmDesignSystemContentTest.php`** — zero remaining Category-1 static `data-feather="..."` attributes in the 29 modified files; genuine `<x-ds-icon>`-equivalent markup present per migrated file, **including inside every natively-retained element named in §5** (§6's Exception-B clarification: native retention never exempts an in-scope icon child); MUST NOT require zero `feather.icons[...]` calls or any Feather-runtime change; MUST NOT require any `ContactsController.php` change. Zero hardcoded color/font-family literals introduced. Every DataTables `ajax.url`/Select2/Flatpickr selector and SweetAlert2 route target still present verbatim, including inside `contactGroups/show.blade.php`'s unrelocated script. The `->links()` calls on all three former native-paginator candidates still present, unreplaced. **Corrected this round, refined this pass:** every native-retained surface named in §5 (the KPI stat cards, the `btn-success`/`btn-info`/`btn-outline-warning`/solid-`btn-secondary`/`btn-relief-*`/genuine-`btn-outline-danger`/**the 15 label-based radio controls** buttons, the export modal's Bootstrap markup, the `_import_history` status badge, the four DataTables-shell tables, the two admin history tables, **`admin/opportunities/index.blade.php` and `admin/opportunities/runs/index.blade.php`'s `table-primary` tables**, the bracketed-name dynamic field inputs) is asserted **present and structurally unchanged**, never asserted absent, and never asserted to have lost its icon children.
2. **`tests/Feature/DesignSystem/ContactsCrmComponentAdoptionTest.php`** — **corrected this round, refined this pass: asserts presence only for the exact final adoption set locked in §5** — `<x-card>` on the 23 single-card files plus `_contacts.blade.php`'s table card (24 total card-marker assertions, explicitly excluding its 3 KPI cards and the 5 zero/non-top-level-card files); `<x-button>` only for the exact **38** compatible occurrences named in §5.2 (24 primary + 6 outline + 8 secondary), never for the 15 label-based radio controls; `<x-select>`/`<x-input>` only for the exact **11** controls named in §5.3 (7 + 4, including `business_id`, firmly locked); `<x-table>` only for the **1** genuinely compatible table (`customer/opportunities/index.blade.php`), never for `admin/opportunities/index.blade.php` or `admin/opportunities/runs/index.blade.php`; `<x-alert>` for exactly **4** nodes and `<x-empty-state>` for exactly **1** node per §5.7, never both on the same node; `<x-tooltip>` per §5.7. **Never asserts `ds-dialog` or a `running`-state `<x-badge>` marker**, since both are non-adopted.
3. **`tests/Feature/DesignSystem/ContactsCrmExistingBehaviorPreservedTest.php`** — unchanged scope from Round 1 (the 15-surface behavior-preservation matrix, §10), plus, per §7's refined Correction F/G, a targeted assertion of the remediation's own encoding/escaping behavior surviving the restyle wherever the remediation modified one of Slice 5's own 29 views and its own test coverage does not already directly assert that inside the view.

**Ordering requirement (§7):** the security-remediation's own pinned test file(s) run first, zero failures required, before any of the three files above.

**Regression baseline**: full existing suite re-run at Slice 5's own final head, 3 new files passing, zero regression in any pre-existing test (most directly the 37 `tests/Feature/Opportunity/` files), exact count reported. This contract itself does not run that suite.

---

## 9. Exact implementation allowlist (Slice 5)

**Closed, numbered, path-level, exactly 32 unique sequential entries — unchanged count from Round 1; this round adds zero paths and removes none, only correcting the per-item component-adoption annotations. Stop threshold: 33rd path.**

### Contacts views (8 modified)

1. `resources/views/customer/Contacts/create.blade.php`
2. `resources/views/customer/Contacts/import.blade.php` — no top-level card (§5.1); no icon/component change beyond nav-pill polish.
3. `resources/views/customer/Contacts/import/mapping.blade.php`
4. `resources/views/customer/Contacts/import_file.blade.php`
5. `resources/views/customer/Contacts/paste_text.blade.php` — its 5 `<label class="btn btn-outline-primary">` delimiter-radio controls are not adopted (§5.2, Exception C).
6. `resources/views/customer/Contacts/show.blade.php`
7. `resources/views/customer/Contacts/subscribe_form.blade.php` — no top-level card (§5.1); remains on `fullLayoutMaster` (§3.7).
8. `resources/views/customer/Contacts/unsubscribe_form.blade.php` — same constraints as item 7.

### contactGroups views (11 modified — `_segments.blade.php` excluded, §3.6)

9. `resources/views/customer/contactGroups/_contacts.blade.php` — **corrected this round**: adopts `<x-card>` for its table card only, not its 3 KPI cards (§5.1); adopts `<x-tooltip>`; does **not** adopt `<x-dialog>` for `#exportContactModal` (§5.5, native retained); button adoption limited to the exact static primary/outline occurrences it contains (§5.2).
10. `resources/views/customer/contactGroups/_fields.blade.php` — no `<x-input>`/`<x-select>` adoption (bracketed dynamic names, §5.3).
11. `resources/views/customer/contactGroups/_form_fields.blade.php` — no `<x-card>` (§5.1: zero cards exist); no `<x-input>`/`<x-select>` adoption (§5.3).
12. `resources/views/customer/contactGroups/_import_history.blade.php` — **corrected this round**: does **not** adopt `<x-badge>` for the status pill (§5.6, native retained).
13. `resources/views/customer/contactGroups/_message.blade.php`
14. `resources/views/customer/contactGroups/_opt_in_keywords.blade.php` — its `btn-success` "Add New" link is not adopted (§5.2).
15. `resources/views/customer/contactGroups/_opt_out_keywords.blade.php` — same constraint as item 14.
16. `resources/views/customer/contactGroups/_settings.blade.php`
17. `resources/views/customer/contactGroups/create.blade.php`
18. `resources/views/customer/contactGroups/index.blade.php` — **corrected this round**: does **not** adopt `<x-table>` (DataTables-shell, §5.4, native retained); its `btn-success`/`btn-info` links are not adopted (§5.2); its 5 JS `feather.icons[...]` calls (including the SweetAlert `copy` occurrence) remain unchanged (§3.4).
19. `resources/views/customer/contactGroups/show.blade.php` — inline script not extracted (§3.6); does **not** adopt `<x-table>` (§5.4); its 4 JS `feather.icons[...]` calls remain unchanged.

### Blacklists views (4 modified)

20. `resources/views/customer/Blacklists/create.blade.php` — adopts `<x-input>` for the `reason` field (§5.3); its `btn-outline-warning` Reset button is not adopted; its 5 `<label class="btn btn-outline-primary">` delimiter-radio controls are not adopted (§5.2, Exception C).
21. `resources/views/customer/Blacklists/index.blade.php` — **corrected this round**: does **not** adopt `<x-table>` (§5.4); its `btn-success` link is not adopted; 1 JS `feather.icons[...]` call unchanged.
22. `resources/views/admin/Blacklists/create.blade.php` — same constraints as item 20, including its own 5 `<label class="btn btn-outline-primary">` delimiter-radio controls not adopted.
23. `resources/views/admin/Blacklists/index.blade.php` — **corrected this round**: does **not** adopt `<x-table>` (§5.4); its `btn-success`/`btn-info` links are not adopted; 1 JS `feather.icons[...]` call unchanged.

### Opportunities views (6 modified)

24. `resources/views/customer/opportunities/index.blade.php` — adopts `<x-table>` (§5.4, **the only `<x-table>` adoption in the entire slice, corrected this pass**), `<x-select>` for its 2 filters (§5.3), and `<x-empty-state>` for its 1 empty-result node (§5.7, **not also `<x-alert>` on that same node**); does not adopt `<x-pagination>` (§5.7); its "Apply Filters"/"Clear Filters"/per-row "View" buttons adopt `variant="primary"`/`"secondary"`/`"outline"` respectively (§5.2).
25. `resources/views/customer/opportunities/show.blade.php` — adopts `<x-select>` for `duration`, `<x-input>` for `value`, and `<x-alert>` for its 2 flash/validation nodes (§5.3/§5.7); its `btn-success`/`btn-outline-danger` static buttons are not adopted (§5.2); its `btn-primary`/`btn-outline-primary`(×3)/`btn-outline-secondary` buttons adopt.
26. `resources/views/admin/opportunities/index.blade.php` — **corrected this pass, Exception D: does NOT adopt `<x-table>`** (its `<thead class="table-primary">` is the identical incompatibility already used to non-adopt two other tables, §5.4); adopts `<x-select>` for its 3 filters and `<x-input>` for `business_id` (§5.3, **firmly locked adopted this pass, Exception A**); does not adopt `<x-pagination>`; its "Filter" button adopts `variant="primary"`.
27. `resources/views/admin/opportunities/show.blade.php` — adopts `<x-select>` for `duration` and `<x-alert>` for its 2 flash/validation nodes (§5.3/§5.7); its `btn-outline-danger` static button is not adopted; its `btn-outline-primary`/`btn-outline-secondary`(×3) buttons adopt; its two history tables do **not** adopt `<x-table>` (§5.4, `table-primary` `<thead>` class unsupported).
28. `resources/views/admin/opportunities/runs/index.blade.php` — **corrected this pass, Exception D: does NOT adopt `<x-table>`** (its `<thead class="table-primary">` is the identical incompatibility, §5.4); does not adopt `<x-pagination>`; its "Back to opportunities" link adopts `variant="secondary"`.
29. `resources/views/admin/opportunities/runs/show.blade.php` — its "Back to runs" link adopts `variant="secondary"`; table was never proposed for `<x-table>` adoption.

### New focused tests (3 new)

30. `tests/Feature/DesignSystem/ContactsCrmDesignSystemContentTest.php`
31. `tests/Feature/DesignSystem/ContactsCrmComponentAdoptionTest.php`
32. `tests/Feature/DesignSystem/ContactsCrmExistingBehaviorPreservedTest.php`

**Counts** — Production views: **29**. Test: **3**. **Overall total: 32. Stop threshold: 33.**

---

## 10. Behavior-preservation matrix

Unchanged from Round 1 in its 15-surface shape; the "Critical DOM/JS contract" column now additionally reflects this round's corrected component decisions (native card/button/badge/dialog/table retention named explicitly per surface in §9's own item annotations above, not repeated a third time here to avoid drift between two descriptions of the same fact).

---

## 11. Responsiveness review

Unchanged from Round 1: existing Bootstrap breakpoints only, no new breakpoint system. Every DataTables-backed table's Responsive extension must be confirmed still functioning through this round's now-corrected **native-retention** decision for those exact tables (§5.4) — since they are no longer being wrapped in `<x-table>` at all, the Responsive extension's existing configuration is entirely undisturbed by this slice, an even lower-risk position than the original claim.

---

## 12. Forbidden scope

Unchanged from Round 1: no product-behavior change; no `app/`/`database/`/`routes/` change of any kind, including the tenant-isolation fix (§7's exclusive scope); no narrowing of admin Blacklists' global visibility (§7's Correction D); no Slice 4/6/Campaigns/billing work; no new token/JS/CSS framework, no client-side icon library (§3.4), no new Blade→JS hydration seam (§3.6); no `AI-AUTONOMY-STATE.json` change; no automatic advancement. **New this round:** no extension or modification of `<x-card>`, `<x-button>`, `<x-dialog>`, `<x-badge>`, `<x-input>`, `<x-select>`, or `<x-table>` themselves — every non-adoption in §5 is resolved by retaining native markup, never by changing the shared component to fit.

---

## 13. Governance block

```
maximum_correction_rounds: 2
correction_round: 2
correction_round_is_final: true
post_round_docs_consistency_exception: human_approved
advance_automatically: false
start_automatically_after_contract_merge: false
implementation_requires_separate_human_authorization: true
implementation_blocked_until: "CRM tenant-isolation security remediation contract + implementation, both human-merged, admin-Blacklists global model preserved, both XSS findings resolved (§7)"
merge_authority: human_only
```

---

## 14. Mechanical searches (Slice 5, run at implementation time)

1. `grep -rniE "anthropic|claude"` across every path in §9 → zero matches (the contract's own references to `CLAUDE.md` and this search pattern are not violations).
2. `grep -c "data-feather"` across §9 items 1-29 (static Blade attributes only) → zero.
3. `grep -c "feather\.icons\["` across §9 items 18, 19, 21, 23 → unchanged from §3.4's count (5, 4, 1, 1 respectively, 11 total).
4. `grep -rnoE "#[0-9A-Fa-f]{3,8}"` across §9 items 1-29 → zero genuine color literals.
5. `git diff --stat -- app database routes` compared against §9 → **must be completely empty**.
6. `grep -c "resources/js/scripts/pages/contact-group-show.js"` anywhere in the changed-path set → zero.
7. `grep -n "@include" resources/views/customer/contactGroups/show.blade.php` → exactly the 8 live partials, `_segments` still commented-out.
8. `grep -c "links()" resources/views/customer/opportunities/index.blade.php resources/views/admin/opportunities/index.blade.php resources/views/admin/opportunities/runs/index.blade.php` → present, unchanged, on all three.
9. **New this round:** `grep -c "ds-card"` in `resources/views/customer/contactGroups/_form_fields.blade.php` → zero (§5.1: no card exists to adopt). `grep -c "ds-card"` in `resources/views/customer/contactGroups/_contacts.blade.php` → exactly 1 (the table card only, not the 3 KPI cards).
10. **New this round:** `grep -c "ds-dialog"` in `resources/views/customer/contactGroups/_contacts.blade.php` → zero (§5.5: not adopted).
11. **New this round:** `grep -n "bg-info"` in `resources/views/customer/contactGroups/_import_history.blade.php` → still present (§5.6: native badge retained, not migrated to `<x-badge>`).
12. **Corrected this pass, post-round Exception D:** `grep -c "ds-table"` across §9 items 18, 19, 21, 23, 26, 27's two history tables, 28 → zero for all **eight**; present in item 24 only (`customer/opportunities/index.blade.php`) — the sole `<x-table>` adoption in the entire slice.
13. Every one of the 30 distinct static icon names in §3.4's Category 1 individually confirmed against `vendor/technikermathe/blade-lucide-icons/resources/svg/{name}.svg` before use — never guessed, with `check-square` given explicit extra scrutiny.
14. `git diff --stat` against Slice 5's own authorized post-remediation baseline, scoped exactly per §7's refined no-diff rule: **every remediation-changed path outside the 29-item §9 view allowlist** (routes/controllers/repositories/requests/policies/etc.) → must be completely empty; a remediation-changed path that **is** inside the 29-item allowlist is expected to carry Slice 5's own additional presentation diff on top of the remediation's own change, and is not subject to this empty-diff check.
15. The security-remediation's own pinned test file(s) run and pass with zero failures **before** §9 items 30-32 run.
16. Final changed-path set equals §9's exact, sequential 1-32 allowlist.
17. `php artisan test` full-suite pass count compared against the pre-Slice-5 baseline, reported exactly.
18. **New this pass, post-round exception:** `grep -c '<label class="btn btn-outline-primary"'` across §9 items 5, 20, 22 → present, unchanged (15 total across the three files) — confirming these were not converted to `<x-button>` (Exception C).
19. **New this pass:** `grep -n 'name="business_id"'` in item 26's rendered output → present inside `<x-input>`-rendered markup, not a bare native `<input>` (Exception A, no remaining discretion).
20. **New this pass:** `grep -c "ds-empty-state"` in item 24 → exactly 1; `grep -c "ds-alert"` in item 24 → zero (Exception E: the empty-result node is never also an alert marker); `grep -c "ds-alert"` across items 25 and 27 → exactly 2 each (4 total).
21. **New this pass:** `grep -c "data-feather"` inside every natively-retained element named in §5 (KPI cards, native buttons, export modal, `_import_history` badge row, all seven natively-retained tables) → **zero** (confirming Category-1 icons inside natively-retained elements still migrated, per §6's Exception-B clarification — a non-adoption decision must never be read as also freezing an in-scope icon child).

---

## 15. Stop conditions

Unchanged from Round 1, plus, this round:

- **Slice 5 implementation must not begin at all unless §7's full prerequisite is satisfied** (remediation merged, SHA pinned, admin Blacklists global model preserved, both XSS findings resolved).
- If the post-remediation tree changes which 29 views are correct, or requires a path beyond §9's 32-item allowlist, STOP and re-audit.
- Any path beyond §9's 32-item allowlist — the **33rd** path.
- Any change to `app/`, `database/`, or `routes/` for any reason, including narrowing admin Blacklists' global model.
- Any of the 30 flagged static icon names lacks a confirmed Lucide equivalent.
- Any existing test fails for a reason not fixable within this slice's own allowlist.
- Any route/controller logic, `@can`/`@canany` gate, AJAX/form target, or CSRF handling changes as a side effect of restyling.
- `contactGroups/show.blade.php`'s inline script is relocated or its Blade-evaluated expressions altered beyond the markup it targets.
- Any `feather.icons[...]` call or the Feather runtime is removed or altered.
- `<x-pagination>` is adopted anywhere the existing `->links()` behavior would be replaced.
- **New this round:** `<x-card>` is adopted for any of `_contacts.blade.php`'s 3 KPI cards, or for `_form_fields.blade.php` (which has none).
- **New this round:** `<x-button variant="secondary">` or `variant="danger"` is used to represent solid `btn-secondary` or outline `btn-outline-danger` markup — a visual-semantics change the component's own emitted classes do not support.
- **New this round:** `<x-dialog>` is adopted for `_contacts.blade.php`'s `#exportContactModal` without first resolving its form-spans-header/footer structural incompatibility (§5.5) through a separately-authorized change — not authorized by this contract.
- **New this round:** `<x-badge variant="accent">` (or any other variant) is used to represent the `_import_history.blade.php` `running`/`bg-info` state.
- **New this round:** `<x-table>` is adopted for any of the four DataTables-shell tables or the two `table-primary`-headed admin history tables.
- **Corrected this pass, post-round Exception D:** `<x-table>` is adopted for `admin/opportunities/index.blade.php` or `admin/opportunities/runs/index.blade.php` — both use `<thead class="table-primary">`, the identical incompatibility that already excludes the two admin history tables. Only `customer/opportunities/index.blade.php` may adopt `<x-table>`.
- **New this pass, post-round Exception C:** `<x-button>` is used to replace any of the 15 `<label class="btn btn-outline-primary">` delimiter-radio controls in `Contacts/paste_text.blade.php` or either `Blacklists/create.blade.php`.
- **New this pass, post-round Exception A:** `business_id` in `admin/opportunities/index.blade.php` is left as a native, non-adopted `<input>`, or its adoption is treated as an open implementation-time discretion rather than a locked decision.
- **New this pass, post-round Exception E:** the `customer/opportunities/index.blade.php` empty-result node is rendered with both `<x-empty-state>` and `<x-alert>` markers simultaneously, or `<x-alert>`/`<x-empty-state>` is applied to any table-cell `@empty` row.
- **New this pass, post-round Exception B:** any static Category-1 `data-feather` icon inside a natively-retained element (a native button, the export modal, the `_import_history` badge partial, or any natively-retained table) is left unmigrated on the theory that its container's native-retention status exempts it — §6's Exception-B clarification is binding: native retention freezes only the structural/semantic shell, never an in-scope icon child.
- **New this round:** any shared component (`card`, `button`, `dialog`, `badge`, `input`, `select`, `table`) is extended or modified to make a non-adopted surface fit.
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is found necessary to reference.
- A genuine business-logic change is found necessary to make any of the 29 views render or behave correctly.

---

## 16. Contract self-audit

1. Full current inventory mechanically re-derived, unchanged and re-confirmed. ✓
2. Sequential numbering, no duplicates, stop threshold = 33 (32 + 1), unchanged this round. ✓
3. Zero new production paths (unchanged from Round 1's own correction). ✓
4. No invented test, permission, route, component API, or token — this round specifically corrects six prior over-broad or false component-adoption claims against direct, mechanical re-inspection of both the component source and the actual Slice-5 markup (§3.11, §5.1–§5.6). ✓
5. Public vs. authenticated forms correctly distinguished, unchanged. ✓
6. Security defect treated as a hard blocker (§7), sharpened in Round 1 and refined this round only for internal consistency of the no-diff rule (§7's Correction G) — no substantive narrowing of the prerequisite. ✓
7. Icon audit distinction (Round 1) stands unchanged. ✓
8. `contactGroups/show.blade.php` decomposition decision (Round 1) stands unchanged. ✓
9. **Component adoption/non-adoption decisions are now exact and mechanically verified for cards, buttons, dialog, badge, input/select, and table — no generic "every X adopts" statement remains anywhere in §5.** ✓
10. `docs/automation/AI-AUTONOMY-STATE.json` untouched. ✓
11. Slice 4 and Slice 6 untouched and explicitly out of scope. ✓
12. No implementation authorization granted anywhere. ✓
13. This document remains the only file changed on this branch. ✓
14. **This is Correction Round 2 of a maximum of 2 — the final allowed correction round for this contract.** ✓
15. **The post-round docs consistency exception applied to this document is the sole, already-human-approved exception — not Correction Round 3, not a second exception — and it reconciles exactly five clerical/mechanical contradictions (input/select count, `business_id` discretion, native-retention/icon-migration wording, button tag classification, table/alert/empty-state node ownership) without reopening any Round 2 architectural conclusion.** ✓

---

## 17. Verification and publication

1. §9's numbered items counted mechanically, confirmed equal to exactly 32, sequential, no gap, no repeat.
2. Every path in §9 checked for uniqueness.
3. Mechanical search confirming zero stale binding claims remain: "every page's single outer .card", any universal-adoption phrasing for buttons/inputs/selects, "no btn-success"/"no btn-info"/"no btn-warning", `<x-dialog>` framed as a direct fit, `running → accent`, universal `<x-table>` adoption without a compatibility caveat, any `<x-pagination>` adoption, any JS-extraction or JS-Feather-migration requirement, any 34-path/35-path reference, and any security no-diff wording that would prohibit a legitimate presentation change to an already-remediated in-scope Blade view — all confirmed absent from this corrected document.
4. **This pass's own additional mechanical sweep, post-round exception:** zero remaining occurrences of "(7 total)" as the combined input/select count; zero remaining "judgment call"/implementation-time-discretion language for `business_id`; zero wording implying native retention forbids the static-icon migration; zero claim that all `btn-outline-primary` occurrences are `<x-button>`-compatible; zero requirement that a `<label>` control become `<x-button>`; zero claim that `admin/opportunities/index.blade.php` or `admin/opportunities/runs/index.blade.php` adopts `<x-table>`; zero "three native-paginator tables adopt" phrasing; zero `ds-table` expectation on items 26 or 28; zero requirement that the same Opportunity empty-result node become both `<x-alert>` and `<x-empty-state>` — all confirmed absent.
5. `git diff --check` — clean.
6. `git diff --name-only origin/main...HEAD` — exactly one path: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md`.
7. `git diff 7ee291456b8932c3e06f589f05971e345369daf4...HEAD -- docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` reviewed to confirm every changed paragraph traces directly to one of the five approved exception items or its governance marker — no unrelated wholesale prose compaction or rewrite.
8. `git status --short` — clean after commit.
9. Stage individually, never `git add -A`/`.`.
10. Commit message: `docs: restore Slice 5 contract scope after exception`.
11. Push to `origin chore/design-system-m2-slice5-contacts-crm-contract` — normal push, never forced.
12. PR #175 already exists — do not open another, do not merge it.
13. **Do not merge. Do not begin Slice 5 implementation. Do not begin the CRM security remediation. Do not begin Slice 4, Slice 6, or any other slice/initiative.** No test is run for this docs-only change.

---

*End of Design System M2 Slice 5 Contract, Correction Round 2 (final) + human-approved post-round docs-only consistency exception (scope-repaired). Implementation requires a separate, explicit human instruction. This contract's own merge does not start or resume it. Slice 5 implementation is blocked until the separate CRM tenant-isolation security remediation named in §7 is complete — contracted, human-merged, implemented, and human-merged, with the admin Blacklists global model preserved and both stored-XSS-shaped findings resolved — and its exact merge SHA is pinned in Slice 5's own later, separate implementation authorization.*
