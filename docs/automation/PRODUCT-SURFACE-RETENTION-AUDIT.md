# AI Business OS — Product Surface Retention Audit

**Status: DOCS-ONLY AUDIT. No implementation, deletion, or refactor is authorized or performed by this document. All eight human-decision-queue items are now RESOLVED (§12). The Design System M2 page-by-page rollout remains PAUSED pending human merge of this audit.**

---

## 0. Governance

```
audit_type: product_surface_retention
docs_only: true
implementation_has_occurred: false
deletion_has_occurred: false
design_rollout_paused: true
slice_7a_started: false
slice_7a_visual_implementation_started: false
human_product_decisions_resolved: true
decision_queue_open_items: 0
retention_audit_status: final_recommendation
old_m2_rollout_supersession_proposed: true
old_m2_rollout_supersession_applied: false
roadmap_replacement_requires_separate_docs_change: true
roadmap_replacement_is_proposal_only: true
advance_automatically: false
merge_authority: human_only
no_force_push: true
no_deployment: true
```

Verified base: `origin/main` at `246d85bdeb4dab31d6fd0012d0dd9ddcf0a01237` (human-merged Design System M2 Slice 6 completion — PR #184, merging `agent/design-system-m2-slice6-chatbox-conversations`). Branch: `chore/product-surface-retention-audit`, created fresh from that exact SHA, pre-resolution head `aeac68773846174cf85979238a3c20528bfeba35`. This branch changes **exactly one file**: this document. `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` is explicitly **not** edited in this branch — replacing the old rollout map with the surviving roadmap (§9/§10) is a separate future docs-only change, applied only after this audit is human-merged.

---

## 1. Why this audit exists

The Design System M2 rollout map (`docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` §8) was built around progressively modernizing the *entire inherited Ultimate SMS application*, page by page, in rollout-map order. That assumption predates a clearer statement of where the product is actually going: **AI Business OS**, a vertical operating system for local businesses (photobooth businesses as the initial niche, architecture generalizable to other local-business verticals) — substantially closer in shape to replacing a tool like GoHighLevel for that customer than to preserving Ultimate SMS's own generic bulk-SMS-marketing surface area as-is.

Continuing the M2 rollout mechanically, slice by slice, risks spending real design effort modernizing legacy screens that will not exist in the shipped product. This audit inventories what exists, maps it against the stated target direction and the RFC-001 through RFC-005 architecture actually built so far, and recommends — for human decision, not automatic action — which surfaces deserve continued incremental redesign, which deserve a from-scratch rebuild on top of retained backend capability, and which are legacy vendor/product-distribution machinery worth scheduling for eventual deletion.

**Nothing is deleted, redesigned, or refactored by this document.** Every classification below is a recommendation for a human to accept, reject, or override.

---

## 2. Method and sources read

Read in full or in targeted, representative depth before drafting:

- `CLAUDE.md`, `AGENTS.md` — repository conventions and non-negotiable rules.
- `docs/automation/DESIGN-SYSTEM-CONTRACT.md` (Milestone 1) and `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` (Milestone 2, including the full §8 rollout map).
- Every merged Design System M2 slice contract (Slices 2, 3, 5, 6 and their security-remediation contracts) — cross-checked against the actual current tree to confirm completion (§4 below).
- `docs/rfcs/RFC-001-BUSINESS-CORE.md` (full), `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md` (summary/goals/scope), `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md` (through §9), `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md` (through §5's repository findings), `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` (header/status) — the current, forward-looking architecture.
- Closure/conformance evidence confirming RFC-001–RFC-005 are each technically complete and merged: `RFC-003-M6-CONFORMANCE.md`, `RFC-004-M4-CONFORMANCE.md`, `RFC-005-M6-CLOSURE.md` (all "RELEASE-READINESS PASS" / "technically complete and tagged").
- A mechanical, repo-wide inventory of every Blade view under `resources/views/{customer,admin,auth}/**`, traced into `routes/**`, `app/Http/Controllers/**`, and relevant models — grouped into product modules (§4).
- Four targeted deep-dive investigations: Campaigns/Quick-Send/Channels; Plugins/Marketplace, legacy Theme Customizer, and system/vendor admin machinery; Sending Server UX; Reports, Admin/Platform, Sub-Accounts vs. Workspace, and Billing/Payments consolidation (§5–§8).
- `config/permissions.php` — every declared permission category, cross-checked against actual views/routes.

The old M2 rollout table is treated **only as an inventory of legacy redesign surfaces**, never as a statement that a feature should survive.

---

## 3. Very important — plugin vs. library

Two unrelated things share the word "plugin" in this codebase and must not be conflated:

- **(A) Product plugin / marketplace features** — `admin/Plugins/**`, the legacy marketplace/plugin-installation workflow, legacy Theme Customizer. These are product features and are genuine retention-decision candidates (§6).
- **(B) Implementation libraries** — Select2, SweetAlert2, Toastr, DataTables, ApexCharts, and similar JS/CSS dependencies used throughout retained screens. These are technical dependencies, entirely out of scope for this audit. A retained screen is never classified for deletion merely because it uses a library whose path contains "plugins."

---

## 4. Completed / paused Design System M2 work (not reopened)

| Slice | Scope | Files | Status |
|---|---|---|---|
| 1 | Foundation (tokens, component library, chart tokens, chat-background fix, shared-chrome icon migration, errors) | 67 | **Done.** Confirmed live: `<x-card>`/`<x-button>`/`<x-ds-icon>` markers present across auth/dashboard/CRM/ChatBox views. |
| 2 | Authentication & Profile (`auth/**` excl. `auth/payment/**`, `Installer/**`) | 27 | **Done.** `auth/login.blade.php` confirmed adopting design-system components. |
| 3 | Dashboards | 5 | **Done.** `customer/dashboard.blade.php` confirmed adopting design-system components. |
| 4 | Reports & Analytics | 12 | **Deliberately skipped.** Not resumed by this audit — see §6.6 for a direction recommendation. |
| 5 | Contacts & CRM (incl. Opportunities) | 30 | **Done.** `customer/Contacts/create.blade.php` confirmed adopting design-system components. |
| 6 | ChatBox / Conversations | 4 | **Done.** Just completed and merged (PR #184). |

**133 files** across Slices 1, 2, 3, 5, 6 are genuinely finished and are not reopened, undone, or reclassified by this audit. Slice 4's 12 files remain skipped, addressed by recommendation in §6.6/§9, not by resuming page-by-page work.

**One scoping note on completed work, for the record, not for correction:** Slice 2's own scope bundled `Installer/**` alongside `auth/**`. §6.4 below finds `Installer/**` is itself a first-run self-hosted-installation wizard — a strong DELETE candidate as vendor-distribution machinery. If Slice 2 already touched `Installer/**` files, that is already-completed work per this repository's own discipline ("do not undo completed work") — noted here only so a future slice does not repeat the same category of surface with a redesign effort before checking this audit first.

---

## 5. Repo-wide product surface inventory

Mechanically inventoried, grouped into 25 coherent product modules. **Grand totals: 313 Blade files, ~92,900 lines** under `resources/views/{customer,admin,auth}/**` (excluding `components/`, `panels/`, `layouts/`, `emails/`, `vendor/` — shared chrome/infra, not product surfaces).

| # | Module | Files / Lines | Controller(s) | What it does | Old M2 slice |
|---|---|---|---|---|---|
| 1 | CRM / Contacts | 23 files / ~5,484 | `ContactsController`, `ContactGroupsController`, `BlacklistsController` (×2) | Contact CRUD, custom fields, CSV import, opt-in/out keywords, segments, blacklists | 5 (done) |
| 2 | Opportunities | 6 files / ~991 | `OpportunityController`, `OpportunityRunController` | RFC-002 AI-suggested "next best action" work queue | 5 (done) |
| 3 | ChatBox / Conversations | 4 files / ~1,354 | `ChatBoxController` | Two-way SMS inbox/live chat | 6 (done) |
| 4 | Campaigns (bulk send, 6 channels) | 26 files / ~14,555 | `CampaignController` | Quick-send, builder, import per channel (SMS/MMS/Voice/WhatsApp/Viber/OTP) | 7a/7b/7c |
| 5 | Automations | 6 files / ~2,028 | `AutomationsController` | Trigger-based recurring messages (e.g. birthday) | 8 |
| 6 | Templates (+ DLT tags) | 6 files / ~2,442 | `TemplateController`, `TemplateTagsController` | Reusable message templates; India DLT regulatory tags | 9 |
| 7 | Numbers/SenderID/Keywords/Compliance | 29 files / ~8,423 | `NumberController`, `SenderIDController`, `KeywordController`, `PhoneNumberController`, `BlockSenderIdController`, `SpamWordController` | Buy/manage numbers, sender IDs, opt-in keywords, blocklists | 10 |
| 8 | Sending Servers | 13 files / ~11,365 | `SendingServerController` | Configure SMS/voice/WhatsApp gateway connections | 11a-d |
| 9 | Billing, Payments & Accounts (legacy) | 21 files / ~4,025 | `PaymentController`, `SubscriptionController`, `InvoiceController` | Legacy per-gateway checkout, SMS-credit purchase/renewal/top-up, invoices | 12 |
| 10 | Usage & Billing (RFC-005 wallet system) | 8 files / ~1,161 | `Business\UsageBillingController` (+3 more), admin `UsageBillingController`, `PaymentProviderEventController`, `AdditionalBusinessSlotAgreementController` (×2) | New pay-as-you-go wallet: spend caps, top-up, auto-recharge, admin credit/suspend | *(post-dates old map)* |
| 11 | Sub-Accounts & Workspaces | 7 files / ~2,201 | `SubAccountController`, `Workspace\WorkspaceController`, admin `WorkspaceController` | Legacy delegated-access sub-accounts + new multi-Business Workspace container | 13 |
| 12 | Workspace Plan Catalog & Business admin | 4 files / ~376 | `WorkspacePlanCatalogController`, `WorkspaceEntitlementController`, admin `BusinessController` | RFC-004 entitlement-catalog inspection, cross-tenant Business admin | 16 (partial) / 17 (partial) |
| 13 | Business Onboarding | 9 files / ~347 | `BusinessOnboardingController` | RFC-001 guided setup wizard + AI profile-completeness analysis | 14 |
| 14 | Developer / API Docs | 22 files / ~6,486 | `DeveloperController` | API keys/webhooks + static per-channel REST/HTTP API reference | 15 |
| 15 | Reports & Analytics (legacy) | 12 files / ~7,219 | `ReportsController` (×2) | Message/campaign delivery logs, DLR tracking, exports | 4 (skipped) |
| 16 | Dashboards | 5 files | `DashboardController`, `AdminBaseController`, `SettingsController` | KPI landing screens; admin AI hot-leads/AI-analytics views | 3 (done) |
| 17 | Admin Customer/Tenant Management (legacy) | 19 files / ~3,872 | `Admin\CustomerController` | Legacy tenant CRUD — impersonation, DLT IDs, pricing/coverage assignment | 16 |
| 18 | Plans, Pricing & Catalog (legacy) | 17 files / ~4,763 | `PlanController`, `CurrencyController`, `TaxController` | Legacy SMS-credit plans, currencies, tax rules | 17 |
| 19 | Invoices & Subscriptions (admin, legacy) | 6 files / ~1,697 | `InvoiceController`, `SubscriptionController` | Admin invoice approval/print, subscription lifecycle | 18 |
| 20 | Admin Users, Roles & Announcements | 8 files / ~2,461 | `AdministratorController`, `RoleController`, `AnnouncementsController` | Backend admin accounts/roles, customer broadcast announcements | 19 |
| 21 | Plugins & Marketplace | 2 files / 424 | `PluginsController` | Install/manage CodeGlen marketplace add-ons | 20 (partial) |
| 22 | Legacy Theme Customizer | 1 file / 318 | `ThemeCustomizerController` | Layout/skin/navbar/footer/width/breadcrumb structural settings | 20 (partial) |
| 23 | Theme Presets (new design system) | 2 files / 295 | `PlatformThemePresetController`, `PlatformThemeFontController` | New color/font token preset system (M2's own deliverable) | *(Slice 1's own deliverable)* |
| 24 | System Settings & Vendor Admin | 26 files / ~5,753 | `SettingsController`, `LanguageController`, `CountriesController`, `PaymentMethodController`, `EmailTemplateController`, `UpdateController`, `InstallerController` | Real platform config (general/email templates/countries/language/payment toggles) **plus** license verification, app updater, first-run installer | 21 |
| 25 | Auth / Profile | 27 files (incl. `auth/payment/**` 8) | Laravel `Auth\*`, `ProfileController` | Login/register/2FA/reset, user profile, legal pages, gateway checkout pages | 2 (done) |

Additionally found, **entirely vestigial**: `config/permissions.php` declares permission categories for `Blogs`/`Blog Categories`/`Blog Tags`/`Blog Settings`, `FAQs`/`FAQ Categories`, `Testimonials`, `Widget Builder`, `Menu Manage`, `Brands`, `Support Tickets`/`Support Agents`/`Support Analytics`/`Support Articles`/`Support Categories`/`Support Settings`/`Support Ticket Tags` — mechanically confirmed **zero** corresponding views, routes, or controllers anywhere in the current repository. These are dead configuration left over from the original commercial-template product (a bundled marketing CMS and helpdesk), not active product surfaces. Not a retention decision — a housekeeping note for a future config cleanup, out of Design System scope entirely.

---

## 6. Special audits

### 6.1 Campaigns / Quick Send / Channels

**Architecture finding, load-bearing for the whole recommendation:** the 6 near-duplicate per-channel quick-send screens and 7 campaign-builder screens (26 files, ~14,555 lines, ~15,500 with imports) all funnel into **two shared repository methods** — `EloquentCampaignRepository::quickSend()` and `::campaignBuilder()`. Diffing the actual blade files confirms the M2 contract's "five near-duplicate copies" claim exactly: MMS/Viber/WhatsApp builder and quick-send blades differ by only 20–31 lines out of 700–900; Voice and OTP diverge more (300–430 lines) due to genuine TTS/IVR fields. `SendingServer` already models channels as boolean capability flags (`mms`, `voice`, `whatsapp`, `viber`, `otp`) on one generic entity — the **backend data model is already channel-agnostic; only the UI is not.**

**Critical proof the backend is the right thing to keep:** `ChatBoxController` (the just-redesigned Conversations surface) already calls this exact same `quickSend()` core with `conversationContext=true`, including full RFC-005 wallet-metering integration. The new architecture's own most-recently-built surface is *already* reusing this legacy send core — strong, direct evidence it is the correct long-term backend, not something to replace.

**Recommendation:** Capability (bulk/outreach messaging) **survives**. Backend (`quickSend()`/`campaignBuilder()` orchestration, `SendCampaignSMS.php` per-provider dispatch) is **high-reuse, keep**. Current UI — 6 duplicated channel silos, 26 files — is **not worth incremental redesign**; it should be **rebuilt from scratch** as one consolidated Outreach/Compose experience (mirroring the pattern already established for ChatBox). Templates (module 6) should fold into this same future compose experience rather than remain a standalone module.

**HUMAN DECISION — LOCKED (resolved 2026-09-04, §12.1):** Build one consolidated Outreach/Compose experience, not six channel interfaces. Initial UI priority is **SMS and MMS only**. The architecture must remain channel-extensible. **WhatsApp is deliberately deferred** to a future, separately-authorized product scope. **No new user-facing Outreach UI is planned for Viber, OTP, or legacy Voice at this stage.** This is a decision about the current retained/new UI roadmap, not an irreversible claim those channels can never exist — and it does not authorize deleting the underlying Viber/OTP/Voice backend integrations; that is separate, later, explicitly-authorized cleanup work.

### 6.2 Channels (SMS/MMS/Voice/WhatsApp/Viber/OTP)

Confirmed: channel capability already lives as attributes of one generic `SendingServer`/coverage concept, not six separate silos, at both the model and the pricing/coverage-row level. The 6-screen UI duplication is a presentation-layer artifact, not a backend constraint. Consolidation is a UI decision, not a backend migration.

### 6.3 Plugins / Marketplace

`admin/Plugins/**` (2 files, 424 lines) is **real, working vendor-distribution machinery**, not a stub: installing a "plugin" verifies a purchase code against a hardcoded `https://ultimatesms.codeglen.com/verify/` license endpoint, unzips an uploaded package, mutates the root `composer.json`, shells out to `exec('composer require ...')`, dynamically registers PSR-4 autoloading and Laravel service providers from the uploaded package's own `composer.json`, and runs `vendor:publish`/`migrate --force`. Its entire purpose is installing CodeGlen's own separately-sold add-ons (a "usupport" helpdesk plugin, a "ulanding" landing-page builder) — direct code inspection found **zero other retained feature in this codebase depends on the plugin system**. This is a genuine security-relevant surface (arbitrary `exec()`, dynamic autoloading of uploaded code) as well as a pure vendor-distribution feature.

**Recommendation: DELETE candidate**, high confidence, evidence-backed. No design-system work should ever be spent on it.

**HUMAN DECISION — LOCKED (resolved 2026-09-04, §12.5):** Confirmed DELETE candidate. The CodeGlen marketplace/plugin-installation product is not part of AI Business OS; no design work. Future removal must explicitly inspect dynamic package/autoload/`exec()`-related dependencies before deleting code, but the user-facing marketplace itself is not a retained product feature.

### 6.4 Legacy Theme Customizer

**Important, non-obvious finding: the legacy Theme Customizer and the new M2 theme-preset system do NOT overlap.** The legacy screen controls seven structural/layout settings (menu orientation, skin, navbar type, footer type, layout width, sidebar-collapsed default, breadcrumbs on/off) persisted directly into `.env` — confirmed these `THEME_*` env keys are referenced **only** by `ThemeCustomizerController` and nowhere else. The new M2 preset system controls color tokens and fonts only — confirmed zero references to any of those seven structural settings anywhere in the preset code. **Deleting the legacy screen removes the only UI able to change those seven settings; it is not "superseded," it is functionally orthogonal.**

~~Recommendation: UNDECIDED, requires an explicit human product decision (decision queue §12.4).~~ **Superseded by resolution below.**

**HUMAN DECISION — LOCKED (resolved 2026-09-04, §12.4):** AI Business OS will ship **one coherent, opinionated structural application layout**. Platform theming continues through the new M2 theme-preset architecture (color/fonts/branding) only. Owner-configurable controls for menu orientation, legacy skin, navbar type, footer type, layout width, default-collapsed sidebar, and breadcrumb toggle are **not retained**. **Classification: DELETE, no redesign.** The M2 Theme Preset system is retained unchanged and is unaffected by this decision.

### 6.5 System / vendor admin features

Beyond Plugins, direct inspection found further vendor-distribution machinery, all coupled to the same `ultimatesms.codeglen.com` license server: a **license verification** tab (`SettingsController::license()`, GETs the CodeGlen Envato-verification endpoint), an **application updater** (`SettingsController::updateApplication()`/`checkAvailableUpdate()`, re-verifies the purchase code before updating), a **second, separate update wizard** (`Installer/update/**` + `UpdateController`, same verify endpoint, hardcodes `APP_VERSION`), and a **first-run installer** (`Installer/welcome.blade.php` + `InstallerController`, a standard self-hosted-PHP-product install wizard). All four are disabled in demo mode and none serve any purpose for a single vendor-operated SaaS. A **5,138-line demo-data command** (`UpdateDemo.php`) seeds CodeGlen-branded fake data and is unrelated to real product operation.

By contrast, the rest of `admin/settings/**` (general/notification/email-templates/countries/language/payment-method-toggle screens) reads as ordinary, genuinely-needed platform-owner configuration.

**Recommendation:** License/Updater/Installer/demo-tooling — **DELETE candidates**, high confidence. Remaining genuine settings — **keep**, but the current 26-file, 5,753-line sprawl (`PaymentMethods/show.blade.php` alone is 1,937 lines) is itself a strong **rebuild-from-scratch-as-a-simpler-settings-experience** candidate rather than incremental redesign.

### 6.6 Reports & Analytics

Legacy Reports (12 files, ~7,219 lines across customer+admin) is deeply SMS-delivery-log-shaped (DLR tracking, sent/received message logs, per-message CSV export) — an SMS-platform-operator's analytics, not a local-business-owner's analytics. The **new** RFC-005 Usage & Billing dashboard (module 10) is a **financial/wallet** ledger view (balance, spend caps, funding history) — confirmed it contains no charts, campaign breakdowns, or delivery-status data, so it is **not a replacement** for Reports; it serves an entirely different purpose. The target product direction's own "local-business marketing analytics" (under Growth/Marketing) names a third, not-yet-built concept: business-outcome analytics (leads generated, conversion, ROI), which is what a photobooth-business owner would actually want, and which none of the current data model or UI is shaped for.

**Recommendation:** legacy Reports UI is a **rebuild-from-scratch candidate**, not an incremental-redesign candidate — Slice 4 should **remain cancelled**, not resumed, and be replaced later by a purpose-built business-outcome analytics module once CRM/Opportunities/Outreach data is stable enough to report on.

**HUMAN DECISION — LOCKED (resolved 2026-09-04, §12.7):** Old Design System M2 Slice 4 is **permanently cancelled in its current form**. Do not redesign the legacy SMS-delivery Reports module. Future analytics is a new AI Business OS business/marketing analytics product built around outcomes such as leads, opportunities, bookings/conversions, campaign/outreach effectiveness, marketing ROI, and business performance. Exact analytics scope is future work, not invented in this audit. **Classification: REBUILD FROM SCRATCH.**

### 6.7 Sending Server UX

Confirmed the M2 contract's own line counts exactly: `admin/SendingServer/create.blade.php` is 4,306 lines with **56** `@case` provider branches in one giant conditional form (Twilio, Vonage, Infobip, Plivo, SMPP, and ~50 more gateway types); `customer/SendingServer/create.blade.php` is 2,316 lines with 39 branches. The underlying `SendingServer` model (capability flags, provider credentials) is genuinely load-bearing — referenced by ChatBox, contact groups, users, campaigns, and coverage/billing — real backend to keep. But no local-business owner should ever see a 56-option raw-gateway picker.

**Recommendation:** backend — **keep, high reuse**; current UI — **delete, do not incrementally redesign**; future UI — **rebuild from scratch** as a small, AI-Business-OS-curated provider/channel connection experience (a handful of blessed providers, not 50+ raw options — decision queue §12.8). `DELETION_DEPENDENCY_RISK: HIGH` for the backend model itself (many cross-module foreign keys) — this is exactly why only the *UI* is a deletion candidate, never the `SendingServer` table/model.

**HUMAN DECISION — LOCKED (resolved 2026-09-04, §12.8):** Do not expose the inherited 39/56-provider raw gateway configuration UI in the finished product. Preserve the reusable `SendingServer`/provider backend infrastructure where needed. Future UI is a curated, simplified provider/channel connection experience; provider breadth is determined by actual AI Business OS product needs, not by which integrations happen to exist in Ultimate SMS. The legacy mega-forms receive no incremental redesign.

### 6.8 Admin/Platform survival

RFC-001/003/004 code comments directly confirm which admin surfaces are *already* the intended new architecture: `admin/businesses/**` docblock states "Admin-only, intentionally cross-tenant Business management (RFC-001 §16, §19 — Milestone 6)"; `admin/workspace-plan-catalog/**` docblock states it "Never reads any entitlement repository directly — every value comes from `EntitlementManager::listPlanCatalogSummaries()`" (RFC-004 Milestone 3); `admin/workspaces/**` is directly named after the RFC-003 model. These three are genuinely-surviving, RFC-aligned admin surfaces, distinct from the legacy `admin/customer/**` tenant CRUD (DLT IDs, per-country pricing/coverage assignment, impersonation) that predates the Workspace model entirely.

Admin Users/Roles/Announcements (module 20) has no RFC dependency but is genuine, low-risk, always-needed platform-owner infrastructure (backend-staff accounts, permissions, customer broadcast messaging) — survives regardless of product repositioning, just not urgent.

### 6.9 Sub-Accounts vs. Workspace/Business

RFC-003's own text is unambiguous: `users.parent_id` (`customer/SubAccounts/**`, `SubAccountController`) is explicitly named **"legacy sub-account... unrelated to Workspaces"** in the RFC's own terminology table, with an explicit non-goal ("RFC-003 must not wire it into Workspace logic"). `Workspace`/`WorkspaceMembership`/`WorkspaceMembershipBusiness` (module 11's other half) are the real, current, RFC-003-aligned multi-Business container with role (`admin`/`staff`) and per-membership `business_access_scope` (`all`/`selected`).

**Recommendation:** Sub-Accounts UI — **delete candidate**, direction is clear, but **timing is a human product decision** (does every existing Sub-Account user need a Workspace-membership migration path before the old UI can go away? — decision queue §12.3). Workspace/Business UI — **keep + redesign**, genuinely aligned, not yet touched by the design system (candidate for the surviving roadmap, §9).

**HUMAN DECISION — LOCKED (resolved 2026-09-04, §12.3):** The final product model is Workspace/Business membership. Legacy Sub-Accounts are not a second permanent tenancy/access model. Direction: eventually migrate required existing Sub-Account relationships into Workspace membership, then retire the legacy Sub-Account UI/path — migration requires its own future contract before deletion. **Classification: DELETE LATER, after explicit migration.** No Design System work on legacy Sub-Account pages in the meantime.

### 6.10 Billing/Payments consolidation

Legacy `customer/Accounts/**` (13 files, SMS-credit purchase/renewal/top-up/invoices), `customer/Payments/**` + `auth/payment/**` (16 gateway-specific files total), and the legacy `Plan`/`Subscription` admin stack (modules 18–19) are all one family — RFC-004's own text calls this out directly: **"a distinct RFC-004 domain fully separate from legacy SMS Plan/Subscription."** `Plan.options` is an SMS-domain JSON blob (`sms_max`, `whatsapp_max`, `sending_quota`); `Subscription` is wired into a completely different concern (`RateTracker`/SMS credit quota), with every foreign key using `cascadeOnDelete()` — the opposite of RFC-003/004/005's `restrictOnDelete()` tenancy-safety posture.

The **new** RFC-005 wallet system (module 10) already exists with a real UI (and was itself one of Milestone 1's two adopted design-system reference pages — already fully componentized, no further design work needed) but is **not yet live-charging-capable** (`READY_FOR_TEST_MODE_IMPLEMENTATION — BLOCKED_FOR_LIVE_CHARGING` per the RFC-005 deployment guide). The legacy billing flow is very likely what is still live in production today.

**Recommendation:** direction is clear (new wallet system supersedes legacy billing) but **the exact cutover timing is a human decision, not something this audit can resolve** (decision queue §12.2) — do not redesign either system in the meantime; legacy billing is a **deferred DELETE candidate**, not an active redesign candidate. One important carve-out: `customer/business/edit.blade.php`, bundled into the old M2 Slice 12 "Billing" scope, is actually the RFC-001 **Business profile** page, unrelated to billing — it should be reclassified onto the Business/Workspace surviving-roadmap track (§9), not cancelled with the rest of Slice 12.

**HUMAN DECISION — LOCKED (resolved 2026-09-04, §12.2):** Do not sunset the legacy billing stack until RFC-005 is genuinely ready for live charging. Until then: legacy billing remains operational, receives no Design System modernization, and no new feature work should be built on it unless required for production continuity. Once RFC-005 live charging is human-authorized and production-ready, plan an explicit migration/cutover, then retire/delete the legacy billing stack separately. **Classification remains deferred DELETE, not KEEP + REDESIGN.** This resolution covers the legacy Billing/Payments/Accounts module, the legacy Plans module (module 18), and Invoices & Subscriptions (module 19) — all part of the same billing dependency chain (§6.10). Currency/Tax, the generic-infra portion of the Plans/Pricing/Catalog module, is a separate concern (§7) and is not gated by this billing-cutover decision.

### 6.11 Developer / API Docs

Current module (22 files, ~6,486 lines) mixes genuine API-key/webhook management with a static per-channel REST/HTTP API reference document, aimed at third-party developers integrating directly against the legacy per-channel SMS API — not at the local-business-owner customer this product now targets.

**HUMAN DECISION — LOCKED (resolved 2026-09-04, §12.6):** Do not preserve or redesign the inherited raw-SMS-platform API documentation product. Current legacy Developer/API Docs become a **DELETE / deprioritize** candidate. If AI Business OS later exposes a public API, it must be defined from the actual AI Business OS domain model, in a separate future RFC/product scope, with new documentation written fresh — not constrained around the inherited per-channel SMS API. **Classification: DELETE / DEPRIORITIZED LEGACY SURFACE.**

---

## 7. Master decision table

Columns: Module · Current paths · Current purpose · Target capability · Survives? · Backend reuse · Current-UI classification · Primary classification · Removal/rebuild complexity · M2 slice(s) · Design action · Confidence · Human decision required? · Key evidence.

| Module | Current paths | Current purpose | Target capability | Survives? | Backend reuse | Current UI | Primary classification | Complexity | M2 slice(s) | Design action | Confidence | Human decision? | Key evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| CRM/Contacts | `customer/{Contacts,contactGroups,Blacklists}/**` | Contact/segment mgmt | CRM/Contacts | YES | HIGH | Redesigned | KEEP + REDESIGN | LOW (done) | 5 | DESIGN NOW *(done)* | HIGH | No | §5 mod 1; Slice 5 complete |
| Opportunities | `customer/opportunities/**`, `admin/opportunities/**` | AI action queue | Opportunity Engine | YES | HIGH | Redesigned | KEEP + REDESIGN | LOW (done) | 5 | DESIGN NOW *(done)* | HIGH | No | RFC-002 complete/tagged |
| ChatBox/Conversations | `customer/ChatBox/**` | 2-way SMS inbox | Conversations/messaging | YES | HIGH | Redesigned | KEEP + REDESIGN | LOW (done) | 6 | DESIGN NOW *(done)* | HIGH | No | Just merged, PR #184 |
| Campaigns (bulk/quick-send/builders) | `customer/Campaigns/**` | 6-channel bulk send | Outreach engine (SMS/MMS launch scope; WhatsApp deferred) | YES (capability) | HIGH (`quickSend()`) | DELETE | REBUILD FROM SCRATCH | HIGH | 7a/7b/7c | DO NOT DESIGN LEGACY UI | HIGH | No — resolved §12.1 | §6.1; ChatBox reuses `quickSend()` |
| Automations | `customer/Automations/**` | Single trigger (birthday) | AI-assisted automation/sequences | YES (capability) | LOW (narrow) | DELETE | REBUILD FROM SCRATCH | HIGH | 8 | DO NOT DESIGN LEGACY UI | MEDIUM | Yes — scope of engine | Task target list far exceeds current 1-trigger model |
| Templates | `customer/Templates/**`, `admin/Templates,TemplateTags/**` | Saved messages + DLT tags | Compose-flow templates | PARTIAL | PARTIAL | DELETE (fold into Outreach) | KEEP BACKEND, REBUILD UI | MEDIUM | 9 | DO NOT DESIGN LEGACY UI | MEDIUM | No | DLT = India-specific, PROVIDER_SPECIFIC |
| Numbers/SenderID/Keywords | `customer/{Numbers,SenderID,keywords}/**`, `admin/{PhoneNumbers,SenderID,BlockSenderID,keywords,SpamWord}/**` | Number/sender-ID/keyword purchase & compliance | Simplified number/channel connect | PARTIAL | PARTIAL | DELETE | REBUILD FROM SCRATCH | HIGH | 10 | DO NOT DESIGN LEGACY UI | MEDIUM | No — resolved §12.8 (same simplified-connect decision as Sending Servers) | §6.2; enterprise-shaped purchase/compliance UI |
| Sending Servers | `{customer,admin}/SendingServer/**` | Gateway config (56 providers) | Simplified provider connect | YES (capability) | HIGH (data model) | DELETE | KEEP BACKEND, REBUILD UI | HIGH | 11a-d | DO NOT DESIGN LEGACY UI | HIGH | No — resolved §12.8 | §6.7; `DELETION_DEPENDENCY_RISK: HIGH` on model, not UI |
| Billing/Payments/Accounts (legacy) | `customer/{Accounts,Payments}/**`, `auth/payment/**` | SMS-credit purchase/top-up | Superseded by wallet billing | NO (superseded) | LOW | Frozen (do not touch) | DELETE | HIGH | 12 (partial) | DELETE LATER (deferred until RFC-005 live charging) | HIGH | No — resolved §12.2 | §6.10; RFC-004 §5: "fully separate... legacy" |
| Business profile edit | `customer/business/edit.blade.php` | RFC-001 Business identity form | Business Core | YES | HIGH | Not yet redesigned | KEEP + REDESIGN | LOW | 12 (carve-out) | DESIGN LATER | HIGH | No | RFC-001 §23; mis-scoped into old "Billing" slice |
| Usage & Billing (wallet) | `customer/business/usage-billing/**`, `admin/usage-billing/**`, `admin/additional-business-slot-agreements/**` | New pay-as-you-go billing | Wallets/usage ledger | YES | HIGH | Already redesigned (M1 reference page) | KEEP + REDESIGN | LOW (done) | *(none — post-dates map)* | DESIGN NOW *(done)* | HIGH | No | Was M1's own adopted reference page |
| Sub-Accounts (legacy) | `customer/SubAccounts/**` | `users.parent_id` delegated access | Superseded by Workspace membership | NO (superseded) | LOW | Frozen | DELETE | MEDIUM | 13 (partial) | DELETE LATER (after explicit migration contract) | HIGH | No — resolved §12.3 | §6.9; RFC-003 own text: "legacy... unrelated to Workspaces" |
| Workspace/Business (customer+admin) | `customer/workspace{,s}/**`, `admin/workspaces/**`, `admin/workspace-plan-catalog/**`, `admin/businesses/**` | Multi-Business tenancy container | Workspace model | YES | HIGH | Not yet redesigned | KEEP + REDESIGN | LOW-MEDIUM | 13 (partial), 16 (partial), 17 (partial) | DESIGN LATER | HIGH | No | §6.8; RFC-003/004 code comments confirm alignment |
| Business Onboarding | `customer/onboarding/**` | Guided setup wizard + AI analysis | New-tenant funnel | YES | HIGH | Not yet redesigned | KEEP + REDESIGN | LOW | 14 | DESIGN LATER | HIGH | No | RFC-001 core repositioning surface |
| Developer/API Docs | `customer/Developers/**` | API keys + static REST docs | Wrong audience for target customer | NO | LOW | DELETE | DELETE / DEPRIORITIZED | LOW | 15 | DELETE LATER | HIGH | No — resolved §12.6 | §6.11; aimed at 3rd-party integrators, not business owners |
| Reports & Analytics (legacy) | `customer/Reports/**`, `admin/Reports/**` | SMS delivery/campaign logs | "Local-business marketing analytics" | PARTIAL | PARTIAL | DELETE | REBUILD FROM SCRATCH | HIGH | 4 (stays skipped) | DO NOT DESIGN LEGACY UI | HIGH | No — resolved §12.7 | §6.6; wrong shape for outcome-analytics |
| Dashboards | `customer/dashboard.blade.php`, `admin/{dashboard,hot_leads,ai_analytics,ai-settings}.blade.php` | KPI landing + AI leads | Dashboards | YES | HIGH | Redesigned | KEEP + REDESIGN | LOW (done) | 3 | DESIGN NOW *(done)* | HIGH | No | Slice 3 complete |
| Admin Tenant Mgmt (legacy) | `admin/customer/**` | Legacy tenant CRUD, impersonation, DLT/pricing | Superseded by Business/Workspace admin | PARTIAL | PARTIAL | DELETE | KEEP BACKEND, REBUILD UI | MEDIUM | 16 (partial) | DO NOT DESIGN LEGACY UI | MEDIUM | Yes — impersonation fate | §6.8; SECURITY_SENSITIVE (impersonation) |
| Plans/Pricing/Catalog (legacy) | `admin/plans/**`, `admin/currency/**`, `admin/taxes/**` | SMS-credit plans, currency, tax | Superseded by RFC-004 catalog | NO (Plans), YES (Currency/Tax generic infra) | LOW (Plans), PARTIAL (Currency/Tax) | Frozen | DELETE (Plans); KEEP BACKEND (Currency/Tax) | HIGH | 17 | DELETE LATER (Plans, deferred to billing cutover); DO NOT DESIGN LEGACY UI (Currency/Tax, pending future Settings rebuild) | HIGH | No — resolved §12.2 | §6.10; same billing dependency chain |
| Invoices & Subscriptions (admin, legacy) | `admin/Invoices/**`, `admin/subscriptions/**` | Legacy invoice/subscription lifecycle | Superseded by wallet billing | NO | LOW | Frozen | DELETE | MEDIUM | 18 | DELETE LATER (deferred until RFC-005 live charging) | HIGH | No — resolved §12.2 | §6.10 |
| Admin Users/Roles/Announcements | `admin/{Administrator,AdminRoles,Announcements}/**` | Backend staff accounts, broadcast | Platform admin, notifications | YES | HIGH | Not yet redesigned | KEEP + REDESIGN | LOW | 19 | DESIGN LATER | HIGH | No | §6.8; no RFC dependency but low-risk core infra |
| Plugins/Marketplace | `admin/Plugins/**` | Install CodeGlen add-ons | None identified | NO | N/A | Frozen | DELETE | LOW-MEDIUM | 20 (partial) | DELETE LATER | HIGH | No — resolved §12.5 | §6.3; zero retained-feature dependency found |
| Legacy Theme Customizer | `admin/ThemeCustomizer/**` | Layout/skin/navbar/width settings | Not retained — one fixed opinionated layout | NO | N/A | DELETE | DELETE | LOW | 20 (partial) | DELETE LATER | HIGH | No — resolved §12.4 | §6.4; genuinely non-overlapping with M2 presets |
| Theme Presets (new) | `admin/theme-settings/**` | Color/font token presets | Platform appearance | YES | HIGH | Already built (M2's own deliverable) | KEEP + REDESIGN | LOW (done) | *(Slice 1's own scope)* | DESIGN NOW *(done)* | HIGH | No | Not a legacy surface at all |
| System Settings (genuine) | `admin/settings/AllSettings/**` (minus license), Countries/Language/EmailTemplates/PaymentMethods | Real platform config | Platform settings | YES | HIGH | Not yet redesigned, sprawling | KEEP BACKEND, REBUILD UI | MEDIUM | 21 (partial) | DO NOT DESIGN LEGACY UI (rebuild instead) | MEDIUM | No | §6.5; 26 files/5,753 lines too sprawling to incrementally redesign |
| License/Updater/Installer/Demo tooling | `admin/settings/UpdateApplication`, `_license` tab, `Installer/**`, `UpdateDemo.php` | Vendor license/update/install/demo machinery | None | NO | N/A | Frozen | DELETE | MEDIUM-HIGH | 2 (Installer, partial), 21 (partial) | DELETE LATER | HIGH | No | §6.5; all coupled to CodeGlen license server |
| Auth/Profile | `auth/**` excl. payment | Login/register/2FA/profile | Auth/Profile | YES | HIGH | Redesigned | KEEP + REDESIGN | LOW (done) | 2 | DESIGN NOW *(done)* | HIGH | No | Slice 2 complete |

---

## 8. Duplication / consolidation opportunities

| Legacy surfaces | → | Proposed future concept |
|---|---|---|
| 6× Quick Send + 7× Campaign Builder + 7× Import (Campaigns) + Templates | → | One consolidated Outreach/Compose experience, channel picker instead of six silos, backend already unified |
| Legacy Accounts/Payments/Plan/Subscription/Invoices (5 modules) | → | RFC-005 Usage & Billing wallet system (already built, not yet live-charging) |
| Legacy Sub-Accounts (`users.parent_id`) | → | Workspace membership (`role` + `business_access_scope`) |
| Legacy Theme Customizer | → | *(resolved §12.4)* removed entirely; AI Business OS ships one fixed, opinionated layout; M2 Theme Presets remains the sole theming mechanism (color/fonts/branding only) |
| Numbers/SenderID/Keywords/Compliance (29 files) + Sending Servers (13 files, 56-provider mega-form) | → | One simplified "connect your number/provider" experience |
| Legacy admin/customer tenant CRUD | → | admin/businesses + admin/workspaces (already RFC-001/003-aligned) |
| Legacy Reports (SMS delivery logs) | → | Future business-outcome "marketing analytics" module (not yet built) |

---

## 9. Surviving design roadmap (binding recommendation — not yet applied)

All eight decision-queue items are now resolved (§12), so this is the **binding recommended roadmap**, pending only human merge of this audit — it is still **not applied**: no code has changed, and the old M2 rollout map (`DESIGN-SYSTEM-M2-CONTRACT.md`) is replaced by this roadmap only in a later, separate docs-only change. Optimized for: final product value, avoiding throwaway work, dependency order, redesigning retained legacy screens only where that's genuinely cheaper than rebuilding, and deferring every deletion to explicit future cleanup work.

### Category A — retained existing UI to finish

| Order | Module | Redesign existing / build new | Dependencies | Backend work first? | Rough scope |
|---|---|---|---|---|---|
| 1 | Business Onboarding | Redesign existing | None | No | Small (9 files, 347 lines) |
| 2 | Workspace/Business (customer + admin, incl. `customer/business/edit.blade.php` Business-profile carve-out) | Redesign existing | §12.3 resolved — Sub-Account migration requires its own future contract before the legacy Sub-Accounts UI can be deleted, but does not block this redesign | No | Small-medium (~11-12 files) |
| 3 | Admin Users/Roles/Announcements | Redesign existing | None | No | Small (8 files, 2,461 lines) |

### Category B — new product UI / rebuilds

| Order | Module | Redesign existing / build new | Dependencies | Backend work first? | Rough scope |
|---|---|---|---|---|---|
| 4 | Outreach/Compose (replaces Campaigns + Templates) | Build new | §12.1 resolved — one consolidated experience, SMS/MMS initial focus, WhatsApp deferred, extensible channel architecture, reuses sending backend | No — `quickSend()`/`campaignBuilder()` backend already reusable | Medium-large — new UI, existing backend |
| 5 | Simplified Provider/Channel connect (replaces Sending Servers + Numbers/SenderID) | Build new | §12.8 resolved — curated provider UX, retains underlying provider infrastructure | No — `SendingServer` model already usable | Medium — new UI, existing backend |
| 6 | Simplified Platform Settings (replaces System Settings; excludes License/Updater/Marketplace/legacy Theme Customizer) | Build new (consolidate) | None | No | Medium |
| 7 | Automations / AI-assisted sequences | Build new | Should land after #4 (shares send primitives) | **Yes** — current single-trigger model far short of target | Large — new backend + new UI |
| 8 | Business/marketing analytics (replaces legacy Reports) | Build new | Should land after CRM/Opportunities/Outreach are stable data sources | **Yes** — needs a business-outcome data shape, not SMS-delivery logs | Large — new backend + new UI |

No additional product modules are added beyond these 8.

---

## 10. Legacy design work to cancel — binding recommended roadmap replacement

All eight decision-queue items are resolved (§12), so the list below is the **binding recommended replacement** for the equivalent rows of the old M2 rollout map — not yet applied to `DESIGN-SYSTEM-M2-CONTRACT.md` itself (that edit is separate, future, docs-only work, after this audit is human-merged). Every M2 slice or part-slice below should **not** receive further page-by-page modernization — its capability, if it survives at all, gets a from-scratch rebuild instead (§9 Category B), or the surface is a deletion candidate outright:

- **Slice 7a** — Campaigns Quick Send (6 files) — resolved §12.1: rebuilt as Outreach/Compose, SMS/MMS initial scope
- **Slice 7b** — Campaigns Builders (7 files) — resolved §12.1
- **Slice 7c** — Campaigns Overview/List/Modals (13 files) — resolved §12.1
- **Slice 8** — Automations (6 files)
- **Slice 9** — Templates (6 files) — folds into Outreach/Compose
- **Slice 10** — Numbers/SenderID/Keywords/Compliance (29 files) — resolved §12.8: folds into simplified provider/channel connect
- **Slice 11a-d** — Sending Servers (13 files) — resolved §12.8: curated, simplified connect experience
- **Slice 12** — Billing/Payments/Accounts, *minus* `customer/business/edit.blade.php` carve-out (~28 of 30 files) — resolved §12.2: deferred DELETE, cutover timing gated on RFC-005 live charging
- **Slice 15** — Developer/API Docs (22 files) — resolved §12.6: DELETE/deprioritized, not redesigned
- **Slice 16** — *partial*: legacy `admin/customer/**` tenant CRUD (~19 of 22 files; `admin/businesses/**` already RFC-aligned, moves to the surviving roadmap instead)
- **Slice 17** — *partial*: legacy Plans (resolved §12.2, deferred DELETE with Slice 12/18); Currency/Taxes generic infra retained backend, redirected to the future simplified-Settings rebuild (§9 #6) rather than incremental redesign
- **Slice 18** — Invoices & Subscriptions (6 files) — resolved §12.2: deferred DELETE
- **Slice 20** — Plugins & Theme Customizer (3 files) — both confidently cancelled: Plugins resolved §12.5 DELETE, Theme Customizer resolved §12.4 DELETE
- **Slice 21** — *partial*: System Settings, License/Updater tab confidently cancelled (~2 files), remainder (24 files) redirected to a rebuild (§9 #6) rather than incremental redesign

**≈199 of the 375 legacy-map files (≈53%) have their planned page-by-page redesign cancelled or redirected to a rebuild** — this is the time saved by this audit, even though a meaningful share of that capability will still need new-build design work eventually (§9 Category B), just not as incremental modernization of the existing screens.

---

## 11. Completion percentage recalculation

**Methodology note, stated explicitly per instructions: exact file-weighted precision would be misleading here, since "redesign an existing file" and "build a new file from scratch" are not equivalent units of work. Both percentages below are given as ranges/approximations, not fabricated precision.**

### A. Legacy M2 progress (against the original full rollout map)

- Total mapped files (excluding the explicitly out-of-scope email-templates row): **375** (133 done + 12 deliberately skipped + 230 not yet started).
- Done: **133 / 375 ≈ 35%**.
- If the deliberately-skipped Slice 4 is excluded from the denominator entirely (never counted as "remaining" in the first place): 133 / 363 ≈ 37%.

### B. Surviving-product design progress (against only what this audit recommends should exist in AI Business OS)

- Of the modules classified **KEEP + REDESIGN** (existing screens genuinely worth incrementally modernizing): Foundation (67) + Auth/Profile (27) + Dashboards (5) + CRM/Opportunities (30) + ChatBox (4) + Usage & Billing (already-done reference page) + Theme Presets (already-done Slice-1 deliverable) = **133 done**, against a remaining surviving-redesign set of Onboarding (9) + Workspace/Business (~11) + Admin Users/Roles/Announcements (8) + Business profile edit (1) ≈ **29 remaining**. **133 / 162 ≈ 82%** of the narrow "redesign existing legacy UI" scope is complete.
- The **REBUILD FROM SCRATCH** scope (Outreach/Compose, Automations, simplified Provider connect, simplified Settings, business/marketing analytics) is **not meaningfully expressible as a percentage of existing files**, since it is new-build work, not incremental modernization — and it has **not begun**.

**Do not collapse this into one number.** The ~82% figure above is **not** overall AI Business OS product-design completion — it means only that approximately 82% of the *existing* UI surfaces recommended for incremental KEEP + REDESIGN work have already been redesigned. New-build product work remains substantial and currently includes at least: Outreach/Compose, simplified provider connection, simplified Settings, Automations, and business/marketing analytics (§9 Category B) — none of it started, and none of it counted in the old M2 rollout map at all, since that map only ever tracked "redesign this existing file," not "build this new thing." Report progress as **two separate indicators, never one combined figure**:

- **A. Retained legacy redesign:** ~80–85% complete (133/162 files, §11A above).
- **B. New-build product UI:** major modules not started; no honest file-weighted percentage exists yet, since these are new builds, not incremental redesigns of existing files.

---

## 12. Decision queue for the human — ALL RESOLVED

**`decision_queue_open_items: 0`.** All eight items below were genuinely ambiguous, high-impact questions this audit could not resolve from evidence alone. The human has reviewed the queue and locked a binding decision for each, reproduced verbatim below. The original "Exists today / Audit recommendation / If YES / If NO" framing is preserved for context; each item now also carries the resolution actually chosen.

### 12.1 — Campaigns/Outreach channel scope — RESOLVED
**Exists today:** 6 fully-built channels (SMS, MMS, Voice, WhatsApp, Viber, OTP), each with dedicated quick-send/builder/import UI.
**Audit recommendation:** consolidate to a single Outreach/Compose UI; likely fewer channels at launch (SMS/MMS/WhatsApp are plausible for a local business; Voice/Viber/OTP read as enterprise-SMS-platform features).
**If YES (keep all 6 channels):** the rebuilt Outreach UI must still support all 6 at launch — larger scope, though the backend already supports it at no extra cost.
**If NO (narrow the channel set):** smaller, faster rebuild; Voice/Viber/OTP-specific provider integrations become lower priority, possibly deferred indefinitely.
**RESOLVED (2026-09-04):** Build one consolidated Outreach/Compose experience, not six separate interfaces. Initial UI priority: **SMS and MMS only**. Architecture remains channel-extensible. WhatsApp is deliberately deferred to a future, separately-authorized product scope. No new user-facing UI for Viber, OTP, or legacy Voice at this stage — their backend integrations are not deleted, only not exposed in new UI yet; backend/provider cleanup is separate future work.

### 12.2 — Legacy billing cutover timing — RESOLVED
**Exists today:** legacy Accounts/Payments/Plans/Subscriptions is very likely what is live in production; the new RFC-005 wallet system exists but is blocked from live Stripe charging.
**Audit recommendation:** keep legacy billing live and untouched until the wallet system is production-ready; do not redesign either system in the meantime.
**If YES (begin sunsetting legacy billing now):** risks breaking live billing before the wallet system can actually charge customers.
**If NO (wait for full cutover):** legacy billing UI continues to exist un-redesigned, which this audit already recommends — no action needed until the human sets a cutover date.
**RESOLVED (2026-09-04):** Do not sunset the legacy billing stack until RFC-005 is genuinely ready for live charging. Until then, legacy billing remains operational, receives no Design System modernization, and no new feature work is built on it unless required for production continuity. Once RFC-005 live charging is human-authorized and production-ready, an explicit migration/cutover will be planned, then the legacy billing stack retired/deleted separately. Classification remains **deferred DELETE**, not KEEP + REDESIGN.

### 12.3 — Sub-Account → Workspace membership migration — RESOLVED
**Exists today:** legacy `users.parent_id` sub-accounts, structurally unrelated to the new Workspace membership model (RFC-003's own words).
**Audit recommendation:** eventually migrate existing Sub-Account relationships into Workspace membership rows, then delete the legacy Sub-Accounts UI.
**If YES:** requires a data-migration plan (which this audit does not design) before the legacy UI can be removed.
**If NO:** Sub-Accounts persists indefinitely as a second, Workspace-invisible access-delegation path.
**RESOLVED (2026-09-04):** The final product model is Workspace/Business membership; legacy Sub-Accounts are not a second permanent tenancy/access model. Eventually migrate required existing Sub-Account relationships into Workspace membership, then retire the legacy Sub-Account UI/path. Migration requires its own future contract before deletion. **Classification: DELETE LATER, after explicit migration.** No Design System work on legacy Sub-Account pages.

### 12.4 — Legacy Theme Customizer's structural layout settings — RESOLVED
**Exists today:** admin-configurable menu orientation/skin/navbar/footer/width/breadcrumbs, functionally independent of the new M2 color/font theme-preset system.
**Audit recommendation:** none — this is a genuine product-taste decision the evidence cannot settle.
**If YES (keep layout configurability):** Theme Customizer needs eventual reconciliation/redesign alongside M2 Theme Presets — two coexisting systems, by design.
**If NO (one fixed, opinionated layout):** Theme Customizer becomes a clean, high-confidence DELETE candidate — no further design work ever required.
**RESOLVED (2026-09-04):** NO — AI Business OS ships one coherent, opinionated structural application layout. Platform theming remains through the M2 theme-preset architecture (branding/colors/fonts) only. Owner-configurable controls for menu orientation, legacy skin, navbar type, footer type, layout width, default-collapsed sidebar, and breadcrumb toggle are not retained. **Legacy Theme Customizer becomes a high-confidence DELETE candidate, no redesign.** The M2 Theme Preset system is retained unchanged.

### 12.5 — Plugins/Marketplace deletion confirmation — RESOLVED
**Exists today:** real, working CodeGlen-marketplace install machinery (`exec()`, dynamic autoloading), used only to install two specific CodeGlen paid add-ons.
**Audit recommendation:** delete-eligible — zero other retained feature was found to depend on it.
**If YES (confirm delete-eligible):** schedule for removal; also removes a real `exec()`/dynamic-code-loading security surface.
**If NO (something still needs it):** name what — the evidence search found nothing.
**RESOLVED (2026-09-04):** Confirmed DELETE candidate. The CodeGlen marketplace/plugin-installation product is not part of AI Business OS. No design work. Future removal must explicitly inspect dynamic package/autoload/`exec()`-related dependencies before deleting code, but the user-facing marketplace is not a retained product feature.

### 12.6 — Developer/API Docs audience fit — RESOLVED
**Exists today:** 22 files of API-key management and static per-channel REST/HTTP API reference documentation, aimed at third-party developers integrating with the SMS platform directly.
**Audit recommendation:** deprioritize — this audience (developers building against a raw SMS API) does not match the target local-business-owner customer.
**If YES (AI Business OS wants a public API product):** it needs its own future-scoped RFC, not a page-by-page redesign of the existing docs.
**If NO:** delete/deprioritize entirely — saves 22 files' worth of redesign effort with no product-value loss identified.
**RESOLVED (2026-09-04):** Do not preserve or redesign the inherited raw-SMS-platform API documentation product. Current legacy Developer/API Docs become a **DELETE/deprioritize candidate**. If AI Business OS later exposes a public API, define it from the actual AI Business OS domain model, in a separate future RFC/product scope, with new documentation — not constrained around the inherited per-channel SMS API.

### 12.7 — Reports/Analytics direction — RESOLVED
**Exists today:** legacy Slice 4 (skipped), SMS-delivery-log-shaped reporting.
**Audit recommendation:** do not resume Slice 4 as originally scoped; replace with a future business-outcome analytics module once CRM/Opportunities/Outreach data is stable.
**If YES (replace):** Slice 4 is permanently cancelled in its current shape; a new analytics module gets designed fresh later, dependency-ordered after CRM/Outreach.
**If NO (resume Slice 4 as originally mapped):** the module stays SMS-delivery-log-shaped, incrementally redesigned as originally planned.
**RESOLVED (2026-09-04):** Old Design System M2 Slice 4 is permanently cancelled in its current form. Do not redesign the legacy SMS-delivery Reports module. Future analytics is a new AI Business OS business/marketing analytics product built around outcomes such as leads, opportunities, bookings/conversions, campaign/outreach effectiveness, marketing ROI, and business performance. Exact analytics scope is future work. **Classification: REBUILD FROM SCRATCH.**

### 12.8 — Sending Server / provider depth — RESOLVED
**Exists today:** 56-provider raw gateway configuration mega-form (customer + admin), enterprise-SMS-platform-shaped.
**Audit recommendation:** drastically simplify to a small, curated set of blessed providers for a "connect your number" experience.
**If YES (keep full 56-provider depth):** the large legacy surface persists essentially as-is, just visually modernized.
**If NO (simplify):** most of the 29+13 = 42 files across Numbers/SenderID/Sending-Servers become moot; the rebuild is small.
**RESOLVED (2026-09-04):** Do not expose the inherited 39/56-provider raw gateway configuration UI in the finished product. Preserve the reusable SendingServer/provider backend infrastructure where needed. Future UI is a curated, simplified provider/channel connection experience; provider breadth is determined by actual AI Business OS product needs, not by which integrations happen to exist in Ultimate SMS. The legacy mega-forms receive no incremental redesign.

---

## 13. Mechanical final check

- Aggregate branch diff from `main` remains exactly **one path**: `docs/automation/PRODUCT-SURFACE-RETENTION-AUDIT.md`. ✓
- No application code, view, controller, model, route, config, or test file changed. ✓
- No deletion performed anywhere in the repository. ✓
- `docs/automation/AI-AUTONOMY-STATE.json` untouched. ✓
- All eight decision-queue items resolved (§12.1–§12.8). ✓
- Zero UNDECIDED rows remain due to these eight questions — Legacy Theme Customizer and Developer/API Docs both resolved to DELETE (§7). ✓
- Plugins classified DELETE (§6.3, §7, §12.5). ✓
- Legacy Theme Customizer classified DELETE (§6.4, §7, §12.4). ✓
- Legacy Developer/API Docs classified DELETE/deprioritized (§6.11, §7, §12.6). ✓
- Legacy Reports classified REBUILD FROM SCRATCH (§6.6, §7, §12.7). ✓
- Campaigns UI classified REBUILD FROM SCRATCH (§6.1, §7, §12.1). ✓
- Sending Server UI classified KEEP BACKEND / REBUILD UI (§6.7, §7, §12.8). ✓
- Sub-Accounts classified DELETE LATER after migration (§6.9, §7, §12.3). ✓
- Legacy billing classified deferred DELETE after RFC-005 live cutover (§6.10, §7, §12.2). ✓
- Surviving roadmap has exactly 8 modules/groups (§9, Category A ×3 + Category B ×5), matching the human-provided ordering exactly. ✓
- Current M2 roadmap (§4, §7) completely mapped — every remaining slice (7a through 21) receives an explicit disposition (§10). ✓
- Surviving-product roadmap exists and is now binding pending merge (§9). ✓
- Cancelled-design-work list exists and is now binding pending merge (§10). ✓
- Recalculated progress exists, both A and B, reported as two separate indicators — no single fake combined percentage (§11). ✓
- `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` **not edited** in this branch — confirmed via `git diff --stat` against `origin/main`, one path only. ✓
- No implementation or deletion occurred. ✓

Run `git diff --check` — clean, exit 0 (verified below).

---

*End of Product Surface Retention Audit. Docs-only. No application change. All eight human decision-queue items resolved — audit ready for human merge. Design System M2 rollout remains paused pending human review. Slice 7a visual implementation has not started.*
