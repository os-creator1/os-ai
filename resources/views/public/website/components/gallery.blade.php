{{-- Website Component Library — gallery (contract §7.2). Plain escaped text only. --}}
<section class="website-section website-gallery">
    @if (! empty($data['heading']))
        <h2 class="wd-section-title">{{ $data['heading'] }}</h2>
    @endif
    <div class="website-gallery-grid">
        @foreach (($data['items'] ?? []) as $item)
            @if (! empty($item['image']) && isset($assetsByUid[$item['image']]))
                <div class="website-gallery-item">
                    {{ \App\Library\Website\Media\ResponsiveImage::tag($assetsByUid[$item['image']], '(min-width: 960px) 33vw, 50vw') }}
                </div>
            @endif
        @endforeach
    </div>
</section>
