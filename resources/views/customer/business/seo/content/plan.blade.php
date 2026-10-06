{{--
    SEO → Content → Content Plan. Each of your key pages with the articles that support it, the pages with
    nothing supporting them yet, and articles worth a second look. Wording is deliberately modest: "may help",
    never a promise about rankings. Escaped Blade output only.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Content plan')

@section('content')
    <div class="row mb-1">
        <div class="col-12">
            <h4 class="mb-25">Content</h4>
            <p class="text-caption mb-0">How your articles support the pages that bring in customers.</p>
        </div>
    </div>

    @include('customer.business.seo.content._nav', ['active' => 'plan', 'withModule' => true])

    <x-flash-alert class="mb-2" />

    @php($route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$workspaceUid, $businessUid], $extra)))

    @if(count($needsReview) + count($rankSignals) > 0)
        <x-card :padded="true" class="mb-2" data-role="needs-review">
            <h5 class="mb-50">Worth a second look</h5>
            <ul class="list-unstyled mb-0">
                @foreach($needsReview as $row)
                    <li class="mb-50" data-article="{{ $row['article_uid'] }}">
                        <a class="fw-bold" href="{{ $route('articles.edit', [$row['article_uid']]) }}">{{ $row['title'] }}</a>
                        <span class="text-caption">— {{ implode(' ', $row['messages']) }}</span>
                    </li>
                @endforeach
                @foreach($rankSignals as $signal)
                    <li class="mb-50" data-article="{{ $signal['article_uid'] }}" data-signal="{{ $signal['kind'] }}">
                        <a class="fw-bold" href="{{ $route('articles.edit', [$signal['article_uid']]) }}">{{ $signal['title'] }}</a>
                        <span class="text-caption">— {{ $signal['message'] }}</span>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    @forelse($plan['clusters'] as $cluster)
        <x-card :padded="true" class="mb-1" data-role="cluster" data-page="{{ $cluster['page']['uid'] }}">
            <div class="d-flex justify-content-between flex-wrap gap-1 mb-50">
                <div>
                    <h5 class="mb-0">{{ $cluster['page']['title'] }}</h5>
                    <span class="text-caption">Your page</span>
                </div>
                @can('manage_seo')
                    <a class="btn btn-sm btn-outline-primary align-self-start" href="{{ $route('articles.create') }}?page={{ $cluster['page']['uid'] }}">Write a supporting article</a>
                @endcan
            </div>

            @if(count($cluster['articles']) > 0)
                <ul class="list-unstyled mb-1">
                    @foreach($cluster['articles'] as $article)
                        <li class="d-flex gap-50 align-items-center mb-25">
                            @include('customer.business.seo.content._status_badge', ['state' => $article['status']])
                            <a href="{{ $route('articles.edit', [$article['uid']]) }}">{{ $article['title'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if(count($cluster['suggested']) > 0)
                <div class="text-caption mb-25">Topics that would support this page</div>
                <ul class="mb-0">
                    @foreach($cluster['suggested'] as $key)
                        @if($opportunities->has($key))
                            <li>{{ $opportunities[$key]['title'] }}</li>
                        @endif
                    @endforeach
                </ul>
            @endif
        </x-card>
    @empty
        <x-card :padded="true" data-role="plan-empty">
            <h5 class="mb-50">Start with topics your customers already search for</h5>
            <p class="text-caption mb-1">Your plan fills in as you write. Begin with a topic that supports one of your service pages.</p>
            <a class="btn btn-primary" href="{{ $route('opportunities') }}">See recommended topics</a>
        </x-card>
    @endforelse

    @if(count($plan['unsupported_pages']) > 0)
        <x-card :padded="true" class="mt-1" data-role="unsupported-pages">
            <h5 class="mb-50">Pages with no supporting article yet</h5>
            <p class="text-caption mb-50">An article can answer the questions a customer has before they are ready to book on one of these pages.</p>
            <ul class="mb-0">
                @foreach($plan['unsupported_pages'] as $page)
                    <li>{{ $page['title'] }}</li>
                @endforeach
            </ul>
        </x-card>
    @endif
@endsection
