@php use App\Library\AgencyOutreach\Analytics\AnalyticsPresenter as P; @endphp
@if(count($rows) === 0)
    <div class="empty"><b>No data yet</b>Segments appear once prospects in them have been contacted.</div>
@else
    <div class="row h" style="grid-template-columns:minmax(0,1.4fr) 60px repeat(5,minmax(52px,.6fr))"><span>Segment</span><span>n</span><span>Reply</span><span>Interest</span><span>Booked</span><span>Rejected</span><span>Won</span></div>
    @foreach($rows as $r)
        <div class="row" style="grid-template-columns:minmax(0,1.4fr) 60px repeat(5,minmax(52px,.6fr))" data-segment="{{ $r['segment'] }}">
            <span class="ell"><b>{{ $r['segment'] }}</b>@if($r['small']) <span class="pl a" title="Fewer than {{ \App\Library\AgencyOutreach\Analytics\OutreachAnalytics::SMALL_SAMPLE }} contacted: treat as a hint, not a result.">Too few to tell</span>@endif</span>
            <span>{{ $r['n'] }}</span>
            <span>{{ P::pct($r['replied'], $r['n']) }}</span><span>{{ P::pct($r['interested'], $r['n']) }}</span><span>{{ P::pct($r['booked'], $r['n']) }}</span><span>{{ P::pct($r['rejected'], $r['n']) }}</span><span>{{ P::pct($r['won'], $r['n']) }}</span>
        </div>
    @endforeach
    <p class="mut" style="margin-top:8px">n = prospects contacted. Rates are shares of n. Select a segment’s filter above (business type, rating, reviews, website, state, source) to list its actual prospects in the Explorer.</p>
@endif
