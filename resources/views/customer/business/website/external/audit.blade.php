@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;

        $detailRoute = fn (int $id): string => route('customer.workspaces.businesses.website.external.page', [$workspaceUid, $businessUid, $id]);
        $openRule = request()->query('rule');
    @endphp

    @if($report === null)
        <section class="xw-card xw-card-pad" data-role="audit-empty">
            <p class="xw-p" style="margin:0">
                @if($earlier !== null) Your last check used an earlier version of the audit, which did not keep the evidence behind each finding. Check again to get the full audit.
                @else The audit appears here once your website has been checked. @endif
            </p>
        </section>
    @else
        {{-- Filters: real GET parameters, so a filtered view can be shared and bookmarked. Each select applies on change. --}}
        @php
            $chev = '<svg class="xi" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>';
            $submitOnChange = 'onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"';
        @endphp
        <form method="GET" action="{{ $auditUrl }}" class="xw-filters" data-role="audit-filters">
            <label class="xw-search" for="f-q"><svg class="xi" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.500"/><path d="M16 16l4.500 4.500"/></svg><input type="search" id="f-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search issues or pages" aria-label="Search issue or page"></label>
            <label class="xw-sel" for="f-severity"><span>Severity</span>
                <select id="f-severity" name="severity" {!! $submitOnChange !!}>
                    <option value="">All</option>
                    @foreach($priorities as $key => $label)<option value="{{ $key }}" @selected(($filters['severity'] ?? '') === $key)>{{ $label }}</option>@endforeach
                </select>{!! $chev !!}</label>
            <label class="xw-sel" for="f-category"><span>Category</span>
                <select id="f-category" name="category" {!! $submitOnChange !!}>
                    <option value="">All</option>
                    @foreach($categories as $key => $label)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $label }}</option>@endforeach
                </select>{!! $chev !!}</label>
            <label class="xw-sel" for="f-kind"><span>Type</span>
                <select id="f-kind" name="kind" {!! $submitOnChange !!}>
                    <option value="">All</option>
                    @foreach($kinds as $key => $label)<option value="{{ $key }}" @selected(($filters['kind'] ?? '') === $key)>{{ $label }}</option>@endforeach
                </select>{!! $chev !!}</label>
            <label class="xw-sel @if($diff === null) is-disabled @endif" for="f-status"><span>Since last check</span>
                <select id="f-status" name="status" @disabled($diff === null) {!! $submitOnChange !!}>
                    <option value="">All</option>
                    <option value="new" @selected(($filters['status'] ?? '') === 'new')>New</option>
                    <option value="unchanged" @selected(($filters['status'] ?? '') === 'unchanged')>Unchanged</option>
                </select>{!! $chev !!}</label>
            @if(! empty($filters['page']))<input type="hidden" name="page_id" value="{{ $filters['page'] }}">@endif
            <button type="submit" class="xw-sr" data-role="apply-filters">Apply filters</button>
            @if($filters !== [])<a class="xw-lk" href="{{ $auditUrl }}" data-role="clear-filters">Clear</a>@endif
        </form>

        <div class="xw-count" data-role="audit-meta">
            <b data-role="filter-count">Showing {{ count($groups) }} of {{ $totalGroups }} issue(s)</b>
            <span class="xw-mut">&middot; Audit of {{ $report->finished_at?->toDayDateTimeString() }} &middot; {{ $report->pages_audited }} {{ $report->pages_audited === 1 ? 'page' : 'pages' }} audited. MotionGrove cannot edit your website, so each issue shows what to change and where. Confirmed observations are stated as facts; interpretations are labelled.</span>
        </div>

        @if($diff !== null && $diff['resolved'] > 0)
            <section class="xw-card xw-card-pad xw-stack" data-role="resolved-since">
                <h2 class="xw-h">Resolved since the previous check ({{ $diff['resolved'] }})</h2>
                <ul style="margin:0;padding-left:18px;font-size:14px">
                    @foreach($diff['resolved_by_rule'] as $rule => $n)
                        <li>{{ \App\Library\ExternalSite\Audit\ExternalAuditCatalog::rule($rule)['title'] }} <span class="xw-mut">&middot; {{ $n }} {{ $n === 1 ? 'place' : 'places' }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @forelse($groups as $group)
            @php
                [$kindText, $kindTone] = P::kindTag($group['kind']);
                $countText = ($group['site_level'] && $group['page_count'] <= 1)
                    ? 'Whole site'
                    : $group['page_count'].' '.($group['page_count'] === 1 ? 'page' : 'pages').($group['row_count'] !== $group['page_count'] ? ', '.$group['row_count'].' places' : '');
                $isOpen = $openRule === $group['rule_key'] || ($openRule === null && ($loop->first || $group['priority'] === 'critical'));
            @endphp
            <section class="xw-card xw-card-flat" id="rule-{{ $group['rule_key'] }}" data-role="issue" data-rule="{{ $group['rule_key'] }}" data-severity="{{ $group['priority'] }}" data-kind="{{ $group['kind'] }}" data-category="{{ $group['category'] }}">
                <details class="xw-det" @if($isOpen) open @endif>
                    <summary>
                        <span class="xw-tg {{ P::toneClass(P::priorityVariant($group['priority'])) }}" data-role="severity">{{ $group['priority_label'] }}</span>
                        <span class="xw-tg {{ $kindTone }}" data-role="kind" title="{{ $group['kind_label'] }}">{{ $kindText }}</span>
                        <span class="xw-det-title">
                            <b data-role="issue-title">{{ $group['title'] }}</b>
                            <span class="xw-det-sub xw-det-hide-open">{{ $group['what'] }}</span>
                        </span>
                        @if(collect($group['rows'])->contains('status', 'new'))<span class="xw-tg xw-t-new" data-role="new-badge">New</span>@endif
                        <span class="xw-mut" data-role="issue-count">{{ $countText }} &middot; {{ $group['category_label'] }}</span>
                        <svg class="xi xw-chev" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>
                    </summary>

                    <div class="xw-det-body">
                        <p class="xw-p-body" data-role="what"><strong>What the problem is.</strong> {{ $group['what'] }}</p>
                        <p class="xw-p-body" data-role="why"><strong>Why it matters.</strong> {{ $group['why'] }}</p>

                        <div class="xw-stack" style="gap:8px">
                            <span class="xw-eyebrow">Affected URLs and what was observed</span>
                            @if($group['clusters'] !== [])
                                @foreach($group['clusters'] as $cluster)
                                    <div class="xw-cluster" data-role="cluster">
                                        @if($cluster['label'] !== '')<span class="xw-cluster-label" data-role="cluster-label">{{ $cluster['label'] }}</span>@endif
                                        @include('customer.business.website.external._evidence-rows', ['rows' => $cluster['rows'], 'detailRoute' => $detailRoute])
                                    </div>
                                @endforeach
                            @else
                                @include('customer.business.website.external._evidence-rows', ['rows' => $group['rows'], 'detailRoute' => $detailRoute])
                            @endif
                        </div>

                        <div class="xw-twocol">
                            <div class="xw-howto">
                                <p class="xw-p-body" style="font-size:13.5px"><strong>Proposed change.</strong> {{ $group['proposal'] }}</p>
                                <b style="display:block;margin-top:10px">How to fix it</b>
                                <ol data-role="how-to">
                                    @foreach($group['steps'] as $step)<li>{{ $step }}</li>@endforeach
                                </ol>
                            </div>
                            <div class="xw-notes">
                                <span data-role="verify"><svg class="xi" viewBox="0 0 24 24"><path d="M20 11a8 8 0 0 0-14.500-4.500L4 8M4 4v4h4M4 13a8 8 0 0 0 14.500 4.500L20 16M20 20v-4h-4"/></svg><span><strong>How MotionGrove will verify the fix.</strong> {{ $group['verify'] }}</span></span>
                                @if($group['confidence'] === 'heuristic')
                                    <span data-role="confidence"><svg class="xi" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.500"/><path d="M12 11v5M12 8h.01"/></svg><span>This is an interpretation of what was observed, not a confirmed fault.</span></span>
                                @endif
                            </div>
                        </div>

                        <div class="xw-actions">
                            <a class="xw-btn xw-btn-primary" href="{{ $planUrl }}#plan-{{ $group['rule_key'] }}" data-role="to-plan">Track in the improvement plan</a>
                            <button type="button" class="xw-btn xw-btn-line" data-role="copy-recommendation"
                                    data-copy="{{ $group['title'].' — '.$group['proposal']."\n\n".implode("\n", array_map(fn ($s, $i) => ($i + 1).'. '.$s, $group['steps'], array_keys($group['steps'])))."\n\nAffected: ".implode(', ', array_slice(array_filter(array_column($group['rows'], 'page_url')), 0, 15)) }}"
                                    onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy); this.textContent='Copied';"><svg class="xi" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>Copy recommendation</button>
                        </div>
                    </div>
                </details>
            </section>
        @empty
            <section class="xw-card xw-card-pad" data-role="audit-clean"><p class="xw-p" style="margin:0">@if($filters !== []) No issues match these filters. @else We did not find any issues on the pages we audited. @endif</p></section>
        @endforelse

        <section class="xw-card xw-card-flat" data-role="not-measured">
            <details class="xw-skip">
                <summary><h2 class="xw-h" style="flex:1">Not measured by this audit</h2><span class="xw-mut">{{ collect($notMeasured)->pluck('label')->implode(', ') }}</span><svg class="xi xw-chev" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg></summary>
                <div class="xw-skip-group">
                    @foreach($notMeasured as $item)
                        <div class="xw-nm"><div class="xw-nm-body"><b style="font-size:13.5px">{{ $item['label'] }}</b><small>{{ $item['detail'] }}</small></div><span class="xw-nm-end">{{ $item['status'] }}</span></div>
                    @endforeach
                </div>
            </details>
        </section>
    @endif

    @if($openRule)
        @verbatim
        <script>document.addEventListener('DOMContentLoaded', function () { var h = window.location.hash; if (h) { var n = document.querySelector(h + ' details'); if (n) { n.open = true; n.scrollIntoView(); } } });</script>
        @endverbatim
    @endif
@endsection
