{{--
    The Business Home's bands, in the parent's order: one billing exception
    when there is a real one (Unified Business Home §2.2 row 0), what changed
    since this customer was last here (§2.3), what needs attention, what the
    Advisor recommends, how the last 30 days compare, and the quick actions.
    A band with nothing to say is absent; a band whose source failed says so
    in one line.

    Spend left this page with H-1: balance, spend, top-ups and invoices are a
    Settings → Billing destination, and Home mentions billing only when the
    customer has something to do about it.
--}}
@php use App\Library\Dashboard\DashboardSnapshot; @endphp

@if($dashboard->has(DashboardSnapshot::BAND_BILLING_EXCEPTION))
    @include('customer.dashboard.bands.billing-exception', ['exception' => $dashboard->band(DashboardSnapshot::BAND_BILLING_EXCEPTION)])
@endif

@if($dashboard->failed(DashboardSnapshot::BAND_ACTIVITY))
    @include('customer.dashboard.band-failed', ['band' => 'activity', 'title' => 'Business activity'])
@elseif($dashboard->has(DashboardSnapshot::BAND_ACTIVITY))
    @include('customer.dashboard.bands.activity', ['activity' => $dashboard->band(DashboardSnapshot::BAND_ACTIVITY)])
@endif

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
    @include('customer.dashboard.band-failed', ['band' => 'headlines', 'title' => 'Business performance'])
@elseif($dashboard->has(DashboardSnapshot::BAND_HEADLINES))
    @include('customer.dashboard.bands.headlines', ['headlines' => $dashboard->band(DashboardSnapshot::BAND_HEADLINES)])
@endif

@if($dashboard->has(DashboardSnapshot::BAND_ACTIONS) && $dashboard->band(DashboardSnapshot::BAND_ACTIONS)['items'] !== [])
    @include('customer.dashboard.bands.actions', ['actions' => $dashboard->band(DashboardSnapshot::BAND_ACTIONS)])
@endif
