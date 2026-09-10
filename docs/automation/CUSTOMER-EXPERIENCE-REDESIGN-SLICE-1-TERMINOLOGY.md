# Customer Experience Redesign — Slice 1: Terminology + Customer Shell Implementation Contract

**Status: CONTRACT ONLY.** No product code, migration, route, or test change is made or authorized by this document. Every claim below was mechanically re-verified against current code during drafting; none is copied from a prior report without independent re-derivation.

---

## 0. Verified base and inspected heads

```
contract_type: implementation_contract
docs_only: true
implementation_authorized_by_this_document: false
```

- **Authoritative `origin/main` at issuance of this task**: `7f729ca686c0f2cf04c1ab39dbaafdff7c6c3ba3`.
- **`origin/main` advanced during this task, to**: `b87b669d55de97957d3583407ec2b69cd75d2eaa`. Verified via `git log 7f729ca..b87b669 --oneline`: exactly 8 commits, all belonging to PR #236 (`agent/mainline-theme-assets-publication`) — `b87b669` (merge), `8e03113`, `5814ec2`, `be71c42`, `c195049`, `6344a5a`, `705e1e3`, `4a17864`. No other product PR touched navigation or locale paths in this range. **This contract is written against `b87b669d55de97957d3583407ec2b69cd75d2eaa`**, per the task's own instruction to use the newer `origin/main` when its only advancement is PR #236.
- **This contract's own worktree/branch**: `agent/customer-experience-redesign-slice-1-terminology-contract`, created via `git worktree add ... origin/main` at exactly `b87b669`, confirmed clean throughout.
- **Chat A's Slice 3 messaging-provider-implementation branch, inspected read-only, never checked out into this worktree**: head `403c5f83c33d4a6b19a9d45587a3a832002ed681` ("Slice 3 checkpoint 35: the full regression result and its baseline"). Confirmed via `git merge-base --is-ancestor 403c5f8... origin/main` (exit 1) that this branch is **NOT yet merged**. All Chat A file contents in §5 below were read exclusively via `git show 403c5f8...:<path>` — no `git checkout`/`git switch` was ever run against this branch.
- **PR #236 (theme assets), head/merge status**: head `8e03113a8cc737edd07077de38ad960691d4dba0`, **confirmed merged** into `origin/main` as part of the `b87b669` merge commit.

---

## 1. Authoritative parent sections consulted

- `docs/automation/AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md`, confirmed byte-identical to the version already read in full in a prior task, re-confirmed unchanged at `b87b669` via `diff`:
  - **§4.4 (D-3)**, **§4.6 (D-5/D-6/D-7)** — the defect register entries this slice closes, lines 304–386.
  - **§7 "Exact customer hierarchy, by role"**, lines 926–976 — authoritative per-role vocabulary and visibility rules.
  - **§16.0** — collision map; `resources/lang/en/locale.php` is contended by other unmerged branches; land before both or after both (re-verify current unmerged-branch state at implementation time — the branch set changes as other lanes merge, so this contract does not hardcode branch names that may already be stale by the time Slice 1 is implemented).
  - **§16, "Slice 1 — Terminology, translation and dead-link cleanup"**, lines 2039–2056 — the exact objective, paths, acceptance, tests and stop condition this contract implements.
  - **§17 "Test matrix"**, lines 2245–2320 — exact definitions of T-TERM-1, T-TERM-2, T-I18N-3, T-NAV-4 (and the adjacent T-NAV-5/T-NAV-6, owned by Slice 2, cited here only to confirm the boundary).
- `docs/automation/PRODUCT-SURFACE-RETENTION-AUDIT.md`, read in full — used to determine which discovered leaks belong to modules already resolved as **REBUILD FROM SCRATCH** or **DELETE LATER** (§12.1, §12.8, §12.3) and therefore excluded from this slice's allowlist as "legacy campaign/provider surfaces assigned to later retention slices" per this task's prohibited-scope instruction.
- `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`, confirmed byte-identical to the fully-read/edited version from a prior task — its §6 role/permission matrix (`manage_advanced_provider` restricted to Platform owner and Agency owner) is the authority §5 below reconciles against.
- `AGENTS.md`, `CLAUDE.md`, `docs/automation/AI-AUTONOMY-STATE.json` — confirmed byte-identical to versions already read in full in prior tasks; governance model (Route 1/2/3, `merge_policy: human_only`, Claude never opens a PR or merges) applies unchanged.

---

## 2. Exact terminology matrix (locked, verbatim from the issuing instruction — not renegotiated here)

| Frame | Internal concept | Customer-visible label |
|---|---|---|
| Core / Growth | Workspace internal object | **Account** |
| Core / Growth | Business | **Business** |
| Agency | Workspace frame | **Agency account** |
| Agency | Businesses | **Client accounts** |
| All tiers | Location | **Location** / **Service area** (unchanged) |

**Forbidden in customer-facing copy, any tier including Agency**: `Workspace`, `Workspaces`, `Sub Account`, `Sub Accounts`, `Sending Server`, `Sender ID`, `Sender IDs`, `Originator`, and (per §5 below, specific to the Agency Advanced provider surface) `Messaging Channels`.

**Technical URL path unchanged**: `/workspaces/{workspaceUid}` stays exactly as-is — this is a route segment, not customer copy, and is explicitly out of scope for renaming.

**Not renamed by this slice**: models, route segments, database columns, tenancy concepts, internal class names (`Workspace`, `WorkspaceMembership`, `customer.workspaces.*` route names, `$workspaceUid` variables) — only rendered copy changes.

---

## 3. Exact `CustomerContext` change

Current state at `b87b669`, `app/Library/Navigation/CustomerContext.php`:

- **`usesBusinessVocabulary()`** (lines 127–133):
  ```php
  public function usesBusinessVocabulary(): bool
  {
      $workspace = $this->frameWorkspace();

      return $workspace !== null
          && in_array($workspace->tier, [WorkspacePlanTier::Core, WorkspacePlanTier::Growth], true);
  }
  ```
  Confirmed exactly 2 call sites repo-wide: `customer/workspaces/index.blade.php:11` and `customer/workspaces/show.blade.php:11`. Semantics confirmed Core/Growth-only, per its own doc comment ("Agency and not-yet-assigned accounts keep the established Workspace wording"). **Per fresh code evidence this task re-derived: do not widen.** No change to this method.

- **`showsWorkspaceVocabulary()`** (lines 116–119):
  ```php
  public function showsWorkspaceVocabulary(): bool
  {
      return $this->isAgency();
  }
  ```
  Confirmed **zero call sites repo-wide** (only the definition itself matches a grep for `showsWorkspaceVocabulary`). The zero-caller proof holds at this SHA. **Contract: delete this method** at implementation time, contingent on re-confirming the zero-caller proof still holds at the implementation SHA (stated as a stop condition, §11).

- **Add `accountNoun()` / `accountsNoun()`**, reconciled exactly with §2's matrix and reusing the existing `usesBusinessVocabulary()` boolean (no new frame logic invented):
  ```php
  public function accountNoun(): string
  {
      return $this->usesBusinessVocabulary() ? 'account' : 'Agency account';
  }

  public function accountsNoun(): string
  {
      return $this->usesBusinessVocabulary() ? 'accounts' : 'Agency accounts';
  }
  ```
  Lowercase inline form, matching the existing convention in `customer/workspaces/index.blade.php`/`show.blade.php` (`$accountNoun`/`$accountNounPlural` are used inline, lowercase; the page title is separately title-cased by the call site — e.g. `Str::ucfirst($resolvedCustomerContext->accountsNoun())` or an equivalent Blade-side capitalization, not a second method). **Open item, not resolved by this contract, flagged rather than invented:** the `false`-branch of `usesBusinessVocabulary()` also covers the "not-yet-assigned Workspace" case (`frameWorkspace() === null`), which the locked hierarchy in §2 does not name explicitly. This contract directs `accountNoun()`/`accountsNoun()` to return the Agency wording in that case too (never the raw word "Workspace"), since raw "Workspace" is forbidden unconditionally and "Agency account" is the only other approved noun this contract is authorized to use — implementation must re-confirm this is inert in practice (no not-yet-assigned-Workspace actor reaches `workspaces/index.blade.php` or `show.blade.php` today) before relying on it, and must stop and ask if it is not.

  **Exact call sites to change**, replacing the raw-`Workspace` ternary fallback (the literal D-7 defect):
  - `customer/workspaces/index.blade.php:11-14` — replace
    ```php
    $accountNoun = $accountVocabulary ? 'account' : 'Workspace';
    $accountNounPlural = $accountVocabulary ? 'accounts' : 'Workspaces';
    $accountTitle = $accountVocabulary ? 'Accounts' : 'Workspaces';
    ```
    with calls to `$resolvedCustomerContext->accountNoun()` / `accountsNoun()` (title-cased for `$accountTitle`).
  - `customer/workspaces/show.blade.php:11-16` — same replacement, including the `@section('title', $accountVocabulary ? 'Account overview' : 'Workspace overview')` at line 16, which must become `$accountVocabulary ? 'Account overview' : 'Agency account overview'`.

---

## 4. Exact horizontal-menu leak correction

Mechanically re-verified, all three claims in the issuing instruction hold exactly at `b87b669`:

- `app/Providers/MenuServiceProvider.php:44-53` composes `CustomerShellComposer` into exactly `panels.sidebar`, `panels.navbar`, `panels.breadcrumb`, `components.customer-context-switcher`, `components.view-as-banner`. **`panels.horizontalMenu` is absent.**
- `resources/views/panels/horizontalMenu.blade.php:33-41` branches `auth()->user()->active_portal == 'admin'` to `$menuData[1]->admin`, else `$menuData[1]->customer` — the legacy `Helper::menuData()` static array, shared globally via `View::share('menuData', ...)` at `MenuServiceProvider.php:37`. It uses `CustomerMenuBuilder`, `CustomerContext` and `CustomerShellComposer` nowhere.
- `app/Helpers/Helper.php`'s customer branch of `menuData()` (lines 919–1094) still literally emits `'Workspaces'` (931-932), `'Channels'` (1013-1014) and `'Sender ID'` (954-955) — none of it vocabulary-aware, none of it entitlement- or authorization-gated the way `CustomerMenuBuilder` is.

**Exact narrow correction, matching the issuing instruction's constraints**:
1. `app/Providers/MenuServiceProvider.php:44-51` — add `'panels.horizontalMenu'` to the `View::composer([...])` array.
2. `resources/views/panels/horizontalMenu.blade.php` — mirror the vertical shell's customer-vs-admin guard: the customer branch (currently `$menuData[1]->customer`) is replaced with the composed, canonical `CustomerMenuBuilder`-driven menu items already available to `panels.sidebar` via `CustomerShellComposer`; the admin branch (`$menuData[1]->admin`) is untouched.
3. **Not touched**: `resources/views/panels/horizontalSubmenu.blade.php`. No fresh evidence was found in this task proving it renders a distinct customer-facing branch requiring correction (its one `locale.menu.` call site was found to share the same admin-only data path); per the issuing instruction, it is modified only if fresh evidence proves it required, which this task's evidence does not.
4. **Not touched**: `app/Helpers/Helper.php`'s customer array itself. Per the issuing instruction, no deletion — after step 2, this array becomes provably unreachable **only if** `sidebar.blade.php`'s own customer branch (`$menuData['0']->customer`, guarded by `CustomerShellComposer::isCustomerPortal()`) also never renders it. This task's fresh evidence: that branch requires a user with `active_portal === 'customer'` and `is_customer === false`, which no controller in the codebase constructs, and every real customer login sets `is_customer = true` — **effectively unreachable, but not a hard route/model guard**. This contract instructs: leave the array in place, unmodified, with its existing "compatibility data" doc comment (`Helper.php:909-918`) — do not add or remove entries.
5. Layout reachability, for the record only, not a scope change: `panels/horizontalMenu.blade.php` and `horizontalSubmenu.blade.php` render only when `config('app.theme_layout_type') === 'horizontal'` (default `vertical`, no shipped view currently opts into the horizontal layout master). The T-TERM-1 acceptance bar ("horizontal layout where reachable") still requires this fix, since an operator can enable it via `THEME_LAYOUT_TYPE`.

---

## 5. Agency Advanced provider terminology — exact rendered strings, mechanically re-verified against Chat A's latest head

Read exclusively via `git show 403c5f8...:<path>` (no checkout). The four files Chat A's branch introduces at `resources/views/customer/settings/advanced/`:

| File | Forbidden string found | Exact location | Required correction |
|---|---|---|---|
| `entry.blade.php` | `Messaging Channels` | line 3 (title), line 8 (heading), line 16 (empty-state body: "Messaging Channels are organized by Business…") | → `Messaging provider` (or a heading built from the same approved noun) |
| `entry.blade.php` | `Workspace` | line 16 ("…ask a Workspace owner to add you…") | → account noun per §2/§3, same pattern as the other nine entry-chooser views in §7 |
| `index.blade.php` | `Messaging Channels` | line 3 (title), line 8 (heading) | → `Messaging provider` |
| `index.blade.php` | `Sender IDs` | line 75 (section heading) | → `Sender identities` |
| `show.blade.php` | `Sender IDs` | line 104 (section heading) | → `Sender identities` |
| `connect.blade.php` | none found | — | no change |

`app/Library/Navigation/CustomerMenuBuilder.php` at the same Chat A head: the `advancedItems()` menu item at line 221 already reads exactly `'Messaging provider'` — **already correct, no change needed there**. No occurrence of `Messaging Channels`, `Sending Server`, `Originator`, or `manage_advanced_provider` anywhere in the file at that head.

**Explicitly not corrected by this slice, and not authorized**: `CustomerMenuBuilder.php:224`'s separate `'Sender IDs'` nav item (the standalone legacy Sender-ID CRUD feature, route `customer.senderid.index`) and its destination screens `customer/SenderID/{index,request_new}.blade.php`. These belong to the **Numbers/SenderID/Keywords/Compliance** module, classified `REBUILD FROM SCRATCH` and resolved to a future "simplified provider/channel connect" experience (`PRODUCT-SURFACE-RETENTION-AUDIT.md` §6.7/§6.9, §12.8, §9 Category B #5) — a legacy campaign/provider surface assigned to a later retention slice, explicitly prohibited by this task. Renaming only the nav label without correcting the destination screen it points to would create a menu-vs-page vocabulary mismatch, so this contract leaves both the label and its destination untouched and records the gap here for the owning future slice.

**Provider credential field labels**: not audited for renaming — the issuing instruction explicitly permits technical vendor credential labels inside the Agency-only provider screen to remain, and this slice does not touch provider behavior, credentials, permissions, or routes.

**Because these four files exist only on Chat A's unmerged branch**, none of the corrections in this section can be applied to `origin/main` today. They are locked into the implementation allowlist (§7) as **conditional on Chat A's merge** and must be re-verified against whatever the merged tree actually contains (see §10 stale-main handling) before being applied.

---

## 6. Locale/raw-key audit — recomputed, not copied

The prior report's 11-item claim was independently re-derived from current code, not trusted. Result: **9 confirmed, 1 refuted, 1 not-yet-real (excluded), plus 3 newly discovered — net 12 genuine current leaks**, none of them customer-sidebar leaks (that surface is already `Lang::has()`-guarded and falls back safely — `app/Library/Navigation/MenuItem.php`, `components/customer-nav-item.blade.php:16-17`).

**`locale.menu.*` additions (9 keys)** — all reached only through the **admin** sidebar (`panels/sidebar.blade.php:104`, `panels/submenu.blade.php:23`, no `Lang::has()` guard) or the **Admin Roles** editor (`admin/AdminRoles/create.blade.php:36`), never through any customer-facing path. Adding these requires **no admin Blade edit** — the call sites already exist and already say `__('locale.menu.'.$menu->name)`; only `resources/lang/en/locale.php` needs the keys:

| Key | Emission site |
|---|---|
| `Platform Settings` | `app/Helpers/Helper.php:770` |
| `Theme Presets` | `app/Helpers/Helper.php:778` |
| `Usage Billing` | `app/Helpers/Helper.php:877` |
| `Safety Limits` | `app/Helpers/Helper.php:885` |
| `Provider Events` | `app/Helpers/Helper.php:893` |
| `Additional Slot Agreements` | `app/Helpers/Helper.php:901` |
| `Workspace` | `config/permissions.php:528` (category, rendered via Admin Roles editor) |
| `Workspace Plans` | `config/permissions.php:534,539` |
| `Opportunities` | `config/permissions.php:545,550` |

**`locale.permission.*` additions (3 keys)** — reached through both the admin `_permissions.blade.php`/`_advanced.blade.php` screens and the customer `SubAccounts/{show,create}.blade.php` screens (all unguarded), sourced from `config/customer-permissions.php`. Again, no Blade edit required — `locale.php` alone closes the leak:

| Key | Emission site |
|---|---|
| `website` | `config/customer-permissions.php:32` |
| `read_google_business_profile` | `config/customer-permissions.php:45` |
| `manage_google_business_profile` | `config/customer-permissions.php:50` |

**Explicitly excluded, with evidence**:
- **`Messaging`** — REFUTED. No code emits the bare string `'Messaging'` as a menu name today; the real key already shipped is `Messaging provider` (`locale.php:928`). Not added.
- **`manage_advanced_provider`** — NOT FOUND. Does not exist in `config/customer-permissions.php`, no `Gate::define`, no `authorize()` call anywhere in current code — only a forward-looking docblock comment in `MessagingChannelsController.php:60` and Chat A's own unmerged planning doc. Nothing to add a key for; adding one now would be inventing a key for a permission that does not exist, which the issuing instruction explicitly forbids ("Contract only keys which are genuinely rendered after Slice 1").
- **Legacy customer `Helper::menuData()` keys** (`Channels`, `Opportunities`, `Compose` in the customer array) — not added. Per §4, that array's customer branch becomes unreachable once the horizontal-menu fix lands; adding keys for it would legitimize a path this slice deliberately closes, which the issuing instruction explicitly forbids.
- **Horizontal-layout duplicate reachability** — `horizontalMenu.blade.php`/`horizontalSubmenu.blade.php` share the identical admin `name` values as the vertical sidebar; not a second distinct set of keys, not double-counted.

---

## 7. Exact implementation allowlist — recounted, not copied

The prior audit's own heading ("Production — 16 paths") did not match its enumeration (19 rows). Neither number is reused here. This task's independent recount:

**15 paths, mutation confirmed necessary on current `origin/main` (`b87b669`), no merge dependency to *read* the leak (implementation start is still gated per §9):**

| # | Path | Exact permitted mutation |
|---|---|---|
| 1 | `resources/lang/en/locale.php` | Add the 12 keys in §6. No deletions. |
| 2 | `resources/views/customer/Automations/entry.blade.php` | Line 15: replace literal `Workspace` with the account noun per §2/§3. |
| 3 | `resources/views/customer/business/analytics/entry.blade.php` | Line 21: same replacement. |
| 4 | `resources/views/customer/business/analytics/overview.blade.php` | Line 25 ("Back to Workspace") — **newly discovered in this task, not in the prior ten**: same replacement. |
| 5 | `resources/views/customer/business/website/entry.blade.php` | Line 15: same replacement. |
| 6 | `resources/views/customer/Outreach/entry.blade.php` | Line 15: same replacement. |
| 7 | `resources/views/customer/business/googleBusinessProfile/entry.blade.php` | Line 21: same replacement. |
| 8 | `resources/views/customer/workspace/additional-business-slots/show.blade.php` | Lines 11 and 38: same replacement. |
| 9 | `resources/views/customer/workspaces/prospecting/entry.blade.php` | Lines 14, 15 (×2), 19: same replacement. |
| 10 | `resources/views/customer/workspaces/index.blade.php` | Lines 11–14: replace the hardcoded `Workspace`/`Workspaces` ternary fallback with `CustomerContext::accountNoun()`/`accountsNoun()` per §3. |
| 11 | `resources/views/customer/workspaces/show.blade.php` | Lines 11–16 (incl. the `@section('title', ...)` at 16): same replacement. |
| 12 | `resources/views/panels/horizontalMenu.blade.php` | Customer branch: consume the `CustomerShellComposer`-composed canonical menu instead of `$menuData[1]->customer`, per §4. |
| 13 | `app/Helpers/Helper.php` | No deletion; confirm/annotate (comment only) that the customer branch of `menuData()` is unreachable after #12. |
| 14 | `app/Library/Navigation/CustomerContext.php` | Delete `showsWorkspaceVocabulary()` (zero-caller proof reconfirmed); add `accountNoun()`/`accountsNoun()` per §3; `usesBusinessVocabulary()` unchanged. |
| 15 | `app/Providers/MenuServiceProvider.php` | Add `'panels.horizontalMenu'` to the `View::composer([...])` array per §4. |

**3 more logical surfaces, mutation confirmed necessary, but conditional on Chat A's merge outcome (§10 stale-main handling governs the exact physical path at implementation time):**

| # | Path (pre-merge legacy name, or Chat A's proposed name) | Exact permitted mutation |
|---|---|---|
| 16 | `customer/business/MessagingChannels/entry.blade.php` (current main) → `customer/settings/advanced/entry.blade.php` (Chat A) | Heading/title `Messaging Channels` → `Messaging provider`; `Workspace` → account noun, per §5. |
| 17 | `customer/business/MessagingChannels/index.blade.php` (current main) → `customer/settings/advanced/index.blade.php` (Chat A) | Heading/title `Messaging Channels` → `Messaging provider`; `Sender IDs` section heading → `Sender identities`, per §5. |
| 18 | `customer/business/MessagingChannels/show.blade.php` (current main) → `customer/settings/advanced/show.blade.php` (Chat A) | `Sender IDs` section heading → `Sender identities`, per §5. |

**Total: 18 production paths with a confirmed, exact required mutation.**

**Verified, explicitly requiring zero mutation** (checked because the issuing instruction required proof, not because a prior report named them):
- `app/Library/Navigation/CustomerMenuBuilder.php` — no forbidden literal customer-facing terminology found on current main or on Chat A's head, except the already-addressed §5 residual gap (left unchanged by design, not by omission).
- `resources/views/customer/settings/advanced/connect.blade.php` (Chat A's branch) — read, no forbidden term found.
- `resources/views/customer/business/usage-billing/show.blade.php` — **removed from the allowlist.** The prior report's claimed lines 28/165/177 are stale: this file contains **zero** literal occurrences of `Workspace` today; it already renders via `locale.usage_billing.back_to_agency`/`back_to_account` and payer-responsibility translation keys. No work required.

**Explicitly out of scope — found during the exhaustive re-grep, excluded as legacy campaign/provider surfaces assigned to a later retention slice** (`PRODUCT-SURFACE-RETENTION-AUDIT.md` §6.1/§6.7/§6.9, §12.1/§12.8, §9 Category B #4/#5) — none are added to the allowlist, and this task does not authorize touching them:

- `resources/views/customer/Outreach/_originator.blade.php` (Sending Server / Sender ID / Originator picker — D-10, folds into the future Outreach/Compose rebuild)
- `resources/views/customer/SenderID/index.blade.php`, `request_new.blade.php`
- `resources/views/customer/SendingServer/create.blade.php`, `index.blade.php`, `list.blade.php`
- `resources/views/customer/workspaces/prospecting/channels/index.blade.php` — a Twilio/Telnyx provider-connect screen (line 9: "…owned by this Workspace…"); excluded because it is a provider-configuration surface adjacent to Chat A's provider work, not a generic informational view, and this task prohibits touching provider surfaces beyond exact-terminology-label changes already scoped in §5.

---

## 8. Prohibited paths (restated, unchanged from the issuing instruction)

No mutation in this slice touches: `database/migrations/**`, `app/Http/Controllers/**`, `routes/**`, `config/**`, `resources/views/admin/**`, `resources/views/layouts/**`, `resources/scss/**`, `public/**`, `package*.json`, `composer*.json`, B4/B5/Website/GBP product internals, Usage billing internals, messaging runtime, `DLRController`, managed messaging provider implementation, `CustomerMenuBuilder` behavior beyond exact terminology labels (and even there, none is required per §7's verification), `CustomerContext` behavior beyond the vocabulary helpers in §3, or the excluded legacy campaign/provider surfaces in §7. Chat A's security correction and PR #236's theme assets are not touched.

---

## 9. Test map — mechanically derived from §17, not invented

| Test | Exact assertion (from parent §17) | What Slice 1's allowlist must satisfy |
|---|---|---|
| **T-TERM-1** | No rendered customer response contains `Workspace`, any tier including Agency | Every §7 path #2–11, #16 fixed; horizontal layout path (#12) fixed; §3's `CustomerContext` change closes the Agency-title gap (D-7) |
| **T-TERM-2** | No rendered customer response contains `Sending Server`, `Sender ID`, `Originator`, `Sub Account`, `Account SID`, `Auth Token`, `API Key` | §5/§7 paths #17–18 (`Sender IDs` → `Sender identities`, `Messaging Channels` → `Messaging provider`); explicitly does **not** cover the out-of-scope SenderID/SendingServer/Outreach-compose surfaces in §7 — those remain a known, documented residual gap owned by the future "simplified provider/channel connect" and "Outreach/Compose" rebuilds, not by this slice. Vendor credential field labels inside the Agency-only advanced screen are tested as permitted exceptions only, per the parent contract. |
| **T-I18N-3** | No rendered response, customer or admin, contains a string matching `locale.` | The 12 keys in §6 close every currently-reachable raw-key leak; the still-unreachable legacy customer `menuData()` keys are deliberately not legitimized with fake entries |
| **T-NAV-4** | Every URL emitted by any rendered customer navigation resolves to a registered route | Unaffected by this slice (no route change); re-run as a regression guard against §4's horizontal-menu change |

**Also required, per the issuing instruction, as regression coverage beyond the four named tests**:
- Role/plan matrix: Core owner, Growth owner, Agency owner, Agency admin, Agency staff (selected/all-Business), ordinary Business user — each exercised against every changed view in §7.
- Route/URL unchanged: assert `/workspaces/{workspaceUid}` and all `customer.workspaces.*` route names are byte-identical before/after.
- Tenancy unchanged: no `business_id`/`user_id`/authorization logic touched (this slice is copy-only plus one dead-method deletion plus one composer registration).
- Vertical customer shell regression: `panels.sidebar`/`navbar`/`breadcrumb` continue to render exactly as today (only `panels.horizontalMenu` gains composition).
- Agency Advanced regression: after §5's changes land (post-merge), the screen still allows configuring Twilio/Telnyx — copy-only change, not a form-field or route change.
- No raw translation key rendering: covered by T-I18N-3, extended to the 12 new keys specifically.

---

## 10. Dependency / merge order and stale-main handling

**Implementation may start only after:**
1. Chat A's Customer Experience Slice 3 messaging-provider branch (currently at `403c5f8...`) is merged into `origin/main`.
2. PR #236 theme assets are merged — **already satisfied**, confirmed in §0.

No other dependency is invented. The **contract** (this document) is prepared now, against `b87b669`, per the issuing instruction.

**Stale-main handling, mechanically specified rather than left implicit:**
- Before implementing §7 items #16–18, re-run `git ls-tree -r <merged-Chat-A-SHA> --name-only | grep -iE "MessagingChannels|settings/advanced"` to determine which physical paths actually survived the merge. If Chat A's merge relocated `customer/business/MessagingChannels/{entry,index,show}.blade.php` to `customer/settings/advanced/{entry,index,show}.blade.php` and deleted the originals, apply the §5 corrections only at the new paths. If Chat A's merge kept both (e.g. a redirect-only rename), apply the corrections at both. If Chat A's merged content differs from what `git show 403c5f8...` showed in §5 (e.g. the heading text changed before merge), re-derive the exact strings from the merged tree before editing — do not assume §5's citations still match byte-for-byte.
- Before implementing §6, re-run the full `locale.menu.`/`locale.permission.` emission-site grep against the merged tree (Chat A's branch may add its own menu entries or permission keys, e.g. anything wiring up `manage_advanced_provider` for real) — add any genuinely new leak found; do not add speculative keys for anything still undefined at implementation time.
- Before implementing §4/§7 item #12, re-check `app/Providers/MenuServiceProvider.php` and `resources/views/panels/horizontalMenu.blade.php` are still in the state described in §4 — if another unmerged branch from §16.0's collision map has landed in the interim and already touched either file, reconcile rather than overwrite.
- Before implementing §3/§7 item #14, re-run the zero-caller grep for `showsWorkspaceVocabulary(` — if any caller has appeared since this contract was written, do not delete the method; stop and report the new caller instead (§11).
- If `origin/main` has advanced by more than PR #236's range by the time implementation starts, repeat the ancestor check in §0 for whatever the new head is, and re-run this document's entire mechanical verification (§3–§7) against that head before touching any file — this contract's citations are pinned to `b87b669` and are not assumed to still hold verbatim after further merges beyond Chat A's.

---

## 11. Stop conditions

- A forbidden term cannot be replaced without changing a route name (the parent contract's own stated stop condition for Slice 1).
- `showsWorkspaceVocabulary()` gains a caller before implementation (§10).
- `usesBusinessVocabulary()`'s Core/Growth-only semantic is contradicted by fresh code evidence at implementation time (per the issuing instruction, do not widen it without such proof).
- Chat A's merged content for the four advanced-settings blades in §5 diverges materially from what was read at `403c5f8...` in a way that changes which strings need correction.
- Any change in this contract would require touching a prohibited path (§8) to close a forbidden-term occurrence — in that case, leave the occurrence open and report it rather than expanding scope.
- The not-yet-assigned-Workspace edge case flagged in §3 is found to be reachable in `customer/workspaces/{index,show}.blade.php` with a live raw "Workspace" render.

---

## 12. Completion gate

This slice is complete when, restricted to the 18 paths in §7 plus the 12 locale keys in §6:
- Zero occurrences of `Workspace`, `Workspaces`, `Sub Account`, `Sub Accounts`, `Sending Server`, `Sender ID`, `Sender IDs`, `Originator`, or `Messaging Channels` remain in rendered customer copy, for Core, Growth, Agency owner, Agency admin, selected staff, and ordinary Business context, including the horizontal layout where reachable — **except** the explicitly out-of-scope legacy provider surfaces in §7, which remain open and documented, not silently dropped.
- Every locale key in §6 resolves; no fake key is added for an unreachable or nonexistent permission.
- `panels.horizontalMenu`'s customer branch renders through `CustomerShellComposer`/`CustomerMenuBuilder`, with the legacy `menuData` customer branch unreachable from it; the admin branch is unchanged.
- T-TERM-1, T-TERM-2 (scoped per §9), T-I18N-3, T-NAV-4 pass, plus the regression coverage in §9.
- `showsWorkspaceVocabulary()` no longer exists (or its continued existence is explicitly justified against a newly-found caller, per §11).
- No prohibited path (§8) was touched; no route, controller, migration, config, or admin view changed.
- Human/ChatGPT review accepts this contract as the binding implementation spec for Slice 1.

---

*End of contract. No implementation, migration, route change, or test was performed by this document. Ready for human/ChatGPT review before Slice 1 implementation begins, gated on the dependency order in §10.*
