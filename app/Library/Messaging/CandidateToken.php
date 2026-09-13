<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\Exceptions\InvalidCandidateTokenException;
use App\Models\Business;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * PR #295 Correction Round 1, item 2 — binds an order to a candidate this
 * platform itself verified a moment earlier, instead of trusting whatever
 * phone_number/provider_candidate_reference/number_type a browser posts.
 *
 * searchNumbers() results are never handed back to the browser as raw,
 * independently-editable form fields; each candidate is wrapped in one
 * opaque, authenticated-encrypted token (Laravel's own APP_KEY-backed
 * Crypt facade — AES-256-CBC with an HMAC, i.e. encrypt-then-MAC) carrying
 * the Business id, every candidate field, and a short expiry. A tampered
 * ciphertext fails MAC verification and throws DecryptException; a token
 * issued for a different Business, or one that has expired, is refused
 * explicitly. There is no way for a customer to alter the provider
 * reference and have the platform order a different resource: decode()
 * either returns the EXACT candidate encode() was given, or throws.
 *
 * This is option (a) from the correction brief: a short-lived, Business-
 * bound token, never a second hidden-input trust boundary.
 */
final class CandidateToken
{
    private const TTL_MINUTES = 15;

    public static function encode(Business $business, AvailableNumberCandidate $candidate): string
    {
        return Crypt::encryptString(json_encode([
            'business_id' => (int) $business->id,
            'phone_number' => $candidate->phoneNumber,
            'number_type' => $candidate->numberType->value,
            'provider_candidate_reference' => $candidate->providerCandidateReference,
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @throws InvalidCandidateTokenException when the token is malformed,
     *                                        tampered with, expired, or was
     *                                        issued for a different Business
     */
    public static function decode(string $token, Business $business): AvailableNumberCandidate
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new InvalidCandidateTokenException('The candidate token could not be verified.');
        }

        if (! is_array($payload)
            || ! isset($payload['business_id'], $payload['phone_number'], $payload['number_type'], $payload['provider_candidate_reference'], $payload['expires_at'])) {
            throw new InvalidCandidateTokenException('The candidate token is malformed.');
        }

        if ((int) $payload['business_id'] !== (int) $business->id) {
            throw new InvalidCandidateTokenException('The candidate token does not belong to this Business.');
        }

        if (Carbon::now()->timestamp > (int) $payload['expires_at']) {
            throw new InvalidCandidateTokenException('The candidate token has expired.');
        }

        $numberType = PhoneNumberType::tryFrom((string) $payload['number_type']);

        if ($numberType === null) {
            throw new InvalidCandidateTokenException('The candidate token names an unknown number type.');
        }

        return new AvailableNumberCandidate(
            phoneNumber: (string) $payload['phone_number'],
            numberType: $numberType,
            providerCandidateReference: (string) $payload['provider_candidate_reference'],
        );
    }
}
