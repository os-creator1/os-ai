{{--
    Meta Ads Module V1 — Ads.

    Cached data only. The creative preview shows the already-validated
    thumbnail (https + allow-listed host, decided by MetaAdsAdReader) and the
    creative title / body, which are raw customer text and escaped. No Meta id
    is rendered. A delivery problem is Meta's own wording, quoted.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Meta ads')

@php
    use App\Library\MetaAds\MetaAdsDisplay as D;

    $ready = $metaState === 'ready' && $result !== null;
    $currency = $account?->currency_code;
    $prefix = 'customer.workspaces.businesses.ads.meta.';
    $keep = [];
    if ($ready && $statusFilter !== null) { $keep['status'] = $statusFilter; }
    if ($ready && $campaignFilter !== null) { $keep['campaign'] = $campaignFilter; }
    if ($ready && $adSetFilter !== null) { $keep['ad_set'] = $adSetFilter; }
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Ads', 'subtitle' => 'Each ad and how it is performing, from your last Meta Ads update.', 'provider' => 'meta'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads.meta._empty-state')
    @else
        @include('customer.business.ads.meta._data-period', ['periodRoute' => 'ads.index', 'keep' => array_merge($keep, ['sort' => $sort, 'dir' => $direction])])

        <form method="GET" action="{{ route($prefix . 'ads.index', [$workspaceUid, $businessUid]) }}" class="d-flex gap-1 flex-wrap align-items-center mb-2" data-role="filters">
            <input type="hidden" name="period" value="{{ $period->key }}">
            <label class="visually-hidden" for="ads-campaign">Campaign</label>
            <select id="ads-campaign" name="campaign" class="form-select form-select-sm w-auto" data-role="campaign-filter">
                <option value="">All campaigns</option>
                @foreach($campaignOptions as $uid => $name)
                    <option value="{{ $uid }}" @selected($campaignFilter === $uid)>{{ $name }}</option>
                @endforeach
            </select>
            <label class="visually-hidden" for="ads-ad-set">Ad set</label>
            <select id="ads-ad-set" name="ad_set" class="form-select form-select-sm w-auto" data-role="ad-set-filter">
                <option value="">All ad sets</option>
                @foreach($adSetOptions as $uid => $name)
                    <option value="{{ $uid }}" @selected($adSetFilter === $uid)>{{ $name }}</option>
                @endforeach
            </select>
            <label class="visually-hidden" for="ads-status">Status</label>
            <select id="ads-status" name="status" class="form-select form-select-sm w-auto" data-role="status-filter">
                <option value="">All statuses</option>
                <option value="active" @selected($statusFilter === 'active')>Active</option>
                <option value="paused" @selected($statusFilter === 'paused')>Paused</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
        </form>

        <x-card :padded="true">
            @if($result->total === 0)
                <x-empty-state icon="megaphone" title="No ads to show"
                               :description="($statusFilter !== null || $campaignFilter !== null || $adSetFilter !== null) ? 'No ads match these filters.' : 'We have not found any ads in your Meta ad account yet. They appear here after the next update.'" data-role="no-ads" />
            @else
                @include('customer.business.ads.meta._data-result-note')

                <div class="table-responsive" data-role="ads-table-wrap">
                    <table class="table ds-table align-middle" data-role="ads-table">
                        @include('customer.business.ads.meta._data-head', [
                            'sortRoute' => 'ads.index',
                            'keep' => $keep,
                            'columns' => array_values(array_filter([
                                ['name', 'Ad', false],
                                ['ad_set', 'Ad set / Campaign', false],
                                ['status', 'Status', false],
                                ['spend', 'Spend', true],
                                ['impressions', 'Impressions', true],
                                ['link_clicks', 'Link clicks', true],
                                ['results', 'Results', true],
                                ['cost_per_result', 'Cost per result', true],
                                $metaCanManage ? [null, 'Action', false] : null,
                            ])),
                        ])
                        <tbody>
                            @foreach($result->items as $row)
                                <tr data-role="ad-row">
                                    <td style="min-width: 14rem;">
                                        @include('customer.business.ads.meta._data-creative', ['creative' => $row->creative, 'name' => $row->name])
                                    </td>
                                    <td>
                                        <div data-role="ad-set-name">{{ $row->adSetName }}</div>
                                        <a class="text-caption" href="{{ route($prefix . 'campaigns.show', [$workspaceUid, $businessUid, $row->campaignUid, 'period' => $period->key]) }}" data-role="campaign-link">{{ $row->campaignName }}</a>
                                    </td>
                                    <td>@include('customer.business.ads.meta._data-status')</td>
                                    <td class="text-numeric text-end">{{ D::money($row->spendMicros(), $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::integer($row->totals->impressions) }}</td>
                                    <td class="text-numeric text-end">{{ D::integer($row->totals->linkClicks) }}</td>
                                    <td class="text-numeric text-end">{{ D::count($row->totals->resultsDisplay()) }}</td>
                                    <td class="text-numeric text-end">{{ D::money($row->costPerResultMicros(), $currency) }}</td>
                                    @if($metaCanManage)
                                        @include('customer.business.ads.meta._data-action-cell', ['kind' => 'ad'])
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @include('customer.business.ads.meta._data-pager', ['pagerRoute' => 'ads.index', 'noun' => 'ads'])

                <p class="text-caption text-muted mt-1 mb-0">Amounts are in your Meta ad account currency ({{ $currency }}).</p>
            @endif
        </x-card>

        @if($metaCanConnect && ! $metaCanManage)
            <p class="text-caption text-muted mt-1" data-role="reconnect-hint">Reconnect Meta to allow pause/resume.</p>
        @endif

        @if($metaCanManage)
            @include('customer.business.ads.meta._data-actions', ['rows' => $result->items, 'kind' => 'ad', 'from' => 'ads', 'returnQuery' => $returnQuery, 'returnCampaign' => null])
        @endif
    @endif
@endsection

@if($ready && $metaCanManage)
    @section('page-script')
        @include('customer.business.ads._once-script')
    @endsection
@endif
