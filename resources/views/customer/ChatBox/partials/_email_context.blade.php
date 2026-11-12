{{--
    Conversations — the contact panel for an EMAIL thread that is not part of an SMS conversation. Deliberately limited: the
    sender's address, and (only when the email ledger itself linked the thread to a Contact) that Contact's name and a link.
    An unmatched sender is never given another Contact's details.
--}}
<div class="conversation-context-inner" data-role="contact-panel" data-context="email">
    <div class="conversation-context-person">
        <span class="avatar bg-light-primary conversation-context-avatar" aria-hidden="true">
            <span class="avatar-content"><x-ds-icon name="mail" /></span>
        </span>
        <h6 class="mb-0 conversation-context-name">{{ $name ?? $counterpart }}</h6>
        @if ($name !== null)
            <p class="text-caption mb-0">{{ $counterpart }}</p>
        @endif
    </div>
    <dl class="conversation-context-details">
        <dt>Channel</dt>
        <dd>Email</dd>
        @if ($subject !== '')
            <dt>Subject</dt>
            <dd>{{ $subject }}</dd>
        @endif
    </dl>
    @if ($matched && $profileUrl !== null)
        <a class="btn btn-sm btn-outline-primary" href="{{ $profileUrl }}" data-role="contact-profile-link">View contact</a>
    @elseif (! $matched)
        <p class="text-caption text-muted mb-0" data-role="email-unmatched-note">This sender isn't linked to a contact yet, so no contact details are shown.</p>
    @endif
</div>
