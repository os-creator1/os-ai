{{--
    Website Component Library — backdrops (Website Builder redesign). Built
    server-side from the Business's own backdrop rows (never AI). Each
    backdrop shows its first photo as a card; extra photos appear as small
    thumbnails. Plain escaped text only.
--}}
<section class="website-section website-backdrops @if ($design ?? null) wd-backdrops @endif" data-testid="backdrops-section">
    @if (! empty($data['heading']))
        <h2 class="wd-section-title">{{ $data['heading'] }}</h2>
    @endif
    <div class="website-backdrops-grid @if ($design ?? null) wd-grid @endif">
        @foreach (($data['items'] ?? []) as $item)
            @php($images = $item['images'] ?? [])
            <figure class="website-backdrop-card @if ($design ?? null) wd-card @endif">
                @if (! empty($images[0]['url']))
                    {{ \App\Library\Website\Media\ResponsiveImage::tag($images[0], '(min-width: 960px) 360px, 100vw', ['alt' => $images[0]['alt_text'] ?? ($item['name'] ?? '')]) }}
                @endif
                <figcaption>
                    <h3>{{ $item['name'] ?? '' }}</h3>
                    @if (! empty($item['description']))
                        <p>{{ $item['description'] }}</p>
                    @endif
                </figcaption>
            </figure>
        @endforeach
    </div>
</section>
