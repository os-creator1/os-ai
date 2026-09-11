{{--
    Customer Experience Slice 4 §4 — the Business Home's five bands, in the
    parent's order: attention, recommended next steps, headline figures,
    spend (payer only), quick actions. A band with nothing to say is absent;
    a band whose source failed says so in one line.
--}}
@php use App\Library\Dashboard\DashboardSnapshot; @endphp

@if($dashboard->failed(DashboardSnapshot::BAND_ATTENTION))
    @include('customer.dashboard.band-failed', ['band' => 'attention', 'title' => 'Needs attention'])
@elseif($dashboard->has(DashboardSnapshot::BAND_ATTENTION))
    @include('customer.dashboard.bands.attention', ['items' => $dashboard->band(DashboardSnapshot::BAND_ATTENTION)])
@endif

@if($dashboard->failed(DashboardSnapshot::BAND_RECOMMENDATIONS))
    @include('customer.dashboard.band-failed', ['band' => 'recommendations', 'title' => 'Recommended next steps'])
@elseif($dashboard->has(DashboardSnapshot::BAND_RECOMMENDATIONS))
    @include('customer.dashboard.bands.recommendations', ['recommendations' => $dashboard->band(DashboardSnapshot::BAND_RECOMMENDATIONS)])
@endif

@if($dashboard->failed(DashboardSnapshot::BAND_HEADLINES))
    @include('customer.dashboard.band-failed', ['band' => 'headlines', 'title' => 'Last 30 days'])
@elseif($dashboard->has(DashboardSnapshot::BAND_HEADLINES))
    @include('customer.dashboard.bands.headlines', ['headlines' => $dashboard->band(DashboardSnapshot::BAND_HEADLINES)])
@endif

@if($dashboard->failed(DashboardSnapshot::BAND_SPEND))
    @include('customer.dashboard.band-failed', ['band' => 'spend', 'title' => 'Spend and billing'])
@elseif($dashboard->has(DashboardSnapshot::BAND_SPEND))
    @include('customer.dashboard.bands.spend', ['spend' => $dashboard->band(DashboardSnapshot::BAND_SPEND)])
@endif

@if($dashboard->has(DashboardSnapshot::BAND_ACTIONS) && $dashboard->band(DashboardSnapshot::BAND_ACTIONS)['items'] !== [])
    @include('customer.dashboard.bands.actions', ['actions' => $dashboard->band(DashboardSnapshot::BAND_ACTIONS)])
@endif
