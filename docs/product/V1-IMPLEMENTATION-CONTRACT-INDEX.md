# V1 Implementation Contract Index

**Status:** Documentation only. Index over the 14 implementation contracts
in `docs/product/implementation-contracts/`, built from
[`V1-IMPLEMENTATION-ROADMAP.md`](./V1-IMPLEMENTATION-ROADMAP.md)'s own
wave plan. Does not authorize implementation.

| # | Contract | Prerequisite(s) | Shared files/tables (conflict-relevant) | Lane | Risk | Effort | Parallel-safe peers | Merge barrier | Prompt location |
|---|---|---|---|---|---|---|---|---|---|
| 01 | [Agency↔Client Workspace relationship](./implementation-contracts/01-AGENCY-CLIENT-WORKSPACE-RELATIONSHIP.md) | none | `AppServiceProvider.php` (additive line) | A | Low | M | 02, 03, 06 | none | Contract 01 §18 |
| 02 | [Location ACL foundation](./implementation-contracts/02-LOCATION-ACL-FOUNDATION.md) | none | `AppServiceProvider.php` (additive line) | B | Low | M | 01, 03, 06 | none | Contract 02 §18 |
| 03 | [Account lifecycle Grace/Locked](./implementation-contracts/03-ACCOUNT-LIFECYCLE-GRACE-LOCKED.md) | none | `CustomerAccountAccessResolver.php` (Contract 05 builds on this same edit) | C | Medium | M | 01, 02, 06 | none | Contract 03 §18 |
| 04 | [Cross-Workspace Agency View As](./implementation-contracts/04-CROSS-WORKSPACE-AGENCY-AUTH-VIEW-AS.md) | 01 (hard) | `AgencyClientRelationshipManager.php` (adds one method) | A | Critical | L | 02, 03 (once 01 lands) | 01 merged | Contract 04 §18 |
| 05 | [Agency non-payment composition](./implementation-contracts/05-AGENCY-NONPAYMENT-COMPOSITION.md) | 01, 03 (hard) | `CustomerAccountAccessResolver.php` (extends Contract 03's edit — serialize with it, not concurrent) | C | High | M | 02, 04 (once prereqs land) | 01 + 03 merged | Contract 05 §18 |
| 06 | [Conversation Location scoping](./implementation-contracts/06-CONVERSATION-LOCATION-SCOPING.md) | none | none shared with 01–05 | D | Medium | L | 01, 02, 03 | none | Contract 06 §18 |
| 07 | [Client Workspace provisioning](./implementation-contracts/07-CLIENT-WORKSPACE-PROVISIONING.md) | 01, 04 (hard) | none new | A | Medium | M | independent product modules only | 01 + 04 merged | Contract 07 §18 |
| 08A | [Agency Clients UI](./implementation-contracts/08A-AGENCY-CLIENTS-UI.md) | 07 (hard, non-concurrent — Wave 3a→3b) | none | B | Low | M | 08B | 07 merged and stable | Contract 08A §18 |
| 08B | [First Location ACL consumer wave](./implementation-contracts/08B-LOCATION-ACL-CONSUMER-WIRING.md) | 02, 06 (hard) | `ContactsController.php`, `OpportunityController.php`, `CrmOpportunitiesController.php`, `ChatBoxController.php` | B | High | XL | 08A | 02 + 06 merged | Contract 08B §18 |
| 09 | [AgencyRebill activation](./implementation-contracts/09-AGENCYREBILL-ACTIVATION.md) | 01 (hard), 05 (hard — upgraded from "recommended" in the contract remediation pass) | `BillingProfileManager.php`, `UsageBillingCheckoutManager.php`, `UsageWalletManager.php`, `PaymentInstrumentManager.php`, `EffectivePayer.php` (new `EffectivePayerResolver`) | C | Critical | L | 08A, 08B | 01 + 05 merged | Contract 09 §18 |
| 10 | [Agency data migration](./implementation-contracts/10-AGENCY-DATA-MIGRATION.md) | 01, 02, 04, 07, 08A, **09** (all hard) | `WorkspaceManager::reassignBusiness()`, `WorkspaceMembershipBusinessRepository` (read/called, not modified) | SERIAL ONLY | Critical | XL | none — serial | every listed prerequisite merged | Contract 10 §18 |
| 11 | [Retire additional-business-slots](./implementation-contracts/11-RETIRE-ADDITIONAL-BUSINESS-SLOTS.md) | 10 (hard, run + verified) | `EntitlementManager.php` (read only) | SERIAL ONLY | Medium | M | independent product modules | Contract 10 run and verified | Contract 11 §18 |
| 12 | [Non-Agency multi-Business migration](./implementation-contracts/12-NONAGENCY-MULTIBUSINESS-MIGRATION.md) | 11 (hard) | `WorkspaceManager::reassignBusiness()` (read/called) | SERIAL ONLY | High | M | independent product modules | 11 merged | Contract 12 §18 |
| 13 | [Enforce Workspace:Business 1:1](./implementation-contracts/13-ENFORCE-WORKSPACE-BUSINESS-ONE-TO-ONE.md) | 10, 12 (both, verified zero-violation) | `businesses` table DDL | SERIAL ONLY | Critical | S | independent product modules | 10 + 12 verified zero-violation | Contract 13 §18 |
| 14 | [Dead-model cleanup](./implementation-contracts/14-DEAD-MODEL-CLEANUP.md) | 13 (hard), implicitly 1–12 | every symbol/table named in its own §3 | A or B | Low | M | independent product modules | 13 merged, full series complete | Contract 14 §18 |

**Independent product-module slices** (Roadmap §"Slices 15–18") have no
contract in this factory — they were explicitly out of the 14-priority
scope this task requested, and remain available as filler for any lane
with spare capacity in any wave, per the Roadmap's own note.

## Wave mapping (for reference, full detail in the Roadmap)

- **Wave 1:** 01, 02, 03, 06 (concurrent, no real conflicts).
- **Wave 2:** 04 (needs 01), 05 (needs 01+03).
- **Wave 3a:** 07 (needs 01+04), 09 (needs 01 and, as of the contract remediation pass, hard-requires 05 too — both are already Wave 2 prerequisites, so this wave placement was already correct) — concurrent with each other.
- **Wave 3b:** 08A (needs 07 merged, not concurrent with it).
- **Wave 3b/4 overlap:** 08B (needs 02+06, independent of the Agency-provisioning chain).
- **Wave 4:** 10 (needs 01, 02, 04, 07, 08A, 09 — serial only).
- **Wave 5:** 11 → 12 → 13, strict serial order.
- **Wave 6:** 14 (needs 13 + full series).
