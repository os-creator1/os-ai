# V1 Master Product Blueprint — AI Business OS

**Status:** Authoritative V1 product specification. Documentation only — this
document does not implement, migrate, or test anything.

**Source precedence (highest first):**
1. [`docs/rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md`](../rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md) ("the Addendum") — merged to `main` via PR #304.
2. Newer explicit product corrections / customer-experience contracts on `main`, principally `docs/automation/AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md` ("the Navigation Contract") and `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`.
3. RFC-003/RFC-004/RFC-005 sections **not** superseded by the Addendum.
4. Other current product/navigation/UX contracts.
5. Existing implementation, as evidence of current state only — never as authority over the above.

Where a source conflicts with a higher one, the higher one controls. Every
such conflict found while writing this document is resolved explicitly in
§35 and inline where it changes reader-facing meaning; current code is never
silently treated as the target architecture.

This document uses **MUST / MUST NOT / MAY** where behavior is locked, and
plain product language everywhere else.

---

## 1. Product Definition

AI Business OS is an operating system for local and service Businesses —
photographers, event vendors, and similar appointment- and lead-driven small
companies, starting with the **Photo Booth** niche as the first fully-built
vertical. It replaces the fragmented stack such a Business normally runs
(CRM, SMS/email marketing, website, local SEO, scheduling, proposals and
payments, often 5–8 separate paid tools) with one cohesive Business
workspace: leads and conversations, a website, local SEO, calendar and
booking, automated follow-up, proposals/contracts/payments, and a wallet that
funds the messaging and AI work behind all of it.

The product is sold in three tiers — **Core**, **Growth**, **Agency** (§21).
Core and Growth are the same product for a single Business owner at
different capability depth. Agency is a distinct role: an operator who runs
their own Business *and* sells and manages the same product, white-labeled,
to a portfolio of their own SaaS clients.

**V1 boundaries, stated once here and not repeated:** V1 is a single-website,
single-Stripe-account, single-wallet-per-Business product; every operational
record resolves to exactly one Location; Agency clients are fully separate
tenants the Agency manages, not sub-accounts living inside the Agency's own
tenant. What is explicitly deferred to V2 is enumerated in §34 and must not
silently reappear inside a V1 section.

## 2. Product Actors

| Actor | What they conceptually reach |
|---|---|
| **Platform Owner** | The software operator. Reaches every account, every Workspace, platform-wide configuration, billing/revenue, and support tooling (§30). Never a customer's financial consent authority (Addendum §10). |
| **Agency Workspace Owner** | Owns the Agency's own Workspace/Business (operationally identical to a Core/Growth owner, §3) *plus* the Agency product surface (§28): managing every Client Workspace their Agency has an active management relationship with (Addendum §2), Outreach, SaaS Plans, White Label, Agency team. |
| **Agency team member** | An ordinary member (Admin or Staff, §26) of the Agency's own Workspace. Reaches the Agency management surface (§28) — Clients list, opening a linked Client Workspace's management surfaces, View As/support access, Outreach, ordinary non-financial client management — to the extent their own Agency-Workspace role/permissions allow, gated on an active Agency↔Client relationship (Addendum §2). Three actions stay owner-only regardless of Agency-team permissions: AgencyRebill financial consent/payer selection/funding configuration (Addendum §10), terminating the Agency↔Client relationship (Addendum §2), and any other action an authoritative financial/ownership rule explicitly reserves to the owner. This authority is never inferred merely from ordinary *membership in the Client Workspace itself* (Addendum §2) — it flows only from the Agency-side relationship and the actor's own Agency-Workspace role. |
| **Business Workspace Owner** | Owns one Workspace containing exactly one Business (Addendum §1). Full authority over that Business: all Locations, staff, billing, integrations. |
| **Business Staff** | A member of a Business's Workspace, scoped to one, several, or all Locations (§26, Addendum §4). Never manages staff/permissions or billing in V1 (§26). |
| **Agency SaaS Client** | The owner (and their staff) of a Client Workspace — a Business Workspace Owner/Staff in every operational respect, whose Workspace additionally has an active managing-Agency relationship (Addendum §2) that grants the Agency View As, support, and (if configured) usage-payer authority. The client cannot remove that relationship themselves. |
| **End customer / lead** | Never a product user. Reaches only customer-facing surfaces: the Business's website, booking pages, payment/signature links, and inbound/outbound SMS or email. |

## 3. Canonical Hierarchy

```
User (global identity, §3, §11)
  └── Workspace (hidden tenant boundary)
        └── Business (exactly one, V1 hard rule — Addendum §1)
              └── Location (one or many)
```

**V1 hard rule: 1 Workspace = exactly 1 Business** (Addendum §1). This
applies uniformly to Core, Growth, and Agency's own operation:

```
Core/Growth:      Workspace → Business → Locations
Agency (own ops): Agency Workspace → Agency Business → Locations
```

Every Agency SaaS client gets its **own** hidden Client Workspace, never a
Business living inside the Agency's own Workspace:

```
Agency Workspace ──manages──> Client Workspace → Client Business → Client Locations
```

The Agency↔Client relationship is a canonical, explicit management link
(Addendum §2) — not ordinary Workspace membership, not a Business row under
the Agency's `workspace_id`. A Client Workspace has zero or one active
managing Agency; no multi-agency co-management in V1.

**Global User identity, isolated authorization** (Addendum §3): one login
may belong to multiple Workspaces (e.g. an Agency owner who is also staff on
a separate Business). Identity and 2FA (§26) belong to the User globally.
Authorization — permissions, Location access, billing authority, payer
authority, integrations — belongs strictly to the active Workspace context
and never leaks or inherits across Workspaces.

## 4. Location Model

A Location is a physical location or defined service area belonging to one
Business. Every Business gets a **Primary Location** automatically at
signup (§6); additional Locations are added as the Business grows.

Location states: **Active** (fully operational), **Archived** (retired,
history preserved, §33), **Locked** (over the plan's Location limit after a
downgrade — read-only, history intact, §6).

Location-specific operating data: staff access grants (§26, Addendum §4),
website pages (§13), Google Business Profile (§15), one primary phone number
plus extras (§19), calendar availability for staff assigned there (§12),
package availability and price overrides (§17), and all operational
reporting attribution (§9–§16 each name this explicitly rather than
repeating it here).

**Downgrade behavior** (Addendum §6, restated for product clarity): a plan
downgrade that leaves more Locations than the new plan allows **MUST NOT**
delete or silently archive any Location. The owner chooses which permitted
Locations stay operational; the rest become locked/read-only, with history
still visible in reporting. An upgrade, or an explicit archive action by the
owner, resolves the over-limit state.

## 5. Business-Wide vs Location-Bound Matrix

| Business-wide (one canonical copy, Locations consume it) | Location-bound (exists once per Location) |
|---|---|
| Pipeline definitions (§9) | Contact (§10) |
| Custom fields | Opportunity (§9) |
| Tags | Conversation (§11) |
| Templates | Appointment (§12) |
| Form/questionnaire definitions (§16) | Form Submission (§16) |
| Automation definitions (§13) | Message (§11) |
| Package/Product catalog (§17) | Automation Run (§13) |
| Website project (§14) | Phone number (§19) |
| Connected Stripe account (§12, §18) | Google Business Profile (§15) |
| Search Console property (§15) | Operational staff assignment (§26) |
| Usage wallet, billing, payer (§20) | Usage-ledger attribution (§20) |

The rule, stated once: **definitions and configuration are Business-wide;
the operational records they produce are Location-bound.** An Automation
*definition* lives once per Business and declares its execution scope
(All / Selected / One Location — §13); each *run* it produces still resolves
to exactly one Location for its whole execution (Addendum §5).

## 6. Signup and Provisioning

Canonical V1 flow:

```
Name / email / password
  → Business name
  → Niche (Photo Booth first)
  → Basic info (address, contact, timezone)
  → Plan selection (Core / Growth / Agency)
  → Payment method + trial start
  → Workspace + Business + Primary Location created together
  → Home
```

Signup **MUST NOT** force A2P registration or calendar/Google connection
during this flow — those are setup-checklist items surfaced on Home (§8)
once the account exists, not signup blockers.

Behind the single visible "create your account" step: a hidden Workspace is
created holding exactly one Business (Addendum §1) with its Primary Location
(§4); the niche selection installs that niche's **Blueprint** (§22),
filtered to what the chosen plan entitles (§21); a setup checklist is seeded
on Home from whatever the Blueprint and plan leave unconfigured (§8).

## 7. Global Navigation

Business app top-level (desktop):

```
Home · Opportunities · Contacts · Conversations · Calendar · Automations ·
Website · SEO · Forms · Packages & Products · Payments & Contracts · Settings
```

Top bar: notifications (Activity Center, §24), Global Search (§24), **+
Create** (§24), and a **Location switcher** when the Business has more than
one Location — hidden entirely for a single-Location Business rather than
shown disabled. Switching Location re-scopes every Location-bound list and
record on screen (§5) without changing Workspace/Business context, and
without itself granting any access — a staff member can only switch into a
Location they already hold a grant for (§26, Addendum §4). The switcher is
not a second tenancy layer beneath Workspace/Business: it changes which
already-authorized Location's data is in view, never what the viewer is
authorized to see in the first place.

There is no visible "CRM" wrapper or internal module branding anywhere in
this navigation — every label is the plain product noun a Business owner
already uses (Navigation Contract §3.2, §8.9).

## 8. Home

Every Home answers five questions in order, applied at whichever scope is
relevant to the actor (Navigation Contract §13.1):

1. **What needs attention?** — urgent exceptions only, non-empty sections
   suppressed entirely: no business phone, wallet balance low, an
   automation failing, a payment method expiring, the website still
   unpublished.
2. **What happened?** — recent, concrete events: messages, new contacts,
   automation runs, website publishes, meaningful SEO milestones (a ranking
   gained, a review received) — never a raw analytics wall of counts without
   interpretation.
3. **What should I do next?** — exactly one recommendation, drawn from the
   setup checklist while it's incomplete, then from the highest-value
   unconfigured capability once setup is done. The setup checklist itself
   disappears from Home once complete — it is not a permanent module.
4. **How is the Business performing?** — a small number of interpreted
   measures (new contacts, conversations started, messages sent, automation
   runs), each with a period comparison and a plain-language verdict.
5. **What is costing money?** — wallet balance, spend this period, projected
   days remaining, one action (Add funds). This card **MUST NOT** appear
   when nothing needs action (Navigation Contract §13.2) — Home is not a
   routine billing dashboard.

An **Ask COO** entry point (§23) sits on Home for any actor with access to
it, offering to explain, recommend, or draft against exactly what Home is
already showing.

Agency Home and Platform Owner Home are distinct surfaces built on the same
five-question shape, scoped to their own actor — see §28 and §30
respectively rather than repeating the shape here.

## 9. Opportunities

Photo Booth default pipeline (Blueprint-installed, §22, Business-wide
definition per §5):

```
New Lead → Auto Follow-Up → In Contact → Proposal Sent → Invoice Sent →
Questionnaire Sent → Questionnaire Submitted → Done
```

plus a separate **Lost** state reachable from any stage. **Booked** is not a
pipeline stage — it is a separate state/badge an Opportunity carries once a
qualifying Appointment (§12) exists, independent of which pipeline stage the
Opportunity is in.

An Opportunity carries a **value** (the deal's expected worth) and its
**payment state** as two independent facts — payment progress (§18) never
silently advances or reinterprets the pipeline stage.

Pipeline **definitions** are Business-wide and customizable per Business
(Growth+ gets fuller customization depth, §21); every individual Opportunity
is Location-bound (§5, Addendum §5) and belongs to exactly one Location at
creation. Cross-Location transfer of an Opportunity **MUST** preserve its
historical record rather than rewriting prior Location ownership (Addendum
§5) — a transfer is a new fact appended to history, not a mutation of the
old one. Stage changes and the automation events they fire (§13) are scoped
to the Opportunity's own Location throughout its life, including after a
transfer, per the same rule.

## 10. Contacts

A Contact belongs to exactly one Location (Addendum §5). The same real
person **MAY** exist as separate Contact records in different Locations of
the same Business — V1 does not merge person-identity across Locations
(§34, cross-Location identity merge is V2). Deduplication is **Location-local
only**: automatic dedup candidates and manual merge both operate within one
Location's Contact set.

Core capabilities: CSV import, saved filters, tags, custom fields
(Business-wide definitions, §5), file attachments, direct Opportunity link,
bulk actions (tag, assign, export), staff assignment, and round-robin
assignment across the Location's available staff.

## 11. Conversations

A three-column desktop layout (list · thread · context) covers SMS, email,
and system activity in one Location-scoped thread per Contact (Addendum
§5). The Contact's own Location determines the conversation's default
outbound number (§19) — an override is possible per message but never
silently changes the Contact's Location.

Every thread supports internal notes, delivery-status indicators, and quick
actions (mark done, create Opportunity, book appointment). Broadcasts
(bulk outbound to a filtered Contact list) run through the same STOP/DND and
wallet safeguards as any other outbound message (§19, §20). A Contact that
has a **booked** Appointment or has texted **STOP** carries a visible
safeguard badge that blocks accidental broadcast inclusion.

## 12. Calendar

Covers **Calendar** (the day/week view), **Booking Types** (what can be
booked and for how long), and **Availability** (who is bookable, when).
Every booking type and calendar entry belongs to one Location; staff
availability is set per staff member and constrained to the Location(s)
they're granted (§26, Addendum §4).

Each staff member connects their own Google or Outlook calendar once,
globally to their User identity — not once per Workspace — and that
connection's busy/free data is consulted for every Location they're
scheduled into, so cross-Location conflicts are prevented by construction
rather than checked after the fact. Round-robin distributes bookings across
available staff at a Location. An appointment's lifecycle is Scheduled →
Reschedule/Cancel are explicit actions → Completed or No-show, each firing
the corresponding automation event (§13). A public scheduler page is
reachable from the website (§14) and from links sent in Conversations
(§11).

## 13. Automations

A visual When/Then builder, with an automation **definition** owned
Business-wide (§5) declaring its own execution scope: **All Locations**,
**Selected Locations**, or **One Location**. Every individual **run** that
definition produces still binds to exactly one Location for its entire
execution (Addendum §5) — the definition's scope decides *which* Locations
may trigger it, never changes the one-Location-per-run rule.

Runs are triggered by events, evaluate conditions, and execute actions
(send message, update field, create task, webhook, etc.), with automatic
retry on transient failure and a visible run history per definition. Any
run step with a paid side effect (an outbound message, primarily) **MUST**
recheck wallet/cap/entitlement/STOP-DND/provider-readiness immediately
before executing that step (§20) — a run queued when funds were sufficient
is not assumed still fundable when it actually executes. Every paid
execution step is idempotent against retries. A transferred Opportunity's
in-flight automation runs continue against their original Location per §9.

The Photo Booth Blueprint (§22) installs a starter set of automations
(e.g. new-lead auto-reply, proposal-sent follow-up) at the depth the
guided-recipe product allows to ship — see the Navigation Contract §11 for
the exact recipe-readiness classification governing which recipes are
guided-catalogue-visible versus custom-builder-only at any given time; this
document does not restate or re-derive that classification.

## 14. Website

**V1: one Business = one primary website** (Addendum §13). Built from
niche-specific templates (Photo Booth first), with AI-assisted generation
that autofills from the Business's own profile and Package/Product catalog
(§17) plus a short set of niche questions asked once during setup.

Each Location gets its own page inside that one website (not a separate
site — §34, per-Location sites are V2). Website pages carry SEO structure
and schema markup (§15). Every operational lead the website produces
**MUST** resolve a deterministic Location before any Location-bound record
(Contact, Opportunity) is created from it (Addendum §5, §13) — V1 does this
via the page/form the lead came from (§16), never by guessing from IP or
GPS (Addendum §13).

The editor supports draft → preview → publish with autosave, and a
one-click restore-to-last-published action. Domain connection (custom
domain or subdomain) and basic analytics are part of the V1 website module.
V1 is template-and-niche-question driven, not an arbitrary fully-free
AI website builder (§34).

## 15. SEO

Covers **Overview**, **Keywords**, **Google Business Profile**, **Website
SEO**, **Citations**, and **Reviews**. Search Console integration is
Business-level (one property, matching the one-website rule in §14); Google
Business Profile is Location-level, since each Location is a distinct
physical/service-area presence with its own GBP listing. Full local-SEO
depth (citations, review management workflows, technical SEO detail) is a
**Growth** capability; Core sees basic SEO/Ads visibility only (§21).

## 16. Forms and Questionnaires

Form and questionnaire **definitions** are Business-wide (§5); every
**submission** is Location-scoped (Addendum §5). A submission is exactly
where deterministic Location resolution for website leads happens in
practice (§14) — the form itself carries or asks for the Location, and that
Location is what the resulting Contact/Opportunity inherits.

A submission can create or update a Contact, create or update an
Opportunity, and trigger automations (§13) at the resolved Location.
Questionnaires support multi-page flows (used by the Photo Booth pipeline's
"Questionnaire Sent/Submitted" stages, §9).

## 17. Packages & Products

One canonical, Business-wide Package/Product catalog (Addendum §14). Each
Location may enable or disable a given package and may set an optional
price override for that Location — the canonical catalog itself is never
duplicated per Location. Every proposal, invoice, or booking that
references a package **MUST** store an immutable snapshot of the exact
package/price actually used (Addendum §14) — later catalog or price changes
never retroactively alter a past transactional document.

## 18. Payments & Contracts

Covers Proposal → Contract (with e-signature) → Invoice, payment links,
deposit-plus-balance collection, automated reminders, expiration, and
refunds — all through the Business's own connected Stripe account
(Addendum §12, one per Business in V1), with every transactional document
carrying Location attribution (§17) and immutable package/price snapshots.
The document lifecycle (draft → sent → signed/paid → expired/void) is
tracked per document; access to an unsigned/unpaid document is via a secure,
non-guessable link. A dedicated client-facing portal is **V2/low priority**
for V1 (§34) — the secure per-document link is the V1 mechanism.

## 19. Messaging and Phone Numbers

Central provider: Telnyx (Addendum §15, mechanism not restated here — see
the Navigation Contract §10 for the exact customer-facing setup flow and
copy, which this document does not repeat). A messaging number belongs to
exactly one Location; a Location may have one primary number plus extras.
Inbound routing: the receiving number determines which Location's
Conversation thread the message lands in. Outbound: defaults to the
Contact's own Location's primary number, overridable per message (§11).
A2P/10DLC business verification is Business-level, not per-number. STOP/DND
is enforced platform-wide, checked before every outbound send regardless of
entry point (broadcast, automation, manual). No shared one-number/multiple-
Location routing in V1 (§34). Every outbound message is a wallet-checked
paid side effect (§20).

## 20. Usage Wallet and Paid Side Effects

One usage wallet per Business (Addendum §9); balance, monthly cap,
auto-recharge policy, payer, and billing method are all Business-wide, never
per-Location — every usage-ledger *entry*, however, carries Location
attribution for reporting (§5, §9, §20). The wallet **MUST NEVER** go
negative; funding, cap, entitlement, and provider-readiness are rechecked
immediately before every paid provider side effect, never assumed valid
from an earlier check (Addendum §9, §13, §19).

A Client Workspace's wallet may be **client-paid** or **Agency-paid**
(`PayerType::AgencyRebill`) — see Addendum §10 for the complete authority,
consent, and standing-consent rules governing AgencyRebill; this document
does not restate them. In short: only the managing Agency Workspace's owner
may establish, configure, or revoke AgencyRebill for a given client, every
automated Agency-funded effect still passes every normal wallet/cap/
entitlement/STOP-DND/provider-readiness/idempotency check, and revoking
consent blocks only *new* Agency-funded activity — already-incurred costs
stay ledgered and the client needs a valid client-paid payer before new
paid activity resumes.

## 21. Plan Model

| | **Core** | **Growth** | **Agency** |
|---|---|---|---|
| Home, Opportunities, Contacts, Conversations, Calendar | ✓ | ✓ | ✓ |
| Forms/Questionnaires, Packages & Products, Payments & Contracts | ✓ | ✓ | ✓ |
| Website (one Business website) | ✓ | ✓ | ✓ |
| Useful basic Automations, messaging integrations | ✓ | ✓ | ✓ |
| Basic AI COO | ✓ | ✓ | ✓ |
| Basic SEO/Ads visibility | ✓ | ✓ | ✓ |
| Full SEO (rankings, reviews, citations, technical SEO) | | ✓ | ✓ |
| Richer automation/outcome functionality | | ✓ | ✓ |
| Full Photo Booth growth blueprint depth | | ✓ | ✓ |
| Clients (Client Workspace management) | | | ✓ |
| Outreach (§29) | | | ✓ |
| SaaS Plans (reselling the product) | | | ✓ |
| White Label | | | ✓ |
| Agency team / Agency-wide surfaces | | | ✓ |
| Client View As, client management | | | ✓ |

This is a capability matrix, not a price list — exact Core/Growth/Agency
pricing is **commercial configuration**, not architecture, and is
deliberately not stated here (§35 lists this as unfrozen where it would
otherwise need to be). Location capacity/pricing rules follow only the
already-locked rules in Addendum §6 and §1 (no Nth-Business slot model);
where an exact current price point is not itself architecture-locked, it is
configuration the business side sets independently of this document.

## 22. Niche Blueprint System

```
Platform Template Library → Niche Blueprint → entitlement-filtered
installation → Business-owned copy
```

Photo Booth is the first fully built niche. A Blueprint bundles: the
default pipeline (§9), form/questionnaire definitions (§16), starter
automations (§13), proposal/contract templates (§18), booking defaults
(§12), review-request flows (§15), SEO defaults (§15), website questions
and content logic (§14), package structures (§17), and custom field
definitions (§5).

Installing a Blueprint gives the Business its **own copy**, filtered to
what its plan entitles (§21) — not a live link back to the platform
template. Every installed Blueprint carries version/provenance metadata.
An update to the platform Blueprint **MUST NOT** silently update or
reactivate components inside an already-installed Business — an upgrade
only **surfaces** newly entitled components for the owner to explicitly add
(Addendum §16). A brand-new account **MAY** receive everything its chosen
plan currently permits at initial installation.

## 23. AI COO

An embedded assistant anchored on Home (§8) and reachable elsewhere in
context. It explains what it's looking at, recommends a next action,
drafts content (messages, automation copy, proposal text), and estimates
cost before any paid action. It surfaces risks (wallet running low, an
automation failing, a stalled Opportunity) proactively rather than only on
request.

The AI COO **MUST NOT** execute a consequential or externally-visible
action (sending a message, changing a live automation, spending wallet
funds) without explicit user approval — it drafts and recommends, the
human confirms. It respects the same budgets, caps, permissions, and
Location scope as the human it's assisting (§20, §26); every approved
action it takes is logged like any other actor's action (§32). The same
mechanism serves three contexts at different scope: a Business owner/staff
member, an Agency owner (scoped to Agency operations and, through View As,
one client at a time), and the Platform Owner (scoped to platform
operations) — one component, three authorization contexts, never three
implementations.

## 24. Notifications / Search / + Create

**Activity Center**: the notification inbox — mentions, assignments,
automation failures, payment events, and system alerts, filtered to what
the viewing actor is permitted to see and, for Location-bound events, to
the Locations they're granted (§26).

**Global Search** searches across Contacts, Opportunities, Conversations,
and documents, with the same permission and Location filtering applied
before results are returned — search never reveals a record the searching
actor could not otherwise open.

**+ Create** offers the common creation shortcuts (new Contact, new
Opportunity, new Appointment, new Broadcast) from anywhere in the app,
defaulting to the currently selected Location (§7).

## 25. Settings

Business Settings: **Business Info**, **Team & Permissions** (§26),
**Billing** (§20), **Phone Numbers** (§19), **Integrations**, **Domains**
(§14), **Locations** (§4), **Custom Fields** (§5), **Personal Profile &
Security** (§26, 2FA), **Templates**.

Business Info, Team & Permissions, Billing, Templates, Custom Fields, and
Integrations are Business-wide (§5). Locations is the one screen that
*manages* Location-scoped entities but is itself reached from Business
Settings (Navigation Contract §3.3 — a Location is configured from Business
Settings, never treated as its own navigation tenant). Personal Profile &
Security is scoped to the individual User, not the Business, consistent
with global User identity (§3).

## 26. Staff and Permissions

Roles: **Owner** (implicit via Workspace ownership, always has every
Location and every permission) and **Staff**, whose access is composed of
two independent axes:

1. **Feature permissions** — what a staff member may *do* (view Contacts,
   send messages, manage Opportunities, etc.), Business-wide in scope.
2. **`location_access_scope` = All | Selected`** (Addendum §4) — *which*
   Locations' operational records a staff member's granted feature
   permissions actually apply to, via the canonical equivalent of a
   `workspace_membership_locations` grant. A staff member with "Selected"
   scope and no grant for a given Location gets no access to that
   Location's records regardless of their feature permissions — knowing a
   record's ID never bypasses this (Addendum §4).

V1 has no generic "Business Admin" role distinct from Owner/Staff — this
matches Addendum §4's authority model, which is deliberately narrower than
"the Agency team generally has full access" in the one place it governs
money (Addendum §10). **Ordinary staff cannot manage staff or permissions
in V1** — that remains an owner-only action.

**2FA** (Addendum §11): belongs globally to the User identity, not per
Workspace. A User who owns any Workspace whose owner role requires 2FA
must enable it globally to satisfy that requirement; Workspace
authorization itself stays independent of this. 2FA is never automatically
disabled if the person later stops being an owner.

## 27. Account Lifecycle

Canonical lifecycle (Addendum §7, `CustomerAccountAccessResolver` as sole
authority): **Trial → Active → Grace → Locked → Inactive**, with
**Suspended** available separately as an exceptional/manual/compliance
state — never a redefinition of Grace or Locked.

Product behavior: renewal failure starts a **3-day Grace** period (full
access, with a clear billing prompt), then **Locked** (read-only/blocked
paid access, data intact, immediate unlock the moment payment is
confirmed). **Inactive** data remains recoverable for **six months** per
the product retention model (§33 — this period is a product decision here,
not a legal-sufficiency claim). An upgrade takes effect immediately; a
downgrade takes effect at the current billing period's end. Losing
entitlement to a feature on downgrade follows §16/§21 — components are
never silently deleted, only no longer active/addable until re-entitled. A
manual or promotional wallet credit (§20) never bypasses plan-level access
— it funds usage, it does not unlock a Locked account.

## 28. Agency Product

Agency sidebar (in addition to the Agency's own Business operational
section, §7 — the Agency owner is a Business owner too, §3):

```
Agency Home · Clients · Outreach (§29) · SaaS Plans · Platform Automations ·
Team · White Label · Settings
```

**Agency Home** answers the same five questions as §8, scoped across every
managed Client Workspace: attention items grouped by client (no phone, low
balance, failing automations, an unpaid Agency-level invoice), the Client
Workspace list itself (name, status, balance, last activity, a View As
entry point), active Outreach campaigns and replies awaiting a human,
aggregate Agency performance (clients gained/lost, aggregate messages/
spend), and Agency billing (plan, client capacity used, next invoice) —
adapted from the Navigation Contract §13.3's Home shape to the corrected
V1 model: what that contract calls "client accounts" are, under this
Blueprint, full **Client Workspaces** (§35), not Business rows inside the
Agency's own Workspace.

**Clients** manages the Agency↔Client Workspace relationship set (Addendum
§2): provisioning a new client (creating their Client Workspace + Business
+ Primary Location and establishing the management relationship in one
flow), viewing a relationship, and the entry point for **View As** and
ordinary support/client-management actions. Any active Agency team member
(Admin or Staff of that Agency Workspace, §2, §26, by membership alone — no
additional Agency-management permission) — not only the owner — may open the
Clients list, view a linked client, View As it, and perform ordinary
non-financial client management, gated on the relationship being active;
every action is attributed to the real acting Agency user, never the
viewed client's identity. **Ending** a relationship is narrower — only the
Agency owner or Platform Owner may end one; the client cannot. View As
itself preserves the viewed client's normal tenancy, security, wallet, and
STOP/DND rules exactly as if the client were using it themselves (§32).

Also from here: **SaaS Plans** (the Agency's own resale plan
configuration for its clients, billed through the Agency's connected
Stripe — money lane C, Addendum §12), Agency-paid vs client-paid wallet
configuration per client (§20, Addendum §10), **White Label** (Agency
branding applied to the client-facing product), and **Team** (the Agency's
own staff, §26).

**Client lifecycle isolation and Agency delinquency composition** follow
Addendum §8 exactly and are not restated here: one client's non-payment
never affects another client or the Agency's own lifecycle; the Agency's
own platform-subscription lapse composes as an upstream access
prerequisite for its managed clients without ever overwriting any client's
own independent lifecycle state.

## 29. Agency Outreach

A campaign-centric prospecting workflow, separate from any individual
client's own Conversations (§11) — Outreach targets prospective new
clients, not existing customers' leads. Covers: a prospect list, a
message sequence with defined timing, canned Q&A responses with an AI
fallback for anything outside the canned set, explicit stop conditions
(reply received, opted out, booked), and handling once a prospect books a
meeting (handoff out of the automated sequence). Campaign enrollment is
always an explicit action — no prospect is auto-enrolled from a passive
list. Analytics cover sequence performance (reply rate, booked-meeting
rate) per campaign. Outreach sends obey the same STOP/DND and messaging
safety rules as every other outbound channel in the product (§19) — there
is no separate, weaker safety path for prospecting.

## 30. Platform Owner

Sidebar:

```
Home · Accounts / Workspaces · Niche Blueprints · Template Library ·
Platform Automations · Messaging / Numbers · Billing / Revenue ·
Support / Privacy Requests · Audit Logs · Settings
```

Platform Owner Home follows the same five-question shape (§8) at platform
scope: system/queue health, commercial summary (accounts by tier, MRR,
trial conversion, churn), operational risk (negative-balance accounts,
funding failures, active entitlement overrides), and recent platform
activity (signups, plan changes, cancellations) — per the Navigation
Contract §13.5, this surface shares no component state with the customer
shell.

**Accounts / Workspaces**: account administration and support actions
(lifecycle overrides, credits) — never a substitute for a customer's own
financial consent (§27, Addendum §10). **Niche Blueprints** and **Template
Library** manage the platform-level Blueprint catalog (§22) and its
versioning. **Platform Automations** are platform-internal, distinct from
per-Business automations (§13). **Messaging / Numbers** manages the
central provider relationship and number inventory (§19). **Billing /
Revenue** gives the Platform Owner separate visibility/accounting across
all four money lanes (Addendum §12) — platform-side margin on lane A, and
operational/reporting visibility into lanes B/C/D where the platform's own
infrastructure or provider relationship is involved — without ever
conflating them: lane B revenue belongs to the Business, lane C revenue
belongs to the Agency, and lane D funds Business usage, none of it
platform revenue merely because the Platform Owner can see it.
**Support / Privacy Requests** and
**Audit Logs** are the support and compliance surfaces (§32, §33). The
Platform Owner administers and supports the platform; they are never
themselves a payer or financial-consent authority for any customer or
Agency account (Addendum §10).

## 31. Domain Events

Modules connect through a canonical event envelope rather than direct
cross-module calls wherever a canonical event already exists. Every event
carries: **Workspace**, **Business**, **Location** (where applicable),
**Contact/Opportunity** (where applicable), **actor**, **source**,
**timestamp**, a stable **event ID**, an **idempotency identity**, and a
**version**. Automations (§13), the Activity Center (§24), and reporting
all consume this same envelope rather than each module inventing its own
shape. Where a canonical event exists for a transition, a module **MUST
NOT** instead reach directly into another module's tables/services — that
is exactly the spaghetti coupling the envelope exists to prevent.

## 32. Security and Audit

Tenancy is Workspace-isolated (§3); within a Workspace, staff access is
further scoped by Location ACL (§26, Addendum §4), built on a fail-closed,
re-derive-from-authoritative-data pattern that never trusts a route-bound
ID to imply access. **View As** (§28) preserves the viewed client's real
tenancy, security, wallet, and STOP/DND rules unchanged — it is a lens on
the real account, never a privileged bypass — and every action taken
during a View As session is audited under the real, acting person's
identity, not the viewed identity. **2FA** (§26) is required for
Business/Workspace owners and Agency owners. **Payer authority** for
wallet-affecting actions follows §20/Addendum §10 exactly — no other
authority substitutes for it. High-value or immutable state transitions
(ownership transfer, Agency relationship termination, payer changes) are
durably audited with actor, timestamp, and reason. Raw provider
credentials and secrets are never exposed to customers (§19, Navigation
Contract §10.2). STOP/DND is enforced platform-wide before every outbound
send (§19).

## 33. Data Retention / Account Recovery

Inactive/cancelled accounts remain recoverable for **six months** as a
product decision (§27) — this document does not claim that period is
legally mandated or has received a legal-sufficiency review; where a
future legal/compliance review changes it, that is a configuration update,
not a re-opening of the product decision itself. Phone-number
release/port-out follows its own separate lifecycle (§19, Addendum §7) and
is not tied to the six-month window. An archived Location (§4, §6)
preserves its historical/audit/reporting data rather than deleting it.
Anonymization/deletion requests and other support/privacy requests are
handled through the Platform Owner's dedicated surface (§30), not ad hoc.

## 34. V1 vs V2

| V1 (this Blueprint) | Explicitly V2 |
|---|---|
| Text/typed interaction with AI COO | Browser voice interaction |
| In-app product surfaces only | Public API / webhooks / developer docs |
| Secure per-document links (§18) | Richer dedicated client portal |
| — | Tasks/reminders as a standalone module |
| Current visual system | Selectable themes |
| Basic analytics (§14) | Heatmaps / session replay |
| Simple performance measures (§8) | Multitouch attribution |
| Deposit + balance (§18) | Complex installment plans |
| Owner/Staff + Location ACL (§26) | Deeper, more granular Agency permission tiers |
| Basic SEO/Ads visibility, full SEO on Growth+ (§15, §21) | Advanced Ads management |
| Template + niche-question website (§14) | Arbitrary fully-free AI website design |
| Location-local dedup only (§10) | Cross-Location person identity/merge |
| One website per Business (§14) | Separate website per Location |
| One Stripe account per Business (§18) | Per-Location Stripe accounts |
| One number per Location (§19) | Shared SMS number routed across multiple Locations |
| — | Travel-time/territory-aware routing |
| One wallet per Business (§20) | Per-Location wallet |

No item on the left may be silently narrowed into the right without an
explicit product-owner decision, and no item on the right may be quietly
built into V1 (Addendum §19).

## 35. Known Migration Conditions

These are **current-implementation mismatches to migrate**, not open
product questions — they do not reopen anything frozen by the Addendum
(§0). Full technical detail lives in the Addendum and the prior
architecture audit; this section states the product-facing correction
only.

- **Multi-Business Workspace model.** Current `main` still permits many
  Businesses under one Workspace (no DB unique constraint on
  `businesses.workspace_id`, `WorkspaceManager::createBusinessInWorkspace()`/
  `reassignBusiness()`). The product is 1 Workspace = 1 Business (§3,
  Addendum §1); this is a data/schema migration condition, not a live
  option.
- **Agency clients modeled as Businesses inside the Agency's own
  Workspace.** This is the single largest correction versus the existing
  **Navigation Contract §3.1–§3.2**, which currently defines "Agency
  customers have unlimited Businesses" and names "Client account" as the
  customer-facing term for a Business under the Agency's Workspace. Under
  this Blueprint, **"Client account" is the correct customer-facing term,
  but it now names an entire Client Workspace** (§3, §28), not a Business
  row inside the Agency's Workspace. The Navigation Contract's UI copy and
  mental model survive; its underlying tenancy mapping does not — this
  Blueprint's §28 and Addendum §2 control.
- **"A Location is not... an authorization boundary."** The Navigation
  Contract §3.3 states this as a locked invariant, written before Location
  staff ACL existed as a product decision. Addendum §4 and this Blueprint's
  §4/§26 now make Location exactly that for staff access scoping — the
  Navigation Contract's narrower claim ("not a navigation *tenant*", i.e.
  never a top-level switchable context like Workspace/Business) remains
  true and is preserved in §7/§25; its broader claim ("not an authorization
  boundary" at all) is superseded by Addendum §4 and must not be read as
  still governing.
- **Same-Workspace-only View As.** `ViewAsManager` currently resolves a
  viewed Business only from within the actor's own Workspace. §28/§32's
  cross-Workspace Agency View As requires the new authorization path
  Addendum §2 anticipates.
- **Business-scoped membership pivot.** `workspace_membership_businesses`
  scopes staff to a subset of a Workspace's Businesses — meaningless once a
  Workspace holds exactly one Business, and superseded by the Location ACL
  in §26/Addendum §4.
- **Additional Business slots.** `additional_business_slots`,
  `unlimited_business_slots`, and the associated purchase/renewal billing
  flow are a live, monetized feature on `main` today. §21's plan matrix
  has no Nth-Business capacity concept; this flow is a migration/retirement
  condition (Addendum §17/§18), not ongoing product direction.
- **`AgencyRebill` inert.** `PayerType::AgencyRebill` exists but is
  currently unactivated, with no defined consent rule in RFC-005 §16.
  §20/Addendum §10 authorize activating it only under the new consent
  rules stated there.

## 36. Implementation Principles

Implementation agents **MUST**: follow the frozen architecture (the
Addendum and this Blueprint) rather than the current shape of `main`;
preserve backward compatibility for existing customers throughout any
migration named in §35; fail closed on any tenancy, security, or billing
ambiguity; centralize account-access and entitlement decisions in the
existing single authorities (`CustomerAccountAccessResolver`,
`EntitlementManager`) rather than introducing a second one; recheck every
paid side effect immediately before execution (§20); use idempotency for
every paid or otherwise non-repeatable action; keep migrations reversible
where realistically possible; add focused adversarial tests for the exact
seam being changed; avoid unrelated refactors; and use sensible
implementation defaults for minor UX/copy/filter/presentation choices
rather than escalating them as new architecture questions.

## 37. Acceptance Definition for V1

This defines what "V1 product complete" means at the product level — not a
code-coverage or test-count number.

**A Business Owner can:** sign up and land on a working account within
minutes (§6); configure Locations (§4); receive and manage leads through
Opportunities and Contacts (§9, §10); message customers through
Conversations with a working business number (§11, §19); take and manage
bookings on a Calendar (§12); automate follow-up (§13); publish a website
(§14); manage local SEO (§15); collect deterministic-Location leads through
Forms (§16); sell from a Package catalog and collect signed, paid
agreements (§17, §18); manage staff and their Location access (§26); and
understand what's happening and what to do next through Home and the AI
COO (§8, §23).

**An Agency can:** operate its own Business exactly as any Core/Growth
owner (§3, §7–§20); create and manage independent Client Workspaces (§2,
§28); View As a client without breaking that client's own tenancy/security/
wallet rules (§28, §32); sell SaaS plans to its clients (§21, §28); run
Outreach campaigns to acquire new clients (§29); white-label the product
(§28); and choose, per client, whether usage is client-paid or Agency-paid
(§20, Addendum §10).

**A Platform Owner can:** operate and support the platform — manage
accounts and Workspaces, maintain the niche Blueprint/Template Library,
manage the central messaging/provider relationship, see separate
visibility/accounting across all four money lanes without conflating them,
handle support and privacy requests, and review audit logs (§30, §32,
§33).

---

## BLOCKING UNRESOLVED PRODUCT DECISIONS

**NONE.** Every area this Blueprint covers is resolved by the Addendum, an
authoritative product/customer-experience contract, or a stated
implementation default (§36). Exact Core/Growth/Agency pricing is left as
commercial configuration (§21) rather than a blocker, since it does not
constrain architecture. The Navigation Contract's "Client account = Business
in Agency Workspace" mapping is corrected in §35 rather than left as an open
question, since the Addendum already resolves it. Existing-holder treatment
for any already-paid `additional_business_slots` agreement (§21, §35) is a
**conditional migration/operations gate**, not an architecture blocker — the
retirement of that flow is already architecture-locked either way; only the
commercial treatment of a paid holder, if any exist when that migration
slice runs its preflight, needs a separate business decision at that time
(see `V1-IMPLEMENTATION-ROADMAP.md` Slice 11).
