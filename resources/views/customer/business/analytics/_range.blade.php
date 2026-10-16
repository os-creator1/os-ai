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
    // Home's compact segmented control: the same form, the same `range` parameter and the same server-side range logic —
    // only the way a preset is chosen differs (a submit button per preset instead of a select).
    $segmented = $segmented ?? false;
    $segmentPresets = [AnalyticsDateRange::PRESET_LAST_7_DAYS => '7 days', AnalyticsDateRange::PRESET_THIS_MONTH => 'This month', AnalyticsDateRange::PRESET_LAST_30_DAYS => '30 days'];
    if ($segmented && ! isset($segmentPresets[$range->preset]) && ! $isCustom) {
        $segmentPresets[$range->preset] = $presetLabels[$range->preset] ?? $range->label();
    }
    $timezoneNote = 'Days follow ' . ($businessName ? $businessName . "'s" : "this Business's") . ' local time (' . $range->timezone . ').';
@endphp

<form method="get" action="{{ $formAction }}" class="{{ $segmented ? 'home-range-form' : 'row g-1 align-items-end' }}" data-role="analytics-range" @if($asyncRegion) data-async-form="{{ $asyncRegion }}" @endif>
    <div class="{{ $segmented ? 'visually-hidden' : 'col-md-3 col-sm-6' }}">
        <label class="form-label" for="range">Date range</label>
        <select id="range" name="range" class="form-select" data-role="range-preset" @if($segmented) inert @endif>
            @foreach(AnalyticsDateRange::SELECTABLE_PRESETS as $value)
                <option value="{{ $value }}" @selected($range->preset === $value)>{{ $presetLabels[$value] }}</option>
            @endforeach
        </select>
    </div>
    @if($segmented)
        <div class="home-segmented" role="group" aria-label="Date range" data-role="range-segments">
            @foreach($segmentPresets as $value => $label)
                <button type="submit" name="range" value="{{ $value }}" class="{{ $range->preset === $value ? 'is-active' : '' }}" aria-pressed="{{ $range->preset === $value ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
            <button type="button" class="{{ $isCustom ? 'is-active' : '' }}" aria-pressed="{{ $isCustom ? 'true' : 'false' }}" data-role="range-custom-toggle">Custom</button>
        </div>
    @endif
    <div class="col-md-3 col-sm-6" data-role="custom-dates" @unless($isCustom) hidden @endunless>
        <label class="form-label" for="start">From</label>
        <input type="date" id="start" name="start" class="form-control" value="{{ old('start', $isCustom ? $range->startLocal->format('Y-m-d') : '') }}">
    </div>
    <div class="col-md-3 col-sm-6" data-role="custom-dates" @unless($isCustom) hidden @endunless>
        <label class="form-label" for="end">To</label>
        <input type="date" id="end" name="end" class="form-control" value="{{ old('end', $isCustom ? $range->endLocal->format('Y-m-d') : '') }}">
    </div>
    <div class="col-md-3 col-sm-6" @if($segmented) data-role="custom-dates" @unless($isCustom) hidden @endunless @endif>
        @if($segmented)
            <x-button type="submit" variant="primary" size="sm" name="range" value="custom">Apply</x-button>
        @else
            <x-button type="submit" variant="primary" size="sm">Apply</x-button>
        @endif
    </div>
    <div class="{{ $segmented ? 'visually-hidden' : 'col-12' }}">
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
        document.addEventListener('click', function (event) {
            var toggle = event.target && event.target.closest ? event.target.closest('[data-role="range-custom-toggle"]') : null;
            if (!toggle) { return; }
            var form = toggle.form || toggle.closest('form');
            var open = form.querySelector('[data-role="custom-dates"]').hidden;
            form.querySelectorAll('[data-role="custom-dates"]').forEach(function (el) { el.hidden = !open; });
            toggle.setAttribute('aria-pressed', open ? 'true' : 'false');
        });
        document.addEventListener('async-region:updated', function (event) { syncAll(event.target); });
        syncAll(document);
    })();
</script>
