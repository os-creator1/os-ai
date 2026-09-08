@props([
    'surface' => 'auth',
    'dark' => false,
])

{{--
    Customer Experience Slice 2 (contract §9.1, brief §3/§5) — the optional,
    safe presentation region of every authentication screen.

    Reads the one seam, App\Library\Branding\AuthBrandPresenter, which
    resolves in this order: an authorized Agency white-label brand for the
    server-resolved request host → the platform owner's configured branding
    → the neutral "AI Business OS" identity. The result is a presentation
    model of display strings and already-validated public asset paths only.

    surface="auth" renders one of:
      - the owner-configured auth illustration (decorative: alt="");
      - the Agency's own mark (informative: alt = the Agency name) inside
        the typographic panel;
      - the neutral typographic panel — no image at all, so no inherited
        login-v2*.svg / Vuexy illustration is ever the effective default
        and no broken image can render.

    surface="auth-mark" renders the small identity in the screen corner:
    the Agency's logo or initials on a white-labelled screen, otherwise
    the platform logo seam (<x-branding-logo>).

    Design tokens only (CSS custom properties from the merged design
    system); no hex literal, no font-family, no animation.
--}}
@php
    $authBrand = app(\App\Library\Branding\AuthBrandPresenter::class)->for(request());
@endphp
@if($surface === 'auth' || $surface === 'auth-mark')
    @once
    <style>
        .auth-brand-panel,
        .auth-brand-mark {
            --auth-brand-accent: var(--color-primary, var(--bs-primary));
            --auth-brand-accent-contrast: var(--color-button-text, var(--bs-white));
            --auth-brand-surface: var(--color-card, var(--bs-body-bg));
            --auth-brand-border: var(--color-border, var(--bs-border-color));
            --auth-brand-text: var(--color-text, var(--bs-body-color));
            --auth-brand-muted: var(--color-text-muted, var(--bs-secondary-color));
        }
        .auth-brand-panel {
            width: 100%;
            max-width: 32rem;
            margin: 0 auto;
            padding: 2.5rem 2.25rem;
            border: 1px solid var(--auth-brand-border);
            border-radius: 1.25rem;
            background: var(--auth-brand-surface);
            color: var(--auth-brand-text);
            box-shadow: 0 1.5rem 3rem -1.5rem var(--color-elevated-shadow-tint, rgba(0, 0, 0, 0.15));
        }
        .auth-brand-panel__mark,
        .auth-brand-mark__initials {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 1.125rem;
            background: var(--auth-brand-accent);
            color: var(--auth-brand-accent-contrast);
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .auth-brand-panel__mark {
            width: 4.5rem;
            height: 4.5rem;
            font-size: 1.75rem;
        }
        .auth-brand-mark {
            display: inline-flex;
            align-items: center;
            gap: 0.625rem;
            color: var(--auth-brand-text);
            font-weight: 700;
            text-decoration: none;
        }
        .auth-brand-mark__initials {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.625rem;
            font-size: 0.9375rem;
        }
        .auth-brand-mark__logo {
            display: block;
            max-height: 2.5rem;
            max-width: 10rem;
            width: auto;
            height: auto;
        }
        .auth-brand-panel__logo {
            display: block;
            max-width: 12rem;
            max-height: 4.5rem;
            height: auto;
        }
        .auth-brand-panel__name {
            margin: 1.5rem 0 0.5rem;
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.01em;
        }
        .auth-brand-panel__tagline {
            margin: 0;
            font-size: 1.0625rem;
            line-height: 1.5;
            color: var(--auth-brand-muted);
        }
        .auth-brand-panel__areas {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 1.75rem 0 0;
            padding: 0;
            list-style: none;
        }
        .auth-brand-panel__areas li {
            padding: 0.35rem 0.75rem;
            border: 1px solid var(--auth-brand-border);
            border-radius: 999px;
            font-size: 0.875rem;
            color: var(--auth-brand-text);
        }
        .auth-brand-panel__rule {
            height: 0.375rem;
            width: 3rem;
            margin: 1.5rem 0 0;
            border-radius: 999px;
            background: var(--auth-brand-accent);
        }
        .auth-brand-illustration {
            display: block;
            max-width: 100%;
            height: auto;
            margin: 0 auto;
        }
    </style>
    @endonce
@endif
@if($surface === 'auth')
    @if($authBrand->hasIllustration())
        <img src="{{ asset($authBrand->illustrationSrc) }}" alt="" role="presentation" {{ $attributes->merge(['class' => 'auth-brand-illustration img-fluid']) }} />
    @else
        <div {{ $attributes->merge(['class' => 'auth-brand-panel']) }} data-role="auth-brand-panel" data-brand-source="{{ $authBrand->source->value }}" @if($authBrand->tokenStyle()) style="{{ $authBrand->tokenStyle() }}" @endif>
            @if($authBrand->hasLogo())
                <img src="{{ asset($authBrand->logoSrc) }}" alt="{{ $authBrand->logoAlt }}" class="auth-brand-panel__logo" />
            @else
                <span class="auth-brand-panel__mark" aria-hidden="true">{{ $authBrand->mark }}</span>
            @endif
            <p class="auth-brand-panel__name">{{ $authBrand->displayName }}</p>
            @if($authBrand->tagline)
                <p class="auth-brand-panel__tagline">{{ $authBrand->tagline }}</p>
            @endif
            @if($authBrand->areas !== [])
                <ul class="auth-brand-panel__areas" aria-label="{{ __('locale.auth.brand_panel_areas_label') }}">
                    @foreach($authBrand->areas as $area)
                        <li>{{ $area }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="auth-brand-panel__rule" aria-hidden="true"></div>
        </div>
    @endif
@elseif($surface === 'auth-mark')
    @if($authBrand->isAgency())
        <span class="auth-brand-mark" data-role="auth-brand-mark" data-brand-source="agency" @if($authBrand->tokenStyle()) style="{{ $authBrand->tokenStyle() }}" @endif>
            @if($authBrand->hasLogo())
                <img src="{{ asset($authBrand->logoSrc) }}" alt="{{ $authBrand->logoAlt }}" class="auth-brand-mark__logo" />
            @else
                <span class="auth-brand-mark__initials" aria-hidden="true">{{ $authBrand->mark }}</span>
                <span>{{ $authBrand->displayName }}</span>
            @endif
        </span>
    @else
        <x-branding-logo variant="full" background="light" />
    @endif
@else
    @php
        $illustration = app(\App\Library\Branding\BrandingPresenter::class)->illustration($surface, $dark ? 'dark' : 'light');
    @endphp
    @if(! empty($illustration['src']))
        <img src="{{ asset($illustration['src']) }}" alt="{{ $illustration['alt'] }}" {{ $attributes->merge(['class' => 'img-fluid']) }} />
    @endif
@endif
