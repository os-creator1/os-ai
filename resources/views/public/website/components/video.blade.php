{{-- Website Component Library — video. Plain escaped text only; the sources are the site's own stored MP4 assets. --}}
<section class="website-section website-video">
    @if (! empty($data['heading']))
        <h2 class="wd-section-title">{{ $data['heading'] }}</h2>
    @endif
    <div class="website-video-grid" style="display:grid;gap:1.5rem;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr))">
        @foreach (($data['items'] ?? []) as $item)
            @if (! empty($item['video']) && isset($assetsByUid[$item['video']]))
                @php($poster = ! empty($item['poster']) ? ($assetsByUid[$item['poster']]['url'] ?? null) : null)
                <figure class="website-video-item" style="margin:0">
                    <video controls preload="metadata" playsinline style="width:100%;height:auto;border-radius:8px;background:#000"
                        @if ($poster) poster="{{ $poster }}" @endif
                        aria-label="{{ $item['title'] ?? $assetsByUid[$item['video']]['alt_text'] ?? 'Video' }}">
                        <source src="{{ $assetsByUid[$item['video']]['url'] }}" type="video/mp4">
                    </video>
                    @if (! empty($item['title']) || ! empty($item['caption']))
                        <figcaption>
                            @if (! empty($item['title']))<strong>{{ $item['title'] }}</strong>@endif
                            @if (! empty($item['caption']))<span>{{ $item['caption'] }}</span>@endif
                        </figcaption>
                    @endif
                </figure>
            @endif
        @endforeach
    </div>
</section>
