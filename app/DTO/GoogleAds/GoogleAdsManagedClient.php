<?php

namespace App\DTO\GoogleAds;

use App\Library\GoogleAds\GoogleAdsCustomerId;
use App\Library\GoogleAds\GoogleAdsJson as J;

/**
 * Google Ads Module V1 contract §2/§3 — one `customer_client` row read
 * through a manager account: id, level (0 = the manager itself), manager,
 * descriptive_name, currency_code, time_zone, status, test_account, hidden.
 */
final readonly class GoogleAdsManagedClient
{
    public function __construct(
        public string $customerId,
        public int $level,
        public bool $isManager,
        public ?string $name,
        public ?string $currencyCode,
        public ?string $timeZone,
        public ?string $status,
        public bool $isTest,
        public bool $hidden,
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromSearchRow(array $row): ?self
    {
        $client = $row['customerClient'] ?? null;

        if (! is_array($client)) {
            return null;
        }

        $id = GoogleAdsCustomerId::normalize(J::id($client['id'] ?? null));
        $level = J::int64($client['level'] ?? null);

        if ($id === null || $level === null) {
            return null;
        }

        $currency = J::string($client['currencyCode'] ?? null, 3);

        return new self(
            customerId: $id,
            level: $level,
            isManager: J::bool($client['manager'] ?? null) === true,
            name: J::string($client['descriptiveName'] ?? null, 191),
            currencyCode: $currency !== null && preg_match('/\A[A-Za-z]{3}\z/', $currency) === 1 ? strtoupper($currency) : null,
            timeZone: J::string($client['timeZone'] ?? null, 64),
            status: J::string($client['status'] ?? null, 24),
            isTest: J::bool($client['testAccount'] ?? null) === true,
            hidden: J::bool($client['hidden'] ?? null) === true,
        );
    }
}
