{{--
    SEO → Content → Articles. The Business's own articles with their status. A Business with none yet sees
    useful next steps (recommended topics when it has the SeoModule package), never "No records found".
    Escaped Blade output only.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Articles')

@section('content')
    <div class="row mb-1">
        <div class="col-12 d-flex justify-content-between align-items-start flex-wrap gap-1">
            <div>
                <h4 class="mb-25">Content</h4>
                <p class="text-caption mb-0">Articles that answer what customers search for, published on your website.</p>
            </div>
            @can('manage_seo')
                <a class="btn btn-primary" href="{{ route('customer.workspaces.businesses.seo.content.articles.create', [$workspaceUid, $businessUid]) }}" data-role="new-article">Write an article</a>
            @endcan
        </div>
    </div>

    @include('customer.business.seo.content._nav', ['active' => 'articles', 'withModule' => $withOpportunities])

    <x-flash-alert class="mb-2" />

    @unless($withOpportunities)
        <x-alert variant="neutral" class="mb-2" data-role="growth-note">Topic suggestions, AI-assisted drafts and the content plan are part of the Growth plan. You can write and publish your own articles here today.</x-alert>
    @endunless

    @if($total === 0)
        <x-card :padded="true" data-role="articles-empty">
            <h5 class="mb-50">Start with topics your customers already search for</h5>
            <p class="text-caption">Good articles answer one real question well: what something costs, how to choose, what to plan for. Each one supports a page on your website and links to it.</p>

            @if(count($recommended) > 0)
                <div class="row g-1 mt-1" data-role="recommended-opportunities">
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
                <a class="d-inline-block mt-1" href="{{ route('customer.workspaces.businesses.seo.content.opportunities', [$workspaceUid, $businessUid]) }}">See all recommended topics</a>
            @else
                @can('manage_seo')
                    <a class="btn btn-primary mt-1" href="{{ route('customer.workspaces.businesses.seo.content.articles.create', [$workspaceUid, $businessUid]) }}">Write your first article</a>
                @endcan
            @endif
        </x-card>
    @else
        <form method="GET" class="row g-1 mb-1" data-role="article-filters">
            <div class="col-12 col-md-4">
                <label class="visually-hidden" for="filter-q">Search articles</label>
                <input id="filter-q" class="form-control" type="search" name="q" value="{{ $search }}" placeholder="Search by title or topic">
            </div>
            <div class="col-8 col-md-3">
                <label class="visually-hidden" for="filter-status">Status</label>
                <select id="filter-status" class="form-select" name="status">
                    <option value="">All statuses ({{ $total }})</option>
                    @foreach(\App\Enums\Seo\ArticleStatus::cases() as $s)
                        <option value="{{ $s->value }}" @selected($status?->value === $s->value)>{{ $s->label() }} ({{ $counts[$s->value] ?? 0 }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-4 col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
        </form>

        <x-card :padded="false">
            <div class="table-responsive">
                <table class="table mb-0 align-middle" data-role="articles-table">
                    <thead>
                        <tr><th>Article</th><th>Status</th><th class="d-none d-md-table-cell">Supports</th><th class="d-none d-md-table-cell">Updated</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse($articles as $article)
                            <tr data-article="{{ $article->uid }}">
                                <td>
                                    <a class="fw-bold" href="{{ route('customer.workspaces.businesses.seo.content.articles.edit', [$workspaceUid, $businessUid, $article->uid]) }}">{{ $article->title }}</a>
                                    @if($article->ai_generated)<span class="badge bg-light-primary ms-50" title="Started with an AI draft">AI draft</span>@endif
                                    <div class="text-caption">/blog/{{ $article->slug }}</div>
                                </td>
                                <td>
                                    @include('customer.business.seo.content._status_badge', ['state' => $article->status->value])
                                    @if($article->status === \App\Enums\Seo\ArticleStatus::Scheduled && $article->scheduled_at)
                                        <div class="text-caption">{{ $article->scheduled_at->copy()->setTimezone($business->timezone ?: 'UTC')->format('M j, g:i A') }}</div>
                                    @endif
                                    @if($article->noindex)<div class="text-caption">Hidden from search</div>@endif
                                </td>
                                <td class="d-none d-md-table-cell">{{ optional($pages->get($article->supports_page_uid))['title'] ?? '—' }}</td>
                                <td class="d-none d-md-table-cell">{{ $article->updated_at?->diffForHumans() }}</td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('customer.workspaces.businesses.seo.content.articles.edit', [$workspaceUid, $businessUid, $article->uid]) }}">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-caption py-2">No articles match this filter. <a href="{{ route('customer.workspaces.businesses.seo.content.articles.index', [$workspaceUid, $businessUid]) }}">Show all</a></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-1">{{ $articles->links() }}</div>
    @endif
@endsection
