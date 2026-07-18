@foreach($chat_box as $chat)
   <li data-id="{{$chat->id}}" data-box-id="{{$chat->id}}">
        <span class="avatar">
            <img src="{{asset('images/profile/profile.jpg')}}" height="36" width="54" alt="Avatar"/>
        </span>
        <div class="chat-info flex-grow-1">
            <h6 class="mb-0">{{ $chat->to }}</h6>
            @if(!empty($chat->contact) && !empty($chat->contact->getFullName()))
                <p class="card-text mb-0 text-truncate">
                    {{ str_limit($chat->contact->getFullName(), 15) }}
                </p>
            @endif
            <p class="card-text mb-0 text-truncate">
                {{ $chat->from }}
            </p>

            @if(! empty($chat->chatBoxMessages) && ! empty($chat->chatBoxMessages->last()->message))
                <p class="card-text mb-0 text-truncate">
                    {{ str_limit($chat->chatBoxMessages->last()->message, 18) }}
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
@endforeach
