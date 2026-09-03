# Design System M2 Slice 6 — ChatBox / Conversations Security Remediation Contract

**This document is fully self-contained.** No section below requires consulting the Slice 6 visual contract, the M2 contract, or any earlier commit to understand this remediation's complete rules — every finding, architecture decision, and path is restated here in full, independently re-verified against current source rather than copied from the Slice 6 contract's own prose.

**Status: contract only. No implementation has occurred under this document. Merging this contract does NOT authorize implementation. Slice 6's own visual/componentization implementation remains blocked until this remediation is itself contracted, human-merged, implemented, and human-merged — per the Slice 6 contract's own §7.**

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice6-chatbox-security-contract`, in an isolated linked worktree, based on `origin/main` at `c5320611a65b9fd97a7287542d4de11dd96822e0` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` before drafting began. This SHA is PR #180 ("Design System M2 Slice 6 — ChatBox / Conversations Contract", including its own Correction Round 1).
- This is a **first drafting pass**. `maximum_correction_rounds: 2`, `correction_round: 0`, unconsumed.
- The merged `docs/automation/DESIGN-SYSTEM-M2-SLICE-6-CONTRACT.md` — including its Correction Round 1, §3.14 (chat identifier audit) and §7 (authorization gap) most directly — is binding context. Every finding it states is independently re-verified against current source in §3 below, not copied blindly; where this document's own re-audit found additional precision (the exact 3 pre-existing tests that hardcode `ChatBox.id` for `reply()`, the exact `quickSend(..., true)` source-count constraint, the exact `Contacts.customer_id` tenant column), it is stated explicitly.
- This contract authorizes **only** drafting this one document. It does not authorize implementation of this remediation, Slice 6's own visual/componentization work, Slice 4, Slice 7a/Campaigns, or any other slice/initiative, and makes zero change to `docs/automation/AI-AUTONOMY-STATE.json`.
- `docs_only: true`. `implementation_has_occurred: false`. `merge_authorizes_implementation: false`. `implementation_requires_separate_human_authorization: true`. `advance_automatically: false`. `start_automatically_after_contract_merge: false`. `merge_authority: human_only`.
- **Slice 6's own visual implementation remains blocked** (`slice_6_visual_status: blocked_until_security_implementation_human_merged`) until this remediation's own implementation is human-merged and its exact merge SHA is pinned in Slice 6's later, separate implementation authorization — per the Slice 6 contract's own §7.5.

---

## 1. Mandatory preflight — verified

1. `git fetch origin --tags` — `origin/main` confirmed at exactly `c5320611a65b9fd97a7287542d4de11dd96822e0`.
2. Starting branch HEAD confirmed at the same SHA (fresh worktree, clean checkout, `git status --short` empty before any edit).
3. Read in full before drafting: `CLAUDE.md`, `AGENTS.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-SLICE-6-CONTRACT.md` (516 lines, including Correction Round 1).
4. Directly re-read, in full, at this drafting pass, independently of the Slice 6 contract's own prose: `app/Http/Controllers/Customer/ChatBoxController.php` (694 lines), `app/Models/ChatBox.php`, `app/Models/ChatBoxMessage.php`, `app/Models/Contacts.php`, `app/Library/Traits/HasUid.php`, `routes/customer.php`'s `chat-box` route group and its wrapping `RouteServiceProvider` middleware stack, `config/customer-permissions.php`'s `chat_box` entry, `resources/views/customer/ChatBox/index.blade.php`, `resources/views/customer/ChatBox/_sidebar.blade.php`, `resources/views/customer/ChatBox/partials/_chat_list.blade.php`, `resources/views/customer/ChatBox/new.blade.php`.
5. Searched the full test suite for existing ChatBox coverage: `find tests -iname "*chatbox*"` returns **zero files** — no dedicated ChatBox test file of any kind exists today (confirmed, not assumed; mirrors the Contacts/ContactGroups/Blacklists "zero existing test coverage" finding the Slice 5 CRM security remediation made before this same engagement). A repository-wide content search (`grep -rl "ChatBox\|chat-box\|chatbox" tests/`) found ChatBox referenced in 7 unrelated Dashboard/Security test files plus, critically, two RFC-005 Milestone 5 billing/idempotency test files that directly exercise `ChatBox`/`chatbox.reply` — read in full at this pass (§3.9): `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` (1,387 lines) and `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php` (306 lines). No `ChatBox` model factory exists in `database/factories/`.

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**: `docs/automation/DESIGN-SYSTEM-M2-SLICE-6-CHATBOX-SECURITY-REMEDIATION-CONTRACT.md` (this document). No `resources/`, `app/`, `database/`, `routes/`, `tests/`, or `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting this contract.

---

## 3. Mandatory security audit — findings, independently re-verified

### 3.1 Controller action inventory — mechanically re-confirmed

`ChatBoxController` (694 lines) exposes exactly 10 public actions, matching the Slice 6 contract's own count exactly, re-confirmed by direct read: `index`, `new`, `sent`, `messages`, `messagesWithNotification`, `reply`, `delete`, `block`, `loadChatUsers`, `pin`.

### 3.2 Finding 1 — single-record ChatBox tenant isolation, re-verified

Six actions resolve a specific `ChatBox` record with **zero ownership predicate** against `Auth::id()`:

| Action | Current resolution | Current denial on non-match |
|---|---|---|
| `messages($id)` | `\DB::table('chat_boxes')->where('id', $id)->first()` — raw query builder, no model, `id`-column | `{'status':'error','data':[],'pinned':0}`, **HTTP 200** (no status code set) |
| `messagesWithNotification(ChatBox $box)` | implicit route-model binding, `uid`-column (`HasUid::getRouteKeyName()`) | Laravel's own default 404 (unhandled `ModelNotFoundException`) |
| `reply($id, ...)` | `ChatBox::find($id)` — Eloquent primary-key (`id`-column) lookup | `{'status':'error','message':'Chat box not found. Refresh page.'}`, **HTTP 404** (explicit) |
| `delete(ChatBox $box)` | implicit route-model binding, `uid`-column | Laravel's own default 404 |
| `block(ChatBox $box)` | implicit route-model binding, `uid`-column | Laravel's own default 404 |
| `pin(ChatBox $box)` | implicit route-model binding, `uid`-column | Laravel's own default 404 |

`ChatBox` (`app/Models/ChatBox.php`) carries no global scope of any kind — re-confirmed by direct read, unchanged from the Slice 6 contract's own finding. `index()` and `loadChatUsers()` both correctly scope by `ChatBox::where('user_id', Auth::id())` — re-confirmed, unchanged.

**Correction to the Slice 6 contract's own denial-behavior claim, made here for precision:** the Slice 6 contract characterized all six actions as uniformly denying "identically" once fixed, without noting that their *current* denial shapes already differ from each other (`messages()`'s 200-with-error-body vs. `reply()`'s explicit 404 vs. the four route-model-bound actions' implicit Laravel-framework 404). §5 below locks an explicit, per-action denial shape — identical between foreign and nonexistent *within* each action, not necessarily identical *across* the six different actions, matching this task's own "document exact expected denial behavior for each route family" instruction.

### 3.3 Finding 2 — mixed id/uid boundary, independently re-confirmed

Direct re-read of the exact lines:
- `_sidebar.blade.php` line 51 (pinned list): `<li data-id="{{$chat->uid}}" data-box-id="{{$chat->id}}">` — `data-id` carries `uid`.
- `partials/_chat_list.blade.php` line 2 (AJAX-loaded unpinned list): `<li data-id="{{$chat->id}}" data-box-id="{{$chat->id}}">` — `data-id` carries numeric `id`.
- `index.blade.php` line 298: `const chat_id = $(this).data("id");` — the shared click handler, consuming whichever value the clicked `<li>` supplied, feeding it into the `messages` AJAX call (line 305) and into the `.chat_id` hidden field (line 311) that `reply`/`delete`/`block`/`pin` read back from (lines 437, 556, 632, 708).

Server-side resolution confirmed split in the opposite direction (§3.2's table): `messages()`/`reply()` are `id`-column lookups; `messagesWithNotification()`/`delete()`/`block()`/`pin()` are `uid`-column lookups (via `getRouteKeyName()`). This is the identical split the Slice 6 contract's own §3.14 documents — independently re-derived here from the same source lines, not copied.

### 3.4 Finding 3 — ownership resolution architecture, decided

**`uid` is locked as the sole canonical external ChatBox identifier.** Reasoning, stated exactly:
1. `ChatBox` already declares `getRouteKeyName() = 'uid'` (`HasUid` trait) — the model's own architecture already nominates `uid` as its external-facing identifier.
2. Every other `HasUid`-using model exercised by this same engagement's prior security remediations (`ContactGroups`, `Contacts`, `Blacklists`) is routed exclusively by `uid` throughout its own controllers and tests — `ChatBox`'s partial use of raw numeric `id` (in `_chat_list.blade.php` and in `messages()`/`reply()`'s own resolution) is the anomaly relative to this codebase's own established convention, not a competing standard worth preserving.
3. Exposing a raw, sequential autoincrement primary key in URLs/JS (`messages($id)`/`reply($id)`'s current shape) is itself a minor enumeration-hygiene weakness independent of the ownership gap — `uid` (a `uniqid()` value) is not a security boundary either, but is consistent with the rest of the application's own external-identifier convention and is not sequentially enumerable in the same trivial way.

**Ownership resolver, locked exactly:** a new private helper on `ChatBoxController`,
```php
private function resolveOwnedChatBox(string $uid): ?ChatBox
{
    return ChatBox::where('uid', $uid)->where('user_id', Auth::id())->first();
}
```
— a single query combining existence and ownership in one predicate, so a foreign `uid` and a nonexistent `uid` produce the identical `null` result and therefore the identical denial response (§3.2, §5) with no separate "exists but not owned" branch to accidentally shape differently. **Implicit Laravel route-model binding is explicitly abandoned for all six single-record actions** — chosen over leaving it in place, because implicit binding resolves *before* the controller body runs and has no ownership predicate of its own: a foreign `uid` would bind successfully (revealing existence) while a nonexistent `uid` throws Laravel's own framework-level `ModelNotFoundException` (a different code path, risking a subtly different response shape/timing than a hand-written denial) — the explicit resolver removes this risk entirely by making both cases the same `null` value on the same query, checked in the same place, in the same action, every time.

**Route-file impact: none.** `routes/customer.php`'s existing `{box}` route segments require no change — implicit model binding is controlled entirely by the *controller method's own parameter type-hint* (`ChatBox $box` vs. a plain `string $box`), not by the route definition itself. Changing the four route-model-bound actions' signatures from `ChatBox $box` to `string $box` (then resolving via `resolveOwnedChatBox($box)` inside the method body) requires zero route-file edits. `routes/customer.php` is **not** added to §9's allowlist.

**Model impact: none.** `ChatBox.php`, `HasUid.php`, and `Contacts.php` already expose every column this remediation needs (`uid`, `user_id`, `customer_id`) — none is added to §9's allowlist merely for having been audited (per this task's own explicit instruction). No new global scope is introduced: `index()`/`loadChatUsers()` already prove `->where('user_id', Auth::id())` is sufficient at the query-builder level for every other ChatBox consumer in this controller, and a global scope would risk silently affecting `\DB::table('chat_boxes')` raw-query callers (none currently exist outside `messages()`, which this remediation itself corrects to use the Eloquent resolver instead of the raw query builder) or any future consumer in a way this audit cannot fully enumerate — the explicit, local resolver is the narrower, safer, equally effective choice.

### 3.5 Finding 4 — `chat_box` permission enforcement, re-verified exactly

Direct re-read confirms the exact split the Slice 6 contract found: `index()` (line 55), `new()` (line 107), `sent()` (line 163), `reply()` (line 403) call `$this->authorize('chat_box')`. `messages()`, `messagesWithNotification()`, `delete()`, `block()`, `loadChatUsers()`, `pin()` call no authorization check of any kind — six actions, independently re-confirmed by direct read of each method body, matching the Slice 6 contract's own count exactly.

`chat_box` (`config/customer-permissions.php` lines 268–272: `'chat_box' => ['display_name' => 'chat_box', 'category' => 'Chat Box', 'default' => true]`) is a real, registered, `default: true` permission — re-confirmed. `routes/customer.php` is wrapped by `Route::middleware(['web', 'auth', 'can:access_backend', 'ValidProduct', 'twofactor'])->...->group(base_path('routes/customer.php'))` (`RouteServiceProvider.php` lines 77–80) — the blanket `access_backend` ability gates the whole customer portal; `chat_box` is the finer-grained, feature-specific permission this remediation restores uniformly.

**`reply()`'s existing ordering, re-verified:** `reply()` currently resolves `$box = ChatBox::find($id)` (line 388) **before** `$this->authorize('chat_box')` (line 403) — authorization runs *after* resolution today. This remediation reverses that ordering for every one of the six actions (§3.4, §5): `$this->authorize('chat_box')` runs **first**, then `resolveOwnedChatBox()` runs **second** — so an actor lacking the `chat_box` permission is denied by the permission check alone and never learns, from response shape or timing, whether the supplied identifier exists, is foreign, or is genuinely invalid.

### 3.6 Finding 5 — `block()`'s `Contacts` tenant-scope defect, re-verified exactly

Direct re-read of `block(ChatBox $box)` (lines 607–632): two distinct mutations.
- `Blacklists::create(['user_id' => auth()->user()->id, 'number' => $box->to, 'reason' => ...])` — **correctly** attributed to the acting customer's own `user_id`.
- `Contacts::where('phone', $box->to)->first(); $contact?->update(['status' => 'unsubscribe']);` — carries **no** tenant predicate.

`app/Models/Contacts.php` re-read directly: `protected $table = 'contacts'`, `protected $fillable = ['customer_id', 'group_id', 'phone', 'status', ...]`. **`customer_id` is the exact tenant column** (verified, not assumed, per this task's own instruction) — and a direct grep of `Customer/ContactsController.php`'s own existing query patterns (`ContactGroups::where('customer_id', Auth::id())`, repeated at 8+ call sites) confirms `customer_id` on this codebase's Contacts-adjacent tables stores the same `Auth::id()`/`users.id` value `ChatBox.user_id` already uses — the same id-space, not a separate `Customer`-table foreign key. This remediation adds `->where('customer_id', Auth::id())` to the `Contacts` lookup — independent of, and in addition to, `ChatBox` ownership scoping, since two different tenants can legitimately hold separate `Contacts` rows sharing the same phone number, and fixing `ChatBox` ownership alone does not scope this second, independent query.

### 3.7 Finding 6 — message-content XSS, current path count independently re-confirmed

Direct re-read of `index.blade.php`'s inline `<script>` block, current `main`, confirms **exactly 3** message-bubble construction paths, matching the Slice 6 contract's own §3.6 count exactly:
1. **History load** — the `$.post('.../messages')`'s `.done()` handler, template literal, `` `<p>${sms.message}</p>` ``.
2. **Optimistic send** — `enter_chat()`'s AJAX success handler, string concatenation, `"<p>" + messageValue + "</p>"`.
3. **Echo listener** — `Echo.private("chat").listen("MessageReceived", ...)`, template literal, `` `<p>${sms.message}</p>` `` (both branches, incoming and outgoing).

All three interpolate message text into raw HTML with no escaping — re-confirmed exactly. This remediation is explicitly authorized (per the Slice 6 contract's own Correction F, §7.3) to change this logic.

### 3.8 Finding 7 — `media_url` attribute safety, current shape re-confirmed

All 3 paths above also interpolate `sms.media_url`/`response.media_url` into `src="${...}"` template-literal/concatenated HTML attribute strings, for `<img>`/`<video>`/`<audio>` tags selected via the existing `isImageOrVideo(url)` helper (extension-based branching, unchanged by this remediation). No attribute-breakout exploit was proven, but none was proven absent either — server-generated paths from `Tool::uploadImage()` were not verified immune to containing a literal `"` character. This remediation resolves the question definitively via the mechanism locked in §5.7, rather than continuing to assume safety.

### 3.9 RFC-005 Milestone 5 billing/idempotency preservation audit — read in full

**`tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` (1,387 lines), read in full.** Confirms the exact `idempotency_token` contract this remediation must not alter: `test_reply_missing_token_returns_422_and_never_reaches_quicksend()`, `test_reply_invalid_token_returns_422_and_never_reaches_quicksend()`, `test_reply_valid_token_passes_trusted_conversation_context_through_to_quicksend()`, `test_sent_retain_redirect_preserves_token_and_restores_form_input()` — the fail-closed 422 behavior for a missing/invalid `idempotency_token` (before `quickSend()` is ever reached), the `m5_token_action` clear/retain classification, and `new()`'s `?m5_retry_token=` reuse logic are all read and must remain byte-identical.

**Critical, mechanically-discovered dependency, not present in the Slice 6 contract's own audit:** three of these tests call `route('customer.chatbox.reply', $box->id)` — the **numeric primary key**, not `$box->uid` — at lines 1283, 1302, and 1334. Under the current, pre-remediation `reply($id) { ChatBox::find($id); ... }` resolution, this works because `find()` resolves by primary key. **Once this remediation changes `reply()` to resolve exclusively via `resolveOwnedChatBox(string $uid)` (§3.4, §5), these three exact call sites will no longer resolve a real box** — the numeric `id` string will not match any `uid` column value, and each test will receive the new uniform denial response instead of its currently-asserted `422`/`200` outcome. **This is a real, necessary, narrowly-scoped existing-test modification this remediation's own implementation must make** (§9) — not incidental scope creep, but a direct, unavoidable consequence of intentionally changing the identifier contract that this pre-existing test hardcodes. The fix is mechanical and minimal: change `$box->id` to `$box->uid` at these exact three call sites; no other line in this 1,387-line file requires any change.

**`tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php` (306 lines), read in full.** Contains a source-level mechanical assertion this remediation must not break: `test_chatbox_controller_is_the_only_caller_passing_true()` (line 106) reads `ChatBoxController.php`'s raw file contents and asserts `preg_match_all('/->quickSend\([^)]*,\s*true\s*\)/', $contents)` equals **exactly 2** — the two existing `$this->campaigns->quickSend($campaign, $input, true);` call sites, in `sent()` and `reply()`. **This remediation's own refactor of `reply()` (adding the authorize-then-resolve ordering, §3.5/§5) must preserve both call sites' exact source text unchanged** — the count must remain exactly 2 after this remediation, verified mechanically (§10, mechanical search item 9). This file itself requires no modification.

**Billing/idempotency-relevant conclusion:** this remediation changes only *where in the request lifecycle* a foreign/nonexistent `ChatBox` is rejected (before any `quickSend()`/provider/billing work is reached, §5.7) — it does not alter the `idempotency_token` requirement, its generation/lifecycle, reservation behavior, `m5_token_action` classification, or provider-send semantics for an *owned* box in any way. `reply()`'s own idempotency-token validation block (lines 442–447) executes unchanged, in the same relative position within the method (after the new authorize+resolve step, which now runs first).

### 3.10 Non-blocking existing correctness issue — re-audited, not repaired

The Slice 6 contract's own Correction I finding — optimistic attachment rendering always emits `<img>` while the `media_image` upload input and `reply()`'s own server-side validation (`'media_image' => '...|mimes:mp4,mov,ogg,qt,jpeg,png,jpg,gif,bmp,webp|...'`) both genuinely accept video — is re-confirmed by direct read, unchanged. **This remediation does not fix it.** §5.7's safe-media-construction mechanism is applied uniformly to whatever element type each of the 3 paths already selects (via the existing `isImageOrVideo()` branching in paths 1 and 3, and the existing unconditional `<img>` choice in path 2) — the *safety* of setting `src` changes; the *choice of tag* does not. Widening this remediation's scope to also fix the optimistic-send video-tag mismatch is explicitly out of scope here, named only for completeness (§0's own governance boundary).

---

## 4. Locked remediation scope

- Exactly the paths enumerated in §9 — a closed, numbered, mechanically-derived allowlist.
- No product-behavior change beyond what §5 locks explicitly.
- No new permission string (`chat_box` already exists and is reused, §3.5).
- No new database migration, no new model, no new global scope (§3.4).
- No route-file change (§3.4).
- No change to Slice 1's already-merged token/background work, and no Slice 6 *visual*/componentization work of any kind (icons, `<x-button>`/`<x-card>`/`<x-tooltip>` adoption, hardcoded-color elimination) — that remains Slice 6's own, separately-authorized, still-blocked future scope.
- No other path.

---

## 5. Locked security architecture — exact code semantics

### 5.1 Canonical ChatBox external identifier

**`uid` (string), exclusively**, for every client-visible reference to a `ChatBox` record: list markup `data-id` attributes, the shared click handler's `chat_id` value, the hidden `.chat_id` field, and the `{box}` route segment consumed by all six single-record controller actions. The numeric primary key (`id`) remains internal database linkage only (foreign keys, `ChatBoxMessage.box_id`, etc.) and is never again placed in client-visible markup or accepted as a client-supplied identifier for ownership-scoped resolution.

### 5.2 Ownership-resolution method

A single new private helper on `ChatBoxController`:
```php
private function resolveOwnedChatBox(string $uid): ?ChatBox
{
    return ChatBox::where('uid', $uid)->where('user_id', Auth::id())->first();
}
```
Called by every one of the six single-record actions (§5.3), always **after** `$this->authorize('chat_box')` (§5.5) has already passed, always producing the identical, action-specific denial response (§5.3's table) when it returns `null` — whether the `uid` belongs to another tenant or does not exist at all.

### 5.3 The exact six single-record actions affected, and their exact denial response

| Action | New signature | New resolution | Denial (identical for foreign vs. nonexistent) |
|---|---|---|---|
| `messages(string $uid)` | `string $uid` (was `$id`, untyped) | `$box = $this->resolveOwnedChatBox($uid);` then, if `$box`, `ChatBoxMessage::where('box_id', $box->id)->orderBy('created_at', 'asc')->get()` (replaces the raw `\DB::table` calls entirely) | `response()->json(['status' => 'error', 'data' => [], 'pinned' => 0])` — **HTTP 200**, preserving this action's own existing success/failure JSON shape exactly |
| `messagesWithNotification(string $uid)` | `string $uid` (was `ChatBox $box`) | `$box = $this->resolveOwnedChatBox($uid);` | `response()->json(['status' => 'error', 'message' => 'Chat box not found.'], 404)` |
| `reply(string $uid, Campaigns $campaign, Request $request)` | `string $uid` (was `$id`) | `$box = $this->resolveOwnedChatBox($uid);` | `response()->json(['status' => 'error', 'message' => 'Chat box not found. Refresh page.'], 404)` — this action's own existing string and status code, now also returned for a foreign `uid`, not only a nonexistent one |
| `delete(string $uid)` | `string $uid` (was `ChatBox $box`) | `$box = $this->resolveOwnedChatBox($uid);` | `response()->json(['status' => 'error', 'message' => 'Chat box not found.'], 404)` |
| `block(string $uid)` | `string $uid` (was `ChatBox $box`) | `$box = $this->resolveOwnedChatBox($uid);` | `response()->json(['status' => 'error', 'message' => 'Chat box not found.'], 404)` |
| `pin(string $uid)` | `string $uid` (was `ChatBox $box`) | `$box = $this->resolveOwnedChatBox($uid);` | `response()->json(['status' => 'error', 'message' => 'Chat box not found.'], 404)` |

Every other line of business logic in each of these six methods — the message list shape, `messagesWithNotification`'s `notification` count, `reply()`'s entire spam-check/sending-server/sender-id/`quickSend()` chain (§5.6), `delete()`'s message-then-box deletion, `block()`'s `Blacklists`/`Contacts` writes (§5.4), `pin()`'s toggle — is unchanged, operating on the now-owned `$box` exactly as it does today.

### 5.4 `block()`'s `Contacts` tenant predicate

```php
$contact = Contacts::where('phone', $box->to)->where('customer_id', Auth::id())->first();
$contact?->update(['status' => 'unsubscribe']);
```
The `Blacklists::create([...])` call immediately above it, already correctly attributed to `auth()->user()->id`, is unchanged.

### 5.5 Authorization-before-resolution ordering

Every one of the six actions (§5.3) begins:
```php
$this->authorize('chat_box');
$box = $this->resolveOwnedChatBox($uid);
if (! $box) {
    return response()->json([...denial per §5.3...]);
}
```
`reply()` specifically moves its existing `$this->authorize('chat_box')` call (currently after resolution, §3.5) to before `resolveOwnedChatBox()` — reversing its current order. An actor lacking the `chat_box` permission is denied by Laravel's own `authorize()` (a `403`, via the framework's standard `AuthorizationException` handling, unchanged from how `index()`/`new()`/`sent()` already behave) before `resolveOwnedChatBox()` ever runs, and therefore before the response can reveal, by shape or timing, whether the supplied `uid` exists, is foreign, or is invalid.

### 5.6 Preserved exactly — RFC-005 Milestone 5 billing/idempotency behavior

`reply()`'s existing idempotency-token validation block (the `filled('idempotency_token')`/`Str::isUuid(...)` fail-closed 422 check), its spam-word check, sending-server resolution, sender-id verification, and its two `$this->campaigns->quickSend($campaign, $input, true)` call sites (one here, one in `sent()`) are **unchanged in logic, order relative to each other, and exact source text** — only the identifier-resolution and authorization-ordering step immediately preceding them changes. **Preserved as a direct consequence of §5.5's ordering**: a request against a foreign or nonexistent `ChatBox` is rejected by §5.3's denial response before `reply()` reaches the idempotency-token check, the spam check, or `quickSend()` — no foreign-thread send, billing reservation, or provider call can occur, closing the billing/spoofing vector named in the Slice 6 contract's own §7.1(b) finding structurally, not by adding a separate guard.

### 5.7 Safe message-content rendering mechanism

**Exact mechanism, no ambiguity left to implementation:** replace every raw-HTML template-literal/string-concatenation bubble construction in all 3 paths (§3.7) with DOM-construction using jQuery's `.text()` for message content and native/jQuery element creation (not string-built HTML) for the surrounding structure. Concretely, for each of the 3 paths:

```js
function buildChatBubble(sms, isIncoming) {
  const $chat = $('<div class="chat"></div>');
  if (isIncoming) $chat.addClass('chat-left');

  const $avatarWrap = $('<div class="chat-avatar"></div>');
  const $avatarImg = $('<img alt="avatar" height="36" width="36">');
  $avatarImg.attr('src', /* existing avatar URL logic, unchanged per path */);
  $avatarWrap.append($('<span class="avatar box-shadow-1 cursor-pointer"></span>').append($avatarImg));

  const $body = $('<div class="chat-body"></div>');
  const $content = $('<div class="chat-content"></div>');

  if (sms.media_url) {
    $content.append(buildMediaElement(sms.media_url)); // §5.8
  }
  if (sms.message) {
    $content.append($('<p></p>').text(sms.message)); // .text() — never .html()/string concatenation
  }
  $content.append($('<p class="chat-time text-muted mt-1"></p>').text(sms.created_at));

  $body.append($content);
  $chat.append($avatarWrap, $body);
  return $chat;
}
```

`buildChatBubble()` (or an equivalently-named single shared function, defined once in `index.blade.php`'s own existing inline `<script>` block — **not** a new external JS file, since this remediation does not introduce a new Blade→JS hydration seam beyond what already exists) replaces the per-path template-literal/concatenation logic in all 3 paths named in §3.7, parameterized only by the per-path differences already present today (which avatar URL to use, whether `isIncoming` applies at all for the optimistic-send path, which value is already known synchronously vs. arrived via AJAX/Echo). **This satisfies every one of the following, mechanically, by construction — never by convention or reviewer discipline:**
- `sms.message` containing literal text such as `<script>alert(1)</script>` renders as visible text, because `.text()` sets the DOM `textContent` property, which the browser never parses as markup, regardless of content.
- `<img onerror=...>`-shaped text similarly displays as text.
- Ordinary quotes, ampersands, angle brackets, Unicode, and newlines in legitimate SMS text remain fully readable, since `.text()` performs no character-level filtering — it only controls *how* the browser interprets the string (as text content, never as markup), not *which* characters are permitted.
- `.chat-time`, `.chat-left`, avatar rendering, and append/scroll behavior are unchanged — only *how* the DOM nodes are built changes, not the resulting DOM structure, class names, or their consumers (§6).

**This does not require preserving the current 3-separate-copy structure.** Per the Slice 6 contract's own Correction F, this remediation may introduce this one shared helper in place of 2 or 3 of the original 3 sites, provided each call site still supplies its own path-specific values (avatar URL selection, `isIncoming` computation, whether the box is the currently-active conversation) unchanged from current behavior.

### 5.8 Safe `media_url` attribute mechanism

**Exact mechanism:** never concatenate a media URL into an HTML string. Construct the element via jQuery and assign the URL through the attribute API:
```js
function buildMediaElement(url) {
  const type = isImageOrVideo(url); // existing helper, unchanged
  if (type === 'video') {
    return $('<video controls>Your browser does not support the video tag.</video>').attr('src', url);
  }
  if (type === 'audio') {
    return $('<audio controls>Your browser does not support the audio element.</audio>').attr('src', url);
  }
  return $('<img alt="media">').attr('src', url);
}
```
`.attr('src', url)` assigns the string as an attribute *value*, never as HTML to be parsed — a `"` character (or any other character) inside `url` cannot break out of the attribute context, because no HTML string containing the URL is ever constructed or parsed. This preserves the existing `isImageOrVideo()`-driven image/video/audio branching exactly (§3.10) — only the construction mechanism changes, not which tag is chosen for which file type, and not the pre-existing optimistic-send-always-`<img>` inconsistency (§3.10, deliberately not repaired here).

### 5.9 Explicitly preserved behavior (unchanged by this remediation)

- `index()`'s and `loadChatUsers()`'s existing `ChatBox::where('user_id', Auth::id())` query scoping (§3.2) — untouched, not loosened, not rewritten.
- `loadChatUsers()`'s filter/search/pagination logic — untouched; it gains only the missing `$this->authorize('chat_box')` call (§5.5), placed as its first statement.
- Both Echo/Pusher conditional guards (`@if(config('broadcasting.connections.pusher.app_id'))`) — untouched.
- The `chat-box` route group's exact 10 route names and URL structure (§3.4 — zero route-file change).
- Every element `id`/`name`/class-based JS selector not directly implicated in §5.7/§5.8 (`.tab-button`, `.send`, `.message`, `.counter`, `.notification_count`, `.active`, `.add-to-blacklist`, `.remove-btn`, `.start-chat-area`, `.active-chat`, `#chat-search`, `#load-more`).
- The composer/attachment/SMS-template-picker structure, Select2 wiring, and all 3 SweetAlert2 confirm-flow configurations.
- `sent()` and `new()` — neither is named among the six single-record actions (§3.2); both already call `$this->authorize('chat_box')` and neither resolves a `ChatBox` by client-supplied identifier. Unchanged.
- The exact 2 `->quickSend(..., true)` call sites' own source text (§3.9) — preserved verbatim, mechanically verified (§10 item 9).
- `ChatBoxMessage::booted()`'s inbound-message mirroring into `cg_ai_conversations`/`cg_ai_messages` (an unrelated AI-copilot side effect, out of this remediation's scope entirely) — untouched.

---

## 6. Preserve all behavior

Every item in §5.9, plus: no controller action's HTTP method, route name, or URL structure changes; no response field is renamed or removed from any of the six actions' *success* path (only the *denial* path is newly added or standardized, per §5.3); no `@can`/`@canany` Blade directive exists in the 4 ChatBox views to preserve (none does, confirmed by the Slice 6 contract's own audit, unaffected by this remediation which touches no Blade view's authorization-relevant markup); every localization key already present in `index.blade.php` remains present, since `buildChatBubble()`'s own static strings (`"Your browser does not support..."`) already exist verbatim in the current 3 paths and are only relocated, not reworded.

---

## 7. Test contract

### 7.1 New dedicated file

`tests/Feature/Security/ChatBoxSecurityTest.php` — mirrors this repository's own established convention (`tests/Feature/Security/{ContactsSecurityTest,ContactGroupsSecurityTest,BlacklistsSecurityTest}.php` from the Slice 5 CRM security remediation), since this pattern is directly supported and precedented, per this task's own instruction to prefer it "if repository conventions support it."

### 7.2 Exact behavioral coverage, at minimum

**A. Ownership** — for each of the six actions (§5.3):
- Tenant A's own `ChatBox` (created directly via `ChatBox::create([...'uid' auto-generated by `HasUid`...])`, no factory exists — §1 item 5) succeeds where the action has a meaningful success path reachable without full billing fixtures (`messages`, `messagesWithNotification`, `delete`, `block`, `pin`); `reply()`'s own success path is covered separately in **G** below, reusing the established `buildQualifyingQuickSendFixture()`-shaped pattern already proven in `ConversationsPlainSmsMeteringTest.php`.
- Tenant B's foreign `ChatBox` (a real `uid`, owned by a different `user_id`) is denied with the exact §5.3 response (status code + body) for that action.
- A syntactically-plausible but nonexistent `uid` (e.g., a freshly-generated `uniqid()` string matching no row) is denied with the **identical** status code + body as the foreign case, for that same action.
- Assert the foreign and nonexistent responses are byte-identical in status and JSON body (`assertSame($foreignResponse->status(), $nonexistentResponse->status())`, `assertSame($foreignResponse->getContent(), $nonexistentResponse->getContent())`) — the direct, mechanical proof of Finding 1's own requirement, not an inference from two separately-eyeballed assertions.

**B. Identifier consistency:**
- Render `customer.chatbox.index` (pinned list, when a pinned `ChatBox` exists) and assert the rendered `data-id` value equals the pinned chat's `uid`.
- Call `loadChatUsers()` (the AJAX-loaded unpinned list) and assert the rendered fragment's `data-id` value equals the returned chat's `uid` — proving §5.1's fix, since this is the exact view §3.3 found sending numeric `id` today.
- For `messages`, `reply`, `delete`, `block`, `pin`, and `messagesWithNotification`: assert each accepts the same real `uid` value consistently, and — the direct proof of Finding 2's own closure — assert that submitting the **numeric primary key** of a real, owned `ChatBox` (instead of its `uid`) to each of these six routes does **not** resolve that box (receives the same denial as a foreign/nonexistent `uid`), proving numeric-ID injection cannot bypass the canonical `uid` ownership boundary even for an actor's own real box.

**C. Permission:**
- An actor authenticated as a customer explicitly lacking `chat_box` (permissions array excludes it) is denied (`403`, via `authorize()`'s standard `AuthorizationException` handling) for `messages`, `messagesWithNotification`, `delete`, `block`, `loadChatUsers`, and `pin` — the six currently missing the check (§3.5).
- For `reply()` specifically: assert a `chat_box`-lacking actor is denied by permission alone even when supplying a **foreign** tenant's real `uid` — and assert the response is the standard `403` authorization denial, not the `404` ownership-denial shape (§5.3), proving the actor cannot distinguish "permission denied" from "identifier denied" and specifically cannot learn the foreign box's existence before authorization runs (§5.5's ordering, directly tested, not merely asserted in prose).

**D. `block()` Contacts scoping:**
- Create a `Contacts` row for tenant A and a separate `Contacts` row for tenant B, both with the identical `phone` value, both `status = 'subscribe'`.
- Tenant A blocks their own, owned `ChatBox` whose `to` matches that phone number.
- Assert tenant A's `Contacts` row is now `status = 'unsubscribe'`.
- Assert tenant B's `Contacts` row is byte/field-unchanged (`status` still `'subscribe'`, `updated_at` unchanged) — the direct mechanical proof of §5.4/Finding 5's own closure.
- Assert the `Blacklists` row created by the same request is attributed to tenant A's own `user_id` — preserving the already-correct behavior (§5.9), tested alongside the fix rather than assumed unaffected.

**E. XSS — honestly distinguished between HTTP/Blade tests and source/contract assertions (PHPUnit does not execute browser JS):**
- **Deterministic source-level assertions** (the only mechanically honest way to prove the JS safety seam from PHPUnit): the compiled/served `index.blade.php` response (or its raw source, whichever is more direct) contains the `buildChatBubble`/`buildMediaElement` function definitions (§5.7/§5.8) using `.text(` and `.attr('src'` — and **contains zero remaining instances** of the specific unsafe patterns named in §3.7 (`` `<p>${sms.message}</p>` ``, `"<p>" + messageValue + "</p>"`, and any other raw-HTML-string message interpolation) — proven by asserting their exact literal absence via string/regex assertions against the response body, not by rendering and hoping.
- **HTTP/Blade behavioral tests**, honestly scoped to what Laravel's test client can actually prove: that `index()` (with an active conversation/pinned chat present) renders `200` and its response body contains the safe-construction call sites named above; that no server-side output anywhere in the ChatBox response path (the Blade template itself, not the client-side JS it emits) ever echoes `sms.message`-equivalent content unescaped — since the actual vulnerable interpolation lives entirely in client-side JavaScript template literals that PHPUnit cannot execute, this test suite does **not** claim to prove the browser-rendered DOM is XSS-safe at runtime; it proves the unsafe source patterns are gone and the safe ones are present, which is the honest, achievable bar for a PHPUnit-only suite. This distinction is stated explicitly in the test file's own doc-comment, mirroring this section's own honesty requirement.

**F. `media_url` attribute safety:**
- Source-level assertion: the response contains `buildMediaElement`'s `.attr('src'` construction and contains zero remaining raw `src="${` /`src="` + string-concatenation patterns for `media_url`/`sms.media_url`/`response.media_url` in any of the 3 paths.

**G. Existing billing/idempotency preservation:**
- `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`'s three `chatbox.reply`-calling tests (§3.9) are updated (their sole required change, §9) to call `route('customer.chatbox.reply', $box->uid)` and re-run, asserting their existing outcomes (`422` for missing/invalid token, the qualifying-chain success proof for a valid token) are unchanged.
- A new test in `ChatBoxSecurityTest.php` (or reused directly from the updated metering test, whichever avoids duplication more cleanly at implementation time) proving a **foreign** `ChatBox`'s `reply()` request — even carrying a syntactically valid `idempotency_token` — is denied before any `business_usage_reservations` row or `reports` row is created (mirroring `test_reply_missing_token_returns_422_and_never_reaches_quicksend()`'s own existing assertion shape: `assertSame(0, DB::table('business_usage_reservations')->count())`), the direct proof of §5.6's own structural closure of the billing/spoofing vector.
- `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`'s existing `test_chatbox_controller_is_the_only_caller_passing_true()` is re-run unmodified and must still pass (exactly 2 matches) — proving this remediation's own refactor did not alter the two `quickSend(..., true)` call sites' source text (§3.9, §5.9).

### 7.3 Ordering requirement

`ChatBoxSecurityTest.php` runs before Slice 6's own three future Design System test files (per the Slice 6 contract's own §7.5/§8 ordering requirement) — not applicable to *this* contract's own scope directly, but restated here for the eventual implementation's own benefit.

---

## 8. Full regression contract

Future implementation must require, in order:

1. `tests/Feature/Security/ChatBoxSecurityTest.php` — the new dedicated focused suite (§7).
2. All pre-existing RFC-005/ChatBox tests relevant to reply/idempotency/billing — `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` (updated per §9) and `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php` (unmodified) — both re-run in full, zero failures.
3. Full `php artisan test` — **0 failed, 0 skipped, exit code 0**, exact pass count reported, never estimated.

**Established environment-only stabilization**, reused verbatim from this same engagement's prior phases, applied only if the local environment requires it (never expanding implementation scope):
```bash
# .env: APP_NAME="AI Business OS"
php artisan clear-compiled
php artisan package:discover --ansi
npx mix --production
php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan cache:clear
```
Generated `public/`/`bootstrap/cache/` artifacts must be restored to their committed state (tracked files reverted, untracked build leftovers removed) before commit — no environment artifact may expand implementation scope or appear in the final changed-path set.

---

## 9. Exact future implementation allowlist

**Mechanically derived from §3–§8 above.**

1. `app/Http/Controllers/Customer/ChatBoxController.php` — **required**: adds `resolveOwnedChatBox()` (§5.2); changes `messages`, `messagesWithNotification`, `reply`, `delete`, `block`, `pin` signatures from `ChatBox $box`/untyped `$id` to `string $uid` and inserts the authorize-then-resolve pattern (§5.3, §5.5); adds `->where('customer_id', Auth::id())` to `block()`'s `Contacts` query (§5.4); replaces `messages()`'s raw `\DB::table` calls with the Eloquent-based owned resolution (§5.3); adds `$this->authorize('chat_box')` as `loadChatUsers()`'s first statement (§5.5, §5.9). Preserves both `quickSend(..., true)` call sites' exact source text (§3.9, §5.9).
2. `resources/views/customer/ChatBox/index.blade.php` — **required**: replaces the 3 unsafe message-construction sites (§3.7) with the shared `buildChatBubble()`/`buildMediaElement()` mechanism (§5.7, §5.8); no other change.
3. `resources/views/customer/ChatBox/partials/_chat_list.blade.php` — **required**: changes `data-id="{{$chat->id}}"` to `data-id="{{$chat->uid}}"` (§5.1, §3.3) — the exact one-line fix closing the identifier split's client-side half; no other change.
4. `tests/Feature/Security/ChatBoxSecurityTest.php` — **required, new file**: the dedicated focused security suite (§7).
5. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` — **required, existing file, narrowly modified**: changes the 3 `route('customer.chatbox.reply', $box->id)` call sites (lines 1283, 1302, 1334 at this contract's own drafting-time head) to `route('customer.chatbox.reply', $box->uid)` (§3.9) — the sole existing-test modification this remediation's own identifier-contract change makes unavoidable; no other line in this file changes.

**Explicitly not included, with exact reasons:**
- `resources/views/customer/ChatBox/_sidebar.blade.php` — its pinned-list `data-id` already carries `uid` (§3.3); no change required.
- `resources/views/customer/ChatBox/new.blade.php` — resolves no `ChatBox` by client identifier at all (`sent()`/`new()` are not among the six single-record actions, §3.2); no change required.
- `routes/customer.php` — implicit-binding removal is controlled by controller method signatures alone (§3.4); no route-file change required.
- `app/Models/ChatBox.php`, `app/Library/Traits/HasUid.php`, `app/Models/Contacts.php` — already expose every column this remediation needs; audited, not modified (§3.4).
- `config/customer-permissions.php` — `chat_box` already exists; no new permission string (§3.5).
- `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php` — imposes a preservation constraint (§3.9) but requires no modification itself.

**Counts** — Production: **3** (items 1–3). Tests: **2** (items 4–5, one new + one narrowly modified existing file). **Overall total: 5. Stop threshold: 6th path.**

Any path beyond this 5-item allowlist required during future implementation is an immediate stop-and-report condition.

---

## 10. Mechanical searches (to be run at future implementation time)

1. `grep -c "resolveOwnedChatBox"` in `ChatBoxController.php` → present, exactly one method definition, called from all six actions named in §5.3.
2. For each of `messages`, `messagesWithNotification`, `reply`, `delete`, `block`, `pin`: confirm the method signature no longer type-hints `ChatBox $box` and no longer accepts an untyped/`$id`-named parameter without a `string` type — all six take `string $uid`.
3. `grep -c "ChatBox \$box"` in `ChatBoxController.php` → zero (confirms implicit route-model binding fully removed from all six actions; `index()`/`loadChatUsers()` never used it, unaffected).
4. `grep -c "DB::table('chat_boxes')"` in `ChatBoxController.php` → zero (confirms `messages()`'s raw-query resolution is replaced, §5.3).
5. `grep -n "authorize('chat_box')"` in `ChatBoxController.php` → exactly 10 occurrences (the 4 pre-existing plus the 6 newly added), one per action, each preceding its own `resolveOwnedChatBox()` call where applicable (§5.5).
6. `grep -c "customer_id" ` scoped to `block()`'s own method body → at least 1 (the new `Contacts` predicate, §5.4).
7. `grep -c "data-id=\"{{\\\$chat->id}}\""` in `partials/_chat_list.blade.php` → zero; `grep -c "data-id=\"{{\\\$chat->uid}}\""` → exactly 1 (§5.1, §9 item 3).
8. `grep -c "buildChatBubble\|buildMediaElement"` in `index.blade.php` → both present; `grep -c "\`<p>\${sms.message}</p>\`"` and equivalent unsafe-pattern searches for all 3 originally-named sites (§3.7) → zero remaining.
9. `preg_match_all('/->quickSend\([^)]*,\s*true\s*\)/', file_get_contents('app/Http/Controllers/Customer/ChatBoxController.php'))` → exactly **2** (§3.9, §5.9 — must remain unchanged from the pre-remediation baseline; this is `QuickSendNonConversationCallersUnaffectedTest.php`'s own existing, unmodified assertion, re-run, not a new search invented here).
10. `git diff --stat -- routes database config` compared against this remediation's own base → completely empty (§3.4, §3.5, §4).
11. `git diff --name-only` + `git ls-files --others --exclude-standard` → equals exactly §9's 5-item allowlist.
12. Full `php artisan test` pass count compared against the pre-remediation baseline, reported exactly, never estimated.

---

## 11. Stop conditions

- Any path beyond §9's 5-item allowlist — the **6th** path.
- Any change to `routes/customer.php`, any model file, or `config/customer-permissions.php` is found necessary — contradicts §3.4/§3.5's own mechanical conclusion; stop and re-audit rather than silently expanding scope.
- Any of the six actions' denial response for a foreign `uid` differs in status code or body from its response for a nonexistent `uid` (§5.3) — the core Finding 1 requirement, violated.
- `resolveOwnedChatBox()` is called before `$this->authorize('chat_box')` in any of the six actions (§5.5's ordering reversed).
- Any existing test other than the exact 3 call sites named in §9 item 5 requires modification.
- The `quickSend(..., true)` source-text count in `ChatBoxController.php` becomes anything other than exactly 2 (§3.9, §10 item 9).
- Any message-content interpolation into raw HTML strings/template literals survives anywhere in `index.blade.php` after the remediation (§5.7).
- Any `media_url`/`sms.media_url`/`response.media_url` value is concatenated into an HTML string rather than assigned via `.attr('src', ...)` (§5.8).
- `idempotency_token` requirement, generation, reservation behavior, `m5_token_action` classification, or provider-send semantics change for an *owned* `ChatBox`'s `reply()` in any way beyond the ordering described in §5.6.
- The optimistic-send video-rendering inconsistency (§3.10) is fixed as a side effect of this remediation without separate, explicit authorization.
- Any Slice 6 *visual*/componentization change (icon migration, `<x-button>`/`<x-card>`/`<x-tooltip>` adoption, hardcoded-color elimination) is made under cover of this security remediation.
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is found necessary to reference.
- A genuine architectural blocker is found that the 5-item allowlist cannot accommodate.

---

## 12. Governance block

```
docs_only: true
implementation_has_occurred: false
merge_authorizes_implementation: false
implementation_requires_separate_human_authorization: true
advance_automatically: false
start_automatically_after_contract_merge: false
merge_authority: human_only
no_force_push: true
no_deployment: true
slice_6_visual_status: blocked_until_security_implementation_human_merged
no_automatic_advance_to_slice_7a_or_any_other_initiative: true
maximum_correction_rounds: 2
correction_round: 0
```

Merging this contract does **not** automatically start the security implementation. A separate, explicit human authorization is required.

---

## 13. Contract self-audit

1. Every finding (1–7) named in the drafting instructions is independently re-verified against current source, not copied from the Slice 6 contract's own prose — with two material corrections found and stated precisely: the exact 3 pre-existing RFC-005 tests hardcoding `ChatBox.id` for `reply()` (§3.9), and the exact `quickSend(..., true)` source-count constraint (§3.9) — neither of which the Slice 6 contract itself surfaced. ✓
2. The canonical identifier decision is locked exactly (`uid`), with its reasoning stated explicitly, not deferred (§3.4, §5.1). ✓
3. The ownership-resolution architecture is locked exactly (`resolveOwnedChatBox()`), compared explicitly against leaving implicit binding in place, with a stated reason for rejecting the alternative (§3.4, §5.2). ✓
4. All six single-record actions, their exact new signatures, and their exact per-action denial response are enumerated in one table, not left to implementation discretion (§5.3). ✓
5. Permission-enforcement and authorization-ordering changes are stated exactly, including `reply()`'s specific ordering reversal (§5.5). ✓
6. `block()`'s `Contacts` tenant predicate is stated as an exact code change, with the correct tenant column (`customer_id`) verified against actual model/schema/controller usage, not assumed (§3.6, §5.4). ✓
7. The message-safe-rendering and `media_url`-safe-attribute mechanisms are both specified as exact code, not as "escape it"/"use DOM APIs" without detail (§5.7, §5.8). ✓
8. RFC-005 billing/idempotency preservation is verified against the actual existing test file, in full, with the exact required test modification identified and scoped to 3 specific lines (§3.9, §9 item 5). ✓
9. The already-correct `index()`/`loadChatUsers()` scoping is explicitly named as preserved, not silently assumed (§5.9). ✓
10. Routes and models are explicitly evaluated and excluded from the allowlist with stated reasons, not merely omitted (§3.4, §9). ✓
11. The future implementation allowlist is closed, numbered, exactly 5 items, with an exact stop threshold of 6 (§9). ✓
12. No phrase equivalent to "implementation may decide," "choose whichever works," or "where appropriate" appears anywhere a mechanically decidable security architecture question is addressed (§5). ✓
13. The non-blocking optimistic-video-rendering issue is named and explicitly not repaired, with a stated reason (§3.10, §11). ✓
14. `docs/automation/AI-AUTONOMY-STATE.json` untouched. ✓
15. This document remains the only file changed on this branch (§2). ✓
16. No implementation authorization is granted anywhere in this document. ✓

---

## 14. Verification and publication

1. `git diff --check` — clean.
2. `git status --short` — exactly `?? docs/automation/DESIGN-SYSTEM-M2-SLICE-6-CHATBOX-SECURITY-REMEDIATION-CONTRACT.md`.
3. `git diff --name-only` — empty before staging (new file, untracked).
4. Stage the one file by its exact path only (never `git add -A`/`.`).
5. Commit exactly: `docs: define Slice 6 ChatBox security remediation`.
6. Push to `origin chore/design-system-m2-slice6-chatbox-security-contract` — normal push, never forced.
7. If `gh` is available, open a **draft** PR into `main`. If `gh` is unavailable, report the exact GitHub comparison URL instead.
8. **Do not merge. Do not begin this remediation's implementation. Do not begin Slice 6's visual implementation. Do not begin Slice 4, Slice 7a/Campaigns, or any other slice/initiative.** No test is run for this docs-only change.

---

*End of Design System M2 Slice 6 ChatBox Security Remediation Contract, first drafting pass. Implementation requires a separate, explicit human instruction. This contract's own merge does not start or resume it. Slice 6's own visual implementation remains blocked until this remediation's own implementation is human-merged and its exact merge SHA is pinned in Slice 6's later, separate implementation authorization.*
