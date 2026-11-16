@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;

        $c = $report?->coverageCounts() ?? [];
        $summary = (array) ($report?->summary ?? []);
        $prio = (array) ($summary['priority'] ?? []);
        $index = P::indexability($report?->indexability);
        $pagesAffected = (int) ($summary['pages_affected'] ?? 0);
        $urgent = (int) ($prio['critical'] ?? 0) + (int) ($prio['high'] ?? 0);
        $minor = (int) ($prio['medium'] ?? 0) + (int) ($prio['low'] ?? 0);
        $broken = (int) ($summary['broken_destinations'] ?? 0);
        $chevron = '<svg class="xi" viewBox="0 0 24 24" style="width:14px;height:14px"><path d="M10 7l5 5-5 5"/></svg>';
    @endphp

    @if($report === null && $earlier !== null)
        {{-- A check made by the first version of the audit: only its counts survive. --}}
        <section class="xw-card xw-card-pad xw-stack" data-role="earlier-format">
            <h2 class="xw-h">Your last check used an earlier version of the audit</h2>
            <p class="xw-p">On {{ $earlier->finished_at?->toFormattedDateString() }} we checked {{ $earlier->pages_fetched }} {{ $earlier->pages_fetched === 1 ? 'page' : 'pages' }} and counted {{ $earlier->totalFindings() }} item(s). The audit now keeps the evidence behind every finding (which page, what was seen, how to fix it), which that check did not record.</p>
            <p class="xw-p">Choose <strong>Check again now</strong> to get the full report and an improvement plan.</p>
        </section>
    @elseif($report === null)
        {{-- Nothing to report yet. The banner above says whether a check is running or why the last one failed. --}}
        @if($latest === null || $latest->status === 'cancelled')
            <section class="xw-card xw-card-pad-lg xw-stack" data-role="connect" style="gap:12px">
                <h2 class="xw-h xw-h-lg">Your website</h2>
                @if($websiteUrl !== '')
                    <p class="xw-p">MotionGrove will read the public pages of <strong>{{ preg_replace('#^https?://#', '', rtrim($websiteUrl, '/')) }}</strong> and tell you what to improve. It never signs in, submits a form or changes anything on the site.</p>
                    <p class="xw-mut">Not the right address? <a class="xw-lk xw-lk-sm" href="{{ $settingsUrl }}">Change it in Settings</a>.</p>
                @else
                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.settings.update', [$workspaceUid, $businessUid]) }}" class="xw-addr" data-role="connect-form" style="align-items:flex-end">
                        @csrf
                        <div class="xw-field" style="flex:1 1 320px">
                            <label for="connect-website-url">Website address</label>
                            <input class="xw-in" id="connect-website-url" name="website_url" value="{{ old('website_url', $business->website_url) }}" placeholder="example.com" maxlength="2048">
                        </div>
                        <button type="submit" class="xw-btn xw-btn-primary">Connect and start audit</button>
                    </form>
                @endif
            </section>
        @endif
    @else
        @if($latest !== null && $latest->id !== $report->id && ! $latest->isActive())
            <p class="xw-mut" data-role="showing-previous">Showing results from {{ $report->finished_at?->toDayDateTimeString() }}.</p>
        @endif

        {{-- Verdict: derived only from this audit's own priority counts --}}
        <section class="xw-card xw-verdict {{ $urgent > 0 ? 'is-warn' : '' }}" data-role="verdict" data-state="{{ $urgent > 0 ? 'attention' : ($minor > 0 ? 'good' : 'clean') }}">
            <span class="xw-verdict-ic" aria-hidden="true">
                @if($urgent > 0)
                    <svg class="xi" viewBox="0 0 24 24"><path d="M12 8v5M12 16.500h.01M10.300 4.200L3 17a2 2 0 0 0 1.700 3h14.600a2 2 0 0 0 1.700-3L13.700 4.200a2 2 0 0 0-3.400 0z"/></svg>
                @else
                    <svg class="xi" viewBox="0 0 24 24"><path d="M5 12.500l4.500 4.500L19 7.500"/></svg>
                @endif
            </span>
            <div class="xw-verdict-text">
                @if($urgent > 0)
                    <b>Your website needs attention</b>
                    <span>{{ $urgent }} critical or high-priority {{ $urgent === 1 ? 'problem' : 'problems' }} found@if($minor > 0), plus {{ $minor }} smaller {{ $minor === 1 ? 'improvement' : 'improvements' }}@endif. Start with the first item below.</span>
                @elseif($minor > 0)
                    <b>Your website is in good shape</b>
                    <span>No critical or high-priority problems. {{ $minor }} small {{ $minor === 1 ? 'improvement' : 'improvements' }} worth doing.</span>
                @else
                    <b>Your website is in good shape</b>
                    <span>No problems or improvements found on the pages we audited.</span>
                @endif
            </div>
            <a href="{{ $planUrl }}" class="xw-btn xw-btn-line" data-role="open-plan">Open improvement plan@if($plan !== null && $plan['counts']['items'] > 0) <span class="xw-faint">({{ $plan['counts']['items'] }} item(s))</span>@endif</a>
        </section>

        {{-- Numbers: precise labels, no invented score --}}
        <div class="xw-kpis" data-role="external-kpis">
            <div class="xw-kpi" data-kpi="pages"><span>Pages audited</span><b>{{ $c['audited'] }}</b><small>of {{ $c['discovered'] }} addresses found</small></div>
            <div class="xw-kpi" data-kpi="critical"><span>Critical issues</span><b class="{{ ($prio['critical'] ?? 0) > 0 ? 'is-bad' : 'is-ok' }}">{{ $prio['critical'] ?? 0 }}</b><small>each issue can cover several pages</small></div>
            <div class="xw-kpi" data-kpi="high"><span>High priority</span><b class="{{ ($prio['high'] ?? 0) > 0 ? 'is-bad' : 'is-ok' }}">{{ $prio['high'] ?? 0 }}</b><small>{{ $urgent === 0 ? 'nothing urgent' : 'worth doing soon' }}</small></div>
            <div class="xw-kpi" data-kpi="other"><span>Other actionable</span><b>{{ $minor }}</b><small>medium and low</small></div>
            <div class="xw-kpi" data-kpi="broken"><span>Broken internal links</span><b class="{{ $broken > 0 ? 'is-bad' : 'is-ok' }}">{{ $broken }}</b><small>{{ $broken }} broken destination(s), {{ $summary['broken_link_instances'] ?? 0 }} link(s) to fix</small></div>
            <div class="xw-kpi" data-kpi="indexability"><span>Technical indexability</span><b class="is-text" data-role="indexability">{{ $index['label'] }}</b><small>Google indexing: not verified</small></div>
        </div>

    @endif

    <div class="xw-cols @if($report === null) is-single @endif">
        <div class="xw-col-main" style="display:flex;flex-direction:column;gap:18px">
            @if($report !== null)
                {{-- 1. What should I fix first? --}}
                <section class="xw-card xw-card-flat" data-role="fix-first" aria-label="What should I fix first?">
                    <div class="xw-ch"><h2 class="xw-h">Fix these first</h2><span class="xw-mut">Most impact on visitors first</span></div>
                    @if($plan === null || $plan['start_here'] === [])
                        <div class="xw-rw" data-role="fix-first-empty" style="display:block">
                            <b style="font-size:15px">Nothing needs fixing right now.</b>
                            <p class="xw-mut" style="margin:4px 0 0">We audited {{ $c['audited'] }} {{ $c['audited'] === 1 ? 'page' : 'pages' }} and found no problems or improvements.</p>
                        </div>
                    @else
                        @foreach($plan['start_here'] as $item)
                            @php [$kindText, $kindTone] = P::kindTag($item['kind']); @endphp
                            <div class="xw-rw" data-role="fix-first-item" data-rule="{{ $item['rule_key'] }}" data-priority="{{ $item['priority'] }}">
                                <span class="xw-num">{{ $loop->iteration }}</span>
                                <div class="xw-rw-body">
                                    <div class="xw-rw-title">
                                        <b data-role="fix-first-title">{{ $item['title'] }}</b>
                                        <span class="xw-tg {{ $kindTone }}" title="{{ $item['kind_label'] }}">{{ $kindText }}</span>
                                        @if(in_array($item['priority'], ['critical', 'high', 'medium'], true))<span class="xw-tg {{ P::toneClass(P::priorityVariant($item['priority'])) }}">{{ $item['priority_label'] }}</span>@endif
                                    </div>
                                    <span class="xw-mut" title="Why it matters: {{ $item['relevance'] }}">{{ $item['what'] }} &middot; @if($item['site_level'] || $item['page_count'] === 0) Whole site @else {{ $item['page_count'] }} {{ $item['page_count'] === 1 ? 'page' : 'pages' }} @endif</span>
                                </div>
                                <div class="xw-rw-actions">
                                    <a class="xw-lk" data-role="view-fix" aria-label="View recommended fix: {{ $item['title'] }}" href="{{ $planUrl }}#plan-{{ $item['rule_key'] }}">See fix {!! $chevron !!}</a>
                                    <a class="xw-lk xw-lk-sm xw-faint" data-role="view-pages" href="{{ $auditUrl }}?rule={{ $item['rule_key'] }}#rule-{{ $item['rule_key'] }}">View affected pages</a>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </section>
            @endif

            {{-- Business recommendations: kept apart from the technical audit on purpose --}}
            @if($goalsWithoutDestination->isNotEmpty() || $goalIntents->isNotEmpty())
                <section class="xw-card xw-card-pad xw-stack" data-role="acquisition-recommendations">
                    <h2 class="xw-h">Ideas to win more of what you are after</h2>
                    <p class="xw-mut">These are suggestions based on your goals. They are not problems with your website.</p>

                    @foreach($goalsWithoutDestination as $goal)
                        <div class="xw-idea" data-role="acquisition-recommendation" data-goal="{{ $goal->purpose_key }}">
                            <strong>{{ $goal->name }} has no dedicated landing page.</strong>
                            <span>A page made for {{ strtolower($goal->website_intent['audience'] ?? 'this audience') }}, ending in {{ $goal->website_intent['cta'] ?? 'one clear action' }}, usually works better than sending people to your home page.</span>
                            @if($adsGoalsUrl)
                                <a class="xw-lk" href="{{ $adsGoalsUrl }}">Choose the page</a>
                            @endif
                        </div>
                    @endforeach

                    @foreach($goalIntents as $goal)
                        @if($goal->hasDestination() && $goal->destination_url)
                            <div class="xw-idea" data-role="goal-destination" data-goal="{{ $goal->purpose_key }}">{{ $goal->name }} sends people to <a class="xw-lk" href="{{ $goal->destination_url }}" target="_blank" rel="noopener noreferrer">{{ $goal->destination_url }}</a>.</div>
                        @endif
                    @endforeach

                    @foreach($goalIntents as $goal)
                        <details class="xw-idea" data-role="niche-pages" data-goal="{{ $goal->purpose_key }}">
                            <summary style="cursor:pointer;font-weight:600">Pages that usually help for {{ $goal->name }}</summary>
                            <ul style="margin:8px 0 0">
                                @foreach($goal->website_intent['pages'] as $suggested)
                                    <li><strong>{{ $suggested['title'] }}</strong>@if(! empty($suggested['summary'])) &mdash; {{ $suggested['summary'] }}@endif</li>
                                @endforeach
                            </ul>
                        </details>
                    @endforeach
                </section>
            @endif
        </div>

        <div class="xw-col-side">
            @if($report !== null)
                {{-- 2. Coverage --}}
                <section class="xw-card xw-card-pad xw-stack" data-role="coverage">
                    <h2 class="xw-h">What was checked</h2>
                    <p class="xw-p" data-role="coverage-sentence">{{ P::coverageSentence($c) }}</p>
                    <p class="xw-mut">
                        This check reads at most {{ $report->page_budget ?? '—' }} pages, one at a time. @if($report->finish_reason && $report->finish_reason !== 'completed') {{ P::finishReason($report->finish_reason, $report->coverage) }} @else Everything eligible within that limit was checked. @endif
                    </p>
                    <a class="xw-lk" href="{{ $pagesUrl }}" style="margin-top:4px">See all pages {!! $chevron !!}</a>
                </section>

                {{-- 3. Not measured --}}
                <section class="xw-card xw-card-pad xw-stack" data-role="not-measured" style="gap:4px">
                    <h2 class="xw-h" style="margin-bottom:6px">Not measured here</h2>
                    @foreach($notMeasured as $item)
                        <div class="xw-nm" title="{{ $item['detail'] }}">
                            <span class="xw-nm-body">{{ $item['label'] }}</span>
                            <span class="xw-nm-end">{{ $item['status'] }}</span>
                        </div>
                    @endforeach
                </section>

                {{-- 4. Changes since the last check, with the recent history --}}
                <section class="xw-card xw-card-pad xw-stack" data-role="changes" style="gap:8px">
                    <h2 class="xw-h">Since the previous check</h2>
                    @if($diff === null)
                        <p class="xw-mut">No earlier check of this website address to compare with yet.</p>
                    @else
                        <p class="xw-p" data-role="changes-summary">
                            <strong>{{ $diff['new'] }}</strong> new &middot; <strong>{{ $diff['resolved'] }}</strong> resolved &middot; <strong>{{ $diff['unchanged'] }}</strong> unchanged
                            <span class="xw-mut">(compared on the {{ $diff['pages_in_both'] }} pages both checks audited)</span>
                        </p>
                        <p class="xw-mut">
                            Pages audited: {{ $diff['pages_before'] }} before, {{ $diff['pages_now'] }} now.
                            @if(! $diff['same_coverage']) The two checks did not cover the same pages, so findings on pages only one of them audited are not counted as new or resolved: {{ $diff['newly_covered'] }} on newly checked pages, {{ $diff['not_rechecked'] }} on pages not re-checked. @endif
                        </p>
                    @endif
                </section>
            @endif

            {{-- Audit history --}}
            @if($history->isNotEmpty())
                <section class="xw-card xw-card-pad xw-stack" data-role="history" style="gap:8px">
                    <div class="xw-rw-title"><h2 class="xw-h" style="flex:1">Recent checks</h2><a href="{{ $settingsUrl }}" class="xw-lk xw-lk-sm">History</a></div>
                    @foreach($history as $check)
                        <div class="xw-hist" data-role="history-row">
                            <span class="xw-tg {{ P::toneClass(P::statusVariant($check)) }}">{{ P::statusLabel($check) }}</span>
                            <span class="xw-hist-main">{{ $check->created_at?->format('M j, g:i A') }}</span>
                            <span class="xw-mut">
                                @if($check->hasResults()){{ $check->pages_audited ?: $check->pages_fetched }} pages &middot; {{ $check->critical_count }} critical, {{ $check->warning_count + $check->info_count }} other &middot; @endif{{ preg_replace('#^www\.#', '', $check->host) }}
                            </span>
                        </div>
                    @endforeach
                </section>
            @endif
        </div>
    </div>
@endsection
