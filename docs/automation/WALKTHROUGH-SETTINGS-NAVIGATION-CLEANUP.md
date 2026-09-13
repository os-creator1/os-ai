# Walkthrough settings and navigation cleanup — implementation record

Route-3 manual lane. Branch `agent/walkthrough-settings-navigation-cleanup`,
from `origin/main` `a6c5f5f`. It implements the owner's walkthrough decisions
and the Settings UX direction that superseded the first draft of this lane
(no expandable Settings tree; a Settings hub instead). It builds on the
navigation redesign contract (`AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md`
§7.1: Core and Growth do not manage an account level and get no switcher) and
supersedes the Team and "Account settings" parts of
`CUSTOMER-SHELL-ACCOUNT-PROFILE-CLEANUP.md`.

Not touched: the Conversations page body, the Automations Builder body, the new
CRM Opportunity domain, Telnyx provisioning, the Results body.

## 1. The Business sidebar is flat

Home · Advisor (when the Opportunity engine is on — it exists today) ·
Conversations · Contacts · Automations · Website · Get found · Results ·
Settings. Only modules that exist are listed; there is no Opportunities or
Forms link.

- **Conversations** is one destination — the selected Business's
  conversations, entitlement-gated as before. The "Messages → Inbox" group is
  gone.
- **Settings** is one destination: the Settings hub. Nothing expands under it,
  and it stays active on every screen the hub leads to.

## 2. The Settings hub

`customer.workspaces.businesses.settings.show`
(`/workspaces/{account}/businesses/{business}/settings`, next to the existing
`…/settings/text-messaging`). A page of cards, each module linking to its own
existing screen. `CustomerMenuBuilder::settingsSections()` builds the modules
with exactly the gates every menu entry uses — route registered, a permission
that reaches it, reachable while viewing as a client — so a module the actor
cannot use is not there.

| Section | Core / Growth Business | Agency client Business |
|---|---|---|
| Business setup | Business details (the customer's primary Business), Locations | same |
| Communication | Text messaging | same |
| Account & billing | Billing, Plan & subscription, Team | **Billing** only — plan and team are the Agency account's |
| Features | the Business's feature switches (managers) | — (on the Agency account page) |

The feature switches (Conversations, Automations, Website, Get found) moved
here for Core and Growth because their account page is no longer shown; they
use the same enable/disable actions and EntitlementManager authority, through
one shared partial (`customer.workspaces.partials.business-feature-switches`).

The Agency account keeps its own hub, `customer.workspaces.settings.show`
(`/workspaces/{account}/settings`), reached from the account frame's single
Settings entry: **Agency account** (Agency account details — the existing
account page with client accounts, client creation and who pays —, Plan &
subscription, Team), **Outreach** (Blocked numbers) and **Advanced** (the
provider surfaces, owner with `manage_advanced_provider` only). There is no
customer white-label/branding screen or account-level billing page today, so
neither is linked. A client Business never repeats these.

## 3. Core and Growth have no account destination

A Core or Growth account is not a customer-managed object.

- `customer.workspaces.show` and `customer.workspaces.settings.show` redirect
  to the Business's Settings hub. No account overview, status/role, rename,
  owner controls, Business list, chooser or Business creation is shown.
- The context switcher offers no account link; with one Business it is the
  plain, labelled identity of that Business (no one-row menu).
- **Exceptions, deliberately kept:** an account with **no Business yet** shows
  only the form that creates its first Business (the zero-Business Home links
  there while onboarding is off); an account whose Business is **not active
  yet** is sent Home, which explains that; an **inactive** account keeps its
  page, because reactivating it is the only thing left to do.
- Decided by the account's own plan, never by the viewer: someone in a Growth
  account and an Agency account sees each account as that account is.
  Accounts with no plan assigned keep the overview unchanged.
- Server-side authorization is unchanged; Business creation for Core and Growth
  remains bounded by the plan's Business capacity.

## 4. Team — one canonical destination

Settings → **Team** is `customer.workspaces.team.show`: the account membership
(members, Owner/Admin/Staff roles, all- or selected-Business access). It is the
Members card that used to sit on the account page, moved to a page of its own;
the member actions and their authorization are unchanged and now return to
Team. Offered to the owner and active Admins, never to a team member signed in
as the account holder, never while viewing as a client.

The legacy delegated-access surface (`customer.sub_accounts.*`, "Team
members") is no longer offered anywhere in the shell. Its routes, models,
permissions and pages are untouched pending its separately contracted
retirement.

## 5. Blocked numbers

Core and Growth: removed from navigation and Settings; the blacklist table and
the sending-time checks that read it are untouched. Agency: the existing screen
stays reachable from the Agency account's Settings → Outreach, not repurposed.
**Seam:** the Agency's real need is an outreach suppression list, which belongs
at Agency account → Prospecting → Suppression list; when it exists it replaces
this module.

## 6. Profile has no Webhook URL

The personal Profile's "Webhook URL" tab is removed. Recon: the stored
`users.webhook_url` is still read at runtime — legacy inbound message handling
(`DLRController::inboundDLR()` and `inboundReceiveWebhook()`) forwards inbound
SMS to it — so the column, the `customer.developer.webhook` save route and that
runtime are kept, and only the customer surface is gone.

## 7. Smaller corrections found on the way

- Billing's back link returns to Settings (an Agency's still returns to its
  client accounts).
- Locations' "See your plan" opens the plan page itself.
- `CustomerShellTranslationTest`'s label inventory described labels the builder
  had stopped emitting; it now lists today's, and the three labels without an
  English entry (Get found, Results, Billing) have one.

## Follow-ups not done here

- The Results page's "Back to Account" link still reads "Account" (it now
  lands on Settings); the Results body is out of scope for this lane.
- `CustomerContextResolutionTest::test_back_links_and_slot_pages_use_account_vocabulary_not_workspace`
  fails identically on untouched `main` (a 500 on the slot pages).
