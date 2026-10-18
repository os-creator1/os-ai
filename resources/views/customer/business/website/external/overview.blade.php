@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;

        $c = $report?->coverageCounts() ?? [];
        $summary = (array) ($report?->summary ?? []);
        $prio = (array) ($summary['priority'] ?? []);
        $index = P::indexability($report?->indexability);
        $pagesAffected = (int) ($summary['pages_affected'] ?? 0);
    @endphp

    @if($report === null && $earlier !== null)
        {{-- A check made by the first version of the audit: only its counts survive. --}}
        <x-card :padded="true" class="mb-2" data-role="earlier-format">
            <p class="text-label mb-1">Your last check used an earlier version of the audit</p>
            <p class="mb-1">On {{ $earlier->finished_at?->toFormattedDateString() }} we checked {{ $earlier->pages_fetched }} {{ $earlier->pages_fetched === 1 ? 'page' : 'pages' }} and counted {{ $earlier->totalFindings() }} item(s). The audit now keeps the evidence behind every finding (which page, what was seen, how to fix it), which that check did not record.</p>
            <p class="text-muted mb-0">Choose <strong>Check again now</strong> to get the full report and an improvement plan.</p>
        </x-card>
    @elseif($report === null)
        {{-- Nothing to report yet. The banner above says whether a check is running or why the last one failed. --}}
        @if($latest === null || $latest->status === 'cancelled')
            <x-card :padded="true" class="mb-2" data-role="connect">
                <p class="text-label mb-1">Your website</p>
                @if($websiteUrl !== '')
                    <p class="mb-1">MotionGrove will read the public pages of <strong>{{ preg_replace('#^https?://#', '', rtrim($websiteUrl, '/')) }}</strong> and tell you what to improve. It never signs in, submits a form or changes anything on the site.</p>
                    <p class="text-caption text-muted mb-0">Not the right address? <a href="{{ $settingsUrl }}">Change it in Settings</a>.</p>
                @else
                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.settings.update', [$workspaceUid, $businessUid]) }}" class="row g-1 align-items-end" data-role="connect-form">
                        @csrf
                        <div class="col-md-8">
                            <label class="form-label" for="connect-website-url">Website address</label>
                            <input class="form-control" id="connect-website-url" name="website_url" value="{{ old('website_url', $business->website_url) }}" placeholder="example.com" maxlength="2048">
                        </div>
                        <div class="col-md-4"><button type="submit" class="btn btn-primary w-100">Connect and start audit</button></div>
                    </form>
                @endif
            </x-card>
        @endif
    @else
        @if($latest !== null && $latest->id !== $report->id && ! $latest->isActive())
            <p class="text-caption text-muted mb-1" data-role="showing-previous">Showing results from {{ $report->finished_at?->toDayDateTimeString() }}.</p>
        @endif

        {{-- 1. What should I fix first? --}}
        <x-card :padded="true" class="mb-2" data-role="fix-first">
            <p class="text-label mb-1">What should I fix first?</p>
            @if($plan['start_here'] === [])
                <h2 class="h4 mb-1" data-role="fix-first-empty">Nothing needs fixing right now.</h2>
                <p class="mb-0 text-muted">We audited {{ $c['audited'] }} {{ $c['audited'] === 1 ? 'page' : 'pages' }} and found no problems or improvements.</p>
            @else
                <p class="text-caption text-muted">Ordered by how much each item can affect visitors reaching, contacting or booking you, not by how many pages it touches.</p>
                <ol class="list-unstyled mb-0">
                    @foreach($plan['start_here'] as $item)
                        <li class="border-top pt-1 mt-1" data-role="fix-first-item" data-rule="{{ $item['rule_key'] }}" data-priority="{{ $item['priority'] }}">
                            <div class="d-flex align-items-center flex-wrap gap-1 mb-50">
                                <x-badge :variant="P::priorityVariant($item['priority'])">{{ $item['priority_label'] }}</x-badge>
                                <x-badge variant="neutral">{{ $item['kind_label'] }}</x-badge>
                                <strong data-role="fix-first-title">{{ $item['title'] }}</strong>
                                <span class="text-caption text-muted">
                                    @if($item['site_level'] || $item['page_count'] === 0) whole site @else {{ $item['page_count'] }} {{ $item['page_count'] === 1 ? 'page' : 'pages' }} @endif
                                </span>
                            </div>
                            <p class="mb-50">{{ $item['what'] }}</p>
                            <p class="text-muted mb-50"><em>Why it matters:</em> {{ $item['relevance'] }}</p>
                            <div class="d-flex flex-wrap gap-1">
                                <a class="btn btn-sm btn-outline-primary" data-role="view-pages" href="{{ $auditUrl }}?rule={{ $item['rule_key'] }}#rule-{{ $item['rule_key'] }}">View affected pages</a>
                                <a class="btn btn-sm btn-outline-secondary" data-role="view-fix" href="{{ $planUrl }}#plan-{{ $item['rule_key'] }}">View recommended fix</a>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <p class="mt-1 mb-0"><a href="{{ $planUrl }}" data-role="open-plan">See the full improvement plan ({{ $plan['counts']['items'] }} item(s))</a></p>
            @endif
        </x-card>

        {{-- 2. Numbers: precise labels, no invented score --}}
        <div class="row" data-role="external-kpis">
            <div class="col-6 col-lg mb-2"><x-card class="h-100" data-kpi="pages"><p class="text-label mb-1">Pages audited</p><p class="h3 mb-0">{{ $c['audited'] }}</p><p class="text-caption text-muted mb-0">of {{ $c['discovered'] }} addresses found</p></x-card></div>
            <div class="col-6 col-lg mb-2"><x-card class="h-100" data-kpi="critical"><p class="text-label mb-1">Critical issues</p><p class="h3 mb-0">{{ $prio['critical'] ?? 0 }}</p><p class="text-caption text-muted mb-0">each issue can cover several pages</p></x-card></div>
            <div class="col-6 col-lg mb-2"><x-card class="h-100" data-kpi="high"><p class="text-label mb-1">High priority</p><p class="h3 mb-0">{{ $prio['high'] ?? 0 }}</p></x-card></div>
            <div class="col-6 col-lg mb-2"><x-card class="h-100" data-kpi="other"><p class="text-label mb-1">Other actionable</p><p class="h3 mb-0">{{ ($prio['medium'] ?? 0) + ($prio['low'] ?? 0) }}</p><p class="text-caption text-muted mb-0">medium and low</p></x-card></div>
            <div class="col-6 col-lg mb-2"><x-card class="h-100" data-kpi="broken"><p class="text-label mb-1">Broken internal links</p><p class="h3 mb-0">{{ $summary['broken_destinations'] ?? 0 }}</p><p class="text-caption text-muted mb-0">{{ $summary['broken_destinations'] ?? 0 }} broken destination(s), {{ $summary['broken_link_instances'] ?? 0 }} link(s) to fix</p></x-card></div>
        </div>

        <div class="row">
            <div class="col-lg-6 mb-2">
                <x-card :padded="true" class="h-100" data-kpi="indexability">
                    <p class="text-label mb-1">Technical indexability</p>
                    <p class="mb-1" data-role="indexability"><x-badge :variant="$index['variant']">{{ $index['label'] }}</x-badge></p>
                    <p class="text-caption text-muted mb-0">{{ $index['detail'] }}</p>
                    <p class="text-caption text-muted mb-0">Google indexing and search performance: <strong>Not verified</strong> (Search Console is not connected).</p>
                </x-card>
            </div>
            <div class="col-lg-6 mb-2">
                {{-- 3. Coverage --}}
                <x-card :padded="true" class="h-100" data-role="coverage">
                    <p class="text-label mb-1">What was checked</p>
                    <p class="mb-1" data-role="coverage-sentence">{{ P::coverageSentence($c) }}</p>
                    <p class="text-caption text-muted mb-0">
                        This check reads at most {{ $report->page_budget ?? '—' }} pages, one at a time. @if($report->finish_reason && $report->finish_reason !== 'completed') {{ P::finishReason($report->finish_reason, $report->coverage) }} @else Everything eligible within that limit was checked. @endif
                    </p>
                </x-card>
            </div>
        </div>

        {{-- 4. Changes since the last check --}}
        <x-card :padded="true" class="mb-2" data-role="changes">
            <p class="text-label mb-1">Since the previous check</p>
            @if($diff === null)
                <p class="text-muted mb-0">No earlier check of this website address to compare with yet.</p>
            @else
                <p class="mb-1" data-role="changes-summary">
                    <strong>{{ $diff['new'] }}</strong> new &middot; <strong>{{ $diff['resolved'] }}</strong> resolved &middot; <strong>{{ $diff['unchanged'] }}</strong> unchanged
                    <span class="text-muted">(compared on the {{ $diff['pages_in_both'] }} pages both checks audited)</span>
                </p>
                <p class="text-caption text-muted mb-0">
                    Pages audited: {{ $diff['pages_before'] }} before, {{ $diff['pages_now'] }} now.
                    @if(! $diff['same_coverage']) The two checks did not cover the same pages, so findings on pages only one of them audited are not counted as new or resolved: {{ $diff['newly_covered'] }} on newly checked pages, {{ $diff['not_rechecked'] }} on pages not re-checked. @endif
                </p>
            @endif
        </x-card>

        {{-- 5. Not measured --}}
        <x-card :padded="true" class="mb-2" data-role="not-measured">
            <p class="text-label mb-1">Not measured</p>
            <ul class="mb-0">
                @foreach($notMeasured as $item)
                    <li class="mb-50"><strong>{{ $item['label'] }}</strong> <x-badge variant="neutral">{{ $item['status'] }}</x-badge><br><span class="text-caption text-muted">{{ $item['detail'] }}</span></li>
                @endforeach
            </ul>
        </x-card>
    @endif

    {{-- 6. Audit history --}}
    @if($history->isNotEmpty())
        <x-card :padded="true" class="mb-2" data-role="history">
            <div class="d-flex justify-content-between align-items-center mb-1"><p class="text-label mb-0">Recent checks</p><a href="{{ $settingsUrl }}" class="text-caption">Full history</a></div>
            @foreach($history as $check)
                <p class="mb-50" data-role="history-row">
                    {{ $check->created_at?->format('M j, Y H:i') }} &middot; <strong>{{ P::statusLabel($check) }}</strong>
                    @if($check->hasResults()) &middot; {{ $check->pages_audited ?: $check->pages_fetched }} pages audited, {{ $check->critical_count }} critical, {{ $check->warning_count + $check->info_count }} other @endif
                    <span class="text-muted">&middot; {{ preg_replace('#^www\.#', '', $check->host) }}</span>
                </p>
            @endforeach
        </x-card>
    @endif

    {{-- 7. Business recommendations: kept apart from the technical audit on purpose --}}
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
