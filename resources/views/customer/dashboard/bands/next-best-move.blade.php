{{--
    Unified Business Home §2.4 (C-2) — Your next best move.

    Exactly ONE move, chosen in a fixed order: a customer waiting for a reply,
    a lost Google connection, failing automations, an unhealthy Google
    listing, the head of the Opportunity work queue, an unpublished website.
    Billing is never a candidate. When there is nothing to do, it says so.

    "Why this?" is deterministic: this application's own sentences and the
    facts that raised the move. No AI writes any of it.

    The one action is linked only when the actor may open it; otherwise the
    move still shows, without a button, and nothing unauthorized is linked.
--}}
<section class="mb-2" aria-labelledby="dashboard-next-best-move-heading" data-band="next_best_move">
    <x-card>
        <h2 class="h4 text-section-heading mb-1" id="dashboard-next-best-move-heading">Your next best move</h2>

        @if($nextBestMove['move'] === null)
            <p class="mb-0" data-role="next-best-move-caught-up">You're all caught up.</p>
        @else
            @php $move = $nextBestMove['move']; @endphp

            <div data-role="next-best-move" data-move-kind="{{ $move['kind'] }}" data-move="{{ $move['key'] }}"
                 @if($move['severity'] !== null) data-severity="{{ $move['severity']->value }}" @endif>
                <div class="d-flex flex-column flex-md-row align-items-md-center gap-1">
                    @if($move['severity'] !== null)
                        <x-badge :variant="$move['severity']->badgeVariant()" data-role="next-best-move-severity">{{ $move['severity']->word() }}</x-badge>
                    @endif

                    <p class="mb-0 flex-grow-1" data-role="next-best-move-headline">{{ $move['headline'] }}</p>

                    @if($move['actionUrl'] !== null)
                        <x-button size="sm" :href="$move['actionUrl']" data-role="next-best-move-action">{{ $move['actionLabel'] }}</x-button>
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
            </div>
        @endif

        @if($nextBestMove['recommendations'] !== null)
            <p class="text-caption mb-0 mt-1" data-role="next-best-move-all">
                @if($nextBestMove['recommendations']['url'] !== null)
                    <a href="{{ $nextBestMove['recommendations']['url'] }}" data-role="next-best-move-all-link">See all recommendations ({{ $nextBestMove['recommendations']['label'] }})</a>
                @else
                    {{ $nextBestMove['recommendations']['label'] }} recommendations
                @endif
            </p>
        @endif
    </x-card>
</section>
