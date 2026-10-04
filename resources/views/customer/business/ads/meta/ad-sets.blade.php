{{--
    Meta Ads Module V1 — Ad sets.

    Cached data only; absent figures are a dash. Ad set / campaign names and
    the audience summary (targeting_summary) are provider data and escaped. No
    Meta id is rendered. Frequency is the ad set's own last-7-days figure,
    labelled as such: it is never summed or averaged across days.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Meta ad sets')

@php
    use App\Library\MetaAds\MetaAdsDisplay as D;

    $ready = $metaState === 'ready' && $result !== null;
    $currency = $account?->currency_code;
    $prefix = 'customer.workspaces.businesses.ads.meta.';
    $keep = [];
    if ($ready && $statusFilter !== null) { $keep['status'] = $statusFilter; }
    if ($ready && $campaignFilter !== null) { $keep['campaign'] = $campaignFilter; }
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Ad sets', 'subtitle' => 'Budget, audience and results for each ad set, from your last Meta Ads update.', 'provider' => 'meta'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads.meta._empty-state')
    @else
        @include('customer.business.ads.meta._data-period', ['periodRoute' => 'ad-sets.index', 'keep' => array_merge($keep, ['sort' => $sort, 'dir' => $direction])])

        <form method="GET" action="{{ route($prefix . 'ad-sets.index', [$workspaceUid, $businessUid]) }}" class="d-flex gap-1 flex-wrap align-items-center mb-2" data-role="filters">
            <input type="hidden" name="period" value="{{ $period->key }}">
            <label class="visually-hidden" for="ad-sets-campaign">Campaign</label>
            <select id="ad-sets-campaign" name="campaign" class="form-select form-select-sm w-auto" data-role="campaign-filter">
                <option value="">All campaigns</option>
                @foreach($campaignOptions as $uid => $name)
                    <option value="{{ $uid }}" @selected($campaignFilter === $uid)>{{ $name }}</option>
                @endforeach
            </select>
            <label class="visually-hidden" for="ad-sets-status">Status</label>
            <select id="ad-sets-status" name="status" class="form-select form-select-sm w-auto" data-role="status-filter">
                <option value="">All statuses</option>
                <option value="active" @selected($statusFilter === 'active')>Active</option>
                <option value="paused" @selected($statusFilter === 'paused')>Paused</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
        </form>

        <x-card :padded="true">
            @if($result->total === 0)
                <x-empty-state icon="megaphone" title="No ad sets to show"
                               :description="($statusFilter !== null || $campaignFilter !== null) ? 'No ad sets match these filters.' : 'We have not found any ad sets in your Meta ad account yet. They appear here after the next update.'" data-role="no-ad-sets" />
            @else
                @include('customer.business.ads.meta._data-result-note')

                <div class="table-responsive" data-role="ad-sets-table-wrap">
                    <table class="table ds-table align-middle" data-role="ad-sets-table">
                        @include('customer.business.ads.meta._data-head', [
                            'sortRoute' => 'ad-sets.index',
                            'keep' => $keep,
                            'columns' => array_values(array_filter([
                                ['name', 'Ad set', false],
                                ['campaign', 'Campaign', false],
                                ['status', 'Status', false],
                                ['budget', 'Budget', true],
                                [null, 'Audience summary', false],
                                ['spend', 'Spend', true],
                                ['results', 'Results', true],
                                ['cost_per_result', 'Cost per result', true],
                                ['frequency', 'Frequency (last 7 days)', true],
                                $metaCanManage ? [null, 'Action', false] : null,
                            ])),
                        ])
                        <tbody>
                            @foreach($result->items as $row)
                                <tr data-role="ad-set-row">
                                    <td>{{ $row->name }}</td>
                                    <td>
                                        <a href="{{ route($prefix . 'campaigns.show', [$workspaceUid, $businessUid, $row->campaignUid, 'period' => $period->key]) }}" data-role="campaign-link">{{ $row->campaignName }}</a>
                                    </td>
                                    <td>@include('customer.business.ads.meta._data-status')</td>
                                    <td class="text-numeric text-end" data-role="ad-set-budget">
                                        @if($row->dailyBudgetMinor !== null)
                                            {{ D::minorMoney($row->dailyBudgetMinor, $currency) }}<span class="text-caption text-muted"> / day</span>
                                        @elseif($row->lifetimeBudgetMinor !== null)
                                            {{ D::minorMoney($row->lifetimeBudgetMinor, $currency) }}<span class="text-caption text-muted"> lifetime</span>
                                        @else
                                            {{ D::DASH }}
                                        @endif
                                    </td>
                                    <td class="text-caption" data-role="audience-summary" style="min-width: 12rem;">{{ $row->targetingSummary ?? D::DASH }}</td>
                                    <td class="text-numeric text-end">{{ D::money($row->spendMicros(), $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::count($row->totals->resultsDisplay()) }}</td>
                                    <td class="text-numeric text-end">{{ D::money($row->costPerResultMicros(), $currency) }}</td>
                                    <td class="text-numeric text-end" data-role="ad-set-frequency">{{ D::frequency($row->frequency7d) }}</td>
                                    @if($metaCanManage)
                                        @include('customer.business.ads.meta._data-action-cell', ['kind' => 'ad_set'])
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @include('customer.business.ads.meta._data-pager', ['pagerRoute' => 'ad-sets.index', 'noun' => 'ad sets'])

                <p class="text-caption text-muted mt-1 mb-0">
                    Frequency is how many times each person saw the ad set in its last 7 days; Meta reports it for that window only. Amounts are in {{ $currency }}.
                </p>
            @endif
        </x-card>

        @if($metaCanConnect && ! $metaCanManage)
            <p class="text-caption text-muted mt-1" data-role="reconnect-hint">Reconnect Meta to allow pause/resume.</p>
        @endif

        @if($metaCanManage)
            @include('customer.business.ads.meta._data-actions', ['rows' => $result->items, 'kind' => 'ad_set', 'from' => 'ad-sets', 'returnQuery' => $returnQuery, 'returnCampaign' => null])
        @endif
    @endif
@endsection

@if($ready && $metaCanManage)
    @section('page-script')
        @include('customer.business.ads._once-script')
    @endsection
@endif
