{{--
    One conversation in the list: the person first — their name when exactly one
    of this Business's contacts has the number, otherwise the number — then the
    latest message and when the conversation last moved. Shared by the pinned
    rail and the loaded list so both read the same.
--}}
<li data-id="{{$chat->uid}}" data-box-id="{{$chat->id}}">
    <span class="avatar">
        <img src="{{asset('images/profile/profile.jpg')}}" height="36" width="54" alt="Avatar"/>
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
