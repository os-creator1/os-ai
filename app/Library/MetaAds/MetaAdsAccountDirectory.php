<?php

namespace App\Library\MetaAds;

use App\DTO\MetaAds\MetaAdsAccountCandidate;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaReadClient;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use LogicException;

/**
 * Meta Ads Module V1 contract 24 §4 — produces the ad-account CANDIDATES the
 * owner may choose from, SERVER-SIDE, on every render of the selection page
 * and again on the POST (MetaAdsAccountSelector re-derives them; nothing a
 * client posts is ever trusted).
 *
 * One ledger operation (`accounts_listed`) carries the provider-call count and
 * the listing runs inside the per-Business call budget. NOTHING is
 * auto-selected here or anywhere.
 */
final class MetaAdsAccountDirectory
{
    public function __construct(
        private readonly MetaReadClient $client,
        private readonly MetaAdsConnectionManager $connections,
        private readonly MetaAdsOperationLedger $ledger,
        private readonly MetaAdsCallBudget $budget,
    ) {
    }

    /**
     * @return array<int, MetaAdsAccountCandidate> sorted by name then id
     *
     * @throws MetaProviderException
     */
    public function candidates(Business $business, BusinessMetaConnection $connection, ?int $actorUserId = null): array
    {
        if ((int) $connection->business_id !== (int) $business->id) {
            throw new LogicException('A Meta connection can only be listed for its own Business.');
        }

        $operation = $this->ledger->open(
            businessId: (int) $business->id,
            type: MetaOperationType::AccountsListed,
            actorUserId: $actorUserId,
            summary: 'Listing accessible Meta ad accounts',
        );

        try {
            $candidates = $this->budget->withinOperation(
                $connection,
                $operation,
                fn (): array => $this->client->listAdAccounts($this->connections->accessTokenFor($connection)),
            );
        } catch (MetaProviderException $exception) {
            $this->ledger->fail($operation, $exception, 'Listing Meta ad accounts failed');

            throw $exception;
        }

        $unique = [];

        foreach ($candidates as $candidate) {
            $unique[$candidate->adAccountId] ??= $candidate;
        }

        $list = array_values($unique);

        usort($list, static fn (MetaAdsAccountCandidate $a, MetaAdsAccountCandidate $b): int => [mb_strtolower((string) $a->name), $a->adAccountId]
            <=> [mb_strtolower((string) $b->name), $b->adAccountId]);

        $this->ledger->succeed($operation, count($list) . ' account(s) available');

        return $list;
    }
}
