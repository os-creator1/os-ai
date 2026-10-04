{{--
    Growth Center — Overview. Answers, at a glance: how is the business doing
    (score), what is the biggest problem and why (the top cards), what should I
    do (the primary action on each), and can I fix it here (a link into the
    owning module; the Growth Center itself writes nothing but snooze/dismiss).

    $score / $movement are null for a Location-restricted viewer (the score is a
    Business-wide aggregate) and before the first evaluation.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Growth Center')

@section('page-style')
    @include('customer.business.growth._styles')
@endsection

@php
    use App\Library\Growth\GrowthMoney;
    $hasOpen = $summary['open'] > 0;
    $evaluated = $score !== null || $summary['open'] > 0 || $summary['resolved_this_month'] > 0;
    $sparkValues = array_values($scoreHistory);
    $moneyFmt = fn (?array $m) => $m === null ? null : GrowthMoney::format($m['value_minor'], $m['currency']);
@endphp

@section('content')
    @include('customer.business.growth._header')

    <div class="gc">
        {{-- ── Hero: score + the numbers that matter ───────────────────── --}}
        <div class="gc-hero">
            <section class="gc-panel" data-role="score-panel" aria-labelledby="gc-score-title">
                <h2 class="gc-panel-title" id="gc-score-title">Growth Score</h2>
                @if($score !== null && $score->overall_score !== null)
                    <div class="gc-score">
                        @include('customer.business.growth._ring', ['value' => (int) $score->overall_score])
                        <div class="gc-score-copy">
                            <p class="gc-score-based" data-role="score-based-on">Based on {{ $score->scored_category_count }} of {{ $score->total_category_count }} categories</p>
                            @if($movement !== null)
                                <p class="gc-score-move {{ $movement['delta'] > 0 ? 'gc-up' : ($movement['delta'] < 0 ? 'gc-down' : 'gc-flat') }}" data-role="score-change">
                                    <x-ds-icon :name="$movement['delta'] > 0 ? 'trending-up' : ($movement['delta'] < 0 ? 'trending-down' : 'minus')" size="16" />
                                    {{ $movement['delta'] > 0 ? '+' : '' }}{{ $movement['delta'] }} in {{ $movement['days'] }} days
                                </p>
                            @else
                                <p class="gc-score-move gc-flat" data-role="score-change-none">No earlier score to compare yet.</p>
                            @endif
                            <a class="gc-link d-block mt-50" href="{{ route('customer.workspaces.businesses.growth.score', [$workspaceUid, $businessUid]) }}" data-role="why-this-score">Why this score?</a>
                        </div>
                    </div>
                    @if(count($sparkValues) >= 3)
                        @php
                            $min = min($sparkValues); $max = max($sparkValues); $range = max(1, $max - $min);
                            $count = count($sparkValues);
                            $points = collect($sparkValues)->map(fn ($v, $i) => round(($i / ($count - 1)) * 200, 1) . ',' . round(34 - (($v - $min) / $range) * 30 - 2, 1))->implode(' ');
                        @endphp
                        <svg class="gc-spark" viewBox="0 0 200 36" preserveAspectRatio="none" role="img" aria-label="Growth Score over time" data-role="score-sparkline">
                            <polyline points="{{ $points }}"></polyline>
                        </svg>
                    @endif
                @elseif($score !== null)
                    <p class="gc-empty-line" data-role="score-not-enough">Not enough data yet to give a score. As you use more of Business OS, it will appear here.</p>
                @elseif(! $canSeeScore)
                    <p class="gc-empty-line" data-role="score-restricted">The Growth Score covers the whole business. You are seeing the opportunities for the locations you manage.</p>
                @else
                    <p class="gc-empty-line" data-role="score-none">Your first score appears after the first check.</p>
                @endif
            </section>

            <div class="gc-tiles" data-role="summary-tiles">
                <div class="gc-panel gc-tile" data-tile="open">
                    <p class="gc-tile-label">Open opportunities</p>
                    <p class="gc-tile-value" data-role="open-count">{{ $summary['open'] }}</p>
                    <p class="gc-tile-note">{{ $summary['snoozed'] > 0 ? $summary['snoozed'] . ' snoozed' : 'Things worth your attention' }}</p>
                </div>
                <div class="gc-panel gc-tile @if($summary['high_impact'] > 0) is-alert @endif" data-tile="high-impact">
                    <p class="gc-tile-label">High impact</p>
                    <p class="gc-tile-value" data-role="high-impact-count">{{ $summary['high_impact'] }}</p>
                    <p class="gc-tile-note">Do these first</p>
                </div>
                @if($summary['pipeline'] !== null)
                    <div class="gc-panel gc-tile" data-tile="pipeline">
                        <p class="gc-tile-label">Pipeline waiting on you</p>
                        <p class="gc-tile-value" data-role="pipeline-value">{{ $moneyFmt($summary['pipeline']) }}</p>
                        <p class="gc-tile-note">Open deal value in the list below</p>
                    </div>
                @endif
                @if($summary['receivables'] !== null)
                    <div class="gc-panel gc-tile" data-tile="receivables">
                        <p class="gc-tile-label">Payments to collect</p>
                        <p class="gc-tile-value" data-role="receivables-value">{{ $moneyFmt($summary['receivables']) }}</p>
                        <p class="gc-tile-note">Unpaid balances you can follow up</p>
                    </div>
                @endif
                <div class="gc-panel gc-tile" data-tile="resolved">
                    <p class="gc-tile-label">Resolved this month</p>
                    <p class="gc-tile-value" data-role="resolved-count">{{ $summary['resolved_this_month'] }}</p>
                    <p class="gc-tile-note">No longer a problem</p>
                </div>
            </div>
        </div>

        {{-- ── What should I do today? ─────────────────────────────────── --}}
        <section class="gc-section" aria-labelledby="gc-today" data-role="today">
            <div class="gc-section-head">
                <div>
                    <h2 class="gc-section-title" id="gc-today">What should I do today?</h2>
                    <p class="gc-section-sub">Ranked by impact, how sure we are, and how easy it is to act on.</p>
                </div>
                @if($hasOpen)
                    <a class="gc-link" href="{{ route('customer.workspaces.businesses.growth.opportunities.index', [$workspaceUid, $businessUid]) }}" data-role="see-all">See all {{ $summary['open'] }}</a>
                @endif
            </div>

            @if($top !== [])
                <div class="gc-list" data-role="top-opportunities">
                    @foreach($top as $card)
                        @include('customer.business.growth._card', ['card' => $card, 'compact' => true])
                    @endforeach
                </div>
            @elseif($evaluated)
                <div class="gc-panel text-center" data-role="all-clear">
                    <x-ds-icon name="check-circle" size="28" />
                    <p class="gc-section-title mt-1">You're in good shape.</p>
                    <p class="gc-empty-line">Nothing needs your attention right now. We check again every day{{ $score !== null ? ' — last checked ' . $score->computed_at->diffForHumans() : '' }}.</p>
                </div>
            @else
                <div class="gc-panel" data-role="first-run">
                    <p class="gc-section-title">Connect or start using more of Business OS to unlock better recommendations.</p>
                    <p class="gc-empty-line">The Growth Center reads what is already in your account — it never asks you to enter data twice. The more you use, the more it can tell you.</p>
                    <div class="gc-welcome">
                        <div class="gc-welcome-card"><strong>Customers &amp; deals</strong>Add leads to your pipeline to see follow-up gaps.</div>
                        <div class="gc-welcome-card"><strong>Conversations</strong>Customer messages waiting for a reply show up here.</div>
                        <div class="gc-welcome-card"><strong>Calendar</strong>Booking types and open availability.</div>
                        <div class="gc-welcome-card"><strong>Website &amp; SEO</strong>Publish your site and track the keywords that matter.</div>
                        <div class="gc-welcome-card"><strong>Proposals &amp; payments</strong>Unsigned proposals and unpaid balances.</div>
                    </div>
                </div>
            @endif
        </section>

        {{-- ── Working / changed ───────────────────────────────────────── --}}
        @if($canSeeScore)
            <div class="gc-cols">
                <section class="gc-panel" data-role="whats-working">
                    <h2 class="gc-panel-title">What's working</h2>
                    @if($brief['positives'] !== [])
                        <ul class="gc-bullets">
                            @foreach($brief['positives'] as $line)
                                <li><x-ds-icon name="check-circle" size="16" class="gc-up" /> <span>{{ $line }}</span></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="gc-empty-line">Good news will show up here as your numbers settle.</p>
                    @endif
                </section>
                <section class="gc-panel" data-role="recent-changes">
                    <h2 class="gc-panel-title">Recent changes</h2>
                    @if($brief['changes'] !== [] || $movement !== null)
                        <ul class="gc-bullets">
                            @if($movement !== null)
                                <li><x-ds-icon :name="$movement['delta'] >= 0 ? 'trending-up' : 'trending-down'" size="16" class="{{ $movement['delta'] >= 0 ? 'gc-up' : 'gc-down' }}" /> <span>{{ $movement['sentence'] }}
                                    @foreach($movement['contributors'] as $c)<br><small class="gc-flat">{{ $c['delta'] > 0 ? '+' : '' }}{{ $c['delta'] }} {{ $c['label'] }}</small>@endforeach</span></li>
                            @endif
                            @foreach($brief['changes'] as $change)
                                <li><x-ds-icon :name="$change['kind'] === 'up' ? 'arrow-up-right' : 'arrow-down-right'" size="16" class="{{ $change['kind'] === 'up' ? 'gc-up' : 'gc-down' }}" /> <span>{{ $change['text'] }}</span></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="gc-empty-line">Changes are compared once there are a couple of weeks of history.</p>
                    @endif
                </section>
            </div>
        @endif

        {{-- ── Category breakdown ─────────────────────────────────────── --}}
        @if($canSeeScore)
            <section class="gc-section" aria-labelledby="gc-cats" data-role="categories">
                <div class="gc-section-head">
                    <div>
                        <h2 class="gc-section-title" id="gc-cats">How each part of the business is doing</h2>
                        <p class="gc-section-sub">A category only gets a score when there is enough real data behind it.</p>
                    </div>
                    <a class="gc-link" href="{{ route('customer.workspaces.businesses.growth.score', [$workspaceUid, $businessUid]) }}">How it is calculated</a>
                </div>
                <div class="gc-cats">
                    @foreach($categories as $cat)
                        @php $band = $cat['score'] === null ? '' : ($cat['score'] >= 75 ? 'is-good' : ($cat['score'] >= 50 ? 'is-fair' : 'is-low')); @endphp
                        <div class="gc-panel gc-cat" data-category="{{ $cat['key'] }}" data-scored="{{ $cat['score'] === null ? 'no' : 'yes' }}">
                            <div class="gc-cat-head">
                                <p class="gc-cat-name">{{ $cat['label'] }}</p>
                                @if($cat['score'] !== null)
                                    <p class="gc-cat-score" data-role="category-score">{{ $cat['score'] }}</p>
                                @else
                                    <span class="gc-cat-na" data-role="category-na">Not enough data</span>
                                @endif
                            </div>
                            <div class="gc-bar {{ $band }}"><span style="width: {{ $cat['score'] ?? 0 }}%"></span></div>
                            <p class="gc-cat-foot">
                                @if($cat['score'] !== null)
                                    {{ collect($cat['rules'])->whereNotNull('health')->count() }} {{ \Illuminate\Support\Str::plural('check', collect($cat['rules'])->whereNotNull('health')->count()) }}@if($cat['delta'] !== null && $cat['delta'] !== 0) · {{ $cat['delta'] > 0 ? '+' : '' }}{{ $cat['delta'] }} recently @endif
                                @else
                                    Not included in the overall score.
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
