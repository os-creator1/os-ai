{{--
    Customer notification cleanup — the flashed status/message pair shown
    in the page, for a screen whose state the message explains (a failed
    Google connection, say). It is marked data-role="flash-message", so the
    same message is not also raised as a toast (App\Library\Feedback\PageToasts).

    Renders the shared <x-alert> notification card. A controller may flash a
    calm, descriptive heading as message_title; without one, the message
    itself is the card's title. Nothing here is rendered unescaped.
--}}
@php
    $flashMessage = session('message');
    $flashMessage = is_string($flashMessage) ? trim($flashMessage) : '';
    $flashTitle = session('message_title');
    $flashTitle = is_string($flashTitle) ? trim($flashTitle) : '';
    [$flashVariant, $flashRole] = match (session('status')) {
        'error' => ['danger', 'alert'],
        'warning' => ['warning', 'alert'],
        'info' => ['accent', 'status'],
        default => ['success', 'status'],
    };
@endphp
@if ($flashMessage !== '')
    @if ($flashTitle !== '')
        <x-alert :variant="$flashVariant" :dismissible="true" :title="$flashTitle" role="{{ $flashRole }}" data-role="flash-message" {{ $attributes }}>{{ $flashMessage }}</x-alert>
    @else
        <x-alert :variant="$flashVariant" :dismissible="true" :title="$flashMessage" role="{{ $flashRole }}" data-role="flash-message" {{ $attributes }} />
    @endif
@endif
