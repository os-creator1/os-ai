# Customer Experience Redesign — Slice 1: Terminology + Customer Shell Implementation Contract

**Status: CONTRACT ONLY.** No product code, migration, route, or test change is made or authorized by this document. This is Correction 2 — the final contract correction. All three previously-open human decisions are resolved and applied mechanically below; one new, additional discovery from this correction's exhaustive sweep is reported separately and is not one of the three resolved items.

---

## 0. Verified base and inspected heads

```
contract_type: implementation_contract
docs_only: true
implementation_authorized_by_this_document: false
correction: 2
```

- **Starting HEAD of this correction**: `d30125adf9e65b7840f343f8a59e1c94a10f1fb6` (Correction 1).
- **`origin/main` at issuance of this correction**: `823448994c2586d3818ad8333088e4976bcbc309` (PR #237, `agent/customer-experience-redesign-slice-2a-navigation-contract`). Confirmed via `git diff b87b669..origin/main --stat`: **exactly one file**, `docs/automation/CUSTOMER-EXPERIENCE-REDESIGN-SLICE-2A-NAVIGATION.md` (507 insertions, new file) — a contract-only sibling document, no production code. That document names Slice 1 as its own prerequisite and declares it will edit `CustomerMenuBuilder.php`/`CustomerContext*`/`locale.php` **after** Slice 1 lands — consistent with, not conflicting with, this contract.
- **Merge performed**: `git merge origin/main --no-edit`, normal merge (not rebase), resulting merge commit `243e86c977c59f8e17f3782ec3814d6f29a987cb`. Clean, no conflicts, no production file touched.
- **Chat A remote head observed at this correction**: `122f3301f235dad35c1e457afdfa8d5ca097bb95` — unchanged from Correction 1 (the correction's own text notes Chat A is "undergoing Security Correction 37," but no new commit is present on the fetched remote branch at this time; `122f330` remains the newest observable evidence and every citation below is pinned to it). Confirmed still not an ancestor of `origin/main`.

---

## 1. Human Decision 1 — provider credential labels: RESOLVED and applied

Authorized: Slice 1 may change exactly five `label` values in exactly two controller files, **only after Chat A's final Slice 3 merges into `origin/main`**. This is a narrow, named exception to the controller prohibition in §8 — not a general grant.

**Exact current locations** (unchanged at `122f330` since Correction 1's read — re-verify at the actual merge SHA before implementing, per §11):

| File | Line | Current value | New value |
|---|---|---|---|
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | 77 | `'account_sid' => ['label' => 'Account SID', ...]` | `'label' => 'Twilio account identifier'` |
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | 78 | `'auth_token' => ['label' => 'Auth Token', ...]` | `'label' => 'Twilio secret'` |
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | 84 | `'api_key' => ['label' => 'API Key', ...]` | `'label' => 'Telnyx access key'` |
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | 85 | `'c1' => ['label' => 'Message Profile ID', ...]` | `'label' => 'Messaging profile ID'` |
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | 86 | `'c2' => ['label' => 'Message Connection ID', ...]` | `'label' => 'Messaging connection ID'` |
| `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | 44 | `'account_sid' => ['label' => 'Account SID', ...]` | `'label' => 'Twilio account identifier'` |
| `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | 45 | `'auth_token' => ['label' => 'Auth Token', ...]` | `'label' => 'Twilio secret'` |
| `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | 51 | `'api_key' => ['label' => 'API Key', ...]` | `'label' => 'Telnyx access key'` |
| `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | 52 | `'c1' => ['label' => 'Message Profile ID', ...]` | `'label' => 'Messaging profile ID'` |
| `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | 53 | `'c2' => ['label' => 'Message Connection ID', ...]` | `'label' => 'Messaging connection ID'` |

**Exactly five distinct visible labels** (each appears twice, once per controller): `Twilio account identifier`, `Twilio secret`, `Telnyx access key`, `Messaging profile ID`, `Messaging connection ID`. Array **keys** (`account_sid`, `auth_token`, `api_key`, `c1`, `c2`), `required` flags, validation, persistence, authorization (`isOwner` + `Gate::allows('manage_advanced_provider')`), routes, and every other line in both files are untouched. Provider names `Twilio`/`Telnyx` remain unchanged and both providers remain fully configurable. This is the only place these five strings are declared (confirmed no locale key or other file duplicates them) — no additional path is touched to make this change.

**Test requirement**: the implementation must assert the rendered `<label>` text is the new value while the `<input name="...">` attribute and the submitted request's field keys remain exactly `account_sid`/`auth_token`/`api_key`/`c1`/`c2`.

This is no longer a stop condition and is not assigned to Chat A's Security Correction 37 — it is Slice 1's own work, gated on Chat A's merge (§11).

---

## 2. Human Decision 2 — legacy Outreach/Campaign terminology: RESOLVED and mechanically enumerated

Authorized: the parent's absolute T-TERM-2 acceptance wins over the retention audit's "do not design legacy UI" decision, because a terminology-only repoint is not a redesign. Full mechanical enumeration, not the prior "~20" estimate:

**Shared keys and their consumers** (`git grep`, both customer and admin, at the merged HEAD):

| Shared key | Current value | Customer consumers | Admin consumers |
|---|---|---|---|
| `locale.labels.sending_server` | `Sending Server` | 22 files (§7b, Group 1B-i) | `admin/Reports/all_messages.blade.php`, `admin/Announcements/create.blade.php` |
| `locale.labels.originator` | `Originator` | 6 files (§7b, Group 1B-i) | none |
| `locale.labels.sender_id` | `Sender ID` | 24 files (§7b, Group 1B-i) | `admin/plans/edit.blade.php`, `admin/BlockSenderID/{create,index}.blade.php`, `admin/keywords/create.blade.php`, `admin/dashboard.blade.php`, `admin/Templates/create.blade.php`, `admin/Announcements/create.blade.php` |

All three are **category A** (shared with admin) — the admin values are never mutated. Every customer call site is repointed to one of two new customer-only keys instead:

- `locale.labels.messaging_provider` = `'Messaging provider'` — replaces every customer `locale.labels.sending_server` reference.
- `locale.labels.sender_identity` = `'Sender identity'` — replaces every customer `locale.labels.originator` **and** `locale.labels.sender_id` reference (both resolve to the identical approved noun; one key serves both, per the instruction not to create duplicate aliases for one concept).

**Semantic check, per the instruction to stop on any field that means something materially different**: every occurrence read in full context (§7b lists each). `originator` is always the section label above a sender-identity picker; `sender_id` is always either a select/option value or a text field for one sender identity; `sending_server` is always the gateway/route picker. No occurrence found where any of the three means something else — **no stop triggered on this axis**.

**One correction to Correction 1's own prior work**: Correction 1 repointed `customer/SenderID/request_new.blade.php:36` to the plural nav key `locale.menu.Sender identities`. Re-reading that file's context (a single text-input field for entering **one** new sender identity, `@section('title', __('locale.labels.request_for_new_one'))`), the singular form is grammatically correct there. **Corrected in this pass**: that line now repoints to the new singular `locale.labels.sender_identity` instead. `customer/SenderID/index.blade.php` (a listing page's title and table header — genuinely plural contexts) keeps Correction 1's `locale.menu.Sender identities` repoint, unchanged.

No form input name, request field, database column, provider lookup, `SendingServer` model, campaign orchestration, provider behavior, route, controller (beyond §1's two exact arrays), admin view, or schema is touched by this decision. Every file in §7b is a Blade-only, key-repoint-only change.

---

## 3. One new discovery from this correction's exhaustive sweep — not one of the three resolved decisions, reported now

The full sweep required by §5 below found a fourth, previously-uncatalogued issue distinct in kind from the three resolved decisions: **the legacy Sub-Accounts feature's own name is the forbidden term.**

`resources/lang/en/locale.php:701` (`'sub_accounts' => 'Sub Accounts'`) and the `'sub_accounts' => [...]` block at lines 2178–2198 (13 distinct string values: page titles, `Update Sub Account`, `Sub account successfully added/updated/deleted`, four confirm-dialog strings, etc.) drive `resources/views/panels/navbar.blade.php:348-350` (a persistent navbar dropdown item, `route('customer.sub_accounts.index')`) and all of `resources/views/customer/SubAccounts/{index,create,show}.blade.php`. This is a live, always-reachable, first-class feature whose **entire identity** is the forbidden term — not an incidental label inside an unrelated form.

This is materially different from every other item in this contract: §2's terminology matrix has no entry for it (Sub-Accounts is a delegated-staff-access system, `users.parent_id` — orthogonal to the Workspace/Business/Agency hierarchy §2 actually defines), and the obvious candidate replacement, "Client accounts," is **already assigned** by §2 to a different concept (Businesses under an Agency) — reusing it here would create a new collision, not fix one. The retention audit separately, and already, locks this feature's fate (§12.3: migrate into Workspace membership, **then** retire the legacy UI — "No Design System work on legacy Sub-Account pages in the meantime"), which is a live migration-sequencing concern, not merely a "don't bother redesigning" note: renaming its customer-visible identity before that migration could read as though it already changed models, which it has not.

**This is not silently classified as "reachable but deferred."** It is named here, explicitly, as a genuinely new item this correction's mandate did not ask it to resolve (the three decisions given were about provider labels, legacy Outreach/Campaign copy, and multi-workspace vocabulary — none of them name Sub-Accounts). It is not added to either sub-slice's allowlist in §7, and it is not counted in this contract's "resolved" total. **Recommendation, not a decision made here**: a fourth human decision is needed — either an approved customer-facing name for delegated staff access (distinct from "Client accounts" and from `CustomerMenuBuilder`'s own, different "Team & account"/"Team & agency account" concept, to avoid a second collision), or an explicit, recorded acceptance that "Sub Account(s)" stays until the §12.3 migration actually happens.

---

## 4. Human Decision 3 — implementation sizing: RESOLVED — Sub-Slice 1A / 1B

The mechanically-final allowlist (§7) totals **51 production paths** — well past the ~30 threshold. Per the instruction, this is contracted as two sequential implementation PRs under the same authoritative Slice 1 contract, not one giant PR and not artificial numbering:

- **Slice 1A** (24 paths, §7a): Account/Agency vocabulary, horizontal shell correction, locale raw-key closure, Agency Advanced terminology, Agency Prospecting wording, Sender identities nav/destination, provider metadata display labels.
- **Slice 1B** (27 paths, §7b): the legacy Outreach/Campaign/Template/ChatBox/Keyword/ContactGroup/SenderID-checkout/Developers terminology repointing from §2, needed solely to close T-TERM-2 on those surfaces.

**Slice 1 is not complete until both merge and the absolute T-TERM tests pass against both.** Slice 2A's own product implementation (as opposed to its already-merged contract) may not begin merely because 1A merges — it must wait for both.

---

## 5. Human Decision 4 — multi-workspace-unselected vocabulary: RESOLVED and applied

Authorized: for the reachable `frameWorkspace() === null` state (more than one accessible Workspace, none selected — confirmed reachable in Correction 1's §9, unchanged), use the neutral, already-approved customer noun: singular **Account**, plural **Accounts**, chooser title **"Choose an account"** where applicable. Never "Workspace"/"Workspaces", never "Agency account" for this specific unproven-frame state.

**`CustomerContext` — final, complete implementation** (no branch left open):
```php
public function accountNoun(): string
{
    return $this->usesBusinessVocabulary() || $this->frameWorkspace() === null
        ? 'account'
        : 'Agency account';
}

public function accountsNoun(): string
{
    return $this->usesBusinessVocabulary() || $this->frameWorkspace() === null
        ? 'accounts'
        : 'Agency accounts';
}
```
Three cases, matching the decision exactly: proven Core/Growth frame → `account`/`accounts`; proven Agency frame (`frameWorkspace()` non-null and `usesBusinessVocabulary()` false, i.e. Agency-tier) → `Agency account`/`Agency accounts`; no frame selected yet → `account`/`accounts` (same as Core/Growth, per the decision's explicit "no third noun" instruction). `usesBusinessVocabulary()` itself is unchanged (Correction 1, §3, still valid — no diff since `b87b669`).

**`workspaces/index.blade.php`/`show.blade.php`** (§7a #11-12): the hardcoded `Workspace`/`Workspaces` ternary fallback is fully replaced by `$resolvedCustomerContext->accountNoun()`/`accountsNoun()` with no remaining open branch. This closes the CustomerContext stop condition from Correction 1 completely.

---

## 6. Locale strategy — final exact count

Correction 1's 15 keys (11 `menu` + 4 `permission`) are unchanged and still needed (re-verified: `Messaging`/`manage_advanced_provider` emission sites in `config/customer-permissions.php` are unchanged at `122f330`). Correction 2 adds exactly **2** more, both new `labels`-namespace keys, per §2's minimal-set instruction (no per-file duplicate aliases):

| New key | Value | Replaces |
|---|---|---|
| `locale.labels.messaging_provider` | `Messaging provider` | every customer `locale.labels.sending_server` reference (§7b) |
| `locale.labels.sender_identity` | `Sender identity` | every customer `locale.labels.originator` and `locale.labels.sender_id` reference (§7b), plus the corrected `SenderID/request_new.blade.php:36` (§2) |

No admin-facing value is mutated; `locale.menu.Sender identities` (Correction 1, plural, for the nav item and the `SenderID/index.blade.php` listing page) is retained unchanged — it is a distinct grammatical form for a distinct UI role (a group/collection heading vs. a single-field label), not a duplicate.

**Final exact total: 17 keys** (11 menu + 4 permission + 2 labels) — not 15, not preserved from either prior pass.

---

## 7. Exact implementation allowlist — rebuilt from scratch, split by sub-slice, no wildcards

### 7a. Slice 1A — 24 paths

| # | Path | Exact reason | Exact permitted mutation |
|---|---|---|---|
| 1 | `resources/lang/en/locale.php` | §6 | Add the 17 keys. No deletions. |
| 2 | `resources/views/customer/Automations/entry.blade.php` | D-7 leak, line 15 | `Workspace` → `CustomerContext::accountNoun()`/parent §2 wording |
| 3 | `resources/views/customer/business/analytics/entry.blade.php` | D-7 leak, line 21 | same |
| 4 | `resources/views/customer/business/analytics/overview.blade.php` | D-7 leak, line 25 | same |
| 5 | `resources/views/customer/business/website/entry.blade.php` | D-7 leak, line 15 | same |
| 6 | `resources/views/customer/Outreach/entry.blade.php` | D-7 leak, line 15 | same |
| 7 | `resources/views/customer/business/googleBusinessProfile/entry.blade.php` | D-7 leak, line 21 | same |
| 8 | `resources/views/customer/workspace/additional-business-slots/show.blade.php` | D-7 leak, lines 11, 38 | same |
| 9 | `resources/views/customer/workspaces/prospecting/entry.blade.php` | D-7 leak, lines 14, 15, 19 | same |
| 10 | `resources/views/customer/workspaces/prospecting/channels/index.blade.php` | Agency Prospecting live leak, line 9 | same |
| 11 | `resources/views/customer/workspaces/index.blade.php` | D-7, lines 11–14 | Replace hardcoded ternary with `accountNoun()`/`accountsNoun()` per §5, all three cases |
| 12 | `resources/views/customer/workspaces/show.blade.php` | D-7, lines 11–16 | same, incl. `@section('title', ...)` line 16 |
| 13 | `resources/views/panels/horizontalMenu.blade.php` | D-3/D-6 horizontal leak | Customer branch consumes `CustomerShellComposer`/`CustomerMenuBuilder` instead of `$menuData[1]->customer` |
| 14 | `app/Helpers/Helper.php` | horizontal-menu fix dependency | No deletion; annotate unreachability of the customer branch after #13 |
| 15 | `app/Library/Navigation/CustomerContext.php` | §5 | Delete `showsWorkspaceVocabulary()` (zero-caller reconfirmed, no diff since `b87b669`); add `accountNoun()`/`accountsNoun()` exactly per §5; `usesBusinessVocabulary()` unchanged |
| 16 | `app/Providers/MenuServiceProvider.php` | horizontal-menu fix | Add `'panels.horizontalMenu'` to `View::composer([...])` |
| 17 | `resources/views/customer/settings/advanced/entry.blade.php` | §5 Agency Advanced, post-Chat-A-merge path | Heading/title `Messaging Channels` → `Messaging provider`; `Workspace` → account noun |
| 18 | `resources/views/customer/settings/advanced/index.blade.php` | same | Heading/title → `Messaging provider`; `Sender IDs` section heading → `Sender identities` |
| 19 | `resources/views/customer/settings/advanced/show.blade.php` | same | `Sender IDs` section heading → `Sender identities` |
| 20 | `app/Library/Navigation/CustomerMenuBuilder.php` | §5a nav item | Line 224: label `'Sender IDs'` → `'Sender identities'` only |
| 21 | `resources/views/customer/SenderID/index.blade.php` | §5b destination, lines 3, 57 | Repoint from `locale.menu.Sender ID` to `locale.menu.Sender identities` |
| 22 | `resources/views/customer/SenderID/request_new.blade.php` | §2 correction, line 36 | Repoint from `locale.menu.Sender ID` to `locale.labels.sender_identity` (singular, corrected in this pass) |
| 23 | `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | §1, **only after Chat A merges** | Lines 77, 78, 84, 85, 86: five `label` values only, exactly as listed in §1 |
| 24 | `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | §1, **only after Chat A merges** | Lines 44, 45, 51, 52, 53: five `label` values only, exactly as listed in §1 |

### 7b. Slice 1B — 27 paths (all Group A: shared `locale.labels.{sending_server,originator,sender_id}` keys, repointed per §2/§6)

| # | Path | Exact lines | Repoint |
|---|---|---|---|
| 25 | `resources/views/customer/Outreach/_originator.blade.php` | 13, 31, 35, 60, 83 | `sending_server`→`messaging_provider` (13); `originator`→`sender_identity` (31); `sender_id`→`sender_identity` (35, 60, 83) |
| 26 | `resources/views/customer/Campaigns/campaignBuilder.blade.php` | 70, 91, 96, 129, 166 | same pattern |
| 27 | `resources/views/customer/Campaigns/import.blade.php` | 77, 98, 103, 136, 173 | same |
| 28 | `resources/views/customer/Campaigns/mmsCampaignBuilder.blade.php` | 67, 87, 92, 125, 162 | same |
| 29 | `resources/views/customer/Campaigns/mmsImport.blade.php` | 76, 97, 102, 135, 172 | same |
| 30 | `resources/views/customer/Campaigns/mmsQuickSend.blade.php` | 60, 82, 88, 122, 160 | same |
| 31 | `resources/views/customer/Campaigns/otpCampaignBuilder.blade.php` | 67, 87, 92, 125, 162 | same |
| 32 | `resources/views/customer/Campaigns/otpImport.blade.php` | 77, 98, 103, 136, 173 | same |
| 33 | `resources/views/customer/Campaigns/otpQuickSend.blade.php` | 59, 80, 86, 120, 158 | same |
| 34 | `resources/views/customer/Campaigns/quickSend.blade.php` | 64, 84, 90, 124, 162 | same |
| 35 | `resources/views/customer/Campaigns/updateCampaignBuilder.blade.php` | 63, 84, 90, 117, 142 | same |
| 36 | `resources/views/customer/Campaigns/viberCampaignBuilder.blade.php` | 67, 87, 92, 125, 162 | same |
| 37 | `resources/views/customer/Campaigns/viberImport.blade.php` | 76, 97, 102, 135, 172 | same |
| 38 | `resources/views/customer/Campaigns/viberQuickSend.blade.php` | 59, 80, 86, 120, 158 | same |
| 39 | `resources/views/customer/Campaigns/voiceCampaignBuilder.blade.php` | 67, 88, 93, 126, 163 | same |
| 40 | `resources/views/customer/Campaigns/voiceImport.blade.php` | 72, 93, 98, 131, 168 | same |
| 41 | `resources/views/customer/Campaigns/voiceQuickSend.blade.php` | 61, 81, 87, 121, 159 | same |
| 42 | `resources/views/customer/Campaigns/whatsAppCampaignBuilder.blade.php` | 67, 87, 92, 125, 162 | same |
| 43 | `resources/views/customer/Campaigns/whatsAppImport.blade.php` | 76, 97, 102, 135, 172 | same |
| 44 | `resources/views/customer/Campaigns/whatsAppQuickSend.blade.php` | 60, 81, 87, 121, 159 | same |
| 45 | `resources/views/customer/ChatBox/new.blade.php` | 44, 65 | `sending_server`→`messaging_provider` (44); `originator`→`sender_identity` (65) |
| 46 | `resources/views/customer/Developers/settings.blade.php` | 26 | `sending_server`→`messaging_provider` |
| 47 | `resources/views/customer/SenderID/checkout.blade.php` | 44, 87 | `sender_id`→`sender_identity` |
| 48 | `resources/views/customer/Templates/create.blade.php` | 107 | `sender_id`→`sender_identity` |
| 49 | `resources/views/customer/contactGroups/_settings.blade.php` | 24, 30, 79 | `originator`→`sender_identity` (24); `sender_id`→`sender_identity` (30, 79) |
| 50 | `resources/views/customer/keywords/create.blade.php` | 65, 71, 117 | `originator`→`sender_identity` (65); `sender_id`→`sender_identity` (71, 117) |
| 51 | `resources/views/customer/keywords/show.blade.php` | 62, 68, 114 | `originator`→`sender_identity` (62); `sender_id`→`sender_identity` (68, 114) |

**Total: 51 production paths.** Not preserved from any prior pass's count.

### 7c. Provably customer-unreachable — proof recorded, no allowlist entry, no edit

- `resources/views/customer/SendingServer/{create,index,list}.blade.php` — contain `Sub Account`, `Sub Account Password`, `Sending Servers`, `Auth Token`, `API Key`, `Promo Sender ID`. **Proof, re-confirmed at the merged HEAD**: `app/Http/Controllers/Customer/SendingServerController.php` does not exist in this repository; the only `SendingServerController` is `Admin\SendingServerController`, which renders only `admin.SendingServer.*` views; no route in `routes/customer.php` reaches these three files; no other controller returns them. Dead, orphaned, unreachable by every customer role.

### 7d. Newly discovered, not fixed, not deferred silently — reported per §3

- The Sub-Accounts feature's own identity (`locale.php:701,2178-2198`; `panels/navbar.blade.php:348-350`; `customer/SubAccounts/{index,create,show}.blade.php`) — genuinely reachable, not closed by this contract, per §3's reasoning. Not counted in §7a/§7b's 51.

---

## 8. Prohibited scope after these decisions

Still fully prohibited: `database/migrations/**`, `routes/**`, `resources/views/admin/**`, provider runtime behavior, `DLRController`, `SendingServer` persistence/model, Campaign repositories, Outreach orchestration, billing internals, tenancy, permissions, feature-entitlement behavior, Website/GBP/B5 implementation, mobile/responsive redesign, ChatBox tenancy, schema, API/provider calls, secret handling, `config/**`, `public/**`, `package*.json`, `composer*.json`, `resources/views/layouts/**`, `resources/scss/**`.

**The controller prohibition now has exactly two exceptions, not a wildcard**: `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` and `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` — and only for the five `label` string values named in §1, only after Chat A's merge. `app/Http/Controllers/**` remains otherwise fully prohibited; no other controller, and no other line in these two controllers, is authorized.

---

## 9. Test map — absolute, with explicit new coverage

| Test | Exact assertion (parent §17) | Coverage |
|---|---|---|
| **T-TERM-1** | Zero rendered customer `Workspace`/`Workspaces`, across Core, Growth, Agency owner, Agency admin, Agency selected staff, Business frame, Account frame, multi-account unselected chooser, Agency Prospecting, horizontal layout | Closed by §7a items #2–13, #17, with §5's three-case `CustomerContext` covering the previously-open unselected-chooser case |
| **T-TERM-2** | Zero rendered customer `Sending Server`, `Sender ID`, `Originator`, `Sub Account`, `Account SID`, `Auth Token`, `API Key` | Closed by §7a items #18–24 and all of §7b — no reachable-but-deferred exception remains for any of these terms **except** the newly-discovered Sub-Accounts identity (§3/§7d, explicitly named, not silently accepted) |
| **T-I18N-3** | No rendered response, customer or admin, contains a string matching `locale.` | The 17 keys in §6 close every currently-reachable raw-key leak found by this and the prior correction |
| **T-NAV-4** | Every emitted customer nav URL resolves to a registered route | Unaffected — no route changed anywhere in §7a/§7b |

**Explicit new coverage required**, per this correction:
- Legacy Outreach compose screen (`_originator.blade.php`) and every reachable Campaign builder/quick-send/import screen across all six channels (§7b #26–44) — including MMS/Voice/WhatsApp/Viber/OTP, which are permission-gated `default => false` but genuinely grantable, hence reachable, not unreachable.
- Templates (`Templates/create.blade.php`), ChatBox (`ChatBox/new.blade.php`), Keywords (`keywords/{create,show}.blade.php`), ContactGroup settings (`contactGroups/_settings.blade.php`) — all confirmed reachable, all in §7b.
- Sender-identity landing/request screens (`SenderID/{index,request_new,checkout}.blade.php`).
- Agency Advanced provider screen and Agency Prospecting provider screen — both provider-credential forms: assert visible labels match §1's five new strings **and** posted field names remain exactly `account_sid`/`auth_token`/`api_key`/`c1`/`c2`.
- Horizontal legacy menu: the mechanical render regression specified in Correction 1 §10 (a feature test, not a comment) proving `Helper::menuData()['customer']` is unreachable through `panels.horizontalMenu` after §7a #13.

No route, schema, or provider-behavior test is added or changed.

---

## 10. Human decision gates — resolved and removed

All three decisions this correction was asked to resolve are resolved and applied, and are no longer stop conditions:
1. Provider credential labels — §1, applied, gated only on Chat A's merge timing (a scheduling dependency, not an open decision).
2. Legacy Outreach/Campaign terminology — §2, applied, fully enumerated in §7b.
3. Multi-workspace-unselected vocabulary — §5, applied, `CustomerContext` has no open branch.

**Remaining, legitimate, generic implementation stop rules** (not the three resolved items, and not new product decisions — ordinary re-verification discipline for a contract that waits on another lane's merge):
- Re-verify §1's exact controller line numbers and §7a #17-19's exact blade paths against the actual Chat A merge SHA before editing (Chat A is mid-Security-Correction-37; content may shift before it merges).
- Re-confirm `showsWorkspaceVocabulary()`'s zero-caller proof and `usesBusinessVocabulary()`'s Core/Growth-only semantic at the implementation SHA before touching `CustomerContext.php`.
- A forbidden term cannot be replaced without changing a route name (the parent's own Slice 1 stop condition — not triggered by any item in §7a/§7b).

**One new, additional, explicitly-not-resolved item** (§3/§7d): the Sub-Accounts feature's own name. This is reported, not silently deferred, and is not counted among the three gates this correction removed.

---

## 11. Dependency

- Theme prerequisite: satisfied.
- Slice 2A contract: merged via PR #237 (docs only; its own implementation has not started and may not start before Slice 1A **and** 1B both merge and pass T-TERM-1/T-TERM-2, since 2A's contract itself names Slice 1 as prerequisite and edits the same files).
- Chat A: still not merged; current observed head `122f330`.
- **Slice 1A may implement and merge independently of Chat A** (none of its 22 non-controller paths depend on Chat A's content; only §7a #23-24 wait on the merge).
- **Slice 1B may implement and merge independently of Chat A entirely** (none of its 27 paths touch Chat A's surface).
- §7a #23-24 (the two controller label changes) must wait for Chat A's actual merge commit into `origin/main`; at that time, re-sweep both controllers and the three Agency Advanced Blade paths (§7a #17-19) fresh before applying any copy change, since Chat A is actively changing under Security Correction 37.
- This contract document may merge before Chat A, being docs-only.

---

## 12. Consistency note

This correction supersedes Correction 1's §3/§6/§7/§9/§11/§12 in full. Every count in this document (17 locale keys, 51 production paths, 24+27 split) is freshly derived in this pass and is not preserved from "15," "22," or any other prior number for the sake of consistency.

---

## 13. Completion gate

Slice 1 (both 1A and 1B) is complete when:
- Every path in §7a and §7b is mutated exactly as specified, and §7a #23-24 have been re-verified against Chat A's actual merged content before being applied.
- Zero occurrences of `Workspace`, `Workspaces`, `Sub Account`, `Sub Accounts` (except the explicitly-named, unresolved §3/§7d item), `Sending Server`, `Sending Servers`, `Sender ID`, `Sender IDs`, `Originator`, `Originators`, `Account SID`, `Auth Token`, `API Key`, `Messaging Channels` remain in rendered customer copy across every role/tier/context in §9's T-TERM-1/T-TERM-2 rows.
- All 17 locale keys in §6 resolve; no fake key added.
- The horizontal-menu mechanical render regression (§9) passes.
- `showsWorkspaceVocabulary()` no longer exists.
- No prohibited path (§8) was touched beyond the two named, narrow controller exceptions.
- T-TERM-1, T-TERM-2, T-I18N-3, T-NAV-4 all pass, plus the explicit new coverage in §9.
- The §3/§7d Sub-Accounts discovery has been put to a human/ChatGPT decision (a fourth decision, distinct from the three this correction resolved) — Slice 1 does not silently ship with it unresolved and unreported.
- Human/ChatGPT review accepts this final correction.

---

*End of Correction 2 — final contract correction. No implementation, migration, route change, or test was performed by this document.*
