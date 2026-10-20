{{--
    Design System M2 Platform Branding contract §6.5/§11 item 11 — emits
    the <link rel="shortcut icon"> tag, used once per master layout,
    replacing each layout's own repeated inline
    `<?php echo asset(config('app.favicon')); ?>` PHP. Never empty —
    BrandingPresenter::favicon() always resolves to a real file (§5/§6.4).
--}}
@php
    // A branded client's tab shows the Agency's favicon when it uploaded one (already gate-checked
    // by ClientChromeBrand); otherwise, and for every non-client request, the platform favicon.
    $faviconSrc = app(\App\Library\Branding\ClientChromeBrand::class)->current(request())['favicon'] ?? null;
    $faviconSrc ??= app(\App\Library\Branding\BrandingPresenter::class)->favicon();
@endphp
<link rel="shortcut icon" type="image/x-icon" href="{{ asset($faviconSrc) }}"/>
