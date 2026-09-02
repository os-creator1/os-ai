# Design System — Milestone 2, Slice 5 Contract: Contacts & CRM

**This document is fully self-contained.** No section below requires consulting an earlier commit to understand Slice 5's complete rules — every requirement, architecture decision, and path is restated here in full.

**Status: contract only. No implementation has occurred under this document. Merging this contract does NOT authorize implementation. Slice 5 implementation cannot be authorized AT ALL until a separate, dedicated CRM tenant-isolation security remediation is drafted, human-reviewed, human-merged, and its own implementation human-merged (§7).**

**Correction Round 1** withdrew an invalid JS-extraction authorization, corrected the icon audit's static-vs-runtime distinction, moved `<x-pagination>` to non-adoption, and sharpened the security-remediation prerequisite.

**Correction Round 2** mechanically re-audited every §5 component-adoption claim against the actual component source and the actual Slice-5 markup, correcting false/over-broad claims for cards, buttons, dialog, badge, input/select, and table.

**Post-Round Consistency Exception (this pass).** `maximum_correction_rounds: 2`, `correction_round: 2`, and `correction_round_is_final: true` are unchanged — **this is not Correction Round 3.** The human has explicitly approved one narrowly-bounded, docs-only exception to reconcile five clerical/mechanical contradictions discovered during final review, none of which reopen the contract's architecture or grant any implementation authority: (A) §5.3's header said "7 total" while enumerating 11 controls, and `business_id` was described both as adopted and as an unresolved "judgment call" — the count is corrected to 11 (7 select + 4 input) and `business_id`'s adoption is mechanically confirmed and locked, no discretion left open; (B) wording that could be read as freezing native-retained markup "byte-identical" is corrected to make explicit that a native-retention decision freezes only the structural/semantic shell, never an in-scope static `data-feather` child icon, which still migrates to `<x-ds-icon>` per Category 1; (C) the button audit is re-run classifying every genuine `btn-primary`/`btn-outline-primary`/`btn-outline-secondary` occurrence by actual HTML tag, revealing 15 of the 21 `btn-outline-primary` occurrences are `<label>` elements pairing with `btn-check` radio inputs — `<x-button>` cannot represent a `<label>` and these are corrected to native retention; (D) direct source inspection found `admin/opportunities/index.blade.php` and `admin/opportunities/runs/index.blade.php` both use `<thead class="table-primary">`, the same incompatibility already used to non-adopt two other tables — both are corrected from "adopted" to native retention, leaving exactly one `<x-table>` adoption in the entire slice; (E) the single "No opportunities are available right now." node was ambiguously eligible for both `<x-alert>` and `<x-empty-state>` — corrected to lock it as `<x-empty-state>` only, with `<x-alert>` reserved for the four genuine, structurally distinct flash/validation-error alerts on the two Opportunity `show.blade.php` pages. No other conclusion is reopened.

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice5-contacts-crm-contract`, based on `origin/main` at `437f12a51b8d036db055ba6ddafd89ab2ec9199a` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` at the start of this pass.
- `maximum_correction_rounds: 2`
- `correction_round: 2`
- `correction_round_is_final: true`
- `post_round_docs_consistency_exception: human_approved` — a one-time, human-approved, docs-only consistency amendment applied after Correction Round 2 completed, reconciling the five clerical/mechanical contradictions named above. It is **not** a third correction round, does not reopen any architectural decision, and grants no implementation or remediation authority beyond what Round 2 already established.
- **Slice 4 (Reports & Analytics) is deliberately, explicitly skipped by human choice.**
- Slice 5 is the rollout-map group "Contacts & CRM," historically listed as 30 files — independently re-derived and confirmed (§3.1), not trusted from that figure alone.
- `advance_automatically: false`. `start_automatically_after_contract_merge: false`.
- Stop threshold for Slice 5 implementation: the **33rd** path (32 allowlisted + 1) — unchanged.
- This contract authorizes only drafting/correcting this one document. It does not authorize any other slice or initiative and makes zero change to `docs/automation/AI-AUTONOMY-STATE.json`.
- **This contract does not fix, and is not authorized to fix, the severe pre-existing cross-tenant authorization gap documented in §3.8.** A separate, dedicated CRM tenant-isolation security-remediation contract must be drafted, human-reviewed, and merged, and its own implementation must be human-merged, before Slice 5 implementation may be authorized (§7).

---

## 1. Mandatory preflight — verified

1. `git fetch origin --tags` — `origin/main` confirmed at exactly `437f12a51b8d036db055ba6ddafd89ab2ec9199a`, unchanged since original drafting.
2. Starting branch HEAD for this pass confirmed at exactly `7ee291456b8932c3e06f589f05971e345369daf4` (Correction Round 2's own commit), working tree clean before any edit.
3. Read in full before drafting: `CLAUDE.md`, `AGENTS.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-CONTRACT.md`.
4. This pass additionally, directly re-verified via `grep`/`sed` against live source: every genuine (non-JS-string) occurrence of `btn-primary`, `btn-outline-primary`, and `btn-outline-secondary` across all 29 Slice-5 views, classified by actual enclosing HTML tag; `admin/opportunities/index.blade.php`'s and `admin/opportunities/runs/index.blade.php`'s `<thead>` markup; `admin/opportunities/index.blade.php`'s `business_id` input's complete markup and a repository-wide search for any JS/CSS selector depending on it; the exact empty-state/flash-alert/table-`@empty`-row nodes across all six Opportunity views.

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md`. No `resources/`, `app/`, `database/`, `routes/`, `tests/`, `config/`, package manifest, or `docs/automation/AI-AUTONOMY-STATE.json` file is touched.

---

## 3. Mandatory repository audit — findings

Unchanged from Correction Round 2 except §3.11, extended this pass with the five mechanical re-audits behind the consistency exception.

### 3.1 Current file inventory — unchanged

30 files, 6,607 lines, across the six owned directories (`customer/Contacts` 8, `customer/contactGroups` 12, `customer/Blacklists` 2, `customer/opportunities` 2, `admin/opportunities` 4, `admin/Blacklists` 2). `_segments.blade.php` (0 bytes) confirmed dead, excluded.

### 3.2 Component library — unchanged from Round 2, with one clarifying restatement

19 components in `resources/views/components/`. Restated for this pass's own findings: `<x-button>` cannot render a `<label>` element — it always renders `<button>` or `<a>` (§3.11). `<x-table>`'s `<thead>` rendering has no mechanism for a `<thead>`-level class such as `table-primary` (§3.11, already established for two other tables in Round 2, now found to apply to two more).

### 3.3 Color/token audit — unchanged

Zero hardcoded literals anywhere in the 30 files.

### 3.4 Icon audit — unchanged, with the native-retention interaction clarified (Exception B)

**Category 1 — static Blade `data-feather="..."`: 79 total occurrences, 30 distinct names — the sole Slice-5 migration target.** **Category 2 — runtime `feather.icons[...].toSvg(...)` JS calls: 11 occurrences, 6 distinct names, across 4 files — explicitly preserved.** **Category 3 — controller-generated dynamic `data-feather` markup: `ContactsController.php` lines 155-156 — outside Slice-5 view scope.**

**Clarified this pass, binding:** a "native retention" decision anywhere in §5 (for a card, button, dialog, badge, table, or any other structural element) freezes only that element's own structural/semantic shell — its tag, role, Bootstrap variant classes, form ownership, input type/name/value semantics, IDs, `data-*` hooks, plugin selectors/classes, and route/method/CSRF/payload behavior. **It does not freeze an otherwise-in-scope Category-1 static `data-feather` child node.** A `<i data-feather="name">` inside a natively-retained element (e.g., a native `btn-success` link, a native `btn-outline-warning` reset button, the native export modal, the native `_import_history.blade.php` badge row, or a native DataTables-shell table) still migrates to its verified `<x-ds-icon>` equivalent exactly as it would inside an adopted element — the native-retention decision and the icon-migration decision are independent, evaluated separately, per node. Concretely: `_contacts.blade.php`'s natively-retained `btn-success`/`btn-info`/solid-`btn-secondary` links keep their exact classes but their `data-feather="plus-circle"`/`"file-text"`/`"x"` children still migrate; `Blacklists/create.blade.php`'s natively-retained `btn-outline-warning` reset button keeps its class but its `data-feather="refresh-cw"` child still migrates; the natively-retained export modal keeps its exact form/header/footer structure but its `data-feather="info"`/`"check-square"`/`"download"`/`"x"` children still migrate; the natively-retained `_import_history.blade.php` badge expression is untouched by icon migration (it contains no icon itself), but unrelated static icons elsewhere in that same partial (`data-feather="download"` on the two download-link/disabled-span elements) still migrate.

### 3.5 DataTables / Select2 / Flatpickr / SweetAlert2 / Dropzone / modal / tabs audit — unchanged

Unchanged from Round 2.

### 3.6 `contactGroups/show.blade.php` decomposition — unchanged

No JS extraction, no new file. Inline script stays in place.

### 3.7 Public form determination — unchanged

`subscribe_form.blade.php`/`unsubscribe_form.blade.php` stay on `fullLayoutMaster`.

### 3.8 Authorization / tenant-isolation audit — unchanged

Full findings and both Correction-D/E prerequisite refinements from Round 1 stand unchanged.

### 3.9 Opportunity-surface preservation — unchanged

37 `tests/Feature/Opportunity/` files, 10 `tests/Unit/Opportunity/` files, re-run unmodified.

### 3.10 Form/mutation preservation — unchanged

### 3.11 Component-markup compatibility audit — extended this pass

**Exception A — `business_id` input, mechanically resolved.** `admin/opportunities/index.blade.php` lines 46-47: `<input type="number" name="business_id" min="1" class="form-control" placeholder="Business ID" value="{{ $filters['business_id'] }}">` — no `id` attribute, no `.input-group` nesting, no Select2/Flatpickr/plugin class. A repository-wide search of `resources/js/**` and the view itself for any selector referencing `business_id` (by `id`, `name`, or class) found **zero matches** — no JS or CSS depends on this field's current DOM shape. **Locked decision: adopted.** No discretion is left open.

**Exception C — button-class occurrences re-classified by actual HTML tag, mechanically enumerated.**

| Class | Genuine (non-JS-string) occurrences | `<button>`/`<a>` (tag-compatible) | `<label>` (tag-incompatible) |
|---|---|---|---|
| `btn-primary` | 24 | 24 | 0 |
| `btn-outline-primary` | 21 | 6 (5 `<button>`, 1 `<a>`) | **15** (5× `Contacts/paste_text.blade.php`, 5× `Blacklists/create.blade.php` customer, 5× `Blacklists/create.blade.php` admin — all `<label class="btn btn-outline-primary" for="...">` paired with a `btn-check` radio input) |
| `btn-outline-secondary` | 8 | 8 (6 `<a>`, 2 `<button>`) | 0 |

The 15 `<label>` occurrences are Bootstrap's own radio-button-group pattern (`<input type="radio" class="btn-check">` + `<label class="btn btn-outline-primary" for="...">`) — `<x-button>` cannot render a `<label>` element or preserve the `for`/`btn-check` pairing; replacing one would break the control group's semantics and JS-free toggle behavior. **These 15 remain native `<label>` markup.**

**Exception D — table-adoption set, mechanically corrected.** Direct source confirms `admin/opportunities/index.blade.php` line 56 and `admin/opportunities/runs/index.blade.php` line 37 both use `<thead class="table-primary">` — the identical incompatibility Round 2 already used to non-adopt `admin/opportunities/show.blade.php`'s two history tables. `customer/opportunities/index.blade.php` (line 49) uses a plain, classless `<thead>`. **Of the three views Round 2 claimed as `<x-table>` adoptions, only one is genuinely compatible.**

**Exception E — empty-state/alert node audit, mechanically resolved.** Across the six Opportunity views: `customer/opportunities/index.blade.php` line 42-44 — exactly one `@if ($opportunities->isEmpty())` block-level `<div class="alert alert-secondary mb-0">No opportunities are available right now.</div>` node. `customer/opportunities/show.blade.php` lines 15-20 — two genuine flash/validation nodes (`session('status') === 'success'` → `alert-success`; validation-errors → `alert-danger`). `admin/opportunities/show.blade.php` lines 23-30 — two genuine flash/validation nodes (dynamic `alert-{{ success ? success : danger }}`; validation-errors → `alert-danger`). `admin/opportunities/index.blade.php` line 82, `admin/opportunities/show.blade.php` lines 213/265, `admin/opportunities/runs/index.blade.php` line 59, `admin/opportunities/runs/show.blade.php` line 118 — each a table-cell `@empty` row (`<td colspan="...">No ... found.</td>`), never block-level. **Exactly one node in the entire slice is eligible for `<x-empty-state>`; exactly four nodes (all flash/validation, never the empty-result node) are eligible for `<x-alert>`; zero table-cell `@empty` rows are eligible for either component**, since every table containing one is itself natively retained (§5.4).

---

## 4. Locked Slice 5 scope

29 existing Blade views (30 minus `_segments.blade.php`). No new production file of any kind. Three new test files (§8). No `app/`, `database/`, `routes/` path of any kind.

---

## 5. Component adoption

**Standard:** use a canonical component only where its actual, current, read API is DOM- and behavior-compatible with the existing surface. Otherwise retain native Bootstrap markup — already runtime-token-compliant. A native-retention decision freezes the structural/semantic shell only, never an in-scope static icon child (§3.4).

### 5.1 Cards — unchanged from Round 2

Adopted: the single `.card` wrapper in 23 of 29 files, plus `_contacts.blade.php`'s table card. Not adopted: `_contacts.blade.php`'s 3 KPI cards; `_form_fields.blade.php` (no card exists); `Contacts/import.blade.php`, `subscribe_form.blade.php`, `unsubscribe_form.blade.php`, `contactGroups/show.blade.php` (no top-level card exists in these files).

### 5.2 Buttons — corrected this pass (Exception C)

**Adopted, exact final subset (38 total):**
- `variant="primary"` — **24** genuine static `btn-primary` occurrences, all on `<button>`/`<a>` tags.
- `variant="outline"` — **6** genuine static `btn-outline-primary` occurrences that are actual `<button>`/`<a>` tags (`_contacts.blade.php`'s "Columns" dropdown toggle; `customer/opportunities/index.blade.php`'s per-row "View" link; `customer/opportunities/show.blade.php`'s "Request approval"/"Reopen opportunity"/"Retry" submit buttons; `admin/opportunities/show.blade.php`'s "Reopen opportunity" submit button).
- `variant="secondary"` — **8** genuine static `btn-outline-secondary` occurrences, all `<button>`/`<a>` tags.

**Not adopted — deliberate native retention:**
- **15 `<label class="btn btn-outline-primary">` radio-group controls** (§3.11) — `<x-button>` cannot render a `<label>` or preserve the `btn-check`/`for` pairing.
- Solid `btn-secondary` (3), `btn-success` (7), `btn-info` (3), `btn-outline-warning` (2), `btn-relief-*` (5, on `<span>`s) — no matching variant or tag, unchanged from Round 2.
- The 2 genuine static `btn-outline-danger` occurrences ("Dismiss opportunity" buttons) — the component's `danger` variant emits solid `btn-danger`, a visual mismatch, unchanged from Round 2.
- The 20 `btn-primary`-in-`confirmButton` and 20 `btn-outline-danger`-in-`cancelButton` SweetAlert2 JS-string occurrences — not Blade markup, not component-adoption-relevant, unchanged from Round 2.

Every natively-retained button above keeps its exact class/tag, but any static `data-feather` icon child it contains still migrates to `<x-ds-icon>` (§3.4). `<x-button>` itself is not modified.

### 5.3 Input / Select — corrected this pass (Exception A)

**Adopted, exact subset: 11 controls total — 7 `<x-select>` + 4 `<x-input>`.**
- `<x-select>` (7): customer Opportunity `status`/`freshness` filters (2), admin Opportunity `status`/`freshness`/`worker_key` filters (3), `duration` selects on both Opportunity `show.blade.php` pages (2).
- `<x-input>` (4): `Blacklists/create.blade.php`'s `reason` field (customer + admin, 2), `customer/opportunities/show.blade.php`'s `value` field (1), and **`admin/opportunities/index.blade.php`'s `business_id` field (1) — mechanically confirmed compatible and locked as adopted, no open discretion** (§3.11's Exception A: no `id`, no `.input-group` nesting, no JS/CSS selector dependency found anywhere in the repository).

Where the existing markup pairs a control with a separate external `<label for="...">`, implementation replaces that native label+control pair as one unit with the component's own `:label` prop, never rendering both.

**Not adopted — deliberate native retention:** every dynamic per-field control in `Contacts/create.blade.php`/`show.blade.php`; every bracketed-array-name field in `_fields.blade.php`/`_form_fields.blade.php`; every Select2-controlled select; the `paste_text.blade.php` recipients textarea and delimiter radio group (radio labels covered by §5.2). `<x-input>`/`<x-select>` are not modified.

### 5.4 Table — corrected this pass (Exception D)

**Adopted: exactly one table — `customer/opportunities/index.blade.php`.** Plain, classless `<thead>`, zero plugin coupling.

**Not adopted — deliberate native retention:**
- `admin/opportunities/index.blade.php` and `admin/opportunities/runs/index.blade.php` — **corrected this pass**: both use `<thead class="table-primary">`, a `<thead>`-level class `<x-table>` cannot emit, mechanically identical to the incompatibility already used for the next two items.
- `admin/opportunities/show.blade.php`'s two history tables — same `table-primary` incompatibility, unchanged from Round 2.
- The four DataTables-shell tables (`contactGroups/index.blade.php`, `contactGroups/show.blade.php`, `Blacklists/index.blade.php` ×2) — plugin-column-class incompatibility, unchanged from Round 2.

Every natively-retained table keeps its exact `<thead>`/column structure, but any static `data-feather` icon inside its surrounding page markup (outside the table itself) still migrates per §3.4. `<x-table>` is not modified.

### 5.5 Dialog — unchanged from Round 2

Not adopted. `_contacts.blade.php`'s `#exportContactModal` keeps its exact native Bootstrap modal/form structure (its `<form>` spans header through footer, which `<x-dialog>`'s auto-rendered-header API cannot represent). Its static `data-feather` children (`info`, `check-square`, `download`, `x`) still migrate per §3.4. `<x-dialog>` is not modified.

### 5.6 Badge — unchanged from Round 2

Not adopted for `_import_history.blade.php`'s 4-way status pill (`bg-info`'s `running` state has no `<x-badge>` equivalent). Adopted, unchanged: the Opportunity status/freshness badges (`accent`/`success`/`warning`, exact matches).

### 5.7 Alert / Empty-State — corrected this pass (Exception E)

**Adopted, `<x-empty-state>`: exactly one node** — `customer/opportunities/index.blade.php`'s "No opportunities are available right now." block. **This node is not also treated as an `<x-alert>` candidate** — a single node receives exactly one component decision.

**Adopted, `<x-alert>`: exactly four nodes**, all genuine flash/validation-error alerts, structurally distinct from the empty-result node above: `customer/opportunities/show.blade.php`'s session-success and validation-error alerts (2); `admin/opportunities/show.blade.php`'s session-status and validation-error alerts (2).

**Not adopted:** every table-cell `@empty` row across the six Opportunity views (`admin/opportunities/index.blade.php`, `admin/opportunities/show.blade.php` ×2, `admin/opportunities/runs/index.blade.php`, `admin/opportunities/runs/show.blade.php`) — each lives inside a table that is itself natively retained (§5.4), so its row stays native table markup, never restructured into a block-level `<x-empty-state>`.

### 5.8 Icon and other unchanged adoptions from Round 1/2

`<x-ds-icon>` for all 79 Category-1 static occurrences (§3.4, with the native-retention interaction clarified this pass). `<x-tooltip>` for `_contacts.blade.php`'s one trigger. `<x-pagination>` not adopted anywhere. `<x-tabs>`, native `<textarea>`, native file input/Dropzone, Select2/Flatpickr controls, `<x-menu>` — all non-adopted, unchanged.

No component is forced anywhere its real API does not match. §8's component-adoption test asserts presence **only** for the exact final sets in §5.1–§5.4 and §5.6–§5.8.

---

## 6. Preserve all behavior

Unchanged in substance from Round 2: no controller/route/request/middleware/authorization/cache/query logic changes for styling; route/controller/security behavior read-only until §7's prerequisite is satisfied; every AJAX endpoint, form action/method, SweetAlert2 payload, DataTables configuration, element `id`/`name`/`data-*` attribute, `@can`/`@canany` gate, localization key, and the dead `_segments.blade.php` pane preserved exactly. Every native Bootstrap card/button/badge/dialog/table markup named "not adopted" in §5 is preserved in its exact current **structural/semantic** shape (§3.4's Exception-B clarification) — **this specifically does not mean its static Category-1 icon children are frozen too; those still migrate to `<x-ds-icon>` per §3.4 regardless of whether their containing element is adopted or natively retained.**

---

## 7. The authorization gap — mandatory pre-Slice-5-implementation prerequisite

Unchanged from Round 1/2. The security defect remains outside this contract's own implementation allowlist. A separate, dedicated CRM tenant-isolation security-remediation contract must be drafted, human-reviewed, and human-merged, and its own implementation must likewise be human-merged, **before** Slice 5 implementation may be authorized. The remediation must scope every customer single-record/batch Contacts/ContactGroups/Blacklists action to the authenticated customer's own ownership boundary, must **not** narrow admin Blacklists' intentionally global visibility, and must explicitly resolve (not merely note) both stored-XSS-shaped findings (§3.8). Once merged, Slice 5's own future implementation authorization must pin the exact remediation merge SHA, the exact then-current `origin/main` SHA, and the remediation's own focused security test file(s), which run first, unchanged, with zero failures, before Slice 5's own three tests. The no-diff requirement applies to every remediation-changed path **outside** the 29-view allowlist; a remediation-changed view that is also one of the 29 remains eligible for its already-authorized presentation changes, with the remediation's own security-relevant behavior verified as preserved rather than requiring an empty diff. **This contract does not, and cannot, hard-code a remediation merge SHA that does not yet exist.**

---

## 8. Test contract (Slice 5)

Three new files under `tests/Feature/DesignSystem/`, unchanged count.

1. **`ContactsCrmDesignSystemContentTest.php`** — zero remaining Category-1 static `data-feather` in the 29 files; genuine `<x-ds-icon>` markup present per migrated file, **including inside every natively-retained element named in §5** (§3.4's Exception-B clarification: native retention never exempts an in-scope icon child); MUST NOT require zero `feather.icons[...]` calls or any Feather-runtime/controller change. Zero hardcoded literals introduced. Every native-retained surface named in §5 (KPI cards, `btn-success`/`btn-info`/`btn-outline-warning`/solid-`btn-secondary`/`btn-relief-*`/`btn-outline-danger`-genuine/**the 15 label-based radio controls**, the export modal, the `_import_history` badge, all six natively-retained tables **including `admin/opportunities/index.blade.php` and `admin/opportunities/runs/index.blade.php`**, the bracketed-name dynamic inputs) is asserted present and structurally unchanged — never asserted absent, and never asserted to have lost its icon children.
2. **`ContactsCrmComponentAdoptionTest.php`** — asserts presence only for the exact final adoption set: `<x-card>` (24 markers: 23 single-card files + `_contacts.blade.php`'s table card); `<x-button>` (38 markers: 24 primary + 6 outline + 8 secondary); `<x-select>`/`<x-input>` (11 markers: 7 + 4, including `business_id`); `<x-table>` (**1 marker only** — `customer/opportunities/index.blade.php`); `<x-dialog>`/`running`-state `<x-badge>` — never asserted (both non-adopted); `<x-alert>` (**4 markers**); `<x-empty-state>` (**1 marker**, and never also an `<x-alert>` marker on that same node); `<x-tooltip>`, `<x-ds-icon>` per §3.4.
3. **`ContactsCrmExistingBehaviorPreservedTest.php`** — unchanged scope (§10), plus the remediation-encoding-preservation assertion per §7 where applicable.

**Ordering**: the security-remediation's own pinned test file(s) run first, zero failures required, before these three.

**Regression baseline**: full existing suite, 3 new files passing, zero regression, exact count reported. This contract does not run that suite.

---

## 9. Exact implementation allowlist (Slice 5)

**Exactly 32 unique sequential entries — unchanged count. This pass corrects only the per-item component-adoption annotations for items 24, 26, 28 and adds none, removes none. Stop threshold: 33rd path.**

### Contacts views (8)

1. `resources/views/customer/Contacts/create.blade.php`
2. `resources/views/customer/Contacts/import.blade.php`
3. `resources/views/customer/Contacts/import/mapping.blade.php`
4. `resources/views/customer/Contacts/import_file.blade.php`
5. `resources/views/customer/Contacts/paste_text.blade.php` — its 5 `<label class="btn btn-outline-primary">` delimiter-radio controls are not adopted (§5.2, Exception C).
6. `resources/views/customer/Contacts/show.blade.php`
7. `resources/views/customer/Contacts/subscribe_form.blade.php`
8. `resources/views/customer/Contacts/unsubscribe_form.blade.php`

### contactGroups views (11 — `_segments.blade.php` excluded)

9. `resources/views/customer/contactGroups/_contacts.blade.php` — adopts `<x-card>` (table card only), `<x-tooltip>`; does not adopt `<x-dialog>`; its "Columns" dropdown-toggle button adopts `<x-button variant="outline">`.
10. `resources/views/customer/contactGroups/_fields.blade.php`
11. `resources/views/customer/contactGroups/_form_fields.blade.php` — no card exists (§5.1); no input/select adoption.
12. `resources/views/customer/contactGroups/_import_history.blade.php` — status badge not adopted; its `data-feather="download"` icons still migrate.
13. `resources/views/customer/contactGroups/_message.blade.php`
14. `resources/views/customer/contactGroups/_opt_in_keywords.blade.php`
15. `resources/views/customer/contactGroups/_opt_out_keywords.blade.php`
16. `resources/views/customer/contactGroups/_settings.blade.php`
17. `resources/views/customer/contactGroups/create.blade.php`
18. `resources/views/customer/contactGroups/index.blade.php` — does not adopt `<x-table>`; 5 JS `feather.icons[...]` calls unchanged.
19. `resources/views/customer/contactGroups/show.blade.php` — inline script not extracted; does not adopt `<x-table>`; 4 JS `feather.icons[...]` calls unchanged.

### Blacklists views (4)

20. `resources/views/customer/Blacklists/create.blade.php` — adopts `<x-input>` for `reason`; its 5 `<label class="btn btn-outline-primary">` delimiter-radio controls are not adopted.
21. `resources/views/customer/Blacklists/index.blade.php` — does not adopt `<x-table>`; 1 JS `feather.icons[...]` call unchanged.
22. `resources/views/admin/Blacklists/create.blade.php` — same as item 20.
23. `resources/views/admin/Blacklists/index.blade.php` — does not adopt `<x-table>`; 1 JS `feather.icons[...]` call unchanged.

### Opportunities views (6)

24. `resources/views/customer/opportunities/index.blade.php` — adopts `<x-table>` (the only table adoption in the entire slice), `<x-select>` (2 filters), `<x-empty-state>` (1 node, not also `<x-alert>`); does not adopt `<x-pagination>`; its "Apply Filters" button adopts `variant="primary"`, "Clear Filters" adopts `variant="secondary"`, per-row "View" link adopts `variant="outline"`.
25. `resources/views/customer/opportunities/show.blade.php` — adopts `<x-select>` (`duration`), `<x-input>` (`value`), `<x-alert>` (2 nodes); its `btn-primary`/`btn-outline-primary`(×3)/`btn-outline-secondary` buttons adopt; its `btn-success`/`btn-outline-danger` buttons do not.
26. `resources/views/admin/opportunities/index.blade.php` — **corrected this pass: does NOT adopt `<x-table>`** (`table-primary` `<thead>`, §5.4/Exception D); adopts `<x-select>` (3 filters) and `<x-input>` (`business_id`, **firmly locked adopted, §5.3/Exception A**); its "Filter" button adopts `variant="primary"`.
27. `resources/views/admin/opportunities/show.blade.php` — adopts `<x-select>` (`duration`), `<x-alert>` (2 nodes); its `btn-outline-primary`/`btn-outline-secondary`(×3) buttons adopt, its `btn-outline-danger` button does not; its two history tables do not adopt `<x-table>`.
28. `resources/views/admin/opportunities/runs/index.blade.php` — **corrected this pass: does NOT adopt `<x-table>`** (`table-primary` `<thead>`, §5.4/Exception D); its "Back to opportunities" link adopts `variant="secondary"`.
29. `resources/views/admin/opportunities/runs/show.blade.php` — its "Back to runs" link adopts `variant="secondary"`; table was never proposed for adoption.

### New focused tests (3)

30. `tests/Feature/DesignSystem/ContactsCrmDesignSystemContentTest.php`
31. `tests/Feature/DesignSystem/ContactsCrmComponentAdoptionTest.php`
32. `tests/Feature/DesignSystem/ContactsCrmExistingBehaviorPreservedTest.php`

**Counts** — Production views: **29**. Test: **3**. **Overall total: 32. Stop threshold: 33.**

---

## 10. Behavior-preservation matrix

Unchanged 15-surface shape from Round 1; the "Critical DOM/JS contract" column now reflects this pass's corrected table/button/empty-state annotations, stated per-item in §9 above rather than restated a third time here.

---

## 11. Responsiveness review

Unchanged. The correction in §5.4 (two more tables now natively retained) further lowers risk — the DataTables/native-table Responsive behavior for those two pages is now entirely undisturbed by this slice.

---

## 12. Forbidden scope

Unchanged from Round 2: no product/route/controller/schema change; no narrowing of admin Blacklists' global visibility; no Slice 4/6/other-initiative work; no new token/JS/CSS framework or client-side icon library; no `AI-AUTONOMY-STATE.json` change; no automatic advancement; no extension or modification of `<x-card>`, `<x-button>`, `<x-dialog>`, `<x-badge>`, `<x-input>`, `<x-select>`, `<x-table>`, `<x-alert>`, or `<x-empty-state>` themselves.

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

1. `grep -rniE "anthropic|claude"` across §9 → zero matches.
2. `grep -c "data-feather"` across §9 items 1-29 (static) → zero.
3. `grep -c "feather\.icons\["` across §9 items 18, 19, 21, 23 → unchanged (5, 4, 1, 1; 11 total).
4. `grep -rnoE "#[0-9A-Fa-f]{3,8}"` across §9 items 1-29 → zero.
5. `git diff --stat -- app database routes` → empty.
6. `grep -c "resources/js/scripts/pages/contact-group-show.js"` → zero.
7. `grep -n "@include"` on `contactGroups/show.blade.php` → the 8 live partials, `_segments` still commented-out.
8. `grep -c "links()"` on the three former native-paginator candidates → present on all three (none replaced by `<x-pagination>`).
9. `grep -c "ds-card"` in `_form_fields.blade.php` → zero; in `_contacts.blade.php` → exactly 1.
10. `grep -c "ds-dialog"` in `_contacts.blade.php` → zero.
11. `grep -n "bg-info"` in `_import_history.blade.php` → still present.
12. **Corrected this pass:** `grep -c "ds-table"` across §9 items 18, 19, 21, 23, 26, 27's two history tables, 28 → zero for all eight; present in item 24 only (exactly one `<x-table>` adoption in the entire slice).
13. **New this pass:** `grep -c '<label class="btn btn-outline-primary"'` across §9 items 5, 20, 22 → unchanged, present (15 total across the three files) — confirming these were not converted to `<x-button>`.
14. **New this pass:** `grep -n 'name="business_id"'` in item 26 → present inside an `<x-input>`-rendered markup, not native `<input>`.
15. **New this pass:** `grep -c "ds-empty-state"` in item 24 → exactly 1; `grep -c "ds-alert"` in item 24 → zero (the empty-result node is never also an alert marker).
16. Every one of the 30 distinct static icon names confirmed against the pinned Lucide package's `.svg` files before use.
17. `git diff --stat` against the post-remediation baseline, scoped per §7's no-diff rule.
18. The security-remediation's own pinned tests run and pass before §9 items 30-32.
19. Final changed-path set equals §9's exact 1-32 allowlist.
20. `php artisan test` full-suite pass count compared against the pre-Slice-5 baseline, reported exactly.

---

## 15. Stop conditions

Unchanged from Round 2, plus, this pass:

- Slice 5 implementation must not begin unless §7's full prerequisite is satisfied.
- Any path beyond §9's 32-item allowlist — the 33rd path.
- Any change to `app/`, `database/`, or `routes/` for any reason, including narrowing admin Blacklists' global model.
- Any of the 30 flagged icon names lacks a confirmed Lucide equivalent.
- Any existing test fails for a reason not fixable within this slice's own allowlist.
- Any route/controller logic, gate, AJAX/form target, or CSRF handling changes as a side effect of restyling.
- `contactGroups/show.blade.php`'s inline script is relocated or its Blade-evaluated expressions altered beyond the markup it targets.
- Any `feather.icons[...]` call or the Feather runtime is removed or altered.
- `<x-pagination>` is adopted anywhere `->links()` would be replaced.
- `<x-card>` is adopted for any of `_contacts.blade.php`'s 3 KPI cards, or for `_form_fields.blade.php`.
- `<x-button variant="secondary">`/`variant="danger"` is used to represent solid `btn-secondary`/outline `btn-outline-danger` markup.
- **New this pass:** `<x-button>` is used to replace any of the 15 `<label class="btn btn-outline-primary">` radio-group controls.
- `<x-dialog>` is adopted for `_contacts.blade.php`'s export modal without a separately-authorized structural change.
- `<x-badge>` is used to represent the `_import_history.blade.php` `running`/`bg-info` state.
- **New this pass:** `<x-table>` is adopted for `admin/opportunities/index.blade.php`, `admin/opportunities/runs/index.blade.php`, or either of `admin/opportunities/show.blade.php`'s history tables, or for any DataTables-shell table.
- **New this pass:** the `customer/opportunities/index.blade.php` empty-result node is rendered with both `<x-empty-state>` and `<x-alert>` markers simultaneously, or `<x-alert>`/`<x-empty-state>` is applied to any table-cell `@empty` row.
- **New this pass:** any static Category-1 `data-feather` icon inside a natively-retained element is left unmigrated on the theory that its container's native-retention status exempts it (§3.4's Exception-B clarification is binding).
- Any shared component (`card`, `button`, `dialog`, `badge`, `input`, `select`, `table`, `alert`, `empty-state`) is extended or modified to make a non-adopted surface fit.
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is found necessary to reference.
- A genuine business-logic change is found necessary.

---

## 16. Contract self-audit

1. Full inventory unchanged and re-confirmed. ✓
2. Sequential numbering, no duplicates, stop threshold = 33, unchanged. ✓
3. Zero new production paths. ✓
4. All six component-adoption defects raised in this pass are corrected with mechanical evidence (§3.11, §5.2–§5.4, §5.7), not asserted. ✓
5. Public/authenticated form distinction, security blocker, icon audit, decomposition decision — all unchanged and re-confirmed. ✓
6. **No generic "every X adopts" statement remains anywhere in §5; every adoption is an exact, enumerated set with its own mechanical evidence.** ✓
7. `AI-AUTONOMY-STATE.json` untouched. ✓
8. Slice 4/6 untouched, explicitly out of scope. ✓
9. No implementation authorization granted. ✓
10. This document remains the only file changed on this branch. ✓
11. **`correction_round: 2` and `correction_round_is_final: true` are unchanged — this pass is a labeled, human-approved, docs-only consistency exception, not a third correction round, and does not imply any further round is available.** ✓

---

## 17. Verification and publication

1. §9's numbered items counted mechanically, confirmed exactly 32, sequential, no gap, no repeat.
2. Every path checked for uniqueness.
3. Mechanical search confirming zero live contradictions remain: "(7 total)" as the combined input/select count, `business_id` described as a judgment call, native retention wording that would forbid icon migration, all `btn-outline-primary` occurrences assumed compatible, `<label>` controls required to become `<x-button>`, `admin/opportunities/index.blade.php`/`runs/index.blade.php` required to adopt `<x-table>`, "three native-paginator tables adopt," `ds-table` expected on items 26/28, the same Opportunity empty-result node required to be both `<x-alert>` and `<x-empty-state>`, any 34/35-path reference, any JS-extraction/JS-Feather-migration requirement, any `<x-pagination>` adoption — all confirmed absent.
4. `git diff --check` — clean.
5. `git diff --name-only origin/main...HEAD` — exactly one path.
6. `git status --short` — clean after commit.
7. Stage individually, never `git add -A`/`.`.
8. Commit message: `docs: reconcile final Slice 5 contract invariants`.
9. Push to `origin chore/design-system-m2-slice5-contacts-crm-contract` — normal push, never forced.
10. PR #175 already exists — do not open another, do not merge it.
11. **Do not merge. Do not begin Slice 5 implementation. Do not begin the CRM security remediation. Do not begin any other slice/initiative.**

---

*End of Design System M2 Slice 5 Contract, Correction Round 2 + human-approved post-round docs-only consistency exception. Implementation requires a separate, explicit human instruction. Slice 5 implementation is blocked until the separate CRM tenant-isolation security remediation named in §7 is complete and its exact merge SHA is pinned in Slice 5's own later, separate implementation authorization.*
