{{--
    Google Ads Module V1 — the period selector shared by the data pages: four
    plain GET links (changing the period only re-filters cached rows; it never
    calls Google). Filters the page already applies are kept; the page number
    is reset.

        @include('customer.business.ads._period-selector', [
            'periodRoute' => 'campaigns.index',     // route name under customer.workspaces.businesses.ads.
            'routeExtra' => [],                      // extra positional route parameters (e.g. a campaign uid)
            'keep' => ['status' => 'enabled'],       // filters/sort to carry across periods
        ])
--}}
@php
    use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;

    $periodLabels = [
        GoogleAdsPeriod::LAST_7 => '7 days',
        GoogleAdsPeriod::LAST_30 => '30 days',
        GoogleAdsPeriod::THIS_MONTH => 'This month',
        GoogleAdsPeriod::PREVIOUS_MONTH => 'Previous month',
    ];
    $keep = $keep ?? [];
    $routeExtra = $routeExtra ?? [];
@endphp

<div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2" data-role="period-selector">
    <div class="btn-group" role="group" aria-label="Period">
        @foreach($periodLabels as $key => $label)
            <a href="{{ route('customer.workspaces.businesses.ads.' . $periodRoute, array_merge([$workspaceUid, $businessUid], $routeExtra, $keep, ['period' => $key])) }}"
               class="btn btn-sm {{ $period->key === $key ? 'btn-primary' : 'btn-outline-secondary' }}"
               @if($period->key === $key) aria-current="true" @endif
               data-period="{{ $key }}">{{ $label }}</a>
        @endforeach
    </div>
    <span class="text-caption text-muted" data-role="period-range">{{ $period->from->format('M j') }} &ndash; {{ $period->to->format('M j, Y') }}</span>
</div>
