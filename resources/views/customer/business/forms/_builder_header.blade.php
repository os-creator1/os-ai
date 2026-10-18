{{-- Forms visual builder — the shell shared by every tab: Back, form name, Preview,
     Integrate, save state, and the tab bar. On the Edit tab the name is editable and
     the Preview / Integrate / save-state controls are live; on the read-only tabs
     (Submissions, Notifications, Analytics) the same shell links back to them.
     $tab: edit | settings | submissions | notifications | analytics --}}
@php
    $scope = [$workspace->uid, $business->uid];
    $formScope = array_merge($scope, [$form->uid]);
    $isBuilder = in_array($tab, ['edit', 'settings'], true);
    $editUrl = route('customer.workspaces.businesses.forms.edit', $formScope);
@endphp
<div class="fb-top" data-role="forms-builder-top">
    <a class="fb-back" href="{{ route('customer.workspaces.businesses.forms.index', $scope) }}" data-role="forms-back">&larr; Forms</a>
    @if ($isBuilder)
        <input type="text" class="fb-name" id="fb-name" value="{{ $form->name }}" maxlength="120" aria-label="Form name" data-role="forms-name">
    @else
        <span class="fb-title-static">{{ $form->name }}</span>
    @endif
    <span class="fb-badge {{ $form->isActive() ? 'is-active' : '' }}" data-role="forms-state">{{ $form->lifecycle_state->label() }}</span>
    <div class="fb-top-actions">
        @if ($isBuilder)
            <span class="fb-save" id="fb-save" data-state="saved" role="status" aria-live="polite" data-role="forms-save-state">Saved</span>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="fb-preview-btn" data-role="forms-preview">Preview</button>
            <button type="button" class="btn btn-primary btn-sm" id="fb-integrate-btn" data-role="forms-integrate">Integrate</button>
        @else
            <a class="btn btn-outline-secondary btn-sm" href="{{ $editUrl }}">Preview</a>
            <a class="btn btn-primary btn-sm" href="{{ $editUrl }}#integrate" data-role="forms-integrate">Integrate</a>
        @endif
    </div>
</div>
<nav class="fb-tabs" aria-label="Form sections" data-role="forms-tabs">
    @if ($isBuilder)
        <button type="button" class="fb-tab {{ $tab === 'edit' ? 'is-active' : '' }}" data-fb-tab="edit">Edit</button>
        <button type="button" class="fb-tab {{ $tab === 'settings' ? 'is-active' : '' }}" data-fb-tab="settings">Settings</button>
    @else
        <a class="fb-tab" href="{{ $editUrl }}">Edit</a>
        <a class="fb-tab" href="{{ $editUrl }}#settings">Settings</a>
    @endif
    <a class="fb-tab {{ $tab === 'submissions' ? 'is-active' : '' }}" href="{{ route('customer.workspaces.businesses.forms.submissions.index', $scope) }}?form={{ $form->uid }}" data-role="forms-tab-submissions">Submissions</a>
    <a class="fb-tab {{ $tab === 'notifications' ? 'is-active' : '' }}" href="{{ route('customer.workspaces.businesses.forms.notifications', $formScope) }}" data-role="forms-tab-notifications">Notifications</a>
    <a class="fb-tab {{ $tab === 'analytics' ? 'is-active' : '' }}" href="{{ route('customer.workspaces.businesses.forms.analytics', $formScope) }}" data-role="forms-tab-analytics">Analytics</a>
</nav>
