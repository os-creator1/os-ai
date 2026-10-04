{{--
    Google Ads Module V1 — one campaign: performance, its daily budget, ad
    groups, keywords, search terms and Google's conversion data.

    Cached data only; every provider string (campaign / ad group / keyword /
    search term) is escaped. No Google id appears in the markup. The trend is
    server data embedded in the page (no extra request); the "Show daily
    figures" table is the no-JavaScript fallback.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Ads campaign')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">
@endsection

@php
    use App\Enums\GoogleAds\GoogleAdsEntityStatus;
    use App\Library\GoogleAds\GoogleAdsDisplay as D;
    use App\Library\GoogleAds\GoogleAdsMoney;

    $campaign = $detail->campaign;
    $totals = $campaign->totals;
    $currency = $account?->currency_code;
    $prefix = 'customer.workspaces.businesses.ads.';
    $trend = $detail->trend;
    $budget = $detail->budget;
    $isPending = $pending[$campaign->uid] ?? false;
    $canChange = $adsCanManage && in_array($campaign->status, [GoogleAdsEntityStatus::Enabled, GoogleAdsEntityStatus::Paused], true);
    $pausing = $campaign->status === GoogleAdsEntityStatus::Enabled;
    $periodName = ['last_7' => '7 days', 'last_30' => '30 days', 'this_month' => 'This month', 'previous_month' => 'Previous month'][$period->key] ?? '';
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => $campaign->name, 'subtitle' => 'Campaign performance from your last Google Ads update.'])

    <x-flash-alert class="mb-2" />

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2">
        <a href="{{ route($prefix . 'campaigns.index', [$workspaceUid, $businessUid, 'period' => $period->key]) }}" data-role="back-to-campaigns">&larr; All campaigns</a>
        <div class="d-flex align-items-center gap-1">
            <x-badge :variant="D::statusVariant($campaign->status)" data-role="campaign-status">{{ D::statusLabel($campaign->status) }}</x-badge>
            @if($isPending)
                <x-badge variant="warning" data-role="pending-confirmation">Pending confirmation</x-badge>
            @endif
            @if($canChange)
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ads-confirm-{{ $campaign->uid }}" data-role="{{ $pausing ? 'pause-campaign' : 'resume-campaign' }}">{{ $pausing ? 'Pause' : 'Resume' }}</button>
            @endif
        </div>
    </div>

    @include('customer.business.ads._period-selector', ['periodRoute' => 'campaigns.show', 'routeExtra' => [$campaign->uid]])

    @if(! $totals->hasData())
        <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="no-period-data">
            No activity was recorded for this campaign in this period. Try a longer range, or check back after the next update.
        </x-alert>
    @endif

    <div class="row" data-role="kpi-cards">
        <div class="col-6 col-lg-3 mb-2">
            <x-card class="h-100" data-role="kpi-spend">
                <p class="text-label mb-1">Spend</p>
                <p class="h3 mb-0" data-role="kpi-spend-value">{{ D::money($campaign->spendMicros(), $currency) }}</p>
                <p class="text-caption text-muted mb-0">{{ $periodName }}</p>
            </x-card>
        </div>
        <div class="col-6 col-lg-3 mb-2">
            <x-card class="h-100" data-role="kpi-conversions">
                <p class="text-label mb-1">Google conversions</p>
                <p class="h3 mb-0" data-role="kpi-conversions-value">{{ D::count($totals->conversionsDisplay()) }}</p>
                <p class="text-caption text-muted mb-0">{{ number_format((int) $totals->clicks) }} clicks</p>
            </x-card>
        </div>
        <div class="col-6 col-lg-3 mb-2">
            <x-card class="h-100" data-role="kpi-cpl">
                <p class="text-label mb-1">Cost per conversion</p>
                <p class="h3 mb-0" data-role="kpi-cpl-value">{{ D::money($campaign->cplMicros(), $currency) }}</p>
                <p class="text-caption text-muted mb-0">A dash means no conversions yet.</p>
            </x-card>
        </div>
        <div class="col-6 col-lg-3 mb-2">
            <x-card class="h-100" data-role="kpi-conversion-rate">
                <p class="text-label mb-1">Conversion rate</p>
                <p class="h3 mb-0" data-role="kpi-conversion-rate-value">{{ D::percent($campaign->conversionRate()) }}</p>
                <p class="text-caption text-muted mb-0">Conversions per click.</p>
            </x-card>
        </div>
    </div>

    <x-card :padded="true" class="mb-2" data-role="trend-card">
        <h2 class="text-section-heading mb-1">Trend</h2>
        <div id="ads-campaign-chart" data-role="chart-trend" data-payload="{{ json_encode($trend) }}" role="img" aria-label="Chart of this campaign's daily spend and Google conversions for the selected period" style="min-height: 260px;"></div>
        <details class="mt-1" data-role="trend-table">
            <summary class="text-caption">Show daily figures</summary>
            <x-table :headers="['Day', 'Spend', 'Google conversions', 'Cost per conversion']" class="mt-1">
                @foreach($trend['labels'] as $i => $label)
                    <tr>
                        <td>{{ $trend['tooltips'][$i] ?? $label }}</td>
                        <td class="text-numeric">{{ D::money($trend['series']['spend_micros'][$i] ?? null, $currency) }}</td>
                        <td class="text-numeric">{{ $trend['series']['conversions'][$i] === null ? '—' : D::count((string) $trend['series']['conversions'][$i]) }}</td>
                        <td class="text-numeric">{{ $trend['series']['cpl'][$i] === null ? '—' : D::money((int) round($trend['series']['cpl'][$i] * GoogleAdsMoney::MICROS_PER_UNIT), $currency) }}</td>
                    </tr>
                @endforeach
            </x-table>
        </details>
    </x-card>

    <div class="row">
        <div class="col-lg-6 mb-2">
            <x-card :padded="true" class="h-100" data-role="budget-facts">
                <h2 class="text-section-heading mb-1">Daily budget</h2>
                <div class="row">
                    <div class="col-6 mb-1">
                        <p class="text-label mb-0">Daily budget</p>
                        <p class="h4 mb-0" data-role="budget-daily">{{ D::money($budget['daily_budget_micros'], $currency) }}</p>
                    </div>
                    <div class="col-6 mb-1">
                        <p class="text-label mb-0">Average daily spend</p>
                        <p class="h4 mb-0" data-role="budget-average">{{ D::money($budget['average_daily_spend_micros'], $currency) }}</p>
                    </div>
                </div>
                <p class="text-caption mb-1" data-role="budget-utilisation">
                    @if($budget['budget_utilisation'] !== null)
                        On days with spend, this campaign used about {{ number_format($budget['budget_utilisation'] * 100, 0) }}% of its daily budget on average.
                    @else
                        We cannot compare spend with a daily budget yet.
                    @endif
                    @if($budget['budget_shared'])
                        Its budget is shared with other campaigns.
                    @endif
                </p>
                <p class="text-caption text-muted mb-0">
                    The daily budget is set in Google Ads and is not changed here. It is separate from the monthly target on the
                    <a href="{{ route($prefix . 'budget', [$workspaceUid, $businessUid]) }}">Budget</a> page.
                </p>
            </x-card>
        </div>
        <div class="col-lg-6 mb-2">
            <x-card :padded="true" class="h-100" data-role="conversion-data">
                <h2 class="text-section-heading mb-1">Conversion data</h2>
                <div class="row">
                    <div class="col-6 mb-1">
                        <p class="text-label mb-0">Google conversions</p>
                        <p class="h4 mb-0" data-role="conversion-total">{{ D::count($totals->conversionsDisplay()) }}</p>
                    </div>
                    <div class="col-6 mb-1">
                        <p class="text-label mb-0">Conversion value (Google)</p>
                        <p class="h4 mb-0" data-role="conversion-value">{{ D::decimalMoney($totals->conversionValue(), $currency) }}</p>
                    </div>
                </div>
                <p class="text-caption text-muted mb-0">
                    Conversions are the actions you count in Google Ads (for example calls or form submissions). The value is what Google Ads assigns to them; it is not sales recorded here.
                    See <a href="{{ route($prefix . 'leads.index', [$workspaceUid, $businessUid, 'period' => $period->key]) }}">Leads &amp; conversions</a> for the leads recorded in this platform.
                </p>
            </x-card>
        </div>
    </div>

    <x-card :padded="true" class="mb-2" data-role="ad-groups">
        <h2 class="text-section-heading mb-1">Ad groups</h2>
        @if(count($detail->adGroups) === 0)
            <p class="text-caption mb-0" data-role="no-ad-groups">No ad groups to show yet.</p>
        @else
            <x-table :headers="['Ad group', 'Status', 'Spend', 'Clicks', 'Conversions', 'Cost per conversion']">
                @foreach($detail->adGroups as $group)
                    <tr data-role="ad-group-row">
                        <td>{{ $group['name'] }}</td>
                        <td><x-badge :variant="D::statusVariant($group['status'])">{{ D::statusLabel($group['status']) }}</x-badge></td>
                        <td class="text-numeric">{{ D::money($group['totals']->spendMicros, $currency) }}</td>
                        <td class="text-numeric">{{ D::integer($group['totals']->clicks) }}</td>
                        <td class="text-numeric">{{ D::count($group['totals']->conversionsDisplay()) }}</td>
                        <td class="text-numeric">{{ D::money($group['totals']->cplMicros(), $currency) }}</td>
                    </tr>
                @endforeach
            </x-table>
            <p class="text-caption text-muted mb-0 mt-1">Ad group figures add up their keywords, so they can be lower than the campaign total.</p>
        @endif
    </x-card>

    <x-card :padded="true" class="mb-2" data-role="campaign-keywords">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
            <h2 class="text-section-heading mb-0">Keywords</h2>
            <a href="{{ route($prefix . 'keywords.index', [$workspaceUid, $businessUid, 'campaign' => $campaign->uid, 'period' => $period->key]) }}" data-role="all-keywords">All keywords</a>
        </div>
        @if(count($detail->keywords->items) === 0)
            <p class="text-caption mb-0" data-role="no-keywords">No keywords to show yet.</p>
        @else
            <x-table :headers="['Keyword', 'Match type', 'Ad group', 'Status', 'Spend', 'Clicks', 'Conversions', 'Cost per conversion']">
                @foreach($detail->keywords->items as $keyword)
                    <tr data-role="keyword-row">
                        <td>{{ $keyword->text }}</td>
                        <td>{{ D::matchLabel($keyword->matchType) }}</td>
                        <td>{{ $keyword->adGroupName ?? '—' }}</td>
                        <td><x-badge :variant="D::statusVariant($keyword->status)">{{ D::statusLabel($keyword->status) }}</x-badge></td>
                        <td class="text-numeric">{{ D::money($keyword->totals->spendMicros, $currency) }}</td>
                        <td class="text-numeric">{{ D::integer($keyword->totals->clicks) }}</td>
                        <td class="text-numeric">{{ D::count($keyword->totals->conversionsDisplay()) }}</td>
                        <td class="text-numeric">{{ D::money($keyword->cplMicros(), $currency) }}</td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>

    <x-card :padded="true" class="mb-2" data-role="campaign-search-terms">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
            <h2 class="text-section-heading mb-0">Search terms</h2>
            <a href="{{ route($prefix . 'search-terms.index', [$workspaceUid, $businessUid, 'campaign' => $campaign->uid, 'period' => $period->key]) }}" data-role="all-search-terms">All search terms</a>
        </div>
        @if(count($detail->searchTerms->items) === 0)
            <p class="text-caption mb-0" data-role="no-search-terms">No search-term data yet.</p>
        @else
            <x-table :headers="['Search term', 'Spend', 'Clicks', 'Conversions', 'Status']">
                @foreach($detail->searchTerms->items as $term)
                    <tr data-role="search-term-row">
                        <td>{{ $term->term }}</td>
                        <td class="text-numeric">{{ D::money($term->totals->spendMicros, $currency) }}</td>
                        <td class="text-numeric">{{ D::integer($term->totals->clicks) }}</td>
                        <td class="text-numeric">{{ D::count($term->totals->conversionsDisplay()) }}</td>
                        <td><x-badge :variant="D::termClassVariant($term->classification)">{{ D::termClassLabel($term->classification) }}</x-badge></td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>

    @if($canChange)
        @include('customer.business.ads._confirm-dialog', [
            'dialogId' => 'ads-confirm-' . $campaign->uid,
            'action' => route($prefix . ($pausing ? 'campaigns.pause' : 'campaigns.resume'), [$workspaceUid, $businessUid, $campaign->uid]),
            'title' => $pausing ? 'Pause campaign' : 'Resume campaign',
            'question' => ($pausing ? 'Pause campaign ' : 'Resume campaign ') . $campaign->name . '?',
            'consequence' => $pausing
                ? 'It will stop showing ads in Google Ads until resumed.'
                : 'It will start showing ads again in Google Ads.',
            'confirmLabel' => $pausing ? 'Pause campaign' : 'Resume campaign',
            'from' => 'campaign',
            'returnQuery' => $returnQuery,
            'returnCampaign' => $campaign->uid,
        ])
    @endif
@endsection

@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/charts/apexcharts.min.js')) }}"></script>
@endsection

@section('page-script')
    @if($canChange)
        @include('customer.business.ads._once-script')
    @endif
    <script>
        (function () {
            var el = document.getElementById('ads-campaign-chart');
            if (!el || !window.ApexCharts || !window.PlatformTheme) { return; }

            var payload;
            try { payload = JSON.parse(el.getAttribute('data-payload')); } catch (e) { return; }
            if (!payload || !payload.series || !payload.labels) { return; }

            // Colours and grid come only from the shared token namespace.
            var theme = window.PlatformTheme;
            var palette = theme.chartPalette();

            function money(value) {
                if (value === null || value === undefined) { return '—'; }
                try {
                    return new Intl.NumberFormat(undefined, { style: 'currency', currency: payload.currency }).format(value);
                } catch (e) {
                    return String(value);
                }
            }

            var width = el.clientWidth || 600;
            var fitting = Math.max(2, Math.floor(width / 110));

            new ApexCharts(el, {
                chart: { type: 'line', height: 260, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
                grid: { borderColor: theme.chartGrid() },
                colors: [palette[0], palette[1] || palette[0]],
                series: [
                    { name: 'Spend', type: 'column', data: payload.series.spend },
                    { name: 'Google conversions', type: 'line', data: payload.series.conversions }
                ],
                stroke: { curve: 'straight', width: [0, 2] },
                markers: { size: [0, 3] },
                xaxis: {
                    type: 'category', categories: payload.labels,
                    tickAmount: payload.labels.length > fitting ? fitting : undefined,
                    labels: { style: { colors: theme.chartAxis() }, rotate: 0, rotateAlways: false, hideOverlappingLabels: true, trim: false },
                    tooltip: { enabled: false }
                },
                yaxis: [
                    { seriesName: 'Spend', min: 0, forceNiceScale: true, labels: { style: { colors: theme.chartAxis() }, formatter: money } },
                    { seriesName: 'Google conversions', opposite: true, min: 0, forceNiceScale: true, decimalsInFloat: 0, labels: { style: { colors: theme.chartAxis() } } }
                ],
                tooltip: { theme: 'dark', x: { formatter: function (v, o) { return payload.tooltips[o.dataPointIndex] || v; } } },
                dataLabels: { enabled: false },
                legend: { labels: { colors: theme.chartAxis() } },
                noData: { text: 'No data for this period yet' }
            }).render();
        })();
    </script>
@endsection
