{{--
    Shared authenticated-app footer partial (vertical, horizontal and detached masters all include it).

    The visible copyright bar (company line, Terms / Privacy links) is intentionally NOT rendered anywhere in the authenticated app: it cost
    vertical space on every page. Layout space is reclaimed by forcing the `footer-hidden` body class in Helper::applClasses(), so there is no
    page-specific CSS. What this partial still provides, unchanged:
      - the owner-configured custom script (injected here since the original template), now emitted without a visible bar;
      - the scroll-to-top button (fixed position, shown by core/app.js on scroll).
    Public marketing pages, customer-generated websites, emails and legal documents do not use this partial and are unaffected;
    <x-branding-footer /> and the branding presenters remain available to them (and to the admin appearance preview).
--}}
@php
    $hasCustomScript = \App\Helpers\Helper::app_config('custom_script') ? \App\Helpers\Helper::app_config('custom_script') : null;
@endphp
<!-- BEGIN: Footer-->
@if($hasCustomScript !== null)
    {!! $hasCustomScript !!}
@endif
<button class="btn btn-primary btn-icon scroll-top transition-fast" type="button" aria-label="Back to top"><x-ds-icon name="arrow-up" /></button>
<!-- END: Footer-->
