{{--
    Meta Ads Module V1 (contract 24 §9) — the cross-channel Ads Overview.

    One compact block per channel, each with its OWN spend, results (own label
    and definition), cost per result, pacing, issue count and freshness.
    NOTHING is blended: no combined conversions, no combined cost per result,
    no combined or split targets. A total spend appears only when every shown
    channel reports in the same currency. Not-connected channels get a calm
    connect call to action. Every string from a provider or the customer is
    escaped; no provider id is ever rendered.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Ads')

@php
    use App\Library\MetaAds\MetaAdsMoney;

    $readyCount = collect($channels)->where('ready', true)->count();
@endphp

@section('content')
    @include('customer.business.ads._header', [
        'title' => 'Ads',
        'subtitle' => 'Each channel is reported on its own terms. Nothing is blended.',
        'showFreshness' => false,
    ])

    <x-flash-alert class="mb-2" />

    <style>
        .ov-bar { display: flex; height: .55rem; border-radius: 999px; overflow: hidden; background: rgba(120,113,108,.18); }
        .ov-bar > span { display: block; height: 100%; }
        .ov-key { display: flex; flex-wrap: wrap; gap: 1rem; font-size: .8rem; margin-top: .6rem; }
        .ov-dot { display: inline-block; width: .55rem; height: .55rem; border-radius: 2px; margin-right: .3rem; }
        .ov-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); border: 1px solid rgba(120,113,108,.22); border-radius: .5rem; margin-bottom: 1rem; }
        .ov-grid > div { padding: .7rem .9rem; }
        .ov-grid > div:nth-child(odd) { border-right: 1px solid rgba(120,113,108,.22); }
        .ov-grid > div:nth-child(n+3) { border-top: 1px solid rgba(120,113,108,.22); }
        .ov-grid small { display: block; font-size: .7rem; opacity: .7; }
        .ov-grid strong { font-size: 1.35rem; }
        .ov-meter { height: .5rem; border-radius: 999px; background: rgba(120,113,108,.18); position: relative; }
        .ov-meter > span { display: block; height: 100%; border-radius: 999px; background: #c98f2c; }
        .ov-meter > i { position: absolute; top: -.2rem; bottom: -.2rem; width: 2px; background: currentColor; }
        .ov-sec { font-size: .7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; padding: .5rem 1rem; }
        .ov-sec.is-act { background: rgba(201,143,44,.1); }
        .ov-sec.is-well { background: rgba(46,125,50,.08); }
        .ov-item { display: flex; gap: .75rem; align-items: center; justify-content: space-between; padding: .75rem 1rem; border-top: 1px solid rgba(120,113,108,.15); }
        .ov-item > div { min-width: 0; overflow-wrap: anywhere; }
    </style>

    @php
        $channelColor = ['google' => '#2f6db5', 'meta' => '#8a63b8'];
        $shown = collect($channels)->where('ready', true);
        $actItems = array_values(array_filter($attention, fn ($i) => ($i['tone'] ?? null) !== 'success'));
        $wellItems = array_values(array_filter($attention, fn ($i) => ($i['tone'] ?? null) === 'success'));
    @endphp

    @if($total !== null)
        <x-card :padded="true" class="mb-2" data-role="total-spend">
            <p class="text-label mb-1">Total spend this month</p>
            <p class="h2 mb-1" data-role="total-spend-value">{{ MetaAdsMoney::format($total['spend_micros'], $total['currency']) }}</p>
            @if($total['spend_micros'] > 0)
                <div class="ov-bar" role="img" aria-label="Share of this month's spend by channel" data-role="spend-distribution">
                    @foreach($shown as $ch)
                        <span style="width: {{ round(((int) $ch['spend_micros']) / $total['spend_micros'] * 100, 2) }}%; background: {{ $channelColor[$ch['key']] ?? '#888' }}" data-channel-share="{{ $ch['key'] }}"></span>
                    @endforeach
                </div>
                <div class="ov-key" data-role="spend-distribution-key">
                    @foreach($shown as $ch)
                        <span><i class="ov-dot" style="background: {{ $channelColor[$ch['key']] ?? '#888' }}"></i>{{ $ch['label'] }} <strong>{{ $ch['spend_display'] }}</strong> {{ round(((int) $ch['spend_micros']) / $total['spend_micros'] * 100) }}%</span>
                    @endforeach
                </div>
            @endif
            <p class="text-caption text-muted mt-1 mb-0">Both channels report in {{ $total['currency'] }}. Results are not added together: each channel counts them differently.</p>
        </x-card>
    @elseif($mixedCurrencies)
        <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="mixed-currencies">
            Different currencies are shown separately.
        </x-alert>
    @endif

    <div class="row" data-role="channels">
        @foreach($channels as $channel)
            <div class="col-12 col-lg-6 mb-2">
                @if($channel['ready'])
                    <x-card :padded="true" class="h-100" data-role="channel-block" data-channel="{{ $channel['key'] }}">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h2 class="text-section-heading mb-0">{{ $channel['label'] }}</h2>
                            <x-badge variant="neutral" data-role="channel-currency">{{ $channel['currency'] }}</x-badge>
                        </div>

                        <div class="ov-grid">
                            <div><small>Spend this month</small><strong data-role="channel-spend">{{ $channel['spend_display'] }}</strong></div>
                            <div>
                                <small>{{ $channel['results_label'] }}
                                    <x-tooltip text="{{ $channel['results_definition'] }}">
                                        <x-ds-icon name="info" size="13" aria-label="About {{ $channel['results_label'] }}" />
                                    </x-tooltip>
                                </small>
                                <strong data-role="channel-results">
                                    @if($channel['results_unset'])
                                        <a href="{{ $channel['settings_url'] }}" class="h6" data-role="choose-result-type">Choose a result type</a>
                                    @else
                                        {{ $channel['results'] ?? '—' }}
                                    @endif
                                </strong>
                            </div>
                            <div><small>{{ $channel['cost_per_result_label'] }}</small><strong data-role="channel-cost-per-result">{{ $channel['cost_per_result_display'] }}</strong></div>
                            @if($channel['issue_count'] !== null)
                                <div><small>Needs attention</small><strong data-role="channel-issues">{{ $channel['issue_count'] }} {{ $channel['issue_count'] === 1 ? 'item' : 'items' }}</strong></div>
                            @endif
                        </div>

                        @php $pd = $channel['pacing_detail'] ?? null; @endphp
                        <div class="mb-1">
                            <div class="d-flex justify-content-between text-caption"><span class="text-label">Monthly budget</span>
                                <span data-role="channel-pacing" data-status="{{ $channel['pacing_status'] }}">{{ $channel['pacing_text'] }}</span></div>
                            @if($pd && $pd['spend_proportion'] !== null && $pd['elapsed_proportion'] !== null)
                                @php $used = max(0, min(100, $pd['spend_proportion'] * 100)); $gone = max(0, min(100, $pd['elapsed_proportion'] * 100)); @endphp
                                <div class="ov-meter my-50" role="img" aria-label="{{ round($used) }}% of the monthly budget used, {{ round($gone) }}% of the month gone"><span style="width: {{ $used }}%"></span><i style="left: {{ $gone }}%"></i></div>
                                <div class="d-flex justify-content-between text-caption text-muted"><span>{{ round($used) }}% of {{ \App\Library\MetaAds\MetaAdsMoney::format($pd['monthly_target_micros'], $channel['currency']) }} used</span><span>Today: {{ round($gone) }}% of month</span></div>
                                @if($pd['projected_micros'] !== null)
                                    <div class="text-caption text-muted">{{ $pd['low_confidence'] ? 'Early estimate: at' : 'At' }} this rate it ends near {{ \App\Library\MetaAds\MetaAdsMoney::format($pd['projected_micros'], $channel['currency']) }}</div>
                                @endif
                            @endif
                        </div>

                        <p class="text-caption text-muted mb-1" data-role="channel-freshness" @if($channel['freshness_warn']) data-warn="1" @endif>
                            @if($channel['updated_at'] !== null)
                                Updated {{ $channel['updated_at']->diffForHumans() }}@if($channel['data_through'] !== null) &middot; Data through {{ $channel['data_through']->format('M j, Y') }}@endif
                            @else
                                Waiting for the first update.
                            @endif
                            @if($channel['freshness_warn'])
                                <span class="d-block">The latest refresh had a problem; the last successful figures are shown.</span>
                            @endif
                        </p>

                        @if($channel['url'])
                            <a href="{{ $channel['url'] }}" class="btn btn-sm btn-outline-secondary" data-role="channel-open">Open {{ $channel['label'] }} &rarr;</a>
                        @endif
                    </x-card>
                @else
                    <x-card :padded="true" class="h-100" data-role="channel-block" data-channel="{{ $channel['key'] }}" data-state="{{ $channel['state'] }}">
                        <h2 class="text-section-heading mb-1">{{ $channel['label'] }}</h2>
                        <p class="text-caption mb-2" data-role="channel-not-connected">
                            @if($channel['state'] === 'expired')
                                This connection has expired, so figures are not updating.
                            @elseif($channel['state'] === 'no_account')
                                Connected, but no ad account has been chosen yet.
                            @else
                                Not connected yet.
                            @endif
                        </p>
                        @if($channel['cta_url'])
                            <a href="{{ $channel['cta_url'] }}" class="btn btn-sm btn-primary" data-role="channel-cta">{{ $channel['cta_label'] }}</a>
                        @endif
                    </x-card>
                @endif
            </div>
        @endforeach
    </div>

    @if($readyCount > 0)
        <x-card :padded="false" class="mb-2" data-role="attention">
            <h2 class="text-section-heading p-1 mb-0">What needs attention</h2>
            @if(count($attention) === 0)
                <p class="text-caption px-1 pb-1 mb-0" data-role="attention-none">Nothing stands out right now.</p>
            @endif
            @foreach([['is-act', 'Act on these', $actItems], ['is-well', 'Going well', $wellItems]] as [$cls, $heading, $items])
                @if(count($items) > 0)
                    <div class="ov-sec {{ $cls }}" data-role="attention-section">{{ $heading }} &middot; {{ count($items) }}</div>
                    @foreach($items as $item)
                        <div class="ov-item" data-role="attention-item" data-provider="{{ $item['provider'] }}" data-tone="{{ $item['tone'] ?? 'neutral' }}">
                            <div>
                                <x-badge variant="neutral" data-role="attention-provider">{{ $item['provider'] === 'meta' ? 'Meta' : 'Google' }}</x-badge>
                                <strong>{{ $item['title'] }}</strong>
                                @foreach($item['evidence_lines'] as $line)
                                    <span class="d-block text-caption text-muted">{{ $line }}</span>
                                @endforeach
                            </div>
                            @if($item['url'])
                                <a href="{{ $item['url'] }}" class="btn btn-sm {{ $loop->first && $cls === 'is-act' ? 'btn-primary' : 'btn-outline-secondary' }} text-nowrap">{{ $item['action_label'] }}</a>
                            @endif
                        </div>
                    @endforeach
                @endif
            @endforeach
        </x-card>
    @endif
@endsection
