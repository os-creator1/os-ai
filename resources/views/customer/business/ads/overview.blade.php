{{--
    Google Ads Module V1 — the Overview.

    CACHED DATA ONLY: every figure comes from the normalised tables the daily
    sync fills, so switching the period (a plain GET link) never calls Google.
    Absent data is shown as an em dash, never as 0. Google conversions are
    labelled as such — never "Leads". All output is escaped Blade; no raw
    provider string is ever rendered unescaped.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Google Ads')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">
@endsection

@php
    use App\Library\GoogleAds\GoogleAdsMoney;
    use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;

    $periodLabels = [
        GoogleAdsPeriod::LAST_7 => '7 days',
        GoogleAdsPeriod::LAST_30 => '30 days',
        GoogleAdsPeriod::THIS_MONTH => 'This month',
        GoogleAdsPeriod::PREVIOUS_MONTH => 'Previous month',
    ];

    $ready = $adsState === 'ready' && $overview !== null;
    $currency = $account?->currency_code;
    $money = static fn (?int $micros): string => GoogleAdsMoney::format($micros, $currency);
    $dash = '—';
    $count = static function (?string $value) use ($dash): string {
        if ($value === null) {
            return $dash;
        }

        $float = (float) $value;

        return abs($float - round($float)) < 0.005 ? number_format((int) round($float)) : number_format($float, 2);
    };
    $percent = static fn (?float $rate): string => $rate === null ? '—' : number_format($rate * 100, 1) . '%';
@endphp

@section('content')
    @include('customer.business.ads._header', [
        'title' => 'Google Ads',
        'subtitle' => 'See where your Google Ads budget is turning into Google conversions, and what needs attention.',
    ])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads._empty-state')
    @else
        @include('customer.business.ads._decision-panel')

        @include('customer.business.ads._overview-period', [
            'periodUrl' => fn (string $key) => route('customer.workspaces.businesses.ads.index', [$workspaceUid, $businessUid, 'period' => $key]),
        ])

        @if(! $hasCampaigns)
            <x-card :padded="true" class="mb-2" data-role="no-campaigns">
                <x-empty-state icon="megaphone" title="No campaigns to show yet"
                               description="Your Google Ads account is connected, but we have not found any campaigns in it. Once you have campaigns running, their results will appear here after the next update." />
            </x-card>
        @elseif(! $overview->hasData)
            <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="no-period-data">
                @if($freshness !== null && $freshness->lastSuccessfulSyncAt === null)
                    We are still loading your first update from Google Ads. Your figures will appear here as soon as it finishes.
                @else
                    No ad activity was recorded for this period. Try a longer range, or check back after the next update.
                @endif
            </x-alert>
        @endif

        @if($hasCampaigns)
            <section class="ads-kpis" data-role="kpi-cards" aria-label="Key figures, {{ strtolower($periodLabels[$period->key]) }}">
                <div class="ads-kpi-main">
                    <div class="ads-kpi" data-role="kpi-spend">
                        <p class="ads-kpi-label">Spend</p>
                        <span class="ads-kpi-value" data-role="kpi-spend-value">{{ $money($overview->spendMicros) }}</span>
                        <p class="ads-kpi-note">
                            @include('customer.business.ads._overview-change', ['change' => $overview->comparison['spend_change'] ?? null, 'goodWhenUp' => true])
                            @if(($overview->comparison['spend_change'] ?? null) === null){{ $periodLabels[$period->key] }}@endif
                        </p>
                    </div>
                    <div class="ads-kpi" data-role="kpi-conversions">
                        <p class="ads-kpi-label">
                            Google conversions
                            <x-tooltip text="Conversions are the actions you have set up for counting in your Google Ads account, such as calls, form submissions or purchases. This is Google's count, not the leads recorded in this platform.">
                                <x-ds-icon name="info" size="13" aria-label="About Google conversions" />
                            </x-tooltip>
                        </p>
                        <span class="ads-kpi-value" data-role="kpi-conversions-value">{{ $count($overview->googleConversions) }}</span>
                        <p class="ads-kpi-note">
                            @include('customer.business.ads._overview-change', ['change' => $overview->comparison['conversions_change'] ?? null, 'goodWhenUp' => true])
                            @if(($overview->comparison['conversions_change'] ?? null) === null)Google's count, not CRM leads.@endif
                        </p>
                    </div>
                    <div class="ads-kpi" data-role="kpi-cpl">
                        <p class="ads-kpi-label">
                            Cost per conversion
                            <x-tooltip text="Spend divided by Google conversions. It shows a dash when there are no conversions yet. Google conversions are the conversion actions set up in your Google Ads account.">
                                <x-ds-icon name="info" size="13" aria-label="About cost per conversion" />
                            </x-tooltip>
                        </p>
                        <span class="ads-kpi-value" data-role="kpi-cpl-value">{{ $money($overview->cplMicros) }}</span>
                        <p class="ads-kpi-note">
                            @include('customer.business.ads._overview-change', ['change' => $overview->comparison['cpl_change'] ?? null, 'goodWhenUp' => false])
                            @if(($overview->comparison['cpl_change'] ?? null) === null)Spend divided by Google conversions.@endif
                        </p>
                    </div>
                </div>
                <div class="ads-kpi-secondary">
                    <div class="ads-kpi-mini" data-role="kpi-clicks">
                        <span>Clicks</span>
                        <span data-role="kpi-clicks-value">{{ $overview->clicks === null ? $dash : number_format($overview->clicks) }}</span>
                    </div>
                    <div class="ads-kpi-mini" data-role="kpi-impressions">
                        <span>Impressions</span>
                        <span data-role="kpi-impressions-value">{{ $overview->impressions === null ? $dash : number_format($overview->impressions) }}</span>
                    </div>
                    <div class="ads-kpi-mini" data-role="kpi-conversion-rate">
                        <span>Conversion rate</span>
                        <span data-role="kpi-conversion-rate-value">{{ $percent($overview->conversionRate) }}</span>
                    </div>
                    <div class="ads-kpi-mini" data-role="kpi-conversion-value">
                        <span>Conversion value (Google)</span>
                        <span data-role="kpi-conversion-value-value">{{ $overview->conversionValue === null ? '—' : GoogleAdsMoney::format((int) round((float) $overview->conversionValue * GoogleAdsMoney::MICROS_PER_UNIT), $currency) }}</span>
                        <p class="ads-kpi-note">
                            @if($overview->conversionValue === null)
                                Shown when your conversions carry a value in Google Ads.
                            @else
                                Google's value, not sales recorded here.
                            @endif
                        </p>
                    </div>
                    <div class="ads-kpi-mini" data-role="kpi-spend-this-month">
                        <span>Spend this month</span>
                        <span data-role="kpi-spend-this-month-value">{{ $money($overview->pacing->spentMicros) }}</span>
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

            @if($overview->hasData && ! ($overview->coverage['covered'] ?? true))
                <p class="text-caption mb-2" data-role="coverage-note">Part of this period is older than the history we keep, so the figures above may be incomplete.</p>
            @endif

            @php
                $hasCplData = collect($trend['series']['cpl'] ?? [])->filter(fn ($v) => $v !== null)->isNotEmpty();
                $decisionState = ($decisionView['primary']['decision'] ?? null)?->state;
            @endphp

            <div class="ads-grid">
                <section class="cx-card" data-role="trend-card">
                    <div class="cx-card-head is-plain">
                        <h2 class="cx-card-title">Daily trend</h2>
                        <div class="cx-seg" role="group" aria-label="Chart metric" data-role="chart-tabs">
                            <button type="button" class="is-active" data-chart-tab="spend" aria-pressed="true">Spend and conversions</button>
                            @if($hasCplData)
                                <button type="button" data-chart-tab="cpr" aria-pressed="false">Cost per conversion</button>
                            @endif
                        </div>
                    </div>
                    <div class="cx-card-body">
                        <div id="ads-trend-chart" data-role="chart-trend" data-series-url="{{ $seriesUrl }}"
                             data-results-key="conversions" data-results-label="Google conversions" data-cpr-key="cpl" data-cpr-label="Cost per conversion"
                             role="img" aria-label="Chart of daily spend, Google conversions and cost per conversion for the selected period" style="min-height: 320px;"></div>
                        <details class="ads-trend-details mt-1" data-role="trend-table">
                            <summary>Show daily figures</summary>
                            <x-table :headers="['Day', 'Spend', 'Google conversions', 'Cost per conversion']" class="mt-1">
                                @foreach($trend['labels'] as $i => $label)
                                    <tr>
                                        <td>{{ $trend['tooltips'][$i] ?? $label }}</td>
                                        <td class="text-numeric">{{ $money($trend['series']['spend_micros'][$i] ?? null) }}</td>
                                        <td class="text-numeric">{{ $trend['series']['conversions'][$i] === null ? '—' : $count((string) $trend['series']['conversions'][$i]) }}</td>
                                        <td class="text-numeric">{{ $trend['series']['cpl'][$i] === null ? '—' : GoogleAdsMoney::format((int) round($trend['series']['cpl'][$i] * GoogleAdsMoney::MICROS_PER_UNIT), $currency) }}</td>
                                    </tr>
                                @endforeach
                            </x-table>
                        </details>
                    </div>
                </section>

                <div class="ads-side">
                    @include('customer.business.ads._overview-attention', [
                        'attention' => $attention ?? null,
                        'extraItems' => [],
                        'decisionState' => $decisionState,
                        'providerName' => 'Google Ads',
                        'upgradeNote' => 'Google Ads recommendations (wasted spend, campaign and budget checks) are part of the Growth plan.',
                    ])
                    @include('customer.business.ads._overview-pacing', [
                        'pacing' => $overview->pacing,
                        'projectedMicros' => $overview->projectedMonthEndSpendMicros,
                        'projectionLowConfidence' => $overview->projectionLowConfidence,
                        'budgetUrl' => route('customer.workspaces.businesses.ads.settings', [$workspaceUid, $businessUid]) . '#monthly_budget_target',
                        'canManageBudget' => \Illuminate\Support\Facades\Gate::allows('manage_google_ads'),
                    ])
                </div>
            </div>

            @if($waste !== null && $waste->hasData && ($waste->termCount ?? 0) > 0 && \Illuminate\Support\Facades\Route::has('customer.workspaces.businesses.ads.search-terms.index'))
                <section class="cx-card" data-role="waste-teaser">
                    <div class="cx-card-body d-flex flex-wrap align-items-center justify-content-between gap-1">
                        <div>
                            <h2 class="cx-card-title mb-25">Money wasted?</h2>
                            <p class="mb-0 text-caption">
                                {{ number_format($waste->termCount) }} search {{ $waste->termCount === 1 ? 'term' : 'terms' }} spent
                                {{ $money($waste->spendMicros) }} {{ strtolower($periodLabels[$period->key]) }} without a single conversion.
                            </p>
                        </div>
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('customer.workspaces.businesses.ads.search-terms.index', [$workspaceUid, $businessUid]) }}">Review search terms</a>
                    </div>
                </section>
            @endif
        @endif

        @unless($adsHasModule)
            <x-card :padded="true" class="mb-2" data-role="core-note">
                <p class="mb-0 text-caption">
                    You are seeing the Ads overview. Campaign, keyword, search-term and budget tools, and the ability to act on what is wasting money, are part of the Growth plan.
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
