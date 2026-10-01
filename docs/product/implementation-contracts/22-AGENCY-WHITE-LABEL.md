# Implementation Contract 22 — Agency V1 Completion: Clients list, SaaS Plans acceptance, White Label

**Status:** implemented and tested on `agent/agency-white-label-saas-v1-completion`.
This is an acceptance/completion pass over the Agency architecture that Contracts
01, 04, 05, 07, 08A, 09 and Lane C (21-LANE-C) already merged. It adds no second
authority of any kind: where a requirement was already met, this document says
where it is proven; where it was not, it says what was built; where the merged
contracts forbid the requested behaviour, it says that and defers.

**Base:** `main` at `3692660f`.

---

## 1. Managing-relationship model (unchanged — and proved, not rebuilt)

There is exactly one authority for "this Agency manages this Client":
`agency_client_workspace_relationships` (`AgencyClientWorkspaceRelationship`,
Contract 01). Two states only — `active`, `terminated` — and a terminated row is
the audit record, never deleted. At most one *active* row per Client Workspace
(`active_client_workspace_id` unique generated column).

* **Never inferred.** Nothing derives Agency ownership from a creator id, an
  invited email, a tier or a shared membership. Every Agency action re-reads the
  persisted row: `ViewAsManager` on start **and on every read**,
  `AgencyClientsController::resolveLinkedClient`, `AgencyClientRelationshipManager`,
  and — new here — `ClientWorkspaceBrandResolver`.
* **Authority** (Contract 01 §6 / Contract 04 correction): the exact Agency
  Workspace's owner or an *active* Admin/Staff member, by membership alone;
  platform/admin status adds none. **Termination** is the Agency owner's (or the
  platform operator's) alone; no customer route can end a relationship, and a
  client cannot escape it. The Agency's management is a *lens* — it never makes an
  Agency user a member of the client's Workspace and never grants Location access.
* **Creation** is Contract 07's invite → claim → provision flow (atomic
  Workspace + Business + Primary Location + relationship, retry-safe); the Agency
  cannot create a client on a recipient's behalf. Unchanged.

Proven by: `AgencyClientRelationshipManagerTest`, `AgencyClientProvisioningTest`,
`ClientInvitationManagerTest`, `AgencyClientsHttpTest`, and this lane's
`AgencyAuthorizationMatrixTest` (actor × surface matrix, A/B isolation both
directions, no client-reachable termination route, Location boundary).

## 2. Clients list (built)

`AgencyClientListReader` replaces the old "load every relationship, query per
client, filter in memory" read.

* **Bounded.** 25 rows/page (hard maximum 50), SQL `LIMIT/OFFSET`. Search (≤ 80
  chars, LIKE wildcards escaped) over Workspace name and Business name, and a
  Business-state filter (`setup`/`active`/`inactive`), both in SQL.
* **Fixed statement count.** The page, its count, then one batched read each for
  Businesses, plan assignments and the Agency's own subscriptions. The test
  asserts the count is identical for 2 and 10 clients.
* **Reads go through the seam that owns the table.** Plan assignments come from
  `WorkspacePlanAssignmentRepository::lifecycleFactsForWorkspaces()` and the
  Agency's own subscriptions from `AgencyClientSubscriptionManager::
  summariesForClients()`; the reader itself holds no raw entitlement-table or
  lane-C query (the repository's "no raw entitlement-table query outside
  `EntitlementManager` and its repositories" rule, `NoRawEntitlementTableQueryTest`,
  is respected for this lane's code).
* **Keyed by the Agency.** The Agency Workspace id is a bound parameter of the
  first statement; another Agency's clients cannot be enumerated by search,
  filter or page number. The Agency never lists itself.
* **Columns:** client, business (with the existing Draft/Inactive truthfulness),
  *Account*, *Plan*, *Agency billing*, since, Open, View As.
* **No second lifecycle authority.** *Account* is produced by
  `CustomerAccountAccessResolver::decideFromAssignmentFacts()` — the Contract 03
  truth table, extracted from `resolveOwnWorkspaceDecision()` into one pure
  method both now call (the structural call-graph test in
  `AgencyNonPaymentCompositionTest` still passes). It is the client's *own*
  state; composing it with the Agency is the controller's gate (a Locked,
  Inactive or Suspended Agency never reaches the list).

The SaaS Plans catalog is bounded the same way: `AgencySaasPlanManager::
MAX_PLANS_PER_AGENCY = 100`, enforced on create (`plan_limit_reached`) and on
read. The Agency revenue page (`AgencySaasController::revenue`) still reads all
of an Agency's subscriptions; it is a report, not a list, and is **deferred**.

## 3. SaaS Plans — the entitlement model (Lane C, proved)

Unchanged from Lane C, and deliberately *narrower* than "feature picker":

* A resale plan chooses **name, description, price, currency, cycle, trial** and a
  **canonical tier** (`core` or `growth`; `agency` is refused). It has no feature,
  limit or permission column — structurally asserted in
  `AgencySaasPlanAcceptanceTest`. Per-plan features/limits are therefore **not
  built**: the registry (`PlatformFeature`) is exercised only through the tier,
  and the contract forbids a second capability lever.
* A client's effective entitlements are exactly its tier's, through
  `EntitlementManager` (the test compares an enrolled client's
  `planFeatureKeys` to a directly-assigned Growth Workspace's, and asserts no
  per-client override exists).
* **"Assigning" a plan is an offer.** The Agency owner *offers*; only the client
  owner (or an active client Admin) can consent through hosted Checkout on the
  Agency's Stripe account; View As can never consent. The plan assignment is then
  written by the verified-subscription `EntitlementManager` wrappers. This is
  Lane C §C6 and is not changed.
* **Foreign plans are impossible:** an offer whose plan belongs to another
  Agency, or to a client the offering Agency does not manage, is refused
  (`no_active_relationship`).
* **Plan deactivation (`unpublish`)** is a shop-window decision (Lane C §C8):
  existing subscribers keep their subscription, terms, tier and lifecycle; no new
  offer can use the plan (`plan_not_sellable`). Repricing never reprices a
  subscriber (price snapshot). Both are proven.
* **History/audit:** offers record `offered_by_user_id`/`offered_at` and a terms
  snapshot; price changes are rows in `agency_saas_plan_pricing_changes`;
  entitlement writes are rows in `workspace_entitlement_transitions`.

## 4. White Label (built — V1 surface only)

### 4.1 What shipped

| Piece | Where |
|---|---|
| Storage | `agency_white_label_settings` (one row per Agency Workspace, `unique(agency_workspace_id)`), `agency_white_label_changes` (insert-only audit) |
| Writer | `AgencyWhiteLabelManager` — owner-only, transaction + row lock, idempotent |
| Surface | Settings → **White label** (`customer.workspaces.agency.white-label.show|update`) |
| Reader | `ClientWorkspaceBrandResolver` → `ClientChromeBrand` → `branding-logo`, `branding-footer`, `contentLayoutMaster` title |
| Entitlement | `white_label` flipped **Planned → Available**, **Workspace-scoped** (like `ProspectOutreach`), decided by `EntitlementManager::decideForWorkspace()` |

Fields: display name (required, ≤ 80), tagline (≤ 160), accent colour (`#rrggbb`),
support email, logo, and an on/off switch (default **off**).

### 4.2 Behaviour and rules

* **Branding belongs to the Agency.** A client never owns a copy. A signed-in
  client renders its *managing* Agency's brand, found from the request's framed
  Workspace → the **active persisted relationship** → *that* Agency's enabled row.
  Another Agency's row is unreachable (lookup keyed by the relationship's agency
  id); a Workspace with no managing Agency is never branded — not by a query
  parameter, a submitted uid or "the first Agency".
* **Re-derived every request**, in order: relationship active → row enabled →
  Agency Workspace active → Agency still management-eligible (Agency tier, usable
  account) → still entitled to `white_label`. Any failure yields platform
  branding. Consequences, each tested: ending the relationship, switching the
  brand off, a locked/suspended/downgraded Agency, or an entitlement override
  *Deny* all return the client to platform branding at once, with nothing to
  clean up. Memoized on the `Request`, never in a cache or singleton. The one relationship
  read it needs is the **same request-scoped read the customer menu already makes**
  (`RequestScopedCache::ACTIVE_AGENCY_RELATIONSHIP_PREFIX`), so a branded page costs
  no additional relationship statement over the platform's baseline — verified
  against pristine main's `QueryBudget` results and pinned by
  `AgencyWhiteLabelTest::…reuses_the_menus_per_request_relationship_read`.
* **Where it renders** (signed-in client chrome): the logo/mark (sidebar, navbar,
  horizontal menu via `x-branding-logo`), the footer copyright holder and the
  Agency's support address, and the browser title suffix. The Agency's *own*
  chrome stays platform-branded.
* **Accent colour** is applied only to the text-mark fallback (readable text
  colour is computed from WCAG luminance). No global design token is overridden;
  the design system is untouched.
* **Reuses the login screen's normalizer.** Name/tagline are plain text, the logo
  must be an existing file under `images/branding/`, the colour a validated hex
  — `AuthBrandPresenter::forAgencyBrand()` — so signed-in chrome can never be
  more permissive than the login screen. A tampered row cannot inject markup, a
  path outside `images/branding/` or a bad colour (tested).
* **Uploads.** The platform's own `ValidBrandingImageRule` (magic-byte type
  detection, raster only — SVG refused, 2 MB, 800×200 px), validated in the form
  *and again* in the manager; stored at
  `images/branding/agency/{agency uid}/{sha256}.{ext}` (never a client-derived
  name); only files inside that Agency's own directory are ever deleted; a
  non-owner's upload writes no file and can never delete the live one.
* **Authority.** Read: any active Agency team member (404 for everyone else, with
  the same answer as a non-existent Agency). Write: the Agency Workspace **owner
  only**, asserted inside the manager from the *locked* Workspace row, plus Agency
  eligibility and the entitlement. **Prohibited under View As**
  (`customer.workspaces.agency.white-label.` in `ViewAsProhibitedActions`).
* **Audit.** Every effective change is one `agency_white_label_changes` row
  (`created|updated|enabled|disabled|logo_replaced|logo_removed`, actor, field-level
  before/after). A resubmission of identical values writes nothing.

### 4.3 Explicitly deferred (documented, not invented)

* **Custom branded domain and the host-resolved login brand.** The repository has
  no domain-ownership/DNS-verification infrastructure and no Agency app-domain
  requirement beyond the Slice 2 placeholder, so `AgencyBrandSource` stays
  **unbound** and no host mapping, verification lifecycle or routing is added.
  An unverified or inactive domain therefore cannot serve a branded authenticated
  app: no domain serves one at all. The settings page says so plainly. The seam
  and its isolation tests (`AuthBrandTenantIsolationTest`) are untouched and
  ready for a future domain slice.
* **Favicon**, per-client branding overrides, branded emails and branded public
  pages.

## 5. View As matrix (proved, not changed)

| Actor | May start View As | Source of proof |
|---|---|---|
| Agency owner / active Admin / active Staff of the **exact** Agency, with an active relationship | Yes | `AgencyViewAsTest`, `AgencyClientsHttpTest`, matrix test |
| Inactive/removed member, member of another Agency only, no membership | No (404) | `AgencyViewAsTest` |
| Client owner / client staff | No | `AgencyViewAsTest::client_side_actors…` |
| Platform Owner / user id 1 not in the Agency | No — separate authority path | `AgencyViewAsTest::platform_admin_status…`, matrix test |
| Another Agency's owner, or any Agency for another Agency's client | No | `AgencyViewAsTest`, `AgencyAuthorizationMatrixTest` |

Session identity stays the **real Agency actor** (`view_as_sessions.actor_user_id`,
`viewing_agency_workspace_id`); no client membership is ever created. A session
ends on the very next read — with a distinct, audited `end_reason` — when the
relationship is terminated (`relationship_ended`), the Agency becomes
Locked/Inactive/Suspended or leaves the Agency tier (`agency_entitlement_lost`),
the actor loses membership, or the client Workspace/Business becomes inactive
(`access_lost`). Money, credentials, destructive actions and now the Agency's
White Label and SaaS surfaces are `ViewAsProhibitedActions`. View As confers no
financial authority (AgencyRebill consent is keyed to the Workspace owner row,
never to a View As context; lane-C consent refuses while a session is active).

## 6. Payer and billing authority (audited, not rebuilt)

Unchanged and proved by `AgencyRebillAuthorityTest`, `AgencyRebillPaidEffectTest`,
`EffectivePayerResolverTest`, `PayerConsentAuthorizationTest` and the lane-C
suites: payer is a persisted rule (`BillingProfileManager`); AgencyRebill
consent/payer/funding is the Agency Workspace owner's alone; the client cannot
alter an Agency-owned payer assignment; the Agency never reads the client's
private payment methods (it sees only its own); an invalid or missing payer fails
closed before paid activity. Lane A/B/C/D remain isolated (`FourLaneCoexistenceTest`).

## 7. Suspension, reactivation, termination (contract-defined; nothing added)

The brief asked for Agency-initiated suspend/reactivate. The authoritative
contracts say otherwise and this lane follows them:

* The relationship has **two** states (`active`, `terminated`); Contract 01 §5
  states that no `Suspended` state is needed. **No new state was added.**
* *Suspended* is the **client's own** plan-assignment status — an administrative,
  Platform-Owner/compliance base status that is never redefined as Grace or
  Locked (Addendum §7). Reactivation is the same writer
  (`EntitlementManager::changePlanStatus`), audited with actor and reason.
* A client suspended/locked is Locked for its own users (Contract 03/05); its
  Agency can still see it in the list with the truthful *Account* badge.
* An **Agency's** lapse composes upstream through
  `CustomerAccountAccessResolver::composeWithManagingAgency()` without
  overwriting a byte of any client's own state; it also ends View As and stops
  white-label branding. One client's non-payment never affects another client or
  the Agency (Addendum §8).
* **Termination** is owner-only on the Agency side, never a client action;
  history is preserved; the client's Workspace, Business, Locations, members and
  data are untouched and it falls back to platform branding.
* **Deferred:** an Agency-initiated, relationship-level suspension. It would
  amend Contract 01's enum and needs its own contract.

## 8. Authorization matrix and audit seams

`AgencyAuthorizationMatrixTest` — Platform Owner, Agency Owner/Admin/Staff,
inactive Admin, Client Owner, Client Staff, another Agency's owner × clients
list/detail, SaaS plans/revenue/Stripe, White Label; owner-only writes; no
client-reachable termination; A/B isolation both ways; Location-scoped client
staff stay Location-scoped under Agency management (and the Agency team hold no
Location access); audit rows for relationship establishment/termination, plan
assignment, suspend/reactivate (actor + reason) and View As start/stop.

## 9. Files

New: `AgencyClientListReader`, `AgencyWhiteLabelManager`,
`ClientWorkspaceBrandResolver`, `ClientChromeBrand`, `AgencyWhiteLabelController`,
`AgencyWhiteLabelSetting`, `AgencyWhiteLabelChange`, `AgencyWhiteLabelException`,
migration `2026_10_13_090001_create_agency_white_label_tables`, the white-label
view, this document, and four test files.

Changed: `CustomerAccountAccessResolver` (pure-method extraction),
`WorkspacePlanAssignmentRepository` + Eloquent implementation (bulk
`lifecycleFactsForWorkspaces`), `AgencyClientSubscriptionManager`
(`summariesForClients`), `RequestScopedCache` (shared key constant),
`AgencyClientsController` (bounded list), clients list view,
`AgencySaasPlanManager` + `AgencyBillingException` (cap), `AgencyBrand`
(`supportEmail`), `AuthBrandPresenter` (`forAgencyBrand`), `PlatformFeatureRegistry`
(flip + scope), `ViewAsProhibitedActions`, `CustomerMenuBuilder`, `routes/customer.php`,
`branding-logo`/`branding-footer`/`contentLayoutMaster`, and the pinned
`PlatformFeatureRegistryTest`.
