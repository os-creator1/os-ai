{{--
    Design System M2 Platform Branding contract §6.5/§11 item 13. Replaces
    panels/footer.blade.php's raw {!! config('app.footer_text') !!}
    output with the composed company-name + copyright-wording + automatic
    current-year line (§6.4) — the year is computed at render time on
    every request, never stored, never owner-editable as a literal value.
--}}
@php
    $copyrightLine = app(\App\Library\Branding\BrandingPresenter::class)->footerCopyrightLine();
@endphp
{{ $copyrightLine }}
