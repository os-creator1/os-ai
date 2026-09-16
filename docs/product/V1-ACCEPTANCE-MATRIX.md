# V1 Acceptance Matrix

**Status:** Documentation only. Converts
[`V1-MASTER-PRODUCT-BLUEPRINT.md`](./V1-MASTER-PRODUCT-BLUEPRINT.md) into
product-level acceptance statements, organized by actor. This is a product
acceptance **authority** for later test planning — it is not itself a test
suite, and it does not authorize implementation. "Known current-main
status" cites only what earlier passes in this session mechanically
verified (see
[`V1-AUTHORITY-TRACEABILITY-MATRIX.md`](./V1-AUTHORITY-TRACEABILITY-MATRIX.md));
where nothing was verified it says so rather than guessing.

Column key: **Scope** = Business-wide (BW) or Location-bound (LB) per
Blueprint §5. **Paid?** = whether the capability triggers a wallet-checked
paid side effect (Blueprint §20).

---

## Business Owner

| Capability | Expected outcome | Entitlement | Scope | Permission boundary | Lifecycle states that matter | Paid? | Critical failure behavior | V1 acceptance statement | Current-main status |
|---|---|---|---|---|---|---|---|---|---|
| Sign up | Working account within minutes, no forced A2P/calendar step | Any tier | BW | none (unauthenticated) | Trial starts | No | Signup must never silently create a second Business under an existing owner's Workspace (Blueprint §35, §6) | A prospective owner reaches Home with a Primary Location and installed niche Blueprint without being blocked by optional setup | Legacy-migration required (traceability row 29) |
| Configure Locations | Add/manage Locations, understand downgrade lock behavior | Core+ (capacity per §21/Addendum §6) | BW (management) / LB (each Location) | Owner only for add/remove | Active/Locked-over-limit (§6) | No | Downgrade must never delete/silently archive a Location | Owner can add a Location and, on downgrade, choose which stay operational | Aligned (model exists) |
| Receive/manage leads | Opportunities move through the pipeline; Contacts are captured once per Location | Core+ | LB | Owner + staff per Location ACL (§26) | Opportunity stage, Booked badge (§9) | No | A lead must never be created without a resolved Location (Addendum §5) | A new lead becomes a Contact + Opportunity at the correct Location without manual fixing | Partially aligned (row 6, 7) |
| Message customers | Send/receive SMS with a working business number | Core+ | LB | Owner + staff per Location ACL | Active wallet, A2P verified (§19) | Yes | STOP/DND must be enforced before every send, no exceptions | A Business can text a lead the moment its number is set up | Legacy-migration required — Conversation is user-scoped today, not Location-scoped (row 8) |
| Take bookings | Calendar shows availability, customer can self-book | Core+ | LB | Owner + staff per Location ACL | Appointment lifecycle (§12) | No | Double-booking across Locations for the same staff member must be prevented by construction | A customer books a slot and both parties see it | Not yet implemented (row 9) |
| Automate follow-up | Recipes/custom automations run reliably | Core+ (depth varies §21) | BW def. / LB run | Owner + staff per feature permission | Run success/failure, retry | Yes (for messaging actions) | A run must recheck wallet/cap immediately before its paid step, never assume an earlier check still holds | A configured automation fires within its stated window and is visible in run history | Partially aligned (row 10) |
| Publish a website | One live website with Location pages | Core+ | BW (site) / LB (pages) | Owner | Draft/preview/published | No | Publishing must never lose the last-published version (restore action, §14) | Owner publishes a site with at least the Primary Location's page live | Partially aligned (row 11) |
| Manage SEO | See rankings/keywords; Growth+ gets full local SEO | Basic: Core+; Full: Growth+ | BW (Search Console) / LB (GBP) | Owner + staff per feature permission | — | No | — | Owner sees keyword visibility at minimum on Core | Not yet implemented beyond Keywords (row 12) |
| Sell from a catalog, collect signed & paid agreements | Package catalog, proposal/contract/invoice flow with e-signature and payment | Core+ | BW (catalog) / LB (transactions) | Owner + staff per feature permission | Document lifecycle (§18) | Yes | Every transactional document must snapshot the package/price immutably (Addendum §14) | Owner can send a proposal, get it signed, and collect payment without leaving the product | Not yet implemented — no Package/Proposal/Contract model found (rows 14, 15) |
| Manage staff and Location access | Add staff, grant one/several/all Locations | Core+ | BW (staff mgmt) / LB (grants) | Owner only — staff cannot manage staff (§26) | — | No | A Selected-scope staff member must never gain access via a guessed ID (Addendum §4) | Owner grants a staff member two of three Locations and confirms the third is inaccessible | Not yet implemented (row 4) |
| Understand what to do next | Home + AI COO surface attention items and one recommendation | Basic AI COO: Core+ | BW | Owner + staff (permission-gated) | — | No | AI COO must never execute a paid/consequential action without approval (§23) | Home never shows an empty billing card with nothing to act on (§8) | Not verified in this pass |

## Business Staff

| Capability | Expected outcome | Entitlement | Scope | Permission boundary | Lifecycle states | Paid? | Critical failure behavior | V1 acceptance statement | Current-main status |
|---|---|---|---|---|---|---|---|---|---|
| Work assigned Locations | See/act on only granted Locations | Inherited from Business plan | LB | `location_access_scope` grant (Addendum §4) | — | Varies by action | Fail closed — a missing grant means no access, not degraded access | A Staff member with Selected scope for Location A cannot open a Location B record by any route | Not yet implemented (row 4) |
| Cannot manage staff/permissions | Attempting to reach Team & Permissions is refused | — | BW | Owner-only (§26) | — | No | Must be a real authorization refusal, not merely a hidden menu item (Navigation Contract D-20 precedent) | A Staff member hitting the Team & Permissions route directly gets refused, not a raw 404 that leaks existence | Not verified in this pass |

## Agency Owner

| Capability | Expected outcome | Entitlement | Scope | Permission boundary | Lifecycle states | Paid? | Critical failure behavior | V1 acceptance statement | Current-main status |
|---|---|---|---|---|---|---|---|---|---|
| Operate own Business | Identical experience to a Core/Growth owner | Agency | BW/LB per §5 | Same as Business Owner | Same as §27 | Same as above | Same as above | Agency owner's own Business passes every Business Owner row above unchanged | Same as those rows |
| Create/manage Client Workspaces | Provision a client, see it in Clients list | Agency | BW (relationship) | **Create/ordinary management:** Agency Workspace owner, or an active Admin/Staff member of that Agency Workspace, by membership alone — no additional customer permission (Blueprint §2; Contract 01 §6; Contract 04 authority correction) — while the Agency is on the Agency tier with a usable account (Trial/Active/Grace; not Locked/Inactive/Suspended); platform/admin status adds no authority. **Termination:** Agency Workspace owner only on the Agency side, or the Platform Owner/platform relationship operator through the separate platform authority path (Addendum §2). **Money:** AgencyRebill consent/payer/funding stays Agency Workspace owner only (Addendum §10; see the AgencyRebill row) | Relationship lifecycle | No (provisioning itself) | Client cannot remove the managing relationship itself (Addendum §2); no Agency Admin/Staff member can terminate it | The owner, or an active Agency Admin/Staff member, provisions a client and it appears with correct status in Clients; only the owner (or the platform) can end the relationship | Not yet implemented (rows 2, 23) |
| View As a client | See exactly what the client sees, real actor audited | Agency | N/A (lens) | Active relationship required (Addendum §2, §6) | Client's own lifecycle unaffected | N/A | View As must never let a bypassed tenancy/wallet/STOP-DND rule through (Blueprint §32) | Every action taken during View As is attributed to the real Agency actor, not the client | Legacy-migration required — same-Workspace only today (row 27) |
| Sell SaaS plans | Configure and bill a client's platform subscription through Agency's own Stripe | Agency | BW | Owner (money-lane C, Addendum §12) | Client's own Trial/Active/etc. | Yes (subscription) | Must never mix with money lanes A/B/D (Addendum §12) | A client's SaaS subscription is billed through the Agency's connected Stripe, never the platform's | Not verified in this pass |
| Run Outreach | Prospect, sequence, convert to client | Agency | BW | Owner + team per permission | Campaign states (§29) | Yes (messaging) | Same STOP/DND rules as any outbound channel — no weaker path | A prospect can be enrolled, messaged per sequence, and stopped on reply/opt-out/booking | Partially aligned (row 24) |
| White-label | Client-facing product shows Agency branding | Agency | BW | Owner | — | No | — | A client sees Agency branding, not the platform's | Not verified in this pass |
| Choose client-paid vs Agency-paid usage | Configure AgencyRebill per client | Agency | BW (per client) | Agency Workspace owner only, never Agency Admin/Staff (Addendum §10) | Consent established/revoked | Yes (standing consent) | Revocation must block only new activity, never erase already-incurred ledgered costs (Addendum §10) | Owner enables AgencyRebill for a client; client's usage draws from the Agency's funding until revoked | Not yet implemented — deliberately inert (row 18) |

## Agency Team Member

| Capability | Expected outcome | Entitlement | Scope | Permission boundary | Lifecycle states | Paid? | Critical failure behavior | V1 acceptance statement | Current-main status |
|---|---|---|---|---|---|---|---|---|---|
| Use Agency surfaces per role | Reach Clients list, View As, and ordinary non-financial client management by active Agency membership; Outreach per its own module entitlement/permissions | Agency | BW | Active Admin or Staff member of the exact Agency Workspace, by membership alone (§26; Contract 01 §6; Contract 04 authority correction); an active Agency↔Client relationship is required for linked-client operations (Addendum §2). No additional `manage_agency_clients` or other Agency-management permission is required for this authority — **View As is not owner-only**; AgencyRebill consent/payer/funding configuration and relationship termination remain owner-only (Addendum §2, §10) | — | Varies (View As itself: no; funding-related sub-actions: yes) | A team member must never gain AgencyRebill consent/payer authority or the ability to terminate the relationship without being the Workspace owner; View As authority must never be inferred merely from ordinary membership in the *Client* Workspace (Addendum §2) | An active Agency Admin/Staff member can View As a linked client and perform ordinary Agency management without a separate Agency-management permission, but cannot touch AgencyRebill owner-only settings or terminate the relationship (Outreach remains governed by its own module entitlement/permissions) | Not verified in this pass |

## Agency SaaS Client (Client Workspace owner/staff)

| Capability | Expected outcome | Entitlement | Scope | Permission boundary | Lifecycle states | Paid? | Critical failure behavior | V1 acceptance statement | Current-main status |
|---|---|---|---|---|---|---|---|---|---|
| Operate their Business | Identical experience to any Core/Growth/Agency-tier owner | Their own plan | BW/LB per §5 | Same as Business Owner | Own independent lifecycle (Addendum §8) | Same as above | Their non-payment must never affect another client or the Agency (Addendum §8) | Every Business Owner acceptance row above holds for a Client Workspace owner unchanged | Same as those rows |
| Cannot remove managing Agency relationship | Attempting to end the relationship is refused | — | BW | Only Agency owner or Platform Owner may terminate (Addendum §2) | Relationship lifecycle | No | Must be a real authorization refusal | A client owner cannot self-service-remove their Agency in V1 | Not yet implemented (relationship doesn't exist yet, row 2) |
| Resume after AgencyRebill revocation | Choose/use a valid client-paid payer | — | BW | Client owner (payer change, §16 pattern) | Payer transition | Yes | No new paid activity until a valid payer is set (Addendum §10) | Client can switch to client-paid and resume sending | Not yet implemented (row 18) |

## Platform Owner

| Capability | Expected outcome | Entitlement | Scope | Permission boundary | Lifecycle states | Paid? | Critical failure behavior | V1 acceptance statement | Current-main status |
|---|---|---|---|---|---|---|---|---|---|
| Manage accounts/Workspaces | Support and administer any account | Platform | Global | Platform Administrator (narrowed, Addendum §10) | Any | No (view/support) | Must never originate a customer's financial consent merely by role (Addendum §10) | Platform Owner can resume a stuck attempt or issue a credit, never originate a fresh charge | Partially aligned (row 25) |
| Manage niche Blueprints / Template Library | Publish/version Blueprints | Platform | Global | Platform Administrator | Blueprint version/provenance (§22) | No | Update must never silently reactivate components in an existing Business (Addendum §16) | A new Blueprint version is published without touching any live Business until it opts in | Not verified in this pass |
| See platform billing/revenue | Separate visibility/accounting across all four money lanes | Platform | Global | Platform Administrator | — | No (view) | Lanes A/B/C/D must never be conflated in reporting — B is Business revenue, C is Agency revenue, D funds Business usage, none of it platform revenue merely because it's visible (Addendum §12) | Revenue dashboard separates platform SaaS (A), Business-customer (B), Agency-client-subscription (C), and usage (D) lanes | Not verified in this pass |
| Handle support/privacy requests | Process a deletion/anonymization request | Platform | Global (data subject) | Platform Administrator | Retention window (§33) | No | Six-month recoverable window is a product statement, not a legal claim (§33) | A privacy request is logged and actioned through the dedicated surface, not ad hoc | Not verified in this pass |
| Review audit logs | See high-value transitions with actor/timestamp/reason | Platform | Global | Platform Administrator (read) | — | No | — | Every AgencyRebill consent change and Agency relationship termination is visible with its mandatory reason | Not yet implemented (depends on rows 2, 18) |

## End Customer / Lead

| Capability | Expected outcome | Entitlement | Scope | Permission boundary | Lifecycle states | Paid? | Critical failure behavior | V1 acceptance statement | Current-main status |
|---|---|---|---|---|---|---|---|---|---|
| Submit a website/form lead | Becomes a Contact/Opportunity at the right Location | N/A | LB | None (public) | — | No (costs the Business, not them) | Must resolve a deterministic Location before record creation, never guess by IP/GPS (Addendum §13) | A form submission on a Location's page creates that Location's Contact | Partially aligned (row 13) |
| Receive/reply to SMS | Normal two-way texting; STOP honored immediately | N/A | LB | None | STOP/DND | Costs the Business | A STOP reply must block all future non-transactional sends immediately | Texting STOP stops all further messages from that Business | Partially aligned (row 16) |
| Book an appointment | Self-serve public scheduler | N/A | LB | None | Appointment lifecycle | No (costs the Business indirectly) | No double-booking | A public booking link produces a confirmed appointment at the correct Location | Not yet implemented (row 9) |
| Sign/pay a document | Secure link, sign and pay without an account | N/A | LB | Possession of the secure link | Document lifecycle (§18) | Costs the Business | Link must be non-guessable | A customer signs and pays via the emailed link | Not yet implemented (row 15) |

---

## How to use this matrix

Each row is a candidate for one or more adversarial/acceptance tests when
its slice (see
[`V1-IMPLEMENTATION-ROADMAP.md`](./V1-IMPLEMENTATION-ROADMAP.md)) is
actually implemented. "Not yet implemented" rows are not gaps in this
document — they are accurately reporting that the capability does not
exist on current `main` today, per the traceability matrix; they become
testable once their roadmap slice lands, not before.
