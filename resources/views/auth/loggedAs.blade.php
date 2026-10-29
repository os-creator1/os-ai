{{--
    Compact "viewing as" banner. Rendered only while an admin (Platform Owner)
    or parent-account impersonation session is active; the exit is the existing
    secure logout route. The viewed name is the live session user, never fixed.
--}}
@php
   $isAdminImpersonation = session()->has('admin_user_id') && session()->has('temp_user_id');
   $isParentImpersonation = session()->has('parent_user_id') && session()->has('temp_user_id');
@endphp

@if ($isAdminImpersonation || $isParentImpersonation)
   <style>
/* Compact "viewing as" banner shown to a Platform Owner / parent account */
.va-bar { display: flex; align-items: center; gap: var(--space-3); min-height: 36px; margin: 0 0 var(--space-3); padding: 4px var(--space-3); background: #2b2a28; color: #f3f0ea; border: 1px solid #44413d; border-radius: var(--radius-md); font-size: .75rem; line-height: 1.3; }
.va-bar > .ds-icon { flex-shrink: 0; opacity: .8; }
.va-bar__text { flex: 1; min-width: 0; }
.va-bar__text strong { color: #fff; font-weight: 600; }
.va-bar a.va-bar__exit { flex-shrink: 0; padding: 3px 10px; border-radius: var(--radius-sm, 6px); background: #fff; color: #2b2a28; font-weight: 600; font-size: .75rem; text-decoration: none; white-space: nowrap; }
.va-bar a.va-bar__exit:hover, .va-bar a.va-bar__exit:focus-visible { background: #f3f0ea; color: #000; }
.va-bar a.va-bar__exit:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
@media (max-width: 575.98px) { .va-bar { flex-wrap: wrap; padding-block: 6px; } .va-bar__text { flex-basis: calc(100% - 32px); } }
   </style>
   <div class="va-bar" role="status" data-role="impersonation-banner">
      <x-ds-icon name="eye" size="14" aria-hidden="true" />
      <span class="va-bar__text">You're viewing {{ config('app.name', 'MotionGrove') }} as <strong data-role="impersonation-name">{{ auth()->user()->displayName() }}</strong>. Changes you make may apply to their account.</span>
      <a class="va-bar__exit" href="{{ route('logout') }}" data-role="impersonation-exit">Return to {{ $isAdminImpersonation ? 'Platform Owner' : (session('parent_user_name') ?: 'parent account') }}</a>
   </div>
@endif
