# V1 Final Acceptance 01 — Account / Signup / Workspace / Shell

Base: `origin/main` 44976df8. Database: `ultimatesms_testing_v1_acceptance_account_shell`.
New acceptance tests: `tests/Feature/V1Acceptance/AccountShell/` (18 tests, 507 assertions).
Everything else cited below is an existing suite that was re-run in this lane.

## Defects found by the integrated journey, and fixed

| # | Defect | Class | Fix |
|---|---|---|---|
| 1 | A paid self-service signup left the Business `Draft` forever (only an admin or an invited client's owner could activate). The new customer reached Home in the Account frame (`home / accounts / settings`); Contacts, Opportunities, Forms etc. were not in the shell. Existing tests hid this: `tenant()` force-writes `status=active`. | A | `BusinessManager::activateSelfServiceBusiness()`, called from `V1SignupManager::activateFromConfirmedSubscription()` (the one seam the browser return and the webhook share). Only a never-activated Draft Business, never an Agency-managed client's. |
| 2 | V1 signup never sent the verification email, but `user.home` is behind `verified` (default `ACCOUNT_VERIFICATION=true`) and the notice says "check your email". | A | `V1SignupController::sendVerificationLink()` after the account exists; failure is reported, never aborts signup. |
| 3 | The Contacts directory (`people.index`, `people.show`, contact picker) ignored Location ACL: Selected-scope staff for Location A could list and open a Location B Contact. | A | `ContactDirectory::restrictToAccessibleLocations()` using the existing `LocationAccessGuard`; owners cost no extra query. |

## Matrix

| Requirement | Result | Evidence | Notes |
|---|---|---|---|
| A. Core signup → one User/Workspace/Business/Primary Location, Core plan, trialing subscription, wallet + payer initialised, Home, Core nav | FIXED + ACCEPTED | `SignupJourneyTest` (journey + `core_shell…`) | Defects 1, 2 |
| A. Growth/Agency routes not reachable by guessing | ACCEPTED | `core_shell…` (404 on GBP, SEO audit/citations/reviews, clients, white-label, prospecting overview; invitation refused, none created) | |
| A. No duplicates on refresh/retry | ACCEPTED | identity counts through retry/resume/success×3/home×3 | |
| B. Growth signup, entitlement-resolved Growth features, no Core leakage | FIXED + ACCEPTED | `growth_shell…` | |
| C. Agency: own Workspace+Business+Location, no client Businesses, Agency surfaces, own Business operable | FIXED + ACCEPTED | `agency_gets_its_own…` | |
| D. Lifecycle: pay, cancel/resume, unpaid/incomplete, retry, grace→locked→recover, resubscribe; no duplicate tenant; no plan while unpaid | ACCEPTED | `SubscriptionLifecycleJourneyTest` (5) | An `incomplete`/`unpaid` provider subscription is "live": resume is refused and recovery is the plan page / Stripe portal (by design, Contract 21). Unassigned Workspace resolves `usable` and can open ungated modules (pre-existing Contract 03 state). Cancelling while trialing shows the resume button, not "Ending at period end". |
| E. One Business per Workspace, Primary Location, switcher hidden for one Business, second Location via canonical path, identity unchanged, foreign Location refused | ACCEPTED | `WorkspaceBusinessLocationShellTest` (3) | |
| E. Global **Location switcher** (Blueprint §7) | **UNRESOLVED — not implemented** | no code; `ContextSwitcherPresenter` states a Location is deliberately not a switcher entry; Location-bound surfaces take Location per request | Needs a product decision; building it is new architecture and was not done. |
| F. Owner reaches all Locations + management; staff-all no owner/billing/admin authority; staff-selected cannot reach Location B by list, direct URL, forged uid (AJAX), search; Agency membership does not widen a client Workspace | FIXED + ACCEPTED | `StaffAuthorityJourneyTest` (4) | Defect 3. Staff can still open the read-only Business Usage & Billing dashboard by direct URL (hidden from their menu; every mutation refused and proven unchanged). Contract §9.3 says restricted staff have "no billing" — owner decision needed whether view should also 404. |
| G. View As (enter, actor attribution, shell, prohibited actions, exit, logout); Platform Owner pages unavailable in View As / after login-as | ACCEPTED | existing: `AgencyViewAsTest` 39/39, `PlatformOwnerAuthorityTest` 12/12, `ViewAsRouteBoundaryTest` 10/11 | One existing failure, see below. Admin "login as customer" makes the admin *be* the customer by documented design (Contract 22-PO §7); its audit is DEFERRED there (§10). |
| H. Account states + recovery + Platform Owner same reason, incl. Agency-composed | ACCEPTED | existing `PlatformOwnerSupportDiagnosticsTest` 10/10 (usable/inactive/suspended/locked/grace vs resolver, gate, admin page) + lifecycle journey (grace/locked/recovery through the real shell) | Agency-composed states: `AgencyViewAsTest` locked/inactive/suspended Agency cases; not re-driven here. |
| I. Navigation vs PlatformFeatureRegistry; Planned modules not shown; hidden ≠ authorised | ACCEPTED (with 2 existing failures) | signup journeys (exact per-tier entries, guessed-route 404s), `CustomerNavigationTreeTest` 52/54 | |
| J. Unauthenticated refused (401 page), customer/Agency owner refused Platform Owner pages, cross-Workspace uid refused | ACCEPTED | `PlatformOwnerAuthorityTest`, `CustomerContextSecurityTest` 9/9, journeys | |

## Existing failures seen in this lane (not caused by it)

Not reproduced on a pristine main worktree (credit rule); attributed by inspection — this lane changed no route and no menu code:
- `ViewAsRouteBoundaryTest::test_every_authenticated_customer_route_is_classified` — unclassified `calendar-connection.*` and `agency.stripe.connect-existing.callback` routes.
- `CustomerNavigationTreeTest::test_opportunities_follows_contacts_…` (Forms now sits before Automations) and `…query_count_does_not_grow…` (6 vs 5; listed as a known baseline failure in lane notes).

## Test-environment notes

The copied `.env` has a real mail transport; journeys pin `mail.default=array`. `Helper::app_config('user_registration_notification_email')` reads an AppConfig row unguarded; the journey seeds it as a real install does. Webhooks are delivered with no customer session, as Stripe does.

## Verdict

**Not written as ACCEPTED.** Unresolved in scope: the Blueprint §7 Location switcher (not implemented), plus two owner questions above (staff read-only billing view; the unreproduced existing failures).
