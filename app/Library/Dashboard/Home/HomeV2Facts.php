<?php

namespace App\Library\Dashboard\Home;

use App\Enums\Documents\StripeConnectionStatus;
use App\Models\Automation;
use App\Models\Business;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessStripeConnection;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Models\CatalogItem;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Home V2 — the few extra persisted facts the final Home needs that no existing band already carries.
 *
 * Each is ONE bounded, Business-scoped existence query on its own table (no joins, no scans) and none is a metric:
 * they decide only whether a setup step is genuinely done and whether data is still being imported. A source that
 * cannot be read comes back null — "unknown" — and the presenter never turns unknown into done or into zero.
 */
final class HomeV2Facts
{
    public function __construct(
        private readonly BusinessAnalyticsQueries $analytics,
        private readonly BusinessConversationReadModel $conversations,
        private readonly HomeBookingReader $bookings,
    ) {
    }

    /**
     * @return array{
     *   createdAt: ?CarbonImmutable,
     *   emailConnected: ?bool,
     *   hasPricedCatalog: ?bool,
     *   hasActiveAutomation: ?bool,
     *   stripeActive: ?bool,
     *   contactImportRunning: ?bool,
     *   hasHistory: ?bool
     * }
     */
    public function gather(Business $business, bool $withHistory = false): array
    {
        $id = (int) $business->id;

        return [
            'createdAt' => $business->created_at === null ? null : CarbonImmutable::parse($business->created_at),
            'emailConnected' => $this->safely(fn () => BusinessEmailAccount::query()->where('business_id', $id)->where('state', 'active')->whereNotNull('refresh_token_encrypted')->exists()),
            'hasPricedCatalog' => $this->safely(fn () => CatalogItem::query()->where('business_id', $id)->whereNotNull('price_minor')->where('lifecycle_state', CatalogItemLifecycleState::Active->value)->exists()),
            'hasActiveAutomation' => $this->safely(fn () => Automation::query()->where('business_id', $id)->where('status', Automation::STATUS_ACTIVE)->exists()),
            'stripeActive' => $this->safely(fn () => BusinessStripeConnection::query()->where('business_id', $id)->where('status', StripeConnectionStatus::Active->value)->exists()),
            // Contacts are B5's: the answer comes from B5's own query class.
            'contactImportRunning' => $this->safely(fn () => $this->analytics->contactImportRunning($business)),
            // Only asked for when it can matter (a Business still in setup): see history().
            'hasHistory' => $withHistory ? $this->history($business) : null,
        ];
    }

    /**
     * Has this Business EVER had operational activity: a contact, a conversation or an appointment? Three single-row existence checks (each
     * through the seam that owns its table), stopping at the first yes. True = established; false = genuinely new (every source read and
     * empty); null = unknown (a source failed and none said yes), which never counts as new.
     */
    private function history(Business $business): ?bool
    {
        $answers = [
            fn () => $this->analytics->hasAnyContact($business),
            fn () => $this->conversations->hasAnyConversation($business),
            fn () => $this->bookings->hasAnyAppointment($business),
        ];
        $unknown = false;

        foreach ($answers as $read) {
            $answer = $this->safely($read);

            if ($answer === true) {
                return true;
            }

            $unknown = $unknown || $answer === null;
        }

        return $unknown ? null : false;
    }

    private function safely(callable $read): ?bool
    {
        try {
            return (bool) $read();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
