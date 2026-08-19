@props([
    'surface',
    'dark' => false,
])

{{--
    Design System M2 Platform Branding contract §6.5/§11 item 12. $surface
    is 'auth' or 'installer'. Renders the owner-configured illustration,
    or the appropriate existing bundled Vuexy illustration for that exact
    surface — never a broken reference (§6.4).
--}}
@php
    $branding = app(\App\Library\Branding\BrandingPresenter::class)
        ->illustration($surface, $dark ? 'dark' : 'light');
@endphp
<img src="{{ asset($branding['src']) }}" alt="{{ $branding['alt'] }}" {{ $attributes->merge(['class' => 'img-fluid']) }} />
