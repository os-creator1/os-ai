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
@endphp
<li class="conv-row email-row" data-id="email:{{ $thread['key'] }}" data-channel="email" data-email-thread="{{ $thread['key'] }}" title="{{ $thread['counterpart'] }}">
    <span class="avatar bg-light-primary conversation-row-avatar email-row-avatar" aria-hidden="true">
        {{ $emailInitials !== '' ? $emailInitials : mb_strtoupper(mb_substr($thread['counterpart'], 0, 1)) }}
    </span>
    <div class="email-row-main">
        <div class="email-row-top">
            <h6 class="mb-0 text-truncate email-row-sender" data-role="conversation-row-title">{{ $emailTitle }}</h6>
            <small class="chat-time email-row-time">{{ \App\Library\Tool::customerDateTime($thread['at']) }}</small>
        </div>
        <div class="email-row-subject text-truncate">
            {{ $thread['subject'] !== '' ? $thread['subject'] : '(no subject)' }}
            @if($thread['count'] > 1)<span class="email-row-count" title="{{ $thread['count'] }} messages">{{ $thread['count'] }}</span>@endif
        </div>
        <p class="email-row-preview text-truncate mb-0" data-role="conversation-row-preview">{{ str_limit(trim(preg_replace('/\s+/', ' ', (string) $thread['latest']->body_text)), 90) }}</p>
        <span class="conversation-channel-badge conversation-channel-badge-email visually-hidden" data-role="conversation-row-channel">Email</span>
    </div>
</li>
