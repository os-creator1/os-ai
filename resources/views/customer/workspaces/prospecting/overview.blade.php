@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Outreach</h4>
            <p class="text-caption mb-0">Text local businesses, answer their replies and book calls. Real numbers only.</p>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'overview'])

    @php
        $info = $readiness->info();
        $walletItem = $readiness->item('wallet');
        $numberItem = $readiness->item('number');
        $fmt = fn ($rate) => $rate !== null ? $rate . '%' : '—';
    @endphp

    @if($pausedForFunds)
        <x-alert variant="warning" data-role="paused-for-funds">
            <div class="d-flex justify-content-between align-items-center w-100 gap-2">
                <span>Paused — add funds. Some texts are waiting to be sent until your balance is topped up.</span>
                <form method="post" action="{{ route('customer.workspaces.prospecting.sending.resume', $workspaceUid) }}">
                    @csrf
                    <x-button type="submit" variant="primary" size="sm">Resume sending</x-button>
                </form>
            </div>
        </x-alert>
    @endif

    <div class="row">
        <div class="col-md-5 mb-2">
            <x-card title="Sending" :padded="true" data-role="sending-panel">
                <dl class="row mb-2">
                    <dt class="col-sm-6">Sending number</dt>
                    <dd class="col-sm-6" data-role="sending-number">{{ $info['number'] ?? 'Not set up' }}</dd>

                    <dt class="col-sm-6">Wallet balance</dt>
                    <dd class="col-sm-6" data-role="wallet-balance">{{ $info['balance'] ?? '—' }}</dd>

                    <dt class="col-sm-6">Auto-recharge</dt>
                    <dd class="col-sm-6" data-role="auto-recharge">{{ ($info['auto_recharge'] ?? null) === null ? '—' : ($info['auto_recharge'] ? 'On' : 'Off') }}</dd>

                    @if(! empty($info['cost_per_message']))
                        <dt class="col-sm-6">Estimated cost per text</dt>
                        <dd class="col-sm-6" data-role="cost-per-message">{{ $info['cost_per_message'] }}</dd>
                    @endif
                </dl>
                <div class="d-flex gap-2">
                    @if(! empty($walletItem['url']))
                        <x-button variant="primary" size="sm" :href="$walletItem['url']">Add funds</x-button>
                    @endif
                    @if(! empty($numberItem['url']))
                        <x-button variant="outline" size="sm" :href="$numberItem['url']">Manage number</x-button>
                    @endif
                </div>
            </x-card>
        </div>

        <div class="col-md-7 mb-2">
            <x-card title="Before you can start" :padded="true" data-role="readiness-checklist">
                <ul class="list-unstyled mb-0">
                    @foreach($readiness->items() as $item)
                        <li class="mb-1 d-flex gap-2 align-items-start" data-readiness="{{ $item['key'] }}" data-ok="{{ $item['ok'] ? '1' : '0' }}">
                            <x-badge :variant="$item['ok'] ? 'success' : 'warning'">{{ $item['ok'] ? 'Ready' : 'Needs attention' }}</x-badge>
                            <span>
                                <strong>{{ $item['label'] }}</strong>
                                @if(! $item['ok'])
                                    <span class="d-block text-caption">{{ $item['reason'] }}
                                        @if(! empty($item['url']))
                                            <a href="{{ $item['url'] }}">Open</a>
                                        @endif
                                    </span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
                <p class="text-caption mt-2 mb-0">People who reply STOP are never texted again. This is always on.</p>
            </x-card>
        </div>
    </div>

    <div class="row">
        <div class="col-md-2 col-sm-6 mb-2 offset-md-1">
            <x-card :padded="true"><p class="text-caption mb-1">Active prospects</p><h3 class="mb-0" data-role="metric-active">{{ $metrics['active'] }}</h3></x-card>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
            <x-card :padded="true"><p class="text-caption mb-1">Replies</p><h3 class="mb-0" data-role="metric-replies">{{ $metrics['replies'] }}</h3></x-card>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
            <x-card :padded="true"><p class="text-caption mb-1">Calls booked</p><h3 class="mb-0" data-role="metric-booked">{{ $metrics['booked'] }}</h3></x-card>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
            <x-card :padded="true"><p class="text-caption mb-1">Reply rate</p><h3 class="mb-0" data-role="metric-reply-rate">{{ $fmt($metrics['reply_rate']) }}</h3></x-card>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
            <x-card :padded="true"><p class="text-caption mb-1">Booking rate</p><h3 class="mb-0" data-role="metric-booking-rate">{{ $fmt($metrics['booking_rate']) }}</h3></x-card>
        </div>
    </div>
@endsection
