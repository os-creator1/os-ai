# 25 — Acquisition Purpose + Ads Decisioning V1 contract

Status: implemented in lane `agent/niches-ads-external-website-v1` (base `f3e1a504`, `preview/v1-polished`).
Product goal: **"Tell the owner exactly what to do next."** Ads Manager already shows numbers; MotionGrove connects
ad delivery to the Business outcome and says which part of the funnel needs attention.

Contracts 23 (Google Ads) and 24 (Meta Ads) remain authoritative for provider data. This document records the
Acquisition Purpose model, the economics model, the deterministic decision policy and what is deliberately deferred.

---

## 1. Ownership boundaries

| Owner | Owns | Never owns |
|---|---|---|
| **Code** | the Acquisition Purpose engine, economics calculators (every formula), the Ads decision engine and its policy, Website modes, the crawler, the audit adapter | any Business number |
| **Blueprint data** (niche) | niche name, purpose definitions, question *wording* (and optional suggested ranges), which calculator applies, recommended pipelines, forms, page/funnel structure, strategy guidance, SEO/local intent guidance | a price, cost, target, rate or any other number a Business acts on; a formula; an input the calculator does not declare |
| **Business data** | actual prices, costs, retention, conversion rates, target CPL/CAC, pipeline records, forms, URLs, campaign assignments | – |

There is no `if ($industry === 'tutoring')` anywhere in runtime code. A Business's runtime reads its own
`acquisition_purposes` rows (copied from the installed Blueprint component at install, so a later niche edit never
rewrites a Business's setup).

## 2. Acquisition Purpose (the joining concept)

One first-class, Business-scoped row in `acquisition_purposes`:

| Column | Meaning |
|---|---|
| `uid`, `business_id`, `purpose_key` | identity; `unique(business_id, purpose_key)` |
| `name`, `outcome_type` | e.g. "Student Enrollment" / `student`; "Teacher Recruitment" / `hire`; "Class Enrollment" / `enrolment` |
| `calculator_key` | the code-owned economics calculator (§4) |
| `is_active`, `sort_order` | display / judging |
| `crm_pipeline_id`, `form_id` | **references** to the canonical CRM pipeline and Form (FK, `nullOnDelete`) – nothing is copied |
| `destination_type`, `destination_page_id`, `destination_url` | `none`, `hosted_page` (a `website_pages` row) or `external_url` – the landing destination, per purpose |
| `labels`, `question_schema`, `guidance`, `website_intent` | JSON copied from the Blueprint component (owner-facing wording, question schema, strategy guidance, website intent) |
| `economics` | bounded JSON `{answers: {key: value}, unknown: [key…]}` – the Business's own answers |

The purpose is the bridge: ad campaign → landing destination → Form → CRM pipeline → downstream outcome → economics.
Student and teacher funnels stay separate because each is its own row with its own pipeline, form, economics and campaigns.
Blueprint provenance is **not** duplicated: it is the existing `business_blueprint_component_installations` record
(`installed_record_type = acquisition_purpose`).

A purpose created by hand (a Business with no niche Blueprint) uses the `simple_outcome` calculator.

## 3. Campaign → purpose mapping

`google_ads_campaigns.acquisition_purpose_id` and `meta_ads_campaigns.acquisition_purpose_id` (nullable FK,
`nullOnDelete`). Both provider tables upsert on their natural provider key and keep rows (a vanished campaign is marked
removed/gone, never deleted), and the sync's upsert names its update columns, so the assignment is stable across syncs
and has real referential integrity. No polymorphic mapping table was needed.

* Assignment is **explicit** (`AcquisitionPurposeManager::assignCampaign`, Ads > Goals & economics). The campaign is
  resolved by `uid` **inside the Business**; the purpose must be the same Business's and active.
* **Never** inferred from a campaign name or copy. A campaign called "Student Enrollment" is still unassigned.
* Unassigned campaigns with spend show: *"Assign a goal so MotionGrove can judge this campaign properly."* They are never
  averaged into a goal's judgement, and incompatible goals are never merged.
* Pausing a goal unassigns its campaigns. Changing the connected ad account purges that account's facts (contract 23/24),
  which leaves campaigns unassigned (set null) rather than orphaned.

## 4. Economics

Calculators (`App\Library\Acquisition\Economics`, pure, no database/clock/AI). Money answers are in the **Business**
currency, major units; values are converted to micros for comparison with Ads facts. Percent answers are 0-100.

**Recurring lessons** (tutoring / Student Enrollment):

```
contribution_per_lesson   = lesson_price - variable_cost_per_lesson
student_contribution_ltv  = contribution_per_lesson x median_paid_lessons
```

**Class enrolment** (ceramics): same, with `units = 1` for one-off enrolment or `paid_sessions` for recurring; a one-off
studio is never forced into recurring-student economics.

**Recruitment** (Teacher Recruitment): no revenue, no LTV, so it **can never claim profit**. Inputs: teachers needed and
subjects (context only), target / hard cost per qualified applicant, target / hard cost per hire, and the stage rates
application→screened, screened→interview, interview→hire. The qualified-applicant→hire rate is the product of the last
two and exists only when both are known.

**Simple outcome** (manual goals): the owner's own limits only.

Derived targets (a target the owner did not type but that follows from confirmed ones):

```
target_qualified_cpl = target_cac x qualified_to_customer_rate      (only when both are answered)
```

* **Revenue is not profit.** "Contribution" is price minus direct variable cost; rent, marketing and overheads are not included and the UI says so.
* **Allowed CAC is not set equal to LTV.** A known LTV only produces a *suggestion* (`ads_decisions.economics.suggested_target_cac_fraction` 0.30, hard 0.50 of life contribution, and the CPL that implies). Suggestions are labelled "not used until you enter them" and **the decision engine never reads a suggestion as a target**.
* The owner edits every number later under **Ads > Goals & economics**.

### 4.1 Unknown is a valid answer

Every question has "I don't know yet". It is stored in `economics.unknown`, never as 0 and never replaced by a default or
suggestion. Consequences, all tested:

* unknown price/cost/lessons ⇒ `contribution_ltv` is `null` ⇒ `supportsProfitabilityClaim()` is `false` ⇒ the engine may say
  *"Delivery looks healthy. Whether these ads are profitable is not known"* and **never** "these ads are profitable";
* unknown conversion rate ⇒ no derived CPL; if no CPL/CAC target exists at all the engine asks for one instead of judging.

## 5. Qualified lead, outcome, attribution

Counted from the purpose's CRM pipeline for the period (a *cohort*: Opportunities created in the period, followed to where they are now):

* **inquiry** – an Opportunity opened in the purpose's pipeline whose contact's **first** attribution touch is this provider's;
* **qualified lead** – an inquiry that is **won**, or has **left the pipeline's first (`new_inquiry`) stage**; an inquiry nobody has worked yet, or one lost at the very first stage, is not qualified;
* **outcome** – a **won** Opportunity (an enrolled student, a hired teacher);
* **milestone** (optional, e.g. "Interviews") – reached a named stage (label `milestone_stage` = a stage semantic key) or beyond.

Provider attribution is the existing first-touch model: Google = a click id or a Google `utm_source` tag; Meta = a Meta
`utm_source` tag (contract 24 §10 – no Pixel/CAPI, no campaign-level attribution). It says "came from this provider",
never "from this campaign".

## 6. Deterministic decision policy

`AdsDecisionEngine` is **pure**: facts in, one verdict out, no database, clock, provider or AI. Every threshold lives in
`config/ads_decisions.php` behind `AdsDecisionPolicy` and is pinned by tests. **There is no calendar rule** ("wait 7 days");
only evidence: spend relative to the owner's target, the number of qualified outcomes, downstream conversion and tracking health.

Vocabulary: `KEEP RUNNING`, `WAIT`, `WATCH`, `ACT`, `FIX THE FUNNEL`, `CHECK TRACKING`, `NOT ENOUGH DATA`.

Rule order (first applying wins):

| # | Condition | Verdict | CTA |
|---|---|---|---|
| 1 | ad account currency ≠ Business currency | NOT ENOUGH DATA (no silent conversion) | Review goal setup |
| 2 | goal has no CRM pipeline | NOT ENOUGH DATA | Finish goal setup |
| 3 | spend, ≥ `min_clicks_for_tracking_check` clicks (or provider results) but **no** inquiry from this provider recorded recently | **CHECK TRACKING** – "do not pause or edit the ads until tracking is confirmed" | Check tracking |
| 3b | the provider reports ≥ `tracking_mismatch_min_provider_results` results **of a type comparable to an inquiry** (see below) but fewer than `tracking_mismatch_recorded_share` of them were recorded as inquiries (inconsistent tracking) | **CHECK TRACKING** – "do not pause or edit the ads until tracking is confirmed"; evidence row *Tracking health: Inconsistent* | Check tracking |
| 4 | no CPL/CAC target set | NOT ENOUGH DATA | Set your targets |
| 5 | ≥ `min_outcomes_for_cac` outcomes: cost per outcome ≤ ceiling | **KEEP RUNNING** even when the provider CPL is above target; "not a reason to pause" | Open campaign |
| 5 | … cost per outcome > ceiling and leads are cheap | **FIX THE FUNNEL** – "Do not change the ads" | Open … pipeline |
| 5 | … cost per outcome > ceiling and leads not cheap | **ACT** | provider screen |
| 6 | inquiries exist but none past the first stage | **FIX THE FUNNEL** (follow-up) | Open … pipeline |
| 6 | ≥ `min_qualified_for_funnel_judgement` qualified at/under target CPL and outcomes 0 or < `weak_conversion_share_of_expected` x the owner's expected rate | **FIX THE FUNNEL** – "the problem is after the lead, not before it" | Open … pipeline |
| 7 | zero qualified: spend < `zero_result_watch_from_multiple` x target | WAIT | Open campaign |
| 7 | … ≥ watch multiple but below the investigation point, **or** at it with fewer than `min_clicks_for_zero_result_act` clicks, **or** at it with an **unknown** click count (unknown is never sufficient) | WATCH ("too few clicks…" / "no click count…") | Open campaign |
| 7 | … ≥ act multiple with enough clicks | **ACT** – an *investigation* trigger, never a pause: "Do not pause or delete the ads on this signal alone" (tracking already healthy) | by diagnosis |
| 7 | qualified > 0, CPL ≤ target | KEEP RUNNING ("not enough evidence to change" when below `min_qualified_for_cost_judgement`) | Open campaign |
| 7 | … above target, small sample | NOT ENOUGH DATA ("wait") | Open campaign |
| 7 | … above target, within the hard maximum | WATCH | Open campaign |
| 7 | … above the hard maximum (or tolerance x target) | **ACT** | by diagnosis |

Defaults (`ads_decisions.decision`): watch from 1.0x target, act at 3.0x (the agreed ~3× "spent three leads' worth with none" investigation trigger; example: target 5.00, spend 15.00, no lead, healthy tracking, ≥ 30 clicks ⇒ ACT, still no automatic pause), 10 qualified for a cost verdict, 10 for a funnel verdict,
2 outcomes before a cost per outcome is trusted, expected-rate share 0.5, 30 clicks before "tracking" is suspected, tolerance 1.25x,
Teacher Recruitment (outcome type `hire`) has **no** policy multiple. Its investigation point is the owner's own *highest cost per qualified applicant*; with none set it never leaves WATCH and asks for one (the shared WAIT/WATCH boundary at 1.0x target still applies). ≥ 30 known clicks (`min_clicks_for_zero_result_act`) before no-lead spend is blamed on the ads; tracking is called inconsistent when the provider reports ≥ 10 comparable results but < 25% were recorded as inquiries; re-review after 4 more qualified leads or 1.0x target of further spend, Google search-term waste ≥ 25% of spend.

**ACT is advisory.** The no-lead investigation verdict is worded as a reason to look ("worth investigating … not proof that the ads are failing"); for a recruitment goal it cites the owner's own highest cost per qualified applicant. It links to a screen and never pauses, edits or mutates a campaign.

**Comparable provider results.** A provider result is set against CRM inquiries (rule 3 "provider results" and rule 3b) only when it is an inquiry measured the way MotionGrove measures one: Meta result types listed in `inquiry_comparable_result_types` (default: `offsite_conversion.fb_pixel_lead`, a website lead). Meta `lead` / `onsite_conversion.lead_grouped` include on-Facebook form leads that never reach a MotionGrove pipeline; page views, link clicks and messaging events are not inquiries; an unchosen/unknown type is not comparable. Google's cached `conversions` are the account's primary-action conversions with no type attached, so Google is never compared. When comparability cannot be established no mismatch is claimed; the clicks-without-any-inquiry safeguard is unchanged.

**Evidence scope.** Every ACT verdict says the figures cover all of the provider's campaigns assigned to the goal together: MotionGrove has no per-ad lead results (contract 24 §10), so it never names a specific campaign, ad set or ad as the culprit. The evidence list also shows cost per inquiry, the inquiry→outcome rate and (non-recruitment only) the expected contribution per outcome ("Not known yet" when any input is unknown). Every verdict, including NOT ENOUGH DATA and CHECK TRACKING, carries a *Next review* stated as evidence, not days.

**Diagnosis → CTA (ACT only)**, because the ads themselves are the cause: Google search-term waste ≥ threshold ⇒ *Review search terms*
(Google only); very low click-through ⇒ creative may be weak (*Review ad* / *Open campaign*); many clicks, almost no inquiries ⇒
offer / landing page weak (*Review landing page*); otherwise *Open campaign* (Google) / *Review ad* (Meta).

**Evidence hierarchy** the rules respect: provider result ≠ qualified lead ≠ paying customer ≠ profitable customer. "Profitable"
is printed only when the purpose's life contribution is known **and** the observed cost per outcome is below it **and** at/under
the owner's target (`claimsProfit`). Every verdict carries a "Why am I seeing this?" evidence list (provider-reported result cost,
MotionGrove cost per qualified lead, targets, cost per outcome, inquiries, qualified, outcomes, tracking health) and a *Next review*
expressed in evidence ("after 4 more qualified leads or another €X of spend"), never in days.

### 6.1 No fake AI authority

Pause / continue / profitable / unprofitable / funnel-failure are never decided by AI. AI may later *rephrase* or suggest creative,
but may not change a verdict.

## 7. Ads UI

One sidebar module **Ads** (Overview · Google · Meta · plus **Goals & economics** in each provider's sub-navigation). Both provider
pages put **"What should you do now?"** first, directly under the page navigation and above the period controls and KPIs:

state badge (printed in words) · goal · headline · reasons · **What not to change** · **Next review** · one CTA · *Why am I seeing this?* ·
other goals' verdicts behind it · unassigned campaigns.

Below it the **business-outcome KPI row** (Spend · Qualified leads · Cost / qualified lead · [Interviews] · Enrolled students / Hires /
Enrolments · Student CAC / Cost per hire) – unknown is `—` with "Not enough data", never 0. The provider's own KPIs follow, secondary.

A CTA is a real internal link or it is not drawn: there is **no fake "Fix" button**. On a Core plan (no campaign pages) the CTA
becomes plain instruction text. Provider truth is preserved: search terms, keywords and negative keywords are Google-only; Meta shows
campaigns/ad sets/ads and the owner-chosen result type.

CTA routing: *Open campaign* → provider campaign page; *Review search terms* → Google search terms; *Review ad* → Meta ads;
*Open … pipeline* / *Review teacher applicants* → the CRM board on **that goal's** pipeline; *Review landing page* → the goal's destination
(hosted page editor, or the owner's external URL in a new tab); *Check tracking* → the provider's Leads/attribution page; goal setup → Goals & economics.

## 8. Goals & economics page

`GET /…/ads/goals` (read: either provider's view permission; every POST: either provider's manage permission, View-As prohibited by the
`ads.` prefix). Per goal: how it connects (pipeline, form, destination – validated inside the Business), the economics questions with
"I don't know yet", the derived arithmetic with its caveats, labelled suggestions, assigned campaigns (assign / unassign), strategy
guidance, pause/resume; plus "Add a goal". Validation: numbers ≥ 0, percents ≤ 100, hard maximum ≥ target.

## 9. Growth / Home seam

Growth is on this base and is fact-based, so no second recommendation engine was built. `GrowthAdsFactReader` gains a `decisions` fact
(count per decision state across settled providers + `unassigned_campaigns`), read from the engine's own states; `GrowthWebsiteFactReader`
gains `mode`, `external` (critical / issues / broken links / indexability) and `missing_landing`. No Growth rule consumes them yet; adding one
is a Growth-lane change whose CTA must point at the owning module (Ads Goals, Website external audit). Lifecycle and scoring stay in Growth.

## 10. Deferred (deliberate)

* **Campaign creation** and any provider edit beyond the existing pause/resume and negative-keyword mutations.
* Campaign-level attribution for Meta (no Pixel / CAPI / click id – contract 24 §10).
* Automatic purpose assignment (explicit only).
* Currency conversion between ad account and Business.
* Growth *rules/opportunities* over the new facts.
* AI explanations of a verdict.
* A purpose-level view that compares goals (they are intentionally never blended).
