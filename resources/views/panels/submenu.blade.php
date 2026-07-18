{{-- For submenu --}}
<ul class="menu-content">
    @if(isset($menu))
        @foreach($menu as $submenu)
            @php
                $submenuTranslation = "";
                // FIX: Check $submenu->i18n instead of $menu->i18n (which is the parent array)
                if (isset($submenu->i18n)) {
                    $submenuTranslation = $submenu->i18n;
                }
                // FIX: Explode permission string
                $permission = explode('|', $submenu->access);
            @endphp

            {{-- FIX: Use @canany to handle pipe-separated permissions --}}
            @canany($permission, auth()->user())
                <li class="{{ isset($submenu->slug) && str_contains(request()->path(),$submenu->slug) ? 'active' : '' }}">
                    <a href="{{isset($submenu->url) ? url($submenu->url):'javascript:void(0)'}}" class="d-flex align-items-center">
                        @if(isset($submenu->icon))
                            <i data-feather="{{ $submenu->icon ?? "" }}"></i>
                        @endif
                        {{-- Use corrected $submenuTranslation --}}
                        <span class="menu-item text-truncate" data-i18n="{{ $submenuTranslation }}">{{ __('locale.menu.'.$submenu->name) }}</span>
                    </a>
                    @if (isset($submenu->submenu))
                        @include('panels/submenu', ['menu' => $submenu->submenu])
                    @endif
                </li>
            @endcanany
        @endforeach
    @endif
</ul>
