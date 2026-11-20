@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach message analytics')
@section('page-heading', 'own')

@section('content')
@php
    use App\Library\AgencyOutreach\Analytics\AnalyticsPresenter as P;
    $q = $filters->toQuery();
    $s = $info;
    $back = route('customer.workspaces.prospecting.analytics.index', $workspaceUid) . '?' . http_build_query($q);
    $tabUrl = fn ($t) => route('customer.workspaces.prospecting.analytics.step', [$workspaceUid, $s['step_key']]) . '?' . http_build_query(array_merge($q, $t === 'all' ? [] : ['tab' => $t]));
    $c = $list['counts'];
    $editUrl = route('customer.workspaces.prospecting.script.show', $workspaceUid) . ($s['step_key'] !== 'opener' ? '?step=' . urlencode($s['step_key']) : '');
    $cols = 'minmax(0,1.5fr) minmax(0,1fr) 90px 90px minmax(0,1.5fr) 110px';
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'analytics', 'ogInside' => true])

    <a class="link" href="{{ $back }}" data-role="back-to-analytics">← Analytics</a>

    <div class="head" data-role="step-header">
        <div>
            <h1 style="display:flex;align-items:center;gap:10px"><span class="av" style="width:40px;height:40px;font-size:15px">{{ $s['number'] }}</span>{{ $s['label'] }}
                @if($s['state'] !== 'live')<span class="pl a">{{ ucfirst($s['state']) }}</span>@endif</h1>
            <p>{{ $s['trigger'] }}</p>
        </div>
        @if($canEdit)
            <a class="btn out" data-role="edit-message" href="{{ $editUrl }}">Edit this message</a>
        @else
            <span class="btn out dis" aria-disabled="true" title="This message was removed from your script, so there is nothing to edit. Its history below stays as it was sent.">Edit this message</span>
        @endif
    </div>

    <section class="card pad" data-role="step-text">
        @foreach($texts as $t)
            <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:8px"><span class="pl n">v{{ $t['script_version'] ?? '?' }}</span>
                <span style="white-space:pre-wrap">{{ $t['body'] }}</span><span class="mut" style="margin-left:auto;white-space:nowrap">sent {{ $t['sent'] }}×</span></div>
        @endforeach
        <p class="mut">Exact text as it was sent, with the script version it went out under. Editing the script never changes this history.</p>
    </section>

    <div class="kpis" data-role="step-kpis">
        <div class="k5"><span>Reached this step</span><b>{{ $s['reached'] }}</b><small>prospects</small></div>
        <div class="k5"><span>Replied</span><b>{{ $s['replied'] }}</b><small>{{ P::pct($s['replied'], $s['reached']) }} of {{ $s['reached'] }}</small></div>
        <div class="k5"><span>Interested</span><b>{{ $s['interested'] }}</b><small>{{ P::pct($s['interested'], $s['reached']) }}</small></div>
        <div class="k5"><span>Rejected</span><b>{{ $s['rejected'] }}</b><small>{{ P::pct($s['rejected'], $s['reached']) }}</small></div>
        <div class="k5"><span>Opted out</span><b>{{ $s['opted_out'] }}</b><small>STOP</small></div>
        <div class="k5"><span>No response yet</span><b>{{ $s['no_response'] }}</b><small>{{ P::pct($s['no_response'], $s['reached']) }}</small></div>
        <div class="k5"><span>Moved on</span><b>{{ $s['advanced'] }}</b><small>{{ $nextLabel ? 'reached ' . $nextLabel : 'next step' }}</small></div>
        <div class="k5"><span>Median reply</span><b>{{ P::duration($s['median_sec']) }}</b><small>75% within {{ P::duration($s['p75_sec']) }}</small></div>
    </div>

    @include('customer.workspaces.prospecting._analytics-filters', ['action' => route('customer.workspaces.prospecting.analytics.step', [$workspaceUid, $s['step_key']])])

    <section class="card menu-card" data-role="step-businesses">
        <div class="filters" style="padding:14px 16px">
            <div class="seg" role="tablist" aria-label="Outcome">
                @foreach(['all' => ['All', $c['all']], 'replied' => ['Replied', $c['replied']], 'rejected' => ['Rejected', $c['rejected']], 'opted_out' => ['Opted out', $c['opted_out']], 'no_response' => ['No response', $c['no_response']]] as $key => [$label, $n])
                    <a href="{{ $tabUrl($key) }}" role="tab" @if($tab === $key) class="on" aria-selected="true" @else aria-selected="false" @endif>{{ $label }} <span class="mut">{{ $n }}</span></a>
                @endforeach
            </div>
        </div>
        <div class="row h" style="grid-template-columns:{{ $cols }}"><span>Business</span><span>Type</span><span>Rating</span><span>Website</span><span>Their reply</span><span>Outcome</span></div>
        @forelse($list['rows'] as $r)
            <div class="row" style="grid-template-columns:{{ $cols }}" data-prospect="{{ $r->prospect_uid }}">
                <a href="{{ route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $r->prospect_uid]) }}"><b>{{ $r->company_name }}</b></a>
                <span class="ell">{{ $r->business_type ?: '—' }}</span>
                <span>{{ $r->google_rating !== null ? '★ ' . $r->google_rating : '—' }}@if($r->review_count !== null) <small class="mut">({{ $r->review_count }})</small>@endif</span>
                <span>{{ $r->has_website === null ? '—' : ($r->has_website ? 'Yes' : 'No') }}</span>
                <span class="ell mut" title="{{ $r->reply_body }}">{{ $r->reply_body ? '“' . \Illuminate\Support\Str::limit($r->reply_body, 60) . '”' : '—' }}</span>
                <span class="pl {{ ['rejected' => 'r', 'opted_out' => 'a', 'interested' => 'g', 'other' => 'b', 'no_response' => 'n'][$r->step_outcome] ?? 'n' }}">{{ ['rejected' => 'Rejected', 'opted_out' => 'Opted out', 'interested' => 'Interested', 'other' => 'Replied', 'no_response' => 'No response'][$r->step_outcome] ?? $r->step_outcome }}</span>
            </div>
        @empty
            <div class="empty" data-role="step-empty"><b>Nobody here</b>No businesses match this tab and these filters.</div>
        @endforelse
        <div class="pager">Showing {{ count($list['rows']) }} of {{ $list['total'] }} · page {{ $page }} of {{ $pages }}
            @if($page > 1) · <a class="link" href="{{ $tabUrl($tab) }}&page={{ $page - 1 }}">Previous</a>@endif
            @if($page < $pages) · <a class="link" href="{{ $tabUrl($tab) }}&page={{ $page + 1 }}">Next</a>@endif</div>
    </section>
</div>
@endsection
