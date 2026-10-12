{{--
    Home V2 §E — What MotionGrove is handling.

    Only rows that PROVE an outcome appear as Completed or Failed (persisted
    results: published website versions, completed recommendations, Google
    operations that succeeded, automations that failed). In progress and
    Waiting for approval come from the recommendation engine's own status. A
    queued job is never shown as done, and no technical event name is shown.
--}}
@php
    use App\Library\Dashboard\DashboardSnapshot;
    $words = ['completed' => 'Completed', 'failed' => 'Failed', 'progress' => 'In progress', 'approval' => 'Waiting for approval'];
    $failedBand = $dashboard->failed(DashboardSnapshot::BAND_RECENT_WORK);
@endphp

<section class="mb-2" aria-labelledby="home-handling-heading" data-band="handling" data-role="home-handling">
    <x-card>
        <h2 class="h5 text-section-heading mb-1" id="home-handling-heading">What MotionGrove is handling</h2>

        @if($failedBand)
            <p class="mb-0 text-muted" data-role="handling-unavailable">We couldn't load recent work just now.</p>
        @elseif($home['handling'] === [])
            <p class="mb-0 text-muted" data-role="handling-empty">Nothing to report yet. Completed work — published website updates, finished recommendations, automations — will be listed here.</p>
        @else
            <ul class="list-unstyled mb-0" data-role="handling-items">
                @foreach($home['handling'] as $row)
                    <li class="home-handled-row" data-role="recent-work-item" data-recent-work="{{ $row['key'] }}" data-status="{{ $row['status'] }}">
                        <span class="home-handled-state" data-status="{{ $row['status'] }}" data-role="handling-status">{{ $words[$row['status']] }}</span>
                        <span class="flex-grow-1">
                            @if($row['url'] !== null)
                                <a href="{{ $row['url'] }}" data-role="recent-work-link">{{ $row['text'] }}</a>
                            @else
                                <span data-role="recent-work-text">{{ $row['text'] }}</span>
                            @endif
                        </span>
                        @if($row['at'] !== null)
                            <time class="text-caption text-muted" datetime="{{ $row['at']->toIso8601String() }}">{{ $row['at']->diffForHumans() }}</time>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @php $resultsUrl = $dashboard->band(DashboardSnapshot::BAND_HEADLINES)['resultsUrl'] ?? null; @endphp
        @if($resultsUrl)
            <p class="mt-1 mb-0 text-caption">
                <a href="{{ $resultsUrl }}" data-role="handling-view-activity">View full results</a>
            </p>
        @endif
    </x-card>
</section>
