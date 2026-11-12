{{--
    One EMAIL conversation in the unified list: a thread that is not part of an
    SMS conversation (it has no matched Contact, or its Contact has no SMS
    thread). The person first — the Contact's name, otherwise the sender's
    address; never another Contact's. Opening it stays inside Conversations.
--}}
@php
    $emailTitle = ! empty($name) ? $name : $thread['counterpart'];
    $emailInitials = '';
    foreach (preg_split('/\s+/', trim((string) ($name ?? ''))) ?: [] as $part) {
        if ($part !== '' && mb_strlen($emailInitials) < 2) {
            $emailInitials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    $emailPreviewText = trim(preg_replace('/\s+/', ' ', ($thread['subject'] !== '' ? $thread['subject'] . ' — ' : '') . (string) $thread['latest']->body_text));
@endphp
<li data-id="email:{{ $thread['key'] }}" data-channel="email" data-email-thread="{{ $thread['key'] }}">
    <span class="avatar bg-light-primary conversation-row-avatar" aria-hidden="true">
        {{ $emailInitials !== '' ? $emailInitials : mb_strtoupper(mb_substr($thread['counterpart'], 0, 1)) }}
    </span>
    <div class="chat-info flex-grow-1">
        <h6 class="mb-0 text-truncate" data-role="conversation-row-title">{{ $emailTitle }}</h6>
        <span class="conversation-channel-badge conversation-channel-badge-email" data-role="conversation-row-channel">Email</span>
        @if(! empty($name))
            <p class="card-text mb-0 text-truncate">{{ $thread['counterpart'] }}</p>
        @endif
        <p class="card-text mb-0 text-truncate" data-role="conversation-row-preview">{{ str_limit($emailPreviewText, 60) }}</p>
    </div>
    <div class="chat-meta text-nowrap">
        <small class="float-end mb-25 chat-time">{{ \App\Library\Tool::customerDateTime($thread['at']) }}</small>
    </div>
</li>
