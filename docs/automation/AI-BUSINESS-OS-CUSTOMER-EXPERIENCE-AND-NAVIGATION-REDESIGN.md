# AI BUSINESS OS — CUSTOMER EXPERIENCE, ACCOUNT HIERARCHY AND NAVIGATION REDESIGN CONTRACT

## 1. STATUS AND AUTHORITY

**Status:** Audit and contract only. **No product code, migration, configuration,
dependency or generated asset is authorized or changed by this document.** This
branch changes exactly one file: this one.

**Revision: Correction Round 1.** This round makes one substantive change of
position and one of fact.

* **Position.** The four security defects this audit discovered were originally
  recorded as observations outside the user-experience remit, with no owner and
  no schedule. That was wrong. Recording a live, unauthenticated
  payment-configuration overwrite as an aside is not neutrality; it is a
  deferral decision taken silently. They are now **§16.A Security Remediation
  Slice 0**: an explicitly owned, pre-release **release blocker** and a
  prerequisite for every other slice in §16. Nothing visual ships before it.
* **Fact.** §11.2's "three recipes are buildable today" framing is **withdrawn**
  and replaced by the three-state classification locked by the automation
  expansion contract merged at `origin/main` after this branch was cut. See
  §11.2 and §20 item 4.

Correction Round 1 also expands §5.4: re-reading `DebugController` in full for
this round found two further unauthenticated destructive routes beyond the two
originally named, one of which overwrites live payment-gateway configuration.

**Verified base:** `origin/main` at
`6c820c801da08ecfd6165d1d3a52ae6336606f0c` — `Merge pull request #219 from
os-creator1/agent/telnyx-managed-messaging-architecture-decision`, fetched with
`git fetch origin --prune`. Every path, symbol, line number, route name,
permission default and plan-packaging claim below was read at that commit in a
clean worktree created directly from that SHA. No unmerged Lane A, B, C, E or F
branch was merged, cherry-picked or consulted for evidence.

**`origin/main` has since advanced to
`98e063aabf0f8f67bc02190ce761064f8889ed22`** (`Merge pull request #220`). That
advance adds **exactly one file**,
`docs/automation/AUTOMATION-TRIGGER-EVENT-AND-GUIDED-RECIPE-EXPANSION-CONTRACT.md`,
and **changes no source, route, migration or configuration**
(`git diff --name-status 6c820c8..98e063a` returns a single `A` row). Every
code-level claim in this document therefore remains exactly true at both SHAs.
This branch is **not** rebased or merged onto the new head, per the standing
instruction; the advance is recorded here instead.

**Branch:** `agent/customer-experience-ux-redesign-contract`.

**Relationship to existing contracts.** This document is the **authoritative
customer-experience and navigation contract**. It does not replace and does not
edit:

| Document | Relationship |
|---|---|
| `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md` | **Parent.** Its §4 terminology, §5 account model, §7 capacity, §10–§13 messaging/wallet/number model, §14–§16 automations and §18–§20 invariants remain binding and are not restated here except where this document adds visible-experience detail. Where the two disagree on **customer-visible naming**, this document wins (§3, §8); on **mechanism**, the parent wins. |
| `docs/automation/PRODUCT-SURFACE-RETENTION-AUDIT.md` §12 | **Binding human decisions.** All eight are treated as settled and are relied on throughout (§9, §16). This document never reopens them. |
| `docs/automation/CUSTOMER-EXPERIENCE-SLICE-1B-ACCOUNT-CONTEXT.md`, `docs/automation/CUSTOMER-EXPERIENCE-SLICE-2-AUTH-SHELL.md` | **Delivered work.** §4 records honestly which reported defects these already fixed. |
| `docs/automation/AUTOMATION-TRIGGER-EVENT-AND-GUIDED-RECIPE-EXPANSION-CONTRACT.md` (merged at `98e063a`) | **Authority on recipe feasibility.** Its §7.0 three-state classification governs §11.2 of this document, which is rewritten to match. Where the two disagree on whether a recipe can run or ship, **that contract wins**. This document does not restate its trigger, producer or event-architecture analysis and makes no producer claim of its own. |
| `docs/automation/CUSTOMER-EXPERIENCE-SLICE-3-MESSAGING-PROVIDER-FOUNDATION.md` (on the unmerged branch `agent/customer-experience-slice-3-messaging-provider-contract`) | **Owns provider relocation.** Its §4.7 already specifies moving BYO credentials to Agency-only advanced settings, introducing `manage_advanced_provider`, and removing the old routes. §16.A item 3 is deliberately scoped **not** to duplicate it — see §16.A.3. |
| `docs/automation/DESIGN-SYSTEM-CONTRACT.md` and the `DESIGN-SYSTEM-M2-*` set | **Design substrate.** §15 reuses its tokens and components rather than proposing a second system. |
| RFC-003 / RFC-004 / RFC-005 | **Unchanged.** No tenancy, entitlement-decision or ledger semantics are altered. |

**Non-authority.** This document authorizes no implementation. Every slice in
§16 requires separate human authorization before any code is written.

---

## 2. METHOD, AND THE LIMITS OF THIS AUDIT

### 2.1 What was done

* Read `routes/customer.php` (1037 lines), `routes/auth.php`, `routes/web.php`,
  `routes/admin.php`, `routes/public.php` and
  `app/Providers/RouteServiceProvider.php` in full, and inventoried every route
  declaration by name (382 named customer declarations, 265 named admin
  declarations).
* Read the navigation stack end to end: `app/Library/Navigation/` (11 classes
  plus 2 invokable actions), `app/Providers/MenuServiceProvider.php`,
  `app/Helpers/Helper.php::menuData()`, the six files in
  `resources/views/panels/` and all five layout masters.
* Read every authentication view, the branding seam under
  `app/Library/Branding/`, the customer dashboard, and the Business-scoped
  controllers for outreach, contacts, channels, automations, analytics,
  website, knowledge profile, Google Business Profile, usage billing, wallet,
  payer, additional-business slots and Agency prospecting.
* Enumerated all 183 customer Blade views under `resources/views/customer/`.
* Cross-checked permission defaults in `config/customer-permissions.php`
  against plan packaging in
  `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`
  and
  `database/migrations/2026_09_09_120004_seed_google_business_profile_plan_packaging.php`.
* Checked translation completeness for every key the menu builders emit against
  the `menu` block of `resources/lang/en/locale.php` (lines 786–939).

### 2.2 What could not be done, and why it does not weaken the findings

**No PHP runtime is available in this environment.** `php` is not on `PATH` and
this worktree has no `vendor/` directory, so `php artisan route:list` could not
be run and no test was executed.

Every route claim below is therefore derived from **static reading of the route
files together with the group middleware, prefix and namespace applied by
`RouteServiceProvider`**, which between them fully determine each registered
name, URI and controller. Route *existence* claims are exact. Claims that a URL
returns 404 or 401 at runtime are labelled **derived** and name the code path
that produces the status, so a reviewer can confirm each with a single command.

Nothing here rests on the live preview. Where a reported symptom turned out to
be **data or configuration** rather than code, §4 says so explicitly rather than
reporting a code defect that does not exist.

### 2.3 Route group facts that every later section depends on

`app/Providers/RouteServiceProvider.php` registers:

| File | Prefix | Name prefix | Namespace | Middleware |
|---|---|---|---|---|
| `routes/customer.php` | **none** | `customer.` | `App\Http\Controllers\Customer` | `web`, `auth`, `can:access_backend`, `ValidProduct`, `twofactor` |
| `routes/auth.php` (User group) | none | `user.` | `App\Http\Controllers\User` | `auth`, `verified` |
| `routes/auth.php` (Auth group) | none | none | `App\Http\Controllers\Auth` | `web`, `twofactor` |
| `routes/admin.php` | `config('app.admin_path')` (default `admin`) | `admin.` | `App\Http\Controllers\Admin` | `web`, `auth`, `can:access backend`, `ValidProduct`, `twofactor` |

Two consequences matter for this contract. First, **customer routes are
mounted at the site root**, so `/channels`, `/outreach`, `/automations`,
`/website`, `/gbp`, `/analytics` and `/prospecting` are all top-level URLs a
customer can type. Second, **the only blanket customer authorization is
`can:access_backend`**; everything finer is a per-controller `authorize()` call,
so every claim about who can reach a surface has to be checked per method rather
than per route file.

---

## 3. LOCKED PRODUCT HIERARCHY

### 3.1 The only levels that exist

```
Platform Owner / Admin        the software operator; internal administration surface
└── Account                   technically the existing Workspace
    └── Business              a GHL-style sub-account / location / client company
        └── Location          a physical location or service area, a property of a Business
```

**No further customer-facing tenancy level may be invented.** A customer is
never shown four conceptual levels.

* **Core** and **Growth** customers have exactly one Business.
* **Agency** customers have unlimited Businesses, subject to plan capacity.

### 3.2 Naming, locked

| Internal name | Ordinary customer copy | Agency customer copy | Platform-admin copy |
|---|---|---|---|
| `Workspace` | **Account** | **Agency account** (as the frame) | Workspace |
| `Business` | **Business** | **Client account** | Business |
| `BusinessLocation` | **Location**, or *Service area* | **Location** | Business location |
| Workspace member | **Team member** | **Team member** | Member |

**The word "Workspace" must not appear in any customer-facing string** — label,
heading, page title, breadcrumb, empty state, flash message, validation message
or button — for **any** customer tier, Agency included.

This is deliberately stricter than parent §5.3, which permits Workspace wording
for Agency users. §4.5 shows that this permission is precisely what leaks the
word into the product today: the two vocabulary-aware views fall back to
"Workspaces" for exactly the Agency case. An Agency owner gains nothing from the
internal noun; "Agency account" and "Client accounts" carry the same meaning and
match how agencies already speak.

The single permitted exception is the URL path segment
`/workspaces/{workspaceUid}/…`. Renaming it is a large, risky, zero-product-value
migration and parent §8.5 already settled that it stays.

**Forbidden in customer copy** — inherited from parent §4 and extended here:
*sub-account*, *sub accounts*, *sending server*, *sender ID*, *originator*,
*messaging profile*, *connection ID*, *messaging channel* used as a provider
concept, *Account SID*, *Auth Token*, *API key*, *feature key*, *meter*,
*reservation*, *tenant*, *entitlement*, *Website Generation* (say **Website**),
*Workspace*.

### 3.3 What a Location is not

A Location is **not** an account, **not** a wallet, **not** a payer boundary,
**not** an authorization boundary and **not** a navigation tenant. It appears in
exactly one place: **Business settings → Locations**. This is a blocking
invariant, already asserted upstream as parent §18 row S-4.

---

## 4. VERIFIED DEFECT REGISTER

Each reported lead was checked against the code at the base SHA. Verdicts are
**CONFIRMED**, **PARTLY CONFIRMED**, **ALREADY FIXED** or **NOT A CODE DEFECT**.
Severity is product severity, not vulnerability severity.

### 4.1 Summary

| # | Lead | Verdict | Severity |
|---|---|---|---|
| D-1 | Login uses the inherited Ultimate SMS / Vuexy illustration | **ALREADY FIXED** (Slice 2) | — |
| D-2 | Dashboard is still the purchased product with a new logo | **CONFIRMED** | Blocker |
| D-3 | Raw `locale.menu.*` labels render | **PARTLY CONFIRMED** | High |
| D-4 | Legacy footer/copyright and placeholder titles | **NOT A CODE DEFECT** (footer) plus **CONFIRMED** (titles) | Medium |
| D-5 | Customer cannot tell Account from Agency from Business | **PARTLY CONFIRMED** | High |
| D-6 | Sidebar mixes global, Account and Business destinations | **ALREADY FIXED** for the vertical sidebar, **CONFIRMED** elsewhere | High |
| D-7 | "Workspaces" visible where ordinary users should read Account | **CONFIRMED** | Blocker |
| D-8 | Campaign navigation 404 through `/outreach/campaigns` | **ALREADY FIXED** (Slice 1B) | — |
| D-9 | Channels exposes Twilio/Telnyx choices and credential forms | **CONFIRMED** | Blocker |
| D-10 | "Sender ID", "sending server", "messaging channel" surfaced | **CONFIRMED** | Blocker |
| D-11 | Automations expose technical dropdowns without outcomes | **CONFIRMED** | Blocker |
| D-12 | Usage & Billing places payer controls in the Business surface | **CONFIRMED** | Blocker |
| D-13 | "Update payer" reports success with no visible change | **CONFIRMED** | High |
| D-14 | Pricing/subscription pages can be empty or misleading | **CONFIRMED** | High |
| D-15 | Empty states describe implementation concepts | **CONFIRMED** | High |
| D-16 | Platform, Account and Business settings are not separated | **CONFIRMED** | Blocker |
| D-17 | Controls offered to roles that should see read-only state | **CONFIRMED** | High |
| D-18 | Mobile and accessibility not reviewed as one experience | **CONFIRMED** | High |
| D-19 | Dashboard invoice tile counts other tenants' invoices | **NEW — CONFIRMED** | Blocker |
| D-20 | Menu offers a feature the plan excludes; refusal is a raw 404 | **NEW — CONFIRMED** | High |
| D-21 | Raw platform environment-variable names shown to customers | **NEW — CONFIRMED** | High |
| D-22 | View-as-client banner disappears in some layout branches | **NEW — CONFIRMED** | High |
| D-23 | No loading-state primitive exists anywhere | **NEW — CONFIRMED** | Medium |
| D-24 | Five unauthenticated destructive debug routes in `routes/web.php`, one of which overwrites live payment configuration | **NEW — CONFIRMED** | **Release blocker — Slice 0 §16.A.1** |

D-19 through D-24 were not in the brief's lead list. They were found by the
mechanical audit the brief required and are reported rather than filtered out.

**Correction Round 1 — security ownership.** Four of the confirmed defects are
security defects, not user-experience defects. The first version of this
document recorded them as observations outside its remit. They are now owned,
scheduled and blocking:

| Defect | Security failure | Owner |
|---|---|---|
| D-24 | Unauthenticated destructive writes and payment reconfiguration by `GET` | **§16.A.1** |
| D-19 | Cross-tenant invoice count rendered to a customer | **§16.A.2** |
| D-9 | Provider credential surface where menu visibility is the only boundary | **§16.A.3** |
| D-21 | Operator configuration leaked into customer responses | **§16.A.4** |

**§16.A Security Remediation Slice 0 is a release blocker and a prerequisite
for every other slice in §16.** No navigation, dashboard, settings or visual
work begins until it exits. The remaining defects in this register stay
user-experience work and keep their original slices.

### 4.2 D-1 — Login artwork. ALREADY FIXED

`resources/views/auth/login.blade.php` renders
`<x-branding-illustration surface="auth">`, and
`resources/views/components/branding-illustration.blade.php` resolves through
`App\Library\Branding\AuthBrandPresenter` to an authorized Agency white-label
brand, the owner's configured illustration, or a **neutral typographic panel
with no image at all**. Searching `resources/views/auth/` for `login-v2`,
`forgot-password-v2`, `reset-password-v2`, `create-account.svg` and
`two-steps-verification` returns only explanatory comments across the eight
audited authentication screens.

Residual inherited artwork does remain, outside those eight screens:

| Path | Asset | Who sees it |
|---|---|---|
| `resources/views/auth/payment/authorize_net.blade.php` and its six siblings | `images/pages/create-account.svg` | A registering customer, at checkout |
| `resources/views/customer/Contacts/subscribe_form.blade.php` lines 38 and 41; `unsubscribe_form.blade.php` lines 31 and 34 | `images/pages/reset-password-v2*.svg` | **The customer's own contacts**, on the public opt-in and opt-out pages |
| `resources/views/errors/401.blade.php` and its six siblings | `images/pages/error*.svg` | Everyone, on every error |

The opt-in and opt-out pages are the most damaging of the three. They are the
only AI Business OS surface a *local business's own customers* ever see, and
they currently render a password-reset illustration borrowed from a different
product.

### 4.3 D-2 and D-19 — Dashboard. CONFIRMED. Blocker

`resources/views/customer/dashboard.blade.php` (408 lines) is still the
inherited product dashboard.

* Line 8 loads `css/base/pages/dashboard-ecommerce.css`, the Vuexy e-commerce
  page stylesheet.
* The tiles are raw implementation counts with no interpretation: Campaigns,
  SMS templates, Contact groups, Contacts, Invoices, Blocked numbers.
* **It is Business-blind.** Line 218 runs
  `Campaigns::where('user_id', Auth::user()->id)` — scoped to the *user*, not to
  the Business the shell says you are inside. The invoice, template,
  contact-group, contact and blacklist counts are all user-scoped in the same
  way. A customer who switches Business sees the sidebar change and the numbers
  stay identical.
* **Eloquent queries execute inside the Blade template** at lines 218–220 and
  320–321, so the dashboard's query cost is invisible to the controller and
  cannot be asserted as a budget.
* The Campaigns tile displays a "not finished / total" ratio in a position and
  style that reads as a delivery rate, and means close to the opposite.
* The greeting card's three primary actions link to
  `customer.sms.quick_send`, `customer.sms.campaign_builder` and
  `customer.contacts.index` — the **legacy flat, Business-blind** routes, not
  the Business-scoped Outreach and Contacts the sidebar uses. The dashboard and
  the sidebar send the customer to two different products.

**D-19.** Line 320 reads
`Invoices::where('user_id', …)->where('status', UNPAID)->orWhere('status', PENDING)->count()`.
Laravel emits `WHERE user_id = ? AND status = 'unpaid' OR status = 'pending'`,
and SQL binds `AND` tighter than `OR`, so the second disjunct carries no user
scope. **The tile counts every pending invoice belonging to every customer on
the platform.** This is a missing pair of parentheses rather than a missing
authorization check, but the number rendered to the customer is a cross-tenant
aggregate and must be treated as a disclosure.

### 4.4 D-3 — Raw `locale.menu.*` labels. PARTLY CONFIRMED

The default customer sidebar no longer renders the static array.
`app/Providers/MenuServiceProvider.php` composes `panels.sidebar` from
`CustomerShellComposer`, and `CustomerMenuBuilder` emits literal English labels
rather than translation keys. The four keys named in the brief now exist:
`Website` (line 919), `Google Business Profile` (line 920), `Prospecting`
(line 935) and `Analytics` (line 876) in `resources/lang/en/locale.php`.

The defect is still real in three places.

1. **`locale.menu.Channels` has no translation at all.** The key is absent from
   the `menu` block while `Helper::menuData()` still emits `'name' => 'Channels'`
   at `app/Helpers/Helper.php:1013`. Any renderer of that array prints the
   literal string `locale.menu.Channels`.
2. **The horizontal layout renders the legacy array to customers.**
   `resources/views/panels/horizontalMenu.blade.php` line 40 reads
   `$menuData[1]->customer` and line 66 prints `__('locale.menu.'.$menu->name)`.
   `MenuServiceProvider` composes only `panels.sidebar`, `panels.navbar`,
   `panels.breadcrumb` and the two context components — **never**
   `panels.horizontalMenu`. `resources/views/layouts/contentLayoutMaster.blade.php`
   line 48 selects `horizontalLayoutMaster` whenever `mainLayoutType` is
   `horizontal`, a value `config/custom.php` already declares supported. In that
   layout the customer receives the entire pre-Slice-1B menu: a `Workspaces`
   entry, a raw `locale.menu.Channels` label, Business-blind links and no
   account context whatsoever.
3. **The admin sidebar prints raw keys.** `Platform Settings`, `Theme Presets`,
   `Usage Billing`, `Safety Limits`, `Provider Events` and
   `Additional Slot Agreements` are emitted by `menuData()` but absent from the
   `menu` translation block. On the customer branch of the same array,
   `Opportunities` and `Compose` are likewise missing.

### 4.5 D-4 — Footer and titles

**Footer: NOT A CODE DEFECT.** `resources/views/panels/footer.blade.php` renders
`<x-branding-footer>`, which calls
`App\Library\Branding\BrandingPresenter::footerCopyrightLine()` at line 133.
That composes `footer_company_name` — falling back to `config('app.name')`,
whose default is `AI Business OS` in `config/app.php` line 18 — with
`footer_copyright_text` and a year computed at render time. A legacy footer in
the preview means the **`app_configs` rows still hold the inherited values**.
The correction is an operator data change plus a shipped default, not a code
change. It must still be scheduled, because nothing today stops an install from
shipping the previous vendor's name in the footer.

**Titles: CONFIRMED.** Page titles are per-view `@section('title', …)` strings
with no discipline: `customer/dashboard.blade.php` yields
`locale.menu.Dashboard`, `customer/business/website/entry.blade.php` yields the
bare word `Website`, and `MessagingChannels/index.blade.php` yields
`Messaging Channels`. Slice 2 added Business and account context to the
`<title>` element in `contentLayoutMaster.blade.php`, but the visible page
heading in `panels/breadcrumb.blade.php` line 10 is still `@yield('title')`
alone, so the on-screen `<h2>` never names the Business.

### 4.6 D-5, D-6 and D-7 — Frame clarity and the word "Workspace". CONFIRMED. Blocker

The sidebar itself is correct. `panels/sidebar.blade.php` lines 60–68 print
`Business`, `Client account`, `Agency account` or `Account` above
`$customerContext->headerLabel()`, and `CustomerMenuBuilder` keeps the Account
frame and the Business frame strictly disjoint, as parent §8.1 requires.

The vocabulary helper `CustomerContext::usesBusinessVocabulary()`
(`app/Library/Navigation/CustomerContext.php` line 127) is consumed by **exactly
two views**: `customer/workspaces/index.blade.php` lines 11–14 and
`customer/workspaces/show.blade.php` lines 11–16. Everywhere else the raw word
leaks unconditionally:

| View | Copy shown to the customer |
|---|---|
| `customer/Automations/entry.blade.php` line 15 | "ask a Workspace owner to add you" |
| `customer/business/analytics/entry.blade.php` line 21 | same pattern |
| `customer/business/website/entry.blade.php` line 15 | same pattern |
| `customer/Outreach/entry.blade.php` line 15 | same pattern |
| `customer/business/MessagingChannels/entry.blade.php` line 15 | same pattern |
| `customer/business/googleBusinessProfile/entry.blade.php` line 21 | same pattern |
| `customer/business/usage-billing/show.blade.php` line 28 | "**Back to Workspace**" |
| `customer/business/usage-billing/show.blade.php` lines 165 and 177 | "billed to the Workspace", "**Workspace pays**" |
| `customer/workspace/additional-business-slots/show.blade.php` lines 11 and 38 | "Back to Workspace", "No additional-slot agreement exists yet for this Workspace." |
| `customer/workspaces/prospecting/entry.blade.php` lines 14, 15 and 19 | "No Workspace available yet", "a Workspace-level feature", "Choose a Workspace to continue" |

Even in the two vocabulary-aware views, `usesBusinessVocabulary()` returns true
**only** for Core and Growth, so an **Agency owner still reads "Workspaces" as
the page title** — which §3.2 of this contract forbids.

### 4.7 D-8 — Campaign navigation. ALREADY FIXED

`routes/customer.php` line 369 registers `GET outreach/campaigns` bound to
`App\Library\Navigation\Actions\CampaignsEntryAction`, named
`customer.outreach.campaigns.entry`. The action resolves the canonical context
and redirects to `customer.workspaces.businesses.outreach.campaigns`, or hands
over to the Outreach chooser when no Business is selected. It never guesses a
Business.

Every route name emitted by `CustomerMenuBuilder` was checked against the route
files: **all 22 exist.** The builder additionally guards with `Route::has()`
before emitting an item and refuses to emit an item whose scoped parameters are
null, so a dead sidebar link cannot be produced by that path.

### 4.8 D-9 — Provider exposure. CONFIRMED. Blocker

`app/Http/Controllers/Customer/Business/MessagingChannelsController.php` line 48
declares a customer-facing provider catalogue:

| Provider | Fields the customer is asked for |
|---|---|
| Twilio | Account SID (required), Auth Token (required) |
| Telnyx | API Key (required), Message Profile ID (required), Message Connection ID |

`resources/views/customer/business/MessagingChannels/connect.blade.php` renders
those five fields as a plain credential form.
`MessagingChannels/index.blade.php` shows the provider brand names as chooser
cards and lists "**Sender IDs**" as a section heading.

**This surface is not restricted to Agency customers.**
`CustomerMenuBuilder::advancedItems()` at line 216 hides it unless
`isAgency() && canManageWorkspace()`, but **all eight controller methods gate
only on `$this->authorize('view_numbers')` plus Business access** — lines 78,
97, 128, 147, 201, 224, 264 and 285 — and `config/customer-permissions.php`
declares `view_numbers` with `'default' => true`. A Core or Growth customer
therefore holds the permission, and `routes/customer.php` line 388
(`GET /channels`) redirects any actor with exactly one accessible Business
**straight into the credential surface**.

Menu visibility is acting as the authorization boundary. Parent §8.4 forbids
this explicitly ("visibility is never the authorization boundary") and parent
§10.2 makes hiding provider credentials a blocking invariant. **Derived
runtime claim:** a Core customer requesting `/channels` receives a 302 to
`customer.workspaces.businesses.channels.index` and then a 200 rendering the
Twilio and Telnyx cards; confirm with one request once a runtime is available.

### 4.9 D-10 — Provider jargon in the primary send flow. CONFIRMED. Blocker

`resources/views/customer/Outreach/_originator.blade.php` is included by all
four compose surfaces — `_smsQuickSend`, `_smsCampaign`, `_mmsQuickSend` and
`_mmsCampaign`. It asks the customer for:

* a **"Sending Server"** (line 13), whose options are the raw admin-assigned
  `$server->sendingServer->name` (line 17);
* an **"Originator"** (line 31), chosen between a **"Sender ID"** (lines 35 and
  60) and a phone number, with a link inviting the customer to *"Request New"*
  sender IDs.

`resources/lang/en/locale.php` renders these literally as `Sending Server`,
`Sender ID` and `Originator`. All three are on the forbidden list in §3.2. This
is the single most damaging jargon leak in the product, because it sits on the
screen every customer uses to send a message.

The same leak appears in Automations: `customer/Automations/form.blade.php`
line 122 labels a `sender_id` select **"Sender"**, and line 131 labels a
`sending_server` select **"Messaging channel"** whose options are, again, raw
sending-server names.

### 4.10 D-11 — Automations. CONFIRMED. Blocker

The only creation path is the mechanical When/Then form, and the entire
capability surface is two triggers and two actions:

| Enum | Cases |
|---|---|
| `app/Enums/Automation/AutomationTriggerType.php` | `contact_date_reached`, `contact_created` |
| `app/Enums/Automation/AutomationActionType.php` | `send_message`, `update_contact_field` |

The form offers no outcome framing, no cost estimate, no readiness check, no
statement of who will receive the messages, and no safety assessment before
activation. It also requires the customer to choose a sending server and a
sender identity, per D-10.

### 4.11 D-12 and D-17 — Payer placement and role-blind controls. CONFIRMED. Blocker

`resources/views/customer/business/usage-billing/show.blade.php` is 444 lines
and contains **zero `@can`, `@canany` or equivalent role guard**. Every control
renders for every actor who can reach the Business: spend cap (line 107),
per-feature limits (line 153), **payer** (line 160), billing contact (line 204),
payment method (line 232), top-up (line 237) and auto-recharge (line 248).

The write paths *are* authorized. `BillingProfileManager::changePayer()` calls
`assertPayerConsent()`, and `UsageBillingController` line 70 catches
`UnauthorizedPayerAssignmentException` into a flash error. So this is not a
privilege escalation; it is that the interface **invites an action it will then
refuse** — exactly the "controls offered to roles that should only see an
explanation or a read-only state" defect.

Placement is wrong on its own terms as well. Parent §12.4 makes payer an
Agency-only concept, yet the control lives at
`/workspaces/{w}/businesses/{b}/usage-billing` — the **Business** surface — and
speaks in Workspace vocabulary, per D-7.

### 4.12 D-13 — No-op reported as success. CONFIRMED. High

`app/Library/Usage/BillingProfileManager.php` lines 93–131. `changePayer()`
reads `$fromPayerType` and then, **unconditionally**:

1. inserts a payer-transition audit row whose `from_payer_type` and
   `to_payer_type` are identical (line 112);
2. writes the assignment (line 123);
3. dispatches `BusinessPayerChanged` with `from === to` (line 127).

There is no equality check anywhere on the path.
`UsageBillingController` line 75 then flashes `Payer updated.`

Submitting the currently-selected payer therefore reports success, changes
nothing visible, writes a null transition into the audit history, and fires a
domain event to every listener. The same shape — unconditional write followed
by an unconditional success flash — is present in `updateSpendCap`,
`updateBillingContact` and `updateFeatureLimit`.

### 4.13 D-14 — Empty and duplicated plan surfaces. CONFIRMED. High

`SubscriptionController@index` and `AccountController@pricing` both fall through
to `Plan::where('status', 1)->where('show_in_customer', 1)->cursor()` and render
`customer/Accounts/plan.blade.php`. That view opens with a bare
`@foreach($plans->chunk(3) as $chunk)` at line 25 and has **no `@forelse`, no
`@empty` and no count guard**. With no plan marked visible to customers, the
page renders a heading and nothing beneath it: no explanation, no action, no
route to support.

There are also two separate plan surfaces in the navbar user menu — Billing
pointing at `customer.subscriptions.index` and Pricing pointing at
`user.account.pricing` — rendering partly overlapping content from the same
underlying query.

### 4.14 D-15 — Empty states. CONFIRMED. High

Two competing implementations exist, and the better one is unused:

| Component | Capability | Adoption |
|---|---|---|
| `resources/views/layouts/partials/empty-state.blade.php` (Slice 2) | `state` of empty / unconfigured / locked, `explanation`, `primary` action, `secondary` action, `ownerHint`, accessible icon labelling | **0 views** |
| `resources/views/components/empty-state.blade.php` | `icon`, `title`, `description` only; no state, no action, no owner hint, and the title is a `<p class="text-section-heading">` rather than a heading element | **32 views** |

Every real empty state in the product therefore uses the weaker component. The
copy in those states is written in implementation terms — "Automations are
organized by Business", "Website Generation is organized by Business" — and
routinely conflates *you have no access* with *your plan does not include this*
(see D-20).

### 4.15 D-16 — Settings separation. CONFIRMED. Blocker

There is no settings information architecture. Settings live in five unrelated
places:

1. the sidebar `Settings` group built by `CustomerMenuBuilder` — Business
   details, Blocked numbers, Usage & billing, Team & account, Plan &
   subscription, Advanced;
2. `customer/workspaces/show.blade.php` (547 lines) — account settings, staff,
   additional-business slots, per-Business feature toggles;
3. `customer/business/usage-billing/show.blade.php` (444 lines) — spend caps,
   feature limits, payer, billing contact, instruments, auto-recharge;
4. the navbar user menu — profile, billing, pricing, announcements and
   **"Sub Accounts"** (`panels/navbar.blade.php` line 348), which is a *second,
   parallel* team-membership system living alongside Workspace members and
   built on `users.parent_id`;
5. the platform administration area at `config('app.admin_path')`.

Nothing in the interface tells the customer which level a given setting belongs
to, and two of the five contain controls for a different level than their own.

### 4.16 D-18 — Responsive behaviour and accessibility. CONFIRMED. High

Slice 2 added a skip link and a `<main id="main-content">` landmark, but only to
two of the five layout masters:

| Layout master | Skip link | `<main>` landmark | Customer menu source |
|---|---|---|---|
| `verticalLayoutMaster` | yes, line 8 | yes, lines 35 and 49 in exclusive branches | Slice 1B builder |
| `fullLayoutMaster` | not applicable | yes, line 54 | authentication only |
| `horizontalLayoutMaster` | **no** | **no** | **legacy static array** |
| `verticalDetachedLayoutMaster` | **no** | **no** | Slice 1B builder |
| `horizontalDetachedLayoutMaster` | **no** | **no** | **legacy static array** |

Beyond the landmarks: the mobile menu trigger in `panels/navbar.blade.php`
line 28 is an `<a href="javascript:void(0)" role="button">`, which is reachable
by keyboard but does not respond to the Space key the way a real `<button>`
does. The breadcrumb at `panels/breadcrumb.blade.php` line 13 is an `<ol>` with
no `<nav>` wrapper and no accessible name. No view declares a responsive
strategy for the wide data tables that dominate Contacts, Campaigns and
Analytics.

### 4.17 D-20 — Navigation offers a feature the plan excludes; the refusal is a raw 404. NEW. CONFIRMED. High

`CustomerMenuBuilder` line 95 emits **Google Business Profile** whenever the
actor holds `view_google_business_profile`, which `config/customer-permissions.php`
declares with `'default' => true`. But
`database/migrations/2026_09_09_120004_seed_google_business_profile_plan_packaging.php`
line 43 sets `ENTITLED_TIERS = ['growth', 'agency']`, with Core deliberately
absent. `GoogleBusinessProfileController::resolveEntitledBusiness()` runs
`EntitlementManager::decide()` and calls `abort(404)` at line 286 when the
decision is not allowed.

**A Core customer therefore sees "Google Business Profile" in the sidebar and
receives the inherited Vuexy 404 page when they click it.** The menu builder
gates on permission but never on entitlement.

The bare `/gbp` entry behaves differently and better: it filters to *entitled*
Businesses, so a Core customer reaching it gets an empty state — but that empty
state says "No Business available yet", which is untrue. The customer has a
Business; their plan does not include the feature.

`website` and `automations` carry the same `'default' => true` shape and are
saved from the same failure only because `website_generation` and `automations`
happen to be in the Core feature set. The Core set is
`crm, conversations, calendar, forms, automations, website_generation,
ai_coo_basic, seo_basic_visibility, ads_basic_visibility`; Growth adds
`seo_module, google_ads_module, meta_ads_module`; Agency adds `white_label,
agency_package_capabilities, prospect_outreach`. Note that **`calendar` and
`forms` are packaged into every tier and have no implementation at all** — no
route, no controller, no view, no model — so any plan comparison that lists
packaged features will promise two products that do not exist.

### 4.18 D-21 — Raw platform environment-variable names shown to customers. NEW. CONFIRMED. High

`app/Exceptions/GoogleBusinessProfile/GoogleBusinessProfileConfigurationException.php`
lines 65–73 return messages such as

```
Google Business Profile is not configured: GOOGLE_BUSINESS_PROFILE_CLIENT_ID is not set.
```

Its own docblock calls this "a safe **operator-facing** message". But
`GoogleBusinessProfileController` lines 196 and 319 route it through
`redirectWithError()` into a **customer** flash. A local business owner who
clicks *Connect* on a misconfigured install is shown the platform's internal
environment-variable names.

This is the only environment-variable leak found. The usage-billing flash
messages are plain language, though several are unhelpfully terse — "Top-up
could not be started." names no reason and offers no next step.

### 4.19 D-22 — The View-as-client banner disappears in some layouts. NEW. CONFIRMED. High

`<x-view-as-banner />` is rendered from `panels/breadcrumb.blade.php` line 7 and
nowhere else. `verticalLayoutMaster.blade.php` lines 25–38 do **not** include
the breadcrumb when `contentLayout` is anything other than `default`, and
`verticalDetachedLayoutMaster.blade.php` line 24 includes it only when
`pageHeader` is true.

Parent §5.5 requires the banner to be persistent and non-dismissible on every
page. In those configurations an Agency actor can be operating inside a client's
account with no indication that they are doing so.

### 4.20 D-23 — No loading-state primitive exists. NEW. CONFIRMED. Medium

There is no spinner, skeleton, progress or `aria-busy` primitive anywhere in
`resources/views/components/` or `resources/views/layouts/partials/`. The
product already polls in three places, all throttled at 60 requests per minute:
`customer.onboarding.analysis.status`,
`customer.opportunities.execution-status` and
`customer.workspaces.businesses.analytics.series`. Every asynchronous operation
is currently silent.

### 4.21 Two further structural facts that shape the redesign

**Onboarding is disabled by default.** `config/business.php` sets
`onboarding.enabled` from `BUSINESS_ONBOARDING_ENABLED` with a default of
`false`, and `EnsureBusinessOnboardingIsEnabled` aborts 404 when it is off. The
eight-step guided onboarding at `customer/onboarding/steps/` therefore does not
run on a default install: a new customer lands directly on the dashboard
described in D-2. `config/opportunity.php` disables the Advisor queue the same
way, so the "Advisor" sidebar entry does not appear either.

**Calendar and payments do not exist.** There is no calendar route, controller,
view or model anywhere in the repository, and no booking or appointment model.
`app/Models/Invoices.php` is the platform's own subscription invoicing, not a
facility for a Business to take payments from its clients. Any navigation tree,
recipe catalogue or dashboard that assumes bookings or customer payments is
describing a product that has not been built.

---

## 5. REPOSITORY AND ROUTE AUDIT

### 5.1 Scale

| Surface | Count |
|---|---|
| Named customer route declarations (`routes/customer.php`) | 382 |
| Named admin route declarations (`routes/admin.php`) | 265 |
| Customer Blade views (`resources/views/customer/`) | 183 |
| Design-system components (`resources/views/components/`) | 22 |
| Layout masters | 5 |
| Payment-gateway callback routes inside the customer group | 27 |
| Legacy per-channel quick-send / builder / import routes | 37 |

### 5.2 Context resolution as it stands

The resolved context is produced once per request by
`App\Http\Middleware\ResolveCustomerContext`, stored on the request as
`customerContext`, and shared into the shell by
`App\Library\Navigation\CustomerShellComposer`. The context object
(`CustomerContext`) exposes the frame (`Business` or `Account`), the selected
Workspace and Business, the list of reachable Workspaces and Businesses, the
View-as state, and the capability predicates `canManageWorkspace()`,
`canManageBilling()`, `canViewAsClient()`, `isAgency()` and
`usesBusinessVocabulary()`.

This is a sound foundation and the redesign builds on it rather than replacing
it. Its two gaps are that **entitlement is not part of the context** (D-20) and
that **only two views consume the vocabulary predicate** (D-7).

### 5.3 Screen inventory and disposition

Scope column: **G** global/user-level, **A** Account (Workspace), **B**
Business, **P** platform admin. Disposition: **Keep**, **Move**, **Combine**,
**Redirect**, **Remove**, **Rebuild**.

#### 5.3.1 Entry, context and shell

| Screen | Route | Now | Correct | Roles | Nav parent now | Nav parent target | Terminology | Safety | Disposition |
|---|---|---|---|---|---|---|---|---|---|
| Dashboard | `user.home` | G | B (and A for Agency) | all | top level | Home, per frame | `locale.menu.Dashboard` | D-19 cross-tenant count | **Rebuild** (§13) |
| Business switcher | `customer.context.business.switch` | B | B | any multi-Business actor | header | header | correct | re-authorized server-side | **Keep** |
| Campaigns entry | `customer.outreach.campaigns.entry` | B | B | outreach perms | none | none, internal | correct | never guesses | **Keep** |
| Outreach entry | `customer.outreach.index` | B | B | outreach perms | Campaigns | Messages | "Workspace owner" in empty state | none | **Keep**, recopy |
| Channels entry | `customer.channels.index` | B | **A, Agency only** | `view_numbers` (default true) | Advanced | Advanced, Agency only | provider names | **D-9** | **Move + gate** |
| Website entry | `customer.website.index` | B | B | `website` | Website | Website | "Website Generation" | none | **Keep**, recopy |
| GBP entry | `customer.gbp.index` | B | B | `view_google_business_profile` | Google Business Profile | Get found | wrong empty-state reason | **D-20** | **Keep**, recopy |
| Analytics entry | `customer.analytics.entry` | B | B | `view_reports` | Analytics | Results | "Workspace owner" | none | **Keep**, recopy |
| Automations entry | `customer.automations.index` | B | B | `automations` | Automations | Automations | "Workspace owner" | none | **Keep**, recopy |
| Prospecting entry | `customer.prospecting.index` | A | A, Agency only | `access_backend` | Prospecting | Prospecting | "Workspace" three times | none | **Keep**, recopy |
| View as start / exit | `customer.view-as.start` / `.exit` | A | A | Agency owner or admin | header | header | correct | audited, TTL-bounded | **Keep**; fix **D-22** |

#### 5.3.2 Business surfaces

| Screen | Route | Now | Correct | Roles | Nav parent target | Terminology | Disposition |
|---|---|---|---|---|---|---|---|
| Contacts, Business-scoped (39 named routes) | `customer.workspaces.businesses.contacts.*` and `…contact.*` | B | B | contact perms | Contacts | correct | **Keep** |
| Contacts, legacy flat (34 named routes plus a 5-action `Route::resource`) | `customer.contacts.*`, `customer.contact.*` | G | — | contact perms | none | correct | **Redirect** to the Business-scoped twin, then remove |
| Contact groups | `customer.contactGroups.*` views | B | B | contact perms | Contacts | correct | **Keep** |
| Outreach compose | `customer.workspaces.businesses.outreach.index` | B | B | outreach perms | Messages → Send | **D-10** blocker | **Rebuild** the originator block (§10) |
| Campaign list, show, pause, restart, resend, destroy | `…outreach.campaigns*` | B | B | outreach perms | Messages → Campaigns | correct | **Keep** |
| Conversations | `customer.chatbox.*` | G | **B** | `chat_box` | Messages → Inbox | correct | **Move** to Business scope — see note below |
| Automations list, create, edit, enable, disable, delete | `…businesses.automations.*` | B | B | `automations` | Automations | **D-10**, **D-11** | **Rebuild** front end (§11) |
| Analytics overview, campaigns, series | `…businesses.analytics.*` | B | B | `view_reports` | Results | correct | **Keep** |
| Website show, setup, pages, preview, generate, publish, history, assets | `…businesses.website.*` | B | B | `website` | Website | "Website Generation" | **Keep**, recopy |
| Knowledge profile | `…businesses.knowledge-profile.*` | B | B | `website` | Website → Business information | correct | **Keep** |
| Google Business Profile overview, locations, bind, refresh, settings | `…businesses.gbp.*` | B | B | `view_google_business_profile` | Get found | **D-21** | **Keep**, fix messages |
| Business details | `customer.business.edit` | G, primary Business only | B | owner | Settings → Business | correct | **Move** to Business scope |
| Blocked numbers | `customer.blacklists.*` | G | **B** | blacklist perms | Settings → Business | correct | **Move** |
| Usage & billing | `…businesses.usage-billing.show` | B | **split A/B** | all reachers | Settings → Billing | **D-7**, **D-12**, **D-17** | **Split** (§9) |
| Spend cap, feature limits | `…usage-billing.spend-cap`, `.feature-limit` | B | B, manager only | manager | Settings → Billing | correct | **Keep**, gate |
| Payer | `…usage-billing.payer` | B | **A, Agency only** | Agency owner or admin | Agency → Client accounts | **D-7**, **D-13** | **Move** |
| Billing contact | `…usage-billing.billing-contact` | B | B, manager only | manager | Settings → Billing | correct | **Keep**, gate |
| Payment method setup, confirm, detach | `…usage-billing.payment-method.*` | B | B, payer only | payer | Settings → Billing | correct | **Keep**, gate |
| Top-up, auto-recharge | `…usage-billing.top-up.*`, `.auto-recharge.configure` | B | B, payer only | payer | Settings → Billing | correct | **Keep**, gate |

**Note on Conversations.** `app/Http/Controllers/Customer/ChatBoxController.php`
scopes every query on `user_id` — lines 57, 75, 103, 127, 138, 143 and 144 —
and carries no `business_id` anywhere. The sidebar therefore places Conversations
inside the Business frame while the controller behind it is entirely
user-scoped: an Agency actor who switches from one client account to another
sees the identical inbox, sending identities and templates. This is the same
class of defect as D-2 and is the reason Conversations must move to Business
scope rather than merely being relabelled.

**Note on Business details.** `BusinessController@edit` and `@update` both
resolve through `findPrimaryByCustomer()`, so the screen only ever edits the
actor's *primary* Business. `CustomerMenuBuilder` already guards the entry on
`isPrimary` and on `customerId === userId`, so it is correctly hidden rather
than broken — but it means an Agency client account has **no** Business-details
screen at all today. The move to Business scope closes that gap.

#### 5.3.3 Account surfaces

| Screen | Route | Now | Correct | Roles | Terminology | Disposition |
|---|---|---|---|---|---|---|
| Account list | `customer.workspaces.index` | A | A | any member | "Workspaces" for Agency | **Keep**, recopy |
| Account overview, staff, businesses | `customer.workspaces.show` | A | A | owner, admin, staff | "Workspace" for Agency | **Keep**, recopy |
| Create, rename, deactivate, reactivate account | `customer.workspaces.{store,rename,deactivate,reactivate}` | A | A | owner | as above | **Keep** |
| Create Business, reassign Business | `…businesses.store`, `…businesses.reassign` | A | A | owner, admin | correct | **Keep** |
| Per-Business feature enable / disable | `…businesses.features.{enable,disable}` | A | A | owner, admin | "feature key" | **Keep**, recopy |
| Additional Business slots | `customer.workspaces.additional-business-slots.*` | A | A, Agency only | owner | "Workspace" twice | **Keep**, recopy |
| Ownership transfer | `…ownership.transfer` | A | A | owner | correct | **Keep** |
| Team members: add, role, access, deactivate, reactivate | `…members.*` | A | A | owner, admin | correct | **Keep** |
| Agency prospecting: overview, prospects, campaigns, settings | `customer.workspaces.prospecting.*` | A | A, Agency only | owner, admin | "Workspace" | **Keep**, recopy |
| Agency prospecting channels (provider credentials) | `…prospecting.channels.*` | A | A, Agency only | owner, admin | provider names | **Keep** under Advanced |
| Plan and subscription | `customer.subscriptions.*` | G | **A** | owner | correct | **Move**; **D-14** |
| Invoices, print | `customer.invoices.*` | G | **A** | owner | correct | **Move** |
| Pricing | `user.account.pricing` | G | A | owner | duplicate of plan list | **Combine** into plan |

#### 5.3.4 User-level surfaces

| Screen | Route | Correct scope | Disposition |
|---|---|---|---|
| Profile, avatar, password, two-factor | `user.account*` | G | **Keep** |
| Notifications | `user.account.notifications*` | G | **Keep** |
| Announcements | `user.account.announcement*` | G | **Keep** |
| Language swap | `lang/{locale}` | G | **Keep** |
| Portal switch (admin/customer) | `user.switch_view` | G, dual-role only | **Keep** |
| Account top-up (legacy credit wallet) | `user.account.top_up` | G | **Remove** after RFC-005 cutover (retention §12.2) |
| Impersonate parent | `user.account.login_as` | G | **Remove** with Sub-Accounts (retention §12.3) |
| Sub-accounts (10 routes) | `customer.sub_accounts.*` | G | **Remove** after migration (retention §12.3) |

#### 5.3.5 Surfaces with no navigation entry that remain reachable by URL

Each of these is registered, authenticated and reachable by typing the URL, but
appears in no target navigation tree. Leaving them addressable is the largest
remaining source of "dead ends the customer can fall into".

| Group | Routes | Retention decision | Disposition |
|---|---|---|---|
| Voice quick-send, builder, import | 6 | Retention §12.1, no new UI | **Redirect** to Messages, then remove |
| WhatsApp quick-send, builder, import | 6 | Retention §12.1, deferred | **Redirect**, then remove |
| Viber quick-send, builder, import | 6 | Retention §12.1, no new UI | **Redirect**, then remove |
| OTP quick-send, builder, import | 6 | Retention §12.1, no new UI | **Redirect**, then remove |
| Legacy SMS and MMS quick-send, builder, import | 13 | superseded by Outreach | **Redirect** to the Business-scoped twin |
| Sender IDs: index, request, pay, release, batch | 9 | Retention §12.8, no raw provider UI | **Move** to Advanced, Agency only |
| Numbers: index, buy, available, release, pay | 11 | Retention §12.8 | **Move** to Advanced, Agency only |
| Keywords: index, create, buy, show, pay, release | 14 | Retention §12.8 | **Move** to Advanced, Agency only |
| SMS templates | 6 | reusable in Outreach | **Combine** into Messages → Templates |
| Developers: API keys, docs, webhook, sending server | 6 | Retention §12.6, delete candidate | **Remove** |
| Per-gateway payment callbacks | 27 | required by gateways | **Keep**, never in navigation |
| Debug routes | 5 | none | **Remove immediately** — see §5.4 |

### 5.4 The unauthenticated debug surface — complete inventory

**Correction Round 1.** This section previously ended by saying the debug
routes were "outside this contract's UX remit" and should be fixed "on their
own branch", with no owner and no schedule. That was a deferral dressed as
scoping. They are now owned by **§16.A Security Remediation Slice 0**, a
release blocker. Re-reading the controller in full for this round also found
that two of the five routes are materially worse than first recorded.

`routes/web.php` lines 54–58 register five `DebugController` routes inside the
`mapWebRoutes()` group, which applies **only the `web` middleware**
(`app/Providers/RouteServiceProvider.php`) — no `auth`, no `can:`, no signed
URL, no `app.stage` guard, no throttle.
`app/Http/Controllers/Debug/DebugController.php` is 431 lines, declares no
constructor, calls `middleware()` nowhere, and contains no internal guard of
any kind. Every method is reachable by an anonymous `GET`.

| # | Route | Method | Verb | Effect | Blast radius |
|---|---|---|---|---|---|
| R-1 | `/remove-jobs` | `removeJobs()` lines 34–40 | GET | `truncate()` on `job_monitors`, `job_batches`, `jobs`, `import_job_histories`, `failed_jobs` | Every tenant's queued work, batch state and failure record destroyed, unrecoverably |
| R-2 | `/remove-contacts` | `removeContacts()` lines 375–397 | GET | Chunks **every `Contacts` row on the platform**, parses each phone number, and `delete()`s any that fails `isPossibleNumber()` or throws `NumberParseException` | Cross-tenant customer-data deletion, driven by a parser's opinion of each number |
| R-3 | `/add-gateways` | `addGateways()` lines 45–370 | GET | `PaymentMethods::updateOrCreate(['type' => …], ['name', 'status', 'options'])` across **29 gateway definitions** hardcoded in the method body | **Overwrites live payment configuration.** See below |
| R-4 | `/cache-clear` | `cacheClear()` line 408 | GET | `Cache::flush()` | Whole-application cache eviction on demand |
| R-5 | `/update-campaign-cache/{campaign}/{number}` | `updateCampaignCache()` lines 413–430 | GET | Looks up **any** campaign by uid with no ownership check, rewrites its cached contact count from the URL, calls `setDone()` and sets `delivery_at` | Cross-tenant campaign mutation; a running campaign can be marked complete by a stranger |

Only `GET /debug` is guarded, and only by `config('app.stage') === 'local'`
(`routes/web.php` line 60). R-1 through R-5 are registered unconditionally in
every environment, production included.

**R-3 is the most serious finding in this audit.** The 29 gateway definitions
it writes are the inherited vendor's own values, embedded as literals in the
controller. Two carry hardcoded provider credentials in an `options` payload,
and the offline-payment definition carries a third party's bank routing
number, account number, beneficiary name and support email address. Because
the write is `updateOrCreate` keyed on `type`, an anonymous request to
`GET /add-gateways` on a production install will:

* replace the operator's configured credentials for those gateways with the
  vendor's sandbox values, breaking payment collection;
* flip the `status` flag on all 29 gateways to the hardcoded defaults,
  silently enabling or disabling payment methods;
* **replace the offline-payment instructions with a third party's bank
  account details**, so customers paying by transfer are told to send money to
  an account the operator does not control.

That last item is a payment-redirection vector reachable by an unauthenticated
`GET`. It requires no credential, no session and no CSRF token, and a browser
will follow it from a link or an image tag.

**Why each of these is security-critical, not merely untidy.** They are
state-changing operations behind `GET`, which means they are triggerable by
cross-site request, by a crawler, by a prefetching browser, by a link in an
email, and by any embedded resource on any page. `GET` is defined as safe, so
none of the ordinary protections apply: there is no CSRF token to miss, and
browsers issue these requests without user intent. Combined with no
authentication at all, the effective access-control policy for platform-wide
data destruction and payment reconfiguration is "know the URL", and the URLs
are literal, guessable English words in a file committed to the repository.

The exact evidence above is the input to §16.A.1, which decides the disposition
of each route rather than assuming one.

---

## 6. EXPERIENCE PRINCIPLES

These are locked. Every later section, and every implementation slice, is
judged against them.

1. **No specialist knowledge is required.** An ordinary local-business owner
   must never need to understand telecommunications, databases, advertising
   platforms or automation engines to run their business on AI Business OS. If a
   screen cannot be operated without one of those, the screen is wrong.
2. **Outcomes before configuration.** Every surface leads with what the customer
   gets, then how to set it up. "Reply to new leads automatically" comes before
   trigger selection; "Get a business number" comes before provider mechanics.
3. **Progressive disclosure.** The default view carries the smallest set of
   controls that makes the common case work. Depth lives behind an explicit,
   labelled *Advanced* affordance, never in the primary flow.
4. **Safe defaults.** Every setting arrives at a value that is correct for most
   customers and cannot spend money or send messages without an explicit act.
5. **One primary action per state.** Each screen state has exactly one visually
   primary action. Everything else is secondary or tertiary.
6. **No dishonest success.** A submission that changes nothing must say so —
   "No change: this Business already bills to the agency" — and must not write
   an audit row or dispatch a domain event. This directly closes D-13.
7. **No dead links and no placeholder pages in navigation.** A navigation entry
   exists only when its route is registered, the actor is authorized for it,
   **and the plan entitles it**. The third clause is new and closes D-20.
8. **Unavailable features explain themselves.** When something is not available,
   the interface states *why* and *what unlocks it* — a plan, a permission, a
   prerequisite, or an operator action — and never returns a bare 404 or a
   generic empty state to a customer whose plan simply excludes the feature.
9. **Consequences before confirmation.** Any destructive or charge-producing
   action states, before the confirming click: what will change, what it costs,
   who it affects, and whether it can be undone.
10. **Help sits beside the decision.** Explanation lives next to the control it
    explains — inline text, a labelled hint, a disclosure — never only in a
    separate manual or a tooltip that a touch user cannot open.
11. **Mobile and keyboard are first-class.** Every flow is completable on a
    375px viewport and with a keyboard alone. This is an acceptance condition,
    not a later pass.
12. **Never leak the platform's internals.** No environment-variable name, class
    name, feature key, provider brand, provider identifier, table name or
    internal status enum appears in customer copy. This closes D-21 and is the
    generalization of parent §10.2.

---

## 7. EXACT CUSTOMER HIERARCHY, BY ROLE

### 7.1 Visible levels and landing surface

| Role | Sees Account level? | Sees Business level? | Lands on after login | Switcher shown | Locations |
|---|---|---|---|---|---|
| **Core owner** | Only as **Settings → Account** | Yes, their single Business | **That Business's Home, directly** | No | Settings → Business → Locations |
| **Growth owner** | Only as **Settings → Account** | Yes, their single Business | **That Business's Home, directly** | No | Settings → Business → Locations |
| **Agency owner** | Yes, as **Agency account** | Yes, all Client accounts | **Agency Home**, with the Client accounts list | Yes, Client accounts | Inside each Client account |
| **Agency-wide Admin** | Yes, as **Agency account** | Yes, all Client accounts | **Agency Home** | Yes | Inside each Client account |
| **Agency staff, all-Business access** | **No** | Yes, all Client accounts | Client accounts list; one Business goes straight through | Yes, when more than one | Read-only unless permitted |
| **Staff or client restricted to selected Businesses** | **No** | Only assigned Businesses | Their single assigned Business, directly; a chooser if several | Only when more than one | Read-only |
| **Platform owner** | Not applicable | Not applicable | Platform administration at the admin path | Not applicable | Not applicable |
| **View-as-client session** | **No** | Only the viewed Business | Home of the viewed Business | **Replaced** by the viewed client's identity | As the client sees them |

### 7.2 The landing rule, stated exactly

After a successful sign-in the customer is sent to:

1. **View-as active** — the viewed Business's Home. Never anything else.
2. **Exactly one reachable Business** — that Business's Home. No account
   selection screen is shown, whatever the tier. This covers every Core and
   Growth owner and every restricted staff member with one assignment.
3. **More than one reachable Business, non-Agency account** — the Business
   chooser, with the last-used Business offered first.
4. **Agency account, actor can manage it** — the **Agency Home**, which contains
   the Client accounts list, agency-wide attention items and prospecting.
5. **Agency account, actor cannot manage it** (staff) — the Client accounts
   list, filtered to the Businesses they may reach. Rule 2 still applies first:
   one assignment goes straight through.
6. **No reachable Business at all** — a single explanatory state naming who can
   give them access, and nothing else. Never an empty dashboard.

Rules 2 and 3 together satisfy the brief's requirement that Core and Growth
users are never forced through a pointless account-selection screen, and
rules 4 and 5 give Agency users the overview-then-enter shape.

### 7.3 What each role must never observe

| Role | Must never see |
|---|---|
| Core / Growth owner | The word Workspace; payer selection; provider names or credentials; another Business; Agency settings; white-label settings; prospecting |
| Agency staff | Payer assignment; agency plan and subscription; agency billing; View-as-client; provider credentials |
| Restricted Business user | Any other Business, including its existence or count; the agency's name, plan, staff or subscription; billing controls of any kind |
| View-as session | Payer changes; wallet funding; number purchase or release; plan or slot changes; team changes; any credential; any deletion; starting a second view-as |
| Platform owner, while in the customer shell | Nothing — the platform owner never enters the customer shell as a tenant; administration is a separate surface reached at the admin path |

Existence disclosure rule, unchanged from parent §5.4: an unauthorized Business
or account identifier returns **404, never 403**.

---

## 8. TARGET NAVIGATION

### 8.1 Structural rules

* The shell renders **exactly one frame** at a time: the **Account frame**
  (Agency only) or the **Business frame**. They never share entries. This is
  already true of `CustomerMenuBuilder` and must stay true.
* Active state is derived from the **resolved frame plus route name**, never
  from the URL's leading segment. Already true; keep.
* An entry renders only when **route exists AND actor authorized AND plan
  entitles**. The third condition is the new requirement (D-20).
* Labels are sentence case. No entry label exceeds two words except *Google
  Business Profile*, which is a proper noun.
* Icons are decorative and always accompanied by a text label. No icon-only
  navigation at any breakpoint.

### 8.2 Core and Growth customer — desktop

```
[Business name]                          header, no switcher when only one

  Home                                   user.home
  Messages                               group
    Inbox                                customer.chatbox.index          (Business-scoped after Slice 2)
    Send                                 …businesses.outreach.index
    Campaigns                            …businesses.outreach.campaigns
    Templates                            customer.templates.index
  Contacts                               …businesses.contacts.index
  Automations                            …businesses.automations.index
  Website                                …businesses.website.show
  Get found                              …businesses.gbp.index           (Growth only; hidden on Core)
  Results                                …businesses.analytics.overview

  Settings                               group
    Business                             customer.business.edit
      Business details
      Locations
      Business phone
      Blocked numbers                    customer.blacklists.index
    Billing                              …businesses.usage-billing.show
      Balance and top-up
      Spending limits
      Billing contact
      Payment method
    Account                              customer.workspaces.show
      Plan                               customer.subscriptions.index
      Invoices                           customer.invoices.index
      Team                               …members.*
```

No *Workspaces* entry. No *Sending*, *Sender ID*, *Numbers*, *Keywords*,
*Developers*, *Channels* or *Advanced* group at all — those are Agency-only
(§8.4). *Get found* is emitted only when
`google_business_profile_module` is entitled, which excludes Core.

### 8.3 Core and Growth customer — mobile, below 768px

The sidebar collapses to a bottom tab bar of **five** destinations plus a sheet:

```
[ Home ]  [ Messages ]  [ Contacts ]  [ Automations ]  [ More ]

More opens a full-height sheet containing:
  Website
  Get found                (when entitled)
  Results
  Settings  →  Business / Billing / Account
  Profile, Language, Sign out
```

Rules: the header keeps the Business name and, when present, the switcher. The
bottom bar is a real `<nav>` with an accessible name, each tab a `<button>` or
`<a>` with a visible label under the icon and a minimum 44 by 44 CSS-pixel
target. The sheet is focus-trapped, dismissible with Escape, and returns focus
to the *More* control.

### 8.4 Agency owner and Agency-wide admin — desktop

```
[Agency name]                            Account frame

  Home                                   agency overview
  Client accounts                        customer.workspaces.show
  Prospecting                            customer.prospecting.index
  Billing                                agency aggregate view
    Balance and funding
    Payer assignments
    Invoices
  Team                                   …members.*

  Settings                               group
    Agency profile
    Branding and white-label
    Plan and client-account capacity     …additional-business-slots.show
    Advanced                             group, permission-gated
      Messaging provider                 …businesses.channels.index / prospecting channels
      Sender identities                  customer.senderid.index
      Numbers                            customer.numbers.index
      Keywords                           customer.keywords.index
```

Choosing a Client account enters the Business frame of §8.2 with:

* the client's name in the header;
* a persistent **"← All client accounts"** control;
* a per-Business **View as client** action on the Client accounts list.

*Developers* does not appear: retention decision §12.6 classifies it as delete
or deprioritize.

### 8.5 Agency owner and admin — mobile

```
[ Home ]  [ Clients ]  [ Prospecting ]  [ Billing ]  [ More ]
```

Selecting a client swaps the whole bar to the Business bar of §8.3, with a
persistent "Back to clients" row pinned above it. The frame the customer is in
must be legible without scrolling.

### 8.6 Restricted Business user — desktop and mobile

Identical in shape to §8.2 and §8.3, reduced by permission:

* **No Settings → Account.** No plan, no invoices, no team.
* **No Settings → Billing** unless the actor is the Business's own customer or
  an account manager.
* Read-only surfaces render their content with an explanatory line — "Your
  account owner manages this" — and no controls at all. They must not render a
  disabled control, because a disabled control still advertises an action.

### 8.7 View-as-client session

* The switcher is **replaced** by the viewed client's identity.
* The banner from `resources/views/components/view-as-banner.blade.php` is
  persistent, non-dismissible, on **every** page, in **every** layout branch.
  This is the D-22 fix and is an acceptance condition of the slice that touches
  layouts.
* The menu is narrowed to entries reachable inside the viewed Business, which
  `ViewAsRouteClassification` already enforces. Keep.
* Billing, funding, plan, staff, provider and delete entries are **absent**, not
  disabled.

### 8.8 Platform administration

Reached only at `config('app.admin_path')`. It is never linked from customer
navigation and never shares the customer frame. The dual-role portal switch in
the navbar stays, gated as it already is on `is_admin`.

The admin tree keeps its current shape; the only navigation requirement this
contract places on it is that the six missing translation keys in §4.4 item 3
are supplied so it stops printing raw keys.

### 8.9 Label changes, exactly

| Today | Target | Why |
|---|---|---|
| Workspaces | **Account** / **Client accounts** | §3.2 |
| Workspace overview | **Account overview** / **Agency account** | §3.2 |
| Conversations, Chat Box | **Inbox**, under Messages | plain language |
| Campaigns | **Campaigns**, under Messages | grouping |
| Outreach, Compose | **Send**, under Messages | plain language |
| Channels, Messaging Channels | **Messaging provider**, Agency Advanced only | §3.2, D-9 |
| Sender ID | **Sender identities**, Agency Advanced only | §3.2 |
| Sending Server | removed from customer copy entirely | §3.2, D-10 |
| Originator | removed from customer copy entirely | §3.2, D-10 |
| Website Generation | **Website** | §3.2 |
| Google Business Profile | **Get found** in navigation; the proper noun inside the page | plain language |
| Analytics | **Results** | plain language |
| Usage & billing | **Billing** | plain language |
| Blacklist, Blocked numbers | **Blocked numbers**, under Settings → Business | plain language |
| Sub Accounts | removed | retention §12.3 |
| Developers | removed | retention §12.6 |

---

## 9. SCREEN PLACEMENT MATRIX

Every setting and control in the product, classified. Levels: **P** platform
owner only, **A** Account or Agency, **B** Business, **L** physical location,
**ADV** advanced or hidden, **REM** remove.

| Control | Today | Target | Rationale |
|---|---|---|---|
| **Subscription and plan** | `customer.subscriptions.*`, user-level | **A** | The subscription belongs to the account, not to one Business. Core and Growth reach it as Settings → Account → Plan. |
| **Client-account capacity** (Business slots) | `…additional-business-slots.*`, Account | **A**, Agency only | Already correct; only the copy changes. Core and Growth never see it — they cannot have a second Business. |
| **Physical-location allocations** | Account-level capacity, no UI | **A** for the allowance, **B** for consumption, **L** for the record | Capacity is bought at account level, spent per Business, and each location is a row inside a Business. Never an account. |
| **Wallet funding** | `…usage-billing.top-up.*`, Business | **B**, payer only | The wallet is per Business (RFC-005). Only the assigned payer may fund it. |
| **Payer assignment** | `…usage-billing.payer`, Business, ungated | **A**, Agency owner or admin only | Parent §12.4. It is a decision *about* a Business made *by* the account. Closes D-12. |
| **Account aggregate ceilings** | Account | **A** | Unchanged in mechanism; relabelled from Workspace. |
| **Business spending caps** | `…usage-billing.spend-cap`, Business, ungated | **B**, manager only | Correct level, wrong gate. Closes half of D-17. |
| **Billing contact** | `…usage-billing.billing-contact`, Business, ungated | **B**, manager only | Correct level, wrong gate. |
| **Auto-recharge** | `…usage-billing.auto-recharge.configure`, Business, ungated | **B**, payer only | Charge-producing; only the payer may arm it. |
| **Payment method** | `…usage-billing.payment-method.*`, Business, ungated | **B**, payer only | Same reasoning. |
| **Telecom provider credentials** | `…businesses.channels.*`, Business, `view_numbers` | **ADV**, Agency owner or admin only, behind an explicit warning | Closes D-9. Parent §11.4 already makes BYO the exception. |
| **Business phone** | does not exist | **B**, Settings → Business → Business phone | The managed replacement for the provider surface (§10). |
| **Sender identities, Numbers, Keywords** | top-level customer routes | **ADV**, Agency only | Retention §12.8. |
| **Team and members** | `…members.*` (Account) and `customer.sub_accounts.*` (user) | **A** only, one system | Two parallel membership systems today. Retention §12.3 removes the second. |
| **View as client** | `customer.view-as.*` | **A**, Agency owner or admin, per Business | Already correct. |
| **Domains** | inside `…businesses.website.*` | **B**, Settings → Website → Domain | A domain belongs to one Business's site. |
| **Website** | `…businesses.website.*` | **B** | Correct. |
| **Google Business Profile** | `…businesses.gbp.*` | **B**, Growth and Agency only | Correct, but the navigation entry must respect entitlement (D-20). |
| **Analytics** | `…businesses.analytics.*` | **B** | Correct. |
| **Prospecting** | `customer.workspaces.prospecting.*` | **A**, Agency only | Correct. It is the agency acquiring clients, never a Business function. |
| **Prospecting channels** | `…prospecting.channels.*` | **ADV** under Agency Settings | Provider credentials; same rule as messaging provider. |
| **Automations** | `…businesses.automations.*` | **B** | Correct level; front end rebuilt (§11). |
| **Calendar** | packaged in every tier, **no implementation** | **Absent from navigation** | Must not appear until it exists. |
| **Payments (taking money from a Business's clients)** | does not exist | **Absent from navigation** | Same. `Invoices` is platform subscription billing, not this. |
| **Platform settings, plans catalogue, sending servers, provider events, safety limits, theme presets** | admin path | **P** | Correct; only the missing translation keys change. |
| **Debug routes** | `routes/web.php`, unauthenticated | **REM** | Not a customer surface at all. |
| **Legacy per-channel campaign UI** (voice, WhatsApp, Viber, OTP) | customer routes | **REM** after redirect | Retention §12.1. |
| **Developers and API docs** | `customer.developer.*` | **REM** | Retention §12.6. |
| **Legacy credit top-up** | `user.account.top_up` | **REM** after RFC-005 cutover | Retention §12.2. |
| **Theme customizer layout controls** | admin | **REM** | Retention §12.4 locks one opinionated layout. This is also what retires the horizontal and detached masters and closes the D-3 item 2 and D-18 layout gaps. |

---

## 10. MANAGED MESSAGING EXPERIENCE

The mechanism is parent §10 through §13 and is not restated. What follows is the
**visible experience**, which the parent does not fully specify.

### 10.1 The ordinary flow

**Entry.** Settings → Business → **Business phone**. When no phone exists, Home
shows a single attention card: *"Your business can't send or receive texts yet."*
with one primary action, **Set up business phone**.

| Step | Heading the customer reads | What they do | What they never see |
|---|---|---|---|
| 1 | Set up your business phone | Confirm the business name, address and contact already on file | Any provider name |
| 2 | Choose your number | Pick a local number by area, or start moving an existing number across | Number inventory internals, provider search parameters |
| 3 | How you'll use texting | Choose from plain descriptions — appointment reminders, replies to enquiries, promotions — and say how people opt in | The words *campaign registration*, *use case*, *brand*, *10DLC* |
| 4 | What this costs | An itemised estimate: one-off setup, monthly number cost, per-message cost, and the balance needed to start | Reservations, meters, micro-units |
| 5 | Add funds *(skipped when the balance already covers it)* | Pay the shortfall | Ledger mechanics |
| 6 | Setting up your number | Wait, with visible progress and an honest estimate of how long | Provider API states |
| 7 | Your business number is +1 … | Send a test message | Anything |

Ordering invariant, from parent §10.3: **no provider-costing call happens before
funds are reserved.** Steps 1 through 4 cost nothing, so a customer who abandons
at step 3 has cost the platform nothing.

### 10.2 What the customer must never be asked for

Twilio Account SID, Twilio Auth Token, Telnyx API Key, Telnyx Message Profile
ID, Telnyx Message Connection ID, a sending server, a sender ID, an originator,
a connection, a messaging profile, or any provider name at all. Closing D-9 and
D-10 means deleting these from the ordinary path entirely, not relabelling them.

### 10.3 How sender identity is described instead

The compose screen shows **one line, not a control**:

> Sending from **Northside Dental** · +1 (415) 555 0142

with a quiet *Change* link that opens Settings → Business → Business phone. When
more than one number exists for the Business, that line becomes a select whose
options are the numbers themselves, labelled by their friendly name and digits —
never by a server, a profile, or a provider.

When no number exists, the compose screen does not render a disabled form. It
renders the single attention state from §10.1 and its one primary action.

### 10.4 Bring your own provider

BYO lives under **Agency account → Settings → Advanced → Messaging provider**,
is visible only to an Agency owner or admin, and is preceded by an explicit,
unavoidable explanation:

> Connecting your own provider means that provider bills you directly for
> messages. AI Business OS will show you the volume but will not charge your
> balance for it, and cannot help with delivery problems on your account.

This is where the existing `MessagingChannelsController` surface goes, unchanged
in mechanism. The change required is the **gate**: the controller must add an
Agency-tier and manage-role check to every method, because today the menu alone
hides it (D-9).

### 10.5 Number lifecycle in customer language

| Event | What the customer reads |
|---|---|
| Renewal due | "Your number renews on 3 March. It costs $2.00 a month and will come out of your balance." |
| Balance too low to renew | "Add funds by 1 March to keep +1 (415) 555 0142." with a primary Add funds action |
| Suspended | "Texting is paused because your balance ran out. Add funds to start again — you keep your number for 30 days." |
| Released | "This number has been released and can't be recovered." — shown only after an explicit confirmation naming that consequence |
| Porting out | "You can move your number to another provider. We'll give you the details you need." |

---

## 11. GUIDED AUTOMATION EXPERIENCE

### 11.1 The default is a recipe catalogue

The Automations entry screen asks **"What would you like to happen
automatically?"** and shows recipe cards. The existing When/Then form at
`resources/views/customer/Automations/form.blade.php` becomes the **custom**
path only, reached from the last card and labelled *Build your own*.

### 11.2 Recipe catalogue — reconciled with the automation expansion contract

**Correction Round 1. The earlier framing of this section — "three recipes are
buildable today, the catalogue ships with three cards" — is withdrawn.** It used
a binary *buildable / not buildable* test that collapsed two independent
questions into one, and it produced a wrong shipping instruction. A recipe can
be mechanically runnable through the legacy technical form and still be
unacceptable to show in a guided, managed catalogue. The earlier wording would
have shipped three guided cards that depend on messaging-identity and wallet
work that has not landed.

**Authority.** Recipe feasibility is now governed by
`docs/automation/AUTOMATION-TRIGGER-EVENT-AND-GUIDED-RECIPE-EXPANSION-CONTRACT.md`
§7.0, merged at `origin/main` `98e063a`. Its three states are adopted verbatim:

| State | Meaning |
|---|---|
| **1 — Technically runnable today** | Trigger and action both execute now through the existing legacy When/Then form. Says nothing about whether it may be exposed |
| **2 — Product/release blocked** | State 1 holds, but the recipe may not ship in the guided experience until default Business messaging-identity resolution, the managed-provider foundation, wallet reservation and cost estimation, and removal of sender/server choices from the form have all landed |
| **3 — No producer or action substrate** | The trigger, the action, or both do not exist as dispatchable code. No amount of identity or wallet work unblocks this |

**This document makes no producer claim of its own.** Every classification below
is the expansion contract's, cited rather than re-derived. Where a recipe from
this document's brief is not among that contract's seventeen, it is marked
**unclassified upstream** and must be ruled on there before any design work,
rather than being given a verdict here.

| Recipe (this document's brief) | Expansion-contract row | State | Why |
|---|---|---|---|
| Reply to a new lead | #1, New lead instant reply | **1 + 2** | Runs today via legacy config; withheld from the guided catalogue pending messaging-identity, provider and wallet work |
| Missed-call text back | #2 | **3** | No voice ingestion exists; out of scope per parent §10.5 |
| Appointment booked confirmation | #3 | **3** | No Booking domain |
| Appointment reminder | #4 | **3** | Same |
| Booking cancelled | #6 | **3** | Same |
| No-show follow-up | #7 | **3** | Same |
| Payment received thank-you | #8 | **3** | No Business-to-client payments domain |
| Payment failed reminder | #9 | **3** | Same |
| Inbound message follow-up | #12 | **3** | An inbound event exists but is a websocket broadcast with no Business attribution, and there is no notify-staff action. **Corrected:** the earlier "no inbound-message trigger" wording understated this — a producer exists and is unsafe, which is a different finding from absence |
| Review request after completed service | #14 | **3** | Needs both Booking and review-ingestion domains |
| Lead not answered | #13, Lead has not replied | **3** | Requires a Business-scoped Conversation model; `ChatBox` is user-scoped |
| Date-based reminder | **unclassified upstream** | — | `contact_date_reached → send_message` is mechanically state 1, but the pair is not one of the seventeen. Its guided-catalogue status must be ruled on in the expansion contract before design |
| Tag a new contact | **unclassified upstream** | — | `contact_created → update_contact_field` is mechanically state 1 and is the one candidate that needs **no** messaging identity or wallet, so it may not carry state 2. That is an upstream ruling, not one this document may make |

**The corrected shipping instruction.** The expansion contract §7.0 states that
**no recipe is shown in the guided catalogue in state 1 alone** — the
product-readiness bar of state 2 governs every entry, Recipe 1 included.
Therefore:

* **At this base, the guided catalogue ships with zero cards.** Slice 7 delivers
  the catalogue *mechanism*, the card contract of §11.3, and *Build your own* —
  not a populated catalogue.
* Recipe 1 is the **first** card to appear, and only once the messaging
  prerequisites land. It is near-term and well understood.
* The two unclassified pairs may be additional early candidates, once ruled on
  upstream.
* Recipes in state 3 are never shown, never greyed out, never labelled "coming
  soon", and never creatable — parent §14.2's discipline, unchanged.

This correction removes the risk the earlier wording created: shipping guided
cards that depend on unlanded work, presented to customers as ready.

### 11.3 What every recipe card must say

| Aspect | Requirement | Example |
|---|---|---|
| What starts it | One sentence, plain language, no enum value | "Someone new is added to your contacts." |
| What it does | One sentence naming the outcome | "Sends them a text within a few minutes." |
| Who receives it | The audience, in the customer's words | "The new contact. Never anyone who has opted out." |
| Estimated cost | Per run and per month, in retail currency, computed from the Business's own recent volume | "About $0.01 each. Roughly $3 a month at your current pace." |
| What's missing | A concrete, named list with a link to fix each | "Needs a business phone. Needs $5 in your balance." |
| Safe to activate? | An explicit verdict, not an inference | "Ready to turn on." or "2 things to finish first." |

A recipe arrives with a working default message, timing and audience. The
customer may activate it without editing anything. It is a **draft** until
explicitly activated, and drafts never execute.

### 11.4 The advanced builder

Retained, clearly labelled **Build your own**, and gated on the same
`automations` permission. It keeps the When/Then structure but loses the
sending-server and sender-identity selects entirely (§10.3): the Business phone
is resolved automatically, and the automation fails closed with the §14.2
message when none exists.

---

## 12. LOGIN AND ONBOARDING REDESIGN

### 12.1 What is already done, and what is left

Slice 2 delivered the neutral authentication identity, one `<h1>` per screen,
visible labels, accessible password toggles, error-to-field association and
translated copy across all eight authentication screens (D-1). **The
authentication redesign is therefore substantially complete.** What remains:

| Item | Why |
|---|---|
| Registration checkout screens still carry `create-account.svg` | 7 files under `resources/views/auth/payment/` |
| Public opt-in and opt-out pages carry `reset-password-v2*.svg` | 4 references, and these are seen by the customer's own contacts |
| Error pages carry the generic Vuexy `error*.svg` | 7 files under `resources/views/errors/` |
| Onboarding is disabled by default | `config/business.php`, `BUSINESS_ONBOARDING_ENABLED` defaults to false |

### 12.2 Layout, without commissioning artwork

The authentication layout must work from typography, product marks and
lightweight shapes alone, exactly as the neutral panel in
`branding-illustration.blade.php` already does. No slice may be blocked on
illustration assets. Final illustration style is an open decision (§18).

* One column below 768px. Two columns above, with the form column at a fixed
  comfortable measure and the brand panel filling the remainder.
* The brand panel is **decorative** and is `aria-hidden` when it carries no
  information, or carries the Agency name as its accessible name when
  white-labelled.
* No screen depends on an image being present.

### 12.3 The screens

| Screen | Requirement |
|---|---|
| **Sign in** | Email, password, remember, sign in. One primary action. Forgot-password link beneath. Errors summarized inline and tied to the field. |
| **Register** | Name, email, password, business name. **The plan choice moves after account creation**, so a failed payment never destroys the account. Today registration and subscription checkout are one flow, which is why the checkout screens still carry the inherited artwork. |
| **Password recovery** | Request and reset. The request screen always reports the same neutral outcome, so it discloses nothing about which addresses exist. |
| **First Account creation** | Implicit. An account is created with the customer's first Business. The customer is never asked to name an account. |
| **First Business creation** | "What's your business called?" plus type and location. This is the first thing a new customer does. |
| **Business information** | Address, hours, services, contact. Reuses the existing knowledge-profile capture rather than a second form. |
| **First phone setup** | §10.1, offered but skippable. Skipping leaves a checklist item, not a blocked account. |
| **First website** | Offered from the checklist, never mandatory. |
| **First automation** | The catalogue's *Reply to a new lead*, pre-filled and one click from active. |
| **Setup checklist** | Persistent on Home until complete: Business details, Business phone, Contacts imported, Website, First automation. Each item states why it matters in one line. Dismissible, and recoverable from Settings. |
| **Progress preservation** | Every step writes on submit. Leaving and returning resumes at the furthest incomplete step. Closing the browser loses nothing. |
| **Trial messaging** | If a trial exists, the header states days remaining and what happens at the end, in one line, with one action. Never a countdown that only creates anxiety. |

The eight-step flow at `customer/onboarding/steps/` already implements goals,
business, location, services, assets, analysis, results and complete. The work is
to enable it, connect it to the checklist, and add the phone, website and
automation steps — not to build it again.

---

## 13. DASHBOARD REDESIGN

### 13.1 The five questions

Every dashboard answers, in this order:

1. **What needs attention?** Things that are broken, blocked or about to be.
2. **What happened?** Recent, concrete events.
3. **What should I do next?** One recommended action.
4. **How is the Business performing?** A small number of interpreted measures.
5. **What is costing money?** Spend against balance, with a trend.

No raw count appears without an interpretation beside it.

### 13.2 Core and Growth Business Home

```
Attention          only when non-empty; ordered by urgency
                   no business phone / balance low / automation failing /
                   payment method expiring / website unpublished

Next step          exactly one recommendation, from the setup checklist
                   or the highest-value unconfigured capability

Recent             last 10 meaningful events: messages in and out,
                   new contacts, automation runs, website publishes

Performance        new contacts, conversations started, messages sent,
                   automation runs — each with a period comparison and
                   a sentence saying whether that is good

Spend              balance, spend this period, projected days remaining,
                   one action: Add funds
```

Everything is **scoped to the selected Business**. This is the fix for D-2 and
D-19: the counts move out of the Blade template into a presenter with a stated
query budget, and every query carries the Business scope.

### 13.3 Agency account Home

```
Attention          across all client accounts, grouped by client
                   clients with no phone / low balance / failing automations /
                   unpaid agency invoice

Client accounts    the list, each row: name, status, balance, last activity,
                   and a View-as control

Prospecting        active campaigns, replies awaiting a human, booked meetings

Agency performance clients added, clients lost, aggregate messages,
                   aggregate spend

Agency billing     plan, client-account capacity used, next invoice
```

### 13.4 Individual Agency Business Home

Identical to §13.2, plus a persistent "← All client accounts" control and the
client's name in the header. An Agency actor must never be uncertain which
client they are looking at.

### 13.5 Platform owner Home

```
Platform health    queue depth, failed jobs, provider event backlog,
                   webhook failures
Commercial         active accounts by tier, MRR, trials converting,
                   churn this period
Operational risk   accounts with negative balance, funding failures,
                   entitlement overrides in force
Recent             signups, plan changes, cancellations
```

This stays on the admin path and shares no component state with the customer
shell.

---

## 14. EMPTY, LOADING, ERROR AND DISABLED STATES

### 14.1 The single component rule

There are two empty-state implementations and the better one is unused (D-15).
**One survives.** The Slice 2 partial at
`resources/views/layouts/partials/empty-state.blade.php` is the design, because
it already carries `state`, `explanation`, `primary`, `secondary` and
`ownerHint`. Its capabilities are folded into `<x-empty-state>` so the 32
existing call sites keep working, and the title becomes a real heading element.

Four states, always distinguished by **a word**, never by colour alone:

| State | Meaning | Shape |
|---|---|---|
| `empty` | Nothing here yet, and the customer can change that | Title, one-line explanation, **one** primary action |
| `unconfigured` | A prerequisite is missing | Title, what is missing, action that fixes the prerequisite |
| `locked` | The plan or the actor's permission excludes it | Title, **why**, what unlocks it, and who can unlock it |
| `unavailable` | The platform cannot do this right now | Title, plain-language reason, what happens next, no action the customer cannot take |

### 14.2 Required copy

Written as the customer reads it. No implementation nouns.

| Situation | State | Copy |
|---|---|---|
| No Business | `empty` | **You don't have a business set up yet.** Create one to start adding contacts, sending messages and building your website. → *Create your business* |
| No Business, staff member | `locked` | **You haven't been given access to a business yet.** Ask the person who invited you to add you to one. |
| No phone | `unconfigured` | **This business can't send or receive texts yet.** Set up a business phone number — it takes about five minutes. → *Set up business phone* |
| No credits | `unconfigured` | **Your balance is empty.** Messages and automations are paused until you add funds. → *Add funds* |
| No credits, actor is not the payer | `locked` | **Your balance is empty and messages are paused.** Your agency manages payment for this business — we've let them know. |
| No contacts | `empty` | **No contacts yet.** Import a list, or add someone by hand. → *Import contacts* · *Add a contact* |
| No campaign | `empty` | **You haven't sent a campaign yet.** A campaign sends one message to a group of contacts. → *Send your first campaign* |
| No website | `empty` | **You don't have a website yet.** We can build one from what you've already told us about your business. → *Create my website* |
| No Google profile | `empty` | **Your Google Business Profile isn't connected.** Connecting it lets you see how people find you on Google and Maps. → *Connect Google* |
| No automation | `empty` | **Nothing is running automatically yet.** Pick something you'd like to happen on its own. → *Browse what you can automate* |
| Integration unavailable | `unavailable` | **We can't reach Google right now.** Nothing has been lost. Try again in a few minutes. |
| Feature not included | `locked` | **Google Business Profile is included on the Growth and Agency plans.** Your plan is Core. → *See plans* — replaces the raw 404 in D-20 |
| Feature not included, staff | `locked` | **Google Business Profile isn't included on this account's plan.** Your account owner can change that. |
| Permission denied | `locked` | **You don't have access to this.** Ask your account owner if you need it. — never states what the thing does |
| Provider configuration missing | `unavailable` | **Google connections aren't available right now.** This is something we need to fix on our side — we've been notified. — replaces the environment-variable message in D-21 |
| No-op update | inline notice | **No change — this business already bills to the agency.** — replaces "Payer updated." in D-13 |
| Failed payment | `unconfigured` | **Your last payment didn't go through.** Your card was declined. Messages will pause on 4 March unless it's updated. → *Update payment method* |
| Usage limit reached | `locked` | **This business has hit its monthly spending limit of $50.** Sending is paused until 1 April, or until the limit is raised. → *Raise the limit* when permitted, otherwise the owner-hint variant |

### 14.3 Loading states

Nothing exists today (D-23). Required:

| Duration | Treatment |
|---|---|
| Under 300ms | Nothing. Never flash a spinner. |
| 300ms to 3s | An inline indicator in the region that is changing, with `aria-busy="true"` on that region. Never a full-page overlay. |
| Over 3s | A skeleton matching the eventual layout, plus a status line naming what is happening: "Checking available numbers…" |
| Polling (analysis status, execution status, analytics series) | A visible, honest status with elapsed time, and a stated stop condition. A poll that has been running for more than its expected duration says so and offers a way out. |
| Submitted form | The primary action becomes busy and disabled, with its label replaced by the present participle: "Adding funds…". Never leave a form that appears untouched after a click. |

Every busy region announces once via `role="status"` and `aria-live="polite"`.
No busy state announces more than once per operation.

### 14.4 Error states

* HTTP error pages get an AI Business OS treatment: what happened, whether it
  was the customer's doing, and one route back. The inherited Vuexy `error*.svg`
  goes.
* **404 is never the answer to "your plan doesn't include this."** That is the
  `locked` empty state. 404 remains the answer to an unauthorized or unknown
  identifier, per parent §5.4.
* Validation errors appear beside their field and are summarized once at the
  top, as the auth screens already do.
* No error message contains a class name, a table name, an environment-variable
  name, a provider error string, an internal status enum or a stack frame.

### 14.5 Disabled states

**A control the actor may never use is absent, not disabled.** A disabled
control still advertises a capability and invites a support request.

A control is disabled only when the actor *could* use it once a stated,
immediately visible condition is met — "Add funds is unavailable until a payment
method is added" — and the condition is rendered adjacent to the control, not in
a tooltip.

Read-only surfaces render their values as text with one explanatory line naming
who can change them.

---

## 15. VISUAL DIRECTION

Definition only. No implementation is authorized.

### 15.1 What already exists and is reused

The Design System Milestone 1 token layer is real and is the substrate. Do not
build a second one.

| Token file | Contents |
|---|---|
| `resources/scss/base/tokens/_colors.scss` | 103 custom properties |
| `resources/scss/base/tokens/_typography.scss` | 6 type roles: page title, section heading, body, label, caption, numeric — the numeric role already sets `font-feature-settings: 'tnum' 1` |
| `resources/scss/base/tokens/_spacing.scss` | a 10-step scale, `--space-1` through `--space-16` |
| `resources/scss/base/tokens/_radii.scss`, `_shadows.scss`, `_motion.scss` | radius, elevation and motion scales |
| `resources/scss/base/tokens/_runtime-bindings.scss` | binds tokens to the platform theme-preset system |

Reusable components, 22 in `resources/views/components/`: `alert`, `badge`,
`button`, `card`, `dialog`, `ds-icon`, `empty-state`, `input`, `menu`,
`pagination`, `select`, `switch-toggle`, `table`, `tabs`, `tooltip`, plus the
branding and customer-shell components.

### 15.2 Direction

| Aspect | Direction |
|---|---|
| **Typography** | One family for the interface. Six roles only, from the existing scale. Page titles no larger than needed to lead. Numbers always use the numeric role so columns align. |
| **Spacing** | The existing 10-step scale, nothing between steps. Vertical rhythm from the same scale; no arbitrary margins. |
| **Density** | Comfortable, not compact. This customer looks at the product a few times a day, not for eight hours. Table rows at least 44px tall so they are touchable. |
| **Colour roles** | Semantic, never decorative: `surface`, `surface-raised`, `text`, `text-muted`, `border`, `accent`, plus `success` / `warning` / `danger` / `info`. Status is never carried by colour alone — always a word or an icon with a label. |
| **Cards** | The container for one idea. One heading, optional one-line description, content, at most one primary action. Never nested more than one level. |
| **Forms** | Labels always visible, above the field. Placeholders are examples, never labels. Help text beneath the label, before the field. Errors beneath the field. One column; two only for genuinely paired values such as city and postcode. |
| **Tables** | Header row, sorted state announced, zebra striping optional and subtle. Below 768px, a table becomes a stacked list of labelled rows — never a horizontally scrolling table on a phone. |
| **Banners** | Reserved for state that persists across pages: view-as, trial ending, balance exhausted, payment failed. At most one at a time, chosen by severity. Never used for transient confirmations, which are toasts. |
| **Contextual help** | Inline text next to the decision. A disclosure for depth. No help that requires hover, because a touch user cannot hover. |
| **Status indicators** | A dot plus a word. Never a bare coloured dot. Consistent vocabulary: Active, Paused, Draft, Failed, Pending. |
| **Mobile navigation** | Bottom tab bar of five, plus a sheet (§8.3). The header keeps context and the switcher. No hamburger as the only route to primary destinations. |
| **Empty-state illustration** | **Policy: no illustration is required.** Icon plus text is the baseline and must always work alone. If illustrations are added later they are additive and decorative, with `alt=""`. No screen may depend on one. |
| **Charts** | Only where a trend answers one of the five dashboard questions. Always with a plain-language interpretation beside them. Accessible name, and a table alternative for the underlying figures. |
| **Contrast and focus** | Text meets WCAG AA at 4.5:1, large text 3:1, and non-text UI 3:1. Focus is always visible with a 2px ring at 3:1 against both the component and the page. Never `outline: none` without a replacement. |

### 15.3 Inherited patterns to retire

| Pattern | Evidence | Why |
|---|---|---|
| `dashboard-ecommerce.css` | `customer/dashboard.blade.php` line 8 | An e-commerce analytics layout for a local-business operating system |
| `page-pricing.css`, `page-knowledge-base.css`, `app-invoice*.css`, `app-chat*.css` | 6 further page stylesheets | Per-page Vuexy stylesheets that fight the token layer |
| Horizontal and detached layout masters | 3 of 5 masters | No `<main>`, no skip link, and they render the pre-Slice-1B legacy menu (D-3, D-18). Retention §12.4 already removes owner-configurable layout. |
| Legacy static customer menu | `Helper::menuData()` customer branch, lines 919–1095 | Superseded by `CustomerMenuBuilder`; retaining it keeps a second, wrong navigation alive |
| `panels/submenu.blade.php`, `panels/horizontalSubmenu.blade.php` | 2 files | Only consumers of the legacy array |
| Vuexy error illustrations | 7 error views | Another product's identity on our failure pages |
| Vuexy auth illustrations in the payment and contact-form views | 11 references | The last of the inherited artwork (§12.1) |
| Feather icon sprinkles used as decoration | across legacy views | Icons must label or be removed |

---

## 16. DELIVERY SLICES

Dependency-ordered, sized to avoid large rewrites. **No slice is authorized by
this document.** Each requires separate human approval.

### 16.0 Collision map with work that is in flight

Three branches exist at `origin` that are **not** merged into the base SHA.
Their file lists were read with `git diff --name-only origin/main...origin/<branch>`
and are recorded here because every slice below has to be sequenced around them.
None of them was merged or consulted for evidence.

| Unmerged branch | Files it changes that this contract's slices also change | Slices affected |
|---|---|---|
| `agent/customer-experience-slice-5-wallet-payer-ux` | `UsageBillingController.php`, `UsageBillingTopUpController.php`, `UsageBillingAutoRechargeController.php`, `BillingProfileManager.php`, `WorkspaceController.php`, `usage-billing/show.blade.php`, `workspaces/show.blade.php`, `resources/lang/en/locale.php` | **1, 5** |
| `agent/b1-outreach-compose` | `_originator.blade.php`, the four compose partials, `OutreachController.php`, `Helper.php`, `routes/customer.php`, `resources/lang/en/locale.php` | **1, 6** |
| `agent/customer-experience-slice-1a-location-capacity` | `EntitlementManager.php`, `Business.php`, `BusinessLocation.php`, `routes/customer.php`, and a new `customer/business/locations/index.blade.php` | **2, 9** |
| `agent/customer-experience-slice-3-messaging-provider-contract` *(added Correction Round 1)* | Documentation only — adds `CUSTOMER-EXPERIENCE-SLICE-3-MESSAGING-PROVIDER-FOUNDATION.md` and edits the parent contract. It changes no source, so it collides with no slice's code. But its **§4.7 owns the provider relocation**, which bounds **§16.A.3** | **0 (item 3), 6** |

Two consequences are binding:

* **`resources/lang/en/locale.php` is contended by two of the three.** Slice 1
  edits it heavily. It must land before either branch, or after both. It must
  not land alongside them.
* **Slice 5 cannot start** until `agent/customer-experience-slice-5-wallet-payer-ux`
  is merged or abandoned. They rewrite the same three files for overlapping
  reasons.

The location-capacity branch is good news for §9: it introduces the
Business-scoped `customer/business/locations/index.blade.php` that the
Settings → Business → Locations placement needs, so Slice 9 should build on it
rather than create a second locations surface.

**Slice 0 (§16.A) sits ahead of everything in this section and is a release
blocker.** The numbered slices below begin only after it exits.

Every slice carries the same universal stop conditions:

* **stop if Slice 0 has not exited** — no slice below may start first;
* stop if the change requires a schema migration not already contracted;
* stop if the change touches RFC-003 tenancy, RFC-004 entitlement decisions or
  RFC-005 ledger invariants;
* stop if a permission or plan-packaging default would have to change;
* stop if the slice would need to modify a currently-active contract's files;
* stop on any finding that contradicts this document, and record it rather than
  working around it.

### 16.A SECURITY REMEDIATION SLICE 0 — release blocker, prerequisite for every slice below

**Status: release blocker.** Slice 0 must land before any slice in §16, before
any visual or navigation work, and before release. It is not a "nice to have
first"; a slice that renumbers a menu while `GET /add-gateways` remains open is
work spent on the wrong thing. **Owner: the same team that takes Slice 1.** It
is not a background task, not a follow-up, and not conditional on someone
volunteering.

**Why Slice 0 exists rather than four scattered tickets.** All four items share
one root cause: *a boundary that is enforced somewhere other than where the
request is authorized.* R-1 through R-5 rely on the URL being unknown; D-9
relies on the menu not linking it; D-19 relies on SQL precedence that does not
hold; D-21 relies on a message being read by the right audience. Each is the
same mistake, and fixing them together makes the shared rule explicit: **the
authorization boundary is the controller, and nothing else.**

**Slice 0 changes no user-visible design.** It is deliberately shaped so it can
land while the design questions in §18 are still open, and so that no
user-experience branch has to carry a security change.

#### Universal Slice 0 conditions

* **No schema migration.** Every fix is additive route, controller, gate or
  query work. Any item that appears to need a migration stops and is
  re-contracted (this is why §16.A.2 does not add a `business_id` to invoices).
* **Fail closed.** Every new check denies by default and permits explicitly.
* **No behaviour change for a correctly authorized actor**, except where the
  current behaviour is itself the defect.
* **Each item is independently revertible** — see the per-item rollback rows.
  A revert restores the prior (defective) behaviour and nothing else.
* **Ship order.** Items 1 and 2 are independent and may land in parallel. Items
  3 and 4 are independent of both.

---

#### 16.A.1 — Unauthenticated destructive debug routes (D-24)

**Objective.** No unauthenticated request may destroy data, mutate another
tenant's records, or alter payment configuration. No `GET` may do so at all.

**Evidence.** §5.4, routes R-1 through R-5.

**Disposition, decided per route rather than assumed.** The brief's default —
remove customer-accessible production registration unless a real operational
requirement is evidenced — was applied to each. The evidence search for an
operational requirement was: consumers of each route name across the
repository, and whether the behaviour is reachable through an existing
authorized surface.

| Route | Evidenced operational need | Disposition | Reasoning |
|---|---|---|---|
| R-1 `/remove-jobs` | **None found.** Queue maintenance belongs to the operator's own tooling, and `php artisan queue:flush` already exists | **Delete route and method** | A web endpoint that truncates five queue tables has no legitimate caller. Nothing in the application links it |
| R-2 `/remove-contacts` | **None found.** A one-off historical data-cleanup pass, not an operation | **Delete route and method** | It deletes customer data platform-wide on a parser heuristic. If the cleanup is ever needed again it is a console command run deliberately, not a URL |
| R-3 `/add-gateways` | **Seeding is a real need.** `database/seeders/PaymentMethodsSeeder.php` already exists and is the correct home | **Delete route and method; do not re-home the hardcoded values** | The behaviour duplicates an existing seeder. The embedded vendor credentials and third-party bank details must be removed from source, not relocated |
| R-4 `/cache-clear` | **Real, but already served.** `php artisan cache:clear` exists | **Delete route and method** | An unauthenticated cache-eviction endpoint is a denial-of-service primitive |
| R-5 `/update-campaign-cache/{campaign}/{number}` | **None found.** It repairs a cached counter | **Delete route and method** | It mutates any campaign by uid with no ownership check. A repair operation belongs in a console command that names its tenant |

**Recommended disposition: delete all five routes and the controller.** With
every method removed, `app/Http/Controllers/Debug/DebugController.php` has no
remaining behaviour, and the `if (config('app.stage') === 'local')` block
guarding `/debug` becomes the only survivor. The recommendation is to delete the
class outright and, with it, the hardcoded credentials and bank details in
source.

**If the operator evidences a genuine need for any one of them**, the fallback
is not "add auth" alone. It is all four of: registration only under
`config('app.stage') === 'local'`; `POST` not `GET`; behind `auth` plus an
explicit operator ability; and a throttle. A route that survives on those terms
is an operator tool, never a customer-reachable one. **This fallback requires
named, written evidence of the need; absence of evidence resolves to delete.**

**Implementation paths (exact).**

| Path | Change |
|---|---|
| `routes/web.php` lines 54–58 | Remove the five `Route::get(...)` registrations |
| `routes/web.php` line 60 and the `use` at line 8 | Remove the `config('app.stage') === 'local'` `/debug` block and the now-unused import |
| `app/Http/Controllers/Debug/DebugController.php` | Delete the file |

**Test paths (exact).**

| Path | Assertions |
|---|---|
| `tests/Feature/Security/DebugRouteRemovalTest.php` **(new)** | For each of the five URIs: an **unauthenticated** request returns 404; an **ordinary authenticated customer** returns 404; an **authenticated admin** returns 404. `Route::has()` is false for `add.gateways`, `remove.jobs`, `remove.contacts`, `cache.clear`, `update.campaign.cache` |
| Same file | **Behavioural proof, not just routing:** seed `jobs`, `failed_jobs` and `Contacts` rows across **two unrelated tenants**, issue each request unauthenticated, and assert every row still exists. This proves the destructive behaviour is gone, not merely that a route name changed |
| Same file | Seed `PaymentMethods` with operator-configured `options`, request `/add-gateways` unauthenticated, assert the stored `options` and `status` are byte-identical afterwards |
| Same file | **No `GET` route anywhere in the application truncates a table or deletes contacts:** assert the five names are absent, and that `DebugController` no longer exists via `class_exists()` |
| `tests/Feature/Security/NoHardcodedGatewayCredentialsTest.php` **(new)** | Assert `app/Http/Controllers/Debug/` contains no PHP file. Guards against the values being reintroduced by a revert |

**Entry criteria.** Owner assigned. Operator asked, in writing, whether any of
the five is in real use; the answer recorded in the pull request. Absence of an
answer within the review window resolves to delete.

**Exit criteria.** All five URIs 404 for every actor class. The behavioural
tests above pass with a positive assertion count. No file under
`app/Http/Controllers/Debug/` remains. No credential or bank detail from those
definitions survives anywhere in source.

**Rollback.** Revert the commit. This restores the routes and the controller,
including the defect — so rollback is only acceptable as an emergency response
to an unrelated regression, and must be followed by a re-landing. **Deleting
routes cannot break a customer flow**, because none of the five is linked from
any view, controller, job or test in the repository; that absence was the
evidence used to classify them.

**Dependencies.** None. Slice 0 item 1 has no prerequisite and blocks nothing
in its own right — it can land first and immediately.

---

#### 16.A.2 — Cross-tenant invoice count (D-19)

**Objective.** No customer-facing query may count, sum or display another
tenant's records.

**Evidence.** `resources/views/customer/dashboard.blade.php` line 320:
`Invoices::where('user_id', …)->where('status', UNPAID)->orWhere('status', PENDING)->count()`
emits `WHERE user_id = ? AND status = 'unpaid' OR status = 'pending'`. `AND`
binds tighter than `OR`, so the second disjunct carries no ownership predicate.

**The fix, stated precisely.** Replace the ungrouped `orWhere` with an
explicitly grouped status set under a single ownership predicate:

```php
Invoices::where('user_id', $actorUserId)
    ->whereIn('status', [Invoices::STATUS_UNPAID, Invoices::STATUS_PENDING])
    ->count();
```

`whereIn` removes the precedence trap by construction rather than by adding a
closure that a future edit could unbalance again.

**An honest scoping note, because the brief asked for Business/Account
scoping.** The `invoices` table has **only `user_id`** — no `business_id` and no
`workspace_id` (`database/migrations/2021_03_25_135511_create_invoices_table.php`
lines 19 and 29; a grep for either column in that migration returns zero).
Invoices are subscription invoices owned by the paying user. **Re-keying them to
a Business or Account is a schema migration and a billing-model change, not a
security fix**, and Slice 0 does not do it. The security defect is precisely the
missing grouping, and grouping fixes it completely. The *navigational*
correction — invoices belong at the Account level — is §9 and Slice 5.
Attempting the schema change inside a security slice would delay the fix and
couple it to RFC-005.

**Implementation paths (exact).**

| Path | Change |
|---|---|
| `resources/views/customer/dashboard.blade.php` lines 320–321 | Remove both inline queries |
| `app/Http/Controllers/User/UserController.php` | Pass pre-computed, actor-scoped counts into the view |

Slice 4 later moves these into a presenter with a query budget. Slice 0 does the
**minimum** that removes the disclosure: compute in the controller with an
explicit actor argument, and use `whereIn`. Slice 0 does not restructure the
dashboard.

**Test paths (exact).**

| Path | Assertions |
|---|---|
| `tests/Feature/Security/DashboardInvoiceScopeTest.php` **(new)** | **Fixtures: three unrelated tenants** — tenant A (the viewer), tenant B, tenant C. Each holds invoices in `paid`, `unpaid` **and** `pending` status, so **both sides of the former `OR` are populated for every tenant** |
| Same file | Tenant A's dashboard shows a count equal to A's own `unpaid` + `pending` only — asserted against an explicitly computed expected integer, never against a hard-coded literal |
| Same file | **Regression guard for the precedence bug specifically:** with A holding *zero* pending invoices and B and C holding several, A's count must not include them. This is the exact case the old query got wrong and a naive fixture would miss |
| Same file | The rendered response body does not contain B's or C's invoice totals |
| `tests/Feature/Dashboards/DashboardRenderTest.php` **(existing)** | Extended, not replaced, so the existing render assertions keep passing |

**Entry criteria.** None beyond an owner.

**Exit criteria.** The multi-tenant tests pass with a positive assertion count.
No `orWhere` remains in any customer-facing view or dashboard query path.

**Rollback.** Revert the commit; the count returns to its defective value. No
data is written by this change, so rollback carries no data risk.

**Dependencies.** None. Independent of items 1, 3 and 4. **Slice 4 depends on
this**, and must build on the corrected query rather than reintroducing an
inline one.

---

#### 16.A.3 — Provider credential authorization (D-9)

**Objective.** Server-side, fail-closed authorization on the provider
credential surface, so that menu visibility is never the boundary.

**Evidence.** All eight methods of
`app/Http/Controllers/Customer/Business/MessagingChannelsController.php` gate
only on `$this->authorize('view_numbers')` plus Business access — lines 78, 97,
128, 147, 201, 224, 264, 285. `config/customer-permissions.php` sets
`view_numbers` `'default' => true`, so every customer holds it.
`CustomerMenuBuilder::advancedItems()` line 216 hides the entry unless
`isAgency() && canManageWorkspace()`, but that is navigation, not
authorization. `routes/customer.php` line 388 redirects an actor with exactly
one accessible Business straight in.

**Scope boundary — this item does not duplicate the Slice 3 contract.** The
unmerged `agent/customer-experience-slice-3-messaging-provider-contract` branch
already owns, in its §4.7: relocating the surface to Agency-only advanced
settings, introducing `manage_advanced_provider` in
`config/customer-permissions.php`, **removing** the
`businesses/{businessUid}/channels` routes, and tests T-MSG-24 through T-MSG-26.
Slice 0 must not re-specify any of that.

The division is:

| Concern | Owner |
|---|---|
| **Close the hole on the routes that exist today**, additively, with no relocation and no new permission key | **Slice 0 §16.A.3** |
| Relocate the surface, introduce `manage_advanced_provider`, remove the old routes, redesign the advanced UI | **Slice 3 contract §4.7** |

Slice 0 adds a guard; Slice 3 deletes the guarded routes and the guard with
them. The two do not conflict, and Slice 0 does not have to land first or last.
**Slice 0 exists here because Slice 3 is a large foundation contract with new
schema and provider-neutral interfaces, and a live authorization hole must not
wait behind it.**

**The guard, stated exactly.** Every one of the eight methods gains a single
fail-closed check, before any existing tenancy resolution, requiring **all** of:

1. **Account tier is Agency.** A verified existing check is available:
   `App\Library\Navigation\WorkspaceCandidate::isAgency()` (line 40), reached
   through `App\Library\Navigation\CustomerContext::isAgency()` (line 106),
   which the resolved request context already exposes. Slice 0 reuses it rather
   than inventing a predicate. (The Slice 3 contract explicitly declined to name
   this method because it had not verified one; this document has, and records
   it so Slice 3 can reuse the citation.)
2. **Role is owner or active admin of that account** —
   `CustomerContext::canManageWorkspace()` (line 149).
3. **Entitlement.** The account's plan must actually package the advanced
   provider capability, resolved through the existing `EntitlementManager`
   rather than inferred from tier alone.
4. **Business scope.** The existing `resolveAccessibleBusiness()` /
   `resolveOwnedConnection()` boundary is **preserved verbatim** and still runs.
   The new check is additional, never a replacement.
5. **The existing `view_numbers` permission check is retained**, so the change
   is strictly narrowing.

**Failure response.** `abort(404)`, never 403, matching the established
existence-disclosure discipline in parent §5.4 and the GBP controller. A Core
or Growth actor must not learn that a provider surface exists.

**Implementation paths (exact).**

| Path | Change |
|---|---|
| `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` | One private guard method; one call at the head of each of the eight public methods |
| `app/Library/Navigation/CustomerMenuBuilder.php` | No change — it is already correct; it is simply no longer load-bearing |

No route file changes. No new permission key. No view changes. No migration.

**Test paths (exact).**

| Path | Assertions |
|---|---|
| `tests/Feature/Security/MessagingProviderAuthorizationTest.php` **(new)** | **By direct URL, for every one of the eight routes**, a Core owner, a Growth owner, an Agency staff member without manage rights, and a restricted Business user each receive **404** |
| Same file | **Mutation requests specifically**, not only reads: `POST` to connect, `PUT`/`PATCH` to update, and `POST` to enable and disable each return 404 for those actors, **and** the corresponding `SendingServer` / `CustomerBasedSendingServer` rows are unchanged afterwards |
| Same file | An Agency owner with the entitlement retains full access — proving the change is narrowing, not breaking |
| Same file | An Agency owner whose plan does **not** package the capability receives 404 — proving entitlement is checked, not just tier |
| Same file | `GET /channels` does not redirect a Core actor into the surface |
| `tests/Feature/Business/MessagingChannelsTest.php` **(existing)** | Extended so existing Agency-path coverage keeps passing |

**Entry criteria.** Confirm the Slice 3 branch has not yet merged. If it has
merged and its §4.7 relocation has landed, **this item is closed as superseded**
and must not be implemented — the routes it guards will no longer exist.

**Exit criteria.** Every non-Agency actor receives 404 on all eight routes by
direct URL and by mutation. No credential field is reachable from the normal
Business interface. Existing Agency behaviour unchanged.

**Rollback.** Revert the single controller commit. The guard disappears and the
prior behaviour returns. No data or schema is touched, so rollback is clean.

**Dependencies.** **External dependency on the Slice 3 contract** — see entry
criteria. Independent of items 1, 2 and 4.

---

#### 16.A.4 — Operator configuration leakage (D-21)

**Objective.** A customer response never carries an environment-variable name,
an operator instruction, or any other internal configuration detail. The
operator still gets the exact detail, in logs.

**Evidence.**
`app/Exceptions/GoogleBusinessProfile/GoogleBusinessProfileConfigurationException.php`
lines 65–73 return five messages naming `GOOGLE_BUSINESS_PROFILE_CLIENT_ID`,
`GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET` and `GOOGLE_BUSINESS_PROFILE_REDIRECT`.
The docblock calls these "operator-facing", but
`GoogleBusinessProfileController` lines 196 and 319 route them through
`redirectWithError()` into a **customer** flash.

**Why this is security-relevant and not only cosmetic.** It discloses which
integrations an install has configured and which specific settings are missing,
to an unprivileged customer, on demand. That is reconnaissance: it tells an
attacker the platform's integration surface and its current misconfiguration
state, and it invites social engineering of the operator using the exact
setting names.

**The fix.** Split the message into two audiences at the seam that already
exists:

| Audience | Content | Mechanism |
|---|---|---|
| Customer | Plain recovery guidance naming no setting: *"Google connections aren't available right now. This is something we need to fix on our side — we've been notified."* (§14.2) | A new `customerMessage()` on the exception, which is what `redirectWithError()` receives |
| Operator | The existing exact text, including the setting name | `Log::error()` at the catch site, with the reason code; `userMessage()` is retained and **renamed `operatorMessage()`** so its audience is unambiguous in the type itself |

Renaming rather than repurposing matters: the current name `userMessage()` is
exactly why a message written for operators reached customers.

**Implementation paths (exact).**

| Path | Change |
|---|---|
| `app/Exceptions/GoogleBusinessProfile/GoogleBusinessProfileConfigurationException.php` | Rename `userMessage()` to `operatorMessage()`; add `customerMessage()` returning setting-free copy |
| `app/Http/Controllers/Customer/Business/GoogleBusinessProfileController.php` lines 196, 319 | Log `operatorMessage()`; flash `customerMessage()` |

**Test paths (exact).**

| Path | Assertions |
|---|---|
| `tests/Feature/Security/ConfigurationLeakageTest.php` **(new)** | With each configuration reason forced, an authenticated customer's response body contains **none** of the three setting names, and contains the plain guidance |
| Same file | The operator detail **is** written to the log, so the fix does not destroy diagnosability |
| Same file | **Repository-wide regression guard:** no customer-reachable response contains a token matching `[A-Z][A-Z0-9_]{7,}` that resolves to a known configuration key. Scoped to an explicit route list so it stays deterministic |
| `tests/Feature/Security/GoogleBusinessProfileSecurityTest.php` **(existing)** | Extended |

**Entry criteria.** None beyond an owner.

**Exit criteria.** No customer-reachable response names a configuration
setting. Operator diagnosis is preserved in logs. `userMessage()` no longer
exists under that name.

**Rollback.** Revert; the leak returns. No data risk.

**Dependencies.** None. Slice 8 later adopts the §14.2 copy across all states
and must not reintroduce the setting names.

---

#### 16.A.5 — Explicitly *not* in Slice 0

| Item | Why not | Owner |
|---|---|---|
| **Payer no-op honesty (D-13, T-NOOP-1/2)** | This is Lane A's work. `agent/customer-experience-slice-5-wallet-payer-ux` already rewrites `BillingProfileManager` and `UsageBillingController` (§16.0). Specifying it here would produce two contracts for one change | **External dependency.** Re-audit `changePayer()` for the equality check **after Slice 5 merges**; if the no-op still reports success, it becomes a Slice 5 exit-criterion failure, not new Slice 0 scope |
| Provider relocation and `manage_advanced_provider` | Owned by the Slice 3 contract §4.7 | Slice 3 |
| Re-keying invoices to Business or Account | Schema and billing-model change, not a security fix | A future contract; §9 places it |
| Dashboard restructure | Not security | Slice 4 |
| Empty-state copy adoption | Not security | Slice 8 |

---

### Slice 1 — Terminology, translation and dead-link cleanup

* **Objective.** Remove every forbidden term and raw translation key from
  customer-facing copy, and make the legacy menu unreachable by customers.
* **Paths.** `resources/lang/en/locale.php`; the ten views listed in §4.6;
  `resources/views/panels/horizontalMenu.blade.php`;
  `app/Helpers/Helper.php` customer branch of `menuData()`;
  `app/Library/Navigation/CustomerContext.php`.
* **Prerequisites.** None. This is the entry slice.
* **Acceptance.** Zero occurrences of `Workspace`, `Sub Account`, `Sending
  Server`, `Sender ID` or `Originator` in rendered customer copy for any tier,
  Agency included. Zero rendered strings matching `locale.`. Every menu target
  resolves.
* **Tests.** T-TERM-1, T-TERM-2, T-I18N-3, T-NAV-4 (§17).
* **Interaction.** Edits `resources/lang/en/locale.php`, which two unmerged
  branches also edit (§16.0). Land before both, or after both.
* **Stop if.** A term cannot be replaced without changing a route name.

### Slice 2 — Account and Business frame clarity

* **Objective.** Make the frame unambiguous everywhere, not only in the sidebar.
  Move Conversations to Business scope. Add entitlement to menu emission.
* **Paths.** `app/Library/Navigation/CustomerMenuBuilder.php`;
  `app/Library/Navigation/CustomerContext.php`;
  `app/Library/Navigation/CustomerShellComposer.php`;
  `resources/views/panels/{sidebar,navbar,breadcrumb}.blade.php`;
  `app/Http/Controllers/Customer/ChatBoxController.php`.
* **Prerequisites.** Slice 1.
* **Acceptance.** Every page names its frame and its Business or account. A menu
  entry appears only when route, authorization **and** entitlement all allow it.
  A Core customer never sees Google Business Profile. Conversations are scoped
  to the selected Business.
* **Tests.** T-NAV-5, T-NAV-6, T-CTX-6, T-ENT-1.
* **Stop if.** Entitlement resolution cannot be added to the context within the
  established query budget.

### Slice 3 — Authentication and error-page completion

* **Objective.** Finish what Slice 2 of the earlier programme started: remove
  the last inherited artwork and rebrand the error pages.
* **Paths.** `resources/views/auth/payment/` (7 files);
  `resources/views/customer/Contacts/{subscribe,unsubscribe}_form.blade.php`;
  `resources/views/errors/` (7 files).
* **Prerequisites.** None; runs in parallel with Slice 2.
* **Acceptance.** No `images/pages/*` reference remains in any customer-reachable
  or contact-reachable view. Error pages state what happened and offer one route
  back.
* **Tests.** T-BRAND-1, T-BRAND-2.
* **Stop if.** Removing an image breaks a layout that has no token-based
  replacement.

### Slice 4 — Dashboard rebuild

* **Objective.** Replace the inherited dashboard with the Business-scoped Home
  of §13.2, and fix D-19.
* **Paths.** `app/Http/Controllers/User/UserController.php`; a new presenter
  under `app/Library/Dashboard/`; `resources/views/customer/dashboard.blade.php`.
* **Prerequisites.** Slice 2, for a reliable frame.
* **Acceptance.** Zero Eloquent calls in the Blade template. Every figure is
  Business-scoped. The invoice count is correctly parenthesized. Every tile
  carries an interpretation. The five questions of §13.1 are answered in order.
  A stated query budget is asserted by a test.
* **Tests.** T-DASH-1 through T-DASH-4, T-PERF-1.
* **Stop if.** A required figure cannot be computed within the query budget.

### Slice 5 — Settings and billing relocation

* **Objective.** Establish the settings information architecture of §8 and §9.
  Move payer to the Account level, gate every billing control, and make no-ops
  honest.
* **Paths.** `routes/customer.php`;
  `app/Http/Controllers/Customer/Business/UsageBillingController.php`;
  `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`;
  `app/Library/Usage/BillingProfileManager.php`;
  `resources/views/customer/business/usage-billing/show.blade.php`;
  `resources/views/customer/workspaces/show.blade.php`.
* **Prerequisites.** Slices 1 and 2.
* **Acceptance.** Payer is unreachable from the Business surface. Every control
  is gated on the capability that will authorize its write. A submission that
  changes nothing says so, writes no transition row and dispatches no event.
* **Tests.** T-BILL-1 through T-BILL-4. **T-NOOP-1 and T-NOOP-2 are an external
  dependency**, owned by Lane A (§16.A.5): re-audit `changePayer()` for the
  equality check after Slice 5 merges rather than specifying it twice.
* **Interaction.** **Blocked by `agent/customer-experience-slice-5-wallet-payer-ux`**
  (§16.0), which already rewrites `UsageBillingController`,
  `BillingProfileManager`, `WorkspaceController` and both views. Land or abandon
  that branch before starting.
* **Stop if.** Moving payer would change any RFC-005 ledger or consent
  semantic.

### Slice 6 — Managed phone experience

* **Objective.** Ship the flow of §10.1 and remove provider concepts from the
  ordinary path.
* **Paths.** New controller and views under
  `app/Http/Controllers/Customer/Business/` and
  `resources/views/customer/business/phone/`;
  `resources/views/customer/Outreach/_originator.blade.php`;
  `app/Http/Controllers/Customer/Business/MessagingChannelsController.php`
  (adding the Agency gate only).
* **Prerequisites.** Slices 1, 2 and 5. Requires the parent contract's §10 and
  §11 mechanism to be authorized separately.
* **Acceptance.** No provider name, credential field, sending server, sender ID
  or originator is reachable by any Core or Growth actor, **by URL as well as by
  menu**. The compose screen shows one sending line, not a control.
* **Tests.** T-SEC-3, T-SEC-4 (parent), plus T-PHONE-1 through T-PHONE-3.
* **Stop if.** Managed provisioning is not yet authorized — in that case ship
  only the Agency gate on `MessagingChannelsController`, which closes D-9 on its
  own and is independently valuable.

### Slice 7 — Automation recipes

* **Objective.** Recipe catalogue as the default entry; the existing form
  becomes *Build your own*.
* **Paths.** `app/Http/Controllers/Customer/Business/AutomationsController.php`;
  a recipe definition under `app/Library/Automation/Recipes/`;
  `resources/views/customer/Automations/`.
* **Prerequisites.** Slice 6, because every messaging recipe depends on a
  resolvable Business phone.
* **Acceptance (corrected Round 1).** The catalogue **mechanism** ships, along
  with the §11.3 card contract and *Build your own*. **The catalogue itself
  renders zero cards at this base**, because the expansion contract §7.0 admits
  no recipe in state 1 alone (§11.2). A card appears only when its recipe has
  cleared state 2 upstream. Every card that does appear states its trigger,
  outcome, audience, cost, gaps and safety verdict. The custom builder contains
  no sending-server or sender select.
* **Tests.** T-AUTO-1 through T-AUTO-4.
* **Stop if.** A recipe would be rendered whose upstream state is 1 alone or 3,
  or whose trigger is not present in `AutomationTriggerType`.
* **Interaction.** Recipe classification is owned upstream. If Slice 7 believes
  a recipe should appear, the change is made in the expansion contract first,
  never by adding a card here.

### Slice 8 — Empty, loading, error and help states

* **Objective.** One empty-state component with four states; the loading
  vocabulary of §14.3; the copy of §14.2 adopted across all 32 call sites.
* **Paths.** `resources/views/components/empty-state.blade.php`;
  `resources/views/layouts/partials/empty-state.blade.php`; a new loading
  component; the 32 consuming views;
  `app/Exceptions/GoogleBusinessProfile/GoogleBusinessProfileConfigurationException.php`.
* **Prerequisites.** Slices 1 and 2.
* **Acceptance.** One empty-state implementation. Every state distinguished by a
  word. No environment-variable name reachable by a customer. A plan exclusion
  produces a `locked` state, never a 404.
* **Tests.** T-EMPTY-1 through T-EMPTY-3, T-ERR-1, T-ERR-2.
* **Stop if.** Consolidating the two components would change any existing call
  site's rendered meaning.

### Slice 9 — Responsive and accessibility pass

* **Objective.** One layout master. Mobile navigation per §8.3 and §8.5.
  Landmarks, focus and keyboard operation everywhere.
* **Paths.** `resources/views/layouts/` (retiring three masters);
  `resources/views/panels/{navbar,sidebar,breadcrumb}.blade.php`; the table
  component; `config/custom.php`.
* **Prerequisites.** Slices 2 and 8.
* **Acceptance.** One layout master. Skip link and `<main>` on every
  authenticated page. The view-as banner renders in every branch. Every flow
  completable at 375px and by keyboard alone. Every table degrades to a stacked
  list below 768px.
* **Tests.** T-A11Y-1 through T-A11Y-5, T-MOBILE-1, T-MOBILE-2, T-VIEWAS-3.
* **Interaction.** Retiring layout masters depends on retention decision §12.4,
  which is already resolved. Confirm nothing else reads `mainLayoutType`.
* **Stop if.** Any admin screen depends on a retired master.

### Slice 10 — Visual polish

* **Objective.** Retire the inherited page stylesheets and apply §15 uniformly.
* **Paths.** `resources/scss/`; the views that load `css/base/pages/*`.
* **Prerequisites.** Every earlier slice.
* **Acceptance.** No `css/base/pages/*` stylesheet is loaded by a customer view.
  Contrast and focus requirements met throughout.
* **Tests.** T-VIS-1, T-VIS-2.
* **Stop if.** Removing a stylesheet changes layout in a way tokens cannot
  express.

### Dependency graph

```
        ┌─────────────────────────────────────────────┐
        │  SLICE 0 — SECURITY (§16.A)  RELEASE BLOCKER │
        │  0.1 debug routes    0.2 invoice scope       │
        │  0.3 provider authz  0.4 config leakage      │
        │  (0.1–0.4 are mutually independent)          │
        └───────────────────────┬─────────────────────┘
                                │  must exit first
                                ▼
        1 ──┬── 2 ──┬── 4
            │       ├── 5 ── 6 ── 7
            │       └── 8 ── 9 ── 10
            └── 3 (parallel)
```

Slice 0's four items have no dependencies on each other and may be worked in
parallel by one owner. Item 3 alone carries an external dependency: it is
closed as superseded if the Slice 3 contract's relocation lands first.

Three later slices inherit a Slice 0 obligation and must not undo it: **Slice 4**
builds on the corrected invoice query, **Slice 6** must not reintroduce a
customer-reachable provider surface when Slice 0's guard is removed alongside
the relocated routes, and **Slice 8** must not reintroduce configuration names
while adopting the §14.2 copy.

---

## 17. TEST MATRIX

Extends, and never duplicates, the existing suites — notably
`tests/Feature/DesignSystem/CustomerShellNavigationTest.php`,
`tests/Feature/Security/{CustomerContextSecurityTest,ViewAsRouteBoundaryTest}.php`,
`tests/Feature/Workspace/{CustomerContextResolutionTest,ViewAsClientTest}.php`
and the six suites under `tests/Feature/Auth/`.

**Every test has exactly one owning slice.** Slice 0's tests are listed first
because they gate every other row: no slice below may be marked complete while
a `T-SEC0-*` row is failing.

| ID | Assertion | Owning slice |
|---|---|---|
| **T-SEC0-1** | Each of the five debug URIs returns 404 for an unauthenticated visitor, an ordinary authenticated customer, and an authenticated admin | **0.1** |
| **T-SEC0-2** | With queue and contact rows seeded across two unrelated tenants, an unauthenticated request to `/remove-jobs` and `/remove-contacts` destroys nothing | **0.1** |
| **T-SEC0-3** | An unauthenticated request to `/add-gateways` leaves stored `PaymentMethods` `options` and `status` byte-identical | **0.1** |
| **T-SEC0-4** | No `GET` route in the application truncates a table or deletes contacts; `DebugController` no longer exists | **0.1** |
| **T-SEC0-5** | No file remains under `app/Http/Controllers/Debug/`, so the hardcoded credentials and bank details cannot return by revert | **0.1** |
| **T-SEC0-6** | With three unrelated tenants each holding `paid`, `unpaid` and `pending` invoices, the dashboard count equals the viewer's own `unpaid` + `pending` only | **0.2** |
| **T-SEC0-7** | Precedence regression guard: a viewer with zero pending invoices, while other tenants hold several, sees none of them counted | **0.2** |
| **T-SEC0-8** | All eight provider-credential routes return 404 by direct URL for a Core owner, a Growth owner, an Agency staff member without manage rights, and a restricted Business user | **0.3** |
| **T-SEC0-9** | The same actors' mutation requests (connect, update, enable, disable) return 404 **and** leave the `SendingServer` and `CustomerBasedSendingServer` rows unchanged | **0.3** |
| **T-SEC0-10** | An Agency owner with the entitlement retains full access; an Agency owner without it receives 404 | **0.3** |
| **T-SEC0-11** | `GET /channels` does not redirect a Core actor into the provider surface | **0.3** |
| **T-SEC0-12** | No customer-reachable response contains `GOOGLE_BUSINESS_PROFILE_CLIENT_ID`, `_CLIENT_SECRET` or `_REDIRECT`; the plain guidance is shown instead | **0.4** |
| **T-SEC0-13** | The operator detail is still written to the log, so diagnosability survives the fix | **0.4** |
| **T-NAV-4** | Every URL emitted by any rendered customer navigation resolves to a registered route | 1 |
| **T-NAV-5** | Menu contents differ correctly across Core owner, Growth owner, Agency owner, Agency admin, Agency staff, restricted staff and view-as | 2 |
| **T-NAV-6** | Account-frame and Business-frame entries are disjoint in every role and context | 2 |
| **T-ENT-1** | A menu entry never appears for a feature the account's plan excludes; specifically, a Core actor never sees Google Business Profile | 2 |
| **T-AUTHZ-1** | Direct URL access to every Business-scoped route is refused for a non-member with 404, never 403 | 2 |
| **T-AUTHZ-2** | After the managed-messaging cutover, no provider-credential surface is reachable by any customer at all, because the routes no longer exist. Distinct from T-SEC0-8, which proves the **interim** guard on the routes as they stand today | 6 |
| **T-TERM-1** | No rendered customer response contains `Workspace`, for any tier including Agency | 1 |
| **T-TERM-2** | No rendered customer response contains `Sending Server`, `Sender ID`, `Originator`, `Sub Account`, `Account SID`, `Auth Token` or `API Key` | 1 |
| **T-I18N-3** | No rendered response, customer or admin, contains a string matching `locale.` | 1 |
| **T-BRAND-1** | No customer-reachable or contact-reachable view references `images/pages/*` | 3 |
| **T-BRAND-2** | The footer renders the configured company name and the current year, never an inherited default | 3 |
| **T-CTX-6** | A Core or Growth actor with one Business lands on that Business's Home, with no account-selection screen | 2 |
| **T-CTX-7** | An Agency owner lands on the Agency Home with the Client accounts list | 2 |
| **T-CTX-8** | A staff member restricted to one Business lands on it directly and cannot enumerate any other | 2 |
| **T-VIEWAS-3** | The view-as banner renders in every layout branch and on every page of a view-as session | 9 |
| **T-VIEWAS-4** | Billing, funding, plan, staff, provider and delete entries are absent from the menu during view-as, not merely disabled | 2 |
| **T-BILL-1** | The payer control is not present in any Business-level response | 5 |
| **T-BILL-2** | Every billing control is rendered only to an actor whose write would be authorized | 5 |
| **T-BILL-3** | A non-payer sees a read-only balance with an explanation and no funding control | 5 |
| **T-BILL-4** | A Core or Growth actor never sees the word payer | 5 |
| **T-NOOP-1** | Submitting an unchanged payer reports no change, writes no transition row and dispatches no event. **External dependency — owned by Lane A (§16.A.5). Re-audit after Slice 5 merges; do not implement here** | 5, external |
| **T-NOOP-2** | The same holds for spend cap, billing contact and feature limit. Same external ownership as T-NOOP-1 | 5, external |
| **T-ERR-1** | Extends T-SEC0-12 beyond Google Business Profile: no customer-reachable response, in any empty, error or disabled state, contains a token matching `[A-Z][A-Z0-9_]{7,}` that resolves to a configuration key | 8 |
| **T-ERR-2** | A plan exclusion produces a `locked` empty state with a reason, never a 404 | 8 |
| **T-EMPTY-1** | Exactly one empty-state component exists and every call site uses it | 8 |
| **T-EMPTY-2** | Every empty state carries a state word, an explanation and either an action or an owner hint | 8 |
| **T-EMPTY-3** | No empty state contains an implementation noun from the §3.2 forbidden list | 8 |
| **T-DASH-1** | The dashboard view executes zero database queries | 4 |
| **T-DASH-2** | Every dashboard figure is scoped to the selected Business | 4 |
| **T-DASH-3** | The invoice count excludes other customers' invoices | 4 |
| **T-DASH-4** | Every dashboard action targets a Business-scoped route | 4 |
| **T-PHONE-1** | The compose screen renders one sending line and no provider control | 6 |
| **T-PHONE-2** | No provider-costing call occurs before funds are reserved | 6 |
| **T-PHONE-3** | A Business with no phone renders the attention state, not a disabled form | 6 |
| **T-AUTO-1** | No recipe is rendered whose upstream state is 1 alone or 3. At this base that means the catalogue renders zero cards, and the mechanism is proven through the *Build your own* path (§11.2) | 7 |
| **T-AUTO-2** | Every recipe card states trigger, outcome, audience, cost, gaps and safety verdict | 7 |
| **T-AUTO-3** | The custom builder renders no sending-server or sender select | 7 |
| **T-AUTO-4** | A recipe is a draft until explicitly activated, and drafts never execute | 7 |
| **T-A11Y-1** | Every authenticated page has one `<main>`, one `<h1>` and a skip link | 9 |
| **T-A11Y-2** | Every interactive element has an accessible name | 9 |
| **T-A11Y-3** | Navigation, banner and status regions expose correct landmarks and live regions | 9 |
| **T-A11Y-4** | Every flow is completable by keyboard, with a visible focus indicator throughout | 9 |
| **T-A11Y-5** | No state is conveyed by colour alone | 9 |
| **T-MOBILE-1** | At 375px, no page scrolls horizontally | 9 |
| **T-MOBILE-2** | At 375px, every primary destination is reachable in at most two taps | 9 |
| **T-VIS-1** | No customer view loads a `css/base/pages/*` stylesheet | 10 |
| **T-VIS-2** | Text and non-text contrast meet WCAG AA throughout | 10 |
| **T-PERF-1** | Home, Contacts, Campaigns and Billing each stay within a stated query budget | 4, 5 |

---

## 18. GENUINELY OPEN HUMAN DECISIONS

Everything in §4 is a mechanically provable defect and needs no decision. What
follows genuinely does. Each carries a recommendation; **none is locked here.**

| # | Decision | Recommendation | Why it is genuinely open |
|---|---|---|---|
| H-1 | **Final illustration style** | Ship on typography, product marks and lightweight shapes; treat illustration as a later, additive, purely decorative layer | It is a brand-identity decision with real cost. §15.2 makes sure nothing is blocked on it either way. |
| H-2 | **Exact brand palette refinement** | Keep the current token structure; refine hue and saturation once against real screens, not in the abstract | The structure is provably adequate; the values are taste. |
| H-3 | **Top-left context label: "Account" or the company name** | Show **the company name**, with the level as a small label above it, as `panels/sidebar.blade.php` already does | Both are defensible. The company name is more useful in a switcher; the level word is clearer on first use. |
| H-4 | **How much advanced functionality ordinary Growth customers see** | Keep Advanced strictly Agency-only at launch; revisit with evidence of Growth customers asking for it | Widening it is easy later; narrowing it after customers depend on it is not. |
| H-5 | **Dashboard default time range** | **Last 30 days**, with 7 and 90 available | 30 days suits a monthly-rhythm local business; 7 is noisy at low volume. Weakly held. |
| H-6 | **Whether platform administration gets a visually distinct shell** | **Yes** — a different header treatment and an unmistakable environment marker | It reduces the risk of an operator acting on a customer's data believing they are elsewhere. But it is cost against a small internal audience, so it is a judgement call. |
| H-7 (raised by this audit) | **Whether `calendar` and `forms`, packaged into every tier with no implementation, are removed from packaging or built** | Remove them from the packaged feature list until they exist | Today any plan comparison built from packaged features promises two products that do not exist. Leaving it is a commercial-honesty risk, not a UX preference. |
| H-8 (raised by this audit) | **Whether the horizontal and detached layout masters are deleted now or after Slice 9** | Delete in Slice 9; retention §12.4 already authorizes it | No unmerged branch touches the layout masters (§16.0), so there is no scheduling pressure either way. The real question is whether the admin portal is ready to move to the single retained master at the same time. |

---

## 19. VALIDATION RECORD

Performed before commit, in this worktree, at the base SHA.

| Check | Result |
|---|---|
| Base SHA is `6c820c801da08ecfd6165d1d3a52ae6336606f0c` | Confirmed by `git rev-parse origin/main` and by the worktree's own `HEAD` |
| Branch created fresh from that SHA | `agent/customer-experience-ux-redesign-contract` |
| No unmerged Lane branch merged, rebased or cherry-picked | Confirmed; the worktree has one commit ahead of base |
| Every cited repository path exists | Confirmed by direct read of each |
| Every cited route name exists in `routes/customer.php`, `routes/auth.php` or `routes/admin.php` | Confirmed by name inventory; all 22 `CustomerMenuBuilder` targets verified |
| Every role and tier claim checked against authorization code | `config/customer-permissions.php` defaults, `CustomerContext` predicates, per-controller `authorize()` calls, and the two plan-packaging migrations |
| `git diff --check` | Clean |
| Secret-shaped-string sweep | No token, key or credential value. Environment-variable **names** appear only where quoting the D-21 defect, and no value accompanies them |
| Exactly one file changed | `docs/automation/AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md` |
| No source, migration, configuration, dependency or generated asset changed | Confirmed by `git status` and `git show --stat` |
| Tests executed | **None.** No PHP runtime is available in this environment (§2.2). This is an audit and contract; it authorizes no code and changes no behaviour, so no test could pass or fail differently because of it |

### 19.1 Correction Round 1 — validation performed before this commit

| Check | Result |
|---|---|
| Expected HEAD `afc00c3bf62a120aef53e1ffbd793af8f6b7ae26` | Confirmed for both local and `origin/agent/customer-experience-ux-redesign-contract` before editing |
| Working tree clean before editing | Confirmed, `git status --porcelain` empty |
| Current `origin/main` recorded | Advanced to `98e063aabf0f8f67bc02190ce761064f8889ed22`; `git diff --name-status 6c820c8..98e063a` returns exactly one added documentation file, so **no code-level claim in this document is invalidated**. Not merged or rebased, per instruction |
| No new branch, PR, merge, rebase or force-push | Confirmed; same branch, ordinary commit and push |
| Slice 0 evidence re-verified at the base SHA | `DebugController` re-read in full (431 lines, 6 public methods, no constructor, no `middleware()` call); `addGateways()` confirmed as `updateOrCreate` over 29 gateway definitions; `updateCampaignCache()` confirmed to resolve any campaign uid with no ownership check |
| Invoice scoping claim re-verified | `database/migrations/2021_03_25_135511_create_invoices_table.php` has `user_id` only; a grep for `business_id` or `workspace_id` in that migration returns 0. The §16.A.2 fix is therefore grouping, not re-keying |
| Agency-tier predicate cited in §16.A.3 verified to exist | `WorkspaceCandidate::isAgency()` line 40, reached via `CustomerContext::isAgency()` line 106; `canManageWorkspace()` line 149 |
| `manage_advanced_provider` confirmed absent | Grep across `app/`, `config/`, `database/` returns no match, matching the Slice 3 contract's own finding. §16.A.3 therefore does **not** depend on it |
| Duplication check against in-flight contracts | Slice 3 §4.7 owns provider relocation; Lane A owns the payer no-op. Both are recorded as external dependencies in §16.A.3 and §16.A.5 and are **not** re-specified |
| Automation reconciliation | §11.2 rewritten against the merged expansion contract §7.0. No producer claim is made in this document; every classification is cited to that contract, and two pairs outside its seventeen are marked unclassified upstream rather than given a verdict here |
| Test ownership | Every test has exactly one owning slice. Three prior overlaps corrected: T-AUTHZ-2 rescoped to the post-cutover state, T-ERR-1 rescoped as the repository-wide extension of T-SEC0-12, T-NOOP-1/2 marked external |
| Stale-phrase sweep | "three recipes are buildable", "three cards", "out of UX scope", "outside this contract's UX remit", "on its own branch", "excluded from those slices' allowlists" — all removed or explicitly superseded and labelled as withdrawn |
| Reference and path validation | Every `§` cross-reference resolves to a heading in this document or is explicitly qualified as `Parent §`, `Retention §` or `expansion contract §`. Every cited repository path exists, except three that are explicitly introduced as new in the slice plan |
| `git diff --check` | Clean |
| Secret-shaped-string sweep | No token, key, credential, routing number or account number value. §5.4 describes the hardcoded values in `addGateways()` **without reproducing any of them**. Configuration-variable **names** appear only where quoting the D-21 and §16.A.4 defect, with no values |
| Exactly one file changed | `docs/automation/AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md` |
| No source, migration, configuration, dependency or generated asset changed | Confirmed by `git status` and `git show --stat` |
| Tests executed | **None**, for the same reason as the first round. Slice 0 specifies tests; it does not authorize writing them on this branch |

---

## 20. APPENDIX A — WHY THIS DOCUMENT DISAGREES WITH ITS OWN BRIEF IN FOUR PLACES

Recorded so a reviewer can accept or reject each disagreement deliberately.

1. **The login illustration is already fixed** (D-1). The brief lists it as a
   current defect; Slice 2 removed it at `origin/main`. Reporting it as
   outstanding would have sent an implementer to change code that is already
   correct.
2. **`/outreach/campaigns` no longer 404s** (D-8). Slice 1B added the entry
   action. The same reasoning applies.
3. **The legacy footer is data, not code** (D-4). The seam is correct and falls
   back to `AI Business OS`. The correction is an operator data change plus a
   shipped default.
4. **None of the requested automation recipes may ship in the guided catalogue
   at this base** (§11.2). *Corrected in Round 1.* The first version of this
   appendix said eight of eleven "cannot be built" and implied the other three
   could ship. Both halves were wrong in a way that mattered: one recipe is
   genuinely runnable through the legacy form, and none of the three is
   acceptable in a guided, managed catalogue until messaging-identity, provider
   and wallet work lands. The classification now defers to the merged automation
   expansion contract §7.0 rather than being re-derived here.

The brief asked for these to be treated as leads rather than assumptions, and
for exact current evidence. That is what §4 records.

---

## 21. APPENDIX B — WHAT CORRECTION ROUND 1 CHANGED

| Area | Before | After |
|---|---|---|
| Security defects | Recorded as observations "outside this contract's UX remit", to be fixed "on their own branch", with no owner and no schedule | **§16.A Security Remediation Slice 0** — an owned, release-blocking prerequisite for every other slice, with per-item paths, tests, entry and exit criteria, rollback and dependencies |
| Debug route inventory | Five routes named; two described in detail | All five inventoried with method, verb, effect and blast radius. **Two upgraded on re-reading:** `/add-gateways` overwrites live payment configuration including offline-payment bank details, and `/update-campaign-cache` mutates any tenant's campaign |
| Disposition of debug routes | None proposed | Decided per route against an evidenced-need test, defaulting to delete. Recommendation: delete all five and the controller |
| Invoice fix | Named as a defect | Specified as explicit `whereIn` grouping, with an honest note that the table has no Business or Account key and that re-keying is a separate contracted migration, not a security fix |
| Provider authorization | Named as a defect | Specified as an additive fail-closed guard on the existing eight methods, with the Agency-tier predicate verified and cited, and **explicitly bounded** so it does not duplicate the Slice 3 contract's relocation |
| Configuration leakage | Named as a defect | Specified as an audience split, renaming `userMessage()` to `operatorMessage()` so the type itself records the audience |
| Payer no-op | Owned by Slice 5 here | **External dependency** — Lane A owns it; re-audit after Slice 5 merges (§16.A.5) |
| Recipe availability | "Three recipes are buildable today" | Withdrawn. Three-state model adopted from the merged expansion contract; catalogue ships with zero cards; two pairs marked unclassified upstream rather than judged here |
| Test matrix | 48 assertions | **61 assertions.** Thirteen `T-SEC0-*` rows added, all gating; three prior ownership overlaps corrected (T-AUTHZ-2, T-ERR-1, T-NOOP-1/2) |
| Dependency graph | Slices 1–10 | Slice 0 added as a blocking root, with the three later slices that inherit an obligation named |

---

AI BUSINESS OS CUSTOMER EXPERIENCE AND NAVIGATION REDESIGN — CORRECTION ROUND 1 READY FOR HUMAN/CHATGPT REVIEW
