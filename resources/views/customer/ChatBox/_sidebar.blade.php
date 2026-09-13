<div class="sidebar-content">

    <span class="sidebar-close-icon">
        <x-ds-icon name="x" />
    </span>

    <div class="text-center pt-1 pb-1">
        <div role="group" class="tab-group btn-group">
            <x-button variant="primary" size="sm" class="tab-button" data-filter="recents">{{ __('locale.labels.recents') }}</x-button>
            <x-button variant="outline" size="sm" class="tab-button" data-filter="unread">{{ __('locale.labels.unread') }}</x-button>
            <x-button variant="outline" size="sm" class="tab-button" data-filter="read">{{ __('locale.labels.read') }}</x-button>
            <x-button variant="outline" size="sm" class="tab-button" data-filter="all">{{ __('locale.labels.all') }}</x-button>
        </div>
    </div>

    <!-- Sidebar header start -->
    <div class="chat-fixed-search">
        <div class="d-flex align-items-center w-100">
            {{-- One control, one border, one focus ring (x-search-field). Stays a
                 text input: the inbox filters on keyup, and a search input's
                 built-in clear button would not fire it. --}}
            <x-search-field id="chat-search" type="text" class="ms-1 w-100"
                            :label="__('locale.labels.search')"
                            :placeholder="__('locale.labels.search')" />
            <div class="d-block d-md-none">
                <a href="{{ route('customer.workspaces.businesses.conversations.new', [$workspaceUid, $businessUid]) }}" class="text-dark ms-1"><x-ds-icon name="plus-circle" />
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
                    @include('customer.ChatBox.partials._chat_row', ['chat' => $chat, 'displayName' => $displayNames[$chat->id] ?? null])
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
        <x-button variant="primary" size="sm" id="load-more" class="mt-1" icon="refresh-cw" />
    </div>

</div>
