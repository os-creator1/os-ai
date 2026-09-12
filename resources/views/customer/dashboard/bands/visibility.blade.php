{{--
    Unified Business Home §2.6 (H-4) — Visibility: is the website live, and is
    Google connected?

    Two plain facts, each a column of the status row this page already read.
    Nothing is inferred beyond it: no visitors, no traffic, no SEO score, no
    "healthy". A tile whose destination this actor cannot open still states
    the fact, as plain text rather than a dead link.

    Where something needs attention the severity is a WORD, exactly as the
    attention band spells it; the badge colour only reinforces it.
--}}
<section class="mb-2" aria-labelledby="dashboard-visibility-heading" data-band="visibility">
    <x-card>
        <h2 class="h4 text-section-heading mb-1" id="dashboard-visibility-heading">Visibility</h2>

        <ul class="list-unstyled mb-0">
            @foreach($visibility['items'] as $item)
                <li class="d-flex flex-column flex-md-row align-items-md-center gap-1 py-50 @unless($loop->last) border-bottom @endunless"
                    data-role="visibility-item" data-visibility="{{ $item['key'] }}"
                    @if($item['severity'] !== null) data-severity="{{ $item['severity']->value }}" @endif>
                    <span class="col-md-3 text-label">{{ $item['label'] }}</span>

                    <div class="flex-grow-1">
                        <p class="mb-0" data-role="visibility-state">{{ $item['state'] }}</p>
                        @if($item['note'] !== null)
                            <p class="text-caption text-muted mb-0" data-role="visibility-note">{{ $item['note'] }}</p>
                        @endif
                    </div>

                    @if($item['severity'] !== null)
                        <x-badge :variant="$item['severity']->badgeVariant()" data-role="visibility-severity">{{ $item['severity']->word() }}</x-badge>
                    @endif

                    @if($item['url'] !== null)
                        <x-button variant="outline" size="sm" :href="$item['url']" data-role="visibility-link">Open</x-button>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-card>
</section>
