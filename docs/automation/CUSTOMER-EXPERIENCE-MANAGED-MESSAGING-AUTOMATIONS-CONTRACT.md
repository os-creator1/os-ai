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
names the correction explicitly.

**Revision — Correction Round 1.** The product owner has authorized the C-1/C-2
capacity interpretation. RFC-004 and its deployment guide are amended in this
same branch (§27), §7.1 now records the resolution rather than an open
contradiction, and six further corrections are applied: an atomicity rule that
makes it impossible to ship location creation without location capacity (§7.6);
executable per-slice allowlists carrying test and documentation paths (§22);
explicit dependency and human-decision gates (§21, §21.1, §21.2); the BYO
measurement-versus-charging distinction (§11.5); removal of premature
voice/calling claims (§10.5); and one owning slice for every test (§24.1).

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
| E-6 | Seeded **Business**-slot numbers | W as deployed; **superseded** by §7.1 | `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php` — Core and Growth: `business_slot_included = 3`, `business_slot_max = 5`, `additional_business_slot_price_ratio = 0.5000`; Agency: `unlimited_business_slots = true`. These are Business/client-account values, not location values. The migration is merged history and is never edited; Slice 1A supersedes the Core/Growth figures additively (§23.2). |
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
| **Business phone** | The Business's default **messaging** (SMS/MMS) identity. Voice is out of scope — see §10.5. | Yes |
| **Usage balance** | The prepaid balance held by the Business wallet, funding managed transport and every paid non-transport service | Yes |
| **Payer** | The party funding a Business's usage balance | Agency only (see §12.4) |
| **Managed transport** | *Platform-managed, platform-billed transport.* AI Business OS holds the provider relationship and is invoiced by the provider; the assigned payer prepays AI Business OS; the wallet is reserved and debited at the disclosed retail rate. | Yes, as "your number" |
| **BYO transport** | The customer holds the provider relationship and is billed by that provider directly. AI Business OS records volume for measurement but **never** reserves or debits the wallet for that transport (§11.5). | Agency advanced only |
| **Non-transport service** | Anything AI Business OS itself performs and pays for — AI generation, email delivery, paid lookups, image generation, storage. Reserved and debited normally **regardless of transport mode**. | Yes |

**Terminology rule.** The wallet is always funded by the customer, Agency or
assigned payer — never by the platform. The phrase *"platform-funded
transport"* is therefore forbidden: it wrongly implies AI Business OS pays.
Write **"platform-managed, platform-billed transport"** (or "managed
transport") when contrasting with BYO.

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

### 7.1 Resolved contradiction — Business capacity vs physical-location capacity

**Status: AUTHORIZED AND RESOLVED.** The product owner has explicitly approved
the corrected interpretation. RFC-004 has been amended in place
(`docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md` v1.4 revision
note and §33), and its deployment guide carries the matching amendment note.
Nothing in this area is awaiting human authorization any longer.

**What was wrong.** RFC-004 §2 read *"Enforce **Business/location** slot capacity
(3 included, an explicit paid allocation step for 4 and 5, 6+ requires
Agency)"*. That single phrase conflated a **Business/client account** (a
`businesses` row) with a **physical location** (a `business_locations` row).
Milestone 1 resolved the ambiguity toward Business slots and seeded the catalog
accordingly (E-6).

**The authorized resolution.**

| Subject | Authorized | Deployed at `7d235cf` | Delivered by |
|---|---|---|---|
| Core Businesses | **1** | `business_slot_included = 3`, `business_slot_max = 5` | Slice 1A additive migration |
| Growth Businesses | **1** | as above | Slice 1A |
| Agency Businesses | Unlimited | `unlimited_business_slots = true` — already correct | — |
| Included physical locations (Core/Growth) | **3** | *no location capacity exists* (E-8) | Slice 1A additive columns |
| Physical locations 4 and 5 | **50% of the tier price each** | not modelled | Slice 1A |
| Physical location 6+ (Core/Growth) | Requires Agency | not modelled | Slice 1A |
| Agency physical locations | Unlimited | not modelled — agrees by omission | Slice 1A (`unlimited_location_slots`) |
| Agency price | **$497/month** | `price = null` (E-7) | operator data, not a migration |
| Core / Growth price | **still undecided** (§28.1) | `price = null` | — |

**Column meanings are stated, never silently reinterpreted.**
`workspace_plan_catalog.business_slot_included`, `business_slot_max`,
`unlimited_business_slots` and `additional_business_slot_price_ratio` continue to
mean **Business/client-account** capacity. Physical-location capacity requires
**new additive columns** (`location_slot_included`, `location_slot_max`,
`unlimited_location_slots`, `additional_location_slot_price_ratio`) plus a
per-Business paid-location allocation counter. No existing column is repurposed.
See RFC-004 §33.3–§33.4.

**The merged seed migration is historical and is never edited.**
`database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`
correctly records what M1 seeded. The correction ships as a **new additive
migration** (§23.2), which also grandfathers existing data so that no Business
and no location already in use becomes inaccessible (§7.5).

**The second problem is a sequencing problem, and §21 now solves it
structurally.** The 3-included / 4-and-5-at-50% rule is unenforceable today
because **no product path creates a second `BusinessLocation`** (E-3). Creating
that path without shipping enforcement in the same slice would open an
unlimited-location gap. Slice **1A** therefore ships multi-location creation
**and** location-capacity enforcement atomically (§7.3, §21).

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
### 7.3 Physical-location capacity behaviour — exact, per case

**Capacity counts ACTIVE locations, not every historical row.** Physical-location
add-ons are **reusable subscription capacity**, not a permanent purchase welded
to one database row. A closed branch must not consume paid capacity forever.

Evaluated per Business as a `COUNT` of `business_locations` rows for that
Business **whose lifecycle state is `active`**, at every
active-location-count-increasing operation (create **and** reactivate), while
holding the Business row lock, before the count-increasing write
(RFC-004 §33.8).

| Case | Behaviour |
|---|---|
| Active locations 1–3, Core/Growth | Created freely. No allocation, no charge, no prompt. |
| Active location 4, Core/Growth | Denied with `location_slot_allocation_required` until a paid allocation exists. Once allocated, creation succeeds. The charge is 50% of the tier price, recurring with the subscription. |
| Active location 5, Core/Growth | Identical to location 4, against a second allocation. |
| Active location 6+, Core/Growth | Denied with `location_slot_limit_exceeded`. **No allocation can raise it** — `location_slot_max = 5`. The only path is upgrading to Agency; the denial message says so in outcome terms. |
| Any location, Agency | Created freely. `unlimited_location_slots = true` short-circuits the check before any counting. |
| Downgrade leaving more active locations than the target permits | **Every existing location is retained and stays fully accessible.** The Business becomes grandfathered-over-capacity: new creation and reactivation are denied, nothing is deleted, hidden or archived automatically. Durably audited. |
| Existing data at migration time | Backfilled as `active` and, where over capacity, grandfathered-complimentary (§7.5). A Business already holding 4+ locations keeps them all and owes nothing retroactively. |

### 7.3a Location lifecycle — archive, reuse, reactivate

`business_locations` **has no lifecycle column today.** Verified at
`database/migrations/2026_07_18_120002_create_business_locations_table.php` and
`database/migrations/2026_09_09_120002_add_hours_and_provenance_to_business_locations_table.php`:
there is no `status`, no `archived_at`, no `is_active` and no `SoftDeletes` on
`app/Models/BusinessLocation.php`. (`hours_verification_status` is hours
provenance, not lifecycle.) Slice 1A therefore **adds the minimum necessary
additive lifecycle state** — see §23.2 step 2a. This contract does not pretend
an absent field exists.

| # | Rule |
|---|---|
| 1 | Capacity counts **active** locations only. Archived locations consume no capacity. |
| 2 | Removing a location from active use **archives** it. All historical data is retained — the row, its GBP binding history, analytics, website references and audit trail. |
| 3 | Archiving frees exactly **one active-location slot**, immediately. |
| 4 | A paid 4th/5th-location allocation is **reusable**: while the allocation remains subscribed, the freed slot may be used by a replacement location. The customer does not re-purchase. |
| 5 | Archiving a location **does not** automatically cancel its paid allocation. The subscription continues until the customer explicitly cancels it. |
| 6 | Cancelling a paid allocation is permitted **only when** the Business's active-location count fits the post-cancellation capacity **at the effective date**. Otherwise the cancellation is refused with a message naming how many locations must first be archived. |
| 7 | **Reactivating** an archived location runs the **same capacity check** as creating a new active location — including allocation requirements and the 6+ ceiling. |
| 8 | **Nothing is silently deleted.** Not the location row, not its GBP binding, not historical analytics, not website references, not audit history. Archiving is a state change, never a delete. |
| 9 | The **primary** location cannot be archived while it is primary. Primary status must first be reassigned to another **active** location, in the same transaction that archives the old one. A Business's last active location cannot be archived at all. |
| 10 | Provider resources attached to a location (phone numbers, GBP bindings) follow **their own lifecycle** (§7.7, §13). Archiving the local record never silently releases a number or unbinds a Google location; those require their own explicit, audited actions. |

**Why the GBP binding needs rule 8 stated explicitly.**
`business_google_locations` carries
`bgl_location_business_foreign … onDelete('cascade')`
(`database/migrations/2026_09_09_120002_create_business_google_locations_table.php`),
so **deleting** a `business_locations` row would cascade-delete its Google
binding. Archiving must therefore be a state change and never a row delete, or
the binding would be destroyed as a side effect.

Because capacity counts active rows, archiving genuinely recovers capacity — and
because archiving is not deletion, no history is lost to recover it.

### 7.3b Enforcement architecture — what a test can and cannot prove

Round 1 claimed a reflection test would prove "a future path cannot bypass
enforcement". **That claim was wrong and is withdrawn.** No test can prove that
arbitrary future ORM calls, repository methods or raw SQL will never write to
`business_locations` directly. What follows is enforceable architecture plus an
honest guard.

1. **One canonical service boundary.** A single service owns every
   customer-reachable location-count-increasing operation — **create** and
   **reactivate**. It performs the §7.3 capacity check while holding the
   Business row lock, before the write, in the same transaction.
2. **Every customer-reachable controller or action delegates to it.** No
   controller performs its own `BusinessLocation` write. At the time of writing
   the only such path is
   `app/Http/Controllers/Customer/BusinessOnboardingController.php::storeLocation()`
   → `EloquentBusinessLocationRepository::upsertPrimary()`, and it must be
   migrated onto the boundary in Slice 1A.
3. **Direct location-count-increasing writes are prohibited** outside
   migrations, factories, seeders and explicitly named test-support helpers.
4. **T-LOC-9 is a source-boundary inventory test.** It enumerates the production
   write seams that exist today, asserts the set is exactly the approved list,
   and **fails when a new unapproved seam appears** — so adding one is a
   deliberate, reviewed act rather than an accident. This is the same technique
   the GBP suite already uses to assert its read-only guarantee
   (`tests/Feature/GoogleBusinessProfile/GoogleBusinessProfileReadOnlyTest.php`).
5. **T-LOC-10 covers behaviour**, route by route, for every currently reachable
   create and reactivate path.
6. **Stated honestly:** T-LOC-9 guards repository architecture. It does not
   mathematically prevent future code from writing directly to the database,
   and this contract does not claim otherwise.
7. **Database-level enforcement is preserved where mechanically possible:** the
   existing foreign keys, the `business_google_locations` composite FK, and
   transaction plus row-lock discipline all remain. A count-based capacity rule
   cannot be expressed as a database constraint in MySQL, which is precisely why
   the service boundary and the inventory test exist.

### 7.4 Business-capacity behaviour — exact, per case

| Case | Behaviour |
|---|---|
| First Business, any tier | Created freely |
| Second Business, Core/Growth | Denied with `business_slot_limit_exceeded`. Core/Growth no longer offer additional Business slots at any price; the only path is Agency. |
| Any Business, Agency | Created freely (`unlimited_business_slots = true`) |
| Workspace already holding 2+ Businesses when Slice 1A ships | **Grandfathered-over-capacity.** Every existing Business keeps working; only new creation is denied. This is the state RFC-004 §25.4 and the M1 backfill already define. |
| Agency → Core/Growth downgrade with multiple Businesses | Same grandfathering. No Business, wallet, phone number or GBP binding is deleted. |

### 7.5 Grandfathering and backfill — no existing data becomes inaccessible

The Slice 1A additive migration (§23.2) tightens Core/Growth Business capacity
from 3-included/5-max to 1 and introduces a location capacity that never existed.
Both could otherwise strand real customer data. Therefore, as a blocking rule:

* **No `businesses` row and no `business_locations` row is deleted, deactivated,
  hidden, unbound or made unreachable by this correction.**
* A Workspace over its corrected Business capacity, or a Business over its new
  location capacity, becomes **grandfathered-over-capacity** — the state RFC-004
  §25.4 already defines. Everything existing keeps working; only *new* creation
  is denied.
* Grandfathered capacity is **complimentary**. It must never be re-interpreted
  later as unpaid recurring debt, and no retroactive charge is raised for a
  location that predates the migration.
* No wallet, phone number, GBP binding or website is touched.

#### 7.5.1 Four capacity kinds must be separately computable

At any moment, for any Business, the system must be able to answer each of these
**without inference and without re-reading history**:

| Kind | Meaning | Billable? |
|---|---|---|
| **Included active capacity** | `location_slot_included` from the tier (3 on Core/Growth) | No — in the plan price |
| **Paid additional capacity** | Subscribed 4th/5th-location allocations, reusable per §7.3a rule 4 | Yes — 50% of tier price each |
| **Complimentary grandfathered active capacity** | Excess active locations that predate the migration or a downgrade | No — never becomes debt |
| **Archived historical locations** | Retained rows in the archived lifecycle state | No — consume nothing |

#### 7.5.2 Persistence — per-Business state, not a Workspace-level inference

A Workspace-level transition row alone is **not sufficient**. Location capacity
is a **per-Business** property, a Workspace may hold many Businesses, and
recomputing "which locations were grandfathered" later from a Workspace payload
would be an inference — exactly what re-interpretation as debt looks like.

Slice 1A therefore persists **additive per-Business state**:

* `businesses.grandfathered_location_slots` — an unsigned integer, the count of
  complimentary excess active locations frozen at backfill time (default 0);
* `businesses.additional_location_slots` — the paid allocation count (already
  contracted in §23.2 step 2).

Effective active-location capacity is then computable arithmetically:

```
capacity = unlimited_location_slots
         ? ∞
         : location_slot_included
         + additional_location_slots          (paid, reusable)
         + grandfathered_location_slots       (complimentary, non-reusable)
```

In addition, one durable `workspace_entitlement_transitions` row per affected
Workspace records the correction, and **its immutable payload names every
affected Business and that Business's exact grandfathered count** — so the audit
trail alone is sufficient to reconstruct the state, and the per-Business columns
are sufficient to enforce it. Neither depends on the other being re-derived.

#### 7.5.3 Consumption rule — paid capacity is reusable, complimentary excess is not

> **Complimentary grandfathering protects the specific existing active
> locations it was granted for. Once an excess grandfathered location is
> archived and the Business falls toward its normal entitlement, that consumed
> grandfathered excess does not become a transferable free slot.**

Mechanically: archiving a location while `grandfathered_location_slots > 0` and
the Business is still above its normal entitlement **decrements**
`grandfathered_location_slots` by one. Paid `additional_location_slots` are
never decremented by archiving (§7.3a rule 4) — that is precisely the difference
between the two.

| Event | Effect |
|---|---|
| A grandfathered excess location is **archived** | `grandfathered_location_slots` decrements by one. The freed capacity is **not** reusable. |
| A grandfathered location is **reactivated** | Runs the normal §7.3 check against current capacity. If the complimentary allowance was already consumed, reactivation needs an included or paid slot like any other location. |
| The Business drops **below** normal included capacity | `grandfathered_location_slots` is already 0 or is set to 0; the Business is simply a normal in-capacity Business again |
| The Workspace **upgrades to Agency** | `unlimited_location_slots` short-circuits every check. `grandfathered_location_slots` is retained but unused, so a later downgrade is evaluated honestly rather than being re-granted. |
| The Workspace **downgrades again** later | Grandfathering is **re-evaluated at that moment** against the then-current active count, producing a fresh complimentary allowance and a fresh audited transition. It is not restored from the pre-upgrade value. |

#### 7.5.4 Backfill idempotency

The backfill logic is idempotent: re-invoking it recomputes the same
per-Business counts from the same active-location data and writes no duplicate
transition. This is a property of the **backfill routine**, not a claim that the
whole migration re-runs — see §23.2, which states the migration semantics
precisely.

### 7.6 Atomicity requirement — there must be no unlimited-location gap

**Blocking rule.** Customer-reachable creation of additional `BusinessLocation`
rows and physical-location capacity enforcement **must ship in the same slice,
in the same release**. There must never exist a deployable state in which a
Core or Growth customer can create unlimited physical locations.

This is why §21 splits the original Slice 1 into **1A** (plan and location
capacity — additive catalog columns, allocation, enforcement, the upgrade path,
the backfill, *and* the multi-location UI) and **1B** (account context and
navigation). A slice that adds the creation path without the capacity check, or
the capacity check without the creation path, is a contract violation regardless
of how it is reviewed.

Two consequences follow mechanically:

1. Slice 1A's allowlist must permit both the location-writing repository/controller
   paths and the entitlement/catalog paths (§22). It does.
2. Slice 1A's exit criteria include T-LOC-1..T-LOC-8 (§24, §24.1). None of those
   tests may be deferred to a later slice.

### 7.7 Activation state for cost-producing capability

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
| 1 | **Set up business phone** | Creates a `business_phone_setup` record in `activating` (§7.7) |
| 2 | Keep an existing number, or get a new one | Number search against the managed provider, or a port-in intake |
| 3 | Business details in normal language (legal name, address, contact, website) | Populates the compliance registration payload |
| 4 | What you'll use messaging for; how people opt in; example messages | Populates the messaging use-case / consent registration |
| 5 | **Itemised setup estimate, then Add funds** — only if the balance is short | Computes the required funding floor (§10.6); if the available balance already covers it, this step is **skipped entirely**; otherwise Stripe funds the shortfall (§12) |
| 6 | "Setting up your number…" | **Reserves the complete amount first**, then provisions provider resources, then settles exactly (§10.6) |
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

### 10.5 Channel scope — SMS and MMS only; voice is future-only

The managed capability contracted here covers **SMS and MMS**. That is what the
repository supports through the B1 send core and what
`AutomationActionType::SendMessage` and `sms_type ∈ {plain, mms}` express
(`resources/views/customer/Automations/form.blade.php:115-119`).

**Voice/calling is explicitly out of scope for every slice in §21.** No slice
provisions a voice-capable resource, prices a call, meters a call, or presents
calling in the customer interface. No acceptance criterion mentions it.

Repository note, so the exclusion is not mistaken for an oversight: an inherited
Ultimate SMS voice-campaign module does exist at `routes/customer.php:277-284`
(`CampaignController@voiceQuickSend`, `@voiceCampaignBuilder`, `@voiceImport`).
It is **User-scoped legacy campaign functionality, not a Business-scoped managed
capability**, it is not part of the Business frame (§8.2), and this contract
neither extends nor removes it. Its existence is not evidence that managed
calling is implemented.

Voice may be contracted later as its own capability with its own meter, its own
compliance scope and its own rate-card entry. Until then, any future-capability
language about calling must be labelled future-only wherever it appears.

---

### 10.6 Required funding before provisioning — $5 is a floor, not an estimate

**The defect this corrects.** Onboarding previously said "Add funds — minimum
$5" and then provisioned. $5 is the *manual top-up minimum*; it is not a claim
that a setup costs $5. A number acquisition plus a compliance registration can
exceed it, so provisioning could begin under-funded and fail mid-flight.

**Locked rule.**

> **Required available funding before provisioning = the greater of (a) $5, or
> (b) the complete displayed upfront reservation for the selected number and
> setup.**

The upfront reservation includes **every currently known provider-billed upfront
item**:

* number acquisition and its first billing period;
* registration / compliance fees (brand, campaign or equivalent);
* applicable taxes and regulatory fees where known at estimate time;
* the initial required usable balance, if the chosen configuration needs one.

**Sequence, in this exact order:**

1. **Estimate.** The customer sees an **itemised retail estimate** before paying
   anything — one line per item above, each at its disclosed retail rate, with
   a total. Provider cost is never shown (§20 C-9).
2. **Sufficiency check.** Compare the total against the wallet's **available**
   balance (balance minus existing reservations).
   * Sufficient → **no top-up is requested.** The customer is not forced to
     add money they already have. Step 5 is skipped.
   * Short → the customer is asked to fund **at least the shortfall**, subject
     to the $5 manual top-up minimum. Funding more is permitted.
3. **Reserve the complete amount** — one reservation covering the whole upfront
   total, taken **before any provider-costing call** (§10.3).
4. **Provision**, then **settle exactly** against the real provider outcome.
   A lower actual cost releases the difference; a higher actual cost follows the
   normal settlement rules and, if it cannot be covered, the operation fails
   closed and the reservation is released rather than leaving a partial setup.
5. **Price drift.** If the provider price changes between estimate and
   reservation, the reservation is attempted at the **new** price. If the new
   total exceeds what was estimated and the balance no longer covers it, the
   flow returns to step 1 with a fresh itemised estimate — it never silently
   reserves more than the customer was shown, and it never provisions on a
   stale estimate.

**Zero provider calls occur before a successful reservation.** Abandoning at any
step before step 3 leaves no reservation, no charge and no provider resource.

Asserted by T-FUND-1..T-FUND-6 (§24), owned by Slice 4 (§24.1).

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
* it must not weaken tenancy, encryption, the support boundary, or **operational
  measurement** (§11.5);
* the current customer-facing credential form
  (`MessagingChannelsController::ALLOWED_PROVIDERS`) is the starting point and
  must be moved, not duplicated.

### 11.5 BYO billing semantics — measurement is not charging

"Metered" is ambiguous and must never be used unqualified for BYO. This
contract separates two distinct things:

| | **Operational measurement** | **Wallet debit** |
|---|---|---|
| What it is | Recording that a message was sent, its size, its outcome and its owning Business | Debiting the Business's platform usage balance at the platform retail rate |
| Purpose | Audit, reporting, analytics, plan-limit accounting, abuse detection, automation history, cost estimates | Collecting money for transport the platform itself paid for |
| Applies to managed transport | ✅ | ✅ |
| Applies to **BYO** transport | ✅ | **❌** |

**The rule, stated exactly.**

> **No wallet reservation or debit may occur for the BYO provider's SMS/MMS
> transport itself.**

That is the whole exemption, and it is scoped to transport. On a BYO connection
the external provider bills the customer directly for that transport, so
debiting the platform wallet for it at the platform retail rate would charge the
customer twice for one message.

**BYO does not make a Business free.** A Business sending over BYO transport
still incurs — and must still be reserved and debited for — every separately
authorized AI Business OS charge for an **independent, non-transport service**,
including where applicable:

* AI generation
* email delivery
* paid data enrichment / lookups
* image generation
* storage
* any other separately metered non-transport operation

Those are services AI Business OS itself performs and pays for. They are
unrelated to who carries the SMS, and their normal reservation, debit,
spend-cap, insufficient-funds and settlement behaviour applies unchanged.

**Cost display must show both halves separately.** An automation that sends over
BYO SMS *and* performs a paid AI action shows:

* **$0.00** — AI Business OS telecom transport (billed by your provider)
* the separately calculated AI (or other platform) cost, at its own meter's rate

There is no platform **transport** fee and no transport markup on BYO in this
contract. Neither is invented here; if the owner later wants one, it requires an
explicit, separately disclosed, approved decision and its own amendment, and it
would be a distinct fee line item — never a re-billing of provider transport.

**Consequences that must be carried consistently everywhere:**

| Surface | BYO behaviour |
|---|---|
| Wallet / ledger | **No transport reservation and no transport debit.** Every non-transport meter reserves and debits normally. |
| Usage meter | The send **is** recorded against the Business's telecom usage meter for measurement and plan-limit accounting, with a rate of zero and an explicit `byo` transport marker, so reporting can distinguish it from a free managed send |
| Payer | Unchanged. The payer still funds managed transport and **all** non-transport services. A wholly-BYO Business that uses no paid platform service may hold a zero balance indefinitely; a BYO Business that uses AI, email or storage still needs funds for those. |
| Rates | No retail **telecom** rate is applied to BYO transport, so §28.1's rate-card decision does not gate BYO. Non-transport meters keep their own rates. |
| Spending caps | A BYO **transport** send consumes neither the Business spending cap nor the Workspace aggregate cap. Non-transport charges on the same Business consume both normally. |
| Insufficient funds | A zero balance **must not** block a BYO transport send (§12.3 applies to managed transport). It **must** still block a paid non-transport operation on that same Business, exactly as it would anywhere else. |
| Automation cost estimate | The estimate is itemised. Transport reads **$0.00 — sent through your own provider, billed by them, not by us**; every paid non-transport action in the same recipe is priced normally and shown on its own line. A recipe with a paid AI step therefore shows a non-zero total. |
| Reporting | Managed transport, BYO transport volume and non-transport platform spend are reported as three separate figures; BYO volume is never summed into a "spend" figure |

Asserted by T-BYO-1..T-BYO-6 (§24). T-BYO-1..2 are owned by Slice 3 and
T-BYO-3..6 by Slice 9 (§24.1).

---

## 12. WALLET, PAYER AND SPENDING-CONTROL MODEL

### 12.1 Funding principle

**There is no included phone/SMS credit on any paid plan.** Subscriptions buy
software access. Telecommunications is prepaid separately by the assigned payer,
before any provider-costing operation.

The Business usage balance pays for: phone-number acquisition; recurring number
rental; required registration and compliance fees; **SMS; and MMS**.

**Verified channel scope is SMS and MMS only.** Voice/calling is **not** in
scope for any slice in this contract — see §10.5. When a future slice adds a
further provider-billed capability, it extends this list in its own contract,
adds its own meter, and is priced under the §28.1 rate-card decision. Nothing
here prices, provisions or implies calling.

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
| **Auto-recharge presets $5/$10/$25/$50 + bounded custom** | — | **New.** `ConfigureAutoRechargeRequest` currently free-form with `min:1` micro (E-17). The custom bounds are **owner-gated** (§28.9); presets may ship first only with custom entry disabled. |
| Monthly auto-recharge ceiling | `monthly_recharge_cap_micro` | none |
| Monthly Business spending cap | `setSpendCap()` | Surface in plain language |
| **Workspace aggregate safety cap** | — | **New** (E-19) |
| **Platform/provider emergency kill switch** | — | **New** (E-20) |
| Alerts before thresholds and on failed recharge | `recordAutoRechargeFailure()` exists | Add customer-facing alerts |
| Concurrency | RFC-005 locking | none |

### 12.3 Insufficient funds

This section governs **platform-managed, platform-billed transport** (§4) and
every paid **non-transport** operation. **BYO transport** takes no reservation
and is never blocked by a zero balance; a paid non-transport operation on a BYO
Business is blocked normally (§11.5).

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

`suspended` (§7.7) stops new paid outbound while retaining the number.
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

| # | Slice | Depends on | Human-decision gate | Parallel with |
|---|---|---|---|---|
| **1A** | **Plan and physical-location capacity** — additive catalog columns (§7.1), corrected Business capacity, per-Business paid and grandfathered counters, **location lifecycle state** (§7.3a, §23.2 step 2a), the canonical location service boundary (§7.3b), **multi-`BusinessLocation` creation and archive/reactivate UI**, capacity enforcement, upgrade path, grandfathering backfill (§7.5). Creation and enforcement ship **atomically** (§7.6). | — | **Cleared** (C-1/C-2 authorized). Core/Growth *prices* stay open (§28.1), so the 50% charge is stored as a ratio and cannot be **collected** until a price exists — this does not block the slice. | 1B, 2 |
| **1B** | **Account-context and navigation foundation** — context resolver, frame model, authorization-driven menu builder, fix E-11, Business-frame vs Account-frame separation, **View as client** (§5.5) | — | None | 1A, 2 |
| **2** | **Shared authentication and customer shell** — auth branding seam and neutral default, shell header/context/user menu, translation completeness (E-10), empty-state pattern | — | **Partial (§28.6).** Neutral typographic branding ships without artwork. Custom artwork is **not** an acceptance requirement (§21.1). | 1A, 1B |
| **3** | **Managed messaging / provider foundation** — provider abstraction, per-Business isolation, credential custody, telecom **measurement** meters, move BYO to Settings → Advanced | 1B | **BLOCKING (§28.3)** for any provider-specific implementation. Design-only work is permitted under §21.2. **Retail rate activation is separately gated by §28.1** and is excluded from this slice. | 5 |
| **4** | **Business-phone guided onboarding** — §10 flow, compliance intake, activation state machine (§7.7), number lifecycle (§13) | 3, 5 | **BLOCKING: all four of §28.3 (mechanism), §28.4 (launch countries and compliance scope), §28.1 (rates), and Slice 5 (funding behaviour) must be resolved before provisioning any number** | — |
| **5** | **Wallet / payer / balance UX** — $5 minimum, auto-recharge presets, plain-language spend caps, Workspace aggregate cap, kill switch, payer visibility rules, **payer no-op fix (E-12)**, raw feature keys removed (E-14) | 1B | **Partial (§28.9).** Funding mechanics are rate-independent, so §28.1a does not gate this slice. But the **custom** auto-recharge amount and the monthly ceiling maxima are owner-gated: the four fixed presets may ship with custom entry **disabled**; an unbounded custom amount may **never** ship. | 3 |
| **6** | **Default messaging identity resolution** — §10.4; remove provider selectors from automations (E-26); `SendMessageAction` resolves at execution (E-27) | 4 | Inherits Slice 4's gates | — |
| **7** | **Guided automation recipes** — §14 catalogue, draft/publish, preview, cost estimate, quiet hours, consent enforcement, dead-letter view | 6 | None beyond Slice 6's | — |
| **8** | **New domain-event trigger adapters** — §16 outbox; the class-**C** triggers only, one producer at a time | 7 | None | — |
| **9** | **Advanced BYO provider migration** — §11.4, §11.5 | 3 | None. BYO applies no retail rate (§11.5), so §28.1 does not gate it. | — |
| **10** | **Final accessibility, translation and visual consistency pass** | all | §28.6 artwork, if supplied by then | — |

**Hard dependencies.** 3 requires 1B (the Business frame must exist to host the
surface). 4 requires 3 and 5 — funds must exist before provisioning, and the
provider mechanism must be chosen. 6 requires 4 (there must be an identity to
resolve). 7 requires 6 (recipes must not ask for a sender). 8 requires 7
(recipes are the consumer). 9 requires 3 (the abstraction it demotes into).

**May run in parallel:** 1A ∥ 1B ∥ 2; 3 ∥ 5.

**1A and 1B are deliberately separated** so that capacity work is not held up by
navigation work, and so §7.6's atomicity rule has a single owning slice. They may
ship in either order.

### 21.1 Per-slice entry and exit criteria

| Slice | May start when | Is complete when |
|---|---|---|
| **1A** | Now | Every §7.3, §7.3a and §7.4 case behaves exactly as tabulated; archived locations consume no capacity and paid slots are reusable while grandfathered excess is not (§7.5.3); the additive migration applies once with an idempotent backfill and a fail-closed conditional `down()` (§23.3); **no deployable state permits unlimited Core/Growth locations**; T-LOC-1..16 and T-BIZ-1..2 pass |
| **1B** | Now | The six §9.3 experiences see their own navigation; every menu target resolves and is authorized; view-as audits, expires and cannot widen authorization; T-CTX-1..5, T-VIEW-1..4, T-NAV-1..3 pass |
| **2** | Now | Auth screens carry no `login-v2*.svg` fallback and render a neutral AI Business OS identity; no page renders `locale.`; T-AUTH-1..2, T-I18N-1..2 pass. **Custom illustration assets are explicitly NOT required** — if §28.6 artwork is unavailable, the neutral panel satisfies this slice in full. |
| **3** | 1B complete **and** §28.3 recorded — *except* the design-only work permitted by §21.2 | The abstraction carries a real recorded mechanism; per-Business isolation holds; no customer role can read a credential; BYO is relocated; measurement meters exist with **no retail rate activated**; T-PROV-1..2, T-BYO-1..2 pass |
| **4** | 3 and 5 complete **and** §28.3, §28.4 and §28.1 all recorded | A number is acquired only after funds are reserved; renewal, grace and release behave per §13; T-PHONE-1..4 pass |
| **5** | 1B complete | $5 floor enforced; presets configure; caps hold at the exact boundary; the unchanged payer is a true no-op; no raw feature key renders; T-WALLET-1..3, T-CAP-1..5, T-PAYER-1..4 pass |
| **6** | 4 complete | An automation with no sender resolves the default identity; with none, it fails closed making zero provider calls; T-SENDER-1..2 pass |
| **7** | 6 complete | Every visible recipe creates a runnable automation; unbacked recipes are absent; T-AUTO-1..6, T-STOP-1..2 pass |
| **8** | 7 complete | Each new producer is transactional with an outbox; redelivery is idempotent; T-EVENT-1..4 pass |
| **9** | 3 complete | BYO never debits the wallet for transport; a zero-balance BYO Business still sends; T-BYO-1..4 pass |
| **10** | All others complete | T-A11Y-1..3 pass and the full §24 matrix passes as a regression |

### 21.2 What may be built before a blocking gate clears

Slice 3 is gated on §28.3, but need not be idle. Permitted **before** the
mechanism is recorded:

* the provider-agnostic interface (send, number search, number order,
  registration submit, status callback) expressed in **platform** vocabulary;
* a deterministic in-memory fake implementing that interface, in the house
  pattern already used by `FakeGoogleBusinessProfileReadClient`;
* the per-Business isolation and credential-custody rules as tests against the
  fake;
* the measurement meters and the BYO relocation.

**Forbidden before the gate clears:** any interface shape, column, enum value,
migration or test that encodes an assumed Telnyx account structure — no
sub-account identifier, no messaging-profile identifier, no connection
identifier, and no assumption about which entity owns a number. If the recorded
mechanism later differs, nothing built under this clause may need reshaping.

**Separately: no retail telecom rate may be activated** in any slice until
§28.1 is approved. `setActiveRate()` is not called for a telecom meter before
then. Measurement without a rate is permitted and is what Slice 3 ships.

---

## 22. PER-SLICE PATH BOUNDARIES

Each slice's contract must publish an exact allowlist. Every slice below carries
**implementation paths, test paths and documentation paths**, because §25
requires all three of every slice. An allowlist that forbids a change the slice's
own acceptance criteria demand is a defect; §22.2 records the reconciliation.

### 22.1 Allowlists

| Slice | Implementation paths | Test paths | Documentation paths |
|---|---|---|---|
| **1A** | `app/Library/Entitlement/EntitlementManager.php` (**additive location-capacity methods only** — existing `decide()`/`decideBusinessSlotCapacity()` semantics unchanged), `app/DTO/Entitlement/**`, `app/Enums/Entitlement/**`, `app/Enums/Business/BusinessLocationLifecycleState.php` (new), `app/Models/{WorkspacePlanCatalog,Business,BusinessLocation}.php`, `app/Repositories/Contracts/BusinessLocationRepository.php`, `app/Repositories/Eloquent/EloquentBusinessLocationRepository.php`, `app/Http/Controllers/Customer/Business/BusinessLocationsController.php` (new), `app/Http/Controllers/Customer/BusinessOnboardingController.php` (delegate onto the §7.3b boundary only), `app/Library/Business/BusinessLocationManager.php` (new), `app/Http/Requests/Business/UpsertBusinessLocationRequest.php`, `app/Http/Requests/Business/StoreBusinessLocationRequest.php` (new), `app/Http/Requests/Business/ArchiveBusinessLocationRequest.php` (new), `app/Exceptions/Entitlement/**`, `app/Events/Entitlement/**`, `resources/views/customer/business/locations/**` (new), `routes/customer.php`, `database/migrations/**` (additive only — see §23.2) | `tests/Feature/Entitlement/**`, `tests/Unit/Entitlement/**`, `tests/Feature/Business/**` | `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md`, `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md`, `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`, the slice's own contract under `docs/automation/**` |
| **1B** | `app/Library/Navigation/**` (new), `app/Library/ViewAs/**` (new), `app/Providers/{MenuServiceProvider,AppServiceProvider}.php`, `app/Helpers/Helper.php` (customer menu branch only), `app/Http/Middleware/**` (context resolution, view-as), `app/Http/Kernel.php` (middleware registration only), `app/Models/ViewAsSession.php` (new), `app/Policies/**`, `resources/views/panels/{sidebar,submenu,navbar,breadcrumb}.blade.php`, `resources/views/components/**`, `routes/customer.php`, `database/migrations/**` (view-as audit table); **Correction Round 1 (narrow):** `app/Http/Controllers/Customer/Workspace/WorkspaceController.php` (account-frame access gate for `index()`/`show()` only) and `resources/views/customer/workspaces/{index,show}.blade.php` (account vocabulary only) — the direct-route leak fix of §5.4 | `tests/Feature/Workspace/**`, `tests/Feature/Security/**`, `tests/Feature/DesignSystem/**`; **Correction Round 1 (narrow):** exactly `tests/Feature/Analytics/AnalyticsCampaignTest.php` and `tests/Feature/Analytics/AnalyticsViewTest.php` (the two assertions that encoded the pre-1B static navigation; no other `tests/Feature/Analytics/**` path) | `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`, the slice's own contract |
| **2** | `resources/views/auth/**`, `resources/views/layouts/**`, `resources/views/components/branding-illustration.blade.php`, `app/Library/Branding/**`, `resources/lang/en/locale.php`, `public/images/branding/**` (new assets), `resources/sass/**` | `tests/Feature/Auth/**`, `tests/Feature/Branding/**`, `tests/Feature/Theme/**` | `docs/automation/DESIGN-SYSTEM-M2-*`, the slice's own contract |
| **3** | `app/Library/Messaging/**` (new), `app/Library/Messaging/Contracts/**` (new), `app/Models/BusinessMessagingIdentity.php` (new), `app/Http/Controllers/Customer/Business/MessagingChannelsController.php`, `app/Enums/Messaging/**` (new), `resources/views/customer/business/MessagingChannels/**`, `resources/views/customer/settings/advanced/**` (new), `config/services.php`, `config/messaging.php` (new), `app/Providers/AppServiceProvider.php` (binding only), `database/migrations/**` | `tests/Feature/Messaging/**` (new), `tests/Feature/Security/**`, `tests/Feature/Usage/**` | the slice's own contract; **restate the superseded B2 docblock rules** (§27 C-5) |
| **4** | `app/Library/Telephony/**` (new), `app/Http/Controllers/Customer/Business/BusinessPhoneController.php` (new), `app/Http/Requests/Customer/Business/**`, `app/Jobs/Telephony/**` (new), `app/Notifications/**`, `app/Console/Commands/**` (renewal sweep), `app/Enums/Telephony/**` (new), `resources/views/customer/business/phone/**` (new), `database/migrations/**` | `tests/Feature/Telephony/**` (new), `tests/Feature/Usage/**` | the slice's own contract |
| **5** | `app/Http/Requests/Customer/Business/**`, `app/Library/Usage/{BillingProfileManager,UsageWalletManager}.php`, `app/Http/Controllers/Customer/Business/UsageBilling*.php`, `app/Models/{BusinessUsageWallet,BusinessPayerAssignment}.php`, `app/Notifications/**`, `app/Console/Commands/**` (threshold alerts), `resources/views/customer/business/usage-billing/**`, `resources/lang/en/locale.php`, `database/migrations/**` | `tests/Feature/Usage/**`, `tests/Unit/Usage/**` | `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` (§27 C-3), the slice's own contract |
| **6** | `app/Library/Automation/Actions/SendMessageAction.php`, `app/Http/Requests/Automations/AutomationDefinitionRequest.php`, `app/Library/Automation/AutomationDefinitionValidator.php`, `resources/views/customer/Automations/form.blade.php`, `app/Library/Messaging/**`, `database/migrations/**` (config backfill for existing automations) | `tests/Feature/Automations/**` | `docs/automation/B4-BUSINESS-AUTOMATIONS-CONTRACT.md` (§27 C-4), the slice's own contract |
| **7** | `app/Library/Automation/**`, `app/Enums/Automation/**`, `app/Models/Automation*.php`, `app/Http/Controllers/Customer/Business/AutomationsController.php`, `app/Http/Requests/Automations/**`, `app/Jobs/Automation*.php`, `app/Notifications/**`, `resources/views/customer/Automations/**`, `resources/lang/en/locale.php`, `database/migrations/**` | `tests/Feature/Automations/**`, `tests/Unit/Automation/**` (new) | `docs/automation/B4-BUSINESS-AUTOMATIONS-CONTRACT.md`, the slice's own contract |
| **8** | `app/Events/**`, `app/Library/Outbox/**` (new), `app/Jobs/Outbox/**` (new), `app/Listeners/**`, the specific producer's owning library only, `app/Providers/EventServiceProvider.php`, `database/migrations/**` | `tests/Feature/Automations/**`, `tests/Feature/Outbox/**` (new), plus the producer's own existing suite | the slice's own contract |
| **9** | `app/Http/Controllers/Customer/Business/MessagingChannelsController.php`, `app/Library/Messaging/**`, `resources/views/customer/settings/advanced/**`, `app/Models/CustomerBasedSendingServer.php`, `resources/lang/en/locale.php` | `tests/Feature/Messaging/**`, `tests/Feature/Usage/**` | the slice's own contract |
| **10** | `resources/views/**`, `resources/lang/**`, `resources/sass/**`, `public/images/branding/**` | any `tests/Feature/**` touched by a fix; the full suite as regression | any contract whose copy changed |

`database/factories/**` and `tests/**` fixture concerns are permitted in every
slice that lists a `tests/**` boundary, for the entities that slice already
touches.

### 22.2 Reconciliation — every promised behaviour has a permitted path

| Promised behaviour | Slice | Permitted by |
|---|---|---|
| Multi-location creation (§7.6) | 1A | `EloquentBusinessLocationRepository.php`, `BusinessLocationsController.php` (new), `resources/views/customer/business/locations/**` |
| Location capacity enforcement (§7.3) | 1A | `EntitlementManager.php` additive methods, `app/Exceptions/Entitlement/**` |
| Canonical location service boundary (§7.3b) | 1A | `app/Library/Business/BusinessLocationManager.php` (new), and migrating `BusinessOnboardingController::storeLocation()` onto it |
| Archive / reactivate lifecycle (§7.3a) | 1A | `app/Enums/Business/BusinessLocationLifecycleState.php` (new), `app/Models/BusinessLocation.php`, `database/migrations/**` |
| Paid-slot reuse and allocation cancellation (§7.3a rules 4–6) | 1A | `EntitlementManager.php` additive methods, `app/Models/Business.php` |
| Grandfathered counters (§7.5.2) | 1A | `app/Models/Business.php`, `database/migrations/**` |
| Additive catalog columns + backfill (§23.2) | 1A | `database/migrations/**` (additive only) |
| RFC-004 amendment upkeep (§27 C-1/C-2) | 1A | both RFC-004 documentation paths |
| View as client (§5.5) | 1B | `app/Library/ViewAs/**` (new), `ViewAsSession.php` (new), middleware, migration |
| Dead menu link fix (E-11) | 1B | `app/Helpers/Helper.php`, `app/Library/Navigation/**` |
| Translation completeness (§17.1) | 2 | `resources/lang/en/locale.php` |
| Neutral branding without artwork (§21.1) | 2 | `resources/views/auth/**`, `app/Library/Branding/**`, `resources/sass/**` |
| Measurement without a rate (§21.2) | 3 | `app/Library/Messaging/**`, `tests/Feature/Usage/**` |
| BYO relocation (§11.4) | 3 | `resources/views/customer/settings/advanced/**` |
| Renewal sweep and alerts (§13.2) | 4 | `app/Console/Commands/**`, `app/Notifications/**`, `app/Jobs/Telephony/**` |
| $5 floor and presets (§12.2) | 5 | `app/Http/Requests/Customer/Business/**` |
| Payer no-op (E-12) | 5 | `app/Library/Usage/BillingProfileManager.php` |
| Threshold alerts (§12.2) | 5 | `app/Notifications/**`, `app/Console/Commands/**` |
| Default identity resolution (§10.4) | 6 | `SendMessageAction.php`, `app/Library/Messaging/**` |
| Existing automations keep working (§10.4) | 6 | `database/migrations/**` config backfill |
| Quiet hours (§14.4) | 7 | `app/Library/Automation/**`, `database/migrations/**` |
| Outbox (§16) | 8 | `app/Library/Outbox/**`, `app/Jobs/Outbox/**` |
| BYO never debits transport (§11.5) | 9 | `app/Library/Messaging/**`, `tests/Feature/Usage/**` |

### 22.3 Forbidden in every slice

* `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`
  and every other **already-merged** migration — history is never edited
  (§23.2).
* `EntitlementManager`'s existing `decide()` precedence and
  `decideBusinessSlotCapacity()` semantics — Slice 1A adds alongside them, it
  does not alter them.
* RFC-005 ledger invariants (immutability, idempotency, negative-balance
  prevention).
* `app/Http/Controllers/Admin/**` and the admin menu branch of
  `app/Helpers/Helper.php`, except where a slice explicitly lists them.
* `public_html`, the preview worktree, and any other lane's worktree.
* Activating a retail telecom rate before §28.1 (§21.2).

---

## 23. MIGRATION AND ROLLBACK EXPECTATIONS

### 23.1 General

* Every migration is additive and reversible, and its `down()` is described in
  its docblock.
* No migration deletes customer data. No migration releases a phone number.
* **Backfill logic** is idempotent and safe to invoke again. This is a property
  of the backfill routine only — a migration itself runs once under the
  `migrations` table (§23.3).
* A slice that adds a state machine seeds existing rows into the state that
  produces **zero external cost** (`available`, never `active`).
* The navigation change is behaviour-only and requires no migration; it must be
  revertible by reverting one commit.
* Rolling back a messaging slice must never orphan a provisioned number: the
  provider resource record survives an application rollback and is reconciled
  forward.

### 23.2 The Slice 1A capacity migration — additive, never a history rewrite

**Already-merged migrations are historical and are never edited.** In
particular `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`
correctly records what Milestone 1 seeded and must remain byte-identical. The
capacity correction ships as **one new additive migration** plus its backfill.

**`up()` — in this order:**

1. Add the four physical-location columns to `workspace_plan_catalog`:
   `location_slot_included` (unsigned tiny int, default 3),
   `location_slot_max` (unsigned tiny int, nullable),
   `unlimited_location_slots` (boolean, default `false`),
   `additional_location_slot_price_ratio` (decimal 6,4, nullable).
   Every one is nullable or defaulted, so the change cannot fail on existing
   rows.
2. Add the per-**Business** capacity counters, held per Business rather than per
   Workspace because the location limit is per Business:
   `businesses.additional_location_slots` (unsigned tiny int, default 0 — paid,
   reusable) and `businesses.grandfathered_location_slots` (unsigned tiny int,
   default 0 — complimentary, non-reusable, §7.5.3).
2a. **Add the location lifecycle column, which does not exist today** (§7.3a):
   `business_locations.lifecycle_state` (string 16, **default `active`**, indexed
   with `business_id`). Every existing row therefore becomes `active` with no
   data change, which is exactly the pre-migration meaning. Archiving sets it to
   `archived`. A nullable `archived_at` timestamp accompanies it for audit.
   This is deliberately **not** `SoftDeletes`: a soft-deleted row would vanish
   from default queries and from the `business_google_locations` relationship,
   whereas an archived location must stay fully readable as history.
   It ships in **this same migration**, because capacity counts active rows
   (§7.3) and the enforcement introduced by step 5 would otherwise have no
   column to count.
3. Set catalog values: Core and Growth `location_slot_included = 3`,
   `location_slot_max = 5`, `additional_location_slot_price_ratio = 0.5000`,
   `unlimited_location_slots = false`; Agency `unlimited_location_slots = true`,
   `location_slot_max = null`, ratio `null`.
4. Set Core and Growth `business_slot_included = 1`. `business_slot_max`
   becomes inapplicable for these tiers and is set to `1`, because Core/Growth
   no longer offer additional Business slots at any price (§7.4).
   Agency is untouched.
5. **Grandfather before any tightening can bite** (§7.5): for every Workspace
   whose current Business count exceeds its corrected capacity, and every
   Business whose **active** location count exceeds `location_slot_included`,
   set `businesses.grandfathered_location_slots` to that exact excess and write
   one durable `workspace_entitlement_transitions` row whose immutable payload
   names every affected Business and its exact grandfathered count (§7.5.2).
   Nothing is deleted, deactivated or archived.
6. Add the location-allocation transition type to the existing transition
   vocabulary. No new audit table.

### 23.3 Migration semantics — stated in exact Laravel terms

The Round 1 wording ("idempotent and re-runnable") was loose. Corrected:

* **A migration runs once**, governed by the `migrations` table. Laravel does
  not re-run an applied migration, and this contract does **not** claim the
  whole `up()` is re-runnable. The DDL steps (1, 2, 2a) are ordinary
  `Schema::table()` additions and would fail on a second execution.
* **The backfill logic (step 5) must be idempotent** if invoked again by other
  means — a console command, a repair routine, or a rollback-then-reapply. It
  recomputes counts from current active-location data and writes no duplicate
  transition row. That is the only idempotency claimed.
* **`down()` reverses only what this migration introduced**: it drops
  `location_slot_included`, `location_slot_max`, `unlimited_location_slots`,
  `additional_location_slot_price_ratio`,
  `businesses.additional_location_slots`,
  `businesses.grandfathered_location_slots`,
  `business_locations.lifecycle_state` and `business_locations.archived_at`,
  and removes the transition type it added.

**Rollback of the Core/Growth Business-capacity values is conditional, and
fails closed.** `workspace_plan_catalog` is **operator-editable** — RFC-004
§12.5 and the M1 seed deliberately leave `price`/`currency_id` for an operator
to set, and `updateCatalogPricing()` exists as an authoritative admin mutation.
A blind `down()` restoring `business_slot_included = 3` / `business_slot_max = 5`
could therefore overwrite a newer, deliberate operator value.

`down()` must therefore use a **compare-and-swap against the historical value
this migration itself wrote**:

* if Core/Growth `business_slot_included` is still exactly `1` and
  `business_slot_max` is still exactly `1` — the values this migration set —
  restore `3` and `5`;
* if either differs, **abort the rollback with a clear error** naming the tier
  and the unexpected value, and change nothing. The operator resolves it
  deliberately.

Never silently overwrite an operator edit, and never guess which value was
intended.

**Archived-location rollback.** Dropping `lifecycle_state` in `down()` loses the
active/archived distinction. If any row is `archived` at rollback time, `down()`
**fails closed** with an error naming the affected Businesses, because silently
resurrecting archived locations as active could push a Business over capacity
and, on Core/Growth, past a paid allocation it no longer holds. The operator
archives-or-deletes deliberately first.

**Properties.** Makes no provider call; touches no wallet, phone number, GBP
binding or website; leaves every existing Business and location fully accessible
(T-LOC-7, T-LOC-8); and both failure modes above are non-destructive.

**Ordering constraint.** This migration and the customer-reachable
second-location creation path ship in the **same release** (§7.6). Deploying the
creation path first would open the unlimited-location gap this correction exists
to close.

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
| **T-LOC-1** | Locations 1–3 are created freely on Core/Growth with no allocation and no charge |
| **T-LOC-2** | Location 4 is denied with `location_slot_allocation_required`; after an allocation it succeeds |
| **T-LOC-3** | Location 5 behaves identically against a second allocation |
| **T-LOC-4** | Location 6 is denied with `location_slot_limit_exceeded` and **no allocation can raise it** |
| **T-LOC-5** | An Agency Business creates locations without limit |
| **T-LOC-6** | Two concurrent creations cannot both consume the last location slot |
| **T-LOC-7** | Downgrading below the current location count retains **every** existing location and denies only new creation |
| **T-LOC-8** | After the §23.2 backfill, every pre-existing Business and location remains reachable, and an over-capacity Business owes nothing retroactively |
| **T-LOC-9** | **Source-boundary inventory.** Every production location-count-increasing write seam is inventoried; the test fails when a new unapproved seam appears (§7.3b). It guards repository architecture — it does **not** prove arbitrary future code cannot write to the database directly. |
| **T-LOC-10** | Every currently reachable create route and reactivate route delegates to the canonical service and is refused when capacity is exhausted — asserted behaviourally, route by route |
| **T-LOC-11** | Archiving a location frees exactly one active slot; a replacement location may then be created against the **same** paid allocation without re-purchase |
| **T-LOC-12** | Reactivating an archived location runs the full capacity check and is refused when capacity is exhausted |
| **T-LOC-13** | Cancelling a paid allocation is refused while the active-location count would exceed post-cancellation capacity, and permitted once it fits |
| **T-LOC-14** | The primary location cannot be archived while primary; reassignment and archival happen in one transaction; the last active location cannot be archived |
| **T-LOC-15** | Archiving deletes nothing — the row, its GBP binding, analytics and audit history all survive, and no phone number is released |
| **T-LOC-16** | Archiving a **grandfathered excess** location decrements `grandfathered_location_slots` and does **not** yield a reusable free slot; archiving a **paid** location leaves `additional_location_slots` untouched |
| **T-BIZ-1** | A second Business on Core/Growth is denied with `business_slot_limit_exceeded` |
| **T-BIZ-2** | A Workspace already holding several Businesses keeps them all after the backfill and is denied only new creation |
| **T-BYO-1** | A BYO **transport** send takes **no reservation** and produces **no wallet debit** |
| **T-BYO-2** | A BYO send is still recorded against the telecom usage meter for measurement, at a zero rate, with a `byo` transport marker |
| **T-BYO-3** | A Business with a zero balance can still send BYO transport, and neither spending cap is consumed |
| **T-BYO-4** | An automation cost estimate on a BYO Business itemises **$0.00 transport** and states the provider bills directly |
| **T-BYO-5** | **BYO does not exempt unrelated paid platform operations.** A paid AI generation, email delivery, paid lookup, image generation or storage operation on a BYO Business reserves and debits **normally**, and is **blocked** on a zero balance exactly as on a managed Business. |
| **T-BYO-6** | A recipe combining BYO SMS with a paid AI action shows **both** lines — $0.00 transport and the separately calculated AI cost — and a **non-zero** total |
| **T-FUND-1** | A setup whose upfront total is **below** $5 still requires the $5 manual top-up minimum |
| **T-FUND-2** | A setup whose upfront total **exceeds** $5 requires the full itemised total, not $5 |
| **T-FUND-3** | A Business with a sufficient available balance is **not** asked to top up, and provisioning proceeds |
| **T-FUND-4** | A Business with an insufficient balance is asked for the shortfall and cannot proceed until it is funded |
| **T-FUND-5** | A provider price increase between estimate and reservation returns the customer to a fresh itemised estimate and never silently reserves more than was shown |
| **T-FUND-6** | **Zero provider calls occur before a successful reservation**, at every abandonment point in the flow |
| **T-WALLET-4** | The four fixed presets configure exactly; a custom amount below the approved minimum or above the approved maximum is refused |
| **T-WALLET-5** | Auto-recharge is **off** until the payer explicitly enables it; no automatic charge occurs before that |
| **T-WALLET-6** | The monthly Business ceiling and the Workspace aggregate monthly ceiling each stop further automatic recharges at their exact boundary |
| **T-A11Y-1** | Every §17.2 control is keyboard reachable with a visible focus state |
| **T-A11Y-2** | Colour is never the sole carrier of meaning for balance, automation or number state |
| **T-A11Y-3** | The view-as banner is announced to assistive technology |
| **T-SCOPE-1** | No customer-facing surface in any slice offers, prices, provisions or meters a voice call (§10.5) |

### 24.1 Test ownership — every test has exactly one owning slice

The owning slice is the one that must **first make the test pass**. A later
slice may extend a test's fixtures but never inherits ownership.

| Slice | Owns |
|---|---|
| **1A** | T-LOC-1..16, T-BIZ-1..2, T-CTX-4, T-COST-2 |
| **1B** | T-CTX-1..3, T-CTX-5, T-VIEW-1..4, T-NAV-1..3 |
| **2** | T-AUTH-1..2, T-I18N-1..2 |
| **3** | T-PROV-1..2, T-BYO-1..2, T-SCOPE-1 |
| **4** | T-PHONE-1..4, T-FUND-1..6, T-COST-3, T-INTEG-1 |
| **5** | T-PAYER-1..4, T-WALLET-1..6, T-CAP-1..5, T-TRIAL-1, T-COST-4, T-COST-9, T-COST-10 |
| **6** | T-SENDER-1..2 |
| **7** | T-AUTO-1..6, T-STOP-1..2, T-COST-7 |
| **8** | T-EVENT-1..4 |
| **9** | T-BYO-3..6 |
| **10** | T-A11Y-1..3 |
| **First slice that ships a meter** (3) | T-COST-1, T-COST-5, T-COST-6, T-COST-8 |

**Gated tests — never demanded of an earlier slice.** These depend on a decision
that is not this contract's to make, and are marked *gated* in the slice that
owns them. A gated test is written against the slice's fake and is promoted to a
release requirement only when its gate clears:

| Test | Gate | Owning slice |
|---|---|---|
| T-PHONE-1..4 | §28.3 mechanism, §28.4 countries/compliance, §28.1a rate policy | 4 |
| T-FUND-1..6 | §28.3 mechanism and §28.1a rate policy — the itemised estimate needs real retail rates. Written against the §21.2 fake with fixture rates until then. | 4 |
| T-PROV-1..2 | §28.3 mechanism (assertions run against the §21.2 fake until then) | 3 |
| T-CAP-1..2 exact-boundary values in retail currency | §28.1a rate policy | 5 |
| T-COST-9 (retail vs provider cost display) | §28.1a rate policy | 5 |
| T-WALLET-4 (custom-amount bounds) | §28.9 auto-recharge bounds. Until approved, Slice 5 ships the four presets with custom entry **disabled**, and T-WALLET-4 asserts that the disabled state is enforced server-side. | 5 |
| T-WALLET-6 (ceiling maxima) | §28.9 platform hard maxima | 5 |
| Custom-artwork visual assertions | §28.6 assets supplied | 10 |

**T-WALLET-5 is deliberately not gated:** off-by-default is a safety property,
not a financial policy, and Slice 5 must satisfy it regardless.

**T-AUTH-1 is deliberately not gated:** it asserts the absence of the
`login-v2*.svg` fallback and the presence of a neutral AI Business OS identity,
both of which Slice 2 can satisfy without any commissioned artwork (§21.1).

**Global regression.** Slice 10 additionally runs the complete §24 matrix. Every
other slice runs its own subset plus the suites its allowlist touches.

---

## 25. ACCEPTANCE CRITERIA

A slice is complete when it meets its §21.1 exit criteria **and** all of:

1. Every test it **owns** in §24.1 passes, with exact counts reported. A gated
   test (§24.1) is written and passing against the slice's fake; it becomes a
   release requirement only once its gate clears.
2. No customer-facing string contains an internal identifier, key, class name or
   classification value.
3. No rendered page contains `locale.`.
4. Every navigation target resolves and is authorized.
5. No new provider call path exists without an authorization + budget check.
6. Every new event has a real producer, or does not exist.
7. Documentation is updated in the same commit as the behaviour change.
8. The six §9.3 experiences have each been exercised.
9. `git diff --check` is clean and the changed-path list matches the slice
   allowlist exactly (§22.1), including its test and documentation paths.
10. No already-merged migration was edited (§23.2).
11. No retail telecom rate was activated ahead of §28.1 (§21.2).
12. For Slice 1A specifically: **the release contains both the location-creation
    path and location-capacity enforcement, or neither** (§7.6).

---

## 26. EXPLICIT EXCLUSIONS

Not authorized by this contract:

* Any product code.
* **Editing any already-merged migration**, including
  `2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`. The capacity
  correction is delivered additively (§23.2).
* Inventing Core or Growth retail prices (§28.1 remains open).
* Inventing a platform fee on BYO transport (§11.5).
* Voice/calling in any form — offered, priced, provisioned or metered (§10.5).
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
| **C-1** | `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md` | **DONE — authorized and applied in this branch.** §2's "Business/location slot capacity" phrase conflated two entities and is corrected in place; a **v1.4 revision note** and a new **§33 Amendment 3** now separate Business/client-account capacity (Core 1, Growth 1, Agency unlimited) from physical-location capacity (3 included, 4–5 at 50%, 6+ requires Agency, Agency unlimited), state which existing catalog columns keep which meaning, specify the additive representation, and lock the grandfathering rule. | No — resolved |
| **C-2** | `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md` | **DONE — authorized and applied in this branch.** §6's seed table is relabelled so every value reads explicitly as a `business_slot_*` value, and carries an amendment note pointing at RFC-004 §33; §13's 4th-Business smoke check is annotated as deployed-behaviour-only with its corrected successor stated. The migration itself is untouched. | No — resolved |
| **C-3** | `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` | Add: $5 minimum top-up; the four auto-recharge presets; the Workspace aggregate cap; the emergency kill switch; the payer no-op rule; telecom meters as first-class; and the §11.5 **measurement-versus-wallet-debit** distinction, so a metered event is never assumed to imply a debit | No |
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
| **28.1** | Core and Growth retail prices | Set both before the first paid 4th location is sold | The catalog seeds `price = null` (E-7) and RFC-004 deliberately refused to invent prices. Does **not** gate Slice 1A: the 50% location charge is stored as a ratio and simply cannot be *collected* until a price exists. |
| **28.1a** | **The telecom retail rate policy — a complete rate card, not a single markup number.** Round 1 recommended "30% over provider cost, floor-rounded to the cent". That is **withdrawn as executable behaviour**: flooring can charge *less* than the intended markup, and a sub-cent per-message provider cost floored to the cent can round to **zero**, giving the transport away. The approved policy must state **all** of: (a) percentage or fixed markup, or a hybrid; (b) **minimum per-message retail price**; (c) decimal precision carried internally; (d) rounding direction; (e) destination and carrier surcharges; (f) MMS segmentation and attachment handling; (g) taxes; (h) effective dates; (i) immutable historical rate attribution; (j) what happens when provider pricing changes **after** a reservation but **before** settlement | Carry more precision internally than the displayed cent and **round toward the platform (ceiling) at the supported retail precision**, never floor; enforce an explicit minimum per-message retail price so no message is ever free; attribute every settled ledger entry to the exact rate row in force when it was **reserved**, so a mid-flight provider price change never retroactively repriced a completed operation. **The exact rate card stays owner-gated** — this row recommends the *shape* of the policy, not the numbers. | Flooring is the wrong direction for a per-unit resale price and interacts badly with sub-cent SMS costs. Attributing to the reservation-time rate is the only rule consistent with RFC-005's immutable ledger and with §10.6's estimate → reserve → settle sequence. **Gates:** **no retail telecom rate may be activated in any slice until this is approved** (§21.2); Slice 4 cannot provision (§21); T-CAP-1..2 boundary values and T-COST-9 are gated on it (§24.1). It does not gate BYO, which applies no retail transport rate (§11.5). |
| **28.2** | Number-renewal grace period, and view-as TTL | Grace **14 days**; view-as TTL **60 minutes** | 14 days spans a missed payment plus a weekend and a support cycle without a month of free rental. 60 minutes is long enough for real support work and short enough to bound an unattended session. |
| **28.3** | Whether managed Telnyx sub-accounts, a single platform account with per-Business messaging profiles, or another mechanism best matches provider terms | **Blocked pending provider-terms confirmation.** Design Slice 3 against the §11.2 isolation requirements so the mechanism is swappable. | The repository provides zero evidence of Telnyx capability (§11.1). Committing to a mechanism before confirming terms risks a rewrite of the whole messaging foundation. **Gates:** blocks all provider-specific work in Slice 3 and all of Slice 4; §21.2 defines exactly what may be built beforehand and forbids encoding any assumed account structure. Must be **recorded in writing** — the chosen mechanism, the provider terms relied on, and the date — before the gate is treated as cleared. |
| **28.4** | Initial countries and number types | **US and Canada, local long-code only**, at launch | Matches the existing `+1` normalization in `app/Library/AgencyProspecting/AgencyProspectPhoneNormalizer.php` and confines the compliance surface to one registration regime. **Gates:** Slice 4 cannot provision a number until the launch countries *and* their compliance/registration scope are recorded. |
| **28.5** | Advanced / BYO eligibility | Agency tier **and** an explicit owner-granted flag; migrations only | Keeps the support boundary intact while honouring genuine agency migrations. |
| **28.6** | Final visual design direction and assets | Ship Slice 2 on a **neutral typographic auth panel**; commission the AI Business OS illustration set in parallel and drop it in during Slice 10 | Slice 2 must not be blocked on artwork. **Gates nothing in Slice 2:** removing the Vuexy fallback and rendering a neutral AI Business OS identity fully satisfies T-AUTH-1, so custom artwork is explicitly **not** an acceptance requirement (§21.1). Only the custom-artwork visual assertions are gated, and they belong to Slice 10 (§24.1). |
| **28.7** | Exact automation recipe copy | Draft from §14.2 during Slice 7; owner review before publish | Copy is the product here; it should not be locked by an engineering contract. |
| **28.8** | Whether "Tag added" ships as a producer or is deferred | **Defer** until a real tag entity exists | `Contacts::getTags()` over a JSON column (E-33) is not a sound event source, and building a tag entity is its own slice. |
| **28.9** | **Auto-recharge bounds — financial policy, not an engineering default.** Specifically: (a) minimum custom auto-recharge amount; (b) maximum custom auto-recharge amount; (c) maximum Business monthly auto-recharge ceiling; (d) maximum Agency Workspace aggregate monthly ceiling; (e) whether auto-recharge is off by default | Manual top-up minimum **$5**; custom auto-recharge range **$5–$500**; auto-recharge **off by default** until the payer explicitly enables it; Business and Workspace monthly ceilings **required**, operator-configured within platform hard maxima; **the exact platform hard maxima need owner approval before Slice 5** | The contract specified `$5/$10/$25/$50` presets plus a "bounded custom amount" without ever stating the bounds — unimplementable, and not a choice engineering should make silently. $500 caps a single automated charge at a level a small business would notice but survive; off-by-default means no customer is ever charged automatically without opting in. **Gates:** Slice 5 **may** ship the four fixed presets before this clears, **provided custom entry stays disabled**. Slice 5 **may not** ship an unbounded custom amount under any circumstances. Owns T-WALLET-4..6 (§24.1). |

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
| Business location editing (primary only) | `BusinessOnboardingController::storeLocation()` | Settings → **Physical locations & service areas**, multi-location (§21 Slice 1A) |
| `impersonate()` via `parent_id` | `EloquentAccountRepository.php:2263` | **View as client** (§5.5) — new mechanism; the legacy path is retained only for the existing admin/sub-account case until Slice 1B replaces it |

---

## 30. APPENDIX B — SLICE 1B IMPLEMENTATION RECORD

**Slice 1B (§21 row 1B) is implemented** on
`agent/customer-experience-slice-1b-account-context`, from `origin/main`
`c11d3191229523bf5b63cb85b68e3b3f002e4538`. Its own contract is
`docs/automation/CUSTOMER-EXPERIENCE-SLICE-1B-ACCOUNT-CONTEXT.md`.

| Promise | Delivered by |
|---|---|
| Context resolver, frame model (§5, §8.1) | `app/Library/Navigation/CustomerContextResolver.php`, `CustomerContext.php`, `CustomerFrame.php`; `app/Http/Middleware/ResolveCustomerContext.php` (in the `web` group) |
| Authorization-driven menu builder replacing the static customer branch (§8.4) | `app/Library/Navigation/CustomerMenuBuilder.php`, `CustomerShellComposer.php`; `resources/views/panels/sidebar.blade.php`, `components/customer-nav-item.blade.php`. `Helper::menuData()['customer']` is retained as non-rendered compatibility data. |
| E-11 dead campaign link | Builder targets `customer.workspaces.businesses.outreach.campaigns`; bare `customer.outreach.campaigns.entry` (`CampaignsEntryAction`) resolves the legacy URL through the context |
| Business switcher (§9.2) | `components/customer-context-switcher.blade.php`; `POST customer.context.business.switch` (`SwitchBusinessAction`, canonical re-authorization, 404 on forgery) |
| View as client (§5.5) | `app/Library/ViewAs/**`, `app/Models/ViewAsSession.php`, migration `2026_09_10_140001_create_view_as_sessions_table.php`, routes `customer.view-as.start` / `customer.view-as.exit`, `components/view-as-banner.blade.php`, logout listener in `AppServiceProvider` |
| Raw `locale.menu.*` labels in the shell (E-10, §17.1) | The builder supplies human labels; the sidebar translates only when a key exists. `resources/lang/en/locale.php` itself remains Slice 2's. |

**Tests (§24.1 ownership):** T-CTX-1, T-CTX-2, T-CTX-5 —
`tests/Feature/Workspace/CustomerContextResolutionTest.php`; T-CTX-3,
T-NAV-1..3, T-VIEW-4 — `tests/Feature/Security/CustomerContextSecurityTest.php`;
T-VIEW-1..3 — `tests/Feature/Workspace/ViewAsClientTest.php`; shell
accessibility and translation of the touched shell —
`tests/Feature/DesignSystem/CustomerShellNavigationTest.php`.

**Recorded decisions.** (1) The navigation read model is one joined SELECT
(`CustomerContextSnapshot`) mirroring RFC-003 §14.1 for listing only, so the
shell fits inside existing per-page query budgets; every access decision
remains `WorkspaceManager::userCanAccessBusiness()`. (2) Draft/inactive
Businesses are listed but never selected or switched into. (3) With several
Workspaces and no selection the Account frame asks for an explicit choice;
visiting an account page records it as the navigation preference. (4) View-as
requires Workspace owner or active Admin (the §5.5 entry rule); the interface
offers it only on the Agency tier. (5) The View-as TTL is the §28.2 default,
60 minutes.

**Correction Round 1 (recorded).** (a) The Slice 1B allowlist is amended
narrowly (§22.1): `WorkspaceController::index()/show()` and the two account
views, for the §5.4 direct-route fix and §5.3 vocabulary only, and exactly
`tests/Feature/Analytics/{AnalyticsCampaignTest,AnalyticsViewTest}.php`, whose
stale static-navigation assertions were corrected (campaign-page budget 9,
with the single extra statement named as the shell's `CustomerContextSnapshot`
query; canonical Business-scoped Analytics URL). (b) A selected-scope member
(client or Business-scoped staff) can no longer reach the account frame by
direct URL: 404 on the overview and on Agency prospecting (the slot page was
already owner-only); no Agency identity on the account list. Owner, active
Admin and Agency-wide staff keep it; mutation actions keep their existing
authorization (the gate is the overview's and the account list's only). The
pre-existing selected-scope cases of
`tests/Feature/Workspace/{WorkspaceBusinessListHttpTest,WorkspaceBusinessReassignmentHttpTest,WorkspaceMemberManagementHttpTest}.php`
and two source needles in
`tests/Feature/DesignSystem/WorkspaceBusinessComponentAdoptionTest.php` (all
already inside the 1B test allowlist) were brought in line. A
Core/Growth owner reads "account", never "Workspace", on those pages.
(c) View-as re-validates the authoritative access chain on every request and
ends itself as `access_lost` when any link breaks. (d) View-as narrowing is a
closed classification of every authenticated customer route
(`ViewAsRouteClassification`: Prohibited / Safe / RedirectToViewed /
BusinessScoped / Denied), enforced by the middleware and mirrored by the menu;
an unclassified route fails `ViewAsRouteBoundaryTest`. (e) The prohibited
inventory is complete for every current capability family and already names
the Slice 1A `customer.workspaces.businesses.locations.allocations.*` family.

**END OF CONTRACT**
