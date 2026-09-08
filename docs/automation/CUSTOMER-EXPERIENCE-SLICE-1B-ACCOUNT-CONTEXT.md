# CUSTOMER EXPERIENCE — SLICE 1B: ACCOUNT CONTEXT, BUSINESS SWITCHING AND CUSTOMER NAVIGATION

## 1. Status and authority

**Status:** Implemented on branch `agent/customer-experience-slice-1b-account-context`.

**Parent contract:** `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`
(Correction Round 1), whose §5, §8, §9, §17, §18, §21 (row 1B), §22.1 (row
1B), §24 and §24.1 govern this slice. Where this document and the parent
disagree, the parent wins.

**Verified base:** `origin/main` at `c11d3191229523bf5b63cb85b68e3b3f002e4538`
(`Merge pull request #215`), with `05cd1684b6d6cd593d87d432fab7a335cc64b4ec` as
an ancestor.

**Owned tests (parent §24.1):** T-CTX-1, T-CTX-2, T-CTX-3, T-CTX-5,
T-VIEW-1..4, T-NAV-1..3. T-CTX-4 (owned by Slice 1A) is *supported* here by
an exclusion assertion on the switcher, without claiming ownership.

## 2. The locked account model, as implemented

| Level | Technical object | Customer-visible as |
|---|---|---|
| Platform owner | admin portal (`active_portal = admin`) | Never inside the customer shell |
| Workspace | `workspaces` + memberships | **Agency only** ("agency account", "Client accounts"); invisible wording for Core/Growth |
| Business | `businesses` | "Business" (Core/Growth) or "Client account" (Agency) — the working context |
| BusinessLocation | `business_locations` | Never an account level; excluded from the switcher by construction |

No table, foreign key or RFC-003/RFC-004 authorization path changed. The
slice adds one audit table (`view_as_sessions`) and no other schema.

## 3. Canonical context resolution

`App\Library\Navigation\CustomerContextResolver::resolve()` is the single
resolution, run once per request by `App\Http\Middleware\ResolveCustomerContext`
(registered in the `web` group) and bound into the container for the shell.

Order of resolution:

1. authenticated customer-portal user (guests, admins and API traffic: no-op);
2. visible Workspaces — `CustomerContextSnapshot::forUser()`, ONE joined
   SELECT with `WorkspaceRepository::allForUser()` semantics (owned, or
   active membership), carrying each Business, the actor's membership row,
   its assignments and the active plan tier;
3. Workspace: active View-as session → route `workspaceUid` → remembered
   preference → the only one; otherwise none;
4. Business inside that Workspace: View-as → route `businessUid` (the owning
   controller authorizes it on the same request) → remembered preference,
   **re-authorized through `WorkspaceManager::userCanAccessBusiness()`** →
   the only selectable one (also re-authorized); otherwise none;
5. cross-Workspace pairs, inactive Workspaces, inactive memberships,
   draft/inactive Businesses and inaccessible Businesses are never selected;
6. several remaining choices → **no choice is made**; the Account frame asks
   for an explicit selection. Database order never decides.

The snapshot's `accessible` flag mirrors RFC-003 §14.1 for *listing only*
(parent §18 S-3). It grants nothing: every route keeps its own tenancy check,
and every switch or view-as action re-runs the canonical decision.

**Why one SQL statement.** The shell renders on every customer page, and the
B5 Analytics overview holds a strict budget of 12 tenancy-plus-KPI queries of
which the page itself spends 11. The joined snapshot costs exactly one, so the
budget still holds (`AnalyticsPerformanceTest` passes unchanged).

**Preference.** `CustomerContextPreference` keeps `{workspace, business}` uids
in the session as a navigation preference only. It is validated against the
snapshot and re-authorized canonically on every use, and cleared the moment it
stops naming an accessible, active Business.

## 4. Frames, vocabulary and the menu

`CustomerMenuBuilder` renders exactly one of two frames (parent §8.1):

**Business frame** (a Business is selected): Home · Advisor (when the
Opportunity Engine is enabled) · Contacts · Conversations · Campaigns ·
Automations · Website · Google Business Profile · Analytics · Settings →
Business details (only when the selected Business is the actor's own primary
one, because `BusinessController@edit` resolves the primary Business) ·
Blocked numbers · Usage & billing (Business customer or Workspace
owner/admin) · Team & account (owner/admin) · Plan & subscription
(owner/admin who is a customer) · Advanced (Agency owner/admin only:
Messaging provider, Sender IDs, Numbers, Keywords, Developers).

**Account frame** (no Business selected): Home · Advisor (when the
Opportunity Engine is enabled and the actor can reach at least one Business,
active or draft) · Client accounts / Businesses / "Choose an account" ·
Prospecting (Agency) · Settings → Plan & subscription · Advanced (Agency
owner/admin).

Rules enforced by the builder:

* an entry is emitted only when its route is registered (T-NAV-3);
* an entry is emitted only when the actor passes the same Gate the route
  enforces (T-NAV-2); visibility is never the boundary (T-NAV-1);
* Business-scoped entries carry the selected Workspace/Business uids;
* active state is computed from the current route **name** and the frame,
  never from the URL's leading `/workspaces/` segment (parent §8.5, T-CTX-5);
* labels are human labels; the sidebar uses a translation only when
  `locale.menu.<label>` exists, so no key path can render (parent §17.1).

**Vocabulary.** `CustomerContext::isAgency()` (frame Workspace on the Agency
tier) selects "Client account(s)" and shows the agency name; everything else
says "Business(es)" and never renders the word Workspace in the shell
(T-CTX-2). URLs keep the `/workspaces/{uid}/businesses/{uid}/…` shape.

**E-11.** The dead `url('outreach/campaigns')` link is corrected twice: the
builder links directly to `customer.workspaces.businesses.outreach.campaigns`
for the selected Business, and a bare `GET /outreach/campaigns`
(`customer.outreach.campaigns.entry`, `CampaignsEntryAction`) now resolves the
legacy target through the canonical context — redirecting to the one campaign
list, or handing over to the existing Outreach chooser. No second campaign
surface exists.

**Legacy array.** `Helper::menuData()['customer']` is no longer rendered to
customers. It is retained as compatibility data for its remaining
non-rendering consumers (existing tests in Outreach, GBP security and the
Workspace switcher), documented as such in place.

## 5. Switcher and header

`resources/views/components/customer-context-switcher.blade.php` (navbar):

* one reachable Business → a compact labelled identity, no switcher;
* several → a Bootstrap dropdown (`aria-haspopup="menu"`, labelled toggle,
  `role="menu"`/`menuitem`, current option `aria-current="true"`) whose items
  are CSRF-protected POSTs to `customer.context.business.switch`
  (`SwitchBusinessAction`): Workspace by uid → Business inside it →
  `userCanAccessBusiness()` → active → remember → redirect to Home. Forged,
  cross-Workspace, inactive or foreign uids are 404;
* Businesses spanning several accounts are distinguished by account name,
  never by an internal id; physical locations never appear;
* Agency owner/admin additionally get "View … as a client" per Business.

The sidebar header names the current context (Business, or agency account).

## 6. View as client (parent §5.5)

* `POST /view-as` (`StartViewAsAction` → `ViewAsManager::start()`):
  active Workspace owner or active Admin, `userCanAccessBusiness()`, active
  Business; otherwise 404. Writes a `view_as_sessions` row (actor, Workspace,
  Business, reason, started_at, expires_at = +60 min) and remembers its uid in
  the session. Starting again ends the previous session (`replaced`).
* `Auth::id()` never changes. `ResolveCustomerContext` layers the view:
  the context is narrowed to the viewed Business; any other `businessUid`
  route is 404 (T-VIEW-4); the §5.5 prohibited actions
  (`ViewAsProhibitedActions`: payer, funding and spend controls, plan and
  slots, Workspace staff and ownership, provider/channel screens, number and
  keyword purchase or release, every DELETE and `.destroy`/`.batch_action`,
  another view-as, the Business switch) are refused with a plain message and
  appended to the row's `refusals` audit (T-VIEW-3).
* Banner: `components/view-as-banner.blade.php`, included by the breadcrumb
  partial on every page, `role="status" aria-live="polite"`, names the viewed
  client, the real actor and the expiry, and carries the Exit control.
* Expiry is evaluated server-side on every request (`ViewAsManager::current()`),
  ends the row as `expired` and returns the actor to the Agency frame
  (T-VIEW-2). Explicit exit (`POST /view-as/exit`) ends it as `exit`; logout
  ends it as `logout` through an `Illuminate\Auth\Events\Logout` listener
  (`AppServiceProvider`). Exit never logs the actor out (T-VIEW-1).
* The legacy `parent_id` impersonation is untouched.

## 7. Accessibility

Sidebar: `role="navigation"` with a frame-specific label, `aria-current="page"`
on exactly one active entry, `aria-expanded` on groups, text label on every
entry, decorative icons `aria-hidden`. Navbar: named mobile toggle wired via
`aria-controls` to the navigation, named collapse toggle, keyboard-operable
switcher (native Bootstrap dropdown). Banner announced through `role="status"`.
No new framework, no hardcoded colours, all icons through `<x-ds-icon>`.

## 8. Changed paths

Implementation: `app/Library/Navigation/**` (new), `app/Library/ViewAs/**`
(new), `app/Http/Middleware/ResolveCustomerContext.php` (new),
`app/Http/Kernel.php` (registration only), `app/Models/ViewAsSession.php`
(new), `app/Providers/{MenuServiceProvider,AppServiceProvider}.php`,
`app/Helpers/Helper.php` (customer branch docblock only),
`resources/views/panels/{sidebar,navbar,breadcrumb}.blade.php`,
`resources/views/components/{customer-nav-item,customer-context-switcher,view-as-banner}.blade.php`
(new), `routes/customer.php`,
`database/migrations/2026_09_10_140001_create_view_as_sessions_table.php` (new).

Tests: `tests/Feature/Workspace/Concerns/CreatesCustomerContextFixtures.php`,
`tests/Feature/Workspace/{CustomerContextResolutionTest,ViewAsClientTest}.php`,
`tests/Feature/Security/CustomerContextSecurityTest.php`,
`tests/Feature/DesignSystem/CustomerShellNavigationTest.php`.

Documentation: this file and the parent contract's Appendix B.

## 8a. Two B5 Analytics assertions outside this slice's allowlist

Two assertions in `tests/Feature/Analytics/**` encode the pre-1B static
navigation and fail on this branch. Both files are outside the Slice 1B test
allowlist (parent §22.1), so they are **not edited here**; each needs a
one-line update by the owner of that suite:

| Test | Why it fails now | Required correction |
|---|---|---|
| `AnalyticsCampaignTest::test_pagination_is_25_per_page_with_disjoint_pages_and_two_aggregate_queries` | Its per-page budget (`≤ 8` statements naming tenancy or analytics tables) had zero headroom; the shell's single `CustomerContextSnapshot` statement makes it 9. The overview budget of 12 still holds. | Raise the campaign-page bound to 9 with a comment naming the shell snapshot. |
| `AnalyticsViewTest::test_customer_nav_offers_analytics_and_no_legacy_reports_or_ghost_entries` | Asserts the literal bare URL `url('analytics')` in the rendered overview. The Business-frame menu now links Analytics directly to the canonical `customer.workspaces.businesses.analytics.overview` for the selected Business. | Assert the canonical route URL for the fixture's Workspace/Business instead of `url('analytics')`. |

The legacy-removal half of the second assertion (no `reports/*`, no ghost
admin entries) still holds and is additionally covered by
`CustomerShellNavigationTest`.

## 9. Deliberately left for later slices

* Login/auth branding, `resources/lang/en/locale.php` completeness and the
  wording of pages outside this allowlist (Workspace overview, Usage &
  Billing, Analytics "Back to Workspace" links) — Slice 2 / Slice 10.
* Physical locations & service areas, Business phone, Calendar, Workspace
  aggregate billing — Slices 1A, 4, 5 and future capability contracts; no
  menu entry is invented for them.
* Moving the BYO provider form itself into Settings → Advanced — Slice 3/9;
  this slice only relocates the *navigation entry* (Agency owner/admin).
* A Business-rooted URL alias (parent §8.5) — explicitly out of scope.
* **Pre-existing finding, not fixed here:** `customer.workspaces.show` (the
  Workspace overview) answers 200 to *any* active member of the Workspace,
  including a selected-scope client, so a client who types that URL can
  observe the Agency's name, plan and staff list (parent §5.4). The route and
  page belong to `app/Http/Controllers/Customer/Workspace/**` and
  `resources/views/customer/workspaces/**`, outside this slice's allowlist.
  Slice 1B removes every navigation path a client has to that page and
  records the exposure here for the owning slice; the Workspace page needs a
  role gate (owner/admin) or a client-safe rendering.
