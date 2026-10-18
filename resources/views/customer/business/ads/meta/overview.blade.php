{{--
    Meta Ads Module V1 (contract 24 §5/§9) — the Meta Overview.

    CACHED DATA ONLY: every figure comes from the normalised tables the sync
    fills, so switching the period (a plain GET link) never calls Meta. Absent
    data is an em dash, never 0. "Results" always carries the owner-chosen
    result type's label; until one is chosen results are UNAVAILABLE and the
    page says "Choose a result type". Amounts are in the AD ACCOUNT currency.
    No Meta id is ever rendered; every provider/customer string is escaped.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Meta Ads')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">
@endsection

@php
    use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
    use App\Library\MetaAds\MetaAdsDisplay as D;

    $periodLabels = [
        GoogleAdsPeriod::LAST_7 => '7 days',
        GoogleAdsPeriod::LAST_30 => '30 days',
        GoogleAdsPeriod::THIS_MONTH => 'This month',
        GoogleAdsPeriod::PREVIOUS_MONTH => 'Previous month',
    ];

    $ready = $metaState === 'ready' && $overview !== null;
    $currency = $account?->currency_code;
    $money = static fn (?int $micros): string => D::money($micros, $currency);
    $differsFromBusiness = $account !== null && $business->currency_code && strtoupper((string) $business->currency_code) !== strtoupper((string) $currency);
    $resultLabel = $ready ? ($overview->resultTypeLabel ?? null) : null;
@endphp

@section('content')
    @include('customer.business.ads._header', [
        'title' => 'Meta Ads',
        'subtitle' => 'See where your Meta ad budget is turning into results, and what needs attention.',
        'provider' => 'meta',
    ])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads.meta._empty-state')
    @else
        @include('customer.business.ads._decision-panel')

        @include('customer.business.ads._overview-period', [
            'periodUrl' => fn (string $key) => route('customer.workspaces.businesses.ads.meta.index', [$workspaceUid, $businessUid, 'period' => $key]),
        ])

        @if($differsFromBusiness)
            <div class="cx-callout is-info mb-2" data-role="currency-note">
                <x-ds-icon name="info" size="15" aria-hidden="true" />
                <span class="cx-callout-text">Amounts are in the ad account currency ({{ $currency }}), which differs from your business currency ({{ strtoupper((string) $business->currency_code) }}).</span>
            </div>
        @endif

        @if(! $hasCampaigns)
            <x-card :padded="true" class="mb-2" data-role="no-campaigns">
                <x-empty-state icon="megaphone" title="No campaigns to show yet"
                               description="Your Meta ad account is connected, but we have not found any campaigns in it. Once you have campaigns running, their results will appear here after the next update." />
            </x-card>
        @elseif(! $overview->hasData)
            <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="no-period-data">
                @if($freshness !== null && $freshness->lastSuccessfulSyncAt === null)
                    We are still loading your first update from Meta. Your figures will appear here as soon as it finishes.
                @else
                    No ad activity was recorded for this period. Try a longer range, or check back after the next update.
                @endif
            </x-alert>
        @endif

        @if($hasCampaigns)
            @if($overview->resultTypeUnset)
                @include('customer.business.ads.meta._result-type-prompt')
            @endif

            <section class="ads-kpis" data-role="kpi-cards" aria-label="Key figures, {{ strtolower($periodLabels[$period->key]) }}">
                <div class="ads-kpi-main">
                    <div class="ads-kpi" data-role="kpi-spend">
                        <p class="ads-kpi-label">Spend <x-badge variant="neutral" data-role="account-currency">{{ $currency }}</x-badge></p>
                        <span class="ads-kpi-value" data-role="kpi-spend-value">{{ $money($overview->spendMicros) }}</span>
                        <p class="ads-kpi-note">
                            @include('customer.business.ads._overview-change', ['change' => $overview->comparison['spend_change'] ?? null, 'goodWhenUp' => true])
                            @if(($overview->comparison['spend_change'] ?? null) === null){{ $periodLabels[$period->key] }}@endif
                        </p>
                    </div>
                    <div class="ads-kpi" data-role="kpi-results">
                        <p class="ads-kpi-label">
                            Results @if($resultLabel)<span data-role="result-type-label">· {{ $resultLabel }}</span>@endif
                            <x-tooltip text="Results are the one kind of action you chose in Settings, counted by Meta. This is Meta's count, not the leads recorded in this platform.">
                                <x-ds-icon name="info" size="13" aria-label="About Meta results" />
                            </x-tooltip>
                        </p>
                        @if($overview->resultTypeUnset)
                            <span class="ads-kpi-value" data-role="kpi-results-value">&mdash;</span>
                            <p class="ads-kpi-note"><a href="{{ route('customer.workspaces.businesses.ads.meta.settings', [$workspaceUid, $businessUid]) }}">Choose a result type</a></p>
                        @else
                            <span class="ads-kpi-value" data-role="kpi-results-value">{{ D::count($overview->results) }}</span>
                            <p class="ads-kpi-note">
                                @include('customer.business.ads._overview-change', ['change' => $overview->comparison['results_change'] ?? null, 'goodWhenUp' => true])
                                @if(($overview->comparison['results_change'] ?? null) === null)Meta's count, not CRM leads.@endif
                            </p>
                        @endif
                    </div>
                    <div class="ads-kpi" data-role="kpi-cpr">
                        <p class="ads-kpi-label">Cost per result</p>
                        <span class="ads-kpi-value" data-role="kpi-cpr-value">{{ $money($overview->costPerResultMicros) }}</span>
                        <p class="ads-kpi-note">
                            @if($overview->resultTypeUnset)
                                Shown once you choose a result type.
                            @elseif($overview->targetCostPerResultMicros !== null)
                                Target {{ $money($overview->targetCostPerResultMicros) }}
                            @else
                                Spend divided by results.
                            @endif
                        </p>
                    </div>
                </div>
                <div class="ads-kpi-secondary">
                    <div class="ads-kpi-mini" data-role="kpi-impressions">
                        <span>Impressions</span>
                        <span data-role="kpi-impressions-value">{{ D::integer($overview->impressions) }}</span>
                    </div>
                    <div class="ads-kpi-mini" data-role="kpi-link-clicks">
                        <span>Link clicks</span>
                        <span data-role="kpi-link-clicks-value">{{ D::integer($overview->linkClicks) }}</span>
                    </div>
                    <div class="ads-kpi-mini" data-role="kpi-projected">
                        <span>Projected month-end spend</span>
                        <span data-role="kpi-projected-value">{{ $money($overview->projectedMonthEndSpendMicros) }}</span>
                        <p class="ads-kpi-note" data-role="kpi-projected-note">
                            @if($overview->projectedMonthEndSpendMicros === null)
                                We need a few days of data to estimate this.
                            @elseif($overview->projectionLowConfidence)
                                Early estimate: based on only a few days of data, so it may change.
                            @else
                                Based on this month's daily average so far.
                            @endif
                        </p>
                    </div>
                </div>
            </section>

            @if($overview->resultValue !== null)
                <p class="text-caption mb-2" data-role="meta-value-note">Meta-reported value of these results: {{ D::decimalMoney($overview->resultValue, $currency) }}. This is Meta's figure, not sales recorded here.</p>
            @endif

            @if($overview->hasData && ! ($overview->coverage['covered'] ?? true))
                <p class="text-caption mb-2" data-role="coverage-note">Part of this period is older than the history we keep, so the figures above may be incomplete.</p>
            @endif

            @php
                $resultsName = $resultLabel ?: 'Results';
                $hasCprData = collect($trend['series']['cost_per_result'] ?? [])->filter(fn ($v) => $v !== null)->isNotEmpty();
                $decisionState = ($decisionView['primary']['decision'] ?? null)?->state;
                $setupItems = $overview->resultTypeUnset ? [[
                    'title' => 'Choose a result type',
                    'text' => 'Results and cost per result stay empty until you pick which Meta action counts as a result.',
                    'url' => route('customer.workspaces.businesses.ads.meta.settings', [$workspaceUid, $businessUid]),
                    'label' => 'Open settings',
                ]] : [];
            @endphp

            <div class="ads-grid">
                <section class="cx-card" data-role="trend-card">
                    <div class="cx-card-head is-plain">
                        <h2 class="cx-card-title">Daily trend</h2>
                        <div class="cx-seg" role="group" aria-label="Chart metric" data-role="chart-tabs">
                            <button type="button" class="is-active" data-chart-tab="spend" aria-pressed="true">Spend and results</button>
                            @if($hasCprData)
                                <button type="button" data-chart-tab="cpr" aria-pressed="false">Cost per result</button>
                            @endif
                        </div>
                    </div>
                    <div class="cx-card-body">
                        <div id="ads-trend-chart" data-role="chart-trend" data-series-url="{{ $seriesUrl }}"
                             data-results-key="results" data-results-label="{{ $resultsName }}" data-cpr-key="cost_per_result" data-cpr-label="Cost per result"
                             role="img" aria-label="Chart of daily spend, Meta results and cost per result for the selected period" style="min-height: 320px;"></div>
                        <details class="ads-trend-details mt-1" data-role="trend-table">
                            <summary>Show daily figures</summary>
                            <x-table :headers="['Day', 'Spend', 'Results', 'Cost per result']" class="mt-1">
                                @foreach($trend['labels'] as $i => $label)
                                    <tr>
                                        <td>{{ $trend['tooltips'][$i] ?? $label }}</td>
                                        <td class="text-numeric">{{ $money($trend['series']['spend_micros'][$i] ?? null) }}</td>
                                        <td class="text-numeric">{{ ($trend['series']['results'][$i] ?? null) === null ? '—' : D::count((string) $trend['series']['results'][$i]) }}</td>
                                        <td class="text-numeric">{{ ($trend['series']['cost_per_result'][$i] ?? null) === null ? '—' : D::money((int) round($trend['series']['cost_per_result'][$i] * 1000000), $currency) }}</td>
                                    </tr>
                                @endforeach
                            </x-table>
                        </details>
                    </div>
                </section>

                <div class="ads-side">
                    @include('customer.business.ads._overview-attention', [
                        'attention' => $attention,
                        'extraItems' => $setupItems,
                        'decisionState' => $decisionState,
                        'providerName' => 'Meta',
                    ])
                    @include('customer.business.ads._overview-pacing', [
                        'pacing' => $overview->pacing,
                        'projectedMicros' => $overview->projectedMonthEndSpendMicros,
                        'projectionLowConfidence' => $overview->projectionLowConfidence,
                        'budgetUrl' => route('customer.workspaces.businesses.ads.meta.settings', [$workspaceUid, $businessUid]) . '#monthly_budget_target',
                        'canManageBudget' => (bool) ($metaCanAct ?? false),
                    ])
                </div>
            </div>
        @endif

        @unless($metaHasModule)
            <x-card :padded="true" class="mb-2" data-role="core-note">
                <p class="mb-0 text-caption">
                    You are seeing the Meta Ads overview. Campaign, ad set, ad and leads tools, and the ability to pause or resume ads, are part of the Growth plan.
                </p>
            </x-card>
        @endunless
    @endif
@endsection

@if($ready && $hasCampaigns)
    @section('vendor-script')
        <script src="{{ asset(mix('vendors/js/charts/apexcharts.min.js')) }}"></script>
    @endsection

    @section('page-script')
        @include('customer.business.ads._overview-chart-script')
    @endsection
@endif
