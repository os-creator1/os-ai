{{--
    Home V2 drawer — Upcoming appointments. A READ of scheduled appointments
    over the next 14 days, at only the Locations this actor may reach, through
    the Calendar's own schedule service. No write.
--}}
<div data-role="drawer-upcoming">
    <p class="mb-1">Your scheduled appointments for the next 14 days.</p>

    @if($rows === [])
        <p class="mb-0" data-role="drawer-empty">No appointments are scheduled in the next 14 days.</p>
    @else
        <ul class="list-unstyled mb-0" data-role="upcoming-items">
            @foreach($rows as $row)
                <li class="py-50 @unless($loop->first) border-top @endunless" data-role="upcoming-item">
                    <p class="fw-bold mb-0">
                        <time datetime="{{ $row['start']->toIso8601String() }}">{{ $row['start']->format('D j M, g:i A') }}</time>
                        · {{ $row['type'] }}
                    </p>
                    <p class="mb-0">{{ $row['who'] }}@if($row['staff']) <span class="text-muted">with {{ $row['staff'] }}</span>@endif</p>
                    <p class="text-caption text-muted mb-0">{{ $row['location'] }} · <a href="{{ $row['url'] }}">Open appointment</a></p>
                </li>
            @endforeach
        </ul>
    @endif

    @if(($action['drawer'] ?? null) !== null && $viewingAs === false)
        <p class="mt-1 mb-0 text-caption">To book someone, use <em>Book an appointment</em> from Ask MotionGrove.</p>
    @endif
</div>
