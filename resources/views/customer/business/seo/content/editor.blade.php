{{--
    SEO → Content → article editor. One form; every action button posts the same fields to its own route, so
    "Publish" and "Schedule" save what is on screen first. The body is safe Markdown with a small toolbar, an
    internal-link picker (stable references, never raw URLs) and a live, sanitized preview. The analysis is a
    plain Good / Needs attention checklist — there is no numeric score. Escaped Blade output only, except the
    preview pane, which is filled from the server's sanitized Markdown renderer.

    Expects: $workspaceUid, $businessUid, $business, $article (?), $pages, $assets, $featuredUid, $suggestions,
    $analysis (?), $intents, $timezone, $scheduledLocal, $hasContentModule.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', $article ? 'Edit article' : 'New article')

@php
    $route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$workspaceUid, $businessUid], $extra));
    $v = fn (string $key, $default = '') => old($key, $article?->{$key} ?? $default);
    $status = $article?->status?->value ?? 'new';
    $published = $status === 'published';
    $formAction = $article ? $route('articles.update', [$article->uid]) : $route('articles.store');
    $supportsDefault = old('supports_page_uid', $article?->supports_page_uid ?? request('page'));
    $featured = old('featured_asset_uid', $featuredUid);
    $strong = $analysis['overlap']['strong'] ?? [];
@endphp

@section('content')
    <style>
        .article-editor .md-toolbar { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .5rem; }
        .article-editor textarea[name="body"] { min-height: 22rem; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .95rem; line-height: 1.55; }
        .article-editor .md-preview { border: 1px solid var(--bs-border-color, #e5e5e5); border-radius: .5rem; padding: 1rem; min-height: 8rem; overflow-wrap: anywhere; }
        .article-editor .md-preview h2 { font-size: 1.35rem; margin: 1.1rem 0 .5rem; }
        .article-editor .md-preview h3 { font-size: 1.15rem; margin: 1rem 0 .4rem; }
        .article-editor .asset-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: .5rem; }
        .article-editor .asset-choice { position: relative; display: block; cursor: pointer; }
        .article-editor .asset-choice input { position: absolute; opacity: 0; }
        .article-editor .asset-choice img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: .4rem; border: 2px solid transparent; }
        .article-editor .asset-choice input:checked + img { border-color: var(--bs-primary, #7367f0); }
        .article-editor .asset-choice input:focus-visible + img { outline: 3px solid var(--bs-primary, #7367f0); outline-offset: 2px; }
        .article-editor .check-row { display: flex; gap: .5rem; align-items: flex-start; margin-bottom: .5rem; }
        .article-editor .check-dot { flex: 0 0 auto; margin-top: .3rem; width: .7rem; height: .7rem; border-radius: 50%; }
        .article-editor .check-dot.good { background: #28c76f; }
        .article-editor .check-dot.attention { background: #ff9f43; }
        .article-editor .link-chip { text-align: left; white-space: normal; }
        .article-editor .counter { font-size: .8rem; }
    </style>

    <div class="row mb-1">
        <div class="col-12 d-flex justify-content-between align-items-start flex-wrap gap-1">
            <div>
                <h4 class="mb-25">{{ $article ? 'Edit article' : 'New article' }}</h4>
                <p class="text-caption mb-0">
                    @if($article) @include('customer.business.seo.content._status_badge', ['state' => $status]) @else A draft is private until you publish it. @endif
                </p>
            </div>
            <a class="btn btn-outline-secondary" href="{{ $route('articles.index') }}">Back to articles</a>
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    @if($errors->any())
        <x-alert variant="danger" class="mb-2" data-role="editor-errors">{{ $errors->first() }}</x-alert>
    @endif

    @if($published)
        <x-alert variant="neutral" class="mb-2" data-role="published-notice">
            This article is live. Saving here only saves a <strong>draft of your changes</strong>; your live article stays exactly as it is until you press <strong>Publish update</strong>.
            @if($hasPendingDraft) <strong>You have unpublished draft changes.</strong> @endif
        </x-alert>
    @endif

    @if($article?->ai_generated && $status === 'draft')
        <x-alert variant="warning" class="mb-2" data-role="ai-notice">This draft was written by AI from your services, packages and prices. Read all of it, correct anything that is not true for your business, then publish when you are happy. It is not published automatically.</x-alert>
    @endif

    <div class="row g-2 article-editor">
        <div class="col-12 col-xl-8">
            <form id="article-form" method="POST" action="{{ $formAction }}" data-role="article-form">
                @csrf

                <x-card :padded="true" class="mb-1">
                    <div class="mb-1">
                        <label class="form-label" for="f-title">Title</label>
                        <input id="f-title" class="form-control" type="text" name="title" value="{{ $v('title') }}" maxlength="180" required data-counter="#c-title">
                        <div class="counter text-muted"><span id="c-title">0</span> characters · about 60 reads best in search results</div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="f-slug">Web address</label>
                        <div class="input-group">
                            <span class="input-group-text">/blog/</span>
                            <input id="f-slug" class="form-control" type="text" name="slug" value="{{ $v('slug') }}" maxlength="80" placeholder="made from the title">
                        </div>
                        <div class="form-text">Lowercase letters, numbers and hyphens. @if($article?->hasBeenPublished())If you change a published article's address, the old address keeps redirecting to the new one.@endif</div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="f-excerpt">Excerpt</label>
                        <textarea id="f-excerpt" class="form-control" name="excerpt" rows="2" maxlength="400" data-counter="#c-excerpt">{{ $v('excerpt') }}</textarea>
                        <div class="counter text-muted"><span id="c-excerpt">0</span>/400 · shown on the blog and under the title</div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="f-body">Article</label>
                        <div class="md-toolbar" role="toolbar" aria-label="Formatting" data-role="md-toolbar">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-md="h2">Heading</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-md="h3">Subheading</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-md="bold"><strong>Bold</strong></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-md="ul">List</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-md="link">Web link</button>
                        </div>
                        <textarea id="f-body" class="form-control" name="body" data-role="article-body">{{ $v('body') }}</textarea>
                        <div class="counter text-muted"><span id="c-words">0</span> words · the title is the page heading, so start sections with a Heading</div>
                    </div>
                </x-card>

                <x-card :padded="true" class="mb-1">
                    <h5 class="mb-1">Search appearance</h5>
                    <div class="mb-1">
                        <label class="form-label" for="f-seo-title">SEO title</label>
                        <input id="f-seo-title" class="form-control" type="text" name="seo_title" value="{{ $v('seo_title') }}" maxlength="180" placeholder="Defaults to the title">
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="f-meta">Meta description</label>
                        <textarea id="f-meta" class="form-control" name="meta_description" rows="2" maxlength="320" data-counter="#c-meta">{{ $v('meta_description') }}</textarea>
                        <div class="counter text-muted"><span id="c-meta">0</span> characters · 70–160 reads best</div>
                    </div>
                    <div class="row g-1">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="f-topic">Target topic</label>
                            <input id="f-topic" class="form-control" type="text" name="primary_topic" value="{{ $v('primary_topic') }}" maxlength="190" placeholder="e.g. how much space does a photo booth need">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="f-intent">Search intent</label>
                            <select id="f-intent" class="form-select" name="search_intent">
                                <option value="">Not set</option>
                                @foreach($intents as $intent)
                                    <option value="{{ $intent->value }}" @selected($v('search_intent') === $intent->value)>{{ $intent->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="f-supports">Page this article supports</label>
                            <select id="f-supports" class="form-select" name="supports_page_uid">
                                <option value="">None</option>
                                @foreach($pages as $page)
                                    @if($page['linkable'] && ! in_array($page['kind'], ['other'], true))
                                        <option value="{{ $page['uid'] }}" @selected($supportsDefault === $page['uid'])>{{ $page['title'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="f-author">Author name</label>
                            <input id="f-author" class="form-control" type="text" name="author_name" value="{{ $v('author_name') }}" maxlength="120" placeholder="{{ $business->name }}">
                            <div class="form-text">Shown as the byline. Leave blank to use your business name.</div>
                        </div>
                    </div>
                    <div class="form-check mt-1">
                        <input type="hidden" name="noindex" value="0">
                        <input id="f-noindex" class="form-check-input" type="checkbox" name="noindex" value="1" @checked((bool) $v('noindex', false))>
                        <label class="form-check-label" for="f-noindex">Hide this article from search engines</label>
                        <div class="form-text">It stays on your website for visitors who have the link. It also stays out of your sitemap.</div>
                    </div>
                </x-card>

                <x-card :padded="true" class="mb-1">
                    <h5 class="mb-50">Featured image</h5>
                    <p class="text-caption">Choose one of the images already in your website's library. Photos you own only.</p>
                    @if(count($assets) === 0)
                        <p class="mb-0" data-role="no-assets">Your website's image library is empty. Add photos from your website's media screen first.</p>
                    @else
                        <div class="asset-grid" role="radiogroup" aria-label="Featured image" data-role="asset-grid">
                            <label class="asset-choice"><input type="radio" name="featured_asset_uid" value="" @checked(! $featured)><span class="d-flex align-items-center justify-content-center border rounded text-caption" style="aspect-ratio:1">None</span></label>
                            @foreach($assets as $asset)
                                <label class="asset-choice" title="{{ $asset['alt'] !== '' ? $asset['alt'] : 'No alt text yet' }}">
                                    <input type="radio" name="featured_asset_uid" value="{{ $asset['uid'] }}" @checked($featured === $asset['uid'])>
                                    <img src="{{ $asset['thumb'] }}" alt="{{ $asset['alt'] }}" loading="lazy" width="92" height="92">
                                </label>
                            @endforeach
                        </div>
                    @endif
                </x-card>

                <x-card :padded="true" class="mb-1">
                    <h5 class="mb-50">Preview of the text</h5>
                    <div class="md-preview" data-role="md-preview" aria-live="polite"></div>
                </x-card>

                <div class="d-flex flex-wrap gap-50" data-role="editor-actions">
                    <button class="btn btn-primary" type="submit" data-role="save-draft">{{ $published ? 'Save draft changes' : 'Save draft' }}</button>
                    @if($article)
                        <a class="btn btn-outline-primary" href="{{ $route('articles.preview', [$article->uid]) }}" target="_blank" rel="noopener" data-role="preview">Preview</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="col-12 col-xl-4">
            @if($article)
                <x-card :padded="true" class="mb-1" data-role="publish-panel">
                    <h5 class="mb-50">Publish</h5>

                    @if(count($strong) > 0)
                        <x-alert variant="warning" class="mb-1" data-role="overlap-warning">
                            <strong>Strong overlap</strong>
                            <ul class="mb-50 ps-1">@foreach($strong as $finding)<li>{{ $finding['reason'] }}</li>@endforeach</ul>
                            <div class="form-check">
                                <input id="f-ack" class="form-check-input" type="checkbox" name="acknowledge_overlap" value="1" form="article-form">
                                <label class="form-check-label" for="f-ack">I understand, publish anyway</label>
                            </div>
                        </x-alert>
                    @endif

                    @if(count($analysis['blockers'] ?? []) > 0)
                        <x-alert variant="neutral" class="mb-1" data-role="blockers">
                            <strong>Before you can publish</strong>
                            <ul class="mb-0 ps-1">@foreach($analysis['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
                        </x-alert>
                    @endif

                    @can('manage_seo')
                        @if(in_array($status, ['draft', 'scheduled'], true))
                            <button class="btn btn-success w-100 mb-1" type="submit" form="article-form" formaction="{{ $route('articles.publish', [$article->uid]) }}" data-role="publish">Publish now</button>

                            <label class="form-label" for="f-schedule">Or schedule it ({{ $timezone }})</label>
                            <input id="f-schedule" class="form-control mb-50" type="datetime-local" name="scheduled_at_local" form="article-form" value="{{ $scheduledLocal }}">
                            <button class="btn btn-outline-primary w-100 mb-1" type="submit" form="article-form" formaction="{{ $route('articles.schedule', [$article->uid]) }}" data-role="schedule">{{ $status === 'scheduled' ? 'Reschedule' : 'Schedule' }}</button>

                            @if($status === 'scheduled')
                                <form method="POST" action="{{ $route('articles.draft', [$article->uid]) }}" class="mb-1">@csrf
                                    <button class="btn btn-outline-secondary w-100" type="submit" data-role="unschedule">Cancel schedule (back to draft)</button>
                                </form>
                            @endif
                        @endif

                        @if($published)
                            <p class="text-caption mb-1">Published {{ $article->published_at?->copy()->setTimezone($timezone)->format('M j, Y g:i A') }}. The live article changes only when you publish an update.</p>
                            <button class="btn btn-success w-100 mb-1" type="submit" form="article-form" formaction="{{ $route('articles.publish', [$article->uid]) }}" data-role="publish-update">Publish update</button>
                            @if($hasPendingDraft)
                                <form method="POST" action="{{ $route('articles.discard', [$article->uid]) }}" class="mb-1">@csrf
                                    <button class="btn btn-outline-secondary w-100" type="submit" data-role="discard-draft">Discard draft changes</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ $route('articles.reviewed', [$article->uid]) }}" class="mb-1">@csrf
                                <button class="btn btn-outline-secondary w-100" type="submit" data-role="mark-reviewed">Mark as reviewed</button>
                            </form>
                            <form method="POST" action="{{ $route('articles.track', [$article->uid]) }}" class="mb-1">@csrf
                                <button class="btn btn-outline-primary w-100" type="submit" data-role="track-topic">Track this topic</button>
                                <div class="form-text">Adds the topic to your keywords. Rank tracking stays off until you turn it on.</div>
                            </form>
                        @endif

                        @if($status === 'archived')
                            <form method="POST" action="{{ $route('articles.restore', [$article->uid]) }}">@csrf
                                <button class="btn btn-outline-primary w-100" type="submit" data-role="restore">Restore as draft</button>
                            </form>
                        @else
                            <form method="POST" action="{{ $route('articles.archive', [$article->uid]) }}" onsubmit="return confirm('Archive this article? It leaves your website, the blog and the sitemap. Its address stays reserved.');">@csrf
                                <button class="btn btn-outline-danger w-100" type="submit" data-role="archive">Archive</button>
                            </form>
                        @endif
                    @endcan
                </x-card>

                <x-card :padded="true" class="mb-1" data-role="analysis">
                    <h5 class="mb-50">Checklist</h5>
                    <p class="text-caption mb-1" data-role="analysis-overall">{{ ($analysis['overall'] ?? 'attention') === 'good' ? 'Everything looks good.' : 'A few things need attention.' }}</p>
                    @foreach($analysis['checks'] as $check)
                        <div class="check-row" data-check="{{ $check['key'] }}" data-status="{{ $check['status'] }}">
                            <span class="check-dot {{ $check['status'] }}" aria-hidden="true"></span>
                            <div>
                                <strong>{{ $check['label'] }}</strong> <span class="text-caption">· {{ $check['status'] === 'good' ? 'Good' : 'Needs attention' }}</span>
                                <div class="text-caption">{{ $check['detail'] }}</div>
                            </div>
                        </div>
                    @endforeach
                    <p class="text-caption mb-0">Save, then re-open this page to refresh the checklist.</p>
                </x-card>
            @else
                <x-card :padded="true" class="mb-1">
                    <h5 class="mb-50">Next</h5>
                    <p class="text-caption mb-0">Save the draft to preview it, run the checklist, schedule it or publish it.</p>
                </x-card>
            @endif

            <x-card :padded="true" data-role="link-suggestions">
                <h5 class="mb-50">Internal links</h5>
                <p class="text-caption">Links to your own pages help readers and search engines. Click one to add it where your cursor is.</p>
                @forelse($suggestions as $s)
                    <button type="button" class="btn btn-sm btn-outline-secondary link-chip mb-50 d-block w-100" data-insert="[{{ str_replace(['[', ']'], '', $s['anchor']) }}]({{ $s['type'] }}:{{ $s['uid'] }})">
                        {{ $s['title'] }} <span class="text-caption">· {{ $s['type'] === 'article' ? 'article' : 'page' }}</span>
                    </button>
                @empty
                    <p class="text-caption mb-0">Choose the page this article supports and suggestions appear here.</p>
                @endforelse
            </x-card>
        </div>
    </div>

    <script>
        (function () {
            var body = document.getElementById('f-body');
            var preview = document.querySelector('[data-role="md-preview"]');
            var words = document.getElementById('c-words');
            var token = document.querySelector('#article-form input[name="_token"]').value;
            var previewUrl = @json($route('markdown-preview'));
            var timer = null;

            function count(el) { var t = document.querySelector(el.getAttribute('data-counter')); if (t) { t.textContent = el.value.length; } }
            document.querySelectorAll('[data-counter]').forEach(function (el) { count(el); el.addEventListener('input', function () { count(el); }); });

            function wrap(prefix, suffix, placeholder) {
                var s = body.selectionStart, e = body.selectionEnd, sel = body.value.slice(s, e) || placeholder;
                body.setRangeText(prefix + sel + suffix, s, e, 'end');
                body.focus();
                refresh();
            }
            function lineStart(prefix) {
                var s = body.selectionStart, start = body.value.lastIndexOf('\n', s - 1) + 1;
                body.setRangeText(prefix, start, start, 'end');
                body.focus();
                refresh();
            }
            function insert(text) {
                var s = body.selectionStart;
                body.setRangeText(text, s, body.selectionEnd, 'end');
                body.focus();
                refresh();
            }

            document.querySelectorAll('[data-md]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    switch (btn.getAttribute('data-md')) {
                        case 'h2': lineStart('## '); break;
                        case 'h3': lineStart('### '); break;
                        case 'bold': wrap('**', '**', 'bold text'); break;
                        case 'ul': lineStart('- '); break;
                        case 'link': wrap('[', '](https://)', 'link text'); break;
                    }
                });
            });
            document.querySelectorAll('[data-insert]').forEach(function (btn) {
                btn.addEventListener('click', function () { insert(btn.getAttribute('data-insert')); });
            });

            function refresh() {
                var text = body.value.trim();
                words.textContent = text === '' ? 0 : text.split(/\s+/).length;
                clearTimeout(timer);
                timer = setTimeout(function () {
                    var data = new FormData();
                    data.append('body', body.value);
                    fetch(previewUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body: data, credentials: 'same-origin' })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (j) { if (j && typeof j.html === 'string') { preview.innerHTML = j.html; } });
                }, 350);
            }
            body.addEventListener('input', refresh);
            refresh();
        })();
    </script>
@endsection
