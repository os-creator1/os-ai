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

    @php
        $scopeWaiting = request()->query('filter') === 'waiting';
        $scopeFrom = request()->query('created_from');
        $scopeTo = request()->query('created_to');
        $scopeDays = is_string($scopeFrom) && is_string($scopeTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $scopeFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $scopeTo)
            && checkdate((int) substr($scopeFrom, 5, 2), (int) substr($scopeFrom, 8, 2), (int) substr($scopeFrom, 0, 4))
            && checkdate((int) substr($scopeTo, 5, 2), (int) substr($scopeTo, 8, 2), (int) substr($scopeTo, 0, 4));
    @endphp
    @if($scopeWaiting || $scopeDays)
        <p class="px-1 py-50 mb-0 text-caption" data-role="home-scope">
            Showing {{ $scopeWaiting ? 'customers waiting for a reply' : 'conversations started ' . \Carbon\Carbon::parse($scopeFrom)->format('M j') . ' – ' . \Carbon\Carbon::parse($scopeTo)->format('M j') }}
            · <a href="{{ url()->current() }}">Show all</a>
        </p>
    @endif

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
