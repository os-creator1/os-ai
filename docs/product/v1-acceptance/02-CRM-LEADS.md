# V1 Final Acceptance 02 — CRM / Leads / Contacts / Opportunities

Base: `origin/main` @ `44976df8d809e45b4a552c9375c8524eac7cfe0e`. Branch `agent/v1-acceptance-02-crm-leads`.
Database: `ultimatesms_testing_v1_acceptance_crm`.

**V1 CRM/LEADS ACCEPTANCE: ACCEPTED** (CRM-owned scope; see the external Website-lead blocker below)

All CRM-owned blockers are fixed. One integration is **BLOCKED / EXTERNAL**: the legacy Website-builder form
lead path for a multi-Location Business (handed to V1 Acceptance 04 Website + Forms). It is NOT claimed accepted.

New journey tests: `tests/Feature/V1Acceptance/CrmLeads/` (36 tests). Existing suites are reused as evidence.

## Defects found and fixed

| # | Defect | Fix |
|---|---|---|
| D1 | **Cross-Business write via custom fields.** `updateOrCreateFieldsFromRequest()` looked a posted field uid up globally and mass-assigned `contact_group_id`: Business B could rewrite, re-home, or plant fields in Business A's list. | Field lookup scoped to the list; `contact_group_id` forced to the list (`EloquentContactsRepository`). |
| D2 | **Selected-Location staff saw foreign-Location records in lists.** People directory, profile by uid, "Add opportunity" picker, CRM board (cards/counts/values/search), bulk actions (delete/subscribe/unsubscribe/copy/move) and group CSV export ignored the Location ACL (only single-record actions were guarded). | New `CrmLocationScope` (thin adapter over `LocationAccessGuard`, pushes reach into SQL; NULL location unchanged per Contract 08B). Wired into `ContactDirectory`, `CrmBoard`, `ContactDirectoryController`, `CrmOpportunitiesController`, `ContactsController` bulk + export. |
| D3 | **Global Search starved authorised results.** Location filter ran after a 4×limit window, so newer foreign-Location matches hid reachable ones. | Reach pushed into SQL before the limit (`ContactSearchSource`, `OpportunitySearchSource`). |
| D4 | **A deal for a located Contact in a multi-Location Business was Business-wide** (NULL), reachable by any Location's staff. | `CrmOpportunityService::create()` uses the Contact's own proven Location; single-Active-Location rule only for Location-less Contacts. |
| D5 | **Re-typing a number in another format silently overwrote and re-subscribed an existing Contact** (unique rule saw the raw value). | Per-list unique rule validates the normalized number (`createContactFromRequest`). |
| D7 | **Contacts were not Location-bound (Blueprint §5/§10).** Add Contact created Location-less Contacts in multi-Location Businesses and identity was one-phone-per-list. | Add Contact now requires exactly one valid Location: one reachable Active Location → automatic; several → the actor must pick one of THEIR reachable Locations (selector on the form); forged/unreachable → refused; zero usable → creation refused. Identity is Business + Location + normalized phone (same Location, any list/formatting → same Contact, refused; other Location or Business → separate Contact). Uses `LocationAccessGuard` via `CrmLocationScope::selectableLocations()`. |
| D8 | `countContacts()` returned a Business-wide subscriber count to Location-restricted staff. | Location reach pushed into the count query. |
| D6 | Staff with `update_contact` could not edit a Contact (`customer_id = acting user id` filter). | Scoped by the already-tenancy-resolved group (+ Location check) instead. |

## Matrix

| Requirement | Result | Evidence | Notes |
|---|---|---|---|
| A. Owner creates Contact via real path | ACCEPTED | `ContactCreationJourneyTest` | Business/customer/group from the list; stored normalized |
| A. Location resolution | FIXED + ACCEPTED (D7) | `test_the_owner_creates…`, `test_with_several_active_locations…`, `test_restricted_staff_can_only_choose…` | Always exactly one valid Location, or refusal |
| A. Foreign ids not injectable | ACCEPTED | `test_forged_business_location_group…`, `test_another_business_cannot_add…` | |
| A. Dedup | FIXED + ACCEPTED (D5, D7) | `test_one_phone_number_is_one_contact_per_list…` (same list), `test_identity_is_location_local…` | Business + Location + normalized phone; Forms identity is the same rule (existing `FormSubmissionServiceTest`) |
| A. uid stable | ACCEPTED | `test_the_contact_uid_is_stable…` | |
| B. Lead → Contact + Opportunity | ACCEPTED | `LeadIngestionJourneyTest` (Forms V1 public link) | Both at the deployment's Location; not subscribed; no pipeline → lead kept |
| B. Replay / idempotency | ACCEPTED | same-token replay → 1 submission/contact/deal | A genuinely new render = new inquiry (same Contact, own deal) |
| B. Website-builder form lead | **BLOCKED — handed to V1 Acceptance 04 Website + Forms** | code inspection: no Location input anywhere in the Website form flow (`WebsiteForm`/page/snapshot/`WebsiteFormSubmissionService`) | A Business with exactly one Active Location resolves it deterministically; a multi-Location Business yields a Location-less lead, which Blueprint forbids. No IP/GPS/default guessing was added. Not accepted here |
| C. Create / stage / won-lost / history | ACCEPTED | existing `ContactsCrmOpportunityPipelineAcceptanceTest`, `CrmOpportunityLifecycleTest`, `CrmOpportunitiesHttpTest` | Value (`value_minor`) and status are separate columns |
| C. Foreign pipeline/stage/opportunity fail closed | ACCEPTED | existing `CrmOpportunitiesHttpTest`, `CrmPipelineCustomizationTest`; journey `test_staff_cannot_open_or_change_a_foreign_deal…` | |
| D. Tags create/attach/detach/archive, cross-Business, forged identity | ACCEPTED | existing `TagManagerTest` (615 lines), `ContactTagsHttpTest` | Canonical `TagManager`; membership Business-wide, Contact Location re-derived |
| E. Custom fields | FIXED + ACCEPTED (D1) | `ContactCreationJourneyTest` E tests | Implemented as per-list "Manage fields"; values per Contact; shown on profile |
| F. Owner reaches all Locations | ACCEPTED | `LocationAuthorityJourneyTest::test_the_owner_reaches…` | |
| F. Selected staff: A yes, B no (URL/list/picker/board/bulk/export) | FIXED + ACCEPTED (D2, D4, D6) | `LocationAuthorityJourneyTest` (16) | |
| F. Contact transfer between Locations | DEFERRED | — | Not exposed by the product; not invented |
| G. Global Search | FIXED + ACCEPTED (D3) | journey + `GlobalSearchTest` | Authorised only; no leak of foreign titles/urls |
| G. Contact timeline / events | ACCEPTED | existing `ContactActivityTimelineTest`; opportunity history asserted once per event in the pipeline acceptance test | Timeline lives in Conversations; deal history on the deal |
| H. Subscriber count | FIXED + ACCEPTED (D8) | `test_the_subscriber_count_honours_location_reach…` | Owner all; Selected staff reachable only; forged groups ignored |
| H. Bulk / list operations | FIXED + ACCEPTED (D2) | journey bulk tests | Actions that exist: contact subscribe/unsubscribe/delete/copy/move, group enable/disable/delete, export. Selection re-derived server-side; foreign/stale ids dropped. No other bulk actions added |
| I. Cross-Business / Location / guessed ids | ACCEPTED | `ContactCreationJourneyTest`, `LocationAuthorityJourneyTest`, existing `ContactsSecurityTest`, `BusinessScopedCrmTest` | |
| I. View As / Agency / Platform Owner | ACCEPTED | existing `AgencyViewAsOperationalAuthorityTest`, `FormsHttpTest` View As; `CrmLocationScope` delegates to `LocationAccessGuard` which honours View As | Agency relationship alone confers nothing; Platform Owner untouched |

## Deferred / external

1. **BLOCKED (external):** Website-builder form leads in a multi-Location Business (above) → Acceptance 04.
2. **Legacy group Contacts datatable** (`searchContact`) filters by `customer_id = acting user`: owner sees the list, staff see it empty (fails closed); staff use the person-first directory.
3. **Contact import (CSV/paste), the public opt-in form and the API** still assign the single-Active-Location-or-NULL rule; only the in-app Add Contact flow was corrected here.
4. **Contact transfer between Locations** is not exposed by the product; not invented.
5. Account/Shell lane: when it rebases after this merges it must reuse `CrmLocationScope`, not add parallel `ContactDirectory` authority.

## Evidence

New: LocationAuthorityJourneyTest 16, ContactCreationJourneyTest 11, LeadIngestionJourneyTest 6 — all pass.
Regression of touched code (all pass): `Crm` 103, `Contacts` 51, `Search` 15, `BusinessScopedCrmTest` 8, `ContactsSecurityTest` 20, `Forms` 144, `WebsiteFormTest` 23. No baseline failures encountered, so no pristine-main comparison was needed.
