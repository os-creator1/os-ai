<?php

namespace App\Library\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailFailureCategory;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use Illuminate\Http\Client\ConnectionException;

/**
 * The ONE place a provider HTTP outcome becomes the provider-neutral failure
 * taxonomy. Both adapters call it, so the mapping is identical for Google and
 * Microsoft and no controller or service ever sees a provider error shape.
 *
 * The operator code it records is a sanitized short token (an HTTP status or
 * a provider error code such as `invalid_grant`), capped at 64 characters
 * and restricted to [A-Za-z0-9_.:-], so it can never carry a body, a token
 * or user text.
 */
final class BusinessEmailProviderErrorMapper
{
    /** 403 codes that mean "slow down" rather than "not allowed". */
    private const RATE_LIMIT_CODES = [
        'ratelimitexceeded',
        'userratelimitexceeded',
        'dailylimitexceeded',
        'quotaexceeded',
        'errorsendquotaexceeded',
        'errortoomanyobjectsopened',
    ];

    public static function fromHttp(
        int $status,
        ?string $code = null,
        bool $recipientProblem = false,
        bool $tokenEndpoint = false,
    ): BusinessEmailProviderException {
        $safeCode = self::safeCode($code ?? (string) $status);
        $lower = strtolower((string) $code);

        if ($tokenEndpoint) {
            if ($status === 429) {
                return new BusinessEmailProviderException(BusinessEmailFailureCategory::ProviderRateLimited, $safeCode);
            }

            if ($status >= 500) {
                return new BusinessEmailProviderException(BusinessEmailFailureCategory::TemporaryProviderFailure, $safeCode);
            }

            // invalid_grant: the refresh token was revoked/expired. Anything
            // else on a token endpoint (invalid_client, unauthorized_client,
            // bad request) is also unusable credentials from our side.
            return new BusinessEmailProviderException(
                BusinessEmailFailureCategory::AuthenticationExpired,
                $safeCode,
                revocation: $lower === 'invalid_grant',
            );
        }

        if ($status === 401) {
            return new BusinessEmailProviderException(BusinessEmailFailureCategory::AuthenticationExpired, $safeCode, revocation: true);
        }

        if ($status === 429) {
            return new BusinessEmailProviderException(BusinessEmailFailureCategory::ProviderRateLimited, $safeCode);
        }

        if ($status === 403) {
            return in_array($lower, self::RATE_LIMIT_CODES, true)
                ? new BusinessEmailProviderException(BusinessEmailFailureCategory::ProviderRateLimited, $safeCode)
                : new BusinessEmailProviderException(BusinessEmailFailureCategory::PermissionDenied, $safeCode);
        }

        if ($status === 408 || $status >= 500) {
            return new BusinessEmailProviderException(BusinessEmailFailureCategory::TemporaryProviderFailure, $safeCode);
        }

        if ($recipientProblem && in_array($status, [400, 422], true)) {
            return new BusinessEmailProviderException(BusinessEmailFailureCategory::RecipientInvalid, $safeCode);
        }

        return new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, $safeCode);
    }

    /**
     * A transport failure. A timeout / reset AFTER the request was sent is
     * ambiguous (the provider may have accepted it) when `$requestSent`;
     * a refusal to connect never reached the provider and is a plain
     * temporary failure.
     */
    public static function fromTransport(ConnectionException $exception, bool $requestSent): BusinessEmailProviderException
    {
        $timedOutMidFlight = (bool) preg_match('/cURL error (28|52|55|56)\b/', $exception->getMessage());

        return new BusinessEmailProviderException(
            BusinessEmailFailureCategory::TemporaryProviderFailure,
            'transport',
            ambiguous: $requestSent && $timedOutMidFlight,
        );
    }

    private static function safeCode(string $code): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.:-]/', '', $code) ?? '';

        return $clean === '' ? 'unknown' : substr($clean, 0, 64);
    }
}
