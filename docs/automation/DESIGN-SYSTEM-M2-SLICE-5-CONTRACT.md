# Design System — Milestone 2, Slice 5 Contract: Contacts & CRM

**This document is fully self-contained.** No section below requires consulting an earlier commit, the Milestone 1 contract, the Milestone 2 contract, or any other slice contract to understand Slice 5's complete rules — every requirement, architecture decision, and path is restated here in full.

**Status: contract only. No implementation has occurred under this document. Merging this contract does NOT authorize implementation. In addition, per §7 below, Slice 5 implementation cannot be authorized AT ALL until a separate, dedicated CRM tenant-isolation security remediation is drafted, human-reviewed, human-merged, and its own implementation human-merged — a materially stronger precondition than a normal "implementation needs its own authorization" gate, because the defect found here sits directly inside the controllers this slice would restyle.**

**Correction Round 1 (this pass).** Independent review confirmed the contract branch was mechanically clean and the CRM tenant-isolation blocker (§7) is real, but found four binding defects in the original drafting pass, corrected throughout this document: (A) the original contract authorized extracting `contactGroups/show.blade.php`'s inline script into a plain static JS file, which is mechanically impossible without inventing a new Blade→JS data-hydration seam — that authorization is withdrawn (§3.6, §9); (B) the original icon audit conflated static `data-feather` Blade markup with runtime `feather.icons[...].toSvg()` JavaScript calls and undercounted the latter — both are now correctly distinguished and the JS calls (and one controller-generated dynamic occurrence) are explicitly preserved, not migrated (§3.4); (C) `<x-pagination>` was wrongly treated as a required, behavior-equivalent adoption for three native-paginator pages when the component's own markup is not equivalent to Laravel's default pagination controls — this is now an explicit non-adoption (§5); (D) the security-remediation prerequisite is sharpened so a future remediation cannot accidentally convert the admin Blacklists surface's intentionally global visibility into customer-style tenant scoping (§7); it additionally now requires the two stored-XSS-shaped findings to be explicitly examined and resolved, not merely "evaluated" (§7), and requires Slice 5 implementation to explicitly preserve and re-run the remediation's own security test baseline before its own focused tests (§7, §8). No architectural conclusion this correction did not name is reopened — the tenant-isolation finding itself, the file inventory, the public-form determination, the Opportunity contrast case, and every already-correct component adoption/non-adoption stand unchanged.

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice5-contacts-crm-contract`, in an isolated linked worktree (`../design-system-m2-slice5-contract-worktree`), based on `origin/main` at `437f12a51b8d036db055ba6ddafd89ab2ec9199a` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` both at this contract's original drafting pass and again at the start of this correction round. This SHA is the Design System M2 Slice 3 (Dashboards) implementation-correction merge (PR #174).
- **Correction Round 1 of a maximum of 2** (`maximum_correction_rounds: 2`). This round corrects the four defects named above; it does not consume authority for a hypothetical third round beyond the one remaining.
- **Slice 4 (Reports & Analytics) is deliberately, explicitly skipped by human choice.** This is not an oversight or a numbering accident. This contract does not touch, contract, or authorize anything under `customer/Reports/**` or `admin/Reports/**`.
- Slice 5 is the rollout-map group named **"Contacts & CRM"** in `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` §8, historically listed there as **30 files**. That historical figure is **not trusted** — §3.1 below mechanically re-derives the current tree from scratch. The re-derived count matches the historical figure exactly (30 files), but only because it was independently re-counted.
- `advance_automatically: false`. `start_automatically_after_contract_merge: false`. Merging this contract never starts implementation by itself, human or automated.
- Any path required during Slice 5 implementation but absent from §9's own numbered allowlist is a stop-and-report condition — not a silent workaround. The stop threshold is the **33rd** path (32 allowlisted + 1) — corrected down from the original drafting pass's 35th (34 + 1) per Correction A's removal of the invalid JS-extraction file and its dedicated test (§3.6, §9).
- This contract authorizes **only** drafting/correcting this one document. It does not authorize Reports & Analytics (Slice 4), ChatBox/Conversations (Slice 6), Campaigns (Slices 7a-c), Automations (Slice 8), Templates (Slice 9), Numbers/SenderID/Keywords/Compliance (Slice 10), Sending Servers (Slices 11a-d), Billing/Payments/Accounts (Slice 12), Sub-Accounts & Workspaces (Slice 13), Onboarding (Slice 14), Developer/API Docs (Slice 15), Admin Tenant Management (Slice 16), Plans/Pricing/Catalog (Slice 17), Invoices & Subscriptions (Slice 18), Admin Users/Roles/Announcements (Slice 19), Plugins/Legacy Theme Customizer (Slice 20), System Settings (Slice 21), or transactional email templates. It also does not authorize any COO, SEO, Outreach, Website Generator, Calendar, Ads, or any other new RFC/initiative outside the Design System track, and it makes zero change to `docs/automation/AI-AUTONOMY-STATE.json`.
- **This contract does not fix, and is not authorized to fix, the severe pre-existing cross-tenant authorization gap documented in §3.8 below.** A separate, dedicated CRM tenant-isolation security-remediation contract must be drafted, human-reviewed, and merged, and its own implementation must be human-merged, before Slice 5 implementation may be authorized (§7) — mirroring the Design System M2 Slice 3 dashboard-security precedent, sharpened this round to prevent the remediation from accidentally narrowing the admin Blacklists surface's intentionally global visibility (§7's Correction D), and to require the two stored-XSS-shaped findings to be explicitly resolved, not merely noted (§7's Correction E).

---

## 1. Mandatory preflight — verified

1. `git fetch origin --tags` — `origin/main` confirmed at exactly `437f12a51b8d036db055ba6ddafd89ab2ec9199a`, unchanged since this contract's original drafting pass.
2. Starting branch HEAD for this correction round confirmed at exactly `6f78d29a657c187123da8c311b0d235237cf1164` (the original contract-drafting commit), working tree clean before any edit.
3. Read in full before drafting: `CLAUDE.md`, `AGENTS.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md` (Milestone 1), `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` (Milestone 2 architecture and full 21-slice rollout map), `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-CONTRACT.md` (the most recent, directly-analogous prior slice contract — its audit methodology, security-blocker section shape, and allowlist/test-contract conventions are the direct template this document follows).
4. Read, directly, the actual merged component library and token/icon infrastructure — not assumed from contract prose. All 19 files in `resources/views/components/*.blade.php`; `resources/views/components/pagination.blade.php`'s exact markup (re-read this correction round, §5's Correction C); `resources/views/components/ds-icon.blade.php`'s exact server-rendered-only mechanism (re-read this correction round, §3.4's Correction B); `resources/js/core/theme-tokens.js`'s real shipped API; `resources/scss/base/tokens/_colors.scss` and `_runtime-bindings.scss`, confirming Design System M2 Slice 1 is genuinely merged at this contract's own base SHA.
5. This correction round additionally, mechanically re-verified: every `feather.icons[...]` occurrence across all 30 Slice-5 files (direct `grep`, both quote styles, §3.4's Correction B); every controller-generated `data-feather` occurrence reachable from a Slice-5 view (`ContactsController.php` lines 155-156, §3.4's Correction B); `webpack.mix.js`'s `mixAssetsDir('js/scripts/**/*.js', ...)` compilation rule, confirming files under `resources/js/scripts/**` are compiled as ordinary static JavaScript, never processed by Blade (§3.6's Correction A); the exact Blade-server-rendered constructs embedded throughout `contactGroups/show.blade.php`'s inline script (`{{ __(...) }}`, `{{ route(...) }}`, `{{ csrf_token() }}`, `$contact->uid`-derived route generation, `@foreach`, and server-rendered collection/JSON values such as `$contact_groups`) (§3.6's Correction A).

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` (this document). No `resources/`, `app/`, `database/`, `routes/`, or `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting or correcting this contract.

---

## 3. Mandatory repository audit — findings

All findings below are from direct repository inspection at `origin/main` `437f12a51b8d036db055ba6ddafd89ab2ec9199a` — unchanged from the original drafting pass except where this correction round's own re-verification (§1 item 5) is cited explicitly.

### 3.1 Current file inventory — mechanically re-derived, independently confirmed, unchanged this round

**`resources/views/customer/Contacts/` — 8 files, 1,290 lines:** `create.blade.php` (209), `import.blade.php` (48), `import/mapping.blade.php` (154), `import_file.blade.php` (147), `paste_text.blade.php` (166), `show.blade.php` (209), `subscribe_form.blade.php` (252), `unsubscribe_form.blade.php` (105).

**`resources/views/customer/contactGroups/` — 12 files, 3,262 lines:** `_contacts.blade.php` (228), `_fields.blade.php` (182), `_form_fields.blade.php` (76), `_import_history.blade.php` (60), `_message.blade.php` (105), `_opt_in_keywords.blade.php` (39), `_opt_out_keywords.blade.php` (39), `_segments.blade.php` (**0 — confirmed dead stub, §3.6**), `_settings.blade.php` (172), `create.blade.php` (137), `index.blade.php` (651), `show.blade.php` (1,573).

**`resources/views/customer/Blacklists/` — 2 files, 513 lines:** `create.blade.php` (109), `index.blade.php` (404).

**`resources/views/customer/opportunities/` — 2 files, 457 lines:** `index.blade.php` (93), `show.blade.php` (364).

**`resources/views/admin/opportunities/` — 4 files, 580 lines:** `index.blade.php` (97), `show.blade.php` (278), `runs/index.blade.php` (74), `runs/show.blade.php` (131).

**`resources/views/admin/Blacklists/` — 2 files, 505 lines:** `create.blade.php` (111), `index.blade.php` (394).

**Total: 30 files, 6,607 lines.** Unchanged from the original drafting pass — this correction round found no error in the inventory itself.

### 3.2 Current Design System component library — re-verified directly, unchanged this round except §5's Correction C

19 components exist in `resources/views/components/`: `alert`, `badge`, `branding-favicon`, `branding-footer`, `branding-illustration`, `branding-logo`, `button`, `card`, `dialog`, `ds-icon`, `empty-state`, `input`, `menu`, `pagination`, `select`, `switch-toggle`, `table`, `tabs`, `tooltip`. Exact prop APIs unchanged from the original drafting pass, with two re-confirmed this round for the corrections below:

- **`<x-pagination :paginator>`** (`resources/views/components/pagination.blade.php`, re-read this round) — renders exactly three controls: a Previous link/disabled-span, a static `currentPage / lastPage` indicator, and a Next link/disabled-span. It does **not** render Laravel's default full page-number pagination view (no numbered page links, no "..." ellipsis, no jump-to-page). **This is a materially different navigation capability than the framework's own `->links()` output currently in production use** (§5's Correction C) — not a presentation-only equivalent.
- **`<x-ds-icon :name :size :strokeWidth>`** (re-read this round) — its entire rendering mechanism is `{{ svg('lucide-' . $name, 'ds-icon', $attributes->merge(...)->getAttributes()) }}`, a **server-side Blade/PHP expression**, backed by `technikermathe/blade-lucide-icons`. It has no client-side/runtime JavaScript counterpart of any kind, and `package.json` contains no browser-side Lucide dependency. **It structurally cannot execute inside a `<script>` block or be called from JavaScript** — confirmed directly (§3.4's Correction B).

### 3.3 Color/token audit — zero hardcoded literals found, zero new SCSS required, unchanged this round

Confirmed, file-by-file, across all 30 files: zero hardcoded hex/`rgb()`/`rgba()`/`hsl()` color literals, zero `font-family` declarations. Design System M2 Slice 1 (confirmed merged, §1 item 4) already retokenizes every native Bootstrap class this slice's markup uses via `_runtime-bindings.scss`. Zero new token file, zero `_colors.scss` change, zero `_runtime-bindings.scss` change required.

### 3.4 Icon audit — corrected this round (Correction B), now distinguishing three separate categories

The original drafting pass conflated static Blade markup with runtime JavaScript calls and undercounted the latter — a real audit defect, corrected here with a full mechanical re-enumeration.

**Category 1 — static Blade `data-feather="..."` attributes (the genuine Slice-5 migration target).** `grep -roE 'data-feather="[a-zA-Z0-9-]*"'` across all six owned directories: **79 total occurrences, 30 distinct names** (`calendar`, `check`, `check-square`, `clock`, `copy`, `download`, `edit-3`, `eye`, `file-text`, `hash`, `info`, `message-circle`, `move`, `pie-chart`, `plus-circle`, `refresh-cw`, `save`, `server`, `settings`, `square`, `stop-circle`, `trash`, `trash-2`, `type`, `upload`, `user-check`, `user-minus`, `user-x`, `users`, `x`), unchanged from the original drafting pass — this figure was correct. `technikermathe/blade-lucide-icons` is pinned `v3.166.0` (`composer.lock`); `vendor/` is not installed in this docs-only worktree, so exact `.svg` verification is deferred to implementation time, never guessed. `check-square` is flagged with elevated scrutiny for a possible Lucide compound-name reordering (the same `adjective-noun` shape Slice 3 found reordered for `x-circle`/`check-circle`).

**Category 2 — runtime `feather.icons[...].toSvg(...)` JavaScript calls (explicitly, permanently outside this slice's own `<x-ds-icon>` migration — mechanically re-enumerated this round, corrected from the original pass's undercount):**

| File | Occurrences | Icon names used |
|---|---|---|
| `customer/contactGroups/index.blade.php` | 5 | `plus-circle` (×1, DataTables action-column render callback), `edit` (×1, same), `trash` (×1, same), `copy` (×2 — one inside the same DataTables render callback, and a **second, separate occurrence inside a SweetAlert2 `confirmButtonText`/copy-flow handler**, confirmed at line 323, distinct from the render-callback context — this second occurrence is exactly what the original audit missed) |
| `customer/contactGroups/show.blade.php` | 4 | `message-square`, `send`, `edit`, `trash` (all inside the `.datatables-basic` action-column render callback) |
| `customer/Blacklists/index.blade.php` | 1 | `trash` |
| `admin/Blacklists/index.blade.php` | 1 | `trash` |

**Total: 11 occurrences, 6 distinct names (`copy`, `edit`, `message-square`, `plus-circle`, `send`, `trash`), across 4 files** — not "two DataTables action-render callbacks" as the original drafting pass stated. `resources/views/customer/opportunities/*`, `admin/opportunities/*`, `Contacts/*`, and the remaining `contactGroups/*` partials have zero JS-embedded icon calls of this kind.

**Category 3 — controller-generated dynamic `data-feather` markup (outside Slice-5 view scope entirely, since no `app/Http/Controllers/**` path is authorized, §0/§4).** Confirmed by direct grep: `app/Http/Controllers/Customer/ContactsController.php` lines 155-156 build raw HTML server-side for the `contactGroups/index.blade.php` status-toggle switch, containing `<i data-feather='check'></i>`/`<i data-feather='x'></i>` literals inside a PHP string, not inside any Blade file this slice may touch.

**Corrected, binding decision:** Category 1 (static Blade `data-feather`) is the sole Slice-5 icon-migration target, adopting `<x-ds-icon>` where mechanically compatible. **Category 2 (JS `feather.icons[...]` calls) and Category 3 (controller-generated dynamic markup) are explicitly, permanently preserved unchanged by this slice** — `<x-ds-icon>` structurally cannot execute inside runtime JavaScript (§3.2), no client-side Lucide package exists in `package.json`, and this repository's own already-merged Slice 2 contract established the identical precedent for `auth/profile/index.blade.php`: JS-generated `feather.icons[...]` markup stays outside the static-migration boundary, and the Feather browser runtime (`feather.replace()` and the `feather.icons` object it exposes) remains loaded and available for these not-yet-migrated dynamic callers. Slice 5 does **not**: add a Lucide JS/npm dependency to `package.json`; add a new client-side icon renderer; modify `ds-icon.blade.php`; or modify `ContactsController.php` (or any controller) to emit Lucide markup instead of Feather.

### 3.5 DataTables / Select2 / Flatpickr / SweetAlert2 / Dropzone / modal / tabs audit — unchanged this round

- **DataTables (server-side)**: `contactGroups/index.blade.php` (AJAX → `customer.contacts.search`), `contactGroups/show.blade.php` (AJAX → `customer.contact.search`, `colvis` extension), `Blacklists/index.blade.php` ×2 (customer → `customer.blacklists.search`; admin → `admin.blacklists.search`, extra `user_id`/"listed by" column). All four use the `datatables.checkboxes` extension and a custom `responsive.details.display` modal renderer.
- **DataTables (client-side)**: `contactGroups/show.blade.php`'s two keyword tables (`.opt-in-keywords`, `.opt-out-keywords`).
- **Native Laravel paginator (no DataTables)**: `customer/opportunities/index.blade.php`, `admin/opportunities/index.blade.php`, `admin/opportunities/runs/index.blade.php` — all three render `{{ $collection->appends(...)->links() }}` (§3.2/§5's Correction C: **not** a `<x-pagination>` adoption target).
- **Select2**: `Contacts/import/mapping.blade.php`, `Contacts/subscribe_form.blade.php`, `contactGroups/_message.blade.php` (×2), `contactGroups/show.blade.php` (bulk copy/move + export modal), `contactGroups/_settings.blade.php` (flagged for implementation-time direct confirmation).
- **Flatpickr**: `Contacts/create.blade.php`, `Contacts/show.blade.php`, `Contacts/subscribe_form.blade.php`.
- **SweetAlert2**: `contactGroups/index.blade.php` (5 flows, including the `copy` flow named in §3.4's Category 2), `contactGroups/show.blade.php` (11 flows), `Blacklists/index.blade.php` ×2 (single + bulk delete).
- **Dropzone.js**: `Contacts/import_file.blade.php` only.
- **Bootstrap modal**: `contactGroups/_contacts.blade.php`'s `#exportContactModal` — the sole genuine Bootstrap-modal fit in this slice, a direct `<x-dialog>` target.
- **Tabs (nav-pills, not Bootstrap nav-tabs)**: `contactGroups/show.blade.php` (8 panes), `Contacts/import.blade.php`/`paste_text.blade.php` (2-item pair). `<x-tabs>` emits nav-*tabs* markup, a structurally different pattern — confirmed non-adoption, unchanged.
- **Tooltips**: exactly one, `contactGroups/_contacts.blade.php`'s "force export phone number" info icon — a direct `<x-tooltip>` fit.

### 3.6 `contactGroups/show.blade.php` — decomposition analysis and decision, corrected this round (Correction A)

1,573 lines; lines 260-1572 (≈1,313 lines, 83% of the file) are a single inline `page-script` block containing 3 DataTables initializations, 11 SweetAlert2 flows, and ~10 AJAX call sites.

**The original drafting pass's authorization to extract this script into `resources/js/scripts/pages/contact-group-show.js`, "byte-for-byte behavior-preserving," is mechanically false and is withdrawn this round.** Direct re-inspection of the inline script (§1 item 5) confirms it is saturated with server-rendered Blade constructs evaluated at request time — `{{ __('...') }}` translation calls, `{{ route(...) }}` URL generation, `{{ csrf_token() }}`, `$contact->uid`-derived route construction, `@foreach ($contact->getFields as $field)` loops emitting per-field JS, and server-rendered collection/JSON values such as `$contact_groups` interpolated directly into the script body. `webpack.mix.js`'s `mixAssetsDir('js/scripts/**/*.js', ...)` rule (confirmed by direct read, §1 item 5) compiles every file under `resources/js/scripts/**` as **ordinary static JavaScript — it is never processed by Blade.** A file at that path cannot contain `{{ }}`/`@foreach`/`{!! !!}` and have them evaluated; moving this script there verbatim would either silently break every Blade-evaluated expression in it or require inventing an entirely new Blade→JS configuration/data-hydration seam (e.g., a `window.__contactGroupShowConfig = @json(...)` bootstrap object the extracted file would then consume) — **that seam does not exist today, and creating one is itself a structural architecture change, unnecessary for and outside the authority of a presentation-only Design System slice.**

**Corrected, binding decision:** `contactGroups/show.blade.php` remains the sole owner of its own current `page-script` block. **No JS extraction. No new JS file. No new Blade partial.** The existing 9-partial tab decomposition (`_contacts`, `_settings`, `_message`, `_segments`, `_fields`, `_opt_in_keywords`, `_opt_out_keywords`, `_import_history`, plus the AJAX-fetched `_form_fields`) is confirmed sufficient and unchanged. Despite its large size, further decomposing the inline script is **not justified inside this presentation-only slice** because it is tightly coupled to Blade-rendered, request-time data — this is a genuine, disclosed, deliberately-not-pursued refactor opportunity, not a defect this contract silently ignores. Slice 5 implementation may restyle the *markup* this script targets (selectors, DOM structure it manipulates) and adopt DS components around it, but must not attempt to relocate, minify, or otherwise "clean up" the script merely for maintainability — every route target, AJAX payload shape, DataTables/SweetAlert2 configuration, and Blade-evaluated value inside it (§3.10) is preserved exactly, in place.

**`_segments.blade.php` (0 bytes)** — unchanged conclusion: confirmed genuinely empty, its nav-link Blade-commented-out, backing a documented-but-unshipped feature. Not modified by this slice, not in §9's allowlist.

### 3.7 Public form determination — unchanged this round

`subscribe_form.blade.php`/`unsubscribe_form.blade.php` are reached exclusively through `routes/public.php` (`web`-middleware-only, zero auth/gate check anywhere in the handling controller methods), already on `fullLayoutMaster`. **Decision, unchanged: these two views remain on `fullLayoutMaster`, unwrapped in customer dashboard chrome.**

### 3.8 Authorization / tenant-isolation audit — CRITICAL FINDING, sharpened this round (Correction D)

**Foundational mechanism, unchanged**: all authorization runs through `Gate::define()`-backed role/permission-type checks; there is no Eloquent global scope anywhere and no `Policy` class registered for `ContactGroups`, `Contacts`, or `Blacklists`.

**Confirmed defect — Customer Contacts / ContactGroups.** `ContactGroups::getRouteKeyName()` returns `'uid'` with no scoping. Every single-record customer action (`show()`, `update()`, `destroy()`, `activeToggle()`, `copy()`, every individual-subscriber mutation, and the fully unscoped `batchAction()`/`batchActionContact()` family, which executes bare `ContactGroups::whereIn('uid', $ids)->delete()/update()`) authorizes purely via Gate/permission-string checks, never comparing `customer_id` to `Auth::user()->id`. List/search endpoints **are** correctly scoped.

**Confirmed defect — Customer Blacklists.** `Blacklists` also binds by `uid` with no scope. Customer `destroy()`/`batchAction()` share an unscoped repository pattern (`whereIn('uid', $ids)->delete()`, no `user_id` filter) — any customer holding `delete_blacklist` can delete another customer's blacklist entries. Customer `search()`/`store()` **are** correctly scoped to the authenticated customer.

**Admin Blacklists — intentionally, architecturally global, confirmed not itself a defect.** `Admin\BlacklistsController::search()`/`export()` deliberately carry no `user_id` filter and explicitly resolve/display the owning customer per row — this is documented platform-operator tooling, the correct and intended behavior for an administrator, structurally identical in kind to the (also intentionally cross-tenant, also correct) admin Opportunity surface. **The defect is narrower and more specific than "Blacklists lacks tenant scoping" — it is that the admin controller's `destroy()`/`batchAction()` actions share the exact same unscoped repository methods the customer controller's equivalent actions use**, meaning a *customer* request reaching those shared repository methods gets the same platform-wide reach an administrator legitimately has. **Correction D — sharpened remediation-prerequisite wording, binding on the future remediation contract:**
- The future remediation **must** scope every customer single-record and batch read/mutation for Contacts/ContactGroups (including the already-audited route-model-binding and raw batch-ID surfaces) to the authenticated customer's own ownership boundary.
- The future remediation **must** scope every customer single-record and batch read/mutation for Blacklists to the authenticated customer's own blacklist ownership.
- The future remediation **must not** convert the admin Blacklists surface's intentionally global list/search/export visibility into `user_id = Auth::id()` or any other customer-style tenant restriction — that would break legitimate, intended platform-operator behavior, not fix a defect.
- The future remediation **must** mechanically determine and preserve the correct admin global-management model while independently preventing a *customer*-originated request from reaching the shared unscoped repository code path that currently grants it the same reach. This contract does not prescribe the exact implementation mechanism (e.g., splitting the repository methods, adding an explicit actor-type check, a policy class, or another approach) — that determination belongs to the remediation contract's own author, informed by this finding.

**Contrast case — Opportunities is correctly scoped, unchanged conclusion.** Customer Opportunity actions resolve through a genuine two-hop tenant-ownership chain (`findOwned`/`findPrimaryByCustomer`); admin Opportunity routes are correctly, deliberately cross-tenant with independent `EnsureUserIsAdministrator` defense-in-depth. No remediation needed for Opportunities — cited only as the working precedent the Contacts/ContactGroups/Blacklists remediation should follow for its own customer/admin separation.

**Two stored-XSS-shaped findings — sharpened this round (Correction E), may not remain merely "evaluated."** The original drafting pass's language ("should be evaluated") is too weak, given one finding lives directly inside `contactGroups/show.blade.php`, a file Slice 5 itself modifies. **Binding requirement:** the separate CRM security-remediation contract **must explicitly audit and resolve the disposition of both findings** — (1) customer-controlled contact-group names and raw collection data (`$contact_groups`, `$remain_opt_in_keywords`, `$remain_opt_out_keywords`) interpolated via `{!! !!}` into inline JS and then into SweetAlert2's `html` option in `contactGroups/show.blade.php`; (2) `Admin\BlacklistsController::search()`'s raw HTML string embedding customer-controllable `displayName()`, rendered unescaped by DataTables. The remediation contract may conclude, on mechanical evidence, that a finding is non-exploitable, or may remediate it directly — but **neither finding may remain unexamined or unresolved by the time Slice 5 implementation is authorized.** Historical insecure behavior in both cases is audit evidence only, never behavior Slice 5 is required or permitted to preserve.

**Zero existing automated test coverage** exists for Contacts, ContactGroups, or Blacklists anywhere in the suite — unchanged finding.

### 3.9 Opportunity-surface preservation — unchanged this round

`tests/Feature/Opportunity/` — 37 files (35 Feature tests + 2 helpers), plus 10 files under `tests/Unit/Opportunity/`. This surface already has deep, tenant-isolation-proving coverage; Slice 5 re-runs it unmodified (§8) and does not re-derive new authorization tests for it. No product-functionality expansion anywhere in this slice.

### 3.10 Form/mutation preservation — unchanged this round, with §3.6's decomposition decision folded in

Every form's exact `action`/method/CSRF/field-name/hidden-field set must be preserved byte-for-byte. `contactGroups/show.blade.php`'s 2 native forms and 11 SweetAlert2-driven AJAX flows (§3.6) remain in the file's own inline script, unrelocated — restyling touches only the markup/classes/icon rendering the script targets, never the script's own route targets, payload shapes, or Blade-evaluated values. `customer/opportunities/show.blade.php`'s 7 conditional forms plus its `fetch()`-based execution-status poller, `admin/opportunities/show.blade.php`'s 3 forms, the `Contacts/import_file.blade.php` → `import/mapping.blade.php` Dropzone-then-AJAX chain, and `_contacts.blade.php`'s `#exportContactModal` (a direct `<x-dialog>` target) are named explicitly as the highest-restyle-risk surfaces, unchanged from the original drafting pass.

---

## 4. Locked Slice 5 scope

- The 30 files inventoried in §3.1, **minus** `customer/contactGroups/_segments.blade.php` (0 bytes, confirmed dead stub, excluded) — **29 existing Blade views** are the presentation-change surface.
- **No new production file of any kind** — corrected this round: the original drafting pass's one new JS asset (`resources/js/scripts/pages/contact-group-show.js`) is withdrawn (§3.6's Correction A) and is not replaced by any other new production path merely to preserve a prior count.
- Three new, mechanically-derived test files (§8) — corrected down from four (§3.6's Correction A withdraws the script-extraction test).
- No controller, route, middleware, FormRequest, model, or migration file. No `app/`, `database/`, or `routes/` path of any kind.
- No other path. Every other rollout-map slice, and every non-Design-System initiative, remains entirely out of scope (§0).

---

## 5. Component adoption

**Adopted, with reasoning (unchanged from the original drafting pass except where noted):**

- **`<x-card>`** — every page's single outer `.card` wrapper adopts cleanly across all 29 files.
- **`<x-table>`** — every DataTables-shell table and every native-paginator table adopts the component for its outer `<table>`/`<thead>` shell, preserving `class="datatables-basic"` via attribute-merge; `<tbody>` content is unchanged (DataTables-injected or `@foreach`-rendered exactly as today, including `contactGroups/show.blade.php`'s tables, whose surrounding script remains in place per §3.6).
- **`<x-button>`** — every `primary`/`secondary`/`outline`/`danger`-shaped button adopts cleanly; this slice uses no `btn-success`/`btn-warning`/`btn-info` semantic-status buttons anywhere.
- **`<x-badge>`** — `_import_history.blade.php`'s nested-ternary status pill and the Opportunity status/freshness badges adopt cleanly.
- **`<x-alert>`** — the Opportunity empty-state and flash/validation-error alerts adopt cleanly (flat icon+slot layout matches; none of these use a two-part `alert-heading` structure).
- **`<x-select>`/`<x-input>`** — every native, non-Select2/non-Flatpickr `<select>`/single-line `<input>` adopts cleanly.
- **`<x-empty-state>`** — the Opportunity empty-message blocks adopt directly. DataTables' own `sZeroRecords` empty states are **not** touched — runtime JS-rendered strings, not static Blade markup.
- **`<x-dialog>`** — `_contacts.blade.php`'s `#exportContactModal`, the one genuine Bootstrap-modal fit.
- **`<x-tooltip>`** — `_contacts.blade.php`'s single tooltip trigger.
- **`<x-ds-icon>`** — corrected scope this round (§3.4's Correction B): **only** the 79 static `data-feather="..."` Blade attributes across the 29 modified views. The 11 runtime `feather.icons[...]` JS calls and the one controller-generated dynamic `data-feather` pair are explicitly **not** touched by this adoption (§3.4).

**Not adopted, with reasoning stated explicitly:**

- **`<x-pagination>`** — **corrected this round (Correction C), moved from "adopted" to explicit non-adoption.** `resources/views/components/pagination.blade.php` renders only Previous/current-of-last/Next — it does not reproduce Laravel's default full numbered-page-link pagination view currently rendered by `customer/opportunities/index.blade.php`, `admin/opportunities/index.blade.php`, and `admin/opportunities/runs/index.blade.php`'s existing `->links()` calls. Adopting it would be a genuine navigation-capability change, not a presentation-only restyle. **Decision: the existing `$collection->appends(...)->links()` calls and their query-string/appends behavior are preserved exactly, unmodified, on all three pages.** The shared `pagination.blade.php` component itself is not modified by this slice (extending or replacing it is outside a single slice's own authority). These three views remain in Slice 5's scope for their other genuine adoptions (card, table, button, badge, alert, empty-state, icon migration).
- **`<x-tabs>`** — every tab-shaped UI in this slice uses Bootstrap nav-**pills**, not nav-**tabs**; `<x-tabs>`'s own markup emits `nav nav-tabs`, a structurally different pattern. Left as native nav-pills markup, already token-bound via `_runtime-bindings.scss`.
- **Native `<textarea>`** — no textarea component exists among the 19; left as native `form-control` markup.
- **Native `<input type="file">` / Dropzone dropzone-area** — no file-upload component exists among the 19; third-party plugin chrome, deferred per the M2 Milestone contract's own §6.11 boundary.
- **Select2/Flatpickr-rendered controls** — third-party plugin chrome, same M2 Milestone §6.11 deferral.
- **`<x-menu>`** — the bulk-actions dropdowns' exact per-item `data-feather`/`feather.icons[...]` icon + permission-gated visibility shape does not map cleanly onto `<x-menu>`'s own API without restructuring the bulk-action wiring itself.

No component is forced anywhere its real, read API does not match the existing markup's shape or behavior.

---

## 6. Preserve all behavior

This is a presentation rollout, not a CRM-feature or business-logic rewrite. No controller, route, request, middleware, authorization rule, cache key, or query's actual filtering/scoping logic may change merely for styling convenience. **Route, controller, and security behavior are entirely read-only to this presentation implementation**, with the sole, explicit exception that Slice 5 implementation cannot even *begin* until the separate remediation named in §7 exists and is merged — at which point Slice 5 preserves *that* remediation's resulting behavior. Slice 5 implementation must preserve exactly:

- Every route, its exact HTTP method(s), and its exact middleware stack, as they exist on Slice 5's own authorized (post-remediation) implementation baseline.
- Every controller/repository method's exact data-building and mutation logic, as established by the separate remediation, not by this document.
- Every AJAX endpoint, every plain form action/method, every SweetAlert2 confirmation flow's exact payload shape, and every DataTables `ajax.url`/column/order/checkbox/responsive configuration named in §3.5 — unchanged targets, unchanged methods, unchanged CSRF handling.
- **`contactGroups/show.blade.php`'s inline script, in place** (§3.6) — every Blade-evaluated expression, route target, and payload shape inside it, unrelocated.
- **The Feather browser runtime, fully intact** (§3.4) — `feather.replace()` and the `feather.icons` object remain loaded and functional for the 11 JS call sites and the 1 controller-generated pair named in §3.4's Category 2/3; Slice 5 does not remove, gate, or conditionally load Feather differently as a side effect of migrating Category 1's static occurrences.
- The existing `$collection->appends(...)->links()` pagination calls and their query-string behavior on all three native-paginator Opportunity pages (§5's Correction C).
- Every existing element `id`, `name`, and `data-*` attribute any JS in these files depends on.
- Every `@can`/`@canany` visibility gate exactly as currently written.
- Every localization key already present. Confirmed hardcoded-English gaps (`_fields.blade.php`'s table headers/field-type labels) are **not** converted to translated strings by this contract.
- The exact, current subscribe/unsubscribe form field set, consent wording, reCAPTCHA wiring, and success-state behavior — no change of any kind.
- The dead/broken `_segments.blade.php` tab pane and its commented-out nav-link — not touched, not fixed, not removed.

---

## 7. The authorization gap — mandatory pre-Slice-5-implementation prerequisite, sharpened this round

§3.8 contains the full audit evidence: every single-record and batch mutation/read path across `ContactsController`/`EloquentContactsRepository` (for `ContactGroups` and `Contacts`) and the shared customer `destroy()`/`batchAction()` path in `BlacklistsController`/`EloquentBlacklistsRepository` is authorized purely by Gate/permission-string role checks, with zero record-ownership verification. This is a genuine, severe, already-live, pre-existing security defect, predating this Design System initiative entirely.

**The binding decision:** this security defect remains outside this contract's own implementation allowlist (§9). **This is a mandatory prerequisite, not an open sequencing question**, mirroring the Design System M2 Slice 3 dashboard-security precedent: a separate, dedicated CRM tenant-isolation security-remediation contract must be drafted, human-reviewed, and human-merged, and its own implementation must likewise be human-merged, **before** Slice 5 implementation may be authorized. No path in §9's allowlist adds any ownership check, global scope, Policy class, or other authorization mechanism.

**Correction D — the remediation must preserve the admin Blacklists surface's intentional global model.** The remediation contract must scope every customer single-record/batch Contacts/ContactGroups/Blacklists action to the authenticated customer's own ownership boundary (§3.8), and must **not** convert admin Blacklists' intentionally global list/search/export visibility into customer-style tenant restriction — it must instead mechanically determine and preserve the correct admin global-management model while independently closing the customer-originated exploit path through the currently-shared unscoped repository code. The exact implementation mechanism is left to the remediation contract's own author, not prescribed here.

**Correction E — the two stored-XSS-shaped findings must be resolved, not merely noted.** The separate remediation contract must explicitly audit and resolve the disposition of both findings named in §3.8 (the `contactGroups/show.blade.php` SweetAlert2 `{!! !!}`→`html` pattern, and the admin Blacklists raw-HTML `displayName()` pattern) — mechanically concluding non-exploitability or remediating directly, but never leaving either unexamined by the time Slice 5 implementation is authorized.

**Correction F — Slice 5 must preserve and re-run the remediation's own security test baseline, in a defined order, before its own tests.** Once the remediation's implementation is merged, Slice 5's own future implementation authorization must pin: (1) the exact CRM security-remediation implementation merge SHA; (2) the exact then-current `origin/main` SHA Slice 5 implementation is based on; (3) the exact focused security test file(s)/command(s) the remediation introduces. Slice 5 implementation must then: leave those security test files completely unchanged; run them **before** Slice 5's own three new focused tests (§8), requiring zero failures; preserve the remediation's customer tenant-isolation behavior and its intentional admin global-access model exactly; and perform a no-diff mechanical check (`git diff <post-remediation-base>...HEAD -- <the exact controller/repository/request/route/security paths the remediation changed>`) proving Slice 5's own presentation edits touch none of them. If the merged remediation changes which 29 views constitute the correct Slice-5 presentation surface, or otherwise requires a presentation-path adjustment beyond §9's own allowlist, Slice 5 implementation must STOP and re-audit rather than silently absorbing that change — the identical discipline §15 already requires for any other unexpected-path discovery.

**This contract does not, and cannot, hard-code a remediation merge SHA that does not yet exist.**

---

## 8. Test contract (Slice 5)

Given §3.8's finding of zero existing coverage for three of the four CRM areas, and §3.9's finding of extensive existing coverage for the fourth (Opportunities), Slice 5 must establish focused new coverage under a new `tests/Feature/DesignSystem/` directory. No existing test file requires modification. **Corrected this round: three new test files, not four** — `ContactGroupShowScriptExtractionTest` is withdrawn along with the JS extraction it existed to prove (§3.6's Correction A).

1. **`tests/Feature/DesignSystem/ContactsCrmDesignSystemContentTest.php`** — raw-source mechanical content proof across all 29 modified files. **Corrected scope (§3.4's Correction B):** SHOULD require zero remaining static `data-feather="..."` source attributes in the 29 modified Blade views where the literal markup is genuinely Slice-5-owned, and genuine `<x-ds-icon>`-equivalent rendered markup present at least once per file that previously contained one; MUST NOT require zero `feather.icons[...]` calls, MUST NOT require removal of or any change to the Feather browser runtime, and MUST NOT require any change to `ContactsController.php`'s controller-generated `data-feather` markup (outside this slice's own scope entirely). Also confirms: zero hardcoded hex/rgb/font-family literals introduced anywhere (§3.3's zero-baseline not regressed); every DataTables `ajax.url`, Select2/Flatpickr initializer selector, and SweetAlert2 route target named in §3.5/§3.10 still present verbatim, including inside `contactGroups/show.blade.php`'s unrelocated inline script (§3.6); the existing `->links()` pagination calls on all three native-paginator Opportunity pages still present, unreplaced by any `<x-pagination>` markup (§5's Correction C).
2. **`tests/Feature/DesignSystem/ContactsCrmComponentAdoptionTest.php`** — rendered-output assertions confirming each locked adoption in §5's "Adopted" list emits its component's own stable marker class on the correct surface. Targeted presence assertions only, not full-page snapshots.
3. **`tests/Feature/DesignSystem/ContactsCrmExistingBehaviorPreservedTest.php`** — since zero prior coverage exists for three of the four CRM areas, this test establishes the actual regression net: for each of the 15 surfaces named in §10's behavior-preservation matrix, a request from an actor genuinely able to reach it on Slice 5's own authorized (post-remediation) implementation baseline returns the expected response, every named form/AJAX endpoint still accepts its exact existing payload shape, and every element `id`/`data-*` attribute named in §6 is still present. **Per §7's Correction F, this test file is run after, and is additional to, the separate security remediation's own preserved test files — it does not assert, and must not assert, anything about tenant-isolation correctness or authorization being present/absent.**

**Ordering requirement (§7's Correction F):** at implementation time, the security-remediation's own pinned test file(s) run first, with zero failures required, before any of the three files above run.

**Regression baseline**: the full existing suite must be re-run at Slice 5's own final head, with the 3 new files above added and passing, and zero regression in any pre-existing test — most directly all 37 files under `tests/Feature/Opportunity/` and the 10 files under `tests/Unit/Opportunity/` — reported with the exact complete-suite count, never estimated. **This contract itself does not run that suite** — a docs-only contract-drafting/correction pass.

---

## 9. Exact implementation allowlist (Slice 5)

**Closed, numbered, path-level, no wildcards, no duplicate path, exactly 32 unique sequential entries — corrected down from the original drafting pass's 34 (§3.6's Correction A withdraws 1 new JS file and 1 test file, with no replacement path added merely to preserve the old count). Any additional path required during Slice 5 implementation is a required-33rd-path-shaped stop condition (§15). This allowlist becomes actionable only after §7's prerequisite is satisfied — it is published now so the audit that produced it is not lost, not because implementation may begin.**

### Contacts views (8 modified)

1. `resources/views/customer/Contacts/create.blade.php`
2. `resources/views/customer/Contacts/import.blade.php`
3. `resources/views/customer/Contacts/import/mapping.blade.php`
4. `resources/views/customer/Contacts/import_file.blade.php`
5. `resources/views/customer/Contacts/paste_text.blade.php`
6. `resources/views/customer/Contacts/show.blade.php`
7. `resources/views/customer/Contacts/subscribe_form.blade.php` — modified: token/component polish only, remains on `fullLayoutMaster` (§3.7).
8. `resources/views/customer/Contacts/unsubscribe_form.blade.php` — modified: same constraint as item 7.

### contactGroups views (11 modified — `_segments.blade.php` excluded, §3.6)

9. `resources/views/customer/contactGroups/_contacts.blade.php` — includes `<x-dialog>` and `<x-tooltip>` adoption.
10. `resources/views/customer/contactGroups/_fields.blade.php`
11. `resources/views/customer/contactGroups/_form_fields.blade.php`
12. `resources/views/customer/contactGroups/_import_history.blade.php` — includes `<x-badge>` adoption.
13. `resources/views/customer/contactGroups/_message.blade.php`
14. `resources/views/customer/contactGroups/_opt_in_keywords.blade.php`
15. `resources/views/customer/contactGroups/_opt_out_keywords.blade.php`
16. `resources/views/customer/contactGroups/_settings.blade.php`
17. `resources/views/customer/contactGroups/create.blade.php`
18. `resources/views/customer/contactGroups/index.blade.php` — markup/component restyle only; the 5 `feather.icons[...]` JS calls (§3.4) remain unchanged.
19. `resources/views/customer/contactGroups/show.blade.php` — **corrected this round (§3.6's Correction A): the inline `page-script` block is NOT extracted and NOT relocated.** Markup/component restyle around the script's existing selectors only; the script itself, its 4 `feather.icons[...]` JS calls, and all 11 SweetAlert2 flows remain in place, unchanged in substance.

### Blacklists views (4 modified)

20. `resources/views/customer/Blacklists/create.blade.php`
21. `resources/views/customer/Blacklists/index.blade.php` — includes 1 `feather.icons[...]` JS call, unchanged (§3.4).
22. `resources/views/admin/Blacklists/create.blade.php`
23. `resources/views/admin/Blacklists/index.blade.php` — includes 1 `feather.icons[...]` JS call, unchanged (§3.4).

### Opportunities views (6 modified)

24. `resources/views/customer/opportunities/index.blade.php` — **corrected this round (§5's Correction C): `<x-pagination>` is NOT adopted.** Existing `->links()` call preserved unchanged.
25. `resources/views/customer/opportunities/show.blade.php`
26. `resources/views/admin/opportunities/index.blade.php` — same Correction C constraint as item 24.
27. `resources/views/admin/opportunities/show.blade.php`
28. `resources/views/admin/opportunities/runs/index.blade.php` — same Correction C constraint as item 24.
29. `resources/views/admin/opportunities/runs/show.blade.php`

### New focused tests (3 new — corrected down from 4, §3.6's Correction A)

30. `tests/Feature/DesignSystem/ContactsCrmDesignSystemContentTest.php`
31. `tests/Feature/DesignSystem/ContactsCrmComponentAdoptionTest.php`
32. `tests/Feature/DesignSystem/ContactsCrmExistingBehaviorPreservedTest.php`

**Counts** — Production views: **29** (all modified, zero new Blade files, zero new JS files, zero `app/`/`database/`/`routes/` paths). Test: **3** (all new). **Overall total: 32. Stop threshold: 33** (32 + 1).

`resources/views/customer/contactGroups/_segments.blade.php` is deliberately **not** listed (§3.6, §4). `resources/js/scripts/pages/contact-group-show.js` and `tests/Feature/DesignSystem/ContactGroupShowScriptExtractionTest.php`, both present in the original drafting pass's allowlist, are **withdrawn this round and are not replaced** — named here explicitly so their removal is a documented decision, not a silent gap.

---

## 10. Behavior-preservation matrix

Only rows that actually exist per §3's audit.

| Surface | Actor | Read behavior | Mutation behavior | Critical DOM/JS contract |
|---|---|---|---|---|
| Contacts create (customer) | Authenticated customer, `create_contact` | Dynamic per-field form | `ContactsController::storeContact()` | Flatpickr `.datetime`/`.date`, dynamic `name="{{ $field->tag }}"` |
| Contacts import — file/paste/mapping (customer) | Authenticated customer, `view_contact`/`view_contact_group` | Dropzone upload → AJAX mapping fetch → run | `storeImportFile()`, `importMapping()`, `importRun()`/`importValidate()`, `storeImportContact()` | Dropzone `paramName:"import_file"`, mapping `select2` per CSV column |
| Contacts edit/show single subscriber (customer) | Authenticated customer, `update_contact`, tenant-scoped subscriber lookup | Dynamic per-field form (pre-filled) | `updateContact()` | Same field-loop template as create |
| Contacts subscribe form (public) | **Anonymous** | N/A | `insertContactBySubscriptionForm()` | `fullLayoutMaster`, reCAPTCHA v3, no dashboard chrome |
| Contacts unsubscribe form (public) | **Anonymous** | N/A | `postUnsubscribeURL()` | `fullLayoutMaster`, reCAPTCHA v3, no dashboard chrome |
| ContactGroups index (customer) | Authenticated customer, `view_contact_group` | Server-side DataTables, `customer.contacts.search` | copy/delete/bulk enable-disable-delete | 5 `feather.icons[...]` JS calls unchanged (§3.4), SweetAlert2 ×5 |
| ContactGroups create (customer) | Authenticated customer, `create_contact_group`, quota-checked | Plain form | `ContactsController::store()` | none beyond standard form |
| ContactGroups show — contacts tab (customer) | Authenticated customer, `view_contact_group` (page) / `view_contact` (tab) | Server-side DataTables, `customer.contact.search` | subscribe/unsubscribe/copy/move/delete; export | Inline script unrelocated (§3.6); 4 `feather.icons[...]` JS calls unchanged; `#exportContactModal` → `<x-dialog>` |
| ContactGroups show — fields/settings/message/keywords tabs (customer) | Authenticated customer, `update_contact_group` | Tab-scoped native forms/tables | field store/delete, message, keyword add/delete | AJAX-fetched `_form_fields.blade.php` fragment |
| ContactGroups show — import history tab (customer) | Authenticated customer, `create_contact_group` | Plain table | `contacts.download_failed` (conditional link) | `<x-badge>` adoption |
| Blacklists index+create (customer) | Authenticated customer, `view_blacklist`/`create_blacklist`/`delete_blacklist` | Server-side DataTables, tenant-scoped search | `store()` (scoped), `destroy()`/`batchAction()` (**unscoped — §3.8, subject to §7's Correction D**) | 1 `feather.icons[...]` JS call unchanged, SweetAlert2 ×2 |
| Blacklists index+create (admin) | Admin, `view blacklist`/`create blacklist`/`delete blacklist` | Server-side DataTables, **intentionally global** search + export | `store()` (self-scoped), `destroy()`/`batchAction()` (**shared unscoped code — §3.8, must stay global per §7's Correction D**) | Raw-HTML `user_id` column (XSS-flagged, §7's Correction E), 1 `feather.icons[...]` JS call unchanged |
| Opportunities index+show (customer) | Authenticated customer, `findOwned`-scoped | Native paginator, `->links()` unchanged (§5's Correction C) | 7 forms | `fetch()`-based execution-status poller |
| Opportunities index+show (admin) | Admin, `EnsureUserIsAdministrator`, intentionally cross-tenant | Native paginator, `->links()` unchanged | 3 forms | none additional |
| Opportunity runs index+show (admin) | Admin, `EnsureUserIsAdministrator`, cross-tenant | Native paginator, `->links()` unchanged, read-only | **None — zero mutation routes exist** | none additional |

---

## 11. Responsiveness review

Unchanged from the original drafting pass: existing Bootstrap breakpoints only (`sm:576px, md:768px, xl:1200px, xxl:1440px`), no new breakpoint system. Every DataTables-backed table already uses the Responsive extension with a custom modal renderer — implementation must confirm this continues to function through the component-shell adoption (§5), not assume it. Every native-paginator table and every form-heavy page already uses `.table-responsive`/standard `.row`/`.col-md-*` grid classes. No file in this slice introduces a fixed-width or non-responsive element.

---

## 12. Forbidden scope

- Any change to product behavior: contact-group creation rules, subscribe/unsubscribe consent wording or flow, import algorithm/validation rules, blacklist opt-in/opt-out semantics, Opportunity scoring/workflow/status semantics.
- Any change to routes, controllers, FormRequests, models, repositories, or migrations — including the tenant-isolation fix named in §7 (exclusively the separate remediation contract's own scope).
- **Any change that narrows the admin Blacklists surface's intentionally global visibility into customer-style tenant scoping** — explicitly forbidden for both this contract and the future remediation (§7's Correction D).
- Any change to `ChatBox`/Conversations (Slice 6), `Reports & Analytics` (Slice 4), Campaigns, or billing surfaces.
- Any new token architecture, JS framework, or CSS framework — zero new SCSS required (§3.3); no client-side icon-rendering library is added (§3.4's Correction B); no new Blade→JS data-hydration seam is invented (§3.6's Correction A).
- Any AI/COO/SEO/Outreach/Website-Generator work of any kind.
- Any change to `docs/automation/AI-AUTONOMY-STATE.json`.
- Automatic advancement to Slice 4, Slice 6, or any other slice/initiative upon this contract's own merge.

---

## 13. Governance block

```
maximum_correction_rounds: 2
correction_round: 1
advance_automatically: false
start_automatically_after_contract_merge: false
implementation_requires_separate_human_authorization: true
implementation_blocked_until: "CRM tenant-isolation security remediation contract + implementation, both human-merged, admin-Blacklists global model preserved, both XSS findings resolved (§7)"
merge_authority: human_only
```

Merging this contract does not authorize Slice 5 implementation. Implementation additionally requires a separate, later, explicit human instruction pinning the exact security-remediation implementation merge SHA, the exact then-current `origin/main` SHA, and the remediation's own focused security test file(s) (§7's Correction F).

---

## 14. Mechanical searches (Slice 5, run at implementation time)

1. `grep -rniE "anthropic|claude"` across every path in §9 → zero matches (the contract document itself legitimately references `CLAUDE.md` and this same search pattern — not a violation).
2. `grep -c "data-feather"` across §9 items 1-29 (static Blade attributes only) → zero.
3. `grep -c "feather\.icons\["` across §9 items 18, 19, 21, 23 → **unchanged from this contract's own §3.4 count (5, 4, 1, 1 respectively, 11 total)** — proving Category 2 was genuinely preserved, not accidentally migrated or deleted.
4. `grep -rnoE "#[0-9A-Fa-f]{3,8}"` across §9 items 1-29 → zero genuine color literals.
5. `git diff --stat -- app database routes` compared against §9 (which contains no such path at all) → **must be completely empty**.
6. `grep -c "resources/js/scripts/pages/contact-group-show.js"` anywhere in the changed-path set → zero (§3.6's Correction A: this path must never appear as a created file).
7. `grep -n "@include" resources/views/customer/contactGroups/show.blade.php` → exactly the 8 live partials named in §3.6, `_segments` still a pane wrapper with its own nav-link still Blade-commented-out.
8. `grep -c "links()" resources/views/customer/opportunities/index.blade.php resources/views/admin/opportunities/index.blade.php resources/views/admin/opportunities/runs/index.blade.php` → present, unchanged, on all three (§5's Correction C: `<x-pagination>` must not have replaced them).
9. Every one of the 30 distinct static icon names in §3.4's Category 1 individually confirmed present as `vendor/technikermathe/blade-lucide-icons/resources/svg/{name}.svg` (or its confirmed Lucide-alias equivalent) before use — never guessed, with `check-square` given explicit extra scrutiny.
10. `git diff --stat -- app/Http/Controllers/Customer/ContactsController.php app/Http/Controllers/Customer/BlacklistsController.php app/Http/Controllers/Admin/BlacklistsController.php app/Http/Controllers/Customer/OpportunityController.php app/Http/Controllers/Admin/OpportunityController.php app/Http/Controllers/Admin/OpportunityRunController.php` compared against Slice 5's own authorized (post-remediation) implementation baseline → **must be completely empty** (§7's Correction F).
11. The security-remediation's own pinned test file(s) (§7's Correction F) run and pass with zero failures **before** §9 items 30-32 run.
12. Final changed-path set (`git diff --name-only` + `git ls-files --others --exclude-standard`) equals §9's exact, sequential 1-32 allowlist — mechanically diffed, not eyeballed.
13. `php artisan test` full-suite pass count compared against the pre-Slice-5 baseline, reported exactly, never estimated.

---

## 15. Stop conditions

Slice 5 implementation must stop, leave the working tree unstaged, and report rather than proceed, if:

- **Slice 5 implementation must not begin at all unless the separate CRM tenant-isolation security remediation named in §7 is already human-merged, its exact implementation merge SHA is pinned in Slice 5's own later, separate implementation authorization, the admin Blacklists global-visibility model is confirmed preserved (§7's Correction D), and both stored-XSS-shaped findings are confirmed resolved (§7's Correction E).**
- If the post-remediation current tree changes which 29 views are the correct rendered-surface set, or causes a genuinely necessary path beyond §9's own 32-item allowlist, STOP and re-audit.
- Any path beyond §9's 32-item allowlist is required — the **33rd** path.
- Any change to `app/`, `database/`, or `routes/` appears necessary for any reason, **including** any change that would add, strengthen, narrow, or otherwise alter tenant-isolation/authorization on `ContactGroups`, `Contacts`, or `Blacklists` — that is exclusively the separate security remediation's own scope.
- Any of the 30 flagged static icon names does not have a confirmed, working Lucide equivalent.
- Any existing test — most directly any of the 37 files under `tests/Feature/Opportunity/` — fails for a reason not fixable within this slice's own allowlist.
- Any route, controller data-building/mutation logic, `@can`/`@canany` gate, AJAX/form target, or CSRF handling changes as a side effect of restyling.
- `contactGroups/show.blade.php`'s inline script is relocated, extracted, or its Blade-evaluated expressions altered in any way beyond the markup it targets (§3.6).
- Any `feather.icons[...]` JS call or the Feather browser runtime itself is removed, migrated, or conditionally altered (§3.4).
- `<x-pagination>` is adopted anywhere the existing `->links()` behavior would be replaced (§5's Correction C).
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is found necessary to reference.
- A genuine business-logic change is found necessary to make any of the 29 views render or behave correctly under the new design system.

---

## 16. Contract self-audit

1. Full current inventory is present and mechanically re-derived (§3.1), unchanged and re-confirmed this round. ✓
2. Sequential numbering, no duplicates, stop threshold = 33 (32 + 1), stated consistently in §0, §9, §15 — corrected down from the original pass's 35 (34 + 1). ✓
3. Every path in §9 exists now (§3.1) or is explicitly marked NEW (items 30-32); zero new production files, corrected from the original pass's one invalid JS file (§3.6's Correction A). ✓
4. No invented test, permission, route, component API, or token. `<x-pagination>`'s real, non-equivalent API is now correctly reflected as a non-adoption (§5's Correction C), not a forced fit. ✓
5. Public vs. authenticated forms correctly distinguished (§3.7), unchanged. ✓
6. Current auth/tenant behavior is documented exhaustively (§3.8) and the security defect is treated as a hard blocker (§7) — this round sharpens the blocker's own wording so a future remediation cannot accidentally regress the admin Blacklists surface's intentional global model (Correction D) and so both XSS findings must be explicitly resolved, not merely noted (Correction E). ✓
7. Icon audit now correctly distinguishes static Blade markup (the genuine migration target) from runtime JS calls and controller-generated markup (both explicitly, permanently preserved) — corrected from the original pass's conflation and undercount (§3.4's Correction B). ✓
8. `contactGroups/show.blade.php`'s decomposition decision is corrected and now mechanically accurate: no JS extraction is claimed or authorized, with the exact reason (Blade-coupled request-time data, no existing hydration seam) stated (§3.6's Correction A). ✓
9. Component adoption/non-adoption decisions are explicit and reasoned for every recurring pattern (§5), including the corrected pagination non-adoption. ✓
10. `docs/automation/AI-AUTONOMY-STATE.json` is untouched. ✓
11. Slice 4 and Slice 6 are untouched and explicitly named as out of scope. ✓
12. No implementation authorization is granted anywhere in this document. ✓
13. This document remains the only file changed on this branch (§2). ✓
14. This is Correction Round 1 of a maximum of 2 (§0, §13) — one correction round remains available if needed. ✓

---

## 17. Verification and publication

Performed, in order, before commit:

1. Markdown structural check — every numbered §9 item follows the pattern `N. `path``, no broken heading levels, no unclosed code fences.
2. §9's numbered items counted mechanically and confirmed equal to exactly 32, sequential, no gap, no repeated number.
3. Every path listed in §9 checked for uniqueness — no path string appears twice.
4. Mechanical search confirming zero stale claims remain from the original drafting pass: `contact-group-show.js`, `ContactGroupShowScriptExtractionTest`, any "byte-for-byte" JS-extraction requirement, any claim that JS `feather.icons` calls must become client-side Lucide, any claim that JS Feather occurs only in two DataTables render callbacks, any requirement for zero `feather.icons` calls, `<x-pagination>` framed as a required adoption, any reference to a 34-path allowlist or 35th-path stop threshold, any wording that could tenant-scope intentional admin Blacklists access, and any wording allowing the two XSS findings to remain unresolved — all confirmed absent from this corrected document.
5. `git diff --check` — clean, no whitespace-error or conflict-marker findings.
6. `git diff --name-only origin/main...HEAD` — exactly one path: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md`.
7. `git status --short` — clean working tree after commit.
8. Stage individually (`git add docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md`), never `git add -A`/`.`.
9. Commit message: `docs: correct Slice 5 implementation boundaries`.
10. Push to `origin chore/design-system-m2-slice5-contacts-crm-contract` — a normal push, never force-pushed.
11. Do not open/merge a PR if `gh` is unavailable; return the exact GitHub comparison URL.
12. **Do not merge. Do not begin Slice 5 implementation. Do not begin the CRM security remediation. Do not begin Slice 4, Slice 6, or any other slice. Do not begin any other RFC or initiative.** All require separate, explicit, future human authorization. No test is run for this docs-only change — reported honestly as not run, no count fabricated.

---

*End of Design System M2 Slice 5 Contract, Correction Round 1. Implementation requires a separate, explicit human instruction. This contract's own merge does not start or resume it. Slice 5 implementation is blocked until the separate CRM tenant-isolation security remediation named in §7 is complete — contracted, human-merged, implemented, and human-merged, with the admin Blacklists global model preserved and both stored-XSS-shaped findings resolved — and its exact merge SHA is pinned in Slice 5's own later, separate implementation authorization.*
