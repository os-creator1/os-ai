<?php

namespace App\Enums\BusinessEmail;

/**
 * Provider-neutral failure taxonomy. No provider payload, error string or
 * HTTP body is ever exposed through this enum: raw diagnostics live only in
 * the operator-facing `failure_provider_code` column and the log.
 *
 * Retryable = worth another bounded attempt with the same operation key.
 * Everything else is terminal for that logical send.
 */
enum BusinessEmailFailureCategory: string
{
    /** No active connected account for the Business (or it was disconnected). */
    case DisconnectedAccount = 'disconnected_account';
    /** The provider rejected our credential; the Business must reconnect. */
    case AuthenticationExpired = 'authentication_expired';
    case RecipientInvalid = 'recipient_invalid';
    /** The grant lacks the permission/scope to send. */
    case PermissionDenied = 'permission_denied';
    case ProviderRateLimited = 'provider_rate_limited';
    case TemporaryProviderFailure = 'temporary_provider_failure';
    case PermanentProviderFailure = 'permanent_provider_failure';
    /** The local per-Business / per-Contact abuse bound was reached. */
    case SendLimitExceeded = 'send_limit_exceeded';
    /** The Contact is not a Contact of this Business (or does not exist). */
    case ContactUnavailable = 'contact_unavailable';
    /** Subject/body/operation key failed validation. */
    case MessageInvalid = 'message_invalid';
    /** No Location could be proven; the caller must choose one. */
    case LocationRequired = 'location_required';
    /** The operation key was already used for a materially different request. */
    case IdempotencyConflict = 'idempotency_conflict';

    public function isRetryable(): bool
    {
        return in_array($this, [self::ProviderRateLimited, self::TemporaryProviderFailure], true);
    }

    /** A plain sentence safe to show a customer. */
    public function customerMessage(): string
    {
        return match ($this) {
            self::DisconnectedAccount => 'Connect an email account before sending email.',
            self::AuthenticationExpired => 'Your email connection expired. Reconnect your email account and try again.',
            self::RecipientInvalid => 'This contact does not have a single valid email address.',
            self::PermissionDenied => 'Your email account did not allow sending. Reconnect it and approve sending.',
            self::ProviderRateLimited => 'Your email provider is limiting sends right now. Try again shortly.',
            self::TemporaryProviderFailure => 'Your email provider had a temporary problem. Try again shortly.',
            self::PermanentProviderFailure => 'Your email provider could not send this message.',
            self::SendLimitExceeded => 'Too many emails were sent recently. Try again later.',
            self::ContactUnavailable => 'That contact could not be found.',
            self::MessageInvalid => 'The email needs a subject and a message within the allowed length.',
            self::LocationRequired => 'Choose which location this email is sent from.',
            self::IdempotencyConflict => 'This email was already requested with different content.',
        };
    }
}
