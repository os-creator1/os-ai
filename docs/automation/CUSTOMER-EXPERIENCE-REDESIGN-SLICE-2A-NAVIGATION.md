# Customer Experience Redesign — Slice 2A: entitlement-aware navigation

**Contract only.** No product code, no migration, no route change, no test
implementation, no generated asset is authorised by this document. It
defines what a later implementation lane may do, and — just as bindingly —
what it may not.

| | |
|---|---|
| Base `origin/main` | `b87b669d55de97957d3583407ec2b69cd75d2eaa` (PR #236, theme assets, merged) |
| Branch | `agent/customer-experience-redesign-slice-2a-navigation-contract` |
| Parent contract | `AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md` §8, §16 *Slice 2* |
| Retention authority | `PRODUCT-SURFACE-RETENTION-AUDIT.md` §6, §7 |
| Reconnaissance | Slice 2 navigation deep reconnaissance, re-verified against this base |

---

## 1. The split, locked

The parent §16 *Slice 2* bundles four independent risks. This contract owns
**2A only**.

**2A owns.** The final customer navigation tree; plan/feature-aware
visibility (D-20); a narrow request-scoped menu entitlement snapshot; the
Business/Account frame grouping; the Messages group; the Settings grouping;
removal of *Developers* from customer navigation; nested active-state
correctness; the role and tier matrix.

**2A does not own, and must not touch.** Business-scoping Conversations;
the chat-box URI; `ChatBoxController`; chat-box links; mobile bottom-tab
navigation; any responsive redesign; deletion of any legacy route,
controller or datum.

Those belong to **Slice 2B — Business-scoped Conversations** and to the
later responsive slice and the retention deletion slices. They are not to be
blurred back together, and a 2A implementation that touches them is out of
contract regardless of how small the change looks.

---

## 2. Facts re-verified against this base

Every claim below was re-derived from `b87b669d` before this contract was
written, not carried over.

| Claim | Verified state |
|---|---|
| `customer.invoices.index` | **absent.** `routes/customer.php:246-248` registers only `invoices.search`, `invoices.view`, `invoices.print` |
| A Team/members landing route | **absent.** `routes/customer.php:711-715` registers five **POST-only** mutations (`members.store`, `.role`, `.access`, `.deactivate`, `.reactivate`) |
| `customer.templates.index` | exists, 10 routes, currently unreachable from any menu |
| `customer.developer.settings` | exists, currently emitted inside *Settings → Advanced* |
| Entitlement in navigation | **none.** `CustomerMenuBuilder` checks `Route::has()` + `Gate::any()` only |
| `PlatformFeature` cases | 16 declared; `PlatformFeatureRegistry::AVAILABILITY` marks **6 Available**; **5 of those are Business-scoped** (`crm`, `conversations`, `automations`, `website_generation`, `google_business_profile_module`), `prospect_outreach` is Workspace-scoped |
| `EntitlementManager::decide()` | RFC-004 §14's **8-step precedence**, 6 repository reads per call, **no caching** (`Cache::` count in the class = 0) |
| `decideAvailableFeaturesForBusiness()` | loops the 5 Business-scoped Available features, adding one toggle read each ≈ **35 reads per render** |
| `UsageWalletManager::evaluateCoarseCapacity()` | an unconditional `return new UsageCapacityDecision(true);` — step 11 costs **zero queries** today, for every feature |
| `WorkspacePlanFeatureRepository` | already exposes the bulk seam `featureKeysForCatalog(WorkspacePlanCatalog): Collection` |
| Override / toggle repositories | expose **per-feature finders only**; no bulk read exists yet |
| `MenuItem` / `customer-nav-item` | support arbitrary nesting; the component recurses; `hasActiveChild()` **is recursive** |
| `WorkspaceCandidate` | already carries `tier` and `tierDisplayName` |

---

## 3. Invoices and Team — resolved, no placeholder routes

**Locked.** Slice 2A creates no route to satisfy a navigation sketch.

* **Plan & subscription** stays `customer.subscriptions.index`. Invoice
  history remains reachable there — the current builder already lists
  `customer.invoices.` among that entry's active prefixes, so an invoice
  detail page keeps the Plan entry highlighted.
* **Account** stays `customer.workspaces.show`. Team and member management
  remain sections of that page.
* **No standalone *Invoices* leaf and no standalone *Team* leaf** may be
  emitted. Both would name destinations that do not exist, and
  `CustomerMenuBuilder::item()`'s own `Route::has()` guard would silently
  drop them — a navigation entry that vanishes without explanation is worse
  than one that was never designed.

A later UX slice may create dedicated pages if product value justifies them.
That is not this slice's decision to pre-empt.

---

## 4. Templates — deferred, explicitly

**Locked: Slice 2A does not surface *Messages → Templates*.**

The route exists and is reachable, but reachability is not the test. The
retention authority is unambiguous: `PRODUCT-SURFACE-RETENTION-AUDIT.md` §7
classifies Templates as **"Current UI: DELETE (fold into Outreach)"**,
**"Design action: DO NOT DESIGN LEGACY UI"**, rebuild in its slice 9, and
§6.1 states that *"Templates should fold into this same future compose
experience rather than remain a standalone module."*

Surfacing the legacy standalone page now would promote a surface the
retention audit has already decided to dissolve, and would have to be
un-promoted by slice 9. It waits. No Templates code changes in 2A.

---

## 5. The final Business frame

Every leaf below names a route that exists on `b87b669d`. Entries marked
*entitlement* render only when the feature is entitled for the selected
Business (§6).

```
Home                      user.home                                   access_backend
Advisor                   customer.opportunities.index                access_backend   [config('opportunity.enabled')]

Messages                  group
  Inbox                   customer.chatbox.index                      chat_box         [route UNCHANGED — see §9]
  Send                    …businesses.outreach.index                  outreach perms   [new in menu]
  Campaigns               …businesses.outreach.campaigns              outreach perms

Contacts                  …businesses.contacts.index                  contact perms
Automations               …businesses.automations.index               automations      + entitlement automations
Website                   …businesses.website.show                    website          + entitlement website_generation
Get found                 …businesses.gbp.index                       view_google_business_profile
                                                                                       + entitlement google_business_profile_module
Results                   …businesses.analytics.overview              view_reports

Settings                  group
  Business                group
    Business details      customer.business.edit                      access_backend   [own PRIMARY Business only]
    Blocked numbers       customer.blacklists.index                   blacklist perms
  Billing                 …businesses.usage-billing.show              access_backend   [canManageBilling()]
  Account                 customer.workspaces.show                    access_backend   [canManageWorkspace()]
  Plan & subscription     customer.subscriptions.index                access_backend   [canManageWorkspace() && is_customer]
  Advanced                group                                                        [Agency only — see §7]
    Messaging provider    …businesses.channels.index                  view_numbers
    Sender identities     customer.senderid.index                     view_sender_id
    Numbers               customer.numbers.index                      view_numbers
    Keywords              customer.keywords.index                     view_keywords
```

Deliberate decisions inside that tree:

* **Advisor is retained** exactly as it is emitted today. The parent §8.2
  sketch omits it; it is a real, config-gated, shipped surface and dropping
  it silently would be a product change disguised as a navigation change.
  If it should go, that is a separate decision with its own evidence.
* ***Send* is genuinely new in the menu.** `…businesses.outreach.index`
  exists and has never been linked; today only `outreach.campaigns` is.
* **Blocked numbers moves under *Settings → Business*** presentationally.
  Its route stays account-scoped (`blacklists`); that scope mismatch is
  recorded as debt in §9, not fixed here.
* **Developers is gone** from the tree (§8).
* **Templates is absent** (§4).
* **No *Invoices* or *Team* leaf** (§3).
* **Two-word labels**, sentence case, except the proper noun *Google
  Business Profile* which appears inside the page; the nav label is
  *Get found*.

---

## 6. Entitlement visibility — fixing D-20 inside the query budget

### 6.1 The visibility rule, locked

A menu leaf is emitted only when **all four** hold:

1. the route is registered (`Route::has()`);
2. the actor holds a required permission (`Gate::forUser()->any()`);
3. the actor has access to the relevant Account/Business (the frame and
   scoped uids the context already resolves);
4. **feature entitlement permits it.**

When a plan feature is not entitled the entry is **absent**. No disabled
control, no greyed row, no upsell badge in the sidebar — a disabled control
still advertises an action, and the sidebar is the wrong place to sell.
Plan and upgrade explanation belongs on the Plan and Account surfaces.

**Menu hiding is not authorization.** Every destination controller must
remain independently fail-closed on its own entitlement check. The existing
GBP controller pattern — Workspace → Business → `userCanAccessBusiness()` →
active Business → entitlement, aborting **404, never 403** — is the
reference and must not be weakened. A raw 404 is the *security* behaviour
for a forged direct request; it is explicitly **not** the discoverability or
upgrade experience, which is why hiding the entry is the visible half of
the fix.

Required outcomes: a Core actor never sees *Get found*; a Growth actor with
the entitlement does; an actor holding `view_google_business_profile`
without the entitlement sees no entry **and** is still denied on direct
request.

### 6.2 Which features gate which entries

| Entry | Feature key | Rationale |
|---|---|---|
| Automations | `automations` | Business-scoped, Available |
| Website | `website_generation` | Business-scoped, Available |
| Get found | `google_business_profile_module` | Business-scoped, Available; the D-20 exemplar |

**Contacts is not entitlement-gated in 2A** even though `crm` is a
Business-scoped Available feature. Whether any tier's catalog actually
excludes CRM cannot be established from code alone, and hiding Contacts
from a tier that pays for it would be a severe regression. A test must
assert Contacts remains visible for every tier. If product confirms a tier
genuinely excludes CRM, gating it is a one-line follow-up on this design.

**Inbox is not entitlement-gated in 2A** despite `conversations` being
Available. Its route is account-scoped, so there is no selected Business to
evaluate against in every frame it renders. Gating it correctly requires the
Business scope that **Slice 2B** delivers. Recorded as debt in §9.

### 6.3 The seam — one policy, not two

§5 of the issuing task asks: if reproducing the policy accurately would
require maintaining a second decision engine, stop and identify the smallest
reusable `EntitlementManager` seam instead. **It would, so this contract
takes the seam.**

`decide()` implements RFC-004 §14's 8-step precedence: known key → available
→ Business-scoped → Business exists and belongs to the Workspace → plan
assignment exists → Workspace override if present, else plan-catalog
mapping → per-Business toggle → plan status → usage authorization. A menu
resolver that re-derived that order would be a second authority that can
drift. **It must not exist.**

The decisive observation is that only **three** of `decide()`'s six reads
vary per feature:

| Read | Varies per feature? |
|---|---|
| `businessRepository->findById()` | no — per request |
| `assignmentRepository->findByWorkspaceId()` | no — per request |
| `catalogRepository->findById()` | no — per request |
| `overrideRepository->findByWorkspaceAndFeature()` | **yes** |
| `planFeatureRepository->includesFeature()` | **yes** |
| `toggleRepository->findByBusinessAndFeature()` | **yes** |
| `usageAuthorizationGateway->check()` | yes, but **0 queries** — `evaluateCoarseCapacity()` is an unconditional `return new UsageCapacityDecision(true)` while every feature stays `is_metered = false` |

So the seam is a **bulk-preloading variant of `decide()`, inside
`EntitlementManager`**, that loads the three constants once, loads the three
per-feature collections in bulk once, and then runs **the same eight steps,
in the same order, from the same class**, over the in-memory snapshot. The
policy stays in exactly one place; only the data access changes shape.

Conceptual shape — the implementer may adjust names to repository
convention, but not the properties:

```
EntitlementManager::snapshotBusinessFeatureDecisions(
    Workspace $workspace,
    Business $business,
    array $featureKeys,
    int $actorUserId,
): array   // featureKey => EntitlementDecision
```

Two small bulk repository methods are required and are in scope:

* `WorkspaceEntitlementOverrideRepository::allForWorkspace(int $workspaceId)`
* `BusinessFeatureToggleRepository::allForBusiness(int $businessId)`

`WorkspacePlanFeatureRepository::featureKeysForCatalog()` already exists and
must be reused rather than duplicated.

### 6.4 The navigation-facing value object

`App\Library\Navigation\MenuEntitlements` — an immutable, request-local
value object holding the resolved decisions and answering
`allows(string $featureKey): bool`. It performs **no** policy of its own; it
carries the snapshot `EntitlementManager` produced. It is built once per
request, alongside the existing `CustomerContext` resolution, and is passed
to `CustomerMenuBuilder`.

### 6.5 The hard query budget

**Menu entitlement resolution must cost ≤ 6 database queries per request,
independent of how many features the menu checks.** The contracted shape:

| # | Read | Scope |
|---|---|---|
| 1 | Business by id | per request |
| 2 | plan assignment by workspace id | per request |
| 3 | plan catalog by id | per request |
| 4 | **all** Workspace entitlement overrides | per request, bulk |
| 5 | **all** plan feature keys for the catalog | per request, bulk |
| 6 | **all** Business feature toggles | per request, bulk |

= **6**, with the usage-authorization step adding **0** while unmetered.
There must be no per-feature query of any kind. In the Account frame, with
no Business selected, the resolver performs **0** queries and answers every
Business feature as not-applicable.

**Correctness outranks the budget.** If a future change makes the usage step
query the database, the implementation must keep the correct answer and
raise the ceiling in a follow-up contract — never silently skip step 11 to
stay under six.

---

## 7. The Account / Agency frame

```
Home                      user.home
Client accounts           customer.workspaces.index | customer.workspaces.show
Prospecting               customer.prospecting.index                    [isAgency()]
Settings                  group
  Plan & subscription     customer.subscriptions.index                  [canManageWorkspace() && is_customer]
  Advanced                group                                         [see below]
    Messaging provider    customer.channels.index
    Sender identities     customer.senderid.index
    Numbers               customer.numbers.index
    Keywords              customer.keywords.index
```

* **No Business-only product entry** (Inbox, Send, Campaigns, Contacts,
  Automations, Website, Get found, Results) may appear while no Business is
  selected. The frames never share entries; that is already true of
  `CustomerMenuBuilder` and must stay true.
* **The word *Workspace* never appears** in customer-facing text. The
  account frame says *Agency account* or *Account*; the plural is *Client
  accounts* for Agency and *Businesses* otherwise, which
  `CustomerContext::businessesNoun()` already returns.
* *Agency profile*, *Branding and white-label* and *Plan and client-account
  capacity* are named in the parent §8.4 sketch. Only
  `customer.workspaces.additional-business-slots.show` exists today as a
  distinct destination; agency profile and branding live inside
  `customer.workspaces.show`. **2A emits no leaf for a destination that does
  not exist** — same rule as §3.

### 7.1 The Advanced group's authority — deferred to Chat A

The Advanced group is gated today on `isAgency() && canManageWorkspace()`,
where `canManage()` is **owner OR active Admin**.

Chat A's branch (`agent/customer-experience-slice-3-messaging-provider-implementation`,
observed head `9dd47b3744d18f98f0c065ac2de91fae10790f9e`) adds a customer
permission whose own docblock states the advanced-settings surface
*"additionally requires authoritative Workspace OWNERSHIP
(`WorkspaceCandidate::$isOwner`), not `canManage()`, not plan tier, and not
admin membership."* It also moves the URI `…/channels` →
`…/settings/advanced` while **keeping the route name
`businesses.channels.`**, so the menu link survives by name.

**Locked:** when Chat A merges, Slice 2A adopts **Chat A's final rule**,
whatever it is at merge, and must not substitute `canManageWorkspace()` for
a deliberately tightened ownership test. The implementation lane re-reads
that branch at its own start and states the rule it found. If Chat A's rule
is owner-only, an agency-wide Admin sees no Advanced group, and a test must
assert exactly that.

---

## 8. Developers

**Locked: removed from customer navigation. Nothing else.**

Slice 2A deletes no route, controller, API key, webhook configuration or
datum. `customer.developer.*` (6 routes) stays registered and directly
reachable. Physical deletion belongs to retention slice 15.

A test must prove the entry is absent from every customer frame, for every
tier and role, including an Agency owner with the `developers` permission.

---

## 9. Debt this slice deliberately does not pay

Recorded so the next lane inherits facts, not surprises.

| Debt | Owner |
|---|---|
| *Inbox* still maps to the account-scoped `customer.chatbox.index`; a Business concept on an account-scoped route | **Slice 2B**, as one atomic move: route, controller, links, tests |
| *Inbox* therefore cannot be entitlement-gated on `conversations` in 2A | Slice 2B |
| *Blocked numbers* is presented under *Settings → Business* but its route stays account-scoped | a later Settings slice |
| No mobile navigation variant exists; §8.3's tab bar and sheet are net-new UI | the responsive slice (§11) |
| Three-level accordion styling is unproven — the Vuexy CSS/JS is exercised at two levels today | 2A implementation must verify visually and, if it fails, flatten rather than restyle |
| `customer.sub_accounts.*` (10), legacy `sms`/`mms` (9), `otp`/`viber`/`voice`/`whatsapp` (16) remain registered and unlinked | retention slices 13, 7a-c, 10 |

**Slice 2A must not change the chat-box URI, add Workspace/Business route
parameters to it, edit `ChatBoxController` or ChatBox models, or attempt any
tenancy remediation.**

---

## 10. Context, zero/one/many, switcher

Preserved exactly as `CustomerContext` and `CustomerContextResolver`
implement them today: zero Businesses, one Business, many Businesses,
view-as-client, selected Business, Account frame, Business frame.

* **No second context resolver.** No primary-Business guessing.
* **The switcher does not move.** `showsSwitcher()` (selectable count > 1,
  never while viewing-as) and its current sidebar/navbar placement stay.
* View-as narrowing stays with `ViewAsRouteClassification::allowsMenuEntry()`.
  Billing, funding, plan, staff, provider and delete entries remain
  **absent**, not disabled, while viewing.

---

## 11. Mobile

**Locked: Slice 2A implements no bottom tab bar and no sheet.** Current
sidebar and collapse mechanics remain. Responsive navigation belongs to the
later responsive slice.

2A must not make mobile behaviour worse: the added Messages and Settings →
Business groups increase nesting depth, and the implementation must confirm
the existing collapse behaviour still works at small widths before it is
considered done.

---

## 12. Implementation allowlist

Authorised for the future Slice 2A implementation:

* `app/Library/Navigation/CustomerMenuBuilder.php`
* `app/Library/Navigation/MenuEntitlements.php` *(new)*
* `app/Library/Entitlement/EntitlementManager.php` — **only** the additive
  snapshot seam of §6.3
* `app/Repositories/Contracts/WorkspaceEntitlementOverrideRepository.php`
  and its Eloquent implementation — **only** the additive bulk read
* `app/Repositories/Contracts/BusinessFeatureToggleRepository.php` and its
  Eloquent implementation — **only** the additive bulk read
* `app/Library/Navigation/CustomerContextResolver.php`,
  `CustomerContextSnapshot.php`, `CustomerShellComposer.php` — **only** if
  mechanically required to carry or pass the already-computed snapshot
* `resources/views/panels/sidebar.blade.php`, `navbar.blade.php`,
  `breadcrumb.blade.php` — **only** if mechanically required
* `resources/lang/en/locale.php` — **only** for genuinely new labels
* Focused tests under `tests/Feature/Navigation/**`, `tests/Feature/Workspace/**`

### Stop-list — not authorised

`app/Http/Controllers/Customer/ChatBoxController.php` · any ChatBox model ·
`routes/customer.php` · any migration · `DLRController` · any
messaging/provider code · billing internals · Website, GBP or Analytics
implementation · `public/**` · any SCSS · `package.json` /
`package-lock.json` · `docs/automation/AI-AUTONOMY-STATE.json` · any route
deletion · any Templates code.

If any of these appears mechanically unavoidable, the implementation lane
**stops and reports** rather than widening scope.

---

## 13. Test contract

| # | Test | Asserts |
|---|---|---|
| 1 | Core Business frame tree | exact ordered tree; **no *Get found*** |
| 2 | Growth Business frame tree | exact ordered tree; ***Get found* present** when entitled |
| 3 | Agency owner Account frame | Account tree; no Business-only entry; Advanced present per §7.1 |
| 4 | Agency-wide admin | per Chat A's final rule; Advanced present or absent accordingly |
| 5 | Agency staff, selected-Business scope | no Account frame; no Team/Account/Plan |
| 6 | Restricted Business actor | no Settings → Account; Billing only when `canManageBilling()` |
| 7 | Zero / one / many Businesses | frame, header label and switcher behaviour unchanged from today |
| 8 | View-as-client | narrowed tree; billing/plan/provider entries absent, not disabled |
| 9 | Account vs Business frame | the two frames share **no** entry |
| 10 | Every emitted URL resolves | every `MenuItem::$url` in every tree maps to a registered route |
| 11 | Nested active state | a third-level active leaf opens **both** ancestors |
| 12 | Developers absent | absent in every frame, tier and role, including a permitted Agency owner |
| 13 | No phantom leaves | no *Invoices* leaf, no *Team* leaf, no *Templates* leaf |
| 14 | Plan feature absent → leaf absent | for each of `automations`, `website_generation`, `google_business_profile_module` |
| 15 | Permission alone cannot expose a plan-excluded feature | permission granted + entitlement denied ⇒ **no entry** |
| 16 | Controller remains fail-closed | the same actor requesting the URL directly is still denied by the controller, 404 |
| 17 | Contacts is never hidden by tier | visible for Core, Growth and Agency |
| 18 | **Query budget** | menu entitlement resolution ≤ **6** queries; asserted by counting, not by inspection |
| 19 | **No N+feature growth** | the count in 18 is identical when the checked-feature list is doubled |
| 20 | Account frame costs nothing | 0 entitlement queries when no Business is selected |
| 21 | No raw locale keys | no rendered label matches `locale.` |
| 22 | No *Workspace* in customer text | after Slice 1; asserted on rendered customer shell output |
| 23 | Shell behaviour after Slice 1 | vertical and horizontal shell both render the tree |
| 24 | No ChatBox route change | `customer.chatbox.index` URI is byte-identical to base |
| 25 | No mobile tab bar added | no new bottom-navigation markup in the shell |

---

## 14. Dependencies

**This contract may merge now.**

**Implementation may start only after both:**

1. Chat A's `agent/customer-experience-slice-3-messaging-provider-implementation`
   merges — it changes `PlatformFeature`, adds the advanced-settings
   permission, and fixes the Advanced group's authority rule (§7.1).
2. Redesign **Slice 1 (terminology)** merges — 2A's tree assumes the
   vocabulary Slice 1 establishes, and on this base
   `resources/views/customer` still contains *Workspace* ×208 and
   *Originator* ×102, with *Sending Server* ×28 and *Sub Account* ×15 in
   `en/locale.php`.

The theme-assets predecessor is already merged at `b87b669d`. No further
blockers are invented here.

**Sequencing note.** Slice 2B (Business-scoped Conversations) depends on 2A
only for the label and grouping; it can be contracted in parallel and must
be implemented after 2A to avoid two lanes editing `CustomerMenuBuilder`.

---

## 15. Size and coherence

Slice 2A is **one coherent pull request**: one builder restructure, one
additive entitlement seam, two additive bulk repository reads, one value
object, label changes, and a focused test file. It has a single acceptance
question — *does a menu entry appear exactly when route, permission, access
and entitlement all allow it, within six queries?* — and everything in the
allowlist serves it.
