<?php

namespace App\DTO\GoogleAds;

/**
 * Google Ads Module V1 contract §2/§6 — what a successful mutate returns:
 * the resource name Google reports for the one operation sent.
 */
final readonly class GoogleAdsMutationResult
{
    public function __construct(public string $resourceName)
    {
    }
}
