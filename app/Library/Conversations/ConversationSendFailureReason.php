<?php

namespace App\Library\Conversations;

use App\Enums\Messaging\ProviderErrorCategory;

/**
 * Conversations — the closed set of CUSTOMER-SAFE reasons a manual send's
 * bubble can show, and nothing else.
 *
 * Never a provider payload, a provider message id, a raw exception message,
 * or an internal wallet/implementation name (item 3). Every case here is a
 * category a customer can act on or simply understand, mapped from whatever
 * this platform genuinely knows failed — never invented detail the failure
 * did not actually carry.
 */
enum ConversationSendFailureReason: string
{
    /** The legacy per-account balance check refused the send before any provider call. */
    case InsufficientBalance = 'insufficient_balance';

    /** No usable managed identity/number for this Business, or an unusable destination — zero provider calls. */
    case MessagingNotReady = 'messaging_not_ready';

    /** The managed messaging platform itself is off, unconfigured, or the provider declined for a reason this platform cannot make more specific. */
    case MessagingUnavailable = 'messaging_unavailable';

    /** A provider accepted the message and a later delivery-status callback reported failure. */
    case DeliveryFailed = 'delivery_failed';

    /** Every other refusal before provider commitment (coverage, blacklist, malformed destination, spam filter, …) — generic on purpose: nothing more specific is known. */
    case SendFailed = 'send_failed';

    /**
     * Correction round 4, item 1 — the provider's own outcome is genuinely
     * UNCONFIRMED: a transport exception/timeout (ProviderErrorCategory::Retryable)
     * or a 2xx response this platform could not correlate to a provider
     * message id (ProviderErrorCategory::Unknown). The provider MAY already
     * have accepted the message, so this is never treated as an ordinary
     * failure — see isRetryable() and bubbleStatus().
     */
    case Ambiguous = 'ambiguous';

    public function customerMessage(): string
    {
        return match ($this) {
            self::InsufficientBalance => __('locale.conversations.send_failure.insufficient_balance'),
            self::MessagingNotReady => __('locale.conversations.send_failure.messaging_not_ready'),
            self::MessagingUnavailable => __('locale.conversations.send_failure.messaging_unavailable'),
            self::DeliveryFailed => __('locale.conversations.send_failure.delivery_failed'),
            self::SendFailed => __('locale.conversations.send_failure.send_failed'),
            self::Ambiguous => __('locale.conversations.send_failure.ambiguous'),
        };
    }

    /**
     * Every reason but one reaches a bubble in a state that by construction
     * means the send either never reached the provider or is a later
     * delivery-status outcome — both always safe to offer a deliberate new
     * attempt for. Ambiguous is the deliberate exception (correction round
     * 4, item 1): the provider's own acceptance was never conclusively
     * disproven, so a Retry could mint a second, genuinely new send for a
     * message that already went out — silently risking a duplicate, and for
     * a paid send, a double charge. A reason this enum cannot express (a
     * local bookkeeping failure after acceptance) never produces a failed
     * bubble at all, so Retry is never offered for it either.
     */
    public function isRetryable(): bool
    {
        return $this !== self::Ambiguous;
    }

    /**
     * The `chat_box_messages.send_status` a failure recorded under this
     * reason belongs in. Ambiguous is its own bubble state — never 'failed'
     * — precisely so a Retry control is never rendered for it (see
     * isRetryable()) and the retry endpoint's own claim transaction, which
     * only accepts a row already in 'failed'/'delivery_failed', refuses it
     * structurally even if posted to directly.
     */
    public function bubbleStatus(): string
    {
        return $this === self::Ambiguous ? 'ambiguous' : 'failed';
    }

    /**
     * From the dispatcher's own coarse provider-failure classification
     * (Slice 3 §4.3) — never from a provider-specific string.
     *
     * Configuration (the kill switch, missing credentials, or an
     * authentication rejection — never reaching the provider, or
     * conclusively refused by it) and Terminal (the provider's own explicit
     * rejection) are both CONCLUSIVE: the message definitely did not go
     * out, so a Retry is always safe. Retryable and Unknown are NOT
     * conclusive (correction round 4, item 1) — the provider may already
     * have accepted the message — and map to Ambiguous instead, which is
     * never retryable.
     */
    public static function fromProviderErrorCategory(?ProviderErrorCategory $category): self
    {
        return match ($category) {
            ProviderErrorCategory::Configuration => self::MessagingUnavailable,
            ProviderErrorCategory::Retryable, ProviderErrorCategory::Unknown => self::Ambiguous,
            ProviderErrorCategory::Terminal, null => self::SendFailed,
        };
    }
}
