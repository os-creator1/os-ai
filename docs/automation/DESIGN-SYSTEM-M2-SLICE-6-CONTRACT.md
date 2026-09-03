# Design System — Milestone 2, Slice 6 Contract: ChatBox / Conversations

**This document is fully self-contained.** No section below requires consulting an earlier commit, the Milestone 1 contract, the Milestone 2 contract, or any other slice contract to understand Slice 6's complete rules — every requirement, architecture decision, and path is restated here in full.

**Status: contract only. No implementation has occurred under this document. Merging this contract does NOT authorize implementation. Per §7 below, Slice 6 implementation cannot be authorized AT ALL until a separate, dedicated ChatBox tenant-isolation and permission-bypass security remediation is drafted, human-reviewed, human-merged, and its own implementation human-merged — following the identical precedent already established for Slice 5's Contacts/CRM security remediation.**

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice6-chatbox-conversations-contract`, in an isolated linked worktree, based on `origin/main` at `ffad907f4e1cddee6900f1195be88a5032fb6147` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` before drafting began. This SHA is the Design System M2 Slice 5 (Contacts & CRM) implementation merge (PR #179).
- This is a **first drafting pass**, not a correction round. `maximum_correction_rounds: 2` applies prospectively to this contract, unconsumed.
- Slice 6 is the rollout-map group named **"ChatBox / Conversations (full componentization)"**, historically listed as **4 files**. §3.1 mechanically re-derives the current tree from direct repository inspection, not copied from the rollout map. The re-derived count matches (4 files), independently re-confirmed.
- `advance_automatically: false`. `start_automatically_after_contract_merge: false`.
- This contract authorizes **only** drafting this one document. It does not authorize implementation of Slice 6, the security remediation named in §7, Slice 4 (Reports & Analytics, deliberately skipped per the Slice 5 contract's own governance and left skipped here), Campaigns (Slice 7a onward), or any other slice/initiative, and makes zero change to `docs/automation/AI-AUTONOMY-STATE.json`.
- **This contract does not fix, and is not authorized to fix, the severe pre-existing cross-tenant authorization gap and permission-bypass gap documented in §7.** A separate, dedicated ChatBox security-remediation contract must be drafted, human-reviewed, and merged, and its own implementation must be human-merged, before Slice 6 implementation may be authorized.
- **Slice 1's chat-background token remediation is already merged to `main`** (confirmed mechanically, §3.12) — this contract does not reopen, re-authorize, or duplicate that work. Slice 6's own future scope is the Blade-markup componentization (icons, buttons, card, tooltips) layered on top of Slice 1's already-completed token/background work, not a second pass at the background itself.

---

## 1. Mandatory preflight — verified

1. `git fetch origin --tags` — `origin/main` confirmed at exactly `ffad907f4e1cddee6900f1195be88a5032fb6147`.
2. Starting branch HEAD for this drafting pass confirmed at the same SHA (fresh worktree, clean checkout, `git status --short` empty before any edit).
3. Read in full before drafting: `CLAUDE.md`, `AGENTS.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` (both correction rounds and the post-round exception, all 435 lines).
4. Directly re-read, in full, at this drafting pass: the actual current source of all 4 files under `resources/views/customer/ChatBox/**` (§3.1), `app/Http/Controllers/Customer/ChatBoxController.php` (694 lines, in full — §7), `app/Models/ChatBox.php`, `app/Models/ChatBoxMessage.php`, `app/Library/Traits/HasUid.php`, `routes/customer.php`'s `chat-box` route group, and every component file in `resources/views/components/` genuinely relevant to ChatBox markup (`card`, `button`, `badge`, `input`, `select`, `dialog`, `alert`, `empty-state`, `tooltip`, `ds-icon`) — not assumed from the Slice 5 contract's own findings, since components can drift between slices and did not (§3.13 confirms no drift).
5. The rollout map's historical "4 files" figure for Slice 6 (`docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` §8) and the M2 contract's own §3.3 chat-background audit prose were both used only as a starting hypothesis, never trusted directly — every fact stated below is independently re-derived by direct `grep`/`Read` against the actual current repository state at the base SHA in item 1.

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**: `docs/automation/DESIGN-SYSTEM-M2-SLICE-6-CONTRACT.md` (this document). No `resources/`, `app/`, `database/`, `routes/`, or `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting this contract.

---

## 3. Mandatory repository audit — findings

### 3.1 Current file inventory — mechanically re-derived

`find resources/views/customer/ChatBox -type f` plus a repository-wide search for any other `*ChatBox*`/`*chat*`-named Blade view (`admin/**` included) returns exactly these 4 files, no more, no fewer — the historical rollout-map figure is confirmed, not merely trusted:

| File | Lines |
|---|---|
| `resources/views/customer/ChatBox/index.blade.php` | 967 |
| `resources/views/customer/ChatBox/new.blade.php` | 258 |
| `resources/views/customer/ChatBox/_sidebar.blade.php` | 101 |
| `resources/views/customer/ChatBox/partials/_chat_list.blade.php` | 34 |
| **Total** | **1,360** |

No admin-side ChatBox surface exists anywhere in `resources/views/admin/`. No other `*chat*`-named Blade view exists anywhere in the repository outside this exact tree.

Two supporting JS assets are loaded by `index.blade.php`, outside `resources/views` and therefore outside this slice's own `customer/ChatBox/**` glob scope entirely, named here only for completeness of the behavioral audit (§3.7, §6):
- `resources/js/scripts/pages/chat.js` (283 lines) — the Vuexy/Codeglen theme's own generic chat-page boilerplate. Wires `.sidebar-toggle`/`.body-content-overlay`/`.sidebar-content` show/hide behavior (genuinely exercised by the current markup) and a duplicate `#chat-search` keyup substring-filter handler (co-existing with, and functionally superseded in practice by, `index.blade.php`'s own debounced AJAX search handler for the same element — pre-existing behavior, not a Slice 6 concern to fix). Defines a legacy `enterChat(source)` function and several selectors (`.chat-profile-sidebar`, `.user-status`, `.speech-to-text`, `.contact-list`, `.menu-toggle`) that match nothing in the current 4-file tree — dead relative to current markup, but this file is not itself a Blade view and is not part of Slice 6's own file-path scope.
- `resources/js/scripts/echo.js` (1,574 lines) — the bundled Laravel Echo client library itself (vendored, not app code), instantiated by `index.blade.php`'s own inline script (§3.6).

### 3.2 Design System component library — directly re-read this pass

18 components exist in `resources/views/components/` at the base SHA (`alert`, `badge`, `branding-favicon`, `branding-footer`, `branding-illustration`, `branding-logo`, `button`, `card`, `dialog`, `ds-icon`, `empty-state`, `input`, `menu`, `pagination`, `select`, `switch-toggle`, `table`, `tabs`). Exact prop APIs re-confirmed by direct read for every component cited in a §5 decision:

- **`<x-card :title :padded>`** — optional `.card-header` (an `<h4 class="card-title text-section-heading">` plus, if a named `actions` slot is supplied, a `<div class="d-flex align-items-center gap-2">` alongside it), body always wrapped in a `div` (`.card-body` unless `padded=false`), optional named `footer` slot. No support for a page with zero cards (opt-in per usage) or for multiple independent, non-nested cards on one page.
- **`<x-button :variant :size :type :href :icon :disabled>`** — `variant` ∈ `primary|secondary|outline|ghost|danger`, mapping to `btn-primary`, `btn-outline-secondary` (not solid `btn-secondary`), `btn-outline-primary`, `btn-flat-secondary`, `btn-danger` (solid) respectively. Always renders a real `<button>` (no `href`) or `<a>` (`href` supplied) — never a `<label>`. Always emits `class="btn {variant-class} {size-class} d-inline-flex align-items-center gap-1 transition-fast"` plus any caller-merged classes/attributes (Blade's `$attributes->merge()`), and always renders the `icon` prop (if supplied) immediately before the slot content — the icon prop and slot content render together, never as a breakpoint-conditional either/or swap.
- **`<x-badge :variant>`** — `variant` ∈ `neutral|accent|success|warning|danger`, mapping to `bg-light-{secondary|primary|success|warning|danger} text-{secondary|primary|success|warning|danger}` respectively. **Every variant is a soft/light background — there is no variant producing a solid `bg-{color}` fill.**
- **`<x-alert :variant :icon :dismissible>`** — `variant` ∈ `neutral|accent|success|warning|danger`, mapping to `alert-secondary|alert-primary|alert-success|alert-warning|alert-danger`. **No variant maps to Bootstrap's own `alert-info`** (`accent` maps to `alert-primary`, a distinct color). Renders a single flat `<div class="alert {variant-class} ds-alert d-flex align-items-start gap-2" role="alert">` with the slot content as a direct child (in a `<div class="flex-grow-1">`) — no nested `.alert-body` wrapper.
- **`<x-empty-state :icon :title :description>`** — a single, non-conditional `title` (plain text, not a link), optional `description`, optional named `action` slot rendered as one plain `<div>` below the description. No mechanism for a breakpoint-conditional swap between two different heading treatments (e.g., plain text on one breakpoint, a clickable link on another) in the title's own position.
- **`<x-tooltip :text :placement>`** — wraps slot content in `<span class="ds-tooltip-trigger" data-bs-toggle="tooltip" data-bs-placement="..." title="...">`, with any caller-supplied class/attributes merged in via `$attributes->merge()` (so an existing click-target class like `remove-btn` survives adoption unchanged).
- **`<x-input :label :name :type :help :error>`** / **`<x-select :label :name :options :selected :help>`** — both always wrap output in a fresh `<div class="ds-field mb-3">`, always set `id="{{ $name }}"`, always render their own `<label>` when a `label` prop is passed. Neither supports a control that must remain a direct child of a `.input-group`/plugin wrapper the surrounding markup owns, nor a control with no matching type at all (there is no `<x-textarea>` or `<x-file-input>` component anywhere in the library).
- **`<x-dialog>`**, **`<x-table>`**, **`<x-pagination>`**, **`<x-menu>`** — unchanged in shape from the Slice 5 audit; no modal, table, native-paginator `->links()` call, or real Bootstrap dropdown-menu markup exists anywhere in the current ChatBox tree (§3.9), so none of these four components has any candidate surface in this slice at all.

### 3.3 Icon audit — static vs. runtime, mechanically counted

**Category 1 — static Blade `data-feather="..."` (the genuine migration target): 14 total occurrences, 13 distinct names**, confirmed by direct `grep` across all 4 files (both plain-quoted static markup and the two JS-string-embedded occurrences described below), never estimated:

| File | Occurrences | Names |
|---|---|---|
| `_sidebar.blade.php` | 4 | `x`, `search`, `plus-circle`, `refresh-cw` |
| `index.blade.php` | 8 | `message-square`, `menu`, `shield`, `trash`, `image`, `send`, `delete`, `edit-2` |
| `new.blade.php` | 2 | `info`, `send` |
| `partials/_chat_list.blade.php` | 0 | — |

(`send` appears once in `index.blade.php` and once in `new.blade.php` — 13 distinct names total across the slice, not 14, since `send` is not double-counted.)

Two of `index.blade.php`'s 8 occurrences are **JS-string-embedded static markup**, mechanically identical in kind to the 4 occurrences Slice 5's `contactGroups/show.blade.php` migrated by embedding `<x-ds-icon>` directly inside a JS string (Slice 5 §3.4's "Category 3" precedent): lines 326 and 337, inside the `.add-to-pin` click handler's pin/unpin icon-swap logic —
```js
addToPin.append("<i data-feather=\"delete\" class=\"cursor-pointer font-medium-2 mx-1 text-danger\"></i>");
// ...
addToPin.append("<i data-feather=\"edit-2\" class=\"cursor-pointer font-medium-2 mx-1 text-info\"></i>");
```
— both immediately followed by a `feather.replace()` call. These count toward the 14/13 totals above; they are static icon *names* embedded in a JS string, not a dynamically-computed icon name, and are therefore migration targets exactly like any other static occurrence (§6).

**Category 2 — runtime `feather.icons[...].toSvg(...)` calls: zero occurrences anywhere in the 4 files.** This differs from every slice audited so far (Slice 5 had 11 such calls across 4 files) — ChatBox uses the older, simpler `feather.replace()` pattern exclusively.

**Category 2b — `feather.replace()` calls: 3 occurrences, all in `index.blade.php`** (lines 329, 340, 899) — one pair immediately following the two JS-string icon-swap appends above, one following the AJAX chat-list reload (`loadChatUsers()`'s success callback, where it has nothing new to convert within the reloaded `_chat_list.blade.php` fragment itself, which carries zero static icons, but is still necessary because it is a global, document-wide re-scan that also picks up the two dynamically-appended `.add-to-pin` icons above). The Feather browser runtime remains loaded and exercised; **Slice 6 must not remove `feather.replace()` or the Feather runtime**, mirroring the established Slice 5 precedent.

**Category 3 — controller-generated dynamic icon markup: none found.** `ChatBoxController.php` was read in full (§1 item 4); it emits no HTML/icon markup of any kind (JSON responses only).

Every one of the 13 distinct names must be individually confirmed against `vendor/technikermathe/blade-lucide-icons/resources/svg/{name}.svg` at implementation time, never guessed — identical discipline to Slice 5 (§9).

### 3.4 Hardcoded color / font-family audit

**3 hardcoded color literals found, all in `index.blade.php`'s own inline `<style>` block** (lines 61–81, styling `textarea.message`/`textarea.message:focus` to visually mimic a native Bootstrap input field):

```css
textarea.message { border: 1px solid #ced4da; /* ... */ }
textarea.message:focus { border-color: #80bdff; box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25); }
```

— `#ced4da` (border), `#80bdff` (focus border), `rgba(0, 123, 255, 0.25)` (focus ring). Slice 1's token system (already merged, §3.12) already emits the exact CSS custom properties these three values duplicate: `--color-input-border`, `--color-focus-border`, `--focus-ring-color` (confirmed present in `resources/scss/base/tokens/_colors.scss`, lines 132–140). Per the M2 contract's own §8 standing mandate ("each slice's own implementation must eliminate that slice's own hardcoded colors... as it migrates"), **Slice 6 must eliminate these 3 literals when implemented**, replacing them with the three token references named above — no new SCSS file or path is required, since the rule lives entirely inside this one Blade file's own inline `<style>` block.

One `font-family: inherit;` declaration exists (line 70) — this is a keyword, not a hardcoded typeface name, and is not a token violation; noted for completeness, not flagged as a defect.

Zero other hex/`rgb()`/`rgba()`/`hsl()`/named hardcoded-font-family literal exists anywhere else in the 4 files.

**Plugin-chrome retrofit check (M2 §6.11 standing mandate).** ChatBox exercises three third-party plugin chrome surfaces: Select2 (5 selects), SweetAlert2 (3 confirm flows), Toastr (loaded globally, exercised throughout). Direct `grep` of all three plugins' own SCSS override files (`resources/scss/base/plugins/forms/select2/_select2.scss`, `resources/scss/base/plugins/extensions/ext-component-sweet-alerts.scss`, `resources/scss/base/plugins/extensions/ext-component-toastr.scss`) found **zero hardcoded hex literals in any of the three** — already fully consuming tokenized Sass variables, confirmed mechanically, not assumed. **No plugin-override SCSS path is required in Slice 6's future allowlist** — the M2 §6.11 mandate's own text ("each later slice... must retrofit that plugin's own override file") applies only where hardcoded literals are actually found; none were.

### 3.5 DataTables / tables audit

No `<table>` element, DataTables instance, or `->links()` paginator call exists anywhere in the 4 files — ChatBox uses an infinite-scroll "Load More" button (`#load-more`) plus a debounced AJAX search/filter pattern instead. `<x-table>` and `<x-pagination>` have zero candidate surfaces in this slice (§3.2).

### 3.6 Message-template / runtime-JS construction audit

**Exactly 3 separate inline JS template-literal/string-concatenation constructions of a chat message bubble, mechanically re-confirmed present in current `main` — matching the M2 contract's own §3.3 historical claim exactly, independently re-derived, not assumed:**

1. **History load** — the `$.post(` .../messages`)`.done(function(response) {...})` handler (index.blade.php, lines ~308–398), building each bubble via a template literal per message, branching only on `sms.direction === "incoming"` for the `chat-left` class and directly interpolating `sms.message`/`sms.media_url`/`sms.created_at` unescaped.
2. **Optimistic send** — inside `enter_chat()`'s AJAX success handler (lines ~486–515), building the just-sent outbound bubble via string concatenation, interpolating `messageValue` (the customer's own just-typed text) and `response.media_url` unescaped.
3. **Echo listener** — inside `Echo.private("chat").listen("MessageReceived", ...)` (lines ~794–874), building the newly-pushed bubble via a template literal, branching on `sms.direction === "incoming"` for both the `chat-left` class and which avatar image URL to use (the authenticated customer's own avatar route for outbound, a static placeholder for inbound), interpolating `sms.message`/`sms.media_url`/`sms.created_at` unescaped.

All three remain genuinely separate, non-deduplicated code paths in current `main`. **No JS extraction or template unification is authorized or implied by this contract** — per §6, only the static `data-feather` names embedded within these template literals (the two `.add-to-pin` icon-swap occurrences, §3.3) are in scope for migration; the surrounding template-literal/string-concatenation structure itself, and the unescaped interpolation pattern within it, is a §7 security-remediation concern (documented there), not a Slice 6 presentation concern.

### 3.7 Attachment / composer / SMS-template picker audit

**Composer** (`index.blade.php`, lines 145–174): a `<form class="chat-app-form" action="javascript:void(0);" onsubmit="enter_chat();" autocomplete="off">` wrapping — a `.input-group.input-group-merge.form-send-message` containing the `#message` textarea (`class="form-control message"`) and an `.input-group-text` holding a `<label for="media_image">` wrapping the `#mms-icon` static icon plus a hidden `<input type="file" id="media_image" name="media_image" accept="image/*,video/*" hidden>`; a `#sms_template` Select2 (`data-placeholder="Select Template"`); and the `.send` submit-triggering button (`type="button"`, `onclick="enter_chat();"`, containing a responsive icon-only-on-small/text-on-large pair). Every id (`message`, `mms-icon`, `media_image`, `sms_template`), name (`media_image`), class-based JS selector (`.message`, `.send`, `.form-send-message`), and the `accept="image/*,video/*"` MIME whitelist must be preserved exactly.

**`new.blade.php`'s own separate compose form** (lines 38–156): `action="{{ route('customer.chatbox.sent') }}" method="post"`, `@csrf`, a conditional `sending_server` Select2, `sender_id` Select2, a `country_code` Select2 nested in an `.input-group` beside the `recipient` text input, an `sms_template` Select2 (AJAX-populates the message textarea on change via `templates/show-data`), the `message` textarea, and two hidden fields: `sms_type` (fixed `"plain"`) and — **critical, RFC-005 Milestone 5 §6.1** — `idempotency_token`, server-minted per-request (fresh, or the same one on a `?m5_retry_token=` retry), never client-minted. This hidden field's exact `name="idempotency_token"` and its value binding to the controller-passed `$idempotencyToken` must be preserved byte-for-byte; it is billing-integrity-critical, unrelated to and outside the scope of any presentation change.

**SMS-template picker**: present in both files, functionally identical (`#sms_template` Select2, AJAX `POST templates/show-data/{id}` on change, injects the returned message text at the current caret position). Select2-controlled throughout — native retention in both files (§5).

**Attachment image/video/audio branching**: the `isImageOrVideo(url)` helper (extension-based: `mp4/avi/mkv/webm`→video, `mp3/wav/ogg`→audio, `jpg/jpeg/png/gif`→image, else `"unknown"`) is called identically in the history-load and Echo-listener paths (§3.6) to select `<video controls>`/`<audio controls>`/`<img>` markup. Not present in the optimistic-send path, which only ever knows about a freshly-uploaded image (`response.media_url` always rendered as `<img>`). This is existing, asymmetric-but-correct behavior (an outbound MMS reply is always an image per the `media_image` file input's own `accept="image/*,video/*"` — video is accepted for upload but the optimistic-send bubble does not itself branch on it, relying on the eventual history-load/Echo-listener re-render to show it correctly) — preserved exactly, not altered.

### 3.8 Chat bubble semantics audit

Outbound/inbound distinction: default (no extra class) vs. `.chat-left`, driven by `sms.direction === "incoming"` in all 3 template paths (§3.6). `.chat-time` timestamp class present on every bubble in all 3 paths, sourced from `sms.created_at` (raw server value, not client-formatted). No delivery/read-status indicator markup exists anywhere (mechanically confirmed absent, not merely unnoticed) — nothing to preserve on that axis beyond its continued absence. Attachment rendering per §3.7. No other `data-*` attribute exists on an individual bubble beyond the structural classes named above.

### 3.9 Sidebar / list semantics audit

- **Unread badge/counter**: `<span class="badge bg-primary rounded-pill float-end notification_count">{{ $chat->notification }}</span>` when `$chat->notification` is truthy, else a hidden wrapper `<div class="counter" hidden><span class="badge ... notification_count"></span></div>` — identical shape in `_sidebar.blade.php`'s pinned-chat loop and `partials/_chat_list.blade.php`'s AJAX-loaded loop. JS reads/writes this exact structure by class (`$(this).find(".notification_count").remove()`, `$counter.removeAttr("hidden")`, `$(".notification_count", $contact).html(response.notification)`) — the `counter`/`notification_count` classes and the `hidden` attribute mechanism must be preserved exactly regardless of any `<x-badge>` decision (§5.6).
- **Selected-conversation state**: `.active` class toggled on the clicked `<li>`, exclusively (`$(".chat-users-list li, .chat-users-list-pinned li").removeClass("active"); $(this).addClass("active");`).
- **Pin/block/delete controls**: `.add-to-pin` (empty static span, entirely JS-populated at runtime — §3.3, §5.5), `.add-to-blacklist` (static tooltip trigger, `shield` icon), `.remove-btn` (static tooltip trigger, `trash` icon) — each bound by class selector to its own SweetAlert2 confirm flow (§3.10).
- **Search/filter**: `#chat-search` (debounced 500ms keyup → AJAX reload), `.tab-button` (4 buttons, `data-filter` attribute drives the `filter` AJAX parameter, plus a `btn-primary`/`btn-outline-primary` class toggle applied by the same click handler — §5.2), `#load-more` (manual click and automatic `#users-list` scroll-bottom trigger, both call the same handler).

### 3.10 SweetAlert2 / Bootstrap / plugin runtime audit

**3 SweetAlert2 confirm flows**, all in `index.blade.php`, all sharing the identical `customClass: { confirmButton: "btn btn-primary", cancelButton: "btn btn-outline-danger ms-1" }, buttonsStyling: false` configuration:
1. `.remove-btn` click → delete confirmation → `POST /{box}/delete`.
2. `.add-to-blacklist` click → block confirmation → `POST /{box}/block`.
3. `.add-to-pin` click → pin/unpin confirmation → `POST /{box}/pin`.

These `customClass` JS-string values are JavaScript configuration, not static Blade markup — exactly analogous in kind to Slice 5's own SweetAlert2 findings (§3.4/§3.11 of the Slice 5 contract) — not Blade-component-adoptable, untouched regardless of any button-adoption decision elsewhere in the file.

**Bootstrap**: no modal exists (§3.2, §3.9 of this document). `.add-to-blacklist`/`.remove-btn`'s tooltips are Bootstrap's own jQuery tooltip plugin, initialized app-wide (the same mechanism `<x-tooltip>` itself is built on, §3.2). `.add-to-pin`'s tooltip is initialized/re-initialized imperatively at runtime (`addToPin.tooltip("dispose").tooltip()`) against whatever `title` attribute the JS itself most recently set — entirely JS-owned, no static markup to adopt (§5.5).

**Select2**: 5 instances (`sms_template` ×2, `sending_server`, `sender_id`, `country_code`), standard app-wide init pattern (`dropdownAutoWidth: true, width: "100%", dropdownParent: $this.parent()`), identical to every other Select2 usage already audited in Slice 5.

### 3.11 Echo/Pusher conditional guard audit — critical finding, confirmed intact

The M2 contract's own §3.3 flags this as critical: **`config('broadcasting.connections.pusher.app_id')` must never be assumed always-configured.** Directly re-confirmed in current `main`, two independent guard sites, both correct:
1. `@if(config('broadcasting.connections.pusher.app_id'))` — guards the `<script src="{{ asset(mix('js/scripts/echo.js')) }}"></script>` tag itself (line 192), so the Echo client library is not even loaded when broadcasting is unconfigured.
2. `@if(config('broadcasting.connections.pusher.app_id'))` — a **second, independent** guard (line 781) wraps the entire `window.Echo = new Echo({...}); Echo.private("chat").listen(...)` instantiation-and-listener block, closed by `@endif` at line 875 (after message template path 3, §3.6).

Both guards must remain present, unmodified, and independent (not collapsed into one) — no code path anywhere in the 4 files references `window.Echo` or `Pusher` outside these two guarded regions. **This contract makes zero change to either guard**, and any future implementation must not either.

### 3.12 Slice-1 chat-background remediation — confirmed already merged, not reopened

Mechanically confirmed present in current `main`, all three markers:
- `$chat-bg-light`/`$chat-bg-dark` no longer exist as SCSS variables in `bootstrap-extended/_variables.scss` — replaced by an explicit comment: `// Design System M2 Slice 1 contract Sec6.17/item 40(a) -- chat-bg-light/chat-bg-dark (embedded base64 chat background patterns) removed. Chat backgrounds are configurable theme surfaces only, never a decorative image, regardless of which preset is active.`
- `resources/scss/base/pages/app-chat.scss` uses a token-driven `$chat-image-back-color` variable in place of the old embedded base64 pattern, with its own comment confirming the base64 pattern's structural removal.
- `resources/scss/base/tokens/_runtime-bindings.scss` exists, and the `platform_theme_presets`/`platform_theme_fonts`/`platform_theme_preset_events` migrations exist — confirming the full Slice 1 theme-preset/token/runtime-binding infrastructure (not just the chat-background piece in isolation) is genuinely built and merged, not partially staged.

**Conclusion, stated precisely per this contract's own instructions:** Slice 1 already completed the chat *background/token* remediation (SCSS-level, outside `resources/views`). **Slice 6's own future scope is exclusively the Blade-markup componentization layer** (icon migration, the button/card/tooltip adoptions in §5, and the 3 hardcoded literals in §3.4, which live inside a Blade file's own inline `<style>` block, not in any SCSS file Slice 1 touched) — a genuinely distinct, non-overlapping, complementary scope. This contract does not re-authorize, reference as pending, or duplicate any part of Slice 1's already-merged work.

### 3.13 Component-library drift check

Every component API cited in §3.2/§5 was re-read directly at this drafting pass's base SHA, not carried over from the Slice 5 contract's own findings. No drift found: `card`, `button`, `dialog`, `badge`, `input`, `select`, `table`, `tooltip`, `ds-icon`, `alert`, `empty-state` all match Slice 5's own characterization exactly, confirming the component library has not changed between the Slice 5 and Slice 6 base SHAs (expected, since no intervening slice has touched `resources/views/components/`).

---

## 4. Locked Slice 6 scope

- The 4 files inventoried in §3.1 — **4 existing Blade views, no exclusions**.
- **No new production Blade component file of any kind** — every adoption in §5 uses an existing component from the 18 already in `resources/views/components/`; none is created, extended, or modified.
- **No new SCSS file** — the 3 hardcoded-literal eliminations (§3.4) are edits within `index.blade.php`'s own existing inline `<style>` block, referencing Slice 1's already-emitted CSS custom properties.
- Three new, mechanically-derived test files (§8), mirroring the Slice 5 pattern exactly.
- No controller, route, middleware, FormRequest, model, or migration file — **with the sole exception of whatever paths the separate, prerequisite security-remediation contract named in §7 authorizes**, which is not this contract's own scope and is not enumerated here (§7 explicitly does not pin a remediation SHA that does not yet exist, mirroring Slice 5's own §7 discipline exactly).
- No `app/`, `database/`, or `routes/` path of any kind within *this* contract's own future allowlist (§9).
- No other path.

---

## 5. Component adoption

**Standard, identical to Slice 5's own final standard:** adopt a canonical component only where its actual, current, read API (§3.2) is DOM- and behavior-compatible with the existing surface it would replace. Otherwise retain native Bootstrap/plain markup — already runtime-token-compliant via Slice 1's `_runtime-bindings.scss`, so non-adoption is a deliberate, correct decision, never an incomplete migration. Every candidate below is classified as exactly one of: **A — adopted**, **B — retained native (component API cannot reproduce current semantics)**, **C — out of scope (runtime JS/plugin-owned markup, nothing static to adopt)**, **D — requires separate prerequisite/remediation**.

### 5.1 Card — Category A (1 adoption)

**Adopted:** `new.blade.php`'s single `.card` wrapper (lines 29–160) — one `.card-header` (title + a mobile-only secondary link) plus one `.card-body` containing the compose form, a direct structural match for `<x-card :title="__('locale.labels.new_conversion')">` with the mobile-only `<a href="{{route('customer.chatbox.index')}}" class="text-primary d-block d-md-none">{{__('locale.menu.Chat Box')}}</a>` link placed in the component's own named `actions` slot (§3.2 confirms this slot exists and renders exactly alongside the title).

**Not a card-adoption surface at all:** `index.blade.php`, `_sidebar.blade.php`, `partials/_chat_list.blade.php` — none contains an actual `.card`/`.card-header`/`.card-body` structural wrapper. `_sidebar.blade.php` and `partials/_chat_list.blade.php` each reuse the bare `.card-text` utility class on standalone `<p>` elements with no enclosing `.card` div at all — cosmetic class reuse, not a card, identical in kind to Slice 5's own `subscribe_form.blade.php` finding.

### 5.2 Button — Category A (6 candidates identified; exact final count confirmed at implementation time per the note below)

**Adopted, genuine `<button>`/`<a>` tags, no structural incompatibility found:**
- `_sidebar.blade.php`'s 4 tab-filter buttons (`recents`/`unread`/`read`/`all`) — real `<button type="button">` elements, `btn-primary`/`btn-outline-primary` classes exactly matching `<x-button variant="primary">`/`variant="outline"`. **Implementation-time verification required, not blocking this contract**: the `.tab-button` click handler (`index.blade.php`, §3.9) toggles these exact two classes via jQuery `removeClass()`/`addClass()` at runtime — `<x-button>`'s own attribute-merge mechanism (§3.2) preserves any caller-supplied extra class (`tab-button`) and `data-filter` attribute, so the toggle continues to operate on the rendered `<button>`'s classes exactly as it does today; this is a DOM/attribute-level compatibility finding, not a rendered-pixel claim, and remains subject to ordinary implementation-time visual verification like every other adoption.
- `_sidebar.blade.php`'s "Load More" button (`<button class="btn btn-sm btn-primary mt-1" id="load-more">`) — `variant="primary" size="sm"`, icon `refresh-cw`.
- `index.blade.php`'s composer send button (`<button type="button" class="btn btn-primary send" onclick="enter_chat();">`) — `variant="primary"`, `type="button"`, the `onclick` handler and `.send`/`#`-less class preserved via attribute merge; its responsive icon-only/text-only pair (`<i data-feather="send" class="d-lg-none">` / `<span class="d-none d-lg-block">Send</span>`) is preserved as **slot content** (with the static icon migrated to `<x-ds-icon>` in place), not passed through the component's own `icon` prop — the `icon` prop always renders unconditionally alongside slot content (§3.2) and cannot represent a breakpoint-conditional either/or swap.
- `new.blade.php`'s submit button (`<button type="submit" class="btn btn-primary mr-1 mb-1 float-end"><i data-feather="send"></i> Send</button>`) — `variant="primary" type="submit"`, icon `send` via the component's own `icon` prop (a plain, unconditional icon+text pair, a direct fit here, unlike the composer button above).

**Not adopted — no matching component surface, structurally out of scope (Category C):** the two `<span>` icon-tooltip triggers (`.add-to-blacklist`, `.remove-btn`) are not `.btn`-classed at all — they are `<x-tooltip>` candidates instead (§5.4), not button candidates. The `<a href="...chatbox.new" class="text-dark ms-1">`/`class="text-dark">` links (in `_sidebar.blade.php`'s mobile "new conversation" shortcut and `index.blade.php`'s `start-chat-area` empty-state) carry no `.btn`/`btn-*` class at all — plain text-styled links, not button-adoption candidates in the first place (pure icon-migration targets only, where they contain a static icon).

### 5.3 Input / Select — Category B, zero adoptions

**No `<x-input>`/`<x-select>` adoption anywhere in this slice.** Every candidate control is disqualified by one of the same three reasons Slice 5 already established as disqualifying:
- **Select2-controlled** (disqualifying per Slice 5 precedent): `sms_template` (×2, `index.blade.php` and `new.blade.php`), `sending_server`, `sender_id`, `country_code` — **5 total selects, 0 adoptable.**
- **`.input-group`-nested** (disqualifying per Slice 5 precedent): `#chat-search` (`_sidebar.blade.php`, nested beside an `.input-group-text` search-icon span) and `#recipient` (`new.blade.php`, nested beside the `country_code` select) — **2 total inputs, 0 adoptable.**
- **No matching component type exists at all** (a stronger disqualifier than any Slice 5 finding, since no workaround is possible regardless of markup shape): the `#message` textarea (both files) and the `#media_image` hidden file input (`index.blade.php`) — **there is no `<x-textarea>` or `<x-file-input>` component anywhere in the current 18-component library** (§3.2). These stay native by structural necessity, not by a judgment call.
- **Hidden fields, not visible-UI candidates**: `sms_type` and `idempotency_token` (`new.blade.php`) — `<x-input>` always renders a visible, labeled `<div class="ds-field">` wrapper; wrapping a `type="hidden"` field in it would add unwanted visible markup around an intentionally invisible field. Native retention, with `idempotency_token`'s exact `name`/value-binding preserved byte-for-byte per its RFC-005 Milestone 5 criticality (§3.7).

### 5.4 Tooltip — Category A (2 adoptions)

**Adopted:** `index.blade.php`'s `.add-to-blacklist` (`shield` icon, title "Block") and `.remove-btn` (`trash` icon, title "Delete") tooltip triggers — both static `<span data-bs-toggle="tooltip" data-bs-placement="top" title="...">` wrappers around a single static icon, a direct structural match for `<x-tooltip :text="...">` (§3.2 confirms the component's own attribute-merge preserves the `add-to-blacklist`/`remove-btn` click-target classes exactly).

**Not adopted — Category C, nothing static to adopt:** `.add-to-pin` (`<span class="add-to-pin"> </span>`, `index.blade.php` line 119) is, in its own static Blade markup, an **empty** span with no `data-bs-toggle`, no `title`, and no icon child at all — every one of those is set imperatively by JS at runtime (`addToPin.attr("title", ...)`, `addToPin.tooltip("dispose").tooltip()`, `addToPin.find("svg").remove()` + `addToPin.append(...)`, §3.3/§3.10). There is no static markup here for `<x-tooltip>` (or any component) to adopt — this is not a native-retention judgment call, it is the absence of a component-adoption surface in the first place.

### 5.5 Badge — Category B, zero adoptions

**Not adopted anywhere.** The single recurring badge shape (`<span class="badge bg-primary rounded-pill float-end notification_count">`, appearing identically in `_sidebar.blade.php` ×2 and `partials/_chat_list.blade.php` ×2, §3.9) uses a **solid** `bg-primary` fill. `<x-badge>`'s entire variant enum (§3.2) produces only **soft/light** `bg-light-*` backgrounds — there is no variant, including `accent`, that reproduces a solid fill. Adopting `variant="accent"` would silently change the notification counter from a solid, high-contrast pill to a soft, low-contrast one — a real visual/semantic regression in exactly the surface (unread-message emphasis) most in need of contrast, not a preservation. **The entire badge structure remains native Bootstrap markup, already token-bound** via Slice 1's runtime-bindings layer. `<x-badge>` is not extended and no new variant is invented — identical reasoning and identical conclusion to Slice 5's own `_import_history.blade.php` badge finding.

### 5.6 Alert — Category B, zero adoptions

**Not adopted.** `new.blade.php`'s single `alert alert-info` block (lines 17–22, static, non-conditional, wrapping a nested `.alert-body` div containing a static `info` icon and message text) uses Bootstrap's `alert-info` class. `<x-alert>`'s entire variant enum (§3.2) maps `accent` to `alert-primary`, not `alert-info` — there is no variant reproducing `alert-info`'s distinct color. The component's own rendered structure is also a single flat div with no nested `.alert-body` wrapper, a second, independent structural mismatch. **The block remains native Bootstrap markup, already token-bound.** `<x-alert>` is not extended and no `info` variant is invented — identical reasoning and identical conclusion to Slice 5's own badge/dialog non-adoption pattern.

### 5.7 Empty-State — Category B, zero adoptions

**Not adopted.** `index.blade.php`'s `.start-chat-area` block (lines 95–106) uses a deliberate breakpoint-conditional pair of headings — one plain-text `<h4 class="... d-block d-md-none">` (small screens) and one link-wrapping `<h4 class="... d-none d-md-block"><a href="...">...</a></h4>` (medium+ screens) — sharing the same icon and text but differing in whether the text is clickable, based on viewport width. `<x-empty-state>`'s `title` prop (§3.2) is a single, non-conditional plain-text value; its own separate `action` slot renders as one more block-level element below the description, not as a breakpoint-swapped alternate presentation of the title itself. Reproducing the current responsive behavior through the component's actual API is not possible without either dropping the responsive distinction (a real behavior change) or rendering both headings redundantly outside the component's own title position (defeating the point of adopting `title` at all). **The block remains native markup, already token-bound.** `<x-empty-state>` is not extended — identical reasoning in kind to Slice 5's Dialog non-adoption (a real, structurally-provable API/markup mismatch, not a guess). The `.start-chat-area` class itself is a required JS selector hook (§3.9, toggled `d-none` on chat-open) and must be preserved regardless of this decision.

### 5.8 Dialog, Table, Pagination, Menu — Category C, zero candidates

No modal, table, `->links()` paginator call, or real Bootstrap dropdown-menu markup exists anywhere in the 4 files (§3.2, §3.5, §3.8). These four components are structurally irrelevant to this slice — not non-adopted by judgment, simply absent as a candidate surface.

### 5.9 List item / message bubble — Category B, no component exists to adopt

**Honest limitation, stated explicitly per this contract's own instructions rather than silently narrowed:** the rollout map's phrase "full componentization" (§0) does not mean every visual surface in ChatBox has a matching Design System component to adopt. The chat-list `<li>` structure (`_sidebar.blade.php`'s pinned loop, `partials/_chat_list.blade.php`'s AJAX-loaded loop) and the message-bubble `<div class="chat">`/`<div class="chat-body">` structures built by all 3 JS template paths (§3.6) have **no corresponding "list item" or "message bubble" component anywhere in the current 18-component library** (§3.2) — not a card, not a table row, not any existing shape. Building one would require **creating a new production Blade component file**, which this contract does not authorize (§4's own "no new production Blade component file of any kind" rule, mirroring Slice 5's identical "no new production file" discipline). **These structures remain entirely native for the whole of Slice 6's own future scope** — this is a genuine, mechanically-derived boundary on what "componentization" can mean for ChatBox without a separately-authorized new-component contract, not an oversight.

No component is forced anywhere its real, read API does not match the existing markup's shape or behavior. §8's `ChatBoxComponentAdoptionTest` (once Slice 6 is eventually implemented) must assert presence **only** for the exact adoptions locked in §5.1/§5.2/§5.4 — never for a non-adopted surface (§5.3/§5.5/§5.6/§5.7/§5.8/§5.9), and never requiring any intentionally-retained native markup to disappear.

---

## 6. Preserve all behavior

No controller, route, request, middleware, authorization rule, cache key, or query's actual filtering/scoping logic may change for styling convenience. Route/controller/security behavior is entirely read-only to any future Slice 6 presentation implementation until §7's prerequisite is satisfied. A future Slice 6 implementation must preserve exactly: every route/method/middleware stack on the post-remediation baseline (§7); the `chat-box` route group's exact 10 route names (§3.1's controller audit, §9's future preservation-test scope); every AJAX endpoint URL construction (`/chat-box/{id}/messages`, `/notification`, `/reply`, `/delete`, `/block`, `/pin`, `/load`, `templates/show-data/{id}`); the exact SweetAlert2 payload shape for all 3 flows (§3.10); the exact composer/attachment/SMS-template-picker structure (§3.7); the two independent Echo/Pusher conditional guards, unmodified and un-collapsed (§3.11); the 3 message-template JS construction paths, unmodified in count and logic (§3.6, unless a future audit at implementation time proves a different number — §9); every element `id`/`name`/`data-*` attribute and JS-selector class (`.tab-button`, `.send`, `.message`, `.counter`, `.notification_count`, `.active`, `.chat-left`, `.chat-time`, `.add-to-pin`, `.add-to-blacklist`, `.remove-btn`, `.start-chat-area`, `.active-chat`); the `idempotency_token` hidden field's exact name and value-binding (§3.7); every localization key already present; the `feather.replace()` calls and the Feather runtime, intact (§3.3).

**Icon migration is independent of native-retention decisions, identical rule to Slice 5's own Exception B**: every one of the 13 distinct/14 total static `data-feather` occurrences (§3.3) migrates to `<x-ds-icon>` regardless of whether the element containing it is adopted (§5.1/§5.2/§5.4) or natively retained (§5.3/§5.5/§5.6/§5.7/§5.9) — a native-retention decision freezes only the structural/semantic shell, never an in-scope static icon child. Concretely: the natively-retained notification badge contains no icon itself (nothing to migrate there); the natively-retained `alert-info` block's `info` icon still migrates; the natively-retained `.start-chat-area` empty-state's `message-square` icon still migrates; the two JS-string-embedded `delete`/`edit-2` icons (§3.3) still migrate via the identical embedding technique Slice 5 already used successfully for `contactGroups/show.blade.php`.

---

## 7. The authorization gap — mandatory pre-Slice-6-implementation prerequisite

**Slice 6 implementation must not begin at all until this prerequisite is satisfied — identical governance shape to Slice 5's own §7.** Every finding below is from direct, full-file reading of `app/Http/Controllers/Customer/ChatBoxController.php` (694 lines) and its supporting model/route files (§1 item 4), not inferred.

### 7.1 Cross-tenant authorization gap — severe, confirmed

`ChatBox` (`app/Models/ChatBox.php`) carries **no global scope** of any kind — a plain Eloquent model. Its `getRouteKeyName()` (via `HasUid`) resolves route-model binding by `uid` (a `uniqid()` value — time-based, not cryptographically random, and never intended as a security boundary). Of the controller's 10 actions, **6 resolve a specific `ChatBox` record with zero ownership check against the authenticated customer**:

| Action | Route | Resolution | Ownership check |
|---|---|---|---|
| `messages($id)` | `POST /{box}/messages` | raw `\DB::table('chat_boxes')->where('id', $id)` (not even model-bound; queries the `id` column with a value the calling JS always supplies as the record's `uid` — §7.4 notes this as a likely-separate correctness defect) | **none** |
| `messagesWithNotification(ChatBox $box)` | `POST /{box}/notification` | implicit route-model binding by `uid` | **none** |
| `reply($id, ...)` | `POST /{box}/reply` | `ChatBox::find($id)` | **none** |
| `delete(ChatBox $box)` | `POST /{box}/delete` | implicit route-model binding by `uid` | **none** |
| `block(ChatBox $box)` | `POST /{box}/block` | implicit route-model binding by `uid` | **none** |
| `pin(ChatBox $box)` | `POST /{box}/pin` | implicit route-model binding by `uid` | **none** |

By contrast, `index()` (`ChatBox::where('user_id', Auth::id())...`) and `loadChatUsers()` (`ChatBox::where('user_id', Auth::id())...`) are both correctly scoped. This confirms the gap is not a systemic absence of a scoping mechanism in this codebase (the correct pattern is used twice, right next to the six that omit it), but a real, per-action inconsistency — structurally identical in kind to the Slice 5 Contacts/CRM finding that already required its own dedicated remediation contract.

**Concrete impact, most-severe first**: any authenticated customer can (a) **permanently delete** any other customer's entire conversation via `delete`, (b) **send an SMS through another customer's conversation thread** via `reply` — using that other customer's `from` number and `sending_server_id`, a billing/spoofing/abuse vector layered on top of the RFC-005 Milestone 5 idempotency-token system already protecting this same endpoint's own billing-integrity concern, (c) **read the full message history** of any other customer's conversation via `messagesWithNotification` (and, separately, whatever `messages($id)` actually resolves given its own id/uid mismatch, §7.4), (d) **toggle the pin state** of any other customer's conversation via `pin`, and (e) **trigger a `Blacklists` row creation and an unscoped `Contacts` status mutation** keyed off another customer's conversation's `to` number via `block` — mirroring the exact "customer-originated exploit path through shared unscoped repository code" shape Slice 5's own Correction D already named for Blacklists, here reachable through a second, independent controller.

### 7.2 Permission-bypass gap — severe, confirmed, compounding §7.1

`chat_box` is a real, registered permission (`config/customer-permissions.php`, `'chat_box' => ['display_name' => 'chat_box', ...]`) — meaning a customer account can legitimately be denied ChatBox access (e.g., a restricted sub-account). Of the controller's 10 actions, `index()`, `new()`, `sent()`, and `reply()` correctly call `$this->authorize('chat_box')`. **The other 6 — `messages()`, `messagesWithNotification()`, `delete()`, `block()`, `loadChatUsers()`, `pin()` — call no authorization check of any kind**, relying only on the blanket customer `auth` middleware. A customer explicitly denied the `chat_box` permission can still list, read, pin/unpin, delete, and block through these six endpoints. This compounds §7.1: even confining the fix to "restore the missing permission check" would not by itself close the cross-tenant gap, since a customer who *does* legitimately hold `chat_box` still has no ownership boundary on any of the same six actions.

### 7.3 Stored-XSS-shaped finding — confirmed, present in all 3 message-rendering paths

All 3 JS template-literal/string-concatenation message-bubble constructions (§3.6) interpolate `sms.message` (history load, Echo listener) or `messageValue` (optimistic send) directly into HTML with **no escaping**:
```js
let message = sms.message ? `<p>${sms.message}</p>` : "";           // history load
"<p>" + messageValue + "</p>"                                        // optimistic send
message = `<p>${sms.message}</p>`;                                   // Echo listener
```
`sms.message` in the history-load and Echo-listener paths is server-stored SMS content — for an **inbound** message, this is text authored by whoever texted the connected phone number, not the account holder. If that content contains HTML/script-shaped text, it renders unescaped in the receiving customer's authenticated browser session when the conversation is viewed or a new message arrives in real time via Echo — a stored-XSS-shaped finding, structurally identical to Slice 5's own Correction E findings (the `contactGroups/show.blade.php` `{!! !!}`/`html`-pattern and the admin Blacklists raw-`displayName()` pattern), both of which Slice 5's own security remediation was required to explicitly resolve or mechanically prove non-exploitable before Slice 5 could be authorized. The optimistic-send path's own interpolation of `messageValue` is lower severity (self-authored, affects only the typing customer's own already-authenticated session — a DOM-based self-XSS at most), but is the same unescaped pattern and should be resolved uniformly rather than left as an inconsistent exception.

`media_url`/`sms.media_url` interpolation into `src="${...}"` attributes in all 3 paths is lower risk (server-generated upload paths, per `Tool::uploadImage()`, not directly attacker-supplied free text) but was not verified immune to attribute-breakout if the stored path value ever legitimately contains a `"` character — named here for the remediation's own audit to close definitively, not assumed safe.

### 7.4 Additional correctness note — `messages($id)`'s own id/uid mismatch (not itself a security-remediation blocker, named for precision)

`messages($id)` queries `chat_boxes.id = $id` via a raw `\DB::table` call, but the only caller (`index.blade.php`'s click handler, §3.9) always supplies `$(this).data("id")`, which is rendered from `{{$chat->uid}}`, not the integer primary key. If `id` is a conventional autoincrement column, this endpoint may already be silently non-functional (matching zero rows) in current `main`, independent of any authorization concern. This does not reduce the severity of §7.1's other five findings (which all correctly resolve real records via `uid`-based model binding) — named here only so the eventual remediation's own audit addresses it with full context rather than rediscovering it, and so this contract does not overstate `messages()`'s own practical exploitability relative to the other five actions.

### 7.5 Remediation requirements, mirroring Slice 5's own §7 discipline exactly

A separate, dedicated ChatBox security-remediation contract must be drafted, human-reviewed, and human-merged, and its own implementation must likewise be human-merged, **before** Slice 6 implementation may be authorized. That remediation must, at minimum:

- Scope every one of the 6 actions named in §7.1 to the authenticated customer's own `ChatBox.user_id` ownership boundary — rejecting a foreign or nonexistent box identically (matching the established Slice 5 "identical denial for foreign vs. nonexistent" pattern), never distinguishing the two in a way that would let an attacker enumerate valid box identifiers by response-shape alone.
- Restore the `chat_box` permission check (§7.2) to every one of the same 6 actions, consistent with the 4 actions that already have it.
- Explicitly audit and resolve the disposition of the stored-XSS-shaped finding (§7.3) across all 3 message-rendering paths uniformly — mechanically concluding non-exploitability or remediating directly, never leaving any of the 3 paths unexamined, and resolve the `media_url` attribute-breakout question named in §7.3 explicitly rather than leaving it assumed-safe.
- Resolve, or explicitly document as a separate non-blocking follow-up, the `messages($id)` id/uid finding (§7.4) — this note does not itself gate Slice 6 authorization, but must not be silently dropped either.
- Preserve `index()`'s and `loadChatUsers()`'s own already-correct `user_id` scoping exactly.

Once the remediation's implementation is merged, Slice 6's own future implementation authorization must pin: (1) the exact ChatBox security-remediation implementation merge SHA; (2) the exact then-current `origin/main` SHA Slice 6 implementation is based on; (3) the exact focused security test file(s)/command(s) the remediation introduces, run before Slice 6's own three new focused tests (§8), requiring zero failures.

**This contract does not, and cannot, hard-code a remediation merge SHA that does not yet exist.**

---

## 8. Test contract (Slice 6, drafted now for the eventual implementation authorization)

Three new files under `tests/Feature/DesignSystem/`, mirroring Slice 5's own count and naming convention exactly. No existing test file requires modification.

1. **`tests/Feature/DesignSystem/ChatBoxDesignSystemContentTest.php`** — zero remaining static `data-feather="..."` attributes (both plain-quoted and the two JS-string-embedded occurrences, §3.3) in the 4 modified files; genuine `<x-ds-icon>`-equivalent markup present per migrated file, including inside every natively-retained element named in §5 (the notification badge, the `alert-info` block, the `.start-chat-area` empty-state, every Select2/textarea/file-input field) — native retention never exempts an in-scope icon child (§6). MUST NOT require zero `feather.replace()` calls or any Feather-runtime change. MUST NOT require any `ChatBoxController.php` change beyond what the separate §7 remediation itself introduces. Zero hardcoded color literals remaining in `index.blade.php`'s inline `<style>` block, replaced by the exact 3 token references named in §3.4. Every Select2/SweetAlert2 selector, the `#load-more`/`#chat-search` AJAX wiring, and both Echo/Pusher conditional guards (§3.11) still present verbatim.
2. **`tests/Feature/DesignSystem/ChatBoxComponentAdoptionTest.php`** — asserts presence only for the exact adoption set locked in §5: `<x-card>` on `new.blade.php` only (1 total); `<x-button>` for the exact set named in §5.2 (the 4 sidebar tab/load-more buttons + composer send + new.blade.php submit — exact final count confirmed and locked at implementation time, since §5.2 identifies the compatible candidates but this contract does not pre-count a final integer the way Slice 5's own post-round exception did, having had no correction round to force that reconciliation); `<x-tooltip>` for exactly the 2 static triggers named in §5.4. **Never asserts** `<x-badge>`, `<x-alert>`, `<x-empty-state>`, `<x-input>`, `<x-select>`, `<x-dialog>`, `<x-table>`, `<x-pagination>`, or `<x-menu>` anywhere in this slice (§5.3, §5.5–§5.9), and never asserts a "list item"/"message bubble" component that does not exist (§5.9).
3. **`tests/Feature/DesignSystem/ChatBoxExistingBehaviorPreservedTest.php`** — covers the full §6 preservation matrix (routes, AJAX endpoints, SweetAlert2 payload shape, composer/attachment/SMS-template structure, both Echo/Pusher guards, the 3 message-template paths' continued independent existence, every named id/class/data-* hook, the `idempotency_token` hidden field's exact name/value-binding) plus, per §7's remediation-preservation requirement, a targeted assertion that the remediation's own tenant-scoping, permission-check, and XSS-disposition changes survive the presentation restyle — mirroring Slice 5's own `ContactsCrmExistingBehaviorPreservedTest.php` pattern exactly.

**Ordering requirement (§7):** the ChatBox security-remediation's own pinned test file(s) run first, zero failures required, before any of the three files above.

**Regression baseline**: full existing suite re-run at Slice 6's own final head, 3 new files passing, zero regression in any pre-existing test. This contract itself does not run that suite (docs-only, §0).

---

## 9. Exact future implementation allowlist

**Mechanically derived from §3–§8 above, not assumed to be the historical "4 files" figure.** This is a **prospective** allowlist for the eventual, separately-authorized Slice 6 implementation — this contract itself changes only the one path named in §2.

### Production views (4 modified — unchanged from the mechanically re-derived §3.1 inventory)

1. `resources/views/customer/ChatBox/index.blade.php` — icon migration (8 of the 14 total occurrences, including the 2 JS-string-embedded ones); adopts `<x-button>` for the composer send button and (jointly with item 3 below) the 4 tab-filter buttons and the Load More button; adopts `<x-tooltip>` for `.add-to-blacklist`/`.remove-btn`; eliminates the 3 hardcoded color literals in its own inline `<style>` block (§3.4); does not adopt `<x-badge>` (notification counter), `<x-empty-state>` (`.start-chat-area`), `<x-input>`/`<x-select>` (composer textarea/file-input/SMS-template select), or any list-item/message-bubble structure (§5.9); the 3 message-template JS paths, both Echo/Pusher guards, and all 3 SweetAlert2 flows preserved unmodified except for the two embedded icon-name migrations.
2. `resources/views/customer/ChatBox/new.blade.php` — icon migration (2 occurrences); adopts `<x-card>` (with the mobile-only link in its `actions` slot) and `<x-button>` for its submit button; does not adopt `<x-alert>` (`alert-info` block) or `<x-input>`/`<x-select>` (all Select2-controlled or `.input-group`-nested, plus the two RFC-005-critical hidden fields untouched).
3. `resources/views/customer/ChatBox/_sidebar.blade.php` — icon migration (4 occurrences); adopts `<x-button>` for the 4 tab-filter buttons and the Load More button; does not adopt `<x-badge>` (notification counter) or `<x-input>` (`#chat-search`, `.input-group`-nested); the pinned-chat `<li>` loop's list structure remains entirely native (§5.9).
4. `resources/views/customer/ChatBox/partials/_chat_list.blade.php` — zero icon migration needed (zero static icons present, §3.3); does not adopt `<x-badge>`; its `<li>` loop structure remains entirely native (§5.9), identical in shape to `_sidebar.blade.php`'s own pinned-chat loop.

### New focused tests (3 new)

5. `tests/Feature/DesignSystem/ChatBoxDesignSystemContentTest.php`
6. `tests/Feature/DesignSystem/ChatBoxComponentAdoptionTest.php`
7. `tests/Feature/DesignSystem/ChatBoxExistingBehaviorPreservedTest.php`

### Plugin-chrome override files — audited, none required

Per §3.4's own mechanical finding (zero hardcoded hex in the Select2/SweetAlert2/Toastr override files ChatBox exercises), **no plugin-override SCSS path is added to this allowlist.** If a future, closer implementation-time re-audit finds this conclusion has changed (e.g., an intervening slice reintroduces a hardcoded literal into one of these three files), that is itself a stop-and-report condition (§12), not a silent addition.

**Counts** — Production views: **4**. Tests: **3**. **Overall total: 7. Stop threshold: 8th path.**

This total is deliberately, mechanically smaller than the loosely-worded "full componentization" phrase might suggest, for the reasons stated exactly in §5.9: most of ChatBox's own distinctive UI (the chat list, the message bubbles) has no existing component to adopt, and this contract does not authorize creating one. The 4-file, 7-path scope above is the complete, honest boundary of what icon migration, the confirmed button/card/tooltip adoptions, and hardcoded-color elimination can achieve within the current component library — not a placeholder pending a larger count.

---

## 10. Responsiveness review

Existing Bootstrap breakpoints only, no new breakpoint system, identical governance to every prior slice. The `.start-chat-area` empty-state's own responsive plain-text/link swap (§5.7) and the composer send button's own responsive icon/text swap (§5.2) are both explicitly named preservation requirements — a future implementation must confirm both breakpoint behaviors are byte-identical before and after adoption, not merely "component renders."

---

## 11. Forbidden scope

No product-behavior change; no `app/`/`database/`/`routes/` change of any kind, including the tenant-isolation and permission-bypass fixes (§7's exclusive scope, a separate contract+implementation); no narrowing of any existing ChatBox behavior; no Slice 4/Campaigns/billing work; no new token/JS/CSS framework; no client-side icon library beyond Feather (§3.3); no new Blade→JS hydration seam; no `AI-AUTONOMY-STATE.json` change; no automatic advancement to Slice 7a or any other initiative; no new production Blade component file (§4, §5.9); no extension or modification of `<x-card>`, `<x-button>`, `<x-tooltip>`, `<x-badge>`, `<x-alert>`, `<x-empty-state>`, `<x-input>`, `<x-select>`, `<x-dialog>`, `<x-table>`, or `<x-pagination>` themselves — every non-adoption in §5 is resolved by retaining native markup, never by changing a shared component to fit; no re-authorization or re-implementation of Slice 1's already-merged chat-background/token work (§3.12).

---

## 12. Governance block

```
maximum_correction_rounds: 2
correction_round: 0
docs_only: true
implementation_has_occurred: false
merge_authorizes_implementation: false
implementation_requires_separate_human_authorization: true
implementation_blocked_until: "ChatBox tenant-isolation and permission-bypass security remediation contract + implementation, both human-merged, stored-XSS-shaped finding resolved (§7)"
advance_automatically: false
start_automatically_after_contract_merge: false
merge_authority: human_only
slice_4_status: deliberately_skipped_unless_separately_reopened
no_deployment: true
no_force_push: true
no_automatic_advance_to_slice_7a_or_campaigns_or_any_other_initiative: true
```

---

## 13. Mechanical searches (Slice 6, to be run at future implementation time)

1. `grep -rniE "anthropic|claude"` across every path in §9 → zero matches.
2. `grep -c "data-feather"` across §9 items 1–4 (both plain-quoted and JS-string-embedded occurrences) → zero.
3. `grep -c "feather\.replace"` in `index.blade.php` → unchanged from §3.3's count (3).
4. `grep -rnoE "#[0-9A-Fa-f]{3,8}|rgba?\([^)]*\)"` across §9 items 1–4 → zero (confirms the 3 literals named in §3.4 are eliminated, and none reintroduced elsewhere).
5. `git diff --stat -- app database routes` compared against §9 → must be completely empty for this contract's own future presentation implementation (the separate §7 remediation is its own, independently-tracked diff).
6. `grep -c "chat-bg-light\|chat-bg-dark"` anywhere in the changed-path set → zero (confirms Slice 1's own already-merged remediation is not reopened or reintroduced, §3.12).
7. `grep -n "config('broadcasting.connections.pusher.app_id')"` in `index.blade.php` → exactly 2 occurrences, both `@if` guards present and independent (§3.11).
8. `grep -c "Echo\."` in `index.blade.php`, outside the two guarded `@if` blocks named in item 7 → zero (confirms no ungated Echo/Pusher reference was introduced).
9. Every one of the 13 distinct static icon names in §3.3 individually confirmed against `vendor/technikermathe/blade-lucide-icons/resources/svg/{name}.svg` before use — never guessed.
10. `git diff --stat` against Slice 6's own authorized post-remediation baseline, scoped exactly per §7.5's pinned-SHA rule: every remediation-changed path outside the 4-item §9 view allowlist (routes/controllers/models/etc.) must be completely empty; a remediation-changed path that is inside the 4-item allowlist (none are expected, since the remediation's own scope is controller/route/model-level, not Blade-view-level, per §7.1–§7.4's findings) is expected to carry Slice 6's own additional presentation diff on top, and is not subject to this empty-diff check.
11. The security-remediation's own pinned test file(s) run and pass with zero failures before §9 items 5–7 run.
12. Final changed-path set equals §9's exact, sequential 1–7 allowlist.
13. `php artisan test` full-suite pass count compared against the pre-Slice-6 baseline, reported exactly, never estimated.

---

## 14. Stop conditions

- **Slice 6 implementation must not begin at all unless §7's full prerequisite is satisfied** (remediation merged, SHA pinned, tenant-isolation and permission-bypass gaps closed, the stored-XSS-shaped finding resolved).
- If the post-remediation tree changes which 4 views are correct, or requires a path beyond §9's 7-item allowlist, STOP and re-audit.
- Any path beyond §9's 7-item allowlist — the **8th** path.
- Any change to `app/`, `database/`, or `routes/` for any reason within Slice 6's own presentation-implementation scope (as opposed to the separately-tracked §7 remediation).
- Any of the 13 flagged static icon names lacks a confirmed Lucide equivalent.
- Any existing test fails for a reason not fixable within this slice's own allowlist.
- Any route/controller logic, AJAX/form target, CSRF handling, or the two Echo/Pusher guards change as a side effect of restyling.
- Any of the 3 message-template JS construction paths is unified, extracted, or reduced in count without direct current-source evidence proving it can be done without changing Blade/runtime semantics (§3.6) — none was found; none is authorized.
- `feather.replace()` or the Feather runtime is removed or altered.
- `<x-badge>`, `<x-alert>`, or `<x-empty-state>` is adopted for the notification counter, the `alert-info` block, or the `.start-chat-area` empty-state respectively (§5.5–§5.7).
- `<x-input>`/`<x-select>` is adopted for any Select2-controlled, `.input-group`-nested, hidden, or no-matching-type (textarea/file) control (§5.3).
- A new production Blade component is created to represent the chat-list/message-bubble structures (§5.9) — not authorized by this contract.
- Any shared component (`card`, `button`, `tooltip`, `badge`, `alert`, `empty-state`, `input`, `select`) is extended or modified to make a non-adopted surface fit.
- Slice 1's already-merged chat-background/token work (§3.12) is reopened, re-touched, or duplicated.
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is found necessary to reference.
- A genuine business-logic change is found necessary to make any of the 4 views render or behave correctly.

---

## 15. Contract self-audit

1. Full current ChatBox inventory mechanically re-derived from direct repository inspection, not trusted from the rollout map — confirmed to match the historical 4-file figure exactly, independently. ✓
2. Every component-adoption decision in §5 is classified as exactly one of A/B/C/D and justified against the actual, directly-read current component source (§3.2) and actual current markup (§3.3–§3.10) — no generic "every X adopts" statement appears anywhere in §5. ✓
3. Static icon migration (§3.3) is explicitly separated from runtime `feather.icons[...]`/`feather.replace()` calls (§3.3, §6) — never conflated. ✓
4. A real, mechanically-evidenced, severe security defect (cross-tenant authorization gap + permission-bypass gap + stored-XSS-shaped finding, §7) was found and is treated as a hard blocker, following the Slice 5 precedent exactly — not invented without evidence, and not silently omitted. ✓
5. The Slice-1 chat-background remediation's already-merged status is mechanically confirmed (§3.12), and this contract does not reopen, re-authorize, or duplicate it anywhere. ✓
6. The future implementation allowlist (§9) is mechanically derived from §3–§8, not assumed to equal the historical 4-file figure by itself — it correctly totals 4 production views + 3 new tests = 7, with an honest, explicit accounting (§5.9) of why "full componentization" does not mean every visual surface adopts a component. ✓
7. `docs/automation/AI-AUTONOMY-STATE.json` untouched. ✓
8. Slice 4 remains explicitly skipped; no Slice 7a/Campaigns/other-initiative work is authorized or implied anywhere. ✓
9. No implementation authorization is granted anywhere in this document. ✓
10. This document remains the only file changed on this branch (§2). ✓
11. Plugin-chrome retrofit question (M2 §6.11 standing mandate) explicitly audited and closed with a mechanical, negative finding (§3.4) — not silently skipped. ✓

---

## 16. Verification and publication

1. `git diff --check` — clean.
2. `git status --short` — exactly `?? docs/automation/DESIGN-SYSTEM-M2-SLICE-6-CONTRACT.md`.
3. `git diff --cached --name-only` — empty before staging.
4. Stage the one file by its exact path only (never `git add -A`/`.`).
5. Commit exactly: `docs: define Design System M2 Slice 6 contract`.
6. Push to `origin chore/design-system-m2-slice6-chatbox-conversations-contract` — normal push, never forced.
7. If `gh` is available, open a **draft** PR into `main`. If `gh` is unavailable, report the exact GitHub comparison URL instead.
8. **Do not merge. Do not begin Slice 6 implementation. Do not begin the ChatBox security remediation. Do not begin Slice 4, Slice 7a/Campaigns, or any other slice/initiative.** No test is run for this docs-only change.

---

*End of Design System M2 Slice 6 Contract (ChatBox / Conversations), first drafting pass. Implementation requires a separate, explicit human instruction. This contract's own merge does not start or resume it. Slice 6 implementation is blocked until the separate ChatBox tenant-isolation and permission-bypass security remediation named in §7 is complete — contracted, human-merged, implemented, and human-merged, with both the ownership-scoping and permission-check gaps closed and the stored-XSS-shaped finding resolved — and its exact merge SHA is pinned in Slice 6's own later, separate implementation authorization.*
