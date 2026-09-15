# V1 Architecture Decision Addendum

**Status:** Locked. Architecture-level decision document, not an implementation
RFC and not the Master Product Blueprint.

**Scope:** This addendum records the V1 product-architecture decisions made
after the read-only Workspace/Business cardinality audit and the B1–B5
validation pass, and formally supersedes the specific portions of
RFC-003/RFC-004/RFC-005 that conflict with them. It authorizes no
implementation work of any kind.

## 0. Precedence rule

Where this addendum conflicts with RFC-003, RFC-004, or RFC-005 on a decision
it explicitly names below, **this addendum controls for V1**. Everything in
those RFCs not explicitly superseded here remains governed by its existing
text until the Master Product Blueprint, or a later approved RFC, changes it.
This addendum does not rewrite those RFCs' historical implementation or
migration context, which remains valid as a record of what M1–M6 actually
built.

The fact that current `main` still supports multiple Businesses per Workspace
(schema, `WorkspaceManager`, `EntitlementManager`, the customer-facing
Business switcher, and the `additional_business_slots` purchase flow) **is a
migration condition, not V1 product authority.** No implementation agent may
cite the current shape of `main` as evidence that multi-Business-per-Workspace
is the target architecture.

---

## 1. Tenant / Business / Location cardinality

Workspace **MUST** remain the hidden tenant/account boundary. Business
**MUST** remain the customer-visible company. Business **MUST** have many
Locations.

**V1 hard rule: 1 Workspace = exactly 1 Business.** Core/Growth: Workspace →
Business → Locations. Agency's own operation: Agency Workspace → Agency
Business → Locations. Every Agency SaaS/client Business **MUST** get its own
hidden Client Workspace: Agency Workspace → manages → Client Workspace →
Client Business → Client Locations.

Multiple unrelated Businesses inside one Workspace **MUST NOT** be treated as
a V1 product model, regardless of what current `main` still permits at the
schema level. DB-level 1:1 enforcement (a unique constraint on
`businesses.workspace_id`) **MUST** happen only after existing Agency
consumers and any existing multi-Business Workspace data have been migrated
(§18, Transition Order).

## 2. Agency ↔ Client Workspace relationship

This **MUST** be a canonical, explicit management relationship — never
inferred from ordinary Workspace membership. Cardinality: one Agency
Workspace manages N Client Workspaces; a Client Workspace has 0 or 1 active
managing Agency Workspace. No multi-agency co-management in V1.

The relationship is the canonical authorization link for: Agency client
management, View As, white-label/SaaS management, client support access,
Agency-assigned SaaS plan management, and Agency-paid usage
(`PayerType::AgencyRebill`).

The relationship record **MUST** identify at minimum: `agency_workspace_id`,
`client_workspace_id`, an explicit lifecycle/status, and created/ended
timestamps or equivalent audit history. Ending a relationship **MUST NOT**
hard-delete it — history **MUST** be preserved. A Client Workspace **MUST
NOT** be able to remove its own managing-Agency relationship; only the Agency
owner or the Platform Owner may terminate it, per lifecycle/authorization
rules to be defined at implementation time. Termination **MUST** immediately
remove new Agency management and AgencyRebill authority while leaving
historical audit/billing/usage intact. Agency authority **MUST NOT** be
inferred merely because an Agency user happens to be an ordinary member of the
Client Workspace.

## 3. Global User identity

One global User/login **MAY** participate in multiple Workspaces. Identity and
2FA belong to the User (§11). Authorization belongs to the active Workspace
context. Permissions, Location access, billing authority, payer authority,
and integrations **MUST NOT** leak or inherit across Workspaces.

## 4. Location staff ACL (B1)

Workspace membership represents membership in the one Business belonging to
that Workspace. Staff operational access **MUST** use
`location_access_scope = All | Selected` plus the canonical equivalent of a
`workspace_membership_locations` grant table. Business/Workspace owner
automatically has all Locations. Staff **MAY** access one, several, or all
Locations.

Every Location-bound operational record **MUST** authorize against that
record's own Location server-side. Knowing or binding a record ID **MUST
NEVER** bypass Location authorization. Location authorization **MUST NOT**
reuse Business-tenancy checks as though they were Location checks — it is a
sibling authority, built on the same fail-closed, re-derive-from-authoritative-
data pattern already used for Business tenancy (re-verify from the
authorization repository, never trust a route-bound model). Business-wide
feature permissions remain a separate concern from Location access. Agency
management authority remains separate from Client Workspace membership (§2).

## 5. Operational Location ownership

Every new operational record **MUST** belong to exactly one Location,
including at minimum: Contact, Opportunity, Conversation, Appointment, Form
Submission, Message, Automation Run, and operational staff assignment where
applicable. `location_id = null` **MUST** be treated only as a temporary
legacy migration/backfill state — never an intentional V1 operating mode.

Contacts belong to one Location; the same real person **MAY** have separate
Contact records in different Locations. An automation run **MUST** bind to one
Location for its entire execution. Cross-Location lead transfer **MUST**
preserve historical records rather than rewriting prior Location ownership.

## 6. Location downgrade / archive

On a plan downgrade that leaves more Locations than the plan allows, the
system **MUST NOT** delete Locations and **MUST NOT** silently archive them.
The owner chooses which permitted Locations remain operational; excess
Locations become operationally locked/read-only; historical data remains
available; an upgrade or an explicit archive action resolves the over-limit
state. An archived Location **MUST** preserve its historical/audit/reporting
data.

## 7. Account lifecycle (B2)

`CustomerAccountAccessResolver` **MUST** remain the single consumer-facing
account access authority. No second lifecycle/access resolver may be
introduced.

Canonical effective lifecycle: **Trial → Active → Grace → Locked → Inactive.**
`Suspended` remains available as a distinct exceptional/manual/compliance base
status and **MUST NOT** be secretly redefined to mean Grace or Locked.

Representation (validated against current `main`): `WorkspacePlanAssignment`
retains its existing base `status` column (`Active | Inactive | Suspended`,
`app/Enums/Entitlement/WorkspacePlanAssignmentStatus.php`) and gains canonical
lifecycle timestamps (e.g. `grace_started_at`, `locked_at`, and further
timestamps only where genuinely needed). `CustomerAccountAccessResolver`
**MUST** derive the effective Trial/Active/Grace/Locked/Inactive lifecycle
from that authoritative status plus timestamps as a pure, computed read — this
state **MUST NOT** be duplicated in any other authority.

Renewal failure: 3-day Grace, then Locked. Inactive/cancelled/non-paying data
remains recoverable for six months per the locked product retention model.
Phone-number port-out/release lifecycle remains a separate concern.

## 8. Agency/Client non-payment composition (B3)

Every Client Workspace **MUST** have its own independent lifecycle. Client A's
non-payment **MUST** affect Client A only — never the Agency Workspace's own
lifecycle, and never Client B/C/etc.

The Agency's own platform subscription is a separate, upstream entitlement.
If the Agency enters Grace, managed Client Workspaces **MUST** continue
normally. If the Agency becomes Locked, managed Client Workspaces **MUST**
lose effective paid platform access through the Agency management relationship
(§2) — but their own lifecycle state **MUST NOT** be overwritten or mutated.
Agency eligibility is composed as an upstream effective-access prerequisite
alongside the Client's own state. After Agency recovery, each Client resumes
only if its own independent lifecycle allows it.

`CustomerAccountAccessResolver` **MUST** remain the single authority that
composes these two inputs (§7) — this composition **MUST NOT** be implemented
as a second, parallel resolver.

## 9. Business usage wallet

1 Business = 1 usage wallet. Wallet balance, monthly cap, auto-top-up policy,
payer, and billing method are Business-wide; Locations **MUST NOT** get
separate wallets in V1. Every usage ledger entry **MUST** retain: Business,
Location, feature/provider, cost, applicable markup/charge, timestamp, and
related message/action/entity identifiers where applicable. The wallet
**MUST NEVER** go negative. Funding, cap, entitlement, and provider readiness
**MUST** be rechecked immediately before each paid provider side effect.

## 10. Agency-paid client usage — AgencyRebill (B4)

A Client Workspace **MAY** use client-paid usage, or Agency-paid usage through
`PayerType::AgencyRebill`. AgencyRebill changes the payer only — the Client
Business continues to own its usage ledger entries, provider/feature
attribution, Location attribution, and related action/message IDs.

`AgencyRebill` **MUST** exist only where there is an active Agency↔Client
Workspace management relationship (§2), and the payer assignment **MUST**
reference the specific managing Agency Workspace through that relationship —
an arbitrary Workspace **MUST NEVER** be selectable as Agency payer.

Only `Workspace.owner_user_id` of the managing Agency Workspace may: switch a
Client between client-paid and Agency-paid; establish or revoke AgencyRebill
consent; select or reconfigure the Agency funding instrument; or configure
Agency-side auto-recharge/funding policy for that client. Agency Admin, Agency
Staff, the Client Workspace's own owner/staff, and a Platform Administrator
acting merely by virtue of platform role **MUST NOT** perform any of these
actions — a Platform Owner may administer/repair the platform but **MUST
NOT** originate a customer's AgencyRebill financial consent. Every
payer/consent change **MUST** record actor, timestamp, Client Workspace,
managing Agency, old payer, new payer, and a mandatory audit reason.

**Standing consent:** once the Agency owner has explicitly consented and
configured funding policy, the system **MAY** automatically consume
Agency-funded wallet value, perform authorized auto-recharges, and pay
provider costs without per-message/per-top-up manual approval — reusing the
existing standing-consent mechanism (`auto_recharge_consented_at` /
`auto_recharge_consented_by_user_id`, or its canonical current equivalent).
These automated effects remain subject to: wallet balance, monthly cap,
auto-recharge policy, entitlement, STOP/DND, idempotency, provider readiness,
Client Workspace effective account access, managing Agency effective account
access (§8), and every existing paid-side-effect guard.

Revoking AgencyRebill consent **MUST** block new Agency-funded paid effects
immediately; already-incurred provider costs remain ledgered as-is; the Client
Workspace **MUST** have or select a valid client-paid payer before new paid
activity resumes.

**Architectural status change to RFC-005 §16:** RFC-005 §16's
`business_payer_assignments.payer_type` table currently documents
`agency_rebill` as "never activated in v1" with no defined consent rule for it
(RFC-005 §16 only defines consent rules for `workspace` and `business`
payer types). This addendum authorizes activating `AgencyRebill` **only**
under the rules in this section — RFC-005 §16's existing consent/charge
authority safeguards for `workspace`/`business` payers, and its narrowed
platform-administrator restrictions, **MUST NOT** be weakened when the
`agency_rebill` consent rule is added.

## 11. 2FA (B5)

2FA belongs globally to the User identity. A User **MUST NOT** have separate
2FA states per Workspace. If a User owns any Workspace whose owner role
requires 2FA, that User **MUST** enable 2FA globally to satisfy that
requirement. Workspace authorization remains independent of this. If the
person later stops being an owner, the system **MUST NOT** automatically
weaken or disable their existing 2FA.

## 12. Money lanes

These **MUST** remain separate ledgers/Stripe relationships:

- **A. SaaS subscription:** customer or Agency → platform Stripe.
- **B. Business customer revenue:** the Business's end customer → that
  Business's connected Stripe account.
- **C. Agency SaaS client subscription:** Client → the Agency's connected
  Stripe account.
- **D. Usage:** internal prepaid Business wallet plus the central
  provider/Telnyx, with payer resolved through the canonical payer authority
  (§9, §10).

1 Business = 1 connected Stripe account in V1.

## 13. Website / Locations

1 Business = 1 primary website in V1. Locations get dedicated pages inside
that website. Operational website leads **MUST** resolve a deterministic
Location before creating any Location-bound operational record. No IP/GPS
guessing in V1.

## 14. Packages / Products

The canonical Package/Product catalog is Business-wide. Locations **MAY**
enable/disable a package and **MAY** apply an optional price override; the
canonical catalog **MUST NOT** be duplicated per Location. Every
proposal/invoice/booking **MUST** store an immutable snapshot of the actual
package/price used.

## 15. Messaging / phone ownership

A messaging number belongs to exactly one Location. Incoming SMS: the
receiving number determines the Location. Outbound: use the Contact's
Location's primary messaging number unless explicitly overridden. A
Conversation remains Location-scoped. A2P/business verification remains
Business-level. No one-number/multiple-Location routing in V1.

## 16. Niche blueprint / entitlements

There is one canonical niche blueprint — separate Core/Growth/Agency copies
**MUST NOT** be maintained. Each component declares its required entitlement;
only components the current plan permits are installed. On an upgrade, newly
entitled components are surfaced for explicit user action to add — they
**MUST NEVER** be silently installed or activated into an existing Business.
New accounts **MAY** receive everything their current plan permits during
initial installation.

---

## 17. Specific old RFC decisions superseded

### RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md

- **§27, bullet 1** — *"The Workspace is the universal top-level account
  container for every customer shape; 'Agency' is a plan tier, not a separate
  tenant model, and no `Agency` model or `businesses.agency_id` is created."*
  **Superseded** by §1–§2 of this addendum: Agency clients each get their own
  Client Workspace, related to the Agency Workspace via an explicit,
  canonical Agency↔Client Workspace relationship — not held as many
  Businesses inside the Agency's own Workspace.
- **§7.1** — *"A single-Business Core customer has one Workspace with one
  Business... A multi-location Growth customer has one Workspace with several
  Businesses. An Agency customer has one Workspace with many unrelated client
  Businesses and several staff members..."* **Superseded** by §1 of this
  addendum: Growth's "several Businesses in one Workspace" and Agency's
  "many unrelated client Businesses in one Workspace" are no longer the V1
  model; V1 is 1 Workspace = 1 Business universally, with Agency clients
  living in their own Client Workspaces (§2).
- **§16.1** — *"Plan rules governing how many Businesses may be created under
  a Workspace are deferred to RFC-004 (§26). M1A, M1B, and Milestone 2 impose
  no numeric limit"* and *"The same Workspace shape supports, without
  modification: branches of one brand, unrelated Agency clients, and internal
  Agency Businesses (§7.1)."* **Superseded** by §1 of this addendum: creating
  an additional Business under an existing Workspace is not a V1 operation;
  a new Business requires a new Workspace.
- **§16.2 (Business reassignment across Workspaces, `WorkspaceManager::
  reassignBusiness()`)** — remains historically accurate to what M2 built, but
  its premise (a Business legitimately moving between two ordinary Workspaces
  that both already exist and both may hold Businesses) is **superseded** by
  §1: under V1, moving a Business to a different Workspace is no longer a
  routine operation once DB-level 1:1 is enforced (§18 transition order,
  step 7).
- **§9.3 (`workspace_membership_businesses`, scoped Business assignment)**
  and the `business_access_scope`/`Selected` mechanism it supports are
  **superseded** by §4 of this addendum (Location-scoped access replaces
  Business-scoped access once a Workspace holds exactly one Business).

### RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md

- **§13 (Business-slot semantics)** — the entire included/additional/maximum
  Business-slot model (*"the first 3 Businesses in a Workspace are included
  in the base price"*, *"5 (`business_slot_included + 2`) is the hard maximum
  for Core/Growth"*, *"Agency: unlimited Businesses/locations
  (`unlimited_business_slots = true`)"*) is **superseded** by §1 of this
  addendum: there is no Nth-Business capacity concept in V1, because a
  Workspace holds exactly one Business.
- **§17 (Business-creation slot enforcement)** — the slot-decision API this
  section defines is **superseded** to the extent it enforces the §13 model;
  Business creation in V1 is one Business per new Workspace, not a
  capacity-gated addition to an existing Workspace.
- **`additional_business_slots` / `unlimited_business_slots`** as a
  customer-facing purchasable capacity model (§13, §17, and the
  `additional_business_slot_agreements`/`additional_business_slot_renewal_
  charges` billing flow they drive) is **superseded** as V1 product
  architecture. It remains historically accurate as a description of what
  `main` currently implements, and **MUST** be treated as a migration
  condition to retire (§18 transition order, step 5), not as ongoing product
  direction.

### RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md

- **§16, `payer_type` table row** — *"`agency_rebill` (never activated in
  v1)"* — its architectural status is **updated**, not reversed: this
  addendum (§10) authorizes activating `AgencyRebill` in V1, but only under
  the specific consent/authority rules in §10 above, which extend — and do
  not weaken — RFC-005 §16's existing consent and narrowed
  platform-administrator safeguards for the `workspace`/`business` payer
  types.

**Intentionally left historical (not superseded):** RFC-003 §7.2's ownership/
membership/tenancy distinction, §17's deactivation and hard-delete policy, and
RFC-004's `EntitlementManager::decide()` defensive consistency check remain
accurate and unaffected by this addendum. RFC-005's payer-consent and
charge-authority rules for the `workspace`/`business` payer types (§16) are
unaffected except for the addition of the `agency_rebill` row described
above.

A short, prominent supersession notice pointing to this addendum has been
added to RFC-003, RFC-004, and RFC-005 at the top of each affected file; the
historical text itself is left intact and readable.

---

## 18. Recommended transition order (architecture level only — no code)

1. Add the Agency↔Client Workspace relationship (§2), additive, no behavior
   change.
2. Add the cross-Workspace Agency authorization path and extend View As to
   use it, alongside the existing same-Workspace path.
3. Introduce Location ACL (§4).
4. Migrate Agency client management away from many Businesses in one
   Workspace, into one Client Workspace per client.
5. Freeze and retire the old additional-Business-slot purchasing flow (§17,
   RFC-004 §13/§17).
6. Migrate/backfill any remaining multi-Business Workspaces outside the
   Agency case.
7. Only then enforce DB-level Workspace:Business 1:1.
8. Retire dead old-model code and tables after migration verification.

---

## 19. Architecture freeze declaration

**V1 product architecture is frozen by this addendum.** Future implementation
work must not reopen the decisions in §1–§16 unless a demonstrated
contradiction makes implementation unsafe or impossible, or the product owner
explicitly changes the decision. Implementation agents should use reasonable
defaults for minor UX, copy, filter, and presentation details rather than
generating new architecture questions from this document.