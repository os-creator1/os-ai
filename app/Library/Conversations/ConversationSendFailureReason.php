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

    public function customerMessage(): string
    {
        return match ($this) {
            self::InsufficientBalance => __('locale.conversations.send_failure.insufficient_balance'),
            self::MessagingNotReady => __('locale.conversations.send_failure.messaging_not_ready'),
            self::MessagingUnavailable => __('locale.conversations.send_failure.messaging_unavailable'),
            self::DeliveryFailed => __('locale.conversations.send_failure.delivery_failed'),
            self::SendFailed => __('locale.conversations.send_failure.send_failed'),
        };
    }

    /**
     * Every reason this enum can carry only ever reaches a bubble in a state
     * (`failed` or `delivery_failed`) that by construction means the send
     * either never reached the provider or is a later delivery-status
     * outcome — both are always safe to offer a deliberate new attempt for.
     * There is deliberately no per-reason exception: a reason this enum
     * cannot express (a local bookkeeping failure after acceptance) never
     * produces a failed bubble at all, so Retry is never offered for it.
     */
    public function isRetryable(): bool
    {
        return true;
    }

    /**
     * From the dispatcher's own coarse provider-failure classification
     * (Slice 3 §4.3) — never from a provider-specific string.
     */
    public static function fromProviderErrorCategory(?ProviderErrorCategory $category): self
    {
        return match ($category) {
            ProviderErrorCategory::Configuration, ProviderErrorCategory::Retryable => self::MessagingUnavailable,
            ProviderErrorCategory::Terminal, ProviderErrorCategory::Unknown, null => self::SendFailed,
        };
    }
}
