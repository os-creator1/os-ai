{{--
    Google Ads Module V1 — the SHARED page header every Ads page includes:
    title, optional subtitle, the account currency (shown once, here), the
    in-page sub-navigation and — on every data page — the freshness line.

    Usage (later Ads pages reuse it unchanged):

        @include('customer.business.ads._header', [
            'title' => 'Campaigns',
            'subtitle' => 'Optional one-line description.',   // optional
            'showFreshness' => true,                            // default true
        ])

    It reads the variables ResolvesAdsBusinessTenancy::adsViewData() already
    provides to the page: $business, $account, $freshness, $adsNav. Nothing
    here is rendered unescaped, and no token or credential is ever available
    to it.
--}}
@php
    $showFreshness = $showFreshness ?? true;
    $subtitle = $subtitle ?? null;
@endphp

<div class="row mb-1">
    <div class="col-12 d-flex justify-content-between align-items-start flex-wrap gap-1">
        <div>
            <h1 class="h3 mb-0" data-role="ads-title">{{ $title }}</h1>
            @if($subtitle)
                <p class="text-caption text-muted mb-0" data-role="ads-subtitle">{{ $subtitle }}</p>
            @endif
        </div>
        <div class="text-end">
            <span class="text-caption d-block" data-role="ads-business">{{ $business->name }}</span>
            @if($account !== null && $account->currency_code)
                <x-badge variant="neutral" data-role="ads-currency" title="Amounts are shown in your Google Ads account currency">{{ $account->currency_code }}</x-badge>
            @endif
        </div>
    </div>
</div>

@if(count($adsNav) > 1)
    <nav aria-label="Ads sections" class="mb-2" data-role="ads-nav">
        <div class="d-flex gap-1 flex-nowrap overflow-auto pb-50">
            @foreach($adsNav as $navItem)
                <a href="{{ $navItem['url'] }}"
                   class="btn btn-sm {{ $navItem['active'] ? 'btn-primary' : 'btn-flat-secondary' }} text-nowrap"
                   @if($navItem['active']) aria-current="page" @endif
                   data-nav="{{ $navItem['key'] }}">{{ $navItem['label'] }}</a>
            @endforeach
        </div>
    </nav>
@endif

@if($showFreshness && $account !== null && $freshness !== null)
    @include('customer.business.ads._freshness', ['freshness' => $freshness])
@endif
