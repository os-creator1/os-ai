{{--
    Customer notification cleanup — the flashed status/message pair shown
    in the page, for a screen whose state the message explains (a failed
    Google connection, say). It is marked data-role="flash-message", so the
    same message is not also raised as a toast (App\Library\Feedback\PageToasts).

    A controller may flash a calm, descriptive heading as message_title.
    Nothing here is rendered unescaped.
--}}
@php
    $flashMessage = session('message');
    $flashMessage = is_string($flashMessage) ? trim($flashMessage) : '';
    $flashTitle = session('message_title');
    $flashTitle = is_string($flashTitle) ? trim($flashTitle) : '';
    [$flashVariant, $flashIcon, $flashRole] = match (session('status')) {
        'error' => ['danger', 'alert-circle', 'alert'],
        'warning' => ['warning', 'alert-triangle', 'alert'],
        'info' => ['accent', 'info', 'status'],
        default => ['success', 'check-circle', 'status'],
    };
@endphp
@if ($flashMessage !== '')
    <x-alert :variant="$flashVariant" :icon="$flashIcon" role="{{ $flashRole }}" data-role="flash-message" {{ $attributes }}>
        @if ($flashTitle !== '')
            <strong class="d-block">{{ $flashTitle }}</strong>
        @endif
        {{ $flashMessage }}
    </x-alert>
@endif
