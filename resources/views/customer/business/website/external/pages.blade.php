@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;

        $sortUrl = fn (string $key): string => $pagesUrl.'?'.http_build_query(array_merge($filters, ['sort' => $key]));
        $groupedOthers = $otherUrls->groupBy('kind');
        $kindOrder = ['error', 'blocked', 'unreachable', 'redirect', 'robots_disallowed', 'excluded', 'non_html', 'skipped'];
    @endphp

    @if($report === null)
        <x-card :padded="true" data-role="pages-empty"><p class="mb-0 text-muted">
            @if($earlier !== null) Your last check used an earlier version of the audit and did not keep a page-by-page record. Check again to see your pages.
            @else Your pages appear here once your website has been checked. @endif
        </p></x-card>
    @else
        <p class="text-caption text-muted mb-1" data-role="pages-meta">The {{ $report->pages_audited }} web {{ $report->pages_audited === 1 ? 'page' : 'pages' }} MotionGrove audited on {{ $report->finished_at?->toFormattedDateString() }}. Files, redirects, blocked and unchecked addresses are listed separately below, not mixed in here.</p>

        <form method="GET" action="{{ $pagesUrl }}" class="row g-1 align-items-end mb-2" data-role="pages-filters">
            <div class="col-md-3"><label class="form-label" for="p-q">Search</label><input type="search" class="form-control" id="p-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="address or title"></div>
            <div class="col-6 col-md-2"><label class="form-label" for="p-pr">Worst issue</label>
                <select class="form-select" id="p-pr" name="priority"><option value="">Any</option>@foreach($priorities as $k => $label)<option value="{{ $k }}" @selected(($filters['priority'] ?? '') === $k)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label" for="p-im">Page type</label>
                <select class="form-select" id="p-im" name="importance"><option value="">Any</option>@foreach(['home' => 'Home page', 'key' => 'Key pages', 'standard' => 'Content pages', 'utility' => 'Housekeeping'] as $k => $label)<option value="{{ $k }}" @selected(($filters['importance'] ?? '') === $k)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label" for="p-ix">Indexing</label>
                <select class="form-select" id="p-ix" name="indexing"><option value="">Any</option><option value="indexable" @selected(($filters['indexing'] ?? '') === 'indexable')>No obvious block</option><option value="noindex" @selected(($filters['indexing'] ?? '') === 'noindex')>Marked noindex</option></select></div>
            <div class="col-6 col-md-2"><label class="form-label" for="p-so">Sort by</label>
                <select class="form-select" id="p-so" name="sort">@foreach(['severity' => 'Severity', 'issues' => 'Issue count', 'title' => 'Page title', 'url' => 'Address', 'status' => 'HTTP status'] as $k => $label)<option value="{{ $k }}" @selected($sort === $k)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-1"><button type="submit" class="btn btn-primary w-100" data-role="apply-filters">Apply</button></div>
        </form>

        <x-card :padded="false" class="mb-2">
            <div class="table-responsive">
                <table class="table mb-0 align-middle" data-role="pages-table">
                    <thead>
                        <tr>
                            <th><a href="{{ $sortUrl('url') }}">Page</a></th>
                            <th><a href="{{ $sortUrl('title') }}">Title</a></th>
                            <th><a href="{{ $sortUrl('status') }}">HTTP</a></th>
                            <th>Indexing</th>
                            <th>Canonical</th>
                            <th><a href="{{ $sortUrl('severity') }}">Issues</a></th>
                            <th>Last successful crawl</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pages as $page)
                            <tr data-role="page-row" data-importance="{{ $page->importance }}">
                                <td>
                                    <a href="{{ route('customer.workspaces.businesses.website.external.page', [$workspaceUid, $businessUid, $page->id]) }}">{{ $page->displayPath() }}</a>
                                    <div class="text-caption text-muted">{{ P::importanceLabel((string) $page->importance) }}</div>
                                </td>
                                <td>{{ $page->title ?: '—' }}</td>
                                <td data-cell="status">{{ $page->http_status ?: '—' }}@if(! empty($page->redirect_chain)) <span class="text-caption text-muted">via redirect</span>@endif</td>
                                <td data-cell="visibility">{{ P::pageIndexability($page->indexable ?? ! $page->noindex, ($page->evidence['canonical_status'] ?? null), (string) $page->kind) }}</td>
                                <td data-cell="canonical">{{ P::canonicalLabel($page->evidence['canonical_status'] ?? null) }}</td>
                                <td data-cell="issues">
                                    @if($page->issue_total === 0)
                                        <span class="text-muted">None</span>
                                    @else
                                        @foreach(['critical', 'high', 'medium', 'low', 'info'] as $p)
                                            @if(($page->counts[$p] ?? 0) > 0)<x-badge :variant="P::priorityVariant($p)" title="{{ $priorities[$p] }}">{{ $page->counts[$p] }} {{ strtolower($priorities[$p]) }}</x-badge> @endif
                                        @endforeach
                                    @endif
                                </td>
                                <td class="text-muted">{{ $page->fetched_at?->diffForHumans() }}</td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('customer.workspaces.businesses.website.external.page', [$workspaceUid, $businessUid, $page->id]) }}" data-role="page-details">Details</a>
                                    <a class="btn btn-sm btn-flat-secondary" href="{{ $page->url }}" target="_blank" rel="noopener noreferrer" data-role="open-page">Open page</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-muted p-2" data-role="pages-none">No audited pages match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        @if($otherUrls->isNotEmpty())
            <x-card :padded="true" class="mb-2" data-role="other-urls">
                <p class="text-label mb-1">Addresses found but not audited ({{ $otherUrls->count() }})</p>
                @foreach($kindOrder as $kind)
                    @if($groupedOthers->has($kind))
                        <details class="mb-1" data-kind="{{ $kind }}" @if(in_array($kind, ['error', 'blocked', 'skipped'], true)) open @endif>
                            <summary class="fw-semibold" style="cursor:pointer">{{ P::kindLabel($kind) }} ({{ $groupedOthers[$kind]->count() }})</summary>
                            <ul class="mb-0 mt-1">
                                @foreach($groupedOthers[$kind]->take(40) as $other)
                                    <li class="mb-50"><span data-role="other-url">{{ $other->displayPath() }}</span>@if($other->http_status) <span class="text-muted">&middot; HTTP {{ $other->http_status }}</span>@endif
                                        @if($other->final_url && $kind === 'redirect') <span class="text-muted">&rarr; {{ $other->final_url }}</span>@endif
                                        <div class="text-caption text-muted">{{ P::reasonLabel($kind, ($other->evidence['reason'] ?? null), $other->http_status) }}</div></li>
                                @endforeach
                                @if($groupedOthers[$kind]->count() > 40)<li class="text-muted">…and {{ $groupedOthers[$kind]->count() - 40 }} more.</li>@endif
                            </ul>
                        </details>
                    @endif
                @endforeach
            </x-card>
        @endif
    @endif
@endsection
