{{--
    The Booking Type settings, as three compact cards for the create and edit
    forms. Each control maps to a real column the scheduling engine reads;
    nothing here is cosmetic. Preset lists show a stored value that is not on
    the list, so an unusual existing value is never silently rewritten.
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
@endphp

<x-card title="Basic information" class="mb-2" data-section="booking-type-basics">
    <div class="form-group">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" class="form-control" maxlength="120" value="{{ old('name', $type?->name) }}" required>
    </div>

    <div class="form-group">
        <label for="description">Description</label>
        <textarea id="description" name="description" class="form-control" rows="3">{{ old('description', $type?->description) }}</textarea>
    </div>

    <div class="form-group">
        <label for="meeting_instructions">Location &amp; meeting instructions</label>
        <textarea id="meeting_instructions" name="meeting_instructions" class="form-control" rows="2" maxlength="2000"
                  placeholder="Where to go, where to park, or how to join">{{ old('meeting_instructions', $type?->meeting_instructions) }}</textarea>
        <small class="text-caption">
            Shown to customers on the booking page and in their confirmation. Appointments are held at
            <strong>{{ $location->name ?: 'this location' }}</strong>.
        </small>
    </div>

    <div class="form-group mb-0">
        <label for="color">Colour</label>
        <input type="text" id="color" name="color" class="form-control" maxlength="16" placeholder="#0F766E" value="{{ old('color', $type?->color) }}">
        <small class="text-caption">Used as the accent on this type's booking page. Leave blank for the default.</small>
    </div>
</x-card>

<x-card title="Scheduling" class="mb-2" data-section="booking-type-scheduling">
    <div class="row">
        <div class="col-md-6 form-group">
            <label for="duration_minutes">Duration (minutes)</label>
            <input type="number" id="duration_minutes" name="duration_minutes" class="form-control" min="1" max="1440" value="{{ $current('duration_minutes', 30) }}" required>
        </div>
        <div class="col-md-6 form-group">
            <label for="slot_interval_minutes">Start times</label>
            <select id="slot_interval_minutes" name="slot_interval_minutes" class="form-control">
                @foreach ($intervals as $value => $label)
                    <option value="{{ $value }}" @selected((int) $current('slot_interval_minutes', 30) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <small class="text-caption">How often a customer can start. Independent of the duration.</small>
        </div>
        <div class="col-md-6 form-group mb-md-0">
            <label for="minimum_notice_minutes">Minimum notice</label>
            <select id="minimum_notice_minutes" name="minimum_notice_minutes" class="form-control">
                @foreach ($notices as $value => $label)
                    <option value="{{ $value }}" @selected((int) $current('minimum_notice_minutes', 0) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <small class="text-caption">How far ahead a customer must book.</small>
        </div>
        <div class="col-md-6 form-group mb-0">
            <label for="booking_window_days">Booking window</label>
            <select id="booking_window_days" name="booking_window_days" class="form-control">
                @foreach ($windows as $value => $label)
                    <option value="{{ $value }}" @selected((int) $current('booking_window_days', 30) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <small class="text-caption">A rolling window counted from today.</small>
        </div>
    </div>
</x-card>

<x-card title="Buffers" class="mb-2" data-section="booking-type-buffers">
    <p class="text-caption">
        Time kept free around each appointment. Buffers only block neighbouring bookings; the appointment keeps its real start and end.
    </p>
    <div class="row">
        <div class="col-md-6 form-group mb-md-0">
            <label for="buffer_before_minutes">Before</label>
            <select id="buffer_before_minutes" name="buffer_before_minutes" class="form-control">
                @foreach ($buffers($current('buffer_before_minutes', 0)) as $value => $label)
                    <option value="{{ $value }}" @selected((int) $current('buffer_before_minutes', 0) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-6 form-group mb-0">
            <label for="buffer_after_minutes">After</label>
            <select id="buffer_after_minutes" name="buffer_after_minutes" class="form-control">
                @foreach ($buffers($current('buffer_after_minutes', 0)) as $value => $label)
                    <option value="{{ $value }}" @selected((int) $current('buffer_after_minutes', 0) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
</x-card>
