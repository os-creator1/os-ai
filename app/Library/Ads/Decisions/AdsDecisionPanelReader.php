<?php

namespace App\Library\Ads\Decisions;

use App\Library\Acquisition\AcquisitionPurposeManager;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermReader;
use App\Models\AcquisitionPurpose;
use App\Models\Business;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Acquisition Purpose + Ads Decisioning V1 — builds the decision panel for ONE
 * provider's page from cached data. Read-only; no provider call; every query is
 * scoped by Business and the selected account.
 *
 * One decision per ACTIVE goal that has at least one of THIS provider's
 * campaigns assigned to it. Goals are never merged: each is judged only on its
 * own campaigns, its own pipeline and its own economics. A provider page never
 * judges a campaign that has no goal; it lists it as "assign a goal".
 */
final class AdsDecisionPanelReader
{
    public function __construct(
        private readonly AcquisitionPurposeManager $purposes,
        private readonly ProviderPurposeDeliveryReader $delivery,
        private readonly PurposeFunnelReader $funnel,
        private readonly GoogleAdsSearchTermReader $searchTerms,
        private readonly AdsDecisionEngine $engine,
    ) {
    }

    public function forGoogle(Business $business, GoogleAdsAccount $account, GoogleAdsPeriod $period): AdsDecisionPanel
    {
        $campaigns = GoogleAdsCampaign::query()
            ->where('business_id', $business->id)
            ->where('google_ads_account_id', $account->id)
            ->where('status', '<>', 'REMOVED')
            ->get(['id', 'uid', 'name', 'external_campaign_id', 'acquisition_purpose_id']);

        $spend = $this->googleSpendByCampaign($account, $period, $campaigns->pluck('external_campaign_id')->map(fn ($k): string => (string) $k)->all());
        $rows = $campaigns->map(fn ($c): array => [
            'id' => (int) $c->id, 'uid' => (string) $c->uid, 'name' => (string) $c->name,
            'purpose_id' => $c->acquisition_purpose_id === null ? null : (int) $c->acquisition_purpose_id,
            'spend_micros' => (int) ($spend[(string) $c->external_campaign_id] ?? 0),
        ])->all();

        return $this->build($business, 'google', (string) $account->currency_code, $rows, $period, function (AcquisitionPurpose $purpose, array $ids, array $campaignRows) use ($account, $period): array {
            $d = $this->delivery->google($account, $ids, $period->fromDate(), $period->toDate());
            $waste = 0;
            $hasWaste = false;

            foreach ($campaignRows as $row) {
                $summary = $this->searchTerms->wasteSummary($account, $period, $row['uid']);

                if ($summary->hasData && $summary->spendMicros !== null) {
                    $hasWaste = true;
                    $waste += $summary->spendMicros;
                }
            }

            return $d + ['waste_micros' => $hasWaste ? $waste : null];
        });
    }

    public function forMeta(Business $business, MetaAdsAccount $account, GoogleAdsPeriod $period): AdsDecisionPanel
    {
        $campaigns = MetaAdsCampaign::query()
            ->where('business_id', $business->id)
            ->where('meta_ads_account_id', $account->id)
            ->whereIn('status', ['ACTIVE', 'PAUSED'])
            ->get(['id', 'uid', 'name', 'acquisition_purpose_id']);

        $spend = $this->metaSpendByCampaign($account, $period, $campaigns->pluck('id')->map(fn ($i): int => (int) $i)->all());
        $rows = $campaigns->map(fn ($c): array => [
            'id' => (int) $c->id, 'uid' => (string) $c->uid, 'name' => (string) $c->name,
            'purpose_id' => $c->acquisition_purpose_id === null ? null : (int) $c->acquisition_purpose_id,
            'spend_micros' => (int) ($spend[(int) $c->id] ?? 0),
        ])->all();

        return $this->build($business, 'meta', (string) $account->currency_code, $rows, $period, function (AcquisitionPurpose $purpose, array $ids) use ($account, $period): array {
            return $this->delivery->meta($account, $ids, $period->fromDate(), $period->toDate()) + ['waste_micros' => null];
        });
    }

    /**
     * @param  list<array{id: int, uid: string, name: string, purpose_id: ?int, spend_micros: int}>  $rows
     * @param  callable(AcquisitionPurpose, list<int>, list<array{id: int, uid: string, name: string, purpose_id: ?int, spend_micros: int}>): array{spend_micros: int, impressions: int, clicks: int, results: ?float, waste_micros: ?int}  $deliveryFor
     */
    private function build(Business $business, string $provider, string $accountCurrency, array $rows, GoogleAdsPeriod $period, callable $deliveryFor): AdsDecisionPanel
    {
        $purposes = $this->purposes->forBusiness($business);
        $now = CarbonImmutable::now();
        $touches = null;
        $decisions = [];

        $businessCurrency = strtoupper(trim((string) $business->currency_code));
        $matches = $businessCurrency === '' || $businessCurrency === strtoupper(trim($accountCurrency));
        $from = CarbonImmutable::parse($period->fromDate(), $period->timezone);
        $to = CarbonImmutable::parse($period->toDate(), $period->timezone);

        foreach ($purposes as $purpose) {
            $mine = array_values(array_filter($rows, fn (array $r): bool => $r['purpose_id'] === (int) $purpose->id));

            if ($mine === []) {
                continue;
            }

            $touches ??= $this->funnel->attributedTouches((int) $business->id, $provider, $now);
            $ids = array_map(fn (array $r): int => $r['id'], $mine);
            $d = $deliveryFor($purpose, $ids, $mine);
            $facts = $this->funnel->read($purpose, $provider, $from, $to);
            $focus = collect($mine)->sortByDesc('spend_micros')->first();

            $decisions[] = $this->engine->decide(new AdsDecisionInput(
                provider: $provider,
                purposeName: (string) $purpose->name,
                outcomeType: (string) $purpose->outcome_type,
                labels: $this->labels($purpose),
                pipelineLinked: $purpose->crm_pipeline_id !== null,
                economics: $purpose->economicsProfile(),
                currency: $accountCurrency,
                currencyMatchesBusiness: $matches,
                spendMicros: $d['spend_micros'],
                impressions: $d['impressions'],
                clicks: $d['clicks'],
                providerResults: $d['results'],
                inquiries: $facts['inquiries'],
                qualified: $facts['qualified'],
                outcomes: $facts['outcomes'],
                attributedTouches: $touches,
                searchTermWasteMicros: $d['waste_micros'],
                focusCampaignUid: $focus['uid'] ?? null,
                purposeUid: (string) $purpose->uid,
                businessCurrency: $businessCurrency,
                milestone: $facts['milestone'],
                providerResultType: $d['result_type'] ?? null,
            ));
        }

        usort($decisions, fn (AdsDecision $a, AdsDecision $b): int => $b->state->urgency() <=> $a->state->urgency());

        $unassigned = collect($rows)
            ->filter(fn (array $r): bool => $r['purpose_id'] === null && $r['spend_micros'] > 0)
            ->sortByDesc('spend_micros')
            ->take(10)
            ->map(fn (array $r): array => ['uid' => $r['uid'], 'name' => $r['name'], 'spend_micros' => $r['spend_micros']])
            ->values()
            ->all();

        return new AdsDecisionPanel($decisions, $unassigned, $purposes->isNotEmpty(), $rows !== []);
    }

    /** @return array<string, string> */
    private function labels(AcquisitionPurpose $purpose): array
    {
        $labels = [];

        foreach (['person', 'lead', 'leads', 'outcome', 'outcomes', 'cost_per_lead', 'cost_per_outcome', 'pipeline_cta'] as $key) {
            $labels[$key] = $purpose->label($key);
        }

        if (is_string($purpose->labels['milestone_label'] ?? null)) {
            $labels['milestone_label'] = $purpose->labels['milestone_label'];
        }

        return $labels;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function googleSpendByCampaign(GoogleAdsAccount $account, GoogleAdsPeriod $period, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return DB::table('google_ads_daily_metrics')
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->where('level', 'campaign')
            ->whereIn('entity_key', $keys)
            ->whereBetween('metric_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('entity_key')
            ->selectRaw('entity_key, SUM(cost_micros) AS spend')
            ->pluck('spend', 'entity_key')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function metaSpendByCampaign(MetaAdsAccount $account, GoogleAdsPeriod $period, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('meta_ads_daily_insights')
            ->where('business_id', $account->business_id)
            ->where('meta_ads_account_id', $account->id)
            ->where('level', 'campaign')
            ->whereIn('entity_id', $ids)
            ->whereBetween('metric_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('entity_id')
            ->selectRaw('entity_id, SUM(spend_micros) AS spend')
            ->pluck('spend', 'entity_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }
}
