<?php

namespace App\Library\Seo\Rank;

use RuntimeException;

/**
 * Every refusal SeoRankTargetManager can make, as a closed vocabulary with
 * calm fixed copy that never echoes input or another tenant's data.
 */
final class SeoRankException extends RuntimeException
{
    public const ACCESS_DENIED = 'access_denied';
    public const NOT_ENTITLED = 'not_entitled';
    public const INVALID_LOCATION = 'invalid_location';
    public const KEYWORD_NOT_ACTIVE = 'keyword_not_active';
    public const LIMIT_REACHED = 'limit_reached';

    private function __construct(public readonly string $reason, string $message, public readonly int $used = 0, public readonly int $limit = 0)
    {
        parent::__construct($message);
    }

    public static function accessDenied(): self
    {
        return new self(self::ACCESS_DENIED, 'The actor may not manage this rank target.');
    }

    public static function notEntitled(): self
    {
        return new self(self::NOT_ENTITLED, 'Rank tracking is not available for this Business.');
    }

    public static function invalidLocation(): self
    {
        return new self(self::INVALID_LOCATION, 'The search location is not a supported provider location.');
    }

    public static function keywordNotActive(): self
    {
        return new self(self::KEYWORD_NOT_ACTIVE, 'Archived keywords cannot be rank tracked.');
    }

    public static function limitReached(int $used, int $limit): self
    {
        return new self(self::LIMIT_REACHED, "The Business already tracks {$used} of {$limit} rank targets.", $used, $limit);
    }

    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::NOT_ENTITLED => 'Rank tracking is not included in your current plan.',
            self::INVALID_LOCATION => 'Choose a search location from the list.',
            self::KEYWORD_NOT_ACTIVE => 'This keyword is archived. Reactivate it before tracking its rank.',
            self::LIMIT_REACHED => "{$this->used} of {$this->limit} rank-tracked keywords are in use. Stop tracking one to free a slot.",
            default => 'That keyword could not be found.',
        };
    }
}
