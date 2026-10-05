<?php

namespace App\Library\Workspace;

use App\Enums\Business\BusinessStatus;
use App\Enums\Website\WebsiteStatus;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Dashboard\BusinessStatusRow;
use App\Library\Dashboard\DashboardStatusReader;
use App\Models\Business;

/**
 * Agency V1 final — the one-client summary on the Agency's client detail page:
 * is the client set up, what needs attention, and are leads arriving.
 *
 * NOTHING HERE IS A NEW SOURCE OF TRUTH. It composes the readers the Agency
 * Home portfolio already trusts and nothing else:
 *
 *  - DashboardStatusReader  — wallet, website and Google status of the Business;
 *  - BusinessStatusRow::attentionTypes() — the same attention sentences the Agency
 *    Home flags a client with (so the two pages cannot disagree);
 *  - BusinessAnalyticsQueries::newContactsForBusinesses() and
 *    BusinessConversationReadModel::startedCountsForBusinesses() — the same two
 *    seams the Agency Home "Client performance" band reads.
 *
 * It is handed a Business the CALLER has already proven is this Agency's actively
 * managed client (AgencyClientsController::resolveLinkedClient()); it widens
 * nothing, writes nothing, and reads only that one Business id. There is no
 * booking figure: the product has no canonical booking count to read, and this
 * page does not invent one.
 *
 * Plain words only — no raw ids, enum values or provider codes.
 */
final class AgencyClientSummaryReader
{
    public function __construct(
        private readonly DashboardStatusReader $statusReader,
        private readonly BusinessAnalyticsQueries $analyticsQueries,
        private readonly BusinessConversationReadModel $conversations,
    ) {
    }

    /**
     * @return array{
     *     business: array{label: string, variant: string},
     *     website: array{label: string, variant: string},
     *     google: array{label: string, variant: string},
     *     funding: array{label: string, variant: string},
     *     attention: array<int, string>,
     *     activity: array{rangeLabel: string, newContacts: int, newConversations: int}
     * }
     */
    public function for(Business $business): array
    {
        $row = $this->statusReader->forBusiness((int) $business->id);
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, (string) config('app.timezone', 'UTC'));
        $id = (int) $business->id;

        return [
            'business' => $this->businessState($business),
            'website' => $this->website($row),
            'google' => $this->google($row),
            'funding' => $this->funding($row),
            'attention' => array_map(fn ($type): string => $type->sentence(), $row->attentionTypes()),
            'activity' => [
                'rangeLabel' => $range->label(),
                'newContacts' => (int) ($this->analyticsQueries->newContactsForBusinesses([$id], $range->startUtc, $range->endUtc)[$id] ?? 0),
                'newConversations' => (int) ($this->conversations->startedCountsForBusinesses([$id], $range->startUtc, $range->endUtc)[$id] ?? 0),
            ],
        ];
    }

    /**
     * @return array{label: string, variant: string}
     */
    private function businessState(Business $business): array
    {
        return match ($business->status) {
            BusinessStatus::Active => ['label' => 'Active', 'variant' => 'success'],
            BusinessStatus::Draft => ['label' => 'Waiting for client setup', 'variant' => 'warning'],
            default => ['label' => 'Not active', 'variant' => 'secondary'],
        };
    }

    /**
     * @return array{label: string, variant: string}
     */
    private function website(BusinessStatusRow $row): array
    {
        return match ($row->websiteStatus) {
            null => ['label' => 'Not started', 'variant' => 'secondary'],
            WebsiteStatus::Published->value => ['label' => 'Published', 'variant' => 'success'],
            WebsiteStatus::Draft->value => ['label' => 'Draft — not published yet', 'variant' => 'warning'],
            default => ['label' => 'Taken down', 'variant' => 'secondary'],
        };
    }

    /**
     * @return array{label: string, variant: string}
     */
    private function google(BusinessStatusRow $row): array
    {
        if (in_array($row->googleConnectionState, ['revoked', 'disconnected'], true)) {
            return ['label' => 'Connection lost — needs reconnecting', 'variant' => 'warning'];
        }

        if ($row->googleConnectionState === null) {
            return ['label' => 'Not connected', 'variant' => 'secondary'];
        }

        return $row->unhealthyGoogleLocations > 0
            ? ['label' => 'Connected — a listing needs attention', 'variant' => 'warning']
            : ['label' => 'Connected', 'variant' => 'success'];
    }

    /**
     * The client's usage funding (messaging, AI and ads spend), in plain words.
     *
     * @return array{label: string, variant: string}
     */
    private function funding(BusinessStatusRow $row): array
    {
        if (! $row->hasWallet) {
            return ['label' => 'Not set up yet', 'variant' => 'secondary'];
        }

        if ($row->billingStatus === 'suspended' || $row->paidActivityPaused) {
            return ['label' => 'Paused', 'variant' => 'danger'];
        }

        if (bccomp($row->debtBalanceMicro, '0') > 0) {
            return ['label' => 'Balance owing', 'variant' => 'warning'];
        }

        return ['label' => 'Ready', 'variant' => 'success'];
    }
}
