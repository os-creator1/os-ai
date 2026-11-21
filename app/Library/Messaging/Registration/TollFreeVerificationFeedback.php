<?php

namespace App\Library\Messaging\Registration;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Models\BusinessMessagingRegistration;

/**
 * What the customer is told about a toll-free verification, derived only from
 * the saved provider state and reason. A nonterminal provider state (Waiting
 * For Telnyx / Customer / Vendor) with a reason is a warning, never a
 * rejection; a state with no reason is never shown as a problem.
 */
final class TollFreeVerificationFeedback
{
    private const MISSING_INFO = '/\b(?:missing|required|not provided|isv ?reseller|reseller|registration (?:country|number|type)|business (?:registration|address|name))\b/i';

    private const SUPPORT = 'Telnyx is holding your existing request, so nothing can be edited or resubmitted from here and no new request should be created. Contact support and we will correct this on your existing request.';

    /**
     * @return array{key: string, label: string, variant: string, message: string, reason: ?string, action: ?string, show_details: bool}
     */
    public static function for(BusinessMessagingRegistration $registration): array
    {
        $status = $registration->status ?? MessagingRegistrationStatus::NotStarted;
        $provider = (string) $registration->provider_status;
        $reason = self::reasonOf($registration->provider_status_reason);

        if ($status === MessagingRegistrationStatus::Approved) {
            return self::make('verified', 'Verified', 'success', 'Your toll-free number is verified.', null, null);
        }

        if ($status === MessagingRegistrationStatus::Rejected) {
            $reason = self::reasonOf($registration->rejection_reason);

            return self::make('rejected', 'Needs attention', 'danger', $reason !== null
                ? 'Telnyx rejected your SMS verification.'
                : 'The carrier did not supply a detailed reason for this rejection. Please review the details below and try again.', $reason, 'review');
        }

        if ($status !== MessagingRegistrationStatus::Pending) {
            return self::make('not_submitted', 'Not started', '', '', null, null);
        }

        if ($reason !== null) {
            $missing = preg_match(self::MISSING_INFO, $reason) === 1;

            return self::make($missing ? 'needs_information' : 'change_requested', 'Needs attention', 'danger', ($missing
                ? 'Your toll-free verification needs additional business information.'
                : 'Telnyx has requested a change to your SMS verification.') . ' ' . self::SUPPORT, $reason, 'support');
        }

        return match ($provider) {
            'waiting_for_vendor' => self::make('carrier_review', 'Under carrier review', 'info', 'Your verification has been submitted to the carriers. No action is currently required.', null, null),
            'waiting_for_customer' => self::make('needs_information', 'Needs attention', 'danger', 'Telnyx is waiting for information from you but did not say what. ' . self::SUPPORT, null, 'support'),
            default => self::make('under_review', 'Under review', 'info', 'Telnyx is reviewing your verification request.', null, null),
        };
    }

    private static function reasonOf(?string $stored): ?string
    {
        $clean = ProviderReasonSanitizer::clean($stored);

        return $clean === null ? null : ProviderReasonSanitizer::humanize($clean);
    }

    private static function make(string $key, string $label, string $variant, string $message, ?string $reason, ?string $action): array
    {
        return ['key' => $key, 'label' => $label, 'variant' => $variant, 'message' => $message, 'reason' => $reason, 'action' => $action, 'show_details' => $action === 'support'];
    }
}
