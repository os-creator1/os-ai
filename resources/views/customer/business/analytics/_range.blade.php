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
    // The region this control updates in place (window.AsyncRegion). Without it
    // the form is an ordinary GET form, which is also the no-JavaScript fallback
    // when it is set: the same URL, the same page.
    $asyncRegion = $asyncRegion ?? null;
    $timezoneNote = 'Days follow ' . ($businessName ? $businessName . "'s" : "this Business's") . ' local time (' . $range->timezone . ').';
@endphp

<form method="get" action="{{ $formAction }}" class="row g-1 align-items-end" data-role="analytics-range" @if($asyncRegion) data-async-form="{{ $asyncRegion }}" @endif>
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
        // Bound once per page, by delegation, so it keeps working for a range
        // control swapped in by an in-place update (whose own copy of this
        // script does not run) and for every range control on the page.
        if (window.__analyticsRangeCustomDates) { return; }
        window.__analyticsRangeCustomDates = true;

        function sync(form) {
            var preset = form && form.querySelector('[data-role="range-preset"]');
            if (!preset) { return; }
            var custom = preset.value === 'custom';
            form.querySelectorAll('[data-role="custom-dates"]').forEach(function (el) { el.hidden = !custom; });
        }

        function syncAll(root) {
            (root || document).querySelectorAll('[data-role="analytics-range"]').forEach(sync);
        }

        document.addEventListener('change', function (event) {
            if (event.target && event.target.matches && event.target.matches('[data-role="range-preset"]')) {
                sync(event.target.form);
            }
        });
        document.addEventListener('async-region:updated', function (event) { syncAll(event.target); });
        syncAll(document);
    })();
</script>
