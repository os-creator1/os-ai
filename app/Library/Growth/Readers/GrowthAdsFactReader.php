<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Growth\GrowthFactStatus;
use App\Library\Ads\AdsFeatureAccess;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleAds\GoogleAdsConnectionManager;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationFactReader;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationType;
use App\Library\GoogleAds\Sync\GoogleAdsFreshness;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\MetaAds\MetaAdsConnectionManager;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationFactReader;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationType;
use App\Library\MetaAds\Sync\MetaAdsFreshness;
use App\Library\Money\CurrencyExponent;
use App\Models\Business;
use App\Models\GoogleAdsAccount;
use App\Models\MetaAdsAccount;
use Carbon\CarbonImmutable;

/**
 * Google + Meta Ads facts (domain `ads`), consumed from the Ads modules' OWN deterministic
 * recommendation fact readers (contract 24 §13): Growth recomputes no metric, calls no
 * provider and reads cached tables only.
 *
 * ENTITLEMENT. No facts unless the Business holds the full Ads module (the same keys the Ads
 * pages use); otherwise the domain is NOT ENTITLED (neutral, never "fix your Ads").
 *
 * SCORE SAFETY. A provider's facts count only once its account has SETTLED (selected at least
 * `ads_settling_days` ago, data present) and its sync is FRESH. Connecting Ads, a stale sync or a
 * fresh account therefore makes the performance rules INSUFFICIENT — excluded from the score,
 * never failed.
 *
 * Fact shape:
 *   providers   array{google: string, meta: string}  not_connected | needs_reconnect | no_account | ready
 *   connected   bool   at least one provider is ready
 *   sufficient  bool   at least one ready provider is settled and fresh
 *   stale       bool   a ready provider's sync is stale
 *   counts      array<string, int>   zero_spend | cpl_above | pacing_over | term_waste | delivery_issue | fatigue | strong
 *   money       array<string, array{minor: int, currency: string}|null>   zero_spend | cpl_above | term_waste
 *   decisions   array<string, int>   Acquisition Purpose + Ads Decisioning V1 verdicts, counted by state across the settled
 *               providers' goals: act | fix_the_funnel | check_tracking | watch | wait | not_enough_data | keep_running,
 *               plus `unassigned_campaigns` (live campaigns with no goal). A READ of AdsDecisionPanelReader's deterministic
 *               states: Growth adds no scoring or lifecycle of its own, and every CTA still points at the Ads module.
 */
final class GrowthAdsFactReader implements GrowthFactReader
{
    private const COUNT_KEYS = ['zero_spend', 'cpl_above', 'pacing_over', 'term_waste', 'delivery_issue', 'fatigue', 'strong'];

    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly GoogleAdsConnectionManager $googleConnections,
        private readonly MetaAdsConnectionManager $metaConnections,
        private readonly GoogleAdsRecommendationFactReader $googleFacts,
        private readonly MetaAdsRecommendationFactReader $metaFacts,
        private readonly GoogleAdsFreshness $googleFreshness,
        private readonly MetaAdsFreshness $metaFreshness,
        private readonly ?\App\Library\Ads\Decisions\AdsDecisionPanelReader $decisionPanels = null,
    ) {
    }

    public function domain(): string
    {
        return 'ads';
    }

    public function feature(): ?PlatformFeature
    {
        return null; // gated here, per the Ads module's own key set
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $workspace = $business->workspace;
        $decisions = $workspace === null ? [] : $this->entitlements->snapshotBusinessFeatureDecisions($workspace, $business, AdsFeatureAccess::fullModuleKeys(), 0);

        if (! collect($decisions)->contains(fn ($d) => $d->allowed)) {
            return GrowthFactSet::withStatus($this->domain(), GrowthFactStatus::NotEntitled);
        }

        $settledBefore = $now->subDays($thresholds->get('ads_settling_days'));
        $counts = array_fill_keys(self::COUNT_KEYS, 0);
        $spend = ['zero_spend' => [], 'cpl_above' => [], 'term_waste' => []];
        $providers = ['google' => 'not_connected', 'meta' => 'not_connected'];
        $sufficient = false;
        $sufficientBy = ['google' => false, 'meta' => false];
        $stale = false;

        $google = $this->googleConnections->findForBusiness($business);
        $googleAccount = null;

        if ($google !== null) {
            $providers['google'] = $google->isActive() ? 'no_account' : 'needs_reconnect';

            if ($google->isActive()) {
                $googleAccount = GoogleAdsAccount::query()
                    ->where('business_id', $business->id)
                    ->where('business_google_connection_id', $google->id)
                    ->whereNotNull('selected_at')
                    ->first();
            }
        }

        if ($googleAccount !== null) {
            $providers['google'] = 'ready';
            $fresh = $this->googleFreshness->for($googleAccount);
            $stale = $stale || $fresh->shouldWarn();

            if ($this->settled($googleAccount->selected_at, $fresh->dataThroughDate, $fresh->shouldWarn(), $settledBefore)) {
                $sufficient = true;
                $sufficientBy['google'] = true;

                foreach ($this->googleFacts->facts($googleAccount, null, $now) as $fact) {
                    $key = match ($fact->type) {
                        GoogleAdsRecommendationType::ZeroConversionCampaign => 'zero_spend',
                        GoogleAdsRecommendationType::CplAboveTarget => 'cpl_above',
                        GoogleAdsRecommendationType::PacingOver => 'pacing_over',
                        GoogleAdsRecommendationType::WastedSearchTerms => 'term_waste',
                        GoogleAdsRecommendationType::StrongCampaign => 'strong',
                        default => null,
                    };

                    if ($key === null) {
                        continue;
                    }

                    $counts[$key]++;
                    $micros = $key === 'term_waste' ? (int) ($fact->evidence['wasted_spend_micros'] ?? 0) : $fact->rankSpendMicros;
                    $this->addSpend($spend, $key, $micros, $fact->currencyCode);
                }
            }
        }

        $meta = $this->metaConnections->findForBusiness($business);
        $metaAccount = null;

        if ($meta !== null) {
            $providers['meta'] = $meta->isActive() ? 'no_account' : 'needs_reconnect';

            if ($meta->isActive()) {
                $metaAccount = MetaAdsAccount::query()
                    ->where('business_id', $business->id)
                    ->where('business_meta_connection_id', $meta->id)
                    ->whereNotNull('selected_at')
                    ->first();
            }
        }

        if ($metaAccount !== null) {
            $providers['meta'] = 'ready';
            $fresh = $this->metaFreshness->for($metaAccount);
            $stale = $stale || $fresh->shouldWarn();

            if ($this->settled($metaAccount->selected_at, $fresh->dataThroughDate, $fresh->shouldWarn(), $settledBefore)) {
                $sufficient = true;
                $sufficientBy['meta'] = true;

                foreach ($this->metaFacts->facts($metaAccount, null, $now) as $fact) {
                    $key = match ($fact->type) {
                        MetaAdsRecommendationType::ZeroResultSpend => 'zero_spend',
                        MetaAdsRecommendationType::CostPerResultAboveTarget => 'cpl_above',
                        MetaAdsRecommendationType::PacingOver => 'pacing_over',
                        MetaAdsRecommendationType::DeliveryIssue => 'delivery_issue',
                        MetaAdsRecommendationType::HighFrequencyWeakResults => 'fatigue',
                        MetaAdsRecommendationType::StrongPerformer => 'strong',
                        default => null,
                    };

                    if ($key === null) {
                        continue;
                    }

                    $counts[$key]++;
                    $this->addSpend($spend, $key, $fact->rankSpendMicros, $fact->currencyCode);
                }
            }
        }

        $decisions = array_fill_keys(array_map(fn (\App\Library\Ads\Decisions\AdsDecisionState $s): string => $s->value, \App\Library\Ads\Decisions\AdsDecisionState::cases()), 0) + ['unassigned_campaigns' => 0];
        $panels = $this->decisionPanels ?? app(\App\Library\Ads\Decisions\AdsDecisionPanelReader::class);

        foreach (array_filter([
            $sufficientBy['google'] && $googleAccount !== null ? $panels->forGoogle($business, $googleAccount, \App\Library\GoogleAds\Reporting\GoogleAdsPeriod::resolve(null, $googleAccount, $now)) : null,
            $sufficientBy['meta'] && $metaAccount !== null ? $panels->forMeta($business, $metaAccount, \App\Library\MetaAds\Reporting\MetaAdsPeriod::resolve(null, $metaAccount, $now)) : null,
        ]) as $panel) {
            foreach ($panel->decisions as $decision) {
                $decisions[$decision->state->value]++;
            }

            $decisions['unassigned_campaigns'] += count($panel->unassigned);
        }

        return GrowthFactSet::available($this->domain(), [
            'providers' => $providers,
            'connected' => in_array('ready', $providers, true),
            'sufficient' => $sufficient,
            'sufficient_by' => $sufficientBy,
            'stale' => $stale,
            'counts' => $counts,
            'money' => array_map(fn (array $byCurrency) => $this->money($byCurrency), $spend),
            'decisions' => $decisions,
        ]);
    }

    private function settled(?\DateTimeInterface $selectedAt, mixed $dataThrough, bool $staleWarn, CarbonImmutable $settledBefore): bool
    {
        return $selectedAt !== null
            && CarbonImmutable::instance($selectedAt)->lte($settledBefore)
            && $dataThrough !== null
            && ! $staleWarn;
    }

    /** @param array<string, array<string, int>> $spend */
    private function addSpend(array &$spend, string $key, int $micros, string $currency): void
    {
        if (isset($spend[$key]) && $micros > 0) {
            $spend[$key][$currency] = ($spend[$key][$currency] ?? 0) + $micros;
        }
    }

    /**
     * One currency with a 2-decimal exponent becomes {minor, currency}; anything else is null —
     * Growth never adds across currencies or guesses a conversion.
     *
     * @param array<string, int> $byCurrency
     * @return array{minor: int, currency: string}|null
     */
    private function money(array $byCurrency): ?array
    {
        if (count($byCurrency) !== 1) {
            return null;
        }

        $currency = (string) array_key_first($byCurrency);

        if (CurrencyExponent::for($currency) !== 2) {
            return null;
        }

        return ['minor' => intdiv((int) $byCurrency[$currency], 10_000), 'currency' => $currency];
    }
}
