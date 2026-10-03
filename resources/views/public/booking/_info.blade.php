{{-- The left panel: who is being booked, for what, for how long, and where. --}}
<aside class="pb-info" data-role="booking-info">
    <div class="pb-brand">
        <div class="pb-logo" aria-hidden="true">{{ $brand['initial'] }}</div>
        <div>
            <div class="pb-business">{{ $brand['business'] }}</div>
            @if ($brand['staff'])<div class="pb-staff">{{ $brand['staff'] }}</div>@endif
        </div>
    </div>
    <h1 class="pb-title">{{ $type->name }}</h1>
    <ul class="pb-meta">
        <li>
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="7.5"/><path d="M10 5.5V10l3 2"/></svg>
            <span>{{ $type->duration_minutes }} minutes</span>
        </li>
        @if ($brand['where'])
            <li>
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17.5s5.5-4.6 5.5-9a5.5 5.5 0 1 0-11 0c0 4.4 5.5 9 5.5 9Z"/><circle cx="10" cy="8.5" r="2"/></svg>
                <span>{{ $brand['where'] }}</span>
            </li>
        @endif
        @if ($timezone)<li>
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="7.5"/><path d="M2.5 10h15M10 2.5c2.2 2.1 3.3 4.6 3.3 7.5S12.2 15.400 10 17.500C7.800 15.400 6.700 12.900 6.700 10S7.800 4.600 10 2.500Z"/></svg>
            <span>Times shown in <span id="pb-tz-label">{{ $timezone }}</span></span>
        </li>@endif
    </ul>
    @if ($type->description)<p class="pb-desc">{{ $type->description }}</p>@endif
    @if ($type->meeting_instructions)<p class="pb-desc" data-role="meeting-instructions" style="margin-top:12px"><strong>Location details:</strong> {{ $type->meeting_instructions }}</p>@endif
</aside>
