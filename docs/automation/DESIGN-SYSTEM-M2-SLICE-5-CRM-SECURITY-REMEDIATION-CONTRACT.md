# Design System M2 Slice 5 — CRM Security Remediation Contract

**Status: contract only. No implementation has occurred under this document. Implementation requires its own separate, explicit human authorization. Merging this contract does NOT satisfy the Slice-5 prerequisite named in `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` §0/§7 — that prerequisite is satisfied only when this remediation's own implementation is separately human-merged (§23).**

**Correction Round 1.** Independent audit confirmed the original drafting pass correctly established the core web-customer Contacts/ContactGroups/Blacklists IDOR findings and both XSS findings, but found ten binding defects in the proposed remediation architecture, all corrected without reopening anything else: (A) `EloquentBlacklistsRepository::destroy()`'s own re-subscribe query is cross-tenant even when the acting customer's own `Blacklists` row is genuinely owned — closed with a repository-level fix, not merely a caller-level ownership check; (B) `EloquentBlacklistsRepository::store()`'s own cache-recompute query has the identical, independent cross-tenant defect — closed by reusing the method's own existing `is_admin` distinction; (C) the raw-`string`-binding architecture (§8) breaks `app/Http/Requests/Contacts/UpdateContactGroup.php`'s `rules()`, which assumes a bound model — closed with a type-aware fix that explicitly preserves the separate, out-of-scope API route's own unchanged behavior; (D) the claim that the `$user->id === 1` superadmin bypass exempts ownership queries was false — corrected to precisely distinguish the permission-Gate bypass (real, unmodified) from direct `where('customer_id', ...)` ownership predicates (never bypassed); (E) the claim that batch responses are identical regardless of foreign/nonexistent/owned composition was false for the zero-owned case — corrected to a five-scenario, per-batch-family table (itself further corrected in Round 2, below); (F) the original "27 of 43"/"Ten actions... 11 total" headline counts were internally inconsistent prose arithmetic — corrected to a mechanically re-derived 32-of-43 and a consistent eleven; (G) "admin Blacklists search entirely untouched" contradicted this same document's own authorized XSS-escaping change inside that method — corrected to precisely distinguish tenant-scope/authorization (untouched) from the two explicitly-authorized escaping lines (changed) (itself further corrected in Round 2, below); (H) the shared-repository-caller inventory falsely claimed the Contacts repository's singular `update()`/`destroy()` are customer-only-called — corrected, and a second, previously-unnamed API controller class identified; (I) the XSS-#1 test design named in the original pass would not have exercised the vulnerable code path, since `show()`'s own query excludes the currently-viewed group from the serialized collection — corrected to use a second, genuinely-included group; (J) a proposed `storeImportContact()` production-line change required an impossible test (asserting a value-difference the ownership fix itself structurally forecloses) — withdrawn, with the underlying defect confirmed closed structurally instead. The future implementation allowlist grows from the original, incomplete 7 paths to a mechanically re-derived 9 (§16).

**Correction Round 2 (this pass, final — `maximum_correction_rounds: 2`).** Independent final review confirmed Round 1's major corrections but found a smaller, still-binding set of remaining mechanical contradictions, all corrected in this final pass: (A) Round 1's own `destroy()` fix (a uniform `customer_id`-scoped resubscribe) is itself incomplete — it silently breaks the intended resubscribe effect for an **admin-owned/global** `Blacklists` row (searching for `Contacts.customer_id = <the admin's own id>`, which structurally cannot match) — corrected to an `is_admin`-branched design keyed on the row's own owner, mirroring `store()`'s own established idiom, correctly handling all three cases (customer-owned row deleted by that customer or by admin; admin-owned/global row deleted by admin); (B) Round 1's own single, shared five-scenario batch table is itself still wrong for the `Contacts`-level family — direct re-read of `batchActionContact()`'s own switch statement confirms the controller **discards every one of these repository methods' return values**, unconditionally returning success regardless of composition, which Round 1 had not checked — corrected into three mechanically-distinct families (Family A `ContactGroups`-level, Family B `Contacts`-level, Family C customer `Blacklists`-level), with Family B needing **no** additional id-prefiltering at all (the existing, now-owned `group_id` scope is already sufficient); (C) one residual "likewise entirely untouched at the controller level" claim about admin `search()` remained, contradicting this document's own §13 — corrected to the precise final wording; (D) §3.11.3's "this contract deliberately touches zero repository code" became false once Round 1 itself authorized one repository file — corrected to the accurate, narrower reason `EloquentContactsRepository` specifically remains untouched (it is API-shared; `EloquentBlacklistsRepository` is not); (E) §18's fixture-authorization pointer still referenced the withdrawn 7-path allowlist's own item numbers (5-7) instead of the final 7-9 — corrected; (F) §8's "only rules()'s first line changes" claim was literally false against its own proposed multi-line code — corrected to describe the derivation *portion*, not a single physical line; (G) `BlacklistsSecurityTest.php`'s own coverage requirements are expanded to explicitly, separately prove both customer and admin `store()`/`destroy()` semantics via direct database assertions, not merely controller-status inference. No conclusion outside these seven items is reopened — every already-correct Round 1 conclusion (base SHA; `uid` binding for all three models; 45/43/32 action counts; eleven missing permission checks; the raw-string ownership-resolution architecture; the `UpdateContactGroup` model-vs-string compatibility design; the Gate-bypass-vs-ownership distinction; the `target_group` fix; `storeImportContact`'s minimal/structural disposition; both XSS remediations; public subscribe/unsubscribe untouched; the API IDOR, file-path-read, and mass-assignment findings all remaining explicitly deferred; the full-regression requirement; no automatic advancement) stands unchanged. The allowlist count remains exactly 9 (§16) — no path is added or removed this round.

**`post_round_docs_consistency_exception: human_approved`.** This is a one-time, human-approved, docs-only consistency exception applied after Correction Round 2 — it is **not** Correction Round 3, not a new correction round, not implementation authorization, and not a new security audit. `maximum_correction_rounds: 2` is unchanged; Correction Round 2 remains the final correction round this contract's governance permits. This exception exists solely to fix three residual stale statements discovered after Round 2 closed, each contradicting a design Round 2 itself already locked, without reopening the contract's security design or its correction-round budget: (1) §6's actor-matrix note describing the two `EloquentBlacklistsRepository` methods (§3.4) as ones "this remediation deliberately does not modify" — false, since §11/§16 item 5 explicitly modify `destroy()`/`store()`; corrected to state plainly that those methods are deliberately modified, in an actor-preserving way, while customer and admin controllers continue sharing the same repository code; (2) §6's superadmin-bypass note describing the global admin Blacklists model as provided through `Admin\BlacklistsController`, "which this contract does not touch" — too broad, since §13/§16 item 3 modify two escaping expressions inside that controller's `search()`; corrected to state precisely that admin Blacklists' tenant scope, authorization, and every controller method's own selection/mutation logic remain unchanged, while exactly the two XSS-escaping expressions inside `search()` are modified; (3) §24 self-audit item 7's claim that the API-shared singular `store()`/`update()`/`destroy()` methods are unmodified because "neither singular method is itself modified" — ambiguous, since it does not name which repository it means; corrected to name `EloquentContactsRepository` explicitly (left unchanged, API-shared, API IDOR remains deferred) and to distinguish it from the separate `EloquentBlacklistsRepository`, which §16 item 5 does intentionally modify. No other statement in this document is altered by this exception.

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice5-crm-security-remediation-contract`, in the isolated linked worktree `../design-system-m2-slice5-security-remediation-contract-worktree`, based on `origin/main` at `0b8e44bbffb431d223b27066e8efd3dedfee032d` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` at the start of this drafting pass. This SHA is the merge of PR #175 (`Merge pull request #175 from os-creator1/chore/design-system-m2-slice5-contacts-crm-contract`), which merged the Design System M2 Slice 5 (Contacts & CRM) contract itself, including both its correction rounds and its human-approved post-round consistency exception.
- Neither this contract's file nor its branch existed before this pass — confirmed via `git cat-file -e origin/main:docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-REMEDIATION-CONTRACT.md` (fails) and `git ls-remote --heads origin chore/design-system-m2-slice5-crm-security-remediation-contract` (empty) before this branch was created.
- This is the mandatory prerequisite named by the merged Slice-5 contract's §0/§7: *"a separate, dedicated CRM tenant-isolation security-remediation contract must be drafted, human-reviewed, and merged, and its own implementation must be human-merged, before Slice 5 implementation may be authorized."* This document is that contract. It does not authorize Slice 5 visual implementation, does not authorize its own implementation, and does not authorize any other RFC or initiative.
- `maximum_correction_rounds: 2` applies to this contract — **this is Correction Round 2, the final allowed correction round for this contract**. `advance_automatically: false`. `start_automatically_after_contract_merge: false` (§23).
- This contract's own scope is strictly: (1) customer-portal tenant-isolation for Contacts/ContactGroups/Blacklists (the defect the merged Slice-5 contract's §3.8 documented), (2) preserving admin Blacklists' intentional global model, and (3) the disposition of the two stored-XSS-shaped findings the merged Slice-5 contract's §3.8/§7 required this remediation to resolve. It does not become a whole-application security audit (§21).
- Drafting this contract makes **zero** application changes. Only the one new file named in §2 is touched by this branch.

---

## 1. Mandatory preflight — verified

1. `git fetch origin --tags` — `origin/main` confirmed at exactly `0b8e44bbffb431d223b27066e8efd3dedfee032d`.
2. Confirmed this is the merge of PR #175, and confirmed `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` exists on this exact base and was read in full before drafting.
3. Also read in full before drafting: `CLAUDE.md`, `AGENTS.md`, `docs/automation/DESIGN-SYSTEM-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`, `docs/automation/DESIGN-SYSTEM-M2-SLICE-3-DASHBOARD-SECURITY-REMEDIATION-CONTRACT.md` (the direct governance-shape precedent for this document — its audit methodology, fail-closed/response-semantics discipline, and test-fixture-authorization pattern are reused here; its specific implementation assumptions — three unauthenticated dashboard controllers, a `chat_box`/`manage ai_settings` permission split — are not, since this contract's own defect shape is materially different: existing authentication and permission checks are present throughout the CRM surface, but record-ownership verification is what is missing).
4. Delegated the exhaustive mechanical audit to three parallel, isolated-worktree research agents (`ContactsController` full audit; `BlacklistsController`/shared-caller audit; XSS-trace and auth-response-semantics audit), with the most consequential findings — the `ContactGroupFields` route-binding key, the `Handler.php` exception-rendering behavior including its `wantsJson()` branch, the `message_form`/`sms_form` enum values, and the existence of a separate API-layer surface sharing the same repository — independently re-verified first-hand in this same drafting pass, not taken on the agents' word alone.
5. **Correction Round 1:** starting branch HEAD confirmed at exactly `c27751cf18af6a947fea7702f2d011c8829849c0` (the original contract-drafting commit), working tree clean before any edit. Every correction in that round was independently re-verified by direct source inspection — the exact `EloquentBlacklistsRepository::store()`/`destroy()` method bodies, the exact `UpdateContactGroup::rules()` source and an exhaustive `grep` confirming it is the only `route()`-dependent `FormRequest` under `app/Http/Requests/Contacts/`, a fresh `public function` enumeration of the entire `ContactsController.php` class, and a repository-wide grep confirming both `API\ContactsController` and the previously-unnamed `API\ContactsHTTPController` call the shared repository's singular `update()`/`destroy()` methods.
6. **Correction Round 2 (this pass, final):** starting branch HEAD confirmed at exactly `9df582410ddd0d3b60e815f1b2aaf08e207e57b3` (Round 1's own commit), working tree clean before any edit. Every correction this round was independently re-verified by direct source inspection: `app/Models/Blacklists.php`'s full `user()` relation, confirming the exact `is_admin`-branch design Correction A reuses; a direct, full read of `Customer\ContactsController::batchActionContact()`'s complete switch statement, `Customer\ContactsController::batchAction()`, and `Customer\BlacklistsController::batchAction()`, confirming precisely which families propagate a repository exception versus silently discard the repository's return value; a direct re-read of `EloquentContactsRepository::batchContactCopy()`'s full body confirming it has no conditional return-false path at all. None of the seven corrections in this round is asserted from prose alone.

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

### 3.2 `Customer\ContactsController` — exhaustive per-action audit, mechanically re-counted (Correction Round 1, Correction F)

**Correction F.** The original drafting pass's own headline counts ("27 of the 43 actions", "Ten actions... 11 total") were internally inconsistent prose arithmetic, not a mechanical count. This round re-derives every count directly from `grep -n "public function " app/Http/Controllers/Customer/ContactsController.php` (46 matches, including `__construct`) cross-referenced against `routes/customer.php`/`routes/public.php`.

**Exact mechanical result:** the controller has **45 public methods excluding `__construct`**. Of these, **2 are internal helpers, never routed** (`contactsGenerator($group_id, $headers = null)` at line 1186, called only from `exportContact()`; `contactGroupsGenerator()` at line 1552, called only from `export()`) — confirmed by their signatures (no `ContactGroups` parameter, not referenced in any route file) and excluded from every count below. This leaves **43 genuinely route-bound actions** — the original "43" total was correct; only the sub-counts inside it were wrong.

| Method | `ContactGroups`-bound? | Public route (`routes/public.php`)? | Had a permission check (pre-correction)? | Remediation required |
|---|---|---|---|---|
| `index`, `search`, `create`, `store`, `batchAction`, `export`, `countContacts` (7) | No | No | 6 of 7 yes (`countContacts` no) | `countContacts` gains a permission check (§7); the other 6 already correctly scoped/gated, unchanged |
| `show`, `activeToggle`, `copy`, `update`, `destroy`, `searchContact`, `createContact`, `storeContact`, `updateContactStatus`, `deleteContact`, `editContact`, `updateContact`, `importContact`, `storeImportContact`, `importProcessData`, `batchActionContact`, `exportContact`, `getMessageForm`, `message`, `optInKeyword`, `optOutKeyword`, `deleteOptInKeyword`, `deleteOptOutKeyword`, `contactSampleField`, `deleteContactField`, `storeContactField`, `pasteText`, `storeImportFile`, `importMapping`, `importRun`, `importValidate`, `downloadFailedContacts` (**32**) | Yes | No | Mixed — see §7 for the 11 with none | **Yes — ownership-resolution fix (§8), all 32** |
| `subscribeURL`, `insertContactBySubscriptionForm`, `unsubscribeURL`, `postUnsubscribeURL` (4) | Yes | **Yes** | N/A — intentionally public | **No — untouched (§3.5)** |

**Corrected total: 36 of the 43 routed actions type-hint `ContactGroups $contact`; 4 of those 36 are the intentionally-public actions; the remaining 32 (not 27) require this contract's ownership-resolution fix (§8).** Every reference elsewhere in this document to "27" is corrected to "32" (§4, §5, §8, §16, §17, §20, §24).

**Eleven actions have no permission check of any kind** (no `$this->authorize()` call, no FormRequest gating) — mechanically re-confirmed this round via a per-method body scan of every `authorize(` occurrence, cross-referenced against each method's own request-parameter type (a plain `Request $request` has no FormRequest `authorize()` to fall back on; a named FormRequest type does): `importProcessData`, `getMessageForm`, `optInKeyword`, `optOutKeyword`, `deleteOptInKeyword`, `deleteOptOutKeyword`, `importMapping`, `importRun`, `importValidate`, `countContacts`, `downloadFailedContacts` — **exactly eleven**, matching §7's own already-correct enumerated table; the original drafting pass's summary sentence incorrectly said "Ten" immediately before that same eleven-item list — corrected here to say "eleven" consistently.

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

**A binding architectural defect, found and corrected this round (Correction C).** §8's own ownership-resolution design (replacing implicit `ContactGroups $contact` binding with a raw `string $contact` parameter, resolved via a new private helper) breaks `app/Http/Requests/Contacts/UpdateContactGroup.php`, the `FormRequest` gating `update()`. Its `rules()` method, verbatim:
```php
public function rules(): array
{
    $id          = $this->route('contact')->id;
    ...
}
```
`$this->route('contact')` returns whatever Laravel resolved for the `{contact}` route segment — today, always a `ContactGroups` model (implicit binding). Once the web customer route's own `update()` action signature changes to `string $contact` (§8), `$this->route('contact')` for that route becomes the **raw uid string**, and `->id` on a string fatally errors — validation would break before `resolveOwnedContactGroup()` ever runs.

**A mechanical search of every FormRequest under `app/Http/Requests/Contacts/` for any `$this->route(...)`/`route(...)`/bound-model dependency confirms `UpdateContactGroup.php` is the only one** (`grep -rn "route(" app/Http/Requests/Contacts/*.php` → exactly one match, this file, this line).

**This same `FormRequest` class is also used by the separate, out-of-scope API layer** — `app/Http/Controllers/API/ContactsController.php::update(ContactGroups $contact, UpdateContactGroup $request)` (line 371) keeps its own implicit `ContactGroups $contact` binding, untouched by this contract (§3.11.1). After §8's web-side change, `$this->route('contact')` therefore resolves to **different types depending on which route invoked this shared `FormRequest`** — a raw string for the web customer route, a `ContactGroups` model for the API route — and the fix must handle both correctly without narrowing or altering the API route's own existing (separately-defective, separately-out-of-scope) behavior in any way.

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

`EloquentContactsRepository`'s interface (`ContactsRepository`) is also injected into **two** separate API controller classes — `app/Http/Controllers/API/ContactsController.php` **and** `app/Http/Controllers/API/ContactsHTTPController.php` (a second, previously-unnamed API consumer, confirmed this round) — a separate, Sanctum-token-authenticated API surface (`routes/api.php`, `auth:sanctum` middleware, confirmed via `app/Providers/RouteServiceProvider.php:110`).

**Corrected, Correction Round 1 (Correction H).** The original drafting pass's claim that "none of ... `store()`/`update()`/`destroy()`/`contactDestroy()`/`updateContact()`/`updateContactStatus()` ... are called from anywhere except `Customer\ContactsController.php`" was false and is withdrawn. Direct re-verification this round, `grep -rn "contactGroups->update(\|contactGroups->destroy(\|contactGroups->store(" app/ --include=*.php`, confirms:
```
app/Http/Controllers/API/ContactsController.php:385:      $group = $this->contactGroups->update($contact, $request->input());
app/Http/Controllers/API/ContactsController.php:414:      $this->contactGroups->destroy($contact);
app/Http/Controllers/API/ContactsHTTPController.php:516:  $group = $this->contactGroups->update($contact, $request->all());
app/Http/Controllers/API/ContactsHTTPController.php:555:  $this->contactGroups->destroy($contact);
app/Http/Controllers/Customer/ContactsController.php:458:  $group = $this->contactGroups->update($contact, ...);
app/Http/Controllers/Customer/ContactsController.php:483:  $this->contactGroups->destroy($contact);
```
— **`store()`, `update()`, and `destroy()` (the three singular, non-batch methods) are genuinely shared** with both API controllers.

**The claim that survives correction, re-verified precisely this round and now stated only where it is actually true:** the exact **six batch methods** quoted above in this section (`batchDestroy`, `batchActive`, `batchDisable`, `batchContactDestroy`, `batchContactSubscribe`, `batchContactUnsubscribe`, plus `batchContactCopy`/`batchContactMove`) have **zero** call sites anywhere except `Customer\ContactsController.php` — confirmed by the identical grep pattern finding no `batch*` method name anywhere under `app/Http/Controllers/API/`. **This narrower, now-accurate claim is what this contract's own architecture actually depends on**: §8/§9's design never calls or modifies `store()`/`update()`/`destroy()` (singular) at the repository layer at all — it resolves ownership at the *caller* level (§8's helper) before the controller reaches its own existing, unmodified `$this->contactGroups->update(...)`/`->destroy(...)` calls, so the fact that those two singular methods are shared with the API layer is irrelevant to this contract's own safety. Only the six batch methods (customer-only, confirmed) and the caller-side id-prefiltering this contract adds before calling them are load-bearing here.

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
// lines 35-88 — store (relevant excerpt)
public function store(array $input): Collection
{
    $user = auth()->user();
    ...
    $numbers->chunk(1000)->each(function ($chunk) use ($input, $user) {
        foreach ($chunk as $number) {
            $query = Contacts::where('phone', $number);
            if (! $user->is_admin) { $query->where('customer_id', $user->id); }
            $query->update(['status' => 'unsubscribe']);
            $insertData[] = ['uid' => uniqid(), 'user_id' => $user->id, 'number' => $number, ...];
        }
        Blacklists::insert($insertData);
    });

    // The line below is unscoped — see the finding immediately following.
    $groupIds = Contacts::whereIn('phone', $numbers)->pluck('group_id')->filter()->unique();
    ContactGroups::whereIn('id', $groupIds)->get()->each(fn ($g) => $g->updateCache());

    return $numbers;
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

**Two previously-undocumented, genuinely cross-tenant side effects, found and both fixed this round (Corrections A and B) — the original drafting pass's conclusion that scoping the customer-controller *caller* alone was sufficient is withdrawn as false for both.**

**Correction A — `destroy()`'s re-subscribe step is cross-tenant even when the deleted `Blacklists` row is genuinely owned by the acting customer.** `Contacts::where('phone', $blacklists->number)->first()` (no `customer_id`/`user_id` filter at all) means: customer A owns blacklist row A (number X); customer B independently has a `Contacts` row for number X in B's own tenant; customer A legitimately deletes A's **own** blacklist entry (passes §11's new ownership check with no issue) — and this unscoped lookup can still resubscribe **B's** `Contacts` row. §8's/§11's caller-level ownership check on `$blacklists` itself does not, and structurally cannot, close this — the defect is inside `destroy()`'s own second query, entirely independent of whether the `Blacklists` row passed in is owned. **This requires a repository-level fix**, added to this contract's own allowlist (§16).

**Corrected this round (Correction Round 2, Correction A) — a naive, uniform `customer_id`-scoped fix silently breaks the intended global behavior for an admin-owned blacklist row, and is replaced with a design that mirrors the exact `is_admin` distinction `Blacklists::user()` (a `belongsTo(User::class)` relation) and `store()`'s own existing logic already establish.** `Blacklists.user_id` is set from `auth()->user()->id` at creation time (§3.4's own `store()` finding) — so a row can be owned either by an ordinary customer account (`$blacklists->user->is_admin === false`) or by an admin account itself (`$blacklists->user->is_admin === true`, the same distinction `Admin\BlacklistsController::search()` already branches on, §3.7). Three cases, mechanically distinguished:

1. **Customer-owned row, deleted by that customer** — a plain `where('customer_id', $blacklists->user_id)` scope correctly resubscribes only that customer's own matching `Contacts` row, never an unrelated tenant's.
2. **Customer-owned row, deleted through admin's global management capability** — the same `where('customer_id', $blacklists->user_id)` scope is *already* correct here too, since it targets the row's own true customer owner regardless of which actor triggered the deletion — no separate branch is needed for this case; it is the identical query as case 1.
3. **Admin-owned/global row, deleted by admin** — a `customer_id`-scoped query here would search for `Contacts.customer_id = <the admin's own user id>`, which structurally cannot match any real customer's contact (admin accounts do not own `Contacts` rows in the tenant sense) — silently zeroing out the resubscribe side effect entirely, a genuine behavior regression from today's actual, intentionally-global effect. This case must **skip** the `customer_id` filter and preserve the original, fully unscoped lookup.

**Locked final design**, mirroring `store()`'s own existing `if (! $user->is_admin) { ... }` idiom rather than inventing a new one:
```php
public function destroy(Blacklists $blacklists)
{
    $contactQuery = Contacts::where('phone', $blacklists->number);

    if (! $blacklists->user->is_admin) {
        $contactQuery->where('customer_id', $blacklists->user_id);
    }

    $contact = $contactQuery->first();
    $contact?->update(['status' => 'subscribe']);

    if (! $blacklists->delete()) {
        throw new GeneralException(__('locale.exceptions.something_went_wrong'));
    }

    return true;
}
```
This single branch correctly satisfies all three cases above: cases 1 and 2 both resolve through the `customer_id`-scoped branch (correct, since ownership — not the deleting actor — determines the target); case 3 resolves through the unscoped branch, preserving the existing intentional global behavior exactly, never narrowed into `customer_id = admin_id`. The branch is keyed on the **row's own owner** (`$blacklists->user->is_admin`), never on which actor is currently deleting it.

**Correction B — `store()`'s cache-recompute step is likewise cross-tenant.** After the (already correctly actor-scoped) `Contacts.status` update, `$groupIds = Contacts::whereIn('phone', $numbers)->pluck('group_id')->filter()->unique();` is **unscoped** — a customer adding number X to their own blacklist can trigger `ContactGroups::updateCache()` on **any** tenant's group that happens to contain a `Contacts` row for X, even though the recomputed cache value itself remains numerically correct for that foreign group. This is a genuine cross-tenant mutation-trigger, independent of whether the resulting cached number is "right." **Fix, mirroring this same method's own already-existing `is_admin` branch immediately above it (§20 item 7 — reusing the established distinction, not inventing a new authorization model):**
```php
$groupIdsQuery = Contacts::whereIn('phone', $numbers);
if (! $user->is_admin) {
    $groupIdsQuery->where('customer_id', $user->id);
}
$groupIds = $groupIdsQuery->pluck('group_id')->filter()->unique();
```
Customer callers now only trigger cache updates on their own groups; admin callers (`$user->is_admin`) keep the exact existing global behavior, unchanged.

**Both fixes land in the same file, `app/Repositories/Eloquent/EloquentBlacklistsRepository.php`, added to §16's allowlist.**

**Exhaustive shared-caller search, re-confirmed this round:** `destroy()`, `store()`, and `batchDestroy()` on `BlacklistsRepository`/`EloquentBlacklistsRepository` have call sites **only** in `Customer\BlacklistsController` and `Admin\BlacklistsController` — no Job, no Command, no API controller calls any of the three (confirmed by the identical repo-wide grep pattern used in §3.3, returning no `blacklists->` matches under `app/Http/Controllers/API/`). Both fixes above are therefore safe for the admin path: Correction A's fix is actor-type-agnostic and correct for admin too (stated above); Correction B's fix explicitly preserves admin's exact existing global behavior via the method's own pre-existing `is_admin` branch.

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

- **§3.11.1 — API-layer IDOR, now confirmed across two controller classes (corrected, Correction Round 1/Correction H).** `app/Http/Controllers/API/ContactsController.php` (Sanctum-token-authenticated, `routes/api.php`) has the **identical** unscoped-`ContactGroups $group_id`/`$contact` binding defect — `show`, `update`, `destroy`, `storeContact`, `searchContact`, `updateContact`, `deleteContact`, `allContact` all lack an ownership check (only `index()` correctly scopes, `ContactGroups::where('customer_id', request()->user()->id)`, line 293). **A second, previously-unnamed API controller, `app/Http/Controllers/API/ContactsHTTPController.php`, is also confirmed to call the same shared repository's `store()`/`update()`/`destroy()` methods** (§3.3), and by the same reasoning is presumed to carry an analogous defect, though this contract's own audit did not re-trace its own action bodies line-by-line (out of scope, unchanged conclusion below). This is a separate pair of controller classes, a separate route file, a separate auth guard, and neither was named anywhere in this task's own scope (which named `Customer\ContactsController.php` specifically). Fixing either here would silently expand scope beyond what was authorized. **Flagged for a separate, future human decision on whether it needs its own remediation contract.**
- **§3.11.2 — Arbitrary local file-path read in `importMapping()`.** `ContactsController.php:1819`, `$contact->readCsv($filepath)` where `$filepath` is `$request->filepath`, entirely unvalidated — a potential path-traversal/arbitrary-file-read primitive, a different vulnerability class from tenant isolation. This contract's own ownership guard (§9) still applies to `importMapping()`'s `$contact` parameter (closing who may trigger it for which group), but does not validate or constrain `$filepath` itself. **Flagged, not fixed** — redesigning the import file-handling pipeline is explicitly forbidden scope for this contract (§21) and would require its own dedicated audit.
- **§3.11.3 — Mass-assignable `customer_id` on group creation.** `EloquentContactsRepository::store()` lines 63-67 (§3.2) — out of scope. **Corrected wording, Correction Round 2 (Correction D):** the original "this contract deliberately touches zero repository code" reasoning is stale — this contract now authorizes exactly one repository file, `app/Repositories/Eloquent/EloquentBlacklistsRepository.php` (§16, Corrections A/B), confirmed customer/admin-controller-only, never API-shared. The accurate reason `EloquentContactsRepository::store()` remains untouched is narrower and unrelated to that: **this contract deliberately leaves `EloquentContactsRepository` entirely unchanged**, specifically because that repository (unlike `EloquentBlacklistsRepository`) *is* shared with the deferred API surface (§3.11.1, §3.3) — fixing `store()`'s mass-assignment issue there would mean modifying API-shared code this contract's own scope explicitly excludes, not a general "no repository changes at all" policy.
- **§3.11.4 — `AiAnalyticsController::updateVariants()`/`admin.ai_variants.update`.** Restated from the merged Dashboard Security Remediation Contract (§3.5 there) purely for completeness — unrelated to CRM, not touched by this contract.

---

## 4. Proven vulnerability statement

Re-confirmed, current `origin/main` `0b8e44bbffb431d223b27066e8efd3dedfee032d`: `Customer\ContactsController` (**32** of 43 routed actions, mechanically re-counted, §3.2 Correction F) and `Customer\BlacklistsController` (2 of 6 actions: `destroy`, `batchAction`) check permission strings but not record ownership. Any authenticated customer holding the relevant, ordinary, non-privileged permission (`view_contact_group`, `update_contact_group`, `delete_contact_group`, `view_contact`, `create_contact`, `update_contact`, `delete_contact`, `view_blacklist`, `delete_blacklist` — all real, pre-existing permission strings, confirmed present in `config/customer-permissions.php`) can read, mutate, or delete another tenant's `ContactGroups`, `Contacts`, `ContactGroupFields`, opt-in/opt-out keyword records, or `Blacklists` rows, and — via `target_group` in batch copy/move — inject or exfiltrate contacts across tenant boundaries independent of which group the route itself bound. Further defects compound this: unwhitelisted dynamic-attribute read/write on `ContactGroups` (`getMessageForm`/`message`); eleven actions with no permission gate at all (§3.2); a `FormRequest` (`UpdateContactGroup`) whose own binding-model assumption breaks under the ownership fix unless separately corrected (§3.2 Correction C); and two independent, genuinely cross-tenant side effects inside `EloquentBlacklistsRepository::destroy()`/`store()` that persist even when the acting customer's own `Blacklists` row is correctly verified as owned (§3.4 Corrections A/B). Two stored-XSS-shaped findings (§3.6, §3.7) are both confirmed genuinely exploitable and are resolved by this same contract, per the merged Slice-5 contract's own explicit requirement.

---

## 5. Endpoint/action inventory — summary

Restated from §3.2/§3.4, mechanically re-counted this round (Correction F): **32** (not 27) `ContactsController` actions and 2 `BlacklistsController` actions require an ownership fix; 11 `ContactsController` actions additionally require a missing permission check; 2 `ContactsController` actions require a dynamic-attribute whitelist; 1 batch-action code path requires a second, independent `target_group`-resolution fix; 1 `FormRequest` (`UpdateContactGroup`) requires a binding-compatibility fix (§3.2 Correction C); 2 `EloquentBlacklistsRepository` methods (`destroy`, `store`) require a repository-level fix independent of, and in addition to, the caller-level ownership check (§3.4 Corrections A/B). 4 public actions (§3.5) and all correctly-scoped list/search/export actions are unaffected, unchanged, and not touched.

---

## 6. Intended actor matrix

| Surface | Read actor | Write actor | Basis |
|---|---|---|---|
| Customer ContactGroups (`show`/`update`/`destroy`/`activeToggle`/`copy`/batch/import/fields/keywords/message) | Authenticated customer holding the named `*_contact_group`/`*_contact` permission, **and** owning the specific `ContactGroups` row | Same | §3.1, §3.2, §9 |
| Customer Contacts (individual subscriber status/edit/update/delete/create/store/batch) | Same customer, and only within a `ContactGroups` row that customer owns | Same | §3.2, §9 |
| Customer Blacklists (`destroy`/`batchAction`) | Authenticated customer holding `delete_blacklist`, and owning the specific `Blacklists` row(s) | Same | §3.4, §11 |
| Admin Blacklists (`index`/`search`/`export`/`destroy`/`batchAction`) | Authenticated admin holding the corresponding admin permission — **intentionally global**, unchanged | Same | §3.4, §11 |
| Public subscribe/unsubscribe | Anonymous, holder of the group's own public link | Same (self-service only) | §3.5, unchanged |

**Not intentionally dual-access anywhere**: no surface named above is meant to be reachable by both a customer and an admin through the same code path except the two Blacklists repository methods (§3.4/§11) — `EloquentBlacklistsRepository::destroy()`/`store()` — which this remediation **does** deliberately modify (§16 item 5), precisely because both a customer controller and the admin controller continue calling the same shared repository code. The fix is actor-preserving by design: it is not implemented by branching on which controller is calling, but on the target row's own owner (`is_admin` on the row's `user` relation, per §11) — so customer/admin behavioral distinctions are correctly reproduced without either controller needing its own copy of the method.

**The `$user->id === 1` superadmin bypass (§3.10), precisely scoped — corrected, Correction Round 1 (Correction D).** The original drafting pass's claim that id-1 "continues to reach every CRM surface regardless of the ownership checks this contract adds" is false and withdrawn. `EloquentAccountRepository::hasPermission()`'s id-1 bypass is scoped **exclusively to the Gate/permission-string lookup** (`$this->authorize('view_contact_group')`-shaped checks, §7) — it is invoked nowhere near, and has no effect on, a direct ownership predicate such as `where('customer_id', Auth::id())` inside `resolveOwnedContactGroup()`/`resolveOwnedBlacklist()` (§8, §11). The two are architecturally independent layers:

- **Permission bypass** — an account with `id === 1` continues to pass every `$this->authorize()`/`can:` check unconditionally, exactly as today, unmodified by this contract.
- **Tenant ownership** — that same account, if it is ever a "customer" actor reaching a `routes/customer.php` route (a separate question about account-type/routing this contract does not resolve or assume), is subject to the **identical** `where('customer_id', Auth::id())` ownership predicate as any other customer account. If `Auth::id() === 1` and the target `ContactGroups`/`Blacklists` row's own `customer_id`/`user_id` is not `1`, `resolveOwnedContactGroup()`/`resolveOwnedBlacklist()` still returns no row and still `abort(404)`s — the permission bypass grants no ownership exemption of any kind.

No special id-1 exemption is invented anywhere in this contract's own design. The intentional global admin Blacklists model (§3.4, §11) is provided entirely through the separate `Admin\BlacklistsController` code path, never through a belief that the Gate bypass itself defeats customer-side ownership queries. That code path is not entirely untouched: admin Blacklists' tenant scope remains global and unchanged, its authorization remains unchanged, and `index()`/`export()`/`destroy()`/`batchAction()`'s own controller bodies remain byte-identical — only the two XSS output-escaping expressions inside `search()` are modified, per §13/§16 item 3 (Correction Round 2, Correction C).

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

Every one of the **32** actions named in §3.2/§5 (mechanically re-counted, Correction F) that currently type-hints `ContactGroups $contact` has its signature changed to `string $contact` and gains, as its first statement, `$contact = $this->resolveOwnedContactGroup($contact);` — after which the rest of the method body is unchanged (it already uses `$contact` as a `ContactGroups` instance). This is applied to every remediated action **except** the four public actions (§3.5), which are not touched at all and keep their existing implicit `ContactGroups $contact` binding exactly as-is.

**`UpdateContactGroup` `FormRequest` compatibility fix (Correction C).** §3.2's own finding — this shared `FormRequest`'s `rules()` breaks once `update()`'s web-route parameter becomes `string $contact` — is resolved by making `rules()` branch on the actual runtime type of `$this->route('contact')`, so the API route's own unchanged, model-bound behavior is fully preserved and the web route's new raw-string behavior is resolved the same safe way as every other action in this section (never `firstOrFail()`, identical response for foreign vs. nonexistent, §3.8):
```php
public function rules(): array
{
    $routeContact = $this->route('contact');

    if ($routeContact instanceof ContactGroups) {
        // API route (out of this contract's scope, §3.11.1) — unchanged, model already bound.
        $id = $routeContact->id;
    } else {
        // Web customer route, post-remediation — raw uid string; resolve the caller's own owned group.
        $contact = ContactGroups::where('uid', $routeContact)
            ->where('customer_id', $this->user()->id)
            ->first();

        abort_unless($contact, 404);

        $id = $contact->id;
    }

    $customer_id = $this->user()->id;
    $name        = $this->name;

    return [
        'name' => ['required',
            Rule::unique('contact_groups')->where(function ($query) use ($customer_id, $name) {
                return $query->where('customer_id', $customer_id)->where('name', $name);
            })->ignore($id)],
    ];
}
```
The `authorize()` method and `messages()` method are unchanged. **Corrected this round (Correction Round 2, Correction F) — the literal claim that "only `rules()`'s first line changes" is false and withdrawn**: the original single-line `$id = $this->route('contact')->id;` is replaced by a multi-line, type-aware resolution block (quoted above), not a one-line edit. **The accurate, binding scope statement is**: only `rules()`'s own `$id`-**derivation portion** changes (the block quoted above, replacing the original single line); the existing `Rule::unique(...)->where(...)->ignore($id)` uniqueness-rule semantics, and every other part of `rules()`, `authorize()`, and `messages()`, are preserved verbatim — the *behavior* of the uniqueness check is unchanged, only *how many physical lines* implement the `$id` lookup grows. Added to §16's allowlist: `app/Http/Requests/Contacts/UpdateContactGroup.php`.

**This guarantees a foreign-but-existing `uid` and a genuinely nonexistent `uid` reach the identical `abort(404)` call, in the identical code path, producing the identical response** — the core fail-closed requirement (§14) — regardless of which `Handler::render()` branch ultimately renders it (§3.8 item 3).

`deleteContactField(ContactGroups $contact, ContactGroupFields $field_id)` additionally becomes `deleteContactField(string $contact, string $field_id)`; after resolving `$contact` via the helper above, the method resolves `$field_id` via a second, analogous scoped lookup (`ContactGroupFields::where('contact_group_id', $contact->id)->where('uid', $field_id)->first()`, `abort_unless(..., 404)`) — closing the previously-unscoped second implicit binding on this one action (§3.2).

**`getMessageForm()`/`message()` dynamic-attribute whitelist:** both actions gain a validation of `sms_form`/`message_form` against the fixed enum `['signup_sms', 'welcome_sms', 'unsubscribe_sms']` (§3.6/§3.2's confirmed exhaustive set of legitimate values) before the dynamic property access — any other value is rejected the same way a missing required field already is (standard `FormRequest`-shaped validation failure), never silently ignored and never permitted through to the dynamic read/write.

**`copy()`/`batchActionContact()`'s `target_group` resolution** (§3.2) gains the identical ownership predicate: `ContactGroups::where('uid', $target_group)->where('customer_id', Auth::id())->first()`, with the existing `'Group not found'`-shaped JSON error response preserved for both a foreign and a nonexistent `target_group` (mirroring §9's own batch fail-closed target below).

---

## 9. Exact tenant/data-isolation model — `Contacts` (individual subscribers) and batch actions

Because every remediated action now resolves `$contact` via `resolveOwnedContactGroup()` (§8) before its own body runs, every `Contacts` query already scoped by `where('group_id', $contact->id)` (Pattern A, §3.2) is now safe by construction — `$contact->id` can never be a foreign group's id inside a remediated action. **This closes Pattern A everywhere it appears (§3.3's six repository methods, plus `updateContactStatus`/`updateContact`/`contactDestroy` in the repository) without any change to those methods' own bodies** — they remain exactly as they are today; only their caller now guarantees the `$contactGroups` argument passed in is pre-verified as owned.

**Pattern B's own coincidental-protection weakness (§3.2) — final disposition, corrected this round (Correction J).** The original drafting pass proposed changing `storeImportContact()`'s insert (`ContactsController.php:959-960`) from `'customer_id' => Auth::user()->id` to `'customer_id' => $contact->customer_id`, and required a test proving the two values differ for a legitimate request. **That test requirement is impossible to satisfy and is withdrawn, along with the production-line change it depended on.** Once every remediated action resolves `$contact` via `resolveOwnedContactGroup()` (§8), `$contact->customer_id === Auth::id()` is a structural invariant of *every* legitimate customer request reaching `storeImportContact()` — there is no allowed path, after this contract's own fix, where `Auth::user()->id` and `$contact->customer_id` can differ. Per the governing instruction's own stated preference ("prefer the minimal outcome unless a remaining allowed path can mechanically reach a differing owner after the ownership fix" — none does), **`storeImportContact()`'s existing `'customer_id' => Auth::user()->id` line is left completely unchanged.** The historical `customer_id`/`group_id` mismatch this line could once produce (§3.2's own Pattern-B finding) is fully closed as a **structural consequence** of §8's binding-resolution fix alone — no separate line-item, no separate allowlist path, and no separate test asserting a value-difference that can no longer occur. (§17's test coverage for this is corrected accordingly — an invariant assertion, not a differing-values assertion.)

**Exact mixed-batch response contract — corrected this round (Correction Round 2, Correction B) into three mechanically-distinct families, not one shared table.** Round 1's own single table was itself still wrong: it applied the same "existing zero-row path is an error response" conclusion to the `Contacts`-level family without checking whether the **controller** actually surfaces that repository-level outcome — it does not, for that family. Every claim below is verified directly against `Customer\ContactsController::batchAction()` (lines 497-544) and `::batchActionContact()` (lines 1073-1166), not inferred from the repository methods alone.

**Family A — `ContactGroups`-level `batchAction()` (`batchDestroy`/`batchActive`/`batchDisable`).** The controller calls each repository method and **discards its return value**, but the repository methods themselves **throw `GeneralException`** (not merely return `false`) when the filtered set matches zero rows (§3.3's verbatim quote: `if ($this->query()->whereIn('uid', $ids)->delete()) { return true; } throw new GeneralException(...);`). An uncaught, thrown exception is not silenceable by the controller's own return-value-ignoring shape — it propagates to `Handler.php`, producing a genuine, distinguishable error response (§3.8). The controller pre-filters `$ids` to the owned subset before calling (unchanged from Round 1): `$ownedIds = ContactGroups::where('customer_id', Auth::id())->whereIn('uid', $ids)->pluck('uid')->all();`.

**Family C — customer `Blacklists` `batchAction()` (`batchDestroy`).** Identical shape to Family A, directly re-confirmed this round: the controller discards the repository's return value, but `EloquentBlacklistsRepository::batchDestroy()` also **throws `GeneralException`** on a zero-row filtered set (§3.4's verbatim quote) — same propagation to `Handler.php`, same genuine error response. Id-prefiltering per §11, unchanged.

**Family A and Family C, locked five-scenario table (identical for both):**

| Scenario | Filtered (owned) set | Repository outcome | Response |
|---|---|---|---|
| Owned + foreign | Non-empty (owned only) | Success path (rows affected) | Existing success response — identical to scenario 2 |
| Owned + nonexistent | Non-empty (owned only) | Success path | Existing success response — identical to scenario 1 |
| Foreign-only | Empty | `GeneralException` thrown, uncaught, reaches `Handler.php` | Existing error response — identical to scenario 4 |
| Nonexistent-only | Empty | `GeneralException` thrown, identical code path | Existing error response — identical to scenario 3 |
| Empty submitted list (if current validation accepts one) | Empty | `GeneralException` thrown, identical code path | Existing error response — identical to scenarios 3-4 |

**Family B — `Contacts`-level `batchActionContact()` (`destroy`/`subscribe`/`unsubscribe`/`copy`/`move`) — genuinely different, corrected this round, no additional id-prefiltering required.** Direct re-read of `batchActionContact()`'s full switch statement confirms every one of its five branches calls the corresponding repository method and **unconditionally returns a `'status' => 'success'` JSON response, without inspecting the method's return value at all** — the call and the response are not connected by any `if`. This holds regardless of what the repository method itself returns: `batchContactDestroy`/`Subscribe`/`Unsubscribe`/`Move` each return `false` on zero rows changed (§3.3), and `batchContactCopy` **always returns `true` unconditionally**, even when its own source cursor is empty (verbatim re-confirmed: no conditional return-false path exists anywhere in its body) — but **none of these return values ever reaches the client**, since the controller discards them all.

**Why this is already safe, without any additional prefiltering:** every repository call in this family operates through `Contacts::where('group_id', $contactGroups->id)->...` (source) — and `$contactGroups` is, by the time any of these branches runs, already resolved and verified-owned via §8's `resolveOwnedContactGroup()`. A `uid` in the submitted `ids` array that is foreign or nonexistent simply matches zero rows inside that already-owned `group_id` scope — exactly as naturally excluded as it would be by an explicit prefilter, with no separate query needed. **The controller's own pre-existing "ignore the repository's return value, always report success" shape is not a defect to correct — it is the mechanism that makes foreign and nonexistent ids indistinguishable for this family**, for free: both produce the identical "success" response, and neither mutates a victim row (the `where('group_id', ...)` scoping prevents that regardless of what the client claims via `ids`).

**`copy`/`move`'s `target_group` remains a separate, explicitly-scoped case** (§8): `ContactGroups::where('uid', $target_group)->where('customer_id', Auth::id())->first()`, with the existing `'Group not found'` error response preserved identically for a foreign and a nonexistent `target_group` — this part of Round 1's design is unchanged and correct.

**Family B, locked table (materially different from Family A/C — success is the response for every composition, not merely the owned-nonempty ones):**

| Scenario | Response | Victim data mutated? |
|---|---|---|
| Owned-only ids | Existing success response | No (only the caller's own data) |
| Owned + foreign ids | Existing success response — identical | No |
| Owned + nonexistent ids | Existing success response — identical | No |
| Foreign-only ids | Existing success response — identical (repository's `false`/no-op return is discarded by the controller) | No |
| Nonexistent-only ids | Existing success response — identical | No |
| `copy`/`move`, foreign `target_group` | Existing `'Group not found'` error response | No — action never reaches the repository call |
| `copy`/`move`, nonexistent `target_group` | Identical `'Group not found'` error response | No |

No additional controller-side id-prefiltering is added for Family B's `ids` array — §8's `$contact` (and, for `copy`/`move`, `target_group`) ownership resolution is the complete, sufficient fix for this family.

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
called as the first statement, identical reasoning to §8 (avoiding `firstOrFail()`/`ModelNotFoundException`'s 500-status behavior, §3.8).

**The repository's `destroy()` method itself is also modified**, because the caller-level ownership check on `$blacklist` alone does not close the method's own second, independently cross-tenant query (§3.4). **Corrected this round (Correction Round 2, Correction A) to the exact `is_admin`-branched design §3.4 now locks** — a uniform `customer_id`-scoped fix, as originally proposed in Correction Round 1, would silently zero out the resubscribe side effect for an admin-owned/global row (§3.4's own three-case analysis), so the fix instead branches on the **row's own owner type** (`$blacklists->user->is_admin`, via the model's existing `belongsTo(User::class)` relation), mirroring `store()`'s own already-established idiom: a customer-owned row (whether deleted by that customer or by admin acting on it) gets the `customer_id`-scoped lookup; an admin-owned/global row keeps the original, fully unscoped lookup, preserving its intentional global effect exactly. This is the **only** logical change in `destroy()`; its own zero-row `GeneralException` throw and every other line are unmodified.

**Customer `batchAction()`** gains the identical id-prefiltering pattern as §9's corrected batch-response contract (Correction E): `$ownedIds = Blacklists::where('user_id', Auth::id())->whereIn('uid', $ids)->pluck('uid')->all();` immediately before the existing, unmodified `$this->blacklists->batchDestroy($ownedIds)` call. `batchDestroy()`'s own existing zero-row `GeneralException` throw is unchanged — the same five-scenario table §9 defines applies identically here: owned+foreign and owned+nonexistent both succeed identically; foreign-only, nonexistent-only, and an empty submitted list all hit the identical pre-existing zero-row error path.

**`store()` also gains a repository-level fix, corrected this round (Correction B)** — independent of `destroy()`/`batchAction()`'s ownership checks, since `store()` is a creation path, not a mutation of an existing owned row: its own post-processing cache-recompute step (§3.4) is scoped using the exact `is_admin` distinction the method already establishes one block earlier for its `Contacts.status` update, reusing rather than inventing an authorization model:
```php
$groupIdsQuery = Contacts::whereIn('phone', $numbers);
if (! $user->is_admin) {
    $groupIdsQuery->where('customer_id', $user->id);
}
$groupIds = $groupIdsQuery->pluck('group_id')->filter()->unique();
```
Customer callers now only recompute cache for their own groups; admin callers keep the exact existing global behavior.

**Admin `Admin\BlacklistsController::destroy()`/`batchAction()` are not modified in any way at the controller level** — they continue calling the exact same, now-partially-modified repository methods (§3.4's own findings: Correction A's `destroy()` fix and Correction B's `store()` fix are both actor-type-preserving by design, confirmed above), which is correct and intentional (§3.4, §6).

**Corrected this round (Correction Round 2, Correction C) — the precise, final binding rule for `Admin\BlacklistsController`, replacing every prior "entirely untouched"/"zero admin-controller changes" phrasing anywhere in this document with one accurate statement:** admin Blacklists' **tenant scope remains global**, its **authorization remains unchanged**, `index()`/`export()`'s own bodies remain byte-identical, `destroy()`/`batchAction()`'s own controller bodies remain byte-identical, and `search()`'s own data-selection/pagination/DataTables response shape remains unchanged — **only** the two `$assign_to` output-escaping expressions inside `search()` are modified, per §13 (XSS finding #2). "Untouched" describes tenant scope, authorization, and every method's own selection/mutation logic; it never describes the literal byte-for-byte content of `search()`'s own method body, which this contract explicitly, narrowly modifies.

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
| Family A/C batch (`ContactGroups`/`Blacklists`-level `batchAction`): owned+foreign or owned+nonexistent ids present | Foreign and nonexistent ids are silently excluded from the id-prefiltered set; the operation succeeds on the owned subset only; identical response for both compositions | §9, §11 (Correction Round 2, Correction B) |
| Family A/C batch: foreign-only, nonexistent-only, or an empty submitted list | Filtered set is empty; `GeneralException` is thrown (unmodified repository behavior), uncaught by the controller, reaches `Handler.php` — identical error response across all three compositions, genuinely different from the owned-subset success case above | §9, §11 (Correction Round 2, Correction B) |
| Family B batch (`Contacts`-level `batchActionContact`'s `destroy`/`subscribe`/`unsubscribe`/`copy`/`move`): **any** composition of owned/foreign/nonexistent ids | **Always the existing success response**, regardless of composition — the controller discards every one of these repository methods' own return values (§9); foreign/nonexistent ids are naturally excluded by the already-owned `where('group_id', ...)` scope, never mutated, but this never surfaces as a different response shape from a genuine success | §9 (Correction Round 2, Correction B — materially different from Family A/C, not merely a re-labeling) |
| `target_group` (copy/move) is foreign or nonexistent | Identical existing `'Group not found'`-shaped JSON error, for both cases | §8 |
| `message_form`/`sms_form` outside the 3-value enum | Standard validation-failure response, no dynamic property access attempted | §8 |
| Malformed/missing required input on any remediated action | Unchanged — standard Laravel `FormRequest`/manual-`Request` validation behavior, not touched by this remediation | Unmodified |

No new response shape, status code, or error-page convention is invented — every entry above is either this application's own existing, verified `Handler.php` behavior, or its existing, unmodified validation behavior.

---

## 15. (Reserved — see §21 for the discovered-but-out-of-scope findings restated as forbidden scope.)

---

## 16. Exact implementation allowlist

**First re-derived mechanically in Correction Round 1 — the original 7-path allowlist was withdrawn as incomplete: it omitted the `UpdateContactGroup` `FormRequest` compatibility fix (Correction C) and the `EloquentBlacklistsRepository` cross-tenant side-effect fixes (Corrections A/B). Re-confirmed, unchanged in count, this round (Correction Round 2) — only items 1 and 5's own exact fix descriptions are refined below (the batch-family split, §9; the `destroy()` `is_admin`-branched design, §3.4/§11) to match the corrected designs; no path is added or removed.** Closed, numbered, path-level, no wildcards, no duplicate path, exactly 9 unique sequential entries. Any additional path required during this remediation's implementation is a required-10th-path-shaped stop condition (§22).

### Controllers (3 modified)

1. `app/Http/Controllers/Customer/ContactsController.php` — modified: the ownership-resolution helper (§8), **32** (not 27 — Correction F) action signatures changed from implicit `ContactGroups $contact`/`ContactGroupFields $field_id` binding to `string`-typed parameters resolved via that helper, 11 added `$this->authorize()` calls (§7), the `message_form`/`sms_form` whitelist (§8), the `target_group` ownership fix (§8), **the Family A (`ContactGroups`-level `batchAction`) id-prefiltering only — Family B (`Contacts`-level `batchActionContact`) requires no additional id-prefiltering beyond §8's own `$contact` resolution, corrected this round, §9 Correction B.** `storeImportContact()`'s own `customer_id` line is explicitly **not** changed (Correction J — closed structurally by the binding fix alone). Zero route, zero public-action (§3.5), zero unrelated method changes.
2. `app/Http/Controllers/Customer/BlacklistsController.php` — modified: the ownership-resolution helper (§11), `destroy()`/`batchAction()` signature/body changes per §11 (Family C id-prefiltering). Zero route, zero `index`/`search`/`create`/`store` changes.
3. `app/Http/Controllers/Admin/BlacklistsController.php` — modified: exactly the two `e()`-wrapping changes in `search()` per §13. Zero tenant-scope/authorization change of any kind; `destroy()`/`batchAction()`/`index()`/`export()`'s own bodies remain byte-identical (§11).

### FormRequest (1 modified — new in Correction Round 1, Correction C)

4. `app/Http/Requests/Contacts/UpdateContactGroup.php` — modified: `rules()`'s `$id`-derivation portion becomes type-aware (model-bound API case vs. raw-string web case), per §3.2/§8's exact design — not literally one line (§3.2 Correction F). `authorize()` and `messages()` unchanged; every other part of `rules()` unchanged.

### Repository (1 modified — new in Correction Round 1, Corrections A/B)

5. `app/Repositories/Eloquent/EloquentBlacklistsRepository.php` — modified: `destroy()`'s re-subscribe query gains the `is_admin`-branched design corrected this round (§3.4/§11, Correction Round 2, Correction A) — a customer-owned row's resubscribe is scoped to `where('customer_id', $blacklists->user_id)`, an admin-owned/global row's resubscribe stays fully unscoped; `store()`'s cache-recompute query gains the method's own existing `is_admin` distinction (Correction B, unchanged this round). No other line, no other method, changes.

### View (1 modified)

6. `resources/views/customer/contactGroups/show.blade.php` — modified: exactly the four `{!! !!}` → `{!! Js::from(...) !!}` replacements per §12. Zero other line changes.

### New focused security tests (3 new)

7. `tests/Feature/Security/ContactGroupsSecurityTest.php` — new (§17).
8. `tests/Feature/Security/ContactsSecurityTest.php` — new (§17).
9. `tests/Feature/Security/BlacklistsSecurityTest.php` — new (§17).

**Counts** — Controller: **3** (all modified). FormRequest: **1** (modified). Repository: **1** (modified). View: **1** (modified). Test: **3** (all new). **Overall total: 9. Stop threshold: 10** (9 + 1).

No `database/`, `routes/`, `config/`, or `app/Models/` path is listed above — this remediation authorizes **zero** schema, routing, permission-config, or model changes. Exactly one `app/Http/Requests/` path and one `app/Repositories/` path are authorized, each with a mechanically-proven, narrowly-scoped reason stated above (§3.2 Correction C, §3.4 Corrections A/B) — neither was assumed or added speculatively. No other Design System Slice-5 view is listed above — this remediation is entirely presentation-neutral outside the one narrow XSS-fix line-range in item 6 (§21).

---

## 17. Exact focused test requirements

Each new file asserts actual HTTP behavior and data outcomes — never a superficial middleware-string assertion — and follows the established idioms in `tests/Feature/Security/` (§3.9): `RefreshDatabase`, distinct non-id-1 actor accounts for every test (never relying on the superadmin bypass, §3.10/§6), and the exact response shape (HTML 404 vs. AJAX 200-status JSON error body, §3.8 item 3/§14) determined by running the assertion against the real application rather than assumed.

1. **`tests/Feature/Security/ContactGroupsSecurityTest.php`**:
   - Own group readable (`show`) / foreign group not readable, identical response to a nonexistent `uid` (§14), and the two are asserted together in one test proving genuine indistinguishability — mirroring `HotLeadsSecurityTest.php:154-171`'s own established pattern (§3.9).
   - `activeToggle`/`copy`/`update`/`destroy` all denied on a foreign group, each proven to leave the victim's row unmutated; each succeeds on the actor's own group (legitimate-actor path preserved).
   - **`UpdateContactGroup` compatibility (Correction C), own dedicated coverage:** an own web-customer `update()` still validates (including the existing name-uniqueness rule) and succeeds; a foreign web-customer `update()` is denied via the corrected `rules()` path; a nonexistent web `uid` produces an identical denial to the foreign case; ordinary validation semantics (missing/duplicate `name`) are unchanged. A separate assertion confirms the shared `FormRequest`'s `authorize()`/`messages()` are unmodified and that the API route's own model-bound `update()` call (not otherwise exercised by this remediation, §3.11.1) does not error when the `FormRequest`'s corrected `rules()` runs against an already-resolved `ContactGroups` instance — proving the `instanceof` branch is real, not merely asserted.
   - **Family A batch response contract (Correction Round 2, Correction B), locked exactly per the five-scenario table (§9, §14):** for `destroy`/`activeToggle`/`disable`, five separate assertions — owned+foreign succeeds (owned row mutated, foreign row untouched); owned+nonexistent succeeds identically; foreign-only produces the pre-existing `GeneralException`-driven error response, with the foreign row's own data confirmed unmutated; nonexistent-only produces the **identical** error response/body as foreign-only (the core indistinguishability proof); an empty submitted list (if accepted) produces the same error response as the two zero-owned cases.
   - `copy`/`move`'s `target_group`: foreign and nonexistent `target_group` both rejected identically; own `target_group` succeeds.
   - `message_form`/`sms_form` outside the 3-value enum rejected; each of the 3 legitimate values still works end-to-end (legitimate-actor path preserved).
   - **XSS finding #1 regression, corrected this round (Correction I).** `show()`'s own `$contact_groups` query excludes the viewed group itself (`where('uid', '!=', $contact->uid)`, §3.6) — a test that only names the *viewed* group maliciously never exercises the vulnerable serialization. Corrected test design: create the group being viewed (ordinary name); create a **second**, separately-owned-by-the-same-customer group with a malicious name (e.g. `<img src=x onerror=alert(1)>`), so it is genuinely included in `$contact_groups`; separately seed a malicious keyword so it appears in the opt-in/opt-out remainder lists; request the **first** group's `show` page; assert the response's inline script contains the `Js::from()`-encoded, HTML-safe form of the payload and contains no raw, unescaped `<img` tag anywhere in the rendered output.
2. **`tests/Feature/Security/ContactsSecurityTest.php`**:
   - Own contact in own group readable/mutable (`status`, `edit`/`update`, `delete`); a contact whose *parent group* is foreign is denied identically to a nonexistent parent (§9's Pattern-A closure).
   - `create`/`store`/import actions cannot target a foreign group — each denied identically to targeting a nonexistent group.
   - **`storeImportContact()`, corrected this round (Correction J):** no test asserts a differing-values case (withdrawn — structurally impossible post-fix, §9). Instead, one invariant assertion: every contact created via this path has `customer_id` equal to the (now-guaranteed-owned) `$contact->customer_id`, confirming the historical mismatch class cannot recur, without claiming a legitimate secured request can ever produce a different value.
   - Every previously-ungated action (§7's 11 items reachable from this file's own scope: `importProcessData`, `optInKeyword`, `optOutKeyword`, `deleteOptInKeyword`, `deleteOptOutKeyword`, `importMapping`, `importRun`, `importValidate`, `countContacts`, `downloadFailedContacts`) now denies an actor lacking the newly-required permission.
   - `deleteContactField`'s second bound parameter: a field belonging to a foreign group is denied identically to a nonexistent field id.
   - **Family B batch response contract, corrected this round (Correction Round 2, Correction B) — materially different from Family A/C, per §9's own re-derived table.** For each of `destroy`/`subscribe`/`unsubscribe`/`move`: owned-only, owned+foreign, owned+nonexistent, foreign-only, and nonexistent-only ids all produce the **identical existing success response** (the controller discards the repository's return value entirely, §9) — the test must confirm this is genuinely true (not assumed) and, separately, that the foreign/nonexistent rows' own data is never mutated in any of the foreign/nonexistent-containing cases, proving the silent-success shape is safe rather than merely convenient. For `copy`: an owned-source-group + foreign or nonexistent contact `uid` set produces the existing success response with zero rows copied (the source `where('group_id', ...)` scoping naturally excludes them); a genuinely-owned contact is still copied correctly (legitimate-actor path preserved). `copy`/`move`'s own `target_group` retains the separate, already-covered foreign/nonexistent-denial coverage (file 1's own pattern, applied here for the `Contacts`-level actions specifically).
3. **`tests/Feature/Security/BlacklistsSecurityTest.php`**:
   - Customer: own entry deletion works; foreign entry deletion denied identically to a nonexistent entry.
   - **`destroy()` side effect, corrected this round (Correction Round 2, Correction A) — all three cases from §3.4's own analysis, not merely the original one:**
     1. *Customer-owned row, customer deletes:* seed customer A's blacklist entry for number X, customer A's own `Contacts` row for X, and — separately — customer B's `Contacts` row for the **same number X**. Customer A deletes A's own blacklist entry. Assert A's own `Contacts` row is resubscribed, **and** assert B's `Contacts` row's `status` is unchanged.
     2. *Customer-owned row, admin deletes:* identical seed shape as case 1, but the deleting actor is an admin exercising the global-delete capability. Assert the same outcome as case 1 — A's own row resubscribed, B's row untouched — proving the fix targets the row's own owner regardless of who triggers the deletion.
     3. *Admin-owned/global row, admin deletes:* seed a blacklist entry whose own `user_id` belongs to an admin account, and separately seed an ordinary customer's `Contacts` row for the same number. Admin deletes the admin-owned entry. Assert the customer's `Contacts` row **is** resubscribed — proving the fix preserves the existing, intentional global/unscoped resubscribe effect for this case, rather than silently breaking it by searching for `Contacts.customer_id = <admin's own id>`.
   - **`store()` side effect (Correction B), with explicit customer and admin coverage (Correction Round 2, Correction G):**
     - *Customer store:* seed a `ContactGroups`/`Contacts` pairing owned by customer B containing a `Contacts` row for number X; customer A submits number X to A's own blacklist `store()`; assert (by direct database query, not merely a controller-status assertion) that B's `ContactGroups` row's cache is not recomputed/touched, while A's own matching group(s), if any, are recomputed as expected.
     - *Admin store:* an admin submits number X to `store()`; assert the existing global `Contacts.status` update and `ContactGroups.updateCache()` behavior fires across tenant boundaries exactly as it does today, unaffected by Correction B's customer-only scoping branch.
   - **Family C batch response contract (Correction Round 2, Correction B)**, identical five-scenario `GeneralException`-driven table as Family A (file 1), applied to customer `batchAction()`.
   - Admin: `index`/`search`/`export` still return all tenants' rows (global visibility genuinely preserved, not accidentally narrowed); `destroy`/`batchAction` still succeed against **any** tenant's entry, with the corrected three-case `destroy()` resubscribe behavior above proven specifically for admin-triggered deletions of both customer-owned and admin-owned rows (admin's legitimate global-delete capability genuinely preserved, proven by seeding two customers' entries and having the admin delete both in the same test run).
   - **XSS finding #2 regression**: seed a user (via the same OAuth-registration code path identified in §3.7, or a direct factory state reproducing its unsanitized-write behavior) with a `first_name` containing `<img src=x onerror=alert(1)>`, create a blacklist entry for that user, request the admin `search()` AJAX response, assert the returned `user_id` field's HTML is properly entity-encoded (`&lt;img` not `<img`) while the trusted `<a href=...>` structural wrapper is still present and points at the correct customer profile URL — proving both that the payload cannot execute and that the legitimate link functionality survives.

All database-outcome assertions above (destroy/store side effects, batch mutation scope) query the database directly to confirm the actual row state — never inferred solely from a controller's `'status' => 'success'`/`'error'` response body, since Family B's own corrected model (above) proves that response alone is not always a reliable signal of what actually mutated.

No test in this list is a brittle full-page snapshot; every assertion is a targeted status-code/response-body/data-outcome check.

---

## 18. Fixture policy

`contact_groups`, `contacts`, `blacklists`, `contact_group_fields`, `contact_groups_optin_keywords`, `contact_groups_optout_keywords` are long-standing, core Ultimate SMS tables — unlike the Dashboard remediation's `ai_settings`/`ai_box_campaign_map` (which had no tracked migration at all), **no schema-visibility gap is currently known for any table this remediation's tests touch**, and none is expected. **If implementation nonetheless discovers a genuine gap**, the identical, already-established authorization from the merged Dashboard Security Remediation Contract (§14.1 there) applies verbatim: a narrowly-scoped, test-only ephemeral-schema fixture may be created inside the three already-allowlisted test files only (§16 items 7-9, corrected this round from the original, now-stale "items 5-7" reference), derived only from existing model/migration evidence, gated by a `Schema::hasTable`/`hasColumn` check, cleaned up deterministically, and never a reason to `markTestSkipped()` any of §17's core assertions. No `database/` migration path is authorized by this contract under any circumstance (§16, §20 item 1).

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

1. `git diff --stat -- database routes config app/Models` compared against §16 (which contains none of these paths) → must be completely empty. `git diff --name-only -- app/Repositories app/Http/Requests` compared against §16 → must equal **exactly** `app/Repositories/Eloquent/EloquentBlacklistsRepository.php` and `app/Http/Requests/Contacts/UpdateContactGroup.php` — no other file in either directory.
2. `git diff --stat` restricted to paths outside §16's exact 9 → must be completely empty; `git diff --name-only` + `git ls-files --others --exclude-standard` must equal §16's allowlist exactly.
3. `grep -n "ContactGroups \$contact\|ContactGroupFields \$field_id" app/Http/Controllers/Customer/ContactsController.php` → must show these implicit-binding type-hints **only** on the four public actions (§3.5); every other, remediated action must show `string $contact`/`string $field_id` instead.
4. `grep -n "Blacklists \$blacklist" app/Http/Controllers/Customer/BlacklistsController.php` → must show zero matches (replaced by `string $blacklist`, §11); `grep -n "Blacklists \$blacklist" app/Http/Controllers/Admin/BlacklistsController.php` → this specific binding pattern must be unchanged from this contract's own base SHA — `destroy()`'s implicit `Blacklists $blacklist` binding is not itself touched, even though `search()` elsewhere in the same file is (§11, Correction C).
5. `grep -c "firstOrFail(" app/Http/Controllers/Customer/ContactsController.php app/Http/Controllers/Customer/BlacklistsController.php` → must remain **zero** in both, confirming §3.8's `ModelNotFoundException`-avoids-500 requirement was actually honored, not merely stated.
6. `grep -n "abort_unless(\$contact\|abort_unless(\$blacklist\|abort_unless(\$field" app/Http/Controllers/Customer/ContactsController.php app/Http/Controllers/Customer/BlacklistsController.php` → present, confirming the explicit `abort(404)` pattern (§8, §11) was actually used.
7. `grep -c "authorize('create_contact')\|authorize('update_contact_group')\|authorize('view_contact')" app/Http/Controllers/Customer/ContactsController.php` → each count increases by exactly the number of §7's newly-gated actions using that string; no new permission string appears anywhere (`grep -n "'[a-z_]*'" ` diffed against `config/customer-permissions.php`'s own existing key list confirms every string used already exists there).
8. `grep -n "Js::from(" resources/views/customer/contactGroups/show.blade.php` → exactly 4 occurrences (§12); `grep -c "{!! \$contact_groups !!}\|{!! \$remain_opt_in_keywords !!}\|{!! \$remain_opt_out_keywords !!}"` → zero (fully replaced).
9. `grep -n "e(\$customer_profile)\|e(\$customer_name)\|e(\$blacklist->user->displayName())" app/Http/Controllers/Admin/BlacklistsController.php` → present (§13); `git diff app/Http/Controllers/Admin/BlacklistsController.php` (against the pre-implementation base) shows **only** these lines changed, nothing else in `search()` or elsewhere in the file.
10. A runtime/database-level check (not static grep): after the batch-mixed-ownership tests (§17) run, the foreign/other-tenant rows referenced in each test are queried directly and confirmed unmutated.
11. `php artisan route:list` (or equivalent) diffed before/after → every existing CRM route URI/name/controller-action binding is byte-identical; only the controller method bodies/signatures changed, never the routing table.
12. `git diff app/Repositories/Eloquent/EloquentBlacklistsRepository.php` shows exactly two changed blocks (the `destroy()` `is_admin`-branched resubscribe fix, corrected this round — Correction Round 2, Correction A; the `store()` `is_admin`-guarded cache scoping, Correction B) — no other method, no other line.
13. `git diff app/Http/Requests/Contacts/UpdateContactGroup.php` shows changes confined to `rules()`'s `$id`-derivation portion only — `authorize()` and `messages()` byte-identical to the pre-implementation base.
14. `grep -c "instanceof ContactGroups" app/Http/Requests/Contacts/UpdateContactGroup.php` → exactly 1, confirming the type-branch (Correction C) is actually present, not merely described.
15. **Corrected this round, Correction Round 2, Correction B:** each of the three batch families (§9/§14) is checked separately, not against one shared table — Family A (`ContactGroups`-level `batchAction`) and Family C (customer `Blacklists` `batchAction`): each of the five compositions run against a live endpoint, confirming owned-nonempty compositions succeed and zero-owned compositions produce the identical `GeneralException`-driven error response; Family B (`Contacts`-level `batchActionContact`): every composition, including foreign-only and nonexistent-only, is confirmed to produce the existing success response, with a direct database check confirming zero victim-row mutation regardless of composition.
16. **New this round:** `grep -n "is_admin" app/Repositories/Eloquent/EloquentBlacklistsRepository.php` → present in both `destroy()` and `store()`, confirming both fixes reuse the same established distinction rather than each inventing its own.
17. `php artisan test tests/Feature/Security/ContactGroupsSecurityTest.php tests/Feature/Security/ContactsSecurityTest.php tests/Feature/Security/BlacklistsSecurityTest.php` → the reported summary line must show **0 skipped, 0 failed**.

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
- Author or authorize any schema, route, config, or model change of any kind — none is required and none is authorized even if later claimed convenient. **Exactly one `FormRequest` file (`UpdateContactGroup.php`) and exactly one repository file (`EloquentBlacklistsRepository.php`) are authorized, each for a single, narrowly-scoped, mechanically-proven reason (§3.2 Correction C, §3.4 Corrections A/B) — no other `app/Http/Requests/` or `app/Repositories/` path may be touched, and neither of these two authorized files may receive any change beyond the exact one described (§16, §20 items 1, 12-14).**
- Redesign or narrow the `$user->id === 1` superadmin bypass (§3.10, §6) — inherited unchanged.
- Begin Slice 5 visual implementation, Slice 4, Slice 6, or any other RFC/initiative (COO, SEO, Outreach, Website, Calendar, Ads) — this contract authorizes only its own, later, separately-authorized implementation (§23).
- Deploy, force-push, push directly to `main`, or merge anything.

---

## 22. Stop conditions

This remediation's implementation must stop, leave the working tree unstaged, and report rather than proceed, if:

- Any path beyond §16's 9-item allowlist is required — the **10th** path.
- `php artisan route:list` shows any CRM route's URI, name, or controller-action binding changed.
- Any table this remediation's queries depend on (`contact_groups.customer_id`, `contacts.group_id`/`customer_id`, `blacklists.user_id`, `contact_group_fields.contact_group_id`/`uid`) is found to have a materially different shape than §3 documents.
- The focused security suite (§19) reports any skipped test not resolved by §18's fixture authorization, or any failed test.
- Any existing test outside §16's own 3 new files fails for a reason not fixable within this remediation's own allowlist, and that failure cannot be eliminated through environment-only correction.
- Any permission string, or any Gate/permission-config path, is found to require a change — none is authorized; this remediation reuses existing strings exactly (§7, §20 item 7).
- The admin Blacklists global-visibility model (`index`/`search`/`export`, and `destroy`/`batchAction`'s own ability to act on any tenant's row, including Correction A/B's own actor-preserving repository fixes) is found to be narrowed by any change in this remediation.
- A genuine need is found to touch the API-layer controllers (§3.11.1), the `importMapping()` file-path handling (§3.11.2), or `EloquentContactsRepository::store()`'s `user_id` handling (§3.11.3) in order to make this remediation's own allowlisted fixes function correctly — implementation must stop and report rather than silently expand scope to cover them.
- `UpdateContactGroup::rules()`'s `instanceof ContactGroups` branch is found to actually change API-route behavior in any way (proving the two route contexts are not as cleanly separable as §3.2/§8 Correction C assumed).
- Any of the locked batch-response scenarios in **any** of the three families (§9/§14) is found not to match its own repository/controller's actual, observed behavior once tested directly — including, specifically, a Family B (`Contacts`-level) foreign/nonexistent composition found to produce anything other than the existing success response, or a Family A/C composition found to produce anything other than the `GeneralException`-driven error response for a zero-owned set.
- A legitimate customer request is found, after implementation, where `Auth::user()->id` and `$contact->customer_id` genuinely differ inside `storeImportContact()` (which would falsify Correction J's own "structurally impossible" conclusion and require reopening the withdrawn production-line fix).
- **New this round (Correction Round 2):** `Blacklists::destroy()`'s `is_admin`-branched resubscribe fix is found not to preserve the exact three-case behavior locked in §3.4/§11 — specifically, if an admin-owned/global row's deletion is found to no longer resubscribe a matching `Contacts` row across tenant boundaries as it does today, or if a customer-owned row's deletion (by either actor) is found to resubscribe any row other than that row's own true customer owner.
- **New this round (Correction Round 2):** `EloquentBlacklistsRepository::store()`'s cache-recompute scoping, or `destroy()`'s resubscribe scoping, is found to require a check beyond the existing `$user->is_admin`/`$blacklists->user->is_admin` distinction already established in this same class.
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is found necessary to reference.

---

## 23. Governance closing — human-only merge, correction rounds, and the Slice-5 handoff

- **Human-only merge.** No automatic merge of this contract, and no automatic merge of its own future implementation, under any condition.
- **`maximum_correction_rounds: 2`** applies to this contract, unchanged from every other contract in this repository's own established convention.
- **`advance_automatically: false`, `start_automatically_after_contract_merge: false`.** Merging this contract does not begin its own implementation. A separate, explicit, future human instruction is required.
- **Merging this contract does NOT satisfy the Slice-5 prerequisite.** The merged Slice-5 contract's own §0/§7 requires this remediation's **implementation** — not merely this contract — to be human-merged, with the admin Blacklists global model preserved and both XSS findings resolved, before Slice 5 implementation may be authorized. Merging this document alone leaves Slice 5 exactly as blocked as it was before this document existed.
- **Post-remediation handoff, explicit, restated from the merged Slice-5 contract's own §7.** Once this remediation's own implementation is separately authorized, implemented, and human-merged, the exact resulting merge SHA must be reported so that Slice 5's own later, separate implementation authorization can pin: (1) that exact remediation-implementation merge SHA; (2) the exact then-current `origin/main` SHA Slice 5 implementation would be based on; (3) the exact focused security test paths/commands this remediation introduces (§16 items 7-9, §19). During Slice 5 implementation: these three test files remain unchanged; they run first, zero failures required; every remediation-changed path *outside* Slice 5's own 29-view allowlist remains byte-clean relative to this remediation's own post-merge baseline; and — since this remediation's own §16 item 6 modifies `contactGroups/show.blade.php`, one of Slice 5's own 29 allowlisted views — that view remains eligible to receive its already-authorized Slice-5 presentation changes, provided the `Js::from()` encoding this remediation introduces (§12) is preserved exactly and proven still-intact by this remediation's own pinned XSS-regression test, re-run per above. This contract does not, and cannot, supply the SHA now, since the implementation it describes does not yet exist.

---

## 24. Contract self-audit

1. Every `ContactsController` CRM action is inventoried (45 public methods, 2 non-routed internal helpers excluded, 43 genuinely routed, mechanically re-counted this round — §3.2 Correction F) — not sampled. ✓
2. The `ContactGroups`/`ContactGroupFields`/`Blacklists` route-model-binding surface is covered exhaustively, with the exact binding key (`uid`) mechanically confirmed for all three, including a correction of an initial audit-agent miscount on `ContactGroupFields` (§3.1). ✓
3. Every raw batch-ID surface (`ContactGroups`-level and `Contacts`-level batch actions, both Blacklists controllers) is covered, with repository method bodies **and their actual controller-level callers** quoted/traced verbatim — Correction Round 2's own re-inspection found even Round 1's own corrected "one five-scenario table" was still wrong for the `Contacts`-level family specifically (the controller discards that family's repository return values entirely, a fact Round 1 had not checked), corrected into three mechanically-distinct family models: Family A/C (`GeneralException`-driven, five-scenario table) and Family B (always succeeds regardless of composition, per the controller's own confirmed return-value-ignoring shape) (§3.3, §3.4, §9, §14, Correction Round 2 Correction B). ✓
4. Every relevant Contacts child-resource mutation is covered, and both independent query-defect patterns (Pattern A/B) are itemized exhaustively, including the mismatch bug that undermines Pattern B's own coincidental protection — closed structurally by the binding fix alone, with the originally-proposed but now-impossible-to-test production-line change explicitly withdrawn (§3.2, §9, Correction J). ✓
5. Customer Blacklists is fully covered, including **two** previously-undocumented, genuinely cross-tenant side effects (`destroy()`'s unscoped resubscribe and `store()`'s unscoped cache-recompute, §3.4) — both mechanically proven to survive caller-level ownership scoping alone, and both closed by a targeted, minimal repository-level fix rather than left as an incorrect "caller ownership alone closes it" claim (Corrections A, B). `destroy()`'s own fix is further corrected in Correction Round 2 (Correction A) from a uniform `customer_id`-scoping (which would have silently broken the resubscribe effect for an admin-owned/global row) to the precise, three-case, `is_admin`-branched design that preserves all three cases correctly. ✓
6. Admin Blacklists' global semantics are preserved by explicit design — zero admin-*controller* tenant-scope/authorization changes anywhere in §16, and the two repository-level fixes (Corrections A/B) are each individually confirmed actor-preserving for the admin path, not merely asserted (§11), including Round 2's own refinement of Correction A specifically to close the admin-owned-row gap Round 1 had not covered. The admin `search()` XSS-escaping change is accurately, precisely described (Correction Round 2, Correction C) — every prior "entirely untouched"/"zero admin-controller changes" phrasing is corrected to distinguish tenant-scope/authorization (genuinely unchanged) from the two explicitly-authorized escaping lines (changed), with no remaining occurrence of the overbroad phrasing anywhere in this document. ✓
7. Shared repository callers are inventoried exhaustively and precisely — the original overbroad claim that no repository method beyond the six batch methods is API-shared is corrected: `EloquentContactsRepository`'s singular `store()`/`update()`/`destroy()` are genuinely shared with **two** API controller classes (`API\ContactsController` and `API\ContactsHTTPController`, the second newly identified this round), while confirming this remains irrelevant to this contract's own safety since neither singular method of `EloquentContactsRepository` is itself modified — that repository is left unchanged, and the separate, deferred API IDOR remains unfixed as a result (§3.3, §3.4, Correction H). `EloquentBlacklistsRepository` is a different repository entirely and **is** intentionally modified, per §16 item 5 — this item's "unmodified" conclusion applies only to `EloquentContactsRepository`, never to `EloquentBlacklistsRepository`. ✓
8. Public subscribe/unsubscribe is confirmed unaffected — traced end-to-end, confirmed to touch `Blacklists` only via a pre-existing, unscoped, read-only, boolean existence check this contract does not alter (§3.5). ✓
9. Both XSS findings receive an explicit, evidence-backed disposition (both: exploitable, both: remediate directly) with an exact, minimal, mechanically-derived fix (§3.6, §3.7, §12, §13) — neither is left as "evaluate later," and this round corrects the XSS-#1 test design itself to exercise a second, genuinely-included group rather than the excluded, currently-viewed one (Correction I). ✓
10. No historical insecure behavior is described anywhere as something to preserve — every table in §14 states the *remediated* target behavior, never the pre-remediation state as a goal. ✓
11. Foreign/nonexistent response behavior is defined precisely, including the non-obvious `ModelNotFoundException`→500 and `wantsJson()`→200-with-error-body findings (§3.8), and the binding-resolution design (§8, §11) is chosen specifically because it is the only pattern proven to produce a genuinely indistinguishable response for both cases given those two findings. The `UpdateContactGroup` `FormRequest`'s own dependency on the bound-model type is identified and closed with a design that explicitly preserves the separate, out-of-scope API route's own unchanged behavior (§3.2, §8, Correction C). ✓
12. Mixed-batch behavior is defined as a precise, per-family, evidence-backed target (§9, §11, §14) — Round 1 corrected the original overbroad "always identical regardless of composition" claim once; Round 2 (Correction B) found that single correction was itself still wrong for the `Contacts`-level family, and split the model into three mechanically-distinct families, each verified directly against its own controller's actual, observed handling of the repository's return value — never left ambiguous or assumed uniform across families. ✓
13. The exact test actor model is defined (distinct non-id-1 accounts per test, §17), the pre-existing superadmin bypass is explicitly never relied upon for an ordinary-customer test fixture, and this round corrects the superadmin/Gate-bypass-vs-ownership-query claim itself to be mechanically accurate rather than assumed (§6, Correction D). ✓
14. Focused security test files are named exactly, derived from this repository's own already-established `tests/Feature/Security/` naming convention (§3.9, §16, §17), not invented ahead of inspecting that convention, with this round's own additional coverage (UpdateContactGroup compatibility, the corrected batch table, both Blacklists side-effect fixes, the corrected XSS-#1 setup) folded into the same three files without adding a fourth. ✓
15. The implementation allowlist is literal, path-level, sequential, mechanically re-derived this round to exactly **9** items (not the original, incomplete 7), with a stop threshold of **10** (§16) — every added path (`UpdateContactGroup.php`, `EloquentBlacklistsRepository.php`) is backed by a specific, cited mechanical finding, never added speculatively. ✓
16. No Slice-5 presentation implementation is authorized anywhere in this document beyond the four exact, narrow XSS-fix lines (§21). ✓
17. `docs/automation/AI-AUTONOMY-STATE.json` is untouched. ✓
18. Every discovered-but-adjacent finding (API layer — now correctly described as two controller classes, file-path read, mass-assignable `user_id`, dead `ai_variants` route) is documented with its own reasoning for exclusion, never silently dropped and never silently folded in (§3.11, §21), including Round 2's own precise correction of the reasoning for `EloquentContactsRepository::store()` remaining untouched — "shared with the API layer," never "zero repository code" (Correction D). ✓
19. Every stale allowlist-item cross-reference (the fixture-authorization pointer to the three test files, §18) is corrected to the actual, final item numbers (7-9), not left pointing at the withdrawn 7-path allowlist's own numbering (Correction E). ✓
20. Every scope-of-change claim is checked against the literal size of its own proposed code, not merely its intent — `UpdateContactGroup.php`'s own "only rules()'s first line changes" claim is corrected to accurately describe a multi-line derivation block (Correction F). ✓
21. **This is Correction Round 2 of a maximum of 2 — the final allowed correction round for this contract.** ✓

---

## 25. Verification and publication

Performed, in order, before commit:

1. Markdown structural check — every numbered §16 item follows the pattern `N. `path``, no broken heading levels, no unclosed code fences.
2. §16's numbered items counted mechanically and confirmed equal to exactly **9**, sequential, no gap, no repeated number.
3. Every path listed in §16 checked for uniqueness — no path string appears twice.
4. Mechanical search confirming zero stale binding claims remain from either prior pass, including this round's own seven: `$blacklists->user_id`-only scoping claimed to preserve admin-owned-row destroy semantics without the `is_admin` branch; Correction A described as actor-type-agnostic without distinguishing admin-owned rows; all three batch families claimed to share one zero-row response; a `Contacts`-level foreign-only batch described as producing an error without controller-level evidence; a generic `Contacts`-level id-prefilter claimed necessary despite the owned `group_id` scope already supplying the tenant boundary; admin `search()` described as "entirely untouched"/"admin controller untouched"; "zero repository code" (global, unqualified); the three security test files referenced as "§16 items 5-7"; any "7-path allowlist"/"stop threshold 8"/"8th path" reference; "Only `rules()`'s first line changes"; "27 remediated ContactGroups actions"; a "Ten actions"/eleven-item-list contradiction — all confirmed absent from this corrected document.
5. `git diff --check` — clean, no whitespace-error or conflict-marker findings.
6. `git diff --name-only origin/main...HEAD` — exactly one path: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-REMEDIATION-CONTRACT.md`.
7. `git status --short` — exactly one entry, the same path.
8. §16's numbered items re-confirmed sequential (1-9), unique, with the stop threshold stated consistently as the 10th path throughout.
9. Stage individually (`git add docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-REMEDIATION-CONTRACT.md`), never `git add -A`/`.`.
10. Commit message: `docs: finalize CRM security remediation contract`.
11. Push to `origin chore/design-system-m2-slice5-crm-security-remediation-contract` — a normal push, never force-pushed.
12. Do not implement. Do not merge. If `gh` remains unavailable, report the exact GitHub comparison URL.
13. **Do not merge. Do not begin this remediation's implementation. Do not begin Slice 5 visual implementation. Do not begin any other RFC or initiative.** All require separate, explicit, future human authorization. No test is run for this docs-only change — reported honestly as not run, no count fabricated.

---

*End of Design System M2 Slice 5 CRM Security Remediation Contract. Implementation requires a separate, explicit human instruction. This contract's own merge does not start or resume it, and does not by itself satisfy the Slice-5 prerequisite named in `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CONTRACT.md` §0/§7 — only this remediation's own subsequently human-merged implementation does that.*
