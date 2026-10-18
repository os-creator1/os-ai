{{--
    SEO Content Engine V1 — one blog article inside the Website's own layout. Exactly one H1 (the article's
    title). The body is safe Markdown rendered by ArticleMarkdown (raw HTML stripped, no images, no unsafe
    links; internal links are stable references resolved for THIS surface). The only image is the featured
    image, through the Website's responsive <img>.
--}}
@php($tone = ($design ?? null) ? $design->toneFor('text') : 'light')
@php($article = $blog['article'])
<div class="wd-band wd-tone-{{ $tone }} blog-band blog-band-article">
    <div class="website-container wd-container blog-container blog-container-article">
        <article class="blog-article">
            <header class="blog-article-header">
                <p class="blog-meta">
                    @if (! empty($article['intent']))
                        <span class="blog-chip">{{ $article['intent'] }}</span>
                    @endif
                    @if (! empty($article['published_at']))
                        <time datetime="{{ $article['published_at']->toDateString() }}">Published {{ $article['published_at']->format('F j, Y') }}</time>
                    @endif
                    @if (! empty($article['modified_at']) && ! empty($article['published_at']) && $article['modified_at']->greaterThan($article['published_at']->copy()->addDay()))
                        <time datetime="{{ $article['modified_at']->toDateString() }}">Updated {{ $article['modified_at']->format('F j, Y') }}</time>
                    @endif
                </p>
                <h1 class="blog-article-title">{{ $article['title'] }}</h1>
                @if (! empty($article['excerpt']))
                    <p class="blog-article-lede">{{ $article['excerpt'] }}</p>
                @endif
                <p class="blog-byline">By {{ $article['author'] }}</p>
            </header>

            @if (! empty($article['image']))
                <figure class="blog-article-figure">
                    {{ \App\Library\Website\Media\ResponsiveImage::tag($article['image'], '(max-width: 900px) 100vw, 860px', ['eager' => true, 'priority' => true, 'class' => 'blog-article-image']) }}
                </figure>
            @endif

            <div class="blog-prose">{!! $article['html'] !!}</div>
        </article>

        @if (! empty($blog['related']))
            <section class="blog-related" aria-labelledby="blog-related-title">
                <h2 id="blog-related-title" class="blog-related-title">Keep reading</h2>
                <div class="blog-grid blog-grid-related">
                    @foreach ($blog['related'] as $card)
                        <article class="blog-card">
                            <div class="blog-card-body">
                                <p class="blog-meta">
                                    @if (! empty($card['published_at']))
                                        <time datetime="{{ $card['published_at']->toDateString() }}">{{ $card['published_at']->format('M j, Y') }}</time>
                                    @endif
                                </p>
                                <h3 class="blog-card-title"><a href="{{ $card['url'] }}">{{ $card['title'] }}</a></h3>
                                @if (! empty($card['excerpt']))
                                    <p class="blog-card-excerpt">{{ $card['excerpt'] }}</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>

@include('public.website.blog.cta', ['supports' => $blog['supports'], 'packagesUrl' => $blog['packages_url']])
