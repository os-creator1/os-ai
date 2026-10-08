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

    Layout: KPI row, then the tracked keywords (primary, left) beside a compact
    "Add a keyword" card and "Suggested for you" (secondary, right).

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

    // Rank UI is shown only where it means something: the Business is entitled, or
    // it keeps stored rank results from an earlier plan (read-only). Without either,
    // "Tracked 0" and a wall of dashes would only be noise.
    $rankRows = collect($rows)->filter(fn ($r) => $r['target'] !== null);
    $showRank = $rankPlan !== null || $rankRows->isNotEmpty();
    $readOnlyRank = $rankPlan === null && $rankRows->isNotEmpty();
    $viewingAsClient = $viewingAsClient ?? false;

    // One shared search location is shown once, as a pill by the heading; with several
    // distinct ones each row names its own.
    $searchLocations = $rankRows->pluck('location_label')->filter()->unique()->values();
    $sharedLocation = $searchLocations->count() === 1 ? $searchLocations->first() : null;

    $headers = $showRank
        ? ['Keyword', 'Organic', 'Local', 'Change', 'Website', 'Actions']
        : ['Keyword', 'Website', 'Actions'];
    $canAdd = Auth::user()->can('manage_seo');
    $suggestions = $suggestions ?? [];
@endphp

@section('content')
    <style>
        .rank-summary-card .rank-summary-value { font-size: 2.75rem; line-height: 1; font-weight: 700; letter-spacing: -.02em; }
        .rank-summary-card .rank-summary-of { font-size: 1.1rem; font-weight: 500; color: var(--bs-secondary-color, #6e6b7b); letter-spacing: 0; }
        .rank-summary-card .card-body, .rank-summary-card { min-height: 8.5rem; }
        .rank-summary-card .rank-summary-inner { min-height: 6.5rem; display: flex; flex-direction: column; justify-content: space-between; }
        .rank-summary-card .rank-summary-label { font-size: .8125rem; color: var(--bs-secondary-color, #6e6b7b); }
        .rank-table td, .rank-table th { vertical-align: middle; }
        .rank-table tr[data-href] { cursor: pointer; }
        .rank-pos-bar { height: 3px; border-radius: 2px; background: var(--bs-border-color, #e5e5e5); margin-top: .35rem; max-width: 72px; overflow: hidden; }
        .rank-pos-bar > i { display: block; height: 100%; background: var(--bs-primary, #7367f0); }
        .keyword-side { position: sticky; top: 1rem; }
        @media (max-width: 767.98px) {
            .rank-summary-card .rank-summary-value { font-size: 2.25rem; }
            .rank-summary-card, .rank-summary-card .card-body, .rank-summary-card .rank-summary-inner { min-height: 0; }
            .rank-summary-card .rank-summary-inner { gap: .75rem; }
            .rank-table thead { display: none; }
            .rank-table, .rank-table tbody, .rank-table tr, .rank-table td { display: block; width: 100%; }
            .rank-table tr { border: 1px solid var(--bs-border-color, #e5e5e5); border-radius: .5rem; margin-bottom: .75rem; padding: .5rem .75rem; }
            .rank-table td { display: flex; justify-content: space-between; gap: 1rem; border: 0; padding: .25rem 0; text-align: right; }
            .rank-table td::before { content: attr(data-label); font-weight: 600; text-align: left; color: var(--bs-secondary-color, #6e6b7b); }
            .rank-table td[data-label="Keyword"] { display: block; text-align: left; }
            .rank-table td[data-label="Keyword"]::before { display: none; }
            .rank-table td[data-label="Actions"] { justify-content: flex-start; flex-wrap: wrap; }
            .rank-table td[data-label="Actions"]::before { display: none; }
            .rank-pos-bar { display: none; }
        }
        @media (max-width: 1199.98px) { .keyword-side { position: static; } }
    </style>

    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-start flex-wrap gap-1">
            <div>
                <h4 class="mb-25">Search keywords</h4>
                <p class="text-caption mb-0" data-role="page-subtitle">Track the searches that matter to your business.</p>
            </div>
            @if($sharedLocation)
                <x-badge variant="neutral" data-role="search-location-pill">{{ $sharedLocation }}</x-badge>
            @endif
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    @if($errors->any())
        <x-alert variant="danger" class="mb-2" data-role="keyword-errors">{{ $errors->first() }}</x-alert>
    @endif

    {{-- One truthful status banner. Unavailable (provider off) and paused (spend cap) are mutually exclusive in practice;
         both say plainly that no new checks run. --}}
    @if($rankUnavailable)
        <x-alert variant="neutral" class="mb-2" data-role="rank-unavailable-notice"><strong>Rank checks are unavailable.</strong> No new checks will run. Existing results stay visible.</x-alert>
    @elseif($rankPaused)
        <x-alert variant="warning" class="mb-2" data-role="rank-paused-notice"><strong>Rank checks paused until your usage period resets.</strong> Your latest results stay visible.</x-alert>
    @endif

    @if($readOnlyRank)
        <x-alert variant="neutral" class="mb-2" data-role="rank-not-included-notice">Rank tracking is not in your current plan. These results are read-only.</x-alert>
    @elseif(! $showRank)
        <p class="text-caption mb-2" data-role="rank-not-included">Rank tracking is not part of your plan.</p>
    @endif

    {{-- Summary: real data only, "—" when there is nothing to summarise. Hidden where rank tracking does not apply. --}}
    @if($showRank)
    <div class="row g-1 mb-2" data-section="rank-summary">
        <div class="col-6 col-lg">
            <x-card :padded="true" class="rank-summary-card h-100">
                <div class="rank-summary-inner">
                    <span class="rank-summary-label">Keywords tracked</span>
                    <div class="rank-summary-value" data-role="summary-tracked">{{ $summary['slots_used'] }}@if($summary['slots_limit'] !== null)<span class="rank-summary-of"> / {{ $summary['slots_limit'] }}</span>@endif</div>
                </div>
            </x-card>
        </div>
        <div class="col-6 col-lg">
            <x-card :padded="true" class="rank-summary-card h-100">
                <div class="rank-summary-inner">
                    <span class="rank-summary-label">Average position</span>
                    <div class="rank-summary-value" data-role="summary-average">{{ $summary['average_organic'] === null ? $dash : '#' . $summary['average_organic'] }}</div>
                </div>
            </x-card>
        </div>
        <div class="col-6 col-lg">
            <x-card :padded="true" class="rank-summary-card h-100">
                <div class="rank-summary-inner">
                    <span class="rank-summary-label">On page one</span>
                    <div class="rank-summary-value" data-role="summary-top10">{{ $summary['top10'] ?? $dash }}</div>
                </div>
            </x-card>
        </div>
        <div class="col-6 col-lg">
            <x-card :padded="true" class="rank-summary-card h-100">
                <div class="rank-summary-inner">
                    <span class="rank-summary-label">Improved</span>
                    <div class="rank-summary-value" data-role="summary-improved">{{ $summary['improved'] ?? $dash }}</div>
                </div>
            </x-card>
        </div>
        <div class="col-12 col-lg">
            <x-card :padded="true" class="rank-summary-card h-100">
                <div class="rank-summary-inner">
                    <span class="rank-summary-label">Local top 3</span>
                    <div class="rank-summary-value" data-role="summary-local-top3">{{ $summary['local_top3'] ?? $dash }}</div>
                </div>
            </x-card>
        </div>
    </div>
    @if(! empty($summary['basis']))
        <p class="text-caption mb-2" data-role="summary-basis">Based on {{ $summary['basis']['fresh'] }} of {{ $summary['basis']['checked'] }} {{ $summary['basis']['checked'] === 1 ? 'keyword' : 'keywords' }} checked recently.</p>
    @endif
    @endif

    <div class="row g-2 align-items-start">
        <div class="{{ $canAdd ? 'col-xl-8' : 'col-12' }}">
    <x-card :padded="false" class="mb-2" data-section="keywords">
        <div class="px-2 pt-2 pb-1"><p class="text-section-heading mb-0">Tracked keywords</p></div>
        @if(count($rows) === 0)
            <div class="px-2 pb-2">
                <p class="mb-0 text-muted" data-role="no-keywords">You have no keywords yet.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table ds-table align-middle mb-0 rank-table" data-role="rank-table">
                    <thead>
                        <tr>
                            @foreach($headers as $header)
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
                                // The detail page is behind the rank-tracking entitlement (404 without it), so a
                                // Business that has lost it sees its stored results as plain text: no link, no clickable row.
                                $detailUrl = ($target !== null && $rankPlan !== null) ? route('customer.workspaces.businesses.seo.rank-targets.show', [$workspaceUid, $businessUid, $target->uid]) : null;
                                $organicObs = $row['organic'];
                                $barPct = ($organicObs && $organicObs->status === \App\Enums\Seo\SeoRankObservationStatus::Found && $organicObs->position !== null)
                                    ? max(4, min(100, 101 - (int) $organicObs->position)) : null;
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
                                    @if($showRank)
                                        @php $rowLocation = $row['location_label'] && $sharedLocation === null ? $row['location_label'] : null; @endphp
                                        @if($rowLocation)
                                            <span class="text-caption d-block" data-role="search-location">{{ $rowLocation }}</span>
                                        @endif
                                        @if($row['last_checked_at'])
                                            <span class="text-caption d-block" data-role="last-checked">
                                                @if(($row['stale_days'] ?? null) !== null)
                                                    {{-- Past the freshness window: the position is kept visible but labelled. --}}
                                                    <span data-role="rank-stale">{{ $row['stale_days'] }} {{ $row['stale_days'] === 1 ? 'day' : 'days' }} ago — may be out of date</span>
                                                @else
                                                    Checked {{ $row['last_checked_at']->isToday() ? 'today' : $row['last_checked_at']->diffForHumans() }}
                                                @endif
                                            </span>
                                        @endif
                                    @endif
                                </td>
                                @if($showRank)
                                <td data-label="Organic">
                                    @include('customer.business.seo._rank-badge', ['obs' => $row['organic'], 'kind' => 'organic', 'state' => $state, 'blockedReason' => ($row['organic_blocked'] ?? false) ? SeoRankDashboardReader::NO_DOMAIN_REASON : null])
                                    @if($barPct !== null)<div class="rank-pos-bar" aria-hidden="true"><i style="width: {{ $barPct }}%"></i></div>@endif
                                </td>
                                <td data-label="Local">@include('customer.business.seo._rank-badge', ['obs' => $row['local'], 'kind' => 'local', 'state' => $state, 'blockedReason' => ($row['local_blocked'] ?? false) ? SeoRankDashboardReader::NO_IDENTITY_REASON : null])</td>
                                <td data-label="Change">@include('customer.business.seo._rank-change', ['change' => $row['change']])</td>
                                @endif
                                <td data-label="Website">
                                    @if($result !== null)
                                        <span data-role="keyword-coverage" data-status="{{ $result->status->value }}">
                                            @if($result->status === SeoKeywordCoverageStatus::Covered)
                                                <x-badge variant="success">Covered</x-badge>
                                            @elseif($result->status === SeoKeywordCoverageStatus::NotCovered)
                                                <x-badge variant="warning">Missing</x-badge>
                                            @elseif($result->status === SeoKeywordCoverageStatus::OnlyOnHiddenPages)
                                                <x-badge variant="warning">Only on pages hidden from search</x-badge>
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
                                <td data-label="Actions">
                                    @can('manage_seo')
                                        <div class="d-flex flex-wrap gap-50 align-items-start">
                                            {{-- Starting, resuming and stopping paid rank checks is closed while viewing as a client. --}}
                                            {{-- A keyword on an archived Location can still be STOPPED (it cannot be started, resumed or edited). --}}
                                            @if($rankPlan !== null && ! $viewingAsClient && ($locationOpen || ! in_array($state, [SeoRankDashboardReader::STATE_UNTRACKED, SeoRankDashboardReader::STATE_PAUSED], true)))
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
        </div>

        @can('manage_seo')
        <div class="col-xl-4">
            <div class="keyword-side">
            <x-card :padded="true" class="mb-2" data-section="add-keyword">
                <p class="text-section-heading mb-1">Add a keyword</p>
                <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.store', [$workspaceUid, $businessUid]) }}" data-role="keyword-add-form">
                    @csrf
                    <div class="mb-1">
                        <label class="form-label" for="keyword-phrase">Keyword</label>
                        <input class="form-control" type="text" id="keyword-phrase" name="phrase" maxlength="120" value="{{ old('phrase') }}" placeholder="photo booth rental" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="keyword-location">Scope</label>
                        <select class="form-select" id="keyword-location" name="location_uid">
                            <option value="">Whole business</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->uid }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if($rankPlan !== null && ! $viewingAsClient)
                        <div class="mb-1" data-section="add-rank-tracking">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="form-check mb-0">
                                    <input type="hidden" name="track_rank" value="0">
                                    <input class="form-check-input" type="checkbox" id="keyword-track-rank" name="track_rank" value="1" data-role="track-rank-toggle" @checked(! $slotsFull && old('track_rank', '1') === '1') @disabled($slotsFull)>
                                    <label class="form-check-label" for="keyword-track-rank">Track Google rank</label>
                                </div>
                                <span class="text-caption">{{ $summary['slots_used'] }} / {{ $rankPlan->trackedTargets }}</span>
                            </div>
                            @if($slotsFull)
                                <p class="text-caption mb-0 mt-50" data-role="slots-full-notice">
                                    {{ $summary['slots_used'] }} of {{ $rankPlan->trackedTargets }} rank-tracked keywords are in use. Stop tracking one to free a slot.
                                </p>
                            @else
                                <div class="mt-50" data-role="add-location-wrap">
                                    <label class="form-label" for="keyword-search-location">Search location</label>
                                    @include('customer.business.seo._rank-location-field', ['url' => route('customer.workspaces.businesses.seo.keywords.rank-locations', [$workspaceUid, $businessUid]), 'fieldId' => 'keyword-search-location'])
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="d-grid"><button class="btn btn-primary" type="submit">Add keyword</button></div>
                </form>
            </x-card>

            {{-- Ideas from your business type's keyword strategy, filled in ONLY with your own services and
                 cities. Nothing is saved until "Add" is pressed (an ordinary keyword add, with no rank tracking). --}}
            @if(count($suggestions) > 0)
                <x-card :padded="true" class="mb-2" data-section="suggested-keywords">
                    <p class="text-section-heading mb-25">Suggested for you</p>
                    <p class="text-caption mb-1">Based on your services and locations.</p>
                    <ul class="list-unstyled mb-0">
                        @foreach($suggestions as $suggestion)
                            <li class="py-50 d-flex justify-content-between align-items-center gap-1 @unless($loop->last) border-bottom @endunless" data-role="suggested-keyword" data-phrase="{{ $suggestion->phrase }}">
                                <div>
                                    <strong data-role="suggested-phrase">{{ $suggestion->phrase }}</strong>
                                    <span class="text-caption d-block">{{ $suggestion->intentLabel() }}@if($suggestion->locationName !== null) · {{ $suggestion->locationName }}@endif</span>
                                </div>
                                <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.store', [$workspaceUid, $businessUid]) }}">
                                    @csrf
                                    <input type="hidden" name="phrase" value="{{ $suggestion->phrase }}">
                                    @if($suggestion->locationUid !== null)
                                        <input type="hidden" name="location_uid" value="{{ $suggestion->locationUid }}">
                                    @endif
                                    <button class="btn btn-sm btn-outline-primary" type="submit" data-role="suggestion-add">Add</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
            </div>
        </div>
        @endcan
    </div>

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
