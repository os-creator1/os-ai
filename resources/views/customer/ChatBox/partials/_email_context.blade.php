{{--
    Conversations — the contact panel for an EMAIL thread (approved design): avatar, identity, status, Call / Open contact,
    then Phone / Email / Group / Added for a contact the email ledger linked to the thread. An unmatched sender is identified
    by its address only, offered "Add to contacts", and is never given another contact's details.
--}}
@php
    $profile = $profile ?? null;
    $words = preg_split('/\s+/u', trim((string) ($name ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $ctxInitials = $words !== [] ? mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2)))) : mb_strtoupper(mb_substr((string) $counterpart, 0, 1));
@endphp
<div class="conversation-context-inner mgx-ctx" data-role="contact-panel" data-context="email" data-header-initials="{{ $ctxInitials }}" data-header-subtitle="{{ ($count ?? 0) > 0 ? ($name ?? $counterpart) . ' · ' . $count . (($count ?? 0) === 1 ? ' email' : ' emails') : $counterpart }}">
    <div class="conversation-context-person">
        <span class="mgx-ctx-av" aria-hidden="true">{{ $ctxInitials }}</span>
        <h6 class="mgx-ctx-name" data-role="contact-panel-name">
            @if ($matched && $profileUrl !== null)
                <a href="{{ $profileUrl }}" class="conversation-context-profile-link" data-role="contact-panel-name-link" title="View contact details">{{ $name ?? $counterpart }}</a>
            @else
                {{ $name ?? $counterpart }}
            @endif
        </h6>
        @if ($matched && $profile !== null)
            <span class="mgx-ctx-badge {{ $profile['subscribed'] ? '' : 'is-warn' }}" data-role="contact-panel-consent">{{ $profile['subscribed'] ? 'Subscribed to texts' : 'Unsubscribed from texts' }}</span>
        @elseif (! $matched)
            <span class="mgx-ctx-badge is-neutral">Not linked to a contact</span>
        @endif
    </div>
    @if (! empty($call) || ($matched && $profileUrl !== null) || ($addContactUrl ?? null) !== null)
        <div class="mgx-ctx-actions">
            @if (! empty($call))
                <button type="button" class="mgx-ctx-btn" data-role="conversation-call" data-call-uid="{{ $call['uid'] }}" data-call-label="{{ $call['label'] }}" data-call-phone="{{ $call['phone'] }}" data-call-initials="{{ $call['initials'] }}" aria-label="Call {{ $call['label'] }}" hidden>
                    <x-ds-icon name="phone" size="16" aria-hidden="true" />Call
                </button>
            @endif
            @if ($matched && $profileUrl !== null)
                <a class="mgx-ctx-btn is-brand" href="{{ $profileUrl }}" data-role="contact-profile-link">Open contact</a>
            @elseif (($addContactUrl ?? null) !== null)
                <a class="mgx-ctx-btn is-brand" href="{{ $addContactUrl }}" data-role="contact-panel-add">Add to contacts</a>
            @endif
        </div>
    @endif
    <dl class="mgx-kv" data-role="contact-panel-identity">
        @if ($matched && $profile !== null)
            @if (($profile['phone'] ?? '') !== '')<dt>Phone</dt><dd>{{ \App\Library\Contacts\ContactPhone::pretty($profile['phone']) }}</dd>@endif
            <dt>Email</dt><dd>{{ $profile['email'] ?? $counterpart }}</dd>
            @if (($profile['group']['name'] ?? null) !== null)<dt>Group</dt><dd class="is-brand">{{ $profile['group']['name'] }}</dd>@endif
            @if ($profile['added'] !== null)<dt>Added</dt><dd>{{ $profile['added']->format('M j, Y') }}</dd>@endif
        @else
            <dt>Email</dt><dd>{{ $counterpart }}</dd>
        @endif
        <dt>Channel</dt><dd>Email</dd>
        @if ($subject !== '')<dt>Subject</dt><dd>{{ $subject }}</dd>@endif
    </dl>
    @if (! $matched)
        <p class="mgx-ctx-note" data-role="email-unmatched-note">This sender isn't linked to a contact yet, so no contact details are shown.</p>
    @endif
</div>
