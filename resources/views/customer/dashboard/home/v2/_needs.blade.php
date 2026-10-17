{{--
    Needs you — the first thing the customer sees, from the existing attention / Next Best Move engine (decided in HomeV2Presenter).

    waiting      "7 customers need a reply": Reply now goes straight to the filtered list of exactly those customers; the AI
                 button asks the existing assistant to draft replies for review (nothing is ever sent without the customer).
    move         the highest-priority other thing, with its one action
    billing      the billing exception, with its one fix
    caught_up    a small calm card — only when every source was read and nothing needs the customer
    unavailable  a source could not be read: says so and never claims all-clear

    Detail is on demand ("Why this?", "N more"), never permanently expanded.
--}}
@if($needs['kind'] !== 'billing_strip')
<section class="hv2-section" aria-labelledby="hv2-needs-title" data-band="next_best_move"@if($needs['failedMove'] ?? false) data-band-state="failed"@endif data-role="needs-you" data-kind="{{ $needs['kind'] }}">
    <h2 class="hv2-label" id="hv2-needs-title"><span class="hv2-num" aria-hidden="true">1</span>Needs you</h2>

    @if($needs['kind'] === 'caught_up')
        <div class="hv2-card hv2-quiet" data-role="caught-up">
            <span class="hv2-check" aria-hidden="true">✓</span>
            <strong>{{ $needs['headline'] }}</strong>
        </div>
    @elseif($needs['kind'] === 'unavailable')
        <div class="hv2-card hv2-quiet hv2-quiet--warn" data-role="needs-unavailable">
            <span class="hv2-check hv2-check--warn" aria-hidden="true">!</span>
            <div><strong>{{ $needs['headline'] }}</strong><p class="hv2-muted mb-0">{{ $needs['sub'] }}</p></div>
        </div>
    @else
        <div class="hv2-card hv2-needs" data-role="next-best-move" data-needs-card data-move-kind="{{ $needs['moveKind'] ?? 'attention' }}" data-move="{{ $needs['moveKey'] ?? 'attention' }}" data-status="{{ $needs['moveStatus'] ?? 'ready' }}"@if(($needs['severity'] ?? null) !== null) data-severity="{{ $needs['severity'] }}"@endif>
            <span class="hv2-tile" aria-hidden="true" data-role="needs-count">{{ $needs['tile'] }}</span>
            <div class="hv2-needs__text">
                <h3 class="hv2-needs__title" data-role="next-best-move-headline">{{ $needs['headline'] }}</h3>
                @if(($needs['severityWord'] ?? null) !== null)<span class="hv2-sev" data-role="next-best-move-severity">{{ $needs['severityWord'] }}</span>@endif
                @if($needs['sub'])<p class="hv2-muted mb-0" data-role="needs-sub">{{ $needs['sub'] }}</p>@endif
            </div>
            <div class="hv2-needs__actions">
                @if($needs['primary'] !== null)
                    <a class="btn btn-primary hv2-btn" href="{{ $needs['primary']['url'] }}" data-role="next-best-move-action">{{ $needs['primary']['label'] }} <span aria-hidden="true">→</span></a>
                @endif
                @if($needs['ai'] !== null)
                    <button type="button" class="btn btn-outline-secondary hv2-btn" data-role="needs-ai" data-ask-prompt="{{ $needs['ai']['prompt'] }}"><span class="hv2-spark" aria-hidden="true">✦</span> Draft replies to review</button>
                @endif
            </div>
        </div>

        @if($needs['why'] !== [] || $needs['others'] !== [] || ($needs['recommendations'] ?? null) !== null)
            <details class="hv2-details" data-role="needs-details">
                <summary>{{ ($needs['others'] !== [] ? count($needs['others']) . ' more · ' : '') . ($needs['why'] !== [] ? 'Why this?' : 'Details') }}</summary>
                @if($needs['why'] !== [])
                    <ul class="hv2-list" data-role="next-best-move-why">
                        @foreach($needs['why'] as $line)<li data-role="next-best-move-reason">{{ $line }}</li>@endforeach
                    </ul>
                @endif
                @if($needs['recommendations'] ?? null)
                    <p class="hv2-muted mb-0" data-role="next-best-move-all"><a href="{{ $needs['recommendations']['url'] }}" data-role="next-best-move-all-link">See all recommendations ({{ $needs['recommendations']['label'] }})</a></p>
                @endif
                @if($needs['others'] !== [])
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
                @endif
            </details>
        @endif
    @endif
</section>
@endif
