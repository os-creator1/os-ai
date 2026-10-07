<?php

namespace App\Library\Messaging;

use App\Library\Messaging\DTO\LocationSendContext;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
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
     * The Business's sending path for the Location a send must speak for — or the
     * plain reason there is none. This is THE Location-aware rule, lifted out of
     * AutomationSmsDispatcher so that Automations and Calendar booking
     * notifications share one copy (no third resolver).
     *
     *   - no usable identity at all  -> 'no_business_sending_path'
     *   - a managed number that cannot be shown to serve the Location
     *                                -> 'location_sender_unavailable'
     *   - a BYO sender (no Location assignment) for a Location-limited send of a
     *     Business with several Locations -> 'location_sender_unavailable'
     *
     * @return array{originator: string, sending_server: int|null}|string
     */
    public function resolveForLocation(Business $business, LocationSendContext $context): array|string
    {
        $originator = $this->originator($business);

        if ($originator === null) {
            return 'no_business_sending_path';
        }

        // 1. Managed sending identity, when one is active. quickSend() detects this
        //    itself and delegates; it must NOT be handed a legacy server. The number
        //    is proven for the Location here, before anything is sent.
        $identity = $this->identities->resolveForBusiness($business);

        if ($identity !== null) {
            try {
                $number = $this->identities->resolvePrimaryNumber($identity);
            } catch (MessagingIdentityConflictException) {
                // Not a Location question: the caller fails exactly as it always
                // has for a Business whose number is not usable.
                return ['originator' => $originator, 'sending_server' => null];
            }

            return $this->identities->numberServes($number, $business, $context)
                ? ['originator' => $originator, 'sending_server' => null]
                : 'location_sender_unavailable';
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
            return 'no_business_sending_path';
        }

        // A BYO sender has no Location assignment: it cannot be shown to belong to one
        // Location of several, so a Location-limited send of such a Business refuses.
        if ($context->scopeBound && count($this->identities->activeLocationIds($business)) > 1) {
            return 'location_sender_unavailable';
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

        if ($number !== null) {
            return (string) $number->number;
        }

        // A Business whose ONLY sender is the managed number it bought in Text
        // messaging has neither a SenderID nor a phone_numbers row (provisioning
        // writes an identity and a number, nothing legacy). Its own active
        // primary managed number is its originator; the managed dispatcher
        // chooses the number itself, and the send core re-authorizes this one
        // against the same Business (validateQuickSendOriginatorValue()).
        $identity = $this->identities->resolveForBusiness($business);

        if ($identity === null) {
            return null;
        }

        try {
            return (string) $this->identities->resolvePrimaryNumber($identity)->phone_number;
        } catch (MessagingIdentityConflictException) {
            return null;
        }
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
