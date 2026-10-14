@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;

        $ev = $detail['evidence'];
        $audited = $page->isAuditedPage();
        $chain = (array) ($page->redirect_chain ?? []);
        $detailRoute = fn (int $id): string => route('customer.workspaces.businesses.website.external.page', [$workspaceUid, $businessUid, $id]);
    @endphp

    <p class="mb-1"><a href="{{ $pagesUrl }}">&larr; All pages</a></p>

    <x-card :padded="true" class="mb-2" data-role="page-detail">
        <h2 class="h4 mb-1">{{ $page->displayPath() }}</h2>
        <p class="mb-1">
            <a href="{{ $page->url }}" target="_blank" rel="noopener noreferrer" data-role="open-page">Open page</a>
            @if($audited)&middot; <a href="{{ $auditUrl }}?page_id={{ $page->id }}" data-role="page-in-audit">Show this page in the audit</a>@endif
            &middot; {{ P::kindLabel((string) $page->kind) }}
            &middot; checked {{ $page->fetched_at?->diffForHumans() ?? 'not fetched' }}
            @if($page->fetched_at)<span class="text-muted">({{ $page->fetched_at->toDayDateTimeString() }})</span>@endif
        </p>

        <h3 class="h6 text-label mt-1">What we observed <span class="text-muted fw-normal">(raw observations, no judgement)</span></h3>
        <dl class="row mb-0" data-role="observations">
            <dt class="col-sm-4">Address requested</dt><dd class="col-sm-8" data-fact="url">{{ $page->url }}</dd>
            <dt class="col-sm-4">Final address</dt><dd class="col-sm-8" data-fact="final-url">{{ $page->final_url ?: $page->url }}</dd>
            <dt class="col-sm-4">HTTP status</dt><dd class="col-sm-8" data-fact="status">{{ $page->http_status ?: ($page->error_code ?: '—') }}</dd>
            @if($chain !== [])
                <dt class="col-sm-4">Redirects followed</dt>
                <dd class="col-sm-8" data-fact="redirects">@foreach($chain as $hop){{ $hop['url'] }} <span class="text-muted">(HTTP {{ $hop['status'] }})</span> &rarr; @endforeach {{ $page->final_url ?: $page->url }}</dd>
            @endif
            <dt class="col-sm-4">Found via</dt><dd class="col-sm-8">{{ ['start' => 'The address you saved', 'link' => 'A link on '.($page->depth ?? '?').' click(s) from the home page', 'sitemap' => 'Your sitemap'][$page->source] ?? 'Crawl' }}@if($page->in_sitemap) &middot; listed in your sitemap @endif</dd>
            <dt class="col-sm-4">Page type</dt><dd class="col-sm-8">{{ P::importanceLabel((string) $page->importance) }}</dd>
            @if($audited)
                <dt class="col-sm-4">Indexing assessment</dt><dd class="col-sm-8" data-fact="indexing">{{ P::pageIndexability($page->indexable ?? ! $page->noindex, $ev['canonical_status'] ?? null, (string) $page->kind) }} <span class="text-muted">(technical only; not a statement about Google's index)</span></dd>
                <dt class="col-sm-4">Robots directives</dt><dd class="col-sm-8">Meta robots: {{ $page->meta_robots ?: 'none' }} &middot; X-Robots-Tag: {{ $page->x_robots ?: 'none' }}</dd>
                <dt class="col-sm-4">Title</dt><dd class="col-sm-8" data-fact="title">{{ $page->title ?: 'None set' }} @if($page->title)<span class="text-muted">({{ mb_strlen($page->title) }} characters)</span>@endif</dd>
                <dt class="col-sm-4">Meta description</dt><dd class="col-sm-8" data-fact="description">{{ $page->meta_description ?: 'None set' }} @if($page->meta_description)<span class="text-muted">({{ mb_strlen($page->meta_description) }} characters)</span>@endif</dd>
                <dt class="col-sm-4">Canonical</dt><dd class="col-sm-8" data-fact="canonical">{{ $page->canonical_url ?: 'None' }} <span class="text-muted">&middot; {{ P::canonicalLabel($ev['canonical_status'] ?? null) }}</span></dd>
                <dt class="col-sm-4">Main headings (H1)</dt><dd class="col-sm-8" data-fact="h1">{{ $page->h1_count ?? '—' }} on this page @if(($ev['h1_texts'] ?? []) !== [])&mdash; {{ implode(' | ', array_map(fn ($t) => '“'.$t.'”', $ev['h1_texts'])) }}@endif</dd>
                <dt class="col-sm-4">Heading outline</dt>
                <dd class="col-sm-8" data-fact="headings">
                    @if(($ev['headings'] ?? []) === []) No headings found @else
                        <details><summary>{{ count($ev['headings']) }} heading(s)</summary>
                            <ul class="mb-0 mt-1 list-unstyled">@foreach($ev['headings'] as $h)<li style="padding-left: {{ (($h['level'] ?? 1) - 1) * 14 }}px"><span class="text-muted">H{{ $h['level'] }}</span> {{ $h['text'] }}</li>@endforeach</ul></details>
                    @endif
                </dd>
                <dt class="col-sm-4">Images</dt><dd class="col-sm-8" data-fact="images">{{ $page->image_count }} image(s)@if($page->images_missing_alt > 0), {{ $page->images_missing_alt }} with no alt attribute @endif</dd>
                <dt class="col-sm-4">Structured data</dt>
                <dd class="col-sm-8" data-fact="structured-data">
                    @if(($ev['structured_data'] ?? []) === []) None found (JSON-LD only) @else
                        @foreach($ev['structured_data'] as $block)
                            <div>{{ $block['valid'] ? implode(', ', $block['types'] ?: ['(no type)']) : 'Block that could not be parsed ('.($block['error'] ?? 'invalid').')' }}@if(! empty($block['missing'])) <span class="text-muted">&middot; missing: {{ implode(', ', $block['missing']) }}</span>@endif</div>
                        @endforeach
                    @endif
                </dd>
                <dt class="col-sm-4">Mobile viewport tag</dt><dd class="col-sm-8">{{ ($ev['has_viewport'] ?? false) ? 'Present' : 'Not found' }} <span class="text-muted">(layout on a phone was not tested)</span></dd>
                <dt class="col-sm-4">Contact signals</dt><dd class="col-sm-8">Phone link: {{ ($ev['has_tel_link'] ?? false) ? 'yes' : 'no' }} &middot; email link: {{ ($ev['has_mailto_link'] ?? false) ? 'yes' : 'no' }} &middot; forms: {{ $ev['form_count'] ?? 0 }}@if(($ev['phones'] ?? []) !== []) &middot; numbers on page: {{ implode(', ', $ev['phones']) }}@endif</dd>
                <dt class="col-sm-4">Words on the page</dt><dd class="col-sm-8">{{ number_format($page->word_count) }}</dd>
            @else
                <dt class="col-sm-4">Why it was not audited</dt><dd class="col-sm-8">{{ P::reasonLabel((string) $page->kind, $ev['reason'] ?? null, $page->http_status) }}</dd>
            @endif
        </dl>
    </x-card>

    {{-- Findings are kept apart from the observations above --}}
    <h3 class="h6 text-label mb-1">Recommendations for this page</h3>
    @forelse($detail['findings'] as $f)
        <x-card :padded="true" class="mb-2" data-role="issue" data-rule="{{ $f['rule_key'] }}" data-severity="{{ $f['priority'] }}">
            <div class="d-flex align-items-center flex-wrap gap-1 mb-1">
                <x-badge :variant="P::priorityVariant($f['priority'])">{{ P::priorityLabel($f['priority']) }}</x-badge>
                <x-badge variant="neutral">{{ $f['kind_label'] }}</x-badge>
                <h4 class="h5 mb-0">{{ $f['title'] }}</h4>
            </div>
            <p class="mb-1" data-role="observed"><strong>Observed.</strong> {{ $f['observed'] }}</p>
            @if($f['related'] !== [])
                <ul class="text-caption mb-1">@foreach($f['related'] as $rel)<li>{{ $rel['note'] ?? 'related' }}: {{ $rel['url'] }}@if(! empty($rel['status'])) <span class="text-muted">&middot; HTTP {{ $rel['status'] }}</span>@endif</li>@endforeach</ul>
            @endif
            <p class="mb-1"><strong>Why it matters.</strong> {{ $f['why'] }}</p>
            @if(! empty($f['proposal']))<p class="mb-1" data-role="row-proposal"><strong>Suggested.</strong> {{ $f['proposal'] }}</p>@endif
            <details class="mb-1" data-role="how-to"><summary class="fw-semibold" style="cursor:pointer">How to fix this</summary>
                <ol class="mt-1 mb-0">@foreach($f['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol></details>
            <p class="text-caption text-muted mb-1">{{ $f['verify'] }}</p>
            <div class="d-flex flex-wrap gap-1">
                <a class="btn btn-sm btn-outline-secondary" href="{{ $page->url }}" target="_blank" rel="noopener noreferrer">View page</a>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-role="copy-recommendation" data-copy="{{ $f['title'].' — '.$page->url."\n".$f['observed']."\n".implode("\n", array_map(fn ($s, $i) => ($i + 1).'. '.$s, $f['steps'], array_keys($f['steps']))) }}" onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy); this.textContent='Copied';">Copy recommendation</button>
            </div>
        </x-card>
    @empty
        <x-card :padded="true" data-role="page-clean"><p class="mb-0">@if($audited) We did not find any issues on this page. @else This address was not audited, so there are no page-level findings for it. @endif</p></x-card>
    @endforelse

    @if($audited)
        <x-card :padded="true" class="mb-2" data-role="links">
            <p class="text-label mb-1">Links to your own pages from this page ({{ count($detail['outgoing']) }})</p>
            @if($detail['outgoing'] === [])
                <p class="text-muted mb-0">No internal links were recorded.</p>
            @else
                <div class="table-responsive"><table class="table table-sm mb-0" data-role="outgoing-links">
                    <thead><tr><th>Link text</th><th>Destination</th><th>Result when checked</th></tr></thead>
                    <tbody>
                        @foreach($detail['outgoing'] as $l)
                            <tr>
                                <td>{{ $l['anchor'] !== '' ? $l['anchor'] : '(no link text)' }}</td>
                                <td>{{ $l['url'] }}</td>
                                <td>@if($l['kind'] === 'html') <span class="text-success">Works (HTTP {{ $l['status'] }})</span>
                                    @elseif($l['kind'] === 'error') <span class="text-danger">Broken (HTTP {{ $l['status'] }})</span>
                                    @elseif($l['kind'] === 'redirect') Redirects to {{ $l['final_url'] }}
                                    @elseif($l['kind'] === 'unchecked') <span class="text-muted">Not checked</span>
                                    @else <span class="text-muted">{{ P::kindLabel($l['kind']) }}</span> @endif</td>
                            </tr>
                        @endforeach
                    </tbody></table></div>
            @endif
        </x-card>

        <x-card :padded="true" class="mb-2" data-role="incoming-links">
            <p class="text-label mb-1">Pages that link to this page ({{ count($detail['incoming']) }})</p>
            @forelse($detail['incoming'] as $in)
                <p class="mb-50"><a href="{{ $detailRoute($in['page_id']) }}">{{ $in['path'] }}</a> @if($in['anchor'] !== '')<span class="text-muted">&middot; “{{ $in['anchor'] }}”</span>@endif</p>
            @empty
                <p class="text-muted mb-0">No checked page links here.</p>
            @endforelse
        </x-card>
    @endif

    <p class="text-caption text-muted" data-role="limitations">Read from one request on {{ $page->fetched_at?->toDayDateTimeString() ?? 'the day of the check' }} as plain HTML: JavaScript was not run, and nothing was submitted or signed in to. Your own browser may show a page that differs from what was read.</p>
@endsection
