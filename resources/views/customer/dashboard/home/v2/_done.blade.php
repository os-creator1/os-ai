{{--
    Done for you — only REAL completed events (a published website, a finished recommendation, an automation that ran), each once,
    with its date. Nothing is ever a placeholder; with nothing completed there is one calm line. "See activity" opens the website page when the work is the website, otherwise the existing
    results. A source that could not be read says so instead of claiming nothing happened.
--}}
<section class="hv2-section" aria-labelledby="hv2-done-title" data-band="handling" data-role="home-handling">
    <h2 class="hv2-label" id="hv2-done-title"><span class="hv2-num" aria-hidden="true">3</span>Done for you</h2>
    <div class="hv2-card hv2-side-card">
        <div class="hv2-side-card__head">
            <strong>Completed</strong>
            @if($done['url'] !== null && $done['rows'] !== [])<a href="{{ $done['url'] }}" data-role="handling-view-activity">See activity</a>@endif
        </div>

        @if($failed)
            <p class="hv2-muted mb-0" data-role="handling-unavailable">We couldn't load recent work just now.</p>
        @elseif($done['rows'] === [])
            <p class="hv2-muted mb-0" data-role="handling-empty">Work MotionGrove finishes for you will appear here.</p>
        @else
            <ul class="hv2-done" data-role="handling-items">
                @foreach($done['rows'] as $row)
                    @php
                        // Many website versions are one outcome on Home: the site is live, with when the latest version went out.
                        $isSite = ($row['key'] ?? '') === 'website_version_published';
                        $title = $isSite ? ($done['websiteTitle'] ?? 'Website updated') : $row['text'];
                    @endphp
                    <li data-role="recent-work-item"@if($isSite) data-outcome="website-live"@endif>
                        <span class="hv2-done__mark{{ $isSite ? ' hv2-done__mark--icon' : '' }}" aria-hidden="true">{{ $isSite ? '🌐' : '✓' }}</span>
                        <span>
                            @if($row['url'] !== null)<a href="{{ $row['url'] }}" data-role="recent-work-link">{{ $title }}</a>@else<span data-role="recent-work-text">{{ $title }}</span>@endif
                            @if($row['at'] !== null)<time class="hv2-muted" datetime="{{ $row['at']->toIso8601String() }}">{{ $isSite ? 'Latest version saved · ' : '' }}{{ $row['at']->format('M j') }}</time>@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
