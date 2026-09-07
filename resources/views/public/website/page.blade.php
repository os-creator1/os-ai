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
    $isPreview (bool).

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
    @if ($page->seo->noindex ?? false)
        <meta name="robots" content="noindex, follow">
    @endif
    {{-- Contract §21 — every Slice A public page is noindex regardless
         of the per-page field above, until a verified custom domain
         exists (Slice B). --}}
    <meta name="robots" content="noindex, follow">
    <meta property="og:title" content="{{ $page->seo->seo_title ?: $page->title }}">
    <meta property="og:description" content="{{ $page->seo->meta_description }}">
    <link rel="stylesheet" href="{{ asset('css/website-public.css') }}">
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
<body class="website-body website-header-{{ $theme['header_variant'] ?? 'default' }} website-button-{{ $theme['button_style'] ?? 'solid' }}">
    @if ($isPreview)
        <div class="website-preview-banner">Preview — draft content, not yet published</div>
    @endif

    <header class="website-header">
        <div class="website-container">
            <span class="website-brand">{{ $websiteMeta['name'] }}</span>
        </div>
    </header>

    <main class="website-main">
        <div class="website-container">
            @foreach ($sections as $section)
                @php($componentView = 'public.website.components.' . ($section['type'] ?? ''))
                @if (\Illuminate\Support\Facades\View::exists($componentView))
                    @include($componentView, ['data' => $section['data'] ?? [], 'website' => $website, 'assetsByUid' => $assetsByUid ?? []])
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
