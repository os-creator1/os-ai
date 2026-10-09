<?php

namespace App\Library\Ads\Decisions;

use App\Library\MetaAds\MetaAdsMoney;

/**
 * Acquisition Purpose + Ads Decisioning V1 — "tell the owner exactly what to do
 * next", deterministically.
 *
 * PURE. Facts in (AdsDecisionInput), one verdict out (AdsDecision). No database,
 * no clock, no provider, no AI — so every rule is testable by constructing an
 * input, and the same facts always give the same words. Every threshold comes
 * from AdsDecisionPolicy (config/ads_decisions.php); there is no calendar rule
 * ("wait 7 days") anywhere, only evidence: spend relative to the owner's target
 * cost of a result, the number of qualified outcomes, downstream conversion
 * and whether tracking is working.
 *
 * THE EVIDENCE HIERARCHY the rules respect:
 *
 *     provider result  !=  qualified lead  !=  paying customer  !=  profitable customer
 *
 * so the Business outcome (cost of an enrolled student, a hired teacher) beats
 * a surface metric (CPM, CTR, the provider's own cost per result), and the word
 * "profitable" is printed only when the owner's own economics and enough
 * outcomes prove it (EconomicsProfile::supportsProfitabilityClaim).
 *
 * RULE ORDER (first that applies wins):
 *   1 currency of the ad account differs from the Business     -> NOT_ENOUGH_DATA
 *   2 the goal is not connected to a CRM pipeline              -> NOT_ENOUGH_DATA
 *   3 clicks arrive, but no inquiry from this provider has ever
 *     been recorded                                            -> CHECK_TRACKING
 *   4 no target cost has been set                              -> NOT_ENOUGH_DATA
 *   5 enough outcomes to know the cost of one                  -> KEEP_RUNNING / FIX_THE_FUNNEL / ACT
 *   6 enough qualified leads and few become customers          -> FIX_THE_FUNNEL
 *   7 cost per qualified lead against the target               -> KEEP_RUNNING / WATCH / ACT / WAIT / NOT_ENOUGH_DATA
 *
 * Only decisions whose cause is the ads themselves (ACT) point at the provider;
 * every funnel finding points at the pipeline, and says not to touch the ads.
 */
final class AdsDecisionEngine
{
    /** The currency of the decision being built; set per call so every amount is printed in the ad account's currency. */
    private ?string $currency = null;

    /** The KPI row of the decision being built (same lifetime as $currency). @var list<array{label: string, value: string, note: ?string}> */
    private array $kpis = [];

    public function __construct(private readonly AdsDecisionPolicy $policy)
    {
    }

    public function decide(AdsDecisionInput $in): AdsDecision
    {
        $this->currency = $in->currency;

        $economics = $in->economics;
        $targetCpl = $economics?->targetCplMicros;
        $hardCpl = $economics?->hardCplMicros ?? ($targetCpl !== null ? (int) round($targetCpl * $this->policy->overTargetToleranceMultiple()) : null);
        $targetCac = $economics?->targetCacMicros;
        $hardCac = $economics?->hardCacMicros ?? ($targetCac !== null ? (int) round($targetCac * $this->policy->overTargetToleranceMultiple()) : null);

        $cpl = $in->qualified > 0 ? intdiv($in->spendMicros, $in->qualified) : null;
        $cac = $in->outcomes > 0 ? intdiv($in->spendMicros, $in->outcomes) : null;
        $evidence = $this->evidence($in, $cpl, $cac, $targetCpl, $targetCac);
        $this->kpis = $this->kpis($in, $cpl, $cac, $targetCpl, $targetCac);

        // 1. Money in two currencies cannot be compared against the owner's targets.
        if (! $in->currencyMatchesBusiness) {
            return $this->make($in, AdsDecisionState::NotEnoughData, 'Your ad account bills in a different currency from your business.', [
                'This ad account uses ' . strtoupper($in->currency) . ' and your business uses ' . strtoupper($in->businessCurrency) . ', and your targets are in ' . strtoupper($in->businessCurrency) . '.',
                'MotionGrove will not guess an exchange rate, so it cannot judge this goal against your targets yet.',
            ], $evidence, doNotChange: null, nextReview: null, cta: [AdsDecisionCtaKind::FinishGoalSetup, 'Review goal setup']);
        }

        // 2. Without a pipeline there is nothing downstream to measure.
        if (! $in->pipelineLinked) {
            return $this->make($in, AdsDecisionState::NotEnoughData, 'This goal is not connected to a pipeline yet.', [
                'MotionGrove can only judge ads by what happens to the people they bring. Connect ' . $in->purposeName . ' to the pipeline where its ' . $in->label('leads', 'inquiries') . ' arrive.',
            ], $evidence, doNotChange: null, nextReview: null, cta: [AdsDecisionCtaKind::FinishGoalSetup, 'Finish goal setup']);
        }

        // 3. Clicks but never an inquiry from this provider: look at tracking BEFORE the ads.
        if ($this->trackingBroken($in)) {
            return $this->make($in, AdsDecisionState::CheckTracking, 'Check that your inquiries are being tracked before judging these ads.', [
                'These ads received ' . number_format((int) $in->clicks) . ' clicks, but MotionGrove has not recorded a single inquiry that came from ' . $in->providerName() . ' recently.',
                'That usually means the tracking is missing or broken, not that the ads failed. Checking the ads now could lead you to change something that is working.',
            ], $evidence, doNotChange: 'Do not pause or edit the ads until tracking is confirmed.', nextReview: null, cta: [AdsDecisionCtaKind::FixTracking, 'Check tracking']);
        }

        // 4. No agreed target: MotionGrove can show facts but not judge them.
        if ($targetCpl === null && $targetCac === null) {
            return $this->make($in, AdsDecisionState::NotEnoughData, 'Tell MotionGrove what a good result costs you.', [
                'Set the most you are happy to pay for one ' . $in->label('lead', 'qualified inquiry') . ' or for one ' . $in->label('outcome', 'customer') . ', and MotionGrove will tell you whether to keep these ads running.',
                $in->qualified > 0 ? 'So far ' . $in->qualified . ' qualified ' . $in->label('leads', 'inquiries') . ' came from ' . $in->providerName() . ' at ' . $this->money($cpl) . ' each.' : 'No qualified ' . $in->label('leads', 'inquiries') . ' from ' . $in->providerName() . ' yet.',
            ], $evidence, doNotChange: null, nextReview: null, cta: [AdsDecisionCtaKind::SetTargets, 'Set your targets']);
        }

        // 5. The cost of an actual outcome is known: it outranks every surface metric.
        if ($cac !== null && $in->outcomes >= $this->policy->minOutcomesForCac() && ($targetCac !== null || $hardCac !== null)) {
            return $this->decideFromOutcomeCost($in, $cpl, $cac, $targetCpl, $hardCpl, $targetCac, $hardCac, $evidence);
        }

        // 6. Plenty of qualified leads, almost none become customers.
        $funnel = $this->funnelProblem($in, $cpl, $targetCpl, $hardCpl);
        if ($funnel !== null) {
            return $this->make($in, AdsDecisionState::FixTheFunnel, $funnel['headline'], $funnel['reasons'], $evidence,
                doNotChange: 'Do not change the ads. The ads are doing their job; the loss happens after the ' . $in->label('lead', 'inquiry') . ' arrives.',
                nextReview: null, cta: [AdsDecisionCtaKind::OpenPipeline, $in->label('pipeline_cta', 'Open pipeline')], diagnosis: 'Sales, follow-up or qualification after the lead');
        }

        // 7. Judge the ads by the cost of a qualified lead.
        return $this->decideFromLeadCost($in, $cpl, $targetCpl, $hardCpl, $evidence);
    }

    // ------------------------------------------------------------------ rule 5

    /**
     * @param  list<array{label: string, value: string}>  $evidence
     */
    private function decideFromOutcomeCost(AdsDecisionInput $in, ?int $cpl, int $cac, ?int $targetCpl, ?int $hardCpl, ?int $targetCac, ?int $hardCac, array $evidence): AdsDecision
    {
        $ceiling = $hardCac ?? $targetCac;
        $outcome = $in->label('outcome', 'customer');
        $outcomes = $in->label('outcomes', 'customers');

        if ($cac <= $ceiling) {
            $reasons = [ucfirst($outcomes) . ' are costing ' . $this->money($cac) . ' each, ' . ($targetCac !== null && $cac <= $targetCac ? 'within your ' . $this->money($targetCac) . ' target.' : 'within your ' . $this->money($ceiling) . ' limit.')];

            if ($cpl !== null && $targetCpl !== null && $cpl > $targetCpl) {
                $reasons[] = 'The cost per qualified lead (' . $this->money($cpl) . ') is above its ' . $this->money($targetCpl) . ' target, but the cost of an actual ' . $outcome . ' is what matters, so this is not a reason to pause.';
            }

            $claims = $in->economics?->supportsProfitabilityClaim($cac) === true && $targetCac !== null && $cac <= $targetCac;

            if ($claims) {
                $reasons[] = 'Each ' . $outcome . ' costs less than the ' . $this->money($in->economics->contributionLtvMicros) . ' they contribute over their life, based on your own estimates.';
            } else {
                $reasons[] = $this->profitNote($in);
            }

            return $this->make($in, AdsDecisionState::KeepRunning, 'Keep running these ads: they are winning ' . $outcomes . ' at an acceptable cost.', $reasons, $evidence,
                doNotChange: 'Leave the ads as they are. Do not pause them because a surface number such as click-through or cost per click looks weak.',
                nextReview: $this->nextReview($in, $targetCpl ?? $targetCac), cta: [AdsDecisionCtaKind::OpenCampaign, 'Open campaign'], claimsProfit: $claims);
        }

        // The cost of an outcome is over the ceiling: lead cost decides where the problem is.
        $leadsAreFine = $cpl !== null && $targetCpl !== null && $cpl <= $targetCpl && $in->qualified >= $this->policy->minQualifiedForFunnelJudgement();

        if ($leadsAreFine) {
            return $this->make($in, AdsDecisionState::FixTheFunnel, 'The ads are bringing cost-effective leads; too few become ' . $outcomes . '.', [
                ucfirst($in->label('leads', 'qualified inquiries')) . ' cost ' . $this->money($cpl) . ' each, below your ' . $this->money($targetCpl) . ' target.',
                'But only ' . $in->outcomes . ' of ' . $in->qualified . ' became a ' . $outcome . ', so each ' . $outcome . ' costs ' . $this->money($cac) . ', above your ' . $this->money($ceiling) . ' limit.',
                'The acquisition problem is after the lead, not before it.',
            ], $evidence, doNotChange: 'Do not change the ads.', nextReview: null,
                cta: [AdsDecisionCtaKind::OpenPipeline, $in->label('pipeline_cta', 'Open pipeline')], diagnosis: 'Sales, follow-up or qualification after the lead');
        }

        return $this->actionOnAds($in, 'Each ' . $outcome . ' is costing ' . $this->money($cac) . ', above your ' . $this->money($ceiling) . ' limit, and leads are not cheap either.', [
            ucfirst($outcomes) . ' cost ' . $this->money($cac) . ' each against your ' . $this->money($ceiling) . ' limit' . ($cpl !== null && $targetCpl !== null ? ', and a qualified lead costs ' . $this->money($cpl) . ' against a ' . $this->money($targetCpl) . ' target.' : '.'),
        ], $evidence, $targetCpl ?? $targetCac);
    }

    // ------------------------------------------------------------------ rule 6

    /** @return array{headline: string, reasons: list<string>}|null */
    private function funnelProblem(AdsDecisionInput $in, ?int $cpl, ?int $targetCpl, ?int $hardCpl): ?array
    {
        $outcome = $in->label('outcome', 'customer');
        $min = $this->policy->minQualifiedForFunnelJudgement();

        // Inquiries are arriving but nobody has worked them: the ads delivered, the follow-up has not happened.
        if ($in->qualified === 0 && $in->inquiries > 0 && $targetCpl !== null && $in->spendMicros >= $targetCpl * $this->policy->zeroResultWatchFromMultiple()) {
            return [
                'headline' => 'Inquiries are arriving, but none has been followed up yet.',
                'reasons' => [
                    $in->inquiries . ' ' . ($in->inquiries === 1 ? 'inquiry' : 'inquiries') . ' came from ' . $in->providerName() . ' and none has moved past the first stage of the pipeline.',
                    'Contact them first. Judging the ads before the inquiries are worked would be judging half the picture.',
                ],
            ];
        }

        if ($in->qualified < $min || $cpl === null || $targetCpl === null || $cpl > $targetCpl) {
            return null;
        }

        $expected = $in->economics?->qualifiedToOutcomeRate;
        $actual = $in->outcomes / $in->qualified;
        $weak = $in->outcomes === 0
            || ($expected !== null && $expected > 0 && $actual < $expected * $this->policy->weakConversionShareOfExpected());

        if (! $weak) {
            return null;
        }

        $reasons = [
            ucfirst($in->label('leads', 'qualified inquiries')) . ' cost ' . $this->money($cpl) . ' each, below your ' . $this->money($targetCpl) . ' target.',
            'Only ' . $in->outcomes . ' of ' . $in->qualified . ' became a ' . $outcome . ($expected !== null ? ' (' . number_format($actual * 100, 0) . '% against the ' . number_format($expected * 100, 0) . '% you expected)' : '') . '.',
            'The acquisition problem is currently after the lead, not before it.',
        ];

        return ['headline' => $in->providerName() . ' is bringing good leads; too few are becoming ' . $in->label('outcomes', 'customers') . '.', 'reasons' => $reasons];
    }

    // ------------------------------------------------------------------ rule 7

    /**
     * @param  list<array{label: string, value: string}>  $evidence
     */
    private function decideFromLeadCost(AdsDecisionInput $in, ?int $cpl, ?int $targetCpl, ?int $hardCpl, array $evidence): AdsDecision
    {
        $lead = $in->label('lead', 'qualified inquiry');
        $leads = $in->label('leads', 'qualified inquiries');

        // Only a target cost per OUTCOME was set and no conversion rate to translate it: cannot judge a lead.
        if ($targetCpl === null) {
            return $this->make($in, AdsDecisionState::NotEnoughData, 'Add a target cost per ' . $lead . ' or your expected conversion rate.', [
                'You set a target cost for a finished ' . $in->label('outcome', 'customer') . ', but there are not enough finished outcomes yet to judge by, and no target for the step before.',
            ], $evidence, doNotChange: null, nextReview: null, cta: [AdsDecisionCtaKind::SetTargets, 'Set your targets']);
        }

        if ($in->qualified === 0) {
            $multiple = $in->spendMicros / $targetCpl;

            if ($multiple < $this->policy->zeroResultWatchFromMultiple()) {
                $remaining = max(0, (int) ceil($targetCpl * $this->policy->zeroResultWatchFromMultiple()) - $in->spendMicros);

                return $this->make($in, AdsDecisionState::Wait, 'Wait: it is too early to expect a ' . $lead . '.', [
                    $in->spendMicros === 0 ? 'These ads have not spent anything in this period.' : 'These ads have spent ' . $this->money($in->spendMicros) . ', less than one ' . $lead . ' should cost you (' . $this->money($targetCpl) . ').',
                ], $evidence, doNotChange: 'Avoid changing the ads while evidence builds.',
                    nextReview: 'After ' . $this->money($remaining) . ' more spend, or the first qualified ' . $lead . ' (whichever comes first).',
                    cta: [AdsDecisionCtaKind::OpenCampaign, 'Open campaign']);
            }

            if ($multiple < $this->policy->zeroResultActAtMultiple()) {
                $until = max(0, (int) ceil($targetCpl * $this->policy->zeroResultActAtMultiple()) - $in->spendMicros);

                return $this->make($in, AdsDecisionState::Watch, 'Watch: no qualified ' . $lead . ' yet, and spend is building.', [
                    'These ads have spent ' . $this->money($in->spendMicros) . ' (' . $this->multiple($multiple) . ' your ' . $this->money($targetCpl) . ' target) without a qualified ' . $lead . '.',
                    'This is not yet enough to conclude the ads are failing.',
                ], $evidence, doNotChange: 'Avoid changing the ads for now.',
                    nextReview: 'After ' . $this->money($until) . ' more spend, or the first qualified ' . $lead . '.',
                    cta: [AdsDecisionCtaKind::OpenCampaign, 'Open campaign']);
            }

            return $this->actionOnAds($in, 'Review these ads: they have spent ' . $this->multiple($multiple) . ' your target without a qualified ' . $lead . '.', [
                'These ads have spent ' . $this->money($in->spendMicros) . ' without a qualified ' . $lead . '. Your target is ' . $this->money($targetCpl) . ' for one.',
                $in->attributedTouches > 0 || $in->inquiries > 0 ? 'Inquiries from ' . $in->providerName() . ' are being recorded, so this points at the ads rather than a missing connection.' : 'Nothing suggests a tracking fault, so this points at the ads.',
            ], $evidence, $targetCpl);
        }

        $multiple = $cpl / $targetCpl;
        $min = $this->policy->minQualifiedForCostJudgement();
        $few = $in->qualified < $min;

        if ($cpl <= $targetCpl) {
            $reasons = [ucfirst($leads) . ' are costing ' . $this->money($cpl) . ' each, below your ' . $this->money($targetCpl) . ' target.'];

            if ($few) {
                $reasons[] = 'Only ' . $in->qualified . ' qualified ' . ($in->qualified === 1 ? $lead : $leads) . ' so far, so there is not enough evidence to make a change yet.';
            }

            $reasons[] = $this->profitNote($in);

            return $this->make($in, AdsDecisionState::KeepRunning, 'Keep running these ads.', $reasons, $evidence,
                doNotChange: 'Leave the ads as they are.', nextReview: $this->nextReview($in, $targetCpl),
                cta: [AdsDecisionCtaKind::OpenCampaign, 'Open campaign']);
        }

        if ($few) {
            return $this->make($in, AdsDecisionState::NotEnoughData, 'Not enough data to say whether these ads got worse.', [
                'Qualified ' . $leads . ' cost ' . $this->money($cpl) . ', above your ' . $this->money($targetCpl) . ' target, but only ' . $in->qualified . ' ' . ($in->qualified === 1 ? 'has' : 'have') . ' been recorded.',
                'There is not enough evidence to conclude performance has deteriorated. Wait.',
            ], $evidence, doNotChange: 'Avoid changing the ads on this little evidence.', nextReview: $this->nextReview($in, $targetCpl),
                cta: [AdsDecisionCtaKind::OpenCampaign, 'Open campaign']);
        }

        if ($hardCpl !== null && $cpl > $hardCpl) {
            return $this->actionOnAds($in, 'Review these ads: ' . $leads . ' cost more than the most you would pay.', [
                ucfirst($leads) . ' are costing ' . $this->money($cpl) . ' each, above your ' . $this->money($hardCpl) . ' maximum (' . $this->multiple($multiple) . ' your target).',
            ], $evidence, $targetCpl);
        }

        return $this->make($in, AdsDecisionState::Watch, 'Watch: ' . $leads . ' cost more than your target, but within what you would accept.', [
            ucfirst($leads) . ' are costing ' . $this->money($cpl) . ' each against a ' . $this->money($targetCpl) . ' target' . ($hardCpl !== null ? ' and a ' . $this->money($hardCpl) . ' maximum.' : '.'),
        ], $evidence, doNotChange: 'Avoid big changes yet; the cost is above target but not out of bounds.', nextReview: $this->nextReview($in, $targetCpl),
            cta: [AdsDecisionCtaKind::OpenCampaign, 'Open campaign']);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * An ACT verdict: the cause is the ads, so the call to action is the most
     * specific provider screen for what the facts suggest.
     *
     * @param  list<string>  $reasons
     * @param  list<array{label: string, value: string}>  $evidence
     */
    private function actionOnAds(AdsDecisionInput $in, string $headline, array $reasons, array $evidence, ?int $targetCost): AdsDecision
    {
        [$kind, $label, $diagnosis, $notes] = $this->actionTarget($in, $targetCost);

        if ($diagnosis !== null) {
            $reasons[] = $diagnosis;
        }

        return $this->make($in, AdsDecisionState::Act, $headline, $reasons, $evidence,
            doNotChange: null, nextReview: $this->nextReview($in, $targetCost, 'After you have made a change, check again'),
            cta: [$kind, $label], diagnosis: $diagnosis, notes: $notes);
    }

    /** @return array{0: AdsDecisionCtaKind, 1: string, 2: ?string, 3: list<string>} */
    private function actionTarget(AdsDecisionInput $in, ?int $targetCost): array
    {
        // Google only: money going to searches that never become inquiries.
        if ($in->isGoogle() && $in->searchTermWasteMicros !== null && $in->spendMicros > 0 && $targetCost !== null
            && $in->searchTermWasteMicros >= $in->spendMicros * $this->policy->searchTermWasteShare()
            && $in->searchTermWasteMicros >= $targetCost * $this->policy->searchTermWasteMinTargetMultiple()) {
            return [
                AdsDecisionCtaKind::ReviewSearchTerms,
                'Review search terms',
                'About ' . $this->money($in->searchTermWasteMicros) . ' of this spend went to searches that produced no conversions. In Google Ads, add the irrelevant ones as negative keywords so they stop costing you.',
                [],
            ];
        }

        if ($in->impressions !== null && $in->clicks !== null && $in->impressions >= $this->policy->minImpressionsForCtr()
            && ($in->clicks / max(1, $in->impressions)) < $this->policy->weakCtr()) {
            return [
                $in->isGoogle() ? AdsDecisionCtaKind::OpenCampaign : AdsDecisionCtaKind::ReviewAd,
                $in->isGoogle() ? 'Open campaign' : 'Review ad',
                'Many people see these ads but very few click, so the wording or picture may not be catching attention. Try a clearer headline or a different image.',
                [],
            ];
        }

        if ($in->clicks !== null && $in->clicks >= $this->policy->minClicksForTrackingCheck()
            && ($in->inquiries / max(1, $in->clicks)) < $this->policy->weakClickToInquiry()) {
            return [
                AdsDecisionCtaKind::ReviewLandingPage,
                'Review landing page',
                'People click but very few send an inquiry, so the page they land on or the offer may be weak. Check that it matches the ad and that the inquiry button is easy to find.',
                [],
            ];
        }

        return [
            $in->isGoogle() ? AdsDecisionCtaKind::OpenCampaign : AdsDecisionCtaKind::ReviewAd,
            $in->isGoogle() ? 'Open campaign' : 'Review ad',
            null,
            [],
        ];
    }

    private function trackingBroken(AdsDecisionInput $in): bool
    {
        if ($in->inquiries > 0 || $in->attributedTouches > 0 || $in->spendMicros <= 0) {
            return false;
        }

        $enoughClicks = $in->clicks !== null && $in->clicks >= $this->policy->minClicksForTrackingCheck();
        $providerSaysResults = $in->providerResults !== null && $in->providerResults > 0;

        return $enoughClicks || $providerSaysResults;
    }

    /** The only place the profit question is answered: honest about what is not known. */
    private function profitNote(AdsDecisionInput $in): string
    {
        $outcome = $in->label('outcome', 'customer');
        $economics = $in->economics;

        if ($economics === null || $economics->contributionLtvMicros === null) {
            return 'Delivery looks healthy. Whether these ads are profitable is not known: it needs what ' . self::article($outcome) . ' contributes over their life (price, direct cost and how long they stay), which you have not given yet.';
        }

        return 'Delivery looks healthy. Profitability is not claimed yet: it needs enough finished ' . $in->label('outcomes', 'customers') . ' to know what one really costs.';
    }

    private function nextReview(AdsDecisionInput $in, ?int $targetCost, string $prefix = 'After'): string
    {
        $more = $this->policy->reviewAfterMoreQualified();
        $lead = $in->label('lead', 'qualified inquiry');

        if ($targetCost === null || $targetCost <= 0) {
            return $prefix . ' ' . $more . ' more ' . ($more === 1 ? $lead : $in->label('leads', 'qualified inquiries')) . '.';
        }

        $spend = (int) round($targetCost * $this->policy->reviewAfterMoreSpendMultiple());

        return $prefix . ' ' . $more . ' more ' . $in->label('leads', 'qualified inquiries') . ' or another ' . $this->money($spend) . ' of spend, whichever comes first.';
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function evidence(AdsDecisionInput $in, ?int $cpl, ?int $cac, ?int $targetCpl, ?int $targetCac): array
    {
        $costPerProviderResult = $in->providerResults !== null && $in->providerResults > 0 ? $this->money((int) round($in->spendMicros / $in->providerResults)) : 'Not available';

        $tracking = match (true) {
            $in->inquiries > 0 || $in->attributedTouches > 0 => 'Good',
            $this->trackingBroken($in) => 'Missing',
            default => 'Nothing recorded yet',
        };

        $rows = [
            ['label' => $in->providerName() . ' reported result cost', 'value' => $costPerProviderResult],
            ['label' => 'MotionGrove ' . strtolower($in->label('cost_per_lead', 'cost per qualified lead')), 'value' => $cpl === null ? 'Not enough data' : $this->money($cpl)],
            ['label' => 'Target ' . strtolower($in->label('cost_per_lead', 'cost per qualified lead')), 'value' => $targetCpl === null ? 'Not set' : $this->money($targetCpl)],
            ['label' => $in->label('cost_per_outcome', 'Cost per customer'), 'value' => $cac === null ? 'Not enough data' : $this->money($cac)],
            ['label' => 'Target ' . strtolower($in->label('cost_per_outcome', 'cost per customer')), 'value' => $targetCac === null ? 'Not set' : $this->money($targetCac)],
            ['label' => 'Inquiries recorded', 'value' => (string) $in->inquiries],
            ['label' => 'Qualified ' . $in->label('leads', 'inquiries'), 'value' => (string) $in->qualified],
            ['label' => ucfirst($in->label('outcomes', 'customers')), 'value' => (string) $in->outcomes],
            ['label' => 'Tracking health', 'value' => $tracking],
        ];

        return $rows;
    }

    /**
     * The Business-outcome KPI row, in order: spend, qualified leads, cost per
     * qualified lead, [milestone], outcomes, cost per outcome. Unknown is a dash
     * ("Not enough data" in the note), never a fake zero.
     *
     * @return list<array{label: string, value: string, note: ?string}>
     */
    private function kpis(AdsDecisionInput $in, ?int $cpl, ?int $cac, ?int $targetCpl, ?int $targetCac): array
    {
        $dash = '—';
        $rows = [
            ['label' => 'Spend', 'value' => $this->money($in->spendMicros), 'note' => null],
            ['label' => 'Qualified ' . $in->label('leads', 'inquiries'), 'value' => number_format($in->qualified), 'note' => $in->inquiries . ' ' . ($in->inquiries === 1 ? 'inquiry' : 'inquiries') . ' in total'],
            ['label' => $in->label('cost_per_lead', 'Cost / qualified lead'), 'value' => $cpl === null ? $dash : $this->money($cpl), 'note' => $cpl === null ? 'Not enough data' : ($targetCpl === null ? null : 'Target ' . $this->money($targetCpl))],
        ];

        if ($in->milestone !== null && $in->label('milestone_label') !== '') {
            $rows[] = ['label' => $in->label('milestone_label'), 'value' => number_format($in->milestone), 'note' => null];
        }

        $rows[] = ['label' => ucfirst($in->label('outcomes', 'customers')), 'value' => number_format($in->outcomes), 'note' => null];
        $rows[] = ['label' => $in->label('cost_per_outcome', 'Cost / customer'), 'value' => $cac === null ? $dash : $this->money($cac), 'note' => $cac === null ? 'Not enough data' : ($targetCac === null ? null : 'Target ' . $this->money($targetCac))];

        return $rows;
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<array{label: string, value: string}>  $evidence
     * @param  array{0: AdsDecisionCtaKind, 1: string}|null  $cta
     * @param  list<string>  $notes
     */
    private function make(
        AdsDecisionInput $in,
        AdsDecisionState $state,
        string $headline,
        array $reasons,
        array $evidence,
        ?string $doNotChange = null,
        ?string $nextReview = null,
        ?array $cta = null,
        bool $claimsProfit = false,
        ?string $diagnosis = null,
        array $notes = [],
    ): AdsDecision {
        if ($in->isGoogle() && $in->searchTermWasteMicros !== null && $in->spendMicros > 0 && $in->searchTermWasteMicros >= $in->spendMicros * $this->policy->searchTermWasteShare()
            && ! in_array(AdsDecisionCtaKind::ReviewSearchTerms, [$cta[0] ?? null], true)) {
            $notes[] = 'Google only: about ' . $this->money($in->searchTermWasteMicros) . ' went to search terms with no conversions. Reviewing search terms may save budget.';
        }

        return new AdsDecision(
            $state, $in->purposeName, $in->purposeUid, $headline, $reasons, $doNotChange, $nextReview,
            $cta[0] ?? null, $cta[1] ?? null, $in->focusCampaignUid, $evidence, $notes, $claimsProfit, $diagnosis, $this->kpis,
        );
    }

    private function money(?int $micros): string
    {
        return MetaAdsMoney::format($micros, $this->currency);
    }

    private static function article(string $noun): string
    {
        return (in_array(strtolower($noun[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ') . $noun;
    }

    private function multiple(float $multiple): string
    {
        return rtrim(rtrim(number_format($multiple, 1, '.', ''), '0'), '.') . '×';
    }
}
