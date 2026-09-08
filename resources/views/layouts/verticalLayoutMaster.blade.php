<body class="vertical-layout vertical-menu-modern {{ $configData['verticalMenuNavbarType'] }} {{ $configData['blankPageClass'] }} {{ $configData['bodyClass'] }} {{ $configData['sidebarClass']}} {{ $configData['footerType'] }} {{$configData['contentLayout']}}"
      data-open="click"
      data-menu="vertical-menu-modern"
      data-col="{{$configData['showMenu'] ? $configData['contentLayout'] : '1-column' }}"
      data-framework="ultimatesms"
      data-asset-path="{{ asset('/')}}">
{{-- Customer Experience Slice 2 (brief §6/§10): a keyboard user can skip the header and sidebar straight to the page content. --}}
<a class="visually-hidden-focusable skip-to-content" href="#main-content">{{ __('locale.labels.skip_to_main_content') }}</a>
<!-- BEGIN: Header-->
@include('panels.navbar')
<!-- END: Header-->

<!-- BEGIN: Main Menu-->
@if((isset($configData['showMenu']) && $configData['showMenu'] === true))
    @include('panels.sidebar')
@endif
<!-- END: Main Menu-->

<!-- BEGIN: Content-->
<div class="app-content content {{ $configData['pageClass'] }}">
    <!-- BEGIN: Header-->
    <div class="content-overlay"></div>
    <div class="header-navbar-shadow"></div>

    @if(($configData['contentLayout']!=='default') && isset($configData['contentLayout']))
        <div class="content-area-wrapper {{ $configData['layoutWidth'] === 'boxed' ? 'container-xxl p-0' : '' }}">
            <div class="{{ $configData['sidebarPositionClass'] }}">
                <div class="sidebar">
                    {{-- Include Sidebar Content --}}
                    @yield('content-sidebar')
                </div>
            </div>
            <div class="{{ $configData['contentsidebarClass'] }}">
                <div class="content-wrapper">
                    <main class="content-body" id="main-content">
                        {{-- Include Page Content --}}
                        @yield('content')
                    </main>
                </div>
            </div>
        </div>
    @else
        <div class="content-wrapper {{ $configData['layoutWidth'] === 'boxed' ? 'container-xxl p-0' : '' }}">
            {{-- Include Breadcrumb --}}
            @if($configData['pageHeader'] === true)
                @include('panels.breadcrumb')
            @endif

            <main class="content-body" id="main-content">
                {{-- Include Page Content --}}
                @yield('content')
            </main>
        </div>
    @endif

</div>
<!-- End: Content-->

<div class="sidenav-overlay"></div>
<div class="drag-target"></div>

{{-- include footer --}}
@include('panels/footer')

{{-- include default scripts --}}
@include('panels/scripts')

<script type="text/javascript">
    $(window).on('load', function () {
        if (feather) {
            feather.replace({
                width: 14, height: 14
            });
        }
    })
</script>
</body>
</html>
