<?php

namespace App\DTO\Calendar;

use Carbon\CarbonInterface;

/**
 * Implementation Contract 15 §5.5/§11, review correction — the result of
 * registering a provider push-notification channel (Google `events.watch`)
 * or change-notification subscription (Microsoft Graph `POST /subscriptions`).
 *
 * `registrationId` is Google's `resourceId` or Microsoft's subscription
 * `id` — provider-assigned, required to renew or unregister. `channelId`
 * is Google-only (the caller-chosen, per-registration channel id Google
 * echoes back); always null for Outlook. `expiresAt` is the ACTUAL
 * expiration the provider granted, which may be shorter than what was
 * requested.
 */
final class ExternalCalendarNotificationRegistration
{
    public function __construct(
        public readonly string $registrationId,
        public readonly ?string $channelId,
        public readonly CarbonInterface $expiresAt,
    ) {
    }
}
