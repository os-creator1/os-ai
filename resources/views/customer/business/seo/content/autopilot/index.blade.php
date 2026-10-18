{{--
    SEO -> Content -> Autopilot. The owner's home for Content: one switch, what is next, this month, the one thing needed (only
    when it is), what waits for approval, and what went live. No SEO topic list: Autopilot decides what is worth writing, and the
    manual topic ideas stay one quiet link away. Every value is the canonical AutopilotOverview; nothing is invented for the
    layout. Escaped Blade output only.

    Expects: $workspaceUid, $businessUid, $business, $autopilot (AutopilotOverview::forBusiness()).
--}}
@extends(request()->query('fragment') === '1' ? 'customer.business.seo.content._fragment' : 'customer.business.seo.content._frame')

@section('title', 'Content Autopilot')
@section('content-active', 'autopilot')
@section('content-subtitle', 'Let MotionGrove decide when a useful article is worth writing, write it from your real business facts, and keep it up to date.')

@section('content-section')
    @php($route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$workspaceUid, $businessUid], $extra)))
    @php($ap = $autopilot)
    @php($filled = min($ap['month']['max'], $ap['month']['published'] + $ap['month']['planned']))

    <x-card :padded="true" class="mb-2" data-role="autopilot-switch" data-state="{{ $ap['enabled'] ? ($ap['paused'] ? 'paused' : 'on') : 'off' }}">
        <div class="autopilot-hero">
            <div class="autopilot-hero-main">
                <span class="content-icon-tile content-icon-tile--neutral" aria-hidden="true"><x-ds-icon name="sparkles" size="16" /></span>
                <div>
                    <h5 class="mb-25">Content Autopilot <span class="content-chip {{ $ap['running'] ? 'is-success' : '' }} ms-50" data-role="autopilot-badge">{{ $ap['running'] ? 'On' : ($ap['enabled'] ? 'Paused' : 'Off') }}</span></h5>
                    <p class="mb-0 text-caption" data-role="autopilot-status">
                        @if($ap['enabled'])
                            {{ $ap['statusText'] }}
                        @else
                            Nothing is being written right now. Turn it on and it will decide, day by day, whether something useful is worth writing.
                        @endif
                    </p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-50">
                @if(! $ap['enabled'])
                    <form method="POST" action="{{ $route('autopilot.enable') }}">@csrf
                        <button type="submit" class="btn btn-primary" data-role="turn-on"><x-ds-icon name="power" size="15" aria-hidden="true" /> Turn on Autopilot</button>
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

        <div class="autopilot-steps" data-role="autopilot-steps">
            <div class="autopilot-step"><span class="autopilot-step-num">1</span><span><strong>Decides</strong>Checks whether a useful article is worth writing.</span></div>
            <div class="autopilot-step"><span class="autopilot-step-num">2</span><span><strong>Writes</strong>Uses your real business facts.</span></div>
            <div class="autopilot-step"><span class="autopilot-step-num">3</span><span><strong>Keeps it current</strong>Updates articles so they stay accurate.</span></div>
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

    <div class="autopilot-grid">
        <x-card :padded="true" class="h-100" data-role="next-up" data-kind="{{ $ap['next']['kind'] }}">
            <h5 class="mb-1">Next up</h5>
            @if($ap['next']['title'])
                <p class="fw-bold mb-25">
                    @if($ap['next']['article_uid'])
                        <a href="{{ $route('articles.edit', [$ap['next']['article_uid']]) }}">{{ $ap['next']['title'] }}</a>
                    @else
                        {{ $ap['next']['title'] }}
                    @endif
                </p>
                <p class="mb-0 text-caption">
                    {{ $ap['next']['detail'] }}
                    @if($ap['next']['at'])
                        {{ $ap['next']['at']->timezone($business->timezone ?: config('app.timezone'))->format('l, F j \a\t g:i A') }}.
                    @endif
                </p>
            @else
                <div class="autopilot-empty" data-role="next-up-empty">
                    <x-ds-icon name="file-text" size="18" class="text-muted" aria-hidden="true" />
                    <strong class="text-body">No articles planned</strong>
                    <span class="text-caption">{{ $ap['enabled'] ? $ap['next']['detail'] : 'Planned articles will show here once Autopilot is on.' }}</span>
                </div>
            @endif
        </x-card>

        <x-card :padded="true" class="h-100" data-role="this-month">
            <h5 class="mb-0">This month</h5>
            <div class="autopilot-month" data-role="month-counts">
                <div><span class="num">{{ $ap['month']['published'] }}</span><span class="content-label">Published</span></div>
                <div><span class="num">{{ $ap['month']['planned'] }}</span><span class="content-label">Planned</span></div>
            </div>
            @if($ap['month']['max'] > 0)
                <div class="autopilot-meter" aria-hidden="true">
                    @for($i = 1; $i <= $ap['month']['max']; $i++)<i class="{{ $i <= $filled ? 'is-filled' : '' }}"></i>@endfor
                </div>
            @endif
            <p class="mb-0 text-caption">Up to {{ $ap['month']['max'] }} a month - and fewer is perfectly fine. Autopilot writes only when there is something worth saying.</p>
        </x-card>
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

    <div class="autopilot-quick" data-role="secondary-links">
        <a href="{{ $route('autopilot.profile') }}" data-role="quick-profile"><span class="content-icon-tile" aria-hidden="true"><x-ds-icon name="user" size="15" /></span>{{ $ap['profileCompleted'] ? 'Edit what Autopilot knows about you' : 'Tell Autopilot a little about you' }}<x-ds-icon name="chevron-right" size="16" class="chev" aria-hidden="true" /></a>
        <a href="{{ $route('opportunities') }}" data-content-local data-role="quick-topics"><span class="content-icon-tile" aria-hidden="true"><x-ds-icon name="lightbulb" size="15" /></span>Browse topic ideas<x-ds-icon name="chevron-right" size="16" class="chev" aria-hidden="true" /></a>
        <a href="{{ $route('plan') }}" data-content-local data-role="quick-plan"><span class="content-icon-tile" aria-hidden="true"><x-ds-icon name="calendar" size="15" /></span>Content plan<x-ds-icon name="chevron-right" size="16" class="chev" aria-hidden="true" /></a>
    </div>
@endsection
