@forelse($items as $item)
    @if($item['type'] === 'email')
        @include('customer.ChatBox.partials._email_row', ['thread' => $item['thread'], 'name' => $item['name']])
    @else
        @include('customer.ChatBox.partials._chat_row', ['chat' => $item['chat'], 'displayName' => $displayNames[$item['chat']->id] ?? null, 'withEmail' => $item['withEmail'] ?? false, 'emailPreview' => $item['emailPreview'] ?? null, 'activityAt' => $item['at']])
    @endif
@empty
    {{-- Only the FIRST page says "nothing here": an empty later page is just the end of the list. --}}
    @if(! empty($showEmptyState))
        <div class="text-center text-muted p-2" data-role="chat-list-empty" data-channel="{{ $channel ?? 'all' }}">
            @if(($emptyReason ?? null) === 'search')
                {{ __('locale.conversations.list_empty_search') }}
            @elseif(($emptyReason ?? null) === 'filtered')
                {{ __('locale.conversations.list_empty_filtered') }}
            @elseif(($channel ?? 'all') === 'email')
                @if(! empty($emailConnected))
                    No email conversations yet. New email to your connected mailbox will appear here.
                @else
                    Email isn't connected yet. Connect your mailbox in Settings → Email to read and reply to email here.
                    @if(! empty($emailSettingsUrl))<a href="{{ $emailSettingsUrl }}">Connect email</a>@endif
                @endif
            @elseif(($channel ?? 'all') === 'sms')
                No text conversations yet.
            @else
                {{ __('locale.conversations.list_empty') }}
            @endif
        </div>
    @endif
@endforelse
