@props([
    'variant' => 'full',
    'background' => 'light',
])

{{--
    Design System M2 Platform Branding contract §6.5/§11 item 10 — the
    single logo-rendering seam. Renders <img> when BrandingPresenter::logo()
    resolves a real asset (owner-configured, or the bundled neutral
    default that config('app.logo')/'app.logo_compact' always fall back
    to); otherwise renders a deterministic accessible text mark, never a
    broken <img>.
--}}
@php
    $branding = app(\App\Library\Branding\BrandingPresenter::class)->logo($variant, $background);
    // Agency V1 completion — a signed-in client of an Agency with an enabled,
    // authorized white-label brand sees the AGENCY's mark in place of the
    // platform's (ClientChromeBrand: persisted relationship + entitlement,
    // re-derived per request). Null for everyone else, and on every guest
    // or error page, so those render exactly as before.
    $agencyChrome = app(\App\Library\Branding\ClientChromeBrand::class)->current(request());
@endphp
@if($agencyChrome !== null && $agencyChrome['logo'] !== null)
    <img src="{{ asset($agencyChrome['logo']) }}" alt="{{ $agencyChrome['name'] }}" {{ $attributes }} />
@elseif($agencyChrome !== null)
    <span {{ $attributes->merge(['class' => 'd-inline-flex align-items-center justify-content-center rounded-circle fw-bold ' . ($agencyChrome['accent'] === null ? 'bg-primary text-white' : '')]) }} style="width:2rem;height:2rem;@if($agencyChrome['accent'] !== null)background-color:{{ $agencyChrome['accent'] }};color:{{ $agencyChrome['accentContrast'] }};@endif" data-role="agency-brand-mark">
        <span aria-hidden="true">{{ $agencyChrome['mark'] }}</span>
        <span class="sr-only">{{ $agencyChrome['name'] }}</span>
    </span>
@elseif($branding['src'])
    <img src="{{ asset($branding['src']) }}" alt="{{ $branding['alt'] }}" {{ $attributes }} />
@else
    {{--
        No owner-configured or bundled asset resolved (defensive-only path
        — config('app.logo') always has a real bundled default, §5, so
        this branch exists purely as a structural guarantee against a
        broken <img>, not a normally-exercised state). Pure Bootstrap
        utility classes only — no new SCSS/CSS file is authorized by
        this contract's own allowlist (§11).
    --}}
    <span {{ $attributes->merge(['class' => 'd-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-bold']) }} style="width:2rem;height:2rem;">
        <span aria-hidden="true">{{ Str::of($branding['alt'])->substr(0, 1) }}</span>
        <span class="sr-only">{{ $branding['alt'] }}</span>
    </span>
@endif
