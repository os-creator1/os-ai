{{--
    The Booking Type settings, as three compact cards for the create and edit
    forms. Each control maps to a real column the scheduling engine reads;
    nothing here is cosmetic. Preset lists show a stored value that is not on
    the list, so an unusual existing value is never silently rewritten.

    The colour swatches only fill the ordinary `color` text input; that input
    is what submits, so the swatches add no second source of truth. Every
    control carries data-bt-field so the live summary beside the form (create
    page) can read it, but the summary only ever mirrors these inputs.
--}}
@php
    $type = $bookingType ?? null;
    $current = static fn (string $key, $fallback) => old($key, $type ? ($type->{$key} ?? $fallback) : $fallback);

    $withCurrent = static function (array $presets, $value, \Closure $label): array {
        $value = (int) $value;
        if (! array_key_exists($value, $presets)) {
            $presets[$value] = $label($value);
            ksort($presets);
        }

        return $presets;
    };

    $windows = $withCurrent([7 => '7 days ahead', 14 => '14 days ahead', 30 => '30 days ahead', 60 => '60 days ahead', 90 => '90 days ahead'],
        $current('booking_window_days', 30), static fn ($v) => $v.' days ahead');
    $intervals = [15 => 'Every 15 minutes', 30 => 'Every 30 minutes', 45 => 'Every 45 minutes', 60 => 'Every hour'];
    $notices = $withCurrent([0 => 'None', 60 => '1 hour', 120 => '2 hours', 240 => '4 hours', 720 => '12 hours', 1440 => '24 hours', 2880 => '48 hours'],
        $current('minimum_notice_minutes', 0), static fn ($v) => $v % 60 === 0 ? ($v / 60).' hours' : $v.' minutes');
    $buffers = fn ($value) => $withCurrent([0 => 'None', 5 => '5 min', 10 => '10 min', 15 => '15 min', 30 => '30 min', 45 => '45 min', 60 => '60 min'],
        $value, static fn ($v) => $v.' min');

    // Suggested accents for the public booking page. They are stored values a
    // customer picks, not theme colours; any other value can still be typed.
    $swatches = ['#0F766E' => 'Teal', '#B5524C' => 'Rust', '#2F6FB0' => 'Blue', '#B7791F' => 'Amber', '#6B46C1' => 'Purple'];
    $colorValue = (string) old('color', $type?->color);
    $invalid = static fn (string $key) => $errors->has($key) ? ' is-invalid' : '';
@endphp

<section class="cx-card" data-section="booking-type-basics">
    <div class="cx-card-head is-plain"><h2 class="cx-card-title">Basic information</h2></div>
    <div class="cx-card-body">
        <div class="form-group">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" class="form-control{{ $invalid('name') }}" maxlength="120"
                   placeholder="e.g. Planning call" value="{{ old('name', $type?->name) }}" required data-bt-field="name">
            @error('name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" class="form-control{{ $invalid('description') }}" rows="3">{{ old('description', $type?->description) }}</textarea>
            @error('description') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        </div>

        @php
            // Meeting location. Zoom is offered only where this Business may use it; the server re-checks (plan + host), this
            // only avoids offering a choice that would be refused.
            $zoomManager = app(\App\Library\Calendar\Zoom\ZoomConnectionManager::class);
            $zoomAvailable = isset($business) && $zoomManager->isEnabledFor($business);
            $zoomHosts = $zoomAvailable ? \App\Models\ZoomConnection::query()->where('business_id', $business->id)->where('state', 'active')->orderBy('id')->get() : collect();
            $zoomUsers = \App\Models\User::query()->whereIn('id', $zoomHosts->pluck('user_id'))->get()->keyBy('id');
            $chosenLocation = old('meeting_location_type', $type?->meeting_location_type);
        @endphp
        <div class="form-group" data-section="meeting-location">
            <label for="meeting_location_type">Meeting location</label>
            <select id="meeting_location_type" name="meeting_location_type" class="form-control{{ $invalid('meeting_location_type') }}">
                <option value="" @selected(! $chosenLocation)>Use the location below (default)</option>
                @if ($zoomAvailable || $chosenLocation === 'zoom')
                    <option value="zoom" @selected($chosenLocation === 'zoom') @disabled(! $zoomAvailable)>Zoom meeting</option>
                @endif
                <option value="in_person" @selected($chosenLocation === 'in_person')>In person</option>
                <option value="phone" @selected($chosenLocation === 'phone')>Phone call</option>
                <option value="custom" @selected($chosenLocation === 'custom')>Custom meeting link or location</option>
            </select>
            @error('meeting_location_type') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror

            <div data-role="meeting-location-custom" class="mt-1" @if ($chosenLocation !== 'custom') hidden @endif>
                <input type="text" id="meeting_location_custom" name="meeting_location_custom" class="form-control{{ $invalid('meeting_location_custom') }}" maxlength="500"
                       placeholder="A meeting link or an address" value="{{ old('meeting_location_custom', $type?->meeting_location_custom) }}">
                @error('meeting_location_custom') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>

            @if ($zoomAvailable)
                <div data-role="meeting-location-zoom" class="mt-1" @if ($chosenLocation !== 'zoom') hidden @endif>
                    <label for="zoom_host_connection_id" class="mb-25">Zoom host</label>
                    <select id="zoom_host_connection_id" name="zoom_host_connection_id" class="form-control">
                        <option value="" @selected(! old('zoom_host_connection_id', $type?->zoom_host_connection_id))>The person who gets each booking</option>
                        @foreach ($zoomHosts as $host)
                            @php($hostUser = $zoomUsers[$host->user_id] ?? null)
                            <option value="{{ $host->id }}" @selected((string) old('zoom_host_connection_id', $type?->zoom_host_connection_id) === (string) $host->id)>
                                One shared host: {{ trim(($hostUser->first_name ?? '').' '.($hostUser->last_name ?? '')) ?: ($hostUser->email ?? 'Team member') }}@if ($host->zoom_account_email) ({{ $host->zoom_account_email }})@endif
                            </option>
                        @endforeach
                    </select>
                    <small class="cx-hint">
                        MotionGrove creates a unique Zoom meeting for every booking and puts the join link in the confirmation and reminders.
                        Customers cannot book this type until its host has connected Zoom
                        (<a href="{{ route('customer.workspaces.businesses.calendar.integrations.zoom.show', [$workspace->uid, $business->uid]) }}">Zoom settings</a>).
                    </small>
                </div>
            @endif
            <script>
                (function () {
                    var select = document.getElementById('meeting_location_type');
                    if (!select) { return; }
                    function sync() {
                        var custom = document.querySelector('[data-role="meeting-location-custom"]');
                        var zoom = document.querySelector('[data-role="meeting-location-zoom"]');
                        if (custom) { custom.hidden = select.value !== 'custom'; }
                        if (zoom) { zoom.hidden = select.value !== 'zoom'; }
                    }
                    select.addEventListener('change', sync);
                })();
            </script>
        </div>

        <div class="form-group">
            <label for="meeting_instructions">Location &amp; meeting instructions</label>
            <textarea id="meeting_instructions" name="meeting_instructions" class="form-control{{ $invalid('meeting_instructions') }}" rows="2" maxlength="2000"
                      placeholder="Where to go, where to park, or how to join">{{ old('meeting_instructions', $type?->meeting_instructions) }}</textarea>
            @error('meeting_instructions') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            <small class="cx-hint">
                Shown to customers on the booking page and in their confirmation. Appointments are held at
                <strong>{{ $location->name ?: 'this location' }}</strong>.
            </small>
        </div>

        <div class="form-group mb-0">
            <label for="color">Colour</label>
            <div class="bt-colors" data-bt-colors>
                @foreach ($swatches as $hex => $swatchName)
                    <button type="button" class="bt-swatch" style="--bt-swatch: {{ $hex }}" data-bt-swatch="{{ $hex }}"
                            aria-label="{{ $swatchName }} ({{ $hex }})"
                            aria-pressed="{{ strcasecmp($colorValue, $hex) === 0 ? 'true' : 'false' }}"></button>
                @endforeach
                <span class="bt-color-custom">
                    <span class="bt-color-preview" data-bt-color-preview aria-hidden="true"></span>
                    <input type="text" id="color" name="color" class="form-control form-control-sm{{ $invalid('color') }}" maxlength="16"
                           placeholder="#0F766E" value="{{ $colorValue }}" data-bt-field="color" aria-describedby="color-hint">
                </span>
            </div>
            @error('color') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            <small class="cx-hint" id="color-hint">Used as the accent on this type's booking page. Leave blank for the default.</small>
        </div>
    </div>
</section>

<section class="cx-card" data-section="booking-type-scheduling">
    <div class="cx-card-head is-plain"><h2 class="cx-card-title">Scheduling</h2></div>
    <div class="cx-card-body">
        <div class="cx-grid-2">
            <div class="form-group">
                <label for="duration_minutes">Duration (minutes)</label>
                <input type="number" id="duration_minutes" name="duration_minutes" class="form-control{{ $invalid('duration_minutes') }}" min="1" max="1440"
                       value="{{ $current('duration_minutes', 30) }}" required data-bt-field="duration">
                @error('duration_minutes') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label for="slot_interval_minutes">Start times</label>
                <select id="slot_interval_minutes" name="slot_interval_minutes" class="form-control{{ $invalid('slot_interval_minutes') }}" data-bt-field="interval">
                    @foreach ($intervals as $value => $label)
                        <option value="{{ $value }}" @selected((int) $current('slot_interval_minutes', 30) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('slot_interval_minutes') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                <small class="cx-hint">How often a customer can start. Independent of the duration.</small>
            </div>
            <div class="form-group mb-md-0">
                <label for="minimum_notice_minutes">Minimum notice</label>
                <select id="minimum_notice_minutes" name="minimum_notice_minutes" class="form-control{{ $invalid('minimum_notice_minutes') }}" data-bt-field="notice">
                    @foreach ($notices as $value => $label)
                        <option value="{{ $value }}" @selected((int) $current('minimum_notice_minutes', 0) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('minimum_notice_minutes') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                <small class="cx-hint">How far ahead a customer must book.</small>
            </div>
            <div class="form-group mb-0">
                <label for="booking_window_days">Booking window</label>
                <select id="booking_window_days" name="booking_window_days" class="form-control{{ $invalid('booking_window_days') }}" data-bt-field="window">
                    @foreach ($windows as $value => $label)
                        <option value="{{ $value }}" @selected((int) $current('booking_window_days', 30) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('booking_window_days') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                <small class="cx-hint">A rolling window counted from today.</small>
            </div>
        </div>
    </div>
</section>

<section class="cx-card" data-section="booking-type-buffers">
    <div class="cx-card-head is-plain">
        <div>
            <h2 class="cx-card-title">Buffers</h2>
            <p class="cx-card-sub">Time kept free around each appointment. Buffers only block neighbouring bookings; the appointment keeps its real start and end.</p>
        </div>
    </div>
    <div class="cx-card-body">
        <div class="cx-grid-2">
            <div class="form-group mb-md-0">
                <label for="buffer_before_minutes">Before</label>
                <select id="buffer_before_minutes" name="buffer_before_minutes" class="form-control{{ $invalid('buffer_before_minutes') }}" data-bt-field="buffer-before">
                    @foreach ($buffers($current('buffer_before_minutes', 0)) as $value => $label)
                        <option value="{{ $value }}" @selected((int) $current('buffer_before_minutes', 0) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('buffer_before_minutes') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
            <div class="form-group mb-0">
                <label for="buffer_after_minutes">After</label>
                <select id="buffer_after_minutes" name="buffer_after_minutes" class="form-control{{ $invalid('buffer_after_minutes') }}" data-bt-field="buffer-after">
                    @foreach ($buffers($current('buffer_after_minutes', 0)) as $value => $label)
                        <option value="{{ $value }}" @selected((int) $current('buffer_after_minutes', 0) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('buffer_after_minutes') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>
</section>
