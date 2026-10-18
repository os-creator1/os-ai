<?php

namespace App\Exceptions\Seo;

use RuntimeException;

/**
 * SEO Content Engine V1 — a refused article write. Carries a machine reason and a message that is safe
 * to show the owner as-is.
 */
final class ArticleException extends RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const NO_WEBSITE = 'no_website';
    public const INVALID_SLUG = 'invalid_slug';
    public const SLUG_TAKEN = 'slug_taken';
    public const BAD_TRANSITION = 'bad_transition';
    public const NOT_READY = 'not_ready';
    public const OVERLAP_UNACKNOWLEDGED = 'overlap_unacknowledged';
    public const BAD_SCHEDULE = 'bad_schedule';
    public const BAD_REFERENCE = 'bad_reference';

    /**
     * @param  array<int, string>  $problems  specific, owner-readable reasons (publish blockers)
     */
    public function __construct(public readonly string $reason, string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }

    public function customerMessage(): string
    {
        return $this->problems === []
            ? $this->getMessage()
            : $this->getMessage() . ' ' . implode(' ', $this->problems);
    }
}
