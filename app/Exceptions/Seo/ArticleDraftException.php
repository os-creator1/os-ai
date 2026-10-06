<?php

namespace App\Exceptions\Seo;

use RuntimeException;

/**
 * SEO Content Engine V1 — an AI draft that could not be produced. The message is safe to show the owner as-is
 * and never carries provider detail.
 */
final class ArticleDraftException extends RuntimeException
{
    public const CANNIBALIZES = 'cannibalizes';
    public const BUDGET = 'budget';
    public const UNAVAILABLE = 'unavailable';
    public const UNUSABLE = 'unusable';
    public const NO_WEBSITE = 'no_website';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function customerMessage(): string
    {
        return $this->getMessage();
    }
}
