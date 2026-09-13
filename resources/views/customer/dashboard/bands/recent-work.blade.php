{{--
    Unified Business Home §14 (H-5) — Recent work: what actually happened to
    this Business, newest first.

    Every line is a persisted row, not an inferred event. An item whose
    destination this actor cannot open still appears, as plain text: the thing
    happened either way, and hiding it would make the list lie by omission.
--}}
<section class="mb-2" aria-labelledby="dashboard-recent-work-heading" data-band="recent_work">
    <x-card>
        <h2 class="h4 text-section-heading mb-1" id="dashboard-recent-work-heading">Recent work</h2>

        <ul class="list-unstyled mb-0">
            @foreach($recentWork['items'] as $item)
                <li class="d-flex flex-column flex-md-row align-items-md-baseline gap-1 py-50 @unless($loop->last) border-bottom @endunless"
                    data-role="recent-work-item" data-recent-work="{{ $item['key'] }}">
                    <div class="flex-grow-1">
                        @if($item['url'] !== null)
                            <a href="{{ $item['url'] }}" data-role="recent-work-link">{{ $item['text'] }}</a>
                        @else
                            <span data-role="recent-work-text">{{ $item['text'] }}</span>
                        @endif
                    </div>

                    <time class="text-caption text-muted" datetime="{{ $item['at']->toIso8601String() }}" data-role="recent-work-time">
                        {{ $item['at']->diffForHumans() }}
                    </time>
                </li>
            @endforeach
        </ul>
    </x-card>
</section>
