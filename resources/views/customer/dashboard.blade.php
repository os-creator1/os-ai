{{--
    Customer Experience Slice 4 — the customer home (user.home).

    Renders one App\Library\Dashboard\DashboardSnapshot, chosen by the
    resolved CustomerContext frame: Business Home, Agency Account Home, the
    Account-frame chooser, or the zero-Business state. Everything here was
    assembled by the presenters before this view ran — no query, no Auth
    lookup, no model call happens in these templates. No page stylesheet and
    no chart: detailed analysis lives in Results.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Dashboard'))

@section('content')
    {{-- The shared title bar (panels.breadcrumb) is switched off on this page
         so its <h2> never precedes the page's own <h1>; the View-as-client
         banner it normally carries is therefore rendered here, first, in
         every branch (§3, §15). The component renders nothing unless a
         view-as session is active. --}}
    <x-view-as-banner />

    <div class="customer-dashboard" data-role="dashboard" data-kind="{{ $dashboard->kind }}">
        <div class="mb-2" data-role="dashboard-header">
            <h1 class="text-page-title mb-0" id="dashboard-title">
                <span class="d-block text-caption text-uppercase text-muted" data-role="dashboard-frame">{{ $dashboard->frameLabel }}</span>
                <span data-role="dashboard-heading">{{ $dashboard->heading }}</span>
            </h1>
        </div>

        @switch($dashboard->kind)
            @case(\App\Library\Dashboard\DashboardSnapshot::KIND_BUSINESS)
                @include('customer.dashboard.business-home', ['dashboard' => $dashboard])
                @break
            @case(\App\Library\Dashboard\DashboardSnapshot::KIND_AGENCY)
                @include('customer.dashboard.agency-home', ['dashboard' => $dashboard])
                @break
            @case(\App\Library\Dashboard\DashboardSnapshot::KIND_CHOOSER)
                @include('customer.dashboard.chooser', ['dashboard' => $dashboard])
                @break
            @default
                @include('layouts.partials.empty-state', $dashboard->emptyState)
        @endswitch
    </div>
@endsection

@php $homeChart = $dashboard->kind === \App\Library\Dashboard\DashboardSnapshot::KIND_BUSINESS
    && $dashboard->has(\App\Library\Dashboard\DashboardSnapshot::BAND_HEADLINES)
    && ($dashboard->band(\App\Library\Dashboard\DashboardSnapshot::BAND_HEADLINES)['seriesUrl'] ?? null) !== null; @endphp

@if($homeChart)
    @section('vendor-style')
        <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">
    @endsection

    @section('vendor-script')
        <script src="{{ asset(mix('vendors/js/charts/apexcharts.min.js')) }}"></script>
    @endsection

    @section('page-script')
        <script>
            (function () {
                // Unified Business Home §2.5 (H-3) — the new-contacts chart.
                // The page ships without a series: the browser asks B5's own
                // series endpoint for the SAME range the tiles above show,
                // and charts only a genuine payload. Colours and grid come
                // from the shared token namespace, exactly as Results does.
                var mount = document.querySelector('[data-role="chart-new-contacts"]');

                if (!mount || typeof ApexCharts === 'undefined' || !window.PlatformTheme) { return; }

                var theme = window.PlatformTheme;
                var palette = theme.chartPalette();
                var LABEL_SPACING_PX = 110;

                function unavailable() {
                    mount.innerHTML = '<p class="text-caption mb-0">Chart data is unavailable right now.</p>';
                }

                fetch(mount.dataset.seriesUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
                    .then(function (payload) {
                        var chart = payload && payload.charts ? payload.charts.new_contacts : null;

                        if (!chart || !chart.series || !chart.series.new_contacts) { return Promise.reject('shape'); }

                        var fitting = Math.max(2, Math.floor(mount.clientWidth / LABEL_SPACING_PX));
                        mount.innerHTML = '';

                        new ApexCharts(mount, {
                            chart: { type: 'area', height: 220, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
                            colors: [palette[0]],
                            series: [{ name: 'New contacts', data: chart.series.new_contacts }],
                            grid: { borderColor: theme.chartGrid() },
                            xaxis: {
                                type: 'category',
                                categories: chart.labels,
                                tickAmount: chart.labels.length > fitting ? fitting : undefined,
                                labels: { style: { colors: theme.chartAxis() }, rotate: 0, rotateAlways: false, hideOverlappingLabels: true, trim: false },
                                tooltip: { enabled: false }
                            },
                            yaxis: { labels: { style: { colors: theme.chartAxis() } }, min: 0, forceNiceScale: true },
                            tooltip: {
                                theme: 'dark',
                                style: { fontSize: '12px' },
                                x: { formatter: function (value, opts) { return chart.tooltips[opts.dataPointIndex] || value; } }
                            },
                            dataLabels: { enabled: false },
                            stroke: { curve: 'straight', width: 2 },
                            fill: { opacity: 0.2 },
                            legend: { show: false }
                        }).render();
                    })
                    .catch(unavailable);
            })();
        </script>
    @endsection
@endif
