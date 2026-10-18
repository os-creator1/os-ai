{{--
    Website Component Library — hero (contract §7.2). Plain escaped text
    only. A template-driven site (WebsiteDesign) renders the template's own
    hero (design/hero.blade.php); a legacy non-template site keeps the
    original hero below: when a background image is set, a fixed dark scrim is
    layered behind it so the heading and subheading stay legible regardless
    of the image's own content.
--}}
@if ($design ?? null)
    @include('public.website.design.hero')
@else
@php($hasBackgroundImage = ! empty($data['background_image']) && isset($assetsByUid[$data['background_image']]))
<section class="website-section website-hero @if($hasBackgroundImage) website-hero-has-image @endif"
    @if ($hasBackgroundImage)
        style="background-image: linear-gradient(rgba(15, 23, 42, .45), rgba(15, 23, 42, .45)), url('{{ $assetsByUid[$data['background_image']]['url'] }}');"
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
@endif
