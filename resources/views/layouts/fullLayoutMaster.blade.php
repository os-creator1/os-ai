@isset($pageConfigs)
{!! \App\Helpers\Helper::updatePageConfig($pageConfigs) !!}
@endisset

        <!DOCTYPE html>
@php $configData = \App\Helpers\Helper::applClasses(); @endphp
{{--
    Customer Experience Slice 2 (contract §9.1, brief §4/§6): the guest
    layout carries the identity the visitor is signing in to — the
    authorized Agency brand for this host, the owner's platform name, or
    the neutral AI Business OS name — in the document title, so a
    white-labelled screen never leaks the platform's own title. It also
    supplies the one landmark and the accessible-state script for the
    password visibility toggles rendered by resources/views/auth/**.
--}}
@inject('authBranding', 'App\Library\Branding\AuthBrandPresenter')
@php $authBrand = $authBranding->for(request()); @endphp

<html class="loading {{($configData['theme'] === 'light') ? '' : $configData['layoutTheme'] }}"
      lang="@if(Session::has('locale')){{Session::get('locale')}}@else{{config('app.locale')}}@endif"
      data-textdirection="{{ config('app.locale_direction') === 'rtl' ? 'rtl' : 'ltr' }}"
      @if($configData['theme'] === 'dark') data-layout="dark-layout"@endif>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta name="keywords" content="{{config('app.keyword')}}" />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title') · {{ $authBrand->displayName }}</title>
    <x-branding-favicon />
    {{-- Design System Contract, Milestone 1, §9 item 38 — Geist Sans is
    self-hosted (resources/scss/base/tokens/_typography.scss), compiled
    into core.css below; the Montserrat Google Fonts link is removed. --}}

    {{-- Include core + vendor Styles --}}
    @include('panels/styles')

</head>


<body class="vertical-layout vertical-menu-modern {{ $configData['bodyClass'] }} {{($configData['theme'] === 'dark') ? 'dark-layout' : ''}} {{ $configData['blankPageClass'] }} blank-page"
      data-menu="vertical-menu-modern"
      data-col="blank-page"
      data-framework="ultimatesms"
      data-asset-path="{{ asset('/')}}">

<!-- BEGIN: Content-->
<div class="app-content content {{ $configData['pageClass'] }}">
    <div class="content-overlay"></div>
    <div class="header-navbar-shadow"></div>

    <div class="content-wrapper">
        <main class="content-body" id="main-content">

            {{-- Include Startkit Content --}}
            @yield('content')

        </main>
    </div>
</div>
<!-- End: Content-->

{{-- include default scripts --}}
@include('panels/scripts')

@stack('scripts')
<script type="text/javascript">
    $(window).on('load', function() {
        if (feather) {
            feather.replace({
                width: 14,
                height: 14
            });
        }
    })

    // Customer Experience Slice 2 (brief §4/§10): the password visibility
    // controls are real buttons (keyboard-operable by construction). The
    // core script flips the input type; this keeps the button's accessible
    // name and pressed state in step with what the field currently shows.
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-role="password-toggle"]');
        if (!toggle) {
            return;
        }
        var group = toggle.closest('.form-password-toggle');
        var input = group ? group.querySelector('input') : null;
        if (!input) {
            return;
        }
        var visible = input.getAttribute('type') === 'text';
        toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
        toggle.setAttribute('aria-label', visible ? toggle.getAttribute('data-label-hide') : toggle.getAttribute('data-label-show'));
    });
</script>

</body>

</html>
