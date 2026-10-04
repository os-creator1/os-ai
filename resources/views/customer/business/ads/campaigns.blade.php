{{--
    Google Ads Module V1 — Campaigns.

    Cached data only. Absent figures are an em dash, never 0. Campaign names
    are provider data and are escaped. No Google id is rendered or linked:
    campaigns are addressed by their uid. Pause / resume are confirmed in a
    dialog that states exactly what changes, then POST to the mutation route.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Ads campaigns')

@php
    use App\Enums\GoogleAds\GoogleAdsEntityStatus;
    use App\Library\GoogleAds\GoogleAdsDisplay as D;

    $ready = $adsState === 'ready' && $result !== null;
    $currency = $account?->currency_code;
    $prefix = 'customer.workspaces.businesses.ads.';
    $keep = $ready && $statusFilter !== null ? ['status' => $statusFilter] : [];
    $hasValue = $ready && collect($result->items)->contains(fn ($row) => $row->totals->conversionValue() !== null);
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Campaigns', 'subtitle' => 'How each campaign is performing, from your last Google Ads update.'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads._empty-state')
    @else
        @include('customer.business.ads._period-selector', ['periodRoute' => 'campaigns.index', 'keep' => array_merge($keep, ['sort' => $sort, 'dir' => $direction])])

        <div class="d-flex gap-1 flex-wrap mb-2" data-role="status-filter" aria-label="Filter by status">
            @foreach([[null, 'All'], ['enabled', 'Enabled'], ['paused', 'Paused']] as [$value, $label])
                <a href="{{ route($prefix . 'campaigns.index', array_filter([$workspaceUid, $businessUid, 'period' => $period->key, 'status' => $value], fn ($v) => $v !== null)) }}"
                   class="btn btn-sm {{ $statusFilter === $value ? 'btn-primary' : 'btn-outline-secondary' }}" data-status-filter="{{ $value ?? 'all' }}">{{ $label }}</a>
            @endforeach
        </div>

        <x-card :padded="true">
            @if($result->total === 0)
                <x-empty-state icon="megaphone" title="No campaigns to show"
                               :description="$statusFilter !== null ? 'No campaigns match this filter.' : 'We have not found any campaigns in your Google Ads account yet. They appear here after the next update.'" data-role="no-campaigns" />
            @else
                <div class="table-responsive" data-role="campaigns-table-wrap">
                    <table class="table ds-table align-middle" data-role="campaigns-table">
                        @include('customer.business.ads._table-head', [
                            'sortRoute' => 'campaigns.index',
                            'keep' => $keep,
                            'columns' => array_values(array_filter([
                                ['name', 'Campaign', false],
                                ['status', 'Status', false],
                                ['budget', 'Daily budget', true],
                                ['spend', 'Spend', true],
                                ['clicks', 'Clicks', true],
                                ['conversions', 'Conversions', true],
                                ['cpl', 'Cost per conversion', true],
                                ['conversion_rate', 'Conv. rate', true],
                                $hasValue ? ['conversion_value', 'Value (Google)', true] : null,
                                $adsCanManage ? [null, 'Action', false] : null,
                            ])),
                        ])
                        <tbody>
                            @foreach($result->items as $row)
                                <tr data-role="campaign-row">
                                    <td>
                                        <a href="{{ route($prefix . 'campaigns.show', [$workspaceUid, $businessUid, $row->uid, 'period' => $period->key]) }}" data-role="campaign-link">{{ $row->name }}</a>
                                        @if($pending[$row->uid] ?? false)
                                            <x-badge variant="warning" data-role="pending-confirmation">Pending confirmation</x-badge>
                                        @endif
                                    </td>
                                    <td><x-badge :variant="D::statusVariant($row->status)" data-role="campaign-status">{{ D::statusLabel($row->status) }}</x-badge></td>
                                    <td class="text-numeric text-end">
                                        {{ D::money($row->dailyBudgetMicros, $currency) }}
                                        @if($row->budgetShared)<x-badge variant="accent">Shared</x-badge>@endif
                                    </td>
                                    <td class="text-numeric text-end">{{ D::money($row->spendMicros(), $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::integer($row->totals->clicks) }}</td>
                                    <td class="text-numeric text-end">{{ D::count($row->totals->conversionsDisplay()) }}</td>
                                    <td class="text-numeric text-end">{{ D::money($row->cplMicros(), $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::percent($row->conversionRate()) }}</td>
                                    @if($hasValue)
                                        <td class="text-numeric text-end">{{ D::decimalMoney($row->totals->conversionValue(), $currency) }}</td>
                                    @endif
                                    @if($adsCanManage)
                                        <td class="text-nowrap">
                                            @if($row->status === GoogleAdsEntityStatus::Enabled)
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ads-confirm-{{ $row->uid }}" data-role="pause-campaign">Pause</button>
                                            @elseif($row->status === GoogleAdsEntityStatus::Paused)
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ads-confirm-{{ $row->uid }}" data-role="resume-campaign">Resume</button>
                                            @else
                                                <span class="text-caption text-muted">—</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($result->lastPage > 1)
                    <nav class="d-flex justify-content-between align-items-center mt-1" aria-label="Pagination" data-role="pagination">
                        <span class="text-caption">Page {{ $result->page }} of {{ $result->lastPage }} &middot; {{ number_format($result->total) }} campaigns</span>
                        <span class="d-flex gap-1">
                            @if($result->page > 1)
                                <a class="btn btn-sm btn-outline-secondary" rel="prev" href="{{ route($prefix . 'campaigns.index', array_merge([$workspaceUid, $businessUid], $keep, ['period' => $period->key, 'sort' => $sort, 'dir' => $direction, 'page' => $result->page - 1])) }}">Previous</a>
                            @endif
                            @if($result->page < $result->lastPage)
                                <a class="btn btn-sm btn-outline-secondary" rel="next" href="{{ route($prefix . 'campaigns.index', array_merge([$workspaceUid, $businessUid], $keep, ['period' => $period->key, 'sort' => $sort, 'dir' => $direction, 'page' => $result->page + 1])) }}">Next</a>
                            @endif
                        </span>
                    </nav>
                @endif

                <p class="text-caption text-muted mt-1 mb-0">
                    Conversions are Google's count. The daily budget is the amount set in Google Ads, not your monthly target.
                </p>
            @endif
        </x-card>

        @if($adsCanManage)
            @foreach($result->items as $row)
                @if($row->status === GoogleAdsEntityStatus::Enabled || $row->status === GoogleAdsEntityStatus::Paused)
                    @php $pausing = $row->status === GoogleAdsEntityStatus::Enabled; @endphp
                    @include('customer.business.ads._confirm-dialog', [
                        'dialogId' => 'ads-confirm-' . $row->uid,
                        'action' => route($prefix . ($pausing ? 'campaigns.pause' : 'campaigns.resume'), [$workspaceUid, $businessUid, $row->uid]),
                        'title' => $pausing ? 'Pause campaign' : 'Resume campaign',
                        'question' => ($pausing ? 'Pause campaign ' : 'Resume campaign ') . $row->name . '?',
                        'consequence' => $pausing
                            ? 'It will stop showing ads in Google Ads until resumed.'
                            : 'It will start showing ads again in Google Ads.',
                        'confirmLabel' => $pausing ? 'Pause campaign' : 'Resume campaign',
                        'from' => 'campaigns',
                        'returnQuery' => $returnQuery,
                        'returnCampaign' => null,
                    ])
                @endif
            @endforeach
        @endif
    @endif
@endsection

@if($ready && $adsCanManage)
    @section('page-script')
        @include('customer.business.ads._once-script')
    @endsection
@endif
