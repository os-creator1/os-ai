# Design System M2 Slice 5 — CRM Security Remediation: `countContacts` Tenant-Isolation Exception

**Status: draft authorization document only. This document authorizes, but does not itself perform, a future correction commit to the already-implemented, already-pushed, still-unmerged CRM security remediation branch. No production code, test code, or `docs/automation/AI-AUTONOMY-STATE.json` is touched by this document. Merging this document does not automatically start the correction it authorizes — a separate, explicit human resume instruction is required.**

**This is NOT Correction Round 3.** The merged `DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-REMEDIATION-CONTRACT.md` (PR #176) exhausted its own `maximum_correction_rounds: 2` — Correction Round 1 and Correction Round 2 (plus one already-closed post-round docs-only consistency exception) are its full, closed correction history, and that budget is not reopened, extended, or reinterpreted by this document. This is a **separate, exceptional, post-implementation security-gap authorization**, mechanically distinct in kind: it does not correct that contract's own drafting text, it authorizes a bounded future correction to the **implementation** the contract already produced, after that implementation was independently re-reviewed against its own actual, pushed code.

---

## 0. Governance

- Drafted on branch `chore/design-system-m2-slice5-crm-security-count-contacts-exception`, in the isolated linked worktree `../design-system-m2-slice5-count-contacts-exception-worktree`, based on `origin/main` at `fdc89f16979838da9ef18188baeaefa2c4658778` — confirmed exactly via `git fetch origin --tags && git rev-parse origin/main` at the start of this drafting pass. This is the identical base SHA the CRM security implementation (branch `agent/design-system-m2-slice5-crm-security-remediation`, head `277cfd4baab23bc9f5b59e693398ea47c53ee361`) was itself built from and is still based on — confirmed via `git rev-parse origin/agent/design-system-m2-slice5-crm-security-remediation`.
- **This document does not modify, rebase, or touch `agent/design-system-m2-slice5-crm-security-remediation` in any way.** That branch is read only, via `git show 277cfd4:<path>`, to mechanically confirm the vulnerability this document describes.
- `maximum_correction_rounds: 2` on the original CRM security remediation contract is unchanged and is not reopened by this document. This document introduces no correction-round counter of its own — it is a one-time exceptional authorization, not a recurring governance track.
- **Human-only merge.** No automatic merge of this document, and no automatic start of the correction it authorizes, under any condition.
- `advance_automatically: false`. `start_automatically_after_contract_merge: false`. Merging this document does not resume work on `agent/design-system-m2-slice5-crm-security-remediation` — a separate, explicit, future human instruction is required to do that.
- **The CRM security implementation remains unmerged and Slice 5 visual implementation remains unauthorized regardless of this document's own disposition** — this document neither advances nor blocks that state; it only authorizes a specific, bounded future correction to close a gap discovered after the implementation was pushed.
- No deployment. No force push. No automatic merge of anything, at any stage.

---

## 1. Mandatory preflight — verified

1. `git fetch origin --tags` — `origin/main` confirmed at exactly `fdc89f16979838da9ef18188baeaefa2c4658778`.
2. Confirmed `agent/design-system-m2-slice5-crm-security-remediation`'s current pushed head is exactly `277cfd4baab23bc9f5b59e693398ea47c53ee361` — via `git rev-parse origin/agent/design-system-m2-slice5-crm-security-remediation`. That branch is not checked out, not modified, and not rebased by this document.
3. Read in full before drafting: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-REMEDIATION-CONTRACT.md` (the merged contract this document takes an exception against).
4. Directly inspected, read-only, at the cited locations:
   - `app/Http/Controllers/Customer/ContactsController.php` at `origin/main` (pre-remediation) and at `277cfd4` (implemented) — §3 below quotes both.
   - `routes/customer.php` at `origin/main` — confirms the route `contacts.count_contact` (`POST contacts/count`).
   - `resources/views/customer/Campaigns/campaignBuilder.blade.php` at `origin/main` — confirms the exact UI evidence in §3.
   - `tests/Feature/Security/ContactsSecurityTest.php` at `277cfd4` — confirms exactly one existing `countContacts` test (permission-denial only, no ownership coverage).
   - `tests/Feature/Security/ContactGroupsSecurityTest.php` at `277cfd4` — confirms the tautological `UpdateContactGroup` model-bound-branch assertion cited in §5.
   - `app/Http/Requests/Contacts/UpdateContactGroup.php` at `277cfd4` — confirms `authorize()`/`messages()` are unchanged from the pre-remediation base, consistent with the merged contract's own §8/§20 findings; no production change to this file is authorized or required by this document.

---

## 2. This document's own exact file scope

This branch changes **exactly one file**: `docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-COUNT-CONTACTS-EXCEPTION.md` (this document). No `app/`, `resources/`, `routes/`, `tests/`, `database/`, `config/`, or `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting this document.

---

## 3. Mechanically-proven `countContacts` tenant-isolation gap

### 3.1 The merged contract's own classification — restated exactly, not reinterpreted

The merged `DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-REMEDIATION-CONTRACT.md` §3.2's own mechanical action table classifies `countContacts` in the same row as `index`, `search`, `create`, `store`, `batchAction`, `export` — all explicitly marked **not** `ContactGroups`-bound — with the single required fix stated as: *"`countContacts` gains a permission check (§7); the other 6 already correctly scoped/gated, unchanged."* §7's own table assigns it exactly `view_contact`, citing `export()`/`exportContact()`'s own gate as the basis. **No ownership-resolution fix was ever specified for `countContacts`'s own internal query** — the contract's own design treated it as needing authorization only, never tenant scoping.

### 3.2 The implemented code — confirmed via direct read of `277cfd4`

`app/Http/Controllers/Customer/ContactsController.php` at implementation head `277cfd4`, verbatim:
```php
public function countContacts(Request $request)
{
    $this->authorize('view_contact');

    $contactGroupIds = $request->input('contact_group_ids');

    if (is_null($contactGroupIds))
        return response()->json('No contact groups selected', 404);

    $total = Contacts::whereIn('group_id', $contactGroupIds)->where('status', Contacts::STATUS_SUBSCRIBE)->count();

    if ($total)
        return $total;

    return 0;
}
```
The implementation correctly and exactly added `$this->authorize('view_contact')`, per the contract's own §7 design — this part of the implementation is not in question and is not reopened. **The underlying `Contacts::whereIn('group_id', $contactGroupIds)` query, however, is byte-identical to the pre-remediation base** — no `customer_id`/tenant predicate of any kind constrains which `group_id` values may be queried.

### 3.3 Direct UI evidence that `contact_group_ids` legitimately carries numeric database IDs, not `uid`s

`resources/views/customer/Campaigns/campaignBuilder.blade.php` at `origin/main`, confirmed directly:
```blade
<select class="select2 form-select" required name="contact_groups[]" multiple="multiple" id="contact_groups">
    @foreach($contact_groups as $group)
        <option value="{{$group->id}}"> {{ $group->name }}
            ({{Tool::number_with_delimiter($group->subscribersCount($group->cache))}} {{__('locale.menu.Contacts')}})
        </option>
    @endforeach
</select>
```
and, in the same file's own page script:
```js
const contact_group_ids = $("#contact_groups").val();
...
$.ajax({
    url: "{{ route('customer.contacts.count_contact') }}",
    type: "POST",
    data: {
        _token: "{{ csrf_token() }}",
        contact_group_ids: contact_group_ids
    },
    ...
});
```
This is the application's own **normal, legitimate, unmodified** campaign-builder flow — `$group->id` (the numeric `ContactGroups` primary key, never the tenant-scoped `uid`) is what every real customer's browser already submits as `contact_group_ids` to `customer.contacts.count_contact` (`POST contacts/count`, confirmed in `routes/customer.php`) every time they open the message-preview step of building a campaign. This is not a contrived attack path — it is the exact value shape this endpoint has always received from its own UI.

### 3.4 The resulting vulnerability

Any authenticated customer holding the now-required `view_contact` permission (a routine, non-privileged, `default: true` permission — confirmed in the merged contract's own §4/`config/customer-permissions.php` citation) can submit an arbitrary **numeric** `ContactGroups.id` belonging to another tenant as an element of `contact_group_ids`, and the endpoint will return the exact count of subscribed `Contacts` rows in that foreign group — a genuine, real cross-tenant **aggregate-data disclosure**. This is squarely inside the exact CRM tenant-isolation domain the merged contract exists to close (its own §0: *"customer-portal tenant-isolation for Contacts/ContactGroups/Blacklists"*) — it was missed because the contract's own §3.2 audit pass classified this action as permission-only without separately re-auditing its own query body for a tenant boundary, the same class of oversight the contract's own Correction Round 1/2 process repeatedly found and fixed elsewhere in this same audit. **The original "permission check only" conclusion for `countContacts` is superseded by this exception** — every other conclusion in the merged contract and in the pushed implementation stands unchanged and is not reopened.

---

## 4. Locked future production correction — exact design

**Authorized, once this document is separately human-merged and a separate human resume instruction is given**, to land as a correction commit on the existing `agent/design-system-m2-slice5-crm-security-remediation` branch (not a new branch), touching **only** `app/Http/Controllers/Customer/ContactsController.php`'s `countContacts()` method body:

```php
public function countContacts(Request $request)
{
    $this->authorize('view_contact');

    $contactGroupIds = $request->input('contact_group_ids');

    if (is_null($contactGroupIds))
        return response()->json('No contact groups selected', 404);

    $ownedGroupIds = ContactGroups::where('customer_id', Auth::id())
        ->whereIn('id', $contactGroupIds)
        ->pluck('id');

    $total = Contacts::whereIn('group_id', $ownedGroupIds)->where('status', Contacts::STATUS_SUBSCRIBE)->count();

    if ($total)
        return $total;

    return 0;
}
```

**Preserved exactly, unchanged:**
- `$this->authorize('view_contact')` — the existing, already-correct permission gate (§7 of the merged contract), unchanged in position and string.
- The existing `is_null($contactGroupIds)` → the existing `response()->json('No contact groups selected', 404)` — this is a distinct, pre-existing, unrelated validation branch (missing/empty input entirely, not a foreign-vs-owned distinction) and is not touched.
- The existing `if ($total) return $total; return 0;` response shape — unchanged, so the endpoint's own existing JSON/plain-number response contract for the campaign builder's own `Number(data)` consumption is preserved exactly.
- `Contacts::whereIn('group_id', ...)->where('status', Contacts::STATUS_SUBSCRIBE)->count()` — the aggregate-counting logic itself is unchanged; only the set of `group_id`s it is allowed to run against is now pre-filtered to the actor's own groups.

**The only change:** the raw, client-supplied `$contactGroupIds` is reduced, before use, to `$ownedGroupIds` — exactly the IDs among those submitted that the authenticated customer's own `ContactGroups` rows actually contain (`ContactGroups.customer_id = Auth::id()`), reusing the exact ownership predicate already established and reused throughout the merged contract's own §8/§9/§11 designs, applied here to the numeric `id` column (matching this specific endpoint's own existing numeric-ID convention) rather than the `uid` column the raw-string route-binding-resolution helpers use elsewhere in this same controller.

### 4.1 Foreign/nonexistent indistinguishability rule — required behavior table

| Submitted `contact_group_ids` composition | Required response |
|---|---|
| Own-only IDs | The subscribed-contact count for the actor's own selected groups |
| Own + foreign | Identical to own-only — the foreign ID is silently excluded from `$ownedGroupIds`, never mixed into or subtracted from the returned count |
| Own + nonexistent | Identical to own-only — the nonexistent ID is silently excluded identically to a foreign one |
| Foreign-only | `0` |
| Nonexistent-only | `0` |
| `null`/absent (existing, unrelated case) | Unchanged — the existing `404`/`'No contact groups selected'` response |

**Foreign-only and nonexistent-only must be indistinguishable** — both simply produce an empty `$ownedGroupIds` set and therefore an identical `0` response, with no separate existence probe, no separate error, and no separate status code distinguishing "this ID exists but isn't yours" from "this ID doesn't exist at all." This mirrors the merged contract's own already-locked fail-closed mixed-batch philosophy (§9/§14's Family A/B tables) — silent exclusion from an owned-subset query, never a differential response — applied here to an aggregate count instead of a mutation, and is the same reasoning that made the `resolveOwnedContactGroup()`/`resolveOwnedBlacklist()` helpers deliberately use `->first()` + `abort(404)` rather than any pattern that could let a foreign-vs-nonexistent distinction leak through a response.

### 4.2 Explicitly not authorized by this section

No route change. No new `FormRequest` class. No model change. No repository change. No schema/migration change. No `resources/views/customer/Campaigns/campaignBuilder.blade.php` change — the campaign builder's own existing numeric-`id` submission shape is exactly what the corrected query now safely handles; it does not need to change what it sends. No change to any other method in `ContactsController.php`. No change to any of the other 8 already-allowlisted implementation paths.

---

## 5. Locked future test correction — exact requirements

**Authorized, in the same future correction commit**, to touch **only** these two already-allowlisted test paths — no new test file:

### 5.1 `tests/Feature/Security/ContactsSecurityTest.php` — new `countContacts` tenant-isolation coverage

In addition to the existing `test_count_contacts_denies_actor_missing_permission` (which remains unchanged and must continue passing), add real ownership coverage proving:

- Tenant A and tenant B each have their own, distinct `ContactGroups`, each with seeded subscribed `Contacts`.
- An authorized tenant-A actor submitting **only** tenant A's own group ID(s) receives exactly tenant A's own subscribed-contact count.
- Submitting **own + foreign** (tenant A's own group ID plus tenant B's) returns exactly tenant A's own count — never a combined total, proving the foreign group's contacts are never added in.
- Submitting **own + nonexistent** (tenant A's own group ID plus a numeric ID that matches no `ContactGroups` row at all) returns exactly tenant A's own count identically to the own-only case.
- Submitting **foreign-only** returns `0`.
- Submitting **nonexistent-only** returns `0`.
- The foreign-only and nonexistent-only responses (status code and body) are asserted **identical** to each other — the core indistinguishability proof, mirroring this repository's own established `HotLeadsSecurityTest.php`-style paired-assertion pattern already reused throughout this remediation's own three test files.

### 5.2 `tests/Feature/Security/ContactGroupsSecurityTest.php` — strengthen the tautological `UpdateContactGroup` model-bound test

**Current defect, confirmed by direct read of `277cfd4`:** `test_update_contact_group_form_request_model_bound_branch_does_not_error()`'s own final assertion,
```php
$this->assertTrue($request->authorize() === false || $request->authorize() === true);
```
is a tautology — `authorize()` returns a `bool`, so this expression is `true` unconditionally regardless of what `authorize()` actually returns or whether it throws, and proves nothing about the FormRequest's real behavior.

**Required correction**, exercising the FormRequest's actual behavior rather than inspecting its source text:

- Using this repository's own existing session/Gate test setup (the same `withSession(['permissions' => collect([...])])` pattern already used throughout this file), prove that an actor/session holding `update_contact_group` makes `$request->authorize()` return `true` (a genuine, meaningful assertion — not a tautology).
- Prove `rules()` still executes successfully (returns the expected `name` validation-rule array) when `$this->route('contact')` resolves to a bound `ContactGroups` model instance — the exact model-bound branch this test exists to cover, already set up correctly in the test's own `$route->setParameter('contact', $group)` call; only the final assertion needs correcting.
- Prove `messages()` still returns its existing `name.unique` message content, confirming that method is genuinely unchanged and still callable, not merely asserted unchanged by inspecting the production diff.

**No production `FormRequest` change is authorized or required** — `app/Http/Requests/Contacts/UpdateContactGroup.php`'s current, already-implemented diff already preserves `authorize()` and `messages()` byte-identical to the pre-remediation base (confirmed in §1 item 4); this section corrects only the **test's own assertion quality**, not anything about the production file.

---

## 6. Implementation path count — unchanged, exactly 9

This exception adds **zero** new implementation paths. The merged contract's own §16 allowlist remains exactly the same 9 paths it has always been. The future correction this document authorizes touches only 3 paths, and all 3 are **already** among those original 9:

1. `app/Http/Controllers/Customer/ContactsController.php` — already allowlist item 1.
2. `tests/Feature/Security/ContactsSecurityTest.php` — already allowlist item 8.
3. `tests/Feature/Security/ContactGroupsSecurityTest.php` — already allowlist item 7.

**Stop threshold remains the 10th path**, unchanged. The other 6 of the original 9 implementation paths — `app/Http/Controllers/Customer/BlacklistsController.php`, `app/Http/Controllers/Admin/BlacklistsController.php`, `app/Http/Requests/Contacts/UpdateContactGroup.php`, `app/Repositories/Eloquent/EloquentBlacklistsRepository.php`, `resources/views/customer/contactGroups/show.blade.php`, `tests/Feature/Security/BlacklistsSecurityTest.php` — **must remain byte-identical to implementation head `277cfd4baab23bc9f5b59e693398ea47c53ee361`** throughout the future correction; a diff touching any of them, or any 10th path, is a stop-and-report condition for that future work, not something this document itself authorizes.

---

## 7. Verification required after the future correction

This document requires, once the future correction described in §4/§5 is separately human-authorized, implemented, and ready for its own verification pass:

1. **Focused CRM security suite**, unchanged command:
   ```
   php artisan test tests/Feature/Security/ContactGroupsSecurityTest.php tests/Feature/Security/ContactsSecurityTest.php tests/Feature/Security/BlacklistsSecurityTest.php
   ```
   Required: **0 skipped, 0 failed**.
2. **Full regression**:
   ```
   php artisan test
   ```
   Required: **0 skipped, 0 failed, exit code 0**.
3. **Reuse the already-proven local environment prerequisites**, established and verified working during this same implementation's own environment-stabilization pass, when needed to reach the result above:
   - Local gitignored `.env` `APP_NAME="AI Business OS"` (not a tracked-file change).
   - `php artisan clear-compiled && php artisan package:discover --ansi` (resolves the `BladeUI\Icons\IconsManifest` dependency-resolution gap).
   - `npx mix --production` (regenerates the local `public/mix-manifest.json` with its `/js/core/theme-tokens.js` entry).
4. **All generated `public/` and `bootstrap/cache/` artifacts must again be reverted** (`git checkout -- public/ bootstrap/cache/`, plus removal of any untracked build leftovers) **before** that future commit — exactly the same discipline already established and proven in this implementation's own prior environment-stabilization round.
5. **The final `git diff`/`git status` for that future commit must show exactly the 3 paths named in §6** (a subset of, never additional to, the original 9) — no `public/`, `bootstrap/cache/`, `package.json`/`package-lock.json`, `config/`, `database/`, or `docs/automation/AI-AUTONOMY-STATE.json` change of any kind.

---

## 8. Governance closing — restated explicitly

- **This document is not Correction Round 3.** The original CRM security remediation contract's `maximum_correction_rounds: 2` is unchanged, unreopened, and unextended.
- This is a **separately, exceptionally, human-authorized** post-implementation security-gap correction — a governance track distinct in kind from a correction round, scoped to exactly one already-identified gap.
- **Merging this document does NOT automatically modify the implementation.** `agent/design-system-m2-slice5-crm-security-remediation` remains exactly at `277cfd4baab23bc9f5b59e693398ea47c53ee361` until a separate, explicit, future human resume instruction authorizes the correction described in §4/§5.
- **CRM security implementation remains unmerged.** This document's own merge changes nothing about that.
- **Slice 5 visual implementation remains unauthorized** — this document neither advances nor further blocks that state; the merged Slice-5 contract's own §0/§7 prerequisite (this remediation's own implementation must be separately human-merged) is entirely unaffected by this document's existence.
- **Human-only merge**, for this document and for the future correction it authorizes.
- `advance_automatically: false`. `start_automatically_after_contract_merge: false`.
- No deployment. No force push. No automatic merge, of this document or of any future correction, under any condition.

---

## 9. Self-audit

1. The vulnerability claim is mechanically proven, not asserted — §3 cites the merged contract's own §3.2/§7 text verbatim, the implemented `countContacts()` body verbatim from `277cfd4`, and the campaign builder's own numeric-ID submission verbatim from `origin/main`, each independently confirmed by direct read this pass. ✓
2. The future production correction (§4) preserves every existing behavior not specifically named as changing — the permission gate, the null-input 404 branch, the response shape — and changes exactly one thing: pre-filtering `$contactGroupIds` to the actor's own groups before counting. ✓
3. The foreign/nonexistent indistinguishability rule (§4.1) is stated as an exact table, mirroring the merged contract's own established fail-closed philosophy, not invented fresh. ✓
4. The future test correction (§5) is scoped to exactly the two already-allowlisted test files, with the `ContactsSecurityTest` requirements exhaustively enumerated and the `ContactGroupsSecurityTest` tautology explicitly quoted and its replacement requirement stated precisely, not left to interpretation. ✓
5. §6 mechanically confirms the 9-path allowlist is unchanged and identifies exactly which 3 of those 9 the future correction may touch, with the remaining 6 required to stay byte-identical to `277cfd4`. ✓
6. No production, test, or automation-state file is touched by this document itself — confirmed by §2 and by the verification in §10 below. ✓
7. This document does not claim to be, and is not, Correction Round 3 — stated explicitly in this document's own opening line and restated in §0/§8. ✓

---

## 10. Verification and publication

Performed, in order, before commit:

1. `git diff --check` — clean, no whitespace-error or conflict-marker findings.
2. `git status --short` — exactly one entry: this document's own path.
3. `git diff --name-only` (working tree, matching `git status`) — exactly one path.
4. `docs/automation/AI-AUTONOMY-STATE.json` — confirmed untouched.
5. Stage individually (`git add docs/automation/DESIGN-SYSTEM-M2-SLICE-5-CRM-SECURITY-COUNT-CONTACTS-EXCEPTION.md`), never `git add -A`/`.`.
6. Commit message: `docs: authorize CRM count isolation correction`.
7. Push to `origin chore/design-system-m2-slice5-crm-security-count-contacts-exception` — a normal push, never force-pushed.
8. Do not open a PR if `gh` is unavailable — report the exact GitHub comparison URL instead.
9. **Do not merge. Do not resume work on `agent/design-system-m2-slice5-crm-security-remediation`. Do not begin Slice 5 visual implementation. Do not begin any other RFC or initiative.** All require separate, explicit, future human authorization.

---

*End of Design System M2 Slice 5 CRM Security Remediation — `countContacts` Tenant-Isolation Exception. This document authorizes a bounded future correction; it does not perform it. A separate, explicit human resume instruction is required before any change to `agent/design-system-m2-slice5-crm-security-remediation`. CRM security implementation remains unmerged. Slice 5 visual implementation remains unauthorized.*
