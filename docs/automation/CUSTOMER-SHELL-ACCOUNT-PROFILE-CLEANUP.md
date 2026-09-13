# Customer shell, account and profile cleanup — implementation record

Route-3 manual lane from the logged-in product walkthrough. Branch
`agent/customer-shell-account-profile-cleanup`, from `origin/main` `9f40fd4`
(PR #280 merged). It follows the hierarchy the navigation redesign already
fixed (`AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md` §7.1:
Core and Growth see the account level "only as Settings → Account", with no
switcher account level) and invents no new domain.

## 1. Profile no longer crashes

**Cause.** `Customer::getNotifications()` returned whatever JSON was stored,
or `[]` when nothing was. Not every path that creates a customer stores
preferences — a customer created alongside a Business stores none — and the
Profile view indexes `['login']`, `['sender_id']`, … directly, so Profile
answered *Undefined array key "login"*. The same direct indexing is used by
the login notice and the sender ID and subscription notifications.

**Fix.** `Customer::DEFAULT_NOTIFICATIONS` holds the values every
account-creation path already stores, and `getNotifications()` always returns
every key: the stored choices merged over those defaults. A partial, empty or
unreadable stored set can no longer crash any reader, and a choice the customer
made still wins.

## 2. Core and Growth work inside their Business

`WorkspaceCandidate::hasAccountHome()` states when an account's own frame is a
destination rather than a hop: an **Agency** account (its portfolio), or an
account with **no Business to open yet** (its Home is "create your first
business", not a chooser).

- The context switcher lists an account only when `hasAccountHome()`. A Core
  or Growth customer sees their Business and **Account settings**
  (`customer.workspaces.show`), which is also Settings → Account; billing and
  plan stay under Settings.
- The resolver honours a remembered account-frame choice only when
  `hasAccountHome()`. A stale or hand-made account switch for a Core or Growth
  account falls straight through to its Business — no "Choose a business"
  page over a single row.
- Someone in several accounts still moves between them by their Businesses,
  each named by its account; an Agency account they belong to is still offered
  as an account. With several Businesses and nothing chosen, the chooser still
  asks — that ambiguity is real.
- Agency is unchanged: Agency Account Home → client Businesses → a client's
  Business Home, and back.

`SwitchAccountAction` and the account page keep their own authorization;
nothing is granted by the new rule.

## 3. User dropdown

Profile · Product updates · Sign out. Plan & subscription was already Settings →
Plan & subscription in both frames, and Team members moved to Settings → Team
(§5), so neither is repeated in the dropdown.

## 4. Product updates (read-only)

The platform owner stays the only publisher (admin Announcements, unchanged).
The customer surface reads the existing `announcements` / `announcements_user`
data — no second store:

- `user.account.announcement` lists the updates published **to this person**,
  newest first, unread ones marked "New"; no Actions menu, selection, search
  endpoint or management verbs.
- `user.account.announcement.view` resolves the uid **among this person's own
  updates** (404 otherwise) and marks it read on first open, keeping the first
  read time. Previously the route bound `{announcement}` by numeric id while
  every link passed the uid — so which row a link opened depended on MySQL's
  string-to-number conversion of the uid — and a customer who was not a
  recipient got a 500 instead of a 404.
- Removed from the customer side: `…announcement.search`, `…batch_action`,
  `…mark-as-read`, `…mark-all-as-read` (routes, actions and view-as
  classification entries). The old "mark all as read" looked up announcements
  the user had *authored* rather than received, so for a customer it marked
  nothing.

## 5. Team

> **Superseded** by `WALKTHROUGH-SETTINGS-NAVIGATION-CLEANUP.md` §4: Settings →
> Team is now the account membership (`customer.workspaces.team.show`), and the
> delegated-access surface below is no longer offered in the shell. §2's
> "Account settings" switcher link is likewise gone for Core and Growth.

Settings → **Team** (`customer.sub_accounts.index`, the delegated-access
surface its own pages call "Team members") in both frames. Offered only while
the platform allows customers to add team members, only to the account holder
— never to a team member, nor to a team member signed in as the account holder
— and never while viewing as a client. The routes keep `customer.sub_only`.

## 6. Footer

`AuthBrandPresenter::footerCopyrightLine(Request)` applies the brand precedence
that class already owns: an authorized Agency white-label brand for the request
host is the copyright holder with ordinary wording (the platform owner's
company and wording never appear under it); otherwise the platform owner's
configured footer. `BrandingPresenter::DEFAULT_COPYRIGHT_WORDING` ("All rights
reserved.") replaces a missing wording, so the line never ends at a bare period.
No brand is written into the product.

**The "© 2026 Keep Company. Keep Copyright" line was data, not code.**
`APP_FOOTER_COMPANY_NAME="Keep Company"` and `APP_FOOTER_COPYRIGHT_TEXT="Keep
Copyright"` (and often `APP_NAME="Test App"`) are values
`PlatformSettingsGeneralWriteTest` wrote into real `.env` files before tests
moved to a temporary environment file. Any environment still carrying them
should clear the two Appearance fields (Admin → Settings → Appearance) or the
`.env` keys; the footer then reads "© {year} {brand}. All rights reserved."

## 7. Avatar

`<x-user-avatar>` renders the photo only when one was uploaded and its file
exists; otherwise the person's initials (or a person icon) with no request at
all. If the photo request still fails, the initials replace it.
