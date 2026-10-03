<?php

namespace App\Library\Messaging;

use App\Models\Business;
use App\Models\Country;
use App\Models\CustomerBasedSendingServer;
use App\Models\PhoneNumbers;
use App\Models\Senderid;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

/**
 * The Business's SMS sending path and phone parsing, resolved at send time and
 * never stored. Lifted unchanged out of SendSmsNodeExecutor so the Automations
 * Send SMS action and the document-link SMS share ONE resolution of "which
 * sender / transport does this Business send from" — both then hand the result
 * to CampaignRepository::checkQuickSendValidation() + quickSend().
 *
 * This class reaches no provider. It only decides which of the Business's own
 * identities to present to the send core, which re-authorizes them anyway.
 *
 * CX Slice 6 handoff: resolve() is the one method to replace with the default
 * sender resolver when that lands.
 */
class BusinessSmsSendingPath
{
    public function __construct(private readonly BusinessMessagingIdentityResolver $identities)
    {
    }

    /**
     * @return array{originator: string, sending_server: int|null}|null null when
     *         this Business has no usable sending path at all
     */
    public function resolve(Business $business): ?array
    {
        $originator = $this->originator($business);

        if ($originator === null) {
            return null;
        }

        // 1. Managed sending identity, when one is active. quickSend() detects
        //    this itself and delegates; it must NOT be handed a legacy server.
        if ($this->identities->resolveForBusiness($business) !== null) {
            return ['originator' => $originator, 'sending_server' => null];
        }

        // 2. Otherwise the Business's own active assigned BYO channel, whose
        //    underlying sending server must itself still be active (B4 §7.A).
        $assignment = CustomerBasedSendingServer::query()
            ->where('business_id', (int) $business->id)
            ->where('status', 1)
            ->with('sendingServer')
            ->orderBy('id')
            ->get()
            ->first(fn ($row): bool => $row->sendingServer !== null && (bool) $row->sendingServer->status);

        if ($assignment === null) {
            // 3. Neither path exists: fail closed.
            return null;
        }

        return ['originator' => $originator, 'sending_server' => (int) $assignment->sending_server];
    }

    /**
     * The Business's own canonical originator: an active SenderID first, then an
     * assigned phone number that can carry SMS. Deterministic by id.
     */
    private function originator(Business $business): ?string
    {
        $senderId = Senderid::query()
            ->where('business_id', (int) $business->id)
            ->where('status', Senderid::STATUS_ACTIVE)
            ->orderBy('id')
            ->value('sender_id');

        if (is_string($senderId) && trim($senderId) !== '') {
            return $senderId;
        }

        $number = PhoneNumbers::query()
            ->where('business_id', (int) $business->id)
            ->where('status', 'assigned')
            ->orderBy('id')
            ->get(['number', 'capabilities'])
            ->first(fn ($row): bool => str_contains((string) $row->capabilities, 'sms'));

        return $number === null ? null : (string) $number->number;
    }

    /**
     * @return array{country_code: int, region_code: string, recipient: string}|null
     */
    public function parsePhone(?string $phone): ?array
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse('+' . preg_replace('/\D/', '', (string) $phone));
            $regionCode = $util->getRegionCodeForNumber($parsed);
            $countryCode = $parsed->getCountryCode();

            if (! $util->isPossibleNumber($parsed) || empty($countryCode) || empty($regionCode)) {
                return null;
            }

            $national = $parsed->isItalianLeadingZero()
                ? '0' . $parsed->getNationalNumber()
                : (string) $parsed->getNationalNumber();

            // The country must be one the platform sends to at all; quickSend()
            // checks coverage itself, so this only avoids handing it a region it
            // cannot parse.
            if (! Country::query()->where('country_code', $countryCode)->where('iso_code', $regionCode)->exists()) {
                return null;
            }

            return [
                'country_code' => $countryCode,
                'region_code' => $regionCode,
                'recipient' => $national,
            ];
        } catch (NumberParseException) {
            return null;
        }
    }
}
