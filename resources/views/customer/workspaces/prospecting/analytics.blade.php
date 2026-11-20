@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach analytics')
@section('page-heading', 'own')

@section('content')
@php
    use App\Library\AgencyOutreach\Analytics\AnalyticsPresenter as P;
    $q = $filters->toQuery();
    $url = fn (array $extra = []) => route('customer.workspaces.prospecting.analytics.index', $workspaceUid) . '?' . http_build_query(array_merge($q, $extra));
    $exUrl = fn (string $reachedKey) => $url(['tab' => 'explorer', 'reached' => $reachedKey]);
    $stepUrl = fn ($key) => route('customer.workspaces.prospecting.analytics.step', [$workspaceUid, $key]) . '?' . http_build_query($q);
    $dayNames = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
    $formatPhone = fn ($d) => '+' . $d;
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'analytics', 'ogInside' => true, 'ogActions' => 'analytics', 'exportUrl' => route('customer.workspaces.prospecting.analytics.export', $workspaceUid) . '?' . http_build_query($filters->toQuery(['reached' => $tab === 'explorer' ? ($reached ?? null) : null]))])

    <div class="seg" role="tablist" aria-label="Analytics sections" data-role="analytics-subtabs" style="align-self:flex-start">
        @foreach($tabs as $key => $label)
            <a href="{{ $url(['tab' => $key]) }}" role="tab" @if($tab === $key) class="on" aria-selected="true" aria-current="page" @else aria-selected="false" @endif>{{ $label }}</a>
        @endforeach
    </div>

    @include('customer.workspaces.prospecting._analytics-filters', ['hidden' => ['tab' => $tab]])

    <p class="mut" data-role="cohort-note">Figures cover prospects first contacted in the selected period, followed through every later reply, booking and win. Each prospect counts once, even if several campaigns reached them.</p>

    @if(in_array($tab, ['performance', 'messages'], true))
        @php $k = $kpis; @endphp
        <div class="kpis kgrid" data-role="analytics-kpis">
            <a class="k5" href="{{ $exUrl('contacted') }}"><span>Contacted</span><b>{{ $k['contacted'] }}</b><small>of {{ $k['eligible'] }} eligible</small></a>
            <a class="k5" href="{{ $exUrl('replied') }}"><span>Reply rate</span><b class="t-b">{{ P::pct($k['replied'], $k['contacted']) }}</b><small>{{ $k['replied'] }} of {{ $k['contacted'] }} contacted</small></a>
            <a class="k5" href="{{ $exUrl('interested') }}"><span>Interested</span><b class="t-g">{{ P::pct($k['interested'], $k['contacted']) }}</b><small>{{ $k['interested'] }} of {{ $k['contacted'] }} contacted</small></a>
            <a class="k5" href="{{ $exUrl('rejected') }}"><span>Rejected</span><b>{{ P::pct($k['rejected'], $k['contacted']) }}</b><small>{{ $k['rejected'] }} of {{ $k['contacted'] }} contacted</small></a>
            <a class="k5" href="{{ $exUrl('opted_out') }}"><span>Opted out</span><b>{{ P::pct($k['opted_out'], $k['contacted']) }}</b><small>{{ $k['opted_out'] }} replied STOP</small></a>
            <a class="k5" href="{{ $exUrl('booked') }}"><span>Calls booked</span><b>{{ $k['booked'] }}</b><small>{{ P::pct($k['booked'], $k['contacted']) }} of contacted</small></a>
            <a class="k5" href="{{ $exUrl('won') }}"><span>Clients won</span><b class="t-r">{{ $k['won'] }}</b><small>{{ $k['booked'] > 0 ? P::pct($k['won'], $k['booked']) . ' of ' . $k['booked'] . ' booked calls' : 'no booked calls yet' }}</small></a>
            <div class="k5"><span>Median reply time</span><b>{{ P::duration($k['median_reply_sec']) }}</b><small>75% within {{ P::duration($k['p75_reply_sec']) }} · {{ $k['reply_samples'] }} replies</small></div>
            <div class="k5"><span>Imported</span><b>{{ $k['imported'] }}</b><small>{{ $k['eligible'] }} eligible</small></div>
        </div>
    @endif

    @if($tab === 'performance' || $tab === 'messages')
        @php $worst = collect($steps)->sortByDesc('rejected')->first(); @endphp
        <section class="card pad" data-role="message-journey" aria-labelledby="journey-h">
            <div class="head" style="align-items:flex-start"><div><h3 id="journey-h" style="font-size:17px;font-weight:700">Where prospects drop off</h3>
                <p class="mut" style="margin-top:2px">Click a message to see exactly who stopped there and why. Rates use the prospects who actually received that message.</p></div>
                <div class="legend" aria-hidden="true"><span><i class="g"></i>Interested</span><span><i class="b"></i>Other reply</span><span><i class="a"></i>Rejected</span><span><i class="s"></i>Opted out</span><span><i class="n"></i>No response yet</span></div></div>
            @if(count($steps) === 0)
                <div class="empty" data-role="journey-empty"><b>No confirmed messages in this view</b>Messages appear once they are delivered. Try widening the period or resetting filters.</div>
            @else
                <div class="jr">
                    @foreach($steps as $s)
                        <a class="jr-step @if($worst && $worst['step_key'] === $s['step_key'] && $s['rejected'] > 0) hot @endif" href="{{ $stepUrl($s['step_key']) }}" data-step="{{ $s['step_key'] }}">
                            <span class="jr-t"><span class="num">{{ $s['number'] }}</span><b>{{ $s['label'] }}</b>@if($s['state'] !== 'live')<span class="pl a">{{ $s['state'] }}</span>@endif</span>
                            <span class="jr-r"><b>{{ P::pct($s['replied'], $s['reached']) }}</b><span class="mut">replied · {{ $s['reached'] }} reached</span></span>
                            <span class="jr-bar" role="img" aria-label="{{ $s['interested'] }} interested, {{ $s['other_replies'] }} other, {{ $s['rejected'] }} rejected, {{ $s['opted_out'] }} opted out, {{ $s['no_response'] }} no response yet">
                                @foreach(['interested' => 'g', 'other_replies' => 'b', 'rejected' => 'a', 'opted_out' => 's'] as $field => $tone)
                                    @if($s[$field] > 0)<i class="{{ $tone }}" style="width:{{ $s[$field] / max(1, $s['reached']) * 100 }}%"></i>@endif
                                @endforeach
                            </span>
                            <span class="jr-c"><span class="t-g"><b>{{ $s['interested'] }}</b> interested</span><span class="t-a"><b>{{ $s['rejected'] }}</b> rejected</span><span><b>{{ $s['opted_out'] }}</b> STOP</span></span>
                        </a>
                    @endforeach
                </div>
                @if($worst && $worst['rejected'] > 0)
                    <div class="jr-note" data-role="journey-note"><span><b>{{ $worst['label'] }}</b> lost the most to rejection — <b>{{ $worst['rejected'] }} rejected</b>. {{ $worst['no_response'] }} got no reply yet.</span>
                        <a class="link" href="{{ $stepUrl($worst['step_key']) }}">See the {{ $worst['reached'] }} businesses</a></div>
                @endif
                @if($unattributed > 0)<p class="mut" data-role="unattributed-note">{{ $unattributed }} sent conversation replies or hand-written texts belong to no scripted message, so they are not counted in any step.</p>@endif
            @endif
        </section>
    @endif

    @if($tab === 'performance')
        <div class="grid2">
            <section class="card pad" style="flex:1 1 420px" data-role="funnel" aria-labelledby="funnel-h">
                <h3 id="funnel-h" style="font-size:16px;font-weight:700">Funnel</h3>
                <p class="mut">Each stage counts a prospect once. Stages are counted independently: a conversation can skip a stage, so every percentage names what it is a share of.</p>
                @foreach($funnel as $st)
                    <div class="fn" data-stage="{{ $st['key'] }}">
                        <span>{{ $st['label'] }}</span>
                        <span class="fn-bar"><i class="s-{{ $st['key'] }}" style="width:{{ $funnel[0]['count'] > 0 ? min(100, $st['count'] / max(1, $funnel[0]['count']) * 100) : 0 }}%"></i></span>
                        <span class="fn-n"><b>{{ $st['count'] }}</b> <small class="mut">{{ $st['pct'] !== null ? $st['pct'] . '% of ' . $st['of'] . ' ' . $st['of_label'] : '' }}</small></span>
                    </div>
                @endforeach
            </section>
            <section class="card pad" style="flex:1 1 480px" data-role="segments-preview" aria-labelledby="seg-h">
                <h3 id="seg-h" style="font-size:16px;font-weight:700">By business type</h3>
                @include('customer.workspaces.prospecting._analytics-segments', ['rows' => $segments, 'dimension' => 'type'])
                <a class="link" href="{{ $url(['tab' => 'types']) }}">All segments →</a>
            </section>
        </div>
    @endif

    @if($tab === 'messages')
        <section class="card menu-card tw" data-role="message-table" role="region" aria-label="Message performance table" tabindex="0">
            <div class="row h" style="grid-template-columns:minmax(0,1.4fr) repeat(8,minmax(60px,.7fr))"><span>Message</span><span>Reached</span><span>Replied</span><span>Reply rate</span><span>Median reply</span><span>Interested</span><span>Booked</span><span>Won</span><span>Advanced</span></div>
            @foreach($steps as $s)
                <a class="row" href="{{ $stepUrl($s['step_key']) }}" style="grid-template-columns:minmax(0,1.4fr) repeat(8,minmax(60px,.7fr))">
                    <span><b>{{ $s['number'] }} · {{ $s['label'] }}</b><br><small class="mut">{{ $s['trigger'] }}</small></span>
                    <span>{{ $s['reached'] }}</span><span>{{ $s['replied'] }}</span><span>{{ P::pct($s['replied'], $s['reached']) }}</span>
                    <span>{{ P::duration($s['median_sec']) }}</span><span>{{ $s['interested'] }}</span><span>{{ $s['booked'] }}</span><span>{{ $s['won'] }}</span><span>{{ $s['advanced'] }}</span>
                </a>
            @endforeach
        </section>
    @endif

    @if($tab === 'types')
        @foreach($dimensions as $key => [$title, $rows])
            <section class="card pad" data-role="segments-{{ $key }}"><h3 style="font-size:16px;font-weight:700">{{ $title }}</h3>
                @include('customer.workspaces.prospecting._analytics-segments', ['rows' => $rows, 'dimension' => $key])</section>
        @endforeach
    @endif

    @if($tab === 'timing')
        @php $t = $timing; $maxBucket = max(1, collect($t['distribution'])->max('count') ?? 1); $maxHour = max(1, collect($t['hours'])->max('sent') ?? 1); @endphp
        <div class="kpis kgrid" data-role="timing-kpis">
            <div class="k5"><span>Median reply time</span><b>{{ P::duration($t['overall']['p50']) }}</b><small>{{ $t['overall']['n'] }} replies</small></div>
            <div class="k5"><span>75th percentile</span><b>{{ P::duration($t['overall']['p75']) }}</b><small>75% reply within</small></div>
            <div class="k5"><span>AI replies sent</span><b>{{ $t['ai']['replies'] }}</b><small>{{ $t['ai']['paced'] }} with a planned wait</small></div>
            <div class="k5"><span>Planned wait (avg)</span><b>{{ P::duration($t['ai']['avg_planned_wait']) }}</b><small>randomised, per category</small></div>
            <div class="k5"><span>Delay vs plan (avg)</span><b>{{ P::duration($t['ai']['avg_send_lag']) }}</b><small>{{ $t['ai']['deferred'] }} held by rate limits</small></div>
            <div class="k5"><span>Manual takeover latency</span><b>{{ P::duration($t['manual']['avg_latency']) }}</b><small>{{ $t['manual']['count'] }} hand-written texts</small></div>
        </div>
        <section class="card pad" data-role="latency-distribution"><h3 style="font-size:16px;font-weight:700">How quickly they reply</h3>
            @forelse($t['distribution'] as $b)<div class="fn"><span>{{ $b['bucket'] }}</span><span class="fn-bar"><i style="width:{{ $b['count'] / $maxBucket * 100 }}%"></i></span><b>{{ $b['count'] }}</b></div>
            @empty<div class="empty"><b>No replies with timing yet</b>Reply times need a confirmed send and a later inbound message.</div>@endforelse</section>
        <div class="grid2">
            <section class="card pad" style="flex:1 1 360px" data-role="send-hours"><h3 style="font-size:16px;font-weight:700">Send hour (server time)</h3>
                @forelse($t['hours'] as $h)<div class="fn"><span>{{ sprintf('%02d:00', $h['hour']) }}</span><span class="fn-bar"><i style="width:{{ $h['sent'] / $maxHour * 100 }}%"></i></span><b>{{ P::pct($h['replied'], $h['sent']) }}</b><small class="mut">{{ $h['replied'] }}/{{ $h['sent'] }}</small></div>@empty<div class="empty">No sends in this view.</div>@endforelse</section>
            <section class="card pad" style="flex:1 1 360px" data-role="send-days"><h3 style="font-size:16px;font-weight:700">Day of week</h3>
                @forelse($t['weekdays'] as $d)<div class="fn"><span>{{ $dayNames[$d['day']] ?? $d['day'] }}</span><span class="fn-bar"><i style="width:{{ $d['sent'] > 0 ? $d['replied'] / $d['sent'] * 100 : 0 }}%"></i></span><b>{{ P::pct($d['replied'], $d['sent']) }}</b><small class="mut">{{ $d['replied'] }}/{{ $d['sent'] }}</small></div>@empty<div class="empty">No sends in this view.</div>@endforelse</section>
        </div>
        <p class="mut">Rate-limit holds and 10-second per-sender pacing are applied by the sender and shown here only as recorded evidence; older messages sent before this evidence was stored show “—”.</p>
    @endif

    @if($tab === 'explorer')
        <section class="card menu-card tw" data-role="explorer" role="region" aria-label="Prospect explorer table" tabindex="0">
            <form method="get" class="filters" style="padding:14px 16px">
                @foreach($filters->toQuery() as $fk => $fv)<input type="hidden" name="{{ $fk }}" value="{{ $fv }}">@endforeach
                <input type="hidden" name="tab" value="explorer">@if($reached)<input type="hidden" name="reached" value="{{ $reached }}">@endif
                <label class="search"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                    <input type="search" name="q" value="{{ $filters->q }}" placeholder="Search business or phone" aria-label="Search prospects"></label>
                <button class="btn out sm" type="submit">Search</button>
                @if($reached)<span class="pl r">Showing: {{ str_replace('_', ' ', $reached) }}</span>@endif
            </form>
            @php $ex = 'minmax(0,1.5fr) minmax(0,1fr) minmax(0,1fr) 90px minmax(0,1fr) 60px minmax(0,1.3fr) 90px 110px'; @endphp
            <div class="row h" style="grid-template-columns:{{ $ex }}"><span>Business</span><span>Campaign</span><span>Type · area</span><span>Rating</span><span>Source</span><span>Steps</span><span>Last reply</span><span>Reply time</span><span>Outcome</span></div>
            @forelse($explorer['rows'] as $r)
                <div class="row" style="grid-template-columns:{{ $ex }}" data-prospect="{{ $r->uid }}">
                    <span><a href="{{ route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $r->uid]) }}"><b>{{ $r->company_name }}</b></a><br><small class="mut">{{ $formatPhone($r->phone) }} · {{ $r->has_website === null ? 'website unknown' : ($r->has_website ? 'has website' : 'no website') }}</small></span>
                    <span class="ell">{{ $r->campaign_name ?? '—' }}</span>
                    <span class="ell">{{ $r->business_type ?: '—' }}<br><small class="mut">{{ collect([$r->city, $r->state])->filter()->implode(', ') ?: '—' }}</small></span>
                    <span>{{ $r->google_rating !== null ? '★ ' . $r->google_rating : '—' }}<br><small class="mut">{{ $r->review_count !== null ? '(' . $r->review_count . ')' : '' }}</small></span>
                    <span class="ell mut" title="{{ $r->group_names }}">{{ $r->group_names ?: ($r->source_name ?? '—') }}</span>
                    <span>{{ $r->steps_sent }}</span>
                    <span class="ell mut" title="{{ $r->last_in }}">{{ $r->last_in ? '“' . \Illuminate\Support\Str::limit($r->last_in, 50) . '”' : '—' }}</span>
                    <span>{{ P::duration($r->reply_sec !== null ? (int) $r->reply_sec : null) }}</span>
                    <span class="pl {{ ['won' => 'g', 'booked' => 'g', 'interested' => 'g', 'replied' => 'b', 'rejected' => 'a', 'opted_out' => 'n'][$r->outcome] ?? 'n' }}">{{ ['none' => 'Not contacted', 'no_response' => 'No response', 'opted_out' => 'Opted out', 'won' => 'Client won', 'booked' => 'Call booked'][$r->outcome] ?? ucfirst($r->outcome) }}</span>
                </div>
            @empty
                <div class="empty" data-role="explorer-empty"><b>No prospects match these filters</b>Reset filters or widen the period.</div>
            @endforelse
            <div class="pager">{{ $explorer['total'] }} {{ \Illuminate\Support\Str::plural('prospect', $explorer['total']) }} · page {{ $page }} of {{ $pages }}
                @if($page > 1) · <a class="link" href="{{ $url(['tab' => 'explorer', 'reached' => $reached, 'page' => $page - 1]) }}">Previous</a>@endif
                @if($page < $pages) · <a class="link" href="{{ $url(['tab' => 'explorer', 'reached' => $reached, 'page' => $page + 1]) }}">Next</a>@endif</div>
        </section>
    @endif
</div>
@endsection
