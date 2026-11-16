@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;

        $sortUrl = fn (string $key): string => $pagesUrl.'?'.http_build_query(array_merge($filters, ['sort' => $key]));
        $groupedOthers = $otherUrls->groupBy('kind');
        $kindOrder = ['error', 'blocked', 'unreachable', 'redirect', 'robots_disallowed', 'excluded', 'non_html', 'skipped'];
    @endphp

    @if($report === null)
        <section class="xw-card xw-card-pad" data-role="pages-empty"><p class="xw-p" style="margin:0">
            @if($earlier !== null) Your last check used an earlier version of the audit and did not keep a page-by-page record. Check again to see your pages.
            @else Your pages appear here once your website has been checked. @endif
        </p></section>
    @else
        @php
            $chev = '<svg class="xi" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>';
            $onChange = 'onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"';
            $statusTone = function ($code): string {
                $code = (int) $code;

                return match (true) {
                    $code >= 200 && $code < 300 => 'xw-t-ok',
                    $code >= 300 && $code < 400 => 'xw-t-am',
                    $code >= 400 => 'xw-t-bad',
                    default => 'xw-t-low',
                };
            };
        @endphp

        <form method="GET" action="{{ $pagesUrl }}" class="xw-filters" data-role="pages-filters">
            <label class="xw-search" for="p-q"><svg class="xi" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.500"/><path d="M16 16l4.500 4.500"/></svg><input type="search" id="p-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search pages" aria-label="Search by address or title"></label>
            <label class="xw-sel" for="p-pr"><span>Issues</span>
                <select id="p-pr" name="priority" aria-label="Worst issue" {!! $onChange !!}><option value="">Any</option>@foreach($priorities as $k => $label)<option value="{{ $k }}" @selected(($filters['priority'] ?? '') === $k)>{{ $label }}</option>@endforeach</select>{!! $chev !!}</label>
            <label class="xw-sel" for="p-im"><span>Type</span>
                <select id="p-im" name="importance" aria-label="Page type" {!! $onChange !!}><option value="">Any</option>@foreach(['home' => 'Home page', 'key' => 'Key pages', 'standard' => 'Content pages', 'utility' => 'Housekeeping'] as $k => $label)<option value="{{ $k }}" @selected(($filters['importance'] ?? '') === $k)>{{ $label }}</option>@endforeach</select>{!! $chev !!}</label>
            <label class="xw-sel" for="p-ix"><span>Indexing</span>
                <select id="p-ix" name="indexing" {!! $onChange !!}><option value="">Any</option><option value="indexable" @selected(($filters['indexing'] ?? '') === 'indexable')>No obvious block</option><option value="noindex" @selected(($filters['indexing'] ?? '') === 'noindex')>Marked noindex</option></select>{!! $chev !!}</label>
            <label class="xw-sel" for="p-so"><span>Sort</span>
                <select id="p-so" name="sort" aria-label="Sort by" {!! $onChange !!}>@foreach(['severity' => 'Most issues', 'issues' => 'Issue count', 'title' => 'Page title', 'url' => 'Address', 'status' => 'HTTP status'] as $k => $label)<option value="{{ $k }}" @selected($sort === $k)>{{ $label }}</option>@endforeach</select>{!! $chev !!}</label>
            <button type="submit" class="xw-sr" data-role="apply-filters">Apply</button>
            @if($filters !== [])<a class="xw-lk" href="{{ $pagesUrl }}">Clear</a>@endif
        </form>

        <section class="xw-card xw-card-flat">
            <div class="xw-ch">
                <h2 class="xw-h">{{ $pages->count() }} {{ $pages->count() === 1 ? 'page' : 'pages' }} @if($filters !== []) shown @else checked @endif</h2>
                <span class="xw-mut" data-role="pages-meta">The {{ $report->pages_audited }} web {{ $report->pages_audited === 1 ? 'page' : 'pages' }} MotionGrove audited on {{ $report->finished_at?->toFormattedDateString() }}. Files, redirects, blocked and unchecked addresses are listed separately below, not mixed in here.</span>
            </div>
            <div data-role="pages-table">
                <div class="xw-tbl-head">
                    <span><a href="{{ $sortUrl('url') }}">Page</a> &middot; <a href="{{ $sortUrl('title') }}">Title</a></span>
                    <span>Type</span>
                    <span><a href="{{ $sortUrl('status') }}">HTTP</a></span>
                    <span>Indexing</span>
                    <span><a href="{{ $sortUrl('severity') }}">Issues</a></span>
                    <span></span>
                </div>
                @forelse($pages as $page)
                    @php
                        $detailUrl = route('customer.workspaces.businesses.website.external.page', [$workspaceUid, $businessUid, $page->id]);
                        $ixLabel = P::pageIndexability($page->indexable ?? ! $page->noindex, ($page->evidence['canonical_status'] ?? null), (string) $page->kind);
                        $ixClass = $ixLabel === 'No obvious indexing block' ? '' : ($ixLabel === 'Not assessed' ? 'is-muted' : 'is-bad');
                    @endphp
                    <div class="xw-tbl-row" data-role="page-row" data-importance="{{ $page->importance }}">
                        <div class="xw-tbl-page">
                            <a class="xw-code" href="{{ $detailUrl }}">{{ $page->displayPath() }}</a>
                            <span class="xw-mut xw-ellip">{{ \Illuminate\Support\Str::limit((string) $page->title, 70) ?: '—' }}</span>
                            <span class="xw-tbl-fine">
                                <span data-cell="canonical">Canonical: {{ P::canonicalLabel($page->evidence['canonical_status'] ?? null) }}</span>
                                <span>Last successful crawl: {{ $page->fetched_at?->diffForHumans() ?? '—' }}</span>
                                <a href="{{ $detailUrl }}" data-role="page-details">Details</a>
                                <a href="{{ $page->url }}" target="_blank" rel="noopener noreferrer" data-role="open-page">Open page</a>
                            </span>
                        </div>
                        <span><span class="xw-tg {{ P::importanceTone((string) $page->importance) }}" title="{{ P::importanceLabel((string) $page->importance) }}">{{ ['home' => 'Home', 'key' => 'Key page', 'utility' => 'Housekeeping'][$page->importance] ?? 'Content' }}</span></span>
                        <span data-cell="status"><span class="xw-tg {{ $statusTone($page->http_status) }}">{{ $page->http_status ?: '—' }}</span>@if(! empty($page->redirect_chain)) <span class="xw-mut">via redirect</span>@endif</span>
                        <span class="xw-ix {{ $ixClass }}" data-cell="visibility">@if($ixClass === '')<svg class="xi" viewBox="0 0 24 24"><path d="M5 12.500l4.500 4.500L19 7.500"/></svg>@endif{{ $ixLabel }}</span>
                        <span data-cell="issues">
                            @if($page->issue_total === 0)
                                <span class="xw-mut">None</span>
                            @else
                                @foreach(['critical', 'high', 'medium', 'low', 'info'] as $p)
                                    @if(($page->counts[$p] ?? 0) > 0)<span class="xw-tg {{ P::toneClass($p === 'low' ? 'warning' : P::priorityVariant($p)) }}" title="{{ $priorities[$p] }}">{{ $page->counts[$p] }} {{ strtolower($priorities[$p]) }}</span> @endif
                                @endforeach
                            @endif
                        </span>
                        <svg class="xi" viewBox="0 0 24 24" style="color:var(--x-chev)" aria-hidden="true"><path d="M10 7l5 5-5 5"/></svg>
                    </div>
                @empty
                    <div class="xw-rw" data-role="pages-none"><span class="xw-mut">No audited pages match these filters.</span></div>
                @endforelse
            </div>
        </section>

        @if($otherUrls->isNotEmpty())
            <section class="xw-card xw-card-flat" data-role="other-urls">
                <details class="xw-skip" open>
                    <summary><h2 class="xw-h" style="flex:1">Addresses found but not audited ({{ $otherUrls->count() }})</h2><span class="xw-mut">Log-in pages, files and duplicate addresses are skipped on purpose</span><svg class="xi xw-chev" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg></summary>
                    @foreach($kindOrder as $kind)
                        @if($groupedOthers->has($kind))
                            <details class="xw-skip-group" data-kind="{{ $kind }}" @if(in_array($kind, ['error', 'blocked', 'skipped'], true)) open @endif>
                                <summary style="list-style:none;cursor:pointer;font-size:13px;font-weight:700;padding:10px 0 4px">{{ P::kindLabel($kind) }} ({{ $groupedOthers[$kind]->count() }})</summary>
                                @foreach($groupedOthers[$kind]->take(40) as $other)
                                    <div class="xw-skip-row">
                                        <span class="xw-code" data-role="other-url">{{ $other->displayPath() }}</span>
                                        <span class="xw-mut">
                                            @if($other->http_status)HTTP {{ $other->http_status }} &middot; @endif
                                            @if($other->final_url && $kind === 'redirect')&rarr; {{ $other->final_url }} &middot; @endif
                                            {{ P::reasonLabel($kind, ($other->evidence['reason'] ?? null), $other->http_status) }}
                                        </span>
                                    </div>
                                @endforeach
                                @if($groupedOthers[$kind]->count() > 40)<div class="xw-skip-row"><span class="xw-mut">…and {{ $groupedOthers[$kind]->count() - 40 }} more.</span></div>@endif
                            </details>
                        @endif
                    @endforeach
                </details>
            </section>
        @endif
    @endif
@endsection
