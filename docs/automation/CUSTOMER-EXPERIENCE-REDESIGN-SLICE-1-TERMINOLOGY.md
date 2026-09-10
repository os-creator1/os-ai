# Customer Experience Redesign — Slice 1: Terminology + Customer Shell Implementation Contract

**Status: CONTRACT ONLY.** No product code, migration, route, or test change is made or authorized by this document. This is Correction 3 — the final contract correction. All four terminology decisions this contract required are now resolved. There is no unresolved product decision remaining in this document.

---

## 0. Verified base and inspected heads

```
contract_type: implementation_contract
docs_only: true
implementation_authorized_by_this_document: false
correction: 3
unresolved_product_decisions: 0
```

- **Starting HEAD of this correction**: `8afb871056f4db0c95447185b7f482fd84d2d38e` (Correction 2).
- **`origin/main` at issuance of this correction**: `634ff2b0d4840ecb4cdd1304d8083647cfcd16b9`. Confirmed via `git diff 823448994c2586d3818ad8333088e4976bcbc309..origin/main --stat`: **exactly one file**, `docs/automation/LEGACY-PROVIDER-WEBHOOK-MEASUREMENT-CONTRACT.md` (590 insertions, new file, PR #238) — a contract-only, documentation-only sibling, no production code, unrelated to Slice 1's paths.
- **Merge performed**: `git merge origin/main --no-edit`, normal merge (not rebase), resulting merge commit `7d167d3aaafc307d558cde377added5f30a61773`. Clean, no conflicts, no production file touched.
- **Chat A remote head, refreshed**: `bc5954d2390d5693192ef3aceb956d29b3aef9a4` ("fix(messaging): close adversarial webhook review findings"), superseding the now-stale `122f3301f235dad35c1e457afdfa8d5ca097bb95` recorded in Correction 2. Inspected read-only via a fresh detached worktree (never checked out into this branch's own worktree, no Chat A code consumed or merged): `git diff 122f330..bc5954d --stat` for every Slice-1-relevant path (`MessagingChannelsController.php`, `AgencyProspectingChannelController.php`, `resources/views/customer/settings/advanced/**`, `CustomerMenuBuilder.php`, `routes/customer.php`, `config/customer-permissions.php`, `resources/lang/en/locale.php`) returns **zero changes** — Chat A's newest commit is unrelated to Slice 1's dependencies. Implementation remains gated on Chat A's eventual merge into `origin/main`, unchanged.

---

## 1. Chat A refresh — mechanical re-check at `bc5954d`

Both credential arrays are byte-identical to what Correction 2 read at `122f330`, confirmed at the exact same line numbers:

| File | Line | Field key | Current label |
|---|---|---|---|
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | 77 | `account_sid` | `Account SID` |
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | 78 | `auth_token` | `Auth Token` |
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | 84 | `api_key` | `API Key` |
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | 85 | `c1` | `Message Profile ID` |
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | 86 | `c2` | `Message Connection ID` |
| `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | 44 | `account_sid` | `Account SID` |
| `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | 45 | `auth_token` | `Auth Token` |
| `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | 51 | `api_key` | `API Key` |
| `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | 52 | `c1` | `Message Profile ID` |
| `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | 53 | `c2` | `Message Connection ID` |

All five field keys (`account_sid`, `auth_token`, `api_key`, `c1`, `c2`) still exist, unchanged, still nested under each controller's `ALLOWED_PROVIDERS[...]['credential_fields']` array. §2's plan (below, unchanged from Correction 2 — this document keeps calling it §2 for the provider-label decision to match prior numbering continuity in spirit, but see the renumbered section) still applies verbatim.

`resources/views/customer/settings/advanced/{entry,index,show,connect}.blade.php` are confirmed present and, via `git diff 403c5f8..bc5954d --stat` for that directory, **unchanged across Chat A's entire commit history since the file was first introduced** — the exact strings Correction 1/2 read remain accurate.

No Chat A code was consumed, checked out, or merged by this inspection.

---

## 2. Provider credential labels (Decision 1 — resolved in Correction 2, re-confirmed here)

Unchanged from Correction 2, re-verified fresh against `bc5954d` in §1 above. Slice 1 may change exactly five `label` values in exactly two controller files, only after Chat A's final merge into `origin/main`:

| File | Line | New value |
|---|---|---|
| `MessagingChannelsController.php` / `AgencyProspectingChannelController.php` | `account_sid` line | `Twilio account identifier` |
| same | `auth_token` line | `Twilio secret` |
| same | `api_key` line | `Telnyx access key` |
| same | `c1` line | `Messaging profile ID` |
| same | `c2` line | `Messaging connection ID` |

Array keys, `required` flags, validation, persistence, authorization, routes, and provider behavior are untouched. Test requirement unchanged: rendered `<label>` text changes; posted field names (`account_sid`, `auth_token`, `api_key`, `c1`, `c2`) do not.

---

## 3. Legacy Outreach/Campaign terminology (Decision 2 — resolved in Correction 2, unchanged)

Unchanged from Correction 2. 27 files (§8b) repointed from the three admin-shared keys (`locale.labels.sending_server`, `originator`, `sender_id`) to two new customer-only keys (`locale.labels.messaging_provider` = `Messaging provider`, `locale.labels.sender_identity` = `Sender identity`). No semantic divergence found on re-check; no admin value touched.

---

## 4. Delegated-access (legacy Sub-Accounts) terminology — Decision 4, now RESOLVED

**Customer-facing noun, authoritative**: singular **Team member**, plural **Team members**. This is the terminology for the surviving legacy delegated-access surface until its separately-contracted migration into Workspace membership retires it.

**Why**: the parent contract forbids "sub-account"/"sub accounts" in customer copy and separately locks Workspace/member-facing human vocabulary to "Team member"; the legacy feature is semantically delegated staff access built on `users.parent_id`; "Team member" names the human role without claiming the underlying persistence has migrated; "Client account" is unavailable (§ terminology matrix already assigns it to an Agency's Business); "Account" does not name a person. No new tenancy level is introduced.

**This is terminology only.** It does not mean `users.parent_id` was migrated, `WorkspaceMembership` rows were created, authorization changed, routes changed, controller/model/repository/class names changed, or the legacy feature was redesigned. Retention is unchanged from the retention audit's §12.3 resolution: migrate delegated access into Workspace membership later, then retire the legacy Sub-Accounts surface. Slice 1 does not accelerate or implement that migration.

### 4a. Exact mechanical count — corrected

Correction 2's "13 distinct string values" was not mechanically derived and is withdrawn. The complete, actually-read set at current `origin/main`:

- `resources/lang/en/locale.php:701` — `labels.sub_accounts` — **1 key**, standalone (not part of the block below).
- `resources/lang/en/locale.php:2178-2204` — the complete `'sub_accounts' => [...]` block — **24 keys**: `add_new`, `add_password`, `send_invitation`, `accept_invitation`, `active_account`, `update_sub_account`, `sub_account_added`, `sub_account_updated`, `sub_account_deleted`, `sub_account_status_updated`, `enable_selected_sub_accounts`, `disable_selected_sub_accounts`, `delete_selected_sub_accounts`, `sub_accounts_enabled`, `sub_accounts_disabled`, `sub_accounts_deleted`, `invitation.subject`, `invitation.body`, `invitation.footer`, `sub_account_activated`, `accept_invitation_description`, `manage_account`, `login_as_parent`, `login_as_parent_message`.

**Total existing keys in the Sub-Accounts locale universe: 25** (1 + 24). Of these, **16 values render a forbidden noun and change**; **9 values already contain no forbidden noun and are left exactly as-is** (no style rewrite): `add_password`, `send_invitation`, `accept_invitation`, `active_account`, `invitation.footer`, `sub_account_activated`, `accept_invitation_description`, `manage_account`, `login_as_parent`.

### 4b. Exact copy policy — internal keys unchanged, only English values change

Locale **keys** are never renamed (`labels.sub_accounts`, `sub_accounts.add_new`, `sub_accounts.update_sub_account`, etc. all survive unchanged — they are internal identifiers, not customer-facing copy). Only the 16 English **values** below change:

| Key | Current value | New value |
|---|---|---|
| `labels.sub_accounts` (line 701) | `Sub Accounts` | `Team members` |
| `sub_accounts.add_new` | `Add New Sub Account` | `Add team member` |
| `sub_accounts.update_sub_account` | `Update Sub Account` | `Update team member` |
| `sub_accounts.sub_account_added` | `Sub account successfully added` | `Team member successfully added` |
| `sub_accounts.sub_account_updated` | `Sub account successfully updated` | `Team member successfully updated` |
| `sub_accounts.sub_account_deleted` | `Sub account successfully deleted` | `Team member successfully deleted` |
| `sub_accounts.sub_account_status_updated` | `Sub account status successfully updated` | `Team member status successfully updated` |
| `sub_accounts.enable_selected_sub_accounts` | `Are you sure to enable selected sub accounts?` | `Are you sure you want to enable the selected team members?` |
| `sub_accounts.disable_selected_sub_accounts` | `Are you sure to disable selected sub accounts?` | `Are you sure you want to disable the selected team members?` |
| `sub_accounts.delete_selected_sub_accounts` | `Are you sure to delete selected sub accounts?` | `Are you sure you want to delete the selected team members?` |
| `sub_accounts.sub_accounts_enabled` | `Selected sub accounts enabled` | `Selected team members enabled` |
| `sub_accounts.sub_accounts_disabled` | `Selected sub accounts disabled` | `Selected team members disabled` |
| `sub_accounts.sub_accounts_deleted` | `Selected sub accounts deleted` | `Selected team members deleted` |
| `sub_accounts.invitation.subject` | `You are invited to join as a Sub-Account on :app_name` | `You are invited to join :app_name as a team member` |
| `sub_accounts.invitation.body` | `You have been invited to join as a sub-account. Please click the button below to accept the invitation and set up your password` | `You have been invited to join as a team member. Please click the button below to accept the invitation and set up your password.` |
| `sub_accounts.login_as_parent_message` | `You are currently logged in as a sub-account. You can manage the main account below.` | `You are currently logged in as a team member. You can manage the main account below.` |

**16 existing locale VALUES change. Zero new locale keys are added for this decision** — the keys already exist; only English text is rewritten in place.

### 4c. Login-as-parent — mechanical disposition

`sub_accounts.login_as_parent` = `Login as Parent` contains no forbidden noun and is left unchanged — it names the banner action, not the delegated-access noun. `sub_accounts.login_as_parent_message` does contain the forbidden noun and is corrected per §4b. Neither the internal `parent_id` column, the `login_as_parent` key name, nor `user.account.login_as` route/mechanic is touched — those survive until the later retention migration owns them.

### 4d. Consumers — confirmed closed entirely through `locale.php`, no new production path

Every consumer of these 25 keys was enumerated and read in full:

- `resources/views/panels/navbar.blade.php:348-350` (persistent dropdown item)
- `resources/views/customer/SubAccounts/{index,create,show}.blade.php`
- `resources/views/customer/dashboard.blade.php:74` (the `login_as_parent` banner line, a consumer not previously catalogued)
- `resources/views/emails/subaccount/sub_account_invitation.blade.php` (the invitation email itself, not previously catalogued)
- `resources/views/auth/subAccount/acceptInvitation.blade.php` (the public accept-invitation page, not previously catalogued)
- `app/Http/Controllers/Customer/SubAccountController.php` (breadcrumb `name` values only, sourced from the same keys)
- `app/Mail/SubAccountInvitation.php` (points at the email Blade above; carries no literal string of its own)

**Mechanically confirmed: every one of these files consumes the forbidden noun exclusively through `__('locale.sub_accounts.*')` or `__('locale.labels.sub_accounts')` calls.** A full case-insensitive grep for `sub.account` across all seven files, excluding lines that are `locale.` translation calls, route/URL/class/namespace identifiers, found exactly one hit: a Blade **comment** in `SubAccounts/show.blade.php:120` (`{{-- Check the box if the sub-account has this permission --}}`), which is stripped at compile time and never rendered to the browser — not a customer-visible leak, no action needed. **No hardcoded, customer-visible forbidden term exists outside `resources/lang/en/locale.php` for this feature.** Correcting the 16 values in §4b closes every one of these seven consumers with no additional file touched, and no allowlist path is added — `resources/lang/en/locale.php` was already Slice 1A path #1.

---

## 5. Locale strategy — final counts, not conflated

- **New locale keys added** (Correction 2, unchanged by this correction): **17** (11 `menu` + 4 `permission` + 2 `labels`: `messaging_provider`, `sender_identity`).
- **Existing locale values changed** (this correction, §4b): **16**, all inside the already-existing `sub_accounts` block plus `labels.sub_accounts`. This is a distinct number from the 17 above and is not added to it — no new key is created for this decision.

---

## 6. Exact implementation allowlist — unchanged count, one mutation description updated

The full sweep confirms §4d's finding: no path is added or removed. **Slice 1A remains 24 paths, Slice 1B remains 27 paths, total 51** — the same total as Correction 2, not increased for this decision.

### 6a. Slice 1A — 24 paths (§7a #1 updated; all other 23 unchanged from Correction 2)

| # | Path | Exact permitted mutation |
|---|---|---|
| 1 | `resources/lang/en/locale.php` | Add the 17 new keys (§5); **change exactly the 16 existing `sub_accounts`/`labels.sub_accounts` values in §4b, and no other existing value** — no unrelated locale cleanup. |
| 2 | `resources/views/customer/Automations/entry.blade.php` | Line 15: `Workspace` → account noun |
| 3 | `resources/views/customer/business/analytics/entry.blade.php` | Line 21: same |
| 4 | `resources/views/customer/business/analytics/overview.blade.php` | Line 25: same |
| 5 | `resources/views/customer/business/website/entry.blade.php` | Line 15: same |
| 6 | `resources/views/customer/Outreach/entry.blade.php` | Line 15: same |
| 7 | `resources/views/customer/business/googleBusinessProfile/entry.blade.php` | Line 21: same |
| 8 | `resources/views/customer/workspace/additional-business-slots/show.blade.php` | Lines 11, 38: same |
| 9 | `resources/views/customer/workspaces/prospecting/entry.blade.php` | Lines 14, 15, 19: same |
| 10 | `resources/views/customer/workspaces/prospecting/channels/index.blade.php` | Line 9: same |
| 11 | `resources/views/customer/workspaces/index.blade.php` | Lines 11–14: `accountNoun()`/`accountsNoun()`, all three cases (Core/Growth, Agency, unselected) |
| 12 | `resources/views/customer/workspaces/show.blade.php` | Lines 11–16: same |
| 13 | `resources/views/panels/horizontalMenu.blade.php` | Customer branch consumes `CustomerMenuBuilder` via `CustomerShellComposer` |
| 14 | `app/Helpers/Helper.php` | No deletion; annotate unreachability of the customer branch after #13 |
| 15 | `app/Library/Navigation/CustomerContext.php` | Delete `showsWorkspaceVocabulary()`; add `accountNoun()`/`accountsNoun()` (three-case, §5 of Correction 2); `usesBusinessVocabulary()` unchanged |
| 16 | `app/Providers/MenuServiceProvider.php` | Add `'panels.horizontalMenu'` to `View::composer([...])` |
| 17 | `resources/views/customer/settings/advanced/entry.blade.php` | Post-Chat-A-merge: `Messaging Channels` → `Messaging provider`; `Workspace` → account noun |
| 18 | `resources/views/customer/settings/advanced/index.blade.php` | Post-Chat-A-merge: same heading fix; `Sender IDs` → `Sender identities` |
| 19 | `resources/views/customer/settings/advanced/show.blade.php` | Post-Chat-A-merge: `Sender IDs` → `Sender identities` |
| 20 | `app/Library/Navigation/CustomerMenuBuilder.php` | Line 224: label `'Sender IDs'` → `'Sender identities'` only |
| 21 | `resources/views/customer/SenderID/index.blade.php` | Lines 3, 57: repoint to `locale.menu.Sender identities` |
| 22 | `resources/views/customer/SenderID/request_new.blade.php` | Line 36: repoint to `locale.labels.sender_identity` (singular) |
| 23 | `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | Post-Chat-A-merge only: 5 label values per §2 |
| 24 | `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php` | Post-Chat-A-merge only: 5 label values per §2 |

### 6b. Slice 1B — 27 paths — unchanged from Correction 2

The 27 legacy Outreach/Campaign/Template/ChatBox/Keyword/ContactGroup/SenderID-checkout/Developers files repointed to `locale.labels.messaging_provider`/`sender_identity` per §3, exactly as enumerated in Correction 2 §7b — no path added, none removed, no line changed.

### 6c. Provably customer-unreachable — unchanged

`resources/views/customer/SendingServer/{create,index,list}.blade.php` — no controller/route serves them (re-confirmed, no diff since Correction 1/2). Dead, unreachable, not edited, not counted.

**No §7d. There is no newly-discovered-but-unresolved item remaining.** The Sub-Accounts finding from Correction 2 is fully resolved in §4 above and closed entirely inside allowlist path #1.

---

## 7. Prohibited scope — unchanged

`database/migrations/**`, `routes/**`, `resources/views/admin/**`, provider runtime behavior, `DLRController`, `SendingServer` persistence/model, Campaign repositories, Outreach orchestration, billing internals, tenancy, permissions, feature-entitlement behavior, Website/GBP/B5 implementation, mobile/responsive redesign, ChatBox tenancy, schema, API/provider calls, secret handling, `config/**`, `public/**`, `package*.json`, `composer*.json`, `resources/views/layouts/**`, `resources/scss/**`. The controller prohibition has exactly two named exceptions (§2), and only for the five listed `label` values, only after Chat A's merge. `SubAccountController.php` and every other Sub-Accounts controller/model/route/class are untouched by §4 — that decision is a locale-value-only change.

---

## 8. Test map — T-TERM-2 fully absolute, no carve-out

| Test | Assertion (parent §17) | Coverage |
|---|---|---|
| **T-TERM-1** | Zero rendered customer `Workspace`/`Workspaces`, across every named role/context including the unselected chooser and Agency Prospecting | Closed by §6a #2–13, #17 |
| **T-TERM-2** | Zero rendered customer `Sending Server`, `Sender ID`, `Originator`, `Sub Account`, `Account SID`, `Auth Token`, `API Key` — **absolute, no reachable-customer-screen exception of any kind** | Closed by §6a #18–24, all of §6b, **and now §4's delegated-access surface** — no term in this row has an open exception anywhere in this contract |
| **T-I18N-3** | No rendered response contains a string matching `locale.` | The 17 new keys (§5) plus the 16 corrected values (§4b) |
| **T-NAV-4** | Every emitted customer nav URL resolves to a registered route | Unaffected |

**Explicit new coverage for the delegated-access surface** (§4d's seven consumers), at minimum:
- `panels/navbar.blade.php` dropdown entry renders `Team members`, never `Sub Account(s)`.
- `SubAccounts/index.blade.php`: page title, table state, and every JS confirm-dialog string (enable/disable/delete, singular and batch) render the `Team member(s)` wording; none render `Sub Account`/`Sub-Account`/`sub account`/`sub-account` in any case.
- `SubAccounts/create.blade.php`, `show.blade.php`: same assertion for form headings and per-permission-category labels.
- `emails/subaccount/sub_account_invitation.blade.php`: rendered subject and body match the corrected `invitation.subject`/`invitation.body` values.
- `auth/subAccount/acceptInvitation.blade.php`: renders no forbidden noun.
- `customer/dashboard.blade.php`: the `login_as_parent_message` banner renders `Team member`, never `sub-account`.
- **Do not assert** that internal route names (`customer.sub_accounts.*`), the `SubAccountController`/`SubAccount` class names, or the locale **key** names (`sub_accounts.*`, `labels.sub_accounts`) are free of `sub_account` — those are implementation identifiers, intentionally unchanged, and survive until the later retention migration.

Horizontal legacy menu unreachability: the mechanical render regression from Correction 1 §10, unchanged. T-I18N-3 exhaustive per above. T-NAV-4 unchanged. No route, schema, or provider-behavior change anywhere in this contract.

---

## 9. Completion gate — closed, not an open decision

Slice 1 (1A and 1B) is complete when:
- Every path in §6a and §6b is mutated exactly as specified, with §6a #23-24 re-verified against Chat A's actual merged content immediately before being applied.
- Zero occurrences, anywhere in rendered customer copy, of `Workspace`, `Workspaces`, `Sub Account`, `Sub Accounts`, `Sub-Account`, `Sub-Accounts`, `Sending Server`, `Sending Servers`, `Sender ID`, `Sender IDs`, `Originator`, `Originators`, `Account SID`, `Auth Token`, `API Key`, `Messaging Channels` — **with no exception, deferred item, or open naming decision of any kind**.
- The legacy delegated-access surface renders `Team member`/`Team members` wherever it previously rendered the forbidden noun; internal route/class/locale-key identifiers may remain unchanged; the Workspace-membership migration remains a separately-owned, later contract.
- All 17 new locale keys resolve; the 16 corrected values render as specified in §4b; no other existing locale value is touched.
- The horizontal-menu mechanical render regression passes; `showsWorkspaceVocabulary()` no longer exists.
- No prohibited path (§7) was touched beyond the two named, narrow controller exceptions.
- T-TERM-1, T-TERM-2 (fully absolute), T-I18N-3, T-NAV-4 all pass, including the explicit delegated-access coverage in §8.
- Human/ChatGPT review accepts this correction. **After acceptance, zero product decisions remain open in this contract.**

---

## 10. Dependency — unchanged

Theme prerequisite satisfied. Slice 2A contract merged (PR #237), its own implementation still waits on Slice 1A **and** 1B. Chat A not yet merged; newest observed head `bc5954d`, re-confirmed unrelated-to-Slice-1 since `122f330`. Slice 1A's 22 non-controller paths and all 27 of Slice 1B may implement and merge independently of Chat A; only §6a #23-24 wait for Chat A's actual merge commit, at which point §1/§2's evidence must be re-verified fresh (Chat A may still be mid-correction) before applying. This contract document may merge before Chat A, being docs-only.

---

## 11. Final story, stated once, plainly

All four terminology decisions this contract required are resolved: provider credential labels (§2), legacy Outreach/Campaign copy (§3), multi-workspace-unselected vocabulary (Correction 2 §5, unchanged), and delegated-access naming (§4, this correction). There are **17 new locale keys**, **51 production paths** (**24 in Slice 1A, 27 in Slice 1B**), and **16 existing locale values corrected** inside the already-counted `resources/lang/en/locale.php` (Slice 1A path #1) — a distinct number from the 17 new keys, not conflated with it. The delegated-access surface's visible noun is `Team member`/`Team members`; its legacy mechanism, routes, classes, and locale key names are untouched and remain until a separately-contracted Workspace-membership migration retires them. Chat A's dependency is pinned to the newest inspected head, `bc5954d`. `origin/main` is refreshed to `634ff2b`. No unresolved product decision remains in this document.

---

*End of Correction 3 — final contract correction. No implementation, migration, route change, or test was performed by this document.*
