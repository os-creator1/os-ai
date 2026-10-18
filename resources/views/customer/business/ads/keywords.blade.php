{{--
    Google Ads Module V1 — Keywords.

    Cached data only; absent figures are a dash, never 0. Keyword text and
    campaign / ad group names are provider data and escaped. The Quality Score
    column appears only when Google returned a score for at least one row shown.
    Pause / resume apply to positive ad-group keywords only (the service
    validates) and are confirmed first. The negative-keyword list is separate,
    collapsed and read-only.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Ads keywords')

@php
    use App\Enums\GoogleAds\GoogleAdsEntityStatus;
    use App\Library\GoogleAds\GoogleAdsDisplay as D;

    $ready = $adsState === 'ready' && $result !== null;
    $currency = $account?->currency_code;
    $prefix = 'customer.workspaces.businesses.ads.';
    $keep = [];

    if ($ready) {
        $keep = array_filter(['status' => $statusFilter, 'campaign' => $campaignFilter], fn ($v) => $v !== null);
    }
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Keywords', 'subtitle' => 'Which keywords are bringing results, from your last Google Ads update.'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads._empty-state')
    @else
        @include('customer.business.ads._period-selector', ['periodRoute' => 'keywords.index', 'keep' => array_merge($keep, ['sort' => $sort, 'dir' => $direction])])

        <form method="GET" action="{{ route($prefix . 'keywords.index', [$workspaceUid, $businessUid]) }}" class="row g-1 align-items-end mb-2" data-role="keyword-filters">
            <input type="hidden" name="period" value="{{ $period->key }}">
            <div class="col-12 col-md-5">
                <label for="keyword-campaign" class="form-label text-label">Campaign</label>
                <select name="campaign" id="keyword-campaign" class="form-select form-select-sm">
                    <option value="">All campaigns</option>
                    @foreach($campaignOptions as $uid => $name)
                        <option value="{{ $uid }}" @selected($campaignFilter === $uid)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-8 col-md-4">
                <label for="keyword-status" class="form-label text-label">Status</label>
                <select name="status" id="keyword-status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="enabled" @selected($statusFilter === 'enabled')>Enabled</option>
                    <option value="paused" @selected($statusFilter === 'paused')>Paused</option>
                </select>
            </div>
            <div class="col-4 col-md-3">
                <button type="submit" class="btn btn-sm btn-outline-secondary w-100" data-role="apply-filters">Apply</button>
            </div>
        </form>

        <x-card :padded="true" class="mb-2">
            @if($result->total === 0)
                <x-empty-state icon="hash" title="No keywords to show"
                               :description="$statusFilter !== null || $campaignFilter !== null ? 'No keywords match these filters.' : 'We have not found any keywords in your Google Ads account yet. They appear here after the next update.'" data-role="no-keywords" />
            @else
                <div class="table-responsive" data-role="keywords-table-wrap">
                    <table class="table ds-table align-middle" data-role="keywords-table">
                        @include('customer.business.ads._table-head', [
                            'sortRoute' => 'keywords.index',
                            'keep' => $keep,
                            'columns' => array_values(array_filter([
                                ['keyword', 'Keyword', false],
                                [null, 'Match type', false],
                                ['campaign', 'Campaign / ad group', false],
                                ['status', 'Status', false],
                                ['spend', 'Spend', true],
                                ['clicks', 'Clicks', true],
                                ['conversions', 'Conversions', true],
                                ['cpl', 'Cost per conversion', true],
                                ['conversion_rate', 'Conv. rate', true],
                                $hasQualityScore ? ['quality_score', 'Quality Score', true] : null,
                                $adsCanManage ? [null, 'Action', false] : null,
                            ])),
                        ])
                        <tbody>
                            @foreach($result->items as $row)
                                <tr data-role="keyword-row">
                                    <td>
                                        {{ $row->text }}
                                        @if($pending[$row->uid] ?? false)
                                            <x-badge variant="warning" data-role="pending-confirmation">Pending confirmation</x-badge>
                                        @endif
                                    </td>
                                    <td>{{ D::matchLabel($row->matchType) }}</td>
                                    <td>
                                        <a href="{{ route($prefix . 'campaigns.show', [$workspaceUid, $businessUid, $row->campaignUid, 'period' => $period->key]) }}">{{ $row->campaignName }}</a>
                                        @if($row->adGroupName)<span class="d-block text-caption text-muted">{{ $row->adGroupName }}</span>@endif
                                    </td>
                                    <td><x-badge :variant="D::statusVariant($row->status)" data-role="keyword-status">{{ D::statusLabel($row->status) }}</x-badge></td>
                                    <td class="text-numeric text-end">{{ D::money($row->totals->spendMicros, $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::integer($row->totals->clicks) }}</td>
                                    <td class="text-numeric text-end">{{ D::count($row->totals->conversionsDisplay()) }}</td>
                                    <td class="text-numeric text-end">{{ D::money($row->cplMicros(), $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::percent($row->conversionRate()) }}</td>
                                    @if($hasQualityScore)
                                        <td class="text-numeric text-end" data-role="quality-score">{{ $row->qualityScore === null ? '—' : $row->qualityScore . '/10' }}</td>
                                    @endif
                                    @if($adsCanManage)
                                        <td class="text-nowrap">
                                            @if($row->status === GoogleAdsEntityStatus::Enabled)
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ads-confirm-{{ $row->uid }}" data-role="pause-keyword">Pause</button>
                                            @elseif($row->status === GoogleAdsEntityStatus::Paused)
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ads-confirm-{{ $row->uid }}" data-role="resume-keyword">Resume</button>
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
                        <span class="text-caption">Page {{ $result->page }} of {{ $result->lastPage }} &middot; {{ number_format($result->total) }} keywords</span>
                        <span class="d-flex gap-1">
                            @if($result->page > 1)
                                <a class="btn btn-sm btn-outline-secondary" rel="prev" href="{{ route($prefix . 'keywords.index', array_merge([$workspaceUid, $businessUid], $keep, ['period' => $period->key, 'sort' => $sort, 'dir' => $direction, 'page' => $result->page - 1])) }}">Previous</a>
                            @endif
                            @if($result->page < $result->lastPage)
                                <a class="btn btn-sm btn-outline-secondary" rel="next" href="{{ route($prefix . 'keywords.index', array_merge([$workspaceUid, $businessUid], $keep, ['period' => $period->key, 'sort' => $sort, 'dir' => $direction, 'page' => $result->page + 1])) }}">Next</a>
                            @endif
                        </span>
                    </nav>
                @endif
            @endif
        </x-card>

        <x-card :padded="true" data-role="negative-keywords">
            <details>
                <summary class="text-section-heading">Negative keywords ({{ count($negatives) }})</summary>
                @if(count($negatives) === 0)
                    <p class="text-caption mt-1 mb-0" data-role="no-negatives">No negative keywords found in your account.</p>
                @else
                    <p class="text-caption text-muted mt-1">These stop ads showing for matching searches. They are listed here for reference; add new ones from the Search terms page.</p>
                    <x-table :headers="['Negative keyword', 'Match type', 'Applies to', 'Campaign / ad group', 'Status']">
                        @foreach($negatives as $negative)
                            <tr data-role="negative-row">
                                <td>{{ $negative->text }}</td>
                                <td>{{ D::matchLabel($negative->matchType) }}</td>
                                <td>{{ $negative->level->value === 'ad_group' ? 'Ad group' : 'Campaign' }}</td>
                                <td>{{ $negative->campaignName }}@if($negative->adGroupName) <span class="text-caption text-muted">/ {{ $negative->adGroupName }}</span>@endif</td>
                                <td><x-badge :variant="D::statusVariant($negative->status)">{{ D::statusLabel($negative->status) }}</x-badge></td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </details>
        </x-card>

        @if($adsCanManage)
            @foreach($result->items as $row)
                @if($row->status === GoogleAdsEntityStatus::Enabled || $row->status === GoogleAdsEntityStatus::Paused)
                    @php $pausing = $row->status === GoogleAdsEntityStatus::Enabled; @endphp
                    @include('customer.business.ads._confirm-dialog', [
                        'dialogId' => 'ads-confirm-' . $row->uid,
                        'action' => route($prefix . ($pausing ? 'keywords.pause' : 'keywords.resume'), [$workspaceUid, $businessUid, $row->uid]),
                        'title' => $pausing ? 'Pause keyword' : 'Resume keyword',
                        'question' => ($pausing ? 'Pause keyword "' : 'Resume keyword "') . $row->text . '"?',
                        'consequence' => $pausing
                            ? 'It will stop triggering ads in ' . $row->campaignName . ' until resumed.'
                            : 'It will start triggering ads again in ' . $row->campaignName . '.',
                        'confirmLabel' => $pausing ? 'Pause keyword' : 'Resume keyword',
                        'from' => 'keywords',
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
