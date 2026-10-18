<?php

namespace App\Library\MetaAds;

use App\DTO\MetaAds\MetaApiUsage;

/**
 * Meta Ads Module V1 — a decoded successful Graph response plus the quota
 * usage Meta reported in its headers. Internal to the Http clients.
 */
final readonly class MetaGraphResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public array $body,
        public ?MetaApiUsage $usage = null,
    ) {
    }
}
