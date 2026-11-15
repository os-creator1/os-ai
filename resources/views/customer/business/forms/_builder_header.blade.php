{{-- Forms shell shared by every form tab — Settings, Notifications, Submissions,
     Analytics and the visual Edit tab: Back, form name + Live/Draft badge, Preview,
     Add to website, and the tab bar (design: FormSettings.dc.html header).
     On Edit/Settings the name is editable and Preview / Add to website / save state
     are live (public/js/forms/form-builder.js hooks: #fb-name, #fb-save,
     #fb-preview-btn, #fb-integrate-btn, [data-fb-tab]); on the other tabs the same
     shell links back to the builder.
     $tab: edit | settings | submissions | notifications | analytics --}}
@php
    $scope = [$workspace->uid, $business->uid];
    $formScope = array_merge($scope, [$form->uid]);
    $isBuilder = in_array($tab, ['edit', 'settings'], true);
    $editUrl = route('customer.workspaces.businesses.forms.edit', $formScope);
    $live = $form->isActive();
    $badge = $live ? 'Live' : $form->lifecycle_state->label();
@endphp
<div class="mgf-head" data-role="forms-builder-top">
    <a class="mgf-back" href="{{ route('customer.workspaces.businesses.forms.index', $scope) }}" data-role="forms-back">@include('customer.business.forms._icon', ['n' => 'back', 'style' => 'width:14px;height:14px']) Forms</a>
    <div class="mgf-titlerow">
        @if ($isBuilder)
            <input type="text" class="mgf-title" id="fb-name" value="{{ $form->name }}" maxlength="120" aria-label="Form name" data-role="forms-name">
        @else
            <h1 class="mgf-title" data-role="forms-title">{{ $form->name }}</h1>
        @endif
        <span class="mgf-badge {{ $live ? 'is-live' : '' }}" data-role="forms-state">{{ $badge }}</span>
        <div class="mgf-actions">
            @if ($isBuilder)
                <span class="mgf-save" id="fb-save" data-state="saved" role="status" aria-live="polite" data-role="forms-save-state">Saved</span>
                <button type="button" class="mgf-btn is-outline" id="fb-preview-btn" data-role="forms-preview">@include('customer.business.forms._icon', ['n' => 'eye']) Preview</button>
                <button type="button" class="mgf-btn is-primary" id="fb-integrate-btn" data-role="forms-integrate">@include('customer.business.forms._icon', ['n' => 'code']) Add to website</button>
            @else
                <a class="mgf-btn is-outline" href="{{ $editUrl }}" data-role="forms-preview">@include('customer.business.forms._icon', ['n' => 'eye']) Preview</a>
                <a class="mgf-btn is-primary" href="{{ $editUrl }}#integrate" data-role="forms-integrate">@include('customer.business.forms._icon', ['n' => 'code']) Add to website</a>
            @endif
        </div>
    </div>
</div>
<nav class="mgf-tabs" aria-label="Form sections" data-role="forms-tabs">
    @if ($isBuilder)
        <button type="button" class="mgf-tab {{ $tab === 'edit' ? 'is-active' : '' }}" data-fb-tab="edit">Edit</button>
        <button type="button" class="mgf-tab {{ $tab === 'settings' ? 'is-active' : '' }}" data-fb-tab="settings">Settings</button>
    @else
        <a class="mgf-tab" href="{{ $editUrl }}">Edit</a>
        <a class="mgf-tab" href="{{ $editUrl }}#settings">Settings</a>
    @endif
    <a class="mgf-tab {{ $tab === 'submissions' ? 'is-active' : '' }}" href="{{ route('customer.workspaces.businesses.forms.submissions.index', $scope) }}?form={{ $form->uid }}" data-role="forms-tab-submissions" @if($tab === 'submissions') aria-current="page" @endif>Submissions</a>
    <a class="mgf-tab {{ $tab === 'notifications' ? 'is-active' : '' }}" href="{{ route('customer.workspaces.businesses.forms.notifications', $formScope) }}" data-role="forms-tab-notifications" @if($tab === 'notifications') aria-current="page" @endif>Notifications</a>
    <a class="mgf-tab {{ $tab === 'analytics' ? 'is-active' : '' }}" href="{{ route('customer.workspaces.businesses.forms.analytics', $formScope) }}" data-role="forms-tab-analytics" @if($tab === 'analytics') aria-current="page" @endif>Analytics</a>
</nav>
