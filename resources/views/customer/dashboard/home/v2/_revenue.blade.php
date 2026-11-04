{{--
    Revenue pipeline — real open opportunities with their true state and ONE next step each (HomeRevenueReader).
    Hidden when there is nothing open or the actor cannot open CRM. Never shows an estimate, a score or a guessed outcome.
--}}
@if(($revenue ?? null) !== null)
<section class="hv2-section" aria-labelledby="hv2-revenue-title" data-role="revenue-pipeline">
    <h2 class="hv2-label" id="hv2-revenue-title">Revenue pipeline · {{ $revenue['openCount'] }} open</h2>
    <div class="hv2-card">
        <ul class="hv2-list" data-role="revenue-rows">
            @foreach($revenue['rows'] as $row)
                <li data-role="revenue-row" data-state="{{ $row['state'] }}">
                    <strong>{{ $row['title'] }}</strong>
                    <span class="hv2-muted"> — {{ $row['stateLabel'] }}</span>
                    @if($row['url'] !== null)
                        <a href="{{ $row['url'] }}" data-role="revenue-next-step">{{ $row['nextStep'] }} <span aria-hidden="true">→</span></a>
                    @else
                        <span class="hv2-muted">{{ $row['nextStep'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
        <button type="button" class="btn btn-outline-secondary hv2-btn mt-1" data-role="revenue-ask" data-ask-prompt="Which deals need me right now?"><span class="hv2-spark" aria-hidden="true">✦</span> Ask MotionGrove about these deals</button>
        @if(($revenue['boardUrl'] ?? null) !== null && $revenue['openCount'] > count($revenue['rows']))
            <a class="hv2-muted" href="{{ $revenue['boardUrl'] }}">See all {{ $revenue['openCount'] }}</a>
        @endif
    </div>
</section>
@endif
