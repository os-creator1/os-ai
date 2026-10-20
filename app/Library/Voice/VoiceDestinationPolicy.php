<?php

namespace App\Library\Voice;

use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * Which numbers MotionGrove will place a call to, decided on the server for every outgoing call and again
 * when the call is bridged. A region must be on the allow-list (default US and CA), and premium-rate,
 * shared-cost and universal-access numbers are refused whatever the region. This is MotionGrove's own
 * gate; Telnyx's outbound voice profile on the Call Control application is the provider-level one.
 */
final class VoiceDestinationPolicy
{
    /** @return array{0: string, 1: string}|null [code, customer message] when refused */
    public function refusal(string $e164): ?array
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($e164, null);
        } catch (Throwable) {
            return ['invalid_phone', 'This contact does not have a valid phone number that can be called.'];
        }

        if (! $util->isValidNumber($parsed)) {
            return ['invalid_phone', 'This contact does not have a valid phone number that can be called.'];
        }

        $allowed = array_filter(array_map('strtoupper', array_map('trim', explode(',', (string) config('services.telnyx.voice_allowed_regions', 'US,CA')))));
        $region = $util->getRegionCodeForNumber($parsed);

        if ($region === null || ! in_array($region, $allowed, true)) {
            return ['destination_not_allowed', 'Calls to this country are not enabled for your account.'];
        }

        if (in_array($util->getNumberType($parsed), [PhoneNumberType::PREMIUM_RATE, PhoneNumberType::SHARED_COST, PhoneNumberType::UAN, PhoneNumberType::VOICEMAIL], true)) {
            return ['destination_not_allowed', 'Premium-rate and shared-cost numbers cannot be called.'];
        }

        return null;
    }
}
