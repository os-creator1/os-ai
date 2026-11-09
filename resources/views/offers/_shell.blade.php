@inject('authBranding', 'App\Library\Branding\AuthBrandPresenter')
@php
    $authBrand = $authBranding->for(request());
    $offerLocked = trim($__env->yieldContent('gift-locked')) !== '';
    $flashStatus = session('status');
    $flashMessage = is_string($flashStatus) && is_string(session('message')) && trim(session('message')) !== '' ? trim(session('message')) : null;
    $flashIsError = in_array($flashStatus, ['error', 'warning'], true);
@endphp
<!DOCTYPE html>
{{--
    THE PRIVATE OFFER SHELL. Every state of an offer link (signed out, wrong
    account, correct account, verification, workspace choice, payment in
    progress, redeemed, subscribed, unavailable) renders inside this one page:
    a brand-red gift panel on the left, the offer on a white panel on the right.

    Self-contained on purpose: scoped `.pof-*` CSS, no JavaScript, no external
    request. The gift floats with CSS only and stops for prefers-reduced-motion.
    The page carries a secret in its URL, so it is never cached or leaked
    through a Referer header (see PrivateOfferController::private()).
--}}
<html lang="{{ str_replace('_', '-', Session::has('locale') ? Session::get('locale') : config('app.locale')) }}" data-textdirection="{{ config('app.locale_direction') === 'rtl' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ $authBrand->displayName }}</title>
    <x-branding-favicon />
    <style>
        .pof, .pof *, .pof *::before, .pof *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #FFFFFF; }
        .pof {
            --pof-brand: #B4514A;
            --pof-brand-dark: #9A423C;
            --pof-ink: #181413;
            --pof-body: #4A4340;
            --pof-muted: #6B625E;
            --pof-line: #F0E3E0;
            --pof-green: #1F5E4A;
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            grid-template-columns: 1fr;
            background: #FFFFFF;
            color: var(--pof-ink);
            font-family: 'Figtree', 'Geist Variable', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .pof a { text-decoration: none; }
        .pof a:not(.pof-btn) { color: inherit; }
        .pof :focus-visible { outline: 3px solid #181413; outline-offset: 3px; border-radius: 12px; }
        .pof-gift-panel :focus-visible { outline-color: #FFFFFF; }

        /* ---- Gift panel ------------------------------------------------ */
        .pof-gift-panel {
            position: relative; overflow: hidden; background: var(--pof-brand);
            min-height: 240px; display: flex; align-items: center; justify-content: center;
        }
        .pof-circle { position: absolute; border-radius: 50%; background: rgba(255,255,255,.08); }
        .pof-circle-1 { width: min(640px, 130%); aspect-ratio: 1; }
        .pof-circle-2 { width: min(440px, 90%); aspect-ratio: 1; }
        .pof-cf { position: absolute; border-radius: 3px; }
        .pof-gift {
            position: relative; display: block; width: 130px; height: 130px;
            filter: drop-shadow(0 16px 20px rgba(60,20,16,.35));
            animation: pof-float 5s ease-in-out infinite;
            will-change: transform;
        }
        @keyframes pof-float {
            0%, 100% { transform: translateY(0) rotate(-4deg); }
            50% { transform: translateY(-10px) rotate(-4deg); }
        }
        .pof-brandmark { position: absolute; left: 20px; top: 16px; z-index: 2; display: flex; align-items: center; max-width: calc(100% - 40px); }
        .pof-brandmark img { display: block; height: 26px; width: auto; max-width: 160px; object-fit: contain; }
        .pof-brandmark-text { font-weight: 800; font-size: 17px; color: #FFFFFF; letter-spacing: -.01em; }

        /* ---- Offer panel ----------------------------------------------- */
        .pof-main { display: flex; align-items: center; justify-content: center; padding: 32px 20px 48px; min-width: 0; }
        .pof-inner { width: 100%; max-width: 460px; display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        .pof-badge {
            align-self: flex-start; display: inline-flex; align-items: center; gap: 8px; height: 32px; padding: 0 14px;
            border-radius: 999px; background: #F8E9E6; color: #8F3B35; font-size: 13px; font-weight: 700;
            letter-spacing: .04em; text-transform: uppercase;
        }
        .pof-ic { width: 16px; height: 16px; flex: none; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .pof-h1 { margin: 0; font-size: 34px; line-height: 1.06; font-weight: 800; letter-spacing: -.035em; overflow-wrap: anywhere; }
        .pof-lead { margin: 0; font-size: 16.5px; line-height: 1.55; color: var(--pof-body); }
        .pof-small { margin: 0; font-size: 12.5px; line-height: 1.5; color: var(--pof-muted); text-align: center; }
        .pof-actions { display: flex; flex-direction: column; gap: 10px; }
        .pof-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; min-height: 52px;
            padding: 0 24px; border-radius: 12px; border: 1px solid transparent; font: inherit; font-size: 16px;
            font-weight: 600; cursor: pointer; text-align: center; line-height: 1.2;
        }
        .pof-btn-primary { background: var(--pof-brand); color: #FFFFFF; box-shadow: 0 12px 24px -10px rgba(180,81,74,.6); }
        .pof-btn-primary:hover { background: var(--pof-brand-dark); }
        .pof-btn-secondary { background: #FFFFFF; color: #2E2826; border-color: #D6CFC8; }
        .pof-btn-secondary:hover { background: #F6F4F0; }
        .pof-form { margin: 0; }
        .pof-alert { margin: 0; padding: 12px 14px; border-radius: 12px; font-size: 14px; line-height: 1.45; }
        .pof-alert-error { background: #FDECEA; color: #8A2A22; border: 1px solid #F3C6C1; }
        .pof-alert-ok { background: #E4F0EA; color: #1F5E4A; border: 1px solid #C5DDD1; }
        .pof-account {
            display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 14px; background: #F6F4F0; min-width: 0;
        }
        .pof-avatar {
            flex: none; width: 32px; height: 32px; border-radius: 50%; background: #F8E9E6; color: #8F3B35;
            display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; text-transform: uppercase;
        }
        .pof-account-meta { min-width: 0; display: flex; flex-direction: column; gap: 1px; }
        .pof-account-label { font-size: 11.5px; color: var(--pof-muted); }
        .pof-account-email { font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }
        .pof-choices { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }

        /* ---- Terms card ------------------------------------------------- */
        .pof-card { border-radius: 20px; border: 2px solid var(--pof-brand); background: #FFFBFA; overflow: hidden; }
        .pof-card-body { padding: 20px; display: flex; flex-direction: column; gap: 12px; }
        .pof-card-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .pof-plan { margin: 0; font-size: 18px; font-weight: 700; }
        .pof-pill { display: inline-flex; align-items: center; gap: 6px; min-height: 28px; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 700; line-height: 1.25; }
        .pof-pill-trial { background: var(--pof-green); color: #FFFFFF; }
        .pof-pill-lock { align-self: flex-start; background: #F8E7A8; color: #5A4300; }
        .pof-pill-warn { align-self: flex-start; background: #FCEBC9; color: #6B4700; }
        .pof-price-row { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
        .pof-price { font-size: 42px; font-weight: 800; letter-spacing: -.04em; color: var(--pof-ink); line-height: 1.05; overflow-wrap: anywhere; }
        .pof-was { font-size: 19px; color: #8A817C; text-decoration: line-through; }
        .pof-cycle { font-size: 15px; color: #5E5652; }
        .pof-features { list-style: none; margin: 4px 0 0; padding: 14px 0 0; border-top: 1px solid var(--pof-line); display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px 16px; font-size: 14.5px; }
        .pof-features li { display: flex; gap: 10px; align-items: center; min-width: 0; }
        .pof-tick { width: 22px; height: 22px; flex: none; border-radius: 50%; background: #E4F0EA; color: var(--pof-green); display: flex; align-items: center; justify-content: center; }
        .pof-tick .pof-ic { width: 12px; height: 12px; stroke-width: 2.6; }
        .pof-facts { display: flex; flex-wrap: wrap; border-top: 1px solid var(--pof-line); background: #FFFFFF; }
        .pof-fact { flex: 1 1 140px; padding: 14px 20px; min-width: 0; }
        .pof-fact + .pof-fact { border-inline-start: 1px solid var(--pof-line); }
        .pof-fact-label { font-size: 12.5px; color: #5E5652; }
        .pof-fact-value { display: block; font-size: 17px; font-weight: 700; overflow-wrap: anywhere; }
        .pof-notes { margin: 0; padding: 14px 20px; border-top: 1px solid var(--pof-line); background: #FFFFFF; display: flex; flex-direction: column; gap: 6px; font-size: 14px; line-height: 1.45; color: var(--pof-body); }
        .pof-notes dt { font-size: 12.5px; color: var(--pof-muted); margin: 0; }
        .pof-notes dd { margin: 0 0 4px; overflow-wrap: anywhere; }
        .pof-footnote { padding: 12px 20px 16px; background: #FFFFFF; font-size: 12.5px; line-height: 1.5; color: var(--pof-muted); }
        .pof-footnote p { margin: 0; }
        .pof-consent { display: flex; align-items: flex-start; gap: 12px; font-size: 14.5px; line-height: 1.45; color: var(--pof-body); cursor: pointer; padding: 4px 0; }
        .pof-consent input { flex: none; width: 22px; height: 22px; margin: 0; accent-color: var(--pof-brand); cursor: pointer; }

        /* ---- Tablet / desktop ------------------------------------------ */
        @media (min-width: 640px) {
            .pof-gift-panel { min-height: 300px; }
            .pof-gift { width: 180px; height: 180px; }
            .pof-brandmark { left: 32px; top: 24px; }
            .pof-main { padding: 48px 32px 64px; }
            .pof-h1 { font-size: 40px; }
            .pof-card-body { padding: 22px 24px; }
            .pof-price { font-size: 48px; }
            .pof-fact { padding: 14px 24px; }
            .pof-notes, .pof-footnote { padding-inline: 24px; }
        }
        @media (min-width: 960px) {
            .pof { grid-template-columns: 1fr 1fr; }
            .pof-gift-panel { position: sticky; top: 0; height: 100vh; height: 100dvh; min-height: 480px; align-self: start; }
            .pof-gift { width: 300px; height: 300px; filter: drop-shadow(0 30px 40px rgba(60,20,16,.35)); }
            .pof-brandmark { left: 40px; top: 32px; }
            .pof-main { padding: 56px 40px; min-height: 100vh; min-height: 100dvh; }
            .pof-h1 { font-size: 44px; }
            .pof-price { font-size: 50px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .pof-gift { animation: none; will-change: auto; }
        }
        @media (forced-colors: active) {
            .pof-btn { border-color: ButtonText; }
        }
    </style>
</head>
<body>
<div class="pof">
    {{-- Gift side: decorative. The real application logo sits small in the corner. --}}
    <div class="pof-gift-panel" data-role="offer-gift-panel">
        <div class="pof-circle pof-circle-1" aria-hidden="true"></div>
        <div class="pof-circle pof-circle-2" aria-hidden="true"></div>
        <span class="pof-cf" aria-hidden="true" style="left:14%;top:18%;width:14px;height:6px;background:#F8E7A8;transform:rotate(30deg)"></span>
        <span class="pof-cf" aria-hidden="true" style="left:22%;top:70%;width:10px;height:10px;border-radius:50%;background:#FFFFFF"></span>
        <span class="pof-cf" aria-hidden="true" style="left:76%;top:22%;width:16px;height:6px;background:#9FD3BB;transform:rotate(-25deg)"></span>
        <span class="pof-cf" aria-hidden="true" style="left:82%;top:64%;width:12px;height:5px;background:#F8E7A8;transform:rotate(60deg)"></span>
        <span class="pof-cf" aria-hidden="true" style="left:66%;top:82%;width:8px;height:8px;border-radius:50%;background:#9FD3BB"></span>
        <span class="pof-cf" aria-hidden="true" style="left:30%;top:12%;width:8px;height:8px;border-radius:50%;background:#FFFFFF"></span>
        <span class="pof-cf" aria-hidden="true" style="left:10%;top:44%;width:12px;height:5px;background:#FFFFFF;transform:rotate(-50deg)"></span>
        <span class="pof-cf" aria-hidden="true" style="left:88%;top:42%;width:8px;height:8px;border-radius:50%;background:#FFFFFF"></span>

        <svg class="pof-gift" viewBox="0 0 240 240" aria-hidden="true" focusable="false" data-role="offer-gift" @if($offerLocked) data-locked="true" @endif>
            <path d="M120 60c-14-30-52-34-56-14-3 16 26 20 56 14z" fill="#F8E7A8"/>
            <path d="M120 60c14-30 52-34 56-14 3 16-26 20-56 14z" fill="#F8E7A8"/>
            <rect x="36" y="62" width="168" height="40" rx="8" fill="#FFFFFF"/>
            <rect x="46" y="100" width="148" height="104" rx="8" fill="#FFFFFF"/>
            <rect x="46" y="100" width="148" height="14" fill="#EEDFDA"/>
            <rect x="106" y="62" width="28" height="142" fill="#F8E7A8"/>
            <circle cx="120" cy="60" r="10" fill="#E9C96E"/>
            @if($offerLocked)
                {{-- Restricted: a small dark lock badge over the box. --}}
                <circle cx="120" cy="152" r="24" fill="#181413"/>
                <rect x="108" y="150" width="24" height="18" rx="4" fill="#FFFFFF"/>
                <path d="M112.5 150v-5a7.5 7.5 0 0 1 15 0v5" fill="none" stroke="#FFFFFF" stroke-width="4" stroke-linecap="round"/>
                <circle cx="120" cy="158.5" r="2.6" fill="#181413"/>
            @endif
        </svg>

        <div class="pof-brandmark">
            <a href="{{ url('/') }}" aria-label="{{ $authBrand->displayName }}" data-role="offer-brand">
                <x-branding-logo variant="full" background="dark" />
            </a>
        </div>
    </div>

    {{-- Offer side --}}
    <main class="pof-main" id="main-content">
        <div class="pof-inner">
            <span class="pof-badge">
                <svg class="pof-ic" viewBox="0 0 24 24" aria-hidden="true" style="width:14px;height:14px"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/></svg>
                {{ __('Private offer') }}
            </span>

            <h1 class="pof-h1">@yield('heading')</h1>

            @hasSection('lead')
                <p class="pof-lead">@yield('lead')</p>
            @endif

            @if ($errors->any())
                <p class="pof-alert pof-alert-error" role="alert" data-role="offer-errors">{{ $errors->first() }}</p>
            @endif

            @if ($flashMessage !== null && in_array($flashStatus, ['error', 'warning', 'success', 'info'], true))
                <p class="pof-alert {{ $flashIsError ? 'pof-alert-error' : 'pof-alert-ok' }}" role="{{ $flashIsError ? 'alert' : 'status' }}" data-role="auth-flash">{{ $flashMessage }}</p>
            @endif

            @yield('offer-body')
        </div>
    </main>
</div>
</body>
</html>
