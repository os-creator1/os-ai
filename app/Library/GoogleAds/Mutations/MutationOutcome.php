<?php

namespace App\Library\GoogleAds\Mutations;

use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Models\BusinessGoogleOperation;

/**
 * Google Ads Module V1 contract §6 — what a mutation request returned.
 *
 * `messageKey` is a stable translation key; `message` is the plain-English
 * fallback. Neither ever carries provider text, a token or a payload.
 * `operationUid` is the ledger row's uid (null only when nothing was opened,
 * i.e. an "already in that state" no-op).
 */
final readonly class MutationOutcome
{
    public const KEY_SUCCEEDED = 'google_ads.mutation.succeeded';

    public const KEY_AWAITING = 'google_ads.mutation.awaiting_confirmation';

    public const KEY_FAILED = 'google_ads.mutation.failed';

    public const KEY_DEFERRED = 'google_ads.mutation.deferred';

    public const KEY_IN_PROGRESS = 'google_ads.mutation.already_in_progress';

    public const KEY_ALREADY_IN_STATE = 'google_ads.mutation.already_in_state';

    public const KEY_ALREADY_EXCLUDED = 'google_ads.mutation.already_excluded';

    public function __construct(
        public MutationOutcomeStatus $status,
        public ?string $operationUid,
        public string $messageKey,
        public string $message,
        public ?string $failureClassification = null,
    ) {
    }

    public static function succeeded(BusinessGoogleOperation $operation): self
    {
        return new self(MutationOutcomeStatus::Succeeded, (string) $operation->uid, self::KEY_SUCCEEDED, 'Done. Google Ads has been updated.');
    }

    /** The result of a provider failure, derived from the ledger status the failure was recorded as. */
    public static function fromFailure(BusinessGoogleOperation $operation, GoogleAdsProviderException $exception): self
    {
        return match ($operation->status) {
            GoogleOperationStatus::Deferred => new self(
                MutationOutcomeStatus::Deferred, (string) $operation->uid, self::KEY_DEFERRED,
                $exception->userMessage(), $exception->classification,
            ),
            GoogleOperationStatus::Unknown => self::awaitingConfirmation($operation, $exception->classification),
            default => new self(
                MutationOutcomeStatus::Failed, (string) $operation->uid, self::KEY_FAILED,
                $exception->userMessage(), $exception->classification,
            ),
        };
    }

    public static function awaitingConfirmation(BusinessGoogleOperation $operation, ?string $classification = null): self
    {
        return new self(
            MutationOutcomeStatus::AwaitingConfirmation, (string) $operation->uid, self::KEY_AWAITING,
            'Awaiting confirmation. Google did not confirm this change; it will be checked on the next refresh. Nothing was sent again.',
            $classification,
        );
    }

    public static function inProgress(BusinessGoogleOperation $operation): self
    {
        return new self(MutationOutcomeStatus::DuplicateNoop, (string) $operation->uid, self::KEY_IN_PROGRESS, 'This change is already in progress.');
    }

    public static function alreadyInState(): self
    {
        return new self(MutationOutcomeStatus::DuplicateNoop, null, self::KEY_ALREADY_IN_STATE, 'Already in that state. Nothing was changed.');
    }

    public static function alreadyExcluded(): self
    {
        return new self(MutationOutcomeStatus::DuplicateNoop, null, self::KEY_ALREADY_EXCLUDED, 'That negative keyword already exists. Nothing was changed.');
    }

    public function isSuccess(): bool
    {
        return $this->status === MutationOutcomeStatus::Succeeded;
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->status === MutationOutcomeStatus::AwaitingConfirmation;
    }
}
