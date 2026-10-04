{{--
    Website Component Library — custom_section (Website Builder redesign).
    The owner's one optional editorial section, composed from their own
    answer: image_left / image_right / stacked / grid. Plain escaped text only.
--}}
@php
    $layout = $data['layout'] ?? 'stacked';
    $images = collect($data['images'] ?? [])->map(fn ($uid) => $assetsByUid[$uid] ?? null)->filter()->values();
@endphp
<section class="website-section website-custom-section website-custom-{{ $layout }} @if ($design ?? null) wd-custom @endif" data-testid="custom-section">
    <div class="website-custom-copy">
        <h2 class="wd-section-title">{{ $data['heading'] ?? '' }}</h2>
        <div class="website-text-body">
            @foreach (explode("\n", $data['body'] ?? '') as $paragraph)
                @if (trim($paragraph) !== '')
                    <p>{{ $paragraph }}</p>
                @endif
            @endforeach
        </div>
    </div>
    @if ($images->isNotEmpty())
        <div class="website-custom-media">
            @foreach ($images as $image)
                <img src="{{ $image['url'] }}" alt="{{ $image['alt_text'] ?? '' }}" loading="lazy">
            @endforeach
        </div>
    @endif
</section>
