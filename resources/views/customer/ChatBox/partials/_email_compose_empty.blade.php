{{-- Conversations → Email: a new outgoing email to a Contact that has no email history yet. --}}
<div class="mgx-email-empty" data-role="email-compose-empty">
    <span class="mgx-email-empty-icon" aria-hidden="true"><x-ds-icon name="mail" size="22" /></span>
    <strong>New email to {{ $label }}</strong>
    <p>{{ $email !== null ? 'It will be sent to ' . $email . ' from your connected business mailbox.' : 'Add an email address to this contact to send them an email.' }}</p>
</div>
