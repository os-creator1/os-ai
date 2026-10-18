{{--
    New contacts per day — short and honest. The page ships no series: home-v2.js asks the existing series endpoint for the SAME range
    the cards show and draws only a genuine payload. With under a week of observed data, or when every day is zero, there is no chart
    (it would add nothing the cards do not already say) — just one line. A failed read says so; it is never drawn as zeros.
    The series covers contacts only, so there is no Leads/Bookings switch: a bookings series does not exist yet.
--}}
@if($chart['future'] ?? false)
    {{-- A period that has not started has nothing to chart. --}}
    <div class="hv2-chart" data-role="home-chart-future">
        <p class="hv2-chart__empty mb-0">This period has not started, so there is nothing to chart yet.</p>
    </div>
@elseif($chart['restricted'] ?? false)
    {{-- The daily series covers the whole business; this actor sees only some Locations, so no chart (their cards above are scoped). --}}
    <div class="hv2-chart" data-role="home-chart-restricted">
        <p class="hv2-chart__empty mb-0">The daily chart covers your whole business, so it is not shown for your locations.@if($chart['resultsUrl']) <a href="{{ $chart['resultsUrl'] }}" data-role="results-link">See all analytics</a>@endif</p>
    </div>
@else
<div class="hv2-chart" data-role="home-chart" data-series-url="{{ $chart['seriesUrl'] }}" data-too-early="{{ $chart['tooEarly'] ? '1' : '0' }}" data-days="{{ $chart['days'] }}">
    <div class="hv2-chart__head">
        <p class="hv2-chart__title mb-0"><strong>New contacts per day</strong> <span class="hv2-muted" data-role="chart-total"></span></p>
    </div>

    {{-- Always requested: a young business that already has contacts must see them. Only an EMPTY series is explained (too early, or none). --}}
    <div class="hv2-chart__plot" data-role="chart-plot" role="img" aria-label="New contacts per day for the selected period"><p class="hv2-muted mb-0" data-role="chart-placeholder">Loading…</p></div>

    @if($chart['resultsUrl'])
        <p class="hv2-chart__foot mb-0"><a href="{{ $chart['resultsUrl'] }}" data-role="results-link">See all analytics</a></p>
    @endif
</div>
@endif
