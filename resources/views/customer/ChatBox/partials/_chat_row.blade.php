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
<li data-id="{{$chat->uid}}" data-box-id="{{$chat->id}}" data-channel="sms" class="conv-row {{ $chat->pinned ? 'pinned-row' : '' }} {{ $chat->notification ? 'conv-unread' : '' }}">
    <span class="avatar bg-light-primary conversation-row-avatar" aria-hidden="true">
        {{ $rowInitials !== '' ? $rowInitials : mb_substr((string) $chat->to, -2) }}
    </span>
    <div class="chat-info flex-grow-1">
        <div class="conv-row-top">
            <h6 class="mb-0 text-truncate" data-role="conversation-row-title">{{ !empty($displayName) ? $displayName : $chat->to }}</h6>
            <small class="chat-time">{{ \App\Library\Tool::customerDateTime($activityAt ?? $chat->updated_at) }}</small>
        </div>
        <div class="conv-row-bottom">
            @if(! empty($emailPreview))
                <p class="card-text mb-0 text-truncate" data-role="conversation-row-preview">{{ str_limit($emailPreview, 60) }}</p>
            @elseif($chat->latestMessage !== null && ! empty($chat->latestMessage->message))
                <p class="card-text mb-0 text-truncate" data-role="conversation-row-preview">{{ str_limit($chat->latestMessage->message, 60) }}</p>
            @elseif(!empty($displayName))
                <p class="card-text mb-0 text-truncate text-numeric">{{ $chat->to }}</p>
            @else
                <p class="card-text mb-0 text-truncate">No messages yet</p>
            @endif
            <span class="conversation-channel-badge" data-role="conversation-row-channel">{{ ! empty($withEmail) ? 'SMS · Email' : 'SMS' }}</span>
            <div class="chat-meta text-nowrap">
                @if($chat->notification)
                    <span class="badge bg-primary rounded-pill float-end notification_count">{{ $chat->notification }}</span>
                @else
                    <div class="counter" hidden>
                        <span class="badge bg-primary rounded-pill float-end notification_count"></span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</li>
