<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * The carrier partner refused a registration step after this platform sent
 * it. The customer did not do anything wrong that they can fix here, and
 * the message is deliberately free of provider detail (status, codes and
 * rejected fields go only to the sanitized operator log). It only carries
 * what the customer needs: which step failed and what is already saved/paid.
 */
class MessagingRegistrationProviderException extends RuntimeException
{
    public const STEP_BRAND = 'brand';

    public const STEP_CAMPAIGN = 'campaign';

    public const STEP_TOLL_FREE = 'toll_free';

    public function __construct(
        string $message,
        public readonly string $step,
        /** A carrier brand now exists and is saved on the registration. */
        public readonly bool $brandSaved = false,
        /** The brand fee was charged by THIS attempt (false: paid earlier, or no brand). */
        public readonly bool $brandFeeChargedNow = false,
        /** HTTP status of a definitive provider refusal (operator/diagnostic use). */
        public readonly ?int $httpStatus = null,
        /** Allow-listed JSON pointers of the rejected request fields (names only, never values). @var list<string> */
        public readonly array $rejectedFields = [],
    ) {
        parent::__construct($message);
    }
}
