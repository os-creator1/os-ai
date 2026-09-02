# Design System M2 Slice 5 — CRM Security Remediation Contract

**Status: contract only. No implementation has occurred under this document. Implementation requires its own separate, explicit human authorization. Merging this contract does NOT satisfy the Slice-5 prerequisite named in `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` §0/§7 — that prerequisite is satisfied only when this remediation's own implementation is separately human-merged (§23).**

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice5-crm-security-remediation-contract`, in the isolated linked worktree `../design-system-m2-slice5-security-remediation-contract-worktree`, based on `origin/main` at `0b8e44bbffb431d223b27066e8efd3dedfee032d` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` at the start of this drafting pass. This SHA is the merge of PR #175 (`Merge pull request #175 from os-creator1/chore/design-system-m2-slice5-contacts-crm-contract`), which merged the Design System M2 Slice 5 (Contacts & CRM) contract itself, including both its correction rounds and its human-approved post-round consistency exception.
- Neither this contract's file nor its branch existed before this pass — confirmed via `git cat-file -e origin/main:docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-REMEDIATION-CONTRACT.md` (fails) and `git ls-remote --heads origin chore/design-system-m2-slice5-crm-security-remediation-contract` (empty) before this branch was created.
- This is the mandatory prerequisite named by the merged Slice-5 contract's §0/§7: *"a separate, dedicated CRM tenant-isolation security-remediation contract must be drafted, human-reviewed, and merged, and its own implementation must be human-merged, before Slice 5 implementation may be authorized."* This document is that contract. It does not authorize Slice 5 visual implementation, does not authorize its own implementation, and does not authorize any other RFC or initiative.
- `maximum_correction_rounds: 2` applies to this contract. `advance_automatically: false`. `start_automatically_after_contract_merge: false` (§23).
- This contract's own scope is strictly: (1) customer-portal tenant-isolation for Contacts/ContactGroups/Blacklists (the defect the merged Slice-5 contract's §3.8 documented), (2) preserving admin Blacklists' intentional global model, and (3) the disposition of the two stored-XSS-shaped findings the merged Slice-5 contract's §3.8/§7 required this remediation to resolve. It does not become a whole-application security audit (§21).
- Drafting this contract makes **zero** application changes. Only the one new file named in §2 is touched by this branch.

---

## 1. Mandatory preflight — verified

1. `git fetch origin --tags` — `origin/main` confirmed at exactly `0b8e44bbffb431d223b27066e8efd3dedfee032d`.
2. Confirmed this is the merge of PR #175, and confirmed `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` exists on this exact base and was read in full before drafting.
3. Also read in full before drafting: `CLAUDE.md`, `AGENTS.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-DASHBOARD-SECURITY-REMEDIATION-CONTRACT.md` (the direct governance-shape precedent for this document — its audit methodology, fail-closed/response-semantics discipline, and test-fixture-authorization pattern are reused here; its specific implementation assumptions — three unauthenticated dashboard controllers, a `chat_box`/`manage ai_settings` permission split — are not, since this contract's own defect shape is materially different: existing authentication and permission checks are present throughout the CRM surface, but record-ownership verification is what is missing).
4. Delegated the exhaustive mechanical audit to three parallel, isolated-worktree research agents (`ContactsController` full audit; `BlacklistsController`/shared-caller audit; XSS-trace and auth-response-semantics audit), with the most consequential findings — the `ContactGroupFields` route-binding key, the `Handler.php` exception-rendering behavior including its `wantsJson()` branch, the `message_form`/`sms_form` enum values, and the existence of a separate API-layer surface sharing the same repository — independently re-verified first-hand in this same drafting pass, not taken on the agents' word alone.

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-REMEDIATION-CONTRACT.md` (this document). No `resources/`, `app/`, `database/`, `routes/`, `tests/`, or `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting this contract.

---

## 3. Mandatory repository audit — findings

All findings below are from direct repository inspection at `origin/main` `0b8e44bbffb431d223b27066e8efd3dedfee032d`, this drafting pass — file:line citations throughout, independently verified, not summarized from any prior document's claims.

### 3.1 The route-model-binding mechanism — the structural root cause

`app/Models/ContactGroups.php:239-245`:
```php
public function getRouteKeyName(): string
{
    return 'uid';
}
```
No `resolveRouteBinding()` override, no `addGlobalScope()` call anywhere in the model's `boot()` method (lines 68-93 — only a `uid`-uniqueness `creating` callback, a `createDefaultFields()` `created` callback, and a cascading `deleted` callback, none tenant-aware). A repository-wide grep for `addGlobalScope|resolveRouteBinding|resolveChildRouteBinding` across `app/` returns **zero matches**. `app/Http/Controllers/Customer/CustomerBaseController.php` (the direct parent of `ContactsController`) has no constructor, middleware, or binding hook of any kind (36 lines, full file read).

`app/Models/Blacklists.php` binds identically, via the shared `App\Library\Traits\HasUid` trait (`app/Library/Traits/HasUid.php:42-45`, `getRouteKeyName() { return 'uid'; }`) — no override, no global scope.

`app/Models/ContactGroupFields.php:16` also `use HasUid;` — **confirmed directly this pass, correcting an initial audit-agent miscount that missed the trait-provided method** — so `ContactGroupFields`'s implicit route binding is likewise by `uid`, not the default `id`.

**Conclusion:** no existing mechanism anywhere in this application scopes `{contact}`, `{blacklist}`, or `{field_id}` route-model binding to the authenticated customer. Any authenticated customer holding the relevant permission string can bind these parameters to **any** other customer's row by supplying its `uid` in the URL/request body.

UIDs are generated via `uniqid()` (`HasUid::generateUid()`, `app/Library/Traits/HasUid.php:27-30`) — a microtime-derived, non-cryptographically-random value. This is a secondary, lower-severity observation (UID guessing/enumeration is a distinct concern from the ownership-check gap this contract remediates) — noted for completeness, not remediated here (§21).

### 3.2 `Customer\ContactsController` — exhaustive per-action audit

The full controller (`app/Http/Controllers/Customer/ContactsController.php`, 2022 lines) was read in full and every one of its 43 public actions was individually traced. Full findings are preserved in the audit transcript; the conclusions below are the load-bearing facts this contract's remediation design (§8, §9, §10) depends on.

**Every action that type-hints `ContactGroups $contact`** (implicit binding, §3.1) **has no ownership predicate on that parameter**, across all of: `show`, `activeToggle`, `copy`, `update`, `destroy`, `searchContact` (protected only coincidentally, see below), `exportContact`, `importContact`, `storeImportContact`, `storeImportFile`, `importProcessData`, `batchActionContact`, `createContact`/`storeContact`, `updateContactStatus`, `editContact`/`updateContact`, `deleteContact`, `getMessageForm`, `message`, `optInKeyword`/`optOutKeyword`/`deleteOptInKeyword`/`deleteOptOutKeyword`, `contactSampleField`, `deleteContactField`, `storeContactField`, `pasteText`, `importMapping`/`importRun`/`importValidate`, `downloadFailedContacts`. This is 27 of the 43 actions — every non-list, non-public action in the controller.

**Ten actions have no permission check of any kind** (no `$this->authorize()` call, no FormRequest gating), compounding the ownership gap — confirmed by direct read of each method body: `importProcessData`, `getMessageForm`, `optInKeyword`, `optOutKeyword`, `deleteOptInKeyword`, `deleteOptOutKeyword`, `importMapping`, `importRun`, `importValidate`, `countContacts`, `downloadFailedContacts` (11 total, one more than initially flagged — `downloadFailedContacts` also lacks an `authorize()` call, confirmed on re-check).

**Two actions permit arbitrary dynamic-attribute access on the bound `ContactGroups` model**, independent of the ownership gap: `getMessageForm()` (`ContactsController.php:1376`) does `$contact->{$request->input('sms_form')}` — an unwhitelisted dynamic property **read**; `message()` (`ContactsController.php:1392-1393`) does `$contact->{$request->input('message_form')} = $request->input('message'); $contact->save();` — an unwhitelisted dynamic property **write**. Direct re-verification this pass confirms the only two legitimate values these fields ever take, from `resources/views/customer/contactGroups/_message.blade.php:14-18`'s own `<select name="message_form">` options and the identically-named `sms_form` value `show.blade.php:354` reads from that same select's current value: **`signup_sms`, `welcome_sms`, `unsubscribe_sms`** — no other value is ever legitimately submitted by the existing UI.

**A second, independent IDOR exists in the batch copy/move `target_group` resolution**, distinct from the primary route-binding defect: `ContactsController.php:1124-1137` (copy) and `:1150-1163` (move), verbatim:
```php
$target_group = $request->get('target_group');
$group        = ContactGroups::where('uid', $target_group)->first();
```
— unscoped by `customer_id`, entirely independent of whichever `$contact` the batch action's own route parameter resolved to.

**Two independent "Contacts query" defect shapes exist**, both real:
- **Pattern A** — `where('group_id', $contact->id)` with no `customer_id` filter at all (e.g. `EloquentContactsRepository::updateContactStatus()` line 330, `::contactDestroy()` line 415, every `batchContact*` method — §3.3). Exploitable directly whenever the parent `$contact` is foreign, since nothing else gates it.
- **Pattern B** — `where('customer_id', Auth::id())` ANDed with `where('group_id', $contact->id)` (e.g. `searchContact()` lines 562/571/580/587, `editContact()`/`updateContact()` lines 803/852). This happens to return zero rows for a genuinely cross-tenant `$contact` **only because** `Contacts.customer_id` is set once, at creation time, to the group's true owner (`EloquentContactsRepository::createContactFromRequest()` line 685: `$subscriber->customer_id = $contactGroups->customer_id;`) — **coincidental protection, not a designed check**, and it is directly undermined by `storeImportContact()` (`ContactsController.php:959-960`), which inserts new `Contacts` rows with `'customer_id' => Auth::user()->id` (the attacker) while `'group_id' => $contact->id` (the victim's group) — a genuine customer_id/group_id mismatch that breaks the very invariant Pattern B relies on.

**Mass-assignment note, discovered but explicitly out of this contract's remediation scope (§21.3):** `EloquentContactsRepository::store()` (lines 63-67) sets the new group's `customer_id` from a client-supplied `$input['user_id']` field if present, instead of always using `auth()->user()->id`. This is a real, separate attribute-tampering primitive (misattributing a *newly created* group, not accessing existing victim data) — this contract does not remediate it, for the reason given in §21.3.

### 3.3 Batch-action repository methods — verbatim, unscoped

`app/Repositories/Eloquent/EloquentContactsRepository.php`, confirmed verbatim, no `customer_id`/tenant filter in any of the six:

```php
// lines 158-171 — batchDestroy (ContactGroups)
public function batchDestroy(array $ids): bool
{
    DB::transaction(function () use ($ids) {
        if ($this->query()->whereIn('uid', $ids)->delete()) { return true; }
        throw new GeneralException(__('locale.exceptions.something_went_wrong'));
    });
    return true;
}
// lines 180-193 — batchActive (identical shape, ->update(['status' => true]))
// lines 202-215 — batchDisable (identical shape, ->update(['status' => false]))
```
```php
// lines 536-546 — batchContactDestroy
public function batchContactDestroy(ContactGroups $contactGroups, array $ids): bool
{
    $status = Contacts::where('group_id', $contactGroups->id)->whereIn('uid', $ids)->delete();
    ...
}
// lines 436-450 — batchContactSubscribe, lines 460-474 — batchContactUnsubscribe (identical group_id-only shape)
// lines 484-501 — batchContactCopy, lines 511-526 — batchContactMove (group_id-only for the source; target_group resolved unscoped in the controller, §3.2)
```

`EloquentContactsRepository`'s interface (`ContactsRepository`) is also injected into `app/Http/Controllers/API/ContactsController.php` and `app/Http/Controllers/API/ContactsHTTPController.php` — a separate, Sanctum-token-authenticated API surface (`routes/api.php`, `auth:sanctum` middleware, confirmed via `app/Providers/RouteServiceProvider.php:110`). **Directly re-confirmed this pass, by exact method-name grep across `app/`:** none of the six batch methods quoted above, nor `store()`/`update()`/`destroy()`/`contactDestroy()`/`updateContact()`/`updateContactStatus()`, are called from anywhere except `Customer\ContactsController.php` — the API controllers call these repository methods too, but only their own separate action methods (`show`, `update`, `destroy`, `storeContact`, `searchContact`, `updateContact`, `deleteContact`, `allContact`, `index`, `store`), which this contract does not modify (§21.1). **This confirms controller-level ownership scoping (filtering IDs/verifying ownership before calling into these repository methods, leaving the methods themselves untouched) cannot regress the API surface**, since this contract touches zero repository code.

### 3.4 `Customer\BlacklistsController` and `Admin\BlacklistsController` — exhaustive per-action audit

Both controllers (245 and 265 lines respectively) read in full, along with `app/Repositories/Eloquent/EloquentBlacklistsRepository.php` (136 lines, full) and `app/Models/Blacklists.php` (62 lines, full).

**Customer controller** — `search()`/`store()` are correctly scoped (`Blacklists::where('user_id', Auth::user()->id)`, and the repository's `store()` sets `user_id = auth()->user()->id` server-side, not client-supplied). **`destroy(Blacklists $blacklist)`** (lines 185-204) and **`batchAction()`** (lines 215-243) have **no ownership predicate** — the route-model-bound `$blacklist` (§3.1) is deleted directly, and `batchAction`'s `$ids` (raw from `$request->get('ids')`) are passed straight to the unscoped repository method below.

**Admin controller** — `index`/`search`/`export` are **intentionally, correctly global** (no `user_id` filter, by design — the merged Slice-5 contract's §3.8 already established this is not a defect). `destroy`/`batchAction` call the **exact same two shared, unscoped repository methods** the customer controller calls — this is correct/intended for admin (an admin should be able to delete any tenant's entry) but is the reason a repository-internal fix is unsafe (it would need to distinguish caller context) and a controller-level fix is not.

**Repository methods, verbatim** (`app/Repositories/Eloquent/EloquentBlacklistsRepository.php`):
```php
// lines 104-115 — destroy
public function destroy(Blacklists $blacklists)
{
    $contact = Contacts::where('phone', $blacklists->number)->first();
    $contact?->update(['status' => 'subscribe']);
    if (! $blacklists->delete()) { throw new GeneralException(...); }
    return true;
}
// lines 123-133 — batchDestroy
public function batchDestroy(array $ids): bool
{
    DB::transaction(function () use ($ids) {
        if ($this->query()->whereIn('uid', $ids)->delete()) { return true; }
        throw new GeneralException(...);
    });
    return true;
}
```
**A previously-undocumented side effect, confirmed this pass:** `destroy()`'s own `Contacts::where('phone', $blacklists->number)->first()` re-subscribe step is **also unscoped by `customer_id`** — deleting one tenant's blacklist entry can flip **any** tenant's `Contacts` row matching that phone number back to `'subscribe'`, if one exists. This is folded into this remediation's own fix for `destroy()` (§11), since it is the same method and the same root cause (an unowned `Blacklists` row being acted on).

**Exhaustive shared-caller search, confirmed this pass:** `destroy()` and `batchDestroy()` on `BlacklistsRepository`/`EloquentBlacklistsRepository` have **exactly four call sites in the entire `app/` tree** — the two customer-controller sites (need the fix) and the two admin-controller sites (must keep their exact current, unscoped behavior). No Job, no Command, no API controller calls either method. This confirms a controller-level fix in `Customer\BlacklistsController` alone cannot regress the admin path, since the repository methods themselves are not modified.

### 3.5 Public subscribe/unsubscribe forms — confirmed unaffected, must stay untouched

`routes/public.php` lines 6-7, 114-115 (`contacts.subscribe_url`, `insertContactBySubscriptionForm`, `contacts.unsubscribe_url`, `postUnsubscribeURL`) — confirmed `web`-only middleware (`RouteServiceProvider.php:63-65`), no `auth`. All four bind `ContactGroups $contact` too, but **none of the four is remediated by this contract** — they are intentionally, correctly public (any holder of a group's own subscribe/unsubscribe link may act on it without authentication; that is the feature, not a defect).

`insertContactBySubscriptionForm()`'s only `Blacklists` interaction is a **read-only**, cross-tenant-unscoped **existence check** — `Contacts::isListedInBlacklist()` (`app/Models/Contacts.php:125-128`) → `Blacklists::where('number', $this->phone)->exists()` — a boolean-only leak (does *any* tenant's blacklist contain this number), not a data-exposure or mutation. `postUnsubscribeURL()` touches only the `Contacts` table's `status` column, never `Blacklists`, confirmed by full-method read. **Neither public action calls `EloquentBlacklistsRepository::store()`, `::destroy()`, or `::batchDestroy()`.** This contract's remediation (§8, §9, §11) touches none of the four public actions' method bodies.

### 3.6 XSS finding #1 — `contactGroups/show.blade.php` SweetAlert2 — disposition: exploitable, remediate

Four occurrences, all in the single `page-script` block: bulk-copy (`show.blade.php:919-928`), bulk-move (`:1009-1018`), add-opt-in-keyword (`:1176-1185`), add-opt-out-keyword (`:1250-1259`) — each of the shape:
```blade
let array = {!! $contact_groups !!}, options;
$.each(array, function (key, value) {
    options = `${options}<option value="${value.uid}">${value.name}</option>`;
});
let html = `<select id="my-select2">${options}</select>`;
Swal.fire({ ..., html: html, ... });
```
`$contact_groups`/`$remain_opt_in_keywords`/`$remain_opt_out_keywords` are Eloquent Collections; Blade's `{!! !!}` triggers `Collection::__toString()` → `toJson()` → `json_encode($this->jsonSerialize(), 0)` — **default flags, no `JSON_HEX_*`**, so `<`/`>`/`'` survive verbatim into the inline `<script>` block. `ContactGroups.name` (`app/Models/ContactGroups.php`) has no accessor/mutator; its only validation (`app/Http/Requests/Contacts/NewContactGroup.php`/`UpdateContactGroup.php`) is `required` + per-customer uniqueness — **no character-class restriction**. `Keywords.keyword_name` is likewise only `required|max:50` (`app/Http/Requests/Keywords/StoreKeywordsRequest.php:29`), and customers can self-service create keywords (`routes/customer.php:170`). The resulting JS string is assigned directly to SweetAlert2's `html:` option, which sets it as the popup's `innerHTML` — no `.textContent`, no client-side escaping helper. Repository-wide search for `Content-Security-Policy` returns zero matches — no CSP mitigation exists anywhere in the app.

**Blast radius, confirmed:** `$contact_groups` (line 267: `...->where('customer_id', auth()->user()->id)...`) and both keyword lists (`Keywords::where('user_id', $contact->customer_id)`, where `$contact` is the currently-viewed, already-owned group) are always scoped to the **currently authenticated customer's own tenant** — this is genuinely self-XSS under the ordinary customer/tenant model, not cross-tenant. A confirmed escalation path exists via this application's admin "login as customer" impersonation capability (present in `EloquentUserRepository.php`/`EloquentAccountRepository.php`/`Admin\CustomerController.php`) — if an admin impersonates a customer whose group/keyword names carry a payload, it executes in the admin's browser during that impersonation session. **Disposition, locked: remediate directly** (not accepted as residual self-XSS risk) — the fix is narrow, low-risk, and closes the admin-impersonation escalation path, which is a materially higher severity than pure self-XSS.

**Exact remediation:** replace the four `{!! $var !!}` occurrences with HTML-safe JSON, using Laravel's own built-in `Illuminate\Support\Js::from($var)` helper (already available in this Laravel version — no new dependency), which applies `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP` and wraps the result in `JsonEncodingException`-safe form specifically designed for embedding inside a `<script>` block. No JS-side logic, no data shape, no visible UI behavior changes — only the four raw-output directives change from `{!! $contact_groups !!}` / `{!! $remain_opt_in_keywords !!}` / `{!! $remain_opt_out_keywords !!}` (×2) to `{!! Js::from($contact_groups) !!}` / equivalents. `Js::from()` still emits valid, parseable JS that `$.each(...)` consumes identically — the fix is purely in how special characters inside `name`/`keyword_name` are encoded, not in the data shape or control flow.

### 3.7 XSS finding #2 — admin Blacklists DataTables — disposition: exploitable against an admin, remediate

`app/Http/Controllers/Admin/BlacklistsController.php::search()`, lines 109-116, verbatim:
```php
if ($blacklist->user->is_admin) {
    $assign_to = $blacklist->user->displayName();
} else {
    $customer_profile = route('admin.customers.show', $blacklist->user->uid);
    $customer_name = $blacklist->user->displayName();
    $assign_to = "<a href='$customer_profile' class='text-primary mr-1'>$customer_name</a>";
}
```
assigned to `$nestedData['user_id']` (line 121) and `json_encode()`d with default flags (line 136) — identical unescaped-JSON mechanism to §3.6. `User::displayName()` (`app/Models/User.php:201-204`) is raw `$this->first_name . ' ' . $this->last_name` concatenation, no escaping, no accessor. `resources/views/admin/Blacklists/index.blade.php`'s DataTables `columns` array (lines 148-156) has **no `render`/escape override on the `user_id` column** (index 4) — confirmed only columns 0/1/2/-1 have any `columnDefs` entry — so DataTables inserts the value as raw HTML by its documented default.

**Confirmed exploitable against an admin**, via a genuine bypass of this app's own name-validation gate: every manual registration/profile-edit/admin-create path enforces a `first_name`/`last_name` regex (`/^[\pL\s\-\'\.]+$/u`, blocking `<`/`>`/`"`) — but `EloquentAccountRepository::findOrCreateSocial()` (lines 148-188, the Socialite OAuth login/registration handler) writes `first_name`/`last_name` directly from the OAuth provider's own profile data (`$data->getName()`/`getNickname()`) with **zero validation**, bypassing the regex entirely. Any customer who can OAuth-register with a crafted display name, and who has (or creates) at least one blacklist entry, can have that payload execute in **any admin's** browser the moment that admin views `GET /admin/blacklists` — a materially higher-severity finding than §3.6, since the victim is an admin, not the attacker's own session. **Disposition, locked: remediate directly.**

**Exact remediation:** separate the trusted structural `<a>` wrapper from the untrusted name content — `$customer_profile` (a server-generated `route()` URL) and `$customer_name` are both passed through `e()` before interpolation:
```php
$assign_to = "<a href='" . e($customer_profile) . "' class='text-primary mr-1'>" . e($customer_name) . "</a>";
```
and the `is_admin` branch becomes `$assign_to = e($blacklist->user->displayName());`. No structural HTML, no link functionality, no column configuration, and no DataTables initialization changes — only the two raw-concatenation lines gain `e()`.

### 3.8 Auth/authorization response semantics — mechanical, not assumed

`app/Exceptions/Handler.php::render()` (lines 67-103), full method confirmed directly this pass:
```php
public function render($request, Throwable $exception)
{
    if ($request->wantsJson()) {
        return response()->json(['status' => 'error', 'message' => $exception->getMessage()]);
    }
    if (config('app.env') != 'local') {
        if ($exception instanceof ViewException || $exception instanceof ModelNotFoundException) {
            return response()->view('errors.500', compact('exception'), 500);
        }
        if ($exception instanceof AuthenticationException || $exception instanceof AuthorizationException) {
            return response()->view('errors.401', compact('exception'), 401);
        }
        if ($exception instanceof TokenMismatchException) { return response()->view('errors.419', ..., 419); }
        if ($exception instanceof HttpException) { return response()->view('errors.404', compact('exception'), 404); }
    }
    if ($exception instanceof GeneralException) { return response()->json(['status' => 'error', 'message' => ...]); }
    return parent::render($request, $exception);
}
```
Three facts, all load-bearing for this contract's own remediation design (§9, §14) and all independently re-confirmed by direct file read this pass, not assumed from any prior contract's own findings:

1. **`AuthenticationException`/`AuthorizationException` → HTTP 401** via `errors.401`, whenever `config('app.env') != 'local'` — unchanged from the Slice-3 precedent's own finding, still true today.
2. **`ModelNotFoundException` is intercepted at line 78, *before* Laravel's own default 404 conversion, and rendered as `errors.500` / HTTP 500 — not 404.** This is the single most important mechanical finding for this contract's own design: a naive `ContactGroups::where('uid', $uid)->where('customer_id', Auth::id())->firstOrFail()` would produce **500**, not 404, for both the "nonexistent" and (if reached) "foreign" cases — contradicting the fail-closed, indistinguishable-response requirement (§9). This repository's own existing, correct precedent (`EloquentOpportunityRepository::findOwned()`, `app/Repositories/Eloquent/EloquentOpportunityRepository.php:70-76`, returning `?Opportunity` via `->first()`, with the calling code doing an explicit `abort(404)` on `null`) is what this contract's own remediation reuses (§9) — never `firstOrFail()`.
3. **`$request->wantsJson()` is checked *first*, before any exception-type branching, and returns `response()->json([...])` with no explicit status code — defaulting to HTTP 200.** This means for any request Laravel classifies as JSON-wanting (based on its `Accept` header, independent of exception type), **every** exception in this application — `AuthenticationException`, `AuthorizationException`, `ModelNotFoundException`, an explicit `abort(404)`, anything — renders as a 200-status JSON body `{"status":"error","message":"..."}`, not a distinguishing HTTP status code at all. This is a genuine, pre-existing, unrelated-to-this-remediation application convention. **It does not weaken this contract's own core security property**: whichever branch of `Handler::render()` ultimately fires, a foreign-uid and a nonexistent-uid reaching the exact same explicit `abort(404)` call (§9) produce the exact same response either way — the indistinguishability guarantee holds regardless of which Handler.php branch renders it. But the **exact** status code/body for each of the CRM controller's AJAX-vs-HTML-rendering actions must be determined by direct testing at implementation time (§17), never assumed from generic Laravel defaults or copied from the dashboard precedent's own HTML-route-only findings.

`phpunit.xml:33` sets `APP_ENV=testing` (no `.env.testing` file exists) — confirms the test suite exercises the non-`local` branch above, per this app's own existing test (`tests/Feature/Security/HotLeadsSecurityTest.php`'s own docblock already documents this exact convention).

### 3.9 Existing test infrastructure — reused, not reinvented

`tests/Feature/Security/` **already exists** (from the merged Dashboard Security Remediation), containing exactly `HotLeadsSecurityTest.php`, `AiAnalyticsSecurityTest.php`, `AiSettingsSecurityTest.php`. All three share established idioms this remediation's own new tests reuse: `RefreshDatabase`; a `setUp()` that deliberately avoids `EloquentAccountRepository::hasPermission()`'s hardcoded `id === 1` superadmin bypass (§3.10) by using distinct, throwaway, non-id-1 accounts for every test actor; and — where a genuine schema gap exists — a narrowly-scoped, test-only ephemeral-fixture pattern via a `security_test_ddl` named connection (§18). `HotLeadsSecurityTest.php:154-171` contains the exact established "cross-tenant real ID and nonexistent ID must produce the identical response" pattern this contract's own new tests mirror (§17).

### 3.10 Superadmin bypass — restated exactly, not re-derived

`app/Repositories/Eloquent/EloquentAccountRepository.php:203-227`, unchanged from every prior contract's own finding: `hasPermission()` unconditionally returns `true` whenever `$user->id === 1`, before consulting any permission list. This governs every `$this->authorize()`/`can:` check in both `ContactsController` and both `BlacklistsController`s. This remediation does not redesign, narrow, or remove it — it is out of this contract's own bounded scope, inherited unchanged like every other existing Gate consumer (§21).

### 3.11 Discovered-but-explicitly-out-of-scope findings — documented, not remediated

Confirmed directly this pass; each is a genuine, mechanically-confirmed finding, and each is explicitly excluded from this contract's own remediation for the stated reason (§21 restates these as forbidden scope):

- **§3.11.1 — API-layer IDOR.** `app/Http/Controllers/API/ContactsController.php` (Sanctum-token-authenticated, `routes/api.php`) has the **identical** unscoped-`ContactGroups $group_id`/`$contact` binding defect — `show`, `update`, `destroy`, `storeContact`, `searchContact`, `updateContact`, `deleteContact`, `allContact` all lack an ownership check (only `index()` correctly scopes, `ContactGroups::where('customer_id', request()->user()->id)`, line 293). This is a separate controller class, a separate route file, a separate auth guard, and was not named anywhere in this task's own scope (which named `Customer\ContactsController.php` specifically). Fixing it here would silently expand scope beyond what was authorized. **Flagged for a separate, future human decision on whether it needs its own remediation contract.**
- **§3.11.2 — Arbitrary local file-path read in `importMapping()`.** `ContactsController.php:1819`, `$contact->readCsv($filepath)` where `$filepath` is `$request->filepath`, entirely unvalidated — a potential path-traversal/arbitrary-file-read primitive, a different vulnerability class from tenant isolation. This contract's own ownership guard (§9) still applies to `importMapping()`'s `$contact` parameter (closing who may trigger it for which group), but does not validate or constrain `$filepath` itself. **Flagged, not fixed** — redesigning the import file-handling pipeline is explicitly forbidden scope for this contract (§21) and would require its own dedicated audit.
- **§3.11.3 — Mass-assignable `customer_id` on group creation.** `EloquentContactsRepository::store()` lines 63-67 (§3.2) — out of scope because fixing it requires touching a repository method also used by the API layer (§3.11.1), and this contract deliberately touches zero repository code (§3.3, §3.4) specifically to avoid any interaction with that separate, unaudited surface.
- **§3.11.4 — `AiAnalyticsController::updateVariants()`/`admin.ai_variants.update`.** Restated from the merged Dashboard Security Remediation Contract (§3.5 there) purely for completeness — unrelated to CRM, not touched by this contract.

---

## 4. Proven vulnerability statement

Re-confirmed, current `origin/main` `0b8e44bbffb431d223b27066e8efd3dedfee032d`: `Customer\ContactsController` (27 of 43 actions) and `Customer\BlacklistsController` (2 of 6 actions: `destroy`, `batchAction`) check permission strings but not record ownership. Any authenticated customer holding the relevant, ordinary, non-privileged permission (`view_contact_group`, `update_contact_group`, `delete_contact_group`, `view_contact`, `create_contact`, `update_contact`, `delete_contact`, `view_blacklist`, `delete_blacklist` — all real, pre-existing permission strings, confirmed present in `config/customer-permissions.php`) can read, mutate, or delete another tenant's `ContactGroups`, `Contacts`, `ContactGroupFields`, opt-in/opt-out keyword records, or `Blacklists` rows, and — via `target_group` in batch copy/move — inject or exfiltrate contacts across tenant boundaries independent of which group the route itself bound. Two further defects compound this: unwhitelisted dynamic-attribute read/write on `ContactGroups` (`getMessageForm`/`message`), and eleven actions with no permission gate at all. Two stored-XSS-shaped findings (§3.6, §3.7) are both confirmed genuinely exploitable and are resolved by this same contract, per the merged Slice-5 contract's own explicit requirement.

---

## 5. Endpoint/action inventory — summary

Restated from §3.2/§3.4: 27 `ContactsController` actions and 2 `BlacklistsController` actions require an ownership fix; 11 `ContactsController` actions additionally require a missing permission check; 2 `ContactsController` actions require a dynamic-attribute whitelist; 1 batch-action code path requires a second, independent `target_group`-resolution fix. 4 public actions (§3.5) and all correctly-scoped list/search/export actions are unaffected, unchanged, and not touched.

---

## 6. Intended actor matrix

| Surface | Read actor | Write actor | Basis |
|---|---|---|---|
| Customer ContactGroups (`show`/`update`/`destroy`/`activeToggle`/`copy`/batch/import/fields/keywords/message) | Authenticated customer holding the named `*_contact_group`/`*_contact` permission, **and** owning the specific `ContactGroups` row | Same | §3.1, §3.2, §9 |
| Customer Contacts (individual subscriber status/edit/update/delete/create/store/batch) | Same customer, and only within a `ContactGroups` row that customer owns | Same | §3.2, §9 |
| Customer Blacklists (`destroy`/`batchAction`) | Authenticated customer holding `delete_blacklist`, and owning the specific `Blacklists` row(s) | Same | §3.4, §11 |
| Admin Blacklists (`index`/`search`/`export`/`destroy`/`batchAction`) | Authenticated admin holding the corresponding admin permission — **intentionally global**, unchanged | Same | §3.4, §11 |
| Public subscribe/unsubscribe | Anonymous, holder of the group's own public link | Same (self-service only) | §3.5, unchanged |

**Not intentionally dual-access anywhere**: no surface named above is meant to be reachable by both a customer and an admin through the same code path except the two Blacklists repository methods (§3.4), which this remediation deliberately does not modify, precisely to preserve that dual reachability correctly.

**The `$user->id === 1` superadmin bypass (§3.10) is unaffected by, and orthogonal to, this remediation** — under that pre-existing, unmodified bypass, the account with `id === 1` continues to reach every CRM surface regardless of the ownership checks this contract adds, exactly as it does for every other Gate-protected route in the application today. This remediation does not compensate for, narrow, or redesign that bypass (§21).

---

## 7. Exact authorization model — missing permission checks added

The eleven actions confirmed in §3.2 to have **zero** permission gate each gain exactly one `$this->authorize('<permission>')` call, matching the closest analogous, already-gated sibling action's own existing permission string — no new permission string is invented:

| Action | Added permission | Basis (sibling action already using this string) |
|---|---|---|
| `importProcessData()` | `create_contact` | `storeImportContact()` via `ImportContact::authorize()` |
| `getMessageForm()` | `update_contact_group` | `message()` via `UpdateContactGroupMessage::authorize()` (same UI, read-then-write pair) |
| `optInKeyword()` | `update_contact_group` | The tab this action serves (`_opt_in_keywords.blade.php`) is itself gated `@can('update_contact_group')` in `contactGroups/show.blade.php` |
| `optOutKeyword()` | `update_contact_group` | Same reasoning as `optInKeyword()` |
| `deleteOptInKeyword()` | `update_contact_group` | Same reasoning |
| `deleteOptOutKeyword()` | `update_contact_group` | Same reasoning |
| `importMapping()` | `view_contact` | `storeImportFile()` (same import pipeline step) |
| `importRun()` | `view_contact` | Same pipeline |
| `importValidate()` | `view_contact` | Same pipeline |
| `countContacts()` | `view_contact` | `export()`/`exportContact()`'s own gate (a read-only aggregate is no less sensitive than an export) |
| `downloadFailedContacts()` | `view_contact` | `contact.export`'s own gate (downloads customer data) |

No `config/permissions.php`, `config/customer-permissions.php`, `app/Providers/AuthServiceProvider.php`, or Gate-registration path changes — every string above already exists and is already enforced elsewhere in this same controller (§20 item 7 mechanically confirms this).

---

## 8. Exact tenant/data-isolation model — `ContactGroups`

**Because implicit route-model binding cannot itself be safely scoped without breaking the public/API surfaces (§3.1, §3.5, §3.11.1), and because `firstOrFail()`/an uncaught `ModelNotFoundException` renders as HTTP 500 in this application (§3.8), every remediated action's `ContactGroups $contact` (and, for `deleteContactField`, the second bound `ContactGroupFields $field_id`) implicit-binding type-hint is replaced with a raw `string` route-parameter type-hint, and the model is resolved explicitly, once, via one new private helper:**

```php
private function resolveOwnedContactGroup(string $uid): ContactGroups
{
    $contact = ContactGroups::where('uid', $uid)
        ->where('customer_id', Auth::id())
        ->first();

    abort_unless($contact, 404);

    return $contact;
}
```

Every one of the 27 actions named in §3.2/§5 that currently type-hints `ContactGroups $contact` has its signature changed to `string $contact` and gains, as its first statement, `$contact = $this->resolveOwnedContactGroup($contact);` — after which the rest of the method body is unchanged (it already uses `$contact` as a `ContactGroups` instance). This is applied to every remediated action **except** the four public actions (§3.5), which are not touched at all and keep their existing implicit `ContactGroups $contact` binding exactly as-is.

**This guarantees a foreign-but-existing `uid` and a genuinely nonexistent `uid` reach the identical `abort(404)` call, in the identical code path, producing the identical response** — the core fail-closed requirement (§14) — regardless of which `Handler::render()` branch ultimately renders it (§3.8 item 3).

`deleteContactField(ContactGroups $contact, ContactGroupFields $field_id)` additionally becomes `deleteContactField(string $contact, string $field_id)`; after resolving `$contact` via the helper above, the method resolves `$field_id` via a second, analogous scoped lookup (`ContactGroupFields::where('contact_group_id', $contact->id)->where('uid', $field_id)->first()`, `abort_unless(..., 404)`) — closing the previously-unscoped second implicit binding on this one action (§3.2).

**`getMessageForm()`/`message()` dynamic-attribute whitelist:** both actions gain a validation of `sms_form`/`message_form` against the fixed enum `['signup_sms', 'welcome_sms', 'unsubscribe_sms']` (§3.6/§3.2's confirmed exhaustive set of legitimate values) before the dynamic property access — any other value is rejected the same way a missing required field already is (standard `FormRequest`-shaped validation failure), never silently ignored and never permitted through to the dynamic read/write.

**`copy()`/`batchActionContact()`'s `target_group` resolution** (§3.2) gains the identical ownership predicate: `ContactGroups::where('uid', $target_group)->where('customer_id', Auth::id())->first()`, with the existing `'Group not found'`-shaped JSON error response preserved for both a foreign and a nonexistent `target_group` (mirroring §9's own batch fail-closed target below).

---

## 9. Exact tenant/data-isolation model — `Contacts` (individual subscribers) and batch actions

Because every remediated action now resolves `$contact` via `resolveOwnedContactGroup()` (§8) before its own body runs, every `Contacts` query already scoped by `where('group_id', $contact->id)` (Pattern A, §3.2) is now safe by construction — `$contact->id` can never be a foreign group's id inside a remediated action. **This closes Pattern A everywhere it appears (§3.3's six repository methods, plus `updateContactStatus`/`updateContact`/`contactDestroy` in the repository) without any change to those methods' own bodies** — they remain exactly as they are today; only their caller now guarantees the `$contactGroups` argument passed in is pre-verified as owned.

**Pattern B's own coincidental-protection weakness (§3.2) is closed at its source**: `storeImportContact()`'s insert (`ContactsController.php:959-960`) changes `'customer_id' => Auth::user()->id` to `'customer_id' => $contact->customer_id` (now safely the true, verified owner, since `$contact` is resolved via §8's helper) — matching `createContactFromRequest()`'s own already-correct precedent (`EloquentContactsRepository.php:685`) and eliminating the `customer_id`/`group_id` mismatch that undermined every other Pattern-B query.

**Exact mixed-batch fail-closed target, locked, for the ContactGroups-level `batchAction()` (`batchDestroy`/`batchActive`/`batchDisable`) and every `Contacts`-level `batchActionContact()` sub-action:**

Before calling any repository batch method, the controller filters the request's raw `ids`/`uid` array down to **only the subset the authenticated customer actually owns** (and, for `Contacts`-level batches, that additionally exist inside the already-verified-owned `$contact`), via one additional query immediately before the existing repository call — e.g. for the `ContactGroups`-level batch: `$ownedIds = ContactGroups::where('customer_id', Auth::id())->whereIn('uid', $ids)->pluck('uid')->all();`, then `$this->contactGroups->batchDestroy($ownedIds)` (repository method itself unchanged, §3.3). **A foreign id and a nonexistent id are both simply excluded from the filtered set — silently, identically, with no distinguishing signal** — the batch operation still succeeds on whatever subset the customer legitimately owns, and the response shape is unaffected by whether zero, some, or all of the originally-submitted ids were foreign/nonexistent. This is the same "foreign and nonexistent produce the identical, non-distinguishing outcome" principle §8/§14 apply to single-record actions, extended to batch context: exclusion-from-mutation is the batch-shaped equivalent of a single record's `abort(404)`. **This is a deliberate, explicit design choice, not a default** — the alternative (rejecting the entire batch if any id is foreign/nonexistent) is not chosen, because it would let an attacker distinguish "my batch was entirely valid" from "my batch contained at least one foreign/nonexistent id" via the success/failure of the whole operation, a weaker tenancy oracle this design avoids.

`Contacts`-level batch sub-actions (`batchContactDestroy`/`Subscribe`/`Unsubscribe`/`Copy`/`Move`) need no additional id-prefiltering beyond what §8's `$contact` resolution already guarantees, **except** `batchContactCopy`/`batchContactMove`'s own `target_group`, which is scoped per §8's own dedicated fix.

---

## 10. (Reserved — batch-action model fully specified in §9; no separate section needed.)

---

## 11. Exact tenant/data-isolation model — `Blacklists`

**Customer `destroy(Blacklists $blacklist)`** becomes `destroy(string $blacklist)`, resolving via a new, analogous private helper:
```php
private function resolveOwnedBlacklist(string $uid): Blacklists
{
    $blacklist = Blacklists::where('uid', $uid)->where('user_id', Auth::id())->first();
    abort_unless($blacklist, 404);
    return $blacklist;
}
```
called as the first statement, identical reasoning to §8 (avoiding `firstOrFail()`/`ModelNotFoundException`'s 500-status behavior, §3.8). The repository's `destroy()` method itself (§3.4, including its previously-undocumented `Contacts`-resubscribe side effect) is **not** modified — by the time it runs, `$blacklist` is guaranteed owned, closing the side effect's cross-tenant reach as a consequence of the caller-level fix, without touching the repository.

**Customer `batchAction()`** gains the identical id-prefiltering pattern as §9: `$ownedIds = Blacklists::where('user_id', Auth::id())->whereIn('uid', $ids)->pluck('uid')->all();` immediately before the existing, unmodified `$this->blacklists->batchDestroy($ownedIds)` call — same silent-exclusion, no-distinguishing-signal fail-closed target as §9, for the identical reasoning.

**Admin `Admin\BlacklistsController::destroy()`/`batchAction()` are not modified in any way** — they continue calling the exact same, unmodified repository methods with their existing unscoped behavior, which is correct and intentional (§3.4, §6). Admin `index`/`search`/`export` are likewise entirely untouched.

---

## 12. XSS finding #1 — remediation (restated from §3.6, binding)

`resources/views/customer/contactGroups/show.blade.php`'s four `{!! $contact_groups !!}` / `{!! $remain_opt_in_keywords !!}` / `{!! $remain_opt_out_keywords !!}` (×2) occurrences (lines 919, 1009, 1176, 1250) are replaced with `{!! Js::from(...) !!}` (Laravel's built-in helper, no new dependency, HTML-safe JSON per §3.6). No other line in this file changes.

---

## 13. XSS finding #2 — remediation (restated from §3.7, binding)

`app/Http/Controllers/Admin/BlacklistsController.php::search()` lines 109-116: both `$assign_to` assignments gain `e()` around `$customer_profile`/`$customer_name` (link branch) and around the plain `displayName()` call (admin branch), per §3.7's exact quoted replacement. No column configuration, no DataTables initialization, and no other line in `search()` or in `admin/Blacklists/index.blade.php` changes.

---

## 14. Fail-closed behavior — response semantics, verified not assumed

| Condition | Response | Basis |
|---|---|---|
| Unauthenticated request to any customer CRM route | Blocked by `routes/customer.php`'s existing `auth` middleware; `AuthenticationException` → 401 (HTML) or 200-status JSON error body (AJAX, §3.8 item 3) | §3.8, unmodified pre-existing behavior |
| Authenticated, lacks the broad `access_backend` gate | Route-group `can:` middleware throws `AuthorizationException` → same as above | §3.8, unmodified |
| Authenticated, lacks the specific named permission (§7) | Controller's `$this->authorize()` throws `AuthorizationException` → same as above | §3.8, unmodified |
| Actor targets another tenant's real `ContactGroups`/`Contacts`(-parent)/`Blacklists`/`ContactGroupFields` row | `resolveOwnedContactGroup()`/`resolveOwnedBlacklist()`/the analogous `ContactGroupFields` lookup returns no row → `abort(404)` — **HTML routes**: rendered as `errors.404`/404 (`Handler.php` `HttpException` branch, §3.8) **only if** `wantsJson()` is false for that request; **AJAX routes**: 200-status JSON error body, per §3.8 item 3 — the exact shape for each specific action must be confirmed by direct testing (§17), never assumed | §8, §9, §11, §14 |
| Nonexistent `uid` (never existed at all) | Identical code path to the row above — the same `->first()`/`abort(404)` call fires for both, since the query itself finds nothing either way | §8, §9, §11 — this is the core indistinguishability guarantee this remediation's entire binding-resolution design (§8) exists to provide |
| Batch action containing a mix of owned, foreign, and nonexistent ids | Foreign and nonexistent ids are silently excluded from the mutated set (§9, §11); the operation succeeds on the owned subset; response shape does not reveal which/how many ids were excluded or why | §9, §11 |
| `target_group` (copy/move) is foreign or nonexistent | Identical existing `'Group not found'`-shaped JSON error, for both cases | §8 |
| `message_form`/`sms_form` outside the 3-value enum | Standard validation-failure response, no dynamic property access attempted | §8 |
| Malformed/missing required input on any remediated action | Unchanged — standard Laravel `FormRequest`/manual-`Request` validation behavior, not touched by this remediation | Unmodified |

No new response shape, status code, or error-page convention is invented — every entry above is either this application's own existing, verified `Handler.php` behavior, or its existing, unmodified validation behavior.

---

## 15. (Reserved — see §21 for the discovered-but-out-of-scope findings restated as forbidden scope.)

---

## 16. Exact implementation allowlist

**Closed, numbered, path-level, no wildcards, no duplicate path, exactly 7 unique sequential entries. Any additional path required during this remediation's implementation is a required-8th-path-shaped stop condition (§22).**

### Controllers (3 modified)

1. `app/Http/Controllers/Customer/ContactsController.php` — modified: the ownership-resolution helper (§8), 27 action signatures changed from implicit `ContactGroups $contact`/`ContactGroupFields $field_id` binding to `string`-typed parameters resolved via that helper, 11 added `$this->authorize()` calls (§7), the `message_form`/`sms_form` whitelist (§8), the `target_group` ownership fix (§8), the `storeImportContact()` `customer_id` fix (§9), the batch id-prefiltering (§9). Zero route, zero public-action (§3.5), zero unrelated method changes.
2. `app/Http/Controllers/Customer/BlacklistsController.php` — modified: the ownership-resolution helper (§11), `destroy()`/`batchAction()` signature/body changes per §11. Zero route, zero `index`/`search`/`create`/`store` changes.
3. `app/Http/Controllers/Admin/BlacklistsController.php` — modified: exactly the two `e()`-wrapping changes in `search()` per §13. Zero other line changes; `destroy()`/`batchAction()`/`index`/`export` remain byte-identical.

### View (1 modified)

4. `resources/views/customer/contactGroups/show.blade.php` — modified: exactly the four `{!! !!}` → `{!! Js::from(...) !!}` replacements per §12. Zero other line changes.

### New focused security tests (3 new)

5. `tests/Feature/Security/ContactGroupsSecurityTest.php` — new (§17).
6. `tests/Feature/Security/ContactsSecurityTest.php` — new (§17).
7. `tests/Feature/Security/BlacklistsSecurityTest.php` — new (§17).

**Counts** — Controller: **3** (all modified). View: **1** (modified). Test: **3** (all new). **Overall total: 7. Stop threshold: 8** (7 + 1).

No `database/`, `routes/`, `config/`, `app/Http/Requests/`, `app/Repositories/`, or `app/Models/` path is listed above — this remediation authorizes **zero** schema, routing, permission-config, FormRequest, repository, or model changes (§3.3, §3.4, §7, §20). No other Design System Slice-5 view is listed above — this remediation is entirely presentation-neutral outside the one narrow XSS-fix line-range in item 4 (§21).

---

## 17. Exact focused test requirements

Each new file asserts actual HTTP behavior and data outcomes — never a superficial middleware-string assertion — and follows the established idioms in `tests/Feature/Security/` (§3.9): `RefreshDatabase`, distinct non-id-1 actor accounts for every test (never relying on the superadmin bypass, §3.10/§6), and the exact response shape (HTML 404 vs. AJAX 200-status JSON error body, §3.8 item 3/§14) determined by running the assertion against the real application rather than assumed.

1. **`tests/Feature/Security/ContactGroupsSecurityTest.php`**:
   - Own group readable (`show`) / foreign group not readable, identical response to a nonexistent `uid` (§14), and the two are asserted together in one test proving genuine indistinguishability — mirroring `HotLeadsSecurityTest.php:154-171`'s own established pattern (§3.9).
   - `activeToggle`/`copy`/`update`/`destroy` all denied on a foreign group, each proven to leave the victim's row unmutated; each succeeds on the actor's own group (legitimate-actor path preserved).
   - Batch `destroy`/`activeToggle`/`disable`: a batch mixing the actor's own group id with a real foreign group id and a nonexistent id mutates only the owned one, leaves the foreign group's row unmutated, and produces one consistent response regardless of composition (§9).
   - `copy`/`move`'s `target_group`: foreign and nonexistent `target_group` both rejected identically; own `target_group` succeeds.
   - `message_form`/`sms_form` outside the 3-value enum rejected; each of the 3 legitimate values still works end-to-end (legitimate-actor path preserved).
   - **XSS finding #1 regression**: seed a group named `<img src=x onerror=alert(1)>` (and a keyword with an equivalent payload), request `show`, assert the rendered response's inline script contains the HTML-hex-encoded form (`<`-shaped or equivalent `Js::from()` output) and never contains a raw, unescaped `<img` tag — proving the payload cannot execute in this context.
2. **`tests/Feature/Security/ContactsSecurityTest.php`**:
   - Own contact in own group readable/mutable (`status`, `edit`/`update`, `delete`); a contact whose *parent group* is foreign is denied identically to a nonexistent parent (§9's Pattern-A closure).
   - `create`/`store`/import actions cannot target a foreign group — each denied identically to targeting a nonexistent group.
   - The `storeImportContact()` fix specifically: a contact created via this path always has `customer_id` matching the group's true owner, never the caller's own id when those differ (proving the Pattern-B mismatch, §9, cannot recur).
   - Every previously-ungated action (§7's 11 items reachable from this file's own scope: `importProcessData`, `optInKeyword`, `optOutKeyword`, `deleteOptInKeyword`, `deleteOptOutKeyword`, `importMapping`, `importRun`, `importValidate`, `countContacts`, `downloadFailedContacts`) now denies an actor lacking the newly-required permission.
   - `deleteContactField`'s second bound parameter: a field belonging to a foreign group is denied identically to a nonexistent field id.
3. **`tests/Feature/Security/BlacklistsSecurityTest.php`**:
   - Customer: own entry deletion works; foreign entry deletion denied identically to a nonexistent entry, and the underlying row (and, per §3.4's confirmed side effect, any unrelated `Contacts` row's status) is proven unmutated in the foreign case.
   - Customer batch: mixed owned/foreign/nonexistent batch mutates only the owned subset (§11), consistent response regardless of composition.
   - Admin: `index`/`search`/`export` still return all tenants' rows (global visibility genuinely preserved, not accidentally narrowed); `destroy`/`batchAction` still succeed against **any** tenant's entry (admin's legitimate global-delete capability genuinely preserved, proven by seeding two customers' entries and having the admin delete both in the same test run).
   - **XSS finding #2 regression**: seed a user (via the same OAuth-registration code path identified in §3.7, or a direct factory state reproducing its unsanitized-write behavior) with a `first_name` containing `<img src=x onerror=alert(1)>`, create a blacklist entry for that user, request the admin `search()` AJAX response, assert the returned `user_id` field's HTML is properly entity-encoded (`&lt;img` not `<img`) while the trusted `<a href=...>` structural wrapper is still present and points at the correct customer profile URL — proving both that the payload cannot execute and that the legitimate link functionality survives.

No test in this list is a brittle full-page snapshot; every assertion is a targeted status-code/response-body/data-outcome check.

---

## 18. Fixture policy

`contact_groups`, `contacts`, `blacklists`, `contact_group_fields`, `contact_groups_optin_keywords`, `contact_groups_optout_keywords` are long-standing, core Ultimate SMS tables — unlike the Dashboard remediation's `ai_settings`/`ai_box_campaign_map` (which had no tracked migration at all), **no schema-visibility gap is currently known for any table this remediation's tests touch**, and none is expected. **If implementation nonetheless discovers a genuine gap**, the identical, already-established authorization from the merged Dashboard Security Remediation Contract (§14.1 there) applies verbatim: a narrowly-scoped, test-only ephemeral-schema fixture may be created inside the three already-allowlisted test files only (§16 items 5-7), derived only from existing model/migration evidence, gated by a `Schema::hasTable`/`hasColumn` check, cleaned up deterministically, and never a reason to `markTestSkipped()` any of §17's core assertions. No `database/` migration path is authorized by this contract under any circumstance (§16, §20 item 1).

---

## 19. Exact test commands and full-suite regression requirement

Focused (run first):
```
php artisan test tests/Feature/Security/ContactGroupsSecurityTest.php tests/Feature/Security/ContactsSecurityTest.php tests/Feature/Security/BlacklistsSecurityTest.php
```
This run must report **zero skipped and zero failed tests** — identical bar to the Dashboard precedent (§18).

Full-suite regression, against `ultimatesms_testing` only (`AGENTS.md`):
```
php artisan test
```
Identical discipline to the Dashboard precedent: stabilize the local, untracked test environment first; rerun the focused suite until zero-skip/zero-fail; rerun the complete suite; report the exact pass/skip/fail/assertion counts, never estimated. If a pre-existing, unrelated failure cannot be eliminated through environment-only correction, implementation must STOP and report the exact evidence rather than declaring the run clean — acceptance of such evidence for merge is a human governance decision, not one the implementation agent may make itself. **This contract itself does not run either command** — it is a docs-only contract-drafting pass.

---

## 20. Static/mechanical security checks

1. `git diff --stat -- database routes config app/Repositories app/Models app/Http/Requests` compared against §16 (which contains none of these paths) → must be completely empty.
2. `git diff --stat` restricted to paths outside §16's exact 7 → must be completely empty; `git diff --name-only` + `git ls-files --others --exclude-standard` must equal §16's allowlist exactly.
3. `grep -n "ContactGroups \$contact\|ContactGroupFields \$field_id" app/Http/Controllers/Customer/ContactsController.php` → must show these implicit-binding type-hints **only** on the four public actions (§3.5); every other, remediated action must show `string $contact`/`string $field_id` instead.
4. `grep -n "Blacklists \$blacklist" app/Http/Controllers/Customer/BlacklistsController.php` → must show zero matches (replaced by `string $blacklist`, §11); `grep -n "Blacklists \$blacklist" app/Http/Controllers/Admin/BlacklistsController.php` → must be unchanged from this contract's own base SHA (admin controller untouched, §11).
5. `grep -c "firstOrFail(" app/Http/Controllers/Customer/ContactsController.php app/Http/Controllers/Customer/BlacklistsController.php` → must remain **zero** in both, confirming §3.8's `ModelNotFoundException`-avoids-500 requirement was actually honored, not merely stated.
6. `grep -n "abort_unless(\$contact\|abort_unless(\$blacklist\|abort_unless(\$field" app/Http/Controllers/Customer/ContactsController.php app/Http/Controllers/Customer/BlacklistsController.php` → present, confirming the explicit `abort(404)` pattern (§8, §11) was actually used.
7. `grep -c "authorize('create_contact')\|authorize('update_contact_group')\|authorize('view_contact')" app/Http/Controllers/Customer/ContactsController.php` → each count increases by exactly the number of §7's newly-gated actions using that string; no new permission string appears anywhere (`grep -n "'[a-z_]*'" ` diffed against `config/customer-permissions.php`'s own existing key list confirms every string used already exists there).
8. `grep -n "Js::from(" resources/views/customer/contactGroups/show.blade.php` → exactly 4 occurrences (§12); `grep -c "{!! \$contact_groups !!}\|{!! \$remain_opt_in_keywords !!}\|{!! \$remain_opt_out_keywords !!}"` → zero (fully replaced).
9. `grep -n "e(\$customer_profile)\|e(\$customer_name)\|e(\$blacklist->user->displayName())" app/Http/Controllers/Admin/BlacklistsController.php` → present (§13); `git diff app/Http/Controllers/Admin/BlacklistsController.php` (against the pre-implementation base) shows **only** these lines changed, nothing else in `search()` or elsewhere in the file.
10. A runtime/database-level check (not static grep): after the batch-mixed-ownership tests (§17) run, the foreign/other-tenant rows referenced in each test are queried directly and confirmed unmutated.
11. `php artisan route:list` (or equivalent) diffed before/after → every existing CRM route URI/name/controller-action binding is byte-identical; only the controller method bodies/signatures changed, never the routing table.
12. `php artisan test tests/Feature/Security/ContactGroupsSecurityTest.php tests/Feature/Security/ContactsSecurityTest.php tests/Feature/Security/BlacklistsSecurityTest.php` → the reported summary line must show **0 skipped, 0 failed**.

---

## 21. Forbidden scope

This remediation must not, under any circumstance:

- Restyle, retheme, migrate icons, adopt Design System components, or otherwise touch the presentation layer of any Slice-5 view beyond the four exact `{!! !!}` line-range changes named in §12/§16 item 4 — Slice-5's own visual implementation remains exclusively its own, separately-authorized future scope.
- Touch `app/Http/Controllers/API/ContactsController.php`/`ContactsHTTPController.php` or `routes/api.php` in any way (§3.11.1) — a genuine, related, but explicitly separate defect, deferred to its own future human decision.
- Redesign or otherwise fix the arbitrary local file-path read in `importMapping()` (§3.11.2) — a different vulnerability class, out of scope.
- Touch `EloquentContactsRepository::store()`'s client-suppliable `user_id` (§3.11.3) — deferred, since fixing it would require touching a repository method also used by the unaudited API surface.
- Fix, wire up, or otherwise touch `AiAnalyticsController::updateVariants()`/`admin.ai_variants.update` (§3.11.4) — unrelated, confirmed dead, already deferred by a separate contract.
- Redesign CRM product behavior, import semantics, opt-in/opt-out compliance wording, keyword-assignment business rules, billing, workspace architecture, Opportunity logic, or any behavior beyond authorization/ownership/validation/escaping (§8-§14) — every change in §16 is exactly that and nothing else.
- Expand into a whole-application security audit, or fix any security issue outside `Customer\ContactsController`, `Customer\BlacklistsController`, the two exact `Admin\BlacklistsController::search()` lines, and the four exact Blade lines named above.
- Author or authorize any schema, route, config, FormRequest, repository, or model change of any kind (§16, §20 item 1) — none is required and none is authorized even if later claimed convenient.
- Redesign or narrow the `$user->id === 1` superadmin bypass (§3.10, §6) — inherited unchanged.
- Begin Slice 5 visual implementation, Slice 4, Slice 6, or any other RFC/initiative (COO, SEO, Outreach, Website, Calendar, Ads) — this contract authorizes only its own, later, separately-authorized implementation (§23).
- Deploy, force-push, push directly to `main`, or merge anything.

---

## 22. Stop conditions

This remediation's implementation must stop, leave the working tree unstaged, and report rather than proceed, if:

- Any path beyond §16's 7-item allowlist is required — the **8th** path.
- `php artisan route:list` shows any CRM route's URI, name, or controller-action binding changed.
- Any table this remediation's queries depend on (`contact_groups.customer_id`, `contacts.group_id`/`customer_id`, `blacklists.user_id`, `contact_group_fields.contact_group_id`/`uid`) is found to have a materially different shape than §3 documents.
- The focused security suite (§19) reports any skipped test not resolved by §18's fixture authorization, or any failed test.
- Any existing test outside §16's own 3 new files fails for a reason not fixable within this remediation's own allowlist, and that failure cannot be eliminated through environment-only correction.
- Any permission string, or any Gate/permission-config path, is found to require a change — none is authorized; this remediation reuses existing strings exactly (§7, §20 item 7).
- The admin Blacklists global-visibility model (`index`/`search`/`export`, and `destroy`/`batchAction`'s own ability to act on any tenant's row) is found to be narrowed by any change in this remediation.
- A genuine need is found to touch the API-layer controllers (§3.11.1), the `importMapping()` file-path handling (§3.11.2), or `EloquentContactsRepository::store()`'s `user_id` handling (§3.11.3) in order to make this remediation's own allowlisted fixes function correctly — implementation must stop and report rather than silently expand scope to cover them.
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is found necessary to reference.

---

## 23. Governance closing — human-only merge, correction rounds, and the Slice-5 handoff

- **Human-only merge.** No automatic merge of this contract, and no automatic merge of its own future implementation, under any condition.
- **`maximum_correction_rounds: 2`** applies to this contract, unchanged from every other contract in this repository's own established convention.
- **`advance_automatically: false`, `start_automatically_after_contract_merge: false`.** Merging this contract does not begin its own implementation. A separate, explicit, future human instruction is required.
- **Merging this contract does NOT satisfy the Slice-5 prerequisite.** The merged Slice-5 contract's own §0/§7 requires this remediation's **implementation** — not merely this contract — to be human-merged, with the admin Blacklists global model preserved and both XSS findings resolved, before Slice 5 implementation may be authorized. Merging this document alone leaves Slice 5 exactly as blocked as it was before this document existed.
- **Post-remediation handoff, explicit, restated from the merged Slice-5 contract's own §7.** Once this remediation's own implementation is separately authorized, implemented, and human-merged, the exact resulting merge SHA must be reported so that Slice 5's own later, separate implementation authorization can pin: (1) that exact remediation-implementation merge SHA; (2) the exact then-current `origin/main` SHA Slice 5 implementation would be based on; (3) the exact focused security test paths/commands this remediation introduces (§16 items 5-7, §19). During Slice 5 implementation: these three test files remain unchanged; they run first, zero failures required; every remediation-changed path *outside* Slice 5's own 29-view allowlist remains byte-clean relative to this remediation's own post-merge baseline; and — since this remediation's own §16 item 4 modifies `contactGroups/show.blade.php`, one of Slice 5's own 29 allowlisted views — that view remains eligible to receive its already-authorized Slice-5 presentation changes, provided the `Js::from()` encoding this remediation introduces (§12) is preserved exactly and proven still-intact by this remediation's own pinned XSS-regression test, re-run per above. This contract does not, and cannot, supply the SHA now, since the implementation it describes does not yet exist.

---

## 24. Contract self-audit

1. Every `ContactsController` CRM action is inventoried (43 total, §3.2) — not sampled. ✓
2. The `ContactGroups`/`ContactGroupFields`/`Blacklists` route-model-binding surface is covered exhaustively, with the exact binding key (`uid`) mechanically confirmed for all three, including a correction of an initial audit-agent miscount on `ContactGroupFields` (§3.1). ✓
3. Every raw batch-ID surface (ContactGroups-level and Contacts-level batch actions, both Blacklists controllers) is covered, with repository method bodies quoted verbatim (§3.3, §3.4). ✓
4. Every relevant Contacts child-resource mutation is covered, and both independent query-defect patterns (Pattern A/B) are itemized exhaustively, including the mismatch bug that undermines Pattern B's own coincidental protection (§3.2, §9). ✓
5. Customer Blacklists is fully covered, including a previously-undocumented side effect (the unscoped `Contacts`-resubscribe on `destroy()`, §3.4) discovered and closed as a consequence of this contract's own fix, without a dedicated line item. ✓
6. Admin Blacklists' global semantics are preserved by explicit design (§11) — zero admin-controller or admin-called-repository-method changes anywhere in §16. ✓
7. Shared repository callers are inventoried exhaustively for every method this contract's design touches or avoids touching, confirming the API layer cannot be affected (§3.3, §3.4). ✓
8. Public subscribe/unsubscribe is confirmed unaffected — traced end-to-end, confirmed to touch `Blacklists` only via a pre-existing, unscoped, read-only, boolean existence check this contract does not alter (§3.5). ✓
9. Both XSS findings receive an explicit, evidence-backed disposition (both: exploitable, both: remediate directly) with an exact, minimal, mechanically-derived fix (§3.6, §3.7, §12, §13) — neither is left as "evaluate later." ✓
10. No historical insecure behavior is described anywhere as something to preserve — every table in §14 states the *remediated* target behavior, never the pre-remediation state as a goal. ✓
11. Foreign/nonexistent response behavior is defined precisely, including the non-obvious `ModelNotFoundException`→500 and `wantsJson()`→200-with-error-body findings (§3.8), and the binding-resolution design (§8, §11) is chosen specifically because it is the only pattern proven to produce a genuinely indistinguishable response for both cases given those two findings. ✓
12. Mixed-batch behavior is defined as one explicit, reasoned, fail-closed target (silent exclusion of foreign/nonexistent from the mutated set, §9, §11), not left ambiguous. ✓
13. The exact test actor model is defined (distinct non-id-1 accounts per test, §17), and the pre-existing superadmin bypass is explicitly never relied upon for an ordinary-customer test fixture. ✓
14. Focused security test files are named exactly, derived from this repository's own already-established `tests/Feature/Security/` naming convention (§3.9, §16, §17), not invented ahead of inspecting that convention. ✓
15. The implementation allowlist is literal, path-level, sequential, exactly 7 items, with a stop threshold of 8 (§16). ✓
16. No Slice-5 presentation implementation is authorized anywhere in this document beyond the four exact, narrow XSS-fix lines (§21). ✓
17. `docs/automation/AI-AUTONOMY-STATE.json` is untouched. ✓
18. Every discovered-but-adjacent finding (API layer, file-path read, mass-assignable `user_id`, dead `ai_variants` route) is documented with its own reasoning for exclusion, never silently dropped and never silently folded in (§3.11, §21). ✓

---

## 25. Verification and publication

Performed, in order, before commit:

1. Markdown structural check — every numbered §16 item follows the pattern `N. `path``, no broken heading levels, no unclosed code fences.
2. §16's numbered items counted mechanically and confirmed equal to exactly 7, sequential, no gap, no repeated number.
3. Every path listed in §16 checked for uniqueness — no path string appears twice.
4. `git diff --check` — clean, no whitespace-error or conflict-marker findings.
5. `git diff --name-only` — exactly one path: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-REMEDIATION-CONTRACT.md`.
6. `git status --short` — exactly one entry, the same path.
7. Stage individually (`git add docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-REMEDIATION-CONTRACT.md`), never `git add -A`/`.`.
8. Commit message: `docs: define Slice 5 CRM security remediation`.
9. Push to `origin chore/design-system-m2-slice5-crm-security-remediation-contract` — a normal push, never force-pushed.
10. Open a draft PR into `main` if `gh` is available; otherwise report the exact GitHub comparison URL.
11. **Do not merge. Do not begin this remediation's implementation. Do not begin Slice 5 visual implementation. Do not begin any other RFC or initiative.** All require separate, explicit, future human authorization. No test is run for this docs-only change — reported honestly as not run, no count fabricated.

---

*End of Design System M2 Slice 5 CRM Security Remediation Contract. Implementation requires a separate, explicit human instruction. This contract's own merge does not start or resume it, and does not by itself satisfy the Slice-5 prerequisite named in `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` §0/§7 — only this remediation's own subsequently human-merged implementation does that.*
