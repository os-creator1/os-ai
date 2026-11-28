{{-- Website Component Library — image_text (contract §7.2). Plain escaped text only. --}}
<section class="website-section website-image-text website-image-position-{{ $data['image_position'] ?? 'left' }}">
    @php($secondaryImage = ! empty($data['image_secondary']) && is_string($data['image_secondary']) ? ($assetsByUid[$data['image_secondary']] ?? null) : null)
    @if (! empty($data['image']) && isset($assetsByUid[$data['image']]))
        {{-- Two different authentic photos overlap as a collage; with one photo this is the ordinary single image. --}}
        <div class="website-image-text-media @if ($secondaryImage) wd-collage @endif">
            {{ \App\Library\Website\Media\ResponsiveImage::tag($assetsByUid[$data['image']], '(min-width: 860px) 560px, 100vw') }}
            @if ($secondaryImage)
                {{ \App\Library\Website\Media\ResponsiveImage::tag($secondaryImage, '(min-width: 860px) 560px, 100vw', ['class' => 'wd-collage-second']) }}
            @endif
        </div>
    @endif
    <div class="website-image-text-content">
        @if (! empty($data['heading']))
            <h2 class="wd-section-title">{{ ($design ?? null) && ($design->variants['accent_titles'] ?? false) ? $design->accentHeading((string) $data['heading']) : $data['heading'] }}</h2>
        @endif
        <p>{{ $data['body'] ?? '' }}</p>
        @if (! empty($data['accent_tag']) && is_string($data['accent_tag']))
            <p class="wd-collage-tag">{{ $data['accent_tag'] }}</p>
        @endif
    </div>
</section>
