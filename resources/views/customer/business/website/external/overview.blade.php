@extends('customer.business.website.external.layout')

@section('external-content')
    @include('customer.business.website.external._status')

    @php
        $indexLabels = [
            'indexable' => ['Search engines can find your site', 'success'],
            'blocked_by_robots' => ['Your robots.txt file asks search engines to stay out', 'danger'],
            'noindex' => ['Your home page is hidden from search engines', 'danger'],
            'unreachable' => ['Your home page could not be loaded', 'danger'],
        ];
        $index = $crawl !== null ? ($indexLabels[$crawl->indexability] ?? ['Not checked yet', 'neutral']) : ['Not checked yet', 'neutral'];
    @endphp

    {{-- 1. What to fix next --}}
    <x-card :padded="true" class="mb-2" data-role="fix-next">
        <p class="text-label mb-1">What should you fix next?</p>
        @if($crawl === null)
            <p class="mb-0 text-muted" data-role="fix-next-empty">Once your website has been checked, the most important thing to improve will appear here.</p>
        @elseif($topIssue === null)
            <h2 class="h4 mb-1" data-role="fix-next-title">Nothing needs fixing right now.</h2>
            <p class="mb-0 text-muted">We checked {{ $crawl->pages_fetched }} {{ $crawl->pages_fetched === 1 ? 'page' : 'pages' }} and found no problems.</p>
        @else
            <div class="d-flex align-items-center flex-wrap gap-1 mb-1">
                <x-badge :variant="$topIssue['severity']->value === 'critical' ? 'danger' : ($topIssue['severity']->value === 'warning' ? 'warning' : 'neutral')">{{ ucfirst($topIssue['severity']->value) }}</x-badge>
            </div>
            <h2 class="h4 mb-1" data-role="fix-next-title">{{ $topIssue['title'] }}</h2>
            <p class="mb-1" data-role="fix-next-description">{{ $topIssue['description'] }}</p>
            @if(! $topIssue['site_level'])
                <p class="text-muted" data-role="fix-next-count">{{ $topIssue['count'] }} {{ $topIssue['count'] === 1 ? 'page is' : 'pages are' }} affected.</p>
            @endif
            <a class="btn btn-primary" data-role="fix-next-cta" href="{{ route('customer.workspaces.businesses.website.external.audit', [$workspaceUid, $businessUid, 'rule' => $topIssue['rule_key']]) }}">Review affected pages</a>
        @endif
    </x-card>

    {{-- 2. Numbers --}}
    <div class="row" data-role="external-kpis">
        <div class="col-6 col-lg mb-2"><x-card class="h-100" data-kpi="pages"><p class="text-label mb-1">Pages found</p><p class="h3 mb-0">{{ $crawl?->pages_discovered ?? '—' }}</p><p class="text-caption text-muted mb-0">{{ $crawl ? $crawl->pages_fetched.' checked' : 'Not checked yet' }}</p></x-card></div>
        <div class="col-6 col-lg mb-2"><x-card class="h-100" data-kpi="critical"><p class="text-label mb-1">Critical issues</p><p class="h3 mb-0">{{ $crawl?->critical_count ?? '—' }}</p></x-card></div>
        <div class="col-6 col-lg mb-2"><x-card class="h-100" data-kpi="seo"><p class="text-label mb-1">Search issues</p><p class="h3 mb-0">{{ $crawl ? $crawl->warning_count + $crawl->info_count : '—' }}</p></x-card></div>
        <div class="col-6 col-lg mb-2"><x-card class="h-100" data-kpi="broken"><p class="text-label mb-1">Broken links</p><p class="h3 mb-0">{{ $crawl?->broken_links ?? '—' }}</p></x-card></div>
        <div class="col-12 col-lg mb-2"><x-card class="h-100" data-kpi="indexability"><p class="text-label mb-1">Search visibility</p><p class="mb-0" data-role="indexability"><x-badge :variant="$index[1]">{{ $index[0] }}</x-badge></p></x-card></div>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2">
        <span class="text-caption text-muted" data-role="last-checked">
            @if($crawl?->finished_at) Last checked {{ $crawl->finished_at->diffForHumans() }} @else Not checked yet @endif
        </span>
        <form method="POST" action="{{ $crawlUrl }}">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary" data-role="check-again">Check again now</button>
        </form>
    </div>

    {{-- 3. Business recommendations: kept apart from the technical audit on purpose --}}
    @if($goalsWithoutDestination->isNotEmpty() || $goalIntents->isNotEmpty())
        <x-card :padded="true" class="mb-2" data-role="acquisition-recommendations">
            <p class="text-label mb-1">Ideas to win more of what you are after</p>
            <p class="text-caption text-muted">These are suggestions based on your goals. They are not problems with your website.</p>

            @foreach($goalsWithoutDestination as $goal)
                <div class="mb-1" data-role="acquisition-recommendation" data-goal="{{ $goal->purpose_key }}">
                    <strong>{{ $goal->name }} has no dedicated landing page.</strong>
                    <span class="text-muted">A page made for {{ strtolower($goal->website_intent['audience'] ?? 'this audience') }}, ending in {{ $goal->website_intent['cta'] ?? 'one clear action' }}, usually works better than sending people to your home page.</span>
                    @if($adsGoalsUrl)
                        <a href="{{ $adsGoalsUrl }}">Choose the page</a>
                    @endif
                </div>
            @endforeach

            @foreach($goalIntents as $goal)
                @if($goal->hasDestination() && $goal->destination_url)
                    <div class="mb-1 text-muted" data-role="goal-destination" data-goal="{{ $goal->purpose_key }}">{{ $goal->name }} sends people to <a href="{{ $goal->destination_url }}" target="_blank" rel="noopener noreferrer">{{ $goal->destination_url }}</a>.</div>
                @endif
            @endforeach

            @foreach($goalIntents as $goal)
                <details class="mb-1" data-role="niche-pages" data-goal="{{ $goal->purpose_key }}">
                    <summary>Pages that usually help for {{ $goal->name }}</summary>
                    <ul class="mb-0 mt-1">
                        @foreach($goal->website_intent['pages'] as $suggested)
                            <li><strong>{{ $suggested['title'] }}</strong>@if(! empty($suggested['summary'])) &mdash; {{ $suggested['summary'] }}@endif</li>
                        @endforeach
                    </ul>
                </details>
            @endforeach
        </x-card>
    @endif
@endsection
