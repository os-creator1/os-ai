{{--
    Search keywords — the rank-tracking dashboard (SEO KEYWORD RANK TRACKING V1).

    Two different things are shown and kept visibly apart:
      * an SEO target: a phrase the Business wants to be found for (up to 50),
        with its Website coverage — free;
      * a rank-tracked keyword: that phrase + a search location, checked on
        Google by a paid provider within a monthly allowance.

    Every number is real or "—". Absence is never a rank: "Not in top 100" and
    "Not matched" are words, never 0 or 101. Provider errors and secrets are
    never shown; only closed states (Checking, Updated, Temporarily
    unavailable, Budget paused).

    Escaped Blade output only. Raw, unescaped output is forbidden in this view.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Search keywords')

@php
    use App\Enums\Seo\SeoKeywordCoverageStatus;
    use App\Library\Seo\Rank\SeoRankDashboardReader;

    $summary = $rank['summary'];
    $rows = $rank['rows'];
    $archived = $keywords->reject(fn ($k) => $k->isActive());
    $slotsFull = $rankPlan !== null && $summary['slots_used'] >= $rankPlan->trackedTargets;
    $dash = '—';
@endphp

@section('content')
    <style>
        .rank-summary-card .rank-summary-value { font-size: 1.6rem; line-height: 1.2; font-weight: 600; }
        .rank-table td, .rank-table th { vertical-align: middle; }
        .rank-table tr[data-href] { cursor: pointer; }
        @media (max-width: 767.98px) {
            .rank-table thead { display: none; }
            .rank-table, .rank-table tbody, .rank-table tr, .rank-table td { display: block; width: 100%; }
            .rank-table tr { border: 1px solid var(--bs-border-color, #e5e5e5); border-radius: .5rem; margin-bottom: .75rem; padding: .5rem .75rem; }
            .rank-table td { display: flex; justify-content: space-between; gap: 1rem; border: 0; padding: .25rem 0; text-align: right; }
            .rank-table td::before { content: attr(data-label); font-weight: 600; text-align: left; color: var(--bs-secondary-color, #6e6b7b); }
            .rank-table td[data-label="Keyword"] { display: block; text-align: left; }
            .rank-table td[data-label="Keyword"]::before { display: none; }
            .rank-table td[data-label="Actions"] { justify-content: flex-start; flex-wrap: wrap; }
            .rank-table td[data-label="Actions"]::before { display: none; }
        }
    </style>

    <div class="row mb-1">
        <div class="col-12 d-flex justify-content-between align-items-start flex-wrap gap-1">
            <div>
                <h4 class="mb-25">Search keywords</h4>
                <p class="text-caption mb-0" data-role="page-subtitle">Track the searches that matter to your business and see how your visibility changes over time.</p>
            </div>
            <span class="text-caption">{{ $business->name }}</span>
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    @if($errors->any())
        <x-alert variant="danger" class="mb-2" data-role="keyword-errors">{{ $errors->first() }}</x-alert>
    @endif

    @if($rankUnavailable)
        <x-alert variant="neutral" class="mb-2" data-role="rank-unavailable-notice">Rank checks are not available right now, so no checks will run. Your existing results stay visible.</x-alert>
    @endif

    @if($rankPaused)
        <x-alert variant="warning" class="mb-2" data-role="rank-paused-notice">Rank checks paused until your usage period resets. Your latest results stay visible.</x-alert>
    @endif

    {{-- Summary: real data only, "—" when there is nothing to summarise. --}}
    <div class="row g-1 mb-2" data-section="rank-summary">
        <div class="col-6 col-lg">
            <x-card :padded="true" class="rank-summary-card h-100">
                <p class="text-caption mb-25">Tracked</p>
                <div class="rank-summary-value" data-role="summary-tracked">
                    {{ $summary['slots_used'] }}@if($summary['slots_limit'] !== null) / {{ $summary['slots_limit'] }}@endif
                </div>
            </x-card>
        </div>
        <div class="col-6 col-lg">
            <x-card :padded="true" class="rank-summary-card h-100">
                <p class="text-caption mb-25">Average organic position</p>
                <div class="rank-summary-value" data-role="summary-average">{{ $summary['average_organic'] === null ? $dash : '#' . $summary['average_organic'] }}</div>
            </x-card>
        </div>
        <div class="col-6 col-lg">
            <x-card :padded="true" class="rank-summary-card h-100">
                <p class="text-caption mb-25">Top 10</p>
                <div class="rank-summary-value" data-role="summary-top10">{{ $summary['top10'] ?? $dash }}</div>
            </x-card>
        </div>
        <div class="col-6 col-lg">
            <x-card :padded="true" class="rank-summary-card h-100">
                <p class="text-caption mb-25">Improved</p>
                <div class="rank-summary-value" data-role="summary-improved">{{ $summary['improved'] ?? $dash }}</div>
            </x-card>
        </div>
        <div class="col-12 col-lg">
            <x-card :padded="true" class="rank-summary-card h-100">
                <p class="text-caption mb-25">Local top 3</p>
                <div class="rank-summary-value" data-role="summary-local-top3">{{ $summary['local_top3'] ?? $dash }}</div>
            </x-card>
        </div>
    </div>

    @can('manage_seo')
        <x-card :padded="true" class="mb-2" data-section="add-keyword">
            <p class="text-section-heading mb-1">Add a keyword</p>
            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.store', [$workspaceUid, $businessUid]) }}" data-role="keyword-add-form">
                @csrf
                <div class="row g-1 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label" for="keyword-phrase">Keyword</label>
                        <input class="form-control" type="text" id="keyword-phrase" name="phrase" maxlength="120" value="{{ old('phrase') }}" placeholder="photo booth rental" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="keyword-location">Scope</label>
                        <select class="form-select" id="keyword-location" name="location_uid">
                            <option value="">Whole business</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->uid }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 d-grid">
                        <button class="btn btn-primary" type="submit">Add keyword</button>
                    </div>
                </div>

                @if($rankPlan !== null)
                    <div class="mt-1" data-section="add-rank-tracking">
                        <div class="form-check">
                            <input type="hidden" name="track_rank" value="0">
                            <input class="form-check-input" type="checkbox" id="keyword-track-rank" name="track_rank" value="1" data-role="track-rank-toggle" @checked(! $slotsFull && old('track_rank', '1') === '1') @disabled($slotsFull)>
                            <label class="form-check-label" for="keyword-track-rank">Track Google rank</label>
                        </div>
                        @if($slotsFull)
                            <p class="text-caption mb-0 mt-50" data-role="slots-full-notice">
                                {{ $summary['slots_used'] }} of {{ $rankPlan->trackedTargets }} rank-tracked keywords are in use. The keyword will be saved as an SEO keyword with rank tracking off. Stop tracking another keyword to free a slot.
                            </p>
                        @else
                            <div class="mt-50" data-role="add-location-wrap">
                                <label class="form-label" for="keyword-search-location">Search location</label>
                                @include('customer.business.seo._rank-location-field', ['url' => route('customer.workspaces.businesses.seo.keywords.rank-locations', [$workspaceUid, $businessUid]), 'fieldId' => 'keyword-search-location'])
                                <p class="text-caption mb-0 mt-50">Google results are checked for this place, on mobile. {{ $summary['slots_used'] }} of {{ $rankPlan->trackedTargets }} rank-tracked keywords in use.</p>
                            </div>
                        @endif
                    </div>
                @endif
            </form>
        </x-card>
    @endcan

    <x-card :padded="false" class="mb-2" data-section="keywords">
        @if(count($rows) === 0)
            <div class="p-2">
                <p class="mb-0" data-role="no-keywords">You have no keywords yet.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table ds-table align-middle mb-0 rank-table" data-role="rank-table">
                    <thead>
                        <tr>
                            @foreach(['Keyword', 'Search location', 'Organic', 'Local', 'Change', 'Website', 'Last checked', 'Actions'] as $header)
                                <th class="text-label text-uppercase text-muted">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            @php
                                $keyword = $row['keyword'];
                                $target = $row['target'];
                                $state = $row['state'];
                                $result = $coverage[$keyword->id] ?? null;
                                $locationOpen = $keyword->location === null || $keyword->location->isActive();
                                $detailUrl = $target !== null ? route('customer.workspaces.businesses.seo.rank-targets.show', [$workspaceUid, $businessUid, $target->uid]) : null;
                            @endphp
                            <tr data-role="keyword" data-uid="{{ $keyword->uid }}" data-state="{{ $keyword->lifecycle_state->value }}" data-rank-state="{{ $state }}" @if($detailUrl) data-href="{{ $detailUrl }}" @endif>
                                <td data-label="Keyword">
                                    @if($detailUrl)
                                        <a href="{{ $detailUrl }}" class="fw-bold" data-role="keyword-phrase">{{ $keyword->phrase }}</a>
                                    @else
                                        <strong data-role="keyword-phrase">{{ $keyword->phrase }}</strong>
                                    @endif
                                    <span class="d-block mt-25">
                                        @if($state === SeoRankDashboardReader::STATE_UNTRACKED)
                                            <x-badge variant="neutral" data-role="keyword-state">SEO target</x-badge>
                                        @elseif($state === SeoRankDashboardReader::STATE_PAUSED)
                                            <x-badge variant="neutral" data-role="keyword-state">Rank tracking stopped</x-badge>
                                        @else
                                            <x-badge variant="accent" data-role="keyword-state">Rank tracked</x-badge>
                                        @endif
                                        @if($row['unavailable'] ?? false)
                                            <x-badge variant="warning" data-role="rank-unavailable">Temporarily unavailable</x-badge>
                                        @endif
                                    </span>
                                    <span class="text-caption d-block" data-role="keyword-location">{{ $keyword->location?->name ?? 'Whole business' }}</span>
                                </td>
                                <td data-label="Search location" data-role="search-location">
                                    @if($row['location_label'])
                                        {{ $row['location_label'] }}<span class="text-caption d-block">Google · mobile</span>
                                    @else
                                        <span class="text-muted">{{ $dash }}</span>
                                    @endif
                                </td>
                                <td data-label="Organic">@include('customer.business.seo._rank-badge', ['obs' => $row['organic'], 'kind' => 'organic', 'state' => $state])</td>
                                <td data-label="Local">@include('customer.business.seo._rank-badge', ['obs' => $row['local'], 'kind' => 'local', 'state' => $state])</td>
                                <td data-label="Change">@include('customer.business.seo._rank-change', ['change' => $row['change']])</td>
                                <td data-label="Website">
                                    @if($result !== null)
                                        <span data-role="keyword-coverage" data-status="{{ $result->status->value }}">
                                            @if($result->status === SeoKeywordCoverageStatus::Covered)
                                                <x-badge variant="success">Covered</x-badge>
                                            @elseif($result->status === SeoKeywordCoverageStatus::NotCovered)
                                                <x-badge variant="warning">Missing</x-badge>
                                            @else
                                                <x-badge variant="neutral">No published website</x-badge>
                                            @endif
                                            <span class="visually-hidden">{{ $result->status->label() }}</span>
                                        </span>
                                        @if($result->status === SeoKeywordCoverageStatus::Covered)
                                            <details class="mt-25">
                                                <summary class="text-caption">Found in</summary>
                                                <p class="text-caption mb-0" data-role="keyword-coverage-detail">
                                                    In {{ $result->titlePages }} {{ $result->titlePages === 1 ? 'page title' : 'page titles' }},
                                                    {{ $result->descriptionPages }} {{ $result->descriptionPages === 1 ? 'description' : 'descriptions' }} and
                                                    {{ $result->bodyPages }} {{ $result->bodyPages === 1 ? 'page' : 'pages' }} of text
                                                    (of {{ $result->pagesTotal }} published {{ $result->pagesTotal === 1 ? 'page' : 'pages' }}).
                                                </p>
                                            </details>
                                        @endif
                                    @else
                                        <span class="text-muted">{{ $dash }}</span>
                                    @endif
                                </td>
                                <td data-label="Last checked" data-role="last-checked">
                                    @if($row['last_checked_at'])
                                        {{ $row['last_checked_at']->isToday() ? 'Today' : $row['last_checked_at']->diffForHumans() }}
                                    @else
                                        <span class="text-muted">{{ $dash }}</span>
                                    @endif
                                </td>
                                <td data-label="Actions">
                                    @can('manage_seo')
                                        <div class="d-flex flex-wrap gap-50 align-items-start">
                                            @if($rankPlan !== null && $locationOpen)
                                                @if($state === SeoRankDashboardReader::STATE_UNTRACKED && ! $slotsFull)
                                                    <details>
                                                        <summary class="btn btn-sm btn-outline-primary" data-role="rank-start">Start tracking</summary>
                                                        <form method="POST" class="mt-50" style="min-width: 240px" action="{{ route('customer.workspaces.businesses.seo.keywords.rank.track', [$workspaceUid, $businessUid, $keyword->uid]) }}" data-role="rank-start-form">
                                                            @csrf
                                                            <label class="form-label" for="loc-{{ $keyword->uid }}">Search location</label>
                                                            @include('customer.business.seo._rank-location-field', ['url' => route('customer.workspaces.businesses.seo.keywords.rank-locations', [$workspaceUid, $businessUid]), 'fieldId' => 'loc-' . $keyword->uid])
                                                            <button class="btn btn-sm btn-primary mt-50" type="submit">Track rank</button>
                                                        </form>
                                                    </details>
                                                @elseif($state === SeoRankDashboardReader::STATE_UNTRACKED)
                                                    <span class="text-caption" data-role="rank-slots-full">{{ $summary['slots_used'] }} of {{ $rankPlan->trackedTargets }} rank-tracked keywords are in use.</span>
                                                @elseif($state === SeoRankDashboardReader::STATE_PAUSED)
                                                    @if(! $slotsFull)
                                                        <form method="POST" action="{{ route('customer.workspaces.businesses.seo.rank-targets.restart', [$workspaceUid, $businessUid, $target->uid]) }}">
                                                            @csrf
                                                            <button class="btn btn-sm btn-outline-primary" type="submit" data-role="rank-restart">Start tracking</button>
                                                        </form>
                                                    @else
                                                        <span class="text-caption" data-role="rank-slots-full">{{ $summary['slots_used'] }} of {{ $rankPlan->trackedTargets }} rank-tracked keywords are in use.</span>
                                                    @endif
                                                @else
                                                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.rank-targets.stop', [$workspaceUid, $businessUid, $target->uid]) }}">
                                                        @csrf
                                                        <button class="btn btn-sm btn-outline-secondary" type="submit" data-role="rank-stop">Stop tracking</button>
                                                    </form>
                                                @endif
                                            @endif
                                            @if($locationOpen)
                                                <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.archive', [$workspaceUid, $businessUid, $keyword->uid]) }}">
                                                    @csrf
                                                    <button class="btn btn-sm btn-outline-secondary" type="submit" data-role="keyword-archive">Archive</button>
                                                </form>
                                                <details>
                                                    <summary class="btn btn-sm btn-outline-secondary">Edit</summary>
                                                    <form method="POST" class="mt-50" style="min-width: 240px" action="{{ route('customer.workspaces.businesses.seo.keywords.update', [$workspaceUid, $businessUid, $keyword->uid]) }}" data-role="keyword-edit-form">
                                                        @csrf
                                                        <label class="form-label" for="phrase-{{ $keyword->uid }}-{{ $loop->index }}">Keyword</label>
                                                        <input class="form-control mb-50" type="text" id="phrase-{{ $keyword->uid }}-{{ $loop->index }}" name="phrase" maxlength="120" value="{{ $keyword->phrase }}" required>
                                                        <label class="form-label" for="location-{{ $keyword->uid }}-{{ $loop->index }}">Scope</label>
                                                        <select class="form-select mb-50" id="location-{{ $keyword->uid }}-{{ $loop->index }}" name="location_uid">
                                                            <option value="">Whole business</option>
                                                            @foreach($locations as $location)
                                                                <option value="{{ $location->uid }}" @selected((int) $keyword->business_location_id === (int) $location->id)>{{ $location->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button class="btn btn-sm btn-primary" type="submit">Save</button>
                                                    </form>
                                                </details>
                                            @endif
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    @if($archived->isNotEmpty())
        <x-card :padded="true" class="mb-2" data-section="archived-keywords">
            <p class="text-section-heading mb-1">Archived keywords</p>
            <ul class="list-unstyled mb-0">
                @foreach($archived as $keyword)
                    @php $locationOpen = $keyword->location === null || $keyword->location->isActive(); @endphp
                    <li class="py-1 @unless($loop->last) border-bottom @endunless" data-role="keyword" data-uid="{{ $keyword->uid }}" data-state="{{ $keyword->lifecycle_state->value }}">
                        <div class="d-flex justify-content-between flex-wrap gap-1">
                            <div>
                                <strong data-role="keyword-phrase">{{ $keyword->phrase }}</strong>
                                <span class="badge bg-light text-dark ms-1" data-role="keyword-state">Archived</span>
                                <span class="text-caption d-block" data-role="keyword-location">{{ $keyword->location?->name ?? 'Whole business' }}</span>
                            </div>
                            @can('manage_seo')
                                @if($locationOpen)
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.reactivate', [$workspaceUid, $businessUid, $keyword->uid]) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit" data-role="keyword-reactivate">Reactivate</button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <script>
        (function () {
            // Row click opens the keyword detail; clicks on controls are left alone.
            document.querySelectorAll('tr[data-href]').forEach(function (tr) {
                tr.addEventListener('click', function (e) {
                    if (e.target.closest('a, button, summary, input, select, form, details')) { return; }
                    window.location.href = tr.getAttribute('data-href');
                });
            });

            // Search-location pickers: query the cached provider locations, submit the CODE.
            document.querySelectorAll('[data-role="rank-location-field"]').forEach(function (field) {
                var input = field.querySelector('[data-role="rank-location-input"]');
                var code = field.querySelector('[data-role="rank-location-code"]');
                var list = field.querySelector('[data-role="rank-location-results"]');
                var hint = field.querySelector('[data-role="rank-location-hint"]');
                var timer = null;

                input.addEventListener('input', function () {
                    code.value = '';
                    clearTimeout(timer);
                    var q = input.value.trim();
                    if (q.length < 2) { list.classList.add('d-none'); return; }
                    timer = setTimeout(function () {
                        fetch(field.getAttribute('data-url') + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                            .then(function (r) { return r.ok ? r.json() : { locations: [] }; })
                            .then(function (data) {
                                list.innerHTML = '';
                                (data.locations || []).forEach(function (loc) {
                                    var b = document.createElement('button');
                                    b.type = 'button';
                                    b.className = 'list-group-item list-group-item-action';
                                    b.setAttribute('data-role', 'rank-location-option');
                                    b.setAttribute('data-code', loc.code);
                                    b.textContent = loc.label;
                                    b.addEventListener('click', function () {
                                        input.value = loc.label; code.value = loc.code;
                                        list.classList.add('d-none'); hint.classList.add('d-none');
                                    });
                                    list.appendChild(b);
                                });
                                list.classList.toggle('d-none', list.children.length === 0);
                            })
                            .catch(function () { list.classList.add('d-none'); });
                    }, 250);
                });

                var form = field.closest('form');
                if (form) {
                    form.addEventListener('submit', function (e) {
                        var toggle = form.querySelector('[data-role="track-rank-toggle"]');
                        var needs = toggle ? (toggle.checked && !toggle.disabled) : true;
                        if (needs && field.offsetParent !== null && code.value === '') {
                            e.preventDefault(); hint.classList.remove('d-none'); input.focus();
                        }
                    });
                }
            });
        })();
    </script>
@endsection
