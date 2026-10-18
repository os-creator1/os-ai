{{--
    MotionGrove public marketing website — page shell.
    Standalone on purpose: it never extends the application's dashboard layouts.
    Receives: $mg (App\Library\Marketing\MarketingSite), $page, $meta, optional $faqs for FAQPage markup.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $meta['title'] }}</title>
    <meta name="description" content="{{ $meta['description'] }}">
    <link rel="canonical" href="{{ $meta['canonical'] }}">
    <meta property="og:site_name" content="{{ config('marketing.site.name') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $meta['title'] }}">
    <meta property="og:description" content="{{ $meta['description'] }}">
    <meta property="og:url" content="{{ $meta['canonical'] }}">
    <meta property="og:image" content="{{ $meta['image'] }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $meta['title'] }}">
    <meta name="twitter:description" content="{{ $meta['description'] }}">
    <link rel="icon" type="image/png" href="{{ $mg->asset('img/mg-mark.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&amp;display=swap">
    <link rel="stylesheet" href="{{ $mg->asset('css/marketing.css') }}">
    <script>document.documentElement.className += ' js';</script>
    <script type="application/ld+json">{!! json_encode(array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => config('marketing.site.name'),
        'url' => $mg->url('home'),
        'logo' => $mg->asset('img/mg-mark.png'),
        'sameAs' => array_values($mg->social()) ?: null,
    ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @if (! empty($faqs))
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($f) => [
                '@type' => 'Question',
                'name' => $f['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
            ], $faqs),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @endif
</head>
<body class="mg {{ $page === 'home' ? 'mg-home' : 'mg-inner mg-'.$page }}">
<a class="skip" href="#main">Skip to content</a>
@include('marketing.partials.header', ['active' => $page])
<main id="main">
    @yield('content')
</main>
@yield('footer')
<script src="{{ $mg->asset('js/marketing.js') }}" defer></script>
</body>
</html>
