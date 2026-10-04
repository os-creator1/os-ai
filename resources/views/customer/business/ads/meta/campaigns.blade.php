{{--
    Meta Ads Module V1 — Campaigns.

    Cached data only. Absent figures are an em dash, never 0. Campaign names
    are provider data and are escaped. No Meta id is rendered or linked:
    campaigns are addressed by their uid. Pause / resume are confirmed in a
    dialog that states exactly what changes, then POST to the mutation route.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Meta campaigns')

@php
    use App\Library\MetaAds\MetaAdsDisplay as D;

    $ready = $metaState === 'ready' && $result !== null;
    $currency = $account?->currency_code;
    $prefix = 'customer.workspaces.businesses.ads.meta.';
    $keep = $ready && $statusFilter !== null ? ['status' => $statusFilter] : [];
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Campaigns', 'subtitle' => 'How each campaign is performing, from your last Meta Ads update.', 'provider' => 'meta'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads.meta._empty-state')
    @else
        @include('customer.business.ads.meta._data-period', ['periodRoute' => 'campaigns.index', 'keep' => array_merge($keep, ['sort' => $sort, 'dir' => $direction])])

        <div class="d-flex gap-1 flex-wrap mb-2" data-role="status-filter" aria-label="Filter by status">
            @foreach([[null, 'All'], ['active', 'Active'], ['paused', 'Paused']] as [$value, $label])
                <a href="{{ route($prefix . 'campaigns.index', array_filter([$workspaceUid, $businessUid, 'period' => $period->key, 'status' => $value], fn ($v) => $v !== null)) }}"
                   class="btn btn-sm {{ $statusFilter === $value ? 'btn-primary' : 'btn-outline-secondary' }}" data-status-filter="{{ $value ?? 'all' }}">{{ $label }}</a>
            @endforeach
        </div>

        <x-card :padded="true">
            @if($result->total === 0)
                <x-empty-state icon="megaphone" title="No campaigns to show"
                               :description="$statusFilter !== null ? 'No campaigns match this filter.' : 'We have not found any campaigns in your Meta ad account yet. They appear here after the next update.'" data-role="no-campaigns" />
            @else
                @include('customer.business.ads.meta._data-result-note')

                <div class="table-responsive" data-role="campaigns-table-wrap">
                    <table class="table ds-table align-middle" data-role="campaigns-table">
                        @include('customer.business.ads.meta._data-head', [
                            'sortRoute' => 'campaigns.index',
                            'keep' => $keep,
                            'columns' => array_values(array_filter([
                                ['name', 'Campaign', false],
                                ['status', 'Status', false],
                                ['budget', 'Budget', true],
                                ['spend', 'Spend', true],
                                ['results', 'Results', true],
                                ['cost_per_result', 'Cost per result', true],
                                ['link_clicks', 'Link clicks', true],
                                ['impressions', 'Impressions', true],
                                $metaCanManage ? [null, 'Action', false] : null,
                            ])),
                        ])
                        <tbody>
                            @foreach($result->items as $row)
                                <tr data-role="campaign-row">
                                    <td>
                                        <a href="{{ route($prefix . 'campaigns.show', [$workspaceUid, $businessUid, $row->uid, 'period' => $period->key]) }}" data-role="campaign-link">{{ $row->name }}</a>
                                    </td>
                                    <td>@include('customer.business.ads.meta._data-status')</td>
                                    <td class="text-numeric text-end" data-role="campaign-budget">
                                        @if($row->dailyBudgetMinor !== null)
                                            {{ D::minorMoney($row->dailyBudgetMinor, $currency) }}<span class="text-caption text-muted"> / day</span>
                                        @elseif($row->lifetimeBudgetMinor !== null)
                                            {{ D::minorMoney($row->lifetimeBudgetMinor, $currency) }}<span class="text-caption text-muted"> lifetime</span>
                                        @else
                                            {{ D::DASH }}
                                        @endif
                                    </td>
                                    <td class="text-numeric text-end">{{ D::money($row->spendMicros(), $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::count($row->totals->resultsDisplay()) }}</td>
                                    <td class="text-numeric text-end">{{ D::money($row->costPerResultMicros(), $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::integer($row->totals->linkClicks) }}</td>
                                    <td class="text-numeric text-end">{{ D::integer($row->totals->impressions) }}</td>
                                    @if($metaCanManage)
                                        @include('customer.business.ads.meta._data-action-cell', ['kind' => 'campaign'])
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @include('customer.business.ads.meta._data-pager', ['pagerRoute' => 'campaigns.index', 'noun' => 'campaigns'])

                <p class="text-caption text-muted mt-1 mb-0">
                    Amounts are in your Meta ad account currency ({{ $currency }}). The budget is the one set in Meta Ads for the campaign, not your monthly target.
                </p>
            @endif
        </x-card>

        @if($metaCanConnect && ! $metaCanManage)
            <p class="text-caption text-muted mt-1" data-role="reconnect-hint">Reconnect Meta to allow pause/resume.</p>
        @endif

        @if($metaCanManage)
            @include('customer.business.ads.meta._data-actions', ['rows' => $result->items, 'kind' => 'campaign', 'from' => 'campaigns', 'returnQuery' => $returnQuery, 'returnCampaign' => null])
        @endif
    @endif
@endsection

@if($ready && $metaCanManage)
    @section('page-script')
        @include('customer.business.ads._once-script')
    @endsection
@endif
