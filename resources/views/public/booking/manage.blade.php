<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>Manage your appointment · {{ $brand['business'] }}</title>
    @include('public.booking._scheduler-styles')
</head>
<body class="pb" @if ($brand['accent']) style="--pb-accent: {{ $brand['accent'] }}" @endif>
<main class="pb-shell">
    <div class="pb-card">
        @include('public.booking._info', ['type' => $type, 'brand' => $brand, 'timezone' => $timezone ?? null])

        <section class="pb-main" data-role="manage-appointment">
            @if (($done ?? null) === 'cancelled')
                <h2 class="pb-heading">Appointment cancelled</h2>
                <p style="margin:0;color:var(--pb-muted)">Your appointment has been cancelled. You can book a new time whenever you like.</p>
                <p style="margin-top:14px"><a class="pb-btn" href="{{ route('demo.booking.show') }}">Book another time</a></p>
            @else
                <h2 class="pb-heading">Your appointment</h2>
                <div class="pb-summary"><dl>
                    <dt>What</dt><dd>{{ $current['type'] }}</dd>
                    <dt>When</dt><dd>{{ $current['date'] }}</dd>
                    <dt>Time</dt><dd>{{ $current['time'] }}</dd>
                    <dt>Time zone</dt><dd>{{ str_replace('_', ' ', $current['timezone']) }}</dd>
                </dl></div>

                @if (! empty($joinUrl))
                    <p style="margin-top:14px"><a class="pb-btn" href="{{ $joinUrl }}" rel="noopener" data-role="join-zoom">Join Zoom meeting</a></p>
                    <p style="margin:6px 0 0;color:var(--pb-muted);font-size:13px;word-break:break-all">Or copy this link: {{ $joinUrl }}</p>
                @endif

                @if ($errors->any())<p class="pb-alert" role="alert">{{ $errors->first() }}</p>@endif

                <h2 class="pb-heading" style="margin-top:22px">Reschedule</h2>
                <form method="get" action="{{ $baseUrl }}" class="pb-field">
                    @foreach ($signed as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                    <label for="manage-date">Pick a date ({{ str_replace('_', ' ', $timezone) }})</label>
                    <input id="manage-date" type="date" name="date" value="{{ $date }}" min="{{ $min }}" max="{{ $max }}" required>
                    <button type="submit" class="pb-btn is-ghost" style="margin-top:10px">Show times</button>
                </form>
                @if ($date)
                    @if (count($slots))
                        <form method="post" action="{{ $actionUrl }}">
                            @csrf
                            <input type="hidden" name="action" value="reschedule">
                            <input type="hidden" name="date" value="{{ $date }}">
                            @foreach ($slots as $slot)
                                <label class="pb-slot-fallback"><input type="radio" name="time" value="{{ $slot['time'] }}" required> {{ $slot['label'] }}</label>
                            @endforeach
                            <button type="submit" class="pb-btn" style="margin-top:12px">Move my appointment</button>
                        </form>
                    @else
                        <p class="pb-empty">No available times on this date. Try another date.</p>
                    @endif
                @endif

                <h2 class="pb-heading" style="margin-top:22px">Cancel</h2>
                <form method="post" action="{{ $actionUrl }}">
                    @csrf
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="pb-btn is-ghost">Cancel this appointment</button>
                </form>
            @endif
        </section>
    </div>
</main>
</body>
</html>
