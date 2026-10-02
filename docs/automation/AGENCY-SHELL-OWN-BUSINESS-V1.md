# Agency shell — the Agency owner keeps their own Business

Authority: V1-MASTER-PRODUCT-BLUEPRINT §7 (the Agency owner is operationally a
Core/Growth owner) and §28 (the Agency sidebar is *in addition to* the Agency's
own Business section). This supersedes the earlier behaviour where the Agency
**account frame** showed only Home / Client accounts / Prospecting / Settings and
the Agency owner appeared to have lost the Business OS.

No tenancy change. The two internal frames (Account frame, Business frame) and
View As are unchanged; only what the sidebar offers in each is.

## One shell, two groups, both frames

`CustomerContext::hasAgencyShell()` — the actor is in their **own** active Agency
account (never while viewing a client) with account-frame access. Then
`CustomerMenuBuilder::agencyShell()` renders:

```
BUSINESS                      (only when the Agency has exactly one enterable Business)
  Business Home
  Conversations · Contacts · Opportunities · Calendar · Forms · Automations
  Website · SEO · Packages & Products · Payments & Contracts · Results
  Business settings
AGENCY
  Agency Home
  Clients · Prospecting
  SaaS Plans · Agency Revenue · White Label      (agency authority; writes stay owner-only)
  Team                                            (owner / active Admin)
  Agency settings                                 (Stripe, plan & subscription, account details, Advanced…)
```

* Business entries come from `businessModuleItems()` — the **same** definition the
  plain Business frame uses, behind the same capability + `MenuEntitlements` gates.
  Nothing is force-shown; an unentitled module disappears from both frames.
* `CustomerShellComposer::currentMenuEntitlements()` now also resolves the
  entitlement snapshot for the Agency's own Business while in the Account frame
  (one bulk snapshot, the same as the Business frame).
* Which Home is current depends on the frame. The other is a *frame move*: a CSRF
  POST to the existing `customer.context.account.switch` /
  `customer.context.business.switch` (re-authorized server-side, as in the
  context switcher). `MenuItem` gained `header` and `post` for this; the
  horizontal shell skips both (its navbar switcher moves frames).
* With no enterable own Business (draft/none, or several), the Business group is
  omitted and the Agency group stands alone with the plain label "Home".
* Business modules link with the Agency Workspace + own-Business uids, so
  following one lands in the Business frame of the *own* Business, never a client.

## Context labels

Switcher frame label: `Agency account` (account frame) · `Your business` (own
Business) · `Client` (View As). The Business Home breadcrumb reads "Business
home" for the Agency's own Business (it said "Client account home").

## View As

Unchanged and tested: while viewing a client, `hasAgencyShell()` is false, so the
menu is the client's normal Business menu — no Agency group, no own-Business
entries, no frame moves, no link to the Agency's own Business.

## Platform Automations — V1 gap

Blueprint §28 lists "Platform Automations". There is **no** Agency-level
Platform Automations product surface in current code (no routes, controllers or
registry entry — only each Business's own Automations module), so no menu entry
was added and no empty page was created. This is a documented V1 gap, not to be
confused with the per-Business Automations module, which the Agency owner now
reaches for their own Business.

## Tests

`tests/Feature/Navigation/AgencyShellOwnBusinessTest.php` (owner both frames,
shared definition, entitlement denial, View As isolation, all-scope staff,
selected-scope staff, owner-only writes, Core/Growth unchanged, no-Business
fallback). Existing navigation tests that encoded "Agency account frame has no
Business entries" were updated to the §28 rule.
