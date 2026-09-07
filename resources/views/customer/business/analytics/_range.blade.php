{{-- B5 contract §4 / §13.1 — the range control. Presets plus a custom
     range bounded at 92 inclusive days; every date is a Business-local
     calendar date. Shared by the overview and the campaign table. --}}
@php
    $presets = [
        \App\Library\Analytics\AnalyticsDateRange::PRESET_LAST_7_DAYS => 'Last 7 days',
        \App\Library\Analytics\AnalyticsDateRange::PRESET_LAST_30_DAYS => 'Last 30 days',
        \App\Library\Analytics\AnalyticsDateRange::PRESET_LAST_90_DAYS => 'Last 90 days',
        \App\Library\Analytics\AnalyticsDateRange::PRESET_CUSTOM => 'Custom',
    ];
    $isCustom = $range->preset === \App\Library\Analytics\AnalyticsDateRange::PRESET_CUSTOM;
@endphp

<form method="get" action="{{ $formAction }}" class="row g-1 align-items-end" data-role="analytics-range">
    <div class="col-md-3 col-sm-6">
        <label class="form-label" for="range">Date range</label>
        <select id="range" name="range" class="form-select" data-role="range-preset">
            @foreach($presets as $value => $label)
                <option value="{{ $value }}" @selected($range->preset === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3 col-sm-6" data-role="custom-dates" @unless($isCustom) hidden @endunless>
        <label class="form-label" for="start">Start</label>
        <input type="date" id="start" name="start" class="form-control" value="{{ old('start', $isCustom ? $range->startLocal->format('Y-m-d') : '') }}">
    </div>
    <div class="col-md-3 col-sm-6" data-role="custom-dates" @unless($isCustom) hidden @endunless>
        <label class="form-label" for="end">End</label>
        <input type="date" id="end" name="end" class="form-control" value="{{ old('end', $isCustom ? $range->endLocal->format('Y-m-d') : '') }}">
    </div>
    <div class="col-md-3 col-sm-6">
        <x-button type="submit" variant="primary" size="sm">Apply</x-button>
    </div>
    <div class="col-12">
        <p class="text-caption mb-0">
            Showing <strong>{{ $range->label() }}</strong> · {{ $range->days() }} local day{{ $range->days() === 1 ? '' : 's' }} in the
            <strong>{{ $range->timezone }}</strong> timezone. Custom ranges may cover up to {{ \App\Library\Analytics\AnalyticsDateRange::MAX_CUSTOM_DAYS }} days.
        </p>
    </div>
</form>

<script>
    (function () {
        var form = document.querySelector('[data-role="analytics-range"]');
        if (!form) { return; }
        var preset = form.querySelector('[data-role="range-preset"]');
        function sync() {
            var custom = preset.value === 'custom';
            form.querySelectorAll('[data-role="custom-dates"]').forEach(function (el) { el.hidden = !custom; });
        }
        preset.addEventListener('change', sync);
        sync();
    })();
</script>
