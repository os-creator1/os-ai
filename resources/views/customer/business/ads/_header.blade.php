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
    // Meta Ads V1: a Meta page passes provider='meta' and carries $metaNav instead of $adsNav.
    $provider = $provider ?? 'google';
    $subNav = $provider === 'meta' ? ($metaNav ?? []) : ($adsNav ?? []);
    $currencyProviderLabel = $provider === 'meta' ? 'Meta ad account' : 'Google Ads account';
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
                <x-badge variant="neutral" data-role="ads-currency" title="Amounts are shown in your {{ $currencyProviderLabel }} currency">{{ $account->currency_code }}</x-badge>
            @endif
        </div>
    </div>
</div>

{{-- Provider switcher (Meta Ads V1, contract 24 §8): Overview | Google | Meta, each only when its
     route exists. `$provider` (include variable, default 'google') marks the active one; Meta pages
     pass 'meta', the cross-channel Overview 'overview'. Hidden below two providers. --}}
@php
    $adsProviders = $adsProviders ?? [];
@endphp
@if(count($adsProviders) > 1)
    <nav aria-label="Ads channels" class="mb-1" data-role="ads-provider-nav">
        <div class="d-flex gap-1 flex-nowrap overflow-auto pb-50">
            @foreach($adsProviders as $providerItem)
                <a href="{{ $providerItem['url'] }}"
                   class="btn btn-sm {{ $providerItem['key'] === $provider ? 'btn-primary' : 'btn-flat-secondary' }} text-nowrap flex-shrink-0"
                   @if($providerItem['key'] === $provider) aria-current="page" @endif
                   data-provider="{{ $providerItem['key'] }}">{{ $providerItem['label'] }}</a>
            @endforeach
        </div>
    </nav>
@endif

@if(count($subNav) > 1)
    <nav aria-label="Ads sections" class="mb-2" data-role="ads-nav">
        <div class="d-flex gap-1 flex-nowrap overflow-auto pb-50">
            @foreach($subNav as $navItem)
                <a href="{{ $navItem['url'] }}"
                   class="btn btn-sm {{ $navItem['active'] ? 'btn-primary' : 'btn-flat-secondary' }} text-nowrap flex-shrink-0"
                   @if($navItem['active']) aria-current="page" @endif
                   data-nav="{{ $navItem['key'] }}">{{ $navItem['label'] }}</a>
            @endforeach
        </div>
    </nav>
@endif

@if($showFreshness && $account !== null && $freshness !== null)
    @include($provider === 'meta' ? 'customer.business.ads.meta._freshness' : 'customer.business.ads._freshness', ['freshness' => $freshness])
@endif

{{-- Compact data tables: Ads tables carry up to ten numeric columns, so tighten padding and let headers wrap
     instead of forcing a horizontal scroll at laptop widths. Scoped to pages that render this header. --}}
<style>
    body:has([data-role="ads-title"]) .table-responsive > .table th,
    body:has([data-role="ads-title"]) .table-responsive > .table td {
        padding-left: .6rem;
        padding-right: .6rem;
    }
    body:has([data-role="ads-title"]) .table-responsive > .table th {
        white-space: normal !important;
        line-height: 1.25;
        vertical-align: bottom;
    }
    body:has([data-role="ads-title"]) .table-responsive > .table td.text-end,
    body:has([data-role="ads-title"]) .table-responsive > .table td[data-col] {
        white-space: nowrap;
    }
</style>
