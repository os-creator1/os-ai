<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $type->name }} · {{ $brand['business'] }}</title>
    <script>document.documentElement.classList.add('js');</script>
    @include('public.booking._scheduler-styles')
</head>
<body class="pb" @if ($brand['accent']) style="--pb-accent: {{ $brand['accent'] }}" @endif>
<main class="pb-shell">
    <div class="pb-card">
        @include('public.booking._info', ['type' => $type, 'brand' => $brand, 'timezone' => $timezone])

        <section class="pb-main pb-app" id="pb-app" data-role="public-scheduler" data-step="date"
                 data-config="{{ json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}">
            <h2 class="pb-heading pb-heading-main">Select a date &amp; time</h2>

            <div class="pb-stage">
                <div class="pb-calendar" data-role="calendar">
                    <div class="pb-cal-head">
                        <span class="pb-month" id="pb-month" aria-live="polite"></span>
                        <span class="pb-nav">
                            <button type="button" class="pb-icon-btn" id="pb-prev" aria-label="Previous month">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>
                            </button>
                            <button type="button" class="pb-icon-btn" id="pb-next" aria-label="Next month">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.5 4.5 13 10l-5.5 5.5"/></svg>
                            </button>
                        </span>
                    </div>
                    <div class="pb-grid" id="pb-dow" aria-hidden="true"></div>
                    <div class="pb-grid" id="pb-days" role="grid" aria-labelledby="pb-month"></div>
                </div>

                <div class="pb-times" data-role="times">
                    <button type="button" class="pb-back pb-back-date" id="pb-back-date">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>
                        Choose another date
                    </button>
                    <p class="pb-times-title" id="pb-times-title">
                        @if ($day) {{ $day->format('l, F j') }} @else Available times @endif
                    </p>
                    <div id="pb-slots" class="pb-slot-list" aria-live="polite">
                        {{-- Server-rendered slots: the whole flow works with no JavaScript. The script replaces them. --}}
                        @if ($day && count($slots))
                            @foreach ($slots as $slot)
                                <label class="pb-slot-fallback"><input form="pb-form" type="radio" name="time" value="{{ $slot['time'] }}" required> {{ $slot['label'] }}</label>
                            @endforeach
                        @elseif ($day)
                            <p class="pb-empty">No available times on this date. Try another date.</p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="pb-tz" data-role="timezone">
                <label for="pb-tz">Time zone</label>
                <select id="pb-tz" aria-label="Time zone">
                    @foreach ($timezones as $region => $zones)
                        <optgroup label="{{ $region }}">
                            @foreach ($zones as $zone)
                                <option value="{{ $zone }}" @selected($zone === $timezone)>{{ str_replace('_', ' ', $zone) }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <form method="get" action="{{ route('public.booking.show', [$type->public_booking_uuid]) }}" class="pb-fallback-only pb-field" style="margin-top:14px">
                <label for="pb-date-fallback">Date</label>
                <input id="pb-date-fallback" type="date" name="date" value="{{ $day?->toDateString() }}" required>
                <button type="submit" class="pb-btn is-ghost" style="margin-top:10px">Show times</button>
            </form>

            <div class="pb-details" data-role="details">
                <button type="button" class="pb-back" id="pb-back-time">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>
                    Back
                </button>
                <h2 class="pb-heading">Enter details</h2>
                <p class="pb-picked" id="pb-picked" hidden></p>
                @if ($errors->any())
                    <p class="pb-alert" role="alert">{{ $errors->first('time') ?: 'Please check your details and choose an available time.' }}</p>
                @endif
                <p class="pb-alert" id="pb-alert" role="alert" hidden></p>

                <form method="post" id="pb-form" action="{{ route('public.booking.store', [$type->public_booking_uuid]) }}" novalidate>
                    @csrf
                    <input type="hidden" name="date" value="{{ $day?->toDateString() }}">
                    <input type="hidden" name="visitor_timezone" value="{{ $timezone }}">
                    <div class="pb-row">
                        <div class="pb-field"><label for="first-name">First name</label><input id="first-name" type="text" name="first_name" value="{{ old('first_name') }}" autocomplete="given-name" required><p class="pb-err" data-err="first_name" hidden></p></div>
                        <div class="pb-field"><label for="last-name">Last name</label><input id="last-name" type="text" name="last_name" value="{{ old('last_name') }}" autocomplete="family-name" required><p class="pb-err" data-err="last_name" hidden></p></div>
                    </div>
                    <div class="pb-field"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" inputmode="email" required><p class="pb-err" data-err="email" hidden></p></div>
                    <div class="pb-field"><label for="phone">Phone</label><input id="phone" type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" inputmode="tel" required><p class="pb-err" data-err="phone" hidden></p></div>
                    @if ($offerSmsConsent)
                        <div class="pb-field"><label for="sms-consent" style="font-weight:400"><input id="sms-consent" type="checkbox" name="sms_consent" value="1" @checked(old('sms_consent')) style="width:auto;margin-right:8px">Text me the confirmation and reminders for this appointment. Message and data rates may apply. This is only for this booking and is not marketing consent.</label></div>
                    @endif
                    <p class="pb-err" data-err="time" hidden></p>
                    <button type="submit" class="pb-btn" id="pb-submit">Confirm booking</button>
                </form>
            </div>

            <div class="pb-done" data-role="confirmation" id="pb-done" aria-live="polite">
                <div class="pb-check"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg></div>
                <h2 class="pb-heading">You are booked</h2>
                <p style="margin:0;color:var(--pb-muted)">Your appointment is confirmed. Keep this page for your details.</p>
                <p id="pb-notice" data-role="booking-notice" style="margin:8px 0 0;color:var(--pb-muted)" hidden></p>
                <div class="pb-summary"><dl id="pb-summary"></dl></div>
                <div class="pb-actions">
                    <a class="pb-btn is-ghost" id="pb-gcal" target="_blank" rel="noopener">Add to Google Calendar</a>
                    <a class="pb-btn is-ghost" id="pb-ics" download="appointment.ics">Download .ics</a>
                </div>
            </div>

            <div class="pb-sr" role="status" aria-live="polite" id="pb-status"></div>
        </section>
    </div>
</main>
@include('public.booking._scheduler-script')
</body>
</html>
