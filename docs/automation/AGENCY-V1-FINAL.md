# Agency V1 — final polish and acceptance

Base: `origin/agent/v1-completion-integration` @ `d944e1b9`. Branch: `agent/agency-v1-final`.
No PR, no merge to main. This is an audit-and-repair lane over the finished Agency
product (Clients, View As, Outreach, SaaS plans, White label, Agency Home) — it builds no
new Agency product and does not rebuild Agency Outreach. Every fix below was found by
walking the real screens in a browser with an Agency owner, their own Business, two
managed clients, a client user, a second unrelated Agency and a plain Business.

## 1. Final Agency information architecture

The Agency owner has two clearly separated contexts, both always one click apart:

```
YOUR BUSINESS  (frame label "Your business" — the owner's own Business OS)
  Business Home · Opportunities · Contacts · Conversations · Calendar · Automations
  Website · SEO · Ads · Forms · Packages & Products · Payments & Contracts · Business settings
AGENCY         (frame label "Agency account" — the Agency management area)
  Agency Home · Clients · Outreach · SaaS Plans · Agency Revenue · White Label · Team · Agency settings
```

* One shell, two groups, in both frames (`docs/automation/AGENCY-SHELL-OWN-BUSINESS-V1.md`).
  What changed is **which frame a page belongs to**: an Agency-management page — any route
  addressed by `workspaceUid` alone under `customer.workspaces.{clients,client-invitations,
  agency,team,settings,plan,prospecting}.*` or the account overview — is the **Agency account**
  frame (`CustomerContextResolver` step 3a). Opening one remembers the account frame exactly as
  the switcher's account option does; opening any own-Business module returns to the Business
  frame. Before, the remembered own Business stayed "current" and the Clients list sat under
  "Your business · Northwind Photo Booths" (and a "Northwind Photo Booths" browser tab).
* The context switcher in the Agency shell lists the owner's **own** Business under
  "Your business" (never "Client accounts") and offers **no** "View X as a client" for it. A
  managed client lives in its own Workspace and is reached only through Clients → View As
  (or Agency Home → "View as client").
* The sidebar says **Outreach** (it said "Prospecting" in the Agency shell and "Outreach" in
  the account frame, over a page titled "Outreach"). Route names, URLs and the entitlement key
  are unchanged. Business Home now stays lit on Results pages, as in the plain Business frame.
* Agency Home: "Manage client accounts" opens Clients (it opened the stale account overview, which
  lists no clients); "Manage capacity" opens Plan & subscription; the paused-billing button opens
  Agency account settings. The per-client button says **View as client** (it is a View As POST; on
  Clients "Open" is a GET to the detail page and is now called **Details**). A client still in setup
  reads "Waiting for client setup" (it read "Not active … Nothing needs attention"). No clients:
  a real empty state with an "Invite a client" button instead of a header-only table.

## 2. View As behaviour (what is true now)

* **Banner + Exit on every page.** The banner used to be rendered by `panels.breadcrumb`, which the
  layout includes only when `THEME_BREADCRUMBS` is on. The real installs' `.env` files set it to
  `false`, so on every module page — CRM, Conversations, Calendar, Forms, Website, SEO, Ads,
  Packages, Payments — there was **no banner and no Exit control** (only Business Home, which
  included it itself, had one). The layout masters now render it themselves, once, for every page;
  the locked-account page (which has no shell) carries it too. Source guard:
  `AgencyV1FinalPolishTest`.
* Readable and persistent: the theme's `.alert-warning` carries `color … !important` (orange on pale
  orange, **1.84:1**); the banner no longer uses it (now 13.8:1, tokens only), is `position: sticky`
  (below a fixed header when the header is sticky/floating) and, at phone width, shows only
  "Viewing <client> as a client." plus **Exit client view** (a sticky banner must not cover a quarter of
  a 375 px screen).
* Identity never changes (`Auth::id()` is the real Agency actor on every request, the audit row names
  them); View As never writes the remembered context. Entering from the Agency account and exiting
  returns to the Agency account; entering from the own Business and exiting returns to the own
  Business — no random current-Business switching (`test_view_as_never_moves_the_owners_context…`).
* Closed under View As (404), by classification rather than by absence: the Agency's own Stripe
  callback, the actor's personal calendar connection, and the actor's own Platform notices/banners
  (the shell no longer polls the closed feed on every page while viewing). These four routes were merged
  after the route inventory was last swept and made `ViewAsRouteBoundaryTest` fail on the base branch.
* Never Platform Owner impersonation: the session row records the Agency owner as actor, the
  platform's `customers/{id}/impersonate` entry stays closed to a customer, no `admin_user_id` marker
  ever exists, and the platform portal is unreachable from a View As session.

## 3. Client management

* Client detail now reads in plain words: **Plan and account** (plan by name, account state through the
  Clients list's own reader), **At a glance** (Business setup state, Website, Google listing, usage
  funding, "Needs attention" sentences — the same ones Agency Home flags with — and new contacts /
  conversations in the last 30 days, through the portfolio's own readers). No raw `workspace`,
  `agency_rebill`, `past_due`; the card is "Usage funding" (not "AgencyRebill"); no "data-integrity
  issue outside this screen's scope". There is **no booking figure**: the product has no canonical
  booking count to read, and Business Home does not invent one either.
* Pending invitations were invisible after sending (the page still said "No clients yet"). They are
  listed with a "Cancel invitation" button (owner only), keyed by the Agency Workspace id.
* Clients is usable at 375 px: the table is for wide screens, clients stack as cards on phones.

## 4. Defects found and fixed

| # | Defect (verified in a browser) | Fix |
|---|---|---|
| 1 | No View As banner/Exit on any module page (`THEME_BREADCRUMBS=false`) | Banner in the layout masters |
| 2 | Banner text 1.84:1; scrolled away; 206 px tall on a phone | Token colours, sticky, compact on phones |
| 3 | Own Business listed as a "Client account" with "View … as a client" | Switcher heading/option fix |
| 4 | Agency pages under "Your business · <own Business>" | Resolver step 3a |
| 5 | "Create Business" on the Agency overview → HTTP 500 with a raw `SQLSTATE[23000] … businesses_workspace_id_unique` | Form hidden when the account has its Business; hand-made POST gets a plain sentence |
| 6 | Overview said "No client accounts yet" while clients existed | Points at Clients |
| 7 | Agency Home buttons opened the stale overview | Right destinations |
| 8 | `flash_success` / `flash_error` never rendered customer-side (Outreach Pause/Resume AI, Save script, campaign start errors, Agency funding failures all silent) | `PageToasts` also toasts `flash_*` unless the page already says it |
| 9 | Raw enums/jargon on client detail and SaaS pages; "Stage 99" | Labels, stage words |
| 10 | Own-Business billing page: "Your agency pays for this client account's usage … the client sees…" | Own-Business wording |
| 11 | Raw `locale.labels.block` tooltip on Conversations | Key added |
| 12 | `ViewAsRouteBoundaryTest` red on base (8 unclassified routes) | Classified |
| 13 | No pending-invitation list/cancel; no empty state on Agency Home | Added |
| 14 | Outreach never said whose number/wallet it uses | One sentence on Sending |
| 15 | Mobile: Clients table sideways; navbar search wrapped the header at 375 px | Stacked cards; `min(220px, 34vw)` |
| 16 | Sidebar labels `Google`/`Meta` had no English menu entry (failing translation test) | Added |

## 5. Not changed, on purpose

* Agency Outreach (rebuilt nothing). Existing Outreach isolation, View As, readiness and
  Pause/Resume tests are part of the verification run.
* Same-workspace `customer.view-as.start` route: still live (legacy callers), just no longer offered
  from the Agency switcher.
* Phone numbers on Outreach pages are shown in the stored digits form; "Client accounts" remains the
  Agency Home band name while the sidebar says "Clients"; the Team page's "Back to Settings";
  duplicate `<h2>` title bars when `THEME_BREADCRUMBS` is on — all cosmetic, listed in the report.
* Pre-existing red tests unrelated to Agency (query-count budgets 6 vs 5, `has-sub` count after the Ads
  group, DesignSystem marker counts, Branding legacy strings, CustomerContextResolution's stale
  menu lists) are unchanged in name and message vs the pristine base.

## 6. Verification

Run against `ultimatesms_testing_agency_v1_final` (lane) and `ultimatesms_testing_agency_v1_final_base` (a
pristine detached worktree of `d944e1b9`, same suites), always by failing test **name and message**. In every
suite the lane's failing set is a strict subset of the base's: nothing new fails, and these base failures are
fixed — `ViewAsRouteBoundaryTest` (2), `ViewAsClientTest` (1), `CustomerShellTranslationTest` (1, Ads
`Google`/`Meta` menu keys), `BusinessLocaleFieldsTest` (1), a stale hub test (1), `CustomerShellNavigationTest`
raw-key (1), two `CustomerContextResolutionTest`. The pre-existing reds that remain are untouched by this lane
(Navigation query-count 6 vs 5 ×2, QueryBudget ×8, Branding ×3, DesignSystem marker counts ×4, CustomerShell ×2,
Analytics budget ×1, CustomerContextResolution ×5, Business capacity ×2).

### Re-verification after the rebase

The integration branch moved while this lane was open (`6c42beb3`, Booking Notifications V1: 30 files, none shared
with this lane). The lane was rebased onto it (first rebase) and re-run; the tables below are from that run. Comparisons are by failing test **name and
message**, against a pristine detached worktree of `6c42beb3` on a disposable sibling database.

| Suite | Result after the rebase |
| --- | --- |
| `tests/Feature/Agency` (polish + isolation + every existing Agency, Outreach and billing test) | 150 passed (2721 assertions) |
| `tests/Feature/Navigation` | 98 passed, 2 failed |
| `tests/Feature/Calendar` | 450 passed, 8 failed |
| `tests/Feature/Workspace` (includes every View As and context test) | 1099 passed, 85 failed |
| `tests/Feature/Security` (includes `ViewAsRouteBoundaryTest`) | 355 passed, 1 failed |

No failure is in an Agency, View As, isolation or navigation-contract test. Each is outside this lane:

* Navigation x2 — query-count budget (6 queries against 5); identical on the base.
* Calendar section navigation x2 — router-script assertions; identical on the base.
* Calendar `AppointmentBookingConcurrencyTest` x6 — multi-process timing ("children entered ... too far to be a
  genuine race"). The same kinds of failure occur on the pristine base on an otherwise quiet machine (2 of 20) and
  the count rises with machine load; it is a timing test, not a behaviour change.
* Workspace x77 — migration-replay tests (`AgencyBusinessMigrationV1*`, `NonAgencyBusinessSplitV1`,
  `NonAgencyMultiBusinessReport`, `WorkspaceBusinessOneToOneEnforcement`, `WorkspaceMembershipLocationRepository`,
  the PreContract13 probes) fail with `Cannot drop index 'website_assets_website_id_sort_order_index': needed in a
  foreign key constraint` while replaying migrations. This lane changes no migration. `NonAgencyMultiBusinessReportTest`
  reproduces the identical error on the pristine base (7 of 7); the other classes of this family raise the same
  exception from the same replay and were not re-run one by one.
* Workspace `CustomerContextResolutionTest` x5 — all five also fail on the base (which fails seven; this lane fixed
  two). Two have identical messages. Three now stop on a *later* assertion only because this lane corrected the
  earlier stale ones: the navbar's inline search script carries a `/workspaces/...` URL, so "the shell must not say
  Workspace" fails (same assertion on the base); Results is no longer a menu entry, so a stale `analytics` link
  assertion fails; and a stale "no `accounts` key on a business route" assertion contradicts the Blueprint section 28
  rule (Clients stays in both frames) that the same test asserts a few lines earlier. The lane does not change when
  the `accounts` key appears.
* Workspace `AdminWorkspaceEntitlementControllerTest` x1, `WorkspaceAccountCreationBoundaryTest` x1,
  `PreContract13HistoricalTestCaseLifecycleTest` x3 — fail on the base as well (1, 1 and 4).
* Security `LegacyWebhookUsageMeasurementTest` x1 — a 20-second child-process timeout under machine load; it passes
  15 of 15 when re-run alone, in the lane and on the base.

The suites outside this list (QueryBudget, Branding, DesignSystem, CustomerShell, Analytics, Business capacity) were
compared against the base before the rebase, as above; the rebase delta shares no file with them.
### Final head: rebased again onto `c2a31402`

The integration branch moved a second time (24 commits: Platform Owner, Growth, Website, signup/onboarding,
deployment readiness). Three files overlap this lane: `app/Library/Dashboard/AccountHomePresenter.php`,
`resources/lang/en/locale.php` and `tests/Feature/Dashboards/AgencyAccountHomeTest.php`. The rebase merged them
without conflict; the merged presenter differs from the integration head only by this lane's intended changes, and
the signup lane's own fix in it is kept. The focused set was re-run on the final head:

| Suite | Result on the final head |
| --- | --- |
| `tests/Feature/Agency` (polish + isolation + existing Agency, Outreach, billing) | 150 passed (2721 assertions) |
| `tests/Feature/Dashboards` | 241 passed (2718 assertions) |
| View As: `AgencyViewAsTest` 39, `AgencyViewAsContextResolutionTest` 20, `ViewAsClientTest` 7, `ViewAsAccessLossTest` 6, `Security/ViewAsRouteBoundaryTest` 12 | all passed |
| `WorkspacePlanPageTest` 16, `PlatformAnnouncementTest` 10, `tests/Feature/Theme` 87, `BusinessLocaleFieldsTest` 10 | all passed |
| `tests/Feature/Navigation` | 98 passed, 2 failed |
| `tests/Feature/CustomerShell` | 30 passed, 2 failed |
| `DesignSystem/CustomerShellNavigationTest` | 10 passed, 1 failed |
| `Analytics/AnalyticsResultsExperienceTest` | 22 passed, 1 failed |
| `Workspace/CustomerContextResolutionTest` | 16 passed, 5 failed |

Each of those suites was also run once on a pristine detached worktree of `c2a31402`, compared by failing test name
and message. Navigation: 2 of 2 identical. CustomerShell: 2 of the base's 3 (the lane fixed the third).
DesignSystem: 1 of the base's 2. Analytics: 1 of 1. CustomerContextResolution: 5 of the base's 7 by name (two with
identical messages; three now stop on a later assertion for the reasons above). Nothing fails in the lane that does
not fail on the base. The wide Calendar, Workspace and Security runs above were not repeated on the final head: the
new commits touch none of this lane's files beyond the three named here.
## 7. Tests

* `tests/Feature/Agency/AgencyV1FinalPolishTest.php` — one test per defect above.
* `tests/Feature/Agency/AgencyV1FinalIsolationTest.php` — the integrated proof: Northwind Agency (own
  Business, Client A on Growth, Client B on Core), a second Agency with its own client, a plain Business;
  every module of every Business holds a marker (`tests/Feature/Agency/Support/SeedsAgencyFleetModuleData.php`).
  View As Client A crawls every module and never renders another Business's marker; every other
  Business is 404 in both directions; a foreign record id never resolves inside Client A's address for
  the viewing Agency or for Client A's own owner; plain/client/other-Agency actors are refused on every
  Agency route; forged ids fail closed; a client cannot escape through the context switchers; View As is
  not impersonation; entitlements are per Business; every sidebar destination is a 200 in both frames.
