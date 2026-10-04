{{--
    Website V1 final — the template-owned hero. Four layouts (fullbleed /
    centered / split / split-soft). It is never a placeholder: the image is the
    section's own, else the owner's hero image, else the first real photo
    already on the page; with no photo at all the design shows its own
    polished typographic hero (a composed gradient field, no empty frame).
    The primary button is the section's own CTA when it truly points
    somewhere, else the site's resolved CTA — never a dead link.
--}}
@php
    $heroVariant = $design->variant('hero');
    $imageUid = $data['background_image'] ?? null;
    $image = $imageUid && isset($assetsByUid[$imageUid]) ? $assetsByUid[$imageUid] : null;

    if ($image === null && ! empty($heroImageFallback)) {
        $image = $heroImageFallback;
    }

    $primary = app(\App\Library\Website\Design\WebsiteCtaResolver::class)->sectionCta($data['primary_cta'] ?? null, $siteCta);
    $secondary = ! empty($data['secondary_cta']['url']) ? $data['secondary_cta'] : null;
    $isSplit = in_array($heroVariant, ['split', 'split-soft'], true) && $image !== null;
@endphp
<section class="wd-hero wd-hero-{{ $heroVariant }} @if ($image) wd-hero-photo @else wd-hero-typographic @endif @if (! $isHomePage) wd-hero-inner-page @endif @if ($isSplit) wd-hero-is-split @endif"
    data-testid="site-hero">
    @if ($image && $heroVariant === 'fullbleed')
        {{-- A decorative full-bleed photo: eager, high priority, sized by the viewport. --}}
        {{ \App\Library\Website\Media\ResponsiveImage::tag($image, '100vw', ['eager' => true, 'priority' => true, 'class' => 'wd-hero-bg', 'alt' => '']) }}
    @endif
    <div class="website-container wd-hero-grid">
        <div class="wd-hero-copy">
            <h1 class="wd-hero-title">{{ $design->accentHeading($data['heading'] ?? '') }}</h1>
            @if (! empty($data['subheading']))
                <p class="wd-hero-sub">{{ $data['subheading'] }}</p>
            @endif
            @if ($primary || $secondary)
                <div class="wd-hero-ctas">
                    @if ($primary)
                        <a class="wd-btn wd-btn-primary wd-btn-lg" href="{{ $primary['url'] }}">{{ $primary['label'] }}</a>
                    @endif
                    @if ($secondary)
                        <a class="wd-btn wd-btn-secondary wd-btn-lg" href="{{ $secondary['url'] }}">{{ $secondary['label'] ?? '' }}</a>
                    @endif
                </div>
            @endif
        </div>
        @if ($isSplit)
            <div class="wd-hero-media">
                {{ \App\Library\Website\Media\ResponsiveImage::tag($image, '(min-width: 900px) 480px, 100vw', ['eager' => true, 'priority' => true]) }}
            </div>
        @elseif ($image && $heroVariant === 'centered')
            <div class="wd-hero-media wd-hero-media-wide">
                {{ \App\Library\Website\Media\ResponsiveImage::tag($image, '(min-width: 1180px) 1116px, 100vw', ['eager' => true, 'priority' => true]) }}
            </div>
        @endif
    </div>
</section>
