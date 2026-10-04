{{--
    Google Ads Module V1 contract §12 — Recommendations.

    A READ-ONLY presentation of deterministic facts computed from your cached
    Google Ads data (no AI). Each card: the problem, the evidence, the factual
    basis and a link to the page that owns the action.

    There is deliberately NO dismiss / snooze / apply here: recommendation
    lifecycle belongs to the Opportunity Engine (RFC-002, contract D7), so
    nothing is stored by this page. Calm tone: neutral / amber / green
    information styling, never red alert styling for ordinary optimisation.
    Names inside the wording are customer data and are escaped.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Ads recommendations')

@php
    use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;

    $ready = $adsState === 'ready' && $period !== null;
    $periodNames = [
        GoogleAdsPeriod::LAST_7 => 'the last 7 days',
        GoogleAdsPeriod::LAST_30 => 'the last 30 days',
        GoogleAdsPeriod::THIS_MONTH => 'this month',
        GoogleAdsPeriod::PREVIOUS_MONTH => 'the previous month',
    ];
    $toneClass = ['success' => 'border-success', 'warning' => 'border-warning', 'neutral' => 'border-secondary'];
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Recommendations', 'subtitle' => 'Things worth a look, based on your Google Ads results.'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads._empty-state')
    @else
        @include('customer.business.ads._period-selector', ['periodRoute' => 'recommendations.index'])

        @if(count($cards) === 0)
            <x-card :padded="true" data-role="no-recommendations">
                <x-empty-state icon="lightbulb" title="Nothing to flag right now"
                               description="We did not find anything worth a look in the data we have. Recommendations appear when there is enough spend and conversion data to say something useful, so a quiet account or a short history shows nothing here." />
            </x-card>
        @else
            <div data-role="recommendation-list">
                @foreach($cards as $card)
                    <x-card :padded="true" class="mb-2 border-start border-3 {{ $toneClass[$card['tone']] ?? 'border-secondary' }}" data-role="recommendation" data-type="{{ $card['type'] }}" data-tone="{{ $card['tone'] }}">
                        <h2 class="text-section-heading mb-1" data-role="recommendation-title">{{ $card['title'] }}</h2>
                        <ul class="mb-1 ps-2" data-role="recommendation-evidence">
                            @foreach($card['evidence_lines'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                        <p class="text-caption text-muted mb-1" data-role="recommendation-basis">
                            Based on your cached Google Ads data for {{ $card['basis_window'] ?? ($periodNames[$period->key] ?? 'this period') }}; deterministic rule.
                        </p>
                        @if($card['action_url'])
                            <a href="{{ $card['action_url'] }}" class="btn btn-sm btn-outline-secondary" data-role="recommendation-action">{{ $card['action_label'] }}</a>
                        @endif
                    </x-card>
                @endforeach
            </div>
        @endif

        <p class="text-caption text-muted" data-role="recommendations-note">
            These are prompts, not changes: nothing is paused, excluded or edited from this page.
        </p>
    @endif
@endsection
