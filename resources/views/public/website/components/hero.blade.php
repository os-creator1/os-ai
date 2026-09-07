{{-- Website Component Library — hero (contract §7.2). Plain escaped text only. --}}
<section class="website-section website-hero"
    @if (! empty($data['background_image']) && isset($assetsByUid[$data['background_image']]))
        style="background-image: url('{{ $assetsByUid[$data['background_image']]['url'] }}');"
    @endif
>
    <div class="website-hero-inner">
        <h1>{{ $data['heading'] ?? '' }}</h1>
        @if (! empty($data['subheading']))
            <p class="website-hero-subheading">{{ $data['subheading'] }}</p>
        @endif
        <div class="website-hero-ctas">
            @if (! empty($data['primary_cta']['url']))
                <a class="website-btn website-btn-primary" href="{{ $data['primary_cta']['url'] }}">{{ $data['primary_cta']['label'] ?? '' }}</a>
            @endif
            @if (! empty($data['secondary_cta']['url']))
                <a class="website-btn website-btn-secondary" href="{{ $data['secondary_cta']['url'] }}">{{ $data['secondary_cta']['label'] ?? '' }}</a>
            @endif
        </div>
    </div>
</section>
