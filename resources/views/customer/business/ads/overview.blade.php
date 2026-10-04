{{--
    Google Ads Module V1 — the Overview.

    CACHED DATA ONLY: every figure comes from the normalised tables the daily
    sync fills, so switching the period (a plain GET link) never calls Google.
    Absent data is shown as an em dash, never as 0. Google conversions are
    labelled as such — never "Leads". All output is escaped Blade; no raw
    provider string is ever rendered unescaped.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Ads')

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
    $change = static function (?float $change): ?string {
        if ($change === null) {
            return null;
        }

        return ($change >= 0 ? '+' : '') . number_format($change * 100, 0) . '% vs previous period';
    };
@endphp

@section('content')
    @include('customer.business.ads._header', [
        'title' => 'Ads',
        'subtitle' => 'See where your budget is turning into leads — and what is wasting money.',
    ])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads._empty-state')
    @else
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2" data-role="period-selector">
            <div class="btn-group" role="group" aria-label="Period">
                @foreach($periodLabels as $key => $label)
                    <a href="{{ route('customer.workspaces.businesses.ads.index', [$workspaceUid, $businessUid, 'period' => $key]) }}"
                       class="btn btn-sm {{ $period->key === $key ? 'btn-primary' : 'btn-outline-secondary' }}"
                       @if($period->key === $key) aria-current="true" @endif
                       data-period="{{ $key }}">{{ $label }}</a>
                @endforeach
            </div>
            <span class="text-caption text-muted" data-role="period-range">{{ $period->from->format('M j') }} &ndash; {{ $period->to->format('M j, Y') }}</span>
        </div>

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
            <div class="row" data-role="kpi-cards">
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-spend-this-month" class="h-100">
                        <p class="text-label mb-1">Spend this month</p>
                        <p class="h2 mb-0" data-role="kpi-spend-this-month-value">{{ $money($overview->pacing->spentMicros) }}</p>
                        <p class="text-caption text-muted mb-0">Month to date, in your account currency.</p>
                    </x-card>
                </div>
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-conversions" class="h-100">
                        <p class="text-label mb-1">
                            Google conversions
                            <x-tooltip text="Conversions are the actions you have set up for counting in your Google Ads account, such as calls, form submissions or purchases. This is Google's count, not the leads recorded in this platform.">
                                <x-ds-icon name="info" size="14" aria-label="About Google conversions" />
                            </x-tooltip>
                        </p>
                        <p class="h2 mb-0" data-role="kpi-conversions-value">{{ $count($overview->googleConversions) }}</p>
                        <p class="text-caption text-muted mb-0">{{ $periodLabels[$period->key] }}@if($change($overview->comparison['conversions_change'] ?? null)) &middot; {{ $change($overview->comparison['conversions_change']) }}@endif</p>
                    </x-card>
                </div>
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-cpl" class="h-100">
                        <p class="text-label mb-1">
                            Cost per conversion
                            <x-tooltip text="Spend divided by Google conversions. It shows a dash when there are no conversions yet. Google conversions are the conversion actions set up in your Google Ads account.">
                                <x-ds-icon name="info" size="14" aria-label="About cost per conversion" />
                            </x-tooltip>
                        </p>
                        <p class="h2 mb-0" data-role="kpi-cpl-value">{{ $money($overview->cplMicros) }}</p>
                        <p class="text-caption text-muted mb-0">{{ $periodLabels[$period->key] }}@if($change($overview->comparison['cpl_change'] ?? null)) &middot; {{ $change($overview->comparison['cpl_change']) }}@endif</p>
                    </x-card>
                </div>
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-conversion-rate" class="h-100">
                        <p class="text-label mb-1">Conversion rate</p>
                        <p class="h2 mb-0" data-role="kpi-conversion-rate-value">{{ $percent($overview->conversionRate) }}</p>
                        <p class="text-caption text-muted mb-0">Conversions per click, {{ strtolower($periodLabels[$period->key]) }}.</p>
                    </x-card>
                </div>
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-conversion-value" class="h-100">
                        <p class="text-label mb-1">Conversion value (Google)</p>
                        <p class="h2 mb-0" data-role="kpi-conversion-value-value">
                            {{ $overview->conversionValue === null ? '—' : GoogleAdsMoney::format((int) round((float) $overview->conversionValue * GoogleAdsMoney::MICROS_PER_UNIT), $currency) }}
                        </p>
                        <p class="text-caption text-muted mb-0">
                            @if($overview->conversionValue === null)
                                Shown when your conversions carry a value in Google Ads.
                            @else
                                The value Google Ads assigns to conversions. Not the same as sales recorded here.
                            @endif
                        </p>
                    </x-card>
                </div>
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-projected" class="h-100">
                        <p class="text-label mb-1">Projected month-end spend</p>
                        <p class="h2 mb-0" data-role="kpi-projected-value">{{ $money($overview->projectedMonthEndSpendMicros) }}</p>
                        <p class="text-caption text-muted mb-0" data-role="kpi-projected-note">
                            @if($overview->projectedMonthEndSpendMicros === null)
                                We need a few days of data to estimate this.
                            @elseif($overview->projectionLowConfidence)
                                Early estimate: based on only a few days of data, so it may change.
                            @else
                                Based on this month's daily average so far.
                            @endif
                        </p>
                    </x-card>
                </div>
            </div>

            @if($overview->hasData && ! ($overview->coverage['covered'] ?? true))
                <p class="text-caption text-muted mb-2" data-role="coverage-note">Part of this period is older than the history we keep, so the figures above may be incomplete.</p>
            @endif

            <x-card :padded="true" class="mb-2" data-role="trend-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
                    <h2 class="text-section-heading mb-0">Trend</h2>
                    <div class="btn-group" role="group" aria-label="Chart metric" data-role="chart-tabs">
                        <button type="button" class="btn btn-sm btn-primary" data-chart-tab="spend" aria-pressed="true">Spend and conversions</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-chart-tab="cpl" aria-pressed="false">Cost per conversion</button>
                    </div>
                </div>
                <div id="ads-trend-chart" data-role="chart-trend" data-series-url="{{ $seriesUrl }}" role="img" aria-label="Chart of daily spend, Google conversions and cost per conversion for the selected period" style="min-height: 280px;"></div>
                <details class="mt-1" data-role="trend-table">
                    <summary class="text-caption">Show daily figures</summary>
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
            </x-card>

            @if($waste !== null && $waste->hasData && ($waste->termCount ?? 0) > 0 && \Illuminate\Support\Facades\Route::has('customer.workspaces.businesses.ads.search-terms.index'))
                <x-card :padded="true" class="mb-2" data-role="waste-teaser">
                    <h2 class="text-section-heading mb-1">Money wasted?</h2>
                    <p class="mb-1">
                        {{ number_format($waste->termCount) }} search {{ $waste->termCount === 1 ? 'term' : 'terms' }} spent
                        {{ $money($waste->spendMicros) }} {{ strtolower($periodLabels[$period->key]) }} without a single conversion.
                    </p>
                    <a href="{{ route('customer.workspaces.businesses.ads.search-terms.index', [$workspaceUid, $businessUid]) }}">Review search terms</a>
                </x-card>
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
        <script>
            (function () {
                var el = document.getElementById('ads-trend-chart');
                if (!el || !window.ApexCharts || !window.PlatformTheme) { return; }

                // Colours and grid come only from the shared token namespace.
                var theme = window.PlatformTheme;
                var palette = theme.chartPalette();
                var chart = null;
                var payload = null;
                var tab = 'spend';

                function money(value) {
                    if (value === null || value === undefined) { return '—'; }
                    try {
                        return new Intl.NumberFormat(undefined, { style: 'currency', currency: payload.currency }).format(value);
                    } catch (e) {
                        return String(value);
                    }
                }

                function options() {
                    var width = el.clientWidth || 600;
                    var fitting = Math.max(2, Math.floor(width / 110));
                    var base = {
                        chart: { height: 280, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
                        grid: { borderColor: theme.chartGrid() },
                        xaxis: {
                            type: 'category', categories: payload.labels,
                            tickAmount: payload.labels.length > fitting ? fitting : undefined,
                            labels: { style: { colors: theme.chartAxis() }, rotate: 0, rotateAlways: false, hideOverlappingLabels: true, trim: false },
                            tooltip: { enabled: false }
                        },
                        tooltip: { theme: 'dark', x: { formatter: function (v, o) { return payload.tooltips[o.dataPointIndex] || v; } } },
                        dataLabels: { enabled: false },
                        legend: { labels: { colors: theme.chartAxis() } },
                        noData: { text: 'No data for this period yet' }
                    };

                    if (tab === 'cpl') {
                        return Object.assign(base, {
                            chart: Object.assign({ type: 'line' }, base.chart),
                            colors: [palette[2] || palette[0]],
                            series: [{ name: 'Cost per conversion', data: payload.series.cpl }],
                            stroke: { curve: 'straight', width: 2 },
                            markers: { size: 3 },
                            yaxis: { min: 0, forceNiceScale: true, labels: { style: { colors: theme.chartAxis() }, formatter: money } }
                        });
                    }

                    return Object.assign(base, {
                        chart: Object.assign({ type: 'line' }, base.chart),
                        colors: [palette[0], palette[1] || palette[0]],
                        series: [
                            { name: 'Spend', type: 'column', data: payload.series.spend },
                            { name: 'Google conversions', type: 'line', data: payload.series.conversions }
                        ],
                        stroke: { curve: 'straight', width: [0, 2] },
                        markers: { size: [0, 3] },
                        yaxis: [
                            { seriesName: 'Spend', min: 0, forceNiceScale: true, labels: { style: { colors: theme.chartAxis() }, formatter: money } },
                            { seriesName: 'Google conversions', opposite: true, min: 0, forceNiceScale: true, decimalsInFloat: 0, labels: { style: { colors: theme.chartAxis() } } }
                        ]
                    });
                }

                function draw() {
                    if (chart) { chart.destroy(); }
                    chart = new ApexCharts(el, options());
                    chart.render();
                }

                document.querySelectorAll('[data-chart-tab]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        tab = button.getAttribute('data-chart-tab');
                        document.querySelectorAll('[data-chart-tab]').forEach(function (other) {
                            var on = other === button;
                            other.classList.toggle('btn-primary', on);
                            other.classList.toggle('btn-outline-secondary', !on);
                            other.setAttribute('aria-pressed', on ? 'true' : 'false');
                        });
                        if (payload) { draw(); }
                    });
                });

                // The server-rendered "Show daily figures" table is the fallback
                // when this request or the chart library fails.
                fetch(el.getAttribute('data-series-url'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
                    .then(function (body) {
                        if (!body || !body.series || !body.labels) { return Promise.reject('shape'); }
                        payload = body;
                        draw();
                    })
                    .catch(function () {
                        el.innerHTML = '<p class="text-caption mb-0">The chart is unavailable right now. The daily figures below show the same data.</p>';
                        var details = document.querySelector('[data-role="trend-table"]');
                        if (details) { details.open = true; }
                    });
            })();
        </script>
    @endsection
@endif
