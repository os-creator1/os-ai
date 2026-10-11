{{--
    The top bar's account button and menu. One shared component for every
    portal and layout that renders panels.navbar: Platform Owner, admin
    staff, Workspace and Business owners, team members, Agency accounts and
    white-labelled client accounts all get this same control.

    Everything in it is the signed-in person's own: their name, initials or
    photo, and only the destinations they may open.

     - Profile: user.account, hidden while a parent account is acting as one
       of its team members (that person's profile is theirs, not the parent's).
     - Settings: $settingsUrl, resolved by AccountMenuSettingsLink from the
       entry this actor's own sidebar already shows. Null means no Settings
       page for them, so no link.
     - Product updates: customers only (read-only, their own updates).
     - Switch view: dual-role accounts only, as before.
     - Sign out: a real POST to the logout route carrying the CSRF token.

    The trigger is a <button> driving Bootstrap's own dropdown, so outside
    click, Escape, arrow-key movement between items and focus return come
    from the one dropdown implementation the rest of the shell uses.
--}}
@props(['user', 'settingsUrl' => null])

@php
    $displayName = trim($user->displayName()) !== '' ? trim($user->displayName()) : config('app.name');
    $isParentImpersonation = session()->has('parent_user_id') && session()->has('temp_user_id');
    $isCustomerPortal = $user->active_portal === 'customer' && (int) $user->is_customer === 1;
    $switchToCustomer = $user->active_portal === 'admin' && (int) $user->is_customer === 1;
    $switchToAdmin = $user->active_portal === 'customer' && (int) $user->is_admin === 1;
@endphp

<li class="nav-item dropdown dropdown-user mg-topbar-item mg-account" data-role="topbar-account">
    <button type="button" class="mg-topbar-btn mg-account-trigger dropdown-user-link" id="dropdown-user"
            data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="{{ $displayName }}">
        <x-user-avatar :user="$user" />
        <span class="mg-account-name" data-role="topbar-account-name">{{ $displayName }}</span>
        <x-ds-icon name="chevron-down" size="14" class="mg-account-chevron" aria-hidden="true" />
    </button>

    <div class="dropdown-menu dropdown-menu-end mg-account-menu" aria-labelledby="dropdown-user">
        <div class="mg-account-menu-identity" data-role="account-menu-identity">
            <x-user-avatar :user="$user" />
            <span class="mg-account-menu-name">{{ $displayName }}</span>
        </div>
        <div class="dropdown-divider"></div>

        @if(! $isParentImpersonation)
            <a class="dropdown-item" href="{{ route('user.account') }}" data-role="user-menu-profile">
                <x-ds-icon name="user" size="16" aria-hidden="true" />
                <span>{{ __('locale.labels.profile') }}</span>
            </a>
        @endif

        @if($settingsUrl)
            <a class="dropdown-item" href="{{ $settingsUrl }}" data-role="user-menu-settings">
                <x-ds-icon name="settings" size="16" aria-hidden="true" />
                <span>{{ __('locale.menu.Settings') }}</span>
            </a>
        @endif

        @if(! $isParentImpersonation && $isCustomerPortal)
            {{-- The person's own menu: who they are, what is new in the
                 product, and signing out. Plan & subscription and Team are
                 account and Business settings, reached through Settings —
                 one destination each, never repeated here. --}}
            <a class="dropdown-item" href="{{ route('user.account.announcement') }}" data-role="user-menu-product-updates">
                <x-ds-icon name="megaphone" size="16" aria-hidden="true" />
                <span>{{ __('locale.menu.Product updates') }}</span>
            </a>
        @endif

        @if($switchToCustomer || $switchToAdmin)
            <a class="dropdown-item" href="{{ route('user.switch_view', ['portal' => $switchToCustomer ? 'customer' : 'admin']) }}" data-role="user-menu-switch-view">
                <x-ds-icon name="repeat" size="16" aria-hidden="true" />
                <span>{{ __('locale.labels.switch_view') }}</span>
            </a>
        @endif

        <div class="dropdown-divider"></div>

        <form id="logout-form" class="mg-account-signout-form" action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit" class="dropdown-item mg-account-signout" data-role="user-menu-sign-out">
                <x-ds-icon name="log-out" size="16" aria-hidden="true" />
                <span>{{ __('locale.menu.Logout') }}</span>
            </button>
        </form>
    </div>
</li>
