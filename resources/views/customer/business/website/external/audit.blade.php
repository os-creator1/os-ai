@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;

        $detailRoute = fn (int $id): string => route('customer.workspaces.businesses.website.external.page', [$workspaceUid, $businessUid, $id]);
        $openRule = request()->query('rule');
    @endphp

    @if($report === null)
        <x-card :padded="true" data-role="audit-empty">
            <p class="mb-0 text-muted">
                @if($earlier !== null) Your last check used an earlier version of the audit, which did not keep the evidence behind each finding. Check again to get the full audit.
                @else The audit appears here once your website has been checked. @endif
            </p>
        </x-card>
    @else
        <p class="text-caption text-muted mb-2" data-role="audit-meta">
            Audit of {{ $report->finished_at?->toDayDateTimeString() }} &middot; {{ $report->pages_audited }} {{ $report->pages_audited === 1 ? 'page' : 'pages' }} audited.
            MotionGrove cannot edit your website, so each issue shows what to change and where. Confirmed observations are stated as facts; interpretations are labelled.
        </p>

        {{-- Filters: real GET parameters, so a filtered view can be shared and bookmarked --}}
        <form method="GET" action="{{ $auditUrl }}" class="row g-1 align-items-end mb-2" data-role="audit-filters">
            <div class="col-md-3">
                <label class="form-label" for="f-q">Search issue or page</label>
                <input type="search" class="form-control" id="f-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="e.g. title or /services">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="f-severity">Severity</label>
                <select class="form-select" id="f-severity" name="severity">
                    <option value="">All</option>
                    @foreach($priorities as $key => $label)<option value="{{ $key }}" @selected(($filters['severity'] ?? '') === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="f-category">Category</label>
                <select class="form-select" id="f-category" name="category">
                    <option value="">All</option>
                    @foreach($categories as $key => $label)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="f-kind">Type</label>
                <select class="form-select" id="f-kind" name="kind">
                    <option value="">All</option>
                    @foreach($kinds as $key => $label)<option value="{{ $key }}" @selected(($filters['kind'] ?? '') === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="f-status">Since last check</label>
                <select class="form-select" id="f-status" name="status" @disabled($diff === null)>
                    <option value="">All</option>
                    <option value="new" @selected(($filters['status'] ?? '') === 'new')>New</option>
                    <option value="unchanged" @selected(($filters['status'] ?? '') === 'unchanged')>Unchanged</option>
                </select>
            </div>
            @if(! empty($filters['page']))<input type="hidden" name="page_id" value="{{ $filters['page'] }}">@endif
            <div class="col-12 d-flex gap-1 align-items-center">
                <button type="submit" class="btn btn-sm btn-primary" data-role="apply-filters">Apply filters</button>
                @if($filters !== [])<a class="btn btn-sm btn-flat-secondary" href="{{ $auditUrl }}" data-role="clear-filters">Clear</a>@endif
                <span class="text-caption text-muted" data-role="filter-count">Showing {{ count($groups) }} of {{ $totalGroups }} issue(s)</span>
            </div>
        </form>

        @if($diff !== null && $diff['resolved'] > 0)
            <x-card :padded="true" class="mb-2" data-role="resolved-since">
                <p class="text-label mb-1">Resolved since the previous check ({{ $diff['resolved'] }})</p>
                <ul class="mb-0">
                    @foreach($diff['resolved_by_rule'] as $rule => $n)
                        <li>{{ \App\Library\ExternalSite\Audit\ExternalAuditCatalog::rule($rule)['title'] }} <span class="text-muted">&middot; {{ $n }} {{ $n === 1 ? 'place' : 'places' }}</span></li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        @forelse($groups as $group)
            <x-card :padded="true" class="mb-2" id="rule-{{ $group['rule_key'] }}" data-role="issue" data-rule="{{ $group['rule_key'] }}" data-severity="{{ $group['priority'] }}" data-kind="{{ $group['kind'] }}" data-category="{{ $group['category'] }}">
                <details @if($openRule === $group['rule_key'] || $group['priority'] === 'critical') open @endif>
                    <summary class="d-flex align-items-center flex-wrap gap-1 mb-1" style="cursor:pointer">
                        <x-badge :variant="P::priorityVariant($group['priority'])" data-role="severity">{{ $group['priority_label'] }}</x-badge>
                        <x-badge variant="neutral" data-role="kind">{{ $group['kind_label'] }}</x-badge>
                        <strong class="h5 mb-0" data-role="issue-title">{{ $group['title'] }}</strong>
                        <span class="text-caption text-muted" data-role="issue-count">
                            @if($group['site_level'] && $group['page_count'] <= 1) whole site
                            @else {{ $group['page_count'] }} {{ $group['page_count'] === 1 ? 'page' : 'pages' }}@if($group['row_count'] !== $group['page_count']), {{ $group['row_count'] }} places @endif
                            @endif
                            &middot; {{ $group['category_label'] }}
                        </span>
                        @if(collect($group['rows'])->contains('status', 'new'))<x-badge variant="accent" data-role="new-badge">New</x-badge>@endif
                    </summary>

                    <p class="mb-1" data-role="what"><strong>What the problem is.</strong> {{ $group['what'] }}</p>
                    <p class="mb-1" data-role="why"><strong>Why it matters.</strong> {{ $group['why'] }}</p>

                    <p class="text-label mb-50">Affected URLs and what was observed</p>
                    @if($group['clusters'] !== [])
                        @foreach($group['clusters'] as $cluster)
                            <div class="border rounded p-1 mb-1" data-role="cluster">
                                @if($cluster['label'] !== '')<p class="mb-50 fw-semibold" data-role="cluster-label">{{ $cluster['label'] }}</p>@endif
                                @include('customer.business.website.external._evidence-rows', ['rows' => $cluster['rows'], 'detailRoute' => $detailRoute])
                            </div>
                        @endforeach
                    @else
                        @include('customer.business.website.external._evidence-rows', ['rows' => $group['rows'], 'detailRoute' => $detailRoute])
                    @endif

                    <p class="mb-50 mt-1"><strong>Proposed change.</strong> {{ $group['proposal'] }}</p>
                    <p class="text-label mb-50">How to fix it</p>
                    <ol class="mb-1" data-role="how-to">
                        @foreach($group['steps'] as $step)<li>{{ $step }}</li>@endforeach
                    </ol>
                    <p class="mb-1" data-role="verify"><strong>How MotionGrove will verify the fix.</strong> {{ $group['verify'] }}</p>
                    @if($group['confidence'] === 'heuristic')
                        <p class="text-caption text-muted mb-1" data-role="confidence">This is an interpretation of what was observed, not a confirmed fault.</p>
                    @endif

                    <div class="d-flex flex-wrap gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-role="copy-recommendation"
                                data-copy="{{ $group['title'].' — '.$group['proposal']."\n\n".implode("\n", array_map(fn ($s, $i) => ($i + 1).'. '.$s, $group['steps'], array_keys($group['steps'])))."\n\nAffected: ".implode(', ', array_slice(array_filter(array_column($group['rows'], 'page_url')), 0, 15)) }}"
                                onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy); this.textContent='Copied';">Copy recommendation</button>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $planUrl }}#plan-{{ $group['rule_key'] }}" data-role="to-plan">Track in the improvement plan</a>
                    </div>
                </details>
            </x-card>
        @empty
            <x-card :padded="true" data-role="audit-clean"><p class="mb-0">@if($filters !== []) No issues match these filters. @else We did not find any issues on the pages we audited. @endif</p></x-card>
        @endforelse

        <x-card :padded="true" class="mb-2" data-role="not-measured">
            <p class="text-label mb-1">Not measured by this audit</p>
            <ul class="mb-0">
                @foreach($notMeasured as $item)
                    <li class="mb-50"><strong>{{ $item['label'] }}</strong> <x-badge variant="neutral">{{ $item['status'] }}</x-badge><br><span class="text-caption text-muted">{{ $item['detail'] }}</span></li>
                @endforeach
            </ul>
        </x-card>
    @endif

    @if($openRule)
        @verbatim
        <script>document.addEventListener('DOMContentLoaded', function () { var h = window.location.hash; if (h) { var n = document.querySelector(h + ' details'); if (n) { n.open = true; n.scrollIntoView(); } } });</script>
        @endverbatim
    @endif
@endsection
