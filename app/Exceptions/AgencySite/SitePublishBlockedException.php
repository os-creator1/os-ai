<?php

namespace App\Exceptions\AgencySite;

use RuntimeException;

/** Publishing refused because the readiness checklist has blocking items. The items are safe to show. */
final class SitePublishBlockedException extends RuntimeException
{
    /** @param list<array{key: string, label: string, detail: string, step: int}> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('Fix '.count($blockers).' item(s) before publishing.');
    }
}
