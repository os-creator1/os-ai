@if ($booking['selected'] === null)
    <p class="text-warning" data-role="booking-none">No booking type selected. Booking buttons are not shown on your site.</p>
@elseif ($booking['readiness']['ready'])
    <p class="text-success" data-role="booking-ready">Visitors book into your calendar at <a href="{{ $booking['url'] }}" target="_blank" rel="noopener">{{ $booking['url'] }}</a>. @if ($booking['editUrl'])<a href="{{ $booking['editUrl'] }}">Edit availability, questions and reminders</a>@endif</p>
@else
    <p class="text-danger" data-role="booking-invalid">The selected booking type can't take bookings: {{ $booking['readiness']['reason'] }} Booking buttons are hidden until it is fixed. @if ($booking['editUrl'])<a href="{{ $booking['editUrl'] }}">Open booking type settings</a>@endif</p>
@endif
