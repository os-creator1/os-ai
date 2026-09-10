# Customer Experience Redesign — Slice 1: Terminology + Customer Shell Implementation Contract

**Status: CONTRACT ONLY.** No product code, migration, route, or test change is made or authorized by this document. This is Correction 1 of the contract: every SHA, count and claim below was re-derived mechanically against the current remote heads at review time, not copied from the prior pass.

---

## 0. Verified base and inspected heads

```
contract_type: implementation_contract
docs_only: true
implementation_authorized_by_this_document: false
correction: 1
```

- **Starting HEAD of this correction**: `1fc2a5340dbd3a046f49ea6b448184f87c0307e1` (the prior contract pass, commit `docs(ux): define customer terminology redesign slice`).
- **Authoritative `origin/main` at this review**: `b87b669d55de97957d3583407ec2b69cd75d2eaa` — unchanged since the prior pass. Confirmed via `git rev-parse origin/main` after `git fetch origin`. **No merge into this branch is required or performed.**
- **Chat A remote head observed at issuance of this correction**: `9dd47b3744d18f98f0c065ac2de91fae10790f9e` ("fix(messaging): close legacy webhook P0 security gaps").
- **Chat A remote head actually observed by this correction, newer than the one named in the correction request**: `122f3301f235dad35c1e457afdfa8d5ca097bb95` ("docs(messaging): reconcile the allowlist and record Security Correction 36"). Per the correction instruction ("If Chat A has advanced beyond 9dd47b3 by the time you run this: inspect the newest remote head and use that instead"), **this contract is re-derived against `122f330`**, not `9dd47b3`. Confirmed `git merge-base --is-ancestor 9dd47b3 122f330` (exit 0) and `git merge-base --is-ancestor b87b669 122f330` (exit 0, via merge commit `50f2725` — Chat A has already merged current `origin/main` into its own branch). Confirmed `git merge-base --is-ancestor 122f330 origin/main` fails (exit 1) — **Chat A is still not merged into `origin/main`**.
- **The prior pass's own inspected head, `403c5f83c33d4a6b19a9d45587a3a832002ed681`, is confirmed stale** by 11 commits (`git log 403c5f8..122f330 --oneline`) and is superseded throughout this correction.
- **Inspection method**: a detached, read-only worktree was created at `122f330` in the scratchpad directory (never inside this contract's own worktree, never a checkout of this branch) to `git diff`/`grep`/read files directly, in addition to `git show 122f330:<path>` spot checks. No file in this branch's own worktree was touched by that inspection; the detached worktree is scratch and is discarded, not part of any deliverable.
- **PR #236 (theme assets)**: unchanged from the prior pass — merged into `origin/main` at `b87b669`.

---

## 1. Re-audit of the actual current Chat A head — what changed since `403c5f8`

`git diff 403c5f8..122f330 --stat` narrows to: `config/customer-permissions.php` (+19), `routes/customer.php` (+29/-1), `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` (view paths + one authorization clause), plus the webhook P0 fix and its own docs (unrelated to this slice). Confirmed **byte-identical, no diff**, between `b87b669` and `122f330` for: `app/Library/Navigation/CustomerMenuBuilder.php`, `app/Library/Navigation/CustomerContext.php`, `app/Providers/MenuServiceProvider.php`, `resources/views/panels/horizontalMenu.blade.php`, `app/Helpers/Helper.php`. §3–§4 below therefore still hold verbatim; only §5 needed re-verification, and it changed materially:

1. **`config/customer-permissions.php`** now defines, at lines 68–72:
   ```php
   'manage_advanced_provider' => [
       'display_name' => 'manage_advanced_provider',
       'category'     => 'Messaging',
       'default'      => false,
   ],
   ```
   with a doc comment confirming the authorization model the parent contract requires: *"the relocated advanced-settings surface additionally requires authoritative Workspace OWNERSHIP (`WorkspaceCandidate::$isOwner`), not `canManage()`, not plan tier, and not admin membership."*

2. **`routes/customer.php`** — the old path `{workspaceUid}/businesses/{businessUid}/channels` is **removed, not duplicated**. Chat A's own comment at the diff site states this exactly: *"a genuine rename... a request to `.../channels` now falls through to Laravel's ordinary 404."* The route is now `{workspaceUid}/businesses/{businessUid}/settings/advanced`, with route **names** deliberately unchanged (`businesses.channels.*`), because `CustomerMenuBuilder.php:132,222` and `ViewAsProhibitedActions.php:71` bind to them by name.

3. **`app/Http/Controllers/Customer/Business/MessagingChannelsController.php`** — every `view(...)` call was repointed from `customer.business.MessagingChannels.*` to `customer.settings.advanced.*`; the authorization method now requires **both** `$workspaceCandidate->isOwner` **and** `Gate::allows('manage_advanced_provider')` (previously `canManage()` alone) — Agency admin is now denied, matching the parent contract's §6 matrix exactly.

4. **`resources/views/customer/business/MessagingChannels/**` no longer exists at `122f330`** (`ls` confirms `No such file or directory`) — fully relocated, not duplicated, to `resources/views/customer/settings/advanced/{entry,index,show,connect}.blade.php`. Read via `git show 122f330:<path>` for all four files: **byte-identical in content** to what the prior pass read at `403c5f8` (same forbidden strings, same line numbers). Only the SHA and the physical path changed; every string-level finding in the prior pass's §5 was already correct and is retained below, now as an **unconditional** (not merge-conditional) allowlist entry, since the merged path is now directly observed rather than inferred.

---

## 2. Locale audit — two Chat-A keys are now real; full recount

Both prior refutals are now **false** against `122f330` and are withdrawn:

- **`config/customer-permissions.php:70`** sets `'category' => 'Messaging'` for the new `manage_advanced_provider` permission. This category renders via `__('locale.menu.'.$category['title'])` at **`resources/views/customer/SubAccounts/show.blade.php:107`** and **`create.blade.php:135`** — genuinely customer-reachable (the legacy Sub-Accounts feature is still live, per the retention audit). **`locale.menu.Messaging` does not exist in `resources/lang/en/locale.php`** (confirmed by grep — zero hits for `'Messaging'` as a bare key). **CONFIRMED, now real. Add.**
- **`manage_advanced_provider`'s own `display_name`** renders via `__('locale.permission.'.$permission['display_name'])` at the same two Sub-Accounts screens (lines 124/151) plus three admin screens. **`locale.permission.manage_advanced_provider` does not exist** (confirmed by grep). **CONFIRMED, now real. Add.**

Neither key was independently added by Chat A itself — both greps came back empty on `locale.php` at `122f330`. Recomputing the **complete** total (not assumed to be the old 12 + 2 = 14):

**`locale.menu.*` — 11 keys total** (the original 9, confirmed still accurate since `Helper.php`/`config/permissions.php` are unchanged, plus 1 new):

| Key | Emission site | Status |
|---|---|---|
| `Platform Settings` | `app/Helpers/Helper.php:770` | unchanged from prior pass |
| `Theme Presets` | `app/Helpers/Helper.php:778` | unchanged |
| `Usage Billing` | `app/Helpers/Helper.php:877` | unchanged |
| `Safety Limits` | `app/Helpers/Helper.php:885` | unchanged |
| `Provider Events` | `app/Helpers/Helper.php:893` | unchanged |
| `Additional Slot Agreements` | `app/Helpers/Helper.php:901` | unchanged |
| `Workspace` | `config/permissions.php:528` | unchanged |
| `Workspace Plans` | `config/permissions.php:534,539` | unchanged |
| `Opportunities` | `config/permissions.php:545,550` | unchanged |
| **`Messaging`** | `config/customer-permissions.php:70` (customer-reachable via SubAccounts) | **NEW in this correction — Chat A added the emission site, this contract adds the key** |
| **`Sender identities`** | new key introduced by this contract itself (§5) — not an existing leak, a deliberate replacement label | **NEW — see §5/§7** |

**`locale.permission.*` — 4 keys total** (the original 3, plus 1 new):

| Key | Emission site |
|---|---|
| `website` | `config/customer-permissions.php:32` |
| `read_google_business_profile` | `config/customer-permissions.php:45` |
| `manage_google_business_profile` | `config/customer-permissions.php:50` |
| **`manage_advanced_provider`** | `config/customer-permissions.php:69` (customer-reachable via SubAccounts) — **NEW** |

**Total: 15 keys** (11 menu + 4 permission) — not 14, not the prior pass's 12. The extra key beyond the expected 12 + 2 = 14 is `Sender identities` (§5/§7's own new label, not a pre-existing leak this audit found — it is added because this correction's §5 renames a rendered string to it, and the render site checks `Lang::has()`).

`manage_advanced_provider` is still governed the same way as every other customer-permission category/display-name pair (via the generic `_permissions.blade.php`/`SubAccounts` iteration) — no bespoke admin Blade edit is needed, consistent with the prior pass's approach.

---

## 3. The child contract may not weaken the parent tests — corrected posture

The prior pass's §7/§9 introduced language treating known, reachable forbidden-term surfaces as accepted "residual gaps" owned by a later slice. **That is withdrawn.** T-TERM-1 and T-TERM-2 are absolute: a later slice may own a screen's redesign or deletion, but Slice 1 still owns copy-only terminology cleanup wherever a forbidden term is genuinely rendered to a customer today. Every surface below was re-classified against exactly two outcomes: **(A) copy-only fix, added to the allowlist**, or **(B) provably unreachable for all customer roles, proof recorded, no code change**. Where neither holds — the only fix available would require a prohibited-path edit — this contract **stops and reports**, per §11, rather than silently declaring the gap acceptable.

---

## 4. Agency Prospecting Channels — confirmed live T-TERM-1 defect, corrected

`resources/views/customer/workspaces/prospecting/channels/index.blade.php:9`:
```
Connect a dedicated Twilio or Telnyx number owned by this Workspace — never a client Business's own connection.
```
Confirmed **not inert**: `routes/customer.php:1054` binds `GET /` on this prefix to `Workspace\AgencyProspectingChannelController@channels`, a real, entitlement-gated (not merely menu-gated) Agency route. This view is genuinely rendered to an entitled Agency owner/admin.

Full re-grep of every view under `resources/views/customer/workspaces/prospecting/**` (11 files: `_nav`, `prospects`, `campaign-show`, `channels/{connect,index,show}`, `settings`, `overview`, `prospect-show`, `entry`, `campaigns`) for `Workspace|Sub Account|Sending Server|Sender ID|Originator` found exactly two files with hits: `channels/index.blade.php:9` (this one) and `entry.blade.php:14,15,19` (already in the prior pass's allowlist, §7 item #9 below). `channels/connect.blade.php` and `channels/show.blade.php` are clean.

**Correction: added to the allowlist** (§7, new item). **Copy-only**: replace "this Workspace" with the Agency-account noun from §2 (e.g. "this Agency account"). No controller, credential, route, or tenancy change — `AgencyProspectingChannelController.php` itself is not touched.

---

## 5. `Sender IDs` customer nav item and its destination views — corrected

**5a. The nav item.** `CustomerMenuBuilder.php:224` — `$this->item($user, 'sender-ids', 'Sender IDs', 'book', ['view_sender_id'], 'customer.senderid.index', [], $current, ['customer.senderid.'])` — renders inside the `Advanced` group gated on `isAgency() && canManageWorkspace()` (i.e. visible to Agency owner **and** admin, a superset of who can actually reach the destination page's write actions). This is a genuinely rendered customer-facing label. **Correction: the label text changes to `'Sender identities'`** (§2's exact approved wording) — one line, no route, permission, or destination change. `MenuItem`'s existing `Lang::has()`-then-fallback mechanism (`components/customer-nav-item.blade.php:16-17`) means this requires no locale key to function correctly, but this contract adds `locale.menu.Sender identities` = `'Sender identities'` anyway, for parity with every other nav label and to keep T-I18N-3 exhaustive.

**5b. The destination.** `customer.senderid.index` is `Customer\SenderIDController`, gated only by `$this->authorize('view_sender_id')` — and `view_sender_id` defaults `true` for **every** customer tier (`config/customer-permissions.php:166-170`, no tier restriction). This route is reachable by direct URL by **any** customer, Agency or not, independent of the Agency-only nav gate — the same "menu visibility is not the authorization boundary" pattern the parent audit already documented for D-9. Re-read the destination views:

| File | Forbidden string | Exact location |
|---|---|---|
| `customer/SenderID/index.blade.php` | `Sender ID` | line 3 (`@section('title', __('locale.menu.Sender ID'))`), line 57 (table header, same key) |
| `customer/SenderID/request_new.blade.php` | `Sender ID` | line 36 (form label, same key) |
| `customer/SenderID/checkout.blade.php` | none found | — |

The key both call `locale.menu.Sender ID` resolves, today, to the literal string `'Sender ID'` (`locale.php:801`). **This key is shared with four admin views** (`admin/plans/_sender_id.blade.php:14`, `admin/SenderID/{create,index,show}.blade.php`) and `Admin\SenderIDController`'s own breadcrumbs — mutating its *value* would silently change admin-rendered copy, which is out of scope. **Correction: do not mutate the shared key.** Instead, repoint exactly these 2 customer files (3 call sites) to the new `locale.menu.Sender identities` key from §5a — a genuinely narrow, copy-only, admin-untouched fix. Added to the allowlist (§7).

This closes the T-TERM-2 violation on both the nav label and its landing page, without any route, permission, controller, or destination-behavior change — satisfying the correction's instruction that a leak not be left open "merely because the screen will later be rebuilt," while not expanding into the SenderID module's broader architecture (create/edit/checkout flows, admin SenderID, payment callbacks — none of it touched).

---

## 6. Account SID / Auth Token / API Key — mechanically located, and why this contract cannot close it

Re-read the authoritative parent (`CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`, fully read in a prior task) and the redesign doc's own T-TERM-2 row: **no clause overriding T-TERM-2 for the Agency Advanced screen was found.** The prior pass's "permitted exceptions" language is **withdrawn** — it cited no such clause because none exists.

Mechanically located: these are **not** blade-file literals. `resources/views/customer/settings/advanced/{show,connect}.blade.php` render `{{ $meta['label'] }}` dynamically. The actual literal strings live in **PHP controller arrays**:
```php
// app/Http/Controllers/Customer/Business/MessagingChannelsController.php:77-86
'account_sid' => ['label' => 'Account SID', 'required' => true],
'auth_token'  => ['label' => 'Auth Token', 'required' => true],
...
'api_key'     => ['label' => 'API Key', 'required' => true],
'c1'          => ['label' => 'Message Profile ID', 'required' => true],
'c2'          => ['label' => 'Message Connection ID', 'required' => false],
```
and identically in `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php:44-53`.

Both files are under `app/Http/Controllers/**` — a path this contract's own Prohibited Scope (§8, unchanged, never rescinded by this correction) forbids editing, and both are Chat A's own Slice 3 provider-implementation surface, which the issuing instruction separately, independently forbids modifying ("Do NOT modify provider behavior, credentials, permissions or routes"; "Do not touch Chat A's security correction"). Changing only the `label` *values* (never the array keys `account_sid`/`auth_token`/`api_key`, never validation, never the credential mechanics) would be display-copy-only in substance, but the only place to make that edit is inside two prohibited controller files.

**This contract does not perform that edit.** Per §11, this is reported as a stop condition rather than resolved by either (a) silently leaving it open, mislabeled as an "exception," or (b) unilaterally breaching this contract's own controller prohibition. **Recommendation, not performed here:** Chat A's own Slice 3 contract — which introduced these exact label strings — is better positioned to change three literal string values in files it already owns and is already authorized to edit; Slice 1 has no such authorization over `app/Http/Controllers/**`.

---

## 7. Full rendered-copy reachability sweep and corrected allowlist

Full grep of `resources/views/customer/**` at `122f330` for every forbidden term (`Workspace(s)`, `Sub Account(s)`, `Sending Server(s)`, `Sender ID(s)`, `Originator`, `Account SID`, `Auth Token`, `API Key`, `Messaging Channels`) found 20 files. Classified below — **reachable copy-only fixes are added to the allowlist; provably unreachable files are recorded as such, not fixed; the two remaining reachable-but-not-copy-only-fixable surfaces are stopped and reported, per §3/§6.**

### 7a. Production allowlist — mutation confirmed necessary, all now unconditional (no merge-pending physical-path ambiguity remains)

| # | Path | Exact permitted mutation |
|---|---|---|
| 1 | `resources/lang/en/locale.php` | Add the 15 keys in §2. No deletions. |
| 2 | `resources/views/customer/Automations/entry.blade.php` | Line 15: `Workspace` → account noun (§2/§3). |
| 3 | `resources/views/customer/business/analytics/entry.blade.php` | Line 21: same. |
| 4 | `resources/views/customer/business/analytics/overview.blade.php` | Line 25 ("Back to Workspace"): same. |
| 5 | `resources/views/customer/business/website/entry.blade.php` | Line 15: same. |
| 6 | `resources/views/customer/Outreach/entry.blade.php` | Line 15: same. |
| 7 | `resources/views/customer/business/googleBusinessProfile/entry.blade.php` | Line 21: same. |
| 8 | `resources/views/customer/workspace/additional-business-slots/show.blade.php` | Lines 11, 38: same. |
| 9 | `resources/views/customer/workspaces/prospecting/entry.blade.php` | Lines 14, 15 (×2), 19: same. |
| 10 | `resources/views/customer/workspaces/prospecting/channels/index.blade.php` | Line 9: same (§4, **new in this correction**). |
| 11 | `resources/views/customer/workspaces/index.blade.php` | Lines 11–14: replace hardcoded ternary with `CustomerContext::accountNoun()`/`accountsNoun()` — **the Core/Growth-vs-Agency case only; the unselected-multi-workspace case is a stop condition, §9**. |
| 12 | `resources/views/customer/workspaces/show.blade.php` | Lines 11–16: same, same caveat. |
| 13 | `resources/views/panels/horizontalMenu.blade.php` | Customer branch consumes the `CustomerShellComposer`-composed canonical menu (§ unchanged from prior pass). |
| 14 | `app/Helpers/Helper.php` | No deletion; annotate unreachability of the customer branch after #13. |
| 15 | `app/Library/Navigation/CustomerContext.php` | Delete `showsWorkspaceVocabulary()` (zero-caller reconfirmed at `122f330` — no diff since `b87b669`); add `accountNoun()`/`accountsNoun()` (Core/Growth-vs-Agency case only, per stop condition §9); `usesBusinessVocabulary()` unchanged. |
| 16 | `app/Providers/MenuServiceProvider.php` | Add `'panels.horizontalMenu'` to `View::composer([...])`. |
| 17 | `resources/views/customer/settings/advanced/entry.blade.php` | **Unconditional now** (path confirmed to exist at Chat A's current head, old path confirmed deleted): heading/title `Messaging Channels` → `Messaging provider`; `Workspace` → account noun. |
| 18 | `resources/views/customer/settings/advanced/index.blade.php` | Heading/title → `Messaging provider`; `Sender IDs` section heading → `Sender identities`. |
| 19 | `resources/views/customer/settings/advanced/show.blade.php` | `Sender IDs` section heading → `Sender identities`. |
| 20 | `app/Library/Navigation/CustomerMenuBuilder.php` | Line 224: label `'Sender IDs'` → `'Sender identities'` (§5a, **reverses the prior pass's decision to leave this line untouched — that decision is withdrawn**). |
| 21 | `resources/views/customer/SenderID/index.blade.php` | Lines 3, 57: repoint from `locale.menu.Sender ID` to `locale.menu.Sender identities` (§5b, **new**). |
| 22 | `resources/views/customer/SenderID/request_new.blade.php` | Line 36: same repoint (§5b, **new**). |

**Total: 22 production paths with a confirmed, exact required mutation** — up from the prior pass's 18. This number is not preserved for consistency with the prior report; it is the direct result of this correction's re-sweep (4 new: prospecting channels, `CustomerMenuBuilder.php`, 2 SenderID views) plus the 3 items that moved from "conditional on merge" to "unconditional" without changing the total count of distinct logical surfaces.

### 7b. Provably unreachable — recorded, not fixed, not deleted

- `resources/views/customer/SendingServer/{create,index,list}.blade.php` — contain `Sub Account`, `Sub Account Password`, `Promo Sender ID`, and (via `locale.menu.Sending Servers`) `Sending Servers`. **Mechanical proof of unreachability**: `app/Http/Controllers/Customer/SendingServerController.php` **does not exist** in this repository (confirmed via `find`); the only `SendingServerController` present is `App\Http\Controllers\Admin\SendingServerController`, which renders exclusively `admin.SendingServer.*` views. No route in `routes/customer.php` binds to a customer `SendingServerController`, and no other controller returns these three customer views (confirmed via repo-wide grep for the view names). These files are dead — orphaned by an earlier cleanup (consistent with Slice 0's closure of all 8 provider-credential routes) — and remain physically present but unreachable for every customer role. **No allowlist entry; no deletion authorized by this contract; the reachability proof above is the record.**

### 7c. Reachable but not copy-only fixable within this contract's authority — stopped, per §3/§6, not silently accepted

- `resources/views/customer/Outreach/_originator.blade.php` (included by `_smsQuickSend`, `_smsCampaign`, `_mmsQuickSend`, `_mmsCampaign` — the primary, high-traffic compose flow, gated only by ordinary send permissions) renders `locale.labels.sending_server`, `locale.labels.originator`, `locale.labels.sender_id` — resolving today to `'Sending Server'`, `'Originator'`, `'Sender ID'`. These **same three shared keys** are also referenced directly (not merely via this partial) by roughly 19 further customer views (every `Campaigns/*` quick-send/builder/import screen across all six channels, `keywords/{create,show}.blade.php`, `contactGroups/_settings.blade.php`, `Templates/create.blade.php`, `ChatBox/new.blade.php`, `Developers/settings.blade.php`) **and** by 7 admin views (`admin/Reports/all_messages.blade.php`, `admin/Announcements/create.blade.php`, `admin/plans/edit.blade.php`, `admin/BlockSenderID/{create,index}.blade.php`, `admin/keywords/create.blade.php`, `admin/dashboard.blade.php`, `admin/Templates/create.blade.php`). Closing this leak without an admin-copy side effect requires either mutating a value 7 admin views also depend on (out of scope), or individually repointing ~20 customer files to new keys — a scope far beyond a narrow copy-only correction, and one that lands squarely inside the Campaigns/Outreach module the `PRODUCT-SURFACE-RETENTION-AUDIT.md` already locks as **"REBUILD FROM SCRATCH... DO NOT DESIGN LEGACY UI"** (§6.1, §12.1, a binding, already-resolved human decision) and inside this task's own standing Prohibited Scope ("legacy campaign/provider surfaces assigned to later retention slices"). **Stopped and reported, §11 — not resolved as an accepted residual gap, but as a conflict between two authoritative directives (T-TERM-2 absolute vs. the locked no-design-work decision) that only a human can settle**, with the exact fix scope (~20 files, or a shared-value change affecting 7 admin views) stated so the decision is not made blind.
- `app/Http/Controllers/Customer/Business/MessagingChannelsController.php:77-86` and `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php:44-53` — the Account SID/Auth Token/API Key labels in §6. **Stopped and reported, §11**, with the recommendation that Chat A's own contract perform this edit.

---

## 8. Prohibited paths — unchanged

No mutation in this contract touches: `database/migrations/**`, `app/Http/Controllers/**`, `routes/**`, `config/**`, `resources/views/admin/**`, `resources/views/layouts/**`, `resources/scss/**`, `public/**`, `package*.json`, `composer*.json`, B4/B5/Website/GBP product internals, Usage billing internals, messaging runtime, `DLRController`, managed messaging provider implementation, `CustomerMenuBuilder`/`CustomerContext` behavior beyond exact terminology labels and the vocabulary helpers already scoped, or Chat A's security correction. This restated prohibition is exactly why §6 and part of §7c are stopped rather than fixed — this contract will not breach its own boundary even under T-TERM-2's absolute mandate; it reports the conflict instead.

---

## 9. `CustomerContext` edge case — resolved to a concrete stop condition, not left open

The prior pass's "open item, not resolved" is not permitted to stand as-is. Mechanical re-audit: `frameWorkspace()` (`CustomerContext.php:97-102`) returns `null` in **two** distinct cases, per its own doc comment — zero workspaces, **or** more than one workspace with none yet selected ("With several unselected Workspaces the shell stays neutral (Business wording) until one is chosen"). `workspaces/index.blade.php` is precisely the chooser rendered for the second case — **this state is confirmed reachable**, not hypothetical.

The locked hierarchy in §2 defines exactly two frames (Core/Growth → Account; Agency → Agency account) and does not name a third "multiple workspaces, none selected yet" state, and nothing in the code proves this state is Agency-exclusive (a user could plausibly hold membership in more than one independent Core/Growth workspace, e.g. as staff). Asserting "Agency account" for this case, as the prior pass did, would misdescribe the chooser for a non-Agency actor in that state.

**No authorized parent vocabulary exists for this reachable state. Per the correction's own instruction: this is one human wording decision, not invented here.** `accountNoun()`/`accountsNoun()` (§7 items #11-12/#15) are implemented for the Core/Growth-vs-Agency binary only; the unselected-multi-workspace fallback is a **stop condition** (§11) — implementation must obtain the wording decision before writing that branch, rather than defaulting silently to either "Account" or "Agency account."

---

## 10. Horizontal menu / Helper — decision preserved, proof requirement strengthened

The prior pass's decision is correct and is preserved unchanged: compose `panels.horizontalMenu` through `CustomerShellComposer`; the customer branch consumes the canonical `CustomerMenuBuilder`; the admin branch stays on legacy `menuData()`; `horizontalSubmenu.blade.php` stays untouched (no fresh evidence found requiring it, re-confirmed at `122f330` — no diff since `b87b669`); `Helper::menuData()` is not deleted.

**Strengthened per this correction**: a code comment asserting unreachability is not sufficient proof. This contract requires a **mechanical render regression** as part of the test map (§ below): a feature test that renders `panels.horizontalMenu` for an authenticated customer with `config(['app.theme_layout_type' => 'horizontal'])` forced on, and asserts the response contains a `CustomerMenuBuilder`-sourced marker (e.g. a route/label pair only the canonical builder emits) and does **not** contain any string sourced from `Helper::menuData()['customer']` (e.g. the literal legacy array's `'Channels'`/`'Workspaces'` labels). This is a test **specification** for the implementer to write; this contract does not write or run it.

---

## 11. Stop conditions — absolute, no silent exceptions

1. **Account SID / Auth Token / API Key / Message Profile ID / Message Connection ID labels** (§6) — cannot be closed without editing `app/Http/Controllers/**` (prohibited) in files belonging to Chat A's Slice 3 (also prohibited to touch independently). **Recommendation for human/ChatGPT decision**: either (a) grant Chat A's own contract a narrow instruction to change these five `label` *values* only (never the array keys, never validation, never credential mechanics), or (b) explicitly accept this as a documented, human-approved residual gap distinct from a silently-invented exception.
2. **The Campaigns/Outreach compose-flow `Sending Server`/`Sender ID`/`Originator` leak** (§7c) — closing it collides with the retention audit's own locked "DO NOT DESIGN LEGACY UI" decision for this exact module (§12.1) and this task's standing prohibition on legacy campaign/provider surfaces. **Recommendation for human/ChatGPT decision**: either (a) authorize a follow-on Slice 1B scoped narrowly to repointing ~20 customer files' three shared label keys (no admin value change, no redesign), or (b) accept this as the one deliberately deferred gap, owned explicitly by the future Outreach/Compose rebuild, recorded here rather than silently dropped.
3. **`CustomerContext`'s unselected-multi-workspace noun** (§9) — no authorized parent vocabulary exists. Needs one human wording decision before `accountNoun()`/`accountsNoun()`'s fallback branch can be written.
4. A forbidden term cannot be replaced without changing a route name (the parent's own stated Slice 1 stop condition — not triggered by any item in §7a).
5. `showsWorkspaceVocabulary()` gains a caller, or `usesBusinessVocabulary()`'s Core/Growth-only semantic is contradicted, before implementation — re-verify both at the implementation SHA before touching `CustomerContext.php`.
6. Chat A's merged content at the actual merge SHA diverges materially from what was read at `122f330` in a way that changes which strings need correction — re-derive from the merged tree before editing (§ stale-main handling, unchanged process from the prior pass, now pointed at `122f330` as the new reference instead of `403c5f8`).

None of the above is resolved by silently declaring a residual gap acceptable. Each is a named, reported blocker.

---

## 12. Test map — no exceptions

| Test | Exact assertion (parent §17) | Coverage after this correction |
|---|---|---|
| **T-TERM-1** | Zero rendered customer `Workspace`/`Workspaces`, any tier including Agency | §7a items #2–13, #17 close every currently-reachable instance found by the full sweep, including Agency Prospecting Channels (new, §4) and the unselected-multi-workspace case once §11 item 3 is resolved. Horizontal layout: §10. Core, Growth, Agency owner, Agency admin, Agency selected staff, Business context, Account context, Agency Prospecting — all exercised. |
| **T-TERM-2** | Zero rendered customer `Sending Server`, `Sender ID`, `Originator`, `Sub Account`, `Account SID`, `Auth Token`, `API Key` | §7a items #18–22 close the `Messaging Channels`→`Messaging provider` and `Sender ID(s)`→`Sender identities` leaks on both the nav item and its destination screens, with no reachable/legacy exception clause. §7b (`SendingServer` views) is closed by unreachability proof, not by leaving a rendered violation. §7c (`_originator.blade.php` family, provider credential labels) is **not** closed by this contract — it is an open, named stop condition (§11), never listed as an accepted residual gap. |
| **T-I18N-3** | No rendered response, customer or admin, contains a string matching `locale.` | The 15 keys in §2 close every currently-reachable raw-key leak, including both of Chat A's new emission sites (`Messaging`, `manage_advanced_provider`) discovered by this correction. |
| **T-NAV-4** | Every emitted customer nav URL resolves to a registered route | Unaffected — no route changed by §7a; re-run as a regression guard on the horizontal-menu change (§10) and the `Sender identities` relabeling (route names unchanged in both cases). |

**Regression coverage, unchanged requirement, restated**:
- Role/plan matrix across Core owner, Growth owner, Agency owner, Agency admin, Agency selected staff, restricted Business user, exercised against every §7a view.
- Route/URL unchanged: `/workspaces/{workspaceUid}`, all `customer.workspaces.*` and `customer.senderid.*` route names byte-identical before/after.
- Tenancy unchanged: no `business_id`/`user_id`/authorization logic touched.
- Vertical customer shell regression unaffected; horizontal shell gains the mechanical render regression in §10.
- Agency Advanced regression: Twilio/Telnyx still configurable after §7a #17–19's copy-only changes; the underlying form still posts `account_sid`/`auth_token`/`api_key` unchanged (§6 explicitly not touched by this contract, so this assertion should currently pass trivially — it becomes meaningful only if stop condition §11.1 is later resolved and implemented).
- No raw translation key rendering: T-I18N-3, extended to all 15 keys.

---

## 13. Dependency status

- **PR #236 theme assets**: satisfied, `origin/main = b87b669`.
- **Chat A**: **not yet merged.** Current observed head `122f330` (superseding the `9dd47b3`/`403c5f8` heads named in earlier passes). Implementation still waits for Chat A's actual merge commit into `origin/main`.
- This **contract document** may merge before Chat A, since it is docs-only — but it must, and now does, describe the current known predecessor (`122f330`) accurately rather than a stale one.
- Stale-main handling at implementation time (unchanged process, updated reference point): re-run the ancestor and diff checks in §0/§1 against whatever SHA Chat A actually merges as, not against `122f330` verbatim — `122f330` is itself a moving target until merged.

---

## 14. Consistency sweep — stale assertions removed

Confirmed removed or corrected in this document: `403c5f8` described as latest (now `122f330`, with the full commit-range diff in §1); "12 keys" (now 15, §2); `"Messaging" — REFUTED` (now confirmed real, §2); `"manage_advanced_provider" — NOT FOUND` (now confirmed real, §2); `"permitted exceptions"` for Account SID/Auth Token/API Key (withdrawn, §6 — no parent clause found); `"out of scope"` beside a reachable T-TERM violation (Agency Prospecting Channels and Sender IDs are now fixed, §4/§5, not excluded); `"known residual gap"` language beside T-TERM-1/T-TERM-2 (replaced with named stop conditions, §11, for the two cases that genuinely cannot be closed within this contract's authority); `"18 paths"` (now 22, §7a, not preserved for consistency); `"Open item, not resolved"` for the CustomerContext edge case (now a concrete stop condition with mechanical proof of reachability, §9); `"Sender IDs ... unchanged"` (now changed, §5a/§7a #20); `"provider credential labels ... remain"` (now explicitly not authorized to remain unexamined — mechanically located and reported as a stop condition instead, §6).

---

## 15. Completion gate

This slice is complete when, restricted to the 22 paths in §7a plus the 15 locale keys in §2:
- Zero occurrences of every forbidden term in §2's matrix remain in rendered customer copy for Core, Growth, Agency owner, Agency admin, Agency selected staff, and ordinary Business context, including the horizontal layout where reachable and including Agency Prospecting — **except** the two stop conditions in §11 (items 1 and 2), each closed only by an explicit human/ChatGPT decision, never by silent exception.
- The unselected-multi-workspace wording decision (§9/§11 item 3) is made and implemented, or the state is proven unreachable with fresh evidence — not left as an open item.
- Every locale key in §2 resolves; no fake key added for anything still undefined.
- `panels.horizontalMenu`'s customer branch is proven, by the mechanical render regression in §10, to render through `CustomerMenuBuilder` with the legacy `menuData` customer branch unreachable — not merely commented as unreachable.
- `CustomerMenuBuilder.php:224` and the two `SenderID` views render `Sender identities`, never `Sender ID`/`Sender IDs`, on any customer-reachable response.
- `showsWorkspaceVocabulary()` no longer exists, or its continued existence is justified against a newly-found caller.
- No prohibited path (§8) was touched — including `app/Http/Controllers/**`, which stop conditions §11.1 explicitly could not touch.
- T-TERM-1, T-TERM-2 (absolute, per §3), T-I18N-3, T-NAV-4 pass, plus the full regression coverage in §12.
- Human/ChatGPT review accepts this correction, including its two open stop conditions, before implementation begins.

---

*End of Correction 1. No implementation, migration, route change, or test was performed by this document. Ready for human/ChatGPT review, gated on Chat A's actual merge (§13) and on resolving the three stop conditions in §11.*
