{{--
    Meta Ads Module V1 — one campaign: performance, its own budget, ad sets
    and ads with creative previews.

    Cached data only; every provider string (campaign / ad set / ad names,
    creative title / body) is escaped. No Meta id appears in the markup. The
    trend is server data embedded in the page (no extra request); the "Show
    daily figures" table is the no-JavaScript fallback.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Meta campaign')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">
@endsection

@php
    use App\Library\MetaAds\MetaAdsDisplay as D;
    use App\Library\MetaAds\MetaAdsMoney;

    $campaign = $detail->campaign;
    $totals = $campaign->totals;
    $currency = $account?->currency_code;
    $prefix = 'customer.workspaces.businesses.ads.meta.';
    $trend = $detail->trend;
    $budget = $detail->budget;
    $isPending = $pending[$campaign->uid] ?? false;
    $action = D::actionFor($campaign->status, $campaign->effectiveStatus);
    $canChange = $metaCanManage && $action !== null;
    $pausing = $action === 'pause';
    $periodName = ['last_7' => '7 days', 'last_30' => '30 days', 'this_month' => 'This month', 'previous_month' => 'Previous month'][$period->key] ?? '';
    $metaValue = $totals->resultValue();
    $issue = D::issueText($campaign->effectiveStatus);
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => $campaign->name, 'subtitle' => 'Campaign performance from your last Meta Ads update.', 'provider' => 'meta'])

    <x-flash-alert class="mb-2" />

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2">
        <a href="{{ route($prefix . 'campaigns.index', [$workspaceUid, $businessUid, 'period' => $period->key]) }}" data-role="back-to-campaigns">&larr; All campaigns</a>
        <div class="d-flex align-items-center flex-wrap gap-1">
            <x-badge :variant="D::statusVariant($campaign->status, $campaign->effectiveStatus)" data-role="campaign-status">{{ D::statusLabel($campaign->status, $campaign->effectiveStatus) }}</x-badge>
            @if($isPending)
                <x-badge variant="warning" data-role="pending-confirmation">Pending confirmation</x-badge>
            @endif
            @if($canChange)
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ads-confirm-{{ $campaign->uid }}" data-role="{{ $action }}-campaign">{{ ucfirst($action) }}</button>
            @endif
        </div>
    </div>
    @if($issue !== null)
        <p class="text-caption text-muted mb-2" data-role="meta-issue">{{ $issue }}</p>
    @endif

    @include('customer.business.ads.meta._data-period', ['periodRoute' => 'campaigns.show', 'routeExtra' => [$campaign->uid]])

    @if(! $totals->hasData())
        <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="no-period-data">
            No activity was recorded for this campaign in this period. Try a longer range, or check back after the next update.
        </x-alert>
    @endif

    @include('customer.business.ads.meta._data-result-note')

    <div class="row" data-role="kpi-cards">
        <div class="col-6 col-lg-3 mb-2">
            <x-card class="h-100" data-role="kpi-spend">
                <p class="text-label mb-1">Spend</p>
                <p class="h3 mb-0" data-role="kpi-spend-value">{{ D::money($campaign->spendMicros(), $currency) }}</p>
                <p class="text-caption text-muted mb-0">{{ $periodName }}</p>
            </x-card>
        </div>
        <div class="col-6 col-lg-3 mb-2">
            <x-card class="h-100" data-role="kpi-results">
                <p class="text-label mb-1">Results</p>
                <p class="h3 mb-0" data-role="kpi-results-value">{{ D::count($totals->resultsDisplay()) }}</p>
                <p class="text-caption text-muted mb-0">{{ $resultLabel ?? 'No result type chosen' }}</p>
            </x-card>
        </div>
        <div class="col-6 col-lg-3 mb-2">
            <x-card class="h-100" data-role="kpi-cpr">
                <p class="text-label mb-1">Cost per result</p>
                <p class="h3 mb-0" data-role="kpi-cpr-value">{{ D::money($campaign->costPerResultMicros(), $currency) }}</p>
                <p class="text-caption text-muted mb-0">A dash means no results yet.</p>
            </x-card>
        </div>
        <div class="col-6 col-lg-3 mb-2">
            <x-card class="h-100" data-role="kpi-link-clicks">
                <p class="text-label mb-1">Link clicks</p>
                <p class="h3 mb-0" data-role="kpi-link-clicks-value">{{ D::integer($totals->linkClicks) }}</p>
                <p class="text-caption text-muted mb-0">{{ D::integer($totals->impressions) }} impressions</p>
            </x-card>
        </div>
    </div>

    <x-card :padded="true" class="mb-2" data-role="trend-card">
        <h2 class="text-section-heading mb-1">Trend</h2>
        <div id="ads-campaign-chart" data-role="chart-trend" data-payload="{{ json_encode($trend) }}" role="img" aria-label="Chart of this campaign's daily spend and results for the selected period" style="min-height: 260px;"></div>
        <details class="mt-1" data-role="trend-table">
            <summary class="text-caption">Show daily figures</summary>
            <x-table :headers="['Day', 'Spend', 'Results', 'Cost per result']" class="mt-1">
                @foreach($trend['labels'] as $i => $label)
                    <tr>
                        <td>{{ $trend['tooltips'][$i] ?? $label }}</td>
                        <td class="text-numeric">{{ D::money($trend['series']['spend_micros'][$i] ?? null, $currency) }}</td>
                        <td class="text-numeric">{{ ($trend['series']['results'][$i] ?? null) === null ? '—' : D::count((string) $trend['series']['results'][$i]) }}</td>
                        <td class="text-numeric">{{ ($trend['series']['cost_per_result'][$i] ?? null) === null ? '—' : D::money((int) round($trend['series']['cost_per_result'][$i] * MetaAdsMoney::MICROS_PER_UNIT), $currency) }}</td>
                    </tr>
                @endforeach
            </x-table>
        </details>
    </x-card>

    <div class="row">
        <div class="col-lg-6 mb-2">
            <x-card :padded="true" class="h-100" data-role="budget-facts">
                <h2 class="text-section-heading mb-1">Campaign budget</h2>
                <div class="row">
                    <div class="col-6 mb-1">
                        <p class="text-label mb-0">{{ $budget['budget_type'] === 'lifetime' ? 'Lifetime budget' : 'Daily budget' }}</p>
                        <p class="h4 mb-0" data-role="budget-amount">
                            @if($budget['budget_type'] === 'lifetime')
                                {{ D::minorMoney($budget['lifetime_budget_minor'], $currency) }}
                            @else
                                {{ D::minorMoney($budget['daily_budget_minor'], $currency) }}
                            @endif
                        </p>
                    </div>
                    <div class="col-6 mb-1">
                        @if($budget['budget_type'] === 'lifetime')
                            <p class="text-label mb-0">Budget remaining</p>
                            <p class="h4 mb-0" data-role="budget-remaining">{{ D::minorMoney($budget['budget_remaining_minor'], $currency) }}</p>
                        @else
                            <p class="text-label mb-0">Average daily spend</p>
                            <p class="h4 mb-0" data-role="budget-average">{{ D::money($budget['average_daily_spend_micros'], $currency) }}</p>
                        @endif
                    </div>
                </div>
                @if($budget['budget_type'] === 'daily')
                    <p class="text-caption mb-1" data-role="budget-utilisation">
                        @if($budget['budget_utilisation'] !== null)
                            On days with spend, this campaign used about {{ number_format($budget['budget_utilisation'] * 100, 0) }}% of its daily budget on average.
                        @else
                            We cannot compare spend with a daily budget yet.
                        @endif
                    </p>
                @elseif($budget['budget_type'] === null)
                    <p class="text-caption mb-1" data-role="budget-none">No campaign-level budget is set. Budgets may be set on its ad sets.</p>
                @endif
                <p class="text-caption text-muted mb-0" data-role="budget-separate-note">
                    This budget is set in Meta Ads and is not changed here. It is separate from the monthly target for your business, which you set in
                    <a href="{{ route($prefix . 'settings', [$workspaceUid, $businessUid]) }}">Settings</a>.
                </p>
            </x-card>
        </div>
        <div class="col-lg-6 mb-2">
            <x-card :padded="true" class="h-100" data-role="result-data">
                <h2 class="text-section-heading mb-1">Results</h2>
                <div class="row">
                    <div class="col-6 mb-1">
                        <p class="text-label mb-0">Results</p>
                        <p class="h4 mb-0" data-role="result-total">{{ D::count($totals->resultsDisplay()) }}</p>
                    </div>
                    @if($metaValue !== null)
                        <div class="col-6 mb-1">
                            <p class="text-label mb-0">Meta-reported value</p>
                            <p class="h4 mb-0" data-role="meta-reported-value">{{ D::decimalMoney($metaValue, $currency) }}</p>
                        </div>
                    @endif
                </div>
                <p class="text-caption text-muted mb-0">
                    Results are the single result type you chose{{ $resultLabel !== null ? ' (' . $resultLabel . ')' : '' }}, as counted by Meta.
                    @if($metaValue !== null)
                        The value is what Meta assigns to those results; it is not sales recorded in this platform.
                    @endif
                </p>
            </x-card>
        </div>
    </div>

    <x-card :padded="true" class="mb-2" data-role="campaign-ad-sets">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
            <h2 class="text-section-heading mb-0">Ad sets</h2>
            <a href="{{ route($prefix . 'ad-sets.index', [$workspaceUid, $businessUid, 'campaign' => $campaign->uid, 'period' => $period->key]) }}" data-role="all-ad-sets">All ad sets</a>
        </div>
        @if(count($detail->adSets) === 0)
            <p class="text-caption mb-0" data-role="no-ad-sets">No ad sets to show yet.</p>
        @else
            <div class="table-responsive">
                <x-table :headers="['Ad set', 'Status', 'Audience summary', 'Spend', 'Results', 'Cost per result', 'Frequency (last 7 days)']">
                    @foreach($detail->adSets as $row)
                        <tr data-role="ad-set-row">
                            <td>{{ $row->name }}</td>
                            <td>
                                <x-badge :variant="D::statusVariant($row->status, $row->effectiveStatus)">{{ D::statusLabel($row->status, $row->effectiveStatus) }}</x-badge>
                                @if(D::issueText($row->effectiveStatus))<div class="text-caption text-muted" data-role="meta-issue">{{ D::issueText($row->effectiveStatus) }}</div>@endif
                            </td>
                            <td class="text-caption">{{ $row->targetingSummary ?? D::DASH }}</td>
                            <td class="text-numeric">{{ D::money($row->spendMicros(), $currency) }}</td>
                            <td class="text-numeric">{{ D::count($row->totals->resultsDisplay()) }}</td>
                            <td class="text-numeric">{{ D::money($row->costPerResultMicros(), $currency) }}</td>
                            <td class="text-numeric">{{ D::frequency($row->frequency7d) }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </div>
            @if($detail->adSetsTotal > count($detail->adSets))
                <p class="text-caption text-muted mb-0 mt-1">Showing {{ count($detail->adSets) }} of {{ number_format($detail->adSetsTotal) }} ad sets.</p>
            @endif
        @endif
    </x-card>

    <x-card :padded="true" class="mb-2" data-role="campaign-ads">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
            <h2 class="text-section-heading mb-0">Ads</h2>
            <a href="{{ route($prefix . 'ads.index', [$workspaceUid, $businessUid, 'campaign' => $campaign->uid, 'period' => $period->key]) }}" data-role="all-ads">All ads</a>
        </div>
        @if(count($detail->ads) === 0)
            <p class="text-caption mb-0" data-role="no-ads">No ads to show yet.</p>
        @else
            <div class="table-responsive">
                <x-table :headers="['Ad', 'Ad set', 'Status', 'Spend', 'Link clicks', 'Results', 'Cost per result']">
                    @foreach($detail->ads as $row)
                        <tr data-role="ad-row">
                            <td style="min-width: 14rem;">@include('customer.business.ads.meta._data-creative', ['creative' => $row->creative, 'name' => $row->name])</td>
                            <td>{{ $row->adSetName }}</td>
                            <td>
                                <x-badge :variant="D::statusVariant($row->status, $row->effectiveStatus)">{{ D::statusLabel($row->status, $row->effectiveStatus) }}</x-badge>
                                @if(D::issueText($row->effectiveStatus))<div class="text-caption text-muted" data-role="meta-issue">{{ D::issueText($row->effectiveStatus) }}</div>@endif
                            </td>
                            <td class="text-numeric">{{ D::money($row->spendMicros(), $currency) }}</td>
                            <td class="text-numeric">{{ D::integer($row->totals->linkClicks) }}</td>
                            <td class="text-numeric">{{ D::count($row->totals->resultsDisplay()) }}</td>
                            <td class="text-numeric">{{ D::money($row->costPerResultMicros(), $currency) }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </div>
            @if($detail->adsTotal > count($detail->ads))
                <p class="text-caption text-muted mb-0 mt-1">Showing {{ count($detail->ads) }} of {{ number_format($detail->adsTotal) }} ads.</p>
            @endif
        @endif
    </x-card>

    @if($canChange)
        @include('customer.business.ads.meta._data-actions', ['rows' => [$campaign], 'kind' => 'campaign', 'from' => 'campaign', 'returnQuery' => $returnQuery, 'returnCampaign' => $campaign->uid])
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

            // Whole-number results: with a tiny maximum the nice scale would
            // repeat labels ("2 2 1 1 0 0"), so small counts get one tick per whole result.
            function resultsAxis() {
                var top = Math.max.apply(null, (payload.series.results || []).map(function (v) { return Number(v) || 0; }).concat([0]));
                var axis = { seriesName: 'Results', opposite: true, min: 0, forceNiceScale: true, decimalsInFloat: 0, labels: { style: { colors: theme.chartAxis() } } };
                if (top <= 5) { axis.max = Math.max(1, Math.ceil(top)); axis.tickAmount = axis.max; axis.forceNiceScale = false; }
                return axis;
            }

            new ApexCharts(el, {
                chart: { type: 'line', height: 260, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
                grid: { borderColor: theme.chartGrid() },
                colors: [palette[0], palette[1] || palette[0]],
                series: [
                    { name: 'Spend', type: 'column', data: payload.series.spend },
                    { name: 'Results', type: 'line', data: payload.series.results }
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
                    resultsAxis()
                ],
                tooltip: { theme: 'dark', x: { formatter: function (v, o) { return payload.tooltips[o.dataPointIndex] || v; } } },
                dataLabels: { enabled: false },
                legend: { labels: { colors: theme.chartAxis() } },
                noData: { text: 'No data for this period yet' }
            }).render();
        })();
    </script>
@endsection
