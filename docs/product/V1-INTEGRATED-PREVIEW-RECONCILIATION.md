# V1 integrated preview — reconciliation record

Temporary integration / acceptance branch `preview/v1-integrated-final-r2` (base
`origin/main` 6ac3e19c). It is **not** a feature branch, is not merged to main and has no
PR; it exists to prove the final lane heads work together. Source branches are unchanged.

## Integrated heads (merge order, `--no-ff`, one merge commit each)

signup `a57048a1` → customer shell / conversations `5d0234c5` → agency shell `0cf20825` →
platform owner shell `68a0ac51` → platform owner hotfix `9eaf1f3c` → CRM optimistic board
`2f04223c` → calendar `5c1f4a84` → public booking `5aae91d2` → SEO reviews `5dc58812` → SEO
citations `1e955ffa` → website onboarding `07beed82` → proposal editor `be2ba0e2` →
forms + custom fields `6f198ed0` (already contains Custom Fields `29920dc2`; not merged
separately) → automations `4ee51509` → Growth `9de11065` (last).

Stacked commits already contained in a later head were not re-applied.

## Product decision: Home = Growth Center

- No Growth and no Results sidebar entry. Business sidebar: Home, Opportunities (CRM),
  Contacts, Conversations, Calendar, Automations, Website, SEO (group), Forms, Packages &
  Products, Payments & Contracts, Settings.
- Home's first band is the Growth band (`App\Library\Growth\GrowthHomeBand`, view
  `customer/dashboard/bands/growth`): top three open recommendations, what's working, the
  Growth Score as a secondary "Business health" figure, "Ask Advisor". It replaces the
  next-best-move band when it renders. The Business snapshot beneath it is the existing
  canonical Home analytics.
- `.../growth` redirects to Home. Recommendations / detail / score / insights / advisor remain
  deep-linkable from Home. Customer wording "Recommendations"; the CRM board keeps
  "Opportunities".
- Results: URLs kept for compatibility, removed from primary navigation; owner-outcome figures
  live in Home's snapshot; module analytics stay with their modules.

## Conflicts and how each was reconciled (no blanket ours/theirs)

| Area | Reconciliation |
|---|---|
| Automations × Forms / Custom Fields (14 files) | Hunk-by-hunk; the compiled workflow-builder bundle was rebuilt from the merged sources, not picked. |
| `DocumentManager::send()` | Signature is `(document, ?channels, ?message, ?lockVersion, ?origin)`; callers pass `origin:` by name. |
| `CrmOpportunityService::moveToStage()` | `(opportunity, to, ?actor, ?expectedFrom, ?origin)`; the board passes `expectedFrom`, the Automation step passes `origin:`. |
| Proposal `DocumentMergeFields` × canonical merge-field registry | Fixed proposal tokens stay; canonical `contact.<key>` (custom fields), `location.*`, `opportunity.*` are accepted, picker via `catalogFor()`. Credential-shaped keys and trailing-newline tokens are refused. |
| Workflow reference catalog union | The Custom Fields branch's select was missing two columns after Automations added them (MySQL cardinality error) — fixed. |
| `EventServiceProvider` | Growth added a second `DocumentPaymentFailed` array key that silently replaced Automations' listener. Merged into one entry (both listeners). |
| Navigation | Growth sidebar item and Results dropped; Home active-route list includes the Growth routes. |
| Growth × Proposals / Payments | A Balance item still ahead of its due date is no longer "signed but unpaid" (the scheduled balance request owns it). |
| Growth × SEO Citations | Directory expectations come from `SeoCitationApplicability` (shared with the Citations page): active, core or niche-recommended, country-applicable per Location. |
| Growth × Website | `website.package_out_of_sync:v1` activated through `WebsiteCatalogReferences::staleness()`. |
| Home read budget | Growth band costs four statements; pinned in `DashboardQueryBudgetTest` (13 → 17, ceiling 18). |

## Growth rule availability (19 implemented)

Available now: the 18 original rules + `website.package_out_of_sync`. Still unavailable (reader
reports `unavailable`, never zero): rank rules, Search Console CTR, Ads rules (no provider
foundation), slow-response trend, forms rules, "high-priority service-area page not
published", "eligible customer not asked for a review". No Booking, Forms or Custom Fields rules
were added; they coexist.

Growth actions stay navigation hand-offs. "Build a follow-up automation" opens the final
Automations list; nothing auto-executes.
