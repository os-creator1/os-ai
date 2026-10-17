@props([
    'code' => 404,
])

{{--
    The ONE platform error-page shell (404 and 429 today; add a code to
    ErrorPagePresenter to extend it). Self-contained on purpose: no layout,
    bundle or image dependency, so it renders even when the app shell cannot.
    Copy and navigation come only from ErrorPagePresenter; this file never
    reads the exception.
--}}
@php
    $page = app(\App\Library\ErrorPages\ErrorPagePresenter::class)->for((int) $code, request());
    $digits = str_split((string) $page['code']);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $page['title'] }} · {{ config('app.name') }}</title>
    <style>
        :root{--eb-canvas:var(--color-canvas,#F7F6F2);--eb-surface:var(--color-surface,#FFFFFF);--eb-border:var(--color-border,#E5E1DA);--eb-text:var(--color-text-primary,#262522);--eb-muted:var(--color-text-muted,#6F6D67);--eb-accent:var(--color-primary,#B5524C);--eb-accent-hover:var(--color-primary-hover,#A83D38);--eb-accent-soft:var(--color-primary-soft-bg,#F4E6E4)}
        *{box-sizing:border-box}
        html{color-scheme:light}
        body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:var(--eb-canvas);color:var(--eb-text);font-family:"Geist Sans",system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
        .eb-header{padding:1rem 1.5rem}
        .eb-logo{display:inline-flex;align-items:center;height:2rem;max-width:11rem;overflow:hidden}
        .eb-logo img{display:block;height:1.75rem;width:auto;max-width:11rem;object-fit:contain}
        .eb-logo span{font-weight:700}
        .eb-main{flex:1;display:flex;align-items:center;justify-content:center;padding:1.5rem 1rem}
        .eb-inner{width:100%;max-width:30rem;text-align:center}
        .eb-code{margin:0 0 .75rem;font-size:clamp(5rem,22vw,8rem);line-height:1;font-weight:800;letter-spacing:-.04em;color:var(--eb-accent-soft);user-select:none}
        .eb-code b{color:var(--eb-accent);font-weight:inherit}
        .eb-title{margin:0 0 .5rem;font-size:1.375rem;font-weight:700;line-height:1.3}
        .eb-desc{margin:0 auto 1.5rem;max-width:24rem;font-size:.875rem;line-height:1.6;color:var(--eb-muted)}
        .eb-actions{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center}
        .eb-btn{display:inline-flex;align-items:center;justify-content:center;min-height:2.25rem;padding:.5rem 1rem;border-radius:.5rem;border:1px solid var(--eb-border);background:var(--eb-surface);color:var(--eb-text);font:inherit;font-size:.8125rem;font-weight:600;text-decoration:none;cursor:pointer}
        .eb-btn:hover{border-color:var(--eb-accent)}
        .eb-btn:focus-visible{outline:2px solid var(--eb-accent);outline-offset:2px}
        .eb-btn-primary{background:var(--eb-accent);border-color:var(--eb-accent);color:#fff}
        .eb-btn-primary:hover{background:var(--eb-accent-hover);border-color:var(--eb-accent-hover)}
        .eb-card{margin:1.5rem auto 0;max-width:30rem;padding:.875rem 1rem;background:var(--eb-surface);border:1px solid var(--eb-border);border-radius:.75rem;text-align:left}
        .eb-card strong{display:block;font-size:.8125rem}
        .eb-card p{margin:.125rem 0 0;font-size:.75rem;line-height:1.5;color:var(--eb-muted)}
        .eb-footer{display:flex;justify-content:space-between;gap:1rem;padding:1rem 1.5rem;border-top:1px solid var(--eb-border);font-size:.75rem;color:var(--eb-muted)}
        @media (max-width:480px){.eb-actions .eb-btn{flex:1 1 100%}}
    </style>
</head>
<body>
<header class="eb-header">
    <a class="eb-logo" href="{{ $page['homeUrl'] }}">
        <x-branding-logo variant="full" background="light" />
    </a>
</header>

<main class="eb-main" id="main-content">
    <div class="eb-inner" data-error-code="{{ $page['code'] }}">
        <p class="eb-code" aria-hidden="true">{{ $digits[0] }}<b>{{ $digits[1] }}</b>{{ $digits[2] }}</p>
        <h1 class="eb-title">{{ $page['title'] }}</h1>
        <p class="eb-desc">{{ $page['description'] }}</p>

        <div class="eb-actions">
            <a class="eb-btn eb-btn-primary" href="{{ $page['homeUrl'] }}">{{ __('locale.labels.back_to_home') }}</a>
            <a class="eb-btn" href="{{ $page['homeUrl'] }}" data-role="go-back">{{ __('locale.error_pages.go_back') }}</a>
        </div>

        @if($page['card'])
            <div class="eb-card" data-role="context-card">
                <strong>{{ $page['card']['title'] }}</strong>
                <p>{{ $page['card']['text'] }}</p>
            </div>
        @endif
    </div>
</main>

<footer class="eb-footer">
    <span>© {{ date('Y') }} {{ config('app.name') }}. {{ __('locale.error_pages.rights') }}</span>
    <span>{{ __('locale.error_pages.error_label', ['code' => $page['code']]) }}</span>
</footer>

<script>
    // "Go back" uses history only when there is a same-site page to return to;
    // otherwise the link's own href (the safe home destination) applies. History
    // navigation never re-submits a POST.
    (function () {
        var link = document.querySelector('[data-role="go-back"]');
        if (!link) { return; }
        link.addEventListener('click', function (event) {
            var sameSite = false;
            try { sameSite = document.referrer !== '' && new URL(document.referrer).origin === location.origin; } catch (e) {}
            if (sameSite && window.history.length > 1) {
                event.preventDefault();
                window.history.back();
            }
        });
    })();
</script>
</body>
</html>
