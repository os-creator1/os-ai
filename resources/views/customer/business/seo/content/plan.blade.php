{{--
    SEO → Content → Content Plan. A review banner for articles that need a second look, then one row per key page:
    the page, the topics that would support it (chips that open the matching Opportunities), the articles that already
    support it, and the next action. Rows come only from $plan (supported + unsupported pages); topics only from
    $opportunities - nothing is invented. Wording is deliberately modest: "may help", never a promise about rankings.
    Escaped Blade output only; no inline scripts (this region is swapped by the Content tab router).
--}}
@extends(request()->query('fragment') === '1' ? 'customer.business.seo.content._fragment' : 'customer.business.seo.content._frame')

@section('title', 'Content plan')
@section('content-active', 'plan')
@section('content-subtitle', 'How your articles support the pages that bring in customers.')

@section('content-section')
    @php
        $route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$workspaceUid, $businessUid], $extra));

        // One row per page: pages with articles/topics first (cluster order), then pages with neither.
        $rows = collect($plan['clusters'])->map(fn ($c) => $c + ['has_cluster' => true])->keyBy(fn ($c) => $c['page']['uid']);
        foreach ($plan['unsupported_pages'] as $page) {
            if (! $rows->has($page['uid'])) {
                $rows->put($page['uid'], ['page' => $page, 'articles' => [], 'suggested' => [], 'has_cluster' => false]);
            }
        }
        $unsupportedUids = collect($plan['unsupported_pages'])->pluck('uid')->all();
        $total = $rows->count();
        $supported = $rows->filter(fn ($r) => ! in_array($r['page']['uid'], $unsupportedUids, true))->count();
        $segments = min(max($total, 1), 12);
        $filled = $total > 0 ? (int) round($supported / $total * $segments) : 0;

        $reviewItems = collect($needsReview)->map(fn ($r) => [
            'uid' => $r['article_uid'], 'title' => $r['title'], 'text' => implode(' ', $r['messages']), 'kind' => 'freshness',
        ])->merge(collect($rankSignals)->map(fn ($s) => [
            'uid' => $s['article_uid'], 'title' => $s['title'], 'text' => $s['message'], 'kind' => $s['kind'],
        ]));

        $pageIcon = fn (string $kind) => match ($kind) {
            'home' => 'home', 'services_hub' => 'list', 'service' => 'layout-grid', 'location' => 'map-pin', 'packages' => 'package', default => 'file-text',
        };
    @endphp

    @foreach($reviewItems as $item)
        <div class="plan-review" data-role="needs-review" data-article="{{ $item['uid'] }}" @if($item['kind'] !== 'freshness') data-signal="{{ $item['kind'] }}" @endif>
            <span class="content-icon-tile content-icon-tile--warm" aria-hidden="true"><x-ds-icon name="file-text" size="16" /></span>
            <div class="plan-review-main">
                <span class="content-label plan-review-label">Needs a quick review</span>
                <div class="plan-review-title">{{ $item['title'] }}</div>
                <div class="plan-review-text">{{ $item['text'] }}</div>
            </div>
            <a class="btn btn-sm btn-outline-secondary plan-review-action" href="{{ $route('articles.edit', [$item['uid']]) }}">Review article</a>
        </div>
    @endforeach

    @if($total === 0)
        <x-card :padded="true" data-role="plan-empty">
            <h5 class="mb-50">Start with topics your customers already search for</h5>
            <p class="text-caption mb-1">Your plan fills in as you write. Begin with a topic that supports one of your service pages.</p>
            <a class="btn btn-primary" href="{{ $route('opportunities') }}" data-content-local>See recommended topics</a>
        </x-card>
    @else
        <section class="plan-pages" data-role="plan-pages" aria-labelledby="plan-pages-title">
            <header class="plan-pages-head">
                <div class="plan-pages-heading">
                    <h5 id="plan-pages-title" class="mb-25">Your pages</h5>
                    <p class="text-caption mb-0">Pick a topic to write an article that supports that page.</p>
                </div>
                <div class="plan-progress" data-role="plan-progress">
                    <span class="plan-progress-bar" aria-hidden="true">
                        @for($i = 0; $i < $segments; $i++)<i class="{{ $i < $filled ? 'is-filled' : '' }}"></i>@endfor
                    </span>
                    <span class="plan-progress-label">{{ $supported }} of {{ $total }} supported</span>
                </div>
            </header>

            @foreach($rows as $row)
                @php
                    $suggested = collect($row['suggested'])->filter(fn ($key) => $opportunities->has($key))->map(fn ($key) => $opportunities[$key])->values();
                @endphp
                <div class="plan-row" data-role="cluster" data-page="{{ $row['page']['uid'] }}">
                    <div class="plan-row-page">
                        <span class="content-icon-tile content-icon-tile--neutral" aria-hidden="true"><x-ds-icon name="{{ $pageIcon($row['page']['kind']) }}" size="15" /></span>
                        <span class="plan-row-name">{{ $row['page']['title'] }}</span>
                    </div>

                    <div class="plan-row-topics">
                        @foreach($row['articles'] as $article)
                            <a class="plan-article" href="{{ $route('articles.edit', [$article['uid']]) }}" data-role="plan-article">
                                @include('customer.business.seo.content._status_badge', ['state' => $article['status']])
                                <span>{{ $article['title'] }}</span>
                            </a>
                        @endforeach
                        @foreach($suggested as $opp)
                            <a class="plan-topic" href="{{ $route('opportunities', ['page' => $row['page']['uid']]) }}" data-content-local data-role="plan-topic" data-opportunity="{{ $opp['key'] }}"><x-ds-icon name="plus" size="12" aria-hidden="true" />{{ $opp['title'] }}</a>
                        @endforeach
                        @if(count($row['articles']) === 0 && $suggested->isEmpty())
                            <span class="plan-none text-caption">No topics suggested yet</span>
                        @endif
                    </div>

                    <div class="plan-row-cta">
                        @can('manage_seo')
                            <a class="btn btn-sm btn-outline-primary" href="{{ $route('articles.create') }}?page={{ $row['page']['uid'] }}">Write article</a>
                        @endcan
                    </div>
                </div>
            @endforeach
        </section>
    @endif
@endsection
