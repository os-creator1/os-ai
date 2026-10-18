<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Booking confirmed · {{ $brand['business'] }}</title>
    @include('public.booking._scheduler-styles')
</head>
<body class="pb" @if ($brand['accent']) style="--pb-accent: {{ $brand['accent'] }}" @endif>
<main class="pb-shell">
    <div class="pb-card">
        @include('public.booking._info', ['type' => $type, 'brand' => $brand, 'timezone' => $summary['timezone'] ?? null])

        <section class="pb-main pb-done" data-role="confirmation">
            <div class="pb-check"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg></div>
            <h2 class="pb-heading">Booking confirmed</h2>
            <p style="margin:0;color:var(--pb-muted)">Your appointment has been booked.</p>
            @if (is_array($summary) && ! empty($summary['notice']))<p data-role="booking-notice" style="margin:8px 0 0;color:var(--pb-muted)">{{ $summary['notice'] }}</p>@endif
            @if (is_array($summary))
                <div class="pb-summary">
                    <dl>
                        <dt>What</dt><dd>{{ $summary['type'] }}</dd>
                        <dt>Who</dt><dd>{{ $summary['business'] }}</dd>
                        <dt>When</dt><dd>{{ $summary['date'] }}</dd>
                        <dt>Time</dt><dd>{{ $summary['time'] }}</dd>
                        <dt>Time zone</dt><dd>{{ str_replace('_', ' ', $summary['timezone']) }}</dd>
                        @if ($summary['where'])<dt>Where</dt><dd>{{ $summary['where'] }}</dd>@endif
                        @if (! empty($summary['instructions']))<dt>Details</dt><dd>{{ $summary['instructions'] }}</dd>@endif
                    </dl>
                </div>
            @endif
        </section>
    </div>
</main>
</body>
</html>
