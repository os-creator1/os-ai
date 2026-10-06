@forelse($chat_box as $chat)
    @include('customer.ChatBox.partials._chat_row', ['chat' => $chat, 'displayName' => $displayNames[$chat->id] ?? null])
@empty
    {{-- Only the FIRST page says "nothing here": an empty later page is just the end of the list. --}}
    @if(! empty($showEmptyState))
        <div class="text-center text-muted p-2" data-role="chat-list-empty">
            @if(($emptyReason ?? null) === 'search')
                {{ __('locale.conversations.list_empty_search') }}
            @elseif(($emptyReason ?? null) === 'filtered')
                {{ __('locale.conversations.list_empty_filtered') }}
            @else
                {{ __('locale.conversations.list_empty') }}
            @endif
        </div>
    @endif
@endforelse
