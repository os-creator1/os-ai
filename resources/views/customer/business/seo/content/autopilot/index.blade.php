{{--
    SEO → Content → Autopilot. The owner's home for Content: one switch, what is next, this month, the one thing needed (only
    when it is), what waits for approval, and what went live. No SEO topic list: Autopilot decides what is worth writing, and the
    manual topic ideas stay one quiet link away. Escaped Blade output only.

    Expects: $workspaceUid, $businessUid, $business, $autopilot (AutopilotOverview::forBusiness()).
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Content Autopilot')

@section('content')
    @php($route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$workspaceUid, $businessUid], $extra)))
    @php($ap = $autopilot)

    <div class="row mb-1">
        <div class="col-12">
            <h4 class="mb-25">Content</h4>
            <p class="text-caption mb-0">Let MotionGrove decide when a useful article is worth writing, write it from your real business facts, and keep it up to date.</p>
        </div>
    </div>

    @include('customer.business.seo.content._nav', ['active' => 'autopilot', 'withModule' => true])

    <x-flash-alert class="mb-2" />

    <x-card :padded="true" class="mb-2" data-role="autopilot-switch" data-state="{{ $ap['enabled'] ? ($ap['paused'] ? 'paused' : 'on') : 'off' }}">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-1">
            <div>
                <h5 class="mb-25">Content Autopilot <span class="badge {{ $ap['running'] ? 'bg-success' : 'bg-secondary' }} ms-50" data-role="autopilot-badge">{{ $ap['running'] ? 'ON' : ($ap['enabled'] ? 'PAUSED' : 'OFF') }}</span></h5>
                <p class="mb-0 text-caption" data-role="autopilot-status">{{ $ap['statusText'] }}</p>
            </div>
            <div class="d-flex gap-50">
                @if(! $ap['enabled'])
                    <form method="POST" action="{{ $route('autopilot.enable') }}">@csrf
                        <button type="submit" class="btn btn-primary" data-role="turn-on">Turn on</button>
                    </form>
                @else
                    @if($ap['paused'] === 'owner')
                        <form method="POST" action="{{ $route('autopilot.resume') }}">@csrf
                            <button type="submit" class="btn btn-outline-primary" data-role="resume">Resume</button>
                        </form>
                    @elseif($ap['paused'] === null)
                        <form method="POST" action="{{ $route('autopilot.pause') }}">@csrf
                            <button type="submit" class="btn btn-outline-secondary" data-role="pause">Pause</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ $route('autopilot.disable') }}">@csrf
                        <button type="submit" class="btn btn-outline-secondary" data-role="turn-off">Turn off</button>
                    </form>
                @endif
            </div>
        </div>
    </x-card>

    @if($ap['question'])
        <x-card :padded="true" class="mb-2" data-role="needs-input" data-key="{{ $ap['question']['key'] }}">
            <h5 class="mb-50">Needs your input</h5>
            <p class="mb-1">{{ $ap['question']['prompt'] }}</p>
            <form method="POST" action="{{ $route('autopilot.answer') }}">@csrf
                <input type="hidden" name="key" value="{{ $ap['question']['key'] }}">
                <textarea class="form-control mb-1 @error('answer') is-invalid @enderror" name="answer" rows="3" maxlength="1000" required placeholder="A sentence or two is plenty"></textarea>
                @error('answer')<div class="invalid-feedback d-block mb-1">{{ $message }}</div>@enderror
                <button type="submit" class="btn btn-primary" data-role="answer">Send</button>
            </form>
        </x-card>
    @endif

    <div class="row">
        <div class="col-md-8 mb-2">
            <x-card :padded="true" class="h-100" data-role="next-up" data-kind="{{ $ap['next']['kind'] }}">
                <h5 class="mb-50">Next up</h5>
                @if($ap['next']['title'])
                    <p class="fw-bold mb-25">
                        @if($ap['next']['article_uid'])
                            <a href="{{ $route('articles.edit', [$ap['next']['article_uid']]) }}">{{ $ap['next']['title'] }}</a>
                        @else
                            {{ $ap['next']['title'] }}
                        @endif
                    </p>
                @endif
                <p class="mb-0 text-caption">
                    {{ $ap['next']['detail'] }}
                    @if($ap['next']['at'])
                        {{ $ap['next']['at']->timezone($business->timezone ?: config('app.timezone'))->format('l, F j \a\t g:i A') }}.
                    @endif
                </p>
            </x-card>
        </div>
        <div class="col-md-4 mb-2">
            <x-card :padded="true" class="h-100" data-role="this-month">
                <h5 class="mb-50">This month</h5>
                <p class="fw-bold mb-25" data-role="month-counts">{{ $ap['month']['published'] }} published &middot; {{ $ap['month']['planned'] }} planned</p>
                <p class="mb-0 text-caption">Up to {{ $ap['month']['max'] }} a month - and fewer is perfectly fine. Autopilot writes only when there is something worth saying.</p>
            </x-card>
        </div>
    </div>

    @if(count($ap['approval']) > 0)
        <x-card :padded="true" class="mb-2" data-role="awaiting-approval">
            <h5 class="mb-50">Awaiting your approval</h5>
            <ul class="list-unstyled mb-0">
                @foreach($ap['approval'] as $draft)
                    <li class="mb-1" data-article="{{ $draft['article_uid'] }}">
                        <a class="fw-bold" href="{{ $route('articles.edit', [$draft['article_uid']]) }}">{{ $draft['title'] }}</a>
                        <a class="btn btn-sm btn-outline-primary ms-50" href="{{ $route('articles.preview', [$draft['article_uid']]) }}">Preview</a>
                        <div class="text-caption">{{ $draft['note'] }}</div>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    @if(count($ap['updates']) > 0)
        <x-card :padded="true" class="mb-2" data-role="waiting-updates">
            <h5 class="mb-50">Updates to your live articles</h5>
            <ul class="list-unstyled mb-0">
                @foreach($ap['updates'] as $update)
                    <li class="mb-1" data-article="{{ $update['article_uid'] }}">
                        <a class="fw-bold" href="{{ $route('articles.edit', [$update['article_uid']]) }}">{{ $update['title'] }}</a>
                        <div class="text-caption">{{ $update['note'] }}</div>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    @if(count($ap['suggestions']) > 0)
        <x-card :padded="true" class="mb-2" data-role="suggestions">
            <h5 class="mb-50">Worth a look</h5>
            <ul class="list-unstyled mb-0">
                @foreach($ap['suggestions'] as $suggestion)
                    <li class="mb-1" data-kind="{{ $suggestion['kind'] }}">
                        <a class="fw-bold" href="{{ $route('articles.edit', [$suggestion['article_uid']]) }}">{{ $suggestion['title'] }}</a>
                        <div class="text-caption">{{ $suggestion['message'] }} Autopilot never merges or archives an article on its own.</div>
                        <form method="POST" action="{{ $route('autopilot.suggestion.dismiss', [$suggestion['uid']]) }}" class="d-inline">@csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary mt-50" data-role="dismiss">Dismiss</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    @if(count($ap['recent']) > 0)
        <x-card :padded="true" class="mb-2" data-role="recently-published">
            <h5 class="mb-50">Recently published</h5>
            <ul class="list-unstyled mb-0">
                @foreach($ap['recent'] as $article)
                    <li class="mb-50">
                        <a href="{{ $route('articles.edit', [$article['uid']]) }}">{{ $article['title'] }}</a>
                        @if($article['published_at'])
                            <span class="text-caption"> - {{ $article['published_at']->format('F j, Y') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <p class="text-caption" data-role="secondary-links">
        <a href="{{ $route('autopilot.profile') }}">{{ $ap['profileCompleted'] ? 'Edit what Autopilot knows about you' : 'Tell Autopilot a little about you' }}</a>
        &middot; <a href="{{ $route('opportunities') }}">Browse topic ideas</a>
        &middot; <a href="{{ $route('plan') }}">Content plan</a>
    </p>
@endsection
