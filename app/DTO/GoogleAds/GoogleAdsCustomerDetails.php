<?php

namespace App\DTO\GoogleAds;

use App\Library\GoogleAds\GoogleAdsCustomerId;
use App\Library\GoogleAds\GoogleAdsJson as J;

/**
 * Google Ads Module V1 contract §2/§3 — the `customer` resource facts the
 * module uses: id, descriptive_name, currency_code, time_zone, manager,
 * test_account, status.
 */
final readonly class GoogleAdsCustomerDetails
{
    public function __construct(
        public string $customerId,
        public ?string $name,
        public ?string $currencyCode,
        public ?string $timeZone,
        public bool $isManager,
        public bool $isTest,
        public ?string $status,
    ) {
    }

    /**
     * @param  array<string, mixed>  $row  one `googleAds:search` result row
     */
    public static function fromSearchRow(array $row): ?self
    {
        $customer = $row['customer'] ?? null;

        if (! is_array($customer)) {
            return null;
        }

        $id = GoogleAdsCustomerId::normalize(J::id($customer['id'] ?? null));

        if ($id === null) {
            return null;
        }

        $currency = J::string($customer['currencyCode'] ?? null, 3);

        return new self(
            customerId: $id,
            name: J::string($customer['descriptiveName'] ?? null, 191),
            currencyCode: $currency !== null && preg_match('/\A[A-Za-z]{3}\z/', $currency) === 1 ? strtoupper($currency) : null,
            timeZone: J::string($customer['timeZone'] ?? null, 64),
            isManager: J::bool($customer['manager'] ?? null) === true,
            isTest: J::bool($customer['testAccount'] ?? null) === true,
            status: J::string($customer['status'] ?? null, 24),
        );
    }
}
