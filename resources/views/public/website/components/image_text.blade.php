{{-- Website Component Library — image_text (contract §7.2). Plain escaped text only. --}}
<section class="website-section website-image-text website-image-position-{{ $data['image_position'] ?? 'left' }}">
    @if (! empty($data['image']) && isset($assetsByUid[$data['image']]))
        <div class="website-image-text-media">
            {{ \App\Library\Website\Media\ResponsiveImage::tag($assetsByUid[$data['image']], '(min-width: 860px) 560px, 100vw') }}
        </div>
    @endif
    <div class="website-image-text-content">
        @if (! empty($data['heading']))
            <h2 class="wd-section-title">{{ $data['heading'] }}</h2>
        @endif
        <p>{{ $data['body'] ?? '' }}</p>
    </div>
</section>
