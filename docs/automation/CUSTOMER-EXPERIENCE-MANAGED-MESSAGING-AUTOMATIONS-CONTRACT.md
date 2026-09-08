# CUSTOMER EXPERIENCE, ACCOUNT CONTEXT, MANAGED MESSAGING, BILLING AND GUIDED AUTOMATIONS — IMPLEMENTATION CONTRACT

## 1. STATUS AND AUTHORITY

**Status:** Contract only. No product code is authorized by this document.

**Verified base:** `origin/main` at `7d235cfb3554116c1088b7da57389e202ed24050`
(`Merge pull request #214 from os-creator1/agent/google-business-profile-slice-a`),
fetched with `git fetch origin --prune`. Every path, symbol and line number cited
below was read at that commit.

**Merge-history evidence** (`git log --oneline --merges origin/main`):

| Required area | Merge evidence | Representative path |
|---|---|---|
| RFC-005 usage billing and wallets | PRs #176–#179 lineage, present at base | `app/Library/Usage/UsageWalletManager.php` |
| Business messaging / channels | `7921fd8` PR #201 `agent/b2-business-messaging-channels` | `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` |
| Automations | `ff9c9d5` PR #207 `agent/b4-business-automations` | `app/Enums/Automation/AutomationTriggerType.php` |
| Design system / customer shell | `8a2af9f` PR #197 and the M2 lineage | `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` |
| Website Guided Generation contract + Slice 1 | `5bf9ca2` PR #212, `9024947` PR #213 | `docs/automation/WEBSITE-GUIDED-GENERATION-CONTRACT.md`, `app/Models/BusinessKnowledgeProfile.php` |
| Google Business Profile Slice A | `7d235cf` PR #214 | `docs/automation/GOOGLE-BUSINESS-PROFILE-CONTRACT.md` |

**Authority:** This contract governs customer-visible information architecture,
account context, managed messaging onboarding, telecommunications funding, and
the guided automation experience. Where it corrects an earlier contract, §27
names the correction explicitly. Where repository evidence contradicts a locked
decision given to this lane, §7.1 records the contradiction verbatim rather than
resolving it silently.

**Non-authority:** This document does not change RFC-003 tenancy, RFC-004
entitlement decision semantics, or RFC-005 ledger invariants. It changes what
customers *see* and *are asked to do*, and it adds capability that does not yet
exist.

---

## 2. PROBLEM STATEMENT

AI Business OS is technically substantial and experientially incoherent. The
platform has a correct multi-tenant model, a real usage ledger, a real
entitlement engine, and a real automation execution engine — presented through
an inherited Ultimate SMS shell that exposes the *implementation* to the
customer instead of the *outcome*.

The sixteen owner-observed problems are not a CSS list. They are four
information-architecture failures:

**F-1 — The shell belongs to another product.** Login renders a bundled Vuexy
illustration; the customer menu is the inherited SMS-reseller menu with new
items bolted on; six menu labels render as raw translation keys.

**F-2 — Account context is unmodelled in the UI.** Every Business-scoped route
lives under `/workspaces/{workspaceUid}/businesses/{businessUid}/…`, so a
single-Business Growth customer is permanently inside an agency-shaped URL and
an agency-shaped sidebar. The menu is flat and global; the product is
Business-scoped. One menu entry does not resolve to any route at all.

**F-3 — The customer is asked to be the integrator.** Messaging onboarding asks
for Twilio Account SID / Auth Token or Telnyx API Key / Message Profile ID.
Every automation asks the customer to choose channel type, sender ID and sending
server. Usage & Billing asks the customer to type a raw internal feature key.

**F-4 — Outcomes are not offered.** Two automation triggers exist. The
automation builder starts from "When / Then" mechanics, not from "what would you
like to automate". Empty states describe system state, not the next useful
action.

This contract fixes the information architecture. It also fixes two concrete
defects found mechanically during investigation (§3, rows E-11 and E-12).

---

## 3. REPOSITORY EVIDENCE TABLE

Classification legend:
**W** = implemented and working · **UX** = implemented but exposed through poor
UX · **P** = partially implemented · **C** = contracted but not implemented ·
**M** = completely missing.

| # | Capability / claim | Class | Evidence (path:symbol) |
|---|---|---|---|
| E-1 | Workspace / membership / Business tenancy | W | `app/Models/Workspace.php` (`owner()`, `memberships()`, `activeMemberships()`, `businesses()`), `app/Models/WorkspaceMembership.php` (`businessAssignments()`, `assignedBusinesses()`), `app/Library/Workspace/WorkspaceManager.php::userCanAccessBusiness()` |
| E-2 | `BusinessLocation` as a physical place | P | `app/Models/BusinessLocation.php`; `app/Models/Business.php::locations()` is `HasMany` and `primaryLocation()` is `HasOne` — the schema supports many |
| E-3 | Creating a **second** BusinessLocation | **M** | `app/Repositories/Contracts/BusinessLocationRepository.php` exposes only `findPrimary()`, `upsertPrimary()`, `setPrimary()`. `app/Repositories/Eloquent/EloquentBusinessLocationRepository.php::upsertPrimary()` edits the existing primary or creates the first one and demotes all others. The only writer is `app/Http/Controllers/Customer/BusinessOnboardingController.php::storeLocation()`. **No product path creates an additional location.** |
| E-4 | Plan tiers Core/Growth/Agency | W | `app/Enums/Entitlement/WorkspacePlanTier.php` |
| E-5 | Business-slot capacity enforcement | W | `app/Library/Entitlement/EntitlementManager.php::decideBusinessSlotCapacity()` (line 267), reasons `business_slot_allocation_required` / `business_slot_limit_exceeded` (lines 300–303, 322–323) |
| E-6 | Seeded slot numbers | W (but see §7.1) | `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php` — Core and Growth: `business_slot_included = 3`, `business_slot_max = 5`, `additional_business_slot_price_ratio = 0.5000`; Agency: `unlimited_business_slots = true` |
| E-7 | Plan **prices** | **M** | Same migration seeds `'price' => null, 'currency_id' => null` for all three tiers. The `$497` Agency price exists nowhere in the repository. |
| E-8 | Location-count limit enforcement | **M** | No `location_limit`, `max_locations`, `location_slot` symbol exists; no code counts `business->locations()` for entitlement |
| E-9 | Customer navigation | UX | `app/Helpers/Helper.php::menuData()` — customer branch begins line 909. Flat, global, `url('gbp')`, `url('website')`, `url('channels')`, `url('automations')`, `url('outreach')` |
| E-10 | Menu label translation | UX | `resources/views/panels/sidebar.blade.php:82` and `resources/views/panels/submenu.blade.php:23` render `__('locale.menu.'.$menu->name)`. `resources/lang/en/locale.php` `menu` block spans lines 780–901 and contains **no** key for `Website`, `Channels`, `Google Business Profile`, `Prospecting`, `Compose` or `Opportunities` (verified: zero whole-file matches for each). Those six render as literal `locale.menu.*`. |
| E-11 | Menu entry `Outreach → Campaigns` | **defect** | `app/Helpers/Helper.php` customer menu points at `url('outreach/campaigns')`. **No such route exists.** Campaign routes exist only at `routes/customer.php:798-803` (`customer.workspaces.businesses.outreach.campaigns.*`). `routes/customer.php:425-435` records that the legacy flat campaign routes were deliberately removed by B5 with no shim. The sidebar link 404s. |
| E-12 | "Payer updated" on an unchanged payer | **defect** | `app/Library/Usage/BillingProfileManager.php::changePayer()` (line 93) has no equality guard: it always writes a `business_payer_transitions` row (with `from_payer_type === to_payer_type`), always performs the update, and always dispatches `BusinessPayerChanged`. `app/Http/Controllers/Customer/Business/UsageBillingController.php::updatePayer()` unconditionally flashes `'Payer updated.'` |
| E-13 | Payer selector visibility | UX | `resources/views/customer/business/usage-billing/show.blade.php:160-184` renders the Payer card and the `Workspace pays / Business pays` selector with **no tier or role condition** |
| E-14 | Raw feature keys in Usage & Billing | UX | Same view: line 130 `{{ $limit['feature_key'] }}`; line 143 a free-text input labelled `Feature key` with placeholder `e.g. crm`; line 285 `{{ $entry->featureKey ?? '—' }}` |
| E-15 | Wallet reserve / commit / release | W | `app/Library/Usage/UsageWalletManager.php::reserve()` (285), `commit()` (544), `release()` (810), `expireStaleReservations()` (1063) |
| E-16 | Spend cap and feature limits | W | `UsageWalletManager::setSpendCap()` (1184), `setFeatureLimit()` (1225), `setSafetyLimit()` (1293) |
| E-17 | Auto-recharge | P | `UsageWalletManager::configureAutoRecharge()` (1858), `recordAutoRechargeFailure()` (1916); `app/Http/Requests/Customer/Business/ConfigureAutoRechargeRequest.php` accepts free-form `auto_recharge_amount_micro` with `min:1` (one micro-unit). **No `$5/$10/$25/$50` preset set; no `$5` minimum.** `monthly_recharge_cap_micro` exists. |
| E-18 | Minimum top-up | **M** | `app/Http/Requests/Customer/Business/InitiateTopUpRequest.php` — `'amount_micro' => ['required','integer','min:1']`. No `$5` floor. |
| E-19 | Workspace **aggregate** spend cap | **M** | No workspace-level aggregate cap symbol exists in `app/Library/Usage/` |
| E-20 | Platform emergency kill switch | **M** | No `kill_switch` / `emergency` control exists (`app/Library/Log.php::emergency()` is a log level, not a control) |
| E-21 | Provider credential entry by the customer | UX (to be replaced) | `app/Http/Controllers/Customer/Business/MessagingChannelsController.php::ALLOWED_PROVIDERS` (lines 48–63): Twilio `account_sid` "Account SID", `auth_token` "Auth Token"; Telnyx `api_key` "API Key", `c1` "Message Profile ID", `c2` "Message Connection ID" |
| E-22 | Telnyx / Twilio **number provisioning** | **M** | No number search, order or purchase code exists anywhere (`availablePhoneNumbers`, `purchaseNumber`, `searchNumbers`, `number_orders` — zero matches). `app/Models/PhoneNumbers.php` is the inherited admin-assigned number table. |
| E-23 | Registration / compliance (brand, campaign, 10DLC) | **M** | No such model, table or field exists |
| E-24 | Automation triggers | P | `app/Enums/Automation/AutomationTriggerType.php` — exactly **two**: `ContactDateReached`, `ContactCreated` |
| E-25 | Automation actions | P | `app/Enums/Automation/AutomationActionType.php` — exactly **two**: `SendMessage`, `UpdateContactField` |
| E-26 | Automation asks for provider details | UX | `resources/views/customer/Automations/form.blade.php:115-137` renders `sms_type` (Channel type), `sender_id` (Sender) and `sending_server` (Messaging channel) selects. `app/Http/Requests/Automations/AutomationDefinitionRequest.php::rules()` accepts all three. |
| E-27 | Default messaging identity resolution | **M** | `app/Library/Automation/Actions/SendMessageAction.php:49-52` fails closed when `sender_id` is empty or `sending_server` is null — the stored config **must** carry both |
| E-28 | Automation execution idempotency / claim | W | `app/Library/Automation/AutomationExecutionClaimService.php` (`claim()`, `claimStart()`, `idempotency_key` UNIQUE), `app/Library/Automation/AutomationEligibility.php` |
| E-29 | Quiet hours | **M** | No `quiet_hours` symbol exists |
| E-30 | Opt-out / STOP | P | `app/Models/ContactGroupsOptoutKeywords.php`, `app/Models/ContactGroupsOptinKeywords.php`, `app/Library/AgencyProspecting/AgencyProspectStopDetector.php`, `AgencyProspectStopAction.php` — per contact group and inside prospecting; not a Business-wide consent ledger |
| E-31 | Calendar / Booking / Appointment | **M** | Zero models, zero migrations |
| E-32 | Form / Quote / Pipeline / Review / Task | **M** | Zero models, zero migrations |
| E-33 | Contact tags | P | `app/Models/Contacts.php::getTags()` decodes a JSON column; there is no tag-added event and no tag entity. The only `*tags*` migration is `2021_02_07_101007_create_template_tags_table.php` (message merge tags). |
| E-34 | `Invoices` model | W but **not** customer invoicing | `app/Models/Invoices.php` — inherited Ultimate SMS **plan-subscription** invoices issued by the platform to the customer. There is no Business→client invoicing feature. |
| E-35 | `MessageSent` event | **M in practice** | `app/Events/MessageSent.php` exists with **zero dispatch sites**. A declared class is not a fired event. |
| E-36 | `MessageReceived` event | P | `app/Events/MessageReceived.php` implements `ShouldBroadcastNow`, carries untyped `$user, $message, $data`, and is dispatched from exactly one site: `app/Http/Controllers/Customer/DLRController.php:629`. It is a websocket broadcast for chat-box, not a durable Business-scoped domain event, and it is not transactional. |
| E-37 | Payment events | P but wrong subject | `app/Events/Usage/BusinessFundingAttemptSucceeded.php` / `…Failed.php` describe the **customer topping up their own wallet**, not a payment received from the Business's client |
| E-38 | Impersonation ("view as") | P and unsuitable | `app/Repositories/Eloquent/EloquentAccountRepository.php::impersonate()` (2263–2299): authorizes on `users.parent_id` only, calls `auth()->loginUsingId()` (full identity swap), overwrites session `permissions`, and **writes to the target user** (`$customer->update(['active_portal' => 'customer'])`). No audit row, no expiry, no prohibited-action list. Banner: `resources/views/auth/loggedAs.blade.php` via `resources/views/panels/breadcrumb.blade.php:3`. Exit: `app/Http/Controllers/Auth/LoginController.php:232`. **It cannot express Agency → client-Business access at all.** |
| E-39 | Auth branding seam | W (unconfigured) | `resources/views/auth/login.blade.php:38` → `resources/views/components/branding-illustration.blade.php` → `app/Library/Branding/BrandingPresenter.php::illustration()`; fallback constants (lines 19–27) are `images/pages/login-v2.svg` / `login-v2-dark.svg` — bundled Vuexy artwork. The owner-configurable seam exists and is simply unset. |
| E-40 | Business-scoped route shape | W | `routes/customer.php:631` opens `Route::prefix('workspaces')->name('workspaces.')`; all Business surfaces are nested inside it (analytics 710, gbp 742, automations 769, outreach 791, contacts 830, usage-billing 650–665) |
| E-41 | Bare per-module chooser routes | W | `routes/customer.php` — `GET /gbp`, `/channels`, `/website`, `/analytics`, `/automations`, `/outreach`, `/prospecting` each resolve to an `entry()` chooser that redirects when exactly one Business is accessible |
| E-42 | Policies | P | `app/Policies/` contains only `UserPolicy.php`; authorization is permission-string + `WorkspaceManager` based |

---

## 4. LOCKED TERMINOLOGY

These are the only names permitted in customer-facing copy, route names and code
comments from the first slice onward.

| Term | Meaning | Customer-visible? |
|---|---|---|
| **Platform** | AI Business OS itself, operated by the software owner | Only in the platform-administration experience |
| **Workspace** | The subscription, ownership, staff and billing container. Conceptually the GHL *Agency* account. Technically universal. | **Agency only.** Hidden from Core/Growth. |
| **Business** | One client company/account: its own CRM, website, conversations, billing attribution, automations and settings. Conceptually the GHL *Location / sub-account*. | Yes. Core/Growth customers live inside exactly one. |
| **Client Account** | The Agency-facing customer-visible name for a Business | Agency only |
| **Location** (a.k.a. *Physical location*, *Service area*) | A `BusinessLocation`: a storefront, branch or service area **inside** a Business | Yes, under Business Settings |
| **Business phone** | The Business's default messaging/calling identity | Yes |
| **Usage balance** | The prepaid telecommunications balance held by the Business wallet | Yes |
| **Payer** | The party funding a Business's usage balance | Agency only (see §12.4) |

**Forbidden in customer-facing copy:** *sub-account*, *sending server*, *sender
ID*, *messaging profile*, *connection ID*, *feature key*, *meter*, *micro*,
*reservation*, *tenant*, *entitlement*.

**Never a customer-visible account level:** `BusinessLocation`. It is a property
of a Business, never an account switcher entry. This is a blocking invariant
(§18 row S-4, asserted by T-CTX-4).

---

## 5. ACCOUNT / CONTEXT MODEL

### 5.1 The technical hierarchy is preserved unchanged

```
Platform (owner)
└── Workspace                  ← subscription, staff, billing container
    └── Business                ← one client company (CRM, site, conversations…)
        └── BusinessLocation    ← physical storefront / branch / service area
```

No table, foreign key or authorization path in RFC-003 or RFC-004 changes. What
changes is which levels a given role *sees*.

### 5.2 Customer-visible hierarchy by role

| Role | Sees Workspace? | Enters at | Business switcher | Location |
|---|---|---|---|---|
| Platform owner | Yes (all) | Platform administration | n/a | n/a |
| Core / Growth owner | **No** | Their single Business | Hidden (one Business) | Business Settings → Locations |
| Agency owner / admin | Yes, as the account frame | Client Accounts list | **Client Accounts** | Inside each Client Account |
| Agency staff | Only assigned Businesses | Client Accounts list (filtered) | Filtered switcher | Inside each assigned Client Account |
| Client Business owner/admin | **No** | Their single Business | Hidden | Business Settings → Locations |
| Restricted Business staff | No | Their single Business, reduced menu | Hidden | Read-only |

### 5.3 Workspace invisibility rule for Core/Growth

For a Workspace whose effective plan tier is Core or Growth **and** which holds
exactly one Business:

* the word "Workspace" must not appear in any customer-facing label, heading,
  breadcrumb, empty state or flash message;
* the sidebar must not contain a *Workspaces* entry;
* the Business is entered directly after login;
* Workspace-level surfaces that must remain reachable (plan, staff, subscription)
  appear under **Settings → Account**, described in Business terms.

The URL may continue to contain `/workspaces/{workspaceUid}/…` (§8.5); the
*interface* may not.

### 5.4 Agency client access

A client user is a member of the Agency Workspace whose
`business_access_scope = selected` with exactly the Businesses they own assigned
(`WorkspaceMembershipBusiness`). They must not be able to observe:

* the Agency Workspace name, plan, staff list or subscription;
* any other Business, including its existence or count;
* global provider credentials;
* Agency billing administration or payer assignment controls.

Existence-disclosure rule: an unauthorized Business uid returns **404**, never
403, matching the established `abort(404)` discipline in
`app/Http/Controllers/Customer/Business/GoogleBusinessProfileController.php`.

### 5.5 View as client — required replacement capability

The existing `impersonate()` (E-38) must **not** be extended. It authorizes on
`users.parent_id`, swaps identity with `loginUsingId()`, mutates the target
user's row, and cannot express Workspace membership. A new mechanism is
required, with these locked properties:

| Property | Requirement |
|---|---|
| **Entry** | POST from `Client Accounts → [Business] → View as client`, CSRF-protected. Authorized only for an active Workspace membership with role owner/admin **and** access to that Business. |
| **Identity** | The authenticated user **does not change**. `Auth::id()` remains the Agency actor for the entire session. A *view context* is layered on top. |
| **Authorization** | Every authorization check continues to run against the real actor **and** is additionally constrained to the viewed Business. View-as may only ever *narrow* what is permitted, never widen it. |
| **Banner** | A persistent, non-dismissible banner on every page naming the viewed Business, the real actor, the expiry time and an **Exit** control. |
| **Audit** | A durable row on entry and on exit: actor user id, Workspace id, Business id, started_at, ended_at, expiry, and the reason if supplied. Not session-only. |
| **Expiry** | A bounded TTL (recommended default 60 minutes, §28.2). Expiry ends the view context and returns the actor to the Agency shell. |
| **Prohibited while viewing** | Changing the payer; funding the wallet; purchasing or releasing a phone number; changing plan or slots; changing Workspace staff; entering or revealing any provider credential; deleting data; starting another view-as. Attempting any of these is refused with a plain message, and the refusal is audited. |
| **Exit** | Explicit control, plus automatic exit on expiry, plus exit on logout. Exit never logs the actor out. |

---

## 6. ROLE AND PERMISSION MATRIX

Existing permission strings are reused where they exist
(`app/Helpers/Helper.php` menu `access` values; `access_backend`,
`view_numbers`, `automations`, `website`, `view_reports`,
`view_google_business_profile`, `manage_google_business_profile`, `chat_box`).
New strings are additive and follow the existing snake_case convention.

| Capability | Platform owner | Agency owner | Agency admin | Agency staff | Business owner | Business staff | Client (viewed) |
|---|---|---|---|---|---|---|---|
| Platform settings, plan catalog, provider config | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| See Workspace frame | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Client Accounts list | ✅ | ✅ | ✅ | assigned only | ❌ | ❌ | ❌ |
| Create a Business | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Business CRM / conversations / website / automations | ✅ | ✅ | ✅ | assigned | ✅ | scoped | ✅ |
| Set up business phone (`manage_business_phone`, new) | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| Fund usage balance (`fund_usage_balance`, new) | ✅ | ✅ | ✅ | ❌ | ✅ if client-paid | ❌ | ❌ |
| Set Business spending cap | ✅ | ✅ | ✅ | ❌ | ✅ if client-paid | ❌ | ❌ |
| **Assign payer** (`assign_billing_responsibility`, new) | ✅ | ✅ | ✅ | ❌ | **❌** | ❌ | ❌ |
| Set Workspace aggregate cap | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| View as client (`view_as_client`, new) | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Advanced / BYO provider (`manage_advanced_provider`, new) | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Platform emergency kill switch | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

**Locked rule:** a Business user can never transfer billing responsibility back
to the Agency (§12.4). `assign_billing_responsibility` is not grantable to a
Business-scoped membership.

---

## 7. PLAN / ACCOUNT LIMITS

### 7.1 Recorded contradiction — Business slots vs physical locations

**The locked decisions given to this lane and the merged repository disagree,
and the disagreement is material.**

| Subject | Locked decision (this lane) | Repository at `7d235cf` |
|---|---|---|
| Core Businesses | **1** | `business_slot_included = 3`, `business_slot_max = 5` |
| Growth Businesses | **1** | `business_slot_included = 3`, `business_slot_max = 5` |
| Included physical locations (Core/Growth) | **3** | *no location limit exists at all* (E-8) |
| Physical locations 4–5 (Core/Growth) | **50% of plan price** | *not modelled* |
| Additional **Business** slots 4–5 | not offered on Core/Growth | `additional_business_slot_price_ratio = 0.5000`, enforced by `EntitlementManager::decideBusinessSlotCapacity()` |
| Agency Businesses | unlimited | `unlimited_business_slots = true` ✅ agrees |
| Agency locations | unlimited | not modelled (no limit exists) — agrees by omission |
| Agency price | **$497/month** | `price = null` for all tiers (E-7) |

**Root cause, stated precisely:** RFC-004 §4 line 38 reads *"Enforce
**Business/location** slot capacity (3 included, an explicit paid allocation step
for 4 and 5, 6+ requires Agency)"*. That sentence conflates two different
entities. The implementation resolved the ambiguity toward **Business** slots.
The locked product decision resolves it toward **physical locations**, with
Businesses capped at one for Core/Growth.

**This contract does not silently change either side.** It records that
implementing §7.2 requires an authorized correction to RFC-004 and to the seeded
catalog (§27, C-1). No slice below may alter `workspace_plan_catalog` seed values
until that correction is approved by a human.

**Second contradiction:** the 3-included / 4-and-5-at-50% rule cannot be
implemented for physical locations today, because **no product path creates a
second `BusinessLocation`** (E-3). A location-count limit would currently be
unreachable. Multi-location creation is therefore a prerequisite, tracked as
Slice 1 scope (§21).

### 7.2 Locked plan and account limits (target state)

| | Core | Growth | Agency |
|---|---|---|---|
| Businesses / client accounts | 1 | 1 | Unlimited |
| Physical locations included | 3 | 3 | Unlimited |
| Physical locations 4–5 | 50% of the relevant plan price each | 50% of the relevant plan price each | n/a (unlimited) |
| Physical locations 6+ | Requires Agency | Requires Agency | Unlimited |
| Price | *open (§28.1)* | *open (§28.1)* | **$497 / month** |
| White-label | ❌ | ❌ | ✅ |

**Locked qualifications:**

* Unlimited Agency Businesses **do not** generate unlimited free variable-usage
  credit. Every Business funds its own usage balance (§12).
* "Unlimited locations" for Agency means unlimited `BusinessLocation` rows; it is
  not a reinterpretation of the Business/client-account limit, which is
  separately unlimited.
* An empty or dormant Business record must create **zero** provider spending
  (§20, T-COST-8).

### 7.3 Activation state for cost-producing capability

A capability that creates recurring or external cost must be explicitly
**activated** per Business. Creating a Business activates nothing.

Required states, per Business per capability:

| State | Meaning | External cost |
|---|---|---|
| `unavailable` | Not entitled by plan | none |
| `available` | Entitled, never activated | none |
| `activating` | Onboarding in progress; funds reserved, provider work not yet complete | reserved only |
| `active` | Provisioned and billable | yes |
| `suspended` | Active but paused (funds, compliance, or admin) | recurring only |
| `released` | Deliberately ended | none |

The first capability to use this state machine is **business phone** (§10).
Website generation and AI allowances use their own meters and are explicitly not
funded by the telecom balance (§20).

---

## 8. NAVIGATION AND INFORMATION ARCHITECTURE

### 8.1 The core rule

**Global routes and Business routes must never be visually mixed.** The shell
renders exactly one of two frames at any moment:

* the **Account frame** (Agency only): Client Accounts, Workspace staff, plan,
  Workspace billing;
* the **Business frame**: everything scoped to one Business.

A Business route must never highlight an Account-frame item. Today it does:
every Business surface is nested under the `workspaces` prefix
(`routes/customer.php:631`) and the sidebar's active-item logic matches on the
`workspaces` slug (E-9, observed problem 6).

### 8.2 Core / Growth navigation (the default experience)

```
[Business name ▾]                      ← header; no switcher when only one
  Home
  Contacts
  Conversations
  Calendar*                            ← hidden until the feature exists
  Website
  Google Business Profile
  Automations
  Campaigns
  Analytics
  Settings
    Business details
    Physical locations & service areas ← BusinessLocation lives HERE
    Business phone
    Usage & billing
    Team
    Account (plan, subscription)
```

No *Workspaces* entry. No *Sending*, *Sender ID*, *Numbers*, *Keywords*,
*Developers* entries in the default menu — they move under
Settings → Advanced and are permission-gated (§8.6).

### 8.3 Agency navigation

```
[Agency name]
  Home
  Client Accounts            ← the Business list; the only account switcher
  Prospecting
  Team
  Billing & usage            ← Workspace aggregate view
  Settings
    Branding (white-label)
    Advanced / provider
```

Selecting a Client Account enters the Business frame of §8.2, with a persistent
"← All client accounts" affordance and the client name in the header.

### 8.4 Navigation is authorization-driven

The menu must be generated from the same authorization the routes enforce. An
item is rendered only when the current actor may reach its target for the
current context. **No link may point at an unavailable or unauthorized route**
(T-NAV-2), and **no menu entry may point at a route that does not exist**
(T-NAV-3 — this is E-11 today).

`app/Helpers/Helper.php::menuData()` returns a static array shared for every
user by `app/Providers/MenuServiceProvider.php`. It cannot express context. It
must be replaced by a context-aware builder that receives the resolved actor,
frame and Business. The legacy array remains for the admin branch.

### 8.5 URL shape

Business-scoped URLs may keep the existing
`/workspaces/{workspaceUid}/businesses/{businessUid}/…` shape — changing them is
a large, risky, low-value migration, and the GBP correction pass has just
finished proving the two-uid resolution boundary works. What must change is
that the *active navigation state* is derived from the resolved **frame and
Business**, never from the URL's leading segment.

A future slice may add a Business-rooted alias; it is explicitly out of scope
here (§26).

### 8.6 Advanced surfaces

`Sender ID`, `Numbers`, `Keywords`, `Developers`, `Sending servers` and the BYO
provider screen move to **Settings → Advanced**, hidden unless the actor holds
the relevant permission *and* the Workspace is Agency (or the platform owner is
viewing). They are never part of the default Core/Growth menu.

---

## 9. AUTHENTICATION AND SHARED-SHELL REQUIREMENTS

### 9.1 Branding

* Login, register, forgot-password, reset-password, two-factor and verify screens
  render the AI Business OS identity.
* The inherited Vuexy illustrations
  (`images/pages/login-v2.svg`, `login-v2-dark.svg`,
  `forgot-password-v2*.svg`, `create-account.svg`) must no longer be the
  effective default for the auth surface.
* The seam already exists and works: `BrandingPresenter::illustration()` returns
  the owner-configured `auth_illustration` when set (E-39). The requirement is
  therefore **(a)** ship AI Business OS default assets and make them the
  fallback constants, and **(b)** keep the owner override and the Agency
  white-label override working.
* White-label precedence: Agency branding (when the Workspace is white-label and
  the request resolves to that Agency) → owner platform branding → AI Business OS
  default. Never a broken image reference.

### 9.2 Shell requirements common to every authenticated customer screen

| Element | Requirement |
|---|---|
| Header | Current context name (Business, or Agency in the Account frame). Never a bare product name. |
| Context switcher | Rendered **only** when the actor can reach more than one Business |
| Impersonation banner | §5.5; persistent, non-dismissible, with expiry and Exit |
| User menu | Profile, language, theme, sign out. No platform-administration links. |
| Empty states | Task-oriented (§17.3) |
| Flash messages | Plain language; never an internal key, class name or classification value |

### 9.3 Six distinct experiences

1. **Platform owner** — separate administration experience at the admin path; no
   customer navigation elements; never reachable from the customer shell.
2. **Core/Growth Business owner** — §8.2; no Workspace vocabulary; no payer
   selector; no provider credentials.
3. **Agency owner/admin** — §8.3; Client Accounts; payer assignment; aggregate
   billing; white-label settings; View as client.
4. **Agency staff** — §8.3 filtered to assigned Businesses; no payer assignment;
   no Workspace billing; no View as client.
5. **Client Business owner/admin** — §8.2 exactly; cannot observe the Agency.
6. **Restricted Business staff** — §8.2 with a reduced menu derived from
   permissions; no Settings → Account; no billing.

---

## 10. MANAGED BUSINESS-PHONE ONBOARDING

### 10.1 The default is platform-managed, not BYO

Today the only path is BYO credentials (E-21). That becomes the exception
(§11.4). The default customer flow is:

| Step | Customer sees | System does |
|---|---|---|
| 1 | **Set up business phone** | Creates a `business_phone_setup` record in `activating` (§7.3) |
| 2 | Keep an existing number, or get a new one | Number search against the managed provider, or a port-in intake |
| 3 | Business details in normal language (legal name, address, contact, website) | Populates the compliance registration payload |
| 4 | What you'll use messaging for; how people opt in; example messages | Populates the messaging use-case / consent registration |
| 5 | **Add funds** — minimum $5 | Stripe funding of the Business usage balance (§12) |
| 6 | "Setting up your number…" | Provisions provider resources; reserves and then settles the acquisition and registration costs |
| 7 | "Your business number is +1 …" | Records the Business default messaging identity |
| 8 | *(nothing)* | Automations and conversations resolve that identity automatically (§10.4) |

### 10.2 What the customer must never see

Telnyx API keys, Telnyx Messaging Profile IDs, Telnyx connection IDs, Twilio
Account SID / Auth Token, sending-server records, raw sender IDs, provider
routing identifiers, provider account identifiers, or any provider-side error
string. Blocking invariant; tested by T-SEC-3 and T-SEC-4.

### 10.3 Ordering invariant

**No provider-costing call may occur before funds are reserved.** Steps 1–4
create no external cost. Step 5 must complete before step 6 begins. A customer
who abandons at step 4 has cost the platform nothing (§20, T-COST-2).

### 10.4 Default messaging identity

Each Business has at most one **active default messaging identity**. Resolution
order for any outbound message:

1. the Business's active default identity;
2. if none: the operation fails closed with a task-oriented message
   ("This business doesn't have a phone number yet — set one up to send
   messages") and a link to §10.1 step 1;
3. never a platform-shared number, never another Business's identity, never a
   silently-picked sending server.

`SendMessageAction` must resolve the identity at execution time rather than
requiring it in stored config (E-27). Automations created before an identity
exists remain valid and become sendable once one exists.

---

## 11. TELNYX ISOLATION AND CREDENTIAL MODEL

### 11.1 Verified vs proposed — stated honestly

**The repository contains no evidence of any Telnyx provisioning, sub-account or
managed-account capability** (E-22). `SendingServer::TYPE_TELNYX` is a credential
holder for a customer-supplied key. Therefore:

* **Verified provider capability: none.** Nothing in this repository
  demonstrates what Telnyx permits for managed sub-accounts, number ordering, or
  brand/campaign registration on a customer's behalf.
* Everything in §10 and §11.2–§11.4 is **proposed application behaviour**,
  contingent on a human confirming provider terms (§28.3).

No implementation slice may proceed past design on §11.2 until that confirmation
is recorded.

### 11.2 Isolation requirements (whatever mechanism is chosen)

| Requirement | Rule |
|---|---|
| Credential ownership | Managed provider credentials belong to the **platform**, are stored once, encrypted at rest, and are never readable by any customer role |
| Per-Business isolation | Each Business's provider resources must be attributable to exactly one Business. No shared identity may send on behalf of two Businesses. |
| Blast radius | A compromise or suspension of one Business's provider resources must not disable another Business |
| Cost attribution | Every provider-billed event must resolve to exactly one Business and one usage meter (§20) |
| Support boundary | The platform, not the customer, holds the provider relationship. Customers never need a provider login. |

### 11.3 Storage

* Managed platform credentials: platform configuration, encrypted, never in this
  or any other contract, never in a log, never in a ledger row, never in a queue
  payload.
* Per-Business provider resource identifiers (number id, registration id): stored
  as opaque identifiers, admin-visible only.
* Existing `CustomerBasedSendingServer` rows remain for BYO only (§11.4).

### 11.4 Advanced / Bring your own provider

An escape hatch may remain, subject to all of:

* gated behind `manage_advanced_provider` (§6), Agency-only;
* located in Settings → Advanced, never in normal onboarding, never linked from
  an empty state or a guided flow;
* it must not weaken tenancy, encryption, metering, or the support boundary;
* usage on a BYO connection is still metered and still attributed to the
  Business, even though the provider bills the customer directly;
* the current customer-facing credential form
  (`MessagingChannelsController::ALLOWED_PROVIDERS`) is the starting point and
  must be moved, not duplicated.

---

## 12. WALLET, PAYER AND SPENDING-CONTROL MODEL

### 12.1 Funding principle

**There is no included phone/SMS credit on any paid plan.** Subscriptions buy
software access. Telecommunications is prepaid separately by the assigned payer,
before any provider-costing operation.

The Business usage balance pays for: phone-number acquisition; recurring number
rental; required registration and compliance fees; SMS; MMS; calls; and any other
provider-billed telecommunications usage.

### 12.2 Funding mechanics (reusing RFC-005)

RFC-005 already provides the correct primitives (E-15, E-16). This contract adds
constraints, not a new ledger.

| Requirement | Basis | Change needed |
|---|---|---|
| Pre-authorization before paid provider work | `UsageWalletManager::reserve()` | Extend to telecom meters |
| Exact settlement after the provider outcome | `commit()` | Extend to telecom meters |
| Release / expiry of abandoned reservations | `release()`, `expireStaleReservations()` | none |
| Immutable ledger | `business_usage_ledger_entries` | none |
| Idempotency | reservation idempotency keys | none |
| Refund / reversal | `applyProviderRefund()`, `applyDisputeWithdrawal()`, `reinstateDisputedFunds()` | none |
| Negative-balance prevention | wallet invariants | none |
| Retail rate vs provider cost | `BusinessUsageRate`, `setActiveRate()` | Add telecom rate rows; retail shown to customers, provider cost administrative only |
| **Minimum top-up $5** | — | **New.** `InitiateTopUpRequest` currently allows `min:1` micro (E-18) |
| **Auto-recharge presets $5/$10/$25/$50 + bounded custom** | — | **New.** `ConfigureAutoRechargeRequest` currently free-form (E-17) |
| Monthly auto-recharge ceiling | `monthly_recharge_cap_micro` | none |
| Monthly Business spending cap | `setSpendCap()` | Surface in plain language |
| **Workspace aggregate safety cap** | — | **New** (E-19) |
| **Platform/provider emergency kill switch** | — | **New** (E-20) |
| Alerts before thresholds and on failed recharge | `recordAutoRechargeFailure()` exists | Add customer-facing alerts |
| Concurrency | RFC-005 locking | none |

### 12.3 Insufficient funds

When the balance cannot cover a reservation:

* the operation is refused **before** any provider call;
* the message is task-oriented and names the shortfall in retail currency;
* recurring inbound handling required by law or compliance is preserved (§13.3);
* nothing is queued that would silently retry into a charge.

### 12.4 Payer model

| Scenario | Payer | Who may change it |
|---|---|---|
| Core / Growth | Workspace owner funds the Business balance | Not applicable — no selector shown |
| Agency, client-paid | The client funds their own Business balance | Agency owner/admin |
| Agency, agency-paid | The Agency funds the client's balance and sets a monthly cap | Agency owner/admin |
| Promotional credit | Platform owner grants explicit, non-withdrawable, bounded, expiring, auditable credit | Platform owner only |

**Visibility rules (correcting E-13):**

* Core/Growth customers **never** see a payer selector.
* The Agency control lives at **Client Accounts → [Business] → Billing
  responsibility**, not on the Business's own Usage & Billing page.
* A client-paid Business sees its funding method, balance, usage and limits.
* An agency-paid Business sees **"Billing managed by your agency"** and no
  funding controls.
* A Business user can never transfer responsibility back to the Agency.

**Payer no-op rule (correcting E-12):** submitting the currently-assigned payer
must be a **true no-op**: no `business_payer_transitions` row, no
`BusinessPayerChanged` event, no update, and a neutral message
("No change — this business is already billed to …"), never
"Payer updated." Every real change remains authorized, audited, idempotent and
safe under concurrency.

### 12.5 Promotional credit

Exists only when the platform owner explicitly grants it. It is
non-withdrawable, bounded, expiring and auditable, is a distinct ledger entry
type, and is consumed before paid balance. It is never granted automatically by
plan, trial, Business creation, or Agency Business count (§20).

---

## 13. NUMBER LIFECYCLE AND PORTABILITY

### 13.1 Acquisition

Only through §10. Never during registration. Never as a side effect of creating
a Business. Always after funds are reserved.

### 13.2 Renewal

| Rule | Requirement |
|---|---|
| Sufficient funds | A renewal charge requires sufficient balance or an authorized auto-recharge |
| Advance warning | Alert before the renewal date when the projected balance is insufficient |
| Degradation order | Pause **new paid outbound** usage first |
| Preserved | Inbound handling required by law or compliance is preserved through the grace period |
| Grace period | A defined, non-zero grace period (recommended default 14 days, §28.2) |
| Never silent | An established number is **never** silently released. Release requires an explicit, audited decision after the grace period expires, with prior notification. |

### 13.3 Suspension vs release

`suspended` (§7.3) stops new paid outbound while retaining the number.
`released` returns the number and is irreversible. The transition from
`suspended` to `released` must be explicit, audited, and preceded by
notification.

### 13.4 Portability and exit

* A customer may port a number **out**. The platform must not obstruct it.
* Porting out is a supported, documented request path, not a support escalation.
* On account closure, the customer is told before any number is released, and is
  given the grace period to port.
* Port-in is supported at §10.1 step 2.

---

## 14. AUTOMATION RECIPE EXPERIENCE

### 14.1 Outcome-first entry

The builder's first screen asks **"What would you like to automate?"** and
presents the recipe catalogue. The current mechanical *When / Then* form
(`resources/views/customer/Automations/form.blade.php`) becomes the **custom**
path only, reached from the last catalogue entry.

### 14.2 Initial recipe catalogue

| Recipe | Trigger | Action | Available when |
|---|---|---|---|
| Reply to a new lead | Contact created | Send SMS | Slice 7 |
| Confirm a new booking | Booking created | Send SMS | Booking feature exists |
| Send a booking reminder | Appointment approaching | Send SMS | Booking feature exists |
| Follow up after an appointment | Appointment completed | Send SMS | Booking feature exists |
| Thank someone after payment | Payment received | Send SMS | Payments feature exists |
| Follow up after a failed payment | Payment failed | Send SMS | Payments feature exists |
| Reply to a missed call | Missed call | Send SMS | Voice events exist |
| Notify staff about an important message | Incoming message received | Internal notification | Slice 8 |
| Ask for a review | Appointment completed | Request review | Booking + review exist |
| Follow up on an unanswered quote | Quote accepted / not accepted | Send SMS | Quote feature exists |
| **Create a custom automation** | any available | any available | Slice 7 |

A recipe whose trigger or action is not yet backed (§15) is **not shown**. It is
never shown disabled, never shown as "coming soon" in the catalogue, and never
creatable. This is the same discipline RFC-004 applies to feature identity vs
availability.

### 14.3 Required behaviour per recipe

| Aspect | Requirement |
|---|---|
| Description | Plain language, with a concrete example message |
| Setup checks | Before activation, verify: a default messaging identity exists (§10.4); the balance is sufficient for a realistic run; consent handling is configured |
| Defaults | Every recipe arrives with a working default message, timing and audience; the customer may activate without editing anything |
| Preview / test | Send a test to the actor's own verified number; the test is metered and billed like a real send, and is labelled as a test in the ledger |
| Draft / publish | A recipe is a draft until explicitly activated. Drafts never execute. |
| Pause / resume | Available at any time; pausing never loses configuration |
| Versioning | Editing an active automation creates a new version; in-flight executions complete against the version they started on |
| Cost estimate | Before activation, show an estimated cost per run and per month in retail currency |

### 14.4 Safety rules (all mandatory)

| Rule | Requirement |
|---|---|
| Idempotency | Reuse `AutomationExecutionClaimService` (E-28); one logical execution per trigger occurrence |
| Loop prevention | An automation may never trigger itself, directly or through a cycle. Cycle detection at publish time **and** a per-contact per-automation execution ceiling at runtime. |
| Rate limiting | Per Business and per contact |
| Quiet hours | Per Business, in the Business timezone, defaulting to a conservative window. **New capability** (E-29). |
| Consent / STOP | An automation must never message a contact who has opted out. Enforced at execution time, after the claim, before the provider call. |
| Missing channel | Fail closed with the §10.4 message; do not silently skip and do not mark the automation failed permanently |
| Insufficient funds | Fail closed, alert the payer, do not queue an unbounded retry |
| Audit history | Every execution recorded with trigger occurrence, decision, outcome and cost |
| Retry / dead-letter | Bounded retries with backoff; terminal failures land in a dead-letter view the customer can see and act on |

---

## 15. TRIGGER / ACTION CAPABILITY MATRIX

Classification: **A** = already backed by a trustworthy domain event ·
**B** = requires an adapter over an existing event · **C** = requires a new
event/outbox producer · **D** = future-only, the source feature does not exist.

### 15.1 Triggers

| Trigger | Class | Evidence / reason |
|---|---|---|
| Contact created | **A** | `AutomationTriggerType::ContactCreated`; after-commit hook on the CRM creation seams |
| Contact date reached | **A** | `AutomationTriggerType::ContactDateReached`; scheduler sweep |
| Form submitted | **D** | No Form model or migration (E-32) |
| Incoming message received | **C** | `MessageReceived` is a `ShouldBroadcastNow` websocket event with an untyped `$user, $message, $data` payload, dispatched from one non-transactional site, `DLRController:629` (E-36). A durable, Business-scoped, transactional producer is required. An adapter over the broadcast event is **not** acceptable. |
| Missed call | **D** | No voice-event ingestion exists |
| Booking created | **D** | No Booking model (E-31) |
| Booking rescheduled | **D** | as above |
| Booking cancelled | **D** | as above |
| Appointment approaching | **D** | as above |
| Appointment completed | **D** | as above |
| Payment received | **D** | `BusinessFundingAttemptSucceeded` is the customer topping up their own wallet, not a payment from the Business's client (E-37) |
| Payment failed | **D** | as above |
| Payment refunded | **D** | as above |
| Invoice due | **D** | `Invoices` is the platform's own plan-subscription invoicing (E-34) |
| Quote accepted | **D** | No Quote model (E-32) |
| Pipeline stage changed | **D** | No Pipeline model (E-32) |
| Tag added | **C** | `Contacts::getTags()` decodes a JSON column (E-33); there is no tag entity and no tag-change event. A producer is required, or the trigger is deferred. |
| Review received | **D** | No review ingestion. GBP Slice A is explicitly read-only and excludes reviews. |

**Result: 2 of 18 triggers exist. 2 require new producers. 14 are future-only.**

### 15.2 Actions

| Action | Class | Evidence / reason |
|---|---|---|
| Send SMS/MMS | **A** (needs correction) | `AutomationActionType::SendMessage`; but `SendMessageAction:49-52` requires `sender_id` + `sending_server` in config (E-27). Must resolve the default identity instead (§10.4). |
| Update contact field | **A** | `AutomationActionType::UpdateContactField` |
| Send email | **C** | No Business-scoped transactional email send path in the automation engine |
| Internal notification | **C** | Requires a staff-notification producer |
| Create / update task | **D** | No Task model (E-32) |
| Add / remove tag | **C** | as trigger "Tag added" |
| Move pipeline stage | **D** | No Pipeline model |
| Create follow-up date | **B** | Can adapt `UpdateContactField` over a date custom field |
| Request review | **D** | Depends on a review capability that does not exist |
| Call webhook / integration | **C** | New, and only under Settings → Advanced with SSRF protection, an allowlist, signed payloads, timeouts and no secrets in the URL |
| AI-assisted response | **D** | Future-only; requires an explicit AI budget, a separate meter, and content safety constraints. Not authorized here. |

**Result: 2 of 11 actions exist (one needing correction), 1 adaptable, 4 need
producers, 4 are future-only.**

### 15.3 No fake sources

**Polling or table-watching must not be used where a transactional domain event
is required.** A trigger classified **C** is implemented by a real producer in
the same transaction as the state change it reports, with an outbox (§16), or it
is not implemented at all. Scheduled sweeps remain acceptable only for
genuinely time-based triggers (Contact date reached, Appointment approaching).

---

## 16. EVENT / OUTBOX REQUIREMENTS

Any trigger classified **C** in §15 must satisfy all of:

1. **Transactional production.** The event row is written in the *same*
   transaction as the state change. If the state change rolls back, the event
   does not exist.
2. **Outbox table.** A durable outbox row per event: business id, event type,
   occurred_at, a stable idempotency key, a bounded payload, and a delivery
   state. The event is dispatched from the outbox *after* commit, never inside
   the transaction (matching the established discipline in
   `docs/automation/GOOGLE-BUSINESS-PROFILE-CONTRACT.md` §24.9).
3. **At-least-once delivery, exactly-once effect.** Consumers deduplicate on the
   idempotency key. Redelivery must be harmless.
4. **Bounded payload.** No provider payloads, no credentials, no message bodies
   beyond what the trigger needs, no personal data beyond the contact reference.
5. **Business-scoped.** Every event carries exactly one `business_id`. A
   consumer must never infer tenancy from anything else.
6. **Ordering not assumed.** Consumers must tolerate out-of-order delivery.
7. **Observability.** Undeliverable events are visible and replayable by the
   platform owner.

`MessageSent` (E-35) must either receive a real producer under these rules or be
deleted. Leaving a declared-but-never-dispatched event in the codebase is
prohibited, because it invites exactly the false-capability claim this contract
forbids.

---

## 17. TRANSLATION, ACCESSIBILITY AND HELP REQUIREMENTS

### 17.1 Translation completeness

* **No raw key may ever render.** `locale.menu.Website`,
  `locale.menu.Channels`, `locale.menu.Google Business Profile`,
  `locale.menu.Prospecting`, `locale.menu.Compose` and
  `locale.menu.Opportunities` currently do (E-10).
* Every navigation label, empty state, flash message, validation message and
  button in scope must have an `en` translation.
* **Fallback behaviour:** when a key is missing in the active locale, fall back
  to `en`; when missing in `en`, render the human-readable label supplied by the
  navigation builder — **never** the key path.
* A mechanical test asserts that no rendered customer page contains the substring
  `locale.` (T-I18N-1).

### 17.2 Accessibility

* Keyboard reachable: context switcher, view-as banner and Exit, recipe
  catalogue, all funding controls.
* Visible focus states throughout.
* Colour is never the sole carrier of meaning (balance state, automation state,
  number state).
* Every form control has a programmatic label; every icon-only control has an
  accessible name.
* The view-as banner is announced to assistive technology.
* Contrast meets WCAG 2.1 AA in light and dark.

### 17.3 Help and empty states

Every empty state answers: *what this is*, *why it is empty*, *the one next
action*. Entitlement denials say what the customer gets by upgrading, in
outcome terms — never a feature key, never a classification value, never
"denied_by_workspace_override".

### 17.4 Responsive behaviour

The Business frame is usable on a phone: the context switcher collapses into the
header, the sidebar becomes a drawer, tables reflow, and funding and phone
onboarding are completable on a small screen.

---

## 18. SECURITY AND PRIVACY INVARIANTS

| # | Invariant |
|---|---|
| S-1 | No provider credential value is ever rendered to a customer role, logged, placed in a ledger row, queue payload, or contract document |
| S-2 | Tenancy is resolved server-side on every request; an unauthorized Workspace or Business uid returns 404, never 403 |
| S-3 | Navigation visibility is never an authorization mechanism; every route enforces independently |
| S-4 | `BusinessLocation` is never an authorization boundary and never an account switcher level |
| S-5 | View-as never changes `Auth::id()`, never widens authorization, always audits, always expires, and is blocked from the §5.5 prohibited actions |
| S-6 | A client user can never observe the Agency Workspace, another Business, or their existence |
| S-7 | Payer changes are authorized, audited, idempotent, and concurrency-safe; a no-op writes nothing |
| S-8 | Funding, number acquisition and number release are CSRF-protected POSTs with explicit confirmation |
| S-9 | Webhook actions (§15.2) enforce an allowlist, block private address ranges, sign payloads, bound timeouts, and never carry secrets in the URL |
| S-10 | Promotional credit is non-withdrawable and cannot be converted to a refund |
| S-11 | Test sends are metered and attributed like real sends; they are never a free channel |
| S-12 | White-label branding never leaks one Agency's assets to another Workspace |

---

## 19. IDEMPOTENCY, CONCURRENCY AND ATOMICITY REQUIREMENTS

| Area | Requirement |
|---|---|
| Wallet funding | Reuse RFC-005 idempotency; a duplicate provider webhook credits exactly once |
| Reservation | Two concurrent reservations cannot both consume the last available balance |
| Auto-recharge | Two concurrent evaluations for one Business create at most one attempt |
| Payer change | Serialized on the payer-assignment row; a no-op writes nothing |
| Number acquisition | Idempotent per setup record; a retried request never orders two numbers |
| Number renewal | Idempotent per period; a replayed renewal charges once |
| Automation execution | Reuse `AutomationExecutionClaimService` claim + `claimStart()` |
| Outbox delivery | At-least-once delivery with consumer-side deduplication (§16) |
| View-as | Entering twice does not create two sessions; expiry is evaluated server-side |
| Provider calls | **Never inside a database transaction** |

---

## 20. COST-CONTROL INVARIANTS

| # | Invariant | Test |
|---|---|---|
| C-1 | No external provider call without prior authorization **and** an available budget | T-COST-1 |
| C-2 | No recurring-cost resource is created merely by creating a Business | T-COST-2 |
| C-3 | No phone number is acquired during registration | T-COST-3 |
| C-4 | Trial accounts receive no phone/SMS credit unless a deliberate, audited promotion applies | T-COST-4 |
| C-5 | Website generation and AI allowances use their own meters and are never funded from the telecom balance | T-COST-5 |
| C-6 | Unlimited Agency Businesses do not multiply free AI or telecom allowance | T-COST-6 |
| C-7 | Every cost-producing feature declares its usage meter and its payer | T-COST-7 |
| C-8 | A dormant Business produces zero external cost over any period | T-COST-8 |
| C-9 | Retail cost is shown to customers; provider cost is administrative only | T-COST-9 |
| C-10 | When funds or an external integration are unavailable, the product degrades gracefully with a task-oriented message and no partial charge | T-COST-10 |

---

## 21. IMPLEMENTATION SLICES IN DEPENDENCY ORDER

No slice below is authorized by this document. Each requires its own contract or
an explicit authorization referencing this one. **This must not be implemented
as a single branch.**

| # | Slice | Depends on | Parallel with |
|---|---|---|---|
| **1** | **Account-context and navigation foundation** — context resolver, frame model, authorization-driven menu builder, fix E-11, Business-frame vs Account-frame separation, multi-`BusinessLocation` creation under Business Settings | — | 2 |
| **2** | **Shared authentication and customer shell** — auth branding, AI Business OS default assets, shell header/context/user menu, translation completeness (E-10), empty-state pattern | — | 1 |
| **3** | **Managed messaging / provider foundation** — managed-provider abstraction, per-Business isolation, credential custody, telecom usage meters and rates, move BYO to Settings → Advanced | 1 | 5 |
| **4** | **Business-phone guided onboarding** — §10 flow, compliance intake, activation state machine (§7.3), number lifecycle (§13) | 3, 5 | — |
| **5** | **Wallet / payer / balance UX** — $5 minimum, auto-recharge presets, plain-language spend caps, Workspace aggregate cap, kill switch, payer visibility rules, **payer no-op fix (E-12)**, raw feature keys removed (E-14) | 1 | 3 |
| **6** | **Default messaging identity resolution** — §10.4; remove provider selectors from automations (E-26); `SendMessageAction` resolves at execution (E-27) | 4 | — |
| **7** | **Guided automation recipes** — §14 catalogue, draft/publish, preview, cost estimate, quiet hours, consent enforcement, dead-letter view | 6 | — |
| **8** | **New domain-event trigger adapters** — §16 outbox; the class-**C** triggers only, one producer at a time | 7 | — |
| **9** | **Advanced BYO provider migration** — §11.4 | 3 | — |
| **10** | **Final accessibility, translation and visual consistency pass** | all | — |

**Hard dependencies:** 4 requires 3 and 5 (funds must exist before provisioning).
6 requires 4 (there must be an identity to resolve). 7 requires 6 (recipes must
not ask for a sender). 8 requires 7 (recipes are the consumer).

**May run in parallel:** 1 ∥ 2; 3 ∥ 5.

**View as client** (§5.5) belongs to Slice 1. **The §7.2 plan-limit change is
not in any slice** until the §27 C-1 correction is authorized.

---

## 22. PER-SLICE PATH BOUNDARIES

Each slice's contract must publish an exact allowlist. The mechanically derivable
boundaries are:

| Slice | Permitted paths |
|---|---|
| 1 | `app/Library/Navigation/**` (new), `app/Providers/MenuServiceProvider.php`, `app/Helpers/Helper.php` (customer menu branch only), `resources/views/panels/{sidebar,submenu,navbar,breadcrumb}.blade.php`, `app/Repositories/{Contracts,Eloquent}/*BusinessLocation*`, `app/Http/Controllers/Customer/Business/BusinessLocationsController.php` (new), `routes/customer.php`, `app/Library/ViewAs/**` (new), `database/migrations/**` (view-as audit, locations) |
| 2 | `resources/views/auth/**`, `resources/views/layouts/**`, `resources/views/components/branding-illustration.blade.php`, `app/Library/Branding/**`, `resources/lang/en/locale.php`, `public/images/branding/**` (new assets) |
| 3 | `app/Library/Messaging/**` (new), `app/Models/BusinessMessagingIdentity.php` (new), `app/Http/Controllers/Customer/Business/MessagingChannelsController.php`, `resources/views/customer/business/MessagingChannels/**`, `config/services.php`, `database/migrations/**` |
| 4 | `app/Library/Telephony/**` (new), `app/Http/Controllers/Customer/Business/BusinessPhoneController.php` (new), `resources/views/customer/business/phone/**` (new), `database/migrations/**` |
| 5 | `app/Http/Requests/Customer/Business/**`, `app/Library/Usage/{BillingProfileManager,UsageWalletManager}.php`, `app/Http/Controllers/Customer/Business/UsageBilling*.php`, `resources/views/customer/business/usage-billing/**`, `database/migrations/**` |
| 6 | `app/Library/Automation/Actions/SendMessageAction.php`, `app/Http/Requests/Automations/AutomationDefinitionRequest.php`, `resources/views/customer/Automations/form.blade.php`, `app/Library/Messaging/**` |
| 7 | `app/Library/Automation/**`, `app/Enums/Automation/**`, `resources/views/customer/Automations/**`, `app/Http/Controllers/Customer/Business/AutomationsController.php`, `database/migrations/**` |
| 8 | `app/Events/**`, `app/Library/Outbox/**` (new), the specific producer's owning library, `database/migrations/**` |
| 9 | `resources/views/customer/settings/advanced/**` (new), `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` |
| 10 | `resources/views/**`, `resources/lang/**`, `resources/sass/**` |

**Forbidden in every slice:** `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`
(until §27 C-1 is authorized), `app/Library/Entitlement/EntitlementManager.php`
decision semantics, RFC-005 ledger invariants, `public_html`, any other lane's
worktree.

---

## 23. MIGRATION AND ROLLBACK EXPECTATIONS

* Every migration is additive and reversible, and its `down()` is described in
  its docblock.
* No migration deletes customer data. No migration releases a phone number.
* Backfills are idempotent and re-runnable.
* A slice that adds a state machine seeds existing rows into the state that
  produces **zero external cost** (`available`, never `active`).
* The navigation change is behaviour-only and requires no migration; it must be
  revertible by reverting one commit.
* Rolling back a messaging slice must never orphan a provisioned number: the
  provider resource record survives an application rollback and is reconciled
  forward.

---

## 24. TEST MATRIX

| ID | Assertion |
|---|---|
| **T-CTX-1** | Each of the six §9.3 experiences sees exactly its own navigation set |
| **T-CTX-2** | A Core/Growth customer's rendered pages contain the word "Workspace" zero times |
| **T-CTX-3** | Agency isolation: client A's actor cannot read, write or discover client B's Business; the response is 404 |
| **T-CTX-4** | `BusinessLocation` never appears as an account-switcher entry and never gates authorization |
| **T-CTX-5** | A Business route never marks an Account-frame navigation item active |
| **T-VIEW-1** | View-as writes an audit row on entry and exit; `Auth::id()` is unchanged throughout |
| **T-VIEW-2** | View-as expires at its TTL and returns the actor to the Agency frame |
| **T-VIEW-3** | Each §5.5 prohibited action is refused while viewing, and the refusal is audited |
| **T-VIEW-4** | View-as cannot widen authorization: a Business the actor cannot reach is still 404 while viewing |
| **T-NAV-1** | With navigation hidden, a direct request to every unauthorized route is still refused |
| **T-NAV-2** | Every rendered menu link resolves to a route the current actor may reach |
| **T-NAV-3** | Every menu target resolves to a defined route (regression guard for E-11) |
| **T-I18N-1** | No rendered customer page contains the substring `locale.` |
| **T-I18N-2** | Every navigation label has an `en` translation; a missing key falls back to a human label, never a key path |
| **T-AUTH-1** | Auth screens render AI Business OS branding and reference no `login-v2*.svg` fallback |
| **T-AUTH-2** | Agency white-label branding never leaks across Workspaces |
| **T-PAYER-1** | A Core/Growth customer's Usage & Billing page renders no payer selector |
| **T-PAYER-2** | A Business-scoped actor cannot mutate the payer, by form or by direct POST |
| **T-PAYER-3** | **Submitting the unchanged payer writes no transition row, dispatches no event, and does not say "updated"** |
| **T-PAYER-4** | An agency-paid Business shows "Billing managed by your agency" and no funding controls |
| **T-WALLET-1** | Funding below $5 is refused |
| **T-WALLET-2** | Each auto-recharge preset configures correctly; a custom amount outside bounds is refused |
| **T-WALLET-3** | A reservation is taken before any provider call and released on abandonment |
| **T-CAP-1** | The Business spending cap admits the exact boundary and refuses one unit above it |
| **T-CAP-2** | The Workspace aggregate cap admits the exact boundary and refuses one unit above it |
| **T-CAP-3** | Insufficient funds refuses before the provider call with a task-oriented message |
| **T-CAP-4** | Two concurrent recharges/reservations cannot both take the last unit |
| **T-CAP-5** | The emergency kill switch stops all new provider work immediately |
| **T-PHONE-1** | Acquisition is refused without sufficient reserved funds and makes zero provider calls |
| **T-PHONE-2** | Renewal with insufficient funds warns, pauses new paid outbound, preserves compliance-required inbound, and never releases the number |
| **T-PHONE-3** | The grace period elapses without an automatic release; release requires an explicit audited decision |
| **T-PHONE-4** | No number is acquired during registration or Business creation |
| **T-PROV-1** | No customer-role response body contains any provider credential field name or value |
| **T-PROV-2** | Provider credentials are readable by no customer role, in any serialization |
| **T-SENDER-1** | An automation created with no sender or channel resolves the Business default identity at execution |
| **T-SENDER-2** | With no default identity, the send fails closed with the §10.4 message and makes zero provider calls |
| **T-STOP-1** | An opted-out contact is never messaged by an automation; the skip is recorded |
| **T-STOP-2** | Quiet hours defer rather than drop, and never send outside the window |
| **T-AUTO-1** | Every visible recipe creates a valid, runnable automation with working defaults |
| **T-AUTO-2** | A recipe whose trigger or action is unbacked is not rendered and cannot be created |
| **T-AUTO-3** | One trigger occurrence produces exactly one execution under concurrent delivery |
| **T-AUTO-4** | Retries are bounded and terminal failures reach the dead-letter view |
| **T-AUTO-5** | A cycle is refused at publish; a runtime ceiling stops any surviving loop |
| **T-AUTO-6** | A draft never executes |
| **T-EVENT-1** | Each new event is written in the same transaction as its state change and dispatched only after commit |
| **T-EVENT-2** | Redelivery of an outbox event produces exactly one effect |
| **T-EVENT-3** | Booking/payment/message-derived triggers fire only from an authentic producer, never from polling |
| **T-EVENT-4** | An event carrying no `business_id` is rejected |
| **T-INTEG-1** | With an external integration unavailable, the surface degrades gracefully and charges nothing |
| **T-COST-1..10** | The ten §20 invariants, each asserted independently |
| **T-COST-8** | A dormant Business, including on an unlimited Agency plan, produces zero external cost across a simulated period |
| **T-TRIAL-1** | A trial account receives no telecom credit; a promotion is required, bounded, expiring and audited |

---

## 25. ACCEPTANCE CRITERIA

A slice is complete when:

1. Every assertion in its §24 subset passes, with exact counts reported.
2. No customer-facing string contains an internal identifier, key, class name or
   classification value.
3. No rendered page contains `locale.`.
4. Every navigation target resolves and is authorized.
5. No new provider call path exists without an authorization + budget check.
6. Every new event has a real producer, or does not exist.
7. Documentation is updated in the same commit as the behaviour change.
8. The six §9.3 experiences have each been exercised.
9. `git diff --check` is clean and the changed-path list matches the slice
   allowlist exactly.

---

## 26. EXPLICIT EXCLUSIONS

Not authorized by this contract:

* Any product code.
* Changing `workspace_plan_catalog` seed values (blocked by §27 C-1).
* Building Calendar, Booking, Forms, Quotes, Pipelines, Reviews, Tasks or
  customer-issued Invoicing — §15 classifies them as future-only.
* AI-assisted automation responses.
* Rewriting Business URLs to a Business-rooted shape (§8.5).
* Changing RFC-003 tenancy or RFC-004 decision semantics.
* Any change to Google Business Profile Slice A read-only guarantees.
* Website Guided Generation scope.
* A ten-client Agency cap — explicitly rejected; Agency Businesses are unlimited.
* Reinterpreting "unlimited locations" as applying only to physical branches.
* Deleting the legacy admin menu or the admin experience.
* Migrating existing BYO connections automatically.

---

## 27. CORRECTIONS REQUIRED TO OLDER CONTRACTS

| # | Document | Correction required | Blocking? |
|---|---|---|---|
| **C-1** | `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md` §4 | The phrase "Business/location slot capacity" conflates two entities. It must be split: **Business slots** (Core/Growth = 1; Agency unlimited) and **physical location slots** (Core/Growth = 3 included, 4–5 at 50%, 6+ requires Agency). The seeded catalog encodes the other reading (§7.1). **Requires human authorization before any code change.** | **Yes** |
| **C-2** | `docs/rfcs/RFC-004-…-DEPLOYMENT.md` §6 seed table | Follows C-1 | Yes |
| **C-3** | `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` | Add: $5 minimum top-up; the four auto-recharge presets; the Workspace aggregate cap; the emergency kill switch; the payer no-op rule; telecom meters as first-class | No |
| **C-4** | `docs/automation/B4-BUSINESS-AUTOMATIONS-CONTRACT.md` | The v1 action config requiring `sender_id` + `sending_server` is superseded by default-identity resolution (§10.4) | No |
| **C-5** | **No B2 contract document exists.** B2 Business Messaging Channels was merged (`7921fd8`, PR #201) with its rules recorded only in the class docblock of `app/Http/Controllers/Customer/Business/MessagingChannelsController.php`. | That docblock's customer-entered-credential model becomes the Agency-only advanced path (§11.4), and managed provisioning becomes the default (§10). Because there is no document to correct, Slice 3's own contract must restate the B2 rules it supersedes rather than cross-referencing them. | No |
| **C-6** | `docs/automation/DESIGN-SYSTEM-M2-*` | The customer shell must express the Account/Business frame split (§8.1); the current M2 work assumes one flat customer shell | No |
| **C-7** | Any contract asserting `MessageSent` is available | It has zero dispatch sites (E-35) | No |

---

## 28. GENUINELY OPEN HUMAN DECISIONS

Each has a recommended default and reasoning. None is a re-litigation of a
locked decision.

| # | Open question | Recommended default | Reasoning |
|---|---|---|---|
| **28.1** | Core and Growth retail prices, and the telecom retail rate card / markup | Set Core and Growth prices before Slice 5; telecom markup **30% over provider cost**, floor-rounded to the cent, published per destination | The catalog seeds `price = null` (E-7) and RFC-004 deliberately refused to invent prices. The 50%-of-plan-price location charge in §7.2 is unimplementable until a plan price exists. 30% is a common reseller margin that covers failed-send waste and support without making SMS look expensive. |
| **28.2** | Number-renewal grace period, and view-as TTL | Grace **14 days**; view-as TTL **60 minutes** | 14 days spans a missed payment plus a weekend and a support cycle without a month of free rental. 60 minutes is long enough for real support work and short enough to bound an unattended session. |
| **28.3** | Whether managed Telnyx sub-accounts, a single platform account with per-Business messaging profiles, or another mechanism best matches provider terms | **Blocked pending provider-terms confirmation.** Design Slice 3 against the §11.2 isolation requirements so the mechanism is swappable. | The repository provides zero evidence of Telnyx capability (§11.1). Committing to a mechanism before confirming terms risks a rewrite of the whole messaging foundation. |
| **28.4** | Initial countries and number types | **US and Canada, local long-code only**, at launch | Matches the existing `+1` normalization in `app/Library/AgencyProspecting/AgencyProspectPhoneNormalizer.php` and confines the compliance surface to one registration regime. |
| **28.5** | Advanced / BYO eligibility | Agency tier **and** an explicit owner-granted flag; migrations only | Keeps the support boundary intact while honouring genuine agency migrations. |
| **28.6** | Final visual design direction and assets | Commission the AI Business OS auth and empty-state illustration set before Slice 2 ships; until then use a neutral typographic auth panel rather than the Vuexy artwork | Slice 2 cannot complete on inherited artwork, and a neutral panel is better than another product's illustration. |
| **28.7** | Exact automation recipe copy | Draft from §14.2 during Slice 7; owner review before publish | Copy is the product here; it should not be locked by an engineering contract. |
| **28.8** | Whether "Tag added" ships as a producer or is deferred | **Defer** until a real tag entity exists | `Contacts::getTags()` over a JSON column (E-33) is not a sound event source, and building a tag entity is its own slice. |

---

## 29. APPENDIX A — CURRENT SCREEN → REPLACEMENT DESTINATION

| Current surface | Path | Replacement destination |
|---|---|---|
| Login with Vuexy illustration | `resources/views/auth/login.blade.php:38` | Same route, AI Business OS branding (§9.1) |
| Sidebar *Workspaces* | `Helper::menuData()` customer branch | Agency: **Client Accounts** (§8.3). Core/Growth: removed (§5.3) |
| Sidebar *Sending → Sender ID / Numbers / Keywords* | same | Settings → Advanced, permission-gated (§8.6) |
| Sidebar *Outreach → Compose* | `url('outreach')` | Business frame → **Campaigns** |
| Sidebar *Outreach → Campaigns* (**404 today**) | `url('outreach/campaigns')` | `customer.workspaces.businesses.outreach.campaigns.index` (§8.4, E-11) |
| Sidebar *Channels* | `url('channels')` | Settings → **Business phone** (§10); advanced BYO moves to Settings → Advanced |
| Sidebar *Google Business Profile* | `url('gbp')` | Business frame → Google Business Profile (unchanged, correctly labelled) |
| Sidebar *Prospecting* | `url('prospecting')` | Agency frame only |
| Sidebar *Developers* | `url('developers')` | Settings → Advanced |
| Sidebar *Chat Box* | `url('chat-box')` | Business frame → **Conversations** |
| Provider credential form | `MessagingChannels/connect.blade.php` | Settings → Advanced → Bring your own provider (§11.4) |
| Automation *Sender* / *Messaging channel* / *Channel type* selects | `Automations/form.blade.php:115-137` | **Removed.** Resolved automatically (§10.4, §6 of Slice 6) |
| Automation *When / Then* first screen | `Automations/form.blade.php` | Recipe catalogue (§14.1); this form becomes the custom path |
| Usage & Billing *Feature key* text input | `usage-billing/show.blade.php:143` | Named capabilities with plain-language labels (§17.3) |
| Usage & Billing raw `feature_key` cells | `usage-billing/show.blade.php:130,285` | Plain-language capability names |
| Usage & Billing *Payer* card | `usage-billing/show.blade.php:160-184` | Agency only, relocated to Client Accounts → [Business] → Billing responsibility (§12.4) |
| Business location editing (primary only) | `BusinessOnboardingController::storeLocation()` | Settings → **Physical locations & service areas**, multi-location (§21 Slice 1) |
| `impersonate()` via `parent_id` | `EloquentAccountRepository.php:2263` | **View as client** (§5.5) — new mechanism; the legacy path is retained only for the existing admin/sub-account case until Slice 1 replaces it |

---

**END OF CONTRACT**
