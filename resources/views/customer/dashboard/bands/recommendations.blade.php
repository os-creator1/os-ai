{{--
    Customer Experience Slice 4 §6 — the Advisor's current, open
    recommendations for this Business, at most five. Deterministic problems
    are never here; they are in Needs attention.
--}}
<section class="mb-2" aria-labelledby="dashboard-recommendations-heading" data-band="recommendations">
    <x-card>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1">
            <h2 class="h4 text-section-heading mb-0" id="dashboard-recommendations-heading">Recommended next steps</h2>
            @if($recommendations['allUrl'])
                <x-button variant="outline" size="sm" :href="$recommendations['allUrl']" data-role="recommendations-all">Open Opportunities</x-button>
            @endif
        </div>
        <ul class="list-unstyled mb-0">
            @foreach($recommendations['items'] as $item)
                <li class="py-1 @unless($loop->last) border-bottom @endunless" data-role="recommendation">
                    @if($item['url'])
                        <a href="{{ $item['url'] }}">{{ $item['title'] }}</a>
                    @else
                        <span>{{ $item['title'] }}</span>
                    @endif
                    @if($item['detected'])
                        <span class="d-block text-caption text-muted">Found {{ $item['detected'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-card>
</section>
