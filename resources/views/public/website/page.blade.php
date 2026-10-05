{{--
    Website Generation + Hosting Slice A contract §18/§38 — the public
    Website's OWN layout, never extending the SaaS M2
    layouts/contentLayoutMaster (or any dashboard layout). This file is
    shared by both the public renderer (App\Http\Controllers\Public\WebsiteController)
    and the authenticated preview action
    (App\Http\Controllers\Customer\Business\WebsiteController::preview()),
    fed an identical variable shape by both callers:
    $website, $websiteMeta (['name','theme']), $page (object with
    ->title, ->seo->{seo_title,meta_description,noindex}),
    $sections (array), $assetsByUid (array<uid, ['url','alt_text']>),
    $isPreview (bool), $navigationPages (snapshot pages for public,
    current draft pages for preview).

    Website V1 final — a template-backed site is rendered by its
    WebsiteDesign (App\Library\Website\Design): the template owns the
    header, section order, band rhythm, hero, services/packages
    presentation, footer and typography. The extra variables below come from
    App\Http\View\Composers\WebsitePageComposer: $design (null = a legacy
    non-template site, which keeps the original generic chrome), $nav,
    $siteCta, $brandStyle, $logo, $siteContact, $isHomePage.

    Every text field renders through Blade's default escaped {{ }}
    output only (contract §8/§30) — never {!! !!}, never Blade::render()
    on user/AI content, on any field in any component partial included
    below.
--}}
<!DOCTYPE html>
<html lang="{{ $head['lang'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $head['title'] }}</title>
    @if ($head['description'] !== null)
        <meta name="description" content="{{ $head['description'] }}">
    @endif
    {{--
        Contract §21/§40 — every Slice A page is noindex regardless of
        the per-page field, UNLESS $allowIndexing was explicitly passed
        true: only App\Http\Middleware\ResolveCustomDomainWebsite's
        renderer ever does that, and only for a domain whose certificate
        is Active (App\Enums\Website\WebsiteDomainStatus) — the
        platform-path /sites/{public_id} route and preview never pass
        it, so they keep today's behavior exactly.
    --}}
    @php($indexable = ($allowIndexing ?? false) && ! ($page->seo->noindex ?? false))
    <meta name="robots" content="{{ $indexable ? 'index, follow' : 'noindex, follow' }}">
    @foreach ($head['og'] as $ogProperty => $ogContent)
        <meta property="{{ $ogProperty }}" content="{{ $ogContent }}">
    @endforeach
    @foreach ($head['twitter'] as $twitterName => $twitterContent)
        <meta name="{{ $twitterName }}" content="{{ $twitterContent }}">
    @endforeach
    {{--
        Set only once the Website has an active custom domain — see
        Public\WebsiteController::renderPage() and
        ResolveCustomDomainWebsite::renderPage() for why: with no such
        domain there is no address more canonical than this one, so the
        tag is omitted rather than self-referencing a platform path that
        is never indexable in the first place.
    --}}
    @if (! empty($canonicalUrl ?? null))
        <link rel="canonical" href="{{ $canonicalUrl }}">
    @endif
    @if (! empty($inlineCss ?? null))
        {{-- Template preview cards are embedded (srcdoc) and must style themselves: our own two static stylesheets, inlined. --}}
        <style>{!! $inlineCss !!}</style>
    @else
        <link rel="stylesheet" href="{{ asset('css/website-public.css') }}">
        @if ($design ?? null)
            <link rel="stylesheet" href="{{ asset('css/website-design.css') }}?v={{ is_file(public_path('css/website-design.css')) ? filemtime(public_path('css/website-design.css')) : 1 }}">
        @endif
    @endif
    {{--
        LocalBusiness structured data (Website Generation + Hosting gap
        recorded in Implementation Contract 18 §3.2/§3.6) — built only
        from confirmed Business/Location facts by
        App\Library\Website\WebsiteLocalBusinessStructuredData, and only
        ever passed in for a genuinely indexable custom-domain page (see
        ResolveCustomDomainWebsite::renderPage()). The JSON_HEX_* flags
        hex-escape `<`, `>`, `&`, `'`, `"` inside every string value, so
        a business name or address field can never break out of this
        `<script>` element even though this is necessarily a raw,
        unescaped `{!! !!}` output (Blade's `{{ }}` would HTML-entity-
        encode the JSON itself, corrupting it).
    --}}
    @if (! empty($localBusinessJsonLd ?? null))
        <script type="application/ld+json">{!! json_encode($localBusinessJsonLd, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endif
    {{--
        BreadcrumbList (Website Generator + Local SEO Completion) — same
        JSON_HEX_* escaping discipline as localBusinessJsonLd above, and
        only ever passed for a genuinely indexable custom-domain page
        (App\Http\Middleware\ResolveCustomDomainWebsite::renderPage()).
    --}}
    @if (! empty($breadcrumbJsonLd ?? null))
        <script type="application/ld+json">{!! json_encode($breadcrumbJsonLd, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endif
    @php($theme = $websiteMeta['theme'] ?? [])
    @if (! empty($theme))
        <style>
            :root {
                --website-primary: {{ $theme['primary_color'] ?? '#1a56db' }};
                --website-secondary: {{ $theme['secondary_color'] ?? '#111827' }};
                --website-content-width: {{ $theme['content_width'] ?? '960px' }};
            }
        </style>
    @endif
</head>
{{-- A template-driven site is styled by its design only; the legacy variant classes (header/button/font) would leak the old generic styles into it. --}}
<body class="website-body @if ($design ?? null) wd wd-{{ $design->key }} @else website-header-{{ $theme['header_variant'] ?? 'default' }} website-button-{{ $theme['button_style'] ?? 'solid' }} website-font-{{ $theme['font'] ?? 'system' }} @endif"
    @if (($design ?? null) && ! empty($brandStyle)) style="{{ $brandStyle }}" @endif>
    @if ($isPreview)
        <div class="website-preview-banner">{{ $previewBannerText ?? 'Preview — draft content, not yet published' }}</div>
    @endif

    @if ($design ?? null)
        @include('public.website.design.header')

        <?php
            // Template-owned order of a Home page's sections, then the
            // band tone (light / dark / accent / tint) the template gives each type.
            $orderedSections = $isHomePage ? $design->orderHomeSections($sections) : $sections;

            // A hero with no image of its own borrows the owner's hero image
            // (Brand & look), else the first real photo already on this page.
            $heroImageFallback = null;
            $heroUid = $theme['hero_asset_uid'] ?? null;
            if ($heroUid && isset($assetsByUid[$heroUid])) {
                $heroImageFallback = $assetsByUid[$heroUid];
            } else {
                foreach ($sections as $candidate) {
                    $uid = match ($candidate['type'] ?? '') {
                        'image_text' => $candidate['data']['image'] ?? null,
                        'gallery', 'services' => collect($candidate['data']['items'] ?? [])->pluck('image')->filter()->first(),
                        default => null,
                    };
                    if ($uid && isset($assetsByUid[$uid])) {
                        $heroImageFallback = $assetsByUid[$uid];
                        break;
                    }
                }
            }
        ?>

        <main class="wd-main" id="wd-main">
            @foreach ($orderedSections as $section)
                @php($type = $section['type'] ?? '')
                @php($componentView = 'public.website.components.' . $type)
                @php($toneKey = ($type === 'services' && collect($section['data']['items'] ?? [])->contains(fn ($item) => ! empty($item['catalog_item_uid']))) ? 'packages' : $type)
                @if (\Illuminate\Support\Facades\View::exists($componentView))
                    <div class="wd-band wd-tone-{{ $design->toneFor($toneKey) }} wd-band-{{ $toneKey }}" data-section="{{ $toneKey }}">
                        @if ($type === 'hero')
                            @include($componentView, ['data' => $section['data'] ?? [], 'website' => $website, 'assetsByUid' => $assetsByUid ?? [], 'formsByUid' => $formsByUid ?? [], 'isPreview' => $isPreview ?? false, 'pageUid' => $page->uid ?? null, 'heroImageFallback' => $heroImageFallback])
                        @else
                            <div class="website-container wd-container">
                                @include($componentView, ['data' => $section['data'] ?? [], 'website' => $website, 'assetsByUid' => $assetsByUid ?? [], 'formsByUid' => $formsByUid ?? [], 'isPreview' => $isPreview ?? false, 'pageUid' => $page->uid ?? null])
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach
        </main>

        @include('public.website.design.footer')

        <script>
            (function () {
                var header = document.querySelector('[data-wd-header]');
                if (!header) { return; }
                var toggle = header.querySelector('[data-wd-menu-toggle]');
                var nav = header.querySelector('[data-wd-nav]');
                function setMenu(open) {
                    header.setAttribute('data-menu-open', open ? 'true' : 'false');
                    if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
                    document.body.classList.toggle('wd-menu-lock', open);
                }
                if (toggle) {
                    toggle.addEventListener('click', function () { setMenu(toggle.getAttribute('aria-expanded') !== 'true'); });
                }
                header.querySelectorAll('[data-wd-dd-toggle]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var item = button.closest('.wd-nav-item');
                        var open = item.getAttribute('data-open') !== 'true';
                        header.querySelectorAll('.wd-nav-item[data-open="true"]').forEach(function (other) {
                            if (other !== item) {
                                other.setAttribute('data-open', 'false');
                                other.querySelectorAll('[data-wd-dd-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
                            }
                        });
                        item.setAttribute('data-open', open ? 'true' : 'false');
                        item.querySelectorAll('[data-wd-dd-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
                    });
                });
                document.addEventListener('keydown', function (event) {
                    if (event.key !== 'Escape') { return; }
                    setMenu(false);
                    header.querySelectorAll('.wd-nav-item[data-open="true"]').forEach(function (item) {
                        item.setAttribute('data-open', 'false');
                        item.querySelectorAll('[data-wd-dd-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
                    });
                });
                window.addEventListener('resize', function () { if (window.innerWidth > 960) { setMenu(false); } });
            })();
        </script>
    @else
        <header class="website-header">
            <div class="website-container">
                @php($homeNavigation = collect($navigationPages ?? [])->firstWhere('is_home', true))
                @if ($homeNavigation)
                    <a class="website-brand" href="{{ $homeNavigation['url'] }}">{{ $websiteMeta['name'] }}</a>
                @else
                    <span class="website-brand">{{ $websiteMeta['name'] }}</span>
                @endif
                @if (count($navigationPages ?? []) > 1)
                    <nav class="website-navigation" aria-label="Site pages">
                        {{-- A site with many pages (service and location pages) keeps the bar readable:
                             the first pages stay inline, the rest fold into a "More" menu. --}}
                        @php($navInline = array_slice(array_values($navigationPages), 0, 6))
                        @php($navMore = array_slice(array_values($navigationPages), 6))
                        @foreach ($navInline as $navigationPage)
                            <a href="{{ $navigationPage['url'] }}" @if (($page->uid ?? null) === $navigationPage['uid']) aria-current="page" @endif>{{ $navigationPage['title'] }}</a>
                        @endforeach
                        @if (count($navMore) > 0)
                            <details class="website-nav-more" @if (collect($navMore)->contains(fn ($p) => ($page->uid ?? null) === $p['uid'])) open @endif>
                                <summary>More</summary>
                                <div class="website-nav-more-list">
                                    @foreach ($navMore as $navigationPage)
                                        <a href="{{ $navigationPage['url'] }}" @if (($page->uid ?? null) === $navigationPage['uid']) aria-current="page" @endif>{{ $navigationPage['title'] }}</a>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </nav>
                @endif
            </div>
        </header>

        <main class="website-main">
            <div class="website-container">
                @foreach ($sections as $section)
                    @php($componentView = 'public.website.components.' . ($section['type'] ?? ''))
                    @if (\Illuminate\Support\Facades\View::exists($componentView))
                        @include($componentView, ['data' => $section['data'] ?? [], 'website' => $website, 'assetsByUid' => $assetsByUid ?? [], 'formsByUid' => $formsByUid ?? [], 'isPreview' => $isPreview ?? false, 'pageUid' => $page->uid ?? null])
                    @endif
                @endforeach
            </div>
        </main>

        <footer class="website-footer website-footer-{{ $theme['footer_variant'] ?? 'default' }}">
            <div class="website-container">
                <p>&copy; {{ date('Y') }} {{ $websiteMeta['name'] }}</p>
            </div>
        </footer>
    @endif
</body>
</html>
