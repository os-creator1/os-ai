# V1 Final Acceptance 02 — CRM / Leads / Contacts / Opportunities

Base: `origin/main` @ `44976df8d809e45b4a552c9375c8524eac7cfe0e`. Branch `agent/v1-acceptance-02-crm-leads`.
Database: `ultimatesms_testing_v1_acceptance_crm`.

**V1 CRM/LEADS ACCEPTANCE: ACCEPTED**

Zero unresolved V1 blockers in scope. Five real defects were found by the journeys and fixed (below); two
observations are recorded as DEFERRED (not blockers, no product behaviour was invented).

New journey tests: `tests/Feature/V1Acceptance/CrmLeads/` (33 tests). Existing suites are reused as evidence.

## Defects found and fixed

| # | Defect | Fix |
|---|---|---|
| D1 | **Cross-Business write via custom fields.** `updateOrCreateFieldsFromRequest()` looked a posted field uid up globally and mass-assigned `contact_group_id`: Business B could rewrite, re-home, or plant fields in Business A's list. | Field lookup scoped to the list; `contact_group_id` forced to the list (`EloquentContactsRepository`). |
| D2 | **Selected-Location staff saw foreign-Location records in lists.** People directory, profile by uid, "Add opportunity" picker, CRM board (cards/counts/values/search), bulk actions (delete/subscribe/unsubscribe/copy/move) and group CSV export ignored the Location ACL (only single-record actions were guarded). | New `CrmLocationScope` (thin adapter over `LocationAccessGuard`, pushes reach into SQL; NULL location unchanged per Contract 08B). Wired into `ContactDirectory`, `CrmBoard`, `ContactDirectoryController`, `CrmOpportunitiesController`, `ContactsController` bulk + export. |
| D3 | **Global Search starved authorised results.** Location filter ran after a 4×limit window, so newer foreign-Location matches hid reachable ones. | Reach pushed into SQL before the limit (`ContactSearchSource`, `OpportunitySearchSource`). |
| D4 | **A deal for a located Contact in a multi-Location Business was Business-wide** (NULL), reachable by any Location's staff. | `CrmOpportunityService::create()` uses the Contact's own proven Location; single-Active-Location rule only for Location-less Contacts. |
| D5 | **Re-typing a number in another format silently overwrote and re-subscribed an existing Contact** (unique rule saw the raw value). | Per-list unique rule validates the normalized number (`createContactFromRequest`). |
| D6 | Staff with `update_contact` could not edit a Contact (`customer_id = acting user id` filter). | Scoped by the already-tenancy-resolved group (+ Location check) instead. |

## Matrix

| Requirement | Result | Evidence | Notes |
|---|---|---|---|
| A. Owner creates Contact via real path | ACCEPTED | `ContactCreationJourneyTest` | Business/customer/group from the list; stored normalized |
| A. Location resolution | ACCEPTED | `test_the_owner_creates…`, `test_a_location_is_never_guessed…` | Exactly one Active Location → that one; zero/several → NULL (Business-wide), never guessed |
| A. Foreign ids not injectable | ACCEPTED | `test_forged_business_location_group…`, `test_another_business_cannot_add…` | |
| A. Dedup | FIXED + ACCEPTED (D5) | `test_one_phone_number_is_one_contact_per_list…` | Rule is one phone per list; Forms identity is Location-local (existing `FormSubmissionServiceTest`) |
| A. uid stable | ACCEPTED | `test_the_contact_uid_is_stable…` | |
| B. Lead → Contact + Opportunity | ACCEPTED | `LeadIngestionJourneyTest` (Forms V1 public link) | Both at the deployment's Location; not subscribed; no pipeline → lead kept |
| B. Replay / idempotency | ACCEPTED | same-token replay → 1 submission/contact/deal | A genuinely new render = new inquiry (same Contact, own deal) |
| B. Website-builder form | ACCEPTED | existing `WebsiteFormTest` (23) | Business-level, no Location of its own: Location = single-active-or-NULL (consistent for Contact and deal after D4) |
| C. Create / stage / won-lost / history | ACCEPTED | existing `ContactsCrmOpportunityPipelineAcceptanceTest`, `CrmOpportunityLifecycleTest`, `CrmOpportunitiesHttpTest` | Value (`value_minor`) and status are separate columns |
| C. Foreign pipeline/stage/opportunity fail closed | ACCEPTED | existing `CrmOpportunitiesHttpTest`, `CrmPipelineCustomizationTest`; journey `test_staff_cannot_open_or_change_a_foreign_deal…` | |
| D. Tags create/attach/detach/archive, cross-Business, forged identity | ACCEPTED | existing `TagManagerTest` (615 lines), `ContactTagsHttpTest` | Canonical `TagManager`; membership Business-wide, Contact Location re-derived |
| E. Custom fields | FIXED + ACCEPTED (D1) | `ContactCreationJourneyTest` E tests | Implemented as per-list "Manage fields"; values per Contact; shown on profile |
| F. Owner reaches all Locations | ACCEPTED | `LocationAuthorityJourneyTest::test_the_owner_reaches…` | |
| F. Selected staff: A yes, B no (URL/list/picker/board/bulk/export) | FIXED + ACCEPTED (D2, D4, D6) | `LocationAuthorityJourneyTest` (16) | |
| F. Contact transfer between Locations | DEFERRED | — | Not exposed by the product; not invented |
| G. Global Search | FIXED + ACCEPTED (D3) | journey + `GlobalSearchTest` | Authorised only; no leak of foreign titles/urls |
| G. Contact timeline / events | ACCEPTED | existing `ContactActivityTimelineTest`; opportunity history asserted once per event in the pipeline acceptance test | Timeline lives in Conversations; deal history on the deal |
| H. Bulk / list operations | FIXED + ACCEPTED (D2) | journey bulk tests | Actions that exist: contact subscribe/unsubscribe/delete/copy/move, group enable/disable/delete, export. Selection re-derived server-side; foreign/stale ids dropped. No other bulk actions added |
| I. Cross-Business / Location / guessed ids | ACCEPTED | `ContactCreationJourneyTest`, `LocationAuthorityJourneyTest`, existing `ContactsSecurityTest`, `BusinessScopedCrmTest` | |
| I. View As / Agency / Platform Owner | ACCEPTED | existing `AgencyViewAsOperationalAuthorityTest`, `FormsHttpTest` View As; `CrmLocationScope` delegates to `LocationAccessGuard` which honours View As | Agency relationship alone confers nothing; Platform Owner untouched |

## Deferred observations (not blockers)

1. **Legacy group Contacts datatable** (`ContactsController::searchContact`) filters by `customer_id = acting user`: owner sees the list, staff see it empty (fails closed). Staff work through the person-first directory. Not changed.
2. **Manually added Contact in a multi-Location Business is Business-wide (NULL Location)**; V1 has no Location picker on "Add contact". Needs a product decision; not invented here. Also `countContacts` returns an aggregate subscriber count not narrowed by Location.

## Evidence

New: LocationAuthorityJourneyTest 16, ContactCreationJourneyTest 11, LeadIngestionJourneyTest 6 — all pass.
Regression of touched code (all pass): `Crm` 103, `Contacts` 51, `Search` 15, `BusinessScopedCrmTest` 8, `ContactsSecurityTest` 20, `Forms` 144, `WebsiteFormTest` 23. No baseline failures encountered, so no pristine-main comparison was needed.
