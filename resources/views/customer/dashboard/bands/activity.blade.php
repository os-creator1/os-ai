{{--
    Unified Business Home §2.3 (H-2) — Business activity: what actually
    changed since this customer last used this Business, in one compact
    factual line. The frame adapts to how recently they were here (today so
    far / since your last visit / a bounded catch-up window), so an hourly
    visit never reduces the summary to noise.

    Every figure is a canonical count. There are no leads, bookings, visitors,
    rankings or percentages here, because this application has no source for
    any of them.
--}}
<section class="mb-2" aria-labelledby="dashboard-activity-heading" data-band="activity" data-activity-mode="{{ $activity['window']->mode }}">
    <x-card>
        <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-1 mb-50">
            <h2 class="h4 text-section-heading mb-0" id="dashboard-activity-heading">Business activity</h2>
            <p class="text-caption text-muted mb-0" data-role="activity-window">{{ $activity['window']->label() }}</p>
        </div>

        @if($activity['items'] === [])
            <p class="mb-0" data-role="activity-empty">{{ $activity['window']->emptySentence() }}</p>
        @else
            <ul class="list-unstyled d-flex flex-wrap align-items-center gap-1 mb-0" data-role="activity-items">
                @foreach($activity['items'] as $item)
                    <li class="d-flex align-items-center" data-role="activity-item" data-activity-item="{{ $item['key'] }}">
                        <span>{{ $item['text'] }}</span>
                        @unless($loop->last)
                            <span class="text-muted mx-50" aria-hidden="true">·</span>
                        @endunless
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</section>
