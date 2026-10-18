{{--
    SEO Content Engine V1 — the ONE call-to-action at the foot of the blog index and every article. It uses the
    site's own resolved CTA ($siteCta: booking → quote form → contact page → phone/email; the same answer the
    header and hero give, so it is never a dead button) and, on an article, links on to the page the article
    supports and to Packages. Nothing here is written per article: no invented offer, price or promise.
--}}
@php($ctaTone = ($design ?? null) ? $design->toneFor('cta') : 'accent')
<div class="wd-band wd-tone-{{ $ctaTone }} blog-cta-band" data-blog-cta>
    <div class="website-container wd-container blog-cta">
        <h2 class="blog-cta-title">{{ $ctaHeading ?? 'Planning an event?' }}</h2>
        <p class="blog-cta-text">{{ $ctaText ?? 'Tell us about your event and we will help you choose the right setup.' }}</p>
        <div class="blog-cta-actions">
            @if (! empty($siteCta))
                <a class="wd-btn wd-btn-primary website-btn blog-cta-primary" href="{{ $siteCta['url'] }}">{{ $siteCta['label'] }}</a>
            @endif
            @if (! empty($supports))
                <a class="wd-btn wd-btn-secondary blog-cta-secondary" href="{{ $supports['url'] }}">{{ $supports['title'] }}</a>
            @endif
            @if (! empty($packagesUrl))
                <a class="wd-btn wd-btn-secondary blog-cta-secondary" href="{{ $packagesUrl }}">See packages</a>
            @endif
        </div>
    </div>
</div>
