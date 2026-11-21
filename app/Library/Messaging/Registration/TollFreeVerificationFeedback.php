<?php

namespace App\Library\Messaging\Registration;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Models\BusinessMessagingRegistration;

/**
 * What the customer is told about a toll-free verification, derived only from
 * the saved provider state and reason. A submitted (pending) request is never
 * presented as unsubmitted or rejected: a nonterminal provider state with a
 * reason is a correction request on an existing request, and nothing here ever
 * suggests creating a second one.
 *
 * Correction path: Telnyx documents a PATCH for amending a request, but which
 * states accept it (and which path) is not confirmable without live calls, and
 * the amendment would change claims the customer made (use case, consent).
 * So the supported route is support, with a prefilled message carrying the
 * Business, number and sanitized reason; the app never edits the provider
 * request itself.
 */
final class TollFreeVerificationFeedback
{
    private const MISSING_INFO = '/\b(?:missing|required|not provided|isv ?reseller|reseller|registration (?:country|number|type)|business (?:registration|address|name))\b/i';

    private const SUBMITTED = 'Your request has already been submitted, so please do not submit it again.';

    private const SUPPORT = 'Telnyx is holding your existing request, so nothing can be edited or resubmitted from here and no new request should be created. Contact support and we will correct this on your existing request.';

    /**
     * @return array{key: string, label: string, headline: string, variant: string, message: string, reason: ?string, action: ?string, show_details: bool, correction: bool, resolve_url: ?string}
     */
    public static function for(BusinessMessagingRegistration $registration, ?string $phoneNumber = null, ?string $businessUid = null): array
    {
        $status = $registration->status ?? MessagingRegistrationStatus::NotStarted;
        $provider = (string) $registration->provider_status;
        $reason = self::reasonOf($registration->provider_status_reason);

        if ($status === MessagingRegistrationStatus::Approved) {
            return self::make('verified', 'Verified', 'Verified', 'success', 'Your toll-free number is verified.', null, null);
        }

        if ($status === MessagingRegistrationStatus::Rejected) {
            $reason = self::reasonOf($registration->rejection_reason);

            return self::make('rejected', 'Needs attention', 'Verification rejected — needs correction', 'danger', $reason !== null
                ? 'Telnyx rejected your SMS verification.'
                : 'The carrier did not supply a detailed reason for this rejection. Please review the details below and try again.', $reason, 'review');
        }

        if ($status !== MessagingRegistrationStatus::Pending) {
            return self::make('not_submitted', 'Not submitted', 'Not submitted yet', '', '', null, null);
        }

        if ($reason !== null || $provider === 'waiting_for_customer') {
            $missing = $reason !== null && preg_match(self::MISSING_INFO, $reason) === 1;
            $lead = $reason === null
                ? 'Telnyx is waiting for information from you but did not say what.'
                : ($missing ? 'Your toll-free verification needs additional business information.' : 'Telnyx has requested a change to your SMS verification.');

            return self::make($missing ? 'needs_information' : 'change_requested', 'Correction requested', 'Verification submitted — correction requested', 'danger',
                $lead . ' ' . self::SUBMITTED . ' ' . self::SUPPORT, $reason, 'support',
                self::resolveUrl($registration, $phoneNumber, $businessUid, $provider, $reason));
        }

        return match ($provider) {
            'waiting_for_vendor' => self::make('carrier_review', 'Carrier review', 'Verification submitted — under carrier review', 'info',
                'Your verification has been submitted to the carriers and is being reviewed. No action is currently required and nothing needs to be submitted again.', null, null),
            default => self::make('under_review', 'Awaiting Telnyx', 'Verification submitted — awaiting Telnyx review', 'info',
                'Your verification has already been submitted and Telnyx is reviewing it. No action is required and there is no second submission to make.', null, null),
        };
    }

    private static function reasonOf(?string $stored): ?string
    {
        $clean = ProviderReasonSanitizer::clean($stored);

        return $clean === null ? null : ProviderReasonSanitizer::humanize($clean);
    }

    /** A prefilled support email: only the Business, number, state and the already-customer-visible reason. */
    private static function resolveUrl(BusinessMessagingRegistration $registration, ?string $phoneNumber, ?string $businessUid, string $provider, ?string $reason): string
    {
        $to = (string) config('marketing.links.support_email', 'support@getmotiongrove.com');
        $body = implode("\n", array_filter([
            'Please correct my existing toll-free verification. Do not create a new request.',
            '',
            'Business: ' . (string) $registration->legal_business_name,
            $businessUid !== null ? 'Business ID: ' . $businessUid : null,
            'Number: ' . ($phoneNumber ?? 'not available'),
            'Telnyx status: ' . ($provider !== '' ? str_replace('_', ' ', $provider) : 'pending'),
            'Telnyx reason: ' . ($reason ?? 'none given'),
        ], fn ($line) => $line !== null));

        return 'mailto:' . $to . '?subject=' . rawurlencode('Toll-free verification correction — ' . (string) $registration->legal_business_name) . '&body=' . rawurlencode($body);
    }

    private static function make(string $key, string $label, string $headline, string $variant, string $message, ?string $reason, ?string $action, ?string $resolveUrl = null): array
    {
        return [
            'key' => $key, 'label' => $label, 'headline' => $headline, 'variant' => $variant, 'message' => $message, 'reason' => $reason,
            'action' => $action, 'show_details' => in_array($key, ['under_review', 'carrier_review', 'change_requested', 'needs_information'], true),
            'correction' => $action === 'support', 'resolve_url' => $resolveUrl,
        ];
    }
}
