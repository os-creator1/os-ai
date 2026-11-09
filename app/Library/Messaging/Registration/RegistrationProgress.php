<?php

namespace App\Library\Messaging\Registration;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Models\BusinessMessagingRegistration;

/**
 * The two persistent stages the customer sees for local (10DLC) texting,
 * derived only from what is actually saved: provider ids, the brand's recorded
 * carrier verification, the campaign status and its last check times. Nothing
 * here asks the carrier anything and nothing is inferred from an HTTP 200.
 */
final class RegistrationProgress
{
    /**
     * @return array{
     *   brand: array{key: string, label: string, detail: string},
     *   campaign: array{key: string, label: string, detail: string},
     *   committed: bool, percent: int, show_form: bool, campaign_ready: bool,
     *   last_checked_at: ?\Carbon\CarbonInterface, tcr_brand_id: ?string, campaign_issue: ?string,
     * }
     */
    public static function for(BusinessMessagingRegistration $registration): array
    {
        $status = $registration->status ?? MessagingRegistrationStatus::NotStarted;
        $hasBrand = trim((string) $registration->provider_brand_id) !== '';
        $hasCampaign = trim((string) $registration->provider_campaign_id) !== '';
        $submitted = in_array($status, [MessagingRegistrationStatus::Pending, MessagingRegistrationStatus::Approved], true);
        $brandStatus = $registration->brand_status;

        // A campaign can only exist for a brand the carrier accepted.
        $brandKey = match (true) {
            $brandStatus === 'verified' => 'verified',
            $brandStatus === 'needs_attention' => 'needs_attention',
            $brandStatus === 'overdue' && $hasBrand => 'overdue',
            $hasBrand && ($hasCampaign || $submitted) && $brandStatus === null => 'unconfirmed',
            $hasBrand => 'processing',
            default => 'not_started',
        };

        $brand = match ($brandKey) {
            'verified' => ['key' => 'verified', 'label' => 'Business verified', 'detail' => 'The carrier has verified your business.'],
            'needs_attention' => ['key' => 'needs_attention', 'label' => 'Needs attention', 'detail' => $registration->brand_status_detail ?: 'The carrier could not finish verifying this business. Your details are saved; contact support to get this resolved.'],
            'unconfirmed' => ['key' => 'unconfirmed', 'label' => 'Verification being confirmed', 'detail' => 'Your campaign was submitted earlier. We are confirming your business\'s verification with the carrier; your campaign, brand and fees are unchanged.'],
            'overdue' => ['key' => 'overdue', 'label' => 'Verification taking longer than expected', 'detail' => $registration->brand_status_detail ?: 'The carrier has not rejected your business, but verification is taking longer than expected. Your brand is saved and nothing needs to be resubmitted.'],
            'processing' => ['key' => 'processing', 'label' => 'Business verification in progress', 'detail' => 'Your business was sent to the carrier. Verification usually takes a short while and sometimes longer. We check automatically; nothing is wrong and you do not need to resubmit.'],
            default => ['key' => 'not_started', 'label' => 'Not started', 'detail' => 'Submit your business details to start carrier verification.'],
        };

        // A sole-proprietor brand needs a carrier PIN text this platform cannot
        // complete: say so instead of implying more waiting will finish it.
        if (in_array($brandKey, ['processing', 'overdue', 'unconfirmed'], true) && $registration->entity_type?->value === 'sole_proprietor') {
            $brand['detail'] = 'Sole-proprietor brands need a one-time PIN text from the carrier to the contact phone, which can\'t be completed in MotionGrove yet, so this verification may not finish on its own. Contact support to resolve it.';
        }

        $issue = $registration->campaign_issue;

        $campaignKey = match (true) {
            $status === MessagingRegistrationStatus::Approved => 'approved',
            $status === MessagingRegistrationStatus::Rejected => 'needs_attention',
            $status === MessagingRegistrationStatus::Pending || $hasCampaign => 'under_review',
            $brandKey === 'verified' && $issue !== null && $issue !== '' => 'issue',
            $brandKey === 'verified' => 'ready',
            default => 'not_started',
        };

        $campaign = match ($campaignKey) {
            'approved' => ['key' => 'approved', 'label' => 'Approved', 'detail' => 'The carrier approved your campaign.'],
            'needs_attention' => ['key' => 'needs_attention', 'label' => 'Needs correction', 'detail' => $registration->rejection_reason ?: 'The carrier did not approve this campaign and did not give a detailed reason. Review your campaign details and submit again.'],
            'under_review' => ['key' => 'under_review', 'label' => 'Campaign submitted — awaiting carrier review', 'detail' => 'The carrier is reviewing your campaign. This is typically a few business days; there is nothing else to do right now.'],
            'issue' => ['key' => 'issue', 'label' => 'Submission issue', 'detail' => (string) $issue],
            'ready' => ['key' => 'ready', 'label' => 'Ready to submit', 'detail' => 'Your business is verified. Review your campaign details and submit when you are ready.'],
            default => ['key' => 'not_started', 'label' => 'Not started', 'detail' => 'Unlocks once your business is verified.'],
        };

        $percent = match (true) {
            $campaignKey === 'approved' => 100,
            $campaignKey === 'under_review' => 75,
            $campaignKey === 'needs_attention' || $campaignKey === 'issue' || $campaignKey === 'ready' => 50,
            in_array($brandKey, ['processing', 'overdue', 'needs_attention'], true) => 25,
            default => 0,
        };

        // The form is editable only where an edit is meaningful: before the
        // brand exists, once it is verified (campaign details), or after a
        // campaign rejection.
        $showForm = $status !== MessagingRegistrationStatus::Pending
            && $status !== MessagingRegistrationStatus::Approved
            && ! $hasCampaign
            && in_array($brandKey, ['not_started', 'verified', 'needs_attention'], true)
            || $status === MessagingRegistrationStatus::Rejected;

        return [
            'brand' => $brand,
            'campaign' => $campaign,
            'committed' => $hasBrand || $hasCampaign || $status !== MessagingRegistrationStatus::NotStarted,
            'percent' => $percent,
            'show_form' => $registration->number_type === PhoneNumberType::TollFree ? true : $showForm,
            'campaign_ready' => $brandKey === 'verified' && (! $hasCampaign || $status === MessagingRegistrationStatus::Rejected) && $status !== MessagingRegistrationStatus::Pending && $status !== MessagingRegistrationStatus::Approved,
            'last_checked_at' => $hasCampaign || $submitted ? $registration->campaign_status_checked_at : $registration->brand_status_checked_at,
            'tcr_brand_id' => $registration->provider_brand_tcr_id,
            'campaign_issue' => $issue,
        ];
    }
}
