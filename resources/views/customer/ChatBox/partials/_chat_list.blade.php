@foreach($chat_box as $chat)
    @include('customer.ChatBox.partials._chat_row', ['chat' => $chat, 'displayName' => $displayNames[$chat->id] ?? null])
@endforeach
