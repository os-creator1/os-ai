# Design System M2 Slice 6 — ChatBox / Conversations Security Remediation Contract

**This document is fully self-contained.** No section below requires consulting the Slice 6 visual contract, the M2 contract, or any earlier commit to understand this remediation's complete rules — every finding, architecture decision, and path is restated here in full, independently re-verified against current source rather than copied from the Slice 6 contract's own prose.

**Status: contract only. No implementation has occurred under this document. Merging this contract does NOT authorize implementation. Slice 6's own visual/componentization implementation remains blocked until this remediation is itself contracted, human-merged, implemented, and human-merged — per the Slice 6 contract's own §7.**

**Correction Round 1** re-audits the exact current ChatBox client-side DOM and message-rendering behavior mechanically and corrects seven factual errors this round found in the original drafting pass: (A) the claim that the numeric `ChatBox` primary key "is never again placed in client-visible markup" was false — both list forms and the Echo listener retain `data-box-id="{{$chat->id}}"`/`e.data.id` as a non-authoritative DOM-correlation value distinct from the canonical `uid` action identifier, corrected in a new §3.11 and in §5.1; (B) the claim that all 3 message-rendering paths select media type via `isImageOrVideo()` was false — only the history-load and Echo-listener paths do; the optimistic-send path always renders `<img>` unconditionally, corrected in §3.8; (C) the original `buildChatBubble()` design silently discarded real, per-path structural differences (avatar wrapper markup/type/dimensions, `chat-time` presence, media `alt` text, the optimistic path's `200px` size constraint) that a universal reconstruction would have erased — every one of these exact differences is now documented precisely in §3.11; (D) the safe-rendering architecture is corrected from one universal bubble-reconstruction function to a narrower design that keeps each path's own existing outer structure and replaces only the two genuinely unsafe dynamic-value insertion points (message text, media `src`) via two small shared helpers, minimizing regression surface, in a rewritten §5.7/§5.8; (E) the test contract is corrected to match this narrower architecture, adding explicit preservation assertions for every structural fact named in (C), in a rewritten §7.2; (F) the permission-test wording claiming an actor "cannot distinguish permission denied from identifier denied" is corrected to state the actual, intentional property precisely — a `chat_box`-lacking actor always receives the same `403` regardless of identifier, because `authorize()` runs before any identifier is resolved, not because the two denial shapes are themselves indistinguishable from each other; (G) the allowlist is re-evaluated against corrections A–F and confirmed unchanged at exactly 5 paths, with `_sidebar.blade.php`'s exclusion now justified explicitly by (A)'s own distinction. No architectural conclusion from the original drafting pass — the canonical `uid` identifier, the `resolveOwnedChatBox()` resolver, the authorize-before-resolve ordering, the `block()`/`Contacts.customer_id` scope fix, the zero route/model changes, or the 3-line `ConversationsPlainSmsMeteringTest.php` update — is reopened beyond these seven corrections.

**Correction Round 2 (final — `maximum_correction_rounds: 2`)** found that Correction Round 1's own narrowed safe-rendering fix still went one step too far: its shared `appendSafeMessage($container, value)` helper imposed a single presence rule (`value !== null && value !== undefined && value !== ''`) on all 3 message-rendering paths, silently overwriting three genuinely different, mechanically-confirmed current rules — history load only creates a `<p>` when `sms.message` is truthy; optimistic send *always* creates one, even for an empty string; the Echo listener creates one for any non-`null` value, including an empty string. This round corrects the architecture so the shared helpers (renamed `safeMessageParagraph()`/`safeTypedMediaParagraph()`, §5.7/§5.8) contain **zero** presence logic of their own — they only construct a safe node when called — and each path's own existing, unmodified condition alone decides whether to call them. A new §5.9 locks the exact child insertion order per path (media→message→timestamp for history/Echo; message→media, no timestamp, for optimistic) as binding, replacing Round 1's looser "equivalent implementation-time mechanical choice" wording, which risked permitting an implementation that preserved presence but silently reordered or restructured. §7.2's test contract, §10's mechanical searches, and §11's stop conditions are all updated to assert these exact per-path conditions and orderings directly, never a harmonized approximation. **This is the final ordinary correction round** (`correction_round: 2`, `correction_round_is_final: true`) — the ordinary correction budget is now exhausted. No conclusion preserved from Correction Round 1 — the canonical `uid` identifier, `data-box-id`'s role, `resolveOwnedChatBox()`, the authorize-before-resolve ordering, the `block()`/`Contacts.customer_id` fix, the zero route/model/config changes, the 3-line `ConversationsPlainSmsMeteringTest.php` update, the exact 2 `quickSend(..., true)` call sites, or the 5-item allowlist — is reopened by this round.

**Human-Authorized Post-Round Correction Exception (this pass).** `maximum_correction_rounds: 2`, `correction_round: 2`, and `correction_round_is_final: true` are unchanged — **this is not Correction Round 3; it is one narrowly-bounded, explicitly human-authorized exception**, reconciling a single newly-identified response-serialization preservation defect found during final review, which does not reopen this contract's architecture or grant any implementation authority. §5.3's own prior text replaced *both* of `messages()`'s current raw queries — the unsafe `chat_boxes` lookup and the `chat_box_messages` retrieval — with one Eloquent `ChatBoxMessage::where(...)` call. That second substitution is withdrawn: `ChatBoxMessage` declares no explicit date casts, so switching from a raw query-builder collection to an Eloquent collection would silently change `created_at`'s JSON-serialized format in `messages()`'s own success response — an unauthorized behavior change this remediation's own preservation rule forbids. §5.3.1 (new) locks the exact fix: the unsafe `chat_boxes` lookup is still eliminated via `resolveOwnedChatBox()`, exactly as every prior round already required, but the `chat_box_messages` retrieval remains the original raw `\DB::table(...)` call, its `box_id` predicate now sourced from the resolver's own trusted `$box->id` rather than the raw client-supplied value. This exception touches only §5.3/§5.3.1, §9 item 1's own description, §7.2's test contract, and §10's mechanical searches — every other conclusion from both correction rounds is unchanged and not reopened.

---

## 0. Governance

- Originally drafted on branch `chore/design-system-m2-slice6-chatbox-security-contract`, in an isolated linked worktree, based on `origin/main` at `c5320611a65b9fd97a7287542d4de11dd96822e0` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` before the original drafting pass began, and remains `origin/main`'s exact value at this correction round as well, re-confirmed (§1). This SHA is PR #180 ("Design System M2 Slice 6 — ChatBox / Conversations Contract", including its own Correction Round 1).
- **This is Correction Round 2 of a maximum of 2 (`maximum_correction_rounds: 2`) — the final ordinary correction round for this contract.** Continued on the same existing branch, same worktree, no new branch created.
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
6. **Correction Round 1's own additional mechanical re-verification**: directly re-read, again, at this round — the exact `data-id`/`data-box-id` attribute pairs in `_sidebar.blade.php` (line 51) and `partials/_chat_list.blade.php` (line 2), and the Echo listener's `let box_id = e.data.id;` / `` $(`.media-list li[data-box-id=${box_id}]`) `` correlation lookup in `index.blade.php` (lines 799, 810) — to correct the original drafting pass's false "numeric id never appears in client-visible markup" claim (§3.11, §5.1). Also directly re-read, line-by-line, all 3 message-bubble construction sites in `index.blade.php` (history load ~lines 344–377, optimistic send ~lines 495–512, Echo listener ~lines 814–863) to correct the original drafting pass's false claim that all 3 select media type via `isImageOrVideo()`, and to document every per-path structural difference (avatar wrapper markup/type/dimensions, `chat-time` presence, media `alt` text, the optimistic path's inline `200px` size constraint) precisely (§3.8, §3.11).
7. **Correction Round 2's own additional mechanical re-verification**: directly re-read, a third time, the exact message-presence and media-presence conditions and their exact position relative to media/timestamp insertion in all 3 paths of `index.blade.php` — confirming, character-for-character: history load's `` let message = sms.message ? `<p>${sms.message}</p>` : ""; `` (line 358, a truthiness check) positioned between the media block and the `chat-time` `<p>` in `chatHtml`'s own template (lines 360–374); optimistic send's unconditional `"<p>" + messageValue + "</p>"` (line 504) positioned *before* its own conditional `if (response.media_url)` media block (lines 507–509), with no `chat-time` element anywhere in that construction (lines 495–512); the Echo listener's `` if (sms.message !== null) { message = `<p>${sms.message}</p>`; } `` (lines 829–831, a strict non-null check) positioned between the media block and the `chat-time` `<p>` in both the incoming (lines 833–847) and outgoing (lines 848–863) branches. This re-verification is the direct basis for Correction Round 2's own §5.7/§5.8/§5.9 rewrite.
8. **This post-round exception's own additional mechanical re-verification**: directly re-read, a fourth time, `app/Http/Controllers/Customer/ChatBoxController.php`'s current `messages($id)` method body (lines 339–361) and `app/Models/ChatBoxMessage.php` in full — confirming `messages()`'s own two current raw queries (the `chat_boxes` lookup and the separate `chat_box_messages` retrieval, §5.3.1) exactly, and confirming `ChatBoxMessage` declares `protected $fillable = ['box_id', 'message', 'media_url', 'sms_type', 'direction', 'sending_server_id', 'send_by'];` with **no** `protected $casts` entry for `created_at`/`updated_at` — the direct, mechanical basis for §5.3.1's own serialization-risk finding, not assumed from Laravel's general default behavior alone.

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

### 3.8 Finding 7 — `media_url` attribute safety, current shape re-confirmed — corrected this round (Correction B)

**Corrected this round.** The original drafting pass claimed all 3 paths select `<img>`/`<video>`/`<audio>` via the existing `isImageOrVideo(url)` helper. That claim is false for one of the three, mechanically re-confirmed by direct line-by-line re-read (§1 item 6, §3.11):

- **History load** and **Echo listener** both call `isImageOrVideo(sms.media_url)` and branch to `<video>`/`<audio>`/`<img>` accordingly, each wrapped in a `<p>`.
- **Optimistic send** does **not** call `isImageOrVideo()` at all — it unconditionally renders `response.media_url` as `<img src="..." alt="media" style="max-width:200px; max-height:200px;">`, wrapped in a `<p>`, regardless of the actual uploaded file's type.

All 3 paths interpolate their respective media URL into a `src="${...}"` template-literal/concatenated HTML attribute string — that part of the original finding stands, re-confirmed. No attribute-breakout exploit was proven, but none was proven absent either — server-generated paths from `Tool::uploadImage()` were not verified immune to containing a literal `"` character. This remediation resolves the question definitively via the mechanism locked in §5.8, rather than continuing to assume safety — applied to each path's own already-existing tag-selection logic (typed selection for history/Echo, unconditional `<img>` for optimistic-send), never changing *which* tag a path selects, only *how* the `src` value is safely assigned (§3.10).

### 3.9 RFC-005 Milestone 5 billing/idempotency preservation audit — read in full

**`tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` (1,387 lines), read in full.** Confirms the exact `idempotency_token` contract this remediation must not alter: `test_reply_missing_token_returns_422_and_never_reaches_quicksend()`, `test_reply_invalid_token_returns_422_and_never_reaches_quicksend()`, `test_reply_valid_token_passes_trusted_conversation_context_through_to_quicksend()`, `test_sent_retain_redirect_preserves_token_and_restores_form_input()` — the fail-closed 422 behavior for a missing/invalid `idempotency_token` (before `quickSend()` is ever reached), the `m5_token_action` clear/retain classification, and `new()`'s `?m5_retry_token=` reuse logic are all read and must remain byte-identical.

**Critical, mechanically-discovered dependency, not present in the Slice 6 contract's own audit:** three of these tests call `route('customer.chatbox.reply', $box->id)` — the **numeric primary key**, not `$box->uid` — at lines 1283, 1302, and 1334. Under the current, pre-remediation `reply($id) { ChatBox::find($id); ... }` resolution, this works because `find()` resolves by primary key. **Once this remediation changes `reply()` to resolve exclusively via `resolveOwnedChatBox(string $uid)` (§3.4, §5), these three exact call sites will no longer resolve a real box** — the numeric `id` string will not match any `uid` column value, and each test will receive the new uniform denial response instead of its currently-asserted `422`/`200` outcome. **This is a real, necessary, narrowly-scoped existing-test modification this remediation's own implementation must make** (§9) — not incidental scope creep, but a direct, unavoidable consequence of intentionally changing the identifier contract that this pre-existing test hardcodes. The fix is mechanical and minimal: change `$box->id` to `$box->uid` at these exact three call sites; no other line in this 1,387-line file requires any change.

**`tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php` (306 lines), read in full.** Contains a source-level mechanical assertion this remediation must not break: `test_chatbox_controller_is_the_only_caller_passing_true()` (line 106) reads `ChatBoxController.php`'s raw file contents and asserts `preg_match_all('/->quickSend\([^)]*,\s*true\s*\)/', $contents)` equals **exactly 2** — the two existing `$this->campaigns->quickSend($campaign, $input, true);` call sites, in `sent()` and `reply()`. **This remediation's own refactor of `reply()` (adding the authorize-then-resolve ordering, §3.5/§5) must preserve both call sites' exact source text unchanged** — the count must remain exactly 2 after this remediation, verified mechanically (§10, mechanical search item 9). This file itself requires no modification.

**Billing/idempotency-relevant conclusion:** this remediation changes only *where in the request lifecycle* a foreign/nonexistent `ChatBox` is rejected (before any `quickSend()`/provider/billing work is reached, §5.7) — it does not alter the `idempotency_token` requirement, its generation/lifecycle, reservation behavior, `m5_token_action` classification, or provider-send semantics for an *owned* box in any way. `reply()`'s own idempotency-token validation block (lines 442–447) executes unchanged, in the same relative position within the method (after the new authorize+resolve step, which now runs first).

### 3.10 Non-blocking existing correctness issue — re-audited, not repaired

The Slice 6 contract's own Correction I finding — optimistic attachment rendering always emits `<img>` while the `media_image` upload input and `reply()`'s own server-side validation (`'media_image' => '...|mimes:mp4,mov,ogg,qt,jpeg,png,jpg,gif,bmp,webp|...'`) both genuinely accept video — is re-confirmed by direct read, unchanged. **This remediation does not fix it.** §5.8's safe-media-construction mechanism is applied uniformly to whatever element type each of the 3 paths already selects (typed selection via the existing `isImageOrVideo()` branching in the history-load and Echo-listener paths, §3.8; the existing unconditional `<img>` choice in the optimistic-send path) — the *safety* of setting `src` changes; the *choice of tag* does not, for any path. Widening this remediation's scope to also fix the optimistic-send video-tag mismatch is explicitly out of scope here, named only for completeness (§0's own governance boundary).

### 3.11 Exact per-path bubble structure audit — new this round (Correction C), the basis for §5.7/§5.8's narrower architecture

Direct, line-by-line re-read of all 3 message-bubble construction sites in `index.blade.php` (§1 item 6), documenting every structural fact a safe-rendering fix must preserve exactly, since the original drafting pass's single universal `buildChatBubble()` design would have silently discarded every one of them:

**Path 1 — history load** (the `$.post('.../messages')`'s `.done()` handler, `cwData.forEach(...)`):
```html
<div class="chat ${incoming ? 'chat-left' : ''}">
  <div class="chat-avatar">
    <span class="avatar box-shadow-1 cursor-pointer">
      <img src="{{ asset('images/profile/profile.jpg') }}" alt="avatar" height="36" width="36" />
    </span>
  </div>
  <div class="chat-body"><div class="chat-content">
    MEDIA (typed via isImageOrVideo, wrapped in <p>, img alt="media")
    MESSAGE (wrapped in <p>)
    <p class="chat-time text-muted mt-1">${sms.created_at}</p>
  </div></div>
</div>
```
Avatar: static profile image, `span.avatar.box-shadow-1.cursor-pointer` wrapper, `36×36`. Timestamp: **present**. Media: `isImageOrVideo()`-typed, `<p>`-wrapped, `<img>` branch uses `alt="media"`.

**Path 2 — optimistic send** (`enter_chat()`'s AJAX success handler):
```html
<div class="chat">
  <div class="chat-avatar">
    <span class="avatar box-shadow-1 cursor-pointer">
      <img src="{{ asset('images/profile/profile.jpg') }}" alt="avatar" height="36" width="36"/>
    </span>
  </div>
  <div class="chat-body"><div class="chat-content">
    MESSAGE (wrapped in <p>)
    MEDIA — only if response.media_url — always <img>, alt="media",
      style="max-width:200px; max-height:200px;", wrapped in <p>
  </div></div>
</div>
```
Avatar: identical static image / `span.avatar.box-shadow-1.cursor-pointer` / `36×36` wrapper as path 1. Timestamp: **absent** — no `.chat-time` element exists in this path today. Media: **never** calls `isImageOrVideo()`; always `<img>`; carries the exact inline `style="max-width:200px; max-height:200px;"` constraint found nowhere else; `<p>`-wrapped.

**Path 3 — Echo listener** (`Echo.private("chat").listen("MessageReceived", ...)`):
```html
<div class="chat ${incoming ? 'chat-left' : ''}">
  <div class="chat-avatar">
    <a class="avatar m-0" href="#">
      <img src="INCOMING: asset('images/profile/profile.jpg') | OUTGOING: route('user.avatar', Auth::user()->uid)"
           alt="avatar" height="40" width="40"/>
    </a>
  </div>
  <div class="chat-body"><div class="chat-content">
    MEDIA (typed via isImageOrVideo, wrapped in <p>, img alt="")
    MESSAGE (wrapped in <p>)
    <p class="chat-time text-muted mt-1">${sms.created_at}</p>
  </div></div>
</div>
```
Avatar: **`a.avatar.m-0[href="#"]` wrapper, not `span`**, `40×40` — genuinely different markup/dimensions from paths 1 and 2, not a cosmetic variant. Incoming avatar is the same static profile image path 1 uses; outgoing avatar uses `route('user.avatar', Auth::user()->uid)` — this path is the only one of the three whose avatar `src` differs by message direction. Timestamp: **present**. Media: `isImageOrVideo()`-typed, `<p>`-wrapped, but its `<img>` branch uses `alt=""` (empty) — **not** `alt="media"` as path 1 uses; a genuine, pre-existing, minor cross-path inconsistency, preserved exactly rather than silently harmonized (§5.8's own helper takes the `alt` text as a parameter for exactly this reason).

**Also preserved, both branches of path 3**: the `sms.direction === "incoming"` test drives both the outer `chat-left` class and which avatar `src` is used — the two concerns share one condition today and continue to. The `if (chat_id === activeChatID) { append to visible history } else { update the counter instead }` branch (line 865) that decides whether an Echo-pushed message renders into the currently-open conversation or only increments the unread badge is unrelated to message-content safety and is unchanged by this remediation.

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

### 5.1 Canonical ChatBox external identifier — corrected this round (Correction A)

**Corrected this round.** The original drafting pass claimed the numeric `ChatBox` primary key "is never again placed in client-visible markup." That is false, mechanically re-confirmed (§1 item 6, §3.11): both `_sidebar.blade.php` (line 51) and `partials/_chat_list.blade.php` (line 2) carry a `data-box-id="{{$chat->id}}"` attribute alongside `data-id`, and `index.blade.php`'s Echo listener reads `let box_id = e.data.id;` (line 799) to locate the matching DOM row via `` $(`.media-list li[data-box-id=${box_id}]`) `` (line 810). This remediation corrects the architecture to distinguish two genuinely different values explicitly, rather than treating "identifier" as one undifferentiated concept:

- **Canonical external action identifier — `ChatBox.uid` (string), exclusively.** Used for: the `data-id` attribute on every list `<li>` (§9 item 3 fixes the one place this is not yet true); the shared click handler's `chat_id` value and the hidden `.chat_id` field it populates; and the `{box}` route segment consumed by all six single-record controller actions (§5.2, §5.3). This is the only value ever accepted for ownership-scoped resolution.
- **Non-authoritative DOM correlation value — `data-box-id`, `ChatBox.id` (numeric), retained.** Used **only** to let the Echo listener match a real-time-pushed event (whose payload already carries the numeric `id`, not `uid` — `e.data.id`) to an existing DOM row, so the correct conversation's unread counter can be updated without a full list reload. This value is **never** sent to, and **never** accepted by, any controller action — `resolveOwnedChatBox()` (§5.2) only ever queries by `uid`, so even if a `data-box-id` value were somehow submitted to a single-record route, it would fail owned-`uid` resolution exactly like any other non-`uid` string (§7.2, item B's own numeric-injection test covers this directly). `data-box-id` is not, and does not become, a security boundary — it is retained purely as an existing, already-correct client-side DOM-correlation mechanism this remediation has no reason to touch.

`_sidebar.blade.php` is **not** added to §9's allowlist merely to remove `data-box-id` — no security requirement calls for its removal, and the Echo correlation mechanism it supports is independently correct today, unrelated to the ownership gap this remediation closes (§9's own "explicitly not included" list restates this exactly).

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
| `messages(string $uid)` | `string $uid` (was `$id`, untyped) | `$box = $this->resolveOwnedChatBox($uid);` then, if `$box`, `\DB::table('chat_box_messages')->where('box_id', $box->id)->orderBy('created_at', 'asc')->get()` — **corrected by post-round exception, §5.3.1 below**: the raw `chat_box_messages` query-builder retrieval is *intentionally preserved*, not replaced with an Eloquent `ChatBoxMessage::where(...)` call; only the unsafe raw `chat_boxes` lookup is eliminated | `response()->json(['status' => 'error', 'data' => [], 'pinned' => 0])` — **HTTP 200**, preserving this action's own existing success/failure JSON shape exactly |
| `messagesWithNotification(string $uid)` | `string $uid` (was `ChatBox $box`) | `$box = $this->resolveOwnedChatBox($uid);` | `response()->json(['status' => 'error', 'message' => 'Chat box not found.'], 404)` |
| `reply(string $uid, Campaigns $campaign, Request $request)` | `string $uid` (was `$id`) | `$box = $this->resolveOwnedChatBox($uid);` | `response()->json(['status' => 'error', 'message' => 'Chat box not found. Refresh page.'], 404)` — this action's own existing string and status code, now also returned for a foreign `uid`, not only a nonexistent one |
| `delete(string $uid)` | `string $uid` (was `ChatBox $box`) | `$box = $this->resolveOwnedChatBox($uid);` | `response()->json(['status' => 'error', 'message' => 'Chat box not found.'], 404)` |
| `block(string $uid)` | `string $uid` (was `ChatBox $box`) | `$box = $this->resolveOwnedChatBox($uid);` | `response()->json(['status' => 'error', 'message' => 'Chat box not found.'], 404)` |
| `pin(string $uid)` | `string $uid` (was `ChatBox $box`) | `$box = $this->resolveOwnedChatBox($uid);` | `response()->json(['status' => 'error', 'message' => 'Chat box not found.'], 404)` |

Every other line of business logic in each of these six methods — the message list shape, `messagesWithNotification`'s `notification` count, `reply()`'s entire spam-check/sending-server/sender-id/`quickSend()` chain (§5.6), `delete()`'s message-then-box deletion, `block()`'s `Blacklists`/`Contacts` writes (§5.4), `pin()`'s toggle — is unchanged, operating on the now-owned `$box` exactly as it does today.

### 5.3.1 `messages()`'s exact retrieval mechanism — human-authorized post-round correction exception

**Corrected by explicit human-authorized post-round exception (not Correction Round 3, §0).** The prior round's own §5.3 replaced *both* of `messages()`'s current raw queries — the unsafe `chat_boxes` lookup **and** the `chat_box_messages` retrieval — with a single `ChatBoxMessage::where('box_id', $box->id)->orderBy('created_at', 'asc')->get()` Eloquent call. That second substitution was never required by the security fix and is withdrawn: `ChatBoxMessage` (re-read directly, §1) declares no `$casts` for `created_at`/`updated_at`, so a plain Eloquent `Model` inherits Laravel's default behavior of exposing those columns as `Carbon` instances — which, once JSON-serialized in `response()->json([...])`, render as Carbon's own ISO-8601-shaped string format, not the raw MySQL datetime string format (`\DB::table(...)->get()`'s stdClass rows return untouched driver strings for every column, including `created_at`). Switching retrieval mechanisms would therefore silently change the JSON representation of every message's `created_at` (and any other column) in `messages()`'s own success response — an unauthorized product/API behavior change this remediation's own success-response preservation rule (§5.9/§6) forbids, not a stylistic Eloquent-vs-query-builder preference.

**Locked fix, exact code:**
```php
public function messages(string $uid): JsonResponse
{
    $this->authorize('chat_box');

    $box = $this->resolveOwnedChatBox($uid);

    if (! $box) {
        return response()->json(['status' => 'error', 'data' => [], 'pinned' => 0]);
    }

    $messages = \DB::table('chat_box_messages')
        ->where('box_id', $box->id)
        ->orderBy('created_at', 'asc')
        ->get();

    return response()->json([
        'status' => 'success',
        'data' => $messages,
        'pinned' => $box->pinned ?? 0,
    ]);
}
```

**Exact distinction, stated precisely:**
- **Removed**: the raw, unsafe `\DB::table('chat_boxes')->where('id', $id)->first()` lookup — the client-supplied value is no longer trusted for `ChatBox` resolution at all; `resolveOwnedChatBox($uid)` (§5.2) is the sole resolution path, exactly as every other single-record action.
- **Preserved, unchanged in mechanism**: the raw `\DB::table('chat_box_messages')` query-builder retrieval — its `orderBy('created_at', 'asc')` and its overall response shape are byte-identical to today. Only its `box_id` predicate's *source* changes: it now reads `$box->id` — the **trusted, internal** numeric id taken from the already-owned `$box` Eloquent model `resolveOwnedChatBox()` returned — never the raw, client-supplied `$uid` string directly (which is not even a valid `box_id` value, since `box_id` is a foreign key to `chat_boxes.id`, not `chat_boxes.uid`).
- The security boundary is established entirely by `resolveOwnedChatBox()`'s own `uid` + `user_id` predicate (§5.2) *before* this query runs — by the time `$box->id` is read, ownership is already proven; the `chat_box_messages` query itself needs no additional tenant predicate of its own, exactly as before.
- `messagesWithNotification()` is **not** part of this exception — it already uses `ChatBoxMessage::where(...)` today (confirmed by direct re-read, §1) and is left exactly as this remediation's own prior rounds already locked it; this exception concerns only `messages()`'s own distinct retrieval mechanism.

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

### 5.7 Safe message-content rendering mechanism — corrected this round (Correction A/B/C), the helper contains zero presence logic

**Corrected this round.** Correction Round 1's `appendSafeMessage()` still imposed one shared presence rule (`value !== null && value !== undefined && value !== ''`) on all 3 paths, silently overwriting three genuinely different, mechanically-confirmed current rules (§3.11, re-verified this round):

| Path | Current presence condition | Behavior |
|---|---|---|
| History load | `` sms.message ? `<p>...</p>` : "" `` — a **truthiness** check | `<p>` created only if `sms.message` is truthy; omitted for `null`, `undefined`, or `""`. |
| Optimistic send | `"<p>" + messageValue + "</p>"` — **unconditional**, part of the base template string itself | `<p>` is **always** created, even when `messageValue` is the empty string — this matters for a media-only optimistic send, where an empty `<p></p>` is still present today. |
| Echo listener | `` sms.message !== null ? `<p>...</p>` : (stays "") `` — a **strict non-null** check | `<p>` created for `""` (an empty `<p></p>` **is** produced) and for any non-`null` value; omitted only for `null`. |

**Locked fix: the shared helper contains no presence/business logic of any kind — it only constructs one safe `<p>` node, unconditionally, whenever it is called:**
```js
function safeMessageParagraph(value) {
  return $('<p></p>').text(value); // .text() — never .html()/string concatenation; no null/undefined/empty check here
}
```
`safeMessageParagraph()` itself never decides *whether* to render a message — it has no `if` of any kind. **Each path's own existing condition decides whether to call it, exactly as today, harmonized with nothing:**
```js
// HISTORY LOAD — unchanged truthiness condition
if (sms.message) {
  $content.append(safeMessageParagraph(sms.message));
}

// OPTIMISTIC SEND — unconditional, matching today's unconditional <p> creation
$content.append(safeMessageParagraph(messageValue));

// ECHO LISTENER — unchanged strict non-null condition (both incoming/outgoing branches)
if (sms.message !== null) {
  $content.append(safeMessageParagraph(sms.message));
}
```
**These three conditions are never harmonized into one shared rule, and no new shared condition is added around any of them** — the differing behavior for an empty string (omitted in history, an empty `<p>` in Echo, always present in optimistic) is a real, pre-existing distinction this remediation preserves exactly, not a bug it fixes.

**Exact child order, locked and binding (§3.11's own documented order, restated here as a requirement, not merely a fact):**
- **History load**: optional media → optional message (per the condition above) → `chat-time`.
- **Optimistic send**: message (always) → optional media (§5.8) → **no** `chat-time`.
- **Echo listener**: optional media → optional message (per the condition above) → `chat-time`.

No implementation technique may reorder these — history/Echo's message must never be appended after `chat-time`; history/Echo's `chat-time` must never be moved before media/message; optimistic's media must never be placed before its message; optimistic must never gain a `chat-time`; and no additional or missing wrapper may result from however the insertion is mechanically sequenced (§5.9 below resolves the "how" question precisely, closing the ambiguity Correction Round 1 left open).

Each path's own existing outer-structure construction (avatar wrapper, `chat-body`/`chat-content` divs, `chat-left`/direction handling) is otherwise **unchanged** — none of that markup is built from attacker-influenced values today, so none of it needs to move off string/template-literal construction. Only the specific line(s) in each path that currently interpolate `sms.message`/`messageValue` directly into an HTML string are replaced with the conditional (or unconditional) `safeMessageParagraph()` call shown above, inserted at the exact position the child-order table requires.

**This satisfies every one of the following, mechanically, by construction — never by convention or reviewer discipline:**
- `sms.message`/`messageValue` containing literal text such as `<script>alert(1)</script>` renders as visible text, because `.text()` sets the DOM `textContent` property, which the browser never parses as markup, regardless of content.
- `<img onerror=...>`-shaped text similarly displays as text.
- Ordinary quotes, ampersands, angle brackets, Unicode, and newlines in legitimate SMS text remain fully readable, since `.text()` performs no character-level filtering.
- `.chat-time` presence/absence, `.chat-left`, avatar markup/type/dimensions per path, message-presence semantics, child order, and append/scroll/`activeChatID` behavior are all **structurally unchanged** — the direct, intended consequence of this design, not merely a claim.

### 5.8 Safe `media_url` attribute mechanism — corrected this round (Correction D), the typed helper also contains zero presence logic

**Corrected this round.** Per §3.8's own correction, the history-load and Echo-listener paths already type-select via `isImageOrVideo()`; the optimistic-send path does not and never has. This remediation preserves that exact distinction. **Additionally, corrected this round**: Correction Round 1's typed-media helper still contained its own internal `if (url === null || url === undefined) return;` guard — a second, helper-owned presence rule that risks silently diverging from each caller's own exact condition. **That guard is removed; the helper only constructs, exactly mirroring §5.7's fix:**

```js
function safeTypedMediaParagraph(url, imgAlt) {
  const type = isImageOrVideo(url); // existing helper, unchanged
  let $media;
  if (type === 'video') {
    $media = $('<video controls>Your browser does not support the video tag.</video>');
  } else if (type === 'audio') {
    $media = $('<audio controls>Your browser does not support the audio element.</audio>');
  } else {
    $media = $('<img>').attr('alt', imgAlt);
  }
  $media.attr('src', url);
  return $('<p></p>').append($media);
}
```
Called only from within each caller's own **unchanged, path-local** presence condition — never inside the helper:
```js
// HISTORY LOAD — unchanged condition, alt="media" preserved
if (sms.media_url !== null) {
  $content.append(safeTypedMediaParagraph(sms.media_url, 'media'));
}

// ECHO LISTENER — unchanged condition, alt="" preserved
if (sms.media_url !== null) {
  $content.append(safeTypedMediaParagraph(sms.media_url, ''));
}
```
The one pre-existing, minor cross-path `alt`-text inconsistency §3.11 identifies (`'media'` vs. `''`) is carried forward exactly via the `imgAlt` parameter, not silently harmonized.

**For optimistic send** — its own separate, image-only path, **never** routed through `safeTypedMediaParagraph()` or `isImageOrVideo()`, preserving its exact current unconditional-tag-choice behavior, its own unchanged presence condition, and its `200px` size constraint:
```js
if (response.media_url) {
  const $img = $('<img alt="media" style="max-width:200px; max-height:200px;">').attr('src', response.media_url);
  $content.append($('<p></p>').append($img));
}
```

In both mechanisms, `.attr('src', url)` assigns the string as an attribute *value*, never as HTML to be parsed — a `"` character (or any other character) inside `url` cannot break out of the attribute context, because no HTML string containing the URL is ever constructed or parsed. No mechanism changes *which* tag a path selects for a given file type, *whether* media renders at all for a given path (each path's own condition is untouched), or the optimistic path's pre-existing always-`<img>` behavior (§3.10, deliberately not repaired here) — only *how* the `src` value is safely assigned.

### 5.9 Implementation-mechanics boundary — corrected this round (Correction F), replacing "equivalent implementation-time choice" language

**Corrected this round.** §5.7's own text in the prior round described obtaining the message/media insertion target (e.g., via `.find('.chat-content')` on an already-appended bubble, vs. appending into a detached shell before insertion) as "equivalent implementation-time mechanical choices." That framing is too loose, because those choices are not equivalent unless every invariant below also holds — different DOM-construction mechanics could easily produce a different child order or a duplicated/missing wrapper by accident. **Corrected boundary**: implementation may choose *how* it obtains a reference to the target container and *when* it appends the bubble to the live DOM (before or after populating its content), **only if every one of the following remains exactly, mechanically true regardless of that choice** — none of these is left open to implementation judgment:
- The exact per-path outer structure documented in §3.11 (avatar wrapper markup/type/dimensions; `chat`/`chat-left` class logic).
- The exact per-path message-presence condition (§5.7's table) — never harmonized, never wrapped in an additional shared condition.
- The exact per-path media-presence condition (§5.8) — never harmonized, never moved into the typed helper.
- The exact child order locked in §5.7 (media → message → timestamp for history/Echo; message → media, no timestamp, for optimistic).
- The exact avatar structure, `chat-time` presence/absence, media `alt` text, `isImageOrVideo()` usage split, and the optimistic `200px` constraint (§3.11, §5.8).
- The exact safe-insertion mechanism itself — `.text()` for message content, `.attr('src', ...)` for media URLs, never `.html()` or string interpolation of either.

No mechanically decidable presence, order, or structural behavior is left to implementation discretion by this contract — only the narrow, behavior-invisible question of DOM-construction sequencing technique.

### 5.10 Explicitly preserved behavior (unchanged by this remediation)

- `index()`'s and `loadChatUsers()`'s existing `ChatBox::where('user_id', Auth::id())` query scoping (§3.2) — untouched, not loosened, not rewritten.
- `loadChatUsers()`'s filter/search/pagination logic — untouched; it gains only the missing `$this->authorize('chat_box')` call (§5.5), placed as its first statement.
- Both Echo/Pusher conditional guards (`@if(config('broadcasting.connections.pusher.app_id'))`) — untouched.
- The `chat-box` route group's exact 10 route names and URL structure (§3.4 — zero route-file change).
- Every element `id`/`name`/class-based JS selector not directly implicated in §5.7/§5.8 (`.tab-button`, `.send`, `.message`, `.counter`, `.notification_count`, `.active`, `.add-to-blacklist`, `.remove-btn`, `.start-chat-area`, `.active-chat`, `#chat-search`, `#load-more`).
- The composer/attachment/SMS-template-picker structure, Select2 wiring, and all 3 SweetAlert2 confirm-flow configurations.
- `sent()` and `new()` — neither is named among the six single-record actions (§3.2); both already call `$this->authorize('chat_box')` and neither resolves a `ChatBox` by client-supplied identifier. Unchanged.
- The exact 2 `->quickSend(..., true)` call sites' own source text (§3.9) — preserved verbatim, mechanically verified (§10 item 9).
- `ChatBoxMessage::booted()`'s inbound-message mirroring into `cg_ai_conversations`/`cg_ai_messages` (an unrelated AI-copilot side effect, out of this remediation's scope entirely) — untouched.
- **New this round (Correction A):** `data-box-id`/`ChatBox.id`'s role as the Echo listener's own non-authoritative DOM-correlation value (`e.data.id` → `` li[data-box-id=...] ``, §5.1) — untouched; not removed, not repurposed, not accepted by any controller action.
- **From Correction Round 1 (C/D):** every per-path structural fact §3.11 documents — path 3's `a.avatar.m-0[href="#"]`/`40×40` wrapper (genuinely distinct from paths 1–2's `span.avatar.box-shadow-1.cursor-pointer`/`36×36`); `chat-time`'s presence in paths 1 and 3 and its absence in path 2; the `img alt="media"` (path 1) vs. `alt=""` (path 3) difference; path 2's exact `style="max-width:200px; max-height:200px;"` constraint; the `sms.direction === "incoming"` condition driving both `chat-left` and (path 3 only) avatar-`src` selection; the `chat_id === activeChatID` branch (§3.11's own closing paragraph) — all preserved exactly, none touched by §5.7/§5.8's fix.
- **New this round (Correction A/B/C/D):** each path's own exact message-presence condition (history's truthiness check, optimistic's unconditional creation including for an empty string, Echo's strict non-null check producing an empty `<p>` for `""`) and each path's own exact media-presence condition (`sms.media_url !== null` for history/Echo, `response.media_url` truthiness for optimistic) — none harmonized into a shared rule, none moved inside either safe-construction helper (§5.7, §5.8). The exact child order per path (§5.7's own table) — media/message/timestamp order for history and Echo, message/media/no-timestamp order for optimistic.

---

## 6. Preserve all behavior

Every item in §5.10, plus: no controller action's HTTP method, route name, or URL structure changes; no response field is renamed or removed from any of the six actions' *success* path (only the *denial* path is newly added or standardized, per §5.3); no `@can`/`@canany` Blade directive exists in the 4 ChatBox views to preserve (none does, confirmed by the Slice 6 contract's own audit, unaffected by this remediation which touches no Blade view's authorization-relevant markup); every localization key already present in `index.blade.php` remains present, since the two safe-rendering helpers' own static strings (`"Your browser does not support..."`) already exist verbatim in the current 3 paths and are only relocated, not reworded, per path, exactly where each path already uses them today (§5.7, §5.8).

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

**B. Identifier consistency — corrected this round (Correction A):**
- Render `customer.chatbox.index` (pinned list, when a pinned `ChatBox` exists) and assert the rendered `data-id` value equals the pinned chat's `uid` — already true today, asserted as a preservation check, not a fix.
- Call `loadChatUsers()` (the AJAX-loaded unpinned list) and assert the rendered fragment's `data-id` value equals the returned chat's `uid` — the direct proof of §9 item 3's own fix, since this is the exact view §3.11 found sending numeric `id` today.
- **New this round:** assert both list forms' `data-box-id` value continues to equal the chat's numeric `id` (§5.1) — proving the DOM-correlation value is deliberately retained, not collaterally removed by the `data-id` fix.
- For `messages`, `reply`, `delete`, `block`, `pin`, and `messagesWithNotification`: assert each accepts the same real `uid` value consistently, and — the direct proof of Finding 2's own closure — assert that submitting the **numeric primary key** of a real, owned `ChatBox` (instead of its `uid`) to each of these six routes does **not** resolve that box (receives the same denial as a foreign/nonexistent `uid`), proving numeric-ID injection cannot bypass the canonical `uid` ownership boundary even for an actor's own real box. **New this round:** this same numeric-injection test is the direct proof that `data-box-id`'s retained numeric value could never be used as a substitute action identifier even if a client attempted it (§5.1) — one assertion serves both purposes.

**C. Permission — corrected this round (Correction F):**
- An actor authenticated as a customer explicitly lacking `chat_box` (permissions array excludes it) is denied (`403`, via `authorize()`'s standard `AuthorizationException` handling) for `messages`, `messagesWithNotification`, `delete`, `block`, `loadChatUsers`, and `pin` — the six currently missing the check (§3.5).
- For `reply()` specifically: assert a `chat_box`-lacking actor is denied with the standard `403` authorization response for **both** a foreign tenant's real `uid` and a nonexistent `uid` — and assert these two `403` responses are themselves byte-identical to each other, proving the permission check alone (not identifier resolution) determines the outcome for such an actor. **Corrected claim, precisely stated (the original wording was logically imprecise):** the property being proven is not that the `403` (permission-denied) and `404` (ownership-denied, §5.3) shapes are indistinguishable from *each other* — they are intentionally different, by status code alone. The property is that a `chat_box`-lacking actor **always** receives the same `403`, for any identifier whatsoever, because `authorize()` (§5.5) runs and fails before `resolveOwnedChatBox()` is ever reached — so that actor's own responses carry no identifier-dependent information at all, regardless of how a *different*, permission-holding actor might be denied for the same identifier via the separate `404` path.

**D. `block()` Contacts scoping:**
- Create a `Contacts` row for tenant A and a separate `Contacts` row for tenant B, both with the identical `phone` value, both `status = 'subscribe'`.
- Tenant A blocks their own, owned `ChatBox` whose `to` matches that phone number.
- Assert tenant A's `Contacts` row is now `status = 'unsubscribe'`.
- Assert tenant B's `Contacts` row is byte/field-unchanged (`status` still `'subscribe'`, `updated_at` unchanged) — the direct mechanical proof of §5.4/Finding 5's own closure.
- Assert the `Blacklists` row created by the same request is attributed to tenant A's own `user_id` — preserving the already-correct behavior (§5.10), tested alongside the fix rather than assumed unaffected.

**E. XSS — corrected this round (Correction E, matching §5.7's condition-free helper) and Correction Round 1 (Correction E, matching §5.7's narrower architecture); honestly distinguished between HTTP/Blade tests and source/contract assertions (PHPUnit does not execute browser JS):**
- **Deterministic source-level assertions** (the only mechanically honest way to prove the JS safety seam from PHPUnit): the served `index.blade.php` response contains the `safeMessageParagraph()` helper (§5.7) using `.text(`, containing **no** `null`/`undefined`/empty/truthiness condition of its own and **no** `.html()` call — and **contains zero remaining instances** of the specific unsafe patterns named in §3.7 (`` `<p>${sms.message}</p>` ``, `"<p>" + messageValue + "</p>"`, and any other raw-HTML-string interpolation of `sms.message`/`messageValue`) — proven by asserting their exact literal absence via string/regex assertions against the response body, not by rendering and hoping. Does **not** require a `buildChatBubble()` function (removed per Correction Round 1's own narrower design) and does **not** require the outer bubble-construction code for any of the 3 paths to have changed shape at all.
- **HTTP/Blade behavioral tests**, honestly scoped to what Laravel's test client can actually prove: that `index()` (with an active conversation/pinned chat present) renders `200` and its response body contains the `safeMessageParagraph()` call sites named above; that no server-side output anywhere in the ChatBox response path (the Blade template itself, not the client-side JS it emits) ever echoes `sms.message`-equivalent content unescaped — since the actual vulnerable interpolation lives entirely in client-side JavaScript template literals that PHPUnit cannot execute, this test suite does **not** claim to prove the browser-rendered DOM is XSS-safe at runtime; it proves the unsafe source patterns are gone and the safe ones are present, which is the honest, achievable bar for a PHPUnit-only suite. This distinction is stated explicitly in the test file's own doc-comment, mirroring this section's own honesty requirement.
- **From Correction Round 1 (C/D), preservation proof, source-level:** the response still contains, per path, exactly the structural markers §3.11 documents — `span.avatar.box-shadow-1.cursor-pointer` in the history-load and optimistic-send paths' own source regions, `a.avatar.m-0` in the Echo-listener path's own source region, `class="chat-time` present in the history-load and Echo-listener regions and **absent** from the optimistic-send region, and `max-width:200px; max-height:200px;` present in the optimistic-send region only.
- **New this round (Correction A/B, message-presence semantics, source-level):** the history-load region's `safeMessageParagraph(sms.message)` call site remains guarded by an `if (sms.message)` (or byte-equivalent truthiness) condition; the optimistic-send region's `safeMessageParagraph(messageValue)` call site is **unconditional** — assert no `if (messageValue)`/nonempty guard has been added around it; the Echo-listener region's `safeMessageParagraph(sms.message)` call site remains guarded by `sms.message !== null` specifically — assert it was not replaced with a generic truthiness/nonempty-string condition.
- **New this round (Correction C, child order, source-level):** within the history-load region, the media-insertion call site precedes the message-insertion call site, which precedes the `chat-time` element; within the Echo-listener region, the same media→message→timestamp order holds; within the optimistic-send region, the message-insertion call site precedes the (conditional) media-insertion call site, and no `chat-time` element exists anywhere in that region.

**F. `media_url` attribute safety — corrected this round (Correction D, condition-free typed helper) and Correction Round 1 (Correction D, two-mechanism split):**
- Source-level assertion, history-load and Echo-listener: the response contains `safeTypedMediaParagraph()`'s `.attr('src'` construction, its continued call to `isImageOrVideo(`, contains **no** `null`/`undefined` presence guard of its own, and zero remaining raw `` src="${ `` /string-concatenation `src="` patterns for `media_url`/`sms.media_url` in either path.
- Source-level assertion, optimistic send: the response contains its own separate `.attr('src', response.media_url)` assignment, confirms it is **not** wrapped in an `isImageOrVideo()` call (preserving §3.8/§3.10's own documented asymmetry, not silently fixing it), and confirms the `style="max-width:200px; max-height:200px;"` constraint is still present on that specific `<img>`.
- **New this round (Correction D, media-presence semantics, source-level):** the history-load and Echo-listener regions' own `if (sms.media_url !== null)` guards remain present, unchanged, and wrap the call to `safeTypedMediaParagraph()` (not the reverse); the optimistic-send region's own `if (response.media_url)` guard remains present, unchanged, and continues to wrap its separate inline image-only construction — none of these three conditions has been moved inside a shared helper or replaced with a harmonized rule.

**G. Existing billing/idempotency preservation:**
- `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`'s three `chatbox.reply`-calling tests (§3.9) are updated (their sole required change, §9) to call `route('customer.chatbox.reply', $box->uid)` and re-run, asserting their existing outcomes (`422` for missing/invalid token, the qualifying-chain success proof for a valid token) are unchanged.
- A new test in `ChatBoxSecurityTest.php` (or reused directly from the updated metering test, whichever avoids duplication more cleanly at implementation time) proving a **foreign** `ChatBox`'s `reply()` request — even carrying a syntactically valid `idempotency_token` — is denied before any `business_usage_reservations` row or `reports` row is created (mirroring `test_reply_missing_token_returns_422_and_never_reaches_quicksend()`'s own existing assertion shape: `assertSame(0, DB::table('business_usage_reservations')->count())`), the direct proof of §5.6's own structural closure of the billing/spoofing vector.
- `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`'s existing `test_chatbox_controller_is_the_only_caller_passing_true()` is re-run unmodified and must still pass (exactly 2 matches) — proving this remediation's own refactor did not alter the two `quickSend(..., true)` call sites' source text (§3.9, §5.10).

**H. `messages()` response-serialization preservation — new, human-authorized post-round exception (§5.3.1):** a preservation test, not a new product behavior.
- For an owned `ChatBox`, insert a `chat_box_messages` row directly (not via `ChatBoxMessage::create()`, to avoid the test itself masking the exact defect) with a deterministic `created_at` value (e.g., a fixed datetime string).
- Call `messages($box->uid)` and assert, at minimum: the top-level `status`/`pinned` fields are unchanged from today's shape; the returned message row's own keys are unchanged (`box_id`, `message`, `media_url`, `sms_type`, `direction`, `sending_server_id`, `send_by`, `created_at`, `updated_at`, as the raw `chat_box_messages` table columns already are); `created_at` in the JSON response is the **raw database string representation** (e.g., `"2024-01-01 12:00:00"`-shaped), asserted via an exact string comparison against the value inserted — **not** transformed into an ISO-8601/Carbon-serialized shape (e.g., containing a `T` separator or a `Z`/timezone suffix) — proving retrieval was not silently switched to Eloquent; ordering by `created_at` ascending across multiple inserted rows is unchanged.
- Retain, unmodified, every ownership assertion already required for `messages()` by section **A** above (foreign `uid` denied, nonexistent `uid` denied identically, numeric-primary-key injection cannot resolve the box) — this new test is additive, not a replacement for the existing ownership coverage.

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

1. `app/Http/Controllers/Customer/ChatBoxController.php` — **required**: adds `resolveOwnedChatBox()` (§5.2); changes `messages`, `messagesWithNotification`, `reply`, `delete`, `block`, `pin` signatures from `ChatBox $box`/untyped `$id` to `string $uid` and inserts the authorize-then-resolve pattern (§5.3, §5.5); adds `->where('customer_id', Auth::id())` to `block()`'s `Contacts` query (§5.4); eliminates `messages()`'s unsafe raw `\DB::table('chat_boxes')` lookup, replacing it with `resolveOwnedChatBox()` — **corrected by post-round exception, §5.3.1**: `messages()`'s separate raw `\DB::table('chat_box_messages')` retrieval is *intentionally preserved* unchanged in mechanism (only its `box_id` predicate now sources from the resolver's own `$box->id`), never converted to an Eloquent `ChatBoxMessage::where(...)` call, to preserve the exact JSON date-serialization shape of its success response; adds `$this->authorize('chat_box')` as `loadChatUsers()`'s first statement (§5.5, §5.10). Preserves both `quickSend(..., true)` call sites' exact source text (§3.9, §5.10). `messagesWithNotification()`'s own existing `ChatBoxMessage::where(...)` usage is unaffected by this exception (§5.3.1's own closing note).
2. `resources/views/customer/ChatBox/index.blade.php` — **required**: replaces only the unsafe message-text and media-`src` insertion points across the 3 paths (§3.7) with the `safeMessageParagraph()` (§5.7) and `safeTypedMediaParagraph()`/optimistic-inline (§5.8) mechanisms, preserving each path's own exact presence condition, exact child order, and every other structural fact named in §3.11 exactly (§5.9); no other change.
3. `resources/views/customer/ChatBox/partials/_chat_list.blade.php` — **required**: changes `data-id="{{$chat->id}}"` to `data-id="{{$chat->uid}}"` (§5.1, §3.3) — the exact one-line fix closing the identifier split's client-side half; no other change.
4. `tests/Feature/Security/ChatBoxSecurityTest.php` — **required, new file**: the dedicated focused security suite (§7).
5. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` — **required, existing file, narrowly modified**: changes the 3 `route('customer.chatbox.reply', $box->id)` call sites (lines 1283, 1302, 1334 at this contract's own drafting-time head) to `route('customer.chatbox.reply', $box->uid)` (§3.9) — the sole existing-test modification this remediation's own identifier-contract change makes unavoidable; no other line in this file changes.

**Explicitly not included, with exact reasons:**
- `resources/views/customer/ChatBox/_sidebar.blade.php` — **corrected justification this round (Correction A/G):** its pinned-list `data-id` already carries `uid` (§3.11), and its `data-box-id` (numeric `id`) is the non-authoritative Echo-correlation value §5.1 explicitly authorizes retaining, not a defect to fix; no change required.
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
4. `grep -c "DB::table('chat_boxes')"` in `ChatBoxController.php` → zero (confirms the unsafe raw `chat_boxes` lookup is eliminated from `messages()`, §5.3/§5.3.1). **Corrected by post-round exception**: this does **not** extend to `chat_box_messages` — `grep -c "DB::table('chat_box_messages')"` in `ChatBoxController.php` → exactly **1**, present and unchanged, scoped to `messages()`'s own method body; `messages()`'s own method body contains **zero** occurrences of `ChatBoxMessage::where` (that call remains exclusive to `messagesWithNotification()`); `grep -n "where('box_id', \$box->id)"` within `messages()`'s own method body → present, confirming the retained raw query's predicate sources from the resolver's trusted `$box->id`, not the raw client-supplied `$uid`.
5. `grep -n "authorize('chat_box')"` in `ChatBoxController.php` → exactly 10 occurrences (the 4 pre-existing plus the 6 newly added), one per action, each preceding its own `resolveOwnedChatBox()` call where applicable (§5.5).
6. `grep -c "customer_id" ` scoped to `block()`'s own method body → at least 1 (the new `Contacts` predicate, §5.4).
7. `grep -c "data-id=\"{{\\\$chat->id}}\""` in `partials/_chat_list.blade.php` → zero; `grep -c "data-id=\"{{\\\$chat->uid}}\""` → exactly 1 (§5.1, §9 item 3).
8. `grep -c "safeMessageParagraph"` in `index.blade.php` → present, one definition (containing zero presence conditions, §5.7), called from all 3 paths; `grep -c "safeTypedMediaParagraph"` → present, one definition (containing zero presence conditions, §5.8), called from the history-load and Echo-listener paths only (not the optimistic-send path); `grep -c "\`<p>\${sms.message}</p>\`"` and equivalent unsafe-pattern searches for all 3 originally-named sites (§3.7) → zero remaining. From Correction Round 1 (C/D): `grep -c "class=\"chat-time"` in `index.blade.php` → exactly 2 occurrences (history-load and Echo-listener paths only, §3.11); `grep -c "max-width:200px; max-height:200px;"` → exactly 1 (optimistic-send path only); `grep -c "a.avatar.m-0\|class=\"avatar m-0\""` → present, scoped to the Echo-listener path only; both `alt="media"` (history load) and `alt=""` (Echo listener, on its `<img>` branch) remain present and distinct. **New this round (Correction A/B/C/D):** history-load's `safeMessageParagraph(` call site is textually preceded by an `if (sms.message)`-shaped guard within the same region; optimistic-send's `safeMessageParagraph(` call site has no such guard anywhere in its own region; Echo-listener's `safeMessageParagraph(` call site is textually preceded by `sms.message !== null` within its own region; in the history-load and Echo-listener regions, each region's own `safeTypedMediaParagraph(`/media-insertion call site appears before its own `safeMessageParagraph(` call site, which appears before its own `chat-time` element; in the optimistic-send region, `safeMessageParagraph(` appears before the conditional media-insertion block.
9. `preg_match_all('/->quickSend\([^)]*,\s*true\s*\)/', file_get_contents('app/Http/Controllers/Customer/ChatBoxController.php'))` → exactly **2** (§3.9, §5.10 — must remain unchanged from the pre-remediation baseline; this is `QuickSendNonConversationCallersUnaffectedTest.php`'s own existing, unmodified assertion, re-run, not a new search invented here).
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
- **From Correction Round 1 (C/D):** any per-path structural fact named in §3.11 — avatar wrapper markup/type/dimensions, `chat-time` presence/absence, media `alt` text, the optimistic path's `200px` size constraint, the `isImageOrVideo()` usage split between paths — is collapsed, unified, or silently changed as a side effect of the message/media-safety fix.
- **From Correction Round 1 (A):** `data-box-id` is removed from either list view, or is accepted by `resolveOwnedChatBox()` or any controller action as an alternative to `uid`.
- **From Correction Round 2 (A/B):** `safeMessageParagraph()` or `safeTypedMediaParagraph()` contains any `null`/`undefined`/empty-string/truthiness condition of its own — either helper's only job is construction, never a presence decision.
- **From Correction Round 2 (B):** any of the 3 paths' own message-presence condition is changed from its current exact shape (history's truthiness check, optimistic's unconditional call, Echo's strict `!== null` check) — including harmonizing them into one shared condition, or wrapping a shared condition around all 3 calls.
- **From Correction Round 2 (D):** any of the 3 paths' own media-presence condition (`sms.media_url !== null` ×2, `response.media_url` truthiness) is changed, moved inside a shared helper, or harmonized with another path's condition.
- **From Correction Round 2 (C):** the locked child order for any path (§5.7's table) is violated — including a message appended after `chat-time`, a `chat-time` moved before media/message, optimistic media placed before its message, or a `chat-time` introduced into the optimistic path.
- **New this pass, post-round exception:** `messages()`'s `chat_box_messages` retrieval is converted to `ChatBoxMessage::where(...)` (or any other Eloquent-based mechanism) instead of remaining the raw `\DB::table('chat_box_messages')` call with `box_id` sourced from `$box->id` (§5.3.1) — this would silently change the JSON date-serialization shape of `messages()`'s own success response, an unauthorized behavior change.
- **New this pass, post-round exception:** `messagesWithNotification()`'s own existing `ChatBoxMessage::where(...)` usage is altered as a side effect of this exception — it is explicitly out of this exception's own scope (§5.3.1).
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
correction_round: 2
correction_round_is_final: true
post_round_exception_count: 1
post_round_exception_authority: explicit_human
post_round_exception_scope: messages_success_response_serialization_preservation
post_round_exception_is_final: true
```

**This is the final ordinary correction round, plus one human-authorized post-round exception.** The ordinary correction budget (`maximum_correction_rounds: 2`) was exhausted after Correction Round 2 — no further *ordinary* correction round is authorized by this document. **This pass is not Correction Round 3** — it is the single, explicitly human-authorized post-round exception named above (`post_round_exception_count: 1`), scoped exclusively to `messages()`'s response-serialization preservation defect (§5.3.1), mirroring the Slice 5 Contacts/CRM security remediation's own precedent of exactly one human-approved post-round docs-consistency exception. **This single post-round exception is now consumed** (`post_round_exception_is_final: true`) — no second post-round exception is authorized by this document; any further correction requires new, separate human instruction outside this contract's own governance. Implementation of this remediation remains unauthorized. Slice 6's own visual/componentization implementation remains blocked, unchanged from §0/§7. Merging this contract does **not** automatically start the security implementation — a separate, explicit human authorization is required, exactly as every prior round already stated.

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
9. The already-correct `index()`/`loadChatUsers()` scoping is explicitly named as preserved, not silently assumed (§5.10). ✓
10. Routes and models are explicitly evaluated and excluded from the allowlist with stated reasons, not merely omitted (§3.4, §9). ✓
11. The future implementation allowlist is closed, numbered, exactly 5 items, with an exact stop threshold of 6 (§9). ✓
12. No phrase equivalent to "implementation may decide," "choose whichever works," or "where appropriate" appears anywhere a mechanically decidable security architecture question is addressed (§5). ✓
13. The non-blocking optimistic-video-rendering issue is named and explicitly not repaired, with a stated reason (§3.10, §11). ✓
14. `docs/automation/AI-AUTONOMY-STATE.json` untouched. ✓
15. This document remains the only file changed on this branch (§2). ✓
16. No implementation authorization is granted anywhere in this document. ✓
17. **Correction Round 1, this round.** Every one of the seven corrections (A–G) named in the Correction Round 1 summary paragraph is applied at its precise, mechanically-identified location — confirmed by a mechanical final sweep (direct `grep` for every stale phrase named in the correction instructions, run before this document was finalized): zero remaining "never again appears in client-visible markup" claim; zero remaining "all 3 media paths use `isImageOrVideo()`" claim; zero remaining reference to the optimistic path as `isImageOrVideo()`-typed; zero remaining universal-`buildChatBubble()` requirement outside the §3.11 explanatory text describing what the original design got wrong; zero remaining claim that the optimistic path gains `chat-time`; zero remaining description of the Echo-listener avatar as `span`/`36×36`; zero remaining omission of the optimistic `200px` constraint or either path's media `<p>` wrapper; zero remaining "403 vs. 404 indistinguishable" framing. ✓
18. Correction Round 1 consumed exactly one of the two available correction rounds; Correction Round 2 consumed the second and final one (`correction_round: 2`, `correction_round_is_final: true`, §12) — the ordinary correction budget was exhausted at that point. ✓
19. No architectural conclusion preserved from Correction Round 1 — the canonical `uid` identifier, `data-box-id`'s retained role, `resolveOwnedChatBox()`, the authorize-before-resolve ordering, the `block()`/`Contacts.customer_id` scope fix, the zero route/model/config changes, the 3-line `ConversationsPlainSmsMeteringTest.php` update, the exact 2 `quickSend(..., true)` call sites, the exact 5-item allowlist — is reopened, narrowed, or extended by this round. ✓
20. **Correction Round 2, this round.** Every one of the six corrections (A–F) named in the Correction Round 2 summary paragraph is applied at its precise, mechanically-identified location — confirmed by a mechanical final sweep (direct `grep` for every stale phrase named in the correction instructions, run before this document was finalized): zero remaining shared nonempty/null condition inside `safeMessageParagraph()`/`safeTypedMediaParagraph()`; zero remaining claim that all 3 paths share one message-presence rule; zero remaining description of the optimistic message `<p>` as conditional; zero remaining description of Echo's empty-message case as omitted; zero remaining description of history's message as unconditional; zero remaining unspecified child order; zero remaining possibility of history/Echo's timestamp preceding message/media, or optimistic's media preceding message; zero remaining media-presence condition moved into a harmonizing helper; zero remaining "equivalent implementation-time choice" language that could change presence/order behavior; zero remaining `correction_round: 1`/`correction_round_is_final: false`. ✓
21. §3 (audit facts, §1 item 7's re-verification), §5 (architecture, §5.7–§5.9), §6 (preservation), §7 (tests), §9 (allowlist), §10 (mechanical searches), §11 (stop conditions), §12 (governance), and §13 (this self-audit) all agree on the same per-path presence conditions, the same child order, and the renamed `safeMessageParagraph()`/`safeTypedMediaParagraph()` helpers — no section contradicts another. ✓
22. **Human-authorized post-round correction exception, this pass.** Mechanically re-verified against direct source (§1 item 8): `ChatBoxMessage` declares no explicit `created_at`/`updated_at` casts, confirming the exact serialization risk named. The fix (§5.3.1) is stated as exact code, not a vague "preserve behavior" instruction. This is **not** Correction Round 3 — `correction_round` remains `2`, `correction_round_is_final` remains `true` (§12); the exception is tracked independently (`post_round_exception_count: 1`, `post_round_exception_is_final: true`) and does not reopen any conclusion from either ordinary correction round (§0's own post-round-exception paragraph). Implementation remains unauthorized; Slice 6 visual implementation remains blocked. ✓
23. This document remains the only file changed on this branch, across all four commits (original drafting pass, Correction Round 1, Correction Round 2, this post-round exception, §2). ✓

---

## 14. Verification and publication

1. `git diff --check` — clean.
2. `git status --short` — exactly ` M docs/automation/DESIGN-SYSTEM-M2-SLICE-6-CHATBOX-SECURITY-REMEDIATION-CONTRACT.md` before staging (a modification to the existing tracked file, not a new untracked file, since this pass continues the existing branch rather than creating a new one).
3. `git diff --name-only c5320611a65b9fd97a7287542d4de11dd96822e0...HEAD` — exactly one path: `docs/automation/DESIGN-SYSTEM-M2-SLICE-6-CHATBOX-SECURITY-REMEDIATION-CONTRACT.md`, aggregated across all four commits on this branch (original drafting pass, Correction Round 1, Correction Round 2, this post-round exception).
4. Stage the one file by its exact path only (never `git add -A`/`.`).
5. Commit exactly: `docs: preserve ChatBox message response serialization`.
6. Push to the existing `origin chore/design-system-m2-slice6-chatbox-security-contract` branch — normal push, never forced, no new branch created.
7. Do not open or merge a new implementation PR. If there is still no contract PR and `gh` is unavailable, return the same GitHub comparison URL as before.
8. **Do not merge. Do not begin this remediation's implementation. Do not begin Slice 6's visual implementation. Do not begin Slice 4, Slice 7a/Campaigns, or any other slice/initiative.** No test is run for this docs-only change.

---

*End of Design System M2 Slice 6 ChatBox Security Remediation Contract, Correction Round 2 of a maximum of 2 (final ordinary round) plus one human-authorized post-round correction exception (`post_round_exception_count: 1`, `post_round_exception_is_final: true`) — not Correction Round 3. Implementation requires a separate, explicit human instruction. This contract's own merge does not start or resume it. Slice 6's own visual implementation remains blocked until this remediation's own implementation is human-merged and its exact merge SHA is pinned in Slice 6's later, separate implementation authorization.*
