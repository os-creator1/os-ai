{{--
    Meta Ads Module V1 contract 24 §12 — Recommendations.

    A READ-ONLY presentation of deterministic facts computed from your cached
    Meta Ads data (no AI). Each card: the observation, the evidence, the
    factual basis and one link to the page that owns it. A "strong performer"
    is a positive card with no action.

    There is deliberately NO dismiss / snooze / apply here: recommendation
    lifecycle belongs to the Opportunity Engine, so nothing is stored by this
    page. Calm tone: neutral / amber / green, never red. Names inside the
    wording are customer data and are escaped.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Meta recommendations')

@php
    use App\Library\MetaAds\MetaAdsDisplay as D;

    $ready = $metaState === 'ready' && $period !== null;
    $toneClass = ['success' => 'border-success', 'warning' => 'border-warning', 'neutral' => 'border-secondary'];
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Meta Ads', 'subtitle' => 'Things worth a look, based on your Meta Ads results.', 'provider' => 'meta'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads.meta._empty-state')
    @else
        @include('customer.business.ads.meta._data-period', ['periodRoute' => 'recommendations.index'])

        @if($resultLabel === null)
            <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="result-type-unset">
                Recommendations about results and cost per result need a result type.
                <a href="{{ route('customer.workspaces.businesses.ads.meta.settings', [$workspaceUid, $businessUid]) }}">Choose which Meta result you count in Settings.</a>
            </x-alert>
        @endif

        @php
            $actionCards = array_values(array_filter($cards, fn ($c) => $c['tone'] !== 'success'));
            $goingWell = array_values(array_filter($cards, fn ($c) => $c['tone'] === 'success'));
            $currency = $currency ?? null;
        @endphp
        <style>
            .rec-label { font-size: .7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; margin: 1.25rem 0 .5rem; }
            .rec-card { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 15rem); gap: 1rem; }
            @media (max-width: 767px) { .rec-card { grid-template-columns: minmax(0, 1fr); } }
            .rec-meter { height: .5rem; border-radius: 999px; background: rgba(120,113,108,.18); position: relative; overflow: visible; }
            .rec-meter > span { display: block; height: 100%; border-radius: 999px; background: #c98f2c; }
            .rec-meter > i { position: absolute; top: -.2rem; bottom: -.2rem; width: 2px; background: currentColor; }
            .rec-stat { border: 1px solid rgba(120,113,108,.2); border-radius: .5rem; padding: .6rem .8rem; margin-bottom: .5rem; }
            .rec-stat small { display: block; opacity: .7; }
            .rec-stat strong { font-size: 1.25rem; }
        </style>

        @if(count($cards) === 0)
            <x-card :padded="true" data-role="no-recommendations">
                <x-empty-state icon="lightbulb" title="Nothing to flag right now"
                               description="We did not find anything worth a look in the data we have. Recommendations appear when there is enough spend and results data to say something useful, so a quiet account or a short history shows nothing here." />
            </x-card>
        @else
            <div data-role="recommendation-list">
                <h2 class="rec-label text-muted" data-role="worth-acting-on">Worth acting on &middot; {{ count($actionCards) }}</h2>
                @if(count($actionCards) === 0)
                    <p class="text-caption text-muted" data-role="nothing-to-act-on">Nothing needs action right now.</p>
                @endif
                @foreach($actionCards as $card)
                    @php $pacing = $card['pacing'] ?? null; @endphp
                    <x-card :padded="true" class="mb-2 border-start border-3 {{ $toneClass[$card['tone']] ?? 'border-secondary' }}" data-role="recommendation" data-type="{{ $card['type'] }}" data-tone="{{ $card['tone'] }}">
                        <div class="{{ $pacing ? 'rec-card' : '' }}">
                            <div>
                                <h3 class="text-section-heading mb-1" data-role="recommendation-title">{{ $card['title'] }}</h3>
                                <ul class="mb-1 ps-2" data-role="recommendation-evidence">
                                    @foreach($card['evidence_lines'] as $line)
                                        <li>{{ $line }}</li>
                                    @endforeach
                                </ul>
                                @if($pacing && $pacing['spend_proportion'] !== null && $pacing['elapsed_proportion'] !== null)
                                    @php
                                        $usedPct = max(0, (float) $pacing['spend_proportion'] * 100);   // real utilisation, may exceed 100
                                        $used = min(100, $usedPct);                                      // bar width only
                                        $elapsed = max(0, min(100, (float) $pacing['elapsed_proportion'] * 100));
                                    @endphp
                                    <div class="rec-meter mb-1" role="img" aria-label="{{ round($usedPct) }}% of the monthly budget used, {{ round($elapsed) }}% of the month gone" data-role="pacing-meter">
                                        <span style="width: {{ $used }}%"></span><i style="left: {{ $elapsed }}%"></i>
                                    </div>
                                    <div class="d-flex justify-content-between text-caption text-muted mb-1"><span>{{ round($usedPct) }}% of budget used</span><span>Today: {{ round($elapsed) }}% of month</span></div>
                                @endif
                                <p class="text-caption text-muted mb-1" data-role="recommendation-basis">{{ $card['basis_line'] }}</p>
                                @if($card['action_url'])
                                    <a href="{{ $card['action_url'] }}" class="btn btn-sm btn-primary" data-role="recommendation-action">{{ $card['action_label'] }}</a>
                                @endif
                            </div>
                            @if($pacing)
                                <div data-role="pacing-stats">
                                    <div class="rec-stat"><small>Spent this month</small><strong>{{ D::money($pacing['spent_micros'], $currency) }}</strong></div>
                                    <div class="rec-stat"><small>Monthly budget</small><strong>{{ D::money($pacing['target_micros'], $currency) }}</strong></div>
                                    <div class="rec-stat"><small>Projected month end{{ $pacing['low_confidence'] ? ' (early estimate)' : '' }}</small><strong>{{ D::money($pacing['projected_micros'], $currency) }}</strong></div>
                                </div>
                            @endif
                        </div>
                    </x-card>
                @endforeach

                @if(count($goingWell) > 0)
                    <h2 class="rec-label text-success" data-role="going-well">Going well &middot; {{ count($goingWell) }}</h2>
                    @foreach($goingWell as $card)
                        <x-card :padded="true" class="mb-2 border-start border-3 border-success" data-role="recommendation" data-type="{{ $card['type'] }}" data-tone="{{ $card['tone'] }}">
                            <h3 class="text-section-heading mb-1" data-role="recommendation-title">{{ $card['title'] }}</h3>
                            <ul class="mb-1 ps-2" data-role="recommendation-evidence">
                                @foreach($card['evidence_lines'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                            <p class="text-caption text-muted mb-0" data-role="recommendation-basis">{{ $card['basis_line'] }}</p>
                        </x-card>
                    @endforeach
                @endif
            </div>
        @endif

        <p class="text-caption text-muted" data-role="recommendations-note">
            These are prompts, not changes: nothing is paused or edited from this page.
        </p>
    @endif
@endsection
