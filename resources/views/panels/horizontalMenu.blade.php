@php
    $configData = \App\Helpers\Helper::applClasses();
@endphp
@if(isset($customerMenuItems))
    {{--
        Customer Experience Slice 1A (contract §6a #13): recursive dropdown
        rendering for a nested customer MenuItem group. This file includes
        itself with `customerMenuItems` set whenever a customer menu entry
        isGroup(), so arbitrary-depth CustomerMenuBuilder trees (e.g.
        Settings -> Advanced -> Messaging provider) render correctly without
        a second file — mirroring horizontalSubmenu.blade.php's role for the
        admin branch below, but sourced from CustomerMenuBuilder, never from
        the legacy Helper::menuData()['customer'] array.
    --}}
    <ul class="dropdown-menu" data-bs-popper="none">
        @foreach($customerMenuItems as $item)
            @php
                $itemLabel = \Illuminate\Support\Facades\Lang::has('locale.menu.' . $item->label) ? __('locale.menu.' . $item->label) : $item->label;
            @endphp
            <li class="{{ $item->isGroup() ? 'dropdown dropdown-submenu' : '' }} {{ ($item->active || $item->hasActiveChild()) ? 'active' : '' }}"
                data-nav-key="{{ $item->key }}"
                @if($item->isGroup()) data-menu="dropdown-submenu" @endif>
                <a href="{{ $item->url ?? 'javascript:void(0)' }}"
                   class="dropdown-item {{ $item->isGroup() ? 'dropdown-toggle' : '' }} d-flex align-items-center transition-fast"
                   @if($item->isGroup()) data-bs-toggle="dropdown" @endif>
                    <x-ds-icon name="{{ $item->icon }}" aria-hidden="true" />
                    <span>{{ $itemLabel }}</span>
                </a>
                @if($item->isGroup())
                    @include('panels/horizontalMenu', ['customerMenuItems' => $item->children])
                @endif
            </li>
        @endforeach
    </ul>
@else
{{-- Horizontal Menu --}}
<div class="horizontal-menu-wrapper">
    <div class="header-navbar navbar-expand-sm navbar navbar-horizontal transition-base
  {{$configData['horizontalMenuClass']}}
    {{($configData['theme'] === 'dark') ? 'navbar-dark' : 'navbar-light' }}
            navbar-shadow menu-border
{{ ($configData['layoutWidth'] === 'boxed' && $configData['horizontalMenuType']  === 'navbar-floating') ? 'container-xxl' : '' }}"
         role="navigation"
         data-menu="menu-wrapper"
         data-menu-type="floating-nav">
        <div class="navbar-header">
            <ul class="nav navbar-nav flex-row">
                <li class="nav-item me-auto">
                    <a class="navbar-brand" href="{{route('login')}}">
                        <span class="brand-logo"><x-branding-logo variant="full" background="light" /></span>
                    </a>
                </li>
                <li class="nav-item nav-toggle">
                    <a class="nav-link modern-nav-toggle pe-0" data-bs-toggle="collapse">
                        <x-ds-icon name="x" class="d-block d-xl-none text-primary toggle-icon font-medium-4" />
                    </a>
                </li>
            </ul>
        </div>
        <div class="shadow-bottom"></div>
        <!-- Horizontal menu content-->
        <div class="navbar-container main-menu-content" data-menu="menu-container">
            <ul class="nav navbar-nav" id="main-menu-navigation" data-menu="menu-navigation">
                {{-- Foreach menu item starts --}}
                @php
                    $customerShell = isset($customerContext) && $customerContext instanceof \App\Library\Navigation\CustomerContext;
                @endphp
                @if($customerShell)
                    {{--
                        Customer Experience Slice 1A (contract §6a #13): the
                        customer branch now consumes the same
                        CustomerShellComposer + CustomerMenuBuilder tree the
                        vertical shell uses, never the legacy
                        Helper::menuData()['customer'] array.
                    --}}
                    @foreach($customerMenu as $item)
                        @php
                            $itemLabel = \Illuminate\Support\Facades\Lang::has('locale.menu.' . $item->label) ? __('locale.menu.' . $item->label) : $item->label;
                        @endphp
                        <li class="nav-item {{ $item->isGroup() ? 'dropdown' : '' }} {{ ($item->active || $item->hasActiveChild()) ? 'active' : '' }}"
                            data-nav-key="{{ $item->key }}"
                            @if($item->isGroup()) data-menu="dropdown" @endif>
                            <a href="{{ $item->url ?? 'javascript:void(0)' }}"
                               class="nav-link d-flex align-items-center {{ $item->isGroup() ? 'dropdown-toggle' : '' }}"
                               @if($item->isGroup()) data-bs-toggle="dropdown" @endif>
                                <x-ds-icon name="{{ $item->icon }}" aria-hidden="true" />
                                <span>{{ $itemLabel }}</span>
                            </a>
                            @if($item->isGroup())
                                @include('panels/horizontalMenu', ['customerMenuItems' => $item->children])
                            @endif
                        </li>
                    @endforeach
                @elseif(isset($menuData[1]))


                    @php
                        $sidebarMenu = $menuData[1]->admin;
                    @endphp

                    @foreach($sidebarMenu as $menu)


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


                            <li class="nav-item @if(isset($menu->submenu)){{'dropdown'}}@endif {{ $custom_classes }} {{ isset($menu->slug) &&  str_contains(request()->path(),$menu->slug) ? 'active' : '' }}"
                            @if(isset($menu->submenu)){{'data-menu=dropdown'}}@endif>
                                <a href="{{isset($menu->url)? url($menu->url):'javascript:void(0)'}}" class="nav-link d-flex align-items-center @if(isset($menu->submenu)){{'dropdown-toggle'}}@endif" target="{{isset($menu->newTab) ? '_blank':'_self'}}"  @if(isset($menu->submenu)){{'data-bs-toggle=dropdown'}}@endif>
                                    <x-ds-icon name="{{ $menu->icon }}" />
                                    <span data-i18n="{{ $translation }}">{{ __('locale.menu.'.$menu->name) }}</span>
                                </a>
                                @if(isset($menu->submenu))
                                    @include('panels/horizontalSubmenu', ['menu' => $menu->submenu])
                                @endif
                            </li>
                        @endcanany



                    @endforeach

                @endif
                {{-- Foreach menu item ends --}}
            </ul>
        </div>
    </div>
</div>
@endif
