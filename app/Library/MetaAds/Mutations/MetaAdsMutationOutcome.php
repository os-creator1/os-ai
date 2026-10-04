<?php

namespace App\Library\MetaAds\Mutations;

use App\Enums\MetaAds\MetaOperationStatus;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Models\BusinessMetaOperation;

/**
 * Meta Ads Module V1 contract 24 §7 — what a pause/resume request returned.
 *
 * `messageKey` is a fixed `meta_ads.mutation.*` key and `message` its plain
 * English fallback (see flashMessages()). NEITHER ever carries provider text,
 * a token or a payload. `operationUid` is the ledger row's uid (null only when
 * nothing was opened, i.e. an "already in that state" no-op).
 */
final readonly class MetaAdsMutationOutcome
{
    public const KEY_SUCCEEDED = 'meta_ads.mutation.succeeded';

    public const KEY_AWAITING = 'meta_ads.mutation.awaiting_confirmation';

    public const KEY_FAILED = 'meta_ads.mutation.failed';

    public const KEY_RECONNECT = 'meta_ads.mutation.reconnect_required';

    public const KEY_DEFERRED = 'meta_ads.mutation.deferred';

    public const KEY_IN_PROGRESS = 'meta_ads.mutation.already_in_progress';

    public const KEY_ALREADY_IN_STATE = 'meta_ads.mutation.already_in_state';

    public function __construct(
        public MetaAdsMutationOutcomeStatus $status,
        public ?string $operationUid,
        public string $messageKey,
        public string $message,
        public ?string $failureClassification = null,
    ) {
    }

    /** @return array<string, string> every fixed key => its fallback copy (flash message map) */
    public static function flashMessages(): array
    {
        return [
            self::KEY_SUCCEEDED => 'Done. Meta Ads has been updated.',
            self::KEY_AWAITING => 'Awaiting confirmation. Meta did not confirm this change; it will be checked on the next refresh. Nothing was sent again.',
            self::KEY_FAILED => 'Meta did not accept this change. Nothing was changed.',
            self::KEY_RECONNECT => 'Your Meta connection needs to be renewed. Reconnect Meta to continue. Nothing was changed.',
            self::KEY_DEFERRED => 'Meta is busy right now. Nothing was changed; please try again shortly.',
            self::KEY_IN_PROGRESS => 'This change is already in progress.',
            self::KEY_ALREADY_IN_STATE => 'Already in that state. Nothing was changed.',
        ];
    }

    public static function succeeded(BusinessMetaOperation $operation): self
    {
        return self::make(MetaAdsMutationOutcomeStatus::Succeeded, $operation, self::KEY_SUCCEEDED);
    }

    /** The result of a provider failure, derived from the ledger status it was recorded as. */
    public static function fromFailure(BusinessMetaOperation $operation, MetaProviderException $exception): self
    {
        return match (true) {
            $operation->status === MetaOperationStatus::Deferred => self::make(MetaAdsMutationOutcomeStatus::Deferred, $operation, self::KEY_DEFERRED, $exception->classification),
            $operation->status === MetaOperationStatus::Unknown => self::awaitingConfirmation($operation, $exception->classification),
            $exception->isTokenFailure() => self::make(MetaAdsMutationOutcomeStatus::Failed, $operation, self::KEY_RECONNECT, $exception->classification),
            default => self::make(MetaAdsMutationOutcomeStatus::Failed, $operation, self::KEY_FAILED, $exception->classification),
        };
    }

    public static function awaitingConfirmation(BusinessMetaOperation $operation, ?string $classification = null): self
    {
        return self::make(MetaAdsMutationOutcomeStatus::AwaitingConfirmation, $operation, self::KEY_AWAITING, $classification);
    }

    public static function inProgress(BusinessMetaOperation $operation): self
    {
        return self::make(MetaAdsMutationOutcomeStatus::DuplicateNoop, $operation, self::KEY_IN_PROGRESS);
    }

    public static function alreadyInState(): self
    {
        return new self(MetaAdsMutationOutcomeStatus::DuplicateNoop, null, self::KEY_ALREADY_IN_STATE, self::flashMessages()[self::KEY_ALREADY_IN_STATE]);
    }

    public function isSuccess(): bool
    {
        return $this->status === MetaAdsMutationOutcomeStatus::Succeeded;
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->status === MetaAdsMutationOutcomeStatus::AwaitingConfirmation;
    }

    private static function make(MetaAdsMutationOutcomeStatus $status, BusinessMetaOperation $operation, string $key, ?string $classification = null): self
    {
        return new self($status, (string) $operation->uid, $key, self::flashMessages()[$key], $classification);
    }
}
