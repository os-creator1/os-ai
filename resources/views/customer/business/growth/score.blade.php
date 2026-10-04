{{--
    Growth Center — Score. "Why this score?": the whole formula in plain words,
    the nine categories, and exactly which checks fed each one. No AI calculates
    any of it. A category that could not be judged says so; it is never shown
    as 0 or 100.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Growth Center — Score')

@section('page-style')
    @include('customer.business.growth._styles')
@endsection

@php
    $statusText = [
        'passing' => 'Looking good',
        'finding' => 'Needs attention',
        'not_applicable' => 'Not available for this business',
        'insufficient' => 'Not enough data',
    ];
@endphp

@section('content')
    @include('customer.business.growth._header')

    <div class="gc">
        @if(! $canSeeScore)
            <div class="gc-panel" data-role="score-restricted">
                <p class="gc-section-title">The Growth Score covers the whole business.</p>
                <p class="gc-empty-line">You can see the opportunities for the locations you manage in the Opportunities tab.</p>
            </div>
        @elseif($score === null || $score->overall_score === null)
            <div class="gc-panel" data-role="score-none">
                <p class="gc-section-title">{{ $score === null ? 'No score yet.' : 'Not enough data yet.' }}</p>
                <p class="gc-empty-line">A score needs real activity to measure — leads, conversations, bookings, a published website. It appears here as soon as there is enough, and it never guesses.</p>
            </div>
        @else
            <div class="gc-hero">
                <section class="gc-panel">
                    <h2 class="gc-panel-title">Growth Score</h2>
                    <div class="gc-score">
                        @include('customer.business.growth._ring', ['value' => (int) $score->overall_score])
                        <div class="gc-score-copy">
                            <p class="gc-score-based" data-role="score-based-on">Based on {{ $score->scored_category_count }} of {{ $score->total_category_count }} categories</p>
                            @if($movement)
                                <p class="gc-score-move {{ $movement['delta'] > 0 ? 'gc-up' : ($movement['delta'] < 0 ? 'gc-down' : 'gc-flat') }}" data-role="score-sentence">{{ $movement['sentence'] }}</p>
                            @endif
                            <p class="gc-note" style="margin:.4rem 0 0">Score version {{ $score->algorithm_version }} · checked {{ $score->computed_at->diffForHumans() }}</p>
                        </div>
                    </div>
                    @if($movement && $movement['contributors'])
                        <ul class="gc-bullets mt-1" data-role="score-contributors">
                            @foreach($movement['contributors'] as $c)
                                <li><x-ds-icon :name="$c['delta'] > 0 ? 'arrow-up-right' : 'arrow-down-right'" size="16" class="{{ $c['delta'] > 0 ? 'gc-up' : 'gc-down' }}" /><span>{{ $c['delta'] > 0 ? '+' : '' }}{{ $c['delta'] }} {{ $c['label'] }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="gc-panel" data-role="formula">
                    <h2 class="gc-panel-title">How the score is worked out</h2>
                    <div class="gc-formula">
                        <p>Each check looks at real data from your account. A check scores <code>100</code> when nothing is wrong. When it finds a problem it loses points in proportion to how much that problem matters and how sure we are of it.</p>
                        <p>A category's score is the weighted average of its checks. The overall score is the plain average of the categories that had enough data.</p>
                        <p class="mb-0">Checks that could not be judged — the feature is not on your plan, is not connected, or there is too little data — are <strong>left out</strong>. They are never counted as 0 or as 100. No AI is involved in the score.</p>
                    </div>
                </section>
            </div>

            <section class="gc-section" data-role="why-score">
                <h2 class="gc-section-title mb-1">Why this score?</h2>
                <div class="gc-why">
                    @foreach($categories as $cat)
                        <div class="gc-why-cat" data-category="{{ $cat['key'] }}">
                            <div class="gc-cat-head">
                                <p class="gc-cat-name">{{ $cat['label'] }}</p>
                                @if($cat['score'] !== null)
                                    <p class="gc-cat-score" data-role="category-score">{{ $cat['score'] }}</p>
                                @else
                                    <span class="gc-cat-na" data-role="category-na">Not enough data</span>
                                @endif
                            </div>
                            @if($cat['rules'] !== [])
                                <ul class="gc-why-rules">
                                    @foreach($cat['rules'] as $rule)
                                        <li data-rule-status="{{ $rule['status'] }}">
                                            <span>{{ $rule['title'] }}</span>
                                            <span class="@if($rule['status'] === 'finding') gc-down @elseif($rule['status'] === 'passing') gc-up @else gc-flat @endif">
                                                {{ $statusText[$rule['status']] ?? $rule['status'] }}@if($rule['health'] !== null && $rule['status'] === 'finding') · {{ $rule['health'] }}%@endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="gc-cat-foot mt-1">Nothing in this category can be measured for your business yet.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="gc-panel" data-role="thresholds">
            <h2 class="gc-panel-title">The thresholds the checks use</h2>
            <p class="gc-empty-line mb-1">These are the same for every business and are set in one place.</p>
            <ul class="gc-facts">
                <li>New lead unanswered after <strong>{{ $thresholds['unanswered_lead_hours'] }} hours</strong></li>
                <li>Deal quiet after <strong>{{ $thresholds['stale_deal_days'] }} days</strong></li>
                <li>Customer message waiting after <strong>{{ $thresholds['conversation_awaiting_hours'] }} hours</strong></li>
                <li>Proposal unsigned after <strong>{{ $thresholds['proposal_unsigned_days'] }} days</strong></li>
                <li>Review request gap <strong>{{ $thresholds['review_request_lookback_days'] }} days</strong></li>
            </ul>
        </section>
    </div>
@endsection
