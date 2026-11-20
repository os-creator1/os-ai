@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach')
@section('page-heading', 'own')

@section('content')
@php
    $info = $readiness->info();
    $walletItem = $readiness->item('wallet');
    $numberItem = $readiness->item('number');
    $fmt = fn ($rate) => $rate !== null ? $rate . '%' : '—';
    $ready = $readiness->isReady();
    $initials = fn ($m) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(preg_replace('/[^\p{L}\p{N} ]/u', '', (string) ($m->prospect->contact_name ?: $m->prospect->company_name)) ?: '?', 0, 2));
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'overview', 'ogInside' => true, 'ogActions' => 'campaign'])

    @if($pausedForFunds)
        <div class="alertbar" data-role="paused-for-funds">
            <span>Paused — add funds. Some texts are waiting to be sent until your balance is topped up.</span>
            <form method="post" action="{{ route('customer.workspaces.prospecting.sending.resume', $workspaceUid) }}">
                @csrf
                <button type="submit" class="btn pri sm">Resume sending</button>
            </form>
        </div>
    @endif

    {{-- Ready-to-send checklist: the real readiness conditions, never a static "ready". --}}
    <section class="ready {{ $ready ? 'ok' : 'no' }}" data-role="readiness-checklist" aria-labelledby="og-ready-title">
        <span class="bubble" aria-hidden="true">
            @if($ready)
                <svg class="ic" viewBox="0 0 24 24" style="width:24px;height:24px;stroke-width:2.4"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
            @else
                <svg class="ic" viewBox="0 0 24 24" style="width:24px;height:24px;stroke-width:2.2"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/></svg>
            @endif
        </span>
        <div class="body">
            <b id="og-ready-title" data-role="readiness-title">{{ $ready ? 'Ready to send' : 'Not ready to send yet' }}</b>
            <div class="chips">
                @foreach($readiness->items() as $item)
                    <span class="chip {{ $item['ok'] ? '' : 'no' }}" data-readiness="{{ $item['key'] }}" data-ok="{{ $item['ok'] ? '1' : '0' }}">
                        @if($item['ok'])
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg><span class="sr">Ready: </span>{{ $item['label'] }}
                        @else
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/></svg><span><strong>{{ $item['label'] }}</strong> — needs attention</span>
                        @endif
                    </span>
                @endforeach
            </div>
            @foreach($readiness->items() as $item)
                @if(! $item['ok'])
                    <span class="mut" data-readiness-reason="{{ $item['key'] }}">{{ $item['reason'] }}
                        @if(! empty($item['url']))<a class="link" href="{{ $item['url'] }}">Open</a>@endif
                    </span>
                @endif
            @endforeach
        </div>
        <span class="mut">STOP replies are never texted again. This is always on.</span>
    </section>

    <div class="kpis">
        <div class="k5"><span>Active prospects</span><b data-role="metric-active">{{ $metrics['active'] }}</b></div>
        <div class="k5"><span>Replies</span><b data-role="metric-replies">{{ $metrics['replies'] }}</b></div>
        <div class="k5"><span>Calls booked</span><b data-role="metric-booked">{{ $metrics['booked'] }}</b></div>
        <div class="k5"><span>Reply rate</span><b data-role="metric-reply-rate">{{ $fmt($metrics['reply_rate']) }}</b>@if($metrics['reply_rate'] !== null)<small>{{ $metrics['replies'] }} of {{ $metrics['messaged'] }} texted</small>@else<small>Nobody texted yet</small>@endif</div>
        <div class="k5"><span>Booking rate</span><b data-role="metric-booking-rate">{{ $fmt($metrics['booking_rate']) }}</b>@if($metrics['booking_rate'] !== null)<small>of replies</small>@else<small>No replies yet</small>@endif</div>
    </div>

    <div class="grid2">
        <section class="card" style="flex:3 1 500px" data-role="needs-you" aria-labelledby="og-needs">
            <div style="display:flex;align-items:center;padding:16px 20px">
                <b id="og-needs" style="flex:1;font-size:16px">Needs you</b>
                <a class="link" href="{{ route('customer.workspaces.prospecting.conversations.index', $workspaceUid) }}">All conversations</a>
            </div>
            @forelse($needsYou as $n)
                @php $m = $n['member']; @endphp
                <div class="row" style="grid-template-columns:minmax(0,1fr) auto auto" data-member="{{ $m->uid }}">
                    <div class="who">
                        <span class="av">{{ $initials($m) }}</span>
                        <div>
                            <b>{{ $m->prospect->contact_name ?: $m->prospect->company_name }}</b>
                            <span class="mut">{{ $n['reason'] }} · {{ $m->last_inbound_at?->diffForHumans() }}</span>
                        </div>
                    </div>
                    <span class="pl {{ $n['tone'] }}">{{ $n['pill'] }}</span>
                    @if($n['url'])
                        <a class="btn out sm" href="{{ $n['url'] }}">{{ $n['tone'] === 'a' ? 'Reply' : 'Open' }}</a>
                    @else
                        <span></span>
                    @endif
                </div>
            @empty
                <div class="empty" data-role="needs-you-empty"><b>You're all caught up</b>Replies that need a person — a call request or a booking link — show up here.</div>
            @endforelse
        </section>

        <section class="card pad" style="flex:2 1 300px;display:flex;flex-direction:column;gap:6px" data-role="sending-panel" aria-labelledby="og-sending">
            <b id="og-sending" style="font-size:16px;margin-bottom:4px">Sending</b>
            <div class="sm"><span>Number</span><b data-role="sending-number">{{ $info['number'] ?? 'Not set up' }}</b></div>
            <div class="sm"><span>Wallet balance</span><b data-role="wallet-balance">{{ $info['balance'] ?? '—' }}</b></div>
            <div class="sm"><span>Auto-recharge</span><b data-role="auto-recharge">{{ ($info['auto_recharge'] ?? null) === null ? '—' : ($info['auto_recharge'] ? 'On' : 'Off') }}</b></div>
            @if(! empty($info['cost_per_message']))
                <div class="sm"><span>Estimated cost per text</span><b data-role="cost-per-message">{{ $info['cost_per_message'] }}</b></div>
            @endif
            @if(! empty($info['business']))
                <span class="mut" data-role="sending-authority">Outreach texts go out from <strong>{{ $info['business']->name }}</strong> — your agency's own business, never a client's — and are paid from its wallet.</span>
            @endif
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:4px">
                @if(! empty($walletItem['url']))
                    <a class="btn pri sm" href="{{ $walletItem['url'] }}">Add funds</a>
                @endif
                @if(! empty($numberItem['url']))
                    <a class="btn out sm" href="{{ $numberItem['url'] }}">Manage number</a>
                @endif
                <a class="link" href="{{ route('customer.workspaces.prospecting.phone-messaging', $workspaceUid) }}">Phone &amp; texting settings</a>
            </div>
        </section>
    </div>
</div>
@endsection
