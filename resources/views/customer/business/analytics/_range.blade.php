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

{{-- data-custom is the ONE source of truth for whether the Custom date fields are open (server-rendered from the range being shown, flipped only by the Custom
     toggle). The hidden preset select is a no-JavaScript/legacy control, never consulted to decide it, and in the segmented variant it carries no name so it
     can never add a second `range` parameter beside the pressed preset button. --}}
<form method="get" action="{{ $formAction }}" class="{{ $segmented ? 'home-range-form' : 'row g-1 align-items-end' }}" data-role="analytics-range" data-custom="{{ $isCustom ? '1' : '0' }}" @if($segmented) data-segmented="1" @endif @if($asyncRegion) data-async-form="{{ $asyncRegion }}" @endif>
    <div class="{{ $segmented ? 'visually-hidden' : 'col-md-3 col-sm-6' }}">
        <label class="form-label" for="range">Date range</label>
        <select id="range" @unless($segmented) name="range" @endunless class="form-select" data-role="range-preset" @if($segmented) inert @endif>
            @foreach(AnalyticsDateRange::SELECTABLE_PRESETS as $value)
                <option value="{{ $value }}" @selected($range->preset === $value)>{{ $presetLabels[$value] }}</option>
            @endforeach
        </select>
    </div>
    @if($segmented)
        {{-- Without JavaScript the Custom toggle cannot reveal anything, so the date fields and Apply are simply shown: an ordinary GET form. --}}
        <noscript><style>[data-role="custom-dates"][hidden] { display: block !important; } [data-role="range-custom-toggle"] { display: none !important; }</style></noscript>
        <div class="home-segmented" role="group" aria-label="Date range" data-role="range-segments">
            @foreach($segmentPresets as $value => $label)
                <button type="submit" name="range" value="{{ $value }}" class="{{ $range->preset === $value ? 'is-active' : '' }}" aria-pressed="{{ $range->preset === $value ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
            <button type="button" class="{{ $isCustom ? 'is-active' : '' }}" aria-pressed="{{ $isCustom ? 'true' : 'false' }}" data-role="range-custom-toggle">Custom</button>
        </div>
    @endif
    <div class="col-md-3 col-sm-6" data-role="custom-dates" @unless($isCustom) hidden @endunless>
        <label class="form-label" for="start">From</label>
        <input type="date" id="start" name="start" class="form-control" data-custom-field value="{{ old('start', $isCustom ? $range->startLocal->format('Y-m-d') : '') }}">
    </div>
    <div class="col-md-3 col-sm-6" data-role="custom-dates" @unless($isCustom) hidden @endunless>
        <label class="form-label" for="end">To</label>
        <input type="date" id="end" name="end" class="form-control" data-custom-field value="{{ old('end', $isCustom ? $range->endLocal->format('Y-m-d') : '') }}">
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

        // Whether the Custom fields are open. The segmented control keeps this in the form's own data-custom (server-rendered, flipped by the
        // Custom toggle); the full-size control still follows its select. Closed fields are also disabled, so they add nothing to a preset's URL,
        // and open ones are required, so Apply can never submit an empty custom range.
        function isCustom(form) {
            if (form.getAttribute('data-segmented') === '1') { return form.getAttribute('data-custom') === '1'; }
            var preset = form.querySelector('[data-role="range-preset"]');
            return !!preset && preset.value === 'custom';
        }

        function sync(form) {
            if (!form) { return; }
            var custom = isCustom(form);
            form.querySelectorAll('[data-role="custom-dates"]').forEach(function (el) { el.hidden = !custom; });
            if (form.getAttribute('data-segmented') === '1') {
                form.querySelectorAll('[data-custom-field]').forEach(function (input) { input.disabled = !custom; input.required = custom; });
                var toggle = form.querySelector('[data-role="range-custom-toggle"]');
                if (toggle) { toggle.setAttribute('aria-pressed', custom ? 'true' : 'false'); toggle.classList.toggle('is-open', custom); }
            }
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
            // Choosing a preset closes Custom for that request: its (possibly open) date fields are disabled, so they add nothing to the URL.
            var preset = event.target && event.target.closest ? event.target.closest('[data-role="range-segments"] button[type="submit"][value]') : null;
            if (preset && preset.value !== 'custom') {
                var owner = preset.form || preset.closest('form');
                if (owner && owner.getAttribute('data-segmented') === '1') { owner.setAttribute('data-custom', '0'); sync(owner); }
            }

            var toggle = event.target && event.target.closest ? event.target.closest('[data-role="range-custom-toggle"]') : null;
            if (!toggle) { return; }
            var form = toggle.form || toggle.closest('form');
            form.setAttribute('data-custom', isCustom(form) ? '0' : '1');
            sync(form);
        });
        // A page restored from history (back/forward, bfcache) shows exactly what the server rendered for its URL, plus any deliberate toggle.
        window.addEventListener('pageshow', function () { syncAll(document); });
        document.addEventListener('async-region:updated', function (event) { syncAll(event.target); });
        syncAll(document);
    })();
</script>
