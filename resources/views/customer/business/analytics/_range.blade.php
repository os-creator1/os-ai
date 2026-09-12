{{-- Results — the date range control, shared by the overview and the
     campaign table. Human presets plus a custom range of up to
     MAX_CUSTOM_DAYS; every date is a calendar date in the Business's own
     timezone. The timezone is stated once, in a tooltip, rather than in
     the main line: it explains the figures, it is not one of them. --}}
@php
    use App\Library\Analytics\AnalyticsDateRange;

    $presetLabels = [
        AnalyticsDateRange::PRESET_LAST_7_DAYS => 'Last 7 days',
        AnalyticsDateRange::PRESET_LAST_30_DAYS => 'Last 30 days',
        AnalyticsDateRange::PRESET_LAST_90_DAYS => 'Last 90 days',
        AnalyticsDateRange::PRESET_THIS_MONTH => 'This month',
        AnalyticsDateRange::PRESET_LAST_MONTH => 'Last month',
        AnalyticsDateRange::PRESET_CUSTOM => 'Custom range',
    ];
    $isCustom = $range->preset === AnalyticsDateRange::PRESET_CUSTOM;
    $businessName = $businessName ?? null;
    $timezoneNote = 'Days follow ' . ($businessName ? $businessName . "'s" : "this Business's") . ' local time (' . $range->timezone . ').';
@endphp

<form method="get" action="{{ $formAction }}" class="row g-1 align-items-end" data-role="analytics-range">
    <div class="col-md-3 col-sm-6">
        <label class="form-label" for="range">Date range</label>
        <select id="range" name="range" class="form-select" data-role="range-preset">
            @foreach(AnalyticsDateRange::SELECTABLE_PRESETS as $value)
                <option value="{{ $value }}" @selected($range->preset === $value)>{{ $presetLabels[$value] }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3 col-sm-6" data-role="custom-dates" @unless($isCustom) hidden @endunless>
        <label class="form-label" for="start">From</label>
        <input type="date" id="start" name="start" class="form-control" value="{{ old('start', $isCustom ? $range->startLocal->format('Y-m-d') : '') }}">
    </div>
    <div class="col-md-3 col-sm-6" data-role="custom-dates" @unless($isCustom) hidden @endunless>
        <label class="form-label" for="end">To</label>
        <input type="date" id="end" name="end" class="form-control" value="{{ old('end', $isCustom ? $range->endLocal->format('Y-m-d') : '') }}">
    </div>
    <div class="col-md-3 col-sm-6">
        <x-button type="submit" variant="primary" size="sm">Apply</x-button>
    </div>
    <div class="col-12">
        <p class="text-caption mb-0" data-role="range-caption">
            Showing <strong>{{ $range->label() }}</strong>
            @unless($isCustom)
                <span class="text-muted">· {{ $range->spanLabel() }}</span>
            @endunless
            <x-tooltip :text="$timezoneNote" tabindex="0" role="note" aria-label="{{ $timezoneNote }}" data-role="timezone-note">
                <x-ds-icon name="info" size="14" class="text-muted align-text-bottom" aria-hidden="true" />
            </x-tooltip>
        </p>
        <p class="text-caption text-muted mb-0" data-role="custom-dates" @unless($isCustom) hidden @endunless>
            A custom range can cover up to {{ AnalyticsDateRange::MAX_CUSTOM_DAYS }} days.
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
