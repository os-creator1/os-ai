{{--
    SEO Content Engine V1 — the blog index, rendered inside the Website's own layout (header, footer, brand,
    typography and band tones belong to the template). Only published articles ever reach this view.
    Every field is Blade-escaped output; images go through the Website's one responsive <img>.
--}}
@php($tone = ($design ?? null) ? $design->toneFor('text') : 'light')
<div class="wd-band wd-tone-{{ $tone }} blog-band">
    <div class="website-container wd-container blog-container">
        <header class="blog-header">
            <h1 class="blog-title">{{ $blog['heading'] }}</h1>
            <p class="blog-intro">{{ $blog['intro'] }}</p>
        </header>

        <div class="blog-grid">
            @foreach ($blog['cards'] as $card)
                <article class="blog-card">
                    @if (! empty($card['image']))
                        <a class="blog-card-media" href="{{ $card['url'] }}" tabindex="-1" aria-hidden="true">
                            {{ \App\Library\Website\Media\ResponsiveImage::tag($card['image'], '(max-width: 700px) 100vw, (max-width: 1100px) 50vw, 380px', ['alt' => '']) }}
                        </a>
                    @endif
                    <div class="blog-card-body">
                        <p class="blog-meta">
                            @if (! empty($card['published_at']))
                                <time datetime="{{ $card['published_at']->toDateString() }}">{{ $card['published_at']->format('M j, Y') }}</time>
                            @endif
                            @if (! empty($card['intent']))
                                <span class="blog-chip">{{ $card['intent'] }}</span>
                            @endif
                        </p>
                        <h2 class="blog-card-title"><a href="{{ $card['url'] }}">{{ $card['title'] }}</a></h2>
                        @if (! empty($card['excerpt']))
                            <p class="blog-card-excerpt">{{ $card['excerpt'] }}</p>
                        @endif
                        <a class="blog-readmore" href="{{ $card['url'] }}">Read article <span class="blog-sr">about {{ $card['title'] }}</span></a>
                    </div>
                </article>
            @endforeach
        </div>

        @if ($blog['last_page'] > 1)
            <nav class="blog-pagination" aria-label="Blog pages">
                @if ($blog['prev_url'])
                    <a class="blog-page-link" rel="prev" href="{{ $blog['prev_url'] }}">&larr; Newer</a>
                @endif
                <span class="blog-page-status">Page {{ $blog['page'] }} of {{ $blog['last_page'] }}</span>
                @if ($blog['next_url'])
                    <a class="blog-page-link" rel="next" href="{{ $blog['next_url'] }}">Older &rarr;</a>
                @endif
            </nav>
        @endif
    </div>
</div>

@include('public.website.blog.cta', ['supports' => null, 'packagesUrl' => $pageUrls['packages'] ?? null])
