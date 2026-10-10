{{--
    SEO -> Content -> Articles. The Business's own articles with their canonical status, the tracked keyword each supports
    (only when that relation really exists) and when it was last updated. A Business with none yet sees useful next steps
    (recommended topics when it has the SeoModule package), never "No records found". Filtering and search are the controller's
    own query (?status= and ?q=); the filter pills and the search box are real links / a GET form that the Content router swaps
    in place. Escaped Blade output only.
--}}
@extends(request()->query('fragment') === '1' ? 'customer.business.seo.content._fragment' : 'customer.business.seo.content._frame')

@section('title', 'Articles')
@section('content-active', 'articles')
@section('content-subtitle', 'Articles that answer what customers search for, published on your website.')

@section('content-action')
    @can('manage_seo')
        <a class="btn btn-primary" href="{{ route('customer.workspaces.businesses.seo.content.articles.create', [$workspaceUid, $businessUid]) }}" data-role="new-article"><x-ds-icon name="pencil" size="15" aria-hidden="true" /> Write an article</a>
    @endcan
@endsection

@section('content-section')
    @php
        $indexUrl = route('customer.workspaces.businesses.seo.content.articles.index', [$workspaceUid, $businessUid]);
        $editUrl = fn ($article) => route('customer.workspaces.businesses.seo.content.articles.edit', [$workspaceUid, $businessUid, $article->uid]);
        $filterUrl = fn (?string $value) => $indexUrl . '?' . http_build_query(array_filter(['status' => $value, 'q' => $search !== '' ? $search : null]));
        // All N, Published N, Drafts N always; Scheduled / Archived only when the Business has some (they are real states, not decoration).
        $pills = array_filter([
            [null, 'All', $total],
            ['published', 'Published', $counts['published'] ?? 0],
            ['draft', 'Drafts', $counts['draft'] ?? 0],
            ($counts['scheduled'] ?? 0) > 0 ? ['scheduled', 'Scheduled', $counts['scheduled']] : null,
            ($counts['archived'] ?? 0) > 0 ? ['archived', 'Archived', $counts['archived']] : null,
        ]);
    @endphp

    @unless($withOpportunities)
        <x-alert variant="neutral" class="mb-2" data-role="growth-note">Topic suggestions, AI-assisted drafts and the content plan are part of the Growth plan. You can write and publish your own articles here today.</x-alert>
    @endunless

    @if($total === 0)
        <x-card :padded="true" data-role="articles-empty">
            <div class="autopilot-empty">
                <x-ds-icon name="file-text" size="18" class="text-muted" aria-hidden="true" />
                <strong class="text-body">No articles yet.</strong>
                <span class="text-caption">Create an article yourself{{ $withOpportunities ? ' or let Content Autopilot plan useful content' : '' }}.</span>
                @can('manage_seo')
                    <a class="btn btn-primary mt-1" href="{{ route('customer.workspaces.businesses.seo.content.articles.create', [$workspaceUid, $businessUid]) }}">Write an article</a>
                @endcan
            </div>

            @if(count($recommended) > 0)
                <h6 class="mt-2 mb-1">Topics your customers already search for</h6>
                <div class="row g-1" data-role="recommended-opportunities">
                    @foreach($recommended as $opp)
                        <div class="col-12 col-lg-4">
                            <div class="border rounded p-1 h-100 d-flex flex-column gap-50">
                                <strong>{{ $opp['title'] }}</strong>
                                <span class="text-caption">{{ $opp['why'] }}</span>
                                <div class="mt-auto">@include('customer.business.seo.content._opportunity_actions', ['opp' => $opp, 'small' => true])</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <a class="d-inline-block mt-1" href="{{ route('customer.workspaces.businesses.seo.content.opportunities', [$workspaceUid, $businessUid]) }}" data-content-local>See all recommended topics</a>
            @endif
        </x-card>
    @else
        <div class="articles-toolbar" data-role="article-filters">
            <div class="articles-filters" role="group" aria-label="Filter articles by status">
                @foreach($pills as [$value, $label, $count])
                    <a href="{{ $filterUrl($value) }}" data-content-local data-role="filter-{{ $value ?? 'all' }}" class="{{ ($status?->value) === $value ? 'is-active' : '' }}" @if(($status?->value) === $value) aria-current="true" @endif>{{ $label }} {{ $count }}</a>
                @endforeach
            </div>

            <form method="GET" action="{{ $indexUrl }}" class="articles-search" data-content-form role="search">
                @if($status)<input type="hidden" name="status" value="{{ $status->value }}">@endif
                <x-ds-icon name="search" size="15" aria-hidden="true" />
                <label class="visually-hidden" for="filter-q">Search articles</label>
                <input id="filter-q" class="form-control" type="search" name="q" value="{{ $search }}" placeholder="Search by title or topic">
            </form>
        </div>

        <div data-role="articles-table">
            @forelse($articles as $article)
                @php
                    $isPublished = $article->status === \App\Enums\Seo\ArticleStatus::Published;
                    $isDraft = $article->status === \App\Enums\Seo\ArticleStatus::Draft;
                    $keyword = $keywords[$article->uid] ?? null;
                @endphp
                <div class="article-row" data-article="{{ $article->uid }}" data-status="{{ $article->status->value }}">
                    <span class="content-icon-tile {{ $isPublished ? '' : 'content-icon-tile--neutral' }}" aria-hidden="true"><x-ds-icon name="file-text" size="16" /></span>
                    <div class="article-row-main">
                        <a class="article-row-title" href="{{ $editUrl($article) }}">{{ $article->title }}</a>
                        @if($article->ai_generated)<span class="badge bg-light-primary" title="Started with an AI draft">AI draft</span>@endif
                        <span class="article-row-slug">/blog/{{ $article->slug }}</span>
                    </div>
                    <div class="article-row-meta">
                        <div>
                            <span class="content-label">Status</span>
                            @include('customer.business.seo.content._status_badge', ['state' => $article->status->value])
                            @if($article->status === \App\Enums\Seo\ArticleStatus::Scheduled && $article->scheduled_at)
                                <div class="text-caption">{{ $article->scheduled_at->copy()->setTimezone($business->timezone ?: 'UTC')->format('M j, g:i A') }}</div>
                            @endif
                            @if($article->noindex)<div class="text-caption">Hidden from search</div>@endif
                        </div>
                        <div data-role="supports-keyword">
                            <span class="content-label">Supports keyword</span>
                            @if($keyword)
                                <a href="{{ route('customer.workspaces.businesses.seo.keywords.index', [$workspaceUid, $businessUid]) }}">{{ $keyword }}</a>
                            @else
                                <span class="text-muted">None linked</span>
                            @endif
                        </div>
                        <div>
                            <span class="content-label">Updated</span>
                            <span>{{ $article->updated_at?->diffForHumans() }}</span>
                        </div>
                    </div>
                    <div class="article-row-actions">
                        @if($isDraft)
                            <a class="btn btn-primary" href="{{ $editUrl($article) }}" data-role="continue-editing">Continue editing</a>
                        @else
                            <a class="btn btn-outline-secondary btn-sm" href="{{ $editUrl($article) }}" data-role="edit-article">Edit</a>
                            @if($isPublished && $website)
                                <a class="btn btn-outline-secondary btn-sm" href="{{ route('public.website.blog.show', [$website->public_id, $article->slug]) }}" target="_blank" rel="noopener" aria-label="Open {{ $article->title }} on your website" data-role="open-live"><x-ds-icon name="external-link" size="14" aria-hidden="true" /></a>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <x-card :padded="true"><p class="text-center text-caption mb-0 py-1">No articles match this filter. <a href="{{ $indexUrl }}" data-content-local>Show all</a></p></x-card>
            @endforelse
        </div>

        <div class="mt-1">{{ $articles->links() }}</div>
    @endif
@endsection
