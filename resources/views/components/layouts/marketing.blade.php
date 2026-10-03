<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta
        :title="isset($title) ? ($title . ' — ' . config('app.name')) : config('app.name')"
        :description="$description ?? null"
    />
    <x-branding-favicon />
    <link rel="stylesheet" href="{{ asset('css/marketing.css') }}">
    {{--
        Reuses PlatformThemeManager::currentStyleBlock() exactly as
        panels/styles.blade.php does for the authenticated app shell, so
        the public homepage and signup pages automatically pick up the
        Platform Owner's active Theme Preset colors — never a second,
        hardcoded color scheme.
    --}}
    @php($platformThemeStyleBlock = app(\App\Library\Theme\PlatformThemeManager::class)->currentStyleBlock())
    @if ($platformThemeStyleBlock)
        {!! $platformThemeStyleBlock !!}
    @endif
    @stack('marketing-styles')
</head>
<body class="marketing-page">
<header class="marketing-header">
    <div class="marketing-shell marketing-header__inner">
        <a href="{{ url('/') }}" class="marketing-header__brand">
            <x-branding-logo variant="full" background="light" class="marketing-header__logo" />
        </a>
        <nav class="marketing-header__nav" aria-label="Primary">
            {{ $nav ?? '' }}
        </nav>
        <div class="marketing-header__actions">
            <a href="{{ route('login') }}" class="marketing-btn marketing-btn--ghost">{{ __('Log in') }}</a>
            @if (config('account.can_register'))
                <a href="{{ route('register') }}" class="marketing-btn marketing-btn--primary">{{ __('Create account') }}</a>
            @endif
        </div>
    </div>
</header>

<main class="marketing-main">
    {{ $slot }}
</main>

<footer class="marketing-footer">
    <div class="marketing-shell marketing-footer__inner">
        <x-branding-logo variant="compact" background="light" class="marketing-footer__logo" />
        <span class="marketing-footer__copyright"><x-branding-footer /></span>
    </div>
</footer>
</body>
</html>
