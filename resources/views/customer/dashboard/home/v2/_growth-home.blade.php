{{--
    Growth Home — the Growth plan's presentation of the SAME Home model (HomeV2Presenter::growthHome). It computes nothing, queries
    nothing and selects nothing: the next move is Needs-you's own, the "What's next" rows are the Growth band's existing queue, progress
    is the band's own score. Anything unreadable is said to be unknown, never shown as a result. CRM/Core never renders this file.

    Order: Where you stand → Your next move → Your progress → MotionGrove is handling → Ask MotionGrove → What's next.
--}}
@php
    $g = $v2['growthHome'];
    $needs = $v2['needs'];
    $standCopy = match (true) {
        $g['growthFailed'] => "We couldn't read your Growth insights just now, so we can't say how things stand. Nothing is assumed.",
        $g['standKey'] === 'attention' => 'Something needs you before anything else.',
        $g['standKey'] === 'unavailable' => "Some of your data is temporarily unavailable, so we can't confirm everything is fine.",
        $g['standKey'] === 'clear' => 'Nothing urgent needs you right now.',
        default => 'Setup is still in progress.',
    };
@endphp
<div class="hv2-layout" data-role="growth-home">
    <div class="hv2-main">
        <section class="hv2-section" aria-labelledby="gh-stand-title" data-role="growth-stand" data-status="{{ $g['standKey'] }}">
            <h2 class="hv2-label" id="gh-stand-title">Where you stand</h2>
            <div class="hv2-card hv2-quiet{{ in_array($g['standKey'], ['unavailable'], true) || $g['growthFailed'] ? ' hv2-quiet--warn' : '' }}">
                <div><strong>{{ $g['standLabel'] }}</strong><p class="hv2-muted mb-0">{{ $standCopy }}</p></div>
            </div>
        </section>

        @if($needs['kind'] !== 'billing_strip')
        <section class="hv2-section" aria-labelledby="gh-next-title" data-band="next_best_move" data-role="growth-next" data-kind="{{ $needs['kind'] }}">
            <h2 class="hv2-label" id="gh-next-title">Your next move</h2>
            @if($needs['kind'] === 'caught_up')
                <div class="hv2-card hv2-quiet" data-role="caught-up"><span class="hv2-check" aria-hidden="true">✓</span><strong>{{ $needs['headline'] }}</strong></div>
            @elseif($needs['kind'] === 'unavailable')
                <div class="hv2-card hv2-quiet hv2-quiet--warn" data-role="needs-unavailable">
                    <span class="hv2-check hv2-check--warn" aria-hidden="true">!</span>
                    <div><strong>{{ $needs['headline'] }}</strong><p class="hv2-muted mb-0">{{ $needs['sub'] }}</p></div>
                </div>
            @else
                <div class="hv2-card hv2-needs" data-role="next-best-move" data-move="{{ $needs['moveKey'] ?? 'attention' }}">
                    @if($needs['kind'] === 'waiting')
                        <span class="hv2-tile" aria-hidden="true" data-role="needs-count">{{ $needs['tile'] }}</span>
                    @endif
                    <div class="hv2-needs__text">
                        <h3 class="hv2-needs__title" data-role="next-best-move-headline">{{ $needs['headline'] }}</h3>
                        @if($needs['sub'])<p class="hv2-muted mb-0" data-role="needs-sub">{{ $needs['sub'] }}</p>@endif
                    </div>
                    @if($needs['primary'] !== null)
                        <div class="hv2-needs__actions">
                            <a class="btn btn-primary hv2-btn" href="{{ $needs['primary']['url'] }}" data-role="next-best-move-action">{{ $needs['primary']['label'] }} <span aria-hidden="true">→</span></a>
                        </div>
                    @endif
                </div>
            @endif
            @if(($needs['others'] ?? []) !== [])
                <details class="hv2-details" data-role="needs-details">
                    <summary>{{ count($needs['others']) }} more to review</summary>
                    <ul class="hv2-list" data-band="needs_attention" data-role="attention-items">
                        @foreach($needs['others'] as $item)
                            <li data-role="attention-item" data-attention="{{ $item['key'] }}" data-kind="{{ $item['kind'] }}">
                                <span data-role="attention-text">{{ $item['text'] }}</span>
                                @if(($item['action']['url'] ?? null) !== null)
                                    <a href="{{ $item['action']['url'] }}"@if(($item['action']['drawerUrl'] ?? null) !== null) data-home-drawer="{{ $item['action']['drawerUrl'] }}" data-drawer-title="{{ $item['action']['label'] }}"@endif data-role="attention-action">{{ $item['action']['label'] }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>
        @endif

        <section class="hv2-section" aria-labelledby="gh-progress-title" data-role="growth-progress">
            <h2 class="hv2-label" id="gh-progress-title">Your progress</h2>
            @if($g['progress'] === null)
                <div class="hv2-card hv2-quiet" data-role="progress-unknown"><p class="hv2-muted mb-0">{{ $g['growthFailed'] ? "Progress is unavailable right now." : 'No verified progress yet. It appears here once MotionGrove has measured it.' }}</p></div>
            @else
                <div class="hv2-card" data-role="progress-verified">
                    @if($g['progress']['score'] !== null)
                        <p class="mb-0"><strong data-role="progress-score">Growth score {{ $g['progress']['score'] }}</strong>
                            @if($g['progress']['delta'] !== null && $g['progress']['days'] !== null)
                                <span class="hv2-muted" data-role="progress-delta">{{ $g['progress']['delta'] > 0 ? '▲ ' : ($g['progress']['delta'] < 0 ? '▼ ' : '') }}{{ $g['progress']['delta'] === 0 ? 'No change' : abs($g['progress']['delta']) }} in {{ $g['progress']['days'] }} days</span>
                            @endif
                        </p>
                    @endif
                    @if($g['progress']['positives'] !== [])
                        <ul class="hv2-list" data-role="progress-positives">@foreach($g['progress']['positives'] as $line)<li>{{ is_array($line) ? ($line['text'] ?? '') : $line }}</li>@endforeach</ul>
                    @endif
                    @if($g['progress']['url'] !== null)<a href="{{ $g['progress']['url'] }}" data-role="progress-link">See details</a>@endif
                </div>
            @endif
        </section>

        <section class="hv2-section" aria-labelledby="gh-handling-title" data-role="growth-handling">
            <h2 class="hv2-label" id="gh-handling-title">MotionGrove is handling</h2>
            <div class="hv2-card">
                @if($g['handlingFailed'])
                    <p class="hv2-muted mb-0" data-role="handling-unavailable">We couldn't load recent work just now.</p>
                @elseif($g['handling'] === [])
                    <p class="hv2-muted mb-0" data-role="handling-empty">Work MotionGrove is doing for you will appear here.</p>
                @else
                    <ul class="hv2-done" data-role="handling-items">
                        @foreach($g['handling'] as $row)
                            @php $word = ['approval' => 'Waiting for your approval', 'progress' => 'In progress', 'failed' => 'Needs attention', 'completed' => 'Done'][$row['status']] ?? ''; @endphp
                            <li data-role="handling-item" data-status="{{ $row['status'] }}">
                                @if($row['url'] !== null)<a href="{{ $row['url'] }}">{{ $row['text'] }}</a>@else<span>{{ $row['text'] }}</span>@endif
                                <span class="hv2-muted">· {{ $word }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        {{-- A failed automation read is still visible on Growth Home; never silently hide a failed proof of all-clear. --}}
        @if($dashboard->failed(\App\Library\Dashboard\DashboardSnapshot::BAND_AUTOMATIONS))
            @include('customer.dashboard.band-failed', ['band' => 'automations', 'title' => 'Automations'])
        @endif

        @include('customer.dashboard.home._ask', ['home' => $home, 'v2Chips' => $v2['chips']])

        @if($g['secondary'] !== [])
        <section class="hv2-section" aria-labelledby="gh-more-title" data-role="growth-whats-next">
            <h2 class="hv2-label" id="gh-more-title">What's next</h2>
            <ul class="hv2-card hv2-side-card hv2-suggest" data-role="whats-next-items">
                @foreach($g['secondary'] as $item)
                    <li data-role="whats-next-item" data-uid="{{ $item['uid'] }}">
                        <span>{{ $item['title'] }}</span>
                        @if($item['url'] !== null)<a href="{{ $item['url'] }}">View</a>@endif
                    </li>
                @endforeach
            </ul>
        </section>
        @endif
    </div>
</div>
