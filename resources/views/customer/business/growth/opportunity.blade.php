{{--
    Growth Center — one Opportunity: why it matters, the evidence, the records
    behind it, what to do, a cautious expectation, and its history.
    Wording is fixed rule copy; numbers are the stored closed evidence.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Growth Center — ' . $card['title'])

@section('page-style')
    @include('customer.business.growth._styles')
@endsection

@php
    $e = $card['evidence'];
    $isOpen = $card['state'] === 'open';
    $growthRoute = fn (string $name) => route('customer.workspaces.businesses.growth.' . $name, [$workspaceUid, $businessUid, $card['uid']]);
    $transitionLabel = fn ($t) => match (true) {
        $t->reason_code === 'missing_from_successful_run' => 'No longer detected',
        $t->reason_code === 'confirmed_in_successful_run' => 'Detected again',
        $t->reason_code === 'recurrence_detected' => 'Came back after being resolved',
        $t->reason_code === 'dismiss_cooldown_elapsed' => 'Came back after the dismissal period',
        $t->reason_code === 'customer_dismissed' => $t->safe_note ?: 'Dismissed',
        str_contains($t->reason_code, 'snooz') => 'Snoozed',
        str_contains($t->reason_code, 'reopen') => 'Reopened',
        default => ucfirst(str_replace('_', ' ', $t->reason_code)),
    };
@endphp

@section('content')
    @include('customer.business.growth._header', ['tab' => 'opportunities'])

    <div class="gc">
        <p class="mb-1"><a class="gc-link" href="{{ route('customer.workspaces.businesses.growth.opportunities.index', [$workspaceUid, $businessUid]) }}" data-role="back">← All opportunities</a></p>

        <div class="gc-detail" data-role="opportunity-detail" data-rule="{{ $card['rule_key'] }}">
            <div class="gc-detail-main">
                <section class="gc-panel">
                    <div class="gc-opp-meta mb-1">
                        <span class="gc-pill impact-{{ strtolower($card['impact']) }}" data-role="impact">{{ strtoupper($card['impact']) }} IMPACT</span>
                        <span>{{ $card['category_label'] }}</span>
                        @if($card['location_name'])<span data-role="location"><x-ds-icon name="map-pin" size="12" /> {{ $card['location_name'] }}</span>@endif
                        <span>{{ $card['age_label'] }}</span>
                        <span class="gc-pill @if($card['state'] === 'resolved') is-ok @endif" data-role="state">{{ $card['state_label'] }}</span>
                    </div>
                    <h2 style="all: unset; display:block; font-size:1.3rem; font-weight:650; line-height:1.3;" data-role="headline">{{ $card['headline'] }}</h2>
                    <p class="gc-empty-line mt-1">{{ $card['title'] }}</p>
                </section>

                <section class="gc-panel" data-role="why-matters">
                    <h2>Why this matters</h2>
                    <p class="mb-0">{{ $card['why'] }}</p>
                </section>

                <section class="gc-panel" data-role="evidence-panel">
                    <h2>Evidence</h2>
                    <p class="mb-1">{{ $card['evidence_summary'] }}</p>
                    <ul class="gc-facts">
                        @isset($e['count'])<li>Records: <strong data-role="evidence-count">{{ $e['count'] }}</strong></li>@endisset
                        @isset($e['value'])<li>Value: <strong data-role="evidence-value">{{ $e['value'] }}</strong></li>@endisset
                        @isset($e['oldest_hours'])<li>Longest wait: <strong>{{ $e['oldest_hours'] }} hours</strong></li>@endisset
                        @isset($e['threshold_hours'])<li>Flagged after: <strong>{{ $e['threshold_hours'] }} hours</strong></li>@endisset
                        @isset($e['threshold_days'])<li>Flagged after: <strong>{{ $e['threshold_days'] }} days</strong></li>@endisset
                        @isset($e['open_minutes'])<li>Open time this week: <strong>{{ round($e['open_minutes'] / 60, 1) }} hours</strong></li>@endisset
                        @isset($e['critical'])<li>Critical: <strong>{{ $e['critical'] }}</strong></li>@endisset
                        @isset($e['warning'])<li>Warnings: <strong>{{ $e['warning'] }}</strong></li>@endisset
                        @if(! empty($e['phrases']))<li>Keywords: <strong>{{ implode(', ', $e['phrases']) }}</strong></li>@endif
                        <li data-role="confidence">{{ $card['confidence'] }}</li>
                    </ul>
                    @if($card['evidence_retrieved_at'])
                        <p class="gc-note">From your own account data, read {{ $card['evidence_retrieved_at']->diffForHumans() }}. Nothing here is estimated.</p>
                    @endif
                </section>

                @if($records !== [])
                    <section class="gc-panel" data-role="affected-records">
                        <h2>Affected records</h2>
                        <ul class="gc-records">
                            @foreach($records as $r)
                                <li>
                                    @if($r['url'])<a href="{{ $r['url'] }}">{{ $r['label'] }}</a>@else<span>{{ $r['label'] }}</span>@endif
                                    @if($r['detail'])<span class="gc-flat">{{ $r['detail'] }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                        @if(($e['count'] ?? 0) > count($records))
                            <p class="gc-note">Showing {{ count($records) }} of {{ $e['count'] }}.</p>
                        @endif
                    </section>
                @endif

                <section class="gc-panel" data-role="expected-result">
                    <h2>What to expect</h2>
                    <p class="mb-0">{{ $card['expected'] }}</p>
                </section>

                @if($history->isNotEmpty())
                    <section class="gc-panel" data-role="history">
                        <h2>History</h2>
                        <ul class="gc-timeline">
                            @foreach($history as $t)
                                <li>{{ $transitionLabel($t) }} <small>· {{ $t->created_at->diffForHumans() }}</small></li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>

            <aside class="gc-panel" data-role="owner-actions">
                <h2>What to do</h2>
                @if($isOpen || $card['state'] === 'snoozed')
                    @if($card['action_url'])
                        <x-button :href="$growthRoute('opportunities.go')" variant="primary" icon="arrow-right" class="w-100 justify-content-center mb-1" data-role="primary-action">{{ $card['action_label'] }}</x-button>
                        <p class="gc-note mt-0 mb-1">This opens the right screen. Nothing is changed or sent until you do it there.</p>
                    @endif
                    @if($secondary)
                        <x-button :href="$secondary['url']" variant="secondary" class="w-100 justify-content-center mb-1" data-role="secondary-action">{{ $secondary['label'] }}</x-button>
                    @endif
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        @include('customer.business.growth._menus', ['card' => $card])
                    </div>
                @elseif($card['state'] === 'dismissed')
                    <form method="POST" action="{{ $growthRoute('opportunities.reopen') }}">@csrf
                        <x-button type="submit" variant="secondary" class="w-100 justify-content-center" data-role="reopen">Reopen</x-button>
                    </form>
                @elseif($card['state'] === 'resolved')
                    <p class="gc-empty-line" data-role="resolved-note">This is no longer a problem. We confirmed that when we last checked{{ $card['resolved_at'] ? ' (' . $card['resolved_at']->diffForHumans() . ')' : '' }}.</p>
                @endif

                <p class="gc-note">Resolved means the problem was no longer found when we re-checked — not that a button was pressed.</p>
            </aside>
        </div>
    </div>
@endsection
