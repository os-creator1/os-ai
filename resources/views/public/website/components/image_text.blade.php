{{-- Website Component Library — image_text (contract §7.2). Plain escaped text only. --}}
<section class="website-section website-image-text website-image-position-{{ $data['image_position'] ?? 'left' }}">
    @if (! empty($data['image']) && isset($assetsByUid[$data['image']]))
        <div class="website-image-text-media">
            <img src="{{ $assetsByUid[$data['image']]['url'] }}" alt="{{ $assetsByUid[$data['image']]['alt_text'] ?? '' }}">
        </div>
    @endif
    <div class="website-image-text-content">
        @if (! empty($data['heading']))
            <h2>{{ $data['heading'] }}</h2>
        @endif
        <p>{{ $data['body'] ?? '' }}</p>
    </div>
</section>
