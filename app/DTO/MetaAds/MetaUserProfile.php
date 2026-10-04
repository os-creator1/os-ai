<?php

namespace App\DTO\MetaAds;

use InvalidArgumentException;

/** Meta Ads Module V1 contract §3 — `GET /me?fields=id,name`. */
final readonly class MetaUserProfile
{
    public function __construct(
        public string $id,
        public ?string $name,
    ) {
        if (preg_match('/\A\d{1,32}\z/', $id) !== 1) {
            throw new InvalidArgumentException('Meta user id must be digits.');
        }
    }
}
