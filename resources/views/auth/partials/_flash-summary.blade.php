{{--
    Customer Experience Slice 2 (brief §4, §10) — the inline outcome
    summary of an authentication form. The auth controllers report a
    failed attempt, an expired link or a sent reset link as a flashed
    status/message pair (also shown as a toast by panels/scripts); this
    renders the same message in the document, next to the form, as an
    alert (error/warning) or status (success/info) region with a stable
    id the primary field can reference through aria-describedby.

    Include it once, directly above the form. Nothing here is rendered
    unescaped.
--}}
@php
    $authFlashStatus = session('status');
    $authFlashMessage = is_string($authFlashStatus) && is_string(session('message')) && trim(session('message')) !== ''
        ? trim(session('message'))
        : null;
    $authFlashVariant = match ($authFlashStatus) {
        'error' => 'danger',
        'warning' => 'warning',
        'success' => 'success',
        'info' => 'accent',
        default => null,
    };
@endphp
@if($authFlashMessage !== null && $authFlashVariant !== null)
    <x-alert :variant="$authFlashVariant" class="mb-1 alert-validation-msg" id="auth-flash" role="{{ in_array($authFlashStatus, ['error', 'warning'], true) ? 'alert' : 'status' }}" data-role="auth-flash">
        <div class="alert-body d-flex align-items-center">
            <x-ds-icon :name="in_array($authFlashStatus, ['error', 'warning'], true) ? 'info' : 'check-circle'" class="me-50" aria-hidden="true" />
            <span>{{ $authFlashMessage }}</span>
        </div>
    </x-alert>
@endif
