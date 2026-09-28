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

    Every text field renders through Blade's default escaped {{ }}
    output only (contract §8/§30) — never {!! !!}, never Blade::render()
    on user/AI content, on any field in any component partial included
    below.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->seo->seo_title ?: $page->title }} — {{ $websiteMeta['name'] }}</title>
    <meta name="description" content="{{ $page->seo->meta_description }}">
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
    <meta property="og:title" content="{{ $page->seo->seo_title ?: $page->title }}">
    <meta property="og:description" content="{{ $page->seo->meta_description }}">
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
    <link rel="stylesheet" href="{{ asset('css/website-public.css') }}">
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
<body class="website-body website-header-{{ $theme['header_variant'] ?? 'default' }} website-button-{{ $theme['button_style'] ?? 'solid' }} website-font-{{ $theme['font'] ?? 'system' }}">
    @if ($isPreview)
        <div class="website-preview-banner">Preview — draft content, not yet published</div>
    @endif

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
                    @foreach ($navigationPages as $navigationPage)
                        <a href="{{ $navigationPage['url'] }}" @if (($page->uid ?? null) === $navigationPage['uid']) aria-current="page" @endif>{{ $navigationPage['title'] }}</a>
                    @endforeach
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
</body>
</html>
