# V1 Authority Traceability Matrix

**Status:** Documentation only. Mechanically maps every major V1 Blueprint
area to its authoritative source(s), so a future implementation agent
cannot pick whichever old RFC or current code path happens to be easiest.
Companion to [`V1-MASTER-PRODUCT-BLUEPRINT.md`](./V1-MASTER-PRODUCT-BLUEPRINT.md)
("the Blueprint") and [`../rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md`](../rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md)
("the Addendum").

**Column key.** *Alignment*: ALIGNED (current `main` matches the target) /
PARTIALLY ALIGNED / LEGACY-MIGRATION REQUIRED (current `main` implements the
superseded model) / NOT YET IMPLEMENTED. *Class*: ARCHITECTURE-LOCKED
(Addendum) / PRODUCT-LOCKED (Blueprint/contract) / IMPLEMENTATION-DEFAULT /
COMMERCIAL-CONFIGURATION / V2. Where current-`main` evidence was not
mechanically re-verified in this pass beyond what earlier session audits
already established, that is stated rather than guessed.

| # | Domain | Blueprint § | Addendum § | RFC/contract § (non-superseded or as noted) | Current-main evidence | Alignment | Class | Implementation implication |
|---|---|---|---|---|---|---|---|---|
| 1 | Workspace/Business/Location cardinality | §3 | §1 | RFC-003 §7.1/§27 — **superseded** | `Workspace::businesses() HasMany`; `businesses.workspace_id` has no unique constraint (migrations `2026_07_30_120004/5/6`) | LEGACY-MIGRATION REQUIRED | ARCHITECTURE-LOCKED | DB 1:1 enforcement is the *last* migration step (Addendum §18), not first |
| 2 | Agency↔Client Workspace relationship | §2, §28 | §2 | RFC-003 §27 — **superseded**; no replacement table exists yet | No `agency_client`/`managed_workspace`/`workspace_relationship` table found on `main` | NOT YET IMPLEMENTED | ARCHITECTURE-LOCKED | First implementation slice per Addendum §18 step 1 — everything else in §28 depends on it |
| 3 | Global User identity / isolated authorization | §3 | §3 | RFC-003 §7.2 (not superseded) | `users` global, `workspace_memberships` per-Workspace — matches | ALIGNED | ARCHITECTURE-LOCKED | No change needed |
| 4 | Staff Location ACL | §4, §26 | §4 | Navigation Contract §3.3 (Location is not a navigation tenant — compatible) | Only `WorkspaceMembership.business_access_scope` (All\|Selected) + `workspace_membership_businesses` exists; no `location_access_scope`/`workspace_membership_locations` | NOT YET IMPLEMENTED | ARCHITECTURE-LOCKED | New sibling authority to Business tenancy checks, per Addendum §4's explicit fail-closed pattern |
| 5 | Operational Location ownership (Contact/Opportunity/etc.) | §5, §9–§16 | §5 | — | `Business::locations()`/`primaryLocation()` exist; per-record `location_id` presence not re-verified per model in this pass | PARTIALLY ALIGNED | ARCHITECTURE-LOCKED | Verify `location_id` is NOT NULL (or backfill-nullable-only) on every listed record type before relying on it |
| 6 | Contacts | §10 | §5 | — | `app/Models/Contacts.php`, `ContactsController` exist (legacy Ultimate SMS base) | PARTIALLY ALIGNED | PRODUCT-LOCKED | Confirm Location scoping and Location-local-only dedup; cross-Location merge must stay excluded (§34) |
| 7 | Opportunities | §9 | §5 | — | `app/Models/Opportunity.php`, `OpportunityRun.php`, `OpportunityController` exist | PARTIALLY ALIGNED | PRODUCT-LOCKED | Confirm pipeline stages match §9's Photo Booth default and that Booked is a badge, not a stage |
| 8 | Conversations | §11 | §5 | Navigation Contract §11.2 ("an inbound event exists but... `ChatBox` is user-scoped, not Business-scoped") | `app/Models/ChatBox.php`, `ChatBoxMessage.php`, `ChatBoxController` — **confirmed user-scoped, not Business/Location-scoped**, per Navigation Contract §11.2's own finding | LEGACY-MIGRATION REQUIRED | PRODUCT-LOCKED | Conversation must become Location-scoped before any guided automation recipe depending on it can ship (Navigation Contract §11.2 already says this independently) |
| 9 | Calendar / Booking | §12 | §5 | — | No `BookingType`/`Appointment`/availability model found under `app/Models` in this pass | NOT YET IMPLEMENTED | PRODUCT-LOCKED | Net-new module; build Location-bound from the start, not retrofitted |
| 10 | Automations | §13 | §5 | Navigation Contract §11 (recipe readiness — governs UI exposure only) | `app/Models/Automation.php`, `AutomationWorkflow(Node/Edge/Version)`, `AutomationEnrollment`, `AutomationExecution`, `AutomationStepRun` exist | PARTIALLY ALIGNED | PRODUCT-LOCKED | Confirm run-level Location binding (Addendum §5) and paid-side-effect recheck (Addendum §9) at execution, not enrollment |
| 11 | Website | §14 | §13 | — | `app/Models/Website.php`, `WebsitePage.php`, `WebsiteAsset.php`, `WebsiteRevision.php` exist | PARTIALLY ALIGNED | PRODUCT-LOCKED | Confirm one-website-per-Business and Location-page structure match §14; confirm lead capture resolves Location deterministically (Addendum §13) |
| 12 | SEO | §15 | — | — | Only `Keywords.php`/`KeywordController` found; no citations/reviews/technical-SEO models found in this pass | NOT YET IMPLEMENTED (beyond keywords) | PRODUCT-LOCKED | Full local-SEO module (Growth+) is largely net-new |
| 13 | Forms / Questionnaires | §16 | §5, §13 | — | `QuestionPack.php` exists; no generic `FormSubmission` model found in this pass | PARTIALLY ALIGNED | PRODUCT-LOCKED | Confirm submission-time Location resolution exists or must be added (Addendum §13) |
| 14 | Packages & Products | §17 | §14 | — | No `Package`/`Product` model found in this pass | NOT YET IMPLEMENTED | PRODUCT-LOCKED | Net-new; build Business-wide catalog + Location enable/override + immutable snapshot from the start |
| 15 | Payments & Contracts | §18 | §12 | — | `app/Models/Invoices.php`, `InvoiceController`, `PaymentController` exist; no `Proposal`/`Contract`/e-signature model found in this pass | PARTIALLY ALIGNED | PRODUCT-LOCKED | Invoicing/payment exists; Proposal/Contract/e-signature layer is largely net-new |
| 16 | Messaging / phone numbers | §19 | §7 (payer via wallet), §15 | Navigation Contract §10 (customer-facing flow/copy, not superseded) | `NumberController`, `CampaignController`, `SenderIDController`, `DLRController` (Telnyx-era Ultimate SMS base) exist | PARTIALLY ALIGNED | PRODUCT-LOCKED | Confirm one-number-per-Location and inbound-routing-by-number match §19; central Telnyx already the provider |
| 17 | Usage wallet | §20 | §9 | RFC-005 (base wallet mechanism, not superseded) | `business_usage_wallets` keyed on `business_id` (confirmed this session) | ALIGNED | ARCHITECTURE-LOCKED | Already Business-scoped and 1:1-compatible; no schema change needed for this item alone |
| 18 | AgencyRebill | §20, §28 | §10 | RFC-005 §16 — **status updated, not reversed** | `PayerType::AgencyRebill` exists, documented inert; no `agency_rebill` consent rule in RFC-005 §16 | NOT YET IMPLEMENTED (deliberately inert) | ARCHITECTURE-LOCKED | Blocked on item 2 (Agency↔Client relationship) per Addendum §10's own invariant |
| 19 | Account lifecycle (Trial/Active/Grace/Locked/Inactive) | §27 | §7 | RFC-004 (entitlement base, not superseded) | `WorkspacePlanAssignmentStatus` = `Active\|Inactive\|Suspended` only; no `grace_started_at`/`locked_at` | LEGACY-MIGRATION REQUIRED | ARCHITECTURE-LOCKED | Extend `CustomerAccountAccessResolver`'s inputs, do not add a second resolver (Addendum §7) |
| 20 | Plans / entitlements | §21 | — | RFC-004 §12/§26 (tier semantics, not superseded except §13/§17 slot model) | `EntitlementManager`, `workspace_plan_catalog` exist | PARTIALLY ALIGNED | PRODUCT-LOCKED + COMMERCIAL-CONFIGURATION (pricing) | Nth-Business slot logic (RFC-004 §13/§17) is the superseded part; capability gating itself is reusable |
| 21 | Niche blueprints | §22 | §16 | — | Blueprint/template installation evidence not re-verified in this pass | NOT YET IMPLEMENTED / evidence not re-verified | PRODUCT-LOCKED | Verify existing niche/template installation code (if any) against §22's version/provenance/no-silent-update rules before reuse |
| 22 | AI COO | §23 | — | — | Not inspected in this pass | NOT YET IMPLEMENTED / evidence not re-verified | PRODUCT-LOCKED | Net-new or evidence pending; must respect §23's approval-before-execution rule regardless of implementation source |
| 23 | Agency product (Clients/SaaS Plans/White Label) | §28 | §2, §8, §10 | Navigation Contract §13.3 (Home shape reusable; §3.1 tenancy mapping **superseded**, §35) | Agency = `WorkspacePlanTier::Agency` (plan tier only, confirmed this session); no Client-management UI evidence found | LEGACY-MIGRATION REQUIRED | ARCHITECTURE-LOCKED + PRODUCT-LOCKED | Do not build Agency "Clients" screen against the old Business-list-in-one-Workspace model |
| 24 | Agency Outreach | §29 | — | `OutreachController`, `Agency*Prospect*` models exist (confirmed this session) | Prospecting/outreach subsystem exists, scoped by `workspace_id`, unrelated to client-management | PARTIALLY ALIGNED | PRODUCT-LOCKED | Existing subsystem is reusable as-is; keep it separate from Client management (item 23) as §29 requires |
| 25 | Platform Owner | §30 | — | Navigation Contract §13.5 (Home shape, not superseded) | Admin surfaces exist in Ultimate SMS base; full §30 sidebar not re-verified in this pass | PARTIALLY ALIGNED / evidence not re-verified | PRODUCT-LOCKED | Confirm existing admin navigation against §30's exact sidebar before extending it |
| 26 | Domain events | §31 | — | — | `App\Events\*` exist for specific flows (e.g. `WorkspaceMembershipBusinessUnassigned`, `BusinessReassignedToWorkspace`, `BusinessPayerChanged`, confirmed this session); no single canonical cross-module envelope confirmed | PARTIALLY ALIGNED | IMPLEMENTATION-DEFAULT (envelope shape) + PRODUCT-LOCKED (that one must exist) | Envelope's exact field/serialization shape is an implementation default; that modules must share one is product-locked |
| 27 | Security / audit | §32 | §4, §7, §11 | — | `CustomerAccountAccessResolver`, `AccountFrameAccess`, `ViewAsManager` exist (confirmed this session) | PARTIALLY ALIGNED | ARCHITECTURE-LOCKED | View As's same-Workspace limitation (item 2/23) is the main gap; 2FA/tenancy patterns otherwise reusable |
| 28 | Retention | §33 | §7 | — | Legacy `Subscription` model has a "grace" reference unrelated to the new lifecycle (confirmed this session) | NOT YET IMPLEMENTED (six-month recoverable window as specified) | PRODUCT-LOCKED | Do not treat the legacy Subscription grace concept as satisfying §27/§33 |
| 29 | Signup / provisioning | §6 | §1 | — | `BusinessOnboardingController`, `BusinessManager::applyIdentity()`, `WorkspaceManager::resolveLegacyOnboardingWorkspace()` exist | LEGACY-MIGRATION REQUIRED | ARCHITECTURE-LOCKED | Legacy onboarding reuses an existing Workspace for a second Business under the same owner — must become "always a new Workspace" per §3/Addendum §1 |
| 30 | Global navigation | §7, §25 | — | Navigation Contract §8 (target navigation, largely reusable), §3.1–§3.2 (tenancy wording — **superseded**, §35) | Not re-verified pixel-for-pixel in this pass | PARTIALLY ALIGNED | PRODUCT-LOCKED | Reuse Navigation Contract's screen/label work; do not reuse its Workspace:Business tenancy assumptions |
| 31 | V1/V2 boundary | §34 | §1, §13 | — | N/A (product scope statement) | N/A | PRODUCT-LOCKED | Any slice proposing a V2 item as V1 scope must be rejected at planning time |

## Notes on evidence confidence

Rows 1–4, 6 (partially), 7 (partially), 16 (payer/wallet piece), 17, 18, 19,
23, 27, 29 rest on direct `git show`/`git grep` evidence gathered across this
session's architecture audit and this pass's model/controller existence
checks. Rows 12, 14, 15 (Proposal/Contract), 21, 22, 25, 26, 28, 30 reflect a
lighter existence-check pass (model/controller name search only) and should
be re-verified with a full read before an implementation slice depends on
their exact current shape — they are marked accordingly rather than asserted
with false precision.

## Cross-check against the Addendum's own transition order

Rows 2, 4, 18, 23 above are exactly Addendum §18 steps 1–3 and the
AgencyRebill dependency it names — this matrix does not introduce a
different order than the Addendum already locked; see
[`V1-IMPLEMENTATION-ROADMAP.md`](./V1-IMPLEMENTATION-ROADMAP.md) for the
full dependency-ordered sequencing built from this table.
