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
    $copyrightLine = app(\App\Library\Branding\AuthBrandPresenter::class)->footerCopyrightLine(request());
@endphp
<span data-role="copyright-line">{{ $copyrightLine }}</span>
