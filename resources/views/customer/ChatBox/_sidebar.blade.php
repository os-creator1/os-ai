<div class="sidebar-content">

    <span class="sidebar-close-icon">
        <i data-feather="x"></i>
    </span>

    <div class="text-center pt-1 pb-1">
        <div role="group" class="tab-group btn-group">
            <button type="button" class="btn tab-button btn-primary btn-sm"
                    data-filter="recents">{{ __('locale.labels.recents') }}</button>
            <button type="button" class="btn tab-button btn-outline-primary btn-sm"
                    data-filter="unread">{{ __('locale.labels.unread') }}</button>
            <button type="button" class="btn tab-button btn-outline-primary btn-sm"
                    data-filter="read">{{ __('locale.labels.read') }}</button>
            <button type="button" class="btn tab-button btn-outline-primary btn-sm"
                    data-filter="all">{{ __('locale.labels.all') }}</button>
        </div>
    </div>

    <!-- Sidebar header start -->
    <div class="chat-fixed-search">
        <div class="d-flex align-items-center w-100">
            <div class="input-group input-group-merge ms-1 w-100">
                <span class="input-group-text round"><i data-feather="search" class="text-muted"></i></span>
                <input type="text" class="form-control round" id="chat-search"
                       placeholder="{{ __('locale.labels.search') }}">
            </div>
            <div class="d-block d-md-none">
                <a href="{{ route('customer.chatbox.new') }}" class="text-dark ms-1"><i data-feather="plus-circle"></i>
                </a>
            </div>
        </div>
    </div>
    <!-- Sidebar header end -->

    <!-- Loader -->
    <div id="loader" class="text-center" style="display:none;">
        <div class="spinner-border text-primary" role="status">
            <span class="sr-only"></span>
        </div>
    </div>

    <!-- Sidebar Users start -->
    <div id="users-list" class="chat-user-list-wrapper list-group">

        @if($pinnedChats->count() > 0)
            <h4 class="chat-list-title">{{ __('locale.labels.pin') }}</h4>

            <ul class="chat-users-list-pinned chat-list media-list">
                @foreach($pinnedChats as $chat)
                    <li data-id="{{$chat->uid}}" data-box-id="{{$chat->id}}">
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
            </ul>

            <h4 class="chat-list-title">{{ __('locale.labels.chats') }}</h4>

        @endif

        <ul class="chat-users-list chat-list media-list">
            <!-- Chat users will be loaded here via Ajax -->
        </ul>
    </div>
    <!-- Sidebar Users end -->

    <!-- Load More button -->
    <div class="text-center" id="load-more-wrapper" style="display:none;">
        <button class="btn btn-sm btn-primary mt-1" id="load-more"><i data-feather="refresh-cw"></i></button>
    </div>

</div>
