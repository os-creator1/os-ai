@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach conversations')

@section('content')
@php
    $stageTone = fn ($label) => match ($label) {
        'Booked' => 'g', 'Call asked' => 'a', 'Booking link sent', 'Scheduling' => 'b', default => 'n',
    };
    $stageLabel = fn ($stage) => \App\Library\AgencyOutreach\OutreachProspectStatusPresenter::stageLabel($stage);
    $cols = 'minmax(0,1.8fr) minmax(0,1fr) 140px 110px 110px 150px';
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'conversations', 'ogInside' => true])

    <form method="get" class="filters" data-role="conversation-filters" id="og-conv-filters">
        <label class="sel"><span>Campaign</span>
            <select name="campaign" id="f_campaign" aria-label="Campaign" onchange="this.form.submit()">
                <option value="">All</option>
                @foreach($campaigns as $campaign)
                    <option value="{{ $campaign->uid }}" @selected($filters['campaign'] === $campaign->uid)>{{ $campaign->name }}</option>
                @endforeach
            </select></label>
        <label class="sel"><span>Stage</span>
            <select name="stage" id="f_stage" aria-label="Stage" onchange="this.form.submit()">
                <option value="">Any</option>
                @foreach($stages as $stage)
                    <option value="{{ $stage->value }}" @selected($filters['stage'] === (string) $stage->value)>{{ $stageLabel($stage) }}</option>
                @endforeach
            </select></label>
        <label class="sel"><span>Replying</span>
            <select name="mode" id="f_mode" aria-label="Replying" onchange="this.form.submit()">
                <option value="">AI or you</option>
                <option value="ai" @selected($filters['mode'] === 'ai')>AI</option>
                <option value="manual" @selected($filters['mode'] === 'manual')>Manual (you)</option>
            </select></label>
        <label class="sel"><span>Outcome</span>
            <select name="outcome" id="f_outcome" aria-label="Outcome" onchange="this.form.submit()">
                <option value="">Any</option>
                <option value="booked" @selected($filters['outcome'] === 'booked')>Booked</option>
                <option value="rejected" @selected($filters['outcome'] === 'rejected')>Rejected / opted out</option>
            </select></label>
        <noscript><button type="submit" class="btn pri sm">Filter</button></noscript>
        <span class="grow"></span>
        <span class="mut" style="font-size:13px">Pause the AI to take over a chat yourself.</span>
    </form>

    <section class="card" aria-label="Conversations">
        @if($members->isEmpty())
            <div class="empty" data-role="conversations-empty"><b>No conversations yet</b>Conversations appear here once a prospect has been texted or replies.</div>
        @else
            <div data-role="conversations-table">
                <div class="row h" style="grid-template-columns:{{ $cols }}"><span>Prospect</span><span>Campaign</span><span>Stage</span><span>Replying</span><span>Last reply</span><span></span></div>
                @foreach($members as $member)
                    @php
                        $paused = $member->isAiPaused();
                        $name = $member->prospect->contact_name ?: $member->prospect->phone;
                        $label = $stageLabel($member->stage);
                        $unread = $member->last_inbound_at !== null && ($member->last_outbound_at === null || $member->last_inbound_at->greaterThan($member->last_outbound_at)) && ! $member->isTerminal();
                        $ini = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(preg_replace('/[^\p{L}\p{N} ]/u', '', (string) ($member->prospect->contact_name ?: $member->prospect->company_name)) ?: '?', 0, 2));
                    @endphp
                    <div class="row rsp" style="grid-template-columns:{{ $cols }}" data-member="{{ $member->uid }}">
                        <div class="who">
                            <span class="av" aria-hidden="true">{{ $ini }}</span>
                            <div style="flex-direction:row;align-items:center;gap:8px">
                                <b @if($unread) style="font-weight:700" @endif>{{ $name }}</b>
                                @if($unread)<span class="dotnew" title="Waiting for a reply from you or the AI"><span class="sr">New reply</span></span>@endif
                            </div>
                        </div>
                        <span class="ell" style="color:var(--og-neutral)" title="{{ $member->prospect->company_name }}">{{ $member->campaign?->name ?? '—' }}</span>
                        <span class="pl {{ $stageTone($label) }}">{{ $label }}</span>
                        @if($paused)
                            <span class="pl a">Manual</span>
                        @else
                            <span class="pl g"><svg class="ic" viewBox="0 0 24 24" style="width:12px;height:12px" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/></svg>AI</span>
                        @endif
                        <span class="mut" style="font-size:13px">{{ $member->last_inbound_at ? $member->last_inbound_at->diffForHumans() : 'No reply yet' }}</span>
                        <div class="acts">
                            @unless($member->isTerminal())
                                <form method="post" action="{{ route($paused ? 'customer.workspaces.prospecting.conversations.resume' : 'customer.workspaces.prospecting.conversations.pause', [$workspaceUid, $member->uid]) }}">
                                    @csrf
                                    <button type="submit" class="ib" title="{{ $paused ? 'Resume AI' : 'Pause AI' }}" aria-label="{{ $paused ? 'Resume AI' : 'Pause AI' }}">
                                        @if($paused)
                                            <svg class="ic" viewBox="0 0 24 24"><path d="M7 5l12 7-12 7z"/></svg>
                                        @else
                                            <svg class="ic" viewBox="0 0 24 24"><path d="M9 5v14M15 5v14"/></svg>
                                        @endif
                                    </button>
                                </form>
                            @endunless
                            @if($links[$member->id])
                                <a class="btn out sm" href="{{ $links[$member->id] }}">Open</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @if($members->hasPages())<div class="pager">{{ $members->links() }}</div>@endif
        @endif
    </section>
</div>
@endsection
