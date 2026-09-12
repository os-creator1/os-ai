{{--
    Unified Business Home §2.6 (H-4) — Automations: completed and failed runs
    for the period chosen above, from the same B5 figures the rest of the page
    uses. The band is absent when nothing ran and nothing failed.

    "Review" goes where a failing automation is actually fixed — the same
    destination the attention item uses.
--}}
<section class="mb-2" aria-labelledby="dashboard-automations-heading" data-band="automations">
    <x-card>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-50">
            <h2 class="h4 text-section-heading mb-0" id="dashboard-automations-heading">Automations</h2>
            @if($automations['reviewUrl'])
                <x-button variant="outline" size="sm" :href="$automations['reviewUrl']" data-role="automations-review">Review</x-button>
            @endif
        </div>

        <ul class="list-unstyled mb-0">
            @foreach($automations['items'] as $item)
                <li class="d-flex flex-column flex-md-row align-items-md-center gap-1 py-50 @unless($loop->last) border-bottom @endunless"
                    data-role="automations-item" data-automations="{{ $item['key'] }}"
                    @if($item['severity'] !== null) data-severity="{{ $item['severity']->value }}" @endif>
                    <span class="col-md-3 text-label">{{ $item['label'] }}</span>

                    <div class="flex-grow-1">
                        <p class="h5 mb-0" data-role="automations-figure">{{ $item['figure'] }}</p>
                        <p class="text-caption text-muted mb-0" data-role="automations-caption">{{ $item['caption'] }}</p>
                    </div>

                    @if($item['severity'] !== null)
                        <x-badge :variant="$item['severity']->badgeVariant()" data-role="automations-severity">{{ $item['severity']->word() }}</x-badge>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-card>
</section>
