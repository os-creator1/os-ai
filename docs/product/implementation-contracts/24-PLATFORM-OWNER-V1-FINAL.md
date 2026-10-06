# Implementation Contract 24 — Platform Owner V1 Final

**Status:** completion pass. Builds on contract 22 (support cockpit, authority) and the
Platform Owner shell (`ad69a748`). Goal: every visible primary admin page is a coherent
Business OS surface — no Ultimate SMS-era primary UX, no second plan authority, no dead
pages, no raw implementation concepts.

Base: `ad69a748` on `agent/v1-completion-integration` (the branch tip had moved one merge
ahead — Agency Outreach `020afc8b` — when this lane started; this lane deliberately forks
the expected SHA so integration is a clean merge).

Out of scope (owned elsewhere, only a seam here): the Platform Automations engine and its
delivery of announcements; Niche Blueprint internals.

## 1. Final sidebar

Defined in `Helper::menuData()['admin']`, resolved per user by `AdminMenuBuilder`.

| Group | Entries |
|---|---|
| (top) | **Home** |
| Accounts & Operations | Workspaces · Businesses · **Users** · **Support** · Opportunities¹ |
| Commercial | **Plans** · Billing & Revenue · Usage & Provider Costs (Safety Limits, AI Usage, Provider Events) |
| Product & Configuration | Niche Blueprints · Website Templates · Proposal Templates · **Feature Management** · **Announcements** · Platform Automations² |
| Governance | Audit Logs · Administrators · Roles |
| (Settings) | Settings (platform settings, theme presets, language, email templates, terms, privacy, maintenance) |
| System / Advanced | Messaging Operations (provisioning incidents, port-out, number lifecycle) · Legacy Slot Agreements · Citation Catalogs (directories, niches) · Legacy SMS Gateway³ |

¹ only while `opportunity.enabled`. ² seam only — wired but hidden until
`PLATFORM_AUTOMATIONS_MENU=true` (the Platform Automations lane owns the route and page).
³ hidden unless `LEGACY_MESSAGING_MENU=true`.

Not given their own entries on purpose: **Subscriptions** and **Invoices** — platform
subscription state, revenue and payment health are on Billing & Revenue; per-customer
Business invoices are Business documents (lane B), not a Platform surface. Faking a page
for them would create a second source of truth. **Security** (blacklist / spam word / block
sender ID) is a legacy SMS-gateway concern and moved under Legacy SMS Gateway. **Provider
Costs** is Usage & Provider Costs.

## 2. Classification of legacy surfaces

| Surface | Class | Notes |
|---|---|---|
| Plan catalog read-only page (`workspace-plan-catalog`) | **deprecate** | route kept (tested, deep-linkable); unlinked everywhere; superseded by Plans |
| Legacy SMS `Plans`, Currencies, Tax, Invoices, Sending Servers, Sender ID, Numbers, Keywords, Templates, SMS History, Blacklist, Spam Word, Block Sender ID, Customers, Subscriptions, Messaging Dashboard | **hide** | one `Legacy SMS Gateway` group behind `LEGACY_MESSAGING_MENU`; routes registered, backends untouched (messaging/billing still depends on them) |
| Legacy Announcements screen (immediate email/SMS blast, DataTables) | **hide** | routes kept; the sidebar points at the new lifecycle UI. Legacy table `announcements` untouched |
| Additional Business Slot Agreements | **advanced** | contract 11 froze new sales; page now explains it is a grandfathered-holders ledger; under System / Advanced as *Legacy Slot Agreements* |
| Messaging provisioning incidents / port-out / number lifecycle | **advanced** | real operational screens, not a primary Platform Owner task |
| Citation Directories / Niches | **advanced** | catalogs maintained rarely |
| Administrators / Roles legacy DataTables views | **retired** | replaced in place (same routes, same controllers); JSON search/export/batch endpoints stay registered but unlinked |
| Settings group, Theme Presets, Language, Email Templates | **keep** | unchanged |

## 3. One plan authority

The customer SaaS plans are exactly **Core, Growth, Agency** — `workspace_plan_catalog`
(closed `WorkspacePlanTier` enum). `Plans` (`/admin/platform-plans`) is the only place they
are read and edited. The legacy SMS `plans` table is a different, hidden thing (SMS
sending plans) and `PlatformPlanPresenter` never reads it (contract 21 §4).

`PlatformPlanAdministrator::apply()` is the single writer:

* price / currency / additional-Business price ratio → `EntitlementManager::updateCatalogPricing()`
  (still the only price-history authority; writes `workspace_plan_catalog_pricing_changes`);
* name, active, available-for-signup, billing cycle, trial, Stripe Price id, Business slots
  included/max/unlimited, Location slots included/max/unlimited →
  `WorkspacePlanCatalogRepository::updateStructure()` (new; the existing `update()` keeps its
  4-column whitelist);
* packaged features → `WorkspacePlanFeatureRepository::syncFeatureKeys()` (new);
* the Stripe Price parity check (`PlatformPriceVerifier`) runs before any row changes;
* the legacy Billing & Revenue form (`platform-billing.update`) now delegates to the same
  service, so there is one code path, not two;
* a reason is required; each change is recorded in `platform_admin_actions` with a
  field-by-field before/after.

**No create, no delete.** Tiers are a code enum and every history table
(`workspace_plan_assignments`, `platform_subscriptions`, `workspace_entitlement_transitions`,
pricing changes, slot agreements) restricts deletion of a catalog row. A plan is retired by
**archiving** it (`is_active = false`, which also forces `available_for_signup = false`):
it stops being sold and assigned, and no subscription or history row is touched. It can be
reactivated. A price cannot be cleared while customers are subscribed
(`PlanCatalogPricingInUseException`) — the owner is told to archive instead.

Slot changes apply to new capacity decisions; customers keep what they already have.

## 4. Feature packaging UI

`PlatformFeatureGroups` maps every `PlatformFeature` to a human group — CRM, Conversations,
Calendar, Automations, Website, SEO, Ads, Forms, Packages, Payments & Contracts, AI, Agency —
with a readable label. A case missing from the map still appears under *Other* (a test
proves every case is grouped), so a new feature can never silently vanish from packaging.
Features that are not live yet show "Coming soon" (`PlatformFeatureRegistry`, unchanged).
Raw keys appear only as small secondary text on the Feature Management matrix.
Entitlement *decisions* are untouched: packaging is data, `PlatformFeatureRegistry` still
gates availability first.

## 5. Users

`/admin/platform-users`: search (name/email), filter (active / suspended / email not
verified), verification, status, Workspace memberships (two batched queries per page),
last activity. Administrators are excluded (managed under Administrators).

Actions (`PlatformUserActions`, every one re-checks `PlatformOwnerAuthority::assertAdministrator`,
refuses administrator targets and the actor themself, and writes an audit row):

* **send password reset link** — `Password::broker()->sendResetLink()`; the link is emailed to
  the user, never shown, stored, logged or put in the audit row;
* **resend verification** — only while unverified;
* **suspend / reactivate** — `users.status`, reason required; suspending also ends sessions;
* **sign out of all sessions** — sets `users.password_changed_at`; the existing
  `CheckPasswordChanged` middleware ends any session whose login predates it. The password
  itself is untouched.

No password is viewable or settable anywhere.

## 6. Administrators and Roles

Same routes and controllers, new server-rendered pages (no DataTables, no popup).

* **Invite**: first/last name, email, roles. The account is created with a random unusable
  password and the standard emailed set-password link is sent; the owner never types or sees
  a password. **Resend invitation** is on the edit page.
* **Edit**: name and roles (validated against `RoleRepository::getAllowedRoles()`, the legacy
  allow-list); cannot strip all roles from yourself.
* **Activate / deactivate**: reason required; deactivation ends sessions; you cannot
  deactivate yourself or the super admin.
* **Roles**: server-rendered list (permission count, administrators, status); the existing
  permission-matrix edit form is kept. Role create/update write an audit row with the
  permissions added/removed.
* Audit: `administrator.invited|invitation_resent|updated|activated|deactivated`,
  `role.created|updated`.

## 7. Announcements

`/admin/platform-announcements` — **management** only: `draft → scheduled → published`, or
`cancelled`; **expired** is derived (`published` past `expires_at`), never stored. Audience:
everyone, or specific plans. Channels: in-app, email. Table `platform_announcements`.

Delivery is **not** built here. `PlatformAnnouncementDelivery` is the seam: the manager calls
`deliver()` once when an announcement is published and `withdraw()` when a published one is
cancelled; the returned opaque reference is stored in `delivery_ref`. The default binding
(`NullPlatformAnnouncementDelivery`, in `AppServiceProvider`) reaches nobody. **Integration:**
Platform Automations rebinds the interface to its own delivery and calls
`PlatformAnnouncementManager::sweepDue()` from its scheduler to publish scheduled rows whose
time has come (idempotent; locks each row). No recipient or per-channel state is stored by
this lane. The legacy `announcements` / `announcements_user` tables are untouched.

## 8. Provider / system readiness

`PlatformProviderReadiness` answers per provider from configuration alone — **Not configured**,
**Connected**, **Needs attention** — for Stripe, Messaging (Telnyx), AI (OpenAI), Google
(Business Profile), Google Ads and DataForSEO. No network call, no client built, no secret value
(only the *name* of what is missing). It is shown on Home and on Safety Limits. A test renders
every primary Platform page with all provider keys blank, with the real gateways (no fakes),
and asserts HTTP 200, no raw translation key, and that a configured secret is never printed.
(The audit found no constructor that boots Stripe eagerly — `StripePaymentProviderGateway`
builds its client lazily — so no code change was needed there; the guard is the test.)

## 9. Support / recovery

`/admin/platform-support`: one search over users, Workspaces and Businesses, linking to the
surfaces that already hold the legitimate actions — the Workspace page (access decision, plan,
suspension/grace state, restore-access), the user page (reset link, verification, suspend,
sessions), and Audit Logs. **View As** is an Agency-owner facility inside their own client
Workspaces (contract 04) with no Platform Owner entry point; this lane does not add one
(that would be an impersonation bypass), and the Support page says so. Every sensitive action
is audited.

## 10. Audit trail

`platform_admin_actions` — append-only (no `updated_at`), one writer (`PlatformAdminAuditLog`).
Justified because `workspace_entitlement_transitions` requires a `workspace_id` and cannot
record "an administrator was invited" or "plan Core was repackaged". Workspace-scoped
actions keep landing in `workspace_entitlement_transitions`. Audit Logs shows both, the new
one filterable by type (plans, users, administrators, roles, announcements). Payloads carry
identifiers and before/after values only — never a secret, token or link.

## 11. Authority

Unchanged rule (contract 22 §2): `users.is_admin` via `EnsureUserIsAdministrator`, plus the
per-feature permission string as an independent second layer (`view customer` /
`edit customer`, `view|create|edit announcement`, `view|create|edit administrator`,
`view|create|edit roles`, `view|manage workspace plans`). No new permission string. Business
users, Agency owners and guests receive 401 on every route, tested route by route.

## 12. Schema

Two tables, both new, both justified above: `platform_admin_actions` (audit) and
`platform_announcements` (management lifecycle). No existing table is altered.

## 13. Tests

`tests/Feature/PlatformOwner/PlatformOwnerV1PlansTest`, `…V1PeopleAndAnnouncementsTest`,
`…V1ReadinessTest`; `PlatformOwnerShellNavigationTest` and
`PlatformOwnerReliabilityHotfixTest` updated for the new IA.
