{{--
    Search keywords — the rank-tracking dashboard (SEO KEYWORD RANK TRACKING V1).

    Two different things are shown and kept visibly apart:
      * an SEO target: a phrase the Business wants to be found for (up to 50),
        with its Website coverage — free;
      * a rank-tracked keyword: that phrase + a search location, checked on
        Google by a paid provider within a monthly allowance.

    Every number is real or "—". Absence is never a rank: "Not in top N" and
    "Not matched" are words, never 0 or 101. Provider errors and secrets are
    never shown; only closed states (Checking, Updated, Temporarily
    unavailable, Budget paused).

    Three states, one page (design references: Search keywords — start / checking / results):
      start    no keywords yet: "Start tracking your searches" + Suggested for you;
      checking tracked keywords exist but none has a result yet and a check is running;
      results  tracked keywords with their results, best performers first
               (SeoRankDashboardReader::sortByBestRank).

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

    // One shared search location is shown once, as the chip by the heading; with several
    // distinct ones each row names its own. With nothing tracked yet, the Business's
    // existing default search location (if any) is what the next keyword will use.
    $searchLocations = $rankRows->pluck('location_label')->filter()->unique()->values();
    $sharedLocation = $searchLocations->count() === 1 ? $searchLocations->first() : null;
    $chipLocation = $sharedLocation ?? ($searchLocations->isEmpty() ? ($rankDefaults['business']['label'] ?? null) : null);

    $canAdd = Auth::user()->can('manage_seo');
    $suggestions = $suggestions ?? [];
    $canTrackRank = $rankPlan !== null && ! $viewingAsClient;

    // Which of the three states this is.
    // (With nothing to suggest, an empty page keeps its summary cards and the plain empty list.)
    $startState = $rankPlan !== null && count($rows) === 0 && $canAdd && count($suggestions) > 0;
    $anyResult = $rankRows->contains(fn ($r) => $r['organic'] !== null || $r['local'] !== null);
    $checkingState = ! $startState && $showRank && ! $anyResult && $rankRows->contains(fn ($r) => $r['state'] === SeoRankDashboardReader::STATE_CHECKING);
    $domainBlocked = $rankRows->contains(fn ($r) => $r['organic_blocked'] ?? false);
    $domainUrl = route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid]);

    // The list shows the best seven; the rest sit behind "Show all N".
    $visibleLimit = 7;
    $slotsLimitLabel = $rankPlan?->trackedTargets;
@endphp

@section('content')
    <style>
        .kw-page { --kw-brand: #B4514A; --kw-brand-d: #8F3B35; --kw-ink: #1F1A19; --kw-mute: #5E5652; --kw-mute2: #6B625E; --kw-faint: #8A817C; --kw-line: #E9E4DF; --kw-line2: #F0EBE6; --kw-field: #D6CFC8; --kw-soft: #FBF9F7; color: var(--kw-ink); display: flex; flex-direction: column; gap: 16px; }
        .kw-page .kw-ic { width: 16px; height: 16px; flex: none; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .kw-h1 { margin: 0; font-size: 28px; font-weight: 800; letter-spacing: -.02em; }
        .kw-sub { margin: 2px 0 0; font-size: 14.5px; color: var(--kw-mute); }
        .kw-chip { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border-radius: 999px; border: 1px solid #E0DAD4; background: #fff; font-size: 13px; font-weight: 500; color: #2E2826; }
        .kw-chip .kw-ic { width: 14px; height: 14px; color: var(--kw-brand-d); }
        .kw-card { background: #fff; border: 1px solid var(--kw-line); border-radius: 16px; box-sizing: border-box; }
        .kw-card-head { display: flex; align-items: center; gap: 10px; padding: 16px 20px; }
        .kw-card-head b { flex: 1; font-size: 16px; }
        .kw-mut { font-size: 12.5px; color: var(--kw-mute2); }
        .kw-faint { color: #A39A94; }
        .kw-kpis { display: flex; flex-wrap: wrap; gap: 12px; }
        .kw-kpi { flex: 1 1 150px; display: flex; flex-direction: column; gap: 3px; padding: 14px 16px; border-radius: 14px; background: #fff; border: 1px solid var(--kw-line); }
        .kw-kpi > span { font-size: 12.5px; color: var(--kw-mute2); font-weight: 500; }
        .kw-kpi > b { font-size: 24px; font-weight: 800; letter-spacing: -.02em; }
        .kw-kpi > b .kw-of { font-size: 15px; color: var(--kw-faint); font-weight: 600; }
        .kw-kpi > small { font-size: 12px; color: var(--kw-faint); }
        .kw-kpi.is-needs { background: var(--kw-soft); }
        .kw-kpi.is-needs > b { font-size: 16px; padding: 5px 0; color: var(--kw-faint); }
        .kw-kpi a, .kw-link { color: var(--kw-brand-d); font-weight: 600; text-decoration: none; }
        .kw-banner { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 12px; background: #E4EEF6; color: #1F5682; font-size: 13.5px; }
        .kw-callout { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 18px; border-radius: 14px; background: #fff; border: 1px dashed var(--kw-field); }
        .kw-callout > .kw-ic { width: 18px; height: 18px; color: var(--kw-faint); }
        .kw-callout > span { flex: 1 1 300px; font-size: 13.5px; color: var(--kw-mute); }
        .kw-callout > span b { color: var(--kw-ink); }
        .kw-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; box-sizing: border-box; padding: 0 20px; border-radius: 10px; border: 0; font-size: 14px; font-weight: 600; white-space: nowrap; background: var(--kw-brand); color: #fff; cursor: pointer; }
        .kw-btn:hover { background: var(--kw-brand-d); color: #fff; }
        .kw-btn.is-ghost { border: 1px solid var(--kw-field); background: #fff; color: #2E2826; min-height: 36px; font-size: 13px; padding: 0 14px; }
        .kw-btn.is-ghost:hover { background: var(--kw-soft); color: #2E2826; }
        .kw-btn[disabled] { opacity: .55; cursor: not-allowed; }
        .kw-ctaicon { width: 52px; height: 52px; border-radius: 14px; background: #F8E9E6; color: var(--kw-brand); display: flex; align-items: center; justify-content: center; flex: none; }
        .kw-ctaicon .kw-ic { width: 24px; height: 24px; }
        .kw-cta { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; padding: 22px 24px; }
        .kw-cta .kw-cta-copy { flex: 1 1 300px; display: flex; flex-direction: column; gap: 3px; }
        .kw-cta .kw-cta-copy b { font-size: 19px; font-weight: 800; }
        .kw-cta .kw-cta-copy span { font-size: 14px; color: var(--kw-mute); }
        .kw-sugg { display: flex; align-items: center; gap: 14px; min-height: 58px; padding: 8px 20px; border-top: 1px solid var(--kw-line2); }
        .kw-sugg > div { flex: 1; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .kw-name { font-size: 14.5px; font-weight: 600; color: var(--kw-ink); overflow-wrap: anywhere; text-decoration: none; }
        a.kw-name:hover { color: var(--kw-brand-d); }
        .kw-table { width: 100%; border-collapse: collapse; margin: 0; }
        .kw-table th { height: 40px; padding: 0 14px; background: var(--kw-soft); font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--kw-mute2); text-align: left; white-space: nowrap; }
        .kw-table td { padding: 10px 14px; border-top: 1px solid var(--kw-line2); font-size: 14px; vertical-align: middle; background: transparent; }
        .kw-table th:first-child, .kw-table td:first-child { padding-left: 20px; }
        .kw-table th:last-child, .kw-table td:last-child { padding-right: 20px; width: 1%; }
        .kw-table tr { background: transparent; }
        .kw-table tr[hidden] { display: none; }
        .kw-table tr[data-href] { cursor: pointer; }
        .kw-table tr[data-href]:hover td { background: var(--kw-soft); }
        .kw-table td[data-label="Keyword"] { min-width: 12rem; }
        .kw-sub2 { display: block; font-size: 12.5px; color: var(--kw-mute2); margin-top: 2px; }
        .kw-rk { display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 10px; border-radius: 999px; font-size: 12.5px; font-weight: 700; white-space: nowrap; }
        .kw-rk-green { background: #E3F3EA; color: #17693F; }
        .kw-rk-blue { background: #E4EEF6; color: #1F5682; }
        .kw-rk-amber { background: #FDF1DC; color: #7A4A05; }
        .kw-rk-neutral { background: #F1EDE9; color: var(--kw-mute2); }
        .kw-sk { display: inline-block; width: 80px; height: 12px; border-radius: 6px; background: linear-gradient(90deg, #EEE9E4, #F6F2EF, #EEE9E4); vertical-align: middle; }
        .kw-more { width: 34px; height: 34px; border: 1px solid var(--kw-line); border-radius: 9px; background: #fff; color: #4A4340; display: inline-flex; align-items: center; justify-content: center; padding: 0; }
        .kw-more:hover { background: var(--kw-soft); }
        .kw-showall { display: flex; align-items: center; justify-content: center; min-height: 46px; width: 100%; border: 0; border-top: 1px solid var(--kw-line2); background: transparent; font-size: 13.5px; font-weight: 600; color: var(--kw-brand-d); }
        .kw-add { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; padding: 16px 18px; }
        .kw-add .kw-f { display: flex; flex-direction: column; gap: 6px; }
        .kw-add label { font-size: 13px; font-weight: 600; margin: 0; }
        .kw-add .form-control, .kw-add .form-select { height: 44px; border: 1px solid var(--kw-field); border-radius: 10px; font-size: 14.5px; padding: 0 14px; }
        .kw-add .kw-foot { flex-basis: 100%; display: flex; flex-wrap: wrap; align-items: center; gap: 4px 14px; }
        .kw-add .form-check { margin: 0; min-height: 0; }
        .kw-add .form-check-label { font-size: 12.5px; color: var(--kw-mute2); font-weight: 500; }
        .kw-page .dropdown-menu { border-radius: 10px; border-color: var(--kw-line); font-size: 13.5px; }
        @media (max-width: 767.98px) {
            .kw-h1 { font-size: 24px; }
            .kw-table thead { display: none; }
            .kw-table, .kw-table tbody, .kw-table tr, .kw-table td { display: block; width: 100%; }
            .kw-table tr { border-top: 1px solid var(--kw-line2); padding: 8px 16px; }
            .kw-table td { display: flex; justify-content: space-between; align-items: center; gap: 1rem; border: 0; padding: .25rem 0; text-align: right; }
            .kw-table th:first-child, .kw-table td:first-child, .kw-table th:last-child, .kw-table td:last-child { padding-left: 0; padding-right: 0; width: 100%; }
            .kw-table td::before { content: attr(data-title); font-weight: 600; text-align: left; color: var(--kw-mute2); font-size: 12.5px; }
            .kw-table td[data-label="Keyword"] { display: block; text-align: left; }
            .kw-table td[data-label="Keyword"]::before, .kw-table td[data-label="Actions"]::before { display: none; }
            .kw-table td[data-label="Actions"] { justify-content: flex-start; }
        }
    </style>

    <div class="kw-page">
        <div class="d-flex flex-wrap align-items-end gap-1">
            <div style="flex: 1 1 300px">
                <h1 class="kw-h1">Search keywords</h1>
                <p class="kw-sub" data-role="page-subtitle">See where you show up when people search for what you offer.</p>
            </div>
            @if($chipLocation)
                <span class="kw-chip"><svg class="kw-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.200-7-11.500a7 7 0 0 1 14 0C19 14.800 12 21 12 21z"></path><circle cx="12" cy="9.500" r="2.500"></circle></svg>Searching from <span data-role="search-location-pill">{{ $chipLocation }}</span></span>
            @endif
        </div>

        <x-flash-alert />

        @if($errors->any())
            <x-alert variant="danger" data-role="keyword-errors">{{ $errors->first() }}</x-alert>
        @endif

        {{-- One truthful status banner. Unavailable (provider off) and paused (spend cap) are mutually exclusive in practice;
             both say plainly that no new checks run. --}}
        @if($rankUnavailable)
            <x-alert variant="neutral" data-role="rank-unavailable-notice"><strong>Rank checks are unavailable.</strong> No new checks will run. Existing results stay visible.</x-alert>
        @elseif($rankPaused)
            <x-alert variant="warning" data-role="rank-paused-notice"><strong>Rank checks paused until your usage period resets.</strong> Your latest results stay visible.</x-alert>
        @endif

        @if($readOnlyRank)
            <x-alert variant="neutral" data-role="rank-not-included-notice">Rank tracking is not in your current plan. These results are read-only.</x-alert>
        @elseif(! $showRank)
            <p class="kw-mut mb-0" data-role="rank-not-included">Rank tracking is not part of your plan.</p>
        @endif

        {{-- ============ START: nothing saved yet ============ --}}
        @if($startState)
            <section class="kw-card kw-cta" data-section="start-tracking">
                <span class="kw-ctaicon"><svg class="kw-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.500"></circle><path d="M16 16l4.500 4.500"></path></svg></span>
                <div class="kw-cta-copy">
                    <b>Start tracking your searches</b>
                    <span>Pick up to {{ $slotsLimitLabel }} keywords. We check your Google Maps rank automatically.</span>
                </div>
                @if($canTrackRank)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.rank.track-suggestions', [$workspaceUid, $businessUid]) }}" data-role="track-all-form">
                        @csrf
                        <input type="hidden" name="search_location_code" value="" data-role="track-all-code">
                        <button class="kw-btn" type="submit" data-role="track-all">Track all {{ count($suggestions) }} {{ count($suggestions) === 1 ? 'suggestion' : 'suggestions' }}</button>
                    </form>
                @endif
            </section>
        @endif

        {{-- ============ CHECKING / RESULTS: summary cards ============ --}}
        @if($showRank && ! $startState)
            @php
                $bestLocal = $summary['best_local'] ?? null;
                $bestOrganic = $summary['best_organic'] ?? null;
                $checkingNote = $checkingState ? 'checking…' : null;
            @endphp
            <div class="kw-kpis" data-section="rank-summary">
                <div class="kw-kpi">
                    <span>Tracking</span>
                    <b data-role="summary-tracked">{{ $summary['slots_used'] }}@if($summary['slots_limit'] !== null) <span class="kw-of">/ {{ $summary['slots_limit'] }}</span>@endif</b>
                    <small>{{ $summary['slots_used'] === 1 ? 'keyword' : 'keywords' }}</small>
                </div>
                <div class="kw-kpi">
                    <span>In Maps top 3</span>
                    <b data-role="summary-local-top3">{{ $checkingState ? $dash : ($summary['local_top3'] ?? $dash) }}</b>
                    <small>{{ $checkingNote ?? 'the spots most people click' }}</small>
                </div>
                <div class="kw-kpi">
                    <span>Best Maps rank</span>
                    <b data-role="summary-best-local">{{ $bestLocal ? '#' . $bestLocal['position'] : $dash }}</b>
                    <small>{{ $checkingNote ?? ($bestLocal['phrase'] ?? 'no Maps result yet') }}</small>
                </div>
                @if($bestOrganic !== null)
                    <div class="kw-kpi">
                        <span>Best Google search rank</span>
                        <b data-role="summary-best-organic">#{{ $bestOrganic['position'] }}</b>
                        <small>{{ $bestOrganic['phrase'] }}</small>
                    </div>
                @elseif($domainBlocked)
                    <div class="kw-kpi is-needs">
                        <span>Google search rank</span>
                        <b data-role="summary-best-organic">Needs your domain</b>
                        <small><a href="{{ $domainUrl }}">Connect domain</a></small>
                    </div>
                @else
                    <div class="kw-kpi">
                        <span>Google search rank</span>
                        <b data-role="summary-best-organic">{{ $dash }}</b>
                        <small>{{ $checkingNote ?? 'no search result yet' }}</small>
                    </div>
                @endif
            </div>
            @if(! empty($summary['basis']))
                <p class="kw-mut mb-0" data-role="summary-basis">Based on {{ $summary['basis']['fresh'] }} of {{ $summary['basis']['checked'] }} {{ $summary['basis']['checked'] === 1 ? 'keyword' : 'keywords' }} checked recently.</p>
            @endif

            @if($checkingState)
                <div class="kw-banner" data-role="checking-banner" role="status">
                    <svg class="kw-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.500-4.500L4 8M4 4v4h4M4 13a8 8 0 0 0 14.500 4.500L20 16M20 20v-4h-4"></path></svg>
                    <span style="flex: 1">Checking your first rankings — usually takes a few minutes. You can leave this page.</span>
                </div>
            @endif

            @if($domainBlocked)
                <div class="kw-callout" data-role="domain-callout">
                    <svg class="kw-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.500"></circle><path d="M3.500 12h17M12 3.500c2.800 2.500 2.800 14.500 0 17M12 3.500c-2.800 2.500-2.800 14.500 0 17"></path></svg>
                    <span><b>Publish your website on your domain</b> to see where it ranks in normal Google results.</span>
                    <a class="kw-link" href="{{ $domainUrl }}" style="font-size: 13.5px">Connect domain</a>
                </div>
            @endif
        @endif

        {{-- ============ START: suggestions list ============ --}}
        @if($startState)
            @include('customer.business.seo._keyword-suggestions', ['suggestions' => $suggestions, 'canTrackRank' => $canTrackRank])
        @endif

        {{-- ============ CHECKING / RESULTS: tracked keywords ============ --}}
        @if(! $startState)
        @php
            $headers = $showRank
                ? [['Keyword', 'Keyword'], ['Organic', 'Google search'], ['Local', 'Google Maps'], ['Change', 'Change'], ['Website', 'Website'], ['Actions', '']]
                : [['Keyword', 'Keyword'], ['Website', 'Website'], ['Actions', '']];
        @endphp
        <section class="kw-card" style="overflow: hidden" data-section="keywords">
            <div class="kw-card-head"><b>Tracked keywords</b>@if($showRank)<span class="kw-mut">Ranks update automatically</span>@endif</div>
            @if(count($rows) === 0)
                <div class="px-2 pb-2">
                    <p class="mb-0 text-muted" data-role="no-keywords">You have no keywords yet.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="kw-table align-middle" data-role="rank-table">
                        <thead>
                            <tr>
                                @foreach($headers as [$label, $title])
                                    <th>{{ $title }}@if($title === '')<span class="visually-hidden">Actions</span>@endif</th>
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
                                    $hidden = $loop->index >= $visibleLimit;
                                @endphp
                                <tr data-role="keyword" data-uid="{{ $keyword->uid }}" data-state="{{ $keyword->lifecycle_state->value }}" data-rank-state="{{ $state }}" @if($detailUrl) data-href="{{ $detailUrl }}" @endif @if($hidden) data-extra="1" hidden @endif>
                                    <td data-label="Keyword" data-title="Keyword">
                                        @if($detailUrl)
                                            <a href="{{ $detailUrl }}" class="kw-name" data-role="keyword-phrase">{{ $keyword->phrase }}</a>
                                        @else
                                            <span class="kw-name" data-role="keyword-phrase">{{ $keyword->phrase }}</span>
                                        @endif
                                        <span class="kw-sub2">
                                            <span data-role="keyword-location">{{ $keyword->location?->name ?? 'Whole business' }}</span>
                                            @if($showRank)
                                                @php $rowLocation = $row['location_label'] && $sharedLocation === null ? $row['location_label'] : null; @endphp
                                                @if($rowLocation)
                                                    · <span data-role="search-location">{{ $rowLocation }}</span>
                                                @endif
                                                @if($row['last_checked_at'])
                                                    · <span data-role="last-checked">
                                                        @if(($row['stale_days'] ?? null) !== null)
                                                            {{-- Past the freshness window: the position is kept visible but labelled. --}}
                                                            <span data-role="rank-stale">{{ $row['stale_days'] }} {{ $row['stale_days'] === 1 ? 'day' : 'days' }} ago — may be out of date</span>
                                                        @else
                                                            checked {{ $row['last_checked_at']->isToday() ? 'today' : $row['last_checked_at']->diffForHumans() }}
                                                        @endif
                                                    </span>
                                                @endif
                                            @endif
                                        </span>
                                        <span class="d-flex flex-wrap gap-50 mt-25">
                                            @if($state === SeoRankDashboardReader::STATE_UNTRACKED)
                                                <span class="kw-rk kw-rk-neutral" style="height:22px;font-size:11.5px" data-role="keyword-state">SEO target</span>
                                            @elseif($state === SeoRankDashboardReader::STATE_PAUSED)
                                                <span class="kw-rk kw-rk-neutral" style="height:22px;font-size:11.5px" data-role="keyword-state">Rank tracking stopped</span>
                                            @endif
                                            @if($row['unavailable'] ?? false)
                                                <span class="kw-rk kw-rk-amber" style="height:22px;font-size:11.5px" data-role="rank-unavailable">Temporarily unavailable</span>
                                            @endif
                                        </span>
                                    </td>
                                    @if($showRank)
                                    <td data-label="Organic" data-title="Google search">
                                        @include('customer.business.seo._rank-pill', ['obs' => $row['organic'], 'kind' => 'organic', 'state' => $state, 'blockedReason' => ($row['organic_blocked'] ?? false) ? SeoRankDashboardReader::NO_DOMAIN_REASON : null, 'blockedLabel' => 'Needs domain'])
                                    </td>
                                    <td data-label="Local" data-title="Google Maps">@include('customer.business.seo._rank-pill', ['obs' => $row['local'], 'kind' => 'local', 'state' => $state, 'blockedReason' => ($row['local_blocked'] ?? false) ? SeoRankDashboardReader::NO_IDENTITY_REASON : null, 'blockedLabel' => 'Needs domain or phone'])</td>
                                    <td data-label="Change" data-title="Change">@include('customer.business.seo._rank-change', ['change' => $row['change']])</td>
                                    @endif
                                    <td data-label="Website" data-title="Website">
                                        @if($result !== null)
                                            <span data-role="keyword-coverage" data-status="{{ $result->status->value }}">
                                                @if($result->status === SeoKeywordCoverageStatus::Covered)
                                                    <span class="kw-rk kw-rk-green">Covered</span>
                                                @elseif($result->status === SeoKeywordCoverageStatus::NotCovered)
                                                    <span class="kw-rk kw-rk-amber">Missing</span>
                                                @elseif($result->status === SeoKeywordCoverageStatus::OnlyOnHiddenPages)
                                                    <span class="kw-rk kw-rk-amber">Only on pages hidden from search</span>
                                                @else
                                                    <span class="kw-rk kw-rk-neutral">No published website</span>
                                                @endif
                                                <span class="visually-hidden">{{ $result->status->label() }}</span>
                                            </span>
                                            @if($result->status === SeoKeywordCoverageStatus::Covered)
                                                <details class="mt-25">
                                                    <summary class="kw-mut">Found in</summary>
                                                    <p class="kw-mut mb-0" data-role="keyword-coverage-detail">
                                                        In {{ $result->titlePages }} {{ $result->titlePages === 1 ? 'page title' : 'page titles' }},
                                                        {{ $result->descriptionPages }} {{ $result->descriptionPages === 1 ? 'description' : 'descriptions' }} and
                                                        {{ $result->bodyPages }} {{ $result->bodyPages === 1 ? 'page' : 'pages' }} of text
                                                        (of {{ $result->pagesTotal }} published {{ $result->pagesTotal === 1 ? 'page' : 'pages' }}).
                                                    </p>
                                                </details>
                                            @endif
                                        @else
                                            <span class="kw-mut">{{ $dash }}</span>
                                        @endif
                                    </td>
                                    <td data-label="Actions">
                                        @can('manage_seo')
                                            @php
                                                $canTrack = $canTrackRank;
                                                $scopeDefault = $keyword->location ? ($rankDefaults['locations'][$keyword->location->uid] ?? null) : ($rankDefaults['business'] ?? null);
                                                $slotsSentence = $summary['slots_used'] . ' of ' . ($rankPlan?->trackedTargets) . ' rank-tracked keywords are in use.';
                                                // Starting, resuming and stopping paid rank checks is closed while viewing as a client.
                                                // A keyword on an archived Location can still be STOPPED (not started, resumed or edited).
                                                $rankActionsOpen = $canTrack && ($locationOpen || ! in_array($state, [SeoRankDashboardReader::STATE_UNTRACKED, SeoRankDashboardReader::STATE_PAUSED], true));
                                                $hasMenu = $rankActionsOpen || $locationOpen;
                                            @endphp
                                            @if($hasMenu)
                                            <div class="dropdown row-actions">
                                                <button class="kw-more" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for {{ $keyword->phrase }}" data-role="keyword-menu"><svg class="kw-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.300"></circle><circle cx="12" cy="12" r="1.300"></circle><circle cx="19" cy="12" r="1.300"></circle></svg></button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    @if($rankActionsOpen)
                                                        @if($state === SeoRankDashboardReader::STATE_UNTRACKED && ! $slotsFull)
                                                            <li>
                                                                @if($scopeDefault)
                                                                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.rank.track', [$workspaceUid, $businessUid, $keyword->uid]) }}">
                                                                        @csrf
                                                                        <button class="dropdown-item" type="submit" data-role="rank-start">Start tracking</button>
                                                                    </form>
                                                                @else
                                                                    <button class="dropdown-item" type="button" data-role="rank-start" data-rank-dialog="track" data-phrase="{{ $keyword->phrase }}" data-scope="{{ $keyword->location?->name ?? 'Whole business' }}" data-action="{{ route('customer.workspaces.businesses.seo.keywords.rank.track', [$workspaceUid, $businessUid, $keyword->uid]) }}">Start tracking</button>
                                                                @endif
                                                            </li>
                                                        @elseif($state === SeoRankDashboardReader::STATE_UNTRACKED || ($state === SeoRankDashboardReader::STATE_PAUSED && $slotsFull))
                                                            <li><span class="dropdown-item-text text-muted" data-role="rank-slots-full" title="{{ $slotsSentence }}">Slots full<span class="visually-hidden"> — {{ $slotsSentence }}</span></span></li>
                                                        @elseif($state === SeoRankDashboardReader::STATE_PAUSED)
                                                            <li>
                                                                <form method="POST" action="{{ route('customer.workspaces.businesses.seo.rank-targets.restart', [$workspaceUid, $businessUid, $target->uid]) }}">
                                                                    @csrf
                                                                    <button class="dropdown-item" type="submit" data-role="rank-restart">Start tracking</button>
                                                                </form>
                                                            </li>
                                                        @else
                                                            <li>
                                                                <form method="POST" action="{{ route('customer.workspaces.businesses.seo.rank-targets.stop', [$workspaceUid, $businessUid, $target->uid]) }}">
                                                                    @csrf
                                                                    <button class="dropdown-item" type="submit" data-role="rank-stop">Stop tracking</button>
                                                                </form>
                                                            </li>
                                                        @endif
                                                    @endif
                                                    @if($locationOpen)
                                                        <li><button class="dropdown-item" type="button" data-role="keyword-edit" data-edit-action="{{ route('customer.workspaces.businesses.seo.keywords.update', [$workspaceUid, $businessUid, $keyword->uid]) }}" data-phrase="{{ $keyword->phrase }}" data-location="{{ $keyword->location?->uid }}">Edit</button></li>
                                                        <li>
                                                            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.archive', [$workspaceUid, $businessUid, $keyword->uid]) }}">
                                                                @csrf
                                                                <button class="dropdown-item" type="submit" data-role="keyword-archive">Archive</button>
                                                            </form>
                                                        </li>
                                                    @endif
                                                </ul>
                                            </div>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(count($rows) > $visibleLimit)
                    <button type="button" class="kw-showall" data-role="show-all" data-count="{{ count($rows) }}">Show all {{ count($rows) }}</button>
                @endif
            @endif
        </section>

        @if($canAdd && count($suggestions) > 0)
            @include('customer.business.seo._keyword-suggestions', ['suggestions' => $suggestions, 'canTrackRank' => $canTrackRank])
        @endif
        @endif

        {{-- ============ Add a keyword (every state) ============ --}}
        @can('manage_seo')
            <section class="kw-card" data-section="add-keyword">
                <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.store', [$workspaceUid, $businessUid]) }}" data-role="keyword-add-form" class="kw-add">
                    @csrf
                    <div class="kw-f" style="flex: 2 1 260px">
                        <label for="keyword-phrase">Add a keyword</label>
                        <input class="form-control" type="text" id="keyword-phrase" name="phrase" maxlength="120" value="{{ old('phrase') }}" placeholder="e.g. photo booth rental" required>
                    </div>
                    <div class="kw-f" style="flex: 1 1 180px">
                        <label for="keyword-location">For</label>
                        <select class="form-select" id="keyword-location" name="location_uid">
                            <option value="">Whole business</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->uid }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="kw-btn" type="submit">{{ $canTrackRank ? 'Track keyword' : 'Add keyword' }}</button>

                    <div class="kw-foot" data-section="add-rank-tracking">
                        @if($canTrackRank)
                            <span class="kw-mut" data-role="slots-used">{{ $summary['slots_used'] }} of {{ $rankPlan->trackedTargets }} used · rank is checked automatically</span>
                            <div class="form-check">
                                <input type="hidden" name="track_rank" value="0">
                                <input class="form-check-input" type="checkbox" id="keyword-track-rank" name="track_rank" value="1" data-role="track-rank-toggle" @checked(! $slotsFull && old('track_rank', '1') === '1') @disabled($slotsFull)>
                                <label class="form-check-label" for="keyword-track-rank">Track Google rank</label>
                            </div>
                            @if($slotsFull)
                                <span class="kw-mut" data-role="slots-full-notice">
                                    {{ $summary['slots_used'] }} of {{ $rankPlan->trackedTargets }} rank-tracked keywords are in use. Stop tracking one to free a slot.
                                </span>
                            @else
                                {{-- The existing rank location (its keyword Location's first) is used silently; the chooser
                                     opens only when tracking is on and there is none yet. --}}
                                <input type="hidden" name="search_location_code" value="" data-role="add-location-code">
                                <span class="kw-mut d-none" data-role="add-tracking-from">
                                    Tracking from: <span data-role="add-location-label"></span> &middot;
                                    <button class="btn btn-link btn-sm p-0 align-baseline" type="button" data-role="change-location">Change</button>
                                </span>
                            @endif
                        @endif
                    </div>
                </form>
            </section>
        @endcan

    @if($archived->isNotEmpty())
        <x-card :padded="true" data-section="archived-keywords">
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

    </div>

    {{-- One chooser for every place that needs a search location (Add keyword, Start tracking). --}}
    @can('manage_seo')
        @if($rankPlan !== null && ! $viewingAsClient)
            @include('customer.business.seo._rank-location-dialog', ['url' => route('customer.workspaces.businesses.seo.keywords.rank-locations', [$workspaceUid, $businessUid])])
            <form method="POST" id="rank-dialog-form" class="d-none" data-role="rank-dialog-form">
                @csrf
                <input type="hidden" name="search_location_code" value="">
            </form>
        @endif

        <x-dialog id="keyword-edit-dialog" title="Edit keyword" size="sm">
            <form method="POST" id="keyword-edit-form" data-role="keyword-edit-dialog-form">
                @csrf
                <label class="form-label" for="keyword-edit-phrase">Keyword</label>
                <input class="form-control mb-1" type="text" id="keyword-edit-phrase" name="phrase" maxlength="120" required>
                <label class="form-label" for="keyword-edit-location">Scope</label>
                <select class="form-select mb-2" id="keyword-edit-location" name="location_uid">
                    <option value="">Whole business</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->uid }}">{{ $location->name }}</option>
                    @endforeach
                </select>
                <div class="d-grid"><button class="btn btn-primary" type="submit">Save</button></div>
            </form>
        </x-dialog>
    @endcan

    <script>
        (function () {
            var defaults = @json($rankDefaults);
            var dialogEl = document.getElementById('rank-location-dialog');
            // Bootstrap is loaded after this script, so resolve the dialog when it is first needed.
            function dialog() { return window.bootstrap.Modal.getOrCreateInstance(dialogEl); }
            var pending = null; // what to do once a real autocomplete result is chosen
            var skipHandler = null; // Add keyword only: save without rank tracking when no city can be chosen

            // Row click opens the keyword detail; clicks on controls are left alone.
            document.querySelectorAll('tr[data-href]').forEach(function (tr) {
                tr.addEventListener('click', function (e) {
                    if (e.target.closest('a, button, summary, input, select, form, details, .dropdown')) { return; }
                    window.location.href = tr.getAttribute('data-href');
                });
            });

            // Search-location picker: query the cached provider locations; only an explicit pick (click, or Enter on the
            // highlighted row) counts as chosen. The highlight is only a cursor: typing never selects anything.
            document.querySelectorAll('[data-role="rank-location-field"]').forEach(function (field) {
                var input = field.querySelector('[data-role="rank-location-input"]');
                var code = field.querySelector('[data-role="rank-location-code"]');
                var list = field.querySelector('[data-role="rank-location-results"]');
                var hint = field.querySelector('[data-role="rank-location-hint"]');
                var idleHint = hint.textContent;
                var countryShort = {};
                try { var names = JSON.parse(field.getAttribute('data-countries') || '{}'); Object.keys(names).forEach(function (iso) { countryShort[names[iso]] = iso; }); } catch (e) { /* labels fall back to the full country name */ }
                var timer = null;
                var seq = 0;
                var active = -1;

                function changed() { field.dispatchEvent(new CustomEvent('rank-location-change', { bubbles: true })); }
                function say(text, bad) { hint.textContent = text; hint.classList.toggle('text-danger', !!bad); }
                function options() { return list.querySelectorAll('[data-role="rank-location-option"]'); }
                function setActive(i) {
                    var opts = options();
                    if (!opts.length) { active = -1; input.removeAttribute('aria-activedescendant'); return; }
                    active = (i + opts.length) % opts.length;
                    opts.forEach(function (o, n) { o.classList.toggle('is-active', n === active); o.setAttribute('aria-selected', n === active ? 'true' : 'false'); });
                    input.setAttribute('aria-activedescendant', opts[active].id);
                    opts[active].scrollIntoView({ block: 'nearest' });
                }
                function clearList() { list.innerHTML = ''; list.classList.add('d-none'); active = -1; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
                function pick(loc) {
                    seq++; clearTimeout(timer); // a response still in flight must not reopen the list
                    input.value = loc.label; code.value = loc.code;
                    clearList(); say('Location selected.', false); changed();
                }
                field.pickLocation = pick;
                field.resetPicker = function () { seq++; clearTimeout(timer); input.value = ''; code.value = ''; clearList(); say(idleHint, false); changed(); };

                // "Mississippi, United States" -> "Mississippi, US"
                function region(label) {
                    var parts = label.split(',').map(function (x) { return x.trim(); });
                    parts.shift();
                    if (parts.length && countryShort[parts[parts.length - 1]]) { parts[parts.length - 1] = countryShort[parts[parts.length - 1]]; }
                    return parts.join(', ');
                }
                function highlight(city, q) {
                    var b = document.createElement('b');
                    var at = city.toLowerCase().indexOf(q.toLowerCase());
                    if (at === 0 && q) {
                        var u = document.createElement('u'); u.textContent = city.slice(0, q.length);
                        b.appendChild(u); b.appendChild(document.createTextNode(city.slice(q.length)));
                    } else { b.textContent = city; }
                    return b;
                }
                function render(locs, q) {
                    clearList();
                    locs.forEach(function (loc, n) {
                        var row = document.createElement('div');
                        row.className = 'kwd-opt';
                        row.id = input.id + '-opt-' + n;
                        row.setAttribute('role', 'option');
                        row.setAttribute('aria-selected', 'false');
                        row.setAttribute('data-role', 'rank-location-option');
                        row.setAttribute('data-code', loc.code);
                        row.innerHTML = '<svg class="kwd-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.200-7-11.500a7 7 0 0 1 14 0C19 14.800 12 21 12 21z"></path><circle cx="12" cy="9.500" r="2.500"></circle></svg>';
                        row.appendChild(highlight(loc.label.split(',')[0].trim(), q));
                        var right = document.createElement('span'); right.textContent = region(loc.label);
                        row.appendChild(right);
                        row.setAttribute('aria-label', loc.label);
                        // mousedown keeps the focus in the input; the pick itself is the click.
                        row.addEventListener('mousedown', function (e) { e.preventDefault(); });
                        row.addEventListener('mousemove', function () { if (active !== n) { setActive(n); } });
                        row.addEventListener('click', function () { pick(loc); input.focus(); });
                        list.appendChild(row);
                    });
                    if (locs.length) { list.classList.remove('d-none'); input.setAttribute('aria-expanded', 'true'); setActive(0); }
                }

                input.addEventListener('input', function () {
                    code.value = ''; changed();
                    clearTimeout(timer);
                    var q = input.value.trim();
                    var mine = ++seq;
                    clearList();
                    if (q.length < 2) { say(idleHint, false); return; }
                    say('Searching…', false);
                    timer = setTimeout(function () {
                        fetch(field.getAttribute('data-url') + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                            .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
                            .then(function (data) {
                                if (mine !== seq) { return; } // a newer keystroke (or a pick) owns the list
                                var locs = data.locations || [];
                                render(locs, q);
                                if (locs.length) { say('Pick a city from the list.', false); }
                                else if (data.status === 'not_initialized') { say('Locations are not initialized yet. Tracking cannot start until they are loaded; you can still add the keyword without tracking.', true); }
                                else if (data.status === 'partial') { say('No matching city found. Some locations are still loading, so try again later.', true); }
                                else { say('No matching city. Check the spelling (supported countries: {{ \App\Library\Seo\Rank\SeoRankLocationCatalog::supportedCountriesLabel() }}).', true); }
                                changed();
                            })
                            .catch(function () {
                                if (mine !== seq) { return; }
                                clearList();
                                say('Locations are temporarily unavailable. Try again shortly, or add the keyword without tracking.', true);
                                changed();
                            });
                    }, 250);
                });
                input.addEventListener('keydown', function (e) {
                    var opts = options();
                    if (e.key === 'ArrowDown' && opts.length) { e.preventDefault(); setActive(active + 1); }
                    else if (e.key === 'ArrowUp' && opts.length) { e.preventDefault(); setActive(active - 1); }
                    else if (e.key === 'Enter') {
                        // Enter never submits anything by itself: it picks the highlighted row, if the list is showing.
                        e.preventDefault();
                        if (opts.length && active > -1) { opts[active].click(); }
                    } else if (e.key === 'Escape' && opts.length) {
                        // First Escape closes the suggestions; the next one closes the dialog.
                        e.preventDefault(); e.stopPropagation(); clearList();
                    }
                });
            });


            // Focus containment: Bootstrap 5.1 only reacts to focusin, so Tab off the last enabled control would land on <body> for a step.
            // Wrap explicitly instead.
            function trapTab(root) {
                root.addEventListener('keydown', function (e) {
                    if (e.key !== 'Tab') { return; }
                    var items = Array.prototype.filter.call(root.querySelectorAll('button, input, textarea, select, a[href], [tabindex]'), function (el) {
                        return !el.disabled && el.type !== 'hidden' && el.getAttribute('tabindex') !== '-1' && el.offsetParent !== null;
                    });
                    if (!items.length) { return; }
                    var first = items[0], last = items[items.length - 1];
                    if (e.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
                    else if (!e.shiftKey && (document.activeElement === last || !root.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
                });
            }

            // The chooser dialog: the action stays disabled until a real result is picked.
            var opener = null; // the control that launched the dialog; focus returns to it on close
            if (dialogEl) {
                var field = dialogEl.querySelector('[data-role="rank-location-field"]');
                var dInput = dialogEl.querySelector('[data-role="rank-location-input"]');
                var dCode = dialogEl.querySelector('[data-role="rank-location-code"]');
                var confirmBtn = dialogEl.querySelector('[data-role="rank-location-confirm"]');
                var skipBtn = dialogEl.querySelector('[data-role="rank-location-skip"]');
                var spacer = dialogEl.querySelector('[data-role="rank-location-spacer"]');
                var kwBox = dialogEl.querySelector('[data-role="rank-location-keyword"]');
                var kwHint = dialogEl.querySelector('[data-role="rank-location-keyword-hint"]');
                var kwPick = dialogEl.querySelector('[data-role="rank-location-keyword-pick"]');
                var hintSeq = 0;
                var hintLoc = null;

                trapTab(dialogEl);
                dialogEl.addEventListener('rank-location-change', function () { confirmBtn.disabled = !dCode.value; });
                skipBtn.addEventListener('click', function () {
                    var run = skipHandler; skipHandler = null; pending = null;
                    dialog().hide();
                    if (run) { run(); }
                });
                kwPick.addEventListener('click', function () { if (hintLoc) { field.pickLocation(hintLoc); dInput.focus(); } });
                // The overlay is the modal itself (no separate backdrop): a click on it, outside the card, cancels.
                dialogEl.addEventListener('mousedown', function (e) { if (e.target === dialogEl) { dialog().hide(); } });
                dialogEl.addEventListener('shown.bs.modal', function () { dInput.focus(); });
                dialogEl.addEventListener('hidden.bs.modal', function () {
                    hintSeq++; hintLoc = null; pending = null; skipHandler = null;
                    field.resetPicker();
                    confirmBtn.disabled = true; skipBtn.classList.add('d-none'); spacer.classList.remove('d-none'); kwHint.classList.add('d-none');
                    // Return focus to the launcher. A menu item that closed with its dropdown falls back to that menu's toggle.
                    var back = opener; opener = null;
                    if (back && !back.offsetParent) { back = back.closest('.dropdown') && back.closest('.dropdown').querySelector('[data-bs-toggle="dropdown"]'); }
                    if (back && back.focus) { back.focus(); }
                });
                confirmBtn.addEventListener('click', function () {
                    if (!dCode.value || !pending) { return; }
                    var chosen = { code: dCode.value, label: dInput.value };
                    var run = pending; pending = null;
                    dialog().hide();
                    run(chosen);
                });
            }
            // ctx: { phrase, scope } shown above the search box; the keyword's own city (if its phrase names one) is offered as a hint.
            function choose(then, skipFn, ctx) {
                pending = then;
                opener = document.activeElement;
                ctx = ctx || {};
                dialogEl.querySelector('[data-role="rank-location-keyword-phrase"]').textContent = ctx.phrase || '';
                dialogEl.querySelector('[data-role="rank-location-keyword-scope"]').textContent = ctx.scope || '';
                kwBox.classList.toggle('d-none', !ctx.phrase);
                skipBtn.classList.toggle('d-none', !skipFn);
                spacer.classList.toggle('d-none', !!skipFn);
                skipHandler = skipFn || null;
                var mine = ++hintSeq;
                kwHint.classList.add('d-none'); hintLoc = null;
                if (ctx.phrase && !ctx.noHint) {
                    fetch(field.getAttribute('data-url') + '?hint=' + encodeURIComponent(ctx.phrase), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (data) {
                            if (mine !== hintSeq || !data || !data.suggestion) { return; }
                            hintLoc = data.suggestion; kwPick.textContent = data.suggestion.short; kwHint.classList.remove('d-none');
                        })
                        .catch(function () { /* the hint is optional */ });
                }
                dialog().show();
            }

            // Start tracking with no default location: choose one, then post it.
            document.querySelectorAll('[data-rank-dialog="track"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    choose(function (chosen) {
                        var f = document.getElementById('rank-dialog-form');
                        f.action = btn.getAttribute('data-action');
                        f.querySelector('[name="search_location_code"]').value = chosen.code;
                        f.submit();
                    }, null, { phrase: btn.getAttribute('data-phrase'), scope: btn.getAttribute('data-scope') });
                });
            });

            // Add keyword: use the existing rank location silently; ask only when tracking is on and there is none.
            var addForm = document.querySelector('[data-role=keyword-add-form]');
            if (addForm) {
                var toggle = addForm.querySelector('[data-role="track-rank-toggle"]');
                var codeInput = addForm.querySelector('[data-role="add-location-code"]');
                var from = addForm.querySelector('[data-role="add-tracking-from"]');
                var label = addForm.querySelector('[data-role="add-location-label"]');
                var scope = addForm.querySelector('[name="location_uid"]');
                var chosenHere = null; // a location picked via Change / the chooser overrides the default

                function effective() {
                    if (chosenHere) { return chosenHere; }
                    var uid = scope ? scope.value : '';
                    return (uid && defaults.locations[uid]) || defaults.business || null;
                }
                function addContext() {
                    var phrase = addForm.querySelector('[name="phrase"]');
                    return { phrase: phrase ? phrase.value.trim() : '', scope: scope && scope.selectedOptions.length ? scope.selectedOptions[0].textContent.trim() : 'Whole business' };
                }
                function render() {
                    if (!codeInput) { return; }
                    var loc = effective();
                    codeInput.value = loc ? loc.code : '';
                    label.textContent = loc ? loc.label : '';
                    from.classList.toggle('d-none', !loc || !(toggle && toggle.checked && !toggle.disabled));
                }
                if (scope) { scope.addEventListener('change', render); }
                if (toggle) { toggle.addEventListener('change', render); }
                var change = addForm.querySelector('[data-role="change-location"]');
                if (change) { change.addEventListener('click', function () { choose(function (c) { chosenHere = c; render(); }, null, addContext()); }); }
                render();

                addForm.addEventListener('submit', function (e) {
                    if (!toggle || !codeInput || !toggle.checked || toggle.disabled || codeInput.value) { return; }
                    e.preventDefault();
                    choose(function (c) { chosenHere = c; render(); addForm.submit(); }, function () {
                        // Never trap the customer: save the keyword with rank tracking switched off.
                        toggle.checked = false; render(); addForm.submit();
                    }, addContext());
                });
            }

            // "Track" on a suggestion: save it AND track it from the existing default location; with none, choose one
            // (or save it without tracking). Without rank tracking the form is a plain add.
            function hasAnyDefault() {
                return !!(defaults.business || Object.keys(defaults.locations || {}).some(function (k) { return defaults.locations[k]; }));
            }
            document.querySelectorAll('[data-role="suggestion-form"]').forEach(function (form) {
                var code = form.querySelector('[data-role="suggestion-location-code"]');
                if (!code) { return; }
                form.addEventListener('submit', function (e) {
                    if (code.value) { return; }
                    var uid = form.getAttribute('data-location-uid');
                    var loc = (uid && defaults.locations[uid]) || defaults.business || null;
                    if (loc) { code.value = loc.code; return; }
                    e.preventDefault();
                    var row = form.closest('[data-role="suggested-keyword"]');
                    choose(function (c) { code.value = c.code; form.submit(); }, function () {
                        form.querySelector('[name="track_rank"]').value = '0'; form.submit();
                    }, { phrase: row ? row.getAttribute('data-phrase') : '', scope: row ? (row.querySelector('.kw-mut') || {}).textContent : '' });
                });
            });

            // Track all suggestions: the server recomputes the list; we only supply a location when none exists yet.
            var trackAll = document.querySelector('[data-role="track-all-form"]');
            if (trackAll) {
                trackAll.addEventListener('submit', function (e) {
                    var code = trackAll.querySelector('[data-role="track-all-code"]');
                    if (code.value || hasAnyDefault()) { return; }
                    e.preventDefault();
                    choose(function (c) { code.value = c.code; trackAll.submit(); }, null, { phrase: 'All suggested keywords', scope: 'Suggested for you', noHint: true });
                });
            }

            // Show all N: reveal the rows beyond the first few.
            var showAll = document.querySelector('[data-role="show-all"]');
            if (showAll) {
                showAll.addEventListener('click', function () {
                    document.querySelectorAll('tr[data-extra]').forEach(function (tr) { tr.hidden = false; });
                    showAll.remove();
                });
            }

            // Menus sit inside the scrolling table: position them fixed so they are never clipped.
            window.addEventListener('load', function () {
                document.querySelectorAll('[data-role=keyword-menu]').forEach(function (btn) {
                    window.bootstrap.Dropdown.getOrCreateInstance(btn, { popperConfig: { strategy: 'fixed' } });
                });
            });

            // Edit: one shared dialog, never a form inside a row.
            document.querySelectorAll('[data-role="keyword-edit"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var f = document.getElementById('keyword-edit-form');
                    f.action = btn.getAttribute('data-edit-action');
                    f.querySelector('[name="phrase"]').value = btn.getAttribute('data-phrase');
                    f.querySelector('[name="location_uid"]').value = btn.getAttribute('data-location') || '';
                    window.bootstrap.Modal.getOrCreateInstance(document.getElementById('keyword-edit-dialog')).show();
                });
            });
        })();
    </script>
@endsection
