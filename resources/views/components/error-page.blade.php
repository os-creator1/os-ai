@props([
    'code' => 404,
    // 503 only: offer the maintenance "notify me" form (see errors/503.blade.php for when it is available).
    'notify' => false,
])

{{--
    The ONE platform error-page shell for every customer-facing status
    (401, 403, 404, 419, 429, 500, 503). Self-contained on purpose: no layout,
    bundle, image or icon-library dependency, so it renders even when the app shell
    cannot (and when pre-rendered for maintenance mode). Copy, icons and navigation
    come only from ErrorPagePresenter; this file never reads the exception.
--}}
@php
    $page = app(\App\Library\ErrorPages\ErrorPagePresenter::class)->for((int) $code, request());
    // White label: a customer of an Agency sees the Agency's name and colours on error pages too. Resolved only from the
    // persisted client relationship (and only when the request got far enough to have a customer context). Any failure
    // here falls back to MotionGrove branding rather than breaking the error page.
    $agencyChrome = null;
    $agencyThemeStyle = null;
    try {
        $agencyChrome = app(\App\Library\Branding\ClientChromeBrand::class)->current(request());
        $agencyThemeStyle = app(\App\Library\Branding\AgencyThemeStyle::class)->forRequest(request());
    } catch (\Throwable) {
        $agencyChrome = null;
        $agencyThemeStyle = null;
    }
    // Maintenance notification: 'form' (a live response: POST + CSRF to maintenance.notify with the visitor's own
    // session token) or 'link' (the prerendered page: a plain link to the live form). Never shown otherwise.
    $notifyToken = '';
    $notifyLink = null;
    if ($notify === 'link' && (int) $code === 503 && \Illuminate\Support\Facades\Route::has('maintenance.notify.form')) {
        $notifyLink = route('maintenance.notify.form');
    }
    if ($notify === 'form' && (int) $code === 503 && \Illuminate\Support\Facades\Route::has('maintenance.notify')) {
        try {
            $notifyToken = (string) csrf_token();
        } catch (\Throwable) {
            $notifyToken = '';
        }
    }
    $showNotify = $notifyToken !== '';
    $notifyError = null;
    try {
        $notifyError = isset($errors) ? $errors->first('email') : null;
    } catch (\Throwable) {
        $notifyError = null;
    }
    $errorBrandName = $agencyChrome['name'] ?? config('app.name');
    $logoHref = app(\App\Library\ErrorPages\ErrorPagePresenter::class)->publicHomeUrl();
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $page['title'] }} · {{ $errorBrandName }}</title>
    <style>
        :root{--eb-canvas:var(--color-canvas,#F7F6F2);--eb-surface:var(--color-surface,#FFFFFF);--eb-border:var(--color-border,#E5E1DA);--eb-text:var(--color-text-primary,#262522);--eb-muted:var(--color-text-muted,#6F6D67);--eb-accent:var(--color-primary,#B5524C);--eb-accent-hover:var(--color-primary-hover,#A83D38);--eb-accent-soft:var(--color-primary-soft-bg,#F4E6E4)}
        *{box-sizing:border-box}
        html{color-scheme:light}
        body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:var(--eb-canvas);color:var(--eb-text);font-family:"Geist Sans",system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
        .eb-header{padding:1rem 1.5rem}
        .eb-logo{display:inline-flex;align-items:center;height:2rem;max-width:11rem;overflow:hidden;color:inherit;text-decoration:none}
        .eb-logo img{display:block;height:1.75rem;width:auto;max-width:11rem;object-fit:contain}
        .eb-logo [data-role="agency-brand-mark"],.eb-logo>span{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:50%;background:var(--eb-accent);color:#fff;font-weight:700}
        .eb-logo .sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}
        .eb-main{flex:1;display:flex;align-items:center;justify-content:center;padding:1.5rem 1rem 3rem}
        .eb-inner{width:100%;max-width:26rem;text-align:center}
        .eb-icon{display:inline-flex;align-items:center;justify-content:center;width:3rem;height:3rem;margin:0 0 1rem;border-radius:50%;background:var(--eb-accent-soft);color:var(--eb-accent)}
        .eb-icon svg{width:1.375rem;height:1.375rem;fill:none;stroke:currentColor;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round}
        .eb-icon-warn{background:#FBF1DC;color:#9A6A10}
        .eb-icon-info{background:#E6EEF7;color:#3B6A9C}
        .eb-icon-neutral{background:#EDEBE5;color:#6F6D67}
        .eb-icon-danger{background:#F7E3E1;color:#B5413B}
        .eb-title{margin:0 0 .5rem;font-size:1.375rem;font-weight:700;line-height:1.3;letter-spacing:-.01em}
        .eb-desc{margin:0 auto 1.5rem;max-width:22rem;font-size:.9375rem;line-height:1.6;color:var(--eb-muted)}
        .eb-actions{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center}
        .eb-btn{display:inline-flex;align-items:center;justify-content:center;min-height:2.5rem;padding:.5rem 1.125rem;border-radius:.5rem;border:1px solid var(--eb-border);background:var(--eb-surface);color:var(--eb-text);font:inherit;font-size:.875rem;font-weight:600;text-decoration:none;cursor:pointer}
        .eb-btn:hover{border-color:var(--eb-accent)}
        .eb-btn:focus-visible{outline:2px solid var(--eb-accent);outline-offset:2px}
        .eb-btn-primary{background:var(--eb-accent);border-color:var(--eb-accent);color:#fff}
        .eb-btn-primary:hover{background:var(--eb-accent-hover);border-color:var(--eb-accent-hover)}
        .eb-notified{margin:0 auto 1rem;max-width:22rem;padding:.625rem .875rem;border-radius:.5rem;background:#E6EEF7;color:#2F5780;font-size:.8125rem}
        .eb-notify{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;margin:0 0 1rem}
        .eb-notify input{flex:1 1 12rem;min-height:2.5rem;padding:.5rem .75rem;border-radius:.5rem;border:1px solid var(--eb-border);background:var(--eb-surface);color:var(--eb-text);font:inherit;font-size:.875rem}
        .eb-notify-error{flex:1 1 100%;margin:0;font-size:.75rem;color:#B5413B}
        .eb-card{margin:1.5rem auto 0;max-width:26rem;padding:.875rem 1rem;background:var(--eb-surface);border:1px solid var(--eb-border);border-radius:.75rem;text-align:left}
        .eb-card strong{display:block;font-size:.8125rem}
        .eb-card p{margin:.125rem 0 0;font-size:.75rem;line-height:1.5;color:var(--eb-muted)}
        .eb-footer{display:flex;justify-content:space-between;gap:1rem;padding:1rem 1.5rem;font-size:.75rem;color:var(--eb-muted)}
        @media (max-width:480px){.eb-actions .eb-btn{flex:1 1 100%}.eb-footer{flex-direction:column;gap:.25rem;text-align:center}.eb-header{padding:1rem}}
    </style>
    @if ($agencyThemeStyle){!! $agencyThemeStyle !!}@endif
</head>
<body>
<header class="eb-header">
    <a class="eb-logo" href="{{ $logoHref }}">
        <x-branding-logo variant="full" background="light" />
    </a>
</header>

<main class="eb-main" id="main-content">
    <div class="eb-inner" data-error-code="{{ $page['code'] }}">
        <span class="eb-icon eb-icon-{{ $page['tone'] }}" data-role="status-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false">{!! $page['icon'] !!}</svg></span>
        <h1 class="eb-title">{{ $page['title'] }}</h1>
        <p class="eb-desc">{{ $page['description'] }}</p>

        @if($notifyLink)
            <p class="eb-desc" style="margin-bottom:1rem"><a data-role="maintenance-notify-link" href="{{ $notifyLink }}">{{ __('locale.error_pages.notify_button') }}</a></p>
        @endif
        @if($showNotify)
            @if(request('notified'))
                <p class="eb-notified" role="status" data-role="maintenance-notified">{{ __('locale.http.503.notify') }}</p>
            @endif
            <form class="eb-notify" method="POST" action="{{ route('maintenance.notify') }}" data-role="maintenance-notify-form">
                <input type="hidden" name="_token" value="{{ $notifyToken }}">
                <input type="email" name="email" required autocomplete="email" placeholder="{{ __('locale.error_pages.notify_placeholder') }}" aria-label="{{ __('locale.error_pages.notify_placeholder') }}" value="{{ old('email') }}">
                <button type="submit" class="eb-btn eb-btn-primary">{{ __('locale.error_pages.notify_button') }}</button>
                @if($notifyError)<p class="eb-notify-error">{{ $notifyError }}</p>@endif
            </form>
        @endif

        <div class="eb-actions">
            <a class="eb-btn eb-btn-primary" data-role="primary-action" href="{{ $page['primary']['url'] }}">{{ $page['primary']['label'] }}</a>
            @if($page['secondary'])
                <a class="eb-btn" data-role="secondary-action" href="{{ $page['secondary']['url'] }}">{{ $page['secondary']['label'] }}</a>
            @endif
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
    <span>© {{ date('Y') }} {{ $errorBrandName }}. {{ __('locale.error_pages.rights') }}</span>
    <span>{{ __('locale.error_pages.error_label', ['code' => $page['code']]) }}</span>
</footer>
</body>
</html>
