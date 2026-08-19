@php
   $isAdminImpersonation = session()->has('admin_user_id') && session()->has('temp_user_id');
   $isParentImpersonation = session()->has('parent_user_id') && session()->has('temp_user_id');
@endphp

@if ($isAdminImpersonation || $isParentImpersonation)
   <x-alert variant="success">
      <h4 class="alert-heading">{{ __('locale.labels.attention') }}</h4>
      <div class="alert-body">
         {!! __('locale.labels.login_as', [
             'name'  => auth()->user()->displayName(),
             'route' => route('logout'),
             'admin' => $isAdminImpersonation ? session('admin_user_name') : session('parent_user_name')
         ]) !!}
      </div>
   </x-alert>
@endif
