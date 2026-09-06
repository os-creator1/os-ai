<?php

namespace App\Library\Automation\Actions;

use App\Library\Automation\AutomationActionResult;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\Contacts;
use App\Models\Country;
use App\Models\CustomerBasedSendingServer;
use App\Models\Plan;
use App\Models\User;
use App\Repositories\Contracts\CampaignRepository;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * B4 Business Automations — SEND_MESSAGE (contract §7.A). SMS/MMS only,
 * through the exact B1 Business Outreach send core:
 *
 *   CampaignRepository::checkQuickSendValidation($sendData)
 *   CampaignRepository::quickSend($campaign, $sendData)
 *
 * with `$campaign->business_id` set on a transient Campaigns instance so
 * every Reports/TrackingLog row the core writes inherits the Business —
 * exactly what OutreachController::sendSms() does. No provider integration
 * of its own, no credentials, no Agency Prospecting channel, and never the
 * legacy User-only Automation::send()/sms_unit path: whatever accounting
 * the B1 core performs is inherited as-is (§7.A billing lock).
 *
 * Runs strictly OUTSIDE any DB transaction (the caller guarantees this):
 * the provider call is the one thing here that must never be held inside
 * a transaction (§5.1 rule 3).
 */
class SendMessageAction
{
    public function __construct(private readonly CampaignRepository $campaigns)
    {
    }

    public function run(AutomationExecution $execution, Automation $automation, Business $business, Contacts $contact): AutomationActionResult
    {
        $config = $automation->action_config ?? [];
        $smsType = (string) ($config['sms_type'] ?? 'plain');
        $message = (string) ($config['message'] ?? '');
        $senderId = (string) ($config['sender_id'] ?? '');
        $sendingServerId = isset($config['sending_server']) ? (int) $config['sending_server'] : null;

        if (! in_array($smsType, ['plain', 'mms'], true) || $message === '' || $senderId === '' || $sendingServerId === null) {
            return AutomationActionResult::skipped('send_config_invalid');
        }

        if ($contact->status !== Contacts::STATUS_SUBSCRIBE) {
            return AutomationActionResult::skipped('contact_unsubscribed');
        }

        // Business-assigned ACTIVE channel, re-fetched now — and its
        // underlying SendingServer must itself still be active (§7.A).
        $assignment = CustomerBasedSendingServer::query()
            ->where('business_id', $business->id)
            ->where('sending_server', $sendingServerId)
            ->where('status', 1)
            ->with('sendingServer')
            ->first();

        if ($assignment === null || $assignment->sendingServer === null || ! $assignment->sendingServer->status) {
            return AutomationActionResult::skipped('channel_unavailable');
        }

        // Legacy Subscription/Plan/coverage stay Customer-level by design —
        // resolved from the Business's owning customer exactly as B1 does.
        $activeSubscription = $business->customer?->activeSubscription();

        if (! $activeSubscription) {
            return AutomationActionResult::skipped('no_active_subscription');
        }

        $plan = Plan::query()->where('status', true)->find($activeSubscription->plan_id);

        if (! $plan) {
            return AutomationActionResult::skipped('plan_inactive');
        }

        $phone = $this->resolvePhone($contact);

        if ($phone === null) {
            return AutomationActionResult::skipped('contact_phone_invalid');
        }

        $coverage = $plan->plansCoverageCountries->firstWhere('country_id', $phone['country_id']);
        $options = $coverage ? json_decode($coverage['options'], true) : null;

        if (! is_array($options) || empty($options[$smsType])) {
            return AutomationActionResult::skipped('country_not_covered');
        }

        $sendData = [
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'message' => $this->renderMessage($message, $contact),
            'sms_type' => $smsType,
            'originator' => 'sender_id',
            'sender_id' => $senderId,
            'sending_server' => $sendingServerId,
        ];

        if ($smsType === 'mms') {
            $sendData['media_url'] = (string) ($config['media_url'] ?? '');

            if ($sendData['media_url'] === '') {
                return AutomationActionResult::skipped('mms_media_missing');
            }
        }

        try {
            // The B1 core authorizes sender_id against THIS Business
            // (Senderid/PhoneNumbers scoped by business_id) — a sender that
            // belongs to another Business is rejected here, at send time.
            $validation = $this->campaigns->checkQuickSendValidation($sendData)->getData();

            if (($validation->status ?? 'error') !== 'success') {
                return AutomationActionResult::skipped('sender_rejected: ' . ($validation->message ?? 'unknown'));
            }

            $sendData['sender_id'] = $validation->sender_id;
            $sendData['sms_type'] = $validation->sms_type;
            $sendData['user'] = User::query()->find($validation->user_id);
            $sendData['country_code'] = $phone['country_code'];
            $sendData['recipient'] = $phone['recipient'];
            $sendData['region_code'] = $phone['region_code'];

            $campaign = new Campaigns();
            $campaign->business_id = $business->id;

            // The provider call. Exactly one per claimed execution (§5).
            $response = $this->campaigns->quickSend($campaign, $sendData)->getData();
        } catch (Throwable $exception) {
            return AutomationActionResult::failed('send_exception: ' . get_class($exception));
        }

        $status = $response->status ?? 'error';

        if (in_array($status, ['success', 'info'], true)) {
            return AutomationActionResult::succeeded('Message sent to ' . $this->maskedPhone($contact));
        }

        return AutomationActionResult::failed('send_failed: ' . ($response->message ?? 'unknown'));
    }

    /**
     * @return array{country_code: int, region_code: string, recipient: string, country_id: int|null}|null
     */
    private function resolvePhone(Contacts $contact): ?array
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse('+' . preg_replace('/\D/', '', (string) $contact->phone));
            $regionCode = $util->getRegionCodeForNumber($parsed);
            $countryCode = $parsed->getCountryCode();

            if (! $util->isPossibleNumber($parsed) || empty($countryCode) || empty($regionCode)) {
                return null;
            }

            $national = $parsed->isItalianLeadingZero()
                ? '0' . $parsed->getNationalNumber()
                : (string) $parsed->getNationalNumber();

            $country = Country::query()->where('country_code', $countryCode)->where('iso_code', $regionCode)->first();

            return [
                'country_code' => $countryCode,
                'region_code' => $regionCode,
                'recipient' => $national,
                'country_id' => $country?->id,
            ];
        } catch (NumberParseException) {
            return null;
        }
    }

    /**
     * Deterministic {TAG} substitution from the Contact's own group fields
     * (the legacy automation behavior, retained) — plain string replacement
     * only, never template evaluation.
     */
    private function renderMessage(string $message, Contacts $contact): string
    {
        $group = $contact->contactGroup;

        if ($group === null) {
            return $message;
        }

        $replacements = [];

        foreach ($group->getFields()->get() as $field) {
            $replacements['{' . $field->tag . '}'] = (string) $contact->getValueByField($field);
        }

        return strtr($message, $replacements);
    }

    private function maskedPhone(Contacts $contact): string
    {
        $digits = preg_replace('/\D/', '', (string) $contact->phone) ?? '';

        return strlen($digits) > 4 ? str_repeat('*', strlen($digits) - 4) . substr($digits, -4) : '****';
    }
}
