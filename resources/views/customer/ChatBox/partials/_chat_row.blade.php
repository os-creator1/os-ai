{{--
    One conversation in the list: the person first — their name when exactly one
    of this Business's contacts has the number, otherwise the number — then the
    latest message and when the conversation last moved. Shared by the pinned
    rail and the loaded list so both read the same.
--}}
@php
    $rowInitials = '';
    if (!empty($displayName)) {
        foreach (preg_split('/\s+/', trim($displayName)) as $part) {
            if ($part !== '') {
                $rowInitials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
            if (mb_strlen($rowInitials) >= 2) {
                break;
            }
        }
    }
@endphp
<li data-id="{{$chat->uid}}" data-box-id="{{$chat->id}}" class="{{ $chat->pinned ? 'pinned-row' : '' }}">
    <span class="avatar bg-light-primary conversation-row-avatar" aria-hidden="true">
        {{ $rowInitials !== '' ? $rowInitials : mb_substr((string) $chat->to, -2) }}
    </span>
    <div class="chat-info flex-grow-1">
        <h6 class="mb-0 text-truncate" data-role="conversation-row-title">{{ !empty($displayName) ? $displayName : $chat->to }}</h6>
        @if(!empty($displayName))
            <p class="card-text mb-0 text-truncate text-numeric">{{ $chat->to }}</p>
        @endif

        @if($chat->latestMessage !== null && ! empty($chat->latestMessage->message))
            <p class="card-text mb-0 text-truncate" data-role="conversation-row-preview">
                {{ str_limit($chat->latestMessage->message, 60) }}
            </p>
        @endif
    </div>
    <div class="chat-meta text-nowrap">
        <small class="float-end mb-25 chat-time">{{ \App\Library\Tool::customerDateTime($chat->updated_at) }}</small>
        @if($chat->notification)
            <span class="badge bg-primary rounded-pill float-end notification_count">{{ $chat->notification }}</span>
        @else
            <div class="counter" hidden>
                <span class="badge bg-primary rounded-pill float-end notification_count"></span>
            </div>
        @endif
    </div>
</li>
