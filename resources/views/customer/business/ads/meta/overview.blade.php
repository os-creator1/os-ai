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
    use App\Library\GoogleAds\Reporting\GoogleAdsPacingStatus;
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
    $change = static function (?float $change): ?string {
        if ($change === null) {
            return null;
        }

        return ($change >= 0 ? '+' : '') . number_format($change * 100, 0) . '% vs previous period';
    };
    $differsFromBusiness = $account !== null && $business->currency_code && strtoupper((string) $business->currency_code) !== strtoupper((string) $currency);
    $resultLabel = $ready ? ($overview->resultTypeLabel ?? null) : null;
    $pacingText = static fn (GoogleAdsPacingStatus $status): string => match ($status) {
        GoogleAdsPacingStatus::OnPace => 'On pace with your monthly budget',
        GoogleAdsPacingStatus::Ahead => 'Spending is ahead of your monthly budget',
        GoogleAdsPacingStatus::Behind => 'Spending is behind your monthly budget',
        GoogleAdsPacingStatus::NoTarget => 'Set a monthly budget target in Settings to see pacing',
        GoogleAdsPacingStatus::InsufficientData => 'A few more days of data are needed to judge pacing',
    };
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
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2" data-role="period-selector">
            <div class="btn-group" role="group" aria-label="Period">
                @foreach($periodLabels as $key => $label)
                    <a href="{{ route('customer.workspaces.businesses.ads.meta.index', [$workspaceUid, $businessUid, 'period' => $key]) }}"
                       class="btn btn-sm {{ $period->key === $key ? 'btn-primary' : 'btn-outline-secondary' }}"
                       @if($period->key === $key) aria-current="true" @endif
                       data-period="{{ $key }}">{{ $label }}</a>
                @endforeach
            </div>
            <span class="text-caption text-muted" data-role="period-range">{{ $period->from->format('M j') }} &ndash; {{ $period->to->format('M j, Y') }}</span>
        </div>

        <p class="text-caption text-muted mb-2" data-role="account-line">
            Ad account: <strong data-role="account-name">{{ $account->name ?? 'Unnamed account' }}</strong>
            <x-badge variant="neutral" data-role="account-currency">{{ $currency }}</x-badge>
            @if($differsFromBusiness)
                <span data-role="currency-note">Amounts are in the ad account currency ({{ $currency }}), which differs from your business currency ({{ strtoupper((string) $business->currency_code) }}).</span>
            @endif
        </p>

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

            <div class="row" data-role="kpi-cards">
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-spend" class="h-100">
                        <p class="text-label mb-1">Spend</p>
                        <p class="h2 mb-0" data-role="kpi-spend-value">{{ $money($overview->spendMicros) }}</p>
                        <p class="text-caption text-muted mb-0">{{ $periodLabels[$period->key] }}@if($change($overview->comparison['spend_change'] ?? null)) &middot; {{ $change($overview->comparison['spend_change']) }}@endif</p>
                    </x-card>
                </div>
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-results" class="h-100">
                        <p class="text-label mb-1">
                            Results @if($resultLabel)<span class="text-muted" data-role="result-type-label">({{ $resultLabel }})</span>@endif
                            <x-tooltip text="Results are the one kind of action you chose in Settings, counted by Meta. This is Meta's count, not the leads recorded in this platform.">
                                <x-ds-icon name="info" size="14" aria-label="About Meta results" />
                            </x-tooltip>
                        </p>
                        @if($overview->resultTypeUnset)
                            <p class="h2 mb-0" data-role="kpi-results-value">&mdash;</p>
                            <p class="text-caption mb-0"><a href="{{ route('customer.workspaces.businesses.ads.meta.settings', [$workspaceUid, $businessUid]) }}">Choose a result type</a></p>
                        @else
                            <p class="h2 mb-0" data-role="kpi-results-value">{{ D::count($overview->results) }}</p>
                            <p class="text-caption text-muted mb-0">{{ $periodLabels[$period->key] }}@if($change($overview->comparison['results_change'] ?? null)) &middot; {{ $change($overview->comparison['results_change']) }}@endif</p>
                        @endif
                    </x-card>
                </div>
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-cpr" class="h-100">
                        <p class="text-label mb-1">Cost per result</p>
                        <p class="h2 mb-0" data-role="kpi-cpr-value">{{ $money($overview->costPerResultMicros) }}</p>
                        <p class="text-caption text-muted mb-0">
                            @if($overview->resultTypeUnset)
                                Shown once you choose a result type.
                            @elseif($overview->targetCostPerResultMicros !== null)
                                Target {{ $money($overview->targetCostPerResultMicros) }}
                            @else
                                Spend divided by results.
                            @endif
                        </p>
                    </x-card>
                </div>
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-impressions" class="h-100">
                        <p class="text-label mb-1">Impressions</p>
                        <p class="h2 mb-0" data-role="kpi-impressions-value">{{ D::integer($overview->impressions) }}</p>
                        <p class="text-caption text-muted mb-0">{{ $periodLabels[$period->key] }}</p>
                    </x-card>
                </div>
                <div class="col-6 col-lg-4 mb-2">
                    <x-card data-role="kpi-link-clicks" class="h-100">
                        <p class="text-label mb-1">Link clicks</p>
                        <p class="h2 mb-0" data-role="kpi-link-clicks-value">{{ D::integer($overview->linkClicks) }}</p>
                        <p class="text-caption text-muted mb-0">{{ $periodLabels[$period->key] }}</p>
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
                        <p class="text-caption mb-0 mt-50" data-role="pacing-status">
                            {{ $pacingText($overview->pacing->status) }}@if($overview->pacing->monthlyTargetMicros !== null) (target {{ $money($overview->pacing->monthlyTargetMicros) }})@endif.
                        </p>
                    </x-card>
                </div>
            </div>

            @if($overview->resultValue !== null)
                <p class="text-caption text-muted mb-2" data-role="meta-value-note">Meta-reported value of these results: {{ D::decimalMoney($overview->resultValue, $currency) }}. This is Meta's figure, not sales recorded here.</p>
            @endif

            @if($overview->hasData && ! ($overview->coverage['covered'] ?? true))
                <p class="text-caption text-muted mb-2" data-role="coverage-note">Part of this period is older than the history we keep, so the figures above may be incomplete.</p>
            @endif

            <x-card :padded="true" class="mb-2" data-role="trend-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
                    <h2 class="text-section-heading mb-0">Trend</h2>
                    <div class="btn-group" role="group" aria-label="Chart metric" data-role="chart-tabs">
                        <button type="button" class="btn btn-sm btn-primary" data-chart-tab="spend" aria-pressed="true">Spend and results</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-chart-tab="cpr" aria-pressed="false">Cost per result</button>
                    </div>
                </div>
                <div id="ads-trend-chart" data-role="chart-trend" data-series-url="{{ $seriesUrl }}" role="img" aria-label="Chart of daily spend, Meta results and cost per result for the selected period" style="min-height: 280px;"></div>
                <details class="mt-1" data-role="trend-table">
                    <summary class="text-caption">Show daily figures</summary>
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
            </x-card>

            <x-card :padded="true" class="mb-2" data-role="attention">
                <h2 class="text-section-heading mb-1">What needs attention</h2>
                @if(count($attention['items']) === 0)
                    <p class="text-caption mb-0" data-role="attention-none">Nothing stands out right now.</p>
                @else
                    <ul class="list-unstyled mb-0">
                        @foreach($attention['items'] as $item)
                            <li class="mb-1" data-role="attention-item" data-tone="{{ $item['tone'] }}">
                                <strong>{{ $item['title'] }}</strong>
                                @foreach($item['evidence_lines'] as $line)
                                    <span class="d-block text-caption">{{ $line }}</span>
                                @endforeach
                                @if($item['url'])
                                    <a href="{{ $item['url'] }}" class="text-caption">{{ $item['action_label'] }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if($attention['count'] > count($attention['items']))
                        <p class="text-caption text-muted mb-0 mt-1">Showing the {{ count($attention['items']) }} biggest of {{ $attention['count'] }}.</p>
                    @endif
                @endif
            </x-card>
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

                    if (tab === 'cpr') {
                        return Object.assign(base, {
                            chart: Object.assign({ type: 'line' }, base.chart),
                            colors: [palette[2] || palette[0]],
                            series: [{ name: 'Cost per result', data: payload.series.cost_per_result }],
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
                            { name: 'Results', type: 'line', data: payload.series.results }
                        ],
                        stroke: { curve: 'straight', width: [0, 2] },
                        markers: { size: [0, 3] },
                        yaxis: [
                            { seriesName: 'Spend', min: 0, forceNiceScale: true, labels: { style: { colors: theme.chartAxis() }, formatter: money } },
                            resultsAxis()
                        ]
                    });
                }

                // Whole-number results: with a tiny maximum the nice scale would
                // repeat labels ("2 2 1 1 0 0"), so small counts get one tick per whole result.
                function resultsAxis() {
                    var top = Math.max.apply(null, (payload.series.results || []).map(function (v) { return Number(v) || 0; }).concat([0]));
                    var axis = { seriesName: 'Results', opposite: true, min: 0, forceNiceScale: true, decimalsInFloat: 0, labels: { style: { colors: theme.chartAxis() } } };
                    if (top <= 5) { axis.max = Math.max(1, Math.ceil(top)); axis.tickAmount = axis.max; axis.forceNiceScale = false; }
                    return axis;
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
