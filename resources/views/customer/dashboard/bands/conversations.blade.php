{{--
    Unified Business Home §2.6 (H-4) — Conversations.

    Incoming and Replied describe the period chosen above. Awaiting reply is
    CURRENT state and says so: it answers "is anyone waiting for me right
    now?", which a figure about last month could not.

    Every number is a count of CONVERSATIONS, never of messages: five replies
    in one thread are one answered conversation.
--}}
<section class="mb-2" aria-labelledby="dashboard-conversations-heading" data-band="conversations">
    <x-card>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-50">
            <h2 class="h4 text-section-heading mb-0" id="dashboard-conversations-heading">Conversations</h2>
            @if($conversations['inboxUrl'])
                <x-button variant="outline" size="sm" :href="$conversations['inboxUrl']" data-role="conversations-link">Open inbox</x-button>
            @endif
        </div>

        <ul class="list-unstyled mb-0">
            @foreach($conversations['items'] as $item)
                <li class="d-flex flex-column flex-md-row align-items-md-center gap-1 py-50 @unless($loop->last) border-bottom @endunless"
                    data-role="conversations-item" data-conversations="{{ $item['key'] }}"
                    @if($item['severity'] !== null) data-severity="{{ $item['severity']->value }}" @endif>
                    <span class="col-md-3 text-label">{{ $item['label'] }}</span>

                    <div class="flex-grow-1">
                        <p class="h5 mb-0" data-role="conversations-figure">{{ $item['figure'] }}</p>
                        <p class="text-caption text-muted mb-0" data-role="conversations-caption">{{ $item['caption'] }}</p>
                    </div>

                    @if($item['severity'] !== null)
                        <x-badge :variant="$item['severity']->badgeVariant()" data-role="conversations-severity">{{ $item['severity']->word() }}</x-badge>
                    @endif
                </li>
            @endforeach
        </ul>

        <p class="text-caption text-muted mb-0 mt-50" data-role="conversations-period">
            Incoming and Replied cover {{ $conversations['rangeLabel'] }}. Awaiting reply is right now.
        </p>
    </x-card>
</section>
