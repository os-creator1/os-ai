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

                // Phone Numbers + A2P lane — 'admin_only' is an additive,
                // opt-in second boundary on top of the 'access' gate above.
                // The 'access' gate alone (AccountRepository::hasPermission())
                // only checks a permission-string collection, never account
                // type, so it cannot by itself guarantee the same boundary as
                // a route gated by EnsureUserIsAdministrator (users.is_admin).
                // Absent for every pre-existing item, so this changes nothing
                // for them.
                $passesAdminOnlyBoundary = empty($submenu->admin_only)
                    || (auth()->check() && (bool) (auth()->user()->is_admin ?? false));
            @endphp

            {{-- FIX: Use @canany to handle pipe-separated permissions --}}
            @if ($passesAdminOnlyBoundary)
            @canany($permission, auth()->user())
                <li class="{{ isset($submenu->slug) && str_contains(request()->path(),$submenu->slug) ? 'active' : '' }}">
                    <a href="{{isset($submenu->url) ? url($submenu->url):'javascript:void(0)'}}" class="d-flex align-items-center transition-fast">
                        @if(isset($submenu->icon))
                            <x-ds-icon name="{{ $submenu->icon ?? "" }}" />
                        @endif
                        {{-- Use corrected $submenuTranslation --}}
                        <span class="menu-item text-truncate" data-i18n="{{ $submenuTranslation }}">{{ __('locale.menu.'.$submenu->name) }}</span>
                    </a>
                    @if (isset($submenu->submenu))
                        @include('panels/submenu', ['menu' => $submenu->submenu])
                    @endif
                </li>
            @endcanany
            @endif
        @endforeach
    @endif
</ul>
