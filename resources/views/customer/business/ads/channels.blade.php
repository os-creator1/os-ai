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

    @if($total !== null)
        <x-card :padded="true" class="mb-2" data-role="total-spend">
            <p class="text-label mb-1">Total spend this month</p>
            <p class="h2 mb-0" data-role="total-spend-value">{{ MetaAdsMoney::format($total['spend_micros'], $total['currency']) }}</p>
            <p class="text-caption text-muted mb-0">Both channels report in {{ $total['currency'] }}. Results are not added together: each channel counts them differently.</p>
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

                        <dl class="row mb-1">
                            <dt class="col-6 text-label">Spend this month</dt>
                            <dd class="col-6 text-end mb-50" data-role="channel-spend">{{ $channel['spend_display'] }}</dd>

                            <dt class="col-6 text-label">
                                {{ $channel['results_label'] }}
                                <x-tooltip text="{{ $channel['results_definition'] }}">
                                    <x-ds-icon name="info" size="14" aria-label="About {{ $channel['results_label'] }}" />
                                </x-tooltip>
                            </dt>
                            <dd class="col-6 text-end mb-50" data-role="channel-results">
                                @if($channel['results_unset'])
                                    <a href="{{ $channel['settings_url'] }}" data-role="choose-result-type">Choose a result type</a>
                                @else
                                    {{ $channel['results'] ?? '—' }}
                                @endif
                            </dd>

                            <dt class="col-6 text-label">{{ $channel['cost_per_result_label'] }}</dt>
                            <dd class="col-6 text-end mb-50" data-role="channel-cost-per-result">{{ $channel['cost_per_result_display'] }}</dd>

                            <dt class="col-6 text-label">Pacing</dt>
                            <dd class="col-6 text-end mb-50" data-role="channel-pacing" data-status="{{ $channel['pacing_status'] }}">{{ $channel['pacing_text'] }}</dd>

                            @if($channel['issue_count'] !== null)
                                <dt class="col-6 text-label">Needs attention</dt>
                                <dd class="col-6 text-end mb-50" data-role="channel-issues">{{ $channel['issue_count'] }} {{ $channel['issue_count'] === 1 ? 'item' : 'items' }}</dd>
                            @endif
                        </dl>

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
                            <a href="{{ $channel['url'] }}" class="btn btn-sm btn-outline-secondary" data-role="channel-open">Open {{ $channel['label'] }}</a>
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
        <x-card :padded="true" class="mb-2" data-role="attention">
            <h2 class="text-section-heading mb-1">What needs attention</h2>
            @if(count($attention) === 0)
                <p class="text-caption mb-0" data-role="attention-none">Nothing stands out right now.</p>
            @else
                <ul class="list-unstyled mb-0">
                    @foreach($attention as $item)
                        <li class="mb-1" data-role="attention-item" data-provider="{{ $item['provider'] }}">
                            <x-badge variant="neutral" data-role="attention-provider">{{ $item['provider'] === 'meta' ? 'Meta' : 'Google' }}</x-badge>
                            <strong>{{ $item['title'] }}</strong>
                            @foreach($item['evidence_lines'] as $line)
                                <span class="d-block text-caption">{{ $line }}</span>
                            @endforeach
                            @if($item['url'])
                                <a href="{{ $item['url'] }}" class="text-caption">{{ $item['action_label'] }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endif
@endsection
