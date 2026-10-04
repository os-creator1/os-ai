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
    $ready = $metaState === 'ready' && $period !== null;
    $toneClass = ['success' => 'border-success', 'warning' => 'border-warning', 'neutral' => 'border-secondary'];
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Recommendations', 'subtitle' => 'Things worth a look, based on your Meta Ads results.', 'provider' => 'meta'])

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

        @if(count($cards) === 0)
            <x-card :padded="true" data-role="no-recommendations">
                <x-empty-state icon="lightbulb" title="Nothing to flag right now"
                               description="We did not find anything worth a look in the data we have. Recommendations appear when there is enough spend and results data to say something useful, so a quiet account or a short history shows nothing here." />
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
                        <p class="text-caption text-muted mb-1" data-role="recommendation-basis">{{ $card['basis_line'] }}</p>
                        @if($card['action_url'])
                            <a href="{{ $card['action_url'] }}" class="btn btn-sm btn-outline-secondary" data-role="recommendation-action">{{ $card['action_label'] }}</a>
                        @endif
                    </x-card>
                @endforeach
            </div>
        @endif

        <p class="text-caption text-muted" data-role="recommendations-note">
            These are prompts, not changes: nothing is paused or edited from this page.
        </p>
    @endif
@endsection
