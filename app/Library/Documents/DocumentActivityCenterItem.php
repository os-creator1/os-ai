<?php

namespace App\Library\Documents;

use Carbon\CarbonInterface;

/**
 * One document/payment Activity Center row this actor has already been
 * re-authorized, right now, to see (DocumentActivityCenterReader). Carries
 * only what the dropdown renders — never the raw notification payload.
 */
final class DocumentActivityCenterItem
{
    public function __construct(
        public readonly string $notificationId,
        public readonly string $message,
        public readonly string $url,
        public readonly CarbonInterface $createdAt,
    ) {
    }
}
