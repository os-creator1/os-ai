{{--
    Customer Experience Slice 4 §5 — what needs attention: severity as a
    word (the badge colour only reinforces it), one plain sentence, where it
    belongs, and the one action that fixes it.
--}}
<section class="mb-2" aria-labelledby="dashboard-attention-heading" data-band="attention">
    <x-card>
        <h2 class="h4 text-section-heading mb-1" id="dashboard-attention-heading">Needs attention</h2>
        <ul class="list-unstyled mb-0">
            @foreach($items as $item)
                <li class="d-flex flex-column flex-md-row align-items-md-center gap-1 py-1 @unless($loop->last) border-bottom @endunless"
                    data-role="attention-item" data-attention-type="{{ $item->type->value }}" data-severity="{{ $item->severity->value }}">
                    <x-badge :variant="$item->severity->badgeVariant()" data-role="attention-severity">{{ $item->severity->word() }}</x-badge>
                    <div class="flex-grow-1">
                        <p class="mb-0" data-role="attention-text">{{ $item->text }}</p>
                        <p class="text-caption text-muted mb-0" data-role="attention-scope">{{ $item->scope }}</p>
                    </div>
                    <x-button variant="outline" size="sm" :href="$item->url" data-role="attention-action">{{ $item->actionLabel }}</x-button>
                </li>
            @endforeach
        </ul>
    </x-card>
</section>
