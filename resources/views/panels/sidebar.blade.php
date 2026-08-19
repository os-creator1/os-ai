@php
    $configData = \App\Helpers\Helper::applClasses();
@endphp
<div class="main-menu menu-fixed {{(($configData['theme'] === 'dark') || ($configData['theme'] === 'semi-dark')) ? 'menu-dark' : 'menu-light'}} menu-accordion menu-shadow"
     data-scroll-to-active="true">
    <div class="navbar-header">
        <ul class="nav navbar-nav flex-row">

            @if(Auth::user()->active_portal == 'customer' && Auth::user()->is_customer == 1 && Auth::user()->customer->activeSubscription())
                <li class="nav-item me-auto">
                    <a class="navbar-brand" href="{{route('user.home')}}">
                        <div class="brand-logo">
                            <x-branding-logo variant="full" background="light" />
                        </div>
                    </a>
                </li>

            @else
                <li class="nav-item me-auto">
                    <a class="navbar-brand" href="{{route('admin.home')}}">
                        <div class="brand-logo">
                            <x-branding-logo variant="full" background="light" />
                        </div>
                    </a>
                </li>
            @endif

            <li class="nav-item nav-toggle">
                <a class="nav-link modern-nav-toggle pe-0 transition-fast" data-toggle="collapse">
                    <x-ds-icon name="x" class="d-block d-xl-none text-primary toggle-icon font-medium-4" />
                    <x-ds-icon name="disc" class="d-none d-xl-block collapse-toggle-icon font-medium-4 text-primary"
                       data-ticon="disc" />
                </a>
            </li>
        </ul>
    </div>

    <div class="shadow-bottom"></div>

    <div class="main-menu-content">
        <ul class="navigation navigation-main" id="main-menu-navigation" data-menu="menu-navigation">
            {{-- Foreach menu item starts --}}
            @if(isset($menuData[0]))
                @php
                    if (auth()->user()->active_portal == 'admin'){
                        $sidebarMenu = $menuData['0']->admin;
                     }else{
                        $sidebarMenu = $menuData['0']->customer;
                     }
                @endphp

                @foreach($sidebarMenu as $menu)
                    @if(isset($menu->navheader))
                        <li class="navigation-header">
                            <span>{{ $menu->navheader }}</span>
                            <x-ds-icon name="more-horizontal" />

                            @if (isset($menu->badge))
                                    <?php $badgeClasses = 'badge rounded-pill badge-light-primary ms-auto me-1'; ?>
                                <span class="{{ isset($menu->badgeClass) ? $menu->badgeClass : $badgeClasses }}">{{ __('locale.labels.'.$menu->badge) }}</span>
                            @endif
                        </li>
                    @else
                        {{-- Add Custom Class with nav-item --}}
                        @php
                            $custom_classes = "";
                            if(isset($menu->classlist)) {
                            $custom_classes = $menu->classlist;
                            }
                            $translation = "";
                            if(isset($menu->i18n)){
                            $translation = $menu->i18n;
                            }
                            $permission = explode('|', $menu->access);
                        @endphp
                        @canany($permission, auth()->user())

                            <li class="nav-item {{ isset($menu->slug) &&  str_contains(request()->path(),$menu->slug) ? 'active' : '' }} {{ $custom_classes }}">
                                <a href="{{ $menu->url }}" class="d-flex align-items-center">
                                    <x-ds-icon name="{{ $menu->icon }}" />
                                    <span class="menu-title text-truncate"
                                          data-i18n="{{ $translation }}">{{ __('locale.menu.'.$menu->name) }}</span>
                                    @if (isset($menu->badge))
                                            <?php $badgeClasses = 'badge rounded-pill badge-light-primary ms-auto me-1'; ?>
                                        <span
                                                class="{{ isset($menu->badgeClass) ? $menu->badgeClass : $badgeClasses }}">{{ __('locale.labels.'.$menu->badge) }}</span>
                                    @endif
                                </a>
                                @if(isset($menu->submenu))
                                    @include('panels/submenu', ['menu' => $menu->submenu])
                                @endif
                            </li>
                        @endcanany
                    @endif
                @endforeach
            @endif
            {{-- Foreach menu item ends --}}
        </ul>
    </div>
</div>
<!-- END: Main Menu-->
