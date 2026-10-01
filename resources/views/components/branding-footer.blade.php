{{--
    Design System M2 Platform Branding contract §6.5/§11 item 13. Replaces
    panels/footer.blade.php's raw {!! config('app.footer_text') !!}
    output with the composed copyright line — the year is computed at render
    time on every request, never stored, never owner-editable as a literal
    value.

    The holder is the active brand for this request: an authorized Agency
    white-label brand, else the platform owner's configured company
    (AuthBrandPresenter::footerCopyrightLine()). Nothing here names a brand.
--}}
@php
    // Agency V1 completion — a signed-in client of an authorized white-label
    // Agency reads the Agency as the copyright holder, with the Agency's
    // support address when it set one (ClientChromeBrand). Everyone else:
    // the host-resolved Agency brand, else the platform owner's footer.
    $agencyChrome = app(\App\Library\Branding\ClientChromeBrand::class)->current(request());
    $copyrightLine = $agencyChrome !== null
        ? \App\Library\Branding\BrandingPresenter::copyrightLine($agencyChrome['name'])
        : app(\App\Library\Branding\AuthBrandPresenter::class)->footerCopyrightLine(request());
@endphp
<span data-role="copyright-line">{{ $copyrightLine }}</span>
@if($agencyChrome !== null && $agencyChrome['support'] !== null)
    <span data-role="agency-support-contact"> · Need help? <a href="mailto:{{ $agencyChrome['support'] }}">{{ $agencyChrome['support'] }}</a></span>
@endif
