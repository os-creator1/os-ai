{{--
    Overview period bar shared by Google Ads and Meta Ads: four plain GET links
    (switching only re-filters cached rows; it never calls the provider) and
    the visible date range. Expects $period, $periodLabels and $periodUrl
    (a closure taking a period key and returning its URL).
--}}
<div class="ads-period" data-role="period-selector">
    <nav class="cx-seg" aria-label="Period">
        @foreach($periodLabels as $key => $label)
            <a href="{{ $periodUrl($key) }}" class="{{ $period->key === $key ? 'is-active' : '' }}"
               @if($period->key === $key) aria-current="true" @endif
               data-period="{{ $key }}">{{ $label }}</a>
        @endforeach
    </nav>
    <span class="ads-period-range">
        <x-ds-icon name="calendar" size="14" aria-hidden="true" />
        <span data-role="period-range">{{ $period->from->format('M j') }} &ndash; {{ $period->to->format('M j, Y') }}</span>
    </span>
</div>
