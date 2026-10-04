<?php

namespace App\Library\GoogleAds;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\DTO\GoogleAds\GoogleAdsAccountCandidate;
use App\DTO\GoogleAds\GoogleAdsCustomerDetails;
use App\DTO\GoogleAds\GoogleAdsManagedClient;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\Contracts\GoogleAdsReadClient;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use LogicException;

/**
 * Google Ads Module V1 contract §3 — produces the account CANDIDATES the
 * owner may choose from, SERVER-SIDE, on every render of the selection page
 * and again on the POST (GoogleAdsAccountSelector re-derives them; nothing
 * a client posts is ever trusted).
 *
 * Derivation:
 *   1. listAccessibleCustomers  — customers the authorised user reaches
 *      DIRECTLY (login-customer-id not needed).
 *   2. customer details for each (name, currency, time zone, manager, test).
 *   3. for every MANAGER customer, its clients via `customer_client` with
 *      login-customer-id = that manager. A client's candidate carries that
 *      manager as `loginCustomerId`; direct access wins over a manager path,
 *      and the first manager (in listing order) wins among managers.
 *
 * Managers (including sub-managers) are listed but flagged not selectable.
 * Hidden clients and CANCELED / CLOSED customers are never candidates, and a
 * non-manager with no currency or time zone is dropped (it cannot be read
 * safely). A customer Google refuses with access_denied / not_found is
 * skipped; any other failure (rate limit, timeout, unavailable, invalid
 * grant) propagates. NOTHING is auto-selected.
 *
 * One ledger operation (`ads_accounts_listed`) carries the provider-call
 * count; all calls run through the per-Business call budget.
 */
final class GoogleAdsAccountDirectory
{
    /** Customer statuses that can never be operated on. */
    private const UNUSABLE_STATUSES = ['CANCELED', 'CLOSED'];

    public function __construct(
        private readonly GoogleAdsReadClient $client,
        private readonly GoogleAdsConnectionManager $connections,
        private readonly GoogleAdsOperationLedger $ledger,
        private readonly GoogleAdsCallBudget $budget,
    ) {
    }

    /**
     * @return array<int, GoogleAdsAccountCandidate> sorted by name then id
     *
     * @throws GoogleAdsProviderException
     */
    public function candidates(Business $business, BusinessGoogleConnection $connection, ?int $actorUserId = null): array
    {
        $this->connections->assertAdsConnection($connection);

        if ((int) $connection->business_id !== (int) $business->id) {
            throw new LogicException('A Google Ads connection can only be listed for its own Business.');
        }

        $operation = $this->ledger->open(
            businessId: (int) $business->id,
            type: GoogleOperationType::AdsAccountsListed,
            actorUserId: $actorUserId,
            summary: 'Listing accessible Google Ads accounts',
        );

        try {
            $candidates = $this->budget->withinOperation(
                $connection,
                $operation,
                fn (): array => $this->derive($this->connections->accessTokenFor($connection)),
            );
        } catch (GoogleAdsProviderException $exception) {
            $this->ledger->fail($operation, $exception, 'Listing Google Ads accounts failed');

            throw $exception;
        }

        $this->ledger->succeed($operation, count($candidates) . ' account(s) available');

        return $candidates;
    }

    /**
     * @return array<int, GoogleAdsAccountCandidate>
     */
    private function derive(string $accessToken): array
    {
        $candidates = [];
        $managers = [];

        foreach ($this->client->listAccessibleCustomers($accessToken) as $customerId) {
            $details = $this->tolerating(fn (): GoogleAdsCustomerDetails => $this->client->customerDetails(
                new GoogleAdsAccessContext($accessToken, $customerId, null),
            ));

            if ($details === null || $this->isUnusable($details->status)) {
                continue;
            }

            $candidate = $this->candidate(
                $details->customerId, $details->name, $details->currencyCode, $details->timeZone,
                null, $details->isManager, $details->isTest,
            );

            if ($candidate === null) {
                continue;
            }

            $candidates[$candidate->customerId] ??= $candidate;

            if ($details->isManager) {
                $managers[] = $details->customerId;
            }
        }

        foreach ($managers as $managerId) {
            $clients = $this->tolerating(fn () => $this->client->managerClients(
                new GoogleAdsAccessContext($accessToken, $managerId, $managerId),
            ));

            foreach ($clients?->rows ?? [] as $client) {
                /** @var GoogleAdsManagedClient $client */
                if ($client->level < 1 || $client->hidden || $client->customerId === $managerId || $this->isUnusable($client->status)) {
                    continue;
                }

                $candidate = $this->candidate(
                    $client->customerId, $client->name, $client->currencyCode, $client->timeZone,
                    $managerId, $client->isManager, $client->isTest,
                );

                if ($candidate !== null) {
                    // Direct access (already present) wins; first manager wins among managers.
                    $candidates[$candidate->customerId] ??= $candidate;
                }
            }
        }

        $list = array_values($candidates);

        usort($list, static fn (GoogleAdsAccountCandidate $a, GoogleAdsAccountCandidate $b): int => [mb_strtolower((string) $a->name), $a->customerId]
            <=> [mb_strtolower((string) $b->name), $b->customerId]);

        return $list;
    }

    private function candidate(string $customerId, ?string $name, ?string $currency, ?string $timeZone, ?string $login, bool $isManager, bool $isTest): ?GoogleAdsAccountCandidate
    {
        // A non-manager that cannot name its currency and time zone cannot be read safely.
        if (! $isManager && ($currency === null || $timeZone === null)) {
            return null;
        }

        return new GoogleAdsAccountCandidate($customerId, $name, (string) $currency, (string) $timeZone, $login, $isManager, $isTest);
    }

    private function isUnusable(?string $status): bool
    {
        return $status !== null && in_array($status, self::UNUSABLE_STATUSES, true);
    }

    /**
     * Runs a per-customer call, skipping a customer Google refuses
     * (access_denied / not_found) and re-throwing anything else.
     *
     * @template T
     *
     * @param  callable():T  $call
     * @return ?T
     */
    private function tolerating(callable $call): mixed
    {
        try {
            return $call();
        } catch (GoogleAdsProviderException $exception) {
            if (in_array($exception->classification, [GoogleAdsProviderException::ACCESS_DENIED, GoogleAdsProviderException::NOT_FOUND], true)) {
                return null;
            }

            throw $exception;
        }
    }
}
