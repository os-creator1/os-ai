{{--
    Home V2 §B — Your Next Best Move. Exactly ONE, chosen by
    NextBestMoveSelector (fixed order, no AI, no score of the Home's own).

    WHAT / WHY / IMPACT / ACTION / STATUS are each shown only when the source
    carries them: no impact claim without the registry's own "expected" text,
    no effort estimate (none is reliably available), and "Waiting for more
    evidence" when the engine itself reports limited data.
--}}
@php $move = $home['primary']; @endphp

<section class="h-100" aria-labelledby="home-move-heading" data-band="next_best_move"{!! $home['primaryFailed'] ? ' data-band-state="failed"' : '' !!} data-role="home-move-section">
    <div class="home-move p-2 h-100" data-role="next-best-move-card"
         @if($move !== null && $move['severity'] !== null) data-severity="{{ $move['severity']->value }}" @endif>
        <h2 class="home-eyebrow mb-50" id="home-move-heading">Your next best move</h2>

        @if($home['primaryFailed'])
            <p class="mb-0" data-role="home-move-unavailable">This section could not be loaded just now. We couldn't work out your next move — your other sections are unaffected, so please refresh in a moment.</p>
        @elseif($move === null)
            <div data-role="next-best-move-caught-up">
                <p class="home-move-headline mb-50">You're all caught up.</p>
                <p class="mb-0 text-muted" @if($home['growthShown']) data-role="growth-all-clear" @endif>@if($home['growthShown'])You're in good shape. Nothing needs your attention right now. @else Nothing needs doing right now. @endif MotionGrove will put the next worthwhile step here as soon as there is evidence for one.</p>
            </div>
        @else
            <div data-role="next-best-move" data-move-kind="{{ $move['kind'] }}" data-move="{{ $move['key'] }}" data-status="{{ $move['status'] }}">
                <div class="d-flex flex-wrap align-items-center gap-50 mb-50">
                    @if($move['severity'] !== null)
                        <x-badge :variant="$move['severity']->badgeVariant()" data-role="next-best-move-severity">{{ $move['severity']->word() }}</x-badge>
                    @endif
                    <span class="home-status" data-role="next-best-move-status">{{ $move['statusLabel'] }}</span>
                </div>

                <h2 class="home-move-headline mb-50" data-role="next-best-move-headline">{{ $move['headline'] }}</h2>

                @if($move['summary'])
                    <p class="mb-1" data-role="next-best-move-summary">{{ $move['summary'] }}</p>
                @elseif($move['why'] !== [])
                    <p class="mb-1" data-role="next-best-move-matters">{{ $move['why'][0] }}</p>
                @endif

                @if($move['evidence'] !== [])
                    <ul class="home-evidence mb-1" data-role="next-best-move-evidence">
                        @foreach($move['evidence'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                @endif

                @if($move['impact'])
                    <p class="mb-1" data-role="next-best-move-impact"><strong>What to expect:</strong> {{ $move['impact'] }}</p>
                @endif

                @if($move['needsMoreInfo'])
                    <p class="text-caption text-muted mb-1" data-role="next-best-move-needs-info">MotionGrove has limited data on this so far, so treat it as a lead to check rather than a conclusion.</p>
                @endif

                <div class="d-flex flex-wrap align-items-center gap-1">
                    @if($move['action'] !== null)
                        @if($move['action']['drawerUrl'] !== null)
                            <x-button :href="$move['action']['url']" data-role="next-best-move-action"
                                      data-home-drawer="{{ $move['action']['drawerUrl'] }}" data-drawer-title="{{ $move['action']['label'] }}">{{ $move['action']['label'] }}</x-button>
                        @elseif($move['action']['url'] !== null)
                            <x-button :href="$move['action']['url']" data-role="next-best-move-action">{{ $move['action']['label'] }}</x-button>
                        @endif
                    @endif
                    @if($move['detailUrl'] !== null)
                        <a href="{{ $move['detailUrl'] }}" class="text-caption" data-role="next-best-move-details">See the evidence</a>
                    @endif
                </div>

                @if($move['why'] !== [])
                    <details class="mt-1" data-role="next-best-move-why">
                        <summary class="text-caption">Why this?</summary>
                        <ul class="list-unstyled mb-0 mt-50">
                            @foreach($move['why'] as $line)
                                <li class="text-caption text-muted" data-role="next-best-move-reason">{{ $line }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                {{-- A cached AI note ATTACHED to this move: labelled, never a second recommendation. --}}
                @if($move['explanation'] !== null)
                    <div class="mt-1 pt-1 border-top" data-role="next-best-move-explanation">
                        <p class="text-caption text-muted mb-50">
                            <span data-role="ai-summary-label">AI note</span>
                            · <span data-role="next-best-move-explanation-updated">{{ $move['explanation']['updated'] }}</span>
                        </p>
                        <ul class="list-unstyled mb-0" data-role="next-best-move-explanation-statements">
                            @foreach($move['explanation']['statements'] as $statement)
                                <li class="mb-50" data-role="next-best-move-explanation-statement" data-class="{{ $statement['class'] }}">
                                    <strong data-role="next-best-move-explanation-certainty">{{ $statement['label'] }}:</strong>
                                    <span>{{ $statement['text'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        {{-- Without a Growth evaluation the queue is RFC-002's work queue: its size, never re-scored. --}}
        @if($home['queue'] === [] && $home['queueLabel'] !== '0' && $home['queueLabel'] !== '')
            <p class="text-caption mb-0 mt-1" data-role="next-best-move-all">
                @if($home['queueUrl'])
                    <a href="{{ $home['queueUrl'] }}" data-role="next-best-move-all-link">See all recommendations ({{ $home['queueLabel'] }})</a>
                @else
                    {{ $home['queueLabel'] }} recommendations
                @endif
            </p>
        @endif
    </div>
</section>
